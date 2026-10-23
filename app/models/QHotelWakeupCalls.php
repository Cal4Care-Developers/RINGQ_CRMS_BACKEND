<?php
// MR hotel front desk update - 2026-09-12 - starts

class QHotelWakeupCalls extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $hotel_wakeup_call_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $extension_uuid;

    /**
     *
     * @var string
     */
    public $extension;

    /**
     *
     * @var integer
     */
    public $wake_hour;

    /**
     *
     * @var integer
     */
    public $wake_minute;

    /**
     *
     * @var string
     */
    public $wake_ampm;

    /**
     *
     * @var string
     */
    public $wake_datetime;

    /**
     *
     * @var integer
     */
    public $status;

    /**
     *
     * @var integer
     */
    public $attempts;

    /**
     *
     * @var string
     */
    public $insert_user;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_hotel_wakeup_calls");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QHotelWakeupCalls[]|QHotelWakeupCalls|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QHotelWakeupCalls|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
// MR hotel front desk update - 2026-09-12 - ends
