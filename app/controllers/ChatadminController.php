<?php

declare(strict_types=1);
use Phalcon\Cli\Task;
use ElephantIO\Client;
use Cronfig\Sysinfo\System;
use Cylab\System\System as System2;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Lame\Lame;
use Lame\Settings;

use Phalcon\Mvc\View;
use Phalcon\Db\Column;
use Phalcon\Forms\Form;
use App\Forms\ChatformForm;
require_once BASE_PATH . '/app/library/Email.php';

class ChatadminController extends ControllerBase
{
    private function secondsToHMS($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    public function indexAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setContentType("application/json", "UTF-8");
        if ($this->chatSessionTenant() === "") {
            return $this->chatRefuse(401, "Unauthorized. (Code AI1001)");
        }

        // Parse request body (ensure array fallback)
        $params = json_decode($this->request->getRawBody(), true) ?? [];

        // Validate form input
        $form = new ChatvalidatorForm();

        if (!$form->isValid($params)) {
            $errors = [];
            foreach ($form->getMessages() as $message) {
                $errors[] = $message->getMessage();
            }

            return $this->response->setJsonContent([
                "result" => "error",
                "message" => implode(" ", $errors),
            ]);
        }

        // Fetch data
        $payload = $this->latestDataNew(
            $params["page"] ?? 1,
            $params["search"] ?? "",
            $params["owner"] ?? "",
            $params["all"] ?? "",
            $params["type"] ?? "",
            $params["email"] ?? "",
            $params["username"] ?? "",
            $params["count"] ?? 20
        );


        // echo $params["page"];
        // Return full pagination info
        return $this->response->setJsonContent([
            "result" => "success",
            "page" => $payload["page"],
            "content" => $payload["data"],
            // "count"   => $payload["count"],
            "total" => $payload["total"],
            "next" => $payload["next"],
            "hasMore" => $payload["hasMore"],
            // "debug"   => $payload["debug"],
            // "debug2"   => $payload["debug2"],
            // "score" => $payload["score"],
            // "userkey"   => $payload["userkey"],
            "agentnewkey" => $payload["agentnewkey"],
            "assignedCustomers" => $payload["assignedCustomers"] ?? "",
            "customersResult" => $payload["customersResult"]
        ]);
    }

