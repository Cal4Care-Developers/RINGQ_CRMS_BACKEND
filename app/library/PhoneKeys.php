<?php
declare(strict_types=1);

use Phalcon\Db\Column;

/**
 * PhoneKeys -- the extension's "Busy Lamp Fields (BLF)" rows on desk phones.
 *
 * The rows are saved in q_blfs (types/values, parallel comma lists) and used by the
 * RingQ apps, but no desk phone config ever carried them. keysFor() turns them into
 * one key list and applyToConfig() writes it into the config a phone is about to
 * download (IndexController), so a change on the BLF page reaches the phone on its
 * next provisioning fetch (reboot) with no re-save of Auto Provisioning.
 *
 * Row n goes on programmable key n+1 -- key 1 stays the phone's own line:
 *   Yealink  linekey.<n+1>.*            type 16 BLF / 13 speed dial / 0 none
 *   Snom     <fkey idx="<n>">           "blf <sip:user@tenant>" / "speed <number>"
 *   Fanvil   dsskey/dssSide/Fkey[n+1]   Type 1 (memory key), Value "<user>@1/bc" BLF or "<number>@1/f" speed dial
 *   Grandstream GRP26xx: line key n+1 (P1363.. mode/account, P1465.. name/value; keys 2-6 only)
 *               GXP21xx: multi-purpose key n (P323-P329 / P301.. for 1-7, P353.. for 8-18) -- no line on MPKs
 *               mode 1 BLF / 0 speed dial; other Grandstream models are served unchanged
 *
 * BLF watches the extension's SIP user (number_alias), not the extension number:
 * RingQ phones and apps register as number_alias and FreeSWITCH reports presence
 * as number_alias@tenant, so a subscription to "1016@tenant" never lights up.
 *
 * Pickup: while the watched extension rings, Snom and Yealink dial "*8<SIP user>"; the
 * blf_sip_user_pickup dial plan (server-configs/blf) intercepts that ringing call.
 *
 * Shared Parking slot n is a BLF key on *59n (Snom's "orbit" key sends nothing to the server): pressed during a
 * call it parks the call in that slot, pressed while the lamp is lit it picks the parked call up
 * (dial plan ringq_desk_park, shared with the apps' parking slots).
 * Agent Status and Change Status dial *26<aux code id> (dial plan ringq_desk_aux), which sets
 * the extension's aux code the same way the dialer does.
 * applyFeatures() also points the phone's Message button at the extension's voicemail and its
 * DND button at *78 / *79, so DND on the phone and in the apps is one setting.
 *
 * Phones keep a key they were given until a config sets it again, so the number of
 * keys last written per phone is remembered (redis hash phone-keys:<tenant>) and
 * keys that are no longer used are set back to empty.
 */
class PhoneKeys
{
    const MAX_KEYS = 60;
    /** pressing a ringing BLF key dials this + the watched SIP user (dial plan blf_sip_user_pickup) */
    const PICKUP_PREFIX = "*8";
    /** Shared Parking slot n is *59n (dial plan ringq_desk_park); Agent / Change Status dial *26<q_auxcodes.id> (ringq_desk_aux) */
    const PARK_PREFIX = "*59";
    const AUX_PREFIX = "*26";
    /** the phones' own DND button sends these (dial plan do-not-disturb), so DND is the same setting as in the apps */
    const DND_ON = "*78";
    const DND_OFF = "*79";

    /**
     * The extension's BLF rows as phone keys: [["kind" => "blf"|"speed"|"none", "target", "label"], ...]
     */
    public static function keysFor(string $domainUuid, string $extension): array
    {
        $qBlf = \QBlfs::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
            "bind" => ["domain_uuid" => $domainUuid, "extension" => $extension],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if (!$qBlf || (string) $qBlf->types === "") {
            return [];
        }
        $types = explode(",", (string) $qBlf->types);
        $values = explode(",", (string) $qBlf->values);
        $keys = [];
        foreach ($types as $i => $type) {
            if (count($keys) >= self::MAX_KEYS - 1) {
                break;
            }
            $type = trim(html_entity_decode($type, ENT_QUOTES));
            $value = trim(html_entity_decode((string) ($values[$i] ?? ""), ENT_QUOTES));
            $keys[] = self::key($domainUuid, $extension, $type, $value);
        }
        // trailing empty rows need no key
        while ($keys && end($keys)["kind"] === "none") {
            array_pop($keys);
        }
        return $keys;
    }

