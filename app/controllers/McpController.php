<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

require_once __DIR__ . '/../library/McpGrant.php';
require_once __DIR__ . '/../library/McpTools.php';
require_once __DIR__ . '/../library/McpOAuth.php';
require_once __DIR__ . '/../library/McpCatalog.php';
require_once __DIR__ . '/../library/McpAccount.php';
require_once __DIR__ . '/../library/McpUserSession.php';
require_once __DIR__ . '/../library/McpStaff.php';

/**
 * Support access (MCP).
 *
 * Lets a customer's own administrator hand RingQ support a time-boxed,
 * read-only view of this server, and take it back. The support engineer's
 * assistant speaks MCP to a small node adapter, which forwards every call
 * here. THIS CONTROLLER IS THE ONLY AUTHORITY: the adapter holds no secret and
 * makes no decision, so a bug there cannot widen access.
 *
 * `toolAction` is the one action in this application that answers without a
 * session, and that is the whole risk of the feature. It is therefore the only
 * action registered for the nonregistered role, and everything between the
 * internet and tenant data is McpGrant::authorize():
 *
 *   * the tenant comes from the REQUEST HOST and never from the body, so a
 *     token issued on one tenant is simply absent on another's key space;
 *   * the grant must exist, be unexpired, be read scope, and its stored hash
 *     must match under hash_equals;
 *   * the tenant's access switch and the server's own kill switch must both
 *     be on;
 *   * every refusal answers in ONE shape, so the endpoint cannot be used to
 *     learn which tenants have support access enabled.
 *
 * Every call is written to the customer's own audit log before it answers.
 */
class McpController extends ControllerBase
{
    public function settingsAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->manager();
        if ($session === null) {
            return $this->refuseAdmin();
        }
        $subdomain = (string) $session["subdomain"];

        if ($this->request->isPost()) {
            $body = $this->body();
            $by = $this->actorName($session);
            if ($this->pageMode($session) !== McpGrant::OWNER_MODE) {
                $this->audit("support-access", "settings-change-refused");
                return $this->fail("Only an account owner can change this. RingQ support extensions can view these settings but not change them.");
            }

            if (array_key_exists("serviceEnabled", $body)) {
                $state = McpGrant::setServiceEnabled($this->redis, ((bool) $body["serviceEnabled"]), $by);
                $this->audit("support-access", $state["enabled"] ? "service-enabled" : "service-disabled");
                return $this->ok(["service" => $state] + $this->overview($subdomain));
            }

            if (array_key_exists("supportLevel", $body)) {
                if (!McpGrant::isLevel($body["supportLevel"])) {
                    return $this->fail("Support access must be off, read or readwrite.");
                }
                $result = McpGrant::setSupportLevel($this->redis, $subdomain, (string) $body["supportLevel"], $by);
                $this->audit("support-access", "level-changed", [
                    "metadata" => [
                        "from" => $result["previous"],
                        "to" => $result["settings"]["supportLevel"],
                        "revokedGrants" => $result["revoked"],
                    ],
                ]);
                return $this->ok([
                    "revoked" => $result["revoked"],
                ] + $this->overview($subdomain));
            }

            if (array_key_exists("mcp", $body)) {
                if ($this->pageMode($session) !== McpGrant::OWNER_MODE) {
                    return $this->fail("Only an account owner can change AI & MCP access.");
                }
                if (!$this->capabilities()["customerMcp"]) {
                    return $this->fail("Customer MCP is not available on this server yet.");
                }
                $changes = is_array($body["mcp"]) ? $body["mcp"] : [];
                $state = McpGrant::setCustomerMcp($this->redis, $subdomain, $changes, $by);
                $revoked = 0;
                if (array_key_exists("enabled", $changes) && !$changes["enabled"]) {
                    $revoked = McpOAuth::revokeTenant($this->db, (string) $session["domain_uuid"], "AI access turned off by " . $by);
                }
                $this->audit("ai-mcp", "settings-changed", ["metadata" => ["mcp" => $state["mcp"], "revoked" => $revoked]]);
                return $this->ok(["revoked" => $revoked] + $this->overview($subdomain));
            }

            if (array_key_exists("supportMayApprove", $body)) {
                if ($this->pageMode($session) !== McpGrant::OWNER_MODE) {
                    return $this->fail("Only an account owner can let RingQ approve on their behalf.");
                }
                $state = McpGrant::setSupportMayApprove($this->redis, $subdomain, (bool) $body["supportMayApprove"], $by);
                $this->audit("support-access", $state["supportMayApprove"] ? "delegated-approval-on" : "delegated-approval-off");
                return $this->ok($this->overview($subdomain));
            }

            if (array_key_exists("adminsMayApprove", $body)) {
                if ($this->pageMode($session) !== McpGrant::OWNER_MODE) {
                    return $this->fail("Only an account owner can let admins approve high-risk changes.");
                }
                $state = McpGrant::setAdminsMayApprove($this->redis, $subdomain, (bool) $body["adminsMayApprove"], $by);
                $this->audit("ai-mcp", $state["adminsMayApprove"] ? "admin-approval-on" : "admin-approval-off");
                return $this->ok($this->overview($subdomain));
            }

            if (array_key_exists("enabled", $body)) {
                $result = McpGrant::setEnabled($this->redis, $subdomain, ((bool) $body["enabled"]), $by);
                $this->audit(
                    "support-access",
                    $result["settings"]["enabled"] ? "enabled" : "disabled",
                    ["metadata" => ["revokedGrants" => $result["revoked"]]]
                );
                return $this->ok([
                    "revoked" => $result["revoked"],
                ] + $this->overview($subdomain));
            }

            return $this->fail("Nothing to change.");
        }

