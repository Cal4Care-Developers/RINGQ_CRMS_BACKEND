#!/bin/bash
# RingQ ring push: install / update mod_ringq and its directory hook on this server.
#
# Run by jobs.sh on every update (new and existing servers) and safe to run any time: every step
# is idempotent, and it never fails the update. Nothing changes how calls ring until a tenant's
# ringq/ring_push_mode setting is "wake" AND mod_ringq is loaded; until then ringq_ring_dial.lua
# keeps the normal dial-string.
#
#   bash ringq-push-setup.sh            install / update
#   bash ringq-push-setup.sh --remove   take the directory hook out (module and setting stay)
#
# Log: /var/log/ringq-push-setup.log

HERE="$(cd "$(dirname "$0")" && pwd)"
LOG=/var/log/ringq-push-setup.log
FS_SRC=${RINGQ_FS_SRC:-/usr/src/freeswitch}
# The installer builds FreeSWITCH in a versioned directory (/usr/src/freeswitch-1.10.12), so use
# the newest configured /usr/src/freeswitch-* when the default path is not a configured source.
if [ -z "${RINGQ_FS_SRC:-}" ] && [ ! -f "$FS_SRC/src/include/switch_am_config.h" ]; then
	for d in $(ls -d /usr/src/freeswitch-* 2>/dev/null | sort -rV); do
		[ -f "$d/src/include/switch_am_config.h" ] && { FS_SRC="$d"; break; }
	done
fi
SCRIPTS=/usr/share/freeswitch/scripts
DIRLUA="$SCRIPTS/app/xml_handler/resources/scripts/directory/directory.lua"
MODS=/etc/freeswitch/autoload_configs/modules.conf.xml
STAMP=/var/lib/ringq-push/mod_ringq.sha256

log() { echo "$(date '+%F %T') $*" | tee -a "$LOG"; }
fs() { fs_cli -x "$1" 2>/dev/null; }

flush_caches() {
	rm -f /var/cache/ringq/directory.* /var/cache/ringq/setting.*.ringq.ring_push_mode.* 2>/dev/null
	fs "memcache flush" >/dev/null
}

if [ "${1:-}" = "--remove" ]; then
	if [ -f "$DIRLUA" ] && grep -q 'ringq_ring_dial' "$DIRLUA"; then
		cp -p "$DIRLUA" "$DIRLUA.ringq-push.$(date +%Y%m%d%H%M%S)"
		perl "$HERE/patch-directory.pl" --remove "$DIRLUA" >>"$LOG" 2>&1 && log "directory hook removed"
	fi
	flush_caches
	exit 0
fi

command -v fs_cli >/dev/null 2>&1 || { log "fs_cli not found, skipping (FreeSWITCH not on this host?)"; exit 0; }
mkdir -p "$(dirname "$STAMP")"

# 0. gcc: needed to build mod_ringq. Only worth installing where the FreeSWITCH source is present
# (without it nothing can be built). Non-interactive, waits for the apt lock, never fails the update.
if ! command -v gcc >/dev/null 2>&1 && [ -f "$FS_SRC/src/include/switch.h" ] && command -v apt-get >/dev/null 2>&1; then
	if DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 install -y --no-install-recommends gcc >>"$LOG" 2>&1; then
		log "gcc installed"
	else
		log "gcc install failed (apt output above)"
	fi
fi

# 1. mod_ringq: build when missing or its source changed
MOD_DIR="$(fs 'global_getvar mod_dir')"
[ -n "$MOD_DIR" ] && [ -d "$MOD_DIR" ] || MOD_DIR=/usr/lib/freeswitch/mod
SRC_SUM="$(sha256sum "$HERE/mod_ringq.c" | cut -d' ' -f1)"
if [ ! -f "$MOD_DIR/mod_ringq.so" ] || [ "$(cat "$STAMP" 2>/dev/null)" != "$SRC_SUM" ]; then
	LIBFS="$(ls /usr/lib/libfreeswitch.so* /usr/lib/x86_64-linux-gnu/libfreeswitch.so* /usr/local/freeswitch/lib/libfreeswitch.so* 2>/dev/null | head -1)"
	if ! command -v gcc >/dev/null 2>&1; then
		log "gcc not found: mod_ringq not built"
	elif [ ! -f "$FS_SRC/src/include/switch.h" ] || [ ! -f "$FS_SRC/src/include/switch_am_config.h" ]; then
		log "configured FreeSWITCH source not found at $FS_SRC: mod_ringq not built"
	elif [ -z "$LIBFS" ]; then
		log "libfreeswitch.so not found: mod_ringq not built"
	else
		BUILD="$(mktemp -d)"
		if gcc -O2 -fPIC -shared -Wall -Werror=implicit-function-declaration \
			-I"$FS_SRC/src/include" -I"$FS_SRC/libs/libteletone/src" \
			-o "$BUILD/mod_ringq.so" "$HERE/mod_ringq.c" "$LIBFS" >>"$LOG" 2>&1; then
			install -o root -g root -m 0644 "$BUILD/mod_ringq.so" "$MOD_DIR/mod_ringq.so"
			echo "$SRC_SUM" > "$STAMP"
			fs "unload mod_ringq" >/dev/null
			log "mod_ringq built and installed in $MOD_DIR"
		else
			log "mod_ringq build failed (compiler output above)"
		fi
		rm -rf "$BUILD"
	fi
