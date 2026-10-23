<?php

/**
 * A Click2Talk (RingQ Talk) link.
 *
 * One row per destination, so a link's URL carries only `click2talk_uuid` and
 * this record decides everything else. Before it, the configuration travelled
 * in the URL and the destination was inferred from the SHAPE of the username —
 * a value containing "-" was read as a queue, because a uuid has hyphens.
 * `destination_type` replaces that guess.
 */
class QClick2talkLinks extends \Phalcon\Mvc\Model
{
    /** Destination kinds. Stored, never inferred. */
    const TYPE_EXTENSION = 'extension';
    const TYPE_QUEUE     = 'queue';

    /**
     *
     * @var string
     */
    public $click2talk_uuid;

    /**
     *
     * @var string
     */
    public $domain_uuid;

    /**
     * One of TYPE_EXTENSION or TYPE_QUEUE.
     *
     * @var string
     */
    public $destination_type;

    /**
     * v_extensions.extension_uuid or v_call_center_queues.call_center_queue_uuid.
     *
     * @var string
     */
    public $destination_uuid;

    /**
     * The dialable number, denormalised so resolving a link is one indexed read.
     *
     * @var string
     */
    public $destination_extension;

    /**
     * "1" name and email, "2" name, "3" email, "4" nothing.
     *
     * @var string
     */
    public $ask_customer_for;

    /**
     *
     * @var integer
     */
    public $share_contact_details;

    /**
     *
     * @var string
     */
    public $button_text;

    /**
     *
     * @var string
     */
    public $button_color;

    /**
     *
     * @var integer
     */
    public $enabled;

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
        $this->setSource("q_click2talk_links");
    }

    /**
     * Allows to query a set of records that match the specified conditions
     *
     * @param mixed $parameters
     * @return QClick2talkLinks[]|QClick2talkLinks|\Phalcon\Mvc\Model\ResultSetInterface
     */
    public static function find($parameters = null): \Phalcon\Mvc\Model\ResultsetInterface
    {
        return parent::find($parameters);
    }

    /**
     * Allows to query the first record that match the specified conditions
     *
     * @param mixed $parameters
     * @return QClick2talkLinks|\Phalcon\Mvc\Model\ResultInterface|\Phalcon\Mvc\ModelInterface|null
     */
    public static function findFirst($parameters = null): ?\Phalcon\Mvc\ModelInterface
    {
        return parent::findFirst($parameters);
    }
}
