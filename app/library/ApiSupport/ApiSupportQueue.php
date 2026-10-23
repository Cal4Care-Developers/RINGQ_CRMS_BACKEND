<?php
declare(strict_types=1);

use ElephantIO\Client;
use Phalcon\Db\Column;
require_once __DIR__ . '/../QueueName.php';

/**
 * API-key endpoints for call-center queues and queue agents:
 *   POST /index/addQueue          (add_queue)
 *   POST /index/updateQueue       (update_queue)
 *   POST /index/deleteQueue       (delete_queue)
 *   POST /index/addAgentsQueue    (add_agents_queue)
 *   POST /index/removeAgentsQueue (remove_agents_queue)
 *
 * Ported from ApiController::callcenterAction (type 1/2/3) and the bulk branch
 * of ApiController::callagentAction, scoped by domain_uuid and with a JSON
 * request instead of the UI's form fields. The optional work/break schedule
 * dialplan generation of the UI is intentionally not ported.
 */
trait ApiSupportQueue
{
    protected function apiQAudioDir(): string
    {
        return '/var/www/ringq/public/audio/';
    }

    protected function apiQStrategies(): array
    {
        return [
        'ring-all', 'longest-idle-agent', 'round-robin', 'top-down',
        'agent-with-least-talk-time', 'agent-with-fewest-calls',
        'sequentially-by-agent-order', 'random', 'ring-progressively',
    ];
    }

    protected function apiQChatKeys(): array
    {
        return [
            'facebook_chat' => ['-facebook-chat', 'num'],
            'whatsapp_chat' => ['-whatsapp-chat', 'num'],
            'webchat_chat'  => ['-webchat-chat', 'uuid'],
            'line_chat'     => ['-line-chat', 'uuid'],
            'telegram_chat' => ['-telgram-chat', 'uuid'],
            'telnyx_chat'   => ['-telnyx-chat', 'uuid'],
            'twilio_chat'   => ['-twilio-chat', 'uuid'],
        ];
    }

    

    

    

    

    

    // ------------------------------------------------------------------
    // Helpers (all prefixed apiQ to stay clear of the controller's methods)
    // ------------------------------------------------------------------

    /** Roll back an open transaction and turn the throwable into a JSON error. */
    protected function apiQFailure(\Throwable $e)
    {
        try {
            if ($this->db->isUnderTransaction()) {
                $this->db->rollback();
            }
        } catch (\Throwable $ignored) {
            // nothing to roll back
        }
        if ($e instanceof \InvalidArgumentException) {
            $code = (int)$e->getCode();
            return $this->apiFail($e->getMessage(), ($code >= 400 && $code < 500) ? $code : 400);
        }
        return $this->apiFailFrom($e, 500);
    }

    /** The UI clears these caches first on every queue/agent change. */
    protected function apiQFlushCaches(string $domain): void
    {
        $this->apiRedisDel([
            'allDialplans-' . $domain,
            'vCallCenterQueues-' . $domain,
            'vCallCenterAgents-' . $domain,
            'vCallCenterAgents5-' . $domain,
            'allVCallCenterTiers-' . $domain,
        ]);
    }

    protected function apiQExtension($value, string $field = 'extension'): int
    {
        $v = trim((string)$value);
        if (!preg_match('/^[1-9][0-9]{1,8}$/', $v)) {
            throw new \InvalidArgumentException('Invalid ' . $field . ': must be a number of 2 to 9 digits', 400);
        }
        return (int)$v;
    }

    /** True when the number is already used by a queue, extension or IVR of the domain. */
    protected function apiQExtensionTaken(string $domainUuid, string $ext): bool
    {
        $checks = [
            'SELECT 1 AS x FROM v_call_center_queues WHERE domain_uuid = :d AND queue_extension::text = :e LIMIT 1',
            'SELECT 1 AS x FROM v_extensions WHERE domain_uuid = :d AND (extension = :e OR number_alias = :e) LIMIT 1',
            'SELECT 1 AS x FROM v_ivr_menus WHERE domain_uuid = :d AND ivr_menu_extension = :e LIMIT 1',
        ];
        foreach ($checks as $sql) {
            if ($this->db->fetchOne($sql, \Phalcon\Db\Enum::FETCH_ASSOC, ['d' => $domainUuid, 'e' => $ext])) {
                return true;
            }
        }
        return false;
    }

