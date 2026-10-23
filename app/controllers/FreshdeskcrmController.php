<?php
declare(strict_types=1);

use Phalcon\Mvc\Dispatcher;
use Phalcon\Mvc\View;
use Library\Freshdesk\FreshdeskException;
use Library\Freshdesk\FreshdeskService;

require_once __DIR__ . '/../library/Freshdesk/FreshdeskService.php';

/**
 * Freshdesk integration (Freshdesk REST API v2).
 *
 * Convention-routed: freshdeskcrm/<action> maps here automatically (no router
 * change). Its ACL entries are registered by beforeExecuteRoute() below, so
 * config/services.php needs no change (on servers that run the ionCube-encoded
 * build it cannot be edited). Admin-only actions are checked in each action.
 *
 *   GET  freshdeskcrm/config                 read settings (never returns the API key)
 *   POST freshdeskcrm/config                 save settings                  (admin)
 *   POST freshdeskcrm/testConnection         test stored / unsaved key      (admin)
 *   POST freshdeskcrm/disconnect             delete key, disable            (admin)
 *   GET  freshdeskcrm/contactsSearch?phone=  contact lookup for the dialer popup (?q= free text)
 *   GET  freshdeskcrm/contact?id=            one contact
 *   POST freshdeskcrm/contacts               create contact (if enabled in settings)
 *   POST freshdeskcrm/callEvents             dialer lifecycle event (started/answered/ended)
 *   GET  freshdeskcrm/callJournals           list, or ?local_call_id= for one
 *   POST freshdeskcrm/callJournals           {action: retry | notes | contact, local_call_id, ...}
 *   POST freshdeskcrm/recordingsSync         re-attach the recording for a call (admin)
 *   POST freshdeskcrm/ctiPair                dialer token for the Freshdesk CTI app (logged in)
 *   POST freshdeskcrm/cti                    Freshdesk CTI app API (public, dialer-token auth)
 *   GET  freshdeskcrm/recording/{id}/{exp}/{sig}  signed recording link (public, signature auth)
 *
 * Isolated from every other integration: its own tables, Redis keys
 * (freshdesk:*) and library. Nothing here is called by the dialer's call path;
 * failures only affect Freshdesk.
 */
class FreshdeskcrmController extends ControllerBase
{
    /** Not logged in: the signed recording link, and the Freshdesk dialer (authenticated by its own token). */
    const ACL_PUBLIC = ['recording', 'cti'];
    /** Logged-in users (role "registered"). */
    const ACL_PRIVATE = ['config', 'testConnection', 'disconnect', 'contactsSearch', 'contact', 'contacts', 'callEvents',
        'callJournals', 'recordingsSync', 'ctiPair', 'cti'];

    /**
     * Adds this controller to RingQ's ACL (the shared "acl" service built in
     * services.php) before ControllerBase checks it, exactly as a
     * "freshdeskcrm" entry in $publicly / $privately would. Entries already
     * present in services.php are kept; nothing else in the ACL changes.
     */
    public function beforeExecuteRoute(Dispatcher $dispatcher)
    {
        try {
            $acl = $this->acl;
            $acl->addComponent('freshdeskcrm', array_values(array_unique(array_merge(self::ACL_PUBLIC, self::ACL_PRIVATE))));
            $acl->allow('nonregistered', 'freshdeskcrm', self::ACL_PUBLIC);
            $acl->allow('registered', 'freshdeskcrm', self::ACL_PRIVATE);
        } catch (\Throwable $e) {
            error_log('[freshdesk] could not register ACL entries: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 200));
        }
        return parent::beforeExecuteRoute($dispatcher);
    }

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
     */
    private function setupError(\Throwable $e, string $fallback)
    {
        $msg = $e->getMessage();
        error_log('[freshdesk] ' . get_class($e) . ': ' . mb_substr($msg, 0, 300));
        if (preg_match('/q_freshdesk_\w+.*does not exist|relation "public\.q_freshdesk/i', $msg)) {
            return $this->fail('Freshdesk database tables are missing. Run app/scripts/freshdesk/2026_10_23_freshdesk_crm.sql on the server (docs/freshdesk/README.md step 2).', 500);
        }
        if (stripos($msg, 'permission denied') !== false && stripos($msg, 'q_freshdesk') !== false) {
            return $this->fail('The app database user cannot access the Freshdesk tables. Apply the GRANT from docs/freshdesk/README.md step 2.', 500);
        }
        if (stripos($msg, 'Library\\Freshdesk') !== false || stripos($msg, 'FreshdeskService') !== false) {
            return $this->fail('Freshdesk library files are missing on the server (app/library/Freshdesk).', 500);
        }
        if (stripos($msg, 'curl') !== false) {
            return $this->fail('PHP cURL is not available on the server; the Freshdesk integration needs it.', 500);
        }
        return $this->fail($fallback, 500);
    }

    private function failFrom(FreshdeskException $e)
    {
        $map = [
            'validation' => 422, 'forbidden' => 403, 'not_configured' => 409, 'conflict' => 409, 'auth_failed' => 502,
            'rate_limited' => 503, 'timeout' => 503, 'unavailable' => 503, 'not_found' => 404,
            'key_missing' => 500, 'key_invalid' => 500, 'decrypt_failed' => 500, 'encrypt_failed' => 500,
        ];
        return $this->fail($e->getMessage(), $map[$e->reason] ?? 400);
    }

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

    private function service(array $session): FreshdeskService
    {
        return new FreshdeskService($this->db, $this->redis, (string) $session['domain_uuid'], (string) ($session['subdomain'] ?? ''));
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
            return $this->forbiddenForUser();
        }
        return $this->fail('Sorry, you can only access your own records. (Code AC4031)', 403);
    }

