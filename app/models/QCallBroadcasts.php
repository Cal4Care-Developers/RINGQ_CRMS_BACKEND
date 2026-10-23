<?php

class QCallBroadcasts extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $call_broadcast_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $broadcast_name;

    /**
     *
     * @var string
     */
    public $broadcast_description;

    /**
     *
     * @var double
     */
    public $broadcast_start_time;

    /**
     *
     * @var double
     */
    public $broadcast_timeout;

    /**
     *
     * @var double
     */
    public $broadcast_concurrent_limit;

    /**
     *
     * @var string
     */
    public $recording_uuid;

    /**
     *
     * @var string
     */
    public $broadcast_caller_id_name;

    /**
     *
     * @var string
     */
    public $broadcast_caller_id_number;

    /**
     *
     * @var string
     */
    public $broadcast_destination_type;

    /**
     *
     * @var string
     */
    public $broadcast_phone_numbers;

    /**
     *
     * @var string
     */
    public $broadcast_avmd;

    /**
     *
     * @var string
     */
    public $broadcast_destination_data;

    /**
     *
     * @var string
     */
    public $broadcast_accountcode;

    /**
     *
     * @var string
     */
    public $broadcast_toll_allow;

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
    public $status;

    /**
     *
     * @var integer
     */
    public $enabled;

    /**
     *
     * @var integer
     */
    public $counter;

    /**
     *
     * @var integer
     */
    public $length;

    /**
     *
     * @var string
     */
    public $broadcast_contacts;

    /**
     *
     * @var string
     */
    public $broadcast_contacts_uuid;

    /**
     *
     * @var string
     */
    public $broadcast_schedule;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_call_broadcasts");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallBroadcasts[]|QCallBroadcasts|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallBroadcasts|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
