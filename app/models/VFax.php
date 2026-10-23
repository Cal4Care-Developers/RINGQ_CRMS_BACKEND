<?php

class VFax extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $fax_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $dialplan_uuid;

    /**
     *
     * @var string
     */
    public $fax_extension;

    /**
     *
     * @var string
     */
    public $fax_destination_number;

    /**
     *
     * @var string
     */
    public $fax_prefix;

    /**
     *
     * @var string
     */
    public $fax_name;

    /**
     *
     * @var string
     */
    public $fax_email;

    /**
     *
     * @var string
     */
    public $fax_email_connection_type;

    /**
     *
     * @var string
     */
    public $fax_email_connection_host;

    /**
     *
     * @var string
     */
    public $fax_email_connection_port;

    /**
     *
     * @var string
     */
    public $fax_email_connection_security;

    /**
     *
     * @var string
     */
    public $fax_email_connection_validate;

    /**
     *
     * @var string
     */
    public $fax_email_connection_username;

    /**
     *
     * @var string
     */
    public $fax_email_connection_password;

    /**
     *
     * @var string
     */
    public $fax_email_connection_mailbox;

    /**
     *
     * @var string
     */
    public $fax_email_inbound_subject_tag;

    /**
     *
     * @var string
     */
    public $fax_email_outbound_subject_tag;

    /**
     *
     * @var string
     */
    public $fax_email_outbound_authorized_senders;

    /**
     *
     * @var string
     */
    public $fax_pin_number;

    /**
     *
     * @var string
     */
    public $fax_caller_id_name;

    /**
     *
     * @var string
     */
    public $fax_caller_id_number;

    /**
     *
     * @var string
     */
    public $fax_toll_allow;

    /**
     *
     * @var string
     */
    public $fax_forward_number;

    /**
     *
     * @var string
     */
    public $fax_send_greeting;

    /**
     *
     * @var double
     */
    public $fax_send_channels;

    /**
     *
     * @var string
     */
    public $fax_description;

    /**
     *
     * @var string
     */
    public $accountcode;

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
        $this->setSource("v_fax");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VFax[]|VFax|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VFax|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
