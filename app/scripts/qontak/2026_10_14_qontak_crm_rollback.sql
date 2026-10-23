-- Rollback for 2026_10_14_qontak_crm.sql. DESTROYS Qontak config and call journals.
-- Only run deliberately, after a backup:
--   pg_dump -U <owner> -d ringq -t 'public.q_qontak_*' > qontak_backup.sql
BEGIN;
DROP TABLE IF EXISTS public.q_qontak_webhook_events;
DROP TABLE IF EXISTS public.q_qontak_call_journals;
DROP TABLE IF EXISTS public.q_qontak_config;
COMMIT;
