<?php

namespace Library;

use Phalcon\Di\Injectable;

/**
 * Uploads files to the tenant's "Time Machine" backup storage (AWS S3 / FTP /
 * SFTP). Credentials come from the box-wide redis key `backup_settings`
 * (storage + storageConfig), the same settings the backup feature uses.
 *
 * Used by Storage archiving: instead of deleting old files during cleanup, they
 * are uploaded here (then removed locally). Every upload is verified by the
 * command's exit status; a failed upload returns false so the caller keeps the
 * local file. Secrets are env-injected / temp-filed and never logged.
 *
 * See docs/PLAN-storage-archive.md.
 */
class RemoteStorage extends Injectable
{
    /** Per-file upload timeout (seconds). */
    const CMD_TIMEOUT = 120;

    /** Remote prefix so archives never collide with scheduled *.zip backups. */
    const PREFIX = "ringq-archive";

    /** Decode the box-wide backup settings. */
    private function settings(): array
    {
        $raw = $this->redis->get("backup_settings");
        $s = $raw ? json_decode($raw, true) : null;
        return is_array($s) ? $s : [];
    }

    /**
     * The active remote target, or null when the Time Machine is Local Disk /
     * unconfigured / missing the minimum credentials.
     * Returns ["type" => "AWS S3"|"FTP"|"SFTP", "config" => [...]].
     */
    public function target(): ?array
    {
        $s = $this->settings();
        $type = $s["storage"] ?? "Local Disk";
        if (!in_array($type, ["AWS S3", "FTP", "SFTP"], true)) {
            return null;
        }
        $cfg = $s["storageConfig"][$type] ?? null;
        if (!is_array($cfg) || empty($cfg)) {
            return null;
        }
        if ($type === "AWS S3" && (empty($cfg["accessKey"]) || empty($cfg["secretKey"]) || empty($cfg["storagePath"]))) {
            return null;
        }
        if (($type === "FTP" || $type === "SFTP") && empty($cfg["host"])) {
            return null;
        }
        return ["type" => $type, "config" => $cfg];
    }

    public function available(): bool
    {
        return $this->target() !== null;
    }

    /** Target type for display, or null. */
    public function label(): ?string
    {
        $t = $this->target();
        return $t ? $t["type"] : null;
    }

    /**
     * Upload one local file to <base>/ringq-archive/<remoteRelPath>. Returns true
     * only on verified success. Never throws.
     */
    public function upload(string $localPath, string $remoteRelPath): bool
    {
        if (!is_file($localPath)) {
            return false;
        }
        $t = $this->target();
        if (!$t) {
            return false;
        }
        $rel = self::PREFIX . "/" . ltrim($remoteRelPath, "/");
        switch ($t["type"]) {
            case "AWS S3":
                return $this->uploadS3($localPath, $rel, $t["config"]);
            case "FTP":
                return $this->uploadFtp($localPath, $rel, $t["config"]);
            case "SFTP":
                return $this->uploadSftp($localPath, $rel, $t["config"]);
        }
        return false;
    }

    private function uploadS3(string $local, string $rel, array $c): bool
    {
        $bucketPath = trim((string)$c["storagePath"], "/");
        if ($bucketPath === "") {
            return false;
        }
        $region = $c["region"] ?? "ap-southeast-1";
        $env = "AWS_ACCESS_KEY_ID=" . escapeshellarg($c["accessKey"])
             . " AWS_SECRET_ACCESS_KEY=" . escapeshellarg($c["secretKey"])
             . " AWS_DEFAULT_REGION=" . escapeshellarg($region) . " ";
        $dest = "s3://" . $bucketPath . "/" . $rel;
        $cmd = "timeout " . self::CMD_TIMEOUT . " " . $env . "aws s3 cp "
             . escapeshellarg($local) . " " . escapeshellarg($dest) . " 2>/dev/null";
        return $this->runOk($cmd);
    }

    private function uploadFtp(string $local, string $rel, array $c): bool
    {
        $host = (string)$c["host"];
        $port = (int)($c["port"] ?? 21);
        $user = (string)($c["username"] ?? "");
        $pass = (string)($c["password"] ?? "");
        $base = trim((string)($c["path"] ?? ""), "/");
        $remoteFile = ($base !== "" ? $base . "/" : "") . $rel;
        $url = "ftp://" . $host . ":" . $port . "/" . ltrim($remoteFile, "/");
        $cmd = "timeout " . self::CMD_TIMEOUT . " curl -s --ftp-create-dirs -T "
             . escapeshellarg($local) . " --user " . escapeshellarg($user . ":" . $pass)
             . " " . escapeshellarg($url) . " 2>/dev/null";
        return $this->runOk($cmd);
    }

    private function uploadSftp(string $local, string $rel, array $c): bool
    {
        $host = (string)$c["host"];
        $port = (int)($c["port"] ?? 22);
        $user = (string)($c["username"] ?? "");
        $authMethod = $c["authMethod"] ?? "password";
        $base = trim((string)($c["path"] ?? ""), "/");
        $remoteFile = ($base !== "" ? $base . "/" : "") . $rel;

        // sftp has no mkdir -p; create each path segment (leading '-' ignores errors).
        $mkdirs = "";
        $dir = dirname($remoteFile);
        if ($dir !== "." && $dir !== "/") {
            $acc = "";
            foreach (explode("/", trim($dir, "/")) as $seg) {
                if ($seg === "") {
                    continue;
                }
                $acc .= ($acc === "" ? "" : "/") . $seg;
                $mkdirs .= "-mkdir " . escapeshellarg($acc) . "\n";
            }
        }
        $batch = $mkdirs . "put " . escapeshellarg($local) . " " . escapeshellarg($remoteFile) . "\n";

        $keyFile = null;
        if ($authMethod === "key" && !empty($c["privateKey"])) {
            $keyFile = tempnam(sys_get_temp_dir(), "sftp_key_");
            file_put_contents($keyFile, $c["privateKey"]);
            @chmod($keyFile, 0400);
            $cmd = "timeout " . self::CMD_TIMEOUT . " sftp -i " . escapeshellarg($keyFile)
                 . " -o Port=" . $port . " -o BatchMode=no -o StrictHostKeyChecking=no "
                 . escapeshellarg($user . "@" . $host) . " << 'EOF'\n" . $batch . "EOF\n";
        } else {
            $pass = (string)($c["password"] ?? "");
            $cmd = "export SSHPASS=" . escapeshellarg($pass) . "; "
                 . "timeout " . self::CMD_TIMEOUT . " sshpass -e sftp -o Port=" . $port
                 . " -o BatchMode=no -o StrictHostKeyChecking=no "
                 . escapeshellarg($user . "@" . $host) . " << 'EOF'\n" . $batch . "EOF\n";
        }
        $ok = $this->runOk($cmd);
        if ($keyFile) {
            @unlink($keyFile);
        }
        return $ok;
    }

    private function runOk(string $cmd): bool
    {
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        return $code === 0;
    }
}
