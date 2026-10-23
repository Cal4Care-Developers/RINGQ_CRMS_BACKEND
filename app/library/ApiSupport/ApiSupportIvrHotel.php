<?php
declare(strict_types=1);

use Phalcon\Db\Column;

/**
 * API-key endpoints for IVR menus (add / delete) and the Hotel module.
 * Used by IndexController together with ApiSupportBase.
 *
 *   POST /index/addIvr       permission add_ivr
 *   POST /index/deleteIvr    permission delete_ivr
 *   POST /index/hotelModule  permission hotel_module (body field "action")
 *
 * Ported from ApiController::ivrAction (type 1 create, type 3 delete) and
 * HotelController. Request values are never interpolated into SQL.
 */
trait ApiSupportIvrHotel
{
    /* ------------------------------------------------------------------ */
    /* IVR                                                                 */
    /* ------------------------------------------------------------------ */

    /** Escape a value for an XML attribute / text node. */
    private function apiIvrXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Strict boolean from JSON (true/false/1/0/"true"/"false"); null when not a boolean. */
    private function apiIvrBool($value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($v, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return null;
    }

    /**
     * Turn {type, value} into the ivr action/param pair the UI stores.
     * @return array{0:string,1:string}|string  [action, param] or an error message
     */
    private function apiIvrTarget($type, $value, string $domain, string $label)
    {
        $type = is_string($type) ? strtolower(trim($type)) : '';
        switch ($type) {
            case 'extension':
            case 'voicemail':
                $value = is_scalar($value) ? trim((string)$value) : '';
                if (!preg_match('/^[0-9]{1,10}$/', $value)) {
                    return $label . ': value must be 1-10 digits for type ' . $type;
                }
                $app = $type === 'voicemail' ? 'transfer *99' : 'transfer ';
                return ['menu-exec-app', $app . $value . ' XML ' . $domain];
            case 'menu-back':
                return ['menu-back', ''];
            case 'menu-exit':
                return ['menu-exit', ''];
        }
        return $label . ': invalid type (use extension, voicemail, menu-back or menu-exit)';
    }

    /** Remove every cache entry an IVR create/delete invalidates. */
    private function apiIvrClearCaches(string $domain, string $domainUuid, string $ivrMenuUuid): void
    {
        $this->apiRedisDel([
            'vIvrMenus-' . $domain,
            'vIvrMenuOptionsRaw-' . $domain,
            'allDialplans-' . $domain,
        ]);
        $this->apiUnlinkCache([
            'dialplan.' . $domain,
            'dialplan.public',
            'configuration.ivr.conf.' . $ivrMenuUuid,
        ]);
    }

    

    

    

    private function apiHotelIvrKey(array $ctx): string
    {
        return 'landing:' . $ctx['domain'] . '-wakeup-ivr-extension';
    }

    private function apiHotelMenuKey(array $ctx): string
    {
        return 'landing:' . $ctx['domain'] . '-hotel-frontdesk-menu-enabled';
    }

    /** Idempotent schema creation, as the UI does. */
    private function apiHotelEnsureTables(): void
    {
        try {
            $this->db->execute("CREATE TABLE IF NOT EXISTS public.q_hotel_checkins (
                hotel_checkin_uuid CHAR(36) PRIMARY KEY,
                domain_uuid CHAR(36) NOT NULL,
                extension_uuid CHAR(36) NOT NULL,
                extension VARCHAR(32) NOT NULL,
                guest_firstname VARCHAR(255) NOT NULL,
                guest_lastname VARCHAR(255) NOT NULL,
                insert_user CHAR(36),
                insert_date TIMESTAMP DEFAULT now()
            );");
            $this->db->execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_hotel_checkins_ext ON public.q_hotel_checkins (domain_uuid, extension_uuid);");

            $this->db->execute("CREATE TABLE IF NOT EXISTS public.q_hotel_wakeup_calls (
                hotel_wakeup_call_uuid CHAR(36) PRIMARY KEY,
                domain_uuid CHAR(36) NOT NULL,
                extension_uuid CHAR(36) NOT NULL,
                extension VARCHAR(32) NOT NULL,
                wake_hour INT NOT NULL,
                wake_minute INT NOT NULL,
                wake_ampm VARCHAR(2) NOT NULL,
                wake_datetime TIMESTAMP NOT NULL,
                status INT NOT NULL DEFAULT 1,
                attempts INT NOT NULL DEFAULT 0,
                insert_user CHAR(36),
                insert_date TIMESTAMP DEFAULT now()
            );");
            $this->db->execute("CREATE INDEX IF NOT EXISTS idx_hotel_wakeup_due ON public.q_hotel_wakeup_calls (status, wake_datetime);");
            $this->db->execute("CREATE INDEX IF NOT EXISTS idx_hotel_wakeup_ext ON public.q_hotel_wakeup_calls (domain_uuid, extension_uuid);");
        } catch (\Throwable $e) {
            error_log('api hotel ensureTables failed: ' . $e->getMessage());
        }
    }

    /** Tell the tenant's front desks a room changed (best effort, 2s timeout). */
    private function apiHotelNotify(array $ctx, $extension): void
    {
        try {
            $client = new \ElephantIO\Client(\ElephantIO\Client::engine(\ElephantIO\Client::CLIENT_4X, 'ws://127.0.0.1:3000', [
                'persistent' => false,
                'timeout'    => 2,
            ]));
            $client->initialize();
            $client->emit('hotelRooms', [
                'subdomain' => (string)$ctx['domain'],
                'extension' => (string)$extension,
            ]);
            $client->close();
        } catch (\Throwable $e) {
            error_log('hotelRooms notify failed: ' . $e->getMessage());
        }
    }

    /** Validate the "extension" field and load the domain's extension, or return an error response. */
    private function apiHotelExtension(array $ctx)
    {
        $ext = $ctx['data']['extension'] ?? null;
        $ext = is_scalar($ext) ? trim((string)$ext) : '';
        if (!preg_match('/^[0-9]{1,10}$/', $ext)) {
            return $this->apiFail('Invalid extension: digits only (max 10)', 400);
        }
        $vExtension = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension = :extension:',
            'bind'       => ['domain_uuid' => $ctx['domain_uuid'], 'extension' => $ext],
            'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if (!$vExtension) {
            return $this->apiFail('Extension not found', 404);
        }
        return $vExtension;
    }

