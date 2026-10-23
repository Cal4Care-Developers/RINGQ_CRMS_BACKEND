# Freshdesk integration: backend

The Freshdesk integration sits beside Qontak and HubSpot and is built the same way. It is self-contained: its own controller, library, tables, cron task and Redis keys (`freshdesk:*`). It does not change Qontak, HubSpot, the dialer, or any other controller.

**Frontend:** the matching web-app code is in the frontend repo `D:\ringq_odekcrm` (branch `feature/freshdesk-crm`):
- `src/integrations/freshdesk/`: settings card and call popup in the RingQ web app.
- `freshdesk-cti/`: the RingQ dialer page shown inside Freshdesk.
- `freshdesk-app/`: the Freshdesk (Freshworks) app that puts that dialer into Freshdesk's CTI sidebar.

## How Freshdesk is used

Freshdesk is a help desk. Its public API has **no call object**, so:

| RingQ | Freshdesk |
|---|---|
| Caller lookup | Contacts, searched by `phone` and `mobile`, also in other formats (+65…, 65…, national) |
| One call | **One ticket** with source **Phone** (3) on the caller's contact. Subject and tags carry the call reference `RQxxxxxxxxxx` |
| Call details | Ticket description: direction, caller and recipient numbers, start/answer/end times, duration, talk time, outcome, disposition, agent and extension, RingQ call ID |
| Agent notes and outcome | A private note on the ticket (updated in place when edited again) |
| Recording | A private note on the ticket with the audio file **attached**. Over 15 MB (Freshdesk allows 20 MB of attachments per ticket), or if the upload is refused, a signed RingQ link instead |
| Unknown caller | Not logged in Freshdesk, unless the agent picks or creates the contact in the popup, or the admin chose "Create a contact, then log the call" |

## Files

| File | Purpose |
|---|---|
| `app/controllers/FreshdeskcrmController.php` | Routes `freshdeskcrm/*` (Phalcon convention routing, no router change). |
| `app/library/Freshdesk/FreshdeskService.php` | Config, contact lookup, call journaling (one ticket per call, duplicate-safe), notes, recordings, Freshdesk dialer tokens. |
| `app/library/Freshdesk/FreshdeskClient.php` | Freshdesk API v2 client (Basic auth with the API key, 401/403/404/409/429 handling, never logs the key, never follows redirects). |
| `app/library/Freshdesk/FreshdeskCrypto.php` | Encrypts the stored API key (AES-256-GCM, key derived from `application.cryptSalt`) and signs recording links. |
| `app/library/Freshdesk/FreshdeskException.php` | Error type with a stable `reason`. |
| `app/tasks/FreshdeskcrmTask.php` | `cli.php freshdeskcrm sync` (cron) and `freshdeskcrm after` (per-call follow-up). |
| `app/scripts/freshdesk/2026_10_23_freshdesk_crm.sql` | Tables `q_freshdesk_config`, `q_freshdesk_call_journals`, `q_freshdesk_cti_tokens`. |
| `app/scripts/freshdesk/2026_10_23_freshdesk_crm_rollback.sql` | Drops those three tables. |
| `tests/freshdesk/freshdesk_unit_test.php` | Unit checks (phone matching, validation, ticket body, encryption, signatures): `php tests/freshdesk/freshdesk_unit_test.php`. |
| `tests/freshdesk/freshdesk_live_check.php` | Checks a real Freshdesk account with the integration's own client (read-only unless `--write`). |

**No existing file changes.** `config/services.php` is not edited: `FreshdeskcrmController::beforeExecuteRoute()` adds its own entries to RingQ's ACL before `ControllerBase` checks it. That matters on servers that run the ionCube-encoded CI build, where `services.php` cannot be edited. The entries are the same as these `services.php` lines would be:

```php
// $publicly  (no login: signed recording links; the Freshdesk dialer sends its own token)
"freshdeskcrm" => ["recording", "cti"],
// $privately (logged-in users; admin-only actions are checked in the controller with isPlainUser())
"freshdeskcrm" => ["config", "testConnection", "disconnect", "contactsSearch", "contact", "contacts", "callEvents", "callJournals", "recordingsSync", "ctiPair", "cti"],
```

