<?php
declare(strict_types=1);

/**
 * AiCallRecord -- what the AI receptionist did on one call: transcript, hand-off note, outcome.
 *
 * The voice bridge (node/claude_server.js) POSTs this to /teamsai/saveaicall twice per transferred
 * call (an early copy as the hand-off is decided, the final one when the bridge ends) and once for a
 * call the AI handled alone. Both copies are MERGED here, never blindly overwritten.
 *
 * WHERE IT LIVES
 *   Redis  aicall:{domainUuid}:{callUuid}   JSON, 30-day TTL. Full detail; this is what the human
 *          agent's hand-off screen reads (GET /teamsai/getaicall?callUuid=...). The call uuid is the
 *          key because FreeSWITCH keeps it across uuid_transfer, so whoever picks up already has it.
 *   QRingqCdr.json  the existing {summary, sentiment, transcribedText} blob Reports already reads and
 *          shows, with an extra "ai" object. The CDR row is written by FreeSWITCH only when the call
 *          ends, so it often does not exist yet when the bridge reports: attachToCdr() is called both
 *          at save time and lazily from ReportsController when it opens a call, and whichever finds
 *          the row first writes it.
 *
 * RULES
 *   - A Whisper transcript that a person already generated from the recording (json without
 *     source = "ai") is never overwritten.
 *   - Text going into the CDR blob is htmlspecialchars()-escaped, exactly like ReportsController does
 *     for its own transcripts, so both render the same way. The Redis copy is raw; render it as text.
 */
class AiCallRecord
{
    const TTL_SECONDS    = 2592000;   // 30 days
    const MAX_TURNS      = 400;
    const MAX_TURN_CHARS = 2000;
    const MAX_CDR_TEXT   = 60000;     // chars of transcript written to the CDR column

    public static function key(string $domainUuid, string $callUuid): string
    {
        return "aicall:{$domainUuid}:{$callUuid}";
    }

    const RECENT_TTL_SECONDS = 900;   // a hand-off note is only "the one for this ring" for 15 minutes

    /**
     * Caller number -> latest hand-off, for the web app. The browser phone is only told who is calling
     * (a number), not FreeSWITCH's call uuid, so it looks the note up by caller number -- the same way
     * its Queue Call Landing popup matches a ringing call. Compared on the last 9 digits, so
     * +65 9123 4567 / 6591234567 / 91234567 all meet; shorter numbers (internal extensions) match whole.
     */
    public static function numberKey(string $number): string
    {
        $digits = preg_replace('/\D+/', '', $number);
        return $digits === '' ? '' : substr($digits, -9);
    }

    public static function recentKey(string $domainUuid, string $number): string
    {
        return "aicall_recent:{$domainUuid}:" . self::numberKey($number);
    }

    /** Remembers this record as the latest hand-off for its caller number. Only hand-offs are indexed. */
    public static function indexRecent($redis, string $domainUuid, array $rec): void
    {
        if (self::numberKey($rec['callerNumber']) === '' || $rec['transferTo'] === '') { return; }
        $redis->setex(self::recentKey($domainUuid, $rec['callerNumber']), self::RECENT_TTL_SECONDS, $rec['callUuid']);
    }

    const REDELIVER_WINDOW_SECONDS = 60;   // the same person ringing again right after (a queue re-ring, a retry) still gets it

    /**
     * Each hand-off note is shown to a given person ONCE. The caller number is only a hint for "which call
     * is this", so without this a customer who rang the AI, was transferred to 1001 and then rang 1001
     * directly ten minutes later would meet the old summary again. The first lookup by a person claims the
     * note; that same person's lookups within the next minute (the web app retries while the note may
     * still be being saved) still succeed, anything later is refused. setnx/expire work on both Redis
     * clients this app can run on.
     */
    public static function claimDelivery($redis, string $domainUuid, string $callUuid, string $userUuid): bool
    {
        if ($userUuid === '') { return false; }
        $key = "aicall_seen:{$domainUuid}:{$callUuid}:{$userUuid}";
        $now = time();
        if ($redis->setnx($key, (string) $now)) {
            $redis->expire($key, self::TTL_SECONDS);
            return true;
        }
        $first = (int) $redis->get($key);
        return $first > 0 && ($now - $first) <= self::REDELIVER_WINDOW_SECONDS;
    }

