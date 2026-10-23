# BLF keys on desk phones

The extension page's "Busy Lamp Fields" rows (table `q_blfs`) are added to each desk phone's config when the phone downloads it (`app/library/PhoneKeys.php`, called from `IndexController::indexAction`).

| Row type | Key |
| --- | --- |
| BLF | Lamp for the watched extension's SIP user (number_alias). Pressing it while that extension rings picks up the call (`*8<SIP user>`, Snom and Yealink). |
| Speed Dial | Dials the number (voicemail values dial `*99<ext>`). |
| Shared Parking, Agent Status, Change Status | No key (these are app actions with no dial code). |

Brands: Yealink linekeys, Snom fkeys (label and short label), Fanvil DSS keys, Grandstream GRP26xx line keys and GXP21xx MPK. Key 1 stays the line, so row n is key n+1.

## Server step: pickup dial plan

`blf-pickup.sql` adds the global dial plan `blf_sip_user_pickup`, which answers `*8<SIP user>` by intercepting the call that `local_extension` saved under `${domain_name}-last_dial_ext/<extension number>`. Run it once per server, then clear the domain's dial plan cache:

```
sudo -u postgres psql -d ringq -v ON_ERROR_STOP=1 -f blf-pickup.sql
rm -f /var/cache/ringq/dialplan.<domain> /var/cache/fusionpbx/dialplan.<domain>
```

## Other files

- `install-on-apple.sh`, `patch-blf.php`: install on the apple test server by patching its live controllers in place.
- `check-*.sh`: read-only checks used while testing on apple.
