<?php

class QQueueNotifications extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $queue_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $queue_extension;

    /**
     *
     * @var string
     */
    public $queue_managers;

    /**
     *
     * @var integer
     */
    public $queue_sla_notify;

    /**
     *
     * @var integer
     */
    public $queue_callback_success;

    /**
     *
     * @var integer
     */
    public $queue_callback_fail;

    /**
     *
     * @var integer
     */
    public $queue_call_lost;

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
    public $insert_date;

    /**
     *
     * @var string
     */
    public $insert_user;

    /**
     *
     * @var integer
     */
    public $id;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_queue_notifications");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QQueueNotifications[]|QQueueNotifications|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QQueueNotifications|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
