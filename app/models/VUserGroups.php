<?php

class VUserGroups extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $user_group_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $group_name;

    /**
     *
     * @var string
     */
    public $group_uuid;

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
        $this->setSource("v_user_groups");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VUserGroups[]|VUserGroups|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VUserGroups|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
