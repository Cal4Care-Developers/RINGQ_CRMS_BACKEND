<?php
declare(strict_types=1);

namespace Library\Qontak;

use Phalcon\Db\Enum;

require_once __DIR__ . '/QontakException.php';
require_once __DIR__ . '/QontakCrypto.php';
require_once __DIR__ . '/QontakClient.php';
require_once __DIR__ . '/QontakAi.php';

/**
 * All Qontak business logic, shared by QontakController (web) and QontakTask
 * (cron). Every query is scoped by the tenant's domain_uuid passed to the
 * constructor; nothing here can read or write another tenant's rows.
 *
 * Tables (see qontak-backend/migrations): q_qontak_config,
 * q_qontak_call_journals, q_qontak_webhook_events.
 */
class QontakService
{
    const CONTACT_CACHE_TTL = 300;
    const RECORDING_WAIT_SECONDS = 1800;   // keep looking for the CDR recording for 30 min after hangup
    // 0 = recording links in Qontak never expire (Qontak cannot store the audio
    // itself via API, so the link is the permanent record). Links stay signed,
    // tenant-bound, and are revoked together by Disconnect (link_salt rotation).
    const RECORDING_LINK_DAYS = 0;
    const MAX_RETRIES = 8;
    const BACKOFF = [60, 300, 900, 1800, 3600, 7200, 14400, 28800];

    const STATUSES = ['ringing', 'answered', 'completed', 'missed', 'rejected', 'busy', 'no_answer', 'failed'];
    const FINAL_STATUSES = ['completed', 'missed', 'rejected', 'busy', 'no_answer', 'failed'];

    const SETTINGS_DEFAULTS = [
        'popup_incoming' => true,
        'popup_outgoing' => true,
        'call_journaling' => true,
        'contact_creation' => false,
        'task_category_name' => 'Call',
        'ai_transcription' => false,
        'ai_summary' => false,
        'ai_sentiment' => false,
        // IANA zone used for times written into Qontak tasks (name/detail/due date).
        'timezone' => 'UTC',
        // CRM screen pop: open the Qontak contact page in a browser tab when a call starts.
        'screenpop_incoming' => false,
        'screenpop_outgoing' => false,
        // Verified: Qontak's "Customer 360" page is chat.qontak.com/customers/<customer_id>.
        'screenpop_url' => 'https://chat.qontak.com/customers/{customer_id}',
        'screenpop_unknown_url' => 'https://chat.qontak.com/customers/all-customers',
        // What the screen pop opens: the Qontak contact page in a tab, or RingQ's call-details window.
        // qontak_task = this call's Qontak task (default), qontak_tab = customer page, window = RingQ window.
        'screenpop_target' => 'qontak_task',
    ];

    private $db;
    private $redis;
    /** @var string */
    private $domainUuid;
    /** @var string tenant FQDN, e.g. sgringq100.ringq.ai */
    private $subdomain;
    /** @var array|null */
    private $configCache = null;

