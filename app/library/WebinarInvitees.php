<?php

class WebinarInvitees
{
    const MAX_PER_REQUEST = 100;
    const ROLE_PARTICIPANT = 'participant';
    const ROLE_COHOST = 'cohost';
    const INVITE_PREFIX = 'meet:invite:';
    const TOKEN_HASH_PREFIX = 'webinar_invite_tokens:';
    const MIN_INVITE_TTL = 86400;
    const INVITE_GRACE = 2592000;
    const UNSCHEDULED_INVITE_TTL = 7776000;

    public static function normalise($raw, $hostEmail, array $existingEmails = [])
    {
        if ($raw === null) {
            return ['rows' => [], 'error' => null, 'skipped' => 0];
        }
        if (!is_array($raw)) {
            return ['rows' => [], 'error' => 'Participants must be a list.', 'skipped' => 0];
        }
        if (count($raw) > self::MAX_PER_REQUEST) {
            return ['rows' => [], 'error' => 'At most ' . self::MAX_PER_REQUEST . ' participants can be invited at once.'];
        }

        $seen = [];
        foreach ($existingEmails as $existing) {
            $seen[strtolower(trim((string)$existing))] = 'existing';
        }
        $host = strtolower(trim((string)$hostEmail));

        $rows = [];
        $skipped = 0;
        $position = 0;
        foreach (array_values($raw) as $item) {
            $position++;
            $label = 'Participant ' . $position . ': ';
            if (!is_array($item)) {
                return ['rows' => [], 'error' => $label . 'is not readable.'];
            }
            $firstName = self::cleanName($item['firstName'] ?? '');
            $lastName = self::cleanName($item['lastName'] ?? '');
            $email = filter_var(trim((string)($item['email'] ?? '')), FILTER_SANITIZE_EMAIL);
            $role = trim((string)($item['role'] ?? self::ROLE_PARTICIPANT));

            if ($firstName === '' || $lastName === '') {
                return ['rows' => [], 'error' => $label . 'first and last name are required.'];
            }
            if (strlen($firstName) > 100 || strlen($lastName) > 100) {
                return ['rows' => [], 'error' => $label . 'names must be at most 100 characters.'];
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['rows' => [], 'error' => $label . 'a valid email address is required.'];
            }
            if ($role !== self::ROLE_PARTICIPANT && $role !== self::ROLE_COHOST) {
                return ['rows' => [], 'error' => $label . 'role must be participant or cohost.'];
            }

            $folded = strtolower($email);
            if ($host !== '' && $folded === $host) {
                return ['rows' => [], 'error' => $label . $email . ' is the host of this webinar.'];
            }
            if (isset($seen[$folded])) {
                if ($seen[$folded] === 'existing') {
                    $skipped++;
                    continue;
                }
                return ['rows' => [], 'error' => $label . $email . ' is listed more than once.'];
            }
            $seen[$folded] = 'new';

            $rows[] = [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'role' => $role,
            ];
        }

        return ['rows' => $rows, 'error' => null, 'skipped' => $skipped];
    }

    public static function existingEmails($redis, $webinarId)
    {
        $emails = [];
        foreach ((array)$redis->lrange('webinar_participants:' . $webinarId, 0, -1) as $item) {
            $row = json_decode($item, true);
            if (is_array($row) && !empty($row['email'])) {
                $emails[] = (string)$row['email'];
            }
        }
        return $emails;
    }

    public static function register($redis, $webinarId, array $webinar, $domainUuid, $linkHost, array $rows)
    {
        $ringqmeetId = (string)($webinar['ringqmeetId'] ?? '');
        $added = [];
        foreach ($rows as $row) {
            $registration = [
                'id' => uniqid(),
                'webinarId' => $webinarId,
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'email' => $row['email'],
                'notes' => '',
                'meetLink' => 'https://' . $linkHost . '/meet/m/' . rawurlencode($ringqmeetId),
                'guestTimezone' => '',
                'role' => $row['role'],
                'source' => 'host',
                'created_at' => date('Y-m-d H:i:s'),
            ];

            $redis->lpush('webinar_participants:' . $webinarId, json_encode($registration));

            if ($row['role'] === self::ROLE_COHOST) {
                self::issueInvite($redis, $webinarId, $webinar, $domainUuid, $registration);
            }

            $redis->lpush('email_queue', json_encode([
                'type' => 'webinar',
                'data' => $registration,
                'webinarId' => $webinarId,
            ]));

            $added[] = $registration;
        }
        return $added;
    }

