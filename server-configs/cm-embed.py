#!/usr/bin/env python3
"""
cm-embed.py -- (re)generates the "CM adjustment" block inside app/scripts/jobs.sh.

jobs.sh is the MAIN script: it ships with every update and runs after every update
(redis key restart_jobs), so the CM adjustments (formerly only in cm-install.sh) are
applied automatically on every server, plain or Docker.

The payload files below stay the editable sources. After changing any of them run:

    python3 server-configs/cm-embed.py

and commit jobs.sh together with the changed file. `--verify` exits 1 when jobs.sh is out
of date (use it in CI).
"""
import os, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
JOBS = os.path.join(ROOT, "app", "scripts", "jobs.sh")
BEGIN = "# >>> BEGIN cm-adjustments >>>"
END = "# <<< END cm-adjustments <<<"

# (payload name used inside jobs.sh, source path relative to the repo root)
PAYLOADS = [
    ("ringq_call_control.lua",       "server-configs/call-control/ringq_call_control.lua"),
    ("ringq_att_voicemail.lua",      "server-configs/call-control/ringq_att_voicemail.lua"),
    ("callcenter.conf.lua",          "server-configs/queue-strategy/callcenter.conf.lua"),
    ("q_call_center_tier_order.sql", "server-configs/queue-strategy/q_call_center_tier_order.sql"),
    ("ringq-call-control.xml",       "server-configs/call-control/ringq-call-control.xml"),
    ("ringq-trunk-media-timeout.xml","server-configs/trunk-media-timeout/dialplan.xml"),
    ("local_extension.xml",          "server-configs/call-control/local_extension.xml"),
    ("ringq-health.sh",              "server-configs/health/ringq-health.sh"),
]

