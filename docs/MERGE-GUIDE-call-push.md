# Merge guide: call push — new push only (PR #8, branch `ring-push-no-force-bridge`)

For whoever reviews, merges and rolls this out. PR #3 (`apns-direct-push`) is already merged; this PR finishes the job. The design is in [`ring-push-design.md`](ring-push-design.md).

## TL;DR

1. **The goal:** every server pushes calls through **one** new mechanism (`mod_ringq` + `node/ringPush.js`) and the **old call pushes are switched off**.
2. **After merging, the central update does it by itself**, on new and existing servers:
   - builds and loads `mod_ringq`
   - turns the new push on for every tenant (**once** per server)
   - the old push code then stays quiet for those tenants

   Servers where `mod_ringq` can't be built keep the old push, so no server ends up with no push at all.
3. **Keys (secrets, never in git):**
   - the Firebase key, for Android pushes: ships with the update (placed once in the sandbox payload)
   - the Apple key, for iPhone pushes: once per server, by hand
4. **Related PRs:**
   - [ringqexpoapp #2](https://github.com/RingQ-Devs/ringqexpoapp/pull/2): iOS / Android app build 14117
   - [ringq_react_app #10](https://github.com/RingQ-Devs/ringq_react_app/pull/10): web ringback and sounds
5. **Already live by hand:** 165.232.169.74 and US6 (3.219.24.98). See "Servers already done".

## How it works (one paragraph)

Every way an extension can ring (direct, queue of any strategy, IVR, ring group, forwarding, transfer) ends in `user/<ext>`. `directory.lua` points that at the FreeSWITCH endpoint `ringq/<ext>@<domain>` (`mod_ringq`). The endpoint:
1. fires **one** event. `node/ringPush.js` sends one push per phone: iPhone straight to Apple, Android through Firebase.
2. plays the caller a ring while it waits (up to 15 s) for the woken phone to register.
3. dials the extension exactly as `user/` would.

It adds no call leg, so call journeys, reports, logs and presence don't change. For a tenant in `wake`, the old pushes stay quiet:
- `server.js`: `getToken*`, `pushQueue*`, the internal-call parking
- `attended.js`: `pushVoip*`, `getTokenIOS`
- `status.js`: `push-call`

The check is `ringPush.owns()` in `server.js`, and `node/pushOwner.js` (via Redis) in the other two.

## What's in this PR

| Area | Change | Files |
|---|---|---|
| **New push only** | The once-per-server switch to `wake` after `mod_ringq` loads; the old pushes in `attended.js` and `status.js` go quiet too | `app/scripts/ring-push/ringq-push-setup.sh`, `node/pushOwner.js`, `node/attended.js`, `node/status.js`, `node/ringPush.js` |
| **Ringing that works** | No forced `uuid_bridge` on `mod_ringq` legs (it caused no audio) | `node/server.js` |
| | Waits for the iPhone/Android **app itself** to register, not just any device of the extension (web dialer, desk phone) | `mod_ringq.c`, `ringPush.js` |
| | With several phones: waits for every pushed phone, at most 8 s once one is up | `mod_ringq.c` |
| | A stop push for phones that never got the call; no "RingQ" placeholder for phones that did | `ringPush.js` |
| | Stale `activeCall-*` entries ignored; a push token used only for the phone's latest sign-in; Flutter placeholder tokens skipped; dead Firebase tokens removed | `ringPush.js` |
| **One phone per extension** | Latest QR sign-in wins. The earlier phone's pushes and remember-me token are removed, its next request gets `401 MOBILE_REPLACED`, and the app signs out and says why. The desktop app is never signed out by this: it sends Dart's default user agent like a phone, so desktop sessions carry `client = desktop`, and a session with no phone sign-in token of its own is left alone | `ControllerBase.php`, `DesktopController.php`, `RegisterController.php`, `signal.js` |
| **Calls on by default** | A phone that signs in again starts with calls on | `signal.js` |
| **Android** | Pushes through the app's own Firebase project (`ringq-push`); the key ships with the update payload and `ringq-push-setup.sh` installs it | `ringPush.js`, `ringq-push-setup.sh`, `server-configs/fcm/install.sh` |
| **Call tones** | One double "ring-ring" ringback, played as one continuous sound (no glitch); the same for transfers; soft "unavailable" pips; short voicemail beep | `mod_ringq.c`, `ringq-push-setup.sh` |
| **Manual deploy** | Script that does all of the above on one server, with checks and rollback | `server-configs/ring-push/deploy.sh` |

## After merging

### 1. The central update (nothing to do)

`jobs.sh` runs `app/scripts/ring-push/ringq-push-setup.sh` on every update. It's idempotent, never fails the update, and logs to `/var/log/ringq-push-setup.log`. On each run it:
1. builds `mod_ringq` from source if it's missing or changed (needs gcc and `/usr/src/freeswitch*`, like `mod_openai_audio_stream`), and loads it
2. adds the `directory.lua` hook
3. adds the setting `ringq / ring_push_mode`, with default `off`
4. **once per server**, if `mod_ringq` is loaded, sets that default to **`wake`**. That's the new push only. The stamp file is `/var/lib/ringq-push/auto-wake`.
5. sets the modern call tones (`ringback`, `transfer_ringback`, `sit`, voicemail beep), only where they still have the stock values

`startup.sh` loads the module at boot.

Check a server with:

```bash
tail /var/log/ringq-push-setup.log      # "mod_ringq loaded", "ring push on (default ring_push_mode = wake)"
fs_cli -x "module_exists mod_ringq"     # true
grep -a "\[ring-push\]\|\[apns\]" /root/.pm2/logs/server-out.log | tail
```

### 2. The keys (secrets, never in git)

**Firebase (Android) ships with the update.** The `ringq-push` service account key is placed once,
by hand, in the sandbox payload as `ringq/node/fcm-service-account.json` (next to `node/secret.json`,
the shared Firebase key that already ships the same way). It is git-ignored, so CI never touches or
deletes it. On every update `ringq-push-setup.sh` installs it to `/etc/ringq/fcm/service-account.json`
(0600, root) when it is a valid service account key that differs from the installed one, and the
update's `pm2 reload all` makes node pick it up. Rotating the key = replace the file in the payload.

```bash
# on the fileserver, once (and again to rotate)
install -o ringq -g ringq -m 0640 ringq-push-firebase-adminsdk-*.json /var/www/sandbox/live/ringq/node/fcm-service-account.json
```

**Apple (iPhone) ships the same way.** Place the Apple key once in the sandbox payload as
`ringq/node/apns-AuthKey_<KEYID>.p8` (the key id is read from the file name; git-ignored). On every
update `ringq-push-setup.sh` installs it to `/etc/ringq/apns/` (0600, root) and writes the `APNS_*`
lines in `/etc/ringq/.env` (team `6GP58F5X59`, bundles `com.ringq.app,…preview,…dev`; override with
`RINGQ_APNS_TEAM_ID` / `RINGQ_APNS_BUNDLE_IDS`), only when they differ. So a new server pushes
iPhones from its first update. Rotating the key = replace the file in the payload.

```bash
# on the fileserver, once (and again to rotate)
install -o ringq -g ringq -m 0640 AuthKey_Q8S445QF9U.p8 /var/www/sandbox/live/ringq/node/apns-AuthKey_Q8S445QF9U.p8
```

The installers below still work for a server that can't wait for an update.

```bash
# iPhone: Apple push key (from the iOS lead)
scp AuthKey_Q8S445QF9U.p8 root@<server>:/tmp/
ssh root@<server> 'bash /var/www/ringq/server-configs/apns/install.sh /tmp/AuthKey_Q8S445QF9U.p8; rm /tmp/AuthKey_Q8S445QF9U.p8'   # APNS_OK

# Android: Firebase service account of project ringq-push (Firebase console > Service accounts)
scp ringq-push-firebase-adminsdk-*.json root@<server>:/tmp/fcm.json
ssh root@<server> 'bash /var/www/ringq/server-configs/fcm/install.sh /tmp/fcm.json; rm /tmp/fcm.json'   # FCM_OK
```

`server-configs/` isn't shipped by the central update, so copy the two `install.sh` files from this repo if the server doesn't have them. Or let `deploy.sh` install the keys (below).

### 3. Apps

- **iOS:** build **14117** or later (TestFlight). After installing, each phone signs in once by QR so it registers its push token.
- **Android:** the new app, built with `google-services.json` from Firebase project `ringq-push` (package `com.ringq.app`). The file is git-ignored and supplied at build time. The old Flutter app can't be pushed.

## A server that can't wait for the central update

`server-configs/ring-push/deploy.sh` does steps 1 and 2 on one server from your checkout:
- uploads over **one** SSH connection (some servers stall on many quick connections)
- checks **on the server** that every node file parses and every `require('./…')` resolves before replacing anything (this is the check that would have caught the missing `quietlog.js` on US6)
- backs up every file it replaces
- restarts `server`, `signal`, `attended` and `status`, and checks they're online

```bash
HOST=root@<server> bash server-configs/ring-push/deploy.sh
HOST=admin@<server> SSH_KEY=~/keys/server.pem \
  APNS_KEY=~/Downloads/AuthKey_Q8S445QF9U.p8 FCM_KEY=~/Downloads/ringq-push-firebase-adminsdk-xxxx.json \
  bash server-configs/ring-push/deploy.sh
```

Expect `UPLOAD_OK`, `PREFLIGHT_OK`, `PHP_OK`, `SERVICES_ONLINE`, `[apns] direct to Apple` and `android pushes via Firebase project ringq-push`. The last line prints the rollback command.

## Servers already done

| Server | State |
|---|---|
| 165.232.169.74 (apple.ringq.ai, host) | New push, keys installed. Merge before its next central update, or the update puts the old node files back. |
| US6 3.219.24.98 (host, login `admin` + sudo) | Deployed by hand on 2026-10-10 (9 node files + 2 PHP files + `mod_ringq`), `ring_push_mode` default `wake`, keys installed. First test call (1000 → 1009, iPhone closed) rang and connected on the new push only. |
| 146.190.217.200 (Skyrock, Docker) | **Still on the old loopback hook in `wake`** for one tenant. Run `deploy.sh` (or the central update) to replace it. |

## Verifying a server

With the app closed on an iPhone:
1. **Direct call:** answer once, decline once.
2. **Queue call from an outside number:** answer, and check you can talk both ways.
3. **Logs:**

```bash
grep -a "\[ring-push\]\|\[apns\]" /root/.pm2/logs/server-out.log | tail     # "pushed ... ios 1 / 1", "[apns] sent"
grep -h "\[ringq\]" /var/log/freeswitch/freeswitch.log | tail                 # "1 of 1 iOS apps registered after …ms"
```

In `q_call_journeys`, `answered_by` should show the extension.

## Rollback

| What | How |
|---|---|
| One tenant back to the old push | Domain settings: `ringq` / `ring_push_mode` = `off`, then `rm -f /var/cache/ringq/setting.*ring_push_mode*; fs_cli -x "memcache flush"`. Takes effect at once. The update won't flip it back. |
| A whole server back to the old push | The default setting `ringq` / `ring_push_mode` = `off`, then clear the cache as above. The stamp file stops the update re-enabling it. |
| A `deploy.sh` run | `HOST=<server> ROLLBACK=<run> bash server-configs/ring-push/deploy.sh` (files back, push off) |
| Direct-to-Apple pushes | Delete the `APNS_*` lines from `/etc/ringq/.env`, then `pm2 restart server --update-env` |
| The `directory.lua` hook | `bash app/scripts/ring-push/ringq-push-setup.sh --remove` |

## Later (not in this PR)

Once all servers have run the new push cleanly for a while, delete the old push code, together with removing the Flutter apps:
- `getToken*`, `pushQueue*`, `pushVoip*`
- the relay
- the app-to-app parking and `waitingCalls`
- `attended.js`'s and `status.js`'s own pushes

Still open:
- queue calls: other agents can keep ringing for a moment after one answers
- **Bridge Calls** (moving a live call between devices): designed, on hold
