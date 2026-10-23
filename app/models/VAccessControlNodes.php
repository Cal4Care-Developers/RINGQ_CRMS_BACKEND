<?php

class VAccessControlNodes extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $access_control_node_uuid;

    /**
     *
     * @var string
     */
    public $access_control_uuid;

    /**
     *
     * @var string
     */
    public $node_type;

    /**
     *
     * @var string
     */
    public $node_cidr;

    /**
     *
     * @var string
     */
    public $node_description;

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
        $this->setSource("v_access_control_nodes");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VAccessControlNodes[]|VAccessControlNodes|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VAccessControlNodes|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
