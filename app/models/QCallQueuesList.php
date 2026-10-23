<?php

class QCallQueuesList extends \Phalcon\Mvc\Model
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
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $queue_uuid;

    /**
     *
     * @var string
     */
    public $call_uuid;

    /**
     *
     * @var string
     */
    public $caller_id_number;

    /**
     *
     * @var string
     */
    public $caller_id_name;

    /**
     *
     * @var string
     */
    public $qbc_call;

    /**
     *
     * @var string
     */
    public $callee_id_number;

    /**
     *
     * @var string
     */
    public $callee_id_name;

    /**
     *
     * @var string
     */
    public $call_direction;

    /**
     *
     * @var string
     */
    public $call_type;

    /**
     *
     * @var integer
     */
    public $call_duration;

    /**
     *
     * @var integer
     */
    public $call_start_time;

    /**
     *
     * @var integer
     */
    public $call_answered_time;

    /**
     *
     * @var integer
     */
    public $call_waiting_time;

    /**
     *
     * @var integer
     */
    public $call_end_time;

    /**
     *
     * @var integer
     */
    public $call_time_to_answer;

    /**
     *
     * @var string
     */
    public $wrapup_uuid;

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
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $insert_user;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_call_queues_list");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallQueuesList[]|QCallQueuesList|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallQueuesList|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
