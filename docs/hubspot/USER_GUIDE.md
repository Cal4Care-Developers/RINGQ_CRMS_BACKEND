# RingQ + HubSpot: setup and user guide

1. [What the integration does](#1-what-the-integration-does)
2. [Before you start](#2-before-you-start)
3. [Sign in to HubSpot and create the access token](#3-sign-in-to-hubspot-and-create-the-access-token)
4. [Install the RingQ side on the server (RingQ admin)](#4-install-the-ringq-side-on-the-server-ringq-admin)
5. [Configure HubSpot in RingQ](#5-configure-hubspot-in-ringq)
6. [Making and taking calls](#6-making-and-taking-calls)
7. [Where call details and recordings appear](#7-where-call-details-and-recordings-appear)
8. [Troubleshooting](#8-troubleshooting)
9. [Reference: files, data and security](#9-reference-files-data-and-security)

Step-by-step setup (keys, install, RingQ settings, test call): [`../setup/HUBSPOT_SETUP.md`](../setup/HUBSPOT_SETUP.md). Technical reference: [`TECHNICAL.md`](TECHNICAL.md) (deploy, call flow, limitations), [`API.md`](API.md) (every endpoint).

---

## 1. What the integration does

HubSpot is a CRM with a native **Call** activity. RingQ logs each call as **one HubSpot call** on the caller's contact timeline.

Calls are made and answered in RingQ, as usual. There is no dialer inside HubSpot: HubSpot is where the contact, the call record and the recording are kept.

| When | What happens |
|---|---|
| A call rings or starts | RingQ finds the caller's HubSpot contact by phone (or mobile). The RingQ contact panel shows it. With screen pop on, the contact's HubSpot record opens in a browser tab. The HubSpot call is created right away (status *Ringing*), so it appears on the contact while the call is live. |
| The call is answered | The same HubSpot call becomes *In progress*. |
| The call ends | The same HubSpot call gets the final status (Completed, No answer, Busy, Failed, Missed), talk time, outcome, notes, and the RingQ call reference `RQ…`. |
| About a minute later | The **recording** is linked to the HubSpot call, so it plays from the contact's timeline. |

One RingQ call is always one HubSpot call, also when the call rings on several devices or an event is retried. If HubSpot is slow, offline or switched off, RingQ calls work exactly as before.

---

## 2. Before you start

| You need | Notes |
|---|---|
| A HubSpot account where you are a **Super Admin** (or may create private apps) | To create the access token. |
| **Admin** access to RingQ | To save the HubSpot settings. |
| Root access to the RingQ server | Once, to install the backend (section 4). |
| Call recording on the RingQ extension or queue | Only if you want recordings in HubSpot. |

---

## 3. Sign in to HubSpot and create the access token

RingQ uses a HubSpot **private app access token**. It never needs your HubSpot password.

1. Sign in at **https://app.hubspot.com** with your HubSpot account.
2. Open **Settings** (gear icon, top right).
3. Go to **Integrations → Private Apps**. In newer HubSpot accounts this is under **Development → Legacy apps → Create legacy app → Private**.
4. Click **Create a private app**:
   - **Basic info:** name `RingQ`.
   - **Scopes:** tick
     - `crm.objects.contacts.read` (required)
     - `crm.objects.contacts.write` (required: calls and contacts)
     - `crm.objects.companies.read` (optional: shows the company and links calls to it)
5. Click **Create app**, confirm, then **Show token** and **Copy**. The token starts with `pat-`.

Keep the token secret. Anyone with it can read and change your HubSpot contacts. If it is ever shared, open the private app → **Auth** → **Rotate**, then paste the new token into RingQ (section 5).

---

## 4. Install the RingQ side on the server (RingQ admin)

Done **once per RingQ server**. Full details: [`TECHNICAL.md`](TECHNICAL.md).

1. **Files:** the HubSpot files are part of this backend's `app/` (deployed to `/var/www/ringq/app/`):
   - `app/controllers/HubspotcrmController.php`
   - `app/library/Hubspot/` (5 files)
   - `app/tasks/HubspotcrmTask.php`
2. **Database** (in this order; both are safe to run again):

   ```bash
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < app/scripts/hubspot/2026_10_21_hubspot_crm.sql
   sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < app/scripts/hubspot/2026_10_22_hubspot_cti.sql
   ```

   The second script adds a table and two columns the code expects; run it too.
   If the app does not connect as the table owner:

   ```sql
   GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_hubspot_config, public.q_hubspot_call_journals, public.q_hubspot_cti_tokens TO <app_db_user>;
   ```

3. **Access rules** (`app/config/services.php`): one line in each list, next to `"qontak"`:

   ```php
   // in $publicly:
   "hubspotcrm" => ["recording", "cti"],
   // in $privately:
   "hubspotcrm" => ["config", "testConnection", "disconnect", "contactsSearch", "contact", "contacts", "callEvents", "callJournals", "recordingsSync", "aiAnalysis", "ctiPair", "cti"],
   ```

4. **Background job** (retries and late recordings): `app/tasks/tasks.txt` lists `cli.php hubspotcrm sync`. RingQ's `app/scripts/jobs.sh` turns `tasks.txt` into root's crontab. Check with `crontab -l | grep hubspotcrm`.
5. **nginx (recording links):** HubSpot only plays links ending in `.wav`/`.mp3`, and the API server's static-file rule answers those with 404. In the `listen 8443` server block, **before** that rule:

   ```nginx
   location ^~ /hubspotcrm/recording/ {
       try_files /nonexistent /index.php?_url=$uri&$args;
   }
   ```

   Then `sudo nginx -t && sudo systemctl reload nginx`.
6. **Reload PHP:** `sudo systemctl reload php7.4-fpm`.
7. **Check:** `curl -s -o /dev/null -w '%{http_code}\n' https://<server>:8443/hubspotcrm/config` prints **401** (installed, needs a login).

The access token is stored **encrypted** with a key derived from RingQ's own application secret (`application.cryptSalt`); no key file is needed.

---

## 5. Configure HubSpot in RingQ

In RingQ, as an admin: **Settings → Integrations → CRM → HubSpot → Configure**.

![RingQ Settings → Integrations → CRM: the HubSpot card](../images/ringq-crm-integrations.png)

*Settings → Integrations → CRM. Click **Configure** on the HubSpot card.*

![HubSpot settings in RingQ: status, enable switch, private app access token, granted scopes, call options](images/ringq-hubspot-settings-1.png)

*Top of the HubSpot settings: status (portal, last test, last call logged), the **Enable** switch, the **Private app access token** (masked once stored) and the **scopes** the token has (green = granted). Screenshots use sample data.*

1. Switch **Enable HubSpot integration** on.
2. Paste the token into **Private app access token**.
3. Click **Test connection**. It checks the token, reads your portal, the granted scopes (green chips), and which call statuses and outcomes your portal offers.
4. Click **Save**. The status should be **Connected**.

![HubSpot settings: unanswered calls, company link, contact creation, screen pop, recordings](images/ringq-hubspot-settings-2.png)

*Call options (unanswered calls, company link, contact creation, automatic contacts), screen pop and recordings.*

![HubSpot settings: screen pop, recordings, recording link address, AI call intelligence](images/ringq-hubspot-settings-3.png)

*Recordings with the **Recording link address**, and the optional AI call intelligence.*

After saving, the token field is empty with a "Stored ••••xxxx" hint; leave it empty to keep the token. **Disconnect** deletes the token, stops logging and revokes every recording link already in HubSpot. Calls already in HubSpot are kept.

| Setting | Default | What it does |
|---|---|---|
| Contact panel on incoming / outgoing calls | On | Shows the caller's HubSpot contact in RingQ during calls. |
| Call journaling | On | Logs each call as a HubSpot call activity on the contact's timeline. |
| Also log unanswered calls | On | Missed, rejected and unanswered calls are logged too, so agents can call back. |
| Link calls to the contact's company | On | Also associates the call with the contact's primary company (needs `crm.objects.companies.read`). |
| Contact creation | Off | Lets agents create a HubSpot contact for an unknown number from the RingQ panel. Never creates duplicates (same phone or email). |
| Create contacts for new callers automatically | Off | Every unknown caller (a real phone number) gets a contact, so every call is saved on a contact. |
| Screen pop on incoming / outgoing calls | Off | Opens the caller's HubSpot record in a browser tab. Allow pop-ups for the RingQ site. |
| Attach call recordings | On | Adds a signed RingQ link to the HubSpot call so it plays from the timeline. |
| Recording link address | empty | Public HTTPS address HubSpot uses to fetch recordings. Empty = `https://<tenant>:8443`. Set it only if HubSpot cannot reach port 8443. |
| AI transcription / summary / sentiment | Off | Transcription uses RingQ's own service and adds the text to the HubSpot call. Summary and sentiment stay inactive until an analysis provider exists. |

---

## 6. Making and taking calls

All calls are made and answered in RingQ.

- **Incoming:** the RingQ contact panel shows the HubSpot contact. With screen pop on, the record opens in HubSpot. The HubSpot call appears on the contact while it rings.
- **Outgoing:** dial in RingQ as usual. The panel shows the contact; the call is logged on it.
- **After the call:** the panel shows **Logged** and lets the agent add **notes** and pick the **outcome**. **Save notes** updates the same HubSpot call (never a new one).
- **Unknown number:** "No HubSpot contact…" with **Create** (if Contact creation is on). With *Create contacts for new callers automatically* on, a contact is made for every new caller.
- **Several contacts share a number:** the panel lists them; pick one to log the call on it.
- **Calls between extensions:** saved on the colleague's HubSpot contact (found by their RingQ email).

---

## 7. Where call details and recordings appear

### In HubSpot

Open the contact → **Activity** timeline → the **Call** card (also under **Calls** in the activity filter).

| Field | Content |
|---|---|
| Title | Includes the RingQ call reference `RQxxxxxxxxxx` |
| Direction | Inbound / Outbound |
| Status | Ringing → In progress → Completed, No answer, Busy, Failed, Missed (only values your portal offers) |
| Outcome | Connected, No answer, Busy… (your portal's outcomes) |
| Duration | Talk time |
| From / To number | Caller and recipient |
| Notes (call body) | Agent notes, the RingQ agent and extension, result, start/answer/end times, transcript (if AI transcription is on) |
| Recording | A **play** control on the call card (from the signed RingQ link) |
| Associations | The contact, and its company if enabled |

### In RingQ

![RingQ contact panel for HubSpot after a call: contact, call times, logged on the timeline with recording, call notes](images/ringq-hubspot-call-popup.png)

*The RingQ contact panel after a call: the HubSpot contact (lifecycle, location, HubSpot ID, **Open in HubSpot**), the call, **Logged — saved to HubSpot on the contact timeline with recording**, the notes form, and the call reference and HubSpot call id.*

The contact panel during and after each call (contact details, **Logged** status, notes form), and **Call Logs** as before.

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| **HubSpot rejected the access token (HTTP 401)** | Wrong or rotated token. | Copy the current token (section 3). |
| **missing required scopes: …** | The private app lacks a scope. | Add the scope in the private app, then **Test connection**. |
| **Sorry, this is not available. (Code AC4031)** | The access rules (section 4.3) are not on the server. | Add both `"hubspotcrm"` lines and reload PHP-FPM. |
| **HubSpot database tables are missing** | SQL not run. | Section 4.2. |
| Recording does not play in HubSpot | nginx rule missing, or HubSpot cannot reach port 8443. | Section 4.5; or set **Recording link address** to a public HTTPS origin. Check: `curl -s -o /dev/null -w "%{http_code} %{content_type}\n" https://<host>:8443/hubspotcrm/recording/00000000-0000-0000-0000-000000000000/0/aaaaaaaaaaaaaaaaaaaaaa/call.wav` must print `404 text/plain`. |
| Failed calls never retried, late recordings never added | Background job not scheduled. | Section 4.4 (`crontab -l \| grep hubspotcrm`). |
| Outbound busy shows as "No answer" | The RingQ web dialer does not report the SIP busy code. | Known limitation. |
| Calls to outside numbers fail | No outbound route on the tenant's PBX. | Add an outbound route in RingQ. |

What HubSpot holds for recent calls (read-only): `php /var/www/ringq/app/cli.php hubspotcrm verify 10`. Logs: `grep "\[hubspot\]" /var/log/php7.4-fpm.log` (never contain the token).

---

## 9. Reference: files, data and security

| Path | What |
|---|---|
| `app/controllers/HubspotcrmController.php` | API `hubspotcrm/*` |
| `app/library/Hubspot/HubspotService.php` | Settings, contact lookup, live and final call sync, recordings, AI |
| `app/library/Hubspot/HubspotClient.php` | HubSpot API client |
| `app/library/Hubspot/HubspotCrypto.php` | Token encryption, signed recording links |
| `app/library/Hubspot/HubspotTranscripts.php`, `HubspotException.php` | Transcription, error type |
| `app/tasks/HubspotcrmTask.php` | `cli.php hubspotcrm sync` / `after` / `verify` |
| `app/scripts/hubspot/` | Tables `q_hubspot_config`, `q_hubspot_call_journals`, `q_hubspot_cti_tokens` (+ rollback, full schema) |

The RingQ settings screen and contact panel are part of the RingQ web app (`src/integrations/hubspot/` in the frontend repository).

Security: the token is stored encrypted and never returned to the browser or logged; recording links are signed, tenant-bound and revoked by Disconnect; every query is limited to the logged-in tenant.
