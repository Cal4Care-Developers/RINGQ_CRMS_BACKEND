# HubSpot integration API (`hubspotcrm/*`)

Base: `https://<tenant>:8443/hubspotcrm/...` (the same host the app already uses).

**Authentication:** the normal RingQ login session cookie (`credentials: 'include'`). Every endpoint except `recording` returns `401 {"result":"error","message":"Unauthorized. Please log in."}` without a session.

**Tenant:** always the session's `domain_uuid`. It is never taken from the request.

**Admin:** non-plain users (`ControllerBase::isPlainUser() === false`). Plain users get `403` (code AC4031) on admin actions.

**Request body:** JSON. The web app sends it with `Content-Type: application/x-www-form-urlencoded` to avoid a CORS preflight; the controller reads the raw body as JSON, and plain form fields are accepted too. Max 64 KB.

**Response envelope:** `{"result":"success"|"error","message"?:string,"content"?:object}`

**Error status codes:**

| Status | Meaning |
|---|---|
| 401 | Not logged in |
| 403 | Not allowed (plain user, contact creation off, call belongs to another user) |
| 404 | Not found |
| 405 | Wrong method |
| 409 | Not configured / not enabled / conflict |
| 422 | Validation |
| 429 | Test throttle (10 per minute) |
| 500 | Setup problem; the message names the missing step |
| 502 | HubSpot rejected the token or is missing scopes |
| 503 | HubSpot is unreachable or rate-limited |

Messages never contain the token, headers or raw HubSpot bodies.

---

## Settings

### GET `hubspotcrm/config`
Any user. Returns settings and never the token. `token_hint`, scopes and `last_error` are only filled for admins.
```json
{"result":"success","content":{
  "enabled":true,"token_set":true,"token_hint":"••••a1b2","portal_id":"123456","ui_domain":"app.hubspot.com","account_timezone":"Asia/Singapore",
  "granted_scopes":["crm.objects.contacts.read","crm.objects.contacts.write"],"missing_scopes":[],"companies_scope":false,
  "connection_status":"connected","last_tested_at":"2026-10-21T03:00:00Z","last_sync_at":null,"last_error":null,"can_manage":true,
  "ai_analysis_available":false,"ai_transcription_available":true,"default_recording_base_url":"https://sgringq100.ringq.ai:8443",
  "popup_incoming":true,"popup_outgoing":true,"call_journaling":true,"log_unanswered":true,"contact_creation":false,
  "associate_company":true,"include_recording":true,"recording_base_url":"","screenpop_incoming":false,"screenpop_outgoing":false,
  "ai_transcription":false,"ai_summary":false,"ai_sentiment":false}}
```

### POST `hubspotcrm/config` (admin)
Saves the settings. `access_token: ""` keeps the stored token.

Validation:
- The token must be 20–512 characters with no whitespace.
- `recording_base_url` must be `https://host[:port]` with no path.
- Booleans accept true/false/1/0.

A new or unverified token is tested right away.
```json
{"enabled":true,"access_token":"pat-na1-…","popup_incoming":true,"popup_outgoing":true,"call_journaling":true,"log_unanswered":true,
 "contact_creation":false,"associate_company":true,"include_recording":true,"recording_base_url":"",
 "screenpop_incoming":false,"screenpop_outgoing":false,"ai_transcription":false,"ai_summary":false,"ai_sentiment":false}
```
Response: `{"result":"success","message":"HubSpot settings saved. Connection verified.","content":{…config…}}`. On a failed test, the message ends with `… But the connection test failed: <reason>`.

### POST `hubspotcrm/testConnection` (admin, max 10 per minute per tenant)
Body: `{"access_token":""}` to test the stored token, or a pasted one, which is **not** saved. The test calls these HubSpot endpoints:

| Call | Purpose |
|---|---|
| `GET /crm/v3/objects/contacts?limit=1` | Authentication and contacts scope |
| `GET /account-info/v3/details` | Portal id and UI domain |
| `POST /oauth/v2/private-apps/get/access-token-info` | Granted scopes (best effort) |
| `GET /crm/v3/properties/calls/hs_call_status` | Allowed call statuses |
| `GET /calling/v1/dispositions` | Call outcomes |

```json
{"result":"error","message":"Connected to HubSpot, but the private app is missing required scopes: crm.objects.contacts.write.",
 "content":{"config":{…},"details":{"portal_id":"123456","granted_scopes":["crm.objects.contacts.read"],"missing_scopes":["crm.objects.contacts.write"]},"reason":"missing_scopes"}}
```
`reason` is one of: `auth_failed`, `missing_scopes`, `forbidden`, `rate_limited`, `timeout`, `unavailable`.

### POST `hubspotcrm/disconnect` (admin)
Deletes the token, disables the integration, and rotates `link_salt`, which revokes every recording link already sent to HubSpot. Queued syncs are skipped; call history is kept.

`409` if HubSpot was never configured.

---

