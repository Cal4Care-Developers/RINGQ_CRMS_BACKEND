<?php

class AbandonedQueue extends \Phalcon\Mvc\Model
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
    public $queue;

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
    public $session_uuid;

    /**
     *
     * @var string
     */
    public $cid_number;

    /**
     *
     * @var string
     */
    public $cid_name;

    /**
     *
     * @var integer
     */
    public $system_epoch;

    /**
     *
     * @var integer
     */
    public $joined_epoch;

    /**
     *
     * @var integer
     */
    public $rejoined_epoch;

    /**
     *
     * @var integer
     */
    public $bridge_epoch;

    /**
     *
     * @var integer
     */
    public $abandoned_epoch;

    /**
     *
     * @var integer
     */
    public $base_score;

    /**
     *
     * @var integer
     */
    public $skill_score;

    /**
     *
     * @var string
     */
    public $serving_agent;

    /**
     *
     * @var string
     */
    public $serving_system;

    /**
     *
     * @var string
     */
    public $state;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("abandoned_queue");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return AbandonedQueue[]|AbandonedQueue|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return AbandonedQueue|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
