<?php
use Phalcon\Mvc\View;

use ElephantIO\Client;

class SmsController extends ControllerBase
{
    public function indexAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setContentType("application/json", "UTF-8");

        $rawBody = $this->request->getRawBody();
        $data = json_decode($rawBody, true);
        // $type_form_data = $_POST['type'] ?? null;
        $type_form_data = $this->request->getPost('type', 'string');
        // sanitize incoming fields
        $licenseKey = $this->filter->sanitize($data['licenseKey'] ?? '', "string");
        $type = $this->filter->sanitize($data['type'] ?? '', "string");
        $countryCode = $this->filter->sanitize($data['countryCode'] ?? '', "string");
        // $phoneNo = $this->filter->sanitize($data['phoneNo'] ?? '', "string");
        // $url = $this->filter->sanitize($data['url'] ?? '', "string");
        $companyName = $this->filter->sanitize($data['companyName'] ?? '', "string");
        $username = $this->filter->sanitize($data['username'] ?? '', "string");
        $password = $this->filter->sanitize($data['password'] ?? '', "string");
        $message = $this->filter->sanitize($data['message'] ?? '', "string");
        $dataArray = $data['data'] ?? [];
        $sanitizedArray = array_map(function ($item) {
            return [
                'phoneNo' => $this->filter->sanitize($item['phoneNo'] ?? '', 'string'),
                'senderId' => $this->filter->sanitize($item['senderId'] ?? '', 'string'),
                'url' => $this->filter->sanitize($item['url'] ?? '', 'string'),
                'countryCode' => $this->filter->sanitize($item['countryCode'] ?? '', 'string'),
                'message' => $this->filter->sanitize($item['message'] ?? '', 'string'),
            ];
        }, $dataArray);
        $sender_id = $this->filter->sanitize($data['countryCode'] ?? '', "string");

        if ($type == "1") {
            $postData = [
                // 'licenseKey' => $licenseKey,
                'type' => $type,
                "userId" => "MTY1Mw==",
                "licenseKey" => "b67ac-e3f57-85499-ca0fa"
            ];
            $jsonBody = json_encode($postData);

            // Initialize cURL
            $ch = curl_init('https://licensekey.ringq.com/process/sms');

            // Set cURL options for raw JSON
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Execute and get response
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);


            if ($response) {
                $this->response->setContent($response);
                $this->response->send(); // <<< this actually sends the content to the client
            } else {
                // If no response (false), return a generic error
                $this->response->setContent(json_encode([
                    'success' => false,
                    'error' => 'Failed to get a response from the server'
                ]));
            }
        } else if ($type == "2") {
            $postData = [
                // 'licenseKey' => $licenseKey,
                'type' => $type,
                'countryCode' => $countryCode,
                "userId" => "MTY1Mw==",
                "licenseKey" => "b67ac-e3f57-85499-ca0fa"
            ];
            $jsonBody = json_encode($postData);

            // Initialize cURL
            $ch = curl_init('https://licensekey.ringq.com/process/sms');

            // Set cURL options for raw JSON
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Execute and get response
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);


            if ($response) {
                $this->response->setContent($response);
                $this->response->send(); // <<< this actually sends the content to the client
            } else {
                // If no response (false), return a generic error
                $this->response->setContent(json_encode([
                    'success' => false,
                    'error' => 'Failed to get a response from the server'
                ]));
            }
        } else if ($type == "3") {
            $postData = [
                "type" => "3",
                // "licenseKey" => $licenseKey,
                "companyName" => $companyName,
                "username" => $username,
                "password" => $password,
                "data" => $sanitizedArray,   // ✅ correct, do NOT wrap in [ ... ]
                "userId" => "MTY1Mw==",
                "licenseKey" => "b67ac-e3f57-85499-ca0fa"
            ];

            $jsonBody = json_encode($postData);

            // Initialize cURL
            $ch = curl_init('https://licensekey.ringq.com/process/sms');

            // Set cURL options for raw JSON
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Execute and get response
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response) {
                $this->response->setContent($response);
                $this->response->send(); // <<< this actually sends the content to the client
            } else {
                // If no response (false), return a generic error
                $this->response->setContent(json_encode([
                    'success' => false,
                    'error' => 'Failed to get a response from the server'
                ]));
            }
        } else if ($type_form_data == "4") {
            $type = $this->request->getPost('type', 'string');
            $licenseKey = $this->request->getPost('licenseKey', 'string');
            $sender_id = $this->request->getPost('sender_id', 'string');
            $password = $this->request->getPost('password', 'string');
            $username = $this->request->getPost('username', 'string');
            $companyName = $this->request->getPost('companyName', 'string');

            if (!isset($_FILES['phoneNo'])) {
                // Return error if file not sent
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => 'CSV file (phoneNo) not uploaded'
                ]);
                exit;
            }

            // CSV file details
            $tmpPath = $_FILES['phoneNo']['tmp_name'];
            $filename = $_FILES['phoneNo']['name'];
            $mimeType = 'text/csv';

            // Prepare POST data for external API
            $postData = [
                'type' => $type,
                // 'licenseKey' => $licenseKey,
                'senderId' => $sender_id,
                'companyName' => $companyName,
                'username' => $username,
                'password' => $password,
                'phoneNo' => new CURLFile($tmpPath, $mimeType, $filename),
                "userId" => "MTY1Mw==",
                "licenseKey" => "b67ac-e3f57-85499-ca0fa"
            ];

            // Optional: Add any other fields you want to forward
            if (isset($_POST['countryCode'])) {
                $postData['countryCode'] = $_POST['countryCode'];
            }
            if (isset($_POST['url'])) {
                $postData['url'] = $_POST['url'];
            }
            if (isset($_POST['message'])) {
                $postData['message'] = $_POST['message'];
            }

            // Initialize cURL
            $ch = curl_init('https://licensekey.ringq.com/process/bulksms');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            // Execute request
            $response = curl_exec($ch);

            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);

                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'error' => "cURL error: $error"
                ]);
                exit;
            }

            curl_close($ch);

            // Return response from external API directly
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'external_response' => json_decode($response, true),
                'datas' => $postData
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'File does not exist'
            ]);
        }

    }




