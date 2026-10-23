#!/bin/bash
# Follows a changed public IP on servers whose ISP hands out a dynamic address (on-prem behind NAT).
# Without it a new ISP address shows on the dashboard (which asks the internet live) while the
# server name, Network > IP, the SIP profiles and the TURN relay keep the old one, and phones,
# apps and trunks cannot reach the server until someone saves Network > IP by hand.
#
#   public-ip-watch.sh          run once (cron, every minute)
#
# On a confirmed change it:
#   1. asks the licence server to point the server name (licence fqdn) at the new IP. The licence
#      server takes the IP from the connection itself, never from this script, so a licence key
#      can only ever point its own name at the address it is calling from
#   2. writes the new IP to ext-sip-ip / ext-rtp-ip
#   3. reloads the SIP profiles and refreshes the TURN relay, only when no call is up
#   4. emails the licence owner (old IP, new IP, whether the name moved)
#
# Rules, so it never fights a deliberate setting or drops a call:
#   - two lookup services must return the same public IPv4, CONFIRM runs in a row
#   - only plain IPv4 values are rewritten; autonat:/stun:/host:/$${var} values are left alone
#   - only rows that are enabled on an enabled profile, as Network > IP itself writes them
#   - profiles and TURN reload only with 0 calls up; until then the change waits and every run
#     tries again. Calls that were up when the ISP switched address are lost by the switch itself
#   - touch /etc/ringq/public-ip-watch.disabled to turn it off on a server
#
# State: /var/lib/ringq/public-ip-watch.state   Log: /var/log/ringq-public-ip-watch.log

CONFIG_PHP=${RINGQ_CONFIG_PHP:-/var/www/ringq/app/config/config.php}
STATE=${RINGQ_PUBLIC_IP_STATE:-/var/lib/ringq/public-ip-watch.state}
LOG=${RINGQ_PUBLIC_IP_LOG:-/var/log/ringq-public-ip-watch.log}
DISABLED=${RINGQ_PUBLIC_IP_DISABLED:-/etc/ringq/public-ip-watch.disabled}
SOFIA_CACHE_GLOB=${RINGQ_FS_SOFIA_CACHE_GLOB:-/var/cache/ringq/configuration.sofia.conf*}
TURN_CONF=${RINGQ_TURN_CONF:-/etc/turnserver.conf}
TURN_SETUP=${RINGQ_TURN_SETUP:-/var/www/ringq/app/scripts/turn/ringq-turn-setup.sh}
LICENSE_URL=${RINGQ_PUBLIC_IP_LICENSE_URL:-https://licensekey.ringq.com/process/publicip}
FS_CLI=${RINGQ_FS_CLI:-fs_cli}
HEALTH_LOCK=${RINGQ_HEALTH_LOCK:-/run/ringq-health.lock}
REDIS_CLI=${RINGQ_REDIS_CLI:-redis-cli}
CONFIRM=${RINGQ_PUBLIC_IP_CONFIRM:-3}

log() {
    echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true
}

is_ipv4() {
    echo "$1" | grep -qE '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'
}

# Private, loopback, CGNAT and link-local answers mean the lookup went wrong, not a new address.
is_public_ipv4() {
    is_ipv4 "$1" || return 1
    case "$1" in
        10.*|127.*|0.*|169.254.*|192.168.*) return 1 ;;
        172.1[6-9].*|172.2[0-9].*|172.3[0-1].*) return 1 ;;
        100.6[4-9].*|100.[7-9][0-9].*|100.1[0-1][0-9].*|100.12[0-7].*) return 1 ;;
    esac
    return 0
}

lookup() {
    curl -4 -s --max-time 5 "$1" 2>/dev/null | tr -d '[:space:]'
}

[ -f "$DISABLED" ] && exit 0
[ -f "$CONFIG_PHP" ] || exit 0
# One run at a time: a slow licence call must not overlap the next minute's run.
exec 9>"${STATE}.lock" 2>/dev/null && { flock -n 9 || exit 0; }

