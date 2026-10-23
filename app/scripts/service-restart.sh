#!/bin/bash
# Restart a RingQ service as root and wait until it answers again. Run by api/servicerestart through
# sudo systemd-run; the caller checks live calls and reports state before and after.
#
#   service-restart.sh freeswitch   restart FreeSWITCH, wait for its console and a running SIP profile
#   service-restart.sh node         restart the pm2 apps, and start any app ecosystem.config.js declares
#                                   that pm2 no longer has
#
# Prints one JSON line. Always exits 0.

SYSTEMCTL=${RINGQ_SYSTEMCTL:-systemctl}
FS_CLI=${RINGQ_FS_CLI:-/usr/bin/fs_cli}
ECOSYSTEM=${RINGQ_ECOSYSTEM:-/var/www/ringq/ecosystem.config.js}
WAIT_FS=${RINGQ_WAIT_FS:-90}
WAIT_SOFIA=${RINGQ_WAIT_SOFIA:-30}
LOG=${RINGQ_RESTART_LOG:-/var/log/ringq-service-restart.log}

log() {
    echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true
}

find_pm2() {
    local c
    if [ -n "${RINGQ_PM2:-}" ]; then
        echo "$RINGQ_PM2"
        return
    fi
    for c in /usr/bin/pm2 /bin/pm2 /usr/local/bin/pm2 /usr/lib/node_modules/pm2/bin/pm2 /root/.nvm/versions/node/*/bin/pm2; do
        [ -x "$c" ] && { echo "$c"; return; }
    done
}

declared_apps() {
    [ -r "$ECOSYSTEM" ] || return
    grep -v '^[[:space:]]*//' "$ECOSYSTEM" | grep -o "name[[:space:]]*:[[:space:]]*[\"'][A-Za-z0-9._-]*[\"']" | sed "s/.*[\"']\([A-Za-z0-9._-]*\)[\"']$/\1/"
}

restart_freeswitch() {
    local waited=0 running=0
    log "restarting freeswitch"
    if ! "$SYSTEMCTL" restart freeswitch >/dev/null 2>&1; then
        log "systemctl restart freeswitch failed"
        echo '{"ok":false,"error":"systemctl could not restart FreeSWITCH"}'
        return
    fi
    while [ "$waited" -lt "$WAIT_FS" ]; do
        "$FS_CLI" -x status >/dev/null 2>&1 && break
        sleep 1
        waited=$((waited + 1))
    done
    if [ "$waited" -ge "$WAIT_FS" ]; then
        log "freeswitch console did not answer after ${WAIT_FS}s"
        echo "{\"ok\":false,\"error\":\"FreeSWITCH did not answer within ${WAIT_FS} seconds\",\"waited\":$waited}"
        return
    fi
    local s=0
    while [ "$s" -lt "$WAIT_SOFIA" ]; do
        running=$("$FS_CLI" -x 'sofia status' 2>/dev/null | grep -cE '[[:space:]]profile[[:space:]].*RUNNING')
        [ "$running" -gt 0 ] && break
        sleep 1
        s=$((s + 1))
    done
    waited=$((waited + s))
    log "freeswitch back after ${waited}s, $running profile(s) running"
    if [ "$running" -gt 0 ]; then
        echo "{\"ok\":true,\"waited\":$waited,\"profilesRunning\":$running}"
    else
        echo "{\"ok\":false,\"error\":\"FreeSWITCH is up but no SIP profile is running\",\"waited\":$waited,\"profilesRunning\":0}"
    fi
}

restart_node() {
    local pm2 name started=""
    pm2=$(find_pm2)
    if [ -z "$pm2" ]; then
        echo '{"ok":false,"error":"pm2 was not found on this server"}'
        return
    fi
    export HOME=${HOME:-/root}
    log "restarting pm2 apps with $pm2"
    "$pm2" restart all >/dev/null 2>&1 || "$pm2" resurrect >/dev/null 2>&1
    for name in $(declared_apps); do
        if ! "$pm2" describe "$name" >/dev/null 2>&1; then
            if "$pm2" start "$ECOSYSTEM" --only "$name" >/dev/null 2>&1; then
                started="$started${started:+,}\"$name\""
                log "started missing app $name"
            else
                log "could not start missing app $name"
            fi
        fi
    done
    "$pm2" save >/dev/null 2>&1 || true
    echo "{\"ok\":true,\"started\":[$started]}"
}

case "$1" in
    freeswitch) restart_freeswitch ;;
    node) restart_node ;;
    *) echo '{"ok":false,"error":"unknown service"}' ;;
esac
exit 0
