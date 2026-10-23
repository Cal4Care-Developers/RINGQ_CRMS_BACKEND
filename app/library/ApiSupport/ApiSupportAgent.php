<?php
declare(strict_types=1);

/**
 * API-key endpoints for agents (a user + extension pair):
 *   POST /index/addAgent     permission add_agent
 *   POST /index/updateAgent  permission update_agent
 *   POST /index/deleteAgent  permission delete_agent
 *
 * Ported from AccountsController::extensionAction (type 1 add, type 2 update,
 * type 5 delete) with the tenant taken from the API context instead of the web
 * session. Request values are only ever used as bound parameters.
 *
 * Deliberately NOT ported (see the endpoint docs): welcome e-mail / QR code /
 * verify2 registration record, provisioning (MAC address) templates, work-hour
 * and break schedules, SIP-ID (force_ping) and IVR digit options.
 */
trait ApiSupportAgent
{
    

    

    

    /* ================================================================== */
    /* helpers                                                             */
    /* ================================================================== */

    /**
     * Validate and normalise the request body. Returns the cleaned fields, or an
     * error message string. $full = add (everything mandatory/defaulted).
     * Only keys present in the request are returned for optional fields, so the
     * update endpoint can tell "not sent" from "sent empty".
     *
     * @return array|string
     */
    protected function apiAgentValidate(array $data, bool $full)
    {
        $out = [];

        $extension = trim((string)($data['extension'] ?? ''));
        if (!preg_match('/^[0-9]{4}$/', $extension)) {
            return 'extension must be exactly 4 digits.';
        }
        $out['extension'] = $extension;

        $nameRe = '/^[\p{L}\p{M}][\p{L}\p{M} .\'\-]{0,49}$/u';
        foreach (['first_name', 'last_name'] as $f) {
            if ($full || array_key_exists($f, $data)) {
                $v = $data[$f] ?? '';
                if (!is_string($v)) {
                    return $f . ' must be a string.';
                }
                $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
                if (!preg_match($nameRe, $v)) {
                    return $f . ' must be 1-50 characters: letters, spaces, dot, apostrophe or hyphen.';
                }
                $out[$f] = $v;
            }
        }

        if ($full || array_key_exists('email', $data)) {
            $v = $data['email'] ?? '';
            if (!is_string($v)) {
                return 'email must be a string.';
            }
            $v = trim($v);
            if (strlen($v) > 254 || !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                return 'email is not a valid email address.';
            }
            $out['email'] = $v;
        }

        if (array_key_exists('group', $data)) {
            $v = $data['group'];
            if (!is_string($v) || !preg_match('/^[A-Za-z0-9_\-]{1,50}$/', trim($v))) {
                return 'group is not valid.';
            }
            $out['group'] = strtolower(trim($v));
        }

        if (array_key_exists('description', $data)) {
            $v = $data['description'];
            if (!is_string($v)) {
                return 'description must be a string.';
            }
            $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
            if (mb_strlen($v) > 255) {
                return 'description must be at most 255 characters.';
            }
            $out['description'] = $v;
        }

        if (array_key_exists('outbound_caller_id_name', $data)) {
            $v = $data['outbound_caller_id_name'];
            if (!is_string($v)) {
                return 'outbound_caller_id_name must be a string.';
            }
            $v = trim($v);
            if ($v !== '' && !preg_match('/^[\p{L}\p{N} .\'_\-+()]{1,50}$/u', $v)) {
                return 'outbound_caller_id_name must be 1-50 characters: letters, digits, space and . \' _ - + ( ).';
            }
            $out['outbound_caller_id_name'] = $v;
        }

        if (array_key_exists('outbound_caller_id_number', $data)) {
            $v = $data['outbound_caller_id_number'];
            if (!is_string($v) && !is_int($v)) {
                return 'outbound_caller_id_number must be a string.';
            }
            $v = trim((string)$v);
            if ($v !== '' && !preg_match('/^\+?[0-9]{3,20}$/', $v)) {
                return 'outbound_caller_id_number must be 3-20 digits (optional leading +).';
            }
            $out['outbound_caller_id_number'] = $v;
        }

        if (array_key_exists('mobile_number', $data)) {
            $v = $data['mobile_number'];
            if (!is_string($v) && !is_int($v)) {
                return 'mobile_number must be a string.';
            }
            $v = trim((string)$v);
            if ($v !== '' && !preg_match('/^\+?[0-9]{5,20}$/', $v)) {
                return 'mobile_number must be 5-20 digits (optional leading +).';
            }
            $out['mobile_number'] = $v;
        }

        return $out;
    }

