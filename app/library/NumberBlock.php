<?php
declare(strict_types=1);

/**
 * Centralised number blocking (Global Settings > Security > Number blocking).
 *
 * One list per company (q_number_blocks, keyed by domain_uuid). The call
 * server applies it in ringq_number_block.lua (global dial plan
 * ringq_number_block): inbound calls whose caller ID matches an entry are
 * rejected, and when an entry blocks outbound, calls from an extension to a
 * matching outside number are refused.
 *
 * An entry is either an exact number or a prefix. Nothing that belongs to the
 * system may be blocked: the company's extensions, IVRs, queues, ring groups,
 * conferences, feature codes / system numbers (dial plan numbers such as 5555)
 * and extension caller IDs, and every DID on the server. A prefix is refused
 * when any of those numbers starts with it.
 *
 * The matching below must stay the same as in
 * app/scripts/number-block/ringq_number_block.lua.
 */
class NumberBlock
{
    const TYPES = ["number", "prefix"];
    const DIRECTIONS = ["inbound", "outbound", "both"];
    const MIN_DIGITS = 3;
    const MAX_DIGITS = 20;
    /** Two numbers of different length match when the shorter one is at least this long and ends the longer one. */
    const SUFFIX_MIN = 7;
    const DESCRIPTION_MAX = 64;

