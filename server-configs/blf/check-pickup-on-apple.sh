#!/bin/bash
# Run on the Mac:  bash ~/Documents/Claude/blf/check-pickup-on-apple.sh
# READ-ONLY. Shows what BLF pickup on desk phones needs on apple: how an extension's SIP user maps
# to its number, which *8 pickup dial plans exist, and where dial plans are stored.
# Output saved to ~/Documents/Claude/blf/pickup-output.txt
HOST=root@165.232.169.74
OUT="$(cd "$(dirname "$0")" && pwd)/pickup-output.txt"
ssh "$HOST" 'bash -s' <<'REMOTE' 2>&1 | tee "$OUT"
TENANT=apple.ringq.ai
FS=$(command -v fs_cli || ls /usr/local/freeswitch/bin/fs_cli /usr/bin/fs_cli 2>/dev/null | head -1)
[ -z "$FS" ] && FS="docker exec ringq fs_cli"
echo "fs_cli: $FS"
echo "== 1. ext 1045 as FreeSWITCH sees it"
for a in id number-alias; do echo "1045 attr $a: $($FS -x "user_data 1045@$TENANT attr $a" 2>&1)"; done
ID=$($FS -x "user_data 1045@$TENANT attr id" 2>/dev/null | tr -d '\r\n ')
[ -n "$ID" ] && for a in id number-alias; do echo "$ID attr $a: $($FS -x "user_data $ID@$TENANT attr $a" 2>&1)"; done
echo "presence_id var: $($FS -x "user_data 1045@$TENANT var presence_id" 2>&1)"
echo
echo "== 2. presence settings of the SIP profiles"
$FS -x "sofia xmlstatus profile internal" 2>/dev/null | grep -io '<manage-presence>[^<]*\|<presence-hosts>[^<]*\|<presence-privacy>[^<]*'
echo
echo "== 3. pickup dial plans"
cd /tmp
for db in $(sudo -u postgres psql -At -c "select datname from pg_database where not datistemplate" 2>/dev/null); do
  sudo -u postgres psql -d "$db" -At -c "select '$db', dialplan_name, dialplan_context, dialplan_enabled, replace(dialplan_xml, E'\n', ' ') from v_dialplans where dialplan_xml like '%intercept%' or dialplan_xml like '%pickup%' limit 10;" 2>/dev/null | cut -c1-400
done
grep -rl 'intercept' /etc/freeswitch/dialplan /usr/local/freeswitch/conf/dialplan 2>/dev/null | head
echo
echo "== 4. dial plan cache / storage"
ls -d /var/cache/fusionpbx /var/cache/ringq 2>/dev/null; ls /var/cache/ringq 2>/dev/null | grep -i dialplan | head -5
REMOTE
echo "Saved: $OUT"
