<?php
declare(strict_types=1);
use Phalcon\Mvc\View;
use Phalcon\Http\Response;
use PhpZip\ZipFile;

class CallsController extends ControllerBase
{
   /********************************IND TEAM PART ************************************************************************/
   public function uploadZipAllAction()
   {
      $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

    if (!$this->request->hasFiles()) {
        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => 'No file uploaded'
        ])->send();
    }

    $files = $this->request->getUploadedFiles();
    $file = $files[0];

    // 200MB limit
    $maxSize = 200 * 1024 * 1024;
    if ($file->getSize() > $maxSize) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'File too large. Maximum allowed size is 200 MB.'
        ])->send();
    }

    $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';
    $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extract_' . uniqid();

    try {

        if (!mkdir($extractDir, 0777, true)) {
            throw new \Exception('Failed to create temp extraction directory');
        }

        $file->moveTo($tempZip);

        $zip = new ZipFile();
        try {
            $zip->openFile($tempZip);
            $zip->extractTo($extractDir);
        } finally {
            $zip->close();
        }

        $xmlFile = $this->findXmlFile($extractDir);
        if (!$xmlFile) {
            throw new \Exception('No XML file found inside the ZIP archive');
        }

        // 🔥 Generate all 4 CSVs
        $extensionCsv = $this->convertXmlToCsvSelected($xmlFile);
        $trunkCsv     = $this->convertXmlToTrunkCsv($xmlFile);
        $didCsv       = $this->convertXmlToDidCsv($xmlFile);
        $outboundCsv  = $this->convertXmlToOutboundRulesCsv($xmlFile);

        return $this->response->setJsonContent([
            'status' => 'success',
            'data' => [
                'extension_url' => $extensionCsv['full_url'],
                'trunk_url'     => $trunkCsv['full_url'],
                'did_url'       => $didCsv['full_url'],
                'outbound_url'  => $outboundCsv['full_url']
            ]
        ])->send();

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => \ControllerBase::safeErrorMessage($e)
        ])->send();

    } finally {

        if (file_exists($tempZip)) unlink($tempZip);
        if (is_dir($extractDir)) $this->deleteDir($extractDir);
    }
}

  public function uploadZipExtensionAction()
{
    $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

    if (!$this->request->hasFiles()) {
        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => 'No file uploaded'
        ])->send();
    }

    $files = $this->request->getUploadedFiles();
    $file = $files[0];

    // 200MB size limit
    $maxSize = 200 * 1024 * 1024;
    if ($file->getSize() > $maxSize) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'File too large. Maximum allowed size is 200 MB.'
        ])->send();
    }

    $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';
    $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extract_' . uniqid();

    try {

        if (!mkdir($extractDir, 0777, true)) {
            throw new \Exception('Failed to create temp extraction directory');
        }

        $file->moveTo($tempZip);

        $zip = new ZipFile();
        try {
            $zip->openFile($tempZip);
            $zip->extractTo($extractDir);
        } finally {
            $zip->close();
        }

        $xmlFile = $this->findXmlFile($extractDir);
        if (!$xmlFile) {
            throw new \Exception('No XML file found inside the ZIP archive');
        }

        $extensionCsv = $this->convertXmlToCsvSelected($xmlFile);

        return $this->response->setJsonContent([
            'status'        => 'success',
            'extension_url' => $extensionCsv['full_url']
        ])->send();

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => \ControllerBase::safeErrorMessage($e)
        ])->send();

    } finally {

        if (file_exists($tempZip)) unlink($tempZip);
        if (is_dir($extractDir)) $this->deleteDir($extractDir);
    }
}
  private function convertXmlToCsvSelected($xmlFile)
{
    $xmlContent = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($xmlContent);

    if (!$xml) {
        throw new \Exception('Failed to load XML file');
    }

    $db = \Phalcon\Di::getDefault()->get('db');

    // ✅ Load all existing extensions from DB
    $existingRows = $db->fetchAll(
        "SELECT extension FROM v_extensions",
        \Phalcon\Db\Enum::FETCH_ASSOC
    );

    $existingExtensions = array_column($existingRows, 'extension');
    $existingExtensions = array_map('intval', $existingExtensions);

    // This will store newly generated extensions in same upload
    $generatedExtensions = [];

    $publicPath = realpath(__DIR__ . '/../../public/');
    $csvFolder = 'extension_csv';
    $targetDir = $publicPath . DIRECTORY_SEPARATOR . $csvFolder . DIRECTORY_SEPARATOR;

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $fileName = 'extensions_' . time() . '.csv';
    $fullPath = $targetDir . $fileName;
    $fullUrl  = $this->generateFileUrl($csvFolder, $fileName);

    $fp = fopen($fullPath, 'w');

    fputcsv($fp, [
        'extension',
        'email_address',
        'first_name',
        'last_name',
        'mobile_number',
        'sip_uid'
    ]);

    $nodes = $xml->xpath('//*[local-name()="Extension" or local-name()="extenstion"]');

    if ($nodes) {

        foreach ($nodes as $node) {

            $rawNumber = trim((string)$node->Number);
            $extension = '';
            $sipId = '';

            // ✅ CASE 1: 4 digit extension
            if (preg_match('/^\d{4}$/', $rawNumber)) {

                if (in_array((int)$rawNumber, $existingExtensions) ||
                    in_array((int)$rawNumber, $generatedExtensions)) {

                    throw new \Exception("Extension {$rawNumber} already exists.");
                }

                $extension = $rawNumber;
                $generatedExtensions[] = (int)$extension;

            } else {

                // ✅ CASE 2: Generate new extension
                $next = 1000;

                while (
                    in_array($next, $existingExtensions) ||
                    in_array($next, $generatedExtensions)
                ) {
                    $next++;
                }

                $extension = (string)$next;
                $generatedExtensions[] = $next;

                $sipId = !empty($rawNumber) ? '="' . $rawNumber . '"' : '';
            }

            // Get Mobile
            $mobile = '';
            if (isset($node->Properties->DNProperty)) {
                foreach ($node->Properties->DNProperty as $prop) {
                    if ((string)$prop->Name === 'MOBILENUMBER') {
                        $mobile = (string)$prop->Value;
                        break;
                    }
                }
            }

            $formattedMobile = !empty($mobile) ? '="' . $mobile . '"' : '';

            fputcsv($fp, [
                $extension,
                (string)$node->EmailAddress,
                (string)$node->FirstName,
                (string)$node->LastName,
                $formattedMobile,
                $sipId
            ]);
        }
    }

    fclose($fp);

    return ['full_url' => $fullUrl];
}
private function extensionExists($extension)
{
    $db = \Phalcon\Di::getDefault()->get('db');

    $result = $db->fetchOne(
        "SELECT extension FROM v_extensions WHERE extension = :ext",
        \Phalcon\Db\Enum::FETCH_ASSOC,
        [
            'ext' => $extension
        ]
    );

    return $result ? true : false;
}
private function getNextExtension()
{
    $db = \Phalcon\Di::getDefault()->get('db');

    $start = 1000;

    while (true) {

        $exists = $db->fetchOne(
            "SELECT extension FROM v_extensions WHERE extension = :ext",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [
                'ext' => $start
            ]
        );

        if (!$exists) {
            return (string)$start;
        }

        $start++;
    }
}
 public function uploadZipTrunkAction()
    {
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

        if (!$this->request->hasFiles()) {
            return $this->response->setJsonContent(['status' => 'error', 'message' => 'No file uploaded'])->send();
        }

        $files = $this->request->getUploadedFiles();
        $file = $files[0];

        // --- 200 MB SIZE LIMIT CHECK ---
        $maxSize = 200 * 1024 * 1024; // 200 MB in bytes
        if ($file->getSize() > $maxSize) {
            return $this->response->setJsonContent([
                'status'  => 'error', 
                'message' => 'File too large. Maximum allowed size is 200 MB.'
            ])->send();
        }

        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';
        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extract_' . uniqid();

        try {
            if (!mkdir($extractDir, 0777, true)) {
                throw new \Exception('Failed to create temp extraction directory');
            }

            $file->moveTo($tempZip);

            $zip = new ZipFile();
            try {
                $zip->openFile($tempZip);
                $zip->extractTo($extractDir);
            } catch (\Exception $e) {
                throw new \Exception('ZIP extract failed: ' . $e->getMessage());
            } finally {
                $zip->close();
            }

            $xmlFile = $this->findXmlFile($extractDir);
            if (!$xmlFile) {
                throw new \Exception('No XML file found inside the ZIP archive');
            }

            // Generate CSVs
            
            $trunkCsv = $this->convertXmlToTrunkCsv($xmlFile);
          


            return $this->response->setJsonContent([
                'status'        => 'success',
                'trunk_url'     => $trunkCsv['full_url']
              
            ])->send();

        } catch (\Exception $e) {
            return $this->response->setJsonContent(['status' => 'error', 'message' => \ControllerBase::safeErrorMessage($e)])->send();
        } finally {
            if (file_exists($tempZip)) unlink($tempZip);
            if (is_dir($extractDir)) $this->deleteDir($extractDir);
        }
    }
 /*   private function convertXmlToTrunkCsv($xmlFile)
{
   
    $xmlContent = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($xmlContent);
    
    $publicPath = realpath(__DIR__ . '/../../public/');
    $csvFolder = 'trunk_csv';
    $targetDir = $publicPath . DIRECTORY_SEPARATOR . $csvFolder . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = 'trunks_' . time() . '.csv';
    $fullPath = $targetDir . $fileName;
    $fullUrl = $this->generateFileUrl($csvFolder, $fileName);

    $fp = fopen($fullPath, 'w');
    fputcsv($fp, ['trunk', 'username', 'password', 'proxy', 'port', 'caller_id']);

    // 1. Create a Proxy Map from the Gateways/VoipProviders section
    $proxyMap = [];
     
    // Look for both Gateway and VoipProvider tags inside Gateways
    $gatewaysNodes = $xml->xpath('//Gateways/Gateway | //Gateways/VoipProvider');
   
    foreach ($gatewaysNodes as $gNode) {
      
        $name = (string)$gNode->Name;
        if (!empty($name)) {
            $proxyMap[$name] = [
                // Use ProxyHost if available, otherwise fallback to Host
                'host' => (string)$gNode->ProxyHost ?: (string)$gNode->Host ?: '',
                'port' => (string)$gNode->ProxyPort ?: (string)$gNode->Port ?: '5060'
            ];
        }
    }

    // 2. Process ExternalLine and match by Gateway name
    $externalLines = $xml->xpath('//*[local-name()="ExternalLine"]');

    if ($externalLines) {
        foreach ($externalLines as $line) {
            $gatewayName = (string)$line->Gateway; // This is the link (e.g., "Trunk 1")
            
            // Fetch proxy info from our map using the gateway name
            $matchedProxy = $proxyMap[$gatewayName]['host'] ?? '';
            $matchedPort  = $proxyMap[$gatewayName]['port'] ?? '5060';

            // Fallback: Check SECONDARY_REGISTRAR if ProxyHost was empty
            if (empty($matchedProxy) && isset($line->Properties->DNProperty)) {
                foreach ($line->Properties->DNProperty as $prop) {
                    if ((string)$prop->Name === 'SECONDARY_REGISTRAR' && !empty((string)$prop->Value)) {
                        $matchedProxy = (string)$prop->Value;
                    }
                }
            }

            fputcsv($fp, [
                $gatewayName,               // trunk
                (string)$line->AuthID,      // username
                (string)$line->AuthPassword,// password
                $matchedProxy,              // proxy
                $matchedPort,               // port
                (string)$line->ExternalNumber // caller_id
            ]);
        }
    }
    
    fclose($fp);
    return ['full_url' => $fullUrl];
}*/
private function convertXmlToTrunkCsv($xmlFile)
{
    $xmlContent = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($xmlContent);

    $publicPath = realpath(__DIR__ . '/../../public/');
    $csvFolder = 'trunk_csv';
    $targetDir = $publicPath . DIRECTORY_SEPARATOR . $csvFolder . DIRECTORY_SEPARATOR;

    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = 'trunks_' . time() . '.csv';
    $fullPath = $targetDir . $fileName;
    $fullUrl = $this->generateFileUrl($csvFolder, $fileName);

    $fp = fopen($fullPath, 'w');
    fputcsv($fp, ['trunk', 'username', 'password', 'proxy', 'port', 'caller_id']);

    // Proxy Map
    $proxyMap = [];

    $gatewaysNodes = $xml->xpath('//Gateways/Gateway | //Gateways/VoipProvider');

    foreach ($gatewaysNodes as $gNode) {
        $name = (string)$gNode->Name;

        if (!empty($name)) {
            $proxyMap[$name] = [
                'host' => (string)$gNode->ProxyHost ?: (string)$gNode->Host ?: '',
                'port' => (string)$gNode->ProxyPort ?: (string)$gNode->Port ?: '5060'
            ];
        }
    }

    // Process ExternalLine
    $externalLines = $xml->xpath('//*[local-name()="ExternalLine"]');

    if ($externalLines) {
        foreach ($externalLines as $line) {

            $gatewayName = trim((string)$line->Gateway);

            // 🚫 Skip WebMeeting trunks
            if (stripos($gatewayName, 'webmeeting') !== false) {
                continue;
            }

            $matchedProxy = $proxyMap[$gatewayName]['host'] ?? '';
            $matchedPort  = $proxyMap[$gatewayName]['port'] ?? '5060';

            if (empty($matchedProxy) && isset($line->Properties->DNProperty)) {
                foreach ($line->Properties->DNProperty as $prop) {
                    if ((string)$prop->Name === 'SECONDARY_REGISTRAR' && !empty((string)$prop->Value)) {
                        $matchedProxy = (string)$prop->Value;
                    }
                }
            }

            fputcsv($fp, [
                $gatewayName,
                (string)$line->AuthID,
                (string)$line->AuthPassword,
                $matchedProxy,
                $matchedPort,
                (string)$line->ExternalNumber
            ]);
        }
    }

    fclose($fp);

    return ['full_url' => $fullUrl];
}
  public function uploadZipDIDAction()
    {
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

        if (!$this->request->hasFiles()) {
            return $this->response->setJsonContent(['status' => 'error', 'message' => 'No file uploaded'])->send();
        }

        $files = $this->request->getUploadedFiles();
        $file = $files[0];

        // --- 200 MB SIZE LIMIT CHECK ---
        $maxSize = 200 * 1024 * 1024; // 200 MB in bytes
        if ($file->getSize() > $maxSize) {
            return $this->response->setJsonContent([
                'status'  => 'error', 
                'message' => 'File too large. Maximum allowed size is 200 MB.'
            ])->send();
        }

        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';
        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extract_' . uniqid();

        try {
            if (!mkdir($extractDir, 0777, true)) {
                throw new \Exception('Failed to create temp extraction directory');
            }

            $file->moveTo($tempZip);

            $zip = new ZipFile();
            try {
                $zip->openFile($tempZip);
                $zip->extractTo($extractDir);
            } catch (\Exception $e) {
                throw new \Exception('ZIP extract failed: ' . $e->getMessage());
            } finally {
                $zip->close();
            }

            $xmlFile = $this->findXmlFile($extractDir);
            if (!$xmlFile) {
                throw new \Exception('No XML file found inside the ZIP archive');
            }

            // Generate CSVs
           
            $didCsv=$this->convertXmlToDidCsv($xmlFile);
          


            return $this->response->setJsonContent([
                'status'        => 'success',
                'did_url'        => $didCsv['full_url']
                
            ])->send();

        } catch (\Exception $e) {
            return $this->response->setJsonContent(['status' => 'error', 'message' => \ControllerBase::safeErrorMessage($e)])->send();
        } finally {
            if (file_exists($tempZip)) unlink($tempZip);
            if (is_dir($extractDir)) $this->deleteDir($extractDir);
        }
    }

    private function convertXmlToDidCsv($xmlFile)
{
    $xmlContent = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($xmlContent);
    if (!$xml) throw new \Exception('Failed to load XML file for DID extraction');

    $publicPath = realpath(__DIR__ . '/../../public/');
    $csvFolder = 'did_number_csv';
    $targetDir = $publicPath . DIRECTORY_SEPARATOR . $csvFolder . DIRECTORY_SEPARATOR;

    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = 'did_numbers_' . time() . '.csv';
    $fullPath = $targetDir . $fileName;
    $fullUrl = $this->generateFileUrl($csvFolder, $fileName);

    $fp = fopen($fullPath, 'w');
    fputcsv($fp, ['DID', 'Extension', 'Trunk']);

    $namespaces = $xml->getDocNamespaces(true);
    foreach ($namespaces as $prefix => $ns) {
        $xml->registerXPathNamespace($prefix ?: 'ns', $ns);
    }

    $externalLines = $xml->xpath('//*[local-name()="ExternalLine"]');

    if ($externalLines) {
        foreach ($externalLines as $line) {

            $gatewayName = trim((string)$line->Gateway);

            // 🚫 Skip WebMeeting trunks
            if (stripos($gatewayName, 'webmeeting') !== false) {
                continue;
            }

            $didValue = (string)$line->ExternalNumber;

            if (empty($didValue)) {
                $didValue = (string)$line->DIDNumbers;
            }

            fputcsv($fp, [
                $didValue,                 // DID
                '',     // Extension
                $gatewayName               // Trunk
            ]);
        }
    }

    fclose($fp);

    return ['full_url' => $fullUrl];
}
/*private function convertXmlToDidCsv($xmlFile)
{
    $xmlContent = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($xmlContent);
    if (!$xml) throw new \Exception('Failed to load XML file for DID extraction');

    $publicPath = realpath(__DIR__ . '/../../public/');
    $csvFolder = 'did_number_csv';
    $targetDir = $publicPath . DIRECTORY_SEPARATOR . $csvFolder . DIRECTORY_SEPARATOR;
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = 'did_numbers_' . time() . '.csv';
    $fullPath = $targetDir . $fileName;
    $fullUrl = $this->generateFileUrl($csvFolder, $fileName);

    $fp = fopen($fullPath, 'w');
    // Header based on your requirement: DID, Extension, Trunk
    fputcsv($fp, ['DID', 'Extension', 'Trunk']);

    // Register namespaces to handle XPath correctly
    $namespaces = $xml->getDocNamespaces(true);
    foreach ($namespaces as $prefix => $ns) { 
        $xml->registerXPathNamespace($prefix ?: 'ns', $ns); 
    }

    // Target ExternalLine tags
    $externalLines = $xml->xpath('//*[local-name()="ExternalLine"]');

    if ($externalLines) {
        foreach ($externalLines as $line) {
            /**
             * Logic Mapping:
             * DID       <- ExternalNumber or DIDNumbers tag
             * Extension <- Number tag
             * Trunk     <- Gateway tag
             */
            
           /* $didValue = (string)$line->ExternalNumber;
            // Fallback to DIDNumbers tag if ExternalNumber is empty
            if (empty($didValue)) {
                $didValue = (string)$line->DIDNumbers;
            }

            fputcsv($fp, [
                $didValue,                  // DID
                (string)$line->Number,      // Extension
                (string)$line->Gateway      // Trunk
            ]);
        }
    }

    fclose($fp);
    return ['full_url' => $fullUrl];
}*/
 public function uploadZipOutboundAction()
    {
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

        if (!$this->request->hasFiles()) {
            return $this->response->setJsonContent(['status' => 'error', 'message' => 'No file uploaded'])->send();
        }

        $files = $this->request->getUploadedFiles();
        $file = $files[0];

        // --- 200 MB SIZE LIMIT CHECK ---
        $maxSize = 200 * 1024 * 1024; // 200 MB in bytes
        if ($file->getSize() > $maxSize) {
            return $this->response->setJsonContent([
                'status'  => 'error', 
                'message' => 'File too large. Maximum allowed size is 200 MB.'
            ])->send();
        }

        $tempZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('zip_', true) . '.zip';
        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'extract_' . uniqid();

        try {
            if (!mkdir($extractDir, 0777, true)) {
                throw new \Exception('Failed to create temp extraction directory');
            }

            $file->moveTo($tempZip);

            $zip = new ZipFile();
            try {
                $zip->openFile($tempZip);
                $zip->extractTo($extractDir);
            } catch (\Exception $e) {
                throw new \Exception('ZIP extract failed: ' . $e->getMessage());
            } finally {
                $zip->close();
            }

            $xmlFile = $this->findXmlFile($extractDir);
            if (!$xmlFile) {
                throw new \Exception('No XML file found inside the ZIP archive');
            }

            // Generate CSVs
           
            $outboundCsv=$this->convertXmlToOutboundRulesCsv($xmlFile);
          


            return $this->response->setJsonContent([
                'status'        => 'success',
                'outbound_url'    => $outboundCsv['full_url']
            ])->send();

        } catch (\Exception $e) {
            return $this->response->setJsonContent(['status' => 'error', 'message' => \ControllerBase::safeErrorMessage($e)])->send();
        } finally {
            if (file_exists($tempZip)) unlink($tempZip);
            if (is_dir($extractDir)) $this->deleteDir($extractDir);
        }
    }
