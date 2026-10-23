#!/bin/bash
# =============================================================================
#  sip-pcap-daemon.sh  —  Full SIP dialog capture, sngrep-complete
#
#  PROBLEM SOLVED:
#    The old design spawned dumpcap AFTER seeing an INVITE from tshark.
#    That loses INVITE + 100 Trying + 180 Ringing (1-2s race window).
#
#  HOW THIS WORKS:
#    Stage 1 — Ring-buffer capture (always running)
#      dumpcap writes a rolling set of small pcap files covering all SIP+RTP
#      traffic at all times.  Nothing is ever lost — the INVITE is already
#      on disk before tshark even sees it.
#
#    Stage 2 — SIP event monitor (always running, parallel)
#      tshark watches SIP traffic and fires callbacks:
#        INVITE  → record call start timestamp + hosts
#        BYE/CANCEL/4xx/5xx/6xx → wait 2s for final 200 OK, then extract
#
#    Stage 3 — Per-call extraction (on BYE/end)
#      tshark merges all ring-buffer files that overlap the call window,
#      filters by Call-ID (SIP) + host-pair UDP (RTP), writes final pcap.
#      Ring files are NOT deleted — they roll themselves.
#
#  REQUIREMENTS:
#    tshark, dumpcap  (wireshark-common / tshark package)
#    mergecap         (same package)
#    bash 4+
#
#  USAGE:
#    chmod +x sip-pcap-daemon.sh
#    sudo ./sip-pcap-daemon.sh
#
#    To run as a systemd service, see the .service template at the bottom.
# =============================================================================

set -euo pipefail

# ── Configuration ─────────────────────────────────────────────────────────────
FINAL_DIR="/var/call-pcaps"          # per-call pcap destination
RING_DIR="/tmp/sip-ring"             # ring-buffer scratch directory
RING_PREFIX="${RING_DIR}/ring"       # dumpcap file prefix
RING_FILE_SIZE_KB=4096               # each ring file ~4 MB
RING_FILE_COUNT=50                   # keep last ~200 MB of rolling history
                                     # increase if calls are long / traffic high

SIP_PORTS="port 5060 or port 5080 or port 5061"
RTP_PORTS="udp portrange 16384-32768"
CAPTURE_IFACE="any"                  # or eth0, ens3, etc.

BYE_DRAIN_SECS=3                     # wait after BYE before extracting
                                     # (captures the 200 OK to BYE)
# ──────────────────────────────────────────────────────────────────────────────

mkdir -p "$FINAL_DIR" "$RING_DIR"

# Purge orphans from prior daemon runs. dumpcap's rotation only manages files
# from its own current PID, so past runs leave timestamped ring_* files behind.
# Stage 2 tshark (below) has no -w, so dumpcap writes its capture to a default
# /tmp/wireshark_<iface><RAND>.pcapng — also orphaned on restart. Without this
# purge, /tmp accumulated ~1-3 GB/day and filled root, taking Postgres down
# (see project_pg_disk_full_outage memory).
rm -f "${RING_DIR}"/ring_*.pcap 2>/dev/null || true
rm -f /tmp/wireshark_*.pcapng 2>/dev/null || true

# ── Logging helpers ───────────────────────────────────────────────────────────
log()  { echo "[$(date '+%H:%M:%S')] $*"; }
info() { echo "  ├─ $*"; }
ok()   { echo "  └─ ✓ $*"; }
warn() { echo "  └─ ⚠ $*"; }

# ── Cleanup on exit ───────────────────────────────────────────────────────────
RING_PID=""
TSHARK_PID=""
cleanup() {
    log "Shutting down..."
    [[ -n "$RING_PID" ]] && kill "$RING_PID" 2>/dev/null && wait "$RING_PID" 2>/dev/null
    [[ -n "$TSHARK_PID" ]] && kill "$TSHARK_PID" 2>/dev/null
    rm -f /tmp/wireshark_*.pcapng 2>/dev/null || true
    log "Done."
}
trap cleanup EXIT INT TERM

