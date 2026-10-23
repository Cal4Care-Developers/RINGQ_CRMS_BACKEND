<?php

class AgentDetails extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $name;

    /**
     *
     * @var string
     */
    public $instance_id;

    /**
     *
     * @var string
     */
    public $uuid;

    /**
     *
     * @var string
     */
    public $type;

    /**
     *
     * @var string
     */
    public $contact;

    /**
     *
     * @var string
     */
    public $status;

    /**
     *
     * @var string
     */
    public $state;

    /**
     *
     * @var integer
     */
    public $max_no_answer;

    /**
     *
     * @var integer
     */
    public $wrap_up_time;

    /**
     *
     * @var integer
     */
    public $reject_delay_time;

    /**
     *
     * @var integer
     */
    public $busy_delay_time;

    /**
     *
     * @var integer
     */
    public $no_answer_delay_time;

    /**
     *
     * @var integer
     */
    public $last_bridge_start;

    /**
     *
     * @var integer
     */
    public $last_bridge_end;

    /**
     *
     * @var integer
     */
    public $last_offered_call;

    /**
     *
     * @var integer
     */
    public $last_status_change;

    /**
     *
     * @var integer
     */
    public $no_answer_count;

    /**
     *
     * @var integer
     */
    public $calls_answered;

    /**
     *
     * @var integer
     */
    public $talk_time;

    /**
     *
     * @var integer
     */
    public $ready_time;

    /**
     *
     * @var integer
     */
    public $external_calls_count;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("agent_details");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return AgentDetails[]|AgentDetails|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return AgentDetails|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
