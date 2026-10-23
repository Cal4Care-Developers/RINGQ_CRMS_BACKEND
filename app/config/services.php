<?php
declare(strict_types=1);

use Phalcon\Crypt;
use Phalcon\Acl\Resource;
use Phalcon\Acl\Enum;
use Phalcon\Acl\Adapter\Memory;
use Phalcon\Acl\Role;
use Phalcon\Acl\Component;
use Phalcon\Escaper;
use Phalcon\Flash\Direct as Flash;
use Phalcon\Flash\Session as FlashSession;
use Phalcon\Mvc\Model\Metadata\Memory as MetaDataAdapter;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Session\Adapter\Stream as SessionAdapter;
use Phalcon\Session\Manager as SessionManager;
use Phalcon\Url as UrlResolver;
use Phalcon\Session\Adapter\Libmemcached;
use Phalcon\Session\Manager;
use Phalcon\Storage\AdapterFactory;
use Phalcon\Storage\SerializerFactory;
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use Phalcon\Mvc\Model\Manager as ModelsManager;

use Phalcon\Cache\AdapterFactory as MetaDataAdapterFactory;
use Phalcon\Mvc\Model\Metadata\Libmemcached as MetaDataMemcached;

use Cronfig\Sysinfo\System;
use Cylab\System\System as System2;
use Shuchkin\SimpleCSV;

/**
 * Shared configuration service
 */
$di->setShared("config", function () {
   return include APP_PATH . "/config/config.php";
});

/**
 * The URL component is used to generate all kind of urls in the application
 */
$di->setShared("url", function () {
   $config = $this->getConfig();

   $url = new UrlResolver();
   $url->setBaseUri($config->application->baseUri);

   return $url;
});

$di->set(
   "dispatcher",
   function () {
      $eventsManager = new \Phalcon\Events\Manager();
      $eventsManager->attach("dispatch:beforeException", function ($event, $dispatcher, $exception) {
         //Handle 404 exceptions
         if ($exception instanceof \Phalcon\Mvc\Dispatcher\Exception) {
            $dispatcher->forward([
               "controller" => "index",
               "action" => "index",
            ]);
            return false;
         }
         //Handle other exceptions
         $dispatcher->forward([
            "controller" => "index",
            "action" => "index",
         ]);
         return false;
      });
      $dispatcher = new \Phalcon\Mvc\Dispatcher();
      //Bind the EventsManager to the dispatcher
      $dispatcher->setEventsManager($eventsManager);
      return $dispatcher;
   },
   true
);

/**
 * Setting up the view component
 */
$di->setShared("view", function () {
   $config = $this->getConfig();

   $view = new View();
   $view->setDI($this);
   $view->setViewsDir($config->application->viewsDir);

   $view->registerEngines([
      ".volt" => function ($view) {
         $config = $this->getConfig();

         $volt = new VoltEngine($view, $this);

         $volt->setOptions([
            "path" => $config->application->cacheDir,
            "separator" => "_",
         ]);

         return $volt;
      },
      ".phtml" => PhpEngine::class,
   ]);

   return $view;
});

/**
 * Database connection is created based in the parameters defined in the configuration file
 */
