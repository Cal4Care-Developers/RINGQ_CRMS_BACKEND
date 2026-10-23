#!/bin/bash
# RingQ Cloud PBX -- NX Device Tunnel Setup
# Each rule: delete if exists (any IP), then recreate fresh from current FS IP.
# This handles FS IP changes correctly.

set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "Run as root: sudo $0"; exit 1; }

echo "=== RingQ Tunnel Setup ==="
echo ""

# ── Detect FreeSWITCH internal IP ────────────────────────────────────────────
FS_INTERNAL_IP=$(fs_cli -x 'sofia status' 2>/dev/null \
    | grep -oP '\d+\.\d+\.\d+\.\d+' | head -1 || echo "127.0.0.1")
echo "FreeSWITCH internal IP detected: ${FS_INTERNAL_IP}"
echo ""

# ── Helpers ───────────────────────────────────────────────────────────────────

# Delete ALL rules in a chain that match a given destination port.
# Uses line-number deletion (sorted reverse) so indices don't shift mid-loop.
# Works for both filter table (default) and nat table.
ipt_clear_by_dport() {
    local table="$1"   # "filter" or "nat"
    local chain="$2"   # INPUT / FORWARD / PREROUTING
    local dport="$3"   # port number to match
    local tbl_flag=""
    [[ "$table" == "nat" ]] && tbl_flag="-t nat"
    local lines
    # `|| true`: with no matching rules grep exits 1, and under pipefail the
    # failed assignment would kill the whole script before the rules are added.
    lines=$(iptables $tbl_flag -L "$chain" --line-numbers -n 2>/dev/null \
        | grep "dpt:${dport}" | awk '{print $1}' | sort -rn) || true
    if [[ -n "$lines" ]]; then
        for line in $lines; do
            iptables $tbl_flag -D "$chain" "$line" 2>/dev/null || true
        done
        echo "  [DELETED] existing ${chain} rules for port ${dport}"
    fi
}

# Delete exact INPUT rule by full rule spec (for rules without IP in them).
ipt_clear_exact() {
    local rule_args=("$@")
    while iptables -C "${rule_args[@]}" 2>/dev/null; do
        iptables -D "${rule_args[@]}" 2>/dev/null || break
    done
}

# ── Rule 1: INPUT tcp/6010 (no IP -- simple exact delete + add) ───────────────
echo "--- Rule 1: INPUT tcp/6010 ---"
if iptables -C INPUT -p tcp --dport 6010 -j ACCEPT 2>/dev/null; then
    iptables -D INPUT -p tcp --dport 6010 -j ACCEPT
    iptables -A INPUT -p tcp --dport 6010 -j ACCEPT
    echo "  [UPDATED] INPUT tcp/6010 (deleted and recreated)"
else
    iptables -A INPUT -p tcp --dport 6010 -j ACCEPT
    echo "  [ADDED]   INPUT tcp/6010 (new rule)"
fi

# ── Rule 2: DNAT tcp/6010 -> FS_IP:5060 (clears ALL port-6010 DNAT rules) ────
# Must clear by port (not exact match) so stale rules pointing to an OLD FS IP
# are also removed when FreeSWITCH moves to a different internal address.
echo "--- Rule 2: DNAT tcp/6010 -> ${FS_INTERNAL_IP}:5060 ---"
ipt_clear_by_dport nat PREROUTING 6010
iptables -t nat -A PREROUTING -p tcp --dport 6010 \
    -j DNAT --to-destination "${FS_INTERNAL_IP}:5060"
echo "  [ADDED]   DNAT tcp/6010 -> ${FS_INTERNAL_IP}:5060"

# ── Rule 3: FORWARD tcp -> FS_IP:5060 (clears ALL port-5060 FORWARD rules) ───
# Same reason: clear by port so old FS IP entries don't remain.
echo "--- Rule 3: FORWARD tcp -> ${FS_INTERNAL_IP}:5060 ---"
ipt_clear_by_dport filter FORWARD 5060
iptables -A FORWARD -p tcp -d "${FS_INTERNAL_IP}" --dport 5060 -j ACCEPT
echo "  [ADDED]   FORWARD tcp -> ${FS_INTERNAL_IP}:5060"

# ── Persist ───────────────────────────────────────────────────────────────────
echo ""
mkdir -p /etc/iptables
iptables-save > /etc/iptables/rules.v4
echo "  [SAVED]   Rules persisted to /etc/iptables/rules.v4"
echo ""
echo "=== Done. FreeSWITCH IP: ${FS_INTERNAL_IP} ==="
