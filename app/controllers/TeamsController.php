<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use ElephantIO\Client;
use Phalcon\Db\Column;
use Phalcon\Image\Enum;

class TeamsController extends ControllerBase
{

public function enableTeamsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth") ?? [];
        $subdomain = $session['subdomain'] ?? 'default';

        if (empty($subdomain)) {
            throw new \Exception("Invalid session");
        }

        // Read JSON payload
        $requestData = $this->request->getJsonRawBody(true);

        $status = $requestData['status'] ?? 0;

        // Only allow 0 or 1
        $status = ($status == 1) ? 1 : 0;

        // Redis Key
        $redisKey = "integration:" . $subdomain . "-teams";

        // Store data
        $data = [
            "enable_teams" => $status
        ];

        $this->redis->set($redisKey, json_encode($data));

        $response = [
            "result" => "success",
            "enable_teams" => $status
        ];

    } catch (\Exception $e) {

        $response = [
            "result" => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ];
    }

    return $this->response->setJsonContent($response);
}


public function initAction()
{
    $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth") ?? [];
        $subdomain = $session['subdomain'] ?? 'default';

        if (empty($subdomain)) {
            throw new \Exception("Invalid session");
        }

        $redisKey = "integration:" . $subdomain . "-teams";


        $redisData = $this->redis->get($redisKey);

        $enableTeams = 0;

        if (!empty($redisData)) {
            $decoded = json_decode($redisData, true);
            $enableTeams = $decoded['enable_teams'] ?? 0;
        }

        $response = [
            "result" => "success",
            "enable_teams" => (int)$enableTeams
        ];

    } catch (\Exception $e) {

        $response = [
            "result" => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ];
    }

    return $this->response->setJsonContent($response);
}
