private function convertXmlToOutboundRulesCsv($xmlFile)
{
    $xmlContent = file_get_contents($xmlFile);
    $xml = @simplexml_load_string($xmlContent);
    if (!$xml) throw new \Exception('Failed to load XML file for Outbound Rules');

    $publicPath = realpath(__DIR__ . '/../../public/');
    $csvFolder = 'outbound_rules_csv';
    $targetDir = $publicPath . DIRECTORY_SEPARATOR . $csvFolder . DIRECTORY_SEPARATOR;

    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = 'outbound_rules_' . time() . '.csv';
    $fullPath = $targetDir . $fileName;
    $fullUrl = $this->generateFileUrl($csvFolder, $fileName);

    $fp = fopen($fullPath, 'w');

    fputcsv($fp, ['name', 'prefix', 'strip', 'prepend', 'length', 'trunk', 'caller_id']);

    $rules = $xml->xpath('//*[local-name()="OutboundRule"]');

    $uniqueRows = [];

    if ($rules) {
        foreach ($rules as $rule) {

            $ruleName = trim((string)$rule->Name);
            $prefix   = trim((string)$rule->Prefix);
            $length   = trim((string)$rule->NumberLengthRanges);

            if (isset($rule->OutboundRoutes->OutboundRoute)) {

                foreach ($rule->OutboundRoutes->OutboundRoute as $route) {

                    $strip    = trim((string)$route->StripDigits);
                    $prepend  = trim((string)$route->Prepend);
                    $gateway  = trim((string)$route->Gateway);
                    $callerId = trim((string)$route->CallerID);

                    // ✅ Skip if trunk (gateway) is empty
                    if (empty($gateway)) {
                        continue;
                    }

                    // Duplicate check
                    $rowKey = md5($ruleName . '|' . $prefix . '|' . $strip . '|' . $prepend . '|' . $length . '|' . $gateway . '|' . $callerId);

                    if (isset($uniqueRows[$rowKey])) {
                        continue;
                    }

                    $uniqueRows[$rowKey] = true;

                    fputcsv($fp, [
                        $ruleName,
                        $prefix,
                        $strip,
                        $prepend,
                        $length,
                        $gateway,
                        $callerId
                    ]);
                }
            }
        }
    }

    fclose($fp);

    return ['full_url' => $fullUrl];
}

    private function generateFileUrl($folder, $fileName)
    {
        $protocol = ($this->request->getScheme() ?: 'http') . '://';
        $host = $this->request->getHttpHost() . ':8443';
        $scriptPath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $baseUri = rtrim(str_replace('/public', '', $scriptPath), '/');
        return $protocol . $host . $baseUri . '/' . $folder . '/' . $fileName;
    }

    private function flattenArray($array, &$result = []) {
        if (!is_array($array)) return $result;
        foreach ($array as $key => $value) {
            if (is_array($value)) { $this->flattenArray($value, $result); } 
            else { $result[$key] = $value; }
        }
        return $result;
    }

    private function findXmlFile($dir) {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $found = $this->findXmlFile($path);
                if ($found) return $found;
            } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xml') {
                return $path;
            }
        }
        return false;
    }

    private function deleteDir($dir) {
        if (!is_dir($dir)) return;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        return rmdir($dir);
    }
    /********************************IND TEAM PART ************************************************************************/
}