    private static function key(string $domainUuid, string $ownExtension, string $type, string $value): array
    {
        $none = ["kind" => "none", "target" => "", "label" => ""];
        if ($value === "" || $value === "Select") {
            return $none;
        }
        if ($type === "Speed Dial") {
            $isVoicemail = $value === "OwnVoicemail" || $value === "Own Voicemail" || strpos($value, "✉") !== false || strpos($value, "*99") === 0;
            $number = $value === "OwnVoicemail" || $value === "Own Voicemail" ? $ownExtension : explode(" - ", $value)[0];
            $number = preg_replace('/[^0-9*#+]/', "", strpos($number, "*99") === 0 ? substr($number, 3) : $number);
            if ($number === "") {
                return $none;
            }
            $label = $isVoicemail ? "VM " . $number : self::extensionLabel($domainUuid, $number);
            return ["kind" => "speed", "target" => ($isVoicemail ? "*99" : "") . $number, "label" => $label];
        }
        if ($type === "BLF") {
            $number = preg_replace('/[^0-9A-Za-z*#+]/', "", explode(" - ", $value)[0]);
            if ($number === "") {
                return $none;
            }
            $ext = self::extension($domainUuid, $number);
            $user = $ext && !empty($ext->number_alias) ? (string) $ext->number_alias : $number;
            return ["kind" => "blf", "target" => $user, "label" => self::extensionLabel($domainUuid, $number, $ext), "pickup" => true];
        }
        if ($type === "Shared Parking") {
            // lamp lit while a call waits in the slot; press with a call to park it there, without one to pick it up
            $slot = (int) preg_replace('/[^0-9]/', "", $value);
            if ($slot < 1 || $slot > 5) {
                return $none;
            }
            return ["kind" => "blf", "target" => self::PARK_PREFIX . $slot, "label" => "Park " . $slot, "park" => true];
        }
        if ($type === "Agent Status" || $type === "Change Status") {
            $aux = self::auxcode($domainUuid, $type, $value);
            if (!$aux) {
                return $none;
            }
            $label = $type === "Change Status" ? strtoupper($value) : ucwords((string) $aux->auxcode_status);
            return ["kind" => "speed", "target" => self::AUX_PREFIX . $aux->id, "label" => function_exists("mb_substr") ? mb_substr($label, 0, 20) : substr($label, 0, 20)];
        }
        return $none;
    }

