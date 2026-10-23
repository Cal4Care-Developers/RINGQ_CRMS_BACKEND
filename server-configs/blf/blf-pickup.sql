-- [blf-keys] BLF key pickup: a desk phone's BLF key watches the extension's SIP user (number_alias),
-- so while it rings the key dials *8<SIP user>. The existing *8<digits> pickup only takes numbers.
-- local_extension saves each ringing call under ${domain_name}-last_dial_ext/<dialed number> (1045),
-- and only rarely under the SIP user, where an old call can stay behind. So: use the number's entry
-- first, and the SIP user's entry only when the number's call no longer exists.
BEGIN;
DELETE FROM v_dialplans WHERE dialplan_uuid = '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c801';
INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name,
  dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled,
  dialplan_description, insert_date)
VALUES (NULL, '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c801', '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c802', NULL, 'global',
  'blf_sip_user_pickup', '[ext]', NULL, 'false', $xml$<extension name="blf_sip_user_pickup" continue="false">
    <condition field="destination_number" expression="^\*8([0-9a-f]{20})$" break="on-false">
        <action application="set" data="blf_pickup_user=$1" inline="true"/>
        <action application="set" data="blf_pickup_number=${user_data($1@${domain_name} attr number-alias)}" inline="true"/>
        <action application="set" data="blf_pickup_uuid=${hash(select/${domain_name}-last_dial_ext/${blf_pickup_number})}" inline="true"/>
        <action application="set" data="blf_pickup_uuid_alias=${hash(select/${domain_name}-last_dial_ext/$1)}" inline="true"/>
    </condition>
    <condition field="${uuid_exists(${blf_pickup_uuid})}" expression="^true$">
        <action application="answer"/>
        <action application="intercept" data="${blf_pickup_uuid}"/>
        <anti-action application="answer"/>
        <anti-action application="intercept" data="${blf_pickup_uuid_alias}"/>
    </condition>
</extension>$xml$, 10, 'true', 'BLF key pickup by SIP user', now());
COMMIT;
SELECT dialplan_name, dialplan_context, dialplan_enabled FROM v_dialplans WHERE dialplan_uuid = '7b1f0c52-4e1d-4a8e-9c3a-b1f0a5e2c801';
