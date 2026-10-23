<?php
declare(strict_types=1);

class ScheduleValidationException extends \RuntimeException
{
    /** @var string */
    private $apiCode;
    /** @var int */
    private $httpStatus;
    /** @var array<string, mixed> */
    private $details;

    public function __construct(string $apiCode, string $message, int $httpStatus = 400, array $details = [])
    {
        parent::__construct($message);
        $this->apiCode = $apiCode;
        $this->httpStatus = $httpStatus;
        $this->details = $details;
    }

    public function apiCode(): string
    {
        return $this->apiCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}

/**
 * Validation and storage-boundary translation for the external schedules API.
 *
 * This intentionally does not change ScheduleController. The CloudApp's
 * session-backed schedule form retains its existing behaviour; external API
 * writes are the only ones that enforce these rules.
 */
class ScheduleValidator
{
    private const TITLE_MAX = 200;
    private const DESCRIPTION_MAX = 5000;
    private const MAX_DURATION_SECONDS = 86400;
    private const MAX_RECIPIENTS = 100;

    public const REPEAT_OPTIONS = ['Does Not Repeat', 'Repeat'];
    public const BYPASS_LOBBY_OPTIONS = [
        'Everyone',
        'People in my team, trusted teams, and guest',
        'People in my team and guest',
        'People in my team',
        'People who were invited',
        'Only organizers and co-organizers',
    ];
    public const DEFAULT_REPEAT = 'Does Not Repeat';
    public const DEFAULT_BYPASS_LOBBY = 'Only organizers and co-organizers';

    public const HOST_PERMISSION_DEFAULTS = [
        'cameraLocked' => false,
        'microphoneLocked' => false,
        'cameraRequestsAllowed' => true,
        'microphoneRequestsAllowed' => true,
        'cohostsMayManageMedia' => true,
        'chatLocked' => false,
        'autoAdmit' => false,
        'whiteboardLocked' => false,
        'hideParticipantNames' => 'off',
        'joinRequestSoundRepeats' => 1,
    ];
    private const NAME_VISIBILITY = ['off', 'participants', 'everyone'];

    /**
     * Validate an external create/update representation.
     *
     * @return array<string, mixed>
     */
    public static function validate(array $data, ?int $now = null): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '' || strlen($title) > self::TITLE_MAX) {
            throw new ScheduleValidationException(
                'invalid_request',
                'title is required and must be at most ' . self::TITLE_MAX . ' characters.',
                400,
                ['field' => 'title']
            );
        }

        $description = (string)($data['description'] ?? '');
        if (strlen($description) > self::DESCRIPTION_MAX) {
            throw new ScheduleValidationException(
                'invalid_request',
                'description must be at most ' . self::DESCRIPTION_MAX . ' characters.',
                400,
                ['field' => 'description']
            );
        }

        $timezoneName = trim((string)($data['timezone'] ?? ''));
        if ($timezoneName === '') {
            throw new ScheduleValidationException('invalid_timezone', 'timezone is required.', 400, ['field' => 'timezone']);
        }
        try {
            $timezone = new \DateTimeZone($timezoneName);
        } catch (\Exception $e) {
            throw new ScheduleValidationException(
                'invalid_timezone',
                'timezone must be a valid IANA timezone.',
                400,
                ['field' => 'timezone', 'value' => $timezoneName]
            );
        }

        $start = self::parseIso($data['start'] ?? null, 'start');
        $durationText = self::durationText($data['duration'] ?? null);
        $hasEnd = isset($data['end']) && $data['end'] !== '';
        if (!$hasEnd && $durationText === null) {
            throw new ScheduleValidationException(
                'invalid_time_range',
                'end or duration is required.',
                400,
                ['fields' => ['end', 'duration']]
            );
        }
        $end = $hasEnd
            ? self::parseIso($data['end'], 'end')
            : $start->modify('+' . self::durationSeconds($durationText, 0) . ' seconds');
        if ($hasEnd && $durationText !== null
            && self::durationSeconds($durationText, 0) !== $end->getTimestamp() - $start->getTimestamp()
        ) {
            throw new ScheduleValidationException(
                'invalid_time_range',
                'duration does not match the time between start and end.',
                400,
                ['fields' => ['duration', 'end']]
            );
        }
        if ($end->getTimestamp() <= $start->getTimestamp()
            || ($end->getTimestamp() - $start->getTimestamp()) > self::MAX_DURATION_SECONDS
        ) {
            throw new ScheduleValidationException(
                'invalid_time_range',
                'end must be after start and the duration must not exceed 24 hours.',
                400,
                ['fields' => ['start', 'end']]
            );
        }

        $now = $now ?? time();
        if ($start->getTimestamp() < $now) {
            throw new ScheduleValidationException('start_in_past', 'start must not be in the past.', 400, ['field' => 'start']);
        }

