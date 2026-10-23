#!/bin/bash
# click2talk-heal.sh
#
# Reconciles v_extensions.extension, v_extensions.number_alias and
# v_users.username for click2talk guest accounts.
#
# Extracted from ringq/app/scripts/jobs.sh so that the INSTALL path and the
# UPDATE path run the same code. A reinstall recreates the drift this repairs,
# and the update-only placement meant a freshly installed server carried it
# until somebody happened to click Update.
#
# SCOPE, stated because the name oversells it: this repairs click2talk GUEST
# rows only (number_alias = '100000'). It is not a general extension-identity
# repair and does not, on its own, stop an ordinary user's first login from
# logging "Can't find user".
#
# Redis key "click2talk-heal": absent -> enforce (default),
# "report" -> log only, "off" -> skip entirely.
#
# Safe to run repeatedly; makes no change when nothing has drifted.
set -uo pipefail

# Cron runs with PATH=/usr/bin:/bin, which hides the sbin tools. Append the
# full system path rather than replacing PATH outright: this script is also
# invoked by the installer and by tests, and clobbering the caller's PATH
# silently discards whatever they put in front of it.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

CONFIG_PHP="${RINGQ_CONFIG_PHP:-/var/www/ringq/app/config/config.php}"

if ! pg_isready -h 127.0.0.1 -p 5432 -U ringq >/dev/null 2>&1; then
    echo "click2talk heal: PostgreSQL is not accepting connections, skipping."
    exit 0
fi

# Callers that already hold the password (jobs.sh) pass it in; a standalone run
# reads it the same way every other RingQ shell helper does.
if [ -z "${_DB_PASS:-}" ]; then
    _DB_PASS=$(awk "/'database' => \[/,/\]/ {
        if (\$0 ~ /'password'/) {
            match(\$0, /'password'[[:space:]]*=>[[:space:]]*'([^']*)'/, a);
            print a[1];
        }
    }" "$CONFIG_PHP" 2>/dev/null)
fi

if [ -z "${_DB_PASS:-}" ]; then
    echo "click2talk heal: could not read the database password, skipping."
    exit 0
fi

# >>> BEGIN click2talk-heal >>>  (extracted verbatim by
# ops/meet/tests/click2talk_identity_heal_test.sh - keep the markers)
#
# click2talk guest identity heal.
#
# A click2talk guest can only REGISTER as v_extensions.number_alias:
# directory.lua emits <user id="<number_alias>" number-alias="<extension>">
# and stamps sip-forbid-register="true" on a second document whenever the
# two columns differ. The /register/calllog endpoint meanwhile *finds* the
# guest by number_alias but hands the widget v_users.username as its SIP
# username. When those two disagree the widget authenticates with a correct
# digest and is still refused 403, so the call drops the moment it is placed.
# Repair makes extension, number_alias and username agree.
#
# The target is always the row's own number_alias, never a hardcoded number:
# the tenant-config origin of the guest extension number is not established,
# so a row we cannot derive a target for is skipped rather than guessed at.
#
# Redis key "click2talk-heal": absent -> enforce (default),
# "report" -> log only, "off" -> skip entirely.
_C2T_MODE=$(redis-cli get click2talk-heal 2>/dev/null | tr -d '[:space:]')
if [ "${_C2T_MODE:-}" = "off" ]; then
    echo "click2talk heal: disabled via redis (click2talk-heal=off), skipping."
