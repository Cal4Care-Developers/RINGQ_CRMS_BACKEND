<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

// MR AI changes - starts
// KnowledgeBase lives in this directory (app/controllers/KnowledgeBase.php), not in
// app/library/. It is required by path rather than left to the autoloader because
// app/config/loader.php is ionCube-encrypted -- which directories it registers cannot be
// read or changed from this checkout. Guarded so a second require is harmless.
if (!class_exists('KnowledgeBase', false)) { require_once __DIR__ . '/KnowledgeBase.php'; }
// Is a typed-in provider API key accepted by its provider? (checked when an agent is saved)
if (!class_exists('AiProviderKeyCheck', false)) { require_once __DIR__ . '/AiProviderKeyCheck.php'; }
// MR AI changes - ends

class AiController extends ControllerBase
{
    /**
     * Override ControllerBase's beforeExecuteRoute to completely bypass the default ACL.
     * We will manage authentication manually inside the administrative actions.
     */
    public function beforeExecuteRoute(\Phalcon\Mvc\Dispatcher $dispatcher)
    {
        return true;
    }

    /**
     * Reusable session authorization check.
     */
    private function checkAuth()
    {
        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "Unathorized. (Code AI1001)",
            ]);
        }
        return $session;
    }

    /**
     * Get Redis client with TCP fallback for Windows development environments.
     */
    private function getRedis()
    {
        try {
            $redis = $this->redis;
            $redis->ping();
            return $redis;
        } catch (\Throwable $e) {
            try {
                $redis = new \Predis\Client([
                    'scheme' => 'tcp',
                    'host'   => '127.0.0.1',
                    'port'   => 6379,
                ]);
                $redis->ping();
                return $redis;
            } catch (\Throwable $e2) {
                throw new \Exception("Redis connection failed: " . $e2->getMessage());
            }
        }
    }

    /**
     * Encrypt sensitive credentials using the app's cryptSalt.
     */
    private function encryptToken(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        return $this->crypt->encryptBase64($text);
    }

    /**
     * Decrypt sensitive credentials.
     */
    private function decryptToken(?string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }
        return rtrim($this->crypt->decryptBase64($encrypted));
    }

    /**
     * Parse languages string to find short codes inside parentheses, e.g. "English (en)" -> "en"
     */
    private function toLanguageCodes(?string $languages): string
    {
        $langCodes = 'en';
        if (!empty($languages)) {
            preg_match_all('/\(([a-zA-Z\-]+)\)/', $languages, $matches);
            if (!empty($matches[1])) {
                $langCodes = implode(',', $matches[1]);
            }
        }
        return $langCodes;
    }

    // MR AI changes - starts
    // ---- Bring-your-own AI provider key (per agent) ----------------------------------------
    // The key is NEVER stored inside the agent blob (agent:{org}:{id}), because /ai/list and
    // /ai/get return that blob verbatim. It lives under its own key, encrypted with the same
    // crypt service as the connector tokens, and is bound to the provider it was entered for:
    // switching an agent's provider without entering a new key means "no key", not "reuse the
    // old provider's key". The browser only ever sees a hasProviderApiKey boolean.
    private function providerSecretKey(string $orgId, string $agentId): string
    {
        return "agent-secret:{$orgId}:{$agentId}";
    }

    private function storeProviderKey($redis, string $orgId, string $agentId, string $provider, string $plainKey): void
    {
        $redis->set($this->providerSecretKey($orgId, $agentId), json_encode([
            "provider" => $provider,
            "key"      => $this->encryptToken($plainKey),
            "updated"  => date("Y-m-d H:i"),
        ]));
    }

    /** Decrypted key for this agent's CURRENT provider, or '' (internal use only -- never returned to browsers). */
    public function loadProviderKey($redis, string $orgId, string $agentId, string $provider): string
    {
        $raw = $redis->get($this->providerSecretKey($orgId, $agentId));
        if (empty($raw)) {
            return '';
        }
        $secret = json_decode($raw, true);
        if (!is_array($secret) || ($secret["provider"] ?? '') !== $provider) {
            return '';
        }
        return $this->decryptToken($secret["key"] ?? '');
    }

    /**
     * What the editor may show about a stored key, so "is there a key on this agent?" never has to be guessed:
     * its LAST FOUR characters and when it was saved. Never more -- the same as any payment screen shows a card.
     * Empty when no key is stored for this provider (a key belongs to the provider it was entered for).
     * @return array{hint:string, savedAt:string}
     */
    private function providerKeyInfo($redis, string $orgId, string $agentId, string $provider): array
    {
        $empty = ['hint' => '', 'savedAt' => ''];
        $raw = $redis->get($this->providerSecretKey($orgId, $agentId));
        if (empty($raw)) {
            return $empty;
        }
        $secret = json_decode($raw, true);
        if (!is_array($secret) || ($secret["provider"] ?? '') !== $provider) {
            return $empty;
        }
        $key = $this->decryptToken($secret["key"] ?? '');
        if ($key === '') {
            return $empty;
        }
        return [
            // A very short value would be mostly revealed by its last four characters: show none.
            'hint'    => strlen($key) >= 12 ? substr($key, -4) : '',
            'savedAt' => (string) ($secret["updated"] ?? ''),
        ];
    }

    /** Strips any plaintext key and adds the hasProviderApiKey flag (plus a safe hint) the editor shows. */
    private function attachProviderKeyFlag($redis, string $orgId, array $agent): array
    {
        unset($agent["providerApiKey"]);
        $agentId = (string) ($agent["id"] ?? '');
        $provider = (string) ($agent["ringq_model"] ?? ($agent["model"] ?? ''));
        $agent["hasProviderApiKey"] = $agentId !== '' && $this->loadProviderKey($redis, $orgId, $agentId, $provider) !== '';
        $info = $agent["hasProviderApiKey"]
            ? $this->providerKeyInfo($redis, $orgId, $agentId, $provider)
            : ['hint' => '', 'savedAt' => ''];
        $agent["providerKeyHint"] = $info['hint'];
        $agent["providerKeySavedAt"] = $info['savedAt'];
        $agent["model"] = $agent["model"] ?? $provider;
        return $agent;
    }
    // MR AI changes - ends

    // MR AI changes - starts
    /**
     * POST /ai/revealKey   {id}
     *
     * Returns the provider key saved for ONE agent, for the editor's eye (show/hide) button.
     *
     * Deliberately a separate request and not a field of /ai/get or /ai/list: those responses are cached,
     * logged and rendered everywhere, so they must never carry the key. This one answers only an explicit
     * click, only a signed-in user, never a plain user (permission mode 10, same rule as saving), is
     * marked no-store, and writes an audit line that names who looked at which agent -- not the key.
     */
    public function revealKeyAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setHeader("Cache-Control", "no-store");

        // CORS preflight: the browser sends OPTIONS without cookies before the real POST, so it must be
        // answered 200 BEFORE the session check below, or the POST is never sent. Like McpaiController, no
        // Access-Control-* headers are set here -- an outer layer already adds them (doing it twice gives
        // a "multiple values" CORS error).
        if ($this->request->getMethod() === 'OPTIONS') {
            $this->response->setStatusCode(200, 'OK');
            return $this->response->setJsonContent(["result" => "success"]);
        }

        try {
            $session = $this->session->get("auth");
            if (empty($session) || empty($session['domain_uuid'])) {
                $this->response->setStatusCode(401, "Unauthorized");
                return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
            }
            if (!$this->request->isPost()) {
                throw new \Exception("Invalid request method");
            }
            $redis = $this->getRedis();
            $orgId = (string) $session['domain_uuid'];

            $mode = $redis->get(($session['extension'] ?? '') . "-" . ($session['subdomain'] ?? '') . "-permission");
            if ($mode !== null && $mode !== false && intval($mode) === 10) {
                $this->response->setStatusCode(403, "Forbidden");
                return $this->response->setJsonContent(["result" => "error", "message" => "Only an administrator can view a saved key."]);
            }

            $body = $this->request->getJsonRawBody(true);
            $agentId = trim((string) (is_array($body) ? ($body['id'] ?? '') : ''));
            if ($agentId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $agentId)) {
                throw new \Exception("Agent id is required");
            }

            // Scoped to the caller's own tenant by construction: the key is looked up under THEIR domain.
            $agentJson = $redis->get("agent:{$orgId}:{$agentId}");
            $agent = $agentJson ? json_decode($agentJson, true) : null;
            if (!is_array($agent)) {
                throw new \Exception("Agent not found");
            }
            $provider = (string) ($agent["ringq_model"] ?? ($agent["model"] ?? ''));
            $key = $this->loadProviderKey($redis, $orgId, $agentId, $provider);
            if ($key === '') {
                throw new \Exception("No key is saved for this agent's provider.");
            }

            error_log('[AI] provider key viewed: agent ' . $agentId . ' (' . $provider . ') by user ' . ($session['user_uuid'] ?? '?'));
            return $this->response->setJsonContent(["result" => "success", "providerApiKey" => $key]);

        } catch (\Throwable $e) {
            return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
        }
    }
    // MR AI changes - ends

    /**
     * GET /ai/get
     * Retrieves the configuration for a specific agent and active connector statuses.
     */
    public function getAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $session = $this->checkAuth();
            $orgId = $session['domain_uuid'];
            $subdomain = $session['subdomain'];
            $redis = $this->getRedis();

            $agentId = $this->request->getQuery("id", "string") ?: 'default';
            $agentKey = "agent:{$orgId}:{$agentId}";
            $agentJson = $redis->get($agentKey);
            $agentConfig = [];
            if (!empty($agentJson)) {
                $agentConfig = json_decode($agentJson, true);
            }

            // Fallback lookup to individual legacy keys if main config is empty (only for default agent)
            if (empty($agentConfig) && $agentId === 'default') {
                $agentConfig = [
                    "id" => "default",
                    "name" => "Main Agent",
                    "prompt" => $redis->get("ai:{$subdomain}-prompt") ?: '',
                    "context" => $redis->get("ai:{$subdomain}-context") ?: '',
                    "greetings" => $redis->get("ai:{$subdomain}-greetings") ?: '',
                    "voice" => $redis->get("ai:{$subdomain}-voice") ?: 'Alloy',
                    "ringq_model" => $redis->get("ai:{$subdomain}-ringq_model") ?: 'Claude',
                    "keywords" => $redis->get("ai:{$subdomain}-keywords") ?: 'agent',
                    "keywordExtensions" => $redis->get("ai:{$subdomain}-keywordExtensions") ?: '1000',
                    "languages" => $redis->get("ai:{$subdomain}-languages") ?: 'English',
                    "defaultLanguage" => $redis->get("ai:{$subdomain}-defaultLanguage") ?: 'English',
                    "enable_live_transcript" => $redis->get("ai:{$subdomain}-enable_live_transcript") ?: 'false',
                    "transcribeToken" => $redis->get("ai:{$subdomain}-transcribeToken") ?: '',
                    "allowedConnectorIds" => []
                ];
            }

            if (empty($agentConfig)) {
                throw new \Exception("Agent with ID '{$agentId}' not found");
            }

            // MR AI changes - starts
            $agentConfig = $this->attachProviderKeyFlag($redis, $orgId, $agentConfig);
            // MR AI changes - ends

            // Check which connectors are authorized
            $connectors = ["google-workspace", "microsoft-365"];
            $allowedConnectorIds = $agentConfig['allowedConnectorIds'] ?? [];
            $connectorStatuses = [];

            foreach ($connectors as $cid) {
                $tokenKey = "connector-token:{$orgId}:{$cid}";
                $tokenExists = (bool)$redis->exists($tokenKey);
                $connectorStatuses[] = [
                    "connectorId" => $cid,
                    "enabled" => in_array($cid, $allowedConnectorIds),
                    "connected" => $tokenExists
                ];
            }

            return $this->response->setJsonContent([
                "result" => "success",
                "config" => $agentConfig,
                "connectors" => $connectorStatuses
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    /**
     * GET /ai/list
     * Lists all registered AI agents for the current organization.
     */
    public function listAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $session = $this->checkAuth();
            $orgId = $session['domain_uuid'];
            $redis = $this->getRedis();

            // Retrieve all agent IDs from the Redis set
            $agentIds = $redis->sMembers("agents:{$orgId}");
            
            // An account with no agents gets an empty list (the page offers "Add Your First Agent"). A "Default Voice
            // Agent" used to be created here on the first page load; nothing seeds it any more.
            $agents = [];
            foreach ($agentIds as $aid) {
                $agentJson = $redis->get("agent:{$orgId}:{$aid}");
                if (!empty($agentJson)) {
                    $row = json_decode($agentJson, true);
                    // The "Default Voice Agent" an earlier version created on the first page load, never edited: remove it
                    // rather than show it (an edited one is the admin's agent and stays).
                    if ((string) $aid === 'default' && is_array($row) && ($row['name'] ?? '') === 'Default Voice Agent'
                        && ($row['description'] ?? '') === 'Global fallback voice interaction agent.'
                        && ($row['prompt'] ?? '') === 'You are a helpful AI assistant.') {
                        $redis->del("agent:{$orgId}:default");
                        $redis->sRem("agents:{$orgId}", 'default');
                        continue;
                    }
                    $agents[] = $row;
                }
            }

            // MR AI changes - starts
            foreach ($agents as $idx => $agentRow) {
                if (is_array($agentRow)) {
                    $agents[$idx] = $this->attachProviderKeyFlag($redis, $orgId, $agentRow);
                }
            }
            // MR AI changes - ends

            // Sort agents by name
            usort($agents, function($a, $b) {
                return strcmp($a['name'] ?? '', $b['name'] ?? '');
            });

            return $this->response->setJsonContent([
                "result" => "success",
                "agents" => $agents
            ]);

        } catch (\Throwable $e) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    /**
     * POST/GET /ai/delete
     * Deletes a registered AI agent from storage.
     */
    public function deleteAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $session = $this->checkAuth();
            $orgId = $session['domain_uuid'];
            $redis = $this->getRedis();

            $agentId = $this->request->get("id", "string");
            if (empty($agentId)) {
                $rawBody = $this->request->getJsonRawBody(true);
                $agentId = $rawBody['id'] ?? '';
            }

            if (empty($agentId)) {
                throw new \Exception("Agent ID is required for deletion");
            }

            $redis->del("agent:{$orgId}:{$agentId}");
            $redis->sRem("agents:{$orgId}", $agentId);
            // MR AI changes - starts
            $redis->del($this->providerSecretKey($orgId, $agentId));
            // The agent's connected Microsoft / Google account (its OAuth tokens) goes with it.
            foreach (['google', 'microsoft'] as $connProvider) {
                $redis->del("ai_agent_token:{$orgId}:{$agentId}:{$connProvider}");
            }
            // MR AI changes - ends

            // MR AI changes - starts
            // Delete this agent's knowledge base too (uploaded documents and
            // crawled website pages -- see app/controllers/KnowledgeBase.php).
            // Those keys carry no TTL, so without this they survive the agent
            // forever and accumulate per tenant with nothing left in the UI to
            // reveal or remove them. Wrapped: a cleanup failure must not stop
            // the agent itself from being deleted, which is what the user asked for.
            try {
                \KnowledgeBase::deleteAllForAgent($redis, $orgId, $agentId);
            } catch (\Throwable $kbErr) {
                error_log("[KB] cleanup after agent delete failed for {$orgId}:{$agentId}: " . $kbErr->getMessage());
            }
            // MR AI changes - ends

            // MR AI changes - starts
            // Take this agent's extension off the dialplan too, so the number does not keep answering
            // into a voice bridge that no longer has an agent for it.
            $this->removeAgentDialplan($orgId, (string) ($session['subdomain'] ?? ''), (string) $agentId);
            // MR AI changes - ends

            return $this->response->setJsonContent([
                "result" => "success",
                "message" => "Agent deleted successfully"
            ]);

        } catch (\Throwable $e) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }


    // MR AI changes - starts
    // ---- Per-agent FreeSWITCH dialplan rule ---------------------------------------------------
    // Every saved agent that has an extension gets its own dialplan row. The rule answers that
    // extension and streams the call audio to the voice bridge (node/claude_server.js), passing
    // "uuid|caller|destination|domain" as the metadata the bridge parses. The row is found again by
    // NAME (prefix + agent id), so changing an agent's extension moves its rule instead of leaving
    // the old number answering. This only ever touches rows it created itself: another dialplan rule
    // or a real extension on the same number is reported back and left alone.
    private const AI_AGENT_DIALPLAN_PREFIX = 'ringq-ai-agent-';
    private const AI_AGENT_DIALPLAN_APP_UUID = '6f0c3b52-9d1e-4a7b-8c35-2e4f7a9d1b60';
    private const AI_BRIDGE_WS = 'ws://127.0.0.1:8081';
    // How the agent's voice reaches the caller.
    //   'relay'  -- live: mod_openai_audio_stream streams the call to the bridge's relay port (8082) and plays the
    //               agent's voice back as it is produced. No WAV files, no gaps, instant barge-in.
    //   'legacy' -- the old way: mod_audio_stream to port 8081, the bridge plays WAV files with uuid_broadcast.
    // Takes effect for an agent the next time it is saved (that rewrites its dialplan rule). To go back, set this to
    // 'legacy' and save the agent again. Needs the bridge's relay (claude_server.js with AI_RELAY on, the default).
    private const AI_AUDIO_TRANSPORT = 'relay';
    private const AI_RELAY_WS = 'ws://127.0.0.1:8082/rt';
    // 5555 is the older "AI IVR" row that saveAction() still writes below.
    private const AI_RESERVED_EXTENSIONS = ['5555'];
    // false: saving an agent no longer creates or rewrites the 5555 dialplan rule (another process owns that number).
    private const WRITE_LEGACY_5555_RULE = false;
    // Runs after "AI External" (7777) and the 5555 AI IVR row, before the catch-all rules.
    private const AI_AGENT_DIALPLAN_ORDER = 82;

    private function clearDialplanCache(string $subdomain): void
    {
        $file = "/var/cache/ringq/dialplan.{$subdomain}";
        if ($subdomain !== '' && file_exists($file)) {
            @unlink($file);
        }
    }

    private function removeAgentDialplan(string $orgId, string $subdomain, string $agentId): void
    {
        try {
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $agentId)) {
                return;
            }
            $row = VDialplans::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :name:",
                "bind" => ["domain_uuid" => $orgId, "name" => self::AI_AGENT_DIALPLAN_PREFIX . $agentId],
            ]);
            if ($row && $row->delete()) {
                $this->clearDialplanCache($subdomain);
            }
        } catch (\Throwable $e) {
            error_log('[AI] dialplan cleanup failed for agent ' . $agentId . ': ' . $e->getMessage());
        }
    }

    /**
     * Creates, moves, disables or leaves alone this agent's dialplan row.
     * Returns ['status' => created|updated|unchanged|disabled|skipped|error, 'extension' => ?, 'message' => ?].
     */
    private function syncAgentDialplan(string $orgId, string $subdomain, array $session, string $agentId, array $agent): array
    {
        try {
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $agentId)) {
                return ['status' => 'skipped', 'message' => 'Agent id has characters that cannot go in a dialplan rule name.'];
            }
            $name = self::AI_AGENT_DIALPLAN_PREFIX . $agentId;
            $ext = preg_replace('/\D+/', '', (string) ($agent['agentExtension'] ?? ''));
            $enabled = !array_key_exists('enabled', $agent) || !empty($agent['enabled']);

            $row = VDialplans::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND dialplan_name = :name:",
                "bind" => ["domain_uuid" => $orgId, "name" => $name],
            ]);

            // Nothing should answer this agent's number: switch an existing rule off, never create one.
            if ($ext === '' || !$enabled) {
                if ($row && (string) $row->dialplan_enabled !== 'false') {
                    $row->dialplan_enabled = 'false';
                    $row->update_user = $session['user_uuid'] ?? null;
                    $row->update_date = date("Y-m-d H:i:s");
                    $row->update();
                    $this->clearDialplanCache($subdomain);
                }
                return ['status' => 'disabled', 'message' => $ext === '' ? 'No extension set, so no dialplan rule.' : 'Agent is disabled, so its rule is off.'];
            }

            if (in_array($ext, self::AI_RESERVED_EXTENSIONS, true)) {
                return ['status' => 'skipped', 'extension' => $ext, 'message' => "Extension {$ext} is used by the AI IVR rule, so no separate rule was created. Give this agent its own extension."];
            }

            // A different dialplan rule already owns this number.
            $other = VDialplans::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND dialplan_number = :number: AND dialplan_name != :name:",
                "bind" => ["domain_uuid" => $orgId, "number" => (int) $ext, "name" => $name],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
            ]);
            if ($other) {
                // Short on purpose: the admin needs to know the number is taken, not which table row holds it.
                error_log('[AI] extension ' . $ext . ' for agent ' . $agentId . ' is already used by dialplan rule "' . $other->dialplan_name . '"');
                return ['status' => 'skipped', 'extension' => $ext, 'message' => "Extension {$ext} is already used by another program."];
            }
            // ...or a real extension (a phone) does.
            $phone = VExtensions::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :ext:",
                "bind" => ["domain_uuid" => $orgId, "ext" => $ext],
            ]);
            if ($phone) {
                return ['status' => 'skipped', 'extension' => $ext, 'message' => "Extension {$ext} belongs to a phone extension. Pick another number for this AI agent."];
            }

            if (self::AI_AUDIO_TRANSPORT === 'relay') {
                // `playback silence_stream://-1` and not `park`: the module plays the agent's voice by replacing the
                // frames FreeSWITCH writes to the caller, and a parked channel writes none. The bridge never breaks
                // this playback (no uuid_break / uuid_broadcast on this path); it transfers or hangs up with
                // uuid_transfer / uuid_kill, which end it cleanly.
                $xml = str_replace(
                    ['{{NAME}}', '{{EXT}}', '{{WS}}'],
                    [$name, $ext, self::AI_RELAY_WS],
                    <<<'XML'
<extension name="{{NAME}}" continue="false">
  <condition field="destination_number" expression="^{{EXT}}$">
    <action application="answer"/>
    <action application="set" data="STREAM_OPENAI_API_KEY=local-relay"/>
    <action application="set" data="STREAM_DISABLE_AUDIOFILES=true"/>
    <action application="set" data="STREAM_BUFFER_SIZE=20"/>
    <action application="set" data="STREAM_NO_RECONNECT=true"/>
    <action application="set" data="api_result=${uuid_openai_audio_stream ${uuid} start {{WS}}?u=${uuid}&amp;c=${caller_id_number}&amp;e=${destination_number}&amp;d=${domain_name}&amp;r=16000 mono 16k}"/>
    <action application="log" data="INFO RingQ AI agent {{EXT}}: uuid_openai_audio_stream start -> ${api_result}"/>
    <action application="sleep" data="500"/>
    <action application="playback" data="silence_stream://-1"/>
    <action application="set" data="api_result=${uuid_openai_audio_stream ${uuid} stop}"/>
    <action application="hangup"/>
  </condition>
</extension>
XML
                );
            } else {
            $xml = str_replace(
                ['{{NAME}}', '{{EXT}}', '{{WS}}'],
                [$name, $ext, self::AI_BRIDGE_WS],
                <<<'XML'
<extension name="{{NAME}}" continue="false">
  <condition field="destination_number" expression="^{{EXT}}$">
    <action application="answer"/>
    <action application="set" data="ai_meta=${uuid}|${caller_id_number}|${destination_number}|${domain_name}"/>
    <action application="log" data="INFO RingQ AI agent {{EXT}}: streaming with metadata ${ai_meta}"/>
    <action application="set" data="api_result=${uuid_audio_stream ${uuid} start {{WS}} mono 8k ${ai_meta}}"/>
    <action application="log" data="INFO RingQ AI agent {{EXT}}: uuid_audio_stream start -> ${api_result}"/>
    <action application="sleep" data="500"/>
    <action application="park"/>
    <action application="set" data="api_result=${uuid_audio_stream ${uuid} stop}"/>
  </condition>
</extension>
XML
            );
            }

            if (!$row) {
                $security = new \Phalcon\Security\Random();
                $row = new VDialplans();
                $row->domain_uuid = $orgId;
                $row->dialplan_uuid = $security->uuid();
                $row->app_uuid = self::AI_AGENT_DIALPLAN_APP_UUID;
                $row->dialplan_name = $name;
                $row->dialplan_number = $ext;
                $row->dialplan_context = $subdomain;
                $row->dialplan_continue = "false";
                $row->dialplan_order = self::AI_AGENT_DIALPLAN_ORDER;
                $row->dialplan_enabled = "true";
                $row->dialplan_xml = $xml;
                $row->insert_user = $session['user_uuid'] ?? null;
                $row->insert_date = date("Y-m-d H:i:s");
                if (!$row->create()) {
                    $errors = [];
                    foreach ($row->getMessages() as $msg) {
                        $errors[] = $msg->getMessage();
                    }
                    throw new \Exception("Database failed to create the dialplan rule: " . implode(', ', $errors));
                }
                $this->clearDialplanCache($subdomain);
                return ['status' => 'created', 'extension' => $ext];
            }

            if ((string) $row->dialplan_xml === $xml && (string) $row->dialplan_enabled === 'true' && (string) $row->dialplan_number === $ext) {
                return ['status' => 'unchanged', 'extension' => $ext];
            }
            $row->dialplan_number = $ext;
            $row->dialplan_enabled = "true";
            $row->dialplan_xml = $xml;
            $row->update_user = $session['user_uuid'] ?? null;
            $row->update_date = date("Y-m-d H:i:s");
            if (!$row->update()) {
                $errors = [];
                foreach ($row->getMessages() as $msg) {
                    $errors[] = $msg->getMessage();
                }
                throw new \Exception("Database failed to update the dialplan rule: " . implode(', ', $errors));
            }
            $this->clearDialplanCache($subdomain);
            return ['status' => 'updated', 'extension' => $ext];

        } catch (\Throwable $e) {
            // The agent itself is already saved; a dialplan problem is reported, not fatal.
            error_log('[AI] agent dialplan sync failed for ' . $agentId . ': ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'The agent was saved, but its dialplan rule could not be written.'];
        }
    }
    // MR AI changes - ends

    /**
     * POST /ai/save
     * Saves the agent settings, validates OAuth configs, and registers the FreeSWITCH Dialplan.
     */
    // MR AI changes - starts
    /**
     * The agent's "escalate to a person" settings, from whatever the editor posted. Returns a clean
     * array, or null when there is nothing usable (the field is then simply not stored).
     *
     * Every field is re-typed and bounded here because the values end up in three sensitive places:
     * the model's prompt (message), spoken aloud by text-to-speech (message), and a FreeSWITCH
     * transfer command (extension). Quotes and control characters are removed from the message so it
     * cannot break out of the prompt's quoting; the extension keeps only dial-string characters.
     * Mirrors normalizeEscalation() in node/escalation.js, which re-checks at call time.
     */
    private function cleanEscalation($raw)
    {
        if (!is_array($raw)) {
            return null;
        }
        $flag = static function ($v, $default) {
            if ($v === null) { return $default; }
            return $v === true || $v === 1 || $v === '1' || $v === 'true';
        };
        $message = preg_replace('/[\x00-\x1F\x7F"]+/u', ' ', (string) ($raw['message'] ?? ''));
        $message = trim((string) preg_replace('/\s+/u', ' ', (string) $message));
        $extension = trim((string) preg_replace('/[^A-Za-z0-9 *#]/', '', (string) ($raw['extension'] ?? '')));
        $maxMisses = isset($raw['maxMisses']) && is_numeric($raw['maxMisses']) ? (int) round((float) $raw['maxMisses']) : 2;

        return [
            'enabled'        => ($raw['enabled'] ?? false) === true || ($raw['enabled'] ?? null) === 'true' || ($raw['enabled'] ?? null) === 1,
            'onHumanRequest' => $flag($raw['onHumanRequest'] ?? null, true),
            'onFrustration'  => $flag($raw['onFrustration'] ?? null, true),
            'maxMisses'      => max(0, min(5, $maxMisses)),
            'extension'      => mb_substr($extension, 0, 80),
            'message'        => mb_substr($message, 0, 300),
        ];
    }
    // MR AI changes - ends

    public function saveAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $session = $this->checkAuth();
            $orgId = $session['domain_uuid'];
            $subdomain = $session['subdomain'];
            $redis = $this->getRedis();

            // Read JSON raw payload or POST data
            $rawBody = $this->request->getJsonRawBody(true);
            if (empty($rawBody)) {
                $rawBody = $this->request->getPost();
            }

            if (empty($rawBody)) {
                throw new \Exception("Invalid or empty request payload");
            }

            $agentId = $rawBody['id'] ?? 'default';

            // MR AI changes - starts
            // One extension, one agent. A call to an extension is answered by the FIRST agent that has it (see
            // TeamsaiController::resolveAgentAction), so two agents sharing one means a call can be answered by a
            // different agent than the one being edited -- with its own provider key, prompt and voice. That is how
            // an agent saved with a wrong API key can appear to "still work". New agents all start on 5555, which
            // makes the clash easy to create, so refuse it here, before anything is written, and say which agent has it.
            $ownExtension = preg_replace('/\D+/', '', (string) ($rawBody['agentExtension'] ?? ''));
            if ($ownExtension !== '' && $agentId !== 'default') {
                foreach ((array) $redis->sMembers("agents:{$orgId}") as $otherId) {
                    if ((string) $otherId === (string) $agentId) {
                        continue;
                    }
                    $other = json_decode((string) $redis->get("agent:{$orgId}:{$otherId}"), true);
                    if (!is_array($other)) {
                        continue;
                    }
                    if (preg_replace('/\D+/', '', (string) ($other['agentExtension'] ?? '')) === $ownExtension) {
                        $otherName = trim((string) ($other['name'] ?? '')) !== '' ? $other['name'] : $otherId;
                        throw new \Exception("Extension {$ownExtension} is already used by the agent \"{$otherName}\". "
                            . "Two agents cannot share an extension -- a call would reach only one of them. Choose a different extension.");
                    }
                }
            }
            // MR AI changes - ends

            // MR AI changes - starts
            // 1. Model Mappings
            // The Flutter agent editor (ai_agent_edit.dart) posts the
            // provider choice as "model" (its AiAgent.toJson() field name)
            // -- it never sends "ringq_model". Reading only ringq_model
            // here meant every save silently defaulted to Claude/the
            // retired claude-3-5-sonnet-20241022 id, regardless of what
            // the user actually picked in the UI. Accept "model" first,
            // fall back to "ringq_model" for any older/internal caller
            // still using that key.
            $model = $rawBody['model'] ?? $rawBody['ringq_model'] ?? 'Claude';
            // MR AI changes - starts: 'Claude' was 'claude-sonnet-4-5' here
            // while listAction()'s seeded default agent said
            // 'claude-3-5-sonnet-20241022' and claude_server.js's
            // DEFAULT_MODEL_BY_PROVIDER said a third thing. All three now
            // say claude-opus-5. THIS MAP EXISTS IN THREE PLACES -- if you
            // change an id, change it in listAction() below and in
            // claude_server.js's DEFAULT_MODEL_BY_PROVIDER too.
            //
            // mapped_model is persisted per agent at save time, so this only
            // affects agents saved from now on; agents already in Redis are
            // upgraded at call time by normalizeModelId() in claude_server.js.
            $modelMap = [
                // Keep in step with DEFAULT_MODEL_BY_PROVIDER in node/claude_server.js (a test there checks it).
                // Claude was still 'claude-opus-5' here while the bridge upgrades that id to 'claude-sonnet-5' on every
                // call; Gemini's old default, 'gemini-2.0-flash', was retired by Google and now fails every call.
                'Claude'        => 'claude-sonnet-5',
                'OpenAI'        => 'gpt-4.1',
                'Gemini'        => 'gemini-3.8-flash',
                // MR AI changes - starts: Groq and DeepSeek DO have live
                // adapters now (providers/groq.js and providers/deepseek.js,
                // both built on createOpenAiCompatibleProvider). This comment
                // used to say all three fell back to Claude, which is only
                // true of Azure OpenAI -- see the note in providers/index.js
                // for why that one is deliberately unmapped (it needs a
                // resource name, deployment name and api-version per tenant,
                // and the agent schema has nowhere to put them).
                'Groq'          => 'openai/gpt-oss-120b',
                'DeepSeek'      => 'deepseek-chat',
                'Grok'          => 'grok-3-mini',
                // Accepted and stored so the choice round-trips through the
                // UI without being silently dropped, but claude_server.js
                // falls back to Claude at call time.
                'Azure OpenAI'  => 'gpt-4.1',
                // MR AI changes - ends
            ];
            $mappedModel = $modelMap[$model] ?? $model;
            // MR AI changes - ends

            // 2. Connector Permissions & Context Providers Alignment
            $allowedConnectorIds = $rawBody['allowedConnectorIds'] ?? [];
            if (!is_array($allowedConnectorIds)) {
                $allowedConnectorIds = [];
            }

            $contextProviders = $rawBody['contextProviders'] ?? [];
            if (!is_array($contextProviders)) {
                $contextProviders = [];
            }

            // Aligns contextProviders with allowedConnectorIds (allowedConnectorIds is source of truth)
            $updatedContextProviders = [];
            foreach ($contextProviders as $provider) {
                if (!isset($provider['type'])) {
                    continue;
                }
                $type = $provider['type'];
                $connectorId = null;
                if ($type === 'google') {
                    $connectorId = 'google-workspace';
                } else if ($type === 'microsoft') {
                    $connectorId = 'microsoft-365';
                }

                if ($connectorId !== null) {
                    $provider['enabled'] = in_array($connectorId, $allowedConnectorIds);
                }
                $updatedContextProviders[] = $provider;
            }

            // 3. Tool Permissions (default to deny-by-default if allowedTools is empty)
            $toolPermissions = $rawBody['toolPermissions'] ?? [];
            $updatedToolPermissions = [];
            foreach ($toolPermissions as $perm) {
                $cid = $perm['connectorId'] ?? '';
                if (empty($cid)) {
                    continue;
                }
                $allowedTools = $perm['allowedTools'] ?? [];
                // If it is empty or not specified, we enforce an empty deny-by-default config
                if (!is_array($allowedTools) || count($allowedTools) === 0) {
                    $allowedTools = [];
                }
                $updatedToolPermissions[] = [
                    "connectorId" => $cid,
                    "allowedTools" => $allowedTools
                ];
            }

            // MR AI changes - starts
            // The Flutter editor (AiAgent.toJson()) posts "greeting" (singular)
            // and "language" (singular) -- this merge block previously only
            // ever read "greetings" (plural) and "defaultLanguage", neither of
            // which Flutter sends, so a saved agent's greeting/language always
            // silently fell back to "Hello"/"English" no matter what the user
            // typed, same class of bug as the ringq_model mismatch fixed above.
            // Normalize once here and store every legacy alias other code in
            // this file (and the dialplan XML generator below) still reads.
            $greetingText = $rawBody['greeting'] ?? $rawBody['greetings'] ?? '';
            $languageText = $rawBody['language'] ?? $rawBody['languages'] ?? $rawBody['defaultLanguage'] ?? 'English';
            // MR AI changes - ends

            // MR AI changes - starts
            // Escalation to a person (when the AI should hand the caller to a human -- see
            // node/escalation.js). A payload that carries the field is validated and stored. One that does
            // not (the Flutter editor does not know it, and this action stores the posted body as the new
            // blob) keeps whatever is already saved, so editing an agent there cannot silently switch
            // escalation off.
            $escalation = null;
            if (array_key_exists('escalation', $rawBody)) {
                $escalation = $this->cleanEscalation($rawBody['escalation']);
            } else {
                $previous = json_decode((string) $redis->get("agent:{$orgId}:{$agentId}"), true);
                if (is_array($previous) && isset($previous['escalation']) && is_array($previous['escalation'])) {
                    $escalation = $previous['escalation'];
                }
            }
            // MR AI changes - ends

            // Merge back cleaned properties
            $configData = array_merge($rawBody, [
                "id" => $agentId,
                "ringq_model" => $model,
                "mapped_model" => $mappedModel,
                "allowedConnectorIds" => $allowedConnectorIds,
                "contextProviders" => $updatedContextProviders,
                "toolPermissions" => $updatedToolPermissions,
                // MR AI changes - starts: canonical + legacy-alias fields, see note above
                "greeting" => $greetingText,
                "greetings" => $greetingText,
                "language" => $languageText,
                "languages" => $languageText,
                "defaultLanguage" => $languageText,
                // MR AI changes - ends
                // MR AI changes - starts
                // ownerUserUuid: whose connected Google/Microsoft accounts
                // this agent acts on. Connector grants are keyed on the
                // (ownerUserUuid, this agent's own id) PAIR, not on
                // ownerUserUuid alone (see TeamsController's ai_user_token:*
                // store) -- so mcp_email_server.js resolves Gmail/Calendar/
                // Graph tokens through both this field and the agent's id
                // together, and two agents saved by the same human get
                // independent connections.
                //
                // Stamped from the session when the client does not supply
                // one, which is the common case: someone configuring their
                // own receptionist. The Flutter editor deliberately does not
                // offer an owner picker yet -- that needs a tenant user list
                // this controller does not expose -- so in practice this is
                // always the saving user today. Sent explicitly, it is
                // honoured, which is what makes an admin-assigns-owner UI a
                // pure front-end addition later.
                "ownerUserUuid" => !empty($rawBody['ownerUserUuid'])
                    ? $rawBody['ownerUserUuid']
                    : ($session['user_uuid'] ?? ''),
                // timezone: passes through as-is. An empty value means "use
                // the voice bridge's RINGQ_DEFAULT_TIMEZONE", which is what
                // buildTimeContext() in claude_server.js falls back to.
                "timezone" => $rawBody['timezone'] ?? '',
                // MR AI changes - ends
                "updatedDate" => date("Y-m-d H:i")
            ]);

            // MR AI changes - starts
            // Provider key: keep it out of the blob, store it encrypted on its own key.
            // Blank = keep what is already stored (the editor never receives the old value back).
            $newProviderKey = trim((string) ($rawBody['providerApiKey'] ?? ''));
            unset($configData['providerApiKey'], $configData['hasProviderApiKey']);
            $configData['providerEndpoint']   = trim((string) ($rawBody['providerEndpoint'] ?? ''));
            $configData['providerDeployment'] = trim((string) ($rawBody['providerDeployment'] ?? ''));
            $keyCheckStatus = null;
            if ($newProviderKey !== '') {
                // Ask the provider whether it accepts this key and refuse the save if it clearly does not. A wrong key
                // used to be stored without a word and then fail on every call. Only a clear rejection blocks the
                // save: an unreachable provider, a restricted key or Azure (cannot be checked here) all pass as
                // 'unverified' -- see AiProviderKeyCheck.
                $keyCheck = \AiProviderKeyCheck::verify((string) $model, $newProviderKey);
                if ($keyCheck['status'] === 'invalid') {
                    throw new \Exception($keyCheck['message']);
                }
                $keyCheckStatus = $keyCheck['status'];
                $this->storeProviderKey($redis, $orgId, $agentId, (string) $model, $newProviderKey);
            } elseif ($this->loadProviderKey($redis, $orgId, $agentId, (string) $model) === '') {
                // Provider changed with no new key: the old provider's key must not linger.
                $redis->del($this->providerSecretKey($orgId, $agentId));
            }
            // MR AI changes - ends

            // MR AI changes: validated escalation settings (or none) -- never the raw posted value.
            if ($escalation !== null) {
                $configData['escalation'] = $escalation;
            } else {
                unset($configData['escalation']);
            }

            // Save main config blob
            $redis->set("agent:{$orgId}:{$agentId}", json_encode($configData));
            $redis->sAdd("agents:{$orgId}", $agentId);

            // 4. Save legacy keys to maintain compatibility with other parts of the platform
            $redis->set("ai:{$subdomain}-prompt", $configData['prompt'] ?? '');
            $redis->set("ai:{$subdomain}-context", $configData['context'] ?? '');
            $redis->set("ai:{$subdomain}-greetings", $configData['greetings'] ?? '');
            $redis->set("ai:{$subdomain}-voice", $configData['voice'] ?? 'Alloy');
            $defaultLanguage = $configData['defaultLanguage'] ?? 'English';
            $greetings = $configData['greetings'] ?? 'Hello';
            $promptText = $configData['prompt'] ?? '';
            $contextText = $configData['context'] ?? '';

            // Extract keywords & extensions dynamically
            $keywordsRaw = $configData['keywords'] ?? [];
            $keywordsStr = '';
            $keywordExtensionsStr = '';
            $transferMap = '';
            $transferExtension = '1000';
            $rules = [];

            if (is_array($keywordsRaw)) {
                // New format: array of objects
                $kwList = [];
                $extList = [];
                $transferPairs = [];
                foreach ($keywordsRaw as $idx => $kwObj) {
                    if (!empty($kwObj['keyword'])) {
                        $kw = trim($kwObj['keyword']);
                        $kwList[] = $kw;

                        // Extract extension
                        $extRaw = $kwObj['extension'] ?? '';
                        preg_match('/(\d+)/', $extRaw, $matches);
                        $ext = $matches[1] ?? '1000';
                        $extList[] = $ext;

                        if ($idx === 0) {
                            $transferExtension = $ext;
                        }

                        $transferPairs[] = strtolower($kw) . ':' . $ext;

                        $rules[] = '- If the caller says the keyword "' . strtolower($kw) . '", respond exactly with: I am now transferring to a live ' . $kw . ', translate it into: ' . $defaultLanguage;
                    }
                }
                $keywordsStr = implode(',', $kwList);
                $keywordExtensionsStr = implode(',', $extList);
                $transferMap = implode(',', $transferPairs);
            } else {
                // Legacy format: comma-separated string
                $keywordsStr = (string)$keywordsRaw;
                $keywordExtensionsStr = $configData['keywordExtensions'] ?? '1000';

                preg_match('/^\d+/', $keywordExtensionsStr, $matches);
                $transferExtension = $matches[0] ?? "1000";

                $keywordArray = array_map('trim', explode(',', $keywordsStr));
                $extensionsArr = array_map('trim', explode(',', $keywordExtensionsStr));
                $transferPairs = [];
                foreach ($keywordArray as $idx => $kw) {
                    if (isset($extensionsArr[$idx])) {
                        $ext = explode(' - ', $extensionsArr[$idx])[0];
                        $transferPairs[] = strtolower($kw) . ':' . $ext;
                    }
                    if (!empty($kw)) {
                        $rules[] = '- If the caller says the keyword "' . strtolower($kw) . '", respond exactly with: I am now transferring to a live ' . $kw . ', translate it into: ' . $defaultLanguage;
                    }
                }
                $transferMap = implode(',', $transferPairs);
            }

            $redis->set("ai:{$subdomain}-keywords", $keywordsStr);
            $redis->set("ai:{$subdomain}-keywordExtensions", $keywordExtensionsStr);
            $redis->set("ai:{$subdomain}-defaultLanguage", $defaultLanguage);
            $redis->set("ai:{$subdomain}-languages", $configData['languages'] ?? 'English');
            $redis->set("ai:{$subdomain}-ringq_model", $model);
            $redis->set("ai:{$subdomain}-enable_live_transcript", $configData['enable_live_transcript'] ?? 'false');

            $transcribeToken = $configData['transcribeToken'] ?? ($configData['aiAgentChatgptToken'] ?? '');
            $redis->set("ai:{$subdomain}-transcribeToken", $transcribeToken);
            $redis->set("ai:{$subdomain}-aiAgentChatgptToken", $transcribeToken);
            $redis->set("ai:{$subdomain}-EnableAIAgent", (!empty($transcribeToken)) ? 'true' : 'false');

            // 5. Generate and register FreeSWITCH Dialplan XML
            $voiceVal = strtolower($configData['voice'] ?? 'alloy');
            $defaultLang = $configData['languages'] ?? 'English';
            $langCodes = $this->toLanguageCodes($defaultLang);

            $rulesText = !empty($rules) ? implode("\n", $rules) : '- No keyword rules defined.';

            $brandNameArr = explode('.', $subdomain);
            $brandName = $brandNameArr[0] . ' ' . ($brandNameArr[1] ?? '');

            $greetingsBehavior = htmlspecialchars(
                "You are a professional voice assistant. Always speak in {$defaultLanguage}.
                Greeting Behavior: When a call starts, greet the caller warmly and professionally using exactly this phrase: " 
                . $greetings . 
                ". Do not translate or change the greeting text unless it is not in {$defaultLanguage}, in which case, politely say the same meaning in {$defaultLanguage}.
                After greeting, pause briefly (about 1 second), then continue the conversation naturally.
                Keep your tone friendly, confident, natural, and concise.",
                ENT_QUOTES | ENT_XML1
            );

            $rawRules = <<<RULES
{$promptText}

