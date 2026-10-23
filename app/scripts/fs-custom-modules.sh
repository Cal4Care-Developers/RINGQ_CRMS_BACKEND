#!/bin/bash
# FreeSWITCH modules RingQ builds from source: no apt package owns their .so, so jobs.sh's
# FreeSWITCH drift heal used to delete them as "orphans" and nothing put them back. The AI
# agent (mod_openai_audio_stream) and G.729 (mod_bcg729) then failed to load on every
# restart until somebody copied the build output back by hand.
#
#   fs-custom-modules.sh heal     copy a missing or stale module back from its build output,
#                                 make sure the AI agent module is in modules.conf.xml, and load
#                                 any declared module FreeSWITCH has not loaded (needs root)
#   fs-custom-modules.sh status   one JSON line describing each module (read-only, any user)
#   fs-custom-modules.sh restart  "AI Voice" on the Service restart panel: heal, hang up the
#                                 calls the AI agent is on (5555), then reload the AI agent
#                                 module so it starts with fresh connections (needs root)
#
# heal never fails the caller (jobs.sh, startup.sh, the repair endpoint) and never compiles:
# when the build output is missing it reports that and leaves the module alone. A module that
# is already loaded is never reloaded, because that would drop live AI calls.
# mod_ringq is not handled here: ring-push/ringq-push-setup.sh rebuilds it on every update.

MOD_DIR=${RINGQ_FS_MOD_DIR:-/usr/lib/freeswitch/mod}
MODULES_CONF=${RINGQ_FS_MODULES_CONF:-/etc/freeswitch/autoload_configs/modules.conf.xml}
SRC_ROOT=${RINGQ_FS_SRC_ROOT:-/usr/src}
LOG=${RINGQ_FS_MODULES_LOG:-/var/log/ringq-fs-modules.log}
FS_CLI=${RINGQ_FS_CLI:-fs_cli}
MODULES="openai_audio_stream bcg729"

sources_for() {
    case "$1" in
        openai_audio_stream)
            echo "$SRC_ROOT/mod_openai_audio_stream/build/mod_openai_audio_stream.so /usr/local/freeswitch/mod/mod_openai_audio_stream.so" ;;
        bcg729)
            echo "$SRC_ROOT/mod_bcg729/mod_bcg729.so $SRC_ROOT/mod_bcg729/build/mod_bcg729.so" ;;
    esac
}

# Modules the AI agent cannot work without get declared when missing from modules.conf.xml,
# the same as mod_openai.sh does at install. Others are only healed when already declared.
always_declare() {
    [ "$1" = "openai_audio_stream" ]
}

log() {
    echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true
}

build_of() {
    local src
    for src in $(sources_for "$1"); do
        if [ -f "$src" ]; then
            echo "$src"
            return 0
        fi
    done
    return 1
}

declared() {
    [ -f "$MODULES_CONF" ] || return 1
    grep -E "<load module=\"mod_$1\"" "$MODULES_CONF" 2>/dev/null | grep -qvE '^[[:space:]]*<!--'
}

# Prints true, false or unknown (FreeSWITCH not answering).
loaded() {
    local out
    out=$("$FS_CLI" -x "module_exists mod_$1" 2>/dev/null | tr -d '[:space:]')
    case "$out" in
        true) echo true ;;
        false) echo false ;;
        *) echo unknown ;;
    esac
}

