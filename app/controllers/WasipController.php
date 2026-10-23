<?php
declare(strict_types=1);

use Phalcon\Http\Response;

class WasipController extends ControllerBase
{
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    |
    | Matches FaxController's conventions:
    |   - $this->db is already wired up in the DI container -- no config
    |     file parsing, no separate PDO connection.
    |   - Auth is session-only: $this->session->get('auth') must contain a
    |     non-empty 'subdomain'. No q_tokens lookup, no Bearer header, no
    |     ?token= query param. domain_uuid is read straight from the
    |     session if present, otherwise resolved once via v_domains by
    |     subdomain.
    |   - domain_uuid comes from the authenticated user's session, so
    |     wa_gateway_config is scoped per-domain (one row per domain_uuid)
    |     instead of "the one global row" the old Micro-app version used.
    |
    */

    private int $requestTimeoutConfigure = 120; // seconds; Meta API round trips
    private const META_GRAPH_VERSION = 'v21.0';

    public const META_IPS = [
        '66.220.144.0/20', '66.220.128.0/19', '69.63.176.0/20',
        '69.171.224.0/19', '173.252.64.0/18', '204.15.20.0/22',
        '74.119.76.0/22', '157.240.0.0/16', '179.60.192.0/22',
        '31.13.24.0/21', '31.13.64.0/18',
    ];

