# Queue strategy mapping

The RingQ apps save strategy names that mod_callcenter does not know (it silently falls back to
one-at-a-time in tier order). `callcenter.conf.lua` (FreeSWITCH xml_handler, copy to
`/usr/share/freeswitch/scripts/app/xml_handler/resources/scripts/configuration/`) now maps them:

| App option | mod_callcenter | tier-rules-apply |
|---|---|---|
| Ring All / Random / Round Robin | ring-all / random / round-robin | as saved |
| Longest Waiting Time | longest-idle-agent | as saved |
| Least Talk Time | agent-with-least-talk-time | as saved |
| Fewest Answered | agent-with-fewest-calls | as saved |
| Skill Based Ring All / Random / Round Robin / Fewest Answered | ring-all / random / round-robin / agent-with-fewest-calls | true (wait 15 s if unset) |

Skill = the agent's tier level (level 1 first; next level after tier-rule-wait-second).
After install: `rm /var/cache/ringq/configuration.callcenter.conf.*` and `callcenter_config queue reload <queue>`.

## Agent order and skill (tiers)
The queue save API stores the skill in `v_call_center_tiers.tier_position` (skill-1), always level 0,
and drops the drag order. Install `q_call_center_tier_order.sql`. The web app then sends order + skill
to signal.js (`setQueueTiers`, allowed only for a logged-in Owner/Manager session, checked against
`/api/index`), which stores them in that table and applies them live (`callcenter_config tier set`).
`callcenter.conf.lua` uses the table when present (else level = tier_position + 1).
