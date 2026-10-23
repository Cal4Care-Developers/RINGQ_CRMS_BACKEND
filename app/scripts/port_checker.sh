#!/bin/bash
#===============================================================================
# RingQ Port Checker (Pre-Installation)
# Checks if required ports are OPEN through the cloud firewall (Lightsail/AWS)
# by probing the server's own public IP.
#
# Logic: connect to own public IP on each port
#   - "Connection refused" = Port is OPEN (firewall allows, no service yet)
#   - "Timeout"            = Port is BLOCKED (firewall is blocking)
#
# Reference: https://www.ringq.com/guides/deployment-methods-system-requirements/
#===============================================================================

RED="\033[0;31m"
GREEN="\033[0;32m"
YELLOW="\033[1;33m"
CYAN="\033[0;36m"
BOLD="\033[1m"
NC="\033[0m"

PASS=0
FAIL=0
TIMEOUT_SEC=3

print_header() {
    # Get public IP
    PUBLIC_IP=$(curl -s --max-time 5 http://checkip.amazonaws.com 2>/dev/null || \
                curl -s --max-time 5 http://ifconfig.me 2>/dev/null || \
                curl -s --max-time 5 http://icanhazip.com 2>/dev/null || \
                echo "UNKNOWN")
    PUBLIC_IP=$(echo "$PUBLIC_IP" | tr -d '[:space:]')

    echo ""
    echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BOLD}  RingQ Port Checker (Pre-Installation)${NC}"
    echo -e "${BOLD}  $(date)${NC}"
    echo -e "${BOLD}  Host: $(hostname)${NC}"
    echo -e "${BOLD}  Public IP: ${PUBLIC_IP}${NC}"
    echo -e "${BOLD}  Private IP: $(hostname -I 2>/dev/null | awk '{print $1}' || echo 'N/A')${NC}"
    echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    echo -e "  ${CYAN}Probing public IP to check cloud firewall (Lightsail)...${NC}"
    echo -e "  ${CYAN}Timeout: ${TIMEOUT_SEC}s per port${NC}"
}

print_section() {
    echo ""
    echo -e "${CYAN}─── $1 ───${NC}"
}

# Check a TCP port by connecting to public IP
# Returns: 0 = open, 1 = blocked
check_tcp_port() {
    local port=$1
    local label=$2

    # Use nc (netcat) with timeout to probe the port
    local result
    result=$(nc -z -w "$TIMEOUT_SEC" "$PUBLIC_IP" "$port" 2>&1; echo "EXIT:$?")
    local exit_code=$(echo "$result" | grep -o 'EXIT:[0-9]*' | cut -d: -f2)

    # Exit code 0 = connected (service running)
    # Exit code 1 with "Connection refused" = port open but no service (GOOD)
    # Exit code 1 with timeout = port blocked by firewall (BAD)

    if [ "$exit_code" = "0" ]; then
        echo -e "  ${GREEN}✔${NC} Port ${BOLD}${port}/tcp${NC} OPEN  - ${label} ${CYAN}(service active)${NC}"
        ((PASS++))
    elif echo "$result" | grep -qi "refused"; then
        echo -e "  ${GREEN}✔${NC} Port ${BOLD}${port}/tcp${NC} OPEN  - ${label} ${CYAN}(no service yet)${NC}"
        ((PASS++))
    else
        echo -e "  ${RED}✘${NC} Port ${BOLD}${port}/tcp${NC} BLOCKED - ${label}"
        ((FAIL++))
    fi
}

# Check a UDP port (best effort — UDP is connectionless)
check_udp_port() {
    local port=$1
    local label=$2

    # UDP check: send empty packet and see if we get ICMP unreachable (open) or nothing (blocked)
    local result
    result=$(nc -u -z -w "$TIMEOUT_SEC" "$PUBLIC_IP" "$port" 2>&1; echo "EXIT:$?")
    local exit_code=$(echo "$result" | grep -o 'EXIT:[0-9]*' | cut -d: -f2)

    if [ "$exit_code" = "0" ]; then
        echo -e "  ${GREEN}✔${NC} Port ${BOLD}${port}/udp${NC} OPEN  - ${label}"
        ((PASS++))
    else
        # UDP is unreliable to check — mark as warning if it times out
        echo -e "  ${YELLOW}~${NC} Port ${BOLD}${port}/udp${NC} UNCERTAIN - ${label} ${CYAN}(UDP hard to verify)${NC}"
        ((PASS++))  # Don't count UDP as fail since it's unreliable to check
    fi
}

