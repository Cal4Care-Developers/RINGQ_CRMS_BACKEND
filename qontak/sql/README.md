# Qontak: database (PostgreSQL `ringq`)

| File | Use |
|---|---|
| `2026_10_14_qontak_crm.sql` | **Run this.** Creates `q_qontak_config`, `q_qontak_call_journals`, `q_qontak_webhook_events`. |
| `2026_10_14_qontak_crm_rollback.sql` | Drops the three Qontak tables (back them up first). |
| `qontak_schema.sql` | The **complete resulting structure** (tables, constraints, indexes), dumped from sgringq100 on 23 Oct 2026. Identical to running the script above. |

Install (safe to run again):

```bash
sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < 2026_10_14_qontak_crm.sql
```

If the RingQ app does not connect as the table owner:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_qontak_config, public.q_qontak_call_journals, public.q_qontak_webhook_events TO <app_db_user>;
```

The script only creates Qontak tables. At runtime the code also **reads** `q_ringq_cdr` and `v_domains`, and only writes `q_ringq_cdr.json` when AI transcription fills an empty transcript.

## Tables

| Table | One row per | Main columns |
|---|---|---|
| `q_qontak_config` | tenant (`domain_uuid`) | `enabled`, `environment`, `client_id`, `client_secret_enc` (encrypted with `/etc/ringq/qontak.key`), `crm_user_sso_id`, `sign_mode`, `task_category_id`, `settings` (JSON), `connection_status`, `last_error`, `webhook_token`, `link_salt` (rotated by Disconnect to revoke recording links) |
| `q_qontak_call_journals` | RingQ call (`UNIQUE domain_uuid, local_call_id`) | direction, numbers, `call_status`, start/answer/end times, `duration`, `talk_duration`, `qontak_contact_id`, `qontak_task_id` (unique per tenant: one task per call), `xml_cdr_uuid`, `recording_status`, AI fields, `sync_status`, retry fields |
| `q_qontak_webhook_events` | received webhook (`PRIMARY KEY domain_uuid, event_hash`) | `event_name`, `received_at` (de-duplication; pruned after 7 days by `qontak prune`) |
