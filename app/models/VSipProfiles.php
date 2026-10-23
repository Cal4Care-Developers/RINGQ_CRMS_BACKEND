<?php

class VSipProfiles extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $sip_profile_uuid;

    /**
     *
     * @var string
     */
    public $sip_profile_name;

    /**
     *
     * @var string
     */
    public $sip_profile_hostname;

    /**
     *
     * @var string
     */
    public $sip_profile_enabled;

    /**
     *
     * @var string
     */
    public $sip_profile_description;

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
        $this->setSource("v_sip_profiles");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return VSipProfiles[]|VSipProfiles|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return VSipProfiles|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