    /** The aux code a key sets: Agent Status by name; Change Status IN / OUT = the tenant's first / second aux code, as in the dialer. */
    private static function auxcode(string $domainUuid, string $type, string $value)
    {
        $codes = \QAuxcodes::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "bind" => ["domain_uuid" => $domainUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
            "order" => "id ASC",
        ]);
        $list = [];
        foreach ($codes as $code) {
            $list[] = $code;
        }
        if ($type === "Change Status") {
            $i = strtoupper(trim($value)) === "IN" ? 0 : (strtoupper(trim($value)) === "OUT" ? 1 : -1);
            return $list[$i] ?? null;
        }
        foreach ($list as $code) {
            if (strcasecmp(trim((string) $code->auxcode_status), trim($value)) === 0) {
                return $code;
            }
        }
        return null;
    }

    private static function extension(string $domainUuid, string $number)
    {
        if (!preg_match('/^[0-9]{1,10}$/', $number)) {
            return null;
        }
        return \VExtensions::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
            "bind" => ["domain_uuid" => $domainUuid, "extension" => $number],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]) ?: null;
    }

    /** "1016 Demo" -- short enough for a key; just the number when no name is known. */
    private static function extensionLabel(string $domainUuid, string $number, $ext = false): string
    {
        if ($ext === false) {
            $ext = self::extension($domainUuid, $number);
        }
        $name = $ext ? trim(html_entity_decode((string) ($ext->effective_caller_id_name ?? ""), ENT_QUOTES)) : "";
        $name = $name !== "" && $name !== $number ? explode(" ", $name)[0] : "";
        $label = trim($number . " " . $name);
        $label = preg_replace('/[\r\n,|<>"]/', "", $label);
        return function_exists("mb_substr") ? mb_substr($label, 0, 20) : substr($label, 0, 20);
    }

    /**
     * The phone's own Message and DND buttons: Message dials the extension's own number (dial plan
     * extension-to-voicemail checks its voicemail), DND sends *78 / *79 (dial plan do-not-disturb), so
     * the phone and the apps share one DND setting. Yealink, Snom, Grandstream (Message only); others unchanged.
     */
    public static function applyFeatures(int $type, string $content, string $extension): string
    {
        try {
            if (!preg_match('/^[0-9]{1,10}$/', $extension)) {
                return $content;
            }
            if ($type === 1) {
                return self::cfgValues($content, [
                    "voice_mail.number.1" => $extension,
                    "features.dnd.on_code" => self::DND_ON,
                    "features.dnd.off_code" => self::DND_OFF,
                    "account.1.dnd.on_code" => self::DND_ON,
                    "account.1.dnd.off_code" => self::DND_OFF,
                ]);
            }
            if ($type === 3) {
                $doc = self::load($content);
                if (!$doc) {
                    return $content;
                }
                $root = $doc->documentElement;
                $parent = $root->nodeName === "settings" ? self::child($doc, $root, "phone-settings") : $root;
                foreach (["user_mailbox" => ["1", $extension], "dnd_on_code" => [null, self::DND_ON], "dnd_off_code" => [null, self::DND_OFF]] as $name => [$idx, $value]) {
                    $node = null;
                    foreach ($doc->getElementsByTagName($name) as $el) {
                        if ($idx === null || $el->getAttribute("idx") === $idx) {
                            $node = $el;
                        }
                    }
                    if (!$node) {
                        $node = $doc->createElement($name);
                        if ($idx !== null) {
                            $node->setAttribute("idx", $idx);
                        }
                        $parent->appendChild($node);
                    }
                    $node->setAttribute("perm", "");
                    while ($node->firstChild) {
                        $node->removeChild($node->firstChild);
                    }
                    $node->appendChild($doc->createTextNode($value));
                }
                return $doc->saveXML();
            }
            if ($type === 4) {
                // P33: voicemail access number of account 1
                if (preg_match('/<config[^>]*>/', $content)) {
                    $pattern = '/<P33>[^<]*<\/P33>/';
                    return preg_match($pattern, $content)
                        ? preg_replace($pattern, "<P33>" . $extension . "</P33>", $content)
                        : preg_replace('/<\/config>/', "<P33>" . $extension . "</P33>\n</config>", $content, 1);
                }
                $pattern = '/^[ \t]*P33[ \t]*=.*$/m';
                return preg_match($pattern, $content)
                    ? preg_replace($pattern, "P33=" . $extension, $content)
                    : rtrim($content, "\r\n") . "\nP33=" . $extension . "\n";
            }
        } catch (\Throwable $e) {
            error_log("PhoneKeys::applyFeatures: " . $e->getMessage());
        }
        return $content;
    }

    /**
     * Write $keys into a config about to be served. $type: 1 Yealink .cfg, 2 Fanvil XML,
     * 3 Snom XML (IndexController). Anything that can't be parsed is served unchanged.
     */
    public static function applyToConfig($redis, int $type, string $content, string $tenant, string $mac, array $keys): string
    {
        try {
            $mac = strtolower(str_replace([":", "-", "."], "", $mac));
            $memo = "phone-keys:" . $tenant;
            $before = (int) ($redis->hGet($memo, $mac) ?: 0);
            $count = max(count($keys), min($before, self::MAX_KEYS - 1));
            if ($count === 0) {
                return $content;
            }
            $keys = array_pad($keys, $count, ["kind" => "none", "target" => "", "label" => ""]);
            if ($type === 1) {
                $out = self::yealink($content, $keys);
            } elseif ($type === 2) {
                $out = self::fanvil($content, $keys);
            } elseif ($type === 3) {
                $out = self::snom($content, $keys, $tenant);
            } elseif ($type === 4) {
                $out = self::grandstream($content, $keys, (string) ($_SERVER["HTTP_USER_AGENT"] ?? ""));
            } else {
                return $content;
            }
            if ($out !== null) {
                $used = count($keys);
                while ($used > 0 && $keys[$used - 1]["kind"] === "none") {
                    $used--;
                }
                $used > 0 ? $redis->hSet($memo, $mac, (string) $used) : $redis->hDel($memo, $mac);
                return $out;
            }
        } catch (\Throwable $e) {
            error_log("PhoneKeys::applyToConfig: " . $e->getMessage());
        }
        return $content;
    }

    private static function yealink(string $cfg, array $keys): string
    {
        $values = [];
        foreach ($keys as $i => $key) {
            $n = $i + 2;
            $kind = $key["kind"];
            $values["linekey.$n.type"] = $kind === "blf" ? "16" : ($kind === "speed" ? "13" : "0");
            $values["linekey.$n.line"] = $kind === "none" ? "0" : "1";
            $values["linekey.$n.value"] = $key["target"];
            $values["linekey.$n.label"] = $key["label"];
            $values["linekey.$n.extension"] = !empty($key["pickup"]) ? self::PICKUP_PREFIX : "";
        }
        return self::cfgValues($cfg, $values);
    }

    /** Set "name = value" lines in a Yealink .cfg, adding the ones that are missing. */
    private static function cfgValues(string $cfg, array $values): string
    {
        foreach ($values as $name => $value) {
            $pattern = '/^[ \t]*' . preg_quote($name, '/') . '[ \t]*=.*$/m';
            if (preg_match($pattern, $cfg)) {
                $cfg = preg_replace_callback($pattern, function () use ($name, $value) {
                    return $name . " = " . $value;
                }, $cfg);
            } else {
                $cfg = rtrim($cfg, "\r\n") . "\n" . $name . " = " . $value . "\n";
            }
        }
        return $cfg;
    }

    /**
     * Grandstream P-values, by model from the User-Agent ("Grandstream GRP2614 ..."):
     * GRP26xx line keys k=2..(fixed keys, max 6): mode P(1361+2k), account P(1362+2k), name P(1463+2k), value P(1464+2k).
     * GXP21xx multi-purpose keys k=1..18: k<=7 mode P(322+k), account/name/value P(298+3k)..P(300+3k);
     * k>=8 mode/account/name/value P(353+4(k-8))..+3. Mode: 1 BLF, 0 speed dial (GRP: -1 = none).
     * The config is either XML (<gs_provision><config><P47>..) or "P47=value" lines; both are handled.
     */
    private static function grandstream(string $content, array $keys, string $agent): ?string
    {
        $values = [];
        if (preg_match('/GRP26(\d\d)/i', $agent, $m)) {
            $fixed = ["12" => 3, "13" => 4][$m[1]] ?? 6;
            foreach ($keys as $i => $key) {
                $k = $i + 2;
                if ($k > $fixed) {
                    break;
                }
                $kind = $key["kind"];
                $values[1361 + 2 * $k] = $kind === "blf" ? "1" : ($kind === "speed" ? "0" : "-1");
                $values[1362 + 2 * $k] = "0";
                $values[1463 + 2 * $k] = $key["label"];
                $values[1464 + 2 * $k] = $key["target"];
            }
        } elseif (preg_match('/GXP21\d\d/i', $agent)) {
            foreach ($keys as $i => $key) {
                $k = $i + 1;
                if ($k > 18) {
                    break;
                }
                $p = $k <= 7 ? [322 + $k, 298 + 3 * $k, 299 + 3 * $k, 300 + 3 * $k] : [353 + 4 * ($k - 8), 354 + 4 * ($k - 8), 355 + 4 * ($k - 8), 356 + 4 * ($k - 8)];
                $values[$p[0]] = $key["kind"] === "blf" ? "1" : "0";
                $values[$p[1]] = "0";
                $values[$p[2]] = $key["label"];
                $values[$p[3]] = $key["target"];
            }
        } else {
            return null;
        }
        if (stripos($content, "<config") !== false) {
            $doc = self::load($content);
            if (!$doc) {
                return null;
            }
            $config = $doc->getElementsByTagName("config")->item(0);
            foreach ($values as $p => $value) {
                self::setText($doc, $config, "P" . $p, (string) $value);
            }
            return $doc->saveXML();
        }
        foreach ($values as $p => $value) {
            $pattern = '/^[ \t]*P' . $p . '[ \t]*=.*$/m';
            $line = "P" . $p . "=" . $value;
            if (preg_match($pattern, $content)) {
                $content = preg_replace_callback($pattern, function () use ($line) {
                    return $line;
                }, $content);
            } else {
                $content = rtrim($content, "\r\n") . "\n" . $line . "\n";
            }
        }
        return $content;
    }

    private static function load(string $xml): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok && $doc->documentElement ? $doc : null;
    }

    private static function child(\DOMDocument $doc, \DOMElement $parent, string $name, ?string $index = null): \DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === $name && ($index === null || $node->getAttribute("index") === $index)) {
                return $node;
            }
        }
        $node = $doc->createElement($name);
        if ($index !== null) {
            $node->setAttribute("index", $index);
        }
        $parent->appendChild($node);
        return $node;
    }

    private static function setText(\DOMDocument $doc, \DOMElement $parent, string $name, string $value): void
    {
        $node = self::child($doc, $parent, $name);
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($doc->createTextNode($value));
    }

    /** Fanvil sysConf: <dsskey><dssSide index="1"><Fkey index="n"><Type/><Value/><Title/></Fkey> */
    private static function fanvil(string $xml, array $keys): ?string
    {
        $doc = self::load($xml);
        if (!$doc) {
            return null;
        }
        $root = $doc->documentElement;
        $dsskey = $root->getElementsByTagName("dsskey")->item(0) ?: self::child($doc, $root, "dsskey");
        $side = self::child($doc, $dsskey, "dssSide", "1");
        foreach ($keys as $i => $key) {
            $fkey = self::child($doc, $side, "Fkey", (string) ($i + 2));
            $kind = $key["kind"];
            self::setText($doc, $fkey, "Type", $kind === "none" ? "0" : "1");
            self::setText($doc, $fkey, "Value", $kind === "blf" ? $key["target"] . "@1/bc" : ($kind === "speed" ? $key["target"] . "@1/f" : ""));
            self::setText($doc, $fkey, "Title", $key["label"]);
        }
        return $doc->saveXML();
    }

    /** Snom: <settings><functionKeys><fkey idx="n" context="active" label=".." perm="">..</fkey> */
    private static function snom(string $xml, array $keys, string $tenant): ?string
    {
        $doc = self::load($xml);
        if (!$doc) {
            return null;
        }
        $root = $doc->documentElement;
        // full format: <settings><phone-settings/><functionKeys/></settings>; flat format: fkeys in <phone-settings>
        $parent = $root->nodeName === "settings" ? self::child($doc, $root, "functionKeys") : $root;
        $settingsParent = $root->nodeName === "settings" ? self::child($doc, $root, "phone-settings") : $root;
        $existing = [];
        foreach ($doc->getElementsByTagName("fkey") as $node) {
            $existing[$node->getAttribute("idx")] = $node;
        }
        foreach ($keys as $i => $key) {
            $idx = (string) ($i + 1);
            $node = $existing[$idx] ?? null;
            if (!$node) {
                $node = $doc->createElement("fkey");
                $node->setAttribute("idx", $idx);
                $parent->appendChild($node);
            }
            $kind = $key["kind"];
            $node->setAttribute("context", "active");
            $node->setAttribute("label", $key["label"]);
            $node->setAttribute("perm", "");
            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $text = ($kind === "blf" ? "blf <sip:" . $key["target"] . "@" . $tenant . ">" . (!empty($key["pickup"]) ? "|" . self::PICKUP_PREFIX : "") : ($kind === "speed" ? "speed " . $key["target"] : "line"));
            $node->appendChild($doc->createTextNode($text));
            // the D7xx screen shows the Short Label (else the raw number / SIP user): set both labels
            $node->setAttribute("short_label", $key["label"]);
            foreach (["fkey_label", "fkey_short_label"] as $setting) {
                $found = null;
                foreach ($doc->getElementsByTagName($setting) as $el) {
                    if ($el->getAttribute("idx") === $idx) {
                        $found = $el;
                    }
                }
                if (!$found) {
                    $found = $doc->createElement($setting);
                    $found->setAttribute("idx", $idx);
                    $found->setAttribute("perm", "");
                    $settingsParent->appendChild($found);
                }
                while ($found->firstChild) {
                    $found->removeChild($found->firstChild);
                }
                $found->appendChild($doc->createTextNode($key["label"]));
            }
        }
        return $doc->saveXML();
    }
}
