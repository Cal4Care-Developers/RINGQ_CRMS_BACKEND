-- HubSpot CTI dialer (calling inside HubSpot) -- PostgreSQL (database: ringq)
-- Run AFTER 2026_10_21_hubspot_crm.sql. Additive: one new table, one new
-- column and a widened CHECK on the HubSpot-only journal table. Touches no
-- other table. Safe to run more than once.

BEGIN;

-- Dialer tokens. The HubSpot calling window runs inside app.hubspot.com, where
-- the browser does not send RingQ's (SameSite=Lax) login cookie, so the agent
-- connects the dialer once through a RingQ pop-up, which issues one of these.
-- Only the SHA-256 of the token is stored; tokens expire and can be revoked.
CREATE TABLE IF NOT EXISTS public.q_hubspot_cti_tokens (
    token_hash    char(64)     PRIMARY KEY,
    domain_uuid   uuid         NOT NULL,
    user_uuid     varchar(64)  NOT NULL,
    extension     varchar(32)  NOT NULL,
    agent_name    varchar(120) NULL,
    created_at    timestamptz  NOT NULL DEFAULT now(),
    last_used_at  timestamptz  NULL,
    expires_at    timestamptz  NOT NULL,
    revoked_at    timestamptz  NULL
);
CREATE INDEX IF NOT EXISTS q_hubspot_cti_tokens_user_idx
    ON public.q_hubspot_cti_tokens (domain_uuid, user_uuid);

-- Calls placed from the HubSpot dialer: HubSpot creates the call record itself;
-- RingQ then only adds the recording / status to it (never a second call).
ALTER TABLE public.q_hubspot_call_journals
    ADD COLUMN IF NOT EXISTS hubspot_owns_call boolean NOT NULL DEFAULT false;

ALTER TABLE public.q_hubspot_call_journals
    DROP CONSTRAINT IF EXISTS q_hubspot_call_journals_call_origin_check;
ALTER TABLE public.q_hubspot_call_journals
    ADD CONSTRAINT q_hubspot_call_journals_call_origin_check
    CHECK (call_origin IN ('dialer', 'click_to_call', 'hubspot_cti'));

COMMIT;
