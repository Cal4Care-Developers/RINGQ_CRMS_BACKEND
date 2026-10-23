<?php

class VConferenceRooms extends \Phalcon\Mvc\Model
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
    public $conference_room_uuid;

    /**
     *
     * @var string
     */
    public $conference_center_uuid;

    /**
     *
     * @var string
     */
    public $conference_room_name;

    /**
     *
     * @var string
     */
    public $profile;

    /**
     *
     * @var string
     */
    public $record;

    /**
     *
     * @var string
     */
    public $moderator_pin;

    /**
     *
     * @var string
     */
    public $participant_pin;

    /**
     *
     * @var double
     */
    public $max_members;

    /**
     *
     * @var string
     */
    public $start_datetime;

    /**
     *
     * @var string
     */
    public $stop_datetime;

    /**
     *
     * @var string
     */
    public $wait_mod;

    /**
     *
     * @var string
     */
    public $moderator_endconf;

    /**
     *
     * @var string
     */
    public $announce_name;

    /**
     *
     * @var string
     */
    public $announce_count;

    /**
     *
     * @var string
     */
    public $announce_recording;

    /**
     *
     * @var string
     */
    public $sounds;

    /**
     *
     * @var string
     */
    public $mute;

    /**
     *
     * @var string
     */
    public $created;

    /**
     *
     * @var string
     */
    public $created_by;

    /**
     *
     * @var string
     */
    public $email_address;

    /**
     *
     * @var string
     */
    public $account_code;

    /**
     *
     * @var string
     */
    public $enabled;

    /**
     *
     * @var string
     */
    public $description;

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
        $this->setSource("v_conference_rooms");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VConferenceRooms[]|VConferenceRooms|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VConferenceRooms|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
