#!/bin/bash
#   cdr-pipeline.sh status   one JSON line: how the call record import is scheduled, its last run,
#                            its recent errors, and which database settings /etc/ringq/.env lacks
# Always exits 0.

SYSTEMCTL=${RINGQ_SYSTEMCTL:-systemctl}
JOURNALCTL=${RINGQ_JOURNALCTL:-journalctl}
CRONTAB=${RINGQ_CRONTAB:-crontab}
ENV_FILE=${RINGQ_ENV_FILE:-/etc/ringq/.env}
SYSTEMD_DIR=${RINGQ_SYSTEMD_DIR:-/run/systemd/system}
SINCE=${RINGQ_CDR_SINCE:--6h}

json_str() {
    local s=${1//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\t'/ }
    printf '"%s"' "$s"
}

list_json() {
    local first=1 v out="["
    for v in "$@"; do
        [ "$first" = 1 ] || out="$out,"
        out="$out$(json_str "$v")"
        first=0
    done
    echo "$out]"
}

redact() {
    sed -E 's/([Pp][Aa][Ss][Ss][Ww][Oo][Rr][Dd]|[Pp][Ww][Dd])([=:][[:space:]]*)[^[:space:];,"]+/\1\2***/g'
}

status() {
    local systemd=false timer_installed=false timer_active=false watcher=false result="" exit_status=0 last_exit=0 stamp
    local cron=false journal=false rejected=0 env_present=false line
    local -a missing errors
    if [ -d "$SYSTEMD_DIR" ]; then
        systemd=true
        [ "$("$SYSTEMCTL" show calldetailrecord.timer -p LoadState --value 2>/dev/null)" = loaded ] && timer_installed=true
        "$SYSTEMCTL" is-active --quiet calldetailrecord.timer 2>/dev/null && timer_active=true
        "$SYSTEMCTL" is-active --quiet ringq-cdr-trigger 2>/dev/null && watcher=true
        result=$("$SYSTEMCTL" show calldetailrecord.service -p Result --value 2>/dev/null)
        exit_status=$("$SYSTEMCTL" show calldetailrecord.service -p ExecMainStatus --value 2>/dev/null)
        case "$exit_status" in ''|*[!0-9]*) exit_status=0 ;; esac
        stamp=$("$SYSTEMCTL" show calldetailrecord.service -p ExecMainExitTimestamp --value 2>/dev/null)
        if [ -n "$stamp" ] && [ "$stamp" != n/a ]; then
            last_exit=$(date -d "$stamp" +%s 2>/dev/null || echo 0)
        fi
        local log
        if log=$("$JOURNALCTL" -u calldetailrecord.service --since "$SINCE" --no-pager -o cat 2>/dev/null); then
            journal=true
            rejected=$(printf '%s\n' "$log" | grep -c 'this is called JB')
            while IFS= read -r line; do
                [ -n "$line" ] && errors+=("${line:0:300}")
            done < <(printf '%s\n' "$log" | grep -E 'Fatal|SQLSTATE|Exception|Uncaught|Parse error|Warning:' | redact | tail -n 3)
        fi
    fi
    "$CRONTAB" -l -u root 2>/dev/null | grep -v '^[[:space:]]*#' | grep -q 'calldetailrecord' && cron=true
    if [ -f "$ENV_FILE" ]; then
        env_present=true
        for key in PostgreSQL_DB_USER PostgreSQL_DB_PASSWORD PostgreSQL_DB_HOST PostgreSQL_DB_NAME PostgreSQL_DB_PORT; do
            grep -Eq "^[[:space:]]*(export[[:space:]]+)?$key=[\"']?[^\"'[:space:]]" "$ENV_FILE" 2>/dev/null || missing+=("$key")
        done
    fi
    echo "{\"ok\":true,\"systemd\":$systemd,\"timer\":{\"installed\":$timer_installed,\"active\":$timer_active},\"watcher\":{\"active\":$watcher},\"service\":{\"result\":$(json_str "$result"),\"exitStatus\":$exit_status,\"lastExit\":$last_exit},\"cron\":{\"scheduled\":$cron},\"env\":{\"present\":$env_present,\"missing\":$(list_json "${missing[@]}")},\"journal\":$journal,\"rejected\":$rejected,\"recentErrors\":$(list_json "${errors[@]}")}"
}

case "$1" in
    status) status ;;
    *) echo '{"ok":false,"error":"usage: cdr-pipeline.sh status"}' ;;
esac
exit 0