public function tikosconfigAction()
{
    $this->view->disable();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    $data = json_decode(file_get_contents("php://input"), true);

      // error_log("RAW INPUT: " . $raw);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
        return $this->respondWithError(400, 'Invalid JSON');
    }

    $type = isset($data['type'])
        ? (int) $this->filter->sanitize($data['type'], 'int')
        : 0;

    if (!$type) {
        return $this->respondWithError(400, 'Invalid type');
    }

    // $fqdn = isset($data['fqdn'])
    //     ? trim($this->filter->sanitize($data['fqdn'], 'string'))
    //     : '';


  $session = $this->session->get('auth');
    $fqdn = $session['subdomain'] ?? null;

    if ($fqdn === '') {
        return $this->respondWithError(400, 'fqdn is required');
    }

    $redisKey = "tikos:sms:config:fqdn:{$fqdn}";

    /* =========================
       TYPE 1 — CREATE
    ========================== */
    if ($type === 1) {

        foreach (['name', 'accessToken'] as $field) {
            if (empty($data[$field])) {
                return $this->respondWithError(400, "$field is required");
            }
        }

$name = $this->filter->sanitize($data['name'], 'string');
$accessToken = $this->filter->sanitize($data['accessToken'], 'string');

if (mb_strlen($name) > 15) {
    return $this->respondWithError(
        422,
        'Name must not be greater than 15 characters'
    );
}



           $security = new \Phalcon\Security\Random();
                $id = $security->uuid();




        $payload = [
            'id'      => $id,
            'fqdn'    => $fqdn,
            'name'    => $name,
            'accessToken'    => $accessToken,
            'created' => time()
        ];

        $this->redis->lPush($redisKey, json_encode($payload));

        echo json_encode([
            'result' => 'success',
            'message' => 'Successfully Added a Configuration',
            'id'     => $id
        ]);
        return;
    }

if ($type === 2) {
    
    $page  = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
    $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));
    $start = ($page - 1) * $limit;

    $searchName = isset($data['searchName'])
        ? strtolower(trim($this->filter->sanitize($data['searchName'], 'string')))
        : '';

    // Only fetch a slice if no search term
    if ($searchName === '') {
        $rowsRaw = $this->redis->lRange($redisKey, $start, $start + $limit - 1);
        $rowsDecoded = array_map(fn($r) => json_decode($r, true), $rowsRaw);

        $total = $this->redis->lLen($redisKey);
        $rows  = $rowsDecoded;
    } else {
        // If searching, must fetch all to filter
        $allRows = $this->redis->lRange($redisKey, 0, -1);
        $rowsDecoded = array_map(fn($r) => json_decode($r, true), $allRows);

        $rowsFiltered = array_values(array_filter(
            $rowsDecoded,
            fn($row) => !empty($row['name']) && stripos($row['name'], $searchName) !== false
        ));

        $total = count($rowsFiltered);
        $rows  = array_slice($rowsFiltered, $start, $limit);
    }

    echo json_encode([
        'result' => 'success',
        'data'   => $rows,
        'pagination' => [
            'totalRecords' => $total,
            'totalPages'   => ceil($total / $limit),
            'currentPage'  => $page,
            'limit'        => $limit
        ]
    ]);
    return;
}


    /* =========================
       TYPE 3 — UPDATE (ONLY name & accessToken)
    ========================== */
    if ($type === 3) {

        $itemId = isset($data['id'])
            ? (string) $this->filter->sanitize($data['id'], 'string')
            : 0;

        if (!$itemId) {
            return $this->respondWithError(400, 'Invalid ID');
        }

        $updatableFields = ['name', 'accessToken'];

        $hasUpdate = false;
        foreach ($updatableFields as $field) {
            if (isset($data[$field])) {
                $hasUpdate = true;
                break;
            }
        }

        if (!$hasUpdate) {
            return $this->respondWithError(400, 'No fields to update');
        }


            // Early check for name length
    if (isset($data['name']) && mb_strlen($data['name']) > 15) {
        return $this->respondWithError(
            422,
            'Name must not be greater than 15 characters'
        );
    }


        $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
        $updated = false;

        foreach ($rowsRaw as $index => $row) {
            $decoded = json_decode($row, true);

            if ($decoded['id'] == $itemId) {
                foreach ($updatableFields as $field) {
                    if (isset($data[$field])) {
                        $decoded[$field] = $this->filter->sanitize($data[$field], 'string');
                    }
                }

                $this->redis->lSet($redisKey, $index, json_encode($decoded));
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            return $this->respondWithError(404, 'Record not found');
        }

        echo json_encode([
            'result'  => 'success',
            'message' => 'Successfully Updated a Configuration'
        ]);
        return;
    }

    /* =========================
       TYPE 4 — DELETE
    ========================== */
    if ($type === 4) {

        $itemId = isset($data['id'])
            ? (string) $this->filter->sanitize($data['id'], 'string')
            : 0;

        if (!$itemId) {
            return $this->respondWithError(400, 'Invalid ID');
        }

        $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
        $deleted = false;

        foreach ($rowsRaw as $row) {
            $decoded = json_decode($row, true);
            if ($decoded['id'] == $itemId) {
                $this->redis->lRem($redisKey, 1, $row);
                $deleted = true;
                break;
            }
        }

        if (!$deleted) {
            return $this->respondWithError(404, 'Record not found');
        }

        echo json_encode([
            'result'  => 'success',
            'message' => 'Successfully Deleted a Configuration'
        ]);
        return;
    }



    return $this->respondWithError(400, 'Invalid type');
}