    public function initialize(): void
    {
        parent::initialize();
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/wasip/health -- no auth required
    |--------------------------------------------------------------------------
    */

    public function healthAction()
    {
        $db = 'error';
        try {
            $this->db->fetchOne("SELECT 1");
            $db = 'ok';
        } catch (Throwable $e) {
        }

        return $this->jsonResponse([
            "status"  => 200,
            "message" => "ok",
            "data"    => [
                'ok'      => true,
                'php'     => PHP_VERSION,
                'phalcon' => phpversion('phalcon'),
                'db'      => $db,
                'host'    => gethostname(),
            ]
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/wasip/destinations -- extension/queue/ivr dropdown list
    |--------------------------------------------------------------------------
    */

    public function destinationsAction()
    {
        try {

            $auth = $this->requireAuth();
            if (!$auth) {
                return $this->response;
            }

            $domainUuid = $auth['domain_uuid'];

            $extensions = $this->db->fetchAll(
                "SELECT extension, extension_uuid, effective_caller_id_name
                 FROM v_extensions WHERE domain_uuid = ?::uuid ORDER BY extension",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            $queues = $this->db->fetchAll(
                "SELECT queue_extension AS extension, call_center_queue_uuid AS extension_uuid,
                        queue_name AS effective_caller_id_name
                 FROM v_call_center_queues WHERE domain_uuid = ?::uuid ORDER BY queue_extension",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            $ivrs = $this->db->fetchAll(
                "SELECT ivr_menu_extension AS extension, ivr_menu_uuid AS extension_uuid,
                        ivr_menu_name AS effective_caller_id_name
                 FROM v_ivr_menus
                 WHERE domain_uuid = ?::uuid AND (ivr_menu_enabled IS NULL OR ivr_menu_enabled = 'true')
                 ORDER BY ivr_menu_name",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            return $this->jsonResponse([
                "status"  => 200,
                "message" => "Destinations",
                "data"    => ['extensions' => $extensions, 'queues' => $queues, 'ivrs' => $ivrs]
            ]);

        } catch (Throwable $e) {
            error_log("destinationsAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to load destinations", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/wasip/load -- saved config for this domain + dropdown data
    |--------------------------------------------------------------------------
    */

    public function loadAction()
    {
        try {

            $auth = $this->requireAuth();
            if (!$auth) {
                return $this->response;
            }

            $domainUuid = $auth['domain_uuid'];
            $row = $this->getGatewayConfig($domainUuid);

            $currentRoute = $row['extension_number'] ?? '';
            $waNum = $row['wa_number'] ?? '';

            if ($waNum) {
                $transferData = $this->db->fetchOne(
                    "SELECT dd.dialplan_detail_data
                     FROM v_dialplan_details dd
                     JOIN v_dialplans dp ON dp.dialplan_uuid = dd.dialplan_uuid
                     WHERE dp.domain_uuid = ?::uuid
                       AND dp.dialplan_context = 'public'
                       AND (dp.dialplan_number = ? OR dp.dialplan_number = ?)
                       AND dd.dialplan_detail_tag = 'action'
                       AND dd.dialplan_detail_type = 'transfer'
                     ORDER BY dp.insert_date DESC LIMIT 1",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    [$domainUuid, $waNum, '+' . $waNum]
                );

                if ($transferData && !empty($transferData['dialplan_detail_data'])) {
                    $parts = explode(' ', $transferData['dialplan_detail_data']);
                    $currentRoute = $parts[0] ?? $currentRoute;
                }

                if ($currentRoute !== '') {
                    $this->db->execute(
                        "UPDATE wa_gateway_config SET extension_number = ?, updated_at = NOW()
                         WHERE domain_uuid = ?::uuid AND wa_number = ?",
                        [$currentRoute, $domainUuid, $waNum]
                    );
                }
            }

            $extensions = $this->db->fetchAll(
                "SELECT extension, extension_uuid, effective_caller_id_name
                 FROM v_extensions WHERE domain_uuid = ?::uuid ORDER BY extension",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            $queues = $this->db->fetchAll(
                "SELECT queue_extension AS extension, call_center_queue_uuid AS extension_uuid,
                        queue_name AS effective_caller_id_name
                 FROM v_call_center_queues WHERE domain_uuid = ?::uuid ORDER BY queue_extension",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            $ivrs = $this->db->fetchAll(
                "SELECT ivr_menu_extension AS extension, ivr_menu_uuid AS extension_uuid,
                        ivr_menu_name AS effective_caller_id_name
                 FROM v_ivr_menus
                 WHERE domain_uuid = ?::uuid AND (ivr_menu_enabled IS NULL OR ivr_menu_enabled = 'true')
                 ORDER BY ivr_menu_name",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            return $this->jsonResponse([
                "status"  => 200,
                "message" => "Gateway config",
                "data"    => [
                    'waNumber'          => $row['wa_number'] ?? '',
                    'phoneNumberId'     => $row['phone_number_id'] ?? '',
                    'businessAccountId' => $row['business_account_id'] ?? '',
                    'pbxDomain'         => $row['pbx_domain'] ?? '',
                    'pbxSipPort'        => $row['pbx_sip_port'] ?? 5061,
                    'extensionNumber'   => $currentRoute,
                    'extensionUuid'     => $row['extension_uuid'] ?? '',
                    'hasToken'          => !empty($row['access_token_enc']),
                    'hasSecret'         => !empty($row['app_secret_enc']),
                    'hasPassword'       => !empty($row['meta_sip_pass_enc']),
                    'gatewayStatus'     => $row['gateway_status'] ?? 'unconfigured',
                    'lastConfigured'    => $row['last_configured'] ?? null,
                    'extensions'        => $extensions,
                    'queues'            => $queues,
                    'ivrs'              => $ivrs,
                ]
            ]);

        } catch (Throwable $e) {
            error_log("loadAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to load config", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/wasip/status -- live gateway/dialplan status for this domain
    |--------------------------------------------------------------------------
    */

    public function statusAction()
    {
        try {

            $auth = $this->requireAuth();
            if (!$auth) {
                return $this->response;
            }

            $domainUuid = $auth['domain_uuid'];
            $row = $this->getGatewayConfig($domainUuid);

            if (!$row) {
                return $this->jsonResponse([
                    "status" => 200, "message" => "Status",
                    "data" => ['configured' => false, 'active' => false, 'gatewayStatus' => 'unconfigured']
                ]);
            }

            $waNum = $row['wa_number'] ?? '';

            $gw = $this->db->fetchOne(
                "SELECT 1 FROM v_gateways WHERE domain_uuid = ?::uuid AND gateway = ? AND enabled = 'true' LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid, 'watrunk_' . $waNum]
            );

            $dp = $waNum ? $this->db->fetchOne(
                "SELECT 1 FROM v_dialplans
                 WHERE domain_uuid = ?::uuid AND dialplan_context = 'public'
                   AND (dialplan_number = ? OR dialplan_number = ?) AND dialplan_enabled = 'true' LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid, $waNum, '+' . $waNum]
            ) : null;

            $this->db->execute(
                "UPDATE wa_gateway_config SET last_checked = NOW() WHERE domain_uuid = ?::uuid AND wa_number = ?",
                [$domainUuid, $waNum]
            );

            $extensionNumber = $row['extension_number'] ?? '';
            if ($waNum) {
                $s = $this->db->fetchOne(
                    "SELECT dd.dialplan_detail_data
                     FROM v_dialplan_details dd
                     JOIN v_dialplans dp ON dp.dialplan_uuid = dd.dialplan_uuid
                     WHERE dp.domain_uuid = ?::uuid AND dp.dialplan_context = 'public'
                       AND (dp.dialplan_number = ? OR dp.dialplan_number = ?)
                       AND dd.dialplan_detail_tag = 'action' AND dd.dialplan_detail_type = 'transfer'
                     ORDER BY dp.insert_date DESC LIMIT 1",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    [$domainUuid, $waNum, '+' . $waNum]
                );
                if ($s && !empty($s['dialplan_detail_data'])) {
                    $p = explode(' ', $s['dialplan_detail_data']);
                    $extensionNumber = $p[0];
                }
            }

            return $this->jsonResponse([
                "status" => 200, "message" => "Status",
                "data" => [
                    'configured'      => true,
                    'active'          => ($row['gateway_status'] === 'active'),
                    'gatewayStatus'   => $row['gateway_status'],
                    'waNumber'        => $waNum,
                    'gatewayInDB'     => (bool) $gw,
                    'dialplanInDB'    => (bool) $dp,
                    'extensionNumber' => $extensionNumber,
                    'pbxDomain'       => $row['pbx_domain'] ?? '',
                    'lastConfigured'  => $row['last_configured'],
                ]
            ]);

        } catch (Throwable $e) {
            error_log("statusAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to get status", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/wasip/configure -- first-time provisioning for this domain
    |--------------------------------------------------------------------------
    */

    public function configureAction()
    {
        set_time_limit($this->requestTimeoutConfigure);

        $domainUuid = null;
        $waNum = '';
        $steps = [];
        $ok = function (string $m) use (&$steps) { $steps[] = ['s' => 'ok', 'm' => $m]; };

        try {

            $auth = $this->requireAuth();
            if (!$auth) {
                return $this->response;
            }

            $domainUuid = $auth['domain_uuid'];
            $body = $this->request->getJsonRawBody();

            $saved = $this->getGatewayConfig($domainUuid);

            $token   = !empty($body->accessToken) ? trim((string) $body->accessToken) : $this->dec($saved['access_token_enc'] ?? '');
            $secret  = !empty($body->appSecret) ? trim((string) $body->appSecret) : $this->dec($saved['app_secret_enc'] ?? '');
            $pid     = trim((string) ($body->phoneNumberId ?? ($saved['phone_number_id'] ?? '')));
            $bid     = trim((string) ($body->businessAcctId ?? ($saved['business_account_id'] ?? '')));
            $waNum   = preg_replace('/\D/', '', (string) ($body->waNumber ?? ($saved['wa_number'] ?? '')));
            $pbxD    = trim((string) ($body->pbxDomain ?? ($saved['pbx_domain'] ?? '')));
            $pbxP    = (int) ($body->pbxSipPort ?? ($saved['pbx_sip_port'] ?? 5061));
            $extNum  = trim((string) ($body->extensionNumber ?? ''));
            $extUuid = trim((string) ($body->extensionUuid ?? ''));

            if (!$token)  return $this->jsonResponse(["status" => 400, "message" => "Access Token is required", "data" => ""]);
            if (!$pid)    return $this->jsonResponse(["status" => 400, "message" => "Phone Number ID is required", "data" => ""]);
            if (!$waNum)  return $this->jsonResponse(["status" => 400, "message" => "WhatsApp Number is required", "data" => ""]);
            if (!$pbxD)   return $this->jsonResponse(["status" => 400, "message" => "PBX Domain is required", "data" => ""]);
            if (!$extNum) return $this->jsonResponse(["status" => 400, "message" => "Extension is required", "data" => ""]);
            $ok('Validation passed');

            $this->db->execute(
                "INSERT INTO wa_gateway_config
                   (domain_uuid, wa_number, phone_number_id, business_account_id, pbx_domain, pbx_sip_port,
                    extension_number, extension_uuid, access_token_enc, app_secret_enc,
                    gateway_status, created_at, updated_at)
                 VALUES (?::uuid,?,?,?,?,?,?,?,?,?,'configuring',NOW(),NOW())
                 ON CONFLICT (domain_uuid) DO UPDATE SET
                    wa_number=EXCLUDED.wa_number, phone_number_id=EXCLUDED.phone_number_id,
                    business_account_id=EXCLUDED.business_account_id, pbx_domain=EXCLUDED.pbx_domain,
                    pbx_sip_port=EXCLUDED.pbx_sip_port, extension_number=EXCLUDED.extension_number,
                    extension_uuid=EXCLUDED.extension_uuid,
                    access_token_enc=CASE WHEN EXCLUDED.access_token_enc<>'' THEN EXCLUDED.access_token_enc ELSE wa_gateway_config.access_token_enc END,
                    app_secret_enc=CASE WHEN EXCLUDED.app_secret_enc<>'' THEN EXCLUDED.app_secret_enc ELSE wa_gateway_config.app_secret_enc END,
                    gateway_status='configuring', updated_at=NOW()",
                [
                    $domainUuid, $waNum, $pid, $bid, $pbxD, $pbxP, $extNum, $extUuid,
                    !empty($body->accessToken) ? $this->enc($token) : ($saved['access_token_enc'] ?? ''),
                    !empty($body->appSecret) ? $this->enc($secret) : ($saved['app_secret_enc'] ?? ''),
                ]
            );
            $ok('Credentials saved (AES-256 encrypted)');

            $check = $this->metaCall('GET', "{$pid}?fields=id,display_phone_number", null, $token);
            if (empty($check['id'])) throw new \RuntimeException('Cannot connect to Meta API — check Access Token');
            $ok('Meta API connected — phone: ' . ($check['display_phone_number'] ?? $waNum));

            $r = $this->metaCall('POST', "{$pid}/settings", ['calling' => ['status' => 'ENABLED', 'call_icon_visibility' => 'DEFAULT']], $token);
            if (empty($r['success'])) throw new \RuntimeException('Enable calling failed: ' . json_encode($r));
            $ok('WhatsApp Calling enabled');

            $r = $this->metaCall('POST', "{$pid}/settings", ['calling' => ['status' => 'ENABLED', 'sip' => ['status' => 'ENABLED', 'servers' => [['hostname' => $pbxD, 'port' => $pbxP]]]]], $token);
            if (empty($r['success'])) throw new \RuntimeException('Enable SIP failed: ' . json_encode($r));
            $ok("SIP mode enabled → {$pbxD}:{$pbxP}");

            $r = $this->metaCall('POST', "{$pid}/settings", ['calling' => ['srtp_key_exchange_protocol' => 'SDES']], $token);
            if (empty($r['success'])) throw new \RuntimeException('Set SDES failed: ' . json_encode($r));
            $ok('SDES key exchange set (required for audio)');

            $settings = $this->metaCall('GET', "{$pid}/settings?include_sip_credentials=true", null, $token);
            $sipPass = null;
            foreach ($settings['calling']['sip']['servers'] ?? [] as $s) {
                if (!empty($s['sip_user_password'])) { $sipPass = $s['sip_user_password']; break; }
            }
            if (!$sipPass) throw new \RuntimeException('Meta did not return SIP password — wait 30s and retry');

            $this->db->execute(
                "UPDATE wa_gateway_config SET meta_sip_pass_enc = ? WHERE domain_uuid = ?::uuid AND wa_number = ?",
                [$this->enc($sipPass), $domainUuid, $waNum]
            );
            $ok('Meta SIP password received and saved');

            $domName = $this->getDomainName($domainUuid);
            $gwName  = 'watrunk_' . $waNum; // unique per-domain gateway name, since one PBX now hosts many domains' WA gateways

            $gwExists = (bool) $this->db->fetchOne(
                "SELECT 1 FROM v_gateways WHERE domain_uuid = ?::uuid AND gateway = ?",
                \Phalcon\Db\Enum::FETCH_ASSOC, [$domainUuid, $gwName]
            );

            if ($gwExists) {
                $this->db->execute(
                    "UPDATE v_gateways SET username=?,auth_username=?,password=?,proxy=?,register_proxy=?,realm=?,
                        register_transport='tls',codec_prefs='OPUS',from_user=?,from_domain=?,context=?,profile='internal',
                        register='true',enabled='true',expire_seconds=600,retry_seconds=30,ping=25,description=?,update_date=NOW()
                     WHERE domain_uuid=?::uuid AND gateway=?",
                    [$waNum, $waNum, $sipPass, 'wa.meta.vc:5061', 'wa.meta.vc:5061', 'wa.meta.vc:5061', $waNum, $pbxD, $domName, $waNum, $domainUuid, $gwName]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO v_gateways
                       (gateway_uuid,domain_uuid,gateway,username,auth_username,password,proxy,register_proxy,realm,
                        register_transport,codec_prefs,from_user,from_domain,context,profile,register,enabled,
                        expire_seconds,retry_seconds,ping,description,insert_date)
                     VALUES (gen_random_uuid(),?::uuid,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,600,30,25,?,NOW())",
                    [$domainUuid, $gwName, $waNum, $waNum, $sipPass, 'wa.meta.vc:5061', 'wa.meta.vc:5061', 'wa.meta.vc:5061', 'tls', 'OPUS', $waNum, $pbxD, $domName, 'internal', 'true', 'true', $waNum]
                );
            }
            $ok('RingQ gateway created/updated');

            $aclUuid = $this->db->fetchOne(
                "SELECT access_control_uuid FROM v_access_controls WHERE access_control_name='providers' LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC
            );
            if ($aclUuid) {
                foreach (self::META_IPS as $cidr) {
                    $exists = $this->db->fetchOne(
                        "SELECT 1 FROM v_access_control_nodes WHERE access_control_uuid=?::uuid AND node_cidr=?",
                        \Phalcon\Db\Enum::FETCH_ASSOC, [$aclUuid['access_control_uuid'], $cidr]
                    );
                    if (!$exists) {
                        $this->db->execute(
                            "INSERT INTO v_access_control_nodes
                               (access_control_node_uuid,access_control_uuid,node_type,node_cidr,node_description,insert_date)
                             VALUES (gen_random_uuid(),?::uuid,'allow',?,'Meta WhatsApp SIP',NOW())",
                            [$aclUuid['access_control_uuid'], $cidr]
                        );
                    }
                }
            }
            $ok('Providers ACL updated with Meta IP ranges');

            $dpUuid = $this->db->fetchOne("SELECT gen_random_uuid() AS u", \Phalcon\Db\Enum::FETCH_ASSOC)['u'];

            $extNameRow = $this->db->fetchOne(
                "SELECT effective_caller_id_name FROM v_extensions WHERE extension_uuid=?::uuid LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC, [$extUuid ?: '00000000-0000-0000-0000-000000000000']
            );
            $extName = $extNameRow['effective_caller_id_name'] ?? $extNum;

            $this->db->execute("DELETE FROM q_dids WHERE domain_uuid=?::uuid AND did_number=?", [$domainUuid, $waNum]);

            $existingDp = $this->db->fetchAll(
                "SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid=?::uuid AND dialplan_context='public' AND (dialplan_number=? OR dialplan_number=?)",
                \Phalcon\Db\Enum::FETCH_ASSOC, [$domainUuid, $waNum, "+$waNum"]
            );
            foreach ($existingDp as $oldDp) {
                $this->db->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid=?::uuid", [$oldDp['dialplan_uuid']]);
                $this->db->execute("DELETE FROM v_dialplans WHERE dialplan_uuid=?::uuid", [$oldDp['dialplan_uuid']]);
            }
            if (!empty($saved['dialplan_uuid'])) {
                $this->db->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid=?::uuid", [$saved['dialplan_uuid']]);
                $this->db->execute("DELETE FROM v_dialplans WHERE dialplan_uuid=?::uuid", [$saved['dialplan_uuid']]);
            }

            $didUuid = $this->db->fetchOne("SELECT gen_random_uuid() AS u", \Phalcon\Db\Enum::FETCH_ASSOC)['u'];
            $this->db->execute(
                "INSERT INTO q_dids (did_uuid, domain_uuid, did_number, type, name, extension, sip_trunk_name, insert_date)
                 VALUES (?::uuid,?::uuid,?,'3',?,?,?,NOW())",
                [$didUuid, $domainUuid, $waNum, $extName, $extNum, $gwName]
            );

            $dpXml = $this->buildDialplanXml($waNum, $domainUuid, $domName, $extNum, $dpUuid);

            $this->db->execute(
                "INSERT INTO v_dialplans (dialplan_uuid, app_uuid, domain_uuid, dialplan_context, dialplan_name, dialplan_number,
                    dialplan_destination, dialplan_continue, dialplan_enabled, dialplan_xml, dialplan_order, insert_date)
                 VALUES (?::uuid,?::uuid,?::uuid,'public',?,?,NULL,'false','true',?,0,NOW())",
                [$dpUuid, $extUuid ?: null, $domainUuid, $waNum, $waNum, $dpXml]
            );

            $this->db->execute(
                "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order, insert_date)
                 VALUES (gen_random_uuid(),?::uuid,?::uuid,'condition','destination_number',?,0,20,NOW())",
                [$domainUuid, $dpUuid, '^(' . $waNum . ')$']
            );

            $this->db->execute(
                "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order, insert_date)
                 VALUES (gen_random_uuid(),?::uuid,?::uuid,'action','transfer',?,0,110,NOW())",
                [$domainUuid, $dpUuid, $extNum . ' XML ' . $domName]
            );

            $this->db->execute(
                "UPDATE wa_gateway_config SET dialplan_uuid=?::uuid, gateway_status='configured', last_configured=NOW(), updated_at=NOW()
                 WHERE domain_uuid=?::uuid AND wa_number=?",
                [$dpUuid, $domainUuid, $waNum]
            );
            $ok("DID + dialplan created (+$waNum → extension $extNum)");

            exec('rm -rf /var/cache/ringq/* 2>/dev/null');
            $this->fsCmd('reloadxml'); sleep(2); $this->fsCmd('reloadacl');
            $this->fsCmd('sofia profile internal rescan');
            $this->fsCmd('sofia profile external rescan');
            $ok('RingQ reloaded');

            $this->db->execute(
                "UPDATE wa_gateway_config SET gateway_status='active', updated_at=NOW() WHERE domain_uuid=?::uuid AND wa_number=?",
                [$domainUuid, $waNum]
            );
            $ok('Gateway active ✓ — Call +' . $waNum . ' from WhatsApp to test');

            return $this->jsonResponse(["status" => 200, "message" => "Configured", "data" => ['success' => true, 'steps' => $steps]]);

        } catch (Throwable $e) {
            error_log("configureAction error: " . $e->getMessage());
            $steps[] = ['s' => 'err', 'm' => $e->getMessage()];
            if ($domainUuid && $waNum) {
                try {
                    $this->db->execute(
                        "UPDATE wa_gateway_config SET gateway_status='failed', updated_at=NOW() WHERE domain_uuid=?::uuid AND wa_number=?",
                        [$domainUuid, $waNum]
                    );
                } catch (Throwable $ignore) {
                }
            }
            return $this->jsonResponse(["status" => 200, "message" => "Configure failed", "data" => ['success' => false, 'error' => $e->getMessage(), 'steps' => $steps]]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/wasip/reconfigure -- re-point existing config
    |--------------------------------------------------------------------------
    */

    public function reconfigureAction()
    {
        set_time_limit($this->requestTimeoutConfigure);
        $steps = [];
        $ok = function (string $m) use (&$steps) { $steps[] = ['s' => 'ok', 'm' => $m]; };

        try {

            $auth = $this->requireAuth();
            if (!$auth) {
                return $this->response;
            }

            $domainUuid = $auth['domain_uuid'];
            $body = $this->request->getJsonRawBody();

            $saved = $this->getGatewayConfig($domainUuid);
            if (!$saved) {
                return $this->jsonResponse(["status" => 400, "message" => "No existing configuration found — use Configure first", "data" => ""]);
            }

            $token   = $this->dec($saved['access_token_enc'] ?? '');
            $pid     = $saved['phone_number_id'] ?? '';
            $waNum   = $saved['wa_number'] ?? '';
            $pbxD    = trim((string) ($body->pbxDomain ?? ($saved['pbx_domain'] ?? '')));
            $pbxP    = (int) ($saved['pbx_sip_port'] ?? 5061);
            $extNum  = trim((string) ($body->extensionNumber ?? ($saved['extension_number'] ?? '')));
            $extUuid = trim((string) ($body->extensionUuid ?? ($saved['extension_uuid'] ?? '')));

            if (!$token)  return $this->jsonResponse(["status" => 400, "message" => "No saved access token — use Configure instead", "data" => ""]);
            if (!$pid)    return $this->jsonResponse(["status" => 400, "message" => "No phone number ID saved", "data" => ""]);
            if (!$extNum) return $this->jsonResponse(["status" => 400, "message" => "Select an extension", "data" => ""]);
            $ok("Reconfiguring for: $pbxD (was: " . ($saved['pbx_domain'] ?? 'unknown') . ')');

            $r = $this->metaCall('POST', "{$pid}/settings", ['calling' => ['status' => 'ENABLED', 'sip' => ['status' => 'ENABLED', 'servers' => [['hostname' => $pbxD, 'port' => $pbxP]]]]], $token);
            if (empty($r['success'])) throw new \RuntimeException('Update SIP hostname failed: ' . json_encode($r));
            $ok("Meta SIP server updated → $pbxD:$pbxP");

            $r = $this->metaCall('POST', "{$pid}/settings", ['calling' => ['srtp_key_exchange_protocol' => 'SDES']], $token);
            if (empty($r['success'])) throw new \RuntimeException('SDES set failed');
            $ok('SDES confirmed');

            $settings = $this->metaCall('GET', "{$pid}/settings?include_sip_credentials=true", null, $token);
            $sipPass = null;
            foreach ($settings['calling']['sip']['servers'] ?? [] as $s) {
                if (!empty($s['sip_user_password'])) { $sipPass = $s['sip_user_password']; break; }
            }
            if (!$sipPass) throw new \RuntimeException('Could not get SIP password');

            $this->db->execute(
                "UPDATE wa_gateway_config SET meta_sip_pass_enc=?, pbx_domain=?, extension_number=?, extension_uuid=?, updated_at=NOW()
                 WHERE domain_uuid=?::uuid AND wa_number=?",
                [$this->enc($sipPass), $pbxD, $extNum, $extUuid, $domainUuid, $waNum]
            );
            $ok('SIP password updated');

            $domName = $this->getDomainName($domainUuid);
            $gwName  = 'watrunk_' . $waNum;

            $this->db->execute(
                "UPDATE v_gateways SET password=?, from_domain=?, context=?, update_date=NOW() WHERE domain_uuid=?::uuid AND gateway=?",
                [$sipPass, $pbxD, $domName, $domainUuid, $gwName]
            );
            $ok('Gateway updated with new PBX domain');

            $dpUuid = $this->db->fetchOne("SELECT gen_random_uuid() AS u", \Phalcon\Db\Enum::FETCH_ASSOC)['u'];

            $old = $this->db->fetchAll(
                "SELECT dialplan_uuid, app_uuid FROM v_dialplans WHERE domain_uuid=?::uuid AND dialplan_context='public' AND (dialplan_number=? OR dialplan_number=?)",
                \Phalcon\Db\Enum::FETCH_ASSOC, [$domainUuid, $waNum, "+$waNum"]
            );
            foreach ($old as $oldRow) {
                if (!empty($oldRow['app_uuid'])) $this->db->execute("DELETE FROM q_dids WHERE did_uuid=?::uuid", [$oldRow['app_uuid']]);
                $this->db->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid=?::uuid", [$oldRow['dialplan_uuid']]);
                $this->db->execute("DELETE FROM v_dialplans WHERE dialplan_uuid=?::uuid", [$oldRow['dialplan_uuid']]);
            }
            if (!empty($saved['dialplan_uuid'])) {
                $this->db->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid=?::uuid", [$saved['dialplan_uuid']]);
                $this->db->execute("DELETE FROM v_dialplans WHERE dialplan_uuid=?::uuid", [$saved['dialplan_uuid']]);
            }

            $extNameRow = $this->db->fetchOne(
                "SELECT effective_caller_id_name FROM v_extensions WHERE extension_uuid=?::uuid LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC, [$extUuid ?: '00000000-0000-0000-0000-000000000000']
            );
            $extName = $extNameRow['effective_caller_id_name'] ?? $extNum;

            $didUuid = $this->db->fetchOne("SELECT gen_random_uuid() AS u", \Phalcon\Db\Enum::FETCH_ASSOC)['u'];
            $this->db->execute(
                "INSERT INTO q_dids (did_uuid, domain_uuid, did_number, type, name, extension, sip_trunk_name, insert_date)
                 VALUES (?::uuid,?::uuid,?,'3',?,?,?,NOW())",
                [$didUuid, $domainUuid, $waNum, $extName, $extNum, $gwName]
            );

            $dpXml = $this->buildDialplanXml($waNum, $domainUuid, $domName, $extNum, $dpUuid);

            $this->db->execute(
                "INSERT INTO v_dialplans (dialplan_uuid, app_uuid, domain_uuid, dialplan_context, dialplan_name, dialplan_number,
                    dialplan_destination, dialplan_continue, dialplan_enabled, dialplan_xml, dialplan_order, insert_date)
                 VALUES (?::uuid,?::uuid,?::uuid,'public',?,?,NULL,'false','true',?,0,NOW())",
                [$dpUuid, $extUuid ?: null, $domainUuid, $waNum, $waNum, $dpXml]
            );

            $this->db->execute(
                "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order, insert_date)
                 VALUES (gen_random_uuid(),?::uuid,?::uuid,'condition','destination_number',?,0,20,NOW())",
                [$domainUuid, $dpUuid, '^(' . $waNum . ')$']
            );

            $this->db->execute(
                "INSERT INTO v_dialplan_details (dialplan_detail_uuid, domain_uuid, dialplan_uuid, dialplan_detail_tag,
                    dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order, insert_date)
                 VALUES (gen_random_uuid(),?::uuid,?::uuid,'action','transfer',?,0,110,NOW())",
                [$domainUuid, $dpUuid, $extNum . ' XML ' . $domName]
            );

            $this->db->execute(
                "UPDATE wa_gateway_config SET dialplan_uuid=?::uuid, gateway_status='active', last_configured=NOW(), updated_at=NOW()
                 WHERE domain_uuid=?::uuid AND wa_number=?",
                [$dpUuid, $domainUuid, $waNum]
            );
            $ok("DID + dialplan rebuilt → $extNum @ $domName");

            exec('rm -rf /var/cache/ringq/* 2>/dev/null');
            $this->fsCmd('reloadxml'); sleep(2); $this->fsCmd('reloadacl');
            $this->fsCmd('sofia profile internal rescan');
            $ok('RingQ reloaded — reconfiguration complete');

            return $this->jsonResponse(["status" => 200, "message" => "Reconfigured", "data" => ['success' => true, 'steps' => $steps]]);

        } catch (Throwable $e) {
            error_log("reconfigureAction error: " . $e->getMessage());
            $steps[] = ['s' => 'err', 'm' => $e->getMessage()];
            return $this->jsonResponse(["status" => 200, "message" => "Reconfigure failed", "data" => ['success' => false, 'error' => $e->getMessage(), 'steps' => $steps]]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/wasip/deactivate -- tear down for this domain
    |--------------------------------------------------------------------------
    */

    public function deactivateAction()
    {
        set_time_limit(60);
        $steps = [];
        $ok   = function (string $m) use (&$steps) { $steps[] = ['s' => 'ok', 'm' => $m]; };
        $warn = function (string $m) use (&$steps) { $steps[] = ['s' => 'warn', 'm' => $m]; };

        try {

            $auth = $this->requireAuth();
            if (!$auth) {
                return $this->response;
            }

            $domainUuid = $auth['domain_uuid'];
            $saved = $this->getGatewayConfig($domainUuid);

            if (!$saved) {
                return $this->jsonResponse(["status" => 200, "message" => "Nothing to deactivate", "data" => ['success' => true, 'steps' => [['s' => 'ok', 'm' => 'Nothing to deactivate']]]]);
            }

            $token = $this->dec($saved['access_token_enc'] ?? '');
            $pid   = $saved['phone_number_id'] ?? '';
            $waNum = $saved['wa_number'] ?? '';
            $gwName = 'watrunk_' . $waNum;

            if ($token && $pid) {
                try {
                    $this->metaCall('POST', "{$pid}/settings", ['calling' => ['sip' => ['status' => 'DISABLED']]], $token);
                    $ok('SIP disabled on Meta — calls will stop');
                } catch (Throwable $e) {
                    $warn('Could not disable Meta SIP: ' . $e->getMessage());
                }
            }

            $this->db->execute("DELETE FROM v_gateways WHERE domain_uuid=?::uuid AND gateway=?", [$domainUuid, $gwName]);
            $ok('Gateway removed');

            $old = $this->db->fetchAll(
                "SELECT dialplan_uuid, app_uuid FROM v_dialplans WHERE domain_uuid=?::uuid AND dialplan_context='public' AND (dialplan_number=? OR dialplan_number=?)",
                \Phalcon\Db\Enum::FETCH_ASSOC, [$domainUuid, $waNum, "+$waNum"]
            );
            foreach ($old as $oldRow) {
                if (!empty($oldRow['app_uuid'])) $this->db->execute("DELETE FROM q_dids WHERE did_uuid=?::uuid", [$oldRow['app_uuid']]);
                $this->db->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid=?::uuid", [$oldRow['dialplan_uuid']]);
                $this->db->execute("DELETE FROM v_dialplans WHERE dialplan_uuid=?::uuid", [$oldRow['dialplan_uuid']]);
            }
            if (!empty($saved['dialplan_uuid'])) {
                $this->db->execute("DELETE FROM v_dialplan_details WHERE dialplan_uuid=?::uuid", [$saved['dialplan_uuid']]);
                $this->db->execute("DELETE FROM v_dialplans WHERE dialplan_uuid=?::uuid", [$saved['dialplan_uuid']]);
            }
            $ok('DID + dialplan removed');

            $this->db->execute("DELETE FROM v_access_control_nodes WHERE node_description='Meta WhatsApp SIP'");
            $ok('Meta IP ranges removed from providers ACL');

            $this->db->execute(
                "UPDATE wa_gateway_config SET gateway_status='deactivated', dialplan_uuid=NULL, meta_sip_pass_enc='', updated_at=NOW()
                 WHERE domain_uuid=?::uuid AND wa_number=?",
                [$domainUuid, $waNum]
            );
            $ok('Configuration marked as deactivated');

            exec('rm -rf /var/cache/ringq/* 2>/dev/null');
            $this->fsCmd('reloadxml'); sleep(2); $this->fsCmd('reloadacl');
            $ok('RingQ reloaded — WhatsApp gateway fully deactivated');

            return $this->jsonResponse(["status" => 200, "message" => "Deactivated", "data" => ['success' => true, 'steps' => $steps]]);

        } catch (Throwable $e) {
            error_log("deactivateAction error: " . $e->getMessage());
            $steps[] = ['s' => 'err', 'm' => $e->getMessage()];
            return $this->jsonResponse(["status" => 200, "message" => "Deactivate failed", "data" => ['success' => false, 'error' => $e->getMessage(), 'steps' => $steps]]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication -- session-only, no q_tokens lookup
    |--------------------------------------------------------------------------
    |
    | On failure this sets $this->response directly and returns null, so
    | every caller does:
    |
    |   $auth = $this->requireAuth();
    |   if (!$auth) {
    |       return $this->response;
    |   }
    |   $domainUuid = $auth['domain_uuid'];
    |
    */

    private function requireAuth(): ?array
    {
        $session = $this->session->get('auth');

        if (!$session || empty($session['subdomain'])) {
            $this->response
                ->setStatusCode(401, 'Unauthorized')
                ->setJsonContent([
                    'status'  => 'error',
                    'message' => 'Unauthorized'
                ]);
            return null;
        }

        $domainUuid = $session['domain_uuid'] ?? $this->resolveDomainUuid($session['subdomain']);

        if (!$domainUuid) {
            $this->response
                ->setStatusCode(401, 'Unauthorized')
                ->setJsonContent([
                    'status'  => 'error',
                    'message' => 'Unauthorized'
                ]);
            return null;
        }

        return [
            'domain_uuid' => $domainUuid,
            'subdomain'   => $session['subdomain'],
            'user_uuid'   => $session['user_uuid'] ?? null,
        ];
    }

    /** Fallback if the session doesn't already carry domain_uuid directly. */
    private function resolveDomainUuid(string $subdomain): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT domain_uuid FROM v_domains WHERE domain_name = ? LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$subdomain]
        );

        return $row['domain_uuid'] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | wa_gateway_config lookup (one row per domain_uuid)
    |--------------------------------------------------------------------------
    */

    private function getGatewayConfig(string $domainUuid): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM wa_gateway_config WHERE domain_uuid = ?::uuid LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domainUuid]
        );

        return $row ?: null;
    }

    private function getDomainName(string $domainUuid): string
    {
        $row = $this->db->fetchOne(
            "SELECT domain_name FROM v_domains WHERE domain_uuid = ?::uuid LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domainUuid]
        );

        return $row['domain_name'] ?? '';
    }

    /*
    |--------------------------------------------------------------------------
    | Encryption -- key comes from env, same pattern FaxController uses for
    | its other settings (getenv() in initialize(), no config-file parsing)
    |--------------------------------------------------------------------------
    */

    private function encKey(): string
    {
        $key = getenv('WASIP_ENC_KEY') ?: 'change-me-wasip-key';
        return hash('sha256', $key, true);
    }

    private function enc(string $v): string
    {
        if (!$v) return '';
        $iv = random_bytes(16);
        return base64_encode($iv . openssl_encrypt($v, 'AES-256-CBC', $this->encKey(), OPENSSL_RAW_DATA, $iv));
    }

    private function dec(string $v): string
    {
        if (!$v) return '';
        $raw = base64_decode($v);
        $d = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $this->encKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $d ?: '';
    }

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH CLI + Meta Graph API (unchanged behaviour)
    |--------------------------------------------------------------------------
    */

    private function fsCmd(string $cmd): string
    {
        exec('fs_cli -x ' . escapeshellarg($cmd) . ' 2>&1', $o);
        return implode("\n", $o);
    }

    private function metaCall(string $method, string $path, ?array $body, string $token): array
    {
        $ch = curl_init('https://graph.facebook.com/' . self::META_GRAPH_VERSION . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token", "Content-Type: application/json"],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $r = curl_exec($ch);
        $e = curl_error($ch);
        curl_close($ch);
        if ($e) throw new \RuntimeException("cURL: $e");
        $d = json_decode($r, true) ?? [];
        if (isset($d['error'])) throw new \RuntimeException('Meta API: ' . $d['error']['message']);
        return $d;
    }

    private function buildDialplanXml(string $waNum, string $domUuid, string $domName, string $extNum, string $dpUuid): string
    {
        return
            '<extension name="' . $waNum . '" continue="false" uuid="' . $dpUuid . '">' . "\n" .
            '  <condition field="${destination_number}" expression="^((\+)?' . $waNum . ')$">' . "\n" .
            '    <action application="set" data="effective_caller_id_number=${sip_from_user}"/>' . "\n" .
            '    <action application="set" data="origination_caller_id_number=${sip_from_user}"/>' . "\n" .
            '    <action application="set" data="effective_caller_id_name=${sip_from_display}"/>' . "\n" .
            '    <action application="set" data="origination_caller_id_name=${sip_from_display}"/>' . "\n" .
            '    <action application="export" data="call_direction=inbound" inline="true"/>' . "\n" .
            '    <action application="set" data="domain_uuid=' . $domUuid . '" inline="true"/>' . "\n" .
            '    <action application="set" data="domain_name=' . $domName . '" inline="true"/>' . "\n" .
            '    <action application="export" data="hold_music=local_stream://default" inline="true"/>' . "\n" .
            '    <action application="set" data="rtp_secure_media=optional"/>' . "\n" .
            '    <action application="set" data="sip_allow_reinvite=false"/>' . "\n" .
            '    <action application="set" data="rtp_timeout_sec=300"/>' . "\n" .
            '    <action application="set" data="effective_caller_id_name=WA: ${caller_id_number}"/>' . "\n" .
            '    <action application="set" data="call_source=whatsapp"/>' . "\n" .
            '    <action application="transfer" data="' . $extNum . ' XML ' . $domName . '"/>' . "\n" .
            '  </condition>' . "\n" .
            '</extension>';
    }

    /*
    |--------------------------------------------------------------------------
    | JSON response -- identical helper to FaxController::jsonResponse()
    |--------------------------------------------------------------------------
    */

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->response;

        $response->setStatusCode($statusCode);
        $response->setContentType('application/json', 'UTF-8');

        $response->setContent(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response;
    }
}