public function loginAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    session_start();

    try {

        $session = $this->session->get("auth") ?? [];
        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = "o365_settings:" . $subdomain;

        $settings = json_decode($this->redis->get($redisKey), true);
      

        if (empty($settings['client_id'])) {
            throw new Exception("MS365 settings missing");
        }

        /*
        |--------------------------------------------------------------------------
        | PKCE
        |--------------------------------------------------------------------------
        */

        $codeVerifier = bin2hex(random_bytes(32));

        $hash = hash('sha256', $codeVerifier, true);

        $codeChallenge = rtrim(
            strtr(base64_encode($hash), '+/', '-_'),
            '='
        );

        $_SESSION['pkce_verifier'] = $codeVerifier;

        /*
        |--------------------------------------------------------------------------
        | AUTH URL
        |--------------------------------------------------------------------------
        */

        $params = [
            'client_id'             => $settings['client_id'],
            'response_type'         => 'code',
            'redirect_uri'          => $settings['redirect_uri'],
            'response_mode'         => 'query',
            'scope'                 => 'openid offline_access User.Read Contacts.Read',
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account'
        ];

        $url = 'https://login.microsoftonline.com/'
             . $settings['tenant_id']
             . '/oauth2/v2.0/authorize?'
             . http_build_query($params);

        return $this->response->redirect($url);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'result' => 'error',
            'message'=> ControllerBase::safeErrorMessage($e)
        ]);
    }
}
public function o365settingsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {

        $session = $this->session->get("auth") ?? [];
        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = "o365_settings:" . $subdomain;

        /*
        |--------------------------------------------------------------------------
        | GET SETTINGS
        |--------------------------------------------------------------------------
        */

        if ($this->request->isGet()) {

            $settings = $this->redis->get($redisKey);

            if (empty($settings)) {

                return $this->response->setJsonContent([
                    'result'  => 'error',
                    'status'  => false,
                    'message' => 'Need to add MS365 settings'
                ]);
            }

            return $this->response->setJsonContent([
                'result' => 'success',
                'status' => true,
                'data'   => json_decode($settings, true)
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE SETTINGS
        |--------------------------------------------------------------------------
        */

        $data = $this->request->getJsonRawBody(true);

        $saveData = [
            'enabled'       => $data['enabled'] ?? 0,
            'client_id'     => trim($data['client_id'] ?? ''),
            'client_secret' => trim($data['client_secret'] ?? ''),
            'tenant_id'     => trim($data['tenant_id'] ?? ''),
            'redirect_uri'  => "https://". $session["subdomain"] . ":8443/teams/callback",
            'contact_mode'  => $data['contact_mode'] ?? 'personal',
            'enablemssso' => $data['enablemssso'] ?? 0
        ];

        $this->redis->set($redisKey, json_encode($saveData));

        return $this->response->setJsonContent([
            'result' => 'success',
            'message'=> 'Settings updated'
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}


public function callbackAction($type = 0)
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    session_start();

    try {
        if ($type == 1) {

             $domain_name = explode(':', $this->request->getHttpHost())[0];
             return $this->msSSO($domain_name);


            // Login flow
            // Don't require an existing RingQ session.
            // Load OAuth settings from a default/global config or determine the tenant first.

        } else {
            /*
            |--------------------------------------------------------------------------
            | SESSION
            |--------------------------------------------------------------------------
            */

            $session = $this->session->get("auth") ?? [];

            $subdomain   = $session['subdomain'] ?? '';
            $domain_uuid = $session['domain_uuid'] ?? '';
            

            if (empty($domain_uuid) || empty($subdomain)) {
                throw new \Exception("Invalid session");
            }
        }

        $redisKey = "o365_settings:" . $subdomain;

        $settings = json_decode($this->redis->get($redisKey), true);

        if (empty($settings)) {
            throw new \Exception("Microsoft settings not found");
        }

        $code = $_GET['code'] ?? '';

        if (!$code) {
            throw new \Exception("No auth code");
        }

        $codeVerifier = $_SESSION['pkce_verifier'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | GET ACCESS TOKEN
        |--------------------------------------------------------------------------
        */

        $postFields = [
            'client_id'     => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $settings['redirect_uri'],
            'code_verifier' => $codeVerifier
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://login.microsoftonline.com/' .
                $settings['tenant_id'] .
                '/oauth2/v2.0/token',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $tokenResponse = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        $tokenData = json_decode($tokenResponse, true);

        $accessToken = $tokenData['access_token'] ?? '';

        if (!$accessToken) {
            throw new \Exception("Access token missing");
        }

        /*
        |--------------------------------------------------------------------------
        | GET CONTACTS
        |--------------------------------------------------------------------------
        */

        $contactsUrl = "https://graph.microsoft.com/v1.0/me/contacts?\$top=999";

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $contactsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]
        ]);

        $contactsResponse = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        $contactsData = json_decode($contactsResponse, true);
        // print_r($contactsData ); // Debug output 
        // exit;

$imported = 0;

foreach (($contactsData['value'] ?? []) as $contact) {

    $firstName = trim($contact['givenName'] ?? '');
    $middleName = trim($contact['middleName'] ?? '');
    $lastName = trim($contact['surname'] ?? '');

    $email1 = $contact['emailAddresses'][0]['address'] ?? '';
    $email2 = $contact['emailAddresses'][1]['address'] ?? '';

    $mobilePhone = trim($contact['mobilePhone'] ?? '');

    $homePhones = $contact['homePhones'] ?? [];
    $phone2 = $homePhones[0] ?? '';

    $businessPhones = $contact['businessPhones'] ?? [];
    $officePhone = $businessPhones[0] ?? '';

    $companyName = trim($contact['companyName'] ?? '');
    $jobTitle = trim($contact['jobTitle'] ?? '');

    $notes = trim($contact['personalNotes'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | ADDRESS
    |--------------------------------------------------------------------------
    */

    // Prefer homeAddress first
    $address = $contact['homeAddress'] ?? [];

    // fallback to businessAddress
    if (empty($address['street'])) {
        $address = $contact['businessAddress'] ?? [];
    }

    $address1 = trim($address['street'] ?? '');
    $city     = trim($address['city'] ?? '');
    $state    = trim($address['state'] ?? '');
    $country  = trim($address['countryOrRegion'] ?? '');
    $postal   = trim($address['postalCode'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | SKIP EMPTY CONTACTS
    |--------------------------------------------------------------------------
    */

    if (
        empty($firstName) &&
        empty($lastName) &&
        empty($email1) &&
        empty($mobilePhone)
    ) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | DUPLICATE CHECK
    |--------------------------------------------------------------------------
    */

    $existing = $this->db->fetchOne(
        "
        SELECT id
        FROM q_contacts
        WHERE domain_uuid = :domain_uuid
        AND (
            email = :email
            OR phone = :phone
        )
        LIMIT 1
        ",
        \Phalcon\Db\Enum::FETCH_ASSOC,
        [
            'domain_uuid' => $domain_uuid,
            'email'       => $email1,
            'phone'       => $mobilePhone
        ]
    );

    if ($existing) {
        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    $this->db->insert(
    'q_contacts',
    [
        $domain_uuid,
        $companyName,
        $jobTitle,
        $firstName,
        $middleName,
        $lastName,
        $email1,
        $address1,
        '',
        $city,
        $state,
        $country,
        $postal,
        $mobilePhone,
        '',
        '',
        '',
        $notes,
        $this->security->getRandom()->uuid(),
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s'),
        $phone2,
        $officePhone,
        $mobilePhone,
        $email2,
        'o365_personal',
        $session["user_uuid"],
        date('Y-m-d H:i:s')
    ],
    [
        'domain_uuid',
        'company_name',
        'title',
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'address_1',
        'address_2',
        'city',
        'state',
        'country',
        'postal',
        'phone',
        'fax',
        'timezone',
        'language',
        'notes',
        'insert_uuid',
        'insert_date',
        'update_date',
        'phone2',
        'office_phone',
        'whatsapp_phone',
        'email2',
        'contacttype',
        'createdby',
        'createdate'
    ]
);

    $imported++;
}
        return $this->response->setJsonContent([
            'result'         => 'success',
            'imported_count' => $imported
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}


public function googlesettingsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {

        /*
        |--------------------------------------------------------------------------
        | SESSION
        |--------------------------------------------------------------------------
        */

        $session = $this->session->get("auth") ?? [];

        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = "google_settings:" . $subdomain;

        /*
        |--------------------------------------------------------------------------
        | GET SETTINGS
        |--------------------------------------------------------------------------
        */

        if ($this->request->isGet()) {

            $settings = $this->redis->get($redisKey);

            if (empty($settings)) {
                return $this->response->setJsonContent([
                    'result'  => 'error',
                    'status'  => false,
                    'message' => 'Google settings not found'
                ]);
            }

            return $this->response->setJsonContent([
                'result' => 'success',
                'status' => true,
                'data'   => json_decode($settings, true)
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | POST DATA
        |--------------------------------------------------------------------------
        */

        $data = $this->request->getJsonRawBody(true);

        if (!is_array($data)) {
            throw new \Exception("Invalid JSON request");
        }

        /*
        |--------------------------------------------------------------------------
        | LOAD EXISTING SETTINGS
        |--------------------------------------------------------------------------
        */

        $existing = [];

        $redisData = $this->redis->get($redisKey);

        if (!empty($redisData)) {

            $decoded = json_decode($redisData, true);

            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE SETTINGS
        |--------------------------------------------------------------------------
        */

        $saveData = [

            'enabled'       => (int)($data['enabled'] ?? 0),

            'client_id'     => trim($data['client_id'] ?? ''),

            'client_secret' => trim($data['client_secret'] ?? ''),

            'redirect_uri'  => "https://" . $subdomain . ":8443/teams/googlecallback",

            'contact_mode'  => trim($data['contact_mode'] ?? 'personal'),

            // Preserve OAuth tokens
            'access_token'  => $existing['access_token'] ?? '',

            'refresh_token' => $existing['refresh_token'] ?? '',

            'expires_at'    => $existing['expires_at'] ?? '',

            'enablegooglesso' => $data['enablegooglesso'] ?? ''
        ];

        if (!$this->redis->set($redisKey, json_encode($saveData))) {
            throw new \Exception("Unable to save Google settings");
        }

        /*
        |--------------------------------------------------------------------------
        | REDIRECT TO GOOGLE OAUTH
        |--------------------------------------------------------------------------
        */

        $params = [

            'client_id' => $saveData['client_id'],

            'redirect_uri' => $saveData['redirect_uri'],

            'response_type' => 'code',

            'scope' => implode(' ', [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/contacts.readonly'
            ]),

            'access_type' => 'offline',

            'prompt' => 'consent',

            'include_granted_scopes' => 'true'
        ];

        $url = "https://accounts.google.com/o/oauth2/v2/auth?" .
            http_build_query($params);

        return $this->response->setJsonContent([
            'status' => true,
            'url'    => $url
        ]);

    } catch (\Throwable $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
}


public function googlecallbackAction($type=0)
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {


        if ($type == 1) {

             $domain_name = explode(':', $this->request->getHttpHost())[0];
             return $this->googleSSO($domain_name);


            // Login flow
            // Don't require an existing RingQ session.
            // Load OAuth settings from a default/global config or determine the tenant first.

        } else {
            /*
            |--------------------------------------------------------------------------
            | SESSION
            |--------------------------------------------------------------------------
            */

            $session = $this->session->get("auth") ?? [];

            $subdomain   = $session['subdomain'] ?? '';
            $domain_uuid = $session['domain_uuid'] ?? '';
            

            if (empty($domain_uuid) || empty($subdomain)) {
                throw new \Exception("Invalid session");
            }
        }

        /*
        |--------------------------------------------------------------------------
        | GOOGLE SETTINGS
        |--------------------------------------------------------------------------
        */

        $redisKey = "google_settings:" . $subdomain;

        $settings = json_decode($this->redis->get($redisKey), true);

        if (empty($settings)) {
            throw new \Exception("Google settings not found");
        }

        /*
        |--------------------------------------------------------------------------
        | AUTHORIZATION CODE
        |--------------------------------------------------------------------------
        */

        $code = $this->request->getQuery('code');

        if (empty($code)) {
            throw new \Exception("Authorization code missing");
        }

        /*
        |--------------------------------------------------------------------------
        | GET ACCESS TOKEN
        |--------------------------------------------------------------------------
        */

        $postFields = [
            'code'          => $code,
            'client_id'     => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'redirect_uri'  => $settings['redirect_uri'],
            'grant_type'    => 'authorization_code'
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://oauth2.googleapis.com/token',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $tokenResponse = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        $tokenData = json_decode($tokenResponse, true);

        if (empty($tokenData['access_token'])) {

            return $this->response->setJsonContent([
                'result' => 'error',
                'step'   => 'token',
                'data'   => $tokenData
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | SAVE TOKEN
        |--------------------------------------------------------------------------
        */

        $settings['access_token'] = $tokenData['access_token'];

        if (!empty($tokenData['refresh_token'])) {
            $settings['refresh_token'] = $tokenData['refresh_token'];
        }

        if (!empty($tokenData['expires_in'])) {
            $settings['expires_at'] = time() + $tokenData['expires_in'];
        }



        /*
|--------------------------------------------------------------------------
| GET CONNECTED GOOGLE ACCOUNT
|--------------------------------------------------------------------------
*/

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => 'https://www.googleapis.com/oauth2/v2/userinfo',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $tokenData['access_token']
    ]
]);

$userResponse = curl_exec($ch);

if (curl_errno($ch)) {
    throw new \Exception(curl_error($ch));
}

curl_close($ch);

$userInfo = json_decode($userResponse, true);

/*
|--------------------------------------------------------------------------
| STORE CONNECTION DETAILS
|--------------------------------------------------------------------------
*/

$settings['connected_account'] = $userInfo['email'] ?? '';

if (!empty($userInfo['email'])) {
    $emailParts = explode('@', $userInfo['email']);
    $settings['organization'] = $emailParts[1] ?? '';
} else {
    $settings['organization'] = '';
}

// Store only the first successful connection date
if (empty($settings['connected_since'])) {
    $settings['connected_since'] = date('Y-m-d H:i:s');
}

// Optional information
$settings['connected_name'] = $userInfo['name'] ?? '';
$settings['connected_picture'] = $userInfo['picture'] ?? '';

$this->redis->set($redisKey, json_encode($settings));


        /*
        |--------------------------------------------------------------------------
        | FETCH CONTACTS
        |--------------------------------------------------------------------------
        */

        $accessToken = $tokenData['access_token'];

        $imported = 0;
        $pageToken = '';

        do {

            $url =
                'https://people.googleapis.com/v1/people/me/connections'
                . '?personFields='
                . 'names,emailAddresses,phoneNumbers,organizations,addresses,biographies';

            if (!empty($pageToken)) {
                $url .= '&pageToken=' . urlencode($pageToken);
            }

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken
                ]
            ]);

            $contactsResponse = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            }

            curl_close($ch);

            $contacts = json_decode($contactsResponse, true);

            if (!empty($contacts['error'])) {

                return $this->response->setJsonContent([
                    'result' => 'error',
                    'step'   => 'contacts',
                    'data'   => $contacts
                ]);
            }

            foreach (($contacts['connections'] ?? []) as $person) {

                if ($this->insertGoogleContact(
                    $person,
                    $domain_uuid,
                    $session,
                    $settings['contact_mode']
                )) {
                    $imported++;
                }
            }

            $pageToken = $contacts['nextPageToken'] ?? '';

        } while (!empty($pageToken));

        /*
        |--------------------------------------------------------------------------
        | SUCCESS
        |--------------------------------------------------------------------------
        */

        return $this->response->setJsonContent([
            'result'         => 'success',
            'imported_count' => $imported
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

private function insertGoogleContact($person, $domain_uuid, $session, $contactMode = 'personal')
{
    /*
    |--------------------------------------------------------------------------
    | NAME
    |--------------------------------------------------------------------------
    */

    $names = $person['names'][0] ?? [];

    $firstName  = trim($names['givenName'] ?? '');
    $middleName = trim($names['middleName'] ?? '');
    $lastName   = trim($names['familyName'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | EMAIL
    |--------------------------------------------------------------------------
    */

    $email1 = trim($person['emailAddresses'][0]['value'] ?? '');
    $email2 = trim($person['emailAddresses'][1]['value'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | PHONE
    |--------------------------------------------------------------------------
    */

    $mobilePhone = '';
    $phone2      = '';
    $officePhone = '';

    foreach (($person['phoneNumbers'] ?? []) as $phone) {

        $type  = strtolower($phone['type'] ?? '');
        $value = trim($phone['value'] ?? '');

        switch ($type) {

            case 'mobile':
                $mobilePhone = $value;
                break;

            case 'home':
                $phone2 = $value;
                break;

            case 'work':
                $officePhone = $value;
                break;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ORGANIZATION
    |--------------------------------------------------------------------------
    */

    $companyName = trim($person['organizations'][0]['name'] ?? '');
    $jobTitle    = trim($person['organizations'][0]['title'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | NOTES
    |--------------------------------------------------------------------------
    */

    $notes = trim($person['biographies'][0]['value'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | ADDRESS
    |--------------------------------------------------------------------------
    */

    $address = $person['addresses'][0] ?? [];

    $address1 = trim($address['streetAddress'] ?? '');
    $city     = trim($address['city'] ?? '');
    $state    = trim($address['region'] ?? '');
    $country  = trim($address['country'] ?? '');
    $postal   = trim($address['postalCode'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | SKIP EMPTY
    |--------------------------------------------------------------------------
    */

    if (
        empty($firstName) &&
        empty($lastName) &&
        empty($email1) &&
        empty($mobilePhone)
    ) {
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | DUPLICATE CHECK
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT id
        FROM q_contacts
        WHERE domain_uuid = ?
    ";

    $params = [
        $domain_uuid
    ];

    $orConditions = [];

    if (!empty($email1)) {
        $orConditions[] = "email = ?";
        $params[] = $email1;
    }

    if (!empty($firstName)) {
        $orConditions[] = "first_name = ?";
        $params[] = $firstName;
    }

    if (!empty($mobilePhone)) {
        $orConditions[] = "phone = ?";
        $params[] = $mobilePhone;
    }

    if (!empty($orConditions)) {

        $sql .= " AND (" . implode(" OR ", $orConditions) . ")";
        $sql .= " LIMIT 1";

        $existing = $this->db->fetchOne(
            $sql,
            \Phalcon\Db\Enum::FETCH_ASSOC,
            $params
        );

        if ($existing) {
            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CONTACT TYPE
    |--------------------------------------------------------------------------
    */

    $contactType = ($contactMode == 'organization')
        ? 'google_org'
        : 'google_personal';

    /*
    |--------------------------------------------------------------------------
    | INSERT
    |--------------------------------------------------------------------------
    */

    $this->db->insert(
        'q_contacts',
        [
            $domain_uuid,
            $companyName,
            $jobTitle,
            $firstName,
            $middleName,
            $lastName,
            $email1,
            $address1,
            '',
            $city,
            $state,
            $country,
            $postal,
            $mobilePhone,
            '',
            '',
            '',
            $notes,
            $this->security->getRandom()->uuid(),
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s'),
            $phone2,
            $officePhone,
            $mobilePhone,
            $email2,
            $contactType,
            $session["user_uuid"] ?? '',
            date('Y-m-d H:i:s')
        ],
        [
            'domain_uuid',
            'company_name',
            'title',
            'first_name',
            'middle_name',
            'last_name',
            'email',
            'address_1',
            'address_2',
            'city',
            'state',
            'country',
            'postal',
            'phone',
            'fax',
            'timezone',
            'language',
            'notes',
            'insert_uuid',
            'insert_date',
            'update_date',
            'phone2',
            'office_phone',
            'whatsapp_phone',
            'email2',
            'contacttype',
            'createdby',
            'createdate'
        ]
    );

    return true;
}


public function googledisconnectAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    try {

        /*
        |--------------------------------------------------------------------------
        | SESSION
        |--------------------------------------------------------------------------
        */

        $session = $this->session->get("auth") ?? [];

        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = "google_settings:" . $subdomain;

        /*
        |--------------------------------------------------------------------------
        | CHECK SETTINGS
        |--------------------------------------------------------------------------
        */

        if (!$this->redis->exists($redisKey)) {
            return $this->response->setJsonContent([
                'result'  => 'error',
                'status'  => false,
                'message' => 'Google settings not found'
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | REMOVE SETTINGS
        |--------------------------------------------------------------------------
        */

        if (!$this->redis->del($redisKey)) {
            throw new \Exception("Unable to disconnect Google account");
        }

        return $this->response->setJsonContent([
            'result'  => 'success',
            'status'  => true,
            'message' => 'Google account disconnected successfully'
        ]);

    } catch (\Throwable $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
}


// MR Contact sync 21-09-2026 starts
// Custom CRM (Address Book) contact sync integration.
// Settings are stored per-tenant in Redis, matching the google_settings/
// custom_webhook pattern already used elsewhere in this controller.

/**
 * Sample JSON contract shared by both the pull (GET from CRM) and push
 * (POST to CRM) directions, shown to the tenant as a demo format.
 */
private function customCrmContactSampleSchema()
{
    return [
        "contacts" => [
            [
                "first_name"     => "John",
                "last_name"      => "Doe",
                "company_name"   => "Acme Inc",
                "title"          => "Manager",
                "email"          => "john.doe@acme.com",
                "email2"         => "",
                "phone"          => "+15551234567",
                "phone2"         => "",
                "office_phone"   => "",
                "whatsapp_phone" => "",
                "address_1"      => "",
                "address_2"      => "",
                "city"           => "",
                "state"          => "",
                "country"        => "",
                "postal"         => "",
                "notes"          => ""
            ]
        ]
    ];
}

private function customCrmContactRedisKey($subdomain)
{
    return "custom_crm_contacts:" . $subdomain;
}

/**
 * Performs an authenticated HTTP request against a tenant-configured
 * Custom CRM webhook, applying whichever auth method they saved.
 */
private function customCrmContactHttpRequest($method, $url, array $settings, $body = null)
{
    $headers = ["Accept: application/json"];

    $authType = $settings['auth_type'] ?? 'None';

    if ($authType === 'Basic Auth') {
        $headers[] = "Authorization: Basic " . base64_encode(($settings['username'] ?? '') . ":" . ($settings['password'] ?? ''));
    } elseif ($authType === 'Bearer Token') {
        $headers[] = "Authorization: Bearer " . ($settings['token'] ?? '');
    } elseif ($authType === 'Custom Header' && !empty($settings['header_key'])) {
        $headers[] = $settings['header_key'] . ": " . ($settings['header_value'] ?? '');
    }

    $timeout = (int)($settings['timeout'] ?? 30);
    if ($timeout <= 0) {
        $timeout = 30;
    }

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true
    ];

    if ($method === 'POST') {
        $headers[] = "Content-Type: application/json";
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body);
    }

    $options[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init();
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    return [
        'status_code' => $statusCode,
        'body'        => $response,
        'error'       => $error
    ];
}

public function customCrmContactSettingsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth") ?? [];

        if (empty($session)) {
            throw new \Exception("Session expired");
        }

        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = $this->customCrmContactRedisKey($subdomain);

        $settings = $this->redis->get($redisKey);

        $data = !empty($settings) ? json_decode($settings, true) : [];

        if (!is_array($data)) {
            $data = [];
        }

        return $this->response->setJsonContent([
            'result'      => 'success',
            'status'      => true,
            'data'        => $data,
            'sample_json' => $this->customCrmContactSampleSchema()
        ]);

    } catch (\Throwable $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
}

public function saveCustomCrmContactSettingsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth") ?? [];

        if (empty($session)) {
            throw new \Exception("Session expired");
        }

        if (!$this->request->isPost()) {
            throw new \Exception("Invalid request method");
        }

        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = $this->customCrmContactRedisKey($subdomain);

        $data = $this->request->getJsonRawBody(true);

        if (!is_array($data)) {
            throw new \Exception("Invalid JSON request");
        }

        $existing = [];
        $existingRaw = $this->redis->get($redisKey);

        if (!empty($existingRaw)) {
            $decoded = json_decode($existingRaw, true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $saveData = [
            'enabled'          => (int)($data['enabled'] ?? 0),
            'pull_webhook_url' => trim($data['pull_webhook_url'] ?? ''),
            'push_webhook_url' => trim($data['push_webhook_url'] ?? ''),
            'auth_type'        => trim($data['auth_type'] ?? 'None'),
            'username'         => trim($data['username'] ?? ''),
            'password'         => trim($data['password'] ?? ''),
            'token'            => trim($data['token'] ?? ''),
            'header_key'       => trim($data['header_key'] ?? ''),
            'header_value'     => trim($data['header_value'] ?? ''),
            'timeout'          => (int)($data['timeout'] ?? 30),
            'last_pull_time'   => $existing['last_pull_time'] ?? '',
            'last_pull_status' => $existing['last_pull_status'] ?? '',
            'last_pull_count'  => $existing['last_pull_count'] ?? 0,
            'last_push_time'   => $existing['last_push_time'] ?? '',
            'last_push_status' => $existing['last_push_status'] ?? '',
            'last_push_count'  => $existing['last_push_count'] ?? 0
        ];

        if (!$this->redis->set($redisKey, json_encode($saveData))) {
            throw new \Exception("Unable to save Custom CRM contact settings");
        }

        return $this->response->setJsonContent([
            'result' => 'success',
            'status' => true,
            'data'   => $saveData
        ]);

    } catch (\Throwable $e) {

        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
}

/**
 * Pulls contacts from the tenant's configured CRM webhook (HTTP GET) and
 * imports them into q_contacts, tagged with contacttype = 'custom_crm'.
 */
public function syncCustomCrmContactsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth") ?? [];

        if (empty($session) || empty($session['domain_uuid'])) {
            throw new \Exception("Session expired");
        }

        $domain_uuid = $session['domain_uuid'];
        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = $this->customCrmContactRedisKey($subdomain);
        $raw = $this->redis->get($redisKey);
        $settings = !empty($raw) ? json_decode($raw, true) : [];

        if (!is_array($settings) || empty($settings['pull_webhook_url'])) {
            throw new \Exception("Custom CRM pull webhook URL is not configured");
        }

        $result = $this->customCrmContactHttpRequest('GET', $settings['pull_webhook_url'], $settings);

        if (!empty($result['error'])) {
            throw new \Exception("Unable to reach CRM webhook: " . $result['error']);
        }

        if ($result['status_code'] < 200 || $result['status_code'] >= 300) {
            throw new \Exception("CRM webhook returned HTTP " . $result['status_code']);
        }

        $payload = json_decode($result['body'], true);

        $contacts = is_array($payload) ? ($payload['contacts'] ?? $payload) : [];

        if (!is_array($contacts)) {
            throw new \Exception("CRM webhook response did not contain a valid contacts list");
        }

        $imported = 0;
        $skippedDuplicate = 0;
        $skippedInvalid = 0;

        foreach ($contacts as $contact) {

            if (!is_array($contact)) {
                $skippedInvalid++;
                continue;
            }

            $firstName = trim($contact['first_name'] ?? '');
            $lastName  = trim($contact['last_name'] ?? '');
            $email     = trim($contact['email'] ?? '');
            $phone     = trim($contact['phone'] ?? '');

            if (empty($firstName) && empty($lastName) && empty($email) && empty($phone)) {
                $skippedInvalid++;
                continue;
            }

            // DUPLICATE CHECK - matches insertGoogleContact()'s convention
            $sql = "SELECT id FROM q_contacts WHERE domain_uuid = ?";
            $params = [$domain_uuid];
            $orConditions = [];

            if (!empty($email)) {
                $orConditions[] = "email = ?";
                $params[] = $email;
            }

            if (!empty($phone)) {
                $orConditions[] = "phone = ?";
                $params[] = $phone;
            }

            if (!empty($orConditions)) {
                $sql .= " AND (" . implode(" OR ", $orConditions) . ")";
                $sql .= " LIMIT 1";

                $existingContact = $this->db->fetchOne($sql, \Phalcon\Db\Enum::FETCH_ASSOC, $params);

                if ($existingContact) {
                    $skippedDuplicate++;
                    continue;
                }
            }

            $this->db->insert(
                'q_contacts',
                [
                    $domain_uuid,
                    trim($contact['company_name'] ?? ''),
                    trim($contact['title'] ?? ''),
                    $firstName,
                    '',
                    $lastName,
                    $email,
                    trim($contact['address_1'] ?? ''),
                    trim($contact['address_2'] ?? ''),
                    trim($contact['city'] ?? ''),
                    trim($contact['state'] ?? ''),
                    trim($contact['country'] ?? ''),
                    trim($contact['postal'] ?? ''),
                    $phone,
                    '',
                    '',
                    '',
                    trim($contact['notes'] ?? ''),
                    $this->security->getRandom()->uuid(),
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                    trim($contact['phone2'] ?? ''),
                    trim($contact['office_phone'] ?? ''),
                    trim($contact['whatsapp_phone'] ?? ''),
                    trim($contact['email2'] ?? ''),
                    'custom_crm',
                    $session["user_uuid"] ?? '',
                    date('Y-m-d H:i:s')
                ],
                [
                    'domain_uuid',
                    'company_name',
                    'title',
                    'first_name',
                    'middle_name',
                    'last_name',
                    'email',
                    'address_1',
                    'address_2',
                    'city',
                    'state',
                    'country',
                    'postal',
                    'phone',
                    'fax',
                    'timezone',
                    'language',
                    'notes',
                    'insert_uuid',
                    'insert_date',
                    'update_date',
                    'phone2',
                    'office_phone',
                    'whatsapp_phone',
                    'email2',
                    'contacttype',
                    'createdby',
                    'createdate'
                ]
            );

            $imported++;
        }

        $settings['last_pull_time']   = date('Y-m-d H:i:s');
        $settings['last_pull_status'] = 'success';
        $settings['last_pull_count']  = $imported;

        $this->redis->set($redisKey, json_encode($settings));

        return $this->response->setJsonContent([
            'result'            => 'success',
            'status'            => true,
            'imported'          => $imported,
            'skipped_duplicate' => $skippedDuplicate,
            'skipped_invalid'   => $skippedInvalid,
            'total'             => count($contacts)
        ]);

    } catch (\Throwable $e) {

        $subdomain = ($this->session->get("auth") ?? [])['subdomain'] ?? 'default';
        $redisKey = $this->customCrmContactRedisKey($subdomain);
        $raw = $this->redis->get($redisKey);
        $settings = !empty($raw) ? json_decode($raw, true) : [];

        if (is_array($settings)) {
            $settings['last_pull_time']   = date('Y-m-d H:i:s');
            $settings['last_pull_status'] = 'failed: ' . $e->getMessage();
            $this->redis->set($redisKey, json_encode($settings));
        }

        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
}

/**
 * Pushes RingQ's own contacts (excluding ones already sourced from this
 * same Custom CRM integration, to avoid an import/export loop) to the
 * tenant's configured push webhook (HTTP POST), using the same JSON shape.
 */
public function pushCustomCrmContactsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth") ?? [];

        if (empty($session) || empty($session['domain_uuid'])) {
            throw new \Exception("Session expired");
        }

        $domain_uuid = $session['domain_uuid'];
        $subdomain = $session['subdomain'] ?? 'default';

        $redisKey = $this->customCrmContactRedisKey($subdomain);
        $raw = $this->redis->get($redisKey);
        $settings = !empty($raw) ? json_decode($raw, true) : [];

        if (!is_array($settings)) {
            throw new \Exception("Custom CRM settings are not configured");
        }

        $pushUrl = !empty($settings['push_webhook_url'])
            ? $settings['push_webhook_url']
            : ($settings['pull_webhook_url'] ?? '');

        if (empty($pushUrl)) {
            throw new \Exception("Custom CRM push webhook URL is not configured");
        }

        $rows = $this->db->fetchAll(
            "SELECT first_name, last_name, company_name, title, email, email2,
                    phone, phone2, office_phone, whatsapp_phone,
                    address_1, address_2, city, state, country, postal, notes
             FROM q_contacts
             WHERE domain_uuid = ?
               AND (contacttype IS NULL OR contacttype != 'custom_crm')",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domain_uuid]
        );

        $contacts = [];

        foreach ($rows as $row) {
            $contacts[] = [
                'first_name'     => $row['first_name'],
                'last_name'      => $row['last_name'],
                'company_name'   => $row['company_name'],
                'title'          => $row['title'],
                'email'          => $row['email'],
                'email2'         => $row['email2'],
                'phone'          => $row['phone'],
                'phone2'         => $row['phone2'],
                'office_phone'   => $row['office_phone'],
                'whatsapp_phone' => $row['whatsapp_phone'],
                'address_1'      => $row['address_1'],
                'address_2'      => $row['address_2'],
                'city'           => $row['city'],
                'state'          => $row['state'],
                'country'        => $row['country'],
                'postal'         => $row['postal'],
                'notes'          => $row['notes']
            ];
        }

        $result = $this->customCrmContactHttpRequest('POST', $pushUrl, $settings, ['contacts' => $contacts]);

        if (!empty($result['error'])) {
            throw new \Exception("Unable to reach CRM webhook: " . $result['error']);
        }

        if ($result['status_code'] < 200 || $result['status_code'] >= 300) {
            throw new \Exception("CRM webhook returned HTTP " . $result['status_code']);
        }

        $settings['last_push_time']   = date('Y-m-d H:i:s');
        $settings['last_push_status'] = 'success';
        $settings['last_push_count']  = count($contacts);

        $this->redis->set($redisKey, json_encode($settings));

        return $this->response->setJsonContent([
            'result' => 'success',
            'status' => true,
            'pushed' => count($contacts)
        ]);

    } catch (\Throwable $e) {

        $subdomain = ($this->session->get("auth") ?? [])['subdomain'] ?? 'default';
        $redisKey = $this->customCrmContactRedisKey($subdomain);
        $raw = $this->redis->get($redisKey);
        $settings = !empty($raw) ? json_decode($raw, true) : [];

        if (is_array($settings)) {
            $settings['last_push_time']   = date('Y-m-d H:i:s');
            $settings['last_push_status'] = 'failed: ' . $e->getMessage();
            $this->redis->set($redisKey, json_encode($settings));
        }

        return $this->response->setJsonContent([
            'result'  => 'error',
            'status'  => false,
            'message' => ControllerBase::safeErrorMessage($e),
            'file'    => $e->getFile(),
            'line'    => $e->getLine()
        ]);
    }
}

/**
 * Demo target for the pull/push webhook URL settings, so this integration
 * can be tried end-to-end without a real external CRM to point at. Logs
 * every request it receives - method, URL, headers, and (for POST) the
 * body - to a dedicated log file, then answers whichever shape the caller
 * expects:
 *  - GET  (hit by "Sync Contacts from CRM", i.e. syncCustomCrmContactsAction)
 *    -> the same sample contacts list shown in the settings dialog
 *  - POST (hit by "Sync Contacts to CRM", i.e. pushCustomCrmContactsAction)
 *    -> {"result": "success"}
 * No session/auth check: this stands in for an external CRM, and
 * customCrmContactHttpRequest() calls it with no session cookie - the same
 * reason ReportsController::writeLogAction() is public.
 */
public function demoCrmWebhookAction()
{
    $this->view->disable();
    $this->response->setHeader('Content-Type', 'application/json');

    $method = $this->request->getMethod();
    $rawBody = $this->request->getRawBody();

    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method'    => $method,
        'url'       => (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        'headers'   => function_exists('getallheaders') ? getallheaders() : [],
        'body'      => $method === 'POST' ? json_decode($rawBody, true) : null,
    ];

    $logDir = APP_PATH . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    file_put_contents(
        $logDir . '/custom_crm_demo_webhook.log',
        json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
            PHP_EOL . str_repeat('=', 120) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    if ($method === 'POST') {
        $count = count($logEntry['body']['contacts'] ?? []);
        return $this->response->setJsonContent([
            'result'  => 'success',
            'message' => "Demo CRM webhook received {$count} contact(s)."
        ]);
    }

    return $this->response->setJsonContent([
        "contacts" => [
            [
                "first_name"     => "John",
                "last_name"      => "Doe",
                "company_name"   => "Acme Inc",
                "title"          => "Manager",
                "email"          => "john.doe@acme.com",
                "email2"         => "",
                "phone"          => "+15551234567",
                "phone2"         => "",
                "office_phone"   => "",
                "whatsapp_phone" => "",
                "address_1"      => "",
                "address_2"      => "",
                "city"           => "",
                "state"          => "",
                "country"        => "",
                "postal"         => "",
                "notes"          => "",
                "contacttype"    => NULL
            ],
            [
                "first_name"     => "Jane",
                "last_name"      => "Smith",
                "company_name"   => "Globex Corp",
                "title"          => "Director",
                "email"          => "jane.smith@globex.com",
                "email2"         => "",
                "phone"          => "+15559876543",
                "phone2"         => "",
                "office_phone"   => "",
                "whatsapp_phone" => "",
                "address_1"      => "",
                "address_2"      => "",
                "city"           => "",
                "state"          => "",
                "country"        => "",
                "postal"         => "",
                "notes"          => "",
                "contacttype"    => NULL    
            ]
        ]
    ]);
}
// MR Contact sync 21-09-2026 ends


public function googleloginAction()
{
    $host = explode(':', $this->request->getHttpHost())[0];

    $redisKey = "google_settings:" . $host;

    $redisValue = $this->redis->get($redisKey);

    if (empty($redisValue)) {
        // No Google settings configured
        $settings = [];
    } else {
        $settings = json_decode($redisValue, true);

        if (!is_array($settings)) {
            $settings = [];
        }
    }


    if (empty($settings)) {
        return $this->response->setContent('
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Google Not Configured</title>
                <script>
                    setTimeout(function() {
                        window.location.href = window.location.protocol + "//" + window.location.hostname + "/cloudapp";
                    }, 2000);
                </script>
            </head>
            <body>
                <div style="text-align:center; margin-top:100px; font-family:Arial;">
                    <h3>Google is not configured.</h3>
                    <p>Redirecting to Login...</p>
                </div>
            </body>
            </html>
        ');
    }

    if ($settings['enablegooglesso'] == 0) {
 
        return $this->response->setContent('
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Google SSO is disabled</title>
            <script>
                setTimeout(function() {
                    window.location.href = window.location.protocol + "//" + window.location.hostname + "/cloudapp";
                }, 2000);
            </script>
        </head>
        <body>
            <div style="text-align:center; margin-top:100px; font-family:Arial;">
                <h3>Google SSO is disabled.</h3>
                <p>Redirecting to Login...</p>
            </div>
        </body>
        </html>
    ');
    }

    $redirectUri = 'https://' . $host . ':8443/teams/googlecallback/1';

    $url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
        "client_id"     => $settings["client_id"],
        "redirect_uri"  => $redirectUri,
        "response_type" => "code",
        "scope"         => "openid email profile",
        "access_type"   => "online",
        "prompt"        => "select_account"
    ]);

    return $this->response->redirect($url);
}

private function googleSSO($domain)
{
    $settings = json_decode($this->redis->get("google_settings:$domain"), true);

    if (empty($settings)) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "Google SSO is not configured."
        ]);
    }

    $code = $this->request->getQuery("code");

    if (empty($code)) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "Authorization code missing."
        ]);
    }

    // Exchange authorization code for access token
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "https://oauth2.googleapis.com/token",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            "code" => $code,
            "client_id" => $settings["client_id"],
            "client_secret" => $settings["client_secret"],
            "redirect_uri" => "https://{$domain}:8443/teams/googlecallback/1",
            "grant_type" => "authorization_code"
        ]),
        CURLOPT_RETURNTRANSFER => true
    ]);

    $tokenData = json_decode(curl_exec($ch), true);

    if (curl_errno($ch)) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => curl_error($ch)
        ]);
    }

    curl_close($ch);

    if (empty($tokenData["access_token"])) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => $tokenData["error_description"]
                ?? $tokenData["error"]
                ?? "Unable to obtain Google access token."
        ]);
    }

    // Get Google user
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "https://www.googleapis.com/oauth2/v2/userinfo",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $tokenData["access_token"]
        ]
    ]);

    $googleUser = json_decode(curl_exec($ch), true);

    if (curl_errno($ch)) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => curl_error($ch)
        ]);
    }

    curl_close($ch);

    if (empty($googleUser["email"])) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "Unable to retrieve Google account."
        ]);
    }

    $user_email = strtolower(trim($googleUser["email"]));

    $vUser = VUsers::findFirst([
        "conditions" => "user_email = :email:",
        "bind" => ["email" => $user_email]
    ]);

    if (!$vUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1001)"
        ]);
    }

    $vUser->user_status = "Available";

    if (!$vUser->update()) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1002)"
        ]);
    }

    $user_uuid = $vUser->user_uuid;

    $vExtensionUser = VExtensionUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $user_uuid],
        "bindTypes" => [Column::BIND_PARAM_STR]
    ]);

    $qUser = QUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $user_uuid],
        "bindTypes" => [Column::BIND_PARAM_STR]
    ]);

    if (!$vExtensionUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1003)"
        ]);
    }

    if (!$qUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1004)"
        ]);
    }

    $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
    $access_token = bin2hex(random_bytes(32));

    $qToken = new QTokens();
    $qToken->user_uuid = $user_uuid;
    $qToken->domain_uuid = $vUser->domain_uuid;
    $qToken->access_token = $access_token;
    $qToken->insert_date = date("Y-m-d H:i:s");
    $qToken->user_agent = $userAgent;
    $qToken->type = 1;

    if (!$qToken->save()) {
        $this->db->rollback();

        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1005)"
        ]);
    }



    $this->redis->setex("sso_login:" . $access_token, 300, $user_uuid);

    $host = preg_replace('/:\d+$/', '', $this->request->getHttpHost());

    return $this->response->redirect(
        "https://{$host}/cloudapp/?token=" . urlencode($access_token)
    );
}



