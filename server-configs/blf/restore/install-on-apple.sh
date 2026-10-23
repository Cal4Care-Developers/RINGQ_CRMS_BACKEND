#!/bin/bash
# Run on the Mac from a ringq_core checkout of blf-current-user-row:
#   bash server-configs/blf/restore/install-on-apple.sh
# apple (165.232.169.74) ONLY. Puts the desk phone keys + Shared Parking fix back after a release
# replaced app/ and node/ (release 9.2.0.49 on 2026-10-14 16:05 wiped the in-place patches).
# The release's node files are obfuscated, so the in-place patchers can't apply: this copies the
# plain files from this checkout instead (main + this branch; the release was built from main).
set -e
HOST=root@165.232.169.74
cd "$(dirname "$0")/../../.."
FILES="app/controllers/IndexController.php app/controllers/AccountsController.php app/library/PhoneKeys.php node/signal.js"
for f in $FILES; do [ -f "$f" ] || { echo "missing $f, run from a ringq_core checkout"; exit 1; }; done
php -l app/library/PhoneKeys.php >/dev/null 2>&1 || true
node --check node/signal.js
ssh "$HOST" 'rm -rf /root/restore-upload && mkdir -p /root/restore-upload'
tar cf - $FILES server-configs/blf/desk-features/ringq_desk_park.lua server-configs/blf/desk-features/ringq_desk_aux.lua \
  server-configs/blf/desk-features/desk-features.sql | ssh "$HOST" 'tar xf - -C /root/restore-upload'
ssh "$HOST" 'bash -s' <<'REMOTE'
set -e
UP=/root/restore-upload
R=/var/www/ringq
SCRIPTS=/usr/share/freeswitch/scripts
[ "$(hostname -I | tr ' ' '\n' | grep -c '^165\.232\.169\.74$')" = "1" ] || { echo "not apple, stopping"; exit 1; }
echo "release on apple: $(cat $R/version.txt 2>/dev/null)"
BK=/root/backup-restore-$(date +%Y%m%d-%H%M%S); mkdir -p "$BK"
for f in app/controllers/IndexController.php app/controllers/AccountsController.php app/library/PhoneKeys.php node/signal.js; do
  mkdir -p "$BK/$(dirname $f)"; cp -a "$R/$f" "$BK/$f"
done
echo "backup: $BK"
for f in app/controllers/IndexController.php app/controllers/AccountsController.php app/library/PhoneKeys.php; do
  php -l "$UP/$f" >/dev/null || { echo "$f failed php -l, nothing changed"; exit 1; }
done
node --check "$UP/node/signal.js" || { echo "signal.js failed node --check, nothing changed"; exit 1; }
own() { install -m "$(stat -c '%a' "$2")" -o "$(stat -c '%U' "$2")" -g "$(stat -c '%G' "$2")" "$1" "$2"; }
for f in app/controllers/IndexController.php app/controllers/AccountsController.php app/library/PhoneKeys.php node/signal.js; do
  own "$UP/$f" "$R/$f"
done
REF=$(ls "$SCRIPTS"/*.lua | head -1)
for f in ringq_desk_park.lua ringq_desk_aux.lua; do
  [ -e "$SCRIPTS/$f" ] || cp -a "$REF" "$SCRIPTS/$f"
  own "$UP/server-configs/blf/desk-features/$f" "$SCRIPTS/$f"
done
echo "files installed"
for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php.*fpm'); do
  systemctl reload "$svc" && echo "reloaded $svc"
done
cp "$UP/server-configs/blf/desk-features/desk-features.sql" /tmp/desk-features.sql && chmod 644 /tmp/desk-features.sql
(cd /tmp && sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -At -f /tmp/desk-features.sql) >/dev/null && echo "dial plans ok"
rm -f /var/cache/ringq/dialplan.* /var/cache/fusionpbx/dialplan.*
redis-cli LTRIM apple.ringq.ai:parked 0 4 >/dev/null; echo "park slots: $(redis-cli LLEN apple.ringq.ai:parked)"
SIG=$(pm2 jlist 2>/dev/null | python3 -c 'import json,sys
for p in json.load(sys.stdin):
    if (p.get("pm2_env") or {}).get("pm_exec_path","").endswith("/signal.js"): print(p["name"])' | head -1)
[ -n "$SIG" ] && pm2 restart "$SIG" --update-env >/dev/null && echo "restarted pm2 app $SIG"
sleep 3; pm2 jlist | python3 -c 'import json,sys
for p in json.load(sys.stdin):
    if (p.get("pm2_env") or {}).get("pm_exec_path","").endswith("/signal.js"): print("signal status:", p["pm2_env"]["status"], "restarts:", p["pm2_env"]["restart_time"])'
echo "== Snom 1031 keys"
curl -sk --resolve apple.ringq.ai:8443:127.0.0.1 -A "snom D150" https://apple.ringq.ai:8443/provision/2db3f557e1d6-000413d83397 \
  | grep -o '<fkey[^>]*>[^<]*</fkey>\|<user_mailbox[^>]*>[^<]*<\|<dnd_o[nf]*_code[^>]*>[^<]*<' | grep -v '>line<' | head -12
echo "== deskAux endpoint (aux=0 should be a 404 from PHP)"
curl -sk -m 8 --resolve apple.ringq.ai:8443:127.0.0.1 -o /dev/null -w 'HTTP %{http_code}\n' "https://apple.ringq.ai:8443/provision/000000000000/aux?rq_desk_aux=1&domain=apple.ringq.ai&user=60bd21bf97886c18e3e7&aux=0"
REMOTE
echo "Done. Reboot desk phones to get their keys back."
