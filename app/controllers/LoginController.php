<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;
use ElephantIO\Client;
use chillerlan\QRCode\QRCode;

class LoginController extends ControllerBase
{
  public function indexAction()
  {
  }

  public function forgotAction($code = "", $email = "")
  {
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    $lean = $this->wantsLeanLogin([]);
    $viaCode = false;
    if ($this->request->isPost() && $code === "verify") {
      $body = json_decode((string) file_get_contents("php://input"), true);
      $body = is_array($body) ? $body : [];
      $code = (string) ($this->request->getPost("code") ?? ($body["code"] ?? ""));
      $email = (string) ($this->request->getPost("email") ?? ($body["email"] ?? ""));
      $lean = $this->wantsLeanLogin($body);
      $viaCode = true;
    }
    if ($this->request->isGet() || $viaCode) {
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
            "message" => trim($message) . " (Code LF1000)",
          ]);
          $this->response->setContent($response);
          return $this->response;
        } else {
          if ($this->loginAttemptsExceeded("code", $parameters["email"])) {
            return $this->tooManyLoginAttempts();
          }
          $verification = strval($parameters["code"]);
          $exists = $this->redis->exists("forgot-" . $parameters["email"]);
          if ($exists) {
            $checkCode = $this->redis->hget("forgot-" . $parameters["email"], "forgot");
            if ($checkCode == $verification) {
              $this->request->setStrictHostCheck(true);
              $uri = explode(':', $this->request->getHttpHost())[0]; // strip port if present
              $vDomain = VDomains::findFirst([
                 "conditions" => "domain_name = :domain_name:",
                 "bind" => [
                    "domain_name" => $uri,
                 ],
                 "bindTypes" => [Column::BIND_PARAM_STR],
              ]);
              if ($vDomain && $vDomain->domain_parent_uuid) {
                 $vDomain = VDomains::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" => [
                       "domain_uuid" => $vDomain->domain_parent_uuid,
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                 ]) ?: $vDomain;
              }
              $vUser = VUsers::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND user_email = :user_email:",
                "bind" => [
                  "domain_uuid" => $vDomain->domain_uuid,
                  "user_email" => $parameters["email"],
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
              ]);
              if (!$vUser) {
                $this->recordLoginAttempt("code", $parameters["email"]);
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1001)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $vExtensionUser = VExtensionUsers::findFirst(
                  [
                      "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                      "bind" =>
                          [
                              "domain_uuid" => $vDomain->domain_uuid,
                              "user_uuid" => $vUser->user_uuid,
                          ],
                      "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                  ]
              );
              if ($vExtensionUser) {
                $vExtensions = VExtensions::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                    "bind" => [
                        "domain_uuid" => $vDomain->domain_uuid,
                        "extension_uuid" => $vExtensionUser->extension_uuid,
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                ]);
                if ($vExtensions) {
                  $webappEnabled = $this->redis->exists("disablewebapp:" . $vExtensions->extension . ":" . $vDomain->domain_name);
                  if ($webappEnabled) {
                    $response = json_encode([
                      "result" => "error",
                      "message" => "Sorry, webapp login is currently disabled for this extension. (Code LF1002)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                  }
                }
              }
              $vUser->user_status = "Available";
              if (!$vUser->update()) {
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1002)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $user_uuid = $vUser->user_uuid;
              $domain_uuid = $vUser->domain_uuid;
              $company_email = $vUser->user_email;
              $vExtensionUser = VExtensionUsers::findFirst([
                "conditions" => "user_uuid = :user_uuid:",
                "bind" => [
                  "user_uuid" => $user_uuid,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR],
              ]);
              if (!$vExtensionUser) {
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1003)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $qUser = QUsers::findFirst([
                "conditions" => "user_uuid = :user_uuid:",
                "bind" => [
                  "user_uuid" => $user_uuid,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR],
              ]);
              if (!$qUser) {
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1004)",
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
                  "message" => "An error has occurred. (Code LF1005)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $expire = time() + 86400 * 360;
              // MR Edits From
              $this->cookies->set("RINGQKIE", $user_uuid, $expire, "/", true, $vExtensionUser->vExtensions->accountcode, true, [
            'samesite' => 'Lax'
        ]);
              $this->cookies->set("RINGQENS", $access_token, $expire, "/", true, $vExtensionUser->vExtensions->accountcode, true, [
            'samesite' => 'Lax'
        ]);
         // MR Edits To
              $walltoken = $this->security->hash($user_uuid);
              $this->redis->sadd("wallboard:" . $vExtensionUser->vExtensions->accountcode, $walltoken);
              $authenticated = [
                "username" => $vUser->username,
                "firstname" => $qUser->first_name,
                "lastname" => $qUser->last_name,
                "email" => $vUser->user_email,
                "image" => $qUser->image,
                "extension" => $vExtensionUser->vExtensions->extension,
                "subdomain" => $vExtensionUser->vExtensions->accountcode,
                "domain" => $this->config->application->publicUrl,
                "domain_uuid" => $domain_uuid,
                "sip_uuid" => $vExtensions->password,
                "user_uuid" => $user_uuid,
                "walltoken" => $walltoken,
                "access" => "registered",
                "result" => "success",
              ];
              $this->session->set("auth", $authenticated);
              $this->redis->del("forgot-" . $parameters["email"]);
              $this->clearLoginAttempts("code", $parameters["email"]);
              error_log("-- LOGIN SUCCESS --");
              $response = json_encode([
                "result" => "success",
                "message" => "Login success.",
                "content" => $lean ? $this->leanAuthentication($authenticated) : $authenticated,
              ]);
              $this->response->setContent($response);
              return $this->response;
            } else {
              $this->recordLoginAttempt("code", $parameters["email"]);
              $response = json_encode([
                "result" => "error",
                "message" => "Please check access code. (Code LF1006)",
              ]);
              $this->response->setContent($response);
              return $this->response;
            }
          } else {
            $this->recordLoginAttempt("code", $parameters["email"]);
            $response = json_encode([
              "result" => "error",
              "message" => "Please check access code. (Code LF1007)",
            ]);
            $this->response->setContent($response);
            return $this->response;
          }
        }
      } else {
        $response = json_encode([
          "result" => "error",
          "message" => "Please check access code. (Code LF1008)",
        ]);
        $this->response->setContent($response);
        return $this->response;
      }
    } else {
        $parameters = [];
        $parameters["email"] = $this->request->getPost("email", "string");
        $parameters["password"] = $this->request->getPost("password", "string");
        if (empty($parameters["email"])) {
          $contents = file_get_contents("php://input");
          $parameters = json_decode($contents, true);
        }
        $parameters["email"] = $this->filter->sanitize($parameters["email"], "string");
        $parameters["password"] = $this->filter->sanitize($parameters["password"], "string");
        $lean = $this->wantsLeanLogin($parameters);
        if (empty($parameters["password"])) {
          $response = json_encode([
            "result" => "error",
            "message" => "Sorry, password is required (Code LF1000)",
          ]);
          $this->response->setContent($response);
          return $this->response;
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
            "message" => trim($message) . " (Code LF1000)",
          ]);
          $this->response->setContent($response);
          return $this->response;
        } else {
          if ($this->loginAttemptsExceeded("login", $parameters["email"])) {
            return $this->tooManyLoginAttempts();
          }
          $vUser = VUsers::findFirst([
              "conditions" => "user_email = :user_email:",
              "bind" => [
                "user_email" => $parameters["email"],
              ],
              "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if (!$vUser) {
              $this->recordLoginAttempt("login", $parameters["email"]);
              $response = json_encode([
                "result" => "error",
                "message" => "An error has occurred. (Code LF1001)",
              ]);
              $this->response->setContent($response);
              return $this->response;
            }
            $qUser = QUsers::findFirst([
              "conditions" => "user_uuid = :user_uuid:",
              "bind" => [
                "user_uuid" => $vUser->user_uuid,
              ],
              "bindTypes" => [Column::BIND_PARAM_STR],
            ]);
            if (!$qUser) {
              $this->recordLoginAttempt("login", $parameters["email"]);
              $response = json_encode([
                "result" => "error",
                "message" => "An error has occurred. (Code LF1001)",
              ]);
              $this->response->setContent($response);
              return $this->response;
            }
            $checkCode = $this->redis->hget("password-" . $parameters["email"], "password");
            error_log("<!-- this is password");
            $passwordMatches = self::storedPasswordMatches((string) $parameters["password"], (string) $qUser->password);
            if ($passwordMatches && empty(password_get_info((string) $qUser->password)["algo"])) {
              $qUser->password = password_hash((string) $parameters["password"], PASSWORD_DEFAULT);
              $qUser->update();
            }
            if (($checkCode !== null && $checkCode !== false && $checkCode !== "" && hash_equals((string) $checkCode, (string) $parameters["password"])) || $passwordMatches) {
              $vUser->user_status = "Available";
              if (!$vUser->update()) {
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1002)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $user_uuid = $vUser->user_uuid;
              $domain_uuid = $vUser->domain_uuid;
              $company_email = $vUser->user_email;
              $vExtensionUser = VExtensionUsers::findFirst([
                "conditions" => "user_uuid = :user_uuid:",
                "bind" => [
                  "user_uuid" => $user_uuid,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR],
              ]);
              if (!$vExtensionUser) {
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1003)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $qUser = QUsers::findFirst([
                "conditions" => "user_uuid = :user_uuid:",
                "bind" => [
                  "user_uuid" => $user_uuid,
                ],
                "bindTypes" => [Column::BIND_PARAM_STR],
              ]);
              if (!$qUser) {
                $response = json_encode([
                  "result" => "error",
                  "message" => "An error has occurred. (Code LF1004)",
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
                  "message" => "An error has occurred. (Code LF1005)",
                ]);
                $this->response->setContent($response);
                return $this->response;
              }
              $expire = time() + 86400 * 360;
              $serverIp = $this->request->getServerAddress();
              $this->cookies->set("RINGQKIE", $user_uuid, $expire, "/", true, $serverIp, true, ["samesite" => "Lax"]);
              $this->cookies->set("RINGQENS", $access_token, $expire, "/", true, $serverIp, true, ["samesite" => "Lax"]);
              $walltoken = $this->security->hash($user_uuid);
              $this->redis->sadd("wallboard:" . $vExtensionUser->vExtensions->accountcode, $walltoken);
              $authenticated = [
                "username" => $vUser->username,
                "firstname" => $qUser->first_name,
                "lastname" => $qUser->last_name,
                "email" => $vUser->user_email,
                "image" => $qUser->image,
                "extension" => $vExtensionUser->vExtensions->extension,
                "subdomain" => $vExtensionUser->vExtensions->accountcode,
                "domain" => $this->config->application->publicUrl,
                "domain_uuid" => $domain_uuid,
                "sip_uuid" => $vExtensionUser->vExtensions->password,
                "user_uuid" => $user_uuid,
                "walltoken" => $walltoken,
                "access" => "registered",
                "result" => "success",
              ];
              $this->session->set("auth", $authenticated);
              $this->redis->del("password-" . $parameters["email"]);
              $this->clearLoginAttempts("login", $parameters["email"]);
              error_log("-- LOGIN SUCCESS --");
              $response = json_encode([
                "result" => "success",
                "message" => "Login success.",
                "content" => $lean ? $this->leanAuthentication($authenticated) : $authenticated,
              ]);
              $this->response->setContent($response);
              return $this->response;
            } else {
              $this->recordLoginAttempt("login", $parameters["email"]);
              $response = json_encode([
                "result" => "error",
                "message" => "Please check password. (Code LF1007)",
              ]);
              $this->response->setContent($response);
              return $this->response;
            }
          // $this->request->setStrictHostCheck(true);
          // $uri = $this->request->getHttpHost();
          // $vDomain = VDomains::findFirst([
          //    "conditions" => "domain_name = :domain_name:",
          //    "bind" => [
          //       "domain_name" => $uri,
          //    ],
          //    "bindTypes" => [Column::BIND_PARAM_STR],
          // ]);
          // if ($vDomain) {
          //   $vUser = VUsers::findFirst([
          //     "conditions" => "domain_uuid = :domain_uuid: AND user_email = :user_email:",
          //     "bind" => [
          //       "domain_uuid" => $vDomain->domain_uuid,
          //       "user_email" => $parameters["email"],
          //     ],
          //     "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
          //   ]);
          //   if (!$vUser) {
          //     $response = json_encode([
          //       "result" => "error",
          //       "message" => "An error has occurred. (Code LF1001)",
          //     ]);
          //     $this->response->setContent($response);
          //     return $this->response;
          //   }
          //   $qUser = QUsers::findFirst([
          //     "conditions" => "user_uuid = :user_uuid:",
          //     "bind" => [
          //       "user_uuid" => $vUser->user_uuid,
          //     ],
          //     "bindTypes" => [Column::BIND_PARAM_STR],
          //   ]);
          //   if (!$qUser) {
          //     $response = json_encode([
          //       "result" => "error",
          //       "message" => "An error has occurred. (Code LF1001)",
          //     ]);
          //     $this->response->setContent($response);
          //     return $this->response;
          //   }
          //   $checkCode = $this->redis->hget("password-" . $parameters["email"], "password");
          //   if ($checkCode == $parameters["password"] || $qUser->password == $parameters["password"]) {
          //     $vUser->user_status = "Available";
          //     if (!$vUser->update()) {
          //       $response = json_encode([
          //         "result" => "error",
          //         "message" => "An error has occurred. (Code LF1002)",
          //       ]);
          //       $this->response->setContent($response);
          //       return $this->response;
          //     }
          //     $user_uuid = $vUser->user_uuid;
          //     $domain_uuid = $vUser->domain_uuid;
          //     $company_email = $vUser->user_email;
          //     $vExtensionUser = VExtensionUsers::findFirst([
          //       "conditions" => "user_uuid = :user_uuid:",
          //       "bind" => [
          //         "user_uuid" => $user_uuid,
          //       ],
          //       "bindTypes" => [Column::BIND_PARAM_STR],
          //     ]);
          //     if (!$vExtensionUser) {
          //       $response = json_encode([
          //         "result" => "error",
          //         "message" => "An error has occurred. (Code LF1003)",
          //       ]);
          //       $this->response->setContent($response);
          //       return $this->response;
          //     }
          //     $qUser = QUsers::findFirst([
          //       "conditions" => "user_uuid = :user_uuid:",
          //       "bind" => [
          //         "user_uuid" => $user_uuid,
          //       ],
          //       "bindTypes" => [Column::BIND_PARAM_STR],
          //     ]);
          //     if (!$qUser) {
          //       $response = json_encode([
          //         "result" => "error",
          //         "message" => "An error has occurred. (Code LF1004)",
          //       ]);
          //       $this->response->setContent($response);
          //       return $this->response;
          //     }
          //     $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
          //     $access_token = md5($company_email . $userAgent);
          //     $qToken = new QTokens();
          //     $qToken->user_uuid = $user_uuid;
          //     $qToken->domain_uuid = $domain_uuid;
          //     $qToken->access_token = $access_token;
          //     $qToken->insert_date = date("Y-m-d H:i:s");
          //     $qToken->user_agent = $userAgent;
          //     $qToken->type = 1; // web, android or ios
          //     if (!$qToken->save()) {
          //       $this->db->rollback();
          //       $response = json_encode([
          //         "result" => "error",
          //         "message" => "An error has occurred. (Code LF1005)",
          //       ]);
          //       $this->response->setContent($response);
          //       return $this->response;
          //     }
          //     $expire = time() + 86400 * 360;
          //     $this->cookies->set("RINGQKIE", $user_uuid, $expire, "/", true, $vExtensionUser->vExtensions->accountcode, false);
          //     $this->cookies->set("RINGQENS", $access_token, $expire, "/", true, $vExtensionUser->vExtensions->accountcode, false);
          //     $walltoken = $this->security->hash($user_uuid);
          //     $this->redis->sadd("wallboard:" . $vExtensionUser->vExtensions->accountcode, $walltoken);
          //     $authenticated = [
          //       "username" => $vUser->username,
          //       "firstname" => $qUser->first_name,
          //       "lastname" => $qUser->last_name,
          //       "email" => $vUser->user_email,
          //       "image" => $qUser->image,
          //       "extension" => $vExtensionUser->vExtensions->extension,
          //       "subdomain" => $vExtensionUser->vExtensions->accountcode,
          //       "domain" => $this->config->application->publicUrl,
          //       "domain_uuid" => $domain_uuid,
          //       "sip_uuid" => $vExtensionUser->vExtensions->password,
          //       "user_uuid" => $user_uuid,
          //       "walltoken" => $walltoken,
          //       "access" => "registered",
          //       "result" => "success",
          //     ];
          //     $this->session->set("auth", $authenticated);
          //     $this->redis->del("password-" . $parameters["email"]);
          //     error_log("-- LOGIN SUCCESS --");
          //     $response = json_encode([
          //       "result" => "success",
          //       "message" => "Login success.",
          //       "content" => $authenticated,
          //     ]);
          //     $this->response->setContent($response);
          //     return $this->response;
          //   } else {
          //     $response = json_encode([
          //       "result" => "error",
          //       "message" => "Please check password. (Code LF1007)",
          //     ]);
          //     $this->response->setContent($response);
          //     return $this->response;
          //   }
          // }

        }
    }
  }

  public function cspreportAction()
  {
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setStatusCode(204, "No Content");
    if (!$this->request->isPost()) {
      return $this->response;
    }
    $body = substr((string) $this->request->getRawBody(), 0, 8192);
    $report = json_decode($body, true);
    if (!is_array($report)) {
      return $this->response;
    }
    $log = "/var/log/ringq/csp-reports.log";
    if (is_file($log) && filesize($log) > 5 * 1024 * 1024) {
      return $this->response;
    }
    $line = json_encode([
      "at" => gmdate("c"),
      "host" => (string) $this->request->getHttpHost(),
      "ip" => (string) $this->request->getClientAddress(),
      "report" => $report["csp-report"] ?? $report,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($log, $line, FILE_APPEND | LOCK_EX) === false) {
      error_log("csp-report " . $line);
    }
    return $this->response;
  }

  public function recoverAction()
  {
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    if ($this->request->isGet()) {
      $this->response->redirect("index");
      return;
    }
    if ($this->request->isPost()) {
      $parameters = [];
      $parameters["type"] = $this->request->getPost("type", "string");
      $parameters["email"] = $this->request->getPost("email", "string");
      $emailForm = new EmailForm();
      if (!$emailForm->isValid($parameters)) {
        $messages = $emailForm->getMessages();
        $message = "";
        foreach ($messages as $error) {
          $message .= $error . " ";
        }
        $response = json_encode([
          "result" => "error",
          "message" => trim($message) . " (Code LR1000)",
          "content" => [],
        ]);
        $this->response->setContent($response);
        return $this->response;
      } else {
        if ($this->loginAttemptsExceeded("recover", $parameters["email"])) {
          return $this->tooManyLoginAttempts();
        }
        $this->recordLoginAttempt("recover", $parameters["email"]);
        $this->request->setStrictHostCheck(true);
        $uri = explode(':', $this->request->getHttpHost())[0];

        $vDomain = VDomains::findFirst([
           "conditions" => "domain_name = :domain_name:",
           "bind" => [
              "domain_name" => $uri,
           ],
           "bindTypes" => [Column::BIND_PARAM_STR],
        ]);

        if ($vDomain && $vDomain->domain_parent_uuid) {
           $vDomain = VDomains::findFirst([
              "conditions" => "domain_uuid = :domain_uuid:",
              "bind" => [
                 "domain_uuid" => $vDomain->domain_parent_uuid,
              ],
              "bindTypes" => [Column::BIND_PARAM_STR],
           ]) ?: $vDomain;
        }

        if (!$vDomain) {
          $response = json_encode([
            "result" => "error",
            "message" => "Sorry, please check account domain. (Code RI1002)",
          ]);
          $this->response->setContent($response);
          return $this->response;
        }
        $vUser = VUsers::findFirst([
          "conditions" => "domain_uuid = :domain_uuid: AND user_email = :user_email:",
          "bind" => [
            "domain_uuid" => $vDomain->domain_uuid,
            "user_email" => $parameters["email"],
          ],
          "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if (!$vUser) {
          $response = json_encode([
            "result" => "error",
            "message" => "If email exists, an email will be sent for account recovery.",
          ]);
          $this->response->setContent($response);
          return $this->response;
        }
        error_log("<!-- THIS IS CALLED 0");
        $vExtensionUser = VExtensionUsers::findFirst(
            [
                "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                "bind" =>
                    [
                        "domain_uuid" => $vDomain->domain_uuid,
                        "user_uuid" => $vUser->user_uuid,
                    ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]
        );
        if ($vExtensionUser) {
          error_log("<!-- THIS IS CALLED 1");
          $vExtensions = VExtensions::findFirst([
              "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
              "bind" => [
                  "domain_uuid" => $vDomain->domain_uuid,
                  "extension_uuid" => $vExtensionUser->extension_uuid,
              ],
              "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
          ]);
          if ($vExtensions) {
            $webappEnabled = $this->redis->exists("disablewebapp:" . $vExtensions->extension . ":" . $vDomain->domain_name);
            if ($webappEnabled) {
              $response = json_encode([
                "result" => "error",
                "message" => "Sorry, webapp login is currently disabled for this extension. (Code LF1002)",
              ]);
              $this->response->setContent($response);
              return $this->response;
            }
          }
        }
        if ($vUser->vExtensionUsers->vExtensions->enabled == false) {
          $response = json_encode([
            "result" => "error",
            "message" => "Sorry, this extension has been disabled by administrator.",
          ]);
          $this->response->setContent($response);
          return $this->response;
        }
        $linkedIpDomains = VDomains::find([
           "conditions" => "domain_parent_uuid = :domain_uuid:",
           "bind" => [
              "domain_uuid" => $vDomain->domain_uuid,
           ],
           "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        $linkedIpDomain = $linkedIpDomains->getFirst();
        $validHosts = [$vDomain->domain_name];
        foreach ($linkedIpDomains as $ipDomain) {
           $validHosts[] = $ipDomain->domain_name;
        }
        if (!in_array($uri, $validHosts)) {
          $response = json_encode([
            "result" => "error",
            "message" => "If email exists, an email will be sent for account recovery.",
          ]);
          $this->response->setContent($response);
          return $this->response;
        }
        if ($parameters["type"] == "0") {
          $exists = $this->redis->exists("forgot-" . $parameters["email"]);
          if ($exists) {
            $response = json_encode([
              "result" => "success",
              "message" => "An email has already been sent with access code (valid for 15 minutes).",
            ]);
            $this->response->setContent($response);
            return $this->response;
          }
          $forgot = random_int(100000000, 999999999);
          $this->redis->lrem("forgot", 0, $parameters["email"]);
          $this->redis->lpush("forgot", $parameters["email"]);
          $this->redis->hmset("forgot-" . $parameters["email"], ["forgot" => $forgot, "fqdn" => $vDomain->domain_name]);
          $this->redis->expire("forgot-" . $parameters["email"], 900);
          exec('/usr/bin/php /var/www/ringq/app/cli.php main main3', $output, $result);
          $response = json_encode([
            "result" => "success",
            "message" => "Please check your email address for access code (valid for 15 minutes).",
          ]);
          $this->response->setContent($response);
          return $this->response;
        } else {
          $ssoNumber = $this->redis->get("security:" . $vDomain->domain_name . "-ssoNumber");
          if ($ssoNumber != "1") {
            $response = json_encode([
              "result" => "error",
              "message" => "SSO number is disabled for this domain. Please contact administrator.",
            ]);
            $this->response->setContent($response);
            return $this->response;
          }
          $qCallBehavior = QCallBehaviors::findFirst([
             "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
             "bind" => [
                "domain_uuid" => $vUser->domain_uuid,
                "extension" => $vUser->VExtensionUsers->VExtensions->extension,
             ],
             "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
          ]);
          if ($qCallBehavior && $qCallBehavior->sso_number != 1) {
            $response = json_encode([
              "result" => "error",
              "content" => $forgot,
              "message" => "SSO number is disabled for this extension",
            ]);
            $this->response->setContent($response);
            return $this->response;
          }
          $security = new \Phalcon\Security\Random();
          $this->request->setStrictHostCheck(true);
          $hostname = "@" . $this->request->getHttpHost();
          $uuid = $security->uuid();
          $forgot = strval(rand(10, 99));
          $this->redis->set("sso-". $vUser->VExtensionUsers->VExtensions->extension . "@" . $vDomain->domain_name , $uuid . $hostname ."@". $forgot);
          $this->redis->set("sso-". $uuid . $hostname ."@". $forgot, $parameters["type"]);
          $this->redis->expire("sso-". $uuid . $hostname ."@". $forgot, 45);
          $this->redis->expire("sso-". $vUser->VExtensionUsers->VExtensions->extension . "@" . $vDomain->domain_name , 45);
          $socketId = $this->redis->get("socketId:". $vDomain->domain_name . ":" . $vUser->VExtensionUsers->VExtensions->extension);
          $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                "persistent" => false,
          ]));
          $client->initialize();
          $client->emit('sso-initiate2', ["socket" => $socketId, "uuid" => $uuid . $hostname, "forgot" => $forgot]);
          $data = array();
          $token = $this->redis->get($vUser->VExtensionUsers->VExtensions->extension . "@@" . $vDomain->domain_name);
          if ($token) {
            if (str_contains($token, "-") && strlen($token) < 100) {
              $url = "https://onesignal.com/api/v1/notifications";
              $headers = ["Authorization: Basic NDhjZDM3MzMtZjIwOS00MTZjLWJjNDktZWE2M2MzZmMyZjNl", "Content-Type: application/json;charset=UTF-8"];
              $data = [
                "app_id" => "581811d9-f716-44b8-9fff-cb1e93096c27",
                "headings" => [
                  "en" => "Verify RingQ Sing-in",
                ],
                "contents" => [
                  "en" => "New sign-in request made on your extension.",
                ],
                "include_player_ids" => [
                  $token
                ],
                "content_available" => true
              ];
            } else {
              $url = "https://api.pushy.me/push?api_key=d1e0dfaa6a6f602379d00a4a6595124fd6107faa6056a6f1acbf1bd8098475ce";
              $headers = ["Content-type: application/json"];
              $data = [
                "to" => $token,
                "data" => [
                  "content_available2" => 1,
                ],
                "notification" => [
                    "title" => "Verify RingQ Sing-in",
                    "body" => "New sign-in request made on your extension.",
                ]
              ];
            }
            $content = json_encode($data);
            error_log($content);
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            $result = curl_exec($curl);
            curl_close($curl);
            $resultContent = json_decode($result);
            $response = json_encode($resultContent);
            error_log($response);
          }
          $response = json_encode([
            "result" => "success",
            "content" => $forgot,
            "message" => "SSO number has been requested",
          ]);
          $this->response->setContent($response);
          return $this->response;
        }
      }
    }
  }

  public function recover2Action()
  {
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    if ($this->request->isGet()) {
      $this->response->redirect("index");
      return;
    }
    if (!$this->session->has("auth")) {
      $response = json_encode([
        "result" => "error",
        "message" => "Disallowed request.",
      ]);
      $this->response->setContent($response);
      return $this->response;
    }
    $session = $this->session->get("auth");
    if ($this->request->isPost()) {
      $parameters = [];
      $parameters["email"] = $this->request->getPost("email", "string");
      $emailForm = new EmailForm();
      if (!$emailForm->isValid($parameters)) {
        $messages = $emailForm->getMessages();
        $message = "";
        foreach ($messages as $error) {
          $message .= $error . " ";
        }
        $response = json_encode([
          "result" => "error",
          "message" => trim($message) . " (Code LR1000)",
          "content" => [],
        ]);
        $this->response->setContent($response);
        return $this->response;
      } else {
        $vUser = VUsers::findFirst([
          "conditions" => "domain_uuid = :domain_uuid: AND user_email = :user_email:",
          "bind" => [
            "domain_uuid" => $session["domain_uuid"],
            "user_email" => $parameters["email"],
          ],
          "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if (!$vUser) {
          $response = json_encode([
            "result" => "error",
            "message" => "If email exists, an email will be sent for account recovery. 3",
          ]);
          $this->response->setContent($response);
          return $this->response;
        }
        $verify = random_int(100000000, 999999999);
        $this->redis->hmset("verify2-" . $parameters["email"], ["verify" => $verify, "user_uuid" => $vUser->user_uuid, "domain_uuid" => $vUser->domain_uuid, "extension_uuid" => $vUser->vExtensionUsers->vExtensions->extension_uuid]);
        $this->redis->expire("verify2-" . $parameters["email"], 900);
        $response = json_encode([
          "result" => "success",
          "accessCode" => $verify,
          "message" => "Access code (valid for 15 minutes).",
        ]);
        $this->response->setContent($response);
        return $this->response;
      }
    }
  }

  public static function storedPasswordMatches(string $plain, string $stored): bool
  {
    if ($plain === "" || $stored === "") {
      return false;
    }
    if (!empty(password_get_info($stored)["algo"])) {
      return password_verify($plain, $stored);
    }
    return hash_equals($stored, $plain);
  }

  private const LOGIN_ATTEMPT_WINDOW = 900;
  private const LOGIN_ATTEMPTS_PER_EMAIL = 5;
  private const LOGIN_ATTEMPTS_PER_IP = 20;

  private function loginAttemptKeys($scope, $email): array
  {
    $email = strtolower(trim((string) $email));
    $ip = (string) $this->request->getClientAddress();
    return [
      "loginattempt:" . $scope . ":email:" . md5($email) => self::LOGIN_ATTEMPTS_PER_EMAIL,
      "loginattempt:" . $scope . ":ip:" . $ip => self::LOGIN_ATTEMPTS_PER_IP,
    ];
  }

  private function loginAttemptsExceeded($scope, $email): bool
  {
    try {
      foreach ($this->loginAttemptKeys($scope, $email) as $key => $limit) {
        if (intval($this->redis->get($key)) >= $limit) {
          return true;
        }
      }
    } catch (\Throwable $e) {
      error_log("login attempt check failed: " . $e->getMessage());
    }
    return false;
  }

  private function recordLoginAttempt($scope, $email): void
  {
    try {
      foreach (array_keys($this->loginAttemptKeys($scope, $email)) as $key) {
        if (intval($this->redis->incr($key)) === 1) {
          $this->redis->expire($key, self::LOGIN_ATTEMPT_WINDOW);
        }
      }
    } catch (\Throwable $e) {
      error_log("login attempt record failed: " . $e->getMessage());
    }
  }

  private function clearLoginAttempts($scope, $email): void
  {
    try {
      $keys = array_keys($this->loginAttemptKeys($scope, $email));
      $this->redis->del($keys[0]);
    } catch (\Throwable $e) {
      error_log("login attempt clear failed: " . $e->getMessage());
    }
  }

  private function tooManyLoginAttempts()
  {
    $this->response->setStatusCode(429, "Too Many Requests");
    $this->response->setHeader("Retry-After", (string) self::LOGIN_ATTEMPT_WINDOW);
    $this->response->setContent(json_encode([
      "result" => "error",
      "message" => "Too many attempts. Please wait 15 minutes and try again. (Code LF1009)",
    ]));
    return $this->response;
  }

  private function wantsLeanLogin($parameters): bool
  {
    $value = is_array($parameters) && array_key_exists("lean", $parameters)
      ? $parameters["lean"]
      : ($this->request->getPost("lean") ?? $this->request->getQuery("lean"));
    return in_array(strtolower((string) $value), ["1", "true", "yes"], true);
  }

  private function leanAuthentication(array $authenticated): array
  {
    unset($authenticated["sip_uuid"], $authenticated["walltoken"]);
    return $authenticated;
  }
}
