<?php

class VIvrMenuOptions extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $ivr_menu_option_uuid;

    /**
     *
     * @var string
     */
    public $ivr_menu_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $ivr_menu_option_digits;

    /**
     *
     * @var string
     */
    public $ivr_menu_option_action;

    /**
     *
     * @var string
     */
    public $ivr_menu_option_param;

    /**
     *
     * @var double
     */
    public $ivr_menu_option_order;

    /**
     *
     * @var string
     */
    public $ivr_menu_option_description;

    /**
     *
     * @var boolean
     */
    public $ivr_menu_option_enabled;

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
        $this->setSource("v_ivr_menu_options");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VIvrMenuOptions[]|VIvrMenuOptions|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VIvrMenuOptions|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
