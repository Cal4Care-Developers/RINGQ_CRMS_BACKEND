#!/bin/bash
# =============================================================================
# ringq-health.sh -- runs every minute from cron (see README.md). Read-mostly.
#
#  * phone side SIP profile ("internal") must be RUNNING. A trunk save (/api/gateway) sometimes
#    restarts all SIP profiles and "internal" can stay half-stopped: phones/apps cannot
#    register (3 Oct 2026, 06:28-09:48). Auto-heal:
#       1. try "sofia profile internal start"
#       2. still down after 2 runs and no active calls -> restart FreeSWITCH + RingQ ESL services
#       3. calls active -> wait (max 10 min), then restart anyway
#  * trunk side ("external") must be RUNNING; trunks that were registered and now are not -> alert
#  * PM2 services restarting > 3 times in 15 min -> alert
#  * disk > 90 % -> alert
# Alerts: /var/log/ringq-health.log (+ optional ALERT_CMD, e.g. a mail or webhook command).
# =============================================================================
set -u
export PATH="$PATH:/usr/local/bin:/root/.nvm/versions/node/v14.21.3/bin"
LOG=/var/log/ringq-health.log
STATE=/var/lib/ringq-health; mkdir -p "$STATE"
ALERT_CMD="${ALERT_CMD:-}"            # optional: command that receives the alert text on stdin
exec 9>/run/ringq-health.lock; flock -n 9 || exit 0
now=$(date '+%F %T'); ts=$(date +%s)
alert() {  # alert <key> <text> -- logs once per 15 min per key
  local k=$1; shift; local f="$STATE/alert.$k"
  if [ ! -f "$f" ] || [ $(( ts - $(cat "$f") )) -ge 900 ]; then
    echo "$now ALERT [$k] $*" >> "$LOG"; echo "$ts" > "$f"
    [ -n "$ALERT_CMD" ] && echo "RingQ $(hostname -f 2>/dev/null) [$k] $*" | bash -c "$ALERT_CMD" >/dev/null 2>&1
  fi
}
info() { echo "$now INFO $*" >> "$LOG"; }
clear_alert() { [ -f "$STATE/alert.$1" ] && { rm -f "$STATE/alert.$1"; info "[$1] recovered"; }; }

fs_ok=1; systemctl is-active --quiet freeswitch || fs_ok=0
if [ $fs_ok = 0 ]; then alert freeswitch "FreeSWITCH service is not active"; exit 0; fi
clear_alert freeswitch
STATUS=$(timeout 10 fs_cli -x "sofia status" 2>/dev/null)
# an empty/failed answer is not proof of a down profile: retry once
echo "$STATUS" | grep -q "profile" || { sleep 3; STATUS=$(timeout 10 fs_cli -x "sofia status" 2>/dev/null); }
echo "$STATUS" | grep -q "profile" || { alert fs_cli "FreeSWITCH does not answer 'sofia status'"; exit 0; }
clear_alert fs_cli

# ---- phone side SIP (internal) ----
if echo "$STATUS" | grep -qE "^ +internal[[:space:]]+profile.*RUNNING"; then
  clear_alert sip-internal; rm -f "$STATE/internal.down"
else
  [ -f "$STATE/internal.down" ] || echo "$ts" > "$STATE/internal.down"
  since=$(( ts - $(cat "$STATE/internal.down") ))
  calls=$(timeout 10 fs_cli -x "show calls count" 2>/dev/null | grep -oE "^[0-9]+" | head -1); calls=${calls:-0}
  alert sip-internal "phone-side SIP profile 'internal' is DOWN for ${since}s (phones/apps cannot register); active calls: $calls"
  if [ $since -lt 90 ]; then
    info "trying: sofia profile internal start"; timeout 15 fs_cli -x "sofia profile internal start" >/dev/null 2>&1
  elif [ "$calls" = 0 ] || [ $since -ge 600 ]; then
    info "restarting FreeSWITCH (internal down ${since}s, calls $calls)"
    systemctl restart freeswitch; sleep 20
    for a in server signal queue attended; do pm2 restart "$a" >/dev/null 2>&1; done
    rm -f "$STATE/internal.down"; info "FreeSWITCH + server/signal/queue/attended restarted"
  else
    info "waiting for $calls active call(s) before restarting FreeSWITCH"
  fi
fi

# ---- trunk side (external) and registered trunks ----
if echo "$STATUS" | grep -qE "^ +external[[:space:]]+profile.*RUNNING"; then clear_alert sip-external; rm -f "$STATE/external.down"
else
  [ -f "$STATE/external.down" ] && alert sip-external "trunk-side SIP profile 'external' is not RUNNING (trunk calls fail)" || echo "$ts" > "$STATE/external.down"
fi
reged=$(echo "$STATUS" | awk '$2=="gateway" && $NF=="REGED"{print $1}' | sort)
if [ -f "$STATE/trunks.reged" ]; then
  lost=$(comm -23 "$STATE/trunks.reged" <(echo "$reged"))
  [ -n "$lost" ] && alert trunks "trunk(s) no longer registered: $(echo $lost | tr '\n' ' ')" || clear_alert trunks
fi
[ -n "$reged" ] && echo "$reged" > "$STATE/trunks.reged"

# ---- PM2 restart rate ----
if command -v pm2 >/dev/null; then
  pm2 jlist 2>/dev/null | python3 -c '
import sys,json
for p in json.load(sys.stdin): print(p["name"], p["pm2_env"].get("restart_time",0), p["pm2_env"]["status"])' 2>/dev/null > "$STATE/pm2.now"
  while read -r name rc st; do
    [ "$st" != "online" ] && alert "pm2-$name" "PM2 service $name is $st"
    hist="$STATE/pm2.$name"; echo "$ts $rc" >> "$hist"; tail -20 "$hist" > "$hist.t" && mv "$hist.t" "$hist"
    old=$(awk -v t=$((ts-900)) '$1>=t{print $2; exit}' "$hist"); old=${old:-$rc}
    [ $(( rc - old )) -gt 3 ] && alert "pm2-$name" "PM2 service $name restarted $(( rc - old )) times in 15 min"
  done < "$STATE/pm2.now"
fi

# ---- disk ----
use=$(df -P / | awk 'NR==2{gsub("%","",$5); print $5}')
[ "${use:-0}" -ge 90 ] && alert disk "disk / is ${use}% full" || clear_alert disk
exit 0
