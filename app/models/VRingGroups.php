<?php

class VRingGroups extends \Phalcon\Mvc\Model
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
    public $ring_group_uuid;

    /**
     *
     * @var string
     */
    public $ring_group_name;

    /**
     *
     * @var string
     */
    public $ring_group_extension;

    /**
     *
     * @var string
     */
    public $ring_group_greeting;

    /**
     *
     * @var string
     */
    public $ring_group_context;

    /**
     *
     * @var double
     */
    public $ring_group_call_timeout;

    /**
     *
     * @var string
     */
    public $ring_group_forward_destination;

    /**
     *
     * @var string
     */
    public $ring_group_forward_enabled;

    /**
     *
     * @var string
     */
    public $ring_group_caller_id_name;

    /**
     *
     * @var string
     */
    public $ring_group_caller_id_number;

    /**
     *
     * @var string
     */
    public $ring_group_cid_name_prefix;

    /**
     *
     * @var string
     */
    public $ring_group_cid_number_prefix;

    /**
     *
     * @var string
     */
    public $ring_group_strategy;

    /**
     *
     * @var string
     */
    public $ring_group_timeout_app;

    /**
     *
     * @var string
     */
    public $ring_group_timeout_data;

    /**
     *
     * @var string
     */
    public $ring_group_distinctive_ring;

    /**
     *
     * @var string
     */
    public $ring_group_ringback;

    /**
     *
     * @var string
     */
    public $ring_group_call_forward_enabled;

    /**
     *
     * @var string
     */
    public $ring_group_follow_me_enabled;

    /**
     *
     * @var string
     */
    public $ring_group_missed_call_app;

    /**
     *
     * @var string
     */
    public $ring_group_missed_call_data;

    /**
     *
     * @var string
     */
    public $ring_group_enabled;

    /**
     *
     * @var string
     */
    public $ring_group_description;

    /**
     *
     * @var string
     */
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $ring_group_forward_toll_allow;

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
        $this->setSource("v_ring_groups");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VRingGroups[]|VRingGroups|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VRingGroups|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
