<?php

// Queue names: 2 to 15 characters (letters, digits, space and _ . - ( ) only, so
// the name stays safe inside the queue's dialplan XML), unique within the tenant
// (case-insensitive, surrounding spaces ignored).
class QueueName
{
    const MIN = 2;
    const MAX = 15;

    // Error message for a bad name, or null when the format is fine.
    public static function formatError($name)
    {
        $name = trim((string)$name);
        $len = mb_strlen($name, 'UTF-8');
        if ($len < self::MIN || $len > self::MAX) {
            return 'Queue name must be ' . self::MIN . ' to ' . self::MAX . ' characters.';
        }
        if (!preg_match('/^[\p{L}\p{N} _.\-()]+$/u', $name)) {
            return 'Queue name can only use letters, digits, spaces and _ . - ( ).';
        }
        return null;
    }

    // The queue's stored name, or null when there is no such queue. A save that keeps the
    // stored name is not checked, so queues named before these rules can still be edited.
    public static function storedName($db, $domainUuid, $extension)
    {
        if ($extension === null || (string)$extension === '' || (string)$extension === '0') {
            return null;
        }
        $row = $db->fetchOne(
            'SELECT queue_name FROM v_call_center_queues WHERE domain_uuid = :d AND queue_extension::text = :e LIMIT 1',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => (string)$domainUuid, 'e' => (string)$extension]
        );
        return $row ? (string)$row['queue_name'] : null;
    }

    // True when another queue in the tenant already has this name. $exceptExtension is the
    // queue being edited (0 or null on create).
    public static function isTaken($db, $domainUuid, $name, $exceptExtension = null)
    {
        $row = $db->fetchOne(
            'SELECT 1 FROM v_call_center_queues WHERE domain_uuid = :d'
            . ' AND lower(trim(queue_name)) = lower(:n) AND queue_extension::text <> :e LIMIT 1',
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['d' => (string)$domainUuid, 'n' => trim((string)$name), 'e' => (string)($exceptExtension ?? '')]
        );
        return !empty($row);
    }
}
