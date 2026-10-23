<?php

class VCallCenterQueues extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $call_center_queue_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $queue_name;

    /**
     *
     * @var string
     */
    public $queue_extension;

    /**
     *
     * @var string
     */
    public $queue_greeting;

    /**
     *
     * @var string
     */
    public $queue_strategy;

    /**
     *
     * @var string
     */
    public $queue_moh_sound;

    /**
     *
     * @var string
     */
    public $queue_record_template;

    /**
     *
     * @var string
     */
    public $queue_time_base_score;

    /**
     *
     * @var double
     */
    public $queue_time_base_score_sec;

    /**
     *
     * @var double
     */
    public $queue_max_wait_time;

    /**
     *
     * @var double
     */
    public $queue_max_wait_time_with_no_agent;

    /**
     *
     * @var double
     */
    public $queue_max_wait_time_with_no_agent_time_reached;

    /**
     *
     * @var string
     */
    public $queue_tier_rules_apply;

    /**
     *
     * @var double
     */
    public $queue_tier_rule_wait_second;

    /**
     *
     * @var string
     */
    public $queue_tier_rule_no_agent_no_wait;

    /**
     *
     * @var string
     */
    public $queue_timeout_action;

    /**
     *
     * @var double
     */
    public $queue_discard_abandoned_after;

    /**
     *
     * @var string
     */
    public $queue_abandoned_resume_allowed;

    /**
     *
     * @var string
     */
    public $queue_tier_rule_wait_multiply_level;

    /**
     *
     * @var string
     */
    public $queue_cid_prefix;

    /**
     *
     * @var string
     */
    public $queue_outbound_caller_id_name;

    /**
     *
     * @var string
     */
    public $queue_outbound_caller_id_number;

    /**
     *
     * @var string
     */
    public $queue_announce_position;

    /**
     *
     * @var string
     */
    public $queue_announce_sound;

    /**
     *
     * @var double
     */
    public $queue_announce_frequency;

    /**
     *
     * @var string
     */
    public $queue_cc_exit_keys;

    /**
     *
     * @var string
     */
    public $queue_email_address;

    /**
     *
     * @var string
     */
    public $queue_description;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $insert_user;

    /**
     *
     * @var string
     */
    public $update_date;

    /**
     *
     * @var string
     */
    public $update_user;

    /**
     *
     * @var integer
     */
    public $queue_max_callers;

    /**
     *
     * @var integer
     */
    public $queue_auto_answer;

    /**
     *
     * @var integer
     */
    public $web_chat;

    /**
     *
     * @var integer
     */
    public $facebook_chat;

    /**
     *
     * @var integer
     */
    public $whatsapp_chat;

    /**
     *
     * @var string
     */
    public $queue_messsage_on_hold;

    /**
     *
     * @var string
     */
    public $queue_messsage_on_hold2;

    /**
     *
     * @var string
     */
    public $queue_messsage_on_hold3;

    /**
     *
     * @var double
     */
    public $queue_messsage_interval;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("v_call_center_queues");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VCallCenterQueues[]|VCallCenterQueues|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VCallCenterQueues|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
