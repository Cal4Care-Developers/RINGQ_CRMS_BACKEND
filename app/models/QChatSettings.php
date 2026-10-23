<?php

class QChatSettings extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var integer
     */
    public $widget_id;

    /**
     *
     * @var string
     */
    public $widget_name;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $widget_color;

    /**
     *
     * @var string
     */
    public $widget_appearance;

    /**
     *
     * @var string
     */
    public $widget_position;

    /**
     *
     * @var integer
     */
    public $attention_grabber;

    /**
     *
     * @var string
     */
    public $mobile_widget;

    /**
     *
     * @var string
     */
    public $countries;

    /**
     *
     * @var string
     */
    public $behaviour;

    /**
     *
     * @var integer
     */
    public $hide_estimated_wait_time;

    /**
     *
     * @var integer
     */
    public $disable_sound_notification;

    /**
     *
     * @var integer
     */
    public $disable_message_preview;

    /**
     *
     * @var integer
     */
    public $disable_agent_typing_notification;

    /**
     *
     * @var integer
     */
    public $disable_visitor_typing_function;

    /**
     *
     * @var integer
     */
    public $disable_browser_tab_notification;

    /**
     *
     * @var integer
     */
    public $hide_widget_when_offline;

    /**
     *
     * @var integer
     */
    public $hide_widget_on_load;

    /**
     *
     * @var integer
     */
    public $hide_widget_on_mobile;

    /**
     *
     * @var integer
     */
    public $disable_emoji_selection;

    /**
     *
     * @var integer
     */
    public $disable_file_upload;

    /**
     *
     * @var integer
     */
    public $disable_chat_rating;

    /**
     *
     * @var integer
     */
    public $consent_form;

    /**
     *
     * @var string
     */
    public $consent_message;

    /**
     *
     * @var string
     */
    public $privacy_policy_link;

    /**
     *
     * @var string
     */
    public $privacy_policy_text;

    /**
     *
     * @var string
     */
    public $opt_in_button;

    /**
     *
     * @var string
     */
    public $opt_out_button;

    /**
     *
     * @var integer
     */
    public $country_restriction;

    /**
     *
     * @var string
     */
    public $option_value;

    /**
     *
     * @var string
     */
    public $offline_email;

    /**
     *
     * @var integer
     */
    public $chat_aviator;

    /**
     *
     * @var integer
     */
    public $chat_agent_name;

    /**
     *
     * @var integer
     */
    public $main_timeZone;

    /**
     *
     * @var string
     */
    public $office_in_time;

    /**
     *
     * @var string
     */
    public $office_out_time;

    /**
     *
     * @var string
     */
    public $schedule_timeZone;

    /**
     *
     * @var string
     */
    public $s_office_in_time;

    /**
     *
     * @var string
     */
    public $s_office_out_time;

    /**
     *
     * @var integer
     */
    public $chat_sound;

    /**
     *
     * @var integer
     */
    public $has_department;

    /**
     *
     * @var string
     */
    public $departments;

    /**
     *
     * @var string
     */
    public $agents;

    /**
     *
     * @var string
     */
    public $widget_activity_time;

    /**
     *
     * @var string
     */
    public $created_dt;

    /**
     *
     * @var string
     */
    public $updated_dt;

    /**
     * Initialize method for model.
     */
    public function initialize()
    {
        $this->setSchema("public");
        $this->setSource("q_chat_settings");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QChatSettings[]|QChatSettings|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QChatSettings|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
