<?php
declare(strict_types=1);

/** Expected problem with a call-monitor request; the code is the HTTP status to answer with. */
class ApiMonitorException extends \RuntimeException
{
}

/**
 * Helpers for the live-call APIs: joinCall, listenCall, whisperCall and bargeInCall.
 *
 * Calls are found in FreeSWITCH's "show calls" list. A registered extension appears there under its
 * internal name (number_alias@domain), not under the number people dial, so an extension is matched
 * by both. Everything is scoped to the caller's domain, and every value that reaches a FreeSWITCH
 * command is validated first.
 */
trait ApiSupportCallMonitor
{
    /** Extension number or alias: letters, digits, dash and underscore only (safe inside an ESL command). */
    protected function apiMonitorRef($value, string $what): string
    {
        $text = is_scalar($value) ? trim((string)$value) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $text)) {
            throw new ApiMonitorException('Invalid ' . $what . '.', 400);
        }
        return $text;
    }

    /** @return array{extension: string, alias: string} the extension as stored in this domain */
    protected function apiMonitorExtension(array $ctx, string $extension): array
    {
        $row = $this->db->fetchOne(
            'SELECT extension, number_alias FROM v_extensions WHERE domain_uuid = :d AND extension = :e LIMIT 1',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => $ctx['domain_uuid'], 'e' => $extension]
        );
        if (!$row) {
            throw new ApiMonitorException('Extension ' . $extension . ' not found in this domain.', 404);
        }
        $alias = trim((string)($row['number_alias'] ?? ''));
        return ['extension' => (string)$row['extension'], 'alias' => preg_match('/^[A-Za-z0-9_-]{1,64}$/', $alias) ? $alias : ''];
    }

    protected function apiEslConnect()
    {
        $fp = @fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);
        if (!$fp) {
            error_log('call monitor: ESL connect failed: ' . $errstr);
            throw new ApiMonitorException('Could not reach the phone system.', 502);
        }
        stream_set_timeout($fp, 5);
        $this->apiEslRead($fp); // auth/request
        fwrite($fp, "auth ClueCon\n\n");
        $reply = $this->apiEslRead($fp);
        if (strpos((string)($reply['headers']['Reply-Text'] ?? ''), '+OK') === false) {
            fclose($fp);
            throw new ApiMonitorException('The phone system refused the connection.', 502);
        }
        return $fp;
    }

    /** @return array{headers: array<string,string>, body: string} one ESL message */
    protected function apiEslRead($fp): array
    {
        $headers = [];
        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if (!$headers) {
                    continue;
                }
                break;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
        }
        $body = '';
        $length = (int)($headers['Content-Length'] ?? 0);
        while (strlen($body) < $length && !feof($fp)) {
            $chunk = fread($fp, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        return ['headers' => $headers, 'body' => $body];
    }

    /** Run an "api" command and return its text answer. */
    protected function apiEslApi($fp, string $command): string
    {
        fwrite($fp, 'api ' . $command . "\n\n");
        return trim($this->apiEslRead($fp)['body']);
    }

    /** Start a background job (returns as soon as FreeSWITCH accepts it). */
    protected function apiEslBgapi($fp, string $command): void
    {
        fwrite($fp, 'bgapi ' . $command . "\n\n");
        $reply = (string)($this->apiEslRead($fp)['headers']['Reply-Text'] ?? '');
        if (strpos($reply, '+OK') !== 0) {
            error_log('call monitor: bgapi refused: ' . $reply);
            throw new ApiMonitorException('The phone system did not accept the request.', 502);
        }
    }

    /** @return array<int, array<string, mixed>> the bridged calls FreeSWITCH reports right now */
    protected function apiMonitorCalls($fp): array
    {
        $data = json_decode($this->apiEslApi($fp, 'show calls as json'), true);
        return (is_array($data) && isset($data['rows']) && is_array($data['rows'])) ? array_values($data['rows']) : [];
    }

    /**
     * Calls the extension is on, in this domain only.
     *
     * @param string[] $states call states to accept (for the extension's own side of the call)
     * @return array<int, array{row: array, side: string, agent_uuid: string, peer_uuid: string, state: string, created: int}>
     */
    protected function apiMonitorFindLegs(array $rows, string $domain, array $agent, array $states): array
    {
        $domain = strtolower($domain);
        $presence = [];
        foreach (array_filter([$agent['extension'], $agent['alias']]) as $id) {
            $presence[] = strtolower($id . '@' . $domain);
        }

        $found = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aUuid = (string)($row['uuid'] ?? '');
            $bUuid = (string)($row['b_uuid'] ?? '');
            if ($aUuid === '' || $bUuid === '') {
                continue;
            }
            $name = strtolower((string)($row['name'] ?? ''));
            $aMatch = in_array(strtolower((string)($row['presence_id'] ?? '')), $presence, true);
            foreach ($presence as $p) {
                if ($name !== '' && strpos($name, '/' . $p) !== false) {
                    $aMatch = true;
                }
            }
            $bName = strtolower((string)($row['b_name'] ?? ''));
            $bMatch = in_array(strtolower((string)($row['b_presence_id'] ?? '')), $presence, true);
            foreach ($presence as $p) {
                if ($bName !== '' && strpos($bName, '/' . $p) !== false) {
                    $bMatch = true;
                }
            }

            if ($aMatch) {
                $side = 'a';
            } elseif ($bMatch) {
                $side = 'b';
            } else {
                continue;
            }
            // A FreeSWITCH build that does not report the state is treated as live; bridged calls are.
            $stateRaw = $side === 'a' ? ($row['callstate'] ?? '') : ($row['b_callstate'] ?? '');
            $state = strtoupper(trim((string)$stateRaw));
            if ($state !== '' && !in_array($state, $states, true)) {
                continue;
            }
            $found[] = [
                'row'        => $row,
                'side'       => $side,
                'agent_uuid' => $side === 'a' ? $aUuid : $bUuid,
                'peer_uuid'  => $side === 'a' ? $bUuid : $aUuid,
                'state'      => $state === '' ? 'ACTIVE' : $state,
                'created'    => (int)($row['created_epoch'] ?? $row['call_created_epoch'] ?? 0),
            ];
        }
        usort($found, static function ($x, $y) {
            return $x['created'] <=> $y['created'];
        });
        return $found;
    }

    /**
     * The one live call of $agent to watch, or an error saying why not.
     *
     * @return array{row: array, side: string, agent_uuid: string, peer_uuid: string, state: string, created: int}
     */
    protected function apiMonitorSingleCall($fp, string $domain, array $agent): array
    {
        $legs = $this->apiMonitorFindLegs($this->apiMonitorCalls($fp), $domain, $agent, ['ACTIVE']);
        if (!$legs) {
            throw new ApiMonitorException('Extension ' . $agent['extension'] . ' is not on an active call.', 409);
        }
        // Prefer the most recent call if the agent has a held call as well.
        return $legs[count($legs) - 1];
    }

    /** Start a supervisor leg that eavesdrops (and optionally speaks) on a live call. */
    protected function apiMonitorStart($fp, string $domain, string $supervisor, string $callerName, string $callerNumber, string $targetUuid, bool $speakToA, bool $speakToB): void
    {
        if (!preg_match('/^[0-9a-fA-F-]{36}$/', $targetUuid)) {
            throw new ApiMonitorException('The call could not be identified.', 409);
        }
        $options = [
            'presence_id=' . $supervisor . '@' . $domain,
            'accountcode=' . $domain,
            'domain_name=' . $domain,
            "origination_caller_id_name='" . $callerName . "'",
            'origination_caller_id_number=' . $callerNumber,
            'hangup_after_bridge=false',
            'ignore_early_media=true',
            'eavesdrop_bridge_aleg=true',
            'eavesdrop_whisper_aleg=' . ($speakToA ? 'true' : 'false'),
            'eavesdrop_bridge_bleg=true',
            'eavesdrop_whisper_bleg=' . ($speakToB ? 'true' : 'false'),
        ];
        $this->apiEslBgapi(
            $fp,
            'originate {' . implode(',', $options) . '}user/' . $supervisor . '@' . $domain . ' &eavesdrop(' . $targetUuid . ')'
        );
    }

    /** @return array<int, array<string, mixed>> every channel FreeSWITCH reports right now */
    protected function apiMonitorChannels($fp): array
    {
        $data = json_decode($this->apiEslApi($fp, 'show channels as json'), true);
        return (is_array($data) && isset($data['rows']) && is_array($data['rows'])) ? array_values($data['rows']) : [];
    }

    /**
     * Channels of the extension's calls in this domain, for starting or stopping a recording.
     * The extension's own leg (registered as alias@domain) comes first; a looser match on the
     * dialled number is kept so a stop reaches the same channel a start picked, but only for
     * channels that belong to this domain.
     *
     * @return string[] channel uuids
     */
    protected function apiRecordingChannels(array $rows, string $domain, array $agent): array
    {
        $domainLc = strtolower($domain);
        $presence = [];
        foreach (array_filter([$agent['extension'], $agent['alias']]) as $id) {
            $presence[] = strtolower($id . '@' . $domainLc);
        }
        $own = [];
        $loose = [];
        foreach ($rows as $ch) {
            if (!is_array($ch)) {
                continue;
            }
            $uuid = (string)($ch['uuid'] ?? '');
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
                continue;
            }
            $name = strtolower((string)($ch['name'] ?? ''));
            $pres = strtolower((string)($ch['presence_id'] ?? ''));
            $isOwn = in_array($pres, $presence, true);
            foreach ($presence as $p) {
                if ($name !== '' && strpos($name, '/' . $p) !== false) {
                    $isOwn = true;
                }
            }
            if ($isOwn) {
                $own[$uuid] = true;
                continue;
            }
            $json = strtolower((string)json_encode($ch));
            if (strpos($json, $domainLc) !== false
                && preg_match('/\b' . preg_quote(strtolower($agent['extension']), '/') . '\b/', $json)) {
                $loose[$uuid] = true;
            }
        }
        return array_slice(array_keys($own + $loose), 0, 6);
    }

    /** Turn a thrown problem into the JSON answer. */
    protected function apiMonitorFail(\Throwable $e)
    {
        if ($e instanceof ApiMonitorException) {
            return $this->apiFail($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
        return $this->apiFailFrom($e, 500);
    }
}