    private function releaseSession(): void
    {
        // Requests of one login are serialised by PHP's session lock; the popup
        // polls the journal straight away, so never hold it during Freshdesk calls.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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
                list($clean, $errors) = FreshdeskService::validateConfigInput($this->input());
                if ($errors) {
                    return $this->fail(implode(' ', $errors), 422);
                }
                $svc->saveConfig($clean, (string) ($session['user_uuid'] ?? ''));
                $message = 'Freshdesk settings saved.';
                // Verify new or changed credentials straight away so the status is meaningful.
                $row = $svc->configRow(true);
                if ($row && ($row['connection_status'] ?? '') !== 'connected') {
                    $test = $svc->testConnection();
                    $message .= $test['ok'] ? ' ' . $test['message'] : ' But the connection test failed: ' . $test['message'];
                }
                $svc->configRow(true);
                return $this->json(['result' => 'success', 'message' => $message, 'content' => $svc->publicConfig(true)]);
            }
            return $this->json(['result' => 'success', 'content' => $svc->publicConfig($canManage)]);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not process Freshdesk settings.');
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
        try {
            $key = 'freshdesk:test-throttle:' . $session['domain_uuid'];
            if ($this->redis->incr($key) > 10) {
                return $this->fail('Too many connection tests. Wait a minute and try again.', 429);
            }
            $this->redis->expire($key, 60);
        } catch (\Throwable $e) {
        }
        try {
            $in = $this->input();
            $override = [];
            if (!empty($in['domain']) || !empty($in['api_key'])) {
                list($clean, $errors) = FreshdeskService::validateConfigInput($in);
                if ($errors) {
                    return $this->fail(implode(' ', $errors), 422);
                }
                $override = ['domain' => $clean['domain'], 'api_key' => $clean['api_key']];
            }
            $svc = $this->service($session);
            $test = $svc->testConnection($override);
            $svc->configRow(true);
            return $this->json([
                'result' => $test['ok'] ? 'success' : 'error',
                'message' => $test['message'],
                'content' => ['config' => $svc->publicConfig(true), 'reason' => $test['reason'] ?? null],
            ]);
        } catch (FreshdeskException $e) {
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
                return $this->fail('Freshdesk is not configured.', 409);
            }
            $svc->disconnect((string) ($session['user_uuid'] ?? ''));
            return $this->json(['result' => 'success', 'message' => 'Freshdesk disconnected. The API key was deleted.', 'content' => $svc->publicConfig(true)]);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not disconnect Freshdesk.');
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
        $q = trim((string) $this->request->getQuery('q', 'string', ''));
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('Freshdesk integration is not enabled.', 409);
            }
            $this->releaseSession();
            if ($phone !== '') {
                if (strlen($phone) > 32) {
                    return $this->fail('A valid phone number is required.', 422);
                }
                if (!FreshdeskService::isExternalNumber($phone)) {
                    return $this->json(['result' => 'success', 'content' => $this->internalLookup($svc, $phone)]);
                }
                return $this->json(['result' => 'success', 'content' => ['contacts' => $svc->searchByPhone($phone)]]);
            }
            if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
                return $this->fail('Search text must be 2-100 characters.', 422);
            }
            $digits = FreshdeskService::digits($q);
            $contacts = strlen($digits) >= 6 && strlen($digits) >= mb_strlen((string) preg_replace('/[\s()+.-]/', '', $q))
                ? $svc->searchByPhone($q) : $svc->searchByQuery($q);
            return $this->json(['result' => 'success', 'content' => ['contacts' => $contacts]]);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Freshdesk lookup failed.');
        }
    }

    /**
     * Popup lookup for an internal number (an extension): the colleague and their existing
     * Freshdesk contact. Nothing is created here; the call sync creates the contact once.
     */
    private function internalLookup(FreshdeskService $svc, string $number): array
    {
        $person = $svc->extensionPerson($number);
        $logged = (bool) FreshdeskService::settingsOf($svc->configRow())['log_internal_calls'];
        $contact = $person && $logged ? $svc->contactForExtension($number, false) : null;
        return [
            'contacts' => $contact ? [$contact] : [],
            'internal' => [
                'extension' => $person ? $person['extension'] : $number,
                'name' => $person ? $person['name'] : '',
                'is_extension' => $person !== null,
                'logged' => $logged && $person !== null,
            ],
        ];
    }

    public function contactAction()
    {
        $session = $this->sessionOrNull();
        if (!$session) {
            return $this->fail('Unauthorized. Please log in.', 401);
        }
        $id = (string) $this->request->getQuery('id', 'string', '');
        if (!preg_match('/^\d{1,20}$/', $id)) {
            return $this->fail('Contact id must be numeric.', 422);
        }
        try {
            $svc = $this->service($session);
            if (!$svc->isActive()) {
                return $this->fail('Freshdesk integration is not enabled.', 409);
            }
            $this->releaseSession();
            $c = $svc->contactById($id, (string) $this->request->getQuery('fresh', 'string', '') === '1');
            if (!$c) {
                return $this->fail('Freshdesk contact not found.', 404);
            }
            return $this->json(['result' => 'success', 'content' => ['contact' => $c]]);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Freshdesk lookup failed.');
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
                return $this->fail('Freshdesk integration is not enabled.', 409);
            }
            if (!FreshdeskService::settingsOf($svc->configRow())['contact_creation']) {
                return $this->fail('Contact creation is turned off in Freshdesk settings.', 403);
            }
            $in = $this->input();
            $this->releaseSession();
            $res = $svc->createContact($in);
            return $this->json([
                'result' => 'success',
                'message' => $res['created'] ? 'Contact created in Freshdesk.' : 'This contact already exists in Freshdesk; using the existing contact.',
                'content' => $res,
            ]);
        } catch (FreshdeskException $e) {
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
                return $this->fail('Freshdesk integration is not enabled.', 409);
            }
            $in = $this->input();
            unset($in['origin']); // only the CTI endpoint sets it
            $this->releaseSession();
            $journal = $svc->recordCallEvent($in, $session);
            return $this->answerThenSync($svc, $session, $journal, ($in['event'] ?? '') === 'ended');
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not record the call event. Please try again.');
        }
    }

    /**
     * Answers the dialer first; for an ended call that is due now, then saves it
     * to Freshdesk in the same PHP worker (after the response is sent) and starts
     * the detached recording follow-up. The dialer never waits on Freshdesk.
     */
    private function answerThenSync(FreshdeskService $svc, array $session, array $journal, bool $ended)
    {
        $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($journal)]]);
        $due = $ended && $journal['sync_status'] === 'pending'
            && (empty($journal['next_attempt_at']) || strtotime((string) $journal['next_attempt_at']) <= time());
        if (!$due) {
            return $this->response;
        }
        $this->response->send();
        $this->releaseSession();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $svc->syncJournal((string) $journal['journal_uuid']);
            $this->startFollowUp((string) $session['domain_uuid'], (string) $journal['journal_uuid']);
        } catch (\Throwable $e) {
            error_log('[freshdesk] inline sync error: ' . get_class($e));
        }
        exit(0); // the response was already sent
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
                $this->releaseSession();
                $action = (string) ($in['action'] ?? '');
                $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
                if (!$j || ($onlyUser !== null && $j['user_uuid'] !== $onlyUser)) {
                    return $this->fail('Call journal not found.', 404);
                }
                if (!$svc->isActive()) {
                    return $this->fail('Freshdesk integration is not enabled.', 409);
                }
                if ($action === 'retry') {
                    $j = $svc->retryJournal($j);
                } elseif ($action === 'notes') {
                    $j = $svc->saveNotes($j, (string) ($in['notes'] ?? ''), (string) ($in['disposition'] ?? ''));
                } elseif ($action === 'contact') {
                    $cid = (string) ($in['freshdesk_contact_id'] ?? '');
                    if (!preg_match('/^\d{1,20}$/', $cid)) {
                        return $this->fail('Contact id must be numeric.', 422);
                    }
                    $j = $svc->linkContact($j, $cid);
                } else {
                    return $this->fail('Unknown action.', 422);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
            }

            $localId = (string) $this->request->getQuery('local_call_id', 'string', '');
            if ($localId !== '') {
                $j = $svc->journalByLocalId($localId);
                if (!$j || ($onlyUser !== null && $j['user_uuid'] !== $onlyUser)) {
                    return $this->fail('Call journal not found.', 404);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
            }
            $page = max(1, (int) $this->request->getQuery('page', 'int', 1));
            $perPage = min(100, max(1, (int) $this->request->getQuery('per_page', 'int', 25)));
            $phone = (string) $this->request->getQuery('phone', 'string', '');
            return $this->json(['result' => 'success', 'content' => $svc->listJournals($page, $perPage, $phone, $onlyUser)]);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not load call journals.');
        }
    }

    public function recordingsSyncAction()
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
            if (!$svc->isActive()) {
                return $this->fail('Freshdesk integration is not enabled.', 409);
            }
            $j = $svc->journalByLocalId((string) ($this->input()['local_call_id'] ?? ''));
            if (!$j) {
                return $this->fail('Call journal not found.', 404);
            }
            $this->releaseSession();
            $svc->requeueRecording($j);
            $svc->syncJournal((string) $j['journal_uuid']);
            $j = $svc->journalByLocalId((string) $j['local_call_id']);
            return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not queue the request.');
        }
    }

    // ------------------------------------------------------------------
    // RingQ dialer inside Freshdesk (CTI app)
    // ------------------------------------------------------------------

    /** POST freshdeskcrm/ctiPair (logged-in user, from the RingQ "Connect" pop-up). */
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
                return $this->fail('Freshdesk integration is not enabled. An administrator must set it up first.', 409);
            }
            return $this->json(['result' => 'success', 'message' => 'Freshdesk dialer connected.', 'content' => $svc->issueCtiToken($session)]);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'Could not connect the Freshdesk dialer.');
        }
    }

    /**
     * POST freshdeskcrm/cti (public ACL; authenticated by the dialer token in the body).
     * {token, op: session | callEvent | callerContact | contactsSearch | linkContact | notes | journal | recents | logout, ...}
     */
    public function ctiAction()
    {
        if (!$this->request->isPost()) {
            return $this->fail('Method not allowed.', 405);
        }
        $ipKey = 'freshdesk:cti-fail:' . preg_replace('/[^0-9a-f:.]/i', '', (string) $this->request->getClientAddress());
        try {
            if ((int) $this->redis->get($ipKey) > 30) {
                return $this->fail('Too many attempts. Try again in a few minutes.', 429);
            }
        } catch (\Throwable $e) {
        }
        $in = $this->input();
        try {
            $session = FreshdeskService::ctiSession($this->db, (string) ($in['token'] ?? ''));
            if (!$session) {
                try {
                    $this->redis->incr($ipKey);
                    $this->redis->expire($ipKey, 600);
                } catch (\Throwable $e) {
                }
                return $this->json(['result' => 'error', 'message' => 'This Freshdesk dialer is not connected to RingQ. Connect it again.', 'content' => ['reason' => 'unpaired']], 401);
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
                $s = FreshdeskService::settingsOf($row);
                return $this->json(['result' => 'success', 'content' => [
                    'agent' => ['name' => (string) $session['firstname'], 'extension' => (string) $session['extension']],
                    'freshdesk' => [
                        'active' => $active,
                        'domain' => $row ? (string) ($row['fd_domain'] ?? '') : '',
                        'call_journaling' => (bool) $s['call_journaling'],
                        'contact_creation' => (bool) $s['contact_creation'],
                    ],
                    // SIP login for the dialer's own phone connection (same data as api/sipcredentials).
                    'sip' => $active ? $svc->sipCredentials((string) $session['extension']) : null,
                ]]);
            }
            if (!$svc->isActive()) {
                return $this->fail('Freshdesk integration is not enabled.', 409);
            }
            if ($op === 'callEvent') {
                $in['origin'] = 'freshdesk_cti';
                $journal = $svc->recordCallEvent($in, $session);
                return $this->answerThenSync($svc, $session, $journal, ($in['event'] ?? '') === 'ended');
            }
            if ($op === 'callerContact' || $op === 'contactsSearch') {
                $q = mb_substr(trim((string) ($in[$op === 'callerContact' ? 'phone' : 'q'] ?? '')), 0, 100);
                if ($op === 'callerContact' && !FreshdeskService::isExternalNumber($q)) {
                    return $this->json(['result' => 'success', 'content' => $this->internalLookup($svc, $q)]);
                }
                if ($op === 'callerContact' || (strlen(FreshdeskService::digits($q)) >= 6 && strlen(FreshdeskService::digits($q)) >= mb_strlen((string) preg_replace('/[\s()+.-]/', '', $q)))) {
                    $contacts = FreshdeskService::isExternalNumber($q) ? $svc->searchByPhone($q) : [];
                } else {
                    $contacts = mb_strlen($q) >= 2 ? $svc->searchByQuery($q) : [];
                }
                return $this->json(['result' => 'success', 'content' => ['contacts' => $contacts]]);
            }
            if ($op === 'createContact') {
                if (!FreshdeskService::settingsOf($svc->configRow())['contact_creation']) {
                    return $this->fail('Contact creation is turned off in Freshdesk settings.', 403);
                }
                return $this->json(['result' => 'success', 'content' => $svc->createContact($in)]);
            }
            if (in_array($op, ['journal', 'notes', 'linkContact'], true)) {
                $j = $svc->journalByLocalId((string) ($in['local_call_id'] ?? ''));
                if (!$j || (string) $j['user_uuid'] !== (string) $session['user_uuid']) {
                    return $this->fail('Call journal not found.', 404);
                }
                if ($op === 'notes') {
                    $j = $svc->saveNotes($j, (string) ($in['notes'] ?? ''), (string) ($in['disposition'] ?? ''));
                } elseif ($op === 'linkContact') {
                    $cid = (string) ($in['freshdesk_contact_id'] ?? '');
                    if (!preg_match('/^\d{1,20}$/', $cid)) {
                        return $this->fail('Contact id must be numeric.', 422);
                    }
                    $j = $svc->linkContact($j, $cid);
                }
                return $this->json(['result' => 'success', 'content' => ['journal' => $svc->publicJournal($j)]]);
            }
            if ($op === 'recents') {
                return $this->json(['result' => 'success', 'content' => ['calls' => $svc->recents((string) $session['user_uuid'])]]);
            }
            return $this->fail('Unknown operation.', 422);
        } catch (FreshdeskException $e) {
            return $this->failFrom($e);
        } catch (\Throwable $e) {
            return $this->setupError($e, 'The Freshdesk dialer request failed.');
        }
    }

    // ------------------------------------------------------------------
    // Public: signed recording link
    // ------------------------------------------------------------------

    /** Streams a call recording for a valid signed link (only while Freshdesk is connected). */
    public function recordingAction($journalUuid = '', $exp = '', $sig = '')
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        try {
            $file = FreshdeskService::resolveSignedRecording($this->db, (string) $journalUuid, (string) $exp, (string) $sig);
        } catch (\Throwable $e) {
            error_log('[freshdesk] recording link error: ' . get_class($e));
            $file = null;
        }
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $this->response->setHeader('X-Robots-Tag', 'noindex');
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
        if (!$file) {
            $this->response->setStatusCode(404);
            $this->response->setHeader('Content-Type', 'text/plain');
            return $this->response->setContent('Recording not found or link revoked.');
        }
        $bytes = @file_get_contents($file['path']);
        if ($bytes === false || $bytes === '') {
            error_log('[freshdesk] recording file unreadable by PHP');
            $this->response->setStatusCode(404);
            $this->response->setHeader('Content-Type', 'text/plain');
            return $this->response->setContent('Recording not available.');
        }
        $this->response->setHeader('Content-Type', $file['mime']);
        $this->response->setHeader('Content-Disposition', 'inline; filename="' . $file['name'] . '"');
        $this->response->setHeader('Content-Length', (string) strlen($bytes));
        return $this->response->setContent($bytes);
    }

    /**
     * Starts "cli.php freshdeskcrm after <domain> <journal>" detached from this request.
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
        $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($cli) . ' freshdeskcrm after '
            . escapeshellarg($domainUuid) . ' ' . escapeshellarg($journalUuid)
            . ' >> ' . escapeshellarg(rtrim(sys_get_temp_dir(), '/') . '/freshdesk-after.log') . ' 2>&1 &';
        @exec($cmd);
    }
}
