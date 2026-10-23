<?php

declare(strict_types=1);
use ElephantIO\Client;

use Phalcon\Mvc\View;
use Phalcon\Db\Column;
class ApichatController extends ControllerBase
{
    /**
     * Whether error responses carry their real HTTP status code.
     *
     * This API answers every request with HTTP 200 and reports success or
     * failure in the body, so clients switch on `result` rather than on the
     * status line. Flip this to true to emit real 4xx/5xx codes instead —
     * every respondWithError() call site already passes the correct one.
     */
    private const USE_HTTP_STATUS_CODES = false;

    /**
     * Read an application/x-www-form-urlencoded request body.
     *
     * Phalcon exposes POST form fields through getPost(). DELETE bodies are
     * parsed explicitly because PHP does not populate $_POST for them. JSON is
     * retained as a fallback for provider webhooks and older API clients.
     */
    private function getRequestData(): array
    {
        $data = $this->request->getPost();
        if (is_array($data) && !empty($data)) {
            return $data;
        }

        $rawBody = $this->request->getRawBody();
        $contentType = strtolower((string) $this->request->getHeader('Content-Type'));
        if ($rawBody !== '' && strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($rawBody, $formData);
            return is_array($formData) ? $formData : [];
        }

        $jsonData = $this->request->getJsonRawBody(true);
        return is_array($jsonData) ? $jsonData : [];
    }

    public function beforeExecuteRoute($dispatcher)
    {
        // Security headers
        $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        $this->response->setHeader('X-Frame-Options', 'DENY');
        $this->response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $this->response->setHeader('Expires', '0');
        $this->response->setHeader('Pragma', 'no-cache');

        // 2026-08-25 09:44 PST: CORS headers are added by Nginx. Do not add
        // them here too: duplicate Access-Control-Allow-Origin values cause
        // browsers to reject an otherwise successful response as a CORS error.

        if ($this->request->isOptions()) {
            // 2026-08-25 09:44 PST: Stop before endpoint authentication; Nginx
            // adds the configured CORS headers to this 204 response.
            $this->response->setStatusCode(204);
            $this->response->send();
            return false;
        }
    }


