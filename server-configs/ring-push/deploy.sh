#!/usr/bin/env bash
# Put the new call push on ONE RingQ server by hand, from a checkout of this repo, and switch the old
# call pushes off. Normally the central update does all of this (jobs.sh -> ring-push setup); use this
# for a server that can't wait for it, or to try the branch on a test server first.
#
# What it does (nothing is replaced unless step 2 passes):
#   1. uploads, over ONE ssh connection, the node files of the new push (+ every local helper they
#      load), the two PHP controllers (one mobile app per extension) and app/scripts/ring-push
#   2. on the server: checks that every file parses, every require('./...') resolves and the helpers
#      load, with the server's own node and node_modules
#   3. swaps them in (each old file kept as <file>.bak-push-<run>), builds/loads mod_ringq, turns the
#      new push on for every domain (ring_push_mode = wake; the old pushes in server.js, attended.js
#      and status.js then stay quiet), sets the modern call tones, restarts the node services and
#      checks they are online - otherwise it prints the rollback command
#   4. optionally installs the APNs (.p8) and Firebase (service account .json) keys
#
# RingQ can run on the host or in the "ringq" Docker container; the login can be root or a user
# with passwordless sudo.
#
#   HOST=root@203.0.113.10 bash server-configs/ring-push/deploy.sh
#   HOST=admin@203.0.113.10 SSH_KEY=~/keys/server.pem \
#     APNS_KEY=~/Downloads/AuthKey_ABCDE12345.p8 FCM_KEY=~/Downloads/ringq-push-firebase-adminsdk-xxxx.json \
#     bash server-configs/ring-push/deploy.sh
#   HOST=... ROLLBACK=<run> bash server-configs/ring-push/deploy.sh   # put that run's files back, push off
#
# Keys are only copied to the server's /etc/ringq/apns and /etc/ringq/fcm (never into git).
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
HOST="${HOST:?set HOST=user@server}"
C="${CONTAINER:-ringq}"
RUN="$(date +%Y%m%d%H%M%S)"
STAGE="/tmp/ringq-push-$RUN"
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=20 -o ServerAliveInterval=15)
[ -n "${SSH_KEY:-}" ] && SSH+=(-i "$SSH_KEY")
SSH+=("$HOST")
NODE_FILES="server.js signal.js attended.js status.js ringPush.js apns.js pushOwner.js quietlog.js callJourney.js"
PHP_FILES="ControllerBase.php RegisterController.php"
SERVICES="server signal attended status"

# Runs on the server as root (sudo when needed), in the RingQ container when there is one.
remote() { # remote <<'EOS' ... EOS   (script on stdin; $RUN $STAGE $C and the lists are passed in)
	"${SSH[@]}" "sudo -n true 2>/dev/null && S=sudo || S=; \$S env RUN=$RUN STAGE=$STAGE C=$C NODE_FILES='$NODE_FILES' PHP_FILES='$PHP_FILES' SERVICES='$SERVICES' ROLLBACK='${ROLLBACK:-}' bash -s"
}

if [ -n "${ROLLBACK:-}" ]; then
	echo "== rolling back run $ROLLBACK on $HOST"
	remote <<'EOS'
set -uo pipefail
if command -v docker >/dev/null 2>&1 && docker inspect -f '{{.State.Running}}' "$C" 2>/dev/null | grep -q true; then
	IN() { docker exec -i "$C" bash -l -s; }
else
	IN() { bash -l -s; }
fi
IN <<IN_EOS
R=/var/www/ringq
for f in $NODE_FILES; do
	if [ -f \$R/node/\$f.bak-push-$ROLLBACK ]; then cp -p \$R/node/\$f.bak-push-$ROLLBACK \$R/node/\$f; echo "restored node/\$f";
	elif [ -f \$R/node/\$f.new-push-$ROLLBACK ]; then rm -f \$R/node/\$f \$R/node/\$f.new-push-$ROLLBACK; echo "removed node/\$f (new in that run)"; fi
done
for f in $PHP_FILES; do [ -f \$R/app/controllers/\$f.bak-push-$ROLLBACK ] && cp -p \$R/app/controllers/\$f.bak-push-$ROLLBACK \$R/app/controllers/\$f && echo "restored \$f"; done
set -a; . /etc/ringq/.env; set +a
PGPASSWORD="\$PostgreSQL_DB_PASSWORD" psql -h "\$PostgreSQL_DB_HOST" -p "\$PostgreSQL_DB_PORT" -U "\$PostgreSQL_DB_USER" -d "\$PostgreSQL_DB_NAME" -q -c "update v_default_settings set default_setting_value='off' where default_setting_category='ringq' and default_setting_subcategory='ring_push_mode'"
rm -f /var/cache/ringq/setting.*ring_push_mode* 2>/dev/null; fs_cli -x "memcache flush" >/dev/null
pm2 restart $SERVICES >/dev/null; sleep 6; pm2 ls | grep -E " (server|signal|attended|status) "
echo "ring push off: the old pushes are back"
IN_EOS
EOS
	exit 0
fi

echo "== bundling from $REPO"
BUNDLE="$(mktemp -d)"
mkdir -p "$BUNDLE/node" "$BUNDLE/ring-push" "$BUNDLE/php"
for f in $NODE_FILES; do cp "$REPO/node/$f" "$BUNDLE/node/"; done
cp "$REPO/app/scripts/ring-push/"* "$BUNDLE/ring-push/"
for f in $PHP_FILES; do cp "$REPO/app/controllers/$f" "$BUNDLE/php/"; done
cp "$REPO/server-configs/apns/install.sh" "$BUNDLE/apns-install.sh"
cp "$REPO/server-configs/fcm/install.sh" "$BUNDLE/fcm-install.sh"
if [ -n "${APNS_KEY:-}" ]; then cp "$APNS_KEY" "$BUNDLE/$(basename "$APNS_KEY" | sed -E 's/.*(AuthKey_[A-Z0-9]{10}\.p8)$/\1/')"; fi
if [ -n "${FCM_KEY:-}" ]; then cp "$FCM_KEY" "$BUNDLE/fcm-key.json"; fi