// MR SSO Changes 30/07/2026 - starts
// Tells the (unauthenticated) login page which SSO providers this tenant has
// switched on, so it can render only the buttons that can actually work.
//
// Deliberately returns ONLY the two booleans. o365settings/googlesettings carry
// client_id / client_secret / tenant_id and must stay the session-scoped way to
// read those -- this endpoint must never become a second route to them.
//
// Resolves the tenant from the HTTP host, matching msloginAction and
// googleloginAction below. A session-based lookup is wrong here: an anonymous
// caller has none, so $session['subdomain'] would fall back to 'default' and
// read another bucket entirely.
public function ssoconfigAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

    $enableMsSso = false;
    $enableGoogleSso = false;

    try {
        $host = explode(':', $this->request->getHttpHost())[0];

        $o365 = $this->redis->get("o365_settings:" . $host);
        if (!empty($o365)) {
            $decoded = json_decode($o365, true);
            if (is_array($decoded)) {
                $enableMsSso = $this->isSsoFlagEnabled($decoded['enablemssso'] ?? null);
            }
        }

        $google = $this->redis->get("google_settings:" . $host);
        if (!empty($google)) {
            $decoded = json_decode($google, true);
            if (is_array($decoded)) {
                $enableGoogleSso = $this->isSsoFlagEnabled($decoded['enablegooglesso'] ?? null);
            }
        }
    } catch (\Exception $e) {
        // Fail closed: report both providers off rather than surfacing an
        // error to an anonymous visitor.
        $enableMsSso = false;
        $enableGoogleSso = false;
    }

    return $this->response->setJsonContent([
        'result' => 'success',
        'status' => true,
        'data'   => [
            'enablemssso'     => $enableMsSso,
            'enablegooglesso' => $enableGoogleSso,
        ],
    ]);
}

