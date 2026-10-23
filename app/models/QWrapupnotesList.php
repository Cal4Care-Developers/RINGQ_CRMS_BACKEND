<?php

class QWrapupnotesList extends \Phalcon\Mvc\Model
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
    public $wrapup_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $content;

    /**
     *
     * @var string
     */
    public $insert_date;

    /**
     *
     * @var string
     */
    public $user_uuid;

    /**
     *
     * @var string
     */
    public $extension;

    /**
     *
     * @var string
     */
    public $username;

    /**
     *
     * @var string
     */
    public $customer;

    /**
     *
     * @var string
     */
    public $contact;

    /**
     *
     * @var string
     */
    public $wrapup_code;

    /**
     *
     * @var integer
     */
    public $contact_id;

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
    public $queue_extension;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_wrapupnotes_list");
        $this->hasOne(
            'user_uuid',
            VUsers::class,
            'user_uuid',
        );
        $this->hasOne(
            'wrapup_uuid',
            QWrapupnotes::class,
            'wrapup_uuid',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QWrapupnotesList[]|QWrapupnotesList|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QWrapupnotesList|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
