<?php

class VExtensionUsers extends \Phalcon\Mvc\Model
{
    /**
     *
     * @var string
     */
    public $extension_user_uuid;

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
    public $user_uuid;

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
        $this->setSource("v_extension_users");
        $this->hasOne(
            'extension_uuid',
            VExtensions::class,
            'extension_uuid',
        );
        $this->hasOne(
            'user_uuid',
            QUsers::class,
            'user_uuid',
        );
        $this->hasOne(
            'user_uuid',
            VUsers::class,
            'user_uuid',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VExtensionUsers[]|VExtensionUsers|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VExtensionUsers|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
