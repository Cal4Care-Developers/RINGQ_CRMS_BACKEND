-- WhatsApp (Meta SIP calling) micro app -- PostgreSQL (database: ringq)
-- Creates wa_gateway_config if it is missing, and upgrades the older
-- single-tenant table (the /wasip index.php micro app) to one row per tenant:
-- WasipController upserts with ON CONFLICT (domain_uuid).
-- Safe to run more than once (IF NOT EXISTS everywhere). Touches no other table.
-- Run as the DB owner:  psql -U <owner> -d ringq -v ON_ERROR_STOP=1 -f 2026_10_23_wasip.sql

BEGIN;

-- One row per tenant. access_token_enc, app_secret_enc and meta_sip_pass_enc
-- are AES-256-CBC encrypted by the app (key from the WASIP_ENC_KEY env var).
CREATE TABLE IF NOT EXISTS public.wa_gateway_config (
    config_uuid         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid         uuid NULL,
    wa_number           text NOT NULL,              -- digits only, e.g. 6567729965
    phone_number_id     text NOT NULL,              -- Meta "Phone number ID"
    business_account_id text NULL,                  -- Meta WABA ID
    pbx_domain          text NULL,                  -- FQDN Meta sends SIP calls to
    pbx_sip_port        integer DEFAULT 5061,
    extension_number    text NULL,                  -- extension / queue / IVR that rings
    extension_uuid      text NULL,
    dialplan_uuid       text NULL,
    access_token_enc    text NULL,
    app_secret_enc      text NULL,
    meta_sip_pass_enc   text NULL,
    gateway_status      text DEFAULT 'unconfigured', -- unconfigured|configuring|configured|active|failed|deactivated
    sip_status          text NULL,
    last_configured     timestamp NULL,
    last_checked        timestamp NULL,
    created_at          timestamp DEFAULT now(),
    updated_at          timestamp DEFAULT now()
);

-- Older installs: columns added after the first release.
ALTER TABLE public.wa_gateway_config ADD COLUMN IF NOT EXISTS domain_uuid      uuid NULL;
ALTER TABLE public.wa_gateway_config ADD COLUMN IF NOT EXISTS extension_number text NULL;
ALTER TABLE public.wa_gateway_config ADD COLUMN IF NOT EXISTS extension_uuid   text NULL;
ALTER TABLE public.wa_gateway_config ADD COLUMN IF NOT EXISTS dialplan_uuid    text NULL;

-- A single-tenant row from index.php has no domain_uuid: give it to the
-- server's only enabled domain when there is exactly one.
UPDATE public.wa_gateway_config c
   SET domain_uuid = d.domain_uuid
  FROM (SELECT domain_uuid FROM public.v_domains WHERE domain_enabled = 'true') d
 WHERE c.domain_uuid IS NULL
   AND (SELECT count(*) FROM public.v_domains WHERE domain_enabled = 'true') = 1;

-- Required by WasipController's upsert (ON CONFLICT (domain_uuid)).
CREATE UNIQUE INDEX IF NOT EXISTS wa_gateway_config_domain_uuid_key ON public.wa_gateway_config (domain_uuid);

COMMIT;
