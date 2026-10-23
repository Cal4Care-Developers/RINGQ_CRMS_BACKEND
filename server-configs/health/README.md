# RingQ health check

`ringq-health.sh` runs every minute and writes to `/var/log/ringq-health.log`.
It lives outside the RingQ release folders, so central updates do not overwrite it.

Install:
    install -m 755 ringq-health.sh /usr/local/sbin/ringq-health.sh
    echo '* * * * * root /usr/local/sbin/ringq-health.sh' > /etc/cron.d/ringq-health
Optional alerts (mail/webhook): add `ALERT_CMD="..."` to the cron line, e.g.
    * * * * * root ALERT_CMD='mail -s RingQ-alert ops@example.com' /usr/local/sbin/ringq-health.sh

Checks: phone-side SIP profile (auto-heals: start profile, then restart FreeSWITCH when no calls
or after 10 min), trunk-side profile, trunks that stopped being registered, PM2 services restarting
more than 3 times in 15 min or not online, disk over 90 %.

Root cause to fix in /api/gateway (trunk save): use `sofia profile external rescan` for new trunks,
`killgw <gw>` + `rescan` for edits, `killgw` for deletes -- never restart all profiles.

## Trunk save fix (node/signal.js `gatewaystart`)
`/api/gateway` asks signal.js to reload trunks. It used to restart every SIP profile (internal too)
when a trunk's proxy/transport changed. Now it only does `reloadxml`, `killgw <trunk>` and `rescan`
on the trunk's own profile -- no profile restart, phones stay registered, other calls continue.
Only the local PHP API can trigger it (browser sockets via nginx are ignored).
