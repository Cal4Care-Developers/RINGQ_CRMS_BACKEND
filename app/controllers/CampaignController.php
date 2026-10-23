<?php 

declare(strict_types=1);

use Phalcon\Mvc\Controller;
use Phalcon\Http\Response;

// FORCE LOAD: Manually point to the file path
require_once __DIR__ . '/../models/CampaignsContactDetails.php';

use App\Models\Campaigns;

class CampaignController extends ControllerBase
{
    /**
     * POST /campaign/bulkUploadCampaign
     */

   
public function createAction()
{
    // Ensure it is a POST request
    if (!$this->request->isPost()) {
        return $this->showJsonError("Method not allowed");
    }

    $data = $this->request->getJsonRawBody(true);

    // Mandatory Field Validation
    if (empty($data['campaign_id']) || empty($data['customer_name']) || empty($data['phone_number'])) {
        return $this->showJsonError("Mandatory fields missing: campaign_id, customer_name, and phone_number are required.");
    }

    $contact = new \App\Models\CampaignsContactDetails();
    $contact->assign($data);

    if ($contact->save()) {
        return $this->response->setJsonContent([
            "status" => "success",
            "message" => "Contact created successfully",
            "id" => $contact->id
        ]);
    } else {
        $messages = $contact->getMessages();
        return $this->showJsonError((string)$messages[0]);
    }
}
public function editAction($id)
{
    $this->view->disable();

    // 1. Fetch the record from the campaigns_contact_details table
    $contact = \App\Models\CampaignsContactDetails::findFirstById($id);

    // 2. Check if the record exists
    if (!$contact) {
        return $this->showJsonError("Contact with ID $id not found.");
    }

    // 3. Return the data as JSON for Postman or your Frontend
    return $this->response->setJsonContent([
        "status" => "success",
        "data"   => [
            "campaign_name"   => $contact->campaign_name,
            "customer_name" => $contact->customer_name,
            "address"       => $contact->address,
            "city"          => $contact->city,
            "state"         => $contact->state,
            "zipcode"       => $contact->zipcode,
            "country"       => $contact->country,
            "phone_number"  => $contact->phone_number,
            "source_data"   => $contact->source_data,
            "notes"         => $contact->notes
        ]
    ]);
}
public function updateAction()
{
    $this->view->disable();

    // 1. Get JSON body
    $data = $this->request->getJsonRawBody(true);

    // 2. Validate ID is in the data
    if (empty($data['id'])) {
        return $this->showJsonError("ID is required in the JSON body.");
    }

    $id = $data['id'];

    // 3. Find existing record in campaigns_contact_details
    $contact = \App\Models\CampaignsContactDetails::findFirstById($id);

    if (!$contact) {
        return $this->showJsonError("Contact with ID $id not found.");
    }

    // 4. Update only the columns provided in the JSON input
    // The assign method in Phalcon updates only the keys present in the $data array
    $contact->assign($data);

    if ($contact->save()) {
        return $this->response->setJsonContent([
            "status" => "success",
            "message" => "Fields updated successfully for ID $id"
        ]);
    } else {
        $messages = $contact->getMessages();
        return $this->showJsonError("Update failed: " . (string)$messages[0]);
    }
}
public function deleteAction($id)
{
    

    $contact = \App\Models\CampaignsContactDetails::findFirstById($id);

    if (!$contact) {
        return $this->showJsonError("Contact not found");
    }

    if ($contact->delete()) {
        return $this->showJsonResponse("success", "Contact deleted successfully");
    } else {
        return $this->showJsonError("Could not delete contact");
    }
}
 public function bulkUploadCampaignAction()
{
    if (!$this->request->hasFiles()) {
        return $this->showJsonError("No file uploaded");
    }

    $file = $this->request->getUploadedFiles()[0];
    $handle = fopen($file->getTempName(), 'r');
    $header = fgetcsv($handle); // Skip header row

    $this->db->begin();

    try {
        $rowNumber = 1;
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $rowNumber++;

            // 1. Extract values based on UI fields
            $campaignName = trim($row[0] ?? '');
            $customerName = trim($row[1] ?? '');
            $rawPhone     = trim($row[7] ?? '');

            // 2. REFINED CLEANING: Remove Excel wrapping but keep spaces and +
            // This transforms '=" +65 7893 5866"' into '+65 7893 5866'
            $phoneNumber = str_replace(['="', '"', '='], '', $rawPhone);
            $phoneNumber = trim($phoneNumber); // Removes leading/trailing spaces only

            // 3. Mandatory Field Validation
            if (empty($campaignName) || empty($customerName) || empty($phoneNumber)) {
                throw new \Exception("Row $rowNumber: Mandatory data missing (Campaign name,customer Name, or Phone)");
            }
if (!empty($phoneNumber) && substr($phoneNumber, 0, 1) !== '+') {
    $phoneNumber = '+' . $phoneNumber;
}
            // 4. Create record in campaigns_contact_details table
            $contact = new \App\Models\CampaignsContactDetails();
            $contact->assign([
                'campaign_name' => $campaignName,
                'customer_name' => $customerName,
                'address'       => $row[2] ?? null,
                'city'          => $row[3] ?? null,
                'state'         => $row[4] ?? null,
                'zipcode'       => $row[5] ?? null,
                'country'       => $row[6] ?? null,
                'phone_number'  => $phoneNumber, // Saves '+65 7893 5866' or '6598754466'
                'source_data'   => $row[8] ?? null,
                'notes'         => $row[9] ?? null
            ]);

            if (!$contact->save()) {
                $messages = $contact->getMessages();
                throw new \Exception("Row $rowNumber: " . (string)$messages[0]);
            }
        }
        
        $this->db->commit();
        fclose($handle);
        return $this->showJsonResponse("success", "Bulk upload successful");

    } catch (\Exception $e) {
        $this->db->rollback();
        if ($handle) fclose($handle);
        return $this->showJsonError(\ControllerBase::safeErrorMessage($e));
    }
}
    /**
     * GET /campaign/list
     */
   public function getCampaignlistAction()
{
    // Fetches records from the campaigns_contact_details table via the model
    $contacts = \App\Models\CampaignsContactDetails::find([
        "order" => "created_at DESC"
    ]);

    return $this->response->setJsonContent([
        "status" => "success",
        "data"   => $contacts
    ]);
}

    /**
     * GET /campaign/downloadCsv
     */
 public function downloadCsvAction()
{
    $this->view->disable();

    $folderPath = BASE_PATH . '/public/samples/';
    $fileName = 'campaign_contact_template.csv';
    $filePath = $folderPath . $fileName;

    if (!is_dir($folderPath)) {
        mkdir($folderPath, 0755, true);
    }

    $fp = fopen($filePath, 'w');

    // Headers based on UI fields
    fputcsv($fp, [
        'campaign_name', 'customer_name', 'address', 'city', 
        'state', 'zipcode', 'country', 'phone_number', 
        'source_data', 'notes'
    ]);

    /**
     * FIX: Use the =" " format to force Excel to display the prefix and number as TEXT.
     * This prevents 4.47701E+11 and keeps the + symbol visible.
     */
    $sampleData = [
        'campaign_name' => '', 
        'customer_name' => '',
        'address'       => '',
        'city'          => '',
        'state'         => '',
        'zipcode'       => '',
        'country'       => '',
        'phone_number'  => '', // Forces + and spaces to stay
        'source_data'   => '',
        'notes'         => ''
    ];

    fputcsv($fp, array_values($sampleData));
    fclose($fp);

    $downloadUrl = $this->request->getScheme() . "://" . $this->request->getHttpHost() . "/samples/" . $fileName;

    return $this->response->setJsonContent([
        "status" => "success", 
        "link"   => $downloadUrl
    ]);
}
    private function showJsonError($msg) {
        return $this->response->setJsonContent(["status" => "error", "message" => $msg]);
    }

    private function showJsonResponse($status, $msg) {
        return $this->response->setJsonContent(["status" => $status, "message" => $msg]);
    }
}