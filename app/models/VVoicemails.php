<?php

class VVoicemails extends \Phalcon\Mvc\Model
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
    public $voicemail_uuid;

    /**
     *
     * @var string
     */
    public $voicemail_id;

    /**
     *
     * @var string
     */
    public $voicemail_password;

    /**
     *
     * @var double
     */
    public $greeting_id;

    /**
     *
     * @var double
     */
    public $voicemail_alternate_greet_id;

    /**
     *
     * @var string
     */
    public $voicemail_recording_instructions;

    /**
     *
     * @var string
     */
    public $voicemail_recording_options;

    /**
     *
     * @var string
     */
    public $voicemail_mail_to;

    /**
     *
     * @var string
     */
    public $voicemail_sms_to;

    /**
     *
     * @var string
     */
    public $voicemail_transcription_enabled;

    /**
     *
     * @var string
     */
    public $voicemail_attach_file;

    /**
     *
     * @var string
     */
    public $voicemail_file;

    /**
     *
     * @var string
     */
    public $voicemail_local_after_email;

    /**
     *
     * @var string
     */
    public $voicemail_enabled;

    /**
     *
     * @var string
     */
    public $voicemail_description;

    /**
     *
     * @var string
     */
    public $voicemail_name_base64;

    /**
     *
     * @var string
     */
    public $voicemail_tutorial;

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
    public $extensions;

    /**
     *
     * @var string
     */
    public $voicemail_name;

    /**
     *
     * @var integer
     */
    public $notify_missed;

    /**
     *
     * @var integer
     */
    public $notify_missed2;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("v_voicemails");
        $this->hasOne(
            'greeting_id',
            VVoicemailGreetings::class,
            'greeting_name',
        );
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VVoicemails[]|VVoicemails|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VVoicemails|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