    public function lineconfigAction()
    {
        $this->view->disable();
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $data = json_decode($this->request->getRawBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return $this->respondWithError(400, 'Invalid JSON');
        }

        $type = isset($data['type'])
            ? (int) $this->filter->sanitize($data['type'], 'int')
            : 0;

        if (!$type) {
            return $this->respondWithError(400, 'Invalid type');
        }

        // Get FQDN from session
        $session = $this->session->get("auth");
        $fqdn = $session["subdomain"] ?? '';

        if ($fqdn === '') {
            return $this->respondWithError(400, 'fqdn is required');
        }

        $redisKey = "lineconfig:fqdn:{$fqdn}";


        /* TYPE 1 — CREATE */
        if ($type === 1) {
            $requiredFields = ['accessToken', 'name'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->respondWithError(400, "$field is required");
                }
            }

            $accessToken = $this->filter->sanitize($data['accessToken'], 'string');
            $name = $this->filter->sanitize($data['name'], 'string');

            // Check for duplicates in Redis
            $existingRecords = $this->redis->lRange($redisKey, 0, -1);
            if (!empty($existingRecords)) {
                foreach ($existingRecords as $record) {
                    $decoded = json_decode($record, true);
                    if (isset($decoded['name']) && $decoded['name'] === $name) {
                        return $this->respondWithError(400, "Name: $name is already added.");
                    }
                }
            }






            $security = new \Phalcon\Security\Random();
            $id = $security->uuid();

            // ADD SOURCE PARAM
            $webhookUrl = "https://{$fqdn}:8443/register/line?source={$id}";

            $payload = [
                'id' => $id,
                'fqdn' => $fqdn,
                'accessToken' => $accessToken,
                'name' => $name,
                'webhookUrl' => $webhookUrl,
                'created' => time()
            ];

            $this->redis->lPush($redisKey, json_encode($payload));

            return $this->response->setJsonContent([
                'result' => 'success',
                'message' => 'Successfully registered LINE configuration',
                'webhookUrl' => $webhookUrl, // User needs to paste this into LINE Console
                'id' => $id
            ])->send();

            /* TYPE 2 — READ */
        } elseif ($type === 2) {
            $page = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
            $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));
            $start = ($page - 1) * $limit;

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $total = count($rowsRaw);

            $rowsPaginated = array_slice($rowsRaw, $start, $limit);
            $rows = array_map(fn($r) => json_decode($r, true), $rowsPaginated);

            return $this->response->setJsonContent([
                'result' => 'success',
                'data' => $rows,
                'pagination' => [
                    'totalRecords' => $total,
                    'totalPages' => ceil($total / $limit),
                    'currentPage' => $page,
                    'limit' => $limit
                ]
            ])->send();

            /* TYPE 3 — UPDATE */
        } elseif ($type === 3) {
            $itemId = isset($data['id']) ? $this->filter->sanitize($data['id'], 'string') : null;
            if (!$itemId)
                return $this->respondWithError(400, 'Invalid ID');

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $updated = false;
            $webhookUrl = null; // Declare outside so it persists after the loop

            foreach ($rowsRaw as $index => $row) {
                $decoded = json_decode($row, true);

                if ($decoded['id'] == $itemId) {
                    // 1. Update Access Token if provided
                    if (isset($data['accessToken']) && $data['accessToken'] !== ($decoded['accessToken'] ?? '')) {
                        $decoded['accessToken'] = $this->filter->sanitize($data['accessToken'], 'string');
                    }

                    // 2. Update Name if provided
                    if (isset($data['name'])) {
                        $decoded['name'] = $this->filter->sanitize($data['name'], 'string');
                    }

                    // 3. CAPTURE the URL from the existing data to return it later
                    $webhookUrl = $decoded['webhookUrl'] ?? null;

                    // 4. Save changes back to Redis
                    $this->redis->lSet($redisKey, $index, json_encode($decoded));
                    $updated = true;
                    break;
                }
            }

            if (!$updated)
                return $this->respondWithError(404, 'Record not found');

            return $this->response->setJsonContent([
                'result' => 'success',
                'message' => 'Successfully updated the configuration',
                'webhookUrl' => $webhookUrl, // Now correctly populated
                'id' => $itemId
            ])->send();

            /* TYPE 4 — DELETE */
        } elseif ($type === 4) {
            $itemId = isset($data['id']) ? $this->filter->sanitize($data['id'], 'string') : null;
            if (!$itemId)
                return $this->respondWithError(400, 'Invalid ID');

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $deleted = false;

            foreach ($rowsRaw as $row) {
                $decoded = json_decode($row, true);
                if ($decoded['id'] == $itemId) {
                    $this->redis->lRem($redisKey, 1, $row);
                    $deleted = true;
                    break;
                }
            }

            if (!$deleted)
                return $this->respondWithError(404, 'Record not found');

            return $this->response->setJsonContent([
                'result' => 'success',
                'message' => 'Successfully deleted the configuration',
                'id' => $itemId
            ])->send();

        } else {
            return $this->respondWithError(400, 'Invalid type');
        }
    }

    public function telegramconfigAction()
    {
        $this->view->disable();
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->request->isPost()) {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $data = json_decode($this->request->getRawBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return $this->respondWithError(400, 'Invalid JSON');
        }

        $type = isset($data['type'])
            ? (int) $this->filter->sanitize($data['type'], 'int')
            : 0;

        if (!$type) {
            return $this->respondWithError(400, 'Invalid type');
        }

        // Get FQDN from session
        $session = $this->session->get("auth");
        $fqdn = $session["subdomain"] ?? '';

        if ($fqdn === '') {
            return $this->respondWithError(400, 'fqdn is required');
        }

        $redisKey = "telegramconfig:fqdn:{$fqdn}";
        $webhookUrl = "https://{$fqdn}:8443/register/telegram";

        /* TYPE 1 — CREATE */
        if ($type === 1) {

            $requiredFields = ['accessToken', 'name'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->respondWithError(400, "$field is required");
                }
            }

            $accessToken = $this->filter->sanitize($data['accessToken'], 'string');
            $name = $this->filter->sanitize($data['name'], 'string');

            // Check duplicate name
            $existingRecords = $this->redis->lRange($redisKey, 0, -1);
            foreach ($existingRecords as $record) {
                $decoded = json_decode($record, true);
                if (isset($decoded['name']) && $decoded['name'] === $name) {
                    return $this->respondWithError(400, "Name: $name is already added.");
                }
            }

            $security = new \Phalcon\Security\Random();
            $id = $security->uuid();

            // ADD SOURCE PARAM
            $webhookUrl = "https://{$fqdn}:8443/register/telegram?source={$id}";

            // Register webhook
            $telegramApiUrl = "https://api.telegram.org/bot{$accessToken}/setWebhook?url=" . urlencode($webhookUrl);

            $payload = [
                'id' => $id,
                'fqdn' => $fqdn,
                'accessToken' => $accessToken,
                'name' => $name,
                'webhookUrl' => $webhookUrl,
                'created' => time()
            ];

            $this->redis->lPush($redisKey, json_encode($payload));

            return $this->response->setJsonContent([
                'result' => 'success',
                'message' => 'Successfully registered configuration',
                'webhookUrl' => $webhookUrl,
                'id' => $id
            ])->send();

            /* TYPE 2 — READ */
        } elseif ($type === 2) {
            $page = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
            $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));
            $start = ($page - 1) * $limit;

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $total = count($rowsRaw);

            $rowsPaginated = array_slice($rowsRaw, $start, $limit);
            $rows = array_map(fn($r) => json_decode($r, true), $rowsPaginated);

            return $this->response->setJsonContent([
                'result' => 'success',
                'data' => $rows,
                'pagination' => [
                    'totalRecords' => $total,
                    'totalPages' => ceil($total / $limit),
                    'currentPage' => $page,
                    'limit' => $limit
                ]
            ])->send();

            /* TYPE 3 — UPDATE */
        } elseif ($type === 3) {
            $itemId = isset($data['id']) ? $this->filter->sanitize($data['id'], 'string') : null;
            if (!$itemId)
                return $this->respondWithError(400, 'Invalid ID');

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $updated = false;
            $webhookUrl = null; // Declare outside so it persists after the loop

            foreach ($rowsRaw as $index => $row) {
                $decoded = json_decode($row, true);

                if ($decoded['id'] == $itemId) {
                    // 1. Update Access Token if provided
                    if (isset($data['accessToken']) && $data['accessToken'] !== ($decoded['accessToken'] ?? '')) {
                        $decoded['accessToken'] = $this->filter->sanitize($data['accessToken'], 'string');
                    }

                    // 2. Update Name if provided
                    if (isset($data['name'])) {
                        $decoded['name'] = $this->filter->sanitize($data['name'], 'string');
                    }

                    // 3. CAPTURE the URL from the existing data to return it later
                    $webhookUrl = $decoded['webhookUrl'] ?? null;

                    // 4. Save changes back to Redis
                    $this->redis->lSet($redisKey, $index, json_encode($decoded));
                    $updated = true;
                    break;
                }
            }

            if (!$updated)
                return $this->respondWithError(404, 'Record not found');

            return $this->response->setJsonContent([
                'result' => 'success',
                'message' => 'Successfully updated the configuration',
                'webhookUrl' => $webhookUrl, // Now correctly populated
                'id' => $itemId
            ])->send();
            /* TYPE 4 — DELETE */
        } elseif ($type === 4) {
            $itemId = isset($data['id']) ? $this->filter->sanitize($data['id'], 'string') : null;
            if (!$itemId)
                return $this->respondWithError(400, 'Invalid ID');

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $deleted = false;

            foreach ($rowsRaw as $row) {
                $decoded = json_decode($row, true);
                if ($decoded['id'] == $itemId) {
                    $this->redis->lRem($redisKey, 1, $row);
                    $deleted = true;
                    break;
                }
            }

            if (!$deleted)
                return $this->respondWithError(404, 'Record not found');

            return $this->response->setJsonContent([
                'result' => 'success',
                'message' => 'Successfully Deleted the configuration',
                'id' => $itemId
            ])->send();

        } else {
            return $this->respondWithError(400, 'Invalid type');
        }
    }

    public function transferagentAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $tenant = $this->chatSessionTenant();
        if ($tenant === "") {
            return $this->chatRefuse(401, "Unauthorized. (Code AI1001)");
        }
        $requestData = $this->request->getJsonRawBody(true);
        $requestData = is_array($requestData) ? $requestData : [];
        $redisKey = $requestData['redisKey'] ?? null;
        if ($redisKey !== null && !$this->chatLogKeyAllowed($tenant, $redisKey)) {
            return $this->chatRefuse(403, "That log does not belong to this account.");
        }

        // 1. Dynamic Multiplier: How many Redis items equal "1" unit in the UI?
        // Defaulting to 6 if the 'type' field is missing or 0
        $multiplier = (!empty($requestData['type']) && (int) $requestData['type'] > 0)
            ? (int) $requestData['type']
            : 6;

        // 2. Requested logical range (e.g., 1 to 4)
        $inputStart = isset($requestData['start']) ? (int) $requestData['start'] : 1;
        $inputEnd = isset($requestData['end']) ? (int) $requestData['end'] : 1;

        // 3. Calculation for Redis (which uses 0-based inclusive indexing)
        // Formula: (Page - 1) * ItemsPerRow  TO  (TotalRequested * ItemsPerRow) - 1
        $multiplier = min($multiplier, 20);
        $inputStart = max(1, $inputStart);
        $inputEnd = max($inputStart, min($inputEnd, $inputStart + 999));
        $realStart = ($inputStart - 1) * $multiplier;
        $realEnd = ($inputEnd * $multiplier) - 1;

        if (!$redisKey) {
            return $this->response->setStatusCode(400)->setJsonContent([
                "status" => "error",
                "message" => "No redisKey provided"
            ]);
        }

        try {
            // 4. Fetch the dynamic slice
            $overallcount = "";
            $rawData = $this->redis->lRange($redisKey, $realStart, $realEnd);
            $overallcount = $this->redis->lLen($redisKey);

            $content = array_map(function ($item) {
                $decoded = json_decode($item, true);
                return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $item;
            }, $rawData ?: []);

            return $this->response->setJsonContent([
                "status" => "success",
                "metadata" => [
                    "items_per_group" => $multiplier,
                    "requested_range" => "$inputStart to $inputEnd",
                    "redis_indices" => "$realStart to $realEnd",
                    "total_count" => count($content),
                    "Overall_count" => $overallcount
                ],
                "content" => $content
            ]);

        } catch (\Exception $e) {
            return $this->response->setStatusCode(500)->setJsonContent([
                "status" => "error",
                "message" => "Redis Error: " . ControllerBase::safeErrorMessage($e)
            ]);
        }
    }

    private function latestDataNew($page = 1, $searching = "", $owner = "", $all = "true", $type = "", $email_Agent = "", $username_Agent = "", $count = 0)
    {
        $limit = intval($count);
        $offset = 0;
        $session = $this->session->get("auth");

        $hostdomain = $session["subdomain"];

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
            $key = $session ? $session["subdomain"] . "-conversation" : "demo.us1.ringq.ai-conversation";

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
            // $pipe->hSet($agentNewKey, "roomstatus", $equivalent);
            // $pipe->execute();



            $totalItems = $this->redis->zCard($newKey);
            $total_count = $totalItems;



            if ($totalItems > 0) {
                // Compute offset based on page count
                $totalPages = ceil($totalItems / $limit);
                $page = min(max(1, $page), $totalPages); // Ensure page is within bounds
                $offset = ($page - 1) * $limit;
                $end = $offset + $limit - 1;

                // if($searching != ""){
                //     $client_data = $this->redis->zRevRange($newKey ['withscores' => true]);
                // } else {
                //     $client_data = $this->redis->zRevRange($newKey, $offset, $end, ['withscores' => true]);
                // }
                // if ($searching != "") {

                //     $client_data = $this->redis->zRevRange($newKey, 0, -1, ['withscores' => true]); 
                // } else {
                //         // Standard pagination: offset and end are provided
                //     $client_data = $this->redis->zRevRange($newKey, $offset, $end, ['withscores' => true]);
                // }
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
                    if (preg_match('/-(website|whatsapp|facebook|telnyx|twilio|telegram|line)-/', $item, $m)) {
                        $platform = $m[1];
                        $parts = explode('-', $item);
                        $username = $parts[0] ?? "";
                        $email = $parts[1] ?? "";
                        $id = $parts[3] ?? "";
                        if ($platform === "website") {
                            $userKey = "{$id}-{$platform}-{$username}-{$email}-{$hostdomain}";
                            $userinbox = "{$hostdomain}-external-{$email}-{$id}";
                        } else if ($platform === "whatsapp") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userinbox = "{$hostdomain}:whatsapp:919894340047:{$email}-{$id}";
                        } else if ($platform === "facebook") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userinbox = "{$hostdomain}:facebook:919894340047:{$email}";
                        } else if ($platform === "telnyx") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                            $userinbox = "{$hostdomain}:telnyx:{$id}:{$id}-{$email}";
                        } else if ($platform === "twilio") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                        } else if ($platform === "telegram") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                        } else if ($platform === "line") {
                            $userKey = "{$email}-{$platform}-{$username}-{$id}-{$hostdomain}";
                        }




                        // } else if (_channel == "telnyx") {
                        //   clientmessagesinbox = "${hostnameParam!.host}:telnyx:${selectournumber.toString()}:${selectournumber.toString()}-${selectedemail.toString()}";
                        // } else if (_channel == "twilio") {
                        //   clientmessagesinbox = "${hostnameParam!.host}:twilio:${selectournumber.toString()}:${selectournumber.toString()}-${selectedemail.toString()}";
                        // } else if (_channel == "telegram") {
                        //   clientmessagesinbox = "${hostnameParam!.host}:telegram:${selectournumber.toString()}:${selectournumber.toString()}-${selectedemail.toString()}";
                        // } else if (_channel == "line") {
                        //   clientmessagesinbox = "${hostnameParam!.host}:line:${selectedID.toString()}:${selectedID.toString()}-${selectedemail.toString()}";
                        // }
                        $hashData += $this->redis->hGetAll($userKey);
                    } else {
                        $username = $email = $id = "";
                    }
                    $hashData["_user_key"] = $userKey ?? '';
                    $hashData["_redis_key"] = $item;
                    $hashData["_username"] = $username ?? '';
                    $hashData["_email"] = $email ?? '';
                    $hashData["ss"] = $userinbox;
                    $new_unread[] = $hashData;
                }

                // $pipe = $this->redis->pipeline();
                // foreach ($new_unread as $hashData) {
                //     if (
                //         ($hashData["allocated_room"] ?? "") === "CLOSED"
                //         && !empty($hashData["_user_key"])
                //     ) {
                //         $pipe->hSet($hashData["_user_key"], "allocated_room", "CLOSED");
                //     }
                // }



                // $pipe->execute();
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

                        if (preg_match('/-(website|whatsapp|facebook|telnyx|twilio|telegram|line)-/', $customerKey, $m)) {
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
                } else {
                    // error_log("[latestDataNew] Assigned customers JSON not an array for agent: " . $agentKey);
                }
            } else {
                // error_log("[latestDataNew] No assigned data found for agent: " . $agentKey);
            }


            $total_count = count($assignedCustomersData);
            $totalPages = ($total_count > 0) ? ceil($total_count / $limit) : 1;
            $page = min(max(1, $page), $totalPages);
            $offset = intval(($page - 1) * $limit); // ensure int
            $new_unread = is_array($assignedCustomersData)
                ? array_slice($assignedCustomersData, $offset, $limit)
                : [];

        }


        $hasNext = $total_count > ($offset + $limit);

        return [
            "customersResult" => $customerResults ?? "",
            "page" => $page,
            "data" => $new_unread,
            "total" => $total_count,
            "next" => $hasNext ? $page + 1 : false,
            "hasMore" => $hasNext,
            "agentnewkey" => $agentNewKey

        ];
    }



    public function safeJsonDecode($value)
    {
        // If it's already an array, just return it
        if (is_array($value)) {
            return $value;
        }

        // If it's a string, try decoding once
        if (is_string($value)) {
            $first = json_decode($value, true);

            // If the first decode gives another string (double encoded)
            if (is_string($first)) {
                $second = json_decode($first, true);
                return $second ?? [];
            }

            return $first ?? [];
        }

        // Anything else → return empty array
        return [];
    }

    public function updateroomAction()
    {
        //Download and send
        // $email = new Library\Email();
        // 1. Prepare JSON response environment
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        // 2. Capture the posted JSON data from Flutter
        // This looks for the "redisKey" field in your http client body


    }


    /**
     * getassignAction
     *
     * RediSearch-based fetch of one or more conversations. Each conversation is
     * fetched starting from its own anchor timestamp and ONLY returns the anchor
     * message + newer messages — it does NOT fetch the older backlog.
     *
     * Now expects a LIST of conversations. Each list item carries its own
     * anchor (messageID) and clientKey (member):
     *   {
     *     "fqdn": "<fqdn>",
     *     "clientmessagelist": [
     *       { "messageID": <timestamp>, "member": "<clientKey>" },
     *       ...
     *     ]
     *   }
     */
    public function getassignAction()
    {
        $email = new Library\Email();
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $data = $this->request->getJsonRawBody();
        $allowedTenant = $this->chatSessionTenant();
        if ($allowedTenant === "" && $this->chatFromThisServer()) {
            $allowedTenant = $this->chatHostTenant();
        }
        if ($allowedTenant === "") {
            return $this->chatRefuse(401, "Unauthorized. (Code AI1001)");
        }
        if (!is_object($data)) {
            return $this->chatRefuse(400, "fqdn is required.");
        }

        // Early return if any required field is missing (checked one by one)
        if (empty($data->fqdn)) {
            return $this->response->setStatusCode(400)->setJsonContent([
                "result"  => "error",
                "message" => "fqdn is required."
            ]);
        }
        if (empty($data->clientmessagelist) || !is_array($data->clientmessagelist)) {
            return $this->response->setStatusCode(400)->setJsonContent([
                "result"  => "error",
                "message" => "clientmessagelist is required and must be a list."
            ]);
        }

        if (strtolower(trim((string) $data->fqdn)) !== $allowedTenant) {
            return $this->chatRefuse(403, "That chat does not belong to this account.");
        }
        foreach ($data->clientmessagelist as $item) {
            $member = is_object($item) ? (string) ($item->member ?? '') : '';
            if ($member !== '' && !$this->chatMemberAllowed($allowedTenant, $member)) {
                return $this->chatRefuse(403, "That chat does not belong to this account.");
            }
        }

        $redisKey = $data->fqdn . "-emailsettings";

        // Fetch the specific 'email' field from the Redis hash
        $emailsettings = $this->redis->hGet($redisKey, 'email');

        if (empty($emailsettings)) {
            return $this->response->setStatusCode(400)->setJsonContent([
                "result"  => "error",
                "message" => "An email address is required. Please update your email settings."
            ]);
        }

        $fqdn   = $data->fqdn;
        $status = $data->status ?? '';
        $index  = "$fqdn-database";

        // Define the internal order (Data5 down to Data1)
        $targetFields = ['Data5', 'Data4', 'Data3', 'Data2', 'Data1'];

        $getLicenseConfig = $this->redis->get('ringq_config');
        $licenseConfig    = json_decode($getLicenseConfig, true);
        $customName       = "RingQ";

        $conversations = []; // per-member response payload

        try {
            // Loop over every requested conversation
            foreach ($data->clientmessagelist as $item) {
                $member    = $item->member    ?? '';
                $messageID = $item->messageID  ?? '0';

                // Skip malformed list entries
                if (empty($member)) {
                    continue;
                }

                // 1. Sanitize the per-item anchor timestamp
                $timestamp = filter_var($messageID, FILTER_SANITIZE_NUMBER_INT);
                if ($timestamp === '' || $timestamp === false) {
                    $timestamp = '0';
                }

                // 2. Resolve the roomID (+ reference/channel) from the clientKey
                $roomID        = null;
                $referencename = "";
                $messageType   = "";

                $query2  = "@clientKey:{\"$member\"}";
                $output2 = $this->redis->executeRaw(['FT.SEARCH', $index, $query2, 'SORTBY', 'timestamp', 'ASC', 'LIMIT', '0', '1', 'DIALECT', '2']);

                if (is_array($output2) && isset($output2[0]) && $output2[0] > 0) {
                    $firstDocFields = $output2[2] ?? [];

                    // Build a field => value lookup map (order-independent)
                    $fieldMap = [];
                    for ($i = 0; $i < count($firstDocFields); $i += 2) {
                        $fieldMap[$firstDocFields[$i]] = $firstDocFields[$i + 1] ?? '';
                    }

                    $roomID        = $fieldMap['roomID']      ?? null;
                    $referencename = $fieldMap['username']    ?? '';
                    $messageType   = $fieldMap['messageType'] ?? '';
                }

                // No room resolved for this member — record and move on
                if ($roomID === null) {
                    $conversations[] = [
                        "member"       => $member,
                        "result"       => "error",
                        "message"      => "No messages found for the specified criteria.",
                        "total"        => 0,
                        "conversation" => []
                    ];
                    continue;
                }

                // 3. Fetch the anchor message + everything newer for this room
                $query  = "@timestamp:[$timestamp +inf] @roomID:{\"$roomID\"}";
                $output = $this->redis->executeRaw([
                    'FT.SEARCH',
                    $index,
                    $query,
                    'LIMIT', '0', '1000',
                    'SORTBY', 'timestamp', 'ASC',
                    'DIALECT', '2'
                ]);

                $totalResults = $output[0] ?? 0;

                if ($totalResults === 0) {
                    $conversations[] = [
                        "member"       => $member,
                        "result"       => "error",
                        "message"      => "No messages found for the specified criteria.",
                        "total"        => 0,
                        "conversation" => []
                    ];
                    continue;
                }

                $documents = [];
                for ($i = 1; $i < count($output); $i += 2) {
                    $documents[] = [
                        'key'    => $output[$i],
                        'fields' => $output[$i + 1] ?? []
                    ];
                }

                // 4. Flatten every document into the single combined result set
                $results = [];
                foreach ($documents as $doc) {
                    $fields = $doc['fields'];

                    // Create a quick lookup map for this document
                    $fieldMap = [];
                    for ($j = 0; $j < count($fields); $j += 2) {
                        $fieldMap[$fields[$j]] = $fields[$j + 1] ?? '';
                    }

                    // Build this row in the defined field order
                    foreach ($targetFields as $target) {
                        $results[] = $fieldMap[$target] ?? '';
                    }
                }

                error_log("the one being sent an email is $emailsettings");
                // 5. Send a CSV email for this conversation
                $email->send($emailsettings, "Transcript of Conversation", "Conversation Logs", [
                    "type"          => "resolveoffline",
                    "hostname"      => $fqdn,
                    "data"          => $results,
                    "channel"       => $messageType,
                    "referencename" => $referencename,
                    "status"        => $status
                ], $licenseConfig, $customName);

                $conversations[] = [
                    "member"       => $member,
                    "result"       => "success",
                    "total"        => $totalResults,
                    "conversation" => $results
                ];
            }

            return $this->response->setJsonContent([
                "result"        => "success",
                "email"         => $emailsettings,
                "redisKey"      => $redisKey,
                "count"         => count($conversations),
                "conversations" => $conversations
            ]);

        } catch (\Exception $e) {
            return $this->response->setJsonContent([
                "result"  => "error",
                "message" => "Redis Error: " . ControllerBase::safeErrorMessage($e)
            ]);
        }
    }

    

     public function updateassignAction()
     {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get('auth');
        $fqdn = $session['subdomain'] ?? null;

        $data = $this->request->getJsonRawBody();

        // 1. Sanitize the inputs
        $timestamp = (isset($data->messageID)) ? filter_var($data->messageID, FILTER_SANITIZE_NUMBER_INT) : '0';
        $index = "$fqdn-database";
        $roomID = $data->clientmessagelist ?? '';

        // 2. Wrap the room ID in double quotes for DIALECT 2 tag parsing
        // We use \" so RediSearch doesn't split on dots and colons
        $query  = "@timestamp:[($timestamp +inf] @roomID:{\"$roomID\"}";
        $query2 = "@timestamp:[-inf ($timestamp] @roomID:{\"$roomID\"}";
        $query3 = "@timestamp:[$timestamp $timestamp] @roomID:{\"$roomID\"}";

        try {
            // 3. Pass DIALECT 2 as separate parameters in executeRaw
            $output  = $this->redis->executeRaw(['FT.SEARCH', $index, $query,  'SORTBY', 'timestamp', 'ASC',  'LIMIT', '0', '20', 'DIALECT', '2']);
            $output2 = $this->redis->executeRaw(['FT.SEARCH', $index, $query2, 'SORTBY', 'timestamp', 'DESC', 'LIMIT', '0', '20', 'DIALECT', '2']);
            $output3 = $this->redis->executeRaw(['FT.SEARCH', $index, $query3, 'DIALECT', '2']);

            $totalResults = $output[0] ?? 0;
            $results = [];
            $results2 = [];
            $results3 = [];
            $documents = [];
            $documents2 = [];
            $documents3 = [];

            for ($i = 1; $i < count($output); $i += 2) {
                $documents[] = [
                    'key'   => $output[$i],
                    'fields' => $output[$i + 1] ?? []
                ];
            }

            for ($i = 1; $i < count($output2); $i += 2) {
                $documents2[] = [
                    'key'    => $output2[$i],
                    'fields' => $output2[$i + 1] ?? []
                ];
            }
            
            for ($i = 1; $i < count($output3); $i += 2) {
                $documents3[] = [
                    'key'    => $output3[$i],
                    'fields' => $output3[$i + 1] ?? []
                ];
            }
        
            // 2. Reverse the documents so the last one found is processed first
            $documents = array_reverse($documents);
            // $documents2 = array_reverse($documents2);
            
        
            // 3. Define the internal order (Data5 down to Data1)
            $targetFields = ['Data5', 'Data4', 'Data3', 'Data2', 'Data1'];
            $topID = "";
            $bottomID = "";
        
            // foreach ($documents as $doc) {
            //     $fields = $doc['fields'];
                
            //     // Create a quick lookup map for this document
            //     $fieldMap = [];
            //     for ($j = 0; $j < count($fields); $j += 2) {
            //         $fieldMap[$fields[$j]] = $fields[$j + 1] ?? '';
            //     }
                
            //     // Push the values into the result array
            //     foreach ($targetFields as $target) {
            //         $results[] = $fieldMap[$target] ?? '';
            //     }
            // }
            foreach ($documents as $doc) {
                $fields = $doc['fields'];
                
                // Create a quick lookup map for this document
                $fieldMap = [];
                for ($j = 0; $j < count($fields); $j += 2) {
                    $fieldMap[$fields[$j]] = $fields[$j + 1] ?? '';
                }
            
                if (isset($fieldMap['Data2']) && $bottomID == "") {
                    $bottomID = $fieldMap['Data2'];
                }
                
                // Push the values into the result array
                foreach ($targetFields as $target) {
                    $results[] = $fieldMap[$target] ?? '';
                }
            }

            foreach ($documents2 as $doc) {
                $fields = $doc['fields'];
                
                // Create a quick lookup map for this document
                $fieldMap = [];
                for ($j = 0; $j < count($fields); $j += 2) {
                    $fieldMap[$fields[$j]] = $fields[$j + 1] ?? '';
                }
                if (isset($fieldMap['Data2'])) {
                    $topID = $fieldMap['Data2'];
                }
                
                // Push the values into the result array
                foreach ($targetFields as $target) {
                    $results2[] = $fieldMap[$target] ?? '';
                }
            }

            foreach ($documents3 as $doc) {
                $fields = $doc['fields'];
                
                // Create a quick lookup map for this document
                $fieldMap = [];
                for ($j = 0; $j < count($fields); $j += 2) {
                    $fieldMap[$fields[$j]] = $fields[$j + 1] ?? '';
                }
                if (isset($fieldMap['Data2'])) {
                    if($topID == ""){
                        $topID = $fieldMap['Data2'];
                    }
                    if($bottomID == ""){
                        $bottomID = $fieldMap['Data2'];
                    }
                    
                }
                
                // Push the values into the result array
                foreach ($targetFields as $target) {
                    $results3[] = $fieldMap[$target] ?? '';
                }
            }
        
            return $this->response->setJsonContent([
                "result" => "success",
                "total" => $totalResults,
                "datadown" => $results,
                "dataup" => $results2,
                "datasearched" => $results3,
                "query1" => $query,
                "query2" => $query2,
                "fullsyntax" => "Queue1: FT.SEARCH $index $query",
                "topID" => $topID,
                "bottomID" => $bottomID
            ]);

        } catch (\Exception $e) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "Redis Error: " . ControllerBase::safeErrorMessage($e)
            ]);
        }
    }

   

    public function updateassign1Action()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $redis = $this->redis;

        // image this agentUserkey is a list like this ["opSS2Hx6QRKW5wrlOsHtzA==-Agent-65137a5610d0c334a57c-angelostalagtag@gmail.com", "ib44WQ0KZbRMZcMtqLkEGQ==-Agent-Jenifer Love-rym@ringq.com"] 
        // and each agentuserkey must update in assign field the clientkey added, same as the agentkey in assigned_agent in clientkey

        $agentUserkey = $this->request->getPost("agentUserkey", "string");

        $clientUserkey = $this->request->getPost("clientUserkey", "string");

        // --- Fetch Agent Data ---
        $agentData = $redis->hGetAll($agentUserkey);
        $assignedClients = [];
        if (!empty($agentData) && isset($agentData["assigned"])) {
            $value = $agentData["assigned"];
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $assignedClients = is_array($decoded) ? $decoded : [];
            } elseif (is_array($value)) {
                $assignedClients = $value;
            }
        }

        // --- Fetch Client Data ---
        $clientData = $redis->hGetAll($clientUserkey);
        $clientAssigned = [];
        if (!empty($clientData) && isset($clientData["assigned_agent"])) {
            $val = $clientData["assigned_agent"];
            if (is_string($val)) {
                $dec = json_decode($val, true);
                $clientAssigned = is_array($dec) ? $dec : [];
            } elseif (is_array($val)) {
                $clientAssigned = $val;
            }
        }

        // --- Determine Action ---
        $agentHasClient = in_array($clientUserkey, $assignedClients, true);
        $clientHasAgent = in_array($agentUserkey, $clientAssigned, true);

        if ($agentHasClient && $clientHasAgent) {
            // ✅ REMOVE both sides
            $assignedClients = array_values(array_diff($assignedClients, [$clientUserkey]));
            $clientAssigned = array_values(array_diff($clientAssigned, [$agentUserkey]));

            $redis->hSet($agentUserkey, "assigned", json_encode($assignedClients));
            $redis->hSet($clientUserkey, "assigned_agent", json_encode($clientAssigned));

            $action = "removed";
        } else {
            // ✅ ADD to both sides (insert at index 0)
            if (!$agentHasClient) {
                array_unshift($assignedClients, $clientUserkey);
                $redis->hSet($agentUserkey, "assigned", json_encode($assignedClients));
            }
            if (!$clientHasAgent) {
                array_unshift($clientAssigned, $agentUserkey);
                $redis->hSet($clientUserkey, "assigned_agent", json_encode($clientAssigned));
            }

            $action = "added";
        }

        // --- Response ---
        $data = [
            "action" => $action,
            "agentUserkey" => $agentUserkey,
            "clientUserkey" => $clientUserkey,
            "assignedClients" => $assignedClients,
            "clientAssigned" => $clientAssigned
        ];

        return $this->response->setJsonContent($data);
    }

    public function getallAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $redis = $this->redis;
        $users = $redis->lRange('agent_data', 0, -1);

        $agents = array_map(function ($agentKey) use ($redis) {

            // Split agent key into parts
            [$id, $role, $username, $email] = explode('-', $agentKey, 4);

            // Fetch agent hash
            // $fields = ['Agent_Name', 'extension'];
            // $values = $redis->hmget($agentKey, $fields);

            // $agentData = array_combine($fields, $values);

            // if (!empty($agentData['Agent_Name'])) {
            //     return [
            //         'id'        => $id,
            //         'username'  => $username,
            //         'email'     => $email,
            //         'key'       => $agentKey,
            //         'agentName' => $agentData['Agent_Name'],
            //         'extension' => $agentData['extension']
            //     ];
            // }
            $session = $this->session->get("auth");

            $fields = ['Agent_Name', 'extension'];
            $values = $redis->hmget($agentKey, $fields);
            $agentData = array_combine($fields, $values);

            if (!empty($agentData['Agent_Name']) && !empty($agentData['extension'])) {
                $extensionWithDomain = $agentData['extension'] . ':' . "demo.us1.ringq.ai";


                // use it to fetch another value from Redis
                $extensionData = $redis->get($extensionWithDomain);
                // $extensionprofilePic = $redis->get($extensionData);

                return [
                    'id' => $id,
                    'username' => $username,
                    'email' => $email,
                    'key' => $agentKey,
                    'agentName' => $agentData['Agent_Name'],
                    'extension' => $agentData['extension'],
                    'extensionkey' => $extensionWithDomain,
                    'extensionget' => $extensionData,
                    // 'extensionprofilePic' => $extensionprofilePic,
                ];
            }



            // Return null if Agent_Name is not set
            return null;

        }, $users);

        // Remove null entries and reindex
        $agents = array_values(array_filter($agents));

        echo json_encode($agents);
    }

    public function emojiAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        // Initialize parameters
        $parameters = json_decode($this->request->getRawBody(), true);
        if (
            empty($parameters['id']) ||
            empty($parameters['roomID']) ||
            empty($parameters['emoji'])
        ) {
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'Missing required fields: id, roomID, or emoji'
            ])->send();
        } else {
            print_r($parameters);
        }
    }

    public function closeAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $session = $this->session->get("auth");
        $fqdn = $this->chatSessionTenant();
        if ($fqdn === "") {
            return $this->chatRefuse(401, "Unauthorized. (Code AI1001)");
        }
        if (!$this->request->isPost()) {
            return $this->chatRefuse(405, "POST required.");
        }
        if ($this->isPlainUser($session)) {
            return $this->chatRefuse(403, "Sorry, this is not available. (Code AC4031)");
        }

        $keysToDelete = [];
        $parentkeyDelete = [];

        $totalAgentUnread = "$fqdn-totalAgentUnread";
        $agentsassignedUnreadlist = $this->redis->zrange("$totalAgentUnread", 0, -1);

        // Delete each key stored inside the sorted set
        if (!empty($agentsassignedUnreadlist)) {
            foreach ($agentsassignedUnreadlist as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }
        $this->redis->del("$fqdn-totalAgentUnread");
        $parentkeyDelete[] = "$totalAgentUnread";

        $overallUnreadCount = "$fqdn-overallUnreadCount";
        $clientUnreadlist = $this->redis->zrange("$overallUnreadCount", 0, -1);

        // Delete each key stored inside the sorted set
        if (!empty($clientUnreadlist)) {
            foreach ($clientUnreadlist as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }
        $this->redis->del("$overallUnreadCount");
        $parentkeyDelete[] = "$overallUnreadCount";


        $agent_data = "$fqdn-agent_data";
        $existingagents = $this->redis->lRange("$agent_data", 0, -1);

        // Delete each key stored inside the sorted set
        if (!empty($existingagents)) {
            foreach ($existingagents as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $this->redis->del("$agent_data");
        $parentkeyDelete[] = "$agent_data";


        $extentiondatalist = "$fqdn-extensiondatalist";
        $agentExtension = $this->redis->sMembers($extentiondatalist);

        // Delete each key stored inside the sorted set
        if (!empty($agentExtension)) {
            foreach ($agentExtension as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $this->redis->del("$extentiondatalist");
        $parentkeyDelete[] = "$extentiondatalist";


        $client_data = "$fqdn-client_data";
        $existingUsers = $this->redis->lRange("$client_data", 0, -1);

        $updatedUsers = array_map(function ($user) use ($fqdn) {
            return $user . "-$fqdn";
        }, $existingUsers);


        // Delete each key stored inside the sorted set
        if (!empty($updatedUsers)) {
            foreach ($updatedUsers as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $this->redis->del("$client_data");
        $parentkeyDelete[] = "$client_data";




        $external = "$fqdn-external-*";
        $websiteinbox = $this->redis->keys("$external");

        // Delete each key stored inside the sorted set
        if (!empty($websiteinbox)) {
            foreach ($websiteinbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $telnyx = "$fqdn:telnyx:*";
        $telnyxinbox = $this->redis->keys("$telnyx");

        // Delete each key stored inside the sorted set
        if (!empty($telnyxinbox)) {
            foreach ($telnyxinbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $twilio = "$fqdn:twilio:*";
        $twilioinbox = $this->redis->keys("$twilio");

        // Delete each key stored inside the sorted set
        if (!empty($twilioinbox)) {
            foreach ($twilioinbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $telegram = "$fqdn:telegram:*";
        $telegraminbox = $this->redis->keys("$telegram");

        // Delete each key stored inside the sorted set
        if (!empty($telegraminbox)) {
            foreach ($telegraminbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $line = "$fqdn:line:*";
        $lineinbox = $this->redis->keys("$line");

        // Delete each key stored inside the sorted set
        if (!empty($lineinbox)) {
            foreach ($lineinbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }


        $whatsapp = "$fqdn:whatsapp:919894340047:*";
        $whatsappinbox = $this->redis->keys("$whatsapp");

        // Delete each key stored inside the sorted set
        if (!empty($whatsappinbox)) {
            foreach ($whatsappinbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $facebook = "$fqdn:facebook:919894340047:*";
        $facebookpinbox = $this->redis->keys("$facebook");

        // Delete each key stored inside the sorted set
        if (!empty($facebookpinbox)) {
            foreach ($facebookpinbox as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $conversation = "$fqdn-conversation*";
        $conversationlist = $this->redis->keys("$conversation");

        // Delete each key stored inside the sorted set
        if (!empty($conversationlist)) {
            foreach ($conversationlist as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        $ratelimiting = "$fqdn:webchat:ratelimiting:banned*";
        $ratelimitinglist = $this->redis->keys("$ratelimiting");

        // Delete each key stored inside the sorted set
        if (!empty($ratelimitinglist)) {
            foreach ($ratelimitinglist as $key) {
                $this->redis->del("$key");
                $keysToDelete[] = $key;
            }
        }

        foreach (['-msgindex', '-database', '-profiles'] as $suffix) {
            $index = $fqdn . $suffix;
            exec("redis-cli FT.DROPINDEX $index DD", $output, $resultCode);

            if ($resultCode === 0) {
                $parentkeyDelete[] = $index;
            } else {
                echo "Error on $index: Code $resultCode";
            }
        }

        $waitingQueue = "$fqdn-waitingQueue";
        $this->redis->del("$waitingQueue");
        $parentkeyDelete[] = "$waitingQueue";



        $listdata = [
            "listconversation" => $conversationlist,
            "totalagentsassignedunreadlist" => $agentsassignedUnreadlist,
            "totalunreadall" => $clientUnreadlist,
            "facebookpinbox" => $facebookpinbox,
            "whatsappinbox" => $whatsappinbox,
            // "websiteinbox" => $websiteinbox,
            "users" => $updatedUsers,
            "agents" => $existingagents,
            "agentsextensions" => $agentExtension,
            "deletingdata" => [
                "childkey" => $keysToDelete,
                "parentkey" => $parentkeyDelete,
            ]
        ];

        return $this->response
            ->setJsonContent($listdata)
            ->setStatusCode(200, "OK")
            ->send();
    }
    public function updateQuickAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $redisKey = "Agent_Quick_Reply";

        try {
            $type = $this->request->getPost("type", "string");
            $value = $this->request->getPost("value", "string"); // only used for add/delete

            if (!$type) {
                return $this->response->setJsonContent([
                    "success" => false,
                    "message" => "Missing required field: type"
                ]);
            }

            switch ($type) {
                case "new":
                    if (!$value) {
                        return $this->response->setJsonContent([
                            "success" => false,
                            "message" => "Missing value to add"
                        ]);
                    }
                    $added = $this->redis->sAdd($redisKey, $value);
                    return $this->response->setJsonContent([
                        "success" => true,
                        "action" => "add",
                        "added" => (bool) $added,
                        "value" => $value
                    ]);

                case "delete":
                    if (!$value) {
                        return $this->response->setJsonContent([
                            "success" => false,
                            "message" => "Missing value to delete"
                        ]);
                    }
                    $removed = $this->redis->sRem($redisKey, $value);
                    return $this->response->setJsonContent([
                        "success" => true,
                        "action" => "delete",
                        "removed" => (bool) $removed,
                        "value" => $value
                    ]);

                case "get":
                    $data = $this->redis->sMembers($redisKey);
                    return $this->response->setJsonContent([
                        "success" => true,
                        "count" => count($data),
                        "data" => $data
                    ]);

                default:
                    return $this->response->setJsonContent([
                        "success" => false,
                        "message" => "Invalid type. Use 'new', 'delete' or 'get'."
                    ]);
            }

        } catch (\Exception $e) {
            return $this->response->setJsonContent([
                "success" => false,
                "error" => ControllerBase::safeErrorMessage($e)
            ]);
        }
    }
    public function webchatconfigAction()
    {
        $this->view->disable();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return $this->respondWithError(400, 'Invalid JSON');
        }

        $type = isset($data['type'])
            ? (int) $this->filter->sanitize($data['type'], 'int')
            : 0;

        if (!$type) {
            return $this->respondWithError(400, 'Invalid type');
        }

        // $fqdn = isset($data['fqdn'])
        //     ? trim($this->filter->sanitize($data['fqdn'], 'string'))
        //     : '';


        $session = $this->session->get('auth');
        $fqdn = $session['subdomain'] ?? null;

        if ($fqdn === '') {
            return $this->respondWithError(400, 'fqdn is required');
        }

        $redisKey = "webchatconfig:fqdn:{$fqdn}";

        /* =========================
           TYPE 1 — CREATE
        ========================== */
        if ($type === 1) {

            foreach (['name', 'url', 'what'] as $field) {
                if (empty($data[$field])) {
                    return $this->respondWithError(400, "$field is required");
                }
            }

            $name = $this->filter->sanitize($data['name'], 'string');
            $url = $this->filter->sanitize($data['url'], 'string');
            $what = $this->filter->sanitize($data['what'], 'string');


            if ($what !== 'plugin' && $what !== 'widget') {
                return $this->respondWithError(400, 'Invalid what value only allowed is plugin or widget');
            }


            $security = new \Phalcon\Security\Random();
            $id = $security->uuid();


            $accessToken = $security->uuid();

            $embedScript =
                '<script src="https://' . $fqdn .
                '/iframes/chat-embed.php?accesstoken=' . urlencode($accessToken) .
                '&color=%23FFFFFF&img=https%3A%2F%2Fwww.ringq.com%2Fwp-content%2Fuploads%2F2024%2F01%2F71580-2.png' .
                '&fqdn=' . urlencode($fqdn) .
                '&tokenized=%242y%2410%24Q0c5WUlCQnFZYU0zRTlEMeRnveV00SzK5qaPEf5XsE.MVKncD4m9C"></script>';



            $payload = [
                'id' => $id,
                'fqdn' => $fqdn,
                'what' => $what,
                'name' => $name,
                'embedScript' => $embedScript,
                'accessToken' => $accessToken,
                'url' => $url,
                'created' => time()
            ];

            $this->redis->lPush($redisKey, json_encode($payload));

            echo json_encode([
                'result' => 'success',
                'message' => 'Successfully Add chat settings',
                'embedScript' => $embedScript,
                'accessToken' => $accessToken,
                'id' => $id
            ]);
            return;
        }

        /* =========================
           TYPE 2 — READ (search + pagination)
        ========================== */
        if ($type === 2) {

            $page = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
            $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));

            $start = ($page - 1) * $limit;
            $end = $start + $limit - 1;

            $rowsRaw = $this->redis->lRange($redisKey, $start, $end);

            $rows = array_values(array_filter(
                array_map(fn($r) => json_decode($r, true), $rowsRaw),
                fn($row) => is_array($row)
            ));

            $total = $this->redis->lLen($redisKey);



            echo json_encode([
                'result' => 'success',
                'data' => $rows,
                'pagination' => [
                    'totalRecords' => $total,
                    'totalPages' => ceil($total / $limit),
                    'currentPage' => $page,
                    'limit' => $limit
                ]
            ]);
            return;

        }

        /* =========================
           TYPE 3 — UPDATE (ONLY name & url)
        ========================== */
        if ($type === 3) {

            $itemId = isset($data['id'])
                ? (string) $this->filter->sanitize($data['id'], 'string')
                : 0;

            if (!$itemId) {
                return $this->respondWithError(400, 'Invalid ID');
            }

            $updatableFields = ['name', 'url'];

            $hasUpdate = false;
            foreach ($updatableFields as $field) {
                if (isset($data[$field])) {
                    $hasUpdate = true;
                    break;
                }
            }

            if (!$hasUpdate) {
                return $this->respondWithError(400, 'No fields to update');
            }

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $updated = false;

            foreach ($rowsRaw as $index => $row) {
                $decoded = json_decode($row, true);

                if ($decoded['id'] == $itemId) {
                    foreach ($updatableFields as $field) {
                        if (isset($data[$field])) {
                            $decoded[$field] = $this->filter->sanitize($data[$field], 'string');
                        }
                    }

                    $this->redis->lSet($redisKey, $index, json_encode($decoded));
                    $updated = true;
                    break;
                }
            }

            if (!$updated) {
                return $this->respondWithError(404, 'Record not found');
            }

            echo json_encode([
                'result' => 'success',
                'id' => $itemId,
                'message' => 'Successfully Update chat settings'
            ]);
            return;
        }

        /* =========================
           TYPE 4 — DELETE
        ========================== */
        if ($type === 4) {

            $itemId = isset($data['id'])
                ? (string) $this->filter->sanitize($data['id'], 'string')
                : 0;

            if (!$itemId) {
                return $this->respondWithError(400, 'Invalid ID');
            }


            $qCallQueues = VCallCenterQueues::find(
                [
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" =>
                        [
                            "domain_uuid" => $session["domain_uuid"],
                        ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]
            );

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $deleted = false;

            foreach ($rowsRaw as $row) {
                $decoded = json_decode($row, true);
                if ($decoded['id'] == $itemId) {
                    foreach ($qCallQueues as $qCallQueue) {
                        $webChats = $this->redis->lrange($qCallQueue->queue_extension . "@" . $session["subdomain"] . "-webchat-chat", 0, -1);
                        if ($webChats) {
                            if (in_array($decoded["id"], $webChats)) {
                                return $this->respondWithError(404, 'Sorry, please unassign this chat account first from its queue assignement');
                            }
                        }
                    }
                    $this->redis->lRem($redisKey, 1, $row);
                    $deleted = true;
                    break;
                }
            }

            if (!$deleted) {
                return $this->respondWithError(404, 'Record not found');
            }

            echo json_encode([
                'result' => 'success',
                'id' => $itemId,
                'message' => 'Successfully Delete chat settings'
            ]);
            return;
        }

        /* =========================
           TYPE 5 — ROTATE ACCESS TOKEN (DEBUG)
        ========================== */
        if ($type === 5) {



            if (empty($data['id'])) {

                return $this->respondWithError(400, 'id is required');
            }

            $id = $this->filter->sanitize($data['id'], 'string');


            $items = $this->redis->lRange($redisKey, 0, -1);
            if (empty($items)) {

                return $this->respondWithError(404, 'No records found');
            }



            $security = new \Phalcon\Security\Random();
            $newAccessToken = $security->uuid();


            $found = false;

            foreach ($items as $index => $item) {


                $payload = json_decode($item, true);

                if (!is_array($payload)) {

                    continue;
                }

                if (($payload['id'] ?? null) !== $id) {

                    continue;
                }


                $payload['accessToken'] = $newAccessToken;

                $payload['embedScript'] =
                    '<script src="https://' . $fqdn .
                    '/iframes/chat-embed.php?accesstoken=' . urlencode($newAccessToken) .
                    '&color=%23FFFFFF&img=https%3A%2F%2Fwww.ringq.com%2Fwp-content%2Fuploads%2F2024%2F01%2F71580-2.png' .
                    '&fqdn=' . urlencode($fqdn) .
                    '&tokenized=%242y%2410%24Q0c5WUlCQnFZYU0zRTlEMeRnveV00SzK5qaPEf5XsE.MVKncD4m9C"></script>';


                $this->redis->lSet($redisKey, $index, json_encode($payload));


                $found = true;
                break;
            }

            if (!$found) {

                return $this->respondWithError(404, 'ID not found');
            }

            echo json_encode([
                'result' => 'success',
                'message' => 'Successfully updated chat settings',
                'id' => $id,
                'embedScript' => $embedScript,
                'accessToken' => $newAccessToken
            ]);


            return;
        }

        /* =========================
           TYPE 7 — READ GLOBAL CHAT SETTINGS
           Same payload chat.js sends as 'queue_settings' (login / type 16 echo)
        ========================== */
        if ($type === 7) {

            if (empty($fqdn)) {
                return $this->respondWithError(401, 'Not logged in');
            }

            try {
                $emailSettings = $this->redis->hGetAll("{$fqdn}-emailsettings") ?: [];
                $aiSettings = $this->redis->hGetAll("{$fqdn}-AISettings") ?: [];
                $chatSettings = $this->redis->hGetAll("{$fqdn}-chatsettings") ?: [];
                $questionsRaw = $this->redis->sMembers("{$fqdn}-clientQuestions") ?: [];
            } catch (\Exception $e) {
                return $this->respondWithError(500, 'Unable to load chat settings');
            }

            // Option buttons are stored as "emoji label" strings; split on the first space
            $buttons = [];
            foreach ($questionsRaw as $question) {
                $trimmed = trim((string) $question);
                $pos = strpos($trimmed, ' ');
                $buttons[] = $pos === false
                    ? ['emoji' => '', 'label' => $trimmed]
                    : ['emoji' => substr($trimmed, 0, $pos), 'label' => substr($trimmed, $pos + 1)];
            }

            $settings = array_merge($emailSettings, $aiSettings, $chatSettings);
            $settings['buttons'] = $buttons;
            $settings['contactEmail'] = ($chatSettings['contactEmail'] ?? '') !== ''
                ? $chatSettings['contactEmail']
                : ($chatSettings['pluginEmail'] ?? '');

            echo json_encode([
                'result' => 'success',
                'settings' => $settings
            ]);
            return;
        }

        /* =========================
           TYPE 8 — SAVE GLOBAL CHAT SETTINGS
           Writes the same keys/shape as chat.js 'communicate' type 16
        ========================== */
        if ($type === 8) {

            if (empty($fqdn)) {
                return $this->respondWithError(401, 'Not logged in');
            }

            $str = fn($key) => isset($data[$key]) && is_scalar($data[$key])
                ? trim($this->filter->sanitize((string) $data[$key], 'striptags'))
                : '';
            $bool = fn($key) => filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';

            $email = $str('email');
            $contactEmail = $str('contactEmail');
            foreach (['email' => $email, 'contactEmail' => $contactEmail] as $field => $value) {
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $this->respondWithError(400, "Invalid $field");
                }
            }

            $aiProvider = $str('aiProvider');
            if ($aiProvider !== '' && $aiProvider !== 'gemini' && $aiProvider !== 'chatgpt') {
                return $this->respondWithError(400, 'Invalid aiProvider value only allowed is gemini or chatgpt');
            }

            $position = strtolower($str('position'));
            if ($position !== '' && $position !== 'left' && $position !== 'right') {
                return $this->respondWithError(400, 'Invalid position value only allowed is left or right');
            }

            $aiFields = [
                'selectedAI' => $aiProvider,
                'aiEnable' => $bool('aiEnable'),
                'brain' => $str('brain'),
            ];
            // Only write a token when one was sent, so a blank field keeps the saved key
            if ($str('geminiToken') !== '') {
                $aiFields['gemini'] = $str('geminiToken');
            }
            if ($str('chatgptToken') !== '') {
                $aiFields['chatgpt'] = $str('chatgptToken');
            }

            $chatFields = [
                'pluginEmail' => $contactEmail,
                'customEnabled' => $bool('customEnabled'),
                'fabIcon' => $str('fabIcon'),
                'fabIconName' => $str('fabIconName'),
                'fabIconSize' => $str('fabIconSize'),
                'fabSize' => $str('fabSize'),
                'fabColor' => $str('fabColor'),
                'headerTitle' => $str('headerTitle'),
                'headerSubtitle' => $str('headerSubtitle'),
                'headerColor' => $str('headerColor'),
                'openingGreeting' => $str('openingGreeting'),
                'menuPrompt' => $str('menuPrompt'),
                'aiEnable' => $bool('aiEnable'),
                'autoOpen' => $bool('autoOpen'),
                'showOnlineIndicator' => $bool('showOnlineIndicator'),
                'showFooter' => $bool('showFooter'),
                'position' => $position,
                'showSettings' => $bool('showSettings'),
                'showPhone' => $bool('showPhone'),
                'phone' => $str('phone'),
                'showEmail' => $bool('showEmail'),
                'contactEmail' => $contactEmail,
                'showWhatsapp' => $bool('showWhatsapp'),
                'whatsapp' => $str('whatsapp'),
                'showSms' => $bool('showSms'),
                'sms' => $str('sms'),
            ];

            // Option buttons [{emoji, label}] -> set of "emoji label" strings
            $questions = [];
            foreach ((is_array($data['buttons'] ?? null) ? $data['buttons'] : []) as $button) {
                if (!is_array($button)) {
                    continue;
                }
                $parts = [];
                foreach (['emoji', 'label'] as $field) {
                    $part = isset($button[$field]) && is_scalar($button[$field])
                        ? trim($this->filter->sanitize((string) $button[$field], 'striptags'))
                        : '';
                    if ($part !== '') {
                        $parts[] = $part;
                    }
                }
                if ($parts) {
                    $questions[] = implode(' ', $parts);
                }
            }

            try {
                $this->redis->hMSet("{$fqdn}-emailsettings", [
                    'email' => $email,
                    'enable' => $bool('enable'),
                ]);
                $this->redis->hMSet("{$fqdn}-AISettings", $aiFields);
                $this->redis->hMSet("{$fqdn}-chatsettings", $chatFields);

                $questionsKey = "{$fqdn}-clientQuestions";
                $this->redis->del($questionsKey);
                if ($questions) {
                    $this->redis->sAdd($questionsKey, ...$questions);
                }

                $this->redis->hMSet("{$fqdn}-wordpress-emailsettings", ['email' => $contactEmail]);
            } catch (\Exception $e) {
                return $this->respondWithError(500, 'Unable to save chat settings');
            }

            echo json_encode([
                'result' => 'success',
                'message' => 'Chat settings saved'
            ]);
            return;
        }

    }

    public function whatsappconfigAction()
    {
        $this->view->disable();
        $this->response->setHeader("Content-Type", "application/json");

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return $this->respondWithError(400, 'Invalid JSON');
        }

        $type = isset($data['type'])
            ? (int) $this->filter->sanitize($data['type'], 'int')
            : 0;

        if (!$type) {
            return $this->respondWithError(400, 'Invalid type');
        }

        $fqdn = isset($data['fqdn'])
            ? trim($this->filter->sanitize($data['fqdn'], 'string'))
            : '';

        $session = $this->session->get("auth");
        $fqdn = $session["subdomain"];

        if ($fqdn === '') {
            return $this->respondWithError(400, 'fqdn is required');
        }

        $redisKey = "whatsappconfig:fqdn:{$fqdn}";

        /* TYPE 1 — CREATE */
        if ($type === 1) {

            $requiredFields = ['verifyToken', 'accessToken', 'name', 'appId', 'number'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->respondWithError(400, "$field is required");
                }
            }

            $verifyToken = $this->filter->sanitize($data['verifyToken'], 'string');
            $accessToken = $this->filter->sanitize($data['accessToken'], 'string');
            $name = $this->filter->sanitize($data['name'], 'string');
            $appId = $this->filter->sanitize($data['appId'], 'string');
            $number = $this->filter->sanitize($data['number'], 'string');

            // ---------- DUPLICATION CHECK START ----------
            // Get all existing items from the Redis list
            $existingItems = $this->redis->lRange($redisKey, 0, -1);

            if (!empty($existingItems)) {
                foreach ($existingItems as $item) {
                    $decoded = json_decode($item, true);
                    if (isset($decoded['number']) && $decoded['number'] === $number) {
                        // Option A: Stop and return error
                        return $this->respondWithError(400, "The number $number is already added please choose a different.");


                    }
                }
            }
            // ---------- DUPLICATION CHECK END ----------

            $security = new \Phalcon\Security\Random();
            $id = $security->uuid();

            $payload = [
                'id' => $id,
                'verifyToken' => $verifyToken,
                'fqdn' => $fqdn,
                'number' => $number,
                'accessToken' => $accessToken,
                'name' => $name,
                'appId' => $appId,
                'created' => time()
            ];

            $this->redis->lPush($redisKey, json_encode($payload));

            echo json_encode([
                'result' => 'success',
                'message' => 'Successfully updated chat settings',
                'id' => $id
            ]);
            return;

            /* TYPE 2 — READ */
        } elseif ($type === 2) {

            $page = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
            $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));
            $start = ($page - 1) * $limit;

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $total = count($rowsRaw);

            $rowsPaginated = array_slice($rowsRaw, $start, $limit);
            $rows = array_map(fn($r) => json_decode($r, true), $rowsPaginated);

            echo json_encode([
                'result' => 'success',
                'data' => $rows,
                'pagination' => [
                    'totalRecords' => $total,
                    'totalPages' => ceil($total / $limit),
                    'currentPage' => $page,
                    'limit' => $limit
                ]
            ]);
            return;

            /* TYPE 3 — UPDATE */
        } elseif ($type === 3) {

            $itemId = isset($data['id'])
                ? (string) $this->filter->sanitize($data['id'], 'string')
                : 0;

            if (!$itemId) {
                return $this->respondWithError(400, 'Invalid ID');
            }

            $updatableFields = ['verifyToken', 'accessToken', 'name', 'appId', 'number'];

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $updated = false;

            foreach ($rowsRaw as $index => $row) {
                $decoded = json_decode($row, true);

                if ($decoded['id'] == $itemId) {
                    foreach ($updatableFields as $field) {
                        if (isset($data[$field])) {
                            $decoded[$field] = $this->filter->sanitize($data[$field], 'string');
                        }
                    }
                    $this->redis->lSet($redisKey, $index, json_encode($decoded));
                    $updated = true;
                    break;
                }
            }

            if (!$updated) {
                return $this->respondWithError(404, 'Record not found');
            }

            echo json_encode([
                'result' => 'success',
                'message' => 'Updated'
            ]);
            return;

            /* TYPE 4 — DELETE */
        } elseif ($type === 4) {

            $itemId = isset($data['id'])
                ? $this->filter->sanitize($data['id'], 'string')
                : 0;

            if (!$itemId) {
                return $this->respondWithError(400, 'Invalid ID');
            }



            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $deleted = false;

            $qCallQueues = VCallCenterQueues::find(
                [
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" =>
                        [
                            "domain_uuid" => $session["domain_uuid"],
                        ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]
            );

            foreach ($rowsRaw as $row) {
                $decoded = json_decode($row, true);
                if ($decoded['id'] == $itemId) {
                    foreach ($qCallQueues as $qCallQueue) {
                        $whatsappChats = $this->redis->lrange($qCallQueue->queue_extension . "@" . $session["subdomain"] . "-whatsapp-chat", 0, -1);
                        if ($whatsappChats) {
                            if (in_array((string) $decoded['appId'], $whatsappChats, true)) {
                                return $this->respondWithError(404, 'Sorry, please unassign this chat account first from its queue assignement');
                            }
                        }
                    }
                    $this->redis->lRem($redisKey, 1, $row);
                    $deleted = true;
                    break;
                }
            }

            if (!$deleted) {
                return $this->respondWithError(404, 'Record not found');
            }

            echo json_encode([
                'result' => 'success',
                'message' => 'Deleted'
            ]);
            return;

        } else {
            return $this->respondWithError(400, 'Invalid type');
        }
    }

    public function facebookconfigAction()
    {
        $this->view->disable();
        $this->response->setHeader("Content-Type", "application/json");

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->respondWithError(405, 'Method not allowed');
        }

        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return $this->respondWithError(400, 'Invalid JSON');
        }

        $type = isset($data['type'])
            ? (int) $this->filter->sanitize($data['type'], 'int')
            : 0;

        if (!$type) {
            return $this->respondWithError(400, 'Invalid type');
        }

        $fqdn = isset($data['fqdn'])
            ? trim($this->filter->sanitize($data['fqdn'], 'string'))
            : '';

        $session = $this->session->get("auth");
        $fqdn = $session["subdomain"];

        if ($fqdn === '') {
            return $this->respondWithError(400, 'fqdn is required');
        }

        $redisKey = "facebookconfig:fqdn:{$fqdn}";

        /* TYPE 1 — CREATE */
        if ($type === 1) {

            $requiredFields = ['verifyToken', 'accessToken', 'name', 'appId'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->respondWithError(400, "$field is required");
                }
            }

            $verifyToken = $this->filter->sanitize($data['verifyToken'], 'string');
            $accessToken = $this->filter->sanitize($data['accessToken'], 'string');
            $name = $this->filter->sanitize($data['name'], 'string');
            $appId = $this->filter->sanitize($data['appId'], 'string');


            $existingRecords = $this->redis->lRange($redisKey, 0, -1);

            if (!empty($existingRecords)) {
                foreach ($existingRecords as $record) {
                    $decoded = json_decode($record, true);

                    if (isset($decoded['appId']) && $decoded['appId'] === $appId) {

                        return $this->respondWithError(400, "App ID: $appId is already added please add a different one.");

                    }
                }
            }
            // ------------------------------------------------

            $security = new \Phalcon\Security\Random();
            $id = $security->uuid();

            $payload = [
                'id' => $id,
                'verifyToken' => $verifyToken,
                'fqdn' => $fqdn,
                'accessToken' => $accessToken,
                'name' => $name,
                'appId' => $appId,
                'created' => time()
            ];

            $this->redis->lPush($redisKey, json_encode($payload));

            echo json_encode([
                'result' => 'success',
                'message' => 'Successfully updated chat settings',
                'id' => $id
            ]);
            return;

            /* TYPE 2 — READ */
        } elseif ($type === 2) {

            $page = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
            $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));
            $start = ($page - 1) * $limit;

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $total = count($rowsRaw);

            $rowsPaginated = array_slice($rowsRaw, $start, $limit);
            $rows = array_map(fn($r) => json_decode($r, true), $rowsPaginated);

            echo json_encode([
                'result' => 'success',
                'data' => $rows,
                'pagination' => [
                    'totalRecords' => $total,
                    'totalPages' => ceil($total / $limit),
                    'currentPage' => $page,
                    'limit' => $limit
                ]
            ]);
            return;

            /* TYPE 3 — UPDATE */
        } elseif ($type === 3) {

            $itemId = isset($data['id'])
                ? (string) $this->filter->sanitize($data['id'], 'string')
                : 0;

            if (!$itemId) {
                return $this->respondWithError(400, 'Invalid ID');
            }

            $updatableFields = ['verifyToken', 'accessToken', 'name', 'appId'];

            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $updated = false;

            foreach ($rowsRaw as $index => $row) {
                $decoded = json_decode($row, true);

                if ($decoded['id'] == $itemId) {
                    foreach ($updatableFields as $field) {
                        if (isset($data[$field])) {
                            $decoded[$field] = $this->filter->sanitize($data[$field], 'string');
                        }
                    }
                    $this->redis->lSet($redisKey, $index, json_encode($decoded));
                    $updated = true;
                    break;
                }
            }

            if (!$updated) {
                return $this->respondWithError(404, 'Record not found');
            }

            echo json_encode([
                'result' => 'success',
                'message' => 'Updated'
            ]);
            return;

            /* TYPE 4 — DELETE */
        } elseif ($type === 4) {

            $itemId = isset($data['id'])
                ? $this->filter->sanitize($data['id'], 'string')
                : 0;

            if (!$itemId) {
                return $this->respondWithError(400, 'Invalid ID');
            }

            $qCallQueues = VCallCenterQueues::find(
                [
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" =>
                        [
                            "domain_uuid" => $session["domain_uuid"],
                        ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]
            );


            $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
            $deleted = false;

            foreach ($rowsRaw as $row) {
                $decoded = json_decode($row, true);
                if ($decoded['id'] == $itemId) {
                    foreach ($qCallQueues as $qCallQueue) {
                        $facebookChats = $this->redis->lrange($qCallQueue->queue_extension . "@" . $session["subdomain"] . "-facebook-chat", 0, -1);
                        if ($facebookChats) {
                            if (in_array($decoded["appId"], $facebookChats)) {
                                return $this->respondWithError(404, 'Sorry, please unassign this chat account first from its queue assignement');
                            }
                        }
                    }
                    $this->redis->lRem($redisKey, 1, $row);
                    $deleted = true;
                    break;
                }
            }

            if (!$deleted) {
                return $this->respondWithError(404, 'Record not found');
            }

            echo json_encode([
                'result' => 'success',
                'message' => 'Deleted'
            ]);
            return;

        } else {
            return $this->respondWithError(400, 'Invalid type');
        }
    }

    public function chatsAction($type = 1, $page = 1, $username = "", $count = 0, $messageposition = "")
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $data = $this->request->getJsonRawBody(true);
        $keyref = $data["key"] ?? "";
        // echo $keyref;
        // return;

        $session = $this->session->get("auth");

        // Phalcon doesn't URL-decode route params, and this value becomes part of the Redis key,
        // so "name%40host" read a key that doesn't exist (history always empty). rawurldecode
        // (not urldecode) keeps a literal "+" intact.
        $username = $this->filter->sanitize(rawurldecode((string) $username), "string");

        // Default number of messages to fetch per request
        $pageSize = 20;
        $elementsPerMessage = 5;

        if ($session && $this->request->isGet()) {

            // Logic: If you've sent 20 messages already, 20 * 5 = 100. 
            // We start fetching from index 100.
            $offset = intval($count) * $elementsPerMessage;
            $limit = $pageSize * $elementsPerMessage;
            $end = $offset + $limit - 1;

            // Pick correct key based on type
            switch ($type) {
                case 1:
                    $key = $session["subdomain"] . "-external-" . $username;
                    break;
                case 2:
                    $key = $session["subdomain"] . ":whatsapp:919894340047:" . $username;
                    break;
                case 3:
                    $key = $session["subdomain"] . ":facebook:919894340047:" . $username;
                    break;
                case 4:
                    $key = $session["subdomain"] . ":telnyx:" . $username;
                    break;
                case 5:
                    $key = $session["subdomain"] . ":twilio:" . $username;
                    break;
                case 6:
                    $key = $session["subdomain"] . ":telegram:" . $username;
                    break;
                case 7:
                    $key = $session["subdomain"] . ":line:" . $username;
                    break;
                default:
                    $key = null;
            }



            if ($key) {
                $totalItems = $this->redis->llen($key);

                echo "key: $key";








                if ($totalItems > 0) {
                    if ($messageposition != "") {
                        $content = $this->redis->lrange($key, $offset, $end);
                    } else {
                        $content = $this->redis->lrange($key, $offset, $end);
                    }

                    // echo "count: $count";
                    // echo "messageposition: $messageposition";
                    // echo "offset: $offset";
                    // echo "end: $end";
                    // print_r($content);
                    // return;

                    // Calculate if there's more to fetch
                    // If the current end index is less than the total items, we have more.
                    $hasMore = ($offset + count($content)) < $totalItems;

                    $data = [
                        "result" => "success",
                        "content" => $content,
                        "next" => $hasMore,
                        "hasMore" => $hasMore,
                        "key" => $key,
                        "fetched" => count($content) / $elementsPerMessage, // How many messages we just got
                        "total" => $totalItems,
                        "offset" => $offset,
                        "end" => $end,
                        "current_count" => $count // The count you sent us
                    ];
                    $this->response->setContent(json_encode($data));
                    return $this->response;
                }
            }

            // Fallback for no data or invalid key
            $this->response->setContent(json_encode([
                "result" => "success",
                "content" => [],
                "next" => false,
                "hasMore" => false,
                "total" => 0,
                "offset" => $offset ?? 0
            ]));
            return $this->response;
        }
    }

    private function chatSessionTenant(): string
    {
        $session = $this->session->get("auth");
        return is_array($session) ? strtolower(trim((string) ($session["subdomain"] ?? ""))) : "";
    }

    private function chatHostTenant(): string
    {
        return strtolower((string) parse_url("http://" . $this->request->getHttpHost(), PHP_URL_HOST));
    }

    private function chatFromThisServer(): bool
    {
        $remote = (string) $this->request->getServer("REMOTE_ADDR");
        if ($remote === "") {
            return false;
        }
        if ($remote === "127.0.0.1" || $remote === "::1" || $remote === (string) $this->request->getServer("SERVER_ADDR")) {
            return true;
        }
        if (function_exists("net_get_interfaces")) {
            foreach ((array) @net_get_interfaces() as $interface) {
                foreach ((array) ($interface["unicast"] ?? []) as $address) {
                    if (($address["address"] ?? "") === $remote) {
                        return true;
                    }
                }
            }
        }
        $host = $this->chatHostTenant();
        return $host !== "" && preg_match('/^[a-z0-9.-]+$/', $host) && gethostbyname($host) === $remote;
    }

    private function chatLogKeyAllowed(string $tenant, $key): bool
    {
        if (!is_string($key) || $tenant === "" || substr($key, -5) !== "-logs") {
            return false;
        }
        return strpos($key, $tenant . "-") === 0 || strpos($key, $tenant . ":") === 0;
    }

    private function chatMemberAllowed(string $tenant, string $member): bool
    {
        if ($tenant === "" || strpbrk($member, "\"\\{}") !== false) {
            return false;
        }
        return strpos($member, $tenant . "-") === 0 || strpos($member, $tenant . ":") === 0
            || substr($member, -strlen("-" . $tenant)) === "-" . $tenant;
    }

    private function chatRefuse(int $code, string $message)
    {
        $this->response->setStatusCode($code);
        $this->response->setHeader("Content-Type", "application/json");
        return $this->response->setJsonContent(["result" => "error", "message" => $message]);
    }

    private function respondWithError($httpCode, $message)
    {
        http_response_code(200);
        echo json_encode(['result' => 'error', 'message' => $message, 'content' => '']);
    }
}




