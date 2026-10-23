# HubSpot CRM integration: backend

The HubSpot integration is part of this backend (branch `feature/hubspot-crm`). It sits beside Qontak and is built the same way. It is self-contained: its own controller, library, tables, cron task and Redis keys (`hubspot:*`). It does not change Qontak, the dialer, or any other controller.

**Frontend:** the matching web-app code is in the frontend repo `D:\ringq_odekcrm` (same branch name): `src/integrations/hubspot/` plus the HubSpot calling window `hubspot-cti/`.

## What is in this backend

| File | Purpose |
|---|---|
| `app/controllers/HubspotcrmController.php` | Routes `hubspotcrm/*` (Phalcon convention routing, no router change). |
| `app/library/Hubspot/HubspotService.php` | Config, contact lookup, call journaling (idempotent, one HubSpot call per RingQ call), recordings, AI, HubSpot dialer tokens. |
| `app/library/Hubspot/HubspotClient.php` | HubSpot API client (Bearer token, 401/403/429 handling, never logs the token). |
| `app/library/Hubspot/HubspotCrypto.php` | Encrypts the token stored in the database. The key is derived from `application.cryptSalt` in `app/config/config.php`, so no key file is needed. |
| `app/library/Hubspot/HubspotTranscripts.php`, `HubspotException.php` | Optional AI transcription (RingQ's own), and the error type. |
| `app/tasks/HubspotcrmTask.php` | `cli.php hubspotcrm sync` (cron) and `hubspotcrm after` (per-call follow-up). |
| `app/scripts/hubspot/2026_10_21_hubspot_crm.sql` | Tables `q_hubspot_config`, `q_hubspot_call_journals`. |
| `app/scripts/hubspot/2026_10_22_hubspot_cti.sql` | Table `q_hubspot_cti_tokens`, plus support for the calling window inside HubSpot. |
| `app/scripts/hubspot/2026_10_21_hubspot_crm_rollback.sql` | Drops all three HubSpot tables. |
| `app/config/services.php` | Two ACL lines added next to `"qontak"`. |
| `app/tasks/tasks.txt` | One cron line added: `cli.php hubspotcrm sync`. |

ACL entries (already added):

```php
// $publicly  (no login: HubSpot downloads recordings; the HubSpot calling window sends its own token)
"hubspotcrm" => ["recording", "cti"],
// $privately (logged-in users; admin-only actions are checked in the controller with isPlainUser())
"hubspotcrm" => ["config", "testConnection", "disconnect", "contactsSearch", "contact", "contacts", "callEvents", "callJournals", "recordingsSync", "aiAnalysis", "ctiPair", "cti"],
```

## Deploy on the server (e.g. sgringq100)

1. **Deploy this backend** as usual. At minimum, copy the files above to `/var/www/ringq/app/...`, and apply the `services.php` and `tasks.txt` changes.
2. **Database**, once, in this order (both are safe to run again):

   ```bash
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -f app/scripts/hubspot/2026_10_21_hubspot_crm.sql
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -f app/scripts/hubspot/2026_10_22_hubspot_cti.sql
   ```

   If the app database user is not the table owner:

   ```sql
   GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_hubspot_config, public.q_hubspot_call_journals, public.q_hubspot_cti_tokens TO <app_db_user>;
   ```

3. **Cron:** add the line from `tasks.txt` to the server crontab:

   ```
   */1 * * * * /usr/bin/php /var/www/ringq/app/cli.php hubspotcrm sync
   ```

   Without it, failed syncs are never retried, and recordings that appear after hang-up are never attached (check: `crontab -l | grep hubspotcrm`).
4. **nginx (recording links):** HubSpot plays only `.wav`/`.mp3` links, so recording links end in `/call.wav` or `/call.mp3`. The API server's static-file rule (`location ~* \.(?:...|wav|mp3|...)$`) answers those with 404. Add this to the `listen 8443` server block, **before** that rule, then `nginx -t` and `systemctl reload nginx`:

   ```nginx
   location ^~ /hubspotcrm/recording/ {
       try_files /nonexistent /index.php?_url=$uri&$args;
   }
   ```

   Check: `curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://<host>:8443/hubspotcrm/recording/00000000-0000-0000-0000-000000000000/0/aaaaaaaaaaaaaaaaaaaaaa/call.wav` must print `404 text/plain` (the app's answer), not `404 text/html` (nginx).
5. **Reload PHP:**

   ```bash
   sudo systemctl reload php7.4-fpm
   ```

6. **Frontend:** build and deploy from the frontend repo (`D:\ringq_odekcrm`, branch `feature/hubspot-crm`):
   - Web app:

     ```bash
     npx vite build --base=/cloudapp/
     ```

     Then copy `dist/` to `/var/www/webapp/cloudapp`.
   - HubSpot calling window:

     ```bash
     npm run build:hubspotcti
     ```

     Then copy `dist-hubspotcti/` into a new `hubspot/` folder inside the folder that serves `/click2call/`. See `HUBSPOT_DIALER.md`.

   The prebuilt `frontend-build/cloudapp` in this repo is an older Qontak-only build. Don't use it for HubSpot.

   **Building from Git Bash on Windows:** prefix the command with `MSYS_NO_PATHCONV=1` (e.g. `MSYS_NO_PATHCONV=1 npx vite build --base=/cloudapp/`). Without it, Git Bash rewrites `/cloudapp/` into a Windows path and every asset link breaks. Check that `dist/index.html` contains `src="/cloudapp/assets/..."`.

## Deployed on sgringq100 (22 Oct 2026)

- **Backend:** HubSpot files in `/var/www/ringq/app`, both `hubspotcrm` ACL lines in `services.php`, and both SQL files (tables owned by `ringq`). Backups are in `/root/hubspot-deploy-20261022-0927/backup/`.
- **Fixed on 23 Oct 2026:** the cron line was missing from the server (added to root's crontab; previous crontab in `/root/crontab.bak-*`), and the nginx recording rule above was added (previous config in `/root/nginx-default.bak-*`). Until then every recording link in HubSpot returned 404, and failed syncs were never retried.
- **Frontend:** web app with HubSpot at `https://sgringq100.ringq.ai/cloudapp_hubspot/`, and the HubSpot calling window at `https://sgringq100.ringq.ai/click2call/hubspot/`.
- **Not changed:** `/cloudapp`, `/cloudapp_qontak`, the Salesforce `/click2call` files, and all Qontak backend files.
- **Switching the main app later:** back up `/var/www/webapp/cloudapp`, then copy a build made with `--base=/cloudapp/` over it.

## HubSpot side

1. **Private app (CRM sync):** HubSpot → Settings → Integrations → Private Apps → create "RingQ".
   - Scopes: `crm.objects.contacts.read`, `crm.objects.contacts.write`, and optionally `crm.objects.companies.read`.
   - Copy the token into RingQ → Global Settings → Integrations → CRM → HubSpot, then click **Test connection** and **Save**.
2. **Calling inside HubSpot (CTI):** this needs a HubSpot developer app with calling settings. See `HUBSPOT_DIALER.md`.

## Check after deploy

- In the browser console of the logged-in web app:

  ```js
  await (await fetch('https://<host>:8443/hubspotcrm/config', {credentials:'include'})).json()
  ```

  This should return `result: "success"` and **no** token field.
- `Sorry, this is not available. (Code AC4031)` means the `services.php` ACL lines are not on the server.
- `HubSpot database tables are missing` means step 2 was not run.
- Logs: `grep "\[hubspot\]" /var/log/php7.4-fpm.log`. They never contain the token.
- **What HubSpot actually holds** (read-only): `php app/cli.php hubspotcrm verify 10` prints, for the last 10 calls, RingQ's journal next to the HubSpot call (status, direction, duration, associated contacts) and whether its recording link really serves audio.

## Call flow

1. **Call starts** (RingQ app, HubSpot dialer, or click-to-call): the dialer posts `started`. RingQ replies at once, then creates the HubSpot call (status RINGING) on the matching contact, so the call shows on the contact while it rings (`syncLive`).
2. **Answered:** the same HubSpot call becomes IN_PROGRESS.
3. **Ended:** the same HubSpot call gets the final status (COMPLETED, NO_ANSWER, BUSY, FAILED, MISSED), talk time, outcome and notes (`syncJournal`). The RingQ call reference (`RQ...`) is in the title and notes.
4. **Recording:** for answered calls, RingQ looks for the PBX recording for 30 minutes after hang-up. When found, the call gets a signed `hs_call_recording_url` and HubSpot plays it. Otherwise the notes say it is not available.

One RingQ call is one HubSpot call: `hubspot_call_id` is unique per journal. An incoming call ringing on two of the agent's devices shares one HubSpot call, and the copy that is logged owns it. Unanswered calls are shown live only while **Log unanswered calls** is on; with it off, nothing is created for them.

## Rollback

1. Remove the two `"hubspotcrm"` ACL lines and the cron line, then reload PHP-FPM. Qontak and the dialer are unaffected.
2. Optional, after `pg_dump -t 'public.q_hubspot_*'`: run `2026_10_21_hubspot_crm_rollback.sql`.

## Limitations

| Item | Detail |
|---|---|
| Recording playback in HubSpot | HubSpot downloads the recording from `https://<tenant>:8443/hubspotcrm/recording/...`. If HubSpot can't reach port 8443, set **Recording link address** in the HubSpot settings to a public HTTPS origin. |
| Call status / outcome values | Read from your HubSpot portal at Test connection. Only values your portal offers are sent. |
| Outbound busy (RingQ web app) | The web app's dialer does not expose the SIP reject code, so busy is logged as "no answer". The HubSpot calling window does detect busy. |
| Call owner | `hubspot_owner_id` is not set; the agent's name and extension are in the call notes. |
| HubSpot's own calling window | Only **public** apps can use HubSpot's Calling Extensions SDK (HubSpot docs: "Extensions overview"). Account 247741894 cannot create legacy apps, and its private (projects) app supports UI extensions only, so RingQ is not a HubSpot calling provider there. The RingQ card on contacts opens the RingQ dialer instead, and every call shows live on the contact (above). The dialer already implements the SDK, so it can be registered once a public app exists (see `HUBSPOT_DIALER.md`). |
| Outbound calls to outside numbers | Need an outbound route on the tenant's PBX. sgringq100.ringq.ai has none (checked 23 Oct 2026): calls to +91... end as "Outbound Caller Abandoned" and never leave the PBX. |
| AI summary / sentiment | No analysis provider in RingQ yet, so these stay inactive. Transcription uses RingQ's own service. |

Full endpoint reference: `API.md`. Calling inside HubSpot: `HUBSPOT_DIALER.md`.
