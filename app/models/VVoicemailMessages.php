<?php

class VVoicemailMessages extends \Phalcon\Mvc\Model
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
    public $voicemail_message_uuid;

    /**
     *
     * @var string
     */
    public $voicemail_uuid;

    /**
     *
     * @var double
     */
    public $created_epoch;

    /**
     *
     * @var double
     */
    public $read_epoch;

    /**
     *
     * @var string
     */
    public $caller_id_name;

    /**
     *
     * @var string
     */
    public $caller_id_number;

    /**
     *
     * @var double
     */
    public $message_length;

    /**
     *
     * @var string
     */
    public $message_status;

    /**
     *
     * @var string
     */
    public $message_priority;

    /**
     *
     * @var string
     */
    public $message_intro_base64;

    /**
     *
     * @var string
     */
    public $message_base64;

    /**
     *
     * @var string
     */
    public $message_transcription;

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
        $this->setSource("v_voicemail_messages");
        $this->hasOne(
            "voicemail_uuid",
            VVoicemails::class,
            "voicemail_uuid",
        );
        $this->hasOne(
            "domain_uuid",
            VDomains::class,
            "domain_uuid",
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VVoicemailMessages[]|VVoicemailMessages|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VVoicemailMessages|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
