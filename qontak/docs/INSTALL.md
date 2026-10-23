# Qontak CRM integration: backend install and deploy (sgringq100)

This is a self-contained module. It adds **new files only**, plus **two additive edits** to existing config: the ACL lists in `services.php` and the cron list in `tasks.txt`. It creates no routes and makes no changes to any existing controller, model or table.

Target environment, from reading `ringq_core-main`: **PHP 7.4, Phalcon 4 MVC, PostgreSQL `ringq`**. Paths below assume `/var/www/ringq`.

## 1. Files

| Copy from `qontak/` (this repository) | To the server |
|---|---|
| `app/controllers/QontakController.php` | `/var/www/ringq/app/controllers/` |
| `app/library/Qontak/*.php` (5 files) | `/var/www/ringq/app/library/Qontak/` |
| `app/tasks/QontakTask.php` | `/var/www/ringq/app/tasks/` |
| `sql/*.sql` | anywhere (run once, see step 4) |

If production code is ionCube-encoded by CI, add these files to the same pipeline as every other `app/**/*.php` file.

## 2. Back up first

```bash
sudo cp -a /var/www/ringq/app/config/services.php /var/www/ringq/app/config/services.php.bak-qontak
sudo cp -a /var/www/ringq/app/tasks/tasks.txt /var/www/ringq/app/tasks/tasks.txt.bak-qontak
sudo -u postgres pg_dump -d ringq --schema-only > /root/ringq-schema-before-qontak.sql
```

## 3. Encryption key (server only, never in Git)

```bash
sudo mkdir -p /etc/ringq
sudo sh -c 'head -c 32 /dev/urandom | base64 > /etc/ringq/qontak.key'
sudo chown root:www-data /etc/ringq/qontak.key
sudo chmod 0640 /etc/ringq/qontak.key
```

Rules for this key:
- PHP-FPM runs as `www-data` and only needs read access.
- The cron runs `cli.php` as root, so it can read the key too.
- **Back up the key separately.** If it is lost, stored Qontak secrets can no longer be decrypted and must be re-entered.
- To use another path, set `QONTAK_KEY_FILE`, in both the FPM pool (`env[QONTAK_KEY_FILE]=...`) and the cron environment.

The following setting is optional. It enables AI transcription to *trigger* RingQ's own transcription for recordings that have none yet. It uses the internal key that `custom_crm.js` already sends as `X-Internal-Key`.
- FPM pool: `env[QONTAK_RINGQ_INTERNAL_KEY]=<value>`
- Cron: export the same variable.

Without it, only transcripts that already exist on the CDR are used.

## 4. Database

```bash
sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < sql/2026_10_14_qontak_crm.sql
sudo -u postgres psql -d ringq -c "GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_qontak_config, public.q_qontak_call_journals, public.q_qontak_webhook_events TO <app_db_user>;"
```

The migration creates three new tables (`q_qontak_config`, `q_qontak_call_journals`, `q_qontak_webhook_events`) and their indexes:
- One Qontak task per call, enforced by the unique `(domain_uuid, local_call_id)` and `(domain_uuid, qontak_task_id)` indexes.
- Webhook de-duplication, enforced by the primary key `(domain_uuid, event_hash)`.

The `GRANT` is only needed if the app does not connect as the table owner. The migration reads `q_ringq_cdr` and `v_domains` but never modifies them.

## 5. ACL (`app/config/services.php`)

Phalcon's ACL is an allow-list: an action that is missing from it gets redirected to `index`. Add one line to each array. Do not change any other entry.

```php
// in $privately (role "registered"), next to the other controllers:
"qontak" => ["config", "testConnection", "disconnect", "contactsSearch", "contacts", "callEvents", "callJournals", "recordingsSync", "aiAnalysis"],

// in $publicly (role "nonregistered"):
"qontak" => ["webhooks", "recording"],
```

Admin-only actions are enforced inside the controller with `isPlainUser()`. They are `POST config`, `testConnection`, `disconnect`, `recordingsSync` and `aiAnalysis`. Plain users can see only their own call journals.

## 6. Cron (`app/tasks/tasks.txt` / crontab)

```
*/1 * * * * /usr/bin/php /var/www/ringq/app/cli.php qontak sync
15 3 * * *  /usr/bin/php /var/www/ringq/app/cli.php qontak prune
```

