<?php

use Phalcon\Db\Enum as DbEnum;

/*
|--------------------------------------------------------------------------
| faxInboundListener.php
|--------------------------------------------------------------------------
|
| NOTE (post-refactor): this file no longer boots its own Phalcon DI, its
| own config loading, or its own DB adapter/connection. It is now required
| by worker.php (via workerRequireJob('faxInboundListener.php')) the same
| way clinikoJob.php / salesforceJob.php / etc. are, and it calls the
| getDb() function that worker.php already defines globally -- so fax jobs
| always use the exact same DB connection/config path as every other job
| in worker.php. There is exactly one place DB config is resolved now:
| worker.php.
|
| This file used to be its own systemd-managed daemon process specifically
| so the FreeSWITCH ESL socket could sit in a blocking/select() read and
| react to CHANNEL_ANSWER within milliseconds, independent of worker.php's
| ~1s polling tick. That is no longer true now that it's merged in:
| faxInboundListenerTick() below is called once per worker.php tick and
| blocks for at most FAX_LISTENER_POLL_INTERVAL seconds waiting on the ESL
| socket, so reaction time to a real fax call is now bounded by
| worker.php's own loop cadence (tick time + FAX_LISTENER_POLL_INTERVAL),
| not by "milliseconds". Keep FAX_LISTENER_POLL_INTERVAL small if you need
| this to stay snappy.
|
| Everything else -- job creation, hangup handling, domain/DID resolution,
| TIFF->PDF conversion, file ownership fixing -- is unchanged from the
| original standalone daemon. (The WhatTheFone partner webhook that used
| to run here has been removed.)
|
*/

// ---------------------------------------------------------------------
// File ownership
// ---------------------------------------------------------------------

// The user PHP-FPM/your web server runs as -- the file MUST be readable by
// this user or FaxController::downloadAction() can't serve it. Override
// via env var if your web server user isn't www-data.
if (!defined('FAX_FILE_OWNER')) {
    define('FAX_FILE_OWNER', getenv('FAX_FILE_OWNER') ?: 'www-data');
}
if (!defined('FAX_FILE_GROUP')) {
    define('FAX_FILE_GROUP', getenv('FAX_FILE_GROUP') ?: 'www-data');
}

function faxlog($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] [FaxInbound] ' . $msg . "\n";
}

/*
 * Sets ownership + permissions on a just-created fax file so it's readable
 * by the web app regardless of which user THIS process happens to be
 * running as (now: whatever user runs worker.php).
 *
 * chown() to a different user only succeeds if the calling process is
 * root (or already owns the file and is changing to itself, a no-op).
 *   - worker.php running as www-data (correct/normal case): chown to
 *     www-data succeeds trivially since it's already the owner.
 *   - worker.php accidentally running as root: chown to www-data still
 *     succeeds, because root can chown to any user.
 *   - worker.php running as some other unprivileged user: chown silently
 *     fails; chmod still loosens read permissions as a partial
 *     mitigation, and this is logged so it's visible.
 */
function fixFileOwnership($path, $mode = 0644) {
    @chmod($path, $mode);

    $chownOk = @chown($path, FAX_FILE_OWNER);
    $chgrpOk = @chgrp($path, FAX_FILE_GROUP);

    if (!$chownOk || !$chgrpOk) {
        faxlog("fixFileOwnership: could not chown/chgrp {$path} to " . FAX_FILE_OWNER . ":" . FAX_FILE_GROUP . " (running as a non-root, non-owning user?) -- file may not be readable by the web app. Check the worker's process owner.");
    }
}

// ---------------------------------------------------------------------
// DB helpers -- each takes $db fresh from getDb() at call time. getDb()
// itself now lives ONLY in worker.php.
// ---------------------------------------------------------------------

function generateUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function createInboundJob($db, $domainUuid, $fromNumber, $toNumber, $callUuid, $tiffPath) {
    $jobId = generateUuid();

    $ok = $db->execute(
        "INSERT INTO fax_jobs
         (id, domain_uuid, direction, from_number, to_number, pages, status, file_path, call_uuid, created_at, updated_at)
         VALUES (?::uuid, ?::uuid, 'in', ?, ?, 0, 'receiving', ?, ?, EXTRACT(EPOCH FROM NOW()), EXTRACT(EPOCH FROM NOW()))",
        [$jobId, $domainUuid, $fromNumber, $toNumber, $tiffPath, $callUuid]
    );

    if ($ok === false) {
        faxlog("createInboundJob: db->execute() returned FALSE (no exception) for job {$jobId} -- insert did not apply. Check DB user permissions / constraints on fax_jobs.");
        return null;
    }

    return $jobId;
}

function getJobByCallUuid($db, $callUuid) {
    $row = $db->fetchOne(
        "SELECT * FROM fax_jobs WHERE call_uuid = ? AND direction = 'in' ORDER BY created_at DESC LIMIT 1",
        DbEnum::FETCH_ASSOC,
        [$callUuid]
    );
    return $row ?: null;
}

function updateFaxJob($db, $jobId, $domainUuid, $fields) {
    if (!$fields) {
        return true;
    }

    $allowed = ['status', 'file_path', 'call_uuid', 'esl_job_uuid', 'pages', 'error_reason'];
    $sets = [];
    $params = [];

    foreach ($fields as $column => $value) {
        if (!in_array($column, $allowed, true)) {
            continue;
        }
        $sets[] = $column . ' = ?';
        $params[] = $value;
    }

    if (!$sets) {
        return true;
    }

    $sets[] = 'updated_at = EXTRACT(EPOCH FROM NOW())';
    $sql = 'UPDATE fax_jobs SET ' . implode(', ', $sets) . ' WHERE id = ?::uuid';
    $params[] = $jobId;

    if ($domainUuid !== null) {
        $sql .= ' AND domain_uuid = ?::uuid';
        $params[] = $domainUuid;
    }

    $ok = $db->execute($sql, $params);

    if ($ok === false) {
        faxlog("updateFaxJob: db->execute() returned FALSE (no exception) for job {$jobId} -- update did not apply.");
        return false;
    }

    $affected = method_exists($db, 'affectedRows') ? $db->affectedRows() : null;
    if ($affected === 0) {
        faxlog("updateFaxJob: statement succeeded but matched 0 rows for job {$jobId} (domain_uuid={$domainUuid}) -- check the job id/domain_uuid actually match an existing row.");
    }

    return true;
}

function getFreeswitchCredential($db) {
    $row = $db->fetchOne(
        "SELECT host, port, password FROM freeswitch_credentials WHERE status = TRUE ORDER BY id ASC LIMIT 1",
        DbEnum::FETCH_ASSOC
    );
    return $row ?: null;
}


function resolveReceivingDomainUuid($db, $toNumber) {
    if (!$toNumber) {
        return null;
    }
    $row = $db->fetchOne(
        "SELECT domain_uuid FROM q_dids WHERE did_number = ? LIMIT 1",
        DbEnum::FETCH_ASSOC,
        [$toNumber]
    );
    $resolved = $row['domain_uuid'] ?? null;
    faxlog("resolveReceivingDomainUuid: looked up did_number='{$toNumber}' -> domain_uuid=" . ($resolved ?? 'NOT FOUND'));
    return $resolved;
}

// ---------------------------------------------------------------------
// TIFF -> PDF
// ---------------------------------------------------------------------

function ensureDirectory($directory) {
    if (is_dir($directory)) return;
    if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new \RuntimeException('Unable to create directory: ' . $directory);
    }
    fixFileOwnership($directory, 0755);
}

function runCommand($command) {
    $escaped = array_map('escapeshellarg', array_map('strval', $command));
    $commandLine = implode(' ', $escaped);
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($commandLine, $descriptorSpec, $pipes);
    if (!is_resource($process)) throw new \RuntimeException('Unable to execute command');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => trim($stderr)];
}

