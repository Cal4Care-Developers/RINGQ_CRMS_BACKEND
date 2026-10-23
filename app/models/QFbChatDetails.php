<?php

class QFbChatDetails extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $chat_id;

    /**
     *
     * @var string
     */
    public $widget_id;

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
     * @var integer
     */
    public $chat_status;

    /**
     *
     * @var string
     */
    public $created_at;

    /**
     *
     * @var string
     */
    public $updatet_at;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_fb_chat_details");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QFbChatDetails[]|QFbChatDetails|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QFbChatDetails|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
