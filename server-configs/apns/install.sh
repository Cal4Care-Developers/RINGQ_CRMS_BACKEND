#!/usr/bin/env bash
# Turn on direct-to-Apple iOS call pushes (node/apns.js) on one RingQ server.
# Run as root on the server, after copying the APNs .p8 key there (never commit the key).
# Works for both layouts: RingQ inside the "ringq" Docker container, or installed on the host.
#
#   bash install.sh /tmp/AuthKey_ABCDE12345.p8              # key id read from the file name
#   bash install.sh /tmp/key.p8 ABCDE12345                  # or given
#   CONTAINER=ringq TEAM_ID=6GP58F5X59 bash install.sh ...  # defaults shown
#
# Safe to run again (e.g. with a new key): the key is replaced and the APNS_* lines rewritten.
set -euo pipefail

KEY_SRC="${1:?usage: install.sh /path/to/AuthKey_KEYID.p8 [KEYID]}"
KEY_ID="${2:-$(basename "$KEY_SRC" | sed -nE 's/.*AuthKey_([A-Z0-9]{10})\.p8$/\1/p')}"
CONTAINER="${CONTAINER:-ringq}"
TEAM_ID="${TEAM_ID:-6GP58F5X59}"
BUNDLES="${BUNDLES:-com.ringq.app,com.ringq.app.preview,com.ringq.app.dev}"

[ -n "$KEY_ID" ] || { echo "key id not in the file name; pass it as the second argument" >&2; exit 1; }
grep -q "BEGIN PRIVATE KEY" "$KEY_SRC" || { echo "$KEY_SRC is not a .p8 private key" >&2; exit 1; }

# in the container if there is one, else on this host
if command -v docker >/dev/null 2>&1 && docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
	echo "RingQ runs in container $CONTAINER"
	run() { docker exec -i "$CONTAINER" sh -c "$1"; }
	put() { docker cp "$1" "$CONTAINER:$2"; }
else
	echo "RingQ runs on this host"
	run() { sh -c "$1"; }
	put() { cp "$1" "$2"; }
fi

DEST="/etc/ringq/apns/AuthKey_${KEY_ID}.p8"
run 'mkdir -p /etc/ringq/apns && chmod 700 /etc/ringq/apns'
put "$KEY_SRC" "$DEST"
run "chown root:root '$DEST' && chmod 600 '$DEST'"

# rewrite the APNS_* lines, keep everything else in .env as it is
run 'f=/etc/ringq/.env; t=$(mktemp); grep -v "^APNS_" "$f" > "$t" || true; cat >> "$t"; cat "$t" > "$f"; rm -f "$t"' <<EOF
APNS_KEY_FILE=$DEST
APNS_KEY_ID=$KEY_ID
APNS_TEAM_ID=$TEAM_ID
APNS_BUNDLE_IDS=$BUNDLES
EOF

# restart only the call server and check it came up with the key (only log lines written after the restart)
run '
O=/root/.pm2/logs/server-out.log; E=/root/.pm2/logs/server-error.log
BO=$(wc -c < "$O" 2>/dev/null || echo 0); BE=$(wc -c < "$E" 2>/dev/null || echo 0)
pm2 restart server --update-env >/dev/null
for i in $(seq 1 20); do
	if tail -c +$((BO+1)) "$O" 2>/dev/null | grep -q "\[apns\] direct to Apple"; then
		tail -c +$((BO+1)) "$O" | grep "\[apns\] direct to Apple" | tail -1; echo "APNS_OK"; exit 0
	fi
	sleep 1
done
echo "APNs not active after restart (is node/apns.js deployed?). New log lines:" >&2
tail -c +$((BO+1)) "$O" 2>/dev/null | grep -i "apns" | tail -5 >&2
tail -c +$((BE+1)) "$E" 2>/dev/null | tail -10 >&2
exit 1'

if [ "$KEY_SRC" != "$DEST" ]; then
	echo "Done. Delete the copy at $KEY_SRC when you no longer need it on this server."
fi
