<?php

class Registrations extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $reg_user;

    /**
     *
     * @var string
     */
    public $realm;

    /**
     *
     * @var string
     */
    public $token;

    /**
     *
     * @var string
     */
    public $url;

    /**
     *
     * @var integer
     */
    public $expires;

    /**
     *
     * @var string
     */
    public $network_ip;

    /**
     *
     * @var string
     */
    public $network_port;

    /**
     *
     * @var string
     */
    public $network_proto;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     *
     * @var string
     */
    public $metadata;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("registrations");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Registrations[]|Registrations|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Registrations|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
