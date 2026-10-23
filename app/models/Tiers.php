<?php

class Tiers extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $queue;

    /**
     *
     * @var string
     */
    public $agent;

    /**
     *
     * @var string
     */
    public $state;

    /**
     *
     * @var integer
     */
    public $level;

    /**
     *
     * @var integer
     */
    public $position;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("tiers");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Tiers[]|Tiers|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Tiers|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
