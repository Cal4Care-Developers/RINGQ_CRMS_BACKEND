#!/bin/bash
set -e

# -------------------------------
# Configuration
# -------------------------------
CACHE_DIR="/var/cache/ringq"
PM2="/root/.nvm/versions/node/v14.21.3/bin/pm2"
FS_STATUS_TIMEOUT=60        # Max seconds to wait for FS to start
SOFIA_PROFILE_TIMEOUT=60    # Max seconds to wait for sofia profiles
SOFIA_RETRY=7               # Number of retries for mod_sofia reload
CHECK_PORTS=(7443 5060 5061) # Ports to ensure FS is listening

# boot          - run at @reboot (default, unchanged)
# all           - "Restart everything" from Service restart: also restarts PostgreSQL first
# call-services - "RingQ Call Services" from Service restart: the call engine plus the
#                 node apps holding an event-socket connection to it; no nginx, no pm2 restart all
MODE="${1:-boot}"
ESL_APPS=(server signal attended queue custom_crm)
[ -x "$PM2" ] || PM2="$(command -v pm2 || echo /bin/pm2)"

# The Service restart panel shows "Restarting" while this key exists; clear it however we exit.
trap 'redis-cli del startup >/dev/null 2>&1 || true' EXIT

# -------------------------------
# Helper functions
# -------------------------------
function wait_for_fs_start() {
    echo "Waiting for FreeSWITCH to start..."
    for i in $(seq 1 $FS_STATUS_TIMEOUT); do
        if fs_cli -x "status" &>/dev/null; then
            echo "✅ FreeSWITCH process is up."
            return 0
        fi
        sleep 1
    done
    echo "❌ FreeSWITCH did not start in $FS_STATUS_TIMEOUT seconds."
    exit 1
}

function wait_for_fs_stop() {
    echo "Waiting for FreeSWITCH to stop..."
    for i in $(seq 1 30); do
        if ! pgrep -x freeswitch >/dev/null; then
            echo "✅ FreeSWITCH stopped."
            return 0
        fi
        sleep 1
    done
    echo "❌ FreeSWITCH did not stop cleanly in 30 seconds."
    exit 1
}

function wait_for_sofia() {
    local attempt=0
    echo "Waiting for internal mod_sofia profile..."
    while ! fs_cli -x "sofia status" | grep -q "^internal"; do
        attempt=$((attempt+1))
        echo "Attempt $attempt: internal profile not ready, reloading mod_sofia..."
        fs_cli -x "reload mod_sofia" || true
        sleep 3
        if [ $attempt -ge $SOFIA_RETRY ]; then
            echo "❌ Internal profile failed to load after $SOFIA_RETRY attempts."
            break
        fi
    done
    echo "✅ Internal profile loaded."
}

function wait_for_ports() {
    for port in "${CHECK_PORTS[@]}"; do
        echo "Checking port $port..."
        for i in $(seq 1 30); do
            if ss -tuln | grep -q ":$port "; then
                echo "✅ Port $port is listening."
                break
            fi
            sleep 1
        done
    done
}

# -------------------------------
# Main script
# -------------------------------  

if [ "$MODE" = "all" ]; then
    echo "Restarting PostgreSQL..."
    systemctl restart postgresql || true
fi

# Clear cache
if [ -d "$CACHE_DIR" ]; then
    echo "Clearing cache directory..."
    rm -rf "${CACHE_DIR:?}"/*
fi

# -------------------------------
# Graceful shutdown and restart of FreeSWITCH
# -------------------------------
if pgrep -x freeswitch > /dev/null; then
    echo "Shutting down FreeSWITCH gracefully..."
    fs_cli -x "shutdown" || true
    # Wait until FreeSWITCH process has fully stopped
    wait_for_fs_stop
else
    echo "FreeSWITCH not running, skipping shutdown."
fi

# Free the WebRTC port if something other than the call engine still holds it
if [ "$MODE" != "boot" ]; then
    lsof -ti :7443 | xargs -r kill -9 || true
fi

# Start FreeSWITCH
echo "Starting FreeSWITCH..."
systemctl start freeswitch

# Wait until FS is fully running
wait_for_fs_start

# -------------------------------
# mod_sofia handling
# -------------------------------
wait_for_sofia

# Rescan Sofia profiles
echo "Rescanning Sofia profiles..."
fs_cli -x 'reload mod_sofia' || true
fs_cli -x "sofia profile internal rescan" || true
fs_cli -x "sofia profile external rescan" || true
# Restores the AI agent / G.729 modules from their build output if an update removed them.
if [ -f /var/www/ringq/app/scripts/fs-custom-modules.sh ]; then
	bash /var/www/ringq/app/scripts/fs-custom-modules.sh heal || true
else
	fs_cli -x "load mod_openai_audio_stream" || true
fi
fs_cli -x "load mod_ringq" || true   # [ring-push] app/scripts/ring-push

# -------------------------------
# Check ports
# -------------------------------
wait_for_ports

if [ "$MODE" = "call-services" ]; then
    # Only the apps that talk to the call engine need to reconnect
    if [ -x "$PM2" ]; then
        for app in "${ESL_APPS[@]}"; do
            $PM2 restart "$app" || true
        done
    fi
    echo "✅ RingQ Call Services restarted."
    exit 0
fi

# -------------------------------
# Reload Nginx gracefully
# -------------------------------
echo "Reloading Nginx..."
sudo nginx -s reload

# -------------------------------
# Restart PM2 processes
# -------------------------------
if [ -x "$PM2" ]; then
    echo "Restarting PM2 applications..."
    $PM2 restart all || true
    $PM2 reset all || true
fi

# -------------------------------
# Cleanup
# -------------------------------
redis-cli del startup
redis-cli del connected_participants # meeting.js
echo "✅ All services restarted successfully."