fi

# load now and on every FreeSWITCH start
if [ -f "$MOD_DIR/mod_ringq.so" ]; then
	if [ -f "$MODS" ] && ! grep -q 'mod_ringq' "$MODS"; then
		cp -p "$MODS" "$MODS.ringq-push.$(date +%Y%m%d%H%M%S)"
		sed -i '/<\/modules>/i\    <load module="mod_ringq"/>' "$MODS"
		log "mod_ringq added to modules.conf.xml"
	fi
	if ! fs "module_exists mod_ringq" | grep -q true; then
		fs "load mod_ringq" >>"$LOG"
	fi
	fs "module_exists mod_ringq" | grep -q true && log "mod_ringq loaded" || log "mod_ringq did not load"
fi

# 2. directory hook: ringq_ring_dial.lua decides per tenant which dial-string to use
if [ -f "$DIRLUA" ]; then
	changed=0
	if ! cmp -s "$HERE/ringq_ring_dial.lua" "$SCRIPTS/ringq_ring_dial.lua"; then
		install -m 0644 "$HERE/ringq_ring_dial.lua" "$SCRIPTS/ringq_ring_dial.lua" && changed=1
	fi
	if ! grep -q 'ringq_ring_dial' "$DIRLUA"; then
		cp -p "$DIRLUA" "$DIRLUA.ringq-push.$(date +%Y%m%d%H%M%S)"
		if perl "$HERE/patch-directory.pl" "$DIRLUA" >>"$LOG" 2>&1; then
			changed=1
			log "directory hook added"
		else
			log "directory hook NOT added (directory.lua layout not recognised)"
		fi
	fi
	[ "$changed" = 1 ] && flush_caches
else
	log "directory.lua not found at $DIRLUA: hook not added"
fi

# 3. the setting (default off); an existing value is left alone
if [ -f /etc/ringq/.env ] && command -v psql >/dev/null 2>&1; then
	set -a; . /etc/ringq/.env; set +a
	PGPASSWORD="$PostgreSQL_DB_PASSWORD" psql -q -h "$PostgreSQL_DB_HOST" -p "$PostgreSQL_DB_PORT" \
		-U "$PostgreSQL_DB_USER" -d "$PostgreSQL_DB_NAME" >>"$LOG" 2>&1 <<'SQL'
insert into v_default_settings (default_setting_uuid, default_setting_category, default_setting_subcategory,
    default_setting_name, default_setting_value, default_setting_enabled, default_setting_description)
select '3d5f2a9e-8c41-4b6e-a7f2-0e9c1b4d6a73', 'ringq', 'ring_push_mode', 'text', 'off', 'true',
    'Ring push: off | wake (mod_ringq pushes the mobile apps on every ring and waits for them)'
where not exists (select 1 from v_default_settings
    where default_setting_category = 'ringq' and default_setting_subcategory = 'ring_push_mode');
-- the loopback design's dialplan context is no longer used
delete from v_dialplans where dialplan_uuid = '7b0e6b1c-2f4e-4f7a-9d8e-5a1c3f9b2e01';
SQL
fi

