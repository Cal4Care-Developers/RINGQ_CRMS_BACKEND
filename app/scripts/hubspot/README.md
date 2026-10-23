# HubSpot: database (PostgreSQL `ringq`)

| File | Use |
|---|---|
| `2026_10_21_hubspot_crm.sql` | **Run first.** Creates `q_hubspot_config` and `q_hubspot_call_journals`. |
| `2026_10_22_hubspot_cti.sql` | **Run second.** Adds the journal column `hubspot_owns_call` and the `call_origin` values the code expects, and creates `q_hubspot_cti_tokens` (not used; stays empty). |
| `2026_10_21_hubspot_crm_rollback.sql` | Drops all three HubSpot tables (back them up first). |
| `hubspot_schema.sql` | The **complete resulting structure** (tables, constraints, indexes), dumped from sgringq100 on 23 Oct 2026. Identical to running the two scripts above. For reference, or to create the tables in one step on an empty database. |

Install (safe to run again):

```bash
sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < 2026_10_21_hubspot_crm.sql
sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < 2026_10_22_hubspot_cti.sql
```

If the RingQ app does not connect as the table owner:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_hubspot_config, public.q_hubspot_call_journals, public.q_hubspot_cti_tokens TO <app_db_user>;
```

The scripts only create HubSpot tables. At runtime the code also **reads** `q_ringq_cdr`, `v_domains`, `v_extensions`, `v_extension_users` and `v_users`, and only writes `q_ringq_cdr.json` when AI transcription fills an empty transcript.

## Tables

| Table | One row per | Main columns |
|---|---|---|
| `q_hubspot_config` | tenant (`domain_uuid`) | `enabled`, `access_token_enc` (encrypted token), `token_last4`, `portal_id`, `ui_domain`, `granted_scopes`, `call_status_options`, `dispositions`, `settings` (JSON), `connection_status`, `last_error`, `link_salt` (rotated by Disconnect to revoke recording links) |
| `q_hubspot_call_journals` | RingQ call (`UNIQUE domain_uuid, local_call_id`) | direction, origin, numbers, `call_status`, start/answer/end times, `duration`, `talk_duration`, `notes`, `hubspot_contact_id`, `hubspot_call_id` (unique per tenant: one HubSpot call per RingQ call), `create_attempted_at`, `xml_cdr_uuid`, `recording_status`, AI fields, `sync_status`, retry fields, `hubspot_owns_call` |
| `q_hubspot_cti_tokens` | not used (reserved; stays empty) | `token_hash` (SHA-256 only), user, extension, `expires_at`, `revoked_at` |
