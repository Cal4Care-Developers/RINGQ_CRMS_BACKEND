<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Library\Hubspot\HubspotException;
use Library\Hubspot\HubspotService;

require_once __DIR__ . '/../library/Hubspot/HubspotService.php';

/**
 * HubSpot CRM integration (HubSpot CRM API v3/v4, private app access token).
 *
 * Convention-routed: hubspotcrm/<action> maps here automatically (no router
 * change). Every action must also be listed in config/services.php
 * ($privately; $publicly only for `recording`), see hubspot-backend/INSTALL.md.
 *
 * Named "hubspotcrm" on purpose: it never collides with any other HubSpot
 * controller that may already exist on the server.
 *
 *   GET  hubspotcrm/config                    settings (never returns the token)
 *   POST hubspotcrm/config                    save settings                        (admin)
 *   POST hubspotcrm/testConnection            test stored or unsaved token         (admin)
 *   POST hubspotcrm/disconnect                delete token, disable                (admin)
 *   GET  hubspotcrm/contactsSearch?phone=|q=  contact lookup / search
 *   GET  hubspotcrm/contact?id=               one contact with company
 *   POST hubspotcrm/contacts                  create contact (if enabled)
 *   POST hubspotcrm/callEvents                dialer lifecycle event (started/answered/ended)
 *   GET  hubspotcrm/callJournals              list, or ?local_call_id= for one
 *   POST hubspotcrm/callJournals              {action: retry | notes | contact | transcript, local_call_id, ...}
 *   POST hubspotcrm/recordingsSync            re-link recording for a call         (admin)
 *   POST hubspotcrm/aiAnalysis                re-run AI for a call                 (admin)
 *   GET  hubspotcrm/recording/{id}/{exp}/{sig}/{file}  signed recording link (public, signature-auth)
 *
 * Response envelope (same as the rest of RingQ): {result: "success"|"error", message?, content?}
 *
 * Isolated from every other integration: its own tables (q_hubspot_*), Redis
 * keys (hubspot:*), key file and library. Nothing here runs on the dialer's
 * call path; failures only affect HubSpot.
 */
