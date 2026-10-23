# RingQ change log - 2 Oct 2026

Branch: `fix/attended-transfer-call-journey` in **ringq_core** and **ringq_react_app**.
Live on apple.ringq.ai as a hotfix. The nightly release sync may overwrite it until this is merged.

## 1. Attended transfer (ringq_core: signal.js, attended.js, server.js)
| Problem | Fix |
|---|---|
| The customer stayed parked when the transfer target didn't answer (voicemail) or the agent hung up | `server.js` `[att-recover]` watches the consult leg and returns the customer to the agent. If the agent has already left, the customer goes back to the queue |
| The customer was re-parked with no live target | `signal.js parkAttendedTransfer` checks the target leg. If it isn't live, it bridges back and emits `declineAttendedTransfer` |
| The target saw its own name and photo | `attended.js` sets the consult-leg caller ID to the agent; after completion the target sees the customer's number |
| Failed transfer left the agent on the wrong screen (React) | `onDeclineAttendedTransfer` always returns to the original call |

## 2. Call journey, Call Status and Call Logs
- New `node/callJourney.js`. It records every step of a call (landed, routed, queue, agents rung, answered, transfers, ended) in Redis and in the new table `q_call_journeys`.
  - **Run `node/q_call_journeys.sql` on each server.**
- New socket call `getCallJourneys` (ack) reads Redis first and falls back to the database.
- Call Status (Active and Previous) gains:
  - Queue name and number, Answered By, Wait.
  - A plain-language status, with Rule and Route filled in.
  - An expandable timeline, including for live calls.
  - Outbound calls listed.
  - Colour by type (Queue, Inbound, Outbound, Fax, Internal).
  - A compact layout for 13" screens.
- Call Logs report gets the same journey columns and timeline.
- Dialer shows a queue name/number badge on queue calls.
- `ringing_now` (agents currently ringing) is exposed for Team Directory.

## 3. Team Directory (React)
- An extension blinks yellow while on a call or ringing.
- Shows the in-call number, queue name and number, and inbound/outbound.
- Respects the per-extension permissions **Call Status** and **In-Call Number**. The toggles now load their saved values.

## 4. Wallboard
| Problem | Fix |
|---|---|
| Attended transfers counted a call 2-3 times (answered, talk time, total) | `queue.js` counts once per `cc_member_uuid`; talk time uses the longest leg |
| "Missed" was added to every agent in the queue | Counted once per agent per call, only for agents actually rung (`server.js bridge-agent-fail`) |
| Averages showed totals | Avg Waiting = waiting / offered; Avg Talking = talk / answered (queue and agent) |
| Opening the wallboard showed an old cached snapshot | `wallBoardDisplay` rebuilds Agent/Queue details from the DB (`queueAction(subdomain,'refresh')`) |
| 13" screens cut off agent names and queue numbers | Wider name columns, queue shown as "9036 · Name", duplicate "(ext)" removed, tighter rows |
| "Since" and "In Call" were frozen | Tick every second; Since shows "15h 4m" |

## 5. Call Control (per call profile)
New `server-configs/call-control/`:
- `ringq_call_control.lua` goes to `/usr/share/freeswitch/scripts/`.
- `install.sql` adds the global dialplan `ringq-call-control` (order 36) and updates `local_extension`.
- After installing, delete `/var/cache/ringq/dialplan.*`.

| Setting | Before | Now |
|---|---|---|
| Status (Available/Away/Busy/Custom 1/Custom 2) | Changes didn't reliably apply | The active dialer profile (Redis `<ext>+<domain>`) applies on the next call |
| Always | Worked | Unchanged, per profile |
| On Busy | Ignored (always voicemail); External destination not stored | Busy/rejected/DND go to the destination |
| No Answer time | Worked | Kept between 5 and 300 s |
| No Answer Action | Ignored (always voicemail) | Not answered / not reachable go to the destination |
| Call Relay | Not used | Up to 3 destinations in turn; extensions ring their phones only |
| Parallel Ringing | Never loaded or saved in the UI; not used | Rings the mobile number together with the extension |
| Call Screening | Not used | Caller records name; press 1 to accept |

React:
- Parallel Ringing loads and saves.
- "Own Voicemail" label replaces "Voicemail: <own ext>".
- Ring-time bounds.
- (i) help tips on every setting.
- A warning when Parallel Ringing is on without a mobile number.

## Deploy notes
1. ringq_core: deploy `node/`, run `q_call_journeys.sql`, `pm2 restart server signal attended queue`.
2. FreeSWITCH: install `server-configs/call-control` (see its README).
3. React: `npx vite build --base=/cloudapp/` and deploy to `/var/www/webapp/cloudapp`.

## Known open items
- The encrypted PHP save of Call Relay, Parallel Ringing, Call Screening and mobile number into `q_call_behaviors` is not yet verified.
- Voicemail Greeting per status: Record/Upload not built.
- Parallel/Relay to outside numbers is not yet tested with a real call (uses the outbound rules).
- An attended transfer to a target whose No Answer Action isn't voicemail follows that forward instead of returning to the agent.

## 6. Live presence and call resume after refresh (added later on 2 Oct)
- Dialer Contacts: the dot shows the live state, with a label: Ringing (yellow, blinking), On call · number (red, blinking), Offline (no phone/app registered), Away, Busy, Custom, Available.
- `signal.js` also listens to `sofia::expire`, so expired devices drop off quickly. The web app registers for 300 s (was 3600 s) and unregisters when the tab closes.
- Refresh during a call: the browser asks "Leave site?" first. If the page reloads anyway, the app asks the server (`resumeCall`, matched on the SIP Call-ID and owner) to ring it again. It auto-answers, and the other party is taken over with `intercept`, so the call continues.
- Fixed a crash in `signal.js` on web-app connect (`Agent` header missing on some registrations).
