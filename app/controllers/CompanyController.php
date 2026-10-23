<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

/**
 * Company — Global Settings > Company.
 *
 * The tenant's company record (q_companies) is written once, from the
 * license server's answer, when the license is activated
 * (LicensingController::registerlicenceAction). Until now nothing showed it
 * or let anyone correct it, yet its company_name is what the RingQ Talk card
 * falls back to when a person has no Company of their own (Click2Talk).
 *
 * GET  company/info — any admin (Owner/Manager) reads it.
 * POST company/save — Owner only. Changes the record on this server only;
 *                     the license server's copy is not touched.
 */
class CompanyController extends ControllerBase
{
    private const OWNER_MODE = 7;
    private const MANAGER_MODE = 8;

    /** Editable columns => max length. */
    private const EDITABLE = [
        "company_name"   => 120,
        "company_email"  => 120,
        "phone"          => 40,
        "fax"            => 40,
        "contact_person" => 120,
        "address_1"      => 200,
        "address_2"      => 200,
        "city"           => 80,
        "state"          => 80,
        "country"        => 80,
        "zip"            => 20,
    ];

    private function json($payload)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        return $this->response->setContent(json_encode($payload));
    }

    /**
     * Same lookup as ControllerBase::permissionModeOf, kept here so this file
     * works on a server whose (encoded) ControllerBase predates that helper.
     */
    private function modeOf($session): int
    {
        $extension = (string) ($session["extension"] ?? "");
        $subdomain = (string) ($session["subdomain"] ?? "");
        try {
            $mode = $this->redis->get($extension . "-" . $subdomain . "-permission");
            if ($mode !== null && $mode !== false && $mode !== "") {
                return intval($mode);
            }
            $modes = QCompanyModes::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind"       => ["domain_uuid" => (string) ($session["domain_uuid"] ?? "")],
                "bindTypes"  => [Column::BIND_PARAM_STR],
            ]);
            if ($modes) {
                for ($i = 1; $i <= 6; $i++) {
                    $list = array_map("trim", explode(",", (string) $modes->{"permission" . $i}));
                    if ($extension !== "" && in_array($extension, $list, true)) {
                        return $i + 6;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("company: permission lookup failed: " . $e->getMessage());
        }
        return 10;
    }

    private function company(string $domainUuid)
    {
        return QCompanies::findFirst([
            "conditions" => "domain_uuid = :domain_uuid:",
            "bind"       => ["domain_uuid" => $domainUuid],
            "bindTypes"  => [Column::BIND_PARAM_STR],
        ]);
    }

    private function describe($qCompany, int $mode): array
    {
        $fields = [];
        foreach (array_keys(self::EDITABLE) as $column) {
            $fields[$column] = (string) ($qCompany->{$column} ?? "");
        }
        return [
            "result"  => "success",
            "company" => $fields,
            "license" => [
                "packageName"     => (string) ($qCompany->packageName ?? ""),
                "extensionLength" => (string) ($qCompany->extension_length ?? ""),
                "activatedAt"     => (string) ($qCompany->insert_date ?? ""),
                "updatedAt"       => (string) ($qCompany->update_date ?? ""),
            ],
            "canEdit" => $mode === self::OWNER_MODE,
        ];
    }

    /** GET company/info */
    public function infoAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        $mode = $this->modeOf($session);
        if ($mode !== self::OWNER_MODE && $mode !== self::MANAGER_MODE) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->json(["result" => "error", "message" => "Sorry, you do not have permission to do this. (Code CO4030)"]);
        }
        $qCompany = $this->company((string) $session["domain_uuid"]);
        if (!$qCompany) {
            return $this->json(["result" => "error", "message" => "No company record was found for this account. (Code CO4040)"]);
        }
        return $this->json($this->describe($qCompany, $mode));
    }

    /** POST company/save — JSON body with any of the EDITABLE columns. */
    public function saveAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        if (!$this->request->isPost()) {
            return $this->json(["result" => "error", "message" => "Sorry, this request must be a POST. (Code CO4050)"]);
        }
        if ($this->modeOf($session) !== self::OWNER_MODE) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->json(["result" => "error", "message" => "Only the Owner can change the company details. (Code CO4031)"]);
        }

        $body = json_decode((string) $this->request->getRawBody(), true);
        if (!is_array($body)) {
            return $this->json(["result" => "error", "message" => "Sorry, the request could not be read. (Code CO4001)"]);
        }

        $qCompany = $this->company((string) $session["domain_uuid"]);
        if (!$qCompany) {
            return $this->json(["result" => "error", "message" => "No company record was found for this account. (Code CO4040)"]);
        }

        $changed = [];
        foreach (self::EDITABLE as $column => $max) {
            if (!array_key_exists($column, $body)) {
                continue;
            }
            $value = trim(strip_tags((string) $body[$column]));
            if (mb_strlen($value) > $max) {
                return $this->json(["result" => "error", "message" => "Sorry, " . str_replace("_", " ", $column) . " is too long (max " . $max . "). (Code CO4002)"]);
            }
            if ((string) ($qCompany->{$column} ?? "") !== $value) {
                $qCompany->{$column} = $value;
                $changed[] = $column;
            }
        }
        if (trim((string) $qCompany->company_name) === "") {
            return $this->json(["result" => "error", "message" => "Please enter the company name. (Code CO4003)"]);
        }
        $email = trim((string) $qCompany->company_email);
        if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(["result" => "error", "message" => "Please enter a valid company email. (Code CO4004)"]);
        }

        if ($changed) {
            $qCompany->update_date = date("Y-m-d H:i:s");
            if (!$qCompany->save()) {
                $messages = [];
                foreach ($qCompany->getMessages() as $m) {
                    $messages[] = (string) $m;
                }
                error_log("company/save: " . implode("; ", $messages));
                return $this->json(["result" => "error", "message" => "Sorry, the company details could not be saved. (Code CO5001)"]);
            }
            if (method_exists($this, "audit")) {
                $this->audit("company", "update", ["entity" => implode(",", $changed)]);
            }
        }

        $out = $this->describe($qCompany, self::OWNER_MODE);
        $out["message"] = $changed ? "Company details saved." : "Nothing changed.";
        return $this->json($out);
    }
}