## Contacts (any logged-in user; integration must be enabled, else 409)

### GET `hubspotcrm/contactsSearch?phone=<number>`
Dialer lookup. Needs 6 or more digits; shorter numbers (extensions) return `422`.

It runs `POST /crm/v3/objects/contacts/search` with these OR'ed filter groups:
- `hs_searchable_calculated_phone_number CONTAINS_TOKEN *<last 9 digits>`
- the same for `hs_searchable_calculated_mobile_number`
- `phone EQ <raw>`
- `mobilephone EQ <raw>`

Results are then filtered to real matches (same trailing digits) and cached for 5 minutes.

### GET `hubspotcrm/contactsSearch?q=<text>`
Free-text search (name, email, company) using HubSpot `query`, 2–100 characters. Phone-like input uses the phone search.
```json
{"result":"success","content":{"contacts":[{"id":"501","firstname":"Jane","lastname":"Tan","full_name":"Jane Tan","email":"jane@example.com",
 "phone":"+65 9123 4567","mobilephone":"","company":"Acme","company_id":"9001","company_name":null,"jobtitle":"Buyer",
 "lifecyclestage":"customer","lead_status":"","owner_id":"","city":"","country":"","created_at":"…","updated_at":"…",
 "url":"https://app.hubspot.com/contacts/123456/record/0-1/501"}]}}
```

### GET `hubspotcrm/contact?id=<hubspot contact id>[&fresh=1]`
One contact, including its primary company's name (needs `crm.objects.companies.read`; without it only `company_id` is returned).
- `422` if the id isn't numeric.
- `404` if the contact doesn't exist (deleted or merged).

### POST `hubspotcrm/contacts`
Only when "Contact creation" is on (otherwise `403`).

Body: `{"firstname":"Jane","lastname":"Tan","email":"jane@example.com","phone":"+6591234567","company":"Acme"}`

It never creates a duplicate: if the phone or email already exists, it returns `{"created":false,"contact":{…}}`. A 30-second Redis lock blocks double submits.

---

## Calls

### POST `hubspotcrm/callEvents`
Sent by the dialer bridge on each transition. It is idempotent per `(tenant, local_call_id)`; once a call reaches a final status, later or out-of-order events are ignored.
```json
{"local_call_id":"7f0c…uuid","event":"started|answered|ended","direction":"inbound|outbound","remote_number":"+6591234567",
 "status":"ringing|answered|completed|missed|rejected|busy|no_answer|failed","origin":"dialer|click_to_call",
 "started_at":"ISO","answered_at":"ISO","ended_at":"ISO","talk_duration":65,"hubspot_contact_id":"501"}
```
- Browser timestamps are accepted only within ±1 day of server time.
- An unanswered call is never stored as `completed`.
- Response: `{"result":"success","content":{"journal":{…}}}`.

On `ended`, the browser gets its answer first. Only then does the server create the HubSpot call:

1. `POST /crm/v3/objects/calls` with properties:

   | Property | Value |
   |---|---|
   | `hs_timestamp` | Call start |
   | `hs_call_title` | Includes the call reference `RQxxxxxxxxxx` |
   | `hs_call_body` | HTML: notes, agent, result, times and transcript |
   | `hs_call_direction` | `INBOUND` / `OUTBOUND` |
   | `hs_call_duration` | Talk time in ms, 0 if unanswered |
   | `hs_call_from_number` / `hs_call_to_number` | Caller and recipient numbers |
   | `hs_call_status` / `hs_call_disposition` | Only values verified for the portal |
   | `hs_call_recording_url` | When the recording is linked |

   It also creates associations: contact (type 194) and the contact's company (type 182, optional).
2. `PATCH /crm/v3/objects/calls/{id}` for later updates (recording, notes, transcript).
3. `PUT /crm/v4/objects/calls/{id}/associations/default/contacts/{contactId}` when the contact is chosen after the call was created.

**One HubSpot call per RingQ call:**
- a row lock;
- `create_attempted_at` is stored **before** the create request;
- `hubspot_call_id` is stored immediately after it;
- after a crash between the two, the call is recovered by `POST /crm/v3/objects/calls/search` on the call reference instead of being created again;
- a unique DB index on `(domain_uuid, hubspot_call_id)`.

### GET `hubspotcrm/callJournals?local_call_id=<id>`
One call log (plain users only see their own). Lists use `?page=1&per_page=25[&phone=][&hubspot_contact_id=]`.
```json
{"result":"success","content":{"journal":{"local_call_id":"…","call_ref":"RQ7f0c1a2b3c","hubspot_contact_id":"501","hubspot_call_id":"40411",
 "hubspot_contact_url":"https://app.hubspot.com/contacts/123456/record/0-1/501","call_origin":"click_to_call","caller_number":"1004",
 "destination_number":"+6591234567","call_direction":"outbound","call_status":"completed","agent_extension":"1004",
 "started_at":"…","answered_at":"…","ended_at":"…","duration":80,"talk_duration":65,"notes":null,"recording_status":"linked",
 "recording_available":true,"call_uuid":"…","transcription":null,"summary":null,"sentiment":null,"ai_status":"disabled",
 "ai_error":null,"ai_note":null,"sync_status":"synced","retry_count":0,"last_error":null}}}
```
- `sync_status`: `pending` (being saved or retrying), `synced`, `failed` (needs Retry), or `skipped` (journaling off, or an unanswered call while "log unanswered" is off).

