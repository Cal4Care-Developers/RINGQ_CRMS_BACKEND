#!/bin/bash
# SIP profiles that do not come back after a certificate renewal or a configuration change.
# A renewal rewrites /etc/freeswitch/tls/all.pem, but a standalone wss.key (or an old copy of
# wss.pem, agent.pem, ...) keeps the previous key; the internal profile then fails with
# "Error Creating SIP UA" on its WSS binding while the external one starts, and nothing starts
# it again. Phones and the webapp show offline although FreeSWITCH is running.
#
#   fs-sip-profiles.sh status [profile...]   one JSON line: the profiles, the TLS files compared
#                                            with all.pem, and this server's IPv4 addresses
#   fs-sip-profiles.sh heal [profile...]     replace TLS files that no longer match all.pem with a
#                                            link to it (the original is kept in .ringq-backup),
#                                            let FreeSWITCH read /etc/ringq again when it cannot,
#                                            then start the listed profiles that are not running
#
# heal never restarts or stops a running profile, so it does not drop calls, and always exits 0.

TLS_DIR=${RINGQ_FS_TLS_DIR:-/etc/freeswitch/tls}
SOFIA_CACHE=${RINGQ_FS_SOFIA_CACHE:-/var/cache/ringq/configuration.sofia.conf.$(hostname 2>/dev/null)}
LOG=${RINGQ_FS_SIP_LOG:-/var/log/ringq-fs-sip.log}
FS_CLI=${RINGQ_FS_CLI:-fs_cli}
OPENSSL=${RINGQ_OPENSSL:-openssl}
WAIT=${RINGQ_FS_SIP_WAIT:-10}
TLS_FILES="wss.pem wss.key agent.pem tls.pem dtls-srtp.pem"
RINGQ_DIR=${RINGQ_CONFIG_DIR:-/etc/ringq}
FS_USER_OVERRIDE=${RINGQ_FS_USER:-}
RUNUSER=${RINGQ_RUNUSER:-runuser}
SS=${RINGQ_SS:-ss}
WSS_PORT=${RINGQ_WSS_PORT:-7443}

log() {
    echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true
}

digest() {
    "$OPENSSL" dgst -sha256 -r 2>/dev/null | cut -d' ' -f1
}

cert_pub() {
    "$OPENSSL" x509 -in "$1" -noout -pubkey 2>/dev/null | "$OPENSSL" pkey -pubin -outform DER 2>/dev/null | digest
}

key_pub() {
    "$OPENSSL" pkey -in "$1" -pubout -outform DER 2>/dev/null | digest
}

cert_fp() {
    "$OPENSSL" x509 -in "$1" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2
}

has_cert() {
    grep -q 'BEGIN CERTIFICATE' "$1" 2>/dev/null
}

has_key() {
    grep -q 'PRIVATE KEY' "$1" 2>/dev/null
}

ALL="$TLS_DIR/all.pem"
ALL_PUB=""
ALL_FP=""
ALL_STATE=""

# Sets ALL_STATE to ok, missing, unreadable or broken (cert and key in all.pem do not belong
# together), and ALL_PUB / ALL_FP for file_state. Not run in a subshell, or those are lost.
check_all() {
    ALL_PUB=""; ALL_FP=""
    if [ ! -e "$ALL" ]; then ALL_STATE=missing; return; fi
    if [ ! -r "$ALL" ]; then ALL_STATE=unreadable; return; fi
    if ! has_cert "$ALL" || ! has_key "$ALL"; then ALL_STATE=broken; return; fi
    ALL_PUB=$(cert_pub "$ALL")
    ALL_FP=$(cert_fp "$ALL")
    if [ -z "$ALL_PUB" ] || [ "$ALL_PUB" != "$(key_pub "$ALL")" ]; then ALL_STATE=broken; return; fi
    ALL_STATE=ok
}

