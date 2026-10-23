<?php

class QSecurityRules extends \Phalcon\Mvc\Model
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
    public $security_uuid;

    /**
     *
     * @var string
     */
    public $pin_all_outbound;

    /**
     *
     * @var string
     */
    public $prefixes;

    /**
     *
     * @var string
     */
    public $trusted_device;

    /**
     *
     * @var string
     */
    public $countries;

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $pin_no_outbound;

    /**
     *
     * @var string
     */
    public $pin_prefix_outbound;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_security_rules");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QSecurityRules[]|QSecurityRules|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QSecurityRules|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