DB_PASS=$(awk "/'database' => \[/,/\]/ {
    if (\$0 ~ /'password'/) {
        match(\$0, /'password'[[:space:]]*=>[[:space:]]*'([^']*)'/, a);
        print a[1];
    }
}" "$CONFIG_PHP" 2>/dev/null)
[ -n "$DB_PASS" ] || exit 0

run_sql() {
    PGPASSWORD="$DB_PASS" psql -U ringq -d ringq -h 127.0.0.1 -p 5432 -v ON_ERROR_STOP=1 "$@"
}

mkdir -p "$(dirname "$STATE")" 2>/dev/null
# SEEN_*: the candidate being confirmed. OLD_IP/NEW_IP: the change being applied.
# DNS_IP: the address the licence server last confirmed for the name. PENDING: reload still owed.
SEEN_IP=""; SEEN_COUNT=0; OLD_IP=""; NEW_IP=""; DNS_IP=""; DNS_STATUS=""; PENDING=0
[ -f "$STATE" ] && . "$STATE" 2>/dev/null

save_state() {
    {
        printf 'SEEN_IP=%s\nSEEN_COUNT=%s\n' "$SEEN_IP" "$SEEN_COUNT"
        printf 'OLD_IP=%s\nNEW_IP=%s\nDNS_IP=%s\n' "$OLD_IP" "$NEW_IP" "$DNS_IP"
        printf 'DNS_STATUS=%s\nPENDING=%s\n' "$DNS_STATUS" "$PENDING"
    } > "$STATE" 2>/dev/null
}

