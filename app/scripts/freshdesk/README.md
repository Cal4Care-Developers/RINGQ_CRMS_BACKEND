# Freshdesk: database (PostgreSQL `ringq`)

| File | Use |
|---|---|
| `2026_10_23_freshdesk_crm.sql` | **Run this** (or let `server-configs/freshdesk/install.sh` do it). Creates `q_freshdesk_config`, `q_freshdesk_call_journals`, `q_freshdesk_cti_tokens`. |
| `2026_10_23_freshdesk_crm_rollback.sql` | Drops the three Freshdesk tables (back them up first). Tickets already in Freshdesk stay there. |
| `freshdesk_schema.sql` | The **complete resulting structure** (tables, constraints, indexes), dumped from sgringq100 on 23 Oct 2026. Identical to running the script above. |

Install (safe to run again). Feed the file on standard input: the `postgres` user usually cannot read files under `/root`.

```bash
sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 < 2026_10_23_freshdesk_crm.sql
```

If the RingQ app does not connect as the table owner:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON public.q_freshdesk_config, public.q_freshdesk_call_journals, public.q_freshdesk_cti_tokens TO <app_db_user>;
```

The script only creates Freshdesk tables. At runtime the code also **reads** `q_ringq_cdr` (recordings), `v_domains`, `v_extensions`, `v_extension_users` and `v_users` (colleagues on internal calls); it never writes to them.

## Tables

| Table | One row per | Main columns |
|---|---|---|
| `q_freshdesk_config` | tenant (`domain_uuid`) | `enabled`, `fd_domain`, `api_key_enc` (encrypted API key), `key_last4`, `fd_agent_id`, `fd_agent_name`, `fd_account_name`, `ticket_field_warnings`, `settings` (JSON), `connection_status`, `last_error`, `link_salt` (rotated by Disconnect to revoke recording links) |
| `q_freshdesk_call_journals` | RingQ call (`UNIQUE domain_uuid, local_call_id`) | direction, origin (`dialer`: the call was made or answered in RingQ), numbers, `call_status`, start/answer/end times, `duration`, `talk_duration`, `notes`, `disposition`, `notes_note_id`, `freshdesk_contact_id`, `freshdesk_ticket_id` (unique per tenant: one ticket per call), `create_attempted_at`, `xml_cdr_uuid`, `recording_status`, `recording_note_id`, `recording_attempted_at`, `sync_status`, retry fields |
| `q_freshdesk_cti_tokens` | not used (reserved; stays empty) | `token_hash` (SHA-256 only), user, extension, `expires_at`, `revoked_at` |
