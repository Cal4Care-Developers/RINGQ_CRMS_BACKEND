<?php

class QChatMessages extends \Phalcon\Mvc\Model
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
     * @var integer
     */
    public $msg_usr_id;

    /**
     *
     * @var integer
     */
    public $msg_usr_type;

    /**
     *
     * @var integer
     */
    public $msg_type;

    /**
     *
     * @var string
     */
    public $chat_msg;

    /**
     *
     * @var integer
     */
    public $msg_status;

    /**
     *
     * @var string
     */
    public $chat_attachments;

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
        $this->setSource("q_chat_messages");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QChatMessages[]|QChatMessages|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QChatMessages|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
