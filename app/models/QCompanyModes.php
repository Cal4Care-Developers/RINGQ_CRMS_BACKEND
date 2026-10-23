<?php

class QCompanyModes extends \Phalcon\Mvc\Model
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
    public $mode1;

    /**
     *
     * @var string
     */
    public $mode2;

    /**
     *
     * @var string
     */
    public $mode3;

    /**
     *
     * @var string
     */
    public $mode4;

    /**
     *
     * @var string
     */
    public $mode5;

    /**
     *
     * @var string
     */
    public $mode6;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $update_date;

    /**
     *
     * @var string
     */
    public $insert_uuid;

    /**
     *
     * @var string
     */
    public $update_uuid;

    /**
     *
     * @var string
     */
    public $permission1;

    /**
     *
     * @var string
     */
    public $permission2;

    /**
     *
     * @var string
     */
    public $permission3;

    /**
     *
     * @var string
     */
    public $permission4;

    /**
     *
     * @var string
     */
    public $permission5;

    /**
     *
     * @var string
     */
    public $permission6;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_company_modes");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCompanyModes[]|QCompanyModes|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCompanyModes|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