heal_one() {
    local name="$1" dest="$MOD_DIR/mod_$1.so" build state
    if ! build=$(build_of "$name"); then
        if [ -f "$dest" ]; then
            log "mod_$name: installed, no build output to compare (left as is)"
        else
            log "mod_$name: MISSING and no build output under $SRC_ROOT; rebuild needed"
        fi
        return 0
    fi
    if ! declared "$name"; then
        if always_declare "$name" && [ -f "$MODULES_CONF" ]; then
            # Written back in place (not mv) so the file keeps its owner and mode.
            local conf_tmp
            conf_tmp=$(mktemp) || return 0
            if awk -v line="    <load module=\"mod_$name\"/>" \
                    '!done && /<\/modules>/ { print line; done = 1 } { print }' "$MODULES_CONF" > "$conf_tmp" \
                && grep -q "mod_$name" "$conf_tmp" && cat "$conf_tmp" > "$MODULES_CONF"; then
                log "mod_$name: added to modules.conf.xml"
            fi
            rm -f "$conf_tmp"
        else
            return 0
        fi
    fi
    if [ ! -f "$dest" ] || ! cmp -s "$build" "$dest"; then
        local tmp="$dest.ringq-tmp.$$"
        local owner=""
        [ "$(id -u)" = "0" ] && owner="-o root -g root"
        # shellcheck disable=SC2086
        if install $owner -m 0644 "$build" "$tmp" 2>/dev/null && mv -f "$tmp" "$dest"; then
            log "mod_$name: installed from $build"
        else
            rm -f "$tmp"
            log "mod_$name: could not install from $build"
            return 0
        fi
    fi
    state=$(loaded "$name")
    if [ "$state" = "false" ]; then
        "$FS_CLI" -x "load mod_$name" >/dev/null 2>&1 || true
        state=$(loaded "$name")
        log "mod_$name: load attempted, loaded=$state"
    fi
    return 0
}

status_json() {
    local name first=1 dest build has_build matches
    printf '{"modules":['
    for name in $MODULES; do
        dest="$MOD_DIR/mod_$name.so"
        has_build=false
        matches=null
        if build=$(build_of "$name"); then
            has_build=true
            if [ -f "$dest" ]; then
                cmp -s "$build" "$dest" && matches=true || matches=false
            fi
        fi
        [ $first -eq 1 ] || printf ','
        first=0
        printf '{"name":"mod_%s","declared":%s,"file":%s,"build":%s,"matchesBuild":%s,"loaded":"%s"}' \
            "$name" \
            "$(declared "$name" && echo true || echo false)" \
            "$([ -f "$dest" ] && echo true || echo false)" \
            "$has_build" "$matches" "$(loaded "$name")"
    done
    printf ']}\n'
}

# Calls currently on the AI agent: channels whose destination is 5555. The AI module keeps a
# media bug on each, and unloading it under a live bug can take FreeSWITCH down.
ai_channels() {
    "$FS_CLI" -x "show channels as delim |" 2>/dev/null | awk -F'|' '
        NR == 1 { for (i = 1; i <= NF; i++) if ($i == "dest") d = i; next }
        d && $d == "5555" { print $1 }'
}

restart_ai() {
    local uuid dropped=0 state
    # A loaded module is reloaded from its own file: the build output can be older than what
    # runs, so it is copied in only when the module is missing or not loaded.
    [ "$(loaded openai_audio_stream)" = "true" ] || heal_one openai_audio_stream
    for uuid in $(ai_channels); do
        "$FS_CLI" -x "uuid_kill $uuid NORMAL_CLEARING" >/dev/null 2>&1 && dropped=$((dropped + 1))
    done
    [ "$dropped" -gt 0 ] && sleep 1
    state=$(loaded openai_audio_stream)
    if [ "$state" = "true" ]; then
        "$FS_CLI" -x "reload mod_openai_audio_stream" >/dev/null 2>&1 || true
    elif [ "$state" = "false" ]; then
        "$FS_CLI" -x "load mod_openai_audio_stream" >/dev/null 2>&1 || true
    fi
    state=$(loaded openai_audio_stream)
    log "mod_openai_audio_stream: AI Voice restart, $dropped AI call(s) ended, loaded=$state"
    printf '{"dropped":%d,"loaded":"%s"}\n' "$dropped" "$state"
}

case "${1:-heal}" in
    status)
        status_json
        ;;
    restart)
        restart_ai
        ;;
    heal)
        for _m in $MODULES; do
            heal_one "$_m"
        done
        ;;
    *)
        echo "usage: $0 [heal|status|restart]" >&2
        exit 2
        ;;
esac
exit 0
