<?php
declare(strict_types=1);

namespace Library\Freshdesk;

use Phalcon\Db\Enum;

require_once __DIR__ . '/FreshdeskException.php';
require_once __DIR__ . '/FreshdeskCrypto.php';
require_once __DIR__ . '/FreshdeskClient.php';

/**
 * All Freshdesk business logic, shared by FreshdeskcrmController (web) and
 * FreshdeskcrmTask (cron). Every query is scoped by the tenant's domain_uuid
 * passed to the constructor; nothing here can read or write another tenant's rows.
 *
 * Freshdesk is a help desk: its public API has no "call" object. Each RingQ call
 * is therefore logged as ONE ticket with source = Phone (3) on the caller's
 * contact, and the recording is added to that ticket as a private note with the
 * audio file attached (or a signed RingQ link when the file is too large).
 *
 * Tables (app/scripts/freshdesk): q_freshdesk_config, q_freshdesk_call_journals,
 * q_freshdesk_cti_tokens.
 */
class FreshdeskService
{
    const CONTACT_CACHE_TTL = 300;
    const CONTACT_MISS_TTL = 60;          // "no contact" answers are cached briefly (new contacts show up fast)
    const RECORDING_WAIT_SECONDS = 1800;  // keep looking for the CDR recording for 30 min after hangup
    // 0 = recording links never expire (they stay signed and tenant-bound, and are
    // revoked together by Disconnect, which rotates link_salt).
    const RECORDING_LINK_DAYS = 0;
    // Freshdesk allows 20 MB of attachments per ticket in total; keep room for agents' own files.
    const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;
    const MAX_RETRIES = 8;
    const BACKOFF = [60, 300, 900, 1800, 3600, 7200, 14400, 28800];
    const MISSED_SYNC_DELAY = 20;         // s: lets the other device of the agent report an answer first
    const SIBLING_WINDOW = 120;           // s: same inbound call ringing on two devices of one agent
    const CTI_TOKEN_DAYS = 30;
    const CTI_TOKENS_PER_USER = 5;

    // Ticket source "Phone" (Freshdesk API: Email 1, Portal 2, Phone 3, ...).
    const SOURCE_PHONE = 3;
    const STATUS_OPEN = 2;

    const STATUSES = ['ringing', 'answered', 'completed', 'missed', 'rejected', 'busy', 'no_answer', 'failed'];
    const FINAL_STATUSES = ['completed', 'missed', 'rejected', 'busy', 'no_answer', 'failed'];
    const RECORDING_DONE = ['uploaded', 'link_only', 'unavailable', 'none'];

