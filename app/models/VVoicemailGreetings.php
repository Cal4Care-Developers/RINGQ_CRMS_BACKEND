<?php

class VVoicemailGreetings extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $voicemail_greeting_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $voicemail_id;

    /**
     *
     * @var double
     */
    public $greeting_id;

    /**
     *
     * @var string
     */
    public $greeting_name;

    /**
     *
     * @var string
     */
    public $greeting_filename;

    /**
     *
     * @var string
     */
    public $greeting_description;

    /**
     *
     * @var string
     */
    public $greeting_base64;

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
        $this->setSource("v_voicemail_greetings");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VVoicemailGreetings[]|VVoicemailGreetings|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VVoicemailGreetings|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
