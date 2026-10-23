# Trunk media timeout (late BYE from the carrier)

Problem (3 Oct 2026, calncall trunk): when the far end of an outbound call hangs up, the carrier
stops sending audio at once but sends the SIP BYE about 60 s later, so the RingQ user stays "in call".
Packet capture: carrier RTP stopped 12:02:19, BYE arrived 12:03:19.

Fix: global dialplan `ringq-trunk-media-timeout` (order 37) exports `nolocal:media_timeout=10000`
for calls from an extension to a non-extension (the trunk leg only), so the call ends 10 s after
the carrier's audio stops. Install: run `install.sql`, then `rm /var/cache/ringq/dialplan.*`.