LOGIC = r'''
cm_adjustments() {
    # ----------------------------------------------------------------------------------
    # CM ADJUSTMENT (added by CM / Mani, merged from server-configs/cm-install.sh)
    # Installs / repairs the server-side pieces of the RingQ fixes that the code update
    # does not install by itself: FreeSWITCH scripts, global dialplans (incl. repairing
    # local_extension after an update reset it to stock), database tables, the health
    # check and the nginx LAN-provisioning route.
    # Idempotent: every step checks first and only changes what is missing or wrong;
    # replaced files are backed up to /var/backups/ringq-cm-install/<time>/.
    # Args: --check (report only, return 1 if anything is off)  --force (also replace a
    # customised local_extension).  Log: /var/log/ringq-cm-install.log
    # The payloads are embedded below; their editable sources live in server-configs/ and
    # node/ -- regenerate this block with: python3 server-configs/cm-embed.py
    # ----------------------------------------------------------------------------------
    local CM_CHECK=0 CM_FORCE=0 a
    for a in "$@"; do case "$a" in --check) CM_CHECK=1 ;; --force) CM_FORCE=1 ;; esac; done
    local CM_LOG=/var/log/ringq-cm-install.log
    local CM_BK=/var/backups/ringq-cm-install/$(date +%Y%m%d-%H%M%S)
    local CM_DB=${RINGQ_DB:-ringq}
    local CM_FS=/usr/share/freeswitch/scripts
    local CM_CC=$CM_FS/app/xml_handler/resources/scripts/configuration/callcenter.conf.lua
    local CM_STOCK_LE=8d3652066503ef7b086cbbee62d8a4ad   # FusionPBX stock local_extension
    local CM_OLD_LE="27ad8f6e963afa3d6d175fd936173004"   # earlier RingQ local_extension versions (upgraded)
    local CM_OK=0 CM_CHG=0 CM_WARN=0 CM_FAIL=0 CM_DP=0
    local CM_SRC; CM_SRC=$(mktemp -d /tmp/ringq-cm.XXXXXX) || return 2

    cm_say()  { echo "[cm] $*"; echo "$(date '+%F %T') $*" >> "$CM_LOG" 2>/dev/null; }
    cm_ok()   { CM_OK=$((CM_OK+1)); cm_say "  OK       $*"; }
    cm_chg()  { CM_CHG=$((CM_CHG+1)); if [ $CM_CHECK = 1 ]; then cm_say "  NEEDED   $*"; else cm_say "  FIXED    $*"; fi; }
    cm_warn() { CM_WARN=$((CM_WARN+1)); cm_say "  WARNING  $*"; }
    cm_fail() { CM_FAIL=$((CM_FAIL+1)); cm_say "  FAILED   $*"; }
    cm_backup() { [ -e "$1" ] && mkdir -p "$CM_BK" && cp -a "$1" "$CM_BK/$(echo "$1" | tr / _)"; }
    cm_psql() { su postgres -s /bin/sh -c "cd /tmp && psql -X -q -At -d $CM_DB $*"; }
    cm_md5()  { md5sum < "$1" | cut -c1-32; }
    # Run "<before> <xml-literal> <after>" with the file read by ROOT and passed on stdin.
    # (The old `psql -v x="$(cat file)"` ran cat as the postgres user, which cannot read
    # the 0700 temp dir: x became empty and the dialplan was saved EMPTY.) Refuses an
    # empty or unreadable file so a dialplan can never be blanked.
    cm_sql_xml() {  # file before after
        local file="$1" before="$2" after="$3"
        [ -s "$file" ] || { echo "cm_sql_xml: $file missing or empty" >> "$CM_LOG"; return 1; }
        grep -q 'rqcmx' "$file" && { echo "cm_sql_xml: $file contains the quote tag" >> "$CM_LOG"; return 1; }
        { printf '%s $rqcmx$' "$before"; cat "$file"; printf '$rqcmx$ %s\n' "$after"; } |
            su postgres -s /bin/sh -c "cd /tmp && psql -X -q -v ON_ERROR_STOP=1 -d $CM_DB" >/dev/null 2>>"$CM_LOG"
    }

    cm_payloads "$CM_SRC" || { cm_fail "could not unpack the embedded payloads"; rm -rf "$CM_SRC"; return 2; }
    cm_say "== CM adjustments $( [ $CM_CHECK = 1 ] && echo '(check only)' ) on $(hostname) $(date '+%F %T')"

    # ---- 1. FreeSWITCH scripts ---------------------------------------------------------
    cm_file() {  # payload dest mode label
        local src="$CM_SRC/$1" dest="$2" mode="$3" label="$4"
        if [ -f "$dest" ] && [ "$(cm_md5 "$src")" = "$(cm_md5 "$dest")" ]; then cm_ok "$label"; return; fi
        if [ $CM_CHECK = 1 ]; then cm_chg "$label ($dest)"; return; fi
        cm_backup "$dest"; mkdir -p "$(dirname "$dest")"
        if install -m "$mode" "$src" "$dest"; then chown www-data:www-data "$dest" 2>/dev/null; cm_chg "$label ($dest)"; else cm_fail "$label"; fi
    }
    cm_say "-- FreeSWITCH scripts"
    cm_file ringq_call_control.lua "$CM_FS/ringq_call_control.lua" 644 "Call Control script"
    cm_file ringq_att_voicemail.lua "$CM_FS/ringq_att_voicemail.lua" 644 "Attended transfer voicemail script"
    local CM_CC_BEFORE CM_CC_AFTER
    CM_CC_BEFORE=$( [ -f "$CM_CC" ] && cm_md5 "$CM_CC" )
    cm_file callcenter.conf.lua "$CM_CC" 644 "Queue strategies (callcenter.conf.lua)"
    CM_CC_AFTER=$( [ -f "$CM_CC" ] && cm_md5 "$CM_CC" )

    # ---- 2. database tables (CREATE / ADD COLUMN IF NOT EXISTS: always safe) ----------
    cm_say "-- Database tables"
    local t name file exists
    for t in q_call_center_tier_order:q_call_center_tier_order.sql; do  # other tables (q_call_journeys...): server-configs/queries.sql
        name=${t%%:*}; file=$CM_SRC/${t#*:}
        exists=$(cm_psql "-c \"select to_regclass('$name') is not null\"")
        if [ $CM_CHECK = 1 ]; then [ "$exists" = "t" ] && cm_ok "$name" || cm_chg "$name table"; continue; fi
        if su postgres -s /bin/sh -c "cd /tmp && psql -X -q -v ON_ERROR_STOP=1 -d $CM_DB" < "$file" >/dev/null 2>>"$CM_LOG"; then
            [ "$exists" = "t" ] && cm_ok "$name" || cm_chg "$name table created"
        else cm_fail "$name"; fi
    done
    # Columns the server code reads; same definitions as server-configs/queries.sql, which the
    # update does not apply (SG96 9.2.0.52: provisionhosts failed on tunnel_config.router_phone).
    local tbl col def
    for t in "tunnel_config|router_phone|boolean NOT NULL DEFAULT false"; do
        tbl=${t%%|*}; t=${t#*|}; col=${t%%|*}; def=${t#*|}
        [ "$(cm_psql "-c \"select to_regclass('public.$tbl') is not null\"")" = "t" ] || continue
        if [ "$(cm_psql "-c \"select count(*) from information_schema.columns where table_schema='public' and table_name='$tbl' and column_name='$col'\"")" = "1" ]; then
            cm_ok "$tbl.$col"; continue
        fi
        if [ $CM_CHECK = 1 ]; then cm_chg "$tbl.$col column"; continue; fi
        if cm_psql "-c \"ALTER TABLE public.$tbl ADD COLUMN IF NOT EXISTS $col $def\"" >/dev/null 2>>"$CM_LOG"; then
            cm_chg "$tbl.$col column added"
        else cm_fail "$tbl.$col column"; fi
    done
    local owner
    for name in q_call_journeys q_call_center_tier_order; do
        owner=$(cm_psql "-c \"select tableowner from pg_tables where schemaname='public' and tablename='$name'\"")
        [ -n "$owner" ] || continue
        [ "$(cm_psql "-c \"select count(*) from pg_roles where rolname='ringq'\"")" = "1" ] || continue
        if [ "$owner" = "ringq" ]; then cm_ok "$name owner"; continue; fi
        if [ $CM_CHECK = 1 ]; then cm_chg "$name owner ($owner)"; continue; fi
        if cm_psql "-c \"ALTER TABLE public.$name OWNER TO ringq\"" >/dev/null 2>>"$CM_LOG"; then
            cm_chg "$name owner ($owner -> ringq)"
        else cm_fail "$name owner"; fi
    done

    # ---- 3. global dialplans ---------------------------------------------------------------
    cm_say "-- Dialplans"
    cm_dialplan() {  # name uuid payload order continue description
        local name="$1" uuid="$2" file="$CM_SRC/$3" order="$4" cont="$5" desc="$6" want have
        want=$(cm_md5 "$file")
        have=$(cm_psql "-c \"select md5(dialplan_xml) from v_dialplans where dialplan_name='$name' and domain_uuid is null limit 1\"")
        if [ "$have" = "$want" ]; then cm_ok "$name"; return; fi
        if [ $CM_CHECK = 1 ]; then cm_chg "$name dialplan"; return; fi
        if [ -z "$have" ]; then
            cm_sql_xml "$file" "insert into v_dialplans (domain_uuid,dialplan_uuid,app_uuid,dialplan_context,dialplan_name,dialplan_number,dialplan_continue,dialplan_xml,dialplan_order,dialplan_enabled,dialplan_description,insert_date) values (null,'$uuid','$uuid','global','$name','','$cont'," ",$order,'true','$desc',now());"
        else
            cm_sql_xml "$file" "update v_dialplans set dialplan_xml =" ", dialplan_enabled = 'true', update_date = now() where dialplan_name = '$name' and domain_uuid is null;"
        fi
        if [ "$(cm_psql "-c \"select md5(dialplan_xml) from v_dialplans where dialplan_name='$name' and domain_uuid is null limit 1\"")" = "$want" ]; then
            cm_chg "$name dialplan"; CM_DP=1
        else cm_fail "$name dialplan"; fi
    }
    cm_dialplan ringq-call-control e2dc2aa5-3d68-4a46-b17d-c0085da71330 ringq-call-control.xml 36 true \
        "RingQ Call Control per call profile (forwarding, relay, parallel ring, screening)"
    cm_dialplan ringq-trunk-media-timeout c0d47518-70a7-4cbe-b71c-224f576fa7cd ringq-trunk-media-timeout.xml 37 true \
        "End trunk calls 10 s after the carrier stops sending audio (late BYE)"

    # local_extension: the central update resets it to the FusionPBX stock version. Only the
    # stock version, or an earlier RingQ version (CM_OLD_LE), is replaced (or any version with --force);
    # a customised one is left alone.
    local CM_LE_FILE=$CM_SRC/local_extension.xml CM_LE_WANT CM_LE_HAVE
    CM_LE_WANT=$(cm_md5 "$CM_LE_FILE")
    CM_LE_HAVE=$(cm_psql "-c \"select md5(dialplan_xml) from v_dialplans where dialplan_name='local_extension' limit 1\"")
    local CM_LE_OLD=0; case " $CM_OLD_LE " in *" $CM_LE_HAVE "*) CM_LE_OLD=1 ;; esac
    if [ "$CM_LE_HAVE" = "$CM_LE_WANT" ]; then cm_ok "local_extension (Call Control screening / parallel ring / failover)"
    elif [ -z "$CM_LE_HAVE" ]; then cm_warn "local_extension dialplan not found"
    elif [ "$CM_LE_HAVE" != "$CM_STOCK_LE" ] && [ $CM_LE_OLD = 0 ] && [ "$CM_LE_HAVE" != "d41d8cd98f00b204e9800998ecf8427e" ] && [ $CM_FORCE = 0 ]; then
        cm_warn "local_extension was customised on this server - not replaced (use --force to replace)"
    elif [ $CM_CHECK = 1 ]; then cm_chg "local_extension (stock after an update, or an earlier RingQ version)"
    else
        mkdir -p "$CM_BK" && cm_psql "-c \"select dialplan_xml from v_dialplans where dialplan_name='local_extension' limit 1\"" > "$CM_BK/local_extension.before.xml"
        cm_sql_xml "$CM_LE_FILE" "update v_dialplans set dialplan_xml =" ", update_date = now() where dialplan_name = 'local_extension';"
        if [ "$(cm_psql "-c \"select md5(dialplan_xml) from v_dialplans where dialplan_name='local_extension' limit 1\"")" = "$CM_LE_WANT" ]; then
            cm_chg "local_extension restored (Call Control screening / parallel ring / failover)"; CM_DP=1
        else cm_fail "local_extension"; fi
    fi

    # ---- 3a. ring push: NOT managed here --------------------------------------------------
    # app/scripts/ring-push/ringq-push-setup.sh owns it (mod_ringq, ringq_ring_dial.lua, the
    # directory.lua hook, the ring_push_mode setting) and jobs.sh runs it on every update. The
    # old loopback install that lived here re-installed the loopback dial hook and dialplan
    # after that script on every update, so mod_ringq was never used. Do not re-add it.

    # ---- 3b. inbound DIDs: accept an optional leading "+" ---------------------------------
    # Carriers send the called number as 6563401010 or +6563401010. Routes whose pattern is
    # only digits (^6563401010$ / ^(6563401010)$) reject the "+" form, so they are widened to
    # ^\+?6563401010$. Only public-context routes / inbound destinations made of plain digits
    # are touched; anything with other regex is left alone.
    cm_say "-- Inbound DIDs (optional +)"
    local CM_DID_N
    CM_DID_N=$(su postgres -s /bin/sh -c "cd /tmp && psql -X -At -d $CM_DB" <<'CMDIDQ'
select (select count(*) from v_dialplans where dialplan_context = 'public'
          and dialplan_xml ~ 'field="[$]?[{]?destination_number[}]?" expression="\^\(?[0-9]+\)?\$"')
     + (select count(*) from v_destinations where destination_type = 'inbound'
          and destination_number_regex ~ '^\^\(?[0-9]+\)?\$$');
CMDIDQ
)
    if [ -z "$CM_DID_N" ]; then cm_warn "inbound DIDs: could not read the routes"
    elif [ "$CM_DID_N" = "0" ]; then cm_ok "inbound DIDs accept +"
    elif [ $CM_CHECK = 1 ]; then cm_chg "inbound DIDs without optional + ($CM_DID_N)"
    else
        mkdir -p "$CM_BK" && su postgres -s /bin/sh -c "cd /tmp && psql -X -At -d $CM_DB -c \"select dialplan_uuid, dialplan_xml from v_dialplans where dialplan_context = 'public'\"" > "$CM_BK/public-dialplans.before.txt" 2>/dev/null
        if su postgres -s /bin/sh -c "cd /tmp && psql -X -q -v ON_ERROR_STOP=1 -d $CM_DB" >/dev/null 2>>"$CM_LOG" <<'CMDIDFIX'
begin;
update v_dialplans
   set dialplan_xml = regexp_replace(dialplan_xml,
         '(field="[$]?[{]?destination_number[}]?" expression=")\^\(?([0-9]+)\)?\$"',
         '\1^\\+?\2$"', 'g'),
       update_date = now()
 where dialplan_context = 'public'
   and dialplan_xml ~ 'field="[$]?[{]?destination_number[}]?" expression="\^\(?[0-9]+\)?\$"';
update v_destinations
   set destination_number_regex = '^\+?' || substring(destination_number_regex from '[0-9]+') || '$'
 where destination_type = 'inbound'
   and destination_number_regex ~ '^\^\(?[0-9]+\)?\$$';
commit;
CMDIDFIX
        then cm_chg "inbound DIDs now accept an optional + ($CM_DID_N)"; CM_DP=1
        else cm_fail "inbound DIDs optional +"; fi
    fi

    # ---- 3c. ringback tone -----------------------------------------------------------------
    # vars.xml / v_vars set ringback=$${us-ring}, but us-ring is defined further down the
    # file, so at parse time ringback (and transfer_ringback) end up EMPTY. Outbound calls
    # use ignore_early_media + instant_ringback, so callers hear silence until answer.
    # Write the tone itself (the value of us-ring) instead of the forward reference, in the
    # database, in vars.xml, and live with global_setvar (no restart, calls unaffected).
    cm_say "-- Ringback tone"
    local CM_RING CM_VARS=/etc/freeswitch/vars.xml CM_RB_BAD=0 CM_RB_LIVE
    CM_RING=$(fs_cli -x 'global_getvar us-ring' 2>/dev/null | tr -d '\r\n')
    case "$CM_RING" in %\(*\)) ;; *) CM_RING='%(2000,4000,440,480)' ;; esac
    if [ "$(su postgres -s /bin/sh -c "cd /tmp && psql -X -At -d $CM_DB -c \"select count(*) from v_vars where var_name in ('ringback','transfer_ringback') and var_value like '%us-ring%'\"" 2>/dev/null)" != "0" ]; then CM_RB_BAD=1; fi
    [ -f "$CM_VARS" ] && grep -q 'ringback=\$\${us-ring}' "$CM_VARS" && CM_RB_BAD=1
    CM_RB_LIVE=$(fs_cli -x 'global_getvar ringback' 2>/dev/null | tr -d '\r\n')
    case "$CM_RB_LIVE" in %\(*\)|*.wav|*.mp3|local_stream*) ;; *) CM_RB_BAD=1 ;; esac
    if [ $CM_RB_BAD = 0 ]; then cm_ok "Ringback tone ($CM_RB_LIVE)"
    elif [ $CM_CHECK = 1 ]; then cm_chg "Ringback tone is empty (callers hear silence while ringing)"
    else
        cm_backup "$CM_VARS"
        su postgres -s /bin/sh -c "cd /tmp && psql -X -q -d $CM_DB -c \"update v_vars set var_value = '$CM_RING' where var_name in ('ringback','transfer_ringback') and var_value like '%us-ring%'\"" >/dev/null 2>>"$CM_LOG"
        [ -f "$CM_VARS" ] && sed -i "s|data=\"ringback=\\\$\\\$[{]us-ring[}]\"|data=\"ringback=$CM_RING\"|; s|data=\"transfer_ringback=\\\$\\\$[{]us-ring[}]\"|data=\"transfer_ringback=$CM_RING\"|" "$CM_VARS"
        fs_cli -x "global_setvar ringback=$CM_RING" >/dev/null 2>&1
        fs_cli -x "global_setvar transfer_ringback=$CM_RING" >/dev/null 2>&1
        CM_RB_LIVE=$(fs_cli -x 'global_getvar ringback' 2>/dev/null | tr -d '\r\n')
        if [ "$CM_RB_LIVE" = "$CM_RING" ] && ! grep -q 'ringback=\$\${us-ring}' "$CM_VARS" 2>/dev/null; then
            cm_chg "Ringback tone set to $CM_RING (outbound calls now ring)"
        else cm_fail "Ringback tone (live: '$CM_RB_LIVE')"; fi
    fi

    # ---- 3d. FreeSWITCH module trim ----------------------------------------------------------
    # Modules no RingQ server uses (audited on apple, US6, SG96, Rising Stream: no dialplan,
    # language, codec or feature needs them). Removed from modules.conf.xml and v_modules (so
    # jobs.sh's FreeSWITCH reinstall no longer installs them), unloaded live and their packages
    # removed, plus libfreeswitch-dev. Tested on apple 2026-10-04.
    cm_say "-- FreeSWITCH module trim"
    local CM_TRIM="mod_say_zh mod_say_ru mod_say_fr mod_say_th mod_say_he mod_say_pt mod_say_de mod_say_it mod_say_nl mod_say_es mod_say_hu mod_flite mod_tts_commandline mod_bv mod_amr mod_g723_1 mod_fsv mod_h26x mod_esf mod_enum mod_valet_parking"
    local CM_MODCONF=/etc/freeswitch/autoload_configs/modules.conf.xml CM_TM CM_TCONF="" CM_TPKG="" CM_TP
    for CM_TM in $CM_TRIM; do
        grep -q "<load module=\"$CM_TM\"/>" "$CM_MODCONF" 2>/dev/null && CM_TCONF="$CM_TCONF $CM_TM"
        CM_TP="freeswitch-$(echo $CM_TM | tr '_' '-')"
        dpkg-query -W -f='${Status}' "$CM_TP" 2>/dev/null | grep -q "install ok installed" && CM_TPKG="$CM_TPKG $CM_TP"
    done
    dpkg-query -W -f='${Status}' libfreeswitch-dev 2>/dev/null | grep -q "install ok installed" && CM_TPKG="$CM_TPKG libfreeswitch-dev"
    if [ -z "$CM_TCONF$CM_TPKG" ]; then cm_ok "FreeSWITCH modules trimmed"
    elif [ $CM_CHECK = 1 ]; then cm_chg "FreeSWITCH module trim ($(echo $CM_TCONF | wc -w) in modules.conf, $(echo $CM_TPKG | wc -w) packages)"
    else
        cm_backup "$CM_MODCONF"
        for CM_TM in $CM_TCONF; do sed -i "/<load module=\"$CM_TM\"\/>/d" "$CM_MODCONF"; done
        su postgres -s /bin/sh -c "cd /tmp && psql -X -q -d $CM_DB -c \"update v_modules set module_enabled='false' where module_name in ($(echo $CM_TRIM | sed "s/[^ ]*/'&'/g; s/ /,/g"))\"" >/dev/null 2>>"$CM_LOG"
        for CM_TM in $CM_TRIM; do fs_cli -x "unload $CM_TM" >/dev/null 2>&1; done
        if [ -n "$CM_TPKG" ]; then
            apt-mark unhold $CM_TPKG >/dev/null 2>&1
            DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 remove -y $CM_TPKG >>"$CM_LOG" 2>&1
        fi
        CM_TCONF=""; for CM_TM in $CM_TRIM; do grep -q "<load module=\"$CM_TM\"/>" "$CM_MODCONF" && CM_TCONF="$CM_TCONF $CM_TM"; done
        if [ -z "$CM_TCONF" ] && fs_cli -x "module_exists mod_sofia" 2>/dev/null | grep -q true; then
            cm_chg "FreeSWITCH modules trimmed ($(grep -c '<load module' "$CM_MODCONF") left in modules.conf)"
        else cm_fail "FreeSWITCH module trim (left:$CM_TCONF)"; fi
    fi

    # ---- 3e. call log speed: indexes on q_ringq_cdr ------------------------------------------
    # /api/history (call logs in web/mobile apps) runs 3 queries with OR over caller/destination/
    # source/agent per page; without indexes each one scanned the whole call history.
    # Built CONCURRENTLY: no table lock, calls keep being written.
    cm_say "-- Call log indexes"
    local CM_IX CM_IXN CM_IXMISS=0
    for CM_IX in "q_ringq_cdr_dom_insert_idx:(domain_uuid, insert_date DESC)" "q_ringq_cdr_dom_dest_idx:(domain_uuid, caller_destination)" "q_ringq_cdr_dom_cid_idx:(domain_uuid, caller_id_number)" "q_ringq_cdr_dom_src_idx:(domain_uuid, source_number)" "q_ringq_cdr_ccagent_idx:(cc_agent)"; do
        CM_IXN=${CM_IX%%:*}
        [ "$(cm_psql "-c \"select count(*) from pg_indexes where indexname='$CM_IXN'\"")" = "1" ] && continue
        CM_IXMISS=$((CM_IXMISS+1))
        [ $CM_CHECK = 1 ] && continue
        su postgres -s /bin/sh -c "cd /tmp && psql -X -q -d $CM_DB -c \"CREATE INDEX CONCURRENTLY IF NOT EXISTS $CM_IXN ON q_ringq_cdr ${CM_IX#*:}\"" >/dev/null 2>>"$CM_LOG"
    done
    if [ $CM_IXMISS = 0 ]; then cm_ok "Call log indexes"
    elif [ $CM_CHECK = 1 ]; then cm_chg "Call log indexes ($CM_IXMISS missing)"
    elif [ "$(cm_psql "-c \"select count(*) from pg_indexes where tablename='q_ringq_cdr' and indexname like 'q_ringq_cdr_%_idx'\"")" -ge 5 ]; then
        cm_psql "-c \"analyze q_ringq_cdr\"" >/dev/null; cm_chg "Call log indexes ($CM_IXMISS created)"
    else cm_fail "Call log indexes"; fi

    # ---- 3f. call records right after hang-up ------------------------------------------------
    # Node pushes each finished call into Redis (callLogs-<domain>); the calldetailrecord job
    # saved them only every ~6 s, so apps reloading right after a call missed it. A tiny
    # watcher starts the job as soon as something is waiting. The 5 s timer stays as fallback.
    cm_say "-- Instant call records"
    local CM_W=/usr/local/sbin/ringq-cdr-trigger CM_WU=/etc/systemd/system/ringq-cdr-trigger.service
    if [ -x "$CM_W" ] && grep -q 'ringq-cdr-trigger v2' "$CM_W" 2>/dev/null && [ -f "$CM_WU" ] && systemctl is-active --quiet ringq-cdr-trigger 2>/dev/null; then cm_ok "Instant call records (watcher running)"
    elif ! systemctl cat calldetailrecord.service >/dev/null 2>&1; then cm_warn "calldetailrecord.service not found - watcher skipped"
    elif [ $CM_CHECK = 1 ]; then cm_chg "Instant call records watcher"
    else
        cat > "$CM_W" <<'CMW'
#!/bin/bash
# CM adjustment: call records appear right after hang-up instead of up to 6 s later.
# Watches the Redis queue Node fills on hang-up (callLogs-<domain>) and starts the existing
# calldetailrecord job as soon as something is waiting. The 5 s timer stays as the fallback.
# ringq-cdr-trigger v2: the queue names (callLogs-<domain>) come from the domain list, read once a
# minute; v1 SCANned the whole keyspace twice a second (Redis at 100% CPU on servers with 500k keys).
keys=""; t=0
while true; do
  now=$(date +%s)
  if [ $((now - t)) -ge 60 ] || [ -z "$keys" ]; then
    keys=$(su postgres -s /bin/sh -c "cd /tmp && psql -X -At -d ${RINGQ_DB:-ringq} -c \"select 'callLogs-' || domain_name from v_domains\"" 2>/dev/null)
    t=$now
  fi
  if [ -n "$keys" ]; then
    for n in $(printf '%s\n' $keys | sed 's/^/LLEN /' | redis-cli 2>/dev/null); do
      if [ "${n:-0}" -gt 0 ] 2>/dev/null; then systemctl start calldetailrecord.service; break; fi
    done
  fi
  sleep 0.5
done
CMW
        chmod 755 "$CM_W"
        printf '[Unit]\nDescription=RingQ: write call records right after hang-up (CM adjustment)\nAfter=redis-server.service\n[Service]\nExecStart=%s\nRestart=always\nRestartSec=2\nNice=5\n[Install]\nWantedBy=multi-user.target\n' "$CM_W" > "$CM_WU"
        systemctl daemon-reload; systemctl enable ringq-cdr-trigger.service >/dev/null 2>&1; systemctl restart ringq-cdr-trigger.service >/dev/null 2>&1
        if systemctl is-active --quiet ringq-cdr-trigger; then cm_chg "Instant call records watcher started"; else cm_fail "Instant call records watcher"; fi
    fi

    # ---- 3g. light housekeeping (CPU / memory) ---------------------------------------------------
    # calldetailrecord ran every 5 s as a full PHP process; the watcher above starts it right after
    # each hang-up, so the timer is only a fallback now: every 30 s. journald capped at 200 MB.
    cm_say "-- Housekeeping"
    if systemctl is-active --quiet ringq-cdr-trigger 2>/dev/null && systemctl cat calldetailrecord.timer >/dev/null 2>&1; then
        local CM_TD=/etc/systemd/system/calldetailrecord.timer.d
        if grep -qs 'OnUnitActiveSec=30s' "$CM_TD/ringq.conf"; then cm_ok "Call records fallback timer (30 s)"
        elif [ $CM_CHECK = 1 ]; then cm_chg "Call records fallback timer"
        else
            mkdir -p "$CM_TD" && printf '[Timer]\nOnUnitActiveSec=\nOnUnitActiveSec=30s\n' > "$CM_TD/ringq.conf"
            systemctl daemon-reload; systemctl restart calldetailrecord.timer >/dev/null 2>&1
            cm_chg "Call records fallback timer set to 30 s"
        fi
    fi
    # ClamAV daemon off (~1 GB RAM per server, decided 2026-10-05): RingQ Meet attachments are then
    # accepted after Meet's own file checks (MEET_CLAMDSCAN_BIN=off, node/meet/attachments.js).
    local CM_MENV=/etc/ringq/meet-control-plane.env CM_AV=0
    local CM_MATT=/opt/ringq-meet-control-plane/current/attachments.js
    if [ -f "$CM_MENV" ] && ! grep -qs 'scanEnabled' "$CM_MATT"; then
        if ! grep -q '^MEET_CLAMDSCAN_BIN=off$' "$CM_MENV"; then cm_ok "Antivirus daemon kept (the running Meet release needs clamd)"
        elif [ $CM_CHECK = 1 ]; then cm_chg "MEET_CLAMDSCAN_BIN=off but the running Meet release cannot skip the scan"
        else
            cm_backup "$CM_MENV"
            sed -i 's|^MEET_CLAMDSCAN_BIN=.*|MEET_CLAMDSCAN_BIN=/usr/bin/clamdscan|' "$CM_MENV"
            systemctl enable --now clamav-daemon clamav-freshclam >/dev/null 2>&1
            command -v pm2 >/dev/null 2>&1 && pm2 restart meeting >/dev/null 2>&1
            cm_chg "Antivirus daemon (clamd) restored; the running Meet release cannot skip the scan"
        fi
    else
        systemctl is-enabled --quiet clamav-daemon 2>/dev/null && CM_AV=1
        systemctl is-active --quiet clamav-daemon 2>/dev/null && CM_AV=1
        if [ -f "$CM_MENV" ] && ! grep -q '^MEET_CLAMDSCAN_BIN=off$' "$CM_MENV"; then CM_AV=1; fi
        if [ $CM_AV = 0 ]; then cm_ok "Antivirus daemon off"
        elif [ $CM_CHECK = 1 ]; then cm_chg "Antivirus daemon (clamd) still running"
        else
            if [ -f "$CM_MENV" ]; then
                cm_backup "$CM_MENV"
                if grep -q '^MEET_CLAMDSCAN_BIN=' "$CM_MENV"; then sed -i 's|^MEET_CLAMDSCAN_BIN=.*|MEET_CLAMDSCAN_BIN=off|' "$CM_MENV"
                else echo 'MEET_CLAMDSCAN_BIN=off' >> "$CM_MENV"; fi
            fi
            systemctl disable --now clamav-daemon clamav-freshclam >/dev/null 2>&1
            pkill -x clamd >/dev/null 2>&1
            command -v pm2 >/dev/null 2>&1 && pm2 restart meeting >/dev/null 2>&1   # re-read MEET_CLAMDSCAN_BIN
            cm_chg "Antivirus daemon (clamd) stopped and disabled; Meet attachments use built-in checks"
        fi
    fi
    # Redis keys that were written without an expiry (one per call / per web-app sign-in; apple had
    # 550k+). New ones get a TTL in node/server.js + function.js; this gives the existing ones the
    # same TTL once: call keys 2 days, registration keys 7 days (renewed while the device stays on).
    if [ "$(redis-cli get ringq:ttl-sweep:v1 2>/dev/null)" = "1" ]; then cm_ok "Redis key expiry sweep"
    elif [ $CM_CHECK = 1 ]; then cm_chg "Redis key expiry sweep"
    else
        local CM_TTLN
        CM_TTLN=$(redis-cli --scan --count 5000 2>/dev/null | awk '
            /-internalCall$/ || /^lastActiveUid-/ || /^duration-/ || /^missedPayload-/ { t=172800 }
            /^ChannelSipURI-/ || /^ConferenceData-/ || /^contactSIP-/ || /-contact$/ || /-deviceIds$/ || /^deviceUID-/ { t=604800 }
            t { gsub(/\\/, "\\\\"); gsub(/"/, "\\\""); printf "EXPIRE \"%s\" %d\n", $0, t; n++; t=0 }
            END { print n+0 > "/tmp/ringq-ttl-sweep.count" }' | redis-cli >/dev/null 2>&1; cat /tmp/ringq-ttl-sweep.count 2>/dev/null)
        rm -f /tmp/ringq-ttl-sweep.count
        redis-cli set ringq:ttl-sweep:v1 1 >/dev/null 2>&1
        cm_chg "Redis key expiry set on ${CM_TTLN:-0} existing keys"
    fi
    local CM_JD=/etc/systemd/journald.conf.d
    if grep -qs '^SystemMaxUse=200M' "$CM_JD/ringq.conf"; then cm_ok "Journal size cap"
    elif [ $CM_CHECK = 1 ]; then cm_chg "Journal size cap"
    else
        mkdir -p "$CM_JD" && printf '[Journal]\nSystemMaxUse=200M\n' > "$CM_JD/ringq.conf"
        systemctl restart systemd-journald >/dev/null 2>&1; journalctl --vacuum-size=200M >/dev/null 2>&1
        cm_chg "Journal capped at 200 MB"
    fi

    # ---- 4. health check -----------------------------------------------------------------
    cm_say "-- Health check"
    cm_file ringq-health.sh /usr/local/sbin/ringq-health.sh 755 "Health check script"
    if [ -f /etc/cron.d/ringq-health ] && grep -q '/usr/local/sbin/ringq-health.sh' /etc/cron.d/ringq-health; then cm_ok "Health check schedule"
    elif [ $CM_CHECK = 1 ]; then cm_chg "Health check schedule"
    else echo '* * * * * root /usr/local/sbin/ringq-health.sh' > /etc/cron.d/ringq-health && chmod 644 /etc/cron.d/ringq-health && cm_chg "Health check schedule (every minute)"; fi

    # ---- 5. nginx: LAN provisioning route (same block as server-configs/ringq.txt) ----------
    cm_say "-- nginx"
    local CM_NGX=/etc/nginx/sites-available/ringq
    if [ ! -f "$CM_NGX" ]; then cm_warn "nginx site 'ringq' not found - LAN provisioning route skipped"
    elif grep -qF 'location ~ "^/provision/[0-9A-Fa-f]{12}(/|$)"' "$CM_NGX"; then cm_ok "LAN provisioning route (http, private networks only, any tenant key)"
    elif [ $CM_CHECK = 1 ]; then cm_chg "LAN provisioning route"
    elif grep -q 'location ^~ /provision/2db3f557e1d6/' "$CM_NGX"; then
        # upgrade the first version (one fixed key) to any tenant key: http://<LAN-IP>/provision/<tenant-key>/
        cm_backup "$CM_NGX"
        sed -i 's#location ^~ /provision/2db3f557e1d6/ {#location ~ "^/provision/[0-9A-Fa-f]{12}(/|$)" {#' "$CM_NGX"
        if nginx -t >/dev/null 2>&1; then nginx -s reload 2>/dev/null || systemctl reload nginx; cm_chg "LAN provisioning route (now any tenant key)"
        else cp -a "$CM_BK/$(echo "$CM_NGX" | tr / _)" "$CM_NGX"; cm_fail "LAN provisioning route upgrade (nginx -t failed - restored)"; fi
    else
        cm_backup "$CM_NGX"
        if python3 - "$CM_NGX" <<'CMPY'
import sys
p = sys.argv[1]; s = open(p).read()
block = """
	# RingQ local-LAN auto provisioning (CM adjustment): phones on the customer LAN fetch
	# their config from http://<server-LAN-IP>/provision/<tenant-key>/[<mac>.cfg]. Private
	# source addresses only.
	location ~ "^/provision/[0-9A-Fa-f]{12}(/|$)" {
		allow 10.0.0.0/8;
		allow 172.16.0.0/12;
		allow 192.168.0.0/16;
		allow 127.0.0.1;
		deny all;
		include fastcgi_params;
		fastcgi_param SCRIPT_FILENAME /var/www/ringq/public/index.php;
		fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
	}
"""
i = s.index('listen 80;')
anchor = 'alias /var/www/dehydrated;\n\t}\n'
j = s.index(anchor, i) + len(anchor)
open(p, 'w').write(s[:j] + block + s[j:])
CMPY
        then
            if nginx -t >/dev/null 2>&1; then nginx -s reload 2>/dev/null || systemctl reload nginx; cm_chg "LAN provisioning route"
            else cp -a "$CM_BK/$(echo "$CM_NGX" | tr / _)" "$CM_NGX"; cm_fail "LAN provisioning route (nginx -t failed - restored)"; fi
        else cm_warn "LAN provisioning route: could not find the port-80 block - skipped"; fi
    fi

    # ---- 6. apply --------------------------------------------------------------------------
    if [ $CM_CHECK = 0 ]; then
        if [ $CM_DP = 1 ] || [ "$CM_CC_BEFORE" != "$CM_CC_AFTER" ]; then
            rm -f /var/cache/ringq/dialplan.* /var/cache/fusionpbx/dialplan.* /var/cache/ringq/configuration.callcenter* 2>/dev/null
            fs_cli -x reloadxml >/dev/null 2>&1 && cm_say "  reloaded FreeSWITCH XML (no calls affected)"
        fi
        mkdir -p /var/lib/ringq-cm-install && date '+%F %T' > /var/lib/ringq-cm-install/last-run
    fi
    rm -rf "$CM_SRC"
    cm_say "== CM adjustments done: $CM_OK ok, $CM_CHG $( [ $CM_CHECK = 1 ] && echo 'to fix' || echo 'fixed'), $CM_WARN warning(s), $CM_FAIL failed$( [ -d "$CM_BK" ] && echo "  (backups: $CM_BK)")"
    [ $CM_FAIL -gt 0 ] && return 2
    [ $CM_CHECK = 1 ] && [ $CM_CHG -gt 0 ] && return 1
    return 0
}
'''


