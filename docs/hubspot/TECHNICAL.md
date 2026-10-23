# HubSpot integration: technical reference

User guide: [`USER_GUIDE.md`](USER_GUIDE.md). Endpoint reference: [`API.md`](API.md).

The HubSpot integration sits beside Qontak and Freshdesk and is built the same way. It is self-contained: its own controller, library, tables, cron task and Redis keys (`hubspot:*`). It does not change Qontak, Freshdesk, the RingQ dialer, or any other controller. Calls are made and answered in RingQ; there is no dialer inside HubSpot.

## Files

| File | Purpose |
|---|---|
| `app/controllers/HubspotcrmController.php` | Routes `hubspotcrm/*` (Phalcon convention routing, no router change). |
| `app/library/Hubspot/HubspotService.php` | Config, contact lookup, call journaling (idempotent, one HubSpot call per RingQ call), recordings, AI. |
| `app/library/Hubspot/HubspotClient.php` | HubSpot API client (Bearer token, 401/403/429 handling, never logs the token). |
| `app/library/Hubspot/HubspotCrypto.php` | Encrypts the token stored in the database. The key is derived from `application.cryptSalt` in `app/config/config.php`, so no key file is needed. |
| `app/library/Hubspot/HubspotTranscripts.php`, `HubspotException.php` | Optional AI transcription (RingQ's own), and the error type. |
| `app/tasks/HubspotcrmTask.php` | `cli.php hubspotcrm sync` (cron), `hubspotcrm after` (per-call follow-up), `hubspotcrm verify` (read-only check). |
| `app/tasks/tasks.txt` | Cron schedule; includes `cli.php hubspotcrm sync`. |
| `app/scripts/hubspot/2026_10_21_hubspot_crm.sql` | Tables `q_hubspot_config`, `q_hubspot_call_journals`. |
| `app/scripts/hubspot/2026_10_22_hubspot_cti.sql` | Table `q_hubspot_cti_tokens` and two journal columns the code expects. Run it even though no dialer inside HubSpot is used. |
| `app/scripts/hubspot/2026_10_21_hubspot_crm_rollback.sql` | Drops all three HubSpot tables. |
| `app/config/services.php` | Two ACL lines next to `"qontak"` (below). |

ACL entries:

```php
// $publicly  (no login: HubSpot downloads recordings)
"hubspotcrm" => ["recording", "cti"],
// $privately (logged-in users; admin-only actions are checked in the controller with isPlainUser())
"hubspotcrm" => ["config", "testConnection", "disconnect", "contactsSearch", "contact", "contacts", "callEvents", "callJournals", "recordingsSync", "aiAnalysis", "ctiPair", "cti"],
```

`ctiPair` and `cti` serve an optional dialer inside HubSpot, which is not part of this setup; they stay unused.

## Deploy on the server

1. **Files:** copy `app/` of this repository to `/var/www/ringq/app/` (the HubSpot files, `services.php` with the two ACL lines, and `tasks.txt`).
2. **Database**, once, in this order (both are safe to run again):

   ```bash
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < app/scripts/hubspot/2026_10_21_hubspot_crm.sql
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < app/scripts/hubspot/2026_10_22_hubspot_cti.sql
   ```

   If the app database user is not the table owner:

   ```sql
   GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_hubspot_config, public.q_hubspot_call_journals, public.q_hubspot_cti_tokens TO <app_db_user>;
   ```

3. **Cron:** `app/tasks/tasks.txt` (deployed with this backend) schedules `cli.php hubspotcrm sync` every minute; RingQ's `app/scripts/jobs.sh` turns `tasks.txt` into root's crontab. Without it, failed syncs are never retried and recordings that appear after hang-up are never attached. Check: `crontab -l | grep hubspotcrm`.
4. **nginx (recording links):** HubSpot plays only `.wav`/`.mp3` links, so recording links end in `/call.wav` or `/call.mp3`. The API server's static-file rule (`location ~* \.(?:...|wav|mp3|...)$`) answers those with 404. Add this to the `listen 8443` server block, **before** that rule, then `nginx -t` and `systemctl reload nginx`:

   ```nginx
   location ^~ /hubspotcrm/recording/ {
       try_files /nonexistent /index.php?_url=$uri&$args;
   }
   ```

   Check: `curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://<host>:8443/hubspotcrm/recording/00000000-0000-0000-0000-000000000000/0/aaaaaaaaaaaaaaaaaaaaaa/call.wav` must print `404 text/plain` (the app's answer), not `404 text/html` (nginx).
5. **Reload PHP:** `sudo systemctl reload php7.4-fpm`.

The RingQ web app part (settings screen, contact panel, call bridge) is in the frontend repository, `src/integrations/hubspot/`, and is deployed with the RingQ web app.

## HubSpot side

**Private app:** HubSpot → Settings → Integrations → Private Apps → create "RingQ".
- Scopes: `crm.objects.contacts.read`, `crm.objects.contacts.write`, and optionally `crm.objects.companies.read`.
- Copy the token into RingQ → Settings → Integrations → CRM → HubSpot, then click **Test connection** and **Save**.

## Check after deploy

- Logged out, `https://<host>:8443/hubspotcrm/config` answers **401**. Logged in (as an admin, in the RingQ web app), it returns `result: "success"` and **no** token field.
- `Sorry, this is not available. (Code AC4031)` means the `services.php` ACL lines are not on the server.
- `HubSpot database tables are missing` means step 2 was not run.
- Logs: `grep "\[hubspot\]" /var/log/php7.4-fpm.log`. They never contain the token.
- **What HubSpot actually holds** (read-only): `php /var/www/ringq/app/cli.php hubspotcrm verify 10` prints, for the last 10 calls, RingQ's journal next to the HubSpot call (status, direction, duration, associated contacts) and whether its recording link really serves audio.

## Call flow

1. **Call starts** in RingQ: RingQ creates the HubSpot call (status RINGING) on the matching contact, so the call shows on the contact while it rings (`syncLive`).
2. **Answered:** the same HubSpot call becomes IN_PROGRESS.
3. **Ended:** the same HubSpot call gets the final status (COMPLETED, NO_ANSWER, BUSY, FAILED, MISSED), talk time, outcome and notes (`syncJournal`). The RingQ call reference (`RQ...`) is in the title and notes.
4. **Recording:** for answered calls, RingQ looks for the PBX recording for 30 minutes after hang-up. When found, the call gets a signed `hs_call_recording_url` and HubSpot plays it. Otherwise the notes say it is not available.

One RingQ call is one HubSpot call: `hubspot_call_id` is unique per journal. An incoming call ringing on two of the agent's devices shares one HubSpot call, and the copy that is logged owns it. Unanswered calls are shown live only while **Log unanswered calls** is on; with it off, nothing is created for them.

## Rollback

1. Remove the two `"hubspotcrm"` ACL lines and the cron line, then reload PHP-FPM. Qontak, Freshdesk and RingQ calling are unaffected.
2. Optional, after `pg_dump -t 'public.q_hubspot_*'`: run `2026_10_21_hubspot_crm_rollback.sql`.

## Limitations

| Item | Detail |
|---|---|
| No dialer inside HubSpot | Calls are made and answered in RingQ. Every call still appears live on the HubSpot contact. |
| Recording playback in HubSpot | HubSpot downloads the recording from `https://<tenant>:8443/hubspotcrm/recording/...`. If HubSpot can't reach port 8443, set **Recording link address** in the HubSpot settings to a public HTTPS origin. |
| Call status / outcome values | Read from your HubSpot portal at Test connection. Only values your portal offers are sent. |
| Outbound busy | The RingQ web dialer does not expose the SIP reject code, so busy is logged as "no answer". |
| Call owner | `hubspot_owner_id` is not set; the agent's name and extension are in the call notes. |
| Outbound calls to outside numbers | Need an outbound route on the tenant's PBX. |
| AI summary / sentiment | No analysis provider in RingQ yet, so these stay inactive. Transcription uses RingQ's own service. |
