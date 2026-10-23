# Freshdesk setup: step by step

This guide takes you from nothing to a working Freshdesk integration: get the address and key, install, configure RingQ, test a call. For how agents use it day to day, see the [Freshdesk user guide](../freshdesk/USER_GUIDE.md).

| | |
|---|---|
| **What you need** | The help desk **address** (`yourcompany.freshdesk.com`) and an agent's **API key** |
| **Who gets the key** | The Freshdesk **agent** RingQ will create tickets as (each agent has their own key) |
| **Where they go in RingQ** | Settings → Integrations → CRM → Freshdesk → **Freshdesk address** and **API key** |
| **Not needed** | Your Freshdesk password, a Freshworks developer account, any app from the Freshworks marketplace |
| **Time** | About 10 minutes (plus the one-time server install) |

---

## Part A. Get the Freshdesk address and API key

### Step A1. Choose the agent

Tickets are created **as the agent whose key you use**. Pick an agent who can view and create contacts and tickets for all customers (an admin, or a dedicated agent such as "RingQ").

### Step A2. Sign in and copy the help desk address

1. Sign in to Freshdesk as that agent.
   - Newer Freshworks accounts sign in at `yourcompany.myfreshworks.com`. That is **only the sign-in page**; the Freshdesk API does not work on it.
2. Open the help desk itself (**Tickets** or **Contacts**).
3. Copy the host name from the browser's address bar:

   ```
   https://yourcompany.freshdesk.com/a/tickets
           └──── this part ────────┘
   ```

A custom help desk domain (e.g. `support.yourcompany.com`) does not work with the Freshdesk API; use the `*.freshdesk.com` address.

### Step A3. Copy the API key

1. Click your **profile picture** (top right) → **Profile settings**.
2. On the right, click **View API key**. Complete the check if Freshdesk asks for one.
3. Copy the key.
   - Classic keys are a short string of letters and digits.
   - Newer Freshworks accounts show a key starting with `fwapi_`. Both work.

Keep the key secret: anyone with it can act as that agent. If it is ever shared (chat, email, screenshot), reset it on the same page and enter the new key in RingQ.

### Step A4. Check ticket fields (one time)

RingQ cannot fill custom ticket fields. In Freshdesk **Admin → Ticket fields**, make sure no custom field is **required for agents when submitting**. Otherwise Freshdesk refuses the call tickets. (RingQ shows a warning in its settings if it finds one.)

---

## Part B. Install the RingQ side on the server (once per server)

Done by the RingQ server admin, as root, from a checkout or copy of this repository:

```bash
sudo bash server-configs/freshdesk/install.sh
```

Other app folder, database or database user:

```bash
sudo APP=/var/www/ringq DB=ringq DB_USER=<app_db_user> bash server-configs/freshdesk/install.sh
```

The installer (safe to run again):

1. copies the Freshdesk controller, library and task into `/var/www/ringq/app/` and checks their syntax;
2. creates the three `q_freshdesk_*` tables (`app/scripts/freshdesk/2026_10_23_freshdesk_crm.sql`);
3. schedules `cli.php freshdeskcrm sync` (already listed in `app/tasks/tasks.txt`);
4. reloads PHP-FPM;
5. checks that `https://<server>:8443/freshdeskcrm/config` answers **401** (installed).

No change to `app/config/services.php` is needed: the Freshdesk controller registers its own access rules. The server must reach `https://<account>.freshdesk.com` on port 443.

---

## Part C. Configure Freshdesk in RingQ

### Step C1. Open the Freshdesk card

Sign in to RingQ as an **admin** → **Settings → Integrations → CRM** → on the **Freshdesk** card click **Configure**.

![RingQ Settings → Integrations → CRM, with the Freshdesk card](../images/ringq-crm-integrations.png)

### Step C2. Enter the address and key

![Freshdesk settings: Enable switch, Freshdesk address, API key](../freshdesk/images/ringq-freshdesk-settings-1.png)