class HubspotcrmController extends ControllerBase
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

    /** Admin-safe explanation for setup problems (missing migration, grants, files). */
    private function setupError(\Throwable $e, string $fallback)
    {
        $msg = $e->getMessage();
        error_log('[hubspot] ' . get_class($e) . ': ' . mb_substr($msg, 0, 300));
        if (preg_match('/q_hubspot_cti_tokens|hubspot_owns_call|call_origin_check/i', $msg)) {
            return $this->fail('The HubSpot dialer tables are missing. Run migrations/2026_10_22_hubspot_cti.sql on the server.', 500);
        }
        if (preg_match('/q_hubspot_\w+.*does not exist|relation "public\.q_hubspot/i', $msg)) {
            return $this->fail('HubSpot database tables are missing. Run migrations/2026_10_21_hubspot_crm.sql on the server (INSTALL.md step 4).', 500);
        }
        if (stripos($msg, 'permission denied') !== false && stripos($msg, 'q_hubspot') !== false) {
            return $this->fail('The app database user cannot access the HubSpot tables. Apply the GRANT from INSTALL.md step 4.', 500);
        }
        if (stripos($msg, 'Library\\Hubspot') !== false || stripos($msg, 'HubspotService') !== false) {
            return $this->fail('HubSpot library files are missing on the server (app/library/Hubspot, INSTALL.md step 1).', 500);
        }
        return $this->fail($fallback, 500);
    }

    private function failFrom(HubspotException $e)
    {
        $map = [
            'validation' => 422, 'forbidden' => 403, 'not_configured' => 409, 'auth_failed' => 502,
            'missing_scopes' => 502, 'rate_limited' => 503, 'timeout' => 503, 'unavailable' => 503,
            'not_found' => 404, 'conflict' => 409,
            'key_missing' => 500, 'key_invalid' => 500, 'decrypt_failed' => 500, 'encrypt_failed' => 500,
        ];
        return $this->fail($e->getMessage(), $map[$e->reason] ?? 400);
    }

    /**
     * Request body as an array. The web app sends JSON labelled as
     * application/x-www-form-urlencoded (no CORS preflight from :443 to :8443),
     * so a JSON-looking raw body wins over form parsing.
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

    private function service(array $session): HubspotService
    {
        return new HubspotService($this->db, $this->redis, (string) $session['domain_uuid'], (string) ($session['subdomain'] ?? ''));
    }

    private function canManage(array $session): bool
    {
        return !$this->isPlainUser($session);
    }

    /** Journal row the current user may see: admins any in the tenant, plain users only their own. */
    private function ownJournal(HubspotService $svc, array $session, string $localId): ?array
    {
        $j = $svc->journalByLocalId($localId);
        if (!$j) {
            return null;
        }
        if (!$this->canManage($session) && (string) $j['user_uuid'] !== (string) ($session['user_uuid'] ?? '')) {
            return null;
        }
        return $j;
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
                    return $this->forbiddenForUser();
                }
                list($clean, $errors) = HubspotService::validateConfigInput($this->input());
                if ($errors) {
                    return $this->fail(implode(' ', $errors), 422);
                }
                $tokenChanged = $svc->saveConfig($clean, (string) ($session['user_uuid'] ?? ''));
                $message = 'HubSpot settings saved.';
                $row = $svc->configRow(true);
                // Verify a new (or never-tested) token right away so the status is meaningful.
                if ($tokenChanged || ($row['connection_status'] ?? '') !== 'connected') {
                    $test = $svc->testConnection();
                    $message .= $test['ok'] ? ' Connection verified.' : ' But the connection test failed: ' . $test['message'];
                }
                $svc->configRow(true);
                return $this->json(['result' => 'success', 'message' => $message, 'content' => $svc->publicConfig(true)]);
            }
            return $this->json(['result' => 'success', 'content' => $svc->publicConfig($canManage)]);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not process HubSpot settings.');
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
            return $this->forbiddenForUser();
        }
        // Per-tenant throttle: a test makes several HubSpot requests.
        try {
            $key = 'hubspot:test-throttle:' . $session['domain_uuid'];
            if ((int) $this->redis->incr($key) > 10) {
                return $this->fail('Too many connection tests. Wait a minute and try again.', 429);
            }
            $this->redis->expire($key, 60);
        } catch (\Throwable $e) {
        }
        try {
            $in = $this->input();
            $token = trim((string) ($in['access_token'] ?? ''));
            if ($token !== '') {
                list(, $errors) = HubspotService::validateConfigInput(['access_token' => $token]);
                if ($errors) {
                    return $this->fail(implode(' ', $errors), 422);
                }
            }
            $svc = $this->service($session);
            if ($token === '' && !$svc->configRow()) {
                return $this->fail('Enter a HubSpot private app access token first.', 422);
            }
            $test = $svc->testConnection($token);
            $svc->configRow(true);
            return $this->json([
                'result' => $test['ok'] ? 'success' : 'error',
                'message' => $test['message'],
                'content' => ['config' => $svc->publicConfig(true), 'details' => $test['details'] ?? null, 'reason' => $test['reason'] ?? null],
            ]);
        } catch (HubspotException $e) {
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
            return $this->forbiddenForUser();
        }
        try {
            $svc = $this->service($session);
            if (!$svc->configRow()) {
                return $this->fail('HubSpot is not configured.', 409);
            }
            $svc->disconnect((string) ($session['user_uuid'] ?? ''));
            return $this->json(['result' => 'success', 'message' => 'HubSpot disconnected. The access token was deleted.', 'content' => $svc->publicConfig(true)]);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not disconnect HubSpot.');
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
        $phone = trim((string) $this->request->getQuery('phone', 'string', ''));
        $q = trim((string) $this->request->getQuery('q', 'string', ''));
        if ($phone === '' && $q === '') {
            return $this->fail('Provide phone or q.', 422);
        }
        if ($phone !== '' && (strlen(HubspotService::digits($phone)) < 6 || strlen($phone) > 32)) {
            return $this->fail('A valid phone number (6+ digits) is required.', 422);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('HubSpot integration is not enabled.', 409);
            }
            $contacts = $phone !== '' ? $svc->searchByPhone($phone) : $svc->searchByQuery($q);
            return $this->json(['result' => 'success', 'content' => ['contacts' => $contacts]]);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'HubSpot lookup failed.');
        }
    }

    public function contactAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        $id = trim((string) $this->request->getQuery('id', 'string', ''));
        if (!preg_match('/^\d{1,20}$/', $id)) {
            return $this->fail('A valid HubSpot contact id is required.', 422);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('HubSpot integration is not enabled.', 409);
            }
            $c = $svc->contactById($id, $this->request->getQuery('fresh', 'string', '') !== '1');
            if ($c === null) {
                return $this->fail('HubSpot contact not found.', 404);
            }
            return $this->json(['result' => 'success', 'content' => ['contact' => $c]]);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not load the HubSpot contact.');
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
                return $this->fail('HubSpot integration is not enabled.', 409);
            }
            $s = HubspotService::settingsOf($svc->configRow());
            $in = $this->input();
            // Agents may create contacts when "Contact creation" is on; the popup may also
            // create a new caller's contact automatically when "Auto-create contacts" is on.
            $autoCaller = $s['auto_create_contacts'] && ($in['auto'] ?? false) === true
                && HubspotService::isExternalNumber((string) ($in['phone'] ?? ''));
            if (!$s['contact_creation'] && !$autoCaller) {
                return $this->fail('Contact creation is turned off in HubSpot settings.', 403);
            }
            $res = $svc->createContact($autoCaller ? HubspotService::newCallerContactInput((string) $in['phone']) : $in);
            return $this->json([
                'result' => 'success',
                'message' => $res['created'] ? 'Contact created in HubSpot.' : 'This contact already exists in HubSpot; using the existing record.',
                'content' => $res,
            ]);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not create the contact.');
        }
    }

    // ------------------------------------------------------------------
    // Calls
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
                return $this->fail('HubSpot integration is not enabled.', 409);
            }
            $in = $this->input();
            if (($in['origin'] ?? '') === 'hubspot_cti') {
                $in['origin'] = 'dialer'; // only the token-authenticated HubSpot dialer may claim that origin
            }
            $journal = $svc->recordCallEvent($in, $session);
            return $this->answerThenSync($svc, $session, $journal, ($in['event'] ?? '') === 'ended');
        } catch (HubspotException $e) {
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
        try {
            $svc = $this->service($session);
            if ($this->request->isPost()) {
                $in = $this->input();
                $action = (string) ($in['action'] ?? '');
                $j = $this->ownJournal($svc, $session, (string) ($in['local_call_id'] ?? ''));
                if (!$j) {
                    return $this->fail('Call journal not found.', 404);
                }
                if (!$svc->isActive()) {
                    return $this->fail('HubSpot integration is not enabled.', 409);
                }
                if ($action === 'retry') {
                    $j = $svc->retryJournal($j);
                } elseif ($action === 'notes') {
                    $j = $svc->saveNotes($j, (string) ($in['notes'] ?? ''));
                } elseif ($action === 'contact') {
                    $j = $svc->setContact($j, (string) ($in['hubspot_contact_id'] ?? ''));
                } elseif ($action === 'transcript') {
                    $j = $svc->applyBrowserTranscript($j, mb_substr((string) ($in['error'] ?? ''), 0, 300));
                } else {
                    return $this->fail('action must be retry, notes, contact or transcript.', 422);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
            }

            $localId = (string) $this->request->getQuery('local_call_id', 'string', '');
            if ($localId !== '') {
                $j = $this->ownJournal($svc, $session, $localId);
                if (!$j) {
                    return $this->fail('Call journal not found.', 404);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
            }
            $page = max(1, (int) $this->request->getQuery('page', 'int', 1));
            $perPage = min(100, max(1, (int) $this->request->getQuery('per_page', 'int', 25)));
            $phone = (string) $this->request->getQuery('phone', 'string', '');
            $contactId = (string) $this->request->getQuery('hubspot_contact_id', 'string', '');
            $onlyUser = $this->canManage($session) ? null : (string) ($session['user_uuid'] ?? '');
            return $this->json(['result' => 'success', 'content' => $svc->listJournals($page, $perPage, $phone, $contactId !== '' ? $contactId : null, $onlyUser)]);
        } catch (HubspotException $e) {
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
        if (!$this->canManage($session)) {
            return $this->forbiddenForUser();
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('HubSpot integration is not enabled.', 409);
            }
            $j = $svc->journalByLocalId((string) ($this->input()['local_call_id'] ?? ''));
            if (!$j) {
                return $this->fail('Call journal not found.', 404);
            }
            if ($what === 'ai') {
                $s = HubspotService::settingsOf($svc->configRow());
                if (!$s['ai_transcription'] && !$s['ai_summary'] && !$s['ai_sentiment']) {
                    return $this->fail('AI features are turned off in HubSpot settings.', 409);
                }
                $svc->requeueAi($j);
            } else {
                $svc->requeueRecording($j);
            }
            $svc->syncJournal((string) $j['journal_uuid']);
            $j = $svc->journalByLocalId((string) $j['local_call_id']) ?: $j;
            return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not queue the request.');
        }
    }

    // ------------------------------------------------------------------
    // Public endpoint (listed in $publicly): signed recording link for HubSpot
    // ------------------------------------------------------------------

    public function recordingAction($journalUuid = '', $exp = '', $sig = '', $file = '')
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        try {
            $rec = HubspotService::resolveSignedRecording($this->db, (string) $journalUuid, (string) $exp, (string) $sig);
        } catch (\Throwable $e) {
            error_log('[hubspot] recording link error: ' . get_class($e));
            $rec = null;
        }
        if (!$rec) {
            $this->response->setStatusCode(404);
            $this->response->setHeader('Content-Type', 'text/plain');
            return $this->response->setContent('Recording not found or link revoked.');
        }
        $ext = strtolower(pathinfo($rec['name'], PATHINFO_EXTENSION));
        $bytes = @file_get_contents($rec['path']);
        if ($bytes === false || $bytes === '') {
            error_log('[hubspot] recording file unreadable by PHP: ' . $rec['name']);
            $this->response->setStatusCode(404);
            $this->response->setHeader('Content-Type', 'text/plain');
            return $this->response->setContent('Recording not available.');
        }
        $this->response->setHeader('Content-Type', $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav');
        $this->response->setHeader('Content-Disposition', 'inline; filename="call-recording.' . ($ext === 'mp3' ? 'mp3' : 'wav') . '"');
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $this->response->setHeader('X-Robots-Tag', 'noindex');
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
        $this->response->setHeader('Accept-Ranges', 'bytes');
        // Audio players (HubSpot's, browsers') seek with Range requests; Safari needs 206.
        $size = strlen($bytes);
        $range = (string) $this->request->getHeader('Range');
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $size - (int) $m[2]);
                $end = $size - 1;
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }
            if ($start >= $size || $start > $end) {
                $this->response->setStatusCode(416);
                $this->response->setHeader('Content-Range', 'bytes */' . $size);
                return $this->response->setContent('');
            }
            $this->response->setStatusCode(206);
            $this->response->setHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size);
            $this->response->setHeader('Content-Length', (string) ($end - $start + 1));
            return $this->response->setContent(substr($bytes, $start, $end - $start + 1));
        }
        $this->response->setHeader('Content-Length', (string) $size);
        return $this->response->setContent($bytes);
    }

    /**
     * Answers the browser with the journal, then (for a just-ended call) saves it to
     * HubSpot AFTER the response is sent, so the dialer never waits on HubSpot.
     */
    private function answerThenSync(HubspotService $svc, array $session, array $journal, bool $ended)
    {
        $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($journal)]]);
        // After the reply (the dialer never waits on HubSpot):
        //  - ringing / answered: show the call live on the HubSpot contact (syncLive);
        //  - ended and logged: save the final call (syncJournal);
        //  - ended, not logged: remove a record created while it rang (discardLiveRecord).
        $live = !$ended && $journal['sync_status'] === 'pending';
        $discard = $ended && $journal['sync_status'] === 'skipped' && !empty($journal['hubspot_call_id']);
        if (!$live && !$discard && (!$ended || $journal['sync_status'] !== 'pending')) {
            return $this->response;
        }
        $this->response->send();
        // Release the PHP session lock first: requests of the same login are
        // serialised, and the popup polls this journal straight away.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            if ($live) {
                $svc->syncLive((string) $journal['journal_uuid']);
            } elseif ($discard) {
                $svc->discardLiveRecord((string) $journal['journal_uuid']);
            } else {
                $svc->syncJournal((string) $journal['journal_uuid']);
                $this->startFollowUp((string) $session['domain_uuid'], (string) $journal['journal_uuid']);
            }
        } catch (\Throwable $e) {
            error_log('[hubspot] inline sync error: ' . get_class($e));
        }
        exit(0); // the response was already sent
    }

    // ------------------------------------------------------------------
    // HubSpot dialer (calling window inside app.hubspot.com)
    // ------------------------------------------------------------------

    /**
     * POST hubspotcrm/ctiPair (logged-in user, from the RingQ "Connect" pop-up):
     * issues a dialer token for the HubSpot calling window. Returned once.
     */
    public function ctiPairAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Please log in to RingQ first.', 401);
        }
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('HubSpot integration is not enabled. An administrator must set it up first.', 409);
            }
            $res = $svc->issueCtiToken($session);
            return $this->json(['result' => 'success', 'message' => 'HubSpot dialer connected.', 'content' => $res]);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not connect the HubSpot dialer.');
        }
    }

    /**
     * POST hubspotcrm/cti (public ACL; authenticated by the dialer token in the body).
     * {token, op: session | callEvent | callerContact | startRecording | journal | logout, ...}
     */
    public function ctiAction()
    {
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        $ipKey = 'hubspot:cti-fail:' . preg_replace('/[^0-9a-f:.]/i', '', (string) $this->request->getClientAddress());
        try {
            if ((int) $this->redis->get($ipKey) > 30) {
                return $this->fail('Too many attempts. Try again in a few minutes.', 429);
            }
        } catch (\Throwable $e) {
        }
        $in = $this->input();
        try {
            $session = HubspotService::ctiSession($this->db, (string) ($in['token'] ?? ''));
            if (!$session) {
                try {
                    $this->redis->incr($ipKey);
                    $this->redis->expire($ipKey, 600);
                } catch (\Throwable $e) {
                }
                return $this->json(['result' => 'error', 'message' => 'This HubSpot dialer is not connected to RingQ. Connect it again.', 'content' => ['reason' => 'unpaired']], 401);
            }
            $svc = $this->service($session);
            $op = (string) ($in['op'] ?? '');

            if ($op === 'logout') {
                $svc->revokeCtiToken((string) $session['cti_token_hash']);
                return $this->json(['result' => 'success', 'message' => 'Disconnected.']);
            }
            if ($op === 'session') {
                $active = $svc->isActive();
                $row = $svc->configRow();
                return $this->json(['result' => 'success', 'content' => [
                    'agent' => ['name' => (string) $session['firstname'], 'extension' => (string) $session['extension']],
                    'hubspot' => [
                        'active' => $active,
                        'portal_id' => $row && $row['portal_id'] !== null ? (string) $row['portal_id'] : null,
                        'ui_domain' => $row ? (string) ($row['ui_domain'] ?? '') : '',
                        'call_journaling' => (bool) HubspotService::settingsOf($row)['call_journaling'],
                    ],
                    // SIP login for the dialer's own phone connection (same data as api/sipcredentials).
                    'sip' => $active ? $svc->sipCredentials((string) $session['extension']) : null,
                ]]);
            }
            if (!$svc->isActive()) {
                return $this->fail('HubSpot integration is not enabled.', 409);
            }
            if ($op === 'callEvent') {
                $in['origin'] = 'hubspot_cti';
                $journal = $svc->recordCallEvent($in, $session);
                return $this->answerThenSync($svc, $session, $journal, ($in['event'] ?? '') === 'ended');
            }
            if ($op === 'callerContact') {
                // Ringing call in the HubSpot window: the caller's contact, so HubSpot can open it.
                // Same rules as the RingQ app: one exact match, else (when "Auto-create contacts"
                // is on) a new "Caller <number>" contact. Never for internal extensions.
                $phone = mb_substr((string) ($in['phone'] ?? ''), 0, 32);
                if (!HubspotService::isExternalNumber($phone)) {
                    return $this->json(['result' => 'success', 'content' => ['contact' => null, 'reason' => 'internal']]);
                }
                $matches = $svc->searchByPhone($phone);
                if (count($matches) === 1) {
                    return $this->json(['result' => 'success', 'content' => ['contact' => $matches[0], 'created' => false]]);
                }
                if (count($matches) > 1) {
                    return $this->json(['result' => 'success', 'content' => ['contact' => null, 'reason' => 'several', 'count' => count($matches)]]);
                }
                if (!HubspotService::settingsOf($svc->configRow())['auto_create_contacts']) {
                    return $this->json(['result' => 'success', 'content' => ['contact' => null, 'reason' => 'none']]);
                }
                $res = $svc->createContact(HubspotService::newCallerContactInput($phone));
                return $this->json(['result' => 'success', 'content' => ['contact' => $res['contact'], 'created' => $res['created']]]);
            }
            if ($op === 'recents') {
                // Dialer "Recents" tab: this agent's latest calls and their HubSpot status.
                $rows = $this->db->fetchAll(
                    "SELECT local_call_id, call_direction, caller_number, destination_number, call_status, started_at,
                            talk_duration, hubspot_contact_id, hubspot_call_id, sync_status, recording_status
                       FROM public.q_hubspot_call_journals
                      WHERE domain_uuid = CAST(:d AS uuid) AND user_uuid = :u
                      ORDER BY started_at DESC LIMIT 40",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => (string) $session['domain_uuid'], 'u' => (string) $session['user_uuid']]
                );
                $calls = array_map(function (array $r) use ($svc) {
                    $contact = (string) ($r['hubspot_contact_id'] ?? '');
                    return [
                        'local_call_id' => (string) $r['local_call_id'],
                        'direction' => (string) $r['call_direction'],
                        'number' => (string) ($r['call_direction'] === 'inbound' ? $r['caller_number'] : $r['destination_number']),
                        'status' => (string) $r['call_status'],
                        'started_at' => (string) $r['started_at'],
                        'talk_duration' => (int) $r['talk_duration'],
                        'contact_url' => $contact !== '' ? $svc->contactUrl($contact) : null,
                        'saved' => (string) $r['sync_status'] === 'synced',
                        'recording' => (string) $r['recording_status'] === 'linked',
                    ];
                }, $rows ?: []);
                return $this->json(['result' => 'success', 'content' => ['calls' => $calls]]);
            }
            if ($op === 'contactsSearch') {
                // Dialer "Contacts" tab: HubSpot contacts by name, email, company or phone.
                $q = trim(mb_substr((string) ($in['q'] ?? ''), 0, 100));
                if (mb_strlen($q) < 2) {
                    return $this->json(['result' => 'success', 'content' => ['contacts' => []]]);
                }
                $digits = HubspotService::digits($q);
                $contacts = strlen($digits) >= 6 && strlen($digits) >= mb_strlen(preg_replace('/[\s()+.-]/', '', $q))
                    ? $svc->searchByPhone($q) : $svc->searchByQuery($q);
                return $this->json(['result' => 'success', 'content' => ['contacts' => $contacts]]);
            }
            if ($op === 'startRecording') {
                // RingQ click2call dialer (HubSpot copy): same recording start as
                // register/calllog/1, without queueing a Salesforce call log.
                $client = new \ElephantIO\Client(\ElephantIO\Client::engine(\ElephantIO\Client::CLIENT_4X, 'ws://127.0.0.1:3000', [
                    'persistent' => false,
                ]));
                $client->initialize();
                $client->emit('recordingClick2Call', ['subdomain' => (string) $session['subdomain'], 'extension' => (string) $session['extension']]);
                try {
                    $client->close();
                } catch (\Throwable $e) {
                }
                return $this->json(['result' => 'success']);
            }
            if ($op === 'journal') {
                $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
                if (!$j || (string) $j['user_uuid'] !== (string) $session['user_uuid']) {
                    return $this->fail('Call journal not found.', 404);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
            }
            return $this->fail('Unknown operation.', 422);
        } catch (HubspotException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'The HubSpot dialer request failed.');
        }
    }

    /**
     * Starts "cli.php hubspotcrm after <domain> <journal>" detached from this request.
     * If exec() is disabled, the cron task (every minute) does the same work.
     */
    private function startFollowUp(string $domainUuid, string $journalUuid): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('exec') || in_array('exec', $disabled, true)) {
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
            return;
        }
        $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' hubspotcrm after '
            . escapeshellarg($domainUuid) . ' ' . escapeshellarg($journalUuid)
            . ' >> ' . escapeshellarg(rtrim(sys_get_temp_dir(), '/') . '/hubspot-after.log') . ' 2>&1 &';
        @exec($cmd);
    }
}