public function tikosbulkAction()
{
    $this->view->disable();

    // ---- FILE HANDLING ----
    if (!isset($_FILES['phoneNo']) || $_FILES['phoneNo']['error'] !== UPLOAD_ERR_OK) {
        return $this->respondWithError(400, 'Missing or invalid phoneNo file');
    }

    $file = $_FILES['phoneNo'];

    if (!is_uploaded_file($file['tmp_name'])) {
        return $this->respondWithError(400, 'Possible file upload attack detected');
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        return $this->respondWithError(400, 'CSV file too large (max 2MB)');
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'csv') {
        return $this->respondWithError(400, 'Only CSV files are allowed');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMime = ['text/plain','text/csv','application/csv','application/vnd.ms-excel'];
    if (!in_array($mime, $allowedMime, true)) {
        return $this->respondWithError(400, 'Invalid CSV MIME type');
    }

    $firstBytes = file_get_contents($file['tmp_name'], false, null, 0, 256);
    if (preg_match('/<\?php|<script|#!\/bin/i', $firstBytes)) {
        return $this->respondWithError(400, 'Malicious content detected in file');
    }

    // Read CSV rows
    if (($handle = fopen($file['tmp_name'], 'r')) === false) {
        return $this->respondWithError(400, 'Unable to read CSV file');
    }

    $header = fgetcsv($handle); // optional header
    $numbers = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($header) {
            $rowData = array_combine($header, $row);
            $numbers[] = trim($rowData['phone_no'] ?? $row[0]);
        } else {
            $numbers[] = trim($row[0]);
        }
    }
    fclose($handle);

    if (empty($numbers)) {
        return $this->respondWithError(400, 'No phone numbers found in CSV');
    }

// ---- CONFIG FETCHING ----
$fields = [
    'id'      => $_POST['id'] ?? '',
    'message' => $_POST['message'] ?? '',
];

$missing = [];

foreach ($fields as $key => $value) {
    $fields[$key] = trim($this->filter->sanitize($value, 'string'));
    if ($fields[$key] === '') {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    return $this->respondWithError(400, 'Missing required field(s): ' . implode(', ', $missing));
}

// Now you have sanitized variables:


  $session = $this->session->get('auth');
    $fqdn = $session['subdomain'] ?? null;

   
$id      = $fields['id'];
$message = $fields['message'];

if ($fqdn === '') {
        return $this->respondWithError(400, 'fqdn is required');
    }


    $redisKey = "tikos:sms:config:fqdn:{$fqdn}";
    $configsRaw = $this->redis->lRange($redisKey, 0, -1);
    if (empty($configsRaw)) {
        return $this->respondWithError(400, 'No provider configurations found');
    }

    $configs = array_map(fn($r) => json_decode($r, true), $configsRaw);

    $matched = null;
    foreach ($configs as $config) {
        if (!empty($config['id']) && $config['id'] === $id) {
            $matched = $config;
            break;
        }
    }

    if (!$matched) {
        return $this->respondWithError(404, 'Config not found for given id');
    }

    // ---- SEND BULK ----
    $apiToken = $matched['accessToken'];
    $apiUrl   = 'https://api.tikosms.com/v1/messages';

    // Prepare recipients array
    $recipients = [];
    foreach ($numbers as $index => $num) {
        $recipients[] = [
            'to' => $num,
            'client_ref' => $fqdn
        ];
    }

    $payload = [
        'from'       => $matched['name'],
        'message'    => $message,
        'recipients' => $recipients,
        'callback_url' => "https://{$fqdn}:8443/sms/tikosmswebhook"
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error) {
        return $this->respondWithError(500, $error);
    }

    $decoded = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    echo json_encode([
        'result' => $isSuccess ? 'success' : 'failed',
        'httpCode' => $httpCode,
        'message' => $decoded['message'] ?? ($isSuccess ? 'Bulk SMS sent successfully' : 'Bulk SMS sending failed'),
        'recipients' => $recipients
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    return;
}



public function tikossingleAction()
{
    $this->view->disable();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    $data = json_decode(file_get_contents("php://input"), true);
    error_log("RAW INPUT: " . $raw);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
        return $this->respondWithError(400, 'Invalid JSON');
    }

    // Validate required fields
    $fields = ['id', 'message', 'phoneNo'];
    $values = [];
    foreach ($fields as $field) {
        $value = trim($this->filter->sanitize($data[$field] ?? '', 'string'));
        if ($value === '') {
            return $this->respondWithError(400, "Invalid {$field} Information");
        }
        $values[$field] = $value;
    }

    $id      = $values['id'];
    $message = $values['message'];

    $numbers = array_filter(array_map('trim', explode(',', $values['phoneNo'])));
    $totalNumbers = count($numbers);

    if ($totalNumbers === 0) {
        return $this->respondWithError(400, 'No valid numbers provided');
    }

    if ($totalNumbers > 10) {
        return $this->respondWithError(400, 'Cannot send to more than 10 numbers at once');
    }



    // $fqdn = trim($this->filter->sanitize($data['fqdn'] ?? '', 'string'));

  $session = $this->session->get('auth');
    $fqdn = $session['subdomain'] ?? null;

   if ($fqdn === '') {
        return $this->respondWithError(400, 'fqdn is required');
    }

    $redisKey = "tikos:sms:config:fqdn:{$fqdn}";

    // Load provider configs
    $configsRaw = $this->redis->lRange($redisKey, 0, -1);
    if (empty($configsRaw)) {
        return $this->respondWithError(400, 'No provider configurations found');
    }

    $configs = array_map(fn($r) => json_decode($r, true), $configsRaw);

    // Find config by ID
    $matched = null;
    foreach ($configs as $config) {
        if (!empty($config['id']) && $config['id'] === $id) {
            $matched = $config;
            break;
        }
    }

    if (!$matched) {
        return $this->respondWithError(404, 'Config not found for given id');
    }

    $apiToken = $matched['accessToken'];
    $apiUrl   = 'https://api.tikosms.com/v1/message';

    $results = [];
    $anyFailed = false;

    foreach ($numbers as $number) {
        $payload = [
            'from'         => $matched['name'],
            'message'      => $message,
            'to'           => $number,
            'callback_url' => "https://{$fqdn}:8443/sms/tikosmswebhook",
            'client_ref'   => $fqdn,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $results[] = [
                'number' => $number,
                'result' => 'failed',
                'error'  => $error,
            ];
            $anyFailed = true;
            continue;
        }

        $decoded = json_decode($response, true);
        $isSuccess = ($httpCode >= 200 && $httpCode < 300);
        if (!$isSuccess) {
            $anyFailed = true;
        }

        $respMessage =
            $decoded['message']
            ?? $decoded['response']['message']
            ?? ($isSuccess ? 'SMS sent successfully' : 'SMS sending failed');

        $results[] = [
            'number'   => $number,
            'result'   => $isSuccess ? 'success' : 'failed',
            'httpCode' => $httpCode,
            'message'  => $respMessage,
        ];
    }

    // Set main result and message
    $mainResult = $anyFailed ? 'failed' : 'success';
    $mainMessage = $anyFailed ? 'Some SMS failed to send' : 'All SMS sent successfully';

    echo json_encode([
        'result' => $mainResult,
        'message' => $mainMessage,
        'data'   => $results,
    ]);

    return;
}

public function tikosmswebhookAction()
{
    $this->view->disable();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        return;
    }

    // Soft validation: source UA
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($userAgent, 'TikoSMS-Callback') === false) {
        http_response_code(403);
        return;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE || empty($payload)) {
        http_response_code(400);
        return;
    }

    // Detect callback type
    if (isset($payload['create'])) {
        $data = $payload['create'];
        $eventType = 'create';
    } elseif (isset($payload['update'])) {
        $data = $payload['update'];
        $eventType = 'update';
    } else {
        http_response_code(400);
        return;
    }

    if (empty($data['message_id']) || empty($data['status'])) {
        http_response_code(400);
        return;
    }
        $record = [
            'event'       => $eventType,
            'messageId'   => $this->filter->sanitize($data['message_id'], 'string'),
            'status'      => $data['status'],
            'from'        => $data['from'] ?? null,
            'to'          => $data['to'] ?? null,
            'clientRef'   => $data['client_ref'] ?? null,
            'mccmnc'      => $data['mccmnc'] ?? null,
            'cost'        => $data['cost'] ?? null,
            'part'        => $data['part'] ?? null,
            'totalParts'  => $data['total_parts'] ?? null,
            'sentAt'      => $data['sent_at'] ?? null,
            'deliveredAt' => $data['delivered_at'] ?? null,
            'timestamp'   => $data['timestamp'] ?? date('c'),
            'receivedAt'  => time(),
        ];

        // Make sure client_ref exists, else use a default
        $clientRef = $data['client_ref'] ?? 'default';
        $redisKey = "sms:callback:tiko:" . $clientRef;

        // Append to Redis list
        $this->redis->lPush($redisKey, json_encode($record));

        http_response_code(200);
        echo json_encode(['result' => 'ok']);
        return;

}
public function telnyxwebhookAction()
{
    $this->view->disable();

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);

    if (!$data) {
        return;
    }

    // // ---------- LOG TO REDIS USING $this->redis ----------
    // try {
    //     $this->redis->lPush('telnyx_webhook_logs', json_encode($data, JSON_PRETTY_PRINT));
    //     // Optional: keep only last 1000 logs
    //     $this->redis->lTrim('telnyx_webhook_logs', 0, 999);
    // } catch (\Exception $e) {
    //     error_log("Redis LPUSH failed: " . $e->getMessage());
    // }

    // 1. Extract payload & event type
    $payload = $data['data']['payload'] ?? $data;
    $eventType = $data['data']['event_type'] ?? $data['event_type'] ?? 'message.received';

    // --- NEW VALIDATION: Only accept if status is 'webhook_delivered' ---
    $status = $payload['to'][0]['status'] ?? null;
    if ($status !== 'webhook_delivered') {
        http_response_code(200);
        echo '<Response>Ignored: Status is ' . $status . '</Response>';
        return;
    }

    // 2. Normalize phone numbers
    $fromPhone = $payload['from']['phone_number'] ?? null;
    if ($fromPhone) $fromPhone = ltrim($fromPhone, '+');

    $toPhone = $payload['to'][0]['phone_number'] ?? null;
    if ($toPhone) $toPhone = ltrim($toPhone, '+');

    // 3. Extract FQDN from webhook_url
    $webhookUrl = $payload['webhook_url'] ?? null;
    $fqdn = $webhookUrl ? parse_url($webhookUrl, PHP_URL_HOST) : null;

    $event = [
          'event_type'     => $eventType,
        'customerNumber' => $fromPhone,
        'ourNumber'      => $toPhone,
           'text'           => $payload['text'] ?? null,
        'fqdn'           => $fqdn
    ];

    // ---------- WEBSOCKET EMIT ----------

    $client = new Client(
        Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
            "persistent" => false,
        ])
    );

    $client->initialize();

    $payload = json_encode($event);
    $content = (array) json_decode($payload, true);


    $client->emit("telnyx", $event);


    // 6. Response
    header('Content-Type: text/xml');
    http_response_code(200);
    echo '<Response></Response>';
}


