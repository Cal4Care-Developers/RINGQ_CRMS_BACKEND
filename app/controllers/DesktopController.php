<?php
declare(strict_types=1); 

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

class DesktopController extends ControllerBase { 
    public function loginAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            $this->response->setContent(json_encode(["result" => "error", "message" => "POST required"]));
            return $this->response;
        }

        $data = $this->request->getJsonRawBody();

        if (!isset($data->domain) || !isset($data->username) || !isset($data->password)) {
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing domain, username, or password"]));
            return $this->response;
        }

        $domain = trim($data->domain);
        $username = trim($data->username);
        $password = $data->password;

        // Step 1: Validate domain exists
        $vDomain = VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $domain],
        ]);

        if (!$vDomain) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Domain not found"]));
            return $this->response;
        }

        $domain_uuid = $vDomain->domain_uuid;

        // Step 2: Find user by email and domain
        $vUser = VUsers::findFirst([
            "conditions" => "user_email = :user_email: AND domain_uuid = :domain_uuid:",
            "bind" => [
                "user_email" => $username,
                "domain_uuid" => $domain_uuid,
            ],
        ]);

        if (!$vUser) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid credentials"]));
            return $this->response;
        }

        // Step 3: Validate password (a password_hash, or legacy plain text upgraded on success)
        $qUser = $vUser->qUsers;
        $stored = $qUser ? (string) $qUser->password : "";
        $hashed = $stored !== "" && !empty(password_get_info($stored)["algo"]);
        $matches = $stored !== "" && (string) $password !== ""
            && ($hashed ? password_verify((string) $password, $stored) : hash_equals($stored, (string) $password));
        if ($matches && !$hashed) {
            $qUser->password = password_hash((string) $password, PASSWORD_DEFAULT);
            $qUser->update();
        }
        if (!$qUser || !$matches) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid credentials"]));
            return $this->response;
        }

        // Step 4: Check user is enabled
        if ($vUser->user_enabled == false) {
            $this->response->setStatusCode(403, "Forbidden");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Account is disabled"]));
            return $this->response;
        }

        // Step 5: Join VUsers → VExtensionUsers → VExtensions
        $vExtensionUser = $vUser->vExtensionUsers;
        if (!$vExtensionUser) {
            $this->response->setStatusCode(404, "Not Found");
            $this->response->setContent(json_encode(["result" => "error", "message" => "No extension assigned to this user"]));
            return $this->response;
        }

        $vExtension = $vExtensionUser->vExtensions;
        if (!$vExtension) {
            $this->response->setStatusCode(404, "Not Found");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Extension not found"]));
            return $this->response;
        }

        // Step 6: Build session
        $vUser->user_status = "Available";
        $vUser->save();

        // Identity, not just routing. Anything downstream that has to *name*
        // this person reads it off the session, and a missing key does not
        // fail -- it silently substitutes an id. MeetApiController::
        // accessTokenAction is the case that found it: with no firstname /
        // lastname / username it fell back to $ownerKey, minted that uuid into
        // the LiveKit token as the participant's name, and everyone else in
        // the meeting read a uuid with its first two characters in the avatar.
        // No client can repair that -- the token carries no
        // canUpdateOwnMetadata, so setName() is refused.
        //
        // "image" is the bare filename, never a URL: MeetApiController and
        // AccountsController both build the /avr/ URL from it themselves.
        //
        // Deliberately no "extension_uuid". accessTokenAction resolves the
        // ownerKey as extension_uuid ?? user_uuid ?? extension and the webapp
        // session carries none, so adding one here would give the same user a
        // different ownerKey on desktop than in the browser: rooms/active
        // would list nothing and rejoining a meeting started in the other
        // client would 403 "Not the host of this meeting".
        $authPayload = [
            "username"    => $vUser->username,
            "firstname"   => $qUser->first_name,
            "lastname"    => $qUser->last_name,
            "email"       => $vUser->user_email,
            "image"       => $qUser->image,
            "extension"   => $vExtension->extension,
            "subdomain"   => $domain,
            "domain_uuid" => $domain_uuid,
            "user_uuid"   => $vUser->user_uuid,
            "access"      => "registered",
            // [one-mobile] the desktop app is never the extension's one mobile app
            // (ControllerBase::mobileReplaced): it sends Dart's default user agent, like a phone
            "client"      => "desktop",
        ];

        $this->session->set("auth", $authPayload);

        // Step 7: Build image URL
        $imageUrl = "";
        if (!empty($qUser->image)) {
            $imageUrl = "https://{$domain}:8443/avr/{$qUser->image}";
        }

        // Step 8: Return SIP credentials + user profile
        $serverVersion = $this->redis->get("serverVersion");
        if (!$serverVersion) {
            $serverVersion = "8.7.62.65032726";
        }

        $response = json_encode([
            "result" => "success",
            "message" => "Login successful",
            "session_id" => $this->session->getId(),
            "sip_credentials" => [
                "extension"    => $vExtension->extension,
                "sip_password" => $vExtension->password,
                "sip_username" => $vUser->username,
                "domain"       => $domain,
            ],
            "user" => [
                "user_email"     => $vUser->user_email,
                "first_name"     => isset($qUser->first_name) ? $qUser->first_name : "",
                "last_name"      => isset($qUser->last_name) ? $qUser->last_name : "",
                "image"          => $imageUrl,
                "domain_uuid"    => $domain_uuid,
                "server_version" => $serverVersion,
            ],
        ]);

        $this->response->setContent($response);
        return $this->response;
    }

    /**
     * TEST ENDPOINT: Destroys the current session to simulate session expiry.
     * Call via: curl -b "RINGQ=<session_id>" https://<host>:8443/desktop/killsession
     */
    public function killsessionAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "No active session"]));
            return $this->response;
        }

        $extension = $session["extension"] ?? "unknown";
        $this->session->destroy();

        $this->response->setContent(json_encode([
            "result" => "success",
            "message" => "Session destroyed for extension {$extension}",
        ]));
        return $this->response;
    }

    /**
     * Issues a short-lived one-time token for desktop auto-login.
     * Called by the webapp while the user has an active session.
     *
     * POST /desktop/autologintoken
     * Cookie: RINGQ=<session>
     * Response: { "result": "success", "token": "<hex64>", "expires_in": 60 }
     */
    public function autologintokenAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        // Handle CORS preflight — nginx sets the Allow-Origin headers globally,
        // PHP only needs to return 200 so the browser accepts the preflight.
        if ($this->request->isOptions()) {
            $this->response->setStatusCode(200, 'OK');
            return $this->response;
        }

        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            $this->response->setContent(json_encode(["result" => "error", "message" => "POST required"]));
            return $this->response;
        }

        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Not authenticated"]));
            return $this->response;
        }

        $token = bin2hex(random_bytes(32));
        $this->redis->setex("desktop_autologin:{$token}", 60, json_encode($session));

        $this->response->setContent(json_encode([
            "result"     => "success",
            "token"      => $token,
            "expires_in" => 60,
        ]));
        return $this->response;
    }

    /**
     * Exchanges a one-time token (issued by autologintokenAction) for a full
     * desktop login session. Called by the Flutter desktop app with no prior session.
     *
     * POST /desktop/autologin
     * Body: { "token": "<hex64>" }
     * Response: same shape as /desktop/login success
     */
    public function autologinAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            $this->response->setContent(json_encode(["result" => "error", "message" => "POST required"]));
            return $this->response;
        }

        $data = $this->request->getJsonRawBody();
        $token = isset($data->token) ? trim((string) $data->token) : '';

        if ($token === '') {
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing token"]));
            return $this->response;
        }

        $stored = $this->redis->get("desktop_autologin:{$token}");
        if (!$stored) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid or expired token"]));
            return $this->response;
        }

        // Consume immediately — single use. Only the caller whose DEL removed
        // the key may proceed, so two concurrent exchanges cannot both win.
        if (intval($this->redis->del("desktop_autologin:{$token}")) !== 1) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid or expired token"]));
            return $this->response;
        }

        $authPayload = json_decode($stored, true);

        // Rehydrate user from DB to get fresh SIP credentials and profile
        $vUser = VUsers::findFirst([
            "conditions" => "user_uuid = :user_uuid:",
            "bind"       => ["user_uuid" => $authPayload["user_uuid"]],
        ]);

        if (!$vUser || $vUser->user_enabled == false) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Account is disabled or not found"]));
            return $this->response;
        }

        $vExtensionUser = $vUser->vExtensionUsers;
        $vExtension = $vExtensionUser ? $vExtensionUser->vExtensions : null;
        if (!$vExtension) {
            $this->response->setStatusCode(404, "Not Found");
            $this->response->setContent(json_encode(["result" => "error", "message" => "No extension assigned to this user"]));
            return $this->response;
        }

        $qUser = $vUser->qUsers;
        $domain = $authPayload["subdomain"];

        // Establish session for the desktop app
        $vUser->user_status = "Available";
        $vUser->save();
        $this->session->set("auth", $authPayload);

        $imageUrl = (!empty($qUser->image)) ? "https://{$domain}:8443/avr/{$qUser->image}" : "";

        $serverVersion = $this->redis->get("serverVersion") ?: "8.7.62.65032726";

        $this->response->setContent(json_encode([
            "result"          => "success",
            "message"         => "Login successful",
            "session_id"      => $this->session->getId(),
            "sip_credentials" => [
                "extension"    => $vExtension->extension,
                "sip_password" => $vExtension->password,
                "sip_username" => $vUser->username,
                "domain"       => $domain,
            ],
            "user" => [
                "user_email"     => $vUser->user_email,
                "first_name"     => isset($qUser->first_name) ? $qUser->first_name : "",
                "last_name"      => isset($qUser->last_name)  ? $qUser->last_name  : "",
                "image"          => $imageUrl,
                "domain_uuid"    => $authPayload["domain_uuid"],
                "server_version" => $serverVersion,
            ],
        ]));
        return $this->response;
    }

    public function versionAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            $this->response->setContent(json_encode(["result" => "error", "message" => "GET required"]));
            return $this->response;
        }

        // Static version config — update these values with each release.
        // Set critical => true to force users to update before continuing.
        $version  = "8.1.6";
        $critical = false;
        $releaseNotes = "Webapp Auto-Login & Account Switch.";
        $downloadMacos   = "https://raw.githubusercontent.com/RingQ-Devs/ringq_desktop_installer/main/releases/download/macos/v{$version}/RingQ_{$version}.dmg";
        $downloadWindows = "https://raw.githubusercontent.com/RingQ-Devs/ringq_desktop_installer/main/releases/download/windows/v{$version}/RingQ_{$version}.exe";

        $this->response->setContent(json_encode([
            "version"       => $version,
            "critical"      => $critical,
            "release_notes" => $releaseNotes,
            "download" => [
                "macos"   => $downloadMacos,
                "windows" => $downloadWindows,
            ],
        ]));

        return $this->response;
    }

    public function contactsAction($page = 1) {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        
        $session = $this->session->get("auth");
        if (!$session || !$this->request->isGet()) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $domain_uuid = $session["domain_uuid"];
        $type = $this->request->getQuery("type", "string", "team");
        $search = $this->request->getQuery("search", "string", "");
        $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
        
        $content = [];
        $count = 0;

        if ($type === "company") {
            $conditions = "domain_uuid = :domain_uuid:";
            $bind = ["domain_uuid" => $domain_uuid];

            if (!empty($search)) {
                $conditions .= " AND (first_name LIKE :search: OR last_name LIKE :search: OR phone LIKE :search: OR company_name LIKE :search:)";
                $bind["search"] = "%" . $search . "%";
            }

            $qContacts = QContacts::find([
                "conditions" => $conditions,
                "bind" => $bind,
                "limit" => 20,
                "offset" => $offset,
                "order" => "id ASC"
            ]);

            $count = QContacts::count([
                "conditions" => $conditions,
                "bind" => $bind
            ]);

            $contactIds = [];
            foreach ($qContacts as $contact) {
                $contactIds[] = $contact->id;
            }

            $notesByContact = [];
            if (!empty($contactIds)) {
                $qWrapupnotesLists = QWrapupnotesList::find([
                    "conditions" => "domain_uuid = :domain_uuid: AND contact_id IN ({ids:array})",
                    "bind" => [
                        "domain_uuid" => $domain_uuid,
                        "ids" => $contactIds
                    ],
                    "order" => "insert_date DESC"
                ]);

                foreach ($qWrapupnotesLists as $note) {
                    if (!isset($notesByContact[$note->contact_id])) {
                        $notesByContact[$note->contact_id] = [];
                    }
                    $notesByContact[$note->contact_id][] = $note;
                }
            }

            foreach ($qContacts as $qContact) {
                $content[] = $this->_formatContact($qContact, isset($notesByContact[$qContact->id]) ? $notesByContact[$qContact->id] : null);
            }
        } else {
            // Default: Team (Extensions)
            $subdomain = $session["subdomain"];
            $conditions = "domain_uuid = :domain_uuid: 
                AND LENGTH(extension) = 4 
                AND extension NOT IN ('{$session['extension']}', '9997', '9998', '9999', '100000')";
            $bind = ["domain_uuid" => $domain_uuid];

            if (!empty($search)) {
                $conditions .= " AND (extension LIKE :search: OR effective_caller_id_name LIKE :search:)";
                $bind["search"] = "%" . $search . "%";
            }

            $vExtensions = VExtensions::find([
                "conditions" => $conditions,
                "bind" => $bind,
                "limit" => 20,
                "offset" => $offset,
                "order" => "extension ASC"
            ]);

            $count = VExtensions::count([
                "conditions" => $conditions,
                "bind" => $bind
            ]);

            // Query FreeSWITCH ESL for real-time active call extensions
            $activeCallExtensions = [];
            try {
                $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 2);
                if ($fp) {
                    stream_set_timeout($fp, 3);
                    fread($fp, 4096); // Read auth/request
                    fwrite($fp, "auth ClueCon\n\n");
                    fread($fp, 4096); // Read auth reply

                    fwrite($fp, "api show calls as json\n\n");

                    // Read response headers to get Content-Length
                    $headers = '';
                    while (($line = fgets($fp)) !== false) {
                        $headers .= $line;
                        if (trim($line) === '') break;
                    }
                    $contentLength = 0;
                    if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
                        $contentLength = (int)$m[1];
                    }

                    // Read body
                    $body = '';
                    while ($contentLength > 0 && strlen($body) < $contentLength) {
                        $chunk = fread($fp, $contentLength - strlen($body));
                        if ($chunk === false || $chunk === '') break;
                        $body .= $chunk;
                    }
                    fclose($fp);

                    $callData = json_decode(trim($body), true);
                    if (isset($callData['rows']) && is_array($callData['rows'])) {
                        foreach ($callData['rows'] as $row) {
                            // Only use presence_id and b_presence_id - these reflect actual connected SIP endpoints.
                            // Other fields like dest/b_dest retain stale data after attended transfers.
                            foreach (['presence_id', 'b_presence_id'] as $field) {
                                if (!empty($row[$field]) && strpos($row[$field], '@') !== false) {
                                    list($ext,) = explode('@', $row[$field], 2);
                                    $activeCallExtensions[$ext] = true;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fail - extensions will show their registration-based status
            }

            foreach ($vExtensions as $vExtension) {
                $firstname = isset($vExtension->vExtensionUsers->qUsers->first_name) ? $vExtension->vExtensionUsers->qUsers->first_name : "";
                $lastname = isset($vExtension->vExtensionUsers->qUsers->last_name) ? $vExtension->vExtensionUsers->qUsers->last_name : "";
                $email = isset($vExtension->vExtensionUsers->vUsers->user_email) ? $vExtension->vExtensionUsers->vUsers->user_email : "";
                $username = isset($vExtension->vExtensionUsers->vUsers->username) ? $vExtension->vExtensionUsers->vUsers->username : "";
                
                $profileStatus = $this->redis->get($vExtension->extension . "+" . $subdomain);
                $status = $profileStatus ? $profileStatus : 0;
                
                $status2 = isset($vExtension->vExtensionUsers->vUsers->user_status) ? $vExtension->vExtensionUsers->vUsers->user_status : 'Offline';
                if ($username) {
                    $registeredUsername = Registrations::findFirst([
                        "conditions" => "reg_user = :username:",
                        "bind" => ["username" => $username]
                    ]);
                    if ($registeredUsername) {
                        $status2 = 'Available';
                    }
                }

                // Check if extension is currently on a call (via real-time FreeSWITCH active calls)
                if (isset($activeCallExtensions[$vExtension->number_alias]) ||
                    isset($activeCallExtensions[$vExtension->extension])) {
                    $status2 = 'On Call';
                }

                $image = isset($vExtension->vExtensionUsers->qUsers->image) ? $vExtension->vExtensionUsers->qUsers->image : "";
                $imageUrl = "";
                if ($image) {
                    $imageUrl = "https://{$session['subdomain']}:8443/avr/{$image}";
                }
                
                $content[] = [
                    "id" => $vExtension->extension,
                    "extension" => $vExtension->extension,
                    "extension2" => $vExtension->number_alias,
                    "firstname" => ucfirst((string)$firstname),
                    "lastname" => ucfirst((string)$lastname),
                    "email" => $email,
                    "phone" => $vExtension->extension,
                    "status" => $status,
                    "status2" => $status2,
                    "image" => $imageUrl,
                ];
            }
        }

        $this->response->setContent(json_encode([
            "result" => "success",
            "content" => $content,
            "count" => $count,
        ]));
        
        return $this->response;
    }

    private function _formatContact($qContact, $notes) {
        return [
            "id" => $qContact->id,
            "companyname" => isset($qContact->company_name) ? ucfirst($qContact->company_name) : null,
            "firstname" => ucfirst($qContact->first_name),
            "lastname" => ucfirst($qContact->last_name),
            "address_1" => isset($qContact->address_1) ? $qContact->address_1 : null,
            "address_2" => isset($qContact->address_2) ? $qContact->address_2 : null,
            "email" => isset($qContact->email) ? $qContact->email : null,
            "email2" => isset($qContact->email2) ? $qContact->email2 : null,
            "phone" => $qContact->phone,
            "phone2" => isset($qContact->phone2) ? $qContact->phone2 : null,
            "office_phone" => isset($qContact->office_phone) ? $qContact->office_phone : null,
            "whatsapp_phone" => isset($qContact->whatsapp_phone) ? $qContact->whatsapp_phone : null,
            "wrapup_notes" => $notes,
        ];
    }

    public function calllogsAction($type = 1, $page = 1, $extension = 0, $callNature = 0, $callType = 0, $startStamp = 0, $endStamp = 0) {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }
        if (!$this->request->isGet()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            $this->response->setContent(json_encode(["result" => "error", "message" => "GET required"]));
            return $this->response;
        }
        $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
        $vCallCenterAgents = VCallCenterAgents::find(
            [
                "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                "bind" =>
                    [
                        "domain_uuid" => $session["domain_uuid"],
                        "user_uuid" => $session["user_uuid"],
                    ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]
        );
        $vCallCenterAgentArray = [];
        foreach ($vCallCenterAgents as $vCallCenterAgent) {
            array_push($vCallCenterAgentArray, $vCallCenterAgent->call_center_agent_uuid);
        }
        $vCallCenterAgentArray = implode(",", $vCallCenterAgentArray);
        $vCallCenterAgentArray = is_array($vCallCenterAgentArray) ? $vCallCenterAgentArray : (strpos((string) $vCallCenterAgentArray, ',') !== false ? explode(",", (string) $vCallCenterAgentArray) : [(string) $vCallCenterAgentArray]);
        $timestamp = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-deletion");
        if (!isset($timestamp)) {
            $timestamp = 0;
        }
        // CALL LOGS // AGENT, CALL TYPE (MISSED/ANSWERED), CALL NATURE (INBOUND/OUTBOUND), DATE
        if ($type == 1) {
            $vXmlCdrs = QRingqCdr::find(
                [
                    // "conditions" => "(caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent IN (:cc_agent:) OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND (caller_id_number != :extension: OR caller_destination != :extension: OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND start_epoch > :start_epoch:",
                    "conditions" => "(caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent IN ({cc_agent:array}) OR source_number = :extension:) AND caller_id_number != caller_destination AND (caller_id_number != :extension: OR caller_destination != :extension: OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND start_epoch > :start_epoch:",
                    "columns" => "direction, call_center_queue_uuid, last_arg, start_stamp, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name",
                    "bind" =>
                        [
                            "extension" => $session["extension"],
                            "domain_uuid" => $session["domain_uuid"],
                            "cc_agent" => $vCallCenterAgentArray,
                            "start_epoch" => $timestamp
                        ],
                    "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                    "limit" => 20,
                    "offset"=> $offset,
                    'order' => 'insert_date DESC'
                ]
            );
            $count = QRingqCdr::count(
                [
                    "conditions" => "(caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent IN ({cc_agent:array}) OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND (caller_id_number != :extension: OR caller_destination != :extension: OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND start_epoch > :start_epoch:",
                    "bind" =>
                        [
                            "extension" => $session["extension"],
                            "domain_uuid" => $session["domain_uuid"],
                            "cc_agent" => $vCallCenterAgentArray,
                            "start_epoch" => $timestamp
                        ],
                    "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                ]
            );
            $count2 = QRingqCdr::count(
                [
                    "conditions" => "caller_destination = :extension: AND caller_id_number != caller_destination AND source_number is not NULL AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch:",
                    "bind" =>
                        [
                            "extension" => $session["extension"],
                            "domain_uuid" => $session["domain_uuid"],
                            "start_epoch" => $timestamp
                        ],
                    "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                ]
            );
            $missed = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-missed");
            $this->redis->set($session["extension"] ."@". $session["subdomain"] ."-missed", $count2);
            
            // Fetch all extensions to resolve names for non-queue direct calls
            $vExtensions = VExtensions::find([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $session["domain_uuid"]]
            ]);
            $extensionNames = [];
            foreach ($vExtensions as $ext) {
                $extensionNames[$ext->extension] = $ext->effective_caller_id_name ?? $ext->directory_first_name;
            }

            $vXmlCdrsArray = $vXmlCdrs->toArray();
            $finalCdrs = [];
            foreach ($vXmlCdrsArray as $key => $vXmlCdr) {
                // Use a more unique key to avoid over-filtering direct calls
                $uniqueKey = ($vXmlCdr['call_center_queue_uuid'] ?? 'direct') . '-' . $vXmlCdr['start_epoch'] . '-' . $vXmlCdr['caller_id_number'] . '-' . $vXmlCdr['caller_destination'];
                if (!isset($uniqueCdrs[$uniqueKey])) {
                    $uniqueCdrs[$uniqueKey] = true;
                    
                    // Determine remote party
                    $isOutbound = ($vXmlCdr['caller_id_number'] == $session['extension']);
                    $vXmlCdr['remote_number'] = $isOutbound ? $vXmlCdr['caller_destination'] : $vXmlCdr['caller_id_number'];
                    
                    // Resolve remote name for internal extensions
                    if ($isOutbound) {
                        $vXmlCdr['remote_name'] = $extensionNames[$vXmlCdr['caller_destination']] ?? $vXmlCdr['caller_destination'];
                    } else {
                        $remoteName = $vXmlCdr['caller_id_name'] ?: ($extensionNames[$vXmlCdr['caller_id_number']] ?? $vXmlCdr['caller_id_number']);
                        
                        // DID/Extension routing detection
                        // If destination_number (original DID) or caller_destination (final extension) 
                        // differs from the caller number, it might be a DID call.
                        if (isset($vXmlCdr['destination_number']) && $vXmlCdr['destination_number'] != $vXmlCdr['caller_id_number']) {
                            if ($vXmlCdr['caller_destination'] == $session['extension']) {
                                $vXmlCdr['remote_name'] = $remoteName;
                            } else {
                                $vXmlCdr['remote_name'] = $vXmlCdr['caller_destination'] . " " . $remoteName;
                            }
                        } else {
                            $vXmlCdr['remote_name'] = $remoteName;
                        }
                    }

                    if (isset($vXmlCdr['call_center_queue_uuid']) && $vXmlCdr['call_center_queue_uuid']) {
                        $vXmlCdr['is_queue'] = 'true';
                    } else {
                        $vXmlCdr['is_queue'] = 'false';
                    }
                    $finalCdrs[] = $vXmlCdr;
                }
            }
            $content = [
                "callLogs" => $finalCdrs,
                "count" => $count,
                "count2" => isset($missed) ? $missed : $count2,
                "message" => $vCallCenterAgentArray
            ];
            $response = json_encode(
                [
                    "result" => "success",
                    "content" => $content,
                ]
            );
            $this->response->setContent($response);
            return $this->response;
        }
        if ($type == 2) {
            $firstAgent = count($vCallCenterAgentArray) > 0 ? $vCallCenterAgentArray[0] : '';
            $vXmlCdrs = QRingqCdr::find(
                [
                    "conditions" => "(caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch:",
                    "columns" => "direction, start_stamp, answer_stamp, last_arg, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name",
                    "bind" =>
                        [
                            "extension" => $session["extension"],
                            "domain_uuid" => $session["domain_uuid"],
                            "cc_agent" => $firstAgent,
                            "start_epoch" => $timestamp
                        ],
                    "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                    "limit" => 20,
                    "offset"=> $offset,
                    'order' => 'insert_date DESC'
                ]
            );
            $count = QRingqCdr::count(
                [
                    "conditions" => "(caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch:",
                    "bind" =>
                        [
                            "extension" => $session["extension"],
                            "domain_uuid" => $session["domain_uuid"],
                            "cc_agent" => $firstAgent,
                            "start_epoch" => $timestamp
                        ],
                    "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                ]
            );

            // Fetch all extensions to resolve names
            $vExtensions = VExtensions::find([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $session["domain_uuid"]]
            ]);
            $extensionNames = [];
            foreach ($vExtensions as $ext) {
                $extensionNames[$ext->extension] = $ext->effective_caller_id_name ?? $ext->directory_first_name;
            }

            $vXmlCdrsArray = $vXmlCdrs->toArray();
            $finalCdrs = [];
            $uniqueCdrs = [];
            foreach ($vXmlCdrsArray as $key => $vXmlCdr) {
                $uniqueKey = ($vXmlCdr['call_center_queue_uuid'] ?? 'direct') . '-' . $vXmlCdr['start_epoch'] . '-' . $vXmlCdr['caller_id_number'] . '-' . $vXmlCdr['caller_destination'];
                if (!isset($uniqueCdrs[$uniqueKey])) {
                    $uniqueCdrs[$uniqueKey] = true;
                    
                    $isOutbound = ($vXmlCdr['caller_id_number'] == $session['extension']);
                    $vXmlCdr['remote_number'] = $isOutbound ? $vXmlCdr['caller_destination'] : $vXmlCdr['caller_id_number'];
                    
                    if ($isOutbound) {
                        $vXmlCdr['remote_name'] = $extensionNames[$vXmlCdr['caller_destination']] ?? $vXmlCdr['caller_destination'];
                    } else {
                        $remoteName = $vXmlCdr['caller_id_name'] ?: ($extensionNames[$vXmlCdr['caller_id_number']] ?? $vXmlCdr['caller_id_number']);
                        if (isset($vXmlCdr['destination_number']) && $vXmlCdr['destination_number'] != $vXmlCdr['caller_id_number']) {
                            if ($vXmlCdr['caller_destination'] == $session['extension']) {
                                $vXmlCdr['remote_name'] = $remoteName;
                            } else {
                                $vXmlCdr['remote_name'] = $vXmlCdr['caller_destination'] . " " . $remoteName;
                            }
                        } else {
                            $vXmlCdr['remote_name'] = $remoteName;
                        }
                    }
                    $vXmlCdr['is_queue'] = 'false';
                    $finalCdrs[] = $vXmlCdr;
                }
            }
            $content = ["callLogs" => $finalCdrs, "count" => $count, "count2" => $count];
            $this->response->setContent(json_encode(["result" => "success", "content" => $content]));
            return $this->response;
        }
    }

    public function playvoicemailAction($uuid = '') {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }
        if (empty($uuid)) {
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing uuid"]));
            return $this->response;
        }

        // Find the voicemail message
        $vVoicemailMessage = VVoicemailMessages::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND voicemail_message_uuid = :uuid:",
            "bind" => [
                "domain_uuid" => $session["domain_uuid"],
                "uuid" => $uuid,
            ],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if (!$vVoicemailMessage) {
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setStatusCode(404, "Not Found");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Voicemail not found"]));
            return $this->response;
        }
        if ((string) $vVoicemailMessage->vVoicemails->voicemail_id !== (string) $session["extension"]) {
            $this->response->setHeader("Content-Type", "application/json");
            $this->response->setStatusCode(404, "Not Found");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Voicemail not found"]));
            return $this->response;
        }

        // Mark as read on first play
        if (!$vVoicemailMessage->read_epoch) {
            $vVoicemailMessage->read_epoch = time();
            $vVoicemailMessage->update();
        }

        $extension = $vVoicemailMessage->vVoicemails->voicemail_id;
        $base = "/var/lib/freeswitch/storage/voicemail/default/{$session["subdomain"]}/{$extension}/msg_{$uuid}";

        foreach (['.wav', '.mp3'] as $ext) {
            if (file_exists($base . $ext)) {
                $mime = $ext === '.wav' ? 'audio/wav' : 'audio/mpeg';
                $this->response->setHeader("Content-Type", $mime);
                $this->response->setFileToSend($base . $ext, "voicemail{$ext}")->send();
                return;
            }
        }

        // Fallback: try legacy filename pattern
        $formattedDate = date("m-d-y-H:i", intval($vVoicemailMessage->created_epoch));
        $base2 = "/var/lib/freeswitch/storage/voicemail/default/{$session["subdomain"]}/{$extension}/{$vVoicemailMessage->caller_id_number}-{$extension}-{$formattedDate}";
        foreach (['.wav', '.mp3'] as $ext) {
            if (file_exists($base2 . $ext)) {
                $mime = $ext === '.wav' ? 'audio/wav' : 'audio/mpeg';
                $this->response->setHeader("Content-Type", $mime);
                $this->response->setFileToSend($base2 . $ext, "voicemail{$ext}")->send();
                return;
            }
        }

        // Fallback: serve from message_base64 stored in DB
        if (!empty($vVoicemailMessage->message_base64)) {
            $bytes = base64_decode($vVoicemailMessage->message_base64);
            // Detect WAV by RIFF header, otherwise assume MP3
            $mime = (substr($bytes, 0, 4) === 'RIFF') ? 'audio/wav' : 'audio/mpeg';
            $this->response->setHeader("Content-Type", $mime);
            $this->response->setHeader("Content-Length", strlen($bytes));
            $this->response->setContent($bytes);
            return $this->response;
        }

        $this->response->setHeader("Content-Type", "application/json");
        $this->response->setStatusCode(404, "Not Found");
        $this->response->setContent(json_encode(["result" => "error", "message" => "Audio file not found"]));
        return $this->response;
    }

    public function clearlogsAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }
        $this->redis->set($session["extension"] . "@" . $session["subdomain"] . "-deletion", time());
        $this->response->setContent(json_encode(["result" => "success", "message" => "Call logs cleared"]));
        return $this->response;
    }

    public function voicemailsAction($page = 1, $type = 1) {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }
        $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
        if ($type == 2) {
                $vVoicemail = VVoicemails::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND voicemail_id = :voicemail_id:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "voicemail_id" => $session["extension"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                    ]
                );
                if (!$vVoicemail) {
                    $response = json_encode(
                        [
                            "result" => "error",
                            "message" => "Please check voicemail extension entry (Code AA1000)",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vVoicemailMessages = VVoicemailMessages::find(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND voicemail_uuid = :voicemail_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "voicemail_uuid" => $vVoicemail->voicemail_uuid,
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        "limit" => 20,
                        "offset"=> $offset,
                        'order' => 'created_epoch DESC'
                    ]
                );
                $count = VVoicemailMessages::count(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND voicemail_uuid = :voicemail_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "voicemail_uuid" => $vVoicemail->voicemail_uuid,
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                $index = 0;
                $content = array();
                foreach ($vVoicemailMessages as $vVoicemailMessage) {
                    $content[$index]["created_epoch"] = $vVoicemailMessage->created_epoch;
                    $content[$index]["read_epoch"] = $vVoicemailMessage->read_epoch;
                    $content[$index]["voicemail_message_uuid"] = $vVoicemailMessage->voicemail_message_uuid;
                    $content[$index]["caller_id_name"] = $vVoicemailMessage->caller_id_name;
                    $content[$index]["caller_id_number"] = $vVoicemailMessage->caller_id_number;
                    $vExtension = VExtensions::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "extension" =>  $vVoicemailMessage->vVoicemails->voicemail_id,
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    $content[$index]["callee_id_name"] = $vExtension->effective_caller_id_name;
                    $content[$index]["callee_id_number"] = $vExtension->extension;
                    $content[$index]["duration"] = $vVoicemailMessage->message_length;
                    $index++;
                }
                $count2 = VVoicemailMessages::count(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND voicemail_uuid = :voicemail_uuid: AND read_epoch is NULL",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "voicemail_uuid" => $vVoicemail->voicemail_uuid,
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                        "count" => $count,
                        "count2" => $count2
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
        }
    }

    // ── Conference endpoints ───────────────────────────────────────────

    /**
     * Helper: open an ESL connection to FreeSWITCH and authenticate.
     * Returns the socket resource on success, or null on failure.
     */
    private function _eslConnect() {
        $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 2);
        if (!$fp) return null;
        stream_set_timeout($fp, 3);
        fread($fp, 4096); // read auth/request
        fwrite($fp, "auth ClueCon\n\n");
        fread($fp, 4096); // read auth reply
        return $fp;
    }

    /**
     * Helper: read ESL response headers, skipping any leading blank lines
     * left over from a previous command's trailing newline.
     */
    private function _eslReadHeaders($fp) {
        $headers = '';
        $foundNonEmpty = false;
        while (($line = fgets($fp)) !== false) {
            if (!$foundNonEmpty && trim($line) === '') continue; // skip leftover \n
            $foundNonEmpty = true;
            $headers .= $line;
            if (trim($line) === '') break; // end of headers
        }
        return $headers;
    }

    /**
     * Helper: send an ESL api command and return the response body.
     */
    private static function _eslSafe($command) {
        return is_string($command) && $command !== '' && strpbrk($command, "\r\n\0") === false;
    }

    private static function _isDialable($value) {
        return is_string($value) && preg_match('/^[0-9A-Za-z*#+_.-]{1,64}$/D', $value) === 1;
    }

    private static function _isCallUuid($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private static function _ownsConferenceId($conferenceId, $subdomain) {
        $prefix = "desktop:conference:";
        $suffix = "@" . $subdomain;
        if (!is_string($conferenceId) || $subdomain === '' || strpos($conferenceId, $prefix) !== 0
            || strlen($conferenceId) <= strlen($prefix) + strlen($suffix)
            || substr($conferenceId, -strlen($suffix)) !== $suffix) {
            return false;
        }
        return self::_isDialable(substr($conferenceId, strlen($prefix), -strlen($suffix)));
    }

    private function _uuidBelongsToTenant($fp, $uuid, $subdomain) {
        if (!self::_isCallUuid($uuid) || $subdomain === '') {
            return false;
        }
        $domain = $this->_eslApi($fp, "uuid_getvar $uuid domain_name");
        if ($domain === $subdomain) {
            return true;
        }
        $channels = json_decode($this->_eslApi($fp, "show channels as json"), true);
        foreach ((array) ($channels['rows'] ?? []) as $row) {
            if (!is_array($row) || strtolower((string) ($row['uuid'] ?? '')) !== $uuid) {
                continue;
            }
            $context = (string) ($row['context'] ?? '');
            $presence = (string) ($row['presence_id'] ?? '');
            return $context === $subdomain
                || ($presence !== '' && substr($presence, -(strlen($subdomain) + 1)) === '@' . $subdomain);
        }
        return false;
    }

    private function _eslApi($fp, $command) {
        if (!self::_eslSafe($command)) {
            return '-ERR invalid command';
        }
        fwrite($fp, "api $command\n\n");
        $headers = $this->_eslReadHeaders($fp);
        $contentLength = 0;
        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m)) {
            $contentLength = (int)$m[1];
        }
        $body = '';
        while ($contentLength > 0 && strlen($body) < $contentLength) {
            $chunk = fread($fp, $contentLength - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        return trim($body);
    }

    /**
     * Helper: send an ESL bgapi command (non-blocking).
     * Returns the Reply-Text header value (e.g. "+OK Job-UUID: ...").
     */
    private function _eslBgApi($fp, $command) {
        if (!self::_eslSafe($command)) {
            return '-ERR invalid command';
        }
        fwrite($fp, "bgapi $command\n\n");
        $headers = $this->_eslReadHeaders($fp);
        // bgapi response is in Reply-Text header, not in the body
        if (preg_match('/Reply-Text:\s*(.+)/i', $headers, $m)) {
            return trim($m[1]);
        }
        // Fallback: try reading body like api
        $contentLength = 0;
        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $m2)) {
            $contentLength = (int)$m2[1];
        }
        $body = '';
        while ($contentLength > 0 && strlen($body) < $contentLength) {
            $chunk = fread($fp, $contentLength - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        return trim($body);
    }

    /**
     * POST /desktop/conferenceInitiate
     * Initiates a 3-way conference call using FreeSWITCH eavesdrop.
     * Body: { "extension": "1001", "target": "1002" }
     */
    public function conferenceInitiateAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session || !$this->request->isPost()) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $data = $this->request->getJsonRawBody();
        $extension = isset($data->extension) ? trim($data->extension) : '';
        $target = isset($data->target) ? trim($data->target) : '';
        $subdomain = $session['subdomain'];

        if (empty($extension) || empty($target)) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing extension or target"]));
            return $this->response;
        }
        if (!self::_isDialable($extension) || !self::_isDialable($target)) {
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid extension or target"]));
            return $this->response;
        }

        try {
            // Look up number_alias for the extension — WebSocket SIP clients register
            // with a hash-like username (e.g. "db0074fa60f690501016") stored as
            // number_alias in VExtensions, NOT the human extension number "1002".
            // FreeSWITCH presence_id uses this SIP username, so we must match against it.
            $matchIdentifiers = [$extension]; // always try the raw extension
            $vExt = VExtensions::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND extension = :ext:",
                "bind" => [
                    "domain_uuid" => $session['domain_uuid'],
                    "ext" => $extension,
                ],
            ]);
            if ($vExt && !empty($vExt->number_alias)) {
                $matchIdentifiers[] = $vExt->number_alias;
            }

            $fp = $this->_eslConnect();
            if (!$fp) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Cannot connect to FreeSWITCH"]));
                return $this->response;
            }

            // Find the initiator's active call UUID
            $callsJson = $this->_eslApi($fp, "show calls as json");
            error_log("CONF_DEBUG: raw ESL response length=" . strlen($callsJson) . ", first100=" . substr($callsJson, 0, 100));
            $callData = json_decode($callsJson, true);
            error_log("CONF_DEBUG: json_decode result=" . (is_array($callData) ? "array" : "null") . ", row_count=" . ($callData['row_count'] ?? 'missing'));
            $bUuid = null;

            if (isset($callData['rows']) && is_array($callData['rows'])) {
                foreach ($callData['rows'] as $row) {
                    // Extract extension/SIP-username from presence_id and b_presence_id,
                    // then check against both the extension number AND number_alias.
                    foreach (['presence_id', 'b_presence_id'] as $field) {
                        if (!empty($row[$field]) && strpos($row[$field], '@') !== false) {
                            list($ext,) = explode('@', $row[$field], 2);
                            if (in_array($ext, $matchIdentifiers, true)) {
                                // presence_id match → b_uuid is the other party's leg
                                // b_presence_id match → uuid is the other party's leg
                                $bUuid = ($field === 'presence_id')
                                    ? (isset($row['b_uuid']) ? $row['b_uuid'] : null)
                                    : (isset($row['uuid']) ? $row['uuid'] : null);
                                if ($bUuid) break 2;
                            }
                        }
                    }
                }
            }

            if (!$bUuid) {
                fclose($fp);
                // Debug: log what we searched vs what ESL returned
                $debugRows = [];
                if (isset($callData['rows']) && is_array($callData['rows'])) {
                    foreach ($callData['rows'] as $row) {
                        $debugRows[] = [
                            'presence_id' => $row['presence_id'] ?? '',
                            'b_presence_id' => $row['b_presence_id'] ?? '',
                            'uuid' => $row['uuid'] ?? '',
                            'direction' => $row['direction'] ?? '',
                        ];
                    }
                }
                error_log("CONF_DEBUG: extension=$extension, matchIdentifiers=" . json_encode($matchIdentifiers)
                    . ", row_count=" . ($callData['row_count'] ?? 0)
                    . ", rows=" . json_encode($debugRows));
                $this->response->setContent(json_encode(["result" => "error", "message" => "No active call found for extension $extension"]));
                return $this->response;
            }

            // Store conference state in Redis
            $conferenceId = "desktop:conference:{$extension}@{$subdomain}";
            $conferenceData = json_encode([
                "initiator" => $extension,
                "b_uuid" => $bUuid,
                "target" => $target,
                "subdomain" => $subdomain,
                "created_at" => time(),
            ]);
            $this->redis->setex($conferenceId, 3600, $conferenceData);

            // Originate eavesdrop call (replicates signal.js 3-way pattern)
            $originateCmd = "originate {"
                . "ignore_early_media=true,"
                . "hangup_after_bridge=true,"
                . "inherit_codec=true,"
                . "nolocal:absolute_codec_string=PCMA,PCMU,G729,"
                . "disable-transcoding=true,"
                . "inbound-late-negotiation=true,"
                . "eavesdrop_enable_dtmf=true,"
                . "origination_caller_id_name=Conference,"
                . "origination_caller_id_number={$extension},"
                . "eavesdrop_bridge_aleg=true,"
                . "eavesdrop_whisper_aleg=true,"
                . "eavesdrop_bridge_bleg=true,"
                . "eavesdrop_whisper_bleg=true,"
                . "domain_name={$subdomain}"
                . "}user/{$target}@{$subdomain} &eavesdrop({$bUuid})";

            // Use bgapi so the originate runs asynchronously — api originate
            // blocks until the target answers/rejects (30+ seconds), which
            // exceeds the socket read timeout and returns empty.
            $result = $this->_eslBgApi($fp, $originateCmd);
            fclose($fp);

            // bgapi returns "+OK Job-UUID: <uuid>" on success
            if (strpos($result, '+OK') !== false) {
                $this->response->setContent(json_encode([
                    "result" => "success",
                    "conference_id" => $conferenceId,
                    "message" => "Conference call initiated",
                ]));
            } else {
                $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Failed to originate conference call: $result",
                ]));
            }
        } catch (\Exception $e) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Exception: " . ControllerBase::safeErrorMessage($e)]));
        }

        return $this->response;
    }

    /**
     * GET /desktop/conferenceParticipants
     * Polls participant status for an active conference.
     * Query: ?conference_id=desktop:conference:1001@domain.com
     */
    public function conferenceParticipantsAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session || !$this->request->isGet()) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $conferenceId = $this->request->getQuery("conference_id", "string", "");
        if (empty($conferenceId)) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing conference_id"]));
            return $this->response;
        }

        try {
            $conferenceJson = $this->redis->get($conferenceId);
            if (!$conferenceJson) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Conference not found"]));
                return $this->response;
            }
            $conferenceData = json_decode($conferenceJson, true);
            $subdomain = $conferenceData['subdomain'] ?? $session['subdomain'];
            $initiator = $conferenceData['initiator'] ?? '';

            $fp = $this->_eslConnect();
            if (!$fp) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Cannot connect to FreeSWITCH"]));
                return $this->response;
            }

            $callsJson = $this->_eslApi($fp, "show calls as json");
            fclose($fp);

            $callData = json_decode($callsJson, true);
            $participants = [];
            $seenExtensions = [];

            if (isset($callData['rows']) && is_array($callData['rows'])) {
                foreach ($callData['rows'] as $row) {
                    foreach (['presence_id', 'b_presence_id'] as $field) {
                        if (!empty($row[$field]) && strpos($row[$field], '@') !== false) {
                            list($ext,) = explode('@', $row[$field], 2);
                            if (!isset($seenExtensions[$ext])) {
                                $seenExtensions[$ext] = true;

                                // Look up display name and real extension from VExtensions.
                                // presence_id may use number_alias (hash), so resolve to the
                                // human-readable extension number for the UI.
                                $displayName = $ext;
                                $realExtension = $ext;
                                $vExt = VExtensions::findFirst([
                                    "conditions" => "domain_uuid = :domain_uuid: AND (extension = :ext: OR number_alias = :ext:)",
                                    "bind" => ["domain_uuid" => $session["domain_uuid"], "ext" => $ext],
                                ]);
                                if ($vExt) {
                                    $realExtension = $vExt->extension;
                                    if (isset($vExt->vExtensionUsers->qUsers)) {
                                        $fn = $vExt->vExtensionUsers->qUsers->first_name ?? '';
                                        $ln = $vExt->vExtensionUsers->qUsers->last_name ?? '';
                                        $displayName = trim("$fn $ln");
                                    }
                                    if (empty($displayName)) $displayName = $realExtension;
                                }

                                $uuid = ($field === 'presence_id') ? ($row['uuid'] ?? '') : ($row['b_uuid'] ?? '');

                                $participants[] = [
                                    "extension" => $realExtension,
                                    "display_name" => $displayName,
                                    "uuid" => $uuid,
                                    "is_muted" => false,
                                    "is_initiator" => ($ext === $initiator),
                                    "status" => "connected",
                                ];
                            }
                        }
                    }
                }
            }

            $this->response->setContent(json_encode([
                "result" => "success",
                "participants" => $participants,
            ]));
        } catch (\Exception $e) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Exception: " . ControllerBase::safeErrorMessage($e)]));
        }

        return $this->response;
    }

    /**
     * POST /desktop/conferenceKick
     * Removes a participant from the conference.
     * Body: { "conference_id": "...", "uuid": "..." }
     */
    public function conferenceKickAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session || !$this->request->isPost()) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $data = $this->request->getJsonRawBody();
        $uuid = isset($data->uuid) ? strtolower(trim((string) $data->uuid)) : '';

        if (empty($uuid)) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing uuid"]));
            return $this->response;
        }
        if (!self::_isCallUuid($uuid)) {
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid uuid"]));
            return $this->response;
        }

        try {
            $fp = $this->_eslConnect();
            if (!$fp) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Cannot connect to FreeSWITCH"]));
                return $this->response;
            }

            if (!$this->_uuidBelongsToTenant($fp, $uuid, (string) ($session['subdomain'] ?? ''))) {
                fclose($fp);
                $this->response->setStatusCode(404, "Not Found");
                $this->response->setContent(json_encode(["result" => "error", "message" => "No active call with that uuid on this account"]));
                return $this->response;
            }
            $result = $this->_eslApi($fp, "uuid_kill $uuid");
            fclose($fp);

            $this->response->setContent(json_encode([
                "result" => "success",
                "message" => "Participant removed",
            ]));
        } catch (\Exception $e) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Exception: " . ControllerBase::safeErrorMessage($e)]));
        }

        return $this->response;
    }

    /**
     * POST /desktop/conferenceEscalate
     * Phase 2: Escalates a 3-way eavesdrop conference to a full FreeSWITCH conference room.
     * Body: { "conference_id": "...", "extension": "1001" }
     */
    public function conferenceEscalateAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session || !$this->request->isPost()) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $data = $this->request->getJsonRawBody();
        $conferenceId = isset($data->conference_id) ? trim($data->conference_id) : '';
        $extension = isset($data->extension) ? trim($data->extension) : '';
        $subdomain = $session['subdomain'];
        $domainUuid = $session['domain_uuid'];

        if (empty($conferenceId) || empty($extension)) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing conference_id or extension"]));
            return $this->response;
        }
        if (!self::_isDialable($extension) || !self::_ownsConferenceId($conferenceId, (string) $subdomain)) {
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid conference_id or extension"]));
            return $this->response;
        }

        try {
            // Determine next available conference extension (4000+)
            $maxExt = VConferenceCenters::maximum([
                "column" => "conference_center_extension",
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $domainUuid],
            ]);
            $confExtension = $maxExt ? ((int)$maxExt + 1) : 4000;

            // Create conference room records
            $confUuid = \Phalcon\Security\Random::uuid();
            $dialplanUuid = \Phalcon\Security\Random::uuid();
            $roomUuid = \Phalcon\Security\Random::uuid();
            $confName = "Desktop-Conf-{$extension}-" . time();

            $center = new VConferenceCenters();
            $center->conference_center_uuid = $confUuid;
            $center->domain_uuid = $domainUuid;
            $center->dialplan_uuid = $dialplanUuid;
            $center->conference_center_name = $confName;
            $center->conference_center_extension = $confExtension;
            $center->conference_center_pin_length = 4;
            $center->conference_center_greeting = "conference/conf-pin.wav";
            $center->conference_center_enabled = "true";
            $center->insert_user = $session['user_uuid'];
            $center->insert_date = date("Y-m-d H:i:s");

            if (!$center->save()) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Failed to create conference center"]));
                return $this->response;
            }

            $room = new VConferenceRooms();
            $room->conference_room_uuid = $roomUuid;
            $room->conference_center_uuid = $confUuid;
            $room->domain_uuid = $domainUuid;
            $room->conference_room_name = $confName;
            $room->profile = "default";
            $room->record = "false";
            $room->moderator_pin = $extension;
            $room->participant_pin = "";
            $room->max_members = 0;
            $room->wait_mod = "false";
            $room->moderator_endconf = "false";
            $room->announce_name = "false";
            $room->announce_recording = "false";
            $room->announce_count = "false";
            $room->sounds = "false";
            $room->mute = "false";
            $room->enabled = "true";
            $room->description = (string)$confExtension;
            $room->account_code = $subdomain;
            $room->insert_date = date("Y-m-d H:i:s");
            $room->insert_user = $session['user_uuid'];

            if (!$room->save()) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Failed to create conference room"]));
                return $this->response;
            }

            // Create dialplan
            $dialplanXml = '<extension name="' . htmlspecialchars($confName) . '" continue="" uuid="' . $dialplanUuid . '">'
                . '<action application="set" data="absolute_codec_string=G729"/>'
                . '<condition field="destination_number" expression="^' . $confExtension . '$">'
                . '<action application="lua" data="app.lua conference_center"/>'
                . '</condition></extension>';

            $dialplan = new VDialplans();
            $dialplan->domain_uuid = $domainUuid;
            $dialplan->dialplan_uuid = $dialplanUuid;
            $dialplan->app_uuid = "69f10e16-49f0-4012-9945-f45992e43e29";
            $dialplan->dialplan_name = $confName;
            $dialplan->dialplan_number = (string)$confExtension;
            $dialplan->dialplan_order = 82;
            $dialplan->dialplan_context = $subdomain;
            $dialplan->dialplan_continue = "";
            $dialplan->dialplan_xml = $dialplanXml;
            $dialplan->dialplan_enabled = "true";
            $dialplan->insert_user = $session['user_uuid'];
            $dialplan->insert_date = date("Y-m-d H:i:s");

            if (!$dialplan->save()) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Failed to create dialplan"]));
                return $this->response;
            }

            // Clear dialplan cache
            @unlink("/var/cache/fusionpbx/dialplan.{$subdomain}");
            @unlink("/var/cache/fusionpbx/dialplan.public");
            @unlink("/var/cache/ringq/dialplan.{$subdomain}");
            @unlink("/var/cache/ringq/dialplan.public");

            // Transfer all active legs into the conference room
            $fp = $this->_eslConnect();
            if ($fp) {
                // Reload XML so the new dialplan is active
                $this->_eslApi($fp, "reloadxml");

                $callsJson = $this->_eslApi($fp, "show calls as json");
                $callData = json_decode($callsJson, true);

                // Build a set of identifiers (extension + number_alias) for all
                // conference participants so we only transfer their legs, not every
                // call on this domain.
                $conferenceJson2 = $this->redis->get($conferenceId);
                $confParticipantIds = [$extension]; // initiator extension
                if ($conferenceJson2) {
                    $confData2 = json_decode($conferenceJson2, true);
                    if (!empty($confData2['initiator'])) $confParticipantIds[] = $confData2['initiator'];
                    if (!empty($confData2['target'])) $confParticipantIds[] = $confData2['target'];
                }
                // Resolve number_alias for each participant extension
                foreach (array_unique($confParticipantIds) as $pExt) {
                    $pVExt = VExtensions::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND extension = :ext:",
                        "bind" => ["domain_uuid" => $domainUuid, "ext" => $pExt],
                    ]);
                    if ($pVExt && !empty($pVExt->number_alias)) {
                        $confParticipantIds[] = $pVExt->number_alias;
                    }
                }
                $confParticipantIds = array_unique($confParticipantIds);

                if (isset($callData['rows']) && is_array($callData['rows'])) {
                    $transferredUuids = [];
                    foreach ($callData['rows'] as $row) {
                        foreach (['uuid', 'b_uuid'] as $uuidField) {
                            if (!empty($row[$uuidField]) && !in_array($row[$uuidField], $transferredUuids)) {
                                // Only transfer legs that belong to conference participants
                                $presenceId = ($uuidField === 'uuid') ? ($row['presence_id'] ?? '') : ($row['b_presence_id'] ?? '');
                                if (!empty($presenceId) && strpos($presenceId, '@') !== false) {
                                    list($pId,) = explode('@', $presenceId, 2);
                                    if (in_array($pId, $confParticipantIds, true)) {
                                        $this->_eslApi($fp, "uuid_transfer {$row[$uuidField]} {$confExtension} XML {$subdomain}");
                                        $transferredUuids[] = $row[$uuidField];
                                    }
                                }
                            }
                        }
                    }
                }
                fclose($fp);
            }

            // Update Redis conference state
            $conferenceJson = $this->redis->get($conferenceId);
            if ($conferenceJson) {
                $conferenceData = json_decode($conferenceJson, true);
                $conferenceData['conference_extension'] = $confExtension;
                $conferenceData['is_escalated'] = true;
                $this->redis->setex($conferenceId, 3600, json_encode($conferenceData));
            }

            $this->response->setContent(json_encode([
                "result" => "success",
                "conference_extension" => (string)$confExtension,
                "conference_id" => $conferenceId,
            ]));
        } catch (\Exception $e) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Exception: " . ControllerBase::safeErrorMessage($e)]));
        }

        return $this->response;
    }

    /**
     * POST /desktop/conferenceInvite
     * Phase 2: Invites a new participant into an existing conference room.
     * Body: { "conference_extension": "4001", "target": "1003" }
     */
    public function conferenceInviteAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session || !$this->request->isPost()) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $data = $this->request->getJsonRawBody();
        $confExtension = isset($data->conference_extension) ? trim($data->conference_extension) : '';
        $target = isset($data->target) ? trim($data->target) : '';
        $subdomain = $session['subdomain'];
        $initiatorExt = $session['extension'];

        if (empty($confExtension) || empty($target)) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Missing conference_extension or target"]));
            return $this->response;
        }
        if (!self::_isDialable($confExtension) || !self::_isDialable($target)) {
            $this->response->setStatusCode(400, "Bad Request");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Invalid conference_extension or target"]));
            return $this->response;
        }

        try {
            $fp = $this->_eslConnect();
            if (!$fp) {
                $this->response->setContent(json_encode(["result" => "error", "message" => "Cannot connect to FreeSWITCH"]));
                return $this->response;
            }

            $originateCmd = "originate {"
                . "ignore_early_media=true,"
                . "hangup_after_bridge=true,"
                . "inherit_codec=true,"
                . "nolocal:absolute_codec_string=PCMA,PCMU,G729,"
                . "origination_caller_id_name=Conference,"
                . "origination_caller_id_number={$initiatorExt},"
                . "domain_name={$subdomain}"
                . "}user/{$target}@{$subdomain} &conference({$confExtension}@{$subdomain})";

            $result = $this->_eslApi($fp, $originateCmd);
            fclose($fp);

            if (strpos($result, '+OK') === 0) {
                $this->response->setContent(json_encode([
                    "result" => "success",
                    "message" => "Participant invited to conference",
                ]));
            } else {
                $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Failed to invite participant: $result",
                ]));
            }
        } catch (\Exception $e) {
            $this->response->setContent(json_encode(["result" => "error", "message" => "Exception: " . ControllerBase::safeErrorMessage($e)]));
        }

        return $this->response;
    }

    public function unreadCountAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        // 1. Voicemail Unread Count
        $vVoicemail = VVoicemails::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND voicemail_id = :voicemail_id:",
            "bind" => [
                "domain_uuid" => $session["domain_uuid"],
                "voicemail_id" => $session["extension"],
            ],
        ]);
        $voicemailUnread = 0;
        if ($vVoicemail) {
            $voicemailUnread = VVoicemailMessages::count([
                "conditions" => "domain_uuid = :domain_uuid: AND voicemail_uuid = :voicemail_uuid: AND read_epoch IS NULL",
                "bind" => [
                    "domain_uuid" => $session["domain_uuid"],
                    "voicemail_uuid" => $vVoicemail->voicemail_uuid,
                ],
            ]);
        }

        // 2. Missed Calls Unread Count
        $currentMissedTotal = QRingqCdr::count([
            "conditions" => "caller_destination = :extension: AND domain_uuid = :domain_uuid: AND missed_call = true",
            "bind" => [
                "extension" => $session["extension"],
                "domain_uuid" => $session["domain_uuid"],
            ],
        ]);
        
        $redisKey = "last_seen_missed:" . $session["extension"] . "@" . $session["subdomain"];
        $lastSeenMissed = intval($this->redis->get($redisKey) ?? 0);
        $missedUnread = max(0, $currentMissedTotal - $lastSeenMissed);

        $this->response->setContent(json_encode([
            "result" => "success",
            "voicemails" => $voicemailUnread,
            "missed_calls" => $missedUnread,
        ]));
        return $this->response;
    }

    public function resetMissedCallsAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(401, "Unauthorized");
            $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
            return $this->response;
        }

        $currentMissedTotal = QRingqCdr::count([
            "conditions" => "caller_destination = :extension: AND domain_uuid = :domain_uuid: AND missed_call = true",
            "bind" => [
                "extension" => $session["extension"],
                "domain_uuid" => $session["domain_uuid"],
            ],
        ]);

        $redisKey = "last_seen_missed:" . $session["extension"] . "@" . $session["subdomain"];
        $this->redis->set($redisKey, $currentMissedTotal);

        $this->response->setContent(json_encode(["result" => "success"]));
        return $this->response;
    }
}