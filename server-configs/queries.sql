CREATE SEQUENCE IF NOT EXISTS q_email_notifications_id_seq;

UPDATE v_sip_profile_settings SET sip_profile_setting_enabled = 'true' WHERE sip_profile_setting_name = 'accept-blind-auth';

INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name, dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled, dialplan_description, insert_date, insert_user, update_date, update_user) VALUES 
    (NULL, 'bee8ae9e-9370-40a0-8262-ccb077625c4a', 'e3c14f2f-7697-4b5d-9c62-2443c0e22bf2', NULL, 'global', 'sip_alias', '[ext]', NULL, 'true', '<extension name="sip_alias" continue="true" uuid="bee8ae9e-9370-40a0-8262-ccb077625c4a"> <condition field="${user_data ${effective_caller_id_number}@${domain_name} var sip_alias}" expression=".+"> <action application="export" data="sip_alias_name=${user_data ${effective_caller_id_number}@${domain_name} var sip_alias_name}"/> <action application="set" data="sip_alias_value=${user_data ${effective_caller_id_number}@${domain_name} var sip_alias}"/> <action application="set" data="effective_caller_id_number=${sip_alias_value}"/> <action application="set" data="extension_caller_id_number=${sip_alias_value}"/> <action application="set" data="outbound_caller_id_number=${sip_alias_value}"/> <action application="set" data="origination_caller_id_number=${sip_alias_value}"/> <action application="set" data="caller_id_number=${sip_alias_value}"/> </condition> </extension>', 901, 'true', NULL, '2023-08-22 02:31:13.485469+00', NULL, '2023-08-22 02:31:14.633889+00', NULL) 
    ON CONFLICT (dialplan_uuid) DO NOTHING;

ALTER TABLE member_board ADD COLUMN IF NOT EXISTS waiting_seconds BIGINT DEFAULT 0 NOT NULL;

ALTER TABLE q_call_ratings ADD COLUMN IF NOT EXISTS queue_name TEXT NULL;

ALTER TABLE agent_details ADD COLUMN IF NOT EXISTS update_date timestamp DEFAULT NULL;

INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name, dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled, dialplan_description, insert_date, insert_user, update_date, update_user) VALUES 
    (NULL, 'f018fcb7-6ae7-438a-b498-7a9292d51435', '85dc0bf2-8add-4b36-b007-51ce891d7af2', NULL, 'global', 'unknown-destination', NULL, NULL, 'true', '<extension name="unknown-destination"><condition field="destination_number" expression="^\\d+$"><action application="set" data="error_detected=true"/><action application="playback" data="misc/no_dialplan.wav"/><action application="hangup" data="UNALLOCATED_NUMBER"/></condition></extension>', '9999', 'true', NULL, '2023-08-22 02:31:13.485469+00', NULL, '2023-08-22 02:31:14.633889+00', NULL) 
    ON CONFLICT (dialplan_uuid) DO NOTHING;

INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name, dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled, dialplan_description, insert_date, insert_user, update_date, update_user) VALUES 
    (NULL, 'a9199b65-44de-4646-96ae-f02843912bab', '4e0f3d96-8cb6-4595-87fc-d8517d89fa5f', NULL, 'global', 'global-hold', NULL, NULL, 'true', '<extension name="global-hold" continue="true"><condition field="destination_number" expression="^.*$"><action application="set" data="hold_music=local_stream://${domain_name}//16000"/><action application="export" data="nolocal:hold_music=local_stream://${domain_name}//16000"/></condition></extension>', '1', 'true', NULL, NULL, NULL, NULL, NULL) 
    ON CONFLICT (dialplan_uuid) DO NOTHING;

INSERT INTO v_dialplans (domain_uuid, dialplan_uuid, app_uuid, hostname, dialplan_context, dialplan_name, dialplan_number, dialplan_destination, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled, dialplan_description, insert_date, insert_user, update_date, update_user) 
    SELECT NULL, '5bdb8f6d-22d0-4239-a13c-fff7cfdf40e1', 'a08a278f-9327-4c4a-9938-1f53f94ef59c', NULL, 'global', 'export-callid', NULL, NULL, 'true', '<extension name="export-callid" continue="true"><condition field="destination_number" expression=".*"><action application="export" data="orig_sip_call_id=${sip_call_id}"/><action application="export" data="sip_h_X-Original-Call-ID=${sip_call_id}"/></condition></extension>', '1', 'true', NULL, '2023-08-22 05:31:13.485469+03', NULL, '2023-08-22 05:31:14.768982+03', NULL 
    FROM v_dialplans WHERE dialplan_context 
    LIKE '%global%' AND ((dialplan_uuid = 'f701a59a-cc92-4130-84bf-1ea251c0a16f')) 
    ON CONFLICT (dialplan_uuid) DO NOTHING;

CREATE TABLE IF NOT EXISTS public.q_email_notifications (
    id integer DEFAULT nextval('q_email_notifications_id_seq') NOT NULL, 
    domain_uuid uuid NOT NULL, 
    send_system_owner integer DEFAULT 0 NOT NULL, 
    emails text,
    disk_space integer DEFAULT 0 NOT NULL, 
    voicemail_space integer DEFAULT 0 NOT NULL, 
    cpu integer DEFAULT 0 NOT NULL, 
    ram integer DEFAULT 0 NOT NULL, 
    disk_space_percentage double precision DEFAULT 0.75, 
    voicemail_space_percentage double precision DEFAULT 0.75, 
    cpu_percentage double precision DEFAULT 0.75, 
    ram_percentage double precision DEFAULT 0.75, 
    update_date timestamptz, 
    update_user uuid, 
    insert_date timestamptz, 
    insert_user uuid, 
    CONSTRAINT q_email_notifications_pkey 
    PRIMARY KEY (id)
);

UPDATE v_dialplans 
SET dialplan_xml = $xml$
    <extension name="limit_exceeded">
        <condition field="destination_number" expression="^limit_exceeded$">
            <action application="playback" data="misc/max_concurrent_calls.wav"/>
            <action application="hangup"/>
        </condition>
    </extension>
    $xml$
WHERE dialplan_uuid = '83f7ba49-3f32-4ef7-a410-087ef792b974';

UPDATE v_dialplans 
SET dialplan_xml = $xml$
    <extension name="external_limit_exceeded">
        <condition field="destination_number" expression="^limit_exceeded$">
            <action application="playback" data="misc/endCallBeep.wav"/>
            <action application="hangup"/>
        </condition>
    </extension>
    $xml$
WHERE dialplan_uuid = 'ef3bf1be-ca3b-4a3b-8f1f-a29e992f2c1f';

ALTER TABLE q_email_notifications ADD COLUMN IF NOT EXISTS concurrent_limit INTEGER DEFAULT NULL;

UPDATE v_dialplans
SET dialplan_xml = $xml$
    <extension name="voicemail" continue="false" uuid="6577e3d8-31d3-45c2-b530-72f16bd4a10c">
        <condition field="${user_exists}" expression="true">
            <action application="sleep" data="3000"/>
            <action application="set" data="record_append=false" inline="true"/>
            <action application="set" data="voicemail_action=save" inline="true"/>
            <action application="set" data="voicemail_id=${destination_number}" inline="true"/>
            <action application="set" data="voicemail_profile=default" inline="true"/>
            <action application="lua" data="app.lua voicemail"/>
        </condition>
    </extension>
    $xml$
WHERE dialplan_uuid = '6577e3d8-31d3-45c2-b530-72f16bd4a10c';

UPDATE v_dialplans
SET dialplan_xml = $xml$
    <extension name="local_extension" continue="true" uuid="1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9">
        <condition field="${user_exists}" expression="true">
            <action application="export" data="dialed_extension=${destination_number}" inline="true"/>
            <action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}" inline="false"/>
        </condition>
        <condition field="${caller_id_number}" expression="^1d{3}$" break="on-true">
            <action application="set" data="hangup_after_bridge=true"/>
            <action application="export" data="inbound-late-negotiation=true"/>
            <action application="export" data="disable-transcoding=true"/>
            <action application="set" data="inherit_codec=true"/>
            <action application="set" data="continue_on_fail=true"/>
            
            <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
            <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
            <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
            <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
            <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="ringback=${us-ring}"/>
            <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
            <action application="export" data="domain_name=${domain_name}"/>
            <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination}"/>
            <action application="lua" data="app.lua failure_handler"/>
        </condition>
        <condition field="" expression="">
            <action application="set" data="hangup_after_bridge=true"/>
            <action application="export" data="inbound-late-negotiation=true"/>
            <action application="export" data="disable-transcoding=true"/>
            <action application="set" data="inherit_codec=true"/>
            <action application="set" data="continue_on_fail=true"/>
            
            <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
            <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
            <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
            <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
            <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="ringback=${us-ring}"/>
            <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
            <action application="export" data="domain_name=${domain_name}"/>
            <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination2}"/>
            <action application="lua" data="app.lua failure_handler"/>
        </condition>
    </extension>
    $xml$
WHERE dialplan_name = 'local_extension';

CREATE TABLE IF NOT EXISTS phone_templates (
    id SERIAL PRIMARY KEY,
    domain_uuid UUID NOT NULL,
    brand VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    template TEXT NOT NULL,
    custom_name VARCHAR(150),
    type VARCHAR(50),
    assigned_username VARCHAR(150),
    custom BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

INSERT INTO v_dialplans (
    dialplan_uuid,
    app_uuid,
    dialplan_context,
    dialplan_name,
    dialplan_continue,
    dialplan_xml,
    dialplan_order,
    dialplan_enabled
)
VALUES (
    'bbd0bcc3-702e-42e5-83d2-6d598063fde6',
    '4e0f3d96-8cb6-4595-87fc-d8517d89fa5f',
    'global',
    'global-bridge-to-agent',
    'true',
    $xml$ 
    <extension name="bridge_to_agent">
        <condition field="destination_number" expression="^bridge_to_agent$">
            <action application="log" data="INFO2 agent_id=${agent_alias}, subdomain=${agent_subdomain}, ${sip_uri_presence}"/>
            <action application="answer"/>
            <action application="set" data="presence_id=${agent_alias}@${agent_subdomain}"/>
            <action application="export" data="presence_id=${agent_alias}@${agent_subdomain}"/>
            <action application="set" data="domain_name=${agent_subdomain}"/>
            <action application="export" data="domain_name=${agent_subdomain}"/>
            <action application="set" data="deviceId=${deviceId}"/>
            <action application="export" data="deviceId=${deviceId}"/>
            <action application="bridge" data="sofia/internal/sip:${sip_uri_presence}"/>
        </condition>
    </extension> 
    $xml$,
    '2',
    'true'
)
ON CONFLICT (dialplan_uuid)
DO UPDATE SET dialplan_xml = EXCLUDED.dialplan_xml;

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    sip_profile_setting_description,
    insert_date,
    insert_user,
    update_date,
    update_user
) VALUES
('81726924-f505-4d2f-8de8-6cd1cb067bf4', '5a740ee3-d43b-474c-b22b-3eb157b91f61', 'disable-transcoding', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:56:26.988599+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3257c92f-2322-4f5c-a9ac-62ba6cafb627', '12f63875-1e90-4881-8da3-f791c0c7024e', 'disable-transcoding', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-10-03 10:23:24.008258+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('4ee08920-ac98-48b7-b187-f6b11923b67a', '638e7e15-fb81-41fb-a998-74fd280b4740', 'disable-transcoding', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-10-03 10:23:24.008258+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('1846b6da-19c5-4ac2-b563-30fd3f737528', '89eca02e-e7bc-4376-a77f-679232d92012', 'disable-transcoding', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-10-03 10:23:24.008258+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4')
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    sip_profile_setting_description,
    insert_date,
    insert_user,
    update_date,
    update_user
) VALUES
('251a0369-f664-47d2-b73d-683fbf418cf6', '89eca02e-e7bc-4376-a77f-679232d92012', 'inbound-late-negotiation', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:55:55.368097+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('e6f3455e-3dce-4890-87af-677e7b73ef45', '5a740ee3-d43b-474c-b22b-3eb157b91f61', 'inbound-late-negotiation', 'true', 'false', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:56:26.988599+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3c65704f-c4e8-40b1-af54-b77a04a08f03', '638e7e15-fb81-41fb-a998-74fd280b4740', 'inbound-late-negotiation', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:56:07.225266+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('bbd5411e-00c1-410c-a46a-74146c398d57', '12f63875-1e90-4881-8da3-f791c0c7024e', 'inbound-late-negotiation', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-10-03 10:23:24.008258+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4')
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    sip_profile_setting_description,
    insert_date,
    insert_user,
    update_date,
    update_user
) VALUES
('3d5911d0-aa79-4596-9c0f-fc79676fed9c', '5a740ee3-d43b-474c-b22b-3eb157b91f61', 'inbound-bypass-media', 'true', 'true', NULL, '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:56:26.988599+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('24d6524b-14f0-4484-9dcd-512e0e2e77ab', '12f63875-1e90-4881-8da3-f791c0c7024e', 'inbound-bypass-media', 'true', 'true', NULL, '2023-08-22 05:31:15.555015+03', NULL, '2023-10-03 10:23:24.008258+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4')
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    sip_profile_setting_description,
    insert_date,
    insert_user,
    update_date,
    update_user
) VALUES
('b9da1193-19ed-468b-a99e-b280964d4a24', '89eca02e-e7bc-4376-a77f-679232d92012', 'inbound-zrtp-passthru', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:55:55.368097+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('9506d96d-5aeb-4995-900c-636e3c030fe4', '638e7e15-fb81-41fb-a998-74fd280b4740', 'inbound-zrtp-passthru', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-08-31 03:56:07.225266+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('dd422ddf-1733-4bbf-9a6b-747ffce13e2d', '12f63875-1e90-4881-8da3-f791c0c7024e', 'inbound-zrtp-passthru', 'true', 'true', '', '2023-08-22 05:31:15.555015+03', NULL, '2023-10-03 10:23:24.008258+03', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4')
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

INSERT INTO v_vars (
    var_uuid,
    var_category,
    var_name,
    var_value,
    var_command,
    var_hostname,
    var_enabled,
    var_order,
    var_description,
    insert_date,
    insert_user,
    update_date,
    update_user
)
VALUES (
    'b9b1898c-50a2-4dce-ba11-bab3a3d0f5ed',
    'Codecs',
    'inbound-codec-negotiation',
    'generous',
    'set',
    NULL,
    'true',
    '0',
    '',
    '2023-08-22 05:31:15.819729+03',
    NULL,
    '2023-12-16 08:24:06.626877+02',
    'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
)
ON CONFLICT (var_uuid)
DO UPDATE SET
    var_value = EXCLUDED.var_value,
    update_date = EXCLUDED.update_date,
    update_user = EXCLUDED.update_user;

INSERT INTO "v_vars" ("var_uuid", "var_category", "var_name", "var_value", "var_command", "var_hostname", "var_enabled", "var_order", "var_description", "insert_date", "insert_user", "update_date", "update_user")
SELECT 'b9b1898c-50a2-4dce-ba11-bab3a3d0f5ed', 'Codecs', 'inbound-codec-negotiation', 'generous', 'set', NULL, 'true', '0', '', '2023-08-22 05:31:15.819729+03', NULL, '2023-12-16 08:24:06.626877+02', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
FROM "v_vars"
WHERE "var_name" LIKE '%codec%' AND (("var_uuid" = 'bd44112c-db18-4446-8d99-35f43a86af40'));

INSERT INTO "v_vars" ("var_uuid", "var_category", "var_name", "var_value", "var_command", "var_hostname", "var_enabled", "var_order", "var_description", "insert_date", "insert_user", "update_date", "update_user")
SELECT '744f380b-ac34-40fb-9f39-8335b6c29848', 'Codecs', 'disable-transcoding', 'true', 'set', NULL, 'true', '0', '', '2023-08-22 13:31:15.819729+11', NULL, '2023-12-16 17:24:06.626877+11', 'b430f241-ec5e-481a-81f5-5d111ac8b474'
FROM "v_vars"
WHERE "var_name" LIKE '%codec%' AND (("var_uuid" = 'b9b1898c-50a2-4dce-ba11-bab3a3d0f5ed'));

INSERT INTO "v_vars" ("var_uuid", "var_category", "var_name", "var_value", "var_command", "var_hostname", "var_enabled", "var_order", "var_description", "insert_date", "insert_user", "update_date", "update_user")
SELECT '124f381b-ac34-41fb-9f39-8335b6c09041', 'Codecs', 'inbound-late-negotiation', 'true', 'set', NULL, 'true', '0', '', '2023-08-22 13:31:15.819729+11', NULL, '2023-12-16 17:24:06.626877+11', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
FROM "v_vars"
WHERE "var_name" LIKE '%codec%' AND (("var_uuid" = 'b9b1898c-50a2-4dce-ba11-bab3a3d0f5ed'));

INSERT INTO "v_vars" ("var_uuid", "var_category", "var_name", "var_value", "var_command", "var_hostname", "var_enabled", "var_order", "var_description", "insert_date", "insert_user", "update_date", "update_user")
SELECT '2f13217b-6907-44bf-876a-f0709098d58e', 'Codecs', 'bypass_media', 'true', 'set', NULL, 'true', '0', '', '2023-08-22 13:31:15.819729+11', NULL, '2023-12-16 17:24:06.626877+11', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
FROM "v_vars"
WHERE "var_name" LIKE '%codec%' AND (("var_uuid" = 'b9b1898c-50a2-4dce-ba11-bab3a3d0f5ed'));

INSERT INTO "v_vars" ("var_uuid", "var_category", "var_name", "var_value", "var_command", "var_hostname", "var_enabled", "var_order", "var_description", "insert_date", "insert_user", "update_date", "update_user")
SELECT 'e02281eb-35d6-4c30-96c6-4b9b5d50e379', 'Codecs', 'inbound-zrtp-passthru', 'true', 'set', NULL, 'true', '0', '', '2023-08-22 13:31:15.819729+11', NULL, '2023-12-16 17:24:06.626877+11', 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
FROM "v_vars"
WHERE "var_name" LIKE '%codec%' AND (("var_uuid" = 'b9b1898c-50a2-4dce-ba11-bab3a3d0f5ed'));

ALTER TABLE "q_dids"
ADD COLUMN IF NOT EXISTS "sip_trunk_name" text NULL;
COMMENT ON TABLE "q_dids" IS '';

UPDATE "v_dialplans" SET
"domain_uuid" = NULL,
"dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9',
"app_uuid" = '71cf1310-b6e3-415b-8745-3cbdc8e15212',
"hostname" = NULL,
"dialplan_context" = 'global',
"dialplan_name" = 'local_extension',
"dialplan_number" = '[ext]',
"dialplan_destination" = NULL,
"dialplan_continue" = 'true',
"dialplan_xml" = '<extension name="local_extension" continue="true" uuid="1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9">
        <condition field="${user_exists}" expression="true">
            <action application="export" data="dialed_extension=${destination_number}" inline="true"/>
            <action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}" inline="false"/>
        </condition>
        <condition field="${caller_id_number}" expression="^1d{3}$" break="on-true">
            <action application="set" data="hangup_after_bridge=true"/>
            <action application="set" data="inherit_codec=true"/>
            <action application="set" data="continue_on_fail=true"/>
            
            <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
            <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
            <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
            <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
            <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="ringback=${us-ring}"/>
            <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
            <action application="export" data="domain_name=${domain_name}"/>
            <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination}"/>
            <action application="lua" data="app.lua failure_handler"/>
        </condition>
        <condition field="" expression="">
            <action application="set" data="hangup_after_bridge=true"/>
            <action application="set" data="inherit_codec=true"/>
            <action application="set" data="continue_on_fail=true"/>
            
            <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
            <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
            <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
            <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
            <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="ringback=${us-ring}"/>
            <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
            <action application="export" data="domain_name=${domain_name}"/>
            <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination2}"/>
            <action application="lua" data="app.lua failure_handler"/>
        </condition>
    </extension>',
"dialplan_order" = '890',
"dialplan_enabled" = 'true',
"dialplan_description" = NULL,
"insert_date" = '2023-08-22 05:31:13.485469+03',
"insert_user" = NULL,
"update_date" = '2023-08-22 05:31:14.620217+03',
"update_user" = NULL
WHERE "dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9';

UPDATE "v_dialplans" SET
    "domain_uuid" = NULL,
    "dialplan_uuid" = '5bdb8f6d-22d0-4239-a13c-fff7cfdf40e1',
    "app_uuid" = 'a08a278f-9327-4c4a-9938-1f53f94ef59c',
    "hostname" = NULL,
    "dialplan_context" = 'global',
    "dialplan_name" = 'export-callid',
    "dialplan_number" = NULL,
    "dialplan_destination" = NULL,
    "dialplan_continue" = 'true',
    "dialplan_xml" = '<extension name="export-callid" continue="true">
            <condition field="destination_number" expression=".*">
                <action application="export" data="orig_sip_call_id=${sip_call_id}"/>
                <action application="export" data="sip_h_X-Original-Call-ID=${sip_call_id}"/>
                <action application="set" data="ignore_early_media=true"/>
                <action application="set" data="instant_ringback=true"/>
            </condition>
        </extension>',
    "dialplan_order" = '1',
    "dialplan_enabled" = 'true',
    "dialplan_description" = NULL,
    "insert_date" = '2023-08-22 13:31:13.485469+11',
    "insert_user" = NULL,
    "update_date" = '2023-08-22 13:31:14.768982+11',
    "update_user" = NULL
