<?php

class QCompanies extends \Phalcon\Mvc\Model
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
    public $user_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $company_name;

    /**
     *
     * @var string
     */
    public $company_email;

    /**
     *
     * @var string
     */
    public $address_1;

    /**
     *
     * @var string
     */
    public $address_2;

    /**
     *
     * @var string
     */
    public $phone;

    /**
     *
     * @var string
     */
    public $city;

    /**
     *
     * @var string
     */
    public $state;

    /**
     *
     * @var string
     */
    public $country;

    /**
     *
     * @var string
     */
    public $extension_length;

    /**
     *
     * @var string
     */
    public $fax;

    /**
     *
     * @var string
     */
    public $contact_person;

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
    public $uid;

    /**
     *
     * @var string
     */
    public $uuid;

    /**
     *
     * @var string
     */
    public $token;

    /**
     *
     * @var integer
     */
    public $owner;

    /**
     *
     * @var integer
     */
    public $manager;

    /**
     *
     * @var integer
     */
    public $user;

    /**
     *
     * @var integer
     */
    public $queue;

    /**
     *
     * @var integer
     */
    public $storage;

    /**
     *
     * @var integer
     */
    public $devices;

    /**
     *
     * @var string
     */
    public $packageName;

    /**
     *
     * @var integer
     */
    public $packageId;

    /**
     *
     * @var string
     */
    public $zip;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_companies");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCompanies[]|QCompanies|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCompanies|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
