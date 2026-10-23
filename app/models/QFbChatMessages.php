<?php

class QFbChatMessages extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $chat_msg_id;

    /**
     *
     * @var integer
     */
    public $chat_id;

    /**
     *
     * @var string
     */
    public $page_name;

    /**
     *
     * @var string
     */
    public $page_picture;

    /**
     *
     * @var string
     */
    public $access_token;

    /**
     *
     * @var string
     */
    public $recipient_id;

    /**
     *
     * @var string
     */
    public $sender_id;

    /**
     *
     * @var string
     */
    public $from_id;

    /**
     *
     * @var string
     */
    public $first_name;

    /**
     *
     * @var string
     */
    public $last_name;

    /**
     *
     * @var string
     */
    public $profile_pic;

    /**
     *
     * @var string
     */
    public $chat_message;

    /**
     *
     * @var string
     */
    public $delivered_status;

    /**
     *
     * @var string
     */
    public $chat_status;

    /**
     *
     * @var string
     */
    public $message_attachment;

    /**
     *
     * @var string
     */
    public $extension;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var string
     */
    public $updated_at;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_fb_chat_messages");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QFbChatMessages[]|QFbChatMessages|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QFbChatMessages|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
