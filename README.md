# RINGQ_CRMS: CRM integrations backend

Backend code, database scripts and documentation for RingQ's three CRM integrations. Each one is a self-contained module for the RingQ backend (PHP 7.4, Phalcon 4, PostgreSQL `ringq`), with its own controller, library, tables, background task and guide.

| CRM | Folder | How a call is saved | Recording | Calling inside the CRM | Guide |
|---|---|---|---|---|---|
| **HubSpot** | [`hubspot/`](hubspot/) | One HubSpot **Call** activity on the contact (live while ringing, then final) | Signed RingQ link on the call (plays on the timeline) | Optional, needs a HubSpot public app | [hubspot/USER_GUIDE.md](hubspot/USER_GUIDE.md) |
| **Qontak CRM** (Mekari) | [`qontak/`](qontak/) | One Qontak **Task** (category "Call") on the contact | Signed RingQ link in the task detail | No | [qontak/USER_GUIDE.md](qontak/USER_GUIDE.md) |
| **Freshdesk** | [`freshdesk/`](freshdesk/) | One Freshdesk **ticket** (source Phone) on the contact | Audio file attached as a private note | Yes: RingQ app in Freshdesk's phone sidebar | [freshdesk/USER_GUIDE.md](freshdesk/USER_GUIDE.md) |

All three:
- look up the caller's contact by phone during the call, and show it in a RingQ popup (and optionally open it in the CRM);
- log **one** record per call, also when a call rings on several devices or an event is retried;
- store the CRM credential **encrypted** on the server, never sending it to the browser or writing it to logs;
- never touch the RingQ call itself: if the CRM is slow, offline or switched off, calls work as before.

## Repository layout

```
<crm>/
  USER_GUIDE.md     setup and daily use: sign in, get the key, server install, RingQ settings,
                    calls, where details and recordings appear, troubleshooting
  docs/             technical reference (install details, API, call flow, limitations)
  app/              copy into the RingQ app root (/var/www/ringq/app/)
    controllers/    <Crm>Controller.php
    library/<Crm>/  service, API client, encryption, errors
    tasks/          background task for cron
  sql/              database tables (+ rollback), additive only
freshdesk/install.sh   one-command server installer for Freshdesk
freshdesk/tests/       unit checks and a live Freshdesk check
```

The `app/` files are identical to the versions deployed on sgringq100 (23 Oct 2026). The same files also live in the RingQ backend repository (`sgring100`); the frontend code is in `sgring100_frontend` (`src/integrations/<crm>/`, and the dialers `hubspot-cti/`, `freshdesk-cti/`, `freshdesk-app/`).

## Server notes that apply to all three

| Topic | Detail |
|---|---|
| Routing | Phalcon convention routing: `<controller>/<action>` maps to the controller. No router change. |
| Access rules | HubSpot and Qontak need their lines in `app/config/services.php` (see each guide). Freshdesk registers its own rules, so `services.php` is not changed. |
| Background jobs | RingQ's `app/scripts/jobs.sh` rebuilds **root's crontab** from `app/tasks/tasks.txt`. Put a job in `tasks.txt` or in its own `/etc/cron.d/` file; a line added straight to the crontab disappears within a minute. |
| Encryption | HubSpot and Freshdesk derive their keys from `application.cryptSalt`. Qontak uses the key file `/etc/ringq/qontak.key`. |
| After copying files | `php7.4 -l` each file, then `sudo systemctl reload php7.4-fpm`. |
| Install check | Logged out, `https://<server>:8443/<controller>/config` must answer **401** (installed). An empty **404** means the server is not running the files. |
| Logs | `grep "\[hubspot\]\|\[qontak\]\|\[freshdesk\]" /var/log/php7.4-fpm.log`. They never contain credentials. |
| Tests | `php freshdesk/tests/freshdesk_unit_test.php` (68 checks). |
