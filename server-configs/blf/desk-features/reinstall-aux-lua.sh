#!/bin/bash
# Run on the Mac from this folder: bash reinstall-aux-lua.sh   (apple only: replaces ringq_desk_aux.lua, then checks)
set -e
HOST=root@165.232.169.74
cd "$(dirname "$0")"
scp ringq_desk_aux.lua "$HOST:/usr/share/freeswitch/scripts/ringq_desk_aux.lua"
ssh "$HOST" 'chmod 644 /usr/share/freeswitch/scripts/ringq_desk_aux.lua; grep -c 8443 /usr/share/freeswitch/scripts/ringq_desk_aux.lua'
bash check-on-apple.sh
