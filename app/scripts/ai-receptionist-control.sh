#!/bin/bash
# AI Receptionists: repair + restart. Run as root. Takes no free-form input; every command is a literal.
#
#   ai-receptionist-control.sh repair   make sure the AI voice bridge can start, then (re)start it:
#                                         1. install ONLY the AI packages that are missing from the node folder,
#                                            without touching, upgrading or removing anything else there
#                                         2. load mod_openai_audio_stream in FreeSWITCH if it is not loaded
#                                         3. start or restart the ai-agent (and mcp-email) pm2 apps
#                                         4. wait for the bridge's relay port to answer
#   ai-receptionist-control.sh status   one JSON line: packages missing, module loaded, pm2 state, relay port
#
# Only ai-agent and mcp-email are started or restarted; no other pm2 app is touched and `pm2 save` is not run.
# The last line of output is always JSON: {"ok":true|false,"message":"...","steps":["...",...]}
#
# INSTALL (as root, once):  install -m 0755 -o root -g root ai-receptionist-control.sh /usr/local/sbin/ringq-ai-receptionist-control.sh
# It must live in a folder only root can write to (/usr/local/sbin is): it runs as root and refuses to run otherwise. It runs
# no other script from the web folders, for the same reason.
#
# Called from TeamsaiController::aiReceptionistRestartAction when the bridge is not answering (a bridge that is
# running restarts itself through its own control endpoint, which needs no root).

export HOME=${HOME:-/root}
NODE_DIR=${RINGQ_NODE_DIR:-/var/www/ringq/node}
ECOSYSTEM=${RINGQ_ECOSYSTEM:-/var/www/ringq/ecosystem.config.js}
NVM_SH=${NVM_DIR:-$HOME/.nvm}/nvm.sh
LOG=${RINGQ_AI_RECEPTIONIST_LOG:-/var/log/ringq-ai-receptionist.log}
FS_CLI=${RINGQ_FS_CLI:-fs_cli}
RELAY_PORT=${RINGQ_AI_RELAY_PORT:-8082}
MODULE=mod_openai_audio_stream

# The packages the bridge imports that are not part of the stock node folder, with the versions it was built and
# tested with. (ws, modesl and openai are installed by the base setup and are only checked, never installed here.)
AI_PACKAGES="@deepgram/sdk@3.13.0 @modelcontextprotocol/sdk@1.32.1 @anthropic-ai/sdk@0.131.0 @google/generative-ai@0.24.1 zod@3.25.76"
BASE_PACKAGES="ws modesl openai"

STEPS=()

log() { echo "$(date '+%F %T') $*" >> "$LOG" 2>/dev/null || true; }
step() { STEPS+=("$1"); log "$1"; }

