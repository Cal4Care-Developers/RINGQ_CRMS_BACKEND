<?php
declare(strict_types=1);

// MR AI changes - starts
// New controller. lib/services/connector_service.dart (Flutter) has
// called "$baseUrl/mcp/connectors" (GET list / POST save / DELETE
// /mcp/connectors/{id}) since it was written, but nothing on the backend
// ever answered that route -- every request threw, was swallowed by
// ConnectorService's own try/catch, and silently fell back to
// SharedPreferences. That meant a tenant's custom MCP connectors (their
// own CRM/ticketing/etc. MCP server -- the "type": "mcp" Connector
// entries, as opposed to the built-in google-workspace/microsoft-365
// ones that are synthesized client-side and need no backend storage)
// only ever existed on whichever browser created them. This controller
// gives that route a real, session-authenticated, per-tenant Redis-backed
// implementation, and TeamsController::resolveAgentAction (see the
// "customConnectors" field added there) is what lets claude_server.js
// actually connect to them for a call -- see ensureMcpClient() in
// claude_server.js.
//
// EXTENDED (2026-08-19): discoverAction() (real MCP tools/list discovery,
// replacing the Flutter-side fake mock generator) and connectorsAction()'s
// sync/invoke sub-actions, plus encrypt-at-rest + masked-on-read
// credential handling. See the plan doc for the full rationale.
use Phalcon\Mvc\View;

// Typed exceptions for the MCP handshake helpers below, so callers can
// map each real failure mode to a distinct, honest message instead of
// one generic "something went wrong": can't reach the server at all,
// the server rejected the credentials, the server returned an HTTP
// error, or the server responded but not with valid MCP JSON-RPC.
class McpConnectionException extends \Exception {}
class McpAuthException extends \Exception {}
class McpHttpException extends \Exception {}
class McpProtocolException extends \Exception {}
// MR AI changes - ends

/*
 * Ported from mr.ringq.ai_backend's McpController (custom MCP connectors / discovery) into
 * this server as a NEW file. This server's own McpController already exists and means
 * something else (customer-support access: settings/grants/approvals/staff), so the class is
 * renamed McpaiController, and it is reached at its own URLs: /mcpai/connectors[/{id}[/sync|
 * invoke|delete]] and /mcpai/discover (app/config/router.php is not loaded by this app, so no
 * /mcp/connectors alias exists; /mcp/* is McpController and refuses these actions).
 *
 * Delete is also accepted as POST /mcpai/connectors/{id}/delete: the web app calls :8443
 * cross-origin and that host's CORS preflight sends no Access-Control-Allow-Methods, so a
 * browser refuses a cross-origin DELETE before it is sent.
 */
class McpaiController extends ControllerBase
{
    // MR AI changes - starts
    // Short-circuits a CORS preflight (OPTIONS) request before connectorsAction's
    // own session check runs -- same fix and same reasoning as
    // TeamsController::beforeExecuteRoute (preflight requests carry no
    // credentials, so they fell into the 401 branch, and browsers treat any
    // non-2xx preflight response as a hard CORS failure). Additive only: every
    // other method still passes through to `return true` unchanged.
    //
    // Deliberately does NOT set any Access-Control-* headers -- an outer
    // layer already attaches them to every response; doing it again here
    // produced a live "Access-Control-Allow-Origin contains multiple
    // values" browser error. Only the status code needs fixing.
    public function beforeExecuteRoute(\Phalcon\Mvc\Dispatcher $dispatcher)
    {
        if ($this->request->getMethod() === 'OPTIONS') {
            $this->response->setStatusCode(200, 'OK');
            $this->response->send();
            return false;
        }
        // Not `return true`: ControllerBase::beforeExecuteRoute carries this app's ACL,
        // cross-site and role checks, and overriding it without delegating would skip them.
        return parent::beforeExecuteRoute($dispatcher);
    }
    // MR AI changes - ends

    private function checkAuth()
    {
        $session = $this->session->get("auth");
        if (!$session) {
            return null;
        }
        return $session;
    }

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

    // MR AI changes - starts
    // Copied verbatim from AiController.php's private encryptToken()/
    // decryptToken() -- same per-controller-copy pattern already used
    // across this codebase (no shared base-class helper exists for this).
    private function encryptToken(?string $text): string
    {
        if (empty($text)) {
            return '';
        }
        return $this->crypt->encryptBase64($text);
    }