# 3b. new push only: once mod_ringq is loaded here, turn the new ring push on for every domain
# (default ring_push_mode off -> wake). The old call pushes in server.js, attended.js and status.js
# then stay quiet for those domains (node/pushOwner.js). Done ONCE per server (stamp file): an admin
# who sets it back to "off" afterwards (or per domain in Domain settings) is not overridden by the
# next update. Without mod_ringq nothing changes and the old pushes keep working.
WAKE_STAMP=/var/lib/ringq-push/auto-wake
if [ ! -f "$WAKE_STAMP" ] && fs "module_exists mod_ringq" | grep -q true \
	&& [ -f /etc/ringq/.env ] && command -v psql >/dev/null 2>&1; then
	if ( set -a; . /etc/ringq/.env; set +a
	     PGPASSWORD="$PostgreSQL_DB_PASSWORD" psql -q -h "$PostgreSQL_DB_HOST" -p "$PostgreSQL_DB_PORT" \
	       -U "$PostgreSQL_DB_USER" -d "$PostgreSQL_DB_NAME" -v ON_ERROR_STOP=1 >>"$LOG" 2>&1 <<'SQL'
update v_default_settings set default_setting_value = 'wake', default_setting_enabled = 'true'
 where default_setting_category = 'ringq' and default_setting_subcategory = 'ring_push_mode'
   and default_setting_value = 'off';
SQL
	); then
		date '+%F %T' > "$WAKE_STAMP"
		flush_caches
		log "ring push on (default ring_push_mode = wake): new push only, old pushes quiet"
	else
		log "could not switch ring push on (database), will retry next run"
	fi
fi

# 4. modern call tones on every server (re-applied on each update): the same double "ring-ring"
# the caller hears while an extension rings (mod_ringq RINGQ_RINGBACK) as the global ringback and
# transfer ringback, three soft pips for the "unavailable" tone (sit) and a short soft voicemail
# record beep instead of the 1 s 640 Hz one. FusionPBX keeps these in v_vars and writes vars.xml
# from it, so both are updated. A value someone changed to anything else is left alone.
RING='%(400,200,400,450);%(400,2000,400,450)'
SIT='%(150,100,520);%(150,100,520);%(150,600,520)'
VARS=/etc/freeswitch/vars.xml
tones_changed=0
set_tone() { # set_tone <var> <new> <old value it replaces>
	local name="$1" new="$2" old="$3"
	if [ -f "$VARS" ] && grep -qF "data=\"$name=$old\"" "$VARS"; then
		cp -p "$VARS" "$VARS.ringq-tones.$(date +%Y%m%d%H%M%S)" 2>/dev/null
		NAME="$name" NEW="$new" OLD="$old" perl -pi -e 's/\Qdata="$ENV{NAME}=$ENV{OLD}"\E/data="$ENV{NAME}=$ENV{NEW}"/' "$VARS"
		tones_changed=1
		log "tone $name -> $new"
	fi
	if [ -f /etc/ringq/.env ] && command -v psql >/dev/null 2>&1; then
		( set -a; . /etc/ringq/.env; set +a
		  PGPASSWORD="$PostgreSQL_DB_PASSWORD" psql -q -h "$PostgreSQL_DB_HOST" -p "$PostgreSQL_DB_PORT" \
			-U "$PostgreSQL_DB_USER" -d "$PostgreSQL_DB_NAME" -v name="$name" -v new="$new" -v old="$old" >>"$LOG" 2>&1 <<'SQL'
update v_vars set var_value = :'new' where var_name = :'name' and var_value = :'old';
SQL
		)
	fi
}
set_tone ringback "$RING" '%(2000,4000,440,480)'
set_tone transfer_ringback "$RING" '%(2000,4000,440,480)'
set_tone sit "$SIT" '%(274,0,913.8);%(274,0,1370.6);%(380,0,1776.7)'
[ "$tones_changed" = 1 ] && fs "reloadxml" >/dev/null
for f in "$SCRIPTS/app/voicemail/resources/functions/macro.lua" "$SCRIPTS/app/voicemail/resources/functions/record_greeting.lua"; do
	if [ -f "$f" ] && grep -qF 'L=1;%(1000, 0, 640)' "$f"; then
		cp -p "$f" "$f.ringq-tones.$(date +%Y%m%d%H%M%S)"
		perl -pi -e 's/\QL=1;%(1000, 0, 640)\E/L=1;%(300, 0, 660)/g' "$f"
		log "voicemail beep softened in $(basename "$f")"
	fi
done

