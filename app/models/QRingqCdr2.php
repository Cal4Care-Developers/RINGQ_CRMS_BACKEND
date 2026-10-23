<?php

class QRingqCdr2 extends \Phalcon\Mvc\Model
{

    /**
     *
     * @var string
     */
    public $xml_cdr_uuid;

    /**
     *
     * @var string
     */
    public $call_broadcast_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     *
     * @var string
     */
    public $extension_uuid;

    /**
     *
     * @var string
     */
    public $sip_call_id;

    /**
     *
     * @var string
     */
    public $domain_name;

    /**
     *
     * @var string
     */
    public $accountcode;

    /**
     *
     * @var string
     */
    public $direction;

    /**
     *
     * @var string
     */
    public $default_language;

    /**
     *
     * @var string
     */
    public $context;

    /**
     *
     * @var string
     */
    public $caller_id_name;

    /**
     *
     * @var string
     */
    public $caller_id_number;

    /**
     *
     * @var string
     */
    public $caller_destination;

    /**
     *
     * @var string
     */
    public $source_number;

    /**
     *
     * @var string
     */
    public $destination_number;

    /**
     *
     * @var double
     */
    public $start_epoch;

    /**
     *
     * @var string
     */
    public $start_stamp;

    /**
     *
     * @var string
     */
    public $answer_stamp;

    /**
     *
     * @var double
     */
    public $answer_epoch;

    /**
     *
     * @var double
     */
    public $end_epoch;

    /**
     *
     * @var string
     */
    public $end_stamp;

    /**
     *
     * @var double
     */
    public $duration;

    /**
     *
     * @var double
     */
    public $mduration;

    /**
     *
     * @var double
     */
    public $billsec;

    /**
     *
     * @var double
     */
    public $billmsec;

    /**
     *
     * @var string
     */
    public $bridge_uuid;

    /**
     *
     * @var string
     */
    public $read_codec;

    /**
     *
     * @var string
     */
    public $read_rate;

    /**
     *
     * @var string
     */
    public $write_codec;

    /**
     *
     * @var string
     */
    public $write_rate;

    /**
     *
     * @var string
     */
    public $remote_media_ip;

    /**
     *
     * @var string
     */
    public $network_addr;

    /**
     *
     * @var string
     */
    public $record_path;

    /**
     *
     * @var string
     */
    public $record_name;

    /**
     *
     * @var double
     */
    public $record_length;

    /**
     *
     * @var string
     */
    public $leg;

    /**
     *
     * @var string
     */
    public $originating_leg_uuid;

    /**
     *
     * @var double
     */
    public $pdd_ms;

    /**
     *
     * @var double
     */
    public $rtp_audio_in_mos;

    /**
     *
     * @var string
     */
    public $last_app;

    /**
     *
     * @var string
     */
    public $last_arg;

    /**
     *
     * @var boolean
     */
    public $voicemail_message;

    /**
     *
     * @var boolean
     */
    public $missed_call;

    /**
     *
     * @var string
     */
    public $call_center_queue_uuid;

    /**
     *
     * @var string
     */
    public $cc_side;

    /**
     *
     * @var string
     */
    public $cc_member_uuid;

    /**
     *
     * @var double
     */
    public $cc_queue_joined_epoch;

    /**
     *
     * @var string
     */
    public $cc_queue;

    /**
     *
     * @var string
     */
    public $cc_member_session_uuid;

    /**
     *
     * @var string
     */
    public $cc_agent_uuid;

    /**
     *
     * @var string
     */
    public $cc_agent;

    /**
     *
     * @var string
     */
    public $cc_agent_type;

    /**
     *
     * @var string
     */
    public $cc_agent_bridged;

    /**
     *
     * @var double
     */
    public $cc_queue_answered_epoch;

    /**
     *
     * @var double
     */
    public $cc_queue_terminated_epoch;

    /**
     *
     * @var double
     */
    public $cc_queue_canceled_epoch;

    /**
     *
     * @var string
     */
    public $cc_cancel_reason;

    /**
     *
     * @var string
     */
    public $cc_cause;

    /**
     *
     * @var double
     */
    public $waitsec;

    /**
     *
     * @var string
     */
    public $conference_name;

    /**
     *
     * @var string
     */
    public $conference_uuid;

    /**
     *
     * @var string
     */
    public $conference_member_id;

    /**
     *
     * @var string
     */
    public $digits_dialed;

    /**
     *
     * @var string
     */
    public $pin_number;

    /**
     *
     * @var string
     */
    public $hangup_cause;

    /**
     *
     * @var double
     */
    public $hangup_cause_q850;

    /**
     *
     * @var string
     */
    public $sip_hangup_disposition;

    /**
     *
     * @var string
     */
    public $xml;

    /**
     *
     * @var string
     */
    public $json;

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
        $this->setSource("q_ringq_cdr2");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QRingqCdr2[]|QRingqCdr2|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QRingqCdr2|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }

}
