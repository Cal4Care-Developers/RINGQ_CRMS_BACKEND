#!/bin/bash
# fail2ban-reconcile.sh
# Walks every active fail2ban jail and upserts currently-banned IPs into
# the sip_security_blocks table, plus marks unblocked any IPs that fail2ban
# has released since the last run.
#
# Category is inferred from the jail name. Only SIP-origin categories are
# prefixed `sip_` — those are the ONLY ones the future central sync (Phase 5)
# is allowed to broadcast. SSH / web / unknown jails MUST stay local, so they
# get non-sip_ categories.
#
#   freeswitch* / fs-* / *sip*   -> sip_reg_failure   (SIP, syncable)
#   SIPInvite / *sip-invite*     -> sip_bruteforce    (SIP, syncable)
#   sshd / ssh                   -> ssh_bruteforce    (LOCAL only)
#   nginx* / apache* / webapp*   -> webapp_login      (LOCAL only)
#   recidive                     -> recidive          (LOCAL only)
#   anything else                -> other             (LOCAL only)
#
# Real failure counts come from fail2ban's own sqlite DB (bips.data.failures),
# NOT from counting reconciler runs.

set -e

CONFIG_PHP="/var/www/ringq/app/config/config.php"
[ -f "$CONFIG_PHP" ] || { echo "config.php not found"; exit 0; }

DB_PASS=$(awk "/'database' => \[/,/\]/ {
    if (\$0 ~ /'password'/) {
        match(\$0, /'password'[[:space:]]*=>[[:space:]]*'([^']*)'/, a);
        print a[1];
    }
}" "$CONFIG_PHP" 2>/dev/null)

[ -n "$DB_PASS" ] || { echo "DB password not extracted"; exit 0; }

run_sql() {
    PGPASSWORD="$DB_PASS" psql -U ringq -d ringq -h 127.0.0.1 -p 5432 -v ON_ERROR_STOP=1 "$@"
}

categorize() {
    case "$1" in
        SIPInvite|sipinvite|*sip-invite*) echo "sip_bruteforce" ;;
        freeswitch*|fs-*) echo "sip_reg_failure" ;;
        sshd|ssh) echo "ssh_bruteforce" ;;
        nginx*|apache*|webapp*) echo "webapp_login" ;;
        recidive) echo "recidive" ;;
        *)
            case "$1" in
                *sip*) echo "sip_reg_failure" ;;
                *) echo "other" ;;
            esac
            ;;
    esac
}

# ---------------------------------------------------------------------------
# One-time correction of rows mislabeled by the previous categorize() logic.
# Recompute category from the jail name embedded in the auto-ban reason string.
# Guarded by a marker row so it only runs once.
# ---------------------------------------------------------------------------
ALREADY_FIXED=$(run_sql -tA -c "SELECT 1 FROM security_audit_log WHERE action='maintenance' AND reason='recategorize_v1' LIMIT 1" 2>/dev/null | tr -d '[:space:]')
if [ "$ALREADY_FIXED" != "1" ]; then
    run_sql >/dev/null <<'SQL' || true
