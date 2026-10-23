<?php

class Rooms extends \Phalcon\Mvc\Model
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
	public $creator;

	/**
	 *
	 * @var string
	 */
	public $room_name;

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
		$this->setSource("rooms");
	}

	/**
	 * Allows to query a set of records that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return Rooms[]|Rooms|\Phalcon\Mvc\Model\ResultSetInterface
	 */
	public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
	{
		return parent::find($parameters);
	}

	/**
	 * Allows to query the first record that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return Rooms|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
	 */
	public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
	{
		return parent::findFirst($parameters);
	}
}
