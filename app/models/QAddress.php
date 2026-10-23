<?php

class QAddress extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $adrs_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $adrs_address;

    /**
     *
     * @var string
     */
    public $adrs_street_address_line_1;

    /**
     *
     * @var string
     */
    public $adrs_street_address_line_2;

    /**
     *
     * @var string
     */
    public $adrs_city;

    /**
     *
     * @var string
     */
    public $adrs_state;

    /**
     *
     * @var string
     */
    public $adrs_country;

    /**
     *
     * @var double
     */
    public $adrs_postal_code;

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
     *
     * @var integer
     */
    public $qid;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_address");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QAddress[]|QAddress|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QAddress|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
