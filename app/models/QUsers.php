<?php

class QUsers extends \Phalcon\Mvc\Model
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
    public $user_uuid;

    /**
     *
     * @var string
     */
    public $password;

    /**
     *
     * @var integer
     */
    public $user_role;

    /**
     *
     * @var string
     */
    public $first_name;

    /**
     *
     * @var string
     */
    public $last_name;

    /**
     *
     * @var string
     */
    public $image;

    /**
     *
     * @var boolean
     */
    public $extension_enabled;

    /**
     *
     * @var string
     */
    public $mobile_type;

    /**
     *
     * @var boolean
     */
    public $mobile_enabled;

    /**
     *
     * @var string
     */
    public $push_token;

    /**
     *
     * @var boolean
     */
    public $voice_mail;

    /**
     *
     * @var boolean
     */
    public $call_screen;

    /**
     *
     * @var boolean
     */
    public $call_record;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $update_date;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_users");
        $this->hasOne(
            'user_uuid',
            QUsers::class,
            'user_uuid',
        );
        $this->hasOne(
            'user_uuid',
            VExtensionUsers::class,
            'user_uuid',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QUsers[]|QUsers|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QUsers|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
