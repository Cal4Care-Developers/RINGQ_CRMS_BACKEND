# WhatsApp calling (wasip): technical reference

Setup guide for admins: [setup/WHATSAPP_SETUP.md](../setup/WHATSAPP_SETUP.md).

## Files

| Part | File |
|---|---|
| Controller | `app/controllers/WasipController.php` |
| Access rule | `app/config/services.php` → `$privately["wasip"]` |
| Schema | `app/scripts/wasip/2026_10_23_wasip.sql` |
| Frontend (RINGQ_CRMS_FRONTEND) | `src/integrations/wasip/` (dialog, card badge, API client) and the WhatsApp card in `src/components/IntegrationsModal.tsx` (Micro apps tab) |

The frontend module is the React port of the old standalone `/wasip` page (`index.php`, Phalcon Micro, Bearer token). The controller replaces that page's API: same Meta and FreeSWITCH steps, but authenticated by the RingQ session and scoped **per tenant** (`domain_uuid`).

## Endpoints

All need a signed-in RingQ session (cookie). Responses: `{"status": 200|400|500, "message": "…", "data": …}`; validation errors are HTTP 200 with `status: 400`.

| Method | Path | Does |
|---|---|---|
| GET | `/wasip/health` | DB / PHP / Phalcon check |
| GET | `/wasip/load` | Saved config (no secrets: `hasToken`, `hasSecret`, `hasPassword` only) + `extensions`, `queues`, `ivrs` for the destination list |
| GET | `/wasip/destinations` | Only the destination lists |
| GET | `/wasip/status` | `gatewayStatus`, `waNumber`, `gatewayInDB`, `dialplanInDB`, `extensionNumber`, `pbxDomain`, `lastConfigured` |
| POST | `/wasip/configure` | First-time provisioning (body below). `data: {success, steps[], error?}` |
| POST | `/wasip/reconfigure` | Re-point Meta at `pbxDomain`, new SIP password, rebuild DID route. Body: `pbxDomain`, `extensionNumber`, `extensionUuid` |
| POST | `/wasip/deactivate` | Disable SIP on Meta, remove gateway, DID, dialplan and Meta ACL nodes |

`configure` body (JSON; the frontend sends it as `application/x-www-form-urlencoded` to avoid a CORS preflight, the controller reads it with `getJsonRawBody()`):

```json
{ "accessToken": "EAA… or '' to keep", "appSecret": "", "phoneNumberId": "1214061478463577",
  "businessAcctId": "3668511866622780", "waNumber": "6567729965", "pbxDomain": "tenant.ringq.ai",
  "pbxSipPort": 5061, "extensionNumber": "1001", "extensionUuid": "…" }
```

Each `steps[]` item is `{"s": "ok"|"warn"|"err", "m": "message"}`.

## What configure does

1. Upserts `wa_gateway_config` (one row per `domain_uuid`), token and secret AES-256-CBC encrypted with `WASIP_ENC_KEY`.
2. Meta Graph `v21.0`: `GET {phone-number-id}` → `POST {id}/settings` calling `ENABLED` → SIP `ENABLED` with server `{pbxDomain}:{port}` → `srtp_key_exchange_protocol: SDES` → `GET {id}/settings?include_sip_credentials=true` for the SIP password.
3. `v_gateways`: gateway `watrunk_{waNumber}` (TLS, OPUS, register, profile `internal`).
4. `v_access_control_nodes`: Meta IP ranges (`WasipController::META_IPS`) on the `providers` ACL.
5. `q_dids` + `v_dialplans` / `v_dialplan_details` in context `public`: number `{waNumber}` → transfer to the destination in the tenant's domain; sets `call_source=whatsapp` and caller name `WA: {number}`.
6. Clears `/var/cache/ringq`, `fs_cli reloadxml`, `reloadacl`, `sofia profile internal|external rescan`.
7. `gateway_status = 'active'`.

## Security

- Admin-only screen in the app; the controller trusts the session's `domain_uuid` (never a client value).
- Tokens never returned to the browser or written to logs.
- `WASIP_ENC_KEY` must stay the same after the first save, or stored tokens cannot be decrypted (re-enter the token then).
- The controller runs `fs_cli` and clears `/var/cache/ringq` with `exec`; PHP-FPM's user needs those rights, as for the old page.
