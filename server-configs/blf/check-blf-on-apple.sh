#!/bin/bash
# Run on the Mac:  bash ~/Documents/Claude/blf/check-blf-on-apple.sh [extension]
# READ-ONLY check on apple (165.232.169.74): is the BLF fix installed, what keys does each desk
# phone on the extension get, and does FreeSWITCH see the phone's BLF subscriptions.
# Writes nothing on apple. Output is also saved to ~/Documents/Claude/blf/check-output.txt.
EXT="${1:-1103}"
HOST=root@165.232.169.74
OUT="$(cd "$(dirname "$0")" && pwd)/check-output.txt"
ssh "$HOST" "EXT=$EXT bash -s" <<'REMOTE' 2>&1 | tee "$OUT"
APP=/var/www/ringq/app
TENANT=apple.ringq.ai
FS=$(command -v fs_cli || ls /usr/local/freeswitch/bin/fs_cli /usr/bin/fs_cli 2>/dev/null | head -1)
[ -z "$FS" ] && FS="docker exec ringq fs_cli"
echo "== 1. fix installed?"
ls -la $APP/library/PhoneKeys.php 2>&1
grep -n "library/PhoneKeys" $APP/controllers/IndexController.php || echo "IndexController does NOT load PhoneKeys.php"
echo "IndexController [blf-keys] lines: $(grep -c 'blf-keys' $APP/controllers/IndexController.php)"
echo "AccountsController [blf-keys] lines: $(grep -c 'blf-keys' $APP/controllers/AccountsController.php)"
ls -d /root/backup-php-*-blf 2>/dev/null | tail -3
echo
echo "== 2. PHP errors mentioning the fix (last 20)"
grep -h -i 'PhoneKeys\|withPhoneKeys' /var/log/nginx/*error*.log /var/log/php*fpm*.log /var/log/apache2/*error*.log 2>/dev/null | tail -5 | cut -c1-300
echo
echo "== 3. desk phones saved on ext $EXT (brand@model@mac@...)"
RC="redis-cli"; [ -n "$REDIS_PASS" ] && RC="redis-cli -a $REDIS_PASS"
$RC lrange "$EXT@$TENANT-macAddresses" 0 -1 2>&1
echo "keys written per phone: $($RC hgetall "phone-keys:$TENANT" 2>&1 | tr '\n' ' ')"
echo
echo "== 4. BLF rows saved for ext $EXT"
cd /tmp
for db in $(sudo -u postgres psql -At -c "select datname from pg_database where not datistemplate" 2>/dev/null); do
  sudo -u postgres psql -d "$db" -At -c "select '$db', extension, types, values, update_date from q_blfs b join v_domains d on d.domain_uuid=b.domain_uuid where d.domain_name='$TENANT' and extension='$EXT';" 2>/dev/null
done
echo
echo "== 5. keys in the config each phone downloads now"
for row in $($RC lrange "$EXT@$TENANT-macAddresses" 0 -1 2>/dev/null); do
  brand=$(echo "$row" | cut -d@ -f1); model=$(echo "$row" | cut -d@ -f2); mac=$(echo "$row" | cut -d@ -f3 | tr -d ':-' | tr 'A-F' 'a-f')
  case "$brand" in
    yealink) ua="Yealink SIP-$model"; ext=cfg ;;
    snom) ua="snom$model"; ext=xml ;;
    fanvil) ua="Fanvil $model"; ext=xml ;;
    grandstream) ua="Grandstream Model HW $model"; ext=xml ;;
    *) ua="$brand"; ext=xml ;;
  esac
  echo "-- $brand $model $mac (whole config: /tmp/blf-config-$mac.txt on apple)"
  curl -sk --max-time 10 --resolve $TENANT:8443:127.0.0.1 -A "$ua" "https://$TENANT:8443/provision/2db3f557e1d6/$mac.$ext" > /tmp/blf-config-$mac.txt
  echo "   $(wc -c < /tmp/blf-config-$mac.txt) bytes"
  grep -o -i 'linekey\.[0-9]*\.[a-z]* *=.*\|<fkey[^>]*>[^<]*</fkey>\|<Fkey index="[0-9]*">.*\?</Fkey>\|<P3[0-9][0-9]>[^<]*</P3[0-9][0-9]>\|<P14[0-9][0-9]>[^<]*</P14[0-9][0-9]>' /tmp/blf-config-$mac.txt | head -30
done
echo
echo "== 6. FreeSWITCH presence settings"
$FS -x "sofia xmlstatus profile internal" 2>/dev/null | grep -io '<manage-presence>[^<]*\|<presence-hosts>[^<]*'
echo
echo "== 7. BLF subscriptions FreeSWITCH holds (dialog)"
for db in /var/lib/freeswitch/db/sofia_reg_internal.db /usr/local/freeswitch/db/sofia_reg_internal.db; do
  [ -f "$db" ] && command -v sqlite3 >/dev/null && sqlite3 "$db" "select sub_to_user, sub_to_host, sip_user, event, expires from sip_subscriptions where event like '%dialog%' limit 30;"
done
sudo -u postgres psql -d freeswitch -At -c "select sub_to_user, sub_to_host, sip_user, event from sip_subscriptions where event like '%dialog%' limit 30;" 2>/dev/null
echo
echo "== 8. presence_id of live calls (make a call to the BLF extension while running this to see it)"
$FS -x "show channels" 2>/dev/null | cut -d, -f1,3,6,7,34 | head -10
$FS -x "show channels as delim |" 2>/dev/null | head -1 | tr '|' '\n' | grep -n presence_id
REMOTE
echo "Saved: $OUT"