-- The old categorize() dumped ssh / recidive / unknown jails into 'sip_manual',
-- which is a sip_% category and would wrongly sync (Section 4 violation). Drop
-- only those mislabeled auto-ban rows; the loop below rebuilds the still-active
-- ones under the correct local category. Manual UI bans (reason 'Manual ban via
-- SIP Guard UI') and correctly-categorized rows are left untouched.
DELETE FROM sip_security_blocks
WHERE category = 'sip_manual'
  AND reason LIKE 'fail2ban auto-ban%';
INSERT INTO security_audit_log (actor, action, ip_address, category, reason)
VALUES ('system', 'maintenance', '0.0.0.0'::inet, 'maintenance', 'recategorize_v1');
SQL
fi

# ---------------------------------------------------------------------------
# Build ip|jail -> failures map from fail2ban's own sqlite DB (best-effort).
# Falls back to 1 if sqlite3 / JSON1 unavailable.
# ---------------------------------------------------------------------------
declare -A FAILMAP
F2B_DB='/var/lib/fail2ban/fail2ban.sqlite3'
if command -v sqlite3 >/dev/null 2>&1 && [ -r "$F2B_DB" ]; then
    while IFS='|' read -r _ip _jail _fails; do
        [ -n "$_ip" ] && FAILMAP["$_ip|$_jail"]="$_fails"
    done < <(sqlite3 "file:${F2B_DB}?mode=ro" \
        "SELECT ip, jail, COALESCE(json_extract(data,'\$.failures'), bancount, 1) FROM bips;" 2>/dev/null)
fi

ACTIVE_BANS=$(mktemp)
trap 'rm -f "$ACTIVE_BANS"' EXIT

JAILS=$(sudo /usr/bin/fail2ban-client status 2>/dev/null | awk -F'Jail list:' '/Jail list:/ {print $2}' | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$')

for JAIL in $JAILS; do
    case "$JAIL" in
        *[!A-Za-z0-9_.-]*) continue ;;
    esac
    CATEGORY=$(categorize "$JAIL")
    BANNED_LINE=$(sudo /usr/bin/fail2ban-client status "$JAIL" 2>/dev/null | grep 'Banned IP list:' || true)
    BANNED_IPS=$(echo "$BANNED_LINE" | sed 's/.*Banned IP list:[[:space:]]*//')
    [ -z "$BANNED_IPS" ] && continue
    for IP in $BANNED_IPS; do
        case "$IP" in
            *[!0-9a-fA-F:./]*) continue ;;
            '') continue ;;
        esac
        echo "$IP|$CATEGORY|$JAIL" >> "$ACTIVE_BANS"
        FAILS="${FAILMAP["$IP|$JAIL"]:-1}"
        case "$FAILS" in
            ''|*[!0-9]*) FAILS=1 ;;
        esac
        PGPASSWORD="$DB_PASS" psql -U ringq -d ringq -h 127.0.0.1 -p 5432 -v ON_ERROR_STOP=1 >/dev/null <<SQL
INSERT INTO sip_security_blocks
    (ip_address, category, reason, failure_count, first_seen_at, last_seen_at, blocked_at, status, source, jail_name)
VALUES
    ('$IP'::inet, '$CATEGORY', 'fail2ban auto-ban ($JAIL)', $FAILS, NOW(), NOW(), NOW(), 'blocked', 'local', '$JAIL')
ON CONFLICT (ip_address, category) DO UPDATE SET
    last_seen_at = NOW(),
    failure_count = GREATEST(sip_security_blocks.failure_count, EXCLUDED.failure_count),
    status = 'blocked',
    unblocked_at = NULL,
    jail_name = EXCLUDED.jail_name;

INSERT INTO security_audit_log (actor, action, ip_address, category, reason, metadata)
SELECT 'system', 'block', '$IP'::inet, '$CATEGORY', 'fail2ban auto-ban ($JAIL)', json_build_object('jail', '$JAIL')::jsonb
WHERE NOT EXISTS (
    SELECT 1 FROM security_audit_log
    WHERE ip_address = '$IP'::inet AND action = 'block' AND category = '$CATEGORY'
      AND occurred_at > NOW() - INTERVAL '1 hour'
);
SQL
    done
done

# Reconcile: rows marked blocked-by-fail2ban but NOT in current ACTIVE_BANS -> mark unblocked
if [ -s "$ACTIVE_BANS" ]; then
    ACTIVE_LIST=$(awk -F'|' '{print "(\x27"$1"\x27::inet,\x27"$2"\x27)"}' "$ACTIVE_BANS" | paste -sd, -)
    run_sql >/dev/null <<SQL
UPDATE sip_security_blocks
SET status = 'unblocked', unblocked_at = NOW()
WHERE source = 'local'
  AND jail_name IS NOT NULL
  AND status = 'blocked'
  AND (ip_address, category) NOT IN ($ACTIVE_LIST);
SQL
else
    run_sql >/dev/null <<SQL
UPDATE sip_security_blocks
SET status = 'unblocked', unblocked_at = NOW()
WHERE source = 'local' AND jail_name IS NOT NULL AND status = 'blocked';
SQL
fi

exit 0
