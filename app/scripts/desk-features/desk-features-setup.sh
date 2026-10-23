#!/bin/bash
# RingQ desk phone keys: install / update the call server side of Shared Parking, Agent Status /
# Change Status, BLF pickup and the DND button (the phone keys themselves come from PhoneKeys.php).
#
# Run by jobs.sh on every update and safe to run any time: every step is idempotent, and it never
# fails the update.
#   - ringq_desk_park.lua, ringq_desk_aux.lua, ringq_blf_pickup.lua -> the FreeSWITCH scripts folder
#     (when changed)
#   - desk-features.sql -> global dial plans *591-*595, *26<id>, *8<SIP user>; do-not-disturb on
#
# Log: /var/log/ringq-desk-features.log

HERE="$(cd "$(dirname "$0")" && pwd)"
LOG=/var/log/ringq-desk-features.log
DB=${RINGQ_DB:-ringq}
SCRIPTS=/usr/share/freeswitch/scripts
STAMP=/var/lib/ringq-desk-features/sql.sha256

log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
q() { su postgres -s /bin/sh -c "cd /tmp && psql -X -q -At -d $DB -c \"$1\"" 2>>"$LOG"; }

[ -d "$SCRIPTS" ] || { log "no $SCRIPTS, skipping (FreeSWITCH not on this host?)"; exit 0; }
command -v psql >/dev/null 2>&1 || { log "psql not found, skipping"; exit 0; }
mkdir -p "$(dirname "$STAMP")"

# 1. Lua scripts: copy when missing or changed, owned like the other scripts there
REF=$(ls "$SCRIPTS"/*.lua 2>/dev/null | head -1)
for f in ringq_desk_park.lua ringq_desk_aux.lua ringq_blf_pickup.lua; do
	if [ -f "$SCRIPTS/$f" ] && cmp -s "$HERE/$f" "$SCRIPTS/$f"; then continue; fi
	if install -m 0644 "$HERE/$f" "$SCRIPTS/$f"; then
		[ -n "$REF" ] && chown --reference="$REF" "$SCRIPTS/$f" 2>/dev/null
		log "installed $SCRIPTS/$f"
	else
		log "could not install $SCRIPTS/$f"
	fi
done

# 2. Dial plans: apply when the SQL changed or a plan is missing
WANT=$(sha256sum "$HERE/desk-features.sql" | cut -c1-64)
HAVE_N=$(q "select count(*) from v_dialplans where dialplan_name in ('ringq_desk_park','ringq_desk_aux','blf_sip_user_pickup') and dialplan_enabled::text = 'true'")
if [ "$(cat "$STAMP" 2>/dev/null)" != "$WANT" ] || [ "$HAVE_N" != "3" ]; then
	if su postgres -s /bin/sh -c "cd /tmp && psql -X -q -v ON_ERROR_STOP=1 -d $DB" < "$HERE/desk-features.sql" >/dev/null 2>>"$LOG"; then
		echo "$WANT" > "$STAMP"
		rm -f /var/cache/ringq/dialplan.* /var/cache/fusionpbx/dialplan.* 2>/dev/null
		log "dial plans applied, dial plan cache cleared"
	else
		log "dial plan SQL failed (psql output above)"
	fi
fi
exit 0
