<?php

class QOutboundRules extends \Phalcon\Mvc\Model
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
    public $outbound_uuid;

    /**
     *
     * @var string
     */
    public $prefix_pattern;

    /**
     *
     * @var string
     */
    public $calls_extensions;

    /**
     *
     * @var string
     */
    public $calls_number_length;

    /**
     *
     * @var string
     */
    public $action;

    /**
     *
     * @var string
     */
    public $strip;

    /**
     *
     * @var string
     */
    public $prepend;

    /**
     *
     * @var string
     */
    public $outbound_caller_id;

    /**
     *
     * @var string
     */
    public $outbound_name;

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
     * @var double
     */
    public $dial_plan_order;

    /**
     *
     * @var string
     */
    public $team_extensions;

    /**
     *
     * @var string
     */
    public $extension_range;

    /**
     *
     * @var integer
     */
    public $priority;

    /**
     *
     * @var string
     */
    public $custom_user;

    /**
     *
     * @var string
     */
    public $custom_display;
    
    /**
     *
     * @var string
     */
    public $custom_host;

    /**
     *
     * @var string
     */
    public $custom_cid;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_outbound_rules");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QOutboundRules[]|QOutboundRules|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QOutboundRules|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
