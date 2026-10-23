#!/bin/bash
# =====================================================================================
#  cm-install.sh -- CM ADJUSTMENT wrapper (added by CM / Mani)
#
#  The CM adjustments now live in app/scripts/jobs.sh (function cm_adjustments), which is
#  the MAIN script: it ships with every update and applies them automatically after every
#  update (restart_jobs). This wrapper only runs that same code by hand:
#
#    bash cm-install.sh            install / repair
#    bash cm-install.sh --check    report only, change nothing (exit 1 if anything is off)
#    bash cm-install.sh --force    also replace a local_extension that was customised
#
#  Equivalent on a server:  bash /var/www/ringq/app/scripts/jobs.sh --cm-adjust [--check]
#  Docker host: run it on the host - it runs inside the "ringq" container.
#  Log: /var/log/ringq-cm-install.log
# =====================================================================================
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JOBS="$SRC/../app/scripts/jobs.sh"
[ -f "$JOBS" ] || JOBS=/var/www/ringq/app/scripts/jobs.sh
[ -f "$JOBS" ] || { echo "cm-install: app/scripts/jobs.sh not found" >&2; exit 2; }

if ! command -v fs_cli >/dev/null 2>&1 && command -v docker >/dev/null 2>&1 \
   && docker ps --format '{{.Names}}' 2>/dev/null | grep -qx ringq; then
  echo "[cm-install] Docker host - running inside the ringq container"
  docker exec -i ringq bash -c 'cat > /tmp/ringq-cm-jobs.sh && bash /tmp/ringq-cm-jobs.sh --cm-adjust "$@"; rc=$?; rm -f /tmp/ringq-cm-jobs.sh; exit $rc' _ "$@" < "$JOBS"
  exit $?
fi
exec bash "$JOBS" --cm-adjust "$@"