WHERE "dialplan_uuid" = '5bdb8f6d-22d0-4239-a13c-fff7cfdf40e1';

UPDATE "v_dialplans" SET
    "dialplan_xml" = 
        '<extension name="local_extension" continue="true" uuid="1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9">
            <condition field="${user_exists}" expression="true">
                <action application="export" data="dialed_extension=${destination_number}" inline="true"/>
                <action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}" inline="false"/>
            </condition>
            <condition field="${caller_id_number}" expression="^[a-f0-9]{20}$" break="on-true">
                <action application="set" data="hangup_after_bridge=true"/>
                <action application="set" data="inherit_codec=true"/>
                <action application="set" data="continue_on_fail=true"/>
                <action application="set" data="continue_on_fail=true"/>
                <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
                <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
                <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
                <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
                <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
                <action application="ring_ready"/>
                <action application="sleep" data="1000"/>
                <action application="set" data="ringback=${us-ring}"/>
                <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
                <action application="export" data="domain_name=${domain_name}"/>
                <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination}"/>
                <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal}"/>
                <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal2}"/>
                <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal3}"/>
                <action application="lua" data="app.lua failure_handler"/>
            </condition>
            <condition field="" expression="">
                <action application="set" data="hangup_after_bridge=true"/>
                <action application="set" data="inherit_codec=true"/>
                <action application="set" data="continue_on_fail=true"/>
                <action application="set" data="continue_on_fail=true"/>
                <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
                <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
                <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
                <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
                <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
                <action application="ring_ready"/>
                <action application="sleep" data="1000"/>
                <action application="set" data="ringback=${us-ring}"/>
                <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
                <action application="export" data="domain_name=${domain_name}"/>
                <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination2}"/>
                <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external}"/>
                <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external2}"/>
                <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external3}"/>
                <action application="lua" data="app.lua failure_handler"/>
            </condition>
        </extension>'
    WHERE "dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9';

UPDATE "v_dialplans" SET
    "dialplan_xml" = 
        '<extension name="user_exists" continue="true" uuid="f87eac8c-a266-43d1-af0c-8139e8a84bee">
            <condition field="${loopback_leg}" expression="^B$" break="never">
                <action application="set" data="domain_name=${context}" inline="true"/>
            </condition>
            <condition field="" expression="">
                <action application="set" data="user_exists=${user_exists id ${destination_number} ${domain_name}}" inline="true"/>
                <action application="set" data="from_user_exists=${user_exists id ${sip_from_user} ${sip_from_host}}" inline="true"/>
            </condition>
            <condition field="${user_exists}" expression="^true$">
                <action application="set" data="extension_uuid=${user_data ${destination_number}@${domain_name} var extension_uuid}" inline="true"/>
                <action application="set" data="extension_caller_id_name=${user_data ${destination_number}@${domain_name} var effective_caller_id_name}" inline="true"/>
                <action application="set" data="extension_caller_id_number=${user_data ${destination_number}@${domain_name} var effective_caller_id_number}" inline="true"/>
                <action application="set" data="forward_all_enabled=${user_data ${destination_number}@${domain_name} var forward_all_enabled}" inline="true"/>
                <action application="set" data="forward_all_destination=${user_data ${destination_number}@${domain_name} var forward_all_destination}" inline="true"/>
                <action application="set" data="forward_busy_enabled=${user_data ${destination_number}@${domain_name} var forward_busy_enabled}" inline="true"/>
                <action application="set" data="forward_busy_destination=${user_data ${destination_number}@${domain_name} var forward_busy_destination}" inline="true"/>
                <action application="set" data="forward_no_answer_enabled=${user_data ${destination_number}@${domain_name} var forward_no_answer_enabled}" inline="true"/>
                <action application="set" data="forward_no_answer_destination=${user_data ${destination_number}@${domain_name} var forward_no_answer_destination}" inline="true"/>
                <action application="set" data="forward_user_not_registered_enabled=${user_data ${destination_number}@${domain_name} var forward_user_not_registered_enabled}" inline="true"/>
                <action application="set" data="forward_user_not_registered_destination=${user_data ${destination_number}@${domain_name} var forward_user_not_registered_destination}" inline="true"/>
                <action application="set" data="forward_all_enabled2=${user_data ${destination_number}@${domain_name} var forward_all_enabled2}" inline="true"/>
                <action application="set" data="forward_all_destination2=${user_data ${destination_number}@${domain_name} var forward_all_destination2}" inline="true"/>
                <action application="set" data="forward_busy_enabled2=${user_data ${destination_number}@${domain_name} var forward_busy_enabled2}" inline="true"/>
                <action application="set" data="forward_busy_destination2=${user_data ${destination_number}@${domain_name} var forward_busy_destination2}" inline="true"/>
                <action application="set" data="forward_no_answer_enabled2=${user_data ${destination_number}@${domain_name} var forward_no_answer_enabled2}" inline="true"/>
                <action application="set" data="forward_no_answer_destination2=${user_data ${destination_number}@${domain_name} var forward_no_answer_destination2}" inline="true"/>
                <action application="set" data="forward_user_not_registered_enabled2=${user_data ${destination_number}@${domain_name} var forward_user_not_registered_enabled2}" inline="true"/>
                <action application="set" data="forward_user_not_registered_destination2=${user_data ${destination_number}@${domain_name} var forward_user_not_registered_destination2}" inline="true"/>
                <action application="set" data="follow_me_uuid=${user_data ${destination_number}@${domain_name} var follow_me_uuid}" inline="true"/>
                <action application="set" data="follow_me_uuid2=${user_data ${destination_number}@${domain_name} var follow_me_uuid2}" inline="true"/>
                <action application="set" data="follow_me_enabled=${user_data ${destination_number}@${domain_name} var follow_me_enabled}" inline="true"/>
                <action application="set" data="follow_me_enabled2=${user_data ${destination_number}@${domain_name} var follow_me_enabled2}" inline="true"/>
                <action application="set" data="do_not_disturb=${user_data ${destination_number}@${domain_name} var do_not_disturb}" inline="true"/>
                <action application="set" data="call_timeout=${user_data ${destination_number}@${domain_name} var call_timeout}" inline="true"/>
                <action application="set" data="call_timeout2=${user_data ${destination_number}@${domain_name} var call_timeout2}" inline="true"/>
                <action application="set" data="missed_call_app=${user_data ${destination_number}@${domain_name} var missed_call_app}" inline="true"/>
                <action application="set" data="missed_call_data=${user_data ${destination_number}@${domain_name} var missed_call_data}" inline="true"/>
                <action application="set" data="toll_allow=${user_data ${destination_number}@${domain_name} var toll_allow}" inline="true"/>
                <action application="set" data="call_screen_enabled=${user_data ${destination_number}@${domain_name} var call_screen_enabled}" inline="true"/>
                <action application="set" data="simultaneous_destination=${user_data ${destination_number}@${domain_name} var simultaneous_destination}" inline="true"/>
                <action application="set" data="simultaneous_destination2=${user_data ${destination_number}@${domain_name} var simultaneous_destination2}" inline="true"/>
                <action application="set" data="follow_internal=${user_data ${destination_number}@${domain_name} var follow_internal}" inline="true"/>
                <action application="set" data="follow_internal2=${user_data ${destination_number}@${domain_name} var follow_internal2}" inline="true"/>
                <action application="set" data="follow_internal3=${user_data ${destination_number}@${domain_name} var follow_internal3}" inline="true"/>
                <action application="set" data="follow_external=${user_data ${destination_number}@${domain_name} var follow_external}" inline="true"/>
                <action application="set" data="follow_external2=${user_data ${destination_number}@${domain_name} var follow_external2}" inline="true"/>
                <action application="set" data="follow_external3=${user_data ${destination_number}@${domain_name} var follow_external3}" inline="true"/>
                <!--<action application="set" data="user_record=${user_data ${destination_number}@${domain_name} var user_record}" inline="true"/>-->
                <!--<action application="set" data="record_path=${recordings_dir}/${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}" inline="true"/>-->
                <!--<action application="set" data="record_name=${uuid}.${record_ext}" inline="true"/>-->
                <!--<action application="set" data="record_append=true" inline="true"/>-->
                <!--<action application="set" data="record_in_progress=true" inline="true"/>-->
                <!--<action application="set" data="recording_follow_transfer=true" inline="true"/>-->
                <!--<action application="record_session" data="${record_path}/${record_name}" inline="false"/>-->
            </condition>
            <condition field="${record_user}" expression="^(All|Inbound)$">
                <action application="set" data="record_path=${recordings_dir}/${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}" inline="true"/>
                <action application="set" data="record_name=${uuid}.${record_ext}" inline="true"/>
                <action application="set" data="record_append=true" inline="true"/>
                <action application="set" data="record_in_progress=true" inline="true"/>
                <action application="set" data="recording_follow_transfer=true" inline="true"/>
                <action application="record_session" data="${record_path}/${record_name}" inline="false"/>
            </condition>
        </extension>'
    WHERE "dialplan_uuid" = 'f87eac8c-a266-43d1-af0c-8139e8a84bee';

ALTER TABLE v_extensions
    ADD COLUMN IF NOT EXISTS parallel_ring  text,
    ADD COLUMN IF NOT EXISTS parallel_ring2 text,
    ADD COLUMN IF NOT EXISTS follow_internal text,
    ADD COLUMN IF NOT EXISTS follow_internal2 text,
    ADD COLUMN IF NOT EXISTS follow_internal3 text,
    ADD COLUMN IF NOT EXISTS parallel_external text,
    ADD COLUMN IF NOT EXISTS parallel_external2 text,
    ADD COLUMN IF NOT EXISTS parallel_external3 text,
    ADD COLUMN IF NOT EXISTS follow_external text,
    ADD COLUMN IF NOT EXISTS follow_external2 text,
    ADD COLUMN IF NOT EXISTS follow_external3 text,
    ADD COLUMN IF NOT EXISTS pickup_group text;

UPDATE "v_dialplans" SET "dialplan_order" = 82, "dialplan_enabled" = 'true' WHERE "dialplan_name" = 'outbound-pin';

UPDATE "v_dialplans" SET "dialplan_order" = 61 WHERE "dialplan_name" = 'extension-to-voicemail';

ALTER TABLE q_company_modes
    ADD COLUMN IF NOT EXISTS permission5 text NULL,
    ADD COLUMN IF NOT EXISTS permission6 text NULL,
    ADD COLUMN IF NOT EXISTS real_network smallint NULL;

ALTER TABLE v_gateways ADD COLUMN IF NOT EXISTS privacy_header int NULL;

UPDATE "v_sip_profiles" SET
"sip_profile_uuid" = '5a740ee3-d43b-474c-b22b-3eb157b91f61',
"sip_profile_name" = 'internal-ipv6',
"sip_profile_hostname" = NULL,
"sip_profile_enabled" = 'false',
"sip_profile_description" = 'The Internal IPV6 profile binds to the IP version 6 address and is similar to the Internal profile.',
"insert_date" = '2023-08-22 13:31:15.555015+11',
"insert_user" = NULL,
"update_date" = '2023-08-31 11:56:26.988599+11',
"update_user" = 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
WHERE "sip_profile_uuid" = '5a740ee3-d43b-474c-b22b-3eb157b91f61';

UPDATE "v_sip_profiles" SET
"sip_profile_uuid" = '5a740ee3-d43b-474c-b22b-3eb157b91f61',
"sip_profile_name" = 'internal-ipv6',
"sip_profile_hostname" = NULL,
"sip_profile_enabled" = 'false',
"sip_profile_description" = 'The Internal IPV6 profile binds to the IP version 6 address and is similar to the Internal profile.',
"insert_date" = '2023-08-22 13:31:15.555015+11',
"insert_user" = NULL,
"update_date" = '2023-08-31 11:56:26.988599+11',
"update_user" = 'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'
WHERE "sip_profile_uuid" = '5a740ee3-d43b-474c-b22b-3eb157b91f61';

