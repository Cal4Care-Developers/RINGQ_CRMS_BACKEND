<?php

class DetailedCalls extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $uuid;

    /**
     *
     * @var string
     */
    public $direction;

    /**
     *
     * @var string
     */
    public $created;

    /**
     *
     * @var integer
     */
    public $created_epoch;

    /**
     *
     * @var string
     */
    public $name;

    /**
     *
     * @var string
     */
    public $state;

    /**
     *
     * @var string
     */
    public $cid_name;

    /**
     *
     * @var string
     */
    public $cid_num;

    /**
     *
     * @var string
     */
    public $ip_addr;

    /**
     *
     * @var string
     */
    public $dest;

    /**
     *
     * @var string
     */
    public $application;

    /**
     *
     * @var string
     */
    public $application_data;

    /**
     *
     * @var string
     */
    public $dialplan;

    /**
     *
     * @var string
     */
    public $context;

    /**
     *
     * @var string
     */
    public $read_codec;

    /**
     *
     * @var string
     */
    public $read_rate;

    /**
     *
     * @var string
     */
    public $read_bit_rate;

    /**
     *
     * @var string
     */
    public $write_codec;

    /**
     *
     * @var string
     */
    public $write_rate;

    /**
     *
     * @var string
     */
    public $write_bit_rate;

    /**
     *
     * @var string
     */
    public $secure;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     *
     * @var string
     */
    public $presence_id;

    /**
     *
     * @var string
     */
    public $presence_data;

    /**
     *
     * @var string
     */
    public $accountcode;

    /**
     *
     * @var string
     */
    public $callstate;

    /**
     *
     * @var string
     */
    public $callee_name;

    /**
     *
     * @var string
     */
    public $callee_num;

    /**
     *
     * @var string
     */
    public $callee_direction;

    /**
     *
     * @var string
     */
    public $call_uuid;

    /**
     *
     * @var string
     */
    public $sent_callee_name;

    /**
     *
     * @var string
     */
    public $sent_callee_num;

    /**
     *
     * @var string
     */
    public $b_uuid;

    /**
     *
     * @var string
     */
    public $b_direction;

    /**
     *
     * @var string
     */
    public $b_created;

    /**
     *
     * @var integer
     */
    public $b_created_epoch;

    /**
     *
     * @var string
     */
    public $b_name;

    /**
     *
     * @var string
     */
    public $b_state;

    /**
     *
     * @var string
     */
    public $b_cid_name;

    /**
     *
     * @var string
     */
    public $b_cid_num;

    /**
     *
     * @var string
     */
    public $b_ip_addr;

    /**
     *
     * @var string
     */
    public $b_dest;

    /**
     *
     * @var string
     */
    public $b_application;

    /**
     *
     * @var string
     */
    public $b_application_data;

    /**
     *
     * @var string
     */
    public $b_dialplan;

    /**
     *
     * @var string
     */
    public $b_context;

    /**
     *
     * @var string
     */
    public $b_read_codec;

    /**
     *
     * @var string
     */
    public $b_read_rate;

    /**
     *
     * @var string
     */
    public $b_read_bit_rate;

    /**
     *
     * @var string
     */
    public $b_write_codec;

    /**
     *
     * @var string
     */
    public $b_write_rate;

    /**
     *
     * @var string
     */
    public $b_write_bit_rate;

    /**
     *
     * @var string
     */
    public $b_secure;

    /**
     *
     * @var string
     */
    public $b_hostname;

    /**
     *
     * @var string
     */
    public $b_presence_id;

    /**
     *
     * @var string
     */
    public $b_presence_data;

    /**
     *
     * @var string
     */
    public $b_accountcode;

    /**
     *
     * @var string
     */
    public $b_callstate;

    /**
     *
     * @var string
     */
    public $b_callee_name;

    /**
     *
     * @var string
     */
    public $b_callee_num;

    /**
     *
     * @var string
     */
    public $b_callee_direction;

    /**
     *
     * @var string
     */
    public $b_call_uuid;

    /**
     *
     * @var string
     */
    public $b_sent_callee_name;

    /**
     *
     * @var string
     */
    public $b_sent_callee_num;

    /**
     *
     * @var integer
     */
    public $call_created_epoch;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("detailed_calls");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return DetailedCalls[]|DetailedCalls|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return DetailedCalls|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
