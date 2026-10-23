<?php

require_once __DIR__ . '/ServiceHealth.php';
require_once __DIR__ . '/Storage.php';

/**
 * Phase 1 support tools. READ ONLY, without exception.
 *
 * Every method answers from the same table or redis key the web app's own
 * screen reads, scoped to the tenant that owns the request host. Nothing here
 * writes, and no argument ever reaches a shell unescaped.
 *
 * WHAT IS DELIBERATELY NOT HERE, and must not be added to this class:
 * call recordings and their paths, voicemail audio, chat/SMS message bodies,
 * SIP or account passwords, push tokens, API keys, and the email addresses,
 * phone numbers and account IDs people type into free-text fields such as an
 * extension description (those are redacted on the way out). A support engineer
 * diagnosing a registration does not need any of them, and a read-only tool
 * that returns them is still a disclosure.
 */
class McpTools
{
    const MAX_ROWS = 100;
    const MAX_TEXT = 2000;
    const CALL_SCAN_ROWS = 200;

    const CALL_FAILURE_CAUSES = [
        'NO_ANSWER' => 'no_answer',
        'NO_USER_RESPONSE' => 'no_answer',
        'ALLOTTED_TIMEOUT' => 'no_answer',
        'TIMEOUT' => 'no_answer',
        'RECOVERY_ON_TIMER_EXPIRE' => 'no_answer',
        'PROGRESS_TIMEOUT' => 'no_answer',
        'ORIGINATOR_CANCEL' => 'cancelled',
        'LOSE_RACE' => 'answered_elsewhere',
        'PICKED_OFF' => 'answered_elsewhere',
        'USER_BUSY' => 'busy',
        'CALL_REJECTED' => 'rejected',
        'USER_NOT_REGISTERED' => 'failed',
        'SUBSCRIBER_ABSENT' => 'failed',
        'DESTINATION_OUT_OF_ORDER' => 'failed',
        'UNALLOCATED_NUMBER' => 'failed',
        'NO_ROUTE_DESTINATION' => 'failed',
        'NO_ROUTE_TRANSIT_NET' => 'failed',
        'OUTGOING_CALL_BARRED' => 'failed',
        'INCOMING_CALL_BARRED' => 'failed',
        'INCOMPATIBLE_DESTINATION' => 'failed',
        'INVALID_NUMBER_FORMAT' => 'failed',
        'NORMAL_TEMPORARY_FAILURE' => 'failed',
        'NORMAL_CIRCUIT_CONGESTION' => 'failed',
        'SWITCH_CONGESTION' => 'failed',
        'NETWORK_OUT_OF_ORDER' => 'failed',
        'SERVICE_UNAVAILABLE' => 'failed',
        'REQUESTED_CHAN_UNAVAIL' => 'failed',
        'FACILITY_REJECTED' => 'failed',
        'BEARERCAPABILITY_NOTAVAIL' => 'failed',
        'CHAN_NOT_IMPLEMENTED' => 'failed',
        'EXCHANGE_ROUTING_ERROR' => 'failed',
        'GATEWAY_DOWN' => 'failed',
    ];

    const CALL_OUTCOME_RANK = [
        'answered' => 9,
        'voicemail' => 8,
        'ivr' => 7,
        'answered_elsewhere' => 6,
        'abandoned' => 5,
        'busy' => 4,
        'rejected' => 3,
        'no_answer' => 2,
        'cancelled' => 1,
        'failed' => 0,
    ];

    const CALL_MISSED_OUTCOMES = ['voicemail', 'abandoned', 'busy', 'rejected', 'no_answer', 'cancelled'];

    /**
     * The tool table. `describe()` is what the MCP client is shown, so it is
     * the single source for both the advertised list and the dispatcher — a
     * tool that is advertised but not dispatchable, or the reverse, cannot
     * happen.
     */
    const TOOLS = [
        'get_system_overview' => [
            'summary' => 'Server build, CPU/memory/disk, and counts of extensions, trunks, queues and DIDs.',
            'args' => [],
        ],
        'get_service_status' => [
            'summary' => 'Which services are running: systemd units, node apps under pm2, and SIP profiles.',
            'args' => [],
        ],
        'list_registrations' => [
            'summary' => 'Devices currently registered to SIP, with IP, user agent and expiry.',
            'args' => ['extension' => 'Optional. Limit to one extension.'],
        ],
        'list_live_calls' => [
            'summary' => 'Calls in progress on this tenant right now, with who is on them and how long they have been up.',
            'args' => ['limit' => 'Optional. How many to return, newest first (default 25, max 100).'],
        ],
        'get_extension' => [
            'summary' => 'One extension: settings, forwarding, voicemail and its known devices.',
            'args' => ['extension' => 'Required. The extension number.'],
        ],
        'get_trunk_status' => [
            'summary' => 'SIP trunks (gateways) and their live registration state.',
            'args' => [],
        ],
        'trace_number' => [
            'summary' => 'Follow a DID to its destination and report the step that is broken.',
            'args' => ['number' => 'Required. The DID or internal number to trace.'],
        ],
        'search_call_logs' => [
            'summary' => 'Recent calls, filtered by extension, direction, number or date.',
            'args' => [
                'extension' => 'Optional. Match caller or destination.',
                'number' => 'Optional. Match either party.',
                'direction' => 'Optional: inbound, outbound or local.',
                'since' => 'Optional ISO date, default 7 days ago.',
                'until' => 'Optional ISO date.',
                'limit' => 'Optional, max 100.',
            ],
        ],
        'get_call_sip_trace' => [
            'summary' => 'SIP message flow for one call, when a packet capture was kept.',
            'args' => ['callId' => 'Required. The sip_call_id from search_call_logs.'],
        ],
        'get_call_debug_log' => [
            'summary' => 'Recent FreeSWITCH log lines for this tenant\'s calls only; other tenants\' calls and server-wide lines are removed. For a fresh capture, an admin turns Call Debug on in the RingQ app (Global Settings, Advanced) first.',
            'args' => [
                'minutes' => 'Optional. How far back to read, default 15, max 120.',
                'limit' => 'Optional. Most lines to return, newest last, default 200, max 500.',
            ],
        ],
        'get_security_blocks' => [
            'summary' => 'IP addresses currently blocked by SIP Guard or fail2ban.',
            'args' => ['limit' => 'Optional, max 100.'],
        ],
        'get_audit_log' => [
            'summary' => 'Recent configuration changes on this tenant and who made them.',
            'args' => ['limit' => 'Optional, max 100.', 'feature' => 'Optional feature filter.'],
        ],
        'get_ssl_and_storage' => [
            'summary' => 'Certificates as served on ports 443, 8443 and 7443 (FreeSWITCH, used by phones and the web app): domain, expiry and whether 7443 still serves an older certificate than 443; and disk usage.',
            'args' => [],
        ],
    ];

