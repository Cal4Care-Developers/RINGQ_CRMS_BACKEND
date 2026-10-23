#!/bin/bash
# fs-inventory.sh — emit a one-shot FreeSWITCH pinning snapshot.
#
# Run by ops on each server before/during the fs-pin-existing-servers rollout
# to (a) stage which boxes still need attention, (b) confirm post-rollout that
# the pin landed.
#
# Output format: one `KEY=VALUE` line per fact, safe to grep/awk. No side effects.

set -u

_kv() { printf '%s=%s\n' "$1" "$2"; }

_kv host             "$(hostname -f 2>/dev/null || hostname)"
_kv timestamp        "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
_kv fs_version       "$(dpkg-query -W -f='${Version}' freeswitch 2>/dev/null || echo none)"
_kv libfs_version    "$(dpkg-query -W -f='${Version}' libfreeswitch1 2>/dev/null || echo none)"
_kv pin_file_present "$([ -f /etc/apt/preferences.d/freeswitch-pin.pref ] && echo yes || echo no)"
_kv pin_priority     "$(awk -F'[: ]+' '/^Pin-Priority/{print $2; exit}' /etc/apt/preferences.d/freeswitch-pin.pref 2>/dev/null || echo none)"
_kv pin_version_glob "$(awk -F'[: ]+' '/^Pin: version/{print $3; exit}' /etc/apt/preferences.d/freeswitch-pin.pref 2>/dev/null || echo none)"
_kv pin_artifact     "$([ -f /var/www/ringq/app/scripts/apt-preferences/freeswitch-pin.pref ] && echo yes || echo no)"
_kv fs_running       "$(systemctl is-active freeswitch 2>/dev/null || echo unknown)"

# Mixed-version detection — flags servers where core is at 1.10.12 but some
# freeswitch-mod-* is at a different version (the orphan-module symptom).
_mixed=0
_core_ver=$(dpkg-query -W -f='${Version}' freeswitch 2>/dev/null || echo "")
if [ -n "$_core_ver" ]; then
    while read -r _pkg _ver; do
        if [ -n "$_pkg" ] && [ "$_ver" != "$_core_ver" ]; then
            _mixed=1
            break
        fi
    done < <(dpkg-query -W -f='${Package} ${Version}\n' 'freeswitch-mod-*' 2>/dev/null)
fi
_kv mixed_module_versions "$([ "$_mixed" = "1" ] && echo yes || echo no)"

# Orphan .so detection — .so files in /usr/lib/freeswitch/mod/ with no owning package.
_orphans=0
if [ -d /usr/lib/freeswitch/mod ]; then
    for _so in /usr/lib/freeswitch/mod/*.so; do
        [ -e "$_so" ] || continue
        if ! dpkg -S "$_so" >/dev/null 2>&1; then
            _orphans=$((_orphans + 1))
        fi
    done
fi
_kv orphan_mod_count "$_orphans"
