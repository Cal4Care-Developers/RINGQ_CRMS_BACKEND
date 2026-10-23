<?php
/**
 * [sraps] Snom SRAPS zero-touch redirection.
 * Opt-in per phone: the "Redirect" checkbox on a Snom row of the extension's Auto Provisioning
 * table (off by default). The first ticked phone gives the tenant its own SRAPS child company under
 * RingQ's reseller company, with one provisioning profile (Setting Server =
 * https://<tenant>:8443/provision/<key>); ticked phones are put there, so a factory-new phone finds
 * RingQ on first boot. Unticking, or removing the MAC from the extension, deletes it from SRAPS,
 * but only when SRAPS still has it in this tenant's profile.
 * State per tenant: redis hash rps:<tenant> (company, profile, last_error), ticked MACs in
 * rps-on:<tenant>, per MAC results in sraps:status:<tenant>.
 * Credentials: /etc/ringq/sraps.env (SRAPS_KEY_ID, SRAPS_SECRET, SRAPS_COMPANY_ID optional,
 * SRAPS_PRODUCT_GROUP optional). [rps-proxy] Without that file, the server asks the RingQ Remote
 * Provisioning service (PROXY_URL) to make the SRAPS calls for it, so the SRAPS key never has to
 * be copied to customer servers. The service only acts for an allowed tenant whose DNS points to
 * the calling server.
 */
class Sraps
{
    const BASE = 'https://api.sraps.snom.com/api/v1/';
    const ENV_FILE = '/etc/ringq/sraps.env';
    // SRAPS setting "setting_server" (D-Series / M-Series redirection target)
    const SETTING_SERVER = '50a400560eec4dc0b96b026094e06562';
    // product group the existing RingQ profiles use (D-Series)
    const DEFAULT_PRODUCT_GROUP = '1a45a2a3f6b94c4cbfa5439b446132f7';
    // [rps-proxy] RingQ Remote Provisioning service: holds the SRAPS key and makes the SRAPS calls
    // for servers without it (SRAPS_PROXY_URL in sraps.env overrides).
    const PROXY_URL = 'https://rps.ringq.com/rps';
    const PROXY_MAX_MACS = 50;

    private static $env = null;
    /** test hook: fn($method, $url, $body) => [code, json, raw, headers] */
    public static $transport = null;

    public static function env(): array
    {
        if (self::$env !== null) return self::$env;
        if (self::$transport) return self::$env = ['SRAPS_KEY_ID' => 'test', 'SRAPS_SECRET' => 'test'];
        $env = [];
        if (is_readable(self::ENV_FILE)) {
            foreach (file(self::ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                if (preg_match('/^\s*([A-Z_]+)\s*=\s*"?([^"]*)"?\s*$/', $l, $m)) $env[$m[1]] = trim($m[2]);
            }
        }
        return self::$env = $env;
    }

    /** This server has the SRAPS key itself (/etc/ringq/sraps.env). */
    public static function hasKey(): bool
    {
        $e = self::env();
        return !empty($e['SRAPS_KEY_ID']) && !empty($e['SRAPS_SECRET']) && function_exists('curl_init');
    }

    /**
     * RPS can be used: with the key on this server, or through the RPS service. With $tenant,
     * proxy mode also asks the service whether it will act for that tenant (cached).
     */
    public static function enabled(string $tenant = ''): bool
    {
        if (self::hasKey()) return true;
        if (self::$transport || !function_exists('curl_init')) return false;
        return $tenant === '' || self::proxyAvailable($tenant);
    }

    /** [rps-proxy] Shared redis connection for calls that are not handed one. */
    private static function redis()
    {
        try {
            $di = \Phalcon\Di::getDefault();
            return $di && $di->has('redis') ? $di->get('redis') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** [rps-proxy] POST to the RPS service. Returns [httpCode, decodedJson]. */
    private static function proxyCall(array $body): array
    {
        $env = self::env();
        $c = curl_init($env['SRAPS_PROXY_URL'] ?? self::PROXY_URL);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'User-Agent: RingQ-SRAPS/1']]);
        $r = curl_exec($c);
        $code = $r === false ? 0 : curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        return [$code, $r === false ? null : json_decode($r, true)];
    }

