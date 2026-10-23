<?php

class VGateways extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $gateway_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $gateway;

    /**
     *
     * @var string
     */
    public $username;

    /**
     *
     * @var string
     */
    public $password;

    /**
     *
     * @var string
     */
    public $distinct_to;

    /**
     *
     * @var string
     */
    public $auth_username;

    /**
     *
     * @var string
     */
    public $realm;

    /**
     *
     * @var string
     */
    public $from_user;

    /**
     *
     * @var string
     */
    public $from_domain;

    /**
     *
     * @var string
     */
    public $proxy;

    /**
     *
     * @var string
     */
    public $register_proxy;

    /**
     *
     * @var string
     */
    public $outbound_proxy;

    /**
     *
     * @var double
     */
    public $expire_seconds;

    /**
     *
     * @var string
     */
    public $register;

    /**
     *
     * @var string
     */
    public $register_transport;

    /**
     *
     * @var string
     */
    public $contact_params;

    /**
     *
     * @var double
     */
    public $retry_seconds;

    /**
     *
     * @var string
     */
    public $extension;

    /**
     *
     * @var string
     */
    public $ping;

    /**
     *
     * @var string
     */
    public $ping_min;

    /**
     *
     * @var string
     */
    public $ping_max;

    /**
     *
     * @var string
     */
    public $contact_in_ping;

    /**
     *
     * @var string
     */
    public $caller_id_in_from;

    /**
     *
     * @var string
     */
    public $supress_cng;

    /**
     *
     * @var string
     */
    public $sip_cid_type;

    /**
     *
     * @var string
     */
    public $codec_prefs;

    /**
     *
     * @var double
     */
    public $channels;

    /**
     *
     * @var string
     */
    public $extension_in_contact;

    /**
     *
     * @var string
     */
    public $context;

    /**
     *
     * @var string
     */
    public $profile;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     *
     * @var string
     */
    public $enabled;

    /**
     *
     * @var string
     */
    public $description;

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
    public $real_network;

    /**
     *
     * @var integer
     */
    public $privacy_header;

    /**
     *
     * @var string
     */
    public $custom_user;

    /**
     *
     * @var string
     */
    public $custom_display;

    /**
     *
     * @var string
     */
    public $custom_match;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("v_gateways");
    }

    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VGateways|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }
}
