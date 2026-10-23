<?php

class VFollowMeDestinations extends \Phalcon\Mvc\Model
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
    public $follow_me_uuid;

    /**
     *
     * @var string
     */
    public $follow_me_destination_uuid;

    /**
     *
     * @var string
     */
    public $follow_me_destination;

    /**
     *
     * @var double
     */
    public $follow_me_delay;

    /**
     *
     * @var double
     */
    public $follow_me_timeout;

    /**
     *
     * @var string
     */
    public $follow_me_prompt;

    /**
     *
     * @var double
     */
    public $follow_me_order;

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
        $this->setSource("v_follow_me_destinations");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VFollowMeDestinations[]|VFollowMeDestinations|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VFollowMeDestinations|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
