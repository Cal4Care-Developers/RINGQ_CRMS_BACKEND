<?php
declare(strict_types=1);

namespace Library\Hubspot;

use Phalcon\Db\Enum;

require_once __DIR__ . '/HubspotException.php';
require_once __DIR__ . '/HubspotCrypto.php';
require_once __DIR__ . '/HubspotClient.php';
require_once __DIR__ . '/HubspotTranscripts.php';

/**
 * All HubSpot business logic, shared by HubspotcrmController (web) and
 * HubspotcrmTask (cron / after-call worker).
 *
 * Every query is scoped by the tenant's domain_uuid passed to the constructor;
 * nothing here can read or write another tenant's rows. Tables (see
 * hubspot-backend/migrations): q_hubspot_config, q_hubspot_call_journals.
 * Redis keys: hubspot:* only.
 *
 * HubSpot API endpoints used (CRM v3 / v4, base https://api.hubapi.com):
 *   GET  /crm/v3/objects/contacts?limit=1                 connection + scope probe
 *   GET  /account-info/v3/details                         portal id, UI domain, time zone
 *   POST /oauth/v2/private-apps/get/access-token-info     granted scopes (best effort)
 *   GET  /crm/v3/properties/calls/hs_call_status          allowed call status values
 *   GET  /calling/v1/dispositions                         this portal's call outcomes
 *   POST /crm/v3/objects/contacts/search                  phone / free-text lookup
 *   GET  /crm/v3/objects/contacts/{id}                    contact details (+ company association)
 *   GET  /crm/v3/objects/companies/{id}                   company name
 *   POST /crm/v3/objects/contacts                         contact creation (optional)
 *   POST /crm/v3/objects/calls                            create call activity (+ associations)
 *   PATCH /crm/v3/objects/calls/{id}                      add recording / transcript later
 *   POST /crm/v3/objects/calls/search                     duplicate recovery by call reference
 *   PUT  /crm/v4/objects/calls/{id}/associations/default/contacts/{contactId}
 */
class HubspotService
{
    const CONTACT_CACHE_TTL = 300;
    const RECORDING_WAIT_SECONDS = 1800;   // look for the CDR recording for 30 min after hangup
    // Same-call matching (one incoming call ringing on several of the agent's devices) compares the
    // caller's last 9 digits, i.e. the whole number for an internal extension such as 1004.
    const SAME_CALLER_MIN_DIGITS = 2;
    const RECORDING_LINK_DAYS = 0;         // 0 = links never expire; revoked together by Disconnect
    const MAX_RETRIES = 8;
    /** HubSpot dialer: how long to wait for HubSpot's own call record before RingQ creates one. */
    const CTI_LINK_WAIT_SECONDS = 60;
    /** HubSpot dialer tokens expire after this many days (the agent reconnects). */
    const CTI_TOKEN_DAYS = 30;
    /** At most this many active dialer tokens per user (older ones are revoked). */
    const CTI_TOKENS_PER_USER = 5;
    const BACKOFF = [60, 300, 900, 1800, 3600, 7200, 14400, 28800];

    /** HubSpot-defined association type ids (CRM associations v4 reference). */
    const ASSOC_CALL_TO_CONTACT = 194;
    const ASSOC_CALL_TO_COMPANY = 182;

    /** Scopes the integration cannot work without (private app → Scopes). */
    const REQUIRED_SCOPES = ['crm.objects.contacts.read', 'crm.objects.contacts.write'];
    /** Used when available; features degrade gracefully without them. */
    const OPTIONAL_SCOPES = ['crm.objects.companies.read'];

    const STATUSES = ['ringing', 'answered', 'completed', 'missed', 'rejected', 'busy', 'no_answer', 'failed'];
    const FINAL_STATUSES = ['completed', 'missed', 'rejected', 'busy', 'no_answer', 'failed'];

    const CONTACT_PROPERTIES = ['firstname', 'lastname', 'email', 'phone', 'mobilephone', 'company', 'jobtitle',
        'lifecyclestage', 'hs_lead_status', 'hubspot_owner_id', 'city', 'country', 'createdate', 'lastmodifieddate'];

    const SETTINGS_DEFAULTS = [
        'popup_incoming' => true,
        'popup_outgoing' => true,
        'call_journaling' => true,
        // Also log unanswered calls (missed / rejected / no answer / failed) as HubSpot calls.
        'log_unanswered' => true,
        'contact_creation' => false,
        // Unknown caller (a real phone number, not an extension): create a HubSpot contact
        // automatically so every call is saved on a contact (and screen pop can open it).
        'auto_create_contacts' => false,
        'associate_company' => true,
        // Put a signed RingQ recording link on the HubSpot call (hs_call_recording_url).
        'include_recording' => true,
        // Public HTTPS origin HubSpot uses to fetch recordings. Empty = https://<tenant>:8443
        'recording_base_url' => '',
        // Open the caller's HubSpot record in a browser tab when a call starts.
        'screenpop_incoming' => false,
        'screenpop_outgoing' => false,
        'ai_transcription' => false,
        'ai_summary' => false,
        'ai_sentiment' => false,
    ];

    private $db;
    private $redis;
    /** @var string */
    private $domainUuid;
    /** @var string tenant FQDN, e.g. sgringq100.ringq.ai */
    private $subdomain;
    /** @var array|null */
    private $configCache = null;
    /** @var HubspotClient|null */
    private $clientCache = null;

