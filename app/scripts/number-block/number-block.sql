-- [number-block] Centralised number blocking (Global Settings > Security > Number blocking).
-- Applied by number-block-setup.sh on every central update (idempotent: fixed uuid, delete + insert).
-- The table is also created by the web app (library/NumberBlock.php) on first use.
BEGIN;
CREATE TABLE IF NOT EXISTS public.q_number_blocks (
    number_block_uuid CHAR(36) PRIMARY KEY,
    domain_uuid CHAR(36) NOT NULL,
    block_number VARCHAR(32) NOT NULL,
    block_type VARCHAR(10) NOT NULL DEFAULT 'number',
    block_direction VARCHAR(10) NOT NULL DEFAULT 'inbound',
    description VARCHAR(64) NOT NULL DEFAULT '',
    enabled BOOLEAN NOT NULL DEFAULT true,
    block_count INTEGER NOT NULL DEFAULT 0,
    last_blocked_at TIMESTAMP,
    insert_user CHAR(36),
    insert_date TIMESTAMP DEFAULT now(),
    update_date TIMESTAMP
);
CREATE INDEX IF NOT EXISTS q_number_blocks_domain ON public.q_number_blocks (domain_uuid, enabled);
-- created here as postgres: hand it to the role that owns the rest of the schema, so the app can use it
DO $own$
DECLARE o text;
BEGIN
  SELECT pg_get_userbyid(relowner) INTO o FROM pg_class WHERE oid = to_regclass('public.v_dialplans');
  IF o IS NOT NULL THEN EXECUTE format('ALTER TABLE public.q_number_blocks OWNER TO %I', o); END IF;
END $own$;
DELETE FROM v_dialplans WHERE dialplan_uuid = 'eab7a901-23ec-4e1e-8797-9eab6558bd7a';
INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name,
  dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled,
  dialplan_description, insert_date)
VALUES (NULL, 'eab7a901-23ec-4e1e-8797-9eab6558bd7a', '449fcd08-0e6e-4cdb-ac47-549bc637900a', NULL, 'global',
  'ringq_number_block', '', NULL, 'true', $xml$<extension name="ringq_number_block" continue="true">
    <condition field="${ringq_nb_done}" expression="^$">
        <action application="lua" data="ringq_number_block.lua"/>
    </condition>
</extension>$xml$, 38, 'true', 'Centralised number blocking (Security > Number blocking)', now());
COMMIT;
