# Incoming calls from a registered gateway

Problem (21 Oct 2026, suryatesting and apple): a gateway (GSM/FXO box, extension 100000-199999)
registers and signs in like an extension. A call it brought in from its line, dialled to a DID,
therefore ran the domain dialplan as an outbound call (user_exists=false) and matched an outbound
rule, so it went straight back out through the SIP trunk. It only worked when the gateway's IP
was added to the providers ACL, which skips sign-in and sends the call to the public dialplan.

Fix: global dialplan `ringq-gateway-inbound` (order 35, before call control and the trunk media
timeout) matches calls whose From user is a gateway extension (1xxxxx, from_user_exists=true)
to a number that is not an extension, takes the caller's number from the From display name when
it is numeric, and transfers the call to the public dialplan, where the DID routes it.
Installed by the maintenance run in `app/scripts/jobs.sh` (cm_dialplan), like the other global
dialplans. Manual install: insert `dialplan.xml` as a global dialplan, then `rm /var/cache/ringq/dialplan.*`.

Per-gateway Inbound Setting (like a SIP trunk's: From User, From Display, DID Match Type): saving
a gateway writes its own domain dialplan row `gateway-inbound` (dialplan_number = the gateway's
extension, order 34, the three choices as JSON in dialplan_description), see
`ControllerBase::saveGatewayInbound()`. It sets the caller's number and name from the chosen
headers (also into sip_from_user / sip_from_display, so a DID's trunk header settings read the
same values) and transfers the chosen number to the public dialplan. The global dialplan above
stays as the default for gateways that were never saved since.

Caller ID note: the caller-details dialplans (global order 15, public order 10) copy
caller_id_number / caller_id_name as channel variables, which win over the caller profile. So both
gateway dialplans also set those two channel variables (plain set, not inline) before the transfer.
