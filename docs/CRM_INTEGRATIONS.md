# CRM integrations: HubSpot, Qontak, Freshdesk

RingQ's three CRM integrations are part of this backend (PHP 7.4, Phalcon 4, PostgreSQL `ringq`). Each one has its own controller, library, tables and background task inside the same `app/` tree, and its own guide.

Calls are made and answered in RingQ. There is no dialer inside the CRMs: each CRM receives the contact lookup, the call record and the recording.

| CRM | How a call is saved | Recording | Guide |
|---|---|---|---|
| **HubSpot** | One HubSpot **Call** activity on the contact (live while ringing, then final) | Signed RingQ link on the call (plays on the timeline) | [hubspot/USER_GUIDE.md](hubspot/USER_GUIDE.md) |
| **Qontak CRM** (Mekari) | One Qontak **Task** (category "Call") on the contact | Signed RingQ link in the task detail | [qontak/USER_GUIDE.md](qontak/USER_GUIDE.md) |
| **Freshdesk** | One Freshdesk **ticket** (source Phone) on the contact | Audio file attached as a private note | [freshdesk/USER_GUIDE.md](freshdesk/USER_GUIDE.md) |

All three are configured in RingQ under **Settings → Integrations → CRM**:

![RingQ Settings → Integrations → CRM with the HubSpot, Qontak and Freshdesk cards](images/ringq-crm-integrations.png)

All three:
- look up the caller's contact by phone during the call, and show it in a RingQ popup (and optionally open it in the CRM);
- log **one** record per call, also when a call rings on several devices or an event is retried;
- store the CRM credential **encrypted** on the server, never sending it to the browser or writing it to logs;
- never touch the RingQ call itself: if the CRM is slow, offline or switched off, calls work as before.

## Where the files are

| | HubSpot | Qontak | Freshdesk |
|---|---|---|---|
| Controller | `app/controllers/HubspotcrmController.php` | `app/controllers/QontakController.php` | `app/controllers/FreshdeskcrmController.php` |
| Library | `app/library/Hubspot/` | `app/library/Qontak/` | `app/library/Freshdesk/` |
| Background task | `app/tasks/HubspotcrmTask.php` | `app/tasks/QontakTask.php` | `app/tasks/FreshdeskcrmTask.php` |
| Cron | `app/tasks/tasks.txt` | `app/tasks/tasks.txt` | `app/tasks/tasks.txt` |
| Database scripts | `app/scripts/hubspot/` | `app/scripts/qontak/` | `app/scripts/freshdesk/` |
| Access rules | `app/config/services.php` | `app/config/services.php` | registered by the controller itself |
| Docs | `docs/hubspot/` | `docs/qontak/` | `docs/freshdesk/` |
| Installer / tests | | | `server-configs/freshdesk/install.sh`, `tests/freshdesk/` |

## Database

Each `app/scripts/<crm>/` folder has the migration scripts (run these on a server; safe to run again), a rollback script, the complete table structure as `<crm>_schema.sql`, and a `README.md` describing each table.

| CRM | Tables | Scripts, in order |
|---|---|---|
| HubSpot | `q_hubspot_config`, `q_hubspot_call_journals`, `q_hubspot_cti_tokens` | `2026_10_21_hubspot_crm.sql`, then `2026_10_22_hubspot_cti.sql` |
| Qontak | `q_qontak_config`, `q_qontak_call_journals`, `q_qontak_webhook_events` | `2026_10_14_qontak_crm.sql` |
| Freshdesk | `q_freshdesk_config`, `q_freshdesk_call_journals`, `q_freshdesk_cti_tokens` | `2026_10_23_freshdesk_crm.sql` |

The `*_cti_tokens` tables are not used (reserved) and stay empty; the code expects them to exist. Checked on 23 Oct 2026: the live tables on sgringq100 are exactly what these scripts create, and each `<crm>_schema.sql` applies cleanly on an empty database. The files contain structure only: no rows and no credentials. All scripts are additive: they create only their own `q_<crm>_*` tables.

## Server notes that apply to all three

| Topic | Detail |
|---|---|
| Routing | Phalcon convention routing: `<controller>/<action>` maps to the controller. No router change. |
| Access rules | HubSpot and Qontak have their lines in `app/config/services.php`. Freshdesk registers its own rules. |
| Background jobs | RingQ's `app/scripts/jobs.sh` rebuilds **root's crontab** from `app/tasks/tasks.txt`, which lists all three sync jobs. A line added straight to the crontab disappears within a minute. |
| Encryption | HubSpot and Freshdesk derive their keys from `application.cryptSalt`. Qontak uses the key file `/etc/ringq/qontak.key`. |
| After deploying | `php7.4 -l` each changed file, then `sudo systemctl reload php7.4-fpm`. |
| Install check | Logged out, `https://<server>:8443/<controller>/config` must answer **401** (installed). An empty **404** means the server is not running the files. |
| Logs | `grep "\[hubspot\]\|\[qontak\]\|\[freshdesk\]" /var/log/php7.4-fpm.log`. They never contain credentials. |
| Tests | `php tests/freshdesk/freshdesk_unit_test.php` (68 checks). |