### POST `hubspotcrm/callJournals`
| `action` | Body | Effect |
|---|---|---|
| `retry` | `{local_call_id}` | Clears the backoff and syncs now (only for ended calls) |
| `notes` | `{local_call_id, notes}` | Saves agent notes (max 5000 characters, HTML stripped) and updates the HubSpot call body |
| `contact` | `{local_call_id, hubspot_contact_id}` | Links or re-links the call to a contact. `404` if the contact doesn't exist |
| `transcript` | `{local_call_id, error?}` | The popup ran RingQ's `/reports/audiototext/{call_uuid}`; the transcript is added to the HubSpot call |

### POST `hubspotcrm/recordingsSync` (admin) · POST `hubspotcrm/aiAnalysis` (admin)
Body `{local_call_id}`. Re-links the recording, or re-runs AI (`409` if the AI switches are off).

### GET `hubspotcrm/recording/{journal_uuid}/{exp}/{sig}/call.(wav|mp3)` (public)
The signed link HubSpot downloads. It is valid only when:
- the HMAC signature matches (key derived from RingQ's `application.cryptSalt`, bound to the tenant's `link_salt`);
- the integration still has a token;
- the file resolves under `/var/lib/freeswitch/recordings/`.

Otherwise it returns `404`. Response headers are `no-store`, `noindex` and `no-referrer`.

---

## End-to-end test plan (run against a real HubSpot portal)

Before deploying, these were run on the build machine:
- `php -l` on all PHP files;
- PHP unit checks: phone matching, validation, encrypt/decrypt and tamper detection, link signatures, call property mapping (only verified status/outcome values are sent);
- the frontend suite: 128 files, 1273 tests, including the new HubSpot tests;
- `tsc --noEmit`: 0 errors;
- a production build;
- a visual check of the UI with stubbed data.

None of this proves the live HubSpot operations. Do these after deploying:

1. **Settings:**
   - Save with a wrong token: the status shows "Connection error" with "HubSpot rejected the access token (HTTP 401)".
   - Save the real token: "Connection verified", portal id shown, scope chips green.
   - Reload: the token field is empty with a "Stored ••••xxxx" placeholder. The network tab shows no token in any response.
2. **Scopes:** create a token without `crm.objects.contacts.write` and Test. Expect "missing required scopes: crm.objects.contacts.write".
3. **Lookup:**
   - Call in from a number stored on a HubSpot contact; the popup shows the contact (name, phone, email, company, HubSpot ID, lifecycle).
   - Unknown number: "No HubSpot contact…" plus Create.
   - Two contacts sharing a number: a picker.
4. **Calling a HubSpot contact from RingQ:** in the RingQ app, open the HubSpot contacts panel, search for the contact and click **Call**. RingQ dials the number and the popup says "from HubSpot".
   - Double-click: only one call.
   - Contact without a phone: a message, no call.
   - Phone unregistered: a message, no call.
5. **Journal:** answer, talk, hang up. The popup shows "Logged".
   - In HubSpot, the contact timeline has one call with direction, duration, result and the `RQ…` reference.
   - Add notes, then Save notes: the HubSpot call body updates (the same call, not a new one).
6. **Duplicates:** in devtools, replay the `callEvents` `ended` request. Still one call in HubSpot, and the journal shows the same `hubspot_call_id`.
7. **Recording:** with recording enabled, within about a minute `recording_available` becomes true and the HubSpot call has a recording. If it doesn't play, see the Limitations section of TECHNICAL.md (public HTTPS reachability).
8. **Missed call:** ring and don't answer. It is logged as NO_ANSWER or MISSED (whichever the portal offers), or skipped if "log unanswered" is off.
9. **Invalid ids:** `GET hubspotcrm/contact?id=999999999999` returns 404. `?id=abc` returns 422. `POST callJournals {action:"contact", hubspot_contact_id:"999999999999"}` returns 404.
10. **Tenant isolation and authorization:**
    - As a plain user, `POST hubspotcrm/config` returns 403.
    - Requesting another user's `local_call_id` returns 404.
    - Without a cookie: 401 or a redirect.
11. **Disconnect:** the card shows "Disconnected", journaling stops, and a recording link already in HubSpot returns 404.
12. **Regression:** Qontak settings, popup and journaling work as before. The other CRM cards (Cliniko, AutoTask, Salesforce, Zendesk, Zoho, Custom CRM) open and save as before. A normal call with HubSpot disabled behaves exactly as before.