public function twiliowebhookAction()
{
    $this->view->disable();

    // ---- POST ----
    $from = $_POST['From'] ?? null;
    $to   = $_POST['To'] ?? null;
    $body = $_POST['Body'] ?? null;
    $accountSid = $_POST['AccountSid'] ?? null;
    $messagingServiceSid = $_POST['MessagingServiceSid'] ?? null;

    // ---- Host ----
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $cleanHost = explode(':', $host)[0];


    

    if (!$from || !$to || !$accountSid || !$messagingServiceSid) {
        http_response_code(400);
        echo '<Response>Missing params</Response>';
        return;
    }

    // ---- Normalize ----
    $from = ltrim($from, '+');
    $to   = ltrim($to, '+');

  
    // ---------------- EMIT ----------------

    $client = new Client(
        Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
            "persistent" => false
        ])
    );
    $client->initialize();

    $event = [
        'event_type'          => 'message.received',
        'customerNumber'      => $from,
        'ourNumber'           => $to,
        'accountSid'          => $accountSid,
        'messagingServiceSid' => $messagingServiceSid,
        'text'                => $body,
        'fqdn'                => $cleanHost
    ];

  $payload = json_encode($event);

 
                $content = (array) json_decode($payload, true);


    $client->emit("twilio", $content);

    header('Content-Type: text/xml');
    http_response_code(200);
    echo '<Response></Response>';
}





