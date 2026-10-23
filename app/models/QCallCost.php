<?php

class QCallCost extends \Phalcon\Mvc\Model
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
    public $call_cost_uuid;

    /**
     *
     * @var string
     */
    public $xml_cdr_uuid;

    /**
     *
     * @var string
     */
    public $caller_id_name;

    /**
     *
     * @var string
     */
    public $caller_id_number;

    /**
     *
     * @var string
     */
    public $sip_call_id;

    /**
     *
     * @var string
     */
    public $direction;

    /**
     *
     * @var string
     */
    public $userId;

    /**
     *
     * @var string
     */
    public $callCostId;

    /**
     *
     * @var string
     */
    public $login;

    /**
     *
     * @var string
     */
    public $clientType;

    /**
     *
     * @var string
     */
    public $calledNumber;

    /**
     *
     * @var string
     */
    public $tariffName;

    /**
     *
     * @var string
     */
    public $tariffPrefix;

    /**
     *
     * @var string
     */
    public $tariffDescription;

    /**
     *
     * @var string
     */
    public $callStart;

    /**
     *
     * @var string
     */
    public $callEnd;

    /**
     *
     * @var string
     */
    public $callDuration;

    /**
     *
     * @var string
     */
    public $perMinCost;

    /**
     *
     * @var string
     */
    public $usageCost;

    /**
     *
     * @var string
     */
    public $call_cost_created_yr;

    /**
     *
     * @var string
     */
    public $call_cost_created_time;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $start_balance;

    /**
     *
     * @var string
     */
    public $end_balance;

    /**
     *
     * @var string
     */
    public $record_path;

    /**
     *
     * @var string
     */
    public $record_name;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_call_cost");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallCost[]|QCallCost|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallCost|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