    /** v_groups lookup (global groups or this domain's). superadmin can never be granted. */
    protected function apiAgentResolveGroup(string $domainUuid, string $name): ?array
    {
        $name = strtolower(trim($name));
        if ($name === '' || $name === 'superadmin') {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT group_uuid, group_name FROM v_groups
              WHERE group_name = :g AND (domain_uuid IS NULL OR domain_uuid = :d)
              ORDER BY (domain_uuid IS NULL) DESC LIMIT 1',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['g' => $name, 'd' => $domainUuid]
        );
        return $row ? ['group_uuid' => (string)$row['group_uuid'], 'group_name' => (string)$row['group_name']] : null;
    }

    protected function apiAgentEmailTaken(string $domainUuid, string $email, ?string $exceptUserUuid): bool
    {
        $sql = 'SELECT 1 AS x FROM v_users WHERE domain_uuid = :d AND LOWER(user_email) = LOWER(:m)';
        $bind = ['d' => $domainUuid, 'm' => $email];
        if ($exceptUserUuid !== null) {
            $sql .= ' AND user_uuid != :u';
            $bind['u'] = $exceptUserUuid;
        }
        return (bool)$this->db->fetchOne($sql . ' LIMIT 1', \Phalcon\Db\Enum::FETCH_ASSOC, $bind);
    }

    /** Port of AccountsController::extensionNumberOwner() with SQL. Returns a label or null when free. */
    protected function apiAgentNumberOwner(string $domainUuid, string $number): ?string
    {
        $checks = [
            ['an extension',     'v_extensions',          'extension'],
            ['a team',           'v_ring_groups',         'ring_group_extension'],
            ['a team voicemail', 'v_voicemails',          'voicemail_id'],
            ['a call queue',     'v_call_center_queues',  'queue_extension'],
            ['an IVR',           'v_ivr_menus',           'ivr_menu_extension'],
            ['a conference',     'v_conference_centers',  'conference_center_extension'],
            ['a conference',     'v_conferences',         'conference_extension'],
            ['an eFax',          'v_fax',                 'fax_extension'],
            ['a dialplan entry', 'v_dialplans',           'dialplan_number'],
        ];
        foreach ($checks as [$label, $table, $column]) {
            $found = $this->db->fetchOne(
                "SELECT 1 AS x FROM $table WHERE domain_uuid = :d AND $column = :v LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'v' => $number]
            );
            if ($found) {
                return $label;
            }
        }
        return null;
    }

    protected function apiAgentSave($model, string $what): void
    {
        if (!$model->save()) {
            $msgs = [];
            foreach ($model->getMessages() as $m) {
                $msgs[] = (string)$m;
            }
            error_log('agent api: could not save ' . $what . ': ' . implode('; ', $msgs));
            throw new \RuntimeException('An error has occurred while saving the ' . $what . '.');
        }
    }

    /**
     * Add (true) or remove (false) an extension in q_company_modes mode1 + permission4
     * (add) or in every mode/permission list (remove). No row = nothing to do.
     */
    protected function apiAgentModesList(string $domainUuid, string $extension, bool $add): void
    {
        $cols = $add
            ? ['mode1', 'permission4']
            : ['mode1', 'mode2', 'mode3', 'mode4', 'mode5', 'mode6',
               'permission1', 'permission2', 'permission3', 'permission4'];
        $row = $this->db->fetchOne(
            'SELECT ' . implode(', ', $cols) . ' FROM q_company_modes WHERE domain_uuid = :d LIMIT 1',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        );
        if (!$row) {
            return;
        }
        $set = [];
        $bind = ['d' => $domainUuid];
        foreach ($cols as $c) {
            $cur = (string)($row[$c] ?? '');
            if ($add) {
                $list = $cur === '' ? [] : explode(',', $cur);
                $list[] = $extension;
                $new = implode(',', $list);
            } else {
                if ($cur === '') {
                    continue;
                }
                $new = $this->apiAgentWithout($cur, $extension);
            }
            $set[] = "$c = :$c";
            $bind[$c] = $new;
        }
        if ($set) {
            $this->db->execute('UPDATE q_company_modes SET ' . implode(', ', $set) . ' WHERE domain_uuid = :d', $bind);
        }
    }

    protected function apiAgentWithout(string $csv, string $value): string
    {
        if ($csv === '') {
            return '';
        }
        return implode(',', array_values(array_diff(explode(',', $csv), [$value])));
    }

    /** Delete dialplans (and their details) matching a fixed condition fragment with bound params. */
    protected function apiAgentDeleteDialplans(string $domainUuid, string $condition, array $bind): void
    {
        $bind['d'] = $domainUuid;
        $this->db->execute(
            "DELETE FROM v_dialplan_details WHERE domain_uuid = :d AND dialplan_uuid IN
                (SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :d AND $condition)",
            $bind
        );
        $this->db->execute("DELETE FROM v_dialplans WHERE domain_uuid = :d AND $condition", $bind);
    }