jesc() { # JSON-escape a string: backslash, quote, control characters
    local s=${1//\\/\\\\}
    s=${s//\"/\\\"}
    s=${s//$'\n'/ }
    s=${s//$'\r'/ }
    s=${s//$'\t'/ }
    printf '%s' "$s"
}

steps_json() {
    local out="" s
    for s in "${STEPS[@]}"; do
        [ -n "$out" ] && out="$out,"
        out="$out\"$(jesc "$s")\""
    done
    printf '[%s]' "$out"
}

finish() { # ok message
    printf '{"ok":%s,"message":"%s","steps":%s}\n' "$1" "$(jesc "$2")" "$(steps_json)"
    exit 0
}

pkg_name() { # "@scope/name@1.2.3" -> "@scope/name", "ws" -> "ws"
    local spec=$1
    case "$spec" in
        @*/*@*) printf '%s' "${spec%@*}" ;;
        @*/*)   printf '%s' "$spec" ;;
        *@*)    printf '%s' "${spec%@*}" ;;
        *)      printf '%s' "$spec" ;;
    esac
}

pkg_present() { [ -f "$NODE_DIR/node_modules/$1/package.json" ]; }

missing_ai_specs() {
    local spec
    for spec in $AI_PACKAGES; do
        pkg_present "$(pkg_name "$spec")" || printf '%s ' "$spec"
    done
}

missing_base_names() {
    local name
    for name in $BASE_PACKAGES; do
        pkg_present "$name" || printf '%s ' "$name"
    done
}

load_node() {
    # The server's nvm (a shell function sourced from nvm.sh) only. Nothing is downloaded or installed here: Node 20 is
    # what the ai-agent app already runs on, so it is already present.
    if [ -s "$NVM_SH" ]; then
        # shellcheck disable=SC1090
        . "$NVM_SH"
        type nvm 2>/dev/null | head -n 1 | grep -q "function" && nvm use 20 >/dev/null 2>&1
    fi
    command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1
}

module_loaded() { [ "$("$FS_CLI" -x "module_exists $MODULE" 2>/dev/null | tr -d '[:space:]')" = "true" ]; }

port_open() { ss -ltn 2>/dev/null | grep -q "127.0.0.1:$RELAY_PORT[[:space:]]"; }

pm2_state() { # name -> online|stopped|errored|missing
    pm2 jlist 2>/dev/null | node -e '
let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{
 try{const p=JSON.parse(s).find(x=>x.name===process.argv[1]);console.log(p?p.pm2_env.status:"missing")}catch(e){console.log("missing")}})' "$1" 2>/dev/null
}

# Install the missing AI packages next to (not over) what is there. npm is run in a scratch folder with the
# "nested" strategy, so each package brings its own dependencies inside itself; then only the package folders that
# are absent from the node folder are copied across. Nothing existing is overwritten or removed, so the other
# services that share this node_modules are not affected -- unlike `npm install` in the node folder, which also
# prunes whatever package.json does not list.
install_missing() {
    local specs=$1 tmp entry base sub name failed=0
    tmp=$(mktemp -d /tmp/ringq-ai-deps.XXXXXX) || return 1
    (
        cd "$tmp" || exit 1
        npm init -y >/dev/null 2>&1
        npm install --install-strategy=nested --no-audit --no-fund --ignore-scripts --no-package-lock $specs >>"$LOG" 2>&1
    ) || failed=1
    if [ "$failed" = 1 ]; then
        # An npm that does not know --install-strategy: plain install in the scratch folder, copy no-clobber.
        (
            cd "$tmp" && rm -rf node_modules package-lock.json &&
            npm install --no-audit --no-fund --ignore-scripts $specs >>"$LOG" 2>&1
        ) && failed=0
    fi
    if [ "$failed" = 0 ]; then
        # Whole package folders only, and only the ones that are absent: an existing folder (the same package at
        # another version that other services use) is never merged into or replaced.
        for entry in "$tmp"/node_modules/*; do
            [ -d "$entry" ] || continue
            base=$(basename "$entry")
            case "$base" in
                @*)
                    mkdir -p "$NODE_DIR/node_modules/$base"
                    for sub in "$entry"/*; do
                        name=$base/$(basename "$sub")
                        if [ ! -e "$NODE_DIR/node_modules/$name" ]; then
                            cp -a "$sub" "$NODE_DIR/node_modules/$name" && step "installed $name"
                        fi
                    done
                    ;;
                *)
                    if [ ! -e "$NODE_DIR/node_modules/$base" ]; then
                        cp -a "$entry" "$NODE_DIR/node_modules/$base" && step "installed $base"
                    fi
                    ;;
            esac
        done
    fi
    rm -rf "$tmp"
    [ "$failed" = 0 ]
}

# A script that root runs and somebody else can edit is a way to become root. So "repair" refuses to run unless this file and
# the folder it sits in belong to root and have no group/other write permission. (Under sudo the environment is reset,
# so the RINGQ_* overrides above cannot be injected by the web server either.)
not_tamperable() {
    local self dir p perm
    self=$(readlink -f "${BASH_SOURCE[0]}") || return 1
    dir=$(dirname "$self")
    for p in "$self" "$dir"; do
        [ "$(stat -c %u "$p" 2>/dev/null)" = "0" ] || return 1
        perm=$(stat -c %a "$p" 2>/dev/null) || return 1
        [ $(( 0$perm & 022 )) -eq 0 ] || return 1
    done
    return 0
}

case "${1:-}" in
    status)
        load_node >/dev/null 2>&1
        printf '{"ok":true,"missingPackages":"%s","moduleLoaded":%s,"aiAgent":"%s","relayPort":%s}\n' \
            "$(jesc "$(missing_ai_specs)$(missing_base_names)")" \
            "$(module_loaded && echo true || echo false)" \
            "$(command -v pm2 >/dev/null 2>&1 && pm2_state ai-agent || echo missing)" \
            "$(port_open && echo true || echo false)"
        ;;
    repair)
        not_tamperable || finish false "Refusing to run: this script and its folder must be owned by root and not writable by anyone else (chown root:root, chmod go-w)."
        [ -d "$NODE_DIR" ] || finish false "The node folder $NODE_DIR does not exist on this server."
        if ! load_node; then
            finish false "Node 20 and npm are not available for root on this server (nvm missing). Install Node 20 first."
        fi
        step "using node $(node --version)"

        base=$(missing_base_names)
        if [ -n "$base" ]; then
            finish false "The base node packages are missing ($base). They come with the server setup and are not installed from here."
        fi

        specs=$(missing_ai_specs)
        if [ -n "$specs" ]; then
            step "missing AI packages: $specs"
            if ! install_missing "$specs"; then
                finish false "Installing the missing AI packages failed. See $LOG on the server."
            fi
            still=$(missing_ai_specs)
            [ -n "$still" ] && finish false "Still missing after the install: $still. See $LOG on the server."
        else
            step "AI packages: all present"
        fi

        if module_loaded; then
            step "$MODULE is loaded"
        else
            "$FS_CLI" -x "load $MODULE" >/dev/null 2>&1
            if module_loaded; then
                step "$MODULE loaded"
            else
                step "$MODULE could not be loaded (the AI voice cannot stream until it is)"
            fi
        fi

        if ! command -v pm2 >/dev/null 2>&1; then
            finish false "pm2 is not available for Node 20 on this server."
        fi
        if [ "$(pm2_state ai-agent)" = "missing" ] && [ -f "$ECOSYSTEM" ]; then
            pm2 start "$ECOSYSTEM" --only ai-agent,mcp-email >>"$LOG" 2>&1
            step "started ai-agent from $ECOSYSTEM"
        fi
        pm2 restart ai-agent >>"$LOG" 2>&1
        [ "$(pm2_state mcp-email)" != "missing" ] && pm2 restart mcp-email >>"$LOG" 2>&1
        step "restarted ai-agent"

        i=0
        while [ $i -lt 30 ]; do
            port_open && break
            sleep 1
            i=$((i + 1))
        done
        if port_open; then
            if module_loaded; then
                finish true "AI receptionist is running again."
            fi
            finish false "The bridge is running but $MODULE is not loaded in FreeSWITCH, so calls cannot stream. See $LOG."
        fi
        finish false "The bridge did not come up after the restart (state: $(pm2_state ai-agent)). See $LOG, or run: pm2 logs ai-agent"
        ;;
    *)
        finish false "Usage: ai-receptionist-control.sh repair|status"
        ;;
esac
