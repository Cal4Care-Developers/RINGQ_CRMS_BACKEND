<?php

class VDialplanDetails extends \Phalcon\Mvc\Model
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
    public $dialplan_detail_uuid;

    /**
     *
     * @var string
     */
    public $dialplan_detail_tag;

    /**
     *
     * @var string
     */
    public $dialplan_detail_type;

    /**
     *
     * @var string
     */
    public $dialplan_detail_data;

    /**
     *
     * @var string
     */
    public $dialplan_detail_break;

    /**
     *
     * @var string
     */
    public $dialplan_detail_inline;

    /**
     *
     * @var double
     */
    public $dialplan_detail_group;

    /**
     *
     * @var double
     */
    public $dialplan_detail_order;

    /**
     *
     * @var boolean
     */
    public $dialplan_detail_enabled;

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
        $this->setSource("v_dialplan_details");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDialplanDetails[]|VDialplanDetails|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDialplanDetails|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
