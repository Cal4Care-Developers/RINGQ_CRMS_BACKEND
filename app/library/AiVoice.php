<?php

/**
 * AI Voice (the AI agent on 5555): checks that this server can reach OpenAI with the
 * tenant's saved token, the same way the FreeSWITCH module does on a call.
 *
 * Web requests don't autoload app/library, so callers require_once this file.
 */
class AiVoice
{
    const HOST = "api.openai.com";
    // Same model and URL the 5555 dial plan hands to uuid_openai_audio_stream
    const MODEL = "gpt-realtime";

    /**
     * Runs each step and stops at the first one that fails, since later steps depend on it.
     * Returns ["ok" => bool, "steps" => [["key","label","ok","detail","ms"], ...], "checkedAt" => epoch].
     */
    public static function connectionTest(string $token, string $model = self::MODEL): array
    {
        $steps = [];
        $add = function (string $key, string $label, $ok, string $detail, float $start) use (&$steps) {
            $steps[] = [
                "key" => $key,
                "label" => $label,
                "ok" => $ok,
                "detail" => self::mask($detail),
                "ms" => (int) round((microtime(true) - $start) * 1000),
            ];
            return $ok;
        };
        $done = function () use (&$steps) {
            $ok = $steps !== [];
            foreach ($steps as $step) {
                if ($step["ok"] === false) $ok = false;
            }
            return ["ok" => $ok, "steps" => $steps, "checkedAt" => time(), "model" => self::MODEL];
        };

        $t = microtime(true);
        if (!$add("token", "OpenAI token saved", $token !== "", $token !== ""
            ? "Token ending " . substr($token, -4) . " is saved."
            : "No OpenAI token is saved. Enter it on Global Settings > AI agent > Settings and save.", $t)) {
            return $done();
        }

        $t = microtime(true);
        $ips = @gethostbynamel(self::HOST);
        if (!$add("dns", "Server can find OpenAI", !empty($ips), !empty($ips)
            ? self::HOST . " resolves to " . implode(", ", array_slice($ips, 0, 3)) . "."
            : "This server could not look up " . self::HOST . ". Check the server's DNS settings.", $t)) {
            return $done();
        }

        // Plain HTTPS with the token: proves the network path, TLS and that OpenAI accepts the key
        $t = microtime(true);
        [$code, $body, $err] = self::httpsGet("/v1/models", $token);
        if ($code === 0) {
            $add("https", "Server can reach OpenAI", false, "Could not connect to " . self::HOST . ":443 (" . $err . "). A firewall may be blocking outgoing HTTPS from this server.", $t);
            return $done();
        }
        $add("https", "Server can reach OpenAI", true, "Connected over HTTPS (HTTP " . $code . ").", $t);

        $t = microtime(true);
        $json = json_decode($body, true);
        if ($code !== 200) {
            $add("key", "OpenAI accepts the token", false, self::explainHttp($code, $json), $t);
            return $done();
        }
        $ids = array_map(function ($m) { return (string) ($m["id"] ?? ""); }, (array) ($json["data"] ?? []));
        $add("key", "OpenAI accepts the token", true, "The token is valid.", $t);

        $t = microtime(true);
        // Not listed is only a warning: the realtime step below is the real test
        $hasModel = in_array($model, $ids, true);
        $add("model", "Token can use " . $model, $hasModel ? true : null, $hasModel
            ? $model . " is available to this token's project."
            : $model . " is not listed for this token's OpenAI project. If the next step fails, allow the realtime models for the project at platform.openai.com (Settings > Project > Limits).", $t);

        // The realtime WebSocket the module opens on every call, then one tiny text reply.
        // The reply is what fails when the OpenAI account has no credit left.
        $t = microtime(true);
        $ws = self::realtimeProbe($token, $model);
        $add("realtime", "Realtime voice connection", $ws["connected"], $ws["connectDetail"], $t);
        if ($ws["connected"]) {
            $steps[] = [
                "key" => "reply",
                "label" => "OpenAI answers",
                "ok" => $ws["replied"],
                "detail" => self::mask($ws["replyDetail"]),
                "ms" => $ws["replyMs"],
            ];
        }
        return $done();
    }

