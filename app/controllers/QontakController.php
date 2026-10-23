<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Library\Qontak\QontakException;
use Library\Qontak\QontakService;
use Library\Qontak\RingqTranscriptProvider;

require_once __DIR__ . '/../library/Qontak/QontakService.php';

/**
 * Qontak CRM integration (Mekari Qontak CRM OpenAPI).
 *
 * Convention-routed: qontak/<action> maps here automatically (no router change).
 * Every action must also be listed in config/services.php ($privately, or
 * $publicly for `webhooks` and `recording`), see qontak-backend/INSTALL.md.
 *
 *   GET  qontak/config                 read settings (never returns the secret)
 *   POST qontak/config                 save settings            (admin)
 *   POST qontak/testConnection         test stored/unsaved creds (admin)
 *   POST qontak/disconnect             delete creds, disable     (admin)
 *   GET  qontak/contactsSearch?phone=  contact lookup for the dialer popup
 *   POST qontak/contacts               create contact (if enabled in settings)
 *   POST qontak/callEvents             dialer lifecycle event (started/answered/ended)
 *   GET  qontak/callJournals           list, or ?local_call_id= for one
 *   POST qontak/callJournals           {action:"retry", local_call_id} or a call event
 *   POST qontak/recordingsSync         re-link recording for a call (admin)
 *   POST qontak/aiAnalysis             re-run AI for a call (admin)
 *   POST qontak/webhooks/{token}       Qontak webhook receiver (public, token-auth)
 *   GET  qontak/recording/{id}/{exp}/{sig}  signed recording link (public, signature-auth)
 *
 * Isolated from every other integration: its own tables, Redis keys
 * (qontak:*) and library. Nothing here is called by the dialer's call path;
 * failures only affect Qontak.
 */
class QontakController extends ControllerBase
{
    private function json(array $payload, int $status = 200)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        if ($status !== 200) {
            $this->response->setStatusCode($status);
        }
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setHeader('Cache-Control', 'no-store');
        return $this->response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function fail(string $message, int $status = 200)
    {
        return $this->json(['result' => 'error', 'message' => $message], $status);
    }

    /**
     * Admin-safe explanation for setup problems (missing migration, DB grants),
     * so "it doesn't work" after a deploy points straight at the missing step.
     * Never includes SQL, values or credentials.
     */
    private function setupError(\Throwable $e, string $fallback)
    {
        $msg = $e->getMessage();
        error_log('[qontak] ' . get_class($e) . ': ' . mb_substr($msg, 0, 300));
        if (preg_match('/q_qontak_\w+.*does not exist|relation "public\.q_qontak/i', $msg)) {
            return $this->fail('Qontak database tables are missing. Run migrations/2026_10_14_qontak_crm.sql on the server (INSTALL.md step 4).', 500);
        }
        if (stripos($msg, 'permission denied') !== false && stripos($msg, 'q_qontak') !== false) {
            return $this->fail('The app database user cannot access the Qontak tables. Apply the GRANT from INSTALL.md step 4.', 500);
        }
        if (stripos($msg, 'Library\\Qontak') !== false || stripos($msg, 'QontakService') !== false) {
            return $this->fail('Qontak library files are missing on the server (app/library/Qontak, INSTALL.md step 1).', 500);
        }
        return $this->fail($fallback, 500);
    }

    private function failFrom(QontakException $e)
    {
        $map = [
            'validation' => 422, 'forbidden' => 403, 'not_configured' => 409, 'auth_failed' => 502,
            'rate_limited' => 503, 'timeout' => 503, 'unavailable' => 503, 'not_found' => 404,
            'key_missing' => 500, 'key_invalid' => 500, 'decrypt_failed' => 500, 'encrypt_failed' => 500,
        ];
        return $this->fail($e->getMessage(), $map[$e->reason] ?? 400);
    }