def build_block():
    out = [BEGIN,
           "# ======================================================================================",
           "# CM ADJUSTMENT -- added by CM (Mani). Merged here from server-configs/cm-install.sh so",
           "# every update applies it automatically (it runs inside the restart_jobs block below).",
           "# GENERATED by server-configs/cm-embed.py -- do not edit by hand; edit the source files",
           "# listed in cm_payloads and re-run the generator.",
           "# Manual run:  bash /var/www/ringq/app/scripts/jobs.sh --cm-adjust [--check] [--force]",
           "# ======================================================================================",
           "cm_payloads() {  # unpack the embedded files into directory $1",
           '    local d="$1"']
    for name, rel in PAYLOADS:
        data = open(os.path.join(ROOT, rel), encoding="utf-8").read()
        tag = "CM_EOF_" + "".join(c if c.isalnum() else "_" for c in name).upper()
        assert tag not in data, tag
        out.append(f"    # source: {rel}")
        out.append(f"    cat > \"$d/{name}\" <<'{tag}' || return 1")
        out.append(data if data.endswith("\n") else data + "\n")
        # heredoc lines join: strip the extra newline we add with join below
        out[-1] = out[-1][:-1]
        out.append(tag)
        if not data.endswith("\n"):
            out.append(f"    truncate -s -1 \"$d/{name}\" || return 1   # source has no final newline")
    out.append("    return 0")
    out.append("}")
    out.append(LOGIC.strip("\n"))
    out.append(END)
    return "\n".join(out) + "\n"


def main():
    jobs = open(JOBS, encoding="utf-8").read()
    if BEGIN not in jobs or END not in jobs:
        sys.exit("cm-embed: markers not found in app/scripts/jobs.sh")
    a = jobs.index(BEGIN); b = jobs.index(END) + len(END) + 1
    new = jobs[:a] + build_block() + jobs[b:]
    if "--verify" in sys.argv:
        if new != jobs:
            sys.exit("cm-embed: app/scripts/jobs.sh is out of date - run python3 server-configs/cm-embed.py")
        print("cm-embed: jobs.sh is up to date"); return
    if new != jobs:
        open(JOBS, "w", encoding="utf-8").write(new); print("cm-embed: jobs.sh updated")
    else:
        print("cm-embed: jobs.sh already up to date")


if __name__ == "__main__":
    main()
