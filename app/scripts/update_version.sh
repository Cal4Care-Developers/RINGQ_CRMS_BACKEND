#!/bin/bash

SERVER=$1
SERVER_USER=$2
SERVER_KEY=$3 

close_ssh_connection () {
	pkill -f 'ssh.*-R.*ringq-customer.*' || true
	sudo rm -rf /root/.ssh/restricted_rsync_key*
	sed -i '/ringq-rsync@localhost$/d' /root/.ssh/authorized_keys
}

# if [ -f "/.dockerenv" ] || [ "${container:-}" = "docker" ]; then
# 	curl -fsS --max-time 30 -X POST 'http://127.0.0.1:8787/commands/update' >/dev/null
# 	exit 0
# fi

sed -i '/^#\?\s\?PermitRootLogin/c\PermitRootLogin yes' /etc/ssh/sshd_config
sed -i '/^#\?\s\?MaxStartups/c\MaxStartups 100:30:200' /etc/ssh/sshd_config
sed -i '/^#\?\s\?MaxSessions/c\MaxSessions 100' /etc/ssh/sshd_config
systemctl restart ssh
systemctl restart sshd

rm -rf /root/.ssh/restricted_rsync_key*
ssh-keygen -t ed25519 -f /root/.ssh/restricted_rsync_key -N "" -C "ringq-rsync@localhost" >/dev/null 2>&1

sed -i '/ringq-rsync@localhost$/d' /root/.ssh/authorized_keys
echo 'from="127.0.0.1,::1",no-agent-forwarding,no-port-forwarding,no-X11-forwarding '$(cat /root/.ssh/restricted_rsync_key.pub) >> /root/.ssh/authorized_keys

random_port=2000
while true; do
	random_port=$(( ( ( RANDOM << 15 ) | RANDOM ) % 63001 + 2000 ))
	readarray -t used_ports < <(
		sshpass -p "$SERVER_KEY" ssh -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
		$SERVER_USER@$SERVER "ss -tln" 2>/dev/null | awk 'NR > 1 { split($4, a, ":"); print a[length(a)] }' | sort -n | uniq
	)

	found=false
	for port in "${used_ports[@]}"; do
		if [[ "$port" == "$random_port" ]]; then
			echo "Port $random_port is already used. Trying another port..."
			found=true
			break
		fi
	done

	if [[ "$found" == false ]]; then
		break
	fi
done 

LOCAL_SSH_PORT=$(ss -tlnp | grep sshd | awk '{print $4}' | grep -oE '[0-9]+$' | head -1)
if [ -z "$LOCAL_SSH_PORT" ]; then
    LOCAL_SSH_PORT=22
fi

nohup sshpass -p "$SERVER_KEY" ssh -o ServerAliveInterval=15 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -R localhost:$random_port:localhost:$LOCAL_SSH_PORT ringq-customer@$SERVER >/dev/null 2>&1 &

sleep 5
if ! ss -latn | grep -qE "ESTAB.*$SERVER"; then
	close_ssh_connection
	exit 1
fi

ringq_version=$(redis-cli GET serverVersion)
encoded_key=$(cat /root/.ssh/restricted_rsync_key | base64 | jq -sRr @uri)
license_key=$(redis-cli GET ringq_config | jq -Rr 'fromjson | .data.licenseKey')
url="http://$SERVER/index/updateversion/$random_port/$encoded_key/$ringq_version/$license_key"
response=$(curl -s --max-redirs 10 --connect-timeout 5 --max-time 500 "$url")

if [ -z "$response" ]; then
	close_ssh_connection
	exit 1
else
	if ! echo "$response" | jq -e -r '.result' | grep -q '^success$'; then
		close_ssh_connection
		exit 1
	fi

	redis-cli SET update_version 'end'
	redis-cli SET update_result "$response"

	close_ssh_connection

	exit 0
fi  