## Deploy on the server (e.g. sgringq100)

`sudo bash server-configs/freshdesk/install.sh` (this repository) does steps 1–4 and checks the result; see `FRESHDESK_INTEGRATION_GUIDE.md` for the full setup and user guide. By hand:

1. **Copy the files** above to `/var/www/ringq/app/...`.
2. **Database**, once (safe to run again):

   ```bash
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -f app/scripts/freshdesk/2026_10_23_freshdesk_crm.sql
   ```

   If the app database user is not the table owner:

   ```sql
   GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_freshdesk_config, public.q_freshdesk_call_journals, public.q_freshdesk_cti_tokens TO <app_db_user>;
   ```

3. **Cron** (retries, late recordings, edited notes), as its own file `/etc/cron.d/ringq-freshdesk`:

   ```
   * * * * * root /usr/bin/php7.4 /var/www/ringq/app/cli.php freshdeskcrm sync >/dev/null 2>&1
   ```

   Not in root's crontab: RingQ's `app/scripts/jobs.sh` rebuilds that from `app/tasks/tasks.txt`, so a line added there disappears within a minute (seen on sgringq100).

4. **Reload PHP:** `sudo systemctl reload php7.4-fpm`.
5. **Requirements:** PHP cURL and OpenSSL (already used by the HubSpot module). The server must reach `https://<account>.freshdesk.com` on port 443.

Recording links (`/freshdeskcrm/recording/<id>/<exp>/<sig>`) have no file extension, so the nginx static-file rule that HubSpot needed a bypass for does not apply.

## Freshdesk side (admin, once)

1. **API key:** in Freshdesk, sign in as the agent that tickets should be created by, then click the profile picture → **Profile settings** → **View API key**. That agent needs permission to view and create contacts and tickets.
2. In RingQ: **Integrations → CRM → Freshdesk**. Enter the address (`yourcompany.freshdesk.com`) and the API key, switch **Enable** on, then **Save** (it tests the connection right away).
   Use the help desk's own `*.freshdesk.com` address, as shown in the browser once you are inside the help desk. Newer Freshworks accounts sign in at `yourcompany.myfreshworks.com`; that is not the help desk address, and the API does not work on it. (Checked 23 Oct 2026: `cal4care.freshdesk.com` answers "We couldn't find …", so it is not the address of the Cal4Care help desk.)
3. If Freshdesk marks custom ticket fields as **required**, the settings show a warning. RingQ cannot fill custom fields. A ticket that Freshdesk refuses to create as Resolved or Closed is created as **Open** instead; a field required for *every* ticket blocks creation entirely. Make such fields optional for agents (Admin → Ticket fields).

## Calling from inside Freshdesk (CTI app)

