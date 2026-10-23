# Ring push: one trigger for every way an extension rings

## Goal

When an extension rings, every mobile device on that extension gets a push, however the call got
there: direct dial, queue (any strategy), IVR option, ring group, follow-me, transfer, forwarding.
When the ringing stops, devices that never connected get a stop push. The same code runs on every
server, single or multi-instance, and reaches new and existing servers through the normal update.

## Why one trigger

The old push code in `server.js` pushes from about 17 places (`getToken`, `getTokenIOS`,
`getTokenMultipleDevice`, `pushQueue`, `pushQueueIOS`, …). Each route has to remember to push, so
routes nobody wired up (IVR options, ring groups, forwarding) never wake a closed app.

Every route ends in FreeSWITCH dialing `user/<ext>@<domain>`, which expands the extension's
directory `dial-string`. That is the one place to hook.

## Design

### mod_ringq (FreeSWITCH endpoint `ringq/<ext>@<domain>`)

`directory.lua` asks `ringq_ring_dial.lua` for each extension's dial-string. In a tenant with
`ringq / ring_push_mode = wake`, and only when `mod_ringq` is loaded, it returns
`ringq/<ext>@<domain>` and keeps the original dial-string in the user param
`ringq-orig-dial-string`. Dialing `user/<ext>` then reaches `mod_ringq`, which:

1. fires `CUSTOM ringq::ring` (ext, domain, ring id, caller, queue). `node/ringPush.js` pushes the
   extension's devices (tokens from Postgres `q_devices`, shared by every instance) and answers in
   `hash ringq_ring/<ring id>` with the number pushed.
2. if nothing is registered and something was pushed, waits for the woken app to register
   (`ringq_ring_wake_wait_ms`, default 15000). It stops early if the caller goes away. In a forked
   dial (a simultaneous ring group) it doesn't wait, so it never holds the other destinations back.
3. originates the original dial-string exactly as the `user/` endpoint does, with the ring id as
   the SIP Call-ID (the app matches it to the push), and returns the real device leg.
4. fires `CUSTOM ringq::ring_end` (cause, answered, registered) for the stop push.

**No leg is added.** Call journeys, reports, activity, dialer call logs, presence and transfers see
a normal call. (A first version used a loopback leg; it credited calls to "ringq_ring" in the
call journeys.)

The user's directory id is the number alias the RingQ apps register as. `mod_ringq` uses it as
`dialed_user`, as `user/` does, so app registrations are found without an alias lookup.

### Stop pushes (`ringPush.js`)

- **Android** gets a data stop (`type: ring`, `call: answered|missed|decline`) when the ring ends.
- **iOS** shows a call for every VoIP push, so the stop push only goes to a phone that never
  registered for this ring. A registered app hears about the call over SIP (cancel, answered
  elsewhere).

### iOS delivery (`node/apns.js`)

Straight to Apple with the .p8 key (`APNS_*` in `/etc/ringq/.env`, see
`server-configs/apns/README.md`). The relay at 157.245.60.143 accepted pushes for the preview
and dev apps but never delivered them. Tokens Apple reports as gone are removed.

## Rollout

- `app/scripts/ring-push/` ships with the central update (the Sandbox deploy copies `app/scripts`).
- `jobs.sh` runs `ring-push/ringq-push-setup.sh` on every update. It is idempotent and never fails
  the update. It:
  - builds `mod_ringq` from source (gcc + the FreeSWITCH source tree in `/usr/src/freeswitch`, as
    for `mod_openai_audio_stream`), installs and loads it, and adds it to `modules.conf.xml`
  - installs `ringq_ring_dial.lua` and adds the `directory.lua` hook once
  - adds the `ring_push_mode` default setting (`off`)
- `startup.sh` loads `mod_ringq` at boot.
- Calls change only for tenants set to `wake`, and only where `mod_ringq` loaded. Elsewhere the
  normal dial-string and the existing push code stay in place.
- Log on each server: `/var/log/ringq-push-setup.log`.

## Later

Once every tenant runs `wake` cleanly, remove the old per-route push code (`getToken*`,
`pushQueue*`, the relay, the app-to-app parking and `waitingCalls`) from `server.js` and
`attended.js`, with the Flutter app.