    public static function issueInvite($redis, $webinarId, array $webinar, $domainUuid, array $registration)
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $record = [
            'domainUuid' => strtolower((string)$domainUuid),
            'externalReference' => (string)($webinar['ringqmeetId'] ?? ''),
            'webinarId' => (string)$webinarId,
            'participantId' => (string)$registration['id'],
            'email' => (string)$registration['email'],
            'displayName' => trim($registration['firstName'] . ' ' . $registration['lastName']),
            'role' => self::ROLE_COHOST,
            'issuedAt' => time(),
        ];
        $ttl = self::inviteTtl($webinar);
        $redis->setex(self::INVITE_PREFIX . hash('sha256', $token), $ttl, json_encode($record));
        $redis->hset(self::TOKEN_HASH_PREFIX . $webinarId, (string)$registration['id'], $token);
        $redis->expire(self::TOKEN_HASH_PREFIX . $webinarId, $ttl);
        return $token;
    }

    public static function refreshInvites($redis, $webinarId, array $webinar)
    {
        $tokens = (array)$redis->hgetall(self::TOKEN_HASH_PREFIX . $webinarId);
        if (!$tokens) {
            return 0;
        }
        $ttl = self::inviteTtl($webinar);
        $refreshed = 0;
        foreach ($tokens as $token) {
            $key = self::INVITE_PREFIX . hash('sha256', (string)$token);
            $raw = $redis->get($key);
            if (!$raw) {
                continue;
            }
            $record = json_decode($raw, true);
            if (!is_array($record)) {
                continue;
            }
            $record['externalReference'] = (string)($webinar['ringqmeetId'] ?? $record['externalReference'] ?? '');
            $redis->setex($key, $ttl, json_encode($record));
            $refreshed++;
        }
        $redis->expire(self::TOKEN_HASH_PREFIX . $webinarId, $ttl);
        return $refreshed;
    }

    public static function revokeParticipant($redis, $webinarId, $participantId)
    {
        $hashKey = self::TOKEN_HASH_PREFIX . $webinarId;
        $token = $redis->hget($hashKey, (string)$participantId);
        if ($token) {
            $redis->del(self::INVITE_PREFIX . hash('sha256', (string)$token));
        }
        $redis->hdel($hashKey, [(string)$participantId]);
    }

    public static function revokeWebinar($redis, $webinarId)
    {
        $hashKey = self::TOKEN_HASH_PREFIX . $webinarId;
        foreach ((array)$redis->hgetall($hashKey) as $token) {
            $redis->del(self::INVITE_PREFIX . hash('sha256', (string)$token));
        }
        $redis->del($hashKey);
    }

    public static function inviteTtl(array $webinar, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $start = self::startTimestamp($webinar);
        if ($start === null) {
            return self::UNSCHEDULED_INVITE_TTL;
        }
        $end = $start + self::durationSeconds((string)($webinar['duration'] ?? '1 hr'));
        return max(self::MIN_INVITE_TTL, $end + self::INVITE_GRACE - $now);
    }

    public static function startTimestamp(array $webinar)
    {
        $date = trim((string)($webinar['date'] ?? ''));
        $time = trim((string)($webinar['time'] ?? ''));
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m) || !preg_match('/^\d{1,2}:\d{1,2}/', $time)) {
            return null;
        }
        $zone = null;
        $zoneName = trim(explode(' (', (string)($webinar['timezone'] ?? ''))[0]);
        if ($zoneName !== '') {
            try {
                $zone = new \DateTimeZone($zoneName);
            } catch (\Exception $e) {
                $zone = null;
            }
        }
        try {
            $at = $zone ? new \DateTime($m[1] . ' ' . $time, $zone) : new \DateTime($m[1] . ' ' . $time);
        } catch (\Exception $e) {
            return null;
        }
        return $at->getTimestamp();
    }

    public static function durationSeconds($duration)
    {
        $minutes = 0;
        if (preg_match('/(\d+)\s*hr/', $duration, $m)) {
            $minutes += (int)$m[1] * 60;
        }
        if (preg_match('/(\d+)\s*min/', $duration, $m)) {
            $minutes += (int)$m[1];
        }
        if ($minutes === 0 && is_numeric($duration)) {
            $minutes = (int)$duration;
        }
        if ($minutes <= 0) {
            $minutes = 60;
        }
        return $minutes * 60;
    }

    private static function cleanName($value)
    {
        return htmlspecialchars(strip_tags(trim((string)$value)), ENT_QUOTES, 'UTF-8');
    }
}
