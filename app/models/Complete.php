<?php

class Complete extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $sticky;

    /**
     *
     * @var string
     */
    public $a1;

    /**
     *
     * @var string
     */
    public $a2;

    /**
     *
     * @var string
     */
    public $a3;

    /**
     *
     * @var string
     */
    public $a4;

    /**
     *
     * @var string
     */
    public $a5;

    /**
     *
     * @var string
     */
    public $a6;

    /**
     *
     * @var string
     */
    public $a7;

    /**
     *
     * @var string
     */
    public $a8;

    /**
     *
     * @var string
     */
    public $a9;

    /**
     *
     * @var string
     */
    public $a10;

    /**
     *
     * @var string
     */
    public $hostname;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("complete");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return Complete[]|Complete|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return Complete|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
