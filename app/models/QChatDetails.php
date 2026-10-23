<?php

class QChatDetails extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $chat_id;

    /**
     *
     * @var integer
     */
    public $widget_id;

    /**
     *
     * @var string
     */
    public $chat_user;

    /**
     *
     * @var string
     */
    public $chat_subject;

    /**
     *
     * @var string
     */
    public $msg_from;

    /**
     *
     * @var string
     */
    public $chat_type;

    /**
     *
     * @var string
     */
    public $chat_cat;

    /**
     *
     * @var string
     */
    public $chat_queue;

    /**
     *
     * @var string
     */
    public $assigned_user;

    /**
     *
     * @var string
     */
    public $chat_status;

    /**
     *
     * @var string
     */
    public $created_ip;

    /**
     *
     * @var string
     */
    public $rating_value;

    /**
     *
     * @var integer
     */
    public $read_status;

    /**
     *
     * @var string
     */
    public $chatUrl;

    /**
     *
     * @var string
     */
    public $created_dt;

    /**
     *
     * @var string
     */
    public $updated_dt;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_chat_details");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QChatDetails[]|QChatDetails|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QChatDetails|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
