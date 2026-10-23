-- [desk-features] Global dial plans behind the desk phone keys. Applied by desk-features-setup.sh on
-- every central update (idempotent: fixed uuids, delete + insert in one transaction).
--   *591 .. *595     Shared Parking slot 1-5 (ringq_desk_park.lua)
--   *26<id>          Agent Status / Change Status: set the caller's aux code (ringq_desk_aux.lua)
--   *8<SIP user>     BLF key pickup by SIP user: the call dialled to that extension, or a queue
--                    call ringing it (ringq_blf_pickup.lua)
--   *78 / *79 / *77  DND on / off / toggle: the stock "do-not-disturb" plan, switched on, so the
--                    phones' own DND button and the apps share one DND setting
BEGIN;
DELETE FROM v_dialplans WHERE dialplan_uuid IN ('7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c811', '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c821');
INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name,
  dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled,
  dialplan_description, insert_date)
VALUES
 (NULL, '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c811', '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c812', NULL, 'global',
  'ringq_desk_park', '*591-*595', NULL, 'false', $xml$<extension name="ringq_desk_park" continue="false">
    <condition field="destination_number" expression="^\*59([1-5])$">
        <action application="lua" data="ringq_desk_park.lua $1"/>
    </condition>
</extension>$xml$, 10, 'true', 'Shared Parking keys on desk phones', now()),
 (NULL, '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c821', '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c822', NULL, 'global',
  'ringq_desk_aux', '*26', NULL, 'false', $xml$<extension name="ringq_desk_aux" continue="false">
    <condition field="destination_number" expression="^\*26([0-9]{1,9})$">
        <action application="lua" data="ringq_desk_aux.lua $1"/>
    </condition>
</extension>$xml$, 10, 'true', 'Agent Status and Change Status keys on desk phones', now());
UPDATE v_dialplans SET dialplan_enabled = 'true' WHERE dialplan_name = 'do-not-disturb' AND dialplan_enabled::text <> 'true';
DELETE FROM v_dialplans WHERE dialplan_uuid = '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c801';
INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name,
  dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled,
  dialplan_description, insert_date)
VALUES (NULL, '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c801', '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c802', NULL, 'global',
  'blf_sip_user_pickup', '[ext]', NULL, 'false', $xml$<extension name="blf_sip_user_pickup" continue="false">
    <condition field="destination_number" expression="^\*8([0-9a-f]{20})$">
        <action application="lua" data="ringq_blf_pickup.lua $1"/>
    </condition>
</extension>$xml$, 10, 'true', 'BLF key pickup by SIP user', now());
COMMIT;