// The settings dialogs write "1"/"0" strings, older rows may hold ints or
// bools. Accept every shape rather than only a JSON boolean.
private function isSsoFlagEnabled($value)
{
    if (is_bool($value)) {
        return $value;
    }
    return $value === 1 || $value === "1" || (is_string($value) && strtolower($value) === "true");
}
// MR SSO Changes 30/07/2026 - ends

public function msloginAction()
{

    $host = explode(':', $this->request->getHttpHost())[0];

    $redisKey = "o365_settings:" . $host;

    $redisValue = $this->redis->get($redisKey);

    if (empty($redisValue)) {
        // No Microsoft settings configured
        $settings = [];
    } else {
        $settings = json_decode($redisValue, true);

        if (!is_array($settings)) {
            $settings = [];
        }
    }


    if (empty($settings)) {
        return $this->response->setContent('
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Microsoft Not Configured</title>
                <script>
                    setTimeout(function() {
                        window.location.href = window.location.protocol + "//" + window.location.hostname + "/cloudapp";
                    }, 2000);
                </script>
            </head>
            <body>
                <div style="text-align:center; margin-top:100px; font-family:Arial;">
                    <h3>Microsoft is not configured.</h3>
                    <p>Redirecting to Login...</p>
                </div>
            </body>
            </html>
        ');
    }

    if ($settings['enablemssso'] == 0) {
 
        return $this->response->setContent('
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Microsoft SSO is disabled</title>
            <script>
                setTimeout(function() {
                    window.location.href = window.location.protocol + "//" + window.location.hostname + "/cloudapp";
                }, 2000);
            </script>
        </head>
        <body>
            <div style="text-align:center; margin-top:100px; font-family:Arial;">
                <h3>Microsoft SSO is disabled.</h3>
                <p>Redirecting to Login...</p>
            </div>
        </body>
        </html>
    ');
    }


    // MR Contact sync 21-09-2026 starts
    $clientId = $settings["client_id"];
    $tenantId = $settings["tenant_id"];

    $redirectUri = "https://{$host}:8443/teams/callback/1";

    // MR Contact sync 21-09-2026 ends

    $url =
        "https://login.microsoftonline.com/" .
        $tenantId .
        "/oauth2/v2.0/authorize?" .
        http_build_query([
            "client_id" => $clientId,
            "response_type" => "code",
            "redirect_uri" => $redirectUri,
            "response_mode" => "query",
            "scope" => "openid profile email User.Read",
            "state" => $domain
        ]);

    return $this->response->redirect($url);
}


private function msSSO($domain)
{
    $settings = json_decode($this->redis->get("o365_settings:$domain"), true);

    if (empty($settings)) {
        throw new \Exception("Microsoft SSO is not configured.");
    }

    $code = $this->request->getQuery("code");

    if (empty($code)) {
        throw new \Exception("Authorization code missing.");
    }

    // Exchange authorization code for access token
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => "https://login.microsoftonline.com/{$settings['tenant_id']}/oauth2/v2.0/token",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            "client_id" => $settings["client_id"],
            "client_secret" => $settings["client_secret"],
            "code" => $code,
            "redirect_uri" => "https://{$domain}:8443/teams/callback/1",
            "grant_type" => "authorization_code",
            "scope" => "openid profile email User.Read"
        ]),
        CURLOPT_RETURNTRANSFER => true
    ]);

    $tokenData = json_decode(curl_exec($ch), true);

    if (curl_errno($ch)) {
        throw new \Exception(curl_error($ch));
    }

    curl_close($ch);

    if (empty($tokenData["access_token"])) {
        throw new \Exception(
            $tokenData["error_description"]
            ?? $tokenData["error"]
            ?? "Unable to obtain Microsoft access token."
        );
    }

