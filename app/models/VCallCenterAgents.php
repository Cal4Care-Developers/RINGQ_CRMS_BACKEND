<?php

class VCallCenterAgents extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $call_center_agent_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $user_uuid;

    /**
     *
     * @var string
     */
    public $agent_name;

    /**
     *
     * @var string
     */
    public $agent_type;

    /**
     *
     * @var double
     */
    public $agent_call_timeout;

    /**
     *
     * @var string
     */
    public $agent_id;

    /**
     *
     * @var string
     */
    public $agent_password;

    /**
     *
     * @var string
     */
    public $agent_contact;

    /**
     *
     * @var string
     */
    public $agent_status;

    /**
     *
     * @var string
     */
    public $agent_logout;

    /**
     *
     * @var double
     */
    public $agent_max_no_answer;

    /**
     *
     * @var double
     */
    public $agent_wrap_up_time;

    /**
     *
     * @var double
     */
    public $agent_reject_delay_time;

    /**
     *
     * @var double
     */
    public $agent_busy_delay_time;

    /**
     *
     * @var string
     */
    public $agent_no_answer_delay_time;

    /**
     *
     * @var string
     */
    public $agent_record;

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
    public $queue_extension;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("v_call_center_agents");
        $this->hasOne(
            'user_uuid',
            QUsers::class,
            'user_uuid',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VCallCenterAgents[]|VCallCenterAgents|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VCallCenterAgents|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