    /** The latest hand-off for this caller number in this tenant, or null. */
    public static function findRecent($redis, string $domainUuid, string $number): ?array
    {
        if (self::numberKey($number) === '') { return null; }
        $uuid = (string) $redis->get(self::recentKey($domainUuid, $number));
        return self::isCallUuid($uuid) ? self::load($redis, $domainUuid, $uuid) : null;
    }

    public static function isCallUuid(string $v): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v);
    }

    private static function str($v, int $max): string
    {
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    /** Whitelists and size-limits what the bridge sent. Never trusts a field's shape. */
    public static function clean(array $in): array
    {
        $turns = [];
        foreach ((array) ($in['transcript'] ?? []) as $t) {
            if (!is_array($t)) { continue; }
            $role = ($t['role'] ?? '') === 'caller' ? 'caller' : (($t['role'] ?? '') === 'ai' ? 'ai' : '');
            $text = self::str($t['text'] ?? '', self::MAX_TURN_CHARS);
            if ($role === '' || $text === '') { continue; }
            $turns[] = ['role' => $role, 'text' => $text, 'at' => (int) ($t['at'] ?? 0)];
            if (count($turns) >= self::MAX_TURNS) { break; }
        }
        $details = [];
        foreach ((array) ($in['details'] ?? []) as $d) {
            $d = self::str($d, 200);
            if ($d !== '') { $details[] = $d; }
            if (count($details) >= 12) { break; }
        }
        $sentiment = strtolower(self::str($in['sentiment'] ?? '', 20));
        $outcome   = self::str($in['outcome'] ?? '', 30);
        return [
            'callUuid'       => self::str($in['callUuid'] ?? '', 40),
            'callerNumber'   => self::str($in['callerNumber'] ?? '', 40),
            'callerName'     => self::str($in['callerName'] ?? '', 80),
            'agentExtension' => self::str($in['agentExtension'] ?? '', 20),
            'agentName'      => self::str($in['agentName'] ?? '', 80),
            'voiceMode'      => self::str($in['voiceMode'] ?? '', 20),
            'outcome'        => in_array($outcome, ['ai_handled', 'transferred', 'transfer_failed'], true) ? $outcome : '',
            'transferTo'     => self::str($in['transferTo'] ?? '', 60),
            'transferReason' => self::str($in['transferReason'] ?? '', 200),
            'startedAt'      => (int) ($in['startedAt'] ?? 0),
            'endedAt'        => (int) ($in['endedAt'] ?? 0),
            'final'          => !empty($in['final']),
            'summary'        => self::str($in['summary'] ?? '', 1000),
            'sentiment'      => in_array($sentiment, ['positive', 'neutral', 'negative'], true) ? $sentiment : '',
            'intent'         => self::str($in['intent'] ?? '', 80),
            'nextStep'       => self::str($in['nextStep'] ?? '', 300),
            'details'        => $details,
            'transcript'     => $turns,
        ];
    }

    /**
     * Folds a new copy into the stored one. A later copy fills gaps and extends the transcript; it
     * does not blank out a summary the earlier copy had (the final copy can lose its summary request
     * to a timeout while the hand-off copy got one), and an early copy never undoes a final outcome.
     */
    public static function merge(?array $old, array $new): array
    {
        if (!$old) {
            $new['updatedAt'] = time();
            return $new;
        }
        $out = $new;
        foreach (['summary', 'sentiment', 'intent', 'nextStep', 'callerName', 'agentName', 'transferTo', 'transferReason',
                  'callerNumber', 'agentExtension', 'voiceMode'] as $f) {
            if (($new[$f] ?? '') === '') { $out[$f] = $old[$f] ?? ''; }
        }
        if (empty($new['details'])) { $out['details'] = $old['details'] ?? []; }
        if (count($old['transcript'] ?? []) > count($new['transcript'])) { $out['transcript'] = $old['transcript']; }
        if (!empty($old['final']) && empty($new['final'])) {
            $out['outcome'] = $old['outcome'] ?? $new['outcome'];
            $out['final']   = true;
        }
        if (($new['outcome'] ?? '') === '') { $out['outcome'] = $old['outcome'] ?? ''; }
        $out['startedAt'] = ($old['startedAt'] ?? 0) ?: $new['startedAt'];
        $out['endedAt']   = max((int) ($old['endedAt'] ?? 0), (int) $new['endedAt']);
        $out['updatedAt'] = time();
        return $out;
    }

    public static function load($redis, string $domainUuid, string $callUuid): ?array
    {
        $raw = $redis->get(self::key($domainUuid, $callUuid));
        if (!$raw) { return null; }
        $rec = json_decode((string) $raw, true);
        return is_array($rec) ? $rec : null;
    }

    public static function store($redis, string $domainUuid, array $rec): void
    {
        $redis->setex(self::key($domainUuid, (string) $rec['callUuid']), self::TTL_SECONDS, json_encode($rec));
    }

    /** "Caller: ..." / "AI: ..." lines, the shape Reports shows as transcribedText. */
    public static function transcriptText(array $rec): string
    {
        $lines = [];
        foreach ($rec['transcript'] ?? [] as $t) {
            $lines[] = ($t['role'] === 'caller' ? 'Caller: ' : 'AI: ') . $t['text'];
        }
        $text = implode("\n", $lines);
        return mb_strlen($text) > self::MAX_CDR_TEXT ? mb_substr($text, 0, self::MAX_CDR_TEXT) . "\n..." : $text;
    }

    /** The JSON written to QRingqCdr.json: Reports' own three keys, plus an "ai" object. */
    public static function toCdrJson(array $rec): string
    {
        $esc = static function ($v) { return htmlspecialchars((string) $v); };
        return json_encode([
            'summary'         => $esc($rec['summary'] !== '' ? $rec['summary'] : 'No summary available.'),
            'sentiment'       => $rec['sentiment'] !== '' ? $rec['sentiment'] : 'neutral',
            'transcribedText' => $esc(self::transcriptText($rec)),
            'source'          => 'ai',
            'ai' => [
                'intent'         => $esc($rec['intent']),
                'callerName'     => $esc($rec['callerName']),
                'details'        => array_map($esc, $rec['details']),
                'nextStep'       => $esc($rec['nextStep']),
                'outcome'        => $rec['outcome'],
                'transferTo'     => $esc($rec['transferTo']),
                'transferReason' => $esc($rec['transferReason']),
                'agentName'      => $esc($rec['agentName']),
                'voiceMode'      => $rec['voiceMode'],
                'turns'          => count($rec['transcript']),
            ],
        ]);
    }

    /**
     * For ReportsController: a CDR row about to be shown with an empty json column picks up the AI
     * record kept in Redis for that call, if there is one. Uses the row's own domain, so it can only
     * ever read the record of the tenant the row belongs to. Never throws.
     */
    public static function fillCdrJson($redis, $cdr): void
    {
        try {
            if (!empty($cdr->json)) { return; }
            $rec = self::load($redis, (string) $cdr->domain_uuid, (string) $cdr->xml_cdr_uuid);
            if (!$rec) { return; }
            $cdr->json = self::toCdrJson($rec);
            $cdr->update();
        } catch (\Throwable $e) {
            error_log('AiCallRecord::fillCdrJson: ' . $e->getMessage());
        }
    }

    /**
     * Writes the stored record into this call's QRingqCdr row, if the row exists and does not already
     * hold a transcript a person generated. Returns true when the row now carries the AI record.
     * Never throws: Redis keeps the record, and the next attempt (Reports opening the call) retries.
     */
    public static function attachToCdr($redis, string $domainUuid, string $callUuid): bool
    {
        try {
            $rec = self::load($redis, $domainUuid, $callUuid);
            if (!$rec) { return false; }

            $cdr = \QRingqCdr::findFirst([
                'conditions' => 'xml_cdr_uuid = :xml_cdr_uuid: AND domain_uuid = :domain_uuid:',
                'bind'       => ['xml_cdr_uuid' => $callUuid, 'domain_uuid' => $domainUuid],
            ]);
            if (!$cdr) { return false; }

            if (!empty($cdr->json)) {
                $existing = json_decode((string) $cdr->json, true);
                if (!is_array($existing) || ($existing['source'] ?? '') !== 'ai') {
                    return false;   // somebody's own transcript of the recording -- leave it
                }
            }
            $cdr->json = self::toCdrJson($rec);
            return (bool) $cdr->update();
        } catch (\Throwable $e) {
            error_log('AiCallRecord::attachToCdr ' . $callUuid . ': ' . $e->getMessage());
            return false;
        }
    }
}
