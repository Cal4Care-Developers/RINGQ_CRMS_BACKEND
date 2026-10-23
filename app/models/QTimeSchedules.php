<?php

class QTimeSchedules extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $work_default;

    /**
     *
     * @var string
     */
    public $work_schedule;

    /**
     *
     * @var string
     */
    public $break_default;

    /**
     *
     * @var string
     */
    public $break_schedule;

    /**
     *
     * @var string
     */
    public $special_schedule;

    /**
     *
     * @var string
     */
    public $insert_uuid;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $updated_uuid;

    /**
     *
     * @var string
     */
    public $update_date;

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $work_extensions;

    /**
     *
     * @var string
     */
    public $break_extensions;

    /**
     *
     * @var string
     */
    public $special_extensions;

    /**
     *
     * @var string
     */
    public $special_default;

    /**
     *
     * @var string
     */
    public $special_filename;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_time_schedules");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QTimeSchedules[]|QTimeSchedules|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QTimeSchedules|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
