<?php
// MR hotel front desk update - 2026-09-12 - starts
// Source of truth for "is this room checked in" - deliberately separate
// from q_users/v_extensions. Every extension already has a real
// staff/device first/last name from normal account setup, so occupied
// status can never be inferred from that field; a row existing here IS the
// occupied signal. Checkout deletes the row here (see HotelController) and,
// as the one exception, also resets the real extension's directory name.

class QHotelCheckins extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $hotel_checkin_uuid;

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
     * @var string
     */
    public $guest_firstname;

    /**
     *
     * @var string
     */
    public $guest_lastname;

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
        $this->setSource("q_hotel_checkins");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QHotelCheckins[]|QHotelCheckins|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QHotelCheckins|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
// MR hotel front desk update - 2026-09-12 - ends
