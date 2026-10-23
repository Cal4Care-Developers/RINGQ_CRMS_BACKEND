<?php

class QHolidays extends \Phalcon\Mvc\Model
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
    public $holiday_name;

    /**
     *
     * @var string
     */
    public $holiday_greeting;

    /**
     *
     * @var integer
     */
    public $month;

    /**
     *
     * @var integer
     */
    public $day;

    /**
     *
     * @var integer
     */
    public $year;

    /**
     *
     * @var integer
     */
    public $recurring;

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
     * @var string
     */
    public $extensions;

    /**
     *
     * @var integer
     */
    public $type;

    /**
     *
     * @var integer
     */
    public $day2;

    /**
     *
     * @var integer
     */
    public $month2;

    /**
     *
     * @var integer
     */
    public $year2;

    /**
     *
     * @var string
     */
    public $time;

    /**
     *
     * @var string
     */
    public $exemptions;

    /**
     *
     * @var string
     */
    public $holiday_uuid;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_holidays");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QHolidays[]|QHolidays|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QHolidays|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
