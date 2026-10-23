# RingQ dialer inside HubSpot (CTI): setup

With this, agents call from HubSpot and take calls in HubSpot:

- **Outgoing:** open a contact in HubSpot, click **Call** and choose **RingQ**. The call goes out from the agent's own RingQ extension.
- **Incoming:** when a call comes to the agent's RingQ extension, HubSpot opens its calling window with the caller's HubSpot contact, and the agent clicks **Answer** there.

Every call is logged once on the HubSpot contact, with the RingQ recording.

This is in addition to the RingQ-side integration (`docs/hubspot/README.md`), which must already be working: HubSpot settings saved and connected.

## How it works

1. HubSpot opens the RingQ dialer, `https://sgringq100.ringq.ai/click2call/hubspot/`, in its calling window.
2. **Connect, once per browser.** Inside HubSpot the browser does not send the RingQ login cookie, because it is `SameSite=Lax`. So the agent clicks **Connect RingQ**, and a RingQ pop-up (where they are logged in) gives the dialer its own token. The token is stored hashed, expires after 30 days, and is revoked by **Disconnect** in the dialer, or for everyone by **Disconnect** in RingQ's HubSpot settings.
3. **Calling.** HubSpot sends the number. The dialer calls over SIP (`wss://<tenant>:7443`) with the agent's extension. It tells HubSpot (ringing, answered, ended), and HubSpot creates the call record on the contact and shows its notes/outcome form. It also tells RingQ (`hubspotcrm/cti`), which adds the recording link and measured status to **that same** HubSpot call. It never creates a second one, and never overwrites the notes or outcome the agent typed.
4. **Incoming calls.** With **Receive calls in HubSpot** on (the default), the dialer registers as one more device on the agent's extension and tells HubSpot the agent is available.
   - When a call comes in, it rings **both** in HubSpot and in the RingQ app.
   - HubSpot opens its calling window and shows its own caller-ID match (the contact).
   - The agent answers **in either place**. The PBX stops the other one ringing.
   - RingQ logs the call **once**: the answered copy wins. A missed call is logged once too.
   - After the call, HubSpot's notes form opens on the call record RingQ created.
   - With the switch off, incoming calls ring only in the RingQ app, as before.

## 1. Backend (server)

Already included in this backend (`feature/hubspot-crm` branch): the `ctiPair` / `cti` actions in `HubspotcrmController.php`, the dialer logic in `HubspotService.php`, and both ACL entries in `app/config/services.php`. On the server you only need to:

1. Deploy this backend as usual.
2. Run `app/scripts/hubspot/2026_10_22_hubspot_cti.sql` once, after `2026_10_21_hubspot_crm.sql` (see `docs/hubspot/README.md`).
3. Reload PHP-FPM.

## 2. Dialer page (server)

Build it on your PC:

```bash
npm run build:hubspotcti
```

This creates `dist-hubspotcti/`. Upload **its contents** to a new `hubspot/` folder inside the folder that serves `https://sgringq100.ringq.ai/click2call/`. That is the Flutter Salesforce dialer's folder; nothing in it is changed, only the `hubspot/` sub-folder is added. For example:

```bash
# find the click2call folder:
grep -rn "click2call" /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null
# then, e.g. if it is /var/www/webapp/click2call:
sudo mkdir -p /var/www/webapp/click2call/hubspot
# copy dist-hubspotcti/* into it (index.html, connect.html, assets/, sounds/, images/ ...)
```

Check that HubSpot is allowed to frame it. There must be **no** `X-Frame-Options` line, the same as `/click2call/` today:

```bash
curl -sI https://sgringq100.ringq.ai/click2call/hubspot/ | grep -i -E "x-frame-options|content-security-policy|permissions-policy"
```

`permissions-policy: microphone=(self)` is fine, because it allows the dialer's own page to use the microphone.

## 3. HubSpot app (one time, by whoever owns the HubSpot account)

HubSpot only offers an external dialer through a **HubSpot app** that declares "calling settings". This is a different thing from the private-app token used for the CRM sync.

1. Create a free **developer account** at https://developers.hubspot.com.
2. In it, create a **public app** (in newer accounts: **Create legacy app → Public**). Name it `RingQ`.
   - **Auth:** add any redirect URL you own, for example `https://sgringq100.ringq.ai/click2call/hubspot/`.
   - **Scopes:** add `crm.objects.contacts.read`. HubSpot needs at least one scope to install an app; the dialer itself uses none.
