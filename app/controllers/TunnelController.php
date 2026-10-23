<?php
declare(strict_types=1);

use Phalcon\Mvc\Controller;
use Phalcon\Http\Response;

class TunnelController extends ControllerBase
{

    private function tunnelSessionMissing()
    {
        if ($this->request->isOptions()) {
            $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
            $this->response->setStatusCode(204, "No Content");
            return $this->response;
        }
        if ($this->session->get("auth")) {
            return null;
        }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $this->response->setStatusCode(401, "Unauthorized");
        return $this->response->setJsonContent(["status" => "error", "message" => "Unauthorized"]);
    }
    private function jsonResponse($data)
    {
        $response = new Response();
        return $response->setJsonContent($data);
    }

    /* ========================================
       1️⃣ CREATE TUNNEL
       POST /tunnel/create
    ======================================== */
   public function createAction()
{
    if ($refused = $this->tunnelSessionMissing()) {
        return $refused;
    }
    $this->view->disable();

    $data = $this->request->getJsonRawBody(true);

    if (!$data || empty($data['tunnel_name']) || empty($data['auth_key'])) {
        return $this->jsonResponse([
            "status"  => "error",
            "message" => "tunnel_name and auth_key required"
        ]);
    }

    $exists = $this->db->fetchOne(
        "SELECT id
         FROM tunnel_config
         WHERE tunnel_name = :name
           AND deleted_at IS NULL",
        \Phalcon\Db\Enum::FETCH_ASSOC,
        [
            "name" => trim($data['tunnel_name'])
        ]
    );

    if ($exists) {
        return $this->jsonResponse([
            "status"  => "error",
            "message" => "Tunnel already exists"
        ]);
    }

    $now = date("Y-m-d H:i:s");

    $this->db->execute(
        "INSERT INTO tunnel_config
        (
            tunnel_name,
            auth_key,
            status,
            router_phone,
            device_local_ip,
            offline_reason,
            created_at,
            updated_at,
            deleted_at
        )
        VALUES
        (
            :name,
            :auth,
            0,
            :router_phone,
            :lan_ip,
            :offline_reason,
            :created_at,
            :updated_at,
            NULL
        )",
        [
            "name"           => trim($data['tunnel_name']),
            "auth"           => trim($data['auth_key']),
            "router_phone"   => !empty($data['router_phone']) ? 'true' : 'false',
            // [snom-manualip] the router phone's LAN IP, typed on the Tunnels page
            "lan_ip"         => (isset($data['device_local_ip']) && filter_var(trim((string) $data['device_local_ip']), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && trim((string) $data['device_local_ip']) !== '0.0.0.0') ? trim((string) $data['device_local_ip']) : null,
            "offline_reason" => "waiting_for_heartbeat",
            "created_at"     => $now,
            "updated_at"     => $now
        ]
    );

    return $this->jsonResponse([
        "status"  => "success",
        "message" => "Tunnel created successfully"
    ]);
}
public function bindAction()
{
    $this->view->disable();

    $data = $this->request->getJsonRawBody(true);

    if (
        !$data ||
        empty($data['auth_key']) ||
        empty($data['device_id'])
    ) {
        return $this->jsonResponse([
            "status"  => false,
            "message" => "auth_key and device_id are required"
        ], 403);
    }

    $authKey  = trim($data['auth_key']);
    $deviceId = trim($data['device_id']);
    $publicIp = isset($data['device_public_ip']) ? trim($data['device_public_ip']) : null;
    $localIp  = isset($data['device_local_ip']) ? trim($data['device_local_ip']) : null;

    // Find tunnel by auth key
    $tunnel = $this->db->fetchOne(
        "SELECT id, tunnel_name, device_id
         FROM tunnel_config
         WHERE auth_key = :auth_key",
        \Phalcon\Db\Enum::FETCH_ASSOC,
        [
            "auth_key" => $authKey
        ]
    );

    if (!$tunnel) {
        return $this->jsonResponse([
            "status"  => false,
            "message" => "Invalid auth key"
        ], 403);
    }

    /**
     * First-time binding
     */
    if (
        !isset($tunnel['device_id']) ||
        $tunnel['device_id'] === null ||
        trim($tunnel['device_id']) === ''
    ) {

        $this->db->execute(
            "UPDATE tunnel_config
             SET device_id = :device_id,
                 device_public_ip = :device_public_ip,
                 device_local_ip = COALESCE(NULLIF(NULLIF(CAST(:device_local_ip AS varchar), ''), '0.0.0.0'), device_local_ip),
                 last_seen = NOW(),
                 updated_at = NOW()
             WHERE auth_key = :auth_key
               AND (device_id IS NULL OR device_id = '')",
            [
                "device_id"        => $deviceId,
                "device_public_ip" => $publicIp,
                "device_local_ip"  => $localIp,
                "auth_key"         => $authKey
            ]
        );

        if ($this->db->affectedRows() <= 0) {
            return $this->jsonResponse([
                "status"  => false,
                "message" => "Failed to bind device"
            ], 403);
        }

        return $this->jsonResponse([
            "status"  => true,
            "message" => "Device bound successfully",
            "data" => [
                "tunnel_id"   => $tunnel['id'],
                "tunnel_name" => $tunnel['tunnel_name'],
                "device_id"   => $deviceId
            ]
        ]);
    }

    /**
     * Same device reconnect
     */
    if ($tunnel['device_id'] === $deviceId) {

        $this->db->execute(
            "UPDATE tunnel_config
             SET device_public_ip = :device_public_ip,
                 device_local_ip = COALESCE(NULLIF(NULLIF(CAST(:device_local_ip AS varchar), ''), '0.0.0.0'), device_local_ip),
                 last_seen = NOW(),
                 updated_at = NOW()
             WHERE auth_key = :auth_key
               AND device_id = :device_id",
            [
                "device_public_ip" => $publicIp,
                "device_local_ip"  => $localIp,
                "auth_key"         => $authKey,
                "device_id"        => $deviceId
            ]
        );

        return $this->jsonResponse([
            "status"  => true,
            "message" => "Device authenticated successfully",
            "data" => [
                "tunnel_id"   => $tunnel['id'],
                "tunnel_name" => $tunnel['tunnel_name'],
                "device_id"   => $deviceId
            ]
        ]);
    }

    /**
     * Device mismatch
     */
    return $this->jsonResponse([
        "status"  => false,
        "message" => "This auth key is already bound to another device"
    ], 403);
}
// public function heartbeatAction()
// {
//     $this->view->disable();

//     $data = $this->request->getJsonRawBody(true);

//     if (
//         !$data ||
//         empty($data['auth_key']) ||
//         empty($data['device_id'])
//     ) {
//         return $this->jsonResponse([
//             "status"  => false,
//             "message" => "auth_key and device_id are required"
//         ], 403);
//     }

//     $authKey  = trim($data['auth_key']);
//     $deviceId = trim($data['device_id']);

//     $this->db->execute(
//         "UPDATE tunnel_config
//          SET last_seen = NOW(),
//              updated_at = NOW()
//          WHERE auth_key = :auth_key
//          AND device_id = :device_id",
//         [
//             "auth_key"  => $authKey,
//             "device_id" => $deviceId
//         ]
//     );

//     if ($this->db->affectedRows() === 0) {
//         return $this->jsonResponse([
//             "status"  => false,
//             "message" => "Invalid auth key or device id"
//         ], 403);
//     }

//     return $this->jsonResponse([
//         "status"  => true,
//         "message" => "success",
//         "data" => [
//             "last_seen" => date('Y-m-d H:i:s')
//         ]
//     ]);
// }
public function heartbeatAction()
{
    $this->view->disable();

    $data = $this->request->getJsonRawBody(true);

    if (
        !$data ||
        empty($data['auth_key']) ||
        empty($data['device_id'])
    ) {
        return $this->jsonResponse([
            "status"  => false,
            "message" => "auth_key and device_id are required"
        ], 403);
    }

    $authKey  = trim($data['auth_key']);
    $deviceId = trim($data['device_id']);

    $status           = isset($data['status']) ? (int)$data['status'] : 1;
    $localIp          = $data['local_ip'] ?? null;
    $publicIp         = $data['public_ip'] ?? null;
    if (!filter_var(trim((string) $publicIp), FILTER_VALIDATE_IP) || trim((string) $publicIp) === '0.0.0.0') {
        // Router phones send no public_ip; use the address the heartbeat came from.
        $fromIp   = (string) $this->request->getClientAddress(true);
        $fromIp   = trim(explode(',', $fromIp)[0]);
        $publicIp = filter_var($fromIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $fromIp : null;
    }
    $phonesRegistered = isset($data['phones_registered'])
        ? (int)$data['phones_registered']
        : 0;
    $rtpSessions      = isset($data['rtp_sessions'])
        ? (int)$data['rtp_sessions']
        : 0;
    $uptimeSeconds    = isset($data['uptime_seconds'])
        ? (int)$data['uptime_seconds']
        : 0;
    $version          = $data['version'] ?? null;

    $this->db->execute(
        "UPDATE tunnel_config
         SET
             status = :status,
             device_local_ip = COALESCE(NULLIF(NULLIF(CAST(:local_ip AS varchar), ''), '0.0.0.0'), device_local_ip),
             device_public_ip = COALESCE(CAST(:public_ip AS varchar), device_public_ip),
             phones_registered = :phones_registered,
             rtp_sessions = :rtp_sessions,
             uptime_seconds = :uptime_seconds,
             version = :version,
             offline_reason = NULL,
             last_seen = NOW(),
             updated_at = NOW()
         WHERE auth_key = :auth_key
           AND device_id = :device_id
           AND deleted_at IS NULL",
        [
            "status"            => $status,
            "local_ip"          => $localIp,
            "public_ip"         => $publicIp,
            "phones_registered" => $phonesRegistered,
            "rtp_sessions"      => $rtpSessions,
            "uptime_seconds"    => $uptimeSeconds,
            "version"           => $version,
            "auth_key"          => $authKey,
            "device_id"         => $deviceId
        ]
    );

    if ($this->db->affectedRows() === 0) {
        return $this->jsonResponse([
            "status"  => false,
            "message" => "Invalid auth key or device id"
        ], 403);
    }

    return $this->jsonResponse([
        "status"  => true,
        "message" => "success",
        "data" => [
            "last_seen" => date('Y-m-d H:i:s')
        ]
    ]);
}
    /* ========================================
       2️⃣ LIST TUNNELS
       POST /tunnel/list
    ======================================== */
  public function listAction()
{
    if ($refused = $this->tunnelSessionMissing()) {
        return $refused;
    }
    $this->view->disable();

    // Get tunnels from DB
    $rows = $this->db->fetchAll(
        "SELECT
            id,
            tunnel_name,
            auth_key,
            device_public_ip,
            device_id,
            device_local_ip,
            status,
            router_phone,
            offline_reason,
            last_seen,
            created_at,
            updated_at
         FROM tunnel_config
         WHERE deleted_at IS NULL
         ORDER BY id DESC",
        \Phalcon\Db\Enum::FETCH_ASSOC
    );

    $total   = count($rows);
    $online  = 0;
    $offline = 0;

    foreach ($rows as &$row) {

        // status = 1 → online
        // status = 0 → offline
        $row['online'] = ((int)$row['status'] === 1);
        $row['router_phone'] = ($row['router_phone'] === true || $row['router_phone'] === 't' || $row['router_phone'] === 1 || $row['router_phone'] === '1');

        if ($row['online']) {
            $online++;
        } else {
            $offline++;
        }
    }

    return $this->jsonResponse([
        "status"  => "success",
        "total"   => $total,
        "online"  => $online,
        "offline" => $offline,
        "data"    => $rows
    ]);
}


    /* ========================================
       3️⃣ VIEW SINGLE TUNNEL
       POST /tunnel/view
       body: { "id": 1 }
    ======================================== */
    public function viewAction()
    {
    if ($refused = $this->tunnelSessionMissing()) {
        return $refused;
    }
        $this->view->disable();
        $data = $this->request->getJsonRawBody(true);

        $row = $this->db->fetchOne(
            "SELECT * FROM tunnel_config WHERE id=:id",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["id"=>$data['id']]
        );

        if (!$row) {
            return $this->jsonResponse(["status"=>"error","message"=>"Tunnel not found"]);
        }
        // [snom-router-phone] {"phones": true} also lists the IP phones that use this tunnel (Tunnels page popup).
        if (!empty($data['phones'])) {
            $row['phones'] = $this->tunnelPhones($row);
        }

        return $this->jsonResponse(["status"=>"success","data"=>$row]);
    }

    /**
     * IP phones provisioned through this tunnel: router phones whose "Tunnel Router Phone" is this tunnel
     * (7th segment of "<ext>@<domain>-macAddresses") and phones whose Host URL is the tunnel's LAN IP.
     * registered / contact_ip come from RingQ Call Services when it can be asked, else null.
     */
    private function tunnelPhones(array $tunnel): array
    {
        $id = (string) $tunnel['id'];
        $lanIp = trim((string) ($tunnel['device_local_ip'] ?? ''));
        if (!filter_var($lanIp, FILTER_VALIDATE_IP) || $lanIp === '0.0.0.0') {
            $lanIp = '';
        }
        $phones = [];
        try {
            $keys = (array) $this->redis->keys('*-macAddresses');
        } catch (\Throwable $e) {
            error_log("tunnelPhones: " . $e->getMessage());
            return [];
        }
        sort($keys);
        foreach ($keys as $key) {
            if (!preg_match('/^(\d+)@(.+)-macAddresses$/', (string) $key, $m)) {
                continue;
            }
            foreach ((array) $this->redis->lrange($key, 0, -1) as $entry) {
                $parts = explode("@", (string) $entry);
                if (count($parts) < 3) {
                    continue;
                }
                $host = preg_replace('#^[a-z]+://#i', '', trim((string) ($parts[5] ?? '')));
                $host = preg_replace('#[:/].*$#', '', $host);
                $via = (isset($parts[6]) && trim($parts[6]) === $id) ? 'router' : (($lanIp !== '' && $host === $lanIp) ? 'host' : '');
                if ($via === '') {
                    continue;
                }
                $phones[] = [
                    "extension" => $m[1],
                    "domain" => $m[2],
                    "brand" => (string) $parts[0],
                    "model" => (string) ($parts[1] ?? ''),
                    "mac" => (string) $parts[2],
                    "via" => $via,
                ] + $this->phoneRegistration($m[1], $m[2], (string) $parts[2], (string) $parts[0]);
            }
        }
        return $phones;
    }

    /** ["registered" => bool|null, "contact_ip" => string|null] for one provisioned phone. */
    private function phoneRegistration(string $extension, string $domain, string $mac, string $brand): array
    {
        $unknown = ["registered" => null, "contact_ip" => null];
        if (!class_exists('PhoneAdmin') || !method_exists('PhoneAdmin', 'registrations')) {
            return $unknown;
        }
        try {
            $user = $this->db->fetchOne(
                "SELECT u.username
                   FROM v_extensions e
                   JOIN v_domains d ON d.domain_uuid = e.domain_uuid
                   JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid
                   JOIN v_users u ON u.user_uuid = eu.user_uuid
                  WHERE d.domain_name = :domain AND e.extension = :extension
                  LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["domain" => $domain, "extension" => $extension]
            );
            $regs = $user ? \PhoneAdmin::registrations((string) $user['username'], $domain, $mac, strtolower($brand)) : [];
            if (!$regs) {
                $regs = \PhoneAdmin::registrations($extension, $domain, $mac, strtolower($brand));
            }
        } catch (\Throwable $e) {
            error_log("phoneRegistration: " . $e->getMessage());
            return $unknown;
        }
        if (!$regs) {
            return ["registered" => false, "contact_ip" => null];
        }
        $ip = preg_match('/@\[?([0-9a-f.:]+?)\]?(?::\d+)?(?:[;>]|$)/i', (string) ($regs[0]['contact'] ?? ''), $c) ? $c[1] : null;
        return ["registered" => true, "contact_ip" => $ip];
    }

    /* ========================================
       4️⃣ UPDATE TUNNEL
       POST /tunnel/update
    ======================================== */
    public function updateAction()
    {
    if ($refused = $this->tunnelSessionMissing()) {
        return $refused;
    }
        $this->view->disable();
        $data = $this->request->getJsonRawBody(true);

        if (empty($data['id'])) {
            return $this->jsonResponse(["status"=>"error","message"=>"ID required"]);
        }

        $this->db->execute("
            UPDATE tunnel_config
            SET tunnel_name=:name,
                auth_key=:auth,
                router_phone=COALESCE(CAST(:router_phone AS boolean), router_phone),
                device_local_ip=COALESCE(CAST(:lan_ip AS varchar), device_local_ip),
                updated_at=:u
            WHERE id=:id
        ",[
            "name"=>$data['tunnel_name'],
            "auth"=>$data['auth_key'],
            "router_phone"=>array_key_exists('router_phone', $data) ? (!empty($data['router_phone']) ? 'true' : 'false') : null,
            // [snom-router-phone] the router phone's LAN IP, typed on the Tunnels page
            "lan_ip"=>(isset($data['device_local_ip']) && filter_var(trim((string) $data['device_local_ip']), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && trim((string) $data['device_local_ip']) !== '0.0.0.0') ? trim((string) $data['device_local_ip']) : null,
            "u"=>date("Y-m-d H:i:s"),
            "id"=>$data['id']
        ]);

        return $this->jsonResponse([
            "status"=>"success",
            "message"=>"Tunnel updated successfully"
        ]);
    }

    /* ========================================
       5️⃣ DELETE TUNNEL
       POST /tunnel/delete
       body: { "id": 1 }
    ======================================== */
    public function deleteAction()
    {
    if ($refused = $this->tunnelSessionMissing()) {
        return $refused;
    }
        $this->view->disable();
        $data = $this->request->getJsonRawBody(true);

        $this->db->execute(
            "DELETE FROM tunnel_config WHERE id=:id",
            ["id"=>$data['id']]
        );

        return $this->jsonResponse([
            "status"=>"success",
            "message"=>"Tunnel deleted successfully"
        ]);
    }

    /* ========================================
       6️⃣ STATUS TOGGLE
       POST /tunnel/status
       body: { "id": 1 }
    ======================================== */
   
     private function generateAuthKey(int $bytes =16): string
    {
        return rtrim(
            strtr(
                base64_encode(random_bytes($bytes)),
                '+/',
                '-_'
            ),
            '='
        );
    }

    /**
     * Generate Unique Auth Key
     */
    private function createUniqueAuthKey(): string
    {
        do {

            $authKey = $this->generateAuthKey();

            $exists = $this->db->fetchOne(
                "SELECT id FROM tunnel_config WHERE auth_key = :auth_key",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [
                    "auth_key" => $authKey
                ]
            );

        } while ($exists);

        return $authKey;
    }

    /* ========================================
       GENERATE AUTH KEY API
       POST /tunnel/generateAuthKey
    ======================================== */
    public function generateAuthKeyAction()
    {
    if ($refused = $this->tunnelSessionMissing()) {
        return $refused;
    }
        $this->view->disable();

        try {

            $authKey = $this->createUniqueAuthKey();

            return $this->jsonResponse([
                "status"    => "success",
                "auth_key"  => $authKey
            ]);

        } catch (\Exception $e) {

            return $this->jsonResponse([
                "status"  => "error",
                "message" => ControllerBase::safeErrorMessage($e)
            ]);
        }
    }
    
   public function statusAction()
{
    $this->view->disable();

    

    try {

        $data = $this->request->getJsonRawBody(true);

        if (
            empty($data['auth_key']) ||
            empty($data['device_id']) ||
            !isset($data['status'])
        ) {
            return $this->response->setJsonContent([
                'status' => false,
                'message' => 'auth_key, device_id and status are required'
            ]);
        }

        $success = $this->db->execute(
            "UPDATE tunnel_config
             SET status = ?,device_local_ip = COALESCE(NULLIF(NULLIF(CAST(? AS varchar), ''), '0.0.0.0'), device_local_ip)
             WHERE auth_key = ? AND device_id = ?",
            [
                (int)$data['status'],
                $data['local_ip'] ?? '',
                $data['auth_key'],
                $data['device_id']
            ]
        );

        if (!$success) {
            return $this->response->setJsonContent([
                'status' => false,
                'message' => 'Failed to update status'
            ]);
        }

        return $this->response->setJsonContent([
            'status' => true,
            'message' => 'Status updated successfully'
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'status' => false,
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}
}