    protected function apiQBool($v): int
    {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_int($v) || is_float($v)) {
            return ((int)$v) !== 0 ? 1 : 0;
        }
        return in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on', 'y'], true) ? 1 : 0;
    }

    /** Integer field in [min,max]; $default when the key is absent or empty. */
    protected function apiQInt(array $d, string $key, int $min, int $max, ?int $default): ?int
    {
        if (!array_key_exists($key, $d) || $d[$key] === null || $d[$key] === '') {
            return $default;
        }
        $v = $d[$key];
        if (is_bool($v)) {
            $v = (int)$v;
        }
        $ok = is_int($v)
            || (is_float($v) && floor($v) == $v)
            || (is_string($v) && preg_match('/^-?[0-9]+$/', trim($v)));
        if (!$ok) {
            throw new \InvalidArgumentException('Invalid ' . $key . ': must be a whole number', 400);
        }
        $i = (int)(is_string($v) ? trim($v) : $v);
        if ($i < $min || $i > $max) {
            throw new \InvalidArgumentException('Invalid ' . $key . ': must be between ' . $min . ' and ' . $max, 400);
        }
        return $i;
    }

    /** Audio file NAME only (no path components); '' means "none". */
    protected function apiQAudio($v, string $field, bool $allowSpace = true): string
    {
        if ($v === null) {
            return '';
        }
        if (!is_string($v)) {
            throw new \InvalidArgumentException('Invalid ' . $field . ': must be a file name', 400);
        }
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if (strpos($v, '/') !== false || strpos($v, '\\') !== false || strpos($v, '..') !== false
            || strlen($v) > 255 || !preg_match($allowSpace ? '/^[\p{L}\p{N}_.\-() ]+$/u' : '/^[\p{L}\p{N}_.\-()]+$/u', $v)) {
            throw new \InvalidArgumentException('Invalid ' . $field . ': must be a plain audio file name', 400);
        }
        return $v;
    }

    protected function apiQStripAudioDir($path): string
    {
        $path = (string)$path;
        $dir = $this->apiQAudioDir();
        return strpos($path, $dir) === 0 ? substr($path, strlen($dir)) : '';
    }

    protected function apiQX($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected function apiQDropFromList(string $csv, string $ext): string
    {
        if ($csv === '') {
            return '';
        }
        return implode(',', array_values(array_diff(explode(',', $csv), [$ext])));
    }

    /** Map the request (and, on update, the stored row) to one canonical settings array. */
    protected function apiQSettings(array $d, ?object $row): array
    {
        $has = static function (string $k) use ($d): bool {
            return array_key_exists($k, $d);
        };

        // Current values: defaults for add, stored values for update.
        $desc = $row ? explode('-', (string)$row->queue_description) : [];
        $desc = array_pad($desc, 6, '0');
        $s = [
            'name'          => $row ? (string)$row->queue_name : '',
            'strategy'      => $row ? (string)$row->queue_strategy : 'ring-all',
            't_type'        => 'hangup',
            't_value'       => '',
            'greeting'      => $row ? $this->apiQStripAudioDir($row->queue_greeting) : '',
            'moh'           => $row ? $this->apiQStripAudioDir($row->queue_moh_sound) : '',
            'hold1'         => $row ? $this->apiQStripAudioDir($row->queue_messsage_on_hold) : '',
            'hold2'         => $row ? $this->apiQStripAudioDir($row->queue_messsage_on_hold2) : '',
            'hold3'         => $row ? $this->apiQStripAudioDir($row->queue_messsage_on_hold3) : '',
            'hold_interval' => $row ? (int)$row->queue_messsage_interval : 0,
            'max_wait'      => $row ? (int)$row->queue_max_wait_time : 0,
            'max_callers'   => $row ? (int)$row->queue_max_callers : 0,
            'auto_answer'   => $row ? (int)$row->queue_auto_answer : 0,
            'announce_pos'  => $row ? (int)$row->queue_announce_position : 0,
            'announce_freq' => $row && $row->queue_announce_frequency !== null ? (int)$row->queue_announce_frequency : null,
            'skill'         => $row ? ((string)$row->queue_tier_rules_apply === 'true' ? 1 : 0) : 0,
            'base_score'    => $row ? (int)$row->queue_time_base_score_sec : 0,
            'agent_wrap'    => (int)$desc[0],
            'sla'           => (int)$desc[1],
            'call_timeout'  => $row ? (int)$desc[2] : 60,
            'wrap_up'       => (int)$desc[3],
            'show_contact'  => (int)$desc[4],
            'show_scripts'  => (int)$desc[5],
            'script'        => '',
            'script_provided' => false,
            'sla_notify'        => 0,
            'callback_success'  => 0,
            'callback_fail'     => 0,
            'call_lost'         => 0,
            'click2talk_mode'   => 1,
            'callback'          => null,
            'callback_custom'   => null,
            'landing'           => null,
            'landing2'          => null,
            'landing_url'       => null,
        ];

        if ($row) {
            $ta = (string)$row->queue_timeout_action;
            if (preg_match('/^transfer:\*99(\S+) XML /', $ta, $m)) {
                $s['t_type'] = 'voicemail';
                $s['t_value'] = $m[1];
            } elseif (preg_match('/^transfer:(\S+) XML /', $ta, $m)) {
                $s['t_type'] = 'extension';
                $s['t_value'] = $m[1];
            }
            $n = QQueueNotifications::findFirst([
                'conditions' => 'domain_uuid = :d: AND queue_extension = :e:',
                'bind'       => ['d' => (string)$row->domain_uuid, 'e' => (int)$row->queue_extension],
                'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if ($n) {
                $s['sla_notify'] = (int)$n->queue_sla_notify;
                $s['callback_success'] = (int)$n->queue_callback_success;
                $s['callback_fail'] = (int)$n->queue_callback_fail;
                $s['call_lost'] = (int)$n->queue_call_lost;
            }
        }

        // Overlay request values.
        if ($has('name')) {
            $name = is_string($d['name']) ? trim($d['name']) : '';
            $nameError = ($row && trim((string)$row->queue_name) === $name) ? null : QueueName::formatError($name);
            if ($nameError !== null) {
                throw new \InvalidArgumentException('Invalid name: ' . $nameError, 400);
            }
            $s['name'] = $name;
        }
        if ($s['name'] === '') {
            throw new \InvalidArgumentException('Missing parameters: name', 400);
        }

        if ($has('strategy')) {
            $strategy = strtolower(str_replace([' ', '_'], '-', trim((string)$d['strategy'])));
            if (!in_array($strategy, $this->apiQStrategies(), true)) {
                throw new \InvalidArgumentException('Invalid strategy: allowed ' . implode(', ', $this->apiQStrategies()), 400);
            }
            $s['strategy'] = $strategy;
        }
        if (!in_array($s['strategy'], $this->apiQStrategies(), true)) {
            $s['strategy'] = 'ring-all';
        }

        if ($has('type') && $d['type'] !== null && $d['type'] !== '') {
            $map = ['1' => 'extension', '2' => 'voicemail', '3' => 'hangup', 'extension' => 'extension', 'voicemail' => 'voicemail', 'hangup' => 'hangup'];
            $t = strtolower(trim((string)$d['type']));
            if (!isset($map[$t])) {
                throw new \InvalidArgumentException("Invalid type: use 'extension', 'voicemail' or 'hangup'", 400);
            }
            $s['t_type'] = $map[$t];
            if ($s['t_type'] === 'hangup') {
                $s['t_value'] = '';
            }
        }
        if ($has('timeout_action_value') && $d['timeout_action_value'] !== null) {
            $s['t_value'] = trim((string)$d['timeout_action_value']);
        }
        if ($s['t_type'] !== 'hangup') {
            if (!preg_match('/^[0-9]{1,15}$/', $s['t_value'])) {
                throw new \InvalidArgumentException('Invalid timeout_action_value: a number is required for type ' . $s['t_type'], 400);
            }
        } else {
            $s['t_value'] = '';
        }

        if ($has('greeting')) {
            $s['greeting'] = $this->apiQAudio($d['greeting'], 'greeting');
        }
        if ($has('moh_sound')) {
            $s['moh'] = $this->apiQAudio($d['moh_sound'], 'moh_sound');
        }
        foreach (['message_on_hold' => 'hold1', 'message_on_hold2' => 'hold2', 'message_on_hold3' => 'hold3'] as $req => $key) {
            if ($has($req)) {
                $s[$key] = $this->apiQAudio($d[$req], $req, false);
            }
        }
        $s['hold_interval'] = $this->apiQInt($d, 'message_interval', 0, 3600, $s['hold_interval']);
        $s['max_wait'] = $this->apiQInt($d, 'max_wait_time', 0, 86400, $s['max_wait']);
        $s['max_callers'] = $this->apiQInt($d, 'max_callers', 0, 10000, $s['max_callers']);
        $s['call_timeout'] = $this->apiQInt($d, 'agent_call_timeout', 0, 3600, $s['call_timeout']);
        $s['wrap_up'] = $this->apiQInt($d, 'wrap_up_time', 0, 3600, $s['wrap_up']);
        $s['agent_wrap'] = $this->apiQInt($d, 'agent_wrap_up_time', 0, 3600, $s['agent_wrap']);
        $s['sla'] = $this->apiQInt($d, 'sla', 0, 86400, $s['sla']);
        $s['announce_pos'] = $this->apiQInt($d, 'announce_position', 0, 3600, $s['announce_pos']);
        $s['announce_freq'] = $this->apiQInt($d, 'announce_frequency', 0, 3600, $s['announce_freq']);
        $s['base_score'] = $this->apiQInt($d, 'time_base_score_sec', 0, 86400, $s['base_score']);
        foreach (['auto_answer' => 'auto_answer', 'skill_based' => 'skill', 'show_contact' => 'show_contact', 'show_scripts' => 'show_scripts',
                  'sla_notify' => 'sla_notify', 'callback_success' => 'callback_success', 'callback_fail' => 'callback_fail',
                  'call_lost' => 'call_lost'] as $req => $key) {
            if ($has($req) && $d[$req] !== null) {
                $s[$key] = $this->apiQBool($d[$req]);
            }
        }
        if ($has('click2talk_mode') && $d['click2talk_mode'] !== null) {
            $s['click2talk_mode'] = $this->apiQInt($d, 'click2talk_mode', 0, 9, 1);
        }
        foreach (['callback' => 'callback', 'callback_custom' => 'callback_custom'] as $req => $key) {
            if ($has($req) && $d[$req] !== null) {
                $s[$key] = $d[$req] === 2 || $d[$req] === '2' || $this->apiQBool($d[$req]) === 1;
            }
        }
        foreach (['call_landing' => 'landing', 'call_landing2' => 'landing2'] as $req => $key) {
            if ($has($req) && is_string($d[$req])) {
                $s[$key] = mb_substr(trim($d[$req]), 0, 100);
            }
        }
        if ($has('call_landing_url') && is_string($d['call_landing_url'])) {
            $s['landing_url'] = mb_substr(trim($d['call_landing_url']), 0, 500);
        }
        if ($has('script')) {
            $script = $d['script'];
            if ($script !== null && !is_string($script)) {
                throw new \InvalidArgumentException('Invalid script: must be a string', 400);
            }
            $script = (string)$script;
            if (strlen($script) > 100000) {
                throw new \InvalidArgumentException('Invalid script: too long', 400);
            }
            $s['script'] = $script;
            $s['script_provided'] = true;
        }
        return $s;
    }

    /** Copy canonical settings onto a VCallCenterQueues model (shared by add and update). */
    protected function apiQApplySettings(object $q, array $s, string $domain): void
    {
        $dir = $this->apiQAudioDir();
        $q->queue_name = $s['name'];
        $q->queue_extension = (string)$s['extension'];
        $q->queue_strategy = $s['strategy'];
        $q->queue_greeting = $s['greeting'] !== '' ? $dir . $s['greeting'] : null;
        $q->queue_moh_sound = $s['moh'] !== '' ? $dir . $s['moh'] : 'local_stream://default';
        $q->queue_messsage_on_hold = $s['hold1'] !== '' ? $dir . $s['hold1'] : null;
        $q->queue_messsage_on_hold2 = $s['hold2'] !== '' ? $dir . $s['hold2'] : null;
        $q->queue_messsage_on_hold3 = $s['hold3'] !== '' ? $dir . $s['hold3'] : null;
        $q->queue_messsage_interval = $s['hold_interval'] > 0 ? $s['hold_interval'] : null;
        $q->queue_announce_frequency = $s['announce_freq'];
        $q->queue_announce_position = $s['announce_pos'];
        $q->queue_description = implode('-', [
            $s['agent_wrap'], $s['sla'], $s['call_timeout'], $s['wrap_up'], $s['show_contact'], $s['show_scripts'],
        ]);
        $q->queue_time_base_score = 'system';
        $q->queue_time_base_score_sec = $s['base_score'];
        $q->queue_max_wait_time = $s['max_wait'];
        $q->queue_max_wait_time_with_no_agent = $s['max_wait'];
        $q->queue_max_wait_time_with_no_agent_time_reached = $s['max_wait'];
        $q->queue_timeout_action = $this->apiQTimeoutAction($s, $domain)[0];
        $q->queue_tier_rules_apply = $s['skill'] === 1 ? 'true' : 'false';
        $q->queue_tier_rule_wait_second = 15;
        $q->queue_tier_rule_wait_multiply_level = 'true';
        $q->queue_tier_rule_no_agent_no_wait = 'true';
        $q->queue_abandoned_resume_allowed = 'false';
        $q->queue_max_callers = $s['max_callers'];
        $q->queue_auto_answer = $s['auto_answer'];
    }

    /** @return array{0:string,1:string,2:string} [stored value, dialplan application, dialplan data] */
    protected function apiQTimeoutAction(array $s, string $domain): array
    {
        if ($s['t_type'] === 'extension') {
            $data = $s['t_value'] . ' XML ' . $domain;
            return ['transfer:' . $data, 'transfer', $data];
        }
        if ($s['t_type'] === 'voicemail') {
            $data = '*99' . $s['t_value'] . ' XML ' . $domain;
            return ['transfer:' . $data, 'transfer', $data];
        }
        return ['hangup:', 'hangup', ''];
    }

    protected function apiQDialplanXml(array $s, string $domain, string $dialplanUuid, string $queueUuid): string
    {
        $x = function ($v): string {
            return $this->apiQX($v);
        };
        $ext = (string)$s['extension'];
        $dir = $this->apiQAudioDir();
        $baseScore = $s['base_score'] != 0
            ? '<action application="set" data="cc_base_score=' . $x($s['base_score']) . '"/>' : '';
        $greeting = $s['greeting'] !== ''
            ? '<action application="playback" data="' . $x($dir . $s['greeting']) . '"/>' : '';
        $announcePos = $s['announce_pos'] != 0
            ? '<action application="set" data="result=${luarun(callcenter-announce-position.lua ${uuid} ' . $x($ext . '@' . $domain) . ' ' . (($s['announce_pos'] - 1) * 1000) . ')}"/>' : '';
        $holdInterval = $s['hold_interval'] != 0
            ? '<action application="set" data="result=${luarun(callcenter-announce-messages.lua ${uuid} ' . $x($ext . '@' . $domain) . ' ' . ($s['hold_interval'] * 1000) . ' ' . $x($s['hold1']) . ' ' . $x($s['hold2']) . ' ' . $x($s['hold3']) . ')}"/>' : '';
        $showContact = $s['show_contact'] === 1 ? '*' : '';
        $autoAnswer = $s['auto_answer'] === 1 ? '*' : '';
        [, $app, $appData] = $this->apiQTimeoutAction($s, $domain);

        return '<extension name="' . $x($s['name']) . '" continue="" uuid="' . $x($dialplanUuid) . '">
                        <condition field="destination_number" expression="^([^#]+#)(.*)$" break="never">
                            <action application="set" data="caller_id_name=$2"/>
                        </condition>
                        <condition field="destination_number" expression="^(callcenter\+)?' . $x($ext) . '$">
                            <action application="answer" data=""/>
                            <action application="set" data="caller_id_name=$2' . $showContact . '(' . $x($ext) . ')' . $autoAnswer . '"/>
                            <action application="set" data="call_center_queue_uuid=' . $x($queueUuid) . '"/>
                            <action application="set" data="queue_extension=' . $x($ext) . '"/>
                            <action application="set" data="cc_export_vars=${cc_export_vars},call_center_queue_uuid,sip_h_Alert-Info,sip_h_X-Original-Call-ID=${sip_call_id}"/>
                            <action application="set" data="hangup_after_bridge=true"/>
                            ' . $baseScore . '
                            ' . $announcePos . '
                            ' . $holdInterval . '
                            <action application="sleep" data="1000"/>
                            ' . $greeting . '
                            <action application="callcenter" data="' . $x($ext . '@' . $domain) . '"/>
                            <action application="' . $x($app) . '" data="' . $x($appData) . '"/>
                        </condition>
                    </extension>';
    }

    /** Redis keys the UI writes for a queue; strings only (strict_types). */
    protected function apiQWriteRedis(array $s, string $domain, bool $isAdd): void
    {
        $ext = (string)$s['extension'];
        $suffix = '@' . $domain;
        $settings = 'queueSettings' . $ext . $suffix;
        $this->redis->hset($settings, 'wrap_up', (string)$s['wrap_up']);
        $this->redis->hset($settings, 'queue_show_contact', (string)$s['show_contact']);
        $this->redis->hset($settings, 'queue_script', $s['show_scripts'] > 0 ? '1' : '0');

        if ($s['callback'] !== null) {
            $s['callback'] ? $this->redis->set($ext . $suffix . '-callBack', '1') : $this->redis->del($ext . $suffix . '-callBack');
        }
        if ($s['callback_custom'] !== null) {
            $s['callback_custom'] ? $this->redis->set($ext . $suffix . '-callBackCustom', '1') : $this->redis->del($ext . $suffix . '-callBackCustom');
        }
        // The UI writes these defaults on every save; keep that but never overwrite stored values on a partial update.
        $landing = [
            '-queue_call_landing'     => [$s['landing'], 'Do Nothing'],
            '-queue_call_landing2'    => [$s['landing2'], 'Do Nothing'],
            '-queue_call_landing_url' => [$s['landing_url'], ''],
        ];
        foreach ($landing as $suffixKey => [$value, $default]) {
            if ($value !== null && $value !== '') {
                $this->redis->set($ext . $suffix . $suffixKey, (string)$value);
            } elseif ($isAdd || ($value !== null)) {
                $this->redis->set($ext . $suffix . $suffixKey, $default);
            }
        }
        $this->redis->set($ext . '-queuemaxcallers' . $suffix, $s['max_callers'] != 0 ? (string)$s['max_callers'] : '1000');
        $this->redis->set($ext . $suffix . '-click2talk-mode', (string)$s['click2talk_mode']);
        $this->redis->set('callboardReset-' . $domain, '1');
    }

    /**
     * Apply chat-channel lists when present in the request (comma string or array),
     * keep the stored ones otherwise, and return the chatSettings socket payload
     * (null when the request did not touch any channel).
     */
    protected function apiQUpdateChatKeys(array $d, int $ext, string $domain): ?array
    {
        $touched = false;
        foreach (array_keys($this->apiQChatKeys()) as $k) {
            if (array_key_exists($k, $d)) {
                $touched = true;
            }
        }
        if (!$touched) {
            return null;
        }
        $base = $ext . '@' . $domain;
        $out = [];
        foreach ($this->apiQChatKeys() as $field => [$suffix, $kind]) {
            $key = $base . $suffix;
            if (array_key_exists($field, $d)) {
                $items = is_array($d[$field]) ? $d[$field] : explode(',', (string)$d[$field]);
                $clean = [];
                foreach ($items as $item) {
                    $item = trim((string)$item);
                    $valid = $kind === 'num'
                        ? is_numeric($item)
                        : (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item);
                    if ($valid) {
                        $clean[] = $item;
                    }
                }
                $this->redis->del($key);
                if ($clean) {
                    $this->redis->lpush($key, ...$clean);
                }
                $out[$field] = $clean;
            } else {
                $cur = $this->redis->lRange($key, 0, -1);
                $out[$field] = is_array($cur) ? $cur : [];
            }
        }
        return [
            'subdomain' => $domain,
            'extension' => $ext,
            'facebook'  => $out['facebook_chat'],
            'whatsapp'  => $out['whatsapp_chat'],
            'website'   => $out['webchat_chat'],
            'line'      => $out['line_chat'],
            'telegram'  => $out['telegram_chat'],
            'telnyx'    => $out['telnyx_chat'],
            'twilio'    => $out['twilio_chat'],
        ];
    }

    /** @return string[] unique, validated extension numbers from an array or comma string. */
    protected function apiQExtList($value): array
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $out = [];
        foreach ($items as $item) {
            if (is_array($item) || is_object($item)) {
                throw new \InvalidArgumentException('Invalid extensions: expected numbers', 400);
            }
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $item)) {
                throw new \InvalidArgumentException('Invalid extension: ' . mb_substr($item, 0, 32), 400);
            }
            $out[$item] = $item;
        }
        if (!$out) {
            throw new \InvalidArgumentException('Missing parameters: extensions', 400);
        }
        if (count($out) > 500) {
            throw new \InvalidArgumentException('Too many extensions (max 500)', 400);
        }
        return array_values($out);
    }

    /** Tier level per extension: scalar applies to all, array/comma list is positional. @return int[] */
    protected function apiQLevels($value, int $count): array
    {
        $levels = array_fill(0, $count, 0);
        if ($value === null || $value === '' || $value === []) {
            return $levels;
        }
        $items = is_array($value) ? array_values($value) : explode(',', (string)$value);
        foreach ($items as $i => $item) {
            $item = trim((string)$item);
            if ($item !== '' && !preg_match('/^[0-9]{1,4}$/', $item)) {
                throw new \InvalidArgumentException('Invalid tier_level: must be numbers', 400);
            }
        }
        foreach ($levels as $i => $_) {
            $item = count($items) === 1 ? $items[0] : ($items[$i] ?? 0);
            $levels[$i] = (int)trim((string)$item);
        }
        return $levels;
    }

    /**
     * Look up extensions of the domain together with their linked user.
     *
     * @param string[] $extensions
     * @return array<string,array> keyed by extension number; missing = not found
     */
    protected function apiQResolveExtensions(string $domainUuid, array $extensions): array
    {
        $ph = [];
        $params = ['d' => $domainUuid];
        foreach ($extensions as $i => $e) {
            $ph[] = ':e' . $i;
            $params['e' . $i] = $e;
        }
        $rows = $this->db->fetchAll(
            'SELECT e.extension, eu.user_uuid, u.username, qu.first_name, qu.last_name '
            . 'FROM v_extensions e '
            . 'LEFT JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid '
            . 'LEFT JOIN v_users u ON u.user_uuid = eu.user_uuid '
            . 'LEFT JOIN q_users qu ON qu.user_uuid = eu.user_uuid '
            . 'WHERE e.domain_uuid = :d AND e.extension IN (' . implode(',', $ph) . ')',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $key = (string)$r['extension'];
            if (!isset($out[$key]) || ((string)$out[$key]['username'] === '' && (string)($r['username'] ?? '') !== '')) {
                $out[$key] = [
                    'user_uuid'  => (string)($r['user_uuid'] ?? ''),
                    'username'   => (string)($r['username'] ?? ''),
                    'first_name' => (string)($r['first_name'] ?? ''),
                    'last_name'  => (string)($r['last_name'] ?? ''),
                ];
            }
        }
        return $out;
    }
}