# Prints absent, linked, match, stale, unreadable or unknown for one file in TLS_DIR.
# Needs check_all to have run first.
file_state() {
    local f="$TLS_DIR/$1" checked=0
    if [ ! -e "$f" ] && [ ! -L "$f" ]; then echo absent; return; fi
    if [ -L "$f" ] && [ "$(readlink -f "$f" 2>/dev/null)" = "$(readlink -f "$ALL" 2>/dev/null)" ]; then
        echo linked; return
    fi
    [ -r "$f" ] || { echo unreadable; return; }
    if has_key "$f"; then
        checked=1
        [ "$(key_pub "$f")" = "$ALL_PUB" ] || { echo stale; return; }
    fi
    if has_cert "$f"; then
        checked=1
        [ "$(cert_fp "$f")" = "$ALL_FP" ] || { echo stale; return; }
    fi
    [ "$checked" = 1 ] && echo match || echo unknown
}

# Lines of "name state" for every profile FreeSWITCH lists; empty when it is not answering.
sofia_profiles() {
    "$FS_CLI" -x "sofia status" 2>/dev/null | awk -F'\t' '
        { for (i = 1; i <= NF; i++) { gsub(/^[ ]+|[ ]+$/, "", $i) } }
        $2 == "profile" { split($4, s, " "); print $1, s[1] }'
}

running() {
    sofia_profiles | awk -v p="$1" '$1 == p && $2 == "RUNNING" { found = 1 } END { exit found ? 0 : 1 }'
}

fs_up() {
    "$FS_CLI" -x "status" 2>/dev/null | grep -q "UP"
}

local_ips() {
    if [ -n "${RINGQ_FS_LOCAL_IPS+x}" ]; then
        echo "$RINGQ_FS_LOCAL_IPS"
        return
    fi
    ip -4 -o addr show 2>/dev/null | awk '{ split($4, a, "/"); print a[1] }' | tr '\n' ' '
}

valid_names() {
    local p
    for p in "$@"; do
        case "$p" in
            ''|*[!A-Za-z0-9_.-]*) log "ignored profile name: $p"; ;;
            *) echo "$p" ;;
        esac
    done
}

fs_user() {
    if [ -n "$FS_USER_OVERRIDE" ]; then
        echo "$FS_USER_OVERRIDE"
        return
    fi
    ps -o user= -C freeswitch 2>/dev/null | head -1 | tr -d ' '
}

config_state() {
    [ -d "$RINGQ_DIR" ] || { echo absent; return; }
    [ -x "$RINGQ_DIR" ] || { echo blocked; return; }
    [ -e "$RINGQ_DIR/config.conf" ] || { echo missing; return; }
    [ -r "$RINGQ_DIR/config.conf" ] || { echo unreadable; return; }
    echo ok
}

config_state_for_fs() {
    local u
    u=$(fs_user)
    case "$u" in
        ''|*[!A-Za-z0-9_.-]*) echo unknown; return ;;
        root) echo ok; return ;;
    esac
    if [ "$(id -un 2>/dev/null)" = "$u" ]; then
        config_state
    elif [ "$(id -u 2>/dev/null)" = 0 ]; then
        "$RUNUSER" -u "$u" -- /bin/bash "$0" config-state 2>/dev/null || echo unknown
    else
        echo unknown
    fi
}

wss_queue() {
    "$SS" -Hltn "sport = :$WSS_PORT" 2>/dev/null | awk '
        { n++; q = $2 + 0; b = $3 + 0; if (q > mq) mq = q; if (b > mb) mb = b; if (b > 0 && q >= b) full = 1 }
        END { printf "{\"listening\":%s,\"queued\":%d,\"backlog\":%d,\"full\":%s}", n ? "true" : "false", mq, mb, full ? "true" : "false" }'
}

heal_config_dir() {
    local u g state
    state=$(config_state_for_fs)
    case "$state" in
        blocked|unreadable) ;;
        *) return ;;
    esac
    u=$(fs_user)
    g=$(id -gn "$u" 2>/dev/null)
    [ -n "$g" ] || { log "$RINGQ_DIR: $state for $u, but its group is unknown; left as it is"; return; }
    if [ "$state" = blocked ]; then
        chgrp "$g" "$RINGQ_DIR" 2>/dev/null && chmod g+rx "$RINGQ_DIR" 2>/dev/null \
            && log "$RINGQ_DIR: $u could not open it; group set to $g with read and enter (other files inside unchanged)" \
            || log "$RINGQ_DIR: $u could not open it and the group could not be changed"
        state=$(config_state_for_fs)
    fi
    if [ "$state" = unreadable ]; then
        chgrp "$g" "$RINGQ_DIR/config.conf" 2>/dev/null && chmod g+r "$RINGQ_DIR/config.conf" 2>/dev/null \
            && log "$RINGQ_DIR/config.conf: $u could not read it; group set to $g with read" \
            || log "$RINGQ_DIR/config.conf: $u could not read it and it could not be changed"
    fi
}