    private function apiHotelCheckinRow(array $ctx, string $extensionUuid)
    {
        return QHotelCheckins::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:',
            'bind'       => ['domain_uuid' => $ctx['domain_uuid'], 'extension_uuid' => $extensionUuid],
            'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
    }

    private function apiHotelPendingWake(array $ctx, string $extensionUuid)
    {
        return QHotelWakeupCalls::findFirst([
            'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid: AND status = :status:',
            'bind'       => ['domain_uuid' => $ctx['domain_uuid'], 'extension_uuid' => $extensionUuid, 'status' => 1],
            'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
        ]);
    }

    /** Fixed-offset zone from the client's UTC offset in minutes (e.g. 330 for IST, -240 for EDT). */
    private function apiHotelTimezone(int $offsetMinutes): \DateTimeZone
    {
        $sign = $offsetMinutes < 0 ? '-' : '+';
        $abs = abs($offsetMinutes);
        $hh = str_pad((string)intdiv($abs, 60), 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string)($abs % 60), 2, '0', STR_PAD_LEFT);
        try {
            return new \DateTimeZone($sign . $hh . ':' . $mm);
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /** Mirror a name onto the extension's directory entry and bust the caches that read it. */
    private function apiHotelSyncName($vExtension, array $ctx, string $first, string $last): void
    {
        $domain = $ctx['domain'];
        $vExtension->effective_caller_id_name = trim($first . ' ' . $last);
        $vExtension->update();
        $this->redis->del('vExtensions-' . $domain);

        if (!$vExtension->vExtensionUsers) {
            return;
        }
        $qUser = QUsers::findFirst([
            'conditions' => 'user_uuid = :user_uuid:',
            'bind'       => ['user_uuid' => $vExtension->vExtensionUsers->user_uuid],
            'bindTypes'  => [Column::BIND_PARAM_STR],
        ]);
        if (!$qUser) {
            return;
        }
        $qUser->first_name = $first;
        $qUser->last_name = $last;
        $qUser->update();

        $names = ['directory.' . $vExtension->extension . '@' . $domain];
        if (!empty($vExtension->number_alias)) {
            $names[] = 'directory.' . $vExtension->number_alias . '@' . $domain;
        }
        $this->apiUnlinkCache($names);
        $this->redis->del($ctx['domain_uuid'] . '-extensions-cache');
    }

    private function apiHotelRooms(array $ctx)
    {
        $this->apiHotelEnsureTables();

        $builder = $this->modelsManager->createBuilder()
            ->from(['v_users' => VUsers::class])
            ->leftJoin(VExtensionUsers::class, 'v_extension_users.user_uuid = v_users.user_uuid', 'v_extension_users')
            ->leftJoin(VExtensions::class, 'v_extensions.extension_uuid = v_extension_users.extension_uuid', 'v_extensions')
            ->columns([
                'v_extensions_extension' => 'v_extensions.extension',
                'v_extensions_uuid'      => 'v_extensions.extension_uuid',
            ])
            ->where('v_users.domain_uuid = :domain_uuid:', ['domain_uuid' => $ctx['domain_uuid']])
            ->orderBy('v_extensions.extension ASC');

        $content = [];
        foreach ($builder->getQuery()->execute() as $row) {
            if (empty($row->v_extensions_extension) || !is_numeric($row->v_extensions_extension)) {
                continue;
            }
            $checkin = null;
            try {
                $checkin = $this->apiHotelCheckinRow($ctx, (string)$row->v_extensions_uuid);
            } catch (\Throwable $e) {
                error_log('api hotel rooms checkin lookup failed: ' . $e->getMessage());
            }
            $wake = null;
            try {
                $wake = $this->apiHotelPendingWake($ctx, (string)$row->v_extensions_uuid);
            } catch (\Throwable $e) {
                error_log('api hotel rooms wakeup lookup failed: ' . $e->getMessage());
            }
            $sipId = '';
            try {
                $cb = QCallBehaviors::findFirst([
                    'conditions' => 'domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:',
                    'bind'       => ['domain_uuid' => $ctx['domain_uuid'], 'extension_uuid' => $row->v_extensions_uuid],
                    'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                ]);
                $sipId = $cb ? (string)$cb->force_ping : '';
            } catch (\Throwable $e) {
                error_log('api hotel rooms SIP ID lookup failed: ' . $e->getMessage());
            }
            $content[] = [
                'extension'     => $row->v_extensions_extension,
                'username'      => $sipId,
                'firstname'     => $checkin ? $checkin->guest_firstname : '',
                'lastname'      => $checkin ? $checkin->guest_lastname : '',
                'wake_hour'     => $wake ? (int)$wake->wake_hour : null,
                'wake_minute'   => $wake ? (int)$wake->wake_minute : null,
                'wake_ampm'     => $wake ? $wake->wake_ampm : null,
                'wake_datetime' => $wake ? $wake->wake_datetime : null,
            ];
        }

        return $this->apiOk(['content' => $content, 'count' => count($content)]);
    }

    private function apiHotelCheckin(array $ctx)
    {
        $first = $ctx['data']['firstname'] ?? null;
        $last = $ctx['data']['lastname'] ?? '';
        $first = is_string($first) ? trim($first) : '';
        $last = is_string($last) ? trim($last) : '';
        $namePattern = '/^[\p{L}\p{N} .,\'\-]{1,100}$/u';
        if (!preg_match($namePattern, $first)) {
            return $this->apiFail('Invalid firstname: required, 1-100 characters (letters, digits, space . , \' -)', 400);
        }
        if ($last !== '' && !preg_match($namePattern, $last)) {
            return $this->apiFail('Invalid lastname: up to 100 characters (letters, digits, space . , \' -)', 400);
        }

        $vExtension = $this->apiHotelExtension($ctx);
        if (!($vExtension instanceof VExtensions)) {
            return $vExtension;
        }
        $this->apiHotelEnsureTables();

        $checkin = $this->apiHotelCheckinRow($ctx, (string)$vExtension->extension_uuid);
        if (!$checkin) {
            $checkin = new QHotelCheckins();
            $checkin->hotel_checkin_uuid = $this->apiUuid();
            $checkin->domain_uuid = $ctx['domain_uuid'];
            $checkin->extension_uuid = $vExtension->extension_uuid;
            $checkin->extension = $vExtension->extension;
            $checkin->insert_user = $ctx['session']['user_uuid'];
            $checkin->insert_date = date('Y-m-d H:i:s');
        }
        $checkin->guest_firstname = $first;
        $checkin->guest_lastname = $last;
        if (!$checkin->save()) {
            return $this->apiFail('Could not update guest details', 500);
        }

        $this->apiHotelSyncName($vExtension, $ctx, $first, $last);
        $this->apiHotelNotify($ctx, $vExtension->extension);
        $this->apiAudit($ctx, 'hotel', 'checkin', ['extension' => (string)$vExtension->extension]);

        return $this->apiOk(['message' => 'Guest details updated.', 'extension' => (string)$vExtension->extension]);
    }

    private function apiHotelCheckout(array $ctx)
    {
        $vExtension = $this->apiHotelExtension($ctx);
        if (!($vExtension instanceof VExtensions)) {
            return $vExtension;
        }
        $this->apiHotelEnsureTables();

        $checkin = $this->apiHotelCheckinRow($ctx, (string)$vExtension->extension_uuid);
        if ($checkin) {
            $checkin->delete();
        }
        // a pending wake-up call must not reach the next guest
        $wake = $this->apiHotelPendingWake($ctx, (string)$vExtension->extension_uuid);
        if ($wake) {
            $wake->delete();
        }

        $this->apiHotelSyncName($vExtension, $ctx, 'XXX', 'YYY');
        $this->apiHotelNotify($ctx, $vExtension->extension);
        $this->apiAudit($ctx, 'hotel', 'checkout', ['extension' => (string)$vExtension->extension]);

        return $this->apiOk(['message' => 'Guest checked out.', 'extension' => (string)$vExtension->extension]);
    }

    private function apiHotelWakeupSet(array $ctx)
    {
        $d = $ctx['data'];

        $offset = 0;
        if (isset($d['utc_offset_minutes'])) {
            if (!is_numeric($d['utc_offset_minutes']) || (int)$d['utc_offset_minutes'] != $d['utc_offset_minutes']
                || (int)$d['utc_offset_minutes'] < -840 || (int)$d['utc_offset_minutes'] > 840) {
                return $this->apiFail('Invalid utc_offset_minutes: whole minutes between -840 and 840', 400);
            }
            $offset = (int)$d['utc_offset_minutes'];
        }

        if (isset($d['time']) && $d['time'] !== '') {
            if (!is_string($d['time']) || !preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/', trim($d['time']), $m)) {
                return $this->apiFail('Invalid time: use 24-hour HH:MM', 400);
            }
            $h24 = (int)$m[1];
            $wakeMinute = (int)$m[2];
            $wakeAmpm = $h24 >= 12 ? 'PM' : 'AM';
            $wakeHour = $h24 % 12 === 0 ? 12 : $h24 % 12;
        } else {
            foreach (['hour', 'minute'] as $f) {
                if (!isset($d[$f]) || !is_numeric($d[$f]) || (int)$d[$f] != $d[$f]) {
                    return $this->apiFail('Missing or invalid ' . $f . ' (or send time as HH:MM)', 400);
                }
            }
            $wakeHour = (int)$d['hour'];
            $wakeMinute = (int)$d['minute'];
            if ($wakeHour < 1 || $wakeHour > 12) {
                return $this->apiFail('Invalid hour: 1-12', 400);
            }
            if ($wakeMinute < 0 || $wakeMinute > 59) {
                return $this->apiFail('Invalid minute: 0-59', 400);
            }
            $ampm = strtoupper(trim((string)($d['ampm'] ?? '')));
            if ($ampm !== 'AM' && $ampm !== 'PM') {
                return $this->apiFail('Invalid ampm: AM or PM', 400);
            }
            $wakeAmpm = $ampm;
            $h24 = ($wakeHour % 12) + ($wakeAmpm === 'PM' ? 12 : 0);
        }

        $vExtension = $this->apiHotelExtension($ctx);
        if (!($vExtension instanceof VExtensions)) {
            return $vExtension;
        }
        $this->apiHotelEnsureTables();

        // next occurrence of that wall-clock time on the client's clock, stored as UTC
        $tz = $this->apiHotelTimezone($offset);
        $now = new \DateTime('now', $tz);
        $wakeAt = new \DateTime($now->format('Y-m-d') . ' ' . sprintf('%02d:%02d:00', $h24, $wakeMinute), $tz);
        if ($wakeAt <= $now) {
            $wakeAt->modify('+1 day');
        }
        $wakeAt->setTimezone(new \DateTimeZone('UTC'));

        $wake = $this->apiHotelPendingWake($ctx, (string)$vExtension->extension_uuid);
        if (!$wake) {
            $wake = new QHotelWakeupCalls();
            $wake->hotel_wakeup_call_uuid = $this->apiUuid();
            $wake->domain_uuid = $ctx['domain_uuid'];
            $wake->extension_uuid = $vExtension->extension_uuid;
            $wake->extension = $vExtension->extension;
            $wake->insert_user = $ctx['session']['user_uuid'];
            $wake->insert_date = date('Y-m-d H:i:s');
        }
        $wake->wake_hour = $wakeHour;
        $wake->wake_minute = $wakeMinute;
        $wake->wake_ampm = $wakeAmpm;
        $wake->wake_datetime = $wakeAt->format('Y-m-d H:i:s');
        $wake->status = 1;
        $wake->attempts = 0;
        if (!$wake->save()) {
            return $this->apiFail('Could not schedule wake-up call', 500);
        }

        $this->apiHotelNotify($ctx, $vExtension->extension);
        $this->apiAudit($ctx, 'hotel', 'wakeup_set', ['extension' => (string)$vExtension->extension]);

        return $this->apiOk([
            'message'          => 'Wake-up call scheduled.',
            'extension'        => (string)$vExtension->extension,
            'wake_datetime_utc' => $wakeAt->format('Y-m-d H:i:s'),
        ]);
    }

    private function apiHotelWakeupClear(array $ctx)
    {
        $vExtension = $this->apiHotelExtension($ctx);
        if (!($vExtension instanceof VExtensions)) {
            return $vExtension;
        }
        $this->apiHotelEnsureTables();

        $wake = $this->apiHotelPendingWake($ctx, (string)$vExtension->extension_uuid);
        if ($wake) {
            $wake->delete();
        }
        $this->apiHotelNotify($ctx, $vExtension->extension);
        $this->apiAudit($ctx, 'hotel', 'wakeup_clear', ['extension' => (string)$vExtension->extension]);

        return $this->apiOk(['message' => 'Wake-up call cleared.', 'extension' => (string)$vExtension->extension]);
    }

    private function apiHotelSetIvr(array $ctx)
    {
        $raw = $ctx['data']['ivr_extension'] ?? '';
        $ivrExtension = is_scalar($raw) ? trim((string)$raw) : '';
        $key = $this->apiHotelIvrKey($ctx);
        if ($ivrExtension === '') {
            $this->redis->del($key);
            return $this->apiOk(['message' => 'Wake-up call IVR cleared.']);
        }
        if (!preg_match('/^[0-9]{1,10}$/', $ivrExtension)) {
            return $this->apiFail('Enter a numeric IVR extension (digits only, max 10)', 400);
        }
        $this->redis->set($key, $ivrExtension);
        return $this->apiOk(['message' => 'Wake-up call IVR updated.', 'selected_ivr_extension' => $ivrExtension]);
    }

    private function apiHotelSetFrontdesk(array $ctx)
    {
        if (!array_key_exists('enabled', $ctx['data'])) {
            return $this->apiFail('Missing parameters: enabled', 400);
        }
        $enabled = $this->apiIvrBool($ctx['data']['enabled']);
        if ($enabled === null) {
            return $this->apiFail('Invalid enabled: must be true or false', 400);
        }
        $key = $this->apiHotelMenuKey($ctx);
        if ($enabled) {
            $this->redis->set($key, '1');
        } else {
            $this->redis->del($key);
        }
        return $this->apiOk([
            'message' => $enabled ? 'Hotel Front Desk menu enabled.' : 'Hotel Front Desk menu disabled.',
            'enabled' => $enabled,
        ]);
    }
}