    public static function names(): array
    {
        return array_keys(self::TOOLS);
    }

    public static function exists(string $name): bool
    {
        return isset(self::TOOLS[$name]);
    }

    /** The advertised schema, derived from TOOLS so the two cannot diverge. */
    public static function describe(): array
    {
        $out = [];
        foreach (self::TOOLS as $name => $meta) {
            $properties = [];
            $required = [];
            foreach ($meta['args'] as $arg => $help) {
                $properties[$arg] = ['type' => 'string', 'description' => $help];
                if (strpos($help, 'Required') === 0) {
                    $required[] = $arg;
                }
            }
            $out[] = [
                'name' => $name,
                'description' => $meta['summary'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => (object) $properties,
                    'required' => $required,
                ],
            ];
        }
        return $out;
    }

    /**
     * Dispatch. $ctx carries domainUuid and subdomain, both resolved from the
     * request host by the caller — never from the request body.
     */
    public static function run($di, string $name, array $args, array $ctx): array
    {
        switch ($name) {
            case 'get_system_overview':
                return self::systemOverview($di, $ctx);
            case 'get_service_status':
                return self::serviceStatus($di);
            case 'list_registrations':
                return self::registrations($di, $ctx, $args);
            case 'list_live_calls':
                return self::liveCalls($ctx, $args);
            case 'get_extension':
                return self::extension($di, $ctx, $args);
            case 'get_trunk_status':
                return self::trunks($di, $ctx);
            case 'trace_number':
                return self::traceNumber($di, $ctx, $args);
            case 'search_call_logs':
                return self::callLogs($di, $ctx, $args);
            case 'get_call_sip_trace':
                return self::sipTrace($di, $ctx, $args);
            case 'get_call_debug_log':
                return self::callDebugLog($di, $ctx, $args);
            case 'get_security_blocks':
                return self::securityBlocks($di, $args);
            case 'get_audit_log':
                return self::auditLog($di, $ctx, $args);
            case 'get_ssl_and_storage':
                return self::sslAndStorage($di, $ctx);
        }
        throw new \InvalidArgumentException('unknown tool');
    }

    // ---------------------------------------------------------------
    // Tools
    // ---------------------------------------------------------------

