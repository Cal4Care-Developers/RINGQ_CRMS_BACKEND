<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;
use ElephantIO\Client;
use Phalcon\Mvc\Model\Resultset;
use Google\Service\Walletobjects;
use Google\Service\Walletobjects\GenericObject;
use Firebase\JWT\JWT;
require_once __DIR__ . '/../library/PersonName.php';

class RegisterController extends ControllerBase
{
   public $allowedIps = ["128.199.192.99", "174.138.23.72", "111.223.127.170"];

   public function indexAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      if ($this->request->isGet()) {
         $this->response->redirect("index");
         return;
      }
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["company_name"] = !empty($this->request->getPost("company_name", "string")) ? ucfirst($this->request->getPost("company_name", "string")) : "";
         $parameters["firstname"] = !empty($this->request->getPost("firstname", "string")) ? ucfirst($this->request->getPost("firstname", "string")) : "";
         $parameters["lastname"] = !empty($this->request->getPost("lastname", "string")) ? ucfirst($this->request->getPost("lastname", "string")) : "";
         $parameters["address_1"] = !empty($this->request->getPost("address_1", "string")) ? ucfirst($this->request->getPost("address_1", "string")) : "";
         $parameters["address_2"] = !empty($this->request->getPost("address_2", "string")) ? ucfirst($this->request->getPost("address_2", "string")) : "";
         $parameters["phone"] = $this->request->getPost("phone", "string");
         $parameters["city"] = !empty($this->request->getPost("city", "string")) ? ucfirst($this->request->getPost("city", "string")) : "";
         $parameters["state"] = !empty($this->request->getPost("state", "string")) ? ucfirst($this->request->getPost("state", "string")) : "";
         $parameters["country"] = !empty($this->request->getPost("country", "string")) ? ucfirst($this->request->getPost("country", "string")) : "";
         $parameters["company_email"] = $this->request->getPost("company_email", "string");
         $parameters["fax"] = $this->request->getPost("fax", "string");
         $parameters["contact_person"] = !empty($this->request->getPost("contact_person", "string")) ? ucfirst($this->request->getPost("contact_person", "string")) : "";
         $parameters["fqdn"] = $this->request->getPost("fqdn", "string");
         $parameters["extension_length"] = intval($this->request->getPost("extension_length", "int"));
         $parameters["packageId"] = intval($this->request->getPost("packageId", "int"));
         $parameters["user_id"] = $this->request->getPost("user_id", "string");
         $parameters["uuid"] = $this->request->getPost("uuid", "string");
         $parameters["access_token"] = $this->request->getPost("access_token", "string");
         $parameters["sipLogin"] = $this->request->getPost("sipLogin", "string");
         $parameters["sipPassword"] = $this->request->getPost("sipPassword", "string");
         $parameters["proxy"] = $this->request->getPost("proxy", "string");
         $parameters["gateway"] = $this->request->getPost("gateway", "string");
         $parameters["did"] = $this->request->getPost("did", "string");
         if (empty($parameters["call_profile"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
            $response = json_encode([
               "result" => "error",
               "message" => "Unathorized request made (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
         error_log("THIS IS REGISTRATION ATTEMPT");
         $registerForm = new RegisterForm();
         if (!$registerForm->isValid($parameters)) {
            $messages = $registerForm->getMessages();
            $message = "";
            foreach ($messages as $error) {
               $message .= $error . " ";
            }
            error_log($message . " (Code RI1000)");
            $response = json_encode([
               "result" => "error",
               "message" => trim($message) . " (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         } else {
            $qCompany = QCompanies::findFirst([
               "conditions" => "company_email = :company_email:",
               "bind" => [
                  "company_email" => $parameters["company_email"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($qCompany) {
               $response = json_encode([
                  "result" => "error",
                  "message" => "Sorry, an existing account already used this email. (Code RI1001)",
               ]);
               $this->response->setContent($response);
               return $this->response;
            } else {
               $qCompany = new QCompanies();
               $registerForm->bind($parameters, $qCompany);
               $security = new \Phalcon\Security\Random();
               $qCompany->user_uuid = $security->uuid();
               $qCompany->domain_uuid = $security->uuid();
               $qCompany->uid = $parameters["user_id"];
               $qCompany->uuid = $parameters["uuid"];
               $qCompany->token = $parameters["access_token"];
               $qCompany->insert_date = date("Y-m-d H:i:s");
               $qCompany->owner = intval($parameters["owner"]) != 0 ? intval($parameters["owner"]) : 1;
               $qCompany->manager = intval($parameters["manager"]) != 0 ? intval($parameters["manager"]) : 3;
               $qCompany->user = intval($parameters["user"]) != 0 ? intval($parameters["user"]) : 10;
               $qCompany->queue = intval($parameters["queues"]) != 0 ? intval($parameters["queues"]) : 99;
               $qCompany->storage = intval($parameters["recordingStorage"]) != 0 ? intval($parameters["recordingStorage"]) : 1;
               $qCompany->devices = intval($parameters["userDevice"]) != 0 ? intval($parameters["userDevice"]) : 5;
               $qCompany->packageName = $parameters["packagename"];
               $qCompany->packageId = intval($parameters["packageId"]);
               if (!$qCompany->create()) {
                  $messages = $qCompany->getMessages();
                  $message = "";
                  foreach ($messages as $error) {
                     $message .= $error;
                  }
                  $response = json_encode([
                     "result" => "error",
                     "message" => $message . " (Code RI1002)",
                  ]);
                  $this->response->setContent($response);
                  return $this->response;
               } else {
                  $verify = rand(100000000, 999999999);
                  if (($parameters["didStatus"] ?? "0") != "0") {
                     $this->redis->set("didStatus-" . $parameters["company_email"], "Welcome to RingQ! Please note that we have tried reserving your desired DID, but we were unable to do so. Please note that we have assigned a new DID to your account.");
                  }
                  $this->redis->lrem("register", 0, $parameters["company_email"]);
                  $this->redis->lpush("register", $parameters["company_email"]);
                  $this->redis->hmset("verify-" . $parameters["company_email"], [
                     "verify" => $verify,
                     "fqdn" => $parameters["fqdn"],
                     "firstname" => trim($parameters["firstname"]),
                     "lastname" => trim($parameters["lastname"]),
                     "package" => $parameters["packageId"],
                     "uuid" => $parameters["uuid"],
                     "access_token" => $parameters["access_token"],
                     "user_id" => $parameters["user_id"],
                     "sipLogin" => $parameters["sipLogin"],
                     "sipPassword" => $parameters["sipPassword"],
                     "proxy" => $parameters["proxy"] ?? '',
                     "gateway" => $parameters["gateway"] ?? '',
                     "did" => $parameters["did"],
                     "trunkName" => $parameters["trunkName"] ?? '',
                     "typeOfAuthentication" => $parameters["typeOfAuthentication"] ?? '',
                     "username" => $parameters["username"] ?? '',
                     "password" => $parameters["password"] ?? '',
                     "authUsername" => $parameters["authUsername"] ?? '',
                     "proxyHostNameIp" => $parameters["proxyHostNameIp"] ?? '',
                     "sipPort" => $parameters["sipPort"] ?? '',
                     "sipProfile" => $parameters["sipProfile"] ?? '',
                     "outboundCallerId" => $parameters["outboundCallerId"] ?? '',
                     "countryDid" => $parameters["countryDid"] ?? '',
                     "outboundSipFromUser" => $parameters["outboundSipFromUser"] ?? '',
                     "outboundSipFromDisplayName" => $parameters["outboundSipFromDisplayName"] ?? '',
                     "outboundSipFromHost" => $parameters["outboundSipFromHost"] ?? '',
                     "outboundCidType" => $parameters["outboundCidType"] ?? '',
                  ]);
                  $this->redis->hmset("backup-verify-" . $parameters["company_email"], [
                     "fqdn" => $parameters["fqdn"],
                     "firstname" => trim($parameters["firstname"]),
                     "lastname" => trim($parameters["lastname"]),
                     "package" => $parameters["packageId"],
                     "uuid" => $parameters["uuid"],
                     "access_token" => $parameters["access_token"],
                     "user_id" => $parameters["user_id"],
                     "sipLogin" => $parameters["sipLogin"],
                     "sipPassword" => $parameters["sipPassword"],
                     "proxy" => $parameters["proxy"] ?? '',
                     "gateway" => $parameters["gateway"] ?? '',
                     "did" => $parameters["did"],
                     "trunkName" => $parameters["trunkName"] ?? '',
                     "typeOfAuthentication" => $parameters["typeOfAuthentication"] ?? '',
                     "username" => $parameters["username"] ?? '',
                     "password" => $parameters["password"] ?? '',
                     "authUsername" => $parameters["authUsername"] ?? '',
                     "proxyHostNameIp" => $parameters["proxyHostNameIp"] ?? '',
                     "sipPort" => $parameters["sipPort"] ?? '',
                     "sipProfile" => $parameters["sipProfile"] ?? '',
                     "outboundCallerId" => $parameters["outboundCallerId"] ?? '',
                     "countryDid" => $parameters["countryDid"] ?? '',
                     "outboundSipFromUser" => $parameters["outboundSipFromUser"] ?? '',
                     "outboundSipFromDisplayName" => $parameters["outboundSipFromDisplayName"] ?? '',
                     "outboundSipFromHost" => $parameters["outboundSipFromHost"] ?? '',
                     "outboundCidType" => $parameters["outboundCidType"] ?? '',
                  ]);
                  $this->redis->expire("verify-" . $parameters["company_email"], 900);
                  $response = json_encode([
                     "result" => "success",
                     "message" => "Please check your email address for login code (valid for 15 minutes).",
                     "content" => "https://" .$parameters["fqdn"] ."/cloudapp/?verify=". $verify ."&email=". $parameters["company_email"],
                  ]);
                  $this->response->setContent($response);
                  return $this->response;
               }
            }
         }
      }
   }

   public function recoverAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if ($this->request->isGet()) {
         $this->response->redirect("index");
         return;
      }
      if (!$this->request->isPost()) {
         return;
      }

      $email = $this->request->getPost("email", "string");
      $fqdn  = $this->request->getPost("fqdn", "string");
      if (empty($email) || empty($fqdn)) {
         $contents = file_get_contents("php://input");
         $decoded  = json_decode($contents, true) ?: [];
         $email = $email ?: ($decoded["email"] ?? "");
         $fqdn  = $fqdn  ?: ($decoded["fqdn"]  ?? "");
      }

      if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
         $response = json_encode([
            "result"  => "error",
            "message" => "Unathorized request made (Code RR1000)",
            "content" => [],
         ]);
         $this->response->setContent($response);
         return $this->response;
      }

      if (empty($email) || empty($fqdn)) {
         $response = json_encode([
            "result"  => "error",
            "message" => "email and fqdn are required.",
         ]);
         $this->response->setContent($response);
         return $this->response;
      }

      // Resolve tenant domain (hop to parent domain if this is a linked IP domain).
      $vDomain = VDomains::findFirst([
         "conditions" => "domain_name = :domain_name:",
         "bind"       => ["domain_name" => $fqdn],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if ($vDomain && $vDomain->domain_parent_uuid) {
         $vDomain = VDomains::findFirst([
            "conditions" => "domain_uuid = :domain_uuid:",
            "bind"       => ["domain_uuid" => $vDomain->domain_parent_uuid],
            "bindTypes"  => [Column::BIND_PARAM_STR],
         ]) ?: $vDomain;
      }
      if (!$vDomain) {
         $response = json_encode([
            "result"  => "error",
            "message" => "Sorry, please check account domain. (Code RR1002)",
         ]);
         $this->response->setContent($response);
         return $this->response;
      }

      $vUser = VUsers::findFirst([
         "conditions" => "domain_uuid = :domain_uuid: AND user_email = :user_email:",
         "bind"       => ["domain_uuid" => $vDomain->domain_uuid, "user_email" => $email],
         "bindTypes"  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
      ]);
      if (!$vUser) {
         $response = json_encode([
            "result"  => "error",
            "message" => "If email exists, an email will be sent for account recovery.",
         ]);
         $this->response->setContent($response);
         return $this->response;
      }

      // Reuse an in-flight code if one is still valid; otherwise generate + queue the email.
      if ($this->redis->exists("forgot-" . $email)) {
         $forgot = $this->redis->hget("forgot-" . $email, "forgot");
      } else {
         $forgot = rand(100000000, 999999999);
         $this->redis->lrem("forgot", 0, $email);
         $this->redis->lpush("forgot", $email);
         $this->redis->hmset("forgot-" . $email, ["forgot" => $forgot, "fqdn" => $vDomain->domain_name]);
         $this->redis->expire("forgot-" . $email, 900);
         exec('/usr/bin/php /var/www/ringq/app/cli.php main main3', $output, $result);
      }

      $response = json_encode([
         "result"  => "success",
         "message" => "Please check your email address for access code (valid for 15 minutes).",
         "content" => "https://" . $vDomain->domain_name . "/cloudapp/?recover=" . $forgot . "&email=" . $email,
      ]);
      $this->response->setContent($response);
      return $this->response;
   }

   public function backupAction($type = 1) {
      if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
         return $this->response->setStatusCode(401, 'Unauthorized')
            ->setJsonContent([
               "result" => "error",
               "message" => "Unathorized request made",
               "content" => [],
            ]);
      }

      if ($this->request->isPost()) {
         $contents = file_get_contents("php://input");
         $parameters = json_decode($contents, true);
   
         if (empty($parameters)) {
            $fqdn = $this->request->getPost('fqdn');
            $customName = $this->request->getPost('name') ?? time();
            $backupId = $this->request->getPost('id') ?? null;
         } else {
            $fqdn = $parameters['fqdn'];
            $customName = $parameters['name'] ?? time();
            $backupId = $parameters['id'] ?? null;
         }
   
         if (empty($parameters['fqdn'])) {
            return $this->response->setStatusCode(400, 'Bad Request')
               ->setJsonContent([
                  "result" => "error",
                  "message" => "FQDN is required",
                  "content" => [],
               ]);
         }
   
         $vDomains = VDomains::findFirst([
            'conditions' => "domain_name = :domain_name:",
            'bind' => [
               'domain_name' => $fqdn
            ],
            "bindTypes" => [Column::BIND_PARAM_STR],
         ]);
   
         if (!$vDomains) {
            return $this->response->setStatusCode(404, 'Not Found')
               ->setJsonContent([
                  "result" => "error",
                  "message" => "Domain not found",
                  "content" => [],
               ]);
         } 
         if ($type == 1) { // Create
            $id = uniqid();
            $finalName = !empty($customName) ? $customName : "ringq-" . $id;

            // Check for uniqueness
            $backups = $this->redis->lrange("backups", 0, -1);
            foreach ($backups as $backupStr) {
               $backup = json_decode($backupStr, true);
               if (isset($backup['name']) && $backup['name'] === $finalName) {
                  return $this->response->setJsonContent([
                     "result" => "error",
                     "message" => "Backup with this name already exists ($finalName)."
                  ]);
               }
            }

            $backupData = [
               "id" => $id,
               "name" => $finalName,
               "type" => "RingQ Service",
               "includes" => [
                  "Database",
                  "Redis",
                  "Recordings",
                  "Voicemails"
               ],
            ];

            // Check for saved settings to respect exclusions
            $settingsJson = $this->redis->get("backup_settings");
            if ($settingsJson) {
               $settings = json_decode($settingsJson, true);
               if (isset($settings['includes']) && is_array($settings['includes'])) {
                  $backupData['includes'] = $settings['includes'];
               }
               if (isset($settings['timezone'])) {
                  $backupData['timezone'] = $settings['timezone'];
               }
            }

            $this->redis->set("performBackup", json_encode($backupData));
            $this->redis->set('performBackup:uuid', $vDomains->domain_uuid); 

            return $this->response->setJsonContent([
               "result" => "success",
               "content" => [],
               "status" => 1,
               'id' => $id,
               "message" => "Backup is now in-progress"
            ]);
         }
         elseif ($type == 2) { // Delete
            $backups = $this->redis->lrange('backups', 0, -1);
            $index = null;

            foreach ($backups as $key => $value) {
               $data = json_decode($value, true);
               if (isset($data['id']) && $data['id'] == $backupId) {
                  $index = $key;
                  break;
               }
            }

            if ($index === null) {
               return $this->response->setStatusCode(404, 'Not Found')
                  ->setJsonContent([
                     "result" => "error",
                     "message" => "Backup not found",
                     "content" => [],
                  ]);
            }

            $backups = $this->redis->lrange("backups", $index, $index);
            if (!empty($backups)) {
               $item = $backups[0];
               $this->redis->lpush("performDeleteQueue", $item);
            }
            $backups = $this->redis->lrange("backups", 0, -1);
            return $this->response->setJsonContent([
               "result" => "success",
               "status" => 1,
               "message" => "Successfully deleted backup."
            ]);
         }
         elseif ($type == 3) { // Download
            $backups = $this->redis->lrange('backups', 0, -1);
            $index = null;
            
            foreach ($backups as $key => $value) {
               $data = json_decode($value, true);
               if (isset($data['id']) && $data['id'] == $backupId) {
                  $index = $key;
                  break;
               }
            }

            if ($index === null) {
               return $this->response->setStatusCode(404, 'Not Found')
                  ->setJsonContent([
                     "result" => "error",
                     "message" => "Backup not found",
                     "content" => [],
                  ]);
            }

            $backups = $this->redis->lrange("backups", $index, $index);
            $data = $backups[0];
            $item = json_decode($data, true);
            $name = $item["name"];
            $date = $item["datetime"];
            $type = $item["type"]; 
            
            if ($type == 'RingQ Service') {
               error_log("<=====. RingQ Service {$name}_{$date}");
               $fileName = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', '', $name) . "_{$date}.zip";

               $realPath = "/var/www/ringq/app/migrations/{$fileName}";
               if (file_exists($realPath)) { 
                  if (ob_get_level()) {
                     ob_end_clean();
                  }
                  header('Content-Description: File Transfer');
                  header('Content-Type: application/zip');
                  header('Content-Disposition: attachment; filename="'.$fileName.'.zip"');
                  header('Content-Length: ' . filesize($realPath));
                  header('Cache-Control: must-revalidate');
                  header('Pragma: public');
                  header('Expires: 0');
                  $fp = fopen($realPath, 'rb');
                  while (!feof($fp)) {
                     echo fread($fp, 8192);
                     flush();
                  }
                  fclose($fp);
                  exit;
               }
            }
         }
         elseif ($type == 4) { // Get
            $backups = $this->redis->lrange('backups', 0, -1);
            $data = array_map('json_decode', $backups);
            
            return $this->response->setJsonContent([
               "result" => "success",
               "message" => "",
               "content" => $data
            ]);
         }
      } 
   }


public function telegramAction()
{
    $this->view->disable();

    $raw = file_get_contents('php://input');
    $update = json_decode($raw, true);

    if (!$update || !isset($update['update_id']) || !isset($update['message'])) {
        http_response_code(200);
        return;
    }

    $updateId = $update['update_id'];
    $duplicateKey = "telegram:processed:" . $updateId;

    // Prevent duplicate processing
    if ($this->redis->get($duplicateKey)) {
        http_response_code(200);
        return;
    }

    // Mark processed for 10 minutes
    $this->redis->setex($duplicateKey, 600, "1");

    // ---- Get source id from webhook ----
    $sourceId = $_GET['source'] ?? '';
    if (!$sourceId) {
        error_log("Telegram source id missing");
        http_response_code(400);
        return;
    }

// ---- Detect host ----
$host = $_SERVER['HTTP_HOST'] ?? '';
// Remove port if exists
$cleanHost = explode(':', $host)[0];

// ---- Get Redis list ----
$redisKey = "telegramconfig:fqdn:{$cleanHost}";
$rows = $this->redis->lRange($redisKey, 0, -1);

if (!$rows) {
    error_log("Telegram config not found for {$cleanHost}");
    http_response_code(404);
    return;
}

    // ---- Try cache first ----
    $cacheKey = "telegram:token:" . $sourceId;
    $botToken = $this->redis->get($cacheKey);

    if (!$botToken) {
        foreach ($rows as $row) {
            $config = json_decode($row, true);
            if (!empty($config['id']) && $config['id'] === $sourceId) {
                $botToken = $config['accessToken'] ?? null;
                if ($botToken) {
                    $this->redis->setex($cacheKey, 300, $botToken); // cache 5 mins
                }
                break;
            }
        }
    }

    if (!$botToken) {
        error_log("Telegram token not found for source: {$sourceId}");
        http_response_code(403);
        return;
    }

    // ---- Handle message ----
    $message = $update['message'];
    $messageId = $message['message_id'];

    $basePath = "/var/www/webapp/chat_file_upload/telegram/";

    $fileId = null;
    $subFolder = '';
    $extension = '';
    $downloadAllowed = false;
    $textOverride = null;

    // Images & documents → download
    if (isset($message['photo'])) {
        $fileId = end($message['photo'])['file_id'];
        $subFolder = 'telegram_image';
        $extension = 'jpg';
        $downloadAllowed = true;
    } elseif (isset($message['document'])) {
        $fileId = $message['document']['file_id'];
        $subFolder = 'telegram_file';
        $extension = self::safeChatFileExtension($message['document']['file_name'] ?? 'file');
        $downloadAllowed = true;
    } 
    // Audio / video / voice / others → do NOT download, override text
    elseif (isset($message['video'])) {
        $textOverride = "[Sent a video]";
    } elseif (isset($message['audio'])) {
        $textOverride = "[Sent an audio]";
    } elseif (isset($message['voice'])) {
        $textOverride = "[Sent a voice message]";
    }

    // ---- Download if allowed ----
    if ($downloadAllowed) {
        $dir = $basePath . $subFolder;
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $savePath = $dir . "/" . $messageId . "." . $extension;
        $this->downloadTelegramContent($fileId, $botToken, $savePath);
    } else {
        $savePath = null;
    }

    // ---- Prepare content for WebSocket ----
    $client = new Client(Client::engine(Client::CLIENT_4X, 'ws://127.0.0.1:3000', ['persistent' => false]));
    $client->initialize();

    $content = (array) json_decode(json_encode($raw), true);
    $content['localFilePath'] = $savePath;
    $content['fqdn'] = $cleanHost;
     $content['idRedis'] = $sourceId;

    // If textOverride exists (audio/video/voice), replace 'text' in message
    if ($textOverride) {
        $content['message']['text'] = $textOverride;
    }
  
    $client->emit('telegram', $content);

    http_response_code(200);
}



private function downloadTelegramContent($fileId, $token, $savePath)
{
    $getPathUrl = "https://api.telegram.org/bot{$token}/getFile?file_id={$fileId}";

    $response = file_get_contents($getPathUrl);
    $data = json_decode($response, true);

    if (!empty($data['result']['file_path'])) {

        $filePath = $data['result']['file_path'];

        $downloadUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        $ch = curl_init($downloadUrl);
        $fp = fopen($savePath, 'wb');

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_exec($ch);

        curl_close($ch);
        fclose($fp);

        return true;
    }

    return false;
}



public function lineAction()
{
    $this->view->disable();

    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data || !isset($data['events'])) {
        http_response_code(200);
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $cleanHost = explode(':', $host)[0];

    // ---- Get source from URL ----
    $sourceId = $_GET['source'] ?? '';
    if (!$sourceId) {
        error_log("Line source id missing in URL");
        http_response_code(400);
        return;
    }

    // ---- Load Redis config for host ----
    $redisKey = "lineconfig:fqdn:{$cleanHost}";
    $rows = $this->redis->lRange($redisKey, 0, -1);
    if (!$rows) {
        error_log("Line config not found for {$cleanHost}");
        http_response_code(404);
        return;
    }

    // ---- Try cache first for bot token ----
    $cacheKey = "line:token:" . $sourceId;
    $botToken = $this->redis->get($cacheKey);

    if (!$botToken) {
        foreach ($rows as $row) {
            $config = json_decode($row, true);
            if (!empty($config['id']) && $config['id'] === $sourceId) {
                $botToken = $config['accessToken'] ?? null;
                if ($botToken) $this->redis->setex($cacheKey, 300, $botToken); // 5 min cache
                break;
            }
        }
    }

    if (!$botToken) {
        error_log("Line token not found for source: {$sourceId}");
        http_response_code(403);
        return;
    }

    foreach ($data['events'] as $event) {

        // Prevent duplicates using webhookEventId
        $eventId = $event['webhookEventId'] ?? null;
        if (!$eventId) continue;

        $duplicateKey = "line:processed:" . $eventId;
        if ($this->redis->get($duplicateKey)) continue;

        $this->redis->setex($duplicateKey, 600, "1"); // 10 min lock

        // ---- Handle message ----
        if ($event['type'] !== 'message') continue;

        $message = $event['message'];
        $messageId = $message['id'];

        $basePath = "/var/www/webapp/chat_file_upload/";
        $fileId = null;
        $subFolder = '';
        $extension = '';
        $downloadAllowed = false;

        // Only download images and files
        if ($message['type'] === 'image') {
            $fileId = $messageId;
            $subFolder = 'line_image';
            $extension = 'jpg';
            $downloadAllowed = true;
        } elseif ($message['type'] === 'file') {
            $fileId = $messageId;
            $subFolder = 'line_file';
            $extension = self::safeChatFileExtension($message['fileName'] ?? 'file');
            $downloadAllowed = true;
        }

        $savePath = null;
        if ($downloadAllowed) {
            $dir = $basePath . $subFolder;
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $savePath = $dir . "/" . $messageId . "." . $extension;
            $this->downloadLineContent($fileId, $botToken, $savePath);
        }

        // ---- For audio, video, voice, just mark as text info ----
        if (!$downloadAllowed) {
            if (in_array($message['type'], ['audio','video','voice'])) {
                $message['text'] = "Sent a {$message['type']}";
            }
        }

        // ---- Fetch LINE display name from profile ----
        $displayName = null;
        $userId = $event['source']['userId'] ?? null;
        if ($userId) {
            $profileCacheKey = "line:profile:{$userId}";
            $cachedProfile = $this->redis->get($profileCacheKey);

            if ($cachedProfile) {
                $profile = json_decode($cachedProfile, true);
                $displayName = $profile['displayName'] ?? null;
            } else {
                $profileUrl = "https://api.line.me/v2/bot/profile/{$userId}";
                $ch = curl_init($profileUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$botToken}"],
                ]);
                $profileRaw = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $profile = json_decode($profileRaw, true);
                    $displayName = $profile['displayName'] ?? null;
                    $this->redis->setex($profileCacheKey, 3600, json_encode($profile)); // 1 hr cache
                } else {
                    error_log("Failed to fetch LINE profile for user {$userId}, HTTP code: {$httpCode}");
                }
            }
        }

        // ---- Send to WebSocket ----
        $client = new Client(Client::engine(Client::CLIENT_4X, 'ws://127.0.0.1:3000', ['persistent' => false]));
        $client->initialize();

        $content = (array) json_decode(json_encode($event), true);
        $content['localFilePath'] = $savePath ?? null;
        $content['fqdn'] = $cleanHost;
        $content['idRedis'] = $sourceId;
        $content['displayName'] = $displayName;

        // ---- Log content for debugging ----
        // error_log("LINE WebSocket Payload: " . json_encode($content, JSON_PRETTY_PRINT));

        $client->emit('line', $content);
    }

    http_response_code(200);
}




/**
 * Downloads LINE content for images and files
 */
private function downloadLineContent($messageId, $accessToken, $savePath)
{
    $url = "https://api-data.line.me/v2/bot/message/{$messageId}/content";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken
        ],
    ]);

    $rawContent = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        file_put_contents($savePath, $rawContent);
        return true;
    }
    return false;
}



function getUserDetails($userId, $pageToken)
{
    // The hash is always the same for the same token!
    $cacheKey = "facebook:user_details:" . $userId . ":" . md5($pageToken);

    // 1. Check if we already have this in Redis
    $cachedData = $this->redis->get($cacheKey);
    if ($cachedData) {
        return json_decode($cachedData, true);
    }

    // 2. If not, ask Facebook
    $url = "https://graph.facebook.com/v19.0/$userId?fields=name,email&access_token=$pageToken";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === FALSE || $httpCode !== 200) {
        return null;
    }

    // 3. Save to Redis for 24 hours
    $this->redis->setex($cacheKey, 86400, $response);

    return json_decode($response, true);
}
   public function messengerAction()
   {
$this->view->disable();

if (isset($_GET['hub_challenge'], $_GET['hub_verify_token'])) {
    $challenge    = $_GET['hub_challenge'];
    $verify_token = $_GET['hub_verify_token'];

    // ---- Target Host ----
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $cleanHost = explode(':', $host)[0];

    // Directly target the specific Redis key
    $targetKey = "facebookconfig:fqdn:{$cleanHost}";
    $rows = $this->redis->lRange($targetKey, 0, -1);

    $found = false;

    foreach ($rows as $row) {
        $decoded = json_decode($row, true);
        
        // Match the verifyToken from the specific host's config
        if (!empty($decoded['verifyToken']) && $decoded['verifyToken'] === $verify_token) {
            $found = true;
            break;
        }
    }

    if ($found) {
        header("HTTP/1.1 200 OK");
        echo $challenge;
        exit;
    } else {
        header("HTTP/1.1 403 Forbidden");
        echo "Verification failed";
        exit;
    }
}

            // Handle incoming messages (POST request)
            $response = file_get_contents("php://input");
            // error_log($response);


// Log raw payload
// error_log("RAW INPUT: " . $response);

            $response = json_decode($response, true);

            // Make sure we got an entry
            if (!isset($response['entry'][0]['messaging'][0])) {
                // error_log("Invalid Messenger payload: " . json_encode($response));
                http_response_code(200);
                exit;
            }

            $messaging = $response['entry'][0]['messaging'][0];

      // Handle delivery/read events early
if (isset($messaging['delivery']) || isset($messaging['read'])) {
    // error_log('Delivery/Read event detected. Returning early.');
    http_response_code(200);
    exit;
}



// ... (previous delivery/read checks) ...

$recipient = $messaging['recipient']['id'] ?? null;
if (!$recipient) {
    http_response_code(200);
    exit;
}

$cacheKey = "facebookconfig:cache:recipient:{$recipient}";
$pageToken = null;
$fqdn      = null;
$appId     = null;

// 1. Try cache first
$cached = $this->redis->get($cacheKey);
if ($cached) {
    $cached = json_decode($cached, true);
    $pageToken = $cached['pageToken'] ?? null;
    $fqdn      = $cached['fqdn'] ?? null;
    $appId     = $cached['appId'] ?? null;
}

// 2. Cache miss → Direct lookup via Host
if (!$pageToken) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $cleanHost = explode(':', $host)[0];
    
    // Target the host key directly
    $targetKey = "facebookconfig:fqdn:{$cleanHost}";
    $rows = $this->redis->lRange($targetKey, 0, -1);

    foreach ($rows as $row) {
        $decoded = json_decode($row, true);

        // Verify this row belongs to the recipient (appId)
        if (isset($decoded['appId']) && (string)$recipient === (string)$decoded['appId']) {
            $pageToken = $decoded['accessToken'] ?? null;
            $fqdn      = $decoded['fqdn'] ?? null;
            $appId     = $decoded['appId'] ?? null;

            // Save cache
            $this->redis->setex($cacheKey, 600, json_encode([
                'pageToken' => $pageToken,
                'fqdn'      => $fqdn,
                'appId'     => $appId,
            ]));
            break;
        }
    }
}

// 3. Final guard
if (!$pageToken) {
    error_log("No matching config found for host {$cleanHost} and recipient {$recipient}");
    http_response_code(200);
    exit;
}



  

      $sender_id = $messaging['sender']['id'] ?? null;
 
      $message = $response['entry'][0]['messaging'][0]['message']['text'];
      $message_id = $response['entry'][0]['messaging'][0]['message']['mid'];
      $messagechecker = $response['entry'][0]['messaging'][0]['message'];



      // error_log("this is the response:\n" . json_encode($response, JSON_PRETTY_PRINT));

      if (isset($message_id) && isset($messagechecker)) {
         $page_id = $response['entry'][0]['id'];
         $timestamp = $response['entry'][0]['time'];
         $attachments = $response['entry'][0]['messaging'][0]['message']['attachments'] ?? [];

         // Get user details
      $userDetails = $this->getUserDetails($sender_id, $pageToken);
      $userName = $userDetails['name'] ?? 'Unknown';

      // Exit if username is Unknown OR sender is the same as the Page (admin/system)
      if ($userName === 'Unknown' || $sender_id === $recipient) {
         // error_log("System/Page/Admin message detected. Returning early. Sender: {$sender_id}, Recipient: {$recipient}");
         http_response_code(200);
         exit;
      }

      // Handle attachments
$attachmentUrls = [];
$lastType = ''; 

foreach ($attachments as $attachment) {
    $message_attachment = $attachment['payload']['url'];
    $type = $attachment['type']; 
    $lastType = $type; 

    // If it's audio or video, we don't save it locally. 
    // We just track the type and skip to the next attachment if necessary.
    if ($type === 'audio' || $type === 'video') {
        // We add the original remote URL to attachmentUrls so the record isn't empty
        $attachmentUrls[] = $message_attachment;
        continue; 
    }

    // --- Logic for Images and Files (Still Saving Locally) ---
    $parsed_url = parse_url($message_attachment);
    $path_parts = pathinfo($parsed_url['path']);
    $extension = isset($path_parts['extension']) ? $path_parts['extension'] : 'bin';
    $fileName = uniqid() . '.' . $extension;

    $destination = '';
    switch ($type) {
        case 'image':
            $destination = '/var/www/webapp/chat_file_upload/fb_image/' . $fileName;
            break;
        case 'file':
        default:
            $destination = '/var/www/webapp/chat_file_upload/fb_files/' . $fileName;
            break;
    }

    $fileContent = file_get_contents($message_attachment);
    if ($fileContent !== false) {
        file_put_contents($destination, $fileContent);
        $attachmentUrls[] = $destination;
    }
}

$attachmentUrlString = implode(',', $attachmentUrls);

// Normalize message when message is null but attachment exists
if ($message === null && !empty($attachmentUrlString)) {
    
    if ($lastType === 'audio') {
        $message = "Sent an audio";
    } elseif ($lastType === 'video') {
        $message = "Sent a video";
    } else {
        // Only do path replacement for images/files that were saved locally
        $message = str_replace(
            '/var/www/webapp/',
            '',
            $attachmentUrlString
        );
    }
}

$data2 = [
    'sender_id'      => $sender_id,
    'recipient'      => $recipient,
    'message'        => $message, 
    'fqdn'           => $fqdn,
    'message_id'     => $message_id,
    'attachmentUrls' => $attachmentUrlString,
    'pageToken'      => $pageToken,
    'userName'       => $userName,
    'timestamp'      => $timestamp,
];
         $jsonData2 = json_encode($data2);

         // error_log("This is the data2" . json_encode($data2));

         $client = new Client(Client::engine(Client::CLIENT_4X, 'ws://127.0.0.1:3000', ['persistent' => false]));
         $client->initialize();
         $content = (array) json_decode(json_encode($jsonData2), true);
         // error_log("Parameters sent:\n" . json_encode($content, JSON_PRETTY_PRINT));
         $client->emit('facebook', $content);

         http_response_code(200);
         // $this->redis->lpush($rediskey, $jsonData);
      }

      exit;
   }

   public function whatsappAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);


           $this->view->disable();
   
  

   // ---- Target Host ----
$host = $_SERVER['HTTP_HOST'] ?? '';
$cleanHost = explode(':', $host)[0];


// ---- Verification (GET) ----
if ($this->request->isGet()) {
    $mode = $this->request->getQuery("hub_mode");
    $token = $this->request->getQuery("hub_verify_token");
    $challenge = $this->request->getQuery("hub_challenge");

    // Directly target the host-specific key instead of scanning all keys
    $targetKey = "whatsappconfig:fqdn:{$cleanHost}";
    $rows = $this->redis->lRange($targetKey, 0, -1);

    $found = false;
    foreach ($rows as $row) {
        $decoded = json_decode($row, true);
        if (!empty($decoded['verifyToken']) && $decoded['verifyToken'] === $token) {
            $found = true;
            break;
        }
    }

    if ($mode === "subscribe" && $found) {
        http_response_code(200);
        header("Content-Type: text/plain");
        echo $challenge;
    } else {
        http_response_code(403);
        echo "Verification failed";
    }
    exit;
}

// ---- Handle Incoming Messages (POST) ----
$payload = file_get_contents("php://input");
$data = json_decode($payload, true);

// Check if 'messages' field exists
if (empty($data['entry'][0]['changes'][0]['value']['messages'])) {
    // error_log('Message delivery status detected. Returning early.');
    http_response_code(200);
    return;
}

// Extract display phone number
$displayPhoneNumber = $data['entry'][0]['changes'][0]['value']['metadata']['display_phone_number'] ?? null;
if (!$displayPhoneNumber) {
    error_log("Display Phone Number not found in payload.");
    return;
}

// Cache key per phone number
$cacheKey = "whatsappconfig:cache:number:{$displayPhoneNumber}";

// Init vars
$accessToken = null;
$fqdn        = null;
$appId       = null;

// ---- 1. Try cache first ----
$cached = $this->redis->get($cacheKey);
if ($cached) {
    $cached = json_decode($cached, true);
    if (is_array($cached)) {
        $accessToken = $cached['accessToken'] ?? null;
        $fqdn        = $cached['fqdn'] ?? null;
        $appId       = $cached['appId'] ?? null;
    }
}

// ---- 2. Cache miss → Target Direct Host Key ----
if (!$accessToken) {
    // Look only in the list belonging to the current cleanHost
    $targetKey = "whatsappconfig:fqdn:{$cleanHost}";
    $rows = $this->redis->lRange($targetKey, 0, -1);

    foreach ($rows as $row) {
        $decoded = json_decode($row, true);

        if (!is_array($decoded) || !isset($decoded['number'])) {
            continue;
        }

        // Compare display number from payload with config number
        if ((string)$displayPhoneNumber === (string)$decoded['number']) {
            $accessToken = $decoded['accessToken'] ?? null;
            $fqdn        = $decoded['fqdn'] ?? null;
            $appId       = $decoded['appId'] ?? null;

            // Save to cache (10 mins)
            $this->redis->setex(
                $cacheKey,
                600,
                json_encode([
                    'accessToken' => $accessToken,
                    'fqdn'        => $fqdn,
                    'appId'       => $appId,
                ])
            );
            break; 
        }
    }
}

// ---- 3. Final guard ----
if (!$accessToken) {
    error_log("No matching config found for Host: {$cleanHost} and Phone: {$displayPhoneNumber}");
    return;
}






      // error_log("[Payload Received]\n" . json_encode(json_decode($payload, true), JSON_PRETTY_PRINT));
// Initialize media variables
      $mediaId = null;
      $mediaType = null;

      // Check for different media types
      if (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['image']['id'])) {
         $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['image']['id'];
         $mediaType = 'image';
      } elseif (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['video']['id'])) {
         $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['video']['id'];
         $mediaType = 'video';
      } elseif (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['audio']['id'])) {
         $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['audio']['id'];
         $mediaType = 'audio';
      } elseif (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['document']['id'])) {
         $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['document']['id'];
         $mediaType = 'document';
      }

      // Proceed if mediaId is found
      if ($mediaId && $mediaType) {

         // Initialize cURL session to retrieve media URL
         $ch = curl_init();
         $url = 'https://graph.facebook.com/v15.0/' . $mediaId;
         $headers = [
            'User-Agent: WhatsApp/2.19.81 A',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'Cookie: ps_l=1; ps_n=1'
         ];

         curl_setopt($ch, CURLOPT_URL, $url);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

         $response = curl_exec($ch);

         if (curl_errno($ch)) {
            error_log('cURL error: ' . curl_error($ch));
            curl_close($ch);
            exit;
         }

         $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         if ($httpCode != 200) {
            error_log('HTTP error code: ' . $httpCode . '. Response: ' . $response);
            curl_close($ch);
            exit;
         }
         curl_close($ch);

         $result = json_decode($response, true);
         if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decoding error: ' . json_last_error_msg());
            exit;
         }

         if (isset($result['url'])) {
            $mediaUrl = $result['url'];

            // --- LOGIC CHANGE FOR AUDIO/VIDEO ---
            if ($mediaType === 'audio' || $mediaType === 'video') {
                $statusText = ($mediaType === 'audio') ? "Sent an audio" : "Sent a video";
                
                $payload = json_decode($payload, true);
                
                // Change message type to text and add the body
                $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] = 'text';
                $payload['entry'][0]['changes'][0]['value']['messages'][0]['text'] = ['body' => $statusText];
                
                // Clear the media specific key and set filePath
                unset($payload['entry'][0]['changes'][0]['value']['messages'][0][$mediaType]);
                $payload['filePath'] = $statusText;
                $payload['fqdn'] = $fqdn;
                $payload['appId'] = $appId;
                $payload = json_encode($payload);

                error_log("Processed $mediaType as text: $statusText");
            } else {
                     // --- KEEP ORIGINAL LOGIC FOR IMAGE/DOCUMENT ---
            $mediaCh = curl_init();
            curl_setopt($mediaCh, CURLOPT_URL, $mediaUrl);
            curl_setopt($mediaCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($mediaCh, CURLOPT_HTTPHEADER, $headers);
            $mediaResponse = curl_exec($mediaCh);

            // 1. DYNAMIC EXTENSION LOGIC (As-Is)
            // This takes 'application/pdf' and turns it into '.pdf'
            $mimeParts = explode('/', $result['mime_type']);
            $rawExt = end($mimeParts);

            // 2. Map the "ugly" Office mimes to clean extensions
            $extensionMap = [
                'vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'vnd.ms-excel' => 'xls',
                'vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'msword' => 'doc',
                'plain' => 'txt'
            ];

            $fileExtension = '.' . ($extensionMap[$rawExt] ?? $rawExt);

            // 3. SET THE PATH AS-IS
            $filePath = ($mediaType === 'image') ? '/var/www/webapp/chat_file_upload/wp_img/' : '/var/www/webapp/chat_file_upload/wp_file/';

            $fileName = uniqid() . $fileExtension;
            $fullPath = $filePath . $fileName;

            // Save the file
            file_put_contents($fullPath, $mediaResponse);
            error_log('Media saved to: ' . $fullPath);

            // Update Payload
            $payload = json_decode($payload, true);
            $payload['filePath'] = $fullPath;
            $payload['fqdn'] = $fqdn;
            $payload['appId'] = $appId;
            $payload = json_encode($payload);

            curl_close($mediaCh);
            }
         } else {
            error_log('Media URL not found in the response.');
         }
      }

      echo http_response_code(200);
      $client = new Client(
          Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", ["persistent" => false])
      );
      $client->initialize();

      // Final Payload Prep
      $payload = json_decode($payload, true);
      $payload['fqdn'] = $fqdn;
      $payload['appId'] = $appId;
      $payload = json_encode($payload);

      $content = (array) json_decode(json_encode($payload), true);
      $client->emit("whatsapp", $content);
      return $this->response;
   }

   public function checkerAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      if ($this->request->isGet()) {
         $this->response->redirect("index");
         return;
      }
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["company_name"] = !empty($this->request->getPost("company_name", "string")) ? ucfirst($this->request->getPost("company_name", "string")) : "";
         $parameters["firstname"] = !empty($this->request->getPost("firstname", "string")) ? ucfirst($this->request->getPost("firstname", "string")) : "";
         $parameters["lastname"] = !empty($this->request->getPost("lastname", "string")) ? ucfirst($this->request->getPost("lastname", "string")) : "";
         $parameters["address_1"] = !empty($this->request->getPost("address_1", "string")) ? ucfirst($this->request->getPost("address_1", "string")) : "";
         $parameters["address_2"] = !empty($this->request->getPost("address_2", "string")) ? ucfirst($this->request->getPost("address_2", "string")) : "";
         $parameters["phone"] = $this->request->getPost("phone", "string");
         $parameters["city"] = !empty($this->request->getPost("city", "string")) ? ucfirst($this->request->getPost("city", "string")) : "";
         $parameters["state"] = !empty($this->request->getPost("state", "string")) ? ucfirst($this->request->getPost("state", "string")) : "";
         $parameters["country"] = !empty($this->request->getPost("country", "string")) ? ucfirst($this->request->getPost("country", "string")) : "";
         $parameters["company_email"] = $this->request->getPost("company_email", "string");
         $parameters["fax"] = $this->request->getPost("fax", "string");
         $parameters["contact_person"] = !empty($this->request->getPost("contact_person", "string")) ? ucfirst($this->request->getPost("contact_person", "string")) : "";
         $parameters["fqdn"] = $this->request->getPost("fqdn", "string");
         $parameters["extension_length"] = intval($this->request->getPost("extension_length", "int"));
         if (empty($parameters["call_profile"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
            $response = json_encode([
               "result" => "error",
               "message" => "Unathorized request made (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
         $registerForm = new RegisterForm();
         if (!$registerForm->isValid($parameters)) {
            $messages = $registerForm->getMessages();
            $message = "";
            foreach ($messages as $error) {
               $message .= $error . " ";
            }
            $response = json_encode([
               "result" => "error",
               "message" => trim($message) . " (Code RC1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         } else {
            $qCompany = QCompanies::findFirst([
               "conditions" => "company_email = :company_email:",
               "bind" => [
                  "company_email" => $parameters["company_email"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($qCompany) {
               $data = json_encode([
                  "result" => "error",
                  "message" => "Email address alredy been used. (Code RC1001)",
               ]);
               $this->response->setContent($data);
               return $this->response;
            }
            $vDomain = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                  "domain_name" => $parameters["fqdn"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($vDomain) {
               $data = json_encode([
                  "result" => "error",
                  "message" => "FQDN alredy been used. (Code RC1002)",
               ]);
               $this->response->setContent($data);
               return $this->response;
            }
            $qPackages = QPackages::find([
               "conditions" => "status = 1",
               "order" => "id ASC",
            ]);
            $response = json_encode([
               "result" => "success",
               "message" => "Valid registration parameters.",
               "content" => $qPackages,
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
      }
   }

   public function checker2Action() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      if ($this->request->isGet()) {
         $this->response->redirect("index");
         return;
      }
      $session = $this->session->get("auth");
      if (!$session) {
         $response = json_encode(
            [
               "result" => "error",
               "message" => "Unathorized. (Code AI1004)",
            ]
         );
         $this->response->setContent($response);
         return $this->response;
      }
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["address_1"] = !empty($this->request->getPost("address_1", "string")) ? ucfirst($this->request->getPost("address_1", "string")) : "";
         $parameters["address_2"] = !empty($this->request->getPost("address_2", "string")) ? ucfirst($this->request->getPost("address_2", "string")) : "";
         $parameters["phone"] = $this->request->getPost("phone", "string");
         $parameters["otp"] = $this->request->getPost("otp", "string");
         $parameters["city"] = !empty($this->request->getPost("city", "string")) ? ucfirst($this->request->getPost("city", "string")) : "";
         $parameters["state"] = !empty($this->request->getPost("state", "string")) ? ucfirst($this->request->getPost("state", "string")) : "";
         $parameters["country"] = !empty($this->request->getPost("country", "string")) ? ucfirst($this->request->getPost("country", "string")) : "";
         $parameters["fax"] = $this->request->getPost("fax", "string");
         $parameters["zip"] = intval($this->request->getPost("zip", "int"));
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         if (empty($parameters["phone"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         if ($parameters["type"] == 1) {
            $qCompany = QCompanies::findFirst([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if (!$qCompany) {
               $data = json_encode([
                  "result" => "error",
                  "message" => "Sorry, please check your request. (Code RC1001)",
               ]);
               $this->response->setContent($data);
               return $this->response;
            }
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "https://apiringq.ringq.io/kyc/otp");
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(["userId" => $qCompany->uid, "phone" => $parameters["phone"]]));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
               "Accept: application/json",
               "Content-Type: application/json",
               "UUID: " . $qCompany->uuid,
               "Authorization: asgasgihqwgagsjokashiogahiosasbjg",
               "Authorization-token: " . $qCompany->token
            ]);
            $responseContent = curl_exec($curl);
            $responseStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $responseContent = json_decode($responseContent);
            $response = json_encode($responseContent);
            error_log($response);
            $this->response->setContent($response);
            return $this->response;
         } else if ($parameters["type"] == 2) {
            $qCompany = QCompanies::findFirst([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if (!$qCompany) {
               $data = json_encode([
                  "result" => "error",
                  "message" => "Sorry, please check your request. (Code RC1001)",
               ]);
               $this->response->setContent($data);
               return $this->response;
            }
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "https://apiringq.ringq.io/kyc/otpverify");
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(["userId" => $qCompany->uid, "otp" => $parameters["otp"], "phone" => $parameters["phone"]]));
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
               "Accept: application/json",
               "Content-Type: application/json",
               "UUID: " . $qCompany->uuid,
               "Authorization: asgasgihqwgagsjokashiogahiosasbjg",
               "Authorization-token: " . $qCompany->token
            ]);
            $responseContent = curl_exec($curl);
            $responseStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $responseContent = json_decode($responseContent);
            $response = json_encode($responseContent);
            error_log($response);
            $this->response->setContent($response);
            return $this->response;
         } else {
            $register2Form = new Register2Form();
            if (!$register2Form->isValid($parameters)) {
               $messages = $register2Form->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code RC1000)",
                  "content" => [],
               ]);
               $this->response->setContent($response);
               return $this->response;
            } else {
               $qCompany = QCompanies::findFirst([
                  "conditions" => "domain_uuid = :domain_uuid:",
                  "bind" => [
                     "domain_uuid" => $session["domain_uuid"],
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]);
               if (!$qCompany) {
                  $data = json_encode([
                     "result" => "error",
                     "message" => "Sorry, please check your request. (Code RC1001)",
                  ]);
                  $this->response->setContent($data);
                  return $this->response;
               }
               $qCompany->address_1 = $parameters["address_1"];
               $qCompany->address_2 = $parameters["address_2"];
               $qCompany->phone = $parameters["phone"];
               $qCompany->city = $parameters["city"];
               $qCompany->state = $parameters["state"];
               $qCompany->country = $parameters["country"];
               $qCompany->zip = $parameters["zip"];
               if (!$qCompany->update()) {
                  $this->db->rollback();
                  $messages = $qCompany->getMessages();
                  $message = "";
                  foreach ($messages as $error) {
                     $message .= $error . " ";
                  }
                  $response = json_encode(
                     [
                        "result" => "error",
                        "message" => trim($message) . " (Code AQ1003)",
                        "content" => [],
                     ]
                  );
                  $this->response->setContent($response);
                  return $this->response;
               }

               $curl = curl_init();
               curl_setopt($curl, CURLOPT_URL, "https://apiringq.ringq.io/kyc");
               curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
                  "userId" => $qCompany->uid,
                  "address" => $parameters["address_1"],
                  "apt" => $parameters["address_2"],
                  "state" => $parameters["state"],
                  "phone" => $parameters["phone"],
                  "city" => $parameters["city"],
                  "country" => $parameters["country"]
               ]));
               curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($curl, CURLOPT_HTTPHEADER, [
                  "Accept: application/json",
                  "Content-Type: application/json",
                  "UUID: " . $qCompany->uuid,
                  "Authorization: asgasgihqwgagsjokashiogahiosasbjg",
                  "Authorization-token: " . $qCompany->token
               ]);
               $responseContent = curl_exec($curl);
               $responseStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
               curl_close($curl);
               $responseContent = json_decode($responseContent);
               $response = json_encode($responseContent);
               error_log($response);
               $this->response->setContent($response);
               return $this->response;
            }
         }
      }
   }

   public function updateversionAction() {
      if ($this->request->isPost()) {
         $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
         $this->response->setHeader("Content-Type", "application/json");

         $rawBody = $this->request->getRawBody();
         $rawData = json_decode($rawBody, true);

         if (!$this->redis->exists('ringq_config')) {
            return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'License not found'
            ]);
         }

         $getLicenseConfig = $this->redis->get('ringq_config');
         $licenseConfig = json_decode($getLicenseConfig, true);

         if ($licenseConfig['data']['licenseKey'] !== $rawData['licenseKey']) {
            return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'Invalid license key'
            ]);
         }

         return $this->auditedLicensedUpdate();
      }
   }

   private function auditedLicensedUpdate() {
      $runId = bin2hex(random_bytes(8));
      $fromVersion = (string)$this->redis->get("serverVersion");
      $actor = [
         "domain_uuid" => $this->licensedDomainUuid(),
         "username" => "license-server",
         "user_fullname" => "License server (remote update)",
      ];
      $this->audit("system-update", "update-started", [
         "actor" => $actor,
         "entity" => "latest",
         "skipPayload" => true,
         "result" => "success",
         "metadata" => ["trigger" => "remote", "runId" => $runId, "fromVersion" => $fromVersion],
      ]);
      try {
         $updateResponse = $this->runLicensedUpdate();
      } catch (\Throwable $e) {
         $this->audit("system-update", "update-failed", [
            "actor" => $actor,
            "entity" => "latest",
            "skipPayload" => true,
            "result" => "error",
            "metadata" => ["trigger" => "remote", "runId" => $runId, "fromVersion" => $fromVersion, "message" => ControllerBase::safeErrorMessage($e)],
         ]);
         throw $e;
      }
      if (!$updateResponse) {
         $updateResponse = $this->response->setJsonContent([
            "result" => "error",
            "version" => null,
            "message" => "The update could not be started.",
         ]);
      }
      $outcome = json_decode((string)$updateResponse->getContent(), true);
      $succeeded = is_array($outcome) && ($outcome["result"] ?? null) === "success";
      $toVersion = is_array($outcome) && !empty($outcome["version"]) ? (string)$outcome["version"] : null;
      $readinessFile = __DIR__ . "/../library/UpdateReadiness.php";
      if ($succeeded && is_file($readinessFile)) {
         // "Successfully updated" only means the files landed; this reports when calls work again.
         try {
            require_once $readinessFile;
            UpdateReadiness::spawn($runId, "remote", $fromVersion, (string)$toVersion);
         } catch (\Throwable $e) {
            error_log("UpdateReadiness: " . $e->getMessage());
         }
      }
      $this->audit("system-update", $succeeded ? "update-completed" : "update-failed", [
         "actor" => $actor,
         "entity" => $toVersion ?? "latest",
         "skipPayload" => true,
         "result" => $succeeded ? "success" : "error",
         "metadata" => array_filter([
            "trigger" => "remote",
            "runId" => $runId,
            "fromVersion" => $fromVersion,
            "toVersion" => $toVersion,
            "message" => $succeeded ? null : (is_array($outcome) ? ($outcome["message"] ?? null) : null),
         ], function ($value) { return $value !== null; }),
      ]);
      return $updateResponse;
   }

   private function licensedDomainUuid() {
      try {
         $licenseConfig = json_decode((string)$this->redis->get("ringq_config"), true);
         $fqdn = is_array($licenseConfig) ? ($licenseConfig["data"]["fqdn"] ?? "") : "";
         return AuditMap::domainUuidFor($this->db, $fqdn);
      } catch (\Throwable $e) {
         error_log("licensedDomainUuid: " . $e->getMessage());
         return null;
      }
   }

   private function runLicensedUpdate() {
      $server = '143.198.209.102';
      $user = 'ringq-customer';
      $key = 'ringqsecurepassword@62';
      exec("sudo /var/www/ringq/app/scripts/update_version.sh 2>&1 '$server' '$user' '$key' 2>&1", $output, $result);
      sleep(2);
      if ($result == 0) {
         try {
            $fileSeverResJson = $this->redis->get('update_result');
            $response = json_decode($fileSeverResJson, true);

            if (!$response || !isset($response["version"])) {
               return $this->response->setJsonContent([
                  "result" => "error",
                  "version" => null,
                  "message" => "Update result not found or invalid JSON.",
               ]);
            }

            $this->redis->del('update_result');

            if (!empty($response["database"]) && is_array($response["database"])) {
               foreach ($response["database"] as $databaseUpdate) {
                  try {
                     $this->db->execute($databaseUpdate);
                  } catch (\Exception $e) {
                     error_log("DB update failed: " . $e->getMessage());
                  }
               }
            }

            foreach (["/var/cache/ringq", "/var/cache/fusionpbx"] as $cacheDir) {
               if (is_dir($cacheDir)) {
                  foreach (glob($cacheDir . "/*") as $file) {
                     if (is_file($file)) {
                        @unlink($file);
                     }
                  }
               }
            }

            try {
               $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                  "persistent" => false,
               ]));
               $client->initialize();
               $client->emit("gatewaystart", ["uuid" => ""]);
            } catch (\Exception $e) {
               error_log("WebSocket error: " . $e->getMessage());
            }

            shell_exec("sudo /bin/pm2 restart all 2>&1");
            shell_exec("sudo /bin/pm2 update 2>&1");

            $this->redis->set('restart_jobs', 1);

            return $this->response->setJsonContent([
               "result" => "success",
               "version" => $response["version"],
               "message" => "Successfully updated.",
            ]);
         } catch (\Exception $e) {
            return $this->response->setJsonContent([
               "result" => "error",
               "version" => null,
               "message" => "Update failed: " . ControllerBase::safeErrorMessage($e),
            ]);
         }
      }
   }

   public function extensionsAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $session = [];

      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         $parameters["fqdn"] = intval($this->request->getPost("fqdn", "string"));

         if (empty($parameters["type"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);

            $vDomains = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                  "domain_name" => $parameters["fqdn"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);

            if ($vDomains) {
               $session = $vDomains->toArray();
               $session['domain_uuid'] = $vDomains->domain_uuid;
               $session['subdomain'] = $vDomains->domain_name;
            }
         }

         if ($parameters["type"] == 1) {
            $vCallCenterAgents = VCallCenterAgents::find(
               [
                  "hydration" => Resultset::HYDRATE_RECORDS,
                  "conditions" => "domain_uuid = :domain_uuid: AND queue_extension IS NULL AND agent_id != :agent_id: AND agent_id != :agent_id2: AND agent_id != :agent_id3:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "agent_id" => "9997",
                        "agent_id2" => "9998",
                        "agent_id3" => "9999",
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
                  "order" => "agent_id ASC"
               ]
            );

            $callcenterAgents3 = array();
            foreach ($vCallCenterAgents as $vCallCenterAgent) {
               array_push($callcenterAgents3, [
                  "name" => $vCallCenterAgent->agent_name,
                  "firstname" => isset($vCallCenterAgent->QUsers->first_name) ? $vCallCenterAgent->QUsers->first_name : "",
                  "lastname" => isset($vCallCenterAgent->QUsers->last_name) ? $vCallCenterAgent->QUsers->last_name : "",
                  "extension" => $vCallCenterAgent->agent_id,
               ]);
            }

            $vIvrMenus = VIvrMenus::find(
               [
                  "hydration" => Resultset::HYDRATE_RECORDS,
                  "conditions" => "domain_uuid = :domain_uuid: AND ivr_menu_extension > '5000'",
                  "columns" => "ivr_menu_uuid, ivr_menu_name, ivr_menu_extension, ivr_menu_greet_long, ivr_menu_exit_data, ivr_menu_timeout, ivr_menu_direct_dial",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
                  "order" => "ivr_menu_extension ASC",
               ]
            );

            $qDids = QDids::find(
               [
                  "hydration" => Resultset::HYDRATE_RECORDS,
                  "conditions" => "domain_uuid = :domain_uuid:",
                  "columns" => "did_uuid, did_number, type, name, extension, outbound",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]
            );

            $response = json_encode(
               [
                  "result" => "success",
                  "data" => [
                     'callcenterAgents3' => $callcenterAgents3,
                     'vIvrMenus' => $vIvrMenus,
                     'qDids' => $qDids
                  ],
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }
      }
   }

   private function resolveSipProfileName($profileUuid)
   {
      if (empty($profileUuid)) {
         return null;
      }
      try {
         $vSipProfile = VSipProfiles::findFirst([
            "conditions" => "sip_profile_uuid = :sip_profile_uuid:",
            "bind" => [
               "sip_profile_uuid" => $profileUuid,
            ],
            "bindTypes" => [Column::BIND_PARAM_STR],
         ]);
         if ($vSipProfile && !empty($vSipProfile->sip_profile_name)) {
            return $vSipProfile->sip_profile_name;
         }
      } catch (\Throwable $e) {
         error_log("resolveSipProfileName failed: " . $e->getMessage());
      }
      return null;
   }

   public function sipAction($uuid = "", $type = 1) {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $session = [];
      if ($this->request->isPost()) {
         $parameters = array();
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         $parameters["fqdn"] = $this->request->getPost("fqdn", "string");
         $parameters["gateway_uuid"] = $this->request->getPost("gateway_uuid", "string");
         $parameters["gateway"] = $this->request->getPost("gateway", "string");
         $parameters["username"] = $this->request->getPost("username", "string");
         $parameters["password"] = $this->request->getPost("password", "string");
         $parameters["proxy"] = $this->request->getPost("proxy", "string");
         $parameters["codec"] = $this->request->getPost("codec", "string");
         $parameters["max_calls"] = $this->request->getPost("max_calls", "int");
         $parameters["authentication"] = $this->request->getPost("authentication", "int");
         $parameters["outbound_caller_id"] = $this->request->getPost("outbound_caller_id", "string");
         $parameters["register_transport"] = $this->request->getPost("register_transport", "string") == "UDP" ? "UDP" : "TCP";
         $parameters["expire_seconds"] = intval($this->request->getPost("expire_seconds", "string")) > 0 ? intval($this->request->getPost("expire_seconds", "string")) : 800;
         $parameters["retry_seconds"] = intval($this->request->getPost("retry_seconds", "string")) > 0 ? intval($this->request->getPost("retry_seconds", "string")) : 30;
         $parameters["profile_uuid"] = $this->request->getPost("profile_uuid", "string");
         $parameters["privacy_header"] = intval($this->request->getPost("privacy_header", "int"));
         $parameters["from_user"] = $this->request->getPost("from_user", "string");
         $parameters["from_display"] = $this->request->getPost("from_display", "string");
         $parameters["did_match"] = $this->request->getPost("did_match", "string");
         if (empty($parameters["gateway"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);

            $vDomains = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                  "domain_name" => $parameters["fqdn"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);

            if ($vDomains) {
               $session = $vDomains->toArray();
               $session['domain_uuid'] = $vDomains->domain_uuid;
               $session['subdomain'] = $vDomains->domain_name;
            }
         }
         if ($parameters["type"] == 3) {
            $vGateway = VGateways::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND gateway_uuid = :gateway_uuid:",
                  "bind" => [
                     "domain_uuid" => $session["domain_uuid"],
                     "gateway_uuid" => $parameters["gateway_uuid"],
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if (!$vGateway) {
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => "Sorry, gateway does not exists. (Code AG1008)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            if (!$vGateway->delete()) {
               $messages = $vGateway->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1009)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            } else {
               if (file_exists("/var/cache/ringq/configuration.sofia.conf." . gethostname())) {
                  unlink("/var/cache/ringq/configuration.sofia.conf." . gethostname());
               }
               $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                  "persistent" => false,
               ]));
               $client->initialize();
               $client->emit("gatewaystart", ["uuid" => $parameters["gateway_uuid"]]);
               $response = json_encode(
                  [
                     "result" => "success",
                     "message" => "Successfully, deleted gateway. (Code AG1010)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
         }
         if ($parameters["type"] == 4) {
            $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
               "persistent" => false,
            ]));
            $client->initialize();
            $client->emit("gatewaystatus", ["subdomain" => $session["subdomain"]]);
            $vGateways = VGateways::find(
               [
                  "conditions" => "domain_uuid = :domain_uuid:",
                  "bind" => [
                     "domain_uuid" => $session["domain_uuid"],
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]
            );
            $index = 0;
            $content = array();
            $sipProfiles = array();
            $profileUuidByName = array();
            try {
               $vSipProfiles = VSipProfiles::find([
                  "conditions" => "sip_profile_enabled = :sip_profile_enabled:",
                  "columns" => "sip_profile_uuid, sip_profile_name",
                  "bind" => [
                     "sip_profile_enabled" => "true",
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
                  "order" => "sip_profile_name ASC",
               ]);
               foreach ($vSipProfiles as $vSipProfile) {
                  $sipProfiles[] = [
                     "sip_profile_uuid" => $vSipProfile->sip_profile_uuid,
                     "sip_profile_name" => $vSipProfile->sip_profile_name,
                  ];
                  $profileUuidByName[$vSipProfile->sip_profile_name] = $vSipProfile->sip_profile_uuid;
               }
            } catch (\Throwable $e) {
               error_log("sipAction sip_profiles lookup failed: " . $e->getMessage());
            }
            foreach ($vGateways as $vGateway) {
               /**
                * Allow the fs_cli command in the sudoers file before uncommenting the below code
                * www-data ALL=(ALL) NOPASSWD: /usr/bin/fs_cli
                */

               // $gatewayIdentifier = 'external::' . $vGateway->gateway_uuid;
               // $fsCliPath = '/usr/bin/fs_cli';
               // $cmd = "sudo $fsCliPath -x \"sofia status gateway $gatewayIdentifier\" 2>&1";
               // $output = shell_exec($cmd);
               // $gatewayStatus = "down";
               // if (is_string($output) && preg_match('/^State\s+(\w+)/mi', $output, $matches)) {
               //    $gatewayState = strtolower($matches[1]);

               //    if (in_array($gatewayState, ['reged', 'registered'])) {
               //       $gatewayStatus = "up";
               //    } else {
               //       $gatewayStatus = "down";
               //    }
               // } else {
               //    error_log("Failed to get gateway status for: $gatewayIdentifier. Output: " . var_export($output, true));
               // }

               $gatewayStatus = "down";
               $gatewaySipKey = 'gatewayStatus-' . $vGateway->gateway_uuid;
               if ($this->redis->exists($gatewaySipKey)) {
                  $gatewayJson = $this->redis->get($gatewaySipKey);
                  $gatewayData = json_decode($gatewayJson, true);
                  $gatewayStatus = isset($gatewayData['state']) && $gatewayData['state'] == 'REGED' ? 'up' : "down";
               }

               $content[$index]["uuid"] = $vGateway->gateway_uuid;
               $content[$index]["gateway"] = $vGateway->gateway;
               $content[$index]["username"] = $vGateway->username;
               $content[$index]["password"] = $vGateway->password;
               $proxy = $vGateway->proxy;
               $proxy2 = explode(":", $proxy);
               $content[$index]["proxy"] = current($proxy2);
               $content[$index]["port"] = str_contains($proxy, ":") ? end($proxy2) : "";
               $content[$index]["codec_prefs"] = is_null($vGateway->codec_prefs) ? "" : explode(",", $vGateway->codec_prefs);
               $content[$index]["description"] = $vGateway->description;
               $content[$index]["expire_seconds"] = $vGateway->expire_seconds;
               $content[$index]["retry_seconds"] = $vGateway->retry_seconds;
               $content[$index]["max_calls"] = $vGateway->channels;
               $content[$index]["register"] = $vGateway->register;
               $content[$index]["transport"] = is_null($vGateway->register_transport) ? "UDP" : strtoupper($vGateway->register_transport);
               $content[$index]["status"] = $vGateway->gateway;
               $content[$index]["enabled"] = $vGateway->enabled;
               $content[$index]["profile_uuid"] = $profileUuidByName[$vGateway->profile] ?? "";
               $content[$index]["privacy_header"] = $vGateway->privacy_header == 1;
               $content[$index]["customUser"] = $vGateway->custom_user;
               $content[$index]["customDisplay"] = $vGateway->custom_display;
               $content[$index]["customMatch"] = $vGateway->custom_match;
               $content[$index]["from_user"] = $vGateway->custom_user;
               $content[$index]["from_display"] = $vGateway->custom_display;
               $content[$index]["did_match"] = $vGateway->custom_match;
               $content[$index]['gateway_status'] = $gatewayStatus;
               $index++;
            }
            $status = array();
            $content3 = $this->redis->get($session["subdomain"] . "-gateways");
            if ($content3) {
               $content3 = trim($content3);
               $status = explode(" ", $content3);
            }
            $response = json_encode(
               [
                  "result" => "success",
                  "data" => $content,
                  "sip_profiles" => $sipProfiles,
                  "status" => $status,
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }
         $gatewayForm = new GatewayForm();
         if (!$gatewayForm->isValid($parameters)) {
            $messages = $gatewayForm->getMessages();
            $message = "";
            foreach ($messages as $error) {
               $message .= $error . " ";
            }
            $response = json_encode(
               [
                  "result" => "error",
                  "message" => trim($message) . " (Code AG1001)",
                  "content" => [],
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }
         if ($parameters["type"] == 1) {
            $exists = VGateways::findFirst([
               "conditions" => "domain_uuid = :domain_uuid: AND gateway = :gateway:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
                  "gateway" => $parameters["gateway"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if ($exists) {
               $response = json_encode([
                  "result" => "error",
                  "message" => "Sorry, gateway already exists, please choose another gateway name (Code AG1002)",
                  "content" => [],
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $vGateway = new VGateways();
            $security = new \Phalcon\Security\Random();
            $vGateway->domain_uuid = $session["domain_uuid"];
            $vGateway->gateway_uuid = $security->uuid();
            $vGateway->gateway = $parameters["gateway"];
            $vGateway->username = $parameters["username"];
            $vGateway->password = $parameters["password"];
            $vGateway->proxy = $parameters["proxy"];
            $vGateway->codec_prefs = $parameters["codec"];
            $vGateway->expire_seconds = $parameters["expire_seconds"];
            $vGateway->register = $parameters["authentication"] == 2 ? "false " : "true";
            $vGateway->register_transport = strtolower($parameters["register_transport"]);
            $vGateway->description = $parameters["outbound_caller_id"];
            $vGateway->caller_id_in_from = !empty($parameters["outbound_caller_id"]) ? "true" : null;
            $vGateway->retry_seconds = $parameters["retry_seconds"];
            $vGateway->channels = $parameters["max_calls"];
            $vGateway->context = "public";
            $vGateway->profile = $this->resolveSipProfileName($parameters["profile_uuid"] ?? "") ?: "external";
            $vGateway->enabled = "true";
            $vGateway->custom_user = empty($parameters["from_user"]) ? null : $parameters["from_user"];
            $vGateway->custom_display = empty($parameters["from_display"]) ? null : $parameters["from_display"];
            $vGateway->custom_match = empty($parameters["did_match"]) ? null : $parameters["did_match"];
            $vGateway->privacy_header = intval($parameters["privacy_header"] ?? 0) == 1 ? 1 : 0;
            $vGateway->insert_user = $session["user_uuid"];
            if (!$vGateway->create()) {
               $messages = $vGateway->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1003)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            } else {
               $action = strtoupper($parameters["gateway"]);
               $qOutboundRules = QOutboundRules::find([
                  "conditions" => "domain_uuid = :domain_uuid: AND action = :action:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "action" => $action
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]);
               foreach ($qOutboundRules as $qOutboundRule) {
                  $vDialplan = VDialplans::findFirst([
                     "conditions" => "domain_uuid = :domain_uuid: AND dialplan_uuid = :dialplan_uuid:",
                     "bind" => [
                        "domain_uuid" => $session["domain_uuid"],
                        "dialplan_uuid" => $qOutboundRule->outbound_uuid
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]);
                  if ($vDialplan) {
                     $xml = "";
                     $parameters["strip"] = $qOutboundRule->strip;
                     $parameters["action"] = $qOutboundRule->action;
                     $parameters["prepend"] = $qOutboundRule->prepend;
                     $outbound_uuid = $qOutboundRule->outbound_uuid;
                     $parameters["prefix_pattern"] = $qOutboundRule->prefix_pattern;
                     $parameters["prefix_pattern2"] = [];
                     // Emergency rules (priority < 0) never match on number length.
                     $parameters["calls_number_length"] = intval($qOutboundRule->priority) < 0 ? "" : $qOutboundRule->calls_number_length;
                     $parameters["calls_number_length2"] = "";
                     $callNumberRange = false;
                     if (str_contains($parameters["calls_number_length"], "-")) {
                        $callNumberRange = true;
                        $explodedCallNumbers = explode("-", $parameters["calls_number_length"]);
                        $parameters["calls_number_length"] = current($explodedCallNumbers);
                        $parameters["calls_number_length2"] = next($explodedCallNumbers);
                     }
                     $multiplePrefixes = false;
                     if (str_contains($parameters["prefix_pattern"], ",")) {
                        $multiplePrefixes = true;
                        $parameters["prefix_pattern2"] = explode(",", $parameters["prefix_pattern"]);
                     }
                     $defaults = ["001", "002", "003", "004", "00"];
                     $length = 0;
                     $positivePrefix = !$multiplePrefixes ? $parameters["prefix_pattern"] : current($parameters["prefix_pattern2"]);
                     if (!empty($parameters["prefix_pattern"])) {
                        if (strlen($positivePrefix)) {
                           if (!$callNumberRange) {
                              $parameters["calls_number_length"] = $parameters["calls_number_length"] - strlen($positivePrefix);
                           } else {
                              $parameters["calls_number_length"] = $parameters["calls_number_length"] - strlen($positivePrefix);
                              $parameters["calls_number_length2"] = $parameters["calls_number_length2"] - strlen($positivePrefix);
                           }
                        }
                        if (substr($parameters["prefix_pattern"], 0, 1) == "+") {
                           $positivePrefix = '\\' . $parameters["prefix_pattern"];
                        }
                     }
                     // Emergency rules match the exact emergency number(s) only (licence limit).
                     if (intval($qOutboundRule->priority) < 0) {
                         $parameters["calls_number_length"] = "0,0";
                         $callNumberRange = false;
                     }
                     $prefix = '';
                     if (!$callNumberRange) {
                        $prefix = '^' . $positivePrefix . '(\d{' . $parameters["calls_number_length"] . '})$';
                     } else {
                        $prefix = '^' . $positivePrefix . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                     }
                     $prefixes = $prefix;
                     foreach ($defaults as $default) {
                        if (!$callNumberRange) {
                           $prefixes .= '|^' . $default . $parameters["prefix_pattern"] . '(\d{' . $parameters["calls_number_length"] . '})$';
                        } else {
                           $prefixes .= '|^' . $default . $parameters["prefix_pattern"] . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                        }
                     }
                     $extensions = [];
                     $extensions2 = [];
                     $parameters["team_extensions"] = $qOutboundRule->team_extensions;
                     $teamExtensions = is_null($parameters["team_extensions"]) ? [] : explode(",", $parameters["team_extensions"]);
                     foreach ($teamExtensions as $teamExtension) {
                        $vRingGroup = VRingGroups::findFirst([
                           "conditions" => "domain_uuid = :domain_uuid: AND ring_group_extension = :ring_group_extension:",
                           "bind" => [
                              "domain_uuid" => $session["domain_uuid"],
                              "ring_group_extension" => $teamExtension
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]);
                        if ($vRingGroup) {
                           $vRingGroupDestinations = VRingGroupDestinations::find(
                              [
                                 "conditions" => "domain_uuid = :domain_uuid: AND ring_group_uuid = :ring_group_uuid:",
                                 "bind" =>
                                    [
                                       "domain_uuid" => $session["domain_uuid"],
                                       "ring_group_uuid" => $vRingGroup->ring_group_uuid
                                    ],
                                 "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                              ]
                           );
                           foreach ($vRingGroupDestinations as $vRingGroupDestination) {
                              array_push($extensions, strval($vRingGroupDestination->destination_number));
                           }
                        }
                     }
                     $parameters["calls_extensions"] = $qOutboundRule->calls_extensions;
                     $extensions2 = empty($parameters["calls_extensions"]) ? [] : explode(",", $parameters["calls_extensions"]);
                     foreach ($extensions as $extension) {
                        if (!in_array($extension, $extensions2)) {
                           array_push($extensions2, $extension);
                        }
                     }
                     $parameters["extension_range"] = $qOutboundRule->extension_range;
                     $extensionRanges = is_null($parameters["extension_range"]) ? [] : explode(",", $parameters["extension_range"]);
                     foreach ($extensionRanges as $extensionRange) {
                        $minMax = explode("-", $extensionRange);
                        if (count($minMax) > 1) {
                           array_push($extensions2, $this->regexRangeExpression(trim($minMax[0]), trim($minMax[1])));
                        }
                     }
                     $callExtensions = empty($parameters["calls_extensions"]) && empty($parameters["team_extensions"]) && empty($parameters["extension_range"]) ? "" : '<condition field="${effective_caller_id_number}" expression="^' . implode("|", $extensions2) . '$"/>';
                     $action = "";
                     $parameters["outbound_caller_id"] = '${outbound_caller_id_number}';
                     $antiActionCallerId = '';
                     $efftiveCallerIds = '
                           <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                           <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                           <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                        ';
                     if ($qOutboundRule->outbound_caller_id) {
                        $parameters["outbound_caller_id"] = $qOutboundRule->outbound_caller_id;
                        $efftiveCallerIds = '
                              <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                              <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                              <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                           ';
                     } else if ($vGateway->description) {
                        $parameters["outbound_caller_id"] = $vGateway->description;
                        $antiActionCallerId = '
                              <condition field="${outbound_caller_id_number}" expression="^$" break="never">
                                    <action application="log" data="CID missing, setting default to ' . $parameters["outbound_caller_id"] . '"/>
                                    <action application="set" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                              </condition>
                           ';
                        $efftiveCallerIds = '
                              <action application="log" data="INFO effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                              <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                              <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $session["subdomain"] . '"/>
                           ';
                     }
                     $privacy = ",privacy=off";
                     if ($vGateway->privacy_header == 1) {
                        $privacy = ",origination_privacy=screen+hide_name+hide_number,privacy=full";
                     }
                     $action = '<action application="bridge" data="{sip_cid_type=rpid,origination_caller_id_name=${outbound_caller_id_number},origination_caller_id_number=${outbound_caller_id_number}@' . $session["subdomain"] . $privacy . '}sofia/gateway/' . $vGateway->gateway_uuid . '/' . $parameters["prepend"] . '${dest_number:' . intval($parameters["strip"]) . '}"/>';
                     if ($parameters["action"] == "BLOCK") {
                        $action = '<action application="hangup" data="OUTGOING_CALL_BARRED"/>';
                     }
                     $xml = "";
                     $channelLimits = "";
                     if ($vGateway->channels != 0) {
                        $channelLimits = '
                              <action application="set" data="max_calls=' . $vGateway->channels . '" inline="true"/>
                              <action application="limit" data="db ' . $session["subdomain"] . ' ' . $vGateway->gateway_uuid . ' ${max_calls}"/>
                           ';
                     }
                     $codecValues = [
                        "OPUS" => "opus@16000h@40i",
                        "ILBC" => "iLBC@30i",
                        "G7221" => "G7221@32000h, G7221@16000h",
                        "G722" => "G722@16000h",
                        "G729" => "G729",
                        "PCMU" => "PCMU",
                        "PCMA" => "PCMA",
                     ];
                     $codecPrefs = '<action application="export" data="nolocal:' . 'absolute_codec_string=PCMA,PCMU,G729"/>';
                     if (!is_null($vGateway->codec_prefs)) {
                        $codecValues2 = [];
                        $codecs = explode(",", $vGateway->codec_prefs);
                        foreach ($codecs as $codec) {
                           $codec = strtoupper($codec);
                           if (array_key_exists($codec, $codecValues)) {
                              array_push($codecValues2, $codecValues[$codec]);
                           }
                        }
                        $codecValues2 = implode(",", $codecValues2);
                        $codecPrefs = '<action application="export" data="nolocal:absolute_codec_string=' . $codecValues2 . '"/>';
                     }
                     $parameters["outbound_name"] = $qOutboundRule->outbound_name;
                     $userExists = '<condition field="${user_exists}" expression="false"/>';
                     if ($qOutboundRule->priority < 0) {
                        $userExists = '';
                        $prefixes = "^" . $parameters["prefix_pattern"] . "(\d{0})$";
                     }
                     if (!$multiplePrefixes) {
                        $vDialplan->dialplan_xml = $xml = '<extension name="' . $parameters["outbound_name"] . '" continue="false" uuid="' . $outbound_uuid . '">
                              ' . $antiActionCallerId . '
                              ' . $userExists . '
                              ' . $callExtensions . '
                              <condition field="destination_number" expression="' . $prefixes . '" break="on-true">
                                    <action application="set" data="dest_number=' . $parameters["prefix_pattern"] . '$1$2$3$4$5$6"/>
                                    <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                                    <action application="export" data="call_direction=outbound" inline="true"/>
                                    ' . $codecPrefs . '
                                    <action application="unset" data="call_timeout"/>
                                    <action application="set" data="hangup_after_bridge=true"/>
                                    ' . $efftiveCallerIds . '
                                    <action application="set" data="inherit_codec=false"/>
                                    <action application="set" data="ignore_display_updates=true"/>
                                    <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                                    <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                                    <action application="set" data="continue_on_fail=true"/>
                                    ' . $channelLimits . $action . '
                                    <action application="playback" data="misc/sorry_your_call.wav"/>
                              </condition>
                           </extension>';
                     } else {
                        $xml = '<extension name="' . $parameters["outbound_name"] . '" continue="false" uuid="' . $outbound_uuid . '">
                              ' . $antiActionCallerId . '
                              ' . $userExists . '
                              ' . $callExtensions . '';
                        foreach ($parameters["prefix_pattern2"] as $prefix_pattern2) {
                           $prefix = '';
                           if (!$callNumberRange) {
                              $prefix = '^' . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . '})$';
                           } else {
                              $prefix = '^' . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                           }
                           $prefixes = $prefix;
                           // $parameters["prefix_pattern"] = preg_replace("/[^0-9]/", "", $parameters["prefix_pattern"]);
                           foreach ($defaults as $default) {
                              if (!$callNumberRange) {
                                 $prefixes .= '|^' . $default . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . '})$';
                              } else {
                                 $prefixes .= '|^' . $default . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                              }
                           }
                           $xml .= '<condition field="destination_number" expression="' . $prefixes . '" break="on-true">
                                       <action application="set" data="dest_number=' . $prefix_pattern2 . '$1$2$3$4$5$6"/><action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                                       <action application="export" data="call_direction=outbound" inline="true"/>
                                       ' . $codecPrefs . '
                                       <action application="unset" data="call_timeout"/>
                                       <action application="set" data="hangup_after_bridge=true"/>
                                       ' . $efftiveCallerIds . '
                                       <action application="set" data="inherit_codec=false"/>
                                       <action application="set" data="ignore_display_updates=true"/>
                                       <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                                       <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                                       <action application="set" data="continue_on_fail=true"/>
                                       ' . $channelLimits . $action . '
                                       <action application="playback" data="misc/sorry_your_call.wav"/>
                                    </condition>';
                        }
                        $xml .= '</extension>';
                        $vDialplan->dialplan_xml = $xml;
                     }
                     $vDialplan->dialplan_name = $parameters["outbound_name"];
                     $vDialplan->dialplan_continue = "false";
                     $vDialplan->dialplan_enabled = "false";
                     $vDialplan->update_user = $session["user_uuid"];
                     $vDialplan->update_date = date("Y-m-d H:i:s");
                     if (!$vDialplan->update()) {
                        $this->db->rollback();
                        $messages = $vDialplan->getMessages();
                        $message = "";
                        foreach ($messages as $error) {
                           $message .= $error . " ";
                        }
                        $response = json_encode([
                           "result" => "error",
                           "message" => trim($message) . " (Code AS1002)",
                           "content" => [],
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     }
                     $vDialplans = VDialplans::find([
                        "conditions" => "domain_uuid = :domain_uuid: AND dialplan_enabled = :dialplan_enabled: AND dialplan_description = :dialplan_description:",
                        "bind" => [
                           "domain_uuid" => $session["domain_uuid"],
                           "dialplan_enabled" => "false",
                           "dialplan_description" => "outbound-rule"
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        "order" => "dialplan_order ASC"
                     ]);
                     foreach ($vDialplans as $vDialplan) {
                        $xml .= "\n" . $vDialplan->dialplan_xml;
                     }
                     $vDialplan2 = VDialplans::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name:",
                        "bind" =>
                           [
                              "domain_uuid" => $session["domain_uuid"],
                              "dialplan_name" => "outbound-rules"
                           ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]);
                     if ($vDialplan2) {
                        $vDialplan2->dialplan_xml = $xml;
                        if (!$vDialplan2->update()) {
                           $this->db->rollback();
                           $messages = $vDialplan2->getMessages();
                           $message = "";
                           foreach ($messages as $error) {
                              $message .= $error . " ";
                           }
                           $response = json_encode(
                              [
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1005)",
                                 "content" => [],
                              ]
                           );
                           $this->response->setContent($response);
                           return $this->response;
                        }
                     } else {
                        $outbound_uuid2 = $security->uuid();
                        $vDialplan2 = new VDialplans();
                        $vDialplan2->domain_uuid = $session["domain_uuid"];
                        $vDialplan2->dialplan_uuid = $outbound_uuid2;
                        $vDialplan2->app_uuid = $security->uuid();
                        $vDialplan2->hostname = null;
                        $vDialplan2->dialplan_xml = $xml;
                        $vDialplan2->dialplan_name = "outbound-rules";
                        $vDialplan2->dialplan_order = 85;
                        $vDialplan2->dialplan_context = $session["subdomain"];
                        $vDialplan2->dialplan_continue = "false";
                        $vDialplan2->dialplan_enabled = "true";
                        $vDialplan2->insert_user = $session["user_uuid"];
                        $vDialplan2->insert_date = date("Y-m-d H:i:s");
                        if (!$vDialplan2->create()) {
                           $this->db->rollback();
                           $messages = $vDialplan2->getMessages();
                           $message = "";
                           foreach ($messages as $error) {
                              $message .= $error . " ";
                           }
                           $response = json_encode(
                              [
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1002)",
                                 "content" => [],
                              ]
                           );
                           $this->response->setContent($response);
                           return $this->response;
                        }
                     }
                  }
               }
               if (file_exists("/var/cache/ringq/dialplan." . $session["subdomain"])) {
                  unlink("/var/cache/ringq/dialplan." . $session["subdomain"]);
               }
               if (file_exists("/var/cache/ringq/configuration.sofia.conf." . gethostname())) {
                  unlink("/var/cache/ringq/configuration.sofia.conf." . gethostname());
               }
               $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                  "persistent" => false,
               ]));
               $client->initialize();
               $client->emit("gatewaystart", ["uuid" => ""]);
               $response = json_encode([
                  "result" => "success",
                  "message" => "Successfully, added new gateway. (Code AG1004)",
                  "content" => [
                     "gateway_uuid" => $vGateway->gateway_uuid
                  ],
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
         }
         if ($parameters["type"] == 2) {
            $vGateway = VGateways::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND gateway_uuid = :gateway_uuid:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "gateway_uuid" => $parameters["gateway_uuid"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if (!$vGateway) {
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => "Sorry, gateway does not exists. (Code AG1005)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $this->db->begin();
            // Capture original values to detect profile-level changes
            $originalProxy = $vGateway->proxy;
            $originalTransport = $vGateway->register_transport;
            $vGateway->gateway = $parameters["gateway"];
            $vGateway->username = $parameters["username"];
            $vGateway->password = $parameters["password"];
            $vGateway->proxy = $parameters["proxy"];
            $vGateway->codec_prefs = $parameters["codec"];
            $vGateway->expire_seconds = $parameters["expire_seconds"];
            $vGateway->retry_seconds = $parameters["retry_seconds"];
            $vGateway->description = $parameters["outbound_caller_id"];
            $vGateway->caller_id_in_from = !empty($parameters["outbound_caller_id"]) ? "true" : null;
            $vGateway->channels = $parameters["max_calls"];
            $vGateway->register = $parameters["authentication"] == 2 ? "false " : "true";
            $vGateway->register_transport = strtolower($parameters["register_transport"]);
            $vGateway->custom_user = empty($parameters["from_user"]) ? null : $parameters["from_user"];
            $vGateway->custom_display = empty($parameters["from_display"]) ? null : $parameters["from_display"];
            $vGateway->custom_match = empty($parameters["did_match"]) ? null : $parameters["did_match"];
            $vGateway->privacy_header = intval($parameters["privacy_header"] ?? 0) == 1 ? 1 : 0;
            $profileName = $this->resolveSipProfileName($parameters["profile_uuid"] ?? "");
            if ($profileName) {
               $vGateway->profile = $profileName;
            }
            $vGateway->update_user = $session["user_uuid"];
            $vGateway->update_date = date("Y-m-d H:i:s");
            if (!$vGateway->update()) {
               $this->db->rollback();
               $messages = $vGateway->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1006)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            } else {
               $action = strtoupper($parameters["gateway"]);
               $qOutboundRules = QOutboundRules::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid: AND action = :action:",
                     "bind" =>
                        [
                           "domain_uuid" => $session["domain_uuid"],
                           "action" => $action
                        ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qOutboundRules as $qOutboundRule) {
                  $vDialplan = VDialplans::findFirst(
                     [
                        "conditions" => "domain_uuid = :domain_uuid: AND dialplan_uuid = :dialplan_uuid:",
                        "bind" =>
                           [
                              "domain_uuid" => $session["domain_uuid"],
                              "dialplan_uuid" => $qOutboundRule->outbound_uuid
                           ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]
                  );
                  if ($vDialplan) {
                     $xml = "";
                     $parameters["prepend"] = $qOutboundRule->prepend;
                     $parameters["strip"] = $qOutboundRule->strip;
                     $parameters["action"] = $qOutboundRule->action;
                     $outbound_uuid = $qOutboundRule->outbound_uuid;
                     $parameters["prefix_pattern"] = $qOutboundRule->prefix_pattern;
                     $parameters["prefix_pattern2"] = [];
                     // Emergency rules (priority < 0) never match on number length.
                     $parameters["calls_number_length"] = intval($qOutboundRule->priority) < 0 ? "" : $qOutboundRule->calls_number_length;
                     $parameters["calls_number_length2"] = "";
                     $callNumberRange = false;
                     if (str_contains($parameters["calls_number_length"], "-")) {
                        $callNumberRange = true;
                        $explodedCallNumbers = explode("-", $parameters["calls_number_length"]);
                        $parameters["calls_number_length"] = current($explodedCallNumbers);
                        $parameters["calls_number_length2"] = next($explodedCallNumbers);
                     }
                     $multiplePrefixes = false;
                     if (str_contains($parameters["prefix_pattern"], ",")) {
                        $multiplePrefixes = true;
                        $parameters["prefix_pattern2"] = explode(",", $parameters["prefix_pattern"]);
                     }
                     $defaults = ["001", "002", "003", "004", "00"];
                     $length = 0;
                     $positivePrefix = !$multiplePrefixes ? $parameters["prefix_pattern"] : current($parameters["prefix_pattern2"]);
                     if (!empty($parameters["prefix_pattern"])) {
                        if (strlen($positivePrefix)) {
                           if (!$callNumberRange) {
                              $parameters["calls_number_length"] = $parameters["calls_number_length"] - strlen($positivePrefix);
                           } else {
                              $parameters["calls_number_length"] = $parameters["calls_number_length"] - strlen($positivePrefix);
                              $parameters["calls_number_length2"] = $parameters["calls_number_length2"] - strlen($positivePrefix);
                           }
                        }
                        if (substr($parameters["prefix_pattern"], 0, 1) == "+") {
                           $positivePrefix = '\\' . $parameters["prefix_pattern"];
                        }
                     }
                     // Emergency rules match the exact emergency number(s) only (licence limit).
                     if (intval($qOutboundRule->priority) < 0) {
                         $parameters["calls_number_length"] = "0,0";
                         $callNumberRange = false;
                     }
                     $prefix = '';
                     if (!$callNumberRange) {
                        $prefix = '^' . $positivePrefix . '(\d{' . $parameters["calls_number_length"] . '})$';
                     } else {
                        $prefix = '^' . $positivePrefix . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                     }
                     $prefixes = $prefix;
                     foreach ($defaults as $default) {
                        if (!$callNumberRange) {
                           $prefixes .= '|^' . $default . $parameters["prefix_pattern"] . '(\d{' . $parameters["calls_number_length"] . '})$';
                        } else {
                           $prefixes .= '|^' . $default . $parameters["prefix_pattern"] . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                        }
                     }
                     $extensions = [];
                     $extensions2 = [];
                     $parameters["team_extensions"] = $qOutboundRule->team_extensions;
                     $teamExtensions = is_null($parameters["team_extensions"]) ? [] : explode(",", $parameters["team_extensions"]);
                     foreach ($teamExtensions as $teamExtension) {
                        $vRingGroup = VRingGroups::findFirst(
                           [
                              "conditions" => "domain_uuid = :domain_uuid: AND ring_group_extension = :ring_group_extension:",
                              "bind" =>
                                 [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "ring_group_extension" => $teamExtension
                                 ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]
                        );
                        if ($vRingGroup) {
                           $vRingGroupDestinations = VRingGroupDestinations::find(
                              [
                                 "conditions" => "domain_uuid = :domain_uuid: AND ring_group_uuid = :ring_group_uuid:",
                                 "bind" =>
                                    [
                                       "domain_uuid" => $session["domain_uuid"],
                                       "ring_group_uuid" => $vRingGroup->ring_group_uuid
                                    ],
                                 "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                              ]
                           );
                           foreach ($vRingGroupDestinations as $vRingGroupDestination) {
                              array_push($extensions, strval($vRingGroupDestination->destination_number));
                           }
                        }
                     }
                     $parameters["calls_extensions"] = $qOutboundRule->calls_extensions;
                     $extensions2 = empty($parameters["calls_extensions"]) ? [] : explode(",", $parameters["calls_extensions"]);
                     foreach ($extensions as $extension) {
                        if (!in_array($extension, $extensions2)) {
                           array_push($extensions2, $extension);
                        }
                     }
                     $parameters["extension_range"] = $qOutboundRule->extension_range;
                     $extensionRanges = is_null($parameters["extension_range"]) ? [] : explode(",", $parameters["extension_range"]);
                     foreach ($extensionRanges as $extensionRange) {
                        $minMax = explode("-", $extensionRange);
                        if (count($minMax) > 1) {
                           array_push($extensions2, $this->regexRangeExpression(trim($minMax[0]), trim($minMax[1])));
                        }
                     }
                     $callExtensions = empty($parameters["calls_extensions"]) && empty($parameters["team_extensions"]) && empty($parameters["extension_range"]) ? "" : '<condition field="${effective_caller_id_number}" expression="^' . implode("|", $extensions2) . '$"/>';
                     $action = "";
                     $antiActionCallerId = '';
                     $efftiveCallerIds = '
                        <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                        <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                        <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                     ';
                     $parameters["outbound_caller_id"] = '${outbound_caller_id_number}';
                     if ($qOutboundRule->outbound_caller_id) {
                        $parameters["outbound_caller_id"] = $qOutboundRule->outbound_caller_id;
                        $efftiveCallerIds = '
                           <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                           <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                           <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                        ';
                     } else if ($vGateway->description) {
                        $parameters["outbound_caller_id"] = $vGateway->description;
                        $antiActionCallerId = '
                           <condition field="${outbound_caller_id_number}" expression="^$" break="never">
                                 <action application="log" data="CID missing, setting default to ' . $parameters["outbound_caller_id"] . '"/>
                                 <action application="set" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                           </condition>
                        ';
                        $efftiveCallerIds = '
                           <action application="log" data="INFO effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                           <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                           <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $session["subdomain"] . '"/>
                        ';
                     }
                     $privacy = ",privacy=off";
                     if ($vGateway->privacy_header == 1) {
                        $privacy = ",origination_privacy=screen+hide_name+hide_number,privacy=full";
                     }
                     $action = '<action application="bridge" data="{sip_cid_type=rpid,origination_caller_id_name=${outbound_caller_id_number},origination_caller_id_number=${outbound_caller_id_number}@' . $session["subdomain"] . $privacy . '}sofia/gateway/' . $vGateway->gateway_uuid . '/' . $parameters["prepend"] . '${dest_number:' . intval($parameters["strip"]) . '}"/>';
                     if ($parameters["action"] == "BLOCK") {
                        $action = '<action application="hangup" data="OUTGOING_CALL_BARRED"/>';
                     }
                     $xml = "";
                     $channelLimits = "";
                     if ($vGateway->channels != 0) {
                        $channelLimits = '
                              <action application="set" data="max_calls=' . $vGateway->channels . '" inline="true"/>
                              <action application="limit" data="db ' . $session["subdomain"] . ' ' . $vGateway->gateway_uuid . ' ${max_calls}"/>
                           ';
                     }
                     $codecValues = [
                        "OPUS" => "opus@16000h@40i",
                        "ILBC" => "iLBC@30i",
                        "G7221" => "G7221@32000h, G7221@16000h",
                        "G722" => "G722@16000h",
                        "G729" => "G729",
                        "PCMU" => "PCMU",
                        "PCMA" => "PCMA",
                     ];
                     $codecPrefs = '<action application="export" data="nolocal:'.'absolute_codec_string=PCMA,PCMU,G729"/>';
                     if (!is_null($vGateway->codec_prefs)) {
                        $codecValues2 = [];
                        $codecs = explode(",", $vGateway->codec_prefs);
                        foreach ($codecs as $codec) {
                           $codec = strtoupper($codec);
                           if (array_key_exists($codec, $codecValues)) {
                              array_push($codecValues2, $codecValues[$codec]);
                           }
                        }
                        $codecValues2 = implode(",", $codecValues2);
                        $codecPrefs = '<action application="export" data="nolocal:'.'absolute_codec_string=' . $codecValues2 . '"/>';
                     }
                     $parameters["outbound_name"] = $qOutboundRule->outbound_name;
                     $userExists = '<condition field="${user_exists}" expression="false"/>';
                     if ($qOutboundRule->priority < 0) {
                        $userExists = '';
                        $prefixes = "^" . $parameters["prefix_pattern"] . "(\d{0})$";
                     }
                     if (!$multiplePrefixes) {
                        $vDialplan->dialplan_xml = $xml = '<extension name="' . $parameters["outbound_name"] . '" continue="false" uuid="' . $outbound_uuid . '">
                              ' . $antiActionCallerId . '
                              ' . $userExists . '
                              ' . $callExtensions . '
                              <condition field="destination_number" expression="' . $prefixes . '" break="on-true">
                                    <action application="set" data="dest_number=' . $parameters["prefix_pattern"] . '$1$2$3$4$5$6"/>
                                    <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                                    <action application="export" data="call_direction=outbound" inline="true"/>
                                    ' . $codecPrefs . '
                                    <action application="unset" data="call_timeout"/>
                                    <action application="set" data="hangup_after_bridge=true"/>
                                    ' . $efftiveCallerIds . '
                                    <action application="set" data="inherit_codec=false"/>
                                    <action application="set" data="ignore_display_updates=true"/>
                                    <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                                    <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                                    <action application="set" data="continue_on_fail=true"/>
                                    ' . $channelLimits . $action . '
                                    <action application="playback" data="misc/sorry_your_call.wav"/>
                              </condition>
                           </extension>';
                     } else {
                        $xml = '<extension name="' . $parameters["outbound_name"] . '" continue="false" uuid="' . $outbound_uuid . '">
                              ' . $antiActionCallerId . '
                              ' . $userExists . '
                              ' . $callExtensions . '';
                        foreach ($parameters["prefix_pattern2"] as $prefix_pattern2) {
                           $prefix = '';
                           if (!$callNumberRange) {
                              $prefix = '^' . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . '})$';
                           } else {
                              $prefix = '^' . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                           }
                           $prefixes = $prefix;
                           foreach ($defaults as $default) {
                              if (!$callNumberRange) {
                                 $prefixes .= '|^' . $default . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . '})$';
                              } else {
                                 $prefixes .= '|^' . $default . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                              }
                           }
                           $xml .= '<condition field="destination_number" expression="' . $prefixes . '" break="on-true">
                                       <action application="set" data="dest_number=' . $prefix_pattern2 . '$1$2$3$4$5$6"/><action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                                       <action application="export" data="call_direction=outbound" inline="true"/>
                                       ' . $codecPrefs . '
                                       <action application="unset" data="call_timeout"/>
                                       <action application="set" data="hangup_after_bridge=true"/>
                                       ' . $efftiveCallerIds . '
                                       <action application="set" data="inherit_codec=false"/>
                                       <action application="set" data="ignore_display_updates=true"/>
                                       <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                                       <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                                       <action application="set" data="continue_on_fail=true"/>
                                       ' . $channelLimits . $action . '
                                       <action application="playback" data="misc/sorry_your_call.wav"/>
                                    </condition>';
                        }
                        $xml .= '</extension>';
                        $vDialplan->dialplan_xml = $xml;
                     }
                     $vDialplan->dialplan_name = $parameters["outbound_name"];
                     $vDialplan->dialplan_continue = "false";
                     $vDialplan->dialplan_enabled = "false";
                     $vDialplan->update_user = $session["user_uuid"];
                     $vDialplan->update_date = date("Y-m-d H:i:s");
                     if (!$vDialplan->update()) {
                        $this->db->rollback();
                        $messages = $vDialplan->getMessages();
                        $message = "";
                        foreach ($messages as $error) {
                           $message .= $error . " ";
                        }
                        $response = json_encode(
                           [
                              "result" => "error",
                              "message" => trim($message) . " (Code AS1002)",
                              "content" => [],
                           ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                     }
                     $vDialplans = VDialplans::find(
                        [
                           "conditions" => "domain_uuid = :domain_uuid: AND dialplan_enabled = :dialplan_enabled: AND dialplan_description = :dialplan_description:",
                           "bind" =>
                              [
                                 "domain_uuid" => $session["domain_uuid"],
                                 "dialplan_enabled" => "false",
                                 "dialplan_description" => "outbound-rule"
                              ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           "order" => "dialplan_order ASC"
                        ]
                     );
                     foreach ($vDialplans as $vDialplan) {
                        $xml .= "\n" . $vDialplan->dialplan_xml;
                     }
                     $vDialplan2 = VDialplans::findFirst(
                        [
                           "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name:",
                           "bind" =>
                              [
                                 "domain_uuid" => $session["domain_uuid"],
                                 "dialplan_name" => "outbound-rules"
                              ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                     );
                     if ($vDialplan2) {
                        $vDialplan2->dialplan_xml = $xml;
                        if (!$vDialplan2->update()) {
                           $this->db->rollback();
                           $messages = $vDialplan2->getMessages();
                           $message = "";
                           foreach ($messages as $error) {
                              $message .= $error . " ";
                           }
                           $response = json_encode(
                              [
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1005)",
                                 "content" => [],
                              ]
                           );
                           $this->response->setContent($response);
                           return $this->response;
                        }
                     } else {
                        $outbound_uuid2 = $security->uuid();
                        $vDialplan2 = new VDialplans();
                        $vDialplan2->domain_uuid = $session["domain_uuid"];
                        $vDialplan2->dialplan_uuid = $outbound_uuid2;
                        $vDialplan2->app_uuid = $security->uuid();
                        $vDialplan2->hostname = null;
                        $vDialplan2->dialplan_xml = $xml;
                        $vDialplan2->dialplan_name = "outbound-rules";
                        $vDialplan2->dialplan_order = 85;
                        $vDialplan2->dialplan_context = $session["subdomain"];
                        $vDialplan2->dialplan_continue = "false";
                        $vDialplan2->dialplan_enabled = "true";
                        $vDialplan2->insert_user = $session["user_uuid"];
                        $vDialplan2->insert_date = date("Y-m-d H:i:s");
                        if (!$vDialplan2->create()) {
                           $this->db->rollback();
                           $messages = $vDialplan2->getMessages();
                           $message = "";
                           foreach ($messages as $error) {
                              $message .= $error . " ";
                           }
                           $response = json_encode(
                              [
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1002)",
                                 "content" => [],
                              ]
                           );
                           $this->response->setContent($response);
                           return $this->response;
                        }
                     }
                  }
               }
               if (file_exists("/var/cache/ringq/dialplan." . $session["subdomain"])) {
                  unlink("/var/cache/ringq/dialplan." . $session["subdomain"]);
               }
               if (file_exists("/var/cache/ringq/configuration.sofia.conf." . gethostname())) {
                  unlink("/var/cache/ringq/configuration.sofia.conf." . gethostname());
               }
               $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                  "persistent" => false,
               ]));
               $client->initialize();
               // Detect if port (proxy) or transport changed — needs full sofia profile restart
               $proxyChanged = ($originalProxy !== $vGateway->proxy);
               $transportChanged = ($originalTransport !== $vGateway->register_transport);
               $needsRestart = $proxyChanged || $transportChanged;
               $client->emit("gatewaystart", ["uuid" => $parameters["gateway_uuid"], "restart" => $needsRestart]);
               $this->db->commit();
               $response = json_encode(
                  [
                     "result" => "success",
                     "message" => "Successfully, updated new gateway. (Code AG1007)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
         }
      }
   }

   public function gatewayAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $session = [];
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         $parameters["fqdn"] = intval($this->request->getPost("fqdn", "string"));
         $parameters["gateway"] = $this->request->getPost("gateway", "string");
         $parameters["gateway_uuid"] = $this->request->getPost("gateway_uuid", "string");
         $parameters["outbound_caller_id"] = $this->request->getPost("outbound_caller_id", "string");
         $parameters["codec"] = $this->request->getPost("codec", "string");
         $parameters["max_calls"] = intval($this->request->getPost("max_calls", "int"));
         $parameters["register_transport"] = $this->request->getPost("register_transport", "string");
         $parameters["expire_seconds"] = intval($this->request->getPost("expire_seconds", "int"));
         $parameters["retry_seconds"] = intval($this->request->getPost("retry_seconds", "int"));
         $parameters["dids"] = $this->request->getPost("dids", "string");

         if (empty($parameters["type"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);

            $vDomains = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                  "domain_name" => $parameters["fqdn"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);

            if ($vDomains) {
               $session = $vDomains->toArray();
               $session['domain_uuid'] = $vDomains->domain_uuid;
               $session['subdomain'] = $vDomains->domain_name;
            }
         }

         if ($parameters["type"] == 3) {
            $vExtension = VExtensions::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "extension_uuid" => $parameters["gateway_uuid"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );

            if (!$vExtension) {
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => "Sorry, gateway does not exists. (Code AG1008)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $this->db->begin();
            if (!$vExtension->delete()) {
               $this->db->rollback();
               $messages = $vExtension->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1009)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $vExtensionUser = VExtensionUsers::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "extension_uuid" => $vExtension->extension_uuid,
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if (!$vExtensionUser->delete()) {
               $this->db->rollback();
               $messages = $vExtensionUser->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1009)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $vUser = VUsers::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "user_uuid" => $vExtensionUser->user_uuid,
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if (!$vUser->delete()) {
               $this->db->rollback();
               $messages = $vUser->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1009)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $vPinNumber = VPinNumbers::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND (description = :description: OR description = :number_alias:)",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "description" => $vExtension->extension,
                        "number_alias" => $vExtension->number_alias
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if (!$vPinNumber->delete()) {
               $this->db->rollback();
               $messages = $vPinNumber->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1009)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            if (file_exists("/var/cache/ringq/directory." . $vExtension->extension . "@" . $session["subdomain"])) {
               unlink("/var/cache/ringq/directory." . $vExtension->extension . "@" . $session["subdomain"]);
            }
            $this->db->commit();
            $response = json_encode(
               [
                  "result" => "success",
                  "message" => "Successfully, deleted gateway.",
                  "content" => [],
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }

         if ($parameters["type"] == 4) {
            $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
               "persistent" => false,
            ]));
            $client->initialize();
            $client->emit("gatewaystatus", ["subdomain" => $session["subdomain"]]);
            $vGateways = VGateways::find(
               [
                  "conditions" => "domain_uuid = :domain_uuid:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]
            );
            $vExtensions = VExtensions::find([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],

            ]);
            $index2 = 0;
            $content2 = array();
            foreach ($vExtensions as $vExtension) {
               if (is_numeric($vExtension->number_alias) && $vExtension->number_alias > 99999) {
                  $content2[$index2]["uuid"] = $vExtension->extension_uuid;
                  $content2[$index2]["extension"] = $vExtension->number_alias;
                  $content2[$index2]["username"] = $vExtension->extension;
                  $content2[$index2]["password"] = $vExtension->password;
                  $content2[$index2]["gateway"] = $vExtension->effective_caller_id_name;
                  $content2[$index2]["codec_prefs"] = is_null($vExtension->absolute_codec_string) ? "" : explode(",", $vExtension->absolute_codec_string);
                  $content2[$index2]["expire_seconds"] = $vExtension->sip_force_expires;
                  $content2[$index2]["outbound_caller_id"] = $vExtension->effective_caller_id_number;
                  $content2[$index2]["max_calls"] = $vExtension->limit_max;
                  $content2[$index2]["dids"] = $vExtension->forward_all_destination;
                  $index2++;
               }
            }
            $status = array();
            $content3 = $this->redis->get($session["subdomain"] . "-gateways");
            if ($content3) {
               $content3 = trim($content3);
               $status = explode(" ", $content3);
            }
            $response = json_encode(
               [
                  "result" => "success",
                  "data" => $content2,
                  "status" => $status,
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }

         if ($parameters["type"] == 1) {
            $parameters["extension"] = 99999;
            $gateway2Form = new Gateway2Form();
            if (!$gateway2Form->isValid($parameters)) {
               $messages = $gateway2Form->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code AG1000)",
                  "content" => [],
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $this->db->begin();
            $security = new \Phalcon\Security\Random();
            $qCompany = QCompanies::findFirst([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            $vExtensions = VExtensions::find([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            $index2 = 0;
            $extensionAlias = array();
            foreach ($vExtensions as $vExtension) {
               if (is_numeric($vExtension->number_alias)) {
                  if ($vExtension->number_alias >= 100000 && $vExtension->number_alias < 200000) {
                     array_push($extensionAlias, intval($vExtension->number_alias));
                  }
               }
            }
            if (!empty($extensionAlias)) {
               $parameters["extension"] = max($extensionAlias) + 1;
            } else {
               $parameters["extension"] = 100000;
            }
            $vDomains = VDomains::findFirst([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
               ],
            ]);
            $user_uuid = $security->uuid();
            $domain_uuid = $session["domain_uuid"];
            $company_email = "";
            $voicemail_uuid = $security->uuid();
            $company = strtolower(str_replace(" ", "", preg_replace("/[^a-zA-Z0-9_ -]/s", " ", $qCompany->company_name)));
            $fqdn = $vDomains->domain_name;
            $extension = intval($parameters["extension"]);
            $lastname = "";
            $firstname = "";
            $username = $security->hex(10);
            $salt = $this->security->hash($security->base64(16));
            $password = $this->security->hash($security->base64(16));
            $password2 = $security->base64(16);
            $extension_password = $password2; // md5($salt . $password2);
            try {
               $isNoAlias1000 = VExtensions::findFirst([
                  "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND number_alias IS NULL",
                  "bind" => [
                     "domain_uuid" => $domain_uuid,
                     "extension" => '1000',
                  ],
               ]);
               if ($isNoAlias1000) {
                  $isNoAlias1000->number_alias = $username;
                  if (!$isNoAlias1000->save()) {
                     $this->db->rollback();
                     $messages = $isNoAlias1000->getMessages();
                     $message = "";
                     foreach ($messages as $error) {
                        $message .= $error . " ";
                     }
                     $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AE1001)",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
            } catch (\Throwable $e) {
               error_log(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            }
            $vUser = new VUsers();
            $vUser->username = $username;
            $vUser->user_uuid = $user_uuid;
            $vUser->domain_uuid = $domain_uuid;
            $vUser->user_email = $company_email;
            $vUser->password = $password;
            $vUser->user_status = "Logged Out";
            $vUser->user_enabled = "true";
            $vUser->api_key = $security->base64(32);
            $vUser->image = null;
            // $vUser->first_name = $firstname;
            // $vUser->last_name = $lastname;
            $vUser->insert_user = $user_uuid;
            $vUser->insert_date = date("Y-m-d H:i:s");
            if (!$vUser->save()) {
               $this->db->rollback();
               $messages = $vUser->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code AE1002)",
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $vExtension = new VExtensions();
            $vExtension->extension_uuid = $extension_uuid = $security->uuid();
            $vExtension->domain_uuid = $domain_uuid;
            $vExtension->extension = $username;
            $vExtension->number_alias = $extension;
            $vExtension->password = $extension_password;
            $vExtension->enabled = "true";
            $vExtension->extension_type = "default";
            $vExtension->accountcode = $fqdn;
            $vExtension->directory_visible = "true";
            $vExtension->directory_exten_visible = "true";
            $vExtension->limit_destination = "!USER_BUSY";
            $vExtension->limit_max = $parameters["max_calls"] == 0 ? 5 : $parameters["max_calls"]; // <!-- Should be based on purchased package
            $codecValues = [
               "OPUS" => "opus@16000h@40i",
               "ILBC" => "iLBC@30i",
               "G7221" => "G7221@32000h, G7221@16000h",
               "G722" => "G722@16000h",
               "G729" => "G729",
               "PCMU" => "PCMU",
               "PCMA" => "PCMA",
            ];
            $codecValues2 = [];
            if (!is_null($parameters["codec"])) {
               $codecs = explode(",", $parameters["codec"]);
               foreach ($codecs as $codec) {
                  $codec = strtoupper($codec);
                  if (array_key_exists($codec, $codecValues)) {
                     array_push($codecValues2, $codecValues[$codec]);
                  }
               }
               $codecValues2 = implode(",", $codecValues2);
            }
            $qDid2 = QDids::findFirst([
               "conditions" => "domain_uuid = :domain_uuid: AND outbound = :outbound: ",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
                  "outbound" => 1,
               ],
               "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            $vExtension->absolute_codec_string = !empty($codecValues2) ? $codecValues2 : null;
            $vExtension->sip_force_expires = $parameters["expire_seconds"];
            $vExtension->effective_caller_id_name = $parameters["gateway"];
            $vExtension->effective_caller_id_number = $parameters["outbound_caller_id"];
            $vExtension->do_not_disturb = "false";
            $vExtension->forward_all_enabled = !empty($parameters["dids"]) ? "true" : null;
            $vExtension->forward_all_enabled2 = !empty($parameters["dids"]) ? "true" : null;
            $vExtension->forward_all_destination = !empty($parameters["dids"]) ? $parameters["dids"] : null;
            $vExtension->forward_all_destination2 = !empty($parameters["dids"]) ? $parameters["dids"] : null;
            $vExtension->forward_busy_enabled = "false";
            $vExtension->forward_busy_enabled2 = "false";
            $vExtension->forward_no_answer_enabled = "true";
            $vExtension->forward_no_answer_enabled2 = "true";
            $vExtension->forward_user_not_registered_enabled = "false";
            $vExtension->forward_user_not_registered_enabled2 = "false";
            $vExtension->forward_no_answer_destination = "*99" . $extension;
            $vExtension->forward_no_answer_destination2 = "*99" . $extension;
            $vExtension->voicemail_enabled = "enabled";
            $vExtension->voicemail_file = "attach";
            $vExtension->call_screen_enabled = "false";
            $vExtension->user_context = $fqdn;
            $vExtension->call_timeout = 30;
            $vExtension->call_timeout2 = 30;
            $vExtension->insert_user = $user_uuid;
            $vExtension->insert_date = date("Y-m-d H:i:s");
            if (!$vExtension->save()) {
               $this->db->rollback();
               $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code AE1003)",
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $vExtensionUser = new VExtensionUsers();
            $vExtensionUser->extension_user_uuid = $security->uuid();
            $vExtensionUser->domain_uuid = $domain_uuid;
            $vExtensionUser->user_uuid = $user_uuid;
            $vExtensionUser->extension_uuid = $extension_uuid;
            if (!$vExtensionUser->save()) {
               $this->db->rollback();
               $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code AE1004)",
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $vPinNumber = new VPinNumbers();
            $vPinNumber->domain_uuid = $session["domain_uuid"];
            $vPinNumber->pin_number_uuid = $security->uuid();
            $vPinNumber->pin_number = strval(rand(1000, 9999));
            $vPinNumber->accountcode = $session["subdomain"];
            $vPinNumber->enabled = "true";
            $vPinNumber->description = strval($extension);
            $vPinNumber->insert_user = $session["user_uuid"];
            $vPinNumber->insert_date = date("Y-m-d H:i:s");
            if (!$vPinNumber->create()) {
               $this->db->rollback();
               $messages = $vPinNumber->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code AS1008)",
                  "content" => [],
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $this->db->commit();
            if (file_exists("/var/cache/ringq/directory." . $vExtension->number_alias . "@" . $session["subdomain"])) {
               unlink("/var/cache/ringq/directory." . $vExtension->number_alias . "@" . $session["subdomain"]);
            }
            $response = json_encode(
               [
                  "result" => "success",
                  "message" => "Successfully, created gateway.",
                  "content" => ""
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }

         if ($parameters["type"] == 2) {
            $vExtension = VExtensions::findFirst(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "extension_uuid" => $parameters["gateway_uuid"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if (!$vExtension) {
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => "Sorry, gateway does not exists. (Code AG1008)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $vExtension->limit_max = $parameters["max_calls"] == 0 ? 5 : $parameters["max_calls"]; // <!-- Should be based on purchased package
            $codecValues = [
               "OPUS" => "opus@16000h@40i",
               "ILBC" => "iLBC@30i",
               "G7221" => "G7221@32000h, G7221@16000h",
               "G722" => "G722@16000h",
               "G729" => "G729",
               "PCMU" => "PCMU",
               "PCMA" => "PCMA",
            ];
            $codecValues2 = [];
            if (!is_null($parameters["codec"])) {
               $codecs = explode(",", $parameters["codec"]);
               foreach ($codecs as $codec) {
                  $codec = strtoupper($codec);
                  if (array_key_exists($codec, $codecValues)) {
                     array_push($codecValues2, $codecValues[$codec]);
                  }
               }
               $codecValues2 = implode(",", $codecValues2);
            }
            $vExtension->forward_all_enabled = !empty($parameters["dids"]) ? "true" : null;
            $vExtension->forward_all_enabled2 = !empty($parameters["dids"]) ? "true" : null;
            $vExtension->forward_all_destination = !empty($parameters["dids"]) ? $parameters["dids"] : null;
            $vExtension->forward_all_destination2 = !empty($parameters["dids"]) ? $parameters["dids"] : null;
            $vExtension->absolute_codec_string = !empty($codecValues2) ? $codecValues2 : null;
            $vExtension->sip_force_expires = $parameters["expire_seconds"];
            $vExtension->effective_caller_id_name = $parameters["gateway"];
            $vExtension->effective_caller_id_number = $parameters["outbound_caller_id"];
            if (!$vExtension->update()) {
               $messages = $vExtension->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode(
                  [
                     "result" => "error",
                     "message" => trim($message) . " (Code AG1009)",
                     "content" => [],
                  ]
               );
               $this->response->setContent($response);
               return $this->response;
            }
            $action = strtoupper($vExtension->effective_caller_id_name . " (" . $vExtension->number_alias . ")");
            $qOutboundRules = QOutboundRules::find(
               [
                  "conditions" => "domain_uuid = :domain_uuid: AND action = :action:",
                  "bind" =>
                     [
                        "domain_uuid" => $session["domain_uuid"],
                        "action" => $action
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            foreach ($qOutboundRules as $qOutboundRule) {
               $vDialplan = VDialplans::findFirst(
                  [
                     "conditions" => "domain_uuid = :domain_uuid: AND dialplan_uuid = :dialplan_uuid:",
                     "bind" =>
                        [
                           "domain_uuid" => $session["domain_uuid"],
                           "dialplan_uuid" => $qOutboundRule->outbound_uuid
                        ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]
               );
               if ($vDialplan) {
                  $xml = "";
                  $parameters["prepend"] = $qOutboundRule->prepend;
                  $parameters["strip"] = $qOutboundRule->strip;
                  $parameters["action"] = $qOutboundRule->action;
                  $outbound_uuid = $qOutboundRule->outbound_uuid;
                  $parameters["prefix_pattern"] = $qOutboundRule->prefix_pattern;
                  $parameters["prefix_pattern2"] = [];
                  // Emergency rules (priority < 0) never match on number length.
                  $parameters["calls_number_length"] = intval($qOutboundRule->priority) < 0 ? "" : $qOutboundRule->calls_number_length;
                  $parameters["calls_number_length2"] = "";
                  $callNumberRange = false;
                  if (str_contains($parameters["calls_number_length"], "-")) {
                     $callNumberRange = true;
                     $explodedCallNumbers = explode("-", $parameters["calls_number_length"]);
                     $parameters["calls_number_length"] = current($explodedCallNumbers);
                     $parameters["calls_number_length2"] = next($explodedCallNumbers);
                  }
                  $multiplePrefixes = false;
                  if (str_contains($parameters["prefix_pattern"], ",")) {
                     $multiplePrefixes = true;
                     $parameters["prefix_pattern2"] = explode(",", $parameters["prefix_pattern"]);
                  }
                  $defaults = ["001", "002", "003", "004", "00"];
                  $length = 0;
                  $positivePrefix = !$multiplePrefixes ? $parameters["prefix_pattern"] : current($parameters["prefix_pattern2"]);
                  if (!empty($parameters["prefix_pattern"])) {
                     if (strlen($positivePrefix)) {
                        if (!$callNumberRange) {
                           $parameters["calls_number_length"] = $parameters["calls_number_length"] - strlen($positivePrefix);
                        } else {
                           $parameters["calls_number_length"] = $parameters["calls_number_length"] - strlen($positivePrefix);
                           $parameters["calls_number_length2"] = $parameters["calls_number_length2"] - strlen($positivePrefix);
                        }
                     }
                     if (substr($parameters["prefix_pattern"], 0, 1) == "+") {
                        $positivePrefix = '\\' . $parameters["prefix_pattern"];
                     }
                  }
                  // Emergency rules match the exact emergency number(s) only (licence limit).
                  if (intval($qOutboundRule->priority) < 0) {
                      $parameters["calls_number_length"] = "0,0";
                      $callNumberRange = false;
                  }
                  $prefix = '';
                  if (!$callNumberRange) {
                     $prefix = '^' . $positivePrefix . '(\d{' . $parameters["calls_number_length"] . '})$';
                  } else {
                     $prefix = '^' . $positivePrefix . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                  }
                  $prefixes = $prefix;
                  foreach ($defaults as $default) {
                     if (!$callNumberRange) {
                        $prefixes .= '|^' . $default . $parameters["prefix_pattern"] . '(\d{' . $parameters["calls_number_length"] . '})$';
                     } else {
                        $prefixes .= '|^' . $default . $parameters["prefix_pattern"] . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                     }
                  }
                  $extensions = [];
                  $extensions2 = [];
                  $parameters["team_extensions"] = $qOutboundRule->team_extensions;
                  $teamExtensions = is_null($parameters["team_extensions"]) ? [] : explode(",", $parameters["team_extensions"]);
                  foreach ($teamExtensions as $teamExtension) {
                     $vRingGroup = VRingGroups::findFirst(
                        [
                           "conditions" => "domain_uuid = :domain_uuid: AND ring_group_extension = :ring_group_extension:",
                           "bind" =>
                              [
                                 "domain_uuid" => $session["domain_uuid"],
                                 "ring_group_extension" => $teamExtension
                              ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                     );
                     if ($vRingGroup) {
                        $vRingGroupDestinations = VRingGroupDestinations::find(
                           [
                              "conditions" => "domain_uuid = :domain_uuid: AND ring_group_uuid = :ring_group_uuid:",
                              "bind" =>
                                 [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "ring_group_uuid" => $vRingGroup->ring_group_uuid
                                 ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]
                        );
                        foreach ($vRingGroupDestinations as $vRingGroupDestination) {
                           array_push($extensions, strval($vRingGroupDestination->destination_number));
                        }
                     }
                  }
                  $parameters["calls_extensions"] = $qOutboundRule->calls_extensions;
                  $extensions2 = empty($parameters["calls_extensions"]) ? [] : explode(",", $parameters["calls_extensions"]);
                  foreach ($extensions as $extension) {
                     if (!in_array($extension, $extensions2)) {
                        array_push($extensions2, $extension);
                     }
                  }
                  $parameters["extension_range"] = $qOutboundRule->extension_range;
                  $extensionRanges = is_null($parameters["extension_range"]) ? [] : explode(",", $parameters["extension_range"]);
                  foreach ($extensionRanges as $extensionRange) {
                     $minMax = explode("-", $extensionRange);
                     if (count($minMax) > 1) {
                        array_push($extensions2, $this->regexRangeExpression(trim($minMax[0]), trim($minMax[1])));
                     }
                  }
                  $callExtensions = empty($parameters["calls_extensions"]) && empty($parameters["team_extensions"]) && empty($parameters["extension_range"]) ? "" : '<condition field="${caller_id_number}" expression="^' . implode("|", $extensions2) . '$"/>';
                  $action = "";
                  $antiActionCallerId = '';
                  $parameters["outbound_caller_id"] = '${effective_caller_id_number}';
                  $efftiveCallerIds = '
                        <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                        <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                        <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                     ';
                  if ($qOutboundRule->outbound_caller_id) {
                     $parameters["outbound_caller_id"] = $qOutboundRule->outbound_caller_id;
                     $efftiveCallerIds = '
                           <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                           <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                           <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                        ';
                  }
                  if ($parameters["action"] == "BLOCK") {
                     $action = '<action application="hangup" data="OUTGOING_CALL_BARRED"/>';
                  }
                  $xml = "";
                  $channelLimits = "";
                  $codecValues = [
                     "OPUS" => "opus@16000h@40i",
                     "ILBC" => "iLBC@30i",
                     "G7221" => "G7221@32000h, G7221@16000h",
                     "G722" => "G722@16000h",
                     "G729" => "G729",
                     "PCMU" => "PCMU",
                     "PCMA" => "PCMA",
                  ];
                  $codecPrefs = '<action application="export" data="nolocal:'.'absolute_codec_string=PCMA,PCMU,G729"/>';
                  if (!is_null($vExtension->absolute_codec_string)) {
                     $codecValues2 = [];
                     $codecs = explode(",", $vExtension->absolute_codec_string);
                     foreach ($codecs as $codec) {
                        $codec = strtoupper($codec);
                        if (array_key_exists($codec, $codecValues)) {
                           array_push($codecValues2, $codecValues[$codec]);
                        }
                     }
                     $codecValues2 = implode(",", $codecValues2);
                     $codecPrefs = '<action application="export" data="nolocal:'.'absolute_codec_string=' . $codecValues2 . '"/>';
                  }
                  $outbound_uuid = $vExtension->extension_uuid;
                  $parameters["outbound_name"] = $vExtension->effective_caller_id_name;
                  if (!is_null($parameters["outbound_caller_id"]) && !empty($parameters["outbound_caller_id"])) {
                     $parameters["outbound_caller_id"] = $parameters["outbound_caller_id"];
                     $efftiveCallerIds = '
                        <action application="export" data="effective_outbound_caller_id_name=' . $parameters["outbound_caller_id"] . '"/>
                        <action application="export" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                        <action application="export" data="sip_from_uri=' . $parameters["outbound_caller_id"] . '@' . $session["subdomain"] . '"/>
                     ';
                  } else if (!empty($vExtension->effective_caller_id_number)) {
                     $parameters["outbound_caller_id"] = $vExtension->effective_caller_id_number;
                     $antiActionCallerId = '
                        <condition field="${outbound_caller_id_number}" expression="^$" break="never">
                           <action application="log" data="CID missing, setting default to ' . $parameters["outbound_caller_id"] . '"/>
                           <action application="set" data="outbound_caller_id_number=' . $parameters["outbound_caller_id"] . '"/>
                        </condition>
                     ';
                     $efftiveCallerIds = '
                        <action application="log" data="INFO effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                        <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                        <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $session["subdomain"] . '"/>
                     ';
                  }
                  $channelLimits = '
                        <action application="set" data="max_calls=' . $vExtension->limit_max . '" inline="true"/>
                        <action application="limit" data="db ' . $session["subdomain"] . ' ' . $vExtension->effective_caller_id_number . ' ${max_calls}"/>
                  ';
                  if ($vExtension->forward_all_enabled) {
                     $action = '<action application="transfer" data="' . $vExtension->forward_all_destination . ' XML ' . $session["subdomain"] . '"/>';
                  } else {
                     $action = '<action application="transfer" data="' . $vExtension->number_alias . ' XML ' . $session["subdomain"] . '"/>';
                  }
                  $parameters["outbound_name"] = $qOutboundRule->outbound_name;
                  $userExists = '<condition field="${user_exists}" expression="false"/>';
                  if ($qOutboundRule->priority < 0) {
                     $userExists = '';
                     $prefixes = "^" . $parameters["prefix_pattern"] . "(\d{0})$";
                  }
                  if (!$multiplePrefixes) {
                     $vDialplan->dialplan_xml = $xml = '<extension name="' . $parameters["outbound_name"] . '" continue="false" uuid="' . $outbound_uuid . '">
                           ' . $antiActionCallerId . '
                           ' . $userExists . '
                           ' . $callExtensions . '
                           <condition field="destination_number" expression="' . $prefixes . '" break="on-true">
                              <action application="set" data="dest_number=' . $parameters["prefix_pattern"] . '$1$2$3$4$5$6"/>
                              <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                              <action application="export" data="call_direction=outbound" inline="true"/>
                              ' . $codecPrefs . '
                              <action application="unset" data="call_timeout"/>
                              <action application="set" data="hangup_after_bridge=true"/>
                              ' . $efftiveCallerIds . '
                              <action application="set" data="inherit_codec=false"/>
                              <action application="set" data="ignore_display_updates=true"/>
                              <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                              <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                              <action application="set" data="continue_on_fail=true"/>
                              ' . $channelLimits . $action . '
                              <action application="playback" data="misc/sorry_your_call.wav"/>
                           </condition>
                        </extension>';
                  } else {
                     $xml = '<extension name="' . $parameters["outbound_name"] . '" continue="false" uuid="' . $outbound_uuid . '">
                           ' . $antiActionCallerId . '
                           ' . $userExists . '
                           ' . $callExtensions . '';
                     foreach ($parameters["prefix_pattern2"] as $prefix_pattern2) {
                        $prefix = '';
                        if (!$callNumberRange) {
                           $prefix = '^' . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . '})$';
                        } else {
                           $prefix = '^' . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                        }
                        $prefixes = $prefix;
                        foreach ($defaults as $default) {
                           if (!$callNumberRange) {
                              $prefixes .= '|^' . $default . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . '})$';
                           } else {
                              $prefixes .= '|^' . $default . $prefix_pattern2 . '(\d{' . $parameters["calls_number_length"] . ',' . $parameters["calls_number_length2"] . '})$';
                           }
                        }
                        $xml .= '<condition field="destination_number" expression="' . $prefixes . '" break="on-true">
                                    <action application="set" data="dest_number=' . $prefix_pattern2 . '$1$2$3$4$5$6"/><action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                                    <action application="export" data="call_direction=outbound" inline="true"/>
                                    ' . $codecPrefs . '
                                    <action application="unset" data="call_timeout"/>
                                    <action application="set" data="hangup_after_bridge=true"/>
                                    ' . $efftiveCallerIds . '
                                    <action application="set" data="inherit_codec=false"/>
                                    <action application="set" data="ignore_display_updates=true"/>
                                    <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                                    <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                                    <action application="set" data="continue_on_fail=true"/>
                                    ' . $channelLimits . $action . '
                                    <action application="playback" data="misc/sorry_your_call.wav"/>
                              </condition>';
                     }
                     $xml .= '</extension>';
                     $vDialplan->dialplan_xml = $xml;
                  }
                  $vDialplan->dialplan_name = $parameters["outbound_name"];
                  $vDialplan->dialplan_continue = "false";
                  $vDialplan->dialplan_enabled = "false";
                  $vDialplan->update_user = $session["user_uuid"];
                  $vDialplan->update_date = date("Y-m-d H:i:s");
                  if (!$vDialplan->update()) {
                     $this->db->rollback();
                     $messages = $vDialplan->getMessages();
                     $message = "";
                     foreach ($messages as $error) {
                        $message .= $error . " ";
                     }
                     $response = json_encode(
                        [
                           "result" => "error",
                           "message" => trim($message) . " (Code AS1002)",
                           "content" => [],
                        ]
                     );
                     $this->response->setContent($response);
                     return $this->response;
                  }
                  $vDialplans = VDialplans::find(
                     [
                        "conditions" => "domain_uuid = :domain_uuid: AND dialplan_enabled = :dialplan_enabled: AND dialplan_description = :dialplan_description:",
                        "bind" =>
                           [
                              "domain_uuid" => $session["domain_uuid"],
                              "dialplan_enabled" => "false",
                              "dialplan_description" => "outbound-rule"
                           ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        "order" => "dialplan_order ASC"
                     ]
                  );
                  foreach ($vDialplans as $vDialplan) {
                     $xml .= "\n" . $vDialplan->dialplan_xml;
                  }
                  $vDialplan2 = VDialplans::findFirst(
                     [
                        "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name:",
                        "bind" =>
                           [
                              "domain_uuid" => $session["domain_uuid"],
                              "dialplan_name" => "outbound-rules"
                           ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]
                  );
                  if ($vDialplan2) {
                     $vDialplan2->dialplan_xml = $xml;
                     if (!$vDialplan2->update()) {
                        $this->db->rollback();
                        $messages = $vDialplan2->getMessages();
                        $message = "";
                        foreach ($messages as $error) {
                           $message .= $error . " ";
                        }
                        $response = json_encode(
                           [
                              "result" => "error",
                              "message" => trim($message) . " (Code AS1005)",
                              "content" => [],
                           ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                     }
                  } else {
                     $outbound_uuid2 = $security->uuid();
                     $vDialplan2 = new VDialplans();
                     $vDialplan2->domain_uuid = $session["domain_uuid"];
                     $vDialplan2->dialplan_uuid = $outbound_uuid2;
                     $vDialplan2->app_uuid = $security->uuid();
                     $vDialplan2->hostname = null;
                     $vDialplan2->dialplan_xml = $xml;
                     $vDialplan2->dialplan_name = "outbound-rules";
                     $vDialplan2->dialplan_order = 85;
                     $vDialplan2->dialplan_context = $session["subdomain"];
                     $vDialplan2->dialplan_continue = "false";
                     $vDialplan2->dialplan_enabled = "true";
                     $vDialplan2->insert_user = $session["user_uuid"];
                     $vDialplan2->insert_date = date("Y-m-d H:i:s");
                     if (!$vDialplan2->create()) {
                        $this->db->rollback();
                        $messages = $vDialplan2->getMessages();
                        $message = "";
                        foreach ($messages as $error) {
                           $message .= $error . " ";
                        }
                        $response = json_encode(
                           [
                              "result" => "error",
                              "message" => trim($message) . " (Code AS1002)",
                              "content" => [],
                           ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                     }
                  }
               }
            }

            if (file_exists("/var/cache/ringq/dialplan." . $session["subdomain"])) {
               unlink("/var/cache/ringq/dialplan." . $session["subdomain"]);
            }
            if (file_exists("/var/cache/ringq/directory." . $vExtension->number_alias . "@" . $session["subdomain"])) {
               unlink("/var/cache/ringq/directory." . $vExtension->number_alias . "@" . $session["subdomain"]);
            }
            $response = json_encode(
               [
                  "result" => "success",
                  "message" => "Successfully, updated gateway.",
                  "content" => ""
               ]
            );
            $this->response->setContent($response);
            return $this->response;
         }
      }
   }

   public function didAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if ($this->request->isGet()) {
         return $this->response->setStatusCode(403)
            ->setJsonContent([
               "result" => "error",
               "message" => "Sorry, unathorized request made (Code AD1000)",
            ]);
      }
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         $parameters["fqdn"] = intval($this->request->getPost("fqdn", "string"));
         $parameters["did_number"] = $this->request->getPost("did_number", "string");
         $parameters["sip_trunk_name"] = $this->request->getPost("sip_trunk_name", "string");

         if (empty($parameters["did_number"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);

            $vDomains = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                  "domain_name" => $parameters["fqdn"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);

            if ($vDomains) {
               $session = $vDomains->toArray();
               $session['domain_uuid'] = $vDomains->domain_uuid;
               $session['subdomain'] = $vDomains->domain_name;
               $session['user_uuid'] = $vDomains->insert_user;
            }
         }

         if (str_contains($parameters["did_number"], "+")) {
            $parameters["did_number"] = "+" . intval($parameters["did_number"]);
         } else {
            $parameters["did_number"] = intval($parameters["did_number"]);
         }
         if ($parameters["type"] == 1) {
            $security = new \Phalcon\Security\Random();
            $did_uuid = $security->uuid();
            $checkDid = QDids::findFirst([
               "conditions" => "domain_uuid = :domain_uuid: AND did_number = :did_number:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
                  "did_number" => $parameters["did_number"]

               ],
               "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if ($checkDid) {
               return $this->response->setStatusCode(400)
                  ->setJsonContent([
                     "result" => "error",
                     "message" => trim($message) . " (Code AD1011)",
                     "content" => [],
                  ]);
            }
            $qDid = new QDids();
            $qDid->did_uuid = $did_uuid;
            $qDid->domain_uuid = $session["domain_uuid"];
            $qDid->did_number = $parameters["did_number"];
            $qDid->sip_trunk_name = $parameters["sip_trunk_name"];
            $qDid->insert_user = $session["user_uuid"];
            $qDid->insert_date = date("Y-m-d H:i:s");
            if (!$qDid->create()) {
               $this->db->rollback();
               $messages = $qDid->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               return $this->response->setStatusCode(400)
                  ->setJsonContent([
                     "result" => "error",
                     "message" => trim($message) . " (Code AD1011)",
                     "content" => [],
                  ]);
            }
            return $this->response->setStatusCode(200)
               ->setJsonContent([
                  "result" => "success",
                  "message" => "Successfully, added new DID.",
                  "content" => [
                     "did" => $parameters["did_number"]
                  ],
               ]);
         }
         if ($parameters["type"] == 2) {
            $qDid = QDids::findFirst([
               "conditions" => "domain_uuid = :domain_uuid: AND did_number = :did_number: ",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"],
                  "did_number" => $parameters["did_number"],
               ],
               "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);

            if (!$qDid) {
               return $this->response->setStatusCode(400)
                  ->setJsonContent([
                     "result" => "error",
                     "message" => "Sorry, DID does not exists. (Code AD1012)",
                     "content" => [],
                  ]);
            }
            if (!$qDid->delete()) {
               $messages = $qDid->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               return $this->response->setStatusCode(400)
                  ->setJsonContent([
                     "result" => "error",
                     "message" => "Error occured while deleting the did.",
                     "content" => [],
                  ]);
            }

            return $this->response->setStatusCode(200)
               ->setJsonContent([
                  "result" => "success",
                  "message" => "Successfully deleted DID.",
                  "content" => [],
               ]);
         }
         if ($parameters["type"] == 3) {
            $dids = QDids::find([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => [
                  "domain_uuid" => $session["domain_uuid"]
               ],
               "bindTypes" => [Column::BIND_PARAM_STR]
            ]);

            return $this->response->setStatusCode(200, "OK")
               ->setJsonContent([
                  "result" => "success",
                  "dids" => $dids,
                  "sip_trunk_name" => $session["sip_trunk_name"]
               ]);
         }
      }
   }

   public function deleteAction() {
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["uid"] = $this->request->getPost("uid", "string");
         $parameters["uuid"] = $this->request->getPost("uuid", "string");
         $parameters["token"] = $this->request->getPost("token", "string");
         if (empty($parameters["uid"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         $this->request->setStrictHostCheck(true);
         if (in_array($this->request->getClientAddress(), $this->allowedIps)) {
            error_log("<-- THIS IS A VALID DELETE REQUEST 111");
            $qCompanies = QCompanies::findFirst(
               [
                  "conditions" => "uid = :uid: AND uuid = :uuid: AND token = :token:",
                  "bind" => [
                     "uid" => $parameters["uid"],
                     "uuid" => $parameters["uuid"],
                     "token" => $parameters["token"],
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
               ]
            );
            if ($qCompanies) {
               $domain_uuid = $qCompanies->domain_uuid;
               error_log("<-- THIS IS A VALID DELETE REQUEST 222");
               $this->db->begin();
               $index = 1;
               $qAuxcodes = QAuxcodes::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qAuxcodes as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qAuxcodesList = QAuxcodesList::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qAuxcodesList as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qBlfs = QBlfs::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qBlfs as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCallBehaviors = QCallBehaviors::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCallBehaviors as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCallCost = QCallCost::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCallCost as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCallQueues = QCallQueues::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCallQueues as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCallQueuesList = QCallQueuesList::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCallQueuesList as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCallRatings = QCallRatings::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCallRatings as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCompanies = QCompanies::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCompanies as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCompanyBlfs = QCompanyBlfs::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCompanyBlfs as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qCompanyModes = QCompanyModes::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qCompanyModes as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qContacts = QContacts::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qContacts as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qDids = QDids::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qDids as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qHolidayExemptions = QHolidayExemptions::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qHolidayExemptions as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qHotel = QHotel::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qHotel as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qIntercom = QIntercom::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qIntercom as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qOutboundRules = QOutboundRules::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qOutboundRules as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qQueueNotifications = QQueueNotifications::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qQueueNotifications as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qRecordings = QRecordings::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qRecordings as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qScripts = QScripts::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qScripts as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qSecurityRestrictions = QSecurityRestrictions::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qSecurityRestrictions as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qSecurityRules = QSecurityRules::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qSecurityRules as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qTimeSchedules = QTimeSchedules::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qTimeSchedules as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qTokens = QTokens::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qTokens as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vUsers = VUsers::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vUsers as $value) {
                  $qUsers = QUsers::find(
                     [
                        "conditions" => "user_uuid = :user_uuid:",
                        "bind" => [
                           "user_uuid" => $value->user_uuid
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                     ]
                  );
                  foreach ($qUsers as $value2) {
                     if (!$value2->delete()) {
                        $this->db->rollback();
                        $response = json_encode([
                           "result" => "error",
                           "message" => "Delete request failed error code (" . strval($index) . ")",
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     }
                  }
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qWrapupnotes = QWrapupnotes::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qWrapupnotes as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $qWrapupnotesList = QWrapupnotesList::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($qWrapupnotesList as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vCallCenterAgents = VCallCenterAgents::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vCallCenterAgents as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vCallCenterQueues = VCallCenterQueues::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vCallCenterQueues as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vCallCenterTiers = VCallCenterTiers::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vCallCenterTiers as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vConferenceCenters = VConferenceCenters::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vConferenceCenters as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vDestinations = VDestinations::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vDestinations as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vDialplanDetails = VDialplanDetails::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vDialplanDetails as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vDialplans = VDialplans::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vDialplans as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vDomains = VDomains::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vDomains as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vExtensionUsers = VExtensionUsers::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vExtensionUsers as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vExtensions = VExtensions::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vExtensions as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vFollowMe = VFollowMe::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vFollowMe as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vFollowMeDestinations = VFollowMeDestinations::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vFollowMeDestinations as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vGateways = VGateways::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vGateways as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vIvrMenus = VIvrMenus::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vIvrMenus as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vIvrMenuOptions = VIvrMenuOptions::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vIvrMenuOptions as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vRecordings = VRecordings::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vRecordings as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vPinNumbers = VPinNumbers::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vPinNumbers as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vRingGroupDestinations = VRingGroupDestinations::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vRingGroupDestinations as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vUserGroups = VUserGroups::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vUserGroups as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vUserLogs = VUserLogs::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vUserLogs as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vVoicemailGreetings = VVoicemailGreetings::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vVoicemailGreetings as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vVoicemailMessages = VVoicemailMessages::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vVoicemailMessages as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vVoicemails = VVoicemails::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vVoicemails as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $vXmlCdr = VXmlCdr::find(
                  [
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]
               );
               foreach ($vXmlCdr as $value) {
                  if (!$value->delete()) {
                     $this->db->rollback();
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Delete request failed error code (" . strval($index) . ")",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
               $this->db->commit();
               $response = json_encode([
                  "result" => "success",
                  "message" => "Delete removal request sucess.",
               ]);
               $this->response->setContent($response);
               return $this->response;
            } else {
               $response = json_encode([
                  "result" => "success",
                  "message" => "No company record found.",
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
         } else {
            $response = json_encode([
               "result" => "error",
               "message" => "Unathorized request.",
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
      }
   }

   public function firewallAction($subdomain = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
         $response = json_encode([
            "result" => "error",
            "message" => "Unathorized request made (Code RI1000)",
            "content" => [],
         ]);
         $this->response->setContent($response);
         return $this->response;
      }
      if ($this->request->isGet()) {
         $parameters["subdomain"] = $this->filter->sanitize($subdomain, "string");
         $vDomain = VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => [
               "domain_name" => $parameters["subdomain"],
            ],
            "bindTypes" => [Column::BIND_PARAM_STR],
         ]);
         if (!$vDomain) {
            $response = json_encode([
               "result" => "error",
               "message" => "Domain does not exists (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
         $qSecurityRestrictions = QSecurityRestrictions::findFirst([
            "conditions" => "domain_uuid = :domain_uuid:",
            "bind" => [
               "domain_uuid" => $vDomain->domain_uuid,
            ],
            "bindTypes" => [Column::BIND_PARAM_STR],
         ]);
         if ($qSecurityRestrictions) {
            $allowedIps = !empty($qSecurityRestrictions->allowed_ips) ? explode(",", $qSecurityRestrictions->allowed_ips) : [];
            $blockedIps = !empty($qSecurityRestrictions->blocked_ips) ? explode(",", $qSecurityRestrictions->blocked_ips) : [];
            $blockedCountries = !empty($qSecurityRestrictions->blocked_countries) ? explode(",", $qSecurityRestrictions->blocked_countries) : [];
            $response = json_encode([
               "result" => "success",
               "message" => "",
               "content" => [
                  "allowed_ips" => implode(",", $allowedIps),
                  "blocked_ips" => implode(",", $blockedIps),
                  "blocked_countries" => implode(",", $blockedCountries),
               ],
            ]);
            $this->response->setContent($response);
            return $this->response;
         } else {
            $response = json_encode([
               "result" => "error",
               "message" => "An error has occured. Please check values. (Code AE1021)",
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
      } else if ($this->request->isPost()) {
         $parameters = [];
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         $parameters["ip_address"] = $this->request->getPost("ip_address", "string");
         $parameters["range"] = $this->request->getPost("range", "string");
         // $parameters["comments"] = $this->request->getPost("comments", "string");
         $parameters["subdomain"] = $this->request->getPost("subdomain", "string");
         $parameters["cloud_server"] = intval($this->request->getPost("cloud_server", "int"));
         if (empty($parameters["subdomain"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         error_log('THIS IS CALLED 222');
         if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
            $response = json_encode([
               "result" => "error",
               "message" => "Unathorized request made (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
         // Cloud Server PHP level
         if ($parameters["cloud_server"] == 1) {
            // BAN IP
            if ($parameters["type"] == 1) {

            }
            // UNBAN IP
            if ($parameters["type"] == 2) {

            }
            // WHITELIST IP
            if ($parameters["type"] == 3) {

            }
         } else {

            // Parse JSON input
            $json_data = file_get_contents("php://input");
            $data = json_decode($json_data, true);

            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
               return $this->respondWithError(400, 'Error decoding JSON data or missing data');
            }

            if (!isset($data['type']) || ($data['type'] !== "4" && (empty($data['ip_address']) || !isset($data['ip_address']))) || ($data['type'] !== "4" && !isset($data['option']))) {
               return $this->respondWithError(400, 'Type, option (except for type 4), and IP address are required');
            }

            if (!empty($data['ip_address'])) {
               // Trim whitespace and sanitize IP
               $data['ip_address'] = trim($data['ip_address']);

               if ($data['option'] === "range") {
                  // Ensure subnet exists and is a valid format
                  if (!isset($data['subnet']) || !preg_match('/^\/?(\d{1,2})$/', $data['subnet'], $subnetMatches)) {
                     return $this->respondWithError(400, 'Subnet must be a number between 0 and 32');
                  }

                  // Extract and convert subnet to an integer
                  $subnet = (int) $subnetMatches[1];

                  // Validate subnet (must be between 0 and 32)
                  if ($subnet < 0 || $subnet > 32) {
                     return $this->respondWithError(400, 'Invalid subnet mask. Must be between 0 and 32');
                  }

                  // Clean IP address for proper formatting (removes unwanted quotes)
                  $ip_address = escapeshellcmd($data['ip_address'] . '/' . $subnet);
               } else {
                  // Clean IP address for proper formatting
                  $ip_address = escapeshellcmd($data['ip_address']);
               }
            }

            // Add a ban firewall rule
            if ($data['type'] === "1") {

               $check_command = "sudo iptables -L INPUT -n --line-numbers | grep $ip_address | grep DROP";
               exec($check_command, $output);

               $check_command1 = "sudo iptables -L INPUT -n --line-numbers | grep $ip_address | grep ACCEPT";
               exec($check_command1, $output1);

               if (!empty($output1)) { // If there's no response, add the rule
                  return $this->respondWithError(400, 'Whitelist Exist, please remove it first to ban');
               }

               if (empty($output)) { // If there's no response, add the rule
                  if (empty($data['description']) || trim($data['description']) === '') {
                     // comments is either not set, null, or empty after trimming
                     $command = "sudo iptables -I INPUT -s $ip_address -j DROP";
                  } else {
                     // $comment = "Blocked by admin"; // You can make this dynamic too
                     $description = $data['description'];
                     $command = "sudo iptables -I INPUT -s $ip_address -j DROP -m comment --comment \"$description\"";
                  }
                  $command_output = shell_exec($command . " 2>&1");

                  return $this->response->setJsonContent([
                     'result' => 'success',
                     'message' => "Firewall rule added for $ip_address",
                     'command' => $command,
                     'output' => $command_output
                  ]);
               } elseif (!empty($output)) {
                  return $this->respondWithError(400, 'Firewall rule already exists');
               } else {
                  return $this->respondWithError(400, 'Unhandled error occurred');
               }
            }

            // Unban
            elseif ($data['type'] === "2") {

               $check_command = "sudo iptables -L INPUT -n --line-numbers | grep $ip_address | grep DROP";
               exec($check_command, $output);

               if (empty($output)) { // If there's no response, add the rule
                  return $this->respondWithError(400, 'Firewall rule not existing');
               } elseif (!empty($output)) {
                  $matched_line = $output[0]; // Use first match only
                  $has_comment = preg_match('/\/\*\s*(.*?)\s*\*\//', $matched_line, $matches);
                  if ($has_comment) {
                     $description = $matches[1];
                     $command = "sudo iptables -D INPUT -s $ip_address -j DROP -m comment --comment \"$description\"";
                  } else {
                     $command = "sudo iptables -D INPUT -s $ip_address -j DROP";
                  }

                  $output = shell_exec($command . " 2>&1");
                  return $this->response->setJsonContent([
                     'result' => 'success',
                     'message' => "Firewall rule deleted",
                     'command' => $command,
                     'output' => $output
                  ]);
               } else {
                  return $this->respondWithError(400, 'Unhandled error occurred');
               }
            }

            // Add a whitelist rule
            elseif ($data['type'] === "3") {
               $check_command = "sudo iptables -L INPUT -n --line-numbers | grep $ip_address | grep ACCEPT";
               exec($check_command, $output);

               if (empty($output)) { // If there's no response, add the rule
                  shell_exec("sudo iptables -D INPUT -s $ip_address -j DROP 2>&1");

                  if (empty($data['description']) || trim($data['description']) === '') {
                     $command = "sudo iptables -I INPUT -s $ip_address -j ACCEPT";
                  } else {
                     $description = $data['description'];
                     $command = "sudo iptables -I INPUT -s $ip_address -j ACCEPT -m comment --comment \"$description\"";
                  }

                  $output = shell_exec($command . " 2>&1");
                  return $this->response->setJsonContent([
                     'result' => 'success',
                     'message' => "Whitelist added, removed ban from IP",
                     'command' => $command,
                     'output' => $output
                  ]);

               } elseif (!empty($output)) {
                  return $this->respondWithError(400, 'Whitelist rule already exists');
               } else {
                  return $this->respondWithError(400, 'Unhandled error occurred');
               }
            }

            // Search for IP in iptables
            elseif ($data['type'] === "4") {
               if (empty($ip_address)) {
                  $search = "";
               } else {
                  $search = $ip_address;
               }
               $output = [];
               $returnCode = 0;

               // Execute iptables command to list rules
               exec("sudo /sbin/iptables -L INPUT -v -n --line-numbers 2>&1", $output, $returnCode);

               // Early return on failure
               if ($returnCode !== 0) {
                  return $this->respondWithError(400, 'Failed to retrieve iptables rules');
               }

               $allowedIps = [];
               $blockedIps = [];
               $pattern = '/
                   ^\s*\d+\s+\S+\s+\S+\s+               # line number, pkts, bytes (more flexible with \S+)
                   (ACCEPT|DROP)\s+                     # target (ACCEPT or DROP)
                   \S+\s+\S+\s+\S+\s+                   # prot, opt, in
                   \S+\s+                               # out
                   (\d{1,3}(?:\.\d{1,3}){3}(?:\/\d+)?)\s+ # source IP with optional subnet
                   (\d{1,3}(?:\.\d{1,3}){3}(?:\/\d+)?)   # destination IP with optional subnet
                   (?:\s+\/\*\s*(.*?)\s*\*\/)?          # optional comment (more lenient)
                   \s*$
               /x';

               foreach ($output as $line) {
                  $line = str_replace('\\/', '/', $line); // Clean up escaped slashes
                  $line = trim($line);                    // Remove extra spaces

                  if (preg_match($pattern, $line, $matches)) {
                     $action = $matches[1];        // ACCEPT or DROP
                     $srcAddress = $matches[2];    // Source IP (may include subnet)
                     $destination = $matches[3];   // Destination IP/subnet
                     $comment = isset($matches[4]) ? trim($matches[4]) : "";

                     // Parse source subnet
                     if (strpos($srcAddress, '/') !== false) {
                        list($ipOnly, $ipSubnet) = explode('/', $srcAddress);
                     } else {
                        $ipOnly = $srcAddress;
                        $ipSubnet = "";
                     }

                     // Parse destination subnet
                     if (strpos($destination, '/') !== false) {
                        list($destIp, $destSubnet) = explode('/', $destination);
                     } else {
                        $destIp = $destination;
                        $destSubnet = "";
                     }

                     if ($ipOnly !== "0.0.0.0") {
                        $entry = [
                           "ipAddress" => $ipSubnet !== "" ? "$ipOnly/$ipSubnet" : $ipOnly,
                           "ipSubnet" => $ipSubnet,
                           "destination" => $destIp,
                           "subnet" => $destSubnet,
                           "description" => $comment,
                        ];

                        if ($action === 'ACCEPT') {
                           $allowedIps[] = $entry;
                        } else {
                           $blockedIps[] = $entry;
                        }
                     }
                  } else {
                     // error_log("No match for line: $line");
                  }
               }

               // Filter results based on search parameter with wildcard support
               if (!empty($search)) {
                  $searchPattern = str_replace(['*', '?'], ['.*', '.'], preg_quote($search, '/'));

                  $allowedIps = array_filter($allowedIps, function ($entry) use ($searchPattern) {
                     return preg_match("/{$searchPattern}/", $entry["ipAddress"]);
                  });

                  $blockedIps = array_filter($blockedIps, function ($entry) use ($searchPattern) {
                     return preg_match("/{$searchPattern}/", $entry["ipAddress"]);
                  });
               }

               // Ensure correct format
               $allowedIps = array_values($allowedIps);
               $blockedIps = array_values($blockedIps);

               // Return JSON response
               return $this->response->setJsonContent([
                  "result" => "success",
                  "data" => [
                     "allowed_ips" => $allowedIps,
                     "blocked_ips" => $blockedIps
                  ]
               ]);
            }

            // Remove from whitelist
            elseif ($data['type'] === "5") {

               $check_command = "sudo iptables -L INPUT -n --line-numbers | grep $ip_address | grep ACCEPT";
               exec($check_command, $output);

               if (empty($output)) { // If there's no response, add the rule
                  return $this->respondWithError(400, 'Whitelist rule not existing');
               } elseif (!empty($output)) {

                  $matched_line = $output[0]; // Use first match only
                  $has_comment = preg_match('/\/\*\s*(.*?)\s*\*\//', $matched_line, $matches);
                  if ($has_comment) {
                     $comment = $matches[1]; // Extract comment without the /* */
                     $command = "sudo iptables -D INPUT -s $ip_address -j ACCEPT -m comment --comment \"$comment\"";
                  } else {
                     $command = "sudo iptables -D INPUT -s $ip_address -j ACCEPT";
                  }

                  $output = shell_exec($command . " 2>&1");
                  return $this->response->setJsonContent([
                     'result' => 'success',
                     'message' => "Whitelist rule deleted",
                     'command' => $command,
                     'output' => $output
                  ]);

               } else {
                  return $this->respondWithError(400, 'Unhandled error occurred');
               }
            }

            return $this->respondWithError(400, 'Invalid type provided');

         }
      }
   }

   public function extensionAction($code = "", $email = "", $hash = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if ($this->request->isGet()) {
         if (!empty($code) && !empty($email)) {
            $parameters = [];
            $parameters["code"] = intval($this->filter->sanitize($code, "int"));
            $parameters["email"] = $this->filter->sanitize($email, "string");
            $emailForm = new EmailForm();
            if (!$emailForm->isValid($parameters)) {
               $messages = $emailForm->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error;
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code RE1000)",
               ]);
               $this->response->setContent($response);
               return $this->response;
            } else {
               $verification = strval($parameters["code"]);
               if ($parameters["email"] == "apple1@ringq.com" || $parameters["email"] == "apple2@ringq.com") {
                  if ($parameters["code"] == 543758123 || $parameters["code"] == 598432321) {
                     $security = new \Phalcon\Security\Random();
                     $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
                     $access_token = bin2hex(random_bytes(32));
                     $user_uuid = $parameters["email"] == "apple1@ringq.com" ? "4234e228-d969-4ba1-95ff-8a91e62a4a59" : "c7dacf22-ff78-441c-a240-6da707ba062c";
                     $domain_uuid = "b0e24914-a8e1-429e-ade5-61327527d3de";
                     $extension_uuid = $parameters["email"] == "apple1@ringq.com" ? "8645141e-0be9-4fb1-96d2-fd5a740c95ac" : "cd0b63a1-8067-49e3-a880-a78190c08148";
                     $qToken = new QTokens();
                     $qToken->user_uuid = $user_uuid;
                     $qToken->domain_uuid = $domain_uuid;
                     $qToken->access_token = $access_token;
                     $qToken->insert_date = date("Y-m-d H:i:s");
                     $qToken->user_agent = $userAgent;
                     $qToken->type = 1; // web, android or ios
                     if (!$qToken->save()) {
                        $response = json_encode([
                           "result" => "error",
                           "message" => "An error has occurred. (Code RE1001)",
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     }
                     $expire = time() + 86400 * 360;
                     $this->cookies->set("RINGQKIE", $user_uuid, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
                     $this->cookies->set("RINGQENS", $access_token, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
                     $this->redis->del("verify2-" . $parameters["email"]);
                     if ($hash != "") {
                        $qUser = QUsers::findFirst([
                           "conditions" => "user_uuid = :user_uuid:",
                           "bind" => [
                              "user_uuid" => $user_uuid,
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR],
                        ]);
                        $vUser = VUsers::findFirst([
                           "conditions" => "user_uuid = :user_uuid:",
                           "bind" => [
                              "user_uuid" => $user_uuid,
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR],
                        ]);
                        $vExtension = VExtensions::findFirst([
                           "conditions" => "extension_uuid = :extension_uuid:",
                           "bind" => [
                              "extension_uuid" => $extension_uuid,
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR],
                        ]);
                        $vUser->user_status = "Available";
                        $vUser->update();
                        $vExtension->enabled = "true"; // true
                        if (!$vExtension->save()) {
                           $response = json_encode([
                              "result" => "error",
                              "message" => "An error has occurred. (Code RE1002)",
                           ]);
                           $this->response->setContent($response);
                           return $this->response;
                        }
                        $walltoken = $this->security->hash($user_uuid);
                        $this->redis->sadd("wallboard:" . $vExtension->accountcode, $walltoken);
                        $content = [
                           "username" => $vUser->username,
                           "email" => $vUser->user_email,
                           "image" => $qUser->image,
                           "extension" => $vExtension->extension,
                           "subdomain" => $vExtension->accountcode,
                           "domain" => $this->config->application->publicUrl,
                           "domain_uuid" => $domain_uuid,
                           "sip_uuid" => $vExtension->password,
                           "user_uuid" => $user_uuid,
                           "walltoken" => $walltoken,
                           "access" => "registered",
                        ];
                        $this->session->set("auth", $content);
                        $response = json_encode([
                           "result" => "success",
                           "message" => "Login success.",
                           "content" => $content,
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     } else {
                        $this->response->redirect("/");
                     }
                  }
               } else {
                  $exists = $this->redis->exists("verify2-" . $parameters["email"]);
                  if ($exists) {
                     $checkCode = $this->redis->hget("verify2-" . $parameters["email"], "verify");
                     if ($checkCode == $verification) {
                        $security = new \Phalcon\Security\Random();
                        $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
                        $access_token = bin2hex(random_bytes(32));
                        $user_uuid = $this->redis->hget("verify2-" . $parameters["email"], "user_uuid");
                        $domain_uuid = $this->redis->hget("verify2-" . $parameters["email"], "domain_uuid");
                        $extension_uuid = $this->redis->hget("verify2-" . $parameters["email"], "extension_uuid");
                        $qToken = new QTokens();
                        $qToken->user_uuid = $user_uuid;
                        $qToken->domain_uuid = $domain_uuid;
                        $qToken->access_token = $access_token;
                        $qToken->insert_date = date("Y-m-d H:i:s");
                        $qToken->user_agent = $userAgent;
                        $qToken->type = 1; // web, android or ios
                        if (!$qToken->save()) {
                           $response = json_encode([
                              "result" => "error",
                              "message" => "An error has occurred. (Code RE1001)",
                           ]);
                           $this->response->setContent($response);
                           return $this->response;
                        }
                        $expire = time() + 86400 * 360;
                        $this->cookies->set("RINGQKIE", $user_uuid, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
                        $this->cookies->set("RINGQENS", $access_token, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
                        $this->redis->del("verify2-" . $parameters["email"]);
                        if ($hash != "") {
                           $qUser = QUsers::findFirst([
                              "conditions" => "user_uuid = :user_uuid:",
                              "bind" => [
                                 "user_uuid" => $user_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $vUser = VUsers::findFirst([
                              "conditions" => "user_uuid = :user_uuid:",
                              "bind" => [
                                 "user_uuid" => $user_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $vExtension = VExtensions::findFirst([
                              "conditions" => "extension_uuid = :extension_uuid:",
                              "bind" => [
                                 "extension_uuid" => $extension_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);

                 $vDomain = VDomains::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" => [
                       "domain_uuid" => $domain_uuid,
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                 ]);
                 if (!$vDomain) {
                   $response = json_encode([
                     "result" => "error",
                     "message" => "Sorry, please check account domain. (Code RI1002)",
                   ]);
                   $this->response->setContent($response);
                   return $this->response;
                 }
                 error_log("disableapp:" . $vExtension->extension . ":" . $vDomain->domain_name);
                     $webappEnabled = $this->redis->exists("disableapp:" . $vExtension->extension . ":" . $vDomain->domain_name);
                     if ($webappEnabled) {
                       $response = json_encode([
                         "result" => "error",
                         "message" => "Sorry, webapp login is currently disabled for this extension. (Code LF1002)",
                       ]);
                       $this->response->setContent($response);
                       return $this->response;
                     }

                           $vUser->user_status = "Available";
                           $vUser->update();
                           $vExtension->enabled = "true"; // true
                           if (!$vExtension->save()) {
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RE1002)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $walltoken = $this->security->hash($user_uuid);
                           $this->redis->sadd("wallboard:" . $vExtension->accountcode, $walltoken);
                           $content = [
                              "username" => $vUser->username,
                              "email" => $vUser->user_email,
                              "image" => $qUser->image,
                              "extension" => $vExtension->extension,
                              "subdomain" => $vExtension->accountcode,
                              "domain" => $this->config->application->publicUrl,
                              "domain_uuid" => $domain_uuid,
                              "sip_uuid" => $vExtension->password,
                              "user_uuid" => $user_uuid,
                              "walltoken" => $walltoken,
                              "access" => "registered",
                           ];
                           // [one-mobile] this phone is now the extension's only mobile app
                           $this->session->set("auth", $content + ["mobile_session" => $access_token]);
                           $this->claimMobileApp(strval($vExtension->extension), [strval($vDomain->domain_name), strval($vExtension->accountcode)], strval($domain_uuid), strval($user_uuid), $access_token);
                           $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                              "persistent" => false,
                           ]));
                           $client->initialize();
                           $client->emit("qrLogin-initiate", ["accessCode" => $checkCode, "subdomain" => $vExtension->accountcode]);
                           $response = json_encode([
                              "result" => "success",
                              "message" => "Login success.",
                              "content" => $content,
                           ]);
                           $this->response->setContent($response);
                           return $this->response;
                        } else {
                           $this->response->redirect("/");
                        }
                     } else {
                        $response = json_encode([
                           "result" => "error",
                           "message" => "Please try again. Verification code has expired already. (Code RE1003)",
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     }
                  } else {
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Sorry, QR code already expired. (Code RE1004)",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
            }
         } else {
            $this->response->redirect("/");
         }
      }
   }

   public function numberAction($id = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      if ($this->request->isGet()) {
         $this->response->redirect("index");
         return;
      }
      if ($this->request->isPost()) {
         if (empty($id)) {
            $curl = curl_init();
            curl_setopt_array($curl, [
               CURLOPT_URL => "https://api.didww.com/v3/regions",
               CURLOPT_RETURNTRANSFER => true,
               CURLOPT_ENCODING => "",
               CURLOPT_MAXREDIRS => 10,
               CURLOPT_TIMEOUT => 0,
               CURLOPT_FOLLOWLOCATION => true,
               CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
               CURLOPT_CUSTOMREQUEST => "GET",
               CURLOPT_HTTPHEADER => ["Accept: application/vnd.api+json", "Content-Type: application/vnd.api+json", "Api-Key: 7VA4AD2CUHBWC4P3TM3GF"],
            ]);
            $response = curl_exec($curl);
            curl_close($curl);
            $response = json_decode($response, true);
            $results = $response["data"];
            $regions = [];
            foreach ($results as $key => $value) {
               $regions[] = [
                  "id" => $value["id"],
                  "region" => $value["attributes"]["name"],
               ];
            }
            $response = json_encode([
               "result" => "success",
               "message" => "DID region request success.",
               "content" => $regions,
            ]);
            $this->response->setContent($response);
            return $this->response;
         } else {
            $curl = curl_init();
            curl_setopt_array($curl, [
               CURLOPT_URL => "https://api.didww.com/v3/available_dids?filter%5Bregion.id%5D=" . $id,
               CURLOPT_RETURNTRANSFER => true,
               CURLOPT_ENCODING => "",
               CURLOPT_MAXREDIRS => 10,
               CURLOPT_TIMEOUT => 0,
               CURLOPT_FOLLOWLOCATION => true,
               CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
               CURLOPT_CUSTOMREQUEST => "GET",
               CURLOPT_HTTPHEADER => ["Accept: application/vnd.api+json", "Content-Type: application/vnd.api+json", "Api-Key: 7VA4AD2CUHBWC4P3TM3GF"],
            ]);
            $response = curl_exec($curl);
            curl_close($curl);
            $response = json_decode($response, true);
            $results = $response["data"];
            $numbers = [];
            foreach ($results as $key => $value) {
               $numbers[] = [
                  "id" => $value["id"],
                  "number" => $value["attributes"]["number"],
               ];
            }
            $response = json_encode([
               "result" => "success",
               "message" => "DID numbers request success.",
               "content" => $numbers,
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
      }
   }

   public function resenderAction() {
      $parameters = [];
      $parameters["email"] = $this->request->getPost("email", "email");
      if (empty($parameters["email"])) {
         $contents = file_get_contents("php://input");
         $parameters = json_decode($contents, true);
      }
      $emailForm = new EmailForm();
      if (!$emailForm->isValid($parameters)) {
         $messages = $emailForm->getMessages();
         $message = "";
         foreach ($messages as $error) {
            $message .= $error;
         }
         $response = json_encode([
            "result" => "error",
            "message" => trim($message) . " (Code RV1000)",
         ]);
         $this->response->setContent($response);
         return $this->response;
      }
      $verify = rand(100000000, 999999999);
      $exists = $this->redis->exists("backup-verify-" . $parameters["email"]);
      if ($exists) {
         $backupVerify = $this->redis->hgetall("backup-verify-" . $parameters["email"]);
         $backupVerify = array_merge($backupVerify, ["verify" => $verify]);
         $this->redis->hmset("verify-" . $parameters["email"], $backupVerify);
         $this->redis->lrem("register", 0, $parameters["email"]);
         $this->redis->lpush("register", $parameters["email"]);
         $this->redis->expire("verify-" . $parameters["email"], 900);
         $response = json_encode([
            "result" => "success",
            "message" => "Another email will be sent over to the user's registered email address.",
         ]);
         $this->response->setContent($response);
         return $this->response;
      } else {
         $response = json_encode([
            "result" => "error",
            "message" => "Sorry, verification does not exists.",
         ]);
         $this->response->setContent($response);
         return $this->response;
      }
   }

   public function systemAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $session = $this->session->get("auth");
      if ($session) {
         $permissionMode = $this->redis->get($session["extension"] . "-" . $session["subdomain"] . "-permission");
         if ($permissionMode == 10) {
            $response = json_encode([
               "result" => "error",
               "message" => "Unathorized request made (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
      } else {
         if (!in_array($this->request->getClientAddress(), $this->allowedIps)) {
            $response = json_encode([
               "result" => "error",
               "message" => "Unathorized request made (Code RI1000)",
               "content" => [],
            ]);
            $this->response->setContent($response);
            return $this->response;
         }
      }
      // Service restart panel. Each card's status key, the pm2 apps it covers
      // and its restart type. Keys stay as the old labels so older apps keep
      // reading them; the panel shows its own names. "optional" apps count only
      // when this server runs them.
      $groups = [
         "Event Server"    => ["type" => 2,  "apps" => ["server"]],
         "Signal Server"   => ["type" => 3,  "apps" => ["signal"]],
         "Push Server"     => ["type" => 4,  "apps" => ["status"]],
         "Transfer Server" => ["type" => 5,  "apps" => ["attended", "queue"]],
         "Meets Server"    => ["type" => 6,  "apps" => ["meeting"], "optional" => ["meeting-summary"]],
         "Chat Server"     => ["type" => 9,  "apps" => ["chat"]],
         "Integrations"    => ["type" => 10, "apps" => [], "optional" => ["custom_crm", "transcript_live", "sso", "mcp"]],
      ];
      if ($this->request->isGet()) {
         $states = $this->systemPm2States();
         $content = [];
         $details = [];

         $callServices = $this->systemCallServicesUp();
         $content["RingQ Server"] = $callServices === true;
         if ($callServices !== true) {
            $details["RingQ Server"] = $callServices === null ? "not answering" : "phones can't register";
         }

         foreach ($groups as $label => $group) {
            if ($states === null) {
               $content[$label] = false;
               $details[$label] = "service list did not answer";
               continue;
            }
            $down = [];
            foreach ($group["apps"] as $app) {
               $state = $states[$app] ?? "not running";
               if ($state !== "online") $down[] = $app . " " . $state;
            }
            foreach ($group["optional"] ?? [] as $app) {
               if (isset($states[$app]) && $states[$app] !== "online") $down[] = $app . " " . $states[$app];
            }
            $content[$label] = !$down;
            if ($down) $details[$label] = implode(", ", $down);
         }
         $content["All"] = true;

         $response = json_encode([
            "result" => "success",
            "message" => "",
            "content" => $content,
            "details" => $details,
            // A background restart (call services or everything) is still running
            "restarting" => (bool) $this->redis->exists("startup"),
         ]);
         $this->response->setContent($response);
         return $this->response;
      }
      if ($this->request->isPost()) {
         $parameters = [];
         $parameters["type"] = intval($this->request->getPost("type", "int"));
         $parameters["action"] = intval($this->request->getPost("action", "int"));
         if (empty($parameters["type"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         $type = intval($parameters["type"] ?? 0);

         // Names as the customer sees them, also what the audit log records
         $names = [
            1 => "RingQ Call Services", 2 => "Live Status & Events", 3 => "App Connection",
            4 => "Mobile Ringing", 5 => "Transfers & Queues", 6 => "Meetings",
            7 => "Everything", 8 => "Server reboot", 9 => "Chat & Messaging", 10 => "Integrations",
         ];
         $reply = function ($ok, $message, $metadata) use ($type, $names) {
            $this->audit("service-restart", $type == 8 ? "reboot" : "restart", [
               "entity" => $names[$type] ?? ("type " . $type),
               "result" => $ok ? "success" : "error",
               "metadata" => array_merge(["type" => $type], $metadata, $message !== "" ? ["message" => $message] : []),
            ]);
            $this->response->setContent(json_encode([
               "result" => $ok ? "success" : "error",
               "message" => $message,
               "content" => "",
            ]));
            return $this->response;
         };
         if (!isset($names[$type])) {
            return $reply(false, "Unknown service.", []);
         }

         // Call services (1) and everything (7) take minutes: run startup.sh in
         // the background and let the panel poll the GET above.
         if ($type == 1 || $type == 7) {
            if ($this->redis->exists("startup")) {
               return $reply(false, "A restart is already running. Please wait for it to finish.", []);
            }
            $this->redis->setex("startup", 900, 1);
            $mode = $type == 7 ? "all" : "call-services";
            exec("sudo -n nohup /bin/bash /var/www/ringq/app/scripts/startup.sh " . $mode . " > /tmp/ringq-service-restart.log 2>&1 &");
            if ($type == 7) $this->redis->del("connected_participants");
            return $reply(true, "", ["background" => true]);
         }

         if ($type == 8) {
            $reply(true, "", []);
            shell_exec("sudo reboot 2>&1");
            return $this->response;
         }

         $restarted = [];
         $failed = [];
         foreach ($groups as $group) {
            if ($group["type"] !== $type) continue;
            $optional = $group["optional"] ?? [];
            $states = $optional ? ($this->systemPm2States() ?? []) : [];
            foreach (array_merge($group["apps"], $optional) as $app) {
               // Optional apps are restarted only on servers that run them
               if (in_array($app, $optional, true) && !isset($states[$app])) continue;
               $out = [];
               exec("timeout 60 sudo -n /bin/pm2 restart " . escapeshellarg($app) . " 2>&1", $out, $code);
               if ($code === 0) {
                  $restarted[] = $app;
               } else {
                  $failed[] = $app;
               }
            }
            if ($type == 6) $this->redis->del("connected_participants");
         }
         if ($failed) {
            return $reply(false, "Could not restart " . $names[$type] . ". Contact RingQ support if it stays offline.", ["restarted" => $restarted, "failed" => $failed]);
         }
         return $reply(true, "", ["restarted" => $restarted]);
      }
   }

   /** pm2 app name => status, or null when pm2 can't be read in time. */
   private function systemPm2States() {
      // Web requests don't autoload app/library (only cli.php registers it)
      require_once __DIR__ . "/../library/ServiceHealth.php";
      foreach (\ServiceHealth::PM2_CANDIDATES as $binary) {
         if (!is_link($binary) && !is_file($binary)) continue;
         $raw = @shell_exec("timeout 15 sudo -n -H " . escapeshellarg($binary) . " jlist 2>/dev/null");
         $decoded = is_string($raw) ? \ServiceHealth::decodePm2List($raw) : null;
         if ($decoded === null) continue;
         $states = [];
         foreach (\ServiceHealth::pm2ByName($decoded, time()) as $name => $p) {
            $states[$name] = $p["state"];
         }
         return $states;
      }
      return null;
   }

   /** true when the call engine's phone profile is running, false when not, null when it doesn't answer. */
   private function systemCallServicesUp() {
      $raw = @shell_exec("timeout 10 /usr/bin/fs_cli -x 'sofia status' 2>/dev/null");
      if (!is_string($raw) || trim($raw) === "") return null;
      return (bool) preg_match('/^\s*internal\s+profile\s+\S+\s+RUNNING/m', $raw);
   }

   public function verifyAction($code = "", $email = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if ($this->request->isGet()) {
         if (!empty($code) && !empty($email)) {
            $parameters = [];
            $parameters["code"] = intval($this->filter->sanitize($code, "int"));
            $parameters["email"] = $this->filter->sanitize($email, "string");
            $emailForm = new EmailForm();
            if (!$emailForm->isValid($parameters)) {
               $messages = $emailForm->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error;
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code RV1000)",
               ]);
               $this->response->setContent($response);
               return $this->response;
            } else {
               $verification = strval($parameters["code"]);
               $exists = $this->redis->exists("verify-" . $parameters["email"]);
               if ($exists) {
                  $checkCode = $this->redis->hget("verify-" . $parameters["email"], "verify");
                  if ($checkCode == $verification) {
                     $security = new \Phalcon\Security\Random();
                     $qCompany = QCompanies::findFirst([
                        "conditions" => "company_email = :company_email:",
                        "bind" => [
                           "company_email" => $parameters["email"],
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                     ]);
                     if ($qCompany) {
                        $this->db->begin();
                        $qCompany->company_status = 1;
                        if ($qCompany->update() === false) {
                           $this->db->rollback();
                           $this->redis->del("verify-" . $parameters["email"]);
                           $messages = $qCompany->getMessages();
                           $message = "";
                           foreach ($messages as $error) {
                              $message .= $error;
                           }
                           $data = json_encode([
                              "result" => "error",
                              "message" => trim($message) . " (Code RV1001)",
                           ]);
                           $this->response->setContent($data);
                           return $this->response;
                        } else {
                           $user_uuid = $qCompany->user_uuid;
                           $domain_uuid = $qCompany->domain_uuid;
                           $company_email = $qCompany->company_email;
                           $voicemail_uuid = $security->uuid();
                           $company = strtolower(str_replace(" ", "", preg_replace("/[^a-zA-Z0-9_ -]/s", " ", $qCompany->company_name)));
                           $fqdn = $this->redis->hget("verify-" . $parameters["email"], "fqdn");
                           $extension = $qCompany->extension_length;
                           $firstname = PersonName::capitalize($this->redis->hget("verify-" . $parameters["email"], "firstname"));
                           $lastname = PersonName::capitalize($this->redis->hget("verify-" . $parameters["email"], "lastname"));
                           $package = $this->redis->hget("verify-" . $parameters["email"], "package");
                           $uuid = $this->redis->hget("verify-" . $parameters["email"], "uuid");
                           $access_token = $this->redis->hget("verify-" . $parameters["email"], "access_token");
                           $username = $security->hex(10);
                           $salt = $this->security->hash($security->base64(16));
                           $password = $this->security->hash($security->base64(16));
                           $password2 = $security->base64(16);
                           $extension_password = $password2; // md5($salt . $password2);
                           // $qPackages = QPackages::findFirst([
                           //    "conditions" => "id = :id:",
                           //    "bind" => [
                           //       "id" => $package,
                           //    ],
                           //    "bindTypes" => [Column::BIND_PARAM_INT],
                           // ]);
                           // if (!$qPackages) {
                           //    $this->db->rollback();
                           //    $data = json_encode([
                           //       "result" => "error",
                           //       "message" => "Sorry, package does not exists anymore. (Code RV1002)",
                           //    ]);
                           //    $this->response->setContent($data);
                           //    return $this->response;
                           // }
                           try {
                              $isNoAlias1000 = VExtensions::findFirst([
                                 "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND number_alias IS NULL",
                                 "bind" => [
                                    "domain_uuid" => $domain_uuid,
                                    "extension" => '1000',
                                 ],
                              ]);
                              if ($isNoAlias1000) {
                                 $isNoAlias1000->number_alias = $username;
                                 if (!$isNoAlias1000->save()) {
                                    $this->db->rollback();
                                    $messages = $isNoAlias1000->getMessages();
                                    $message = "";
                                    foreach ($messages as $error) {
                                       $message .= $error . " ";
                                    }
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => trim($message) . " (Code AE1001)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }
                           } catch (\Throwable $e) {
                              error_log(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
                           }
                           $qPackagesCompany = new QPackagesCompany();
                           $qPackagesCompany->user_uuid = $user_uuid;
                           $qPackagesCompany->package_id = $package;
                           $qPackagesCompany->uuid = $uuid;
                           $qPackagesCompany->access_token = $access_token;
                           $qPackagesCompany->created_at = date("Y-m-d H:i:s");
                           if (!$qPackagesCompany->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1003)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDomain = new VDomains();
                           $vDomain->domain_uuid = $domain_uuid;
                           $vDomain->domain_name = $fqdn;
                           $vDomain->domain_description = $fqdn;
                           $vDomain->domain_enabled = true;
                           $vDomain->insert_user = $user_uuid;
                           $vDomain->insert_date = date("Y-m-d H:i:s");
                           if (!$vDomain->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1004)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qUser = QUsers::findFirst([
                              "conditions" => "user_uuid = :user_uuid:",
                              "bind" => ["user_uuid" => $user_uuid],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           if (!$qUser) {
                              $qUser = new QUsers();
                              $qUser->user_uuid = $user_uuid;
                              $qUser->domain_uuid = $domain_uuid;
                              $qUser->first_name = $firstname;
                              $qUser->last_name = $lastname;
                              $qUser->extension_enabled = true;
                              $qUser->insert_date = date("Y-m-d H:i:s");
                              $qUser->user_email = $company_email;
                           }
                           $qUser->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                           if (!$qUser->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1005)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUser = new VUsers();
                           $vUser->username = $username;
                           $vUser->user_uuid = $user_uuid;
                           $vUser->domain_uuid = $domain_uuid;
                           $vUser->user_email = $company_email;
                           $vUser->password = $password;
                           // $vUser->salt = $salt;
                           $vUser->user_status = "Available";
                           $vUser->user_enabled = "true";
                           $vUser->api_key = $security->base64(32);
                           $vUser->image = null;
                           $vUser->first_name = $firstname;
                           $vUser->last_name = $lastname;
                           $vUser->insert_user = $user_uuid;
                           $vUser->insert_date = date("Y-m-d H:i:s");
                           if (!$vUser->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1006)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           // Removal of the DID
                           /*
                           $outbound_caller_id_number = $this->redis->hget("verify-" . $parameters["email"], "did");
                           if ($outbound_caller_id_number) {
                              $outbound_caller_id_number = trim($outbound_caller_id_number);
                              $outbound_caller_id_number = explode(",", $outbound_caller_id_number);
                           } else {
                              $outbound_caller_id_number[0] = $extension;
                           }
                           $vExtension->outbound_caller_id_name = $outbound_caller_id_number[0];
                           $vExtension->outbound_caller_id_number = $outbound_caller_id_number[0];
                           */
                           $vExtension = new VExtensions();
                           $vExtension->extension_uuid = $extension_uuid = $security->uuid();
                           $vExtension->domain_uuid = $domain_uuid;
                           $vExtension->extension = $extension;
                           $vExtension->password = $extension_password;
                           $vExtension->number_alias = $username;
                           $vExtension->enabled = "true";
                           $vExtension->extension_type = "default";
                           $vExtension->accountcode = $fqdn; // <!-- blank on original
                           $vExtension->directory_visible = "true";
                           $vExtension->directory_exten_visible = "true";
                           $vExtension->limit_max = "5"; // <!-- Should be based on purchased package
                           $vExtension->limit_destination = "!USER_BUSY";
                           $vExtension->effective_caller_id_name = $firstname . " " . $lastname;
                           // Owner's outbound caller ID = first DID purchased during registration.
                           $ownerPrimaryDid = explode(",", (string) ($this->redis->hget("verify-" . $parameters["email"], "did") ?: ''));
                           $ownerPrimaryDid = strval(intval(trim($ownerPrimaryDid[0] ?? '')));
                           if ($ownerPrimaryDid !== "0") {
                              $vExtension->effective_caller_id_number = $ownerPrimaryDid;
                              $vExtension->outbound_caller_id_number = $ownerPrimaryDid;
                           }
                           $vExtension->do_not_disturb = "false";
                           $vExtension->forward_all_enabled = "false";
                           $vExtension->forward_all_enabled2 = "false";
                           $vExtension->forward_busy_enabled = "false";
                           $vExtension->forward_busy_enabled2 = "false";
                           $vExtension->forward_no_answer_enabled = "true";
                           $vExtension->forward_no_answer_enabled2 = "true";
                           $vExtension->forward_user_not_registered_enabled = "false";
                           $vExtension->forward_user_not_registered_enabled2 = "false";
                           $vExtension->forward_no_answer_destination = "*99" . $extension;
                           $vExtension->forward_no_answer_destination2 = "*99" . $extension;
                           $vExtension->voicemail_enabled = "enabled";
                           $vExtension->voicemail_file = "attach";
                           $vExtension->call_timeout = "30";
                           $vExtension->call_screen_enabled = "false";
                           $vExtension->user_context = $fqdn;
                           $vExtension->voicemail_uuid = $voicemail_uuid;
                           $vExtension->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                           $vExtension->insert_user = $user_uuid;
                           $vExtension->insert_date = date("Y-m-d H:i:s");
                           if (!$vExtension->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1007)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtensionUser = new VExtensionUsers();
                           $vExtensionUser->extension_user_uuid = $security->uuid();
                           $vExtensionUser->domain_uuid = $domain_uuid;
                           $vExtensionUser->user_uuid = $user_uuid;
                           $vExtensionUser->extension_uuid = $extension_uuid;
                           if (!$vExtensionUser->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred.",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vVoicemail = new VVoicemails();
                           $vVoicemail->domain_uuid = $domain_uuid;
                           $vVoicemail->voicemail_uuid = $voicemail_uuid;
                           $vVoicemail->voicemail_id = $extension;
                           $vVoicemail->voicemail_mail_to = $company_email;
                           $vVoicemail->voicemail_password = (string)random_int(1000, 9999);
                           $vVoicemail->voicemail_transcription_enabled = "false";
                           $vVoicemail->voicemail_file = "attach";
                           $vVoicemail->voicemail_local_after_email = "true";
                           $vVoicemail->voicemail_enabled = "true";
                           if (!$vVoicemail->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1008)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $path = "/var/lib/freeswitch/recordings/{$fqdn}/archive";
                           if (!file_exists($path)) {
                              mkdir($path, 0777, true);
                           }
                           $vUserGroup = new VUserGroups();
                           $vUserGroup->user_group_uuid = $security->uuid();
                           $vUserGroup->user_uuid = $user_uuid;
                           $VGroup = VGroups::findFirst([
                              "conditions" => "group_name = :group_name:",
                              "bind" => [
                                 "group_name" => "admin",
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $vUserGroup->group_uuid = $VGroup->group_uuid;
                           $vUserGroup->group_name = $VGroup->group_name;
                           $vUserGroup->domain_uuid = $domain_uuid;
                           if (!$vUserGroup->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1009)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenu = new VIvrMenus();
                           $ivr_menu_uuid = $security->uuid();
                           $dialplan_uuid = $security->uuid();
                           $vIvrMenu->ivr_menu_uuid = $ivr_menu_uuid;
                           $vIvrMenu->domain_uuid = $domain_uuid;
                           $vIvrMenu->dialplan_uuid = $dialplan_uuid;
                           $vIvrMenu->ivr_menu_extension = "3000";
                           $vIvrMenu->ivr_menu_name = "Ratings";
                           $vIvrMenu->ivr_menu_timeout = 30000;
                           $vIvrMenu->ivr_menu_greet_long = "Thank_you-we-hope-yo.wav";
                           $vIvrMenu->ivr_menu_parent_uuid = null;
                           $vIvrMenu->ivr_menu_language = "";
                           $vIvrMenu->ivr_menu_dialect = "us";
                           $vIvrMenu->ivr_menu_voice = "callie";
                           $vIvrMenu->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                           $vIvrMenu->ivr_menu_exit_sound = "";
                           $vIvrMenu->ivr_menu_pin_number = "";
                           $vIvrMenu->ivr_menu_confirm_macro = "";
                           $vIvrMenu->ivr_menu_confirm_key = "";
                           $vIvrMenu->ivr_menu_tts_engine = "flite";
                           $vIvrMenu->ivr_menu_tts_voice = "rms";
                           $vIvrMenu->ivr_menu_confirm_attempts = 1;
                           $vIvrMenu->ivr_menu_exit_app = "hangup";
                           $vIvrMenu->ivr_menu_exit_data = null;
                           $vIvrMenu->ivr_menu_inter_digit_timeout = 2000;
                           $vIvrMenu->ivr_menu_max_failures = 1;
                           $vIvrMenu->ivr_menu_max_timeouts = 1;
                           $vIvrMenu->ivr_menu_digit_len = 5;
                           $vIvrMenu->ivr_menu_direct_dial = "false";
                           $vIvrMenu->ivr_menu_ringback = "local_stream://default";
                           $vIvrMenu->ivr_menu_cid_prefix = "";
                           $vIvrMenu->ivr_menu_context = $fqdn;
                           $vIvrMenu->ivr_menu_enabled = "true";
                           $vIvrMenu->ivr_menu_description = "";
                           $vIvrMenu->insert_date = date("Y-m-d H:i:s");
                           if (!$vIvrMenu->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenu->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1004)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDialplan = new VDialplans();
                           $vDialplan->domain_uuid = $domain_uuid;
                           $vDialplan->dialplan_uuid = $dialplan_uuid;
                           $vDialplan->app_uuid = $security->uuid();
                           $vDialplan->dialplan_name = "Ratings";
                           $vDialplan->dialplan_number = "3000";
                           $vDialplan->dialplan_order = 81;
                           $vDialplan->dialplan_context = $fqdn;
                           $vDialplan->dialplan_continue = "false";
                           $vDialplan->dialplan_xml =
                              '<extension name="Ratings" continue="false" uuid="' .
                              $dialplan_uuid .
                              '">
											<condition field="destination_number" expression="^3000$">
												<action application="ring_ready" data=""/>
												<action application="answer" data=""/>
												<action application="sleep" data="1000"/>
												<action application="set" data="hangup_after_bridge=true"/>
												<action application="set" data="ringback=local_stream://default"/>
												<action application="set" data="transfer_ringback=local_stream://default"/>
												<action application="set" data="ivr_menu_uuid=' .
                              $ivr_menu_uuid .
                              '"/>
												<action application="ivr" data="' .
                              $ivr_menu_uuid .
                              '"/>
												<action application="hangup" data=""/>
											</condition>
                              </extension>';
                           $vDialplan->dialplan_enabled = "true";
                           $vDialplan->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenuOption = new VIvrMenuOptions();
                           $vIvrMenuOption->domain_uuid = $domain_uuid;
                           $vIvrMenuOption->ivr_menu_uuid = $ivr_menu_uuid;
                           $vIvrMenuOption->ivr_menu_option_uuid = $security->uuid();
                           $vIvrMenuOption->ivr_menu_option_action = "menu-exec-app";
                           $vIvrMenuOption->ivr_menu_option_param = "transfer 3001 XML " . $fqdn;
                           $vIvrMenuOption->ivr_menu_option_order = 0;
                           $vIvrMenuOption->ivr_menu_option_digits = "1";
                           $vIvrMenuOption->ivr_menu_option_enabled = 1;
                           if (!$vIvrMenuOption->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenuOption->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1006)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenuOption2 = new VIvrMenuOptions();
                           $vIvrMenuOption2->domain_uuid = $domain_uuid;
                           $vIvrMenuOption2->ivr_menu_uuid = $ivr_menu_uuid;
                           $vIvrMenuOption2->ivr_menu_option_uuid = $security->uuid();
                           $vIvrMenuOption2->ivr_menu_option_action = "menu-exec-app";
                           $vIvrMenuOption2->ivr_menu_option_param = "transfer 3002 XML " . $fqdn;
                           $vIvrMenuOption2->ivr_menu_option_order = 1;
                           $vIvrMenuOption2->ivr_menu_option_digits = "2";
                           $vIvrMenuOption2->ivr_menu_option_enabled = 1;
                           if (!$vIvrMenuOption2->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenuOption2->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1006)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenuOption3 = new VIvrMenuOptions();
                           $vIvrMenuOption3->domain_uuid = $domain_uuid;
                           $vIvrMenuOption3->ivr_menu_uuid = $ivr_menu_uuid;
                           $vIvrMenuOption3->ivr_menu_option_uuid = $security->uuid();
                           $vIvrMenuOption3->ivr_menu_option_action = "menu-exec-app";
                           $vIvrMenuOption3->ivr_menu_option_param = "transfer 3003 XML " . $fqdn;
                           $vIvrMenuOption3->ivr_menu_option_order = 2;
                           $vIvrMenuOption3->ivr_menu_option_digits = "3";
                           $vIvrMenuOption3->ivr_menu_option_enabled = 1;
                           if (!$vIvrMenuOption3->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenuOption3->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1006)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenuOption4 = new VIvrMenuOptions();
                           $vIvrMenuOption4->domain_uuid = $domain_uuid;
                           $vIvrMenuOption4->ivr_menu_uuid = $ivr_menu_uuid;
                           $vIvrMenuOption4->ivr_menu_option_uuid = $security->uuid();
                           $vIvrMenuOption4->ivr_menu_option_action = "menu-exec-app";
                           $vIvrMenuOption4->ivr_menu_option_param = "transfer 3004 XML " . $fqdn;
                           $vIvrMenuOption4->ivr_menu_option_order = 3;
                           $vIvrMenuOption4->ivr_menu_option_digits = "4";
                           $vIvrMenuOption4->ivr_menu_option_enabled = 1;
                           if (!$vIvrMenuOption4->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenuOption4->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1006)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenuOption5 = new VIvrMenuOptions();
                           $vIvrMenuOption5->domain_uuid = $domain_uuid;
                           $vIvrMenuOption5->ivr_menu_uuid = $ivr_menu_uuid;
                           $vIvrMenuOption5->ivr_menu_option_uuid = $security->uuid();
                           $vIvrMenuOption5->ivr_menu_option_action = "menu-exec-app";
                           $vIvrMenuOption5->ivr_menu_option_param = "transfer 3005 XML " . $fqdn;
                           $vIvrMenuOption5->ivr_menu_option_order = 4;
                           $vIvrMenuOption5->ivr_menu_option_digits = "5";
                           $vIvrMenuOption5->ivr_menu_option_enabled = 1;
                           if (!$vIvrMenuOption5->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenuOption5->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1006)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenu2 = new VIvrMenus();
                           $ivr_menu_uuid2 = $security->uuid();
                           $dialplan_uuid2 = $security->uuid();
                           $vIvrMenu2->ivr_menu_uuid = $ivr_menu_uuid2;
                           $vIvrMenu2->domain_uuid = $domain_uuid;
                           $vIvrMenu2->dialplan_uuid = $dialplan_uuid2;
                           $vIvrMenu2->ivr_menu_extension = "3001";
                           $vIvrMenu2->ivr_menu_name = "Rating 1";
                           $vIvrMenu2->ivr_menu_timeout = 2000;
                           $vIvrMenu2->ivr_menu_greet_long = "you_have-chosen-one_.wav";
                           $vIvrMenu2->ivr_menu_parent_uuid = null;
                           $vIvrMenu2->ivr_menu_language = "";
                           $vIvrMenu2->ivr_menu_dialect = "us";
                           $vIvrMenu2->ivr_menu_voice = "callie";
                           $vIvrMenu2->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                           $vIvrMenu2->ivr_menu_exit_sound = "";
                           $vIvrMenu2->ivr_menu_pin_number = "";
                           $vIvrMenu2->ivr_menu_confirm_macro = "";
                           $vIvrMenu2->ivr_menu_confirm_key = "";
                           $vIvrMenu2->ivr_menu_tts_engine = "flite";
                           $vIvrMenu2->ivr_menu_tts_voice = "rms";
                           $vIvrMenu2->ivr_menu_confirm_attempts = 1;
                           $vIvrMenu2->ivr_menu_exit_app = null;
                           $vIvrMenu2->ivr_menu_exit_data = null;
                           $vIvrMenu2->ivr_menu_inter_digit_timeout = 2000;
                           $vIvrMenu2->ivr_menu_max_failures = 1;
                           $vIvrMenu2->ivr_menu_max_timeouts = 1;
                           $vIvrMenu2->ivr_menu_digit_len = 5;
                           $vIvrMenu2->ivr_menu_direct_dial = "false";
                           $vIvrMenu2->ivr_menu_ringback = "local_stream://default";
                           $vIvrMenu2->ivr_menu_cid_prefix = "";
                           $vIvrMenu->ivr_menu_context = $fqdn;
                           $vIvrMenu2->ivr_menu_enabled = "true";
                           $vIvrMenu2->ivr_menu_description = "";
                           $vIvrMenu2->insert_date = date("Y-m-d H:i:s");
                           if (!$vIvrMenu2->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenu2->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1004)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDialplan2 = new VDialplans();
                           $vDialplan2->domain_uuid = $domain_uuid;
                           $vDialplan2->dialplan_uuid = $dialplan_uuid2;
                           $vDialplan2->app_uuid = $security->uuid();
                           $vDialplan2->dialplan_name = "Rating 1";
                           $vDialplan2->dialplan_number = "3001";
                           $vDialplan2->dialplan_order = 81;
                           $vDialplan2->dialplan_context = $fqdn;
                           $vDialplan2->dialplan_continue = "false";
                           $vDialplan2->dialplan_xml =
                              '<extension name="Rating 1" continue="false" uuid="' .
                              $dialplan_uuid2 .
                              '">
											<condition field="destination_number" expression="^3001$">
												<action application="ring_ready" data=""/>
												<action application="answer" data=""/>
												<action application="sleep" data="1000"/>
												<action application="set" data="hangup_after_bridge=true"/>
												<action application="set" data="ringback=local_stream://default"/>
												<action application="set" data="transfer_ringback=local_stream://default"/>
												<action application="set" data="ivr_menu_uuid=' .
                              $ivr_menu_uuid2 .
                              '"/>
												<action application="ivr" data="' .
                              $ivr_menu_uuid2 .
                              '"/>
												<action application="hangup" data=""/>
											</condition>
                              </extension>';
                           $vDialplan2->dialplan_enabled = "true";
                           $vDialplan2->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan2->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan2->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenu3 = new VIvrMenus();
                           $ivr_menu_uuid3 = $security->uuid();
                           $dialplan_uuid3 = $security->uuid();
                           $vIvrMenu3->ivr_menu_uuid = $ivr_menu_uuid3;
                           $vIvrMenu3->domain_uuid = $domain_uuid;
                           $vIvrMenu3->dialplan_uuid = $dialplan_uuid3;
                           $vIvrMenu3->ivr_menu_extension = "3002";
                           $vIvrMenu3->ivr_menu_name = "Rating 2";
                           $vIvrMenu3->ivr_menu_timeout = 2000;
                           $vIvrMenu3->ivr_menu_greet_long = "you_have-chosen-two_.wav";
                           $vIvrMenu3->ivr_menu_parent_uuid = null;
                           $vIvrMenu3->ivr_menu_language = "";
                           $vIvrMenu3->ivr_menu_dialect = "us";
                           $vIvrMenu3->ivr_menu_voice = "callie";
                           $vIvrMenu3->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                           $vIvrMenu3->ivr_menu_exit_sound = "";
                           $vIvrMenu3->ivr_menu_pin_number = "";
                           $vIvrMenu3->ivr_menu_confirm_macro = "";
                           $vIvrMenu3->ivr_menu_confirm_key = "";
                           $vIvrMenu3->ivr_menu_tts_engine = "flite";
                           $vIvrMenu3->ivr_menu_tts_voice = "rms";
                           $vIvrMenu3->ivr_menu_confirm_attempts = 1;
                           $vIvrMenu3->ivr_menu_exit_app = null;
                           $vIvrMenu3->ivr_menu_exit_data = null;
                           $vIvrMenu3->ivr_menu_inter_digit_timeout = 2000;
                           $vIvrMenu3->ivr_menu_max_failures = 1;
                           $vIvrMenu3->ivr_menu_max_timeouts = 1;
                           $vIvrMenu3->ivr_menu_digit_len = 5;
                           $vIvrMenu3->ivr_menu_direct_dial = "false";
                           $vIvrMenu3->ivr_menu_ringback = "local_stream://default";
                           $vIvrMenu3->ivr_menu_cid_prefix = "";
                           $vIvrMenu3->ivr_menu_context = $fqdn;
                           $vIvrMenu3->ivr_menu_enabled = "true";
                           $vIvrMenu3->ivr_menu_description = "";
                           $vIvrMenu3->insert_date = date("Y-m-d H:i:s");
                           if (!$vIvrMenu3->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenu->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1004)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDialplan3 = new VDialplans();
                           $vDialplan3->domain_uuid = $domain_uuid;
                           $vDialplan3->dialplan_uuid = $dialplan_uuid3;
                           $vDialplan3->app_uuid = $security->uuid();
                           $vDialplan3->dialplan_name = "Rating 2";
                           $vDialplan3->dialplan_number = "3002";
                           $vDialplan3->dialplan_order = 81;
                           $vDialplan3->dialplan_context = $fqdn;
                           $vDialplan3->dialplan_continue = "false";
                           $vDialplan3->dialplan_xml =
                              '<extension name="Rating 2" continue="false" uuid="' .
                              $dialplan_uuid3 .
                              '">
											<condition field="destination_number" expression="^3002$">
												<action application="ring_ready" data=""/>
												<action application="answer" data=""/>
												<action application="sleep" data="1000"/>
												<action application="set" data="hangup_after_bridge=true"/>
												<action application="set" data="ringback=local_stream://default"/>
												<action application="set" data="transfer_ringback=local_stream://default"/>
												<action application="set" data="ivr_menu_uuid=' .
                              $ivr_menu_uuid3 .
                              '"/>
												<action application="ivr" data="' .
                              $ivr_menu_uuid3 .
                              '"/>
												<action application="hangup" data=""/>
											</condition>
                              </extension>';
                           $vDialplan3->dialplan_enabled = "true";
                           $vDialplan3->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan3->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan2->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenu4 = new VIvrMenus();
                           $ivr_menu_uuid4 = $security->uuid();
                           $dialplan_uuid4 = $security->uuid();
                           $vIvrMenu4->ivr_menu_uuid = $ivr_menu_uuid4;
                           $vIvrMenu4->domain_uuid = $domain_uuid;
                           $vIvrMenu4->dialplan_uuid = $dialplan_uuid4;
                           $vIvrMenu4->ivr_menu_extension = "3003";
                           $vIvrMenu4->ivr_menu_name = "Rating 3";
                           $vIvrMenu4->ivr_menu_timeout = 2000;
                           $vIvrMenu4->ivr_menu_greet_long = "you_have-chosen-thre.wav";
                           $vIvrMenu4->ivr_menu_parent_uuid = null;
                           $vIvrMenu4->ivr_menu_language = "";
                           $vIvrMenu4->ivr_menu_dialect = "us";
                           $vIvrMenu4->ivr_menu_voice = "callie";
                           $vIvrMenu4->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                           $vIvrMenu4->ivr_menu_exit_sound = "";
                           $vIvrMenu4->ivr_menu_pin_number = "";
                           $vIvrMenu4->ivr_menu_confirm_macro = "";
                           $vIvrMenu4->ivr_menu_confirm_key = "";
                           $vIvrMenu4->ivr_menu_tts_engine = "flite";
                           $vIvrMenu4->ivr_menu_tts_voice = "rms";
                           $vIvrMenu4->ivr_menu_confirm_attempts = 1;
                           $vIvrMenu4->ivr_menu_exit_app = null;
                           $vIvrMenu4->ivr_menu_exit_data = null;
                           $vIvrMenu4->ivr_menu_inter_digit_timeout = 2000;
                           $vIvrMenu4->ivr_menu_max_failures = 1;
                           $vIvrMenu4->ivr_menu_max_timeouts = 1;
                           $vIvrMenu4->ivr_menu_digit_len = 5;
                           $vIvrMenu4->ivr_menu_direct_dial = "false";
                           $vIvrMenu4->ivr_menu_ringback = "local_stream://default";
                           $vIvrMenu4->ivr_menu_cid_prefix = "";
                           $vIvrMenu4->ivr_menu_context = $fqdn;
                           $vIvrMenu4->ivr_menu_enabled = "true";
                           $vIvrMenu4->ivr_menu_description = "";
                           $vIvrMenu4->insert_date = date("Y-m-d H:i:s");
                           if (!$vIvrMenu4->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenu4->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1004)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDialplan4 = new VDialplans();
                           $vDialplan4->domain_uuid = $domain_uuid;
                           $vDialplan4->dialplan_uuid = $dialplan_uuid4;
                           $vDialplan4->app_uuid = $security->uuid();
                           $vDialplan4->dialplan_name = "Rating 3";
                           $vDialplan4->dialplan_number = "3003";
                           $vDialplan4->dialplan_order = 81;
                           $vDialplan4->dialplan_context = $fqdn;
                           $vDialplan4->dialplan_continue = "false";
                           $vDialplan4->dialplan_xml =
                              '<extension name="Rating 3" continue="false" uuid="' .
                              $dialplan_uuid4 .
                              '">
											<condition field="destination_number" expression="^3003$">
												<action application="ring_ready" data=""/>
												<action application="answer" data=""/>
												<action application="sleep" data="1000"/>
												<action application="set" data="hangup_after_bridge=true"/>
												<action application="set" data="ringback=local_stream://default"/>
												<action application="set" data="transfer_ringback=local_stream://default"/>
												<action application="set" data="ivr_menu_uuid=' .
                              $ivr_menu_uuid4 .
                              '"/>
												<action application="ivr" data="' .
                              $ivr_menu_uuid4 .
                              '"/>
												<action application="hangup" data=""/>
											</condition>
                              </extension>';
                           $vDialplan4->dialplan_enabled = "true";
                           $vDialplan4->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan4->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan4->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenu5 = new VIvrMenus();
                           $ivr_menu_uuid5 = $security->uuid();
                           $dialplan_uuid5 = $security->uuid();
                           $vIvrMenu5->ivr_menu_uuid = $ivr_menu_uuid5;
                           $vIvrMenu5->domain_uuid = $domain_uuid;
                           $vIvrMenu5->dialplan_uuid = $dialplan_uuid5;
                           $vIvrMenu5->ivr_menu_extension = "3004";
                           $vIvrMenu5->ivr_menu_name = "Rating 4";
                           $vIvrMenu5->ivr_menu_timeout = 2000;
                           $vIvrMenu5->ivr_menu_greet_long = "you_have-chosen-four.wav";
                           $vIvrMenu5->ivr_menu_parent_uuid = null;
                           $vIvrMenu5->ivr_menu_language = "";
                           $vIvrMenu5->ivr_menu_dialect = "us";
                           $vIvrMenu5->ivr_menu_voice = "callie";
                           $vIvrMenu5->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                           $vIvrMenu5->ivr_menu_exit_sound = "";
                           $vIvrMenu5->ivr_menu_pin_number = "";
                           $vIvrMenu5->ivr_menu_confirm_macro = "";
                           $vIvrMenu5->ivr_menu_confirm_key = "";
                           $vIvrMenu5->ivr_menu_tts_engine = "flite";
                           $vIvrMenu5->ivr_menu_tts_voice = "rms";
                           $vIvrMenu5->ivr_menu_confirm_attempts = 1;
                           $vIvrMenu5->ivr_menu_exit_app = null;
                           $vIvrMenu5->ivr_menu_exit_data = null;
                           $vIvrMenu5->ivr_menu_inter_digit_timeout = 2000;
                           $vIvrMenu5->ivr_menu_max_failures = 1;
                           $vIvrMenu5->ivr_menu_max_timeouts = 1;
                           $vIvrMenu5->ivr_menu_digit_len = 5;
                           $vIvrMenu5->ivr_menu_direct_dial = "false";
                           $vIvrMenu5->ivr_menu_ringback = "local_stream://default";
                           $vIvrMenu5->ivr_menu_cid_prefix = "";
                           $vIvrMenu5->ivr_menu_context = $fqdn;
                           $vIvrMenu5->ivr_menu_enabled = "true";
                           $vIvrMenu5->ivr_menu_description = "";
                           $vIvrMenu5->insert_date = date("Y-m-d H:i:s");
                           if (!$vIvrMenu5->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenu5->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1004)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDialplan5 = new VDialplans();
                           $vDialplan5->domain_uuid = $domain_uuid;
                           $vDialplan5->dialplan_uuid = $dialplan_uuid5;
                           $vDialplan5->app_uuid = $security->uuid();
                           $vDialplan5->dialplan_name = "Rating 4";
                           $vDialplan5->dialplan_number = "3004";
                           $vDialplan5->dialplan_order = 81;
                           $vDialplan5->dialplan_context = $fqdn;
                           $vDialplan5->dialplan_continue = "false";
                           $vDialplan5->dialplan_xml =
                              '<extension name="Rating 4" continue="false" uuid="' .
                              $dialplan_uuid5 .
                              '">
											<condition field="destination_number" expression="^3004$">
												<action application="ring_ready" data=""/>
												<action application="answer" data=""/>
												<action application="sleep" data="1000"/>
												<action application="set" data="hangup_after_bridge=true"/>
												<action application="set" data="ringback=local_stream://default"/>
												<action application="set" data="transfer_ringback=local_stream://default"/>
												<action application="set" data="ivr_menu_uuid=' .
                              $ivr_menu_uuid5 .
                              '"/>
												<action application="ivr" data="' .
                              $ivr_menu_uuid5 .
                              '"/>
												<action application="hangup" data=""/>
											</condition>
                              </extension>';
                           $vDialplan5->dialplan_enabled = "true";
                           $vDialplan5->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan5->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan5->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vIvrMenu6 = new VIvrMenus();
                           $ivr_menu_uuid6 = $security->uuid();
                           $dialplan_uuid6 = $security->uuid();
                           $vIvrMenu6->ivr_menu_uuid = $ivr_menu_uuid6;
                           $vIvrMenu6->domain_uuid = $domain_uuid;
                           $vIvrMenu6->dialplan_uuid = $dialplan_uuid6;
                           $vIvrMenu6->ivr_menu_extension = "3005";
                           $vIvrMenu6->ivr_menu_name = "Rating 5";
                           $vIvrMenu6->ivr_menu_timeout = 2000;
                           $vIvrMenu6->ivr_menu_greet_long = "you_have-chosen-five.wav";
                           $vIvrMenu6->ivr_menu_parent_uuid = null;
                           $vIvrMenu6->ivr_menu_language = "";
                           $vIvrMenu6->ivr_menu_dialect = "us";
                           $vIvrMenu6->ivr_menu_voice = "callie";
                           $vIvrMenu6->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                           $vIvrMenu6->ivr_menu_exit_sound = "";
                           $vIvrMenu6->ivr_menu_pin_number = "";
                           $vIvrMenu6->ivr_menu_confirm_macro = "";
                           $vIvrMenu6->ivr_menu_confirm_key = "";
                           $vIvrMenu6->ivr_menu_tts_engine = "flite";
                           $vIvrMenu6->ivr_menu_tts_voice = "rms";
                           $vIvrMenu6->ivr_menu_confirm_attempts = 1;
                           $vIvrMenu6->ivr_menu_exit_app = null;
                           $vIvrMenu6->ivr_menu_exit_data = null;
                           $vIvrMenu6->ivr_menu_inter_digit_timeout = 2000;
                           $vIvrMenu6->ivr_menu_max_failures = 1;
                           $vIvrMenu6->ivr_menu_max_timeouts = 1;
                           $vIvrMenu6->ivr_menu_digit_len = 5;
                           $vIvrMenu6->ivr_menu_direct_dial = "false";
                           $vIvrMenu6->ivr_menu_ringback = "local_stream://default";
                           $vIvrMenu6->ivr_menu_cid_prefix = "";
                           $vIvrMenu6->ivr_menu_context = $fqdn;
                           $vIvrMenu6->ivr_menu_enabled = "true";
                           $vIvrMenu6->ivr_menu_description = "";
                           $vIvrMenu6->insert_date = date("Y-m-d H:i:s");
                           if (!$vIvrMenu6->create()) {
                              $this->db->rollback();
                              $messages = $vIvrMenu6->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1004)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vDialplan6 = new VDialplans();
                           $vDialplan6->domain_uuid = $domain_uuid;
                           $vDialplan6->dialplan_uuid = $dialplan_uuid6;
                           $vDialplan6->app_uuid = $security->uuid();
                           $vDialplan6->dialplan_name = "Rating 5";
                           $vDialplan6->dialplan_number = "3005";
                           $vDialplan6->dialplan_order = 81;
                           $vDialplan6->dialplan_context = $fqdn;
                           $vDialplan6->dialplan_continue = "false";
                           $vDialplan6->dialplan_xml =
                              '<extension name="Rating 5" continue="false" uuid="' .
                              $dialplan_uuid6 .
                              '">
											<condition field="destination_number" expression="^3005$">
												<action application="ring_ready" data=""/>
												<action application="answer" data=""/>
												<action application="sleep" data="1000"/>
												<action application="set" data="hangup_after_bridge=true"/>
												<action application="set" data="ringback=local_stream://default"/>
												<action application="set" data="transfer_ringback=local_stream://default"/>
												<action application="set" data="ivr_menu_uuid=' .
                              $ivr_menu_uuid6 .
                              '"/>
												<action application="ivr" data="' .
                              $ivr_menu_uuid6 .
                              '"/>
												<action application="hangup" data=""/>
											</condition>
                              </extension>';
                           $vDialplan6->dialplan_enabled = "true";
                           $vDialplan6->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan6->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan6->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $dialplan_uuid7 = $security->uuid();
                           $vDialplan7 = new VDialplans();
                           $vDialplan7->domain_uuid = $domain_uuid;
                           $vDialplan7->dialplan_uuid = $dialplan_uuid7;
                           $vDialplan7->app_uuid = $security->uuid();
                           $vDialplan7->dialplan_name = "AI External";
                           $vDialplan7->dialplan_number = "7777";
                           $vDialplan7->dialplan_order = 81;
                           $vDialplan7->dialplan_context = $fqdn;
                           $vDialplan7->dialplan_continue = "false";
                           $vDialplan7->dialplan_xml =
                              '<extension name="AI External" continue="false" uuid="' .
                              $dialplan_uuid7 .
                              '">
                                 <condition field="destination_number" expression="^7777$">
                                    <action application="answer" data=""/>
                                    <action application="set" data="STREAM_BUFFER_SIZE=40"/>
                                    <action application="set" data="record_sample_rate=16000"/>
                                    <action application="set" data="RECORD_STEREO=false"/>
                                    <action application="sleep" data="1000"/>
                                    <action application="lua" data="ivr_ai2.lua ${uuid} ${domain_name}"/>
                                    <action application="sleep" data="180000"/>
                                 </condition>
                              </extension>';
                           $vDialplan7->dialplan_enabled = "true";
                           $vDialplan7->insert_date = date("Y-m-d H:i:s");
                           if (!$vDialplan7->create()) {
                              $this->db->rollback();
                              $messages = $vDialplan7->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code IV1005)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qAuxcodes = new QAuxcodes();
                           $qAuxcodes->auxcode_uuid = $security->uuid();
                           $qAuxcodes->domain_uuid = $domain_uuid;
                           $qAuxcodes->username = $username;
                           $qAuxcodes->extension = $extension;
                           $qAuxcodes->user_uuid = $user_uuid;
                           $qAuxcodes->auxcode_status = "available";
                           $qAuxcodes->insert_date = date("Y-m-d H:i:s");
                           if (!$qAuxcodes->create()) {
                              $this->db->rollback();
                              $messages = $qAuxcodes->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AA1002)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qAuxcodes2 = new QAuxcodes();
                           $qAuxcodes2->auxcode_uuid = $security->uuid();
                           $qAuxcodes2->domain_uuid = $domain_uuid;
                           $qAuxcodes2->username = $username;
                           $qAuxcodes2->extension = $extension;
                           $qAuxcodes2->user_uuid = $user_uuid;
                           $qAuxcodes2->auxcode_status = "auxcode 1";
                           $qAuxcodes2->insert_date = date("Y-m-d H:i:s");
                           if (!$qAuxcodes2->create()) {
                              $this->db->rollback();
                              $messages = $qAuxcodes2->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AA1002)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qAuxcodes3 = new QAuxcodes();
                           $qAuxcodes3->auxcode_uuid = $security->uuid();
                           $qAuxcodes3->domain_uuid = $domain_uuid;
                           $qAuxcodes3->username = $username;
                           $qAuxcodes3->extension = $extension;
                           $qAuxcodes3->user_uuid = $user_uuid;
                           $qAuxcodes3->auxcode_status = "auxcode 2";
                           $qAuxcodes3->insert_date = date("Y-m-d H:i:s");
                           if (!$qAuxcodes3->create()) {
                              $this->db->rollback();
                              $messages = $qAuxcodes3->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AA1002)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qWrapupnote = new QWrapupnotes();
                           $qWrapupnote->wrapup_uuid = $security->uuid();
                           $qWrapupnote->domain_uuid = $domain_uuid;
                           $qWrapupnote->username = $username;
                           $qWrapupnote->extension = $extension;
                           $qWrapupnote->user_uuid = $user_uuid;
                           $qWrapupnote->wrapup_note = "General";
                           $qWrapupnote->insert_date = date("Y-m-d H:i:s");
                           if (!$qWrapupnote->create()) {
                              $messages = $qWrapupnote->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AW1002)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vCallCenterAgent = new VCallCenterAgents();
                           $vCallCenterAgent->call_center_agent_uuid = $security->uuid();
                           $vCallCenterAgent->domain_uuid = $domain_uuid;
                           $vCallCenterAgent->user_uuid = $user_uuid;
                           $vCallCenterAgent->agent_name = $firstname . " " . $lastname;
                           $vCallCenterAgent->agent_type = "callback";
                           $vCallCenterAgent->agent_call_timeout = 30;
                           $vCallCenterAgent->agent_id = $extension;
                           $vCallCenterAgent->agent_contact = "user/" . $extension . "@" . $fqdn;
                           $vCallCenterAgent->agent_status = "Available";
                           $vCallCenterAgent->agent_max_no_answer = 120;
                           if (!$vCallCenterAgent->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1010)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qSecurityRule = new QSecurityRules();
                           $qSecurityRule->domain_uuid = $domain_uuid;
                           $qSecurityRule->security_uuid = $security->uuid();
                           if (!$qSecurityRule->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1011)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qSecurityRestrictions = new QSecurityRestrictions();
                           $qSecurityRestrictions->domain_uuid = $domain_uuid;
                           $qSecurityRestrictions->security_uuid = $security->uuid();
                           if (!$qSecurityRestrictions->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1011)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qTimeSchedule = new QTimeSchedules();
                           $qTimeSchedule->domain_uuid = $domain_uuid;
                           $qTimeSchedule->update_user = $user_uuid;
                           $qTimeSchedule->update_date = date("Y-m-d H:i:s");
                           $qTimeSchedule->insert_user = $user_uuid;
                           $qTimeSchedule->insert_date = date("Y-m-d H:i:s");
                           if (!$qTimeSchedule->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1011)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $sipLogin = $this->redis->hget("verify-" . $parameters["email"], "sipLogin");
                           $sipPassword = $this->redis->hget("verify-" . $parameters["email"], "sipPassword");
                           $proxy = $this->redis->hget("verify-" . $parameters["email"], "proxy");
                           $gateway = $this->redis->hget("verify-" . $parameters["email"], "gateway");
                           $did = $this->redis->hget("verify-" . $parameters["email"], "did");

                           // Trunk fields (stored during provision, needed for commio SIP trunk creation)
                           $parameters["trunkName"]            = $this->redis->hget("verify-" . $parameters["email"], "trunkName") ?: '';
                           $parameters["typeOfAuthentication"] = $this->redis->hget("verify-" . $parameters["email"], "typeOfAuthentication") ?: '';
                           $parameters["username"]             = $this->redis->hget("verify-" . $parameters["email"], "username") ?: '';
                           $parameters["password"]             = $this->redis->hget("verify-" . $parameters["email"], "password") ?: '';
                           $parameters["authUsername"]         = $this->redis->hget("verify-" . $parameters["email"], "authUsername") ?: '';
                           $parameters["proxyHostNameIp"]      = $this->redis->hget("verify-" . $parameters["email"], "proxyHostNameIp") ?: '';
                           $parameters["sipPort"]              = $this->redis->hget("verify-" . $parameters["email"], "sipPort") ?: '';
                           $parameters["sipProfile"]           = $this->redis->hget("verify-" . $parameters["email"], "sipProfile") ?: '';
                           $parameters["outboundCallerId"]     = $this->redis->hget("verify-" . $parameters["email"], "outboundCallerId") ?: '';
                           $parameters["countryDid"]           = $this->redis->hget("verify-" . $parameters["email"], "countryDid") ?: '';

                           // Outbound rule SIP identity / CID type (from SipSettingsInternal 153, passed through provision).
                           $outboundSipFromUser        = trim((string) ($this->redis->hget("verify-" . $parameters["email"], "outboundSipFromUser") ?: ''));
                           $outboundSipFromDisplayName = trim((string) ($this->redis->hget("verify-" . $parameters["email"], "outboundSipFromDisplayName") ?: ''));
                           $outboundSipFromHost        = trim((string) ($this->redis->hget("verify-" . $parameters["email"], "outboundSipFromHost") ?: ''));
                           $outboundCidType            = trim((string) ($this->redis->hget("verify-" . $parameters["email"], "outboundCidType") ?: ''));

                           $didOriginal = $did;
                           $isCommio = isset($parameters["trunkName"]) && $parameters["trunkName"] == "commio";
                           if ($did && $isCommio && false) { // disabled: DIDs are created only for commio registrations — see the commio link-DID block below
                              $did = trim($did);
                              $dids = explode(",", $did);
                              foreach ($dids as $key => $did) {
                                 $qDid = new QDids();
                                 $qDid->did_uuid = $security->uuid();
                                 $qDid->domain_uuid = $domain_uuid;
                                 $qDid->did_number = strval(intval($did));
                                 $qDid->type = "3";
                                 $qDid->name = $firstname . " " . $lastname;
                                 $qDid->extension = strval($extension);
                                 $qDid->outbound = 1;
                                 $qDid->insert_user = $user_uuid;
                                 $qDid->insert_date = date("Y-m-d H:i:s");
                                 if (!$qDid->create()) {
                                    $this->db->rollback();
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => "An error has occurred. (Code RV1012)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                                 $destination_uuid = $security->uuid();
                                 $destination_dialplan_uuid = $security->uuid();
                                 $vDestination = new VDestinations();
                                 $vDestination->domain_uuid = $domain_uuid;
                                 $vDestination->destination_uuid = $destination_uuid;
                                 $vDestination->dialplan_uuid = $destination_dialplan_uuid;
                                 $vDestination->destination_type = "inbound";
                                 $vDestination->destination_number = strval(intval($did));
                                 $vDestination->destination_number_regex = "^(" . strval(intval($did)) . ")$";
                                 $vDestination->destination_context = "public";
                                 $vDestination->destination_record = "true";
                                 $vDestination->destination_hold_music = "local_stream://default";
                                 $vDestination->destination_actions = '[{"destination_app":"transfer","destination_data":"' . strval($extension) . ' XML ' . $fqdn . '"}] ';
                                 $vDestination->destination_order = 0;
                                 $vDestination->destination_enabled = "true";
                                 if (!$vDestination->create()) {
                                    $this->db->rollback();
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => "An error has occurred. (Code RV1013)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                                 $vDestinationDialplan = new VDialplans();
                                 $vDestinationDialplan->domain_uuid = $domain_uuid;
                                 $vDestinationDialplan->dialplan_uuid = $destination_dialplan_uuid;
                                 $vDestinationDialplan->app_uuid = $security->uuid();
                                 $vDestinationDialplan->dialplan_context = "public";
                                 $vDestinationDialplan->dialplan_name = strval(intval($did));
                                 $vDestinationDialplan->dialplan_number = strval(intval($did));
                                 $vDestinationDialplan->dialplan_continue = "true";
                                 $vDestinationDialplan->dialplan_xml = '<extension name="' . strval(intval($did)) . '" continue="false" uuid="' . $destination_dialplan_uuid . '">
                                 <condition field="destination_number" expression="^\+?(' . strval(intval($did)) . ')$">
                                    <action application="export" data="call_direction=inbound" inline="true"/>
                                    <action application="set" data="domain_uuid=' . $domain_uuid . '" inline="true"/>
                                    <action application="set" data="domain_name=' . $fqdn . '" inline="true"/>
                                    <action application="export" data="hold_music=local_stream://default" inline="true"/>
                                    <action application="transfer" data="' . strval($extension) . ' XML ' . $fqdn . '"/>
                                 </condition>
                                 </extension>';
                                 $vDestinationDialplan->dialplan_order = 0;
                                 $vDestinationDialplan->dialplan_enabled = "true";
                                 if (!$vDestinationDialplan->create()) {
                                    $this->db->rollback();
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => "An error has occurred. (Code RV1014)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                                 $vDestinationDialplanDetails = new VDialplanDetails();
                                 $vDestinationDialplanDetails->domain_uuid = $domain_uuid;
                                 $vDestinationDialplanDetails->dialplan_uuid = $destination_dialplan_uuid;
                                 $vDestinationDialplanDetails->dialplan_detail_uuid = $security->uuid();
                                 $vDestinationDialplanDetails->dialplan_detail_tag = "condition";
                                 $vDestinationDialplanDetails->dialplan_detail_type = "destination_number";
                                 $vDestinationDialplanDetails->dialplan_detail_data = '^(' . strval(intval($did)) . ')$';
                                 $vDestinationDialplanDetails->dialplan_detail_group = 0;
                                 $vDestinationDialplanDetails->dialplan_detail_order = 20;
                                 if (!$vDestinationDialplanDetails->create()) {
                                    $this->db->rollback();
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => "An error has occurred. (Code RV1015)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                                 $vDestinationDialplanDetails2 = new VDialplanDetails();
                                 $vDestinationDialplanDetails2->domain_uuid = $domain_uuid;
                                 $vDestinationDialplanDetails2->dialplan_uuid = $destination_dialplan_uuid;
                                 $vDestinationDialplanDetails2->dialplan_detail_uuid = $security->uuid();
                                 $vDestinationDialplanDetails2->dialplan_detail_tag = "action";
                                 $vDestinationDialplanDetails2->dialplan_detail_type = "transfer";
                                 $vDestinationDialplanDetails2->dialplan_detail_data = strval($extension) . ' XML ' . $fqdn;
                                 $vDestinationDialplanDetails2->dialplan_detail_group = 0;
                                 $vDestinationDialplanDetails2->dialplan_detail_order = 20;
                                 if (!$vDestinationDialplanDetails2->create()) {
                                    $this->db->rollback();
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => "An error has occurred. (Code RV1016)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }
                           }
                           // Create extensions for each DID (1001, 1002, ... — 1000 is admin, not a DID ext) — commio registrations only
                           if ($didOriginal && $isCommio) {
                              $allDidsForExt = explode(",", trim($didOriginal));
                              $totalDids = count($allDidsForExt);

                              if ($totalDids > 0) {
                                 // First DID belongs to the owner (ext 1000) — create extensions only for the rest.
                                 for ($i = 1; $i < $totalDids; $i++) {
                                    $newExtNumber   = intval($extension) + $i; // i=1 -> 1001, i=2 -> 1002 ...
                                    $extFirstname   = "User";
                                    $extLastname    = "Extension " . $i;
                                    $extEmail       = "user" . $i . "@ringq.com";
                                    $extUserUuid    = $security->uuid();
                                    $extExtUuid     = $security->uuid();
                                    $extVmUuid      = $security->uuid();
                                    $extUsername     = $security->hex(10);
                                    $extPassword2   = $security->base64(16);
                                    $extPassword    = $this->security->hash($extPassword2);
                                    $extExtPassword = $security->base64(16);

                                    // QUsers
                                    $extQUser = new QUsers();
                                    $extQUser->user_uuid         = $extUserUuid;
                                    $extQUser->domain_uuid       = $domain_uuid;
                                    $extQUser->first_name        = $extFirstname;
                                    $extQUser->last_name         = $extLastname;
                                    $extQUser->extension_enabled = true;
                                    $extQUser->insert_date       = date("Y-m-d H:i:s");
                                    $extQUser->user_email        = $extEmail;
                                    $extQUser->password          = $extPassword2;
                                    if (!$extQUser->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_Q)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // VUsers
                                    $extVUser = new VUsers();
                                    $extVUser->username     = $extUsername;
                                    $extVUser->user_uuid    = $extUserUuid;
                                    $extVUser->domain_uuid  = $domain_uuid;
                                    $extVUser->user_email   = $extEmail;
                                    $extVUser->password     = $extPassword;
                                    $extVUser->user_status  = "Logged Out";
                                    $extVUser->user_enabled = "true";
                                    $extVUser->api_key      = $security->base64(32);
                                    $extVUser->image        = null;
                                    $extVUser->first_name   = $extFirstname;
                                    $extVUser->last_name    = $extLastname;
                                    $extVUser->insert_user  = $extUserUuid;
                                    $extVUser->insert_date  = date("Y-m-d H:i:s");
                                    if (!$extVUser->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_V)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // VExtensions
                                    $extVExt = new VExtensions();
                                    $extVExt->extension_uuid                       = $extExtUuid;
                                    $extVExt->domain_uuid                          = $domain_uuid;
                                    $extVExt->extension                            = strval($newExtNumber);
                                    $extVExt->password                             = $extExtPassword;
                                    $extVExt->number_alias                         = $extUsername;
                                    $extVExt->enabled                              = "true";
                                    $extVExt->extension_type                       = "default";
                                    $extVExt->accountcode                          = $fqdn;
                                    $extVExt->directory_visible                    = "true";
                                    $extVExt->directory_exten_visible              = "true";
                                    $extVExt->limit_max                            = "5";
                                    $extVExt->limit_destination                    = "!USER_BUSY";
                                    $extVExt->effective_caller_id_name             = $extFirstname . " " . $extLastname;
                                    $extVExt->effective_caller_id_number           = strval($newExtNumber);
                                    $extVExt->do_not_disturb                       = "false";
                                    $extVExt->forward_all_enabled                  = "false";
                                    $extVExt->forward_all_enabled2                 = "false";
                                    $extVExt->forward_busy_enabled                 = "false";
                                    $extVExt->forward_busy_enabled2                = "false";
                                    $extVExt->forward_no_answer_enabled            = "true";
                                    $extVExt->forward_no_answer_enabled2           = "true";
                                    $extVExt->forward_user_not_registered_enabled  = "false";
                                    $extVExt->forward_user_not_registered_enabled2 = "false";
                                    $extVExt->forward_no_answer_destination        = "*99" . strval($newExtNumber);
                                    $extVExt->forward_no_answer_destination2       = "*99" . strval($newExtNumber);
                                    $extVExt->voicemail_enabled                    = "enabled";
                                    $extVExt->voicemail_file                       = "attach";
                                    $extVExt->call_timeout                         = 30;
                                    $extVExt->call_timeout2                        = 30;
                                    $extVExt->call_screen_enabled                  = "false";
                                    $extVExt->user_context                         = $fqdn;
                                    $extVExt->voicemail_uuid                       = $extVmUuid;
                                    $extVExt->description                          = $extUsername . " " . $extFirstname . " " . $extLastname . " " . $extEmail;
                                    $extVExt->insert_user                          = $extUserUuid;
                                    $extVExt->insert_date                          = date("Y-m-d H:i:s");
                                    if (!$extVExt->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_E)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // VExtensionUsers
                                    $extVExtUser = new VExtensionUsers();
                                    $extVExtUser->extension_user_uuid = $security->uuid();
                                    $extVExtUser->domain_uuid         = $domain_uuid;
                                    $extVExtUser->user_uuid           = $extUserUuid;
                                    $extVExtUser->extension_uuid      = $extExtUuid;
                                    if (!$extVExtUser->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_EU)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // VVoicemails
                                    $extVVm = new VVoicemails();
                                    $extVVm->domain_uuid                     = $domain_uuid;
                                    $extVVm->voicemail_uuid                  = $extVmUuid;
                                    $extVVm->voicemail_id                    = strval($newExtNumber);
                                    $extVVm->voicemail_mail_to               = $extEmail;
                                    $extVVm->voicemail_password              = strval(rand(1000, 9999));
                                    $extVVm->voicemail_transcription_enabled = "false";
                                    $extVVm->voicemail_file                  = "attach";
                                    $extVVm->voicemail_local_after_email     = "true";
                                    $extVVm->voicemail_enabled               = "true";
                                    if (!$extVVm->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_VM)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // VUserGroups — "user" group (not admin)
                                    $extVUserGroup = new VUserGroups();
                                    $extVUserGroup->user_group_uuid = $security->uuid();
                                    $extVUserGroup->user_uuid       = $extUserUuid;
                                    $extVUserGroupData = VGroups::findFirst([
                                       "conditions" => "group_name = :group_name:",
                                       "bind"       => ["group_name" => "user"],
                                       "bindTypes"  => [Column::BIND_PARAM_STR],
                                    ]);
                                    $extVUserGroup->group_uuid  = $extVUserGroupData->group_uuid;
                                    $extVUserGroup->group_name  = $extVUserGroupData->group_name;
                                    $extVUserGroup->domain_uuid = $domain_uuid;
                                    if (!$extVUserGroup->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_UG)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // VCallCenterAgents
                                    $extAgent = new VCallCenterAgents();
                                    $extAgent->call_center_agent_uuid = $security->uuid();
                                    $extAgent->domain_uuid            = $domain_uuid;
                                    $extAgent->user_uuid              = $extUserUuid;
                                    $extAgent->agent_name             = $extFirstname . " " . $extLastname;
                                    $extAgent->agent_type             = "callback";
                                    $extAgent->agent_call_timeout     = 30;
                                    $extAgent->agent_id               = strval($newExtNumber);
                                    $extAgent->agent_contact          = "user/" . strval($newExtNumber) . "@" . $fqdn;
                                    $extAgent->agent_status           = "Available";
                                    $extAgent->agent_max_no_answer    = 120;
                                    if (!$extAgent->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_AG)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // QCallBehaviors
                                    $extCallBehavior = new QCallBehaviors();
                                    $extCallBehavior->extension_uuid = $extExtUuid;
                                    $extCallBehavior->domain_uuid = $domain_uuid;
                                    $extCallBehavior->extension = strval($newExtNumber);
                                    $extCallBehavior->forward_no_answer_destination = "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . ",";
                                    $extCallBehavior->forward_no_answer_destination2 = "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . "," . "*99" . strval($newExtNumber) . ",";
                                    $extCallBehavior->forward_no_answer_timeout = "30,30,30,30,30";
                                    $extCallBehavior->forward_no_answer_timeout2 = "30,30,30,30,30";
                                    $extCallBehavior->forward_no_answer_enabled = "true,true,true,true,true";
                                    $extCallBehavior->forward_no_answer_enabled2 = "true,true,true,true,true";
                                    $extCallBehavior->type = 1;
                                    $extCallBehavior->enabled = 1;
                                    $extCallBehavior->sso_qr = 1;
                                    $extCallBehavior->sso_number = 1;
                                    $extCallBehavior->call_profiles_colors = "#004D40,#004D40,#004D40,#004D40,#004D40";
                                    $extCallBehavior->insert_user = $extUserUuid;
                                    $extCallBehavior->insert_date = date("Y-m-d H:i:s");
                                    if (!$extCallBehavior->create()) {
                                       $this->db->rollback();
                                       $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV_EXT_CB)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // Per-extension Redis initialization (mirrors admin ext 1000 block)
                                    $extKey = strval($newExtNumber) . "@" . $fqdn;
                                    $this->redis->set(strval($newExtNumber) . "+" . $fqdn, "0");
                                    $this->redis->set(strval($newExtNumber) . "-" . $fqdn . "-permission", "7");

                                    $listsToReset = [
                                       "-forward-all-destination", "-forward-all-destination2",
                                       "-forward-busy-destination", "-forward-busy-destination2",
                                       "-forward-no-answer-destination", "-forward-no-answer-destination2",
                                       "-forward-no-answer-timeout", "-forward-no-answer-timeout2",
                                       "-follow-me-destinations", "-follow-me-destinations2",
                                       "-forward-all-enabled", "-forward-all-enabled2",
                                       "-forward-busy-enabled", "-forward-busy-enabled2",
                                       "-forward-no-answer-enabled", "-forward-no-answer-enabled2",
                                       "-forward-me-enabled", "-forward-me-enabled2",
                                       "-callScreening", "-callScreening2",
                                    ];
                                    foreach ($listsToReset as $suffix) {
                                       $this->redis->del($extKey . $suffix);
                                    }

                                    $empty5     = ["", "", "", "", ""];
                                    $nae5       = ["*99" . $newExtNumber, "*99" . $newExtNumber, "*99" . $newExtNumber, "*99" . $newExtNumber, "*99" . $newExtNumber];
                                    $timeouts5  = ["30", "30", "30", "30", "30"];
                                    $empty15    = array_fill(0, 15, "");
                                    $false5     = ["false", "false", "false", "false", "false"];
                                    $true5      = ["true", "true", "true", "true", "true"];

                                    $this->redis->rpush($extKey . "-forward-all-destination", $empty5);
                                    $this->redis->rpush($extKey . "-forward-all-destination2", $empty5);
                                    $this->redis->rpush($extKey . "-forward-busy-destination", $empty5);
                                    $this->redis->rpush($extKey . "-forward-busy-destination2", $empty5);
                                    $this->redis->rpush($extKey . "-forward-no-answer-destination", $nae5);
                                    $this->redis->rpush($extKey . "-forward-no-answer-destination2", $nae5);
                                    $this->redis->rpush($extKey . "-forward-no-answer-timeout", $timeouts5);
                                    $this->redis->rpush($extKey . "-forward-no-answer-timeout2", $timeouts5);
                                    $this->redis->rpush($extKey . "-follow-me-destinations", $empty15);
                                    $this->redis->rpush($extKey . "-follow-me-destinations2", $empty15);
                                    $this->redis->rpush($extKey . "-forward-all-enabled", $false5);
                                    $this->redis->rpush($extKey . "-forward-all-enabled2", $false5);
                                    $this->redis->rpush($extKey . "-forward-busy-enabled", $false5);
                                    $this->redis->rpush($extKey . "-forward-busy-enabled2", $false5);
                                    $this->redis->rpush($extKey . "-forward-no-answer-enabled", $true5);
                                    $this->redis->rpush($extKey . "-forward-no-answer-enabled2", $true5);
                                    $this->redis->rpush($extKey . "-forward-me-enabled", $false5);
                                    $this->redis->rpush($extKey . "-forward-me-enabled2", $false5);
                                    $this->redis->rpush($extKey . "-callScreening", $false5);
                                    $this->redis->rpush($extKey . "-callScreening2", $false5);
                                    $this->redis->set($newExtNumber . "+" . $fqdn . "-contact-page-enabled", "1");
                                 }
                              }
                           }

                           if ($didOriginal && isset($parameters["trunkName"]) && $parameters["trunkName"] == "commio") {
                              $linkDids = explode(",", trim($didOriginal));
                              foreach ($linkDids as $linkKey => $linkDid) {
                                 $linkDid = trim($linkDid);
                                 if (empty($linkDid)) continue;

                                 // First DID -> owner ext 1000; the rest -> 1001, 1002 ...
                                 $linkExtNumber = ($linkKey == 0) ? intval($extension) : intval($extension) + $linkKey;
                                 $linkDidNumber = strval(intval($linkDid));
                                 $linkDestUuid = $security->uuid();
                                 $linkDpUuid = $security->uuid();

                                 $linkExtName = ($linkKey == 0) ? "Owner" : "User Extension " . $linkKey;
                                 $linkVExt = VExtensions::findFirst([
                                    "conditions" => "domain_uuid = :domain_uuid: AND extension = :ext:",
                                    "bind" => [
                                       "domain_uuid" => $domain_uuid,
                                       "ext" => strval($linkExtNumber),
                                    ],
                                 ]);
                                 if ($linkVExt) {
                                    $linkExtName = $linkVExt->effective_caller_id_name;
                                    // Assign this DID as the extension's outbound caller ID.
                                    // Mirrors AccountsController::extensionAction (7886-7887): both fields carry
                                    // the DID number. $linkDidNumber is the same value written to
                                    // q_dids.did_number below, so a later extensionAction lookup on this DID
                                    // resolves to exactly this value.
                                    $linkVExt->outbound_caller_id_name   = $linkDidNumber;
                                    $linkVExt->outbound_caller_id_number = $linkDidNumber;
                                    if (!$linkVExt->update()) {
                                       $this->db->rollback();
                                       $response = json_encode([
                                          "result" => "error",
                                          "message" => "An error has occurred. (Code RV_DID_CID)",
                                       ]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }

                                    // Seed the per-extension outbound-DID list the Accounts UI reads
                                    // (AccountsController 4927; written in type 4 at 9017-9018).
                                    $this->redis->del("dids-" . strval($linkExtNumber) . "@" . $fqdn);
                                    $this->redis->lpush("dids-" . strval($linkExtNumber) . "@" . $fqdn, [$linkDidNumber]);
                                 }

                                 $qDidLink = new QDids();
                                 $qDidLink->did_uuid = $security->uuid();
                                 $qDidLink->domain_uuid = $domain_uuid;
                                 $qDidLink->did_number = $linkDidNumber;
                                 $qDidLink->type = "3";
                                 $qDidLink->name = $linkExtName;
                                 $qDidLink->extension = strval($linkExtNumber);
                                 // Only the first DID (owner's, = outbound caller ID) is the primary DID.
                                 $qDidLink->outbound = ($linkKey == 0) ? 1 : 0;
                                 $qDidLink->insert_user = $user_uuid;
                                 $qDidLink->insert_date = date("Y-m-d H:i:s");
                                 $qDidLink->sip_trunk_name = strtolower($parameters["trunkName"]);
                                 $countryDids = !empty($parameters["countryDid"]) ? explode(",", $parameters["countryDid"]) : [];
                                 $isoCode = strtoupper(trim($countryDids[$linkKey] ?? ''));
                                 $countryDialPrefixes = [
                                    'US' => '+1', 'CA' => '+1', 'GB' => '+44', 'AU' => '+61',
                                    'SG' => '+65', 'MY' => '+60', 'PH' => '+63', 'IN' => '+91',
                                    'ID' => '+62', 'JP' => '+81', 'KR' => '+82', 'CN' => '+86',
                                    'HK' => '+852', 'TW' => '+886', 'TH' => '+66', 'VN' => '+84',
                                    'DE' => '+49', 'FR' => '+33', 'IT' => '+39', 'ES' => '+34',
                                    'NL' => '+31', 'BE' => '+32', 'CH' => '+41', 'AT' => '+43',
                                    'SE' => '+46', 'NO' => '+47', 'DK' => '+45', 'FI' => '+358',
                                    'IE' => '+353', 'PT' => '+351', 'PL' => '+48', 'CZ' => '+420',
                                    'RO' => '+40', 'HU' => '+36', 'GR' => '+30', 'TR' => '+90',
                                    'RU' => '+7', 'UA' => '+380', 'IL' => '+972', 'AE' => '+971',
                                    'SA' => '+966', 'QA' => '+974', 'KW' => '+965', 'BH' => '+973',
                                    'OM' => '+968', 'JO' => '+962', 'LB' => '+961', 'EG' => '+20',
                                    'ZA' => '+27', 'NG' => '+234', 'KE' => '+254', 'GH' => '+233',
                                    'TZ' => '+255', 'UG' => '+256', 'ET' => '+251', 'SL' => '+232',
                                    'BR' => '+55', 'MX' => '+52', 'AR' => '+54', 'CL' => '+56',
                                    'CO' => '+57', 'PE' => '+51', 'VE' => '+58', 'EC' => '+593',
                                    'NZ' => '+64', 'BD' => '+880', 'PK' => '+92', 'LK' => '+94',
                                    'NP' => '+977', 'MM' => '+95', 'KH' => '+855', 'LA' => '+856',
                                    'PA' => '+507', 'CR' => '+506', 'DO' => '+1', 'PR' => '+1',
                                    'JM' => '+1', 'TT' => '+1', 'BS' => '+1', 'BB' => '+1',
                                 ];
                                 $qDidLink->country = $countryDialPrefixes[$isoCode] ?? '';
                                 $qDidLink->create();

                                 $linkDest = new VDestinations();
                                 $linkDest->domain_uuid = $domain_uuid;
                                 $linkDest->destination_uuid = $linkDestUuid;
                                 $linkDest->dialplan_uuid = $linkDpUuid;
                                 $linkDest->destination_type = "inbound";
                                 $linkDest->destination_number = $linkDidNumber;
                                 $linkDest->destination_number_regex = '^((\+)?' . $linkDidNumber . ')$';
                                 $linkDest->destination_context = "public";
                                 $linkDest->destination_record = "true";
                                 $linkDest->destination_hold_music = "local_stream://default";
                                 $linkDest->destination_actions = '[{"destination_app":"transfer","destination_data":"' . strval($linkExtNumber) . ' XML ' . $fqdn . '"}]';
                                 $linkDest->destination_order = 0;
                                 $linkDest->destination_enabled = "true";
                                 $linkDest->insert_user = $user_uuid;
                                 $linkDest->create();

                                 $linkDialplan = new VDialplans();
                                 $linkDialplan->domain_uuid = $domain_uuid;
                                 $linkDialplan->dialplan_uuid = $linkDpUuid;
                                 $linkDialplan->app_uuid = $security->uuid();
                                 $linkDialplan->dialplan_context = "public";
                                 $linkDialplan->dialplan_name = $linkDidNumber;
                                 $linkDialplan->dialplan_number = $linkDidNumber;
                                 $linkDialplan->dialplan_continue = "true";
                                 $linkDialplan->dialplan_xml = '<extension name="' . $linkDidNumber . '" continue="false" uuid="' . $linkDpUuid . '">
                                 <condition field="destination_number" expression="^\+?(' . str_replace('+', '', $linkDidNumber) . ')$">
                                    <action application="export" data="call_direction=inbound" inline="true"/>
                                    <action application="set" data="domain_uuid=' . $domain_uuid . '" inline="true"/>
                                    <action application="set" data="domain_name=' . $fqdn . '" inline="true"/>
                                    <action application="export" data="hold_music=local_stream://default" inline="true"/>
                                    <action application="transfer" data="' . strval($linkExtNumber) . ' XML ' . $fqdn . '"/>
                                 </condition>
                                 </extension>';
                                 $linkDialplan->dialplan_order = 0;
                                 $linkDialplan->dialplan_enabled = "true";
                                 $linkDialplan->create();

                                 $linkDetail1 = new VDialplanDetails();
                                 $linkDetail1->domain_uuid = $domain_uuid;
                                 $linkDetail1->dialplan_uuid = $linkDpUuid;
                                 $linkDetail1->dialplan_detail_uuid = $security->uuid();
                                 $linkDetail1->dialplan_detail_tag = "condition";
                                 $linkDetail1->dialplan_detail_type = "destination_number";
                                 $linkDetail1->dialplan_detail_data = '^(' . $linkDidNumber . ')$';
                                 $linkDetail1->dialplan_detail_group = 0;
                                 $linkDetail1->dialplan_detail_order = 20;
                                 $linkDetail1->create();

                                 $linkDetail2 = new VDialplanDetails();
                                 $linkDetail2->domain_uuid = $domain_uuid;
                                 $linkDetail2->dialplan_uuid = $linkDpUuid;
                                 $linkDetail2->dialplan_detail_uuid = $security->uuid();
                                 $linkDetail2->dialplan_detail_tag = "action";
                                 $linkDetail2->dialplan_detail_type = "transfer";
                                 $linkDetail2->dialplan_detail_data = strval($linkExtNumber) . ' XML ' . $fqdn;
                                 $linkDetail2->dialplan_detail_group = 0;
                                 $linkDetail2->dialplan_detail_order = 20;
                                 $linkDetail2->create();
                              }

                              // Guarantee exactly one primary DID for the owner: only the first DID stays outbound=1.
                              // ponytail: domain-scoped, a few DIDs per tenant — plain loop is fine
                              $primaryDidNumber = strval(intval(trim(explode(",", $didOriginal)[0])));
                              foreach (QDids::find([
                                 "conditions" => "domain_uuid = :domain_uuid:",
                                 "bind" => ["domain_uuid" => $domain_uuid],
                                 "bindTypes" => [Column::BIND_PARAM_STR],
                              ]) as $domainDid) {
                                 $shouldBePrimary = ($domainDid->did_number == $primaryDidNumber) ? 1 : 0;
                                 if ((int) $domainDid->outbound !== $shouldBePrimary) {
                                    $domainDid->outbound = $shouldBePrimary;
                                    $domainDid->update();
                                 }
                              }
                           }

                           // Commio SIP trunk + outbound rule — created last so the account is fully set up first
                           if (isset($parameters["trunkName"]) && $parameters["trunkName"] == "commio") {
                              // Outbound rule config (from SipSettingsInternal 153) must be complete before we build it.
                              if ($outboundSipFromUser === '' || $outboundSipFromDisplayName === '' || $outboundSipFromHost === '' || $outboundCidType === '') {
                                 $this->db->rollback();
                                 $response = json_encode(["result" => "error", "message" => "Outbound SIP settings are incomplete. (Code RV1023)"]);
                                 $this->response->setContent($response);
                                 return $this->response;
                              }
                              // Owner's outbound caller ID is always the first DID.
                              $ownerDid = strval(intval(trim(explode(",", $didOriginal)[0])));

                              // SIP trunk (mirrors ApiController gatewayAction POST type=1)
                              $commioProxyHost = $parameters["proxyHostNameIp"] ?? '';
                              $commioProxyPort = $parameters["sipPort"] ?? '5060';
                              $commioProxy = !empty($commioProxyPort) ? $commioProxyHost . ':' . $commioProxyPort : $commioProxyHost;

                              $gParams = [];
                              $gParams["gateway"]            = strtolower($parameters["trunkName"]);
                              $gParams["username"]           = $parameters["username"] ?? "";
                              $gParams["auth_username"]      = $parameters["authUsername"] ?? "";
                              $gParams["password"]           = $parameters["password"] ?? "";
                              $gParams["proxy"]              = $commioProxy;
                              $gParams["codec"]              = "PCMA,PCMU,iLBC,G722,G729,OPUS";
                              $gParams["max_calls"]          = 0;
                              $gParams["authentication"]     = filter_var($parameters["typeOfAuthentication"] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 2;
                              $gParams["outbound_caller_id"] = $ownerDid;
                              $gParams["register_transport"] = "UDP";
                              $gParams["expire_seconds"]     = 3600;
                              $gParams["retry_seconds"]      = 180;
                              $gParams["real_network"]       = 0;
                              $gParams["privacy_header"]     = 0;
                              $gParams["from_user"]          = "From User";
                              $gParams["from_display"]       = "From User";
                              $gParams["did_match"]          = "To User";

                              // Look up SIP profile by name (ApiController uses UUID; we have the name)
                              $vSipProfilesGw = null;
                              try {
                                 $vSipProfilesGw = VSipProfiles::findFirst([
                                    "conditions" => "sip_profile_name = :sip_profile_name: AND sip_profile_enabled = :sip_profile_enabled:",
                                    "bind" => [
                                       "sip_profile_name"    => $parameters["sipProfile"] ?? "external",
                                       "sip_profile_enabled" => "true",
                                    ],
                                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                 ]);
                              } catch (\Throwable $e) {}

                              $vGateway = new VGateways();
                              $vGateway->domain_uuid        = $domain_uuid;
                              $vGateway->gateway_uuid       = $security->uuid();
                              $vGateway->gateway            = $gParams["gateway"];
                              $vGateway->username           = $gParams["username"];
                              $vGateway->password           = $gParams["password"];
                              $vGateway->proxy              = $gParams["proxy"];
                              $vGateway->codec_prefs        = $gParams["codec"];
                              $vGateway->expire_seconds     = $gParams["expire_seconds"];
                              $vGateway->auth_username      = isset($gParams["auth_username"]) ? $gParams["auth_username"] : null;
                              $vGateway->realm              = isset($gParams["auth_username"]) ? $gParams["proxy"] : null;
                              $vGateway->register           = $gParams["authentication"] == 2 ? "false " : "true";
                              $vGateway->register_transport = strtolower($gParams["register_transport"]);
                              $vGateway->description        = $gParams["outbound_caller_id"];
                              $vGateway->caller_id_in_from  = !empty($gParams["outbound_caller_id"]) ? "true" : null;
                              $vGateway->retry_seconds      = is_numeric($gParams["retry_seconds"]) ? (int) $gParams["retry_seconds"] : null;
                              $vGateway->channels           = is_numeric($gParams["max_calls"]) ? (int) $gParams["max_calls"] : null;
                              $vGateway->context            = "public";
                              $vGateway->profile            = isset($vSipProfilesGw) ? $vSipProfilesGw->sip_profile_name : "external";
                              $vGateway->enabled            = "true";
                              $vGateway->real_network       = intval($gParams["real_network"]) == 1 ? 1 : 0;
                              $vGateway->privacy_header     = intval($gParams["privacy_header"]) == 1 ? 1 : 0;
                              $vGateway->custom_user        = $gParams["from_user"];
                              $vGateway->custom_display     = $gParams["from_display"];
                              $vGateway->custom_match       = $gParams["did_match"];
                              $vGateway->insert_user        = $user_uuid;
                              if (!$vGateway->create()) {
                                 $this->db->rollback();
                                 $messages = $vGateway->getMessages();
                                 $message = "";
                                 foreach ($messages as $error) {
                                    $message .= $error . " ";
                                 }
                                 $response = json_encode(["result" => "error", "message" => trim($message) . " (Code RV1012)"]);
                                 $this->response->setContent($response);
                                 return $this->response;
                              }
                              $commioGatewayUuid = $vGateway->gateway_uuid;

                              // Check qDIDs with the same trunk assignment (mirrors ApiController gatewayAction POST type=1)
                              $gwDids = QDids::find([
                                 "conditions" => "domain_uuid = :domain_uuid: AND sip_trunk_name = :sip_trunk_name:",
                                 "bind" => [
                                    "domain_uuid" => $domain_uuid,
                                    "sip_trunk_name" => $gParams["gateway"],
                                 ],
                                 "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                              ]);
                              foreach ($gwDids as $gwDid) {
                                 $gwDestinations = VDestinations::find([
                                    "conditions" => "domain_uuid = :domain_uuid: AND destination_number = :destination_number:",
                                    "bind" => [
                                       "domain_uuid" => $domain_uuid,
                                       "destination_number" => $gwDid->did_number,
                                    ],
                                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                 ]);
                                 foreach ($gwDestinations as $gwDestination) {
                                    $gwOldDialplan = VDialplans::findFirst([
                                       "conditions" => "domain_uuid = :domain_uuid: AND dialplan_uuid = :dialplan_uuid:",
                                       "bind" => [
                                          "domain_uuid" => $domain_uuid,
                                          "dialplan_uuid" => $gwDestination->dialplan_uuid,
                                       ],
                                       "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                    ]);
                                    if ($gwOldDialplan) {
                                       if (!$gwOldDialplan->delete()) {
                                          $this->db->rollback();
                                          $response = json_encode(["result" => "error", "message" => "Sorry, dialplan cannot be deleted. (Code AD1001)"]);
                                          $this->response->setContent($response);
                                          return $this->response;
                                       }
                                    }
                                    $gwOldDetails = VDialplanDetails::find([
                                       "conditions" => "domain_uuid = :domain_uuid: AND dialplan_uuid = :dialplan_uuid:",
                                       "bind" => [
                                          "domain_uuid" => $domain_uuid,
                                          "dialplan_uuid" => $gwDestination->dialplan_uuid,
                                       ],
                                       "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                    ]);
                                    foreach ($gwOldDetails as $gwOldDetail) {
                                       if (!$gwOldDetail->delete()) {
                                          $this->db->rollback();
                                          $response = json_encode(["result" => "error", "message" => "Sorry, detailplan option cannot be deleted. (Code AD1019)"]);
                                          $this->response->setContent($response);
                                          return $this->response;
                                       }
                                    }
                                    $gwExtension = $gwDid->extension;
                                    $gwDestNumber = $gwDestination->destination_number;
                                    $gwDialplanUuid = $gwDestination->dialplan_uuid;
                                    $gwDialplan = new VDialplans();
                                    $gwDialplan->app_uuid = $security->uuid();
                                    $gwDialplan->dialplan_uuid = $gwDialplanUuid;
                                    $gwDialplan->domain_uuid = $domain_uuid;
                                    $gwDialplan->dialplan_name = $gwDestNumber;
                                    $gwDialplan->dialplan_number = $gwDestNumber;
                                    $gwDialplan->dialplan_context = "public";
                                    $gwDialplan->dialplan_continue = "false";
                                    $gwDialplan->dialplan_order = 000;
                                    $gwDialplan->dialplan_enabled = "true";
                                    $gwCheckQueue = VCallCenterQueues::findFirst([
                                       "conditions" => "domain_uuid = :domain_uuid: AND queue_extension = :queue_extension:",
                                       "bind" => [
                                          "domain_uuid" => $domain_uuid,
                                          "queue_extension" => $gwExtension,
                                       ],
                                       "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                    ]);
                                    $gwQueueHeader = $gwCheckQueue ? " (" . $gwCheckQueue->queue_extension . ")" : "";
                                    $gwCustomHeaders = "";
                                    if ($gParams["from_user"] == "From User") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_number=${sip_from_user}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_number=${sip_from_user}"/>';
                                    }
                                    if ($gParams["from_user"] == "From Display") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_number=${sip_from_display}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_number=${sip_from_display}"/>';
                                    }
                                    if ($gParams["from_user"] == "Invite") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_number=${destination_number}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_number=${destination_number}"/>';
                                    }
                                    if ($gParams["from_user"] == "To User") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_number=${sip_to_user}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_number=${sip_to_user}"/>';
                                    }
                                    if ($gParams["from_display"] == "From User") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_name=${sip_from_user}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_name=${sip_from_user}"/>';
                                    }
                                    if ($gParams["from_display"] == "From Display") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_name=${sip_from_display}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_name=${sip_from_display}"/>';
                                    }
                                    if ($gParams["from_display"] == "Invite") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_name=${destination_number}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_name=${destination_number}"/>';
                                    }
                                    if ($gParams["from_display"] == "To User") {
                                       $gwCustomHeaders .= '
                                          <action application="set" data="effective_caller_id_name=${sip_to_user}' . $gwQueueHeader . '"/>
                                          <action application="set" data="origination_caller_id_name=${sip_to_user}"/>';
                                    }
                                    $gwCustomInvite = "";
                                    if ($gParams["did_match"] == "From User") {
                                       $gwCustomInvite = '${sip_from_user}';
                                    }
                                    if ($gParams["did_match"] == "From Display") {
                                       $gwCustomInvite = '${sip_from_display}';
                                    }
                                    if ($gParams["did_match"] == "Invite") {
                                       $gwCustomInvite = '${destination_number}';
                                    }
                                    if ($gParams["did_match"] == "To User") {
                                       $gwCustomInvite = '${sip_to_user}';
                                    }
                                    $gwDialplan->dialplan_xml = '<extension name="' . $gwDestNumber . '" continue="false" uuid="' . $gwDialplanUuid . '">
                                       <condition field="' . $gwCustomInvite . '" expression="^((\+)?' . $gwDestNumber . ')$">
                                          ' . $gwCustomHeaders . '
                                          <action application="export" data="call_direction=inbound" inline="true"/>
                                          <action application="set" data="domain_uuid=' . $domain_uuid . '" inline="true"/>
                                          <action application="set" data="domain_name=' . $fqdn . '" inline="true"/>
                                          <action application="export" data="hold_music=local_stream://default" inline="true"/>
                                          <action application="transfer" data="' . $gwExtension . ' XML ' . $fqdn . '"/>
                                       </condition>
                                    </extension>';
                                    $gwDialplan->insert_user = $user_uuid;
                                    $gwDialplan->insert_date = date("Y-m-d H:i:s");
                                    if (!$gwDialplan->create()) {
                                       $this->db->rollback();
                                       $messages = $gwDialplan->getMessages();
                                       $message = "";
                                       foreach ($messages as $error) {
                                          $message .= $error . " ";
                                       }
                                       $response = json_encode(["result" => "error", "message" => trim($message) . " (Code AD1002)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }
                                    $gwDetail1 = new VDialplanDetails();
                                    $gwDetail1->dialplan_detail_uuid = $security->uuid();
                                    $gwDetail1->domain_uuid = $domain_uuid;
                                    $gwDetail1->dialplan_uuid = $gwDialplanUuid;
                                    $gwDetail1->dialplan_detail_tag = "condition";
                                    $gwDetail1->dialplan_detail_type = "destination_number";
                                    $gwDetail1->dialplan_detail_data = "^(" . $gwDestNumber . ")$";
                                    $gwDetail1->dialplan_detail_inline = null;
                                    $gwDetail1->dialplan_detail_group = 0;
                                    $gwDetail1->dialplan_detail_order = 20;
                                    $gwDetail1->insert_user = $user_uuid;
                                    $gwDetail1->insert_date = date("Y-m-d H:i:s");
                                    if (!$gwDetail1->create()) {
                                       $this->db->rollback();
                                       $messages = $gwDetail1->getMessages();
                                       $message = "";
                                       foreach ($messages as $error) {
                                          $message .= $error . " ";
                                       }
                                       $response = json_encode(["result" => "error", "message" => trim($message) . " (Code AD1003)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }
                                    $gwDetail8 = new VDialplanDetails();
                                    $gwDetail8->dialplan_detail_uuid = $security->uuid();
                                    $gwDetail8->domain_uuid = $domain_uuid;
                                    $gwDetail8->dialplan_uuid = $gwDialplanUuid;
                                    $gwDetail8->dialplan_detail_tag = "action";
                                    $gwDetail8->dialplan_detail_type = "transfer";
                                    $gwDetail8->dialplan_detail_data = $gwExtension . ' XML ' . $fqdn;
                                    $gwDetail8->dialplan_detail_group = 0;
                                    $gwDetail8->dialplan_detail_order = 110;
                                    $gwDetail8->insert_user = $user_uuid;
                                    $gwDetail8->insert_date = date("Y-m-d H:i:s");
                                    if (!$gwDetail8->create()) {
                                       $this->db->rollback();
                                       $messages = $gwDetail8->getMessages();
                                       $message = "";
                                       foreach ($messages as $error) {
                                          $message .= $error . " ";
                                       }
                                       $response = json_encode(["result" => "error", "message" => trim($message) . " (Code AD1010)"]);
                                       $this->response->setContent($response);
                                       return $this->response;
                                    }
                                 }
                              }

                              // Create QOutboundRules (in-memory only — NOT saved to DB)
                              $commioOutboundUuid = $security->uuid();
                              $commioOutbound = new QOutboundRules();
                              $commioOutbound->domain_uuid         = $domain_uuid;
                              $commioOutbound->outbound_uuid       = $commioOutboundUuid;
                              $commioOutbound->prefix_pattern      = "";
                              $commioOutbound->calls_extensions    = "";
                              $commioOutbound->team_extensions     = "";
                              $commioOutbound->extension_range     = "";
                              $commioOutbound->calls_number_length = "10";
                              $commioOutbound->action              = $gParams["gateway"];
                              $commioOutbound->strip               = "";
                              $commioOutbound->prepend             = "";
                              $commioOutbound->outbound_caller_id  = $ownerDid;
                              $commioOutbound->outbound_name       = $gParams["gateway"];
                              $commioOutbound->custom_user         = $outboundSipFromUser;
                              $commioOutbound->custom_display      = $outboundSipFromDisplayName;
                              $commioOutbound->custom_host         = $outboundSipFromHost;
                              $commioOutbound->custom_cid          = $outboundCidType;
                              $commioOutbound->dial_plan_order     = 85;
                              $commioOutbound->priority            = 1;
                              $commioOutbound->insert_user         = $user_uuid;
                              $commioOutbound->insert_date         = date("Y-m-d H:i:s");
                              if (!$commioOutbound->create()) {
                                 $this->db->rollback();
                                 $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV1020)"]);
                                 $this->response->setContent($response);
                                 return $this->response;
                              }

                              // Dialplan XML — mirrors ApiController outboundrulesAction POST type=1 for this rule.
                              // SIP From user/display come from SipSettingsInternal 153 (resolved via the same map as the API).
                              $sipUserMap = [
                                 "Extension Name"               => '${effective_caller_id_name}',
                                 "Extension Number"             => '${effective_caller_id_number}',
                                 "Trunk Name"                   => $vGateway->gateway,
                                 "SIP Auth ID"                  => $vGateway->auth_username,
                                 "SIP Username"                 => $vGateway->username,
                                 "Outbound Rule Name"           => $commioOutbound->outbound_name,
                                 "Caller ID from Extension"     => '${outbound_caller_id_number}',
                                 "Caller ID from Trunk"         => $vGateway->description,
                                 "Caller ID from Outbound Rule" => $commioOutbound->outbound_caller_id,
                              ];
                              $uFrom = $sipUserMap[$outboundSipFromUser] ?? $outboundSipFromUser;
                              $uDisp = $sipUserMap[$outboundSipFromDisplayName] ?? $outboundSipFromDisplayName;
                              $commioSipHeaders  = $uFrom !== '' ? '<action application="export" data="ui_from_user=' . $uFrom . '"/>' : '';
                              $commioSipHeaders .= $uDisp !== '' ? '<action application="export" data="ui_from_display=' . $uDisp . '"/>' : '';
                              // sip_from_host: "Domain" -> fqdn, "Default IP" -> request IP, else literal.
                              $commioSipHost = $outboundSipFromHost;
                              if ($commioSipHost === "Domain") {
                                 $commioSipHost = $fqdn;
                              } else if ($commioSipHost === "Default IP") {
                                 $commioSipHost = $_SERVER['REMOTE_ADDR'] ?? $fqdn;
                              }
                              // outbound_caller_id empty + gateway description empty -> default ui_from_user branch
                              $commioAntiActionCallerId = '';
                              $commioEffectiveCallerIds = '
                                       <action application="export" data="effective_outbound_caller_id_name=${ui_from_user}"/>
                                       <action application="export" data="outbound_caller_id_number=${ui_from_user}"/>
                                       <action application="export" data="sip_from_uri=${ui_from_user}@' . $commioSipHost . '"/>
                              ';
                              if (!empty($vGateway->description)) {
                                 // Gateway description holds the owner DID -> use it as the fixed outbound caller ID.
                                 $commioAntiActionCallerId = '
                                       <condition field="${outbound_caller_id_number}" expression="^$" break="never">
                                          <action application="log" data="CID missing, setting default to ' . $ownerDid . '"/>
                                          <action application="set" data="outbound_caller_id_number=' . $ownerDid . '"/>
                                       </condition>
                                 ';
                                 $commioEffectiveCallerIds = '
                                       <action application="log" data="INFO effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                                       <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                                       <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $commioSipHost . '"/>
                                 ';
                              }
                              $commioPrivacy = ",privacy=off";
                              if ($vGateway->privacy_header == 1) {
                                 $commioPrivacy = ",origination_privacy=screen+hide_name+hide_number,privacy=full";
                              }
                              $commioChannelLimits = "";
                              if ($vGateway->channels != 0) {
                                 $commioChannelLimits = '
                                       <action application="set" data="max_calls=' . $vGateway->channels . '" inline="true"/>
                                       <action application="limit" data="db ' . $fqdn . ' ' . $vGateway->gateway_uuid . ' ${max_calls}"/>
                                 ';
                              }
                              $commioCodecValues = [
                                 "OPUS"  => "OPUS",
                                 "ILBC"  => "iLBC",
                                 "G7221" => "G7221@32k",
                                 "G722"  => "G722",
                                 "G729"  => "G729",
                                 "PCMU"  => "PCMU",
                                 "PCMA"  => "PCMA",
                              ];
                              $commioCodecPrefs = "";
                              if (!is_null($vGateway->codec_prefs)) {
                                 $commioCodecValues2 = [];
                                 $commioCodecs = explode(",", $vGateway->codec_prefs);
                                 foreach ($commioCodecs as $commioCodec) {
                                    $commioCodec = strtoupper($commioCodec);
                                    if (array_key_exists($commioCodec, $commioCodecValues)) {
                                       array_push($commioCodecValues2, $commioCodecValues[$commioCodec]);
                                    }
                                 }
                                 $commioCodecValues2 = implode(",", $commioCodecValues2);
                                 $commioCodecPrefs = '<action application="export" data="nolocal:' . 'absolute_codec_string=' . $commioCodecValues2 . '"/>';
                              }
                              // cid_type PAI -> PID (same conversion as the API)
                              $commioCidType = $commioOutbound->custom_cid == "PAI" ? "pid" : strtolower($commioOutbound->custom_cid);
                              // Caller-ID number sent to Commio is always the owner's first DID (literal), not the From-user.
                              $commioBridge = '<action application="bridge" data="{sip_cid_type=' . $commioCidType . ',origination_caller_id_name=${ui_from_display},origination_caller_id_number=' . $ownerDid . '@' . $commioSipHost . $commioPrivacy . '}sofia/gateway/' . $vGateway->gateway_uuid . '/${dest_number:0}"/>';
                              $commioXml = '<extension name="' . $commioOutbound->outbound_name . '" continue="false" uuid="' . $commioOutboundUuid . '">
                                    ' . $commioAntiActionCallerId . '
                                    <condition field="${user_exists}" expression="false"/>
                                    <condition field="destination_number" expression="^(\d{10})$" break="on-true">
                                       <action application="set" data="dest_number=$1$2$3$4$5$6"/>
                                       <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                                       <action application="export" data="call_direction=outbound" inline="true"/>
                                       <action application="export" data="user-agent-string" value="RingQ"/>
                                       ' . $commioSipHeaders . '
                                       ' . $commioCodecPrefs . '
                                       <action application="unset" data="call_timeout"/>
                                       <action application="set" data="hangup_after_bridge=true"/>
                                       ' . $commioEffectiveCallerIds . '
                                       <action application="export" data="rule=' . $commioOutbound->outbound_name . '"/>
                                       <action application="set" data="inherit_codec=false"/>
                                       <action application="set" data="ignore_display_updates=true"/>
                                       <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                                       <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                                       <action application="set" data="continue_on_fail=true"/>
                                       ' . $commioChannelLimits . $commioBridge . '
                                       <action application="playback" data="misc/sorry_your_call.wav"/>
                                    </condition>
                                 </extension>';

                              $commioDialplan = new VDialplans();
                              $commioDialplan->domain_uuid          = $domain_uuid;
                              $commioDialplan->dialplan_uuid        = $commioOutboundUuid;
                              $commioDialplan->app_uuid             = $security->uuid();
                              $commioDialplan->hostname             = null;
                              $commioDialplan->dialplan_xml         = $commioXml;
                              $commioDialplan->dialplan_name        = $commioOutbound->outbound_name;
                              $commioDialplan->dialplan_order       = 84;
                              $commioDialplan->dialplan_context     = $fqdn;
                              $commioDialplan->dialplan_continue    = "false";
                              $commioDialplan->dialplan_enabled     = "false";
                              $commioDialplan->dialplan_description = "outbound-rule";
                              $commioDialplan->insert_user          = $user_uuid;
                              $commioDialplan->insert_date          = date("Y-m-d H:i:s");
                              if (!$commioDialplan->create()) {
                                 $this->db->rollback();
                                 $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV1021)"]);
                                 $this->response->setContent($response);
                                 return $this->response;
                              }

                              // "outbound-rules" wrapper dialplan (same as the API: update if exists, else create)
                              $commioWrapper = VDialplans::findFirst([
                                 "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :dialplan_name:",
                                 "bind" => [
                                    "domain_uuid"   => $domain_uuid,
                                    "dialplan_name" => "outbound-rules",
                                 ],
                                 "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                              ]);
                              if ($commioWrapper) {
                                 $commioWrapper->dialplan_xml = $commioXml;
                                 if (!$commioWrapper->update()) {
                                    $this->db->rollback();
                                    $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV1022)"]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              } else {
                                 $commioWrapper = new VDialplans();
                                 $commioWrapper->domain_uuid       = $domain_uuid;
                                 $commioWrapper->dialplan_uuid     = $security->uuid();
                                 $commioWrapper->app_uuid          = $security->uuid();
                                 $commioWrapper->hostname          = null;
                                 $commioWrapper->dialplan_xml      = $commioXml;
                                 $commioWrapper->dialplan_name     = "outbound-rules";
                                 $commioWrapper->dialplan_order    = 85;
                                 $commioWrapper->dialplan_context  = $fqdn;
                                 $commioWrapper->dialplan_continue = "false";
                                 $commioWrapper->dialplan_enabled  = "false";
                                 $commioWrapper->insert_user       = $user_uuid;
                                 $commioWrapper->insert_date       = date("Y-m-d H:i:s");
                                 if (!$commioWrapper->create()) {
                                    $this->db->rollback();
                                    $response = json_encode(["result" => "error", "message" => "An error has occurred. (Code RV1022)"]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }

                              $this->redis->set($fqdn . ":outboundParallel", "1");
                           }

                           $localOutboundUuid = $security->uuid();
                           $localOutboundUuid2 = $security->uuid();
                           $internationalOutboundUuid = $security->uuid();
                           // $qOutboundRules = new QOutboundRules();
                           // $qOutboundRules->domain_uuid = $domain_uuid;
                           // $qOutboundRules->outbound_uuid = $internationalOutboundUuid;
                           // $qOutboundRules->prefix_pattern = "00";
                           // $qOutboundRules->calls_extensions = "";
                           // $qOutboundRules->team_extensions = "";
                           // $qOutboundRules->extension_range = "";
                           // $qOutboundRules->calls_number_length = 0;
                           // $qOutboundRules->action = strtoupper($gateway);
                           // $qOutboundRules->strip = 2;
                           // $qOutboundRules->prepend = "00";
                           // $qOutboundRules->outbound_caller_id = "";
                           // $qOutboundRules->outbound_name = "International";
                           // $qOutboundRules->dial_plan_order = 83;
                           // if (!$qOutboundRules->create()) {
                           //    $this->db->rollback();
                           //    $response = json_encode([
                           //       "result" => "error",
                           //       "message" => "An error has occurred. (Code RV1012)",
                           //    ]);
                           //    $this->response->setContent($response);
                           //    return $this->response;
                           // }
                           // $vDialplan = new VDialplans();
                           // $vDialplan->domain_uuid = $domain_uuid;
                           // $vDialplan->dialplan_uuid = $internationalOutboundUuid;
                           // $vDialplan->app_uuid = $security->uuid();
                           // $vDialplan->hostname = null;
                           // $vDialplan->dialplan_xml = '<extension name="International" continue="false" uuid="' . $internationalOutboundUuid . '">
                           //     <condition field="${user_exists}" expression="false"/>
                           //     <condition field="destination_number" expression="^\b00(\d*)\b$|^\b0000100(\d*)\b$|^\b0000200(\d*)\b$|^\b000030011(\d*)\b$|^\b0000400(\d*)\b$|^\b000000(\d*)\b$" break="on-true">
                           //         <action application="set" data="dest_number=00$1$2$3$4$5$6"/>
                           //         <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                           //         <action application="export" data="call_direction=outbound" inline="true"/>
                           //         <action application="export" data="nolocal:absolute_codec_string=PCMA,PCMU"/>
                           //         <action application="unset" data="call_timeout"/>
                           //         <action application="set" data="hangup_after_bridge=true"/>
                           //         <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $fqdn . '"/>
                           //         <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                           //         <action application="set" data="inherit_codec=false"/>
                           //         <action application="set" data="ignore_display_updates=true"/>
                           //         <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                           //         <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                           //         <action application="bridge" data="{sip_cid_type=rpid,origination_caller_id_name=${outbound_caller_id_number},origination_caller_id_number=${outbound_caller_id_number}@' . $proxy . ',origination_privacy=screen+hide_name+hide_number}sofia/gateway/' . $gatewayUuid . '/00${dest_number:2}"/>
                           //     </condition>
                           // </extension>';
                           // $vDialplan->dialplan_name = "International";
                           // $vDialplan->dialplan_order = 83;
                           // $vDialplan->dialplan_context = $fqdn;
                           // $vDialplan->dialplan_continue = "false";
                           // $vDialplan->dialplan_enabled = "true";
                           // $vDialplan->dialplan_description = "outbound-rule";
                           // $vDialplan->insert_user = $user_uuid;
                           // $vDialplan->insert_date = date("Y-m-d H:i:s");
                           // if (!$vDialplan->create()) {
                           //    $this->db->rollback();
                           //    $response = json_encode([
                           //       "result" => "error",
                           //       "message" => "An error has occurred. (Code RV1012)",
                           //    ]);
                           //    $this->response->setContent($response);
                           //    return $this->response;
                           // }
                           // $qOutboundRules2 = new QOutboundRules();
                           // $qOutboundRules2->domain_uuid = $domain_uuid;
                           // $qOutboundRules2->outbound_uuid = $localOutboundUuid;
                           // $qOutboundRules2->prefix_pattern = "";
                           // $qOutboundRules2->calls_extensions = "";
                           // $qOutboundRules2->team_extensions = "";
                           // $qOutboundRules2->extension_range = "";
                           // $qOutboundRules2->calls_number_length = 11;
                           // $qOutboundRules2->action = strtoupper($gateway);
                           // $qOutboundRules2->strip = 0;
                           // $qOutboundRules2->prepend = "";
                           // $qOutboundRules2->outbound_caller_id = "";
                           // $qOutboundRules2->outbound_name = "Local 1";
                           // $qOutboundRules2->dial_plan_order = 84;
                           // if (!$qOutboundRules2->create()) {
                           //    $this->db->rollback();
                           //    $response = json_encode([
                           //       "result" => "error",
                           //       "message" => "An error has occurred. (Code RV1012)",
                           //    ]);
                           //    $this->response->setContent($response);
                           //    return $this->response;
                           // }
                           // $vDialplan2 = new VDialplans();
                           // $vDialplan2->domain_uuid = $domain_uuid;
                           // $vDialplan2->dialplan_uuid = $localOutboundUuid;
                           // $vDialplan2->app_uuid = $security->uuid();
                           // $vDialplan2->hostname = null;
                           // $vDialplan2->dialplan_xml = '<extension name="Local 1" continue="false" uuid="' . $localOutboundUuid . '">
                           //      <condition field="${user_exists}" expression="false"/>
                           //      <condition field="destination_number" expression="^1(\d{10})$|^0011(\d{10})$|^0021(\d{10})$|^0031(\d{10})$|^0041(\d{10})$|^001(\d{10})$" break="on-true">
                           //          <action application="set" data="dest_number=$1$2$3$4$5$6"/>
                           //          <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                           //          <action application="export" data="call_direction=outbound" inline="true"/>
                           //          <action application="export" data="nolocal:absolute_codec_string=PCMA,PCMU"/>
                           //          <action application="unset" data="call_timeout"/>
                           //          <action application="set" data="hangup_after_bridge=true"/>
                           //          <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $fqdn . '"/>
                           //          <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                           //          <action application="set" data="inherit_codec=false"/>
                           //          <action application="set" data="ignore_display_updates=true"/>
                           //          <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                           //          <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                           //          <action application="bridge" data="{sip_cid_type=rpid,origination_caller_id_name=${outbound_caller_id_number},origination_caller_id_number=${outbound_caller_id_number}@' . $proxy . ',origination_privacy=screen+hide_name+hide_number}sofia/gateway/' . $gatewayUuid . '/${dest_number:0}"/>
                           //      </condition>
                           //  </extension>';
                           // $vDialplan2->dialplan_name = "Local 1";
                           // $vDialplan2->dialplan_order = 84;
                           // $vDialplan2->dialplan_context = $fqdn;
                           // $vDialplan2->dialplan_continue = "false";
                           // $vDialplan2->dialplan_enabled = "true";
                           // $vDialplan2->dialplan_description = "outbound-rule";
                           // $vDialplan2->insert_user = $user_uuid;
                           // $vDialplan2->insert_date = date("Y-m-d H:i:s");
                           // if (!$vDialplan2->create()) {
                           //    $this->db->rollback();
                           //    $response = json_encode([
                           //       "result" => "error",
                           //       "message" => "An error has occurred. (Code RV1012)",
                           //    ]);
                           //    $this->response->setContent($response);
                           //    return $this->response;
                           // }
                           // $qOutboundRules3 = new QOutboundRules();
                           // $qOutboundRules3->domain_uuid = $domain_uuid;
                           // $qOutboundRules3->outbound_uuid = $localOutboundUuid2;
                           // $qOutboundRules3->prefix_pattern = "";
                           // $qOutboundRules3->calls_extensions = "";
                           // $qOutboundRules3->team_extensions = "";
                           // $qOutboundRules3->extension_range = "";
                           // $qOutboundRules3->calls_number_length = 10;
                           // $qOutboundRules3->action = strtoupper($gateway);
                           // $qOutboundRules3->strip = 0;
                           // $qOutboundRules3->prepend = "1";
                           // $qOutboundRules3->outbound_caller_id = "";
                           // $qOutboundRules3->outbound_name = "Local";
                           // $qOutboundRules3->dial_plan_order = 85;
                           // if (!$qOutboundRules3->create()) {
                           //    $this->db->rollback();
                           //    $response = json_encode([
                           //       "result" => "error",
                           //       "message" => "An error has occurred. (Code RV1012)",
                           //    ]);
                           //    $this->response->setContent($response);
                           //    return $this->response;
                           // }
                           // $vDialplan3 = new VDialplans();
                           // $vDialplan3->domain_uuid = $domain_uuid;
                           // $vDialplan3->dialplan_uuid = $localOutboundUuid2;
                           // $vDialplan3->app_uuid = $security->uuid();
                           // $vDialplan3->hostname = null;
                           // $vDialplan3->dialplan_xml = '<extension name="Local" continue="false" uuid="' . $localOutboundUuid2 . '">
                           //      <condition field="${user_exists}" expression="false"/>
                           //      <condition field="destination_number" expression="^(\d{10})$|^001(\d{10})$|^002(\d{10})$|^003(\d{10})$|^004(\d{10})$|^00(\d{10})$" break="on-true">
                           //          <action application="set" data="dest_number=$1$2$3$4$5$6"/>
                           //          <action application="set" data="sip_h_X-accountcode=${accountcode}"/>
                           //          <action application="export" data="call_direction=outbound" inline="true"/>
                           //          <action application="export" data="nolocal:' . 'absolute_codec_string=PCMA,PCMU"/>
                           //          <action application="unset" data="call_timeout"/>
                           //          <action application="set" data="hangup_after_bridge=true"/>
                           //          <action application="export" data="sip_from_uri=${outbound_caller_id_number}@' . $fqdn . '"/>
                           //          <action application="set" data="effective_outbound_caller_id_name=${outbound_caller_id_number}"/>
                           //          <action application="set" data="inherit_codec=false"/>
                           //          <action application="set" data="ignore_display_updates=true"/>
                           //          <action application="set" data="callee_id_number=$1$2$3$4$5$6"/>
                           //          <action application="set" data="continue_on_fail=1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809"/>
                           //          <action application="bridge" data="{sip_cid_type=rpid,origination_caller_id_name=${outbound_caller_id_number},origination_caller_id_number=${outbound_caller_id_number}@' . $proxy . ',origination_privacy=screen+hide_name+hide_number}sofia/gateway/' . $gatewayUuid . '/1${dest_number:0}"/>
                           //      </condition>
                           //  </extension>';
                           // $vDialplan3->dialplan_name = "Local";
                           // $vDialplan3->dialplan_order = 85;
                           // $vDialplan3->dialplan_context = $fqdn;
                           // $vDialplan3->dialplan_continue = "false";
                           // $vDialplan3->dialplan_enabled = "true";
                           // $vDialplan3->dialplan_description = "outbound-rule";
                           // $vDialplan3->insert_user = $user_uuid;
                           // $vDialplan3->insert_date = date("Y-m-d H:i:s");
                           // if (!$vDialplan3->create()) {
                           //    $this->db->rollback();
                           //    $response = json_encode([
                           //       "result" => "error",
                           //       "message" => "An error has occurred. (Code RV1012)",
                           //    ]);
                           //    $this->response->setContent($response);
                           //    return $this->response;
                           // }
                           $qCallBehavior = new QCallBehaviors();
                           $qCallBehavior->extension_uuid = $extension_uuid;
                           $qCallBehavior->domain_uuid = $domain_uuid;
                           $qCallBehavior->extension = $extension;
                           $qCallBehavior->forward_no_answer_destination = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior->forward_no_answer_destination2 = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior->forward_no_answer_timeout = "30,30,30,30,30";
                           $qCallBehavior->forward_no_answer_timeout2 = "30,30,30,30,30";
                           $qCallBehavior->forward_no_answer_enabled = "true,true,true,true,true";
                           $qCallBehavior->forward_no_answer_enabled2 = "true,true,true,true,true";
                           $qCallBehavior->type = 1;
                           $qCallBehavior->enabled = 1;
                           $qCallBehavior->sso_qr = 1;
                           $qCallBehavior->sso_number = 1;
                           $qCallBehavior->call_profiles_colors = "#004D40,#004D40,#004D40,#004D40,#004D40";
                           $qCallBehavior->insert_user = $user_uuid;
                           $qCallBehavior->insert_date = date("Y-m-d H:i:s");
                           if (!$qCallBehavior->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1012)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qCompanyMode = new QCompanyModes();
                           $qCompanyMode->domain_uuid = $domain_uuid;
                           $qCompanyMode->mode1 = "1000";
                           $qCompanyMode->permission1 = "1000";
                           if (!$qCompanyMode->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1012)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vPinNumber = new VPinNumbers();
                           $vPinNumber->domain_uuid = $domain_uuid;
                           $vPinNumber->pin_number_uuid = $security->uuid();
                           $vPinNumber->pin_number = rand(1000, 9999);
                           $vPinNumber->enabled = "true";
                           $vPinNumber->description = $extension;
                           $vPinNumber->insert_user = $user_uuid;
                           $vPinNumber->insert_date = date("Y-m-d H:i:s");
                           if (!$vPinNumber->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1013)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           // $vDialplan = new VDialplans();
                           // $vDialplan->domain_uuid = $domain_uuid;
                           // $vDialplan->dialplan_uuid
                           // $vDialplan->app_uuid
                           // $vDialplan->dialplan_context = $domain_uuid;
                           if (!$vPinNumber->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1013)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
                           $access_token = bin2hex(random_bytes(32));
                           $qToken = new QTokens();
                           $qToken->user_uuid = $user_uuid;
                           $qToken->domain_uuid = $domain_uuid;
                           $qToken->access_token = $access_token;
                           $qToken->insert_date = date("Y-m-d H:i:s");
                           $qToken->user_agent = $userAgent;
                           $qToken->type = 1; // web, android or ios
                           if (!$qToken->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV1014)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $expire = time() + 86400 * 360;
                           $this->cookies->set("RINGQKIE", $user_uuid, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
                           $this->cookies->set("RINGQENS", $access_token, $expire, "/", true, "." . $this->config->application->publicUrl, true, ["samesite" => "Lax"]);
                           $walltoken = $this->security->hash($user_uuid);
                           $this->redis->sadd("wallboard:" . $vExtension->accountcode, $walltoken);
                           $authenticated = [
                              "username" => $vUser->username,
                              "firstname" => $vUser->qUsers->first_name,
                              "lastname" => $vUser->qUsers->last_name,
                              "email" => $vUser->user_email,
                              "image" => $vUser->qUsers->image,
                              "extension" => $vExtension->extension,
                              "subdomain" => $vExtension->accountcode,
                              "domain" => $this->config->application->publicUrl,
                              "domain_uuid" => $domain_uuid,
                              "sip_uuid" => $extension_password,
                              "user_uuid" => $user_uuid,
                              "walltoken" => $walltoken,
                              "access" => "registered",
                              "result" => "success",
                           ];
                           $this->session->set("auth", $authenticated);
                           if ($extension == 1000) {
                              $config = $this->redis->get('ringq_config');
                              $configData = json_decode($config, true);
                              $userId = $configData['data']['user_id'];
                              $licenseKey = $configData['data']['licenseKey'];
                              $uuid = $this->redis->hget("verify-" . $parameters["email"], "uuid");
                              $access_token = $this->redis->hget("verify-" . $parameters["email"], "access_token");
                              $user_id = $this->redis->hget("verify-" . $parameters["email"], "user_id");
                              $data = [
                                 "licenseKey" => $licenseKey,
                                 "userId" => $qCompany->uid,
                                 "type" => (int) $this->redis->get('server_type')
                              ];
                              $billingURL = "https://licensekey.ringq.com/process/firstlogin";
                              if ($this->request->getServerAddress() == "165.232.164.1") {
                                 $billingURL = "https://test.ringq.io";
                              }
                              $ch = curl_init($billingURL);
                              curl_setopt($ch, CURLOPT_POST, true);
                              curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                                 "Accept: application/json",
                                 "Content-Type: application/json",
                                 "Authorization: asgasgihqwgagsjokashiogahiosasbjg",
                              ), );
                              curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                              curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                              $response2 = curl_exec($ch);
                              curl_close($ch);
                           }
                           $this->redis->del("verify-" . $parameters["email"]);
                           $this->redis->set($vExtension->extension . "+" . $vExtension->accountcode, "0");
                           $this->redis->set($vExtension->extension . "-" . $vExtension->accountcode . "-permission", "7");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-destination");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-destination2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-destination");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-destination2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-follow-me-destinations");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-follow-me-destinations2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-enabled");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-enabled2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-enabled");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-me-enabled");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-forward-me-enabled2");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-callScreening");
                           $this->redis->del($vExtension->extension . "@" . $vExtension->accountcode . "-callScreening2");

                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination", ["*99" . $vExtension->extension, "*99" . $vExtension->extension, "*99" . $vExtension->extension, "*99" . $vExtension->extension, "*99" . $vExtension->extension]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2", ["*99" . $vExtension->extension, "*99" . $vExtension->extension, "*99" . $vExtension->extension, "*99" . $vExtension->extension, "*99" . $vExtension->extension]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-follow-me-destinations", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-follow-me-destinations2", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["", "", "", "", ""]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->extension . "@" . $vExtension->accountcode . "-callScreening2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($vExtension->accountcode . ":parked", ["", "", "", "", ""]);

                           $this->redis->set("ai:" . $vExtension->accountcode . "-keywords", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-keywordMessages", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-keywordExtensions", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-questions", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-defaultLanguage", "English");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-languages", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-prompt", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-context", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-prompt2", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-context2", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-greetings", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-voice", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-playingSpeed", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-interruptions", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-waitings", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-token", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-selections", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-silenceDuration", "60");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-invalidAttempt", "3");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-silenceMessage", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-invalidMessage", "");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-silenceDurationAction", "1000");
                           $this->redis->set("ai:" . $vExtension->accountcode . "-invalidAttemptAction", "1000");
                           $this->redis->set("security:" . $vExtension->accountcode . "-ssoQR", 1);
                           $this->redis->set("security:" . $vExtension->accountcode . "-ssoNumber", 1);
                           $this->redis->del("backup-verify-" . $parameters["email"]);
                           $this->redis->set("gatewayQueue-" . $vExtension->accountcode, "1");
                           $this->redis->set($vExtension->extension . "+" . $vExtension->accountcode . "-contact-page-enabled", "1");
                           // DEFAULT CLICK2CALL
                           $user_uuid = $security->uuid();
                           $company_email = "";
                           $voicemail_uuid = $security->uuid();
                           $company = strtolower(str_replace(" ", "", preg_replace("/[^a-zA-Z0-9_ -]/s", " ", $qCompany->company_name)));
                           $fqdn = $vExtension->accountcode;
                           $extension = 100000;
                           $lastname = "";
                           $firstname = "";
                           $username = $security->hex(10);
                           $salt = $this->security->hash($security->base64(16));
                           $password = $this->security->hash($security->base64(16));
                           $password2 = $security->base64(16);
                           $extension_password = $password2; // md5($salt . $password2);
                           try {
                              $isNoAlias1000 = VExtensions::findFirst([
                                 "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND number_alias IS NULL",
                                 "bind" => [
                                    "domain_uuid" => $domain_uuid,
                                    "extension" => '1000',
                                 ],
                              ]);
                              if ($isNoAlias1000) {
                                 $isNoAlias1000->number_alias = $username;
                                 if (!$isNoAlias1000->save()) {
                                    $this->db->rollback();
                                    $messages = $isNoAlias1000->getMessages();
                                    $message = "";
                                    foreach ($messages as $error) {
                                       $message .= $error . " ";
                                    }
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => trim($message) . " (Code AE1001)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }
                           } catch (\Throwable $e) {
                              error_log(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
                           }
                           $qUserC2C = new QUsers();
                           $qUserC2C->user_uuid = $user_uuid;
                           $qUserC2C->domain_uuid = $domain_uuid;
                           $qUserC2C->first_name = $firstname;
                           $qUserC2C->last_name = $lastname;
                           $qUserC2C->extension_enabled = true;
                           $qUserC2C->insert_date = date("Y-m-d H:i:s");
                           $qUserC2C->user_email = $company_email;
                           $qUserC2C->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                           if (!$qUserC2C->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_C2C_Q)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUser = new VUsers();
                           $vUser->username = $username;
                           $vUser->user_uuid = $user_uuid;
                           $vUser->domain_uuid = $domain_uuid;
                           $vUser->user_email = $company_email;
                           $vUser->password = $password;
                           $vUser->user_status = "Logged Out";
                           $vUser->user_enabled = "true";
                           $vUser->api_key = $security->base64(32);
                           $vUser->image = null;
                           if (!$vUser->save()) {
                              $this->db->rollback();
                              $messages = $vUser->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AE1002)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtension = new VExtensions();
                           $vExtension->extension_uuid = $extension_uuid = $security->uuid();
                           $vExtension->domain_uuid = $domain_uuid;
                           $vExtension->extension = $username;
                           $vExtension->number_alias = $extension;
                           $vExtension->password = $extension_password;
                           $vExtension->enabled = "true";
                           $vExtension->extension_type = "default";
                           $vExtension->accountcode = $fqdn;
                           $vExtension->directory_visible = "true";
                           $vExtension->directory_exten_visible = "true";
                           $vExtension->limit_max = "10"; // <!-- Should be based on purchased package
                           $vExtension->limit_destination = "!USER_BUSY";
                           $vExtension->effective_caller_id_name = "100000";
                           $vExtension->effective_caller_id_number = $extension;
                           $vExtension->do_not_disturb = "false";
                           $vExtension->forward_all_enabled = "false";
                           $vExtension->forward_all_enabled2 = "false";
                           $vExtension->forward_busy_enabled = "false";
                           $vExtension->forward_busy_enabled2 = "false";
                           $vExtension->forward_no_answer_enabled = "true";
                           $vExtension->forward_no_answer_enabled2 = "true";
                           $vExtension->forward_user_not_registered_enabled = "false";
                           $vExtension->forward_user_not_registered_enabled2 = "false";
                           $vExtension->forward_no_answer_destination = "*99" . $extension;
                           $vExtension->forward_no_answer_destination2 = "*99" . $extension;
                           $vExtension->voicemail_enabled = "enabled";
                           $vExtension->voicemail_file = "attach";
                           $vExtension->call_screen_enabled = "false";
                           $vExtension->user_context = $fqdn;
                           $vExtension->call_timeout = 30;
                           $vExtension->call_timeout2 = 30;
                           $vExtension->insert_user = $user_uuid;
                           $vExtension->insert_date = date("Y-m-d H:i:s");
                           if (!$vExtension->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1003)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtensionUser = new VExtensionUsers();
                           $vExtensionUser->extension_user_uuid = $security->uuid();
                           $vExtensionUser->domain_uuid = $domain_uuid;
                           $vExtensionUser->user_uuid = $user_uuid;
                           $vExtensionUser->extension_uuid = $extension_uuid;
                           if (!$vExtensionUser->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1004)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vPinNumber = new VPinNumbers();
                           $vPinNumber->domain_uuid = $domain_uuid;
                           $vPinNumber->pin_number_uuid = $security->uuid();
                           $vPinNumber->pin_number = strval(rand(1000, 9999));
                           $vPinNumber->accountcode = $vExtension->accountcode;
                           $vPinNumber->enabled = "true";
                           $vPinNumber->description = strval($extension);
                           $vPinNumber->insert_user = $user_uuid;
                           $vPinNumber->insert_date = date("Y-m-d H:i:s");
                           if (!$vPinNumber->create()) {
                              $this->db->rollback();
                              $messages = $vPinNumber->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1008)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $company_email = "example@ringq.com";
                           $firstname = "Partner";
                           $lastname = "Account";
                           $extension = 9997;
                           $username = $security->hex(10);
                           $user_uuid = $security->uuid();
                           $salt = $this->security->hash($security->base64(16));
                           $password = $this->security->hash($security->base64(16));
                           $password2 = $security->base64(16);
                           $extension_password = $password2; // md5($salt . $password2);
                           try {
                              $isNoAlias1000 = VExtensions::findFirst([
                                 "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND number_alias IS NULL",
                                 "bind" => [
                                    "domain_uuid" => $domain_uuid,
                                    "extension" => '1000',
                                 ],
                              ]);
                              if ($isNoAlias1000) {
                                 $isNoAlias1000->number_alias = $username;
                                 if (!$isNoAlias1000->save()) {
                                    $this->db->rollback();
                                    $messages = $isNoAlias1000->getMessages();
                                    $message = "";
                                    foreach ($messages as $error) {
                                       $message .= $error . " ";
                                    }
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => trim($message) . " (Code AE1001)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }
                           } catch (\Throwable $e) {
                              error_log(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
                           }
                           $qUser7 = new QUsers();
                           $qUser7->user_uuid = $user_uuid;
                           $qUser7->domain_uuid = $domain_uuid;
                           $qUser7->first_name = $firstname;
                           $qUser7->last_name = $lastname;
                           $qUser7->extension_enabled = true;
                           $qUser7->insert_date = date("Y-m-d H:i:s");
                           $qUser7->user_email = $company_email;
                           $qUser7->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                           if (!$qUser7->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1001)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUser7 = new VUsers();
                           $vUser7->username = $username;
                           $vUser7->user_uuid = $user_uuid;
                           $vUser7->domain_uuid = $domain_uuid;
                           $vUser7->user_email = $company_email;
                           $vUser7->password = $password;
                           $vUser7->user_status = "Logged Out";
                           $vUser7->user_enabled = "true";
                           $vUser7->api_key = $security->base64(32);
                           $vUser7->image = null;
                           $vUser7->first_name = $firstname;
                           $vUser7->last_name = $lastname;
                           $vUser7->insert_user = $user_uuid;
                           $vUser7->insert_date = date("Y-m-d H:i:s");
                           if (!$vUser7->save()) {
                              $this->db->rollback();
                              $messages = $vUser7->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1008)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtension7 = new VExtensions();
                           $vExtension7->extension_uuid = $extension_uuid = $security->uuid();
                           $vExtension7->domain_uuid = $domain_uuid;
                           $vExtension7->extension = $extension;
                           $vExtension7->number_alias = $username;
                           $vExtension7->password = $extension_password;
                           $vExtension7->enabled = "true";
                           $vExtension7->extension_type = "default";
                           $vExtension7->accountcode = $fqdn;
                           $vExtension7->directory_visible = "true";
                           $vExtension7->directory_exten_visible = "true";
                           $vExtension7->limit_max = "10"; // <!-- Should be based on purchased package
                           $vExtension7->limit_destination = "!USER_BUSY";
                           $vExtension7->effective_caller_id_name = $firstname . " " . $lastname;
                           $vExtension7->effective_caller_id_number = $extension;
                           $vExtension7->do_not_disturb = "false";
                           $vExtension7->forward_all_enabled = "false";
                           $vExtension7->forward_all_enabled2 = "false";
                           $vExtension7->forward_busy_enabled = "false";
                           $vExtension7->forward_busy_enabled2 = "false";
                           $vExtension7->forward_no_answer_enabled = "true";
                           $vExtension7->forward_no_answer_enabled2 = "true";
                           $vExtension7->forward_user_not_registered_enabled = "false";
                           $vExtension7->forward_user_not_registered_enabled2 = "false";
                           $vExtension7->forward_no_answer_destination = "*99" . $extension;
                           $vExtension7->forward_no_answer_destination2 = "*99" . $extension;
                           $vExtension7->voicemail_enabled = "enabled";
                           $vExtension7->voicemail_file = "attach";
                           $vExtension7->voicemail_uuid = $vmUuid7 = $security->uuid();
                           $vExtension7->call_screen_enabled = "false";
                           $vExtension7->user_context = $fqdn;
                           $vExtension7->call_timeout = 30;
                           $vExtension7->call_timeout2 = 30;
                           $vExtension7->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                           $vExtension7->insert_user = $user_uuid;
                           $vExtension7->insert_date = date("Y-m-d H:i:s");
                           if (!$vExtension7->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1003)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtensionUser7 = new VExtensionUsers();
                           $vExtensionUser7->extension_user_uuid = $security->uuid();
                           $vExtensionUser7->domain_uuid = $domain_uuid;
                           $vExtensionUser7->user_uuid = $user_uuid;
                           $vExtensionUser7->extension_uuid = $extension_uuid;
                           if (!$vExtensionUser7->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1004)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vVoicemail7 = new VVoicemails();
                           $vVoicemail7->domain_uuid = $domain_uuid;
                           $vVoicemail7->voicemail_uuid = $vmUuid7;
                           $vVoicemail7->voicemail_id = strval($extension);
                           $vVoicemail7->voicemail_mail_to = $company_email;
                           $vVoicemail7->voicemail_password = strval(rand(1000, 9999));
                           $vVoicemail7->voicemail_transcription_enabled = "false";
                           $vVoicemail7->voicemail_file = "attach";
                           $vVoicemail7->voicemail_local_after_email = "true";
                           $vVoicemail7->voicemail_enabled = "true";
                           if (!$vVoicemail7->create()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_PA_VM)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUserGroup7 = new VUserGroups();
                           $vUserGroup7->user_group_uuid = $security->uuid();
                           $vUserGroup7->user_uuid = $user_uuid;
                           $vGroupUser7 = VGroups::findFirst([
                              "conditions" => "group_name = :group_name:",
                              "bind" => ["group_name" => "user"],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $vUserGroup7->group_uuid = $vGroupUser7->group_uuid;
                           $vUserGroup7->group_name = $vGroupUser7->group_name;
                           $vUserGroup7->domain_uuid = $domain_uuid;
                           if (!$vUserGroup7->create()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_PA_UG)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vAgent7 = new VCallCenterAgents();
                           $vAgent7->call_center_agent_uuid = $security->uuid();
                           $vAgent7->domain_uuid = $domain_uuid;
                           $vAgent7->user_uuid = $user_uuid;
                           $vAgent7->agent_name = $firstname . " " . $lastname;
                           $vAgent7->agent_type = "callback";
                           $vAgent7->agent_call_timeout = 30;
                           $vAgent7->agent_id = strval($extension);
                           $vAgent7->agent_contact = "user/" . $extension . "@" . $fqdn;
                           $vAgent7->agent_status = "Logged Out";
                           $vAgent7->agent_max_no_answer = 120;
                           if (!$vAgent7->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_PA_AG)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vPinNumber7 = new VPinNumbers();
                           $vPinNumber7->domain_uuid = $domain_uuid;
                           $vPinNumber7->pin_number_uuid = $security->uuid();
                           $vPinNumber7->pin_number = strval(rand(1000, 9999));
                           $vPinNumber7->accountcode = $vExtension->accountcode;
                           $vPinNumber7->enabled = "true";
                           $vPinNumber7->description = strval($extension);
                           $vPinNumber7->insert_user = $user_uuid;
                           $vPinNumber7->insert_date = date("Y-m-d H:i:s");
                           if (!$vPinNumber7->save()) {
                              $this->db->rollback();
                              $messages = $vPinNumber7->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1008)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qCallBehavior7 = new QCallBehaviors();
                           $qCallBehavior7->domain_uuid = $domain_uuid;
                           $qCallBehavior7->extension_uuid = $extension_uuid;
                           $qCallBehavior7->extension = $extension;
                           $qCallBehavior7->forward_no_answer_destination = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior7->forward_no_answer_destination2 = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior7->forward_no_answer_timeout = "30,30,30,30,30";
                           $qCallBehavior7->forward_no_answer_timeout2 = "30,30,30,30,30";
                           $qCallBehavior7->forward_no_answer_enabled = "true,true,true,true,true";
                           $qCallBehavior7->forward_no_answer_enabled2 = "true,true,true,true,true";
                           $qCallBehavior7->type = 1;
                           $qCallBehavior7->enabled = 1;
                           $qCallBehavior7->sso_qr = 1;
                           $qCallBehavior7->sso_number = 1;
                           $qCallBehavior7->call_profiles_colors = "#004D40,#004D40,#004D40,#004D40,#004D40";
                           $qCallBehavior7->insert_user = $user_uuid;
                           $qCallBehavior7->insert_date = date("Y-m-d H:i:s");

                           $this->redis->set($extension . "@" . $vExtension->accountcode . "-click2talk-mode", "");
                           $this->redis->set($extension . "+" . $vExtension->accountcode, "0");
                           $this->redis->set($extension . "-" . $vExtension->accountcode . "-chameleon", "1");
                           $this->redis->set($extension . "-" . $vExtension->accountcode . "-permission", "7");

                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-follow-me-destinations");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-follow-me-destinations2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-me-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-me-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-callScreening");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-callScreening2");

                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-follow-me-destinations", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-follow-me-destinations2", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-callScreening2", ["false", "false", "false", "false", "false"]);

                           if (!$qCallBehavior7->save()) {
                              $this->db->rollback();
                              $messages = $qCallBehavior7->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1009)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $company_email = "l1support@ringq.com";
                           $lastname = "Support";
                           $firstname = "RingQ L1";
                           $extension = 9998;
                           $username = $security->hex(10);
                           $user_uuid = $security->uuid();
                           $salt = $this->security->hash($security->base64(16));
                           $password = $this->security->hash($security->base64(16));
                           $password2 = $security->base64(16);
                           $extension_password = $password2; // md5($salt . $password2);
                           try {
                              $isNoAlias1000 = VExtensions::findFirst([
                                 "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND number_alias IS NULL",
                                 "bind" => [
                                    "domain_uuid" => $domain_uuid,
                                    "extension" => '1000',
                                 ],
                              ]);
                              if ($isNoAlias1000) {
                                 $isNoAlias1000->number_alias = $username;
                                 if (!$isNoAlias1000->save()) {
                                    $this->db->rollback();
                                    $messages = $isNoAlias1000->getMessages();
                                    $message = "";
                                    foreach ($messages as $error) {
                                       $message .= $error . " ";
                                    }
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => trim($message) . " (Code AE1001)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }
                           } catch (\Throwable $e) {
                              error_log(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
                           }
                           $qUser8 = new QUsers();
                           $qUser8->user_uuid = $user_uuid;
                           $qUser8->domain_uuid = $domain_uuid;
                           $qUser8->first_name = $firstname;
                           $qUser8->last_name = $lastname;
                           $qUser8->extension_enabled = true;
                           $qUser8->insert_date = date("Y-m-d H:i:s");
                           $qUser8->user_email = $company_email;
                           $qUser8->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                           if (!$qUser8->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1001)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUser8 = new VUsers();
                           $vUser8->username = $username;
                           $vUser8->user_uuid = $user_uuid;
                           $vUser8->domain_uuid = $domain_uuid;
                           $vUser8->user_email = $company_email;
                           $vUser8->password = $password;
                           $vUser8->user_status = "Logged Out";
                           $vUser8->user_enabled = "true";
                           $vUser8->api_key = $security->base64(32);
                           $vUser8->image = null;
                           $vUser8->first_name = $firstname;
                           $vUser8->last_name = $lastname;
                           $vUser8->insert_user = $user_uuid;
                           $vUser8->insert_date = date("Y-m-d H:i:s");
                           if (!$vUser8->save()) {
                              $this->db->rollback();
                              $messages = $vUser8->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AE1002)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtension8 = new VExtensions();
                           $vExtension8->extension_uuid = $extension_uuid = $security->uuid();
                           $vExtension8->domain_uuid = $domain_uuid;
                           $vExtension8->extension = $extension;
                           $vExtension8->number_alias = $username;
                           $vExtension8->password = $extension_password;
                           $vExtension8->enabled = "true";
                           $vExtension8->extension_type = "default";
                           $vExtension8->accountcode = $fqdn;
                           $vExtension8->directory_visible = "true";
                           $vExtension8->directory_exten_visible = "true";
                           $vExtension8->limit_max = "10"; // <!-- Should be based on purchased package
                           $vExtension8->limit_destination = "!USER_BUSY";
                           $vExtension8->effective_caller_id_name = $firstname . " " . $lastname;
                           $vExtension8->effective_caller_id_number = $extension;
                           $vExtension8->do_not_disturb = "false";
                           $vExtension8->forward_all_enabled = "false";
                           $vExtension8->forward_all_enabled2 = "false";
                           $vExtension8->forward_busy_enabled = "false";
                           $vExtension8->forward_busy_enabled2 = "false";
                           $vExtension8->forward_no_answer_enabled = "true";
                           $vExtension8->forward_no_answer_enabled2 = "true";
                           $vExtension8->forward_user_not_registered_enabled = "false";
                           $vExtension8->forward_user_not_registered_enabled2 = "false";
                           $vExtension8->forward_no_answer_destination = "*99" . $extension;
                           $vExtension8->forward_no_answer_destination2 = "*99" . $extension;
                           $vExtension8->voicemail_enabled = "enabled";
                           $vExtension8->voicemail_file = "attach";
                           $vExtension8->voicemail_uuid = $vmUuid8 = $security->uuid();
                           $vExtension8->call_screen_enabled = "false";
                           $vExtension8->user_context = $fqdn;
                           $vExtension8->call_timeout = 30;
                           $vExtension8->call_timeout2 = 30;
                           $vExtension8->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                           $vExtension8->insert_user = $user_uuid;
                           $vExtension8->insert_date = date("Y-m-d H:i:s");
                           if (!$vExtension8->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1003)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtensionUser8 = new VExtensionUsers();
                           $vExtensionUser8->extension_user_uuid = $security->uuid();
                           $vExtensionUser8->domain_uuid = $domain_uuid;
                           $vExtensionUser8->user_uuid = $user_uuid;
                           $vExtensionUser8->extension_uuid = $extension_uuid;
                           if (!$vExtensionUser8->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1004)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vVoicemail8 = new VVoicemails();
                           $vVoicemail8->domain_uuid = $domain_uuid;
                           $vVoicemail8->voicemail_uuid = $vmUuid8;
                           $vVoicemail8->voicemail_id = strval($extension);
                           $vVoicemail8->voicemail_mail_to = $company_email;
                           $vVoicemail8->voicemail_password = strval(rand(1000, 9999));
                           $vVoicemail8->voicemail_transcription_enabled = "false";
                           $vVoicemail8->voicemail_file = "attach";
                           $vVoicemail8->voicemail_local_after_email = "true";
                           $vVoicemail8->voicemail_enabled = "true";
                           if (!$vVoicemail8->create()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_L1_VM)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUserGroup8 = new VUserGroups();
                           $vUserGroup8->user_group_uuid = $security->uuid();
                           $vUserGroup8->user_uuid = $user_uuid;
                           $vGroupUser8 = VGroups::findFirst([
                              "conditions" => "group_name = :group_name:",
                              "bind" => ["group_name" => "user"],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $vUserGroup8->group_uuid = $vGroupUser8->group_uuid;
                           $vUserGroup8->group_name = $vGroupUser8->group_name;
                           $vUserGroup8->domain_uuid = $domain_uuid;
                           if (!$vUserGroup8->create()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_L1_UG)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vAgent8 = new VCallCenterAgents();
                           $vAgent8->call_center_agent_uuid = $security->uuid();
                           $vAgent8->domain_uuid = $domain_uuid;
                           $vAgent8->user_uuid = $user_uuid;
                           $vAgent8->agent_name = $firstname . " " . $lastname;
                           $vAgent8->agent_type = "callback";
                           $vAgent8->agent_call_timeout = 30;
                           $vAgent8->agent_id = strval($extension);
                           $vAgent8->agent_contact = "user/" . $extension . "@" . $fqdn;
                           $vAgent8->agent_status = "Logged Out";
                           $vAgent8->agent_max_no_answer = 120;
                           if (!$vAgent8->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_L1_AG)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vPinNumber8 = new VPinNumbers();
                           $vPinNumber8->domain_uuid = $domain_uuid;
                           $vPinNumber8->pin_number_uuid = $security->uuid();
                           $vPinNumber8->pin_number = strval(rand(1000, 9999));
                           $vPinNumber8->accountcode = $vExtension->accountcode;
                           $vPinNumber8->enabled = "true";
                           $vPinNumber8->description = strval($extension);
                           $vPinNumber8->insert_user = $user_uuid;
                           $vPinNumber8->insert_date = date("Y-m-d H:i:s");
                           if (!$vPinNumber8->save()) {
                              $this->db->rollback();
                              $messages = $vPinNumber8->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1008)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qCallBehavior8 = new QCallBehaviors();
                           $qCallBehavior8->domain_uuid = $domain_uuid;
                           $qCallBehavior8->extension_uuid = $extension_uuid;
                           $qCallBehavior8->extension = $extension;
                           $qCallBehavior8->forward_no_answer_destination = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior8->forward_no_answer_destination2 = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior8->forward_no_answer_timeout = "30,30,30,30,30";
                           $qCallBehavior8->forward_no_answer_timeout2 = "30,30,30,30,30";
                           $qCallBehavior8->forward_no_answer_enabled = "true,true,true,true,true";
                           $qCallBehavior8->forward_no_answer_enabled2 = "true,true,true,true,true";
                           $qCallBehavior8->type = 1;
                           $qCallBehavior8->enabled = 1;
                           $qCallBehavior8->sso_qr = 1;
                           $qCallBehavior8->sso_number = 1;
                           $qCallBehavior8->call_profiles_colors = "#004D40,#004D40,#004D40,#004D40,#004D40";
                           $qCallBehavior8->insert_user = $user_uuid;
                           $qCallBehavior8->insert_date = date("Y-m-d H:i:s");

                           $this->redis->set($extension . "@" . $vExtension->accountcode . "-click2talk-mode", "");
                           $this->redis->set($extension . "+" . $vExtension->accountcode, "0");
                           $this->redis->set($extension . "-" . $vExtension->accountcode . "-chameleon", "1");
                           $this->redis->set($extension . "-" . $vExtension->accountcode . "-permission", "7");

                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-follow-me-destinations");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-follow-me-destinations2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-me-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-me-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-callScreening");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-callScreening2");

                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-follow-me-destinations", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-follow-me-destinations2", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-callScreening2", ["false", "false", "false", "false", "false"]);

                           if (!$qCallBehavior8->save()) {
                              $this->db->rollback();
                              $messages = $qCallBehavior8->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1009)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $company_email = "l2support@ringq.com";
                           $lastname = "Support";
                           $firstname = "RingQ L2";
                           $extension = 9999;
                           $username = $security->hex(10);
                           $user_uuid = $security->uuid();
                           $salt = $this->security->hash($security->base64(16));
                           $password = $this->security->hash($security->base64(16));
                           $password2 = $security->base64(16);
                           $extension_password = $password2; // md5($salt . $password2);
                           try {
                              $isNoAlias1000 = VExtensions::findFirst([
                                 "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension: AND number_alias IS NULL",
                                 "bind" => [
                                    "domain_uuid" => $domain_uuid,
                                    "extension" => '1000',
                                 ],
                              ]);
                              if ($isNoAlias1000) {
                                 $isNoAlias1000->number_alias = $username;
                                 if (!$isNoAlias1000->save()) {
                                    $this->db->rollback();
                                    $messages = $isNoAlias1000->getMessages();
                                    $message = "";
                                    foreach ($messages as $error) {
                                       $message .= $error . " ";
                                    }
                                    $response = json_encode([
                                       "result" => "error",
                                       "message" => trim($message) . " (Code AE1001)",
                                    ]);
                                    $this->response->setContent($response);
                                    return $this->response;
                                 }
                              }
                           } catch (\Throwable $e) {
                              error_log(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
                           }
                           $vUser9 = new QUsers();
                           $vUser9->user_uuid = $user_uuid;
                           $vUser9->domain_uuid = $domain_uuid;
                           $vUser9->first_name = $firstname;
                           $vUser9->last_name = $lastname;
                           $vUser9->extension_enabled = true;
                           $vUser9->insert_date = date("Y-m-d H:i:s");
                           $vUser9->user_email = $company_email;
                           $vUser9->password = $password2;
                           if (!$vUser9->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1001)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUser9 = new VUsers();
                           $vUser9->username = $username;
                           $vUser9->user_uuid = $user_uuid;
                           $vUser9->domain_uuid = $domain_uuid;
                           $vUser9->user_email = $company_email;
                           $vUser9->password = $password;
                           $vUser9->user_status = "Logged Out";
                           $vUser9->user_enabled = "true";
                           $vUser9->api_key = $security->base64(32);
                           $vUser9->image = null;
                           $vUser9->first_name = $firstname;
                           $vUser9->last_name = $lastname;
                           $vUser9->insert_user = $user_uuid;
                           $vUser9->insert_date = date("Y-m-d H:i:s");
                           if (!$vUser9->save()) {
                              $this->db->rollback();
                              $messages = $vUser9->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AE1002)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtension9 = new VExtensions();
                           $vExtension9->extension_uuid = $extension_uuid = $security->uuid();
                           $vExtension9->domain_uuid = $domain_uuid;
                           $vExtension9->extension = $extension;
                           $vExtension9->number_alias = $username;
                           $vExtension9->password = $extension_password;
                           $vExtension9->enabled = "true";
                           $vExtension9->extension_type = "default";
                           $vExtension9->accountcode = $fqdn;
                           $vExtension9->directory_visible = "true";
                           $vExtension9->directory_exten_visible = "true";
                           $vExtension9->limit_max = "10"; // <!-- Should be based on purchased package
                           $vExtension9->limit_destination = "!USER_BUSY";
                           $vExtension9->effective_caller_id_name = $firstname . " " . $lastname;
                           $vExtension9->effective_caller_id_number = $extension;
                           $vExtension9->do_not_disturb = "false";
                           $vExtension9->forward_all_enabled = "false";
                           $vExtension9->forward_all_enabled2 = "false";
                           $vExtension9->forward_busy_enabled = "false";
                           $vExtension9->forward_busy_enabled2 = "false";
                           $vExtension9->forward_no_answer_enabled = "true";
                           $vExtension9->forward_no_answer_enabled2 = "true";
                           $vExtension9->forward_user_not_registered_enabled = "false";
                           $vExtension9->forward_user_not_registered_enabled2 = "false";
                           $vExtension9->forward_no_answer_destination = "*99" . $extension;
                           $vExtension9->forward_no_answer_destination2 = "*99" . $extension;
                           $vExtension9->voicemail_enabled = "enabled";
                           $vExtension9->voicemail_file = "attach";
                           $vExtension9->voicemail_uuid = $vmUuid9 = $security->uuid();
                           $vExtension9->call_screen_enabled = "false";
                           $vExtension9->user_context = $fqdn;
                           $vExtension9->call_timeout = 30;
                           $vExtension9->call_timeout2 = 30;
                           $vExtension9->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                           $vExtension9->insert_user = $user_uuid;
                           $vExtension9->insert_date = date("Y-m-d H:i:s");
                           if (!$vExtension9->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1003)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vExtensionUser9 = new VExtensionUsers();
                           $vExtensionUser9->extension_user_uuid = $security->uuid();
                           $vExtensionUser9->domain_uuid = $domain_uuid;
                           $vExtensionUser9->user_uuid = $user_uuid;
                           $vExtensionUser9->extension_uuid = $extension_uuid;
                           if (!$vExtensionUser9->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code AE1004)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vVoicemail9 = new VVoicemails();
                           $vVoicemail9->domain_uuid = $domain_uuid;
                           $vVoicemail9->voicemail_uuid = $vmUuid9;
                           $vVoicemail9->voicemail_id = strval($extension);
                           $vVoicemail9->voicemail_mail_to = $company_email;
                           $vVoicemail9->voicemail_password = strval(rand(1000, 9999));
                           $vVoicemail9->voicemail_transcription_enabled = "false";
                           $vVoicemail9->voicemail_file = "attach";
                           $vVoicemail9->voicemail_local_after_email = "true";
                           $vVoicemail9->voicemail_enabled = "true";
                           if (!$vVoicemail9->create()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_L2_VM)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vUserGroup9 = new VUserGroups();
                           $vUserGroup9->user_group_uuid = $security->uuid();
                           $vUserGroup9->user_uuid = $user_uuid;
                           $vGroupUser9 = VGroups::findFirst([
                              "conditions" => "group_name = :group_name:",
                              "bind" => ["group_name" => "user"],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $vUserGroup9->group_uuid = $vGroupUser9->group_uuid;
                           $vUserGroup9->group_name = $vGroupUser9->group_name;
                           $vUserGroup9->domain_uuid = $domain_uuid;
                           if (!$vUserGroup9->create()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_L2_UG)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vAgent9 = new VCallCenterAgents();
                           $vAgent9->call_center_agent_uuid = $security->uuid();
                           $vAgent9->domain_uuid = $domain_uuid;
                           $vAgent9->user_uuid = $user_uuid;
                           $vAgent9->agent_name = $firstname . " " . $lastname;
                           $vAgent9->agent_type = "callback";
                           $vAgent9->agent_call_timeout = 30;
                           $vAgent9->agent_id = strval($extension);
                           $vAgent9->agent_contact = "user/" . $extension . "@" . $fqdn;
                           $vAgent9->agent_status = "Logged Out";
                           $vAgent9->agent_max_no_answer = 120;
                           if (!$vAgent9->save()) {
                              $this->db->rollback();
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => "An error has occurred. (Code RV_L2_AG)",
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $vPinNumber9 = new VPinNumbers();
                           $vPinNumber9->domain_uuid = $domain_uuid;
                           $vPinNumber9->pin_number_uuid = $security->uuid();
                           $vPinNumber9->pin_number = strval(rand(1000, 9999));
                           $vPinNumber9->accountcode = $vExtension->accountcode;
                           $vPinNumber9->enabled = "true";
                           $vPinNumber9->description = strval($extension);
                           $vPinNumber9->insert_user = $user_uuid;
                           $vPinNumber9->insert_date = date("Y-m-d H:i:s");
                           if (!$vPinNumber9->save()) {
                              $this->db->rollback();
                              $messages = $vPinNumber9->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1008)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $qCallBehavior9 = new QCallBehaviors();
                           $qCallBehavior9->domain_uuid = $domain_uuid;
                           $qCallBehavior9->extension_uuid = $extension_uuid;
                           $qCallBehavior9->extension = $extension;
                           $qCallBehavior9->forward_no_answer_destination = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior9->forward_no_answer_destination2 = "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . "," . "*99" . $extension . ",";
                           $qCallBehavior9->forward_no_answer_timeout = "30,30,30,30,30";
                           $qCallBehavior9->forward_no_answer_timeout2 = "30,30,30,30,30";
                           $qCallBehavior9->forward_no_answer_enabled = "true,true,true,true,true";
                           $qCallBehavior9->forward_no_answer_enabled2 = "true,true,true,true,true";
                           $qCallBehavior9->type = 1;
                           $qCallBehavior9->enabled = 1;
                           $qCallBehavior9->sso_qr = 1;
                           $qCallBehavior9->sso_number = 1;
                           $qCallBehavior9->call_profiles_colors = "#004D40,#004D40,#004D40,#004D40,#004D40";
                           $qCallBehavior9->insert_user = $user_uuid;
                           $qCallBehavior9->insert_date = date("Y-m-d H:i:s");
                           $this->redis->set($extension . "@" . $vExtension->accountcode . "-click2talk-mode", "");
                           $this->redis->set($extension . "+" . $vExtension->accountcode, "0");
                           $this->redis->set($extension . "-" . $vExtension->accountcode . "-chameleon", "1");
                           $this->redis->set($extension . "-" . $vExtension->accountcode . "-permission", "7");

                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-follow-me-destinations");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-follow-me-destinations2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-all-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-me-enabled");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-forward-me-enabled2");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-callScreening");
                           $this->redis->del($extension . "@" . $vExtension->accountcode . "-callScreening2");

                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-destination", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-destination2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-destination2", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-follow-me-destinations", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-follow-me-destinations2", ["", "", "", "", "", "", "", "", "", "", "", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-timeout2", ["", "", "", "", ""]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-busy-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                           $this->redis->rpush($extension . "@" . $vExtension->accountcode . "-callScreening2", ["false", "false", "false", "false", "false"]);

                           if (!$qCallBehavior9->save()) {
                              $this->db->rollback();
                              $messages = $qCallBehavior9->getMessages();
                              $message = "";
                              foreach ($messages as $error) {
                                 $message .= $error . " ";
                              }
                              $response = json_encode([
                                 "result" => "error",
                                 "message" => trim($message) . " (Code AS1009)",
                                 "content" => [],
                              ]);
                              $this->response->setContent($response);
                              return $this->response;
                           }
                           $this->db->commit();
                           // ApiController index caches these per-subdomain in Redis with no TTL and never
                           // invalidates them — stale entries hide the support accounts created above
                           $apiCacheKeys = [
                              "vExtensions", "allVusers", "allQUsers", "allDialplans",
                              "vCallCenterAgents", "vCallCenterAgents5", "allVCallCenterTiers",
                              "vCallCenterQueues", "allVRingGroups", "vIvrMenus",
                              "vIvrMenuOptionsRaw", "queueAssigmentsAgents",
                           ];
                           foreach ($apiCacheKeys as $apiCacheKey) {
                              $this->redis->del($apiCacheKey . "-" . $fqdn);
                           }
                           $files = glob("/var/cache/fusionpbx/*");
                           foreach ($files as $file) {
                              if (is_file($file)) {
                                 unlink($file);
                              }
                           }
                           // Clear cached directory lookups for the new domain — a register attempt made
                           // before activation caches a "user not found" response that blocks all later registers
                           $directoryCaches = glob("/var/cache/ringq/directory.*@" . $fqdn);
                           foreach ($directoryCaches as $directoryCache) {
                              if (is_file($directoryCache)) {
                                 unlink($directoryCache);
                              }
                           }
                           if (!empty($isCommio)) {
                              if (file_exists("/var/cache/ringq/dialplan." . $fqdn)) {
                                 unlink("/var/cache/ringq/dialplan." . $fqdn);
                              }
                              if (file_exists("/var/cache/ringq/configuration.sofia.conf." . gethostname())) {
                                 unlink("/var/cache/ringq/configuration.sofia.conf." . gethostname());
                              }
                              if (file_exists("/var/cache/ringq/dialplan.public")) {
                                 unlink("/var/cache/ringq/dialplan.public");
                              }
                           } 
                           $emitGatewaystart = function (array $payload) {
                              for ($attempt = 0; $attempt < 2; $attempt++) {
                                 try {
                                    $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                                       "persistent" => false,
                                    ]));
                                    $client->initialize();
                                    $client->emit("gatewaystart", $payload);
                                    return true;
                                 } catch (\Throwable $e) {
                                    error_log("verify gatewaystart emit failed (attempt " . ($attempt + 1) . "): " . $e->getMessage());
                                    usleep(500000);
                                 }
                              }
                              return false;
                           };
                           // Mirror gatewayAction POST type=1 (create): emit an empty uuid so the signal
                           // server only rescans the sofia profiles to pick up the new gateway. Emitting the
                           // new gateway uuid makes signal.js run "sofia profile external killgw <uuid>",
                           // which executes after the restart/rescan and unloads the just-created gateway,
                           // leaving it offline (red) until something else triggers a rescan.
                           $emitGatewaystart(["uuid" => ""]);
                           $response = json_encode([
                              "result" => "success",
                              "message" => "Login success.",
                              "content" => $authenticated,
                           ]);
                           $this->response->setContent($response);
                           return $this->response;
                        }
                     }
                  } else {
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Sorry, another email will be sent with a new verification code. (Code RV1015)",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               } else {
                  $verify = rand(100000000, 999999999);
                  $exists = $this->redis->exists("backup-verify-" . $parameters["email"]);
                  if ($exists) {
                     $backupVerify = $this->redis->hgetall("backup-verify-" . $parameters["email"]);
                     $backupVerify = array_merge($backupVerify, ["verify" => $verify]);
                     $this->redis->hmset("verify-" . $parameters["email"], $backupVerify);
                     $this->redis->lrem("register", 0, $parameters["email"]);
                     $this->redis->lpush("register", $parameters["email"]);
                     $this->redis->expire("verify-" . $parameters["email"], 900);
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Sorry, another email will be sent with a new verification code.",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  } else {
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Sorry, verification does not exists.",
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
               }
            }
         } else {
            $this->response->redirect("/");
         }
      } else {
         $this->response->redirect("/");
      }
   }

   public function walletAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");

      $type = $this->request->getPost("type", "string");
      $username = $this->request->getPost("username", "string");
      $companyName = $this->request->getPost("company_name", "string");
      $email = $this->request->getPost("email", "string");
      $phone = $this->request->getPost("phone", "string");
      $fullName = $this->request->getPost("full_name", "string");
      $title = $this->request->getPost("title", "string");
      $image = $this->request->getPost("image", "string");
      $host = $this->request->getPost("host", "string");

      if ($type == 'google') {
         try {
            $keyFile = '/var/www/ringq/ringq-public-471605-e5489f4dae77.json';
            
            $client = new \Google\Client();
            $client->setAuthConfig($keyFile);
            $client->addScope('https://www.googleapis.com/auth/wallet_object.issuer');

            $service = new \Google\Service\Walletobjects($client);

            // Define IDs
            $issuerId = '3388000000023063525';
            $classSuffix = 'AgentBusinessCard';
            $classId = $issuerId . '.' . $classSuffix;
            $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);
            $objectId = $issuerId . '.' . $safeUsername . '-' . uniqid();

            // Check/Create Class
            try {
               $service->genericclass->get($classId);
            } catch (\Google\Service\Exception $e) {
               if ($e->getCode() == 404) {
                  $newClass = new \Google\Service\Walletobjects\GenericClass([
                     'id' => $classId,
                     'reviewStatus' => 'DRAFT'
                  ]);
                  $service->genericclass->insert($newClass);
               } else {
                  throw $e;
               }
            }

            $iconSource = "https://$host:8443/avr/$image";
            if (!file_exists("/var/www/ringq/public/avr/$image") || !is_file("/var/www/ringq/public/avr/$image")) {
               $iconSource = "https://$host:8443/img/ringq-square.png";
            }

            // Create Object
            $passObject = new \Google\Service\Walletobjects\GenericObject([
               'id' => $objectId,
               'classId' => $classId,
               'state' => 'ACTIVE',
               'header' => [
                  'defaultValue' => ['language' => 'en-US', 'value' => $fullName]
               ],

               'hexBackgroundColor' => '#101A38',
               'logo' => [
                  'sourceUri' => ['uri' => $iconSource],
                  'contentDescription' => [
                     'defaultValue' => [
                        'language' => 'en-US',
                        'value' => 'RingQ Logo'
                     ]
                  ]
               ],
               'cardTitle' => [
                  'defaultValue' => [
                     'language' => 'en-US',
                     'value' => $companyName
                  ]
               ],
               'textModulesData' => [
                  [
                     'header' => 'AGENT NAME',
                     'body' => $fullName,
                     'id' => 'agent_name'
                  ],

                  [
                     'header' => 'COMPANY',
                     'body' => $companyName,
                     'id' => 'company_info'
                  ],
                  [
                     'header' => 'EMAIL',
                     'body' => $email,
                     'id' => 'email_info'
                  ],
                  [
                     'header' => 'PHONE',
                     'body' => $phone,
                     'id' => 'phone_info'
                  ]
               ],
               'linksModuleData' => [
                  'uris' => [
                     [
                        'uri' => "tel:$phone",
                        'description' => 'Call',
                        'id' => 'link_phone'
                     ],
                     [
                        'uri' => "mailto:$email",
                        'description' => 'Email',
                        'id' => 'link_email'
                     ],
                     [
                        'uri' => "https://$host/cloudapp",
                        'description' => 'Visit Website',
                        'id' => 'link_website'
                     ]
                  ]
               ],
               'barcode' => [
                  'type' => 'QR_CODE',
                  'value' => "BEGIN:VCARD\nVERSION:3.0\nFN:$fullName\nORG:$companyName\nTEL:$phone\nEMAIL:$email\nEND:VCARD",
                  'alternateText' => 'Scan to save contact'
               ]
            ]);

            try {
               $service->genericobject->insert($passObject);
            } catch (\Exception $e) {
               // Ignore if exists
            }

            // Generate JWT
            $credentials = json_decode(file_get_contents($keyFile), true);
            $serviceAccountEmail = $credentials['client_email'];
            $privateKey = $credentials['private_key'];

            $claims = [
               "iss" => $serviceAccountEmail,
               "aud" => "google",
               "origins" => ["https://$host"],
               "typ" => "savetowallet",
               "payload" => [
                  "genericObjects" => [
                        ['id' => $objectId]
                  ]
               ]
            ];

            $token = \Firebase\JWT\JWT::encode($claims, $privateKey, 'RS256');
            $saveUrl = "https://pay.google.com/gp/v/save/" . $token;

            $response = json_encode([
               "result" => "success",
               "message" => "Google Wallet pass generated",
               "url" => $saveUrl
            ]);
         } catch (\Exception $e) {
            $response = json_encode([
               "result" => "error",
               "message" => "Google Wallet error: " . ControllerBase::safeErrorMessage($e),
               "url" => ""
            ]);
         }
      } else if ($type == 'apple') {
         try {
            // --- 1. CONFIGURATION ---
            $passIdentifier = 'pass.com.ringq.agent'; // Replace with your Pass Type ID
            $teamIdentifier = '6GP58F5X59';         // Replace with your Team ID
            $certPath = '/var/www/ringq/certs/certificate.pem'; 
            $wwdrPath = '/var/www/ringq/certs/wwdr.pem';
            $baseDir = '/var/www/webapp/passes';
            $baseUrl = 'https://' . $host . '/passes';

            if (!file_exists($certPath)) throw new \Exception("Apple certificate not found.");
            if (!file_exists($wwdrPath)) throw new \Exception("WWDR certificate not found.");
            if (!is_dir($baseDir)) mkdir($baseDir, 0777, true);

            $tempId = $username . '_' . time();
            $passDir = "/tmp/pass_$tempId";
            if (!is_dir($passDir)) mkdir($passDir, 0777, true);

            // --- 2. CREATE pass.json ---
            $passData = [
               'formatVersion' => 1,
               'passTypeIdentifier' => $passIdentifier,
               'serialNumber' => $tempId,
               'teamIdentifier' => $teamIdentifier,
               'organizationName' => $companyName ?: 'RingQ',
               'description' => 'Business Card for ' . $fullName,
               'logoText' => $companyName ?: 'RingQ',
               'foregroundColor' => 'rgb(255, 255, 255)',
               'labelColor' => 'rgb(255, 255, 255)',
               'backgroundColor' => 'rgb(16, 26, 56)',
               'generic' => [
                  'primaryFields' => [
                     [
                        'key' => 'name', 
                        'value' => $fullName
                     ],
                  ],
                  'secondaryFields' => [],
                  'auxiliaryFields' => [
                     [
                        'key' => 'phone', 
                        'label' => 'PHONE', 
                        'value' => $phone
                     ],
                     [
                        'key' => 'email', 
                        'label' => 'EMAIL', 
                        'value' => $email
                     ]
                  ],
                  'backFields' => [
                     [
                        'key' => 'name', 
                        'label' => 'NAME', 
                        'value' => $fullName
                     ],
                     [
                        'key' => 'company', 
                        'label' => 'COMPANY', 
                        'value' => $companyName
                     ],
                     [
                        'key' => 'email', 
                        'label' => 'EMAIL', 
                        'value' => $email
                     ],
                     [
                        'key' => 'phone', 
                        'label' => 'PHONE', 
                        'value' => $phone
                     ],
                     [
                        'key' => 'website', 
                        'label' => 'WEBSITE', 
                        'value' => "https://$host/cloudapp"
                     ]
                  ]
               ],
               'barcode' => [
                  'format' => 'PKBarcodeFormatQR',
                  'message' => "BEGIN:VCARD\nVERSION:3.0\nFN:$fullName\nORG:$companyName\nTEL:$phone\nEMAIL:$email\nEND:VCARD",
                  'messageEncoding' => 'iso-8859-1'
               ]
            ];
            file_put_contents("$passDir/pass.json", json_encode($passData));

            // --- 3. ADD ASSETS (Icon is mandatory) ---
            $iconSource = "/var/www/ringq/public/avr/$image"; 
            if (!file_exists($iconSource) || !is_file($iconSource)) { 
               $iconSource = '/var/www/ringq/public/img/ringq-square.png';
            }
            if (file_exists($iconSource)) {
               copy($iconSource, "$passDir/icon.png");
               copy($iconSource, "$passDir/icon@2x.png");
               copy($iconSource, "$passDir/logo.png");
               copy($iconSource, "$passDir/logo@2x.png");
            }

            // --- 4. GENERATE manifest.json ---
            $manifest = [];
            $files = scandir($passDir);
            foreach ($files as $file) {
               if ($file == '.' || $file == '..') continue;
               $manifest[$file] = sha1_file("$passDir/$file");
            }
            file_put_contents("$passDir/manifest.json", json_encode($manifest));

            // --- 5. SIGN THE MANIFEST ---
            $signatureFile = "$passDir/signature";
            $manifestFile = "$passDir/manifest.json";
            $certContent = file_get_contents($certPath);
            
            openssl_pkcs7_sign(
               $manifestFile, 
               $signatureFile, 
               $certContent, 
               $certContent, 
               [], 
               PKCS7_BINARY | PKCS7_DETACHED, 
               $wwdrPath
            );

            // Extract raw signature from PKCS7 output
            $signatureData = file_get_contents($signatureFile);
            $begin = 'filename="smime.p7s"' . "\n\n";
            $end = "\n\n-";
            $parts = explode($begin, $signatureData);
            if (isset($parts[1])) {
               $parts = explode($end, $parts[1]);
               $signatureData = base64_decode($parts[0]);
               file_put_contents($signatureFile, $signatureData);
            }

            // --- 6. PACKAGE AS .PKPASS (ZIP) ---
            $pkpassFile = "$baseDir/agent_$tempId.pkpass";
            $zip = new \ZipArchive();
            $zip->open($pkpassFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            foreach (scandir($passDir) as $file) {
               if ($file == '.' || $file == '..') continue;
               $zip->addFile("$passDir/$file", $file);
            }
            $zip->close();

            // Cleanup
            array_map('unlink', glob("$passDir/*"));
            rmdir($passDir);

            $response = json_encode([
               "result" => "success",
               "message" => "Apple Wallet pass generated",
               "url" => "$baseUrl/agent_$tempId.pkpass"
            ]);
         } catch (\Exception $e) {
            $response = json_encode([
               "result" => "error",
               "message" => "Apple Wallet error: " . ControllerBase::safeErrorMessage($e),
               "url" => ""
            ]);
         }
      }

      $this->response->setContent($response);
      return $this->response;
   }

   public function calllogAction($type = 0, $username = "", $number = "", $queue = 0, $direction = "", $objectType = "", $sfUserId = "", $continent = "", $city = "", $recordId = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if ($this->request->isGet()) {
         // Check username, in order to get the domain uuid
         // If there is, then set the mobile number and the as job for uploading 
         if (intval($type) == 1) {
            $parameters = [];
            $parameters["username"] = $username;
            $parameters["number"] = $number;
            $queue = intval($queue);
            $calllogForm = new CalllogForm();
            if (!$calllogForm->isValid($parameters)) {
               $messages = $calllogForm->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code RC1000)",
                  "content" => [],
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $vUser = VUsers::findFirst(
               [
                  "conditions" => "username = :username:",
                  "bind" =>
                     [
                        "username" => $parameters["username"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]
            );
            if ($vUser) {
               $vDomain = VDomains::findFirst([
                  "conditions" => "domain_uuid = :domain_uuid:",
                  "bind" => [
                     "domain_uuid" => $vUser->domain_uuid,
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]);
               if ($vDomain) {
                  $vExtensionUser = VExtensionUsers::findFirst(
                     [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                        "bind" => [
                           "domain_uuid" => $vDomain->domain_uuid,
                           "user_uuid" => $vUser->user_uuid,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]
                  );
                  if ($vExtensionUser) {
                     $vExtension = VExtensions::findFirst(
                        [
                           "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid =:extension_uuid:",
                           "bind" => [
                              "domain_uuid" => $vDomain->domain_uuid,
                              "extension_uuid" => $vExtensionUser->extension_uuid,
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                        ]
                     );
                     if (!$vExtension) {
                        $response = json_encode([
                           "result" => "error",
                           "message" => "Sorry, invalid request has been made.",
                           "content" => [],
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     }
                     $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                        "persistent" => false,
                     ]));
                     $client->initialize();
                     $client->emit("recordingClick2Call", ["subdomain" => $vDomain->domain_name, "extension" => $vExtension->extension]);
                     $callSessionKey = "sf-keys:$number";
                     $this->redis->rPush($callSessionKey, json_encode([
                        'domain_name' => $vDomain->domain_name,
                        'domain_uuid' => $vDomain->domain_uuid,
                        'contactNumber' => $number,
                        'queueExtension' => $queue,
                        'status' => $direction,
                        'objectType' => $objectType,
                        'sfUserId' => $sfUserId,
                        'timeZone' => $continent . '/' . $city,
                        'recordId' => $recordId,
                        'time' => time()
                     ]));
                     $this->redis->expire($callSessionKey, 86400);
                     $this->redis->sAdd("sf-keys-index", $callSessionKey);

                     $response = [];
                     $this->response->setContent(json_encode($response));
                     return $this->response;
                  }
               }
            }
         }
         if (intval($type) == 2) {
            $parameters = [];
            $parameters["username"] = $username;
            $parameters["number"] = $number;
            $queue = intval($queue);
            $calllogForm = new CalllogForm();
            if (!$calllogForm->isValid($parameters)) {
               $messages = $calllogForm->getMessages();
               $message = "";
               foreach ($messages as $error) {
                  $message .= $error . " ";
               }
               $response = json_encode([
                  "result" => "error",
                  "message" => trim($message) . " (Code RC1000)",
                  "content" => [],
               ]);
               $this->response->setContent($response);
               return $this->response;
            }
            $vUser = VUsers::findFirst(
               [
                  "conditions" => "username = :username:",
                  "bind" =>
                     [
                        "username" => $parameters["username"],
                     ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]
            );
            if ($vUser) {
               $vDomain = VDomains::findFirst([
                  "conditions" => "domain_uuid = :domain_uuid:",
                  "bind" => [
                     "domain_uuid" => $vUser->domain_uuid,
                  ],
                  "bindTypes" => [Column::BIND_PARAM_STR],
               ]);
               if ($vDomain) {
                  $integration = $this->redis->hgetall("integration:{$vDomain->domain_name}-salesforce");

                  $queries = [
                     "Contact" => "SELECT Id, Phone FROM Contact WHERE Phone = '$number'",
                     "Account" => "SELECT Id, Phone FROM Account WHERE Phone = '$number'",
                     "Lead" => "SELECT Id, Phone FROM Lead WHERE Phone = '$number'"
                  ];

                  $results = [];
                  foreach ($queries as $object => $query) {
                     $queryUrl = $integration['url'] . '/services/data/v62.0/query/?q=' . urlencode($query);
                     $ch = curl_init($queryUrl);
                     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                     curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Authorization: Bearer " . $integration['access_token'],
                        "Content-Type: application/json"
                     ]);
                     $response = curl_exec($ch);
                     $queryHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                     $queryErr = curl_error($ch);
                     curl_close($ch);
                     $query_result = json_decode($response, true);
                     if (!empty($query_result['records'])) {
                        $results[$object] = $query_result;
                     }
                  }

                  if (empty($results)) {
                     $qContacts = QContacts::find([
                        "columns" => "first_name, middle_name, last_name, email, company_name, address_1, address_2, city, state, country, postal, phone, phone2, email2",
                        "conditions" => "phone = :phone: OR phone2 = :phone2:",
                        "bind" => [
                           "phone" => $number,
                           "phone2" => $number,
                        ],
                        "limit" => 1
                     ]);

                     if ($qContacts->count()) {
                        $qContactsRecord = $qContacts->getFirst();

                        $ch = curl_init("https://ringq5-dev-ed.develop.my.salesforce.com/services/data/v62.0/sobjects/Contact");
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                           "FirstName" => $qContactsRecord->first_name,
                           "LastName" => $qContactsRecord->last_name,
                           "Email" => $qContactsRecord->email,
                           "Phone" => $number
                        ]));
                        curl_setopt($ch, FontWeight::HTTPHEADER, [
                           "Authorization: Bearer 00DQy00000K9Kv5!AQEAQI.O1cPd8JNyw441PBVy70oZLbpQ5cUgjlvZYqjhDJtPh.J.tgpcZ9J5xOfuQUtJiRS56NjDaGyRdMUN_7FJtSt_XnOU",
                           "Content-Type: application/json"
                        ]);
                        $response = curl_exec($ch);
                        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($http_status >= 200 && $http_status < 300) {
                           return $this->response->setJsonContent([
                              'result' => 'created',
                           ], JSON_PRETTY_PRINT);
                        } else {
                           return $this->response->setJsonContent([
                              'result' => 'failed',
                           ], JSON_PRETTY_PRINT);
                        }
                     }
                     return $this->response->setJsonContent([
                        'result' => 'not_found',
                     ], JSON_PRETTY_PRINT);
                  }
                  return $this->response->setJsonContent([
                     'result' => 'not existing',
                     'data' => $results
                  ], JSON_PRETTY_PRINT);
               }
            }
         }
      } else if ($this->request->isPost()) {
         $parameters = [];
         $parameters["dialer_name"] = !empty($this->request->getPost("dialer_name", "string")) ? $this->request->getPost("dialer_name", "string") : "";
         if (empty($parameters["dialer_name"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
         }
         $this->request->setStrictHostCheck(true);
         $uri = $this->request->getHttpHost();
         if (str_contains($parameters["dialer_name"], "@")) {
            $parameters = explode("@", $parameters["dialer_name"]);
            $username = reset($parameters);
            $username = $this->filter->sanitize($username, "string");
            $extension = next($parameters);
            $extension = intval($extension);
            $vDomain = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                  "domain_name" => $uri,
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($vDomain) {
               $vUsers = VUsers::findFirst(
                  [
                     "conditions" => "domain_uuid = :domain_uuid: AND username = :username:",
                     "bind" => [
                        "domain_uuid" => $vDomain->domain_uuid,
                        "username" => $username,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]
               );
               if ($vUsers) {
                  $vExtensionUsers = VExtensionUsers::findFirst(
                     [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                        "bind" => [
                           "domain_uuid" => $vDomain->domain_uuid,
                           "user_uuid" => $vUsers->user_uuid,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]
                  );
                  if ($vExtensionUsers) {
                     $vExtensions = VExtensions::findFirst(
                        [
                           "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid =:extension_uuid: AND extension =:extension:",
                           "bind" => [
                              "domain_uuid" => $vDomain->domain_uuid,
                              "extension_uuid" => $vExtensionUsers->extension_uuid,
                              "extension" => $extension
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                        ]
                     );
                     if ($vExtensions) {
                        $lookupOrder = $this->redis->hget("integration:" . $vDomain->domain_name . "-salesforce", "lookupOrder");
                        $contactOrder = $this->redis->hget("integration:" . $vDomain->domain_name . "-salesforce", "contactOrder");
                        $response = json_encode([
                           "result" => "success",
                           "message" => "",
                           "content" => [
                              "access" => $vExtensions->password,
                              "priority" => is_null($lookupOrder) ? "Contacts / Leads / Accounts" : $lookupOrder,
                              "form" => is_null($contactOrder) ? "Contacts" : $contactOrder,
                           ],
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                     }
                  }
               }
            }
         } else {
            $parameters["caller_name"] = !empty($this->request->getPost("caller_name", "string")) ? $this->request->getPost("caller_name", "string") : "";
            $parameters["caller_email"] = !empty($this->request->getPost("caller_email", "string")) ? $this->request->getPost("caller_email", "string") : "";
            $username = $this->filter->sanitize($parameters["dialer_name"], "string");
            $callerName = $this->filter->sanitize($parameters["caller_name"], "string");
            $callerEmail = $this->filter->sanitize($parameters["caller_email"], "string");
            $vDomain = VDomains::findFirst([
               "conditions" => "domain_name = :domain_name:",
               "bind" => [
                     "domain_name" => $uri,
               ],
               "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if ($vDomain) {
               if (str_contains($parameters["dialer_name"], "-")) { // Click2call queues
                  $destination = $this->filter->sanitize($parameters["dialer_name"], "string");
                  $vCallCenterQueue = VCallCenterQueues::findFirst([
                     "conditions" => "domain_uuid = :domain_uuid: AND call_center_queue_uuid = :call_center_queue_uuid:",
                     "bind" => [
                        "domain_uuid" => $vDomain->domain_uuid,
                        "call_center_queue_uuid" => $destination
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]);
                  if (!$vCallCenterQueue) {
                     $response = json_encode([
                        "result" => "error",
                        "message" => "Sorry, selected channel does not exists or already deleted.",
                        "content" => [],
                     ]);
                     $this->response->setContent($response);
                     return $this->response;
                  }
                  $qCompanies = QCompanies::findFirst([
                     "conditions" => "domain_uuid = :domain_uuid:",
                     "bind" => [
                        "domain_uuid" => $vDomain->domain_uuid,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR],
                  ]);
                  $companyName = $qCompanies ? ucwords($qCompanies->company_name) : "";
                  $destinationName = ucwords($vCallCenterQueue->queue_name);
                  $destination = $vCallCenterQueue->queue_extension;
                  $extension = "100000";
                  $vExtensions = VExtensions::findFirst([
                     "conditions" => "domain_uuid = :domain_uuid: AND number_alias =:number_alias:",
                     "bind" => [
                        "domain_uuid" => $vDomain->domain_uuid,
                        "number_alias" => $extension
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]);

                  $access2Username = "";
                  if ($vExtensions) {
                     $vExtensionUsers = VExtensionUsers::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                        "bind" => [
                           "domain_uuid" => $vDomain->domain_uuid,
                           "extension_uuid" => $vExtensions->extension_uuid,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]);

                     if ($vExtensionUsers) {
                        $vUser = VUsers::findFirst([
                           "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                           "bind" => [
                              "domain_uuid" => $vDomain->domain_uuid,
                              "user_uuid" => $vExtensionUsers->user_uuid,
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]);
                        if ($vUser) {
                           $access2Username = $vUser->username;
                        }
                     }
                  }

                  $this->redis->hmset("click2call-" . $destination . "-" . $vDomain->domain_name, [
                     "callerName" => $callerName,
                     "callerEmail" => $callerEmail,
                  ]);
                  $response = json_encode([ 
                     "result" => "success",
                     "message" => "",
                     "content" => [
                        "access" => $vExtensions ? $vExtensions->password : "",
                        "access2" => $access2Username,
                        "booking_url" => "",
                        "destination" => $destination,
                        "image" => "",
                        "companyName" => $companyName,
                        "destinationName" => $destinationName,
                        "fullName" => $destinationName,
                        "email" => "",
                        "phone" => "",
                        "title" => 'Queue',
                        "linkedin" => ""
                     ],
                  ]); 
                  $this->response->setContent($response);                  
                  return $this->response;
               } else { // Click2call extensions
                  $vUsers = VUsers::findFirst([
                     "conditions" => "domain_uuid = :domain_uuid: AND username = :username:",
                     "bind" => [
                        "domain_uuid" => $vDomain->domain_uuid,
                        "username" => $username,
                     ],
                     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]);
                  if ($vUsers) {
                     $vExtensionUsers = VExtensionUsers::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                        "bind" => [
                              "domain_uuid" => $vDomain->domain_uuid,
                              "user_uuid" => $vUsers->user_uuid,
                        ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                     ]);
                     if ($vExtensionUsers) {
                        $vExtensions = VExtensions::findFirst([
                           "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid =:extension_uuid:",
                           "bind" => [
                              "domain_uuid" => $vDomain->domain_uuid,
                              "extension_uuid" => $vExtensionUsers->extension_uuid,
                           ],
                           "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]);
                        if ($vExtensions) {
                           $qUsers = QUsers::findFirst([
                              "conditions" => "user_uuid = :user_uuid:",
                              "bind" => [
                                 "user_uuid" => $vUsers->user_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]);
                           $qCompanies = QCompanies::findFirst([
                              "conditions" => "domain_uuid = :domain_uuid:",
                              "bind" => [
                                 "domain_uuid" => $vDomain->domain_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR],
                           ]); 
                           $companyName = ucwords($qCompanies->company_name);
                           $destinationName = ucwords($qUsers->first_name) . (!empty($qUsers->last_name) ? " " . substr(ucwords($qUsers->last_name), 0, 1) . "." : "");
                           if (!empty($qUsers->first_name) && !empty($qUsers->last_name)) {
                              $fullName = ucwords($qUsers->first_name) . " " . ucwords($qUsers->last_name);
                           } else if (!empty($qUsers->first_name)) {
                              $fullName = ucwords($qUsers->first_name);
                           } else if (!empty($qUsers->last_name)) {
                              $fullName = ucwords($qUsers->last_name);
                           } else {
                              $fullName = $username;
                           }
                           $destination = $vExtensions->extension;
                           $extension = "100000";
                           $vExtensions = VExtensions::findFirst([
                              "conditions" => "domain_uuid = :domain_uuid: AND number_alias =:number_alias:",
                              "bind" => [
                                 "domain_uuid" => $vDomain->domain_uuid,
                                 "number_alias" => $extension
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]); 
                           $getAgentExtension = VExtensions::findFirst([
                              "conditions" => "domain_uuid = :domain_uuid: AND number_alias = :number_alias:",
                              "bind" => [
                                 "domain_uuid" => $vDomain->domain_uuid,
                                 "number_alias" => $username
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]);
                           $getAgentMobileNo = QCallBehaviors::findFirst([
                              "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                              "bind" => [
                                 "domain_uuid" => $vDomain->domain_uuid,
                                 "extension_uuid" => $getAgentExtension->extension_uuid
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]);
                           $image = $this->redis->get('avatar:' . $uri . ':' . $getAgentExtension->extension);
                           $getAgentLinkInProfile = $this->redis->get($getAgentExtension->extension . "@" . $getAgentExtension->accountcode . "-linkedInProfileURL");
                           $getAgentJobTitle = $this->redis->get($getAgentExtension->extension . "@" . $getAgentExtension->accountcode . "-jobTitle");
                           $vExtensionUsers = VExtensionUsers::findFirst([
                              "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                              "bind" => [
                                 "domain_uuid" => $vDomain->domain_uuid,
                                 "extension_uuid" => $vExtensions->extension_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]);
                           $vUser = VUsers::findFirst([
                              "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                              "bind" => [
                                 "domain_uuid" => $vDomain->domain_uuid,
                                 "user_uuid" => $vExtensionUsers->user_uuid,
                              ],
                              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                           ]);
                           $linkedInEnable = $this->redis->hgetall("linkedin-profile:{$destination}:{$vDomain->domain_uuid}");
                           $isConnectedLinkedIn = !empty($linkedInEnable);
                           $this->redis->hmset("click2call-" . $destination . "-" . $vDomain->domain_name, [
                              "callerName" => $callerName,
                              "callerEmail" => $callerEmail,
                           ]);
                           $response = json_encode([
                              "result" => "success",
                              "message" => "2",
                              "content" => [
                                 "access" => $vExtensions->password,
                                 "access2" => $vUser->username,
                                 "booking_url" => "https://" . $vDomain->domain_name . '/bookings?bk=' . $vDomain->domain_uuid . $username,
                                 "destination" => $destination,
                                 "image" => $image ?? '',
                                 "linkedInImage" => $isConnectedLinkedIn ? ($linkedInEnable['picture']  ?? '')    : '',
                                 "linkedInName" => $isConnectedLinkedIn ? ($linkedInEnable['name']     ?? '')    : '',
                                 "linkedInVerified" => $isConnectedLinkedIn ? ($linkedInEnable['verified'] ?? false) : false,
                                 "companyName" => $companyName,
                                 "destinationName" => $destinationName,
                                 "fullName" => $fullName,
                                 "email" => $vUsers->user_email,
                                 "phone" => $getAgentMobileNo->mobile_number,
                                 "title" => $getAgentJobTitle ?? '',
                                 "linkedin" => $getAgentLinkInProfile ?? ''
                              ],
                           ]); 
                           $this->response->setContent($response);
                           return $this->response;
                        }
                     }
                  }
               }
            }
         }
         $response = json_encode([
            "result" => "error",
            "message" => "Sorry, invalid request has been made.",
            "content" => [],
         ]);
         $this->response->setContent($response);
         return $this->response;
      }
   }

   private function respondWithError($httpCode, $message) {
      http_response_code($httpCode);
      return $this->response->setJsonContent([
         'result' => 'error',
         'message' => $message
      ]);
   }

   public function apiclearcacheAction() {
      $this->view->disable();
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

      if (!$this->request->isPost()) {
         return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Invalid request."
         ]);
      }

      $parameters = []; 
      $parameters["fqdn"] = intval($this->request->getPost("fqdn"));

      if (empty($parameters["did_number"])) {
         $contents = file_get_contents("php://input");
         $parameters = json_decode($contents, true);
      }

      try {
         $vDomains = VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => [
               "domain_name" => $parameters["fqdn"],
            ],
            "bindTypes" => [Column::BIND_PARAM_STR],
         ]);

         if (!$vDomains) {
            return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Domain not found."
            ]);
         }

         // clear Redis API caches created by this controller
        try {
            $cacheKeys = $this->redis->keys("api_cache:*");
            if (is_array($cacheKeys)) {
                foreach ($cacheKeys as $key) {
                    $this->redis->del($key);
                }
            }
            // chat caches for external, whatsapp, facebook start here
            $whatsAppKeys = $this->redis->keys("whatsappconfig:cache:number:*");
            if (is_array($whatsAppKeys)) {
                foreach ($whatsAppKeys as $key) {
                    $this->redis->del($key);
                }
            }
            $facebookKeys = $this->redis->keys("facebookconfig:cache:recipient:*");
            if (is_array($facebookKeys)) {
                foreach ($facebookKeys as $key) {
                    $this->redis->del($key);
                }
            }
            $twilioKeys = $this->redis->keys("twilio:cache:phonefqdn:*");
            if (is_array($twilioKeys)) {
                foreach ($twilioKeys as $key) {
                    $this->redis->del($key);
                }
            }
            $twilioKeys = $this->redis->keys("twilio:fqdn:cache:information:*");
            if (is_array($twilioKeys)) {
                foreach ($twilioKeys as $key) {
                    $this->redis->del($key);
                }
            }
            $telnyxKeys = $this->redis->keys("telnyx:phonecache:*");
            if (is_array($telnyxKeys)) {
                foreach ($telnyxKeys as $key) {
                    $this->redis->del($key);
                }
            }
            // chat cache end here
        } catch (\Throwable $e) {
            // log and continue without failing the request
            error_log(json_encode([
                "clearcache_error" => ControllerBase::safeErrorMessage($e),
            ]));
        }

         return $this->response->setJsonContent([
            "result"  => "success",
            "message" => "Cache clear successful."
         ]);
      } catch (\Throwable $e) {
         return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Failed to clear cache.",
            "debug" => ControllerBase::safeErrorMessage($e),
         ]);
      } 
   }

   public function zohoPhonebridgeAction() {
      $this->view->disable();
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $requestData = [
         'time' => date("Y-m-d H:i:s"),
         'method' => $this->request->getMethod(),
         'params' => $this->request->get(),
         'post' => $this->request->getPost(),
         'rawBody' => $this->request->getRawBody(),
         'ip' => $this->request->getClientAddress(),
      ];
      $logString = "[" . $requestData['time'] . "] " . json_encode($requestData, JSON_UNESCAPED_SLASHES) . PHP_EOL;
      $logFile = "/var/www/ringq/app/controllers/logs/zoho_phonebridge.log";
      file_put_contents($logFile, $logString, FILE_APPEND | LOCK_EX);
      $response = json_encode([
         "result" => "success",
         "message" => "Received Successfully",
      ]);
      $this->response->setContent($response);
      return $this->response;
   }

   public function zohoWebhookAction() {
      $this->view->disable();
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");

      $requestData = [
         'time' => date("Y-m-d H:i:s"),
         'method' => $this->request->getMethod(),
         'params' => $this->request->get(),
         'post' => $this->request->getPost(),
         'rawBody' => $this->request->getRawBody(),
         'ip' => $this->request->getClientAddress(),
      ];

      // Log webhook for debugging
      $logFile = "/var/www/ringq/app/controllers/logs/zoho_phonebridge.log";
      $logString = "[" . $requestData['time'] . "] " . json_encode($requestData, JSON_UNESCAPED_SLASHES) . PHP_EOL;
      file_put_contents($logFile, $logString, FILE_APPEND | LOCK_EX);

      // Extract the authorization code
      $authCode = $this->request->get("code") ?? null;
      if ($authCode) {
         // Get clientId, clientSecret, and dc from Redis (saved earlier)
         $session = $this->session->get("auth") ?? [];
         $redisKey = "integration:" . ($session['subdomain'] ?? 'default') . "-zohodesk";
         $zohoData = $this->redis->hgetall($redisKey);

         $clientId = $zohoData['clientId'] ?? '';
         $clientSecret = $zohoData['clientSecret'] ?? '';
         $dc = $zohoData['dc'] ?? 'in';
         // Exchange code for access token & refresh token
         $tokenUrl = "https://accounts.zoho.$dc/oauth/v2/token";
         $postData = http_build_query([
               "grant_type" => "authorization_code",
               "client_id" => $clientId,
               "client_secret" => $clientSecret,
               "redirect_uri" => "https://{$session['subdomain']}:8443/register/zohoWebhook",
               "code" => $authCode,
         ]);
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_URL, $tokenUrl);
         curl_setopt($ch, CURLOPT_POST, 1);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // optional, only if self-signed
         $tokenResponse = curl_exec($ch);
         curl_close($ch);

         $tokenData = json_decode($tokenResponse, true);

         if (isset($tokenData['access_token'])) {
            $expiresIn = (int)($tokenData['expires_in'] ?? 3600); // fallback 1h

            // Save tokens to Redis
            $this->redis->hmset($redisKey, [
               "accessToken"  => $tokenData['access_token'],
               "refreshToken" => $tokenData['refresh_token'] ?? '',
               "expiresIn"    => $tokenData['expires_in'] ?? 0,
            ]);
            if (!empty($session['subdomain'])) {
               $accessTokenKey = "zoho_access_token:{$session['subdomain']}";
               $this->redis->setex($accessTokenKey, max(60, $expiresIn - 60), $tokenData['access_token']);
            }
         }
      }

      $response = json_encode([
         "result" => "success",
         "message" => "Webhook received successfully",
      ]);
      $this->response->setContent($response);
      return $this->response;
   }  

   public function zohoGetUserDetAction($userEmail = null) {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      // print_r($this->session); exit; 
      // if (!$this->session->has("auth")) {
      //     $response = json_encode([
      //         "result" => "error",
      //         "message" => "Disallowed request."
      //     ]);
      //     $this->response->setContent($response);
      //     return $this->response;
      // }
      // $session = $this->session->get("auth");

      if ($this->request->isGet()) {
        if (empty($userEmail)) {
            
            $response = json_encode([
                "result" => "error",
                "message" => "User email is required."
            ]);
            $this->response->setContent($response);
            return $this->response;
        }

        // Find user by email
        $userEmail = urldecode($userEmail);
        $vUser = VUsers::findFirst([
            "conditions" => "user_email = :user_email:",
            "bind" => [
                "user_email" => $userEmail
            ],
            "bindTypes" => [
                Column::BIND_PARAM_STR,
                Column::BIND_PARAM_STR
            ]
        ]);

        if (!$vUser) {
            $response = json_encode([
                "result" => "error",
                "message" => "User not found."
            ]);
            $this->response->setContent($response);
            return $this->response;
        }

        $content = [
            "extension" => $vUser->vExtensionUsers->vExtensions->extension ?? "",
            "username"  => $vUser->username,
            "agentuuid"  => $vUser->user_uuid,
            "password"  => $vUser->vExtensionUsers->vExtensions->password ?? "",
            "image"     => $vUser->qUsers->image ?? "",
            "firstname" => $vUser->qUsers->first_name ?? "",
            "lastname"  => $vUser->qUsers->last_name ?? "",
            "email"     => $vUser->user_email,
            "status"    => $vUser->user_status ?? ""
         ];

         $response = json_encode([
               "result" => "success",
               "content" => $content
         ]);

         $this->response->setContent($response);
         return $this->response;
      }
   }

   public function saveZohoIntegrationAction() {
      $this->response->setContentType('application/json', 'utf-8');

      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $session = $this->session->get("auth");
      if (!$session) {
         return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'Unauthorized. Please log in.'
         ]);
      }
      $data = $this->request->getJsonRawBody(true);
      $clientId = $data['clientId'] ?? null;
      $clientSecret = $data['clientSecret'] ?? null;
      $dc = $data['dc'] ?? 'in';

      if (!$clientId || !$clientSecret) {
         return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'Client ID and Client Secret are required'
         ]);
      }

      $redisKey = "integration:" . ($session['subdomain']) . "-zohodesk";

      // Save to Redis
      $this->redis->hmset($redisKey, [
         "clientId"     => $clientId,
         "clientSecret" => $clientSecret,
         "dc"           => $dc,
         "domain"       => $session['domain_uuid'] ?? '',
         "callJournaling" => '1',
         "callContacts"   => '1',
      ]);
      

      return $this->response->setJsonContent(['result' => 'success', 'message' => 'Zoho integration settings saved']);
   }

   public function zohoGetContactDetAction($username = "", $number = "") {
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    $parameters = [
        "username" => $username,
        "number"   => $number,
    ];
    // 1. Find user
    $vUser = VUsers::findFirst([
        "conditions" => "username = :username:",
        "bind"       => ["username" => $parameters["username"]],
        "bindTypes"  => [Column::BIND_PARAM_STR],
    ]);
    if (!$vUser) {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "User not found."
        ]);
    }
    // 2. Find domain
    $vDomain = VDomains::findFirst([
        "conditions" => "domain_uuid = :domain_uuid:",
        "bind"       => ["domain_uuid" => $vUser->domain_uuid],
        "bindTypes"  => [Column::BIND_PARAM_STR],
    ]);
    if (!$vDomain) {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Domain not found."
        ]);
    }
    // 3. Get integration data from Redis
    $integrationData = $this->redis->hgetall("integration:{$vDomain->domain_name}-zohodesk");

    $clientId     = $integrationData['clientId'] ?? '';
    $clientSecret = $integrationData['clientSecret'] ?? '';
    $refreshToken = $integrationData['refreshToken'] ?? '';
    $dc           = $integrationData['dc'] ?? 'in';
    if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Missing ZohoDesk integration credentials in Redis"
        ]);
    }
    // 4. Get or refresh access token
    $accessTokenKey = "zoho_access_token:{$vDomain->domain_name}";
    $accessToken = $this->redis->get($accessTokenKey);
    if (!$accessToken) {
        $tokenData = [
            "refresh_token" => $refreshToken,
            "client_id"     => $clientId,
            "client_secret" => $clientSecret,
            "grant_type"    => "refresh_token"
        ];
        $ch = curl_init("https://accounts.zoho.$dc/oauth/v2/token");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $tokenResponse = curl_exec($ch);
        curl_close($ch);
        $tokenJson = json_decode($tokenResponse, true);
        if (!isset($tokenJson['access_token'])) {
            return $this->response->setJsonContent([
                "result"  => "error",
                "message" => "Failed to generate access token",
                "debug"   => $tokenResponse
            ]);
        }
        $accessToken = $tokenJson['access_token'];
        $expiresIn   = $tokenJson['expires_in'] ?? 3600;
        $this->redis->setex($accessTokenKey, $expiresIn - 60, $accessToken);
    }
    $baseUrl = "https://desk.zoho.$dc/api/v1";
    // 5. Search contact by phone
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => $baseUrl . '/contacts/search?phone=' . urlencode($parameters["number"]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Zoho-oauthtoken $accessToken"
        ],
    ]);
    $response = curl_exec($curl);
    $curlErr  = curl_error($curl);
    curl_close($curl);
    if ($curlErr) {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Curl error: $curlErr"
        ]);
    }
    $data = json_decode($response, true);
    // print_r($data); exit;
    // 6. If contact found, return
    if (isset($data['data'][0])) {
        $contact = $data['data'][0];
        $output  = [
            "id"        => $contact['id'] ?? null,
            "firstName" => $contact['firstName'] ?? null,
            "lastName"  => $contact['lastName'] ?? null,
            "webUrl"    => $contact['webUrl'] ?? null,
        ];
        return $this->response->setJsonContent([
            "result"  => "success",
            "content" => $output
        ]);
    }
    // 7. No contact found → create new one
    $newContactData = [
        "lastName" => "New RingQ Contact " . $parameters["number"],
        "phone"    => $parameters["number"],
        "email"    => $parameters["number"] . "@example.com" // fallback email
    ];
    $ch = curl_init($baseUrl . "/contacts");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Zoho-oauthtoken $accessToken",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS     => json_encode($newContactData)
    ]);
    $createResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($curlErr) {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Curl error (create): $curlErr"
        ]);
    }
    $created = json_decode($createResponse, true);
    if (isset($created['id'])) {
        $output = [
            "id"        => $created['id'],
            "firstName" => $created['firstName'] ?? null,
            "lastName"  => $created['lastName'] ?? null,
            "webUrl"    => $created['webUrl'] ?? null,
        ];
        return $this->response->setJsonContent([
            "result"  => "success",
            "content" => $output
        ]);
    } else {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Failed to create contact",
            "debug"   => $createResponse
        ]);
    }
   }

   public function zohoGetTicketDetAction($username = "", $number = "", $departmentID = "", $calltype = "", $contactId = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");

      $parameters = [
         "username" => $username,
         "number"   => $number,
         "calltype" => strtolower($calltype),
      ];

      // 1. Find user
      $vUser = VUsers::findFirst([
         "conditions" => "username = :username:",
         "bind"       => ["username" => $parameters["username"]],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if (!$vUser) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "User not found."
         ]);
      }

      // 2. Find domain
      $vDomain = VDomains::findFirst([
         "conditions" => "domain_uuid = :domain_uuid:",
         "bind"       => ["domain_uuid" => $vUser->domain_uuid],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if (!$vDomain) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Domain not found."
         ]);
      }

      // 3. Get integration data from Redis
      $integrationData = $this->redis->hgetall("integration:{$vDomain->domain_name}-zohodesk");
      $clientId     = $integrationData['clientId'] ?? '';
      $clientSecret = $integrationData['clientSecret'] ?? '';
      $refreshToken = $integrationData['refreshToken'] ?? '';
      $dc           = $integrationData['dc'] ?? 'in';

      // 4. Get or refresh access token
      $accessTokenKey = "zoho_access_token:{$vDomain->domain_name}";
      $accessToken    = $this->redis->get($accessTokenKey);

      if (!$accessToken) {
         $tokenData = [
               "refresh_token" => $refreshToken,
               "client_id"     => $clientId,
               "client_secret" => $clientSecret,
               "grant_type"    => "refresh_token"
         ];

         $ch = curl_init("https://accounts.zoho.$dc/oauth/v2/token");
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         $tokenResponse = curl_exec($ch);
         curl_close($ch);

         $tokenJson = json_decode($tokenResponse, true);
         if (!isset($tokenJson['access_token'])) {
               return $this->response->setJsonContent([
                  "result"  => "error",
                  "message" => "Failed to generate access token",
                  "debug"   => $tokenResponse
               ]);
         }

         $accessToken = $tokenJson['access_token'];
         $expiresIn   = $tokenJson['expires_in'] ?? 3600;
         $this->redis->setex($accessTokenKey, $expiresIn - 60, $accessToken);
      }

      $baseUrl = "https://desk.zoho.$dc/api/v1";

      // 5. Subject & status based on call type
      $subject = ($parameters["calltype"] == "incoming")
         ? "Incoming Call from {$parameters["number"]}"
         : "Outgoing Call to {$parameters["number"]}";
      $status = "New";

      // 6. Prepare ticket data
      $ticketData = [
         "subject"      => $subject,
         "departmentId" => $departmentID,
         "priority"     => "Low",
         "status"       => $status,
         "description"  => "This ticket was generated from RingQ for a {$parameters["calltype"]} call involving number {$parameters["number"]}."
      ];

      // If contactId is provided use it, otherwise create a fallback contact object
      if (!empty($contactId)) {
         $ticketData["contactId"] = $contactId;
      } else {
         $ticketData["contact"] = [
               "lastName" => "New RingQ Contact " . $parameters["number"],
               "email"    => $parameters["number"] . "@example.com",
               "phone"    => $parameters["number"]
         ];
      }

      // 7. Create ticket
      $ch = curl_init($baseUrl . "/tickets");
      curl_setopt_array($ch, [
         CURLOPT_POST           => true,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POSTFIELDS     => json_encode($ticketData),
         CURLOPT_HTTPHEADER     => [
               "Authorization: Zoho-oauthtoken $accessToken",
               "Content-Type: application/json",
         ],
      ]);
      $ticketResponse = curl_exec($ch);
      curl_close($ch);

      $ticketJson = json_decode($ticketResponse, true);

      if (isset($ticketJson['id'])) {
         $ticketInfo = [
               "id"     => $ticketJson['id'],
               "webUrl" => $ticketJson['webUrl'] ?? null
         ];
         return $this->response->setJsonContent([
               "result"    => "success",
               "contactId" => $contactId ?: ($ticketJson['contact']['id'] ?? null),
               "ticket"    => $ticketInfo
         ]);
      }

      // Error case
      return $this->response->setJsonContent([
         "result"  => "error",
         "message" => "Failed to create ticket",
         "debug"   => $ticketResponse
      ]);
   }

   public function zohoCallJournalingAction($type = 0, $username = "", $number = "", $queue = 0, $direction = "", $ticketId = 0, $crmType, $objectType = "ticket") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      if (!$this->request->isGet()) {
         return $this->response->setContent(json_encode([
               "result" => "error",
               "message" => "Invalid request method.",
               "content" => []
         ]));
      }

      $parameters = ["username" => $username, "number" => $number];
      $queue = intval($queue);

      $calllogForm = new CalllogForm();
      if (!$calllogForm->isValid($parameters)) {
         $message = implode(" ", $calllogForm->getMessages());
         return $this->response->setContent(json_encode([
               "result" => "error",
               "message" => trim($message) . " (Code ZD1000)",
               "content" => []
         ]));
      }

      $vUser = VUsers::findFirst(["conditions" => "username = :username:", "bind" => ["username" => $username]]);
      if (!$vUser) return $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid user", "content" => []]));

      $vDomain = VDomains::findFirst(["conditions" => "domain_uuid = :domain_uuid:", "bind" => ["domain_uuid" => $vUser->domain_uuid]]);
      if (!$vDomain) return $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid domain", "content" => []]));

      $vExtensionUser = VExtensionUsers::findFirst([
         "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
         "bind" => ["domain_uuid" => $vDomain->domain_uuid, "user_uuid" => $vUser->user_uuid]
      ]);
      if (!$vExtensionUser) return $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid extension user", "content" => []]));

      $vExtension = VExtensions::findFirst([
         "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
         "bind" => ["domain_uuid" => $vDomain->domain_uuid, "extension_uuid" => $vExtensionUser->extension_uuid]
      ]);
      if (!$vExtension) return $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid extension", "content" => []]));



      if($crmType == 'zendesk'){
         // Redis key per extension + number to avoid clashes
         $callSessionKey = "zendesk-keys:{$vDomain->domain_name}:{$vExtension->extension}:{$number}:$ticketId";
      } else {
         // Redis key per extension + number to avoid clashes
         $callSessionKey = "zd-keys:{$vDomain->domain_name}:{$vExtension->extension}:{$number}:$ticketId";
      }


      

      if (intval($type) === 1) {
         // Call start / answer
         $this->redis->rPush($callSessionKey, json_encode([
               'domain_name' => $vDomain->domain_name,
               'domain_uuid' => $vDomain->domain_uuid,
               'extension' => $vExtension->extension,
               'contactNumber' => $number,
               'queueExtension' => $queue,
               'status' => $direction,
               'objectType' => $objectType,
               'ticketId' => $ticketId ?: null,
               'answered_time' => microtime(true) // store precise timestamp
         ]));
         $this->redis->expire($callSessionKey, 86400);

         return $this->response->setContent(json_encode(["result" => "success"]));
      }

      if (intval($type) === 2) {
         // Call End
            if($crmType == 'zendesk'){
               // Redis key per extension + number to avoid clashes
               $callSessionKey = "zendesk-keys:{$vDomain->domain_name}:{$vExtension->extension}:{$number}:$ticketId";
         } else {
               // Redis key per extension + number to avoid clashes
               $callSessionKey = "zd-keys:{$vDomain->domain_name}:{$vExtension->extension}:{$number}:$ticketId";
         }

         // $zdKeys = $this->redis->keys("zd-*");
         // $this->redis->del("zd-keys:station.ind1.ringq.ai:1007:1029:212041000000412352");
         // print_r($zdKeys); exit;

         $callSessions   = $this->redis->lRange($callSessionKey, 0, -1);

         
         if (!$callSessions) {
               print_r("No call session found in Redis for key: " . $callSessionKey);
               return;
         }

         $latestSession = json_decode(end($callSessions), true);

         
         $answeredTime = (int) floor($latestSession['answered_time']); // round Redis float
         $contactNumber = $latestSession['contactNumber'];
         $domainUuid    = $vDomain->domain_uuid;
         $epochField = ($latestSession['status'] === 'Outgoing') ? 'start_epoch' : 'answer_epoch';

         // Poll DB until record exists
         $maxWait   = 120; // seconds
         $interval  = 3;   // seconds
         $elapsed   = 0;
         $cdrRecord = null;

         while ($elapsed < $maxWait) {
               $cdrRecord = QRingqCdr::findFirst([
                  "conditions" => "
                     domain_uuid = :domain:
                     AND (caller_id_number = :number: OR destination_number = :number:)
                     AND {$epochField} BETWEEN :start: AND :end:",
                  "bind" => [
                     "domain" => $domainUuid,
                     "number" => $contactNumber,
                     "start"  => $answeredTime - 10,
                     "end"    => $answeredTime + 10
                  ],
                  "order" => "{$epochField} DESC"
               ]);

               if ($cdrRecord) {
                  break;
               }

               sleep($interval);
               $elapsed += $interval;
         }
         if (!$cdrRecord) {
               $this->logger->error("CDR record not found after waiting for call: {$contactNumber} on ext {$extension}");
               return;
         }
         // Compute duration
         $duration = (int) $cdrRecord->duration;

         // Build update payload for Zoho
         $updatePayload = [
               "ticketId"   => $ticketId ?? null,
               "status"     => "Open",
               "resolution" => "Call completed. Duration: " . gmdate("H:i:s", $duration),
               "timeSpent"  => $duration,
               "callNumber" => $contactNumber,
               "direction"  => $latestSession['status'],
               "recordURL"  => "https://{$vDomain->domain_name}:8443/register/zohoplayrecoding/" . $cdrRecord->xml_cdr_uuid,
               "recordName" => $cdrRecord->record_name  ?? null,
               "userId"     => $latestSession['zdUserId'] ?? null
         ];


         // Push to Zoho
         


      if($crmType == 'zendesk'){
         // Redis key per extension + number to avoid clashes
         $zohoResponse = $this->zendeskupdateTicket($updatePayload, $vDomain->domain_name);
      } else {
         $zohoResponse = $this->zohoupdateTicket($updatePayload, $vDomain->domain_name);
      }


         $data = json_decode($zohoResponse, true);
         // Cleanup Redis
            $this->redis->del($callSessionKey);

         // $this->redis->del($callSessionKey);
         return $this->response->setContent(json_encode(["result" => "success"]));
         
      }

      return $this->response->setContent(json_encode([
         "result" => "error",
         "message" => "Invalid type",
         "content" => []
      ]));
   }

   public function zohoupdateTicket($updatePayload, $domain_name) {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");

      // 1. Integration data from Redis
      $integrationData = $this->redis->hgetall("integration:{$domain_name}-zohodesk");
      $clientId     = $integrationData['clientId'] ?? '';
      $clientSecret = $integrationData['clientSecret'] ?? '';
      $refreshToken = $integrationData['refreshToken'] ?? '';
      $dc           = $integrationData['dc'] ?? 'in';

      // 2. Access token
      $accessTokenKey = "zoho_access_token:{$domain_name}";
      $accessToken    = $this->redis->get($accessTokenKey);

      if (!$accessToken) {
         $tokenData = [
               "refresh_token" => $refreshToken,
               "client_id"     => $clientId,
               "client_secret" => $clientSecret,
               "grant_type"    => "refresh_token"
         ];

         $ch = curl_init("https://accounts.zoho.$dc/oauth/v2/token");
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         $tokenResponse = curl_exec($ch);
         curl_close($ch);

         $tokenJson = json_decode($tokenResponse, true);
         if (!isset($tokenJson['access_token'])) {
               return $this->response->setJsonContent([
                  "result"  => "error",
                  "message" => "Failed to generate access token",
                  "debug"   => $tokenResponse
               ]);
         }

         $accessToken = $tokenJson['access_token'];
         $expiresIn   = $tokenJson['expires_in'] ?? 3600;
         $this->redis->setex($accessTokenKey, $expiresIn - 60, $accessToken);
      }

      $baseUrl  = "https://desk.zoho.$dc/api/v1";
      $ticketId = $updatePayload['ticketId'] ?? null;

      if (!$ticketId) {
         return $this->response->setJsonContent([
               "result" => "error",
               "message" => "Missing ticketId"
         ]);
      }

      // 3. Build description body in bullet points
      // Build HTML description with call details
      $descriptionHtml = <<<EOT
      <div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6;">
         <p><b>📞 Call Details</b></p>
         <ul>
               <li><b>Direction:</b> {$updatePayload['direction']}</li>
               <li><b>Number:</b> {$updatePayload['callNumber']}</li>
               <li><b>Duration:</b> {$updatePayload['timeSpent']} seconds</li>
               <li><b>Recording:</b> 
                  <a href="{$updatePayload['recordURL']}" target="_blank">
                     {$updatePayload['recordName']}
                  </a>
               </li>
         </ul>
      </div>
      EOT;

      $ticketData = [
         "description" => $descriptionHtml
      ];

      // 5. PATCH request to update description
      $ch = curl_init($baseUrl . "/tickets/" . $ticketId);
      curl_setopt_array($ch, [
         CURLOPT_CUSTOMREQUEST  => "PATCH",
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POSTFIELDS     => json_encode($ticketData),
         CURLOPT_HTTPHEADER     => [
               "Authorization: Zoho-oauthtoken $accessToken",
               "Content-Type: application/json",
         ],
      ]);
      $ticketResponse = curl_exec($ch);
      curl_close($ch);

      $ticketJson = json_decode($ticketResponse, true);
      if (isset($ticketJson['id'])) {
         return json_encode(["result" => "success"]);
      }

      return $this->response->setContent(json_encode(["result" => "success"]));
   }

   public function zohoplayrecodingAction($uuid)
    {
        $cdr = QRingqCdr::findFirst([
            'conditions' => 'xml_cdr_uuid = :uuid: OR originating_leg_uuid = :uuid:',
            'bind' => [
                'uuid' => $uuid
            ]
        ]);

        if (!$cdr) {
            $this->response->setStatusCode(404, "Not Found");
            return $this->response->setContent("Recording not found.");
        }

        $filePath = rtrim($cdr->record_path, '/') . '/' . $cdr->record_name;

        if (!is_file($filePath)) {
            $this->response->setStatusCode(404, "Not Found");
            return $this->response->setContent("Recording file missing.");
        }

        if (!is_readable($filePath)) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->response->setContent("Recording is not readable.");
        }

        $fileSize = filesize($filePath);

        // Clear any existing output
        while (ob_get_level()) {
            ob_end_clean();
        }

        $this->response->setStatusCode(200, "OK");

        $this->response->setHeader("Content-Type", "audio/mpeg");
        $this->response->setHeader(
            "Content-Disposition",
            'inline; filename="' . basename($filePath) . '"'
        );
        $this->response->setHeader("Content-Length", (string) $fileSize);
        $this->response->setHeader("Accept-Ranges", "bytes");
        $this->response->setHeader("Cache-Control", "no-cache");

        $this->response->setContent(file_get_contents($filePath));

        return $this->response;
    }

   private function base64UrlEncode($data) {
      return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
   }

   private function generateCodeVerifier() {
      return $this->base64UrlEncode(random_bytes(32));
   }

   private function generateCodeChallenge($codeVerifier) {
      return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
   }

   public function savezendeskIntegrationAction() {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $session = $this->session->get("auth");
      if (!$session) {
         return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'Unauthorized. Please log in.'
         ]);
      }
      $data = $this->request->getJsonRawBody(true);
      $clientId = $data['clientId'] ?? null;
      $clientSecret = $data['clientSecret'] ?? null;
      $zendesk_fqdn = $data['zendesk_fqdn'] ?? null;

      if (!$clientId || !$clientSecret) {
         return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'Client ID and Client Secret are required'
         ]);
      }
      
      // 1. Generate PKCE values
      $codeVerifier  = $this->generateCodeVerifier();
      $codeChallenge = $this->generateCodeChallenge($codeVerifier);
      $redisKey = "integration:" . ($session['subdomain']) . "-zendesk";

      // Save to Redis
      $this->redis->hmset($redisKey, [
         "clientId"     => $clientId,
         "clientSecret" => $clientSecret,
         "zendesk_fqdn"  => $zendesk_fqdn,
         "domain"       => $session['domain_uuid'] ?? 'station.ind1.ringq.ai',
         "codeVerifier" => $codeVerifier,
         "callJournaling" => '2',
         "callContacts"   => '1',
      ]);
      //$redisKey = "integration:-zendesk";
      // $this->redis->hmset($redisKey, [
      //     "clientId"     => 'ringq_cti_dialer',
      //     "clientSecret" => '19bdf92d76bd51cb0ec4822fdc4c7b95da532b0eaf5e56871b2a89f3367662f7',
      //     "zendesk_fqdn"  => 'https://demo1-1431.zendesk.com/',
      //     "domain"       => $session['domain_uuid'] ?? '',
      //     "codeVerifier" => $codeVerifier,
      //     "callJournaling" => '2',
      //     "callContacts"   => '1',
      // ]);
      $redirectUri = "https://{$session['subdomain']}:8443/register/zendeskwebhook";


      $authUrl = "$zendesk_fqdn/oauth/authorizations/new?" .
         "response_type=code" .
         "&client_id={$clientId}" .
         "&redirect_uri={$redirectUri}" .
         "&code_challenge_method=S256" .
         "&code_challenge={$codeChallenge}" .
         "&scope=" . urlencode("users:read users:write tickets:read tickets:write");
         
      return $this->response->setJsonContent([
            "result" => "success",
            "message" => $authUrl
      ]); 
   }
   public function zendeskwebhookAction() {
      $this->view->disable();
      $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");

      $session = $this->session->get("auth");
      $redisKey = "integration:" . ($session['subdomain']) . "-zendesk";
      // $redisKey = "integration:-zendesk";
      $integrationData = $this->redis->hgetall($redisKey);
      if (!$session) {
         return $this->response->setJsonContent([
               'result' => 'error',
               'message' => 'Unauthorized. Please log in.'
         ]);
      }

      $authCode = $this->request->get("code") ?? null;
      

      if ($authCode) {
         $redisKey = "integration:" . $session['subdomain'] . "-zendesk";
         $data = $this->redis->hgetall($redisKey);
         $clientId     = $data['clientId'] ?? '';
         $clientSecret = $data['clientSecret'] ?? '';
         $codeVerifier = $data['codeVerifier'] ?? '';
         $zendesk_fqdn = $data['zendesk_fqdn'] ?? '';

         if (empty($codeVerifier)) {
            return $this->response->setJsonContent([
                  "result" => "error",
                  "message" => "Missing PKCE code_verifier (cannot exchange token)."
            ]);
         }

         // PKCE + Confidential client token exchange
         $tokenUrl = $zendesk_fqdn."oauth/tokens";
         $postData = [
            "grant_type"                 => "authorization_code",
            "code"                       => $authCode,       // from query param
            "client_id"                  => $clientId,       // Zendesk OAuth client ID
            "redirect_uri"               => "https://{$session['subdomain']}:8443/register/zendeskwebhook",
            "scope"                      => "users:read users:write tickets:read tickets:write",
            "code_verifier"              => $codeVerifier,   // PKCE
            "expires_in"                 => 86400,           // optional
            "refresh_token_expires_in"   => 604800           // optional
         ];

         
         $ch = curl_init($tokenUrl);
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
         curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json"
         ]);
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

         
         $tokenResponse = curl_exec($ch);
        
         curl_close($ch);
         $tokenData = json_decode($tokenResponse, true);


         

         if (isset($tokenData['access_token'])) {
            $expiresIn = (int)($tokenData['expires_in'] ?? 3600); // fallback 1h
            // Save tokens
            $this->redis->hmset($redisKey, [
                  "accessToken"  => $tokenData['access_token'],
                  "refreshToken" => $tokenData['refresh_token'] ?? '',
                  "expiresIn"    => $tokenData['expires_in'] ?? 0,
            ]);
               $accessTokenKey = "zendesk_access_token:{$session['subdomain']}";
               //$accessTokenKey = "zendesk_access_token:test";
               $this->redis->setex($accessTokenKey, max(60, $expiresIn - 60), $tokenData['access_token']);
         } else {
            // log error
            file_put_contents(
                  "/var/www/ringq/app/controllers/logs/zendesk_error.log",
                  date("Y-m-d H:i:s") . " TOKEN ERROR: " . $tokenResponse . "\n",
                  FILE_APPEND
            );
         }
      }

      return $this->response->setJsonContent([
         "result" => "success",
         "message" => "Webhook received successfully"
      ]);
   }

   public function zendeskGetContactDetAction($username = "", $number = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $parameters = [
         "username" => $username,
         "number"   => $number,
      ];
      // 1. Find user
      $vUser = VUsers::findFirst([
         "conditions" => "username = :username:",
         "bind"       => ["username" => $parameters["username"]],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if (!$vUser) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "User not found."
         ]);
      }
      // 2. Find domain
      $vDomain = VDomains::findFirst([
         "conditions" => "domain_uuid = :domain_uuid:",
         "bind"       => ["domain_uuid" => $vUser->domain_uuid],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if (!$vDomain) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Domain not found."
         ]);
      }
      // 3. Get integration data from Redis
    $redisKey = "integration:{$vDomain->domain_name}-zendesk";


      $integrationData = $this->redis->hgetall($redisKey);

      $clientId     = $integrationData['clientId'] ?? '';
      $clientSecret = $integrationData['clientSecret'] ?? '';
      $refreshToken = $integrationData['refreshToken'] ?? '';
      $zendesk_fqdn = $integrationData['zendesk_fqdn'];
      $tokenUrl = $zendesk_fqdn."oauth/tokens";
      if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Missing zenDesk integration credentials in Redis"
         ]);
      }
      // 4. Get or refresh access token
      $accessTokenKey = "zendesk_access_token:{$vDomain->domain_name}";
    //   $accessTokenKey = "zendesk_access_token:test";
      $accessToken = $this->redis->get($accessTokenKey);
      if (!$accessToken) {
               $tokenData = [
                  "grant_type"                 => "refresh_token",
                  "refresh_token"              => $refreshToken,
                  "client_id"                  => $clientId,
                  "client_secret"              => $clientSecret,   // required for confidential clients
                  "scopes"                     => "users:read users:write tickets:read tickets:write",
                  "expires_in"                 => 86400,           // 1 day
                  "refresh_token_expires_in"   => 604800           // 7 days
               ];
               $ch = curl_init($tokenUrl);
               curl_setopt($ch, CURLOPT_POST, true);
               curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($tokenData));
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($ch, CURLOPT_HTTPHEADER, [
                  "Accept: application/json",
                  "Content-Type: application/json"
               ]);
               $tokenResponse = curl_exec($ch);
               $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
               $curlErr  = curl_error($ch);
               curl_close($ch);
               if ($curlErr) {
                  // network / curl failure
                  return $this->response->setJsonContent([
                     'result'  => 'error',
                     'message' => 'cURL error: ' . $curlErr,
                  ]);
               }
               $tokenJson = json_decode($tokenResponse, true);
               // debug logging (save to log file) — do not expose in production
               if ($httpCode !== 200 || !isset($tokenJson['access_token'])) {
                  return $this->response->setJsonContent([
                     'result'  => 'error',
                     'message' => 'Failed to refresh access token',
                     'http_code'=> $httpCode,
                     'debug'   => $tokenResponse
                  ]);
               }
               $accessToken = $tokenJson['access_token'];
               $expiresIn   = $tokenJson['expires_in'] ?? 3600;
               $this->redis->setex($accessTokenKey, $expiresIn - 60, $accessToken);
               $newRefresh = $tokenJson['refresh_token'] ?? $refreshToken;
               $this->redis->hset($redisKey, "refreshToken", $newRefresh);
               $this->redis->hset($redisKey, "accessToken", $accessToken);
      }
      $baseUrl = $zendesk_fqdn;
      $curl = curl_init();
      curl_setopt_array($curl, [
         CURLOPT_URL            => $baseUrl . 'api/v2/users/search.json?query=phone:' . urlencode($parameters["number"]),
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_HTTPHEADER     => [
               "Authorization: Bearer $accessToken"
         ],
      ]);
      $response = curl_exec($curl);
      $curlErr  = curl_error($curl);
      
      curl_close($curl);
      if ($curlErr) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Curl error: $curlErr"
         ]);
      }
      $data = json_decode($response, true);
      // 6. If contact found, return
      if (isset($data['users'][0])) {
         $contact = $data['users'][0];
         $output  = [
               "id"        => $contact['id'] ?? null,
               "firstName" => $contact['name'] ?? null,
               "email"  => $contact['email'] ?? null,
               "webUrl"    => $contact['url'] ?? null,
         ];
         return $this->response->setJsonContent([
               "result"  => "success",
               "content" => $output
         ]);
      }
      // 7. No contact found → create new one
    $number = $parameters["number"];
    // 7. No contact found → create new one
    $digits = preg_replace('/\D/', '', $number  );
    // reject obvious extensions
    if (strlen($digits) <= 5) {
        $number = '989898989';
    }


      $newContactData = [
         "user" => [
               "name" => "New RingQ Contact " . $parameters["number"],
               "phone"    => $number,
               "email"    => $parameters["number"] . "@example.com" // fallback email
         ],
      ];


      $ch = curl_init($baseUrl . "api/v2/users.json");
      curl_setopt_array($ch, [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POST           => true,
         CURLOPT_HTTPHEADER     => [
               "Authorization: Bearer $accessToken",
               "Content-Type: application/json"
         ],
         CURLOPT_POSTFIELDS     => json_encode($newContactData)
      ]);
      $createResponse = curl_exec($ch);

      $curlErr = curl_error($ch);
      curl_close($ch);
      if ($curlErr) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Curl error (create): $curlErr"
         ]);
      }
      $created = json_decode($createResponse, true);
      if (isset($created['user']['id'])) {
         $output  = [
               "id"        => $created['user']['id'] ?? null,
               "firstName" => $created['user']['name'] ?? null,
               "email"  => $created['user']['email'] ?? null,
               "webUrl"    => $created['user']['url'] ?? null,
         ];
         return $this->response->setJsonContent([
               "result"  => "success",
               "content" => $output
         ]);
      } else {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Failed to create contact",
               "debug"   => $createResponse
         ]);
      }
   }

   public function zendeskGetTicketDetAction($username = "", $number = "", $calltype = "", $contactId = "") {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");
      $parameters = [
         "username" => $username,
         "number"   => $number,
      ];
      // 1. Find user
      $vUser = VUsers::findFirst([
         "conditions" => "username = :username:",
         "bind"       => ["username" => $parameters["username"]],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if (!$vUser) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "User not found."
         ]);
      }
      // 2. Find domain
      $vDomain = VDomains::findFirst([
         "conditions" => "domain_uuid = :domain_uuid:",
         "bind"       => ["domain_uuid" => $vUser->domain_uuid],
         "bindTypes"  => [Column::BIND_PARAM_STR],
      ]);
      if (!$vDomain) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Domain not found."
         ]);
      }
      // 3. Get integration data from Redis
      $redisKey = "integration:{$vDomain->domain_name}-zendesk";
      $integrationData = $this->redis->hgetall($redisKey);
      $clientId     = $integrationData['clientId'] ?? '';
      $clientSecret = $integrationData['clientSecret'] ?? '';
      $refreshToken = $integrationData['refreshToken'] ?? '';
      $zendesk_fqdn = $integrationData['zendesk_fqdn'];
      $tokenUrl = $zendesk_fqdn."oauth/tokens";
      if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
         return $this->response->setJsonContent([
               "result"  => "error",
               "message" => "Missing zenDesk integration credentials in Redis"
         ]);
      }
      // 4. Get or refresh access token
      $accessTokenKey = "zendesk_access_token:{$vDomain->domain_name}";
    //   $accessTokenKey = "zendesk_access_token:test";
      $accessToken = $this->redis->get($accessTokenKey);
      if (!$accessToken) {
               $tokenData = [
                  "grant_type"                 => "refresh_token",
                  "refresh_token"              => $refreshToken,
                  "client_id"                  => $clientId,
                  "client_secret"              => $clientSecret,   // required for confidential clients
                  "scopes"                     => "users:read users:write tickets:read tickets:write",
                  "expires_in"                 => 86400,           // 1 day
                  "refresh_token_expires_in"   => 604800           // 7 days
               ];
               $ch = curl_init($tokenUrl);
               curl_setopt($ch, CURLOPT_POST, true);
               curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($tokenData));
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
               curl_setopt($ch, CURLOPT_HTTPHEADER, [
                  "Accept: application/json",
                  "Content-Type: application/json"
               ]);
               $tokenResponse = curl_exec($ch);
               $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
               $curlErr  = curl_error($ch);
               curl_close($ch);
               if ($curlErr) {
                  // network / curl failure
                  return $this->response->setJsonContent([
                     'result'  => 'error',
                     'message' => 'cURL error: ' . $curlErr,
                  ]);
               }
               $tokenJson = json_decode($tokenResponse, true);
               // debug logging (save to log file) — do not expose in production
               if ($httpCode !== 200 || !isset($tokenJson['access_token'])) {
                  return $this->response->setJsonContent([
                     'result'  => 'error',
                     'message' => 'Failed to refresh access token',
                     'http_code'=> $httpCode,
                     'debug'   => $tokenResponse
                  ]);
               }
               $accessToken = $tokenJson['access_token'];
               $expiresIn   = $tokenJson['expires_in'] ?? 3600;
               $this->redis->setex($accessTokenKey, $expiresIn - 60, $accessToken);
               $newRefresh = $tokenJson['refresh_token'] ?? $refreshToken;
               $this->redis->hset($redisKey, "refreshToken", $newRefresh);
               $this->redis->hset($redisKey, "accessToken", $accessToken);
      }
      $baseUrl = $zendesk_fqdn;

      // 5. Subject & status based on call type
      $subject = ($calltype == "incoming")
         ? "Incoming Call from {$parameters["number"]}"
         : "Outgoing Call to {$parameters["number"]}";

      $ticketData = [
         "ticket" => [
               "subject"      => $subject,
               "comment"      => ["body" => "This ticket was generated from RingQ for a {$parameters["calltype"]} call involving number {$parameters["number"]}."],
               "priority"     => "normal",
               "requester_id" => $contactId
         ]
      ];

      // 7. Create ticket
      $ch = curl_init($baseUrl . "/api/v2/tickets.json");
      curl_setopt_array($ch, [
         CURLOPT_POST           => true,
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POSTFIELDS     => json_encode($ticketData),
         CURLOPT_HTTPHEADER     => [
               "Authorization: Bearer $accessToken",
               "Content-Type: application/json",
         ],
      ]);
      $ticketResponse = curl_exec($ch);
      curl_close($ch);

      $ticketJson = json_decode($ticketResponse, true);

      if (isset($ticketJson['ticket']['id'])) {
         $ticketInfo = [
               "id"     => $ticketJson['ticket']['id'],
               "webUrl" => $ticketJson['ticket']['url'] ?? null
         ];
         return $this->response->setJsonContent([
               "result"    => "success",
               "contactId" => $contactId ?: ($ticketJson['contact']['id'] ?? null),
               "ticket"    => $ticketInfo
         ]);
      }

      // Error case
      return $this->response->setJsonContent([
         "result"  => "error",
         "message" => "Failed to create ticket",
         "debug"   => $ticketResponse
      ]);
   }

   public function zendeskupdateTicket($updatePayload, $domain_name) {
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json");

      // 1. Integration data from Redis
      
      $redisKey = "integration:{$domain_name}-zendesk";
      $integrationData = $this->redis->hgetall($redisKey);
      $clientId     = $integrationData['clientId'] ?? '';
      $clientSecret = $integrationData['clientSecret'] ?? '';
      $refreshToken = $integrationData['refreshToken'] ?? '';
      $zendesk_fqdn = $integrationData['zendesk_fqdn'];
      $tokenUrl = $zendesk_fqdn."oauth/tokens";
      // 2. Access token
      $accessTokenKey = "zendesk_access_token:{$domain_name}";
    //   $accessTokenKey = "zendesk_access_token:test";
      $accessToken = $this->redis->get($accessTokenKey);
      if (!$accessToken) {
         $tokenData = [
               "grant_type"                 => "refresh_token",
               "refresh_token"              => $refreshToken,
               "client_id"                  => $clientId,
               "client_secret"              => $clientSecret,   // required for confidential clients
               "scopes"                     => "users:read users:write tickets:read tickets:write",
               "expires_in"                 => 86400,           // 1 day
               "refresh_token_expires_in"   => 604800           // 7 days
         ];
         $ch = curl_init($tokenUrl);
         curl_setopt($ch, CURLOPT_POST, true);
         curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($tokenData));
         curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt($ch, CURLOPT_HTTPHEADER, [
               "Accept: application/json",
               "Content-Type: application/json"
         ]);
         $tokenResponse = curl_exec($ch);
         $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
         $curlErr  = curl_error($ch);
         curl_close($ch);
         if ($curlErr) {
               // network / curl failure
               return $this->response->setJsonContent([
                  'result'  => 'error',
                  'message' => 'cURL error: ' . $curlErr,
               ]);
         }
         $tokenJson = json_decode($tokenResponse, true);
         // debug logging (save to log file) — do not expose in production
         if ($httpCode !== 200 || !isset($tokenJson['access_token'])) {
               return $this->response->setJsonContent([
                  'result'  => 'error',
                  'message' => 'Failed to refresh access token',
                  'http_code'=> $httpCode,
                  'debug'   => $tokenResponse
               ]);
         }
         $accessToken = $tokenJson['access_token'];
         $expiresIn   = $tokenJson['expires_in'] ?? 3600;
         $this->redis->setex($accessTokenKey, $expiresIn - 60, $accessToken);
         $newRefresh = $tokenJson['refresh_token'] ?? $refreshToken;
         $this->redis->hset($redisKey, "refreshToken", $newRefresh);
         $this->redis->hset($redisKey, "accessToken", $accessToken);
      }
      $baseUrl = $zendesk_fqdn;
      $ticketId = $updatePayload['ticketId'] ?? null;


      $ch = curl_init("$baseUrl/api/v2/tickets/$ticketId");
      curl_setopt_array($ch, [
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_HTTPHEADER => [
               "Authorization: Bearer $accessToken",
               "Content-Type: application/json",
         ],
      ]);
      $ticketResponse = curl_exec($ch);
      curl_close($ch);
      $ticketJson = json_decode($ticketResponse, true);
      $originalRequesterId = $ticketJson['ticket']['requester_id'];

      if (!$ticketId) {
         return $this->response->setJsonContent([
               "result" => "error",
               "message" => "Missing ticketId"
         ]);
      }

      $descriptionMd = <<<EOT
      **📞 Call Details**

      - **Direction:** {$updatePayload['direction']}
      - **Number:** {$updatePayload['callNumber']}
      - **Duration:** {$updatePayload['timeSpent']} seconds
      - **Recording:** [{$updatePayload['recordName']}]({$updatePayload['recordURL']})
      EOT;


      $ticketData = [
               "ticket" => [
                  "comment"      => ["body" => $descriptionMd],
                  "author_id" => $originalRequesterId,
               ]
         ];


      // 5. PATCH request to update description
      $ch = curl_init($baseUrl . "/api/v2/tickets/" . $ticketId);
      curl_setopt_array($ch, [
         CURLOPT_CUSTOMREQUEST  => "PATCH",
         CURLOPT_RETURNTRANSFER => true,
         CURLOPT_POSTFIELDS     => json_encode($ticketData),
         CURLOPT_HTTPHEADER     => [
               "Authorization: Bearer $accessToken",
               "Content-Type: application/json",
         ],
      ]);
      $ticketResponse = curl_exec($ch);

      //     print_r($ticketResponse); exit;
      //  echo  $originalRequesterId; exit;

      curl_close($ch);
      $ticketJson = json_decode($ticketResponse, true);
      if (isset($ticketJson['ticket']['id'])) {
         return json_encode(["result" => "success"]);
      }
      return $this->response->setContent(json_encode(["result" => "success"]));
   }

   public function dialercontactsAction($username = "") {
      // No view rendering, return JSON
      $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
      $this->response->setHeader("Content-Type", "application/json; charset=utf-8");

      try {
         // If username not provided, try to get from session (common pattern)
         if (empty($username)) {
               // adjust session key based on your app (common ones: 'auth', 'user', 'username')
               $sess = $this->session->get('auth') ?? $this->session->get('user') ?? null;
               if (is_array($sess) && !empty($sess['username'])) {
                  $username = $sess['username'];
               } elseif (!empty($sess) && is_string($sess)) {
                  $username = $sess;
               }
         }

         if (empty($username)) {
               return $this->response->setJsonContent([
                  'result'  => 'error',
                  'message' => 'Missing username parameter or session authentication.'
               ]);
         }

         // --- 2. Find user ---
         $vUser = VUsers::findFirst([
               "conditions" => "username = :username:",
               "bind"       => ["username" => $username],
               "bindTypes"  => [Column::BIND_PARAM_STR],
         ]);

         if (!$vUser) {
               return $this->response->setJsonContent([
                  "result"  => "error",
                  "message" => "User not found."
               ]);
         }

      

         // Determine domain_uuid (prefer user->domain_uuid but allow session fallback)
         $domain_uuid = $vUser->domain_uuid ?? null;
         if (empty($domain_uuid)) {
               // try session domain (if your app stores it)
               $sessionDomainUuid = $this->session->get('domain_uuid') ?? null;
               if (!empty($sessionDomainUuid)) {
                  $domain_uuid = $sessionDomainUuid;
               } else {
                  return $this->response->setJsonContent([
                     'result' => 'error',
                     'message' => 'Domain UUID not available for user.'
                  ]);
               }
         }



                  $result = [
               'result'   => 'success',
               'contacts' => [],
               'queues'   => [],
         ];
      



      $vCallCenterAgents = VCallCenterAgents::find([
      "conditions" => "
         domain_uuid = :domain_uuid:
         AND queue_extension IS NULL
         AND agent_id != :agent_id:
         AND agent_id != :agent_id2:
         AND agent_id != :agent_id3:
      ",
      "bind" => [
         "domain_uuid" => $domain_uuid,
         "agent_id" => "9997",
         "agent_id2" => "9998",
         "agent_id3" => "9999",
      ],
      "bindTypes" => [
         Column::BIND_PARAM_STR,
         Column::BIND_PARAM_STR,
         Column::BIND_PARAM_STR,
         Column::BIND_PARAM_STR,
      ],
      "order" => "agent_id ASC",
      "hydration" => Resultset::HYDRATE_RECORDS
   ]);



               // Map agents to simple arrays for JSON
               foreach ($vCallCenterAgents as $agent) {
                  // Determine display name & phone/extension fields with fallbacks
                  $agentName = $agent->agent_name ?? $agent->agent_id ?? '';
                  // Some schemas store agent_extension or agent_phone — try several fields
                  $agentExtension = $agent->agent_extension ?? $agent->extension ?? $agent->agent_id ?? '';

                  $result['contacts'][] = [
                     'id'        => (string)($agent->agent_id ?? ''),
                     'name'      => (string)trim($agentName),
                     'number'    => (string)trim($agentExtension),
                     'status'    => (string)($agent->status ?? ''), // optional extra
                     'queue'     => (string)($agent->queue_name ?? ''), // optional
                  ];
               }


               // --- 5. Fetch call center queues ---

   $vCallCenterQueues = VCallCenterQueues::find([
                  "hydration"  => Resultset::HYDRATE_RECORDS,
                  "conditions" => "domain_uuid = :domain_uuid:",
                  // select columns that are meaningful for the dialer
                  "columns"    => "call_center_queue_uuid, queue_name, queue_extension, queue_description, queue_max_callers",
                  "bind"       => ["domain_uuid" => $domain_uuid],
                  "bindTypes"  => [Column::BIND_PARAM_STR],
                  "order"      => "queue_extension ASC",
               ]);

               foreach ($vCallCenterQueues as $q) {
                  $result['queues'][] = [
                     'id'          => (string)($q->call_center_queue_uuid ?? ''),
                     'name'        => (string)trim($q->queue_name ?? ''),
                     'extension'   => (string)($q->queue_extension ?? ''),
                     'description' => (string)($q->queue_description ?? ''),
                     'max_callers' => (int)($q->queue_max_callers ?? 0),
                  ];
               }



         

         // --- 6. Return JSON ---
         return $this->response->setJsonContent($result, JSON_UNESCAPED_SLASHES);

      } catch (\Exception $e) {
         // Log exception if you have logger: $this->logger->error($e->getMessage());
         return $this->response->setJsonContent([
               'result'  => 'error',
               'message' => 'Server error: ' . ControllerBase::safeErrorMessage($e)
         ]);
      }
   }

    private const CHAT_FILE_EXTENSIONS = ["jpg", "jpeg", "png", "gif", "webp", "pdf", "txt", "csv", "doc", "docx", "xls", "xlsx", "ppt", "pptx", "zip", "mp3", "mp4", "m4a", "ogg", "wav"];

    public static function safeChatFileExtension($fileName): string
    {
        $extension = strtolower((string) pathinfo((string) $fileName, PATHINFO_EXTENSION));
        return in_array($extension, self::CHAT_FILE_EXTENSIONS, true) ? $extension : "bin";
    }
}
