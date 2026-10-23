<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

class LanguageController extends ControllerBase {

    public function languageAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setJsonContent([
                "result"  => "error",
                "message" => "Unathorized request. (Code AL1000)",
            ]);
        }

        if ($this->request->isGet()) {
            $globalLocale = $this->redis->get("language:" . $session["subdomain"] . "-global") ?: null;

            $userSetting = VUserSettings::findFirst([
                "conditions" => "user_uuid = :user_uuid: AND domain_uuid = :domain_uuid: AND user_setting_subcategory = :subcategory:",
                "bind" => [
                    "user_uuid"   => $session["user_uuid"],
                    "domain_uuid" => $session["domain_uuid"],
                    "subcategory" => "ui_language",
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);

            return $this->response->setJsonContent([
                "result" => "success",
                "global" => $globalLocale,
                "user"   => $userSetting ? $userSetting->user_setting_value : null,
            ]);
        }

        if ($this->request->isPost()) {
            $body   = $this->request->getJsonRawBody(true);
            $locale = isset($body["locale"]) ? trim((string) $body["locale"]) : null;
            $scope  = isset($body["scope"])  ? trim((string) $body["scope"])  : "user";

            $allowed = ['en','ar','fr','es','pt','de','zh','ja','ko','it','nl','ru','tr','id','hi','th','vi','pl','sv','fil'];
            if (!$locale || !in_array($locale, $allowed, true)) {
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "message" => "Invalid locale.",
                ]);
            }

            if ($scope === "global") {
                $this->redis->set("language:" . $session["subdomain"] . "-global", $locale);
                return $this->response->setJsonContent(["result" => "success"]);
            }

            // scope === "user" — save personal preference to VUserSettings
            $userSetting = VUserSettings::findFirst([
                "conditions" => "user_uuid = :user_uuid: AND domain_uuid = :domain_uuid: AND user_setting_subcategory = :subcategory:",
                "bind" => [
                    "user_uuid"   => $session["user_uuid"],
                    "domain_uuid" => $session["domain_uuid"],
                    "subcategory" => "ui_language",
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);

            if ($userSetting) {
                $userSetting->user_setting_value = $locale;
                $userSetting->update_date        = date("Y-m-d H:i:s");
                $userSetting->update_user        = $session["user_uuid"];
                $saved = $userSetting->update();
            } else {
                $security                              = new \Phalcon\Security\Random();
                $userSetting                           = new VUserSettings();
                $userSetting->user_setting_uuid        = $security->uuid();
                $userSetting->user_uuid                = $session["user_uuid"];
                $userSetting->domain_uuid              = $session["domain_uuid"];
                $userSetting->user_setting_category    = "language";
                $userSetting->user_setting_subcategory = "ui_language";
                $userSetting->user_setting_name        = "locale";
                $userSetting->user_setting_value       = $locale;
                $userSetting->insert_date              = date("Y-m-d H:i:s");
                $userSetting->insert_user              = $session["user_uuid"];
                $userSetting->update_date              = date("Y-m-d H:i:s");
                $userSetting->update_user              = $session["user_uuid"];
                $saved = $userSetting->save();
            }

            if (!$saved) {
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "message" => "Failed to save language preference.",
                ]);
            }

            return $this->response->setJsonContent(["result" => "success"]);
        }

        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => "Method not allowed.",
        ]);
    }
}
