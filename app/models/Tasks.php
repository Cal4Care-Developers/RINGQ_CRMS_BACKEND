<?php

class Tasks extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $task_id;

    /**
     *
     * @var string
     */
    public $task_desc;

    /**
     *
     * @var string
     */
    public $task_group;

    /**
     *
     * @var integer
     */
    public $task_runtime;

    /**
     *
     * @var integer
     */
    public $task_sql_manager;

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
        $this->setSource("tasks");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Tasks[]|Tasks|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Tasks|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
