<?php

use Phalcon\Validation;

class QContacts extends \Phalcon\Mvc\Model
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
    public $title;

    /**
     *
     * @var string
     */
    public $first_name;

    /**
     *
     * @var string
     */
    public $middle_name;

    /**
     *
     * @var string
     */
    public $last_name;

    /**
     *
     * @var string
     */
    public $email;

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
    public $postal;

    /**
     *
     * @var string
     */
    public $phone;

    /**
     *
     * @var string
     */
    public $fax;

    /**
     *
     * @var string
     */
    public $timezone;

    /**
     *
     * @var string
     */
    public $language;

    /**
     *
     * @var string
     */
    public $notes;

    /**
     *
     * @var string
     */
    public $insert_uuid;

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
    public $phone2;

    /**
     *
     * @var string
     */
    public $office_phone;

    /**
     *
     * @var string
     */
    public $whatsapp_phone;

    /**
     *
     * @var string
     */
    public $email2;

    /**
     *
     * @var string
     */
    public $contacttype;

    /**
     *
     * @var string
     */
    public $createdby;

    /**
     *
     * @var string
     */
    public $createdate;

    /**
     * Validations and business logic
     *
     * @return boolean
     */
    public function validation()
    {
        $validator = new Validation();
        return $this->validate($validator);
    }

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_contacts");
        $this->hasMany(
            'id',
            QWrapupnotesList::class,
            'contact_id',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QContacts[]|QContacts|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QContacts|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