    /** [rps-proxy] The RPS service will act for $tenant (ok cached 10 min, refusals 2 min). */
    public static function proxyAvailable(string $tenant, $redis = null): bool
    {
        $redis = $redis ?: self::redis();
        $ck = "sraps:proxy:$tenant";
        try {
            if ($redis && ($v = $redis->get($ck)) !== null && $v !== false) return $v === '1';
        } catch (\Throwable $e) {}
        [$code, $j] = self::proxyCall(['op' => 'status', 'tenant' => $tenant]);
        $ok = $code == 200 && ($j['result'] ?? '') === 'success' && !empty($j['available']);
        if (!$ok) error_log("[sraps] RPS service refused $tenant: HTTP $code " . substr((string) ($j['message'] ?? ''), 0, 200));
        try { if ($redis) $redis->setex($ck, $ok ? 600 : 120, $ok ? '1' : '0'); } catch (\Throwable $e) {}
        return $ok;
    }

    /** [rps-proxy] Same as syncDirect, done by the RPS service. */
    private static function proxySync(string $tenant, string $provKey, array $added, array $removed, $redis): array
    {
        $out = ['company' => '', 'profile' => '', 'results' => []];
        $added = array_values($added);
        $removed = array_values($removed);
        while ($added || $removed) {
            $a = array_splice($added, 0, self::PROXY_MAX_MACS);
            $r = array_splice($removed, 0, self::PROXY_MAX_MACS - count($a));
            [$code, $j] = self::proxyCall(['op' => 'sync', 'tenant' => $tenant,
                'provKey' => $provKey, 'added' => $a, 'removed' => $r]);
            if ($code != 200 || ($j['result'] ?? '') !== 'success') {
                $msg = is_array($j) && !empty($j['message']) ? (string) $j['message'] : "RPS service HTTP $code";
                if ($redis && $code == 403) { try { $redis->setex("sraps:proxy:$tenant", 120, '0'); } catch (\Throwable $e) {} }
                throw new \RuntimeException('RingQ Remote Provisioning: ' . substr($msg, 0, 200));
            }
            $d = (array) ($j['data'] ?? []);
            $out['company'] = (string) ($d['company'] ?? $out['company']);
            $out['profile'] = (string) ($d['profile'] ?? $out['profile']);
            foreach ((array) ($d['results'] ?? []) as $mac => $res) $out['results'][(string) $mac] = (array) $res;
        }
        return $out;
    }

