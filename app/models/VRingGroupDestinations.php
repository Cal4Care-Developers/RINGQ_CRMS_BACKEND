<?php

class VRingGroupDestinations extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $ring_group_destination_uuid;

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
    public $destination_number;

    /**
     *
     * @var double
     */
    public $destination_delay;

    /**
     *
     * @var double
     */
    public $destination_timeout;

    /**
     *
     * @var boolean
     */
    public $destination_enabled;

    /**
     *
     * @var double
     */
    public $destination_prompt;

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
        $this->setSource("v_ring_group_destinations");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VRingGroupDestinations[]|VRingGroupDestinations|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VRingGroupDestinations|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
