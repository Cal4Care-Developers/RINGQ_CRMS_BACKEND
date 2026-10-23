<?php

use Phalcon\Mvc\View;

/**
 * AccessController — read-only diagnostics for the Emergency Support screen.
 *
 * Reached by the login screen's Emergency Support dialog (30 clicks on the
 * footer). See docs/INTEGRATION-emergency-support.md.
 *
 * THREE RULES, and each is a refusal rather than a feature:
 *
 * 1. AUTH IS SPLIT, NOT UNIFORM, and the split is the point:
 *      - diagnosticsAction is SESSION-GUARDED. It publishes the build plus
 *        which node services are down -- an unauthenticated version is a
 *        precise attack map on every box this controller ships to. During a
 *        *login* outage it is unavailable, and the dialog says so; the common
 *        incident (app fails to load, session cookie still valid) still works.
 *      - terminalAction is deliberately SESSION-OPTIONAL. It returns only a
 *        hostname and an `ssh <host>` string -- no secret, no service list, and
 *        it executes nothing -- so a locked-out operator can still read the
 *        command to paste. The broad half (service health) stays guarded.
 *
 * 2. READ-ONLY. Nothing here starts, stops, restarts or writes anything, and
 *    no request value ever reaches a shell. Every command below is a literal.
 *    `terminalAction` deliberately executes NOTHING; see its own comment.
 *
 * 3. NEVER FABRICATE. A value that cannot be read is reported absent, with the
 *    reason. Reporting a plausible default during an incident is worse than
 *    admitting ignorance — the screen exists to be trusted at 3am.
 *
 * Errors answer HTTP 200 with a `code`, matching this application's own
 * convention. The client MUST classify on `code`, never on the status line.
 */
class AccessController extends ControllerBase
{
    /** Where pm2 may live. nvm installs it outside sudo's secure_path. */
    const PM2_CANDIDATES = [
        '/usr/bin/pm2',
        '/usr/local/bin/pm2',
        '/usr/lib/node_modules/pm2/bin/pm2',
        '/root/.nvm/versions/node/v24.12.0/bin/pm2',
    ];

    /** The declared node app list. The same file pm2 itself is driven from. */
    const ECOSYSTEM_CONFIG = '/var/www/ringq/ecosystem.config.js';