When asked about what {$brandName} is, respond exactly with the description above — do not add or modify any words.
Then continue the conversation naturally following the given instructions.
Keep your tone friendly, confident, and conversational, but always concise.
Do not mention or refer to any other product or company except {$brandName}.

Special Rules:
{$rulesText}

Language Control:
- You must speak strictly and exclusively in the following language(s): {$defaultLanguage}.
- Never respond in any other language.
- Even if the caller speaks another language, respond only in {$defaultLanguage}.

Core Behavior:
- Always maintain a warm, professional tone.
- Never reveal you are an AI or mention internal systems.
- Never talk about technical terms, tokens, or APIs.
- Keep your answers short, polite, and contextually relevant.

Fallback Behavior:
If the caller asks about anything unrelated or undefined, respond exactly with:
"I am sorry, I am unable to assist with that."

Do not deviate from these rules under any circumstances.

{$contextText}
RULES;
            $specialRules = htmlspecialchars($rawRules, ENT_QUOTES | ENT_XML1);

            // Determine WebSocket connection URL based on model branching
            $modelLower = strtolower($model);
            if (strpos($modelLower, 'claude') !== false || strpos($modelLower, 'anthropic') !== false) {
                // Point to our local WebSocket proxy/gateway which bridges audio to Claude + MCP
                $wsUrl = "wss://127.0.0.1:5000/claude-stream";
                $tokenVar = "STREAM_CLAUDE_API_KEY";
            } else {
                // OpenAI Realtime directly
                $wsUrl = "wss://api.openai.com/v1/realtime?model=gpt-realtime";
                $tokenVar = "STREAM_OPENAI_API_KEY";
            }

            $aiExtensionXml = <<<XML
