<?php

class VDialplans extends \Phalcon\Mvc\Model
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
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $app_uuid;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     *
     * @var string
     */
    public $dialplan_context;

    /**
     *
     * @var string
     */
    public $dialplan_name;

    /**
     *
     * @var string
     */
    public $dialplan_number;

    /**
     *
     * @var string
     */
    public $dialplan_destination;

    /**
     *
     * @var string
     */
    public $dialplan_continue;

    /**
     *
     * @var string
     */
    public $dialplan_xml;

    /**
     *
     * @var double
     */
    public $dialplan_order;

    /**
     *
     * @var string
     */
    public $dialplan_enabled;

    /**
     *
     * @var string
     */
    public $dialplan_description;

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
        $this->setSource("v_dialplans");
        $this->hasMany(
            "dialplan_uuid",
            VDialplanDetails::class,
            "dialplan_uuid",
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDialplans[]|VDialplans|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDialplans|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
