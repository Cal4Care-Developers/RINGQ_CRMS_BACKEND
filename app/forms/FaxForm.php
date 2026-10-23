<?php
use Phalcon\Forms\Form;
use Phalcon\Forms\Element\Text;
// use Phalcon\Validation\Validator\Url;
use Phalcon\Validation\Validator\PresenceOf;
// use Phalcon\Validation\Validator\InclusionIn;
use Phalcon\Validation\Validator\StringLength;
// use Phalcon\Validation\Validator\Email as EmailValidator;

class FaxForm extends Form
{
	public function initialize()
	{
        $fax_name = new Text("fax_name");
        $fax_name->setFilters(
            [
                "string",
                "trim"
            ]
        );
        $fax_name->addValidators(
            [
                new StringLength(
                    [
                        "min" => 2,
                        "max" => 36,
                        "messageMinimum" => "Fax name is too short.",
                        "messageMaximum" => "Fax name is too long.",
                        "allowEmpty" => true
                    ]
                ),
                new PresenceOf(
                    [
                        "message" => "Fax name is required."
                    ]
                ),
            ]
        );
        $this->add($fax_name);
        // $fax_email_connection_host = new Text("fax_email_connection_host");
        // $fax_email_connection_host->setFilters(
        //     [
        //         "string",
        //         "trim"
        //     ]
        // );
        // $fax_email_connection_host->addValidators(
        //     [
        //         new StringLength(
        //             [
        //                 "min" => 2,
        //                 "max" => 124,
        //                 "messageMinimum" => "Fax host is too short.",
        //                 "messageMaximum" => "Fax host is too long.",
        //                 "allowEmpty" => true
        //             ]
        //         ),
        //         new UrlValidator(
        //             [
        //                 "message" => "Fax host must be a url",
        //             ]
        //         ),
        //         new PresenceOf(
        //             [
        //                 "message" => "Fax host is required."
        //             ]
        //         ),
        //     ]
        // );
        // $this->add($fax_email_connection_host);
        // $fax_email_connection_type = new Text("fax_email_connection_type");
        // $fax_email_connection_type->setFilters(
        //     [
        //         "string",
        //         "trim"
        //     ]
        // );
        // $fax_email_connection_type->addValidators(
        //     [
        //         new InclusionIn(
        //             [
        //                 "domain" => ["IMAP", "pop3"],
        //                 "message" => "Please check Fax email connection entry.",
        //             ]
        //         ),
        //         new PresenceOf(
        //             [
        //                 "message" => "Fax email connection is required."
        //             ]
        //         ),
        //     ]
        // );
        // $this->add($fax_email_connection_type);
        // $fax_email_connection_security = new Text("fax_email_connection_security");
        // $fax_email_connection_security->setFilters(
        //     [
        //         "string",
        //         "trim"
        //     ]
        // );
        // $fax_email_connection_security->addValidators(
        //     [
        //         new InclusionIn(
        //             [
        //                 "domain" => ["ssl", "tls"],
        //                 "message" => "Please check Fax email connection security  entry.",
        //             ]
        //         ),
        //         new PresenceOf(
        //             [
        //                 "message" => "Fax email connection security is required."
        //             ]
        //         ),
        //     ]
        // );
        // $this->add($fax_email_connection_security);
        // $fax_email_connection_username = new Text("fax_email_connection_username");
        // $fax_email_connection_username->setFilters(
        //     [
        //         "string",
        //         "trim"
        //     ]
        // );
        // $fax_email_connection_username->addValidators(
        //     [
        //         new StringLength(
        //             [
        //                 "min" => 2,
        //                 "max" => 36,
        //                 "messageMinimum" => "Fax email username is too short.",
        //                 "messageMaximum" => "Fax email username  is too long.",
        //                 "allowEmpty" => true
        //             ]
        //         ),
        //         new PresenceOf(
        //             [
        //                 "message" => "Fax email username is required."
        //             ]
        //         ),
        //     ]
        // );
        // $this->add($fax_email_connection_username);
        // $fax_email_connection_password = new Text("fax_email_connection_password");
        // $fax_email_connection_password->setFilters(
        //     [
        //         "string",
        //         "trim"
        //     ]
        // );
        // $fax_email_connection_password->addValidators(
        //     [
        //         new StringLength(
        //             [
        //                 "min" => 2,
        //                 "max" => 36,
        //                 "messageMinimum" => "Fax email password is too short.",
        //                 "messageMaximum" => "Fax email password  is too long.",
        //                 "allowEmpty" => true
        //             ]
        //         ),
        //         new PresenceOf(
        //             [
        //                 "message" => "Fax email password is required."
        //             ]
        //         ),
        //     ]
        // );
        // $this->add($fax_email_connection_password);
        // $fax_email_outbound_authorized_senders = new Text("fax_email_outbound_authorized_senders");
        // $fax_email_outbound_authorized_senders->setFilters(
        //     [
        //         "string",
        //         "trim"
        //     ]
        // );
        // $fax_email_outbound_authorized_senders->addValidators(
        //     [
        //         new EmailValidator(
        //             [
        //                 "message" => "Email is not valid"
        //             ]
        //         ),
        //         new StringLength(
        //             [
        //                 "min" => 2,
        //                 "max" => 36,
        //                 "messageMinimum" => "Fax email sender is too short.",
        //                 "messageMaximum" => "Fax email sender  is too long.",
        //                 "allowEmpty" => true
        //             ]
        //         ),
        //         new PresenceOf(
        //             [
        //                 "message" => "Fax email sender is required."
        //             ]
        //         ),
        //     ]
        // );
        // $this->add($fax_email_outbound_authorized_senders);
	}
}
