<?php
declare(strict_types=1);

/**
 * Call Debug — Global Settings ▸ Advanced.
 *
 * FreeSWITCH writes /var/log/freeswitch/freeswitch.log continuously; this
 * controller never starts or stops logging. Enabling debug truncates the
 * file so a fresh log is captured from that point; disabling hands back
 * the log captured during the debug session.
 *
 *   GET  /calldebug/status    → {"debug":"0|1","started":<unix ts|null>}
 *   POST /calldebug/toggle    → debug=1 clears the log, debug=0 returns a download URL
 *   GET  /calldebug/tail      → ?offset=N, returns log bytes appended since N (live view)
 *   GET  /calldebug/download  → streams freeswitch.log as an attachment
 */
class CalldebugController extends ControllerBase
{
    private const LOG_FILE = "/var/log/freeswitch/freeswitch.log";

    private function jsonResponse($data)
    {
        $this->view->disable();
        return $this->response->setJsonContent($data);
    }

    private function requireSession()
    {
        if (!$this->session->get("auth")) {
            return $this->jsonResponse([
                "result"  => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
        }
        return null;
    }

    private function domain(): string
    {
        $session = $this->session->get("auth");
        return $session['subdomain'] ?? 'default';
    }

    public function statusAction()
    {
        if ($denied = $this->requireSession()) {
            return $denied;
        }

        $domain  = $this->domain();
        $debug   = $this->redis->get("call_debug:{$domain}");
        $started = $this->redis->get("call_debug_started:{$domain}");

        return $this->jsonResponse([
            "result"  => "success",
            "debug"   => $debug === "1" ? "1" : "0",
            "started" => $started ? (int) $started : null,
        ]);
    }

    public function toggleAction()
    {
        if ($denied = $this->requireSession()) {
            return $denied;
        }

        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                "result"  => "error",
                "message" => "POST method required. (Code CD1000)",
            ]);
        }

        $domain     = $this->domain();
        $key        = "call_debug:{$domain}";
        $startedKey = "call_debug_started:{$domain}";

        $debug = $this->request->getPost("debug", "string", "0");
        $debug = ($debug === "1" || $debug === "true") ? "1" : "0";

        if ($debug === "1") {
            clearstatcache(true, self::LOG_FILE);
            $sizeBefore = file_exists(self::LOG_FILE) ? filesize(self::LOG_FILE) : 0;
            exec("truncate -s 0 " . escapeshellarg(self::LOG_FILE) . " 2>&1", $output, $rc);
            clearstatcache(true, self::LOG_FILE);
            $sizeAfter = file_exists(self::LOG_FILE) ? filesize(self::LOG_FILE) : 0;
            // FreeSWITCH may append between truncate and stat, so only
            // require the size to have shrunk, not to be exactly 0.
            if ($rc !== 0 || ($sizeBefore > 0 && $sizeAfter >= $sizeBefore)) {
                return $this->jsonResponse([
                    "result"  => "error",
                    "message" => "Failed to clear the call log. (Code CD1001)",
                ]);
            }

            $this->redis->set($key, "1");
            $this->redis->set($startedKey, (string) time());

            return $this->jsonResponse([
                "result"  => "success",
                "debug"   => "1",
                "message" => "Debug mode enabled. Call log cleared.",
            ]);
        }

        $this->redis->set($key, "0");
        $this->redis->del($startedKey);

        return $this->jsonResponse([
            "result"   => "success",
            "debug"    => "0",
            "message"  => "Debug mode disabled.",
            "download" => "/calldebug/download",
        ]);
    }

    public function tailAction()
    {
        if ($denied = $this->requireSession()) {
            return $denied;
        }

        $offset = (int) $this->request->getQuery("offset", "int", -1);

        clearstatcache(true, self::LOG_FILE);
        if (!file_exists(self::LOG_FILE) || !is_readable(self::LOG_FILE)) {
            return $this->jsonResponse([
                "result" => "success",
                "offset" => 0,
                "size"   => 0,
                "data"   => "",
            ]);
        }

        $size = filesize(self::LOG_FILE);
        if ($offset < 0 || $offset > $size) {
            // First poll, or the file was truncated under us — start near the
            // end instead of replaying the whole log to the browser.
            $offset = max(0, $size - 16384);
        }

        $chunk = "";
        if ($size > $offset) {
            $fh = fopen(self::LOG_FILE, "rb");
            if ($fh !== false) {
                fseek($fh, $offset);
                // Cap each poll so a burst of logging can't produce a huge response.
                $chunk = (string) fread($fh, min($size - $offset, 262144));
                fclose($fh);
            }
        }

        return $this->jsonResponse([
            "result" => "success",
            "offset" => $offset + strlen($chunk),
            "size"   => $size,
            // Strip invalid UTF-8 so json_encode never fails on binary noise.
            "data"   => mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'),
        ]);
    }

    public function downloadAction()
    {
        $this->view->disable();

        $session = $this->session->get("auth");
        if (!$session) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->response->setContent("Unauthorized");
        }

        clearstatcache(true, self::LOG_FILE);
        if (!file_exists(self::LOG_FILE) || !is_readable(self::LOG_FILE)) {
            $this->response->setStatusCode(404, "Not Found");
            return $this->response->setContent("Log file not found");
        }

        $domain   = $session['subdomain'] ?? 'default';
        $filename = "call-debug-{$domain}-" . date("Ymd-His") . ".log";

        // Stream straight to the client — a busy PBX writes hundreds of MB
        // between enable and disable, so the log must never be loaded into
        // PHP memory (file_get_contents would hit the memory limit).
        $this->response->setHeader('Content-Type', 'application/octet-stream');
        $this->response->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        $this->response->setHeader('Content-Length', (string) filesize(self::LOG_FILE));
        $this->response->sendHeaders();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile(self::LOG_FILE);
        exit;
    }
}
