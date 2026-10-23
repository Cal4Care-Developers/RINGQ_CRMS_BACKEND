#!/bin/bash
# Run on the Mac:  bash ~/Documents/Claude/blf/check-blf-pickup-on-apple.sh
# READ-ONLY. Why pressing a blinking BLF key answers and then hangs up.
# When it says "CALL NOW", call 1045 from 1005 and let it ring; it reads while the call rings.
# Output saved to ~/Documents/Claude/blf/blf-pickup-output.txt
HOST=root@165.232.169.74
OUT="$(cd "$(dirname "$0")" && pwd)/blf-pickup-output.txt"
ssh "$HOST" 'bash -s' <<'REMOTE' 2>&1 | tee "$OUT"
FS=/usr/bin/fs_cli
T=apple.ringq.ai
ALIAS=a2172077f45ab2bd508f
cd /tmp
echo "== 1. pickup dial plans in the database"
sudo -u postgres psql -d ringq -At -c "select dialplan_name, dialplan_context, dialplan_order, dialplan_enabled from v_dialplans where dialplan_xml like '%intercept%' order by dialplan_order;"
echo
echo "== 2. dial plans that store the ringing call for pickup (last_dial_ext)"
sudo -u postgres psql -d ringq -At -c "select dialplan_name, dialplan_context, dialplan_order, replace(substring(dialplan_xml from position('last_dial_ext' in dialplan_xml)-120 for 260), E'\n', ' ') from v_dialplans where dialplan_xml like '%last_dial_ext%' and dialplan_xml like '%insert%';" | cut -c1-400
grep -rn 'last_dial_ext' /usr/share/freeswitch/scripts /etc/freeswitch /var/www/ringq/node 2>/dev/null | grep -v node_modules | cut -c1-250 | head -15
echo
echo "== 3. log of the last *8$ALIAS call"
LOG=/var/log/freeswitch/freeswitch.log
N=$(grep -n "\*8$ALIAS" "$LOG" | tail -1 | cut -d: -f1)
[ -z "$N" ] && N=$(grep -n "\*8$ALIAS" "$LOG.1" 2>/dev/null | tail -1 | cut -d: -f1)
[ -n "$N" ] && sed -n "$((N-70)),$((N+40))p" "$LOG" | cut -c1-260 || echo "(not in log)"
echo
echo "== 4. CALL NOW: call 1045 from 1005 and let it ring. Reading in 12 seconds..."
sleep 12
$FS -x "show channels as delim |" | cut -d'|' -f1,5,6,7,8,24,25 | head -8
for k in 1045 $ALIAS; do echo "hash $T-last_dial_ext/$k = [$($FS -x "hash select/$T-last_dial_ext/$k")]"; done
for u in $($FS -x "show channels as delim |" | awk -F'|' 'NR>1 && $1 ~ /-/{print $1}' | head -4); do
  echo "-- channel $u"
  $FS -x "uuid_dump $u" | grep -i 'presence_id\|destination_number\|Caller-Callee-ID-Number\|dialed_user\|last_dial\|domain_name\|^Channel-Name\|Answer-State' | head -12
done
REMOTE
echo "Saved: $OUT"
