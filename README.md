# ringq_core

Backend of RingQ: PHP API (Phalcon, `app/`), Node services (`node/`, run by PM2) and the
server-side FreeSWITCH / nginx pieces (`server-configs/`). The web app is in the separate repo
`ringq_react_app`.

---

## 1. How a change reaches a server

```
developer / apple.ringq.ai (dev server)
        │  git push  (main)
        ▼
GitHub ──► CI/CD pipeline ──► encrypts PHP (ionCube) + obfuscates JS ──► central file server
                                                                              │
                         customer server: Settings → Security → Update  ◄─────┘
                         (or nightly auto-update, if enabled)
                                        │
                                        ▼
                     files replaced ──► jobs.sh (restart_jobs) ──► CM adjustments (see §3)
```

* **`apple.ringq.ai` is the development server.** Changes are made and tested there, then pushed
  to `main`. Every push is a release candidate: the pipeline publishes it to the file server.
* **Always `git pull` (merge `origin/main`) before you push.** Never overwrite someone else's
  newer file with an older copy.
* A server only changes when it runs **Update**. Updating apple brings it the file-server build,
  so only update apple after the pipeline has finished building the latest `main`.

## 2. What the update delivers by itself

| Part | Folder | Notes |
|---|---|---|
| PHP API | `app/` | encrypted by the pipeline |
| Node services | `node/*.js` | obfuscated by the pipeline; PM2 restarts them |
| Web app | repo `ringq_react_app` | built to `/var/www/webapp/cloudapp` |

Several Node features also set themselves up at start (no install needed): hold-music per
extension, the "Calls off" list, the 24-hour mobile device memory, presence refresh.

## 3. Server-side pieces → CM adjustments inside `app/scripts/jobs.sh`

FreeSWITCH scripts, global dialplans, database tables, the health check and nginx routes are
server setup, not code. They are applied by the **CM adjustment** block in
**`app/scripts/jobs.sh`** (function `cm_adjustments`, marked `# >>> BEGIN cm-adjustments >>>`,
comment "added by CM"). jobs.sh is the main script: it ships with every update and the
`restart_jobs` step after every update runs `cm_adjustments` automatically — no extra update
step and no `server-configs/` folder needed on the server (the files are embedded in jobs.sh).

It is idempotent (checks first, changes only what is missing or wrong, backs up what it replaces
to `/var/backups/ringq-cm-install/`, no restarts) and non-fatal. Log: `/var/log/ringq-cm-install.log`.

Run it by hand on a server (inside the `ringq` container on Docker servers):

```bash
bash /var/www/ringq/app/scripts/jobs.sh --cm-adjust --check   # report only; exit 1 if anything needs fixing
bash /var/www/ringq/app/scripts/jobs.sh --cm-adjust           # install / repair
bash /var/www/ringq/app/scripts/jobs.sh --cm-adjust --force   # also replace a customised local_extension
```

From a repo checkout, `bash server-configs/cm-install.sh [--check|--force]` does the same (it is
now only a wrapper; on a Docker host it runs inside the container).

| Installs / repairs | Source | Feature it enables |
|---|---|---|
| `/usr/share/freeswitch/scripts/ringq_call_control.lua` | `call-control/` | Call Control: forwarding per call profile, relay, parallel ring, screening, on-busy / no-answer |
| global dialplan `ringq-call-control` (order 36) | `call-control/ringq-call-control.xml` | same |
| `local_extension` dialplan | `call-control/local_extension.xml` | screening, parallel ring and failover hooks. **The update package currently resets it to FusionPBX stock — the script puts it back.** |
| global dialplan `ringq-trunk-media-timeout` (order 37) | `trunk-media-timeout/` | ends trunk calls 10 s after the carrier stops sending audio (late BYE) |
| `.../configuration/callcenter.conf.lua` | `queue-strategy/` | queue strategies (longest idle, least talk time, fewest calls, skill-based) and agent order / skill |
| tables `q_call_center_tier_order`, `q_call_journeys` | `queue-strategy/`, `node/` | queue agent order + skill; call journey history (Call Status / Call Logs) |
| `/usr/local/sbin/ringq-health.sh` + `/etc/cron.d/ringq-health` | `health/` | self-heals the phone-side SIP profile; alerts on trunks, PM2 restart loops, disk |
| nginx `location ^~ /provision/2db3f557e1d6/` on port 80 | (inside the script) | local-LAN auto provisioning over http, private networks only |

Details: `server-configs/CM-INSTALL.md` and the README in each sub-folder.

**Editing / adding a server-side piece:** the files under `server-configs/` (and
`node/q_call_journeys.sql`) are the editable sources; jobs.sh carries embedded copies. After any
change run `python3 server-configs/cm-embed.py` and commit jobs.sh with it
(`python3 server-configs/cm-embed.py --verify` fails when jobs.sh is out of date — good for CI).
A new piece: add the file to `PAYLOADS` and a step to the `LOGIC` template in `cm-embed.py`
(check → install → verify), regenerate, test with `--cm-adjust --check` first.

## 4. Node services (PM2)

| PM2 name | File | Role |
|---|---|---|
| `server` | `node/server.js` | FreeSWITCH events (ESL), push notifications (iOS VoIP / Android), queues |
| `signal` | `node/signal.js` | Socket.IO API for the web/desktop/mobile apps (port 3000 behind nginx 9443) |
| `status` | `node/status.js` | Android app connections (port 4060) |
| `queue`, `attended`, `chat`, `sso`, ... | `node/*.js` | queue board, attended transfer, chat, SSO, ... |

Socket events added on `signal` (all check the caller's PHP session where they change data):
`myDevices`, `killMyDevice` (live connections + disconnect), `getVoicemailSettings` /
`setVoicemailSettings`, `getExtensionSchedule`, `getQueueTiers` / `setQueueTiers`, `resumeCall`,
`getCallJourneys`. `gatewaystart` (trunk save) now reloads only the changed trunk and is accepted
only from the local PHP API.

## 5. Known issues for the PHP / update side

1. **Update package resets `local_extension`** to stock → Call Control screening / parallel ring /
   failover stop working until jobs.sh's CM adjustments put it back (seconds later, after restart_jobs). Remove it from the package's database statements.
2. **`autoupdateAction` (app/tasks/MainTask.php)**: the version check is reversed — it skips the
   update when the server version differs from the central one.
3. **Credentials for the central update server are hard-coded** in `MainTask.php`,
   `RegisterController.php` and `AccountsController.php`. Rotate them and move them to config.
4. **`health/` folder is missing from the update package.**
5. **Voicemail on/off**: the web app sends `1` for on, the PHP source treats `2` as on. The web
   app now writes the flag through `signal.js` (`setVoicemailSettings`), which is authoritative.
6. **Every update restarts FreeSWITCH**, and on Docker servers the service watchdog restarts it
   again while it is starting — calls drop several times. The watchdog should wait for start-up.

## 6. Change log

See `CHANGELOG-*.md` and the commit history of `main`.