$di->setShared("db", function () {
   $config = $this->getConfig();

   $class = "Phalcon\Db\Adapter\Pdo\\" . $config->database->adapter;
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

/**
 * If the configuration specify the use of metadata adapter use it or use memory otherwise
 */
$di->setShared("modelsMetadata", function () {
   // [perf] Model metadata (table columns / types) was introspected from Postgres on every
   // request ("SELECT ... column_name ..." per model), a large share of PHP and Postgres CPU.
   // Cached in the local memcached for 10 minutes; the key prefix includes the release version
   // and database name, so an update (which may ALTER tables) starts with a fresh cache.
   // Falls back to the per-request memory adapter if memcached is not available.
   try {
      $config = $this->getConfig();
      $ver = @file_get_contents(__DIR__ . "/../../version.txt");
      $ver = preg_replace("/[^0-9A-Za-z._-]/", "", (string) $ver);
      $db = preg_replace("/[^0-9A-Za-z_]/", "", (string) ($config->database->dbname ?? "ringq"));
      $factory = new MetaDataAdapterFactory(new SerializerFactory());
      return new MetaDataMemcached($factory, [
         "servers" => [["host" => "127.0.0.1", "port" => 11211, "weight" => 1]],
         "lifetime" => 600,
         "prefix" => "ringqmeta-" . $db . "-" . $ver . "-",
      ]);
   } catch (\Throwable $e) {
      return new MetaDataAdapter();
   }
});

$di->setShared("modelsManager", function () {
	$modelsManager = new ModelsManager();
	return $modelsManager;
});

/**
 * Register the session flash service with the Twitter Bootstrap classes
 */
$di->set("flash", function () {
   $escaper = new Escaper();
   $flash = new Flash($escaper);
   $flash->setImplicitFlush(false);
   $flash->setCssClasses([
      "error" => "alert alert-danger",
      "success" => "alert alert-success",
      "notice" => "alert alert-info",
      "warning" => "alert alert-warning",
   ]);

   return $flash;
});

$di->set(
   "redis",
   function () {
      $cache = new Predis\Client();
      $cache->connect("/var/run/redis/redis-server.sock");
      return $cache;
   },
   true
);

$di->set(
   "acl",
   function () {
      $acl = new Memory();
      $acl->setDefaultAction(Enum::DENY);
      $acl->addRole("nonregistered");
      $publicly = [
         "api" => ["index", "appversion", "agentactivity", "auxcodes", "auxcodes2", "assets", "blf", "callagent", "callcenter", "callcost", "callmanager", "callrating", "callqueue", "chats", "chatLobby", "clearcache", "conference", "contact", "contacts", "debug", "destinations", "did", "fax", "followme", "gateway", "history", "holidayexempt", "ivr", "queues", "recordings", "scheduling", "scheduling2", "search", "timescheduling", "timescheduling2", "outbound", "overall", "upload", "outboundrules", "upload2", "upload3", "upload4", "voicemails", "voicemails2", "wrapupnotes", "wallboard", "wrapupnotes2", "webautologout", "user", "siprepair", "dbownerrepair", "queueagentreload", "pushrepair", "servicerestart", "mailtest", "aiagentrepair", "aiagenttest", "aiagentlog", "aiagentrestart", "chatassign", "chatresolve", "permissionsrepair", "permissionsrollback", "sipcredentials", "lists", "hangup"],
         "index" => ["index", "messenger", "whatsapp", "whatsappsend", "autoprov", "createKey", "revokeKey", "rotateKey", "keyStatus", "saveCallsAPI", "listCallAPIs", "makeCall", "divertCallByExtension", "divertCallToVoicemail", "pickupCallByExtension", "transferCall", "dropCall", "listenCall", "whisperCall", "bargeInCall", "recordStart", "recordStop", "joinCall", "recordingList", "recordingLink", "voicemailDelete", "changeVoicemailPin", "queueLogin", "queueLogout", "addAgent", "addAgentsQueue", "addIvr", "addQueue", "changeRecordingPath", "changeRecordingStatus", "changeUserStatus", "changeVmPath", "deleteAgent", "deleteIvr", "deleteQueue", "disableExtension", "disableExternalCall", "hotelModule", "playgroundCall", "queryExtension", "removeAgentsQueue", "sendWelcomeEmail", "updateAgent", "updateQueue", "voicemailList", "license", "revokeKeys", "joinCall2", "downloadRecording"],
         "reports" => ["history","callhistory", "callcost", "callrating", "overall", "wrapupnotes", "wrapupnotes2","auxcodes2","agentactivity","callqueue", "agentsreport", "callrecordings", "nodeaudiototext","audiototext", "getInterfaces", "startCapture", "stopCapture", "captureDebug", "downloadCapture", "showsingleCapture", "showsingleCapture2", "showsingleCapture3", "runTerminal", "scheduleReports", "saveCustomWebhookDetails", "getCustomWebhookDetails", "writeLog", "getCustomWebhookDetailss", "nodeivrmenus"],
         "calls" => ["uploadZipAll", "uploadZipExtension","uploadZipTrunk","uploadZipDID","uploadZipOutbound"],
         "chat" => ["index"],
         "customize" => ["index", "intercom", "blf"],
         "accounts" => ["index", "ai", "avatar", "billing", "callbroadcast", "callprofile", "chameleon", "chatsetting","credentail", "delete", "devices", "did", "downloademail", "extension", "extension2", "extension3", "gateway", "integration", "interface", "acl", "logout", "schedule", "restriction", "sipprofile", "sso", "security", "support", "teams", "voicemail", "wrapupemail", "autosip", "autoprov", "phonetemp", "provisionhosts", "booking", "webinar", "workhours", "connect", "linkedin", "ringqtalk", "backup", "livetranscriptdata", "mcpupdate"],
         "access" => ["diagnostics", "terminal"],
         "login" => ["index", "forgot", "recover", "recover2", "cspreport"],
         "register" => ["index", "checker", "checker2", "extension", "firewall", "number", "resender", "system", "verify", "calllog", "delete", "updateversion", "gateway", "sip", "extensions", "did", "zohoPhonebridge", "zohoWebhook", "zohoGetUserDet", "zohoGetContactDet", "zohoGetTicketDet", "zohoCallJournaling", "zohoplayrecoding", "saveZohoIntegration", "savezendeskIntegration", "zendeskwebhook", "zendeskGetContactDet", "zendeskGetTicketDet", "dialercontacts", "apiclearcache", "wallet", "whatsapp", "messenger", "telegram", "line", "recover", "backup"],
         "ringqtalk" => ["config", "session", "link"],
         "chatadmin" => ["getassign"],
         "sms" => ["index","data","sendingsingle","sendingbulk","tikosmswebhook", "tikossingle", "tikosbulk", "tikosconfig", "telnyxconfig", "telnyxwebhook", "twiliowebhook", "twilioconfig"],
         "campaign" => ["index", "bulkUploadCampaign","downloadCsv","getCampaignlist","create","edit","update","delete"],
         // MR Contact sync 21-09-2026 starts
         "teams"=>["extensionDetails", "getContacts", "callhistory", "init", "enableTeams", "o365settings", "orgContacts", "callback", "login", "contactsFilter", "googlesettings", "googlecallback", "googledisconnect", "googlelogin", "mslogin", "ssologin", "ssoconfig", "contactsByNumber", "customCrmContactSettings", "saveCustomCrmContactSettings", "syncCustomCrmContacts", "pushCustomCrmContacts", "demoCrmWebhook"],
         // MR Contact sync 21-09-2026 ends
         "teamsai"=>[ "demowebhook", "demowebhooklogs", "resolveagent", "contactsbynumber", "voicesettings", "aiconnectors", "aiconnect", "aiconnectcallback", "aidisconnect", "aiconnectortoken", "aikbupload", "aikbcrawl", "aikbcrawlsave", "aikbrecrawl", "aikbsources", "aikbdelete", "aikbsearch", "saveaicall"],
         "telegram" => ["register", "submitOtp", "status", "restart", "stop", "logs", "deleteService"],
         "meetApi" => ["tenantMeetConfig"],
         "scheduleApi" => ["schedules", "hosts", "availability"],
         "calendar" => ["settings", "status", "connect", "callback", "events", "push", "disconnect"],
         "licensing" => ["verifyInstallationType", "registerlicence", "resendotp", "status", "interfaces", "setupNetworkProfiles"],
         "desktop" => ["login", "autologintoken", "autologin", "calllogs", "contacts", "conferenceInitiate", "conferenceParticipants", "conferenceKick", "conferenceEscalate", "conferenceInvite", "killsession", "version"],
         "apichat" => ["conversations", "inbox", "telnyx", "twilio", "chatwebhook", "chatsend", "genericsend", "telnyxsend", "twiliosend", "telnyxconversation", "twilioconversation", "telnyxwebhook", "twiliowebhook"],
         "system" => ["index"],
         "ports" => ["index", "scan", "status", "scancancel", "scanall"],
         "backup" => ["backup"],
         "booking" => ["booking"],
         "language" => ["language"],
         "schedule" => ["schedule"],
         "webinar" => ["webinar"],
         "workhours" => ["workhours"],
         "ssl" => ["info", "renew", "status"],
         "ai" => ["get", "save", "list", "delete", "token", "googleAuthorize", "googleCallback", "microsoftAuthorize", "microsoftCallback", "runTests"],
         "storage" => ["usage", "cleanup", "policy", "savePolicy", "simulate", "saveNotify"],
         "tunnel" => ["create", "list", "view", "update", "delete", "status","bind","heartbeat","generateAuthKey"],
         "audit" => ["list", "features", "settings", "saveSettings", "download"],
         "calldebug" => ["status", "toggle", "download", "tail"],
         "fax" => ["jobsList", "settings", "dids","send","updateSettings","status","download"],
         "hotel" => ["rooms", "checkin", "checkout", "wakeupSet", "wakeupClear", "ivrSettings", "frontDeskMenu", "roomNumberMode"],
         // Boutique Hotel guest QR sign-in (HotelpbxController self-checks the admin actions)
         "hotelpbx" => ["info", "join", "guest"],
         "mcp" => ["tool", "account", "staff"],
         "mcpai" => ["connectors", "discover"],
         "oauth" => ["resource", "metadata", "register", "authorize", "token", "revoke"],
         // CORS preflight (OPTIONS, no cookies) must pass; RolesController checks the session itself.
         "roles" => ["custom", "mine"],
         // same for number blocking: NumberblockController checks the session and admin rights itself
         "numberblock" => ["list", "save", "delete", "check"],
         "qontak" => ["webhooks", "recording"],
         // HubSpot: signed recording link (HubSpot downloads it, no cookie).
         "hubspotcrm" => ["recording", "cti"]
      ];
      foreach ($publicly as $name => $actions) {
         $acl->addComponent(new Component($name), $actions);
      }
      foreach ($publicly as $resource => $actions) {
         $acl->allow("nonregistered", $resource, $actions);
      }
      $acl->addRole("registered");
      $privately = [
         "api" => ["index", "appversion", "agentactivity", "auxcodes", "auxcodes2", "assets", "blf", "callagent", "callcenter", "callcost", "callmanager", "callrating", "callqueue", "chats", "chatLobby", "clearcache", "conference", "contact", "contacts",  "debug", "destinations", "did", "fax", "followme", "gateway", "history", "holidayexempt", "ivr", "messages", "queues", "recordings", "scheduling", "scheduling2", "search", "timescheduling", "timescheduling2", "outbound", "outboundrules", "overall", "upload", "upload2", "upload3", "upload4", "voicemails", "voicemails2", "wrapupnotes", "wallboard", "wrapupnotes2", "webautologout", "user", "siprepair", "dbownerrepair", "queueagentreload", "pushrepair", "servicerestart", "mailtest", "aiagentrepair", "aiagenttest", "aiagentlog", "aiagentrestart", "chatassign", "chatresolve", "permissionsrepair", "permissionsrollback", "sipcredentials", "lists", "hangup"],
         "index" => ["index", "messenger", "whatsapp", "autoprov", "createKey", "revokeKey", "rotateKey", "keyStatus", "saveCallsAPI", "listCallAPIs", "makeCall", "divertCallByExtension", "divertCallToVoicemail", "pickupCallByExtension", "transferCall", "dropCall", "listenCall", "whisperCall", "bargeInCall", "recordStart", "recordStop", "joinCall", "recordingList", "recordingLink", "voicemailDelete", "changeVoicemailPin", "queueLogin", "queueLogout", "addAgent", "addAgentsQueue", "addIvr", "addQueue", "changeRecordingPath", "changeRecordingStatus", "changeUserStatus", "changeVmPath", "deleteAgent", "deleteIvr", "deleteQueue", "disableExtension", "disableExternalCall", "hotelModule", "playgroundCall", "queryExtension", "removeAgentsQueue", "sendWelcomeEmail", "updateAgent", "updateQueue", "voicemailList", "license", "revokeKeys", "joinCall2", "downloadRecording"],
         "reports" => ["history","callhistory", "callcost", "callrating", "overall", "wrapupnotes", "wrapupnotes2","auxcodes2","agentactivity","callqueue", "agentsreport", "callrecordings", "nodeaudiototext", "audiototext", "getInterfaces", "startCapture", "stopCapture", "captureDebug", "downloadCapture", "showsingleCapture", "showsingleCapture2", "showsingleCapture3", "runTerminal", "scheduleReports", "saveCustomWebhookDetails", "getCustomWebhookDetails", "writeLog", "getCustomWebhookDetailss", "nodeivrmenus"],
         "calls" => ["uploadZipAll", "uploadZipExtension","uploadZipTrunk","uploadZipDID","uploadZipOutbound"],
         "chat" => ["index"],
         "customize" => ["index", "intercom", "blf"],
         "accounts" => ["index", "ai", "avatar", "billing", "callbroadcast", "callprofile", "chameleon", "chatsetting","credentail", "delete", "devices", "did", "downloademail", "extension", "extension2", "extension3", "gateway", "integration", "interface", "acl", "logout", "schedule", "restriction", "sipprofile", "sso", "security", "support","teams", "voicemail", "wrapupemail", "autosip", "autoprov", "phonetemp", "provisionhosts", "booking", "webinar", "workhours", "connect", "linkedin", "ringqtalk", "backup", "livetranscriptdata", "mcpupdate"],
         // MR hotel front desk update - 2026-09-12
         "hotel" => ["rooms", "checkin", "checkout", "wakeupSet", "wakeupClear", "ivrSettings","frontDeskMenu", "roomNumberMode"],
         "hotelpbx" => ["settings", "save", "rotate", "info", "join", "guest"],
         "access" => ["diagnostics", "terminal"],
         "login" => ["index", "forgot", "recover", "recover2", "cspreport"],
	      "payment" => ["index"],
         "register" => ["checker2", "verify", "extension", "firewall", "system", "calllog", "delete", "saveZohoIntegration", "savezendeskIntegration", "zohoWebhook", "wallet", "telegram", "backup"],
         "ringqtalk" => ["config", "session", "link"],
         "chatadmin" => ["index","transferagent","getall", "whatsappconfig", "facebookconfig", "webchatconfig", "lineconfig", "telegramconfig", "updateassign", "getassign", "chats"],
         "sms" => ["index","data","sendingsingle","sendingbulk","tikosmswebhook", "tikossingle", "tikosbulk", "tikosconfig", "telnyxconfig", "telnyxwebhook", "twiliowebhook", "twilioconfig"],
         "campaign" => ["index", "bulkUploadCampaign","downloadCsv","getCampaignlist","create","edit","update","delete"],
         // MR Contact sync 21-09-2026 starts
         "teams"=>["extensionDetails", "getContacts", "callhistory", "init", "enableTeams", "o365settings", "orgContacts", "callback", "login", "contactsFilter", "googlesettings", "googlecallback", "googledisconnect", "ssologin", "ssoconfig", "contactsByNumber", "customCrmContactSettings", "saveCustomCrmContactSettings", "syncCustomCrmContacts", "pushCustomCrmContacts", "googleDisconnect"],
         // MR Contact sync 21-09-2026 ends
         "teamsai"=>[ "demowebhook", "demowebhooklogs", "resolveagent", "contactsbynumber", "voicesettings", "aiconnectors", "aiconnect", "aiconnectcallback", "aidisconnect", "aiconnectortoken", "aikbupload", "aikbcrawl", "aikbcrawlsave", "aikbrecrawl", "aikbsources", "aikbdelete", "aikbsearch", "getaicall", "aireceptioniststatus", "aireceptionistrestart", "aidestinations"],
         "agentassist" => ["feed", "suggest", "summary", "settings"],
         "telegram" => ["register", "submitOtp", "status", "restart", "stop", "logs", "deleteService"],
         "meetApi" => ["tenantMeetConfig", "accessToken", "history", "setupStatus"],
         "scheduleApi" => ["schedules", "hosts", "availability"],
         "calendar" => ["settings", "status", "connect", "callback", "events", "push", "disconnect"],
         "licensing" => ["verifyInstallationType", "registerlicence", "resendotp", "status", "interfaces", "setupNetworkProfiles"],
         "desktop" => ["login", "autologintoken", "autologin", "calllogs", "contacts", "conferenceInitiate", "conferenceParticipants", "conferenceKick", "conferenceEscalate", "conferenceInvite", "killsession", "version", "playvoicemail", "clearlogs", "voicemails", "unreadCount", "resetMissedCalls"],
         "apichat" => ["conversations", "inbox", "telnyx", "twilio", "chatwebhook", "chatsend", "genericsend", "telnyxsend", "twiliosend", "telnyxconversation", "twilioconversation", "telnyxwebhook", "twiliowebhook", "accesstoken", "chatconfig", "chat"],
         "system" => ["index"],
         "ports" => ["index", "scan", "status", "scancancel", "scanall"],
         "backup" => ["backup"],
         "booking" => ["booking"],
         "language" => ["language"],
         "schedule" => ["schedule"],
         "webinar" => ["webinar"],
         "workhours" => ["workhours"],
         "ssl" => ["info", "renew", "status"],
         "ai" => ["get", "save", "list", "delete", "token", "googleAuthorize", "googleCallback", "microsoftAuthorize", "microsoftCallback", "runTests"],
         "storage" => ["usage", "cleanup", "policy", "savePolicy", "simulate", "saveNotify"],
         "tunnel" => ["create", "list", "view", "update", "delete", "status","bind","heartbeat","generateAuthKey"],
         "audit" => ["list", "features", "settings", "saveSettings", "download", "meetList", "meetActions"],
         "company" => ["info", "save"],
         "roles" => ["custom", "mine"],
         "numberblock" => ["list", "save", "delete", "check"],
         "calldebug" => ["status", "toggle", "download", "tail"],
         "fax" => ["jobsList", "settings", "dids","send","updateSettings","status","download"],
         "mcp" => ["settings", "grants", "activity", "tool", "account", "sessions", "keys", "approvals", "staff", "me", "undo"],
         "mcpai" => ["connectors", "discover"],
         "oauth" => ["resource", "metadata", "register", "authorize", "token", "revoke"],
         "qontak" => ["config", "testConnection", "disconnect", "contactsSearch", "contacts", "callEvents", "callJournals", "recordingsSync", "aiAnalysis", "webhooks", "recording"],
         "hubspotcrm" => ["config", "testConnection", "disconnect", "contactsSearch", "contact", "contacts", "callEvents", "callJournals", "recordingsSync", "aiAnalysis", "ctiPair", "cti"],
         // WhatsApp (Meta SIP calling) micro app: WasipController checks the session itself.
         "wasip" => ["health", "destinations", "load", "status", "configure", "reconfigure", "deactivate"]
      ];
      foreach ($privately as $name => $actions) {
         $acl->addComponent(new Component($name), $actions);
      }
      foreach ($privately as $resource => $actions) {
         foreach ($actions as $action) {
            $acl->allow("registered", $resource, $action);
         }
      }
      return $acl;
   },
   true
);

$di["crypt"] = function () {
   $config = $this->getConfig();
   $crypt = new Crypt();
   $crypt->setKey($config->application->cryptSalt);
   return $crypt;
};

$di->set(
   "session",
   function () {
      $options = [
         "client" => [],
         "servers" => [
            [
               "host" => "127.0.0.1",
               "port" => 11211,
               "weight" => 0,
            ],
         ],
         "lifetime" => 43200,
         "prefix" => "ringq_",
      ];
      $session = new Manager();
      $serializerFactory = new SerializerFactory();
      $factory = new AdapterFactory($serializerFactory);
      $libmemcached = new Libmemcached($factory, $options);
      session_name("RINGQ");
      session_set_cookie_params([
         "lifetime" => 0,
         "path" => "/",
         "secure" => true,
         "httponly" => true,
         "samesite" => "Lax",
      ]);
      $session->setAdapter($libmemcached)->start();
      return $session;
   },
   true
);

$di->set(
   "system",
   function () {
      $system = new System;
      return $system->getOs();
   },
   true
);

$di->set(
   "system2",
   function () {
      $system = new System2;
      return $system;
   },
   true
);

$di->set(
   "csv",
   function () {
      $csv = new SimpleCSV;
      return $csv;
   },
   true
);

$di->set(
   "whatsapp",
   function () {
      $whatsapp_cloud_api = new WhatsAppCloudApi([
         "from_phone_number_id" => "111822498634993",
         "access_token" => "EAAJGPZCYzhCEBAFxr3ogokGBSLCKLVXHLwJZBbGEIH2GZCa5ioqUYAQjYIOy1kJZC0PIB9yfVlrlYnr8et4ENkCZCrXZCnmQwOY18G5pRfyiNxm1bZCUvAthZBHUkHh66tZCnsjjpHS6CiY2zkPrLZAGjyiihpa1zAF1u5hwaRoxUgPndAuJVjClEV",
      ]);
      return $whatsapp_cloud_api;
   },
   true
);