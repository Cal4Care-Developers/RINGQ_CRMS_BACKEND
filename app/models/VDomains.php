<?php

use Phalcon\Validation;
use Phalcon\Validation\Validator\Uniqueness;

class VDomains extends \Phalcon\Mvc\Model
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
    public $domain_parent_uuid;

    /**
     *
     * @var string
     */
    public $domain_name;

    /**
     *
     * @var boolean
     */
    public $domain_enabled;

    /**
     *
     * @var string
     */
    public $domain_description;

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
        $this->setSource("v_domains");
    }

    public function validation()
    {
        $validator = new Validation();
        $validator->add(
            "domain_name",
            new Uniqueness(
                [
                    "message" => "Domain name already been taken.",
                ]
            )
        );
        return $this->validate($validator);
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDomains[]|VDomains|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VDomains|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
