<?php

class UserData extends \Phalcon\Mvc\Model
{
	/**
	 *
	 * @var integer
	 */
	public $id;

	/**
	 *
	 * @var integer
	 */
	public $status;

	/**
	 *
	 * @var integer
	 */
	public $company_id;

	/**
	 *
	 * @var integer
	 */
	public $company_uid;

	/**
	 *
	 * @var string
	 */
	public $username;

	/**
	 *
	 * @var string
	 */
	public $email_address;

	/**
	 *
	 * @var string
	 */
	public $password;

	/**
	 *
	 * @var string
	 */
	public $image;

	/**
	 *
	 * @var string
	 */
	public $firstname;

	/**
	 *
	 * @var string
	 */
	public $lastname;

	/**
	 *
	 * @var string
	 */
	public $position_title;

	/**
	 *
	 * @var string
	 */
	public $created_date;

	/**
	 *
	 * @var string
	 */
	public $updated_date;

	/**
	 *
	 * @var integer
	 */
	public $created_timestamp;

	/**
	 *
	 * @var integer
	 */
	public $updated_timestamp;

	/**
	 *
	 * @var integer
	 */
	public $created_user_id;

	/**
	 *
	 * @var integer
	 */
	public $updated_user_id;

	/**
	 *
	 * @var string
	 */
	public $created_ip;

	/**
	 *
	 * @var string
	 */
	public $updated_ip;

	/**
	 * Initialize method for model.
	 */
	public function initialize()
	{
		$this->setSchema("teleon");
		$this->setSource("user_data");
		$this->hasOne("id", UserEncrypt::class, "user_id", [
			"alias" => "userEncrypt",
			"reusable" => true,
		]);
	}

	public function afterValidationOnCreate()
	{
		$this->created_date = date("Y-m-d H:i:s");
		$this->created_timestamp = time();
	}

	public function afterValidationOnUpdate()
	{
		$this->updated_date = date("Y-m-d H:i:s");
		$this->updated_timestamp = time();
	}

	/**
	 * Allows to query a set of records that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return UserData[]|UserData|\Phalcon\Mvc\Model\ResultSetInterface
	 */
	public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
	{
		return parent::find($parameters);
	}

	/**
	 * Allows to query the first record that match the specified conditions
	 *
	 * @param mixed $parameters
	 * @return UserData|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
	 */
	public static function findFirst($parameters = null)
	{
		return parent::findFirst($parameters);
	}
}
