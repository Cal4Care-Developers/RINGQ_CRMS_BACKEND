<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

require_once __DIR__ . "/../library/CustomRoles.php";

/**
 * Roles — the Owner-defined Custom 1 / Custom 2 roles (Settings > User Roles).
 *
 * GET  roles/custom — Owner: both roles with their names and permissions.
 * POST roles/custom — Owner: save one role. JSON {role, name, permissions[]}.
 * GET  roles/mine   — anyone signed in: their own mode and, for a Custom
 *                     member, the permissions the web app shows them.
 *
 * Who is in each role is still set by dragging people on the User Roles page
 * (accounts/chameleon, actions 9 and 12). See library/CustomRoles.php.
 */
class RolesController extends ControllerBase
{
    private const OWNER_MODE = 7;
    private const NAME_MAX = 40;

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
            error_log("roles: permission lookup failed: " . $e->getMessage());
        }
        return 10;
    }

    private function describe(array $roles): array
    {
        $out = [];
        foreach (CustomRoles::ROLES as $key => $role) {
            $out[$key] = [
                "mode"        => $role["mode"],
                "label"       => $role["label"],
                "name"        => $roles[$key]["name"] ?? $role["label"],
                "permissions" => $roles[$key]["permissions"] ?? [],
                "saved"       => (bool) ($roles[$key]["saved"] ?? false),
            ];
        }
        return $out;
    }

    /** GET / POST roles/custom */
    public function customAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        if ($this->modeOf($session) !== self::OWNER_MODE) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->json(["result" => "error", "message" => "Only the Owner can manage custom roles. (Code RO4031)"]);
        }
        $domainUuid = (string) $session["domain_uuid"];

        if ($this->request->isGet()) {
            $roles = CustomRoles::load($this->db, $this->redis, $domainUuid);
            return $this->json(["result" => "success", "roles" => $this->describe($roles), "keys" => CustomRoles::KEYS]);
        }
        if (!$this->request->isPost()) {
            return $this->json(["result" => "error", "message" => "Sorry, this request must be a GET or POST. (Code RO4050)"]);
        }

        $body = json_decode((string) $this->request->getRawBody(), true);
        if (!is_array($body)) {
            return $this->json(["result" => "error", "message" => "Sorry, the request could not be read. (Code RO4001)"]);
        }
        $roleKey = (string) ($body["role"] ?? "");
        if (!isset(CustomRoles::ROLES[$roleKey])) {
            return $this->json(["result" => "error", "message" => "Sorry, that role does not exist. (Code RO4002)"]);
        }
        $name = trim(strip_tags((string) ($body["name"] ?? "")));
        if ($name === "") {
            $name = CustomRoles::ROLES[$roleKey]["label"];
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return $this->json(["result" => "error", "message" => "Sorry, the role name is too long (max " . self::NAME_MAX . "). (Code RO4003)"]);
        }
        if (!is_array($body["permissions"] ?? null)) {
            return $this->json(["result" => "error", "message" => "Sorry, the permissions could not be read. (Code RO4004)"]);
        }

        try {
            CustomRoles::save($this->db, $this->redis, $domainUuid, $roleKey, $name, $body["permissions"], (string) ($session["user_uuid"] ?? ""));
        } catch (\Throwable $e) {
            error_log("roles/custom save: " . $e->getMessage());
            return $this->json(["result" => "error", "message" => "Sorry, the role could not be saved. (Code RO5001)"]);
        }

        $roles = CustomRoles::load($this->db, $this->redis, $domainUuid);
        return $this->json([
            "result"  => "success",
            "message" => "Role saved.",
            "roles"   => $this->describe($roles),
        ]);
    }

    /** GET roles/mine */
    public function mineAction()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["domain_uuid"])) {
            return $this->json(["result" => "error", "message" => "Unauthorized."]);
        }
        $mode = $this->modeOf($session);
        $roleKey = CustomRoles::roleForMode($mode);
        if ($roleKey === null) {
            return $this->json(["result" => "success", "mode" => $mode, "role" => null]);
        }
        $roles = CustomRoles::load($this->db, $this->redis, (string) $session["domain_uuid"]);
        return $this->json([
            "result"      => "success",
            "mode"        => $mode,
            "role"        => $roleKey,
            "name"        => $roles[$roleKey]["name"] ?? CustomRoles::ROLES[$roleKey]["label"],
            "permissions" => $roles[$roleKey]["permissions"] ?? [],
        ]);
    }
}
