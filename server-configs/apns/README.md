# Direct-to-Apple iOS call pushes

`node/apns.js` sends iOS VoIP pushes straight to Apple instead of through the relay at
157.245.60.143. The relay accepted pushes for the preview and dev apps but never delivered them,
so a closed iPhone app never rang. A server without the APNs settings keeps using the relay,
exactly as before this change.

## Rollout after merging to main

1. **Merge, then do the central update as usual.** The Sandbox deploy workflow ships
   `node/server.js`, `node/apns.js` and `node/ringPush.js` with everything else. Nothing changes on a
   server yet: with no APNs settings it keeps using the relay.
2. **Once per server**, after it has the update, turn on direct pushes (next section). This is
   the only manual step. The key is a secret and is not in git.
3. **Check** each server (see "Checking a server").

Order doesn't matter much. Step 2 before the update fails safely: `install.sh` reports that
`[apns]` didn't start, and the server keeps the relay until the update arrives.

Already done (key and settings in place; they survive the central update):
- 146.190.217.200, Skyrock (Docker)
- 165.232.169.74, apple.ringq.ai (host install)

`server-configs/` is not part of the Sandbox deploy, so run `install.sh` from a `ringq_core`
checkout on the server or copy this folder there.

## Turning it on for a server

```bash
# from a machine that has the key (ask the iOS lead; never commit it or paste it in chat)
scp AuthKey_Q8S445QF9U.p8 root@<server>:/tmp/

# on the server, as root
bash install.sh /tmp/AuthKey_Q8S445QF9U.p8
rm /tmp/AuthKey_Q8S445QF9U.p8
```

It prints `APNS_OK` and the line `[apns] direct to Apple, bundles ...`. It works whether RingQ
runs in the `ringq` Docker container or directly on the host. It:

- puts the key at `/etc/ringq/apns/AuthKey_<KEYID>.p8` (root only, 600)
- writes `APNS_KEY_FILE`, `APNS_KEY_ID`, `APNS_TEAM_ID`, `APNS_BUNDLE_IDS` in `/etc/ringq/.env`
- restarts `server` (only that pm2 process) and checks its log

`/etc/ringq` is kept across updates: it's the `ringq_etc` volume on Docker servers, and on host
installs the Sandbox deploy never touches it. Running the script again with a new key replaces
the old one.

## Checking a server

Prefix with `docker exec ringq` on Docker servers.

```bash
grep -a "\[apns\]" /root/.pm2/logs/server-out.log /root/.pm2/logs/server-error.log | tail
```

- `direct to Apple, bundles …` means the key loaded at startup.
- `sent <call> <ext> <domain> <bundle> <env>` means Apple accepted a call push.
- `failed … <status> <reason>` gives Apple's reason (for example `InvalidProviderToken` = wrong key id or team).

Real test: sign the iOS app in on that server, close the app completely, call the extension. It rings.

## Turning it off

Delete the `APNS_*` lines from `/etc/ringq/.env`, then run `pm2 restart server --update-env`.
The server goes back to the relay.