// Get Microsoft user profile
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => "https://graph.microsoft.com/v1.0/me",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . $tokenData["access_token"],
        "Accept: application/json"
    ]
]);

$msUser = json_decode(curl_exec($ch), true);

if (curl_errno($ch)) {
    throw new \Exception(curl_error($ch));
}

curl_close($ch);

if (!empty($msUser["error"])) {
    throw new \Exception(
        $msUser["error"]["message"] ?? "Unable to retrieve Microsoft account."
    );
}

// Microsoft sometimes doesn't populate "mail"
$email = $msUser["mail"] ?? "";

if (empty($email)) {
    $email = $msUser["userPrincipalName"] ?? "";
}

if (empty($email)) {
    throw new \Exception("Unable to retrieve Microsoft account email.");
}

$user_email = strtolower(trim($email));

    $vUser = VUsers::findFirst([
        "conditions" => "user_email = :email:",
        "bind" => ["email" => $user_email]
    ]);

    if (!$vUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1001)"
        ]);
    }

    $vUser->user_status = "Available";

    if (!$vUser->update()) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1002)"
        ]);
    }

    $user_uuid = $vUser->user_uuid;

    $vExtensionUser = VExtensionUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $user_uuid],
        "bindTypes" => [Column::BIND_PARAM_STR]
    ]);

    $qUser = QUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $user_uuid],
        "bindTypes" => [Column::BIND_PARAM_STR]
    ]);

    if (!$vExtensionUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1003)"
        ]);
    }

    if (!$qUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1004)"
        ]);
    }

    $userAgent = $this->filter->sanitize($this->request->getUserAgent(), "string");
    $access_token = bin2hex(random_bytes(32));

    $qToken = new QTokens();
    $qToken->user_uuid = $user_uuid;
    $qToken->domain_uuid = $vUser->domain_uuid;
    $qToken->access_token = $access_token;
    $qToken->insert_date = date("Y-m-d H:i:s");
    $qToken->user_agent = $userAgent;
    $qToken->type = 1;

    if (!$qToken->save()) {
        $this->db->rollback();

        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "An error has occurred. (Code LF1005)"
        ]);
    }



    $this->redis->setex("sso_login:" . $access_token, 300, $user_uuid);

    $host = preg_replace('/:\d+$/', '', $this->request->getHttpHost());

    return $this->response->redirect(
        "https://{$host}/cloudapp/?token=" . urlencode($access_token)
    );
}