public function telnyxconfigAction()
{
    $this->view->disable();
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        return $this->respondWithError(400, 'Invalid JSON');
    }

    $type = (int) ($data['type'] ?? 0);

    if (!in_array($type, [1,2,3,4], true)) {
        return $this->respondWithError(400, 'Invalid type');
    }

    $session = $this->session->get('auth');
    $fqdn = $session['subdomain'] ?? null;






    if (!$fqdn) {
        return $this->respondWithError(400, 'fqdn is required');
    }

    $redisKey = "telnyx:sms:config:$fqdn";

    /* ================= CREATE ================= */
    if ($type === 1) {
        foreach (['name','phoneNumber','accessToken'] as $f) {
            if (empty($data[$f])) {
                return $this->respondWithError(400, "$f is required");
            }
        }

        $name        = trim($this->filter->sanitize($data['name'], 'string'));
        $phoneNumber = trim($this->filter->sanitize($data['phoneNumber'], 'string'));
        $accessToken = trim($this->filter->sanitize($data['accessToken'], 'string'));

        if (mb_strlen($name) > 15) {
            return $this->respondWithError(422, 'Name too long');
        }

        /* ===== Prevent Duplicate Phone Number ===== */

        $records = $this->redis->lRange($redisKey, 0, -1);

        foreach ($records as $row) {
            $decoded = json_decode($row, true);

            if (isset($decoded['phoneNumber']) && $decoded['phoneNumber'] === $phoneNumber) {
                return $this->respondWithError(409, 'Phone number already exists');
            }
        }

        /* ===== Create Record ===== */

        $id = (new \Phalcon\Security\Random())->uuid();

        $payload = [
            'id' => $id,
            'fqdn' => $fqdn,
            'name' => $name,
            'phoneNumber' => $phoneNumber,
            'accessToken' => $accessToken,
            'created' => time()
        ];

        $this->redis->lPush($redisKey, json_encode($payload));

        echo json_encode([
            'result' => 'success',
            'message' => 'Successfully Added Configuration',
            'id' => $id
        ]);
        return;

    } elseif ($type === 2) {

        /* ================= READ ================= */

        $page  = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
        $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));

        $start = ($page - 1) * $limit;
        $end   = $start + $limit - 1;

        $records = $this->redis->lRange($redisKey, $start, $end);
        $total   = $this->redis->lLen($redisKey);

        $list = [];
        foreach ($records as $r) {
            $list[] = json_decode($r, true);
        }

        echo json_encode([
            'result' => 'success',
            'data' => $list,
            'pagination' => [
                'currentPage' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => ceil($total / $limit)
            ]
        ]);
        return;

    } elseif ($type === 3) {

        /* ================= UPDATE ================= */

        $id = trim($data['id'] ?? '');

        if (!$id) {
            return $this->respondWithError(400, 'id is required');
        }

        $records = $this->redis->lRange($redisKey, 0, -1);

        $targetIndex = null;
        $targetItem  = null;

        /* ---- find target record ---- */
        foreach ($records as $index => $record) {
            $item = json_decode($record, true);

            if ($item['id'] === $id) {
                $targetIndex = $index;
                $targetItem  = $item;
                break;
            }
        }

        if ($targetIndex === null) {
            return $this->respondWithError(404, 'Record not found');
        }

        $newPhone = trim($this->filter->sanitize($data['phoneNumber'] ?? $targetItem['phoneNumber'], 'string'));

        /* ---- prevent duplicate phone number ---- */
        foreach ($records as $record) {
            $item = json_decode($record, true);

            if ($item['id'] !== $id && isset($item['phoneNumber']) && $item['phoneNumber'] === $newPhone) {
                return $this->respondWithError(409, 'Phone number already exists');
            }
        }

        /* ---- update record ---- */
        $targetItem['name']        = trim($this->filter->sanitize($data['name'] ?? $targetItem['name'], 'string'));
        $targetItem['phoneNumber'] = $newPhone;
        $targetItem['accessToken'] = trim($this->filter->sanitize($data['accessToken'] ?? $targetItem['accessToken'], 'string'));

        $this->redis->lSet($redisKey, $targetIndex, json_encode($targetItem));

        echo json_encode([
            'result' => 'success',
            'id' => $id,
            'message' => 'Configuration updated'
        ]);
        return;


    } elseif ($type === 4) {

        /* ================= DELETE ================= */

        $itemId = isset($data['id']) ? (string)$this->filter->sanitize($data['id'], 'string') : '';

        if (!$itemId) {
            return $this->respondWithError(400, 'Invalid ID');
        }

        $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
        $deleted = false;

        foreach ($rowsRaw as $row) {
            $decoded = json_decode($row, true);

            if ($decoded['id'] === $itemId) {
                $this->redis->lRem($redisKey, 1, $row);
                $deleted = true;
                break;
            }
        }

        if (!$deleted) {
            return $this->respondWithError(404, 'Record not found');
        }

        echo json_encode([
            'result' => 'success',
            'id' => $itemId,
            'message' => 'Configuration deleted'
        ]);
        return;
    }
}



public function twilioconfigAction()
{
    $this->view->disable();
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data)) {
        return $this->respondWithError(400, 'Invalid JSON');
    }

    $type = (int) ($data['type'] ?? 0);

    if (!in_array($type, [1,2,3,4], true)) {
        return $this->respondWithError(400, 'Invalid type');
    }

    $session = $this->session->get('auth');
    $fqdn = $session['subdomain'] ?? null;

 
    if (!$fqdn) {
        return $this->respondWithError(400, 'fqdn is required');
    }

    $redisKey = "twilioconfig:sms:config:$fqdn";

    /* ================= CREATE ================= */
    if ($type === 1) {

foreach (['name','phoneNumber','accessToken','accountSid','messagingServiceSid'] as $f) {
    if (empty($data[$f])) {
        return $this->respondWithError(400, "$f is required");
    }
}

$name            = trim($this->filter->sanitize($data['name'], 'string'));
$phoneNumber     = trim($this->filter->sanitize($data['phoneNumber'], 'string'));
$accessToken     = trim($this->filter->sanitize($data['accessToken'], 'string'));
$accountSid      = trim($this->filter->sanitize($data['accountSid'], 'string'));
$MessagingServiceSid = trim($this->filter->sanitize($data['messagingServiceSid'], 'string'));

if (mb_strlen($name) > 15) {
    return $this->respondWithError(422, 'Name too long');
}

/* ==== Prevent Duplicate Phone Number ==== */
$records = $this->redis->lRange($redisKey, 0, -1);
foreach ($records as $row) {
    $decoded = json_decode($row, true);
    if (isset($decoded['phoneNumber']) && $decoded['phoneNumber'] === $phoneNumber) {
        return $this->respondWithError(409, 'Phone number already exists');
    }
}

/* ==== Create Record ==== */
$id = (new \Phalcon\Security\Random())->uuid();

$payload = [
    'id' => $id,
    'fqdn' => $fqdn,
    'name' => $name,
    'phoneNumber' => $phoneNumber,
    'accessToken' => $accessToken,
    'accountSid' => $accountSid,
    'messagingServiceSid' => $MessagingServiceSid,
    'created' => time()
];

$this->redis->lPush($redisKey, json_encode($payload));

echo json_encode([
    'result' => 'success',
    'message' => 'Successfully Added Configuration',
    'id' => $id
]);
return;


    } elseif ($type === 2) {

        /* ================= READ ================= */

        $page  = max(1, (int) $this->filter->sanitize($data['currentPage'] ?? 1, 'int'));
        $limit = max(1, (int) $this->filter->sanitize($data['limit'] ?? 10, 'int'));

        $start = ($page - 1) * $limit;
        $end   = $start + $limit - 1;

        $records = $this->redis->lRange($redisKey, $start, $end);
        $total   = $this->redis->lLen($redisKey);

        $list = [];
        foreach ($records as $r) {
            $list[] = json_decode($r, true);
        }

        echo json_encode([
            'result' => 'success',
            'data' => $list,
            'pagination' => [
                'currentPage' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => ceil($total / $limit)
            ]
        ]);
        return;

    } elseif ($type === 3) {

         /* ================= UPDATE ================= */

        $id = trim($data['id'] ?? '');

        if (!$id) {
            return $this->respondWithError(400, 'id is required');
        }

        $records = $this->redis->lRange($redisKey, 0, -1);

        $targetIndex = null;
        $targetItem  = null;

        /* ---- find the record to update ---- */
        foreach ($records as $index => $record) {
            $item = json_decode($record, true);

            if ($item['id'] === $id) {
                $targetIndex = $index;
                $targetItem  = $item;
                break;
            }
        }

        if ($targetIndex === null) {
            return $this->respondWithError(404, 'Record not found');
        }

        /* ---- sanitize new values ---- */
        $newName        = trim($this->filter->sanitize($data['name'] ?? $targetItem['name'], 'string'));
        $newPhoneNumber = trim($this->filter->sanitize($data['phoneNumber'] ?? $targetItem['phoneNumber'], 'string'));
        $newAccessToken = trim($this->filter->sanitize($data['accessToken'] ?? $targetItem['accessToken'], 'string'));
        $newAccountSid  = trim($this->filter->sanitize($data['accountSid'] ?? $targetItem['accountSid'], 'string'));
        $newMessagingServiceSid = trim($this->filter->sanitize($data['messagingServiceSid'] ?? $targetItem['messagingServiceSid'], 'string'));

        /* ---- prevent duplicate phone number ---- */
        foreach ($records as $record) {
            $item = json_decode($record, true);

            if ($item['id'] !== $id && isset($item['phoneNumber']) && $item['phoneNumber'] === $newPhoneNumber) {
                return $this->respondWithError(409, 'Phone number already exists');
            }
        }

        /* ---- update record ---- */
        $targetItem['name']              = $newName;
        $targetItem['phoneNumber']       = $newPhoneNumber;
        $targetItem['accessToken']       = $newAccessToken;
        $targetItem['accountSid']        = $newAccountSid;
        $targetItem['messagingServiceSid'] = $newMessagingServiceSid;

        $this->redis->lSet($redisKey, $targetIndex, json_encode($targetItem));

        echo json_encode([
            'result' => 'success',
            'id' => $id,
            'message' => 'Configuration updated'
        ]);
        return;


    } elseif ($type === 4) {

         $itemId = isset($data['id']) ? (string) $this->filter->sanitize($data['id'], 'string') : '';
        if (!$itemId) {
            return $this->respondWithError(400, 'Invalid ID');
        }

        $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
        $deleted = false;

        foreach ($rowsRaw as $row) {
            $decoded = json_decode($row, true);
            if ($decoded['id'] === $itemId) {
                $this->redis->lRem($redisKey, 1, $row);
                $deleted = true;
                break;
            }
        }

        if (!$deleted) {
            return $this->respondWithError(404, 'Record not found');
        }

        echo json_encode([
            'result'  => 'success',
              'id'       => $itemId,
           'message' => 'Configuration deleted'
        ]);
        return;

    }
}


