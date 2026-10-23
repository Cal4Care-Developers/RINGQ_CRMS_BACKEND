<?php

use Phalcon\Di;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Model\Manager as ModelsManager;
use Phalcon\Db\Adapter\Pdo\Mysql as DbAdapter;
use Phalcon\Config;
use Phalcon\Db\Column;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Library\Email;

require __DIR__ . '/../../app/library/Email.php';
require __DIR__ . '/../../vendor/autoload.php';

define('BASE_PATH', dirname(__DIR__, 2));
define('APP_PATH', BASE_PATH . '/app');

$di = new FactoryDefault();

$configFile = APP_PATH . '/config/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../config/config.php';
}

if (file_exists($configFile)) {
    $config = include $configFile;
    $di->set('config', $config);
} else {
    die("Config file not found!\n");
}

$di->setShared("db", function () {
    $config = $this->getConfig();
    if (!$config || !isset($config->database)) {
        return null;
    }
    $class = "Phalcon\\Db\\Adapter\\Pdo\\" . $config->database->adapter;
    $params = [
       "host" => $config->database->host,
       "username" => $config->database->username,
       "password" => $config->database->password,
       "dbname" => $config->database->dbname,
       "charset" => $config->database->charset,
    ];
    if ($config->database->adapter == "Postgresql") {
       unset($params["charset"]);
    }
    return new $class($params);
});

$di->set('modelsManager', function () {
    return new ModelsManager();
});

$di->set("redis", function() {
    $cache = new Predis\Client();
    return $cache;
}, true);

Di::setDefault($di);

// =====================================================
// DB RECONNECT HELPER — prevents stale connections
// from blocking PHP-FPM and slowing page loads
// =====================================================
function getDb() {
    $di = Di::getDefault();
    try {
        $db = $di->get('db'); 
        if (!$db instanceof \Phalcon\Db\Adapter\AdapterInterface) {
            throw new \RuntimeException('db service resolved to ' . gettype($db));
        }
        $db->query('SELECT 1');
        return $db;
    } catch (\Throwable $e) {
        $di->remove('db');
        $config = $di->getConfig();
        $di->setShared("db", function () use ($config) {
            $class = "Phalcon\\Db\\Adapter\\Pdo\\" . $config->database->adapter;
            $params = [
                "host"     => $config->database->host,
                "username" => $config->database->username,
                "password" => $config->database->password,
                "dbname"   => $config->database->dbname,
                "charset"  => $config->database->charset,
            ];
            if ($config->database->adapter == "Postgresql") {
                unset($params["charset"]);
            }
            return new $class($params);
        });
        $db = $di->get('db');
        if (!$db instanceof \Phalcon\Db\Adapter\AdapterInterface) {
            // Surface the real reason instead of handing a null back to a
            // caller that will dereference it.
            throw new \RuntimeException(
                'db service could not be rebuilt (got ' . gettype($db) . '): ' . $e->getMessage()
            );
        }
        return $db;
    }
}

function redis_scanKeys($redis, $pattern) {
    $keys = [];
    $cursor = 0;
    do {
        list($cursor, $batch) = $redis->scan($cursor, ['match' => $pattern, 'count' => 200]);
        if (!empty($batch)) {
            foreach ($batch as $k) {
                $keys[] = $k;
            }
        }
    } while ((int)$cursor !== 0);
    return array_values(array_unique($keys));
}

function redis_scanHasKey($redis, $pattern) {
    $cursor = 0;
    do {
        list($cursor, $batch) = $redis->scan($cursor, ['match' => $pattern, 'count' => 200]);
        if (!empty($batch)) {
            return true;
        }
    } while ((int)$cursor !== 0);
    return false;
}


