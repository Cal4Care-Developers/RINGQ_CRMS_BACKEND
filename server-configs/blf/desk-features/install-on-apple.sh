#!/bin/bash
# Run on the Mac:  bash server-configs/blf/desk-features/install-on-apple.sh  (from a ringq_core checkout)
# apple (165.232.169.74) ONLY. Desk phone keys for the rest of the BLF types, plus the phones' own buttons:
#  - Shared Parking slot n  -> park key *59n (park during a call, pick up when lit; same slots as the apps)
#  - Agent Status / Change Status -> *26<aux code id> sets the extension's aux code like the dialer
#  - Message button -> the extension's voicemail; DND button -> *78 / *79 (same DND as the apps)
# PHP controllers are patched IN PLACE (patch-blf.php), signal.js too (patch-signal-desk-park.py).
# Safe to run twice. Backs everything up first.
set -e
HOST=root@165.232.169.74
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"
# run from the ringq_core repo (server-configs/blf/desk-features) or from the project package (blf/desk-features)
if [ -f ../../../app/library/PhoneKeys.php ]; then PK=../../../app/library/PhoneKeys.php; else PK=../PhoneKeys.php; fi
[ -f SHA256SUMS ] && shasum -a 256 -c SHA256SUMS
ssh "$HOST" 'rm -rf /root/desk-upload && mkdir -p /root/desk-upload'
scp "$PK" ../patch-blf.php desk-aux-method.php.txt ringq_desk_park.lua ringq_desk_aux.lua \
    desk-features.sql patch-signal-desk-park.py "$HOST:/root/desk-upload/"
ssh "$HOST" 'bash -s' <<'REMOTE'
set -e
TS=$(date +%Y%m%d-%H%M%S)
UP=/root/desk-upload
APP=/var/www/ringq/app/controllers
LIB=/var/www/ringq/app/library
NODE=/var/www/ringq/node
SCRIPTS=/usr/share/freeswitch/scripts
[ "$(hostname -I | tr ' ' '\n' | grep -c '^165\.232\.169\.74$')" = "1" ] || { echo "not apple, stopping"; exit 1; }
[ -d "$SCRIPTS" ] || SCRIPTS=$(dirname "$(find / -name do_not_disturb.lua -path '*scripts*' 2>/dev/null | head -1)")
[ -d "$SCRIPTS" ] || { echo "lua scripts folder not found, stopping"; exit 1; }
BK=/root/backup-desk-features-$TS
mkdir -p "$BK"
cp -a "$APP/IndexController.php" "$APP/AccountsController.php" "$LIB/PhoneKeys.php" "$NODE/signal.js" "$BK/"
for f in ringq_desk_park.lua ringq_desk_aux.lua; do [ -e "$SCRIPTS/$f" ] && cp -a "$SCRIPTS/$f" "$BK/"; done
sudo -u postgres psql -d ringq -At -c "COPY (SELECT * FROM v_dialplans WHERE dialplan_name IN ('ringq_desk_park','ringq_desk_aux','do-not-disturb')) TO STDOUT WITH CSV HEADER" > "$BK/v_dialplans.csv"
echo "backup: $BK"

echo "== PHP"
rm -rf "$UP/out"
php "$UP/patch-blf.php" "$APP/IndexController.php" "$APP/AccountsController.php" "$UP/out" || { echo "patch did not apply, nothing changed"; exit 1; }
for f in "$UP/out/IndexController.php" "$UP/out/AccountsController.php" "$UP/PhoneKeys.php"; do
  php -l "$f" >/dev/null || { echo "$f failed php -l, nothing changed"; exit 1; }
done
echo "== signal.js"
python3 "$UP/patch-signal-desk-park.py" "$NODE/signal.js" "$UP/signal.js" || { echo "signal.js patch did not apply, nothing changed"; exit 1; }
node --check "$UP/signal.js" || { echo "patched signal.js failed node --check, nothing changed"; exit 1; }

own() { install -m "$(stat -c '%a' "$3")" -o "$(stat -c '%U' "$3")" -g "$(stat -c '%G' "$3")" "$1" "$2"; }
own "$UP/PhoneKeys.php" "$LIB/PhoneKeys.php" "$APP/IndexController.php"
own "$UP/out/IndexController.php" "$APP/IndexController.php" "$APP/IndexController.php"
own "$UP/out/AccountsController.php" "$APP/AccountsController.php" "$APP/AccountsController.php"
own "$UP/signal.js" "$NODE/signal.js" "$NODE/signal.js"
REF=$(ls "$SCRIPTS"/*.lua | head -1)
own "$UP/ringq_desk_park.lua" "$SCRIPTS/ringq_desk_park.lua" "$REF"
own "$UP/ringq_desk_aux.lua" "$SCRIPTS/ringq_desk_aux.lua" "$REF"
echo "files installed (lua in $SCRIPTS)"
for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php.*fpm'); do
  systemctl reload "$svc" && echo "reloaded $svc"
done

echo "== dial plans"
cp "$UP/desk-features.sql" /tmp/desk-features.sql && chmod 644 /tmp/desk-features.sql
(cd /tmp && sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -At -f /tmp/desk-features.sql)
rm -f /var/cache/ringq/dialplan.* /var/cache/fusionpbx/dialplan.*
echo "dial plan caches cleared"

echo "== signal (node) restart"
SIG=$(pm2 jlist 2>/dev/null | python3 -c 'import json,sys
for p in json.load(sys.stdin):
    if (p.get("pm2_env") or {}).get("pm_exec_path","").endswith("/signal.js"): print(p["name"])' | head -1)
if [ -n "$SIG" ]; then pm2 restart "$SIG" --update-env >/dev/null && echo "restarted pm2 app $SIG"; else echo "!! no pm2 app runs signal.js; check pm2 list"; pm2 list; fi
REMOTE
echo "Installed. Now run: bash $DIR/check-on-apple.sh"
