<?php

namespace App\Models;

use Phalcon\Mvc\Model;

class CampaignsContactDetails extends Model
{
    public $id;
    public $campaign_name;
    public $customer_name;
    public $address;
    public $city;
    public $state;
    public $zipcode;
    public $country;
    public $phone_number;
    public $source_data;
    public $notes;
    public $created_at;

    public function initialize()
    {
        // Links the model to your specific table name
        $this->setSource("campaigns_contact_details");
    }
}