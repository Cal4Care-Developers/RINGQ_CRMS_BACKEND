<?php

class QSecurityRestrictions extends \Phalcon\Mvc\Model
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
    public $blocked_countries;

    /**
     *
     * @var string
     */
    public $blocked_ips;

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $allowed_ips;

    /**
     *
     * @var string
     */
    public $blocked_descriptions;

    /**
     *
     * @var string
     */
    public $allowed_descriptions;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_security_restrictions");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QSecurityRestrictions[]|QSecurityRestrictions|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QSecurityRestrictions|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