else
    _C2T_DIRTY=0; _C2T_TOTAL=0; _C2T_FIXED=0; _C2T_SKIPPED=0; _C2T_WOULD=0
    if [ "${_C2T_MODE:-}" = "report" ]; then
        echo "click2talk heal: report-only mode (click2talk-heal=report), no changes will be made."
    fi

    while IFS='|' read -r _c2t_domain _c2t_ext_uuid _c2t_user_uuid \
                          _c2t_ext _c2t_alias _c2t_username \
                          _c2t_peers _c2t_users _c2t_taken; do
        [ -z "${_c2t_ext_uuid:-}" ] && continue
        _C2T_TOTAL=$((_C2T_TOTAL + 1))

        # Ambiguous tenant: never auto-modify. Two different accounts sharing
        # the guest alias is how a manual pass once collapsed both onto one
        # extension and broke the tenant a second time.
        if [ "${_c2t_peers:-0}" -gt 1 ]; then
            echo "WARNING: click2talk heal: skipping $_c2t_domain, $_c2t_peers candidate guests (ext=$_c2t_ext_uuid) - needs manual review"
            _C2T_SKIPPED=$((_C2T_SKIPPED + 1)); continue
        fi
        if [ "${_c2t_users:-0}" -gt 1 ]; then
            echo "WARNING: click2talk heal: skipping $_c2t_domain, extension $_c2t_ext_uuid links to $_c2t_users users - needs manual review"
            _C2T_SKIPPED=$((_C2T_SKIPPED + 1)); continue
        fi
        if [ "${_c2t_taken:-0}" -gt 0 ]; then
            echo "WARNING: click2talk heal: skipping $_c2t_domain, extension '$_c2t_alias' already used by another enabled row - needs manual review"
            _C2T_SKIPPED=$((_C2T_SKIPPED + 1)); continue
        fi
        # Defensive: the target is interpolated into SQL below.
        if ! printf '%s' "${_c2t_alias:-}" | grep -qE '^[A-Za-z0-9._-]{1,64}$'; then
            echo "WARNING: click2talk heal: skipping $_c2t_domain, unusable number_alias for ext=$_c2t_ext_uuid"
            _C2T_SKIPPED=$((_C2T_SKIPPED + 1)); continue
        fi
        # Already consistent.
        if [ "$_c2t_ext" = "$_c2t_alias" ] && [ "$_c2t_username" = "$_c2t_alias" ]; then
            continue
        fi

        if [ "${_C2T_MODE:-}" = "report" ]; then
            echo "click2talk heal (report-only): $_c2t_domain ext=$_c2t_ext_uuid extension '$_c2t_ext' -> '$_c2t_alias', username '$_c2t_username' -> '$_c2t_alias'"
            _C2T_WOULD=$((_C2T_WOULD + 1)); continue
        fi

        if PGPASSWORD="$_DB_PASS" psql -U "ringq" -d "ringq" -h "127.0.0.1" -p "5432" -v ON_ERROR_STOP=1 -q -c "
            BEGIN;
            UPDATE public.v_extensions SET extension = '$_c2t_alias' WHERE extension_uuid = '$_c2t_ext_uuid';
            UPDATE public.v_users      SET username  = '$_c2t_alias' WHERE user_uuid      = '$_c2t_user_uuid';
            COMMIT;" >/dev/null 2>&1; then
            echo "click2talk heal: $_c2t_domain ext=$_c2t_ext_uuid extension '$_c2t_ext' -> '$_c2t_alias', username '$_c2t_username' -> '$_c2t_alias'"
            _C2T_FIXED=$((_C2T_FIXED + 1)); _C2T_DIRTY=1
        else
            echo "WARNING: click2talk heal: repair failed for $_c2t_domain ext=$_c2t_ext_uuid (rolled back, will retry next update)"
            _C2T_SKIPPED=$((_C2T_SKIPPED + 1))
        fi
    done < <(PGPASSWORD="$_DB_PASS" psql -U "ringq" -d "ringq" -h "127.0.0.1" -p "5432" -tAF'|' -c "
        SELECT d.domain_name,
               e.extension_uuid,
               eu.user_uuid,
               e.extension,
               e.number_alias,
               u.username,
               (SELECT COUNT(*) FROM public.v_extensions c
                 WHERE c.domain_uuid = e.domain_uuid
                   AND c.number_alias = '100000'
                   AND c.effective_caller_id_number = '100000'
                   AND c.enabled = 'true') AS candidates_in_domain,
               (SELECT COUNT(*) FROM public.v_extension_users x
                 WHERE x.extension_uuid = e.extension_uuid) AS linked_users,
               (SELECT COUNT(*) FROM public.v_extensions t
                 WHERE t.domain_uuid = e.domain_uuid
                   AND t.extension_uuid <> e.extension_uuid
                   AND t.enabled = 'true'
                   AND t.extension = e.number_alias) AS target_taken
          FROM public.v_extensions e
          JOIN public.v_domains d          ON d.domain_uuid     = e.domain_uuid
          JOIN public.v_extension_users eu ON eu.extension_uuid = e.extension_uuid
          JOIN public.v_users u            ON u.user_uuid       = eu.user_uuid
         WHERE e.number_alias = '100000'
           AND e.effective_caller_id_number = '100000'
           AND e.enabled = 'true'
           AND d.domain_enabled = 'true'
         ORDER BY d.domain_name
    " 2>/dev/null)

    if [ "$_C2T_DIRTY" -eq 1 ]; then
        rm -f /var/cache/ringq/directory.*
        echo "click2talk heal: repaired $_C2T_FIXED of $_C2T_TOTAL guest(s), $_C2T_SKIPPED skipped; directory cache cleared (FreeSWITCH restart below will reload)."
    elif [ "$_C2T_TOTAL" -eq 0 ]; then
        echo "click2talk heal: no click2talk guest accounts found."
    elif [ "$_C2T_WOULD" -gt 0 ]; then
        echo "click2talk heal: $_C2T_WOULD of $_C2T_TOTAL guest(s) need repair (report-only, no changes made)."
    elif [ "$_C2T_SKIPPED" -gt 0 ]; then
        echo "click2talk heal: no changes applied; $_C2T_SKIPPED of $_C2T_TOTAL guest(s) skipped for manual review."
    else
        echo "click2talk heal: no identity drift detected."
    fi
    unset _C2T_DIRTY _C2T_TOTAL _C2T_FIXED _C2T_SKIPPED _C2T_WOULD
fi
unset _C2T_MODE
# <<< END click2talk-heal <<<
