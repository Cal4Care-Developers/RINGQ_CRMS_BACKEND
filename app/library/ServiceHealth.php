<?php

/**
 * Service health, read once and shared.
 *
 * Extracted from AccessController so the Emergency Support screen and the
 * support MCP tools answer from one implementation. Two copies of this is how
 * two screens come to disagree about whether a box is healthy.
 *
 * READ ONLY. Every command below is a literal; no caller value ever reaches a
 * shell, and nothing here starts, stops or writes anything.
 *
 * A value that cannot be read is reported absent with its reason. Reporting a
 * plausible default during an incident is worse than admitting ignorance.
 */
class ServiceHealth
{
    /** Where pm2 may live. nvm installs it outside sudo's secure_path. */
    const PM2_CANDIDATES = [
        '/usr/bin/pm2',
        '/bin/pm2',
        '/usr/local/bin/pm2',
        '/usr/lib/node_modules/pm2/bin/pm2',
        '/root/.nvm/versions/node/v14.21.3/bin/pm2',
        '/root/.nvm/versions/node/v24.12.0/bin/pm2',
    ];

    /** The declared node app list. The same file pm2 itself is driven from. */
    const ECOSYSTEM_CONFIG = '/var/www/ringq/ecosystem.config.js';

    /** systemd units this product depends on. Literal names only. */
    const UNITS = ['freeswitch', 'nginx', 'redis-server', 'postgresql', 'supervisor'];

    public static function serverVersion($redis)
    {
        try {
            $version = $redis->get("serverVersion");
        } catch (\Throwable $e) {
            error_log("ServiceHealth::serverVersion: " . $e->getMessage());
            return null;
        }
        if (!is_string($version)) {
            return null;
        }
        $version = trim($version);
        return $version === "" ? null : $version;
    }

    public static function hostname()
    {
        $host = gethostname();
        if (!is_string($host)) {
            return null;
        }
        $host = trim($host);
        return $host === "" ? null : $host;
    }

    /**
     * Every declared node service and whether pm2 is running it.
     *
     * THE FAILURE THAT MATTERS IS AN APP PM2 HAS NEVER HEARD OF. `pm2 jlist`
     * alone cannot show it: a service dropped from pm2 (which jobs.sh's
     * reconciliation loop has done before) is simply absent from the list, so
     * reading pm2 by itself reports a shorter healthy list rather than a
     * failure. The declared list therefore comes from ecosystem.config.js —
     * the same file pm2 is driven from — and anything expected but missing is
     * reported `absent`, which is a louder state than `stopped`.
     *
     * @return array{services: array, error: ?string}
     */
    public static function nodeServices()
    {
        $expected = self::declaredNodeApps();
        $running  = self::pm2Processes();

        if ($running["error"] !== null) {
            $services = [];
            foreach ($expected as $name) {
                $services[] = [
                    "name"     => $name,
                    "state"    => "unknown",
                    "declared" => true,
                    "pid"       => null,
                    "restarts"  => null,
                    "uptimeMs"  => null,
                    "startedAt" => null,
                ];
            }
            return ["services" => $services, "error" => $running["error"]];
        }

        $byName   = $running["byName"];
        $services = [];

        foreach ($expected as $name) {
            if (!array_key_exists($name, $byName)) {
                $services[] = [
                    "name"     => $name,
                    "state"    => "absent",
                    "declared" => true,
                    "pid"       => null,
                    "restarts"  => null,
                    "uptimeMs"  => null,
                    "startedAt" => null,
                ];
                continue;
            }
            $services[] = $byName[$name] + ["declared" => true];
            unset($byName[$name]);
        }

        foreach ($byName as $name => $process) {
            $services[] = $process + ["declared" => false];
        }

        return ["services" => $services, "error" => null];
    }