function tiffToPdf($tiffPath, $pdfPath) {
    if (!is_file($tiffPath)) {
        throw new \RuntimeException('TIFF file not found: ' . $tiffPath);
    }
    $result = runCommand(['tiff2pdf', '-o', $pdfPath, $tiffPath]);
    if ($result['exit_code'] !== 0) {
        throw new \RuntimeException('TIFF to PDF conversion failed: ' . $result['stderr']);
    }
    if (!is_file($pdfPath) || filesize($pdfPath) <= 0) {
        throw new \RuntimeException('TIFF to PDF conversion produced no file');
    }
    fixFileOwnership($pdfPath);
}

// ---------------------------------------------------------------------
// ESL
// ---------------------------------------------------------------------

function connectFreeSwitch($credential) {
    $host = trim((string) $credential['host']) ?: '127.0.0.1';
    $port = (int) $credential['port'] ?: 8021;
    $password = (string) $credential['password'];

    if ($password === '') {
        throw new \RuntimeException('FreeSWITCH password is not configured');
    }

    $errno = 0; $errstr = '';
    $socket = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$socket) {
        throw new \RuntimeException("Unable to connect to FreeSWITCH ESL: {$errstr} ({$errno})");
    }

    stream_set_timeout($socket, 5);
    $response = readRawUntilBlank($socket);
    if (stripos($response, 'auth/request') === false) {
        fclose($socket);
        throw new \RuntimeException('Invalid FreeSWITCH ESL response: ' . trim($response));
    }

    fwrite($socket, "auth {$password}\n\n");
    $authResponse = readRawUntilBlank($socket);
    if (stripos($authResponse, '+OK accepted') === false) {
        fclose($socket);
        throw new \RuntimeException('FreeSWITCH ESL authentication failed: ' . trim($authResponse));
    }

    stream_set_blocking($socket, false);
    return $socket;
}

function readRawUntilBlank($socket) {
    stream_set_blocking($socket, true);
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket);
        if ($line === false) break;
        $response .= $line;
        if (rtrim($line, "\r\n") === '') break;
    }
    return $response;
}

function writeEslCommand($socket, $command) {
    $data = $command . "\n\n";
    $length = strlen($data);
    $written = 0;
    stream_set_blocking($socket, true);
    while ($written < $length) {
        $result = fwrite($socket, substr($data, $written));
        if ($result === false) throw new \RuntimeException('Failed writing to FreeSWITCH ESL');
        $written += $result;
    }
    stream_set_blocking($socket, false);
}

function readEslMessageBlocking($socket, $timeout = 10) {
    stream_set_blocking($socket, true);
    stream_set_timeout($socket, $timeout);
    $message = readOneEslMessage($socket);
    stream_set_blocking($socket, false);
    return $message;
}

function readOneEslMessage($socket) {
    $headers = [];
    $headerText = '';

    while (!feof($socket)) {
        $line = fgets($socket);
        if ($line === false) break;
        $line = rtrim($line, "\r\n");
        if ($line === '') break;
        $headerText .= $line . "\n";
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $headers[trim($key)] = trim($value);
        }
    }

    $body = '';
    $contentLength = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : 0;
    if ($contentLength > 0) {
        $remaining = $contentLength;
        while ($remaining > 0 && !feof($socket)) {
            $chunk = fread($socket, $remaining);
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }
    }

    if (($headers['Content-Type'] ?? '') === 'text/event-plain' && $body !== '') {
        foreach (preg_split('/\r?\n/', $body) as $bodyLine) {
            if ($bodyLine === '' || strpos($bodyLine, ':') === false) continue;
            list($key, $value) = explode(':', $bodyLine, 2);
            $key = trim($key);
            if ($key !== '') {
                $headers[$key] = urldecode(trim($value));
            }
        }
    }

    return ['headers' => $headers, 'body' => $body, 'raw_headers' => $headerText];
}

