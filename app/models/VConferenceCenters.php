<?php

class VConferenceCenters extends \Phalcon\Mvc\Model
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
    public $conference_center_uuid;

    /**
     *
     * @var string
     */
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $conference_center_name;

    /**
     *
     * @var string
     */
    public $conference_center_extension;

    /**
     *
     * @var double
     */
    public $conference_center_pin_length;

    /**
     *
     * @var string
     */
    public $conference_center_greeting;

    /**
     *
     * @var string
     */
    public $conference_center_description;

    /**
     *
     * @var string
     */
    public $conference_center_enabled;

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
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("v_conference_centers");
        $this->hasOne(
            'conference_center_uuid',
            VConferenceRooms::class,
            'conference_center_uuid',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VConferenceCenters[]|VConferenceCenters|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VConferenceCenters|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
