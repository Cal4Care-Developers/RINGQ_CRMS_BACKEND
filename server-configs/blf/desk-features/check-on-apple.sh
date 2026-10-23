#!/bin/bash
# Run on the Mac:  bash ~/Documents/Claude/blf/desk-features/check-on-apple.sh
# apple only, read-only: does the desk phone key install look right?
HOST=root@165.232.169.74
ssh "$HOST" 'bash -s' <<'REMOTE'
D=apple.ringq.ai
ALIAS=60bd21bf97886c18e3e7
echo "== dial plans"
(cd /tmp && sudo -u postgres psql -d ringq -At -c "SELECT dialplan_name, dialplan_number, dialplan_enabled, dialplan_order FROM v_dialplans WHERE dialplan_name IN ('ringq_desk_park','ringq_desk_aux','do-not-disturb') ORDER BY 1")
echo "== aux codes ($D)"
(cd /tmp && sudo -u postgres psql -d ringq -At -c "SELECT a.id, a.auxcode_status FROM q_auxcodes a JOIN v_domains d USING (domain_uuid) WHERE d.domain_name='$D' ORDER BY a.id")
echo "== deskAux endpoint (aux=0 must give 404 from PHP; 403 or HTML means the route is wrong)"
curl -sk -m 8 --resolve $D:8443:127.0.0.1 -w '\nHTTP %{http_code}\n' "https://$D:8443/provision/000000000000/aux?rq_desk_aux=1&domain=$D&user=$ALIAS&aux=0"
echo "== from outside localhost it must be refused"
curl -s -m 8 -o /dev/null -w 'HTTP %{http_code}\n' "http://165.232.169.74/provision/000000000000/aux?rq_desk_aux=1&domain=$D&user=$ALIAS&aux=0"
echo "== DND script reads"
S=$(find /usr/share/freeswitch/scripts /etc/freeswitch -name do_not_disturb.lua 2>/dev/null | head -1); echo "$S"
grep -n 'getVariable\|UPDATE\|cache' "$S" | head -20
echo "== directory vars for $ALIAS"
for v in extension_uuid do_not_disturb mailbox; do printf '%s=' $v; /usr/bin/fs_cli -x "user_data $ALIAS@$D var $v"; done
echo "== park lamps (luarun)"
/usr/bin/fs_cli -x "luarun ringq_desk_park.lua lamps $D"; sleep 1
redis-cli LRANGE "$D:parked" 0 -1
echo "== Snom D150 on 1031 (000413d83397): park / status keys, mailbox, DND codes"
curl -sk --resolve $D:8443:127.0.0.1 -A "snom D150" "https://$D:8443/provision/2db3f557e1d6-000413d83397" \
  | grep -o '<fkey[^>]*>[^<]*</fkey>\|<user_mailbox[^>]*>[^<]*<\|<dnd_o[nf]*_code[^>]*>[^<]*<' | grep -v '>line<' | head -24
echo "== last errors"
tail -n 20 /var/log/freeswitch/freeswitch.log 2>/dev/null | grep -i 'ringq_desk\|lua' | tail -5
pm2 list 2>/dev/null | grep -i signal
REMOTE