    const SETTINGS_DEFAULTS = [
        'popup_incoming' => true,
        'popup_outgoing' => true,
        'call_journaling' => true,
        'log_unanswered' => true,
        // Calls between two extensions: logged on the colleague's Freshdesk contact (found by
        // the extension user's email, or created once with name, email and the extension number).
        'log_internal_calls' => true,
        // Caller not in Freshdesk: 'skip' keeps the call in RingQ only; 'create_contact' creates one first.
        'unmatched_calls' => 'skip',
        'contact_creation' => false,
        'ticket_status' => 4,          // Resolved
        'missed_ticket_status' => 2,   // Open: someone should call back
        'ticket_priority' => 1,        // Low
        'recording_upload' => true,
        'recording_link' => false,
        'screenpop_incoming' => false,
        'screenpop_outgoing' => false,
        'screenpop_target' => 'contact_tab',
        'country_code' => '',
        'timezone' => 'UTC',
    ];
    const BOOL_SETTINGS = ['popup_incoming', 'popup_outgoing', 'call_journaling', 'log_unanswered', 'log_internal_calls', 'contact_creation',
        'recording_upload', 'recording_link', 'screenpop_incoming', 'screenpop_outgoing'];

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
            throw new FreshdeskException('Invalid tenant.', 'validation');
        }
        $this->db = $db;
        $this->redis = $redis;
        $this->domainUuid = strtolower($domainUuid);
        $this->subdomain = (string) preg_replace('/[^a-z0-9.-]/i', '', $subdomain);
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
            'SELECT * FROM public.q_freshdesk_config WHERE domain_uuid = CAST(:d AS uuid)',
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

    /** Shape returned to the browser. Never includes the API key. */
    public function publicConfig(bool $canManage): array
    {
        $row = $this->configRow();
        $s = self::settingsOf($row);
        $warnings = [];
        if ($row && !empty($row['ticket_field_warnings'])) {
            $decoded = json_decode((string) $row['ticket_field_warnings'], true);
            $warnings = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        $out = [
            'enabled' => $row ? self::bool($row['enabled']) : false,
            'domain' => (string) ($row['fd_domain'] ?? ''),
            'api_key_set' => $row ? !empty($row['api_key_enc']) : false,
            'key_hint' => $canManage && $row && !empty($row['api_key_enc']) && !empty($row['key_last4']) ? '••••' . $row['key_last4'] : '',
            'agent_name' => (string) ($row['fd_agent_name'] ?? ''),
            'account_name' => (string) ($row['fd_account_name'] ?? ''),
            'ticket_field_warnings' => $canManage ? $warnings : [],
            'connection_status' => $row['connection_status'] ?? 'not_configured',
            'last_tested_at' => self::iso($row['last_tested_at'] ?? null),
            'last_sync_at' => self::iso($row['last_sync_at'] ?? null),
            'last_error' => $canManage ? ($row['last_error'] ?? null) : null,
            'can_manage' => $canManage,
        ];
        foreach ($s as $k => $v) {
            $out[$k] = in_array($k, self::BOOL_SETTINGS, true) ? (bool) $v
                : (in_array($k, ['ticket_status', 'missed_ticket_status', 'ticket_priority'], true) ? (int) $v : (string) $v);
        }
        return $out;
    }

    /** Validates admin input. Returns [cleanInput, errors]. */
    public static function validateConfigInput(array $in): array
    {
        $errors = [];
        $out = [];
        $out['domain'] = FreshdeskClient::normalizeDomain((string) ($in['domain'] ?? ''));
        if ($out['domain'] === '') {
            $errors[] = 'Enter your Freshdesk address, e.g. yourcompany.freshdesk.com.';
        }
        $key = trim((string) ($in['api_key'] ?? ''));
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_-]{10,128}$/', $key)) {
            $errors[] = 'The API key is invalid.';
        }
        $out['api_key'] = $key;
        $out['enabled'] = self::bool($in['enabled'] ?? false);

        $settings = [];
        foreach (self::SETTINGS_DEFAULTS as $k => $default) {
            $v = $in[$k] ?? $default;
            if (in_array($k, self::BOOL_SETTINGS, true)) {
                $settings[$k] = self::bool($v);
            } elseif ($k === 'unmatched_calls') {
                $settings[$k] = $v === 'create_contact' ? 'create_contact' : 'skip';
            } elseif ($k === 'ticket_status' || $k === 'missed_ticket_status') {
                $settings[$k] = in_array((int) $v, [2, 3, 4, 5], true) ? (int) $v : $default;
            } elseif ($k === 'ticket_priority') {
                $settings[$k] = in_array((int) $v, [1, 2, 3, 4], true) ? (int) $v : $default;
            } elseif ($k === 'screenpop_target') {
                $settings[$k] = $v === 'window' ? 'window' : 'contact_tab';
            } elseif ($k === 'country_code') {
                $cc = ltrim(trim((string) $v), '+');
                if ($cc !== '' && !preg_match('/^\d{1,4}$/', $cc)) {
                    $errors[] = 'Country calling code must be 1-4 digits.';
                    $cc = '';
                }
                $settings[$k] = $cc;
            } elseif ($k === 'timezone') {
                $zone = trim((string) $v);
                if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                    $errors[] = 'Time zone is invalid.';
                    $zone = $default;
                }
                $settings[$k] = $zone;
            }
        }
        $out['settings'] = $settings;
        return [$out, $errors];
    }

    public function saveConfig(array $clean, string $userUuid): void
    {
        $row = $this->configRow(true);
        $keyEnc = $row['api_key_enc'] ?? null;
        $last4 = $row['key_last4'] ?? null;
        $credentialsChanged = !$row || $clean['domain'] !== (string) ($row['fd_domain'] ?? '');
        if ($clean['api_key'] !== '') {
            $keyEnc = FreshdeskCrypto::encrypt($clean['api_key']);
            $last4 = substr($clean['api_key'], -4);
            $credentialsChanged = true;
        }
        if (empty($keyEnc)) {
            throw new FreshdeskException('API key is required.', 'validation');
        }
        $binds = [
            'd' => $this->domainUuid,
            'enabled' => $clean['enabled'] ? 'true' : 'false',
            'dom' => $clean['domain'],
            'key' => $keyEnc,
            'l4' => $last4,
            'settings' => json_encode($clean['settings']),
            'by' => $userUuid ?: null,
        ];
        if ($row) {
            $sql = 'UPDATE public.q_freshdesk_config SET enabled = CAST(:enabled AS boolean), fd_domain = :dom, api_key_enc = :key,
                       key_last4 = :l4, settings = CAST(:settings AS jsonb), updated_by = :by, updated_at = now()'
                . ($credentialsChanged ? ", connection_status = 'not_configured', last_error = NULL, fd_agent_id = NULL,
                       fd_agent_name = NULL, fd_account_name = NULL, ticket_field_warnings = NULL" : '')
                . ' WHERE domain_uuid = CAST(:d AS uuid)';
        } else {
            $binds['salt'] = bin2hex(random_bytes(16));
            $sql = "INSERT INTO public.q_freshdesk_config
                      (domain_uuid, enabled, fd_domain, api_key_enc, key_last4, settings, connection_status, link_salt, updated_by, created_at, updated_at)
                    VALUES (CAST(:d AS uuid), CAST(:enabled AS boolean), :dom, :key, :l4, CAST(:settings AS jsonb),
                       'not_configured', :salt, :by, now(), now())";
        }
        $this->db->execute($sql, $binds);
        $this->configCache = null;
        $this->forgetAllContactCaches();
    }

    /** Builds a client from stored credentials, optionally overridden by unsaved form values. */
    public function client(array $override = []): FreshdeskClient
    {
        $row = $this->configRow();
        $key = (string) ($override['api_key'] ?? '');
        if ($key === '') {
            if (!$row || empty($row['api_key_enc'])) {
                throw new FreshdeskException('Freshdesk is not configured.', 'not_configured');
            }
            $key = FreshdeskCrypto::decrypt((string) $row['api_key_enc']);
        }
        $domain = (string) (($override['domain'] ?? '') !== '' ? $override['domain'] : ($row['fd_domain'] ?? ''));
        if ($domain === '') {
            throw new FreshdeskException('Freshdesk is not configured.', 'not_configured');
        }
        return new FreshdeskClient($domain, $key);
    }

    /**
     * GET agents/me (auth + who tickets are created as), GET account (name,
     * best effort) and GET ticket_fields (required custom fields that can
     * block ticket creation). Records the outcome on the stored config only when
     * testing the stored key (or exactly the stored domain + key).
     */
    public function testConnection(array $override = []): array
    {
        $row = $this->configRow();
        $usesStored = $row && (($override['api_key'] ?? '') === '')
            && ((($override['domain'] ?? '') === '') || $override['domain'] === $row['fd_domain']);
        try {
            $client = $this->client($override);
            try {
                $me = $client->get('agents/me');
            } catch (FreshdeskException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                }
                // Freshdesk answers 404 for an account name that does not exist (any key).
                $domain = (string) (($override['domain'] ?? '') !== '' ? $override['domain'] : ($row['fd_domain'] ?? ''));
                throw new FreshdeskException('There is no Freshdesk help desk at ' . $domain . '. Sign in to Freshdesk, open the help desk, and copy the yourcompany.freshdesk.com address from the browser (a *.myfreshworks.com sign-in address is not the help desk address).', 'not_found', 404);
            }
            $agentId = isset($me['id']) ? (string) $me['id'] : '';
            $agentName = trim((string) ($me['contact']['name'] ?? ''));
            if ($agentId === '') {
                throw new FreshdeskException('Freshdesk answered, but not with an agent profile. Use an agent\'s API key.', 'validation');
            }
            $accountName = '';
            try {
                $acct = $client->get('account');
                $accountName = trim((string) ($acct['account_name'] ?? ''));
            } catch (FreshdeskException $e) {
                // Needs admin rights on some plans; the name is only informational.
            }
            $warnings = [];
            try {
                $warnings = self::requiredTicketFields($client->get('ticket_fields'));
            } catch (FreshdeskException $e) {
                if (in_array($e->reason, ['auth_failed'], true)) {
                    throw $e;
                }
            }
            if ($usesStored) {
                $this->db->execute(
                    "UPDATE public.q_freshdesk_config SET connection_status = 'connected', last_error = NULL, last_tested_at = now(),
                        fd_agent_id = :aid, fd_agent_name = :an, fd_account_name = :acc, ticket_field_warnings = CAST(:w AS jsonb)
                      WHERE domain_uuid = CAST(:d AS uuid)",
                    ['aid' => $agentId, 'an' => mb_substr($agentName, 0, 120) ?: null, 'acc' => mb_substr($accountName, 0, 120) ?: null,
                        'w' => json_encode($warnings), 'd' => $this->domainUuid]
                );
                $this->configCache = null;
            }
            $who = $agentName !== '' ? ' as ' . $agentName : '';
            $msg = 'Connected to Freshdesk' . ($accountName !== '' ? ' (' . $accountName . ')' : '') . $who . '.';
            if ($warnings) {
                $msg .= ' Note: Freshdesk requires these ticket fields: ' . implode(', ', $warnings) . '.';
            }
            return ['ok' => true, 'message' => $msg];
        } catch (FreshdeskException $e) {
            if ($usesStored) {
                $this->setStatus('error', $e->getMessage());
            }
            return ['ok' => false, 'message' => $e->getMessage(), 'reason' => $e->reason];
        }
    }

    /** Labels of ticket fields an agent must fill (they can make API ticket creation fail). */
    public static function requiredTicketFields(array $fields): array
    {
        $alwaysSet = ['requester', 'subject', 'status', 'priority', 'source', 'description', 'company'];
        $out = [];
        foreach ($fields as $f) {
            if (!is_array($f) || in_array((string) ($f['name'] ?? ''), $alwaysSet, true)) {
                continue;
            }
            $label = trim((string) ($f['label'] ?? $f['name'] ?? ''));
            if ($label === '') {
                continue;
            }
            if (!empty($f['required_for_agents'])) {
                $out[] = $label;
            } elseif (!empty($f['required_for_closure'])) {
                $out[] = $label . ' (when resolving)';
            }
        }
        return array_values(array_unique($out));
    }

    public function setStatus(string $status, ?string $error): void
    {
        $this->db->execute(
            'UPDATE public.q_freshdesk_config SET connection_status = :s, last_error = :e, last_tested_at = now() WHERE domain_uuid = CAST(:d AS uuid)',
            ['s' => $status, 'e' => $error === null ? null : mb_substr($error, 0, 500), 'd' => $this->domainUuid]
        );
        $this->configCache = null;
    }

    /**
     * Removes the API key, disables the integration, revokes every recording
     * link (new link_salt) and every Freshdesk dialer token. Journals are kept.
     */
    public function disconnect(string $userUuid): void
    {
        $this->db->execute(
            "UPDATE public.q_freshdesk_config SET enabled = false, api_key_enc = NULL, key_last4 = NULL,
                connection_status = 'disconnected', last_error = NULL, link_salt = :salt, updated_by = :by, updated_at = now()
             WHERE domain_uuid = CAST(:d AS uuid)",
            ['salt' => bin2hex(random_bytes(16)), 'by' => $userUuid ?: null, 'd' => $this->domainUuid]
        );
        $this->db->execute(
            "UPDATE public.q_freshdesk_call_journals SET next_attempt_at = NULL,
                sync_status = CASE WHEN sync_status = 'pending' THEN 'skipped' ELSE sync_status END
             WHERE domain_uuid = CAST(:d AS uuid) AND next_attempt_at IS NOT NULL",
            ['d' => $this->domainUuid]
        );
        $this->db->execute(
            'UPDATE public.q_freshdesk_cti_tokens SET revoked_at = now() WHERE domain_uuid = CAST(:d AS uuid) AND revoked_at IS NULL',
            ['d' => $this->domainUuid]
        );
        $this->configCache = null;
        $this->forgetAllContactCaches();
    }

    public function isActive(): bool
    {
        $row = $this->configRow();
        return $row && self::bool($row['enabled']) && !empty($row['api_key_enc']) && !empty($row['fd_domain'])
            && $row['connection_status'] !== 'disconnected';
    }

    public function contactUrl(?string $contactId): ?string
    {
        $row = $this->configRow();
        if (!$row || empty($row['fd_domain']) || !$contactId || !preg_match('/^\d{1,20}$/', $contactId)) {
            return null;
        }
        return 'https://' . $row['fd_domain'] . '/a/contacts/' . $contactId;
    }

    public function ticketUrl(?string $ticketId): ?string
    {
        $row = $this->configRow();
        if (!$row || empty($row['fd_domain']) || !$ticketId || !preg_match('/^\d{1,20}$/', $ticketId)) {
            return null;
        }
        return 'https://' . $row['fd_domain'] . '/a/tickets/' . $ticketId;
    }

    // ------------------------------------------------------------------
    // Phone numbers
    // ------------------------------------------------------------------

    public static function digits(string $phone): string
    {
        return (string) preg_replace('/\D+/', '', $phone);
    }

    /** Internal extensions (short numbers) are never looked up or logged in Freshdesk. */
    public static function isExternalNumber(string $phone): bool
    {
        return strlen(self::digits($phone)) >= 6;
    }

    /**
     * True when two numbers are the same subscriber in different formats
     * (+65 9123 4567 / 6591234567 / 91234567 / 091234567): the shorter one,
     * without trunk zeros, must be the tail of the longer one (7+ digits).
     */
    public static function sameNumber(string $a, string $b): bool
    {
        $a = ltrim(self::digits($a), '0');
        $b = ltrim(self::digits($b), '0');
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $short = strlen($a) <= strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;
        return strlen($short) >= 7 && substr($long, -strlen($short)) === $short;
    }

    /**
     * Formats to try against Freshdesk, which matches the stored text exactly.
     * E.164 first (+6591234567), then digits, then the national form.
     */
    public static function phoneVariants(string $phone, string $countryCode = ''): array
    {
        $d = self::digits($phone);
        if (strlen($d) < 6) {
            return [];
        }
        $v = [];
        $cc = self::digits($countryCode);
        if ($cc !== '') {
            if (strpos($d, $cc) === 0 && strlen($d) > strlen($cc) + 5) {
                $national = substr($d, strlen($cc));
                $intl = $d;
            } elseif ($d[0] === '0') {
                $national = ltrim($d, '0');
                $intl = $cc . $national;
            } elseif (strlen($d) <= 10) {
                $national = $d;
                $intl = $cc . $d;
            } else {
                $national = '';
                $intl = $d;
            }
            $v[] = '+' . $intl;
            $v[] = $intl;
            if ($national !== '') {
                $v[] = $national;
                $v[] = '0' . $national;
            }
        } else {
            $v[] = '+' . $d;
            $v[] = $d;
            if ($d[0] === '0') {
                $v[] = ltrim($d, '0');
            }
        }
        $raw = trim($phone);
        if ($raw !== '' && strlen($raw) <= 32 && preg_match('/^[0-9+() .-]+$/', $raw)) {
            $v[] = $raw;
        }
        return array_values(array_unique(array_filter($v, function ($x) { return strlen(self::digits($x)) >= 6; })));
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    public function normalizeContact(array $c, string $companyName = ''): array
    {
        $id = isset($c['id']) ? (string) $c['id'] : '';
        return [
            'id' => $id,
            'name' => trim((string) ($c['name'] ?? '')) ?: 'Unnamed contact',
            'email' => (string) ($c['email'] ?? ''),
            'phone' => (string) ($c['phone'] ?? ''),
            'mobile' => (string) ($c['mobile'] ?? ''),
            'job_title' => (string) ($c['job_title'] ?? ''),
            'company_id' => isset($c['company_id']) && $c['company_id'] !== null ? (string) $c['company_id'] : null,
            'company_name' => $companyName,
            'url' => $this->contactUrl($id),
        ];
    }

    /** Every number stored on a Freshdesk contact. */
    private static function contactNumbers(array $c): array
    {
        $nums = [(string) ($c['phone'] ?? ''), (string) ($c['mobile'] ?? '')];
        foreach ((array) ($c['other_phone_numbers'] ?? []) as $n) {
            if (is_string($n)) {
                $nums[] = $n;
            } elseif (is_array($n) && isset($n['value'])) {
                $nums[] = (string) $n['value'];
            }
        }
        return array_values(array_filter($nums, function ($n) { return $n !== ''; }));
    }

    private function companyName($client, ?string $companyId): string
    {
        if (!$companyId || !preg_match('/^\d{1,20}$/', $companyId)) {
            return '';
        }
        $key = 'freshdesk:company:' . $this->domainUuid . ':' . $companyId;
        try {
            $cached = $this->redis->get($key);
            if (is_string($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
        }
        try {
            $company = $client->get('companies/' . $companyId);
            $name = trim((string) ($company['name'] ?? ''));
        } catch (FreshdeskException $e) {
            if ($e->reason !== 'not_found' && $e->reason !== 'forbidden') {
                return '';
            }
            $name = '';
        }
        try {
            $this->redis->setex($key, 86400, $name);
        } catch (\Throwable $e) {
        }
        return $name;
    }

    /** One contact by id, cached briefly. Null when it does not exist (deleted / merged). */
    public function contactById(string $id, bool $fresh = false): ?array
    {
        if (!preg_match('/^\d{1,20}$/', $id)) {
            return null;
        }
        $key = 'freshdesk:contact:' . $this->domainUuid . ':' . $id;
        if (!$fresh) {
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
        }
        $client = $this->client();
        try {
            $raw = $client->get('contacts/' . $id);
        } catch (FreshdeskException $e) {
            if ($e->reason === 'not_found') {
                return null;
            }
            throw $e;
        }
        $c = $this->normalizeContact($raw, $this->companyName($client, isset($raw['company_id']) ? (string) $raw['company_id'] : null));
        try {
            $this->redis->setex($key, self::CONTACT_CACHE_TTL, json_encode($c));
        } catch (\Throwable $e) {
        }
        return $c;
    }

    /**
     * Contacts whose phone, mobile (or other numbers) are this number.
     * 1. One filter-search request over every format (indexed, may lag a few
     *    minutes for brand-new contacts).
     * 2. If that finds nothing: the live contact list filters ?phone= / ?mobile=
     *    for the two most likely formats.
     * Results are re-checked with sameNumber() and cached.
     *
     * @return array[] normalized contacts (deduplicated by id)
     */
    public function searchByPhone(string $phone, bool $useCache = true): array
    {
        $digits = self::digits($phone);
        if (strlen($digits) < 6) {
            return [];
        }
        $cacheKey = 'freshdesk:contacts:v1:' . $this->domainUuid . ':' . $digits;
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
            }
        }

        $client = $this->client();
        $variants = self::phoneVariants($phone, (string) self::settingsOf($this->configRow())['country_code']);
        $raw = [];
        $keep = function ($items) use (&$raw, $phone) {
            foreach ((array) $items as $c) {
                if (!is_array($c) || !isset($c['id'])) {
                    continue;
                }
                foreach (self::contactNumbers($c) as $n) {
                    if (self::sameNumber($n, $phone)) {
                        $raw[(string) $c['id']] = $c;
                        break;
                    }
                }
            }
        };

        $terms = [];
        foreach ($variants as $v) {
            $q = str_replace("'", '', $v);
            $terms[] = "phone:'" . $q . "'";
            $terms[] = "mobile:'" . $q . "'";
        }
        $query = '"' . implode(' OR ', $terms) . '"';
        if (strlen($query) <= 512) {
            try {
                $res = $client->get('search/contacts', ['query' => $query]);
                $keep($res['results'] ?? []);
            } catch (FreshdeskException $e) {
                if (!in_array($e->reason, ['validation', 'not_found'], true)) {
                    throw $e;
                }
                // Search not available on this account / query rejected: the list filters below still work.
            }
        }
        if (!$raw) {
            foreach (array_slice($variants, 0, 2) as $v) {
                $keep($client->get('contacts', ['phone' => $v]));
                $keep($client->get('contacts', ['mobile' => $v]));
                if ($raw) {
                    break;
                }
            }
        }

        $result = [];
        foreach (array_slice($raw, 0, 10, true) as $c) {
            $result[] = $this->normalizeContact($c, count($raw) <= 3 ? $this->companyName($client, isset($c['company_id']) ? (string) $c['company_id'] : null) : '');
        }
        try {
            $this->redis->setex($cacheKey, $result ? self::CONTACT_CACHE_TTL : self::CONTACT_MISS_TTL, json_encode($result));
            $this->redis->sadd('freshdesk:contactkeys:' . $this->domainUuid, [$cacheKey]);
        } catch (\Throwable $e) {
        }
        return $result;
    }

    /** Free-text contact search (name prefix via autocomplete, or exact email). */
    public function searchByQuery(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            return [];
        }
        $client = $this->client();
        if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
            $items = $client->get('contacts', ['email' => $q]);
            return array_map(function ($c) { return $this->normalizeContact($c); }, array_filter((array) $items, 'is_array'));
        }
        $hits = $client->get('contacts/autocomplete', ['term' => $q]);
        $out = [];
        foreach (array_slice((array) $hits, 0, 5) as $h) {
            if (is_array($h) && isset($h['id'])) {
                $c = $this->contactById((string) $h['id']);
                if ($c) {
                    $out[] = $c;
                }
            }
        }
        return $out;
    }

    public function forgetContactCache(string $phone): void
    {
        try {
            $this->redis->del('freshdesk:contacts:v1:' . $this->domainUuid . ':' . self::digits($phone));
        } catch (\Throwable $e) {
        }
    }

    private function forgetAllContactCaches(): void
    {
        try {
            $set = 'freshdesk:contactkeys:' . $this->domainUuid;
            $keys = (array) $this->redis->smembers($set);
            if ($keys) {
                $this->redis->del($keys);
            }
            $this->redis->del($set);
        } catch (\Throwable $e) {
        }
    }

    /** Input for a contact created for an unknown caller ("Caller +6591234567"). */
    public static function newCallerContactInput(string $phone): array
    {
        $clean = (string) preg_replace('/[^0-9+]/', '', $phone);
        return ['name' => 'Caller ' . $clean, 'phone' => $clean];
    }

    /**
     * Creates a contact unless one already has this number (or email): the
     * existing one is returned instead, so no duplicates are made.
     */
    public function createContact(array $in): array
    {
        $name = trim(strip_tags((string) ($in['name'] ?? '')));
        $email = trim((string) ($in['email'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new FreshdeskException('Name is required (max 255 characters).', 'validation');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new FreshdeskException('Email address is invalid.', 'validation');
        }
        if (!self::isExternalNumber($phone) || strlen($phone) > 32) {
            throw new FreshdeskException('Phone number is invalid.', 'validation');
        }
        $existing = $this->searchByPhone($phone, false);
        if ($existing) {
            return ['contact' => $existing[0], 'created' => false];
        }
        $lockKey = 'freshdesk:create-lock:' . $this->domainUuid . ':' . self::digits($phone);
        try {
            if (!$this->redis->set($lockKey, '1', 'EX', 30, 'NX')) {
                throw new FreshdeskException('This contact is already being created. Try again in a moment.', 'validation');
            }
        } catch (FreshdeskException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Redis unavailable: the search above still guards.
        }
        $client = $this->client();
        $body = ['name' => $name, 'phone' => (string) preg_replace('/[^0-9+]/', '', $phone)];
        if ($email !== '') {
            $body['email'] = $email;
        }
        try {
            $created = $client->post('contacts', $body);
        } catch (FreshdeskException $e) {
            if ($e->reason === 'conflict' && $email !== '') {
                // The email belongs to an existing contact: use it.
                $list = $client->get('contacts', ['email' => $email]);
                if (!empty($list[0]['id'])) {
                    $this->forgetContactCache($phone);
                    return ['contact' => $this->normalizeContact($list[0]), 'created' => false];
                }
            }
            throw $e;
        }
        $this->forgetContactCache($phone);
        return ['contact' => $this->normalizeContact($created), 'created' => true];
    }

    /**
     * The person behind one of this tenant's extensions: the extension's caller ID name and its
     * RingQ user's email (may be ''). Null when the number is not an extension of this tenant.
     */
    public function extensionPerson(string $number): ?array
    {
        $n = trim($number);
        if ($n === '' || strlen($n) > 32 || !preg_match('/^[0-9A-Za-z*#+_-]+$/', $n)) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT e.extension, e.effective_caller_id_name, u.user_email
               FROM public.v_extensions e
               LEFT JOIN public.v_extension_users eu ON eu.extension_uuid = e.extension_uuid
               LEFT JOIN public.v_users u ON u.user_uuid = eu.user_uuid
              WHERE e.domain_uuid = CAST(:d AS uuid) AND (e.extension = :n OR e.number_alias = :n2)
              ORDER BY (u.user_email IS NULL), e.extension LIMIT 1",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'n' => $n, 'n2' => $n]
        );
        if (!$row) {
            return null;
        }
        $email = strtolower(trim((string) ($row['user_email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            $email = '';
        }
        $name = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($row['effective_caller_id_name'] ?? ''))));
        if ($name === '' || ctype_digit(str_replace(' ', '', $name))) {
            $name = 'Extension ' . $row['extension'];
        }
        return ['extension' => (string) $row['extension'], 'name' => mb_substr($name, 0, 200), 'email' => $email];
    }

    /** Freshdesk unique_external_id marking a colleague's contact, e.g. "ringq-ext-1004". */
    public static function extensionExternalId(string $extension): string
    {
        return 'ringq-ext-' . preg_replace('/[^0-9A-Za-z*#+_-]/', '', $extension);
    }

    /**
     * Freshdesk contact of a colleague on an internal extension: the contact with the extension
     * user's email, else the one with unique_external_id "ringq-ext-<extension>", else (when
     * $create) a new contact with name, email and that external id. Freshdesk rejects an
     * extension (1004) as a phone number, so the extension is not put in the phone field.
     * A colleague who is a Freshdesk agent already owns that email; the contact is then
     * created without it (identified by the external id only).
     */
    public function contactForExtension(string $number, bool $create): ?array
    {
        $person = $this->extensionPerson($number);
        if (!$person) {
            return null;
        }
        $key = 'freshdesk:extcontact:' . $this->domainUuid . ':' . $person['extension'] . ':' . md5($person['email']);
        try {
            $cachedId = (string) $this->redis->get($key);
            if ($cachedId !== '' && ($c = $this->contactById($cachedId))) {
                return $c;
            }
        } catch (\Throwable $e) {
        }
        $client = $this->client();
        $found = null;
        if ($person['email'] !== '') {
            $list = (array) $client->get('contacts', ['email' => $person['email']]);
            $found = !empty($list[0]['id']) ? $list[0] : null;
        }
        $externalId = self::extensionExternalId($person['extension']);
        if (!$found) {
            try {
                $list = (array) $client->get('contacts', ['unique_external_id' => $externalId]);
                $found = !empty($list[0]['id']) ? $list[0] : null;
            } catch (FreshdeskException $e) {
                if ($e->reason !== 'validation') {
                    throw $e; // validation: this account has no unique_external_id field
                }
            }
        }
        if (!$found && $create) {
            $body = [
                'name' => $person['name'],
                'unique_external_id' => $externalId,
                'description' => 'RingQ colleague on extension ' . $person['extension'] . '. Calls with this extension are logged here.',
            ];
            if ($person['email'] !== '') {
                $body['email'] = $person['email'];
            }
            $found = $this->createExtensionContact($client, $body);
        }
        if (!$found || empty($found['id'])) {
            return null;
        }
        try {
            $this->redis->setex($key, 86400, (string) $found['id']);
        } catch (\Throwable $e) {
        }
        return $this->normalizeContact($found);
    }

    /**
     * POST contacts for a colleague, dropping what Freshdesk refuses: the email when it belongs
     * to an agent (409), the external id when the account does not accept it (400). Freshdesk
     * needs at least one of them; with neither left the contact cannot be created (null).
     */
    private function createExtensionContact($client, array $body): ?array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (!isset($body['email']) && !isset($body['unique_external_id'])) {
                return null;
            }
            try {
                return $client->post('contacts', $body);
            } catch (FreshdeskException $e) {
                $fields = array_map(function ($x) { return (string) ($x['field'] ?? ''); }, $e->errors);
                if ($e->reason === 'conflict' && isset($body['email'])) {
                    // Email taken (a Freshdesk agent, or a contact we could not list): an existing
                    // contact with it is used; otherwise create without it.
                    $list = (array) $client->get('contacts', ['email' => $body['email']]);
                    if (!empty($list[0]['id'])) {
                        return $list[0];
                    }
                    unset($body['email']);
                } elseif ($e->reason === 'conflict' && in_array('unique_external_id', $fields, true)) {
                    $list = (array) $client->get('contacts', ['unique_external_id' => $body['unique_external_id']]);
                    return !empty($list[0]['id']) ? $list[0] : null;
                } elseif ($e->reason === 'validation' && in_array('unique_external_id', $fields, true)) {
                    unset($body['unique_external_id']);
                } else {
                    throw $e;
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Call journals
    // ------------------------------------------------------------------

    /** Records a dialer lifecycle event. Idempotent per (tenant, local_call_id). */
    public function recordCallEvent(array $in, array $session): array
    {
        $localId = (string) ($in['local_call_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $localId)) {
            throw new FreshdeskException('local_call_id is invalid.', 'validation');
        }
        $event = (string) ($in['event'] ?? '');
        if (!in_array($event, ['started', 'answered', 'ended'], true)) {
            throw new FreshdeskException('event must be started, answered or ended.', 'validation');
        }
        $dirIn = (string) ($in['direction'] ?? '');
        $direction = in_array($dirIn, ['inbound', 'outbound'], true) ? $dirIn : '';
        if ($direction === '') {
            throw new FreshdeskException('direction must be inbound or outbound.', 'validation');
        }
        $remote = mb_substr((string) preg_replace('/[^0-9+*#]/', '', (string) ($in['remote_number'] ?? '')), 0, 32);
        $status = (string) ($in['status'] ?? '');
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new FreshdeskException('status is invalid.', 'validation');
        }
        $origin = ($in['origin'] ?? '') === 'freshdesk_cti' ? 'freshdesk_cti' : 'dialer';
        $ext = mb_substr((string) preg_replace('/[^0-9A-Za-z*#+_-]/', '', (string) ($session['extension'] ?? '')), 0, 32);
        $agentName = mb_substr(trim(((string) ($session['firstname'] ?? '')) . ' ' . ((string) ($session['lastname'] ?? ''))), 0, 120);
        $contactId = isset($in['freshdesk_contact_id']) && preg_match('/^\d{1,20}$/', (string) $in['freshdesk_contact_id']) ? (string) $in['freshdesk_contact_id'] : null;

        $startedAt = self::clampTime($in['started_at'] ?? null);
        $answeredAt = self::clampTime($in['answered_at'] ?? null);
        $endedAt = self::clampTime($in['ended_at'] ?? null);
        $talk = max(0, min(86400, (int) ($in['talk_duration'] ?? 0)));

        $existing = $this->journalByLocalId($localId);
        if ($existing && $existing['user_uuid'] && $existing['user_uuid'] !== ($session['user_uuid'] ?? '')) {
            throw new FreshdeskException('This call belongs to another user.', 'forbidden');
        }

        if (!$existing) {
            $uuid = (new \Phalcon\Security\Random())->uuid();
            // ON CONFLICT makes a retried/duplicated first event a no-op.
            $this->db->execute(
                "INSERT INTO public.q_freshdesk_call_journals
                   (journal_uuid, domain_uuid, local_call_id, user_uuid, agent_extension, agent_name, call_direction, call_origin,
                    caller_number, destination_number, call_status, started_at, freshdesk_contact_id, sync_status, recording_status,
                    retry_count, created_at, updated_at)
                 VALUES (CAST(:u AS uuid), CAST(:d AS uuid), :lid, :usr, :ext, :aname, :dir, :origin,
                    :caller, :dest, 'ringing', COALESCE(CAST(:st AS timestamptz), now()), :cid, 'pending', 'none',
                    0, now(), now())
                 ON CONFLICT (domain_uuid, local_call_id) DO NOTHING",
                [
                    'u' => $uuid, 'd' => $this->domainUuid, 'lid' => $localId,
                    'usr' => ($session['user_uuid'] ?? '') ?: null, 'ext' => $ext ?: null, 'aname' => $agentName ?: null,
                    'dir' => $direction, 'origin' => $origin,
                    'caller' => $direction === 'inbound' ? $remote : $ext,
                    'dest' => $direction === 'inbound' ? $ext : $remote,
                    'st' => $startedAt, 'cid' => $contactId,
                ]
            );
            $existing = $this->journalByLocalId($localId);
        }
        if (!$existing) {
            throw new FreshdeskException('Could not record the call.', 'unavailable');
        }

        // Once a journal reached a final status, later or out-of-order events are ignored.
        if (in_array($existing['call_status'], self::FINAL_STATUSES, true)) {
            return $existing;
        }

        if ($event === 'answered') {
            $this->db->execute(
                "UPDATE public.q_freshdesk_call_journals SET call_status = 'answered',
                    answered_at = COALESCE(answered_at, CAST(:a AS timestamptz), now()),
                    freshdesk_contact_id = COALESCE(:cid, freshdesk_contact_id), updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                ['a' => $answeredAt, 'cid' => $contactId, 'u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
        } elseif ($event === 'ended') {
            $final = in_array($status, self::FINAL_STATUSES, true) ? $status : 'failed';
            $wasAnswered = $existing['answered_at'] !== null || $answeredAt !== null;
            // A call that never reached 'answered' can never be stored as completed.
            if ($final === 'completed' && !$wasAnswered) {
                $final = $direction === 'inbound' ? 'missed' : 'no_answer';
            }
            $settings = self::settingsOf($this->configRow());
            $journaling = $settings['call_journaling'] && $this->isActive();
            $remoteNumber = $direction === 'inbound' ? (string) $existing['caller_number'] : (string) $existing['destination_number'];

            $skip = null;
            if (!$journaling) {
                $skip = 'Call journaling is off.';
            } elseif (!self::isExternalNumber($remoteNumber) && (!$settings['log_internal_calls'] || $this->extensionPerson($remoteNumber) === null)) {
                $skip = $settings['log_internal_calls']
                    ? 'Internal number ' . $remoteNumber . ' is not an extension: not logged in Freshdesk.'
                    : 'Internal call between extensions: not logged in Freshdesk (turned off in Freshdesk settings).';
            } elseif ($final !== 'completed' && !$settings['log_unanswered']) {
                $skip = 'Unanswered calls are not logged (Freshdesk settings).';
            } elseif (!empty($in['answered_elsewhere'])) {
                $skip = 'Answered on another RingQ device; logged from there.';
            } elseif ($direction === 'inbound' && $final !== 'completed' && $this->siblingTakesOver($existing)) {
                $skip = 'The same call rang on another RingQ device of this agent; logged once from there.';
            }
            $delayed = $skip === null && $direction === 'inbound' && $final !== 'completed';

            $this->db->execute(
                "UPDATE public.q_freshdesk_call_journals SET call_status = :s,
                    answered_at = CASE WHEN :ans1 = 'y' THEN COALESCE(answered_at, CAST(:a AS timestamptz)) ELSE NULL END,
                    ended_at = COALESCE(CAST(:e AS timestamptz), now()),
                    talk_duration = CAST(:talk AS integer),
                    freshdesk_contact_id = COALESCE(:cid, freshdesk_contact_id),
                    recording_status = CASE WHEN :ans2 = 'y' THEN 'waiting' ELSE 'none' END,
                    sync_status = :sync, last_error = :err,
                    next_attempt_at = CASE WHEN :sync2 = 'pending' THEN now() + CAST(:delay AS integer) * interval '1 second' ELSE NULL END,
                    updated_at = now()
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
                [
                    's' => $final, 'ans1' => $wasAnswered ? 'y' : 'n', 'a' => $answeredAt, 'e' => $endedAt,
                    'talk' => $final === 'completed' ? $talk : 0, 'cid' => $contactId,
                    'ans2' => $final === 'completed' && $skip === null ? 'y' : 'n',
                    'sync' => $skip === null ? 'pending' : 'skipped', 'sync2' => $skip === null ? 'pending' : 'skipped',
                    'err' => $skip, 'delay' => $delayed ? self::MISSED_SYNC_DELAY : 0,
                    'u' => $existing['journal_uuid'], 'd' => $this->domainUuid,
                ]
            );
            $this->db->execute(
                'UPDATE public.q_freshdesk_call_journals SET duration = GREATEST(0, CAST(EXTRACT(EPOCH FROM (ended_at - started_at)) AS integer))
                 WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                ['u' => $existing['journal_uuid'], 'd' => $this->domainUuid]
            );
            if ($direction === 'inbound' && $final === 'completed') {
                $this->skipUnansweredSiblings($existing);
            }
        }
        return $this->journalByLocalId($localId);
    }

    /** Copies of the same inbound call on the agent's other devices (other local_call_id). */
    private function siblings(array $j): array
    {
        // Short tails too: a call from an extension (1004) ringing on several devices.
        $tail = substr(self::digits((string) $j['caller_number']), -9);
        if (strlen($tail) < 2 || (empty($j['user_uuid']) && empty($j['agent_extension']))) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM public.q_freshdesk_call_journals
              WHERE domain_uuid = CAST(:d AS uuid) AND local_call_id <> :lid AND call_direction = 'inbound'
                AND (user_uuid = :usr OR agent_extension = :ext)
                AND right(regexp_replace(COALESCE(caller_number, ''), '\\D', '', 'g'), 9) = :t
                AND started_at BETWEEN CAST(:st AS timestamptz) - CAST(:w1 AS integer) * interval '1 second'
                                   AND CAST(:st2 AS timestamptz) + CAST(:w2 AS integer) * interval '1 second'",
            Enum::FETCH_ASSOC,
            [
                'd' => $this->domainUuid, 'lid' => $j['local_call_id'], 'usr' => (string) ($j['user_uuid'] ?? ''),
                'ext' => (string) ($j['agent_extension'] ?? ''), 't' => $tail,
                'st' => $j['started_at'], 'st2' => $j['started_at'], 'w1' => self::SIBLING_WINDOW, 'w2' => self::SIBLING_WINDOW,
            ]
        );
        return $rows ?: [];
    }

    /**
     * An unanswered copy is not logged when another copy was answered, or when
     * an earlier unanswered copy is already being logged (one missed call, once).
     */
    private function siblingTakesOver(array $j): bool
    {
        foreach ($this->siblings($j) as $s) {
            if (in_array($s['call_status'], ['answered', 'completed'], true)) {
                return true;
            }
            if (in_array($s['call_status'], self::FINAL_STATUSES, true) && $s['sync_status'] !== 'skipped') {
                return true;
            }
        }
        return false;
    }

    /** The answered copy wins: unanswered copies not yet in Freshdesk are skipped. */
    private function skipUnansweredSiblings(array $j): void
    {
        foreach ($this->siblings($j) as $s) {
            if ($s['call_status'] !== 'completed' && in_array($s['call_status'], self::FINAL_STATUSES, true)
                && empty($s['freshdesk_ticket_id']) && empty($s['create_attempted_at'])) {
                $this->updateJournal((string) $s['journal_uuid'], [
                    'sync_status' => 'skipped', 'next_attempt_at' => null,
                    'last_error' => 'Answered on another RingQ device; logged from there.',
                ]);
            }
        }
    }

    public function journalByLocalId(string $localId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM public.q_freshdesk_call_journals WHERE domain_uuid = CAST(:d AS uuid) AND local_call_id = :lid',
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
        $total = (int) ($this->db->fetchOne("SELECT count(*) AS n FROM public.q_freshdesk_call_journals WHERE $where", Enum::FETCH_ASSOC, $binds)['n'] ?? 0);
        $binds['lim'] = $perPage;
        $binds['off'] = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT * FROM public.q_freshdesk_call_journals WHERE $where ORDER BY started_at DESC LIMIT CAST(:lim AS integer) OFFSET CAST(:off AS integer)",
            Enum::FETCH_ASSOC,
            $binds
        );
        return ['journals' => array_map([$this, 'publicJournal'], $rows ?: []), 'total' => $total, 'page' => $page];
    }

    public function publicJournal(array $j): array
    {
        return [
            'local_call_id' => $j['local_call_id'],
            'call_ref' => self::callRef((string) $j['local_call_id']),
            'freshdesk_contact_id' => $j['freshdesk_contact_id'],
            'freshdesk_ticket_id' => $j['freshdesk_ticket_id'],
            'contact_url' => $this->contactUrl($j['freshdesk_contact_id'] ?? null),
            'ticket_url' => $this->ticketUrl($j['freshdesk_ticket_id'] ?? null),
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
            'disposition' => $j['disposition'],
            'recording_status' => $j['recording_status'],
            'recording_available' => in_array($j['recording_status'], ['uploaded', 'link_only'], true),
            'sync_status' => $j['sync_status'],
            'retry_count' => (int) $j['retry_count'],
            'last_error' => $j['last_error'],
        ];
    }

    /** Manual retry from the UI: clears the backoff and syncs now. */
    public function retryJournal(array $j): array
    {
        $this->db->execute(
            "UPDATE public.q_freshdesk_call_journals SET sync_status = CASE WHEN sync_status = 'synced' THEN 'synced' ELSE 'pending' END,
                retry_count = 0, next_attempt_at = now(), last_error = NULL,
                recording_status = CASE WHEN recording_status = 'unavailable' AND call_status = 'completed' THEN 'waiting' ELSE recording_status END
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid) AND ended_at IS NOT NULL",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        $this->syncJournal((string) $j['journal_uuid']);
        return $this->journalByLocalId((string) $j['local_call_id']);
    }

    /** Agent notes + disposition from the popup. Written into the ticket (as a private note once it exists). */
    public function saveNotes(array $j, string $notes, string $disposition): array
    {
        $notes = mb_substr(trim(strip_tags($notes)), 0, 5000);
        $disposition = mb_substr(trim(strip_tags($disposition)), 0, 40);
        $this->db->execute(
            "UPDATE public.q_freshdesk_call_journals SET notes = :n, disposition = :disp,
                notes_dirty = CASE WHEN freshdesk_ticket_id IS NOT NULL OR create_attempted_at IS NOT NULL THEN true ELSE false END,
                next_attempt_at = CASE WHEN ended_at IS NOT NULL AND sync_status <> 'skipped' THEN now() ELSE next_attempt_at END,
                updated_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['n' => $notes !== '' ? $notes : null, 'disp' => $disposition !== '' ? $disposition : null, 'u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        $this->syncJournal((string) $j['journal_uuid']);
        return $this->journalByLocalId((string) $j['local_call_id']);
    }

    /**
     * The agent picked (or created) the contact for a call. A call that was
     * skipped only because nobody matched is logged now; a ticket that already
     * exists is moved to this contact.
     */
    public function linkContact(array $j, string $contactId): array
    {
        $contact = $this->contactById($contactId, true);
        if (!$contact) {
            throw new FreshdeskException('That Freshdesk contact does not exist (deleted or merged).', 'not_found');
        }
        $retryable = $j['sync_status'] === 'skipped' && empty($j['freshdesk_ticket_id'])
            && is_string($j['last_error']) && strpos($j['last_error'], 'contact') !== false;
        $this->db->execute(
            "UPDATE public.q_freshdesk_call_journals SET freshdesk_contact_id = :c,
                sync_status = CASE WHEN :r = 'y' THEN 'pending' ELSE sync_status END,
                last_error = CASE WHEN :r2 = 'y' THEN NULL ELSE last_error END,
                recording_status = CASE WHEN :r3 = 'y' AND call_status = 'completed' AND recording_status = 'none' THEN 'waiting' ELSE recording_status END,
                next_attempt_at = CASE WHEN ended_at IS NOT NULL THEN now() ELSE next_attempt_at END, updated_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['c' => $contactId, 'r' => $retryable ? 'y' : 'n', 'r2' => $retryable ? 'y' : 'n', 'r3' => $retryable ? 'y' : 'n',
                'u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
        if (!empty($j['freshdesk_ticket_id']) && (string) $j['freshdesk_contact_id'] !== $contactId) {
            $this->client()->put('tickets/' . $j['freshdesk_ticket_id'], ['requester_id' => (int) $contactId]);
        }
        $this->syncJournal((string) $j['journal_uuid']);
        return $this->journalByLocalId((string) $j['local_call_id']);
    }

    /**
     * Pushes one ended call to Freshdesk: one ticket (created once), the agent
     * notes and the recording. Safe to call concurrently and repeatedly: a row
     * lock serialises workers, create_attempted_at / recording_attempted_at are
     * stored BEFORE each create so a crash in between is recovered by looking
     * the record up instead of creating a second one.
     */
    public function syncJournal(string $journalUuid): void
    {
        $j = $this->db->fetchOne(
            "UPDATE public.q_freshdesk_call_journals SET sync_locked_at = now()
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
            if (!$this->isActive() || $j['sync_status'] === 'skipped') {
                $update['next_attempt_at'] = null;
                if ($j['sync_status'] === 'pending') {
                    $update['sync_status'] = 'skipped';
                    $update['last_error'] = 'Freshdesk integration is not enabled.';
                }
                $this->updateJournal($journalUuid, $update);
                return;
            }

            // 1. Recording (answered calls only): find the CDR that holds it.
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

            // 2. Contact.
            $contact = null;
            if (!empty($j['freshdesk_contact_id'])) {
                $contact = $this->contactById((string) $j['freshdesk_contact_id']);
                if (!$contact) {
                    $update['freshdesk_contact_id'] = null;
                    $j['freshdesk_contact_id'] = null;
                }
            }
            if (!$contact && empty($j['freshdesk_ticket_id'])) {
                $remote = $j['call_direction'] === 'inbound' ? (string) $j['caller_number'] : (string) $j['destination_number'];
                $colleague = !self::isExternalNumber($remote) && $settings['log_internal_calls']
                    ? $this->contactForExtension($remote, true) : null;
                $matches = $colleague ? [$colleague] : (self::isExternalNumber($remote) ? $this->searchByPhone($remote) : []);
                if ($colleague) {
                    $contact = $colleague;
                } elseif (!self::isExternalNumber($remote)) {
                    $update['sync_status'] = 'skipped';
                    $update['next_attempt_at'] = null;
                    $update['last_error'] = 'Extension ' . $remote . ' has no RingQ user to log the call on.';
                    $this->updateJournal($journalUuid, $update);
                    return;
                } elseif (count($matches) === 1) {
                    $contact = $matches[0];
                } elseif (!$matches && $settings['unmatched_calls'] === 'create_contact') {
                    $contact = $this->createContact(self::newCallerContactInput($remote))['contact'];
                } else {
                    // Never guess: an unknown or shared number is logged only after the agent picks the contact.
                    $update['sync_status'] = 'skipped';
                    $update['next_attempt_at'] = null;
                    $update['last_error'] = $matches
                        ? count($matches) . ' Freshdesk contacts have this number; pick the contact in the call popup to log the call.'
                        : 'No Freshdesk contact has this number; pick or create the contact in the call popup to log the call.';
                    $this->updateJournal($journalUuid, $update);
                    return;
                }
                $update['freshdesk_contact_id'] = (string) $contact['id'];
                $j['freshdesk_contact_id'] = (string) $contact['id'];
            }

            $client = $this->client();

            // 3. Ticket (create once).
            if (empty($j['freshdesk_ticket_id'])) {
                $ticketId = $this->ensureTicket($client, $j, $contact, $settings);
                $j['freshdesk_ticket_id'] = $ticketId;
                $j['notes_dirty'] = false;
                $update['notes_dirty'] = false;
            }

            // 4. Notes typed after the ticket was created.
            if (self::bool($j['notes_dirty'])) {
                $noteId = $this->pushNotes($client, $j);
                $update['notes_note_id'] = $noteId;
                $update['notes_dirty'] = false;
            }

            // 5. Recording note (file upload, or link).
            $recordingError = null;
            if ($j['recording_status'] === 'linked' && empty($j['recording_note_id'])) {
                $rec = $this->attachRecording($client, $j, $settings);
                $recordingError = $rec['last_error'] ?? null;
                $update = array_merge($update, $rec);
                $j = array_merge($j, $rec);
            }

            $update['sync_status'] = 'synced';
            $update['last_error'] = $recordingError;
            $update['retry_count'] = 0;
            $update['synced_at'] = date('c');
            $this->db->execute('UPDATE public.q_freshdesk_config SET last_sync_at = now() WHERE domain_uuid = CAST(:d AS uuid)', ['d' => $this->domainUuid]);

            // Recording still coming: check again soon (cron polls every few seconds).
            $stillWaiting = $j['recording_status'] === 'waiting'
                || ($j['recording_status'] === 'linked' && empty($j['recording_note_id']));
            $update['next_attempt_at'] = $stillWaiting ? date('c', time() + 5) : null;
        } catch (FreshdeskException $e) {
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
            error_log('[freshdesk] sync error ' . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300));
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

    /** Creates the call ticket, or recovers the one an interrupted earlier attempt created. Returns its id. */
    private function ensureTicket($client, array $j, array $contact, array $settings): string
    {
        if (!empty($j['create_attempted_at'])) {
            $found = $this->recoverTicket($client, $j);
            if ($found !== null) {
                $this->updateJournal((string) $j['journal_uuid'], ['freshdesk_ticket_id' => $found, 'freshdesk_contact_id' => $j['freshdesk_contact_id']]);
                return $found;
            }
        }
        // Persist the attempt BEFORE creating, so a crash right after is recovered, not duplicated.
        $this->updateJournal((string) $j['journal_uuid'], ['create_attempted_at' => date('c'), 'freshdesk_contact_id' => $j['freshdesk_contact_id']]);

        $answered = $j['call_status'] === 'completed';
        $status = (int) ($answered ? $settings['ticket_status'] : $settings['missed_ticket_status']);
        $body = $this->ticketBody($j, $contact, $settings, $status, null);
        try {
            $ticket = $client->post('tickets', $body);
        } catch (FreshdeskException $e) {
            // Mandatory "required when closing" fields reject Resolved/Closed tickets: create it Open instead.
            if ($e->reason !== 'validation' || !$e->hasMissingField() || $status === self::STATUS_OPEN) {
                throw $e;
            }
            $body = $this->ticketBody($j, $contact, $settings, self::STATUS_OPEN,
                'Created as Open because Freshdesk requires fields RingQ cannot fill to resolve or close a ticket.');
            $ticket = $client->post('tickets', $body);
        }
        if (empty($ticket['id'])) {
            throw new FreshdeskException('Freshdesk did not return a ticket id.', 'unavailable');
        }
        $id = (string) $ticket['id'];
        // Stored immediately: every later run only adds notes to this ticket.
        $this->updateJournal((string) $j['journal_uuid'], ['freshdesk_ticket_id' => $id, 'notes_dirty' => false]);
        return $id;
    }

    /** Finds the ticket of this call by its reference (subject / tag) after an interrupted create. */
    private function recoverTicket($client, array $j): ?string
    {
        $ref = self::callRef((string) $j['local_call_id']);
        if (!empty($j['freshdesk_contact_id'])) {
            $since = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $j['create_attempted_at']) - 900);
            $list = $client->get('tickets', ['requester_id' => $j['freshdesk_contact_id'], 'updated_since' => $since, 'order_by' => 'created_at', 'per_page' => 100]);
            foreach ((array) $list as $t) {
                if (is_array($t) && isset($t['id']) && stripos((string) ($t['subject'] ?? ''), $ref) !== false) {
                    return (string) $t['id'];
                }
            }
        }
        try {
            $res = $client->get('search/tickets', ['query' => '"tag:\'' . strtolower($ref) . '\'"']);
            foreach ((array) ($res['results'] ?? []) as $t) {
                if (is_array($t) && isset($t['id'])) {
                    return (string) $t['id'];
                }
            }
        } catch (FreshdeskException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
        }
        return null;
    }

    private static function statusLabel(string $s): string
    {
        $labels = [
            'completed' => 'Answered', 'missed' => 'Missed', 'rejected' => 'Rejected', 'busy' => 'Busy',
            'no_answer' => 'No answer', 'failed' => 'Failed', 'ringing' => 'Ringing', 'answered' => 'In call',
        ];
        return $labels[$s] ?? ucfirst($s);
    }

    private function localTime(?string $v, string $timezone): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $d = new \DateTime((string) $v);
        $d->setTimezone($tz);
        return $d->format('d M Y, H:i:s T');
    }

    /** POST /tickets body for a call (subject, HTML description, Phone source, tags with the call reference). */
    public function ticketBody(array $j, ?array $contact, array $settings, int $status, ?string $remark): array
    {
        $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $inbound = $j['call_direction'] === 'inbound';
        $remote = $inbound ? (string) $j['caller_number'] : (string) $j['destination_number'];
        $statusLabel = self::statusLabel((string) $j['call_status']);
        $ref = self::callRef((string) $j['local_call_id']);
        $tz = (string) $settings['timezone'];
        $contactName = $contact ? trim((string) ($contact['name'] ?? '')) : '';
        $agentName = trim((string) ($j['agent_name'] ?? ''));
        $agent = trim($agentName . (!empty($j['agent_extension']) ? ' (ext ' . $j['agent_extension'] . ')' : ''));
        $internal = !self::isExternalNumber($remote);
        $remoteLabel = $internal ? 'Extension ' . $remote : $remote;

        $lines = [
            'Direction' => ($inbound ? 'Inbound' : 'Outbound') . ($internal ? ' (internal call between extensions)' : ''),
            'Call status' => $statusLabel,
            'Disposition' => $j['disposition'] ?: '-',
            'Caller number' => $inbound ? $remoteLabel : ($j['agent_extension'] ? 'Extension ' . $j['agent_extension'] : '-'),
            'Recipient number' => $inbound ? ($j['agent_extension'] ? 'Extension ' . $j['agent_extension'] : '-') : $remoteLabel,
            'Contact' => $contactName !== '' ? $contactName : '-',
            'Agent' => $agent !== '' ? $agent : '-',
            'Started' => $this->localTime($j['started_at'], $tz),
            'Answered' => $this->localTime($j['answered_at'], $tz),
            'Ended' => $this->localTime($j['ended_at'], $tz),
            'Duration' => self::fmtDuration((int) $j['duration']),
            'Talk time' => self::fmtDuration((int) $j['talk_duration']),
            'RingQ call ID' => (string) $j['local_call_id'],
            'Call reference' => $ref,
        ];
        $html = '<p><strong>RingQ ' . $h($inbound ? 'inbound' : 'outbound') . ' call</strong></p>';
        foreach ($lines as $k => $v) {
            $html .= '<p><strong>' . $h($k) . ':</strong> ' . $h($v) . '</p>';
        }
        if (in_array($j['recording_status'], ['waiting', 'linked'], true)) {
            $html .= '<p><strong>Recording:</strong> added to this ticket as a private note when it is ready.</p>';
        } elseif ($j['call_status'] === 'completed' && $j['recording_status'] === 'none') {
            $html .= '<p><strong>Recording:</strong> none.</p>';
        }
        if (!empty($j['notes'])) {
            $html .= '<p><strong>Agent notes:</strong></p><p>' . nl2br($h($j['notes'])) . '</p>';
        }
        if ($remark) {
            $html .= '<p><em>' . $h($remark) . '</em></p>';
        }

        $talk = (int) $j['talk_duration'];
        $subject = ($inbound ? 'Inbound call from ' : 'Outbound call to ')
            . ($contactName !== '' ? $contactName . ' (' . ($internal ? 'ext ' : '') . $remote . ')' : $remoteLabel)
            . ' - ' . $statusLabel . ($talk > 0 ? ' ' . self::fmtShort($talk) : '')
            . ' - ' . $ref;

        return [
            'subject' => mb_substr($subject, 0, 255),
            'description' => $html,
            'requester_id' => (int) $j['freshdesk_contact_id'],
            'source' => self::SOURCE_PHONE,
            'status' => $status,
            'priority' => (int) $settings['ticket_priority'],
            'tags' => array_values(array_filter(['RingQ', 'ringq-call', strtolower($ref), $inbound ? 'inbound-call' : 'outbound-call', $internal ? 'internal-call' : null])),
        ];
    }

    /** Agent notes as one private note on the ticket (updated in place when edited again). */
    private function pushNotes($client, array $j): ?string
    {
        $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $body = '<p><strong>Agent notes</strong> - ' . $h(self::callRef((string) $j['local_call_id'])) . '</p>'
            . '<p><strong>Disposition:</strong> ' . $h($j['disposition'] ?: '-') . '</p>'
            . '<p>' . nl2br($h($j['notes'] ?: '-')) . '</p>';
        if (!empty($j['notes_note_id'])) {
            try {
                $client->put('conversations/' . $j['notes_note_id'], ['body' => $body]);
                return (string) $j['notes_note_id'];
            } catch (FreshdeskException $e) {
                if ($e->reason !== 'not_found') {
                    throw $e;
                }
            }
        }
        $note = $client->post('tickets/' . $j['freshdesk_ticket_id'] . '/notes', ['body' => $body, 'private' => true]);
        return isset($note['id']) ? (string) $note['id'] : null;
    }

    /**
     * Adds the recording to the ticket as a private note: the audio file
     * attached when allowed and small enough, otherwise a signed RingQ link.
     * Returns the journal fields to update. Success is only reported when
     * Freshdesk confirmed the note (and, for uploads, the attachment).
     */
    private function attachRecording($client, array $j, array $settings): array
    {
        $ref = self::callRef((string) $j['local_call_id']);
        if (!empty($j['recording_attempted_at'])) {
            $found = $this->recoverRecordingNote($client, $j, $ref);
            if ($found) {
                return $found;
            }
        }
        $file = $this->recordingFile($j);
        if (!$file) {
            // The CDR names a file PHP cannot read (yet): retry for a while, then give up honestly.
            if (time() - (int) strtotime((string) $j['ended_at']) > self::RECORDING_WAIT_SECONDS) {
                return ['recording_status' => 'unavailable', 'last_error' => 'The call recording file could not be read on the RingQ server.'];
            }
            return [];
        }
        $this->updateJournal((string) $j['journal_uuid'], ['recording_attempted_at' => date('c')]);

        $h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $inbound = $j['call_direction'] === 'inbound';
        $remote = $inbound ? (string) $j['caller_number'] : (string) $j['destination_number'];
        if (!self::isExternalNumber($remote)) {
            $remote = 'Extension ' . $remote;
        }
        $intro = '<p><strong>RingQ recording</strong> - ' . $h($ref) . '</p>'
            . '<p>' . $h(($inbound ? 'Inbound call from ' : 'Outbound call to ') . $remote . ', talk time ' . self::fmtDuration((int) $j['talk_duration'])) . '</p>';

        $tooBig = $file['size'] > self::MAX_ATTACHMENT_BYTES;
        $upload = $settings['recording_upload'] && !$tooBig;
        $link = ($settings['recording_link'] || !$upload) ? $this->recordingUrl($j) : null;
        $linkHtml = $link ? '<p><a href="' . $h($link) . '" target="_blank" rel="noopener noreferrer">Play recording</a> (RingQ link)</p>' : '';

        if ($upload) {
            try {
                $note = $client->postMultipart('tickets/' . $j['freshdesk_ticket_id'] . '/notes', ['body' => $intro . $linkHtml, 'private' => true], $file);
                if (!empty($note['id']) && !empty($note['attachments'])) {
                    return ['recording_note_id' => (string) $note['id'], 'recording_status' => 'uploaded'];
                }
                if (!empty($note['id'])) {
                    // Note saved but Freshdesk kept no file: say so instead of reporting an upload.
                    return ['recording_note_id' => (string) $note['id'], 'recording_status' => $link ? 'link_only' : 'unavailable',
                        'last_error' => 'Freshdesk saved the recording note without the audio file.'];
                }
                throw new FreshdeskException('Freshdesk did not return the recording note.', 'unavailable');
            } catch (FreshdeskException $e) {
                // Attachment refused (size / type / ticket limit): fall back to a link note.
                if ($e->reason !== 'validation') {
                    throw $e;
                }
                $link = $link ?: $this->recordingUrl($j);
                $linkHtml = $link ? '<p><a href="' . $h($link) . '" target="_blank" rel="noopener noreferrer">Play recording</a> (RingQ link)</p>' : '';
                $intro .= '<p><em>Freshdesk did not accept the audio file, so it is linked instead.</em></p>';
            }
        } elseif ($tooBig) {
            $intro .= '<p><em>The recording is larger than ' . (self::MAX_ATTACHMENT_BYTES / 1048576) . ' MB, so it is linked instead of attached.</em></p>';
        }
        if (!$link) {
            return ['recording_status' => 'unavailable', 'last_error' => 'The recording could not be attached and no RingQ link could be built (tenant host unknown).'];
        }
        $note = $client->post('tickets/' . $j['freshdesk_ticket_id'] . '/notes', ['body' => $intro . $linkHtml, 'private' => true]);
        if (empty($note['id'])) {
            throw new FreshdeskException('Freshdesk did not return the recording note.', 'unavailable');
        }
        return ['recording_note_id' => (string) $note['id'], 'recording_status' => 'link_only'];
    }

    /** After an interrupted upload: the recording note already on the ticket, if any. */
    private function recoverRecordingNote($client, array $j, string $ref): ?array
    {
        $list = $client->get('tickets/' . $j['freshdesk_ticket_id'] . '/conversations', ['per_page' => 100]);
        foreach ((array) $list as $c) {
            if (!is_array($c) || !isset($c['id'])) {
                continue;
            }
            $text = (string) ($c['body_text'] ?? '') . ' ' . (string) ($c['body'] ?? '');
            if (stripos($text, 'RingQ recording') !== false && stripos($text, $ref) !== false) {
                return ['recording_note_id' => (string) $c['id'], 'recording_status' => !empty($c['attachments']) ? 'uploaded' : 'link_only'];
            }
        }
        return null;
    }

    private function updateJournal(string $journalUuid, array $fields): void
    {
        $allowed = ['sync_locked_at', 'next_attempt_at', 'sync_status', 'xml_cdr_uuid', 'recording_status', 'recording_note_id',
            'recording_attempted_at', 'freshdesk_contact_id', 'freshdesk_ticket_id', 'create_attempted_at', 'notes_dirty',
            'notes_note_id', 'last_error', 'retry_count', 'synced_at'];
        $sets = [];
        $binds = ['u' => $journalUuid, 'd' => $this->domainUuid];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) {
                continue;
            }
            $cast = in_array($k, ['sync_locked_at', 'next_attempt_at', 'synced_at', 'create_attempted_at', 'recording_attempted_at'], true) ? 'timestamptz'
                : ($k === 'xml_cdr_uuid' ? 'uuid' : ($k === 'retry_count' ? 'integer' : ($k === 'notes_dirty' ? 'boolean' : null)));
            if ($k === 'notes_dirty') {
                $v = $v ? 'true' : 'false';
            }
            $sets[] = $cast ? "$k = CAST(:f_$k AS $cast)" : "$k = :f_$k";
            $binds['f_' . $k] = $v;
        }
        if (!$sets) {
            return;
        }
        $this->db->execute(
            'UPDATE public.q_freshdesk_call_journals SET ' . implode(', ', $sets) . ', updated_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
            $binds
        );
    }

    /** Short unique reference used in the ticket subject and tags, e.g. "RQ1a2b3c4d". */
    public static function callRef(string $localCallId): string
    {
        return 'RQ' . substr(strtolower((string) preg_replace('/[^0-9a-f]/i', '', $localCallId)), 0, 10);
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

    /** The recording file of a linked journal: only files under the FreeSWITCH recordings tree. */
    private function recordingFile(array $j): ?array
    {
        if (empty($j['xml_cdr_uuid'])) {
            return null;
        }
        $cdr = $this->db->fetchOne(
            'SELECT record_path, record_name FROM public.q_ringq_cdr WHERE domain_uuid = CAST(:d AS uuid) AND xml_cdr_uuid = CAST(:c AS uuid)',
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'c' => $j['xml_cdr_uuid']]
        );
        return $cdr ? self::safeRecordingPath((string) $cdr['record_path'], (string) $cdr['record_name']) : null;
    }

    private static function safeRecordingPath(string $dir, string $name): ?array
    {
        $path = realpath(rtrim($dir, '/') . '/' . basename($name));
        if ($path === false || strpos($path, '/var/lib/freeswitch/recordings/') !== 0 || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return [
            'path' => $path,
            'name' => 'call-recording.' . ($ext === 'mp3' ? 'mp3' : 'wav'),
            'mime' => $ext === 'mp3' ? 'audio/mpeg' : 'audio/wav',
            'size' => $size,
        ];
    }

    /** Signed, tenant-bound link served by FreshdeskcrmController::recordingAction. */
    public function recordingUrl(array $j): ?string
    {
        $row = $this->configRow();
        if (!$row || empty($row['link_salt']) || $this->subdomain === '') {
            return null;
        }
        $exp = self::RECORDING_LINK_DAYS > 0 ? time() + self::RECORDING_LINK_DAYS * 86400 : 0;
        $sig = FreshdeskCrypto::sign($j['journal_uuid'] . '|' . $exp . '|' . $row['link_salt']);
        return 'https://' . $this->subdomain . ':8443/freshdeskcrm/recording/' . $j['journal_uuid'] . '/' . $exp . '/' . $sig;
    }

    /** Validates a signed recording link; returns the file or null. */
    public static function resolveSignedRecording($db, string $journalUuid, string $exp, string $sig): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $journalUuid) || !ctype_digit($exp) || ((int) $exp !== 0 && (int) $exp < time()) || !preg_match('/^[A-Za-z0-9_-]{20,64}$/', $sig)) {
            return null;
        }
        $row = $db->fetchOne(
            "SELECT j.journal_uuid, c.link_salt, c.api_key_enc, cdr.record_path, cdr.record_name
               FROM public.q_freshdesk_call_journals j
               JOIN public.q_freshdesk_config c ON c.domain_uuid = j.domain_uuid
               JOIN public.q_ringq_cdr cdr ON cdr.xml_cdr_uuid = j.xml_cdr_uuid AND cdr.domain_uuid = j.domain_uuid
              WHERE j.journal_uuid = CAST(:u AS uuid) AND j.xml_cdr_uuid IS NOT NULL",
            Enum::FETCH_ASSOC,
            ['u' => $journalUuid]
        );
        // A disconnected integration (no key) serves nothing, even for a correctly signed link.
        if (!$row || empty($row['link_salt']) || empty($row['api_key_enc'])) {
            return null;
        }
        if (!FreshdeskCrypto::verify($row['journal_uuid'] . '|' . $exp . '|' . $row['link_salt'], $sig)) {
            return null;
        }
        return self::safeRecordingPath((string) $row['record_path'], (string) $row['record_name']);
    }

    /** Manual recording re-sync from POST freshdeskcrm/recordingsSync. */
    public function requeueRecording(array $j): void
    {
        $this->db->execute(
            "UPDATE public.q_freshdesk_call_journals SET
                recording_status = CASE WHEN call_status = 'completed' AND recording_status IN ('none', 'unavailable') THEN 'waiting' ELSE recording_status END,
                next_attempt_at = now()
             WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)",
            ['u' => $j['journal_uuid'], 'd' => $this->domainUuid]
        );
    }

    /**
     * Post-hangup follow-up in a separate CLI process (FreshdeskcrmTask::afterAction)
     * so no web worker is held: saves the ticket, then adds the recording as soon
     * as the CDR exists (checked every 2 s for up to ~2 minutes; cron continues after).
     */
    public function followUp(string $journalUuid): void
    {
        for ($i = 0; $i < 60; $i++) {
            $j = $this->db->fetchOne(
                'SELECT * FROM public.q_freshdesk_call_journals WHERE journal_uuid = CAST(:u AS uuid) AND domain_uuid = CAST(:d AS uuid)',
                Enum::FETCH_ASSOC,
                ['u' => $journalUuid, 'd' => $this->domainUuid]
            );
            if (!$j || in_array($j['sync_status'], ['failed', 'skipped'], true)) {
                return;
            }
            $done = $j['sync_status'] === 'synced' && !self::bool($j['notes_dirty'])
                && (in_array($j['recording_status'], self::RECORDING_DONE, true) || !empty($j['recording_note_id']));
            if ($done) {
                return;
            }
            if (empty($j['next_attempt_at']) || strtotime((string) $j['next_attempt_at']) <= time()) {
                $this->syncJournal($journalUuid);
            }
            sleep(2);
        }
    }

    // ------------------------------------------------------------------
    // Cron entry point (all tenants)
    // ------------------------------------------------------------------

    public static function processDue($db, $redis, int $limit = 50): int
    {
        $rows = $db->fetchAll(
            "SELECT j.journal_uuid, j.domain_uuid, d.domain_name
               FROM public.q_freshdesk_call_journals j
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

    // ==================================================================
    // RingQ dialer inside Freshdesk (CTI app)
    // ==================================================================

    /**
     * Issues a dialer token for the logged-in user. Returned ONCE; only its
     * SHA-256 is stored. The Freshdesk dialer keeps it and sends it with every
     * freshdeskcrm/cti request (it cannot use the RingQ login cookie there).
     */
    public function issueCtiToken(array $session): array
    {
        $userUuid = (string) ($session['user_uuid'] ?? '');
        $ext = mb_substr((string) preg_replace('/[^0-9A-Za-z*#+_-]/', '', (string) ($session['extension'] ?? '')), 0, 32);
        if ($userUuid === '' || $ext === '') {
            throw new FreshdeskException('Your RingQ user has no extension, so it cannot place calls from Freshdesk.', 'validation');
        }
        if ($this->sipCredentials($ext) === null) {
            throw new FreshdeskException('Extension ' . $ext . ' was not found or is disabled.', 'not_found');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $agent = mb_substr(trim(((string) ($session['firstname'] ?? '')) . ' ' . ((string) ($session['lastname'] ?? ''))), 0, 120);
        $this->db->execute(
            "INSERT INTO public.q_freshdesk_cti_tokens (token_hash, domain_uuid, user_uuid, extension, agent_name, created_at, expires_at)
             VALUES (:h, CAST(:d AS uuid), :u, :e, :n, now(), now() + CAST(:days AS integer) * interval '1 day')",
            ['h' => hash('sha256', $token), 'd' => $this->domainUuid, 'u' => $userUuid, 'e' => $ext, 'n' => $agent !== '' ? $agent : null, 'days' => self::CTI_TOKEN_DAYS]
        );
        $this->db->execute(
            'UPDATE public.q_freshdesk_cti_tokens SET revoked_at = now()
              WHERE domain_uuid = CAST(:d AS uuid) AND user_uuid = :u AND revoked_at IS NULL
                AND token_hash NOT IN (
                    SELECT token_hash FROM public.q_freshdesk_cti_tokens
                     WHERE domain_uuid = CAST(:d2 AS uuid) AND user_uuid = :u2 AND revoked_at IS NULL
                     ORDER BY created_at DESC LIMIT CAST(:keep AS integer))',
            ['d' => $this->domainUuid, 'u' => $userUuid, 'd2' => $this->domainUuid, 'u2' => $userUuid, 'keep' => self::CTI_TOKENS_PER_USER]
        );
        return ['token' => $token, 'expires_in_days' => self::CTI_TOKEN_DAYS];
    }

    /** Resolves a dialer token to a session-like array, or null when unknown, expired or revoked. */
    public static function ctiSession($db, string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $row = $db->fetchOne(
            'SELECT t.token_hash, t.domain_uuid, t.user_uuid, t.extension, t.agent_name, t.last_used_at, d.domain_name
               FROM public.q_freshdesk_cti_tokens t
               JOIN public.v_domains d ON d.domain_uuid = t.domain_uuid
              WHERE t.token_hash = :h AND t.revoked_at IS NULL AND t.expires_at > now()',
            Enum::FETCH_ASSOC,
            ['h' => $hash]
        );
        if (!$row || !hash_equals((string) $row['token_hash'], $hash)) {
            return null;
        }
        if (empty($row['last_used_at']) || strtotime((string) $row['last_used_at']) < time() - 300) {
            $db->execute('UPDATE public.q_freshdesk_cti_tokens SET last_used_at = now() WHERE token_hash = :h', ['h' => $hash]);
        }
        return [
            'domain_uuid' => (string) $row['domain_uuid'],
            'subdomain' => (string) $row['domain_name'],
            'user_uuid' => (string) $row['user_uuid'],
            'extension' => (string) $row['extension'],
            'firstname' => trim((string) ($row['agent_name'] ?? '')),
            'lastname' => '',
            'cti_token_hash' => $hash,
        ];
    }

    public function revokeCtiToken(string $tokenHash): void
    {
        $this->db->execute(
            'UPDATE public.q_freshdesk_cti_tokens SET revoked_at = now() WHERE token_hash = :h AND domain_uuid = CAST(:d AS uuid)',
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

    /** Dialer "Recents": this agent's latest calls and their Freshdesk status. */
    public function recents(string $userUuid): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM public.q_freshdesk_call_journals
              WHERE domain_uuid = CAST(:d AS uuid) AND user_uuid = :u
              ORDER BY started_at DESC LIMIT 40",
            Enum::FETCH_ASSOC,
            ['d' => $this->domainUuid, 'u' => $userUuid]
        );
        return array_map(function (array $r) {
            return [
                'local_call_id' => (string) $r['local_call_id'],
                'direction' => (string) $r['call_direction'],
                'number' => (string) ($r['call_direction'] === 'inbound' ? $r['caller_number'] : $r['destination_number']),
                'status' => (string) $r['call_status'],
                'started_at' => self::iso($r['started_at']),
                'talk_duration' => (int) $r['talk_duration'],
                'contact_id' => $r['freshdesk_contact_id'],
                'ticket_id' => $r['freshdesk_ticket_id'],
                'saved' => (string) $r['sync_status'] === 'synced',
                'recording' => in_array((string) $r['recording_status'], ['uploaded', 'link_only'], true),
            ];
        }, $rows ?: []);
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

    public static function fmtDuration(int $s): string
    {
        return sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }

    private static function fmtShort(int $s): string
    {
        return $s >= 3600 ? self::fmtDuration($s) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
