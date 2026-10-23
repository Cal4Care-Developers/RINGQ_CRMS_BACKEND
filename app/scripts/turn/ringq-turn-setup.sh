#!/bin/bash
# RingQ TURN relay: install / update coturn and the /api/turn route on this server.
#
# Safe to run any time and from an update: every step is idempotent and it never fails the update.
# The relay only forwards media to this server's own public IP (where RingQ Call Services RTP
# lives); every other peer, private and loopback ranges included, is refused. Clients get
# short-lived credentials from GET https://<host>:8443/api/turn (node/turnCredentials.js, signed-in
# only), signed with TURN_SECRET from /etc/ringq/.env. The secret is made once, only when missing,
# and never rotated or printed here.
#
#   bash ringq-turn-setup.sh
#
# Overrides (environment): RINGQ_TURN_HOST, RINGQ_TURN_IP, RINGQ_TURN_PUBLIC_IP, RINGQ_TURN_PORT,
# RINGQ_TURNS_PORT, RINGQ_TURN_MIN_PORT, RINGQ_TURN_MAX_PORT.
# Log: /var/log/ringq-turn-setup.log   Backups of changed files: /root/backup-turn-<date>/

HERE="$(cd "$(dirname "$0")" && pwd)"
LOG=/var/log/ringq-turn-setup.log
BACKUP=/root/backup-turn-$(date +%Y%m%d)
ENV=/etc/ringq/.env
CONF=/etc/turnserver.conf
CERTS=/etc/coturn/certs
SYNC=/usr/local/sbin/ringq-turn-cert-sync
NGINX_SITE=/etc/nginx/sites-available/default
RULES=/etc/iptables/rules.v4

log() { echo "$(date '+%F %T') $*" | tee -a "$LOG"; }
backup() { [ -e "$1" ] || return 0; mkdir -p "$BACKUP"; [ -e "$BACKUP/$(basename "$1")" ] || cp -p "$1" "$BACKUP/"; }

[ "$(id -u)" = 0 ] || { log "must run as root, skipping"; exit 0; }
command -v apt-get >/dev/null 2>&1 || { log "apt-get not found, skipping"; exit 0; }

# 1. coturn (turnserver + turnutils_*). Installed with services held so the stock config never
# starts and grabs ports before ours is written.
if ! command -v turnserver >/dev/null 2>&1; then
	printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 755 /usr/sbin/policy-rc.d
	if DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends coturn >>"$LOG" 2>&1; then
		log "coturn installed"
	else
		log "coturn install failed (apt output above)"
	fi
	rm -f /usr/sbin/policy-rc.d
	command -v turnserver >/dev/null 2>&1 || exit 0
	systemctl stop coturn >/dev/null 2>&1
fi

