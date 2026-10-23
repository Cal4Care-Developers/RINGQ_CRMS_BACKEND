<?php
use Phalcon\Crypt;
use Phalcon\Loader;
use Phalcon\Mvc\View;
use Phalcon\Cli\Console as ConsoleApp;
use Phalcon\Di\FactoryDefault\Cli as CliDI;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Cache\Frontend\Data as FrontendData;
use Phalcon\Mvc\Model\Metadata\Memory as MetaDataAdapter;

$di = new CliDI();
$loader = new Loader();
$loader->registerDirs(
    [
        __DIR__ . '/models',
        __DIR__ . "/tasks",
        __DIR__ . "/views"
    ]
);
$loader->register();
include __DIR__ .'/../vendor/autoload.php';
$configFile = __DIR__ . "/config/config.php";
include __DIR__ .'/library/Email.php';
$config = include $configFile;
$di->set("config", $config);
$di->set('view', function () {
    $config = $this->getConfig();
    $view = new View();
    $view->setDI($this);
    $view->setViewsDir($config->application->viewsDir);
    $view->registerEngines([
        '.volt' => function ($view) {
            $config = $this->getConfig();
            $volt = new VoltEngine($view, $this);
            $volt->setOptions([
                'compiledPath' => $config->application->cacheDir,
                'compiledSeparator' => '_'
            ]);
            return $volt;
        }
    ]);
    return $view;
});
$di->set("db", function () {
    $config = $this->getConfig();
    $class = 'Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
    $params = [
        'host'     => $config->database->host,
        'username' => $config->database->username,
        'password' => $config->database->password,
        'dbname'   => $config->database->dbname,
        'charset'  => $config->database->charset
    ];
    // PostgreSQL has no `charset` connection parameter. Phalcon's Pdo adapter
    // serialises every leftover descriptor key into the DSN, so leaving it here
    // -- even as NULL -- yields `pgsql:...;charset=` and libpq answers
    // SQLSTATE[08006] "invalid connection option \"charset\"", killing every
    // cli.php task on its first model access. app/config/services.php has
    // carried this same guard for the web tier since the repo reorg.
    if ($config->database->adapter == "Postgresql") {
        unset($params["charset"]);
    }
    $connection = new $class($params);
    return $connection;
});
$di->set("modelsMetadata", function () {
    return new MetaDataAdapter();
});
$di->set("crypt", function() {
    $config = $this->getConfig();
    $crypt = new Crypt();
    $crypt->setKey($config->application->cryptSalt);
    return $crypt;
}, true);
$di->set("redis", function() {
    $cache = new Predis\Client();
    return $cache;
}, true);
$console = new ConsoleApp();
$console->setDI($di);
$arguments = [];
foreach ($argv as $k => $arg) {
    if ($k === 1) {
        $arguments["task"] = $arg;
    } elseif ($k === 2) {
        $arguments["action"] = $arg;
    } elseif ($k >= 3) {
        $arguments["params"][] = $arg;
    }
}
try {
    $console->handle($arguments);
} catch (\Phalcon\Exception $e) {
    echo $e->getMessage();
    exit(255);
}