A=$(lookup https://api.ipify.org)
B=$(lookup https://ifconfig.me/ip)
if is_public_ipv4 "$A" && [ "$A" = "$B" ]; then
    if [ "$A" = "$SEEN_IP" ]; then
        [ "$SEEN_COUNT" -lt "$CONFIRM" ] && SEEN_COUNT=$((SEEN_COUNT + 1))
    else
        SEEN_IP="$A"; SEEN_COUNT=1
    fi
fi
# No agreement this run: neither confirm nor forget a candidate, but still finish owed work below.

if [ "$SEEN_COUNT" -ge "$CONFIRM" ] && is_public_ipv4 "$SEEN_IP"; then
    PUBLIC_IP="$SEEN_IP"

    # 1. Server name. Asked again every run until the licence server confirms this address.
    if [ "$DNS_IP" != "$PUBLIC_IP" ]; then
        CONFIG_JSON=$("$REDIS_CLI" --raw GET ringq_config 2>/dev/null)
        LICENSE_KEY=$(printf '%s' "$CONFIG_JSON" | jq -r '.data.licenseKey // empty' 2>/dev/null)
        if [ -n "$LICENSE_KEY" ]; then
            REPLY=$(curl -s --max-time 20 -X POST -H 'Content-Type: application/json' \
                -d "$(jq -cn --arg k "$LICENSE_KEY" '{licenseKey: $k}')" "$LICENSE_URL" 2>/dev/null)
            RESULT=$(printf '%s' "$REPLY" | jq -r '.result // empty' 2>/dev/null)
            REPLY_IP=$(printf '%s' "$REPLY" | jq -r '.ipAddress // empty' 2>/dev/null)
            if { [ "$RESULT" = "success" ] || [ "$RESULT" = "unchanged" ]; } && [ "$REPLY_IP" = "$PUBLIC_IP" ]; then
                DNS_IP="$PUBLIC_IP"; DNS_STATUS="updated"
                log "server name points at $PUBLIC_IP ($RESULT)"
            else
                DNS_STATUS="failed"
                log "licence server did not move the server name to $PUBLIC_IP: $(printf '%s' "$REPLY" | jq -r '.message // empty' 2>/dev/null | head -c 200)"
            fi
        fi
    fi

    # 2. SIP profiles: database first, so the next reload of any kind picks the new address.
    while IFS='|' read -r row_uuid row_profile row_name row_value; do
        [ -z "$row_uuid" ] && continue
        is_ipv4 "$row_value" || continue
        [ "$row_value" = "$PUBLIC_IP" ] && continue
        if run_sql -c "UPDATE public.v_sip_profile_settings SET sip_profile_setting_value = '$PUBLIC_IP' WHERE sip_profile_setting_uuid = '$row_uuid';" >/dev/null 2>&1; then
            log "profile=$row_profile $row_name '$row_value' -> '$PUBLIC_IP'"
            if [ "$NEW_IP" != "$PUBLIC_IP" ]; then
                OLD_IP="$row_value"; NEW_IP="$PUBLIC_IP"
            fi
            PENDING=1
        else
            log "profile=$row_profile $row_name update failed"
        fi
    done < <(run_sql -tAF'|' -c "
        SELECT s.sip_profile_setting_uuid, p.sip_profile_name, s.sip_profile_setting_name, s.sip_profile_setting_value
        FROM public.v_sip_profile_settings s
        JOIN public.v_sip_profiles p ON p.sip_profile_uuid = s.sip_profile_uuid
        WHERE p.sip_profile_enabled::text = 'true'
          AND s.sip_profile_setting_enabled::text = 'true'
          AND s.sip_profile_setting_name IN ('ext-sip-ip', 'ext-rtp-ip')
    " 2>/dev/null)
fi

# 3. Reload, only with no call up.
if [ "$PENDING" -eq 1 ]; then
    calls=$(timeout 10 "$FS_CLI" -x "show calls count" 2>/dev/null | grep -oE '^[0-9]+' | head -1)
    if [ "${calls:-x}" = "0" ]; then
        rm -f $SOFIA_CACHE_GLOB 2>/dev/null
        # One "reload mod_sofia", as the Network > IP save does. Restarting profiles one by one
        # left "internal" half-stopped on apple (23 Oct) and ringq-health's own "profile start"
        # then raced it into an orphan. Hold ringq-health's lock until the profiles are back so
        # it does not step in mid-reload.
        exec 8>"$HEALTH_LOCK" 2>/dev/null && flock -w 70 8
        timeout 60 "$FS_CLI" -x "reload mod_sofia" >/dev/null 2>&1
        profiles=$(run_sql -tA -c "SELECT sip_profile_name FROM public.v_sip_profiles WHERE sip_profile_enabled::text = 'true';" 2>/dev/null)
        for i in $(seq 1 30); do
            status=$(timeout 10 "$FS_CLI" -x "sofia status" 2>/dev/null)
            down=""
            for profile in $profiles; do
                echo "$status" | grep -qE "^ +$profile[[:space:]]+profile.*RUNNING" || down="$down $profile"
            done
            [ -z "$down" ] && break
            sleep 1
        done
        exec 8>&- 2>/dev/null
        if [ -z "$down" ]; then
            log "SIP profiles reloaded and running for public IP $NEW_IP"
        else
            log "SIP profiles reloaded for public IP $NEW_IP; not running after 30 s:$down"
        fi
        # TURN announces the public IP in external-ip; the setup script restarts coturn only
        # when its config actually changes.
        if [ -f "$TURN_CONF" ] && [ -f "$TURN_SETUP" ] && ! grep -q "^external-ip=$NEW_IP\(/\|$\)" "$TURN_CONF"; then
            RINGQ_TURN_PUBLIC_IP="$NEW_IP" bash "$TURN_SETUP" >/dev/null 2>&1
            log "TURN relay refreshed for public IP $NEW_IP"
        fi
        PENDING=0

        # 4. Tell the licence owner. The worker picks the recipient from the licence data.
        "$REDIS_CLI" LPUSH email_queue "$(jq -cn \
            --arg old "$OLD_IP" --arg new "$NEW_IP" --arg dns "$DNS_STATUS" --argjson at "$(date +%s)" \
            '{type: "public_ip_changed", data: {oldIp: $old, newIp: $new, dns: $dns, appliedAt: $at}}')" >/dev/null 2>&1
    else
        log "public IP $NEW_IP saved; reload waits for ${calls:-unknown} call(s) to end"
    fi
fi

save_state
exit 0
