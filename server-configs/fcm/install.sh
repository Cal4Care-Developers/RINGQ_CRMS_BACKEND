#!/usr/bin/env bash
# Turn on Android call pushes through the RingQ app's Firebase project (node/ringPush.js) on one
# RingQ server. Run as root on the server, after copying the Firebase service account key there
# (Firebase console > Project settings > Service accounts > Generate new private key; never
# commit the key). Works for both layouts: RingQ inside the "ringq" Docker container, or on the host.
#
#   bash install.sh /tmp/ringq-push-firebase-adminsdk-xxxxx.json
#   CONTAINER=ringq bash install.sh ...   # default shown
#
# Safe to run again (e.g. with a new key): the key is replaced. Without it, Android pushes keep
# going through the shared Firebase app (node/secret.json) as before.
set -euo pipefail

KEY_SRC="${1:?usage: install.sh /path/to/firebase-service-account.json}"
CONTAINER="${CONTAINER:-ringq}"

grep -q '"type": *"service_account"' "$KEY_SRC" && grep -q '"private_key"' "$KEY_SRC" \
	|| { echo "$KEY_SRC is not a Firebase service account key" >&2; exit 1; }

if command -v docker >/dev/null 2>&1 && docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
	echo "RingQ runs in container $CONTAINER"
	run() { docker exec -i "$CONTAINER" sh -c "$1"; }
	put() { docker cp "$1" "$CONTAINER:$2"; }
else
	echo "RingQ runs on this host"
	run() { sh -c "$1"; }
	put() { cp "$1" "$2"; }
fi

DEST=/etc/ringq/fcm/service-account.json
run 'mkdir -p /etc/ringq/fcm && chmod 700 /etc/ringq/fcm'
put "$KEY_SRC" "$DEST"
run "chown root:root '$DEST' && chmod 600 '$DEST'"

# restart only the call server and check it picked the key up (only log lines written after the restart)
run '
O=/root/.pm2/logs/server-out.log; E=/root/.pm2/logs/server-error.log
BO=$(wc -c < "$O" 2>/dev/null || echo 0); BE=$(wc -c < "$E" 2>/dev/null || echo 0)
pm2 restart server --update-env >/dev/null
for i in $(seq 1 20); do
	if tail -c +$((BO+1)) "$O" 2>/dev/null | grep -q "android pushes via Firebase project"; then
		tail -c +$((BO+1)) "$O" | grep "android pushes via Firebase project" | tail -1; echo "FCM_OK"; exit 0
	fi
	sleep 1
done
echo "Firebase key not picked up after restart (is the new node/ringPush.js deployed?). New log lines:" >&2
tail -c +$((BO+1)) "$O" 2>/dev/null | grep -i "ring-push" | tail -5 >&2
tail -c +$((BE+1)) "$E" 2>/dev/null | tail -10 >&2
exit 1'
