<?php

/**
 * Emailed verification codes for consequential actions.
 *
 * Extracted from AccountsController so a second caller — the support access
 * grant — reuses the same implementation instead of copying it. Two copies of
 * a code check is how one of them quietly stops consuming its code.
 *
 * Only the SHA-256 of a code is stored, the code is bound to the exact values
 * it was issued for, and it is deleted before the action runs so a replay
 * cannot follow a failure. Every refusal answers in the same shape, so the
 * endpoint cannot be used to tell "no request outstanding" from "wrong code".
 *
 * The mail itself is sent by app/jobs/worker.php, which already holds the relay
 * credentials and drains `email_queue` every tick. Nothing about the message
 * body lives here.
 */
class ActionOtp
{
    /** Seconds a code stays valid. Mirrors kNetworkOtpTtlSeconds in the client. */
    const TTL = 300;

    /** Seconds before another code may be requested. */
    const COOLDOWN = 60;

    /** Wrong codes allowed before the outstanding code is destroyed. */
    const MAX_ATTEMPTS = 5;

    const PREFIXES = [
        "ip-change"  => "ip-change-otp",
        "update"     => "update-otp",
        "sipprofile" => "sipprofile-otp",
        "acl-change" => "acl-change-otp",
        "mcp-grant"  => "mcp-grant-otp",
    ];

    public static function keys($session, $purpose): array
    {
        $prefix = self::PREFIXES[$purpose];
        $scope = ($session["domain_uuid"] ?? "") . ":" . ($session["user_uuid"] ?? "");
        return [
            "code"     => $prefix . ":" . $scope,
            "target"   => $prefix . "-target:" . $scope,
            "tries"    => $prefix . "-tries:" . $scope,
            "cooldown" => $prefix . "-cooldown:" . $scope,
        ];
    }

    public static function normalise($target): array
    {
        $out = [];
        foreach ((array) $target as $key => $value) {
            $out[(string) $key] = is_array($value) ? json_encode($value) : (string) $value;
        }
        ksort($out);
        return $out;
    }

    public static function fqdn($di, $session): string
    {
        $licenseConfig = json_decode((string) $di->get("redis")->get("ringq_config"), true);
        return $licenseConfig["data"]["fqdn"] ?? ($session["subdomain"] ?? "this server");
    }

    /**
     * Mint a code, queue the email, and write nothing else.
     *
     * @return array the JSON body the caller should return
     */
    public static function send($di, $session, $purpose, array $target, $jobType, array $mail): array
    {
        $redis = $di->get("redis");
        $email = trim((string) ($session["email"] ?? ""));

        if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                "result" => "error",
                "message" => "Your account has no valid email address on file, so a verification code cannot be sent. Add one in your profile first.",
            ];
        }

        $keys = self::keys($session, $purpose);

        if ($redis->get($keys["cooldown"])) {
            return [
                "result" => "error",
                "message" => "A code was just sent. Wait a moment before requesting another.",
            ];
        }

        $code = (string) random_int(100000, 999999);

        $redis->setex($keys["code"], self::TTL, hash("sha256", $code));
        $redis->setex($keys["target"], self::TTL, json_encode($target));
        $redis->setex($keys["tries"], self::TTL, 0);
        $redis->setex($keys["cooldown"], self::COOLDOWN, 1);

        $redis->lpush("email_queue", json_encode([
            "type" => $jobType,
            "data" => array_merge($mail, [
                "email"       => $email,
                "name"        => trim(($session["firstname"] ?? "") . " " . ($session["lastname"] ?? "")),
                "code"        => $code,
                "fqdn"        => self::fqdn($di, $session),
                "ttlMinutes"  => intdiv(self::TTL, 60),
                "requestedAt" => time(),
            ]),
        ]));

        return [
            "result" => "success",
            "message" => "A verification code has been emailed to " . $email . ".",
            "expiresIn" => self::TTL,
        ];
    }

    /**
     * Verify a submitted code against the values it was issued for.
     *
     * @return array|null null when the code is good and has been consumed
     */
    public static function check($di, $session, $purpose, array $target, $submitted, $mismatchMessage)
    {
        $redis = $di->get("redis");
        $keys = self::keys($session, $purpose);
        $submitted = trim((string) $submitted);
        $expected = $redis->get($keys["code"]);

        $generic = [
            "result" => "error",
            "message" => "That verification code is not valid. Request a new one.",
        ];

        if (!$expected || $submitted === "") {
            return $generic;
        }

        $tries = intval($redis->get($keys["tries"]));
        if ($tries >= self::MAX_ATTEMPTS) {
            $redis->del($keys["code"]);
            $redis->del($keys["target"]);
            $redis->del($keys["tries"]);
            return [
                "result" => "error",
                "message" => "Too many incorrect codes. Request a new one.",
                "attemptsLeft" => 0,
            ];
        }

        if (!hash_equals((string) $expected, hash("sha256", $submitted))) {
            $redis->incr($keys["tries"]);
            $left = self::MAX_ATTEMPTS - ($tries + 1);
            return [
                "result" => "error",
                "message" => "That verification code is not valid.",
                "attemptsLeft" => $left < 0 ? 0 : $left,
            ];
        }

        $stored = json_decode((string) $redis->get($keys["target"]), true);
        if (!is_array($stored) || self::normalise($stored) !== self::normalise($target)) {
            return [
                "result" => "error",
                "message" => $mismatchMessage,
            ];
        }

        $redis->del($keys["code"]);
        $redis->del($keys["target"]);
        $redis->del($keys["tries"]);

        return null;
    }
}
