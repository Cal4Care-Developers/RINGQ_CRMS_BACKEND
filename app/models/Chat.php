<?php

class Chat extends \Phalcon\Mvc\Model
{
	/**
	 *
	 * @var integer
	 */
	public $id;

	/**
	 *
	 * @var string
	 */
	public $name;

	/**
	 *
	 * @var string
	 */
	public $sender;

	/**
	 *
	 * @var string
	 */
	public $receiver;

	/**
	 *
	 * @var string
	 */
	public $message;

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
		$this->keepSnapshots(true);
		$this->setSchema("public");
		$this->setSource("chat");
	}

	/**
	 * Allows to query a set of records that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return Chat[]|Chat|\Phalcon\Mvc\Model\ResultSetInterface
	 */
	public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
	{
		return parent::find($parameters);
	}

	/**
	 * Allows to query the first record that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return Chat|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
	 */
	public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
	{
		return parent::findFirst($parameters);
	}
}
