#!/bin/bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/root/removed-pcapsipdump-$(date +%Y%m%d%H%M%S)}"
PURGE_PCAP_DATA="${PURGE_PCAP_DATA:-0}"
JOBS_SH="${JOBS_SH:-/var/www/ringq/app/scripts/jobs.sh}"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this script as root." >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"

log() {
    echo "[remove-pcapsipdump] $*"
}

backup_path() {
    local path="$1"
    local dest

    dest="$BACKUP_DIR$(dirname "$path")"
    mkdir -p "$dest"
    mv "$path" "$dest/"
    log "moved $path"
}

remove_path() {
    local path="$1"

    if [ -e "$path" ] || [ -L "$path" ]; then
        backup_path "$path"
    fi
}

stop_units() {
    log "stopping and disabling capture services"
    systemctl stop sip-pcap.service pcapsipdump.service 2>/dev/null || true
    systemctl disable sip-pcap.service pcapsipdump.service 2>/dev/null || true

    if command -v update-rc.d >/dev/null 2>&1; then
        update-rc.d -f pcapsipdump remove 2>/dev/null || true
    fi
}

stop_capture_processes() {
    log "stopping remaining capture processes"

    pkill -x pcapsipdump 2>/dev/null || true

    ps -eo pid=,comm=,args= | while read -r pid comm args; do
        case "$comm:$args" in
            bash:*/usr/local/bin/sip-pcap-daemon.sh*|sh:*/usr/local/bin/sip-pcap-daemon.sh*)
                kill "$pid" 2>/dev/null || true
                ;;
            tcpdump:*sip-ring*|tcpdump:*5060*|tcpdump:*5080*|tcpdump:*5061*|tcpdump:*16384-32768*)
                kill "$pid" 2>/dev/null || true
                ;;
        esac
    done
}

remove_setup_files() {
    log "removing active service files, scripts, and binaries"

    remove_path /etc/systemd/system/sip-pcap.service
    remove_path /etc/systemd/system/pcapsipdump.service
    remove_path /etc/init.d/pcapsipdump
    remove_path /etc/default/pcapsipdump
    remove_path /etc/tmpfiles.d/ringq-pcap.conf
    remove_path /etc/tmpfiles.d/sip-pcap.conf

    rm -f /etc/systemd/system/multi-user.target.wants/sip-pcap.service
    rm -f /etc/systemd/system/multi-user.target.wants/pcapsipdump.service
    rm -f /etc/rc0.d/*pcapsipdump /etc/rc1.d/*pcapsipdump /etc/rc2.d/*pcapsipdump
    rm -f /etc/rc3.d/*pcapsipdump /etc/rc4.d/*pcapsipdump /etc/rc5.d/*pcapsipdump
    rm -f /etc/rc6.d/*pcapsipdump

    remove_path /usr/sbin/pcapsipdump
    remove_path /usr/local/sbin/pcapsipdump
    remove_path /usr/bin/pcapsipdump
    remove_path /usr/local/bin/pcapsipdump
    remove_path /usr/local/bin/sip-pcap-daemon.sh

    remove_path /var/www/ringq/app/scripts/sip-pcap-daemon.sh
    remove_path /var/www/ringq/app/service/sip-pcap.service
    remove_path /var/www/ringq/app/scripts/pcapsipdump.sh

    rm -f /var/www/ringq/cache/pcapsipdump

    systemctl daemon-reload
    systemctl reset-failed sip-pcap.service pcapsipdump.service 2>/dev/null || true
}

disable_jobs_setup() {
    if [ ! -f "$JOBS_SH" ]; then
        log "jobs.sh not found at $JOBS_SH; skipping jobs patch"
        return 0
    fi

    if ! grep -q 'SIP_PCAP_DST' "$JOBS_SH"; then
        log "jobs.sh has no SIP PCAP setup block; skipping jobs patch"
        return 0
    fi

    if grep -q 'sip-pcap/pcapsipdump setup disabled on this server' "$JOBS_SH"; then
        log "jobs.sh setup block is already disabled"
        return 0
    fi

    local backup tmp
    backup="${JOBS_SH}.bak-remove-pcapsipdump-$(date +%Y%m%d%H%M%S)"
    tmp="$(mktemp)"

    cp "$JOBS_SH" "$backup"

    awk '
        /^[[:space:]]*if \[ -x "\$SIP_PCAP_DST" \]/ {
            print "        echo \"sip-pcap/pcapsipdump setup disabled on this server.\""
            print "        systemctl stop sip-pcap pcapsipdump 2>/dev/null || true"
            print "        systemctl disable sip-pcap pcapsipdump 2>/dev/null || true"
            print "        rm -f \"$SIP_UNIT_DST\" /etc/systemd/system/pcapsipdump.service"
            print "        rm -f \"$SIP_PCAP_DST\" /usr/sbin/pcapsipdump"
            print "        systemctl daemon-reload"
            skip = 1
            depth = 1
            changed = 1
            next
        }

        skip {
            if ($0 ~ /^[[:space:]]*if[[:space:]]/) {
                depth++
            }
            if ($0 ~ /^[[:space:]]*fi[[:space:]]*$/) {
                depth--
                if (depth == 0) {
                    skip = 0
                }
            }
            next
        }

        { print }

        END {
            if (!changed) {
                exit 2
            }
        }
    ' "$JOBS_SH" > "$tmp" || {
        rm -f "$tmp"
        log "could not patch jobs.sh automatically; backup kept at $backup"
        return 0
    }

    if bash -n "$tmp"; then
        cat "$tmp" > "$JOBS_SH"
        log "disabled SIP PCAP setup block in $JOBS_SH"
        log "jobs.sh backup: $backup"
    else
        rm -f "$tmp"
        log "patched jobs.sh failed syntax check; original left unchanged at $JOBS_SH"
        log "jobs.sh backup: $backup"
        return 1
    fi

    rm -f "$tmp"
}

purge_pcap_data() {
    if [ "$PURGE_PCAP_DATA" != "1" ]; then
        log "preserving captured data; set PURGE_PCAP_DATA=1 to remove legacy pcap stores"
        return 0
    fi

    log "purging legacy pcap data"
    rm -rf /var/spool/pcapsipdump /tmp/sip-ring
    rm -f /tmp/wireshark_*.pcapng
}

print_status() {
    log "status"
    systemctl list-unit-files --no-pager | awk '/pcapsipdump|sip-pcap|sipdump/ {print}' || true
    systemctl list-units --all --no-pager | awk '/pcapsipdump|sip-pcap|sipdump/ {print}' || true

    if pgrep -x pcapsipdump >/dev/null 2>&1; then
        pgrep -a -x pcapsipdump
    else
        log "no pcapsipdump process"
    fi

    if pgrep -x tcpdump >/dev/null 2>&1; then
        pgrep -a -x tcpdump
    else
        log "no tcpdump process"
    fi

    log "backup directory: $BACKUP_DIR"
}

stop_units
stop_capture_processes
remove_setup_files
disable_jobs_setup
purge_pcap_data
print_status

log "pcapsipdump setup removal complete"