// Load all models (superset of all jobs)
require_once APP_PATH . '/models/QRingqCdr.php';
require_once APP_PATH . '/models/VDomains.php';
require_once APP_PATH . '/models/VCallCenterAgents.php';
require_once APP_PATH . '/models/VCallCenterQueues.php';
require_once APP_PATH . '/models/VExtensionUsers.php';
require_once APP_PATH . '/models/VExtensions.php';
if (file_exists(APP_PATH . '/models/VXmlCdr.php')) {
    require_once APP_PATH . '/models/VXmlCdr.php';
}

function workerRequireJob(string $file): bool
{
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        echo "[Worker] MISSING JOB FILE {$file} — that concern is disabled on this box. "
            . "Check it is listed in build-fileserver-payload.sh and verify-fileserver-payload.sh.\n";
        return false;
    }
    require_once $path;
    return true;
}

workerRequireJob('clinikoJob.php');
workerRequireJob('salesforceJob.php');
workerRequireJob('meetJob.php');
workerRequireJob('ipchangeJob.php');
workerRequireJob('serviceJob.php');

// PRE-EXISTING, FLAGGED, DELIBERATELY NOT CHANGED: the five above discard this
// return value and their handlers are called unconditionally in the loop below.
// workerRequireJob() logs-and-skips a missing file, but the call site would then
// hit an undefined function, the outer catch would charge that to
// $consecutiveFailures, and 60 ticks later the worker exits. The fax listener is
// guarded because it is the newest arrival and the one most likely to be absent
// on a box whose payload predates it.
$faxListenerLoaded = workerRequireJob('faxInboundListener.php');
$tunnelJobLoaded   = workerRequireJob('tunnelJob.php');


$redis = $di->get('redis');

// --- State for polling-based jobs ---
$sfTempStorage = [];
$sfPhoneTimers = [];
$sfCount = 1;
$lastServiceCheck = 0;
$lastTunnelCheck  = 0;

echo "[Worker] Unified worker started at " . date('Y-m-d H:i:s') . "\n";

foreach (redis_scanKeys($redis, "sf-keys:*") as $existingKey) {
    $redis->sAdd("sf-keys-index", $existingKey);
}
foreach (redis_scanKeys($redis, "sf-tasks:*") as $existingKey) {
    $redis->sAdd("sf-tasks-index", $existingKey);
}

$consecutiveFailures = 0;
$lastFailureMessage  = '';
const WORKER_MAX_CONSECUTIVE_FAILURES = 60;

const TIME_MACHINE_CLI            = '/var/www/ringq/app/cli.php';
const TIME_MACHINE_LOG            = '/var/log/ringq-jobs.log';
const TIME_MACHINE_SPAWN_LOCK     = 'lock:jobs_spawn';
const TIME_MACHINE_SPAWN_TTL      = 15;

