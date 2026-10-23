-- Rolls back 2026_10_23_freshdesk_crm.sql: drops the three Freshdesk-only tables.
-- Nothing else is touched. Tickets already created in Freshdesk stay there.
BEGIN;
DROP TABLE IF EXISTS public.q_freshdesk_cti_tokens;
DROP TABLE IF EXISTS public.q_freshdesk_call_journals;
DROP TABLE IF EXISTS public.q_freshdesk_config;
COMMIT;