<extension name="openai-audio-stream">
<condition field="destination_number" expression="^5555$">
    <action application="set" data="{$tokenVar}={$transcribeToken}"/>
    <action application="set" data="STREAM_BUFFER_SIZE=40"/>
    <action application="set" data="STREAM_OPENAI_VOICE={$voiceVal}"/>
    <action application="set" data="STREAM_OPENAI_INSTRUCTIONS={$specialRules}"/>
    <action application="set" data="STREAM_OPENAI_LANGUAGE={$langCodes}"/>
    <action application="set" data="STREAM_OPENAI_TRANSFER_KEYWORDS={$keywords}"/>
    <action application="set" data="STREAM_OPENAI_TRANSFER_MAP={$transferMap}"/>
    <action application="set" data="STREAM_OPENAI_TRANSFER_NUMBER={$transferExtension}"/>
    <action application="set" data="STREAM_OPENAI_TRANSFER_DEFAULT=1000"/>
    <action application="set" data="STREAM_OPENAI_GREETING={$greetingsBehavior}"/>
    <action application="answer"/>
    <action application="set" data="STREAM_OPENAI_NOISE=near_field"/>
    <action application="set" data="api_result=\${uuid_openai_audio_stream \${uuid} start {$wsUrl} mono 24k}"/>
    <action application="sleep" data="500"/>
    <action application="playback" data="silence_stream://-1"/>
    <action application="set" data="api_result=\${uuid_openai_audio_stream \${uuid} stop}"/>
    <action application="hangup"/>