# ── Stage 1: Start always-on ring-buffer capture ─────────────────────────────
log "Starting ring-buffer capture on interface '$CAPTURE_IFACE'"
log "  Ring dir : $RING_DIR"
log "  Ring files: ${RING_FILE_COUNT} × ${RING_FILE_SIZE_KB} KB"
log "  Filter   : (${SIP_PORTS}) or (${RTP_PORTS})"

dumpcap \
    -i "$CAPTURE_IFACE" \
    -s 0 \
    -P \
    -f "(${SIP_PORTS}) or (${RTP_PORTS})" \
    -b filesize:"${RING_FILE_SIZE_KB}" \
    -b files:"${RING_FILE_COUNT}" \
    -w "${RING_PREFIX}.pcap" \
    >/dev/null 2>&1 &

RING_PID=$!
log "Ring-buffer PID: $RING_PID"

# Give dumpcap a moment to bind and start writing
sleep 1

if ! kill -0 "$RING_PID" 2>/dev/null; then
    log "ERROR: dumpcap failed to start. Check permissions (need CAP_NET_RAW or run as root)."
    exit 1
fi

log "Ring-buffer running. Starting SIP event monitor..."
echo ""
echo "════════════════════════════════════════════════════════"
echo "  SIP PCAP CAPTURE DAEMON — ready, all events captured"
echo "════════════════════════════════════════════════════════"
echo ""

# ── Per-call state ────────────────────────────────────────────────────────────
# Bash associative arrays keyed by sanitised Call-ID
declare -A CALL_START_TS    # epoch seconds (float) when INVITE was seen
declare -A CALL_SRCIP
declare -A CALL_DSTIP
declare -A CALL_LOG         # accumulated SIP message log for pretty print

# ── Helper: epoch timestamp from tshark frame.time_epoch ─────────────────────
now_epoch() { date +%s.%N; }

# ── Helper: list ring files that overlap a time window ───────────────────────
#   We check mtime of each ring file — files written after call_start and
#   before (call_end + BYE_DRAIN_SECS + 2) are included.
#   We add all files within ±1 file window of that range to be safe.
ring_files_for_window() {
    local start_epoch="$1"
    local end_epoch="$2"
    # Give a 30-second buffer on each side to account for file rotation timing
    local earliest=$(echo "$start_epoch - 30" | bc)
    local latest=$(echo "$end_epoch + 10" | bc)
    local result=()
    for f in "${RING_DIR}"/ring*.pcap; do
        [[ -f "$f" ]] || continue
        local mtime
        mtime=$(stat -c %Y "$f" 2>/dev/null || echo 0)
        # include files whose mtime falls after earliest (file was being written
        # during or after the call start window)
        if (( $(echo "$mtime >= $earliest" | bc -l) )); then
            result+=("$f")
        fi
    done
    # Always include the newest file in case mtime is slightly off
    local newest
    newest=$(ls -t "${RING_DIR}"/ring*.pcap 2>/dev/null | head -1)
    [[ -n "$newest" ]] && result+=("$newest")
    # Deduplicate and sort
    printf '%s\n' "${result[@]}" | sort -u
}

# ── Helper: pretty-print SIP ladder entry ────────────────────────────────────
ladder_line() {
    local callid="$1" src="$2" dst="$3" method="$4" status="$5" statusline="$6"
    local arrow label
    if [[ -n "$method" ]]; then
        label=">>> $method"
        arrow="→"
    else
        label="<<< $status $statusline"
        arrow="←"
    fi
    printf "  %s [%-20s] %s  (%s %s %s)\n" \
        "$arrow" "${callid:0:20}" "$label" "$src" "$arrow" "$dst"
}