See `D:\ringq_odekcrm\freshdesk-app\README.md`. In short:
1. Build the dialer page: `npx vite build --config vite.freshdeskcti.config.js`. Upload **the contents** of `dist-freshdeskcti/` to a new `freshdesk/` folder inside the folder that serves `https://<tenant>/click2call/` (next to the HubSpot dialer's `hubspot/` folder). It must be served without `X-Frame-Options`, the same as `/click2call/hubspot/`.
2. Pack `freshdesk-app/` with the Freshworks CLI (`fdk validate`, `fdk pack`), upload it as a **custom app**, install it, and enter the RingQ server address.

## API

| Endpoint | Who | Notes |
|---|---|---|
| `GET freshdeskcrm/config` | any user | Settings. Never returns the API key; `key_hint`, `ticket_field_warnings`, `last_error` only for admins. |
| `POST freshdeskcrm/config` | admin | `{enabled, domain, api_key ("" keeps the stored key), popup_incoming, popup_outgoing, call_journaling, log_unanswered, unmatched_calls ("skip"/"create_contact"), contact_creation, ticket_status (2-5), missed_ticket_status, ticket_priority (1-4), recording_upload, recording_link, screenpop_incoming, screenpop_outgoing, screenpop_target ("contact_tab"/"window"), country_code, timezone}`. New or changed credentials are tested right away. |
| `POST freshdeskcrm/testConnection` | admin, 10/min | `{}` tests the stored key; `{domain, api_key}` tests unsaved values (not stored). Calls `GET agents/me`, `GET account` (best effort), `GET ticket_fields`. |
| `POST freshdeskcrm/disconnect` | admin | Deletes the key, disables, revokes every recording link and every Freshdesk dialer token. Call logs are kept. |
| `GET freshdeskcrm/contactsSearch?phone=` | any user | Caller lookup (6+ digits). `?q=` searches names (autocomplete) or an exact email. |
| `GET freshdeskcrm/contact?id=` | any user | One contact (404 when deleted or merged). |
| `POST freshdeskcrm/contacts` | any user, if "Contact creation" is on | `{name, phone, email?}`. Never creates a duplicate: an existing contact with the number (or email) is returned with `created:false`. |
| `POST freshdeskcrm/callEvents` | any user | `{local_call_id, event: started/answered/ended, direction, remote_number, status, started_at, answered_at, ended_at, talk_duration, freshdesk_contact_id?}`. Idempotent per call; out-of-order events after the final one are ignored. |
| `GET freshdeskcrm/callJournals?local_call_id=` | own calls (admins: all) | One call log; or a list with `?page=&per_page=&phone=`. |
| `POST freshdeskcrm/callJournals` | own calls | `{action:"retry"}`, `{action:"notes", notes, disposition}`, `{action:"contact", freshdesk_contact_id}`. |
| `POST freshdeskcrm/recordingsSync` | admin | `{local_call_id}`: look for the recording again and attach it. |
| `POST freshdeskcrm/ctiPair` | logged-in user | Dialer token for the Freshdesk CTI app (shown once, SHA-256 stored, 30 days, max 5 per user). |
| `POST freshdeskcrm/cti` | public, token in body | `op`: `session`, `callEvent`, `callerContact`, `contactsSearch`, `createContact`, `linkContact`, `notes`, `journal`, `recents`, `logout`. More than 30 bad tokens per IP in 10 minutes returns 429. |
| `GET freshdeskcrm/recording/{journal}/{exp}/{sig}` | public, signed | Streams the recording while Freshdesk is connected; 404 otherwise. |

Errors use the usual `{result:"error", message}` with: 401 not logged in, 403 not allowed, 404 not found, 409 not configured/disabled, 422 validation, 429 throttled, 500 setup problem (the message names the missing step), 502 Freshdesk rejected the API key, 503 Freshdesk unreachable or rate-limited. Messages never contain the API key or raw Freshdesk responses.

## How duplicates are prevented

- **One journal per call:** `UNIQUE (domain_uuid, local_call_id)`; repeated events are no-ops, and once a call has a final status later events are ignored.
- **One ticket per call:**
  - a row lock (`sync_locked_at`) serialises workers;
  - `create_attempted_at` is stored **before** `POST /tickets` and the ticket id right after;
  - after a crash between the two, the ticket is found again (by the contact's recent tickets and the `RQ…` reference in the subject, then by tag search) instead of being created twice;
  - a unique index on `(domain_uuid, freshdesk_ticket_id)`.
- **One recording note:** the same pattern with `recording_attempted_at` and `recording_note_id` (recovered from the ticket's conversations).
- **One call that rings on two devices** (RingQ app + Freshdesk dialer): an unanswered copy is skipped when another copy of the same inbound call for the same agent was answered or is already being logged. Unanswered inbound calls are saved 20 s after hang-up to allow for this.

## What is not done (by design)

- **No guessing:** a number shared by several contacts is never logged on one of them until the agent picks it.
- **Agent mapping:** tickets are created by the agent whose API key is stored. The RingQ agent's name and extension are written into the ticket.
- **AI transcription / summary:** not part of this integration.