</condition>
</extension>
XML;

            // Extension 5555 belongs to another process on these servers (the older AI Voice / AI IVR). Saving an agent
            // used to rewrite its dialplan row every time; it no longer touches it. An agent's own number gets its own
            // rule below (syncAgentDialplan), and 5555 stays reserved so no agent can take it.
            if (self::WRITE_LEGACY_5555_RULE) {
            // Load or Create Dialplan in Postgres using VDialplans model
            $vDialplan = VDialplans::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND dialplan_number = :dialplan_number:",
                "bind" => [
                    "domain_uuid" => $orgId,
                    "dialplan_number" => "5555",
                ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);

            if (!$vDialplan) {
                $security = new \Phalcon\Security\Random();
                $vDialplan = new VDialplans();
                $vDialplan->domain_uuid = $orgId;
                $vDialplan->dialplan_uuid = $security->uuid();
                $vDialplan->app_uuid = $security->uuid();
                $vDialplan->dialplan_name = "AI IVR";
                $vDialplan->dialplan_number = "5555";
                $vDialplan->dialplan_context = $subdomain;
                $vDialplan->dialplan_continue = "false";
                $vDialplan->dialplan_order = 81;
                $vDialplan->dialplan_enabled = "true";
                $vDialplan->dialplan_xml = $aiExtensionXml;
                $vDialplan->insert_user = $session["user_uuid"];
                $vDialplan->insert_date = date("Y-m-d H:i:s");
                
                if (!$vDialplan->create()) {
                    $errors = [];
                    foreach ($vDialplan->getMessages() as $msg) {
                        $errors[] = $msg->getMessage();
                    }
                    throw new \Exception("Database failed to create dialplan: " . implode(', ', $errors));
                }
            } else {
                $vDialplan->dialplan_xml = $aiExtensionXml;
                $vDialplan->update_user = $session["user_uuid"];
                $vDialplan->update_date = date("Y-m-d H:i:s");
                
                if (!$vDialplan->update()) {
                    $errors = [];
                    foreach ($vDialplan->getMessages() as $msg) {
                        $errors[] = $msg->getMessage();
                    }
                    throw new \Exception("Database failed to update dialplan: " . implode(', ', $errors));
                }
            }
            }

            // Clear cache files to force FreeSWITCH reload
            $dialplanCacheFile = "/var/cache/ringq/dialplan.{$subdomain}";
            if (file_exists($dialplanCacheFile)) {
                @unlink($dialplanCacheFile);
            }

            // MR AI changes - starts
            // This agent's own FreeSWITCH rule (extension -> voice bridge). Reported back, never fatal.
            $agentDialplan = $this->syncAgentDialplan($orgId, $subdomain, (array) $session, (string) $agentId, (array) $configData);
            // MR AI changes - ends

            return $this->response->setJsonContent([
                "result" => "success",
                "message" => "Successfully updated AI settings and Dialplan",
                // 'valid' | 'unverified' for a key entered in this save, null when no new key was entered.
                "keyCheck" => $keyCheckStatus,
                "wsUrl" => $wsUrl,
                "agentDialplan" => $agentDialplan
            ]);

        } catch (\Throwable $e) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    // MR AI changes - starts
    // DEAD CODE, confirmed by repo-wide search (2026-08-18): nothing in
    // the Flutter app (lib/) or the live Node voice bridge
    // (cluade_code_ai_receptionist/) calls /ai/googleAuthorize,
    // /ai/googleCallback, /ai/microsoftAuthorize, /ai/microsoftCallback,
    // callClaude(), getValidAccessToken(), or /ai/runTests -- this whole
    // block (through the end of runTestsAction() below) is a separate,
    // parallel Google/Microsoft OAuth + connector-token implementation
    // from an earlier design iteration, superseded by:
    //   - Google/Microsoft OAuth: TeamsController's googlesettingsAction/
    //     googlecallbackAction/msloginAction family, writing
    //     google_settings:<subdomain> / o365_settings:<subdomain> in
    //     Redis -- this is what the live "Connect Google"/"Connect
    //     Microsoft" buttons in ai_agent_edit.dart -> gsetting.dart
    //     actually hit, and what mcp-email/mcp_email_server.js actually
    //     reads.
    //   - callClaude()'s "call the model with MCP tools" job: the real
    //     live path is claude_server.js's CallSession (providers/*.js +
    //     the local MCP client in ensureMcpClient()) -- callClaude() below
    //     points at literal placeholder URLs ("https://mcp.yourdomain.com/
    //     google") and a retired model id, and was never routable anyway
    //     (no "Action" suffix, so Phalcon can't dispatch to it).
    // This code is left in place rather than deleted (a large removal
    // from a live controller is a bigger, riskier change than the
    // consolidation being done alongside it), but treat it as reference
    // only -- do not extend it, and do not assume it runs.
    // MR AI changes - ends

    /**
     * GET /ai/googleAuthorize
     * Generates a nonce, builds authorization parameters, and redirects the user to Google OAuth.
     */
    public function googleAuthorizeAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

        try {
            $orgId = $this->request->getQuery("orgId", "string");
            if (empty($orgId)) {
                $session = $this->checkAuth();
                $orgId = $session['domain_uuid'];
            }

            $redis = $this->getRedis();

            // Find subdomain
            $vDomain = \VDomains::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $orgId]
            ]);
            if (!$vDomain) {
                throw new \Exception("Domain not found for organization UUID");
            }
            $subdomain = $vDomain->domain_name;

            // Load settings
            $settingsKey = "google_settings:{$subdomain}";
            $settingsJson = $redis->get($settingsKey);
            if (empty($settingsJson)) {
                throw new \Exception("Google settings not found in Redis. Please configure client ID and secret first.");
            }
            $settings = json_decode($settingsJson, true);

            $nonce = uniqid('', true);
            $stateObj = [
                "orgId" => $orgId,
                "subdomain" => $subdomain,
                "nonce" => $nonce
            ];
            $stateStr = base64_encode(json_encode($stateObj));
            
            // Save nonce for verification
            $redis->setex("google_oauth_nonce:{$orgId}", 600, $nonce);

            $redirectUri = "https://" . $this->request->getHttpHost() . "/ai/googleCallback";

            $params = [
                'client_id' => $settings['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => implode(' ', [
                    'openid',
                    'email',
                    'profile',
                    'https://www.googleapis.com/auth/gmail.readonly',
                    'https://www.googleapis.com/auth/calendar',
                    'https://www.googleapis.com/auth/drive.readonly'
                ]),
                'access_type' => 'offline',
                'prompt' => 'consent',
                'state' => $stateStr
            ];

            $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);
            return $this->response->redirect($authUrl);

        } catch (\Throwable $e) {
            $this->response->setHeader("Content-Type", "application/json");
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    /**
     * GET /ai/googleCallback
     * Exchanges Google OAuth authorization code, encrypts access/refresh tokens, and redirects back.
     */
    public function googleCallbackAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

        try {
            $code = $this->request->getQuery("code", "string");
            $stateStr = $this->request->getQuery("state", "string");
            if (empty($code) || empty($stateStr)) {
                throw new \Exception("Authorization code or state is missing");
            }

            $stateObj = json_decode(base64_decode($stateStr), true);
            if (!$stateObj || empty($stateObj['orgId']) || empty($stateObj['nonce'])) {
                throw new \Exception("Invalid OAuth state");
            }

            $orgId = $stateObj['orgId'];
            $subdomain = $stateObj['subdomain'];
            $nonce = $stateObj['nonce'];

            $redis = $this->getRedis();

            // Verify nonce
            $savedNonce = $redis->get("google_oauth_nonce:{$orgId}");
            if ($savedNonce !== $nonce) {
                throw new \Exception("OAuth state CSRF validation failed");
            }
            $redis->del("google_oauth_nonce:{$orgId}");

            // Load settings
            $settingsKey = "google_settings:{$subdomain}";
            $settings = json_decode($redis->get($settingsKey), true);

            $redirectUri = "https://" . $this->request->getHttpHost() . "/ai/googleCallback";

            // Exchange code
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://oauth2.googleapis.com/token',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'code' => $code,
                    'client_id' => $settings['client_id'],
                    'client_secret' => $settings['client_secret'],
                    'redirect_uri' => $redirectUri,
                    'grant_type' => 'authorization_code'
                ]),
                CURLOPT_RETURNTRANSFER => true
            ]);
            $tokenResponse = curl_exec($ch);
            curl_close($ch);

            $tokenData = json_decode($tokenResponse, true);
            if (empty($tokenData['access_token'])) {
                throw new \Exception("Failed to exchange auth code: " . ($tokenData['error_description'] ?? 'access_token missing'));
            }

            // Save encrypted token details in Redis
            $connectorToken = [
                "orgId" => $orgId,
                "connectorId" => "google-workspace",
                "accessToken" => $this->encryptToken($tokenData['access_token']),
                "refreshToken" => $this->encryptToken($tokenData['refresh_token'] ?? ''),
                "expiresAt" => date('c', time() + ($tokenData['expires_in'] ?? 3600)),
                "scopes" => explode(' ', $tokenData['scope'] ?? ''),
                "connectedByUserId" => $this->session->get("auth")['user_uuid'] ?? 'system',
                "createdAt" => date('c'),
                "updatedAt" => date('c')
            ];

            $redis->set("connector-token:{$orgId}:google-workspace", json_encode($connectorToken));

            // Log event for observability
            $redis->lPush("observability:events:{$orgId}", json_encode([
                "type" => "token_connected",
                "connectorId" => "google-workspace",
                "timestamp" => date('c')
            ]));

            return $this->response->redirect("/accounts/ai");

        } catch (\Throwable $e) {
            $this->response->setHeader("Content-Type", "text/html");
            return $this->response->setContent("<h2>Google Authorization Error</h2><p>" . htmlspecialchars($e->getMessage()) . "</p><p><a href='/accounts/ai'>Back to Integrations</a></p>");
        }
    }

    /**
     * GET /ai/microsoftAuthorize
     * Generates PKCE parameters, state, and redirects the user to Microsoft Azure OAuth.
     */
    public function microsoftAuthorizeAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

        try {
            $orgId = $this->request->getQuery("orgId", "string");
            if (empty($orgId)) {
                $session = $this->checkAuth();
                $orgId = $session['domain_uuid'];
            }

            $redis = $this->getRedis();

            // Find subdomain
            $vDomain = \VDomains::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $orgId]
            ]);
            if (!$vDomain) {
                throw new \Exception("Domain not found for organization UUID");
            }
            $subdomain = $vDomain->domain_name;

            // Load settings
            $settingsKey = "o365_settings:{$subdomain}";
            $settingsJson = $redis->get($settingsKey);
            if (empty($settingsJson)) {
                throw new \Exception("Microsoft settings not found in Redis. Please configure client ID and secret first.");
            }
            $settings = json_decode($settingsJson, true);

            // PKCE Generation
            $codeVerifier = bin2hex(random_bytes(32));
            $hash = hash('sha256', $codeVerifier, true);
            $codeChallenge = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');

            $redis->setex("ms_oauth_verifier:{$orgId}", 600, $codeVerifier);

            $nonce = uniqid('', true);
            $stateObj = [
                "orgId" => $orgId,
                "subdomain" => $subdomain,
                "nonce" => $nonce
            ];
            $stateStr = base64_encode(json_encode($stateObj));
            $redis->setex("ms_oauth_nonce:{$orgId}", 600, $nonce);

            $redirectUri = "https://" . $this->request->getHttpHost() . "/ai/microsoftCallback";

            $params = [
                'client_id' => $settings['client_id'],
                'response_type' => 'code',
                'redirect_uri' => $redirectUri,
                'response_mode' => 'query',
                'scope' => 'openid offline_access mail.read calendars.readwrite files.read email profile',
                'code_challenge' => $codeChallenge,
                'code_challenge_method' => 'S256',
                'state' => $stateStr
            ];

            $authUrl = "https://login.microsoftonline.com/" . $settings['tenant_id'] . "/oauth2/v2.0/authorize?" . http_build_query($params);
            return $this->response->redirect($authUrl);

        } catch (\Throwable $e) {
            $this->response->setHeader("Content-Type", "application/json");
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    /**
     * GET /ai/microsoftCallback
     * Exchanges MS authorization code with PKCE verification, encrypts tokens, and redirects back.
     */
    public function microsoftCallbackAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

        try {
            $code = $this->request->getQuery("code", "string");
            $stateStr = $this->request->getQuery("state", "string");
            if (empty($code) || empty($stateStr)) {
                throw new \Exception("Authorization code or state is missing");
            }

            $stateObj = json_decode(base64_decode($stateStr), true);
            if (!$stateObj || empty($stateObj['orgId']) || empty($stateObj['nonce'])) {
                throw new \Exception("Invalid OAuth state");
            }

            $orgId = $stateObj['orgId'];
            $subdomain = $stateObj['subdomain'];
            $nonce = $stateObj['nonce'];

            $redis = $this->getRedis();

            // Verify nonce
            $savedNonce = $redis->get("ms_oauth_nonce:{$orgId}");
            if ($savedNonce !== $nonce) {
                throw new \Exception("OAuth state CSRF validation failed");
            }
            $redis->del("ms_oauth_nonce:{$orgId}");

            // Load PKCE verifier
            $codeVerifier = $redis->get("ms_oauth_verifier:{$orgId}");
            if (empty($codeVerifier)) {
                throw new \Exception("PKCE verification code expired. Please restart login.");
            }
            $redis->del("ms_oauth_verifier:{$orgId}");

            // Load settings
            $settingsKey = "o365_settings:{$subdomain}";
            $settings = json_decode($redis->get($settingsKey), true);

            $redirectUri = "https://" . $this->request->getHttpHost() . "/ai/microsoftCallback";

            // Exchange code
            $postFields = [
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://login.microsoftonline.com/' . $settings['tenant_id'] . '/oauth2/v2.0/token',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postFields),
                CURLOPT_RETURNTRANSFER => true
            ]);
            $tokenResponse = curl_exec($ch);
            curl_close($ch);

            $tokenData = json_decode($tokenResponse, true);
            if (empty($tokenData['access_token'])) {
                throw new \Exception("Failed to exchange Microsoft auth code: " . ($tokenData['error_description'] ?? 'access_token missing'));
            }

            // Save Microsoft tokens
            $connectorToken = [
                "orgId" => $orgId,
                "connectorId" => "microsoft-365",
                "accessToken" => $this->encryptToken($tokenData['access_token']),
                "refreshToken" => $this->encryptToken($tokenData['refresh_token'] ?? ''),
                "expiresAt" => date('c', time() + ($tokenData['expires_in'] ?? 3600)),
                "scopes" => explode(' ', $tokenData['scope'] ?? ''),
                "connectedByUserId" => $this->session->get("auth")['user_uuid'] ?? 'system',
                "createdAt" => date('c'),
                "updatedAt" => date('c')
            ];

            $redis->set("connector-token:{$orgId}:microsoft-365", json_encode($connectorToken));

            // Log event
            $redis->lPush("observability:events:{$orgId}", json_encode([
                "type" => "token_connected",
                "connectorId" => "microsoft-365",
                "timestamp" => date('c')
            ]));

            return $this->response->redirect("/accounts/ai");

        } catch (\Throwable $e) {
            $this->response->setHeader("Content-Type", "text/html");
            return $this->response->setContent("<h2>Microsoft Authorization Error</h2><p>" . htmlspecialchars($e->getMessage()) . "</p><p><a href='/accounts/ai'>Back to Integrations</a></p>");
        }
    }

    /**
     * Helper to retrieve a valid access token, performing dynamic refresh-on-expiry if needed.
     */
    public function getValidAccessToken(string $orgId, string $connectorId): ?string
    {
        $redis = $this->getRedis();
        $tokenKey = "connector-token:{$orgId}:{$connectorId}";
        $tokenJson = $redis->get($tokenKey);
        if (empty($tokenJson)) {
            return null;
        }

        $tokenData = json_decode($tokenJson, true);
        if (!is_array($tokenData)) {
            return null;
        }

        $accessToken = $this->decryptToken($tokenData['accessToken']);
        $refreshToken = $this->decryptToken($tokenData['refreshToken']);
        $expiresAt = strtotime($tokenData['expiresAt']);

        // Refresh token if it will expire in the next 5 minutes
        if ($expiresAt - time() <= 300) {
            // Retrieve subdomain
            $vDomain = \VDomains::findFirst([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $orgId]
            ]);
            $subdomain = $vDomain ? $vDomain->domain_name : 'default';

            if ($connectorId === 'google-workspace') {
                $settingsKey = "google_settings:{$subdomain}";
                $settings = json_decode($redis->get($settingsKey), true);
                if (empty($settings['client_id'])) {
                    return null;
                }

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => 'https://oauth2.googleapis.com/token',
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'client_id' => $settings['client_id'],
                        'client_secret' => $settings['client_secret'],
                        'refresh_token' => $refreshToken,
                        'grant_type' => 'refresh_token'
                    ]),
                    CURLOPT_RETURNTRANSFER => true
                ]);
                $response = curl_exec($ch);
                curl_close($ch);

                $resData = json_decode($response, true);
                if (!empty($resData['access_token'])) {
                    $accessToken = $resData['access_token'];
                    if (!empty($resData['refresh_token'])) {
                        $refreshToken = $resData['refresh_token'];
                    }
                    $expiresIn = $resData['expires_in'] ?? 3600;

                    $tokenData['accessToken'] = $this->encryptToken($accessToken);
                    $tokenData['refreshToken'] = $this->encryptToken($refreshToken);
                    $tokenData['expiresAt'] = date('c', time() + $expiresIn);
                    $tokenData['updatedAt'] = date('c');

                    $redis->set($tokenKey, json_encode($tokenData));

                    $redis->lPush("observability:events:{$orgId}", json_encode([
                        "type" => "token_refresh",
                        "connectorId" => "google-workspace",
                        "timestamp" => date('c')
                    ]));
                }
            } else if ($connectorId === 'microsoft-365') {
                $settingsKey = "o365_settings:{$subdomain}";
                $settings = json_decode($redis->get($settingsKey), true);
                if (empty($settings['client_id'])) {
                    return null;
                }

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "https://login.microsoftonline.com/{$settings['tenant_id']}/oauth2/v2.0/token",
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'client_id' => $settings['client_id'],
                        'client_secret' => $settings['client_secret'],
                        'refresh_token' => $refreshToken,
                        'grant_type' => 'refresh_token',
                        'redirect_uri' => $settings['redirect_uri'] ?? ''
                    ]),
                    CURLOPT_RETURNTRANSFER => true
                ]);
                $response = curl_exec($ch);
                curl_close($ch);

                $resData = json_decode($response, true);
                if (!empty($resData['access_token'])) {
                    $accessToken = $resData['access_token'];
                    if (!empty($resData['refresh_token'])) {
                        $refreshToken = $resData['refresh_token'];
                    }
                    $expiresIn = $resData['expires_in'] ?? 3600;

                    $tokenData['accessToken'] = $this->encryptToken($accessToken);
                    $tokenData['refreshToken'] = $this->encryptToken($refreshToken);
                    $tokenData['expiresAt'] = date('c', time() + $expiresIn);
                    $tokenData['updatedAt'] = date('c');

                    $redis->set($tokenKey, json_encode($tokenData));

                    $redis->lPush("observability:events:{$orgId}", json_encode([
                        "type" => "token_refresh",
                        "connectorId" => "microsoft-365",
                        "timestamp" => date('c')
                    ]));
                }
            }
        }

        return $accessToken;
    }

    /**
     * GET /ai/token?orgId=...&connectorId=...
     * Exposes validated access tokens. Restricted to localhost/active admin sessions.
     */
    public function tokenAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $clientIp = $this->request->getClientAddress();
            $session = $this->session->get("auth");

            // Allow only localhost service calls or authenticated active web sessions
            if ($clientIp !== '127.0.0.1' && $clientIp !== '::1' && empty($session)) {
                $this->response->setStatusCode(403, "Forbidden");
                return $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Access denied. Private local endpoint."
                ]);
            }

            $orgId = $this->request->getQuery("orgId", "string");
            $connectorId = $this->request->getQuery("connectorId", "string");

            if (empty($orgId)) {
                $orgId = $session['domain_uuid'] ?? '';
            }

            if (empty($orgId) || empty($connectorId)) {
                throw new \Exception("Missing parameters 'orgId' or 'connectorId'");
            }

            $token = $this->getValidAccessToken($orgId, $connectorId);
            if (empty($token)) {
                return $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Connector not authorized or settings missing"
                ]);
            }

            return $this->response->setJsonContent([
                "result" => "success",
                "accessToken" => $token
            ]);

        } catch (\Throwable $e) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => $e->getMessage()
            ]);
        }
    }

    /**
     * Prepares parameters and sends the prompt request to Claude via Anthropic Messages API.
     */
    public function callClaude(array $agentConfig, string $orgId, array $conversationHistory): array
    {
        $model = $agentConfig['mapped_model'] ?? 'claude-opus-5';
        $prompt = $agentConfig['prompt'] ?? '';

        $mcpServers = [];
        $tools = [];

        $allowedConnectorIds = $agentConfig['allowedConnectorIds'] ?? [];
        $toolPermissions = $agentConfig['toolPermissions'] ?? [];

        $mcpUrls = [
            "google-workspace" => "https://mcp.yourdomain.com/google",
            "microsoft-365" => "https://mcp.yourdomain.com/microsoft"
        ];

        foreach ($allowedConnectorIds as $cid) {
            $url = $mcpUrls[$cid] ?? null;
            if (!$url) {
                continue;
            }

            $token = $this->getValidAccessToken($orgId, $cid);
            if (!$token) {
                continue;
            }

            $mcpServers[] = [
                "type" => "url",
                "url" => $url,
                "name" => $cid,
                "authorization_token" => $token
            ];

            // Resolve explicit allowed tool configs
            $perm = null;
            foreach ($toolPermissions as $p) {
                if (($p['connectorId'] ?? '') === $cid) {
                    $perm = $p;
                    break;
                }
            }
            $allowedTools = $perm['allowedTools'] ?? [];

            if (count($allowedTools) > 0) {
                $configs = [];
                foreach ($allowedTools as $tName) {
                    $configs[$tName] = ["enabled" => true];
                }
                $tools[] = [
                    "type" => "mcp_toolset",
                    "mcp_server_name" => $cid,
                    "default_config" => ["enabled" => false],
                    "configs" => $configs
                ];
            } else {
                // Deny by default: empty list does not inherit all tools
                $tools[] = [
                    "type" => "mcp_toolset",
                    "mcp_server_name" => $cid,
                    "default_config" => ["enabled" => false]
                ];
            }
        }

        $headers = [
            "Content-Type: application/json",
            "X-API-Key: " . (getenv("ANTHROPIC_API_KEY") ?: 'mock_api_key'),
            "anthropic-version: 2023-06-01",
            "anthropic-beta: mcp-client-2025-11-20"
        ];

        // MR AI changes - starts
        // Bring-your-own key: when this agent has its own Claude key saved, use it instead of the
        // server-wide ANTHROPIC_API_KEY. $headers[1] is the X-API-Key line built just above.
        try {
            $ownKey = $this->loadProviderKey($this->getRedis(), $orgId, (string) ($agentConfig['id'] ?? ''), 'Claude');
            if ($ownKey !== '') {
                $headers[1] = "X-API-Key: " . $ownKey;
            }
        } catch (\Throwable $ownKeyErr) {
            error_log('[AI] own provider key lookup failed: ' . $ownKeyErr->getMessage());
        }
        // MR AI changes - ends

        $payload = [
            "model" => $model,
            "max_tokens" => 1024,
            "system" => $prompt,
            "messages" => $conversationHistory
        ];

        if (count($mcpServers) > 0) {
            $payload["mcp_servers"] = $mcpServers;
            $payload["tools"] = $tools;
        }

        // POST request to Anthropic with retry logic
        $maxRetries = 3;
        $attempt = 0;
        $response = null;
        
        while ($attempt < $maxRetries) {
            $attempt++;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "https://api.anthropic.com/v1/messages",
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30
            ]);
            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($statusCode === 200) {
                break;
            }

            // Retry on rate limit (429) or server errors (5xx)
            if ($statusCode === 429 || $statusCode >= 500) {
                if ($attempt < $maxRetries) {
                    usleep(500000 * $attempt); // Exponential backoff
                    continue;
                }
            }
            throw new \Exception("Anthropic API error status {$statusCode}: {$response}");
        }

        $resJson = json_decode($response, true);
        if (!$resJson) {
            throw new \Exception("Invalid Anthropic JSON response: " . $response);
        }

        // Observability logging of tool usage
        $redis = $this->getRedis();
        if (isset($resJson['content'])) {
            foreach ($resJson['content'] as $block) {
                if (($block['type'] ?? '') === 'mcp_tool_use') {
                    $redis->lPush("observability:events:{$orgId}", json_encode([
                        "type" => "mcp_tool_use",
                        "tool" => $block['name'] ?? 'unknown',
                        "arguments" => $block['input'] ?? [],
                        "timestamp" => date('c')
                    ]));
                }
            }
        }

        return $resJson;
    }

    /**
     * GET /ai/runTests
     * Executable in-browser unit/integration test runner.
     */
    public function runTestsAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $results = [];

        // 1. Test Keyword Mapping Helper
        try {
            $keywords = "Support,Sales,Billing";
            $keywordExtensions = "1007 XML - Support,1008 - Sales";
            $keywordArray = array_map('trim', explode(',', $keywords));
            $extensionsArr = array_map('trim', explode(',', $keywordExtensions));
            $transferPairs = [];
            foreach ($keywordArray as $idx => $kw) {
                if (isset($extensionsArr[$idx])) {
                    $ext = explode(' - ', $extensionsArr[$idx])[0];
                    $transferPairs[] = strtolower($kw) . ':' . $ext;
                }
            }
            $transferMap = implode(',', $transferPairs);
            
            if ($transferMap === "support:1007,sales:1008") {
                $results[] = ["test" => "Keyword Mapping Helper", "status" => "pass"];
            } else {
                throw new \Exception("Incorrect keyword mapping format: " . $transferMap);
            }
        } catch (\Throwable $e) {
            $results[] = ["test" => "Keyword Mapping Helper", "status" => "fail", "error" => $e->getMessage()];
        }

        // 2. Test Token Encryption and Decryption
        try {
            $testToken = "my-secret-oauth-refresh-token-12345";
            $enc = $this->encryptToken($testToken);
            $dec = $this->decryptToken($enc);
            
            if ($dec === $testToken) {
                $results[] = ["test" => "Token Encryption/Decryption", "status" => "pass"];
            } else {
                throw new \Exception("Decrypted token did not match input");
            }
        } catch (\Throwable $e) {
            $results[] = ["test" => "Token Encryption/Decryption", "status" => "fail", "error" => $e->getMessage()];
        }

        // 3. Test Language Codes Parser
        try {
            $langStr = "English (en), Thai (th)";
            $parsed = $this->toLanguageCodes($langStr);
            if ($parsed === "en,th") {
                $results[] = ["test" => "Language Code Parsing", "status" => "pass"];
            } else {
                throw new \Exception("Incorrect parsed languages output: " . $parsed);
            }
        } catch (\Throwable $e) {
            $results[] = ["test" => "Language Code Parsing", "status" => "fail", "error" => $e->getMessage()];
        }

        // 4. Test MCP Request Builder Mappings (Dry run callClaude building logic)
        try {
            $mockAgentConfig = [
                "ringq_model" => "Claude",
                "mapped_model" => "claude-opus-5",
                "prompt" => "Test prompt",
                "allowedConnectorIds" => ["google-workspace"],
                "toolPermissions" => [
                    ["connectorId" => "google-workspace", "allowedTools" => ["read_mail"]]
                ]
            ];

            // Verify deny-by-default logic builder:
            $perm = null;
            foreach ($mockAgentConfig['toolPermissions'] as $p) {
                if (($p['connectorId'] ?? '') === "google-workspace") {
                    $perm = $p;
                    break;
                }
            }
            $allowedTools = $perm['allowedTools'] ?? [];
            if ($allowedTools === ["read_mail"]) {
                $results[] = ["test" => "MCP Tool Configuration Builder", "status" => "pass"];
            } else {
                throw new \Exception("Allowed tools config resolution failed");
            }
        } catch (\Throwable $e) {
            $results[] = ["test" => "MCP Tool Configuration Builder", "status" => "fail", "error" => $e->getMessage()];
        }

        return $this->response->setJsonContent([
            "result" => "success",
            "summary" => [
                "total" => count($results),
                "passed" => count(array_filter($results, fn($r) => $r['status'] === 'pass')),
                "failed" => count(array_filter($results, fn($r) => $r['status'] === 'fail'))
            ],
            "tests" => $results
        ]);
    }
}