# 2. where and on which ports. 3478 is often already taken (RingQ Meet's own TURN listens on UDP
# 3478), so the relay moves to 3479 when anything other than coturn holds it.
#
# The host is the server's public name, never the machine's own hostname: most servers are named
# after the tenant or the cloud instance (randall-blanchard-llc-<uuid>, ip-172-31-84-253), which
# does not resolve and is not on the certificate. Taken, in order, from: the override, the
# licence (redis ringq_config data.fqdn, the name the licence and the SSL panel are issued for),
# the name on the certificate nginx serves on :8443, turn.<region> for a wildcard (multi-tenant)
# certificate, the dehydrated domain, and only then a
# hostname -f that has a dot and resolves.
#
# Single tenant servers are xyz.domain.com and the licence names them. Multi-tenant servers are
# xyz.region.domain.com behind a *.region.domain.com certificate and wildcard DNS, so when the
# licence name does not resolve, turn.region.domain.com does (from the wildcard certificate, or
# turn.<publicUrl> on install_mode mt).
valid_name() { echo "$1" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$' && ! echo "$1" | grep -Eq '^[0-9.]+$'; }
resolves() { getent ahostsv4 "$1" >/dev/null 2>&1; }
NGINX_CERT="$(sed -n '/listen 8443/,/}/s/^[[:space:]]*ssl_certificate[[:space:]]\+\([^;]*\);.*/\1/p' "$NGINX_SITE" 2>/dev/null | head -1)"
host_candidates() {
	echo "${RINGQ_TURN_HOST:-}"
	redis-cli --raw get ringq_config 2>/dev/null | sed -n 's/.*"fqdn"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1
	[ -f "$NGINX_CERT" ] && openssl x509 -in "$NGINX_CERT" -noout -subject 2>/dev/null | sed -n 's/.*CN[[:space:]]*=[[:space:]]*\([^,/]*\).*/\1/p' | grep -v '^\*'
	# multi-tenant fallback: a region's wildcard certificate (*.us6.ringq.cloud) comes with wildcard
	# DNS, so turn.<region domain> always resolves to this server and is covered
	[ -f "$NGINX_CERT" ] && openssl x509 -in "$NGINX_CERT" -noout -text 2>/dev/null | grep -o 'DNS:\*\.[^,[:space:]]*' | sed 's/^DNS:\*\./turn./' | head -1
	# or turn.<publicUrl> on install_mode mt, when the certificate is not a wildcard
	if [ "$(redis-cli --raw get install_mode 2>/dev/null)" = mt ]; then
		sed -n "s/.*'publicUrl'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/turn.\1/p" "$HERE/../../config/config.php" 2>/dev/null | head -1
	fi
	awk 'NF && $1 !~ /^#/ {print $1; exit}' /etc/dehydrated/domains.txt 2>/dev/null
	hostname -f 2>/dev/null
}
HOST=""
while read -r h; do
	h="$(echo "$h" | tr 'A-Z' 'a-z' | tr -d '[:space:]')"
	[ -n "$h" ] && valid_name "$h" || continue
	# the override is taken as given; everything else must resolve to be usable by clients
	{ [ "$h" = "${RINGQ_TURN_HOST:-}" ] || resolves "$h"; } && { HOST="$h"; break; }
done <<EOF
$(host_candidates)
EOF
[ -n "$HOST" ] || { log "public hostname not found (licence fqdn, certificate, dehydrated): relay not configured"; exit 0; }

# Local address coturn binds to, and the public address clients reach. They differ behind 1:1 NAT
# (AWS, Oracle, GCP): the instance only has the private IP, so coturn listens and relays on it and
# announces the public one (external-ip=PUBLIC/PRIVATE). The public IP is what the host name
# resolves to.
IP="${RINGQ_TURN_IP:-$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p')}"
PUBLIC_IP="${RINGQ_TURN_PUBLIC_IP:-$(getent ahostsv4 "$HOST" 2>/dev/null | awk '{print $1; exit}')}"
[ -n "$PUBLIC_IP" ] || PUBLIC_IP="$IP"
if [ "$PUBLIC_IP" != "$IP" ] && ip -4 -o addr show 2>/dev/null | grep -q " $PUBLIC_IP/"; then
	IP="$PUBLIC_IP" # the public address is on this machine after all
fi
if [ "$PUBLIC_IP" = "$IP" ]; then EXTERNAL_IP="$IP"; else EXTERNAL_IP="$PUBLIC_IP/$IP"; fi
in_use_by_other() {
	ss -Hlnup "sport = :$1" 2>/dev/null | grep -v turnserver | grep -q . \
		|| ss -Hlntp "sport = :$1" 2>/dev/null | grep -v turnserver | grep -q .
}
PORT="${RINGQ_TURN_PORT:-}"
if [ -z "$PORT" ]; then
	PORT=3478
	in_use_by_other 3478 && PORT=3479
fi
TLS_PORT="${RINGQ_TURNS_PORT:-5349}"
MIN_PORT="${RINGQ_TURN_MIN_PORT:-34000}"
MAX_PORT="${RINGQ_TURN_MAX_PORT:-35999}"
for p in "$PORT" "$TLS_PORT"; do
	in_use_by_other "$p" && { log "port $p is in use by another service: relay not configured"; exit 0; }
done
[ -n "$IP" ] || { log "public IPv4 not found: relay not configured"; exit 0; }

# Certificate: the one nginx serves on :8443 (a wildcard such as *.us6.ringq.cloud on multi-tenant
# servers), else one issued for the host; either way it must cover the host.
CERT_DIR=""
for d in "$(dirname "${NGINX_CERT:-/nonexistent/x}")" "/etc/dehydrated/certs/$HOST" "/etc/letsencrypt/live/$HOST"; do
	[ -f "$d/fullchain.pem" ] && [ -f "$d/privkey.pem" ] || continue
	openssl x509 -in "$d/fullchain.pem" -noout -checkhost "$HOST" 2>/dev/null | grep -q 'does match' || continue
	CERT_DIR="$d"; break
done
[ -n "$CERT_DIR" ] || log "no certificate for $HOST: TLS ($TLS_PORT) disabled"

# 3. secret (only when missing) and settings in /etc/ringq/.env
mkdir -p /etc/ringq
touch "$ENV"
set_env() { # set_env NAME VALUE
	if grep -q "^$1=" "$ENV"; then
		grep -qx "$1=$2" "$ENV" && return 0
		backup "$ENV"; sed -i "s|^$1=.*|$1=$2|" "$ENV"
	else
		backup "$ENV"; echo "$1=$2" >> "$ENV"
	fi
	log ".env: $1 set"
}
if ! grep -q '^TURN_SECRET=.\{40,\}' "$ENV"; then
	backup "$ENV"; echo "TURN_SECRET=$(openssl rand -hex 32)" >> "$ENV"; log ".env: TURN_SECRET created"
fi
set_env TURN_HOST "$HOST"
set_env TURN_PORT "$PORT"
set_env TURNS_PORT "$TLS_PORT"
set_env TURN_MIN_PORT "$MIN_PORT"
set_env TURN_MAX_PORT "$MAX_PORT"
SECRET="$(sed -n 's/^TURN_SECRET=//p' "$ENV" | tail -1)"

# 4. certificate copy coturn (user turnserver) can read, re-synced every 6 h after renewals
if [ -n "$CERT_DIR" ]; then
	cat > "$SYNC.new" <<EOF
#!/bin/sh
# RingQ TURN: copy the web certificate where coturn can read it; reload coturn only when it changed.
SRC=$CERT_DIR
DST=$CERTS
[ -f "\$SRC/fullchain.pem" ] && [ -f "\$SRC/privkey.pem" ] || exit 0
mkdir -p \$DST
changed=0
for f in fullchain.pem privkey.pem; do
	if ! cmp -s "\$SRC/\$f" "\$DST/\$f"; then
		install -o root -g turnserver -m 0640 "\$SRC/\$f" "\$DST/\$f" && changed=1
	fi
done
if [ "\$changed" = 1 ] && systemctl is-active -q coturn; then
	systemctl kill -s USR2 coturn
	echo "\$(date "+%F %T") certificate updated, coturn reloaded" >> $LOG
fi
exit 0
EOF
	if ! cmp -s "$SYNC.new" "$SYNC"; then install -m 0755 "$SYNC.new" "$SYNC"; log "cert sync installed ($CERT_DIR)"; fi
	rm -f "$SYNC.new"
	echo "23 */6 * * * root $SYNC" > /etc/cron.d/ringq-turn-cert
	"$SYNC"
fi

# 5. coturn config
TMP="$(mktemp)"
cat > "$TMP" <<EOF
# RingQ TURN relay. Written by ringq-turn-setup.sh; edits are replaced on the next update.
listening-ip=$IP
relay-ip=$IP
external-ip=$EXTERNAL_IP
listening-port=$PORT
tls-listening-port=$TLS_PORT
min-port=$MIN_PORT
max-port=$MAX_PORT
realm=$HOST
server-name=$HOST
use-auth-secret
static-auth-secret=$SECRET
fingerprint
stale-nonce=600
EOF
if [ -n "$CERT_DIR" ]; then
	printf 'cert=%s/fullchain.pem\npkey=%s/privkey.pem\nno-tlsv1\nno-tlsv1_1\n' "$CERTS" "$CERTS" >> "$TMP"
else
	echo "no-tls" >> "$TMP"
fi
cat >> "$TMP" <<EOF
no-dtls
no-cli
no-multicast-peers
no-tcp-relay
# media is relayed to this server only (its own address and, behind NAT, its public one)
denied-peer-ip=0.0.0.0-255.255.255.255
denied-peer-ip=::-ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
allowed-peer-ip=$IP
EOF
[ "$PUBLIC_IP" != "$IP" ] && echo "allowed-peer-ip=$PUBLIC_IP" >> "$TMP"
cat >> "$TMP" <<EOF
user-quota=12
total-quota=1200
max-allocate-lifetime=3600
log-file=/var/log/turnserver.log
simple-log
EOF
conf_changed=0
if ! cmp -s "$TMP" "$CONF"; then
	backup "$CONF"
	install -o root -g turnserver -m 0640 "$TMP" "$CONF"
	conf_changed=1
	log "turnserver.conf written (host $HOST, port $PORT, tls $TLS_PORT, relay $MIN_PORT-$MAX_PORT, ip $EXTERNAL_IP)"
fi
rm -f "$TMP"
touch /var/log/turnserver.log; chown turnserver:turnserver /var/log/turnserver.log; chmod 0640 /var/log/turnserver.log
cat > /etc/logrotate.d/turnserver <<'EOF'
/var/log/turnserver.log {
	weekly
	rotate 4
	compress
	delaycompress
	missingok
	notifempty
	copytruncate
	su turnserver turnserver
}
EOF

# 6. firewall (iptables, live and persisted in rules.v4)
fw_rules() {
	echo "-p udp -m udp --dport $PORT -m comment --comment ringq-turn -j ACCEPT"
	echo "-p tcp -m tcp --dport $PORT -m comment --comment ringq-turn -j ACCEPT"
	[ -n "$CERT_DIR" ] && echo "-p tcp -m tcp --dport $TLS_PORT -m comment --comment ringq-turn -j ACCEPT"
	echo "-p udp -m udp --dport $MIN_PORT:$MAX_PORT -m comment --comment ringq-turn -j ACCEPT"
}
if command -v iptables >/dev/null 2>&1; then
	fw_rules | while read -r r; do
		# shellcheck disable=SC2086
		iptables -C INPUT $r 2>/dev/null || { iptables -A INPUT $r && log "iptables: INPUT $r"; }
	done
	if [ -f "$RULES" ]; then
		missing="$(fw_rules | while read -r r; do grep -qxF -- "-A INPUT $r" "$RULES" || echo "-A INPUT $r"; done)"
		if [ -n "$missing" ]; then
			backup "$RULES"
			MISSING="$missing" perl -0pi -e 's/(\*filter\n.*?)(\nCOMMIT\n)/$1\n$ENV{MISSING}$2/s' "$RULES"
			log "rules.v4: ringq-turn rules persisted"
		fi
	fi
fi

# 7. run it
systemctl enable -q coturn 2>>"$LOG"
if [ "$conf_changed" = 1 ] || ! systemctl is-active -q coturn; then
	systemctl restart coturn 2>>"$LOG"
	sleep 1
fi
systemctl is-active -q coturn && log "coturn running" || log "coturn NOT running (journalctl -u coturn)"

# 8. nginx: /api/turn on :8443 goes to node (turnCredentials.js), not PHP
if [ -f "$NGINX_SITE" ] && ! grep -q 'location = /api/turn' "$NGINX_SITE"; then
	backup "$NGINX_SITE"
	cp -p "$NGINX_SITE" "$NGINX_SITE.ringq-turn.tmp"
	SNIPPET="$HERE/nginx-turn-location.conf" perl -0pi -e 'BEGIN{ local $/; open F,"<",$ENV{SNIPPET} or die; $s=<F>; close F } s/(listen 8443 ssl[^\n]*\n.*?)(\n[ \t]*location \/ \{)/$1\n$s$2/s or die "8443 block not found\n"' "$NGINX_SITE" 2>>"$LOG"
	if grep -q 'location = /api/turn' "$NGINX_SITE" && nginx -t >>"$LOG" 2>&1; then
		systemctl reload nginx && log "nginx: /api/turn added on :8443"
	else
		cp -p "$NGINX_SITE.ringq-turn.tmp" "$NGINX_SITE"
		log "nginx: /api/turn NOT added (8443 block not recognised or nginx -t failed), site restored"
	fi
	rm -f "$NGINX_SITE.ringq-turn.tmp"
fi

exit 0