    /**
     * GET /access/diagnostics — the server half of the Emergency Support report.
     */
    public function diagnosticsAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        // Guarded on purpose: the build and node health below are an attack map
        // when handed to anyone. The terminal command (a hostname) is the part
        // that is reachable signed-out -- see terminalAction.
        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setJsonContent([
                "status"  => "error",
                "code"    => "unauthorized",
                "message" => "Sign in to load server diagnostics.",
            ]);
        }

        $nodes = $this->nodeServices();

        return $this->response->setJsonContent([
            "status"        => "success",
            "serverVersion" => $this->serverVersion(),
            "hostname"      => $this->hostname(),
            "nodes"         => $nodes["services"],
            "nodesError"    => $nodes["error"],
            "readAt"        => gmdate("c"),
        ]);
    }

    /**
     * GET /access/terminal — what an operator needs to get a shell. NOT a shell.
     *
     * THIS ACTION EXECUTES NOTHING, ON PURPOSE. An in-browser shell reachable
     * from a screen revealed by clicking a footer is remote code execution, and
     * the brief that commissioned Emergency Support forbids it by name:
     * "must not execute arbitrary commands from an unauthenticated HTTP
     * request" and "must not implement a new covert remote-access mechanism".
     *
     * So it returns the connection detail a developer would otherwise look up
     * — host and SSH port, and NO credential of any kind — for them to paste
     * into their own terminal. That is genuinely faster at 3am and adds no
     * attack surface whatsoever.
     *
     * It is deliberately reachable WITHOUT a session: a hostname is not a secret,
     * nothing here executes, and the point is that a locked-out operator can
     * read the command. It is still audited (view-terminal-access), so the
     * intent to reach a shell is on the record either way.
     *
     * The product already reserves a home for a real terminal: `runTerminal` is
     * registered in BOTH ACL role blocks of app/config/services.php and is
     * already audited as `run-terminal` in app/library/AuditMap.php — but
     * `ReportsController::runTerminalAction` DOES NOT EXIST. It is a declared,
     * audited, never-implemented action. If an in-browser terminal is ever
     * built, that is where it belongs, and it needs an entitlement check and a
     * command allow-list before anything else.
     */
    public function terminalAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $host = $this->hostname();

        return $this->response->setJsonContent([
            "status"     => "success",
            "host"       => $host,
            "sshPort"    => 22,
            // No user, no password, no key. Deliberately not "root@": which
            // account an operator may use is not this endpoint's business, and
            // naming one reads as an instruction.
            "sshCommand" => $host === null ? null : "ssh " . $host,
            "executes"   => false,
            "reserved"   => "reports/runTerminal",
            "message"    => "This screen does not run commands. Open a terminal yourself.",
        ]);
    }

    /**
     * The server build, from the same redis key the rest of the app reads.
     *
     * Uses the framework's redis service rather than shelling out to
     * `redis-cli get serverVersion`: it is the established pattern here
     * (SystemController, DesktopController, MainTask), it needs no shell and no
     * redis-cli on www-data's PATH, and it returns the identical value.
     *
     * Returns null when redis cannot answer — which is itself worth knowing
     * during an incident, and is why this is not allowed to throw.
     */
    private function serverVersion()
    {
        try {
            $version = $this->redis->get("serverVersion");
        } catch (\Throwable $e) {
            error_log("AccessController::serverVersion: " . $e->getMessage());
            return null;
        }

        if (!is_string($version)) {
            return null;
        }
        $version = trim($version);

        return $version === "" ? null : $version;
    }

    /** This server's own hostname, or null. */
    private function hostname()
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
    private function nodeServices()
    {
        $expected = $this->declaredNodeApps();
        $running  = $this->pm2Processes();

        if ($running["error"] !== null) {
            // Report the declared list with an unknown state rather than
            // claiming everything is down. "I cannot see pm2" and "nothing is
            // running" are opposite conclusions and must not be confused.
            $services = [];
            foreach ($expected as $name) {
                $services[] = [
                    "name"      => $name,
                    "state"     => "unknown",
                    "declared"  => true,
                    "restarts"  => null,
                    "uptimeMs"  => null,
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
                    "restarts" => null,
                    "uptimeMs" => null,
                ];
                continue;
            }
            $services[] = $byName[$name] + ["declared" => true];
            unset($byName[$name]);
        }

        // Anything pm2 runs that the config does not declare. Also a real
        // finding: it survives a config rewrite and nothing will restart it.
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
     * declared and is then reported permanently `absent` — a guard failing
     * against a healthy server.
     *
     * @return string[]
     */
    private function declaredNodeApps()
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
     * its dump from — hence `sudo -n -H`. `-n` so a missing sudoers entry fails
     * immediately instead of blocking the request on a password prompt.
     *
     * @return array{byName: array, error: ?string}
     */
    private function pm2Processes()
    {
        $binary = null;
        foreach (self::PM2_CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                $binary = $candidate;
                break;
            }
        }
        if ($binary === null) {
            return ["byName" => [], "error" => "pm2 was not found on this server"];
        }

        // Fully literal. No request value reaches this command.
        $command = 'sudo -n -H ' . escapeshellarg($binary) . ' jlist 2>/dev/null';
        $raw     = @shell_exec($command);

        if (!is_string($raw) || trim($raw) === "") {
            return ["byName" => [], "error" => "pm2 could not be read by the web process"];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ["byName" => [], "error" => "pm2 returned something this build could not parse"];
        }

        $byName = [];
        foreach ($decoded as $process) {
            if (!is_array($process) || !isset($process["name"])) {
                continue;
            }
            $env    = isset($process["pm2_env"]) && is_array($process["pm2_env"]) ? $process["pm2_env"] : [];
            $status = isset($env["status"]) ? (string) $env["status"] : "unknown";

            $byName[(string) $process["name"]] = [
                "name"     => (string) $process["name"],
                "state"    => $status === "online" ? "online" : $status,
                "restarts" => isset($env["restart_time"]) ? (int) $env["restart_time"] : null,
                "uptimeMs" => isset($env["pm_uptime"]) ? (int) $env["pm_uptime"] : null,
            ];
        }

        return ["byName" => $byName, "error" => null];
    }
}