# ── Helper: extract and save final per-call pcap ─────────────────────────────
extract_call() {
    local safe_id="$1"
    local callid="$2"
    local start_epoch="$3"
    local end_epoch="$4"
    local h1="$5"
    local h2="$6"

    local final_pcap="${FINAL_DIR}/${safe_id}.pcap"
    local tmp_merged="${RING_DIR}/merge_${safe_id}.pcap"
    local tmp_extracted="${RING_DIR}/extract_${safe_id}.pcap"

    echo ""
    echo "┌──────────────────────────────────────────────────────"
    echo "│ [EXTRACT] $safe_id"
    echo "│  Call-ID : $callid"
    echo "│  Window  : $(date -d @${start_epoch%.*} '+%H:%M:%S') → $(date -d @${end_epoch%.*} '+%H:%M:%S')"
    echo "│  Hosts   : $h1  ↔  $h2"

    # Collect ring files covering the call window
    local ring_files
    mapfile -t ring_files < <(ring_files_for_window "$start_epoch" "$end_epoch")

    if [[ ${#ring_files[@]} -eq 0 ]]; then
        warn "No ring files found for window. Skipping."
        echo "└──────────────────────────────────────────────────────"
        return
    fi

    info "Ring files used (${#ring_files[@]}): ${ring_files[*]}"

    # Merge ring files into one temporary pcap
    if [[ ${#ring_files[@]} -eq 1 ]]; then
        cp "${ring_files[0]}" "$tmp_merged"
    else
        mergecap -w "$tmp_merged" "${ring_files[@]}" 2>/dev/null || {
            warn "mergecap failed, using newest ring file only"
            cp "${ring_files[-1]}" "$tmp_merged"
        }
    fi

    # Extract: SIP with exact Call-ID  +  host-pair UDP in RTP range
    local disp_filter
    disp_filter="sip.Call-ID == \"${callid}\" or \
(ip.addr == ${h1} and ip.addr == ${h2} and \
 (udp.port >= 16384 and udp.port <= 32768))"

    tshark \
        -r "$tmp_merged" \
        -Y "$disp_filter" \
        -w "$tmp_extracted" \
        >/dev/null 2>&1

    local pkt_count=0
    if [[ -f "$tmp_extracted" ]]; then
        pkt_count=$(tshark -r "$tmp_extracted" 2>/dev/null | wc -l)
    fi

    if [[ "$pkt_count" -gt 0 ]]; then
        mv "$tmp_extracted" "$final_pcap"
        ok "Saved: $final_pcap  ($pkt_count packets)"
    else
        warn "0 packets extracted — check Call-ID or hosts"
        # Save the merged file as fallback so nothing is lost
        mv "$tmp_merged" "$final_pcap"
        warn "Fallback: saved raw merged file as $final_pcap"
    fi

    rm -f "$tmp_merged" "$tmp_extracted" 2>/dev/null

    # Print captured SIP ladder for this call
    if [[ -n "${CALL_LOG[$safe_id]+x}" ]]; then
        echo "│"
        echo "│  SIP Dialog:"
        while IFS= read -r line; do
            echo "│  $line"
        done <<< "${CALL_LOG[$safe_id]}"
    fi

    echo "└──────────────────────────────────────────────────────"
    echo ""
}

# ── Stage 2: SIP event monitor ───────────────────────────────────────────────
# -f restricts the internal dumpcap (which tshark always spawns) to SIP-only.
# Without -f, dumpcap captures everything on the interface and writes it to a
# /tmp/wireshark_<iface><RAND>.pcapng temp file that grows ~1-3 GB/day on a
# busy SBC. The -f filter caps that growth to ≪100 MB/day even before the
# orphan-purge at process start catches up.
tshark \
    -i "$CAPTURE_IFACE" \
    -l \
    -f "$SIP_PORTS" \
    -Y 'sip' \
    -T fields \
    -e frame.time_epoch \
    -e _ws.col.Source \
    -e _ws.col.Destination \
    -e sip.Call-ID \
    -e sip.Method \
    -e sip.Status-Code \
    -e sip.Status-Line \
    -E separator='|' \
    2>/dev/null \
| while IFS='|' read -r TS SRCIP DSTIP CALLID METHOD STATUS_CODE STATUS_LINE; do

    [[ -z "$CALLID" ]] && continue

    # Strip port suffix from IPs
    SRCIP="${SRCIP%%:*}"
    DSTIP="${DSTIP%%:*}"

    # Sanitise Call-ID for use as filename
    SAFE="${CALLID//[^a-zA-Z0-9._@-]/_}"

    # Append to ladder log
    if [[ -n "$METHOD" ]]; then
        LOG_LINE="$(printf '%-8s  %s → %s' "$METHOD" "$SRCIP" "$DSTIP")"
    else
        LOG_LINE="$(printf '%-8s  %s ← %s  [%s %s]' "" "$DSTIP" "$SRCIP" "$STATUS_CODE" "$STATUS_LINE")"
        LOG_LINE="${STATUS_CODE} ${STATUS_LINE}  ${SRCIP} → ${DSTIP}"
    fi
    CALL_LOG[$SAFE]+="${LOG_LINE}"$'\n'

    # ── INVITE: register call start ──────────────────────────────────────────
    if [[ "$METHOD" == "INVITE" ]] && [[ -z "${CALL_START_TS[$SAFE]+x}" ]]; then
        CALL_START_TS[$SAFE]="$TS"
        CALL_SRCIP[$SAFE]="$SRCIP"
        CALL_DSTIP[$SAFE]="$DSTIP"

        echo ""
        echo "┌──────────────────────────────────────────────────────"
        echo "│ [INVITE] $SAFE"
        echo "│  From : $SRCIP  →  To: $DSTIP"
        echo "│  Time : $(date -d @${TS%.*} '+%H:%M:%S.%3N' 2>/dev/null || echo $TS)"
        echo "│  Ring-buffer already capturing — INVITE will be in pcap ✓"
        echo "└──────────────────────────────────────────────────────"
        continue
    fi

    # ── Active call: log every hop (sngrep style) ────────────────────────────
    if [[ -n "${CALL_START_TS[$SAFE]+x}" ]]; then
        if [[ -n "$METHOD" ]]; then
            printf "  ↪ [%-22s]  %-8s  %s → %s\n" "${SAFE:0:22}" "$METHOD" "$SRCIP" "$DSTIP"
        else
            printf "  ↩ [%-22s]  %-3s %-20s  %s → %s\n" \
                "${SAFE:0:22}" "$STATUS_CODE" "${STATUS_LINE:0:20}" "$SRCIP" "$DSTIP"
        fi
    fi

    # ── Termination conditions ───────────────────────────────────────────────
    SHOULD_STOP=0
    STOP_REASON=""

    if [[ "$METHOD" == "BYE" ]] || [[ "$METHOD" == "CANCEL" ]]; then
        SHOULD_STOP=1
        STOP_REASON="$METHOD"
    fi

    if [[ -n "$STATUS_CODE" ]] && [[ -n "${CALL_START_TS[$SAFE]+x}" ]]; then
        if [[ "$STATUS_CODE" =~ ^[456][0-9][0-9]$ ]]; then
            SHOULD_STOP=1
            STOP_REASON="${STATUS_CODE} ${STATUS_LINE}"
        fi
    fi

    if [[ "$SHOULD_STOP" -eq 1 ]] && [[ -n "${CALL_START_TS[$SAFE]+x}" ]]; then
        echo ""
        echo "├─ [ENDING] Reason: $STOP_REASON — draining ${BYE_DRAIN_SECS}s for final 200 OK..."

        sleep "$BYE_DRAIN_SECS"

        END_TS=$(now_epoch)

        H1="${CALL_SRCIP[$SAFE]}"
        H2="${CALL_DSTIP[$SAFE]}"
        START_TS="${CALL_START_TS[$SAFE]}"

        # Run extraction in background so monitor loop stays live
        (
            extract_call \
                "$SAFE" "$CALLID" "$START_TS" "$END_TS" "$H1" "$H2"
        ) &

        # Clean up state
        unset "CALL_START_TS[$SAFE]"
        unset "CALL_SRCIP[$SAFE]"
        unset "CALL_DSTIP[$SAFE]"
        unset "CALL_LOG[$SAFE]"
    fi

done

# ── Systemd service template (printed to stderr for reference) ────────────────
# To install as a service:
#
#   sudo cp sip-pcap-daemon.sh /usr/local/bin/
#   sudo chmod +x /usr/local/bin/sip-pcap-daemon.sh
#
# Create /etc/systemd/system/sip-pcap.service:
#
# [Unit]
# Description=SIP PCAP capture daemon
# After=network.target
#
# [Service]
# Type=simple
# ExecStart=/usr/local/bin/sip-pcap-daemon.sh
# Restart=on-failure
# RestartSec=5
# StandardOutput=journal
# StandardError=journal
#
# [Install]
# WantedBy=multi-user.target
#
# Then: sudo systemctl daemon-reload && sudo systemctl enable --now sip-pcap