-- [desk-features] Dial codes behind the desk phone keys (global dial plans):
--   *591 .. *595   Shared Parking slot 1-5: park the call transferred here, or pick up the parked call
--   *26<id>        Agent Status / Change Status: set the caller's aux code (q_auxcodes.id)
--   *78 / *79 / *77  DND on / off / toggle (the existing "do-not-disturb" plan, switched on), used by
--                  the phones' own DND button so DND on the phone and in the apps is one setting
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
UPDATE v_dialplans SET dialplan_enabled = 'true' WHERE dialplan_uuid = 'b2ab3f05-fa67-4821-917c-88e87c2b7608';
COMMIT;
SELECT dialplan_name, dialplan_number, dialplan_enabled FROM v_dialplans
 WHERE dialplan_name IN ('ringq_desk_park', 'ringq_desk_aux', 'do-not-disturb') ORDER BY dialplan_name;
