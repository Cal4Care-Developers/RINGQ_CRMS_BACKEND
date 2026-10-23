-- [queue-tiers] agent order + skill level per queue, written by signal.js (setQueueTiers).
-- The queue save API stores the skill in v_call_center_tiers.tier_position (level-1) and drops the
-- drag order, so the real values are kept here and used by callcenter.conf.lua and live tier set.
CREATE TABLE IF NOT EXISTS q_call_center_tier_order (
    call_center_queue_uuid uuid NOT NULL,
    call_center_agent_uuid uuid NOT NULL,
    domain_uuid uuid,
    tier_level integer NOT NULL DEFAULT 1,
    tier_position integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (call_center_queue_uuid, call_center_agent_uuid)
);
