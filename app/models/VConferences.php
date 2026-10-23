<?php

class VConferences extends \Phalcon\Mvc\Model
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
    public $conference_uuid;

    /**
     *
     * @var string
     */
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $conference_name;

    /**
     *
     * @var string
     */
    public $conference_extension;

    /**
     *
     * @var string
     */
    public $conference_pin_number;

    /**
     *
     * @var string
     */
    public $conference_profile;

    /**
     *
     * @var string
     */
    public $conference_email_address;

    /**
     *
     * @var string
     */
    public $conference_account_code;

    /**
     *
     * @var string
     */
    public $conference_flags;

    /**
     *
     * @var double
     */
    public $conference_order;

    /**
     *
     * @var string
     */
    public $conference_description;

    /**
     *
     * @var string
     */
    public $conference_enabled;

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
        $this->setSource("v_conferences");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VConferences[]|VConferences|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VConferences|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