    /**
     * App names declared in ecosystem.config.js.
     *
     * Comment lines are stripped BEFORE the names are matched, mirroring
     * app/scripts/jobs.sh:157. Without that a commented-out app reads as
     * declared and is then reported permanently `absent`.
     *
     * @return string[]
     */
    public static function declaredNodeApps()
    {
        if (!is_readable(self::ECOSYSTEM_CONFIG)) {
            return [];
        }
        $source = file_get_contents(self::ECOSYSTEM_CONFIG);
        if (!is_string($source)) {
            return [];
        }
        $lines = [];
        foreach (preg_split('/\R/', $source) as $line) {
            if (preg_match('/^\s*\/\//', $line)) {
                continue;
            }
            $lines[] = $line;
        }
        preg_match_all('/name:\s*[\'"]([^\'"]+)[\'"]/', implode("\n", $lines), $matches);
        $names = [];
        foreach ($matches[1] as $name) {
            $name = trim($name);
            if ($name !== "" && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * pm2's own view, keyed by app name.
     *
     * pm2 is root's, php-fpm is www-data's, and pm2 needs a HOME it can read
     * its dump from — hence `sudo -n -H`. `-n` so a missing sudoers entry
     * fails immediately instead of blocking the request on a password prompt.
     *
     * @return array{byName: array, error: ?string}
     */
    public static function pm2Processes()
    {
        $visible = [];
        $hidden  = [];
        foreach (self::PM2_CANDIDATES as $candidate) {
            if (is_link($candidate) || is_file($candidate)) {
                $visible[] = $candidate;
            } else {
                $hidden[] = $candidate;
            }
        }

        $sawOutput = false;
        foreach (array_merge($visible, $hidden) as $binary) {
            $raw = @shell_exec('sudo -n -H ' . escapeshellarg($binary) . ' jlist 2>/dev/null');
            if (!is_string($raw) || trim($raw) === "") {
                continue;
            }
            $sawOutput = true;
            $decoded = self::decodePm2List($raw);
            if ($decoded !== null) {
                return ["byName" => self::pm2ByName($decoded, time()), "error" => null];
            }
        }

        if ($sawOutput) {
            return ["byName" => [], "error" => "pm2 returned something this build could not parse"];
        }
        if (count($visible) === 0) {
            return ["byName" => [], "error" => "pm2 was not found on this server"];
        }
        return ["byName" => [], "error" => "pm2 could not be read by the web process"];
    }

    public static function decodePm2List($raw)
    {
        $raw = (string) $raw;
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $at = strpos($raw, "[{");
        if ($at === false) {
            $at = strpos($raw, "[]");
        }
        if ($at === false) {
            return null;
        }
        $decoded = json_decode(trim(substr($raw, $at)), true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function pm2ByName(array $decoded, $now)
    {
        $byName = [];
        foreach ($decoded as $process) {
            if (!is_array($process) || !isset($process["name"])) {
                continue;
            }
            $env     = isset($process["pm2_env"]) && is_array($process["pm2_env"]) ? $process["pm2_env"] : [];
            $status  = isset($env["status"]) && (string) $env["status"] !== "" ? (string) $env["status"] : "unknown";
            $started = isset($env["pm_uptime"]) && is_numeric($env["pm_uptime"]) ? (int) $env["pm_uptime"] : null;
            $online  = $status === "online";

            $byName[(string) $process["name"]] = [
                "name"      => (string) $process["name"],
                "state"     => $status,
                "pid"       => isset($process["pid"]) && (int) $process["pid"] > 0 ? (int) $process["pid"] : null,
                "restarts"  => isset($env["restart_time"]) ? (int) $env["restart_time"] : null,
                "uptimeMs"  => $online && $started !== null && $started > 0 ? max(0, (int) $now * 1000 - $started) : null,
                "startedAt" => $online && $started !== null && $started > 0 ? gmdate("c", intdiv($started, 1000)) : null,
            ];
        }
        return $byName;
    }

    /**
     * systemd unit states. `is-active` exits non-zero for anything but active,
     * so its output is read rather than its status — and an empty answer is
     * reported unknown, never as "inactive".
     *
     * No sudo: reading a unit's state needs no privilege, measured as www-data
     * on a live host. Every sudo dependency is one more way for this to report
     * a healthy box as unknown.
     */
    public static function systemdUnits(): array
    {
        $units = [];
        foreach (self::UNITS as $unit) {
            if ($unit === 'postgresql') {
                $clusters = self::postgresqlClusterUnits((string) @shell_exec("/bin/systemctl list-units --type=service --all --plain --no-legend 'postgresql@*' 2>/dev/null"));
                if ($clusters !== []) {
                    foreach ($clusters as $cluster) {
                        $units[] = $cluster;
                    }
                    continue;
                }
            }
            $units[] = $unit;
        }
        $out = [];
        foreach ($units as $unit) {
            $raw = @shell_exec('/bin/systemctl is-active ' . escapeshellarg($unit) . ' 2>/dev/null');
            $state = is_string($raw) ? trim($raw) : '';
            $out[] = [
                "name"  => $unit,
                "state" => $state === '' ? 'unknown' : $state,
            ];
        }
        return $out;
    }

    public static function postgresqlClusterUnits(string $listing): array
    {
        $units = [];
        foreach (preg_split('/\R/', $listing) as $line) {
            if (preg_match('/^\s*(?:\S+\s+)?(postgresql@[A-Za-z0-9._-]+)\.service\b/', $line, $m)) {
                $units[$m[1]] = true;
            }
        }
        return array_keys($units);
    }

    /**
     * Sofia SIP profiles, from FreeSWITCH's own console.
     *
     * `0 profiles` while FreeSWITCH reads active is the signature of a profile
     * that refused to bind — the 2026-09-20 outage where every hosted tenant
     * had no SIP service at all — and it is invisible in `systemctl is-active`,
     * which is why this is read separately rather than inferred from the unit.
     *
     * No sudo: fs_cli authenticates through event_socket.conf.xml, which
     * FreeSWITCH's own user can already read. Measured as www-data.
     */
    /**
     * Channels FreeSWITCH currently has up, as it reports them.
     *
     * Read-only, and it goes through the same fs_cli that sofiaProfiles() uses
     * so there is one place that talks to the console. The caller is
     * responsible for scoping the result to a tenant: this returns everything
     * the switch has.
     */
    public static function channels(): array
    {
        $raw = @shell_exec('/usr/bin/fs_cli -x "show channels as json" 2>/dev/null');
        if (!is_string($raw) || trim($raw) === '') {
            return ["rows" => [], "error" => "FreeSWITCH console did not answer"];
        }
        $decoded = json_decode(trim($raw), true);
        if (!is_array($decoded)) {
            return ["rows" => [], "error" => "FreeSWITCH returned something this build could not parse"];
        }
        // `show ... as json` answers {row_count: 0} with no rows when idle.
        $rows = isset($decoded["rows"]) && is_array($decoded["rows"]) ? $decoded["rows"] : [];
        return ["rows" => $rows, "error" => null];
    }

    public static function sofiaProfiles(): array
    {
        $raw = @shell_exec('/usr/bin/fs_cli -x "sofia status" 2>/dev/null');
        if (!is_string($raw) || trim($raw) === '') {
            return ["profiles" => [], "error" => "FreeSWITCH console did not answer"];
        }
        $profiles = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            if (!preg_match('/^\s*(\S+)\s+profile\s+(\S+)\s+(\S+)/', $line, $m)) {
                continue;
            }
            $profiles[] = [
                "name"  => $m[1],
                "url"   => $m[2],
                "state" => $m[3],
            ];
        }
        return ["profiles" => $profiles, "error" => null];
    }

    const SERVED_CERT_PORTS = [443 => "web app (nginx)", 8443 => "app API (nginx)", 7443 => "phones and web app calling (FreeSWITCH WSS)"];

    public static function localIpv4s(): array
    {
        $raw = @shell_exec('ip -4 -o addr show 2>/dev/null');
        $ips = [];
        foreach (preg_split('/\R/', (string) $raw) as $line) {
            if (preg_match('/\sinet\s+(\d{1,3}(?:\.\d{1,3}){3})\//', $line, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $m[1];
            }
        }
        return array_values(array_unique($ips));
    }

    public static function servedCertificates(string $host, ?array $addresses = null, int $timeout = 3): array
    {
        $host = strtolower(trim($host));
        if ($addresses === null) {
            $addresses = array_values(array_unique(array_merge(["127.0.0.1"], self::localIpv4s())));
        }
        $addresses = array_slice($addresses, 0, 6);
        $out = [];
        foreach (self::SERVED_CERT_PORTS as $port => $role) {
            $out[] = self::servedCertificate($host, (int) $port, $role, $addresses, $timeout);
        }
        return $out;
    }

    public static function servedCertificate(string $host, int $port, string $role, array $addresses, int $timeout = 3): array
    {
        $result = [
            "port" => $port,
            "role" => $role,
            "reachable" => false,
            "address" => null,
            "subject" => null,
            "names" => [],
            "issuer" => null,
            "notAfter" => null,
            "daysUntilExpiry" => null,
            "fingerprint" => null,
            "matchesHost" => null,
            "error" => null,
        ];
        $validHost = preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D', $host) === 1;
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            $ssl = [
                "capture_peer_cert" => true,
                "verify_peer" => false,
                "verify_peer_name" => false,
                "allow_self_signed" => true,
                "SNI_enabled" => $validHost,
            ];
            if ($validHost) {
                $ssl["peer_name"] = $host;
            }
            $context = stream_context_create(["ssl" => $ssl]);
            $socket = @stream_socket_client("ssl://{$address}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                continue;
            }
            $params = stream_context_get_params($socket);
            fclose($socket);
            $peer = $params["options"]["ssl"]["peer_certificate"] ?? null;
            if (!$peer) {
                continue;
            }
            $parsed = @openssl_x509_parse($peer);
            if (!is_array($parsed)) {
                continue;
            }
            $names = [];
            foreach (explode(",", (string) ($parsed["extensions"]["subjectAltName"] ?? "")) as $entry) {
                $entry = trim($entry);
                if (stripos($entry, "DNS:") === 0) {
                    $names[] = strtolower(substr($entry, 4));
                }
            }
            $subject = isset($parsed["subject"]["CN"]) ? strtolower((string) (is_array($parsed["subject"]["CN"]) ? end($parsed["subject"]["CN"]) : $parsed["subject"]["CN"])) : null;
            if ($names === [] && $subject !== null) {
                $names[] = $subject;
            }
            $issuer = $parsed["issuer"]["O"] ?? ($parsed["issuer"]["CN"] ?? null);
            $notAfter = isset($parsed["validTo_time_t"]) ? (int) $parsed["validTo_time_t"] : null;
            $fingerprint = @openssl_x509_fingerprint($peer, "sha256");
            $result = array_merge($result, [
                "reachable" => true,
                "address" => $address,
                "subject" => $subject,
                "names" => array_values(array_unique($names)),
                "issuer" => is_array($issuer) ? (string) end($issuer) : ($issuer !== null ? (string) $issuer : null),
                "notAfter" => $notAfter !== null ? gmdate("c", $notAfter) : null,
                "daysUntilExpiry" => $notAfter !== null ? (int) floor(($notAfter - time()) / 86400) : null,
                "fingerprint" => is_string($fingerprint) ? strtolower($fingerprint) : null,
                "matchesHost" => $validHost ? self::certificateCoversHost($names, $host) : null,
            ]);
            return $result;
        }
        $result["error"] = "Nothing answered a TLS connection on port {$port} on this server.";
        return $result;
    }

    public static function certificateCoversHost(array $names, string $host): bool
    {
        $host = strtolower(trim($host));
        foreach ($names as $name) {
            $name = strtolower(trim((string) $name));
            if ($name === $host) {
                return true;
            }
            if (strpos($name, "*.") === 0) {
                $suffix = substr($name, 1);
                $label = substr($host, 0, -strlen($suffix));
                if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix && $label !== "" && strpos($label, ".") === false) {
                    return true;
                }
            }
        }
        return false;
    }

    public static function servedCertificateProblems(array $served, string $host, ?int $onlyPort = null): array
    {
        $problems = [];
        $reference = null;
        foreach ($served as $cert) {
            if ((int) ($cert["port"] ?? 0) === 443 && !empty($cert["reachable"])) {
                $reference = $cert;
            }
        }
        foreach ($served as $cert) {
            $port = (int) ($cert["port"] ?? 0);
            if ($onlyPort !== null && $port !== $onlyPort) {
                continue;
            }
            $role = (string) ($cert["role"] ?? "");
            if (empty($cert["reachable"])) {
                $problems[] = "Port {$port} ({$role}) did not answer a TLS connection on this server.";
                continue;
            }
            $days = $cert["daysUntilExpiry"];
            if ($days !== null && $days < 0) {
                $problems[] = "The certificate on port {$port} ({$role}) expired on " . substr((string) $cert["notAfter"], 0, 10) . ".";
            } elseif ($days !== null && $days < 14) {
                $problems[] = "The certificate on port {$port} ({$role}) expires in {$days} days.";
            }
            if ($cert["matchesHost"] === false) {
                $problems[] = "The certificate on port {$port} ({$role}) is for " . implode(", ", (array) $cert["names"]) . ", not {$host}.";
            }
            if ($reference !== null && $port !== 443 && !empty($cert["fingerprint"]) && !empty($reference["fingerprint"])
                && $cert["fingerprint"] !== $reference["fingerprint"] && ($cert["notAfter"] ?? "") < ($reference["notAfter"] ?? "")) {
                $problems[] = "Port {$port} ({$role}) still serves an older certificate than port 443"
                    . ($port === 7443 ? "; FreeSWITCH was not given the renewed certificate (rebuild /etc/freeswitch/tls/all.pem and restart the internal profile)." : ".");
            }
        }
        return $problems;
    }
}