function readAvailableEslMessages($socket, $timeoutSecs) {
    $read = [$socket];
    $write = null;
    $except = null;

    $sec = (int) floor($timeoutSecs);
    $usec = (int) (($timeoutSecs - $sec) * 1000000);

    $changed = @stream_select($read, $write, $except, $sec, $usec);
    if ($changed === false || $changed === 0) {
        return [];
    }

    stream_set_blocking($socket, true);
    $messages = [];

    while (true) {
        $meta = stream_get_meta_data($socket);
        if ($meta['eof']) {
            throw new \RuntimeException('FreeSWITCH ESL connection closed');
        }

        $message = readOneEslMessage($socket);
        if (empty($message['headers'])) break;
        $messages[] = $message;

        $read2 = [$socket];
        $changed2 = @stream_select($read2, $write, $except, 0, 0);
        if (!$changed2) break;
    }

    stream_set_blocking($socket, false);
    return $messages;
}

// ---------------------------------------------------------------------
// Main entry point -- called once per worker.php loop tick.
// ---------------------------------------------------------------------

function faxInboundListenerTick() {
    static $inboxPdfDir     = null;
    static $inboundTiffDir  = null;
    static $pollIntervalSec = null;
    static $staleTimeoutSec = null;
    static $socket          = null;
    static $activeCalls     = [];
    static $lastNoCredLog   = 0;
    static $reconnectBackoff = null;
    static $nextConnectAt    = 0;
    static $connectFailures  = 0;

    if ($inboxPdfDir === null) {
        $inboxPdfDir     = getenv('INBOX_PDF_DIR') ?: '/var/spool/fax/inbox';
        $inboundTiffDir  = getenv('INBOUND_TIFF_DIR') ?: '/var/spool/fax/inbound_tiff';
        $pollIntervalSec = (float) (getenv('FAX_LISTENER_POLL_INTERVAL') ?: 0.25);
        $staleTimeoutSec = (int) (getenv('FAX_INBOUND_STALE_TIMEOUT') ?: 300);
        $reconnectBackoff = max(1, (int) (getenv('FAX_LISTENER_RECONNECT_BACKOFF') ?: 30));
        faxlog('fax inbound tick handler active inside worker.php (poll_interval=' . $pollIntervalSec . 's)');
    }

    $db = getDb();

    if (!$socket) {
        if (time() < $nextConnectAt) {
            return;
        }

        $credential = getFreeswitchCredential($db);
        if (!$credential) {
            if (time() - $lastNoCredLog > 30) {
                faxlog('No active FreeSWITCH credentials configured, retrying');
                $lastNoCredLog = time();
            }
            return;
        }

        try {
            $socket = connectFreeSwitch($credential);
            writeEslCommand($socket, 'event plain CHANNEL_ANSWER CHANNEL_HANGUP_COMPLETE');
            $subscribeResponse = readEslMessageBlocking($socket, 10);
            if (stripos($subscribeResponse['raw_headers'] ?? '', '+OK') === false) {
                throw new \RuntimeException('Failed to subscribe to FreeSWITCH events: ' . trim($subscribeResponse['raw_headers'] ?? ''));
            }
            faxlog('Connected to FreeSWITCH ESL and subscribed to inbound-relevant events');
            $connectFailures = 0;
            $nextConnectAt   = 0;
        } catch (\Throwable $e) {
            $connectFailures++;
            if ($connectFailures === 1 || $connectFailures % 20 === 0) {
                faxlog('faxInboundListenerTick: connect failed (x' . $connectFailures
                    . ', retrying in ' . $reconnectBackoff . 's): ' . $e->getMessage());
            }
            $nextConnectAt = time() + $reconnectBackoff;
            if ($socket) { @fclose($socket); }
            $socket = null;
            return;
        }
    }

    try {
        $messages = readAvailableEslMessages($socket, $pollIntervalSec);
    } catch (\Throwable $e) {
        faxlog('faxInboundListenerTick: ESL read error: ' . $e->getMessage() . ' -- will reconnect next tick');
        @fclose($socket);
        $socket = null;
        return;
    }

    foreach ($messages as $message) {

        $eventName = $message['headers']['Event-Name'] ?? '';
        if ($eventName === '') continue;

        $direction = $message['headers']['variable_fax_direction'] ?? '';
        if ($direction !== 'in') continue;

        if ($eventName === 'CHANNEL_ANSWER') {

            $callUuid = $message['headers']['Unique-ID'] ?? null;
            $fromNumber = $message['headers']['Caller-Caller-ID-Number'] ?? 'unknown';
            $toNumber = $message['headers']['Caller-Destination-Number'] ?? 'unknown';
            $tiffPath = $message['headers']['variable_fax_file']
                ?? (rtrim($inboundTiffDir, '/') . '/' . $callUuid . '.tiff');

            if (!$callUuid) {
                faxlog('CHANNEL_ANSWER (inbound fax) with no Unique-ID, skipping');
                continue;
            }

            $domainUuid = resolveReceivingDomainUuid($db, $toNumber);

            if (!$domainUuid) {
                faxlog("Inbound fax call {$callUuid}: could not resolve receiving domain for DID {$toNumber} (not found in q_dids). Skipping.");
                continue;
            }

            ensureDirectory($inboundTiffDir);

            try {
                $jobId = createInboundJob($db, $domainUuid, $fromNumber, $toNumber, $callUuid, $tiffPath);
                if ($jobId) {
                    $activeCalls[$callUuid] = [
                        'job_id' => $jobId,
                        'domain_uuid' => $domainUuid,
                        'started_at' => time(),
                    ];
                    faxlog("Inbound fax call {$callUuid} -> job {$jobId} (domain {$domainUuid}, {$fromNumber} -> {$toNumber})");
                }
            } catch (\Throwable $e) {
                faxlog("Exception creating inbound job for call {$callUuid}: " . $e->getMessage());
            }

            continue;
        }

        if ($eventName === 'CHANNEL_HANGUP_COMPLETE') {

            $callUuid = $message['headers']['Unique-ID'] ?? null;
            $jobId = $message['headers']['variable_fax_job_id'] ?? null;
            $domainUuidFallback = null;

            faxlog("CHANNEL_HANGUP_COMPLETE raw: call={$callUuid} Caller-Caller-ID-Number=" . ($message['headers']['Caller-Caller-ID-Number'] ?? 'MISSING') . " Caller-Destination-Number=" . ($message['headers']['Caller-Destination-Number'] ?? 'MISSING') . " variable_domain_uuid=" . ($message['headers']['variable_domain_uuid'] ?? 'MISSING'));

            $callInfo = $callUuid ? ($activeCalls[$callUuid] ?? null) : null;

            if (!$jobId && $callInfo) {
                $jobId = $callInfo['job_id'];
            }

            if (!$jobId && $callUuid) {
                $job = getJobByCallUuid($db, $callUuid);
                $jobId = $job['id'] ?? null;
                $domainUuidFallback = $job['domain_uuid'] ?? null;
            }

            $domainUuid = $callInfo['domain_uuid'] ?? $domainUuidFallback;

            if (!$jobId) {
                $toNumber = $message['headers']['Caller-Destination-Number'] ?? 'unknown';

                $domainUuid = resolveReceivingDomainUuid($db, $toNumber);

                if (!$domainUuid) {
                    faxlog("Hangup for inbound fax call {$callUuid} but no matching job AND could not resolve receiving domain for DID {$toNumber} (not found in q_dids) -- cannot create one.");
                    continue;
                }

                $fromNumber = $message['headers']['Caller-Caller-ID-Number'] ?? 'unknown';
                $tiffPath = $message['headers']['variable_fax_file']
                    ?? (rtrim($inboundTiffDir, '/') . '/' . $callUuid . '.tiff');

                try {
                    $jobId = createInboundJob($db, $domainUuid, $fromNumber, $toNumber, $callUuid, $tiffPath);
                    if ($jobId) {
                        faxlog("Job {$jobId} created at hangup time (CHANNEL_ANSWER never produced one -- direction/domain_uuid likely weren't set on the channel yet at answer time) for call {$callUuid}");
                    }
                } catch (\Throwable $e) {
                    faxlog("Exception creating fallback inbound job at hangup for call {$callUuid}: " . $e->getMessage());
                }

                if (!$jobId) {
                    faxlog("Hangup for inbound fax call {$callUuid}: fallback job creation also failed, giving up on this call");
                    continue;
                }
            }

            $faxSuccess = ($message['headers']['variable_fax_success'] ?? '') === '1';
            $resultText = $message['headers']['variable_fax_result_text'] ?? '';
            $pages = (int) ($message['headers']['variable_fax_document_transferred_pages'] ?? 0);

            if (!$faxSuccess) {
                updateFaxJob($db, $jobId, $domainUuid, [
                    'status' => 'failed', 'pages' => $pages, 'error_reason' => $resultText,
                ]);
                faxlog("Job {$jobId} (in) failed: {$resultText}");
                if ($callUuid) unset($activeCalls[$callUuid]);
                continue;
            }

            $tiffPath = $callInfo
                ? (rtrim($inboundTiffDir, '/') . '/' . $callUuid . '.tiff')
                : ($message['headers']['variable_fax_file']
                    ?? (rtrim($inboundTiffDir, '/') . '/' . $callUuid . '.tiff'));

            try {
                ensureDirectory($inboxPdfDir);
                $pdfPath = rtrim($inboxPdfDir, '/') . '/' . $jobId . '.pdf';
                tiffToPdf($tiffPath, $pdfPath);
                updateFaxJob($db, $jobId, $domainUuid, [
                    'status' => 'received', 'pages' => $pages, 'file_path' => $pdfPath,
                ]);
                faxlog("Job {$jobId} (in) received: {$pages} pages -> {$pdfPath}");

                // ---------------------------------------------------------
                // NEW (additive): extension-routed email delivery.
                // For q_dids rows with type = 8, resolve the destination
                // user's email via v_extensions -> v_extension_users ->
                // v_users, and email them the received fax PDF. Wrapped
                // so any failure here can never affect the job status
                // already committed above.
                // ---------------------------------------------------------
                try {
                    if (!function_exists('sendFaxReceivedEmailToExtension')) {
                        require_once __DIR__ . '/faxExtensionEmailNotifier.php';
                    }
                    $didRouting = getDidRoutingDetails($db, $message['headers']['Caller-Destination-Number'] ?? 'unknown');
                    if ($didRouting && (int) ($didRouting['type'] ?? 0) === FAX_DID_TYPE_EXTENSION_EMAIL) {
                        sendFaxReceivedEmailToExtension(
                            $db,
                            $didRouting['extension'],
                            $message['headers']['Caller-Caller-ID-Number'] ?? 'unknown',
                            $message['headers']['Caller-Destination-Number'] ?? 'unknown',
                            $pdfPath,
                            $pages
                        );
                    }
                } catch (\Throwable $e) {
                    faxlog("Job {$jobId} (in): extension email notification failed (non-fatal): " . $e->getMessage());
                }
            } catch (\Throwable $e) {
                updateFaxJob($db, $jobId, $domainUuid, [
                    'status' => 'failed', 'pages' => $pages, 'error_reason' => $e->getMessage(),
                ]);
                faxlog("Job {$jobId} (in) TIFF->PDF conversion failed: " . $e->getMessage());
            }

            if ($callUuid) unset($activeCalls[$callUuid]);
            continue;
        }
    }

    foreach ($activeCalls as $callUuid => $info) {
        if ((time() - $info['started_at']) > $staleTimeoutSec) {
            faxlog("Call {$callUuid} / job {$info['job_id']} exceeded stale timeout ({$staleTimeoutSec}s), marking failed");
            updateFaxJob($db, $info['job_id'], $info['domain_uuid'], [
                'status' => 'failed',
                'error_reason' => 'Timed out waiting for a call result from FreeSWITCH',
            ]);
            unset($activeCalls[$callUuid]);
        }
    }
}