    /** Hawk-signed request. Returns [httpCode, decodedJson, rawBody, headers]. */
    public static function call(string $method, string $url, $body = null): array
    {
        if (self::$transport) return (self::$transport)($method, $url, $body);
        $e = self::env();
        $p = parse_url($url);
        $res = $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
        $port = $p['port'] ?? 443;
        $ts = time();
        $nonce = substr(bin2hex(random_bytes(6)), 0, 8);
        $json = $body === null ? null : json_encode($body);
        $hash = '';
        $h = ['Accept: application/json', 'User-Agent: RingQ-SRAPS/1'];
        if ($json !== null) {
            $hash = base64_encode(hash('sha256', "hawk.1.payload\napplication/json\n" . $json . "\n", true));
            $h[] = 'Content-Type: application/json';
        }
        $norm = "hawk.1.header\n$ts\n$nonce\n" . strtoupper($method) . "\n$res\n" . strtolower($p['host']) . "\n$port\n$hash\n\n";
        $mac = base64_encode(hash_hmac('sha256', $norm, $e['SRAPS_SECRET'], true));
        $h[] = 'Authorization: Hawk id="' . $e['SRAPS_KEY_ID'] . '", ts="' . $ts . '", nonce="' . $nonce . '"'
            . ($hash !== '' ? ', hash="' . $hash . '"' : '') . ', mac="' . $mac . '"';
        $c = curl_init($url);
        $opt = [CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $h, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15];
        if ($json !== null) $opt[CURLOPT_POSTFIELDS] = $json;
        curl_setopt_array($c, $opt);
        $r = curl_exec($c);
        if ($r === false) {
            $err = curl_error($c);
            curl_close($c);
            return [0, null, $err, []];
        }
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE);
        curl_close($c);
        $hdr = [];
        foreach (explode("\r\n", substr($r, 0, $hs)) as $l) {
            if (strpos($l, ':')) { [$k, $v] = explode(':', $l, 2); $hdr[strtolower(trim($k))] = trim($v); }
        }
        $raw = substr($r, $hs);
        return [$code, json_decode($raw, true), $raw, $hdr];
    }

    public static function companyId($redis = null): string
    {
        $e = self::env();
        if (!empty($e['SRAPS_COMPANY_ID'])) return $e['SRAPS_COMPANY_ID'];
        $ck = 'sraps:company:' . $e['SRAPS_KEY_ID'];
        if ($redis && ($v = $redis->get($ck))) return (string) $v;
        [$code, $j] = self::call('GET', self::BASE . 'tokens/' . $e['SRAPS_KEY_ID']);
        $cid = ($code == 200 && !empty($j['links']['company'])) ? basename(rtrim($j['links']['company'], '/')) : '';
        if ($cid !== '' && $redis) $redis->set($ck, $cid);
        return $cid;
    }

    /** This server's profile for $serverUrl; created when missing. Returns its uuid or ''. */
    public static function profileFor(string $cid, string $serverUrl, string $name, $redis = null): string
    {
        $ck = 'sraps:profile:' . $cid . ':' . $serverUrl;
        if ($redis && ($v = $redis->get($ck))) return (string) $v;
        [$code, $list] = self::call('GET', self::BASE . "companies/$cid/provisioning-profiles/?page_size=1000");
        if ($code != 200) { error_log("[sraps] list profiles HTTP $code"); return ''; }
        $uuid = '';
        foreach ((array) $list as $p) {
            $v = $p['settings'][self::SETTING_SERVER]['value'] ?? '';
            if (is_string($v) && rtrim($v, '/') === rtrim($serverUrl, '/')) { $uuid = (string) $p['uuid']; break; }
        }
        if ($uuid === '') {
            $e = self::env();
            [$code, $j, $raw] = self::call('POST', self::BASE . "companies/$cid/provisioning-profiles/", [
                'name' => substr($name, 0, 60),
                'autoprovisioning_enabled' => false,
                'dm_enabled' => false,
                'product_group' => $e['SRAPS_PRODUCT_GROUP'] ?? self::DEFAULT_PRODUCT_GROUP,
                'settings' => [self::SETTING_SERVER => ['value' => $serverUrl]],
            ]);
            if (($code == 200 || $code == 201) && !empty($j['uuid'])) {
                $uuid = (string) $j['uuid'];
                error_log("[sraps] created profile $name -> $serverUrl ($uuid)");
            } else {
                error_log("[sraps] create profile HTTP $code " . substr((string) $raw, 0, 300));
                return '';
            }
        }
        if ($redis) $redis->setex($ck, 86400, $uuid);
        return $uuid;
    }

    private static function endpointProfile($j): string
    {
        if (!empty($j['provisioning_profile'])) return (string) $j['provisioning_profile'];
        $l = $j['links']['provisioning-profile'] ?? '';
        return $l ? basename(rtrim((string) $l, '/')) : '';
    }

    /** Snom MACs (12 lowercase hex) in an extension's "<brand>@<model>@<mac>@..." device list. */
    public static function snomMacs(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $parts = explode('@', (string) $entry);
            if (count($parts) < 3 || stripos($parts[0], 'snom') === false) continue;
            $m = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $parts[2]));
            if (strlen($m) === 12) $out[$m] = true;
        }
        return array_keys($out);
    }

    /** uuid of a company object from SRAPS (field names vary between list and detail responses). */
    private static function uuidOf($c): string
    {
        foreach (['uuid', 'id'] as $f) if (!empty($c[$f]) && is_string($c[$f])) return $c[$f];
        if (!empty($c['links']['self'])) return basename(rtrim((string) $c['links']['self'], '/'));
        return '';
    }

    public static function tenantState($redis, string $tenant): array
    {
        try { $h = $redis ? (array) $redis->hgetall("rps:$tenant") : []; } catch (\Throwable $e) { $h = []; }
        return $h;
    }

    /** The tenant's SRAPS child company (found by name, created when missing). */
    public static function childCompany(string $parent, string $tenant, $redis = null): string
    {
        $st = self::tenantState($redis, $tenant);
        if (!empty($st['company'])) return (string) $st['company'];
        [$code, $list, $raw] = self::call('GET', self::BASE . "companies/$parent/child-companies/?page_size=1000");
        if ($code != 200) throw new \RuntimeException("SRAPS child companies HTTP $code " . substr((string) $raw, 0, 200));
        $uuid = '';
        foreach ((array) $list as $c) if (($c['name'] ?? '') === $tenant) { $uuid = self::uuidOf($c); break; }
        if ($uuid === '') {
            [$pc, $pj] = self::call('GET', self::BASE . "companies/$parent");
            $pj = $pc == 200 ? (array) $pj : [];
            [$code, $j, $raw] = self::call('POST', self::BASE . "companies/$parent/child-companies/", [
                'name' => $tenant,
                'street' => (string) ($pj['street'] ?? '-'),
                'postal_code' => (string) ($pj['postal_code'] ?? '-'),
                'city' => (string) ($pj['city'] ?? '-'),
                'country' => (string) ($pj['country'] ?? 'SG'),
                'company_type' => 'end_user',
            ]);
            if (!in_array($code, [200, 201])) throw new \RuntimeException("SRAPS create company HTTP $code " . substr((string) $raw, 0, 200));
            $j = isset($j[0]) ? $j[0] : $j;
            $uuid = self::uuidOf((array) $j);
            if ($uuid === '') {
                [, $list] = self::call('GET', self::BASE . "companies/$parent/child-companies/?page_size=1000");
                foreach ((array) $list as $c) if (($c['name'] ?? '') === $tenant) { $uuid = self::uuidOf($c); break; }
            }
            if ($uuid === '') throw new \RuntimeException('SRAPS created the company but gave no id: ' . substr((string) $raw, 0, 200));
            error_log("[sraps] created child company $tenant ($uuid)");
        }
        [$code, $j] = self::call('GET', self::BASE . "companies/$uuid");
        if ($code != 200 || ($j['name'] ?? '') !== $tenant) throw new \RuntimeException("SRAPS company $uuid check failed (HTTP $code)");
        if ($redis) $redis->hset("rps:$tenant", 'company', $uuid);
        return $uuid;
    }

    /** Every Snom MAC saved on the tenant's extensions. */
    public static function tenantSnomMacs($redis, string $tenant): array
    {
        $macs = [];
        foreach ((array) $redis->keys("*@$tenant-macAddresses") as $k) {
            foreach (self::snomMacs((array) $redis->lrange($k, 0, -1)) as $m) $macs[$m] = true;
        }
        return array_keys($macs);
    }

    /** The phone's "Redirect" checkbox is ticked. */
    public static function phoneOn($redis, string $tenant, string $mac): bool
    {
        try { return (bool) $redis->hexists("rps-on:$tenant", $mac); } catch (\Throwable $e) { return false; }
    }

    /** Snom MACs that should be in SRAPS: saved and ticked. */
    public static function wanted($redis, string $tenant, array $macs): array
    {
        return array_values(array_filter($macs, function ($m) use ($redis, $tenant) { return self::phoneOn($redis, $tenant, $m); }));
    }

    /** One phone's "Redirect" checkbox: on = put it in SRAPS, off = take it out. */
    public static function setPhone($redis, string $tenant, string $provKey, string $mac, bool $on): array
    {
        if (!self::enabled($tenant)) throw new \RuntimeException('RingQ Remote Provisioning is not set up on this server.');
        if ($on) {
            $redis->hset("rps-on:$tenant", $mac, '1');
            try {
                return self::sync($tenant, $provKey, [$mac], [], $redis);
            } catch (\Throwable $e) {
                $redis->hdel("rps-on:$tenant", $mac);
                throw $e;
            }
        }
        $res = self::sync($tenant, $provKey, [], [$mac], $redis);
        $redis->hdel("rps-on:$tenant", $mac);
        $redis->hdel("sraps:status:$tenant", $mac);
        return $res;
    }

    /** Add $added to the tenant's profile, delete $removed (only if still in it). Records results in redis. */
    public static function sync(string $tenant, string $provKey, array $added, array $removed, $redis = null): array
    {
        $result = [];
        if ((!$added && !$removed) || $tenant === '' || $provKey === '') return $result;
        try {
            $out = self::hasKey()
                ? self::syncDirect($tenant, $provKey, $added, $removed, $redis)
                : self::proxySync($tenant, $provKey, $added, $removed, $redis);
            if ($redis && $out['company'] !== '') $redis->hset("rps:$tenant", 'company', $out['company']);
            if ($redis && $out['profile'] !== '') $redis->hset("rps:$tenant", 'profile', $out['profile']);
        } catch (\Throwable $e) {
            error_log('[sraps] ' . $e->getMessage());
            if ($redis) $redis->hset("rps:$tenant", 'last_error', $e->getMessage());
            throw $e;
        }
        $result = $out['results'];
        foreach ($result as $mac => $r) {
            $r['at'] = gmdate('c');
            error_log("[sraps] $tenant $mac " . json_encode($r));
            if ($redis) {
                try {
                    if (($r['action'] ?? '') === 'removed' || ($r['action'] ?? '') === 'not-in-sraps') $redis->hdel("sraps:status:$tenant", $mac);
                    else $redis->hset("sraps:status:$tenant", $mac, json_encode($r));
                } catch (\Throwable $e) {}
            }
        }
        if ($redis) $redis->hdel("rps:$tenant", 'last_error');
        return $result;
    }

    /** SRAPS calls with this server's own key. Returns ['company', 'profile', 'results']. */
    private static function syncDirect(string $tenant, string $provKey, array $added, array $removed, $redis): array
    {
        $result = [];
        $parent = self::companyId($redis);
        if ($parent === '') throw new \RuntimeException('SRAPS key not accepted (company unknown)');
        $cid = self::childCompany($parent, $tenant, $redis);
        $profile = self::profileFor($cid, "https://$tenant:8443/provision/$provKey", $tenant, $redis);
        if ($profile === '') throw new \RuntimeException('SRAPS profile could not be found or created');
        foreach ($added as $mac) {
            $url = self::BASE . "companies/$cid/endpoints/$mac";
            [$code, $j] = self::call('GET', $url);
            if ($code != 200) {
                // In SRAPS under another RingQ company (or RingQ Ltd itself)? Move it here first.
                [$pc, $pj] = self::call('GET', self::BASE . "companies/$parent/endpoints/$mac?include_subsidiary_devices=all");
                if ($pc == 200 && self::endpointProfile($pj) !== '' && self::endpointProfile($pj) !== $profile) {
                    // Redirected to another RingQ server: never take it over silently.
                    $result[$mac] = ['ok' => false, 'action' => 'other-server', 'msg' => 'Already redirected to another RingQ server. Turn it off there first.'];
                    continue;
                }
                if ($pc == 200) {
                    [$mc, $mj, $mraw] = self::call('POST', self::BASE . "companies/$parent/endpoints/move", ['mac_addresses' => [$mac], 'dest_company' => $cid]);
                    $okMove = $mc == 200 && !empty($mj['results'][0]['success']);
                    if (!$okMove) { $result[$mac] = ['ok' => false, 'action' => 'move', 'http' => $mc, 'msg' => substr((string) $mraw, 0, 200)]; continue; }
                    [$code, $j] = self::call('GET', $url);
                }
            }
            if ($code == 200) {
                $was = self::endpointProfile($j);
                if ($was === $profile) { $result[$mac] = ['ok' => true, 'action' => 'already']; continue; }
                [$c2, , $raw] = self::call('PATCH', $url, ['provisioning_profile' => $profile]);
                $result[$mac] = ['ok' => $c2 == 200, 'action' => 'assigned', 'http' => $c2];
                if ($c2 != 200) $result[$mac]['msg'] = substr((string) $raw, 0, 200);
            } else {
                [$c2, $j2, $raw] = self::call('PUT', $url, ['mac' => $mac, 'autoprovisioning_enabled' => false, 'provisioning_profile' => $profile]);
                $result[$mac] = ['ok' => in_array($c2, [200, 201]), 'action' => 'added', 'http' => $c2];
                if (!in_array($c2, [200, 201])) {
                    // Not visible to RingQ Ltd or any RingQ instance, and SRAPS refuses it: another PBX or
                    // operator already has this phone in SRAPS (SRAPS keeps each MAC in one company only).
                    $detail = is_array($j2) ? json_encode($j2['details'] ?? $j2) : (string) $raw;
                    $result[$mac]['action'] = ($c2 >= 400 && $c2 < 500 && $c2 != 401 && $c2 != 429) ? 'other-operator' : 'added';
                    $result[$mac]['msg'] = $result[$mac]['action'] === 'other-operator'
                        ? 'Already provisioned with another PBX or operator in Snom redirection. Ask them (or Snom) to release this MAC.'
                        : substr($detail, 0, 200);
                    $result[$mac]['sraps'] = substr($detail, 0, 200);
                }
            }
        }
        foreach ($removed as $mac) {
            $url = self::BASE . "companies/$cid/endpoints/$mac";
            [$code, $j] = self::call('GET', $url);
            if ($code == 404) { $result[$mac] = ['ok' => true, 'action' => 'not-in-sraps']; continue; }
            if ($code != 200) { $result[$mac] = ['ok' => false, 'action' => 'remove', 'http' => $code]; continue; }
            if (self::endpointProfile($j) !== $profile) { $result[$mac] = ['ok' => true, 'action' => 'kept-other-profile']; continue; }
            [$c2] = self::call('DELETE', $url);
            $result[$mac] = ['ok' => $c2 == 204 || $c2 == 200, 'action' => 'removed', 'http' => $c2];
        }
        return ['company' => $cid, 'profile' => $profile, 'results' => $result];
    }
}
