#!/usr/bin/env bash
# Installs the RingQ Freshdesk integration backend on a RingQ server (e.g. sgringq100).
#
#   sudo bash install.sh            # app root /var/www/ringq, database ringq
#   sudo APP=/var/www/ringq DB=ringq DB_USER=<app_db_user> bash install.sh
#
# Adds NEW files only. No existing file is changed: the Freshdesk controller
# registers its own ACL entries, so config/services.php (ionCube-encoded on
# servers that run the CI build) is not touched.
#
# Steps (each safe to run again):
#   1. copies the Freshdesk files (controller, library, task)
#   2. creates the three q_freshdesk_* tables (additive SQL)
#   3. schedules the cron job: tasks.txt if it lists it, else /etc/cron.d/ringq-freshdesk
#   4. reloads PHP-FPM
#   5. checks that https://<this server>:8443/freshdeskcrm/config now answers 401 (installed) instead of 404
set -euo pipefail

APP="${APP:-/var/www/ringq}"
DB="${DB:-ringq}"
DB_USER="${DB_USER:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"
# Runs from the release bundle (app/ and sql/ next to this script) or from the
# backend repository (server-configs/freshdesk/install.sh).
if [ -d "$HERE/app/library/Freshdesk" ]; then
  SRC="$HERE"; SQLDIR="$HERE/sql"
else
  SRC="$(cd "$HERE/../.." && pwd)"; SQLDIR="$SRC/app/scripts/freshdesk"
fi
[ -f "$SRC/app/controllers/FreshdeskcrmController.php" ] || { echo "ERROR: Freshdesk files not found next to $0" >&2; exit 1; }
STAMP="$(date +%Y%m%d-%H%M%S)"
PHP="$(command -v php7.4 || command -v php)"

say() { printf '\n== %s\n' "$*"; }
die() { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "Run as root (sudo)."
[ -d "$APP/app/controllers" ] || die "$APP/app/controllers not found. Set APP=<RingQ app root>."
[ -f "$APP/app/controllers/ControllerBase.php" ] || die "$APP/app/controllers/ControllerBase.php not found. Set APP=<RingQ app root>."

say "1. Files (new files only)"
for f in app/controllers/FreshdeskcrmController.php app/tasks/FreshdeskcrmTask.php \
         app/library/Freshdesk/FreshdeskService.php app/library/Freshdesk/FreshdeskClient.php \
         app/library/Freshdesk/FreshdeskCrypto.php app/library/Freshdesk/FreshdeskException.php; do
  install -D -m 0644 "$SRC/$f" "$APP/$f"
  "$PHP" -l "$APP/$f" >/dev/null || die "PHP syntax check failed: $APP/$f"
  echo "ok  $APP/$f"
done
OWNER="$(stat -c '%U:%G' "$APP/app/controllers/ControllerBase.php")"
chown -R "$OWNER" "$APP/app/library/Freshdesk" "$APP/app/controllers/FreshdeskcrmController.php" "$APP/app/tasks/FreshdeskcrmTask.php"
echo "owner $OWNER"

say "2. Database tables"
# Fed on stdin: the postgres user usually cannot read files under /root.
sudo -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q < "$SQLDIR/2026_10_23_freshdesk_crm.sql"
if [ -n "$DB_USER" ]; then
  sudo -u postgres psql -d "$DB" -v ON_ERROR_STOP=1 -q -c \
    "GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_freshdesk_config, public.q_freshdesk_call_journals, public.q_freshdesk_cti_tokens TO \"$DB_USER\";"
fi
N="$(sudo -u postgres psql -d "$DB" -At -c "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'q_freshdesk_%'")"
[ "$N" = 3 ] || die "Expected 3 q_freshdesk_* tables, found $N."
echo "ok  q_freshdesk_config, q_freshdesk_call_journals, q_freshdesk_cti_tokens"

say "3. Cron"
# Its own /etc/cron.d file: RingQ's app/scripts/jobs.sh rebuilds root's crontab
# from tasks.txt, so a line added to the crontab itself is dropped within a minute.
CRONFILE=/etc/cron.d/ringq-freshdesk
if grep -q 'cli.php freshdeskcrm sync' "$APP/app/tasks/tasks.txt" 2>/dev/null; then
  # tasks.txt (deployed with this backend) already schedules it: no second copy.
  rm -f "$CRONFILE"
  echo "ok  scheduled by $APP/app/tasks/tasks.txt"
else
  cat > "$CRONFILE.tmp" <<EOF
# RingQ Freshdesk integration: retries, late recordings, edited notes (FreshdeskcrmTask::syncAction).
* * * * * root $PHP $APP/app/cli.php freshdeskcrm sync >/dev/null 2>&1
EOF
  chmod 644 "$CRONFILE.tmp" && mv "$CRONFILE.tmp" "$CRONFILE"
  echo "ok  $CRONFILE"
fi

say "4. Reload PHP"
RELOADED=0
for svc in $(systemctl list-units --type=service --no-legend --plain --state=active 'php*-fpm*' | awk '{print $1}'); do
  systemctl reload "$svc" && echo "reloaded $svc" && RELOADED=1
done
[ "$RELOADED" = 1 ] || echo "WARNING: no php*-fpm service found; reload PHP-FPM yourself."

say "5. Check"
sleep 1
HOST="$(hostname -f)"
CODE="$(curl -sk -o /dev/null -w '%{http_code}' "https://$HOST:8443/freshdeskcrm/config" || true)"
echo "freshdeskcrm/config -> HTTP $CODE (401 = installed, needs login; 404 = the API is not running these files)"
if [ "$CODE" = 404 ]; then
  echo "The API on :8443 is not loading files from $APP. Find the folder it uses:"
  echo "  grep -rn 'listen.*8443' -A15 /etc/nginx/sites-enabled/ | grep -E 'root|fastcgi_pass'"
  echo "then run again with APP=<that folder without /public>."
  exit 1
fi
echo "Done."