INSERT INTO "v_sip_profile_settings" ("sip_profile_setting_uuid", "sip_profile_uuid", "sip_profile_setting_name", "sip_profile_setting_value", "sip_profile_setting_enabled", "sip_profile_setting_description", "insert_date", "insert_user", "update_date", "update_user") VALUES
('318e0c9c-59d9-4d97-8e49-32b34038a0f4',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-transfer',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('ca6ba778-820d-48df-a1a1-9d2af83a31e3',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'dtmf-duration',	'2000',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('85762522-196e-4daf-896a-b390d7f489e9',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'dtmf-type',	'rfc2833',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6e404c0a-7f43-499c-87d7-aa7d03953d76',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'enable-100rel',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('e6635d7c-3be9-4f15-ab1f-e5d274773c27',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'enable-3pcc',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('11e55a02-6f75-4edf-93a0-5683146ffb77',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'enable-compact-headers',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('22dd7cda-4b25-4103-b6d0-49e29f331a28',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'enable-timer',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('a981acc3-29ba-411e-9845-e592093c0a02',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'extended-info-parsing',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('bb91e7e0-a2c9-4f96-94e4-34ea3638e8b2',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'force-register-db-domain',	'$${domain}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6aec4d5f-ec87-42a6-a523-c96a31126ce0',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'auto-rtp-bugs',	NULL,	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('b9363552-bd73-460d-ae14-9fcb76b86616',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'NDLB-broken-auth-hash',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3ef6ef99-b18b-4c67-aefc-1ce4f1c2ec07',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'NDLB-force-rport',	'safe',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('f4e80357-7524-4303-8631-72e0359f420a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'NDLB-received-in-nat-reg-contact',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('ae0b066d-1b0d-4cb7-b239-c5ab8b6a70d1',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'accept-blind-reg',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('16a6a9d5-fe0c-4097-93b3-9922b83ea826',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'aggressive-nat-detection',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('cff187c4-3b2b-4557-a3ec-862c4d44213d',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'alias',	'sip:10.0.1.251:5555',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('499bd9a5-8a08-4638-9c5f-94a20cce9c64',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'apply-nat-acl',	'nat.auto',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5a877b5e-8ba6-463e-9dd4-1c8aaed9d23a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'apply-register-acl',	'providers',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('9c649323-6f38-4f0c-86a1-54f10bca77ce',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'auth-all-packets',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('44596af5-cf5a-411c-a91d-eb584781baec',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'auth-subscriptions',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('89b0f91a-328f-4598-912d-56d25e3bf48a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'auto-jitterbuffer-msec',	'60',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('f531555b-f8d6-458c-8b4a-d27bb6b7d9f3',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'bind-params',	'transport=udp',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d52a150f-a057-427e-accc-5ac7eaec55dc',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'bitpacking',	'aal2',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('dbbbea17-a49b-479e-8ae1-f244ee09a457',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'caller-id-type',	'rpid',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('975472a9-99d4-4f60-b8fd-df589e8ef6ff',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'caller-id-type',	'pid',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('f5a2e00b-0b5e-4f5f-a1ef-e5986c52383b',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'caller-id-type',	'none',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('c0143843-9097-466c-96c7-6a5af020139d',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'challenge-realm',	'auto_to',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('cf0e26ef-af34-4678-91a6-c42781c18eaa',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'cid-in-1xx',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('9b7f9adf-3b39-46b7-88a7-ce594dff802d',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'context',	'public',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('e87628a5-b7e7-4568-831b-65c12a5fc240',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'dbname',	'share_presence',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('08e56ee5-21e2-4756-8158-d61b6a407609',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'debug',	'0',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('156e27b0-a6b6-4845-8351-c30aba06ffca',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'delete-subs-on-register',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('c0b10f00-cfe3-4dc2-9f3b-f7b246e518d8',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'dialplan',	'XML',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('ff61860e-d634-4228-b331-f0688b461f4a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-naptr',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('a2bb53ca-b38f-4e94-b758-77e0d156beb4',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-register',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('9af00b91-c87b-44a8-b2f5-8fc7b13d8915',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-rtp-auto-adjust',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('c6f3c490-b346-4632-aa6a-e065d4f0d313',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-srv',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3f5365f2-4cbe-4f4b-9559-6f1d851732fb',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-srv503',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d6f69ff4-a90a-471e-87f0-181f0d610172',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'force-register-domain',	'$${domain}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('f3022278-341b-44ac-ac1b-0faf086ecc56',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'force-subscription-domain',	'$${domain}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('52741565-2236-4d21-9a99-cf278cd3f605',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'force-subscription-expires',	'60',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('eff60789-a87f-4204-a2a4-9350d3a3e36d',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'forward-unsolicited-mwi-notify',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('0d975009-9337-4035-8eed-757a61cef47a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'apply-inbound-acl',	'providers',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('bb5c2993-7164-4c6f-aac3-5b6d90681809',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'ext-rtp-ip',	'159.223.51.73',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('71dee559-1d5c-4ce1-8fb6-94a26f5229a4',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'auth-calls',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6b3aade0-6306-46ff-898f-843283602c7f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'disable-transcoding',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d4ce1a36-a854-47f1-bed0-b40c01e821e6',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'hold-music',	'$${hold_music}',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('1fe312b6-38bb-4c22-84b7-26a16ee8aad8',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-proxy-media',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6115bdb5-e247-42bf-808b-7594af632183',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-reg-force-matching-username',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('a9d25288-fa2e-428d-824d-f700d053d621',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-reg-in-new-thread',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('329c9fab-41c2-4c1e-a6aa-4ecf49048963',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-use-callid-as-uuid',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3a9a3d2c-ce90-4e50-81d3-35028085cee2',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'liberal-dtmf',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6ac03c0f-0add-460a-ae58-4b44d3a72296',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'local-network-acl',	'localnet.auto',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('f2a32d08-7b0f-46a6-bf44-fba4559e7a36',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'log-auth-failures',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('a72e1e87-a970-4eca-b90d-a89650129c2c',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'manage-presence',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3174dda9-d98b-4af3-b441-f41571b3a8af',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'manage-shared-appearance',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('9f75e1a0-b724-48f7-be76-c4ae5dc91112',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'manual-redirect',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5b01a206-3893-4171-af47-cf5813857cd1',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'max-proceeding',	'1000',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('0ec6adec-9f1e-494c-8731-58e4612f8640',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'max-recv-requests-per-second',	'0',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('153767fd-0b29-4f27-abe7-7bb3a36893a9',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'media-option',	'resume-media-on-hold',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('7b6a2122-b3b3-46ab-a5b7-6a6455bd2411',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'media-option',	'bypass-media-after-att-xfer',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('7f7ba473-1d2c-4222-8a52-051d826a7a9d',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'media_hold_timeout',	'1800',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('b5adf116-aab2-4729-b08b-b48beb287096',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'media_timeout',	'300',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d96401cf-4110-4b50-9260-bd57097eb512',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'minimum-session-expires',	'0',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('50cc4150-7f62-4510-9f8a-9af1a0978e82',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'multiple-registrations',	'contact',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('1686db1f-f951-4b5d-9c4b-43ee3425e1ee',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'nat-options-ping',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('fec94373-0292-48af-a4a6-829d2aa56ef1',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'nonce-ttl',	'60',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6363d3b5-a103-40eb-bd57-dfe8b0b77f35',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'odbc-dsn',	'$${dsn}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6ffd10ac-11bc-4ba9-be2a-e4b31fe95f59',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'outbound-use-uuid-as-callid',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('2d7f87b9-e5f1-4e9f-8692-5524f47fb0ff',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'pass-callee-id',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6c6ef129-c2a7-4672-80e8-db2511dc62af',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'pass-rfc2833',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5a86bcb4-7e19-4e68-b1c6-3e2af2f5d20f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'presence-hosts',	'$${domain},$${local_ip_v4}',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('2a86d878-c286-4cdc-961d-51c8a87efe42',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'presence-privacy',	'$${presence_privacy}',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5dc9020b-5cd1-455b-bbd2-53d8d72ab318',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'presence-probe-on-register',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('28e92bd0-06e1-430a-b30c-0dca694d3fe0',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'presence-proto-lookup',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('0d032aa8-8ef4-4dfd-921d-0bb7908d05e0',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'record-path',	'$${recordings_dir}',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('ba3b7fb3-9987-4591-ac67-43f0a55be206',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'record-template',	'${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}/${uuid}.${record_ext}',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('a464eea6-7956-4544-b667-ab04e7694a04',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'registration-thread-frequency',	'30',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('09930d51-497c-409c-8350-6bfb22f2dd7e',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'renegotiate-codec-on-hold',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('cf59834c-d329-43c1-accf-2a6a6db7522f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rfc2833-pt',	'101',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('4085f81a-393f-4d64-a9c4-f0ffc38a59f3',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtcp-audio-interval-msec',	'5000',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('29403493-eb58-4cd4-a3d3-86df67127616',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtcp-video-interval-msec',	'5000',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('c37c4e31-fb52-446c-9fac-bc1bc689f718',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-autofix-timing',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d62b5816-2a70-4242-bc77-8855d133bd3a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-autoflush-during-bridge',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('807ef8a0-541c-4e9a-ad54-1c262b0a5792',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-digit-delay',	'40',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('e6e8ca31-001c-489f-b5e5-82f3e9274a8f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-hold-timeout-sec',	'1800',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('bf16632d-4ec0-4e2c-9fb3-6f871cc87677',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-rewrite-timestamps',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('68c98fa7-ce1a-43f8-bf5d-276d3324535e',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-timeout-sec',	'300',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3b1e4f65-684f-424a-b281-06834c73b240',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-timer-name',	'soft',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('02647028-d42f-4601-b19d-32176b5f173b',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'send-message-query-on-register',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3123e662-2089-405b-8cae-b943e8d21a8c',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'send-presence-on-register',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('62a79e47-d6cb-45e6-a882-80c7c26c768c',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'session-timeout',	'1800',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('f8fe8b90-ef7a-44da-aa62-30d1d80b7fe4',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'shutdown-on-fail',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6ebe74da-79d7-419e-8bb0-edc348f96b00',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-capture',	'no',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5c4918da-d504-40f2-9faa-4b806ccfa4da',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-expires-max-deviation',	'600',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('e8d40fe9-1a27-481f-b976-3b86ebf4f907',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-force-expires',	'1800',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('48fa00a9-941b-4640-b6e1-4984ad71b87a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-port',	'5060',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('c331dfeb-3c8b-41fb-8b12-71c7747acc89',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-subscription-max-deviation',	'120',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('000d7cfb-7063-4b11-8e69-f31389bb738f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-trace',	'no',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('15e955f2-e6bd-4c8d-8143-f179823f567f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'suppress-cng',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('7f4444e0-59f4-480b-881e-b8cf5215b5ca',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-zrtp-passthru',	'false',	'true',	'',	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('bd00344a-f22a-43d9-b520-23737eee5d99',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'timer-T1',	'500',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('c745829a-fea7-4c55-aee8-e7a20f8446f1',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'timer-T1X64',	'32000',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('32838517-e2ba-44bd-8ab3-5a841090a6cf',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'timer-T2',	'4000',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('fb96a7a5-4b9f-45d6-bf5d-8078376cb69f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'track-calls',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d93cf7ee-e631-4f6c-b357-355d10f91eee',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'unregister-on-options-fail',	'true',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('fe28e86e-e06e-49fc-bba0-fbb2a18cc6c1',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'user-agent-string',	'RingQ',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('8818f5ea-70dc-48f3-8586-fe1b6a19cfb2',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'vad',	'out',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('e60f1ec3-f840-4ad6-8722-6963b71bc5df',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'watchdog-enabled',	'no',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('8ed8241a-b3a8-4e17-838b-40e2dd58c097',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'watchdog-event-timeout',	'30000',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3aea7580-75ae-48cc-934b-4e4a140ee844',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'watchdog-step-timeout',	'30000',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('54657531-e450-45db-b237-020fd0ce7a57',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'ws-binding',	':5066',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('54327c1c-7cdb-44d9-980f-33d1e06ae791',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'wss-binding',	':7443',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('4109c4f7-cbf3-4753-ae13-b962cab78710',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-late-negotiation',	'false',	'true',	'',	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('fadbeb8b-0ead-46fe-ac63-713798dbc5c2',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'rtp-ip',	'159.223.51.73',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('43fa44f6-c57d-43b3-84b0-b4843ec6abf5',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-codec-negotiation',	'greedy',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('41bc30ee-2e48-41b5-90db-ceae4ad867d3',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'outbound-codec-prefs',	'PCMA,PCMU,iLBC@30i,G722@16000h,G729,opus@16000h@40i',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('2a3847b9-14ee-4b8b-82d7-c6784783c205',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'ext-sip-ip',	'159.223.51.73',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('6a8c5ea7-6c0f-4acd-aadd-38b600d793c3',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-bypass-media',	'false',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3eccf866-daf9-4166-8371-b669df9490ab',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'accept-blind-auth',	'true',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3ec66af6-21a9-4ea2-b700-0ebfa09da2ac',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'inbound-codec-prefs',	'PCMA,PCMU,iLBC@30i,G722@16000h,G729,opus@16000h@40i',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('73bc7e51-32ee-4478-b92a-2629044d661f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'bind-params',	'transport=udp',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('45f995db-c808-47be-988c-6c5bd1a56d2e',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'sip-ip',	'0.0.0.0',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5d8173ac-5bfe-491e-972c-4c28258de40b',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-bind-params',	'transport=tls',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('07ed9bba-103e-4a7f-b224-71454458638c',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-cert-dir',	'$${internal_ssl_dir}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('a0e5fae4-5c09-43b3-9f5f-b28afe09dc2a',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-ciphers',	'$${sip_tls_ciphers}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('3c05abc1-66a8-447d-8b4d-26d639ef0de0',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-only',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('45900ff9-36ef-4ef2-bf47-0af1d2e98230',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-orq-connect-timeout',	'3000',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('991f72ec-ca1c-47a8-ae88-1cc6121e4513',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-passphrase',	NULL,	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('d66bff39-fb6b-4fce-9612-fd558f586917',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-sip-port',	'5061',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('5602ad07-7077-4b67-aba7-576e8238587f',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-verify-date',	'false',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('992ddf9f-59e0-45b2-ada8-2082237cb326',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-verify-depth',	'2',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('1c59c6a6-fd8f-42a6-8622-4af55ae8f70b',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-verify-in-subjects',	NULL,	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('54f603e0-13d6-4a81-99a4-0760a69864c3',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-verify-policy',	'none',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('ab15c651-2172-4496-af67-23bedc6d957e',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls-version',	'$${sip_tls_version}',	'false',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4'),
('07ef21cf-dd79-4a1d-bc85-0c44d23d4185',	'bfb3226f-ee87-4e83-8033-a7a3a798904c',	'tls',	'$${internal_ssl_enable}',	'true',	NULL,	'2023-08-22 13:31:15.555015+11',	NULL,	'2023-10-03 18:23:24.008258+11',	'ff522dc9-9cdc-46c2-8e6e-aae22573d6d4')
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

UPDATE "v_sip_profile_settings" 
SET "sip_profile_setting_value" = 'rfc1918.auto'
WHERE "sip_profile_setting_name" = 'apply-nat-acl';

UPDATE "v_sip_profile_settings"
SET "sip_profile_setting_value" = 'true'
WHERE "sip_profile_setting_name" = 'tls';

UPDATE v_sip_profile_settings s
SET sip_profile_setting_value = v.value,
    sip_profile_setting_enabled = 'true',
    update_date = now()
FROM v_sip_profiles p,
     (VALUES ('apply-inbound-acl', 'providers'),
             ('auth-calls-acl-only', 'false'),
             ('auth-calls', 'true')) AS v(name, value)
WHERE p.sip_profile_uuid = s.sip_profile_uuid
  AND p.sip_profile_name = 'internal'
  AND s.sip_profile_setting_name = v.name;

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    insert_date
)
SELECT gen_random_uuid(), p.sip_profile_uuid, v.name, v.value, 'true', now()
FROM v_sip_profiles p,
     (VALUES ('apply-inbound-acl', 'providers'),
             ('auth-calls-acl-only', 'false'),
             ('auth-calls', 'true')) AS v(name, value)
WHERE p.sip_profile_name = 'internal'
  AND NOT EXISTS (
        SELECT 1 FROM v_sip_profile_settings s
        WHERE s.sip_profile_uuid = p.sip_profile_uuid
          AND s.sip_profile_setting_name = v.name
      );

CREATE OR REPLACE FUNCTION normalize_bool_fields()
RETURNS TRIGGER AS $$
BEGIN
NEW.forward_all_enabled := CASE 
    WHEN NEW.forward_all_enabled IN ('t','1','yes','on') THEN 'true'
    WHEN NEW.forward_all_enabled IN ('f','0','no','off') THEN 'false'
    ELSE NEW.forward_all_enabled
END;
NEW.forward_all_enabled2 := CASE 
    WHEN NEW.forward_all_enabled2 IN ('t','1','yes','on') THEN 'true'
    WHEN NEW.forward_all_enabled2 IN ('f','0','no','off') THEN 'false'
    ELSE NEW.forward_all_enabled2
END;
RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_normalize_bool_fields ON v_extensions;

CREATE TRIGGER trg_normalize_bool_fields
BEFORE INSERT OR UPDATE ON v_extensions
FOR EACH ROW EXECUTE FUNCTION normalize_bool_fields();

ALTER TABLE v_extensions
ALTER COLUMN forward_all_enabled TYPE TEXT
    USING CASE WHEN forward_all_enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN forward_busy_enabled TYPE TEXT
    USING CASE WHEN forward_busy_enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN forward_no_answer_enabled TYPE TEXT
    USING CASE WHEN forward_no_answer_enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN forward_user_not_registered_enabled TYPE TEXT
    USING CASE WHEN forward_user_not_registered_enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN do_not_disturb TYPE TEXT
    USING CASE WHEN do_not_disturb::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN call_screen_enabled TYPE TEXT
    USING CASE WHEN call_screen_enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN directory_visible TYPE TEXT
    USING CASE WHEN directory_visible::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN directory_exten_visible TYPE TEXT
    USING CASE WHEN directory_exten_visible::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN follow_me_enabled TYPE TEXT
    USING CASE WHEN follow_me_enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN enabled TYPE TEXT
    USING CASE WHEN enabled::boolean THEN 'true' ELSE 'false' END,
ALTER COLUMN force_ping TYPE TEXT
    USING CASE WHEN force_ping::boolean THEN 'true' ELSE 'false' END;

ALTER TABLE "q_dids" ADD COLUMN IF NOT EXISTS "country" text NULL; COMMENT ON TABLE "q_dids" IS '';

ALTER TABLE "v_dialplans" ADD COLUMN IF NOT EXISTS "exceeded2" character(1);

UPDATE "v_dialplans" SET
"domain_uuid" = NULL,
"dialplan_uuid" = '31a22b7c-88f1-49f7-bd27-e20f8b732bed',
"app_uuid" = '4d9826ce-6d88-419b-b686-98ca49f215ed',
"hostname" = NULL,
"dialplan_context" = 'global',
"dialplan_name" = 'limit_exceeded2',
"dialplan_number" = 'limit_exceeded2$|transfer:limit_exceeded2',
"dialplan_destination" = NULL,
"dialplan_continue" = 'false',
"dialplan_xml" = '<extension name="limit_exceeded2">
    <condition field="destination_number" expression="^limit_exceeded2$|transfer:limit_exceeded2">
        <action application="answer"/>
        <action application="sleep" data="500"/>
        <action application="playback" data="misc/The_number_you_are_trying_to_r.wav"/>
        <action application="hangup" data="USER_BUSY"/>
    </condition>
    </extension>',
"dialplan_order" = '889',
"dialplan_enabled" = 'false',
"dialplan_description" = NULL,
"insert_date" = '2023-08-22 13:31:13.485469+11',
"insert_user" = NULL,
"update_date" = '2023-08-22 13:31:13.814396+11',
"update_user" = NULL,
"exceeded2" = NULL
WHERE "dialplan_uuid" = '31a22b7c-88f1-49f7-bd27-e20f8b732bed';

UPDATE "v_dialplans" SET
"domain_uuid" = NULL,
"dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9',
"app_uuid" = '71cf1310-b6e3-415b-8745-3cbdc8e15212',
"hostname" = NULL,
"dialplan_context" = 'global',
"dialplan_name" = 'local_extension',
"dialplan_number" = '[ext]',
"dialplan_destination" = NULL,
"dialplan_continue" = 'true',
"dialplan_xml" = '<extension name="local_extension" continue="true" uuid="1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9">
        <condition field="${user_exists}" expression="true">
            <action application="export" data="dialed_extension=${destination_number}" inline="true"/>
            <action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}" inline="false"/>
        </condition>
        <condition field="${caller_id_number}" expression="^[a-f0-9]{20}$" break="on-true">
            <action application="set" data="ringback=${us-ring}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="hangup_after_bridge=true"/>
            <action application="set" data="inherit_codec=true"/>
            <action application="set" data="continue_on_fail=true"/>
            <action application="set" data="continue_on_fail=true"/>
            <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
            <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
            <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
            <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
            <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
            <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
            <action application="export" data="domain_name=${domain_name}"/>
            <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination}"/>
            <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal}"/>
            <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal2}"/>
            <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal3}"/>
            <action application="lua" data="app.lua failure_handler"/>
        </condition>
        <condition field="" expression="">
            <action application="set" data="ringback=${us-ring}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="hangup_after_bridge=true"/>
            <action application="set" data="inherit_codec=true"/>
            <action application="set" data="continue_on_fail=true"/>
            <action application="set" data="continue_on_fail=true"/>
            <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
            <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
            <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
            <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
            <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
            <action application="ring_ready"/>
            <action application="sleep" data="1000"/>
            <action application="set" data="ringback=${us-ring}"/>
            <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
            <action application="export" data="domain_name=${domain_name}"/>
            <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination2}"/>
            <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external}"/>
            <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external2}"/>
            <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external3}"/>
            <action application="lua" data="app.lua failure_handler"/>
        </condition>
    </extension>',
"dialplan_order" = '890',
"dialplan_enabled" = 'true',
"dialplan_description" = NULL,
"insert_date" = '2023-08-22 13:31:13.485469+11',
"insert_user" = NULL,
"update_date" = '2023-08-22 13:31:14.620217+11',
"update_user" = NULL,
"exceeded2" = NULL
WHERE "dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9';

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    sip_profile_setting_description,
    insert_date,
    insert_user,
    update_date, 
    update_user
) VALUES
('f0e8a5d2-9f31-4b6c-a04e-c1b7e8f2a911', '12f63875-1e90-4881-8da3-f791c0c7024e', 'all-reg-options-ping', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL),
('2a91d6c4-0e7f-4d83-b215-c9f4a08d7b62', '89eca02e-e7bc-4376-a77f-679232d92012', 'all-reg-options-ping', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL),
('8b5e2f10-7c4a-4d19-9f08-3a6b1e9c5d7e', '638e7e15-fb81-41fb-a998-74fd280b4740', 'all-reg-options-ping', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL),
('4c7d9e83-6a1b-4f25-8e34-b87a2d5c0f93', '5a740ee3-d43b-474c-b22b-3eb157b91f61', 'all-reg-options-ping', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL)
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

INSERT INTO v_sip_profile_settings (
    sip_profile_setting_uuid,
    sip_profile_uuid,
    sip_profile_setting_name,
    sip_profile_setting_value,
    sip_profile_setting_enabled,
    sip_profile_setting_description,
    insert_date,
    insert_user,
    update_date,
    update_user
) VALUES
('5e2a8f17-3b9c-4d61-a075-c4e8b1f9d206', '12f63875-1e90-4881-8da3-f791c0c7024e', 'unregister-on-options-fail', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL),
('9d0c4a72-1e6b-4f83-8c15-7a3e9b5d2f48', '89eca02e-e7bc-4376-a77f-679232d92012', 'unregister-on-options-fail', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL),
('3f6b8c14-d502-49a7-b8e3-1c79a6e0d24f', '638e7e15-fb81-41fb-a998-74fd280b4740', 'unregister-on-options-fail', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL),
('6a4e1b9d-c378-4f52-bd06-89e1c7a3f15b', '5a740ee3-d43b-474c-b22b-3eb157b91f61', 'unregister-on-options-fail', 'true', 'true', '', '2026-05-11 06:30:00+00', NULL, '2026-05-11 06:30:00+00', NULL)
ON CONFLICT (sip_profile_setting_uuid) DO NOTHING;

ALTER TABLE q_contacts ADD COLUMN IF NOT EXISTS contacttype VARCHAR(50);
ALTER TABLE q_contacts ADD COLUMN IF NOT EXISTS createdby VARCHAR(100);
ALTER TABLE q_contacts ADD COLUMN IF NOT EXISTS createdate TIMESTAMP;

ALTER TABLE "v_gateways"
    ADD COLUMN IF NOT EXISTS "custom_user" text NULL,
    ADD COLUMN IF NOT EXISTS "custom_display" text NULL,
    ADD COLUMN IF NOT EXISTS "custom_match" text NULL;
    COMMENT ON TABLE "v_gateways" IS '';

ALTER TABLE "q_outbound_rules"
    ADD COLUMN IF NOT EXISTS "custom_user" text NULL,
    ADD COLUMN IF NOT EXISTS "custom_display" text NULL,
    ADD COLUMN IF NOT EXISTS "custom_host" text NULL,
    ADD COLUMN IF NOT EXISTS "custom_cid" text NULL;

COMMENT ON TABLE "q_outbound_rules" IS '';

ALTER TABLE v_gateways ADD COLUMN IF NOT EXISTS real_network smallint NULL;

CREATE TABLE IF NOT EXISTS public.sip_security_blocks (
    id BIGSERIAL PRIMARY KEY,
    ip_address INET NOT NULL,
    category VARCHAR(32) NOT NULL,
    reason TEXT NOT NULL,
    failure_count INTEGER NOT NULL DEFAULT 0,
    first_seen_at TIMESTAMPTZ NOT NULL,
    last_seen_at TIMESTAMPTZ NOT NULL,
    blocked_at TIMESTAMPTZ NOT NULL,
    unblocked_at TIMESTAMPTZ,
    status VARCHAR(16) NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'local',
    jail_name VARCHAR(64),
    metadata JSONB,
    CONSTRAINT sip_security_blocks_ip_category_uniq UNIQUE (ip_address, category)
);
CREATE INDEX IF NOT EXISTS idx_sip_blocks_status ON public.sip_security_blocks(status) WHERE status = 'blocked';
CREATE INDEX IF NOT EXISTS idx_sip_blocks_category ON public.sip_security_blocks(category);
CREATE INDEX IF NOT EXISTS idx_sip_blocks_last_seen ON public.sip_security_blocks(last_seen_at);

CREATE TABLE IF NOT EXISTS public.fully_allowed_ips (
    id BIGSERIAL PRIMARY KEY,
    ip_address INET NOT NULL UNIQUE,
    description TEXT,
    added_by VARCHAR(64),
    added_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS public.security_audit_log (
    id BIGSERIAL PRIMARY KEY,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    actor VARCHAR(64),
    action VARCHAR(32) NOT NULL,
    ip_address INET NOT NULL,
    category VARCHAR(32),
    reason TEXT,
    metadata JSONB
);
CREATE INDEX IF NOT EXISTS idx_audit_ip ON public.security_audit_log(ip_address);
CREATE INDEX IF NOT EXISTS idx_audit_time ON public.security_audit_log(occurred_at);
SELECT * FROM "v_sip_profile_settings" WHERE "sip_profile_setting_name" LIKE 'user-agent-string' LIMIT 50;

CREATE TABLE IF NOT EXISTS public.q_storage_retention (
    id serial PRIMARY KEY,
    domain_uuid uuid NOT NULL,
    category text NOT NULL,
    scope text DEFAULT 'tenant' NOT NULL,
    enabled integer DEFAULT 0 NOT NULL,
    retention_days integer DEFAULT 90 NOT NULL,
    last_run_at timestamptz,
    last_freed_bytes bigint DEFAULT 0,
    last_deleted_files integer      DEFAULT 0,
    update_date timestamptz,
    update_user uuid,
    insert_date timestamptz,
    insert_user uuid,
    CONSTRAINT q_storage_retention_uniq UNIQUE (domain_uuid, category)
);

ALTER TABLE q_storage_retention ADD COLUMN IF NOT EXISTS archive integer DEFAULT 0 NOT NULL;

CREATE TABLE IF NOT EXISTS public.tunnel_config (
    id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    tunnel_name character varying(255) NOT NULL,
    auth_key text NOT NULL,
    status smallint NOT NULL DEFAULT 0,
    created_at timestamp NOT NULL DEFAULT now(),
    updated_at timestamp NULL,
    device_id character varying(255) NULL,
    device_public_ip character varying(45) NULL,
    device_local_ip character varying(45) NULL,
    last_seen timestamp NULL
);

DO $$
    DECLARE
        expected record;
        actual record;
    BEGIN
        FOR expected IN
            SELECT * FROM (VALUES
                ('tunnel_name',      'character varying(255)', 'character varying',           255,  ''),
                ('auth_key',         'text',                   'text',                        NULL, ''),
                ('status',           'smallint',               'smallint',                    NULL, 'NOT NULL DEFAULT 0'),
                ('created_at',       'timestamp',              'timestamp without time zone', NULL, 'NOT NULL DEFAULT now()'),
                ('updated_at',       'timestamp',              'timestamp without time zone', NULL, ''),
                ('device_id',        'character varying(255)', 'character varying',           255,  ''),
                ('device_public_ip', 'character varying(45)',  'character varying',           45,   ''),
                ('device_local_ip',  'character varying(45)',  'character varying',           45,   ''),
                ('last_seen',        'timestamp',              'timestamp without time zone', NULL, '')
            ) AS t(col_name, col_type, data_type, max_length, add_extra)
        LOOP
            SELECT c.data_type, c.character_maximum_length INTO actual
            FROM information_schema.columns c
            WHERE c.table_schema = 'public'
                AND c.table_name = 'tunnel_config'
                AND c.column_name = expected.col_name;

            IF NOT FOUND THEN
                EXECUTE format('ALTER TABLE public.tunnel_config ADD COLUMN %I %s %s',
                    expected.col_name, expected.col_type, expected.add_extra);
            ELSIF actual.data_type <> expected.data_type
                OR actual.character_maximum_length IS DISTINCT FROM expected.max_length THEN
                EXECUTE format('ALTER TABLE public.tunnel_config ALTER COLUMN %I TYPE %s USING %I::%s',
                    expected.col_name, expected.col_type, expected.col_name, expected.col_type);
            END IF;
        END LOOP;

        EXECUTE 'ALTER TABLE public.tunnel_config ALTER COLUMN status SET DEFAULT 0';
        EXECUTE 'ALTER TABLE public.tunnel_config ALTER COLUMN created_at SET DEFAULT now()';

        IF NOT EXISTS (
            SELECT 1 FROM pg_attribute
            WHERE attrelid = 'public.tunnel_config'::regclass
                AND attname = 'id'
                AND attidentity IN ('d', 'a')
        ) THEN
            EXECUTE 'ALTER TABLE public.tunnel_config ALTER COLUMN id DROP DEFAULT';
            EXECUTE 'ALTER TABLE public.tunnel_config ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY';
            PERFORM setval(
                pg_get_serial_sequence('public.tunnel_config', 'id'),
                COALESCE((SELECT MAX(id) FROM public.tunnel_config), 0) + 1,
                false
            );
        END IF;
    END $$;

DO $$
    BEGIN
        IF NOT EXISTS (
            SELECT 1 FROM pg_constraint
            WHERE conrelid = 'public.tunnel_config'::regclass
                AND conname = 'tunnel_config_tunnel_name_unique'
        ) THEN
            ALTER TABLE public.tunnel_config
                ADD CONSTRAINT tunnel_config_tunnel_name_unique UNIQUE (tunnel_name);
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM pg_constraint
            WHERE conrelid = 'public.tunnel_config'::regclass
                AND conname = 'tunnel_config_auth_key_unique'
        ) THEN
            ALTER TABLE public.tunnel_config
                ADD CONSTRAINT tunnel_config_auth_key_unique UNIQUE (auth_key);
        END IF;
    END $$;

CREATE INDEX IF NOT EXISTS idx_tunnel_config_auth_key ON public.tunnel_config (auth_key);
CREATE INDEX IF NOT EXISTS idx_tunnel_config_device_id ON public.tunnel_config (device_id);
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS offline_reason text NULL;
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS deleted_at timestamp NULL;
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS phones_registered integer DEFAULT 0;
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS rtp_sessions integer DEFAULT 0;
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS uptime_seconds integer DEFAULT 0;
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS version text NULL;
ALTER TABLE public.tunnel_config ADD COLUMN IF NOT EXISTS router_phone boolean NOT NULL DEFAULT false;

CREATE TABLE IF NOT EXISTS public.audit_logs (
    id BIGSERIAL PRIMARY KEY,
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    domain_uuid VARCHAR(64),
    user_uuid VARCHAR(64),
    username VARCHAR(255),
    user_fullname VARCHAR(255),
    user_email VARCHAR(255),
    extension VARCHAR(32),
    ip_address VARCHAR(45),
    user_agent TEXT,
    feature VARCHAR(64) NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity VARCHAR(255),
    result VARCHAR(16) NOT NULL DEFAULT 'success',
    metadata JSONB
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_occurred ON public.audit_logs (occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_domain ON public.audit_logs (domain_uuid);
CREATE INDEX IF NOT EXISTS idx_audit_logs_feature ON public.audit_logs (feature);
CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON public.audit_logs (user_uuid);

CREATE TABLE IF NOT EXISTS public.audit_settings (
    domain_uuid VARCHAR(64) PRIMARY KEY,
    max_logs INTEGER NOT NULL DEFAULT 10000,
    update_date TIMESTAMPTZ,
    update_user VARCHAR(64)
);

UPDATE "v_dialplans" SET
"domain_uuid" = NULL,
"dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9',
"app_uuid" = '71cf1310-b6e3-415b-8745-3cbdc8e15212',
"hostname" = NULL,
"dialplan_context" = 'global',
"dialplan_name" = 'local_extension',
"dialplan_number" = '[ext]',
"dialplan_destination" = NULL,
"dialplan_continue" = 'true',
"dialplan_xml" = '<extension name="local_extension" continue="true" uuid="1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9">
    <condition field="${user_exists}" expression="true">
        <action application="export" data="dialed_extension=${destination_number}" inline="true"/>
        <action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}" inline="false"/>
    </condition>
    <condition field="${caller_id_number}" expression="^[a-f0-9]{20}$" break="on-true">
        <action application="set" data="ringback=${us-ring}"/>
        <action application="ring_ready"/>
        <action application="sleep" data="1000"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="inherit_codec=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
        <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
        <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
        <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
        <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
        <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
        <action application="export" data="domain_name=${domain_name}"/>
        <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal2}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal3}"/>
        <action application="answer"/>
        <!-- <action application="lua" data="app.lua failure_handler"/> -->
    </condition>
    <condition field="" expression="">
        <action application="set" data="ringback=${us-ring}"/>
        <action application="ring_ready"/>
        <action application="sleep" data="1000"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="inherit_codec=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
        <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
        <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
        <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
        <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
        <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
        <action application="export" data="domain_name=${domain_name}"/>
        <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination2}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external2}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external3}"/>
        <action application="answer"/>
        <!-- <action application="lua" data="app.lua failure_handler"/> -->
    </condition>
</extension>',
"dialplan_order" = '890',
"dialplan_enabled" = 'true',
"dialplan_description" = NULL,
"insert_date" = '2023-08-22 13:31:13.485469+11',
"insert_user" = NULL,
"update_date" = '2023-08-22 13:31:14.620217+11',
"update_user" = NULL,
"exceeded2" = NULL
WHERE "dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9';

-- eFax Quiries

DROP TABLE IF EXISTS "fax_domain_settings";
CREATE TABLE "public"."fax_domain_settings" (
    "domain_uuid" uuid NOT NULL,
    "fax_ident" text,
    "fax_header" text,
    "updated_at" double precision NOT NULL,
    CONSTRAINT "fax_domain_settings_pkey" PRIMARY KEY ("domain_uuid")
) WITH (oids = false);

INSERT INTO "fax_domain_settings" ("domain_uuid", "fax_ident", "fax_header", "updated_at") VALUES
('c7319502-4c16-48de-bf40-78346863cada',	'sgringq100',	'sgringq100',	1787159005.564439);

DROP TABLE IF EXISTS "fax_jobs";
CREATE TABLE "public"."fax_jobs" (
    "id" uuid DEFAULT gen_random_uuid() NOT NULL,
    "domain_uuid" uuid NOT NULL,
    "direction" text NOT NULL,
    "from_number" text,
    "to_number" text,
    "pages" integer DEFAULT '0',
    "status" text NOT NULL,
    "file_path" text,
    "call_uuid" text,
    "esl_job_uuid" text,
    "error_reason" text,
    "created_at" double precision NOT NULL,
    "updated_at" double precision NOT NULL,
    CONSTRAINT "fax_jobs_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

CREATE INDEX "idx_fax_jobs_call_uuid" ON "public"."fax_jobs" USING btree ("call_uuid");

CREATE INDEX "idx_fax_jobs_direction_status" ON "public"."fax_jobs" USING btree ("direction", "status");

CREATE INDEX "idx_fax_jobs_domain" ON "public"."fax_jobs" USING btree ("domain_uuid");

CREATE INDEX "idx_fax_jobs_domain_uuid" ON "public"."fax_jobs" USING btree ("domain_uuid");

CREATE INDEX "idx_fax_jobs_esl_job_uuid" ON "public"."fax_jobs" USING btree ("esl_job_uuid");

CREATE INDEX "idx_fax_jobs_status" ON "public"."fax_jobs" USING btree ("direction", "status");

INSERT INTO "fax_jobs" ("id", "domain_uuid", "direction", "from_number", "to_number", "pages", "status", "file_path", "call_uuid", "esl_job_uuid", "error_reason", "created_at", "updated_at") VALUES
('c14ef995-5f27-47e6-abbc-fcb89426b319',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	1,	'received',	'/var/spool/fax/inbox/c14ef995-5f27-47e6-abbc-fcb89426b319.pdf',	'53bdbab7-270a-4c84-a0b2-855768d32d05',	NULL,	NULL,	1787115010.1102915,	1787115039.366804),
('c45bc1ff-f9a2-44e5-a0f8-5daf377bc66a',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/c45bc1ff-f9a2-44e5-a0f8-5daf377bc66a.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787052421.720016,	1787053731.0062094),
('136a7709-7126-458e-9eeb-c316c0413361',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/136a7709-7126-458e-9eeb-c316c0413361.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787048076.963533,	1787053731.0312977),
('6bcef354-125a-4c2e-bf5d-d9994fd70945',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/6bcef354-125a-4c2e-bf5d-d9994fd70945.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787048811.668489,	1787053731.054056),
('4bb20199-15ad-4b32-8a2c-ebde83b3e7f2',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/4bb20199-15ad-4b32-8a2c-ebde83b3e7f2.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787044772.372654,	1787044782.475852),
('a6f52b88-e948-44e1-b3d4-8c431540dd9b',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/a6f52b88-e948-44e1-b3d4-8c431540dd9b.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787049789.644301,	1787053731.0745966),
('e32492b6-d29b-4c07-8a3d-b6a57653085d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/e32492b6-d29b-4c07-8a3d-b6a57653085d.tiff',	'cd671aa8-0d03-481c-a225-13f6a009a667',	'493a5e38-a5a7-42d4-8256-5b67e4ce76e0',	'The call dropped prematurely',	1787115939.3954515,	1787115941.3904312),
('a9236769-8f84-489f-8ba0-5a2a2b2d1117',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/a9236769-8f84-489f-8ba0-5a2a2b2d1117.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787050353.048053,	1787053731.0926483),
('9adce639-2cf4-4dc4-8305-6c2ebdbb18a3',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/9adce639-2cf4-4dc4-8305-6c2ebdbb18a3.tiff',	'c8ac6adc-a720-4c94-9dbe-0e6606be87c1',	'80c8d9b8-fcea-41fc-8e57-f62b5e36a961',	'Timed out waiting for the first message',	1787158918.648716,	1787158963.840704),
('7f0777b0-376f-444f-a5a0-bf7999ac0bb4',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/7f0777b0-376f-444f-a5a0-bf7999ac0bb4.tiff',	'23887f75-ca85-420c-b1bd-85caa93244dd',	'2d7e484e-16f6-42d9-81ba-9c91f6763b6e',	NULL,	1787116141.651959,	1787116185.2954469),
('2b9c41d5-9c88-4ca4-8ca7-3b355bd06f07',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'68809876',	1,	'sent',	'/var/spool/fax/outbound_tiff/2b9c41d5-9c88-4ca4-8ca7-3b355bd06f07.tiff',	'a5884bce-7b31-4bb0-a702-677bae711c32',	'c83aec55-16b7-4f35-8033-3a9cfdb2b021',	NULL,	1787117040.34388,	1787117088.9600563),
('679c70ee-ff38-4b1f-b334-3b4d0f639602',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13466844460',	1,	'sent',	'/var/spool/fax/outbound_tiff/679c70ee-ff38-4b1f-b334-3b4d0f639602.tiff',	'7f1caf71-1c2f-475f-a552-4645a6fde541',	'059d47dc-967e-4533-8992-f44d5df16c25',	NULL,	1787162023.901176,	1787162056.7267442),
('29b5d7c8-4c85-464d-8050-0e9bc435d9e0',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/29b5d7c8-4c85-464d-8050-0e9bc435d9e0.tiff',	NULL,	'88974b93-a1ea-47b7-a97b-b31469da6b7c',	'Timed out waiting for FreeSWITCH fax result',	1787048923.056891,	1787049129.77557),
('b848f978-b736-4437-ad26-c5234704ce95',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/b848f978-b736-4437-ad26-c5234704ce95.tiff',	'e8821348-f064-4f96-a190-e91c6b2e2a62',	'2cb44e40-f83d-437f-b6dc-cfa37de1487d',	'',	1787058370.2157407,	1787058411.7574043),
('fce19e78-860f-4dcc-a49e-ebfb7642812b',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/fce19e78-860f-4dcc-a49e-ebfb7642812b.tiff',	'e329816f-7417-4e85-9600-741a28d63c45',	'403db9c3-2d4d-462a-991d-ae07d72d0d96',	'The call dropped prematurely',	1787136799.4703898,	1787136804.6902742),
('894d6065-9c7c-4e97-8d8c-b61317d0f95e',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/026b7e94-3a2c-4b7d-8403-2f4d3f071523.tiff',	'026b7e94-3a2c-4b7d-8403-2f4d3f071523',	NULL,	'Timed out waiting for the first message',	1787136846.5308332,	1787136885.9547763),
('fafd97a6-7d04-47b6-a608-ff0f2232d80c',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'916380186380',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/fafd97a6-7d04-47b6-a608-ff0f2232d80c.tiff',	'70a7b0d9-d586-4fc6-a7e5-af674484ae61',	'a8285519-dd05-4ce4-9f0e-801a125db352',	'',	1787105678.382742,	1787105679.876889),
('01494575-0cd7-4f7d-8af3-2ad57521165a',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401003',	'63401023',	0,	'received',	'/var/spool/fax/outbound_tiff/01494575-0cd7-4f7d-8af3-2ad57521165a.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787044511.954212,	1787044522.589503),
('bce616fe-099a-48ec-b3f0-e02d362cc699',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/bce616fe-099a-48ec-b3f0-e02d362cc699.tiff',	'b8cf60f8-4d45-4e34-a311-58d5e40f25cd',	'1f9fb0ec-9791-448f-acd9-7aa6ce2b6a75',	'The call dropped prematurely',	1786597455.8180273,	1786597484.2713985),
('4e8eea5d-1d89-43bb-8598-1429c4c621b1',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/4e8eea5d-1d89-43bb-8598-1429c4c621b1.tiff',	'88a4090f-0886-4e59-a04b-ec3bc7a759dd',	'50573d1c-6b77-4106-95cc-da6d078c3618',	'The call dropped prematurely',	1787137097.2183187,	1787137137.5327015),
('d220ff3c-6c87-4606-ba9b-e2fadca2a222',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+13462090806',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/cf505ddc-2f55-4072-ac27-4f5f705eafe2.tiff',	'cf505ddc-2f55-4072-ac27-4f5f705eafe2',	NULL,	'The call dropped prematurely',	1787137250.8233356,	1787137253.2749772),
('500afdca-73d8-46c9-afd2-50e85639db98',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401003',	'63401007',	1,	'received',	'/var/spool/fax/inbox/0945765e-7635-4c6e-88ff-497162a082bd.pdf',	'7caef1ef-4653-4c5c-8ec1-b4c6a9d0fe95',	'f41ef64c-568f-494d-9122-fd5ad543845b',	NULL,	1787056676.135839,	1787056728.7197237),
('b0a40b23-81eb-46e5-b2cd-d08e52a45bea',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/b0a40b23-81eb-46e5-b2cd-d08e52a45bea.tiff',	'2cae3b40-1dac-42ac-b227-1e3a545690a9',	'af11f1ac-b7ac-4c02-ab34-c39b69d8adc5',	'Timed out waiting for the first message',	1787137497.0269876,	1787137540.0431085),
('266eff26-0239-49b2-a1ba-213b20acd51f',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/266eff26-0239-49b2-a1ba-213b20acd51f.tiff',	'38d872e7-13d0-4498-ab2a-50e149a615cf',	'75394b73-13f9-4519-b482-8cd8b119fd64',	'The call dropped prematurely',	1787117871.527365,	1787138131.0785851),
('d98f53f3-8921-47db-9438-3ce73f339c24',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/d98f53f3-8921-47db-9438-3ce73f339c24.tiff',	'79ec22e8-7f3c-41b9-a219-8eca50847035',	'10227fb5-9b6e-4ddb-865b-fe1fbb4f98d5',	'Timed out waiting for the first message',	1787138443.4418194,	1787138484.0049238),
('364f453b-0637-4e1a-a465-caf3cfb9e649',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/548899b2-1a16-4578-9a1c-ad8ffee80914.tiff',	'548899b2-1a16-4578-9a1c-ad8ffee80914',	NULL,	'The call dropped prematurely',	1787115237.5875216,	1787115237.65295),
('f058c728-01d5-40ee-9e3d-a79b9ba90bf6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/f058c728-01d5-40ee-9e3d-a79b9ba90bf6.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787044611.387942,	1787044621.490418),
('ede409e3-bc4d-4c8c-85da-c5cb4a5a85f6',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+13462090806',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/b0a488d0-05a9-42fa-ba1a-b7e8b10be008.tiff',	'b0a488d0-05a9-42fa-ba1a-b7e8b10be008',	NULL,	'The call dropped prematurely',	1787115940.6604857,	1787115940.7175226),
('a8ad8748-1c44-46ba-b929-87754b13296a',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/a8ad8748-1c44-46ba-b929-87754b13296a.tiff',	NULL,	'a59484d9-0349-4283-87e5-b2665ee98c39',	'Timed out waiting for FreeSWITCH fax result',	1787052515.65593,	1787052732.386115),
('e445ed63-27eb-4b34-b93b-011b8a6294e3',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/e445ed63-27eb-4b34-b93b-011b8a6294e3.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787044910.283719,	1787053731.119257),
('6fdaa6f3-c9e7-4d11-a82d-cf79dc5d6ba2',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/6fdaa6f3-c9e7-4d11-a82d-cf79dc5d6ba2.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787048826.109684,	1787053731.1407816),
('8ad7d113-0534-4fa7-9f07-6ca1a2017755',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/8ad7d113-0534-4fa7-9f07-6ca1a2017755.tiff',	NULL,	NULL,	'Class ''DB'' not found',	1787048446.284366,	1787048446.398273),
('0e791092-b3df-4d69-8d37-28a5eed060cf',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/0e791092-b3df-4d69-8d37-28a5eed060cf.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787049835.240171,	1787053731.1593895),
('0c7da99d-f35e-4fa1-b777-e924c4f4bbe0',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/0c7da99d-f35e-4fa1-b777-e924c4f4bbe0.tiff',	'f4462f82-795d-49a5-9ff8-60e669991f13',	'a20efcaa-58b6-4203-b571-ce54ef08f09f',	'Timed out waiting for the first message',	1787160143.293508,	1787160185.6110034),
('708a604a-3a0d-40d4-b151-0e2171b9567d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/708a604a-3a0d-40d4-b151-0e2171b9567d.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787051094.786438,	1787053731.1777437),
('3909f25c-4807-4c12-abbe-50af69eb2e44',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/3909f25c-4807-4c12-abbe-50af69eb2e44.tiff',	'f58de144-cecf-46c5-a169-18fc89752274',	'694939a3-3ca1-40ef-b1de-225cf49d4602',	'',	1787116216.8976715,	1787116247.1167033),
('038133ea-da80-46c4-b217-c6a71565e5d7',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/038133ea-da80-46c4-b217-c6a71565e5d7.tiff',	NULL,	'02264f04-ae9d-48c6-9d88-2980b7e3ff0f',	'Timed out waiting for FreeSWITCH fax result',	1787049018.559739,	1787049224.900205),
('b150407c-6e30-47f1-a41b-1b2023dfebee',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	1,	'sent',	'/var/spool/fax/outbound_tiff/b150407c-6e30-47f1-a41b-1b2023dfebee.tiff',	'3a719530-0432-4688-9765-bd9dff37a784',	'9a74abbd-ee65-428f-a1cf-29c631e619f6',	NULL,	1787117283.7021859,	1787117310.0503643),
('c0a9309b-55ae-449d-8dde-b4517336ebb0',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/c0a9309b-55ae-449d-8dde-b4517336ebb0.tiff',	'd3b311ed-aa52-4b55-bbc6-227453ffc694',	'86ada065-fd40-46b2-83d9-3b803d601b11',	NULL,	1787056771.497559,	1787056818.6359115),
('569f518d-b8ef-43ce-911f-7d18cb34bf5d',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+13462090806',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/f45ac616-b854-4d4e-8bff-3cf5bf7b6adc.tiff',	'f45ac616-b854-4d4e-8bff-3cf5bf7b6adc',	NULL,	'Timed out waiting for the first message',	1787117873.653103,	1787118472.3929553),
('12e044ec-2bc1-414c-a718-7b4649672c2f',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+13462090806',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/d2ba74d8-8036-4c10-8a9e-6f2ede0094bc.tiff',	'd2ba74d8-8036-4c10-8a9e-6f2ede0094bc',	NULL,	'The call dropped prematurely',	1787136801.906067,	1787136804.4102864),
('ce030e44-a053-408b-b704-5d8091b3f905',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401023',	1,	'sent',	'/var/spool/fax/outbound_tiff/ce030e44-a053-408b-b704-5d8091b3f905.tiff',	'd1d97199-59ea-4614-9e13-6f51847e24ac',	'1f5e4aa5-0f39-4db5-9a80-53231e84a8d3',	NULL,	1787058443.6119018,	1787058482.4407556),
('ce0146b4-ad1e-4813-8881-c18d997c1a54',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/ce0146b4-ad1e-4813-8881-c18d997c1a54.tiff',	'7ddc74ab-51d8-4ecf-9317-aba09a53c42a',	'98de6ff2-0e2c-4d07-b51f-2b56c4a674cc',	'The call dropped prematurely',	1787137074.6323526,	1787137079.760761),
('de6591a7-ef79-4e1f-ba97-f708d2b81370',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/3ce812ba-27a8-40ff-b13f-b0dfd4d28629.tiff',	'3ce812ba-27a8-40ff-b13f-b0dfd4d28629',	NULL,	'Timed out waiting for the first message',	1787137098.0587418,	1787137137.4702652),
('8f2d86c4-dc01-4934-a0a4-a6c0b59ee112',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/8f2d86c4-dc01-4934-a0a4-a6c0b59ee112.tiff',	'713d2a33-e4e4-4e7b-b9be-c4cfd4af68ee',	'4a9c1f48-9b1e-4dd7-8ab7-d923fbc174ec',	'',	1787113921.498565,	1787113953.5198798),
('eb866091-76ee-4df8-937b-6c529c2b81d6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'68809876',	1,	'sent',	'/var/spool/fax/outbound_tiff/eb866091-76ee-4df8-937b-6c529c2b81d6.tiff',	'd54548eb-3e32-47fe-a298-696e7baa1ddd',	'7f3c4d41-def1-4772-ab47-c8b107e6004f',	NULL,	1787137688.357376,	1787137727.9464889),
('74255ac8-9ebb-4278-8e65-4bcbd0534ed0',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/74255ac8-9ebb-4278-8e65-4bcbd0534ed0.tiff',	'a5f62e3b-c643-47c1-aa11-e6167f2a52d5',	'37f13b29-717c-46ef-9100-5a0cd8ddfbdb',	'The call dropped prematurely',	1787137326.6406314,	1787137331.6460216),
('b8a80b2c-d8a3-44d1-9291-3619efdb8e6e',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13466844460',	1,	'sent',	'/var/spool/fax/outbound_tiff/b8a80b2c-d8a3-44d1-9291-3619efdb8e6e.tiff',	'949ae2fb-a7d1-4ef5-806d-84c85fc134b4',	'458b59b9-9150-4eb4-9100-279312015b30',	NULL,	1787139541.2834263,	1787139567.8235743),
('589226a0-41a7-49b1-8d15-2df44ca2240d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/589226a0-41a7-49b1-8d15-2df44ca2240d.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787044969.281957,	1787053731.1955078),
('560c3646-8dd2-43bb-9bce-020fcf019c35',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/560c3646-8dd2-43bb-9bce-020fcf019c35.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787048832.836284,	1787053731.2136257),
('5f003097-8544-4571-b2d1-dd29c14dd449',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/5f003097-8544-4571-b2d1-dd29c14dd449.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787049858.372685,	1787053731.2416248),
('9948fb83-3ab8-45cc-90f8-01d0ba76e46a',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/9948fb83-3ab8-45cc-90f8-01d0ba76e46a.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787044626.613583,	1787044636.716138),
('aeeecb08-70fd-43f0-aca9-7a054cb7a9cc',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/d2f66b3d-c3dc-4bb0-93cd-9106e482882e.tiff',	'd2f66b3d-c3dc-4bb0-93cd-9106e482882e',	NULL,	'The call dropped prematurely',	1787115318.9445856,	1787115319.0078828),
('9bbb4c02-1d63-4a11-ba6c-828dde863e5f',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/9bbb4c02-1d63-4a11-ba6c-828dde863e5f.tiff',	'233529e7-857b-4629-8c91-5eca72f0c662',	'07bb6e00-33f0-47d2-8f69-b929502bbfcb',	'Timed out waiting for initial communication',	1787118105.0993729,	1787118705.373003),
('5d16689a-51d0-43f9-a926-f3c867a20844',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/5d16689a-51d0-43f9-a926-f3c867a20844.tiff',	NULL,	NULL,	'Class ''Illuminate\Support\Facades\DB'' not found',	1787048468.080839,	1787048468.180312),
('687e5701-a006-4a64-9249-68a85876c1f2',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/687e5701-a006-4a64-9249-68a85876c1f2.tiff',	'f7adf7ae-880c-43ad-8e23-64742c3f5b5c',	'7ca64621-36e5-439b-9c60-97bd8f6ae080',	NULL,	1787054599.4402964,	1787054640.957234),
('87f55f28-9b45-4115-805b-9309470536df',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/87f55f28-9b45-4115-805b-9309470536df.tiff',	'118cc206-0712-42cd-9c27-4364cd125e74',	'd00550b3-f9a9-4fd6-b58b-1607f83943f3',	'The call dropped prematurely',	1787116011.2558167,	1787116051.7536578),
('8c2fa8fd-f330-40d4-8efe-bffaed1bdaed',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/8c2fa8fd-f330-40d4-8efe-bffaed1bdaed.tiff',	'8cde997f-d490-428f-8741-135d08b14710',	'6b2e0a40-6886-4aad-b7e9-f41100e93fc1',	'Timed out waiting for the first message',	1787160143.303056,	1787160186.048505),
('fcab0d01-2c74-4e96-b187-b7951342a05d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'68809823',	1,	'sent',	'/var/spool/fax/outbound_tiff/fcab0d01-2c74-4e96-b187-b7951342a05d.tiff',	'7a7bc7a7-c004-4bb8-b67e-0b934e30d351',	'1bca6748-ab21-46fe-a398-dd20d9d50a6d',	NULL,	1787116768.26116,	1787116828.297225),
('537eb370-36a5-47b0-a52c-e9a851911014',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/537eb370-36a5-47b0-a52c-e9a851911014.tiff',	NULL,	'6a1f04a1-5982-4cb6-8130-4d8ea56931ab',	'Timed out waiting for FreeSWITCH fax result',	1787049466.308827,	1787049670.396028),
('03541305-289c-48f1-bd83-4486df171f1d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/03541305-289c-48f1-bd83-4486df171f1d.tiff',	'13cd79f4-bdb8-44cd-90da-e1d84ffaf5ef',	'f9a29226-8eb1-4e55-bfca-38e0a9ebf17b',	'',	1787057091.2736282,	1787057132.2429574),
('11526eeb-c44b-4909-abee-77468f1dc452',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/11526eeb-c44b-4909-abee-77468f1dc452.tiff',	NULL,	'6afa8fd2-e5b7-4ee8-9ee5-69dce66bbe3f',	'-ERR INVALID_GATEWAY',	1787117405.9770446,	1787117406.6596894),
('05718070-2f5f-4c42-95a6-4f245ea78b30',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/05718070-2f5f-4c42-95a6-4f245ea78b30.tiff',	'54b9c17e-c160-457d-b934-92ca3e5cee40',	'76a70209-240e-4ee7-a234-de1d47295214',	NULL,	1787058647.381284,	1787058694.8694644),
('98be70b1-a7d8-47e3-ad34-700c1f325131',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/98be70b1-a7d8-47e3-ad34-700c1f325131.tiff',	'd0bd769e-464b-404b-90cb-1bbd0c308c7a',	'2fdc87b3-2466-412d-9b4e-63c72f60d885',	'',	1787114348.579624,	1787114379.7048082),
('e0b717cb-47ee-463d-8272-0cd62ab2ffd5',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/e0b717cb-47ee-463d-8272-0cd62ab2ffd5.tiff',	'89422631-a804-4b09-bb71-c19ad027bdee',	'7039b64c-deee-4706-ac8e-724bc2da1a4b',	'The call dropped prematurely',	1787136845.4041998,	1787136886.064324),
('eeef4aa1-578d-4f2f-a5c6-a7a7809bd180',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+13462023679',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/78216b9c-0678-481d-b593-b644acf0e471.tiff',	'78216b9c-0678-481d-b593-b644acf0e471',	NULL,	'The call dropped prematurely',	1787137077.2157247,	1787137079.3988845),
('3eadf75a-3086-47fd-8f45-767d666a46a3',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/3eadf75a-3086-47fd-8f45-767d666a46a3.tiff',	'17d91829-c6ee-4a6b-ae90-c193e103a1a3',	'a46e225f-43ac-40a7-b4e0-e80f219027e2',	'The call dropped prematurely',	1787137249.1789582,	1787137253.6758497),
('df2c7518-b941-4801-82fa-5dd4a832a41d',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+13462090806',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/9d575d60-4032-40fd-8853-90cebe1c9fa1.tiff',	'9d575d60-4032-40fd-8853-90cebe1c9fa1',	NULL,	'The call dropped prematurely',	1787137329.2598097,	1787137331.2340395),
('98bf17ee-bc7b-4627-825e-a9a62038a74d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/98bf17ee-bc7b-4627-825e-a9a62038a74d.tiff',	'e64e07ab-2ab0-458f-bedc-bf9e926ea69b',	'10054148-1ce8-4fbd-99ae-188d5e426c3d',	'Timed out waiting for the first message',	1787138224.6287227,	1787138271.80267),
('3bef5255-1db6-4484-9f04-d72d097894b9',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/3bef5255-1db6-4484-9f04-d72d097894b9.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787048859.115306,	1787053731.267869),
('dbdff8ce-54bc-4acf-9b13-2efa8b39d65d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/dbdff8ce-54bc-4acf-9b13-2efa8b39d65d.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787049686.858709,	1787053731.2907999),
('138a4ac9-0bac-41d5-9534-122eadb26c61',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/138a4ac9-0bac-41d5-9534-122eadb26c61.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787044676.340571,	1787044686.441131),
('fae02351-e51c-4846-afa9-a4bbbb844e21',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/fae02351-e51c-4846-afa9-a4bbbb844e21.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787049992.096085,	1787053731.309878),
('663fd52b-62b0-4f41-bebc-3f34b00f7681',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13466844460',	1,	'sent',	'/var/spool/fax/outbound_tiff/663fd52b-62b0-4f41-bebc-3f34b00f7681.tiff',	'1a93649d-cb9b-427e-a40d-284a8fbf8666',	'dc4a29ba-27c6-4300-9f4f-6d599ebfe955',	NULL,	1787115799.6143675,	1787115825.200796),
('e3ecf9c6-142b-457a-a4e7-77021acf6d77',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/e3ecf9c6-142b-457a-a4e7-77021acf6d77.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787047823.524126,	1787047833.814886),
('b5c7557a-111a-48fc-b38e-8b79bd152643',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/b5c7557a-111a-48fc-b38e-8b79bd152643.tiff',	'934b1886-68db-4ff7-9ac5-5157dfe0d357',	'5744b69a-2f82-4e0e-89eb-0b5443b5119c',	'',	1787056334.672766,	1787056377.0829072),
('5776d0f3-311b-4711-b738-d4635ddab31a',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/e9069c4f-28e4-42cf-b7b2-946d05753954.tiff',	'e9069c4f-28e4-42cf-b7b2-946d05753954',	NULL,	'The call dropped prematurely',	1787116012.1432712,	1787116012.265549),
('d245f925-1062-4a4b-9d66-d203000019ca',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/d245f925-1062-4a4b-9d66-d203000019ca.tiff',	NULL,	'e55a60d3-6bd5-44b3-b859-77afd46f42b4',	'Timed out waiting for FreeSWITCH fax result',	1787048527.001871,	1787048732.370913),
('1c4eccff-3451-4a4e-83c6-04e34f3ce344',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/1c4eccff-3451-4a4e-83c6-04e34f3ce344.tiff',	'0cace214-3d9e-4ca2-9579-44779b2ae578',	'71e91ae4-93fc-42c6-806a-5a60cd7b41bc',	'The call dropped prematurely',	1786615386.9395185,	1786615408.5863454),
('5c5b14f7-8679-4d4e-8f33-52ba69b1b516',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/5c5b14f7-8679-4d4e-8f33-52ba69b1b516.tiff',	NULL,	'4dfa3f9a-8867-4691-a13c-df86d360a1e5',	'-ERR INVALID_GATEWAY',	1786444918.9747477,	1786444920.8481007),
('8f76c840-b640-4afc-b907-7690dc9b843b',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	1,	'sent',	'/var/spool/fax/outbound_tiff/8f76c840-b640-4afc-b907-7690dc9b843b.tiff',	'84cf26cd-8f27-4f35-a1fc-27870a27ffd3',	'13de5152-2327-4a81-8238-8af5ef4b9952',	NULL,	1786598328.2286587,	1786598350.9849136),
('6efab580-9729-4d4d-a7d4-35088c3223db',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/6efab580-9729-4d4d-a7d4-35088c3223db.tiff',	'7cbc4e43-f04e-43ea-9704-07775fec8aa6',	'58e7aa95-a97b-426a-865c-9acfd36de48c',	'',	1786535893.0629466,	1786535954.0955026),
('6d4727bc-e7ee-40c8-96fe-769b50c23c9e',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/6d4727bc-e7ee-40c8-96fe-769b50c23c9e.tiff',	'4a96fdec-0857-49e1-ba19-9a90846ac99d',	'7e5a5d76-ea27-4f76-898b-993fd448bae0',	'The call dropped prematurely',	1786445418.4185557,	1786445459.6546574),
('506eedb1-7cdb-4b10-977e-847fcccb24a3',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	1,	'sent',	'/var/spool/fax/outbound_tiff/506eedb1-7cdb-4b10-977e-847fcccb24a3.tiff',	'befff01a-f872-49c8-adb6-ec7d9010ad72',	'6c3ede93-29b1-4062-a4b7-30f9782fc106',	NULL,	1786616430.83732,	1786616450.411173),
('54f45a94-04c6-4710-8a6d-f6306fa7ceb6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/54f45a94-04c6-4710-8a6d-f6306fa7ceb6.tiff',	'39fbd31c-2c9a-4b87-a514-eafc9cc61e99',	'3a496a0b-e186-40b7-bf3e-95e889018b63',	'The call dropped prematurely',	1786446407.865957,	1786446447.5387404),
('2249cc09-581d-4d56-8f02-edc2aab2efcf',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/2249cc09-581d-4d56-8f02-edc2aab2efcf.tiff',	'd05982f9-a8af-417a-a629-9485dbc7c6af',	'c96465e0-84af-4d61-a1e9-9bf4fc55e191',	NULL,	1786451003.2244697,	1786451045.0542939),
('77731730-5531-4476-90b6-8174b2f53b00',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	1,	'sent',	'/var/spool/fax/outbound_tiff/77731730-5531-4476-90b6-8174b2f53b00.tiff',	'90f0d3ea-c9ba-450e-9862-93245d57503a',	'5b586f6d-abe9-43a4-a607-9e85a47096b6',	NULL,	1786615510.4765906,	1786615543.3294115),
('9f3425dc-adbf-48c5-9512-3a0071429a96',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/9f3425dc-adbf-48c5-9512-3a0071429a96.tiff',	'b955e12d-0f6f-46d9-8cb9-fc7cd292718b',	'5e3f2570-28a3-40b9-9785-4e89f18483b8',	'The call dropped prematurely',	1786447379.9320831,	1786447418.853301),
('f94b3fa0-e7bb-4b07-9737-4cd16354088e',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	1,	'sent',	'/var/spool/fax/outbound_tiff/f94b3fa0-e7bb-4b07-9737-4cd16354088e.tiff',	'1d4a2767-26be-45e6-b01a-3e9ea64e2fa2',	'a0916319-830e-40f7-be32-64b7a33a00d7',	NULL,	1786616043.6092024,	1786616080.760915),
('a5d43f54-d283-4a67-bfe7-fff5ba97b7e2',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/a5d43f54-d283-4a67-bfe7-fff5ba97b7e2.tiff',	'c489a033-7630-49ce-8e1c-308798edabbb',	'1d2f92d5-c383-42c6-b59a-8b41e58223a9',	'The call dropped prematurely',	1786449427.430058,	1786449468.2657247),
('85db7de6-3934-42ea-8a6c-5a645818d5d6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/85db7de6-3934-42ea-8a6c-5a645818d5d6.tiff',	'56cd156f-ea1d-4d81-b84d-946d29b6baf5',	'9449befd-e2b4-45c5-ac6d-c31115fdf312',	NULL,	1786450696.3009405,	1786450741.5815957),
('35c6c872-385a-4f85-b043-6bb9f63e392d',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/35c6c872-385a-4f85-b043-6bb9f63e392d.tiff',	'823c8de5-0e99-4153-9b80-f29d54722744',	'7cef87ea-be92-499f-9577-8749b83c4da9',	'The call dropped prematurely',	1786514497.5391743,	1786514534.59833),
('6c1d8fa3-96d1-4ba3-bee2-3ee2e30476f6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/6c1d8fa3-96d1-4ba3-bee2-3ee2e30476f6.tiff',	'1110e22f-44e7-4d57-b5df-ce347b847cf0',	'2273e46c-6165-4ff6-87b9-6a6ff7fcd1c0',	'',	1786617785.9262605,	1786617788.1939774),
('1bdcb300-a8ab-49fa-9196-9bc41663ac79',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/1bdcb300-a8ab-49fa-9196-9bc41663ac79.tiff',	'4a548293-e505-42d1-83df-6878660c1420',	'806c887d-5c10-4b54-be78-38f82b159771',	'Timed out waiting for initial communication',	1786516854.009901,	1786516920.8794675),
('2e7b5174-0ce2-42d1-87ba-d3324e6bc918',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/8889aae6-0e8c-450f-8a56-2ff3c2a9ce47.tiff',	'8889aae6-0e8c-450f-8a56-2ff3c2a9ce47',	NULL,	'Timed out waiting for the first message',	1786700301.392363,	1786700340.8558788),
('91ab0d86-3343-46fa-b548-6c569bbb2f22',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/91ab0d86-3343-46fa-b548-6c569bbb2f22.tiff',	'0a805ce7-7488-48e1-a6ef-285bea5570d8',	'ce4692c7-6b36-4271-874f-360210d9a197',	NULL,	1787105216.808097,	1787105261.7107484),
('e5880386-9957-40e2-9fa7-0f1258eb6455',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401003',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/e5880386-9957-40e2-9fa7-0f1258eb6455.tiff',	'f47b5e0e-0327-4df9-8f27-0126d1b45a19',	'd1bdce93-d14b-417e-ad77-1bbd00c52d33',	'',	1787058243.3554761,	1787058285.6621945),
('3e7a045f-82a0-4adf-b965-75f30038a022',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/c354d94b-54ad-44ab-bc51-9c233829ce90.tiff',	'c354d94b-54ad-44ab-bc51-9c233829ce90',	NULL,	'The call dropped prematurely',	1787114793.6854935,	1787114793.7708685),
('ae940231-3227-496a-9a8f-4c4b0fbde116',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/ae940231-3227-496a-9a8f-4c4b0fbde116.tiff',	'eace10da-a6e0-4ab4-9927-254fd3841945',	'1d2969af-8755-4e19-b007-461a1fda55e8',	'',	1786617817.4974074,	1786617819.4556901),
('fa7c3698-027b-404e-a992-a97f5ba243f6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	0,	'failed',	'/var/spool/fax/outbound_tiff/fa7c3698-027b-404e-a992-a97f5ba243f6.tiff',	'ff8433e9-67c9-4996-a73e-4eb4c8f2c1ff',	'2e9388a1-5bca-48cb-892a-0d6374975042',	'',	1786536657.1092386,	1786536718.5504673),
('a663e96d-ee85-46f4-80c8-fc4168e877ff',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/a663e96d-ee85-46f4-80c8-fc4168e877ff.tiff',	'17f78c29-a699-4bc5-994e-bb50cb1f2654',	'47591b14-c895-4b04-a67f-5ec943efbed5',	NULL,	1786517139.9693286,	1786517190.9406018),
('06c887c8-3e4c-4aa5-81ad-378420618d76',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/06c887c8-3e4c-4aa5-81ad-378420618d76.tiff',	'3637208e-f27d-4a53-a00b-3d0a755251f9',	'f7f56498-fdcd-4282-9e60-2a48a610ebc7',	'',	1786617930.4430325,	1786617963.7463038),
('b9102eb0-6778-4a89-9e07-abdc5cc210db',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/b9102eb0-6778-4a89-9e07-abdc5cc210db.tiff',	NULL,	'031b1a11-89d5-436f-9307-f3d701ac15af',	'-ERR GATEWAY_DOWN',	1786619059.801446,	1786619060.2913146),
('e8fd4d4a-2f14-46aa-bef7-e4024b3a69a6',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/e8fd4d4a-2f14-46aa-bef7-e4024b3a69a6.tiff',	'a1b54cdb-a856-4516-accb-3273fbc17a1e',	'af62ce5b-f04a-4d7c-963a-fe3ec763396a',	'',	1786535987.7875514,	1786536051.0872068),
('c098e401-9882-45ed-b171-1d32571217db',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/c098e401-9882-45ed-b171-1d32571217db.tiff',	NULL,	'e50fbea3-6e77-4771-9ee5-3f4e8ccc16df',	'-ERR GATEWAY_DOWN',	1786619091.5076022,	1786619093.0156288),
('31881f77-7cd6-4b9c-94e6-63ded50b10a5',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/31881f77-7cd6-4b9c-94e6-63ded50b10a5.tiff',	'2c94b5d8-1cb9-4988-aadf-d20a3db8967d',	'f3475fed-e862-4df4-bc46-633956fb4b25',	'',	1786536558.9798346,	1786536622.1052222),
('b1526f46-c427-4fcd-bcbb-80bfb8f60f4b',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/b1526f46-c427-4fcd-bcbb-80bfb8f60f4b.tiff',	'3fec60a0-0aac-4de8-a5e6-8776acc46998',	'edd10be0-e932-4b67-aad6-c32dd03047f2',	'Timed out waiting for initial communication',	1786619844.4475598,	1786619910.019713),
('7a76f900-eea8-403e-90c4-af264ba73845',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	1,	'sent',	'/var/spool/fax/outbound_tiff/7a76f900-eea8-403e-90c4-af264ba73845.tiff',	'20f19c5f-0b12-481c-9e83-2710cf8ac63d',	'4da65ac8-55b2-4a05-a80a-aa3c141ccc0a',	NULL,	1786595632.6057804,	1786595674.8084874),
('06247e73-c83e-4f75-ba6f-13f9b5645250',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/7b8bca5a-9295-4447-8c60-bc1de5776a9e.tiff',	'7b8bca5a-9295-4447-8c60-bc1de5776a9e',	NULL,	'Timed out waiting for the first message',	1786684871.646753,	1786684910.9891062),
('e9c55579-6332-47c3-bec3-9df789b9e85c',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/e9c55579-6332-47c3-bec3-9df789b9e85c.pdf',	'0151a378-8654-4800-8849-cd38e61ceefd',	NULL,	NULL,	1786677418.8004913,	1786677442.6873393),
('0053c527-2bdb-4d5a-9207-045028e161a8',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/0053c527-2bdb-4d5a-9207-045028e161a8.tiff',	'eeaa5f15-3682-49b7-a2ff-4136bfe43b5e',	'e831aab8-8724-4e21-87f2-94a13505e880',	'Timed out waiting for initial communication',	1786620666.8445797,	1786620733.6129959),
('a41dbd34-aa48-4d07-884a-6654b8edb75e',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462090806',	1,	'received',	'/var/spool/fax/inbox/a41dbd34-aa48-4d07-884a-6654b8edb75e.pdf',	'78732215-62ab-4e94-95f5-9170bc0915a2',	NULL,	NULL,	1786623116.6345513,	1786623143.6687593),
('b7357ed9-e062-492c-9f8c-a8d814a905eb',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	1,	'sent',	'/var/spool/fax/outbound_tiff/b7357ed9-e062-492c-9f8c-a8d814a905eb.tiff',	'38bd0d1c-3960-4fd5-9fd9-b945c9f10bf2',	'e6f886d2-a9c0-4c8a-b998-de450bd774ee',	NULL,	1786623114.6760302,	1786623143.7231843),
('b58ee671-8332-4cd6-97cf-67af81cc3a19',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	1,	'sent',	'/var/spool/fax/outbound_tiff/b58ee671-8332-4cd6-97cf-67af81cc3a19.tiff',	'aeb99f18-0c21-41ce-b228-f9d5f5c6ab22',	'038dd2a9-9c5a-4a89-b381-f66591029ef0',	NULL,	1786677417.6077347,	1786677442.789605),
('52f101db-b853-4637-afe6-296bb0ffed07',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/52f101db-b853-4637-afe6-296bb0ffed07.tiff',	'4ed32cb2-e75b-4b9b-a50c-23c3af68d456',	'71a6c81d-693e-46cf-91bb-5869c0d75c5e',	'Timed out waiting for initial communication',	1786621268.6310916,	1786621334.8705487),
('002871b7-7bec-4fc6-8e55-0e3bc7d6e799',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/002871b7-7bec-4fc6-8e55-0e3bc7d6e799.pdf',	'fbf63209-c3a1-4fae-af2f-22c1710b848d',	NULL,	NULL,	1786623304.725059,	1786623330.0232978),
('c6b0ef04-4492-45db-b4da-4d177f94af42',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	1,	'sent',	'/var/spool/fax/outbound_tiff/c6b0ef04-4492-45db-b4da-4d177f94af42.tiff',	'4ec801a7-8a52-4044-adac-d8c272d73176',	'8f71e725-76be-4bad-a69e-46d5b5a7c9d2',	NULL,	1786623303.0505228,	1786623330.1596622),
('0eee607e-2479-41bd-9598-0d08ca6de114',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/0eee607e-2479-41bd-9598-0d08ca6de114.tiff',	'95157470-fd1c-4924-82a4-c7fc063461e8',	'6d30f0cf-debb-4427-b159-dd3ae86304ee',	'Timed out waiting for initial communication',	1786621483.1794074,	1786621548.7376423),
('7901d07f-af44-4a7a-98dd-f60ca56444f4',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/7901d07f-af44-4a7a-98dd-f60ca56444f4.pdf',	'672110d3-67ef-459d-b388-4240e5c11dc7',	NULL,	NULL,	1786623995.8415205,	1786624024.6257858),
('6b21e2b9-aeb8-4d19-a3e5-1035810ee712',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	1,	'sent',	'/var/spool/fax/outbound_tiff/6b21e2b9-aeb8-4d19-a3e5-1035810ee712.tiff',	'f122eba6-ae6b-4b32-aafb-c2d2847a0d8a',	'8aed235a-bde3-458c-9d23-d76a9e07ad92',	NULL,	1786623993.7030156,	1786624026.7751846),
('a9d18126-b638-4e25-8ecd-04d07d681c33',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	0,	'failed',	'/var/spool/fax/outbound_tiff/a9d18126-b638-4e25-8ecd-04d07d681c33.tiff',	'4a023871-b6ad-4af1-8434-990d8c984c48',	'87274f3e-139c-4900-bc20-789fdb053312',	'Timed out waiting for initial communication',	1786621689.0336862,	1786621756.7847068),
('3b936d1b-af3b-4da7-91c5-bef60469f385',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401006',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/fc563bfb-42f3-408a-8020-0c8a3c09a5a4.tiff',	'fc563bfb-42f3-408a-8020-0c8a3c09a5a4',	NULL,	'The call dropped prematurely',	1786676603.971581,	1786676609.9281054),
('37706873-3479-4d95-8b84-314e3913a399',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/13f20b6d-61d8-4304-bdf1-d1f46b22ca57.tiff',	'13f20b6d-61d8-4304-bdf1-d1f46b22ca57',	NULL,	'Timed out waiting for the first message',	1786677180.2773438,	1786677219.7949417),
('a88305ad-806c-4a8f-8cea-35092526c610',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/48359b08-b176-4708-83a8-f884aa94cddc.tiff',	'48359b08-b176-4708-83a8-f884aa94cddc',	NULL,	'Timed out waiting for the first message',	1786677286.132749,	1786677325.6178184),
('bb64d72e-8354-4d0a-bbab-3e93d26a1a36',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/57fce1c8-81ea-4f64-a84a-3815d189fc45.tiff',	'57fce1c8-81ea-4f64-a84a-3815d189fc45',	NULL,	'Timed out waiting for the first message',	1786677342.8525574,	1786677382.595485),
('fa072753-3cf8-46e4-b8cd-2b3b570c1b5d',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462090806',	1,	'received',	'/var/spool/fax/inbox/fa072753-3cf8-46e4-b8cd-2b3b570c1b5d.pdf',	'5788e828-8ddc-421a-934e-d56b83b5b555',	NULL,	NULL,	1786621988.0451088,	1786622005.4804223),
('ecc55504-e63f-4852-a2fe-351b80df5591',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13462090806',	1,	'sent',	'/var/spool/fax/outbound_tiff/ecc55504-e63f-4852-a2fe-351b80df5591.tiff',	'8f8c94a6-10db-471d-947b-a5ed3a5c7d1c',	'af42a31d-34a3-4f68-9443-5a3f89b058e9',	NULL,	1786621986.532022,	1786622005.5645194),
('bfe8b502-37d4-4e27-b227-8652a7167062',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	1,	'received',	'/var/spool/fax/inbox/bfe8b502-37d4-4e27-b227-8652a7167062.pdf',	'8141605b-8440-465a-a03f-a0cac16cc26c',	NULL,	NULL,	1786677620.7234075,	1786677652.9680948),
('764bfd5e-409b-49aa-aa34-75a14150779a',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/eab46385-b87f-40e0-9aa4-cc63917d885d.tiff',	'eab46385-b87f-40e0-9aa4-cc63917d885d',	NULL,	'Timed out waiting for the first message',	1786677399.803009,	1786677439.478023),
('37a2dd1b-5aa0-4fd9-aa19-70ab33e331d6',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	1,	'received',	'/var/spool/fax/inbox/37a2dd1b-5aa0-4fd9-aa19-70ab33e331d6.pdf',	'3c6befc7-52a7-4963-8b40-6ab325898283',	NULL,	NULL,	1786677692.925684,	1786677719.6940444),
('f2a01ce9-b6f5-4e12-ad9f-5e0d21299306',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401006',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/53eb547e-7332-40d5-8909-11d4aa26943b.tiff',	'53eb547e-7332-40d5-8909-11d4aa26943b',	NULL,	'The call dropped prematurely',	1786677856.9067512,	1786677861.4625082),
('b6a518a1-36bb-4586-a3f3-c70b6d633e54',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/98001ccc-dda4-4ec6-9dc1-e8985dc41ec1.tiff',	'98001ccc-dda4-4ec6-9dc1-e8985dc41ec1',	NULL,	'Timed out waiting for the first message',	1786677948.3923476,	1786677987.7697947),
('0ab8fe23-4029-4268-a115-3109e582b6ed',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/6cf828a7-ccd5-4f26-8eb5-a9f3e910cabc.tiff',	'6cf828a7-ccd5-4f26-8eb5-a9f3e910cabc',	NULL,	'Timed out waiting for the first message',	1786678060.429483,	1786678099.778544),
('7c32ad3f-fc8f-4ec4-a069-e748ddb00074',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/2bc933a0-7f96-4037-a497-8f3e26edac44.tiff',	'2bc933a0-7f96-4037-a497-8f3e26edac44',	NULL,	'Timed out waiting for the first message',	1786678412.2021277,	1786678451.7295465),
('5457bf24-e056-41aa-b43c-fcd0f56d5801',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/8b9ff86f-33b8-4bc5-982d-4a9486134428.tiff',	'8b9ff86f-33b8-4bc5-982d-4a9486134428',	NULL,	'Timed out waiting for the first message',	1786678763.5951517,	1786678804.1214933),
('69fcaab9-e4e6-49c8-a330-927f8396fb91',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/6d374982-7d94-45b5-9121-6e77c9431070.tiff',	'6d374982-7d94-45b5-9121-6e77c9431070',	NULL,	'Timed out waiting for the first message',	1786678993.763581,	1786679033.142686),
('b2bc162a-f9f6-4aca-b131-151c0bc41cf6',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/1216e23d-09e1-4b5d-b621-24d4add9f7da.tiff',	'1216e23d-09e1-4b5d-b621-24d4add9f7da',	NULL,	'Timed out waiting for the first message',	1786679105.7512825,	1786679145.1242192),
('531e741a-5c89-4443-baee-3f083ce63302',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/e0080dc4-b432-4b0e-891d-a4ce71fcedfe.tiff',	'e0080dc4-b432-4b0e-891d-a4ce71fcedfe',	NULL,	'Timed out waiting for the first message',	1786679455.6701272,	1786679495.0391202),
('352b4773-85ec-484a-afd7-06fdaf5beb5a',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/2977a308-2ed1-435d-bb57-8bd5708a58ef.tiff',	'2977a308-2ed1-435d-bb57-8bd5708a58ef',	NULL,	'Timed out waiting for the first message',	1786684929.3343344,	1786684968.6901116),
('44b2608d-ee56-4e34-8396-2080373a0a7d',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+12063374321',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/44b2608d-ee56-4e34-8396-2080373a0a7d.pdf',	'e8d56113-6a8f-4fb6-90f7-772b821b8c56',	NULL,	NULL,	1786679803.3972156,	1786679831.421382),
('9e984b90-3fec-4bdc-85be-70e2af466438',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/0c31cb99-f92f-41c5-91d0-86acf9f044b5.tiff',	'0c31cb99-f92f-41c5-91d0-86acf9f044b5',	NULL,	'Timed out waiting for the first message',	1786679805.3239634,	1786679844.928058),
('793c45d4-a8d7-478e-8cd1-bb776ff7c50a',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+12063374321',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/793c45d4-a8d7-478e-8cd1-bb776ff7c50a.pdf',	'c1eced7c-a3cd-454e-bd3d-3ac365cf26a2',	NULL,	NULL,	1786679776.2229757,	1786679895.7111077),
('6cf9aedd-cdbb-458b-b3b0-42cadde38190',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/48e3c388-9bab-4d29-8745-ff8ff2db3fe7.tiff',	'48e3c388-9bab-4d29-8745-ff8ff2db3fe7',	NULL,	'Timed out waiting for the first message',	1786680156.3230429,	1786680195.9190514),
('5a855fbf-4479-4473-9798-d4cc3635bf3f',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/4a42bb6e-9dbd-47d4-8f87-74ede7fb15ea.tiff',	'4a42bb6e-9dbd-47d4-8f87-74ede7fb15ea',	NULL,	'The call dropped prematurely',	1786685279.6518462,	1786685281.1448667),
('a015da4e-b68b-46a1-b5c6-257f16e79e8f',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/b0e7f514-f363-401a-a5bd-355c57ddfd06.tiff',	'b0e7f514-f363-401a-a5bd-355c57ddfd06',	NULL,	'Timed out waiting for the first message',	1786680507.1490757,	1786680546.57239),
('a988aff7-4831-49ac-ba1a-db2fd3fd08d5',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/f9b7aaac-62ce-4580-a8a8-8a936aa3e7b3.tiff',	'f9b7aaac-62ce-4580-a8a8-8a936aa3e7b3',	NULL,	'The call dropped prematurely',	1786685280.9910958,	1786685287.2620924),
('5b728c5b-77a5-4c43-a449-c73092bbbfc5',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'+16813100710',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/5b728c5b-77a5-4c43-a449-c73092bbbfc5.pdf',	'ab8a7e31-373e-4c1f-a3ff-b4775f0f894d',	NULL,	NULL,	1786682929.6265986,	1786682961.0043137),
('c3f05d4a-f73a-40f1-b7fd-ed2d87b65a7e',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/87040129-ed40-4df2-8317-b00e9016a0c7.tiff',	'87040129-ed40-4df2-8317-b00e9016a0c7',	NULL,	'Timed out waiting for the first message',	1786683015.100707,	1786683054.487184),
('a3c9c169-85f6-43cd-a39e-f4d23fe997eb',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/9c59ec36-e7f0-4785-96dc-519123b17d95.tiff',	'9c59ec36-e7f0-4785-96dc-519123b17d95',	NULL,	'Timed out waiting for the first message',	1786683127.944414,	1786683167.8619118),
('923bb8ee-1bd9-49c2-91a4-829a90e761a5',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13466844460',	0,	'failed',	'/var/spool/fax/outbound_tiff/923bb8ee-1bd9-49c2-91a4-829a90e761a5.tiff',	'cacea234-0534-4c72-b673-6c92f55e3dbe',	'ae9284bc-5f4d-421f-95a6-e342fc54a370',	'',	1786683174.5966828,	1786683176.3179452),
('ea73ee04-192e-4ad5-b259-ae7d45996274',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13466844460',	0,	'failed',	'/var/spool/fax/outbound_tiff/ea73ee04-192e-4ad5-b259-ae7d45996274.tiff',	'65c6e829-4c4e-451c-93c7-67c6ba516b93',	'5e88951f-6b08-4abc-b01b-3321727ebd24',	'',	1786683267.9764385,	1786683269.001519),
('dd62778e-b7af-48e9-a1ae-66d823a08ad7',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13466844460',	0,	'failed',	'/var/spool/fax/outbound_tiff/dd62778e-b7af-48e9-a1ae-66d823a08ad7.tiff',	'ed1aa94c-0bdb-49fe-be65-ae7249beadd1',	'1a7bfaee-113c-48b4-a81d-75f98b2bf4c3',	'',	1786683288.354641,	1786683289.3881118),
('53bc673f-0351-481a-ac22-f2ee7a30a49d',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/7a422629-18af-4d83-853d-4f614f2ff7d6.tiff',	'7a422629-18af-4d83-853d-4f614f2ff7d6',	NULL,	'The call dropped prematurely',	1786685546.4182649,	1786685547.1902654),
('161632cc-17b9-49f8-93af-29eb1c431604',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13466844460',	0,	'failed',	'/var/spool/fax/outbound_tiff/161632cc-17b9-49f8-93af-29eb1c431604.tiff',	'd19ccd78-7973-4de8-aeb2-03ce08cc6962',	'7ac59e35-67b7-40a9-b06d-041e4158a8dd',	'',	1786683356.5216668,	1786683357.9015343),
('31a1974d-8d29-491f-9467-58b6655d9741',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/21ab5863-b7c5-4090-8a5d-384bfa5a6f06.tiff',	'21ab5863-b7c5-4090-8a5d-384bfa5a6f06',	NULL,	'The call dropped prematurely',	1786685547.129851,	1786685554.0244026),
('50f16590-f2d4-400c-95f5-02ad2eb58fdf',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462023679',	'13466844460',	0,	'failed',	'/var/spool/fax/outbound_tiff/50f16590-f2d4-400c-95f5-02ad2eb58fdf.tiff',	'03ea81dd-68f8-4fbd-a7fb-bbbf0bdb8771',	'ba10deb6-3bd6-4db1-b594-6aa980a85090',	'',	1786683441.5934315,	1786683443.13185),
('47719abf-838d-43b7-97df-9d04daa8e3ac',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/d5d27976-15bf-45a7-942d-17cf4f25c986.tiff',	'd5d27976-15bf-45a7-942d-17cf4f25c986',	NULL,	'The call dropped prematurely',	1786685698.5619662,	1786685698.6907532),
('5d7aac4f-37d7-4961-aafa-8e5a3100abe7',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/03a57e7c-b075-48a3-98db-e5beeaa2d33f.tiff',	'03a57e7c-b075-48a3-98db-e5beeaa2d33f',	NULL,	'Timed out waiting for the first message',	1786683480.3633063,	1786683519.894352),
('024486eb-6aa7-40a4-9b46-0fcbd0864cc6',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	1,	'received',	'/var/spool/fax/inbox/024486eb-6aa7-40a4-9b46-0fcbd0864cc6.pdf',	'78fe4056-d8a8-4c9c-a770-14e8c9b700bc',	NULL,	NULL,	1786683510.1176298,	1786683527.5322163),
('b6406874-6797-468d-a2c7-ddb0a3f4bceb',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13462023679',	1,	'sent',	'/var/spool/fax/outbound_tiff/b6406874-6797-468d-a2c7-ddb0a3f4bceb.tiff',	'07af68ba-93c3-4769-b6e2-b7a5aa2124cf',	'66428181-6e8a-4c03-87bb-7629ac0e1feb',	NULL,	1786683509.0432317,	1786683527.6693358),
('05ebe67f-63e9-49fb-bdfb-c8a268725bd1',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/d18ba4de-7bf7-4cec-b9d7-59f04383f9c5.tiff',	'd18ba4de-7bf7-4cec-b9d7-59f04383f9c5',	NULL,	'The call dropped prematurely',	1786685698.5896628,	1786685706.1700032),
('28b3e822-427c-44b2-b79c-9e5aa369ab6e',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/198f849e-aee2-4416-ad40-e3cc1acf45b2.tiff',	'198f849e-aee2-4416-ad40-e3cc1acf45b2',	NULL,	'Timed out waiting for the first message',	1786683833.597593,	1786683872.9920049),
('552e7384-6599-46b3-b841-2b793c786b74',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/de3ecc30-7973-4901-9f89-04a44672c93d.tiff',	'de3ecc30-7973-4901-9f89-04a44672c93d',	NULL,	'Timed out waiting for the first message',	1786684094.6446278,	1786684134.0259774),
('8b4bf043-ecba-470e-8891-7c517923523b',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'13466844460',	0,	'failed',	'/var/spool/fax/outbound_tiff/8b4bf043-ecba-470e-8891-7c517923523b.tiff',	'ddb80c1d-fc74-4558-87ee-b075c239b559',	'1638fbb8-00e5-4f9d-a272-f17e9d65a938',	'',	1786693349.9472668,	1786693351.3990135),
('05da863e-03ee-446d-9226-88cd712abe0e',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/840925fc-4de9-45e2-8a7b-17d7c232493e.tiff',	'840925fc-4de9-45e2-8a7b-17d7c232493e',	NULL,	'Timed out waiting for the first message',	1786684206.4735036,	1786684245.8681774),
('6ca67395-1720-4976-a23c-a9e78127c470',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/e2cebce0-0714-4ed0-b3b2-72966355a0ee.tiff',	'e2cebce0-0714-4ed0-b3b2-72966355a0ee',	NULL,	'Timed out waiting for the first message',	1786684407.8795118,	1786684447.3918035),
('0bcbc0b1-7547-4be6-abc8-19d1155748e3',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/ebdb5915-41a3-4aeb-b8d0-1a42c4f60660.tiff',	'ebdb5915-41a3-4aeb-b8d0-1a42c4f60660',	NULL,	'Timed out waiting for the first message',	1786684520.355752,	1786684559.711451),
('994aaac0-0bf1-453e-bd9b-78b2fee0f822',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/dac207ff-f8cf-4842-937d-6d017f4c049b.tiff',	'dac207ff-f8cf-4842-937d-6d017f4c049b',	NULL,	'Timed out waiting for the first message',	1786684577.8989801,	1786684617.2384522),
('eca8b682-19f7-4c34-8fba-9bbd95355a20',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401010',	0,	'failed',	'/var/spool/fax/outbound_tiff/eca8b682-19f7-4c34-8fba-9bbd95355a20.tiff',	'fb3aae0c-233f-47ac-a701-a2a9ac98af11',	'8d04ce0e-c9a8-4a74-8072-abef889f3dcb',	'',	1786693378.9330568,	1786693410.778405),
('f0600ed8-30ce-4b7e-8ca2-1ed8e82c7c79',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401023',	'63401023',	1,	'received',	'/var/spool/fax/inbox/f0600ed8-30ce-4b7e-8ca2-1ed8e82c7c79.pdf',	'e771ef56-066a-4826-8037-7c6716981d4b',	NULL,	NULL,	1786693824.5056968,	1786693848.7221944),
('64531a92-0b25-4772-97b6-144afab41131',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'13462090806',	'63401023',	1,	'sent',	'/var/spool/fax/outbound_tiff/64531a92-0b25-4772-97b6-144afab41131.tiff',	'f540b70e-7c89-4283-b4ad-3b71b51c2836',	'c0e881ba-2627-4882-8b49-f04872557f80',	NULL,	1786693821.5483706,	1786693849.067959),
('de4658fa-b46a-4c8a-82ea-2c8aeeefdfc4',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/dda64f12-989a-4225-b68e-333920ea2f42.tiff',	'dda64f12-989a-4225-b68e-333920ea2f42',	NULL,	'Timed out waiting for the first message',	1786700421.6432073,	1786700461.034424),
('109d44bb-710a-4596-92cc-ad4884ed6a20',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/d404f1f4-b493-4eb0-a9e0-9ef82249781c.tiff',	'd404f1f4-b493-4eb0-a9e0-9ef82249781c',	NULL,	'Timed out waiting for the first message',	1786700771.7201986,	1786700811.1225445),
('3221d476-d67c-4332-af2f-11da7ada6c1c',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/d1d23ca8-d031-4857-9a50-651ae5302c7e.tiff',	'd1d23ca8-d031-4857-9a50-651ae5302c7e',	NULL,	'Timed out waiting for the first message',	1786700971.1469436,	1786701010.6630123),
('e27facad-254f-47d4-8b21-ccf4bf956f33',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/176b1a78-34f8-4fc3-a7e6-7f2248b4ac6c.tiff',	'176b1a78-34f8-4fc3-a7e6-7f2248b4ac6c',	NULL,	'Timed out waiting for the first message',	1786701083.7566059,	1786701123.1198218),
('9651e8d2-6b43-4e31-bb03-b394163418d1',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/b0782581-a901-4ea1-bf4f-ff2263c4dfbf.tiff',	'b0782581-a901-4ea1-bf4f-ff2263c4dfbf',	NULL,	'Timed out waiting for the first message',	1786701301.952098,	1786701341.3837116),
('7f00b857-3f12-4b75-ba35-828889277cd8',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/b60bfb4c-0958-4d33-81c2-d8dcc2def3ef.tiff',	'b60bfb4c-0958-4d33-81c2-d8dcc2def3ef',	NULL,	'Timed out waiting for the first message',	1786701414.1129556,	1786701453.6162806),
('44df0e62-6feb-42d3-9fd9-9f5923f3713b',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/45d9f80b-90aa-475a-b441-6aa9a7db9ffc.tiff',	'45d9f80b-90aa-475a-b441-6aa9a7db9ffc',	NULL,	'Timed out waiting for the first message',	1786701684.8438919,	1786701724.3690782),
('d984e2c1-0f34-4e53-a74a-6c432ceebc60',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/d14bff64-5463-44cb-ad81-fa33a415da75.tiff',	'd14bff64-5463-44cb-ad81-fa33a415da75',	NULL,	'The call dropped prematurely',	1786704341.6868846,	1786704341.8289864),
('90456ebe-2cef-44b0-ad54-3e00957e4a4b',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/dad3ed73-cdb1-4307-9910-6551518f53d9.tiff',	'dad3ed73-cdb1-4307-9910-6551518f53d9',	NULL,	'The call dropped prematurely',	1786704341.719331,	1786704349.3104413),
('f0026d53-ef2c-456f-bf31-5e79d948566b',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/dc199aea-7881-47ad-94f4-eacc3192f876.tiff',	'dc199aea-7881-47ad-94f4-eacc3192f876',	NULL,	'The call dropped prematurely',	1786704473.5589378,	1786704473.7172039),
('ca19bfef-998b-4c46-9809-e2dced5898a0',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'63401023',	'63401007',	0,	'failed',	'/var/spool/fax/outbound_tiff/ca19bfef-998b-4c46-9809-e2dced5898a0.tiff',	NULL,	'e9ec611c-4541-470d-93a7-3b1c3b9fee99',	'-ERR CHAN_NOT_IMPLEMENTED',	1786444216.0905824,	1786444222.9873776),
('0ace72c2-80ab-4e89-9b8e-a6e9514c75b3',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'63401010',	'63401023',	0,	'failed',	'/var/spool/fax/inbound_tiff/ccb5c73b-3897-4b43-9823-f7256fbfe81e.tiff',	'ccb5c73b-3897-4b43-9823-f7256fbfe81e',	NULL,	'The call dropped prematurely',	1786704473.596842,	1786704481.1953938),
('2954c367-235c-4c41-b2a8-c61b74c98d4f',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/2954c367-235c-4c41-b2a8-c61b74c98d4f.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787048884.063511,	1787053731.3364244),
('9c51f373-2aa3-424e-a949-e58d33b8d519',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/9c51f373-2aa3-424e-a949-e58d33b8d519.tiff',	NULL,	NULL,	'FS_ESL_PASSWORD is not configured',	1787047959.497294,	1787047959.599595),
('78607a02-8519-4d81-9e9d-78a7958b7c41',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/78607a02-8519-4d81-9e9d-78a7958b7c41.tiff',	NULL,	NULL,	'FreeSWITCH ESL authentication failed',	1787044701.737192,	1787044711.840574),
('891791af-508b-4eee-babe-aef07c3f14aa',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/891791af-508b-4eee-babe-aef07c3f14aa.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787049689.114766,	1787053731.361474),
('85124bc8-5574-4325-806c-4c7395b924c4',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/85124bc8-5574-4325-806c-4c7395b924c4.tiff',	NULL,	NULL,	'Timed out waiting for a call result from FreeSWITCH',	1787050111.149565,	1787053731.3819938),
('4200f62a-30ba-4cc3-9b6d-aabee7c69f54',	'c7319502-4c16-48de-bf40-78346863cada',	'in',	'6563401023',	'+13462023679',	0,	'failed',	'/var/spool/fax/inbound_tiff/e5a08cec-6042-4ba0-ba2d-6c341d486d50.tiff',	'e5a08cec-6042-4ba0-ba2d-6c341d486d50',	NULL,	'Timed out waiting for the first message',	1787118106.9333413,	1787118705.435527),
('b06a7145-5a03-4c63-8b4d-25805908fa65',	'c7319502-4c16-48de-bf40-78346863cada',	'out',	'6563401003',	'63401023',	0,	'failed',	'/var/spool/fax/outbound_tiff/b06a7145-5a03-4c63-8b4d-25805908fa65.tiff',	NULL,	'63072f41-0510-448e-aa32-f39e06175693',	'Timed out waiting for FreeSWITCH fax result',	1787048733.901607,	1787048941.063286);

DROP TABLE IF EXISTS "fax_numbers";
CREATE TABLE "public"."fax_numbers" (
    "number" text NOT NULL,
    "label" text,
    "enabled" integer DEFAULT '1' NOT NULL,
    "created_at" double precision NOT NULL,
    "updated_at" double precision NOT NULL,
    CONSTRAINT "fax_numbers_pkey" PRIMARY KEY ("number")
) WITH (oids = false);

INSERT INTO "fax_numbers" ("number", "label", "enabled", "created_at", "updated_at") VALUES
('63401023',	'Fax line',	1,	1785321668.0980392,	1785321668.0980392),
('263401023',	'Fax line',	1,	1785323409.6974638,	1785323409.6974638),
('123456',	'Fax line',	1,	1785494446.0925622,	1785494446.0925622),
('13462023679',	'Fax line',	1,	1785844366.6573944,	1785844366.6573944),
('13462090806',	'Fax line',	1,	1785845168.4004102,	1785845168.4004102);

DROP TABLE IF EXISTS "freeswitch_credentials";
DROP SEQUENCE IF EXISTS freeswitch_credentials_id_seq;
CREATE SEQUENCE freeswitch_credentials_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 9223372036854775807 START 1 CACHE 1;

CREATE TABLE "public"."freeswitch_credentials" (
    "id" bigint DEFAULT nextval('freeswitch_credentials_id_seq') NOT NULL,
    "name" character varying(255),
    "host" character varying(255) DEFAULT '127.0.0.1' NOT NULL,
    "port" integer DEFAULT '8021' NOT NULL,
    "password" text NOT NULL,
    "status" boolean DEFAULT true NOT NULL,
    "created_at" timestamp,
    "updated_at" timestamp,
    CONSTRAINT "freeswitch_credentials_pkey" PRIMARY KEY ("id")
) WITH (oids = false);

INSERT INTO "freeswitch_credentials" ("id", "name", "host", "port", "password", "status", "created_at", "updated_at") VALUES
(1,	'FreeSWITCH Server',	'127.0.0.1',	8021,	'ClueCon',	't',	'2026-08-18 10:18:39.86057',	'2026-08-18 10:18:39.86057');

-- End efax queries

UPDATE v_voicemails vm
   SET voicemail_name = COALESCE(
           NULLIF(btrim((SELECT rg.ring_group_name
                           FROM v_ring_groups rg
                          WHERE rg.domain_uuid          = vm.domain_uuid
                            AND rg.ring_group_extension = vm.voicemail_id
                          LIMIT 1)), ''),
           'Extension ' || vm.voicemail_id)
 WHERE (vm.voicemail_name IS NULL OR btrim(vm.voicemail_name) = '')
   AND vm.voicemail_id ~ '^[0-9]+$'
   AND vm.voicemail_id::bigint BETWEEN 2000 AND 2500;

INSERT INTO "v_dialplans" ("domain_uuid", "dialplan_uuid", "app_uuid", "hostname", "dialplan_context", "dialplan_name", "dialplan_number", "dialplan_destination", "dialplan_continue", "dialplan_xml", "dialplan_order", "dialplan_enabled", "dialplan_description", "insert_date", "insert_user", "update_date", "update_user", "exceeded2") VALUES
(NULL,	'117d44b4-4af0-44e6-9820-92bb31aef2a5',	'bfc5e675-0df8-4ca3-83fc-a120521bda7a',	NULL,	'global',	'targeted_extension_pickup',	'[ext]',	NULL,	'true',	'<extension name="targeted_extension_pickup">
    <condition field="destination_number" expression="^\*8(\d+)$">
        <action application="answer"/>
        <action application="intercept" data="${hash(select/${domain_name}-last_dial_ext/$1)}"/>
    </condition>
</extension>',	10,	'true',	NULL,	'2023-08-22 13:31:13.485469+11',	NULL,	'2023-08-22 13:31:14.620217+11',	NULL,	NULL);

INSERT INTO "v_dialplans" ("domain_uuid", "dialplan_uuid", "app_uuid", "hostname", "dialplan_context", "dialplan_name", "dialplan_number", "dialplan_destination", "dialplan_continue", "dialplan_xml", "dialplan_order", "dialplan_enabled", "dialplan_description", "insert_date", "insert_user", "update_date", "update_user", "exceeded2") VALUES
(NULL,	'fff6fd80-b5a4-46af-a300-f9230ddbbdc2',	'737036bb-9b7b-49f1-92b9-ef763fbc8591',	NULL,	'global',	'targeted_extension_pickup',	'[ext]',	NULL,	'true',	'<extension name="call_pickup">
 <condition field="destination_number" expression="^\*8$">
 <action application="answer"/>
 <action application="intercept" data="${hash(select/${domain_name}-last_dial/${call_group})}"/>
 </condition>
</extension>',	10,	'true',	NULL,	'2023-08-22 13:31:13.485469+11',	NULL,	'2023-08-22 13:31:14.620217+11',	NULL,	NULL);

INSERT INTO "v_dialplans" ("domain_uuid", "dialplan_uuid", "app_uuid", "hostname", "dialplan_context", "dialplan_name", "dialplan_number", "dialplan_destination", "dialplan_continue", "dialplan_xml", "dialplan_order", "dialplan_enabled", "dialplan_description", "insert_date", "insert_user", "update_date", "update_user", "exceeded2") VALUES
(NULL,	'50ee3b10-1c8b-404a-b6b8-8eef92b4e55c',	'e635b3fc-f8f4-4d3d-b54e-2918eedf07ec',	NULL,	'global',	'vmain_common',	'[ext]',	NULL,	'true',	'<extension name="vmain_common" continue="false" uuid="50ee3b10-1c8b-404a-b6b8-8eef92b4e55c">     <condition field="destination_number" expression="^7878$">         <action application="answer" data=""/>         <action application="sleep" data="1000"/>         <action application="set" data="record_append=false"/>         <action application="set" data="voicemail_action=check"/>         <action application="set" data="voicemail_profile=default"/>         <action application="lua" data="app.lua voicemail"/>     </condition> </extension> 
',	10,	'true',	NULL,	'2023-08-22 13:31:13.485469+11',	NULL,	'2023-08-22 13:31:14.620217+11',	NULL,	NULL);

UPDATE "v_dialplans" SET
"domain_uuid" = NULL,
"dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9',
"app_uuid" = '71cf1310-b6e3-415b-8745-3cbdc8e15212',
"hostname" = NULL,
"dialplan_context" = 'global',
"dialplan_name" = 'local_extension',
"dialplan_number" = '[ext]',
"dialplan_destination" = NULL,
"dialplan_continue" = 'true',
"dialplan_xml" = '
<extension name="local_extension" continue="true" uuid="1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9">
    <condition field="${user_exists}" expression="true">
        <action application="export" data="dialed_extension=${destination_number}" inline="true"/>
        <action application="limit" data="hash ${domain_name} ${destination_number} ${limit_max} ${limit_destination}" inline="false"/>
    </condition>
    <condition field="${caller_id_number}" expression="^[a-f0-9]{20}$" break="on-true">
        <action application="set" data="ringback=${us-ring}"/>
        <action application="ring_ready"/>
        <action application="sleep" data="1000"/>
        <action application="set" data="hangup_after_bridge=false"/>
        <action application="set" data="park_after_bridge=false"/>
        <action application="set" data="auto_hangup=false"/>
        <action application="set" data="transfer_after_bridge=false"/>
        <action application="set" data="inherit_codec=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
        <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
        <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
        <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
        <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
        <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
        <action application="export" data="domain_name=${domain_name}"/>
        <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal2}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_internal3}"/>
        <action application="answer"/>
        <!-- <action application="lua" data="app.lua failure_handler"/> -->
    </condition>
    <condition field="" expression="">
        <action application="set" data="ringback=${us-ring}"/>
        <action application="ring_ready"/>
        <action application="sleep" data="1000"/>
        <action application="set" data="hangup_after_bridge=false"/>
        <action application="set" data="park_after_bridge=false"/>
        <action application="set" data="auto_hangup=false"/>
        <action application="set" data="transfer_after_bridge=false"/>
        <action application="set" data="inherit_codec=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="set" data="initial_callee_id_name=${user_data(${dialed_extension}@${domain_name} var effective_caller_id_name)}"/>
        <action application="hash" data="insert/${domain_name}-call_return/${dialed_extension}/${caller_id_number}"/>
        <action application="hash" data="insert/${domain_name}-last_dial_ext/${dialed_extension}/${uuid}"/>
        <action application="set" data="called_party_call_group=${user_data(${dialed_extension}@${domain_name} var call_group)}"/>
        <action application="hash" data="insert/${domain_name}-last_dial/${called_party_call_group}/${uuid}"/>
        <action application="set" data="api_hangup_hook=lua app.lua hangup"/>
        <action application="export" data="domain_name=${domain_name}"/>
        <action application="bridge" data="user/${destination_number}@${domain_name}${user_data ${destination_number}@${domain_name} var simultaneous_destination2}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external2}"/>
        <action application="bridge" data="${user_data ${destination_number}@${domain_name} var follow_external3}"/>
        <action application="answer"/>
        <!-- <action application="lua" data="app.lua failure_handler"/> -->
    </condition>
</extension>',
"dialplan_order" = '890',
"dialplan_enabled" = 'true',
"dialplan_description" = NULL,
"insert_date" = '2023-08-22 13:31:13.485469+11',
"insert_user" = NULL,
"update_date" = '2023-08-22 13:31:14.620217+11',
"update_user" = NULL,
"exceeded2" = NULL
WHERE "dialplan_uuid" = '1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9';

CREATE TABLE IF NOT EXISTS public.q_click2talk_links (
    click2talk_uuid       uuid NOT NULL,
    domain_uuid           uuid NOT NULL,
    destination_type      text NOT NULL,
    destination_uuid      uuid,
    destination_extension text NOT NULL,
    ask_customer_for      text NOT NULL DEFAULT '1',
    share_contact_details integer NOT NULL DEFAULT 1,
    button_text           text,
    button_color          text,
    enabled               integer NOT NULL DEFAULT 1,
    insert_date           timestamptz,
    insert_user           uuid,
    update_date           timestamptz,
    update_user           uuid,
    CONSTRAINT q_click2talk_links_pkey PRIMARY KEY (click2talk_uuid)
);

CREATE UNIQUE INDEX IF NOT EXISTS q_click2talk_links_dest
    ON public.q_click2talk_links (domain_uuid, destination_type, destination_extension);

INSERT INTO public.q_click2talk_links (
    click2talk_uuid, domain_uuid, destination_type, destination_uuid,
    destination_extension, ask_customer_for, share_contact_details,
    enabled, insert_date, update_date)
SELECT gen_random_uuid(), e.domain_uuid, 'extension', e.extension_uuid,
       btrim(e.extension),
       '1', 1, 1, now(), now()
  FROM v_extensions e
 WHERE e.enabled = 'true'
   AND e.extension IS NOT NULL
   AND btrim(e.extension) <> ''
ON CONFLICT (domain_uuid, destination_type, destination_extension) DO NOTHING;

INSERT INTO public.q_click2talk_links (
    click2talk_uuid, domain_uuid, destination_type, destination_uuid,
    destination_extension, ask_customer_for, share_contact_details,
    enabled, insert_date, update_date)
SELECT gen_random_uuid(), q.domain_uuid, 'queue', q.call_center_queue_uuid,
       q.queue_extension, '1', 1, 1, now(), now()
  FROM v_call_center_queues q
 WHERE q.queue_extension IS NOT NULL
   AND btrim(q.queue_extension) <> ''
ON CONFLICT (domain_uuid, destination_type, destination_extension) DO NOTHING;

CREATE TABLE IF NOT EXISTS public.q_hotel_checkins (
    hotel_checkin_uuid CHAR(36) PRIMARY KEY,
    domain_uuid CHAR(36) NOT NULL,
    extension_uuid CHAR(36) NOT NULL,
    extension VARCHAR(32) NOT NULL,
    guest_firstname VARCHAR(255) NOT NULL,
    guest_lastname VARCHAR(255) NOT NULL,
    insert_user CHAR(36),
    insert_date TIMESTAMP DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_hotel_checkins_ext ON public.q_hotel_checkins (domain_uuid, extension_uuid);

CREATE TABLE IF NOT EXISTS public.q_user_preferences (
    domain_uuid VARCHAR(64) NOT NULL,
    user_uuid VARCHAR(64) NOT NULL,
    preferences JSONB NOT NULL DEFAULT '{}'::jsonb,
    update_date TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (domain_uuid, user_uuid)
);

CREATE TABLE IF NOT EXISTS public.q_mcp_clients (
    client_id VARCHAR(64) PRIMARY KEY,
    client_name VARCHAR(200) NOT NULL DEFAULT '',
    redirect_uris JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS public.q_mcp_sessions (
    session_id VARCHAR(64) PRIMARY KEY,
    domain_uuid VARCHAR(64) NOT NULL,
    user_uuid VARCHAR(64) NOT NULL,
    client_id VARCHAR(64) NOT NULL,
    client_name VARCHAR(200) NOT NULL DEFAULT '',
    scopes VARCHAR(64) NOT NULL DEFAULT 'read',
    resource VARCHAR(255) NOT NULL DEFAULT '',
    refresh_hash CHAR(64) NOT NULL,
    prev_refresh_hash CHAR(64),
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ,
    revoked_by VARCHAR(255)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_q_mcp_sessions_refresh ON public.q_mcp_sessions (refresh_hash);
CREATE INDEX IF NOT EXISTS idx_q_mcp_sessions_domain ON public.q_mcp_sessions (domain_uuid, user_uuid);

CREATE TABLE IF NOT EXISTS public.q_mcp_agent_keys (
    key_id VARCHAR(64) PRIMARY KEY,
    domain_uuid VARCHAR(64) NOT NULL,
    user_uuid VARCHAR(64) NOT NULL,
    name VARCHAR(60) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    scopes VARCHAR(64) NOT NULL DEFAULT 'read',
    ip_allowlist JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    last_used_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ,
    revoked_at TIMESTAMPTZ
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_q_mcp_agent_keys_hash ON public.q_mcp_agent_keys (key_hash);
CREATE INDEX IF NOT EXISTS idx_q_mcp_agent_keys_domain ON public.q_mcp_agent_keys (domain_uuid);

CREATE TABLE IF NOT EXISTS public.q_mcp_approvals (
    approval_id VARCHAR(64) PRIMARY KEY,
    domain_uuid VARCHAR(64) NOT NULL,
    user_uuid VARCHAR(64) NOT NULL,
    actor_kind VARCHAR(16) NOT NULL,
    actor_ref VARCHAR(64) NOT NULL,
    actor_label VARCHAR(255) NOT NULL DEFAULT '',
    operation_id VARCHAR(128) NOT NULL,
    args JSONB NOT NULL DEFAULT '{}'::jsonb,
    args_hash CHAR(64) NOT NULL,
    summary TEXT NOT NULL DEFAULT '',
    changes JSONB NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    expires_at TIMESTAMPTZ NOT NULL,
    decided_at TIMESTAMPTZ,
    decided_by VARCHAR(255),
    decision VARCHAR(16),
    executed_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS idx_q_mcp_approvals_domain ON public.q_mcp_approvals (domain_uuid, decision);

CREATE TABLE IF NOT EXISTS q_call_journeys (
  root_uuid      text PRIMARY KEY,
  domain_uuid    uuid,
  start_epoch    bigint,
  legs           text[] NOT NULL DEFAULT '{}',
  caller         text,
  did            text,
  queue_ext      text,
  queue_name     text,
  answered_by    text,
  answered_name  text,
  wait_sec       integer,
  talk_sec       integer,
  outcome        text,
  status_text    text,
  rule           text,
  route          text,
  timeline       jsonb NOT NULL DEFAULT '[]',
  updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS q_call_journeys_legs_gin ON q_call_journeys USING gin (legs);
CREATE INDEX IF NOT EXISTS q_call_journeys_domain_start ON q_call_journeys (domain_uuid, start_epoch);
ALTER TABLE q_call_journeys ADD COLUMN IF NOT EXISTS call_type text;

CREATE TABLE IF NOT EXISTS q_call_center_tier_order (
    call_center_queue_uuid uuid NOT NULL,
    call_center_agent_uuid uuid NOT NULL,
    domain_uuid uuid,
    tier_level integer NOT NULL DEFAULT 1,
    tier_position integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (call_center_queue_uuid, call_center_agent_uuid)
);
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ringq')
       AND (SELECT rolsuper FROM pg_roles WHERE rolname = current_user) THEN
        EXECUTE 'ALTER TABLE public.q_call_journeys OWNER TO ringq';
        EXECUTE 'ALTER TABLE public.q_call_center_tier_order OWNER TO ringq';
    END IF;
END $$;

begin;
update v_dialplans set dialplan_xml=convert_from(decode('CjxleHRlbnNpb24gbmFtZT0ibG9jYWxfZXh0ZW5zaW9uIiBjb250aW51ZT0idHJ1ZSIgdXVpZD0iMWM0YjBiMmYtOWI4OC00ZjRmLWI1ZjUtZjUwYmI3MzU1MmY5Ij4KICAgIDxjb25kaXRpb24gZmllbGQ9IiR7dXNlcl9leGlzdHN9IiBleHByZXNzaW9uPSJ0cnVlIj4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJleHBvcnQiIGRhdGE9ImRpYWxlZF9leHRlbnNpb249JHtkZXN0aW5hdGlvbl9udW1iZXJ9IiBpbmxpbmU9InRydWUiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJsaW1pdCIgZGF0YT0iaGFzaCAke2RvbWFpbl9uYW1lfSAke2Rlc3RpbmF0aW9uX251bWJlcn0gJHtsaW1pdF9tYXh9ICR7bGltaXRfZGVzdGluYXRpb259IiBpbmxpbmU9ImZhbHNlIi8+CiAgICA8L2NvbmRpdGlvbj4KICAgIDxjb25kaXRpb24gZmllbGQ9IiR7Y2FsbGVyX2lkX251bWJlcn0iIGV4cHJlc3Npb249Il5bYS1mMC05XXsyMH0kIiBicmVhaz0ib24tdHJ1ZSI+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJyaW5nYmFjaz0ke3VzLXJpbmd9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0icmluZ19yZWFkeSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNsZWVwIiBkYXRhPSIxMDAwIi8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJoYW5ndXBfYWZ0ZXJfYnJpZGdlPWZhbHNlIi8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJwYXJrX2FmdGVyX2JyaWRnZT1mYWxzZSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNldCIgZGF0YT0iYXV0b19oYW5ndXA9ZmFsc2UiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9InRyYW5zZmVyX2FmdGVyX2JyaWRnZT1mYWxzZSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNldCIgZGF0YT0iaW5oZXJpdF9jb2RlYz10cnVlIi8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJjb250aW51ZV9vbl9mYWlsPXRydWUiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9ImluaXRpYWxfY2FsbGVlX2lkX25hbWU9JHt1c2VyX2RhdGEoJHtkaWFsZWRfZXh0ZW5zaW9ufUAke2RvbWFpbl9uYW1lfSB2YXIgZWZmZWN0aXZlX2NhbGxlcl9pZF9uYW1lKX0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJoYXNoIiBkYXRhPSJpbnNlcnQvJHtkb21haW5fbmFtZX0tY2FsbF9yZXR1cm4vJHtkaWFsZWRfZXh0ZW5zaW9ufS8ke2NhbGxlcl9pZF9udW1iZXJ9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0iaGFzaCIgZGF0YT0iaW5zZXJ0LyR7ZG9tYWluX25hbWV9LWxhc3RfZGlhbF9leHQvJHtkaWFsZWRfZXh0ZW5zaW9ufS8ke3V1aWR9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJjYWxsZWRfcGFydHlfY2FsbF9ncm91cD0ke3VzZXJfZGF0YSgke2RpYWxlZF9leHRlbnNpb259QCR7ZG9tYWluX25hbWV9IHZhciBjYWxsX2dyb3VwKX0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJoYXNoIiBkYXRhPSJpbnNlcnQvJHtkb21haW5fbmFtZX0tbGFzdF9kaWFsLyR7Y2FsbGVkX3BhcnR5X2NhbGxfZ3JvdXB9LyR7dXVpZH0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9ImFwaV9oYW5ndXBfaG9vaz1sdWEgYXBwLmx1YSBoYW5ndXAiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJleHBvcnQiIGRhdGE9ImRvbWFpbl9uYW1lPSR7ZG9tYWluX25hbWV9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ibHVhIiBkYXRhPSJyaW5ncV9jYWxsX2NvbnRyb2wubHVhIHNjcmVlbiIvPiA8IS0tIFtyaW5ncS1jYWxsLWNvbnRyb2xdIGNhbGwgc2NyZWVuaW5nIC0tPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImJyaWRnZSIgZGF0YT0idXNlci8ke2Rlc3RpbmF0aW9uX251bWJlcn1AJHtkb21haW5fbmFtZX0ke3VzZXJfZGF0YSAke2Rlc3RpbmF0aW9uX251bWJlcn1AJHtkb21haW5fbmFtZX0gdmFyIHNpbXVsdGFuZW91c19kZXN0aW5hdGlvbn0ke3JxX3BhcmFsbGVsfSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImJyaWRnZSIgZGF0YT0iJHt1c2VyX2RhdGEgJHtkZXN0aW5hdGlvbl9udW1iZXJ9QCR7ZG9tYWluX25hbWV9IHZhciBmb2xsb3dfaW50ZXJuYWx9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0iYnJpZGdlIiBkYXRhPSIke3VzZXJfZGF0YSAke2Rlc3RpbmF0aW9uX251bWJlcn1AJHtkb21haW5fbmFtZX0gdmFyIGZvbGxvd19pbnRlcm5hbDJ9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0iYnJpZGdlIiBkYXRhPSIke3VzZXJfZGF0YSAke2Rlc3RpbmF0aW9uX251bWJlcn1AJHtkb21haW5fbmFtZX0gdmFyIGZvbGxvd19pbnRlcm5hbDN9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ibHVhIiBkYXRhPSJyaW5ncV9jYWxsX2NvbnRyb2wubHVhIGZhaWxvdmVyIi8+IDwhLS0gW3JpbmdxLWNhbGwtY29udHJvbF0gcmVsYXkgLyBvbiBidXN5IC8gbm8gYW5zd2VyIC0tPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImFuc3dlciIvPgogICAgICAgIDwhLS0gPGFjdGlvbiBhcHBsaWNhdGlvbj0ibHVhIiBkYXRhPSJhcHAubHVhIGZhaWx1cmVfaGFuZGxlciIvPiAtLT4KICAgIDwvY29uZGl0aW9uPgogICAgPGNvbmRpdGlvbiBmaWVsZD0iIiBleHByZXNzaW9uPSIiPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNldCIgZGF0YT0icmluZ2JhY2s9JHt1cy1yaW5nfSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InJpbmdfcmVhZHkiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzbGVlcCIgZGF0YT0iMTAwMCIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNldCIgZGF0YT0iaGFuZ3VwX2FmdGVyX2JyaWRnZT1mYWxzZSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNldCIgZGF0YT0icGFya19hZnRlcl9icmlkZ2U9ZmFsc2UiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9ImF1dG9faGFuZ3VwPWZhbHNlIi8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJ0cmFuc2Zlcl9hZnRlcl9icmlkZ2U9ZmFsc2UiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9ImluaGVyaXRfY29kZWM9dHJ1ZSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249InNldCIgZGF0YT0iY29udGludWVfb25fZmFpbD10cnVlIi8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJjb250aW51ZV9vbl9mYWlsPXRydWUiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9ImluaXRpYWxfY2FsbGVlX2lkX25hbWU9JHt1c2VyX2RhdGEoJHtkaWFsZWRfZXh0ZW5zaW9ufUAke2RvbWFpbl9uYW1lfSB2YXIgZWZmZWN0aXZlX2NhbGxlcl9pZF9uYW1lKX0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJoYXNoIiBkYXRhPSJpbnNlcnQvJHtkb21haW5fbmFtZX0tY2FsbF9yZXR1cm4vJHtkaWFsZWRfZXh0ZW5zaW9ufS8ke2NhbGxlcl9pZF9udW1iZXJ9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0iaGFzaCIgZGF0YT0iaW5zZXJ0LyR7ZG9tYWluX25hbWV9LWxhc3RfZGlhbF9leHQvJHtkaWFsZWRfZXh0ZW5zaW9ufS8ke3V1aWR9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ic2V0IiBkYXRhPSJjYWxsZWRfcGFydHlfY2FsbF9ncm91cD0ke3VzZXJfZGF0YSgke2RpYWxlZF9leHRlbnNpb259QCR7ZG9tYWluX25hbWV9IHZhciBjYWxsX2dyb3VwKX0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJoYXNoIiBkYXRhPSJpbnNlcnQvJHtkb21haW5fbmFtZX0tbGFzdF9kaWFsLyR7Y2FsbGVkX3BhcnR5X2NhbGxfZ3JvdXB9LyR7dXVpZH0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9ImFwaV9oYW5ndXBfaG9vaz1sdWEgYXBwLmx1YSBoYW5ndXAiLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJleHBvcnQiIGRhdGE9ImRvbWFpbl9uYW1lPSR7ZG9tYWluX25hbWV9Ii8+CiAgICAgICAgPGFjdGlvbiBhcHBsaWNhdGlvbj0ibHVhIiBkYXRhPSJyaW5ncV9jYWxsX2NvbnRyb2wubHVhIHNjcmVlbiIvPiA8IS0tIFtyaW5ncS1jYWxsLWNvbnRyb2xdIGNhbGwgc2NyZWVuaW5nIC0tPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImJyaWRnZSIgZGF0YT0idXNlci8ke2Rlc3RpbmF0aW9uX251bWJlcn1AJHtkb21haW5fbmFtZX0ke3VzZXJfZGF0YSAke2Rlc3RpbmF0aW9uX251bWJlcn1AJHtkb21haW5fbmFtZX0gdmFyIHNpbXVsdGFuZW91c19kZXN0aW5hdGlvbjJ9JHtycV9wYXJhbGxlbH0iLz4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJicmlkZ2UiIGRhdGE9IiR7dXNlcl9kYXRhICR7ZGVzdGluYXRpb25fbnVtYmVyfUAke2RvbWFpbl9uYW1lfSB2YXIgZm9sbG93X2V4dGVybmFsfSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImJyaWRnZSIgZGF0YT0iJHt1c2VyX2RhdGEgJHtkZXN0aW5hdGlvbl9udW1iZXJ9QCR7ZG9tYWluX25hbWV9IHZhciBmb2xsb3dfZXh0ZXJuYWwyfSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImJyaWRnZSIgZGF0YT0iJHt1c2VyX2RhdGEgJHtkZXN0aW5hdGlvbl9udW1iZXJ9QCR7ZG9tYWluX25hbWV9IHZhciBmb2xsb3dfZXh0ZXJuYWwzfSIvPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249Imx1YSIgZGF0YT0icmluZ3FfY2FsbF9jb250cm9sLmx1YSBmYWlsb3ZlciIvPiA8IS0tIFtyaW5ncS1jYWxsLWNvbnRyb2xdIHJlbGF5IC8gb24gYnVzeSAvIG5vIGFuc3dlciAtLT4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJhbnN3ZXIiLz4KICAgICAgICA8IS0tIDxhY3Rpb24gYXBwbGljYXRpb249Imx1YSIgZGF0YT0iYXBwLmx1YSBmYWlsdXJlX2hhbmRsZXIiLz4gLS0+CiAgICA8L2NvbmRpdGlvbj4KPC9leHRlbnNpb24+','base64'),'UTF8'), update_date=now() where dialplan_uuid='1c4b0b2f-9b88-4f4f-b5f5-f50bb73552f9';
insert into v_dialplans (domain_uuid,dialplan_uuid,app_uuid,dialplan_context,dialplan_name,dialplan_number,dialplan_continue,dialplan_xml,dialplan_order,dialplan_enabled,dialplan_description,insert_date)
 values (null,'e2dc2aa5-3d68-4a46-b17d-c0085da71330','e2dc2aa5-3d68-4a46-b17d-c0085da71330','global','ringq-call-control','','true',convert_from(decode('PGV4dGVuc2lvbiBuYW1lPSJyaW5ncS1jYWxsLWNvbnRyb2wiIGNvbnRpbnVlPSJ0cnVlIiB1dWlkPSJlMmRjMmFhNS0zZDY4LTRhNDYtYjE3ZC1jMDA4NWRhNzEzMzAiPgogICAgPCEtLSBbcmluZ3EtY2FsbC1jb250cm9sXSBhcHBseSB0aGUgZXh0ZW5zaW9uJ3MgQ2FsbCBDb250cm9sIHNldHRpbmdzIGZvciBpdHMgY3VycmVudCBjYWxsIHByb2ZpbGUgLS0+CiAgICA8Y29uZGl0aW9uIGZpZWxkPSIke3VzZXJfZXhpc3RzfSIgZXhwcmVzc2lvbj0iXnRydWUkIj4KICAgICAgICA8YWN0aW9uIGFwcGxpY2F0aW9uPSJzZXQiIGRhdGE9InJxX2NjPSR7bHVhKHJpbmdxX2NhbGxfY29udHJvbC5sdWEgcmVzb2x2ZSAke3V1aWR9KX0iIGlubGluZT0idHJ1ZSIvPgogICAgPC9jb25kaXRpb24+CjwvZXh0ZW5zaW9uPg==','base64'),'UTF8'),36,'true','RingQ Call Control per call profile (forwarding, relay, parallel ring, screening)',now());
commit;

insert into v_dialplans (domain_uuid,dialplan_uuid,app_uuid,dialplan_context,dialplan_name,dialplan_number,dialplan_continue,dialplan_xml,dialplan_order,dialplan_enabled,dialplan_description,insert_date)
values (null,'c0d47518-70a7-4cbe-b71c-224f576fa7cd','c0d47518-70a7-4cbe-b71c-224f576fa7cd','global','ringq-trunk-media-timeout','','true',convert_from(decode('PGV4dGVuc2lvbiBuYW1lPSJyaW5ncS10cnVuay1tZWRpYS10aW1lb3V0IiBjb250aW51ZT0idHJ1ZSIgdXVpZD0iYzBkNDc1MTgtNzBhNy00Y2JlLWI3MWMtMjI0ZjU3NmZhN2NkIj4KICAgIDwhLS0gW3RydW5rLW1lZGlhLXRpbWVvdXRdIG91dGJvdW5kIGNhbGxzIGZyb20gYW4gZXh0ZW5zaW9uIHRvIHRoZSB0cnVuazogaWYgdGhlIGNhcnJpZXIgc3RvcHMKICAgICAgICAgc2VuZGluZyBhdWRpbyBmb3IgMTAgcyAodGhlIGZhciBlbmQgaHVuZyB1cCBidXQgdGhlIGNhcnJpZXIncyBCWUUgaXMgbGF0ZSksIGVuZCB0aGUgY2FsbC4KICAgICAgICAgQXBwbGllcyBvbmx5IHRvIHRoZSB0cnVuayBsZWcgKG5vbG9jYWw6KSwgbm90IHRvIHRoZSBleHRlbnNpb24ncyBvd24gbGVnLiAtLT4KICAgIDxjb25kaXRpb24gZmllbGQ9IiR7ZnJvbV91c2VyX2V4aXN0c30iIGV4cHJlc3Npb249Il50cnVlJCIvPgogICAgPGNvbmRpdGlvbiBmaWVsZD0iJHt1c2VyX2V4aXN0c30iIGV4cHJlc3Npb249Il5mYWxzZSQiPgogICAgICAgIDxhY3Rpb24gYXBwbGljYXRpb249ImV4cG9ydCIgZGF0YT0ibm9sb2NhbDptZWRpYV90aW1lb3V0PTEwMDAwIiBpbmxpbmU9InRydWUiLz4KICAgIDwvY29uZGl0aW9uPgo8L2V4dGVuc2lvbj4=','base64'),'UTF8'),37,'true','End trunk calls 10 s after the carrier stops sending audio (late BYE)',now());

CREATE INDEX IF NOT EXISTS q_ringq_cdr_dom_insert_idx ON q_ringq_cdr (domain_uuid, insert_date DESC);
CREATE INDEX IF NOT EXISTS q_ringq_cdr_dom_dest_idx ON q_ringq_cdr (domain_uuid, caller_destination);
CREATE INDEX IF NOT EXISTS q_ringq_cdr_dom_cid_idx ON q_ringq_cdr (domain_uuid, caller_id_number);
CREATE INDEX IF NOT EXISTS q_ringq_cdr_dom_src_idx ON q_ringq_cdr (domain_uuid, source_number);
CREATE INDEX IF NOT EXISTS q_ringq_cdr_ccagent_idx ON q_ringq_cdr (cc_agent);

begin;

delete from v_dialplans where dialplan_uuid = '7b0e6b1c-2f4e-4f7a-9d8e-5a1c3f9b2e01';
insert into v_dialplans (domain_uuid, dialplan_uuid, app_uuid, dialplan_context, dialplan_name,
    dialplan_number, dialplan_continue, dialplan_xml, dialplan_order, dialplan_enabled,
    dialplan_description, insert_date)
values (null, '7b0e6b1c-2f4e-4f7a-9d8e-5a1c3f9b2e01', '7b0e6b1c-2f4e-4f7a-9d8e-5a1c3f9b2e01',
    'ringq_ring', 'ringq-ring', '', 'false',
'<extension name="ringq-ring" continue="false" uuid="7b0e6b1c-2f4e-4f7a-9d8e-5a1c3f9b2e01">
    <!-- [ringq-ring] every ring of an extension: push its mobile devices, wait for the app, ring -->
    <condition field="destination_number" expression="^ringq_ring\+">
        <action application="lua" data="ringq_ring.lua"/>
    </condition>
</extension>',
    1, 'true', 'RingQ ring push: loopback leg that pushes and rings an extension', now());

insert into v_default_settings (default_setting_uuid, default_setting_category, default_setting_subcategory,
    default_setting_name, default_setting_value, default_setting_enabled, default_setting_description)
select '3d5f2a9e-8c41-4b6e-a7f2-0e9c1b4d6a73', 'ringq', 'ring_push_mode', 'text', 'off', 'true',
    'Ring push: off | event (log only) | wake (push mobile apps and wait for them to register)'
where not exists (select 1 from v_default_settings
    where default_setting_category = 'ringq' and default_setting_subcategory = 'ring_push_mode');

commit;
