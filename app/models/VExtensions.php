<?php

class VExtensions extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $extension_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $extension;

    /**
     *
     * @var string
     */
    public $number_alias;

    /**
     *
     * @var string
     */
    public $password;

    /**
     *
     * @var string
     */
    public $accountcode;

    /**
     *
     * @var string
     */
    public $effective_caller_id_name;

    /**
     *
     * @var string
     */
    public $effective_caller_id_number;

    /**
     *
     * @var string
     */
    public $outbound_caller_id_name;

    /**
     *
     * @var string
     */
    public $outbound_caller_id_number;

    /**
     *
     * @var string
     */
    public $emergency_caller_id_name;

    /**
     *
     * @var string
     */
    public $emergency_caller_id_number;

    /**
     *
     * @var string
     */
    public $directory_first_name;

    /**
     *
     * @var string
     */
    public $directory_last_name;

    /**
     *
     * @var string
     */
    public $directory_visible;

    /**
     *
     * @var string
     */
    public $directory_exten_visible;

    /**
     *
     * @var string
     */
    public $max_registrations;

    /**
     *
     * @var string
     */
    public $limit_max;

    /**
     *
     * @var string
     */
    public $limit_destination;

    /**
     *
     * @var string
     */
    public $missed_call_app;

    /**
     *
     * @var string
     */
    public $missed_call_data;

    /**
     *
     * @var string
     */
    public $user_context;

    /**
     *
     * @var string
     */
    public $toll_allow;

    /**
     *
     * @var double
     */
    public $call_timeout;

    /**
     *
     * @var string
     */
    public $call_group;

    /**
     *
     * @var string
     */
    public $call_screen_enabled;

    /**
     *
     * @var string
     */
    public $user_record;

    /**
     *
     * @var string
     */
    public $hold_music;

    /**
     *
     * @var string
     */
    public $auth_acl;

    /**
     *
     * @var string
     */
    public $cidr;

    /**
     *
     * @var string
     */
    public $sip_force_contact;

    /**
     *
     * @var string
     */
    public $nibble_account;

    /**
     *
     * @var double
     */
    public $sip_force_expires;

    /**
     *
     * @var string
     */
    public $mwi_account;

    /**
     *
     * @var string
     */
    public $sip_bypass_media;

    /**
     *
     * @var double
     */
    public $unique_id;

    /**
     *
     * @var string
     */
    public $dial_string;

    /**
     *
     * @var string
     */
    public $dial_user;

    /**
     *
     * @var string
     */
    public $dial_domain;

    /**
     *
     * @var string
     */
    public $do_not_disturb;

    /**
     *
     * @var string
     */
    public $forward_all_destination;

    /**
     *
     * @var string
     */
    public $forward_all_enabled;

    /**
     *
     * @var string
     */
    public $forward_busy_destination;

    /**
     *
     * @var string
     */
    public $forward_busy_enabled;

    /**
     *
     * @var string
     */
    public $forward_no_answer_destination;

    /**
     *
     * @var string
     */
    public $forward_no_answer_enabled;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_destination;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_enabled;

    /**
     *
     * @var string
     */
    public $follow_me_uuid;

    /**
     *
     * @var string
     */
    public $follow_me_enabled;

    /**
     *
     * @var string
     */
    public $follow_me_destinations;

    /**
     *
     * @var string
     */
    public $extension_type;

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
    public $absolute_codec_string;

    /**
     *
     * @var string
     */
    public $force_ping;

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
     * @var string
     */
    public $forward_all_destination2;

    /**
     *
     * @var string
     */
    public $forward_all_enabled2;

    /**
     *
     * @var string
     */
    public $forward_busy_destination2;

    /**
     *
     * @var string
     */
    public $forward_busy_enabled2;

    /**
     *
     * @var string
     */
    public $forward_no_answer_destination2;

    /**
     *
     * @var string
     */
    public $forward_no_answer_enabled2;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_destination2;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_enabled2;

    /**
     *
     * @var double
     */
    public $call_timeout2;

    /**
     *
     * @var string
     */
    public $follow_me_uuid2;

    /**
     *
     * @var string
     */
    public $follow_me_enabled2;


    /**
     *
     * @var string
     */
    public $pickup_group;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("v_extensions");
        $this->hasOne(
            'extension_uuid',
            VExtensionUsers::class,
            'extension_uuid',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VExtensions[]|VExtensions|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VExtensions|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
