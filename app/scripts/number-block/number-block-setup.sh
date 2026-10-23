#!/bin/bash
# RingQ centralised number blocking: install / update the call server side.
#
# Run by jobs.sh on every update and safe to run any time: every step is idempotent, and it never
# fails the update.
#   - ringq_number_block.lua -> the FreeSWITCH scripts folder (when changed)
#   - number-block.sql -> table q_number_blocks + global dial plan ringq_number_block (order 38)
#
# Log: /var/log/ringq-number-block.log

HERE="$(cd "$(dirname "$0")" && pwd)"
LOG=/var/log/ringq-number-block.log
DB=${RINGQ_DB:-ringq}
SCRIPTS=/usr/share/freeswitch/scripts
STAMP=/var/lib/ringq-number-block/sql.sha256

log() { echo "$(date '+%F %T') $*" >> "$LOG"; }
q() { su postgres -s /bin/sh -c "cd /tmp && psql -X -q -At -d $DB -c \"$1\"" 2>>"$LOG"; }

[ -d "$SCRIPTS" ] || { log "no $SCRIPTS, skipping (call server not on this host?)"; exit 0; }
command -v psql >/dev/null 2>&1 || { log "psql not found, skipping"; exit 0; }
mkdir -p "$(dirname "$STAMP")"

# 1. Lua script first, so the dial plan never points at a missing script
REF=$(ls "$SCRIPTS"/*.lua 2>/dev/null | head -1)
f=ringq_number_block.lua
if ! { [ -f "$SCRIPTS/$f" ] && cmp -s "$HERE/$f" "$SCRIPTS/$f"; }; then
	if install -m 0644 "$HERE/$f" "$SCRIPTS/$f"; then
		[ -n "$REF" ] && chown --reference="$REF" "$SCRIPTS/$f" 2>/dev/null
		log "installed $SCRIPTS/$f"
	else
		log "could not install $SCRIPTS/$f, dial plan left as it is"
		exit 0
	fi
fi

# 2. Table + dial plan: apply when the SQL changed or the plan is missing
WANT=$(sha256sum "$HERE/number-block.sql" | cut -c1-64)
HAVE_N=$(q "select count(*) from v_dialplans where dialplan_name = 'ringq_number_block' and dialplan_enabled::text = 'true'")
if [ "$(cat "$STAMP" 2>/dev/null)" != "$WANT" ] || [ "$HAVE_N" != "1" ]; then
	if su postgres -s /bin/sh -c "cd /tmp && psql -X -q -v ON_ERROR_STOP=1 -d $DB" < "$HERE/number-block.sql" >/dev/null 2>>"$LOG"; then
		echo "$WANT" > "$STAMP"
		rm -f /var/cache/ringq/dialplan.* /var/cache/fusionpbx/dialplan.* 2>/dev/null
		log "table and dial plan applied, dial plan cache cleared"
	else
		log "number-block SQL failed (psql output above)"
	fi
fi
exit 0
