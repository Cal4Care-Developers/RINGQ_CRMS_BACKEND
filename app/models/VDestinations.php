<?php

class VDestinations extends \Phalcon\Mvc\Model
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
    public $destination_uuid;

    /**
     *
     * @var string
     */
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $fax_uuid;

    /**
     *
     * @var string
     */
    public $user_uuid;

    /**
     *
     * @var string
     */
    public $group_uuid;

    /**
     *
     * @var string
     */
    public $destination_type;

    /**
     *
     * @var string
     */
    public $destination_number;

    /**
     *
     * @var string
     */
    public $destination_trunk_prefix;

    /**
     *
     * @var string
     */
    public $destination_area_code;

    /**
     *
     * @var string
     */
    public $destination_prefix;

    /**
     *
     * @var string
     */
    public $destination_condition_field;

    /**
     *
     * @var string
     */
    public $destination_number_regex;

    /**
     *
     * @var string
     */
    public $destination_caller_id_name;

    /**
     *
     * @var string
     */
    public $destination_caller_id_number;

    /**
     *
     * @var string
     */
    public $destination_cid_name_prefix;

    /**
     *
     * @var string
     */
    public $destination_context;

    /**
     *
     * @var string
     */
    public $destination_record;

    /**
     *
     * @var string
     */
    public $destination_hold_music;

    /**
     *
     * @var string
     */
    public $destination_distinctive_ring;

    /**
     *
     * @var string
     */
    public $destination_accountcode;

    /**
     *
     * @var double
     */
    public $destination_type_voice;

    /**
     *
     * @var double
     */
    public $destination_type_fax;

    /**
     *
     * @var double
     */
    public $destination_type_emergency;

    /**
     *
     * @var double
     */
    public $destination_type_text;

    /**
     *
     * @var string
     */
    public $destination_conditions;

    /**
     *
     * @var string
     */
    public $destination_actions;

    /**
     *
     * @var string
     */
    public $destination_app;

    /**
     *
     * @var string
     */
    public $destination_data;

    /**
     *
     * @var string
     */
    public $destination_alternate_app;

    /**
     *
     * @var string
     */
    public $destination_alternate_data;

    /**
     *
     * @var double
     */
    public $destination_order;

    /**
     *
     * @var string
     */
    public $destination_enabled;

    /**
     *
     * @var string
     */
    public $destination_description;

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
        $this->setSource("v_destinations");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDestinations[]|VDestinations|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDestinations|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
