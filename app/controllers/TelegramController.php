<?php
declare(strict_types=1);

use Phalcon\Mvc\Controller;
use Phalcon\Http\Response;

class TelegramController extends ControllerBase
{
    private $baseUrl = "https://telegram.ringq.io";
    private $token   = "aVDJePF8tKlsrVe8D6jjAZfL81K3mA7l1D1uHMM7UCxhsYFKbsqXI3dpgo5fHt59i5KYBZPwLuqWoloutAZFkQmxq2f5d0Z9TQ7zDos40e0GfvjtXxfct46aP01IKi1UH23XkstxVavPaTdXiwjsoZIaS9lSbMXwLRssrMWWJaaS671Lkl3uVDdjsFmdcUjerw64u3dm";


    private function telegramSessionTenant()
    {
        $session = $this->session->get("auth");
        if (!$session || empty($session["subdomain"])) {
            return null;
        }
        return (string) $session["subdomain"];
    }

    private function telegramTenantAllowed($tenant): bool
    {
        $subdomain = $this->telegramSessionTenant();
        if ($subdomain === null || !is_string($tenant) || $tenant === "") {
            return false;
        }
        $derived = str_replace(".", "_", explode(".ringq", $subdomain)[0]) . "_ringq";
        $stored = (string) $this->redis->get("telegram_tenant:" . $subdomain);
        return hash_equals($derived, $tenant) || ($stored !== "" && hash_equals($stored, $tenant));
    }

    private function telegramPreflight()
    {
        if (!$this->request->isOptions()) {
            return null;
        }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $this->response->setStatusCode(200, "OK");
        return $this->response->setContent("");
    }

    private function telegramRefused()
    {
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $this->response->setStatusCode(403, "Forbidden");
        return $this->response->setJsonContent(["status" => "error", "message" => "Not allowed for this tenant."]);
    }

    /* =====================================================
        REGISTER TELEGRAM TENANT
       ===================================================== */
    public function registerAction()
    {
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

        $data = json_decode($this->request->getRawBody(), true);

        if (!$data) {
            return $this->response->setJsonContent([
                "status"=>"error",
                "message"=>"Invalid JSON"
            ]);
        }

        $body = [
            "name"     => $data['name'] ?? null,
            "api_id"   => (int)($data['api_id'] ?? 0),
            "api_hash" => $data['api_hash'] ?? null,
            "phone"    => $data['phone'] ?? null,
            "pbx_fqdn" => $data['pbx_fqdn'] ?? null,
        ];

        if ($this->telegramSessionTenant() === null) {
            return $this->telegramRefused();
        }
        $result = $this->telegramCurl("/register","POST",$body);
        $tenant = $result["response"]["tenant"] ?? null;
        if (is_string($tenant) && $tenant !== "") {
            $this->redis->set("telegram_tenant:" . $this->telegramSessionTenant(), $tenant);
        }

        return $this->response->setJsonContent($result);
    }

    /* =====================================================
        SUBMIT OTP
       ===================================================== */
    public function submitOtpAction()
    {
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $data = json_decode($this->request->getRawBody(), true);

        $body = [
            "tenant" => $data['tenant'] ?? null,
            "code"   => $data['code'] ?? null,
        ];

        if (!$this->telegramTenantAllowed($body["tenant"])) {
            return $this->telegramRefused();
        }
        $result = $this->telegramCurl("/submit_code","POST",$body);
        return $this->response->setJsonContent($result);
    }

    /* =====================================================
        CHECK STATUS
       ===================================================== */
    public function statusAction($tenant)
    {
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        if (!$this->telegramTenantAllowed($tenant)) {
            return $this->telegramRefused();
        }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $result = $this->telegramCurl("/status/".$tenant,"GET");
        return $this->response->setJsonContent($result);
    }

    /* =====================================================
        RESTART SERVICE
       ===================================================== */
    public function restartAction($tenant)
    {
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        if (!$this->telegramTenantAllowed($tenant)) {
            return $this->telegramRefused();
        }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $result = $this->telegramCurl("/restart/".$tenant,"POST");
        return $this->response->setJsonContent($result);
    }

    /* =====================================================
        STOP SERVICE
       ===================================================== */
    public function stopAction($tenant)
    {
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        if (!$this->telegramTenantAllowed($tenant)) {
            return $this->telegramRefused();
        }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $result = $this->telegramCurl("/stop/".$tenant,"POST");
        return $this->response->setJsonContent($result);
    }

    /* =====================================================
        VIEW LOGS URL
       ===================================================== */
    public function logsAction($tenant)
    {
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        if (!$this->telegramTenantAllowed($tenant)) {
            return $this->telegramRefused();
        }
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

        return $this->response->setJsonContent([
            "status" => 200,
            "logs_url" => $this->baseUrl."/logs/".$tenant
        ]);
    }


   public function deleteServiceAction($tenant)
{
        if (($pre = $this->telegramPreflight()) !== null) { return $pre; }
        if (!$this->telegramTenantAllowed($tenant)) {
            return $this->telegramRefused();
        }
    $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
    $result = $this->telegramCurl("/delete/".$tenant,"DELETE");
    return $this->response->setJsonContent($result);
}
    /* =====================================================
        CURL HELPER
       ===================================================== */

       private function telegramCurl($endpoint, $method = 'GET', $body = null)
{
    $url = $this->baseUrl . $endpoint;

    $ch = curl_init($url);

    $headers = [
        "Authorization: Bearer " . $this->token,
        "Content-Type: application/json"
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    //  IMPORTANT: support GET, POST, DELETE, PUT, PATCH
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    // send body if exists (for POST/PUT/PATCH)
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    // optional but recommended
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        return [
            "status" => "error",
            "message" => curl_error($ch)
        ];
    }

    curl_close($ch);

    return [
        "status"   => $httpCode,
        "response" => json_decode($response, true)
    ];
}
 /*   private function telegramCurl($endpoint, $method='GET', $body=null)
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);

        $headers = [
            "Authorization: Bearer ".$this->token,
            "Content-Type: application/json"
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($method == "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            return [
                "status"=>"error",
                "message"=>curl_error($ch)
            ];
        }

        curl_close($ch);

        return [
            "status"=>$httpCode,
            "response"=>json_decode($response,true)
        ];
    }*/
}