3. Register the dialer URL as the app's calling settings. You need the **App ID** (shown on the app) and the developer account's **developer API key** (Developer account → Keys):

   ```bash
   curl -X POST "https://api.hubapi.com/crm/v3/extensions/calling/<APP_ID>/settings?hapikey=<DEVELOPER_API_KEY>" \
     -H "Content-Type: application/json" \
     -d '{"name":"RingQ","url":"https://sgringq100.ringq.ai/click2call/hubspot/","width":400,"height":600,"isReady":true,"supportsCustomObjects":false,"supportsInboundCalling":true}'
   ```

   HubSpot changes how app settings are managed from time to time. If this request is rejected, or your developer account shows a **Calling** section in the app's settings instead, enter the same values there: name `RingQ`, the URL above, width 400, height 600, and turn on **inbound calling**.
4. Install the app into **your** HubSpot account: open the app's **Install URL** (Auth tab), choose your HubSpot account, and click **Connect app**.

## 4. Agents: first call

1. In HubSpot, open a contact and click **Call**.
2. In the call window, open the calling-provider menu and choose **RingQ**. HubSpot remembers the choice.
3. Click **Connect RingQ**. A RingQ window opens; if it asks, sign in to RingQ and click **Connect**. It closes by itself.
4. Allow the microphone when the browser asks.
5. Leave **Receive calls in HubSpot** on to take incoming calls in HubSpot. Keep a HubSpot tab open, and keep yourself **available** for calling in HubSpot's calling settings.
6. Click **Call**. When the call ends, HubSpot shows its notes/outcome form. The summary in the RingQ window shows "Saved in HubSpot", and "with recording" once the recording is attached (if recording is on for the extension).

## 5. Test checklist

- [ ] `curl -I …/click2call/hubspot/` shows no `X-Frame-Options`.
- [ ] HubSpot → contact → Call → provider list shows **RingQ**.
- [ ] Connect RingQ works. Clicking **Disconnect** in the dialer, then reloading, asks to connect again.
- [ ] An answered call is logged **once** on the contact in HubSpot, with duration and the notes you typed in HubSpot's form.
- [ ] About a minute later, the same HubSpot call has a recording. Your notes and outcome are not overwritten.
- [ ] A busy number is logged as BUSY. An unanswered call, or one hung up while ringing, is logged as NO_ANSWER / CANCELED.
- [ ] In RingQ's HubSpot settings, **Disconnect** makes every HubSpot dialer ask to connect again.
- [ ] **Incoming, answered in HubSpot:** HubSpot's window opens with the contact and you click Answer. The call is logged **once** on the contact, with the recording.
- [ ] **Incoming, answered in the RingQ app:** HubSpot's window closes by itself and the call is logged **once**.
- [ ] **Incoming, not answered:** logged **once** as missed (if "log unanswered calls" is on).
- [ ] **Receive calls in HubSpot** off: incoming calls ring only in the RingQ app.
- [ ] The Salesforce dialer at `/click2call/` still works.

## Limitations

| Item | Detail |
|---|---|
| Incoming calls in HubSpot | Need a HubSpot tab open, the agent set to **available** for calling in HubSpot, and the app registered with inbound calling (step 3). If HubSpot is closed, calls still ring and log in the RingQ app. Inbound calling is a newer HubSpot feature; if HubSpot does not open its window for incoming calls in your account, the call still rings in the RingQ app and is logged as usual. |
| Both devices ring | With the switch on, the agent's RingQ app also rings for the same call. This is intentional (it's a fallback when HubSpot is closed). Answer in either place. |
| AI transcript for HubSpot-window calls | Kept in RingQ but not written into the HubSpot call, so it can't overwrite the notes the agent typed in HubSpot's form. |
| Transfer, hold, conference | Not in the HubSpot window (mute, keypad/DTMF and hang-up are). Use the RingQ app for these. |
| HubSpot app registration | Needs a HubSpot developer account (free). The calling-settings request in step 3 follows HubSpot's documented API, but HubSpot's docs could not be fetched from the build machine; if it's rejected, use the app's Calling settings page. |
| Old browsers / strict privacy settings | The dialer keeps its token in the HubSpot window's own storage. If the browser blocks storage in frames, the agent has to connect again on each HubSpot reload. |
