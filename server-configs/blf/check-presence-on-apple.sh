#!/bin/bash
# Run on the Mac:  bash ~/Documents/Claude/blf/check-presence-on-apple.sh
# READ-ONLY. Why the BLF lamp stays off: presence settings of every SIP profile, the BLF
# subscriptions RingQ Call Services holds, and recent log lines about them.
# Output saved to ~/Documents/Claude/blf/presence-output.txt
HOST=root@165.232.169.74
OUT="$(cd "$(dirname "$0")" && pwd)/presence-output.txt"
ssh "$HOST" 'bash -s' <<'REMOTE' 2>&1 | tee "$OUT"
FS=/usr/bin/fs_cli
ALIAS=a2172077f45ab2bd508f
echo "== 1. profiles"
$FS -x "sofia status" 2>&1 | head -20
echo
echo "== 2. presence settings per profile"
for p in $($FS -x "sofia status" | awk '$2=="profile"{print $1}' | sort -u); do
  echo "-- $p"
  $FS -x "sofia status profile $p" | grep -i 'PRES\|DBName\|ODBC'
  $FS -x "sofia xmlstatus profile $p" | grep -io '<[a-z-]*presence[a-z-]*>[^<]*'
done
echo
echo "== 3. where 1031's phone is registered"
$FS -x "show registrations" 2>&1 | grep -i 'snom\|1031' | cut -c1-250 | head
echo
echo "== 4. BLF subscriptions held (dialog / presence)"
for db in $(ls /var/lib/freeswitch/db/*.db /usr/local/freeswitch/db/*.db 2>/dev/null); do
  command -v sqlite3 >/dev/null || { echo "(no sqlite3)"; break; }
  sqlite3 "$db" "select '$db', sub_to_user, sub_to_host, sip_user, sip_host, event, expires, profile_name from sip_subscriptions limit 40;" 2>/dev/null
done
cd /tmp
for db in $(sudo -u postgres psql -At -c "select datname from pg_database where not datistemplate" 2>/dev/null); do
  sudo -u postgres psql -d "$db" -At -c "select '$db', sub_to_user, sub_to_host, sip_user, sip_host, event, expires, profile_name from sip_subscriptions limit 40;" 2>/dev/null
done
echo
echo "== 5. recent FreeSWITCH log lines about subscriptions / this BLF"
LOG=$(ls /var/log/freeswitch/freeswitch.log /usr/local/freeswitch/log/freeswitch.log 2>/dev/null | head -1)
tail -n 200000 "$LOG" 2>/dev/null | grep -i "subscri\|$ALIAS" | grep -iv 'message-summary' | tail -25 | cut -c1-250
REMOTE
echo "Saved: $OUT"
