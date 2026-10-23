<?php

class QCallBehaviors extends \Phalcon\Mvc\Model
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
    public $forward_all_destination2;

    /**
     *
     * @var string
     */
    public $forward_all_enabled;

    /**
     *
     * @var string
     */
    public $forward_all_enabled2;

    /**
     *
     * @var string
     */
    public $forward_busy_destination;

    /**
     *
     * @var string
     */
    public $forward_busy_destination2;

    /**
     *
     * @var string
     */
    public $forward_busy_enabled;

    /**
     *
     * @var string
     */
    public $forward_busy_enabled2;

    /**
     *
     * @var string
     */
    public $forward_no_answer_destination;

    /**
     *
     * @var string
     */
    public $forward_no_answer_destination2;

    /**
     *
     * @var string
     */
    public $forward_no_answer_enabled;

    /**
     *
     * @var string
     */
    public $forward_no_answer_enabled2;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_destination;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_destination2;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_enabled;

    /**
     *
     * @var string
     */
    public $forward_user_not_registered_enabled2;

    /**
     *
     * @var string
     */
    public $follow_me_uuid;

    /**
     *
     * @var string
     */
    public $follow_me_uuid2;

    /**
     *
     * @var string
     */
    public $follow_me_enabled;

    /**
     *
     * @var string
     */
    public $follow_me_enabled2;

    /**
     *
     * @var string
     */
    public $follow_me_destinations;

    /**
     *
     * @var string
     */
    public $follow_me_destinations2;

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
     * @var integer
     */
    public $type;

    /**
     *
     * @var integer
     */
    public $enabled;

    /**
     *
     * @var string
     */
    public $forward_no_answer_timeout;

    /**
     *
     * @var string
     */
    public $forward_no_answer_timeout2;

    /**
     *
     * @var string
     */
    public $call_profiles;

    /**
     *
     * @var string
     */
    public $call_profiles_colors;

    /**
     *
     * @var string
     */
    public $mobile_number;

    /**
     *
     * @var string
     */
    public $simultaneous;

    /**
     *
     * @var string
     */
    public $simultaneous_destination_enabled;

    /**
     *
     * @var string
     */
    public $simultaneous_destination_enabled2;

    /**
     *
     * @var string
     */
    public $simultaneous2;

    /**
     *
     * @var string
     */
    public $call_screening;

    /**
     *
     * @var string
     */
    public $call_screening2;

    /**
     *
     * @var integer
     */
    public $id;

    /**
     *
     * @var integer
     */
    public $outbound_mode;

    /**
     *
     * @var integer
     */
    public $sso_qr;

    /**
     *
     * @var integer
     */
    public $sso_number;

    /**
     *
     * @var integer
     */
    public $record_control;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_call_behaviors");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallBehaviors[]|QCallBehaviors|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QCallBehaviors|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
