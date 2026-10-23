<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;

class SslController extends ControllerBase
{
    private $contactEmail = 'cm@cal4care.com';

    /** Durable home for the DNS credentials certbot re-reads at renewal. */
    const CLOUDFLARE_INI = '/etc/letsencrypt/ringq-cloudflare.ini';

    public function infoAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unauthorized.",
            ]));
        }

        $fqdn = $this->resolveFqdn();
        if (!$fqdn) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Could not resolve FQDN.",
            ]));
        }

        $client   = $this->detectClient($fqdn);
        $certPath = $this->certPathFor($client, $fqdn);

        $info = [
            "result"          => "success",
            "fqdn"            => $fqdn,
            "client"          => $client,
            "certPath"        => $certPath,
            "issuer"          => null,
            "notBefore"       => null,
            "notAfter"        => null,
            "daysUntilExpiry" => null,
            "autoRenewAt"     => null,
            "autoRenewSource" => null,
            "currentJob"      => null,
        ];

        // Don't gate on file_exists() — /etc/letsencrypt/live is 0700 root:root
        // so www-data can't stat it. readCertInfo uses `sudo cat` internally
        // and is safe to call unconditionally; it returns nulls if unreadable.
        if ($certPath) {
            $info = array_merge($info, $this->readCertInfo($certPath));
        }

        // Derived from whatever actually renews THIS host, never from a fixed
        // assumption. The previous version of this field computed
        // notAfter - 7 days "matching the daily 0 0 * * * cron" -- but that cron
        // (MainTask::checkCertsAction) is hardcoded to certbot, so on a
        // dehydrated host it renews nothing and the panel printed a confident
        // date for a job that did nothing right up to the day the cert expired
        // (risingstream.ringq.ai, 2026-09-11). A null here means "no renewal is
        // scheduled", which is worth saying out loud.
        [$renewAt, $renewSource] = $this->renewalSchedule($client, $fqdn, $info["notAfter"] ?? null);
        $info["autoRenewAt"]     = $renewAt;
        $info["autoRenewSource"] = $renewSource;

        require_once __DIR__ . "/../library/ServiceHealth.php";
        $servedHost = strtolower(trim((string) ($session["subdomain"] ?? "")));
        if ($servedHost === "") {
            $servedHost = $fqdn;
        }
        $info["servedHost"]     = $servedHost;
        $info["served"]         = \ServiceHealth::servedCertificates($servedHost);
        $info["servedProblems"] = \ServiceHealth::servedCertificateProblems($info["served"], $servedHost);

        $currentId = $this->redis->get("ssl_renewal:current");
        if ($currentId) {
            $status = $this->redis->get("ssl_renewal:{$currentId}:status");
            if ($status) {
                $info["currentJob"] = ["id" => $currentId, "status" => $status];
            }
        }

        return $this->response->setContent(json_encode($info));
    }

    public function renewAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result"  => "error",
                "message" => "Unauthorized.",
            ]));
        }

        $refusal = $this->serverWideRefusal($session);
        if ($refusal !== null) {
            return $this->response->setContent(json_encode([
                "result"  => "error",
                "message" => $refusal,
            ]));
        }

        if ($this->redis->get("lock:ssl_renewal")) {
            $currentId = $this->redis->get("ssl_renewal:current");
            return $this->response->setContent(json_encode([
                "result"  => "error",
                "message" => "A renewal is already in progress.",
                "jobId"   => $currentId,
            ]));
        }

        $fqdn = $this->resolveFqdn();
        if (!$fqdn) {
            return $this->response->setContent(json_encode([
                "result"  => "error",
                "message" => "Could not resolve FQDN.",
            ]));
        }

        // Claim the job before responding so a concurrent click can't double-fire.
        $jobId = uniqid('ssl_', true);
        $this->redis->setex("lock:ssl_renewal", 1200, 1);
        $this->redis->setex("ssl_renewal:current", 1200, $jobId);
        $this->redis->del("ssl_renewal:{$jobId}:log");
        $this->redis->setex("ssl_renewal:{$jobId}:status", 3600, "queued");

        // Respond immediately so the UI can begin polling for log lines.
        @ignore_user_abort(true);
        @set_time_limit(0);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $this->response->setContent(json_encode([
            "result" => "success",
            "jobId"  => $jobId,
            "status" => "queued",
        ]));
        $this->response->send();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            @ob_end_flush();
            @flush();
        }

        $this->executeRenewal($jobId, $fqdn);
        return null;
    }

    public function statusAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result"  => "error",
                "message" => "Unauthorized.",
            ]));
        }

        $id = (string)$this->request->get("id", "string", "");
        if (!preg_match('/^ssl_[a-zA-Z0-9._]+$/', $id)) {
            return $this->response->setContent(json_encode([
                "result"  => "error",
                "message" => "Invalid job id.",
            ]));
        }

        $status  = $this->redis->get("ssl_renewal:{$id}:status") ?: "unknown";
        $log     = $this->redis->lrange("ssl_renewal:{$id}:log", 0, -1) ?: [];
        $summary = $this->redis->hgetall("ssl_renewal:{$id}:summary") ?: [];

        return $this->response->setContent(json_encode([
            "result"  => "success",
            "jobId"   => $id,
            "status"  => $status,
            "log"     => $log,
            "summary" => $summary,
        ]));
    }

    // ---------------------------------------------------------------
    // Renewal pipeline
    // ---------------------------------------------------------------

    private function executeRenewal(string $jobId, string $fqdn): void
    {
        $startedAt = time();
        $client    = $this->detectClient($fqdn);

        $this->redis->setex("ssl_renewal:{$jobId}:status", 3600, "running");
        $this->log($jobId, "Starting SSL renewal for {$fqdn}");
        $this->log($jobId, "Detected ACME client: {$client}");

        $ok    = false;
        $error = null;

        try {
            if ($client === 'letsencrypt') {
                $ok = $this->runLegacyLetsencrypt($jobId, $fqdn);
            } else {
                $ok = $this->runCertbot($jobId, $fqdn);
            }

            if ($ok) {
                $this->rebuildFreeswitchTls($jobId, $this->certDirFor($client, $fqdn));
                $this->relinkStaleTlsFiles($jobId);
                $this->restartServices($jobId);
            } else {
                $error = "Renewal command exited non-zero.";
            }
        } catch (\Throwable $e) {
            $ok    = false;
            $error = $e->getMessage();
            $this->log($jobId, "ERROR: {$error}");
        }

        $newExpiry = null;
        $certPath  = $this->certPathFor($client, $fqdn);
        if ($certPath) {
            $info = $this->readCertInfo($certPath);
            $newExpiry = $info["notAfter"] ?? null;
        }

        $status = $ok ? "success" : "failed";
        $this->log($jobId, "Renewal " . strtoupper($status) . ($newExpiry ? " — new expiry: {$newExpiry}" : ""));

        $this->redis->setex("ssl_renewal:{$jobId}:status", 3600, $status);
        $this->redis->hmset("ssl_renewal:{$jobId}:summary", [
            "client"      => $client,
            "fqdn"        => $fqdn,
            "newExpiry"   => $newExpiry ?? "",
            "durationSec" => (string)(time() - $startedAt),
            "error"       => $error ?? "",
        ]);
        $this->redis->expire("ssl_renewal:{$jobId}:summary", 3600);
        $this->redis->del("lock:ssl_renewal");
    }

    private function runCertbot(string $jobId, string $fqdn): bool
    {
        $installMode = $this->redis->get('install_mode');
        if ($installMode === 'mt') {
            $certDomain = '*.' . $fqdn;
            $apiToken   = 'cfat_RqKQ5dnxdJh0fFtUR2ThJTEZy0MO7peqMCR7xvk8bb1c7ebc';
        } else {
            $certDomain = $fqdn;
            $apiToken   = 'cfat_RqKQ5dnxdJh0fFtUR2ThJTEZy0MO7peqMCR7xvk8bb1c7ebc';
        }

        $needsBootstrap = !is_dir("/etc/letsencrypt/live/{$fqdn}");
        $this->log($jobId, $needsBootstrap
            ? "No existing cert at /etc/letsencrypt/live/{$fqdn} — bootstrapping."
            : "Existing cert found — forcing renewal.");

        // certbot PERSISTS this path into /etc/letsencrypt/renewal/<fqdn>.conf and
        // re-reads it on every unattended renewal. Writing it to /tmp and
        // unlinking it after issuance -- what this did before -- left every later
        // `certbot renew` failing with "File not found: /tmp/cloudflare_*.ini".
        // Measured on graylunaplc.ringq.ai 2026-09-11: the timer was enabled and
        // running, the cert was healthy, and `certbot renew --dry-run` reported
        // "All simulated renewals failed" -- it would have failed at its October
        // window with no earlier warning, because the timer SKIPS (and so never
        // touches the credentials) until the cert is actually due. The file must
        // therefore outlive the request that creates it.
        //
        // Staged as www-data (which cannot write /etc/letsencrypt) and installed
        // by root with the mode applied BEFORE the file lands, so the token is
        // never briefly world-readable; it never reaches a command line either.
        $iniPath = self::CLOUDFLARE_INI;
        $stage   = (string)tempnam(sys_get_temp_dir(), 'cfini');
        file_put_contents($stage, "dns_cloudflare_api_token = {$apiToken}\n");
        @chmod($stage, 0600);
        exec("sudo install -D -o root -g root -m 600 "
            . escapeshellarg($stage) . " " . escapeshellarg($iniPath));
        @unlink($stage);

        $cmd = "sudo certbot certonly "
            . "--dns-cloudflare "
            . "--dns-cloudflare-credentials " . escapeshellarg($iniPath) . " "
            . "--non-interactive "
            . "--agree-tos "
            . "--email " . escapeshellarg($this->contactEmail) . " "
            . ($needsBootstrap ? "" : "--force-renewal ")
            . "-d " . escapeshellarg($certDomain);

        $code = $this->tee($jobId, $cmd);

        // Deliberately NOT unlinked: certbot needs it again at every renewal.
        return $code === 0;
    }

    private function runLegacyLetsencrypt(string $jobId, string $fqdn): bool
    {
        $scriptDir = '/usr/src/telephony-setup.sh/debian/resources';
        if (!is_dir($scriptDir)) {
            $this->log($jobId, "Legacy renewal script directory not found: {$scriptDir}");
            return false;
        }
        $cmd = "cd " . escapeshellarg($scriptDir)
            . " && printf " . escapeshellarg("{$fqdn}\n{$this->contactEmail}\n")
            . " | sudo ./letsencrypt.sh";
        $code = $this->tee($jobId, $cmd);
        return $code === 0;
    }

    private function rebuildFreeswitchTls(string $jobId, string $certDir): void
    {
        $fullchain = "{$certDir}/fullchain.pem";
        $privkey   = "{$certDir}/privkey.pem";
        $certPem   = "{$certDir}/cert.pem";
        $chainPem  = "{$certDir}/chain.pem";
        $tls       = "/etc/freeswitch/tls";

        $this->log($jobId, "Rebuilding FreeSWITCH TLS bundle in {$tls}");

        // Source cert dir may be root:root 0700 (newer certbot), so we sudo cat
        // the inputs and sudo tee into the destinations. sudoers whitelists
        // /bin/cat /etc/letsencrypt/live/*/*.pem and /usr/bin/tee /etc/freeswitch/tls/*.pem.
        $this->tee($jobId, "{ sudo cat " . escapeshellarg($fullchain)
            . "; sudo cat " . escapeshellarg($privkey)
            . "; } | sudo tee {$tls}/all.pem > /dev/null");

        $this->tee($jobId, "sudo cat " . escapeshellarg($fullchain)
            . " | sudo tee {$tls}/fullchain.pem > /dev/null");
        $this->tee($jobId, "sudo cat " . escapeshellarg($privkey)
            . " | sudo tee {$tls}/privkey.pem > /dev/null");
        $this->tee($jobId, "sudo cat " . escapeshellarg($certPem)
            . " 2>/dev/null | sudo tee {$tls}/cert.pem > /dev/null");
        $this->tee($jobId, "sudo cat " . escapeshellarg($chainPem)
            . " 2>/dev/null | sudo tee {$tls}/chain.pem > /dev/null");

        // chown each file individually so each call matches a sudoers entry.
        foreach (['all.pem', 'fullchain.pem', 'privkey.pem', 'cert.pem', 'chain.pem'] as $f) {
            $this->tee($jobId, "sudo chown www-data:www-data {$tls}/{$f}");
        }
        $this->tee($jobId, "sudo chmod 640 {$tls}/privkey.pem");
        foreach (['all.pem', 'fullchain.pem', 'cert.pem', 'chain.pem'] as $f) {
            $this->tee($jobId, "sudo chmod 644 {$tls}/{$f}");
        }
    }

    private function sipProfilesScript(): string
    {
        return dirname(__DIR__) . "/scripts/fs-sip-profiles.sh";
    }

    private function relinkStaleTlsFiles(string $jobId): void
    {
        $script = $this->sipProfilesScript();
        if (!is_file($script)) {
            $this->log($jobId, "fs-sip-profiles.sh is not on this server; wss.key and the other TLS copies were not checked.");
            return;
        }
        $this->log($jobId, "Pointing TLS files that still hold the old certificate or key at the new all.pem");
        $heal = "/bin/bash " . escapeshellarg($script) . " heal";
        if (is_dir("/run/systemd/system") && is_executable("/usr/bin/systemd-run")) {
            $heal = "/usr/bin/systemd-run --quiet --wait --collect -p RuntimeMaxSec=60 " . $heal;
        }
        $this->tee($jobId, "/usr/bin/sudo -n " . $heal);

        $status = json_decode((string) @shell_exec("/bin/bash " . escapeshellarg($script) . " status 2>/dev/null"), true);
        if (!is_array($status) || !isset($status["tls"]) || !is_array($status["tls"])) {
            $this->log($jobId, "Could not read the TLS file states back.");
            return;
        }
        $this->log($jobId, "all.pem: " . (string) ($status["tls"]["allPem"] ?? "unknown"));
        $stale = [];
        foreach ((array) ($status["tls"]["files"] ?? []) as $file) {
            $name = (string) ($file["name"] ?? "");
            $state = (string) ($file["state"] ?? "unknown");
            $this->log($jobId, "{$name}: {$state}");
            if ($state === "stale" || $state === "unreadable") {
                $stale[] = $name;
            }
        }
        if ($stale !== []) {
            $this->log($jobId, "WARNING: still not matching all.pem: " . implode(", ", $stale)
                . ". The internal profile may not start after the restart; run sip.repair or link them to all.pem.");
        }
    }

    private function restartServices(string $jobId): void
    {
        $this->log($jobId, "Restarting services");
        $this->tee($jobId, "sudo systemctl restart nginx");
        $this->tee($jobId, "sudo systemctl restart freeswitch");

        // pm2 sits at different paths depending on how Node was installed (apt,
        // NVM, npm -g). Try the common locations in turn.
        $pm2Candidates = [
            '/usr/local/bin/pm2',
            '/usr/bin/pm2',
            '/bin/pm2',
        ];
        $rootPath = trim((string)shell_exec("ls -d /root/.nvm/versions/node/*/bin/pm2 2>/dev/null | tail -1"));
        if ($rootPath !== '') {
            array_unshift($pm2Candidates, $rootPath);
        }
        foreach ($pm2Candidates as $bin) {
            if (file_exists($bin)) {
                $this->tee($jobId, "sudo " . escapeshellarg($bin) . " restart all");
                return;
            }
        }
        $this->log($jobId, "pm2 binary not found — skipping pm2 restart.");
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function resolveFqdn(): ?string
    {
        $publicUrl = strtolower(trim((string) $this->config->application->publicUrl));
        if ($this->redis->get('install_mode') === 'mt') {
            return $publicUrl !== '' ? $publicUrl : null;
        }
        $hostname = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/D';
        $licence = json_decode((string) $this->redis->get('ringq_config'), true);
        $licensed = is_array($licence) ? strtolower(trim((string) ($licence['data']['fqdn'] ?? ''))) : '';
        if ($licensed !== '' && preg_match($hostname, $licensed) === 1 && filter_var($licensed, FILTER_VALIDATE_IP) === false) {
            return $licensed;
        }
        if ($publicUrl === '') {
            return null;
        }
        $exact = VDomains::findFirst([
            'conditions' => 'domain_name = :domain:',
            'bind' => ['domain' => $publicUrl],
            'bindTypes' => [Column::BIND_PARAM_STR],
        ]);
        if ($exact) {
            return (string) $exact->domain_name;
        }
        $matches = [];
        foreach (VDomains::find([
            'conditions' => 'domain_name LIKE :domain:',
            'bind' => ['domain' => '%' . $publicUrl . '%'],
            'bindTypes' => [Column::BIND_PARAM_STR],
        ]) as $row) {
            $name = strtolower(trim((string) $row->domain_name));
            if (preg_match($hostname, $name) === 1 && filter_var($name, FILTER_VALIDATE_IP) === false) {
                $matches[$name] = true;
            }
        }
        return count($matches) === 1 ? (string) array_key_first($matches) : null;
    }

    /**
     * When the next unattended renewal can first happen, and what would do it.
     *
     * Returns [ "YYYY-MM-DD"|null, source|null ]. The date is the first day the
     * scheduler's own renewal window opens (notAfter minus the client's
     * renew-before threshold), clamped to today when that day has already
     * passed. Null means nothing on this host is scheduled to renew the
     * certificate -- which is a real answer, not a missing one.
     */
    private function renewalSchedule(string $client, string $fqdn, ?string $notAfter): array
    {
        if (!$notAfter) return [null, null];
        $expiry = strtotime($notAfter);
        if (!$expiry) return [null, null];

        if ($client === 'letsencrypt') {
            $source = $this->dehydratedScheduler();
            $days   = $this->dehydratedRenewDays();
        } else {
            $source = $this->certbotScheduler();
            $days   = $this->certbotRenewDays($fqdn);
        }
        if (!$source) return [null, null];

        $renewAt = $expiry - ($days * 86400);
        if ($renewAt < time()) $renewAt = time();
        return [date('Y-m-d', $renewAt), $source];
    }

    /** The systemd timer or cron entry that runs `certbot renew`, if any. */
    private function certbotScheduler(): ?string
    {
        // Readable by www-data; no sudo needed for a state query.
        $active = trim((string)shell_exec("systemctl is-active certbot.timer 2>/dev/null"));
        if ($active === 'active') return 'certbot.timer';
        $enabled = trim((string)shell_exec("systemctl is-enabled certbot.timer 2>/dev/null"));
        if ($enabled === 'enabled') return 'certbot.timer';

        return $this->cronInvoking('certbot');
    }

    /** The cron entry that runs dehydrated, if any. */
    private function dehydratedScheduler(): ?string
    {
        return $this->cronInvoking('dehydrated');
    }

    /**
     * The /etc/cron.d entry that actually RUNS $binary, if any.
     *
     * Comments are stripped before matching. A plain substring search over the
     * raw file reports any entry that merely mentions the name in a comment --
     * measured: the dehydrated cron shipped for risingstream.ringq.ai explains
     * in its header why checkCerts/certbot does not work there, and that alone
     * made this function answer "certbot.timer exists" on a host with no
     * certbot scheduler at all. A false positive here prints a renewal date for
     * something that will never run, which is the exact failure this field was
     * restored to stop telling.
     */
    private function cronInvoking(string $binary): ?string
    {
        foreach ((array)glob('/etc/cron.d/*') as $f) {
            $body = (string)@file_get_contents($f);
            if ($body === '') continue;
            foreach (preg_split('/\R/', $body) as $line) {
                $line = preg_replace('/#.*$/', '', $line);
                if (trim((string)$line) === '') continue;
                if (preg_match('/(^|[\/\s])' . preg_quote($binary, '/') . '(\s|$)/', (string)$line)) {
                    return 'cron: ' . basename($f);
                }
            }
        }
        return null;
    }

    /**
     * certbot renews inside `renew_before_expiry` (default 30 days). The value
     * lives in the per-cert renewal config, which is 0644 and readable here --
     * unlike /etc/letsencrypt/live, which is 0700.
     */
    private function certbotRenewDays(string $fqdn): int
    {
        $conf = "/etc/letsencrypt/renewal/{$fqdn}.conf";
        $body = (string)@file_get_contents($conf);
        if ($body !== '' && preg_match('/^\s*renew_before_expiry\s*=\s*(\d+)\s*day/mi', $body, $m)) {
            return max(1, (int)$m[1]);
        }
        return 30;
    }

    /** dehydrated renews inside RENEW_DAYS (default 30). */
    private function dehydratedRenewDays(): int
    {
        $body = (string)@file_get_contents('/etc/dehydrated/config');
        if ($body !== '' && preg_match('/^\s*RENEW_DAYS\s*=\s*"?(\d+)"?/mi', $body, $m)) {
            return max(1, (int)$m[1]);
        }
        return 30;
    }

    private function detectClient(string $fqdn): string
    {
        // What nginx is actually serving wins — sites-enabled is world-readable,
        // unlike /etc/letsencrypt/live which is root:root 0700 on newer certbot
        // and unreachable to www-data.
        $ngrep = (string)shell_exec("grep -h 'ssl_certificate ' /etc/nginx/sites-enabled/* 2>/dev/null | head -1");
        if (strpos($ngrep, '/etc/letsencrypt/') !== false) return 'certbot';
        if (strpos($ngrep, '/etc/dehydrated/') !== false) return 'letsencrypt';

        // Filesystem fallbacks (best-effort; may fail silently under perms).
        if (@is_dir("/etc/letsencrypt/live/{$fqdn}")) return 'certbot';
        if (@is_dir("/etc/dehydrated/certs/{$fqdn}")) return 'letsencrypt';

        return 'certbot';
    }

    private function certDirFor(string $client, string $fqdn): string
    {
        return $client === 'letsencrypt'
            ? "/etc/dehydrated/certs/{$fqdn}"
            : "/etc/letsencrypt/live/{$fqdn}";
    }

    private function certPathFor(string $client, string $fqdn): string
    {
        return $this->certDirFor($client, $fqdn) . '/fullchain.pem';
    }

    private function readCertInfo(string $certPath): array
    {
        $info = [
            "issuer"          => null,
            "notBefore"       => null,
            "notAfter"        => null,
            "daysUntilExpiry" => null,
        ];
        // /etc/letsencrypt/live and /etc/letsencrypt/archive are root:root 0700
        // on newer certbot — unreadable by www-data. Try sudo cat first, fall
        // back to direct read for world-readable paths like /etc/dehydrated/.
        $out = (string)shell_exec(
            "sudo cat " . escapeshellarg($certPath)
            . " 2>/dev/null | openssl x509 -noout -issuer -startdate -enddate 2>/dev/null"
        );
        if ($out === '') {
            $out = (string)shell_exec(
                "openssl x509 -noout -issuer -startdate -enddate -in "
                . escapeshellarg($certPath) . " 2>/dev/null"
            );
        }
        if (preg_match('/issuer=(.*)/', $out, $m)) $info["issuer"] = trim($m[1]);
        if (preg_match('/notBefore=(.*)/', $out, $m)) $info["notBefore"] = trim($m[1]);
        if (preg_match('/notAfter=(.*)/', $out, $m)) {
            $info["notAfter"] = trim($m[1]);
            $expiry = strtotime($info["notAfter"]);
            if ($expiry) {
                $info["daysUntilExpiry"] = (int)floor(($expiry - time()) / 86400);
            }
        }
        return $info;
    }

    private function log(string $jobId, string $line): void
    {
        $stamped = "[" . date('H:i:s') . "] " . $line;
        $this->redis->rpush("ssl_renewal:{$jobId}:log", $stamped);
        $this->redis->expire("ssl_renewal:{$jobId}:log", 3600);
    }

    /**
     * Stream a command's stdout/stderr into the Redis job log one line at a time.
     * Returns the command's exit code.
     */
    private function tee(string $jobId, string $command): int
    {
        $this->log($jobId, "$ {$command}");
        $fh = @popen($command . ' 2>&1', 'r');
        if (!$fh) {
            $this->log($jobId, "Failed to start command.");
            return -1;
        }
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) break;
            $line = rtrim($line, "\r\n");
            if ($line !== '') {
                $this->log($jobId, "  " . $line);
            }
        }
        $code = pclose($fh);
        $this->log($jobId, "  (exit {$code})");
        return $code;
    }
}