public function sendingsingleAction()
{
    $this->view->disable();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        return $this->respondWithError(400, 'Invalid JSON');
    }

    $session = $this->session->get('auth');
    $fqdn = $session['subdomain'] ?? null;

    if (empty($fqdn) || empty($data['id']) || empty($data['phoneNo']) || empty($data['message'])) {
        return $this->respondWithError(400, 'Missing required fields');
    }

    /* ---------- Normalize phones ---------- */
    $phones = array_slice(array_filter(array_map('trim', explode(',', $data['phoneNo']))), 0, 10);

    $payload = [];
    foreach ($phones as $phone) {
        $payload[] = [
            'phoneNo' => $phone,
            'message' => $data['message'],
        ];
    }

    /* ---------- Load configs ---------- */
    $redisKey = "sms:fqdn:omni:config:{$fqdn}";
    $rows = $this->redis->lRange($redisKey, 0, -1);
    if (!$rows) {
        return $this->respondWithError(400, 'No provider configurations found');
    }
    $configs = array_map(fn($r) => json_decode($r, true), $rows);

    /* ---------- Find requested config ---------- */
    $requestedConfig = null;
    $fallbackConfigs = [];
    foreach ($configs as $c) {
        if ((string)$c['id'] === (string)$data['id']) {
            $requestedConfig = $c;
        } else {
            $fallbackConfigs[] = $c;
        }
    }

    // Sort fallback by priority
    usort($fallbackConfigs, fn($a, $b) => ((int)$a['priority']) <=> ((int)$b['priority']));

    // Final provider order
    $providers = $requestedConfig ? array_merge([$requestedConfig], $fallbackConfigs) : $fallbackConfigs;

    /* ---------- Sending Bulk SMS ---------- */
    $results = [];
    $hasError = false;

    foreach ($payload as $item) {
        $rawPhone = preg_replace('/\D+/', '', $item['phoneNo']);
        $sent = false;
        $usedCfg = null;
        $usedSenderIds = [];

        // Track country code match
        $matchedConfig = null;
        $matchedCode = null;
        foreach ($providers as $config) {
            if (empty($config['countryCode']) || empty($config['url']) || empty($config['senderId'])) {
                continue;
            }
            $codes = array_filter(array_map(fn($c) => preg_replace('/\D+/', '', trim($c)), explode(',', $config['countryCode'])));
            foreach ($codes as $cc) {
                if (str_starts_with($rawPhone, $cc)) {
                    $matchedConfig = $config;
                    $matchedCode = $cc;
                    break 2; // exit both loops
                }
            }
        }

        if (!$matchedConfig) {
            return $this->respondWithError(400, "Matched country code not configured for phone {$item['phoneNo']}");
        }

        // Prepare phone without country code
        $phoneNo = substr($rawPhone, strlen($matchedCode));
        if ($phoneNo === '') {
            return $this->respondWithError(400, "Invalid phone number after country code for {$item['phoneNo']}");
        }

        // ---------- Try senderIds ----------
        $senderIdsToTry = [];

        // 1. Request senderId first
        if (!empty($data['senderId'])) {
            $senderIdsToTry[] = $data['senderId'];
        }

        // 2. Config senderId if not already included
        if (!in_array($matchedConfig['senderId'], $senderIdsToTry)) {
            $senderIdsToTry[] = $matchedConfig['senderId'];
        }

        // 3. Other senderIds from config if any (comma separated)
        if (!empty($matchedConfig['otherSenderIds'])) {
            $others = array_map('trim', explode(',', $matchedConfig['otherSenderIds']));
            foreach ($others as $sid) {
                if (!in_array($sid, $senderIdsToTry)) {
                    $senderIdsToTry[] = $sid;
                }
            }
        }

        foreach ($senderIdsToTry as $sid) {
            if (in_array($sid, $usedSenderIds)) {
                continue; // skip already attempted
            }

            $params = [];
            foreach ([
                'sender_id' => $sid,
                'phone_no' => $phoneNo,
                'country_code' => $matchedCode,
                'message' => $item['message'],
                'company_name' => $matchedConfig['company'],
                'username' => $matchedConfig['username'],
                'password' => $matchedConfig['password'],
            ] as $k => $v) {
                $params[] = $k . '=' . str_replace([' ', '&'], ['%20', '%26'], $v);
            }

            $url = rtrim($matchedConfig['url'], '/') . '/api/v1.0/sms/?' . implode('&', $params);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $json = json_decode(ltrim($response, "\xEF\xBB\xBF"), true);
            $usedSenderIds[] = $sid;

            if (json_last_error() === JSON_ERROR_NONE && !empty($json['status']) && !empty($json['result']['status'])) {
                $sent = true;
                $usedCfg = $matchedConfig;
                $usedCfg['senderId'] = $sid;
                break;
            }
        }

        if (!$sent) {
            $hasError = true;
        }

        $results[] = [
            'phoneNo' => $item['phoneNo'],
            'status' => $sent ? 'success' : 'failed',
            'configId' => $sent ? $usedCfg['id'] : null,
            'senderId' => $sent ? $usedCfg['senderId'] : null,
        ];
    }

    /* ---------- Response ---------- */
    echo json_encode([
        'result' => $hasError ? 'error' : 'success',
        'message' => $hasError ? 'Some SMS failed to send' : 'All SMS successfully processed',
        'data' => $results,
    ]);
}



