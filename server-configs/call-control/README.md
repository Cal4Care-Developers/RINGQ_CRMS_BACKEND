# RingQ Call Control (per call profile)

Makes the extension "Call Control" tab work at call time, for the profile the user
currently has selected in the dialer (Redis `<ext>+<domain>`, 0..4).

- `ringq_call_control.lua` -> copy to `/usr/share/freeswitch/scripts/`
- `ringq_att_voicemail.lua` -> copy to `/usr/share/freeswitch/scripts/` (attended transfer: the agent reaches the
  unanswered target's voicemail while the caller stays on hold, then returns to the caller; used by node/server.js [att-vm],
  which reconnects the agent straight away when this file is missing)
- `install.sql` -> updates the global `local_extension` dialplan and adds the global
  `ringq-call-control` dialplan (order 36). Then `rm /var/cache/ringq/dialplan.*`.

What it does (reads `q_call_behaviors` directly, so status changes apply instantly):

| Setting | Behaviour |
|---|---|
| Always | forward_all_* (internal) / forward_all_*2 (external) for the active profile |
| No Answer (seconds) | ring time (`call_timeout`), 5-300 s |
| On Busy | USER_BUSY / CALL_REJECTED -> destination (was ignored before: always voicemail) |
| No Answer Action | not answered / not reachable -> destination (was ignored before) |
| Call Relay | after no answer ring up to 3 destinations in turn (extensions ring their phones only) |
| Parallel Ringing | rings `q_call_behaviors.mobile_number` together with the extension |
| Call Screening | caller records name, callee presses 1 to accept |

Internal = `call_direction=local`; External = everything else. "" / `*99<own ext>` = own voicemail
(the normal voicemail step runs).
