<?php

class VIvrMenus extends \Phalcon\Mvc\Model
{

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
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $ivr_menu_name;

    /**
     *
     * @var string
     */
    public $ivr_menu_extension;

    /**
     *
     * @var string
     */
    public $ivr_menu_parent_uuid;

    /**
     *
     * @var string
     */
    public $ivr_menu_language;

    /**
     *
     * @var string
     */
    public $ivr_menu_dialect;

    /**
     *
     * @var string
     */
    public $ivr_menu_voice;

    /**
     *
     * @var string
     */
    public $ivr_menu_greet_long;

    /**
     *
     * @var string
     */
    public $ivr_menu_greet_short;

    /**
     *
     * @var string
     */
    public $ivr_menu_invalid_sound;

    /**
     *
     * @var string
     */
    public $ivr_menu_exit_sound;

    /**
     *
     * @var string
     */
    public $ivr_menu_pin_number;

    /**
     *
     * @var string
     */
    public $ivr_menu_confirm_macro;

    /**
     *
     * @var string
     */
    public $ivr_menu_confirm_key;

    /**
     *
     * @var string
     */
    public $ivr_menu_tts_engine;

    /**
     *
     * @var string
     */
    public $ivr_menu_tts_voice;

    /**
     *
     * @var double
     */
    public $ivr_menu_confirm_attempts;

    /**
     *
     * @var double
     */
    public $ivr_menu_timeout;

    /**
     *
     * @var string
     */
    public $ivr_menu_exit_app;

    /**
     *
     * @var string
     */
    public $ivr_menu_exit_data;

    /**
     *
     * @var double
     */
    public $ivr_menu_inter_digit_timeout;

    /**
     *
     * @var double
     */
    public $ivr_menu_max_failures;

    /**
     *
     * @var double
     */
    public $ivr_menu_max_timeouts;

    /**
     *
     * @var double
     */
    public $ivr_menu_digit_len;

    /**
     *
     * @var string
     */
    public $ivr_menu_direct_dial;

    /**
     *
     * @var string
     */
    public $ivr_menu_ringback;

    /**
     *
     * @var string
     */
    public $ivr_menu_cid_prefix;

    /**
     *
     * @var string
     */
    public $ivr_menu_context;

    /**
     *
     * @var string
     */
    public $ivr_menu_enabled;

    /**
     *
     * @var string
     */
    public $ivr_menu_description;

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
        $this->setSource("v_ivr_menus");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VIvrMenus[]|VIvrMenus|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VIvrMenus|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