    public function __construct($db, $redis, string $domainUuid, string $subdomain)
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $domainUuid)) {
            throw new HubspotException('Invalid tenant.', 'validation');
        }
        $this->db = $db;
        $this->redis = $redis;
        $this->domainUuid = strtolower($domainUuid);
        $this->subdomain = (string) preg_replace('/[^a-z0-9.-]/i', '', $subdomain);
    }

    // ==================================================================
    // Configuration
    // ==================================================================

    public function configRow(bool $fresh = false): ?array
    {
        if ($this->configCache !== null && !$fresh) {
            return $this->configCache ?: null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM public.q_hubspot_config WHERE domain_uuid = CAST(:d AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid]
        );
        $this->configCache = $row ?: [];
        $this->clientCache = null;
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

    private static function jsonList($v): array
    {
        if ($v === null || $v === '') {
            return [];
        }
        $d = json_decode((string) $v, true);
        return is_array($d) ? $d : [];
    }

    /** Shape returned to the browser. Never includes the access token. */
    public function publicConfig(bool $canManage): array
    {
        $row = $this->configRow();
        $s = self::settingsOf($row);
        $scopes = $row ? self::jsonList($row['granted_scopes'] ?? null) : [];
        $out = [
            'enabled' => $row ? self::bool($row['enabled']) : false,
            'token_set' => $row ? !empty($row['access_token_enc']) : false,
            // Last 4 characters only, so an admin can tell which token is stored.
            'token_hint' => $canManage && $row && !empty($row['token_last4']) ? '••••' . $row['token_last4'] : '',
            'portal_id' => $row && $row['portal_id'] !== null ? (string) $row['portal_id'] : null,
            'ui_domain' => $row ? (string) ($row['ui_domain'] ?? '') : '',
            'account_timezone' => $row ? (string) ($row['account_timezone'] ?? '') : '',
            'granted_scopes' => $canManage ? array_values($scopes) : [],
            'missing_scopes' => $canManage && $scopes ? array_values(array_diff(self::REQUIRED_SCOPES, $scopes)) : [],
            'companies_scope' => $scopes ? in_array('crm.objects.companies.read', $scopes, true) : null,
            'connection_status' => $row['connection_status'] ?? 'not_configured',
            'last_tested_at' => self::iso($row['last_tested_at'] ?? null),
            'last_sync_at' => self::iso($row['last_sync_at'] ?? null),
            'last_error' => $canManage ? ($row['last_error'] ?? null) : null,
            'can_manage' => $canManage,
            // RingQ has no summary/sentiment analysis provider yet (see HubspotTranscripts.php).
            'ai_analysis_available' => !($this->summaryProvider() instanceof NullHubspotSummaryProvider),
            'ai_transcription_available' => (new HubspotTranscripts($this->redis))->ringqTranscriptionEnabled($this->subdomain),
            'default_recording_base_url' => $this->subdomain !== '' ? 'https://' . $this->subdomain . ':8443' : '',
        ];
        foreach (self::SETTINGS_DEFAULTS as $k => $default) {
            $out[$k] = is_bool($default) ? (bool) $s[$k] : (string) $s[$k];
        }
        if (!$canManage) {
            $out['recording_base_url'] = '';
            $out['default_recording_base_url'] = '';
        }
        return $out;
    }

    /** Validates admin input. Returns [cleanInput, errors]. */
    public static function validateConfigInput(array $in): array
    {
        $errors = [];
        $out = [];
        $token = trim((string) ($in['access_token'] ?? ''));
        if ($token !== '' && (strlen($token) < 20 || strlen($token) > 512 || preg_match('/\s/', $token))) {
            $errors[] = 'The access token does not look like a HubSpot private app token.';
        }
        $out['access_token'] = $token;
        $out['enabled'] = self::bool($in['enabled'] ?? false);
        $settings = [];
        foreach (self::SETTINGS_DEFAULTS as $k => $default) {
            if ($k === 'recording_base_url') {
                $url = rtrim(trim((string) ($in[$k] ?? '')), '/');
                if ($url !== '') {
                    $p = parse_url($url);
                    if (!is_array($p) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host'])
                        || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])
                        || (isset($p['path']) && $p['path'] !== '') || strlen($url) > 200) {
                        $errors[] = 'Recording base URL must be an https:// origin such as https://pbx.example.com (no path).';
                        $url = '';
                    }
                }
                $settings[$k] = $url;
            } else {
                $settings[$k] = self::bool($in[$k] ?? $default);
            }
        }
        $out['settings'] = $settings;
        return [$out, $errors];
    }

    public function saveConfig(array $clean, string $userUuid): bool
    {
        $row = $this->configRow(true);
        $tokenEnc = $row['access_token_enc'] ?? null;
        $last4 = $row['token_last4'] ?? null;
        $tokenChanged = false;
        if ($clean['access_token'] !== '') {
            $tokenEnc = HubspotCrypto::encrypt($clean['access_token']);
            $last4 = substr($clean['access_token'], -4);
            $tokenChanged = true;
        }
        if (empty($tokenEnc)) {
            throw new HubspotException('A HubSpot private app access token is required.', 'validation');
        }
        $binds = [
            'd' => $this->domainUuid,
            'enabled' => $clean['enabled'] ? 'true' : 'false',
            'tok' => $tokenEnc,
            'l4' => $last4,
            'settings' => json_encode($clean['settings']),
            'by' => $userUuid !== '' ? $userUuid : null,
        ];
        if ($row) {
            $sql = 'UPDATE public.q_hubspot_config SET enabled = CAST(:enabled AS boolean), access_token_enc = :tok, token_last4 = :l4,
                       settings = CAST(:settings AS jsonb), updated_by = :by, updated_at = now()'
                . ($tokenChanged ? ", connection_status = 'not_configured', last_error = NULL, granted_scopes = NULL,
                       call_status_options = NULL, dispositions = NULL, portal_id = NULL, ui_domain = NULL" : '')
                . ' WHERE domain_uuid = CAST(:d AS uuid)';
        } else {
            $binds['salt'] = bin2hex(random_bytes(16));
            $sql = "INSERT INTO public.q_hubspot_config
                      (domain_uuid, enabled, access_token_enc, token_last4, settings, connection_status, link_salt,
                       updated_by, created_at, updated_at)
                    VALUES (CAST(:d AS uuid), CAST(:enabled AS boolean), :tok, :l4, CAST(:settings AS jsonb),
                       'not_configured', :salt, :by, now(), now())";
        }
        $this->db->execute($sql, $binds);
        $this->configCache = null;
        $this->clientCache = null;
        $this->forgetAllContactCache();
        return $tokenChanged;
    }

    private function accessToken(): string
    {
        $row = $this->configRow();
        if (!$row || empty($row['access_token_enc'])) {
            throw new HubspotException('HubSpot is not configured.', 'not_configured');
        }
        return HubspotCrypto::decrypt((string) $row['access_token_enc']);
    }

    public function client(string $tokenOverride = '', bool $interactive = true): HubspotClient
    {
        if ($tokenOverride !== '') {
            return new HubspotClient($tokenOverride, $interactive);
        }
        if ($this->clientCache === null) {
            $this->clientCache = new HubspotClient($this->accessToken(), $interactive);
        }
        return $this->clientCache;
    }

    /**
     * Verifies a token against HubSpot and, when it is the stored token,
     * records the account details and the call metadata this portal accepts.
     *
     * @return array{ok:bool, message:string, reason?:string, details?:array}
     */
    public function testConnection(string $tokenOverride = ''): array
    {
        $usesStored = $tokenOverride === '';
        try {
            $client = $this->client($tokenOverride);
            // 1. Auth + the contacts read scope (fails with 401 / 403 MISSING_SCOPES).
            $client->get('crm/v3/objects/contacts', ['limit' => 1, 'archived' => 'false']);

            // 2. Account details (portal id is needed for "Open in HubSpot" links).
            $account = [];
            try {
                $account = $client->get('account-info/v3/details');
            } catch (HubspotException $e) {
                if ($e->reason === 'auth_failed') {
                    throw $e;
                }
            }

            // 3. Granted scopes (best effort; the endpoint is specific to private app tokens).
            $scopes = null;
            try {
                $info = $client->post('oauth/v2/private-apps/get/access-token-info', ['tokenKey' => $tokenOverride !== '' ? $tokenOverride : $this->accessToken()]);
                if (is_array($info['scopes'] ?? null)) {
                    $scopes = array_values(array_map('strval', $info['scopes']));
                }
            } catch (HubspotException $e) {
                // Not fatal: scopes are then verified by the calls the integration makes.
            }
            $missing = $scopes !== null ? array_values(array_diff(self::REQUIRED_SCOPES, $scopes)) : [];

            // 4. What this portal accepts for call status / outcome (never assume).
            $statusOptions = $this->fetchCallStatusOptions($client);
            $dispositions = $this->fetchDispositions($client);

            $details = [
                'portal_id' => isset($account['portalId']) ? (string) $account['portalId'] : null,
                'ui_domain' => (string) ($account['uiDomain'] ?? ''),
                'account_timezone' => (string) ($account['timeZone'] ?? ''),
                'granted_scopes' => $scopes,
                'missing_scopes' => $missing,
            ];

            if ($missing) {
                $msg = 'Connected to HubSpot, but the private app is missing required scopes: ' . implode(', ', $missing) . '.';
                if ($usesStored) {
                    $this->storeAccountDetails($details, $statusOptions, $dispositions);
                    $this->setStatus('error', $msg);
                }
                return ['ok' => false, 'message' => $msg, 'reason' => 'missing_scopes', 'details' => $details];
            }
            if ($usesStored) {
                $this->storeAccountDetails($details, $statusOptions, $dispositions);
                $this->setStatus('connected', null);
                $this->requeueAuthFailures();
            }
            $portal = $details['portal_id'] ? ' (portal ' . $details['portal_id'] . ')' : '';
            return ['ok' => true, 'message' => 'Connected to HubSpot' . $portal . ' successfully.', 'details' => $details];
        } catch (HubspotException $e) {
            if ($usesStored) {
                $this->setStatus('error', $e->getMessage());
            }
            return ['ok' => false, 'message' => $e->getMessage(), 'reason' => $e->reason];
        }
    }

    /** @return string[]|null values of the hs_call_status enumeration on this portal */
    private function fetchCallStatusOptions(HubspotClient $client): ?array
    {
        try {
            $prop = $client->get('crm/v3/properties/calls/hs_call_status');
            $values = [];
            foreach ((array) ($prop['options'] ?? []) as $o) {
                if (is_array($o) && isset($o['value']) && empty($o['hidden'])) {
                    $values[] = (string) $o['value'];
                }
            }
            return $values ?: null;
        } catch (HubspotException $e) {
            if ($e->reason === 'auth_failed') {
                throw $e;
            }
            return null;
        }
    }

    /** @return array<string,string>|null lower-case label => disposition GUID */
    private function fetchDispositions(HubspotClient $client): ?array
    {
        try {
            $list = $client->get('calling/v1/dispositions');
            $map = [];
            foreach ($list as $d) {
                if (is_array($d) && !empty($d['id']) && !empty($d['label']) && empty($d['deleted'])) {
                    $map[strtolower(trim((string) $d['label']))] = (string) $d['id'];
                }
            }
            return $map ?: null;
        } catch (HubspotException $e) {
            if ($e->reason === 'auth_failed') {
                throw $e;
            }
            return null;
        }
    }

    private function storeAccountDetails(array $details, ?array $statusOptions, ?array $dispositions): void
    {
        $this->db->execute(
            'UPDATE public.q_hubspot_config SET portal_id = CAST(:p AS bigint), ui_domain = :ui, account_timezone = :tz,
                granted_scopes = CAST(:sc AS jsonb), call_status_options = CAST(:so AS jsonb), dispositions = CAST(:dp AS jsonb)
             WHERE domain_uuid = CAST(:d AS uuid)',
            [
                'p' => $details['portal_id'] !== null && ctype_digit((string) $details['portal_id']) ? $details['portal_id'] : null,
                'ui' => preg_match('/^[a-z0-9.-]{3,64}$/i', (string) $details['ui_domain']) ? $details['ui_domain'] : null,
                'tz' => mb_substr((string) $details['account_timezone'], 0, 64) ?: null,
                'sc' => $details['granted_scopes'] !== null ? json_encode($details['granted_scopes']) : null,
                'so' => $statusOptions !== null ? json_encode($statusOptions) : null,
                'dp' => $dispositions !== null ? json_encode($dispositions) : null,
                'd' => $this->domainUuid,
            ]
        );
        $this->configCache = null;
    }

    /**
     * Calls of the last 7 days that were not saved only because the token or its scopes
     * were broken: once the connection works again, save them (retry counter reset).
     */
    private function requeueAuthFailures(): void
    {
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET sync_status = 'pending', retry_count = 0, next_attempt_at = now(),
                last_error = NULL, updated_at = now()
              WHERE domain_uuid = CAST(:d AS uuid) AND sync_status = 'failed' AND ended_at > now() - interval '7 days'
                AND (last_error LIKE 'The HubSpot access token has expired%' OR last_error LIKE 'HubSpot rejected the access token%'
                     OR last_error LIKE 'The HubSpot private app is missing a required scope%')",
            ['d' => $this->domainUuid]
        );
    }

    public function setStatus(string $status, ?string $error): void
    {
        $this->db->execute(
            'UPDATE public.q_hubspot_config SET connection_status = :s, last_error = :e, last_tested_at = now() WHERE domain_uuid = CAST(:d AS uuid)',
            ['s' => $status, 'e' => $error === null ? null : mb_substr($error, 0, 500), 'd' => $this->domainUuid]
        );
        $this->configCache = null;
    }

    /**
     * Deletes the stored token, disables the integration and revokes every
     * recording link already written to HubSpot (new link_salt). Call journals
     * are kept for history; queued syncs are skipped.
     */
    public function disconnect(string $userUuid): void
    {
        $this->db->execute(
            "UPDATE public.q_hubspot_config SET enabled = false, access_token_enc = NULL, token_last4 = NULL,
                connection_status = 'disconnected', last_error = NULL, granted_scopes = NULL,
                call_status_options = NULL, dispositions = NULL, link_salt = :salt, updated_by = :by, updated_at = now()
             WHERE domain_uuid = CAST(:d AS uuid)",
            ['salt' => bin2hex(random_bytes(16)), 'by' => $userUuid !== '' ? $userUuid : null, 'd' => $this->domainUuid]
        );
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET next_attempt_at = NULL,
                sync_status = CASE WHEN sync_status = 'pending' THEN 'skipped' ELSE sync_status END
             WHERE domain_uuid = CAST(:d AS uuid) AND (next_attempt_at IS NOT NULL OR sync_status = 'pending')",
            ['d' => $this->domainUuid]
        );
        // Every HubSpot dialer connected for this tenant must connect again.
        $this->db->execute(
            'UPDATE public.q_hubspot_cti_tokens SET revoked_at = now() WHERE domain_uuid = CAST(:d AS uuid) AND revoked_at IS NULL',
            ['d' => $this->domainUuid]
        );
        $this->configCache = null;
        $this->clientCache = null;
        $this->forgetAllContactCache();
    }

    public function isActive(): bool
    {
        $row = $this->configRow();
        return $row && self::bool($row['enabled']) && !empty($row['access_token_enc']) && $row['connection_status'] !== 'disconnected';
    }

    // ==================================================================
    // Contacts
    // ==================================================================

    public static function digits(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    /**
     * Same subscriber? Compares the trailing digits so national (0812…/91234567)
     * and international (+62812…/+6591234567) forms match. Needs 7+ digits.
     */
    public static function sameNumber(string $a, string $b): bool
    {
        $a = self::digits(self::withoutExtension($a));
        $b = self::digits(self::withoutExtension($b));
        if (strlen($a) < 7 || strlen($b) < 7) {
            return $a !== '' && $a === $b;
        }
        $n = min(9, strlen($a), strlen($b));
        return substr($a, -$n) === substr($b, -$n);
    }

    public function contactUrl(string $contactId): ?string
    {
        $row = $this->configRow();
        if (!$row || empty($row['portal_id']) || !preg_match('/^\d{1,20}$/', $contactId)) {
            return null;
        }
        $ui = (string) ($row['ui_domain'] ?? '');
        if (!preg_match('/^app(-[a-z0-9]+)?\.hubspot\.com$/i', $ui)) {
            $ui = 'app.hubspot.com';
        }
        return 'https://' . $ui . '/contacts/' . $row['portal_id'] . '/record/0-1/' . $contactId;
    }

    public function normalizeContact(array $c): array
    {
        $p = is_array($c['properties'] ?? null) ? $c['properties'] : [];
        $str = function (string $k) use ($p): string {
            return isset($p[$k]) && $p[$k] !== null ? (string) $p[$k] : '';
        };
        $id = (string) ($c['id'] ?? '');
        $companyIds = [];
        foreach ((array) ($c['associations']['companies']['results'] ?? []) as $a) {
            if (is_array($a) && isset($a['id']) && preg_match('/^\d{1,20}$/', (string) $a['id'])) {
                $companyIds[] = (string) $a['id'];
            }
        }
        $full = trim($str('firstname') . ' ' . $str('lastname'));
        return [
            'id' => $id,
            'firstname' => $str('firstname'),
            'lastname' => $str('lastname'),
            'full_name' => $full,
            'email' => $str('email'),
            'phone' => $str('phone'),
            'mobilephone' => $str('mobilephone'),
            'company' => $str('company'),
            'company_id' => $companyIds[0] ?? null,
            'company_name' => null,
            'jobtitle' => $str('jobtitle'),
            'lifecyclestage' => $str('lifecyclestage'),
            'lead_status' => $str('hs_lead_status'),
            'owner_id' => $str('hubspot_owner_id'),
            'city' => $str('city'),
            'country' => $str('country'),
            'created_at' => self::iso($str('createdate')),
            'updated_at' => self::iso($str('lastmodifieddate')),
            'url' => $this->contactUrl($id),
        ];
    }

    private function cacheGet(string $key): ?array
    {
        try {
            $v = $this->redis->get($key);
            if ($v) {
                $d = json_decode((string) $v, true);
                return is_array($d) ? $d : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    private function cacheSet(string $key, array $value, int $ttl = self::CONTACT_CACHE_TTL): void
    {
        try {
            $this->redis->setex($key, $ttl, json_encode($value));
        } catch (\Throwable $e) {
        }
    }

    private function cacheGeneration(): string
    {
        try {
            $g = (string) $this->redis->get('hubspot:gen:' . $this->domainUuid);
            return $g !== '' ? $g : '0';
        } catch (\Throwable $e) {
            return '0';
        }
    }

    /** Invalidates every cached lookup for this tenant (token change, disconnect). */
    private function forgetAllContactCache(): void
    {
        try {
            $this->redis->incr('hubspot:gen:' . $this->domainUuid);
        } catch (\Throwable $e) {
        }
    }

    private function phoneCacheKey(string $digits): string
    {
        return 'hubspot:phone:' . $this->domainUuid . ':' . $this->cacheGeneration() . ':' . $digits;
    }

    /**
     * Contacts whose phone or mobile number is the given number. Uses HubSpot's
     * calculated searchable number properties (digits only) with a trailing-
     * digits wildcard, plus exact matches on phone / mobilephone, then keeps
     * only real matches (the search is token-based and may over-match).
     *
     * @return array[] normalized contacts
     */
    /**
     * Search request for a caller number. HubSpot keeps every contact phone in
     * calculated, digits-only forms: hs_searchable_calculated_phone_number holds the
     * NATIONAL number (e.g. 7904487404) and ..._international_phone_number the full
     * number with country code (917904487404). Those only match exactly (a leading
     * wildcard finds nothing -- verified against a live portal), so the caller number
     * is tried in each form it can take: as received, without leading zeros, and its
     * last 8-11 digits (the national number behind any country code). Results are
     * checked with sameNumber() afterwards, so a looser candidate never mis-links.
     */
    public static function phoneSearchBody(string $phone, array $properties): array
    {
        $d = self::digits(self::withoutExtension($phone));
        $cands = [$d, ltrim($d, '0')];
        foreach ([8, 9, 10, 11] as $n) {
            if (strlen($d) > $n) {
                $cands[] = substr($d, -$n);
            }
        }
        $cands = array_values(array_unique(array_filter($cands, function ($c) { return strlen($c) >= 6; })));
        $intl = array_values(array_unique(array_filter([$d, ltrim($d, '0')], function ($c) { return strlen($c) >= 8; })));
        $groups = [
            ['filters' => [['propertyName' => 'hs_searchable_calculated_phone_number', 'operator' => 'IN', 'values' => $cands]]],
            ['filters' => [['propertyName' => 'hs_searchable_calculated_mobile_number', 'operator' => 'IN', 'values' => $cands]]],
        ];
        if ($intl) {
            $groups[] = ['filters' => [['propertyName' => 'hs_searchable_calculated_international_phone_number', 'operator' => 'IN', 'values' => $intl]]];
            $groups[] = ['filters' => [['propertyName' => 'hs_searchable_calculated_international_mobile_number', 'operator' => 'IN', 'values' => $intl]]];
        }
        // Raw property text as a last resort (numbers HubSpot could not parse).
        $groups[] = ['filters' => [['propertyName' => 'phone', 'operator' => 'CONTAINS_TOKEN', 'value' => '*' . substr($d, -min(7, strlen($d)))]]];
        return [
            'filterGroups' => array_slice($groups, 0, 5), // HubSpot allows at most 5 filter groups
            'properties' => $properties,
            'sorts' => [['propertyName' => 'lastmodifieddate', 'direction' => 'DESCENDING']],
            'limit' => 20,
        ];
    }

    public function searchByPhone(string $phone, bool $useCache = true): array
    {
        $digits = self::digits($phone);
        if (strlen($digits) < 6) {
            return []; // internal extensions / short codes are never HubSpot contacts
        }
        $key = $this->phoneCacheKey($digits);
        if ($useCache && ($cached = $this->cacheGet($key)) !== null) {
            return $cached;
        }
        $res = $this->client()->post('crm/v3/objects/contacts/search', self::phoneSearchBody($phone, self::CONTACT_PROPERTIES));
        $found = [];
        foreach ((array) ($res['results'] ?? []) as $c) {
            if (!is_array($c) || empty($c['id'])) {
                continue;
            }
            $n = $this->normalizeContact($c);
            if (self::sameNumber($n['phone'], $phone) || self::sameNumber($n['mobilephone'], $phone)) {
                $found[$n['id']] = $n;
            }
        }
        $result = array_values($found);
        $this->cacheSet($key, $result);
        return $result;
    }

    /** Free-text contact search (name, email, phone, company) for click-to-call. */
    public function searchByQuery(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            throw new HubspotException('Enter 2 to 100 characters to search.', 'validation');
        }
        $digits = self::digits($q);
        if (strlen($digits) >= 6 && strlen($digits) >= strlen(preg_replace('/[\s()+.-]/', '', $q)) - 1) {
            return $this->searchByPhone($q);
        }
        $res = $this->client()->post('crm/v3/objects/contacts/search', [
            'query' => $q,
            'properties' => self::CONTACT_PROPERTIES,
            'sorts' => [['propertyName' => 'lastmodifieddate', 'direction' => 'DESCENDING']],
            'limit' => 20,
        ]);
        $out = [];
        foreach ((array) ($res['results'] ?? []) as $c) {
            if (is_array($c) && !empty($c['id'])) {
                $out[] = $this->normalizeContact($c);
            }
        }
        return $out;
    }

    /** One contact with its primary company name. Null when it does not exist. */
    public function contactById(string $id, bool $useCache = true): ?array
    {
        if (!preg_match('/^\d{1,20}$/', $id)) {
            throw new HubspotException('HubSpot contact id is invalid.', 'validation');
        }
        $key = 'hubspot:contact:' . $this->domainUuid . ':' . $this->cacheGeneration() . ':' . $id;
        if ($useCache && ($cached = $this->cacheGet($key)) !== null) {
            return $cached;
        }
        try {
            $raw = $this->client()->get('crm/v3/objects/contacts/' . $id, [
                'properties' => implode(',', self::CONTACT_PROPERTIES),
                'associations' => 'companies',
                'archived' => 'false',
            ]);
        } catch (HubspotException $e) {
            if ($e->reason === 'not_found') {
                return null;
            }
            throw $e;
        }
        $c = $this->normalizeContact($raw);
        if (!empty($c['company_id'])) {
            try {
                $co = $this->client()->get('crm/v3/objects/companies/' . $c['company_id'], ['properties' => 'name,domain']);
                $c['company_name'] = (string) ($co['properties']['name'] ?? '') ?: null;
            } catch (HubspotException $e) {
                // Missing crm.objects.companies.read or company deleted: keep the id only.
                if ($e->reason === 'auth_failed') {
                    throw $e;
                }
            }
        }
        $this->cacheSet($key, $c);
        return $c;
    }

    /**
     * Drops a trailing extension ("+91-79044-87404, ext. 1001", "+1 555 0100 x23",
     * "... #1001"): HubSpot keeps it in the phone property, but the caller ID never has it.
     */
    public static function withoutExtension(string $phone): string
    {
        return (string) preg_replace('/\s*[,;]?\s*(?:ext\.?|extension|x|#)\s*\d+\s*$/i', '', $phone);
    }

    /** A customer phone number (7+ digits), not an internal extension or short code. */
    public static function isExternalNumber(string $phone): bool
    {
        return strlen(self::digits($phone)) >= 7;
    }

    /** Contact fields for an automatically created caller: "Caller +6591234567". */
    public static function newCallerContactInput(string $phone): array
    {
        $clean = (string) preg_replace('/[^0-9+]/', '', $phone);
        return ['firstname' => 'Caller', 'lastname' => $clean, 'phone' => $clean];
    }

    public function createContact(array $in): array
    {
        $first = trim(strip_tags((string) ($in['firstname'] ?? '')));
        $last = trim(strip_tags((string) ($in['lastname'] ?? '')));
        $email = trim((string) ($in['email'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $company = trim(strip_tags((string) ($in['company'] ?? '')));
        if ($first === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100 || mb_strlen($company) > 150) {
            throw new HubspotException('First name is required (max 100 characters).', 'validation');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) {
            throw new HubspotException('Email address is invalid.', 'validation');
        }
        if (strlen(self::digits($phone)) < 6 || strlen($phone) > 32 || !preg_match('/^[0-9+()\s.-]+$/', $phone)) {
            throw new HubspotException('Phone number is invalid.', 'validation');
        }
        // Duplicate guard: never create a second contact for a number or email HubSpot already has.
        $existing = $this->searchByPhone($phone, false);
        if ($existing) {
            return ['contact' => $existing[0], 'created' => false];
        }
        if ($email !== '') {
            $byEmail = $this->client()->post('crm/v3/objects/contacts/search', [
                'filterGroups' => [['filters' => [['propertyName' => 'email', 'operator' => 'EQ', 'value' => $email]]]],
                'properties' => self::CONTACT_PROPERTIES,
                'limit' => 1,
            ]);
            if (!empty($byEmail['results'][0]['id'])) {
                return ['contact' => $this->normalizeContact($byEmail['results'][0]), 'created' => false];
            }
        }
        $lockKey = 'hubspot:create-lock:' . $this->domainUuid . ':' . self::digits($phone);
        try {
            if (!$this->redis->set($lockKey, '1', 'EX', 30, 'NX')) {
                throw new HubspotException('This contact is already being created. Try again in a moment.', 'conflict');
            }
        } catch (HubspotException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Redis unavailable: the searches above still guard against duplicates.
        }
        $props = array_filter([
            'firstname' => $first, 'lastname' => $last, 'email' => $email, 'phone' => $phone, 'company' => $company,
        ], function ($v) { return $v !== ''; });
        try {
            $created = $this->client()->post('crm/v3/objects/contacts', ['properties' => $props]);
        } catch (HubspotException $e) {
            if ($e->reason === 'conflict') {
                throw new HubspotException('A HubSpot contact with this email already exists.', 'conflict', 409);
            }
            throw $e;
        }
        $this->forgetAllContactCache();
        return ['contact' => $this->normalizeContact($created), 'created' => true];
    }

    // ==================================================================
    // Call journals
    // ==================================================================

    /** Records a dialer lifecycle event. Idempotent per (tenant, local_call_id). */
    public function recordCallEvent(array $in, array $session): array
    {
        $localId = (string) ($in['local_call_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $localId)) {
            throw new HubspotException('local_call_id is invalid.', 'validation');
        }
        $event = (string) ($in['event'] ?? '');
        if (!in_array($event, ['started', 'answered', 'ended'], true)) {
            throw new HubspotException('event must be started, answered or ended.', 'validation');
        }
        $dirIn = (string) ($in['direction'] ?? '');
        $direction = $dirIn === 'outbound' || $dirIn === 'inbound' ? $dirIn : '';
        if ($direction === '') {
            throw new HubspotException('direction must be inbound or outbound.', 'validation');
        }
        $remote = mb_substr((string) preg_replace('/[^0-9+*#]/', '', (string) ($in['remote_number'] ?? '')), 0, 32);
        $status = (string) ($in['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new HubspotException('status is invalid.', 'validation');
        }
        $origin = in_array(($in['origin'] ?? ''), ['click_to_call', 'dialer', 'hubspot_cti'], true) ? (string) $in['origin'] : 'dialer';
        // HubSpot dialer only: the call record HubSpot itself created (engagement id).
        $hsCallId = $origin === 'hubspot_cti' && isset($in['hubspot_call_id']) && preg_match('/^\d{1,20}$/', (string) $in['hubspot_call_id'])
            ? (string) $in['hubspot_call_id'] : null;
        $ext = mb_substr((string) preg_replace('/[^0-9A-Za-z*#+_-]/', '', (string) ($session['extension'] ?? '')), 0, 32);
        $agentName = mb_substr(trim(((string) ($session['firstname'] ?? '')) . ' ' . ((string) ($session['lastname'] ?? ''))), 0, 120);
        $contactId = isset($in['hubspot_contact_id']) && preg_match('/^\d{1,20}$/', (string) $in['hubspot_contact_id'])
            ? (string) $in['hubspot_contact_id'] : null;

        $startedAt = self::clampTime($in['started_at'] ?? null);
        $answeredAt = self::clampTime($in['answered_at'] ?? null);
        $endedAt = self::clampTime($in['ended_at'] ?? null);
        $talk = max(0, min(86400, (int) ($in['talk_duration'] ?? 0)));
        $userUuid = (string) ($session['user_uuid'] ?? '');

        $existing = $this->journalByLocalId($localId);
        if ($existing && !empty($existing['user_uuid']) && $existing['user_uuid'] !== $userUuid) {
            throw new HubspotException('This call belongs to another user.', 'forbidden');
        }

        if (!$existing) {
            $uuid = (new \Phalcon\Security\Random())->uuid();
            // ON CONFLICT makes a retried/duplicated 'started' (or a late first event) a no-op.
            $this->db->execute(
                "INSERT INTO public.q_hubspot_call_journals
                   (journal_uuid, domain_uuid, local_call_id, user_uuid, agent_extension, agent_name, call_direction, call_origin,
                    caller_number, destination_number, call_status, started_at, hubspot_contact_id, sync_status,
                    recording_status, ai_status, retry_count, created_at, updated_at)
                 VALUES (CAST(:u AS uuid), CAST(:d AS uuid), :lid, :usr, :ext, :aname, :dir, :origin,
                    :caller, :dest, 'ringing', COALESCE(CAST(:st AS timestamptz), now()), :cid, 'pending',
                    'none', NULL, 0, now(), now())
                 ON CONFLICT (domain_uuid, local_call_id) DO NOTHING",
                [
                    'u' => $uuid, 'd' => $this->domainUuid, 'lid' => $localId,
                    'usr' => $userUuid !== '' ? $userUuid : null, 'ext' => $ext !== '' ? $ext : null,
                    'aname' => $agentName !== '' ? $agentName : null, 'dir' => $direction, 'origin' => $origin,
                    'caller' => $direction === 'inbound' ? $remote : $ext,
                    'dest' => $direction === 'inbound' ? $ext : $remote,
                    'st' => $startedAt, 'cid' => $contactId,
                ]
            );
            $existing = $this->journalByLocalId($localId);
        }
        if (!$existing) {
            throw new HubspotException('Could not record the call.', 'unavailable');
        }

        // HubSpot created the call record (HubSpot dialer): remember it, so RingQ only
        // updates that record and never creates a second one. HubSpot associated it with
        // the record the call was started from. May arrive after 'ended', so before the
        // final-status return below.
        if ($hsCallId !== null && empty($existing['hubspot_call_id'])) {
            $this->db->execute(
                'UPDATE public.q_hubspot_call_journals SET hubspot_call_id = :c, hubspot_owns_call = true,
                    associated_contact_id = COALESCE(:cid, hubspot_contact_id), updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid) AND hubspot_call_id IS NULL',
                ['c' => $hsCallId, 'cid' => $contactId, 'u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
            $existing = $this->journalByLocalId($localId) ?: $existing;
        }

        // A final status is final: later out-of-order or repeated events change nothing.
        if (in_array($existing['call_status'], self::FINAL_STATUSES, true)) {
            return $existing;
        }

        if ($event === 'answered') {
            $this->db->execute(
                "UPDATE public.q_hubspot_call_journals SET call_status = 'answered',
                    answered_at = COALESCE(answered_at, CAST(:a AS timestamptz), now()),
                    hubspot_contact_id = COALESCE(:cid, hubspot_contact_id), updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                ['a' => $answeredAt, 'cid' => $contactId, 'u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
        } elseif ($event === 'started' && $contactId !== null) {
            $this->db->execute(
                'UPDATE public.q_hubspot_call_journals SET hubspot_contact_id = COALESCE(hubspot_contact_id, :cid), updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                ['cid' => $contactId, 'u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
        } elseif ($event === 'ended') {
            $final = in_array($status, self::FINAL_STATUSES, true) ? $status : 'failed';
            $wasAnswered = $existing['answered_at'] !== null || $answeredAt !== null;
            // A call that never reached 'answered' is never stored as completed.
            if ($final === 'completed' && !$wasAnswered) {
                $final = $direction === 'inbound' ? 'missed' : 'no_answer';
            }
            $s = self::settingsOf($this->configRow());
            // HubSpot dialer: the agent answered this ringing call on another device (the
            // RingQ app or a desk phone). That device logs it; this copy is not logged.
            $elsewhere = $origin === 'hubspot_cti' && !$wasAnswered && self::bool($in['answered_elsewhere'] ?? false);
            $journaling = !$elsewhere && $s['call_journaling'] && $this->isActive() && ($final === 'completed' || $s['log_unanswered']);
            $wantRecording = $final === 'completed';
            $this->db->execute(
                "UPDATE public.q_hubspot_call_journals SET call_status = :s,
                    answered_at = CASE WHEN :ans1 = 'y' THEN COALESCE(answered_at, CAST(:a AS timestamptz)) ELSE NULL END,
                    ended_at = COALESCE(CAST(:e AS timestamptz), now()),
                    talk_duration = :talk,
                    hubspot_contact_id = COALESCE(:cid, hubspot_contact_id),
                    recording_status = CASE WHEN :rec = 'y' THEN 'waiting' ELSE 'none' END,
                    sync_status = :sync, next_attempt_at = CASE WHEN :sync2 = 'pending' THEN now() ELSE NULL END,
                    updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                [
                    's' => $final, 'ans1' => $wasAnswered ? 'y' : 'n', 'a' => $answeredAt, 'e' => $endedAt,
                    'talk' => $final === 'completed' ? $talk : 0, 'cid' => $contactId,
                    'rec' => $wantRecording ? 'y' : 'n',
                    'sync' => $journaling ? 'pending' : 'skipped', 'sync2' => $journaling ? 'pending' : 'skipped',
                    'u' => $existing['journal_uuid'], 'd' => $this->domainUuid,
                ]
            );
            $this->db->execute(
                'UPDATE public.q_hubspot_call_journals SET duration = GREATEST(0, CAST(EXTRACT(EPOCH FROM (ended_at - started_at)) AS integer))
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                ['u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
            if ($elsewhere) {
                $this->updateJournal((string) $existing['journal_uuid'], ['last_error' => 'Answered on another RingQ device; logged from there.']);
            }
        }
        return $this->journalByLocalId($localId) ?: $existing;
    }

    public function journalByLocalId(string $localId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $localId)) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT * FROM public.q_hubspot_call_journals WHERE domain_uuid = CAST(:d AS uuid) AND local_call_id = :lid',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'lid' => $localId]
        );
        return $row ?: null;
    }

    private function journalByUuid(string $journalUuid): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM public.q_hubspot_call_journals WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid, 'd' => $this->domainUuid]
        );
        return $row ?: null;
    }

    public function listJournals(int $page, int $perPage, string $phone, ?string $contactId, ?string $onlyUserUuid): array
    {
        $where = 'domain_uuid = CAST(:d AS uuid)';
        $binds = ['d' => $this->domainUuid];
        if ($onlyUserUuid !== null) {
            $where .= ' AND user_uuid = :usr';
            $binds['usr'] = $onlyUserUuid;
        }
        if ($contactId !== null && preg_match('/^\d{1,20}$/', $contactId)) {
            $where .= ' AND hubspot_contact_id = :cid';
            $binds['cid'] = $contactId;
        }
        $digits = self::digits($phone);
        if (strlen($digits) >= 4) {
            $where .= " AND (regexp_replace(COALESCE(caller_number, ''), '\\D', '', 'g') LIKE :p1
                         OR regexp_replace(COALESCE(destination_number, ''), '\\D', '', 'g') LIKE :p2)";
            $binds['p1'] = '%' . substr($digits, -9);
            $binds['p2'] = '%' . substr($digits, -9);
        }
        $total = (int) ($this->db->fetchOne("SELECT count(*) AS n FROM public.q_hubspot_call_journals WHERE $where", Enum::FETCH_ASSOC, $binds)['n'] ?? 0);
        $binds['lim'] = $perPage;
        $binds['off'] = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT * FROM public.q_hubspot_call_journals WHERE $where ORDER BY started_at DESC
              LIMIT CAST(:lim AS integer) OFFSET CAST(:off AS integer)",
            Enum::FETCH_ASSOC,
            $binds
        );
        return ['journals' => array_map([$this, 'publicJournal'], $rows ?: []), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function publicJournal(array $j): array
    {
        $s = self::settingsOf($this->configRow());
        $aiNote = null;
        if (($s['ai_summary'] || $s['ai_sentiment']) && $this->summaryProvider() instanceof NullHubspotSummaryProvider) {
            $aiNote = 'Summary and sentiment need an AI analysis provider, which is not configured on this server.';
        }
        return [
            'local_call_id' => $j['local_call_id'],
            'call_ref' => self::callRef((string) $j['local_call_id']),
            'hubspot_contact_id' => $j['hubspot_contact_id'],
            'hubspot_call_id' => $j['hubspot_call_id'],
            'hubspot_contact_url' => !empty($j['hubspot_contact_id']) ? $this->contactUrl((string) $j['hubspot_contact_id']) : null,
            'call_origin' => $j['call_origin'],
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
            'notes' => $j['notes'],
            'recording_status' => $j['recording_status'],
            'recording_available' => $j['recording_status'] === 'linked',
            // RingQ call id (xml_cdr_uuid): the popup transcribes it with RingQ's own /reports/audiototext.
            'call_uuid' => $j['recording_status'] === 'linked' ? ($j['xml_cdr_uuid'] ?? null) : null,
            'transcription' => $j['transcription'],
            'summary' => $j['summary'],
            'sentiment' => $j['sentiment'],
            'ai_status' => $j['ai_status'],
            'ai_error' => $j['ai_status'] === 'failed' ? $j['ai_error'] : null,
            'ai_note' => $aiNote,
            'sync_status' => $j['sync_status'],
            'retry_count' => (int) $j['retry_count'],
            'last_error' => $j['last_error'],
        ];
    }

    /** Agent notes for a call (written into the HubSpot call body). */
    public function saveNotes(array $j, string $notes): array
    {
        $notes = trim(strip_tags($notes));
        if (mb_strlen($notes) > 5000) {
            throw new HubspotException('Notes can be at most 5000 characters.', 'validation');
        }
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET notes = :n, updated_at = now(),
                next_attempt_at = CASE WHEN sync_status IN ('synced', 'pending') AND ended_at IS NOT NULL THEN now() ELSE next_attempt_at END
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['n' => $notes !== '' ? $notes : null, 'u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        if (!empty($j['ended_at']) && $this->isActive() && $j['sync_status'] !== 'skipped') {
            $this->syncJournal((string) $j['journal_uuid']);
        }
        return $this->journalByLocalId((string) $j['local_call_id']) ?: $j;
    }

    /** Links (or re-links) a call to a HubSpot contact after the agent picked one. */
    public function setContact(array $j, string $contactId): array
    {
        if (!preg_match('/^\d{1,20}$/', $contactId)) {
            throw new HubspotException('HubSpot contact id is invalid.', 'validation');
        }
        if ($this->contactById($contactId) === null) {
            throw new HubspotException('That HubSpot contact does not exist (it may have been deleted or merged).', 'not_found');
        }
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET hubspot_contact_id = :c, updated_at = now(),
                next_attempt_at = CASE WHEN ended_at IS NOT NULL AND sync_status <> 'skipped' THEN now() ELSE next_attempt_at END
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['c' => $contactId, 'u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        if (!empty($j['ended_at']) && $this->isActive() && $j['sync_status'] !== 'skipped') {
            $this->syncJournal((string) $j['journal_uuid']);
        }
        return $this->journalByLocalId((string) $j['local_call_id']) ?: $j;
    }

    /** Manual retry from the UI: clears the backoff and syncs now. */
    public function retryJournal(array $j): array
    {
        if (empty($j['ended_at'])) {
            throw new HubspotException('The call has not ended yet.', 'validation');
        }
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET sync_status = CASE WHEN sync_status = 'synced' THEN 'synced' ELSE 'pending' END,
                retry_count = 0, next_attempt_at = now(), sync_locked_at = NULL,
                recording_status = CASE WHEN recording_status = 'unavailable' AND call_status = 'completed' THEN 'waiting' ELSE recording_status END
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        $this->syncJournal((string) $j['journal_uuid']);
        return $this->journalByLocalId((string) $j['local_call_id']) ?: $j;
    }

    /**
     * Pushes one ended call to HubSpot as a Call activity and advances its
     * recording / AI state. Safe to run concurrently and repeatedly:
     *   - a row lock (sync_locked_at) stops two workers working on one call;
     *   - create_attempted_at is persisted BEFORE the create request, and the
     *     returned id immediately after, so a crash between the two is
     *     recovered by searching HubSpot for the call reference instead of
     *     creating a second call;
     *   - once hubspot_call_id is stored, later runs only PATCH that call.
     */
    public function syncJournal(string $journalUuid): void
    {
        $j = $this->db->fetchOne(
            "UPDATE public.q_hubspot_call_journals SET sync_locked_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)
               AND ended_at IS NOT NULL AND sync_status <> 'skipped'
               AND (sync_locked_at IS NULL OR sync_locked_at < now() - interval '3 minutes')
             RETURNING *",
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid, 'd' => $this->domainUuid]
        );
        if (!$j) {
            return; // not ended, skipped, or another worker holds it
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

            // 1. Recording (answered calls only), when wanted.
            if ($j['recording_status'] === 'waiting') {
                if (!$settings['include_recording'] && !$this->aiWanted($settings)) {
                    $update['recording_status'] = 'none';
                    $j['recording_status'] = 'none';
                } elseif ($cdr = $this->findCdr($j)) {
                    $update['xml_cdr_uuid'] = $cdr['xml_cdr_uuid'];
                    $update['recording_status'] = 'linked';
                    $j['xml_cdr_uuid'] = $cdr['xml_cdr_uuid'];
                    $j['recording_status'] = 'linked';
                } elseif (time() - (int) strtotime((string) $j['ended_at']) > self::RECORDING_WAIT_SECONDS) {
                    $update['recording_status'] = 'unavailable';
                    $j['recording_status'] = 'unavailable';
                }
            }

            // 2. AI (optional; runs only after the call itself is saved, never blocks it).
            $ai = $this->advanceAi($j, $settings);
            $update = array_merge($update, $ai);
            $j = array_merge($j, $ai);

            if (empty($j['hubspot_call_id']) && $this->liveUpdateRunning($j)) {
                // The live record of this call is being created right now: update it once it
                // exists rather than create a second call.
                $update['next_attempt_at'] = date('c', time() + 5);
                $this->updateJournal($journalUuid, $update);
                return;
            }

            $client = $this->client('', false);
            $owned = self::bool($j['hubspot_owns_call'] ?? false) && !empty($j['hubspot_call_id']);
            if (!$owned && ($j['call_origin'] ?? '') === 'hubspot_cti' && $j['call_direction'] === 'outbound' && empty($j['hubspot_call_id'])
                && time() - (int) strtotime((string) $j['ended_at']) < self::CTI_LINK_WAIT_SECONDS) {
                // HubSpot dialer: HubSpot is creating this call's record. Wait for its id rather
                // than create a second call; after the wait, RingQ creates the call itself.
                $update['next_attempt_at'] = date('c', time() + 15);
                $this->updateJournal($journalUuid, $update);
                return;
            }

            if ($owned) {
                // HubSpot created and associated this call, and the agent logs notes and the outcome
                // in HubSpot's own form: only add what RingQ knows, never the title, notes or outcome.
                $callId = (string) $j['hubspot_call_id'];
                $client->patch('crm/v3/objects/calls/' . rawurlencode($callId), ['properties' => $this->ownedCallProperties($j, $settings)]);
                $contact = null;
            } else {
                // One incoming call can ring on two of the agent's RingQ devices (the RingQ app
                // and the HubSpot dialer); each sends its own events. Log it only once.
                if ($j['call_direction'] === 'inbound' && empty($j['hubspot_call_id'])) {
                    $decision = $this->inboundDuplicateDecision($j);
                    if ($decision === 'wait') {
                        $update['next_attempt_at'] = date('c', time() + 15);
                        $this->updateJournal($journalUuid, $update);
                        return;
                    }
                    if ($decision === 'skip') {
                        $update['sync_status'] = 'skipped';
                        $update['next_attempt_at'] = null;
                        $update['last_error'] = 'Same call is logged from your other RingQ device.';
                        $this->updateJournal($journalUuid, $update);
                        return;
                    }
                }

                // 3. Contact: the agent's pick, else a single unambiguous phone match.
                [$contact, $note] = $this->resolveContact($j, $update, $settings);

                // 4. Call activity: create once, then update. An incoming call may already have
                // a record created while it rang on the agent's other device: update that one.
                $properties = $this->callProperties($j, $contact, $note, $settings);
                $callId = (string) ($j['hubspot_call_id'] ?? '');
                if ($callId === '' && $j['call_direction'] === 'inbound' && ($taken = $this->takeOverSiblingCall($j))) {
                    [$callId, $j['associated_contact_id']] = $taken;
                }
                if ($callId === '' && !empty($j['create_attempted_at'])) {
                    $callId = $this->findCallByRef($client, self::callRef((string) $j['local_call_id'])) ?? '';
                    if ($callId !== '') {
                        $this->updateJournal($journalUuid, ['hubspot_call_id' => $callId]);
                        error_log('[hubspot] recovered call ' . $callId . ' for ' . self::callRef((string) $j['local_call_id']));
                    }
                }
                if ($callId !== '') {
                    $client->patch('crm/v3/objects/calls/' . rawurlencode($callId), ['properties' => $properties]);
                } else {
                    $body = ['properties' => $properties];
                    $assoc = [];
                    if ($contact) {
                        $assoc[] = ['to' => ['id' => $contact['id']], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::ASSOC_CALL_TO_CONTACT]]];
                        if ($settings['associate_company'] && !empty($contact['company_id'])) {
                            $assoc[] = ['to' => ['id' => $contact['company_id']], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::ASSOC_CALL_TO_COMPANY]]];
                        }
                    }
                    if ($assoc) {
                        $body['associations'] = $assoc;
                    }
                    $this->updateJournal($journalUuid, ['create_attempted_at' => date('c')]);
                    $created = $client->post('crm/v3/objects/calls', $body);
                    $callId = (string) ($created['id'] ?? '');
                    if ($callId === '') {
                        throw new HubspotException('HubSpot did not return an id for the new call.', 'unavailable');
                    }
                    // Persist at once so nothing below can lead to a second call.
                    $this->updateJournal($journalUuid, [
                        'hubspot_call_id' => $callId,
                        'associated_contact_id' => $contact ? $contact['id'] : null,
                        'hubspot_contact_id' => $j['hubspot_contact_id'] ?: null,
                    ]);
                    $j['associated_contact_id'] = $contact ? $contact['id'] : null;
                }
            }
            $update['hubspot_call_id'] = $callId;

            // 5. Contact chosen (or changed) after the call was created: add the association.
            if ($contact && (string) ($j['associated_contact_id'] ?? '') !== $contact['id']) {
                $client->put('crm/v4/objects/calls/' . rawurlencode($callId) . '/associations/default/contacts/' . rawurlencode($contact['id']));
                $update['associated_contact_id'] = $contact['id'];
            }

            $update['sync_status'] = 'synced';
            $update['last_error'] = null;
            $update['synced_at'] = date('c');
            $this->db->execute('UPDATE public.q_hubspot_config SET last_sync_at = now() WHERE domain_uuid = CAST(:d AS uuid)', ['d' => $this->domainUuid]);

            $stillWaiting = $j['recording_status'] === 'waiting' || $j['ai_status'] === 'pending';
            $update['next_attempt_at'] = $stillWaiting ? date('c', time() + 10) : null;
        } catch (HubspotException $e) {
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
            if (in_array($e->reason, ['auth_failed', 'missing_scopes', 'decrypt_failed', 'key_missing'], true)) {
                $this->setStatus('error', $e->getMessage());
            }
        } catch (\Throwable $e) {
            error_log('[hubspot] sync error ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300));
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
        $casts = [
            'sync_locked_at' => 'timestamptz', 'next_attempt_at' => 'timestamptz', 'synced_at' => 'timestamptz',
            'create_attempted_at' => 'timestamptz', 'xml_cdr_uuid' => 'uuid', 'retry_count' => 'integer',
            'sync_status' => null, 'recording_status' => null, 'ai_status' => null, 'ai_error' => null,
            'transcription' => null, 'summary' => null, 'sentiment' => null, 'hubspot_contact_id' => null,
            'hubspot_call_id' => null, 'associated_contact_id' => null, 'last_error' => null,
        ];
        $sets = [];
        $binds = ['u' => $journalUuid, 'd' => $this->domainUuid];
        foreach ($fields as $k => $v) {
            if (!array_key_exists($k, $casts)) {
                continue;
            }
            $sets[] = $casts[$k] ? "$k = CAST(:f_$k AS {$casts[$k]})" : "$k = :f_$k";
            $binds['f_' . $k] = $v;
        }
        if (!$sets) {
            return;
        }
        $this->db->execute(
            'UPDATE public.q_hubspot_call_journals SET ' . implode(', ', $sets) . ', updated_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
            $binds
        );
    }

    /** Short unique reference put in the call title, e.g. "RQ1a2b3c4d" (searchable in HubSpot). */
    public static function callRef(string $localCallId): string
    {
        return 'RQ' . substr(strtolower((string) preg_replace('/[^0-9a-f]/i', '', $localCallId)), 0, 10);
    }

    /** Finds a call this integration already created (duplicate recovery). */
    private function findCallByRef(HubspotClient $client, string $ref): ?string
    {
        $res = $client->post('crm/v3/objects/calls/search', [
            'filterGroups' => [['filters' => [['propertyName' => 'hs_call_title', 'operator' => 'CONTAINS_TOKEN', 'value' => $ref]]]],
            'properties' => ['hs_call_title'],
            'limit' => 5,
        ]);
        foreach ((array) ($res['results'] ?? []) as $r) {
            if (is_array($r) && !empty($r['id']) && strpos((string) ($r['properties']['hs_call_title'] ?? ''), $ref) !== false) {
                return (string) $r['id'];
            }
        }
        return null;
    }

    /**
     * The call's HubSpot contact: the agent's pick, else a single unambiguous phone match,
     * else (when "Auto-create contacts" is on, real numbers only) a new contact.
     * Updates $j and $update; returns [contact|null, note for the call body|null].
     */
    private function resolveContact(array &$j, array &$update, array $settings): array
    {
        $note = null;
        if (empty($j['hubspot_contact_id'])) {
            $remote = $j['call_direction'] === 'inbound' ? $j['caller_number'] : $j['destination_number'];
            $matches = $this->searchByPhone((string) $remote);
            if (count($matches) === 1) {
                $update['hubspot_contact_id'] = $matches[0]['id'];
                $j['hubspot_contact_id'] = $matches[0]['id'];
            } elseif (count($matches) > 1) {
                $note = count($matches) . ' HubSpot contacts share this number and none was picked, so the call is not linked to a contact.';
            } elseif (!self::isExternalNumber((string) $remote) && ($ext = $this->contactForExtension((string) $remote, (bool) $settings['auto_create_contacts']))) {
                // Internal extension: the colleague's HubSpot contact (by email), created from
                // the extension's name and email when "Auto-create contacts" is on.
                $update['hubspot_contact_id'] = $ext['id'];
                $j['hubspot_contact_id'] = $ext['id'];
            } elseif ($settings['auto_create_contacts'] && self::isExternalNumber((string) $remote)) {
                // New caller: create the contact so the call is saved on it.
                try {
                    $created = $this->createContact(self::newCallerContactInput((string) $remote));
                    $update['hubspot_contact_id'] = $created['contact']['id'];
                    $j['hubspot_contact_id'] = $created['contact']['id'];
                } catch (HubspotException $e) {
                    if (!in_array($e->reason, ['validation', 'conflict', 'missing_scopes', 'forbidden'], true)) {
                        throw $e; // transient: retry the whole sync later
                    }
                    $note = 'No HubSpot contact matches this number, and a new contact could not be created: ' . $e->getMessage();
                }
            } else {
                $note = 'No HubSpot contact matches this number.';
            }
        }
        $contact = null;
        if (!empty($j['hubspot_contact_id'])) {
            $contact = $this->contactById((string) $j['hubspot_contact_id']);
            if ($contact === null) {
                $note = 'HubSpot contact ' . $j['hubspot_contact_id'] . ' no longer exists (deleted or merged); the call is not linked.';
                $update['hubspot_contact_id'] = null;
                $j['hubspot_contact_id'] = null;
            }
        }
        return [$contact, $note];
    }

    /**
     * Name and email of the person behind one of this tenant's extensions (the extension's
     * caller ID name and its user's email), or null when it has no usable email.
     */
    public function extensionPerson(string $number): ?array
    {
        $n = trim($number);
        if ($n === '' || strlen($n) > 32) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT e.extension, e.effective_caller_id_name, u.username, u.user_email
               FROM public.v_extensions e
               LEFT JOIN public.v_extension_users eu ON eu.extension_uuid = e.extension_uuid
               LEFT JOIN public.v_users u ON u.user_uuid = eu.user_uuid
              WHERE e.domain_uuid = CAST(:d AS uuid) AND (e.extension = :n OR e.number_alias = :n2)
              ORDER BY (u.user_email IS NULL), e.extension LIMIT 1",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'n' => $n, 'n2' => $n]
        );
        $email = $row ? strtolower(trim((string) ($row['user_email'] ?? ''))) : '';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            return null;
        }
        $name = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($row['effective_caller_id_name'] ?? ''))));
        if ($name === '' || ctype_digit(str_replace(' ', '', $name))) {
            $name = 'Extension ' . $row['extension'];
        }
        $parts = explode(' ', $name, 2);
        return [
            'extension' => (string) $row['extension'],
            'firstname' => mb_substr($parts[0], 0, 100),
            'lastname' => mb_substr($parts[1] ?? '', 0, 100),
            'email' => $email,
        ];
    }

    /**
     * HubSpot contact of a colleague calling from (or called on) an internal extension:
     * the contact with the extension user's email, else (when $create) a new contact with
     * the extension's name, email, and the extension as phone. Null when the extension has
     * no email, or no contact exists and none may be created.
     */
    public function contactForExtension(string $number, bool $create): ?array
    {
        $person = $this->extensionPerson($number);
        if (!$person) {
            return null;
        }
        $key = 'hubspot:extcontact:' . $this->domainUuid . ':' . md5($person['email']);
        if (($cached = $this->cacheGet($key)) !== null && !empty($cached['id'])) {
            return $cached;
        }
        $byEmail = function () use ($person): ?array {
            $res = $this->client()->post('crm/v3/objects/contacts/search', [
                'filterGroups' => [['filters' => [['propertyName' => 'email', 'operator' => 'EQ', 'value' => $person['email']]]]],
                'properties' => self::CONTACT_PROPERTIES,
                'limit' => 1,
            ]);
            return !empty($res['results'][0]['id']) ? $this->normalizeContact($res['results'][0]) : null;
        };
        $contact = $byEmail();
        if (!$contact && $create) {
            try {
                $created = $this->client()->post('crm/v3/objects/contacts', ['properties' => array_filter([
                    'firstname' => $person['firstname'],
                    'lastname' => $person['lastname'],
                    'email' => $person['email'],
                    'phone' => $person['extension'],
                ], function ($v) { return $v !== ''; })]);
                $contact = !empty($created['id']) ? $this->normalizeContact($created) : null;
            } catch (HubspotException $e) {
                if ($e->reason !== 'conflict') {
                    throw $e;
                }
                $contact = $byEmail(); // created meanwhile (another call, or HubSpot's search lag)
            }
        }
        if ($contact) {
            $this->cacheSet($key, $contact);
        }
        return $contact;
    }

    /**
     * Live call in HubSpot: while the call rings or is in progress, create its HubSpot call
     * record once (status RINGING, then IN_PROGRESS) on the matching contact, so the call
     * shows on the contact's timeline as it happens. When it ends, syncJournal updates this
     * same record with the final status, duration, outcome and recording.
     *
     * One incoming call can ring on two of the agent's devices: a short Redis lock lets
     * only one copy create the record; the other copy reuses it (siblingCallId).
     */
    public function syncLive(string $journalUuid): void
    {
        $load = function () use ($journalUuid) {
            return $this->db->fetchOne(
                'SELECT * FROM public.q_hubspot_call_journals WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                Enum::FETCH_ASSOC,
                ['u' => $journalUuid, 'd' => $this->domainUuid]
            );
        };
        $j = $load();
        if (!$j) {
            return;
        }
        // One update at a time per call (both copies of an incoming call share the lock):
        // wait for a running one, then work on fresh data (it may have created the record).
        $lockKey = $this->liveLockKey($j);
        $locked = false;
        for ($i = 0; $i < 16 && !$locked; $i++) {
            try {
                $locked = (bool) $this->redis->set($lockKey, '1', 'EX', 20, 'NX');
            } catch (\Throwable $e) {
                $locked = true; // without Redis: carry on (the final sync still reuses a sibling's record)
            }
            if (!$locked) {
                usleep(500000);
            }
        }
        if (!$locked) {
            return;
        }
        try {
            $j = $load();
            if (!$j || !empty($j['ended_at']) || $j['sync_status'] === 'skipped' || self::bool($j['hubspot_owns_call'] ?? false)) {
                return; // ended (the final sync takes over), not logged, or HubSpot's own record
            }
            $settings = self::settingsOf($this->configRow());
            if (!$this->isActive() || !$settings['call_journaling']) {
                return;
            }
            $answered = $j['call_status'] === 'answered';
            if (!$answered && !$settings['log_unanswered']) {
                return; // unanswered calls are not logged: show the call once it is answered
            }
            $inbound = $j['call_direction'] === 'inbound';
            $update = [];
            $callId = (string) ($j['hubspot_call_id'] ?? '');
            if ($callId === '' && $inbound) {
                // The record created while it rang on the agent's other device: update it, but
                // it stays owned by that copy (one journal per HubSpot call); the copy that is
                // finally logged takes it over (takeOverSiblingCall).
                $sibling = $this->siblingCall($j);
                $callId = $sibling ? (string) $sibling['hubspot_call_id'] : '';
            }
            $client = $this->client('', false);
            [$contact, $note] = $this->resolveContact($j, $update, $settings);
            $j['call_status'] = $answered ? 'answered' : 'ringing';
            $properties = $this->callProperties($j, $contact, $note, $settings);
            if ($callId !== '') {
                $client->patch('crm/v3/objects/calls/' . rawurlencode($callId), ['properties' => $properties]);
                if ($contact && (string) ($j['associated_contact_id'] ?? '') !== $contact['id']) {
                    // Idempotent PUT: adding an association the record already has is a no-op.
                    $client->put('crm/v4/objects/calls/' . rawurlencode($callId) . '/associations/default/contacts/' . rawurlencode($contact['id']));
                }
            } else {
                $body = ['properties' => $properties];
                if ($contact) {
                    $body['associations'] = [['to' => ['id' => $contact['id']], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::ASSOC_CALL_TO_CONTACT]]]];
                    if ($settings['associate_company'] && !empty($contact['company_id'])) {
                        $body['associations'][] = ['to' => ['id' => $contact['company_id']], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::ASSOC_CALL_TO_COMPANY]]];
                    }
                }
                $this->updateJournal($journalUuid, ['create_attempted_at' => date('c')]);
                $created = $client->post('crm/v3/objects/calls', $body);
                $callId = (string) ($created['id'] ?? '');
                if ($callId === '') {
                    return; // the final sync finds it by call reference, or creates it
                }
                $update['hubspot_call_id'] = $callId;
                $update['associated_contact_id'] = $contact ? $contact['id'] : null;
            }
            if ($update) {
                // Only while the call is still running: a final sync may have taken over meanwhile.
                $this->db->execute(
                    'UPDATE public.q_hubspot_call_journals SET hubspot_call_id = COALESCE(hubspot_call_id, :c),
                        hubspot_contact_id = COALESCE(hubspot_contact_id, :hc),
                        associated_contact_id = COALESCE(:ac, associated_contact_id), updated_at = now()
                     WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                    ['c' => $update['hubspot_call_id'] ?? null, 'hc' => $update['hubspot_contact_id'] ?? null,
                     'ac' => $update['associated_contact_id'] ?? null, 'u' => $journalUuid, 'd' => $this->domainUuid]
                );
            }
        } catch (HubspotException $e) {
            // Live status is best effort: the final sync (with retries) still saves the call.
            error_log('[hubspot] live call update failed: ' . $e->reason . ' ' . mb_substr($e->getMessage(), 0, 200));
        } finally {
            try {
                $this->redis->del($lockKey);
            } catch (\Throwable $e) {
            }
        }
    }

    /** Redis lock for live updates of one call (shared by both copies of an incoming call). */
    private function liveLockKey(array $j): string
    {
        $tail = substr(self::digits((string) $j['caller_number']), -9);
        return 'hubspot:live:' . $this->domainUuid . ':'
            . ($j['call_direction'] === 'inbound' && strlen($tail) >= self::SAME_CALLER_MIN_DIGITS ? 'in:' . $j['user_uuid'] . ':' . $tail : 'j:' . $j['journal_uuid']);
    }

    /** True while a live update of this call runs (it may be creating the HubSpot record). */
    private function liveUpdateRunning(array $j): bool
    {
        try {
            return (bool) $this->redis->exists($this->liveLockKey($j));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The same incoming call's journal on the agent's other device that holds a HubSpot
     * record (created while it rang there), if any. Not a record HubSpot created itself.
     */
    private function siblingCall(array $j, bool $forUpdate = false): ?array
    {
        $tail = substr(self::digits((string) $j['caller_number']), -9);
        if (strlen($tail) < self::SAME_CALLER_MIN_DIGITS) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT journal_uuid, hubspot_call_id, associated_contact_id FROM public.q_hubspot_call_journals
              WHERE domain_uuid = CAST(:d AS uuid) AND journal_uuid <> CAST(:u AS uuid)
                AND call_direction = 'inbound' AND user_uuid IS NOT DISTINCT FROM :usr
                AND hubspot_call_id IS NOT NULL AND NOT COALESCE(hubspot_owns_call, false)
                AND right(regexp_replace(COALESCE(caller_number, ''), '\\D', '', 'g'), 9) = :t
                AND started_at BETWEEN CAST(:st AS timestamptz) - interval '20 seconds'
                                   AND CAST(:st2 AS timestamptz) + interval '20 seconds'
                -- a copy of the same call cannot have ended before this one started (a quick redial is another call)
                AND (ended_at IS NULL OR ended_at > CAST(:st3 AS timestamptz))
              ORDER BY started_at LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''),
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'u' => $j['journal_uuid'], 'usr' => $j['user_uuid'], 't' => $tail, 'st' => $j['started_at'], 'st2' => $j['started_at'], 'st3' => $j['started_at']]
        );
        return $row ?: null;
    }

    /**
     * This copy of an incoming call is the one logged, and its other copy holds the HubSpot
     * record created while the call rang: move the record to this journal (one transaction,
     * so exactly one journal owns it). Returns [hubspot_call_id, associated_contact_id] or null.
     */
    private function takeOverSiblingCall(array $j): ?array
    {
        $this->db->begin();
        try {
            $s = $this->siblingCall($j, true);
            if (!$s) {
                $this->db->commit();
                return null;
            }
            $this->db->execute(
                'UPDATE public.q_hubspot_call_journals SET hubspot_call_id = NULL, associated_contact_id = NULL, updated_at = now()
                  WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                ['u' => $s['journal_uuid'], 'd' => $this->domainUuid]
            );
            $this->db->execute(
                'UPDATE public.q_hubspot_call_journals SET hubspot_call_id = :c, associated_contact_id = :a, updated_at = now()
                  WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                ['c' => $s['hubspot_call_id'], 'a' => $s['associated_contact_id'], 'u' => $j['journal_uuid'], 'd' => $this->domainUuid]
            );
            $this->db->commit();
            return [(string) $s['hubspot_call_id'], $s['associated_contact_id'] !== null ? (string) $s['associated_contact_id'] : null];
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * A call that ended without being logged (unanswered while "log unanswered" is off,
     * or answered on another device) may have a record created while it rang. Remove it
     * from HubSpot (archived: restorable from HubSpot's recycle bin), unless another
     * copy of the same call still uses it.
     */
    public function discardLiveRecord(string $journalUuid): void
    {
        $j = $this->db->fetchOne(
            'SELECT * FROM public.q_hubspot_call_journals WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid, 'd' => $this->domainUuid]
        );
        if (!$j || $j['sync_status'] !== 'skipped' || empty($j['hubspot_call_id']) || self::bool($j['hubspot_owns_call'] ?? false)) {
            return;
        }
        $callId = (string) $j['hubspot_call_id'];
        if ($j['call_direction'] === 'inbound') {
            $tail = substr(self::digits((string) $j['caller_number']), -9);
            $other = strlen($tail) >= self::SAME_CALLER_MIN_DIGITS ? $this->db->fetchOne(
                "SELECT 1 FROM public.q_hubspot_call_journals
                  WHERE domain_uuid = CAST(:d AS uuid) AND journal_uuid <> CAST(:u AS uuid)
                    AND call_direction = 'inbound' AND user_uuid IS NOT DISTINCT FROM :usr AND sync_status <> 'skipped'
                    AND right(regexp_replace(COALESCE(caller_number, ''), '\\D', '', 'g'), 9) = :t
                    AND started_at BETWEEN CAST(:st AS timestamptz) - interval '20 seconds'
                                       AND CAST(:st2 AS timestamptz) + interval '20 seconds'
                    AND (ended_at IS NULL OR ended_at > CAST(:st3 AS timestamptz))",
                Enum::FETCH_ASSOC,
                ['d' => $this->domainUuid, 'u' => $journalUuid, 'usr' => $j['user_uuid'], 't' => $tail, 'st' => $j['started_at'], 'st2' => $j['started_at'], 'st3' => $j['started_at']]
            ) : null;
            if ($other) {
                return; // the copy that is logged updates this record
            }
        }
        try {
            $this->client('', false)->request('DELETE', 'crm/v3/objects/calls/' . rawurlencode($callId));
            $this->updateJournal($journalUuid, ['hubspot_call_id' => null, 'associated_contact_id' => null]);
        } catch (HubspotException $e) {
            if ($e->reason === 'not_found') {
                $this->updateJournal($journalUuid, ['hubspot_call_id' => null, 'associated_contact_id' => null]);
                return;
            }
            error_log('[hubspot] could not remove the live call record ' . $callId . ': ' . $e->reason);
        }
    }

    /**
     * hs_call_status for a RingQ status, but only a value this portal's
     * hs_call_status property actually offers (read at connection test).
     * Unknown/unverified: omitted, never guessed.
     */
    private function hubspotStatus(string $ringqStatus): ?string
    {
        $wanted = [
            'completed' => ['COMPLETED'],
            'missed' => ['MISSED', 'NO_ANSWER'],
            'rejected' => ['CANCELED', 'NO_ANSWER'],
            'busy' => ['BUSY'],
            'no_answer' => ['NO_ANSWER'],
            'failed' => ['FAILED'],
            // Live record while the call runs (syncLive).
            'ringing' => ['RINGING', 'CONNECTING'],
            'answered' => ['IN_PROGRESS'],
        ][$ringqStatus] ?? [];
        $allowed = self::jsonList($this->configRow()['call_status_options'] ?? null);
        foreach ($wanted as $v) {
            if (in_array($v, $allowed, true)) {
                return $v;
            }
        }
        return null;
    }

    /** Call outcome GUID from this portal's own dispositions (matched by label). */
    private function disposition(string $ringqStatus): ?string
    {
        $labels = [
            'completed' => ['connected'],
            'busy' => ['busy'],
            'no_answer' => ['no answer'],
            'missed' => ['no answer'],
            'rejected' => ['no answer'],
        ][$ringqStatus] ?? [];
        $map = self::jsonList($this->configRow()['dispositions'] ?? null);
        foreach ($labels as $l) {
            if (!empty($map[$l]) && preg_match('/^[0-9a-f-]{36}$/i', (string) $map[$l])) {
                return (string) $map[$l];
            }
        }
        return null;
    }

    private function callProperties(array $j, ?array $contact, ?string $note, array $settings): array
    {
        $labels = [
            'completed' => 'Answered', 'missed' => 'Missed', 'rejected' => 'Rejected', 'busy' => 'Busy',
            'no_answer' => 'No answer', 'failed' => 'Failed', 'ringing' => 'Ringing', 'answered' => 'In call',
        ];
        $statusLabel = $labels[$j['call_status']] ?? ucfirst((string) $j['call_status']);
        $inbound = $j['call_direction'] === 'inbound';
        $remote = (string) ($inbound ? $j['caller_number'] : $j['destination_number']);
        $agentName = trim((string) ($j['agent_name'] ?? ''));
        $agent = trim($agentName . (!empty($j['agent_extension']) ? ' (ext ' . $j['agent_extension'] . ')' : ''));
        $ref = self::callRef((string) $j['local_call_id']);
        $h = function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

        $rows = [
            'Direction' => $inbound ? 'Inbound' : 'Outbound',
            'Result' => $statusLabel,
            'Number' => $remote !== '' ? $remote : '-',
            'Agent' => $agent !== '' ? $agent : '-',
            'Started via' => ($j['call_origin'] ?? '') === 'click_to_call' ? 'Click-to-call from HubSpot contact' : 'RingQ dialer',
            'Talk time' => self::fmtDuration((int) $j['talk_duration']),
            'Total time' => self::fmtDuration((int) $j['duration']),
            'RingQ call reference' => $ref,
        ];
        $html = '';
        if (!empty($j['notes'])) {
            $html .= '<p><strong>Notes:</strong></p><p>' . nl2br($h(mb_substr((string) $j['notes'], 0, 5000))) . '</p>';
        }
        foreach ($rows as $k => $v) {
            $html .= '<p><strong>' . $h($k) . ':</strong> ' . $h($v) . '</p>';
        }
        if ($j['recording_status'] === 'waiting' && $settings['include_recording']) {
            $html .= '<p><em>Recording: processing, it will be attached shortly.</em></p>';
        } elseif ($j['recording_status'] === 'unavailable' && $settings['include_recording']) {
            $html .= '<p><em>Recording: not available for this call.</em></p>';
        }
        if (!empty($j['summary'])) {
            $html .= '<p><strong>AI summary:</strong> ' . $h($j['summary']) . '</p>';
        }
        if (!empty($j['sentiment'])) {
            $html .= '<p><strong>Sentiment:</strong> ' . $h($j['sentiment']) . '</p>';
        }
        if (!empty($j['transcription'])) {
            $html .= '<p><strong>Transcription:</strong></p><p>' . nl2br($h(mb_substr((string) $j['transcription'], 0, 20000))) . '</p>';
        }
        if ($note) {
            $html .= '<p><em>' . $h($note) . '</em></p>';
        }

        $who = $contact && $contact['full_name'] !== '' ? $contact['full_name'] : $remote;
        $title = ($inbound ? 'Inbound call from ' : 'Outbound call to ') . $who . ' - ' . $statusLabel . ' - ' . $ref;
        $answered = $j['call_status'] === 'completed';

        $p = [
            'hs_timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime((string) $j['started_at'])),
            'hs_call_title' => mb_substr($title, 0, 250),
            'hs_call_body' => $html,
            'hs_call_direction' => $inbound ? 'INBOUND' : 'OUTBOUND',
            // Milliseconds of real talk time measured by the dialer; 0 when not answered.
            'hs_call_duration' => (string) ($answered ? ((int) $j['talk_duration']) * 1000 : 0),
            'hs_call_from_number' => (string) ($j['caller_number'] ?? ''),
            'hs_call_to_number' => (string) ($j['destination_number'] ?? ''),
        ];
        $status = $this->hubspotStatus((string) $j['call_status']);
        if ($status !== null) {
            $p['hs_call_status'] = $status;
        }
        $disp = $this->disposition((string) $j['call_status']);
        if ($disp !== null) {
            $p['hs_call_disposition'] = $disp;
        }
        if ($j['recording_status'] === 'linked' && $settings['include_recording']) {
            $url = $this->recordingUrl($j);
            if ($url !== null) {
                $p['hs_call_recording_url'] = $url;
            }
        }
        return $p;
    }

    /**
     * One incoming call can ring on two of the agent's devices (RingQ app + HubSpot
     * dialer), giving two journals for one call. Decides whether $j is the copy to log.
     *
     * @return string 'log' | 'skip' | 'wait' (a copy is still ringing: decide once it ends)
     */
    private function inboundDuplicateDecision(array $j): string
    {
        $tail = substr(self::digits((string) $j['caller_number']), -9);
        if (strlen($tail) < self::SAME_CALLER_MIN_DIGITS) {
            return 'log';
        }
        $rows = $this->db->fetchAll(
            "SELECT journal_uuid, call_origin, call_status, hubspot_call_id, ended_at, started_at
               FROM public.q_hubspot_call_journals
              WHERE domain_uuid = CAST(:d AS uuid) AND journal_uuid <> CAST(:u AS uuid)
                AND call_direction = 'inbound' AND user_uuid IS NOT DISTINCT FROM :usr
                AND sync_status <> 'skipped'
                AND right(regexp_replace(COALESCE(caller_number, ''), '\\D', '', 'g'), 9) = :t
                AND started_at BETWEEN CAST(:st AS timestamptz) - interval '20 seconds'
                                   AND CAST(:st2 AS timestamptz) + interval '20 seconds'
                AND (ended_at IS NULL OR ended_at > CAST(:st3 AS timestamptz))",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'u' => $j['journal_uuid'], 'usr' => $j['user_uuid'], 't' => $tail, 'st' => $j['started_at'], 'st2' => $j['started_at'], 'st3' => $j['started_at']]
        );
        return self::duplicateDecision($j, $rows ?: [], time());
    }

    /**
     * Pure rule (unit-tested): the answered copy wins, then one already in HubSpot,
     * then the HubSpot dialer's copy, then the lowest journal id. A copy that has not
     * ended yet (and started under 2 minutes ago) makes us wait for it.
     */
    public static function duplicateDecision(array $j, array $siblings, int $now): string
    {
        $score = function (array $r): int {
            return (in_array($r['call_status'], ['completed', 'answered'], true) ? 4 : 0)
                + (!empty($r['hubspot_call_id']) ? 2 : 0)
                + (($r['call_origin'] ?? '') === 'hubspot_cti' ? 1 : 0);
        };
        $mine = $score($j);
        foreach ($siblings as $s) {
            if (empty($s['ended_at']) && !in_array($s['call_status'], ['answered', 'completed'], true)) {
                if ($now - (int) strtotime((string) $s['started_at']) < 120) {
                    return 'wait';
                }
                continue; // abandoned copy (tab closed while ringing)
            }
            $theirs = $score($s);
            if ($theirs > $mine || ($theirs === $mine && strcmp((string) $s['journal_uuid'], (string) $j['journal_uuid']) < 0)) {
                return 'skip';
            }
        }
        return 'log';
    }

    /**
     * Update for a call HubSpot created itself (HubSpot dialer). Only facts RingQ
     * measured: never the title, notes or outcome the agent enters in HubSpot.
     */
    private function ownedCallProperties(array $j, array $settings): array
    {
        $answered = $j['call_status'] === 'completed';
        $p = [
            'hs_call_direction' => $j['call_direction'] === 'inbound' ? 'INBOUND' : 'OUTBOUND',
            'hs_call_duration' => (string) ($answered ? ((int) $j['talk_duration']) * 1000 : 0),
            'hs_call_from_number' => (string) ($j['caller_number'] ?? ''),
            'hs_call_to_number' => (string) ($j['destination_number'] ?? ''),
        ];
        $status = $this->hubspotStatus((string) $j['call_status']);
        if ($status !== null) {
            $p['hs_call_status'] = $status;
        }
        if ($j['recording_status'] === 'linked' && $settings['include_recording']) {
            $url = $this->recordingUrl($j);
            if ($url !== null) {
                $p['hs_call_recording_url'] = $url;
            }
        }
        return $p;
    }

    // ==================================================================
    // HubSpot dialer (calling window inside HubSpot)
    // ==================================================================

    /**
     * Issues a dialer token for the logged-in user. Returned ONCE; only its
     * SHA-256 is stored. The HubSpot calling window keeps it and sends it with
     * every hubspotcrm/cti request (it cannot use the RingQ login cookie there).
     */
    public function issueCtiToken(array $session): array
    {
        $userUuid = (string) ($session['user_uuid'] ?? '');
        $ext = mb_substr((string) preg_replace('/[^0-9A-Za-z*#+_-]/', '', (string) ($session['extension'] ?? '')), 0, 32);
        if ($userUuid === '' || $ext === '') {
            throw new HubspotException('Your RingQ user has no extension, so it cannot place calls from HubSpot.', 'validation');
        }
        if ($this->sipCredentials($ext) === null) {
            throw new HubspotException('Extension ' . $ext . ' was not found or is disabled.', 'not_found');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $agent = mb_substr(trim(((string) ($session['firstname'] ?? '')) . ' ' . ((string) ($session['lastname'] ?? ''))), 0, 120);
        $this->db->execute(
            "INSERT INTO public.q_hubspot_cti_tokens (token_hash, domain_uuid, user_uuid, extension, agent_name, created_at, expires_at)
             VALUES (:h, CAST(:d AS uuid), :u, :e, :n, now(), now() + CAST(:days AS integer) * interval '1 day')",
            ['h' => hash('sha256', $token), 'd' => $this->domainUuid, 'u' => $userUuid, 'e' => $ext, 'n' => $agent !== '' ? $agent : null, 'days' => self::CTI_TOKEN_DAYS]
        );
        // Keep only the newest few per user (each browser/computer has its own).
        $this->db->execute(
            'UPDATE public.q_hubspot_cti_tokens SET revoked_at = now()
              WHERE domain_uuid = CAST(:d AS uuid) AND user_uuid = :u AND revoked_at IS NULL
                AND token_hash NOT IN (
                    SELECT token_hash FROM public.q_hubspot_cti_tokens
                     WHERE domain_uuid = CAST(:d2 AS uuid) AND user_uuid = :u2 AND revoked_at IS NULL
                     ORDER BY created_at DESC LIMIT CAST(:keep AS integer))',
            ['d' => $this->domainUuid, 'u' => $userUuid, 'd2' => $this->domainUuid, 'u2' => $userUuid, 'keep' => self::CTI_TOKENS_PER_USER]
        );
        return ['token' => $token, 'expires_in_days' => self::CTI_TOKEN_DAYS];
    }

    /**
     * Resolves a dialer token to a session-like array (same keys the RingQ login
     * session has), or null when unknown, expired or revoked.
     */
    public static function ctiSession($db, string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $db->fetchOne(
            'SELECT t.token_hash, t.domain_uuid, t.user_uuid, t.extension, t.agent_name, t.last_used_at, d.domain_name
               FROM public.q_hubspot_cti_tokens t
               JOIN public.v_domains d ON d.domain_uuid = t.domain_uuid
              WHERE t.token_hash = :h AND t.revoked_at IS NULL AND t.expires_at > now()',
            Enum::FETCH_ASSOC,
            ['h' => $hash]
        );
        if (!$row || !hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }
        if (empty($row['last_used_at']) || strtotime((string) $row['last_used_at']) < time() - 300) {
            $db->execute('UPDATE public.q_hubspot_cti_tokens SET last_used_at = now() WHERE token_hash = :h', ['h' => $hash]);
        }
        $name = trim((string) ($row['agent_name'] ?? ''));
        return [
            'domain_uuid' => (string) $row['domain_uuid'],
            'subdomain' => (string) $row['domain_name'],
            'user_uuid' => (string) $row['user_uuid'],
            'extension' => (string) $row['extension'],
            'firstname' => $name,
            'lastname' => '',
            'cti_token_hash' => $hash,
        ];
    }

    public function revokeCtiToken(string $tokenHash): void
    {
        $this->db->execute(
            'UPDATE public.q_hubspot_cti_tokens SET revoked_at = now() WHERE token_hash = :h AND domain_uuid = CAST(:d AS uuid)',
            ['h' => $tokenHash, 'd' => $this->domainUuid]
        );
    }

    /** SIP login of an extension (same source as api/sipcredentials). Null if missing or disabled. */
    public function sipCredentials(string $extension): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT extension, number_alias, password FROM public.v_extensions
              WHERE domain_uuid = CAST(:d AS uuid) AND extension = :e
                AND COALESCE(CAST(enabled AS text), 'true') NOT IN ('false', 'f')",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'e' => $extension]
        );
        if (!$row || (string) $row['password'] === '') {
            return null;
        }
        return [
            'extension' => (string) $row['extension'],
            'sip_username' => (string) ($row['number_alias'] ?: $row['extension']),
            'sip_password' => (string) $row['password'],
            'sip_domain' => $this->subdomain,
        ];
    }

    // ==================================================================
    // Recordings
    // ==================================================================

    /** Matches the journal to its CDR (same tenant, same remote number, start within ±90 s, has a recording). */
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

    /** Signed, tenant-bound link served by HubspotcrmController::recordingAction. */
    public function recordingUrl(array $j): ?string
    {
        $row = $this->configRow();
        if (!$row || empty($row['link_salt']) || empty($j['xml_cdr_uuid'])) {
            return null;
        }
        $base = (string) (self::settingsOf($row)['recording_base_url'] ?? '');
        if ($base === '') {
            if ($this->subdomain === '') {
                return null;
            }
            $base = 'https://' . $this->subdomain . ':8443';
        }
        $exp = self::RECORDING_LINK_DAYS > 0 ? time() + self::RECORDING_LINK_DAYS * 86400 : 0;
        $sig = HubspotCrypto::sign($j['journal_uuid'] . '|' . $exp . '|' . $row['link_salt']);
        // The trailing file name gives HubSpot's player a recognisable audio extension.
        $ext = strtolower(pathinfo((string) ($this->cdrRecordName((string) $j['xml_cdr_uuid']) ?? ''), PATHINFO_EXTENSION)) === 'mp3' ? 'mp3' : 'wav';
        return rtrim($base, '/') . '/hubspotcrm/recording/' . $j['journal_uuid'] . '/' . $exp . '/' . $sig . '/call.' . $ext;
    }

    private function cdrRecordName(string $cdrUuid): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT record_name FROM public.q_ringq_cdr WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'c' => $cdrUuid]
        );
        return $row ? (string) $row['record_name'] : null;
    }

    /**
     * Validates a signed recording link and returns the absolute file path, or
     * null. Only files under the FreeSWITCH recordings tree are served, and only
     * while the tenant's HubSpot integration is configured (Disconnect revokes).
     */
    public static function resolveSignedRecording($db, string $journalUuid, string $exp, string $sig): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $journalUuid) || !ctype_digit($exp)
            || ((int) $exp !== 0 && (int) $exp < time()) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $sig)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT j.journal_uuid, c.link_salt, cdr.record_path, cdr.record_name
               FROM public.q_hubspot_call_journals j
               JOIN public.q_hubspot_config c ON c.domain_uuid = j.domain_uuid
               JOIN public.q_ringq_cdr cdr ON cdr.xml_cdr_uuid = j.xml_cdr_uuid AND cdr.domain_uuid = j.domain_uuid
              WHERE j.journal_uuid = CAST(:u AS uuid) AND j.recording_status = 'linked'
                AND c.access_token_enc IS NOT NULL",
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid]
        );
        if (!$row || empty($row['link_salt'])) {
            return null;
        }
        if (!HubspotCrypto::verify($row['journal_uuid'] . '|' . $exp . '|' . $row['link_salt'], $sig)) {
            return null;
        }
        $path = realpath(rtrim((string) $row['record_path'], '/') . '/' . basename((string) $row['record_name']));
        if ($path === false || strpos($path, '/var/lib/freeswitch/recordings/') !== 0 || !is_file($path)) {
            return null;
        }
        return ['path' => $path, 'name' => basename($path)];
    }

    /** Manual recording re-sync (POST hubspotcrm/recordingsSync). */
    public function requeueRecording(array $j): void
    {
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET
                recording_status = CASE WHEN call_status = 'completed' AND recording_status <> 'linked' THEN 'waiting' ELSE recording_status END,
                sync_status = CASE WHEN sync_status = 'failed' THEN 'pending' ELSE sync_status END,
                next_attempt_at = now(), sync_locked_at = NULL
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
    }

    // ==================================================================
    // AI (optional)
    // ==================================================================

    private function aiWanted(array $settings): bool
    {
        return (bool) ($settings['ai_transcription'] || $settings['ai_summary'] || $settings['ai_sentiment']);
    }

    /** Hook for a real summary/sentiment provider. */
    public function summaryProvider(): HubspotSummaryProvider
    {
        return new NullHubspotSummaryProvider();
    }

    /** Returns journal fields to change for the AI step (may be empty). */
    private function advanceAi(array $j, array $settings): array
    {
        $wanted = $this->aiWanted($settings);
        if (!$wanted) {
            return $j['ai_status'] === null ? ['ai_status' => 'disabled'] : [];
        }
        if ($j['ai_status'] === 'done' || $j['ai_status'] === 'failed') {
            return [];
        }
        if ($j['call_status'] !== 'completed' || $j['recording_status'] === 'none' || $j['recording_status'] === 'unavailable') {
            return ['ai_status' => 'disabled'];
        }
        if ($j['recording_status'] === 'waiting' || $j['sync_status'] !== 'synced') {
            return $j['ai_status'] === 'pending' ? [] : ['ai_status' => 'pending'];
        }
        // Recording linked and the call is saved in HubSpot: look for the transcript.
        $cdr = $this->db->fetchOne(
            'SELECT xml_cdr_uuid, json, record_path, record_name FROM public.q_ringq_cdr WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'c' => $j['xml_cdr_uuid']]
        );
        if (!$cdr) {
            return ['ai_status' => 'failed', 'ai_error' => 'The call record was not found.'];
        }
        $stored = HubspotTranscripts::storedText((string) ($cdr['json'] ?? ''));
        $age = time() - (int) strtotime((string) $j['ended_at']);
        // The agent's popup transcribes via RingQ's /reports/audiototext right after the
        // call. Background workers only transcribe if that has not happened within 90 s,
        // so a call is never paid for twice.
        if ($stored === null && (PHP_SAPI !== 'cli' || $age < 90)) {
            return $j['ai_status'] === 'pending' ? [] : ['ai_status' => 'pending'];
        }
        $tx = $stored !== null ? ['status' => 'done', 'text' => $stored, 'reason' => null]
            : (new HubspotTranscripts($this->redis))->transcribe($cdr, $this->subdomain);
        if ($tx['status'] === 'pending') {
            return $age > 1800 ? ['ai_status' => 'failed', 'ai_error' => mb_substr((string) ($tx['reason'] ?? 'Transcription timed out.'), 0, 300)] : [];
        }
        if ($tx['status'] !== 'done') {
            return ['ai_status' => 'failed', 'ai_error' => mb_substr((string) ($tx['reason'] ?? 'Transcription is not available.'), 0, 300)];
        }
        if ($stored === null && trim((string) ($cdr['json'] ?? '')) === '') {
            // Keep RingQ's Call Logs in step (same format as /reports/audiototext).
            try {
                $this->db->execute(
                    "UPDATE public.q_ringq_cdr SET json = :j WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid) AND COALESCE(json, '') = ''",
                    ['j' => HubspotTranscripts::cdrJson((string) $tx['text']), 'd' => $this->domainUuid, 'c' => $cdr['xml_cdr_uuid']]
                );
            } catch (\Throwable $e) {
                error_log('[hubspot] could not store transcript on CDR: ' . get_class($e));
            }
        }
        $out = ['ai_status' => 'done', 'ai_error' => null];
        if ($settings['ai_transcription']) {
            $out['transcription'] = mb_substr((string) $tx['text'], 0, 60000);
        }
        if ($settings['ai_summary'] || $settings['ai_sentiment']) {
            $analysis = $this->summaryProvider()->analyze((string) $tx['text']);
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

    /**
     * Called by the popup after it ran RingQ's own /reports/audiototext/{xml_cdr_uuid}:
     * the text is now on the CDR, so add it to the HubSpot call at once.
     * $error = RingQ's message when transcription did not work.
     */
    public function applyBrowserTranscript(array $j, string $error): array
    {
        $hasText = false;
        if (!empty($j['xml_cdr_uuid'])) {
            $row = $this->db->fetchOne(
                'SELECT json FROM public.q_ringq_cdr WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid)',
                Enum::FETCH_ASSOC,
                ['d' => $this->domainUuid, 'c' => $j['xml_cdr_uuid']]
            );
            $hasText = $row && HubspotTranscripts::storedText((string) ($row['json'] ?? '')) !== null;
        }
        if ($hasText && $j['ai_status'] !== 'done') {
            $this->db->execute(
                "UPDATE public.q_hubspot_call_journals SET ai_status = 'pending', next_attempt_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
            );
            $this->syncJournal((string) $j['journal_uuid']);
        } elseif (!$hasText && $error !== '' && in_array($j['ai_status'], [null, 'pending'], true)) {
            $reason = trim((string) preg_replace('/\s*\(Code [A-Z0-9]+\)\s*$/', '', $error));
            $this->db->execute(
                "UPDATE public.q_hubspot_call_journals SET ai_status = 'failed', ai_error = :e
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                ['e' => 'RingQ transcription: ' . mb_substr($reason, 0, 250), 'u' => $j['journal_uuid'], 'd' => $this->domainUuid]
            );
        }
        return $this->journalByLocalId((string) $j['local_call_id']) ?: $j;
    }

    /** Manual (re)run of AI for one call (POST hubspotcrm/aiAnalysis). */
    public function requeueAi(array $j): void
    {
        $this->db->execute(
            "UPDATE public.q_hubspot_call_journals SET ai_status = 'pending', ai_error = NULL, next_attempt_at = now(), sync_locked_at = NULL,
                recording_status = CASE WHEN call_status = 'completed' AND recording_status = 'unavailable' THEN 'waiting' ELSE recording_status END
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
    }

    // ==================================================================
    // Background work
    // ==================================================================

    /**
     * Post-hangup follow-up for ONE call, in a separate CLI process
     * (HubspotcrmTask::afterAction): adds the recording link as soon as the
     * CDR exists, then the transcript. Anything left is handled by cron.
     */
    public function followUp(string $journalUuid): void
    {
        for ($i = 0; $i < 60; $i++) {
            $j = $this->journalByUuid($journalUuid);
            if (!$j || $j['sync_status'] === 'skipped') {
                return;
            }
            $open = $j['sync_status'] === 'pending' || $j['recording_status'] === 'waiting' || $j['ai_status'] === 'pending';
            if (!$open && $j['sync_status'] !== 'failed') {
                return;
            }
            if ($j['sync_status'] === 'failed') {
                return; // left for the admin's Retry
            }
            $due = empty($j['next_attempt_at']) || strtotime((string) $j['next_attempt_at']) <= time();
            if ($due) {
                $this->syncJournal($journalUuid);
            }
            sleep(3);
        }
    }

    /** Cron entry point: every due journal of every tenant. */
    public static function processDue($db, $redis, int $limit = 50): int
    {
        $rows = $db->fetchAll(
            "SELECT j.journal_uuid, j.domain_uuid, d.domain_name
               FROM public.q_hubspot_call_journals j
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
            $key = (string) $r['domain_uuid'];
            try {
                if (!isset($services[$key])) {
                    $services[$key] = new self($db, $redis, $key, (string) $r['domain_name']);
                }
                $services[$key]->syncJournal((string) $r['journal_uuid']);
            } catch (\Throwable $e) {
                error_log('[hubspot] cron journal error: ' . get_class($e));
            }
            $n++;
        }
        return $n;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

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
