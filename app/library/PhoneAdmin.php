<?php
declare(strict_types=1);

/**
 * PhoneAdmin -- desk phone web password and remote reboot.
 *
 * Web password: one random password per phone (tenant + MAC), kept in the redis
 * hash "phone-webpass:<tenant fqdn>" (field = MAC without separators). It is made
 * the first time it is needed (the phone fetches its config, or an admin opens it
 * in Auto Provisioning) and never changes after that, so what the admin sees is
 * what the phone was given. applyToConfig() writes it into the config the phone
 * downloads, so existing phones pick it up on their next provisioning fetch.
 *
 * Reboot: the phone's own SIP registration gets a NOTIFY (Event: check-sync,
 * which FreeSWITCH sends as check-sync;reboot=true to Yealink and Snom). No web
 * password or LAN access to the phone is needed.
 */
class PhoneAdmin
{
    const WEB_USER = "admin";
    const PASSWORD_LENGTH = 12;
    const DESK_PHONE_AGENTS = "/yealink|fanvil|snom|grandstream/i";

    public static function cleanMac($mac): string
    {
        return strtolower(str_replace([":", "-", "."], "", trim((string) $mac)));
    }

    /** The phone's web password; made (once) when $create and none is saved yet. */
    public static function webPassword($redis, string $tenant, string $mac, bool $create = true): ?string
    {
        $mac = self::cleanMac($mac);
        if ($tenant === "" || !preg_match('/^[0-9a-f]{12}$/', $mac)) {
            return null;
        }
        $key = "phone-webpass:" . $tenant;
        $saved = $redis->hGet($key, $mac);
        if (is_string($saved) && $saved !== "") {
            return $saved;
        }
        if (!$create) {
            return null;
        }
        // hSetNx: if two requests race, both end up with the first one's password.
        $redis->hSetNx($key, $mac, self::newPassword());
        $saved = $redis->hGet($key, $mac);
        return is_string($saved) && $saved !== "" ? $saved : null;
    }

