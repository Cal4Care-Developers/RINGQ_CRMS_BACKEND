<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

class SystemController extends ControllerBase
{
    public function indexAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $rawBody = $this->request->getRawBody();
        $rawData = json_decode($rawBody, true);

        if (!$this->redis->exists('ringq_config')) {
            return $this->response->setJsonContent([
                'result' => 'error',
                'message' => 'License not found'
            ]);
        }

        $getLicenseConfig = $this->redis->get('ringq_config');
        $licenseConfig = json_decode($getLicenseConfig, true); 
        
        if ($licenseConfig['data']['licenseKey'] !== $rawData['licenseKey']) {
            return $this->response->setJsonContent([
                'result' => 'error',
                'message' => 'Invalid license key'
            ]);
        }
 
        $currentVersion = $this->redis->get("serverVersion") ?? '8.7.62.3';
        $systemLoadPercentage = $this->system->getLoadPercentage(0, 2);
        $systemLoadSystem = $this->system->getLoad(0);
        $systemDiskUsagePercentage = $this->redis->lrange("systemDiskUsagePercentage2", -1, -1);
        $systemLoadPercentage2 = $this->redis->hgetall("systemLoadPercentage");
        $systemCoreCount = $this->system->getCoreCount();
        $systemMemTotal = $this->system2->memTotal();
        $systemMemUsed = $this->system2->memUsed();
        $systemMemFree = $this->system2->memFree();
        $systemMemAvailable = $this->system2->memAvailable();
        $totalSpace = disk_total_space("/");
        $totalSpaceGB = $totalSpace / (1024 * 1024 * 1024);
        // "systemDiskUsagePercentage": 25.54,
        // "systemDiskTotalSpace": 78.577938079834,
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => "http://143.198.209.102",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 500,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            echo 'cURL error: ' . curl_error($curl);
        } else {
            $version = json_decode($response, true); 

            return $this->response->setJsonContent([
                'result' => 'success',
                'data' => [
                    'systemLoadPercentage' =>  $systemLoadPercentage,
                    'systemLoadSystem' => (float)$this->redis->get("totalSpaceGB") - (float)current($systemDiskUsagePercentage),
                    'systemDiskUsagePercentage' => (float)current($systemDiskUsagePercentage), // 75
                    'systemDiskTotalSpace' => (float)$this->redis->get("totalSpaceGB"), // 25
                    'systemLoadPercentage2' => $systemLoadPercentage2, 
                    'systemCoreCount' => $systemCoreCount, 
                    'systemMemTotal' => $systemMemTotal, 
                    'systemMemUsed' => $systemMemUsed, 
                    'systemMemFree' => $systemMemFree, 
                    'systemMemAvailable' => $systemMemAvailable, 
                    'totalSpace' => $totalSpace, 
                    'totalSpaceGB' => $totalSpaceGB,
                    'currentVersion' => $currentVersion,
                    'updatedVersion' => $version["version"]
                ]
            ]);
        } 
    }
}