    /** Rebuild the domain's "change_dest_number" dialplan from all enabled numeric extensions. */
    protected function apiAgentRebuildChangeDest(string $domainUuid, string $domain): void
    {
        $esc = static function (string $v): string {
            return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        };
        $exts = $this->db->fetchAll(
            "SELECT extension, number_alias FROM v_extensions WHERE domain_uuid = :d AND enabled = 'true'",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        );
        $pings = [];
        foreach ($this->db->fetchAll(
            'SELECT extension, force_ping FROM q_call_behaviors WHERE domain_uuid = :d AND force_ping IS NOT NULL',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        ) as $p) {
            $pings[(string)$p['extension']] = (string)$p['force_ping'];
        }

        $xml = '<extension name="change_dest_number">';
        foreach ($exts as $e) {
            $number = (string)$e['extension'];
            if (!is_numeric($number)) {
                continue;
            }
            $alias = $esc((string)$e['number_alias']);
            $xml .= '<condition field="destination_number" expression="^' . $esc($number) . '$">
					        <action application="log" data="INFO About to bridge call for ${destination_number}"/>
					        <action application="transfer" data="' . $alias . ' XML default"/>
					    </condition>';
            if (isset($pings[$number]) && $pings[$number] !== '') {
                $xml .= '<condition field="destination_number" expression="^' . $esc($pings[$number]) . '$">
						        <action application="log" data="INFO About to bridge call for ${destination_number}"/>
						        <action application="transfer" data="' . $alias . ' XML default"/>
						    </condition>';
            }
        }
        $xml .= '</extension>';

        $now = date('Y-m-d H:i:s');
        $existing = $this->db->fetchOne(
            "SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :d AND dialplan_name = 'change_dest_number' LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $domainUuid]
        );
        if ($existing) {
            $this->db->execute(
                'UPDATE v_dialplans SET dialplan_xml = :x, update_date = :t WHERE domain_uuid = :d AND dialplan_uuid = :u',
                ['x' => $xml, 't' => $now, 'd' => $domainUuid, 'u' => $existing['dialplan_uuid']]
            );
            return;
        }
        $dp = new VDialplans();
        $dp->domain_uuid = $domainUuid;
        $dp->dialplan_uuid = $this->apiUuid();
        $dp->app_uuid = $this->apiUuid();
        $dp->dialplan_name = 'change_dest_number';
        $dp->dialplan_order = 2;
        $dp->dialplan_context = $domain;
        $dp->dialplan_continue = 'true';
        $dp->dialplan_xml = $xml;
        $dp->dialplan_enabled = 'true';
        $dp->insert_date = $now;
        $this->apiAgentSave($dp, 'dialplan');
    }

    /** The per-domain list caches the UI drops whenever users / extensions / agents change. */
    protected function apiAgentFlushLists(string $sub): void
    {
        $this->apiRedisDel([
            'allVusers-' . $sub,
            'allQUsers-' . $sub,
            'vExtensions-' . $sub,
            'vCallCenterAgents-' . $sub,
            'vCallCenterAgents5-' . $sub,
            'allVCallCenterTiers-' . $sub,
        ]);
    }

    /** Fresh call-forward / follow-me / screening lists for a new extension (same defaults as the UI). */
    protected function apiAgentResetForwardKeys(string $extension, string $sub): void
    {
        $p = $extension . '@' . $sub . '-';
        $r = $this->redis;
        $names = ['forward-all-destination', 'forward-busy-destination', 'forward-no-answer-destination',
                  'forward-no-answer-timeout', 'follow-me-destinations', 'forward-all-enabled',
                  'forward-busy-enabled', 'forward-no-answer-enabled', 'forward-me-enabled', 'callScreening'];
        foreach ($names as $n) {
            $r->del($p . $n);
            $r->del($p . $n . '2');
        }
        $blank5 = ['', '', '', '', ''];
        $no = '*99' . $extension;
        $noAns = [$no, $no, $no, $no, $no];
        $f5 = ['false', 'false', 'false', 'false', 'false'];
        $t5 = ['true', 'true', 'true', 'true', 'true'];
        $defaults = [
            'forward-all-destination'       => $blank5,
            'forward-busy-destination'      => $blank5,
            'forward-no-answer-destination' => $noAns,
            'forward-no-answer-timeout'     => ['30', '30', '30', '30', '30'],
            'follow-me-destinations'        => array_fill(0, 15, ''),
            'forward-all-enabled'           => $f5,
            'forward-busy-enabled'          => $f5,
            'forward-no-answer-enabled'     => $t5,
            'forward-me-enabled'            => $f5,
            'callScreening'                 => $f5,
        ];
        foreach ($defaults as $n => $values) {
            $r->rpush($p . $n, $values);
            $r->rpush($p . $n . '2', $values);
        }
        // The UI additionally pushes five blank entries onto the no-answer timeouts (kept for parity).
        $r->rpush($p . 'forward-no-answer-timeout', $blank5);
        $r->rpush($p . 'forward-no-answer-timeout2', $blank5);
    }
}
