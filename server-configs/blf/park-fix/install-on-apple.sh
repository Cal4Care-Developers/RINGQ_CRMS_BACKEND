#!/bin/bash
# Run on the Mac from a ringq_core checkout:  bash server-configs/blf/park-fix/install-on-apple.sh
# apple (165.232.169.74) ONLY. Shared Parking fixes:
#  - Snom park keys become BLF keys on *59N (the "orbit" key sent nothing to the server)
#  - web parking finds the other party's call leg when that phone registered by IP (signal.js, in place)
#  - the shared slot list apple.ringq.ai:parked is cut back to its 5 slots (it had grown to 390 entries)
set -e
HOST=root@165.232.169.74
cd "$(dirname "$0")"
scp ../../../app/library/PhoneKeys.php patch-signal-park-match.py "$HOST:/tmp/"
ssh "$HOST" 'bash -s' <<'REMOTE'
set -e
cd /tmp
[ "$(hostname -I | tr ' ' '\n' | grep -c '^165\.232\.169\.74$')" = "1" ] || { echo "not apple, stopping"; exit 1; }
LIB=/var/www/ringq/app/library; NODE=/var/www/ringq/node
BK=/root/backup-park-fix-$(date +%Y%m%d-%H%M%S); mkdir -p "$BK"
cp -a "$LIB/PhoneKeys.php" "$NODE/signal.js" "$BK/"; redis-cli LRANGE apple.ringq.ai:parked 0 -1 > "$BK/parked.txt"
echo "backup: $BK"
php -l /tmp/PhoneKeys.php >/dev/null
python3 /tmp/patch-signal-park-match.py "$NODE/signal.js" /tmp/signal.js
node --check /tmp/signal.js
install -m "$(stat -c '%a' "$LIB/PhoneKeys.php")" -o "$(stat -c '%U' "$LIB/PhoneKeys.php")" -g "$(stat -c '%G' "$LIB/PhoneKeys.php")" /tmp/PhoneKeys.php "$LIB/PhoneKeys.php"
install -m "$(stat -c '%a' "$NODE/signal.js")" -o "$(stat -c '%U' "$NODE/signal.js")" -g "$(stat -c '%G' "$NODE/signal.js")" /tmp/signal.js "$NODE/signal.js"
for svc in $(systemctl list-units --type=service --no-legend | awk '{print $1}' | grep -E '^php.*fpm'); do systemctl reload "$svc"; done
redis-cli LTRIM apple.ringq.ai:parked 0 4 >/dev/null; echo "slots: $(redis-cli LLEN apple.ringq.ai:parked)"
pm2 restart signal --update-env >/dev/null && echo "restarted signal"
echo "Snom 1031 park key now:"
curl -sk --resolve apple.ringq.ai:8443:127.0.0.1 -A "snom D150" https://apple.ringq.ai:8443/provision/2db3f557e1d6-000413d83397 | grep -o '<fkey[^>]*>[^<]*591[^<]*</fkey>'
REMOTE
echo "Done. Reboot the Snom so it gets the new park key."
