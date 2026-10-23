<?php

class QDevices extends \Phalcon\Mvc\Model
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
    public $user_uuid;

    /**
     *
     * @var integer
     */
    public $device_type;

    /**
     *
     * @var string
     */
    public $device_name;

    /**
     *
     * @var string
     */
    public $device_version;

    /**
     *
     * @var string
     */
    public $push_token;

    /**
     *
     * @var string
     */
    public $update_login;

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
    public $extension;

    /**
     *
     * @var string
     */
    public $version;

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var integer
     */
    public $enabled;

    /**
     *
     * @var integer
     */
    public $enabled2;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_devices");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QDevices[]|QDevices|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QDevices|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