// =====================================================
// MAIN LOOP
// =====================================================
while (true) {
    if (time() - $lastServiceCheck >= 30) {
        $lastServiceCheck = time();
        try {
            handleServiceChecker();
        } catch (\Throwable $serviceError) {
            echo '[Worker] service checker failed: ' . $serviceError->getMessage() . "\n";
        }
    }

    try {
        getDb();

        $reloadModSofia = $redis->get("reload_mod_sofia");
        if ($reloadModSofia) {
            exec("fs_cli -x 'reload mod_sofia' 2>&1", $output, $return_var);
            echo 'reload mod_sofia 2 ' . implode("\n", $output) . "\n" . $return_var;
            $redis->del("reload_mod_sofia");
        }

        // --- Cliniko Inbound ---
        $job = $redis->lPop("queues-cliniko");
        if ($job) handleClinikoInbound($redis, $job);

        // --- Cliniko Outbound ---
        $job = $redis->lPop("queues-outbound-cliniko");
        if ($job) handleClinikoOutbound($redis, $job);

        // --- Cliniko Inbound New Patient ---
        $job = $redis->lPop("cliniko:newuser");
        if ($job) handleClinikoInboundNewPatient($redis, $job);

        // --- Cliniko Outbound New Patient (polling) ---
        if ($redis->lLen("queues-outbound-cliniko") > 0 || worker_hasClinikoIntegration($redis)) {
            handleClinikoOutboundNewPatient($redis);
        }

        // --- Salesforce Queue Key (polling) ---
        if ($redis->sCard("sf-keys-index") > 0 || !empty($sfTempStorage)) {
            handleSalesforceQueueKey($redis, $sfTempStorage, $sfPhoneTimers);
        }

        // --- Salesforce Sync (polling) ---
        if ($redis->sCard("sf-tasks-index") > 0) {
            handleSalesforceSync($redis, $sfCount);
        }

        // --- Meeting Notifications ---
        $item = $redis->rPop("email_queue");
        if ($item) handleMeetingNotification($redis, $item); 

        // --- Time Machine jobs (backup / restore / import / delete) ---
        dispatchTimeMachineJobs($redis);

                // --- Tunnel heartbeat check (every 30s) ---
        if ($tunnelJobLoaded && time() - $lastTunnelCheck >= 30) {
            $lastTunnelCheck = time();
            try {
                $marked = handleTunnelHeartbeatCheck(30);
                if ($marked > 0) {
                    echo '[Worker] ' . date('Y-m-d H:i:s')
                        . " tunnels marked offline: {$marked}\n";
                }
            } catch (\Throwable $tunnelError) {
                echo '[Worker] tunnel heartbeat check failed: '
                    . $tunnelError->getMessage() . "\n";
            }
        }

        // --- Inbound fax listener (FreeSWITCH ESL) --- 
        if ($faxListenerLoaded) {
            try {
                faxInboundListenerTick();
            } catch (\Throwable $faxError) {
                echo '[Worker] fax inbound listener tick failed: '
                    . $faxError->getMessage() . "\n";
            }
        }

        $consecutiveFailures = 0;

    } catch (\Throwable $e) {
        $consecutiveFailures++;
        if ($consecutiveFailures === 1
            || $e->getMessage() !== $lastFailureMessage
            || $consecutiveFailures % 30 === 0
        ) {
            echo "[Worker] Error (x{$consecutiveFailures}): " . $e->getMessage() . "\n";
        }
        $lastFailureMessage = $e->getMessage();

        if ($consecutiveFailures >= WORKER_MAX_CONSECUTIVE_FAILURES) {
            echo "[Worker] " . WORKER_MAX_CONSECUTIVE_FAILURES
                . " consecutive failed ticks; exiting so supervisor restarts a"
                . " clean process. Last error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    usleep(1000000);
}

function dispatchTimeMachineJobs($redis): void
{
    $queued = $redis->get('performBackup')
        || $redis->get('performRestore')
        || $redis->get('performImport')
        || $redis->llen('performDeleteQueue') > 0;
    if (!$queued) {
        return;
    }

    foreach (['restore', 'import', 'backup'] as $job) {
        $document = @file_get_contents('/var/lib/ringq/jobs/' . $job . '.json');
        if ($document === false) {
            continue;
        }
        $state = json_decode($document, true);
        if (isset($state['state']) && $state['state'] === 'running') {
            return;
        }
    }

    if ($redis->get(TIME_MACHINE_SPAWN_LOCK)) {
        return;
    }
    $redis->setex(TIME_MACHINE_SPAWN_LOCK, TIME_MACHINE_SPAWN_TTL, (string) time());
    $inner   = 'nohup ' . PHP_BINARY . ' ' . TIME_MACHINE_CLI . ' main runJobs >> '
        . TIME_MACHINE_LOG . ' 2>&1 &';
    $command = 'sudo -n /bin/sh -c ' . escapeshellarg($inner);

    $output = [];
    $status = 0;
    exec($command . ' > /dev/null 2>&1', $output, $status);
    if ($status !== 0) {
        echo "[Worker] Could not start the job runner (exit {$status}); cron will run it within a minute.\n";
    }
}