    /** JSON body, or form fields (the app sends both styles). */
    /**
     * Request body as an array. The web app sends JSON labelled as
     * application/x-www-form-urlencoded (avoids a CORS preflight from :443 to
     * :8443), so a JSON-looking raw body wins over form parsing.
     */
    private function input(): array
    {
        $raw = (string) $this->request->getRawBody();
        if (strlen($raw) > 65536) {
            return [];
        }
        $trimmed = ltrim($raw);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            $data = json_decode($trimmed, true);
            return is_array($data) ? $data : [];
        }
        $contentType = strtolower((string) $this->request->getContentType());
        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false || strpos($contentType, 'multipart/form-data') !== false) {
            $post = $this->request->getPost();
            return is_array($post) ? $post : [];
        }
        return [];
    }

    private function sessionOrNull(): ?array
    {
        $session = $this->session->get('auth');
        if (!$session || empty($session['domain_uuid'])) {
            return null;
        }
        return $session;
    }

    private function service(array $session): QontakService
    {
        return new QontakService($this->db, $this->redis, (string) $session['domain_uuid'], (string) ($session['subdomain'] ?? ''));
    }

    private function canManage(array $session): bool
    {
        // isPlainUser() exists in RingQ's ControllerBase from the permission-modes release;
        // servers without it have no plain-user mode, so every logged-in user is a manager.
        return method_exists($this, 'isPlainUser') ? !$this->isPlainUser($session) : true;
    }

    private function forbidden()
    {
        if (method_exists($this, 'forbiddenForUser')) {
            return $this->forbidden();
        }
        return $this->fail('Sorry, you can only access your own records. (Code AC4031)', 403);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function configAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        $canManage = $this->canManage($session);
        try {
            $svc = $this->service($session);
            if ($this->request->isPost()) {
                if (!$canManage) {
                    return $this->forbidden();
                }
                list($clean, $errors) = QontakService::validateConfigInput($this->input());
                if ($errors) {
                    return $this->fail(implode(' ', $errors), 422);
                }
                $svc->saveConfig($clean, (string) ($session['user_uuid'] ?? ''));
                $message = 'Qontak settings saved.';
                // Verify freshly saved credentials straight away so the status is meaningful.
                if ($svc->configRow(true) && ($svc->configRow()['connection_status'] ?? '') === 'not_configured') {
                    $test = $svc->testConnection();
                    $message .= $test['ok'] ? ' Connection verified.' : ' But the connection test failed: ' . $test['message'];
                }
                $svc->configRow(true);
                return $this->json(['result' => 'success', 'message' => $message, 'content' => $svc->publicConfig(true)]);
            }
            return $this->json(['result' => 'success', 'content' => $svc->publicConfig($canManage)]);
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not process Qontak settings.');
        }
    }

    public function testConnectionAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        if (!$this->canManage($session)) {
            return $this->forbidden();
        }
        // Simple per-tenant throttle: test hits Qontak directly.
        try {
            $key = 'qontak:test-throttle:' . $session['domain_uuid'];
            if ($this->redis->incr($key) > 10) {
                return $this->fail('Too many connection tests. Wait a minute and try again.', 429);
            }
            $this->redis->expire($key, 60);
        } catch (\Throwable $e) {
        }
        try {
            $in = $this->input();
            $override = [];
            if (!empty($in['client_id'])) {
                list($clean, $errors) = QontakService::validateConfigInput($in);
                if ($errors) {
                    return $this->fail(implode(' ', $errors), 422);
                }
                $override = [
                    'environment' => $clean['environment'],
                    'client_id' => $clean['client_id'],
                    'client_secret' => $clean['client_secret'],
                    'crm_user_sso_id' => $clean['crm_user_sso_id'],
                ];
            }
            $svc = $this->service($session);
            $test = $svc->testConnection($override);
            $svc->configRow(true);
            return $this->json([
                'result' => $test['ok'] ? 'success' : 'error',
                'message' => $test['message'],
                'content' => ['config' => $svc->publicConfig(true)],
            ]);
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Connection test failed unexpectedly.');
        }
    }

    public function disconnectAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        if (!$this->canManage($session)) {
            return $this->forbidden();
        }
        try {
            $svc = $this->service($session);
            if (!$svc->configRow()) {
                return $this->fail('Qontak is not configured.', 409);
            }
            $svc->disconnect((string) ($session['user_uuid'] ?? ''));
            return $this->json(['result' => 'success', 'message' => 'Qontak disconnected. Credentials were deleted.', 'content' => $svc->publicConfig(true)]);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not disconnect Qontak.');
        }
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    public function contactsSearchAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        $phone = (string) $this->request->getQuery('phone', 'string', '');
        if (strlen(QontakService::digits($phone)) < 4 || strlen($phone) > 32) {
            return $this->fail('A valid phone number is required.', 422);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('Qontak integration is not enabled.', 409);
            }
            return $this->json(['result' => 'success', 'content' => ['contacts' => $svc->withCustomerIds($svc->searchContacts($phone))]]);
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Qontak lookup failed.');
        }
    }

    public function contactsAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('Qontak integration is not enabled.', 409);
            }
            if (!QontakService::settingsOf($svc->configRow())['contact_creation']) {
                return $this->fail('Contact creation is turned off in Qontak settings.', 403);
            }
            $res = $svc->createContact($this->input());
            return $this->json([
                'result' => 'success',
                'message' => $res['created'] ? 'Contact created in Qontak.' : 'This number already exists in Qontak; using the existing contact.',
                'content' => $res,
            ]);
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not create the contact.');
        }
    }

    // ------------------------------------------------------------------
    // Call journals
    // ------------------------------------------------------------------

    public function callEventsAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('Qontak integration is not enabled.', 409);
            }
            $in = $this->input();
            // Session already copied: release its lock so the popup's contact lookup
            // (same browser session) is not queued behind the Qontak task calls below.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $journal = $svc->recordCallEvent($in, $session);
            $this->json(['result' => 'success', 'content' => ['journal' => QontakService::publicJournal($this->withAiReason($journal))]]);

            // Sync right after answering the browser so the dialer never waits on Qontak.
            if (($in['event'] ?? '') === 'ended' && $journal['sync_status'] === 'pending') {
                $this->response->send();
                // Release the login-session lock BEFORE the background work below.
                // PHP serialises requests per session, so holding it would make the
                // popup's journal check (same browser session) wait up to ~30 s.
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                // The browser already has its answer. Save the call to Qontak now
                // (a few quick API calls), then hand recording + transcription to a
                // separate background process so this web worker is free at once:
                // holding workers for minutes made other requests (the popup) queue.
                try {
                    RingqTranscriptProvider::$hold = true;
                    $svc->syncJournal($journal['journal_uuid']);
                    RingqTranscriptProvider::$hold = false;
                    $this->startFollowUp((string) $session['domain_uuid'], (string) $journal['journal_uuid']);
                } catch (\Throwable $e) {
                    error_log('[qontak] inline sync error: ' . get_class($e));
                }
                // Prevent Phalcon from sending a second response.
                exit(0);
            }
            return $this->response;
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not record the call event. Please try again.');
        }
    }

    public function callJournalsAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        $onlyUser = $this->canManage($session) ? null : (string) ($session['user_uuid'] ?? '');
        try {
            $svc = $this->service($session);
            if ($this->request->isPost()) {
                $in = $this->input();
                if (($in['action'] ?? '') === 'retry') {
                    $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
                    if (!$j || ($onlyUser !== null && $j['user_uuid'] !== $onlyUser)) {
                        return $this->fail('Call journal not found.', 404);
                    }
                    if (!$svc->isActive()) {
                        return $this->fail('Qontak integration is not enabled.', 409);
                    }
                    $j = $svc->retryJournal($j);
                    return $this->json(['result' => 'success', 'content' => ['journal' => QontakService::publicJournal($this->withAiReason($j))]]);
                }
                if (($in['action'] ?? '') === 'transcribing') {
                    $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
                    if (!$j || ($onlyUser !== null && $j['user_uuid'] !== $onlyUser)) {
                        return $this->fail('Call journal not found.', 404);
                    }
                    $svc->markBrowserTranscribing($j);
                    return $this->json(['result' => 'success']);
                }
                if (($in['action'] ?? '') === 'transcript') {
                    $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
                    if (!$j || ($onlyUser !== null && $j['user_uuid'] !== $onlyUser)) {
                        return $this->fail('Call journal not found.', 404);
                    }
                    if (!$svc->isActive()) {
                        return $this->fail('Qontak integration is not enabled.', 409);
                    }
                    $j = $svc->applyBrowserTranscript($j, mb_substr((string) ($in['error'] ?? ''), 0, 300));
                    return $this->json(['result' => 'success', 'content' => ['journal' => QontakService::publicJournal($this->withAiReason($j))]]);
                }
                if (!$svc->isActive()) {
                    return $this->fail('Qontak integration is not enabled.', 409);
                }
                $j = $svc->recordCallEvent($in, $session);
                return $this->json(['result' => 'success', 'content' => ['journal' => QontakService::publicJournal($this->withAiReason($j))]]);
            }

            $localId = (string) $this->request->getQuery('local_call_id', 'string', '');
            if ($localId !== '') {
                $j = $svc->journalByLocalId($localId);
                if (!$j || ($onlyUser !== null && $j['user_uuid'] !== $onlyUser)) {
                    return $this->fail('Call journal not found.', 404);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => QontakService::publicJournal($this->withAiReason($j))]]);
            }
            $page = max(1, (int) $this->request->getQuery('page', 'int', 1));
            $perPage = min(100, max(1, (int) $this->request->getQuery('per_page', 'int', 25)));
            $phone = (string) $this->request->getQuery('phone', 'string', '');
            return $this->json(['result' => 'success', 'content' => $svc->listJournals($page, $perPage, $phone, $onlyUser)]);
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not load call journals.');
        }
    }

    public function recordingsSyncAction()
    {
        return $this->requeue('recording');
    }

    public function aiAnalysisAction()
    {
        return $this->requeue('ai');
    }

    private function requeue(string $what)
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        $manager = $this->canManage($session);
        if (!$manager && $what !== 'ai') {
            return $this->forbidden();
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('Qontak integration is not enabled.', 409);
            }
            $in = $this->input();
            $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
            if (!$j || (!$manager && $j['user_uuid'] !== (string) ($session['user_uuid'] ?? ''))) {
                return $this->fail('Call journal not found.', 404);
            }
            if ($what === 'ai') {
                $s = QontakService::settingsOf($svc->configRow());
                if (!$s['ai_transcription'] && !$s['ai_summary'] && !$s['ai_sentiment']) {
                    return $this->fail('AI features are turned off in Qontak settings.', 409);
                }
                $svc->requeueAi($j);
            } else {
                $svc->requeueRecording($j);
            }
            $svc->syncJournal($j['journal_uuid']);
            $j = $svc->journalByLocalId($j['local_call_id']);
            return $this->json(['result' => 'success', 'content' => ['journal' => QontakService::publicJournal($this->withAiReason($j))]]);
        } catch (QontakException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not queue the request.');
        }
    }

    // ------------------------------------------------------------------
    // Public endpoints (listed in $publicly)
    // ------------------------------------------------------------------

    /**
     * Qontak webhook receiver. Mekari documents the event payloads but no
     * signature scheme, so the URL carries a 192-bit per-tenant secret token
     * (rotated on disconnect). Duplicate deliveries are dropped by body hash.
     */
    public function webhooksAction($token = '')
    {
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        $raw = (string) $this->request->getRawBody();
        if ($raw === '' || strlen($raw) > 262144) {
            return $this->fail('Invalid payload.', 400);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->fail('Invalid payload.', 400);
        }
        try {
            $cfg = QontakService::configByWebhookToken($this->db, (string) $token);
            if (!$cfg) {
                return $this->fail('Not found.', 404);
            }
            $svc = new QontakService($this->db, $this->redis, (string) $cfg['domain_uuid'], (string) $cfg['domain_name']);
            $fresh = $svc->recordWebhook($raw, $payload);
            return $this->json(['result' => 'success', 'message' => $fresh ? 'accepted' : 'duplicate']);
        } catch (\Throwable $e) {
            error_log('[qontak] webhook error: ' . get_class($e));
            return $this->fail('Webhook processing failed.', 500);
        }
    }

    /** Streams a call recording for a valid, unexpired signed link. */
    public function recordingAction($journalUuid = '', $exp = '', $sig = '')
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        try {
            $file = QontakService::resolveSignedRecording($this->db, (string) $journalUuid, (string) $exp, (string) $sig);
        } catch (\Throwable $e) {
            error_log('[qontak] recording link error: ' . get_class($e));
            $file = null;
        }
        if (!$file) {
            $this->response->setStatusCode(404);
            return $this->response->setContent('Recording not found or link expired.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $this->response->setHeader('Content-Type', $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav');
        $this->response->setHeader('Content-Disposition', 'inline; filename="call-recording.' . ($ext === 'mp3' ? 'mp3' : 'wav') . '"');
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $this->response->setHeader('X-Robots-Tag', 'noindex');
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
        // Send the bytes directly (same approach as RegisterController::zohoplayrecoding):
        // setFileToSend() produced an empty 200 response on this server.
        $bytes = @file_get_contents($file['path']);
        if ($bytes === false || $bytes === '') {
            error_log('[qontak] recording file unreadable by PHP: ' . $file['name']);
            $this->response->setStatusCode(404);
            $this->response->setHeader('Content-Type', 'text/plain');
            return $this->response->setContent('Recording not available.');
        }
        $this->response->setHeader('Content-Length', (string) strlen($bytes));
        return $this->response->setContent($bytes);
    }

    /** Adds why AI could not run (kept in Redis by the sync) to a journal row. */
    private function withAiReason(array $j): array
    {
        if (($j['ai_status'] ?? null) === 'failed' && !empty($j['journal_uuid'])) {
            $j['ai_error'] = QontakService::aiReason($this->redis, (string) $j['journal_uuid']);
        }
        return $j;
    }

    /**
     * Starts "cli.php qontak after <domain> <journal>" detached from this request.
     * If the server forbids exec(), the cron task (every minute) does the same work.
     */
    private function startFollowUp(string $domainUuid, string $journalUuid): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
            error_log('[qontak] after-call worker not started: exec() is disabled; cron will do it');
            return;
        }
        $cli = realpath(__DIR__ . '/../cli.php');
        $php = '';
        foreach (['/usr/bin/php7.4', '/usr/bin/php', '/usr/local/bin/php'] as $p) {
            if (is_executable($p)) {
                $php = $p;
                break;
            }
        }
        if (!$cli || $php === '') {
            error_log('[qontak] after-call worker not started: php CLI or cli.php not found');
            return;
        }
        $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' qontak after '
            . escapeshellarg($domainUuid) . ' ' . escapeshellarg($journalUuid)
            . ' >> ' . escapeshellarg(rtrim(sys_get_temp_dir(), '/') . '/qontak-after.log') . ' 2>&1 &';
        @exec($cmd);
        error_log('[qontak] after-call worker started for ' . substr($journalUuid, 0, 8));
    }
}