# one connection for the upload (some servers stall when many ssh/scp connections open back to back)
echo "== uploading to $HOST:$STAGE"
COPYFILE_DISABLE=1 tar -C "$BUNDLE" -czf - . | "${SSH[@]}" "mkdir -p $STAGE && tar -xzf - -C $STAGE && chmod -R go-rwx $STAGE && echo UPLOAD_OK"
rm -rf "$BUNDLE"

echo "== checking, then installing"
remote <<'EOS'
set -euo pipefail
# keys go in with the installers, which handle host or container themselves
for k in "$STAGE"/AuthKey_*.p8; do [ -f "$k" ] && { echo "== APNs key"; CONTAINER="$C" bash "$STAGE/apns-install.sh" "$k" || echo "!! APNs key install reported a problem"; }; done
[ -f "$STAGE/fcm-key.json" ] && { echo "== Firebase key"; CONTAINER="$C" bash "$STAGE/fcm-install.sh" "$STAGE/fcm-key.json" || echo "!! Firebase key install reported a problem"; }
rm -f "$STAGE"/AuthKey_*.p8 "$STAGE/fcm-key.json"

if command -v docker >/dev/null 2>&1 && docker inspect -f '{{.State.Running}}' "$C" 2>/dev/null | grep -q true; then
	echo "RingQ runs in container $C"
	docker exec "$C" mkdir -p "$STAGE" && docker cp "$STAGE/." "$C:$STAGE/"
	IN() { docker exec -i "$C" bash -l -s; }
else
	echo "RingQ runs on this host"
	IN() { bash -l -s; }
fi

IN <<IN_EOS
set -euo pipefail
R=/var/www/ringq

# --- preflight: nothing is replaced unless all of this passes ---
CHECK=$STAGE/check; mkdir -p \$CHECK
( cd \$R/node && for f in *.js *.json; do [ -f "\$f" ] && cp -a "\$f" \$CHECK/; done ) 2>/dev/null || true
ln -s \$R/node/node_modules \$CHECK/node_modules
cp $STAGE/node/* \$CHECK/
for f in $NODE_FILES; do node --check \$CHECK/\$f; done
cd \$CHECK && node -e '
  const fs = require("fs"), path = require("path");
  let bad = 0;
  for (const f of process.argv.slice(1)) {
    const src = fs.readFileSync(f, "utf8");
    for (const m of src.matchAll(/require\(\s*["\x27](\.[^"\x27]+)["\x27]\s*\)/g)) {
      try { require.resolve(path.resolve(m[1])); } catch (e) { console.error("MISSING", f, "->", m[1]); bad++; }
    }
  }
  for (const m of ["./quietlog", "./callJourney", "./pushOwner", "./apns", "./ringPush"]) {
    try { require(path.resolve(m)); } catch (e) { console.error("LOAD FAILED", m, e.message); bad++; }
  }
  if (bad) process.exit(1);
  console.log("PREFLIGHT_OK");
' $NODE_FILES
for f in $PHP_FILES; do php -l $STAGE/php/\$f >/dev/null; done && echo PHP_OK
cd /

# --- swap in (backups first) ---
put() {
	if [ -f "\$2" ]; then cp -p "\$2" "\$2.bak-push-$RUN"; cp "\$1" "\$2"; chown --reference="\$2.bak-push-$RUN" "\$2"; chmod --reference="\$2.bak-push-$RUN" "\$2";
	else cp "\$1" "\$2"; chown --reference="\$(dirname "\$2")" "\$2"; touch "\$2.new-push-$RUN"; fi
}
for f in $NODE_FILES; do put $STAGE/node/\$f \$R/node/\$f; done
for f in $PHP_FILES; do put $STAGE/php/\$f \$R/app/controllers/\$f; done
mkdir -p \$R/app/scripts/ring-push && cp $STAGE/ring-push/* \$R/app/scripts/ring-push/

# --- mod_ringq, new push on (once per server), modern tones ---
bash \$R/app/scripts/ring-push/ringq-push-setup.sh
tail -8 /var/log/ringq-push-setup.log

pm2 restart $SERVICES >/dev/null
sleep 12
pm2 ls | grep -E " (server|signal|attended|status) " || true
if pm2 jlist | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const bad=JSON.parse(s).filter(p=>["server","signal","attended","status"].includes(p.name)&&p.pm2_env.status!=="online");if(bad.length){console.error("NOT ONLINE:",bad.map(p=>p.name).join(" "));process.exit(1)}console.log("SERVICES_ONLINE")})'; then
	pm2 logs server --lines 200 --nostream 2>&1 | grep -E "\[apns\] direct|android pushes via|\[ring-push\] mod_ringq" | tail -3 || true
else
	echo "!! a service is not online - roll back with:  HOST=<this server> ROLLBACK=$RUN bash server-configs/ring-push/deploy.sh"; exit 1
fi
rm -rf $STAGE
IN_EOS
rm -rf "$STAGE"
echo "== DONE (run $RUN). Undo with:  HOST=$HOST ROLLBACK=$RUN bash server-configs/ring-push/deploy.sh"
EOS
