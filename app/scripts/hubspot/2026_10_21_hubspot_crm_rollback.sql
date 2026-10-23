-- Rollback for the HubSpot integration (2026_10_21_hubspot_crm.sql + 2026_10_22_hubspot_cti.sql).
-- DESTROYS HubSpot config, call journals and dialer tokens (calls already in HubSpot stay there).
-- Only run deliberately, after a backup:
--   pg_dump -U <owner> -d ringq -t 'public.q_hubspot_*' > hubspot_backup.sql
BEGIN;
DROP TABLE IF EXISTS public.q_hubspot_cti_tokens;
DROP TABLE IF EXISTS public.q_hubspot_call_journals;
DROP TABLE IF EXISTS public.q_hubspot_config;
COMMIT;
