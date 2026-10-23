<?php
declare(strict_types=1); 

use Phalcon\Mvc\View;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use \Phalcon\Http\Request;
use Symfony\Component\Process\Process;
use Phalcon\Db\Column;
require_once __DIR__ . '/../library/PersonName.php';
 
class LicensingController extends ControllerBase
{
    public function verifyInstallationTypeAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $licenseKey = $this->request->getPost('licenseKey'); 

        try {
            $ch = curl_init('https://licensekey.ringq.com/process/setupinitial');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array_filter([
                'licenseKey' => $licenseKey,
                'type' => 2,
                'referenceId' => $this->installReferenceId(),
            ])));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 

            $responseRaw = curl_exec($ch);
            if (curl_errno($ch)) {
                $error_msg = curl_error($ch);
                throw new \Exception($error_msg);
            }
            curl_close($ch);
            
            $response = json_decode($responseRaw, true);
            
            if ($response['result'] == 'success') {
                $this->redis->set('install_mode', $response['data']['which']);

                $setTimezone = new Process(['sudo', 'timedatectl', 'set-timezone', $response['data']['timeZone']]);
                $setTimezone->setTimeout(600);
                $setTimezone->run(); 

                if (empty($response['data']['fqdn'])) {
                    echo json_encode([
                        'status'  => "success", 
                        'result'  => "warning",
                        'message' => "Please finish the setup of your license key.", 
                        'mode' => $response['data']['which'],
                    ]);
                } else {
                    echo json_encode([
                        'status'  => "success", 
                        'result'  => "success",
                        'fqdn' => $response['data']['fqdn'],
                        'mode' => $response['data']['which'], 
                    ]);
                }

                fastcgi_finish_request();

                $phpReload = new Process(['sudo', 'service', 'php7.4-fpm', 'reload']);
                $phpReload->setTimeout(600);
                $phpReload->run(); 
                return;
            }

            return $this->response->setJsonContent([
                'status' => "error", 
                'result' => "error",
                'message' => "Invalid license key", 
            ]);
        } catch (\Throwable $th) {
            return $this->response->setJsonContent([
                'status' => "error",  
                'result' => 'error',
                'message' => "",
                'content' => [
                    'result' => 'error', 
                    'message' => ControllerBase::safeErrorMessage($th)
                ]
            ]);
        }
    }

    public function statusAction() {
        if ($this->redis->exists('activation_status')) {
            $activationStatus = json_decode($this->redis->get('activation_status'), true); 

            $data = [];
            if ($this->redis->exists('ringq_config')) {
                $ringqConfig = $this->redis->get('ringq_config');
                $data = json_decode($ringqConfig, true); 
                
                // Unset sensitive / unneeded data
                if (isset($data['data'])) {
                    $fieldsToRemove = [
                        'companyName', 'firstname', 'lastname', 'customerToken',
                        'address_1', 'address_2', 'phone', 'city', 'state', 'country',
                        'fax', 'contact_person', 'user_id', 'uuid',
                        'access_token', 'sipLogin', 'sipPassword', 'proxy', 'gateway', 'did',
                        'type', 'extension_length', 'licenseKey', 'serverType', 'packagename',
                        'packageId'
                    ];
                    foreach ($fieldsToRemove as $field) {
                        unset($data['data'][$field]);
                    }
                }
            }

            if ($activationStatus['percentage'] !== 100) {
                return $this->response->setJsonContent([
                    'result' => 'in progress',
                    'content' => $data
                ]);
            }

            return $this->response->setJsonContent([
                'result' => 'activated',
                'content' => $data
            ]);
        }

        return $this->response->setJsonContent([
            'result' => 'error', 
        ]);
    }

    /**
     * Resolve this server's own public IPv4 address.
     *
     * This used to be `trim(shell_exec('curl -s ifconfig.me'))` — no --max-time, no
     * -4, no fallback, no validation. It is called on the 8th line of
     * registerlicenceAction(), before the ringq_config guard and before any outbound
     * call, so it sits on the critical path of every licence activation.
     *
     * That made activation datacenter-dependent. ifconfig.me is a single geo-routed
     * third party, rate-limited per SOURCE IP RANGE, and a cloud provider's egress
     * ranges differ per region and are shared with every other tenant in that region.
     * From a throttled or distant region the lookup stalls; with no --max-time, curl
     * blocked, shell_exec blocked with it, and the whole activation hung before doing
     * any work — so the caller timed out, retried, and hung again. When it returned
     * empty instead, an empty ipAddress was posted to process/setup and activation
     * failed for a different reason. Singapore appearing healthy simply meant that
     * path happened to reach a fast PoP.
     *
     * Note every OTHER public-IP lookup in this codebase already had these guards
     * (port_checker.sh:27, jobs.sh:1644, LicensingController:2317, PortsController:949,
     * ApiController:29396) — this one call, on the most critical path, did not.
     *
     * Fix: ask the hypervisor, not the internet. The cloud metadata services are
     * link-local (169.254.169.254), never leave the host, carry no rate limit and
     * answer identically in every region. Public providers remain only as a fallback
     * for bare-metal installs. Every hop is bounded, the result must be a PUBLIC IPv4
     * (matching what ifconfig.me always returned), and total failure is logged rather
     * than silently returning ''.
     */
    protected function serverIpAddress() {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        foreach (self::publicIpv4Sources() as $label => $command) {
            $output = @shell_exec($command . ' 2>/dev/null');
            $candidate = trim((string) $output);

            if (filter_var(
                $candidate,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                if ($label !== 'do-metadata') {
                    error_log('serverIpAddress: resolved via fallback source "' . $label . '"');
                }
                $cached = $candidate;
                return $cached;
            }
        }

        // Deliberately loud. An empty public IP is not a recoverable condition here —
        // it is posted onward as `ipAddress` and silently breaks activation.
        error_log('serverIpAddress: FAILED — no public IPv4 from metadata or any fallback provider');
        $cached = '';

        return $cached;
    }

    /**
     * Ordered public-IPv4 sources. Metadata services first (link-local, instant,
     * region-independent), then bounded public providers for non-cloud installs.
     * --connect-timeout keeps a bare-metal box, where 169.254.169.254 has no route,
     * from paying the full --max-time on each metadata probe.
     */
    protected static function publicIpv4Sources() {
        return [
            'do-metadata'  => 'curl -s -4 --connect-timeout 1 --max-time 2 '
                . 'http://169.254.169.254/metadata/v1/interfaces/public/0/ipv4/address',
            'ec2-metadata' => 'curl -s -4 --connect-timeout 1 --max-time 2 '
                . 'http://169.254.169.254/latest/meta-data/public-ipv4',
            'amazon'       => 'curl -s -4 --max-time 5 https://checkip.amazonaws.com',
            'ipify'        => 'curl -s -4 --max-time 5 https://api.ipify.org',
            'icanhazip'    => 'curl -s -4 --max-time 5 https://icanhazip.com',
            'ifconfig'     => 'curl -s -4 --max-time 5 https://ifconfig.me/ip',
        ];
    }

    protected function installReferenceId() {
        $referenceFile = '/var/log/ringq/install_reference_id';
        if (!is_readable($referenceFile)) {
            return '';
        }

        $referenceId = substr(preg_replace('/[^A-Za-z0-9]/', '', (string) file_get_contents($referenceFile)), 0, 10);

        return strlen($referenceId) === 10 ? $referenceId : '';
    }

    public function resendotpAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $licenseKey = $this->request->getPost('licenseKey', 'string'); 
        $clientIp = $this->request->getPost('clientIp', 'string');  

        $serverIpAddress = $this->serverIpAddress(); 

        $request = new Request();
        $licenseType = explode(' ', $licenseKey);
        $serverType = isset($licenseType[1]) && $licenseType[1] == 'test' ? 1 : 2;

        try {   
            $response = (new Client())->post('https://licensekey.ringq.com/process/setup', [
                'json' => array_filter([
                    'licenseKey' => $licenseType[0],
                    'clientIp' => $clientIp,
                    'ipAddress' => $serverIpAddress,
                    'type' => $serverType,
                    "for" => 'resend',
                    'referenceId' => $this->installReferenceId(),
                ]),
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]
            ]);

            $jsonData = $response->getBody()->getContents();
            $data = json_decode($jsonData, true);
 
            return $this->response->setJsonContent([
                'status' => $response->getStatusCode(),
                'content' => $data
            ]);
        } catch (RequestException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();

            $decodedResponse = json_decode($responseBody, true);

            return $this->response->setJsonContent([
                'error' =>  $decodedResponse,
                'result' => $decodedResponse['result'] ?? 'error',
                'content' => $decodedResponse
            ]);
        }
    } 

    public function registerlicenceAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $licenseKey = $this->request->getPost('licenseKey', 'string'); 
        $clientIp = $this->request->getPost('clientIp', 'string');
        $otp = $this->request->getPost('otp', 'string'); 

        $serverIpAddress = $this->serverIpAddress(); 

        $request = new Request();
        $licenseType = explode(' ', $licenseKey);
        $serverType = isset($licenseType[1]) && $licenseType[1] == 'test' ? 1 : 2;
        $this->redis->set('server_type', $serverType);

        if ($this->redis->exists('ringq_config')) {
            return $this->response->setJsonContent([
                'result' => 'error',
                'message' => '',
                'content' => [
                    'result' => 'error',
                    'message' => 'Already activated.',
                ]
            ]);
        }

        try {
            $payload = array_filter([
                'licenseKey' => $licenseType[0],
                'clientIp' => $clientIp,
                'ipAddress' => $serverIpAddress,
                'type' => $serverType,
                'otp' => $otp,
                "for" => !empty($otp) ? 'validate' : '',
                'referenceId' => $this->installReferenceId(),
            ]);

            $response = (new Client())->post('https://licensekey.ringq.com/process/setup', [
                'json' => $payload,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ]
            ]);

            $jsonData = $response->getBody()->getContents();
            $data = json_decode($jsonData, true); 

            if ($data['result'] === 'success') {
                set_time_limit(300); 

                $security = new \Phalcon\Security\Random();
                $userUuid = $security->uuid();
                $domainUuid = $security->uuid();
                $user_uuid = $userUuid;
                $domain_uuid = $domainUuid;

                // Find the existing IP domain record to cross-link with the FQDN record
                $existingIpDomain = VDomains::findFirst([
                    "conditions" => "domain_name = :server_ip:",
                    "bind" => ["server_ip" => $serverIpAddress],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]);
                $company_email = $data['data']["company_email"];
                $voicemail_uuid = $security->uuid();
                $company = strtolower(str_replace(" ", "", preg_replace("/[^a-zA-Z0-9_ -]/s", " ", $data['data']["companyName"])));
                $fqdn = $data['data']["fqdn"];
                $extension = $data['data']["extension_length"];
                $firstname = PersonName::capitalize($data['data']["firstname"]);
                $lastname = PersonName::capitalize($data['data']["lastname"]);
                $package = $data['data']["packagename"];
                $packageId = $data['data']["packageId"];
                $companyEmail = $data['data']["company_email"];
                $companyName = $data['data']["companyName"];
                $access_token = $data['data']["access_token"];
                $loginPassword = $data['data']["customerToken"];
                $uuid = $security->uuid();
                $username = $security->hex(10);
                $salt = $this->security->hash($security->base64(16));
                $password = $this->security->hash($security->base64(16));
                $password2 = $security->base64(16);
                $extension_password = $password2;

                $qCompany = new QCompanies();
                $qCompany->user_uuid = $security->uuid();
                $qCompany->domain_uuid = $domain_uuid;
                $qCompany->company_name = $companyName;
                $qCompany->company_email = $companyEmail;
                $qCompany->uid = $data['data']["user_id"];
                $qCompany->uuid = $data['data']["uuid"];
                $qCompany->token = $data['data']["access_token"];
                $qCompany->insert_date = date("Y-m-d H:i:s");
                $qCompany->owner = 9999;
                $qCompany->manager = 9999;
                $qCompany->user = 9999;
                $qCompany->queue = 9999;
                $qCompany->storage = 9999;
                $qCompany->devices = 9999;
                $qCompany->packageName = $package;
                $qCompany->packageId = intval($packageId); 
                if (!$qCompany->save()) {
                    error_log(json_encode([
                        'msg' => 'qCompany exit',
                        'error' => $qCompany->getMessages()
                    ], JSON_PRETTY_PRINT));
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1003)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                error_log('qCompany continue');

                if ($data['data']['installationType'] == 'multi') {
                    $ch = curl_init("https://licensekey.ringq.com/process/firstlogin");
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER,array(
                        "Accept: application/json",
                        "Content-Type: application/json",
                        "Authorization: asgasgihqwgagsjokashiogahiosasbjg",
                    ),);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                        "licenseKey" => $licenseType[0],
                        "userId" => $data['data']["user_id"], 
                        "type" => (int)$serverType
                    ]));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch);
                    curl_close($ch);
                } 

                $qPackagesCompany = new QPackagesCompany();
                $qPackagesCompany->user_uuid = $user_uuid;
                $qPackagesCompany->package_id = $packageId;
                $qPackagesCompany->uuid = $uuid;
                $qPackagesCompany->access_token = $access_token;
                $qPackagesCompany->created_at = date("Y-m-d H:i:s");
                if (!$qPackagesCompany->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1003)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vDomain = new VDomains();
                $vDomain->domain_uuid = $domain_uuid;
                $vDomain->domain_name = $fqdn;
                $vDomain->domain_description = $fqdn;
                $vDomain->domain_parent_uuid = null; // FQDN record has no parent; only IP records point to FQDN
                $vDomain->domain_enabled = true;
                $vDomain->insert_user = $user_uuid;
                $vDomain->insert_date = date("Y-m-d H:i:s");
                if (!$vDomain->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1004)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }

                // Update or create public IP domain record linked to FQDN
                if ($existingIpDomain) {
                    /** @var VDomains $existingIpDomain */
                    $existingIpDomain->domain_parent_uuid = $domain_uuid;
                    $existingIpDomain->update_date = date("Y-m-d H:i:s");
                    if (!$existingIpDomain->save()) {
                        $this->db->rollback();
                        $response = json_encode([
                            "result" => "error",
                            "message" => "An error has occurred. (Code RV1004B)",
                        ]);
                        $this->response->setContent($response);
                        return $this->response;
                    }
                } else {
                    // Public IP record doesn't exist yet — create it
                    $vDomainPublicIp = new VDomains();
                    $vDomainPublicIp->domain_uuid = $security->uuid();
                    $vDomainPublicIp->domain_name = $serverIpAddress;
                    $vDomainPublicIp->domain_description = $serverIpAddress;
                    $vDomainPublicIp->domain_parent_uuid = $domain_uuid;
                    $vDomainPublicIp->domain_enabled = true;
                    $vDomainPublicIp->insert_user = $user_uuid;
                    $vDomainPublicIp->insert_date = date("Y-m-d H:i:s");
                    $vDomainPublicIp->save();
                }

                // Create/update a v_domains record for every local interface IP linked to FQDN
                // so FreeSWITCH can look up users when it uses any local IP as the domain (NAT scenario).
                // On dual-NIC servers this includes both the LAN IP and the WAN interface IP.
                $allLocalIps = array_filter(
                    array_map('trim', explode("\n",
                        shell_exec("ip -4 addr show scope global | grep inet | awk '{print $2}' | cut -d/ -f1") ?? ''
                    )),
                    fn($ip) => $ip !== '' && $ip !== $serverIpAddress
                );
                foreach ($allLocalIps as $localIp) {
                    $existingLocalIpDomain = VDomains::findFirst([
                        "conditions" => "domain_name = :local_ip:",
                        "bind" => ["local_ip" => $localIp],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]);
                    if (!$existingLocalIpDomain) {
                        $vDomainLocal = new VDomains();
                        $vDomainLocal->domain_uuid = $security->uuid();
                        $vDomainLocal->domain_name = $localIp;
                        $vDomainLocal->domain_description = $localIp;
                        $vDomainLocal->domain_parent_uuid = $domain_uuid;
                        $vDomainLocal->domain_enabled = true;
                        $vDomainLocal->insert_user = $user_uuid;
                        $vDomainLocal->insert_date = date("Y-m-d H:i:s");
                        $vDomainLocal->save();
                    } elseif (!$existingLocalIpDomain->domain_parent_uuid) {
                        /** @var VDomains $existingLocalIpDomain */
                        $existingLocalIpDomain->domain_parent_uuid = $domain_uuid;
                        $existingLocalIpDomain->update_date = date("Y-m-d H:i:s");
                        $existingLocalIpDomain->save();
                    }
                }

                if ($data['data']['installationType'] == 'single') {
                    // Set force-register-domain to the FQDN so FreeSWITCH stores SIP registrations
                    // under the same domain the dialplan uses for bridge lookups.
                    // This must run at license activation (not install time) because the FQDN
                    // is not in v_domains until this activation creates it.
                    $this->db->execute("
                        UPDATE v_sip_profile_settings
                        SET sip_profile_setting_value = :fqdn, sip_profile_setting_enabled = 'true'
                        WHERE sip_profile_setting_name IN ('force-register-domain', 'force-register-db-domain', 'force-subscription-domain')
                    ", ['fqdn' => $fqdn]);
                }

                $qUser = new QUsers();
                $qUser->user_uuid = $user_uuid;
                $qUser->domain_uuid = $domain_uuid;
                $qUser->first_name = $firstname;
                $qUser->last_name = $lastname;
                $qUser->extension_enabled = true;
                $qUser->insert_date = date("Y-m-d H:i:s");
                $qUser->user_email = $company_email;
                $qUser->password = password_hash((string) $loginPassword, PASSWORD_DEFAULT);
                if (!$qUser->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1005)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vUser = new VUsers();
                $vUser->username = $username;
                $vUser->user_uuid = $user_uuid;
                $vUser->domain_uuid = $domain_uuid;
                $vUser->user_email = $company_email;
                $vUser->password = $password;
                // $vUser->salt = $salt;
                $vUser->user_status = "Available";
                $vUser->user_enabled = "true";
                $vUser->api_key = $security->base64(32);
                $vUser->image = null;
                $vUser->first_name = $firstname;
                $vUser->last_name = $lastname;
                $vUser->insert_user = $user_uuid;
                $vUser->insert_date = date("Y-m-d H:i:s");
                if (!$vUser->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1006)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtension = new VExtensions();
                $vExtension->extension_uuid = $extension_uuid = $security->uuid();
                $vExtension->domain_uuid = $domain_uuid;
                $vExtension->extension = $extension;
                $vExtension->number_alias = $username;
                $vExtension->password = $extension_password;
                $vExtension->enabled = "true";
                $vExtension->extension_type = "default";
                $vExtension->accountcode = $fqdn; // <!-- blank on original
                $vExtension->effective_caller_id_number = $extension;
                $vExtension->directory_visible = "true";
                $vExtension->directory_exten_visible = "true";
                $vExtension->limit_max = "5"; // <!-- Should be based on purchased package
                $vExtension->limit_destination = "!USER_BUSY";
                $vExtension->effective_caller_id_name = $firstname . " " . $lastname; 
                $vExtension->do_not_disturb = "false";
                $vExtension->forward_all_enabled = "false";
                $vExtension->forward_all_enabled2 = "false";
                $vExtension->forward_busy_enabled = "false";
                $vExtension->forward_busy_enabled2 = "false";
                $vExtension->forward_no_answer_enabled = "true";
                $vExtension->forward_no_answer_enabled2 = "true";
                $vExtension->forward_user_not_registered_enabled = "false";
                $vExtension->forward_user_not_registered_enabled2 = "false";
                $vExtension->forward_no_answer_destination = "*99" . $extension;
                $vExtension->forward_no_answer_destination2 = "*99" . $extension;
                $vExtension->voicemail_enabled = "enabled";
                $vExtension->voicemail_file = "attach";
                $vExtension->call_timeout = "30";
                $vExtension->call_screen_enabled = "false";
                $vExtension->user_context = $fqdn;
                $vExtension->voicemail_uuid = $voicemail_uuid;
                $vExtension->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                $vExtension->insert_user = $user_uuid;
                $vExtension->insert_date = date("Y-m-d H:i:s"); 
                if (!$vExtension->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1007)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtensionUser = new VExtensionUsers();
                $vExtensionUser->extension_user_uuid = $security->uuid();
                $vExtensionUser->domain_uuid = $domain_uuid;
                $vExtensionUser->user_uuid = $user_uuid;
                $vExtensionUser->extension_uuid = $extension_uuid;
                if (!$vExtensionUser->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred.",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vVoicemail = new VVoicemails();
                $vVoicemail->domain_uuid = $domain_uuid;
                $vVoicemail->voicemail_uuid = $voicemail_uuid;
                $vVoicemail->voicemail_id = $extension;
                $vVoicemail->voicemail_mail_to = $company_email;
                $vVoicemail->voicemail_password = (string)random_int(1000, 9999);
                $vVoicemail->voicemail_transcription_enabled = "false";
                $vVoicemail->voicemail_file = "attach";
                $vVoicemail->voicemail_local_after_email = "true";
                $vVoicemail->voicemail_enabled = "true";
                if (!$vVoicemail->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1008)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $path = "/var/lib/freeswitch/recordings/{$fqdn}/archive";
                if (!file_exists($path)) {
                    mkdir($path, 0777, true);
                }
                $vUserGroup = new VUserGroups();
                $vUserGroup->user_group_uuid = $security->uuid();
                $vUserGroup->user_uuid = $user_uuid;
                $VGroup = VGroups::findFirst([
                    "conditions" => "group_name = :group_name:",
                    "bind" => [
                        "group_name" => "admin",
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]);
                $vUserGroup->group_uuid = $VGroup->group_uuid;
                $vUserGroup->group_name = $VGroup->group_name;
                $vUserGroup->domain_uuid = $domain_uuid;
                if (!$vUserGroup->save()) { 
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1009)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                } 
                $vIvrMenu = new VIvrMenus();
                $ivr_menu_uuid = $security->uuid();
                $dialplan_uuid = $security->uuid();
                $vIvrMenu->ivr_menu_uuid = $ivr_menu_uuid;
                $vIvrMenu->domain_uuid = $domain_uuid;
                $vIvrMenu->dialplan_uuid = $dialplan_uuid;
                $vIvrMenu->ivr_menu_extension = "3000";
                $vIvrMenu->ivr_menu_name = "Ratings";
                $vIvrMenu->ivr_menu_timeout = 30000;
                $vIvrMenu->ivr_menu_greet_long = "Thank_you-we-hope-yo.wav";
                $vIvrMenu->ivr_menu_parent_uuid = null;
                $vIvrMenu->ivr_menu_language = "";
                $vIvrMenu->ivr_menu_dialect = "us";
                $vIvrMenu->ivr_menu_voice = "callie";
                $vIvrMenu->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                $vIvrMenu->ivr_menu_exit_sound = "";
                $vIvrMenu->ivr_menu_pin_number = "";
                $vIvrMenu->ivr_menu_confirm_macro = "";
                $vIvrMenu->ivr_menu_confirm_key = "";
                $vIvrMenu->ivr_menu_tts_engine = "flite";
                $vIvrMenu->ivr_menu_tts_voice = "rms";
                $vIvrMenu->ivr_menu_confirm_attempts = 1;
                $vIvrMenu->ivr_menu_exit_app = "hangup";
                $vIvrMenu->ivr_menu_exit_data = null;
                $vIvrMenu->ivr_menu_inter_digit_timeout = 2000;
                $vIvrMenu->ivr_menu_max_failures = 1;
                $vIvrMenu->ivr_menu_max_timeouts = 1;
                $vIvrMenu->ivr_menu_digit_len = 5;
                $vIvrMenu->ivr_menu_direct_dial = "false";
                $vIvrMenu->ivr_menu_ringback = "local_stream://default";
                $vIvrMenu->ivr_menu_cid_prefix = "";
                $vIvrMenu->ivr_menu_context = $fqdn;
                $vIvrMenu->ivr_menu_enabled = "true";
                $vIvrMenu->ivr_menu_description = "";
                $vIvrMenu->insert_date = date("Y-m-d H:i:s");
                if (!$vIvrMenu->create()) {
                    $this->db->rollback();
                    $messages = $vIvrMenu->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1004)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vDialplan = new VDialplans();
                $vDialplan->domain_uuid = $domain_uuid;
                $vDialplan->dialplan_uuid = $dialplan_uuid;
                $vDialplan->app_uuid = $security->uuid();
                $vDialplan->dialplan_name = "Ratings";
                $vDialplan->dialplan_number = "3000";
                $vDialplan->dialplan_order = 81;
                $vDialplan->dialplan_context = $fqdn;
                $vDialplan->dialplan_continue = "false";
                $vDialplan->dialplan_xml =
                    '<extension name="Ratings" continue="false" uuid="' .
                    $dialplan_uuid .
                    '">
                                    <condition field="destination_number" expression="^3000$">
                                        <action application="ring_ready" data=""/>
                                        <action application="answer" data=""/>
                                        <action application="sleep" data="1000"/>
                                        <action application="set" data="hangup_after_bridge=true"/>
                                        <action application="set" data="ringback=local_stream://default"/>
                                        <action application="set" data="transfer_ringback=local_stream://default"/>
                                        <action application="set" data="ivr_menu_uuid=' .
                    $ivr_menu_uuid .
                    '"/>
                                        <action application="ivr" data="' .
                    $ivr_menu_uuid .
                    '"/>
                                        <action application="hangup" data=""/>
                                    </condition>
                    </extension>';
                $vDialplan->dialplan_enabled = "true";
                $vDialplan->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan->create()) {
                    $this->db->rollback();
                    $messages = $vDialplan->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenuOption = new VIvrMenuOptions();
                $vIvrMenuOption->domain_uuid = $domain_uuid;
                $vIvrMenuOption->ivr_menu_uuid = $ivr_menu_uuid;
                $vIvrMenuOption->ivr_menu_option_uuid = $security->uuid();
                $vIvrMenuOption->ivr_menu_option_action = "menu-exec-app";
                $vIvrMenuOption->ivr_menu_option_param = "transfer 3001 XML " . $fqdn;
                $vIvrMenuOption->ivr_menu_option_order = 0;
                $vIvrMenuOption->ivr_menu_option_digits = "1";
                $vIvrMenuOption->ivr_menu_option_enabled = 1;
                if (!$vIvrMenuOption->create()) {
                    $this->db->rollback();
                    $messages = $vIvrMenuOption->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1006)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenuOption2 = new VIvrMenuOptions();
                $vIvrMenuOption2->domain_uuid = $domain_uuid;
                $vIvrMenuOption2->ivr_menu_uuid = $ivr_menu_uuid;
                $vIvrMenuOption2->ivr_menu_option_uuid = $security->uuid();
                $vIvrMenuOption2->ivr_menu_option_action = "menu-exec-app";
                $vIvrMenuOption2->ivr_menu_option_param = "transfer 3002 XML " . $fqdn;
                $vIvrMenuOption2->ivr_menu_option_order = 1;
                $vIvrMenuOption2->ivr_menu_option_digits = "2";
                $vIvrMenuOption2->ivr_menu_option_enabled = 1;
                if (!$vIvrMenuOption2->create()) {
                    $this->db->rollback();
                    $messages = $vIvrMenuOption2->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1006)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenuOption3 = new VIvrMenuOptions();
                $vIvrMenuOption3->domain_uuid = $domain_uuid;
                $vIvrMenuOption3->ivr_menu_uuid = $ivr_menu_uuid;
                $vIvrMenuOption3->ivr_menu_option_uuid = $security->uuid();
                $vIvrMenuOption3->ivr_menu_option_action = "menu-exec-app";
                $vIvrMenuOption3->ivr_menu_option_param = "transfer 3003 XML " . $fqdn;
                $vIvrMenuOption3->ivr_menu_option_order = 2;
                $vIvrMenuOption3->ivr_menu_option_digits = "3";
                $vIvrMenuOption3->ivr_menu_option_enabled = 1;
                if (!$vIvrMenuOption3->create()) { 
                    $this->db->rollback();
                    $messages = $vIvrMenuOption3->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1006)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenuOption4 = new VIvrMenuOptions();
                $vIvrMenuOption4->domain_uuid = $domain_uuid;
                $vIvrMenuOption4->ivr_menu_uuid = $ivr_menu_uuid;
                $vIvrMenuOption4->ivr_menu_option_uuid = $security->uuid();
                $vIvrMenuOption4->ivr_menu_option_action = "menu-exec-app";
                $vIvrMenuOption4->ivr_menu_option_param = "transfer 3004 XML " . $fqdn;
                $vIvrMenuOption4->ivr_menu_option_order = 3;
                $vIvrMenuOption4->ivr_menu_option_digits = "4";
                $vIvrMenuOption4->ivr_menu_option_enabled = 1;
                if (!$vIvrMenuOption4->create()) {
                    $this->db->rollback();
                    $messages = $vIvrMenuOption4->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1006)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenuOption5 = new VIvrMenuOptions();
                $vIvrMenuOption5->domain_uuid = $domain_uuid;
                $vIvrMenuOption5->ivr_menu_uuid = $ivr_menu_uuid;
                $vIvrMenuOption5->ivr_menu_option_uuid = $security->uuid();
                $vIvrMenuOption5->ivr_menu_option_action = "menu-exec-app";
                $vIvrMenuOption5->ivr_menu_option_param = "transfer 3005 XML " . $fqdn;
                $vIvrMenuOption5->ivr_menu_option_order = 4;
                $vIvrMenuOption5->ivr_menu_option_digits = "5";
                $vIvrMenuOption5->ivr_menu_option_enabled = 1;
                if (!$vIvrMenuOption5->create()) {
                    $this->db->rollback();
                    $messages = $vIvrMenuOption5->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1006)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenu2 = new VIvrMenus();
                $ivr_menu_uuid2 = $security->uuid();
                $dialplan_uuid2 = $security->uuid();
                $vIvrMenu2->ivr_menu_uuid = $ivr_menu_uuid2;
                $vIvrMenu2->domain_uuid = $domain_uuid;
                $vIvrMenu2->dialplan_uuid = $dialplan_uuid2;
                $vIvrMenu2->ivr_menu_extension = "3001";
                $vIvrMenu2->ivr_menu_name = "Rating 1";
                $vIvrMenu2->ivr_menu_timeout = 2000;
                $vIvrMenu2->ivr_menu_greet_long = "you_have-chosen-one_.wav";
                $vIvrMenu2->ivr_menu_parent_uuid = null;
                $vIvrMenu2->ivr_menu_language = "";
                $vIvrMenu2->ivr_menu_dialect = "us";
                $vIvrMenu2->ivr_menu_voice = "callie";
                $vIvrMenu2->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                $vIvrMenu2->ivr_menu_exit_sound = "";
                $vIvrMenu2->ivr_menu_pin_number = "";
                $vIvrMenu2->ivr_menu_confirm_macro = "";
                $vIvrMenu2->ivr_menu_confirm_key = "";
                $vIvrMenu2->ivr_menu_tts_engine = "flite";
                $vIvrMenu2->ivr_menu_tts_voice = "rms";
                $vIvrMenu2->ivr_menu_confirm_attempts = 1;
                $vIvrMenu2->ivr_menu_exit_app = null;
                $vIvrMenu2->ivr_menu_exit_data = null;
                $vIvrMenu2->ivr_menu_inter_digit_timeout = 2000;
                $vIvrMenu2->ivr_menu_max_failures = 1;
                $vIvrMenu2->ivr_menu_max_timeouts = 1;
                $vIvrMenu2->ivr_menu_digit_len = 5;
                $vIvrMenu2->ivr_menu_direct_dial = "false";
                $vIvrMenu2->ivr_menu_ringback = "local_stream://default";
                $vIvrMenu2->ivr_menu_cid_prefix = "";
                $vIvrMenu->ivr_menu_context = $fqdn;
                $vIvrMenu2->ivr_menu_enabled = "true";
                $vIvrMenu2->ivr_menu_description = "";
                $vIvrMenu2->insert_date = date("Y-m-d H:i:s");
                if (!$vIvrMenu2->create()) { 
                    $this->db->rollback();
                    $messages = $vIvrMenu2->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1004)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                } 
                $vDialplan2 = new VDialplans();
                $vDialplan2->domain_uuid = $domain_uuid;
                $vDialplan2->dialplan_uuid = $dialplan_uuid2;
                $vDialplan2->app_uuid = $security->uuid();
                $vDialplan2->dialplan_name = "Rating 1";
                $vDialplan2->dialplan_number = "3001";
                $vDialplan2->dialplan_order = 81;
                $vDialplan2->dialplan_context = $fqdn;
                $vDialplan2->dialplan_continue = "false";
                $vDialplan2->dialplan_xml =
                    '<extension name="Rating 1" continue="false" uuid="' .
                    $dialplan_uuid2 .
                    '">
                                    <condition field="destination_number" expression="^3001$">
                                        <action application="ring_ready" data=""/>
                                        <action application="answer" data=""/>
                                        <action application="sleep" data="1000"/>
                                        <action application="set" data="hangup_after_bridge=true"/>
                                        <action application="set" data="ringback=local_stream://default"/>
                                        <action application="set" data="transfer_ringback=local_stream://default"/>
                                        <action application="set" data="ivr_menu_uuid=' .
                    $ivr_menu_uuid2 .
                    '"/>
                                        <action application="ivr" data="' .
                    $ivr_menu_uuid2 .
                    '"/>
                                        <action application="hangup" data=""/>
                                    </condition>
                    </extension>';
                $vDialplan2->dialplan_enabled = "true";
                $vDialplan2->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan2->create()) {
                    $this->db->rollback();
                    $messages = $vDialplan2->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenu3 = new VIvrMenus();
                $ivr_menu_uuid3 = $security->uuid();
                $dialplan_uuid3 = $security->uuid();
                $vIvrMenu3->ivr_menu_uuid = $ivr_menu_uuid3;
                $vIvrMenu3->domain_uuid = $domain_uuid;
                $vIvrMenu3->dialplan_uuid = $dialplan_uuid3;
                $vIvrMenu3->ivr_menu_extension = "3002";
                $vIvrMenu3->ivr_menu_name = "Rating 2";
                $vIvrMenu3->ivr_menu_timeout = 2000;
                $vIvrMenu3->ivr_menu_greet_long = "you_have-chosen-two_.wav";
                $vIvrMenu3->ivr_menu_parent_uuid = null;
                $vIvrMenu3->ivr_menu_language = "";
                $vIvrMenu3->ivr_menu_dialect = "us";
                $vIvrMenu3->ivr_menu_voice = "callie";
                $vIvrMenu3->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                $vIvrMenu3->ivr_menu_exit_sound = "";
                $vIvrMenu3->ivr_menu_pin_number = "";
                $vIvrMenu3->ivr_menu_confirm_macro = "";
                $vIvrMenu3->ivr_menu_confirm_key = "";
                $vIvrMenu3->ivr_menu_tts_engine = "flite";
                $vIvrMenu3->ivr_menu_tts_voice = "rms";
                $vIvrMenu3->ivr_menu_confirm_attempts = 1;
                $vIvrMenu3->ivr_menu_exit_app = null;
                $vIvrMenu3->ivr_menu_exit_data = null;
                $vIvrMenu3->ivr_menu_inter_digit_timeout = 2000;
                $vIvrMenu3->ivr_menu_max_failures = 1;
                $vIvrMenu3->ivr_menu_max_timeouts = 1;
                $vIvrMenu3->ivr_menu_digit_len = 5;
                $vIvrMenu3->ivr_menu_direct_dial = "false";
                $vIvrMenu3->ivr_menu_ringback = "local_stream://default";
                $vIvrMenu3->ivr_menu_cid_prefix = "";
                $vIvrMenu3->ivr_menu_context = $fqdn;
                $vIvrMenu3->ivr_menu_enabled = "true";
                $vIvrMenu3->ivr_menu_description = "";
                $vIvrMenu3->insert_date = date("Y-m-d H:i:s");
                if (!$vIvrMenu3->create()) { 
                    $this->db->rollback();
                    $messages = $vIvrMenu->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1004)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vDialplan3 = new VDialplans();
                $vDialplan3->domain_uuid = $domain_uuid;
                $vDialplan3->dialplan_uuid = $dialplan_uuid3;
                $vDialplan3->app_uuid = $security->uuid();
                $vDialplan3->dialplan_name = "Rating 2";
                $vDialplan3->dialplan_number = "3002";
                $vDialplan3->dialplan_order = 81;
                $vDialplan3->dialplan_context = $fqdn;
                $vDialplan3->dialplan_continue = "false";
                $vDialplan3->dialplan_xml =
                    '<extension name="Rating 2" continue="false" uuid="' .
                    $dialplan_uuid3 .
                    '">
                                    <condition field="destination_number" expression="^3002$">
                                        <action application="ring_ready" data=""/>
                                        <action application="answer" data=""/>
                                        <action application="sleep" data="1000"/>
                                        <action application="set" data="hangup_after_bridge=true"/>
                                        <action application="set" data="ringback=local_stream://default"/>
                                        <action application="set" data="transfer_ringback=local_stream://default"/>
                                        <action application="set" data="ivr_menu_uuid=' .
                    $ivr_menu_uuid3 .
                    '"/>
                                        <action application="ivr" data="' .
                    $ivr_menu_uuid3 .
                    '"/>
                                        <action application="hangup" data=""/>
                                    </condition>
                    </extension>';
                $vDialplan3->dialplan_enabled = "true";
                $vDialplan3->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan3->create()) { 
                    $this->db->rollback();
                    $messages = $vDialplan2->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                } 
                $vIvrMenu4 = new VIvrMenus();
                $ivr_menu_uuid4 = $security->uuid();
                $dialplan_uuid4 = $security->uuid();
                $vIvrMenu4->ivr_menu_uuid = $ivr_menu_uuid4;
                $vIvrMenu4->domain_uuid = $domain_uuid;
                $vIvrMenu4->dialplan_uuid = $dialplan_uuid4;
                $vIvrMenu4->ivr_menu_extension = "3003";
                $vIvrMenu4->ivr_menu_name = "Rating 3";
                $vIvrMenu4->ivr_menu_timeout = 2000;
                $vIvrMenu4->ivr_menu_greet_long = "you_have-chosen-thre.wav";
                $vIvrMenu4->ivr_menu_parent_uuid = null;
                $vIvrMenu4->ivr_menu_language = "";
                $vIvrMenu4->ivr_menu_dialect = "us";
                $vIvrMenu4->ivr_menu_voice = "callie";
                $vIvrMenu4->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                $vIvrMenu4->ivr_menu_exit_sound = "";
                $vIvrMenu4->ivr_menu_pin_number = "";
                $vIvrMenu4->ivr_menu_confirm_macro = "";
                $vIvrMenu4->ivr_menu_confirm_key = "";
                $vIvrMenu4->ivr_menu_tts_engine = "flite";
                $vIvrMenu4->ivr_menu_tts_voice = "rms";
                $vIvrMenu4->ivr_menu_confirm_attempts = 1;
                $vIvrMenu4->ivr_menu_exit_app = null;
                $vIvrMenu4->ivr_menu_exit_data = null;
                $vIvrMenu4->ivr_menu_inter_digit_timeout = 2000;
                $vIvrMenu4->ivr_menu_max_failures = 1;
                $vIvrMenu4->ivr_menu_max_timeouts = 1;
                $vIvrMenu4->ivr_menu_digit_len = 5;
                $vIvrMenu4->ivr_menu_direct_dial = "false";
                $vIvrMenu4->ivr_menu_ringback = "local_stream://default";
                $vIvrMenu4->ivr_menu_cid_prefix = "";
                $vIvrMenu4->ivr_menu_context = $fqdn;
                $vIvrMenu4->ivr_menu_enabled = "true";
                $vIvrMenu4->ivr_menu_description = "";
                $vIvrMenu4->insert_date = date("Y-m-d H:i:s");
                if (!$vIvrMenu4->create()) { 
                    $this->db->rollback();
                    $messages = $vIvrMenu4->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1004)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vDialplan4 = new VDialplans();
                $vDialplan4->domain_uuid = $domain_uuid;
                $vDialplan4->dialplan_uuid = $dialplan_uuid4;
                $vDialplan4->app_uuid = $security->uuid();
                $vDialplan4->dialplan_name = "Rating 3";
                $vDialplan4->dialplan_number = "3003";
                $vDialplan4->dialplan_order = 81;
                $vDialplan4->dialplan_context = $fqdn;
                $vDialplan4->dialplan_continue = "false";
                $vDialplan4->dialplan_xml =
                    '<extension name="Rating 3" continue="false" uuid="' .
                    $dialplan_uuid4 .
                    '">
                                    <condition field="destination_number" expression="^3003$">
                                        <action application="ring_ready" data=""/>
                                        <action application="answer" data=""/>
                                        <action application="sleep" data="1000"/>
                                        <action application="set" data="hangup_after_bridge=true"/>
                                        <action application="set" data="ringback=local_stream://default"/>
                                        <action application="set" data="transfer_ringback=local_stream://default"/>
                                        <action application="set" data="ivr_menu_uuid=' .
                    $ivr_menu_uuid4 .
                    '"/>
                                        <action application="ivr" data="' .
                    $ivr_menu_uuid4 .
                    '"/>
                                        <action application="hangup" data=""/>
                                    </condition>
                    </extension>';
                $vDialplan4->dialplan_enabled = "true";
                $vDialplan4->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan4->create()) { 
                    $this->db->rollback();
                    $messages = $vDialplan4->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vIvrMenu5 = new VIvrMenus();
                $ivr_menu_uuid5 = $security->uuid();
                $dialplan_uuid5 = $security->uuid();
                $vIvrMenu5->ivr_menu_uuid = $ivr_menu_uuid5;
                $vIvrMenu5->domain_uuid = $domain_uuid;
                $vIvrMenu5->dialplan_uuid = $dialplan_uuid5;
                $vIvrMenu5->ivr_menu_extension = "3004";
                $vIvrMenu5->ivr_menu_name = "Rating 4";
                $vIvrMenu5->ivr_menu_timeout = 2000;
                $vIvrMenu5->ivr_menu_greet_long = "you_have-chosen-four.wav";
                $vIvrMenu5->ivr_menu_parent_uuid = null;
                $vIvrMenu5->ivr_menu_language = "";
                $vIvrMenu5->ivr_menu_dialect = "us";
                $vIvrMenu5->ivr_menu_voice = "callie";
                $vIvrMenu5->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                $vIvrMenu5->ivr_menu_exit_sound = "";
                $vIvrMenu5->ivr_menu_pin_number = "";
                $vIvrMenu5->ivr_menu_confirm_macro = "";
                $vIvrMenu5->ivr_menu_confirm_key = "";
                $vIvrMenu5->ivr_menu_tts_engine = "flite";
                $vIvrMenu5->ivr_menu_tts_voice = "rms";
                $vIvrMenu5->ivr_menu_confirm_attempts = 1;
                $vIvrMenu5->ivr_menu_exit_app = null;
                $vIvrMenu5->ivr_menu_exit_data = null;
                $vIvrMenu5->ivr_menu_inter_digit_timeout = 2000;
                $vIvrMenu5->ivr_menu_max_failures = 1;
                $vIvrMenu5->ivr_menu_max_timeouts = 1;
                $vIvrMenu5->ivr_menu_digit_len = 5;
                $vIvrMenu5->ivr_menu_direct_dial = "false";
                $vIvrMenu5->ivr_menu_ringback = "local_stream://default";
                $vIvrMenu5->ivr_menu_cid_prefix = "";
                $vIvrMenu5->ivr_menu_context = $fqdn;
                $vIvrMenu5->ivr_menu_enabled = "true";
                $vIvrMenu5->ivr_menu_description = "";
                $vIvrMenu5->insert_date = date("Y-m-d H:i:s");
                if (!$vIvrMenu5->create()) { 
                    $this->db->rollback();
                    $messages = $vIvrMenu5->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1004)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                } 
                $vDialplan5 = new VDialplans();
                $vDialplan5->domain_uuid = $domain_uuid;
                $vDialplan5->dialplan_uuid = $dialplan_uuid5;
                $vDialplan5->app_uuid = $security->uuid();
                $vDialplan5->dialplan_name = "Rating 4";
                $vDialplan5->dialplan_number = "3004";
                $vDialplan5->dialplan_order = 81;
                $vDialplan5->dialplan_context = $fqdn;
                $vDialplan5->dialplan_continue = "false";
                $vDialplan5->dialplan_xml =
                    '<extension name="Rating 4" continue="false" uuid="' .
                    $dialplan_uuid5 .
                    '">
                                    <condition field="destination_number" expression="^3004$">
                                        <action application="ring_ready" data=""/>
                                        <action application="answer" data=""/>
                                        <action application="sleep" data="1000"/>
                                        <action application="set" data="hangup_after_bridge=true"/>
                                        <action application="set" data="ringback=local_stream://default"/>
                                        <action application="set" data="transfer_ringback=local_stream://default"/>
                                        <action application="set" data="ivr_menu_uuid=' .
                    $ivr_menu_uuid5 .
                    '"/>
                                        <action application="ivr" data="' .
                    $ivr_menu_uuid5 .
                    '"/>
                                        <action application="hangup" data=""/>
                                    </condition>
                    </extension>';
                $vDialplan5->dialplan_enabled = "true";
                $vDialplan5->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan5->create()) { 
                    $this->db->rollback();
                    $messages = $vDialplan5->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                } 
                $vIvrMenu6 = new VIvrMenus();
                $ivr_menu_uuid6 = $security->uuid();
                $dialplan_uuid6 = $security->uuid();
                $vIvrMenu6->ivr_menu_uuid = $ivr_menu_uuid6;
                $vIvrMenu6->domain_uuid = $domain_uuid;
                $vIvrMenu6->dialplan_uuid = $dialplan_uuid6;
                $vIvrMenu6->ivr_menu_extension = "3005";
                $vIvrMenu6->ivr_menu_name = "Rating 5";
                $vIvrMenu6->ivr_menu_timeout = 2000;
                $vIvrMenu6->ivr_menu_greet_long = "you_have-chosen-five.wav";
                $vIvrMenu6->ivr_menu_parent_uuid = null;
                $vIvrMenu6->ivr_menu_language = "";
                $vIvrMenu6->ivr_menu_dialect = "us";
                $vIvrMenu6->ivr_menu_voice = "callie";
                $vIvrMenu6->ivr_menu_invalid_sound = "ivr/ivr-that_was_an_invalid_entry.wav";
                $vIvrMenu6->ivr_menu_exit_sound = "";
                $vIvrMenu6->ivr_menu_pin_number = "";
                $vIvrMenu6->ivr_menu_confirm_macro = "";
                $vIvrMenu6->ivr_menu_confirm_key = "";
                $vIvrMenu6->ivr_menu_tts_engine = "flite";
                $vIvrMenu6->ivr_menu_tts_voice = "rms";
                $vIvrMenu6->ivr_menu_confirm_attempts = 1;
                $vIvrMenu6->ivr_menu_exit_app = null;
                $vIvrMenu6->ivr_menu_exit_data = null;
                $vIvrMenu6->ivr_menu_inter_digit_timeout = 2000;
                $vIvrMenu6->ivr_menu_max_failures = 1;
                $vIvrMenu6->ivr_menu_max_timeouts = 1;
                $vIvrMenu6->ivr_menu_digit_len = 5;
                $vIvrMenu6->ivr_menu_direct_dial = "false";
                $vIvrMenu6->ivr_menu_ringback = "local_stream://default";
                $vIvrMenu6->ivr_menu_cid_prefix = "";
                $vIvrMenu6->ivr_menu_context = $fqdn;
                $vIvrMenu6->ivr_menu_enabled = "true";
                $vIvrMenu6->ivr_menu_description = "";
                $vIvrMenu6->insert_date = date("Y-m-d H:i:s");
                if (!$vIvrMenu6->create()) { 
                    $this->db->rollback();
                    $messages = $vIvrMenu6->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1004)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vDialplan6 = new VDialplans();
                $vDialplan6->domain_uuid = $domain_uuid;
                $vDialplan6->dialplan_uuid = $dialplan_uuid6;
                $vDialplan6->app_uuid = $security->uuid();
                $vDialplan6->dialplan_name = "Rating 5";
                $vDialplan6->dialplan_number = "3005";
                $vDialplan6->dialplan_order = 81;
                $vDialplan6->dialplan_context = $fqdn;
                $vDialplan6->dialplan_continue = "false";
                $vDialplan6->dialplan_xml =
                    '<extension name="Rating 5" continue="false" uuid="' .
                    $dialplan_uuid6 .
                    '">
                                    <condition field="destination_number" expression="^3005$">
                                        <action application="ring_ready" data=""/>
                                        <action application="answer" data=""/>
                                        <action application="sleep" data="1000"/>
                                        <action application="set" data="hangup_after_bridge=true"/>
                                        <action application="set" data="ringback=local_stream://default"/>
                                        <action application="set" data="transfer_ringback=local_stream://default"/>
                                        <action application="set" data="ivr_menu_uuid=' .
                    $ivr_menu_uuid6 .
                    '"/>
                                        <action application="ivr" data="' .
                    $ivr_menu_uuid6 .
                    '"/>
                                        <action application="hangup" data=""/>
                                    </condition>
                    </extension>';
                $vDialplan6->dialplan_enabled = "true";
                $vDialplan6->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan6->create()) {
                    $this->db->rollback();
                    $messages = $vDialplan6->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $dialplan_uuid7 = $security->uuid();
                $vDialplan7 = new VDialplans();
                $vDialplan7->domain_uuid = $domain_uuid;
                $vDialplan7->dialplan_uuid = $dialplan_uuid7;
                $vDialplan7->app_uuid = $security->uuid();
                $vDialplan7->dialplan_name = "AI External";
                $vDialplan7->dialplan_number = "7777";
                $vDialplan7->dialplan_order = 81;
                $vDialplan7->dialplan_context = $fqdn;
                $vDialplan7->dialplan_continue = "false";
                $vDialplan7->dialplan_xml =
                    '<extension name="AI External" continue="false" uuid="' .
                    $dialplan_uuid7 .
                    '">
                        <condition field="destination_number" expression="^7777$">
                            <action application="answer" data=""/>
                            <action application="set" data="STREAM_BUFFER_SIZE=40"/>
                            <action application="set" data="record_sample_rate=16000"/>
                            <action application="set" data="RECORD_STEREO=false"/>
                            <action application="sleep" data="1000"/>
                            <action application="lua" data="ivr_ai2.lua ${uuid} ${domain_name}"/>
                            <action application="sleep" data="180000"/>
                        </condition>
                    </extension>';
                $vDialplan7->dialplan_enabled = "true";
                $vDialplan7->insert_date = date("Y-m-d H:i:s");
                if (!$vDialplan7->create()) {
                    $this->db->rollback();
                    $messages = $vDialplan7->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code IV1005)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qAuxcodes = new QAuxcodes();
                $qAuxcodes->auxcode_uuid = $auxcode_uuid = $security->uuid();
                $qAuxcodes->domain_uuid = $domain_uuid;
                $qAuxcodes->username = $username;
                $qAuxcodes->extension = $extension;
                $qAuxcodes->user_uuid = $user_uuid;
                $qAuxcodes->auxcode_status = "available";
                $qAuxcodes->insert_date = date("Y-m-d H:i:s");
                if (!$qAuxcodes->create()) {
                    $this->db->rollback();
                    $messages = $qAuxcodes->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AA1002)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qAuxcodes2 = new QAuxcodes();
                $qAuxcodes2->auxcode_uuid = $security->uuid();
                $qAuxcodes2->domain_uuid = $domain_uuid;
                $qAuxcodes2->username = $username;
                $qAuxcodes2->extension = $extension;
                $qAuxcodes2->user_uuid = $user_uuid;
                $qAuxcodes2->auxcode_status = "auxcode 1";
                $qAuxcodes2->insert_date = date("Y-m-d H:i:s");
                if (!$qAuxcodes2->create()) {
                    $this->db->rollback();
                    $messages = $qAuxcodes2->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AA1002)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qAuxcodes3 = new QAuxcodes();
                $qAuxcodes3->auxcode_uuid = $security->uuid();
                $qAuxcodes3->domain_uuid = $domain_uuid;
                $qAuxcodes3->username = $username;
                $qAuxcodes3->extension = $extension;
                $qAuxcodes3->user_uuid = $user_uuid;
                $qAuxcodes3->auxcode_status = "auxcode 2";
                $qAuxcodes3->insert_date = date("Y-m-d H:i:s");
                if (!$qAuxcodes3->create()) {
                    $this->db->rollback();
                    $messages = $qAuxcodes3->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AA1002)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qWrapupnote = new QWrapupnotes();
                $qWrapupnote->wrapup_uuid = $security->uuid();
                $qWrapupnote->domain_uuid = $domain_uuid;
                $qWrapupnote->username = $username;
                $qWrapupnote->extension = $extension;
                $qWrapupnote->user_uuid = $user_uuid;
                $qWrapupnote->wrapup_note = "General";
                $qWrapupnote->insert_date = date("Y-m-d H:i:s");
                if (!$qWrapupnote->create()) {
                    $messages = $qWrapupnote->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AW1002)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vCallCenterAgent = new VCallCenterAgents();
                $vCallCenterAgent->call_center_agent_uuid = $security->uuid();
                $vCallCenterAgent->domain_uuid = $domain_uuid;
                $vCallCenterAgent->user_uuid = $user_uuid;
                $vCallCenterAgent->agent_name = $firstname . " " . $lastname;
                $vCallCenterAgent->agent_type = "callback";
                $vCallCenterAgent->agent_call_timeout = 30;
                $vCallCenterAgent->agent_id = $extension;
                $vCallCenterAgent->agent_contact = "user/" . $extension . "@" . $fqdn;
                $vCallCenterAgent->agent_status = "Available";
                $vCallCenterAgent->agent_max_no_answer = 120;
                if (!$vCallCenterAgent->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1010)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qSecurityRule = new QSecurityRules();
                $qSecurityRule->domain_uuid = $domain_uuid;
                $qSecurityRule->security_uuid = $security->uuid();
                if (!$qSecurityRule->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1011)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qSecurityRestrictions = new QSecurityRestrictions();
                $qSecurityRestrictions->domain_uuid = $domain_uuid;
                $qSecurityRestrictions->security_uuid = $security->uuid();
                if (!$qSecurityRestrictions->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1011)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qTimeSchedule = new QTimeSchedules();
                $qTimeSchedule->domain_uuid = $domain_uuid;
                $qTimeSchedule->update_user = $user_uuid;
                $qTimeSchedule->update_date = date("Y-m-d H:i:s");
                $qTimeSchedule->insert_user = $user_uuid;
                $qTimeSchedule->insert_date = date("Y-m-d H:i:s");
                if (!$qTimeSchedule->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1011)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qCallBehavior = new QCallBehaviors();
                $qCallBehavior->extension_uuid = $extension_uuid;
                $qCallBehavior->domain_uuid = $domain_uuid;
                $qCallBehavior->extension = $extension;
                $qCallBehavior->forward_no_answer_destination = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior->forward_no_answer_destination2 = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior->forward_no_answer_timeout = "30,30,30,30,30";
                $qCallBehavior->forward_no_answer_timeout2 = "30,30,30,30,30";
                $qCallBehavior->forward_no_answer_enabled = "true,true,true,true,true";
                $qCallBehavior->forward_no_answer_enabled2 = "true,true,true,true,true";
                $qCallBehavior->type = 1;
                $qCallBehavior->enabled = 1;
                $qCallBehavior->sso_qr = 1;
                $qCallBehavior->sso_number = 1;
                $qCallBehavior->insert_user = $user_uuid;
                $qCallBehavior->insert_date = date("Y-m-d H:i:s");
                if (!$qCallBehavior->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1012)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qCompanyMode = new QCompanyModes();
                $qCompanyMode->domain_uuid = $domain_uuid;
                $qCompanyMode->mode1 = "1000";
                $qCompanyMode->permission1 = "1000";
                if (!$qCompanyMode->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1012)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vPinNumber = new VPinNumbers();
                $vPinNumber->domain_uuid = $domain_uuid;
                $vPinNumber->pin_number_uuid = $security->uuid();
                $vPinNumber->pin_number = rand(1000, 9999);
                $vPinNumber->enabled = "true";
                $vPinNumber->description = $extension;
                $vPinNumber->insert_user = $user_uuid;
                $vPinNumber->insert_date = date("Y-m-d H:i:s");
                if (!$vPinNumber->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1013)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                // $vDialplan = new VDialplans();
                // $vDialplan->domain_uuid = $domain_uuid;
                // $vDialplan->dialplan_uuid
                // $vDialplan->app_uuid
                // $vDialplan->dialplan_context = $domain_uuid;
                $userAgent = 'Mozilla Firefox';
                $access_token = md5($company_email . 'Mozilla Firefox');
                $qToken = new QTokens();
                $qToken->user_uuid = $user_uuid;
                $qToken->domain_uuid = $domain_uuid;
                $qToken->access_token = $access_token;
                $qToken->insert_date = date("Y-m-d H:i:s");
                $qToken->user_agent = $userAgent;
                $qToken->type = 1; // web, android or ios
                if (!$qToken->save()) {
                    $this->db->rollback();
                    $response = json_encode([ 
                        "result" => "error",
                        "message" => "An error has occurred. (Code RV1014)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }

                $this->redis->set("security:" . $fqdn . "-ssoNumber", "1");
                $this->redis->set("security:" . $fqdn . "-ssoQR", "1");
                $this->redis->set($extension . "-". $fqdn . "-auxcode", $auxcode_uuid);
                $this->redis->set($extension . "-". $fqdn . "-chameleon", "1");
                $this->redis->set($extension . "-". $fqdn . "-permission", "7"); 

                $company_email = "example@ringq.com";
                $firstname = "Partner";
                $lastname = "Account"; 
                $extension = 9997;
                $username = $security->hex(10);
                $user_uuid = $security->uuid();
                $salt = $this->security->hash($security->base64(16));
                $password = $this->security->hash($security->base64(16));
                $password2 = $security->base64(16);
                $extension_password = $password2; // md5($salt . $password2);
                $qUser7 = new QUsers();
                $qUser7->user_uuid = $user_uuid;
                $qUser7->domain_uuid = $domain_uuid;
                $qUser7->first_name = $firstname;
                $qUser7->last_name = $lastname;
                $qUser7->extension_enabled = true;
                $qUser7->insert_date = date("Y-m-d H:i:s");
                $qUser7->user_email = $company_email;
                $qUser7->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                if (!$qUser7->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1001)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vUser7 = new VUsers();
                $vUser7->username = $username;
                $vUser7->user_uuid = $user_uuid;
                $vUser7->domain_uuid = $domain_uuid;
                $vUser7->user_email = $company_email;
                $vUser7->password = $password;
                $vUser7->user_status = "Logged Out";
                $vUser7->user_enabled = "true";
                $vUser7->api_key = $security->base64(32);
                $vUser7->image = null;
                $vUser7->insert_user = $user_uuid;
                $vUser7->insert_date = date("Y-m-d H:i:s");
                if (!$vUser7->save()) {
                    $this->db->rollback();
                    $messages = $vUser7->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AS1008)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtension7 = new VExtensions();
                $vExtension7->extension_uuid = $extension_uuid = $security->uuid();
                $vExtension7->domain_uuid = $domain_uuid;
                $vExtension7->extension = $extension;
                $vExtension7->number_alias = $username;
                $vExtension7->password = $extension_password;
                $vExtension7->enabled = "true";
                $vExtension7->extension_type = "default";
                $vExtension7->accountcode = $fqdn;
                $vExtension7->directory_visible = "true";
                $vExtension7->directory_exten_visible = "true";
                $vExtension7->limit_max = "5";
                $vExtension7->limit_destination = "!USER_BUSY";
                $vExtension7->effective_caller_id_name = $firstname. ' ' .$lastname;
                $vExtension7->effective_caller_id_number = $extension;
                $vExtension7->do_not_disturb = "false";
                $vExtension7->forward_all_enabled = "false";
                $vExtension7->forward_busy_destination = "*99" . $extension;
                $vExtension7->forward_all_enabled2 = "false";
                $vExtension7->forward_busy_enabled = "false";
                $vExtension7->forward_busy_enabled2 = "false";
                $vExtension7->forward_no_answer_enabled = "true";
                $vExtension7->forward_no_answer_enabled2 = "true";
                $vExtension7->forward_user_not_registered_enabled = "false";
                $vExtension7->forward_user_not_registered_enabled2 = "false";
                $vExtension7->forward_no_answer_destination = "*99" . $extension;
                $vExtension7->forward_no_answer_destination2 = "*99" . $extension;
                $vExtension7->voicemail_enabled = "enabled";
                $vExtension7->voicemail_file = "attach";
                $vExtension7->call_screen_enabled = "false";
                $vExtension7->user_context = $fqdn;
                $vExtension7->call_timeout = 30;
                $vExtension7->call_timeout2 = 30;
                $vExtension7->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                $vExtension7->insert_user = $user_uuid;
                $vExtension7->insert_date = date("Y-m-d H:i:s");
                if (!$vExtension7->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1003)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtensionUser7 = new VExtensionUsers();
                $vExtensionUser7->extension_user_uuid = $security->uuid();
                $vExtensionUser7->domain_uuid = $domain_uuid;
                $vExtensionUser7->user_uuid = $user_uuid;
                $vExtensionUser7->extension_uuid = $extension_uuid;
                if (!$vExtensionUser7->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1004)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vPinNumber7 = new VPinNumbers();
                $vPinNumber7->domain_uuid = $domain_uuid;
                $vPinNumber7->pin_number_uuid = $security->uuid();
                $vPinNumber7->pin_number = strval(rand(1000, 9999));
                $vPinNumber7->accountcode = $vExtension->accountcode;
                $vPinNumber7->enabled = "true";
                $vPinNumber7->description = strval($extension);
                $vPinNumber7->insert_user = $user_uuid;
                $vPinNumber7->insert_date = date("Y-m-d H:i:s");
                if (!$vPinNumber7->save()) {
                    $this->db->rollback();
                    $messages = $vPinNumber7->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AS1008)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qCallBehavior7 = new QCallBehaviors();
                $qCallBehavior7->domain_uuid = $domain_uuid;
                $qCallBehavior7->extension_uuid = $extension_uuid;
                $qCallBehavior7->extension = $extension;
                $qCallBehavior7->forward_no_answer_destination = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior7->forward_no_answer_destination2 = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior7->forward_no_answer_timeout = "30,30,30,30,30";
                $qCallBehavior7->forward_no_answer_timeout2 = "30,30,30,30,30";
                $qCallBehavior7->forward_no_answer_enabled = "true,true,true,true,true";
                $qCallBehavior7->forward_no_answer_enabled2 = "true,true,true,true,true";
                $qCallBehavior7->type = 1;
                $qCallBehavior7->enabled = 1;
                $qCallBehavior7->insert_user = $user_uuid;
                $qCallBehavior7->insert_date = date("Y-m-d H:i:s");

                $this->redis->set($extension ."@". $vExtension7->accountcode ."-click2talk-mode", "");
                $this->redis->set($extension . "+" . $vExtension7->accountcode, "0");
                $this->redis->set($extension  . "-". $vExtension7->accountcode . "-chameleon", "1");
                $this->redis->set($extension  . "-". $vExtension7->accountcode . "-permission", "7"); 

                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-all-destination", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-all-destination2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-busy-destination", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-busy-destination2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-destination", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-destination2", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-follow-me-destinations", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-follow-me-destinations2", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-timeout", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-timeout2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-busy-enabled2",  ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-enabled",  ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension7->accountcode . "-callScreening2",  ["false", "false", "false", "false", "false"]);
                $qCallBehavior7->save(); 

                $company_email = "l1support@ringq.com";
                $lastname = "Support";
                $firstname = "RingQ L1";
                $extension = 9998;
                $username = $security->hex(10);
                $user_uuid = $security->uuid();
                $salt = $this->security->hash($security->base64(16));
                $password = $this->security->hash($security->base64(16));
                $password2 = $security->base64(16);
                $extension_password = $password2; // md5($salt . $password2);
                $qUser8 = new QUsers();
                $qUser8->user_uuid = $user_uuid;
                $qUser8->domain_uuid = $domain_uuid;
                $qUser8->first_name = $firstname;
                $qUser8->last_name = $lastname;
                $qUser8->extension_enabled = true;
                $qUser8->insert_date = date("Y-m-d H:i:s");
                $qUser8->user_email = $company_email;
                $qUser8->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                if (!$qUser8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1001)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vUser8 = new VUsers();
                $vUser8->username = $username;
                $vUser8->user_uuid = $user_uuid;
                $vUser8->domain_uuid = $domain_uuid;
                $vUser8->user_email = $company_email;
                $vUser8->password = $password;
                $vUser8->user_status = "Logged Out";
                $vUser8->user_enabled = "true";
                $vUser8->api_key = $security->base64(32);
                $vUser8->image = null;
                if (!$vUser8->save()) {
                    $this->db->rollback();
                    $messages = $vUser8->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AE1002)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtension8 = new VExtensions();
                $vExtension8->extension_uuid = $extension_uuid = $security->uuid();
                $vExtension8->domain_uuid = $domain_uuid;
                $vExtension8->extension = $extension;
                $vExtension8->number_alias = $username;
                $vExtension8->password = $extension_password;
                $vExtension8->enabled = "true";
                $vExtension8->extension_type = "default";
                $vExtension8->accountcode = $fqdn;
                $vExtension8->directory_visible = "true";
                $vExtension8->directory_exten_visible = "true";
                $vExtension8->limit_max = "5"; // <!-- Should be based on purchased package
                $vExtension8->limit_destination = "!USER_BUSY";
                $vExtension8->effective_caller_id_name = $firstname. ' ' .$lastname;
                $vExtension8->effective_caller_id_number = $extension;
                $vExtension8->do_not_disturb = "false";
                $vExtension8->forward_all_enabled = "false";
                $vExtension8->forward_all_enabled2 = "false";
                $vExtension8->forward_busy_enabled = "false";
                $vExtension8->forward_busy_enabled2 = "false";
                $vExtension8->forward_no_answer_enabled = "true";
                $vExtension8->forward_no_answer_enabled2 = "true";
                $vExtension8->forward_user_not_registered_enabled = "false";
                $vExtension8->forward_user_not_registered_enabled2 = "false";
                $vExtension8->forward_no_answer_destination = "*99" . $extension;
                $vExtension8->forward_no_answer_destination2 = "*99" . $extension;
                $vExtension8->voicemail_enabled = "enabled";
                $vExtension8->voicemail_file = "attach";
                $vExtension8->call_screen_enabled = "false";
                $vExtension8->user_context = $fqdn;
                $vExtension8->call_timeout = 30;
                $vExtension8->call_timeout2 = 30;
                $vExtension8->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                $vExtension8->insert_user = $user_uuid;
                $vExtension8->insert_date = date("Y-m-d H:i:s");
                if (!$vExtension8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1003)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtensionUser8 = new VExtensionUsers();
                $vExtensionUser8->extension_user_uuid = $security->uuid();
                $vExtensionUser8->domain_uuid = $domain_uuid;
                $vExtensionUser8->user_uuid = $user_uuid;
                $vExtensionUser8->extension_uuid = $extension_uuid;
                if (!$vExtensionUser8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1004)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vPinNumber8 = new VPinNumbers();
                $vPinNumber8->domain_uuid = $domain_uuid;
                $vPinNumber8->pin_number_uuid = $security->uuid();
                $vPinNumber8->pin_number = strval(rand(1000, 9999));
                $vPinNumber8->accountcode = $vExtension->accountcode;
                $vPinNumber8->enabled = "true";
                $vPinNumber8->description = strval($extension);
                $vPinNumber8->insert_user = $user_uuid;
                $vPinNumber8->insert_date = date("Y-m-d H:i:s");
                if (!$vPinNumber8->save()) {
                    $this->db->rollback();
                    $messages = $vPinNumber8->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AS1008)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qCallBehavior8 = new QCallBehaviors();
                $qCallBehavior8->domain_uuid = $domain_uuid;
                $qCallBehavior8->extension_uuid = $extension_uuid;
                $qCallBehavior8->extension = $extension;
                $qCallBehavior8->forward_no_answer_destination = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior8->forward_no_answer_destination2 = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior8->forward_no_answer_timeout = "30,30,30,30,30";
                $qCallBehavior8->forward_no_answer_timeout2 = "30,30,30,30,30";
                $qCallBehavior8->forward_no_answer_enabled = "true,true,true,true,true";
                $qCallBehavior8->forward_no_answer_enabled2 = "true,true,true,true,true";
                $qCallBehavior8->type = 1;
                $qCallBehavior8->enabled = 1;
                $qCallBehavior8->insert_user = $user_uuid;
                $qCallBehavior8->insert_date = date("Y-m-d H:i:s");

                $this->redis->set($extension ."@". $vExtension8->accountcode ."-click2talk-mode", "");
                $this->redis->set($extension . "+" . $vExtension8->accountcode, "0");
                $this->redis->set($extension  . "-". $vExtension8->accountcode . "-chameleon", "1");
                $this->redis->set($extension  . "-". $vExtension8->accountcode . "-permission", "7");
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-destination", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-destination2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-destination", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-destination2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-destination", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-destination2", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-follow-me-destinations", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-follow-me-destinations2", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-enabled2",  ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-enabled",  ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-callScreening2",  ["false", "false", "false", "false", "false"]);
                $qCallBehavior8->save();

                $company_email = "l2support@ringq.com";
                $lastname = "Support";
                $firstname = "RingQ L2";
                $extension = 9999;
                $username = $security->hex(10);
                $user_uuid = $security->uuid();
                $salt = $this->security->hash($security->base64(16));
                $password = $this->security->hash($security->base64(16));
                $password2 = $security->base64(16);
                $extension_password = $password2; // md5($salt . $password2);
                $qUser8 = new QUsers();
                $qUser8->user_uuid = $user_uuid;
                $qUser8->domain_uuid = $domain_uuid;
                $qUser8->first_name = $firstname;
                $qUser8->last_name = $lastname;
                $qUser8->extension_enabled = true;
                $qUser8->insert_date = date("Y-m-d H:i:s");
                $qUser8->user_email = $company_email;
                $qUser8->password = password_hash((string) $password2, PASSWORD_DEFAULT);
                if (!$qUser8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1001)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vUser8 = new VUsers();
                $vUser8->username = $username;
                $vUser8->user_uuid = $user_uuid;
                $vUser8->domain_uuid = $domain_uuid;
                $vUser8->user_email = $company_email;
                $vUser8->password = $password;
                $vUser8->user_status = "Logged Out";
                $vUser8->user_enabled = "true";
                $vUser8->api_key = $security->base64(32);
                $vUser8->image = null;
                if (!$vUser8->save()) {
                    $this->db->rollback();
                    $messages = $vUser8->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AE1002)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtension8 = new VExtensions();
                $vExtension8->extension_uuid = $extension_uuid = $security->uuid();
                $vExtension8->domain_uuid = $domain_uuid;
                $vExtension8->extension = $extension;
                $vExtension8->number_alias = $username;
                $vExtension8->password = $extension_password;
                $vExtension8->enabled = "true";
                $vExtension8->extension_type = "default";
                $vExtension8->accountcode = $fqdn;
                $vExtension8->directory_visible = "true";
                $vExtension8->directory_exten_visible = "true";
                $vExtension8->limit_max = "5"; // <!-- Should be based on purchased package
                $vExtension8->limit_destination = "!USER_BUSY";
                $vExtension8->effective_caller_id_name = $firstname. ' ' .$lastname;
                $vExtension8->effective_caller_id_number = $extension;
                $vExtension8->do_not_disturb = "false";
                $vExtension8->forward_all_enabled = "false";
                $vExtension8->forward_all_enabled2 = "false";
                $vExtension8->forward_busy_enabled = "false";
                $vExtension8->forward_busy_enabled2 = "false";
                $vExtension8->forward_no_answer_enabled = "true";
                $vExtension8->forward_no_answer_enabled2 = "true";
                $vExtension8->forward_user_not_registered_enabled = "false";
                $vExtension8->forward_user_not_registered_enabled2 = "false";
                $vExtension8->forward_no_answer_destination = "*99" . $extension;
                $vExtension8->forward_no_answer_destination2 = "*99" . $extension;
                $vExtension8->voicemail_enabled = "enabled";
                $vExtension8->voicemail_file = "attach";
                $vExtension8->call_screen_enabled = "false";
                $vExtension8->user_context = $fqdn;
                $vExtension8->call_timeout = 30;
                $vExtension8->call_timeout2 = 30;
                $vExtension8->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                $vExtension8->insert_user = $user_uuid;
                $vExtension8->insert_date = date("Y-m-d H:i:s");
                if (!$vExtension8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1003)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtensionUser8 = new VExtensionUsers();
                $vExtensionUser8->extension_user_uuid = $security->uuid();
                $vExtensionUser8->domain_uuid = $domain_uuid;
                $vExtensionUser8->user_uuid = $user_uuid;
                $vExtensionUser8->extension_uuid = $extension_uuid;
                if (!$vExtensionUser8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1004)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vPinNumber8 = new VPinNumbers();
                $vPinNumber8->domain_uuid = $domain_uuid;
                $vPinNumber8->pin_number_uuid = $security->uuid();
                $vPinNumber8->pin_number = strval(rand(1000, 9999));
                $vPinNumber8->accountcode = $vExtension->accountcode;
                $vPinNumber8->enabled = "true";
                $vPinNumber8->description = strval($extension);
                $vPinNumber8->insert_user = $user_uuid;
                $vPinNumber8->insert_date = date("Y-m-d H:i:s");
                if (!$vPinNumber8->save()) {
                    $this->db->rollback();
                    $messages = $vPinNumber8->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AS1008)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qCallBehavior8 = new QCallBehaviors();
                $qCallBehavior8->domain_uuid = $domain_uuid;
                $qCallBehavior8->extension_uuid = $extension_uuid;
                $qCallBehavior8->extension = $extension;
                $qCallBehavior8->forward_no_answer_destination = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior8->forward_no_answer_destination2 = "*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .","."*99" . $extension .",";
                $qCallBehavior8->forward_no_answer_timeout = "30,30,30,30,30";
                $qCallBehavior8->forward_no_answer_timeout2 = "30,30,30,30,30";
                $qCallBehavior8->forward_no_answer_enabled = "true,true,true,true,true";
                $qCallBehavior8->forward_no_answer_enabled2 = "true,true,true,true,true";
                $qCallBehavior8->type = 1;
                $qCallBehavior8->enabled = 1;
                $qCallBehavior8->insert_user = $user_uuid;
                $qCallBehavior8->insert_date = date("Y-m-d H:i:s");

                $this->redis->set($extension ."@". $vExtension8->accountcode ."-click2talk-mode", "");
                $this->redis->set($extension . "+" . $vExtension8->accountcode, "0");
                $this->redis->set($extension  . "-". $vExtension8->accountcode . "-chameleon", "1");
                $this->redis->set($extension  . "-". $vExtension8->accountcode . "-permission", "7");
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-destination", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-destination2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-destination", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-destination2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-destination", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-destination2", ["*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension, "*99" . $extension]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-follow-me-destinations", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-follow-me-destinations2", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout2", ["","","","",""]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-busy-enabled2",  ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-enabled",  ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($extension . "@" . $vExtension8->accountcode . "-callScreening2",  ["false", "false", "false", "false", "false"]);
                $qCallBehavior8->save(); 

                $extension_uuid = $security->uuid();
                $username = $security->hex(10);
                $number_alias = 100000;
                $user_uuid = $security->uuid();
                $salt = $this->security->hash($security->base64(16));
                $password = $this->security->hash($security->base64(16));
                $password2 = $security->base64(16);
                $extension_password = $password2;
                $vUser8 = new VUsers();
                $vUser8->username = $username;
                $vUser8->user_uuid = $user_uuid;
                $vUser8->domain_uuid = $domain_uuid; 
                $vUser8->password = $password;
                $vUser8->user_status = "Logged Out";
                $vUser8->user_enabled = "true";
                $vUser8->api_key = $security->base64(32);
                $vUser8->image = null;
                if (!$vUser8->save()) {
                    $this->db->rollback();
                    $messages = $vUser8->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AE1002)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtension8 = new VExtensions();
                $vExtension8->extension_uuid = $extension_uuid = $security->uuid();
                $vExtension8->domain_uuid = $domain_uuid;
                $vExtension8->extension = $username;
                $vExtension8->number_alias = $number_alias;
                $vExtension8->password = $extension_password;
                $vExtension8->enabled = "true";
                $vExtension8->extension_type = "default";
                $vExtension8->accountcode = $fqdn;
                $vExtension8->directory_visible = "true";
                $vExtension8->directory_exten_visible = "true";
                $vExtension8->limit_max = "10"; // <!-- Should be based on purchased package
                $vExtension8->limit_destination = "!USER_BUSY";
                $vExtension8->effective_caller_id_name = strval($number_alias);
                $vExtension8->effective_caller_id_number = $number_alias;
                $vExtension8->do_not_disturb = "false";
                $vExtension8->forward_all_enabled = "false";
                $vExtension8->forward_all_enabled2 = "false";
                $vExtension8->forward_busy_enabled = "false";
                $vExtension8->forward_busy_enabled2 = "false";
                $vExtension8->forward_no_answer_enabled = "true";
                $vExtension8->forward_no_answer_enabled2 = "true";
                $vExtension8->forward_user_not_registered_enabled = "false";
                $vExtension8->forward_user_not_registered_enabled2 = "false";
                $vExtension8->forward_no_answer_destination = "*99" . $number_alias;
                $vExtension8->forward_no_answer_destination2 = "*99" . $number_alias;
                $vExtension8->voicemail_enabled = "enabled";
                $vExtension8->voicemail_file = "attach";
                $vExtension8->call_screen_enabled = "false";
                $vExtension8->user_context = $fqdn;
                $vExtension8->call_timeout = 30;
                $vExtension8->call_timeout2 = 30;
                $vExtension8->description = $username . " " . $firstname . " " . $lastname . " " . $company_email;
                $vExtension8->insert_user = $user_uuid;
                $vExtension8->insert_date = date("Y-m-d H:i:s");
                if (!$vExtension8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1003)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vExtensionUser8 = new VExtensionUsers();
                $vExtensionUser8->extension_user_uuid = $security->uuid();
                $vExtensionUser8->domain_uuid = $domain_uuid;
                $vExtensionUser8->user_uuid = $user_uuid;
                $vExtensionUser8->extension_uuid = $extension_uuid;
                if (!$vExtensionUser8->save()) {
                    $this->db->rollback();
                    $response = json_encode([
                        "result" => "error",
                        "message" => "An error has occurred. (Code AE1004)",
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $vPinNumber8 = new VPinNumbers();
                $vPinNumber8->domain_uuid = $domain_uuid;
                $vPinNumber8->pin_number_uuid = $security->uuid();
                $vPinNumber8->pin_number = strval(rand(1000, 9999));
                $vPinNumber8->accountcode = $vExtension->accountcode;
                $vPinNumber8->enabled = "true";
                $vPinNumber8->description = strval($number_alias);
                $vPinNumber8->insert_user = $user_uuid;
                $vPinNumber8->insert_date = date("Y-m-d H:i:s");
                if (!$vPinNumber8->save()) {
                    $this->db->rollback();
                    $messages = $vPinNumber8->getMessages();
                    $message = "";
                    foreach ($messages as $error) {
                        $message .= $error . " ";
                    }
                    $response = json_encode([
                        "result" => "error",
                        "message" => trim($message) . " (Code AS1008)",
                        "content" => [],
                    ]);
                    $this->response->setContent($response);
                    return $this->response;
                }
                $qCallBehavior8 = new QCallBehaviors();
                $qCallBehavior8->domain_uuid = $domain_uuid;
                $qCallBehavior8->extension_uuid = $extension_uuid;
                $qCallBehavior8->extension = $number_alias;
                $qCallBehavior8->forward_no_answer_destination = "*99" . $number_alias .","."*99" . $number_alias .","."*99" . $number_alias .","."*99" . $number_alias .","."*99" . $number_alias .",";
                $qCallBehavior8->forward_no_answer_destination2 = "*99" . $number_alias .","."*99" . $number_alias .","."*99" . $number_alias .","."*99" . $number_alias .","."*99" . $number_alias .",";
                $qCallBehavior8->forward_no_answer_timeout = "30,30,30,30,30";
                $qCallBehavior8->forward_no_answer_timeout2 = "30,30,30,30,30";
                $qCallBehavior8->forward_no_answer_enabled = "true,true,true,true,true";
                $qCallBehavior8->forward_no_answer_enabled2 = "true,true,true,true,true";
                $qCallBehavior8->type = 1;
                $qCallBehavior8->enabled = 1;
                $qCallBehavior8->insert_user = $user_uuid;
                $qCallBehavior8->insert_date = date("Y-m-d H:i:s");

                $this->redis->set($number_alias ."@". $vExtension8->accountcode ."-click2talk-mode", "");
                $this->redis->set($number_alias . "+" . $vExtension8->accountcode, "0");
                $this->redis->set($number_alias  . "-". $vExtension8->accountcode . "-chameleon", "1");
                $this->redis->set($number_alias  . "-". $vExtension8->accountcode . "-permission", "7");
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-all-destination", ["","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-all-destination2", ["","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-busy-destination", ["","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-busy-destination2", ["","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-destination", ["*99" . $number_alias, "*99" . $number_alias, "*99" . $number_alias, "*99" . $number_alias, "*99" . $number_alias]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-destination2", ["*99" . $number_alias, "*99" . $number_alias, "*99" . $number_alias, "*99" . $number_alias, "*99" . $number_alias]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout2", ["30", "30", "30", "30", "30"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-follow-me-destinations", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-follow-me-destinations2", ["","","","","","","","","","","","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout", ["","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-timeout2", ["","","","",""]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-all-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-all-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-busy-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-busy-enabled2",  ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-enabled",  ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-no-answer-enabled2", ["true", "true", "true", "true", "true"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-me-enabled", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-forward-me-enabled2", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-callScreening", ["false", "false", "false", "false", "false"]);
                $this->redis->rpush($number_alias . "@" . $vExtension8->accountcode . "-callScreening2",  ["false", "false", "false", "false", "false"]);
                $qCallBehavior8->save();

                $data['data']['licenseKey'] = $licenseType[0];
                $data['data']['serverType'] = (int)$serverType;
                $this->redis->set('ringq_config', json_encode($data));
                $this->redis->set('setup_ringq', $fqdn);

                // Give the SIP profiles this machine's own addresses.
                //
                // The hosted-by-RingQ image is a snapshot of a box where the installer
                // ran inside a BRIDGE-networked container, so it carries that build
                // container's 172.17.x.x in sip-ip/rtp-ip and the build box's public IP
                // in ext-sip-ip/ext-rtp-ip. The droplet's PBX container runs with
                // network_mode: host, where 172.17.x.x is assigned to nothing, so both
                // profiles fail to bind ("the IP the profile is attempting to bind to is
                // not local to this system") and `sofia status` reports 0 profiles: a
                // freshly delivered tenant with no registrations and no calls.
                //
                // Nothing else repairs it. The heal loops in jobs.sh and the installer
                // are both gated on sip_profile_name IN ('__disabled__'), and
                // setupNetworkProfilesAction has no caller. Activation is the only code
                // that runs on every new hosted box, and it already knows both answers.
                //
                // Not gated on installationType: a profile bound to an address that does
                // not exist is wrong for every kind of install. $onlyStale = true keeps
                // it a no-op wherever the stored values are already usable.
                $profileChanges = [];
                $bindIp = $this->detectLocalIpv4();
                if ($bindIp === '') {
                    error_log('sipProfileNetwork: no local IPv4 could be proven — SIP profile addresses left untouched');
                } else {
                    $profileChanges = $this->writeSipProfileNetwork($bindIp, $serverIpAddress, true);
                    foreach ($profileChanges as $change) {
                        error_log(sprintf(
                            'sipProfileNetwork: %s %s %s -> %s',
                            $change['profile'], $change['setting'], $change['from'], $change['to']
                        ));
                    }
                }

                // Clear FreeSWITCH settings cache so updated SIP profile settings
                // (force-register-domain, rtp-ip, ext-rtp-ip, etc.) take effect immediately
                // without requiring a manual cache clear or FreeSWITCH restart.
                shell_exec('rm -rf /var/cache/ringq/configuration.sofia.conf* 2>/dev/null');
                shell_exec('fs_cli -x "reloadxml" 2>/dev/null');
                shell_exec('fs_cli -x "sofia profile internal rescan" 2>/dev/null');
                shell_exec('fs_cli -x "sofia profile external rescan" 2>/dev/null');

                // A rescan reloads a RUNNING profile. When the bind address was the one
                // just corrected, the profile never started and there is nothing to
                // rescan — only `start` brings it up. Gated on an actual change so a
                // repeat activation disturbs nothing.
                if ($profileChanges) {
                    $this->reloadSipProfiles();
                }

                return $this->response->setJsonContent([
                    'status' => $response->getStatusCode(),
                    'content' => $data
                ]);
            } else {
                return $this->response->setJsonContent([
                    'result' => 'error',
                    'message' => 'An unexpected error occurred. Please try again later.',
                    'content' => $data
                ]);
            }
        } catch (RequestException $e) {
            $responseBody = $e->getResponse()->getBody()->getContents();

            $decodedResponse = json_decode($responseBody, true);

            return $this->response->setJsonContent([
                'error' =>  $decodedResponse,
                'result' => $decodedResponse['result'] ?? 'error',
                'content' => $decodedResponse
            ]);
        }
    }

    /**
     * GET /licensing/interfaces
     * Lists every IPv4 interface on this host plus the auto-detected public IP.
     * Used by the activation wizard to let the user pick the Primary
     * (internet-facing) interface when more than one NIC is present.
     */
    public function interfacesAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        // Enumerate global-scope IPv4 interfaces: name + IP.
        // `ip -4 -o addr show scope global` emits one line per address.
        $raw = shell_exec("ip -4 -o addr show scope global 2>/dev/null") ?: '';
        $interfaces = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/\d+/', $line, $m)) {
                $interfaces[] = [
                    'name'    => $m[1],
                    'address' => $m[2],
                ];
            }
        }

        // The IP that owns the default route — recommended "Primary" choice.
        $defaultIp = trim((string) shell_exec(
            "ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if (\$i==\"src\") print \$(i+1)}' | head -n1"
        ));
        foreach ($interfaces as &$iface) {
            $iface['is_default_route'] = ($iface['address'] === $defaultIp);
        }
        unset($iface);

        // Auto-detected public IP (best-effort; may be empty if no internet).
        // Routed through the shared resolver so this path is metadata-first too and
        // no single third-party provider is a point of failure anywhere in this file.
        $publicIp = $this->serverIpAddress();

        return $this->response->setJsonContent([
            'result'     => 'success',
            'interfaces' => array_values($interfaces),
            'default_ip' => $defaultIp,
            'public_ip'  => $publicIp,
        ]);
    }


    /**
     * Every global-scope IPv4 currently assigned to this host.
     *
     * Inside the hosted image the PBX container runs with `network_mode: host`, so
     * this is the droplet's own interface list, not a Docker bridge.
     */
    protected function localIpv4List() {
        $out = [];
        exec("ip -4 addr show scope global 2>/dev/null | awk '/inet / {sub(/\\/.*/, \"\", \$2); print \$2}'", $out);

        return array_values(array_filter(array_map('trim', $out), function ($ip) {
            return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }));
    }

    /**
     * The IPv4 a SIP profile can actually BIND to: the address that owns the default
     * route.
     *
     * Deliberately NOT serverIpAddress(). That resolves the address the world reaches
     * us on, which behind NAT is not assigned to any local interface — and a profile
     * told to bind to an address that is not local does not degrade, it refuses to
     * start at all ("Error Creating SIP UA ... the IP the profile is attempting to
     * bind to is not local to this system"). Binding uses this; advertising uses
     * serverIpAddress().
     *
     * The answer is validated against the live interface list before it is returned:
     * writing an unverified address reproduces the very defect this exists to fix,
     * only with a different number. Returns '' when nothing can be proven local,
     * which every caller must read as "write nothing".
     */
    protected function detectLocalIpv4() {
        $candidate = trim((string) shell_exec(
            "ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<=NF;i++) if (\$i==\"src\") print \$(i+1)}' | head -n1"
        ));

        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return '';
        }

        return in_array($candidate, $this->localIpv4List(), true) ? $candidate : '';
    }

    /**
     * Values that are FreeSWITCH constructs rather than stale literals. `$${...}` is
     * resolved by FreeSWITCH itself at profile start, and autonat:/stun:/host: are
     * deliberate NAT strategies. Overwriting any of them with a literal silently
     * removes a working configuration, so they are never rewritten.
     */
    protected static function isManagedNetworkToken($value) {
        return strpos($value, '$${') === 0
            || strpos($value, 'autonat:') === 0
            || strpos($value, 'stun:')    === 0
            || strpos($value, 'host:')    === 0;
    }

    /**
     * THE SINGLE WRITER for the `internal` / `external` SIP profile network settings.
     *
     * Two callers with genuinely different semantics share it, which is why $onlyStale
     * exists rather than two copies of this SQL:
     *
     *   - setupNetworkProfilesAction ($onlyStale = false) — an operator picked a NIC in
     *     the activation wizard. Their choice is applied whether or not the stored value
     *     was already usable.
     *   - registerlicenceAction ($onlyStale = true) — nobody chose anything. Only rows
     *     that are provably broken are touched, so a deliberate secondary-NIC binding
     *     survives and the whole operation is a no-op on a correct box. That is what
     *     makes it safe to run on every activation.
     *
     * Returns one row per change: ['profile','setting','from','to']. An empty array
     * means nothing needed doing — callers use that to decide whether FreeSWITCH has
     * to be disturbed at all.
     *
     * MPLS and the ipv6 profiles are never touched.
     */
    protected function writeSipProfileNetwork($localIp, $publicIp, $onlyStale = true) {
        $localIp  = trim((string) $localIp);
        $publicIp = trim((string) $publicIp);

        if ($localIp === '' || filter_var($localIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }
        if ($publicIp !== '' && filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            // A malformed public IP must not blank the NAT advertisement. Drop it and
            // still fix the bind address, which is the half that stops the switch.
            $publicIp = '';
        }

        $liveIps = $this->localIpv4List();

        // presence-hosts is a composite, not an address, so it is computed once here
        // and compared whole.
        $presenceHosts = ($publicIp !== '' && $publicIp !== $localIp)
            ? '$${domain},' . $localIp . ',' . $publicIp
            : '$${domain},' . $localIp;

        $rows = $this->db->fetchAll(
            "SELECT s.sip_profile_setting_uuid AS uuid,
                    p.sip_profile_name         AS profile,
                    s.sip_profile_setting_name AS setting,
                    s.sip_profile_setting_value AS value
               FROM v_sip_profile_settings s
               JOIN v_sip_profiles p ON p.sip_profile_uuid = s.sip_profile_uuid
              WHERE p.sip_profile_name IN ('internal', 'external')
                AND s.sip_profile_setting_name IN ('sip-ip', 'rtp-ip', 'ext-sip-ip', 'ext-rtp-ip', 'presence-hosts')",
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        $changes = [];

        foreach ($rows as $row) {
            $setting = $row['setting'];
            $current = (string) $row['value'];
            $target  = null;

            if ($setting === 'sip-ip' || $setting === 'rtp-ip') {
                $target = $localIp;
                if ($onlyStale) {
                    // A FreeSWITCH token resolves itself; an address that is live on
                    // this host is somebody's deliberate choice. Neither is stale.
                    if (self::isManagedNetworkToken($current) || in_array($current, $liveIps, true)) {
                        continue;
                    }
                }
            } elseif ($setting === 'ext-sip-ip' || $setting === 'ext-rtp-ip') {
                if ($publicIp === '') {
                    // No public IP could be resolved. Leaving a stale advertisement is
                    // bad; blanking it removes NAT traversal entirely, which is worse.
                    continue;
                }
                if (self::isManagedNetworkToken($current)) {
                    continue;
                }
                $target = $publicIp;
            } else {
                $target = $presenceHosts;
            }

            if ($target === null || $current === $target) {
                continue;
            }

            $this->db->execute(
                "UPDATE v_sip_profile_settings
                    SET sip_profile_setting_value = :value
                  WHERE sip_profile_setting_uuid = :uuid",
                ['value' => $target, 'uuid' => $row['uuid']]
            );

            $changes[] = [
                'profile' => $row['profile'],
                'setting' => $setting,
                'from'    => $current,
                'to'      => $target,
            ];
        }

        return $changes;
    }

    /**
     * Make FreeSWITCH pick up rewritten profile network settings.
     *
     * A cache clear plus `rescan` is NOT sufficient and that is the whole point of this
     * method. `rescan` re-reads a profile that is RUNNING; a profile whose bind address
     * was not local never started, so on exactly the boxes this repair exists for there
     * is nothing to rescan and `sofia status` reports `0 profiles`. Only `start` brings
     * one up, and it is harmless against a profile that is already running.
     */
    protected function reloadSipProfiles() {
        @array_map('unlink', glob('/var/cache/ringq/configuration.sofia.conf*') ?: []);
        shell_exec('fs_cli -x "reloadxml" 2>/dev/null');
        foreach (['internal', 'external'] as $profile) {
            shell_exec('fs_cli -x "sofia profile ' . $profile . ' rescan" 2>/dev/null');
            shell_exec('fs_cli -x "sofia profile ' . $profile . ' start" 2>/dev/null');
        }
    }

    /**
     * POST /licensing/setupNetworkProfiles
     * Persists the user's NIC choice from the activation wizard.
     *
     * Body: { local_ip: "192.168.50.49", public_ip: "123.176.22.45" (optional) }
     *
     * - Writes local_ip to sip-ip / rtp-ip on the `internal` and `external` profiles.
     * - Writes public_ip to ext-sip-ip / ext-rtp-ip on the same profiles ONLY IF
     *   non-blank. Blank means "leave whatever the install script set in place" —
     *   the user can fix it later via the SIP Profiles UI.
     * - Never touches MPLS or ipv6 profiles.
     * - Validates that local_ip is actually assigned to an interface on this host
     *   so a buggy or compromised frontend can't write a foreign IP.
     */
    public function setupNetworkProfilesAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if (!$this->request->isPost()) {
            return $this->response->setJsonContent([
                'result'  => 'error',
                'message' => 'POST required.',
            ]);
        }

        // Accept either form-encoded POST or JSON body.
        $localIp  = (string) ($this->request->getPost('local_ip', 'string')  ?: '');
        $publicIp = (string) ($this->request->getPost('public_ip', 'string') ?: '');
        if ($localIp === '') {
            $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $localIp  = isset($body['local_ip'])  ? (string) $body['local_ip']  : '';
            $publicIp = isset($body['public_ip']) ? (string) $body['public_ip'] : '';
        }
        $localIp  = trim($localIp);
        $publicIp = trim($publicIp);

        if ($localIp === '' || filter_var($localIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return $this->response->setJsonContent([
                'result'  => 'error',
                'message' => 'Invalid local IP address.',
            ]);
        }
        if ($publicIp !== '' && filter_var($publicIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return $this->response->setJsonContent([
                'result'  => 'error',
                'message' => 'Invalid public IP address.',
            ]);
        }

        // Backstop: local_ip must actually live on this host.
        $localList = [];
        exec("ip -4 addr show scope global 2>/dev/null | awk '/inet / {sub(/\\/.*/, \"\", \$2); print \$2}'", $localList);
        if (!in_array($localIp, $localList, true)) {
            return $this->response->setJsonContent([
                'result'  => 'error',
                'message' => 'Local IP ' . $localIp . ' is not assigned to any interface on this server.',
            ]);
        }

        // The SQL itself lives in writeSipProfileNetwork() so this endpoint and the
        // automatic repair inside registerlicenceAction cannot drift about what a
        // correct profile looks like. $onlyStale = false because an operator picked
        // this NIC deliberately: their choice is applied whether or not the stored
        // value happened to be usable already.
        try {
            $this->db->begin();
            $changes = $this->writeSipProfileNetwork($localIp, $publicIp, false);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return $this->response->setJsonContent([
                'result'  => 'error',
                'message' => 'Failed to update profiles: ' . ControllerBase::safeErrorMessage($e),
            ]);
        }

        // Clear the sofia config cache and bring the profiles up. `start` is included
        // because a profile whose previous bind address was not local never started,
        // and a rescan cannot reload something that is not running.
        if ($changes) {
            $this->reloadSipProfiles();
        }

        return $this->response->setJsonContent([
            'result'  => 'success',
            'message' => 'Network profiles updated.',
            'applied' => [
                'local_ip'  => $localIp,
                'public_ip' => $publicIp !== '' ? $publicIp : null,
            ],
        ]);
    }
}
