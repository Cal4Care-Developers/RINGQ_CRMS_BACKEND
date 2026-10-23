<?php

/**
 * Any tunnel with status = 1 whose last_seen is older than $timeout seconds
 * is switched to status = 0. Returns the number of rows changed.
 * Uses the DB clock (NOW()), the same clock heartbeatAction writes last_seen with.
 */
function handleTunnelHeartbeatCheck(int $timeout = 30): int
{
    $db = getDb();
    $timeout = max(1, $timeout);

    if ($db->getType() === 'pgsql') {
        $stale = "last_seen < NOW() - INTERVAL '{$timeout} seconds'";
    } else {
        $stale = "last_seen < DATE_SUB(NOW(), INTERVAL {$timeout} SECOND)";
    }

    // 1. Find every row that is online but has not sent a heartbeat in time
    $rows = $db->fetchAll(
        "SELECT id, tunnel_name, last_seen
         FROM tunnel_config
         WHERE status = 1
           AND deleted_at IS NULL
           AND (last_seen IS NULL OR {$stale})",
        \Phalcon\Db\Enum::FETCH_ASSOC
    );

    $count = 0;

    // 2. Update each one individually
    foreach ($rows as $row) {
        $db->execute(
            "UPDATE tunnel_config
             SET status            = 0,
                 offline_reason    = 'heartbeat_timeout',
                 phones_registered = 0,
                 rtp_sessions      = 0,
                 uptime_seconds    = 0,
                 updated_at        = NOW()
             WHERE id = ?
               AND status = 1
               AND (last_seen IS NULL OR {$stale})",
            [$row['id']]
        );

        if ($db->affectedRows() > 0) {
            $count++;
            echo '[Worker] ' . date('Y-m-d H:i:s')
                . " tunnel OFFLINE: {$row['tunnel_name']} (id {$row['id']}), last_seen {$row['last_seen']}\n";
        }
    }

    return $count;
}