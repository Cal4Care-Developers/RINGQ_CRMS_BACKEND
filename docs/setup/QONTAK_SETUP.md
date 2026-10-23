# Qontak CRM setup: step by step

This guide takes you from nothing to a working Qontak CRM (Mekari) integration: get the keys, install, configure RingQ, test a call. For how agents use it day to day, see the [Qontak user guide](../qontak/USER_GUIDE.md).

| | |
|---|---|
| **Keys you need** | An **HMAC Client ID** and **Client Secret** for Qontak CRM, from the **Mekari Developer Center** |
| **Optional** | The **SSO ID** (a UUID) of the Qontak user that contacts and tasks are created as |
| **Who creates them** | The Qontak **company owner** (only the owner can request API access) |
| **Where they go in RingQ** | Settings → Integrations → CRM → Qontak → **Client ID**, **Client Secret**, **Acting CRM user SSO ID** |
| **Not needed** | Your Qontak dashboard login (it does **not** work for the API) |
| **Server extra** | An encryption key file `/etc/ringq/qontak.key` (Part B) |

---

## Part A. Get the Qontak API credentials

### Step A1. Sign in to the Mekari Developer Center

1. Open **https://developers.mekari.com**.
2. Sign in with the **Mekari account of the Qontak company owner**.

### Step A2. Request Qontak CRM API access

1. In the Developer Center, request access to the **Qontak CRM** API for your company.
2. Wait until Mekari enables it. Without this, every request is refused with *lack permission (HTTP 403)*.

### Step A3. Create an HMAC application

1. Create a new **application** for **Qontak CRM**.
2. Choose **HMAC** as the authentication type.
3. Save it.

Mekari's reference for HMAC credentials: https://developers.mekari.com/docs/kb/hmac-authentication

### Step A4. Copy the credentials

| Credential | Example shape | Goes into RingQ field |
|---|---|---|
| **Client ID** | a short ID string | **Client ID** |
| **Client Secret** | a long secret string | **Client Secret** |
| **SSO ID** (optional) | `50c3d284-7b8b-460d-88bf-9cdde2bdbc26` | **Acting CRM user SSO ID** |

Choose the **environment** at the same time:
- **Production** (`api.mekari.com`): your live Qontak data.
- **Sandbox** (`api.mekari.io`): Mekari's test environment, only if your credentials are sandbox credentials.

Keep the Client Secret private. If it is ever shared, create a new secret in the Developer Center and enter it in RingQ.

---

## Part B. Install the RingQ side on the server (once per server)

Done by the RingQ server admin, as root. The Qontak files are already part of this backend.

| Step | Command / action |
|---|---|
| B1. Deploy this backend's `app/` | To `/var/www/ringq/app/`. It includes `QontakController.php`, `app/library/Qontak/`, `QontakTask.php`, the access rules in `app/config/services.php` and the cron lines (`qontak sync`, `qontak prune`) in `app/tasks/tasks.txt`. |
| B2. Create the encryption key | See below. **Back up this file.** If it is lost, the Client Secret must be entered again. |
| B3. Create the tables | `sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < app/scripts/qontak/2026_10_14_qontak_crm.sql` |
| B4. Reload PHP | `sudo systemctl reload php7.4-fpm` |
| B5. Check | `curl -s -o /dev/null -w '%{http_code}\n' https://<server>:8443/qontak/config` prints **401** |

```bash
sudo mkdir -p /etc/ringq
sudo sh -c 'head -c 32 /dev/urandom | base64 > /etc/ringq/qontak.key'
sudo chown root:www-data /etc/ringq/qontak.key
sudo chmod 0640 /etc/ringq/qontak.key
```

The key file stays on the server only; it is never in Git.

---

## Part C. Configure Qontak in RingQ

### Step C1. Open the Qontak card

Sign in to RingQ as an **admin** → **Settings → Integrations → CRM** → on the **Qontak** card click **Configure**.

![RingQ Settings → Integrations → CRM, with the Qontak card](../images/ringq-crm-integrations.png)

### Step C2. Enter the credentials and test

![Qontak settings: Enable switch, Environment, Client ID, Client Secret, Acting CRM user SSO ID](../qontak/images/ringq-qontak-settings-1.png)

1. Switch **Enable Qontak integration** on.
2. **Environment:** Production (`api.mekari.com`) or Sandbox (`api.mekari.io`), matching your credentials.
3. **Client ID** and **Client Secret**: paste from Step A4.
4. **Acting CRM user SSO ID**: optional; leave empty unless you have it.
5. Click **Test connection**. A green result means Qontak accepted the credentials.
6. Click **Save configuration**. **Status** changes to **Connected**.

After saving, the secret field shows `•••• (stored, leave blank to keep)`. Leave it blank when you change other settings.

### Step C3. Choose the call options

![Qontak settings: contact creation, task category, time zone, CRM screen pop](../qontak/images/ringq-qontak-settings-2.png)

![Qontak settings: screen pop target, AI call intelligence](../qontak/images/ringq-qontak-settings-3.png)

Recommended starting values:

| Setting | Recommended | Notes |
|---|---|---|
| Contact popup on incoming / outgoing calls | On | Shows the Qontak contact in RingQ during calls |
| Call journaling | On | Every call becomes a Qontak task |
| Contact creation | On | Agents can create a contact for an unknown number |
| Task category | `Call` | Created in Qontak automatically if missing |
| Time zone | Your zone (e.g. `Asia/Jakarta`) | Times written into tasks |
| Screen pop on incoming / outgoing calls | Your choice | Allow pop-ups for the RingQ site |
| Screen pop opens | This call's Qontak task | Or the customer page, or a RingQ window |
| AI transcription | Off | Optional |

Click **Save configuration**.

---

## Part D. Test with a real call

1. Make sure a Qontak contact has your test phone number (`0812…`, `62812…` and `+62812…` all match).
2. Call the RingQ extension from that phone (or dial the number from RingQ).
3. While it rings, the RingQ popup shows the Qontak contact (name, phone, email, company, owner).
4. Answer, talk a few seconds, hang up.
5. The popup shows **Qontak log: Synced — Call saved to Qontak with recording link**.

![RingQ call popup after a call, saved to Qontak with recording link](../qontak/images/ringq-qontak-call-popup.png)

6. In Qontak, open **CRM → Tasks**, filter by category **Call**. The task name looks like `Inbound call - Answered - <contact> (<number>) - <agent> - RQ…`, status **Completed**, linked to the contact.
7. The task detail holds the call times, duration and a **Recording** link. Copy the link address into the browser to play the call.

If something is missing, see [Troubleshooting](../qontak/USER_GUIDE.md#8-troubleshooting).

---

## Part E. Change or remove the credentials

| Task | How |
|---|---|
| **New secret** (shared or leaked) | Create a new Client Secret in the Mekari Developer Center. Paste it in RingQ (Step C2) and save. |
| **Disconnect** | RingQ Qontak settings → **Disconnect**. Deletes the stored secret, stops logging and revokes recording links. Tasks already in Qontak stay. |
| **Remove completely** | Disconnect in RingQ, then delete the application in the Mekari Developer Center. |

---

## Checklist

- [ ] Qontak CRM API access enabled for the company (company owner)
- [ ] HMAC application created; Client ID and Client Secret copied
- [ ] Server: `/etc/ringq/qontak.key` created and backed up, tables created, PHP reloaded, `/qontak/config` answers 401
- [ ] RingQ: environment chosen, credentials entered, **Test connection** green, **Save configuration**, status **Connected**
- [ ] Test call appears as a Qontak task with a recording link
