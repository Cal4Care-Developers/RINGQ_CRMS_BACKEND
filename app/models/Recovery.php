<?php

class Recovery extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $runtime_uuid;

    /**
     *
     * @var string
     */
    public $technology;

    /**
     *
     * @var string
     */
    public $profile_name;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     *
     * @var string
     */
    public $uuid;

    /**
     *
     * @var string
     */
    public $metadata;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("recovery");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Recovery[]|Recovery|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Recovery|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
