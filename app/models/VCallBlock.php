<?php

class VCallBlock extends \Phalcon\Mvc\Model
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
	public $call_block_uuid;

	/**
	 *
	 * @var string
	 */
	public $call_block_direction;

	/**
	 *
	 * @var string
	 */
	public $extension_uuid;

	/**
	 *
	 * @var string
	 */
	public $call_block_name;

	/**
	 *
	 * @var double
	 */
	public $call_block_country_code;

	/**
	 *
	 * @var string
	 */
	public $call_block_number;

	/**
	 *
	 * @var double
	 */
	public $call_block_count;

	/**
	 *
	 * @var string
	 */
	public $call_block_action;

	/**
	 *
	 * @var string
	 */
	public $call_block_app;

	/**
	 *
	 * @var string
	 */
	public $call_block_data;

	/**
	 *
	 * @var string
	 */
	public $date_added;

	/**
	 *
	 * @var string
	 */
	public $call_block_enabled;

	/**
	 *
	 * @var string
	 */
	public $call_block_description;

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
		$this->keepSnapshots(true);
		$this->setSchema("public");
		$this->setSource("v_call_block");
	}

	/**
	 * Allows to query a set of records that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return VCallBlock[]|VCallBlock|\Phalcon\Mvc\Model\ResultSetInterface
	 */
	public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
	{
		return parent::find($parameters);
	}

	/**
	 * Allows to query the first record that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return VCallBlock|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
	 */
	public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
	{
		return parent::findFirst($parameters);
	}
}