public function sendingbulkAction()
{
    $this->view->disable();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    // ---- INPUT ----
    $id      = $this->filter->sanitize($_POST['id'] ?? '', 'string');
    $message = $this->filter->sanitize($_POST['message'] ?? '', 'string');

    $senderId = $this->filter->sanitize($_POST['senderId'] ?? '', 'string');
// error_log(print_r($_POST, true)); // noisy + unsafe

    if (empty($id) || empty($message)) {
        return $this->respondWithError(400, 'id and message are required');
    }

    $session = $this->session->get('auth');
    $fqdn    = $session['subdomain'] ?? null;

    if (empty($fqdn)) {
        return $this->respondWithError(401, 'Invalid session');
    }

    // ---- FILE ----
    if (!isset($_FILES['phoneNo']) || $_FILES['phoneNo']['error'] !== UPLOAD_ERR_OK) {
        return $this->respondWithError(400, 'CSV file is required');
    }

    $file = $_FILES['phoneNo'];

    if (!is_uploaded_file($file['tmp_name'])) {
        return $this->respondWithError(400, 'Invalid upload');
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        return $this->respondWithError(400, 'CSV file too large (max 2MB)');
    }

    // MIME only (no filename check)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMime = [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
    ];

    if (!in_array($mime, $allowedMime, true)) {
        return $this->respondWithError(400, 'Invalid CSV file');
    }

    // Simple malware check
    $firstBytes = file_get_contents($file['tmp_name'], false, null, 0, 256);
    if (preg_match('/<\?php|<script|#!\/bin/i', $firstBytes)) {
        return $this->respondWithError(400, 'Malicious content detected');
    }

    // CSV structure check
    if (($h = fopen($file['tmp_name'], 'r')) === false || fgetcsv($h) === false) {
        return $this->respondWithError(400, 'Invalid CSV structure');
    }
    fclose($h);

    // ---- LOAD CONFIG BY ID ----
    $redisKey = "sms:fqdn:omni:config:{$fqdn}";
    $rows     = $this->redis->lRange($redisKey, 0, -1);

    if (!$rows) {
        return $this->respondWithError(400, 'No provider configurations found');
    }

    $config = null;
    foreach ($rows as $row) {
        $c = json_decode($row, true);
        if (!empty($c['id']) && (string)$c['id'] === (string)$id) {
            $config = $c;
            break;
        }
    }

    if (!$config) {
        return $this->respondWithError(400, 'Provider configuration not found');
    }




    foreach (['url', 'username', 'password', 'senderId'] as $key) {
        if (empty($config[$key])) {
            return $this->respondWithError(400, "Invalid provider configuration: {$key} missing");
        }
    }

    // ---- SEND BULK ----
    $url = rtrim($config['url'], '/') . '/api/v1.0/sms/bulk_sms/';

    $curlFile = new \CURLFile(
        $file['tmp_name'],
        $mime,
        'bulk_upload.csv'
    );


if (empty($senderId)) {
    // take the first senderId from the comma-separated config
    $senderIds = array_map('trim', explode(',', $config['senderId']));
    $senderId  = $senderIds[0] ?? null;
}

$postData = [
    'company_name' => $config['company'],
    'username'     => $config['username'],
    'password'     => $config['password'],
    'sender_id'    => $senderId,  // <-- use request or first config senderId
    'message'      => $message,
    'csv_file'     => $curlFile,
];



    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return $this->respondWithError(500, 'Curl error: ' . $error);
    }

    // ---- PARSE RESPONSE ----
    $results = [];
    foreach (explode("\n", trim($response)) as $line) {
        if (!$line) continue;

        if (preg_match('/Response for (\d+):\s*(\{.*\})/', $line, $m)) {
            $data = json_decode($m[2], true);

            $results[] = [
                'phoneNo' => $m[1],
                'status'  => (($data['status'] ?? false) && ($data['result']['status'] ?? false))
                    ? 'success'
                    : 'failed',
            ];
        }
    }

    echo json_encode([
        'result'   => 'success',
        'message'  => 'Bulk SMS processed',
        'data'     => $results,
        'configId' => $config['id'],
        'senderId' => $config['senderId'],
    ]);
}