    public function __construct($db, $redis, string $domainUuid, string $subdomain)
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $domainUuid)) {
            throw new QontakException('Invalid tenant.', 'validation');
        }
        $this->db = $db;
        $this->redis = $redis;
        $this->domainUuid = strtolower($domainUuid);
        $this->subdomain = preg_replace('/[^a-z0-9.-]/i', '', $subdomain);
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function configRow(bool $fresh = false): ?array
    {
        if ($this->configCache !== null && !$fresh) {
            return $this->configCache ?: null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM public.q_qontak_config WHERE domain_uuid = CAST(:d AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid]
        );
        $this->configCache = $row ?: [];
        return $row ?: null;
    }

    public static function settingsOf(?array $row): array
    {
        $settings = [];
        if ($row && !empty($row['settings'])) {
            $decoded = json_decode((string) $row['settings'], true);
            $settings = is_array($decoded) ? $decoded : [];
        }
        return array_merge(self::SETTINGS_DEFAULTS, array_intersect_key($settings, self::SETTINGS_DEFAULTS));
    }

    /** Shape returned to the browser. Never includes the secret. */
    public function publicConfig(bool $canManage): array
    {
        $row = $this->configRow();
        $s = self::settingsOf($row);
        return [
            'enabled' => $row ? self::bool($row['enabled']) : false,
            'environment' => $row['environment'] ?? 'production',
            'client_id' => $canManage ? (string) ($row['client_id'] ?? '') : '',
            'client_secret_set' => $row ? !empty($row['client_secret_enc']) : false,
            'crm_user_sso_id' => $canManage ? (string) ($row['crm_user_sso_id'] ?? '') : '',
            'popup_incoming' => (bool) $s['popup_incoming'],
            'popup_outgoing' => (bool) $s['popup_outgoing'],
            'call_journaling' => (bool) $s['call_journaling'],
            'contact_creation' => (bool) $s['contact_creation'],
            'task_category_name' => (string) $s['task_category_name'],
            'timezone' => (string) $s['timezone'],
            'screenpop_incoming' => (bool) $s['screenpop_incoming'],
            'screenpop_outgoing' => (bool) $s['screenpop_outgoing'],
            'screenpop_url' => (string) $s['screenpop_url'],
            'screenpop_unknown_url' => (string) $s['screenpop_unknown_url'],
            'screenpop_target' => (string) $s['screenpop_target'],
            'ai_transcription' => (bool) $s['ai_transcription'],
            'ai_summary' => (bool) $s['ai_summary'],
            'ai_sentiment' => (bool) $s['ai_sentiment'],
            'connection_status' => $row['connection_status'] ?? 'not_configured',
            'last_tested_at' => self::iso($row['last_tested_at'] ?? null),
            'last_sync_at' => self::iso($row['last_sync_at'] ?? null),
            'last_error' => $canManage ? ($row['last_error'] ?? null) : null,
            'can_manage' => $canManage,
        ];
    }

    /** Validates admin input. Returns [cleanInput, errors]. */
    public static function validateConfigInput(array $in): array
    {
        $errors = [];
        $out = [];
        $out['environment'] = ($in['environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production';
        $out['client_id'] = trim((string) ($in['client_id'] ?? ''));
        if ($out['client_id'] === '' || !preg_match('/^[\w.-]{4,128}$/', $out['client_id'])) {
            $errors[] = 'Client ID is missing or invalid.';
        }
        $secret = (string) ($in['client_secret'] ?? '');
        if (strlen($secret) > 256 || preg_match('/[\r\n]/', $secret)) {
            $errors[] = 'Client Secret is invalid.';
        }
        $out['client_secret'] = $secret;
        $out['crm_user_sso_id'] = trim((string) ($in['crm_user_sso_id'] ?? ''));
        if ($out['crm_user_sso_id'] !== '' && !preg_match('/^[0-9a-fA-F-]{8,64}$/', $out['crm_user_sso_id'])) {
            $errors[] = 'CRM user SSO ID must be the user UUID from Qontak.';
        }
        $out['enabled'] = self::bool($in['enabled'] ?? false);
        $settings = [];
        foreach (self::SETTINGS_DEFAULTS as $k => $default) {
            if ($k === 'task_category_name') {
                $name = trim(strip_tags((string) ($in[$k] ?? $default)));
                if ($name === '' || mb_strlen($name) > 60) {
                    $errors[] = 'Task category must be 1-60 characters.';
                }
                $settings[$k] = $name;
            } elseif ($k === 'screenpop_url' || $k === 'screenpop_unknown_url') {
                $url = trim((string) ($in[$k] ?? $default));
                if ($url !== '' && (strlen($url) > 500 || !preg_match('#^https://[^\s<>"]+$#i', $url))) {
                    $errors[] = 'Screen pop URL must be an https:// address.';
                    $url = $default;
                }
                if ($k === 'screenpop_url' && $url === '') {
                    $url = $default;
                }
                $settings[$k] = $url;
            } elseif ($k === 'screenpop_target') {
                $target = (string) ($in[$k] ?? $default);
                $settings[$k] = in_array($target, ['qontak_task', 'qontak_tab', 'window'], true) ? $target : $default;
            } elseif ($k === 'timezone') {
                $zone = trim((string) ($in[$k] ?? $default));
                if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                    $errors[] = 'Time zone is invalid.';
                    $zone = $default;
                }
                $settings[$k] = $zone;
            } else {
                $settings[$k] = self::bool($in[$k] ?? $default);
            }
        }
        $out['settings'] = $settings;
        return [$out, $errors];
    }

    public function saveConfig(array $clean, string $userUuid): void
    {
        $row = $this->configRow(true);
        $secretEnc = $row['client_secret_enc'] ?? null;
        $credentialsChanged = !$row
            || $clean['client_id'] !== (string) ($row['client_id'] ?? '')
            || $clean['environment'] !== (string) ($row['environment'] ?? '');
        if ($clean['client_secret'] !== '') {
            $secretEnc = QontakCrypto::encrypt($clean['client_secret']);
            $credentialsChanged = true;
        }
        if (empty($secretEnc)) {
            throw new QontakException('Client Secret is required.', 'validation');
        }
        $oldSettings = self::settingsOf($row);
        $categoryChanged = $oldSettings['task_category_name'] !== $clean['settings']['task_category_name'];

        $binds = [
            'd' => $this->domainUuid,
            'enabled' => $clean['enabled'] ? 'true' : 'false',
            'env' => $clean['environment'],
            'cid' => $clean['client_id'],
            'sec' => $secretEnc,
            'sso' => $clean['crm_user_sso_id'],
            'settings' => json_encode($clean['settings']),
            'by' => $userUuid ?: null,
        ];
        if ($row) {
            $sql = 'UPDATE public.q_qontak_config SET enabled = CAST(:enabled AS boolean), environment = :env, client_id = :cid,
                       client_secret_enc = :sec, crm_user_sso_id = :sso, settings = CAST(:settings AS jsonb),
                       updated_by = :by, updated_at = now()'
                . ($credentialsChanged ? ", connection_status = 'not_configured', sign_mode = 'path_query', last_error = NULL" : '')
                . ($credentialsChanged || $categoryChanged ? ', task_category_id = NULL' : '')
                . ' WHERE domain_uuid = CAST(:d AS uuid)';
        } else {
            $binds['wt'] = bin2hex(random_bytes(24));
            $binds['salt'] = bin2hex(random_bytes(16));
            $sql = "INSERT INTO public.q_qontak_config
                      (domain_uuid, enabled, environment, client_id, client_secret_enc, crm_user_sso_id, settings,
                       connection_status, sign_mode, webhook_token, link_salt, updated_by, created_at, updated_at)
                    VALUES (CAST(:d AS uuid), CAST(:enabled AS boolean), :env, :cid, :sec, :sso, CAST(:settings AS jsonb),
                       'not_configured', 'path_query', :wt, :salt, :by, now(), now())";
        }
        $this->db->execute($sql, $binds);
        $this->configCache = null;
    }

    /** Builds a client from stored credentials, optionally overridden by unsaved form values. */
    public function client(array $override = []): QontakClient
    {
        $row = $this->configRow();
        $secret = (string) ($override['client_secret'] ?? '');
        if ($secret === '') {
            if (!$row || empty($row['client_secret_enc'])) {
                throw new QontakException('Qontak is not configured.', 'not_configured');
            }
            $secret = QontakCrypto::decrypt((string) $row['client_secret_enc']);
        }
        $clientId = (string) ($override['client_id'] ?? ($row['client_id'] ?? ''));
        if ($clientId === '') {
            throw new QontakException('Qontak is not configured.', 'not_configured');
        }
        return new QontakClient(
            (string) ($override['environment'] ?? ($row['environment'] ?? 'production')),
            $clientId,
            $secret,
            (string) ($override['crm_user_sso_id'] ?? ($row['crm_user_sso_id'] ?? '')),
            (string) ($row['sign_mode'] ?? 'path_query')
        );
    }

    /** Persists the HMAC request-line variant the gateway accepted. */
    private function rememberSignMode(QontakClient $client): void
    {
        if ($client->signModeChanged && $this->configRow()) {
            $this->db->execute(
                'UPDATE public.q_qontak_config SET sign_mode = :m WHERE domain_uuid = CAST(:d AS uuid)',
                ['m' => $client->signMode, 'd' => $this->domainUuid]
            );
            $this->configCache = null;
        }
    }

    /**
     * Calls GET /contacts?page=1&per_page=1 with the given (or stored) credentials.
     * Records the outcome on the stored config only when testing stored credentials
     * or credentials identical to them.
     */
    public function testConnection(array $override = []): array
    {
        $row = $this->configRow();
        $usesStored = $row && (($override['client_secret'] ?? '') === '')
            && (($override['client_id'] ?? $row['client_id']) === $row['client_id'])
            && (($override['environment'] ?? $row['environment']) === $row['environment']);
        try {
            $client = $this->client($override);
            $client->get('contacts', ['page' => 1, 'per_page' => 1]);
            if ($usesStored) {
                $this->rememberSignMode($client);
                $this->setStatus('connected', null);
            }
            return ['ok' => true, 'message' => 'Connected to Qontak CRM successfully.'];
        } catch (QontakException $e) {
            if ($usesStored) {
                $this->setStatus('error', $e->getMessage());
            }
            return ['ok' => false, 'message' => $e->getMessage(), 'reason' => $e->reason];
        }
    }

    public function setStatus(string $status, ?string $error): void
    {
        $this->db->execute(
            'UPDATE public.q_qontak_config SET connection_status = :s, last_error = :e, last_tested_at = now() WHERE domain_uuid = CAST(:d AS uuid)',
            ['s' => $status, 'e' => $error === null ? null : mb_substr($error, 0, 500), 'd' => $this->domainUuid]
        );
        $this->configCache = null;
    }

    /**
     * Removes credentials, disables the integration and invalidates every
     * recording link handed to Qontak (new link_salt) and the webhook token.
     * Call journals are kept for history.
     */
    public function disconnect(string $userUuid): void
    {
        $this->db->execute(
            "UPDATE public.q_qontak_config SET enabled = false, client_secret_enc = NULL, task_category_id = NULL,
                connection_status = 'disconnected', last_error = NULL, link_salt = :salt, webhook_token = :wt,
                updated_by = :by, updated_at = now()
             WHERE domain_uuid = CAST(:d AS uuid)",
            ['salt' => bin2hex(random_bytes(16)), 'wt' => bin2hex(random_bytes(24)), 'by' => $userUuid ?: null, 'd' => $this->domainUuid]
        );
        $this->db->execute(
            "UPDATE public.q_qontak_call_journals SET next_attempt_at = NULL,
                sync_status = CASE WHEN sync_status = 'pending' THEN 'skipped' ELSE sync_status END
             WHERE domain_uuid = CAST(:d AS uuid) AND next_attempt_at IS NOT NULL",
            ['d' => $this->domainUuid]
        );
        $this->configCache = null;
    }

    public function isActive(): bool
    {
        $row = $this->configRow();
        return $row && self::bool($row['enabled']) && !empty($row['client_secret_enc']) && $row['connection_status'] !== 'disconnected';
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    public static function digits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone);
    }

    /** True when two numbers are the same subscriber (national vs international forms). */
    public static function sameNumber(string $a, string $b): bool
    {
        $a = self::digits($a);
        $b = self::digits($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        if (strlen($a) >= 9 && strlen($b) >= 9) {
            return substr($a, -9) === substr($b, -9);
        }
        return false;
    }

    /** Search variants for Indonesian / international formatting (0812… / 62812… / +62812…). */
    public static function phoneVariants(string $phone): array
    {
        $d = self::digits($phone);
        if ($d === '') {
            return [];
        }
        $v = [$d];
        if (strpos($d, '62') === 0 && strlen($d) > 9) {
            $v[] = '0' . substr($d, 2);
            $v[] = '+' . $d;
        } elseif (strpos($d, '0') === 0 && strlen($d) > 8) {
            $v[] = '62' . substr($d, 1);
        }
        return array_values(array_unique($v));
    }

    public static function normalizeContact(array $c): array
    {
        return [
            'id' => $c['id'] ?? null,
            'first_name' => (string) ($c['first_name'] ?? ''),
            'last_name' => (string) ($c['last_name'] ?? ''),
            'full_name' => trim(((string) ($c['first_name'] ?? '')) . ' ' . ((string) ($c['last_name'] ?? ''))),
            'email' => (string) ($c['email'] ?? ''),
            'telephone' => (string) ($c['telephone'] ?? ''),
            'job_title' => (string) ($c['job_title'] ?? ''),
            'company_name' => (string) ($c['crm_company_name'] ?? ''),
            'owner_name' => (string) ($c['creator_name'] ?? ''),
            'status_name' => (string) ($c['crm_status_name'] ?? ''),
            // Qontak web pages identify contacts by this slug (a UUID).
            'slug' => (string) ($c['slug'] ?? ''),
            // Qontak Customer 360 id. Contacts usually return null here; see customerIdFor().
            'customer_id' => (string) ($c['customer_id'] ?? ''),
            'company_id' => isset($c['crm_company_id']) ? (int) $c['crm_company_id'] : null,
            'deal_ids' => array_values(array_filter(array_map('intval', (array) ($c['crm_deal_ids'] ?? [])))),
        ];
    }

    /**
     * Qontak Customer 360 id for a CRM contact. The contacts API returns
     * customer_id = null, but tasks linked to the contact carry it, so it is
     * learned from our call tasks (remembered when a task is created/updated,
     * or looked up from the latest call task for the contact) and cached.
     */
    public function customerIdFor($client, string $contactId): string
    {
        if (!preg_match('/^\d{1,20}$/', $contactId)) {
            return '';
        }
        $key = 'qontak:custid:' . $this->domainUuid . ':' . $contactId;
        try {
            $cached = (string) $this->redis->get($key);
            if ($cached !== '') {
                return $cached;
            }
        } catch (\Throwable $e) {
        }
        $row = $this->db->fetchOne(
            'SELECT qontak_task_id FROM public.q_qontak_call_journals
              WHERE domain_uuid = CAST(:d AS uuid) AND qontak_contact_id = :c AND qontak_task_id IS NOT NULL
              ORDER BY created_at DESC LIMIT 1',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'c' => $contactId]
        );
        if (!$row || empty($row['qontak_task_id'])) {
            return '';
        }
        try {
            $task = $client->get('tasks/' . rawurlencode((string) $row['qontak_task_id']));
        } catch (QontakException $e) {
            return '';
        }
        $customerId = (string) ($task['customer_id'] ?? '');
        $this->rememberCustomerId($contactId, $customerId);
        return $customerId;
    }

    private function rememberCustomerId(string $contactId, string $customerId): void
    {
        if ($contactId === '' || !preg_match('/^[A-Za-z0-9-]{6,64}$/', $customerId)) {
            return;
        }
        try {
            $this->redis->setex('qontak:custid:' . $this->domainUuid . ':' . $contactId, 30 * 86400, $customerId);
        } catch (\Throwable $e) {
        }
    }

    /** Adds customer_id to contacts for the dialer screen pop (best effort). */
    public function withCustomerIds(array $contacts): array
    {
        if (!$contacts) {
            return $contacts;
        }
        $client = $this->client();
        foreach ($contacts as $i => $c) {
            if (empty($c['customer_id']) && !empty($c['id'])) {
                try {
                    $contacts[$i]['customer_id'] = $this->customerIdFor($client, (string) $c['id']);
                } catch (\Throwable $e) {
                    $contacts[$i]['customer_id'] = '';
                }
            }
        }
        return $contacts;
    }

    /** One contact by id (GET contacts/:id), cached briefly. */
    public function contactById($client, string $id): ?array
    {
        if (!preg_match('/^\d{1,20}$/', $id)) {
            return null;
        }
        $key = 'qontak:contact:' . $this->domainUuid . ':' . $id;
        try {
            $cached = $this->redis->get($key);
            if ($cached) {
                $d = json_decode((string) $cached, true);
                if (is_array($d)) {
                    return $d;
                }
            }
        } catch (\Throwable $e) {
        }
        try {
            $c = self::normalizeContact($client->get('contacts/' . $id));
        } catch (QontakException $e) {
            if ($e->reason === 'not_found') {
                return null;
            }
            throw $e;
        }
        try {
            $this->redis->setex($key, self::CONTACT_CACHE_TTL, json_encode($c));
        } catch (\Throwable $e) {
        }
        return $c;
    }

    /** @return array[] matching contacts (deduplicated by id) */
    public function searchContacts(string $phone, bool $useCache = true): array
    {
        $digits = self::digits($phone);
        if (strlen($digits) < 4) {
            return [];
        }
        $cacheKey = 'qontak:contacts:v2:' . $this->domainUuid . ':' . $digits;
        if ($useCache) {
            try {
                $cached = $this->redis->get($cacheKey);
                if ($cached) {
                    $decoded = json_decode((string) $cached, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable $e) {
                // cache is best-effort
            }
        }

        $client = $this->client();
        $found = [];
        foreach (self::phoneVariants($phone) as $variant) {
            $list = $client->get('contacts', ['phone' => $variant, 'page' => 1, 'per_page' => 10]);
            $items = isset($list[0]) || $list === [] ? $list : ($list['data'] ?? []);
            foreach ((array) $items as $c) {
                if (!is_array($c) || !isset($c['id'])) {
                    continue;
                }
                // The API's phone filter may be a partial match; only keep real matches.
                if (!self::sameNumber((string) ($c['telephone'] ?? ''), $phone)) {
                    continue;
                }
                $found[(string) $c['id']] = self::normalizeContact($c);
            }
            if ($found) {
                break;
            }
        }
        $this->rememberSignMode($client);
        $result = array_values($found);
        try {
            $this->redis->setex($cacheKey, self::CONTACT_CACHE_TTL, json_encode($result));
        } catch (\Throwable $e) {
        }
        return $result;
    }

    public function forgetContactCache(string $phone): void
    {
        try {
            $this->redis->del('qontak:contacts:v2:' . $this->domainUuid . ':' . self::digits($phone));
        } catch (\Throwable $e) {
        }
    }

    public function createContact(array $in): array
    {
        $first = trim(strip_tags((string) ($in['first_name'] ?? '')));
        $last = trim(strip_tags((string) ($in['last_name'] ?? '')));
        $email = trim((string) ($in['email'] ?? ''));
        $phone = trim((string) ($in['telephone'] ?? ''));
        if ($first === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            throw new QontakException('First name is required (max 100 characters).', 'validation');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new QontakException('Email address is invalid.', 'validation');
        }
        if (strlen(self::digits($phone)) < 4 || strlen($phone) > 32) {
            throw new QontakException('Phone number is invalid.', 'validation');
        }
        // Duplicate guard: never create a second contact for a number Qontak already has.
        $existing = $this->searchContacts($phone, false);
        if ($existing) {
            return ['contact' => $existing[0], 'created' => false];
        }
        $lockKey = 'qontak:create-lock:' . $this->domainUuid . ':' . self::digits($phone);
        try {
            if (!$this->redis->set($lockKey, '1', 'EX', 30, 'NX')) {
                throw new QontakException('This contact is already being created. Try again in a moment.', 'validation');
            }
        } catch (QontakException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Redis unavailable: proceed without the lock (search above still guards).
        }
        $client = $this->client();
        $created = $client->post('contacts', array_filter([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'telephone' => $phone,
        ], function ($v) { return $v !== ''; }));
        $this->forgetContactCache($phone);
        return ['contact' => self::normalizeContact($created), 'created' => true];
    }

    // ------------------------------------------------------------------
    // Call journals
    // ------------------------------------------------------------------

    /** Records a dialer lifecycle event. Idempotent per (tenant, local_call_id). */
    public function recordCallEvent(array $in, array $session): array
    {
        $localId = (string) ($in['local_call_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $localId)) {
            throw new QontakException('local_call_id is invalid.', 'validation');
        }
        $event = (string) ($in['event'] ?? '');
        if (!in_array($event, ['started', 'answered', 'ended'], true)) {
            throw new QontakException('event must be started, answered or ended.', 'validation');
        }
        $direction = ($in['direction'] ?? '') === 'outbound' ? 'outbound' : (($in['direction'] ?? '') === 'inbound' ? 'inbound' : '');
        if ($direction === '') {
            throw new QontakException('direction must be inbound or outbound.', 'validation');
        }
        $remote = mb_substr(preg_replace('/[^0-9+*#]/', '', (string) ($in['remote_number'] ?? '')), 0, 32);
        $status = (string) ($in['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new QontakException('status is invalid.', 'validation');
        }
        $ext = mb_substr(preg_replace('/[^0-9A-Za-z*#+_-]/', '', (string) ($session['extension'] ?? '')), 0, 32);
        $agentName = mb_substr(trim(((string) ($session['firstname'] ?? '')) . ' ' . ((string) ($session['lastname'] ?? ''))), 0, 120);
        $contactId = isset($in['qontak_contact_id']) && preg_match('/^\d{1,20}$/', (string) $in['qontak_contact_id']) ? (string) $in['qontak_contact_id'] : null;

        $startedAt = self::clampTime($in['started_at'] ?? null);
        $answeredAt = self::clampTime($in['answered_at'] ?? null);
        $endedAt = self::clampTime($in['ended_at'] ?? null);
        $talk = max(0, min(86400, (int) ($in['talk_duration'] ?? 0)));

        $existing = $this->journalByLocalId($localId);
        if ($existing && $existing['user_uuid'] && $existing['user_uuid'] !== ($session['user_uuid'] ?? '')) {
            throw new QontakException('This call belongs to another user.', 'forbidden');
        }

        if (!$existing) {
            $uuid = (new \Phalcon\Security\Random())->uuid();
            // ON CONFLICT makes a retried/duplicated 'started' a no-op.
            $this->db->execute(
                "INSERT INTO public.q_qontak_call_journals
                   (journal_uuid, domain_uuid, local_call_id, user_uuid, agent_extension, agent_name, call_direction,
                    caller_number, destination_number, call_status, started_at, sync_status, recording_status, ai_status,
                    retry_count, created_at, updated_at)
                 VALUES (CAST(:u AS uuid), CAST(:d AS uuid), :lid, :usr, :ext, :aname, :dir,
                    :caller, :dest, 'ringing', COALESCE(CAST(:st AS timestamptz), now()), 'pending', 'none', NULL,
                    0, now(), now())
                 ON CONFLICT (domain_uuid, local_call_id) DO NOTHING",
                [
                    'u' => $uuid, 'd' => $this->domainUuid, 'lid' => $localId,
                    'usr' => ($session['user_uuid'] ?? '') ?: null, 'ext' => $ext ?: null, 'aname' => $agentName ?: null,
                    'dir' => $direction,
                    'caller' => $direction === 'inbound' ? $remote : $ext,
                    'dest' => $direction === 'inbound' ? $ext : $remote,
                    'st' => $startedAt,
                ]
            );
            $existing = $this->journalByLocalId($localId);
        }
        if (!$existing) {
            throw new QontakException('Could not record the call.', 'unavailable');
        }

        // Create the Qontak task right away so the CRM screen pop can open it
        // during the call; the end-of-call sync updates the same task.
        if ($event === 'started' && empty($existing['qontak_task_id'])
            && self::settingsOf($this->configRow())['call_journaling'] && $this->isActive()) {
            $this->startTask($existing);
            $existing = $this->journalByLocalId($localId) ?: $existing;
        }

        // Once a journal reached a final status, later out-of-order events are ignored.
        if (in_array($existing['call_status'], self::FINAL_STATUSES, true)) {
            return $existing;
        }

        if ($event === 'answered') {
            $this->db->execute(
                "UPDATE public.q_qontak_call_journals SET call_status = 'answered',
                    answered_at = COALESCE(answered_at, CAST(:a AS timestamptz), now()), updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                ['a' => $answeredAt, 'u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
        } elseif ($event === 'ended') {
            $final = in_array($status, self::FINAL_STATUSES, true) ? $status : 'failed';
            $wasAnswered = $existing['answered_at'] !== null || $answeredAt !== null;
            // A call that never reached 'answered' can never be stored as completed.
            if ($final === 'completed' && !$wasAnswered) {
                $final = $direction === 'inbound' ? 'missed' : 'no_answer';
            }
            $journaling = self::settingsOf($this->configRow())['call_journaling'] && $this->isActive();
            $this->db->execute(
                "UPDATE public.q_qontak_call_journals SET call_status = :s,
                    answered_at = CASE WHEN :ans1 = 'y' THEN COALESCE(answered_at, CAST(:a AS timestamptz)) ELSE NULL END,
                    ended_at = COALESCE(CAST(:e AS timestamptz), now()),
                    talk_duration = :talk,
                    qontak_contact_id = COALESCE(:cid, qontak_contact_id),
                    recording_status = CASE WHEN :ans2 = 'y' THEN 'waiting' ELSE 'none' END,
                    sync_status = :sync, next_attempt_at = CASE WHEN :sync2 = 'pending' THEN now() ELSE NULL END,
                    updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                [
                    's' => $final, 'ans1' => $wasAnswered ? 'y' : 'n', 'a' => $answeredAt, 'e' => $endedAt,
                    'talk' => $final === 'completed' ? $talk : 0, 'cid' => $contactId,
                    'ans2' => $final === 'completed' ? 'y' : 'n',
                    'sync' => $journaling ? 'pending' : 'skipped', 'sync2' => $journaling ? 'pending' : 'skipped',
                    'u' => $existing['journal_uuid'], 'd' => $this->domainUuid,
                ]
            );
            $this->db->execute(
                'UPDATE public.q_qontak_call_journals SET duration = GREATEST(0, CAST(EXTRACT(EPOCH FROM (ended_at - started_at)) AS integer))
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                ['u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
        }
        return $this->journalByLocalId($localId);
    }

    public function journalByLocalId(string $localId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM public.q_qontak_call_journals WHERE domain_uuid = CAST(:d AS uuid) AND local_call_id = :lid',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'lid' => $localId]
        );
        return $row ?: null;
    }

    public function listJournals(int $page, int $perPage, string $phone, ?string $onlyUserUuid): array
    {
        $where = 'domain_uuid = CAST(:d AS uuid)';
        $binds = ['d' => $this->domainUuid];
        if ($onlyUserUuid !== null) {
            $where .= ' AND user_uuid = :usr';
            $binds['usr'] = $onlyUserUuid;
        }
        $digits = self::digits($phone);
        if (strlen($digits) >= 4) {
            $where .= " AND (regexp_replace(caller_number, '\\D', '', 'g') LIKE :p1 OR regexp_replace(destination_number, '\\D', '', 'g') LIKE :p2)";
            $binds['p1'] = '%' . substr($digits, -9);
            $binds['p2'] = '%' . substr($digits, -9);
        }
        $total = (int) ($this->db->fetchOne("SELECT count(*) AS n FROM public.q_qontak_call_journals WHERE $where", Enum::FETCH_ASSOC, $binds)['n'] ?? 0);
        $binds['lim'] = $perPage;
        $binds['off'] = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT * FROM public.q_qontak_call_journals WHERE $where ORDER BY started_at DESC LIMIT CAST(:lim AS integer) OFFSET CAST(:off AS integer)",
            Enum::FETCH_ASSOC,
            $binds
        );
        return ['journals' => array_map([self::class, 'publicJournal'], $rows ?: []), 'total' => $total, 'page' => $page];
    }

    public static function publicJournal(array $j): array
    {
        return [
            'local_call_id' => $j['local_call_id'],
            'call_ref' => self::callRef((string) $j['local_call_id']),
            'qontak_contact_id' => $j['qontak_contact_id'],
            'qontak_task_id' => $j['qontak_task_id'],
            'caller_number' => (string) $j['caller_number'],
            'destination_number' => (string) $j['destination_number'],
            'call_direction' => $j['call_direction'],
            'call_status' => $j['call_status'],
            'agent_extension' => $j['agent_extension'],
            'started_at' => self::iso($j['started_at']),
            'answered_at' => self::iso($j['answered_at']),
            'ended_at' => self::iso($j['ended_at']),
            'duration' => (int) $j['duration'],
            'talk_duration' => (int) $j['talk_duration'],
            'recording_available' => $j['recording_status'] === 'linked',
            // RingQ call id (xml_cdr_uuid): the popup transcribes it with RingQ's own /reports/audiototext.
            'call_uuid' => $j['recording_status'] === 'linked' ? ($j['xml_cdr_uuid'] ?? null) : null,
            'transcription' => $j['transcription'],
            'summary' => $j['summary'],
            'sentiment' => $j['sentiment'],
            'ai_status' => $j['ai_status'],
            'ai_error' => $j['ai_status'] === 'failed' ? ($j['ai_error'] ?? null) : null,
            'sync_status' => $j['sync_status'],
            'retry_count' => (int) $j['retry_count'],
            'last_error' => $j['last_error'],
        ];
    }

    /** Manual retry from the UI: clears the backoff and syncs now. */
    public function retryJournal(array $j): array
    {
        $this->db->execute(
            "UPDATE public.q_qontak_call_journals SET sync_status = CASE WHEN sync_status = 'synced' THEN 'synced' ELSE 'pending' END,
                retry_count = 0, next_attempt_at = now(),
                recording_status = CASE WHEN recording_status = 'unavailable' AND call_status = 'completed' THEN 'waiting' ELSE recording_status END
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        $this->syncJournal($j['journal_uuid']);
        return $this->journalByLocalId($j['local_call_id']);
    }

    /**
     * Pushes one journal to Qontak as a Task and advances its recording / AI
     * state. Safe to call concurrently and repeatedly: a row lock prevents two
     * workers from creating the task twice, and once qontak_task_id is stored
     * every later run UPDATEs that task instead of creating another.
     */
    public function syncJournal(string $journalUuid): void
    {
        $j = $this->db->fetchOne(
            "UPDATE public.q_qontak_call_journals SET sync_locked_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)
               AND ended_at IS NOT NULL
               AND (sync_locked_at IS NULL OR sync_locked_at < now() - interval '3 minutes')
             RETURNING *",
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid, 'd' => $this->domainUuid]
        );
        if (!$j) {
            return; // not ended yet, or another worker holds it
        }
        $settings = self::settingsOf($this->configRow());
        $update = ['sync_locked_at' => null];

        try {
            if (!$this->isActive()) {
                $update['next_attempt_at'] = null;
                if ($j['sync_status'] === 'pending') {
                    $update['sync_status'] = 'skipped';
                }
                $this->updateJournal($journalUuid, $update);
                return;
            }

            // 1. Recording (only answered calls have one).
            if ($j['recording_status'] === 'waiting') {
                $cdr = $this->findCdr($j);
                if ($cdr) {
                    $update['xml_cdr_uuid'] = $cdr['xml_cdr_uuid'];
                    $update['recording_status'] = 'linked';
                    $j['xml_cdr_uuid'] = $cdr['xml_cdr_uuid'];
                    $j['recording_status'] = 'linked';
                } elseif (time() - (int) strtotime((string) $j['ended_at']) > self::RECORDING_WAIT_SECONDS) {
                    $update['recording_status'] = 'unavailable';
                    $j['recording_status'] = 'unavailable';
                }
            }

            // 2. AI (only when enabled; never blocks the task sync).
            $aiWanted = $settings['ai_transcription'] || $settings['ai_summary'] || $settings['ai_sentiment'];
            // Transcription only after the call itself is saved in Qontak (sync_status synced),
            // so saving the call never waits on AI; the next pass adds the transcript.
            if ($aiWanted && $j['recording_status'] === 'linked' && in_array($j['ai_status'], [null, 'pending'], true)
                && $j['sync_status'] !== 'synced') {
                $update['ai_status'] = 'pending';
                $j['ai_status'] = 'pending';
            } elseif ($aiWanted && $j['recording_status'] === 'linked' && in_array($j['ai_status'], [null, 'pending'], true)) {
                $ai = $this->runAi($j, $settings);
                $update = array_merge($update, $ai);
                $j = array_merge($j, $ai);
            } elseif (!$aiWanted && $j['ai_status'] === null) {
                $update['ai_status'] = 'disabled';
                $j['ai_status'] = 'disabled';
            } elseif ($aiWanted && $j['ai_status'] === null && $j['recording_status'] === 'waiting') {
                $update['ai_status'] = 'pending';
                $j['ai_status'] = 'pending';
            }

            // AI that can never run (AI switched off, or no recording for this call):
            // settle it now, otherwise the journal is re-synced every few seconds forever.
            if ($j['ai_status'] === 'pending' && (!$aiWanted || in_array($j['recording_status'], ['none', 'unavailable'], true))) {
                $update['ai_status'] = $aiWanted ? 'failed' : 'disabled';
                $j['ai_status'] = $update['ai_status'];
                if ($aiWanted) {
                    $this->setAiReason($journalUuid, 'No call recording is available for this call.');
                }
            }

            // 3. Contact.
            $client = $this->client();
            $note = null;
            if (empty($j['qontak_contact_id'])) {
                $remote = $j['call_direction'] === 'inbound' ? $j['caller_number'] : $j['destination_number'];
                $matches = $this->searchContacts((string) $remote);
                if (count($matches) === 1) {
                    $update['qontak_contact_id'] = (string) $matches[0]['id'];
                    $j['qontak_contact_id'] = (string) $matches[0]['id'];
                } elseif (count($matches) > 1) {
                    $note = count($matches) . ' Qontak contacts share this number; the agent did not pick one, so the task is not linked to a contact.';
                } else {
                    $note = 'No Qontak contact matches this number.';
                }
            }

            // Contact details for the task name and company/deal association.
            $contact = !empty($j['qontak_contact_id']) ? $this->contactById($client, (string) $j['qontak_contact_id']) : null;

            // 4. Task (create once, then update).
            $categoryId = $this->taskCategoryId($client, (string) $settings['task_category_name']);
            $body = $this->taskBody($j, $categoryId, $note, $contact, (string) $settings['timezone']);
            if (!empty($j['qontak_task_id'])) {
                $task = $client->put('tasks/' . rawurlencode((string) $j['qontak_task_id']), $body);
                $this->rememberCustomerId((string) ($j['qontak_contact_id'] ?? ''), (string) ($task['customer_id'] ?? ''));
            } else {
                $task = $client->post('tasks', $body);
                $this->rememberCustomerId((string) ($j['qontak_contact_id'] ?? ''), (string) ($task['customer_id'] ?? ''));
                if (empty($task['id'])) {
                    throw new QontakException('Qontak did not return a task id.', 'unavailable');
                }
                $update['qontak_task_id'] = (string) $task['id'];
                // Persist immediately so a crash below can never cause a duplicate task.
                $this->updateJournal($journalUuid, ['qontak_task_id' => (string) $task['id'], 'qontak_contact_id' => $j['qontak_contact_id'] ?: null]);
            }
            $this->rememberSignMode($client);

            $update['sync_status'] = 'synced';
            $update['last_error'] = null;
            $update['retry_count'] = 0; // transient errors earlier must not add up over the journal's life
            $update['synced_at'] = date('c');
            $this->db->execute('UPDATE public.q_qontak_config SET last_sync_at = now() WHERE domain_uuid = CAST(:d AS uuid)', ['d' => $this->domainUuid]);

            // More to do later? (recording still coming, AI still pending)
            $stillWaiting = $j['recording_status'] === 'waiting' || $j['ai_status'] === 'pending';
            // Recording/AI still coming: check again soon (cron runs every ~5 s).
            $update['next_attempt_at'] = $stillWaiting ? date('c', time() + 3) : null;
        } catch (QontakException $e) {
            $retries = (int) $j['retry_count'] + 1;
            $update['retry_count'] = $retries;
            $update['last_error'] = mb_substr($e->getMessage(), 0, 500);
            if ($e->isTransient() && $retries < self::MAX_RETRIES) {
                $delay = max(self::BACKOFF[min($retries - 1, count(self::BACKOFF) - 1)], (int) $e->retryAfter);
                $update['next_attempt_at'] = date('c', time() + $delay);
                if ($j['sync_status'] !== 'synced') {
                    $update['sync_status'] = 'pending';
                }
            } else {
                $update['next_attempt_at'] = null;
                if ($j['sync_status'] !== 'synced') {
                    $update['sync_status'] = 'failed';
                }
            }
            if (in_array($e->reason, ['auth_failed', 'forbidden'], true)) {
                $this->setStatus('error', $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('[qontak] sync error ' . get_class($e) . ': ' . $e->getMessage());
            $retries = (int) $j['retry_count'] + 1;
            $update['retry_count'] = $retries;
            $update['last_error'] = 'Internal error while syncing.';
            $update['next_attempt_at'] = $retries < self::MAX_RETRIES ? date('c', time() + 300) : null;
            if ($j['sync_status'] !== 'synced') {
                $update['sync_status'] = $retries < self::MAX_RETRIES ? 'pending' : 'failed';
            }
        }
        $this->updateJournal($journalUuid, $update);
    }

    private function updateJournal(string $journalUuid, array $fields): void
    {
        $allowed = ['sync_locked_at', 'next_attempt_at', 'sync_status', 'xml_cdr_uuid', 'recording_status', 'ai_status',
            'transcription', 'summary', 'sentiment', 'qontak_contact_id', 'qontak_task_id', 'last_error', 'retry_count', 'synced_at'];
        $sets = [];
        $binds = ['u' => $journalUuid, 'd' => $this->domainUuid];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $cast = in_array($k, ['sync_locked_at', 'next_attempt_at', 'synced_at'], true) ? 'timestamptz'
                : ($k === 'xml_cdr_uuid' ? 'uuid' : ($k === 'retry_count' ? 'integer' : null));
            $sets[] = $cast ? "$k = CAST(:f_$k AS $cast)" : "$k = :f_$k";
            $binds['f_' . $k] = $v;
        }
        if (!$sets) {
            return;
        }
        $this->db->execute(
            'UPDATE public.q_qontak_call_journals SET ' . implode(', ', $sets) . ', updated_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
            $binds
        );
    }

    /** Finds (or creates, once) the Qontak task category used for call tasks. */
    private function taskCategoryId($client, string $name): ?int
    {
        $row = $this->configRow();
        if (!empty($row['task_category_id'])) {
            return (int) $row['task_category_id'];
        }
        $id = null;
        // Qontak may store a created category as "<slug>-<uuid>" (seen live:
        // POST {"name":"Call"} -> "call-37c26a69-..."), so match that form too.
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
        foreach ((array) $client->get('tasks/category') as $cat) {
            if (!is_array($cat) || !isset($cat['id'])) {
                continue;
            }
            $catName = strtolower(trim((string) ($cat['name'] ?? '')));
            if ($catName === strtolower($name) || preg_match('/^' . preg_quote($slug, '/') . '-[0-9a-f-]{36}$/', $catName)) {
                $id = (int) $cat['id'];
                break;
            }
        }
        if ($id === null) {
            try {
                $created = $client->post('tasks/category', ['name' => $name]);
                $id = isset($created['id']) ? (int) $created['id'] : null;
            } catch (QontakException $e) {
                if ($e->reason !== 'forbidden') {
                    throw $e;
                }
                // No permission to create categories: log the task without one.
                error_log('[qontak] no permission to create task category; continuing without category');
            }
        }
        if ($id !== null) {
            $this->db->execute(
                'UPDATE public.q_qontak_config SET task_category_id = :c WHERE domain_uuid = CAST(:d AS uuid)',
                ['c' => $id, 'd' => $this->domainUuid]
            );
            $this->configCache = null;
        }
        return $id;
    }

    /** Short unique reference put in the task name, e.g. "RQ1a2b3c4d" (searchable in Qontak). */
    public static function callRef(string $localCallId): string
    {
        return 'RQ' . substr(strtolower(preg_replace('/[^0-9a-f]/i', '', $localCallId)), 0, 8);
    }

    /**
     * Creates the Qontak task when a call starts (status In Progress). Never
     * throws: a failure here only means the task is created at hang-up instead.
     */
    public function startTask(array $j): void
    {
        $locked = $this->db->fetchOne(
            "UPDATE public.q_qontak_call_journals SET sync_locked_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)
               AND qontak_task_id IS NULL
               AND (sync_locked_at IS NULL OR sync_locked_at < now() - interval '3 minutes')
             RETURNING *",
            Enum::FETCH_ASSOC,
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        if (!$locked) {
            return;
        }
        $update = ['sync_locked_at' => null];
        try {
            $settings = self::settingsOf($this->configRow());
            $client = $this->client();
            $remote = $locked['call_direction'] === 'inbound' ? $locked['caller_number'] : $locked['destination_number'];
            $matches = $this->searchContacts((string) $remote);
            $contact = null;
            if (count($matches) === 1) {
                $locked['qontak_contact_id'] = (string) $matches[0]['id'];
                $update['qontak_contact_id'] = $locked['qontak_contact_id'];
                $contact = $this->contactById($client, $locked['qontak_contact_id']);
            }
            $categoryId = $this->taskCategoryId($client, (string) $settings['task_category_name']);
            $body = $this->taskBody($locked, $categoryId, null, $contact, (string) $settings['timezone']);
            $task = $client->post('tasks', $body);
            if (!empty($task['id'])) {
                $update['qontak_task_id'] = (string) $task['id'];
                $this->rememberCustomerId((string) ($locked['qontak_contact_id'] ?? ''), (string) ($task['customer_id'] ?? ''));
            }
            $this->rememberSignMode($client);
        } catch (\Throwable $e) {
            error_log('[qontak] start-of-call task not created: ' . get_class($e) . ' ' . mb_substr($e->getMessage(), 0, 200));
        }
        $this->updateJournal($j['journal_uuid'], $update);
    }

    private function taskBody(array $j, ?int $categoryId, ?string $note, ?array $contact = null, string $timezone = 'UTC'): array
    {
        $labels = [
            'completed' => 'Answered', 'missed' => 'Missed', 'rejected' => 'Rejected', 'busy' => 'Busy',
            'no_answer' => 'No answer', 'failed' => 'Failed', 'ringing' => 'Ringing', 'answered' => 'In call',
        ];
        $status = $labels[$j['call_status']] ?? ucfirst((string) $j['call_status']);
        $inbound = $j['call_direction'] === 'inbound';
        $remote = $inbound ? $j['caller_number'] : $j['destination_number'];
        $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        // Times in the tenant's own zone, e.g. "15 Oct 2026, 10:28:09 IST".
        $local = function ($v) use ($tz) {
            if ($v === null || $v === '') {
                return '-';
            }
            $d = new \DateTime((string) $v);
            $d->setTimezone($tz);
            return $d->format('d M Y, H:i:s T');
        };
        $contactName = $contact ? trim((string) ($contact['full_name'] ?? '')) : '';
        $agentName = trim((string) ($j['agent_name'] ?? ''));

        $lines = [
            'Direction' => $inbound ? 'Inbound' : 'Outbound',
            'Status' => $status,
            'Contact' => $contactName !== '' ? $contactName : '-',
            'Number' => $remote,
            'Agent' => trim($agentName . ($j['agent_extension'] ? ' (ext ' . $j['agent_extension'] . ')' : '')),
            'Started' => $local($j['started_at']),
            'Answered' => $local($j['answered_at']),
            'Ended' => $local($j['ended_at']),
            'Duration' => self::fmtDuration((int) $j['duration']),
            'Talk time' => self::fmtDuration((int) $j['talk_duration']),
            'RingQ call ID' => $j['local_call_id'],
        ];
        $html = '';
        foreach ($lines as $k => $v) {
            $html .= '<p><strong>' . $h($k) . ':</strong> ' . $h($v) . '</p>';
        }
        if ($j['recording_status'] === 'linked') {
            $url = $this->recordingUrl($j);
            if ($url) {
                $html .= '<p><strong>Recording:</strong> <a href="' . $h($url) . '" target="_blank" rel="noopener noreferrer">' . $h('Play recording') . '</a>' . (self::RECORDING_LINK_DAYS > 0 ? ' (link expires in ' . self::RECORDING_LINK_DAYS . ' days)' : '') . '</p>';
                // Qontak shows task details in a rich-text editor where links are not
                // clickable, so also give the address as text to copy into a browser.
                $html .= '<p><strong>Recording URL:</strong> ' . $h($url) . '</p>';
            }
        } elseif ($j['recording_status'] === 'waiting') {
            $html .= '<p><strong>Recording:</strong> processing, will be added shortly.</p>';
        }
        if (!empty($j['summary'])) {
            $html .= '<p><strong>AI summary:</strong> ' . $h($j['summary']) . '</p>';
        }
        if (!empty($j['sentiment'])) {
            $html .= '<p><strong>Sentiment:</strong> ' . $h($j['sentiment']) . '</p>';
        }
        if (!empty($j['transcription'])) {
            $html .= '<p><strong>Transcription:</strong></p><p>' . nl2br($h(mb_substr((string) $j['transcription'], 0, 8000))) . '</p>';
        }
        if ($note) {
            $html .= '<p><em>' . $h($note) . '</em></p>';
        }

        $ended = new \DateTime((string) ($j['ended_at'] ?: 'now'));
        $ended->setTimezone($tz);
        // e.g. "Outbound call - Answered - paramesh waran (1004) - Paramesh Paramesh"
        $name = ($inbound ? 'Inbound' : 'Outbound') . ' call - ' . $status . ' - '
            . ($contactName !== '' ? $contactName . ' (' . $remote . ')' : $remote)
            . ($agentName !== '' ? ' - ' . $agentName : '')
            . ' - ' . self::callRef((string) $j['local_call_id']);
        $body = [
            'name' => mb_substr($name, 0, 250),
            // 4 = Completed for handled calls; missed/unanswered calls stay 1 = Not Started as a call-back to-do.
            // 4 Completed, 2 In Progress while the call is live, 1 Not Started (call-back to-do) otherwise.
            'crm_task_status_id' => $j['call_status'] === 'completed' ? 4 : (in_array($j['call_status'], ['ringing', 'answered'], true) ? 2 : 1),
            'due_date' => $ended->format('Y-m-d\TH:i:s.000P'),
            'detail' => $html,
        ];
        if (!empty($j['qontak_contact_id'])) {
            $body['crm_person_id'] = (int) $j['qontak_contact_id'];
        }
        // Also associate the contact's company and (first) deal, when it has them.
        if ($contact && !empty($contact['company_id'])) {
            $body['crm_company_id'] = (int) $contact['company_id'];
        }
        if ($contact && !empty($contact['deal_ids'])) {
            $body['crm_deal_id'] = (int) $contact['deal_ids'][0];
        }
        if ($categoryId !== null) {
            $body['crm_task_category_id'] = $categoryId;
        }
        return $body;
    }

    // ------------------------------------------------------------------
    // Recordings
    // ------------------------------------------------------------------

    /** Matches the journal to its CDR row (same tenant, same remote number, start within ±90 s, has a recording). */
    private function findCdr(array $j): ?array
    {
        $remote = $j['call_direction'] === 'inbound' ? $j['caller_number'] : $j['destination_number'];
        $tail = substr(self::digits((string) $remote), -9);
        if (strlen($tail) < 4) {
            return null;
        }
        $epoch = (int) strtotime((string) $j['started_at']);
        $row = $this->db->fetchOne(
            "SELECT xml_cdr_uuid, record_path, record_name, start_epoch FROM public.q_ringq_cdr
             WHERE domain_uuid = CAST(:d AS uuid)
               AND start_epoch BETWEEN CAST(:lo AS bigint) AND CAST(:hi AS bigint)
               AND COALESCE(record_name, '') <> ''
               AND (right(regexp_replace(COALESCE(caller_id_number, ''), '\\D', '', 'g'), 9) = :t1
                    OR right(regexp_replace(COALESCE(destination_number, ''), '\\D', '', 'g'), 9) = :t2)
             ORDER BY abs(start_epoch - CAST(:ep AS bigint)) ASC
             LIMIT 1",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'lo' => $epoch - 90, 'hi' => $epoch + 90, 't1' => $tail, 't2' => $tail, 'ep' => $epoch]
        );
        return $row ?: null;
    }

    /** Signed, expiring, tenant-bound link served by QontakController::recordingAction. */
    public function recordingUrl(array $j): ?string
    {
        $row = $this->configRow();
        if (!$row || empty($row['link_salt']) || $this->subdomain === '') {
            return null;
        }
        $exp = self::RECORDING_LINK_DAYS > 0 ? time() + self::RECORDING_LINK_DAYS * 86400 : 0;
        $sig = QontakCrypto::sign($j['journal_uuid'] . '|' . $exp . '|' . $row['link_salt']);
        return 'https://' . $this->subdomain . ':8443/qontak/recording/' . $j['journal_uuid'] . '/' . $exp . '/' . $sig;
    }

    /**
     * Validates a signed recording link and returns the absolute file path,
     * or null. Only files under the FreeSWITCH recordings tree are served.
     */
    public static function resolveSignedRecording($db, string $journalUuid, string $exp, string $sig): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $journalUuid) || !ctype_digit($exp) || ((int) $exp !== 0 && (int) $exp < time()) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $sig)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT j.journal_uuid, j.domain_uuid, c.link_salt, cdr.record_path, cdr.record_name
               FROM public.q_qontak_call_journals j
               JOIN public.q_qontak_config c ON c.domain_uuid = j.domain_uuid
               JOIN public.q_ringq_cdr cdr ON cdr.xml_cdr_uuid = j.xml_cdr_uuid AND cdr.domain_uuid = j.domain_uuid
              WHERE j.journal_uuid = CAST(:u AS uuid) AND j.recording_status = 'linked'",
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid]
        );
        if (!$row || empty($row['link_salt'])) {
            return null;
        }
        if (!QontakCrypto::verify($row['journal_uuid'] . '|' . $exp . '|' . $row['link_salt'], $sig)) {
            return null;
        }
        $path = realpath(rtrim((string) $row['record_path'], '/') . '/' . basename((string) $row['record_name']));
        if ($path === false || strpos($path, '/var/lib/freeswitch/recordings/') !== 0 || !is_file($path)) {
            return null;
        }
        return ['path' => $path, 'name' => basename($path)];
    }

    // ------------------------------------------------------------------
    // AI
    // ------------------------------------------------------------------

    private function runAi(array $j, array $settings): array
    {
        $cdr = $this->db->fetchOne(
            'SELECT xml_cdr_uuid, json, record_path, record_name FROM public.q_ringq_cdr WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'c' => $j['xml_cdr_uuid']]
        );
        if (!$cdr) {
            $this->setAiReason((string) $j['journal_uuid'], 'The call record was not found.');
            return ['ai_status' => 'failed'];
        }
        // The agent's popup transcribes through RingQ's /reports/audiototext right after the
        // call (same as Call Logs). Background workers only step in if that has not happened
        // within 90 s (popup closed), so a call is never transcribed twice.
        if (PHP_SAPI === 'cli' && RingqTranscriptProvider::storedText((string) ($cdr['json'] ?? '')) === null) {
            // The popup is transcribing (flag set when it starts), or may still start: wait.
            if (time() - (int) strtotime((string) $j['ended_at']) < 90 || $this->redisHas('qontak:transcribing:' . $j['journal_uuid'])) {
                return ['ai_status' => 'pending'];
            }
            // Only one background worker transcribes a call (lock outlives the 170 s request).
            if (!$this->redisLock('qontak:txlock:' . $j['journal_uuid'], 330)) {
                return ['ai_status' => 'pending'];
            }
        }
        $tx = (new RingqTranscriptProvider($this->redis))->transcript($cdr, $this->subdomain);
        if ($tx['status'] === 'pending') {
            $age = time() - (int) strtotime((string) $j['ended_at']);
            // Transient (recording still being written, service busy): retry, give up after 30 min.
            if ($age > 1800) {
                $this->setAiReason((string) $j['journal_uuid'], (string) ($tx['reason'] ?? 'Transcription timed out.'));
                return ['ai_status' => 'failed'];
            }
            return ['ai_status' => 'pending'];
        }
        if ($tx['status'] !== 'done') {
            $this->setAiReason((string) $j['journal_uuid'], (string) ($tx['reason'] ?? 'Transcription is not available.'));
            return ['ai_status' => 'failed'];
        }
        // Keep RingQ's Call Logs in step and never pay to transcribe the same call twice.
        if (trim((string) ($cdr['json'] ?? '')) === '') {
            try {
                $this->db->execute(
                    "UPDATE public.q_ringq_cdr SET json = :j WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid) AND COALESCE(json, '') = ''",
                    ['j' => RingqTranscriptProvider::cdrJson((string) $tx['text']), 'd' => $this->domainUuid, 'c' => $cdr['xml_cdr_uuid']]
                );
            } catch (\Throwable $e) {
                error_log('[qontak] could not store transcript on CDR: ' . $e->getMessage());
            }
        }
        $this->setAiReason((string) $j['journal_uuid'], null);
        $out = ['ai_status' => 'done'];
        if ($settings['ai_transcription']) {
            $out['transcription'] = $tx['text'];
        }
        if ($settings['ai_summary'] || $settings['ai_sentiment']) {
            $analysis = (new NullSummaryProvider())->analyze((string) $tx['text']);
            if ($analysis !== null) {
                if ($settings['ai_summary'] && !empty($analysis['summary'])) {
                    $out['summary'] = mb_substr((string) $analysis['summary'], 0, 4000);
                }
                if ($settings['ai_sentiment'] && !empty($analysis['sentiment'])) {
                    $out['sentiment'] = mb_substr((string) $analysis['sentiment'], 0, 20);
                }
            }
        }
        return $out;
    }

    private function cdrTranscript(array $j): ?string
    {
        if (empty($j['xml_cdr_uuid'])) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT json FROM public.q_ringq_cdr WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'c' => $j['xml_cdr_uuid']]
        );
        return $row ? RingqTranscriptProvider::storedText((string) ($row['json'] ?? '')) : null;
    }

    /**
     * Called by the popup right after it transcribed the call through RingQ's own
     * /reports/audiototext/{xml_cdr_uuid}: the text is now on the CDR, so add it to the
     * Qontak task at once. $error = RingQ's message when transcription did not work.
     */
    public function applyBrowserTranscript(array $j, string $error): array
    {
        try {
            $this->redis->del('qontak:transcribing:' . $j['journal_uuid']);
        } catch (\Throwable $e) {
        }
        if ($this->cdrTranscript($j) !== null) {
            if ($j['ai_status'] !== 'done') {
                $this->db->execute(
                    "UPDATE public.q_qontak_call_journals SET ai_status = 'pending', next_attempt_at = now()
                     WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                    ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
                );
                RingqTranscriptProvider::$hold = false;
                $this->syncJournal((string) $j['journal_uuid']); // stored text: no remote AI call
            }
        } elseif ($error !== '' && in_array($j['ai_status'], [null, 'pending'], true)) {
            $reason = trim(preg_replace('/\s*\(Code [A-Z0-9]+\)\s*$/', '', $error));
            $this->setAiReason((string) $j['journal_uuid'], 'RingQ transcription: ' . mb_substr($reason, 0, 250));
            $this->db->execute(
                "UPDATE public.q_qontak_call_journals SET ai_status = 'failed'
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
            );
        }
        return $this->journalByLocalId((string) $j['local_call_id']) ?: $j;
    }

    /** The popup started RingQ transcription for this call: background workers wait (max 10 min). */
    public function markBrowserTranscribing(array $j): void
    {
        try {
            $this->redis->setex('qontak:transcribing:' . $j['journal_uuid'], 600, '1');
        } catch (\Throwable $e) {
            // Without Redis the 90 s grace still applies.
        }
    }

    private function redisHas(string $key): bool
    {
        try {
            return (bool) $this->redis->exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function redisLock(string $key, int $ttl): bool
    {
        try {
            return (bool) $this->redis->set($key, '1', 'EX', $ttl, 'NX');
        } catch (\Throwable $e) {
            return true; // no Redis: the journal row lock still serialises workers
        }
    }

    /** Why AI could not run for a call (shown in the popup); kept 7 days in Redis. */
    private function setAiReason(string $journalUuid, ?string $reason): void
    {
        try {
            $key = 'qontak:aireason:' . $journalUuid;
            if ($reason === null) {
                $this->redis->del($key);
            } else {
                $this->redis->setex($key, 7 * 86400, mb_substr($reason, 0, 300));
            }
        } catch (\Throwable $e) {
            // Redis down: the popup falls back to its generic text.
        }
    }

    public static function aiReason($redis, string $journalUuid): ?string
    {
        try {
            $v = $redis->get('qontak:aireason:' . $journalUuid);
            return is_string($v) && $v !== '' ? $v : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Manual (re)run of AI for one journal, from POST qontak/aiAnalysis. */
    public function requeueAi(array $j): void
    {
        $this->db->execute(
            "UPDATE public.q_qontak_call_journals SET ai_status = 'pending', next_attempt_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
    }

    /** Manual recording re-sync from POST qontak/recordingsSync. */
    public function requeueRecording(array $j): void
    {
        $this->db->execute(
            "UPDATE public.q_qontak_call_journals SET
                recording_status = CASE WHEN call_status = 'completed' AND recording_status <> 'linked' THEN 'waiting' ELSE recording_status END,
                next_attempt_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
    }

    /**
     * Post-hangup follow-up, run in a separate CLI process (QontakTask::afterAction)
     * so no web worker is held: adds the recording link as soon as the CDR exists
     * (checked every 2 s), then transcribes and updates the same Qontak task.
     */
    public function followUp(string $journalUuid): void
    {
        $t0 = microtime(true);
        $seen = [];
        $mark = function (string $what) use (&$seen, $t0, $journalUuid) {
            if (!isset($seen[$what])) {
                $seen[$what] = true;
                error_log(sprintf('[qontak] after-call %s: %s at +%.1fs', substr($journalUuid, 0, 8), $what, microtime(true) - $t0));
            }
        };
        $mark('started');
        for ($i = 0; $i < 45; $i++) {
            $j = $this->db->fetchOne(
                'SELECT * FROM public.q_qontak_call_journals WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                Enum::FETCH_ASSOC,
                ['u' => $journalUuid, 'd' => $this->domainUuid]
            );
            if (!$j || in_array($j['sync_status'], ['failed', 'skipped'], true)) {
                return; // failed for good (needs Retry) or integration off
            }
            // Respect the retry back-off (Qontak errors, Retry-After) instead of hammering Qontak.
            if (!empty($j['next_attempt_at']) && strtotime((string) $j['next_attempt_at']) > time()
                && !($j['sync_status'] === 'synced' && $j['recording_status'] === 'linked' && $j['ai_status'] === 'pending')) {
                sleep(2);
                continue;
            }
            if ($j['sync_status'] !== 'synced' || $j['recording_status'] === 'waiting') {
                // Call details / recording link first, never waiting on AI.
                RingqTranscriptProvider::$hold = true;
                try {
                    $this->syncJournal($journalUuid);
                } finally {
                    RingqTranscriptProvider::$hold = false;
                }
                $j = $this->db->fetchOne(
                    'SELECT * FROM public.q_qontak_call_journals WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                    Enum::FETCH_ASSOC,
                    ['u' => $journalUuid, 'd' => $this->domainUuid]
                );
                if (!$j) {
                    return;
                }
            }
            if ($j['recording_status'] === 'linked') {
                $mark('recording linked');
            }
            if ($j['sync_status'] === 'synced' && $j['recording_status'] === 'linked' && $j['ai_status'] === 'pending') {
                // The popup transcribes via /reports/audiototext; as soon as the text is on
                // the CDR, add it to the Qontak task (no second transcription here).
                if ($this->cdrTranscript($j) !== null) {
                    $mark('transcript found');
                    $this->syncJournal($journalUuid);
                    continue;
                }
                sleep(2);
                continue;
            }
            if ($j['recording_status'] !== 'waiting' && $j['ai_status'] !== 'pending' && $j['sync_status'] !== 'pending') {
                $mark('finished, AI ' . (string) $j['ai_status']);
                return; // all done (or failed with a reason)
            }
            sleep(2);
        }
        // Anything still open is picked up by the cron task.
    }

    // ------------------------------------------------------------------
    // Cron entry point (all tenants)
    // ------------------------------------------------------------------

    public static function processDue($db, $redis, int $limit = 50): int
    {
        $rows = $db->fetchAll(
            "SELECT j.journal_uuid, j.domain_uuid, d.domain_name
               FROM public.q_qontak_call_journals j
               JOIN public.v_domains d ON d.domain_uuid = j.domain_uuid
              WHERE j.next_attempt_at IS NOT NULL AND j.next_attempt_at <= now()
                AND (j.sync_locked_at IS NULL OR j.sync_locked_at < now() - interval '3 minutes')
              ORDER BY j.next_attempt_at ASC
              LIMIT CAST(:lim AS integer)",
            Enum::FETCH_ASSOC,
            ['lim' => $limit]
        );
        $services = [];
        $n = 0;
        foreach ($rows ?: [] as $r) {
            $key = $r['domain_uuid'];
            if (!isset($services[$key])) {
                $services[$key] = new self($db, $redis, $r['domain_uuid'], (string) $r['domain_name']);
            }
            $services[$key]->syncJournal($r['journal_uuid']);
            $n++;
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Webhooks
    // ------------------------------------------------------------------

    public static function configByWebhookToken($db, string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) {
            return null;
        }
        $row = $db->fetchOne(
            'SELECT c.domain_uuid, c.webhook_token, d.domain_name FROM public.q_qontak_config c
               JOIN public.v_domains d ON d.domain_uuid = c.domain_uuid
              WHERE c.webhook_token = :t AND c.enabled = true',
            Enum::FETCH_ASSOC,
            ['t' => $token]
        );
        return $row && hash_equals((string) $row['webhook_token'], $token) ? $row : null;
    }

    /** Returns false when the event was already processed (duplicate delivery / replay). */
    public function recordWebhook(string $rawBody, array $payload): bool
    {
        $hash = hash('sha256', $rawBody);
        $eventName = mb_substr((string) ($payload['event'] ?? $payload['event_name'] ?? $payload['type'] ?? 'unknown'), 0, 80);
        $inserted = $this->db->fetchOne(
            "INSERT INTO public.q_qontak_webhook_events (domain_uuid, event_hash, event_name, received_at)
             VALUES (CAST(:d AS uuid), :h, :e, now())
             ON CONFLICT (domain_uuid, event_hash) DO NOTHING
             RETURNING event_hash",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'h' => $hash, 'e' => $eventName]
        );
        if (!$inserted) {
            return false;
        }
        // Contact changed in Qontak: drop our cached lookups for its number.
        if (strpos($eventName, 'qontak.crm.lead') === 0) {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (!empty($data['telephone'])) {
                $this->forgetContactCache((string) $data['telephone']);
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function bool($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true' || $v === 'on';
    }

    public static function iso($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $t = strtotime((string) $v);
        return $t === false ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    /** Browser timestamps are trusted only within ±1 day of server time. */
    private static function clampTime($v): ?string
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $t = strtotime($v);
        if ($t === false || abs($t - time()) > 86400) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $t);
    }

    private static function fmtDuration(int $s): string
    {
        return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
}