public function ssologinAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    $params = json_decode(file_get_contents("php://input"), true);

    if (empty($params["token"])) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "Token is required."
        ]);
    }

    $token = $this->filter->sanitize($params["token"], "string");

    if (!ControllerBase::isRandomRememberToken($token) || intval($this->redis->del("sso_login:" . $token)) !== 1) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "Invalid token."
        ]);
    }

    $qToken = QTokens::findFirst([
        "conditions" => "access_token = :token:",
        "bind" => ["token" => $token]
    ]);

    if (!$qToken) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "Invalid token."
        ]);
    }

    $vUser = VUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $qToken->user_uuid]
    ]);

    $qUser = QUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $qToken->user_uuid]
    ]);

    $vExtensionUser = VExtensionUsers::findFirst([
        "conditions" => "user_uuid = :uuid:",
        "bind" => ["uuid" => $qToken->user_uuid]
    ]);

    if (!$vUser || !$qUser || !$vExtensionUser) {
        return $this->response->setJsonContent([
            "result" => "error",
            "message" => "User configuration not found."
        ]);
    }

    $expire = time() + (86400 * 360);
    $domain = $vExtensionUser->vExtensions->accountcode;

    foreach ([
        "RINGQKIE" => $qToken->user_uuid,
        "RINGQENS" => $qToken->access_token
    ] as $name => $value) {
        $this->cookies->set(
            $name,
            $value,
            $expire,
            "/",
            true,
            $domain,
            true,
            ["samesite" => "Lax"]
        );
    }

    $this->cookies->send();

    $walltoken = $this->security->hash($qToken->user_uuid);
    $this->redis->sadd("wallboard:$domain", $walltoken);

    return $this->response->setJsonContent([
        "result" => "success",
        "message" => "Login success.",
        "content" => [
            "username"    => $vUser->username,
            "firstname"   => $qUser->first_name,
            "lastname"    => $qUser->last_name,
            "email"       => $vUser->user_email,
            "image"       => $qUser->image,
            "extension"   => $vExtensionUser->vExtensions->extension,
            "subdomain"   => $domain,
            "domain"      => $this->config->application->publicUrl,
            "domain_uuid" => $vUser->domain_uuid,
            "sip_uuid"    => $vExtensionUser->vExtensions->password,
            "user_uuid"   => $vUser->user_uuid,
            "walltoken"   => $walltoken,
            "access"      => "registered",
            "result"      => "success"
        ]
    ]);
}