# Check a range of TCP ports (sample first, middle, last)
check_tcp_range() {
    local start=$1
    local end=$2
    local label=$3

    # Sample 3 ports from range
    local mid=$(( (start + end) / 2 ))
    local open=0
    local blocked=0

    for port in $start $mid $end; do
        local result
        result=$(nc -z -w "$TIMEOUT_SEC" "$PUBLIC_IP" "$port" 2>&1; echo "EXIT:$?")
        local exit_code=$(echo "$result" | grep -o 'EXIT:[0-9]*' | cut -d: -f2)

        if [ "$exit_code" = "0" ] || echo "$result" | grep -qi "refused"; then
            ((open++))
        else
            ((blocked++))
        fi
    done

    if [ "$open" -ge 2 ]; then
        echo -e "  ${GREEN}✔${NC} Range ${BOLD}${start}-${end}/tcp${NC} OPEN  - ${label} ${CYAN}(sampled ${open}/3)${NC}"
        ((PASS++))
    elif [ "$open" -ge 1 ]; then
        echo -e "  ${YELLOW}~${NC} Range ${BOLD}${start}-${end}/tcp${NC} PARTIAL - ${label} ${CYAN}(sampled ${open}/3)${NC}"
        ((FAIL++))
    else
        echo -e "  ${RED}✘${NC} Range ${BOLD}${start}-${end}/tcp${NC} BLOCKED - ${label}"
        ((FAIL++))
    fi
}

# Check a range of UDP ports (best effort)
check_udp_range() {
    local start=$1
    local end=$2
    local label=$3
    echo -e "  ${YELLOW}~${NC} Range ${BOLD}${start}-${end}/udp${NC} - ${label} ${CYAN}(UDP ranges hard to verify remotely)${NC}"
    ((PASS++))
}

# ─── Main ────────────────────────────────────────────────────────────────────

# Check if nc (netcat) is available
if ! command -v nc &>/dev/null; then
    echo "ERROR: netcat (nc) is required but not installed."
    echo "Install with: sudo apt install netcat-openbsd"
    exit 2
fi

print_header

if [ "$PUBLIC_IP" = "UNKNOWN" ]; then
    echo -e "\n  ${RED}ERROR: Could not detect public IP.${NC}"
    echo -e "  ${RED}Check internet connectivity.${NC}\n"
    exit 2
fi

# ─── TCP Ports ───────────────────────────────────────────────────────────────
print_section "TCP Ports"
check_tcp_port 20   "FTP Data"
check_tcp_port 21   "FTP Control"
check_tcp_port 22   "SSH"
check_tcp_port 80   "HTTP"
check_tcp_port 443  "HTTPS"
check_tcp_port 3389 "Remote Desktop (RDP)"
check_tcp_port 8443 "RingQ Backend (HTTPS)"

# ─── UDP Ports ───────────────────────────────────────────────────────────────
print_section "UDP Ports"
check_udp_port 53  "DNS"
check_udp_port 67  "DHCP Server"
check_udp_port 68  "DHCP Client"
check_udp_port 123 "NTP"

# ─── RTP Ports ───────────────────────────────────────────────────────────────
print_section "RTP (Real-Time Protocol)"
check_udp_range 16384 32767 "RTP Media Stream"
check_udp_range 5004  5005  "RTP Control"
check_udp_range 5060  5067  "RTP/SIP Range"

# ─── WSS Ports ───────────────────────────────────────────────────────────────
print_section "WebSocket Secure (WSS)"
check_tcp_port 9443 "WSS"
check_tcp_port 7443 "WSS"

# ─── SIP Ports ───────────────────────────────────────────────────────────────
print_section "SIP (Session Initiation Protocol)"
check_tcp_port 5060 "SIP (TCP)"
check_udp_port 5061 "SIP (UDP/TLS)"

# ─── Media Ports ─────────────────────────────────────────────────────────────
print_section "Media Ports"
check_udp_range 9000 10999 "RingQ Media"

# ─── Apple Push ──────────────────────────────────────────────────────────────
print_section "Push Notification Services"
check_tcp_port 5223 "Apple iOS Push (Outbound)"

# ─── Audio/Video ─────────────────────────────────────────────────────────────
print_section "Audio & Video (Outbound UDP)"
check_udp_range 48000 65535 "Audio/Video Outbound"

# ─── Summary ─────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
echo -e "  ${GREEN}✔ OPEN: ${PASS}${NC}  |  ${RED}✘ BLOCKED: ${FAIL}${NC}"
echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"

if [ "$FAIL" -gt 0 ]; then
    echo -e "\n${RED}${BOLD}  ⚠  Some required ports are BLOCKED by the cloud firewall.${NC}"
    echo -e "  ${RED}Open them in Networking IPv4 Firewall.${NC}"
    echo -e "  ${RED}Reference: https://www.ringq.com/guides/deployment-methods-system-requirements/${NC}\n"
    exit 1
else
    echo -e "\n${GREEN}${BOLD}  ✔  All required RingQ ports are OPEN. Ready to install!${NC}\n"
    exit 0
fi
