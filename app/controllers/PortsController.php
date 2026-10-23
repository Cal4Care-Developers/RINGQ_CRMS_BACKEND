<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

class PortsController extends ControllerBase
{
    public function indexAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "Unauthorized request. (Code PT1000)",
                "content" => [],
            ]);
        }

        // mode=internal (default) → local `ss` listening check.
        // mode=external          → reachability probed from outside the server.
        $mode = strtolower((string) $this->request->get("mode", "string", "internal"));
        if ($mode === "external") {
            return $this->response->setJsonContent($this->buildExternalResponse());
        }

        $metadata = $this->getPortMetadata();
        $livePorts = $this->getLivePorts();
        $liveRanges = [
            "5004-5005" => $this->getLiveUdpRange(5004, 5005),
            "9000-10999" => $this->getLiveUdpRange(9000, 10999),
            "16384-32767" => $this->getLiveUdpRange(16384, 32767),
            "48000-65535" => $this->getLiveUdpRange(48000, 65535),
        ];
        $ports = [];

        foreach ($metadata as $port => $info) {
            $live = !empty($info["scan_range"]) ? ($liveRanges[$port] ?? null) : ($livePorts[$port] ?? null);
            $ports[] = $this->buildPortRow((string) $port, $info, $live);
        }

        usort($ports, function ($a, $b) {
            return intval($a["port"]) <=> intval($b["port"]);
        });

        return $this->response->setJsonContent([
            "result" => "success",
            "message" => "",
            "content" => [
                "mode" => "internal",
                "generated_at" => date("c"),
                "ports" => $ports,
            ],
        ]);
    }

    // ───────────────────────────────────────────────────────────────
    // Per-row live scan (async job + poll, mirrors SslController)
    //
    // The UI scans one table row at a time. POST /ports/scan?port=KEY starts a
    // background job that streams a "checking port N... [State]" log line per
    // port (every integer for a range), which the row polls via
    // GET /ports/status?id=. POST /ports/scancancel?id= stops it.
    // ───────────────────────────────────────────────────────────────

    private const EXTERNAL_SCAN_CAP = 150; // hard ceiling on external probes per row

    public function scanAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->session->get("auth")) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized."]);
        }
        $refusal = $this->serverWideRefusal($this->session->get("auth"));
        if ($refusal !== null) {
            return $this->response->setJsonContent(["result" => "error", "message" => $refusal]);
        }

        $mode = strtolower((string) $this->request->get("mode", "string", "internal"));
        if ($mode !== "external") {
            $mode = "internal";
        }
        $portKey = (string) $this->request->get("port", "string", "");
        $metadata = $this->getPortMetadata();
        if ($portKey === "" || !isset($metadata[$portKey])) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Unknown port."]);
        }

        $lockKey = "lock:portscan:{$mode}:{$portKey}";
        $existing = $this->redis->get($lockKey);
        if ($existing) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "A scan for this port is already running.",
                "jobId" => $existing,
            ]);
        }

        $jobId = uniqid("portscan_", true);
        $this->redis->setex($lockKey, 1800, $jobId);
        $this->redis->del("portscan:{$jobId}:log");
        $this->redis->del("portscan:{$jobId}:cancel");
        $this->redis->setex("portscan:{$jobId}:status", 3600, "queued");
        $this->redis->setex("portscan:{$jobId}:checked", 3600, "0");
        $this->redis->setex("portscan:{$jobId}:total", 3600, "0");

        // Run the scan AFTER the response is delivered. Deferring to a shutdown
        // function lets Phalcon send the JSON body through its normal path
        // (reliable Content-Length, CORS, etc.) — manually calling send()+flush
        // mid-action returns an empty body on some SAPIs/output-buffer setups.
        $info = $metadata[$portKey];
        register_shutdown_function(function () use ($jobId, $mode, $portKey, $info, $lockKey) {
            @ignore_user_abort(true);
            @set_time_limit(0);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // release the session lock so polls aren't blocked
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $this->executeScan($jobId, $mode, $portKey, $info, $lockKey);
            } catch (\Throwable $e) {
                $this->finishScan($jobId, $lockKey, "unknown", "failed");
            }
        });

        return $this->response->setJsonContent([
            "result" => "success",
            "jobId" => $jobId,
            "status" => "queued",
        ]);
    }

    public function statusAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->session->get("auth")) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized."]);
        }

        $id = (string) $this->request->get("id", "string", "");

        // No id: a client that lost its jobId (page refresh) asks for the
        // bulk scan that is still running. The scanall lock holds its jobId
        // until finishScan() deletes it, so a present lock means running.
        if ($id === "") {
            $modes = ["internal", "external"];
            $preferred = strtolower((string) $this->request->get("mode", "string", ""));
            if ($preferred === "external") {
                $modes = ["external", "internal"];
            }
            foreach ($modes as $mode) {
                $running = (string) ($this->redis->get("lock:portscanall:{$mode}") ?: "");
                if (preg_match('/^portscan_[a-zA-Z0-9._]+$/', $running)) {
                    return $this->response->setJsonContent(
                        ["mode" => $mode] + $this->jobStatus($running)
                    );
                }
            }
            return $this->response->setJsonContent(["result" => "success", "status" => "idle"]);
        }

        if (!preg_match('/^portscan_[a-zA-Z0-9._]+$/', $id)) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Invalid job id."]);
        }

        return $this->response->setJsonContent($this->jobStatus($id));
    }

    private function jobStatus(string $id): array
    {
        return [
            "result" => "success",
            "jobId" => $id,
            "status" => $this->redis->get("portscan:{$id}:status") ?: "unknown",
            "log" => $this->redis->lrange("portscan:{$id}:log", 0, -1) ?: [],
            "checked" => (int) ($this->redis->get("portscan:{$id}:checked") ?: 0),
            "total" => (int) ($this->redis->get("portscan:{$id}:total") ?: 0),
            "port_status" => $this->redis->get("portscan:{$id}:result") ?: "",
            "row_status" => $this->redis->hgetall("portscan:{$id}:rowresults") ?: [],
        ];
    }

    public function scancancelAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->session->get("auth")) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized."]);
        }
        $refusal = $this->serverWideRefusal($this->session->get("auth"));
        if ($refusal !== null) {
            return $this->response->setJsonContent(["result" => "error", "message" => $refusal]);
        }

        $id = (string) $this->request->get("id", "string", "");
        if (!preg_match('/^portscan_[a-zA-Z0-9._]+$/', $id)) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Invalid job id."]);
        }
        $this->redis->setex("portscan:{$id}:cancel", 600, "1");
        return $this->response->setJsonContent(["result" => "success"]);
    }

    // ───────────────────────────────────────────────────────────────
    // Scan All — one bulk job over every row, streaming to a shared log.
    // ───────────────────────────────────────────────────────────────

    private const BULK_SAMPLE_PER_RANGE = 50; // ports sampled per big (scan_range) row

    public function scanallAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->session->get("auth")) {
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized."]);
        }
        $refusal = $this->serverWideRefusal($this->session->get("auth"));
        if ($refusal !== null) {
            return $this->response->setJsonContent(["result" => "error", "message" => $refusal]);
        }

        $mode = strtolower((string) $this->request->get("mode", "string", "internal"));
        if ($mode !== "external") {
            $mode = "internal";
        }

        $lockKey = "lock:portscanall:{$mode}";
        $existing = $this->redis->get($lockKey);
        if ($existing) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "A bulk scan is already running.",
                "jobId" => $existing,
            ]);
        }

        $jobId = uniqid("portscan_", true);
        $this->redis->setex($lockKey, 1800, $jobId);
        $this->redis->del("portscan:{$jobId}:log");
        $this->redis->del("portscan:{$jobId}:cancel");
        $this->redis->del("portscan:{$jobId}:rowresults");
        $this->redis->setex("portscan:{$jobId}:status", 3600, "queued");
        $this->redis->setex("portscan:{$jobId}:checked", 3600, "0");
        $this->redis->setex("portscan:{$jobId}:total", 3600, "0");

        register_shutdown_function(function () use ($jobId, $mode, $lockKey) {
            @ignore_user_abort(true);
            @set_time_limit(0);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                $this->executeScanAll($jobId, $mode, $lockKey);
            } catch (\Throwable $e) {
                $this->finishScan($jobId, $lockKey, "", "failed");
            }
        });

        return $this->response->setJsonContent([
            "result" => "success",
            "jobId" => $jobId,
            "status" => "queued",
        ]);
    }

    private function executeScanAll(string $jobId, string $mode, string $lockKey): void
    {
        $this->redis->setex("portscan:{$jobId}:status", 3600, "running");

        $metadata = $this->getPortMetadata();
        $keys = array_keys($metadata);
        usort($keys, fn($a, $b) => intval($a) <=> intval($b));

        // Pre-compute each row's (sampled) port list + the grand total.
        $rows = [];
        $total = 0;
        foreach ($keys as $key) {
            $ports = $this->bulkPortsFor((string) $key, $metadata[$key]);
            $rows[] = [(string) $key, $metadata[$key], $ports];
            $total += count($ports);
        }
        $this->redis->setex("portscan:{$jobId}:total", 3600, (string) $total);

        $snapshot = $mode === "external" ? [] : $this->snapshotListeningPorts();
        $publicIp = "";
        $externalReady = false;
        if ($mode === "external") {
            $publicIp = (string) ($this->resolvePublicIp() ?? "");
            $externalReady = self::PORT_SCAN_API_URL !== "" && $publicIp !== "";
            if (!$externalReady) {
                $this->scanLog($jobId, "External scanning unavailable (no public IP) — ports reported as Timed-out.");
            }
        }

        $checked = 0;
        $delayUs = (int) min(120000, max(2000, (20.0 / max(1, $total)) * 1000000));

        foreach ($rows as [$key, $info, $ports]) {
            if ($this->redis->get("portscan:{$jobId}:cancel")) {
                $this->scanLog($jobId, "Scan cancelled.");
                $this->finishScan($jobId, $lockKey, "", "cancelled");
                return;
            }

            $service = (string) ($info["service"] ?? $key);
            $this->scanLog($jobId, "── {$service} ({$key}) ──");
            $exposure = $info["exposure"] ?? "";

            // Outbound rows → single egress test, mode-independent.
            if ($exposure === "Outbound") {
                $state = $this->outboundStatus($key);
                $this->scanLog($jobId, "checking port {$key}... [" . $this->stateLabel($state) . "]");
                $this->redis->hset("portscan:{$jobId}:rowresults", $key, $state);
                $checked += count($ports);
                $this->redis->setex("portscan:{$jobId}:checked", 3600, (string) $checked);
                usleep($delayUs);
                continue;
            }

            // External: one batched checker call for this row.
            $externalMap = [];
            if ($mode === "external" && $externalReady) {
                try {
                    $protocols = $this->protocolsFor((string) ($info["protocol"] ?? ""));
                    $tcpAll = in_array("tcp", $protocols, true) ? $ports : [];
                    $udpAll = in_array("udp", $protocols, true) ? $ports : [];
                    $externalMap = $this->externalScan($publicIp, $tcpAll, $udpAll);
                } catch (\Throwable $e) {
                    $this->scanLog($jobId, "External scanner error: " . $e->getMessage());
                }
            }

            $anyGood = false;
            $anyBad = false;
            $anyUnknown = false;

            foreach ($ports as $p) {
                if ($this->redis->get("portscan:{$jobId}:cancel")) {
                    $this->scanLog($jobId, "Scan cancelled.");
                    $this->finishScan($jobId, $lockKey, "", "cancelled");
                    return;
                }

                if ($mode === "external") {
                    $state = $externalMap["tcp:{$p}"] ?? $externalMap["udp:{$p}"] ?? "unknown";
                } else {
                    $state = isset($snapshot[$p]) ? "listening" : "not_detected";
                    if ($state === "not_detected" && !empty($info["internal_undetected"])) {
                        $state = $info["internal_undetected"];
                    }
                }

                $this->scanLog($jobId, "checking port {$p}... [" . $this->stateLabel($state) . "]");

                if (in_array($state, ["listening", "active", "reachable", "egress_ok"], true)) {
                    $anyGood = true;
                } elseif (in_array($state, ["blocked", "filtered", "egress_blocked"], true)) {
                    $anyBad = true;
                } elseif ($state === "unknown") {
                    $anyUnknown = true;
                }

                $checked++;
                $this->redis->setex("portscan:{$jobId}:checked", 3600, (string) $checked);
                usleep($delayUs);
            }

            $rowResult = $this->aggregateFlags($mode, $anyGood, $anyBad, $anyUnknown);
            if ($mode === "internal" && $rowResult === "not_detected" && !empty($info["internal_undetected"])) {
                $rowResult = $info["internal_undetected"];
            }
            $this->redis->hset("portscan:{$jobId}:rowresults", $key, $rowResult);
        }

        $this->redis->expire("portscan:{$jobId}:rowresults", 3600);
        $this->scanLog($jobId, "Scan complete — {$checked} port(s) checked.");
        $this->finishScan($jobId, $lockKey, "", "done");
    }

    /** Ports to scan for a row in bulk: full for discrete/small, sampled for big ranges. */
    private function bulkPortsFor(string $key, array $info): array
    {
        if (!empty($info["scan_range"]) && strpos($key, "-") !== false) {
            [$start, $end] = array_map("intval", explode("-", $key, 2));
            $sample = [];
            for ($p = $start; $p <= $end && count($sample) < self::BULK_SAMPLE_PER_RANGE; $p++) {
                $sample[] = $p;
            }
            if (!in_array($end, $sample, true)) {
                $sample[] = $end;
            }
            return array_values(array_unique($sample));
        }
        return $this->expandPorts($key);
    }

    private function executeScan(string $jobId, string $mode, string $portKey, array $info, string $lockKey): void
    {
        $this->redis->setex("portscan:{$jobId}:status", 3600, "running");
        $exposure = $info["exposure"] ?? "";

        // Outbound ports → egress test (mode-independent; outbound reachability is the real check).
        if ($exposure === "Outbound") {
            $this->redis->setex("portscan:{$jobId}:total", 3600, "1");
            $this->scanLog($jobId, "checking outbound egress on port {$portKey}...");
            if ($this->redis->get("portscan:{$jobId}:cancel")) {
                $this->finishScan($jobId, $lockKey, "unknown", "cancelled");
                return;
            }
            $state = $this->outboundStatus($portKey);
            $this->scanLog($jobId, "port {$portKey}... [" . $this->stateLabel($state) . "]");
            $this->redis->setex("portscan:{$jobId}:checked", 3600, "1");
            $this->finishScan($jobId, $lockKey, $state, "done");
            return;
        }

        $ports = $this->expandPorts($portKey);

        $externalReady = false;
        $publicIp = "";
        if ($mode === "external") {
            $publicIp = (string) ($this->resolvePublicIp() ?? "");
            $externalReady = self::PORT_SCAN_API_URL !== "" && $publicIp !== "";
            if (count($ports) > self::EXTERNAL_SCAN_CAP) {
                $ports = array_slice($ports, 0, self::EXTERNAL_SCAN_CAP);
                $this->scanLog($jobId, "External scan capped at " . self::EXTERNAL_SCAN_CAP . " ports.");
            }
            if (!$externalReady) {
                $this->scanLog($jobId, "External scanning unavailable (no public IP) — ports reported as Timed-out.");
            }
        }

        $this->redis->setex("portscan:{$jobId}:total", 3600, (string) count($ports));
        $snapshot = $mode === "external" ? [] : $this->snapshotListeningPorts();
        $protocols = $this->protocolsFor((string) ($info["protocol"] ?? ""));

        // External: ONE batched provider call for the row. The free HackerTarget
        // API is quota-limited (50/day) and scans common ports only, so we never
        // call it per-port; ports it didn't scan stay "unknown".
        $externalMap = [];
        if ($mode === "external" && $externalReady) {
            $this->scanLog($jobId, "Querying external scanner for {$publicIp} (common ports only)…");
            try {
                $tcpAll = in_array("tcp", $protocols, true) ? $ports : [];
                $udpAll = in_array("udp", $protocols, true) ? $ports : [];
                $externalMap = $this->externalScan($publicIp, $tcpAll, $udpAll);
            } catch (\Throwable $e) {
                $this->scanLog($jobId, "External scanner error: " . $e->getMessage());
            }
        }

        $anyGood = false;
        $anyBad = false;
        $anyUnknown = false;
        $checked = 0;
        // Always stream each port with a visible delay so the terminal ticks
        // live — even for big ranges. Small ranges tick at ~0.5s/port; large
        // ranges scale down (2ms floor) so the whole range streams in ~20-35s
        // instead of finishing before the UI can show it (a flat 0.5s on a 17k
        // range would take hours).
        $portCount = max(1, count($ports));
        $delayUs = (int) min(500000, max(2000, (20.0 / $portCount) * 1000000));

        foreach ($ports as $p) {
            if ($this->redis->get("portscan:{$jobId}:cancel")) {
                $this->scanLog($jobId, "Scan cancelled at port {$p}.");
                $this->finishScan($jobId, $lockKey, $this->aggregateFlags($mode, $anyGood, $anyBad, $anyUnknown), "cancelled");
                return;
            }

            if ($mode === "external") {
                $state = $externalMap["tcp:{$p}"] ?? $externalMap["udp:{$p}"] ?? "unknown";
            } else {
                $state = isset($snapshot[$p]) ? "listening" : "not_detected";
                if ($state === "not_detected" && !empty($info["internal_undetected"])) {
                    $state = $info["internal_undetected"];
                }
            }

            $this->scanLog($jobId, "checking port {$p}... [" . $this->stateLabel($state) . "]");

            if (in_array($state, ["listening", "active", "reachable", "egress_ok"], true)) {
                $anyGood = true;
            } elseif (in_array($state, ["blocked", "filtered", "egress_blocked"], true)) {
                $anyBad = true;
            } elseif ($state === "unknown") {
                $anyUnknown = true;
            }

            $checked++;
            $this->redis->setex("portscan:{$jobId}:checked", 3600, (string) $checked);

            if ($delayUs > 0) {
                usleep($delayUs);
            }
        }

        $result = $this->aggregateFlags($mode, $anyGood, $anyBad, $anyUnknown);
        if ($mode === "internal" && $result === "not_detected" && !empty($info["internal_undetected"])) {
            $result = $info["internal_undetected"];
        }
        $this->finishScan($jobId, $lockKey, $result, "done");
    }

    private function aggregateFlags(string $mode, bool $good, bool $bad, bool $unknown): string
    {
        if ($good) {
            return $mode === "external" ? "reachable" : "listening";
        }
        if ($bad) {
            return "blocked";
        }
        if ($unknown) {
            return "unknown";
        }
        return $mode === "external" ? "closed" : "not_detected";
    }

    private function expandPorts(string $portKey): array
    {
        if (strpos($portKey, "-") !== false) {
            [$start, $end] = array_map("intval", explode("-", $portKey, 2));
            if ($end < $start) {
                return [$start];
            }
            return range($start, $end);
        }
        return [intval($portKey)];
    }

    private function snapshotListeningPorts(): array
    {
        $set = [];
        foreach (["ss -H -tuln 2>/dev/null", "ss -H -uan 2>/dev/null"] as $cmd) {
            $output = [];
            exec($cmd, $output, $code);
            if ($code !== 0 || empty($output)) {
                continue;
            }
            foreach ($output as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 5) {
                    continue;
                }
                if (preg_match('/:(\d+)$/', (string) $parts[4], $m)) {
                    $set[(int) $m[1]] = true;
                }
            }
        }
        return $set;
    }

    private function stateLabel(string $state): string
    {
        switch ($state) {
            case "listening": return "Listening";
            case "active": return "Active";
            case "reachable": return "Reachable";
            case "blocked": return "Blocked";
            case "filtered": return "Filtered";
            case "closed": return "Closed";
            case "egress_ok": return "Egress OK";
            case "egress_blocked": return "Egress Blocked";
            case "reference": return "Reference";
            case "not_detected": return "Not detected";
            default: return "Timed-out";
        }
    }

    private function scanLog(string $jobId, string $line): void
    {
        $this->redis->rpush("portscan:{$jobId}:log", $line);
        $this->redis->ltrim("portscan:{$jobId}:log", -500, -1);
        $this->redis->expire("portscan:{$jobId}:log", 3600);
    }

    private function finishScan(string $jobId, string $lockKey, string $result, string $status): void
    {
        $this->redis->setex("portscan:{$jobId}:result", 3600, $result);
        $this->redis->setex("portscan:{$jobId}:status", 3600, $status);
        $this->redis->del($lockKey);
    }

    private function buildPortRow(string $port, array $info, ?array $live): array
    {
        $bindAddresses = $live ? $live["bind_addresses"] : [];
        $protocols = $live ? $live["protocols"] : [$info["protocol"]];

        return [
            "port" => $port,
            "category" => $info["category"] ?? "",
            "protocol" => implode("/", $protocols),
            "exposure" => $live ? $this->detectExposure($bindAddresses) : $info["exposure"],
            "service" => $info["service"],
            "listener" => $info["service"],
            "used_for" => $info["used_for"],
            "required" => $info["required"],
            "recommended_action" => $info["recommended_action"],
            "status" => $live ? (!empty($info["scan_range"]) ? "active" : "listening") : ($info["status"] ?? $info["internal_undetected"] ?? "not_detected"),
            "bind_addresses" => $bindAddresses,
        ];
    }

    private function getLivePorts(): array
    {
        $output = [];
        exec("ss -H -tulpn 2>/dev/null", $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return [];
        }

        $ports = [];
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 5) {
                continue;
            }

            $protocol = strtoupper((string) $parts[0]);
            $localAddress = (string) $parts[4];

            if (!preg_match('/^(.*):(\d+)$/', $localAddress, $matches)) {
                continue;
            }

            $bindAddress = $matches[1];
            $port = $matches[2];
            if (!isset($ports[$port])) {
                $ports[$port] = [
                    "protocols" => [],
                    "bind_addresses" => [],
                ];
            }

            $ports[$port]["protocols"][$protocol] = $protocol;
            $ports[$port]["bind_addresses"][$bindAddress] = $bindAddress;
        }

        foreach ($ports as &$portInfo) {
            $portInfo["protocols"] = array_values($portInfo["protocols"]);
            $portInfo["bind_addresses"] = array_values($portInfo["bind_addresses"]);
        }
        unset($portInfo);

        return $ports;
    }

    private function getLiveUdpRange(int $startPort, int $endPort): ?array
    {
        $output = [];
        exec("ss -H -uan 2>/dev/null", $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $ports = [];
        $bindAddresses = [];
        foreach ($output as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 5) {
                continue;
            }

            foreach ([4, 3] as $index) {
                $localAddress = (string) ($parts[$index] ?? "");
                if (!preg_match('/^(.*):(\d+)$/', $localAddress, $matches)) {
                    continue;
                }

                $port = intval($matches[2]);
                if ($port < $startPort || $port > $endPort) {
                    continue;
                }

                $ports[$port] = $port;
                $bindAddresses[$matches[1]] = $matches[1];
                break;
            }
        }

        if (empty($ports)) {
            return null;
        }

        return [
            "protocols" => ["UDP"],
            "bind_addresses" => array_values($bindAddresses),
        ];
    }

    private function detectExposure(array $bindAddresses): string
    {
        if (empty($bindAddresses)) {
            return "Unknown";
        }

        foreach ($bindAddresses as $address) {
            $normalized = trim($address, "[]");
            if ($normalized === "*" || $normalized === "0.0.0.0" || $normalized === "::" || $normalized === "") {
                return "Public";
            }
            if (strpos($normalized, "127.") !== 0 && $normalized !== "::1" && $normalized !== "localhost" && strpos($normalized, "%lo") === false) {
                return "Public";
            }
        }

        return "Local only";
    }

    // ───────────────────────────────────────────────────────────────
    // External reachability scanning
    //
    // Internal mode (`ss`) only proves a service is *listening locally*; it
    // cannot see the firewall / NAT / cloud-security-group. External mode
    // probes the server's public IP from OUTSIDE via a third-party port-scan
    // API, and tests outbound ports (APNs/DNS/NTP) with a real egress probe.
    //
    // Inbound external probing uses RingQ's own checker (globaldownload/portscan.php
    // deployed on download-global.ringq.ai) — an external vantage point that can
    // TCP-connect to ANY of the server's ports, custom ones included. No third
    // party, no quota. The token must match portscan.php's PORTSCAN_TOKEN.
    // Outbound egress tests (53/123/5223) still run server-side regardless.
    // ───────────────────────────────────────────────────────────────

    private const PORT_SCAN_API_URL = "https://download-global.ringq.ai/desktops/portscan.php";
    private const PORT_SCAN_TOKEN = "CHANGE-ME-ringq-portscan-shared-secret"; // must match portscan.php

    private function buildExternalResponse(): array
    {
        $metadata = $this->getPortMetadata();
        $publicIp = $this->resolvePublicIp();
        [$tcpPorts, $udpPorts, $targetsByKey] = $this->buildScanTargets($metadata);

        $configured = self::PORT_SCAN_API_URL !== "";
        $scan = [];
        $scanError = "";
        if ($configured && $publicIp) {
            try {
                $scan = $this->externalScan($publicIp, $tcpPorts, $udpPorts);
            } catch (\Throwable $e) {
                $scanError = $e->getMessage();
            }
        }

        $ports = [];
        foreach ($metadata as $port => $info) {
            $ports[] = $this->buildExternalRow((string) $port, $info, $targetsByKey, $scan, $configured && $publicIp && $scanError === "");
        }
        usort($ports, function ($a, $b) {
            return intval($a["port"]) <=> intval($b["port"]);
        });

        $message = "";
        if (!$publicIp) {
            $message = "Could not determine the server's public IP for external scanning.";
        } elseif ($scanError !== "") {
            $message = "External scan error: {$scanError}";
        } else {
            $message = "External reachability is probed from RingQ's own checker (download-global). TCP ports give real verdicts; UDP is best-effort. Outbound egress is tested live.";
        }

        return [
            "result" => "success",
            "message" => $message,
            "content" => [
                "mode" => "external",
                "configured" => $configured,
                "public_ip" => $publicIp ?? "",
                "generated_at" => date("c"),
                "ports" => $ports,
            ],
        ];
    }

    private function buildExternalRow(string $port, array $info, array $targetsByKey, array $scan, bool $scanned): array
    {
        $exposure = $info["exposure"] ?? "";
        $isReference = ($info["status"] ?? "") === "reference";

        if ($exposure === "Outbound") {
            // Outbound ports (APNs 5223, DNS 53, NTP 123): can the server reach OUT?
            $status = $this->outboundStatus($port);
        } elseif ($exposure === "Inbound" && !$isReference) {
            if (!$scanned) {
                $status = "unknown";
            } else {
                $states = [];
                foreach (($targetsByKey[$port] ?? []) as $target) {
                    $states[] = $scan[$target] ?? "unknown";
                }
                $status = $this->aggregateExternalState($states);
            }
        } else {
            // STUN device setting, DHCP — not internet-facing, no live probe.
            $status = "reference";
        }

        return [
            "port" => $port,
            "category" => $info["category"] ?? "",
            "protocol" => $info["protocol"],
            "exposure" => $exposure,
            "service" => $info["service"],
            "listener" => $info["service"],
            "used_for" => $info["used_for"],
            "required" => $info["required"],
            "recommended_action" => $info["recommended_action"],
            "status" => $status,
            "bind_addresses" => [],
        ];
    }

    /**
     * Best-effort target selection. Discrete inbound ports are probed directly;
     * huge UDP ranges are sampled at their boundaries + midpoint (a true full
     * scan of 16384–32767 / 48000–65535 is not feasible via a hosted API).
     *
     * @return array{0: int[], 1: int[], 2: array<string, string[]>}
     */
    private function buildScanTargets(array $metadata): array
    {
        $tcp = [];
        $udp = [];
        $byKey = []; // metadata key => ["tcp:5060", "udp:5060", ...]

        foreach ($metadata as $port => $info) {
            $key = (string) $port;
            if (($info["exposure"] ?? "") !== "Inbound") {
                continue; // outbound + device-setting handled elsewhere
            }
            if (($info["status"] ?? "") === "reference") {
                continue; // e.g. DHCP 67-68 — not internet-facing
            }

            foreach ($this->protocolsFor((string) ($info["protocol"] ?? "")) as $proto) {
                foreach ($this->portsFor($key) as $p) {
                    $target = "{$proto}:{$p}";
                    $byKey[$key][] = $target;
                    if ($proto === "tcp") {
                        $tcp[$p] = $p;
                    } else {
                        $udp[$p] = $p;
                    }
                }
            }
        }

        return [array_values($tcp), array_values($udp), $byKey];
    }

    private function protocolsFor(string $protocol): array
    {
        $p = strtolower($protocol);
        $out = [];
        if (strpos($p, "tcp") !== false) {
            $out[] = "tcp";
        }
        if (strpos($p, "udp") !== false) {
            $out[] = "udp";
        }
        return $out ?: ["tcp"];
    }

    private function portsFor(string $key): array
    {
        if (strpos($key, "-") !== false) {
            [$start, $end] = array_map("intval", explode("-", $key, 2));
            if ($end <= $start) {
                return [$start];
            }
            if ($end - $start <= 4) {
                return range($start, $end);
            }
            $mid = intdiv($start + $end, 2);
            return array_values(array_unique([$start, $mid, $end]));
        }
        return [intval($key)];
    }

    private function aggregateExternalState(array $states): string
    {
        if (in_array("reachable", $states, true)) {
            return "reachable";
        }
        if (in_array("blocked", $states, true)) {
            return "blocked";
        }
        if (in_array("closed", $states, true)) {
            return "closed";
        }
        return "unknown";
    }

    /**
     * Probe the public IP via RingQ's own checker (portscan.php on
     * download-global). One call per protocol with the exact ports requested.
     *
     * @return array<string, string> keyed "proto:port" → reachable|closed|blocked|unknown
     */
    private function externalScan(string $ip, array $tcpPorts, array $udpPorts): array
    {
        $out = [];
        if (!empty($tcpPorts)) {
            $out = array_merge($out, $this->ownCheckerScan($ip, $tcpPorts, "tcp"));
        }
        if (!empty($udpPorts)) {
            $out = array_merge($out, $this->ownCheckerScan($ip, $udpPorts, "udp"));
        }
        return $out;
    }

    private function ownCheckerScan(string $ip, array $ports, string $proto): array
    {
        if (empty($ports)) {
            return [];
        }

        $url = self::PORT_SCAN_API_URL
            . "?token=" . urlencode(self::PORT_SCAN_TOKEN)
            . "&ip=" . urlencode($ip)
            . "&proto=" . $proto
            . "&ports=" . urlencode(implode(",", $ports));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 200,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== "") {
            throw new \RuntimeException("Checker request failed: {$err}");
        }
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException("Checker returned HTTP {$code}");
        }

        $decoded = json_decode((string) $body, true);
        if (($decoded["result"] ?? "") !== "success" || !is_array($decoded["results"] ?? null)) {
            throw new \RuntimeException((string) ($decoded["message"] ?? "Checker returned no results"));
        }

        $map = ["open" => "reachable", "closed" => "closed", "filtered" => "blocked", "unknown" => "unknown"];
        $out = [];
        foreach ($decoded["results"] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $portNum = (int) ($r["port"] ?? 0);
            $state = strtolower((string) ($r["state"] ?? "unknown"));
            if ($portNum > 0) {
                $out["{$proto}:{$portNum}"] = $map[$state] ?? "unknown";
            }
        }
        return $out;
    }

    private function resolvePublicIp(): ?string
    {
        try {
            $fqdn = (string) ($this->config->application->publicUrl ?? "");
        } catch (\Throwable $e) {
            $fqdn = "";
        }
        if ($fqdn !== "") {
            $ip = gethostbyname($fqdn);
            if ($ip !== $fqdn && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        $ip = trim((string) @shell_exec("curl -s --max-time 5 https://api.ipify.org 2>/dev/null"));
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function outboundStatus(string $port): string
    {
        switch ($port) {
            case "5223":
                return $this->tcpEgress("gateway.push.apple.com", 5223) ? "egress_ok" : "egress_blocked";
            case "53":
                return $this->dnsEgress("1.1.1.1") ? "egress_ok" : "egress_blocked";
            case "123":
                return $this->ntpEgress("pool.ntp.org") ? "egress_ok" : "egress_blocked";
            default:
                return "unknown";
        }
    }

    private function tcpEgress(string $host, int $port, int $timeout = 5): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            @fclose($fp);
            return true;
        }
        return false;
    }

    private function dnsEgress(string $resolver): bool
    {
        // Minimal DNS A-query for "dns.google" — any UDP reply means :53 egress works.
        $name = "";
        foreach (explode(".", "dns.google") as $label) {
            $name .= chr(strlen($label)) . $label;
        }
        $query = "\xAA\xAA\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00" . $name . "\x00\x00\x01\x00\x01";
        return $this->udpProbe($resolver, 53, $query);
    }

    private function ntpEgress(string $server): bool
    {
        $packet = "\x1b" . str_repeat("\x00", 47); // NTPv3 client request
        return $this->udpProbe($server, 123, $packet);
    }

    private function udpProbe(string $host, int $port, string $payload, int $timeout = 3): bool
    {
        $sock = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$sock) {
            return false;
        }
        @stream_set_timeout($sock, $timeout);
        @fwrite($sock, $payload);
        $resp = @fread($sock, 512);
        @fclose($sock);
        return is_string($resp) && strlen($resp) > 0;
    }

    private function getPortMetadata(): array
    {
        return $this->getRelayPortMetadata() + [
            "5060" => ["category" => "SIP SIGNALING", "protocol" => "UDP/TCP", "exposure" => "Inbound", "service" => "SIP Signaling", "used_for" => "Standard voice signaling for SIP phones and SIP trunks.", "required" => "Required for SIP phones and SIP trunks.", "recommended_action" => "Allow inbound only from trusted voice networks where possible."],
            "5061" => ["category" => "SIP SIGNALING", "protocol" => "TCP", "exposure" => "Inbound", "service" => "Secure SIP Signaling", "used_for" => "Encrypted SIP TLS signaling for SIP trunks.", "required" => "Required when SIP TLS trunks are used.", "recommended_action" => "Allow inbound when secure trunk signaling is enabled."],
            "443" => ["category" => "SECURE WEB / WSS", "protocol" => "TCP", "exposure" => "Inbound", "service" => "Secure Web Access", "used_for" => "HTTPS and secure signaling for mobile, desktop, WebApp, and admin access.", "required" => "Required for secure RingQ access.", "recommended_action" => "Keep open for RingQ clients and administration."],
            "7443" => ["category" => "SECURE WEB / WSS", "protocol" => "TCP", "exposure" => "Inbound", "service" => "Secure Realtime Connection", "used_for" => "Alternate secure realtime connection for mobile, desktop, and WebApp.", "required" => "Required when clients use the alternate secure connection.", "recommended_action" => "Keep open for RingQ clients when this connection is enabled."],
            "8443" => ["category" => "SECURE WEB / WSS", "protocol" => "TCP", "exposure" => "Inbound", "service" => "RingQ Backend", "used_for" => "Backend HTTPS access for mobile, desktop, WebApp, and admin users.", "required" => "Required for RingQ client and admin access.", "recommended_action" => "Keep open for RingQ application access."],
            "5004-5005" => ["category" => "RTP MEDIA", "protocol" => "UDP", "exposure" => "Inbound", "service" => "RTP/RTCP Standard Media", "used_for" => "Standard RTP and RTCP media for SIP phones.", "required" => "Required for SIP phone media when this standard range is used.", "recommended_action" => "Allow inbound media only as required by the voice deployment.", "scan_range" => true],
            "9000-10999" => ["category" => "RTP MEDIA", "protocol" => "UDP", "exposure" => "Inbound", "service" => "Trunk RTP Media", "used_for" => "RTP media range for SIP trunks.", "required" => "Required for SIP trunk media.", "recommended_action" => "Allow inbound media from trusted trunk providers.", "scan_range" => true],
            "16384-32767" => ["category" => "RTP MEDIA", "protocol" => "UDP", "exposure" => "Inbound", "service" => "Primary RTP Voice Media", "used_for" => "Primary voice media range for SIP phones, mobile, and desktop clients.", "required" => "Required for active voice calls.", "recommended_action" => "Allow inbound media according to the voice network policy.", "scan_range" => true],
            "48000-65535" => ["category" => "RTP MEDIA", "protocol" => "UDP", "exposure" => "Inbound", "service" => "WebRTC Media", "used_for" => "WebRTC audio and video media for the WebApp.", "required" => "Required for WebApp audio/video sessions.", "recommended_action" => "Allow inbound media when WebRTC calling or video is enabled.", "scan_range" => true],
            "9999" => ["category" => "PUSH NOTIFICATIONS", "protocol" => "TCP", "exposure" => "Inbound", "service" => "Android Push Notification", "used_for" => "Android push notification support for the mobile app.", "required" => "Required for Android mobile push notifications.", "recommended_action" => "Keep open when Android mobile notifications are enabled."],
            "5223" => ["category" => "PUSH NOTIFICATIONS", "protocol" => "TCP", "exposure" => "Outbound", "service" => "Apple Push Notification", "used_for" => "Apple APNs connectivity for iOS push notifications.", "required" => "Required for iOS push notifications.", "recommended_action" => "Allow outbound access for iOS push notifications.", "status" => "reference"],
            "9886" => ["category" => "RINGQ MEET", "protocol" => "TCP", "exposure" => "Inbound", "service" => "RingQ Meet Primary", "used_for" => "Primary RingQ Meet connection.", "required" => "Required for RingQ Meet.", "recommended_action" => "Keep open when RingQ Meet is enabled."],
            // 8445 was "RingQ Meet Signaling" for the PHP Meet application, which is
            // permanently retired (see licensing/setup.php) — nothing listens on it.
            //
            // These two are what ops/meet/runtime/ringq-meet-port-guard deliberately
            // ALLOWS through for Meet media; the rest of the Meet stack (control plane
            // 6000, gateway 8444, LiveKit HTTP 7880, transcriber 6011) is DROPPED from
            // non-loopback by that same guard and must NOT be advertised here.
            "7881" => ["category" => "RINGQ MEET", "protocol" => "TCP", "exposure" => "Inbound", "service" => "RingQ Meet Media (TCP fallback)", "used_for" => "RingQ Meet audio/video when a participant's network blocks UDP.", "required" => "Required for RingQ Meet participants behind UDP-blocking firewalls.", "recommended_action" => "Keep open when RingQ Meet is enabled."],
            "3478" => ["category" => "RINGQ MEET", "protocol" => "UDP", "exposure" => "Inbound", "service" => "RingQ Meet STUN/TURN", "used_for" => "RingQ Meet NAT traversal for media.", "required" => "Required for RingQ Meet media.", "recommended_action" => "Keep open when RingQ Meet is enabled."],
            "3478-3479" => ["category" => "STUN / NAT TRAVERSAL", "protocol" => "TCP", "exposure" => "Device setting", "service" => "STUN/NAT Traversal", "used_for" => "STUN server setting for SIP phones.", "required" => "Configured on SIP phones; not a firewall rule.", "recommended_action" => "Configure supported phones to use the RingQ STUN servers when needed.", "status" => "reference"],
            "53" => ["category" => "INFRASTRUCTURE", "protocol" => "UDP", "exposure" => "Outbound", "service" => "DNS", "used_for" => "Outbound DNS name resolution for the server.", "required" => "Required for outbound name resolution.", "recommended_action" => "Allow outbound DNS to trusted resolvers.", "status" => "reference"],
            "67-68" => ["category" => "INFRASTRUCTURE", "protocol" => "UDP", "exposure" => "Inbound", "service" => "DHCP", "used_for" => "DHCP dynamic IP addressing.", "required" => "Required when the server obtains its address via DHCP.", "recommended_action" => "Typically managed by the network; not a firewall rule.", "status" => "reference"],
            "123" => ["category" => "INFRASTRUCTURE", "protocol" => "UDP", "exposure" => "Outbound", "service" => "NTP", "used_for" => "Outbound NTP time synchronisation.", "required" => "Required for outbound time sync.", "recommended_action" => "Allow outbound NTP to trusted time servers.", "status" => "reference"],
            "6010" => ["category" => "TUNNEL", "protocol" => "TCP", "exposure" => "Inbound", "service" => "RingQ Tunnel", "used_for" => "SIP signaling over TCP, forwarded to the SIP port 5060, for networks that block 5060. Carries no audio.", "required" => "Required when RingQ tunneling is enabled.", "recommended_action" => "Allow inbound from trusted tunnel clients.", "internal_undetected" => "reference"],
        ];
    }

    // The call relay (TURN, app/scripts/turn/ringq-turn-setup.sh) is listed only where it is
    // installed. Its ports come from /etc/ringq/.env because the setup moves to 3479 when RingQ
    // Meet already holds 3478.
    private function getRelayPortMetadata(): array
    {
        $env = [];
        foreach ((array) @file("/etc/ringq/.env", FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^(TURN_[A-Z_]+|TURNS_PORT)=(.*)$/', (string) $line, $m)) {
                $env[$m[1]] = trim($m[2]);
            }
        }
        if (empty($env["TURN_SECRET"])) {
            return [];
        }
        $port = ctype_digit($env["TURN_PORT"] ?? "") ? $env["TURN_PORT"] : "3478";
        $tls = ctype_digit($env["TURNS_PORT"] ?? "") ? $env["TURNS_PORT"] : "5349";
        $min = ctype_digit($env["TURN_MIN_PORT"] ?? "") ? $env["TURN_MIN_PORT"] : "34000";
        $max = ctype_digit($env["TURN_MAX_PORT"] ?? "") ? $env["TURN_MAX_PORT"] : "35999";
        return [
            $port => ["category" => "CALL RELAY", "protocol" => "UDP/TCP", "exposure" => "Inbound", "service" => "Call Relay (TURN)", "used_for" => "Carries call audio for the mobile apps and WebApp when their network blocks a direct path (NAT, mobile data, strict Wi-Fi).", "required" => "Required for calls from behind restrictive NAT or firewalls.", "recommended_action" => "Keep open to the internet."],
            $tls => ["category" => "CALL RELAY", "protocol" => "TCP", "exposure" => "Inbound", "service" => "Call Relay over TLS (TURNS)", "used_for" => "Call audio relayed over TLS for networks that only allow web-style encrypted traffic.", "required" => "Required for calls from networks that block UDP.", "recommended_action" => "Keep open to the internet."],
            "{$min}-{$max}" => ["category" => "CALL RELAY", "protocol" => "UDP", "exposure" => "Inbound", "service" => "Call Relay Media", "used_for" => "Relay ports the call relay uses toward this server's voice media.", "required" => "Required whenever the call relay is used.", "recommended_action" => "Allow inbound UDP on this range.", "scan_range" => true],
        ];
    }
}
