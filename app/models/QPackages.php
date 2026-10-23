<?php

class QPackages extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var string
     */
    public $package_name;

    /**
     *
     * @var integer
     */
    public $no_of_agents;

    /**
     *
     * @var integer
     */
    public $no_of_dids;

    /**
     *
     * @var double
     */
    public $package_price;

    /**
     *
     * @var string
     */
    public $description;

    /**
     *
     * @var string
     */
    public $agent_name;

    /**
     *
     * @var string
     */
    public $did_name;

    /**
     *
     * @var string
     */
    public $airtime_name;

    /**
     *
     * @var string
     */
    public $agent_price;

    /**
     *
     * @var string
     */
    public $did_price;

    /**
     *
     * @var string
     */
    public $airtime_price;

    /**
     *
     * @var string
     */
    public $no_of_airtime;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var string
     */
    public $updated_at;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_packages");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QPackages[]|QPackages|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QPackages|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
