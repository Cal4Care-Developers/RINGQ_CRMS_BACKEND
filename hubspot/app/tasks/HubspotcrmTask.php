<?php
use Phalcon\Cli\Task;
use Library\Hubspot\HubspotService;

require_once __DIR__ . '/../library/Hubspot/HubspotService.php';

/**
 * HubSpot background work (cron, see INSTALL.md):
 *
 *   * * * * * /usr/bin/php /var/www/ringq/app/cli.php hubspotcrm sync
 *
 * sync  - retries pending/failed-transient call syncs (with backoff), attaches
 *         recordings that appear after hangup, runs optional AI.
 * after <domain_uuid> <journal_uuid> - one call's post-hangup follow-up,
 *         started by HubspotcrmController (detached), not by cron.
 *
 * A Redis lock keeps two overlapping cron runs from working at the same time;
 * per-row locks in HubspotService prevent duplicate HubSpot calls regardless.
 */
class HubspotcrmTask extends Task
{
    private function redisClient()
    {
        if ($this->getDI()->has('redis')) {
            return $this->getDI()->get('redis');
        }
        return new \Predis\Client(['scheme' => 'unix', 'path' => '/var/run/redis/redis-server.sock']);
    }

    public function mainAction()
    {
        $this->syncAction();
    }

    public function syncAction()
    {
        $redis = $this->redisClient();
        $lock = 'hubspot:cron-lock';
        try {
            if (!$redis->set($lock, (string) getmypid(), 'EX', 55, 'NX')) {
                return;
            }
        } catch (\Throwable $e) {
            // Without Redis we still run; journal row locks prevent duplicates.
        }
        $started = time();
        $total = 0;
        try {
            // Cron fires once a minute; poll every 5 s within that minute.
            do {
                $n = HubspotService::processDue($this->db, $redis, 50);
                $total += $n;
                if ($n < 50) {
                    sleep(5);
                }
            } while (time() - $started < 50);
        } catch (\Throwable $e) {
            error_log('[hubspot] cron sync error: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 300));
        } finally {
            try {
                $redis->del($lock);
            } catch (\Throwable $e) {
            }
        }
        if ($total > 0) {
            error_log('[hubspot] cron processed ' . $total . ' call journal(s)');
        }
    }

    /**
     * Read-only check of what HubSpot actually holds for the latest call journals:
     *   cli.php hubspotcrm verify [count=10]
     * Prints, per call: RingQ journal vs the HubSpot call object (status, direction,
     * duration, associated contact) and whether its recording link really serves audio.
     * Never prints the access token or a recording link signature.
     */
    public function verifyAction($count = 10)
    {
        if (is_array($count)) {
            $count = $count[0] ?? 10;
        }
        $count = max(1, min(50, (int) $count));
        $rows = $this->db->fetchAll(
            'SELECT j.*, d.domain_name FROM public.q_hubspot_call_journals j
               JOIN public.v_domains d ON d.domain_uuid = j.domain_uuid
              ORDER BY j.started_at DESC LIMIT ' . $count,
            \Phalcon\Db\Enum::FETCH_ASSOC
        );
        $services = [];
        foreach ($rows as $j) {
            $out = [
                'local_call_id' => $j['local_call_id'],
                'ringq' => [
                    'direction' => $j['call_direction'], 'from' => $j['caller_number'], 'to' => $j['destination_number'],
                    'status' => $j['call_status'], 'talk_s' => (int) $j['talk_duration'], 'sync' => $j['sync_status'],
                    'recording' => $j['recording_status'],
                ],
            ];
            try {
                $d = (string) $j['domain_uuid'];
                $svc = $services[$d] ?? ($services[$d] = new HubspotService($this->db, $this->redisClient(), $d, (string) $j['domain_name']));
                if (empty($j['hubspot_call_id'])) {
                    $out['hubspot'] = null;
                } else {
                    $call = $svc->client('', false)->get('/crm/v3/objects/calls/' . rawurlencode((string) $j['hubspot_call_id']), [
                        'properties' => 'hs_call_status,hs_call_direction,hs_call_duration,hs_call_disposition,hs_call_recording_url,hs_call_from_number,hs_call_to_number,hs_timestamp,hs_call_title',
                        'associations' => 'contacts,companies',
                    ]);
                    $p = $call['properties'] ?? [];
                    $contacts = array_map(function ($a) { return (string) ($a['id'] ?? ''); }, $call['associations']['contacts']['results'] ?? []);
                    $rec = (string) ($p['hs_call_recording_url'] ?? '');
                    $out['hubspot'] = [
                        'id' => $j['hubspot_call_id'], 'status' => $p['hs_call_status'] ?? null, 'direction' => $p['hs_call_direction'] ?? null,
                        'duration_ms' => $p['hs_call_duration'] ?? null, 'disposition' => $p['hs_call_disposition'] ?? null,
                        'from' => $p['hs_call_from_number'] ?? null, 'to' => $p['hs_call_to_number'] ?? null,
                        'contacts' => array_values(array_unique(array_filter(array_merge($contacts)))),
                        'recording_url_set' => $rec !== '',
                        'recording_fetch' => $rec !== '' ? self::probeAudio($rec) : null,
                    ];
                }
            } catch (\Throwable $e) {
                $out['error'] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200);
            }
            echo json_encode($out, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
    }

    /** GETs the first bytes of a recording link the way HubSpot would (no cookies). */
    private static function probeAudio(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Range: bytes=0-15'], CURLOPT_USERAGENT => 'RingQ-HubSpot-verify',
        ]);
        $body = (string) curl_exec($ch);
        $res = [
            'http' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'wav_header' => strncmp($body, 'RIFF', 4) === 0,
            'host' => (string) parse_url($url, PHP_URL_HOST),
        ];
        curl_close($ch);
        return $res;
    }

    public function afterAction($domainUuid = '', $journalUuid = '')
    {
        if (is_array($domainUuid)) {
            $journalUuid = (string) ($domainUuid[1] ?? '');
            $domainUuid = (string) ($domainUuid[0] ?? '');
        }
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        if (!preg_match($uuid, (string) $domainUuid) || !preg_match($uuid, (string) $journalUuid)) {
            return;
        }
        @set_time_limit(600);
        try {
            $domain = $this->db->fetchOne(
                'SELECT domain_name FROM public.v_domains WHERE domain_uuid = CAST(:d AS uuid)',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid]
            );
            if (!$domain) {
                return;
            }
            $svc = new HubspotService($this->db, $this->redisClient(), (string) $domainUuid, (string) $domain['domain_name']);
            $svc->followUp((string) $journalUuid);
        } catch (\Throwable $e) {
            error_log('[hubspot] after-call error: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 300));
        }
    }
}