json_list() {
    local first=1 v out="["
    for v in "$@"; do
        [ "$first" = 1 ] || out="$out,"
        out="$out\"$v\""
        first=0
    done
    echo "$out]"
}

status() {
    local names all fs=false p out first state seen listed=""
    names=$(valid_names "$@")
    check_all
    all=$ALL_STATE
    fs_up && fs=true
    seen=$(sofia_profiles)

    out="{\"fsUp\":$fs,\"profiles\":["
    first=1
    for p in $names $(echo "$seen" | awk '{ print $1 }'); do
        case " $listed " in *" $p "*) continue ;; esac
        listed="$listed $p"
        state=false
        echo "$seen" | awk -v p="$p" '$1 == p && $2 == "RUNNING" { f = 1 } END { exit f ? 0 : 1 }' && state=true
        [ "$first" = 1 ] || out="$out,"
        out="$out{\"name\":\"$p\",\"running\":$state}"
        first=0
    done
    out="$out],\"tls\":{\"allPem\":\"$all\",\"files\":["
    first=1
    for f in $TLS_FILES; do
        if [ "$all" = ok ]; then state=$(file_state "$f"); else state=unchecked; fi
        [ "$state" = absent ] && continue
        [ "$first" = 1 ] || out="$out,"
        out="$out{\"name\":\"$f\",\"state\":\"$state\"}"
        first=0
    done
    # shellcheck disable=SC2046
    out="$out]},\"localIps\":$(json_list $(local_ips)),\"ringqConfig\":\"$(config_state_for_fs)\",\"wssQueue\":$(wss_queue)}"
    echo "$out"
}

heal() {
    local names p f state stamp backup relinked=0 started=0 i
    names=$(valid_names "$@")

    check_all
    if [ "$ALL_STATE" = ok ]; then
        stamp=$(date '+%Y%m%dT%H%M%S')
        for f in $TLS_FILES; do
            state=$(file_state "$f")
            [ "$state" = stale ] || continue
            backup="$TLS_DIR/.ringq-backup/$stamp"
            mkdir -p "$backup" 2>/dev/null
            if mv "$TLS_DIR/$f" "$backup/$f" 2>/dev/null && ln -s all.pem "$TLS_DIR/$f" 2>/dev/null; then
                chown -h --reference="$ALL" "$TLS_DIR/$f" 2>/dev/null || true
                log "$f: did not match all.pem; now a link to it (old file in $backup)"
                relinked=1
            else
                [ -e "$TLS_DIR/$f" ] || mv "$backup/$f" "$TLS_DIR/$f" 2>/dev/null
                log "$f: did not match all.pem and could not be replaced"
            fi
        done
    else
        log "all.pem is $ALL_STATE; TLS files left as they are"
    fi

    heal_config_dir

    for p in $names; do
        running "$p" && continue
        if [ "$started" = 0 ] && [ -n "$SOFIA_CACHE" ] && [ -f "$SOFIA_CACHE" ]; then
            rm -f "$SOFIA_CACHE" 2>/dev/null && log "removed the cached sofia configuration $SOFIA_CACHE"
        fi
        log "$p: not running; starting it"
        "$FS_CLI" -x "sofia profile $p start" >/dev/null 2>&1
        started=1
    done

    if [ "$started" = 1 ]; then
        for i in $(seq 1 "$WAIT"); do
            local down=0
            for p in $names; do running "$p" || down=1; done
            [ "$down" = 0 ] && break
            sleep 1
        done
        for p in $names; do
            if running "$p"; then log "$p: RUNNING"; else log "$p: still not running"; fi
        done
    elif [ "$relinked" = 1 ]; then
        log "TLS files relinked; every listed profile was already running, nothing restarted"
    fi
    return 0
}

case "$1" in
    status) shift; status "$@" ;;
    heal) shift; heal "$@" ;;
    config-state) config_state ;;
    *) echo "usage: $0 status|heal [profile...]" >&2 ;;
esac
exit 0