    private function decryptToken(?string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }
        return rtrim($this->crypt->decryptBase64($encrypted));
    }
    // MR AI changes - ends

    /**
     * GET  /mcp/connectors             -> list this tenant's custom connectors
     * POST /mcp/connectors             -> create/update one (body = Connector.toJson())
     * DELETE /mcp/connectors/{id}      -> delete one
     * POST /mcp/connectors/{id}/sync   -> re-run discovery for a SAVED connector, refresh + persist its tools
     * POST /mcp/connectors/{id}/invoke -> not implemented yet (real invocation needs the Node-side MCP client)
     *
     * $id and $subaction are populated from whatever URI segments follow
     * the action name -- Phalcon's default router passes those as
     * positional params regardless of HTTP verb, which is also exactly
     * the shape lib/services/mcp_service.dart's refreshTools()/
     * invokeTool() already call ("$baseUrl/mcp/connectors/$id/sync" and
     * ".../invoke") -- no Flutter-side route changes needed for those.
     */
    public function connectorsAction($id = null, $subaction = null)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $session = $this->checkAuth();
            if (!$session) {
                $this->response->setStatusCode(401, "Unauthorized");
                return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
            }
            $orgId = $session['domain_uuid'];
            $redis = $this->getRedis();
            $setKey = "mcp_connectors:{$orgId}";

            // MR AI changes - starts
            if ($subaction === 'sync' && $this->request->isPost()) {
                if (empty($id)) {
                    throw new \Exception("Connector id is required");
                }
                $json = $redis->get("mcp_connector:{$orgId}:{$id}");
                if (empty($json)) {
                    throw new \Exception("Connector not found");
                }
                $connector = json_decode($json, true);
                if (!is_array($connector)) {
                    throw new \Exception("Stored connector data is corrupted");
                }

                $creds = [
                    'username'    => $connector['username'] ?? '',
                    'password'    => $this->decryptToken($connector['password'] ?? ''),
                    'apiKey'      => $this->decryptToken($connector['apiKey'] ?? ''),
                    'bearerToken' => $this->decryptToken($connector['bearerToken'] ?? ''),
                ];

                try {
                    $tools = $this->performMcpToolsList(
                        $connector['serverUrl'] ?? '',
                        $connector['authType'] ?? 'No Authentication',
                        $creds,
                        is_array($connector['headers'] ?? null) ? $connector['headers'] : []
                    );
                    $connector['availableTools'] = $tools;
                    $connector['status'] = 'Connected';
                    $connector['lastSync'] = date('c');
                    $redis->set("mcp_connector:{$orgId}:{$id}", json_encode($connector));
                    // Bare array -- matches refreshTools()'s expectation
                    // (`list.map((e) => ConnectorTool.fromJson(...))`).
                    return $this->response->setJsonContent($tools);
                } catch (\Throwable $e) {
                    $connector['status'] = 'Error';
                    $redis->set("mcp_connector:{$orgId}:{$id}", json_encode($connector));
                    $this->response->setStatusCode(502, "Bad Gateway");
                    return $this->response->setJsonContent([
                        "result"    => "error",
                        "message"   => $e->getMessage(),
                        "errorType" => $this->mcpErrorType($e),
                    ]);
                }
            }

            if ($subaction === 'invoke') {
                // Real invocation needs the same MCP client machinery the
                // Node voice bridge already has (claude_server.js's
                // ensureMcpClient()) -- deliberately deferred, not faked.
                $this->response->setStatusCode(501, "Not Implemented");
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "message" => "Tool invocation from the dashboard is not available yet.",
                ]);
            }
            // MR AI changes - ends

            if ($this->request->isDelete() || ($subaction === 'delete' && $this->request->isPost())) {
                if (empty($id)) {
                    throw new \Exception("Connector id is required");
                }
                $redis->del("mcp_connector:{$orgId}:{$id}");
                $redis->sRem($setKey, $id);
                return $this->response->setJsonContent(["result" => "success"]);
            }

            if ($this->request->isPost()) {
                $body = $this->request->getJsonRawBody(true);
                if (empty($body) || empty($body['id'])) {
                    throw new \Exception("Connector payload with an id is required");
                }
                $this->validateConnectorPayload($body); // MR AI changes

                $connectorId = $body['id'];

                // MR AI changes - starts
                // Encrypt-at-rest + "blank means unchanged": credentials
                // are never round-tripped in plain text. If the client
                // sends a blank secret field AND a connector with this id
                // already has one stored, keep the existing encrypted
                // value instead of overwriting it with blank -- same
                // pattern as "change password only if you type a new one".
                $existingJson = $redis->get("mcp_connector:{$orgId}:{$connectorId}");
                $existing = $existingJson ? json_decode($existingJson, true) : null;
                foreach (['password', 'apiKey', 'bearerToken'] as $secretField) {
                    $incoming = trim((string) ($body[$secretField] ?? ''));
                    if ($incoming !== '') {
                        $body[$secretField] = $this->encryptToken($incoming);
                    } elseif (is_array($existing) && !empty($existing[$secretField])) {
                        $body[$secretField] = $existing[$secretField];
                    } else {
                        $body[$secretField] = '';
                    }
                }
                // MR AI changes - ends

                $redis->set("mcp_connector:{$orgId}:{$connectorId}", json_encode($body));
                $redis->sAdd($setKey, $connectorId);
                return $this->response->setJsonContent([
                    "result"    => "success",
                    "connector" => $this->maskConnectorForResponse($body), // MR AI changes
                ]);
            }

            // GET -- Flutter's ConnectorService.getConnectors() does
            // `jsonDecode(response.body)` straight into a List, so this
            // returns a bare JSON array, not the {result, data} envelope
            // most other endpoints in this app use.
            $ids = $redis->sMembers($setKey);
            $connectors = [];
            foreach ((array) $ids as $cid) {
                $json = $redis->get("mcp_connector:{$orgId}:{$cid}");
                if (!empty($json)) {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $connectors[] = $this->maskConnectorForResponse($decoded); // MR AI changes
                    }
                }
            }
            return $this->response->setJsonContent($connectors);

        } catch (\Throwable $e) {
            $this->response->setStatusCode(500, "Internal Error");
            return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
        }
    }

    // MR AI changes - starts
    /**
     * POST /mcp/discover
     *
     * Real-time "Test Connection" check for a connector's CURRENT,
     * UNSAVED form values (serverUrl/authType/credentials/headers) --
     * never touches Redis. This is what replaces the old client-side
     * fake mock tool generator: lib/services/mcp_service.dart's
     * discoverTools() already posts to exactly this route.
     */
    public function discoverAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            $session = $this->checkAuth();
            if (!$session) {
                $this->response->setStatusCode(401, "Unauthorized");
                return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
            }

            if (!$this->request->isPost()) {
                throw new \Exception("Invalid request method");
            }

            $body = $this->request->getJsonRawBody(true) ?: [];
            $serverUrl = trim((string) ($body['serverUrl'] ?? ''));
            $authType = (string) ($body['authType'] ?? 'No Authentication');

            if (empty($serverUrl) || !filter_var($serverUrl, FILTER_VALIDATE_URL)) {
                $this->response->setStatusCode(400, "Bad Request");
                return $this->response->setJsonContent(["result" => "error", "message" => "A valid Server URL is required"]);
            }

            $creds = [
                'username'    => $body['username'] ?? '',
                'password'    => $body['password'] ?? '',
                'apiKey'      => $body['apiKey'] ?? '',
                'bearerToken' => $body['bearerToken'] ?? '',
            ];
            $headers = is_array($body['headers'] ?? null) ? $body['headers'] : [];

            try {
                $tools = $this->performMcpToolsList($serverUrl, $authType, $creds, $headers);
                // Bare array -- matches discoverTools()'s expectation.
                return $this->response->setJsonContent($tools);
            } catch (\Throwable $e) {
                $this->response->setStatusCode(502, "Bad Gateway");
                return $this->response->setJsonContent([
                    "result"    => "error",
                    "message"   => $e->getMessage(),
                    "errorType" => $this->mcpErrorType($e),
                ]);
            }

        } catch (\Throwable $e) {
            $this->response->setStatusCode(500, "Internal Error");
            return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
        }
    }

    /**
     * Real MCP tools/list discovery over the server's Streamable HTTP
     * transport -- plain JSON-RPC 2.0 over POST to one URL, no SDK
     * needed. The "initialize" handshake is attempted best-effort (some
     * strict servers expect it; our own mcp_email_server.js -- a fresh,
     * stateless McpServer per HTTP request -- doesn't need it at all),
     * so only the final tools/list call's success/shape is fatal; that
     * call alone still surfaces a real connection/auth/HTTP failure just
     * as reliably, since a genuinely broken server fails it the same way.
     */
    private function performMcpToolsList(string $serverUrl, string $authType, array $creds, array $headers = []): array
    {
        $authHeaders = $this->buildAuthHeaders($authType, $creds);
        $allHeaders = array_merge($authHeaders, $this->flattenHeaders($headers));

        try {
            $this->sendMcpRequest($serverUrl, $allHeaders, [
                "jsonrpc" => "2.0",
                "id"      => 1,
                "method"  => "initialize",
                "params"  => [
                    "protocolVersion" => "2024-11-05",
                    "capabilities"    => (object) [],
                    "clientInfo"      => ["name" => "RingQ", "version" => "1.0.0"],
                ],
            ]);
            $this->sendMcpRequest($serverUrl, $allHeaders, [
                "jsonrpc" => "2.0",
                "method"  => "notifications/initialized",
            ]);
        } catch (\Throwable $e) {
            // best-effort -- see doc comment above
        }

        $listRaw = $this->sendMcpRequest($serverUrl, $allHeaders, [
            "jsonrpc" => "2.0",
            "id"      => 2,
            "method"  => "tools/list",
            "params"  => (object) [],
        ]);
        $listResult = $this->parseMcpResponseBody($listRaw);

        $tools = $listResult['result']['tools'] ?? null;
        if (!is_array($tools)) {
            throw new McpProtocolException("The server responded, but did not return a valid list of tools.");
        }

        // Shape already matches ConnectorTool.fromJson() in connector.dart
        // -- name/description/inputSchema -- zero translation needed.
        return array_map(function ($t) {
            return [
                "name"        => $t['name'] ?? '',
                "description" => $t['description'] ?? '',
                "inputSchema" => $t['inputSchema'] ?? (object) [],
            ];
        }, $tools);
    }

    /**
     * One JSON-RPC POST over cURL. Throws a distinct, typed exception per
     * failure mode -- unreachable/timeout, credentials rejected, other
     * HTTP error -- so the caller can surface an honest, specific message
     * instead of one generic failure.
     */
    private function sendMcpRequest(string $url, array $headers, array $jsonRpcPayload): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array_merge(
                ["Content-Type: application/json", "Accept: application/json, text/event-stream"],
                $headers
            ),
            CURLOPT_POSTFIELDS     => json_encode($jsonRpcPayload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new McpConnectionException("Could not reach the server: {$error}");
        }
        if ($status === 401 || $status === 403) {
            throw new McpAuthException("The server rejected the provided credentials (HTTP {$status}).");
        }
        if ($status < 200 || $status >= 300) {
            throw new McpHttpException("The server returned an unexpected HTTP {$status} response.");
        }
        return (string) $raw;
    }

    /**
     * The response body may be a single JSON object OR a text/event-stream
     * body (one or more "data: {...}" lines) -- MCP's Streamable HTTP
     * transport allows either depending on the server implementation.
     * Try plain JSON first, then fall back to the LAST decodable "data:"
     * line (the final JSON-RPC response for this request).
     */
    private function parseMcpResponseBody(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw new McpProtocolException("The server returned an empty response.");
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $lines = explode("\n", $trimmed);
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (str_starts_with($line, 'data:')) {
                $candidate = trim(substr($line, 5));
                $decodedLine = json_decode($candidate, true);
                if (is_array($decodedLine)) {
                    return $decodedLine;
                }
            }
        }

        throw new McpProtocolException("The server's response was not valid JSON-RPC.");
    }

    private function buildAuthHeaders(string $authType, array $creds): array
    {
        switch ($authType) {
            case 'Bearer Token':
                return !empty($creds['bearerToken']) ? ["Authorization: Bearer {$creds['bearerToken']}"] : [];
            case 'API Key':
                return !empty($creds['apiKey']) ? ["X-API-Key: {$creds['apiKey']}"] : [];
            case 'Basic Auth':
                if (!empty($creds['username']) || !empty($creds['password'])) {
                    $encoded = base64_encode(($creds['username'] ?? '') . ':' . ($creds['password'] ?? ''));
                    return ["Authorization: Basic {$encoded}"];
                }
                return [];
            default:
                return []; // 'No Authentication' (or anything unrecognized)
        }
    }

    private function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            if (is_string($key) && $key !== '') {
                $out[] = "{$key}: {$value}";
            }
        }
        return $out;
    }

    private function mcpErrorType(\Throwable $e): string
    {
        if ($e instanceof McpConnectionException) return 'connection';
        if ($e instanceof McpAuthException) return 'auth';
        if ($e instanceof McpHttpException) return 'http';
        if ($e instanceof McpProtocolException) return 'protocol';
        return 'unknown';
    }

    /**
     * Strips real secret values from a connector before it's ever
     * returned to the client -- replaced with hasPassword/hasApiKey/
     * hasBearerToken booleans so the edit dialog can show "credential
     * set, leave blank to keep" instead of the actual (encrypted) value.
     */
    private function maskConnectorForResponse(array $connector): array
    {
        foreach (['password', 'apiKey', 'bearerToken'] as $field) {
            $connector["has" . ucfirst($field)] = !empty($connector[$field] ?? '');
            $connector[$field] = '';
        }
        return $connector;
    }

    private function validateConnectorPayload(array $body): void
    {
        if (empty($body['name'])) {
            throw new \Exception("Connector name is required");
        }
        if (empty($body['serverUrl']) || !filter_var($body['serverUrl'], FILTER_VALIDATE_URL)) {
            throw new \Exception("A valid Server URL is required");
        }
        $validAuthTypes = ['No Authentication', 'Bearer Token', 'API Key', 'Basic Auth', 'None'];
        // 'None' tolerated too -- back-compat with the built-in google/
        // microsoft synthesized entries' authType value, even though
        // those never actually reach this save path today.
        if (!empty($body['authType']) && !in_array($body['authType'], $validAuthTypes, true)) {
            throw new \Exception("Invalid authentication type");
        }
    }
    // MR AI changes - ends
}
