<?php

class Calls extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $call_uuid;

    /**
     *
     * @var string
     */
    public $call_created;

    /**
     *
     * @var integer
     */
    public $call_created_epoch;

    /**
     *
     * @var string
     */
    public $caller_uuid;

    /**
     *
     * @var string
     */
    public $callee_uuid;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("calls");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Calls[]|Calls|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Calls|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