# 5. Android pushes through the RingQ app's own Firebase project (node/ringPush.js reads
# /etc/ringq/fcm/service-account.json when node starts). The key ships with the update payload as
# node/fcm-service-account.json, beside node/secret.json; it is NEVER in git (.gitignore). Without it
# Android pushes keep going through the shared Firebase app. A key someone installed by hand with
# server-configs/fcm/install.sh is replaced only when the shipped one is a different, valid key.
FCM_SRC="$(cd "$HERE/../../.." && pwd)/node/fcm-service-account.json"
FCM_DEST=/etc/ringq/fcm/service-account.json
if [ -s "$FCM_SRC" ]; then
	if ! grep -q '"type": *"service_account"' "$FCM_SRC" || ! grep -q '"private_key"' "$FCM_SRC"; then
		log "shipped Firebase key $FCM_SRC is not a service account key: not installed"
	elif ! cmp -s "$FCM_SRC" "$FCM_DEST"; then
		# only the fcm directory is restricted: /etc/ringq itself must stay traversable by FreeSWITCH
		if mkdir -p /etc/ringq/fcm && chmod 700 /etc/ringq/fcm \
			&& install -o root -g root -m 0600 "$FCM_SRC" "$FCM_DEST"; then
			log "Firebase key installed ($(grep -o '"project_id": *"[^"]*"' "$FCM_DEST" | head -1))"
			# node reads the key at start; jobs.sh reloads every app later in the update, this covers a
			# manual run of this script
			command -v pm2 >/dev/null 2>&1 && pm2 restart server --update-env >/dev/null 2>&1 \
				&& log "node server restarted to pick up the Firebase key"
		else
			log "could not install the Firebase key to $FCM_DEST"
		fi
	fi
fi

# 6. iPhone pushes go straight to Apple (node/apns.js, settings APNS_* in /etc/ringq/.env). The
# Apple key ships with the update payload like the Firebase key, as node/apns-AuthKey_<KEYID>.p8
# (the key id is read from the name); it is NEVER in git (.gitignore). A new server then pushes
# iPhones from its first update instead of waiting for server-configs/apns/install.sh by hand.
# A key or settings that already match are left alone; a different shipped key replaces them.
NODE_DIR="$(cd "$HERE/../../.." && pwd)/node"
APNS_SRC="$(ls -t "$NODE_DIR"/apns-AuthKey_*.p8 2>/dev/null | head -1)"
if [ -n "$APNS_SRC" ] && [ -s "$APNS_SRC" ]; then
	APNS_KEY_ID="$(basename "$APNS_SRC" | sed -nE 's/^apns-AuthKey_([A-Z0-9]{10})\.p8$/\1/p')"
	APNS_TEAM_ID="${RINGQ_APNS_TEAM_ID:-6GP58F5X59}"
	APNS_BUNDLES="${RINGQ_APNS_BUNDLE_IDS:-com.ringq.app,com.ringq.app.preview,com.ringq.app.dev}"
	APNS_DEST="/etc/ringq/apns/AuthKey_${APNS_KEY_ID}.p8"
	ENV_FILE=/etc/ringq/.env
	if [ -z "$APNS_KEY_ID" ] || ! grep -q "BEGIN PRIVATE KEY" "$APNS_SRC"; then
		log "shipped Apple key $APNS_SRC is not an AuthKey_<10 char id>.p8 private key: not installed"
	elif [ ! -f "$ENV_FILE" ]; then
		log "no $ENV_FILE: Apple key not installed"
	else
		APNS_LINES="APNS_KEY_FILE=$APNS_DEST
APNS_KEY_ID=$APNS_KEY_ID
APNS_TEAM_ID=$APNS_TEAM_ID
APNS_BUNDLE_IDS=$APNS_BUNDLES"
		if cmp -s "$APNS_SRC" "$APNS_DEST" && [ "$(grep '^APNS_' "$ENV_FILE")" = "$APNS_LINES" ]; then
			: # already installed
		elif mkdir -p /etc/ringq/apns && chmod 700 /etc/ringq/apns \
			&& install -o root -g root -m 0600 "$APNS_SRC" "$APNS_DEST"; then
			# rewrite only the APNS_* lines, keep the rest of .env as it is
			tmp="$(mktemp)"
			{ grep -v '^APNS_' "$ENV_FILE" || true; printf '%s\n' "$APNS_LINES"; } > "$tmp" && cat "$tmp" > "$ENV_FILE"
			rm -f "$tmp"
			log "Apple key installed (key id $APNS_KEY_ID, team $APNS_TEAM_ID)"
			# node reads the key at start; jobs.sh reloads every app later in the update, this covers a
			# manual run of this script. Time-limited: pm2 can hang when it can't reach its daemon.
			command -v pm2 >/dev/null 2>&1 && timeout --kill-after=5s 60s pm2 restart server --update-env >/dev/null 2>&1 \
				&& log "node server restarted to pick up the Apple key"
		else
			log "could not install the Apple key to $APNS_DEST"
		fi
	fi
fi

exit 0