    private static function systemOverview($di, array $ctx): array
    {
        $redis = $di->get('redis');
        $domainUuid = $ctx['domainUuid'];

        $latest = function ($key) use ($redis) {
            $values = $redis->lrange($key, -1, -1);
            if (!is_array($values) || count($values) === 0) {
                return null;
            }
            return (float) current($values);
        };

        $counts = [
            'extensions' => VExtensions::count(['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $domainUuid]]),
            'trunks' => VGateways::count(['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $domainUuid]]),
            'queues' => VCallCenterQueues::count(['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $domainUuid]]),
            'ivrMenus' => VIvrMenus::count(['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $domainUuid]]),
            'dids' => QDids::count(['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $domainUuid]]),
            'devices' => QDevices::count(['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $domainUuid]]),
        ];

        $totalGb = $redis->get('totalSpaceGB');

        return [
            'server' => [
                'version' => ServiceHealth::serverVersion($redis),
                'hostname' => ServiceHealth::hostname(),
                'tenant' => $ctx['subdomain'],
                'readAt' => gmdate('c'),
            ],
            'resources' => [
                'cpuLoadPercent' => $latest('systemLoadPercentage2_List'),
                'cpuCores' => $redis->get('systemCoreCount') === null ? null : (int) $redis->get('systemCoreCount'),
                'memoryTotalKb' => $latest('systemMemTotal2'),
                'memoryUsedKb' => $latest('systemMemUsed2'),
                'memoryAvailableKb' => $latest('systemMemAvailable2'),
                'diskUsedPercent' => $latest('systemDiskUsagePercentage2'),
                'diskTotalGb' => $totalGb === null ? null : round((float) $totalGb, 2),
            ],
            'counts' => $counts,
        ];
    }

    private static function serviceStatus($di): array
    {
        $nodes = ServiceHealth::nodeServices();
        $sofia = ServiceHealth::sofiaProfiles();

        $notes = [];
        foreach ($nodes['services'] as $service) {
            if ($service['state'] === 'absent') {
                $notes[] = 'Node service "' . $service['name'] . '" is declared but pm2 has never heard of it.';
            } elseif ($service['state'] !== 'online' && $service['state'] !== 'unknown') {
                $notes[] = 'Node service "' . $service['name'] . '" is ' . $service['state'] . '.';
            }
        }
        foreach (ServiceHealth::systemdUnits() as $unit) {
            if ($unit['state'] !== 'active') {
                $notes[] = 'System service "' . $unit['name'] . '" is ' . $unit['state'] . '.';
            }
        }
        if ($sofia['error'] === null && count($sofia['profiles']) === 0) {
            $notes[] = 'FreeSWITCH is answering but has 0 SIP profiles, so nothing can register or place a call.';
        }
        if ($nodes['error'] !== null) {
            $notes[] = 'Node service states could not be read: ' . $nodes['error'] . '.';
        }

        return [
            'systemd' => ServiceHealth::systemdUnits(),
            'nodeServices' => $nodes['services'],
            'nodeServicesError' => $nodes['error'],
            'sipProfiles' => $sofia['profiles'],
            'sipProfilesError' => $sofia['error'],
            'notes' => $notes,
            'readAt' => gmdate('c'),
        ];
    }

    /**
     * Live registrations.
     *
     * The registrar snapshot is redis `onlineStatus-<subdomain>`, written by
     * signal.js on every SIP register and unregister. Its `reg_user` is the
     * extension's `number_alias`, NOT the extension number — a direct compare
     * against `extension` matches nothing — so it is mapped back here.
     */
    private static function registrations($di, array $ctx, array $args): array
    {
        $redis = $di->get('redis');
        $raw = json_decode((string) $redis->get('onlineStatus-' . $ctx['subdomain']), true);
        $rows = is_array($raw) ? $raw : [];

        $byAlias = [];
        foreach (VExtensions::find([
            'conditions' => 'domain_uuid = :d:',
            'bind' => ['d' => $ctx['domainUuid']],
        ]) as $ext) {
            $alias = (string) $ext->number_alias;
            if ($alias !== '') {
                $byAlias[$alias] = (string) $ext->extension;
            }
            $byAlias[(string) $ext->extension] = (string) $ext->extension;
        }

        $wanted = trim((string) ($args['extension'] ?? ''));
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $alias = (string) ($row['reg_user'] ?? '');
            $extension = $byAlias[$alias] ?? null;
            if ($extension === null) {
                continue;
            }
            if ($wanted !== '' && $extension !== $wanted) {
                continue;
            }
            $expires = isset($row['expires']) ? (int) $row['expires'] : null;
            $out[] = [
                'extension' => $extension,
                'userAgent' => self::clip((string) ($row['realm'] ?? '')),
                'networkIp' => (string) ($row['network_ip'] ?? ''),
                'networkPort' => (string) ($row['network_port'] ?? ''),
                'transport' => (string) ($row['network_proto'] ?? ''),
                'expiresAt' => $expires,
                'expiresInSeconds' => $expires === null ? null : $expires - time(),
            ];
        }

        return [
            'registrations' => $out,
            'count' => count($out),
            'snapshotPresent' => is_array($raw),
            'note' => is_array($raw)
                ? 'The registrar snapshot refreshes on each SIP register and unregister.'
                : 'No registrar snapshot exists yet for this tenant, which is not the same as nothing being registered.',
        ];
    }

    private static function extension($di, array $ctx, array $args): array
    {
        $number = trim((string) ($args['extension'] ?? ''));
        if ($number === '') {
            throw new \InvalidArgumentException('extension is required');
        }

        $ext = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :d: AND extension = :e:',
            'bind' => ['d' => $ctx['domainUuid'], 'e' => $number],
        ]);
        if (!$ext) {
            return ['found' => false, 'extension' => $number];
        }

        $devices = [];
        foreach (QDevices::find([
            'conditions' => 'domain_uuid = :d: AND extension = :e:',
            'bind' => ['d' => $ctx['domainUuid'], 'e' => $number],
        ]) as $device) {
            $devices[] = [
                'id' => (int) $device->id,
                'name' => self::redactContact((string) $device->device_name),
                'type' => self::deviceType((int) $device->device_type),
                'version' => (string) $device->device_version,
                'appVersion' => (string) $device->version,
                'receivesCalls' => ((int) $device->enabled) !== 0,
                'lastLoginAt' => $device->update_login === null ? null : (string) $device->update_login,
            ];
        }

        return [
            'found' => true,
            'extension' => (string) $ext->extension,
            'callerIdName' => self::redactContact((string) $ext->effective_caller_id_name),
            'outboundCallerId' => (string) $ext->outbound_caller_id_number,
            'enabled' => (string) $ext->enabled === 'true',
            'description' => self::clip(self::redactContact((string) $ext->description)),
            'callTimeoutSeconds' => $ext->call_timeout === null ? null : (int) $ext->call_timeout,
            'doNotDisturb' => (string) $ext->do_not_disturb === 'true',
            'maxRegistrations' => (string) $ext->max_registrations,
            'forwarding' => [
                'all' => self::forward($ext->forward_all_enabled, $ext->forward_all_destination),
                'busy' => self::forward($ext->forward_busy_enabled, $ext->forward_busy_destination),
                'noAnswer' => self::forward($ext->forward_no_answer_enabled, $ext->forward_no_answer_destination),
                'notRegistered' => self::forward($ext->forward_user_not_registered_enabled, $ext->forward_user_not_registered_destination),
            ],
            'followMeEnabled' => (string) $ext->follow_me_enabled === 'true',
            'voicemailMissedCallApp' => (string) $ext->missed_call_app,
            'devices' => $devices,
            'note' => 'SIP credentials are never returned by support tools. Email addresses, phone numbers and account IDs inside free-text fields are redacted.',
        ];
    }

    /**
     * Calls up right now, scoped to this tenant.
     *
     * FreeSWITCH has no per-tenant view of channels, so the scoping is done
     * here: a channel belongs to this tenant when its context is the tenant's
     * domain or either leg's presence id is at that domain. Anything that does
     * not match is dropped rather than shown -- an engineer holding one
     * tenant's grant must not see another tenant's callers.
     */
    private static function liveCalls(array $ctx, array $args): array
    {
        $domain = (string) $ctx['subdomain'];
        $limit = self::limit($args['limit'] ?? null);
        $channels = ServiceHealth::channels();
        if ($channels['error'] !== null) {
            return ['calls' => [], 'count' => 0, 'error' => $channels['error']];
        }

        $mine = [];
        foreach ($channels['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $context = (string) ($row['context'] ?? '');
            $presence = (string) ($row['presence_id'] ?? '');
            $belongs = ($domain !== '' && $context === $domain)
                || ($domain !== '' && $presence !== '' && substr($presence, -(strlen($domain) + 1)) === '@' . $domain);
            if (!$belongs) {
                continue;
            }
            $created = (string) ($row['created_epoch'] ?? '');
            $mine[] = [
                'uuid' => (string) ($row['uuid'] ?? ''),
                'direction' => (string) ($row['direction'] ?? ''),
                'state' => (string) ($row['callstate'] ?? $row['state'] ?? ''),
                'from' => (string) ($row['cid_num'] ?? ''),
                'fromName' => self::clip(self::redactContact((string) ($row['cid_name'] ?? '')), 120),
                'to' => (string) ($row['dest'] ?? ''),
                'extension' => $presence === '' ? '' : strstr($presence, '@', true),
                'application' => (string) ($row['application'] ?? ''),
                'secondsUp' => $created === '' ? null : max(0, time() - (int) $created),
                'createdAt' => (string) ($row['created'] ?? ''),
            ];
        }

        usort($mine, function ($a, $b) {
            return ($a['secondsUp'] ?? 0) <=> ($b['secondsUp'] ?? 0);
        });

        return [
            'calls' => array_slice($mine, 0, $limit),
            'count' => count($mine),
            'note' => count($mine) > $limit
                ? 'Showing the ' . $limit . ' most recent of ' . count($mine) . ' calls.'
                : 'All calls currently up for this tenant.',
        ];
    }

    private static function trunks($di, array $ctx): array
    {
        $redis = $di->get('redis');
        $out = [];
        foreach (VGateways::find([
            'conditions' => 'domain_uuid = :d:',
            'bind' => ['d' => $ctx['domainUuid']],
        ]) as $gateway) {
            $live = json_decode((string) $redis->get('gatewayStatus-' . $gateway->gateway_uuid), true);
            $observedAt = is_array($live) && isset($live['observed_at']) ? (int) $live['observed_at'] : null;
            $out[] = [
                'name' => (string) $gateway->gateway,
                'uuid' => (string) $gateway->gateway_uuid,
                'proxy' => (string) $gateway->proxy,
                'profile' => (string) $gateway->profile,
                'transport' => strtoupper((string) $gateway->register_transport),
                'registers' => (string) $gateway->register === 'true',
                'enabled' => (string) $gateway->enabled === 'true',
                'state' => is_array($live) ? (string) ($live['state'] ?? 'unknown') : 'unknown',
                'uri' => is_array($live) ? (string) ($live['uri'] ?? '') : '',
                'observedAt' => $observedAt,
                'observedSecondsAgo' => $observedAt === null ? null : max(0, time() - $observedAt),
                'inboundCalls' => is_array($live) ? ($live['ib_calls'] ?? null) : null,
                'outboundCalls' => is_array($live) ? ($live['ob_calls'] ?? null) : null,
            ];
        }
        return [
            'trunks' => $out,
            'count' => count($out),
            'note' => 'state "unknown" means no live reading was published, which is not the same as down.',
        ];
    }

    /**
     * Follow a number to what answers it.
     *
     * The destination is resolved by LOOKUP, never by reading the DID's numeric
     * `type` column: a stored type that disagrees with what the number really
     * points at is itself one of the faults this tool exists to find.
     */
    private static function traceNumber($di, array $ctx, array $args): array
    {
        $number = trim((string) ($args['number'] ?? ''));
        if ($number === '') {
            throw new \InvalidArgumentException('number is required');
        }
        $domainUuid = $ctx['domainUuid'];
        $steps = [];
        $problems = [];

        $did = QDids::findFirst([
            'conditions' => 'domain_uuid = :d: AND did_number = :n:',
            'bind' => ['d' => $domainUuid, 'n' => $number],
        ]);

        $target = $number;
        if ($did) {
            $target = trim((string) $did->extension);
            $steps[] = [
                'step' => 'did',
                'status' => $target === '' ? 'broken' : 'ok',
                'detail' => 'DID ' . $number . ($did->name ? ' (' . self::redactContact((string) $did->name) . ')' : ''),
                'routesTo' => $target,
                'trunk' => (string) $did->sip_trunk_name,
            ];
            if ($target === '') {
                $problems[] = 'DID ' . $number . ' has no destination set, so an inbound call to it goes nowhere.';
                return ['number' => $number, 'steps' => $steps, 'problems' => $problems];
            }
        } else {
            $steps[] = [
                'step' => 'did',
                'status' => 'not_found',
                'detail' => 'No DID row for ' . $number . ' on this tenant. Tracing it as an internal number.',
                'routesTo' => $number,
            ];
        }

        $resolved = false;

        $ivr = VIvrMenus::findFirst([
            'conditions' => 'domain_uuid = :d: AND ivr_menu_extension = :e:',
            'bind' => ['d' => $domainUuid, 'e' => $target],
        ]);
        if ($ivr) {
            $resolved = true;
            $enabled = (string) $ivr->ivr_menu_enabled === 'true';
            $options = [];
            foreach (VIvrMenuOptions::find([
                'conditions' => 'ivr_menu_uuid = :i:',
                'bind' => ['i' => $ivr->ivr_menu_uuid],
                'order' => 'ivr_menu_option_order ASC',
            ]) as $option) {
                $options[] = [
                    'digits' => (string) $option->ivr_menu_option_digits,
                    'action' => (string) $option->ivr_menu_option_action,
                    'target' => (string) $option->ivr_menu_option_param,
                    'enabled' => (string) $option->ivr_menu_option_enabled === 'true',
                ];
            }
            $steps[] = [
                'step' => 'ivr',
                'status' => $enabled ? 'ok' : 'disabled',
                'detail' => 'IVR "' . $ivr->ivr_menu_name . '" on ' . $target,
                'timeoutAction' => (string) $ivr->ivr_menu_exit_app . ' ' . (string) $ivr->ivr_menu_exit_data,
                'options' => $options,
            ];
            if (!$enabled) {
                $problems[] = 'IVR "' . $ivr->ivr_menu_name . '" is disabled, so calls reaching it are dropped.';
            }
            if (count($options) === 0) {
                $problems[] = 'IVR "' . $ivr->ivr_menu_name . '" has no options, so a caller has nothing to choose.';
            }
        }

        $queue = VCallCenterQueues::findFirst([
            'conditions' => 'domain_uuid = :d: AND queue_extension = :e:',
            'bind' => ['d' => $domainUuid, 'e' => $target],
        ]);
        if ($queue) {
            $resolved = true;
            $agents = [];
            foreach (VCallCenterTiers::find([
                'conditions' => 'domain_uuid = :d: AND call_center_queue_uuid = :q:',
                'bind' => ['d' => $domainUuid, 'q' => $queue->call_center_queue_uuid],
            ]) as $tier) {
                $agents[] = (string) $tier->agent_name;
            }
            $steps[] = [
                'step' => 'queue',
                'status' => count($agents) === 0 ? 'broken' : 'ok',
                'detail' => 'Queue "' . $queue->queue_name . '" on ' . $target,
                'strategy' => (string) $queue->queue_strategy,
                'maxWaitSeconds' => $queue->queue_max_wait_time === null ? null : (int) $queue->queue_max_wait_time,
                'timeoutAction' => (string) $queue->queue_timeout_action,
                'agents' => $agents,
            ];
            if (count($agents) === 0) {
                $problems[] = 'Queue "' . $queue->queue_name . '" has no agents assigned, so callers wait with nobody to answer.';
            }
        }

        $ext = VExtensions::findFirst([
            'conditions' => 'domain_uuid = :d: AND extension = :e:',
            'bind' => ['d' => $domainUuid, 'e' => $target],
        ]);
        if ($ext) {
            $resolved = true;
            $enabled = (string) $ext->enabled === 'true';
            $forwards = [];
            foreach ([
                'all' => [$ext->forward_all_enabled, $ext->forward_all_destination],
                'busy' => [$ext->forward_busy_enabled, $ext->forward_busy_destination],
                'noAnswer' => [$ext->forward_no_answer_enabled, $ext->forward_no_answer_destination],
                'notRegistered' => [$ext->forward_user_not_registered_enabled, $ext->forward_user_not_registered_destination],
            ] as $kind => $pair) {
                if ((string) $pair[0] === 'true') {
                    $forwards[$kind] = (string) $pair[1];
                }
            }
            $steps[] = [
                'step' => 'extension',
                'status' => $enabled ? 'ok' : 'disabled',
                'detail' => 'Extension ' . $target . ' (' . self::redactContact((string) $ext->effective_caller_id_name) . ')',
                'doNotDisturb' => (string) $ext->do_not_disturb === 'true',
                'forwarding' => $forwards,
            ];
            if (!$enabled) {
                $problems[] = 'Extension ' . $target . ' is disabled, so it cannot be reached.';
            }
            if ((string) $ext->do_not_disturb === 'true') {
                $problems[] = 'Extension ' . $target . ' has Do Not Disturb on.';
            }
        }

        if (!$resolved) {
            $steps[] = [
                'step' => 'destination',
                'status' => 'broken',
                'detail' => 'Nothing on this tenant answers to ' . $target . ': no extension, queue or IVR has that number.',
            ];
            $problems[] = 'The destination ' . $target . ' does not exist, so calls routed to it fail.';
        }

        $schedule = self::scheduleFor($domainUuid, $target);
        if ($schedule !== null) {
            $steps[] = $schedule;
        }

        return [
            'number' => $number,
            'steps' => $steps,
            'problems' => $problems,
            'note' => 'Steps are resolved by looking the destination up, not from the stored DID type.',
        ];
    }

    /** Office-hours rows that name this destination, if any. */
    private static function scheduleFor(string $domainUuid, string $target): ?array
    {
        $row = QTimeSchedules::findFirst([
            'conditions' => 'domain_uuid = :d:',
            'bind' => ['d' => $domainUuid],
        ]);
        if (!$row) {
            return null;
        }
        $lists = [
            'work' => (string) $row->work_extensions,
            'break' => (string) $row->break_extensions,
            'special' => (string) $row->special_extensions,
        ];
        $applies = [];
        foreach ($lists as $kind => $csv) {
            $members = array_map('trim', explode(',', $csv));
            if (in_array($target, $members, true)) {
                $applies[] = $kind;
            }
        }
        if (count($applies) === 0) {
            return null;
        }
        return [
            'step' => 'schedule',
            'status' => 'ok',
            'detail' => 'Office-hours rules apply to ' . $target . ' (' . implode(', ', $applies) . ').',
            'workDefault' => (string) $row->work_default,
            'breakDefault' => (string) $row->break_default,
            'note' => 'Outside these hours the call is sent to the default destination above, which is a common cause of "calls go nowhere" reports.',
        ];
    }

    private static function callLogs($di, array $ctx, array $args): array
    {
        $limit = self::limit($args['limit'] ?? null);
        $conditions = ['domain_uuid = :d:'];
        $bind = ['d' => $ctx['domainUuid']];

        $extension = trim((string) ($args['extension'] ?? ''));
        if ($extension !== '') {
            $conditions[] = '(caller_id_number = :x: OR destination_number = :x: OR caller_destination = :x: OR cc_agent = :x:)';
            $bind['x'] = $extension;
        }
        $number = trim((string) ($args['number'] ?? ''));
        if ($number !== '') {
            $conditions[] = '(caller_id_number LIKE :n: OR destination_number LIKE :n: OR caller_destination LIKE :n:)';
            $bind['n'] = '%' . $number . '%';
        }
        $direction = strtolower(trim((string) ($args['direction'] ?? '')));
        if ($direction === 'local') {
            $conditions[] = "direction IN ('local', 'inbound')";
        } elseif (in_array($direction, ['inbound', 'outbound'], true)) {
            $conditions[] = 'direction = :dir:';
            $bind['dir'] = $direction;
        } else {
            $direction = '';
        }
        $since = self::date($args['since'] ?? null, '-7 days');
        $conditions[] = 'start_stamp >= :since:';
        $bind['since'] = $since;
        $until = self::date($args['until'] ?? null, null);
        if ($until !== null) {
            $conditions[] = 'start_stamp <= :until:';
            $bind['until'] = $until;
        }

        $found = [];
        foreach (QRingqCdr::find([
            'conditions' => implode(' AND ', $conditions),
            'bind' => $bind,
            'order' => 'start_stamp DESC',
            'limit' => min(self::CALL_SCAN_ROWS, $limit * 2),
        ]) as $cdr) {
            $found[] = $cdr->toArray();
        }

        $calls = self::groupCallLegs($di, $ctx, $found);
        $rows = [];
        foreach ($calls as $call) {
            if ($direction !== '' && $call['direction'] !== $direction) {
                continue;
            }
            $rows[] = $call;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'calls' => $rows,
            'count' => count($rows),
            'since' => $since,
            'limit' => $limit,
            'note' => 'One row per call: the legs a call is recorded in are merged, and direction is the call\'s, not a leg\'s. '
                . 'answered means a person or agent talked; missed means an inbound or internal call no person took (it may have reached voicemail); '
                . 'outcome says which. Recording audio is never returned; hasRecording only says whether one exists.',
        ];
    }

    private static function groupCallLegs($di, array $ctx, array $found): array
    {
        if (count($found) === 0) {
            return [];
        }
        $db = $di->get('db');
        $byId = [];
        foreach ($found as $row) {
            $byId[(string) $row['xml_cdr_uuid']] = $row;
        }

        $parents = [];
        foreach ($found as $row) {
            $origin = (string) ($row['originating_leg_uuid'] ?? '');
            if ($origin !== '' && $origin !== (string) $row['xml_cdr_uuid'] && !isset($byId[$origin])) {
                $parents[$origin] = true;
            }
        }
        foreach (self::cdrRowsByColumn($db, $ctx, 'xml_cdr_uuid', array_keys($parents)) as $row) {
            $byId[(string) $row['xml_cdr_uuid']] = $row;
        }
        $roots = [];
        foreach ($byId as $id => $row) {
            $origin = (string) ($row['originating_leg_uuid'] ?? '');
            if ($origin === '' || $origin === $id || !isset($byId[$origin])) {
                $roots[$id] = true;
            }
        }
        foreach (self::cdrRowsByColumn($db, $ctx, 'originating_leg_uuid', array_keys($roots)) as $row) {
            $byId[(string) $row['xml_cdr_uuid']] = $row;
        }

        $lookup = self::callNumberLookup($ctx);
        $legs = [];
        $order = [];
        foreach ($found as $row) {
            $root = self::callRoot($byId, (string) $row['xml_cdr_uuid']);
            if (!isset($legs[$root])) {
                $legs[$root] = [];
                $order[] = $root;
            }
        }
        foreach ($byId as $id => $row) {
            $root = self::callRoot($byId, $id);
            if (isset($legs[$root])) {
                $legs[$root][$id] = $row;
            }
        }

        $calls = [];
        foreach ($order as $root) {
            $calls[] = self::describeCall($byId[$root], array_values($legs[$root]), $lookup);
        }
        return $calls;
    }

    private static function callRoot(array $byId, string $id): string
    {
        $seen = [];
        while (isset($byId[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            $origin = (string) ($byId[$id]['originating_leg_uuid'] ?? '');
            if ($origin === '' || $origin === $id || !isset($byId[$origin])) {
                return $id;
            }
            $id = $origin;
        }
        return $id;
    }

    private static function cdrRowsByColumn($db, array $ctx, string $column, array $ids): array
    {
        $ids = array_values(array_filter($ids, function ($id) {
            return is_string($id) && preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1;
        }));
        if (count($ids) === 0 || !in_array($column, ['xml_cdr_uuid', 'originating_leg_uuid'], true)) {
            return [];
        }
        $marks = [];
        $bind = [$ctx['domainUuid']];
        foreach (array_slice($ids, 0, 400) as $id) {
            $marks[] = '?';
            $bind[] = strtolower($id);
        }
        try {
            return $db->fetchAll(
                'SELECT * FROM q_ringq_cdr WHERE domain_uuid::text = ? AND ' . $column . '::text IN (' . implode(', ', $marks) . ')',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function callNumberLookup(array $ctx): array
    {
        $lookup = ['internal' => [], 'ivr' => [], 'queue' => []];
        $bind = ['conditions' => 'domain_uuid = :d:', 'bind' => ['d' => $ctx['domainUuid']]];
        try {
            foreach (VExtensions::find($bind) as $ext) {
                foreach ([(string) $ext->extension, (string) $ext->number_alias] as $n) {
                    if ($n !== '') {
                        $lookup['internal'][$n] = true;
                    }
                }
            }
            foreach (VIvrMenus::find($bind) as $ivr) {
                if ((string) $ivr->ivr_menu_extension !== '') {
                    $lookup['ivr'][(string) $ivr->ivr_menu_extension] = true;
                }
            }
            foreach (VCallCenterQueues::find($bind) as $queue) {
                if ((string) $queue->queue_extension !== '') {
                    $lookup['queue'][(string) $queue->queue_extension] = true;
                }
            }
        } catch (\Throwable $e) {
        }
        return $lookup;
    }

    public static function callTalkSeconds(array $row): int
    {
        if (isset($row['billsec']) && $row['billsec'] !== null && $row['billsec'] !== '') {
            return max(0, (int) $row['billsec']);
        }
        $answered = (int) ($row['answer_epoch'] ?? 0);
        $ended = (int) ($row['end_epoch'] ?? 0);
        if ($answered > 0 && $ended >= $answered) {
            return $ended - $answered;
        }
        return 0;
    }

    public static function callLegOutcome(array $row, array $lookup): string
    {
        $truthy = function ($v) {
            return $v === true || $v === 't' || $v === 'true' || $v === 1 || $v === '1';
        };
        if ($truthy($row['voicemail_message'] ?? null)) {
            return 'voicemail';
        }
        $cause = strtoupper(trim((string) ($row['hangup_cause'] ?? '')));
        if (isset(self::CALL_FAILURE_CAUSES[$cause])) {
            return self::CALL_FAILURE_CAUSES[$cause];
        }
        $destination = (string) ($row['destination_number'] ?? '');
        $agent = trim((string) ($row['cc_agent'] ?? ''));
        $queueLeg = trim((string) ($row['cc_queue'] ?? '')) !== '' || isset($lookup['queue'][$destination]);
        if ($queueLeg && $agent === '' && strtolower((string) ($row['cc_cause'] ?? '')) !== 'answered') {
            return 'abandoned';
        }
        if (self::callTalkSeconds($row) <= 0) {
            return 'no_answer';
        }
        if (isset($lookup['ivr'][$destination])) {
            return 'ivr';
        }
        return 'answered';
    }

    public static function callDirection(array $row, array $lookup): string
    {
        $stored = strtolower((string) ($row['direction'] ?? ''));
        if ($stored === 'inbound' && isset($lookup['internal'][(string) ($row['caller_id_number'] ?? '')])) {
            return 'local';
        }
        return $stored;
    }

    public static function describeCall(array $root, array $legs, array $lookup): array
    {
        if (count($legs) === 0) {
            $legs = [$root];
        }
        $outcome = 'failed';
        $talk = 0;
        $recording = false;
        foreach ($legs as $leg) {
            $legOutcome = self::callLegOutcome($leg, $lookup);
            if (self::CALL_OUTCOME_RANK[$legOutcome] > self::CALL_OUTCOME_RANK[$outcome]) {
                $outcome = $legOutcome;
            }
            $talk = max($talk, self::callTalkSeconds($leg));
            if ((string) ($leg['record_name'] ?? '') !== '') {
                $recording = true;
            }
        }
        $direction = self::callDirection($root, $lookup);
        $agent = '';
        $queue = '';
        foreach ($legs as $leg) {
            $agent = $agent !== '' ? $agent : (string) ($leg['cc_agent'] ?? '');
            $queue = $queue !== '' ? $queue : (string) ($leg['cc_queue'] ?? '');
        }

        return [
            'callId' => (string) $root['sip_call_id'],
            'direction' => $direction,
            'from' => (string) $root['caller_id_number'],
            'to' => (string) $root['destination_number'],
            'dialled' => (string) $root['caller_destination'],
            'startedAt' => (string) $root['start_stamp'],
            'outcome' => $outcome,
            'answered' => $outcome === 'answered',
            'missed' => $direction !== 'outbound' && in_array($outcome, self::CALL_MISSED_OUTCOMES, true),
            'durationSeconds' => $root['duration'] === null || $root['duration'] === '' ? null : (int) $root['duration'],
            'talkSeconds' => $talk,
            'hangupCause' => (string) $root['hangup_cause'],
            'legs' => count($legs),
            'queue' => $queue,
            'agent' => $agent,
            'hasRecording' => $recording,
        ];
    }

    /**
     * SIP flow for one call.
     *
     * The call is resolved against this tenant's CDRs FIRST. Reading the
     * capture by id alone would let a support grant on one tenant read another
     * tenant's call.
     */
    const CALL_DEBUG_LOG = '/var/log/freeswitch/freeswitch.log';
    const CALL_DEBUG_READ_BYTES = 2097152;

    public static function tenantLogLines(array $lines, string $domain, array $knownUuids, int $since): array
    {
        $uuidAt = '/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}) (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/';
        $mentions = '/(?<![A-Za-z0-9.-])' . preg_quote($domain, '/') . '(?![A-Za-z0-9-])/';
        $mine = array_fill_keys($knownUuids, true);
        $parsed = [];
        foreach ($lines as $line) {
            if (!preg_match($uuidAt, $line, $m)) {
                continue;
            }
            $at = strtotime($m[2]);
            if ($at === false || $at < $since) {
                continue;
            }
            $parsed[] = [$m[1], $line];
            if ($domain !== '' && preg_match($mentions, $line)) {
                $mine[$m[1]] = true;
            }
        }
        $out = [];
        foreach ($parsed as [$uuid, $line]) {
            if (isset($mine[$uuid])) {
                $out[] = self::clip(preg_replace('/(password|passwd|secret)(\s*[=:]\s*"?)[^\s",;&]+/i', '$1$2***', $line), 600);
            }
        }
        return ['lines' => $out, 'calls' => count(array_unique(array_column(array_filter($parsed, function ($p) use ($mine) { return isset($mine[$p[0]]); }), 0)))];
    }

    private static function callDebugLog($di, array $ctx, array $args): array
    {
        $domain = (string) $ctx['subdomain'];
        $minutes = max(1, min(120, (int) ($args['minutes'] ?? 15)));
        $limit = max(1, min(500, (int) ($args['limit'] ?? 200)));
        $debugOn = (string) $di->get('redis')->get('call_debug:' . $domain) === '1';
        clearstatcache(true, self::CALL_DEBUG_LOG);
        if (!is_readable(self::CALL_DEBUG_LOG)) {
            return ['lines' => [], 'count' => 0, 'debugOn' => $debugOn, 'reason' => 'The call log cannot be read on this server.'];
        }
        $size = (int) filesize(self::CALL_DEBUG_LOG);
        $fh = fopen(self::CALL_DEBUG_LOG, 'rb');
        if ($fh === false) {
            return ['lines' => [], 'count' => 0, 'debugOn' => $debugOn, 'reason' => 'The call log cannot be read on this server.'];
        }
        $start = max(0, $size - self::CALL_DEBUG_READ_BYTES);
        fseek($fh, $start);
        $raw = (string) stream_get_contents($fh);
        fclose($fh);
        $lines = explode("\n", $raw);
        if ($start > 0) {
            array_shift($lines);
        }
        $known = [];
        $channels = ServiceHealth::channels();
        foreach ((array) ($channels['rows'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $context = (string) ($row['context'] ?? '');
            $presence = (string) ($row['presence_id'] ?? '');
            if (($domain !== '' && $context === $domain) || ($domain !== '' && $presence !== '' && substr($presence, -(strlen($domain) + 1)) === '@' . $domain)) {
                $known[] = (string) ($row['uuid'] ?? '');
            }
        }
        $result = self::tenantLogLines($lines, $domain, array_filter($known), time() - $minutes * 60);
        $all = $result['lines'];
        $shown = array_slice($all, -$limit);
        return [
            'debugOn' => $debugOn,
            'minutes' => $minutes,
            'calls' => $result['calls'],
            'count' => count($all),
            'lines' => $shown,
            'note' => 'Only lines belonging to this tenant\'s calls are shown; lines for other tenants and server-wide lines are removed, and anything that looks like a password is masked.'
                . (count($all) > $limit ? ' Showing the last ' . $limit . ' of ' . count($all) . ' lines.' : '')
                . ($debugOn ? '' : ' Call debug is off, so this is the normal log; for a fresh capture turn Call Debug on in the RingQ app (Global Settings, Advanced).'),
        ];
    }

    private static function sipTrace($di, array $ctx, array $args): array
    {
        $callId = trim((string) ($args['callId'] ?? ''));
        if ($callId === '') {
            throw new \InvalidArgumentException('callId is required');
        }

        $cdr = QRingqCdr::findFirst([
            'conditions' => 'domain_uuid = :d: AND sip_call_id = :c:',
            'bind' => ['d' => $ctx['domainUuid'], 'c' => $callId],
        ]);
        if (!$cdr) {
            return ['found' => false, 'callId' => $callId, 'reason' => 'No call with that id belongs to this tenant.'];
        }

        $path = '/var/call-pcaps/' . $callId . '.pcap';
        if (!preg_match('/^[A-Za-z0-9._@:-]{1,190}$/', $callId) || strpos($callId, '..') !== false) {
            return ['found' => false, 'callId' => $callId, 'reason' => 'That call id cannot name a capture file.'];
        }
        if (!file_exists($path)) {
            return [
                'found' => false,
                'callId' => $callId,
                'reason' => 'No packet capture was kept for this call. Captures are only written while capture is running.',
            ];
        }

        $raw = @shell_exec('sudo -n /usr/bin/tshark -r ' . escapeshellarg($path) . ' -Y sip -T json 2>/dev/null');
        $packets = json_decode((string) $raw, true);
        if (!is_array($packets)) {
            return ['found' => false, 'callId' => $callId, 'reason' => 'The capture for this call could not be read.'];
        }

        $messages = [];
        foreach ($packets as $packet) {
            $layers = $packet['_source']['layers'] ?? null;
            if (!is_array($layers)) {
                continue;
            }
            $src = $layers['ip']['ip.src'] ?? null;
            $dst = $layers['ip']['ip.dst'] ?? null;
            $text = $layers['text'] ?? ($layers['sip'] ?? null);
            if ($text === null) {
                continue;
            }
            $body = is_array($text)
                ? implode("\n", array_map(function ($v) {
                    return is_scalar($v) ? (string) $v : json_encode($v);
                }, $text))
                : (string) $text;
            $first = trim(strtok($body, "\n"));
            $messages[] = [
                'at' => (string) ($layers['frame']['frame.time_relative'] ?? ''),
                'from' => is_array($src) ? (string) $src[0] : (string) $src,
                'to' => is_array($dst) ? (string) $dst[0] : (string) $dst,
                'line' => self::clip($first, 200),
            ];
            if (count($messages) >= 300) {
                break;
            }
        }

        $call = self::groupCallLegs($di, $ctx, [$cdr->toArray()]);
        $call = count($call) > 0 ? $call[0] : null;

        return [
            'found' => true,
            'callId' => $callId,
            'direction' => $call !== null ? $call['direction'] : (string) $cdr->direction,
            'outcome' => $call !== null ? $call['outcome'] : null,
            'answered' => $call !== null ? $call['answered'] : null,
            'missed' => $call !== null ? $call['missed'] : null,
            'talkSeconds' => $call !== null ? $call['talkSeconds'] : null,
            'startedAt' => (string) $cdr->start_stamp,
            'hangupCause' => (string) $cdr->hangup_cause,
            'messages' => $messages,
            'count' => count($messages),
        ];
    }

    /**
     * Blocked addresses.
     *
     * sip_security_blocks has no tenant column: the firewall is the server's,
     * not a tenant's. That is stated in the answer rather than hidden, and the
     * rows carry attacker addresses only.
     */
    private static function securityBlocks($di, array $args): array
    {
        $limit = self::limit($args['limit'] ?? null);
        $rows = [];
        try {
            $found = $di->get('db')->fetchAll(
                "SELECT ip_address, category, reason, failure_count, jail_name, status,
                        first_seen_at, last_seen_at, blocked_at
                   FROM sip_security_blocks
                  WHERE status = 'blocked'
                  ORDER BY blocked_at DESC NULLS LAST
                  LIMIT " . (int) $limit,
                \Phalcon\Db\Enum::FETCH_ASSOC
            );
        } catch (\Throwable $e) {
            return ['blocks' => [], 'count' => 0, 'error' => 'The security block list could not be read on this server.'];
        }
        foreach ($found as $row) {
            $rows[] = [
                'ip' => (string) $row['ip_address'],
                'category' => (string) $row['category'],
                'reason' => self::clip(self::redactContact((string) $row['reason'], false), 300),
                'failures' => $row['failure_count'] === null ? null : (int) $row['failure_count'],
                'jail' => (string) $row['jail_name'],
                'firstSeenAt' => (string) $row['first_seen_at'],
                'lastSeenAt' => (string) $row['last_seen_at'],
                'blockedAt' => (string) $row['blocked_at'],
            ];
        }
        return [
            'blocks' => $rows,
            'count' => count($rows),
            'scope' => 'server',
            'note' => 'Blocks are server-wide: this firewall is not divided per tenant.',
        ];
    }

    private static function auditLog($di, array $ctx, array $args): array
    {
        $limit = self::limit($args['limit'] ?? null);
        $bind = ['d' => $ctx['domainUuid']];
        $where = 'domain_uuid = :d';
        $feature = trim((string) ($args['feature'] ?? ''));
        if ($feature !== '') {
            $where .= ' AND feature = :f';
            $bind['f'] = $feature;
        }
        $rows = [];
        try {
            $found = $di->get('db')->fetchAll(
                "SELECT id, occurred_at, username, user_fullname, extension, feature, action,
                        entity, result, ip_address, metadata
                   FROM audit_logs
                  WHERE $where
                  ORDER BY id DESC
                  LIMIT " . (int) $limit,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
        } catch (\Throwable $e) {
            return ['entries' => [], 'count' => 0, 'error' => 'The audit log could not be read on this server.'];
        }
        foreach ($found as $row) {
            $entry = [
                'id' => (int) $row['id'],
                'at' => (string) $row['occurred_at'],
                'who' => (string) ($row['user_fullname'] !== null && $row['user_fullname'] !== '' ? $row['user_fullname'] : $row['username']),
                'extension' => (string) $row['extension'],
                'feature' => (string) $row['feature'],
                'action' => (string) $row['action'],
                'entity' => self::redactContact((string) $row['entity'], false),
                'result' => (string) $row['result'],
                'ip' => (string) $row['ip_address'],
            ];
            $meta = json_decode((string) ($row['metadata'] ?? ''), true);
            if ((string) $row['feature'] === 'ai-mcp' && (string) $row['result'] === 'success' && is_array($meta) && array_key_exists('changes', $meta)) {
                $entry['changes'] = is_array($meta['changes']) ? $meta['changes'] : [];
                $entry['undo'] = is_array($meta['revert'] ?? null) ? $meta['revert'] : null;
            }
            $rows[] = $entry;
        }
        return ['entries' => $rows, 'count' => count($rows), 'limit' => $limit];
    }

    /**
     * Certificate and disk.
     *
     * The certificate is read from what the server actually SERVES on 443
     * rather than from a path on disk. nginx and FreeSWITCH read different
     * files and have been observed disagreeing for weeks — the served bytes
     * are the only answer that matches what a browser sees.
     */
    private static function sslAndStorage($di, array $ctx): array
    {
        $fqdn = self::fqdn($di, $ctx);
        $cert = ['fqdn' => $fqdn, 'notAfter' => null, 'issuer' => null, 'daysUntilExpiry' => null, 'error' => null];

        if ($fqdn !== '') {
            $command = 'echo | timeout 5 openssl s_client -servername ' . escapeshellarg($fqdn)
                . ' -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate -issuer 2>/dev/null';
            $raw = @shell_exec($command);
            if (is_string($raw) && trim($raw) !== '') {
                if (preg_match('/notAfter=(.+)/', $raw, $m)) {
                    $stamp = strtotime(trim($m[1]));
                    if ($stamp !== false) {
                        $cert['notAfter'] = gmdate('c', $stamp);
                        $cert['daysUntilExpiry'] = (int) floor(($stamp - time()) / 86400);
                    }
                }
                if (preg_match('/issuer=(.+)/', $raw, $m)) {
                    $cert['issuer'] = self::clip(trim($m[1]), 200);
                }
            }
            if ($cert['notAfter'] === null) {
                $cert['error'] = 'The served certificate could not be read from this server.';
            }
        }

        $disk = ['total' => null, 'used' => null, 'free' => null, 'pct' => null, 'mount' => '/'];
        try {
            $storage = new \Library\Storage();
            $storage->setDI($di);
            $disk = $storage->diskUsage();
        } catch (\Throwable $e) {
            // reported as nulls below rather than guessed
        }

        $notes = [];
        if ($cert['daysUntilExpiry'] !== null && $cert['daysUntilExpiry'] < 14) {
            $notes[] = 'The certificate expires in ' . $cert['daysUntilExpiry'] . ' days.';
        }
        if (isset($disk['pct']) && $disk['pct'] !== null && $disk['pct'] >= 85) {
            $notes[] = 'Disk is ' . $disk['pct'] . '% full.';
        }

        $servedHost = strtolower(trim((string) ($ctx['subdomain'] ?? '')));
        if ($servedHost === '') {
            $servedHost = strtolower($fqdn);
        }
        $served = ServiceHealth::servedCertificates($servedHost);
        foreach (ServiceHealth::servedCertificateProblems($served, $servedHost) as $problem) {
            $notes[] = $problem;
        }

        return ['certificate' => $cert, 'served' => $served, 'disk' => $disk, 'notes' => $notes];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private static function fqdn($di, array $ctx): string
    {
        $config = json_decode((string) $di->get('redis')->get('ringq_config'), true);
        $fqdn = is_array($config) ? (string) ($config['data']['fqdn'] ?? '') : '';
        return $fqdn !== '' ? $fqdn : (string) $ctx['subdomain'];
    }

    private static function forward($enabled, $destination): array
    {
        return [
            'enabled' => (string) $enabled === 'true',
            'destination' => (string) $destination,
        ];
    }

    private static function deviceType(int $type): string
    {
        if ($type === 1) {
            return 'android';
        }
        if ($type === 2) {
            return 'ios';
        }
        if ($type === 3) {
            return 'web-or-desktop';
        }
        return 'unknown';
    }

    private static function limit($value): int
    {
        $limit = (int) $value;
        if ($limit < 1) {
            return 25;
        }
        return min($limit, self::MAX_ROWS);
    }

    private static function date($value, ?string $default): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return $default === null ? null : gmdate('Y-m-d H:i:s', strtotime($default));
        }
        $stamp = strtotime($raw);
        if ($stamp === false) {
            throw new \InvalidArgumentException('date could not be read: ' . self::clip($raw, 40));
        }
        return gmdate('Y-m-d H:i:s', $stamp);
    }

    public static function redactContact(string $value, bool $phones = true): string
    {
        $value = preg_replace('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '[email removed]', $value);
        $value = preg_replace_callback('/(?<![A-Za-z0-9_-])[A-Za-z0-9]{16,}(?![A-Za-z0-9_-])/', function ($m) {
            return preg_match('/[0-9]/', $m[0]) ? '[id removed]' : $m[0];
        }, (string) $value);
        if ($phones) {
            $value = preg_replace_callback('/(?<![A-Za-z0-9])\+?[0-9][0-9 ().-]{5,}[0-9](?![A-Za-z0-9])/', function ($m) {
                return strlen(preg_replace('/[^0-9]/', '', $m[0])) >= 7 ? '[number removed]' : $m[0];
            }, (string) $value);
        }
        return (string) $value;
    }

    private static function clip(string $value, int $max = self::MAX_TEXT): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/u', ' ', $value);
        return mb_substr((string) $value, 0, $max);
    }
}
