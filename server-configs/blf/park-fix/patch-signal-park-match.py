#!/usr/bin/env python3
# [park-match] web Shared Parking: find the call leg of the extension to park even when its
# phone registered by IP (contact "<alias>@<ip>:<port>", no presence mapping): also match the
# contact's user part and the channel's presence_id. Usage: <signal.js in> <signal.js out>.
import sys
src, out = sys.argv[1], sys.argv[2]
s = open(src, encoding="utf-8").read()
if "[park-match]" in s:
    print("signal.js: park matching already fixed"); open(out, "w", encoding="utf-8").write(s); sys.exit(0)
start = s.find('socket.on("park", async (arg) => {')
end = s.find('socket.on(', start + 10)
if start < 0 or end < 0:
    sys.exit("signal.js: park handler not found")
body = s[start:end]
old = 'if (`${presence}@${channel}` === parkAgent || sipContactId.includes(parkAgent)) {'
if body.count(old) != 2:
    sys.exit("signal.js: park leg checks not found (%d)" % body.count(old))
a = 'if (`${presence}@${channel}` === parkAgent || sipContactId.includes(parkAgent) || String(sipContactId).split("@")[0] === String(destinationAlias) || data.presence_id === parkAgent) { // [park-match]'
b = 'if (`${presence}@${channel}` === parkAgent || sipContactId.includes(parkAgent) || String(sipContactId).split("@")[0] === String(destinationAlias) || data.b_presence_id === parkAgent) { // [park-match]'
body = body.replace(old, a, 1).replace(old, b, 1)
s = s[:start] + body + s[end:]
open(out, "w", encoding="utf-8").write(s)
print("signal.js: web parking also finds desk phones registered by IP")