    const TABLE_SQL = "CREATE TABLE IF NOT EXISTS public.q_number_blocks (
        number_block_uuid CHAR(36) PRIMARY KEY,
        domain_uuid CHAR(36) NOT NULL,
        block_number VARCHAR(32) NOT NULL,
        block_type VARCHAR(10) NOT NULL DEFAULT 'number',
        block_direction VARCHAR(10) NOT NULL DEFAULT 'inbound',
        description VARCHAR(64) NOT NULL DEFAULT '',
        enabled BOOLEAN NOT NULL DEFAULT true,
        block_count INTEGER NOT NULL DEFAULT 0,
        last_blocked_at TIMESTAMP,
        insert_user CHAR(36),
        insert_date TIMESTAMP DEFAULT now(),
        update_date TIMESTAMP
    );";
    const INDEX_SQL = "CREATE INDEX IF NOT EXISTS q_number_blocks_domain ON public.q_number_blocks (domain_uuid, enabled);";

    public static function ensureTable($db): void
    {
        $db->execute(self::TABLE_SQL);
        $db->execute(self::INDEX_SQL);
    }

    /** Digits only (leading zeros kept). */
    public static function digits(string $number): string
    {
        return preg_replace('/\D+/', '', $number);
    }

    /** Digits without the international / trunk zeros: "+65 9123", "0065 9123" and "09123" compare alike. */
    public static function canonical(string $number): string
    {
        return ltrim(self::digits($number), "0");
    }

    /** Would an exact-number entry $entry block calls from/to $number? */
    public static function numberMatches(string $number, string $entry): bool
    {
        $c = self::canonical($number);
        $e = self::canonical($entry);
        if ($c === "" || $e === "") {
            return false;
        }
        if ($c === $e) {
            return true;
        }
        $short = strlen($c) < strlen($e) ? $c : $e;
        $long = $short === $c ? $e : $c;
        return strlen($short) >= self::SUFFIX_MIN && substr($long, -strlen($short)) === $short;
    }

    /** Would a prefix entry $prefix block calls from/to $number? */
    public static function prefixMatches(string $number, string $prefix): bool
    {
        $p = self::digits($prefix);
        if ($p === "") {
            return false;
        }
        if (strpos(self::digits($number), $p) === 0) {
            return true;
        }
        $pc = self::canonical($prefix);
        return $pc !== "" && strpos(self::canonical($number), $pc) === 0;
    }

    public static function matches(string $number, string $entry, string $type): bool
    {
        return $type === "prefix" ? self::prefixMatches($number, $entry) : self::numberMatches($number, $entry);
    }

    /**
     * Check one entry's shape. Returns [clean number, error or null].
     */
    public static function validateShape(string $number, string $type): array
    {
        $number = trim($number);
        if ($number === "") {
            return ["", "Enter a number to block."];
        }
        if (!preg_match('/^\+?[0-9][0-9 ().\-]*$/', $number)) {
            return ["", "A blocked number may contain only digits, with an optional + at the start."];
        }
        $digits = self::digits($number);
        if (strlen($digits) < self::MIN_DIGITS) {
            return ["", ($type === "prefix" ? "A prefix" : "A number") . " needs at least " . self::MIN_DIGITS . " digits."];
        }
        if (strlen($digits) > self::MAX_DIGITS) {
            return ["", "That number is too long (at most " . self::MAX_DIGITS . " digits)."];
        }
        $clean = (substr($number, 0, 1) === "+" ? "+" : "") . $digits;
        return [$clean, null];
    }

    /**
     * Every number that belongs to the system, as [number, what it is].
     * Company items come from $domainUuid; DIDs come from the whole server.
     */
    public static function systemNumbers($db, string $domainUuid): array
    {
        $out = [];
        $add = function ($number, string $label) use (&$out) {
            $number = trim((string) $number);
            // feature codes such as *97 or patterns cannot clash with a digits-only entry
            if ($number === "" || !preg_match('/^\+?[0-9]+$/', $number)) {
                return;
            }
            $out[] = [$number, $label];
        };
        $rows = function (string $sql, array $bind = []) use ($db): array {
            try {
                return $db->fetchAll($sql, \Phalcon\Db\Enum::FETCH_ASSOC, $bind);
            } catch (\Throwable $e) {
                error_log("numberblock: system numbers query failed: " . $e->getMessage());
                return [];
            }
        };
        $d = ["d" => $domainUuid];

        foreach ($rows("SELECT extension, number_alias, outbound_caller_id_number, effective_caller_id_number, emergency_caller_id_number
                          FROM v_extensions WHERE domain_uuid = CAST(:d AS uuid)", $d) as $r) {
            $add($r["extension"], "extension " . $r["extension"]);
            $add($r["number_alias"], "extension " . $r["extension"] . " (number alias)");
            foreach (["outbound_caller_id_number", "effective_caller_id_number", "emergency_caller_id_number"] as $col) {
                $add($r[$col], "the caller ID of extension " . $r["extension"]);
            }
        }
        foreach ($rows("SELECT ivr_menu_name, ivr_menu_extension FROM v_ivr_menus WHERE domain_uuid = CAST(:d AS uuid)", $d) as $r) {
            $add($r["ivr_menu_extension"], "IVR " . $r["ivr_menu_name"] . " (" . $r["ivr_menu_extension"] . ")");
        }
        foreach ($rows("SELECT queue_name, queue_extension, queue_outbound_caller_id_number FROM v_call_center_queues WHERE domain_uuid = CAST(:d AS uuid)", $d) as $r) {
            $add($r["queue_extension"], "queue " . $r["queue_name"] . " (" . $r["queue_extension"] . ")");
            $add($r["queue_outbound_caller_id_number"], "the caller ID of queue " . $r["queue_name"]);
        }
        foreach ($rows("SELECT ring_group_name, ring_group_extension, ring_group_caller_id_number FROM v_ring_groups WHERE domain_uuid = CAST(:d AS uuid)", $d) as $r) {
            $add($r["ring_group_extension"], "ring group " . $r["ring_group_name"] . " (" . $r["ring_group_extension"] . ")");
            $add($r["ring_group_caller_id_number"], "the caller ID of ring group " . $r["ring_group_name"]);
        }
        foreach ($rows("SELECT conference_center_name, conference_center_extension FROM v_conference_centers WHERE domain_uuid = CAST(:d AS uuid)", $d) as $r) {
            $add($r["conference_center_extension"], "conference " . $r["conference_center_name"] . " (" . $r["conference_center_extension"] . ")");
        }
        foreach ($rows("SELECT conference_name, conference_extension FROM v_conferences WHERE domain_uuid = CAST(:d AS uuid)", $d) as $r) {
            $add($r["conference_extension"], "conference " . $r["conference_name"] . " (" . $r["conference_extension"] . ")");
        }
        // feature codes and system numbers: this company's dial plans and the global ones
        foreach ($rows("SELECT dialplan_name, dialplan_number FROM v_dialplans
                         WHERE (domain_uuid = CAST(:d AS uuid) OR domain_uuid IS NULL)
                           AND dialplan_context <> 'public' AND COALESCE(dialplan_number, '') <> ''", $d) as $r) {
            foreach (preg_split('/[\s,|]+/', (string) $r["dialplan_number"]) as $n) {
                $add($n, "system number " . $n . " (" . $r["dialplan_name"] . ")");
            }
        }
        // DIDs: every company's on this server
        foreach ($rows("SELECT destination_number, CAST(domain_uuid AS text) AS domain_uuid FROM v_destinations
                         WHERE COALESCE(destination_number, '') <> ''") as $r) {
            $own = strcasecmp((string) $r["domain_uuid"], $domainUuid) === 0;
            $add($r["destination_number"], ($own ? "your DID " : "a DID on this server: ") . $r["destination_number"]);
        }
        foreach ($rows("SELECT dialplan_number, CAST(domain_uuid AS text) AS domain_uuid FROM v_dialplans
                         WHERE dialplan_context = 'public' AND COALESCE(dialplan_number, '') <> ''") as $r) {
            $own = strcasecmp((string) $r["domain_uuid"], $domainUuid) === 0;
            $add($r["dialplan_number"], ($own ? "your DID " : "a DID on this server: ") . $r["dialplan_number"]);
        }
        return $out;
    }

    /** The first system number the entry would block, as [number, label], or null. */
    public static function findConflict(array $systemNumbers, string $entry, string $type): ?array
    {
        foreach ($systemNumbers as $sys) {
            if (self::matches($sys[0], $entry, $type)) {
                return $sys;
            }
        }
        return null;
    }

    public static function conflictMessage(string $entry, string $type, array $conflict): string
    {
        return "Cannot block " . $entry . ": " . ($type === "prefix" ? "it would block " : "it matches ") . $conflict[1] . ". Numbers that belong to this system cannot be blocked.";
    }
}