    private function latestDataNew($page = 1, $searching = "", $owner = "", $all = "true", $type = "", $email_Agent = "", $username_Agent = "", $count = 0)
    {
        $limit = intval($count);
        $offset = 0;
        $session = $this->session->get("auth");

        // Fallback: If session subdomain is missing, resolve host domain from request header
        $hostdomain = $session["subdomain"] ?? null;
        if (empty($hostdomain)) {
            $host = $this->request->getHttpHost();
            $hostdomain = parse_url('http://' . $host, PHP_URL_HOST) ?: $host;
        }

        $new_unread = [];
        $total_count = 0;
        $total_24hrs = 0;
        $nowMs = round(microtime(true) * 1000);
        $threshold24hr = $nowMs - (24 * 60 * 60 * 1000);
        $threshold24h = $threshold24hr / 1000;
        $assignedCustomersData = [];

        $agentNewKey = $owner . "-Agent-" . $username_Agent . "-" . $email_Agent;
        if ($searching != "") {
            $type = "4";
        }

        if ($all === "true") {
            // Use the resolved hostdomain to build the dynamic key prefix
            $key = !empty($hostdomain) ? $hostdomain . "-conversation" : "demo.us1.ringq.ai-conversation";

            // Fallback so an empty or unrecognised $type can never leave $newKey
            // undefined for the Redis calls below. Matches type "0" (all).
            $newKey = $key;

            if ($type === "0") {
                $newKey = $key;
            } else if ($type === "1") {
                $newKey = $key . "-new";
            } else if ($type === "2") {
                $newKey = $key . "-active";
            } else if ($type === "3") {
                $newKey = $key . "-close";
            } else if ($type === "4") {
                $newKey = $key . "-search";
            }

            $pipe = $this->redis->pipeline();

            $totalItems = $this->redis->zCard($newKey);
            $total_count = $totalItems;

            if ($totalItems > 0) {
                // Compute offset based on page count
                $totalPages = ceil($totalItems / $limit);
                // Preserve out-of-range pages so they return empty results.
                $page = max(1, (int) $page);
                $offset = ($page - 1) * $limit;
                $end = $offset + $limit - 1;

                if ($searching != "") {
                    // 1. Get all members from Redis
                    $query = $searching;
                    error_log("searching: $searching");

                    // Hardcode the brackets for a sanity check
                    $members = $this->redis->zRangeByLex($newKey, "[" . $query, "[" . $query . "\xff", ['limit' => [$offset, $count]]);

                    $client_data = array_fill_keys($members, "0");
                } else {
                    // Standard pagination
                    $client_data = $this->redis->zRevRange($newKey, $offset, $end, ['withscores' => true]);
                }

                $pipe = $this->redis->pipeline();
                $items = [];
                foreach ($client_data as $item => $score) {
                    $items[] = [$item, $score];
                    $pipe->hGetAll($item);
                }
                $hashResults = $pipe->execute();
                $hashKeyArray = [];
                foreach ($items as $idx => [$item, $score]) {
                    $hashData = $hashResults[$idx];

                    $platform = '';
                    $userKey = "";
                    $userinbox = "";
                    $userkeydisplaying = "";
                    if (preg_match('/-(website|whatsapp|facebook|telnyx|twilio|telegram|line|generic)-/', $item, $m)) {
                        $platform = $m[1];
                        $parts = explode('-', $item);
                        $username = $parts[0] ?? "";
                        $email = $parts[1] ?? "";
                        $id = $parts[3] ?? "";
                        if ($platform === "website") {
                            $userKey = "{$id}-{$platform}-{$username}-{$email}-{$hostdomain}";
                            $userkeydisplaying = "{$id}-{$platform}-{$username}-{$email}";
                            $userinbox = "external-{$email}-{$id}";
                        } else if ($platform === "whatsapp") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "whatsapp:919894340047:{$email}-{$id}";
                        } else if ($platform === "facebook") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "facebook:919894340047:{$email}";
                        } else if ($platform === "telnyx") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "telnyx:{$email}:{$email}-{$id}";
                        } else if ($platform === "twilio") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "twilio:{$email}:{$email}-{$id}";
                        } else if ($platform === "telegram") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "telegram:{$email}:{$email}-{$id}";
                        } else if ($platform === "line") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "line:{$email}:{$email}-{$id}";
                        } else if ($platform === "generic") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userkeydisplaying = "{$email}-{$platform}-{$username}-{$id}";
                            $userinbox = "generic:{$email}:{$email}-{$id}";
                        }

                        $userData = $this->redis->hGetAll($userKey);
                        unset($userData['aiEnable']);
                        unset($userData['isReceived']);
                        unset($userData['Dept']);
                        unset($userData['assigned_agent']);
                        unset($userData['msgIndex']);
                        unset($userData['seen']);
                        unset($userData['pageId']);
                        unset($userData['socketID']);
                        unset($userData['chatUpdated']);
                        unset($userData['accessToken']);
                        unset($userData['accountSid']);
                        unset($userData['messagingServiceSid']);
                        unset($userData['number']);
                        $hashData += $userData;
                    } else {
                        $username = $email = $id = "";
                    }
                    // $hashData["_user_key"] = $userKey ?? '';
                    $hashData["_userkey"] = $userkeydisplaying ?? '';
                    // $hashData["_redis_key"] = $item;
                    // $hashData["_username"] = $username ?? '';
                    // $hashData["_email"] = $email ?? '';
                    $hashData["inbox"] = $userinbox;
                    $new_unread[] = $hashData;
                }
            }
        } else {
            $assignedCustomers = [];
            $customerResults = [];
            $hashKeyArray = [];
            $hashData = [];
            $trig = "";
            $score = 0;
            $newKey = "";
            $equivalent = "";

            $agentKey = $owner . "-Agent-" . $username_Agent . "-" . $email_Agent . "-" . $hostdomain;
            $agentData = $this->redis->hGetAll($agentKey);

            if (!empty($agentData) && isset($agentData["assigned"])) {
                $assignedCustomers = json_decode($agentData["assigned"], true);

                if (is_array($assignedCustomers)) {
                    $pipe = $this->redis->pipeline();
                    foreach ($assignedCustomers as $customerKey) {
                        $pipe->hGetAll($customerKey);
                    }
                    $customerResults = $pipe->execute();

                    foreach ($assignedCustomers as $i => $customerKey) {
                        $hashData = $customerResults[$i];
                        if (empty($hashData))
                            continue;

                        if (preg_match('/-(website|whatsapp|facebook|telnyx|twilio|telegram|line|generic)-/', $customerKey, $m)) {
                            $platform = $m[1];
                            $pos = strpos($customerKey, "-{$platform}-");
                            $rest = substr($customerKey, $pos + strlen($platform) + 2);
                            $lastDash = strrpos($rest, "-");
                            $username = substr($rest, 0, $lastDash);
                            $email = substr($rest, $lastDash + 1);

                            if (
                                $searching !== "" &&
                                stripos($username, $searching) === false &&
                                stripos($email, $searching) === false
                            )
                                continue;

                            $username = str_replace('/', '-', $username);
                        } else {
                            $username = $email = "";
                        }

                        $hashData["_redis_key"] = $customerKey;
                        $hashData["_username"] = $username;
                        $hashData["_email"] = $email;
                        $assignedCustomersData[] = $hashData;
                    }
                }
            }

            $total_count = count($assignedCustomersData);
            $totalPages = ($total_count > 0) ? ceil($total_count / $limit) : 1;
            $page = max(1, (int) $page);
            $offset = intval(($page - 1) * $limit);
            $new_unread = is_array($assignedCustomersData)
                ? array_slice($assignedCustomersData, $offset, $limit)
                : [];
        }

        $hasNext = $total_count > ($offset + $limit);
        // 2026-08-25 09:27 PST: conversationsAction exposes this value, so
        // calculate it for both the all-conversations and assigned-agent paths.
        $totalPages = ($total_count > 0 && $limit > 0)
            ? (int) ceil($total_count / $limit)
            : 1;

        return [
            "customersResult" => $customerResults ?? "",
            "page" => $page,
            "data" => $new_unread,
            "total" => $total_count,
            "next" => $hasNext ? $page + 1 : false,
            "hasMore" => $hasNext,
            "totalPages" => $totalPages,
        ];
    }

    public function conversationsAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setContentType("application/json", "UTF-8");

        $authResult = $this->validateDomainHeaderAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        // Parse application/x-www-form-urlencoded data (with JSON fallback).
        $body = $this->request->isPost() ? $this->getRequestData() : [];

        $page = max(1, (int) ($body['page'] ?? $this->request->getPost('page', 'int') ?? $this->request->getQuery('page', 'int') ?? 1));
        $type = $body['type'] ?? $this->request->getPost('type', 'string') ?? $this->request->getQuery('type', 'string') ?? '0';

        $listType = is_scalar($type) ? trim((string) $type) : '';
        if ($listType === '') {
            $listType = "0";
        }

        if (!in_array($listType, ["0", "1", "2", "3", "4"], true)) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "type must be one of 0 (all), 1 (new), 2 (active), 3 (close), 4 (search)",
            ]);
        }

        $payload = $this->latestDataNew(
            $page,         // page number
            "",            // search
            "",            // owner
            "true",        // all
            $listType,     // validated list filter, defaults to "0"
            "",            // email
            "",            // username
            10             // count
        );

        return $this->response->setJsonContent([
            "result" => "success",
            "page" => $payload["page"],
            "content" => $payload["data"],
            "total" => $payload["total"],
            "next" => $payload["next"],
            "hasMore" => $payload["hasMore"],
            "customersResult" => $payload["customersResult"] ?? [],
            "pagination" => [
                "currentPage" => $payload["page"],
                "totalRecords" => $payload["total"],
                "totalPages" => $payload["totalPages"],
            ]
        ]);
    }

    public function inboxAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setContentType("application/json", "UTF-8");

        $authResult = $this->validateDomainHeaderAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        // Parse application/x-www-form-urlencoded data (with JSON fallback).
        $body = $this->request->isPost() ? $this->getRequestData() : [];

        $inbox = $body['inbox'] ?? $this->request->getPost('inbox', 'string') ?? null;
        if (!is_string($inbox)) {
            return $this->respondWithError(400, 'inbox must be a non-empty string');
        }
        $inbox = trim($inbox, " \t\n\r\0\x0B\"");
        if ($inbox === '') {
            return $this->respondWithError(400, 'inbox is required');
        }
        $page = max(1, (int) ($body['page'] ?? $this->request->getPost('page', 'int') ?? $this->request->getQuery('page', 'int') ?? 1));

        $session = $this->session->get('auth');
        $fqdn = $session['subdomain'] ?? null;
        if (empty($fqdn)) {
            $host = $this->request->getHttpHost();
            $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: '';
        }

        // Convert generic display keys ({email}-generic-{username}-{id}) to inbox keys.
        if (preg_match('/^([^-:]+)-generic-(.+)-([^-:]+)$/', $inbox, $genericParts)) {
            $email = $genericParts[1];
            $id = $genericParts[3];
            $inboxKey = "{$fqdn}:generic:{$email}:{$email}-{$id}";
        } else {
            // Restore the domain prefix removed from conversation-list inboxes.
            $firstPart = preg_split('/[-:]/', $inbox, 2)[0];
            $separator = in_array($firstPart, ['extension', 'external'], true) ? '-' : ':';
            $inboxKey = $fqdn . $separator . $inbox;
        }

        $groupSize = 5;
        $groupsPerPage = 10;
        $limit = $groupSize * $groupsPerPage; // 50 raw items per page
        $start = ($page - 1) * $limit;
        $end = $start + $limit - 1;

        $totalRawItems = $this->redis->lLen($inboxKey);
        $totalGroups = (int) floor($totalRawItems / $groupSize);
        $totalPages = ($totalGroups > 0) ? (int) ceil($totalGroups / $groupsPerPage) : 1;

        $itemsRaw = $this->redis->lRange($inboxKey, $start, $end);
        $messages = [];

        if (is_array($itemsRaw) && !empty($itemsRaw)) {
            $groupCount = floor(count($itemsRaw) / $groupSize);

            for ($groupIndex = 0; $groupIndex < $groupCount; $groupIndex++) {
                $groupStart = $groupIndex * $groupSize;
                $groupItems = array_slice($itemsRaw, $groupStart, $groupSize);

                $senderRaw = isset($groupItems[1]) ? (string) $groupItems[1] : '';
                $thirdItem = isset($groupItems[2]) ? (string) $groupItems[2] : '';
                $fourthItem = isset($groupItems[3]) ? (string) $groupItems[3] : '';
                $messageItem = $groupItems[4] ?? null;

                // Clean timestamp from the 4th item (index 3)
                $timestamp = preg_replace('/-(Agent|Status)$/', '', $fourthItem);

                // Clean -Agent or -Status suffix from the 3rd item (index 2)
                $name = preg_replace('/-(Agent|Status)$/', '', $thirdItem);

                // Determine message source role
                $roleType = 'client';
                if (strpos($senderRaw, '-Agent') !== false || strpos($thirdItem, '-Agent') !== false) {
                    $roleType = 'agent';
                } elseif (strpos($senderRaw, '-Status') !== false || strpos($thirdItem, '-Status') !== false) {
                    $roleType = 'status';
                }

                // Parse message payload
                $messageValue = null;
                if ($messageItem !== null) {
                    $decoded = json_decode($messageItem, true);
                    $messageValue = is_array($decoded) ? $decoded : $messageItem;
                }

                $entry = [
                    'type' => $roleType,
                    'timestamp' => $timestamp,
                    'message' => $messageValue
                ];

                if ($name !== '') {
                    $entry['name'] = $name;
                }

                $messages[] = $entry;
            }
        }

        return $this->response->setJsonContent([
            'result' => 'success',
            'inboxkey' => $inboxKey,
            'inbox' => $inbox,
            'page' => $page,
            'messages' => count($messages),
            'data' => $messages,
            'pagination' => [
                'currentPage' => $page,
                'totalRecords' => $totalGroups,
                'totalPages' => $totalPages,
            ]
        ]);
    }

    private function validateDomainHeaderAuth()
    {
        $authHeader = $this->request->getHeader('Authorization');
        $token = null;
        if (!empty($authHeader)) {
            if (stripos($authHeader, 'Bearer ') === 0) {
                return $this->respondWithError(401, 'Do not use Bearer prefix; pass raw token or use accessToken');
            } else {
                $token = trim($authHeader);
            }
        }

        $session = $this->session->get('auth');
        $domain = $session['subdomain'] ?? null;
        if (empty($domain)) {
            $host = $this->request->getHttpHost();
            $domain = parse_url('http://' . $host, PHP_URL_HOST) ?: '';
        }

        if (empty($domain)) {
            return $this->respondWithError(401, 'Unable to determine domain for header auth');
        }

        $headerAuthKey = $domain . '-headerauth';
        $expectedToken = $session['api_token'] ?? $this->redis->get($headerAuthKey);

        // 1) Try header-based token if present
        if (!empty($token) && !empty($expectedToken) && hash_equals($expectedToken, $token)) {
            return true;
        }

        // 1b) If header token didn't match header auth, allow treating the raw
        // Authorization header value as an accessToken (so clients can send the
        // generated access token in the Authorization header without a prefix).
        if (!empty($token)) {
            $keyName = $domain . '-accesstoken';
            $expectedAccessToken = $this->redis->get($keyName);
            if (!empty($expectedAccessToken) && hash_equals($expectedAccessToken, $token)) {
                return true;
            }
            // fall through to other checks so we return a consistent error
        }

        // 2) Fallback: accept accessToken in JSON body or query param
        $payload = json_decode($this->request->getRawBody(), true);
        $accessToken = null;
        if (is_array($payload) && !empty($payload['accessToken'])) {
            $accessToken = trim((string) $payload['accessToken']);
        } elseif (is_array($payload) && !empty($payload['access_token'])) {
            $accessToken = trim((string) $payload['access_token']);
        } elseif ($this->request->getQuery('accessToken')) {
            $accessToken = trim((string) $this->request->getQuery('accessToken'));
        }

        if (!empty($accessToken)) {
            $keyName = $domain . '-accesstoken';
            $expectedAccessToken = $this->redis->get($keyName);
            if (!empty($expectedAccessToken) && hash_equals($expectedAccessToken, $accessToken)) {
                return true;
            }
            return $this->respondWithError(401, 'Access token validation failed');
        }

        // If we had a header token but it didn't match, or no auth provided at all,
        // return a generic authorization error.
        if (empty($token)) {
            return $this->respondWithError(401, 'Missing Authorization header or Access token');
        }

        return $this->respondWithError(401, 'Authorization validation failed');
    }

    private function validateAccessTokenAuth()
    {
        // Allow passing access token via raw Authorization header (no Bearer prefix),
        // or via JSON body/query param as before.
        $accessToken = null;
        $authHeader = $this->request->getHeader('Authorization');
        if (!empty($authHeader)) {
            if (stripos($authHeader, 'Bearer ') === 0) {
                return $this->respondWithError(401, 'Do not use Bearer prefix; pass raw accessToken in Authorization header or use body/query');
            }
            $accessToken = trim($authHeader);
        }

        if (empty($accessToken)) {
            $payload = json_decode($this->request->getRawBody(), true);
            if (is_array($payload) && !empty($payload['accessToken'])) {
                $accessToken = trim((string) $payload['accessToken']);
            } elseif (is_array($payload) && !empty($payload['access_token'])) {
                $accessToken = trim((string) $payload['access_token']);
            } elseif ($this->request->getQuery('accessToken')) {
                $accessToken = trim((string) $this->request->getQuery('accessToken'));
            }
        }

        if (empty($accessToken)) {
            return $this->respondWithError(401, 'Missing accessToken');
        }

        $session = $this->session->get('auth');
        $fqdn = $session['subdomain'] ?? null;
        if (empty($fqdn)) {
            $host = $this->request->getHttpHost();
            $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: '';
        }

        if (empty($fqdn)) {
            return $this->respondWithError(401, 'Unable to determine domain for access token');
        }

        $keyName = $fqdn . '-accesstoken';
        $expected = $this->redis->get($keyName);

        if (empty($expected)) {
            return $this->respondWithError(401, 'No access token configured for this domain');
        }

        if (!hash_equals($expected, $accessToken)) {
            return $this->respondWithError(401, 'Access token validation failed');
        }

        return true;
    }

    private function getVDomainByName($fqdn)
    {
        $fqdn = trim((string) $fqdn);
        if ($fqdn === '') {
            return null;
        }

        return VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $fqdn],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
    }

    /**
     * Proof-of-ownership check for the domain the request arrived on.
     *
     * The caller posts an "accesstoken" whose value is the domain_uuid belonging
     * to the fqdn in the request host (v_domains.domain_name ->
     * v_domains.domain_uuid). Only when the two line up may an access token be
     * minted for that domain. The posted value is never echoed back, and the
     * failure messages stay generic so they reveal nothing about it.
     *
     * Returns the matched VDomains row, or null after emitting the error JSON.
     */
    private function validateDomainUuidForFqdn($fqdn, array $data)
    {
        error_log("fqdn is $fqdn");
        $posted = null;
        foreach (['accesstoken', 'accessToken', 'access_token'] as $field) {
            if (!empty($data[$field])) {
                $posted = trim((string) $data[$field]);
                break;
            }
        }

        if (empty($posted)) {
            $this->respondWithError(400, 'access token is required');
            return null;
        }

        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $posted)) {
            $this->respondWithError(401, 'Access token validation failed');
            return null;
        }

        $vDomain = $this->getVDomainByName($fqdn);

        if (!$vDomain) {
            $this->respondWithError(401, 'Access token validation failed');
            return null;
        }

        $uuidMatches = hash_equals(strtolower((string) $vDomain->domain_uuid), strtolower((string) $posted));
        $fqdnMatches = hash_equals(strtolower((string) $vDomain->domain_name), strtolower((string) $fqdn));

        // Explicitly checking that BOTH must match:
        if (!($uuidMatches && $fqdnMatches)) {
            $this->respondWithError(401, 'Access token validation failed');
            return null;
        }

        return $vDomain;
    }

    public function accesstokenAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        $session = $this->session->get('auth');
        $fqdn = $session['subdomain'] ?? null;
        if (empty($fqdn)) {
            $host = $this->request->getHttpHost();
            $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: null;
        }

        if (empty($fqdn)) {
            return $this->respondWithError(400, 'fqdn is required to generate access token');
        }

        $keyName = $fqdn . '-accesstoken';

        // --- 1. GET METHOD (Retrieve existing access token) ---
        if ($this->request->isGet()) {
            // 2026-08-25 10:07 PST: Only an authenticated tenant admin session
            // may read the stored token. Hostname knowledge alone is not enough.
            if (!is_array($session) || empty($session['subdomain'])) {
                return $this->respondWithError(401, 'Authenticated session is required');
            }

            $sessionDomain = strtolower(trim((string) $session['subdomain']));
            if (!hash_equals($sessionDomain, strtolower(trim((string) $fqdn)))) {
                return $this->respondWithError(403, 'Session domain does not match request domain');
            }

            // 2026-08-25 11:31 PST: Match api.phtml's credential flow. The
            // long-lived credential is v_domains.domain_uuid for domain_name;
            // POST exchanges it for the short-lived Redis access token.
            $vDomain = $this->getVDomainByName($fqdn);
            if (!$vDomain || empty($vDomain->domain_uuid)) {
                return $this->respondWithError(404, 'No master token configured for this domain');
            }

            return $this->response->setJsonContent([
                'result' => 'success',
                'accessToken' => (string) $vDomain->domain_uuid,
                'masterToken' => (string) $vDomain->domain_uuid,
                'domain_name' => (string) $vDomain->domain_name
            ]);
        }

        // --- 2. POST METHOD (Create / Regenerate access token) ---
        if ($this->request->isPost()) {
            $data = $this->getRequestData();
            if (!is_array($data)) {
                $data = [];
            }

            // The posted accesstoken must belong to the fqdn this request came in
            // on, otherwise no access token is issued.
            $vDomain = $this->validateDomainUuidForFqdn($fqdn, $data);
            if (!$vDomain) {
                return; // error JSON already emitted
            }

            // Optional TTL (seconds). Defaults to 3600 (1 hour) if not provided.
            $ttl = array_key_exists('ttl', $data) ? (int) $data['ttl'] : 3600;

            try {
                $token = bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $token = (new \Phalcon\Security\Random())->uuid();
            }

            if ($ttl > 0) {
                // setex requires seconds
                $this->redis->setex($keyName, $ttl, $token);
                $expiresAt = time() + $ttl;
            } else {
                $this->redis->set($keyName, $token);
                $expiresAt = null;
            }

            // Nothing from the v_domains row goes back out beyond the domain
            // name the caller already used to reach this endpoint.
            echo json_encode([
                'result' => 'success',
                'message' => 'Access token generated',
                // 'accessToken' => $token,
                'accessToken' => $token,
                'domain_name' => $vDomain->domain_name,
                'ttl' => $ttl,
                'expires_at' => ($expiresAt !== null) ? date(DATE_ATOM, $expiresAt) : null
            ]);
            return;
        }

        // --- 3. DELETE METHOD (Remove access token) ---
        if ($this->request->isDelete()) {
            $authResult = $this->validateDomainHeaderAuth();
            if ($authResult !== true) {
                return $authResult;
            }

            $data = $this->getRequestData();
            if (!is_array($data)) {
                $data = [];
            }

            $vDomain = $this->validateDomainUuidForFqdn($fqdn, $data);
            if (!$vDomain) {
                return;
            }

            $this->redis->del($keyName);

            echo json_encode([
                'result' => 'success',
                'message' => 'access token deleted'
            ]);
            return;
        }

        // Fallback if client sends an unsupported method (e.g., PUT, PATCH)
        return $this->respondWithError(405, 'Method not allowed');
    }

    public function telnyxAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        // GET — list Telnyx configs for this domain (requires header auth)
        if ($this->request->isGet()) {
            $authResult = $this->validateDomainHeaderAuth();
            if ($authResult !== true) {
                return $authResult;
            }

            $session = $this->session->get('auth');
            $fqdn = $session['subdomain'] ?? null;
            if (empty($fqdn)) {
                $host = $this->request->getHttpHost();
                $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: null;
            }
            if (empty($fqdn)) {
                return $this->respondWithError(400, 'Unable to determine domain for header auth');
            }

            $redisKey = "telnyxconfig:sms:config:{$fqdn}";
            $page = max(1, (int) ($this->request->getQuery('page', 'int') ?? 1));
            $perPage = 10;
            $totalRecords = $this->redis->lLen($redisKey);
            $totalPages = ($totalRecords > 0) ? (int) ceil($totalRecords / $perPage) : 1;
            $start = ($page - 1) * $perPage;
            $end = $start + $perPage - 1;

            $itemsRaw = $this->redis->lRange($redisKey, $start, $end);
            $items = [];
            if (is_array($itemsRaw) && !empty($itemsRaw)) {
                foreach ($itemsRaw as $it) {
                    $decoded = json_decode($it, true);
                    if (is_array($decoded)) {
                        unset($decoded['accessToken']);
                        $items[] = $decoded;
                    } else {
                        $items[] = $it;
                    }
                }
            }

            $this->response->setStatusCode(200);
            echo json_encode([
                'result' => 'success',
                'message' => 'List of Telnyx accounts',
                'domain' => $fqdn,
                'data' => $items,
                'pagination' => [
                    'currentPage' => $page,
                    'totalRecords' => $totalRecords,
                    'totalPages' => $totalPages,
                ]
            ]);
            return;
        }

        return $this->respondWithError(405, 'Method not allowed');
    }

    public function chatconfigAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        $isDelete = $this->request->isDelete();
        if (!$this->request->isPost() && !$this->request->isGet() && !$isDelete) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        //ryan 092926 start
        // chatconfig does not require Authorization header or access-token validation.
        // Resolve the configuration domain from the session or request host below.
        //ryan 092926 end

        $session = $this->session->get('auth');
        $fqdn = $session['subdomain'] ?? null;
        if (empty($fqdn)) {
            $fqdn = parse_url('http://' . $this->request->getHttpHost(), PHP_URL_HOST) ?: null;
        }
        if (empty($fqdn)) {
            //ryan 092926 start
            return $this->respondWithError(400, 'Unable to determine configuration domain');
            //ryan 092926 end
        }

        $redisKey = "genericconfig:sms:config:{$fqdn}";
        try {
            if ($isDelete) {
                $payload = $this->getRequestData();
                $id = $payload['id'] ?? $this->request->getQuery('id');
                if (!is_string($id) || trim($id) === '') {
                    return $this->respondWithError(400, 'A generic configuration id is required');
                }
                $id = trim($id);
                $rows = $this->redis->lRange($redisKey, 0, -1);
                if (!is_array($rows)) {
                    return $this->respondWithError(500, 'Unable to read generic configurations');
                }
                $removed = 0;
                foreach ($rows as $row) {
                    $config = json_decode($row, true);
                    if (!is_array($config) || ($config['id'] ?? null) !== $id ||
                        ($config['fqdn'] ?? null) !== $fqdn) {
                        continue;
                    }
                    // Predis LREM removes this exact record, preserving other UUIDs.
                    $count = $this->redis->lRem($redisKey, 1, $row);
                    if ($count === false) {
                        return $this->respondWithError(500, 'Unable to delete generic configuration');
                    }
                    $removed += (int) $count;
                }
                if ($removed === 0) {
                    return $this->respondWithError(404, 'Generic configuration not found for this id');
                }
                $warning = null;
                try {
                    if ($this->redis->del("{$fqdn}-{$id}-genericwebhook") === false) {
                        $warning = 'Configuration deleted, but webhook link cleanup failed';
                    }
                } catch (\Throwable $exception) {
                    $warning = 'Configuration deleted, but webhook link cleanup failed';
                }
                $result = [
                    'result' => 'success', 'message' => 'Generic configuration deleted',
                    'data' => ['id' => $id, 'domain' => $fqdn],
                ];
                if ($warning !== null) {
                    $result['warning'] = $warning;
                }
                return $this->response->setStatusCode(200)->setJsonContent($result);
            }
            if ($this->request->isGet()) {
                $page = max(1, (int) ($this->request->getQuery('page', 'int') ?? 1));
                $perPage = 10;
                $totalRecords = $this->redis->lLen($redisKey);
                $start = ($page - 1) * $perPage;
                $itemsRaw = $this->redis->lRange($redisKey, $start, $start + $perPage - 1);
                if ($totalRecords === false || $itemsRaw === false) {
                    return $this->respondWithError(500, 'Unable to read generic SMS configurations');
                }
                $items = [];
                foreach ($itemsRaw as $item) {
                    $decoded = json_decode($item, true);
                    if (is_array($decoded)) {
                        $items[] = $decoded;
                    }
                }

                $this->response->setStatusCode(200);
                echo json_encode([
                    'result' => 'success',
                    'message' => 'List of generic SMS configurations',
                    'domain' => $fqdn,
                    'data' => $items,
                    'pagination' => [
                        'currentPage' => $page,
                        'totalRecords' => $totalRecords,
                        'totalPages' => $totalRecords > 0 ? (int) ceil($totalRecords / $perPage) : 1,
                    ],
                ]);
                return;
            }

            $data = $this->getRequestData();
            //ryan 092926 start
            $name = $data['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                return $this->respondWithError(400, 'name is required and must be a non-empty string');
            }
            $name = trim($name);
            //ryan 092926 end
            $number = $data['number'] ?? null;
            if ((!is_string($number) && !is_int($number)) || trim((string) $number) === '') {
                return $this->respondWithError(400, 'number is required');
            }
            $number = trim((string) $number);
            if (!preg_match('/^\+?[0-9]+$/D', $number)) {
                return $this->respondWithError(400, 'number must contain digits with an optional leading +');
            }

            //ryan 092926 start
            // An omitted, null, or blank link means no webhook is requested.
            $rawLink = $data['link'] ?? null;
            $hasLink = $rawLink !== null && !(is_string($rawLink) && trim($rawLink) === '');
            $link = $hasLink ? $this->normalizeWebhookLink($rawLink) : null;
            if ($hasLink && $link === null) {
                return $this->respondWithError(400, 'link must be a valid HTTPS webhook URL when provided');
            }
            //ryan 092926 end

            // Match the admin configuration list format with generated metadata.
            $security = new \Phalcon\Security\Random();
            $config = [
                'id' => $security->uuid(),
                'fqdn' => $fqdn,
                //ryan 092926 start
                'name' => $name,
                //ryan 092926 end
                'number' => $number,
                'created' => time(),
            ];
            // Add a new configuration every time, including repeated numbers.
            //ryan 092926 start
            if ($hasLink) {
                $config['link'] = $link;
                $webhook = [
                    'id' => $config['id'], 'fqdn' => $fqdn, 'link' => $link,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                $this->redis->multi();
                $this->redis->lPush($redisKey, json_encode($config));
                $this->redis->set("{$fqdn}-{$config['id']}-genericwebhook", json_encode($webhook));
                $saved = $this->redis->exec();
                if (!is_array($saved) || in_array(false, $saved, true)) {
                    return $this->respondWithError(500, 'Unable to save generic SMS configuration');
                }
            } else {
                $saved = $this->redis->lPush($redisKey, json_encode($config));
            }
            //ryan 092926 end
            if ($saved === false) {
                return $this->respondWithError(500, 'Unable to save generic SMS configuration');
            }
        } catch (\Throwable $exception) {
            //ryan 092926 start
            try {
                $this->redis->discard();
            } catch (\Throwable $ignored) {
                // There may be no active transaction.
            }
            //ryan 092926 end
            return $this->respondWithError(500, 'Unable to access generic SMS configurations');
        }

        $this->response->setStatusCode(200);
        echo json_encode([
            'result' => 'success',
            'message' => 'Successfully registered generic SMS configuration',
            'id' => $config['id'],
            'data' => $config,
        ]);
    }

    public function chatwebhookAction()
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');

        if (!$this->request->isPost()) {
            return $this->response->setStatusCode(405)->setJsonContent([
                'result' => 'error', 'message' => 'Method not allowed'
            ]);
        }

        $authResult = $this->validateDomainHeaderAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        $data = $this->request->getPost();
        if (!is_array($data) || empty($data)) {
            $data = json_decode($this->request->getRawBody(), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $this->response->setStatusCode(400)->setJsonContent([
                    'result' => 'error', 'message' => 'Invalid JSON'
                ]);
            }
        }

        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $message = $data['message'] ?? null;
        if ((!is_string($from) && !is_int($from)) || trim((string) $from) === '' ||
            (!is_string($to) && !is_int($to)) || trim((string) $to) === '' ||
            !is_string($message) || trim($message) === '') {
            return $this->response->setStatusCode(400)->setJsonContent([
                'result' => 'error', 'message' => 'from, to, and message are required; from and to must be non-empty strings or integers and message a non-empty string'
            ]);
        }

        // Resolve the webhook's domain without requiring another payload field.
        $fqdn = parse_url('http://' . $this->request->getHttpHost(), PHP_URL_HOST);
        if (!is_string($fqdn) || $fqdn === '') {
            return $this->response->setStatusCode(400)->setJsonContent([
                'result' => 'error', 'message' => 'Unable to determine webhook domain'
            ]);
        }

        $from = trim((string) $from);
        try {
            $rows = $this->redis->lRange("genericconfig:sms:config:{$fqdn}", 0, -1);
            if (!is_array($rows)) {
                return $this->response->setStatusCode(500)->setJsonContent([
                    'result' => 'error', 'message' => 'Unable to read chat configurations'
                ]);
            }
            $configured = false;
            foreach ($rows as $row) {
                $config = is_string($row) ? json_decode($row, true) : null;
                $number = is_array($config) ? ($config['number'] ?? null) : null;
                if ((is_string($number) || is_int($number)) && trim((string) $number) === $from) {
                    $configured = true;
                    break;
                }
            }
        } catch (\Throwable $exception) {
            return $this->response->setStatusCode(500)->setJsonContent([
                'result' => 'error', 'message' => 'Unable to read chat configurations'
            ]);
        }
        if (!$configured) {
            return $this->response->setStatusCode(400)->setJsonContent([
                'result' => 'error', 'message' => 'The From number is not configured in chat (API)'
            ]);
        }

        $event = [
            'type' => 25,
            'fqdn' => $fqdn,
            'from' => $from,
            'to' => trim((string) $to),
            'message' => $message,
        ];

        $client = null;
        try {
            $client = new Client(
                Client::engine(Client::CLIENT_4X, 'ws://127.0.0.1:3000', [
                    'persistent' => false,
                ])
            );
            $client->initialize();
            $client->emit('communicate', $event);
        } catch (\Throwable $e) {
            return $this->response->setStatusCode(502)->setJsonContent([
                'result' => 'error', 'message' => 'Unable to emit webhook to websocket'
            ]);
        } finally {
            if ($client !== null) {
                try {
                    $client->close();
                } catch (\Throwable $e) {
                    // Cleanup must not replace the emit result.
                }
            }
        }

        return $this->response->setStatusCode(200)->setJsonContent([
            'result' => 'success', 'message' => 'Webhook emitted'
        ]);
    }

    /** GET lists configurations; POST edits/queues; DELETE removes a webhook link. */
    public function chatAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        $isDelete = $this->request->isDelete();
        if (!$this->request->isPost() && !$this->request->isGet() && !$isDelete) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        // $authResult = $this->validateDomainHeaderAuth();
        // if ($authResult !== true) {
        //     return $authResult;
        // }

        if ($this->request->isGet()) {
            $session = $this->session->get('auth');
            $fqdn = $session['subdomain'] ?? null;
            if (empty($fqdn)) {
                $fqdn = parse_url('http://' . $this->request->getHttpHost(), PHP_URL_HOST) ?: '';
            }
            if ($fqdn === '') {
                return $this->respondWithError(400, 'Unable to determine configuration domain');
            }
            try {
                $rows = $this->redis->lRange("genericconfig:sms:config:{$fqdn}", 0, -1);
                if (!is_array($rows)) {
                    return $this->respondWithError(500, 'Unable to read generic configurations');
                }
                $items = [];
                foreach ($rows as $row) {
                    $config = json_decode($row, true);
                    if (is_array($config)) {
                        if (is_string($config['id'] ?? null)) {
                            $webhookRaw = $this->redis->get("{$fqdn}-{$config['id']}-genericwebhook");
                            $webhook = is_string($webhookRaw) ? json_decode($webhookRaw, true) : null;
                            $config['link'] = is_array($webhook) ? ($webhook['link'] ?? '') : '';
                        }
                        $items[] = $config;
                    }
                }
            } catch (\Throwable $exception) {
                return $this->respondWithError(500, 'Unable to read generic configurations');
            }
            return $this->response->setStatusCode(200)->setJsonContent([
                'result' => 'success',
                'message' => 'List of generic configurations',
                'domain' => $fqdn,
                'totalRecords' => count($items),
                'data' => $items,
            ]);
        }

        $payload = $this->getRequestData();
        //ryan 092926 start
        // Edit name and optional number/link on the configuration selected by UUID.
        if ($isDelete || (array_key_exists('id', $payload) ||
            array_key_exists('name', $payload) || array_key_exists('number', $payload) || array_key_exists('link', $payload))) {
            $id = $payload['id'] ?? ($isDelete ? $this->request->getQuery('id') : null);
            if (!is_string($id) || trim($id) === '') {
                return $this->respondWithError(400, 'A generic configuration id is required');
            }
            $id = trim($id);
            $editNumber = !$isDelete && array_key_exists('number', $payload);
            // Explicit null or blank removes the webhook; an omitted link leaves it unchanged.
            $rawLink = $payload['link'] ?? null;
            $hasLink = !$isDelete && array_key_exists('link', $payload);
            $clearLink = $hasLink && ($rawLink === null ||
                (is_string($rawLink) && trim($rawLink) === ''));
            $editLink = $hasLink && !$clearLink;
            $name = $payload['name'] ?? null;
            if (!$isDelete && (!is_string($name) || trim($name) === '')) {
                return $this->respondWithError(400, 'name is required and must be a non-empty string');
            }
            $number = $payload['number'] ?? null;
            if ($editNumber && ((!is_string($number) && !is_int($number)) ||
                !preg_match('/^\+?[0-9]+$/D', trim((string) $number)))) {
                return $this->respondWithError(400, 'number must contain digits with an optional leading +');
            }
            $link = $editLink ? $this->normalizeWebhookLink($rawLink) : null;
            if ($editLink && $link === null) {
                return $this->respondWithError(400, 'A valid non-empty HTTPS webhook link is required');
            }
            $session = $this->session->get('auth');
            $fqdn = $session['subdomain'] ?? null;
            if (empty($fqdn)) {
                $fqdn = parse_url('http://' . $this->request->getHttpHost(), PHP_URL_HOST) ?: '';
            }
            if ($fqdn === '') {
                return $this->respondWithError(400, 'Unable to determine configuration domain');
            }
            $configKey = "genericconfig:sms:config:{$fqdn}";
            $webhookKey = "{$fqdn}-{$id}-genericwebhook";
            try {
                // WATCH prevents a concurrent LPUSH from shifting the LSET index.
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $this->redis->watch($configKey, $webhookKey);
                    $rows = $this->redis->lRange($configKey, 0, -1);
                    if (!is_array($rows)) {
                        $this->redis->unwatch();
                        return $this->respondWithError(500, 'Unable to read generic configurations');
                    }
                    $matched = null;
                    $matchedIndex = null;
                    foreach ($rows as $index => $row) {
                        $config = json_decode($row, true);
                        if (is_array($config) && ($config['id'] ?? null) === $id &&
                            ($config['fqdn'] ?? null) === $fqdn) {
                            $matched = $config;
                            $matchedIndex = $index;
                            break;
                        }
                    }
                    if ($matched === null) {
                        $this->redis->unwatch();
                        return $this->respondWithError(404, 'Generic configuration not found for this id');
                    }
                    if (!$isDelete) {
                        $matched['name'] = trim($name);
                    }
                    if ($editNumber) {
                        $matched['number'] = trim((string) $number);
                    }
                    $webhookRaw = $this->redis->get($webhookKey);
                    $webhook = is_string($webhookRaw) ? json_decode($webhookRaw, true) : null;
                    $storedLink = is_array($webhook) ? ($webhook['link'] ?? '') : '';
                    if ($isDelete && $storedLink === '' && empty($matched['link'])) {
                        $this->redis->unwatch();
                        return $this->respondWithError(404, 'No saved webhook link found for this configuration');
                    }
                    // Persist the link in the LRANGE record as well as the callback key.
                    if ($clearLink) {
                        unset($matched['link']);
                    } elseif ($isDelete) {
                        $matched['link'] = '';
                    } elseif ($editLink) {
                        $matched['link'] = $link;
                    }
                    $configJson = json_encode($matched);
                    $webhookJson = json_encode([
                        'id' => $id, 'fqdn' => $fqdn, 'link' => $link,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    if ($configJson === false || ($editLink && $webhookJson === false)) {
                        $this->redis->unwatch();
                        return $this->respondWithError(400, 'Unable to encode configuration update');
                    }
                    // Plain Redis transaction, without a Lua script.
                    $this->redis->multi();
                    $this->redis->lSet($configKey, $matchedIndex, $configJson);
                    if ($isDelete || $clearLink) {
                        $this->redis->del($webhookKey);
                    }
                    if ($editLink) {
                        $this->redis->set($webhookKey, $webhookJson);
                    }
                    $results = $this->redis->exec();
                    if ($results === null || $results === false) {
                        continue;
                    }
                    if (in_array(false, $results, true)) {
                        return $this->respondWithError(500, 'Unable to update generic configuration');
                    }
                    return $this->response->setStatusCode(200)->setJsonContent([
                        'result' => 'success',
                        'message' => $isDelete ? 'Generic webhook link deleted' : 'Generic configuration updated',
                        'data' => $isDelete ? ['id' => $id, 'link' => ''] : $matched,
                    ]);
                }
                return $this->respondWithError(409, 'Configuration changed during update; please retry');
            } catch (\Throwable $exception) {
                try {
                    $this->redis->discard();
                } catch (\Throwable $ignored) {
                    // There may be no active transaction.
                }
                try {
                    $this->redis->unwatch();
                } catch (\Throwable $ignored) {
                }
                return $this->respondWithError(500, 'Unable to update generic configuration');
            }
        }
        //ryan 092926 end

        $numbers = [];
        foreach (['from', 'to'] as $field) {
            $value = $payload[$field] ?? null;
            if ((!is_string($value) && !is_int($value)) || trim((string) $value) === '') {
                return $this->respondWithError(400, "$field is required and must be a non-empty phone number");
            }
            $numbers[$field] = trim((string) $value);
        }

        $message = $payload['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            return $this->respondWithError(400, 'message is required and must be a non-empty string');
        }

        $session = $this->session->get('auth');
        $domain = $session['subdomain'] ?? null;
        if (empty($domain)) {
            $domain = parse_url('http://' . $this->request->getHttpHost(), PHP_URL_HOST) ?: '';
        }

        $time = (string) round(microtime(true) * 1000);
        $uniqueId = $time . bin2hex(random_bytes(8));
        $queueKey = "generic:sms:queue:{$domain}";
        // Required fields are checked above; provider/config lookups are skipped.
        $job = json_encode([
            'from' => $numbers['from'],
            'to' => $numbers['to'],
            'message' => $message,
            'fqdn' => $domain,
            'timestamp' => $time,
            'messageID' => $uniqueId,
        ]);

        if ($job === false) {
            return $this->respondWithError(400, 'Unable to encode SMS job');
        }

        try {
            $queued = $this->redis->rPush($queueKey, $job);
        } catch (\Exception $exception) {
            return $this->respondWithError(500, 'Unable to queue SMS');
        }

        if ($queued === false) {
            return $this->respondWithError(500, 'Unable to queue SMS');
        }

        $this->response->setStatusCode(200);
        echo json_encode([
            'result' => 'success',
            'message' => 'SMS queued',
            'uniqueid' => $uniqueId,
        ]);
    }

    public function chatsendAction()
    {
        return $this->genericsendAction();
    }

    public function genericsendAction()
    {
        $this->view->disable();
        $this->response->setContentType('application/json', 'UTF-8');
        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }
        return $this->sendSmsFromProvider('generic', $this->getRequestData());
    }

    public function telnyxsendAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        return $this->sendSmsFromProvider('telnyx', $this->getRequestData());
    }

    public function telnyxconversationAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $authResult = $this->validateAccessTokenAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        $payload = $this->getRequestData();
        if (!is_array($payload)) {
            return $this->respondWithError(400, 'Invalid form data');
        }

        $from = isset($payload['from']) ? trim((string) $payload['from']) : '';
        $to = isset($payload['to']) ? trim((string) $payload['to']) : '';
        $page = max(1, (int) ($payload['page'] ?? 1));

        if ($from === '') {
            return $this->respondWithError(400, 'from is required');
        }
        if ($to === '') {
            return $this->respondWithError(400, 'to is required');
        }

        if (!preg_match('/^[0-9]+$/', $from)) {
            return $this->respondWithError(400, 'from must be digits only');
        }
        if (!preg_match('/^[0-9]+$/', $to)) {
            return $this->respondWithError(400, 'to must be digits only');
        }

        $host = $this->request->getHttpHost();
        $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: null;
        if (empty($fqdn)) {
            return $this->respondWithError(400, 'Unable to determine fqdn from host');
        }

        $domain = $fqdn;

        // Fetch hash map data
        $hashKey = "{$from}-telnyx-{$to}-{$to}-{$domain}";
        $hashData = $this->redis->hGetAll($hashKey);
        $hashData = is_array($hashData) ? $hashData : [];

        $redisKey = "{$fqdn}:telnyx:{$from}:{$from}-{$to}";
        $groupSize = 5;
        $groupsPerPage = 10;
        $limit = $groupSize * $groupsPerPage;
        $start = ($page - 1) * $limit;
        $end = $start + $limit - 1;

        $totalRawItems = $this->redis->lLen($redisKey);
        $totalGroups = (int) floor($totalRawItems / $groupSize);
        $totalPages = ($totalGroups > 0) ? (int) ceil($totalGroups / $groupsPerPage) : 1;

        $itemsRaw = $this->redis->lRange($redisKey, $start, $end);
        $messages = [];

        if (is_array($itemsRaw) && !empty($itemsRaw)) {
            $groupCount = floor(count($itemsRaw) / $groupSize);
            for ($groupIndex = 0; $groupIndex < $groupCount; $groupIndex++) {
                $groupStart = $groupIndex * $groupSize;
                $groupItems = array_slice($itemsRaw, $groupStart, $groupSize);

                // Group mapping (0-indexed):
                // [0] -> Unique ID / Raw Timestamp
                // [1] -> Sender identifier
                // [2] -> 3rd item (Name / Sender ID)
                // [3] -> 4th item (Timestamp)
                // [4] -> 5th item (Message body)
                $senderRaw = isset($groupItems[1]) ? (string) $groupItems[1] : '';
                $thirdItem = isset($groupItems[2]) ? (string) $groupItems[2] : '';
                $fourthItem = isset($groupItems[3]) ? (string) $groupItems[3] : '';
                $messageItem = $groupItems[4] ?? null;

                // Clean timestamp from the 4th item (index 3)
                $timestamp = preg_replace('/-(Agent|Status)$/', '', $fourthItem);

                // Clean -Agent or -Status suffix from the 3rd item (index 2)
                $name = preg_replace('/-(Agent|Status)$/', '', $thirdItem);

                // Determine message source role
                $type = 'client';
                if (strpos($senderRaw, '-Agent') !== false || strpos($thirdItem, '-Agent') !== false) {
                    $type = 'agent';
                } elseif (strpos($senderRaw, '-Status') !== false || strpos($thirdItem, '-Status') !== false) {
                    $type = 'status';
                }

                // Parse message payload
                $messageValue = null;
                if ($messageItem !== null) {
                    $decoded = json_decode($messageItem, true);
                    $messageValue = is_array($decoded) ? $decoded : $messageItem;
                }

                $entry = [
                    'type' => $type,
                    'timestamp' => $timestamp,
                    'message' => $messageValue
                ];

                if ($name !== '') {
                    $entry['name'] = $name;
                }

                $messages[] = $entry;
            }
        }

        echo json_encode([
            'result' => 'success',
            'message' => 'Telnyx conversation fetched',
            'data' => $messages,
            'page' => $page,
            'messages' => count($messages),
            'key' => $redisKey,
            'hashKey' => $hashKey,
            'hashData' => $hashData,
            'pagination' => [
                'currentPage' => $page,
                'totalRecords' => $totalGroups,
                'totalPages' => $totalPages,
            ],
        ]);
        return;
    }

    public function twilioAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        // GET — list Twilio configs for this domain (requires header auth)
        if ($this->request->isGet()) {
            $authResult = $this->validateDomainHeaderAuth();
            if ($authResult !== true) {
                return $authResult;
            }

            $session = $this->session->get('auth');
            $fqdn = $session['subdomain'] ?? null;
            if (empty($fqdn)) {
                $host = $this->request->getHttpHost();
                $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: null;
            }
            if (empty($fqdn)) {
                return $this->respondWithError(400, 'Unable to determine domain for header auth');
            }

            $redisKey = "twilioconfig:sms:config:{$fqdn}";
            $page = max(1, (int) ($this->request->getQuery('page', 'int') ?? 1));
            $perPage = 10;
            $totalRecords = $this->redis->lLen($redisKey);
            $totalPages = ($totalRecords > 0) ? (int) ceil($totalRecords / $perPage) : 1;
            $start = ($page - 1) * $perPage;
            $end = $start + $perPage - 1;

            $itemsRaw = $this->redis->lRange($redisKey, $start, $end);
            $items = [];
            if (is_array($itemsRaw) && !empty($itemsRaw)) {
                foreach ($itemsRaw as $it) {
                    $decoded = json_decode($it, true);
                    if (is_array($decoded)) {
                        unset($decoded['accessToken']);
                        $items[] = $decoded;
                    } else {
                        $items[] = $it;
                    }
                }
            }

            $this->response->setStatusCode(200);
            echo json_encode([
                'result' => 'success',
                'message' => 'List of Twilio accounts',
                'domain' => $fqdn,
                'data' => $items,
                'pagination' => [
                    'currentPage' => $page,
                    'totalRecords' => $totalRecords,
                    'totalPages' => $totalPages,
                ]
            ]);
            return;
        }

        return $this->respondWithError(405, 'Method not allowed');
    }

    public function twiliosendAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        return $this->sendSmsFromProvider('twilio', $this->getRequestData());
    }

    public function twilioconversationAction()
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $authResult = $this->validateAccessTokenAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        $payload = $this->getRequestData();
        if (!is_array($payload)) {
            return $this->respondWithError(400, 'Invalid form data');
        }

        $from = isset($payload['from']) ? trim((string) $payload['from']) : '';
        $to = isset($payload['to']) ? trim((string) $payload['to']) : '';
        $page = max(1, (int) ($payload['page'] ?? 1));

        if ($from === '') {
            return $this->respondWithError(400, 'from is required');
        }
        if ($to === '') {
            return $this->respondWithError(400, 'to is required');
        }

        if (!preg_match('/^[0-9]+$/', $from)) {
            return $this->respondWithError(400, 'from must be digits only');
        }
        if (!preg_match('/^[0-9]+$/', $to)) {
            return $this->respondWithError(400, 'to must be digits only');
        }

        $host = $this->request->getHttpHost();
        $fqdn = parse_url('http://' . $host, PHP_URL_HOST) ?: null;
        if (empty($fqdn)) {
            return $this->respondWithError(400, 'Unable to determine fqdn from host');
        }

        $domain = $fqdn;

        // Fetch hash map data
        $hashKey = "{$from}-twilio-{$to}-{$to}-{$domain}";
        $hashData = $this->redis->hGetAll($hashKey);
        $hashData = is_array($hashData) ? $hashData : [];

        $redisKey = "{$fqdn}:twilio:{$from}:{$from}-{$to}";
        $groupSize = 5;
        $groupsPerPage = 10;
        $limit = $groupSize * $groupsPerPage;
        $start = ($page - 1) * $limit;
        $end = $start + $limit - 1;

        $totalRawItems = $this->redis->lLen($redisKey);
        $totalGroups = (int) floor($totalRawItems / $groupSize);
        $totalPages = ($totalGroups > 0) ? (int) ceil($totalGroups / $groupsPerPage) : 1;

        $itemsRaw = $this->redis->lRange($redisKey, $start, $end);
        $messages = [];

        if (is_array($itemsRaw) && !empty($itemsRaw)) {
            $groupCount = floor(count($itemsRaw) / $groupSize);
            for ($groupIndex = 0; $groupIndex < $groupCount; $groupIndex++) {
                $groupStart = $groupIndex * $groupSize;
                $groupItems = array_slice($itemsRaw, $groupStart, $groupSize);

                // Group mapping (0-indexed):
                // [0] -> Unique ID / Raw Timestamp
                // [1] -> Sender identifier
                // [2] -> 3rd item (Name / Sender ID)
                // [3] -> 4th item (Timestamp)
                // [4] -> 5th item (Message body)
                $senderRaw = isset($groupItems[1]) ? (string) $groupItems[1] : '';
                $thirdItem = isset($groupItems[2]) ? (string) $groupItems[2] : '';
                $fourthItem = isset($groupItems[3]) ? (string) $groupItems[3] : '';
                $messageItem = $groupItems[4] ?? null;

                // Clean timestamp from the 4th item (index 3)
                $timestamp = preg_replace('/-(Agent|Status)$/', '', $fourthItem);

                // Clean -Agent or -Status suffix from the 3rd item (index 2)
                $name = preg_replace('/-(Agent|Status)$/', '', $thirdItem);

                // Determine message source role
                $type = 'client';
                if (strpos($senderRaw, '-Agent') !== false || strpos($thirdItem, '-Agent') !== false) {
                    $type = 'agent';
                } elseif (strpos($senderRaw, '-Status') !== false || strpos($thirdItem, '-Status') !== false) {
                    $type = 'status';
                }

                // Parse message payload
                $messageValue = null;
                if ($messageItem !== null) {
                    $decoded = json_decode($messageItem, true);
                    $messageValue = is_array($decoded) ? $decoded : $messageItem;
                }

                $entry = [
                    'type' => $type,
                    'timestamp' => $timestamp,
                    'message' => $messageValue
                ];

                if ($name !== '') {
                    $entry['name'] = $name;
                }

                $messages[] = $entry;
            }
        }

        echo json_encode([
            'result' => 'success',
            'message' => 'Twilio conversation fetched',
            'data' => $messages,
            'page' => $page,
            'messages' => count($messages),
            'key' => $redisKey,
            'hashKey' => $hashKey,
            'hashData' => $hashData,
            'pagination' => [
                'currentPage' => $page,
                'totalRecords' => $totalGroups,
                'totalPages' => $totalPages,
            ],
        ]);
        return;
    }

    private function normalizeWebhookLink($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $link = trim($value);

        if ($link === '' || !filter_var($link, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = parse_url($link, PHP_URL_SCHEME);
        if (strtolower((string) $scheme) !== 'https') {
            return null;
        }

        $host = parse_url($link, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        // Block raw IPs and private/reserved ranges to prevent SSRF
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        } elseif (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $link;
    }




    public function telnyxwebhookAction()
    {
        return $this->handleProviderWebhook('telnyx');
    }

    public function twiliowebhookAction()
    {
        return $this->handleProviderWebhook('twilio');
    }

    private function handleProviderWebhook(string $provider)
    {
        $this->view->disable();
        $this->response->setHeader('Content-Type', 'application/json');

        $host = $this->request->getHttpHost();
        $cleanHost = parse_url('http://' . $host, PHP_URL_HOST) ?: explode(':', $host)[0];
        $providerName = ucfirst($provider);
        $isGet = $this->request->isGet();
        $isPost = $this->request->isPost();
        $isDelete = $this->request->isDelete();

        if (!$isGet && !$isPost && !$isDelete) {
            $this->response->setHeader('Allow', 'GET, POST, DELETE');
            return $this->respondWithError(405, 'Method not allowed. Use GET, POST or DELETE.');
        }

        $payload = $isGet ? [] : $this->getRequestData();

        // Preserve the existing live-event acknowledgement route.
        if ($isPost && !array_key_exists('link', $payload)) {
            $this->response->setStatusCode(200);
            echo json_encode(['result' => 'event_received']);
            return;
        }

        $authResult = $this->validateDomainHeaderAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        // GET uses the query; DELETE accepts a body or query parameter.
        $configId = $isGet
            ? $this->request->getQuery('id')
            : ($payload['id'] ?? ($isDelete ? $this->request->getQuery('id') : null));
        $configId = is_string($configId) ? trim($configId, " \"'") : null;
        if (!$configId) {
            return $this->respondWithError(400, "A valid id ({$providerName} configuration ID) is required");
        }

        $configList = $this->redis->lRange("{$provider}config:sms:config:{$cleanHost}", 0, -1);
        if ($configList === false) {
            return $this->respondWithError(500, "Unable to read {$providerName} configurations");
        }

        $configFound = false;
        foreach ($configList as $configJson) {
            $config = json_decode($configJson, true);
            if (is_array($config) && ($config['id'] ?? null) === $configId) {
                $configFound = true;
                break;
            }
        }
        if (!$configFound) {
            return $this->respondWithError(404, "No {$providerName} configuration found for this id");
        }

        $webhookRedisKey = "{$cleanHost}-{$configId}-{$provider}webhook";
        if ($isGet) {
            $storedConfig = $this->redis->get($webhookRedisKey);
            if ($storedConfig === false || $storedConfig === null) {
                return $this->respondWithError(404, 'No active webhook configuration found for this id');
            }
            $data = json_decode($storedConfig, true);
            if (!is_array($data)) {
                return $this->respondWithError(500, 'Invalid stored webhook configuration');
            }
            unset($data['userkey'], $data['page_id'], $data['pageId']);
            $data['id'] = $configId;
            $this->response->setStatusCode(200);
            echo json_encode(['result' => 'success', 'method' => 'GET', 'data' => $data]);
            return;
        }

        if ($isDelete) {
            // Delete only this webhook, preserving the provider configuration list.
            $deleted = $this->redis->del($webhookRedisKey);
            if ($deleted === false) {
                return $this->respondWithError(500, "Unable to delete {$providerName} webhook configuration");
            }
            if ($deleted === 0) {
                return $this->respondWithError(404, 'No active webhook configuration found for this id');
            }
            $this->response->setStatusCode(200);
            echo json_encode([
                'result' => 'success',
                'message' => "{$providerName} webhook configuration deleted from Redis",
                'method' => 'DELETE',
                'data' => ['id' => $configId]
            ]);
            return;
        }

        $link = $this->normalizeWebhookLink($payload['link'] ?? null);
        if ($link === null) {
            return $this->respondWithError(400, 'A valid non-empty HTTPS URL is required (e.g., https://example.com/endpoint).');
        }
        $configData = json_encode([
            'link' => $link,
            'id' => $configId,
            'fqdn' => $cleanHost,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        if (!$this->redis->set($webhookRedisKey, $configData)) {
            return $this->respondWithError(500, "Unable to save {$providerName} webhook configuration");
        }
        $this->response->setStatusCode(200);
        echo json_encode([
            'result' => 'success',
            'message' => "{$providerName} webhook configuration saved to Redis",
            'method' => 'POST',
            'data' => ['link' => $link, 'id' => $configId]
        ]);
        return;
    }

    /**
     * Normalise a phone number lifted straight out of an untrusted JSON body.
     *
     * The value may be any JSON type — Telnyx quotes phone numbers, but a
     * client posting the same event by hand can easily send a bare number, and
     * under declare(strict_types=1) that would make ltrim() raise TypeError.
     *
     * Returns the number with any leading "+" removed, or null when the value
     * is missing, non-scalar, or empty.
     */

    private function sendSmsFromProvider(string $provider, $payload)
    {
        if (!is_array($payload)) {
            return $this->respondWithError(400, 'Invalid form data');
        }

        $authResult = $this->validateDomainHeaderAuth();
        if ($authResult !== true) {
            return $authResult;
        }

        if (!in_array($provider, ['telnyx', 'twilio', 'generic'], true)) {
            return $this->respondWithError(400, 'Unsupported SMS provider');
        }
        if (!is_string($payload['userkey'] ?? null) || trim($payload['userkey']) === '' ||
            !is_string($payload['message'] ?? null) || trim($payload['message']) === '') {
            return $this->respondWithError(400, 'userkey and message are required and must be non-empty strings');
        }
        $client = null;
        $queued = false;
        try {

        $chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
        $time = (string) round(microtime(true) * 1000);
        $uniqueId = "";
        $length = 16;
        $message = $payload["message"];
        $urlwebhook = "";
        $webhookWarning = null;
        $webhookStatus = null;
        $responseData = "";

        $websocketdata = [];

        $charsLength = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $uniqueId .= $chars[random_int(0, $charsLength - 1)];
        }

        $uniqueId = $time . $uniqueId;


        // PHP array key access with safety checks (equivalent to optional chaining `?.`)
        $clientkey = $payload['userkey'] ?? null;

        $fqdn = $this->request->getHttpHost();
        if ($provider === 'generic') {
            $session = $this->session->get('auth');
            $fqdn = $session['subdomain'] ?? null;
            if (empty($fqdn)) {
                $fqdn = parse_url('http://' . $this->request->getHttpHost(), PHP_URL_HOST) ?: '';
            }
            $clientkey = trim($clientkey);

            
        }

        $clientkey = "$clientkey-$fqdn";

        $clientData = $this->redis->hGetAll($clientkey);
// print("$clientkey key?");
//             return;

        if (empty($clientData)) {
            $this->response->setStatusCode(404);
            echo json_encode([
                'result' => 'error',
                'message' => 'no config found',
            ]);
            return;
        }


        $assignedAgents = json_decode($clientData["assigned_agent"] ?? '', true) ?? "";
        $configvalidation = $clientData['pageId'] ?? '';
        if ($provider === 'generic') {
            if (($clientData['messageType'] ?? '') !== 'generic' ||
                ($clientData['fqdn'] ?? '') !== $fqdn ||
                empty($clientData['id']) || empty($clientData['email'])) {
                return $this->respondWithError(404, 'Generic conversation not found or incomplete');
            }
            $configList = $this->redis->lRange("genericconfig:sms:config:{$fqdn}", 0, -1);
            if (!is_array($configList)) {
                return $this->respondWithError(500, 'Unable to read generic configurations');
            }
            // appId is the configuration UUID. Older type 25 records used pageId.
            // id is the sender number, and email/pageId identify the recipient.
            $foundConfig = null;
            foreach ([$clientData['appId'] ?? '', $clientData['pageId'] ?? ''] as $candidateId) {
                if (!is_string($candidateId) || $candidateId === '') {
                    continue;
                }
                foreach ($configList as $row) {
                    $config = json_decode($row, true);
                    if (is_array($config) && ($config['id'] ?? '') === $candidateId &&
                        ($config['fqdn'] ?? '') === $fqdn) {
                        $foundConfig = $config;
                        break 2;
                    }
                }
            }
            if ($foundConfig === null) {
                return $this->respondWithError(404, 'No matching generic configuration found for this conversation');
            }
            $configvalidation = $foundConfig['id'];
            $clientData['appId'] = $configvalidation;
            $clientData['pageId'] = $clientData['email'];
        }

        if ($provider === "telnyx") {
            // Fetch all items from Redis
            $configList = $this->redis->lrange("telnyxconfig:sms:config:$fqdn", 0, -1);

            // Early return if list is empty or redis returned false
            if (empty($configList)) {
                $this->response->setStatusCode(404);
                echo json_encode([
                    'result' => 'error',
                    'message' => 'no telnyx config found',
                ]);
                return;
            }

            // Search for matching ID
            foreach ($configList as $jsonItem) {
                $data = json_decode($jsonItem, true);

                // Found a match - store it and stop searching
                if (isset($data['id']) && $data['id'] === $configvalidation) {
                    $foundConfig = $data;
                    break;
                }
            }

            // Early return if ID not found
            if (!isset($foundConfig)) {
                $this->response->setStatusCode(404);
                echo json_encode([
                    'result' => 'error',
                    'message' => 'no config match found',
                ]);
                return;
            }

            // Success path for Telnyx
            // You can now safely use $foundConfig

        } else if ($provider === "twilio") {
            // Fetch all items from Redis
            $configList = $this->redis->lrange("twilioconfig:sms:config:$fqdn", 0, -1);

            // Early return if list is empty or redis returned false
            if (empty($configList)) {
                $this->response->setStatusCode(404);
                echo json_encode([
                    'result' => 'error',
                    'message' => 'no telnyx config found',
                ]);
                return;
            }

            // Search for matching ID
            foreach ($configList as $jsonItem) {
                $data = json_decode($jsonItem, true);

                // Found a match - store it and stop searching
                if (isset($data['id']) && $data['id'] === $configvalidation) {
                    $foundConfig = $data;
                    break;
                }
            }

            // Early return if ID not found
            if (!isset($foundConfig)) {
                $this->response->setStatusCode(404);
                echo json_encode([
                    'result' => 'error',
                    'message' => 'no config match found',
                ]);
                return;
            }

            // Success path for twilio
            // You can now safely use $foundConfig
        }

        if ($provider === 'generic') {
            $webhookRaw = $this->redis->get("{$fqdn}-{$configvalidation}-genericwebhook");
            if (is_string($webhookRaw) && $webhookRaw !== '') {
                $webhookData = json_decode($webhookRaw, true);
                $savedLink = is_array($webhookData) ? ($webhookData['link'] ?? null) : null;
                $validatedLink = $this->normalizeWebhookLink($savedLink);
                if ($validatedLink === null || ($webhookData['id'] ?? '') !== $configvalidation ||
                    ($webhookData['fqdn'] ?? '') !== $fqdn) {
                    return $this->respondWithError(400, 'Invalid saved generic webhook configuration');
                }
                $urlwebhook = $validatedLink;
            }
        }

        // if($provider === "telnyx"){
        //     $webhookRaw = $this->redis->get("$fqdn-telnyxwebhook");
        //     $webhookData = json_decode($webhookRaw, true);
        //     $urlwebhook = $webhookData['link'] ?? "";
        // } else if($provider === "twilio"){
        //     $webhookRaw = $this->redis->get("$fqdn-twiliowebhook");
        //     $webhookData = json_decode($webhookRaw, true);
        //     $urlwebhook = $webhookData['link'] ?? "";
        // }








        $inbox = "";
        $clientconversationkey = "";

        if ($provider == "telnyx") {
            $inbox = "$fqdn:telnyx:{$clientData["id"]}:{$clientData["id"]}-{$clientData["email"]}";
            $clientconversationkey = $clientData['email'] . '-' . $clientData['id'] . '-' . 'telnyx' . '-' . $clientData['email'];
        } else if ($provider == "twilio") {
            $inbox = "$fqdn:twilio:{$clientData["id"]}:{$clientData["id"]}-{$clientData["email"]}";
            $clientconversationkey = $clientData['email'] . '-' . $clientData['id'] . '-' . 'twilio' . '-' . $clientData['email'];
        } else if ($provider === 'generic') {
            $inbox = "{$fqdn}:generic:{$clientData['id']}:{$clientData['id']}-{$clientData['email']}";
            $clientconversationkey = $clientData['email'] . '-' . $clientData['id'] . '-generic-' . $clientData['email'];
        } else {
            $this->response->setStatusCode(404);
            echo json_encode([
                'result' => 'error',
                'message' => 'no functions yet',
            ]);
            return;
        }



        if (empty($inbox) || !$this->redis->exists($inbox)) {
            $this->response->setStatusCode(404);
            echo json_encode([
                'result' => 'error',
                'message' => 'Inbox data not found',
                'inbox' => $inbox,
            ]);
            return;
        }

        $client = new Client(Client::engine(Client::CLIENT_4X, 'ws://127.0.0.1:3000', ['persistent' => false]));
        $client->initialize();
        if ($provider === 'generic') {
            $job = json_encode([
                'from' => $clientData['id'], 'to' => $clientData['email'],
                'message' => $message, 'fqdn' => $fqdn,
                'timestamp' => $time, 'messageID' => $uniqueId,
            ]);
            if ($job === false) {
                return $this->respondWithError(400, 'Unable to encode SMS job');
            }
            if ($this->redis->rPush("generic:sms:queue:{$fqdn}", $job) === false) {
                return $this->respondWithError(500, 'Unable to queue generic SMS');
            }
            $queued = true;
        }

        $this->redis->hmset($clientkey, [
            'timestamp' => $time,
            'last_message' => $message,
            'message' => $message,
            'isReceived' => "0",
            'agent_unread' => "0",
            'client_unread' => ((int) ($clientData["client_unread"] ?? 0)) + 1,
            'allocated_room' => "ACTIVE",
            'chatUpdated' => $time,
        ]);



        $this->redis->zRem("$fqdn-conversation-close", $clientconversationkey);
        $this->redis->zRem("$fqdn-conversation-active", $clientconversationkey);
        $this->redis->zRem("$fqdn-conversation-new", $clientconversationkey);
        $this->redis->zRem("$fqdn-conversation", $clientconversationkey);

        $result = $this->redis->zadd("$fqdn-conversation-active", $time, $clientconversationkey);
        $result = $this->redis->zadd("$fqdn-conversation", $time, $clientconversationkey);

        $pushData = [
            (string) $message,
            (string) $time,
            "Api-Agent",
            "Api",
            (string) $uniqueId,
        ];



        $this->redis->lPush($inbox, ...$pushData);

        $post = [
            "to" => $clientData["phoneNumber"],
            "from" => $fqdn,
            "pageId" => $clientData["pageId"],
            "message" => $message,
            'timestamp' => $time,
            "messageID" => $uniqueId,
            'clientkey' => $clientkey,
            'inboxkey' => $inbox,
            'clientdata' => $clientData,
        ];




        if ($provider === 'generic') {
            $post['from'] = $clientData['id'];
            $post['to'] = $clientData['email'];
            $post['fqdn'] = $fqdn;
            // The external webhook uses pageId for the configuration UUID.
            $post['pageId'] = $configvalidation;
            // Send conversation metadata without any legacy provider credentials.
            $webhookClientData = $clientData;
            unset(
                $webhookClientData['appId'],
                $webhookClientData['accessToken'],
                $webhookClientData['password'],
                $webhookClientData['clientdata'],
                $webhookClientData['seen'],
                $webhookClientData['isReceived'],
                $webhookClientData['client_unread'],
                $webhookClientData['msgIndex'],
                $webhookClientData['phoneNumber'],
                $webhookClientData['email'],
                $webhookClientData['socketID'],
                $webhookClientData['agent_unread'],
                $webhookClientData['last_message']
            );
            unset($post['clientdata']);
            // Array union keeps the outgoing message values when metadata repeats a key.
            $post += $webhookClientData;
            // Use camelCase on the webhook without renaming stored conversation fields.
            $webhookFieldNames = [
                'messageID' => 'messageId',
                'clientkey' => 'clientKey',
                'inboxkey' => 'inboxKey',
                'Dept' => 'dept',
                'allocated_room' => 'allocatedRoom',
            ];
            foreach ($webhookFieldNames as $storedName => $webhookName) {
                if (array_key_exists($storedName, $post)) {
                    $post[$webhookName] = $post[$storedName];
                    unset($post[$storedName]);
                }
            }
        }

        if (!empty($urlwebhook)) {
            $body = json_encode($post);
            if ($body === false) {
                $webhookWarning = 'SMS queued, but webhook payload could not be encoded';
            } else {
                $ch = curl_init($urlwebhook);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                $response = curl_exec($ch);
                $webhookStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                if ($response === false || $webhookStatus < 200 || $webhookStatus >= 300) {
                    $webhookWarning = 'SMS queued, but webhook callback failed';
                    error_log('Generic webhook callback failed: messageID=' . $uniqueId .
                        ' status=' . $webhookStatus . ' error=' . $curlError);
                }
                if ($response !== false) {
                    $decodedResponse = json_decode($response, true);
                    $responseData = json_last_error() === JSON_ERROR_NONE ? $decodedResponse : $response;
                }
            }
        }

        if ($provider === 'generic') {
            $clientData['client_unread'] = ((int) ($clientData['client_unread'] ?? 0)) + 1;
            $clientData['agent_unread'] = 0;
        }

        $event = [
            'type' => "api",
            'username' => "Api-Agent",
            'messageType' => $provider,
            'name' => $clientData["username"],
            'useremail' => $clientData["email"],
            'channel' => $fqdn,
            'message' => $message,
            'messageID' => $uniqueId,
            'time' => $time,
            'allocated_room' => "ACTIVE",
            'client_unread' => $clientData["client_unread"],
            'agent_unread' => $clientData["agent_unread"],
            "assigned_agent" => $assignedAgents,
            'last_message' => $message,
            'id' => $clientData["id"],
            'ourNumber' => $clientData["ourNumber"],
            'appId' => $clientData["appId"],
            'chatUpdated' => $time,
            'pageId' => $clientData["pageId"],
            'fqdn' => $fqdn,
            'data' => $payload,
        ];

        $client->emit('communicate', $event);










        $this->response->setStatusCode(200);
        echo json_encode([
            'result' => 'success',
            'message' => "message successfully sent via {$provider}",
            // 'test' => $clientData,
            // 'clientkey' => $clientkey,
            // 'clientconversationkey' => $clientconversationkey,
            // 'responseData' => $responseData,
            // 'webhookStatus' => $webhookStatus,
            // 'warning' => $webhookWarning,
            // 'uniqueid' => $uniqueId
        ]);
        return;
        } catch (\Throwable $exception) {
            if ($queued) {
                return $this->response->setStatusCode(200)->setJsonContent([
                    'result' => 'success', 'message' => 'SMS queued', 'uniqueid' => $uniqueId,
                    'warning' => 'Conversation update or websocket notification failed',
                ]);
            }
            return $this->respondWithError(500, 'Unable to process SMS message');
        } finally {
            if ($client !== null) {
                try {
                    $client->close();
                } catch (\Throwable $exception) {
                    // Preserve the send result if socket cleanup fails.
                }
            }
        }
    }




    private function respondWithError($httpCode, $message)
    {
        $httpCode = (int) $httpCode;

        http_response_code(self::USE_HTTP_STATUS_CODES ? $httpCode : 200);

        echo json_encode([
            'result' => 'error',
            'code' => $httpCode,
            'message' => $message,
            'content' => ''
        ]);
    }

    private function validateNumericPhone(string $field, array $payload)
    {
        if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
            $this->respondWithError(400, "$field is required");
            return null;
        }

        $value = trim((string) $payload[$field]);
        if (!preg_match('/^[0-9]+$/', $value)) {
            $this->respondWithError(400, "$field must be a number");
            return null;
        }

        return $value;
    }
}