1. Switch **Enable Freshdesk integration** on.
2. **Freshdesk address**: paste the address from Step A2 (`yourcompany.freshdesk.com`; a full URL is fine, RingQ keeps the host).
3. **API key**: paste the key from Step A3.
4. Click **Test connection**. A green result shows the account and the agent.
5. Click **Save configuration** (**Update configuration** once saved). **Status** changes to **Connected**, with:
   - **Account**: your Freshdesk account name;
   - **Tickets created as**: the agent from Step A1.

After saving, the key field shows `••••xxxx (stored, leave blank to keep)`. Leave it blank when you change other settings.

### Step C3. Set country code, time zone and call options

![Freshdesk settings: call logging, ticket status and priority, caller not in Freshdesk, recordings](../freshdesk/images/ringq-freshdesk-settings-2.png)

![Freshdesk settings: recordings and CRM screen pop](../freshdesk/images/ringq-freshdesk-settings-3.png)

Recommended starting values:

| Setting | Recommended | Notes |
|---|---|---|
| Contact popup on incoming / outgoing calls | On | Shows the Freshdesk contact in RingQ during calls |
| Contact creation | On | Agents can create a contact for an unknown number |
| **Country calling code** | **Set it** (e.g. `91`, `65`) | Lets `9876543210` match `+919876543210` in Freshdesk |
| **Time zone** | Your zone (e.g. `Asia/Kolkata`) | Not `UTC`, unless you want UTC times in tickets |
| Call journaling | On | One Phone ticket per call |
| Log unanswered calls | On | Missed calls get an Open ticket for call-back |
| Log calls between extensions | On | Internal calls go on the colleague's contact |
| Ticket status: answered / unanswered | Resolved / Open | |
| Ticket priority | Low | |
| Caller not in Freshdesk | Don't log in Freshdesk | Or "Create a contact, then log the call" |
| Attach the recording file | On | The recording is attached to the ticket as a private note |
| Also add a RingQ playback link | Off | Optional signed link |
| Screen pop on incoming / outgoing calls | Your choice | Allow pop-ups for the RingQ site |

Click **Save configuration**.

---

## Part D. Test with a real call

1. Make sure a Freshdesk contact has your test phone number in **Work phone** or **Mobile phone**.
2. Call the RingQ extension from that phone (or dial the number from RingQ).
3. While it rings, the RingQ popup shows the Freshdesk contact (name, phone, email, company).
4. Answer, talk a few seconds, hang up.
5. The popup shows **Freshdesk ticket: Saved as ticket #…**, then **Recording attached**. Pick an outcome, type a note, click **Save notes**.

![RingQ call popup after a call, saved as a Freshdesk ticket with recording](../freshdesk/images/ringq-freshdesk-call-popup.png)

6. Click **Open ticket**, or in Freshdesk open the contact → its tickets. The ticket has source **Phone**, a subject such as `Inbound call from <contact> (<number>) - Answered 0:42 - RQ…`, and tags `RingQ`, `ringq-call`.
7. The ticket's **private notes** hold the recording (`call-recording.mp3`) and the agent notes.

If something is missing, see [Troubleshooting](../freshdesk/USER_GUIDE.md#9-troubleshooting).

---

## Part E. Change or remove the key

| Task | How |
|---|---|
| **Reset the key** (shared or leaked) | Freshdesk → profile picture → **Profile settings** → reset the API key. Paste the new key in RingQ (Step C2) and save. |
| **Change the agent** | Get the other agent's key (Step A3) and paste it in RingQ. New tickets are created as that agent. |
| **Disconnect** | RingQ Freshdesk settings → **Disconnect**. Deletes the stored key, stops logging and revokes playback links. Tickets already in Freshdesk stay. |

---

## Checklist

- [ ] Agent chosen; help desk address (`*.freshdesk.com`) and API key copied
- [ ] No custom ticket field required for agents
- [ ] Server: `install.sh` finished with `freshdeskcrm/config -> HTTP 401`
- [ ] RingQ: address and key entered, **Test connection** green, saved, status **Connected**, country code and time zone set
- [ ] Test call appears as a Phone ticket with the recording attached
