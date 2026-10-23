<?php

class MemberBoard extends \Phalcon\Mvc\Model
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
     * @var integer
     */
    public $answered_seconds;

    /**
     *
     * @var integer
     */
    public $abandoned_seconds;

    /**
     *
     * @var integer
     */
    public $answered_calls;

    /**
     *
     * @var integer
     */
    public $missed_calls;

    /**
     *
     * @var integer
     */
    public $talk_time;

    /**
     *
     * @var integer
     */
    public $waiting_wallboard;

    /**
     *
     * @var integer
     */
    public $answered_wallboard;

    /**
     *
     * @var integer
     */
    public $abandoned_wallboard;

    /**
     *
     * @var integer
     */
    public $total_wallboard;

    /**
     *
     * @var integer
     */
    public $waiting_seconds;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("member_board");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return MemberBoard[]|MemberBoard|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return MemberBoard|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
