<?php
declare(strict_types=1);

use Phalcon\Db\Column;

/**
 * API-key endpoints for single-extension operations: query, call-profile
 * status, recording mode, enable/disable, external-call barring, welcome
 * email and the (read-only) storage path reports.
 *
 * Used by IndexController next to ApiSupportBase, so every method runs in the
 * controller's scope ($this->db, $this->redis, the Phalcon models).
 *
 * Request contract (all endpoints): POST /index/<endpoint> with a JSON body
 * containing "domain" and, unless noted, "extension" (digits only, 2-6 long).
 * Every query is scoped by domain_uuid and every request value is bound.
 */
trait ApiSupportExtension
{
    

    

    

    

    

    

    

    

    private function apiExtStoragePath(string $permission, string $category)
    {
        $ctx = $this->apiBegin($permission);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        if (array_key_exists('path', $data) || array_key_exists('new_path', $data)) {
            return $this->apiFail('The storage path is fixed and cannot be changed through the API.', 409);
        }

        $extension = null;
        if (isset($data['extension']) && trim((string)$data['extension']) !== '') {
            $extension = $this->apiExtNumber($data['extension']);
            if ($extension === null) {
                return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
            }
        }

        try {
            if ($extension !== null) {
                $exists = $this->db->fetchOne(
                    'SELECT 1 AS x FROM v_extensions WHERE domain_uuid = :d AND extension = :e',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'e' => $extension]
                );
                if (!$exists) {
                    return $this->apiFail('Extension not found', 404);
                }
            }

            try {
                $base = (new \Library\Storage())->pathFor($category, $domain);
            } catch (\Throwable $e) {
                $base = null;
            }
            if (!$base) {
                $base = ($category === 'recordings'
                    ? '/var/lib/freeswitch/recordings/'
                    : '/var/lib/freeswitch/storage/voicemail/default/') . $domain;
            }

            if ($category === 'recordings') {
                $path = rtrim($base, '/') . '/archive';
            } else {
                $path = rtrim($base, '/') . ($extension !== null ? '/' . $extension : '');
            }

            $present = is_dir($path);
            $files = 0;
            $bytes = 0;
            $truncated = false;
            if ($present) {
                $limit = 5000;
                try {
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($it as $file) {
                        if (!$file->isFile()) {
                            continue;
                        }
                        if ($files >= $limit) {
                            $truncated = true;
                            break;
                        }
                        $files++;
                        $bytes += (int)$file->getSize();
                    }
                } catch (\Throwable $e) {
                    $truncated = true;
                }
            }

            $payload = [
                'domain'     => $domain,
                'category'   => $category === 'recordings' ? 'recording' : 'voicemail',
                'path'       => $path,
                'path_fixed' => true,
                'exists'     => $present,
                'file_count' => $files,
                'size_bytes' => $bytes,
                'counts_truncated' => $truncated,
            ];
            if ($extension !== null) {
                $payload['extension'] = $extension;
            }
            return $this->apiOk($payload);
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }
    }

    /* ------------------------------------------------------------------ */
    /* helpers (apiExt prefix)                                             */
    /* ------------------------------------------------------------------ */

    /** Extension number: digits only, 2-6 long, else null. */
    private function apiExtNumber($value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string)$value);
        return preg_match('/^[0-9]{2,6}$/', $value) === 1 ? $value : null;
    }

    private function apiExtBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($v, ['false', '0', 'no'], true)) {
                return false;
            }
        }
        return null;
    }

    private function apiExtMaskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    private function apiExtRecordLabel(string $userRecord): string
    {
        switch ($userRecord) {
            case 'All':
                return 'all';
            case 'Inbound':
                return 'inbound';
            case 'Outbound':
                return 'outbound';
            default:
                return 'none';
        }
    }

    /** Call-profile names (5 slots), from q_call_behaviors.call_profiles with defaults. */
    private function apiExtProfileNames($behavior): array
    {
        $defaults = ['Available', 'Away', 'Busy', 'Custom 1', 'Custom 2'];
        $names = [];
        $csv = $behavior ? trim((string)$behavior->call_profiles) : '';
        $parts = $csv === '' ? [] : explode(',', $csv);
        foreach ($defaults as $i => $default) {
            $name = isset($parts[$i]) ? trim($parts[$i]) : '';
            $names[$i] = $name === '' ? $default : $name;
        }
        return $names;
    }

    /** Current profile index (0-4) from Redis `<ext>+<domain>`; 0 when unset. */
    private function apiExtProfileIndex(string $extension, string $domain): int
    {
        $v = $this->redis->get($extension . '+' . $domain);
        if ($v === null || $v === false || !is_numeric($v)) {
            return 0;
        }
        $i = (int)$v;
        return ($i >= 0 && $i <= 4) ? $i : 0;
    }

    /** status input to a 1-5 number, or null when invalid. */
    private function apiExtProfileNumber($status, array $names): ?int
    {
        if (is_bool($status) || is_array($status) || $status === null) {
            return null;
        }
        if (is_int($status) || (is_string($status) && preg_match('/^[0-9]+$/', trim($status)) === 1)) {
            $n = (int)$status;
            return ($n >= 1 && $n <= 5) ? $n : null;
        }
        if (!is_string($status)) {
            return null;
        }
        $key = strtolower(str_replace([' ', '_', '-'], '', trim($status)));
        $map = ['available' => 1, 'away' => 2, 'busy' => 3, 'custom1' => 4, 'custom2' => 5];
        if (isset($map[$key])) {
            return $map[$key];
        }
        foreach ($names as $i => $name) {
            if (strtolower(str_replace([' ', '_', '-'], '', $name)) === $key && $key !== '') {
                return $i + 1;
            }
        }
        return null;
    }

    /** Mobile app / web app / call control flags (true = allowed), computed from Redis. */
    private function apiExtAccess(string $extension, string $domain): array
    {
        return [
            'app'         => !$this->redis->exists('disableapp:' . $extension . ':' . $domain),
            'webapp'      => !$this->redis->exists('disablewebapp:' . $extension . ':' . $domain),
            'callcontrol' => !$this->redis->exists('disablecallcontrol:' . $extension . ':' . $domain),
        ];
    }

    /**
     * Port of AccountsController::outboundGateway: first outbound rule whose
     * prefix and length match gives [gateway_uuid, rewritten number]. The
     * gateway uuid is '' when the rule's action is not a gateway (e.g. BLOCK).
     * Returns null when no rule matches.
     */
    private function apiExtOutboundGateway(array $rules, string $destination, string $domainUuid): ?array
    {
        foreach ($rules as $rule) {
            $prefix = (string)$rule->prefix_pattern;
            if (!str_starts_with($destination, $prefix)) {
                continue;
            }
            $length = (string)$rule->calls_number_length;
            if (str_contains($length, '-')) {
                $range = explode('-', $length);
                $min = (int)($range[0] ?? 0);
                $max = (int)($range[1] ?? 0);
                if (!(strlen($destination) >= $min && strlen($destination) <= $max)) {
                    continue;
                }
            } elseif (!(strlen($destination) == (int)$length || (int)$length == 0)) {
                continue;
            }
            if ($rule->strip) {
                $destination = substr_replace($destination, '', 0, (int)$rule->strip);
            }
            if ($rule->prepend) {
                $destination = $rule->prepend . $destination;
            }
            $gateway = VGateways::findFirst([
                'conditions' => 'domain_uuid = :d: AND gateway = :g:',
                'bind'       => ['d' => $domainUuid, 'g' => strtolower((string)$rule->action)],
            ]);
            return [$gateway ? (string)$gateway->gateway_uuid : '', $destination];
        }
        return null;
    }

    /** Port of AccountsController::followMeTargets. */
    private function apiExtFollowMeTargets($value, int $index): array
    {
        $slots = explode(',', (string)$value);
        if (count($slots) > 5) {
            $candidates = array_slice($slots, 0, 3);
        } else {
            $candidates = explode('|', $slots[$index] ?? '');
        }
        $targets = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '' || $candidate === 'hangup' || str_starts_with($candidate, '*99') || !preg_match('/^\+?[0-9]+$/', $candidate)) {
                continue;
            }
            $targets[] = $candidate;
            if (count($targets) === 3) {
                break;
            }
        }
        return $targets;
    }

    /** Port of AccountsController::applyFollowMe. */
    private function apiExtApplyFollowMe($vExtension, $behavior, array $rules, int $index, string $domain, string $domainUuid): void
    {
        foreach (['' => 'follow_internal', '2' => 'follow_external'] as $suffix => $column) {
            $vExtension->{$column} = null;
            $vExtension->{$column . '2'} = null;
            $vExtension->{$column . '3'} = null;
            $enabled = explode(',', (string)$behavior->{'follow_me_enabled' . $suffix});
            if (($enabled[$index] ?? 'false') !== 'true') {
                continue;
            }
            $position = 0;
            foreach ($this->apiExtFollowMeTargets($behavior->{'follow_me_destinations' . $suffix}, $index) as $value) {
                if (strlen($value) > 4) {
                    $output = $this->apiExtOutboundGateway($rules, $value, $domainUuid);
                    if (!is_array($output) || empty($output[0])) {
                        continue;
                    }
                    $target = 'sofia/gateway/' . $output[0] . '/' . $output[1];
                } else {
                    $target = 'user/' . $value . '@' . $domain;
                }
                $vExtension->{$column . ($position === 0 ? '' : strval($position + 1))} = $target;
                $position++;
            }
        }
    }

    /**
     * Delete FreeSWITCH memcache directory entries through ESL, like
     * node/signal.js does. Best effort: a failure only means the entry expires
     * with its normal TTL (the file caches are already unlinked).
     */
    private function apiExtEslMemcacheDelete(array $keys): void
    {
        $socket = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 2);
        if (!$socket) {
            return;
        }
        try {
            stream_set_timeout($socket, 2);
            $guard = 0;
            while (($line = fgets($socket)) !== false && $line !== "\n" && ++$guard < 50) {
            }
            fwrite($socket, "auth ClueCon\n\n");
            $guard = 0;
            while (($line = fgets($socket)) !== false && $line !== "\n" && ++$guard < 50) {
            }
            foreach ($keys as $key) {
                if (preg_match('/^directory:[A-Za-z0-9_.\-]+@[A-Za-z0-9_.\-]+$/', (string)$key) !== 1) {
                    continue;
                }
                fwrite($socket, 'api memcache delete ' . $key . "\n\n");
                $length = 0;
                $guard = 0;
                while (($line = fgets($socket)) !== false && $line !== "\n" && ++$guard < 50) {
                    if (stripos($line, 'Content-Length:') === 0) {
                        $length = (int)trim(substr($line, 15));
                    }
                }
                if ($length > 0 && $length < 4096) {
                    fread($socket, $length);
                }
            }
        } catch (\Throwable $e) {
            error_log('api esl: ' . $e->getMessage());
        }
        fclose($socket);
    }

    /**
     * Outbound-rules aggregate exactly as the UI rebuilds it after a reorder/delete:
     * every per-rule row (disabled, description outbound-rule) by dialplan_order ASC,
     * each prefixed with a newline.
     */
    private function apiExtOutboundRulesXml(string $domainUuid): string
    {
        $xml = '';
        $rows = VDialplans::find([
            'conditions' => 'domain_uuid = :d: AND dialplan_enabled = :f: AND dialplan_description = :s:',
            'bind'       => ['d' => $domainUuid, 'f' => 'false', 's' => 'outbound-rule'],
            'order'      => 'dialplan_order ASC',
        ]);
        foreach ($rows as $row) {
            $xml .= "\n" . $row->dialplan_xml;
        }
        return $xml;
    }

    /**
     * XML for the per-extension block rule. Same <extension> skeleton the UI
     * generator emits for a BLOCK rule (ApiController::outboundrulesAction):
     * user_exists=false guard, caller condition on the extension, destination
     * condition with break="on-true", hangup OUTGOING_CALL_BARRED.
     *
     * "External" destination = E.164 style (+digits) or a digit string longer
     * than every internal number of the domain (extensions, teams, queues,
     * IVRs, conference centers; at least 6), so internal calls to queues,
     * ring groups and IVRs (which also have user_exists=false) are not blocked.
     */
    private function apiExtBlockRuleXml(string $name, string $uuid, string $extension, string $domainUuid): string
    {
        $longest = 6;
        $sources = [
            'SELECT MAX(LENGTH(extension)) AS m FROM v_extensions WHERE domain_uuid = :d',
            'SELECT MAX(LENGTH(ring_group_extension)) AS m FROM v_ring_groups WHERE domain_uuid = :d',
            'SELECT MAX(LENGTH(queue_extension)) AS m FROM v_call_center_queues WHERE domain_uuid = :d',
            'SELECT MAX(LENGTH(ivr_menu_extension)) AS m FROM v_ivr_menus WHERE domain_uuid = :d',
            'SELECT MAX(LENGTH(conference_center_extension)) AS m FROM v_conference_centers WHERE domain_uuid = :d',
        ];
        foreach ($sources as $sql) {
            try {
                $row = $this->db->fetchOne($sql, \Phalcon\Db\Enum::FETCH_ASSOC, ['d' => $domainUuid]);
                if ($row && $row['m'] !== null) {
                    $longest = max($longest, (int)$row['m']);
                }
            } catch (\Throwable $e) {
                error_log('api block rule length: ' . $e->getMessage());
            }
        }
        $minExternal = $longest + 1;

        return '<extension name="' . $name . '" continue="false" uuid="' . $uuid . '">
                            <condition field="${user_exists}" expression="false"/>
                            <condition field="${effective_caller_id_number}" expression="^' . $extension . '$"/>
                            <condition field="destination_number" expression="^(?:\+\d+|\d{' . $minExternal . ',})$" break="on-true">
                                <action application="export" data="call_direction=outbound" inline="true"/>
                                <action application="export" data="rule=' . $name . '"/>
                                <action application="hangup" data="OUTGOING_CALL_BARRED"/>
                            </condition>
                        </extension>';
    }
}