public function dataAction()
{
    $this->view->disable();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return $this->respondWithError(405, 'Method not allowed');
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
        return $this->respondWithError(400, 'Invalid JSON');
    }

    $type = isset($data['type']) ? (int) $this->filter->sanitize($data['type'], 'int') : 0;
    if (!$type) {
        return $this->respondWithError(400, 'Invalid type');
    }

    $session = $this->session->get("auth"); 
    $fqdn = $data['fqdn'] = $session["subdomain"];

    $fqdn = isset($data['fqdn']) ? trim($this->filter->sanitize($data['fqdn'], 'string')) : '';
    if ($fqdn === '') {
        return $this->respondWithError(400, 'fqdn is required');
    }

    $redisKey = "sms:fqdn:omni:config:{$fqdn}";

    /* TYPE 1 — CREATE */
    if ($type === 1) {
$requiredFields = ['name', 'url', 'username', 'password'];
foreach ($requiredFields as $field) {
    if (empty($data[$field])) {
        return $this->respondWithError(400, "$field is required");
    }
}

// Sanitize inputs
$what    = $this->filter->sanitize($data['what'] ?? null, '');
$company = $this->filter->sanitize($data['company'] ?? null, 'string');
$port    = $this->filter->sanitize($data['port'] ?? null, '');

// Validate 'what'
if (!empty($what) && !in_array($what, ['omni', 'routemobile'])) {
    return $this->respondWithError(400, 'Invalid what value, only allowed is omni or routemobile');
}

// Specific validation based on 'what'
if ($what === 'omni') {
    $data['countryCode'] = trim((string)($data['countryCode'] ?? ''));
    if (empty($data['countryCode'])) {
        return $this->respondWithError(400, 'countryCode is required');
    }

    if (empty($company)) {
        return $this->respondWithError(400, 'Invalid company value');
    }

} elseif ($what === 'routemobile') {

    if (empty($port)) {
        return $this->respondWithError(400, 'Invalid port value');
    }
}

// Sender ID validation
$data['senderId'] = trim((string)($data['senderId'] ?? ''));
if (empty($data['senderId'])) {
    return $this->respondWithError(400, 'senderId is required');
}

// Sanitize again for storage
$name     = $this->filter->sanitize($data['name'], 'string');
$url      = $this->filter->sanitize($data['url'], 'string');
$company  = $this->filter->sanitize($data['company'] ?? null, 'string');
$username = $this->filter->sanitize($data['username'], 'string');
$password = $this->filter->sanitize($data['password'], 'string');

/** 🔹 Get next priority */
$rowsRaw = $this->redis->lRange($redisKey, 0, -1);
$maxPriority = 0;

foreach ($rowsRaw as $row) {
    $decoded = json_decode($row, true);
    if (isset($decoded['priority']) && is_numeric($decoded['priority'])) {
        $maxPriority = max($maxPriority, (int)$decoded['priority']);
    }
}

$nextPriority = (string)($maxPriority + 1);

// Generate UUID
$security = new \Phalcon\Security\Random();
$id = $security->uuid();

// Prepare payload
$payload = [
    'id'          => $id,
    'name'        => $name,
    'url'         => $url,
    'company'     => $company,
    'what'        => $what,
    'username'    => $username,
    'senderId'    => $data['senderId'],
    'priority'    => $nextPriority,
    'countryCode' => $data['countryCode'] ?? null,
    'port'        => $port ?? null,
    'password'    => $password,
    'created'     => time()
];

// Save to Redis
$this->redis->rPush($redisKey, json_encode($payload));

// Response
echo json_encode([
    'result'   => 'success',
    'id'       => $id,
    'priority' => $nextPriority,
    'message'  => 'Successfully created new SMS entry'
]);
return;


/* TYPE 2 — READ */
} elseif ($type === 2) {

$searchUsername = isset($data['searchUsername'])
    ? strtolower(trim($this->filter->sanitize($data['searchUsername'], 'string')))
    : '';

// Just get the raw list from Redis
$rowsRaw = $this->redis->lRange($redisKey, 0, -1);
$rows = array_map(fn($r) => json_decode($r, true), $rowsRaw);

// Filter by username if provided
if ($searchUsername !== '') {
    $rows = array_values(array_filter($rows, fn($row) =>
        !empty($row['username']) &&
        stripos($row['username'], $searchUsername) !== false
    ));
}

$total = count($rows);

echo json_encode([
    'result' => 'success',
    'totalRecords' => $total,
    'data' => $rows
]);
return;




    /* TYPE 3 — UPDATE */
    } elseif ($type === 3) {
        $itemId = isset($data['id']) ? (string) $this->filter->sanitize($data['id'], 'string') : '';
        if (!$itemId) {
            return $this->respondWithError(400, 'Invalid ID');
        }

        $updatableFields = ['name', 'url', 'company', 'username', 'password', 'countryCode', 'senderId', 'port'];
        $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
        $updated = false;

        foreach ($rowsRaw as $index => $row) {
            $decoded = json_decode($row, true);
            if ($decoded['id'] === $itemId) {
                foreach ($updatableFields as $field) {
                    if (isset($data[$field])) {
                        $decoded[$field] = is_array($data[$field]) ? $data[$field] : $this->filter->sanitize($data[$field], 'string');
                    }
                }
                $this->redis->lSet($redisKey, $index, json_encode($decoded));
                $updated = true;
                break;
            }
        }

        if (!$updated) {
            return $this->respondWithError(404, 'Record not found');
        }

        echo json_encode([
            'result'  => 'success',
             'id'       => $itemId,
            'message' => 'Updated'
        ]);
        return;

    /* TYPE 4 — DELETE */
    } elseif ($type === 4) {
        $itemId = isset($data['id']) ? (string) $this->filter->sanitize($data['id'], 'string') : '';
        if (!$itemId) {
            return $this->respondWithError(400, 'Invalid ID');
        }

        $rowsRaw = $this->redis->lRange($redisKey, 0, -1);
        $deleted = false;

        foreach ($rowsRaw as $row) {
            $decoded = json_decode($row, true);
            if ($decoded['id'] === $itemId) {
                $this->redis->lRem($redisKey, 1, $row);
                $deleted = true;
                break;
            }
        }

        if (!$deleted) {
            return $this->respondWithError(404, 'Record not found');
        }

        echo json_encode([
            'result'  => 'success',
              'id'       => $itemId,
            'message' => 'Deleted'
        ]);
        return;


} elseif ($type === 5) {

// Handle priorityList as comma-separated string
$priorityList = isset($data['priorityList']) && is_string($data['priorityList'])
    ? array_filter(array_map('trim', explode(',', $data['priorityList'])))
    : [];

if (empty($priorityList)) {
    return $this->respondWithError(400, 'priorityList is required');
}

// Load all existing rows from Redis
$rowsRaw = $this->redis->lRange($redisKey, 0, -1);
$rowsDecoded = array_map(fn($r) => json_decode($r, true), $rowsRaw);

// Build a mapping of ID => row
$idMap = [];
foreach ($rowsDecoded as $row) {
    $idMap[$row['id']] = $row;
}

// Assign priorities according to priorityList
$currentPriority = 1;
$orderedRows = [];
foreach ($priorityList as $id) {
    if (isset($idMap[$id])) {
        $idMap[$id]['priority'] = (string)$currentPriority++;
        $orderedRows[] = $idMap[$id];
        unset($idMap[$id]); // remove from map to avoid duplication
    }
}

// Append remaining rows that were not in priorityList
foreach ($idMap as $row) {
    $row['priority'] = (string)$currentPriority++;
    $orderedRows[] = $row;
}

// Save back to Redis in the new order (replaces existing list)
$this->redis->del($redisKey); // clear old list
foreach ($orderedRows as $row) {
    $this->redis->rPush($redisKey, json_encode($row));
}

echo json_encode([
    'result'  => 'success',
    'message' => 'Priorities updated and Redis list reordered'
]);
return;






    } else {
        return $this->respondWithError(400, 'Invalid type');
    }
}







 private function respondWithError($httpCode, $message)
    {
        http_response_code(200);
        echo json_encode(['result' => 'error', 'message' => $message, 'content' => '']);
    }



}