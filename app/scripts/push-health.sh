#!/bin/bash
# What this server needs for mobile push, read as root, and the one server-side fix.
# Android phones keep a socket to status.js through nginx on 9999; an older installer opened 9999
# with `-i eth0`, so on a server whose network card is not eth0 the rule never matched and every
# Android phone stopped getting calls (sgringq96, 2026-09-18). Ring pushes also need the Firebase key
# (Android) and the Apple key (iOS, direct) when the new ring push is in charge.
#
#   push-health.sh status <domain>   one JSON line: firewall for 9999, whether 9999 listens, the
#                                    Firebase and Apple keys (present or not, never their content)
#                                    and this domain's last push errors from the server log
#   push-health.sh heal              open 9999 when the firewall closes it, and save the rules
#
# Always exits 0.

IPT=${RINGQ_IPTABLES:-iptables}
IPT_SAVE=${RINGQ_IPTABLES_SAVE:-iptables-save}
RULES_DIR=${RINGQ_RULES_DIR:-/etc/iptables}
SS=${RINGQ_SS:-ss}
IP=${RINGQ_IP:-ip}
FCM_KEY=${RINGQ_FCM_KEY:-/etc/ringq/fcm/service-account.json}
ENV_FILE=${RINGQ_ENV_FILE:-/etc/ringq/.env}
PM2_LOGS=${RINGQ_PM2_LOG_DIR:-/root/.pm2/logs}
LOG=${RINGQ_PUSH_LOG:-/var/log/ringq-push.log}
PORT=9999

log() {
    echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true
}

json_str() {
    local s=${1//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\t'/ }
    printf '"%s"' "$s"
}

FW_STATE=""
FW_POLICY=""
FW_BOUND=""
FW_MISSING=""

firewall() {
    local rules line iface target verdict=""
    FW_STATE=unknown; FW_POLICY=""; FW_BOUND=""; FW_MISSING=""
    rules=$("$IPT" -S INPUT 2>/dev/null) || return
    [ -n "$rules" ] || return
    FW_POLICY=$(echo "$rules" | awk '$1 == "-P" && $2 == "INPUT" { print $3 }')
    while IFS= read -r line; do
        case "$line" in "-A INPUT "*) ;; *) continue ;; esac
        echo "$line" | grep -Eq -- "--dport $PORT( |$)" || continue
        echo "$line" | grep -Eq -- "(^| )-s " && continue
        target=$(echo "$line" | awk '{ for (i = 1; i <= NF; i++) if ($i == "-j") print $(i + 1) }')
        iface=$(echo "$line" | awk '{ for (i = 1; i <= NF; i++) if ($i == "-i") print $(i + 1) }')
        if [ -n "$iface" ]; then
            case "$iface" in *[!A-Za-z0-9_.:-]*) continue ;; esac
            if ! "$IP" link show "$iface" >/dev/null 2>&1; then
                [ "$target" = ACCEPT ] && FW_MISSING="$FW_MISSING $iface"
                continue
            fi
            [ "$target" = ACCEPT ] && FW_BOUND="$FW_BOUND $iface"
        fi
        if [ -z "$verdict" ]; then
            case "$target" in ACCEPT|DROP|REJECT) verdict=$target ;; esac
        fi
    done <<< "$rules"
    case "$verdict" in
        ACCEPT) FW_STATE=open ;;
        DROP|REJECT) FW_STATE=closed ;;
        *) [ "$FW_POLICY" = ACCEPT ] && FW_STATE=open || FW_STATE=closed ;;
    esac
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

status() {
    local domain="$1" listening=false fcm=false project="" apns=false apnsKey=false keyfile errors="[]" f line start
    local -a lines
    case "$domain" in
        ''|*[!A-Za-z0-9.-]*) echo '{"ok":false,"error":"invalid domain"}'; return ;;
    esac
    firewall
    [ -n "$("$SS" -Hltn "sport = :$PORT" 2>/dev/null)" ] && listening=true
    if [ -f "$FCM_KEY" ]; then
        fcm=true
        project=$(grep -o '"project_id"[[:space:]]*:[[:space:]]*"[^"]*"' "$FCM_KEY" 2>/dev/null | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
    fi
    if [ -f "$ENV_FILE" ]; then
        keyfile=$(grep -E '^APNS_KEY_FILE=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'"'")
        if [ -n "$keyfile" ] && grep -Eq '^APNS_KEY_ID=.+' "$ENV_FILE" && grep -Eq '^APNS_TEAM_ID=.+' "$ENV_FILE"; then
            apns=true
            [ -f "$keyfile" ] && apnsKey=true
        fi
    fi
    for f in "$PM2_LOGS"/server-out*.log "$PM2_LOGS"/server-error*.log; do
        [ -f "$f" ] || continue
        while IFS= read -r line; do
            lines+=("${line:0:300}")
        done < <(tail -n 20000 "$f" 2>/dev/null | grep -F -- " $domain" | grep -E '\[(ring-push|apns)\].*(failed|send|token gone|cleanup|key)' | tail -n 20)
    done
    if [ "${#lines[@]}" -gt 0 ]; then
        start=$(( ${#lines[@]} > 20 ? ${#lines[@]} - 20 : 0 ))
        errors=$(list_json "${lines[@]:$start}")
    fi
    # shellcheck disable=SC2086
    echo "{\"ok\":true,\"firewall\":{\"state\":\"$FW_STATE\",\"policy\":$(json_str "$FW_POLICY"),\"boundTo\":$(list_json $FW_BOUND),\"missingInterfaces\":$(list_json $FW_MISSING)},\"listening\":$listening,\"fcmKey\":{\"present\":$fcm,\"projectId\":$(json_str "$project")},\"apns\":{\"configured\":$apns,\"keyFilePresent\":$apnsKey},\"recentErrors\":$errors}"
}

heal() {
    local tmp
    firewall
    if [ "$FW_STATE" != closed ]; then
        log "firewall for $PORT is $FW_STATE; nothing changed"
        return
    fi
    if "$IPT" -I INPUT 1 -p tcp --dport "$PORT" -j ACCEPT 2>/dev/null; then
        log "opened INPUT tcp/$PORT for every interface, first in the chain (was closed${FW_MISSING:+; rule bound to missing interface$FW_MISSING})"
    else
        log "could not open INPUT tcp/$PORT"
        return
    fi
    if [ -d "$RULES_DIR" ]; then
        tmp="$RULES_DIR/rules.v4.ringq-tmp"
        if "$IPT_SAVE" > "$tmp" 2>/dev/null && [ -s "$tmp" ]; then
            mv "$tmp" "$RULES_DIR/rules.v4" && log "saved the rules to $RULES_DIR/rules.v4"
        else
            rm -f "$tmp"
            log "could not save the rules; the change lasts until the next reboot"
        fi
    fi
}

case "$1" in
    status) status "$2" ;;
    heal) heal ;;
    *) echo "usage: $0 status <domain>|heal" >&2 ;;
esac
exit 0
