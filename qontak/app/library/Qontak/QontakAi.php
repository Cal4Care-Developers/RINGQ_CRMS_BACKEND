<?php
declare(strict_types=1);

namespace Library\Qontak;

/**
 * Optional AI call intelligence for Qontak journals.
 *
 * Qontak CRM's API has no transcription / summary / sentiment endpoints, so
 * these come from providers configured on OUR side and the results are written
 * into the Qontak task's detail. Each capability has its own provider so a
 * real one can be plugged in later without touching the sync code.
 *
 * Shipped providers:
 *   - RingqTranscriptProvider: RingQ's existing per-tenant transcription
 *     (same settings and services as /reports/audiototext; result stored in q_ringq_cdr.json).
 *   - None for summary and sentiment. RingQ's current pipeline stores the
 *     placeholders "No Summary Available" / "neutral", which are NOT real
 *     analysis, so they are ignored rather than reported as results.
 */
interface QontakTranscriptProvider
{
    /**
     * @return array{status:string, text:?string, reason:?string} status: done | pending | unavailable
     */
    public function transcript(array $cdr, string $subdomain): array;
}

interface QontakSummaryProvider
{
    /** @return array{summary:?string, sentiment:?string} or null when not configured */
    public function analyze(string $transcript): ?array;
}

class RingqTranscriptProvider implements QontakTranscriptProvider
{
    const PLACEHOLDER_SUMMARY = 'No Summary Available';
    const NO_TEXT = 'No transcription available.';

    /** True after the dialer has its HTTP answer (post-hangup work), so transcribing cannot delay it. */
    public static $allowInline = false;

    /** True for a quick pass (save call / recording link) that must not start a transcription. */
    public static $hold = false;

    private $redis;

    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    /**
     * Same transcription RingQ's own Call Logs use (ReportsController::audiototextAction):
     * the tenant's AI settings in Redis (ai:<subdomain>-transcribeTokenEnabled / -aitranscripttype /
     * -transcribeToken / -ringq_model) and the call's local recording file. Runs in-process, so no
     * internal HTTP key is needed. A fresh transcription only runs from the cron task (CLI), never
     * inside a dialer request.
     *
     * @return array{status:string, text:?string, reason:?string}
     */
    public function transcript(array $cdr, string $subdomain): array
    {
        $stored = self::parseStored((string) ($cdr['json'] ?? ''));
        if ($stored !== null) {
            return ['status' => 'done', 'text' => $stored, 'reason' => null];
        }

        $get = function (string $k) use ($subdomain): string {
            try {
                return (string) $this->redis->get('ai:' . $subdomain . '-' . $k);
            } catch (\Throwable $e) {
                return '';
            }
        };
        $enabled = $get('transcribeTokenEnabled');
        if ($enabled === '' || $enabled === '2' || $enabled === '0') {
            return ['status' => 'unavailable', 'text' => null,
                'reason' => 'AI transcription is turned off in RingQ. Turn it on in Global Settings → AI transcription.'];
        }

        if (self::$hold || (PHP_SAPI !== 'cli' && !self::$allowInline)) {
            // Inside a live request: leave it to the post-hangup step or the cron task.
            return ['status' => 'pending', 'text' => null, 'reason' => null];
        }

        $file = rtrim((string) ($cdr['record_path'] ?? ''), '/') . '/' . basename((string) ($cdr['record_name'] ?? ''));
        if (empty($cdr['record_name']) || !is_file($file) || !is_readable($file)) {
            return ['status' => 'pending', 'text' => null, 'reason' => 'The call recording file is not available yet.'];
        }

        $type = $get('aitranscripttype') ?: 'openai';
        if ($type === 'ringq') {
            $ch = curl_init('https://aicloud.ringq.io/ai/transcribe');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 170,
                CURLOPT_POSTFIELDS => [
                    'file' => new \CURLFile($file, (string) (mime_content_type($file) ?: 'audio/wav'), basename($file)),
                    'engine' => $get('ringq_model') ?: 'tiny',
                ],
                // Same header RingQ's audiototext sends; override with RINGQ_AICLOUD_KEY if set.
                CURLOPT_HTTPHEADER => ['X-API-Key: ' . ((string) getenv('RINGQ_AICLOUD_KEY') ?: 'CHANGE_ME')],
            ]);
        } else {
            $token = $get('transcribeToken');
            if ($token === '') {
                return ['status' => 'unavailable', 'text' => null,
                    'reason' => 'No OpenAI transcription token is set in RingQ AI transcription settings.'];
            }
            $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 170,
                CURLOPT_POSTFIELDS => ['file' => new \CURLFile($file, 'audio/wav'), 'model' => 'whisper-1'],
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            ]);
        }
        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        error_log(sprintf('[qontak] transcription via %s%s took %.1fs, HTTP %d, file %s (%d KB)', $type,
            $type === 'ringq' ? ' (model ' . ($get('ringq_model') ?: 'tiny') . ')' : '', microtime(true) - $t0, $http,
            basename($file), (int) (filesize($file) / 1024)));
        curl_close($ch);
        if ($raw === false || $http < 200 || $http >= 300) {
            error_log('[qontak] transcription (' . $type . ') failed HTTP ' . $http . ($err !== '' ? ' ' . $err : ''));
            $why = $http === 401 || $http === 403 ? 'The AI transcription service rejected the token (HTTP ' . $http . ').'
                : 'The AI transcription service did not respond (' . ($http ?: 'no connection') . ').';
            return ['status' => in_array($http, [401, 403], true) ? 'unavailable' : 'pending', 'text' => null, 'reason' => $why];
        }
        $data = json_decode((string) $raw, true);
        $text = '';
        if (is_array($data)) {
            foreach (['text', 'transcription', 'result'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && trim($data[$k]) !== '') {
                    $text = trim($data[$k]);
                    break;
                }
            }
        }
        if ($text === '' || $text === self::NO_TEXT) {
            return ['status' => 'unavailable', 'text' => null, 'reason' => 'The transcription service returned no text (silent or very short call).'];
        }
        return ['status' => 'done', 'text' => $text, 'reason' => null];
    }

    /** The CDR json RingQ's Call Logs read, in RingQ's own format. */
    public static function cdrJson(string $text): string
    {
        return (string) json_encode(['summary' => self::PLACEHOLDER_SUMMARY, 'sentiment' => 'neutral',
            'transcribedText' => htmlspecialchars($text, ENT_QUOTES)]);
    }

    /** Transcript RingQ's /reports/audiototext stored on the CDR (q_ringq_cdr.json), or null. */
    public static function storedText(string $json): ?string
    {
        return self::parseStored($json);
    }

    private static function parseStored(string $json): ?string
    {
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $text = (string) ($data['transcribedText'] ?? $data['transcription'] ?? $data['text'] ?? '');
        $text = html_entity_decode($text, ENT_QUOTES);
        return ($text !== '' && $text !== self::NO_TEXT) ? $text : null;
    }
}

/** Default: no summary/sentiment provider configured. */
class NullSummaryProvider implements QontakSummaryProvider
{
    public function analyze(string $transcript): ?array
    {
        return null;
    }
}
