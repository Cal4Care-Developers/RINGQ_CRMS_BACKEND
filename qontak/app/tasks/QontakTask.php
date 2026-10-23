<?php
use Phalcon\Cli\Task;
use Library\Qontak\QontakService;

require_once __DIR__ . '/../library/Qontak/QontakService.php';

/**
 * Qontak background work (cron, see INSTALL.md):
 *
 *   * * * * * /usr/bin/php /var/www/ringq/app/cli.php qontak sync
 *   15 3 * * * /usr/bin/php /var/www/ringq/app/cli.php qontak prune
 *
 * sync  - retries failed/pending journal syncs (with backoff), attaches
 *         recordings that appear after hangup, runs AI when enabled.
 * prune - removes webhook de-duplication rows older than 7 days.
 * after <domain_uuid> <journal_uuid> - one call's post-hangup follow-up
 *         (recording link + transcription); started by the controller, not cron.
 *
 * A Redis lock keeps two overlapping cron runs from working at the same time.
 */
class QontakTask extends Task
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
        $lock = 'qontak:cron-lock';
        try {
            if (!$redis->set($lock, (string) getmypid(), 'EX', 55, 'NX')) {
                return;
            }
        } catch (\Throwable $e) {
            // Without Redis we still run; journal row locks prevent duplicate tasks.
        }
        $started = time();
        $total = 0;
        try {
            // Cron fires once a minute; poll every 5 s inside that minute so late
            // recordings and retries are picked up within seconds.
            do {
                $n = QontakService::processDue($this->db, $redis, 50);
                $total += $n;
                if ($n < 50) {
                    sleep(5);
                }
            } while (time() - $started < 50);
        } catch (\Throwable $e) {
            error_log('[qontak] cron sync error: ' . get_class($e) . ' ' . $e->getMessage());
        } finally {
            try {
                $redis->del($lock);
            } catch (\Throwable $e) {
            }
        }
        if ($total > 0) {
            error_log('[qontak] cron processed ' . $total . ' journal(s)');
        }
    }

    /**
     * Post-hangup follow-up for ONE call, started by QontakController right after
     * the dialer's call-end request (detached, so it never holds a web worker).
     */
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
            $svc = new QontakService($this->db, $this->redisClient(), (string) $domainUuid, (string) $domain['domain_name']);
            $svc->followUp((string) $journalUuid);
        } catch (\Throwable $e) {
            error_log('[qontak] after-call error: ' . get_class($e) . ' ' . $e->getMessage());
        }
    }

    public function pruneAction()
    {
        try {
            $this->db->execute("DELETE FROM public.q_qontak_webhook_events WHERE received_at < now() - interval '7 days'");
        } catch (\Throwable $e) {
            error_log('[qontak] prune error: ' . get_class($e) . ' ' . $e->getMessage());
        }
    }
}