`cli.php` autoloads `app/tasks`, so `QontakTask` needs no registration.

## 7. Permissions, reload, check

```bash
sudo chown -R www-data:www-data /var/www/ringq/app/controllers/QontakController.php /var/www/ringq/app/library/Qontak /var/www/ringq/app/tasks/QontakTask.php
sudo find /var/www/ringq/app/library/Qontak -type f -exec chmod 0644 {} \;
php7.4 -l /var/www/ringq/app/controllers/QontakController.php
for f in /var/www/ringq/app/library/Qontak/*.php /var/www/ringq/app/tasks/QontakTask.php; do php7.4 -l "$f"; done
sudo systemctl reload php7.4-fpm
```

HTTPS and CORS need no change. The app already calls `https://<host>:8443/...`, and nginx already allows same-host origins with credentials.

## 8. Smoke tests on the server

Run these while logged in to the web app, as an admin, from the browser devtools console on `https://sgringq100.ringq.ai`:

```js
const b = 'https://sgringq100.ringq.ai:8443';
await (await fetch(b + '/qontak/config', {credentials:'include'})).json();
// -> {result:"success", content:{connection_status:"not_configured", client_secret_set:false, ...}}
```

Without a session, `curl -i https://sgringq100.ringq.ai:8443/qontak/config` must **not** return config. It should return a redirect or `401`.

After saving real credentials in the UI:
- **Test connection** calls `GET https://api.mekari.com/qontak/crm/contacts?page=1&per_page=1`.
- Check `tail -f /var/log/nginx/error.log /var/log/php7.4-fpm.log` for `[qontak]` lines.
- Those lines contain only method, path pattern, HTTP status and the API's error text. They never contain secrets, signatures or headers.

## 9. Rollback

1. Comment out the two `"qontak"` ACL lines and the two cron lines.
2. Reload php-fpm. The frontend shows "Not configured" and the dialer is unaffected.
3. Dropping the tables is optional: `2026_10_14_qontak_crm_rollback.sql`, after a `pg_dump` of them.

## 10. Things that must be confirmed with Mekari/Qontak

| Item | Status |
|---|---|
| HMAC Client ID + Secret for **Qontak CRM** in the Mekari Developer Center | Required. The company owner must request it. The dashboard login does not work for the API. |
| `X-Crm-User-Sso-Id` | Documented as "sso_id of user that want to do action". It's unclear whether it's mandatory and where admins find it. The field is optional in the UI. |
| Request-line signing with or without the query string | The KB and CRM Postman collection disagree. The client tries both and remembers the one that works. |
| `Digest` header on POST/PUT | Sent as the KB requires. The CRM collection omits it. |
| Call log / journal endpoint | **None exists** in the Qontak CRM API. Calls are logged as **Tasks**, using a category named by the admin (default "Call"). |
| Recording upload | No documented audio upload for tasks. The task detail gets a **permanent, signed, tenant-bound link** served by `qontak/recording/...` (revoked by Disconnect; set `RECORDING_LINK_DAYS` > 0 in QontakService for expiring links). |
| Webhook signature | Not documented. The receiver URL carries a secret per-tenant token, and events are de-duplicated by body hash. The URL must be registered with Mekari. |
| Rate limits | Not documented. HTTP 429 and `Retry-After` are honoured, with exponential backoff and at most 8 retries. |
| AI transcription, summary, sentiment | Not in the Qontak API. Transcription uses RingQ's existing per-tenant transcription. Summary and sentiment need a provider; `QontakSummaryProvider` is the interface to plug one in. RingQ's current pipeline stores only the placeholders "No Summary Available" and "neutral", so these are ignored. |
| Outbound busy vs rejected | The dialer (`sipService`) does not expose the SIP reject code, so these are logged as `no_answer`. Inbound rejected and missed calls, and outbound failed calls, are distinct. |

## 11. Key rotation

1. Generate a new key file.
2. For each row: `decrypt(old)` then `encrypt(new)`.
3. Alternatively, have admins re-enter the secret after replacing the key.

Changing the key also invalidates all recording links already sent to Qontak. To revoke links without changing the key, use **Disconnect** (it rotates `link_salt`).