    const LOG_MAX = 500;

    /**
     * The tenant's AI Voice log (Global Settings > AI agent > Log): connection tests, restarts
     * and call events, newest first, in Redis list ai:<subdomain>-log.
     */
    public static function log($redis, string $subdomain, string $kind, string $message, array $data = [], ?int $at = null): void
    {
        try {
            $redis->lPush("ai:" . $subdomain . "-log", json_encode([
                "at" => $at ?? time(),
                "kind" => $kind,
                "message" => self::mask($message),
                "data" => $data,
            ]));
            $redis->lTrim("ai:" . $subdomain . "-log", 0, self::LOG_MAX - 1);
        } catch (\Throwable $e) {
            error_log("AiVoice::log: " . $e->getMessage());
        }
    }

    public static function readLog($redis, string $subdomain, int $limit = 200): array
    {
        $rows = [];
        foreach ((array) $redis->lRange("ai:" . $subdomain . "-log", 0, max(1, $limit) - 1) as $raw) {
            $row = json_decode((string) $raw, true);
            if (is_array($row)) $rows[] = $row;
        }
        return $rows;
    }

    public static function firstProblem(array $result): string
    {
        foreach ($result["steps"] ?? [] as $step) {
            if ($step["ok"] === false) return $step["label"] . ": " . $step["detail"];
        }
        return "";
    }

    const FS_LOG = "/var/log/freeswitch/freeswitch.log";

    /**
     * What happened between each call and OpenAI, read from the RingQ Call Services log
     * (mod_openai_audio_stream logs there). Results are kept in Redis for 30 days because the
     * log rotates. $calls: uuid => start epoch. Returns uuid => ["result","reason","errors","lines"].
     */
    public static function callResults($redis, string $subdomain, array $calls): array
    {
        $out = [];
        $todo = [];
        foreach ($calls as $uuid => $start) {
            if (!preg_match('/^[0-9a-f-]{36}$/', (string) $uuid)) continue;
            $cached = json_decode((string) $redis->get("ai:" . $subdomain . "-call:" . $uuid), true);
            if (is_array($cached)) {
                $out[$uuid] = $cached;
            } else {
                $todo[$uuid] = (int) $start;
            }
        }
        if ($todo) {
            $lines = self::logLinesFor(array_keys($todo), min($todo));
            foreach ($todo as $uuid => $start) {
                $result = self::summarize($lines[$uuid] ?? []);
                $out[$uuid] = $result;
                // A call still in the current log could get more lines; cache once it is old
                if ($result["result"] !== "unknown" && time() - $start > 600) {
                    $redis->setex("ai:" . $subdomain . "-call:" . $uuid, 30 * 86400, json_encode($result));
                }
            }
        }
        return $out;
    }