        $emails = self::emailList($data['emailAddresses'] ?? []);
        $internalExtensions = self::internalExtensionList($data['internalUsers'] ?? []);

        $allDay = $data['allDay'] ?? false;
        if (!is_bool($allDay)) {
            throw new ScheduleValidationException('invalid_request', 'allDay must be a boolean.', 400, ['field' => 'allDay']);
        }
        $repeat = self::option($data['repeat'] ?? null, self::REPEAT_OPTIONS, self::DEFAULT_REPEAT, 'repeat');
        $bypassLobby = self::option(
            $data['bypassLobby'] ?? null,
            self::BYPASS_LOBBY_OPTIONS,
            self::DEFAULT_BYPASS_LOBBY,
            'bypassLobby'
        );
        $hostPermissions = self::hostPermissions($data['hostPermissions'] ?? null);

        return [
            'title' => $title,
            'description' => $description,
            'timezone' => $timezone,
            'timezoneName' => $timezoneName,
            // ISO strings identify an instant. The named timezone controls the
            // wall-clock representation stored and returned by RingQ.
            'start' => $start->setTimezone($timezone),
            'end' => $end->setTimezone($timezone),
            'emailAddresses' => $emails,
            'internalExtensions' => $internalExtensions,
            'allDay' => $allDay,
            'repeat' => $repeat,
            'bypassLobby' => $bypassLobby,
            'duration' => self::durationLabel($end->getTimestamp() - $start->getTimestamp()),
            'hostPermissions' => $hostPermissions,
        ];
    }

    private static function option($value, array $options, string $default, string $field): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || !in_array($value, $options, true)) {
            throw new ScheduleValidationException(
                'invalid_request',
                $field . ' must be one of: ' . implode(', ', $options) . '.',
                400,
                ['field' => $field, 'allowed' => $options]
            );
        }
        return $value;
    }

    private static function durationText($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = is_string($value) ? trim($value) : '';
        if (!preg_match('/^(?:(\d+)\s*hr)?\s*(?:(\d+)\s*min)?$/', $text, $matches)
            || self::durationSeconds($text, 0) <= 0
        ) {
            throw new ScheduleValidationException(
                'invalid_request',
                'duration must look like "30 min", "1 hr" or "1 hr 30 min".',
                400,
                ['field' => 'duration']
            );
        }
        return $text;
    }

    public static function durationLabel(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);
        $hours = intdiv($minutes, 60);
        $minutes %= 60;
        if ($hours === 0) {
            return $minutes . ' min';
        }
        return $minutes === 0 ? $hours . ' hr' : $hours . ' hr ' . $minutes . ' min';
    }

    /** @return array<string, mixed>|null */
    private static function hostPermissions($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && array_keys($value) === range(0, count($value) - 1))) {
            throw new ScheduleValidationException(
                'invalid_request',
                'hostPermissions must be an object.',
                400,
                ['field' => 'hostPermissions']
            );
        }
        $result = self::HOST_PERMISSION_DEFAULTS;
        foreach ($value as $key => $setting) {
            if (!array_key_exists($key, self::HOST_PERMISSION_DEFAULTS)) {
                throw new ScheduleValidationException(
                    'invalid_request',
                    'hostPermissions.' . $key . ' is not a host permission.',
                    400,
                    ['field' => 'hostPermissions.' . $key]
                );
            }
            $valid = $key === 'hideParticipantNames'
                ? is_string($setting) && in_array($setting, self::NAME_VISIBILITY, true)
                : ($key === 'joinRequestSoundRepeats'
                    ? is_int($setting) && $setting >= 0 && $setting <= 3
                    : is_bool($setting));
            if (!$valid) {
                throw new ScheduleValidationException(
                    'invalid_request',
                    'hostPermissions.' . $key . ' has an invalid value.',
                    400,
                    ['field' => 'hostPermissions.' . $key]
                );
            }
            $result[$key] = $setting;
        }
        return $result === self::HOST_PERMISSION_DEFAULTS ? null : $result;
    }

    public static function parseIso($value, string $field): \DateTimeImmutable
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || !preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/',
            $text
        )) {
            throw new ScheduleValidationException(
                'invalid_time_range',
                $field . ' must be an ISO-8601 timestamp with an offset.',
                400,
                ['field' => $field]
            );
        }
        try {
            return new \DateTimeImmutable($text);
        } catch (\Exception $e) {
            throw new ScheduleValidationException(
                'invalid_time_range',
                $field . ' must be a valid ISO-8601 timestamp.',
                400,
                ['field' => $field]
            );
        }
    }

    /** @return string[] */
    private static function emailList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > self::MAX_RECIPIENTS) {
            throw new ScheduleValidationException(
                'invalid_request',
                'emailAddresses must be an array with at most ' . self::MAX_RECIPIENTS . ' entries.',
                400,
                ['field' => 'emailAddresses']
            );
        }
        $result = [];
        foreach ($value as $address) {
            $address = is_string($address) ? trim($address) : '';
            if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new ScheduleValidationException(
                    'invalid_recipient',
                    'Every emailAddresses entry must be a valid email address.',
                    400,
                    ['address' => $address]
                );
            }
            $result[strtolower($address)] = $address;
        }
        return array_values($result);
    }

    /** @return string[] */
    private static function internalExtensionList($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > self::MAX_RECIPIENTS) {
            throw new ScheduleValidationException(
                'invalid_request',
                'internalUsers must be an array with at most ' . self::MAX_RECIPIENTS . ' entries.',
                400,
                ['field' => 'internalUsers']
            );
        }
        $result = [];
        foreach ($value as $entry) {
            $extension = is_array($entry) ? ($entry['extension'] ?? '') : $entry;
            $extension = is_scalar($extension) ? trim((string)$extension) : '';
            if ($extension === '' || strlen($extension) > 32) {
                throw new ScheduleValidationException(
                    'unknown_internal_user',
                    'Every internalUsers entry must name an extension in this tenant.',
                    400,
                    ['extension' => $extension]
                );
            }
            $result[$extension] = $extension;
        }
        return array_values($result);
    }

    /**
     * Convert validated API time fields into the exact schedule-list shape.
     *
     * @return array<string, string>
     */
    public static function storedTimeFields(array $validated): array
    {
        /** @var \DateTimeImmutable $start */
        $start = $validated['start'];
        /** @var \DateTimeImmutable $end */
        $end = $validated['end'];
        $midnight = $start->setTime(0, 0, 0);

        return [
            'date' => $midnight->format('Y-m-d'),
            'startTime' => (string)($start->getTimestamp() - $midnight->getTimestamp()),
            'endTime' => (string)($end->getTimestamp() - $midnight->getTimestamp()),
            'timezone' => (string)$validated['timezoneName'],
        ];
    }

    /**
     * The form fields the CloudApp stores beside the time, in its own shape.
     *
     * @return array<string, mixed>
     */
    public static function storedMeetingFields(array $validated): array
    {
        $fields = [
            'allDay' => (bool)$validated['allDay'],
            'repeat' => (string)$validated['repeat'],
            'bypassLobby' => (string)$validated['bypassLobby'],
            'duration' => (string)$validated['duration'],
        ];
        if ($validated['hostPermissions'] !== null) {
            $fields['hostPermissions'] = $validated['hostPermissions'];
        }
        return $fields;
    }

    /**
     * Read a schedule row on the same terms as meet_timezoneOf and
     * meet_zonedTimestamp in the email worker.
     *
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null
     */
    public static function scheduleInterval(array $row): ?array
    {
        $date = trim((string)($row['date'] ?? ''));
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return null;
        }
        $startSeconds = filter_var($row['startTime'] ?? null, FILTER_VALIDATE_INT);
        $endSeconds = filter_var($row['endTime'] ?? null, FILTER_VALIDATE_INT);
        if ($startSeconds === false || $endSeconds === false || $endSeconds <= $startSeconds) {
            return null;
        }

        $timezoneName = trim((string)($row['timezone'] ?? ''));
        $timezone = null;
        if ($timezoneName !== '') {
            try {
                $timezone = new \DateTimeZone($timezoneName);
            } catch (\Exception $e) {
                $timezone = null;
            }
        }

        if ($timezone === null) {
            // This is deliberately the worker's legacy sequence. A bare
            // strtotime($date) + seconds moves ISO rows containing a T.
            $base = strtotime($date);
            if ($base === false) {
                return null;
            }
            $dateOnly = date('Y-m-d', $base);
            $midnight = strtotime($dateOnly);
            if ($midnight === false) {
                return null;
            }
            $timezone = new \DateTimeZone(date_default_timezone_get());
            $start = (new \DateTimeImmutable('@' . ($midnight + $startSeconds)))->setTimezone($timezone);
            $end = (new \DateTimeImmutable('@' . ($midnight + $endSeconds)))->setTimezone($timezone);
            return ['start' => $start, 'end' => $end];
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $matches)) {
            $dateOnly = $matches[1];
        } else {
            $base = strtotime($date);
            if ($base === false) {
                return null;
            }
            $dateOnly = date('Y-m-d', $base);
        }
        try {
            $midnight = new \DateTimeImmutable($dateOnly . ' 00:00:00', $timezone);
        } catch (\Exception $e) {
            return null;
        }
        return [
            'start' => $midnight->modify('+' . $startSeconds . ' seconds'),
            'end' => $midnight->modify('+' . $endSeconds . ' seconds'),
        ];
    }

    /** @return array<string, mixed> */
    public static function storedToApi(array $row, ?string $hostExtension = null, ?string $meetingLink = null): array
    {
        $interval = self::scheduleInterval($row);
        $emails = [];
        foreach (explode(',', (string)($row['emailAddresses'] ?? '')) as $address) {
            $address = trim($address);
            if ($address !== '') {
                $emails[] = $address;
            }
        }

        $result = [
            'id' => (string)($row['id'] ?? ''),
            'title' => (string)($row['meetingTitle'] ?? ''),
            'description' => (string)($row['meetingDescription'] ?? ''),
            'start' => $interval ? $interval['start']->format(\DateTimeInterface::ATOM) : null,
            'end' => $interval ? $interval['end']->format(\DateTimeInterface::ATOM) : null,
            'timezone' => isset($row['timezone']) && trim((string)$row['timezone']) !== ''
                ? (string)$row['timezone']
                : null,
            'emailAddresses' => $emails,
            'internalUsers' => is_array($row['internalUsers'] ?? null) ? $row['internalUsers'] : [],
            'allDay' => ($row['allDay'] ?? false) === true || ($row['allDay'] ?? '') === 'true',
            'repeat' => (string)($row['repeat'] ?? self::DEFAULT_REPEAT),
            'bypassLobby' => (string)($row['bypassLobby'] ?? self::DEFAULT_BYPASS_LOBBY),
            'duration' => $interval
                ? self::durationLabel($interval['end']->getTimestamp() - $interval['start']->getTimestamp())
                : (string)($row['duration'] ?? ''),
            'hostPermissions' => is_array($row['hostPermissions'] ?? null) ? $row['hostPermissions'] : null,
            'hostName' => (string)($row['hostName'] ?? ''),
            'hostEmail' => (string)($row['hostEmail'] ?? ''),
            'ringqmeetId' => (string)($row['ringqmeetId'] ?? ''),
            'ringqmeetLink' => (string)($row['ringqmeetLink'] ?? ''),
        ];
        if ($meetingLink !== null && $meetingLink !== '') {
            $result['ringqmeetLink'] = $meetingLink;
            foreach ($result['internalUsers'] as &$user) {
                if (is_array($user)) {
                    $user['meetLink'] = $meetingLink;
                }
            }
            unset($user);
        }
        if ($hostExtension !== null) {
            $result['hostExtension'] = $hostExtension;
        }
        return $result;
    }

    /** @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null */
    public static function bookingInterval(array $appointment, array $booking): ?array
    {
        $duration = self::durationSeconds($booking['duration'] ?? null, 1800);
        return self::wallClockInterval(
            $appointment['date'] ?? '',
            $appointment['time'] ?? '',
            $booking['timezone'] ?? null,
            $duration
        );
    }

    /** @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null */
    public static function webinarInterval(array $webinar): ?array
    {
        $duration = self::durationSeconds($webinar['duration'] ?? null, 3600);
        return self::wallClockInterval(
            $webinar['date'] ?? '',
            $webinar['time'] ?? '',
            $webinar['timezone'] ?? null,
            $duration
        );
    }

    /** @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null */
    private static function wallClockInterval($date, $time, $timezoneName, int $duration): ?array
    {
        $date = trim((string)$date);
        $time = trim((string)$time);
        if ($date === '' || strpos($date, '0000-00-00') === 0 || $time === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $matches)) {
            $date = $matches[1];
        }
        $timezone = null;
        if (is_string($timezoneName) && trim($timezoneName) !== '') {
            try {
                $timezone = new \DateTimeZone(trim($timezoneName));
            } catch (\Exception $e) {
                $timezone = null;
            }
        }
        try {
            $start = $timezone === null
                ? new \DateTimeImmutable($date . ' ' . $time)
                : new \DateTimeImmutable($date . ' ' . $time, $timezone);
        } catch (\Exception $e) {
            return null;
        }
        return ['start' => $start, 'end' => $start->modify('+' . $duration . ' seconds')];
    }

    private static function durationSeconds($value, int $fallback): int
    {
        $text = strtolower(trim((string)$value));
        $seconds = 0;
        if (preg_match('/(\d+)\s*hr/', $text, $matches)) {
            $seconds += (int)$matches[1] * 3600;
        }
        if (preg_match('/(\d+)\s*min/', $text, $matches)) {
            $seconds += (int)$matches[1] * 60;
        }
        if ($seconds === 0 && ctype_digit($text)) {
            $seconds = (int)$text * 60;
        }
        return $seconds > 0 ? $seconds : $fallback;
    }

    public static function overlaps(
        \DateTimeInterface $leftStart,
        \DateTimeInterface $leftEnd,
        \DateTimeInterface $rightStart,
        \DateTimeInterface $rightEnd
    ): bool {
        return $leftStart->getTimestamp() < $rightEnd->getTimestamp()
            && $leftEnd->getTimestamp() > $rightStart->getTimestamp();
    }
}
