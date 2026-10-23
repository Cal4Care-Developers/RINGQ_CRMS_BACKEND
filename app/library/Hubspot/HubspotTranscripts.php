<?php
declare(strict_types=1);

namespace Library\Hubspot;

/**
 * Optional AI call intelligence for HubSpot call journals.
 *
 * HubSpot's CRM Calls API stores call notes (hs_call_body) and a recording URL,
 * but it does not transcribe audio for third-party callers (HubSpot's own
 * transcription only runs for HubSpot-hosted/Calling-SDK recordings in
 * eligible Sales/Service Hub seats). So transcription comes from RingQ's
 * existing per-tenant service and the text is written into the call's notes.
 *
 * Summary and sentiment need a real analysis provider. None is configured in
 * RingQ today: its pipeline stores the placeholders "No Summary Available" /
 * "neutral", which are NOT analysis, so they are ignored. Implement
 * HubspotSummaryProvider and return it from HubspotService::summaryProvider()
 * to enable them; until then those settings stay inactive and say so.
 */
interface HubspotSummaryProvider
{
    /** @return array{summary:?string, sentiment:?string}|null null when not available */
    public function analyze(string $transcript): ?array;
}

class NullHubspotSummaryProvider implements HubspotSummaryProvider
{
    public function analyze(string $transcript): ?array
    {
        return null;
    }
}

class HubspotTranscripts
{
    const NO_TEXT = 'No transcription available.';
    const PLACEHOLDER_SUMMARY = 'No Summary Available';

    private $redis;

    public function __construct($redis)
    {
        $this->redis = $redis;
    }

    /** Transcript RingQ's /reports/audiototext stored on the CDR (q_ringq_cdr.json), or null. */
    public static function storedText(string $json): ?string
    {
        if (trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $text = html_entity_decode((string) ($data['transcribedText'] ?? $data['transcription'] ?? $data['text'] ?? ''), ENT_QUOTES);
        $text = trim($text);
        return ($text !== '' && $text !== self::NO_TEXT) ? $text : null;
    }

    /** The CDR json RingQ's Call Logs read, in RingQ's own format. */
    public static function cdrJson(string $text): string
    {
        return (string) json_encode(['summary' => self::PLACEHOLDER_SUMMARY, 'sentiment' => 'neutral',
            'transcribedText' => htmlspecialchars($text, ENT_QUOTES)]);
    }

    private function setting(string $subdomain, string $key): string
    {
        try {
            return (string) $this->redis->get('ai:' . $subdomain . '-' . $key);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** True when the tenant has RingQ AI transcription switched on (Global Settings). */
    public function ringqTranscriptionEnabled(string $subdomain): bool
    {
        $v = $this->setting($subdomain, 'transcribeTokenEnabled');
        return !($v === '' || $v === '0' || $v === '2');
    }

    /**
     * Transcribes the CDR's recording with the tenant's configured RingQ
     * transcription service. Only called from the CLI task, never inside a web
     * request, so it can never delay the dialer.
     *
     * @return array{status:string, text:?string, reason:?string} status: done | pending | unavailable
     */
    public function transcribe(array $cdr, string $subdomain): array
    {
        $stored = self::storedText((string) ($cdr['json'] ?? ''));
        if ($stored !== null) {
            return ['status' => 'done', 'text' => $stored, 'reason' => null];
        }
        if (!$this->ringqTranscriptionEnabled($subdomain)) {
            return ['status' => 'unavailable', 'text' => null,
                'reason' => 'AI transcription is turned off in RingQ (Global Settings → AI transcription).'];
        }
        if (PHP_SAPI !== 'cli') {
            return ['status' => 'pending', 'text' => null, 'reason' => null];
        }
        $file = rtrim((string) ($cdr['record_path'] ?? ''), '/') . '/' . basename((string) ($cdr['record_name'] ?? ''));
        if (empty($cdr['record_name']) || !is_file($file) || !is_readable($file)) {
            return ['status' => 'pending', 'text' => null, 'reason' => 'The call recording file is not available yet.'];
        }

        $type = $this->setting($subdomain, 'aitranscripttype') ?: 'openai';
        if ($type === 'ringq') {
            $key = (string) getenv('RINGQ_AICLOUD_KEY');
            if ($key === '') {
                return ['status' => 'unavailable', 'text' => null,
                    'reason' => 'RingQ AI cloud key is not configured for background transcription (RINGQ_AICLOUD_KEY).'];
            }
            $ch = curl_init('https://aicloud.ringq.io/ai/transcribe');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_POSTFIELDS => [
                    'file' => new \CURLFile($file, (string) (mime_content_type($file) ?: 'audio/wav'), basename($file)),
                    'engine' => $this->setting($subdomain, 'ringq_model') ?: 'tiny',
                ],
                CURLOPT_HTTPHEADER => ['X-API-Key: ' . $key],
            ]);
        } else {
            $token = $this->setting($subdomain, 'transcribeToken');
            if ($token === '') {
                return ['status' => 'unavailable', 'text' => null,
                    'reason' => 'No OpenAI transcription token is set in RingQ AI transcription settings.'];
            }
            $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_POSTFIELDS => ['file' => new \CURLFile($file, 'audio/wav'), 'model' => 'whisper-1'],
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            ]);
        }
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $http < 200 || $http >= 300) {
            error_log('[hubspot] transcription (' . $type . ') failed HTTP ' . $http);
            $denied = in_array($http, [401, 403], true);
            return [
                'status' => $denied ? 'unavailable' : 'pending',
                'text' => null,
                'reason' => $denied ? 'The AI transcription service rejected its token (HTTP ' . $http . ').'
                    : 'The AI transcription service did not respond (' . ($http ?: 'no connection') . ').',
            ];
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
}