    /** An admin-chosen web password: 6-32 of letters, digits and . _ - ! @ # % * + = */
    public static function validPassword(string $password): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._!@#%*+=-]{6,32}$/', $password);
    }

    public static function setWebPassword($redis, string $tenant, string $mac, string $password): bool
    {
        $mac = self::cleanMac($mac);
        if ($tenant === "" || !preg_match('/^[0-9a-f]{12}$/', $mac) || !self::validPassword($password)) {
            return false;
        }
        $redis->hSet("phone-webpass:" . $tenant, $mac, $password);
        return true;
    }

    /** Letters and digits only (no look-alikes), so it needs no escaping in .cfg or XML. */
    public static function newPassword(): string
    {
        $chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789";
        do {
            $out = "";
            for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
                $out .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while (!preg_match('/[a-z]/', $out) || !preg_match('/[A-Z]/', $out) || !preg_match('/[0-9]/', $out));
        return $out;
    }

    /**
     * Add the web login (and, for Yealink, notify_reboot_enable=0: reboot only on
     * check-sync;reboot=true, so reboot=false from Reprovision just re-downloads) to a config
     * about to be served. $type: 1 Yealink .cfg, 2 Fanvil XML, 3 Snom XML (IndexController).
     * Anything that can't be parsed is served unchanged.
     */
    public static function applyToConfig(int $type, string $content, string $password): string
    {
        try {
            if ($type === 1) {
                return self::setCfgValues($content, [
                    "static.auth.user" => self::WEB_USER,
                    "static.auth.password" => $password,
                    "static.security.user_name.admin" => self::WEB_USER,
                    "static.security.user_password" => self::WEB_USER . ":" . $password,
                    "sip.notify_reboot_enable" => "0",
                ]);
            }
            if ($type === 2) {
                return self::setFanvilXml($content, $password);
            }
            if ($type === 3) {
                return self::setSnomXml($content, [
                    "web_user" => self::WEB_USER,
                    "web_pass" => $password,
                    "http_user" => self::WEB_USER,
                    "http_pass" => $password,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("PhoneAdmin::applyToConfig: " . $e->getMessage());
        }
        return $content;
    }

    private static function setCfgValues(string $cfg, array $values): string
    {
        foreach ($values as $key => $value) {
            $pattern = '/^[ \t]*' . preg_quote($key, '/') . '[ \t]*=.*$/m';
            if (preg_match($pattern, $cfg)) {
                $cfg = preg_replace($pattern, $key . " = " . $value, $cfg);
            } else {
                $cfg = rtrim($cfg, "\r\n") . "\n" . $key . " = " . $value . "\n";
            }
        }
        return $cfg;
    }

    /** Snom: <settings><phone-settings><web_pass perm="R">..</web_pass>, added when missing. */
    private static function setSnomXml(string $xml, array $values): string
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return $xml;
        }
        $parent = $doc->getName() === "phone-settings" ? $doc : ($doc->{"phone-settings"}[0] ?? $doc);
        foreach ($values as $name => $value) {
            $nodes = $doc->xpath("//" . $name);
            if ($nodes) {
                foreach ($nodes as $node) {
                    $node[0] = $value;
                }
            } else {
                $parent->addChild($name, $value)->addAttribute("perm", "R");
            }
        }
        return $doc->asXML();
    }

    /**
     * Fanvil sysConf XML: the web logins are <account> entries holding <name>,
     * <password> and <level>. Set the admin one; add it under <sys> if the template
     * has none.
     */
    /**
     * Fanvil sysConf: the web logins live in <web><account index="N"><Name/><Password/><Level/></account>
     * (index 1 = admin, level 10; index 2 = guest, level 5). Tags are case-sensitive on the phone.
     */
    private static function setFanvilXml(string $xml, string $password): string
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return $xml;
        }
        $web = $doc->web[0] ?? $doc->addChild("web");
        $admin = null;
        foreach ($web->account as $account) {
            if (strtolower(trim((string) $account->Name)) === self::WEB_USER) {
                $admin = $account;
                break;
            }
        }
        if ($admin === null) {
            foreach ($web->account as $account) {
                if ((string) $account["index"] === "1") {
                    $admin = $account;
                    break;
                }
            }
        }
        if ($admin === null) {
            $admin = $web->addChild("account");
            $admin->addAttribute("index", "1");
        }
        foreach (["Name" => self::WEB_USER, "Password" => $password, "Level" => "10"] as $tag => $value) {
            if (isset($admin->{$tag})) {
                $admin->{$tag}[0] = $value;
            } else {
                $admin->addChild($tag, $value);
            }
        }
        // Earlier builds added <sys><account> (wrong section); harmless, but drop it if it's ours.
        foreach ($doc->xpath("/sysConf/sys/account[name]") ?: [] as $stray) {
            unset($stray[0]);
        }
        return $doc->asXML();
    }

    // ----- reboot -----

    private static function fsCli(string $command): string
    {
        $bin = is_executable("/usr/bin/fs_cli") ? "/usr/bin/fs_cli" : "fs_cli";
        return (string) @shell_exec($bin . " -x " . escapeshellarg($command) . " 2>&1");
    }

    /** SIP profiles, "internal" first (phones register there unless the server says otherwise). */
    private static function profiles(): array
    {
        $names = [];
        foreach (explode("\n", self::fsCli("sofia status")) as $line) {
            if (preg_match('/^\s*(\S+)\s+profile\s/', $line, $m)) {
                $names[] = $m[1];
            }
        }
        $names = array_values(array_unique($names));
        usort($names, function ($a, $b) {
            return ($b === "internal") <=> ($a === "internal");
        });
        return $names ?: ["internal"];
    }

    /**
     * Desk-phone registrations of $sipUser@$tenant that belong to this MAC: the
     * User-Agent carries the MAC (Yealink, Fanvil), or -- for phones that don't put
     * it there (Snom) -- the User-Agent names the phone's brand. Apps and softphones
     * on the same extension are never matched.
     *
     * @return array<int, array{profile:string, call_id:string, agent:string, contact:string}>
     */
    public static function registrations(string $sipUser, string $tenant, string $mac, string $brand): array
    {
        $mac = self::cleanMac($mac);
        $byMac = [];
        $byBrand = [];
        if (!preg_match('/^[A-Za-z0-9_.@-]+$/', $sipUser)) {
            return [];
        }
        foreach (self::profiles() as $profile) {
            $out = self::fsCli("sofia status profile " . $profile . " reg " . $sipUser);
            foreach (preg_split('/\n\s*\n/', $out) as $block) {
                $row = [];
                foreach (explode("\n", $block) as $line) {
                    if (preg_match('/^\s*([A-Za-z\- ]+?):\s*(.*)$/', $line, $m)) {
                        $row[strtolower(trim($m[1]))] = trim($m[2]);
                    }
                }
                $user = strtolower($row["user"] ?? "");
                if (empty($row["call-id"]) || $user !== strtolower($sipUser . "@" . $tenant)) {
                    continue;
                }
                $reg = [
                    "profile" => $profile,
                    "call_id" => $row["call-id"],
                    "agent" => $row["agent"] ?? "",
                    "contact" => $row["contact"] ?? "",
                ];
                $agent = strtolower($reg["agent"]);
                if ($mac !== "" && strpos(self::cleanMac($agent), $mac) !== false) {
                    $byMac[] = $reg;
                } elseif ($brand !== "" && stripos($agent, $brand) !== false && preg_match(self::DESK_PHONE_AGENTS, $agent)) {
                    $byBrand[] = $reg;
                }
            }
        }
        return $byMac ?: $byBrand;
    }

    /** NOTIFY check-sync to one registration; true when FreeSWITCH accepted the command. */
    public static function sendReboot(array $registration): bool
    {
        if (!preg_match('/^[^\s\'"]+$/', $registration["call_id"]) || !preg_match('/^[A-Za-z0-9_.-]+$/', $registration["profile"])) {
            return false;
        }
        $out = self::fsCli("sofia profile " . $registration["profile"] . " check_sync " . $registration["call_id"]);
        return stripos($out, "+OK") !== false;
    }
}