public function orgContactsAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        /*
        |--------------------------------------------------------------------------
        | SESSION
        |--------------------------------------------------------------------------
        */

        $session = $this->session->get("auth") ?? [];

        $domain_uuid = $session['domain_uuid'] ?? '';
        $subdomain   = $session['subdomain'] ?? '';

        if (empty($domain_uuid) || empty($subdomain)) {
            throw new \Exception("Invalid session");
        }

        /*
        |--------------------------------------------------------------------------
        | REDIS SETTINGS
        |--------------------------------------------------------------------------
        */

        $redisKey = "o365_settings:" . $subdomain;

        $o365Settings = json_decode($this->redis->get($redisKey), true);

        if (empty($o365Settings)) {
            throw new \Exception("Microsoft settings not found");
        }

        $tenantId     = trim($o365Settings['tenant_id'] ?? '');
        $clientId     = trim($o365Settings['client_id'] ?? '');
        $clientSecret = trim($o365Settings['client_secret'] ?? '');

        if (
            empty($tenantId) ||
            empty($clientId) ||
            empty($clientSecret)
        ) {
            throw new \Exception("Incomplete Microsoft settings");
        }

        /*
        |--------------------------------------------------------------------------
        | GET ACCESS TOKEN
        |--------------------------------------------------------------------------
        */

        $postFields = [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials'
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true
        ]);

        $tokenResponse = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        $tokenData = json_decode($tokenResponse, true);

        $accessToken = $tokenData['access_token'] ?? '';

        if (!$accessToken) {

            return $this->response->setJsonContent([
                'result' => 'error',
                'step'   => 'token',
                'data'   => $tokenData
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | FETCH CONTACTS
        |--------------------------------------------------------------------------
        */

        $imported = 0;

        $nextUrl = 'https://graph.microsoft.com/v1.0/contacts';

        while ($nextUrl) {

            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $nextUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]
            ]);

            $contactsResponse = curl_exec($ch);

            if (curl_errno($ch)) {
                throw new \Exception(curl_error($ch));
            }

            curl_close($ch);

            $contactsData = json_decode($contactsResponse, true);

            /*
            |--------------------------------------------------------------------------
            | GRAPH ERROR
            |--------------------------------------------------------------------------
            */

            if (!empty($contactsData['error'])) {

                return $this->response->setJsonContent([
                    'result' => 'error',
                    'step'   => 'graph',
                    'data'   => $contactsData
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | LOOP CONTACTS
            |--------------------------------------------------------------------------
            */

            foreach (($contactsData['value'] ?? []) as $contact) {

                $firstName = trim($contact['givenName'] ?? '');
                $middleName = trim($contact['middleName'] ?? '');
                $lastName = trim($contact['surname'] ?? '');

                $email1 = trim($contact['emailAddresses'][0]['address'] ?? '');
                $email2 = trim($contact['emailAddresses'][1]['address'] ?? '');

                $mobilePhone = trim($contact['mobilePhone'] ?? '');

                $homePhones = $contact['homePhones'] ?? [];
                $phone2 = trim($homePhones[0] ?? '');

                $businessPhones = $contact['businessPhones'] ?? [];
                $officePhone = trim($businessPhones[0] ?? '');

                $companyName = trim($contact['companyName'] ?? '');
                $jobTitle = trim($contact['jobTitle'] ?? '');

                $notes = trim($contact['personalNotes'] ?? '');

                /*
                |--------------------------------------------------------------------------
                | ADDRESS
                |--------------------------------------------------------------------------
                */

                $address = $contact['homeAddress'] ?? [];

                if (empty($address['street'])) {
                    $address = $contact['businessAddress'] ?? [];
                }

                $address1 = trim($address['street'] ?? '');
                $city     = trim($address['city'] ?? '');
                $state    = trim($address['state'] ?? '');
                $country  = trim($address['countryOrRegion'] ?? '');
                $postal   = trim($address['postalCode'] ?? '');

                /*
                |--------------------------------------------------------------------------
                | SKIP EMPTY
                |--------------------------------------------------------------------------
                */

                if (
                    empty($firstName) &&
                    empty($lastName) &&
                    empty($email1) &&
                    empty($mobilePhone)
                ) {
                    continue;
                }

                /*
                |--------------------------------------------------------------------------
                | DUPLICATE CHECK
                |--------------------------------------------------------------------------
                */

                $conditions = ["domain_uuid = :domain_uuid:"];
                $bind = [
                    'domain_uuid' => $domain_uuid
                ];

                if (!empty($email1)) {
                    $conditions[] = "email = :email:";
                    $bind['email'] = $email1;
                }

              
                if (!empty($firstName)) {
                    $conditions[] = "first_name = :firstName";
                    $bind['firstName'] = $firstName;
                }

                if (!empty($mobilePhone)) {
                    $conditions[] = "phone = :phone:";
                    $bind['phone'] = $mobilePhone;
                }

                $where = implode(" OR ", array_slice($conditions, 1));

                if (!empty($where)) {

                    $existing = $this->db->fetchOne(
                        "
                        SELECT id
                        FROM q_contacts
                        WHERE domain_uuid = :domain_uuid
                        AND ($where)
                        LIMIT 1
                        ",
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        $bind
                    );

                    if ($existing) {
                        continue;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | INSERT
                |--------------------------------------------------------------------------
                */

                $this->db->insert(
                    'q_contacts',
                    [
                        $domain_uuid,
                        $companyName,
                        $jobTitle,
                        $firstName,
                        $middleName,
                        $lastName,
                        $email1,
                        $address1,
                        '',
                        $city,
                        $state,
                        $country,
                        $postal,
                        $mobilePhone,
                        '',
                        '',
                        '',
                        $notes,
                        $this->security->getRandom()->uuid(),
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s'),
                        $phone2,
                        $officePhone,
                        $mobilePhone,
                        $email2,
                        'o365_org',
                        $session["user_uuid"] ?? '',
                        date('Y-m-d H:i:s')
                    ],
                    [
                        'domain_uuid',
                        'company_name',
                        'title',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'email',
                        'address_1',
                        'address_2',
                        'city',
                        'state',
                        'country',
                        'postal',
                        'phone',
                        'fax',
                        'timezone',
                        'language',
                        'notes',
                        'insert_uuid',
                        'insert_date',
                        'update_date',
                        'phone2',
                        'office_phone',
                        'whatsapp_phone',
                        'email2',
                        'contacttype',
                        'createdby',
                        'createdate'
                    ]
                );

                $imported++;
            }

            $nextUrl = $contactsData['@odata.nextLink'] ?? null;
        }

        return $this->response->setJsonContent([
            'success'   => true,
            'imported' => $imported
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'success'  => false,
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function contactsFilterAction()
{
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth");

        if (!$session) {
            throw new \Exception("Session expired");
        }

        if (!$this->request->isPost()) {
            throw new \Exception("Invalid request method");
        }
        $data = $this->request->getJsonRawBody(true);

        $page = (int)($data['page'] ?? 1);

        $overallLimit = (int)($data['limit'] ?? 100);

        $pageSize = 25;

        $offset = ($page - 1) * $pageSize;

        if ($offset < 0) {
            $offset = 0;
        }

        $remaining = $overallLimit - $offset;
        if ($remaining <= 0) {
            return $this->response->setJsonContent([
                "result" => "success",
                "count" => 0,
                "content" => []
            ]);
        }
        $queryLimit = min($pageSize, $remaining);


        $sources         = $data['sources'] ?? [];
        $hasPhone        = $data['has_phone'] ?? false;
        $hasEmail        = $data['has_email'] ?? false;
        $company         = trim($data['company'] ?? '');
        $department      = trim($data['department'] ?? '');
        $keyword         = trim($data['keyword'] ?? '');
        $sortBy          = $data['sort_by'] ?? 'name_asc';

        $favoritesOnly   = $data['favorites_only'] ?? false;
        $duplicatesOnly  = $data['duplicates_only'] ?? false;

        $conditions = [];
        $bind = [];

        // REQUIRED
        $conditions[] = "domain_uuid = :domain_uuid:";
        $bind["domain_uuid"] = $session["domain_uuid"];

        // CONTACT SOURCE FILTER
        if (!empty($sources) && is_array($sources)) {

            $sourceConditions = [];

            foreach ($sources as $index => $source) {

                if ($source === 'ringq') {

            $sourceConditions[] = "(contacttype IS NULL OR contacttype = '')";

        } else {

            $key = "source_" . $index;

            $sourceConditions[] = "contacttype = :$key:";
            $bind[$key] = $source;
        }
            }

            if (!empty($sourceConditions)) {
                $conditions[] = "(" . implode(" OR ", $sourceConditions) . ")";
            }
        }

        // HAS PHONE
        if ($hasPhone == "true" || $hasPhone == 1) {

            $conditions[] = "(
                phone IS NOT NULL AND phone != ''
                OR phone2 IS NOT NULL AND phone2 != ''
                OR office_phone IS NOT NULL AND office_phone != ''
                OR whatsapp_phone IS NOT NULL AND whatsapp_phone != ''
            )";
        }

        // HAS EMAIL
        if ($hasEmail == "true" || $hasEmail == 1) {

            $conditions[] = "(
                email IS NOT NULL AND email != ''
                OR email2 IS NOT NULL AND email2 != ''
            )";
        }

        // COMPANY
        if (!empty($company)) {

            $conditions[] = "LOWER(company_name) LIKE :company:";
            $bind["company"] = "%" . strtolower($company) . "%";
        }

        // DEPARTMENT / TITLE
        if (!empty($department)) {

            $conditions[] = "LOWER(title) LIKE :department:";
            $bind["department"] = "%" . strtolower($department) . "%";
        }

        // KEYWORD SEARCH
        if (!empty($keyword)) {

            $conditions[] = "(
                LOWER(first_name) LIKE :keyword:
                OR LOWER(last_name) LIKE :keyword:
                OR LOWER(company_name) LIKE :keyword:
                OR LOWER(email) LIKE :keyword:
                OR LOWER(phone) LIKE :keyword:
            )";

            $bind["keyword"] = "%" . strtolower($keyword) . "%";
        }

        // DUPLICATES ONLY
        if ($duplicatesOnly == "true" || $duplicatesOnly == 1) {

            $conditions[] = "(
                phone IN (
                    SELECT phone
                    FROM q_contacts
                    WHERE phone IS NOT NULL
                    GROUP BY phone
                    HAVING COUNT(*) > 1
                )
            )";
        }

        // SORTING
        $order = "first_name ASC";

        switch ($sortBy) {

            case "name_desc":
                $order = "first_name DESC";
                break;

            case "company_asc":
                $order = "company_name ASC";
                break;

            case "company_desc":
                $order = "company_name DESC";
                break;

            case "recent":
                $order = "insert_date DESC";
                break;

            default:
                $order = "first_name ASC";
        }

        $finalConditions = implode(" AND ", $conditions);

        // GET CONTACTS
        $qContacts = QContacts::find([
            "conditions" => $finalConditions,
            "bind"       => $bind,
            "limit"      => $queryLimit,
            "offset"     => $offset,
            "order"      => $order
        ]);

        // COUNT
        $count = QContacts::count([
            "conditions" => $finalConditions,
            "bind"       => $bind
        ]);

        $content = [];
        $index = 0;

                foreach ($qContacts as $qContact) {

            $content[$index] = [
                "id"              => $qContact->id,
                "domain_uuid"     => $qContact->domain_uuid,
                "company_name"    => $qContact->company_name,
                "companyname"     => $qContact->company_name, // kept for backward compatibility
                "company"         => $qContact->company_name, // kept for backward compatibility
                "title"           => $qContact->title,
                "first_name"      => $qContact->first_name,
                "firstname"       => $qContact->first_name,   // kept for backward compatibility
                "middle_name"     => $qContact->middle_name,
                "last_name"       => $qContact->last_name,
                "lastname"        => $qContact->last_name,    // kept for backward compatibility
                "email"           => $qContact->email,
                "email2"          => $qContact->email2,
                "address_1"       => $qContact->address_1,
                "address_2"       => $qContact->address_2,
                "city"            => $qContact->city,
                "state"           => $qContact->state,
                "country"         => $qContact->country,
                "postal"          => $qContact->postal,
                "phone"           => $qContact->phone,
                "phone2"          => $qContact->phone2,
                "office_phone"    => $qContact->office_phone,
                "whatsapp_phone"  => $qContact->whatsapp_phone,
                "fax"             => $qContact->fax,
                "timezone"        => $qContact->timezone,
                "language"        => $qContact->language,
                "notes"           => $qContact->notes,
                "contacttype"     => $qContact->contacttype,
                "insert_uuid"     => $qContact->insert_uuid,
                "insert_date"     => $qContact->insert_date,
                "update_date"     => $qContact->update_date,
                "createdby"       => $qContact->createdby,
            ];

            $index++;
        }

        $response = [
            "result" => "success",
            "count"  => $overallLimit,
            "contactsCount" => $count,
            "page"   => (int)$page,
            "limit"  => $pageSize,
            "content"=> $content
        ];

    } catch (\Exception $e) {

        $response = [
            "result"  => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ];
    }

    return $this->response->setJsonContent($response);
}



public function contactsByNumberAction()
{
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

    try {

        if (!$this->request->isPost()) {
            throw new \Exception("Invalid request method");
        }

        $data = $this->request->getJsonRawBody(true);

        $internalKey = $this->request->getHeader("X-Internal-Key");
        $domainUuid  = null;

        if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            // Normal user session required
            $session = $this->session->get("auth");

            if (!$session) {
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "message" => "Unauthorized"
                ]);
            }

            $domainUuid = $session["domain_uuid"] ?? null;
        } else {
            // Internal service call: domain_uuid must be passed explicitly
            $domainUuid = trim($data['domain_uuid'] ?? '');
        }

        if (empty($domainUuid)) {
            throw new \Exception("Unable to resolve domain");
        }

        $number = trim($data['number'] ?? '');

        if (empty($number)) {
            throw new \Exception("Number is required");
        }

        // Normalize: strip everything except digits (handles +, -, (), spaces, ext.)
        $digitsOnly = preg_replace('/\D+/', '', $number);


        if (empty($digitsOnly)) {
            throw new \Exception("Invalid number");
        }

        // ---------------------------------------------------------------
        // 1) FIND THE LAST WRAP-UP NOTE LOGGED AGAINST THIS NUMBER
        //    (wrapupnotes.contact is the free-text number field)
        // ---------------------------------------------------------------

        $qWrap = QWrapupnotesList::findFirst([
            "conditions" => "
                domain_uuid = :domain_uuid:
                AND regexp_replace(trim(COALESCE(contact, '')), '[^0-9]', '', 'g') = :number:
            ",
            "bind" => [
                "domain_uuid" => $domainUuid,
                "number"      => $digitsOnly,
            ],
            "order" => "id DESC"
        ]);



        $wrapupNote = null;
        $contact    = null;

        if ($qWrap) {
            $wrapupNote = [
                "id"              => $qWrap->id,
                "content"         => $qWrap->content,
                "insert_date"     => $qWrap->insert_date,
                "extension"       => $qWrap->extension,
                "username"        => $qWrap->username,
                "customer"        => $qWrap->customer,
                "contact"         => $qWrap->contact,
                "wrapup_code"     => $qWrap->wrapup_code,
                "queue_extension" => $qWrap->queue_extension,
            ];

            // ---------------------------------------------------------------
            // 2) LOAD THE CONTACT LINKED TO THAT NOTE, IF ANY
            // ---------------------------------------------------------------
            if (!empty($qWrap->contact_id)) {
                $qContact = QContacts::findFirst([
                    "conditions" => "id = :id: AND domain_uuid = :domain_uuid:",
                    "bind"       => ["id" => (int) $qWrap->contact_id, "domain_uuid" => $domainUuid],
                ]);

               

                if ($qContact) {
                    $contact = [
                        "id"              => $qContact->id,
                        "company_name"    => $qContact->company_name,
                        "first_name"      => $qContact->first_name,
                        "middle_name"     => $qContact->middle_name,
                        "last_name"       => $qContact->last_name,
                        "email"           => $qContact->email,
                        "email2"          => $qContact->email2,
                        "address_1"       => $qContact->address_1,
                        "address_2"       => $qContact->address_2,
                        "city"            => $qContact->city,
                        "state"           => $qContact->state,
                        "country"         => $qContact->country,
                        "postal"          => $qContact->postal,
                        "phone"           => $qContact->phone,
                        "phone2"          => $qContact->phone2,
                        "office_phone"    => $qContact->office_phone,
                        "whatsapp_phone"  => $qContact->whatsapp_phone,
                        "fax"             => $qContact->fax,
                        "notes"           => $qContact->notes,
                    ];
                }
            }
        }

         $response = json_encode([
            "result"     => "success",
            "content"    => $contact,     // single contact, or null
            "wrapupnote" => $wrapupNote,  // last wrap-up note for this number, or null
        ]);

    } catch (\Exception $e) {

        $response = json_encode([
            "result"  => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ]);
    }
    return $this->response->setContent($response); // but this is not wokring
}
}






