#!/usr/bin/env python3
# [desk-park] Shared Parking between the apps and desk phones, in place on node/signal.js:
#  - listens for CUSTOM ringq::park (fired by ringq_desk_park.lua when a desk phone parks or picks up)
#    and sends the apps the new slot list (parkStatus)
#  - after every parkStatus the apps get, refreshes the desk phones' park lamps
#    (luarun ringq_desk_park.lua lamps <domain>)
# Usage: patch-signal-desk-park.py <signal.js in> <signal.js out>. Already patched files are copied unchanged.
import re
import sys

src, out = sys.argv[1], sys.argv[2]
s = open(src, encoding="utf-8").read()
if "[desk-park]" in s:
    print("signal.js: already has desk phone parking")
    open(out, "w", encoding="utf-8").write(s)
    sys.exit(0)

n = s.count('ringq::ring");')
if n < 1:
    sys.exit("signal.js: ESL event list not found")
s = s.replace('ringq::ring");', 'ringq::ring ringq::park"); /* [desk-park] */')

hook = 'if (subclass === "ringq::ring") { rememberRing(ev); return; }'
h = s.count(hook)
if h < 1:
    sys.exit("signal.js: ringq::ring handler not found")
s = re.sub(r'(?m)^([ \t]*)' + re.escape(hook) + r'[^\n]*$',
           lambda m: m.group(0) + "\n" + m.group(1) + 'if (subclass === "ringq::park") { deskParkChanged(ev); return; } // [desk-park]', s)

func = '''
// [desk-park] a desk phone parked or picked up a call (ringq_desk_park.lua): send the apps the slots
async function deskParkChanged(ev) {
  try {
    const domain = ev.getHeader("domain");
    if (!domain) return;
    const arrStatus = await redisClient.lRange(`${domain}:parked`, 0, -1);
    wallboard.to(domain).emit("parkStatus", arrStatus);
  } catch (e) { /* ignore */ }
}
// [desk-park] the apps changed the slots: light / clear the desk phones' park lamps
function deskParkLamps(domain) {
  try {
    if (domain && /^[A-Za-z0-9._-]+$/.test(String(domain))) conn.api(`luarun ringq_desk_park.lua lamps ${domain}`);
  } catch (e) { /* ignore */ }
}

'''
at = s.find("function rememberRing(ev)")
if at < 0:
    sys.exit("signal.js: rememberRing not found")
line = s.rfind("\n", 0, at) + 1
# keep any comment lines that sit right above rememberRing together with it
while True:
    prev = s.rfind("\n", 0, line - 1) + 1
    if s[prev:line].lstrip().startswith("//"):
        line = prev
    else:
        break
s = s[:line] + func.lstrip("\n") + s[line:]

count = 0
def add_lamps(m):
    global count
    count += 1
    return m.group(0) + " deskParkLamps(" + m.group(1).strip() + "); // [desk-park]"
# live (not commented-out) emits: wallboard.to(X)  .emit("parkStatus", Y);   (possibly split over lines)
s = re.sub(r'(?m)^(?![ \t]*//)[^\n]*?wallboard\s*\.to\(([^()]+)\)\s*\.emit\(\s*"parkStatus"\s*,\s*[^;]*?\);', add_lamps, s)
if count < 3:
    sys.exit("signal.js: only %d parkStatus emits found" % count)
open(out, "w", encoding="utf-8").write(s)
print("signal.js: desk phone parking added (%d app park changes refresh the lamps)" % count)
