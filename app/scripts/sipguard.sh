#!/bin/bash
set -e

JAIL_FILE="/etc/fail2ban/jail.local"

[ -f "$JAIL_FILE" ] || { echo "sipguard: $JAIL_FILE not found, nothing to do."; exit 0; }

sip_guard_enable=$(redis-cli get sip-guard-enabled)
sip_firewall_rate=$(redis-cli get sip-firewall-rate)
sip_firewall_duration=$(redis-cli get sip-firewall-duration)

# Ports a SIP offence is allowed to cost the offender. Must stay in step with
# F2B_SIP_PORTS in jobs.sh; deliberately excludes 80/443/22 so a SIP ban can
# never remove the CloudApp or SSH.
SIP_PORTS='5060,5061,5080,5081'

# Delete one INI section without disturbing its neighbours.
#
# The previous implementation used:
#     sed -i "/^\[$section\]/,/^\[.*\]/d"
# A sed address range ends on the NEXT line matching the end pattern and deletes
# that line too, so this removed the target section AND the following section's
# [header]. The orphaned body then parsed as duplicate keys in whatever section
# preceded it, which is exactly the "duplicate option 'enabled' in section
# [sip-auth-failure]" failure recorded in docs/fail2ban_issue.md — fail2ban
# refuses to start and every jail is off until somebody edits the file by hand.
delete_section() {
    local section="$1" file="$2" tmp
    grep -q "^\[$section\]" "$file" || return 0
    echo "Removing [$section] section..."
    tmp=$(mktemp)
    awk -v want="[$section]" '
        # A header line either opens the section we are dropping or ends it.
        /^\[/ { drop = ($0 == want) ? 1 : 0 }
        !drop { print }
    ' "$file" > "$tmp"
    cat "$tmp" > "$file"
    rm -f "$tmp"
}

for section in fusionpbx-404 fusionpbx fusionpbx-mac; do
    delete_section "$section" "$JAIL_FILE"
done

if [ -n "$sip_guard_enable" ]; then
    echo "Key exists, value = $sip_guard_enable"

    if [ "$sip_guard_enable" = "1" ]; then
        enable_value="true"
    else
        enable_value="false"
    fi

    if grep -q "^\[sip-invite\]" "$JAIL_FILE"; then
        echo "Updating existing [sip-invite] section..."
        # Bound every edit to the [sip-invite] section: an unbounded range would
        # run past the next header and rewrite whatever jail follows.
        awk -v enable="$enable_value" -v rate="$sip_firewall_rate" -v dur="$sip_firewall_duration" '
            /^\[/ { inside = ($0 == "[sip-invite]") ? 1 : 0 }
            inside && /^enabled/  { print "enabled  = " enable; next }
            inside && /^maxretry/ { print "maxretry = " rate;   next }
            inside && /^bantime/  { print "bantime  = " dur;    next }
            { print }
        ' "$JAIL_FILE" > "$JAIL_FILE.sipguard.tmp"
        cat "$JAIL_FILE.sipguard.tmp" > "$JAIL_FILE"
        rm -f "$JAIL_FILE.sipguard.tmp"
    else
        echo "Appending new [sip-invite] section..."
        # Port-scoped, matching what jobs.sh writes. This section used to be
        # appended with iptables-allports, which meant enabling SIP Guard also
        # made every SIP ban cost the offender HTTPS and SSH.
        cat >> "$JAIL_FILE" <<EOF

[sip-invite]
enabled  = $enable_value
filter   = sip-invite
logpath  = /var/log/freeswitch/freeswitch.log
port     = $SIP_PORTS
maxretry = $sip_firewall_rate
findtime = 60
bantime  = $sip_firewall_duration
action = ringq-sip-ports[name=SIPInvite, port="$SIP_PORTS"]
EOF
    fi

    fail2ban-client reload
fi