        return $this->ok($this->overview($subdomain));
    }

    public function grantsAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->manager();
        if ($session === null) {
            return $this->refuseAdmin();
        }
        $subdomain = (string) $session["subdomain"];

        $revoked = McpGrant::revokeAll($this->redis, $subdomain);
        if ($revoked > 0) {
            $this->audit("support-access", "grants-retired", ["metadata" => ["revokedGrants" => $revoked]]);
        }

        if (!$this->request->isPost()) {
            return $this->ok(["grants" => [], "retired" => true]);
        }
        if ((string) ($this->body()["action"] ?? "") === "revoke") {
            return $this->ok(["removed" => $revoked > 0, "grants" => [], "retired" => true]);
        }
        return $this->response->setContent(json_encode([
            "result" => "error",
            "code" => "grants_retired",
            "message" => "Timed support grants have been replaced. Choose what RingQ support may do in AI & MCP → RingQ support access.",
            "retired" => true,
        ], JSON_UNESCAPED_UNICODE));
    }

    public function activityAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->manager();
        if ($session === null) {
            return $this->refuseAdmin();
        }

        $filters = McpGrant::activityFilters([
            "limit" => $this->request->getQuery("limit"),
            "before" => $this->request->getQuery("before"),
            "actor" => $this->request->getQuery("actor"),
            "tool" => $this->request->getQuery("tool"),
            "result" => $this->request->getQuery("result"),
            "source" => $this->request->getQuery("source"),
        ]);

        $where = ["domain_uuid = :d", "feature IN ('support-mcp', 'support-access', 'ai-mcp')"];
        $bind = ["d" => (string) $session["domain_uuid"]];
        if ($filters["before"] > 0) {
            $where[] = "id < :before";
            $bind["before"] = $filters["before"];
        }
        if ($filters["actor"] !== "") {
            $where[] = "(user_email = :actor OR user_fullname = :actorName)";
            $bind["actor"] = $filters["actor"];
            $bind["actorName"] = $filters["actor"];
        }
        if ($filters["tool"] !== "") {
            $where[] = "action = :tool";
            $bind["tool"] = $filters["tool"];
        }
        if ($filters["result"] !== "") {
            $where[] = "result = :result";
            $bind["result"] = $filters["result"];
        }
        if ($filters["source"] === "" || $filters["source"] === "staff") {
            $where[] = "feature = 'support-mcp'";
        } elseif ($filters["source"] === "customer") {
            $where[] = "feature IN ('support-access', 'ai-mcp')";
        }

        try {
            $found = $this->db->fetchAll(
                "SELECT id, occurred_at, feature, user_fullname, user_email, action, entity, result, ip_address, metadata::text AS metadata
                   FROM audit_logs
                  WHERE " . implode(" AND ", $where) . "
                  ORDER BY id DESC
                  LIMIT " . ($filters["limit"] + 1),
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
        } catch (\Throwable $e) {
            return $this->ok(["activity" => [], "nextCursor" => null, "unavailable" => true]);
        }

        $more = count($found) > $filters["limit"];
        $found = array_slice($found, 0, $filters["limit"]);
        $rows = array_map([McpGrant::class, "activityRow"], $found);
        foreach ($rows as $i => $row) {
            $rows[$i]["undone"] = $row["undo"] !== null && (string) $this->redis->get(McpGrant::undoKey((string) $session["domain_uuid"], (int) $row["id"])) !== "";
        }
        $last = end($rows);
        return $this->ok([
            "activity" => $rows,
            "nextCursor" => $more && $last ? $last["id"] : null,
            "unavailable" => false,
        ]);
    }

    public function undoAction($id = "")
    {
        try {
            return $this->runUndo((string) $id);
        } catch (\Throwable $e) {
            error_log("ai-mcp undo: " . get_class($e) . ": " . $e->getMessage() . " at " . basename($e->getFile()) . ":" . $e->getLine());
            if (isset($this->undoClaim) && $this->undoClaim !== "") {
                $this->redis->del($this->undoClaim);
            }
            return $this->accountError("server", "The undo failed on the server. Nothing was changed.", 500);
        }
    }

    private $undoClaim = "";

    private function runUndo(string $id)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");
        if (!$this->request->isPost()) {
            return $this->accountError("bad_request", "Use POST.", 400);
        }
        $session = $this->ownerSession();
        if ($session === null) {
            return $this->accountError("forbidden", "Only an account owner can undo changes here.", 403);
        }
        $domainUuid = (string) $session["domain_uuid"];
        $vDomain = $this->domainForHost((string) $this->request->getHttpHost());
        if ($vDomain === null || !hash_equals((string) $vDomain->domain_uuid, $domainUuid)) {
            return $this->accountError("forbidden", "Only an account owner can undo changes here.", 403);
        }
        $auditId = ctype_digit((string) $id) ? (int) $id : 0;
        $row = $auditId > 0 ? $this->db->fetchOne(
            "SELECT id, feature, result, metadata::text AS metadata FROM audit_logs WHERE id = :id AND domain_uuid = :d AND feature = 'ai-mcp' AND result = 'success'",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["id" => $auditId, "d" => $domainUuid]
        ) : null;
        $meta = is_array($row) ? json_decode((string) $row["metadata"], true) : null;
        if (!is_array($row) || McpGrant::undoOf($row) === null || !is_array($meta)) {
            return $this->accountError("not_found", "That change cannot be undone from here.", 404);
        }
        $userUuid = (string) ($session["user_uuid"] ?? "");
        $user = $userUuid === "" ? null : McpUserSession::lookupUser($this->db, $domainUuid, $userUuid);
        if ($user === null) {
            return $this->accountError("forbidden", "Only an account owner can undo changes here.", 403);
        }
        $key = McpGrant::undoKey($domainUuid, $auditId);
        if (!$this->redis->set($key, (string) time(), "EX", 90 * 86400, "NX")) {
            return $this->accountError("conflict", "That change has already been undone.", 409);
        }
        $this->undoClaim = $key;
        $settings = McpGrant::settings($this->redis, (string) $vDomain->domain_name);
        $ctx = [
            "vDomain" => $vDomain,
            "actor" => [
                "kind" => "app",
                "ref" => "app:" . $userUuid,
                "domain_uuid" => $domainUuid,
                "user_uuid" => $userUuid,
                "scope" => McpOAuth::SCOPE_WRITE,
                "client" => "RingQ app (undo of #" . $auditId . ")",
            ],
            "user" => $user,
            "tier" => "owner",
            "selfApproved" => true,
            "writesOpen" => true,
            "planAllowsWrites" => true,
            "adminsMayApprove" => (bool) $settings["adminsMayApprove"],
            "host" => $this->bareHost(),
        ];
        $revert = $meta["revert"];
        $response = $this->accountGeneric($ctx, "ringq_api_write", [
            "operationId" => (string) $revert["operationId"],
            "arguments" => (array) $revert["arguments"],
            "idempotencyKey" => "undo-" . $auditId,
        ]);
        $out = json_decode((string) $response->getContent(), true);
        $done = is_array($out) && !empty($out["ok"]) && empty($out["data"]["pendingApproval"]);
        if (!$done) {
            $this->redis->del($key);
        }
        return $response;
    }

    public function accountAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");

        if (!$this->request->isPost()) {
            return $this->accountError("bad_request", "Use POST.", 400);
        }
        $vDomain = $this->domainForHost((string) $this->request->getHttpHost());
        if ($vDomain === null || !McpAccount::schemaReady($this->db, $this->redis)) {
            return $this->accountUnauthorized();
        }
        if ($this->redis->get(McpAccount::ACCOUNT_DISABLED_KEY)) {
            return $this->accountError("unavailable", "AI access is switched off on this server.", 503);
        }
        $actor = $this->accountActor($vDomain);
        if ($actor === null) {
            return $this->accountUnauthorized();
        }
        $subdomain = (string) $vDomain->domain_name;
        $settings = McpGrant::settings($this->redis, $subdomain);
        if (empty($settings["mcp"]["enabled"])) {
            return $this->accountError("mcp_disabled", "An account owner has turned AI access off for this account.", 403);
        }
        $user = McpUserSession::lookupUser($this->db, (string) $vDomain->domain_uuid, (string) $actor["user_uuid"]);
        if ($user === null) {
            return $this->accountUnauthorized();
        }
        $tier = McpAccount::tierForMode($this->permissionModeOf([
            "extension" => (string) $user["extension"],
            "subdomain" => (string) $user["accountcode"],
            "domain_uuid" => (string) $vDomain->domain_uuid,
        ]));
        $planAllowsWrites = McpAccount::planAllowsWrites(McpAccount::planName($this->db, (string) $vDomain->domain_uuid));
        $writesOpen = McpAccount::writesOpen($settings["mcp"], (string) $actor["scope"], (bool) $this->redis->get(McpAccount::WRITES_DISABLED_KEY), $planAllowsWrites);
        $ctx = [
            "vDomain" => $vDomain,
            "actor" => $actor,
            "user" => $user,
            "tier" => $tier,
            "writesOpen" => $writesOpen,
            "planAllowsWrites" => $planAllowsWrites,
            "adminsMayApprove" => (bool) $settings["adminsMayApprove"],
            "host" => $this->bareHost(),
        ];

        $body = $this->body();
        $op = (string) ($body["op"] ?? "call");
        if ($op === "whoami") {
            return $this->accountOk(["identity" => $this->identity($ctx)]);
        }
        if ($op === "list") {
            return $this->accountOk(["tools" => McpAccount::toolsFor($tier, $writesOpen)]);
        }
        if ($op !== "call") {
            return $this->accountError("bad_request", "Unknown operation.", 400);
        }

        $name = (string) ($body["name"] ?? "");
        $arguments = is_array($body["arguments"] ?? null) ? $body["arguments"] : [];
        if ($actor["kind"] === "session") {
            McpOAuth::touchSession($this->db, (string) $actor["ref"]);
        }

        if (in_array($name, McpAccount::GENERIC, true)) {
            return $this->accountGeneric($ctx, $name, $arguments);
        }
        if (!McpTools::exists($name) || !McpAccount::curatedAllowed($name, $tier)) {
            $this->auditAccount($ctx, $name, "", "refused", ["arguments" => $arguments]);
            return $this->accountError("not_permitted", 'Your RingQ role cannot use "' . $name . '", or it does not exist.', 403);
        }
        if (($rateRefusal = McpAccount::rateRefusal($this->redis, (string) $ctx["vDomain"]->domain_uuid, (string) $actor["ref"], "read")) !== null) {
            return $this->accountError("rate_limited", $rateRefusal, 429);
        }
        $args = McpAccount::pin($name, $arguments, $tier, (string) $user["extension"]);
        try {
            $data = McpTools::run($this->getDI(), $name, $args, [
                "domainUuid" => (string) $vDomain->domain_uuid,
                "subdomain" => $subdomain,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->auditAccount($ctx, $name, "", "error", ["arguments" => $args]);
            return $this->accountError("bad_request", $e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log("ai-mcp tool " . $name . ": " . $e->getMessage());
            $this->auditAccount($ctx, $name, "", "error", ["arguments" => $args]);
            return $this->accountError("tool_failed", "That tool could not finish.", 500);
        }
        $this->auditAccount($ctx, $name, "", "success", ["arguments" => $args]);
        return $this->accountOk(["data" => $data]);
    }

    public function sessionsAction($sub = "")
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->signedIn();
        if ($session === null) {
            return $this->refuseAdmin();
        }
        $domain = (string) $session["domain_uuid"];
        $all = $this->seesWholeTenant($session);
        if ($this->request->isPost() && $sub === "revoke") {
            $target = McpOAuth::findSession($this->db, $domain, (string) ($this->body()["id"] ?? ""));
            if ($target === null || (!$all && (string) $target["user_uuid"] !== (string) $session["user_uuid"])) {
                return $this->fail("That connection was not found.");
            }
            McpOAuth::revokeSession($this->db, (string) $target["session_id"], "revoked by " . $this->actorName($session));
            McpUserSession::forget($this->redis, "session:" . (string) $target["session_id"]);
            $this->audit("ai-mcp", "app-revoked", ["entity" => (string) $target["client_name"]]);
            return $this->ok(["sessions" => McpOAuth::listSessions($this->db, $domain, $all ? null : (string) $session["user_uuid"])]);
        }
        return $this->ok(["sessions" => McpOAuth::listSessions($this->db, $domain, $all ? null : (string) $session["user_uuid"])]);
    }

    public function keysAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");
        $session = $this->ownerSession();
        if ($session === null) {
            return $this->refuseOwner();
        }
        $domain = (string) $session["domain_uuid"];
        if ($this->request->isPost()) {
            $body = $this->body();
            $action = (string) ($body["action"] ?? "");
            if ($action === "create") {
                $name = trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string) ($body["name"] ?? "")));
                if ($name === "" || mb_strlen($name) > 60) {
                    return $this->fail("Give the key a name of up to 60 characters.");
                }
                $scopes = is_array($body["scopes"] ?? null) ? $body["scopes"] : [];
                $ips = [];
                $rawIps = array_key_exists("allowedIps", $body) ? $body["allowedIps"] : ($body["ipAllowlist"] ?? []);
                foreach ((array) $rawIps as $ip) {
                    $ip = McpAccount::cleanIpRange((string) $ip);
                    if ($ip === null) {
                        return $this->fail("Not an IP address or range.");
                    }
                    if ($ip !== "") {
                        $ips[] = $ip;
                    }
                }
                $expiresAt = McpAccount::cleanExpiry($body["expiresAt"] ?? null);
                if ($expiresAt === false) {
                    return $this->fail("Choose a valid expiry date, or leave it empty.");
                }
                if ($expiresAt !== null && $expiresAt <= time()) {
                    return $this->fail("Choose an expiry date in the future, or leave it empty.");
                }
                $created = McpOAuth::createAgentKey($this->db, $domain, (string) $session["user_uuid"], $name,
                    in_array("write", $scopes, true) ? McpOAuth::SCOPE_WRITE : McpOAuth::SCOPE_READ, array_slice($ips, 0, 20), $expiresAt);
                $this->audit("ai-mcp", "key-created", ["entity" => $name]);
                return $this->ok(["token" => $created["token"], "keys" => McpOAuth::listAgentKeys($this->db, $domain)]);
            }
            if ($action === "revoke") {
                $id = (string) ($body["id"] ?? "");
                if (!McpOAuth::revokeAgentKey($this->db, $domain, $id)) {
                    return $this->fail("That key was not found.");
                }
                McpUserSession::forget($this->redis, "key:" . $id);
                $this->audit("ai-mcp", "key-revoked", ["entity" => $id]);
                return $this->ok(["keys" => McpOAuth::listAgentKeys($this->db, $domain)]);
            }
            return $this->fail("Nothing to change.");
        }
        return $this->ok(["keys" => McpOAuth::listAgentKeys($this->db, $domain)]);
    }

    public function approvalsAction($id = "", $decision = "")
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->signedIn();
        if ($session === null) {
            return $this->refuseOwner();
        }
        $tier = $this->approvalTier($session);
        $adminsMayApprove = (bool) McpGrant::settings($this->redis, (string) $session["subdomain"])["adminsMayApprove"];
        if (!McpAccount::canApprove($tier, $adminsMayApprove)) {
            return $this->refuseOwner();
        }
        $domain = (string) $session["domain_uuid"];
        $viewer = ["tier" => $tier, "user_uuid" => (string) $session["user_uuid"], "adminsMayApprove" => $adminsMayApprove];
        if ($this->request->isPost()) {
            $request = McpAccount::approvalRequest($this->db, $domain, (string) $id);
            $serverWide = $request !== null && McpAccount::serverWideOnShared(McpCatalog::load()[(string) ($request["operation_id"] ?? "")] ?? null, (string) $session["subdomain"]);
            $refusal = McpAccount::mayDecide($tier, (string) $session["user_uuid"], $adminsMayApprove, $request, $serverWide);
            if ($refusal !== null) {
                return $this->fail($refusal);
            }
            if ($this->supportApprover($session) && hash_equals((string) ($request["user_uuid"] ?? ""), (string) $session["user_uuid"])) {
                return $this->fail("You cannot approve your own request. Ask an account owner or another RingQ Admin.");
            }
            if (!McpAccount::decideApproval($this->db, $domain, (string) $id, (string) $decision, $this->actorName($session))) {
                return $this->fail("That request was not found, has expired, or was already decided.");
            }
            $this->audit("ai-mcp", $decision === "approve" ? "approval-approved" : "approval-denied", ["entity" => (string) $id]);
        }
        return $this->ok(["approvals" => McpAccount::pendingApprovals($this->db, $domain, $viewer)]);
    }

    public function meAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");
        $session = $this->signedIn();
        if ($session === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->fail("Sign in to RingQ again.");
        }
        $tier = McpAccount::tierForMode($this->pageMode($session));
        $settings = McpGrant::settings($this->redis, (string) $session["subdomain"]);
        $approvalTier = $this->approvalTier($session);
        $planAllowsWrites = McpAccount::planAllowsWrites(McpAccount::planName($this->db, (string) $session["domain_uuid"]));
        $killed = (bool) $this->redis->get(McpAccount::WRITES_DISABLED_KEY);
        $adminsMayApprove = (bool) $settings["adminsMayApprove"];
        return $this->ok([
            "tier" => $tier,
            "tierLabel" => McpAccount::tierLabel($tier),
            "mcpEnabled" => (bool) $settings["mcp"]["enabled"],
            "writesOpen" => McpAccount::writesOpen($settings["mcp"], McpOAuth::SCOPE_WRITE, $killed, $planAllowsWrites),
            "planAllowsWrites" => $planAllowsWrites,
            "scopeOffered" => McpAccount::scopeOffered($settings["mcp"], $planAllowsWrites),
            "serverUrl" => "https://" . $this->bareHost() . "/mcp/account",
            "adminsMayApprove" => $adminsMayApprove,
            "canApprove" => McpAccount::canApprove($approvalTier, $adminsMayApprove),
        ]);
    }

    public function staffAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");

        if (!$this->request->isPost()) {
            return $this->refuseStaff();
        }
        $host = $this->bareHost();
        $now = time();
        $body = McpStaff::verify($this->redis, McpStaff::keysFrom(McpStaff::keyFiles(), $now), [
            "key" => (string) $this->request->getHeader("X-Ringq-Staff-Key"),
            "timestamp" => (string) $this->request->getHeader("X-Ringq-Staff-Timestamp"),
            "nonce" => (string) $this->request->getHeader("X-Ringq-Staff-Nonce"),
            "signature" => (string) $this->request->getHeader("X-Ringq-Staff-Signature"),
        ], "POST", $host, McpStaff::PATH, (string) $this->request->getRawBody(), $now);
        if ($body === null) {
            return $this->refuseStaff();
        }
        $vDomain = $this->domainForHost($host);
        if ($vDomain === null) {
            return $this->refuseStaff();
        }
        if (McpGrant::serviceDisabled($this->redis)) {
            return $this->accountError("unavailable", "RingQ support access is switched off on this server.", 503);
        }
        McpAccount::schemaReady($this->db, $this->redis);
        $staff = McpStaff::claims($body);
        $subdomain = (string) $vDomain->domain_name;
        $domainUuid = (string) $vDomain->domain_uuid;
        $settings = McpGrant::settings($this->redis, $subdomain);
        $level = (string) $settings["supportLevel"];
        $killed = (bool) $this->redis->get(McpStaff::WRITES_DISABLED_KEY);
        $op = (string) $body["op"];

        if ($op === "describe") {
            return $this->accountOk(["data" => [
                "tenant" => $subdomain,
                "supportLevel" => $level,
                "supportMayApprove" => (bool) $settings["supportMayApprove"],
                "version" => (string) $this->redis->get("serverVersion"),
                "writesKilled" => $killed,
            ]]);
        }
        if ($level === McpGrant::LEVEL_OFF) {
            $this->auditStaff($vDomain, $staff, $op === "call" ? (string) ($body["name"] ?? "") : $op, "", "refused", ["reason" => "support access off"]);
            return $this->accountError("support_off", "This customer has RingQ support access turned off.", 403);
        }
        if ($op === "health") {
            $parts = [];
            foreach (McpStaff::HEALTH_TOOLS as $tool) {
                try {
                    $parts[$tool] = McpTools::run($this->getDI(), $tool, [], ["domainUuid" => $domainUuid, "subdomain" => $subdomain]);
                } catch (\Throwable $e) {
                    $parts[$tool] = null;
                }
            }
            $this->auditStaff($vDomain, $staff, "fleet-health", "", "success", []);
            return $this->accountOk(["data" => McpStaff::healthSummary($parts)]);
        }
        if ($op === "list") {
            return $this->accountOk(["tools" => McpStaff::toolsFor($level, $staff["role"], $killed)]);
        }
        if ($op === "lookup") {
            $lookup = McpStaff::lookupRequest($body);
            $refusal = McpStaff::permits($level, $staff["role"], "read", $killed);
            if ($lookup === null || $refusal !== null) {
                $this->auditStaff($vDomain, $staff, "instance-search", "", $lookup === null ? "error" : "refused", McpStaff::lookupAudit($lookup, []));
                return $lookup === null
                    ? $this->accountError("bad_request", "Search for a phone number or an email address.", 400)
                    : $this->accountError("not_permitted", $refusal, 403);
            }
            if (($rateRefusal = McpAccount::rateRefusal($this->redis, "staff:" . $domainUuid, McpStaff::actorRef($staff, $domainUuid), "read")) !== null) {
                return $this->accountError("rate_limited", $rateRefusal, 429);
            }
            $matches = McpStaff::lookup($this->db, $domainUuid, $lookup);
            $this->auditStaff($vDomain, $staff, "instance-search", "", $matches === null ? "error" : "success", McpStaff::lookupAudit($lookup, (array) $matches));
            if ($matches === null) {
                return $this->accountError("tool_failed", "That search could not finish.", 500);
            }
            return $this->accountOk(["data" => ["kind" => $lookup["kind"], "matched" => $matches !== [], "matches" => $matches]]);
        }
        if ($op === "approvals") {
            return $this->accountOk(["data" => [
                "approvals" => McpAccount::pendingApprovals($this->db, $domainUuid),
                "supportMayApprove" => (bool) $settings["supportMayApprove"],
            ]]);
        }
        if ($op === "decide") {
            $approvalId = (string) ($body["approvalId"] ?? "");
            $decision = (string) ($body["decision"] ?? "");
            $requester = McpStaff::approvalRequester($this->db, $domainUuid, $approvalId);
            $serverWide = $requester !== null && McpAccount::serverWideOnShared(McpCatalog::load()[(string) ($requester["operation_id"] ?? "")] ?? null, (string) $vDomain->domain_name);
            $refusal = McpStaff::mayDecide($settings, $staff, $domainUuid, $requester, $serverWide);
            if ($refusal !== null) {
                $this->auditStaff($vDomain, $staff, "approval-" . ($decision === "approve" ? "approve" : "deny"), "", "refused", ["approvalId" => $approvalId]);
                return $this->accountError("not_permitted", $refusal, 403);
            }
            if (!McpAccount::decideApproval($this->db, $domainUuid, $approvalId, $decision, McpStaff::label($staff))) {
                return $this->accountError("not_found", "That request was not found, has expired, or was already decided.", 404);
            }
            $this->auditStaff($vDomain, $staff, $decision === "approve" ? "approval-approved" : "approval-denied", "", "success", ["approvalId" => $approvalId]);
            return $this->accountOk(["data" => ["approvalId" => $approvalId, "decision" => $decision]]);
        }

        $name = (string) ($body["name"] ?? "");
        $arguments = is_array($body["arguments"] ?? null) ? $body["arguments"] : [];
        $actorRef = McpStaff::actorRef($staff, $domainUuid);
        if (in_array($name, McpAccount::GENERIC, true) && $name !== "get_account_info") {
            $owner = McpStaff::ownerUser($this->db, $domainUuid);
            if ($owner === null) {
                return $this->accountError("no_owner", "This account has no active owner user, so RingQ support cannot act through the app.", 409);
            }
            $scope = McpStaff::catalogScope($level, $staff["role"], $killed);
            return $this->accountGeneric([
                "vDomain" => $vDomain,
                "actor" => [
                    "kind" => "staff",
                    "ref" => $actorRef,
                    "domain_uuid" => $domainUuid,
                    "user_uuid" => (string) $owner["user_uuid"],
                    "scope" => $scope,
                    "client" => $staff["client"],
                ],
                "user" => $owner,
                "tier" => "owner",
                "writesOpen" => $scope === McpOAuth::SCOPE_WRITE,
                "host" => $host,
                "staff" => $staff,
                "level" => $level,
                "killed" => $killed,
                "supportMayApprove" => (bool) $settings["supportMayApprove"],
            ], $name, $arguments);
        }
        if (!McpTools::exists($name)) {
            return $this->accountError("unknown_tool", "This server does not offer a tool called \"" . mb_substr($name, 0, 60) . "\".", 400);
        }
        $refusal = McpStaff::permits($level, $staff["role"], "read", $killed);
        if ($refusal !== null) {
            $this->auditStaff($vDomain, $staff, $name, "", "refused", ["arguments" => $arguments]);
            return $this->accountError("not_permitted", $refusal, 403);
        }
        if (($rateRefusal = McpAccount::rateRefusal($this->redis, "staff:" . $domainUuid, $actorRef, "read")) !== null) {
            return $this->accountError("rate_limited", $rateRefusal, 429);
        }
        try {
            $data = McpTools::run($this->getDI(), $name, $arguments, [
                "domainUuid" => $domainUuid,
                "subdomain" => $subdomain,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->auditStaff($vDomain, $staff, $name, "", "error", ["arguments" => $arguments]);
            return $this->accountError("bad_request", $e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log("ops-mcp tool " . $name . ": " . $e->getMessage());
            $this->auditStaff($vDomain, $staff, $name, "", "error", ["arguments" => $arguments]);
            return $this->accountError("tool_failed", "That tool could not finish.", 500);
        }
        $this->auditStaff($vDomain, $staff, $name, "", "success", ["arguments" => $arguments]);
        return $this->accountOk(["data" => $data]);
    }

    /**
     * The support endpoint. Bearer only, no session, no cookies.
     *
     * Body: {"op":"list"} or {"op":"call","name":"...","arguments":{...}}.
     * One endpoint means one authorization site.
     */
    public function toolAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");

        if (!$this->request->isPost()) {
            return $this->refuseTool();
        }

        $host = (string) $this->request->getHttpHost();
        $vDomain = $this->domainForHost($host);
        if ($vDomain === null) {
            return $this->refuseTool();
        }
        $subdomain = (string) $vDomain->domain_name;

        $token = McpGrant::bearerFrom($this->request->getHeader("Authorization"));
        $grant = McpGrant::RETIRED ? null : McpGrant::authorize($this->redis, $subdomain, $token);
        if ($grant === null) {
            return $this->refuseTool();
        }

        if (McpGrant::rateLimited($this->redis, $subdomain, (string) $grant["id"])) {
            return $this->toolError("rate_limited", "Too many requests. Wait a minute and try again.", 429);
        }

        $body = $this->body();
        $op = (string) ($body["op"] ?? "call");

        McpGrant::touch($this->redis, $subdomain, $grant);

        if ($op === "list") {
            return $this->response->setContent(json_encode([
                "ok" => true,
                "tools" => McpTools::describe(),
                "tenant" => $subdomain,
                "scope" => (string) $grant["scope"],
                "expiresAt" => (int) $grant["expiresAt"],
            ]));
        }

        if ($op !== "call") {
            return $this->toolError("bad_request", "Unknown operation.", 400);
        }

        $name = (string) ($body["name"] ?? "");
        $arguments = is_array($body["arguments"] ?? null) ? $body["arguments"] : [];

        // A change this grant does not permit is refused before anything runs,
        // and is audited as a refusal so the customer sees the attempt. It sits
        // below $arguments so the audit records what was asked for -- above it,
        // auditTool() gets an undefined variable and the request fatals.
        //
        // Ahead of the unknown-tool check on purpose: "this grant does not allow
        // it" is the true answer, and "no such tool" would be misleading.
        $supportLevel = McpGrant::settings($this->redis, $subdomain)["supportLevel"];
        if (McpGrant::isFixAction($name) && !McpGrant::permits($grant, $name, $supportLevel)) {
            $this->auditTool($vDomain, $grant, $name, $arguments, "refused");
            return $this->toolError(
                "not_permitted",
                $supportLevel === McpGrant::LEVEL_READWRITE
                    ? 'This grant does not allow "' . $name . '". The customer can allow it from AI & MCP.'
                    : 'Support access on this account is read only. The customer can allow changes from AI & MCP.',
                403
            );
        }

        if (!McpTools::exists($name)) {
            return $this->toolError("unknown_tool", "This server does not offer a tool called \"" . mb_substr($name, 0, 60) . "\".", 400);
        }

        $ctx = [
            "domainUuid" => (string) $vDomain->domain_uuid,
            "subdomain" => $subdomain,
        ];

        try {
            $data = McpTools::run($this->getDI(), $name, $arguments, $ctx);
        } catch (\InvalidArgumentException $e) {
            $this->auditTool($vDomain, $grant, $name, $arguments, "error");
            return $this->toolError("bad_arguments", $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->auditTool($vDomain, $grant, $name, $arguments, "error");
            return $this->toolError("tool_failed", ControllerBase::safeErrorMessage($e), 500);
        }

        $this->auditTool($vDomain, $grant, $name, $arguments, "success");

        return $this->response->setContent(json_encode([
            "ok" => true,
            "tool" => $name,
            "tenant" => $subdomain,
            "data" => $data,
        ]));
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    private function bareHost(): string
    {
        return strtolower(trim(explode(":", (string) $this->request->getHttpHost())[0]));
    }

    private function capabilities(): array
    {
        return McpAccount::capabilities(McpAccount::schemaReady($this->db, $this->redis));
    }

    private function signedIn()
    {
        $session = $this->session->get("auth");
        if (!$session || ($session["access"] ?? "") !== "registered") {
            return null;
        }
        return $session;
    }

    private function seesWholeTenant($session): bool
    {
        return McpGrant::canManage($this->permissionModeOf($session), (string) ($session["extension"] ?? ""));
    }

    private function pageMode($session): int
    {
        $mode = $this->permissionModeOf($session);
        return McpGrant::isSupportExtension((string) ($session["extension"] ?? "")) ? McpGrant::OWNER_MODE : $mode;
    }

    private function supportApprover($session): bool
    {
        return McpGrant::supportMayDecide($this->redis, (string) ($session["extension"] ?? ""));
    }

    private function approvalTier($session): string
    {
        if (McpGrant::isSupportExtension((string) ($session["extension"] ?? ""))) {
            return $this->supportApprover($session) ? "owner" : "admin";
        }
        return McpAccount::tierForMode($this->pageMode($session));
    }

    private function ownerSession()
    {
        $session = $this->signedIn();
        if ($session === null || $this->pageMode($session) !== McpGrant::OWNER_MODE) {
            return null;
        }
        return $session;
    }

    private function refuseOwner()
    {
        $this->response->setStatusCode(403, "Forbidden");
        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "Only an account owner can manage this.",
        ]));
    }

    private function accountActor($vDomain): ?array
    {
        $token = McpGrant::bearerFrom($this->request->getHeader("Authorization"));
        $domain = (string) $vDomain->domain_uuid;
        if (strpos($token, McpOAuth::ACCESS_PREFIX) === 0) {
            $row = McpOAuth::sessionForAccess($this->db, $this->redis, $token);
            if ($row === null || !hash_equals((string) $row["domain_uuid"], $domain)) {
                return null;
            }
            return [
                "kind" => "session",
                "ref" => (string) $row["session_id"],
                "domain_uuid" => $domain,
                "user_uuid" => (string) $row["user_uuid"],
                "scope" => (string) $row["scopes"],
                "client" => (string) $row["client_name"],
            ];
        }
        if (strpos($token, McpOAuth::KEY_PREFIX) === 0) {
            $row = McpOAuth::agentKeyFor($this->db, $token, $this->callerAddress());
            if ($row === null || !hash_equals((string) $row["domain_uuid"], $domain)) {
                return null;
            }
            return [
                "kind" => "key",
                "ref" => (string) $row["key_id"],
                "domain_uuid" => $domain,
                "user_uuid" => (string) $row["user_uuid"],
                "scope" => (string) $row["scopes"],
                "client" => "Agent key: " . (string) $row["name"],
            ];
        }
        return null;
    }

    private function identity(array $ctx): array
    {
        $u = $ctx["user"];
        return [
            "name" => trim(((string) ($u["first_name"] ?? "")) . " " . ((string) ($u["last_name"] ?? ""))),
            "email" => (string) $u["user_email"],
            "extension" => (string) $u["extension"],
            "account" => (string) $ctx["vDomain"]->domain_name,
            "role" => McpAccount::tierLabel((string) $ctx["tier"]),
            "connection" => (string) $ctx["actor"]["client"],
            "access" => (string) $ctx["actor"]["scope"] === McpOAuth::SCOPE_WRITE ? "read & write" : "read only",
            "changesAllowed" => (bool) $ctx["writesOpen"],
            "planAllowsWrites" => (bool) ($ctx["planAllowsWrites"] ?? true),
        ];
    }

    private function actorLabel(array $ctx): string
    {
        if (isset($ctx["staff"])) {
            return McpStaff::label($ctx["staff"]);
        }
        $u = $ctx["user"];
        $who = (string) $u["user_email"];
        return $ctx["actor"]["kind"] === "key" ? (string) $ctx["actor"]["client"] . " (" . $who . ")" : $who;
    }

    private function accountGeneric(array $ctx, string $name, array $arguments)
    {
        $tier = (string) $ctx["tier"];
        $scope = (string) $ctx["actor"]["scope"];
        $all = McpCatalog::load();
        $visible = McpCatalog::visible($all, $tier, $ctx["writesOpen"] ? McpOAuth::SCOPE_WRITE : McpOAuth::SCOPE_READ);
        if (!isset($ctx["staff"])) {
            $visible = McpAccount::customerVisible($visible, $tier, (bool) $ctx["adminsMayApprove"]);
        }

        if ($name === "get_account_info") {
            $this->auditAccount($ctx, $name, "", "success", []);
            return $this->accountOk(["data" => $this->identity($ctx)]);
        }
        if ($name === "ringq_api_search") {
            $results = McpCatalog::search($visible, (string) ($arguments["query"] ?? ""));
            return $this->accountOk(["data" => ["operations" => $results, "count" => count($results)]]);
        }
        $operationId = (string) ($arguments["operationId"] ?? "");
        $op = $all[$operationId] ?? null;
        if ($op === null || !McpCatalog::allowedFor($op, $tier)) {
            $this->auditAccount($ctx, $name, $operationId, "refused", []);
            return $this->accountError("not_found", "No operation " . mb_substr($operationId, 0, 80) . " is available to you. Use ringq_api_search.", 404);
        }
        if ($name === "ringq_api_details") {
            return $this->accountOk(["data" => McpCatalog::details($op)]);
        }
        $isRead = $op["access"] === "read";
        if ($name === "ringq_api_read" && !$isRead) {
            return $this->accountError("bad_request", "That operation changes the account. Use ringq_api_write.", 400);
        }
        if ($name === "ringq_api_write" && $isRead) {
            return $this->accountError("bad_request", "That operation only reads. Use ringq_api_read.", 400);
        }
        if (!isset($ctx["staff"])) {
            $customer = McpAccount::customerRefusal($op, $tier, (bool) $ctx["adminsMayApprove"], (bool) $ctx["planAllowsWrites"]);
            if ($customer !== null) {
                $this->auditAccount($ctx, $name, $operationId, "refused", ["reason" => $customer[0]]);
                return $this->accountError($customer[0], $customer[1], 403);
            }
        }
        $refusal = isset($ctx["staff"])
            ? McpStaff::permits((string) $ctx["level"], (string) $ctx["staff"]["role"], (string) $op["access"], (bool) $ctx["killed"])
            : null;
        if ($refusal === null) {
            $refusal = McpAccount::permits($op, $tier, $scope, (bool) $ctx["writesOpen"]);
        }
        if ($refusal === null) {
            $refusal = McpAccount::instanceRefusal($op, (string) $ctx["vDomain"]->domain_name, isset($ctx["staff"]));
        }
        if ($refusal !== null) {
            $this->auditAccount($ctx, $name, $operationId, "refused", []);
            return $this->accountError("not_permitted", $refusal, 403);
        }
        if (($rateRefusal = McpAccount::rateRefusal($this->redis, (string) $ctx["vDomain"]->domain_uuid, (string) $ctx["actor"]["ref"], (string) $op["access"])) !== null) {
            return $this->accountError("rate_limited", $rateRefusal, 429);
        }
        $checked = McpCatalog::validate($op, $arguments["arguments"] ?? []);
        if (isset($checked["error"])) {
            return $this->accountError("bad_arguments", $checked["error"], 400);
        }
        $args = $checked["args"];
        $summary = McpCatalog::summaryFor($op, $args);

        if ($isRead) {
            return $this->accountRun($ctx, $name, $op, $args, $summary, []);
        }

        $dryRun = !empty($arguments["dryRun"]);
        $approvalId = (string) ($arguments["approvalId"] ?? "");
        $selfApproved = !isset($ctx["staff"]) && !empty($ctx["selfApproved"]) && (string) $ctx["actor"]["kind"] === "app" && $tier === "owner";
        if ($dryRun || ($op["access"] === "dangerous" && $approvalId === "" && !$selfApproved)) {
            $preview = $this->preview($ctx, $op, $args);
            if (isset($preview["error"])) {
                return $this->accountError("preview_failed", $preview["error"], 409);
            }
            if (McpCatalog::isMerge($op)) {
                $merged = McpCatalog::mergeCurrent($op, $args, $preview["body"] ?? null);
                if (isset($merged["error"])) {
                    return $this->accountError("preview_failed", $merged["error"], 409);
                }
                $preview["changes"] = McpCatalog::previewChanges($op, $merged["args"], $preview["body"] ?? null)["changes"];
            }
            if ($dryRun) {
                $this->auditAccount($ctx, $name, $operationId, "success", ["dryRun" => true, "arguments" => $args]);
                return $this->accountOk(["data" => [
                    "dryRun" => true,
                    "summary" => $summary,
                    "changes" => $preview["changes"],
                    "previewAvailable" => $preview["available"],
                    "needsApproval" => $op["access"] === "dangerous",
                ]]);
            }
            $id = McpAccount::createApproval($this->db, $ctx["actor"] + ["label" => $this->actorLabel($ctx)], $op, $args, $summary, $preview["changes"]);
            $this->auditAccount($ctx, $name, $operationId, "pending", ["approvalId" => $id, "arguments" => $args]);
            return $this->accountOk(["data" => [
                "pendingApproval" => true,
                "approvalId" => $id,
                "summary" => $summary,
                "message" => isset($ctx["staff"]) && McpAccount::serverWideOnShared($op, (string) $ctx["vDomain"]->domain_name)
                    ? "This change affects every account on this shared server, so another RingQ Admin must approve it from the staff console (account owners cannot). Once approved, call ringq_api_write again with the same arguments and this approvalId. The request expires in 24 hours."
                    : (isset($ctx["staff"])
                    ? "This change needs approval. The customer's account owner can approve it in RingQ: Global Settings, Advanced, AI & MCP, Approvals." . (!empty($ctx["supportMayApprove"]) ? " This customer also lets another RingQ Admin approve it from the staff console." : "") . " Once approved, call ringq_api_write again with the same arguments and this approvalId. The request expires in 24 hours."
                    : "This change needs an account owner's approval" . (!empty($ctx["adminsMayApprove"]) ? " (or another admin's)" : "") . ". Ask them to open RingQ: Global Settings, Advanced, AI & MCP, Approvals. Once approved, call ringq_api_write again with the same arguments and this approvalId. The request expires in 24 hours."),
            ]]);
        }
        $runArgs = $args;
        $record = null;
        $previewKind = (string) ($op["preview"]["kind"] ?? "");
        if ($previewKind !== "" && ($previewKind !== "create" || !empty($op["preview"]["checks"]))) {
            $preview = $this->preview($ctx, $op, $args);
            if (isset($preview["error"])) {
                $this->auditAccount($ctx, $name, $operationId, "refused", ["arguments" => $args, "reason" => $preview["error"]]);
                return $this->accountError("preview_failed", $preview["error"], 409);
            }
            $changes = $preview["changes"];
            if (McpCatalog::isMerge($op)) {
                $merged = McpCatalog::mergeCurrent($op, $args, $preview["body"] ?? null);
                if (isset($merged["error"])) {
                    return $this->accountError("preview_failed", $merged["error"], 409);
                }
                $runArgs = $merged["args"];
                $changes = McpCatalog::previewChanges($op, $runArgs, $preview["body"] ?? null)["changes"];
            }
            if (!empty($preview["available"])) {
                $record = $this->revertRecord($op, $runArgs, $changes);
            }
        }
        if ($op["access"] === "dangerous" && !$selfApproved) {
            $refused = McpAccount::consumeApproval($this->db, $approvalId, $ctx["actor"], $operationId, $args);
            if ($refused !== null) {
                $this->auditAccount($ctx, $name, $operationId, "refused", ["approvalId" => $approvalId]);
                return $this->accountError("approval_required", $refused, 403);
            }
        }
        $args = $runArgs;
        $idemKey = "";
        $rawKey = trim((string) ($arguments["idempotencyKey"] ?? ""));
        if ($rawKey !== "") {
            $idemKey = McpAccount::idemKey((string) $ctx["vDomain"]->domain_uuid, (string) $ctx["actor"]["ref"], $rawKey);
            $seen = McpAccount::idemBegin($this->redis, $idemKey);
            if ($seen !== null) {
                if (!empty($seen["pending"])) {
                    return $this->accountError("in_progress", "A request with this idempotencyKey is still running.", 409);
                }
                return $this->accountOk(["data" => ["replayed" => true] + (array) $seen["result"]]);
            }
        }
        return $this->accountRun($ctx, $name, $op, $args, $summary, ["approvalId" => $approvalId, "idemKey" => $idemKey, "record" => $record]);
    }

    private function revertRecord(array $op, array $args, array $changes): array
    {
        $record = McpCatalog::revertRecord($op, $args, $changes);
        foreach ($record["changes"] as $i => $change) {
            $probe = [(string) ($change["field"] ?? "") => 1];
            if (AuditMap::redact($probe) !== $probe) {
                $record["changes"][$i]["before"] = "[REDACTED]";
                $record["changes"][$i]["after"] = "[REDACTED]";
            }
        }
        if ($record["revert"] !== null && AuditMap::redact($record["revert"]["arguments"]) !== $record["revert"]["arguments"]) {
            $record["revert"] = null;
            $record["reason"] = "This change includes a secret, which is never stored, so it has to be undone by hand.";
        }
        return $record;
    }

    private function preview(array $ctx, array $op, array $args): array
    {
        $preview = (array) ($op["preview"] ?? []);
        if ($preview === []) {
            return ["available" => false, "changes" => []];
        }
        $body = null;
        if (($preview["kind"] ?? "") !== "create" || (string) ($preview["operation"] ?? "") !== "") {
            $readOp = McpCatalog::load()[(string) ($preview["operation"] ?? "")] ?? null;
            if ($readOp === null || $readOp["access"] !== "read") {
                return ["available" => false, "changes" => []];
            }
            $result = $this->loopback($ctx, McpCatalog::buildRequest($readOp, McpCatalog::previewArgs($op, $args)), "preview");
            if (!$result["ok"]) {
                return ["error" => $result["busy"] ? "RingQ is busy. Try again in a moment." : "The current values could not be read, so nothing was changed."];
            }
            $body = $result["body"];
        }
        $diff = McpCatalog::previewChanges($op, $args, $body);
        if (!$diff["found"]) {
            return ["error" => (string) ($diff["error"] ?? "Nothing matching those arguments exists, so there is nothing to change.")];
        }
        return ["available" => true, "changes" => $diff["changes"], "body" => $body];
    }

    private function loopback(array $ctx, array $request, string $callId): array
    {
        $actorRef = $ctx["actor"]["kind"] . ":" . $ctx["actor"]["ref"];
        $auth = McpUserSession::authFor($ctx["user"], (string) $this->config->application->publicUrl, (string) $ctx["actor"]["user_uuid"]);
        if ($ctx["actor"]["kind"] === "staff") {
            $auth["mcp_staff"] = true;
        }
        $sid = McpUserSession::sessionFor($this->redis, $actorRef, $auth);
        return McpUserSession::call($this->redis, (string) $ctx["host"], $sid, $callId, $request);
    }

    private function accountRun(array $ctx, string $name, array $op, array $args, string $summary, array $extra)
    {
        $callId = bin2hex(random_bytes(8));
        $this->redis->setex("mcp_call:" . $callId, 60, (string) $op["id"]);
        $result = $this->loopback($ctx, McpCatalog::buildRequest($op, $args), $callId);
        $this->redis->del("mcp_call:" . $callId);
        $idemKey = (string) ($extra["idemKey"] ?? "");
        $meta = ["arguments" => $args, "callId" => $callId, "approvalId" => (string) ($extra["approvalId"] ?? "")];
        if ($result["busy"]) {
            if ($idemKey !== "") {
                McpAccount::idemEnd($this->redis, $idemKey, null);
            }
            return $this->accountError("busy", "RingQ is busy. Try again in a moment.", 503);
        }
        if (!$result["ok"] || !McpCatalog::appSucceeded($result["body"])) {
            $message = is_array($result["body"]) ? (string) ($result["body"]["message"] ?? "") : "";
            if ($idemKey !== "") {
                McpAccount::idemEnd($this->redis, $idemKey, null);
            }
            $this->auditAccount($ctx, $name, (string) $op["id"], "error", $meta + ["status" => $result["status"]]);
            return $this->accountError("app_refused", $message !== "" ? $message : "RingQ did not accept that request (HTTP " . $result["status"] . ").", 422);
        }
        $data = ["operationId" => (string) $op["id"], "summary" => $summary, "result" => McpCatalog::shape($result["body"], $op)];
        $record = $extra["record"] ?? null;
        if (is_array($record)) {
            $meta["changes"] = $record["changes"];
            $meta["revert"] = $record["revert"];
            $data["undo"] = $record["revert"] !== null
                ? ["available" => true, "how" => "Call ringq_api_write with this operationId and arguments (a dry run first is recommended)."] + $record["revert"]
                : ["available" => false, "reason" => $record["reason"]];
        }
        if ($idemKey !== "") {
            McpAccount::idemEnd($this->redis, $idemKey, $data);
        }
        $this->auditAccount($ctx, $name, (string) $op["id"], "success", $meta);
        return $this->accountOk(["data" => $data]);
    }

    private function auditAccount(array $ctx, string $tool, string $operationId, string $result, array $meta): void
    {
        if (isset($ctx["staff"])) {
            $this->auditStaff($ctx["vDomain"], $ctx["staff"], $tool, $operationId, $result, $meta);
            return;
        }
        try {
            $u = $ctx["user"];
            $metadata = json_encode([
                "client" => (string) $ctx["actor"]["client"],
                "actorKind" => (string) $ctx["actor"]["kind"],
                "actorRef" => (string) $ctx["actor"]["ref"],
                "tool" => $tool,
                "operationId" => $operationId,
            ] + AuditMap::redact($meta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->db->execute(
                "INSERT INTO audit_logs (domain_uuid, username, user_fullname, user_email, extension, ip_address, user_agent, feature, action, entity, result, metadata)
                 VALUES (:d, 'ai-mcp', :n, :e, :x, :ip, :ua, 'ai-mcp', :a, :en, :r, CAST(:m AS jsonb))",
                [
                    "d" => (string) $ctx["vDomain"]->domain_uuid,
                    "n" => mb_substr($ctx["actor"]["kind"] === "key" ? (string) $ctx["actor"]["client"] : trim(((string) ($u["first_name"] ?? "")) . " " . ((string) ($u["last_name"] ?? ""))), 0, 255),
                    "e" => mb_substr((string) $u["user_email"], 0, 190),
                    "x" => (string) $u["extension"],
                    "ip" => substr($this->callerAddress(), 0, 45),
                    "ua" => mb_substr((string) $ctx["actor"]["client"], 0, 255),
                    "a" => mb_substr($operationId !== "" ? $operationId : $tool, 0, 64),
                    "en" => mb_substr((string) $ctx["actor"]["client"], 0, 255),
                    "r" => $result,
                    "m" => $metadata === false ? null : $metadata,
                ]
            );
        } catch (\Throwable $e) {
            error_log("ai-mcp audit: " . $e->getMessage());
        }
    }

    private function refuseStaff()
    {
        return $this->accountError("unauthorized", "That staff request was not accepted.", 401);
    }

    private function auditStaff($vDomain, array $staff, string $tool, string $operationId, string $result, array $meta): void
    {
        try {
            $metadata = json_encode([
                "via" => "ops-mcp",
                "staffId" => (string) $staff["id"],
                "staffRole" => (string) $staff["role"],
                "client" => (string) $staff["client"],
                "session" => (string) $staff["session"],
                "staffIp" => (string) $staff["ip"],
                "tool" => $tool,
                "operationId" => $operationId,
            ] + AuditMap::redact($meta), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $arguments = is_array($meta["arguments"] ?? null) ? $meta["arguments"] : [];
            $this->db->execute(
                "INSERT INTO audit_logs (domain_uuid, username, user_fullname, user_email, extension, ip_address, user_agent, feature, action, entity, result, metadata)
                 VALUES (:d, 'ops-mcp', :n, :e, NULL, :ip, :ua, 'support-mcp', :a, :en, :r, CAST(:m AS jsonb))",
                [
                    "d" => (string) $vDomain->domain_uuid,
                    "n" => mb_substr(($staff["name"] !== "" ? $staff["name"] : $staff["email"]) . " (RingQ " . McpStaff::roleLabel((string) $staff["role"]) . ")", 0, 255),
                    "e" => mb_substr((string) $staff["email"], 0, 190),
                    "ip" => substr($staff["ip"] !== "" ? (string) $staff["ip"] : $this->callerAddress(), 0, 45),
                    "ua" => mb_substr((string) $staff["client"], 0, 255),
                    "a" => mb_substr($operationId !== "" ? $operationId : $tool, 0, 64),
                    "en" => mb_substr($arguments !== [] ? $this->argumentSummary($arguments) : (string) ($meta["approvalId"] ?? ""), 0, 255),
                    "r" => $result,
                    "m" => $metadata === false ? null : $metadata,
                ]
            );
        } catch (\Throwable $e) {
            error_log("ops-mcp audit: " . $e->getMessage());
        }
    }

    private function accountOk(array $payload)
    {
        return $this->response->setContent(json_encode(["ok" => true] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function accountError(string $code, string $message, int $status)
    {
        $this->response->setStatusCode($status);
        return $this->response->setContent(json_encode([
            "ok" => false,
            "error" => ["code" => $code, "message" => $message],
        ]));
    }

    private function accountUnauthorized()
    {
        return $this->accountError("unauthorized", "Sign in to RingQ again to use this connection.", 401);
    }

    /** The signed-in session, only when it may manage support access. */
    private function manager()
    {
        $session = $this->session->get("auth");
        if (!$session || ($session["access"] ?? "") !== "registered") {
            return null;
        }
        $mode = $this->permissionModeOf($session);
        $extension = (string) ($session["extension"] ?? "");
        if (!McpGrant::canManage($mode, $extension)) {
            return null;
        }
        return $session;
    }

    private function overview(string $subdomain): array
    {
        return [
            "access" => McpGrant::settings($this->redis, $subdomain),
            "service" => McpGrant::service($this->redis),
            "endpoint" => $this->endpoint(),
            "grants" => [],
            "grantsRetired" => McpGrant::RETIRED,
            "tools" => array_map(function ($tool) {
                return ["name" => $tool["name"], "summary" => $tool["description"]];
            }, McpTools::describe()),
            "operations" => array_values(array_map(function ($op) {
                return ["id" => (string) $op["id"], "summary" => (string) ($op["summary"] ?? ""), "access" => (string) $op["access"]];
            }, array_filter(McpCatalog::load(), function ($op) {
                return McpCatalog::allowedFor($op, "owner");
            }))),
            "durations" => McpGrant::DURATIONS,
            "maxGrants" => McpGrant::MAX_ACTIVE_GRANTS,
            "capabilities" => $this->capabilities(),
            "customerMcpUrl" => "https://" . $this->bareHost() . "/mcp/account",
        ];
    }

    private function endpoint(): string
    {
        return "https://" . $this->request->getHttpHost() . "/mcp";
    }

    private function domainForHost(string $host)
    {
        $host = trim(explode(':', $host)[0]);
        if ($host === '') {
            return null;
        }
        return VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $host],
            "bindTypes" => [\Phalcon\Db\Column::BIND_PARAM_STR],
        ]);
    }

    private function body(): array
    {
        try {
            $decoded = $this->request->getJsonRawBody(true);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $e) {
            // fall through to form fields
        }
        $post = $this->request->getPost();
        return is_array($post) ? $post : [];
    }

    private function actorName($session): string
    {
        $name = trim(($session["firstname"] ?? "") . " " . ($session["lastname"] ?? ""));
        if ($name !== "") {
            return $name;
        }
        return (string) ($session["username"] ?? "unknown");
    }

    private function ok(array $payload)
    {
        return $this->response->setContent(json_encode(["result" => "success"] + $payload));
    }

    private function fail(string $message)
    {
        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => $message,
        ]));
    }

    private function refuseAdmin()
    {
        $this->response->setStatusCode(403, "Forbidden");
        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "Only an account owner or RingQ support can manage support access.",
        ]));
    }

    /**
     * One body for every refusal. Telling "no such grant" apart from "access is
     * off" would make this endpoint an oracle for which tenants can be reached.
     */
    private function refuseTool()
    {
        return $this->toolError("unauthorized", "Support access is not available with that token.", 401);
    }

    private function toolError(string $code, string $message, int $status)
    {
        $this->response->setStatusCode($status, $status === 401 ? "Unauthorized" : "Bad Request");
        return $this->response->setContent(json_encode([
            "ok" => false,
            "error" => ["code" => $code, "message" => $message],
        ]));
    }

    /**
     * The customer's own audit log, written before the answer leaves. The grant
     * label identifies the engineer; the token never appears.
     */
    private function auditTool($vDomain, array $grant, string $name, array $arguments, string $result): void
    {
        try {
            $metadata = json_encode([
                "grantId" => (string) $grant["id"],
                "arguments" => AuditMap::redact($arguments),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->db->execute(
                "INSERT INTO audit_logs
                    (domain_uuid, username, user_fullname, user_email, extension,
                     ip_address, user_agent, feature, action, entity, result, metadata)
                 VALUES
                    (:domain_uuid, :username, :user_fullname, :user_email, NULL,
                     :ip_address, :user_agent, 'support-mcp', :action, :entity, :result,
                     CAST(:metadata AS jsonb))",
                [
                    "domain_uuid" => (string) $vDomain->domain_uuid,
                    "username" => "support-mcp",
                    "user_fullname" => mb_substr((string) $grant["label"], 0, 255),
                    "user_email" => mb_substr((string) ($grant["engineerEmail"] ?? ""), 0, 190),
                    "ip_address" => substr($this->callerAddress(), 0, 45),
                    "user_agent" => mb_substr($this->callerAgent(), 0, 255),
                    "action" => mb_substr($name, 0, 64),
                    "entity" => mb_substr($this->argumentSummary($arguments), 0, 255),
                    "result" => $result,
                    "metadata" => $metadata === false ? null : $metadata,
                ]
            );
        } catch (\Throwable $e) {
            error_log("mcp audit: " . $e->getMessage());
        }
    }

    /**
     * The machine that made the call, not the adapter that relayed it.
     *
     * The adapter reaches PHP over loopback, so every audit row would otherwise
     * read 127.0.0.1 and the customer could never tell two engineers apart. The
     * forwarded value is read ONLY when the immediate peer is loopback: a
     * request arriving straight from outside carries whatever address it really
     * came from, and a header it invented is ignored.
     */
    private function callerAddress(): string
    {
        $peer = (string) $this->request->getClientAddress();
        if (!$this->fromLoopback($peer)) {
            return $peer;
        }
        $forwarded = trim((string) $this->request->getHeader("X-Ringq-Mcp-Client-Ip"));
        return $forwarded === "" ? $peer : $forwarded;
    }

    private function callerAgent(): string
    {
        $peer = (string) $this->request->getClientAddress();
        if (!$this->fromLoopback($peer)) {
            return (string) $this->request->getUserAgent();
        }
        $forwarded = trim((string) $this->request->getHeader("X-Ringq-Mcp-Client-Agent"));
        return $forwarded === "" ? (string) $this->request->getUserAgent() : $forwarded;
    }

    private function fromLoopback(string $address): bool
    {
        return $address === "127.0.0.1" || $address === "::1" || strpos($address, "127.") === 0;
    }

    private function argumentSummary(array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $parts[] = $key . "=" . mb_substr((string) $value, 0, 40);
        }
        return implode(" ", $parts);
    }
}
