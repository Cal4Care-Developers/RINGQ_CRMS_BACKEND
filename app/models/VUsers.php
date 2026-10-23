<?php

use Phalcon\Validation;
use Phalcon\Validation\Validator\Uniqueness;

class VUsers extends \Phalcon\Mvc\Model
{
    /**
     *
     * @var string
     */
    public $user_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $contact_uuid;

    /**
     *
     * @var string
     */
    public $username;

    /**
     *
     * @var string
     */
    public $password;

    /**
     *
     * @var string
     */
    public $salt;

    /**
     *
     * @var string
     */
    public $user_email;

    /**
     *
     * @var string
     */
    public $user_status;

    /**
     *
     * @var string
     */
    public $api_key;

    /**
     *
     * @var string
     */
    public $user_totp_secret;

    /**
     *
     * @var string
     */
    public $user_type;

    /**
     *
     * @var string
     */
    public $user_enabled;

    /**
     *
     * @var string
     */
    public $add_user;

    /**
     *
     * @var string
     */
    public $add_date;

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
        $this->setSource("v_users");
        $this->hasOne(
            "user_uuid",
            VUserGroups::class,
            "user_uuid",
        );
        $this->hasOne(
            "domain_uuid",
            VDomains::class,
            "domain_uuid",
        );
        $this->hasOne(
            "user_uuid",
            QUsers::class,
            "user_uuid",
        );
        $this->hasOne(
            "user_uuid",
            VExtensionUsers::class,
            "user_uuid",
        );
    }
/*
    public function validation()
    {
        $validator = new Validation();
        $validator->add(
            "user_email",
            new Uniqueness(
                [
                    "message" => "Email already been used.",
                ]
            )
        );
        return $this->validate($validator);
    }
*/
    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VUsers[]|VUsers|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VUsers|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
