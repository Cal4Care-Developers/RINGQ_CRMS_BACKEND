#!/bin/bash
# Run on the Mac:  bash ~/Documents/Claude/blf/install-on-apple.sh
# apple (165.232.169.74) ONLY. Puts the extension's "Busy Lamp Fields (BLF)" rows on desk phones:
#  - app/library/PhoneKeys.php (Yealink linekeys, Snom fkeys, Fanvil DSS keys, Grandstream GRP26xx/GXP21xx keys)
#  - IndexController: adds the keys to the config each Yealink/Snom/Fanvil/Grandstream phone downloads
#  - AccountsController: removing every BLF row now really clears them
# The two controllers are patched IN PLACE on apple (patch-blf.php), so the Snom router phone and
# desk phone changes already live there are kept. Safe to run twice. Backs everything up first.
set -e
HOST=root@165.232.169.74
DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"
shasum -a 256 -c SHA256SUMS
ssh "$HOST" 'mkdir -p /root/blf-upload'
scp PhoneKeys.php patch-blf.php blf-pickup.sql "$HOST:/root/blf-upload/"
ssh "$HOST" 'bash -s' <<'REMOTE'
set -e
TS=$(date +%Y%m%d-%H%M%S)
UP=/root/blf-upload
APP=/var/www/ringq/app/controllers
LIB=/var/www/ringq/app/library
[ "$(hostname -I | tr ' ' '\n' | grep -c '^165\.232\.169\.74$')" = "1" ] || { echo "not apple, stopping"; exit 1; }
BK=/root/backup-php-$TS-blf
mkdir -p "$BK"
for f in "$APP/IndexController.php" "$APP/AccountsController.php"; do
  cp -a "$f" "$BK/"; test -s "$BK/$(basename "$f")" || { echo "backup of $f failed, stopping"; exit 1; }
done
[ -e "$LIB/PhoneKeys.php" ] && cp -a "$LIB/PhoneKeys.php" "$BK/"
echo "php backup: $BK"
rm -rf "$UP/out"
php "$UP/patch-blf.php" "$APP/IndexController.php" "$APP/AccountsController.php" "$UP/out" || { echo "patch did not apply, nothing changed"; exit 1; }
for f in "$UP/out/IndexController.php" "$UP/out/AccountsController.php" "$UP/PhoneKeys.php"; do
  php -l "$f" >/dev/null || { echo "$f failed php -l, nothing changed"; exit 1; }
done
own() { install -m "$(stat -c '%a' "$APP/IndexController.php")" -o "$(stat -c '%U' "$APP/IndexController.php")" -g "$(stat -c '%G' "$APP/IndexController.php")" "$1" "$2"; }
own "$UP/PhoneKeys.php" "$LIB/PhoneKeys.php"; echo "library/PhoneKeys.php installed"
own "$UP/out/IndexController.php" "$APP/IndexController.php"; echo "IndexController.php updated"
own "$UP/out/AccountsController.php" "$APP/AccountsController.php"; echo "AccountsController.php updated"
for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -E '^php.*fpm'); do
  systemctl reload "$svc" && echo "reloaded $svc"
done
echo
echo "BLF pickup dial plan (*8 + the watched extension's SIP user picks up its ringing call):"
cp "$UP/blf-pickup.sql" /tmp/blf-pickup.sql && chmod 644 /tmp/blf-pickup.sql
(cd /tmp && sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -At -f /tmp/blf-pickup.sql)
rm -f /var/cache/ringq/dialplan.apple.ringq.ai /var/cache/fusionpbx/dialplan.apple.ringq.ai
echo "  dial plan cache for apple.ringq.ai cleared"
echo
echo "FreeSWITCH presence (BLF needs manage-presence true on the profile phones register to):"
(/usr/bin/fs_cli -x "sofia xmlstatus profile internal" 2>/dev/null | grep -io '<manage-presence>[^<]*\|<presence-hosts>[^<]*') || echo "  (fs_cli not reachable from here)"
echo
echo "Keys the Snom on ext 1103 would get now (read-only fetch):"
curl -sk --resolve apple.ringq.ai:8443:127.0.0.1 -A "snom D785" "https://apple.ringq.ai:8443/provision/2db3f557e1d6/000413e30aac.xml" | grep -o '<fkey[^>]*>[^<]*</fkey>' | head -12 || true
echo
echo "Rollback, if needed: cp -a $BK/IndexController.php $BK/AccountsController.php $APP/ && systemctl reload php7.4-fpm"
REMOTE
echo "Done. Reboot the desk phone (Auto Provisioning > IP Phones > Reboot) so it fetches the new keys."