    /** uuid => the module's log lines for that call, oldest first, keys masked. */
    private static function logLinesFor(array $uuids, int $since): array
    {
        $files = [];
        foreach (array_merge([self::FS_LOG], glob(self::FS_LOG . ".*") ?: []) as $file) {
            if (preg_match('/\.(gz|bz2|xz)$/', $file)) continue;
            $mtime = @filemtime($file);
            // the log may not be readable by the web user; filemtime still works on the dir entry
            if ($mtime === false || $mtime >= $since - 60) $files[] = $file;
        }
        if (!$files) return [];
        $list = tempnam(sys_get_temp_dir(), "aiuuid");
        file_put_contents($list, implode("\n", $uuids) . "\n");
        // Only the AI module's own lines: never the dial plan's EXECUTE lines, which carry the key
        $cmd = "/usr/bin/sudo -n /bin/grep -h -F -f " . escapeshellarg($list) . " " . implode(" ", array_map("escapeshellarg", $files))
            . " 2>/dev/null | /bin/grep -E 'openai_audio_stream|openai_audio_streamer|processMessage|OPENAI_API_KEY|connection (closed|error)' | /bin/grep -v EXECUTE | /usr/bin/tail -n 20000";
        $raw = (string) @shell_exec("timeout 20 " . $cmd);
        @unlink($list);
        $byUuid = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === "") continue;
            foreach ($uuids as $uuid) {
                if (strpos($line, $uuid) !== false) {
                    $byUuid[$uuid][] = self::mask($line);
                    break;
                }
            }
        }
        foreach ($byUuid as $uuid => $lines) {
            // rotated files come after the current one in the grep; order by the timestamp in each line
            usort($byUuid[$uuid], function ($a, $b) {
                return strcmp(self::lineTime($a), self::lineTime($b));
            });
        }
        return $byUuid;
    }

    private static function lineTime(string $line): string
    {
        return preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?/', $line, $m) ? $m[0] : "";
    }

    /** Plain-language result of one AI call from its module log lines. */
    public static function summarize(array $lines): array
    {
        $errors = [];
        $noKey = false;
        $connError = 0;
        foreach ($lines as $line) {
            if (strpos($line, "OPENAI_API_KEY is not set") !== false) $noKey = true;
            if (preg_match('/connection error/i', $line)) $connError++;
            if (preg_match('/processMessage - error:\s*(\{.*\})\s*$/', $line, $m)) {
                $json = json_decode($m[1], true);
                $err = is_array($json) ? ($json["error"] ?? $json) : [];
                $key = (string) ($err["code"] ?? $err["type"] ?? "error") . "|" . (string) ($err["message"] ?? "");
                $errors[$key] = ($errors[$key] ?? 0) + 1;
            }
        }
        $first = $errors ? array_key_first($errors) : "";
        [$code, $message] = $first !== "" ? explode("|", $first, 2) : ["", ""];
        if ($noKey || stripos($message, "Missing bearer") !== false) {
            $result = "failed";
            $reason = "No OpenAI token on this call. Save the token on Global Settings > AI agent > Settings.";
        } elseif ($code === "invalid_api_key" || stripos($message, "Incorrect API key") !== false) {
            $result = "failed";
            $reason = "OpenAI rejected the token. Save a new API key and test the connection.";
        } elseif ($code === "insufficient_quota") {
            $result = "failed";
            $reason = "The OpenAI account has no credit left. Add credit at platform.openai.com > Billing.";
        } elseif ($code === "rate_limit_exceeded") {
            $result = "failed";
            $reason = "OpenAI rate limited the call.";
        } elseif ($errors) {
            $result = "failed";
            $reason = "OpenAI returned an error: " . ($message !== "" ? $message : $code);
        } elseif ($connError > 0) {
            $result = "failed";
            $reason = "Could not connect to OpenAI from this server.";
        } elseif ($lines) {
            $result = "connected";
            $reason = "Connected to OpenAI.";
        } else {
            $result = "unknown";
            $reason = "No AI Voice entries in the log for this call (the log may have rotated).";
        }
        $tail = array_slice($lines, -40);
        return [
            "result" => $result,
            "reason" => self::mask($reason),
            "errors" => array_sum($errors),
            "lines" => array_map(function ($l) { return mb_substr($l, 0, 600); }, $tail),
        ];
    }

    /** sk-... keys never leave the server, even inside OpenAI's own error text. */
    public static function mask(string $text): string
    {
        return preg_replace_callback('/sk-[A-Za-z0-9_\-*]{6,}/', function ($m) {
            return "sk-..." . substr($m[0], -4);
        }, $text);
    }

    private static function explainHttp(int $code, $json): string
    {
        $message = is_array($json) ? (string) ($json["error"]["message"] ?? "") : "";
        $type = is_array($json) ? (string) ($json["error"]["code"] ?? $json["error"]["type"] ?? "") : "";
        $why = [
            401 => "OpenAI rejected the token. Copy a new API key from platform.openai.com > API keys, save it, and test again.",
            403 => "OpenAI refused this token (HTTP 403). The key's project may not allow this, or OpenAI does not serve the server's country.",
            429 => "OpenAI is limiting this token (HTTP 429). Check billing and credit at platform.openai.com > Billing.",
        ][$code] ?? ("OpenAI answered HTTP " . $code . ".");
        if ($type === "insufficient_quota") {
            $why = "The OpenAI account has no credit left. Add credit at platform.openai.com > Billing.";
        }
        return $why . ($message !== "" ? " OpenAI said: " . $message : "");
    }

    private static function httpsGet(string $path, string $token): array
    {
        $ch = curl_init("https://" . self::HOST . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $token],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$body === false ? 0 : $code, (string) $body, $err];
    }

    private static function realtimeProbe(string $token, string $model): array
    {
        $r = ["connected" => false, "connectDetail" => "", "replied" => false, "replyDetail" => "", "replyMs" => 0];
        $ctx = stream_context_create(["ssl" => ["verify_peer" => true, "verify_peer_name" => true, "peer_name" => self::HOST, "SNI_enabled" => true]]);
        $sock = @stream_socket_client("tls://" . self::HOST . ":443", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            $r["connectDetail"] = "Could not open a secure connection to " . self::HOST . " (" . ($errstr ?: "error " . $errno) . ").";
            return $r;
        }
        stream_set_timeout($sock, 10);
        $key = base64_encode(random_bytes(16));
        fwrite($sock, "GET /v1/realtime?model=" . rawurlencode($model) . " HTTP/1.1\r\n"
            . "Host: " . self::HOST . "\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . $key . "\r\nSec-WebSocket-Version: 13\r\n"
            . "Authorization: Bearer " . $token . "\r\n\r\n");
        $head = "";
        while (strpos($head, "\r\n\r\n") === false && !feof($sock) && strlen($head) < 16384) {
            $line = fgets($sock);
            if ($line === false) break;
            $head .= $line;
        }
        if (!preg_match('#^HTTP/\d\.\d (\d{3})#', $head, $m)) {
            fclose($sock);
            $r["connectDetail"] = "OpenAI did not answer the realtime connection in time.";
            return $r;
        }
        $code = (int) $m[1];
        if ($code !== 101) {
            $body = "";
            if (preg_match('/content-length:\s*(\d+)/i', $head, $len) && (int) $len[1] > 0) {
                $body = (string) stream_get_contents($sock, min((int) $len[1], 8192));
            }
            fclose($sock);
            $r["connectDetail"] = "Realtime connection refused. " . self::explainHttp($code, json_decode($body, true));
            return $r;
        }

        $event = self::readEvent($sock, 8);
        if (($event["type"] ?? "") !== "session.created") {
            fclose($sock);
            $r["connectDetail"] = "Connected, but OpenAI did not start a session"
                . (isset($event["error"]["message"]) ? ": " . $event["error"]["message"] : ".");
            return $r;
        }
        $r["connected"] = true;
        $r["connectDetail"] = "Opened a realtime session (" . ($event["session"]["model"] ?? $model) . ").";

        $t = microtime(true);
        self::sendText($sock, json_encode([
            "type" => "response.create",
            "response" => [
                "output_modalities" => ["text"],
                "max_output_tokens" => 16,
                "instructions" => "Reply with the single word OK.",
            ],
        ]));
        $deadline = microtime(true) + 15;
        $text = "";
        while (microtime(true) < $deadline) {
            $event = self::readEvent($sock, (int) max(1, ceil($deadline - microtime(true))));
            if ($event === null) break;
            $type = (string) ($event["type"] ?? "");
            if ($type === "response.output_text.delta" || $type === "response.text.delta") {
                $text .= (string) ($event["delta"] ?? "");
            } elseif ($type === "error") {
                $r["replyDetail"] = self::explainEventError($event["error"] ?? []);
                break;
            } elseif ($type === "response.done") {
                $status = (string) ($event["response"]["status"] ?? "");
                if ($status === "completed" || $status === "incomplete") {
                    $r["replied"] = true;
                    $r["replyDetail"] = "OpenAI replied" . ($text !== "" ? " \"" . trim(mb_substr($text, 0, 40)) . "\"" : "") . ". AI Voice can talk to OpenAI.";
                } else {
                    $r["replyDetail"] = self::explainEventError($event["response"]["status_details"]["error"] ?? [], $status);
                }
                break;
            }
        }
        if ($r["replyDetail"] === "") {
            $r["replyDetail"] = "OpenAI accepted the connection but did not reply in time.";
        }
        $r["replyMs"] = (int) round((microtime(true) - $t) * 1000);
        @fwrite($sock, self::frame("\x03\xe8", 0x8));
        fclose($sock);
        return $r;
    }

    private static function explainEventError($error, string $status = ""): string
    {
        $code = (string) ($error["code"] ?? $error["type"] ?? "");
        $message = (string) ($error["message"] ?? "");
        if ($code === "insufficient_quota") {
            return "The OpenAI account has no credit left, so calls connect and then stay silent. Add credit at platform.openai.com > Billing.";
        }
        if ($code === "rate_limit_exceeded") {
            return "OpenAI is rate limiting this token. Check the project's limits at platform.openai.com.";
        }
        return "OpenAI did not reply" . ($status !== "" ? " (" . $status . ")" : "") . ($message !== "" ? ": " . $message : ".");
    }

    // Minimal WebSocket client: text frames from the server are unmasked; ours must be masked.

    private static function frame(string $payload, int $opcode = 0x1): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len < 126) {
            $head .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $head .= chr(0x80 | 126) . pack("n", $len);
        } else {
            $head .= chr(0x80 | 127) . pack("J", $len);
        }
        $mask = random_bytes(4);
        $masked = "";
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        return $head . $mask . $masked;
    }

    private static function sendText($sock, string $text): void
    {
        fwrite($sock, self::frame($text));
    }

    private static function readExact($sock, int $n): ?string
    {
        $buf = "";
        while (strlen($buf) < $n) {
            $chunk = fread($sock, $n - strlen($buf));
            if ($chunk === false || $chunk === "") {
                $meta = stream_get_meta_data($sock);
                if ($meta["timed_out"] || feof($sock)) return null;
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Next JSON event, or null on timeout, close or a broken frame. */
    private static function readEvent($sock, int $timeout): ?array
    {
        stream_set_timeout($sock, $timeout);
        $message = "";
        while (true) {
            $h = self::readExact($sock, 2);
            if ($h === null) return null;
            $fin = (ord($h[0]) & 0x80) !== 0;
            $opcode = ord($h[0]) & 0x0f;
            $len = ord($h[1]) & 0x7f;
            if ($len === 126) {
                $ext = self::readExact($sock, 2);
                if ($ext === null) return null;
                $len = unpack("n", $ext)[1];
            } elseif ($len === 127) {
                $ext = self::readExact($sock, 8);
                if ($ext === null) return null;
                $len = unpack("J", $ext)[1];
            }
            if ($len > 4 * 1024 * 1024) return null;
            $payload = $len > 0 ? self::readExact($sock, $len) : "";
            if ($payload === null) return null;
            if ($opcode === 0x8) {
                $reason = strlen($payload) > 2 ? substr($payload, 2) : "";
                return ["type" => "error", "error" => ["message" => "OpenAI closed the connection" . ($reason !== "" ? ": " . $reason : ""), "code" => "closed"]];
            }
            if ($opcode === 0x9) {
                fwrite($sock, self::frame($payload, 0xA));
                continue;
            }
            if ($opcode === 0xA) continue;
            $message .= $payload;
            if ($fin) {
                $decoded = json_decode($message, true);
                return is_array($decoded) ? $decoded : ["type" => "unknown"];
            }
        }
    }
}
