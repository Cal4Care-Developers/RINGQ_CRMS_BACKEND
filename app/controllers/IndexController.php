<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
require_once __DIR__ . '/../library/PhoneKeys.php'; // [blf-keys]
require_once __DIR__ . '/../library/PersonName.php';
require_once __DIR__ . '/../library/QueueName.php';
use ElephantIO\Client;
use Phalcon\Db\Column;
use Netflie\WhatsAppCloudApi\WebHook;
use Netflie\WhatsAppCloudApi\WhatsAppCloudAPI;
use Netflie\WhatsAppCloudApi\Message\Media\LinkID;
use Netflie\WhatsAppCloudApi\Message\Media\MediaObjectID;
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportBase.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportQueue.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportAgent.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportExtension.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportIvrHotel.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportPlayground.php';
require_once __DIR__ . '/../library/ApiSupport/ApiSupportCallMonitor.php';
require_once __DIR__ . '/../library/PhoneAdmin.php';

class IndexController extends ControllerBase
{
    // Helpers for the admin API actions at the end of this class (queues, agents, extensions, IVR, hotel, playground)
    // and for the live-call APIs (join, listen, whisper, barge in).
    use ApiSupportBase, ApiSupportQueue, ApiSupportAgent, ApiSupportExtension, ApiSupportIvrHotel, ApiSupportPlayground, ApiSupportCallMonitor;

    /**
     * [snom-router-phone] sip-proxy.yaml for a Snom router phone, built from the tunnel picked as
     * "Tunnel Router Phone" in the extension's Auto Provisioning. Same layout as the RingQ NX
     * device config; generated on every request so it always carries the tunnel's current key.
     */
    private function routerPhoneYaml($vUser, $vExtension, $vDomains, $cleanMac) {
        $notFound = function () {
            $this->response->setStatusCode(404, "Not Found");
            return $this->response->setContent("");
        };
        if (!$vExtension) {
            return $notFound();
        }
        $fqdn = $vDomains->domain_name;
        $tunnelId = $this->routerTunnelIdFor($vExtension, $fqdn, $cleanMac);
        if ($tunnelId === "") {
            return $notFound();
        }
        $tunnel = $this->db->fetchOne(
            "SELECT auth_key, device_id, device_local_ip, device_public_ip
               FROM tunnel_config
              WHERE id = :id AND router_phone = true AND deleted_at IS NULL",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["id" => (int) $tunnelId]
        );
        if (!$tunnel) {
            return $notFound();
        }

        $ipOk = function ($ip) {
            return filter_var(trim((string) $ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        };
        // The phone's own LAN / public address: last tunnel heartbeat first; before the first
        // heartbeat listen on all interfaces and advertise the address this request came from.
        $localIp  = $ipOk($tunnel["device_local_ip"] ?? "") ? trim($tunnel["device_local_ip"]) : "0.0.0.0";
        $clientIp = (string) $this->request->getClientAddress(true);
        $publicIp = $ipOk($tunnel["device_public_ip"] ?? "") ? trim($tunnel["device_public_ip"])
            : ($ipOk($clientIp) ? $clientIp : $localIp);
        $deviceId = trim((string) ($tunnel["device_id"] ?? ""));
        if ($deviceId === "") {
            // Stable per tunnel + phone, so the first bind and every reconnect send the same id.
            $deviceId = md5($tunnel["auth_key"] . ":" . $cleanMac);
        }

        $q = function ($v) {
            return '"' . addcslashes((string) $v, "\"\\") . '"';
        };
        $hostPair = $localIp . "," . $publicIp;
        $hosts = [
            "  - name: " . $q($fqdn),
            "    ip: " . $q($fqdn),
            "  - name: " . $q($hostPair),
            "    ip: " . $q($localIp),
        ];
        $lines = [
            "# RingQ NX Device SIP Proxy Configuration",
            "# Generated for extension " . $vExtension->extension,
            "",
            "admin:",
            "  addr: " . $q($localIp . ":8899"), // [snom-lanip v3] the phone's LAN IP (just reported)
            "",
            "proxies:",
            "  - name: \"RingQ-Proxy\"",
            "    auth-key: " . $q($tunnel["auth_key"]),
            "    device-id: " . $q($deviceId),
            "    pbx-domain: " . $q($fqdn),
            "    pbx-api-url: " . $q("https://" . $fqdn . ":443"),
            "    dialog-timeout: 1200",
            "    must-record-route: true",
            "    keep-next-hop-route: \"no\"",
            "",
            "    listens:",
            "      - address: " . $q($localIp),
            "        udp-port: 5060",
            "        tcp-port: 5061",
            "        via: " . $q("udp://" . $publicIp . ":5060"),
            "        backends:",
            "          - address: " . $q("tcp://" . $fqdn . ":6010"),
            "",
            "    route:",
            "      - dests: [" . $q($fqdn) . "]",
            "        protocol: tcp",
            "        nexthop: " . $q($fqdn . ":6010"),
            "      - dests: [\"default\"]",
            "        protocol: tcp",
            "        nexthop: " . $q($fqdn . ":6010"),
            "",
            "    hosts:",
        ];
        foreach ($hosts as $h) {
            $lines[] = "    " . $h;
        }
        $lines[] = "";
        $lines[] = "hosts:";
        foreach ($hosts as $h) {
            $lines[] = $h;
        }

        $this->response->setStatusCode(200, "OK")
            ->setHeader("Content-Type", "application/x-yaml; charset=UTF-8")
            ->setHeader("Content-Disposition", "attachment; filename=\"sip-proxy.yaml\"")
            ->setHeader("Cache-Control", "no-cache, must-revalidate")
            ->setHeader("Pragma", "no-cache");
        return $this->response->setContent(implode("\n", $lines) . "\n");
    }

    /** Tunnel id saved as this MAC's "Tunnel Router Phone" (7th segment of the device list), or "". */
    private function routerTunnelIdFor($vExtension, $fqdn, $cleanMac) {
        foreach ((array) $this->redis->lrange($vExtension->extension . "@" . $fqdn . "-macAddresses", 0, -1) as $entry) {
            $parts = explode("@", (string) $entry);
            if (count($parts) > 6 && strtolower(str_replace([':', '-'], '', $parts[2])) === $cleanMac) {
                $id = trim($parts[6]);
                return ctype_digit($id) ? $id : "";
            }
        }
        return "";
    }

    /**
     * [snom-router-phone] The phone calls <mac>.ip?ip=$ip (Snom action_setup_url) after it boots, so its
     * LAN IP is picked up automatically: stored on the tunnel and written into this phone's settings.xml
     * as user_outbound 1, so the next provisioning registers through the proxy on that address.
     */
    private function routerPhoneReportIp($vUser, $vExtension, $vDomains, $cleanMac) {
        $this->response->setHeader("Content-Type", "text/plain; charset=UTF-8");
        // First of ip / ip2 that the phone actually filled in (an unknown variable arrives as literal text).
        $ip = "";
        foreach (["ip", "ip2"] as $param) {
            $candidate = trim((string) $this->request->getQuery($param));
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $candidate !== "0.0.0.0" && strpos($candidate, "127.") !== 0) {
                $ip = $candidate;
                break;
            }
        }
        $valid = $ip !== "";
        $tunnelId = $vExtension ? $this->routerTunnelIdFor($vExtension, $vDomains->domain_name, $cleanMac) : "";
        if (!$valid || $tunnelId === "") {
            $this->response->setStatusCode(404, "Not Found");
            return $this->response->setContent("");
        }
        // [snom-manualip] The LAN IP is typed on the Tunnels page now; a report from a phone still running
        // an older settings.xml is only logged, never stored.
        // [snom-lanip v3] settings.xml gets this IP when it is next served (withRouterPhoneLanIp).
        error_log("Router phone {$cleanMac} reported LAN IP {$ip} (tunnel {$tunnelId})");
        $this->response->setStatusCode(200, "OK");
        return $this->response->setContent("ok\n");
    }

    /**
     * [snom-lanip v3] Router phone: Registrar = the phone's LAN IP (RingQ proxy there) as last reported, no outbound proxy
     * (<mac>.ip after boot). After a DHCP change the phone boots with the old address, reports the new one,
     * and gets it on its next settings refresh (settings_refresh_timer), with no reboot.
     */
    private function withRouterPhoneLanIp(string $content, $vExtension, $tenant, $mac): string
    {
        try {
            $cleanMac = strtolower(str_replace([':', '-', '.'], '', (string) $mac));
            if (!$vExtension || $content === "") {
                return $content;
            }
            $tunnelId = $this->routerTunnelIdFor($vExtension, (string) $tenant, $cleanMac);
            if ($tunnelId === "") {
                return $content;
            }
            $row = $this->db->fetchOne(
                "SELECT device_local_ip FROM tunnel_config WHERE id = :id AND router_phone = true AND deleted_at IS NULL",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ["id" => (int) $tunnelId]
            );
            $lanIp = trim((string) ($row["device_local_ip"] ?? ""));
            if (!filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $lanIp === "0.0.0.0") {
                return $content;
            }
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($content);
            if ($doc === false) {
                return $content;
            }
            foreach ($doc->xpath('//user_host[@idx="1"]') ?: [] as $node) {
                $node[0] = $lanIp . ":5060"; // the RingQ proxy on the phone listens on 5060
            }
            foreach ($doc->xpath('//user_outbound[@idx="1"]') ?: [] as $node) {
                $node[0] = "";
            }
            // [snom-router-reg] short registration: if the PBX drops the registration carried by the proxy's
            // TCP link, the phone's next REGISTER (within 5 min, not 1 h) restores inbound calls.
            $expiry = $doc->xpath('//user_expiry[@idx="1"]') ?: [];
            foreach ($expiry as $node) {
                $node[0] = "300";
            }
            $settings = $doc->xpath('//phone-settings') ?: [];
            if (!$expiry && $settings) {
                $node = $settings[0]->addChild("user_expiry", "300");
                $node->addAttribute("idx", "1");
                $node->addAttribute("perm", "");
            }
            return $doc->asXML();
        } catch (\Throwable $e) {
            error_log("withRouterPhoneLanIp: " . $e->getMessage());
            return $content;
        }
    }

    /**
     * [snom-clean] Snom templates were made from one phone's settings dump, so they carry that phone's own
     * state: its firmware/u-boot version, its SIP instance id (user_uid) and its Snom device-management
     * (TR-369) identity. A stock-firmware phone given another phone's identity keeps being told to update
     * and reboot. Drop those lines and turn TR-369 off; every other setting is served as saved.
     */
    private function withoutSnomDeviceState(string $content): string
    {
        static $stateTags = [
            "firmware_version", "uboot_version", "tr69_params", "system_time", "was_never_registered",
            "user_was_registered", "user_uid", "tr369_mqtt_topic", "tr369_controller_subscriptionid",
        ];
        try {
            if ($content === "") {
                return $content;
            }
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            if (!$dom->loadXML($content)) {
                return $content;
            }
            $xp = new \DOMXPath($dom);
            foreach ($stateTags as $tag) {
                foreach (iterator_to_array($xp->query("//" . $tag)) as $node) {
                    $node->parentNode->removeChild($node);
                }
            }
            foreach ($xp->query("//tr369_enable") as $node) {
                $node->nodeValue = "off";
            }
            // [snom-callerid-from] caller name/number from the From header only: P-Asserted-Identity /
            // Remote-Party-ID updates showed the far end's SIP user ("Outbound Call" + hex id) on connected calls.
            // Served on every refresh, so it no longer reverts to the template's "INFO PAI RPID FROM".
            $sources = iterator_to_array($xp->query("//contact_source_sip_priority"));
            foreach ($sources as $node) {
                $node->nodeValue = "INFO FROM";
            }
            $settings = $xp->query("//phone-settings")->item(0);
            if (!$sources && $settings) {
                $node = $dom->createElement("contact_source_sip_priority", "INFO FROM");
                $node->setAttribute("idx", "1");
                $node->setAttribute("perm", "");
                $settings->appendChild($node);
            }
            return $dom->saveXML();
        } catch (\Throwable $e) {
            error_log("withoutSnomDeviceState: " . $e->getMessage());
            return $content;
        }
    }

    /** [desk-phone-actions] the phone's web login (+ Yealink reboot-on-NOTIFY) in the served config. */
    private function withPhoneWebLogin(int $type, string $content, $tenant, $mac): string
    {
        try {
            $password = \PhoneAdmin::webPassword($this->redis, (string) $tenant, (string) $mac, true);
            return $password === null ? $content : \PhoneAdmin::applyToConfig($type, $content, $password);
        } catch (\Throwable $e) {
            error_log("withPhoneWebLogin: " . $e->getMessage());
            return $content;
        }
    }

    public function indexAction() { 
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        if ($this->request->getQuery("rq_desk_aux") !== null) { return $this->deskAux(); } // [desk-features]
        $this->response->setHeader("Content-Type", "application/json");
        $userAgent2 = $this->request->getUserAgent();
        $userAgent = $userAgent2;
        $provId = $this->dispatcher->getParam("id");
        $macAddress = $this->dispatcher->getParam("mac");

        error_log("Provisioning request received. User-Agent: $userAgent, Provisioning ID: $provId, MAC Address: $macAddress");

        // [snom-router-phone] <mac>.yaml = the RingQ proxy config a Snom router phone downloads.
        $wantsRouterYaml = !empty($macAddress) && str_ends_with(strtolower((string) $macAddress), ".yaml");
        // <mac>.ip?ip=... = the router phone reporting its LAN IP after boot.
        $wantsRouterIp = !empty($macAddress) && str_ends_with(strtolower((string) $macAddress), ".ip");
        if (!empty($macAddress)) {
            $macAddress = str_replace(".yaml", "", $macAddress);
            if ($wantsRouterIp) {
                $macAddress = substr($macAddress, 0, -3);
            }
            $macAddress = str_replace(".cfg", "", $macAddress);
            $macAddress = str_replace(".boot", "", $macAddress);
            $macAddress = str_replace(".xml", "", $macAddress);
            $macAddress = str_replace(".htm", "", $macAddress);
            // Grandstream prefixes MAC with "cfg" or "dev" in its request filename
            $lcMac = strtolower($macAddress);
            if (str_starts_with($lcMac, 'cfg')) {
                $macAddress = substr($macAddress, 3);
            } elseif (str_starts_with($lcMac, 'dev')) {
                $macAddress = substr($macAddress, 3);
            }
        } else {
            if (preg_match('/([0-9A-Fa-f]{2}[:\-]?){5}[0-9A-Fa-f]{2}/', $userAgent, $matches)) { 
                $macAddress = strtolower(str_replace([':', '-'], '', $matches[0]));
            } else {
                $macAddress = $this->filter->sanitize($userAgent, "string");
            }
        }
        $type = 1;
        $ip = $this->request->getServerAddress();
        $hostname = $this->request->getHttpHost();
        $hostname = $this->filter->sanitize($hostname, "string");
        if (str_contains($hostname, ":")) {
            $hostname = explode(":", $hostname);
            $hostname = current($hostname);
        } 
        
        // Get parameters correctly now that router is fixed
        if (empty($provId)) {
            $provId = $this->dispatcher->getParam("id");
        }
        if (empty($macAddress)) {
            $macAddress = $this->dispatcher->getParam("mac");
        }

        $vDomains = VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => [
                "domain_name" => $hostname,
            ],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
                
        // Local-LAN provisioning: a phone pointed at the server's LAN IP (DHCP option 66 or the
        // "Host URL" picked in Auto Provisioning) sends Host = that IP, which is no tenant domain.
        // Resolve the tenant from the MAC instead -- only when exactly one user carries it.
        if (!$vDomains && filter_var($hostname, FILTER_VALIDATE_IP) && !empty($macAddress)
            && preg_match('/^[0-9a-f]{12}$/i', (string) $macAddress)) {
            $macUsers = VUsers::find([
                "conditions" => "add_user LIKE :mac: OR add_date LIKE :mac:",
                "bind" => ["mac" => "%" . strtolower($macAddress) . "%"],
                "bindTypes" => [Column::BIND_PARAM_STR],
                "limit" => 2,
            ]);
            if (count($macUsers) === 1) {
                $vDomains = VDomains::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" => ["domain_uuid" => $macUsers[0]->domain_uuid],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]);
            }
        }

        if (!$vDomains) {
            return;
        } 
        $vUser = VUsers::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND (add_user LIKE :add_user: OR add_date LIKE :add_date:)",
            "bind" => [
                "domain_uuid" => $vDomains->domain_uuid,
                "add_user" => "%". $macAddress ."%",
                "add_date" => "%". $macAddress ."%",
            ],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]); 
        if (!empty($userAgent2)) {
            if (stripos($userAgent2, 'Yealink') !== false) {
                $type = 1;
            }
            if (stripos($userAgent2, 'Fanvil') !== false) {
                $type = 2;
            }
            if (stripos($userAgent2, 'snom') !== false) {
                $type = 3;
            }
            if (stripos($userAgent2, 'grandstream') !== false) {
                $type = 4;
            }
        }   
       
        if ($vUser) {
            try {
                $parameters["extension"] = $vUser->vExtensionUsers->vExtensions->extension;
                $session["domain_uuid"] = $vUser->domain_uuid;
                $session["subdomain"] = $vDomains->domain_name;
                $vDomain = VDomains::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid:",
                    "bind" => [
                        "domain_uuid" => $session["domain_uuid"],
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]);
                $vExtension = VExtensions::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid: AND extension = :extension:",
                    "bind" => [
                        "domain_uuid" => $session["domain_uuid"],
                        "extension" => intval($parameters["extension"]),
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                ]); 
                $vUserSetting = VUserSettings::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                    "bind" => [
                        "domain_uuid" => $session["domain_uuid"],
                        "user_setting_subcategory" => "time_zone",
                    ],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                ]);
                $stun = "stun.ringq.com";
                $timezone = date_default_timezone_get();
                if ($vUserSetting) {
                    $timezone = $vUserSetting->user_setting_value;
                }
                $macAddresses = $vUser->add_date;
                $macAddresses = explode(",", $macAddresses);
                $macStun = $this->redis->get($vExtension->extension . "@" . $session["subdomain"] . "-macStun");
                $macTimezone = $this->redis->get($vExtension->extension . "@" . $session["subdomain"] . "-macTimezone");
                if ($macStun) {
                    $macStun = explode(",", $macStun);
                    $index = array_search($macAddress, $macAddresses);
                    $stun = $macStun[$index];
                }
                if ($macTimezone) {
                    $macTimezone = explode(",", $macTimezone);
                    $index = array_search($macAddress, $macAddresses);
                    $timezone = $macTimezone[$index];
                } 
            } catch (\Throwable $e) {
                error_log(json_encode([
                    "result" => "error 2",
                    "message" => "Something went wrong.",
                    "debug"   => [
                        "error"   => ControllerBase::safeErrorMessage($e),
                        "file"    => $e->getFile(),
                        "line"    => $e->getLine(),
                    ],
                ], JSON_PRETTY_PRINT));
            } 
            if ($wantsRouterIp) {
                return $this->routerPhoneReportIp($vUser, $vExtension ?? null, $vDomains, strtolower((string) $macAddress));
            }
            if ($wantsRouterYaml) {
                return $this->routerPhoneYaml($vUser, $vExtension ?? null, $vDomains, strtolower((string) $macAddress));
            }
            if ($type == 1) { // Yealink (.cfg)
                $customProvisionKey = "provision:custom:{$macAddress}:cfg:" . $vUser->username;
                if ($this->redis->exists($customProvisionKey)) {
                    $content = $this->redis->get($customProvisionKey); 
                    $content = $this->withPhoneKeys(1, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]
                    $lines = explode("\n", $content); 
                    $config = array_map('trim', $lines);

                    $configContent = $this->withPhoneWebLogin(1, implode("\n", $config), $vDomains->domain_name, $macAddress);  
                    return $this->response
                        ->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/plain")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache")
                        ->setContent($configContent);
                }

                $provisionKey = "provision:yealink:{$macAddress}:cfg:" . $vUser->username;
                if ($this->redis->exists($provisionKey)) {
                    $content = $this->redis->get($provisionKey); 
                    $content = $this->withPhoneKeys(1, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]
                    $lines = explode("\n", $content); 
                    $config = array_map('trim', $lines);

                    $configContent = $this->withPhoneWebLogin(1, implode("\n", $config), $vDomains->domain_name, $macAddress);  
                    return $this->response
                        ->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/plain")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache")
                        ->setContent($configContent);
                }
            }
            elseif ($type == 2) { // Fanvil (.xml)
                $customProvisionKey = "provision:custom:{$macAddress}:xml:" . $vUser->username;
                if ($this->redis->exists($customProvisionKey)) {
                    $content = $this->redis->get($customProvisionKey);
                    $content = $this->withPhoneWebLogin(2, (string) $content, $vDomains->domain_name, $macAddress); 
                    $content = $this->withPhoneKeys(2, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]

                    $this->response->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/xml; charset=UTF-8")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache");

                    return $this->response->setContent($content);
                }

                $provisionKey = 'provision:fanvil:'. $macAddress .':xml:' . $vUser->username; 
                if ($this->redis->exists($provisionKey)) {
                    $content = $this->redis->get($provisionKey);
                    $content = $this->withPhoneWebLogin(2, (string) $content, $vDomains->domain_name, $macAddress); 
                    $content = $this->withPhoneKeys(2, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]

                    $this->response->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/xml; charset=UTF-8")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache");

                    return $this->response->setContent($content);
                }
            }
            elseif ($type == 3) { // snom (.xml) 
                $customProvisionKey = "provision:custom:{$macAddress}:xml:" . $vUser->username;
                if ($this->redis->exists($customProvisionKey)) {
                    $content = $this->redis->get($customProvisionKey);
                    $content = $this->withPhoneWebLogin(3, (string) $content, $vDomains->domain_name, $macAddress);
                    $content = $this->withPhoneKeys(3, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]

                    $this->response->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/xml; charset=UTF-8")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache");

                    return $this->response->setContent($content);
                }

                $provisionKey = 'provision:snom:'. $macAddress .':xml:' . $vUser->username; 
                if ($this->redis->exists($provisionKey)) {
                    $content = $this->redis->get($provisionKey);
                    $content = $this->withRouterPhoneLanIp((string) $content, $vExtension ?? null, $vDomains->domain_name, $macAddress);
                    $content = $this->withoutSnomDeviceState((string) $content); // [snom-clean]
                    $content = $this->withPhoneWebLogin(3, (string) $content, $vDomains->domain_name, $macAddress);
                    $content = $this->withPhoneKeys(3, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys]

                    $this->response->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/xml; charset=UTF-8")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache");

                    return $this->response->setContent($content);
                }
            }
            elseif ($type == 4) { // grandstream (.xml)
                $provisionKey = "provision:grandstream:{$macAddress}:xml:" . $vUser->username;
                $keyExists = $this->redis->exists($provisionKey);
                if ($keyExists) {
                    $content = $this->redis->get($provisionKey); 
                    $content = $this->withPhoneKeys(4, (string) $content, $vDomains, $vUser, $macAddress); // [blf-keys] [blf-keys-gs]
                    return $this->response
                        ->setStatusCode(200, "OK")
                        ->setHeader("Content-Type", "text/xml")
                        ->setHeader("Cache-Control", "no-cache, must-revalidate")
                        ->setHeader("Pragma", "no-cache")
                        ->setContent($content);
                }
            }
        }
    }

    /**
     * [desk-aux] Agent Status / Change Status keys on desk phones: the phone dials *26<aux code id>,
     * the dial plan ringq_desk_aux runs ringq_desk_aux.lua, which calls this from the server itself
     * (127.0.0.1 only). Sets the extension's aux code the same way the dialer's Agent Status does
     * (api/auxcodes, auxcode_type 3, all queues).
     */
    private function deskAux()
    {
        $this->response->setHeader("Content-Type", "application/json");
        $remote = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
        if ($remote !== "127.0.0.1" && $remote !== "::1") {
            return $this->response->setStatusCode(403, "Forbidden")->setContent(json_encode(["result" => "error"]));
        }
        $domain = (string) $this->request->getQuery("domain", "string", "");
        $user = (string) $this->request->getQuery("user", "string", "");
        $auxId = (int) $this->request->getQuery("aux", "int", 0);
        $vDomains = VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $domain],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        $vExtension = $vDomains ? VExtensions::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND (number_alias = :alias: OR extension = :extension:)",
            "bind" => ["domain_uuid" => $vDomains->domain_uuid, "alias" => $user, "extension" => $user],
            "bindTypes" => ["domain_uuid" => Column::BIND_PARAM_STR, "alias" => Column::BIND_PARAM_STR, "extension" => Column::BIND_PARAM_STR],
        ]) : null;
        $qAuxcode = $vDomains ? QAuxcodes::findFirst([
            "conditions" => "id = :id: AND domain_uuid = :domain_uuid:",
            "bind" => ["id" => $auxId, "domain_uuid" => $vDomains->domain_uuid],
            "bindTypes" => ["id" => Column::BIND_PARAM_INT, "domain_uuid" => Column::BIND_PARAM_STR],
        ]) : null;
        $userUuid = $vExtension && $vExtension->vExtensionUsers ? (string) $vExtension->vExtensionUsers->user_uuid : "";
        if (!$vExtension || !$qAuxcode || $userUuid === "") {
            return $this->response->setStatusCode(404, "Not Found")->setContent(json_encode(["result" => "error", "message" => "unknown extension or aux code"]));
        }
        $extension = (string) $vExtension->extension;
        $status = ucwords((string) $qAuxcode->auxcode_status);
        $vUser = VUsers::findFirst([
            "conditions" => "user_uuid = :user_uuid:",
            "bind" => ["user_uuid" => $userUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        $qAuxcodesList = new QAuxcodesList();
        $qAuxcodesList->auxcode_uuid = $qAuxcode->auxcode_uuid;
        $qAuxcodesList->domain_uuid = $vDomains->domain_uuid;
        $qAuxcodesList->auxcode_status = $status;
        $qAuxcodesList->insert_date = date("Y-m-d H:i:s P");
        $qAuxcodesList->user_uuid = $userUuid;
        $qAuxcodesList->username = $vUser && $vUser->qUsers ? trim($vUser->qUsers->first_name . " " . $vUser->qUsers->last_name) : $extension;
        $qAuxcodesList->extension = $extension . " | 0";
        if (!$qAuxcodesList->create()) {
            return $this->response->setStatusCode(500, "Error")->setContent(json_encode(["result" => "error", "message" => "could not save"]));
        }
        $this->redis->set($extension . "-" . $vDomains->domain_name . "-auxcode", $qAuxcode->auxcode_uuid);
        $vCallCenterAgents = VCallCenterAgents::find([
            "conditions" => "user_uuid = :user_uuid: AND domain_uuid = :domain_uuid: AND queue_extension IS NOT NULL",
            "bind" => ["user_uuid" => $userUuid, "domain_uuid" => $vDomains->domain_uuid],
            "bindTypes" => ["user_uuid" => Column::BIND_PARAM_STR, "domain_uuid" => Column::BIND_PARAM_STR],
        ]);
        foreach ($vCallCenterAgents as $vCallCenterAgent) {
            $vCallCenterAgent->agent_status = $status;
            $vCallCenterAgent->update();
            foreach (["/var/cache/fusionpbx/", "/var/cache/ringq/"] as $dir) {
                if (file_exists($dir . "configuration.callcenter.conf." . gethostname())) {
                    unlink($dir . "configuration.callcenter.conf." . gethostname());
                }
            }
            try {
                $client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", ["persistent" => false]));
                $client->initialize();
                $client->emit('agentstatus', ["agentuuid" => $vCallCenterAgent->call_center_agent_uuid, "aux_uuid" => $qAuxcode->auxcode_uuid, "extension" => $extension, "status" => $status != "Available" ? "On Break" : "Available", "subdomain" => $vDomains->domain_name, "auxcode" => $status, "queue_extension" => "0"]);
            } catch (\Throwable $e) {
                error_log("deskAux emit: " . $e->getMessage());
            }
        }
        return $this->response->setStatusCode(200, "OK")->setContent(json_encode(["result" => "success", "status" => $status]));
    }

    /** [blf-keys] the extension's BLF page rows (BLF, Speed Dial) as programmable keys in the served config. */
    private function withPhoneKeys(int $type, string $content, $vDomains, $vUser, $mac): string
    {
        try {
            $extension = (string) $vUser->vExtensionUsers->vExtensions->extension;
            $keys = \PhoneKeys::keysFor((string) $vUser->domain_uuid, $extension);
            $content = \PhoneKeys::applyFeatures($type, $content, $extension); // [desk-features]
            return \PhoneKeys::applyToConfig($this->redis, $type, $content, (string) $vDomains->domain_name, (string) $mac, $keys);
        } catch (\Throwable $e) {
            error_log("withPhoneKeys: " . $e->getMessage());
            return $content;
        }
    }

    public function whatsappAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $webhook = new WebHook();
        $verificationResult = $webhook->verify($_GET, "12345678932154646456554");
        $payload = file_get_contents("php://input");

        echo $verificationResult;



        $data = json_decode($payload, true);


        // Check if 'messages' field exists in the entry
        if (empty($data['entry'][0]['changes'][0]['value']['messages'])) {
            error_log('Message delivery status detected. Returning early.');
            echo http_response_code(200);
            return; // Early return if there is no message to process
        }

        // Initialize media variables
        $mediaId = null;
        $mediaType = null;

        // Check for different media types
        if (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['image']['id'])) {
            $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['image']['id'];
            $mediaType = 'image';
        } elseif (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['video']['id'])) {
            $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['video']['id'];
            $mediaType = 'video';
        } elseif (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['audio']['id'])) {
            $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['audio']['id'];
            $mediaType = 'audio';
        } elseif (isset($data['entry'][0]['changes'][0]['value']['messages'][0]['document']['id'])) {
            $mediaId = $data['entry'][0]['changes'][0]['value']['messages'][0]['document']['id'];
            $mediaType = 'document';
        }

        // Proceed if mediaId is found
        if ($mediaId && $mediaType) {

            // Your WhatsApp access token
            $accessToken = 'EAAJGPZCYzhCEBO2HzT79rt11aNAOiPkTT0zMZB90rY7EdxNnuDrF537RaP0OnofL9yomefcNJIaYt2g0iKnV76u2iv5lZC7tR0ZAT7mZCLEg0QGCe8FJ4VHZALz1DJIzLnOI0jZBBxn80ONZBbp7u706WtiMaVPDiHP3NlwCeIBkos1lwH5QmxYFmWTMS09ZBq8gZAfwZDZD'; // Replace with your actual access token

            // Initialize cURL session to retrieve media URL
            $ch = curl_init();

            // Set the URL for the request
            $url = 'https://graph.facebook.com/v15.0/' . $mediaId;

            // Define the headers
            $headers = [
                'User-Agent: WhatsApp/2.19.81 A',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
                'Cookie: ps_l=1; ps_n=1'
            ];

            // Set the cURL options
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Execute cURL session
            $response = curl_exec($ch);
        

            // Check for cURL errors
            if (curl_errno($ch)) {
                error_log('cURL error: ' . curl_error($ch));
                curl_close($ch);
                exit;
            }

            // Get the HTTP status code
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode != 200) {
                error_log('HTTP error code: ' . $httpCode . '. Response: ' . $response);
                curl_close($ch);
                exit;
            }

            // Close cURL session
            curl_close($ch);

            // Decode the JSON response
            $result = json_decode($response, true);

            // Check if JSON decoding was successful
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON decoding error: ' . json_last_error_msg());
                error_log('Response content: ' . $response);
                exit;
            }

            // // Log the decoded result for debugging
            // error_log('Decoded response: ' . print_r($result, true));

            // Retrieve media URL from response
            if (isset($result['url'])) {
                $mediaUrl = $result['url'];

                // Initialize cURL session to download the media
                $mediaCh = curl_init();

                // Set the cURL options
                curl_setopt($mediaCh, CURLOPT_URL, $mediaUrl);
                curl_setopt($mediaCh, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($mediaCh, CURLOPT_HTTPHEADER, $headers);

                // Execute cURL session to get media content
                $mediaResponse = curl_exec($mediaCh);

                // Check for cURL errors
                if (curl_errno($mediaCh)) {
                    error_log('cURL error: ' . curl_error($mediaCh));
                    curl_close($mediaCh);
                    exit;
                }

                // Get the HTTP status code
                $mediaHttpCode = curl_getinfo($mediaCh, CURLINFO_HTTP_CODE);
                if ($mediaHttpCode != 200) {
                    error_log('HTTP error code: ' . $mediaHttpCode . '. Response: ' . $mediaResponse);
                    curl_close($mediaCh);
                    exit;
                }

                // Determine the file extension and path based on MIME type
                switch ($result['mime_type']) {
                    // Audio Types
                    case 'audio/aac':
                        $fileExtension = '.aac';
                        $filePath = '/var/www/ringq/public/wp_audio/';
                        break;
                    case 'audio/amr':
                        $fileExtension = '.amr';
                        $filePath = '/var/www/ringq/public/wp_audio/';
                        break;
                    case 'audio/mpeg':
                        $fileExtension = '.mp3';
                        $filePath = '/var/www/ringq/public/wp_audio/';
                        break;
                    case 'audio/mp4':
                        $fileExtension = '.m4a';
                        $filePath = '/var/www/ringq/public/wp_audio/';
                        break;
                    case 'audio/ogg':
                        $fileExtension = '.ogg';
                        $filePath = '/var/www/ringq/public/wp_audio/';
                        break;

                    // Document Types
                    case 'text/plain':
                        $fileExtension = '.txt';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/vnd.ms-excel':
                        $fileExtension = '.xls';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                        $fileExtension = '.xlsx';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/msword':
                        $fileExtension = '.doc';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                        $fileExtension = '.docx';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/vnd.ms-powerpoint':
                        $fileExtension = '.ppt';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
                        $fileExtension = '.pptx';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                    case 'application/pdf':
                        $fileExtension = '.pdf';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;

                    // Image Types
                    case 'image/jpeg':
                        $fileExtension = '.jpeg';
                        $filePath = '/var/www/ringq/public/wp_img/';
                        break;
                    case 'image/png':
                        $fileExtension = '.png';
                        $filePath = '/var/www/ringq/public/wp_img/';
                        break;
                    case 'image/webp':
                        $fileExtension = '.webp';
                        $filePath = '/var/www/ringq/public/wp_img/';
                        break;

                    // Video Types
                    case 'video/3gp':
                        $fileExtension = '.3gp';
                        $filePath = '/var/www/ringq/public/wp_vid/';
                        break;
                    case 'video/mp4':
                        $fileExtension = '.mp4';
                        $filePath = '/var/www/ringq/public/wp_vid/';
                        break;

                    // Default case for unsupported types
                    default:
                        $fileExtension = '.bin';
                        $filePath = '/var/www/ringq/public/wp_file/';
                        break;
                }

                // Generate a unique filename with the determined extension
                $fileName = uniqid() . $fileExtension;
                $fullPath = $filePath . $fileName;







                // Save the media file
                file_put_contents($fullPath, $mediaResponse);
                error_log('Media saved to: ' . $fullPath);



                $payload = json_decode($payload, true);

                $payload['filePath'] = $fullPath;

                $payload = json_encode($payload); // Changed encode to json_encode


                // error_log($payload);
                // Close cURL session
                curl_close($mediaCh);
            } else {
                error_log('Media URL not found in the response.');
            }

        }


        echo http_response_code(200);
        $client = new Client(
            Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
                "persistent" => false,
            ])
        );
        $client->initialize();
        $content = (array) json_decode(json_encode($payload), true);
        $client->emit("whatsapp", $content);
        return $this->response;
    }

    public function whatsappsendAction() { 
        $url = "https://graph.facebook.com/v18.0/111822498634993/messages"; 
        $accessToken = "EAAJGPZCYzhCEBOxx63ibZCZALJczfntZB9U0dLdigX89Xf4J2609fOZB7nvfNdDZC54JbI2W17d4QGZBsR9K9ksgVmlbQVYKY6vweYGYnbjorGjN53b1ppOkCMmvwzjvstHajbHJQlp6tlvHlJbdt2Cb07wAETwudgGjqZBXVrkYXioGu2ZAeEWIDkqZANVUOmjy0BZBVOua9YCbKCaZCPZBbb6c0hpBZBf77c1YmZAIS0ZD";
        $headers = ["Authorization: Bearer " . $accessToken, "Content-Type: application/json"];
        $data = [
            "messaging_product" => "whatsapp",
            "to" => "639919400990",
            "type" => "template",
            "template" => [
                "name" => "hello_world",
                "language" => ["code" => "en_US"],
            ],
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
    }

    public function licenseAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);

        sleep(2);

        $host = $this->request->getHttpHost();
        $host = preg_replace('/:\d+$/', '', $host);
        $url = 'http://' . $host . '/license';
        return $this->response->redirect($url, true);
    }

    private function authenticate()
    {
        require_once __DIR__ . '/../library/ApiAuth.php';
        $keyData = ApiAuth::authenticate($this->request, $this->redis);
        $body = json_decode((string) $this->request->getRawBody(), true);
        $requested = is_array($body) && array_key_exists('domain', $body)
            ? $body['domain']
            : $this->request->get('domain');
        if ($requested !== null && $requested !== ''
            && !hash_equals(strtolower((string) ($keyData['domain'] ?? '')), strtolower((string) $requested))) {
            throw new \Exception('AUTH_DOMAIN_MISMATCH');
        }
        return $keyData;
    }

    private function apiKeySession()
    {
        $session = $this->session->get('auth');
        if (!$session || empty($session['subdomain'])) {
            return null;
        }
        return $session;
    }

    private function apiKeyBelongsToSession(string $apiKey, array $session): bool
    {
        $keyData = $this->redis->hGetAll('call_api_keys:' . $apiKey);
        if (!$keyData) {
            return false;
        }
        return hash_equals(
            strtolower((string)$keyData['domain']),
            strtolower((string)$session['subdomain'])
        );
    }

    /**
     * The tenant's current API key, or null. Uses the call_api_key_by_domain:<domain> index and
     * falls back to scanning keys created before the index existed (then fills the index).
     */
    private function apiKeyForTenant(string $domain): ?string
    {
        $indexKey = 'call_api_key_by_domain:' . strtolower($domain);
        $indexed = (string)$this->redis->get($indexKey);
        if ($indexed !== '' && $this->redis->exists('call_api_keys:' . $indexed)) {
            return $indexed;
        }
        $found = null;
        // Predis: scan(cursor, options) returns [nextCursor, keys]
        $cursor = '0';
        do {
            $page = $this->redis->scan($cursor, ['MATCH' => 'call_api_keys:*', 'COUNT' => 200]);
            $cursor = (string)$page[0];
            foreach ((array)$page[1] as $redisKey) {
                $row = $this->redis->hGetAll($redisKey);
                if ($row && ($row['status'] ?? '') === 'active'
                    && hash_equals(strtolower((string)($row['domain'] ?? '')), strtolower($domain))) {
                    $found = substr((string)$redisKey, strlen('call_api_keys:'));
                    break 2;
                }
            }
        } while ($cursor !== '0');
        if ($found !== null) {
            $this->redis->set($indexKey, $found);
        } elseif ($indexed !== '') {
            $this->redis->del($indexKey);
        }
        return $found;
    }

    public function createKeyAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setContentType('application/json');

        $session = $this->apiKeySession();
        if (!$session) {
            return $this->response->setStatusCode(401, 'Unauthorized')->setJsonContent([
                'status' => 'error',
                'message' => 'Unauthorized'
            ]);
        }

        // One key per tenant: the key manager shows a single key, so a new one replaces the old.
        $previous = $this->apiKeyForTenant((string)$session['subdomain']);
        if ($previous !== null) {
            $this->redis->del('call_api_keys:' . $previous);
        }

        $apiKey = 'ringq_live_' . bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(32));

        // ✅ CORRECT REDIS CALL
        $this->redis->hMSet("call_api_keys:$apiKey", [
            'secret'     => $secret,
            'domain'     => $session['subdomain'],
            'status'     => 'active',
            'created_at' => time()
        ]);
        $this->redis->set('call_api_key_by_domain:' . strtolower((string)$session['subdomain']), $apiKey);

        // 🚨 VERY IMPORTANT: return response
        return $this->response->setJsonContent([
            'status' => 'success',
            'apiKey' => $apiKey,
            'secret' => $secret,
            'warning' => 'Save this secret securely. It will not be shown again.'
        ]);
    }


    public function revokeKeysAction()
    {
        $session = $this->apiKeySession();
        if (!$session) {
            return $this->response->setStatusCode(401, 'Unauthorized')->setJsonContent([
                'status' => 'error',
                'message' => 'Unauthorized'
            ]);
        }
        $data = $this->request->getJsonRawBody(true);
        $apiKey = trim((string)($data['apiKey'] ?? ''));
        if ($apiKey === '' || !$this->apiKeyBelongsToSession($apiKey, $session)) {
            return $this->response->setStatusCode(403, 'Forbidden')->setJsonContent([
                'status' => 'error',
                'message' => 'Key does not belong to this tenant'
            ]);
        }

        $this->redis->del('call_api_keys:' . $apiKey);
        $this->redis->del('call_api_key_by_domain:' . strtolower((string)$session['subdomain']));

        $this->response->setStatusCode(200, 'OK');
        return $this->response->setJsonContent(['status' => 'success']);
    }

    public function revokeKeyAction()
    {
        $session = $this->apiKeySession();
        if (!$session) {
            return $this->response->setStatusCode(401, 'Unauthorized')->setJsonContent([
                'status' => 'error',
                'message' => 'Unauthorized'
            ]);
        }
        $data = $this->request->getJsonRawBody(true);

        if (empty($data['apiKey'])) {
            return $this->response->setJsonContent([
                'status' => 'error',
                'message' => 'apiKey is required'
            ]);
        }

        $key = "call_api_keys:" . $data['apiKey'];

        if (!$this->redis->exists($key)) {
            return $this->response->setJsonContent([
                'status' => 'success',
                'message' => 'Key not found'
            ]);
        }

        if (!$this->apiKeyBelongsToSession((string)$data['apiKey'], $session)) {
            return $this->response->setStatusCode(403, 'Forbidden')->setJsonContent([
                'status' => 'error',
                'message' => 'Key does not belong to this tenant'
            ]);
        }

        // Delete key
        $this->redis->del($key);
        $this->redis->del('call_api_key_by_domain:' . strtolower((string)$session['subdomain']));

        return $this->response->setJsonContent([
            'status' => 'success'
        ]);
    } 

    public function rotateKeyAction()
    {
        $session = $this->apiKeySession();
        if (!$session) {
            return $this->response->setStatusCode(401, 'Unauthorized')->setJsonContent([
                'status' => 'error',
                'message' => 'Unauthorized'
            ]);
        }
        $data = $this->request->getJsonRawBody(true);
        $apiKey = trim((string)($data['apiKey'] ?? ''));
        if ($apiKey === '' || !$this->apiKeyBelongsToSession($apiKey, $session)) {
            return $this->response->setStatusCode(403, 'Forbidden')->setJsonContent([
                'status' => 'error',
                'message' => 'Key does not belong to this tenant'
            ]);
        }
        $newSecret = bin2hex(random_bytes(32));

        $this->redis->hSet('call_api_keys:' . $apiKey, 'secret', $newSecret);

        return $this->response->setJsonContent([
            'status' => 'success',
            'secret' => $newSecret,
            'warning' => 'Old secret invalid immediately'
        ]);
    } 

    public function keyStatusAction()
    {
        $this->response->setContentType('application/json');

        // Logged-in admin (the Integrations > RingQ APIs screen): return this tenant's key so it
        // survives a page refresh. Machine callers still send the signed key headers below.
        $session = $this->apiKeySession();
        if ($session && !$this->request->getHeader('X-API-KEY')) {
            $apiKey = $this->apiKeyForTenant((string)$session['subdomain']);
            if ($apiKey === null) {
                return $this->response->setJsonContent(['status' => 'none']);
            }
            $row = $this->redis->hGetAll('call_api_keys:' . $apiKey);
            return $this->response->setJsonContent([
                'status' => 'valid',
                'domain' => $session['subdomain'],
                'apiKey' => $apiKey,
                'secret' => (string)($row['secret'] ?? ''),
                'created_at' => (int)($row['created_at'] ?? 0)
            ]);
        }

        try {
            $keyData = $this->authenticate();
            return $this->response->setJsonContent([
                'status' => 'valid',
                'domain' => $keyData['domain']
            ]);
        } catch (Exception $e) {
            return $this->response->setStatusCode(401)->setJsonContent([
                'status' => 'invalid'
            ]);
        }
    }


    public function saveCallsAPIAction()
    {
        $this->view->disable();
        $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        try {
            // 1️⃣ Read JSON body
            $rawBody = $this->request->getRawBody();
            $data = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON payload");
            }

            // 2️⃣ Validate permissions
            if (empty($data['permissions']) || !is_array($data['permissions'])) {
                throw new \Exception("Missing permissions data");
            }

            $permissions = $data['permissions'];

            // 3️⃣ Session validation
            $session = $this->session->get("auth");

            if (!$session || empty($session['subdomain'])) {
                return $this->response
                    ->setStatusCode(401, "Unauthorized")
                    ->setJsonContent([
                        'result'  => 'error',
                        'message' => 'Unauthorized. Please log in.'
                    ]);
            }

            // 4️⃣ Redis key (SAFE)
            $redisKey = "call_api_permissions:" . ($session['subdomain'] ?? 'default');

            // 5️⃣ Convert boolean → int
            $redisData = [];
            foreach ($permissions as $key => $value) {
                $redisData[$key] = $value ? 1 : 0;
            }

            // 6️⃣ Save to Redis
            $this->redis->hMSet($redisKey, $redisData);

            // Optional TTL
            // $this->redis->expire($redisKey, 86400);

            return $this->response->setJsonContent([
                "result"   => "success",
                "message"  => "Permissions saved successfully",
                "redisKey" => $redisKey
            ]);

        } catch (\Exception $e) {

            return $this->response
                ->setStatusCode(400, "Bad Request")
                ->setJsonContent([
                    "result"  => "error",
                    "message" => ControllerBase::safeErrorMessage($e)
                ]);
        }
    }

    public function listCallAPIsAction()
    {
        $this->view->disable();
        $this->response->setContentType('application/json');

        // 🔐 Session check
        $session = $this->session->get('auth');
        if (!$session || empty($session['subdomain'])) {
            return $this->response
                ->setStatusCode(401, 'Unauthorized')
                ->setJsonContent([
                    'status' => 'error',
                    'message' => 'Unauthorized'
                ]);
        }

        $redisKey = 'call_api_permissions:' . $session['subdomain'];

        // ✅ Master API list (SOURCE OF TRUTH)
        $allApis = [
            'make_call',
            'pickup_call',
            'divert_call',
            'divert_voicemail',
            'listen',
            'whisper',
            'bargein',
            'record_start',
            'record_stop',
            'join_call',
            'transfer_call',
            'drop_call',
            'add_agent',
            'update_agent',
            'delete_agent',
            'query_extension',
            'hotel_module',
            'add_queue',
            'update_queue',
            'delete_queue',
            'add_agents_queue',
            'remove_agents_queue',
            'queue_login',
            'queue_logout',
            'change_user_status',
            'change_recording_status',
            'disable_extension',
            'disable_external_call',
            'send_welcome_email',
            'add_ivr',
            'delete_ivr',
            'change_vm_pin',
            'change_vm_path',
            'change_recording_path',
            'add_billing',
            'update_billing',
            'voicemail_delete',
            'voicemail_list',
            'recording_list',
            'recording_link',
            'schedule_read',
            'schedule_write',
            'schedule_delete'
        ];

        // 🔁 Fetch existing permissions from Redis
        $storedPermissions = $this->redis->hGetAll($redisKey);

        // 🧠 Merge with defaults
        $result = [];
        foreach ($allApis as $api) {
            $result[$api] = isset($storedPermissions[$api])
                ? (bool)$storedPermissions[$api]
                : false;
        }

        return $this->response->setJsonContent([
            'status' => 'success',
            'permissions' => $result
        ]);
    }

public function makeCallAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);


    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }


    if (
        empty($data['from']) ||
        empty($data['to']) ||
        empty($data['domain'])
    ) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }


    $redisKey = 'call_api_permissions:' . $data['domain'];
    $canMakeCall = $this->redis->hGet($redisKey, 'make_call');
    if ((int)$canMakeCall !== 1) {
        return $this->response
            ->setStatusCode(403, 'Forbidden')
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied: make_call'
            ]);
    }



    $from   = $data['from'];    // extension or number
    $to     = $data['to'];      // extension or number
    $domain = $data['domain'];
    // UUID v4 in PHP: shell_exec('uuidgen') was a fatal (empty 500) where shell_exec is disabled
    $rand = random_bytes(16);
    $rand[6] = chr((ord($rand[6]) & 0x0f) | 0x40);
    $rand[8] = chr((ord($rand[8]) & 0x3f) | 0x80);
    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($rand), 4));

    try {
        /* =======================
            ESL CONNECTION
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);
        if (!$socket) {
            throw new Exception("ESL connect failed: $errstr");
        }
        
        // 1️⃣ Read initial auth request headers
        $buffer = '';
        while (!feof($socket)) {
            $line = fgets($socket, 4096);
            if ($line === "\n") break;
            $buffer .= $line;
        }

        // 2️⃣ Send auth
        fwrite($socket, "auth ClueCon\n\n");

        // 3️⃣ Read auth response headers
        $authResponse = '';
        while (!feof($socket)) {
            $line = fgets($socket, 4096);
            if ($line === "\n") break;
            $authResponse .= $line;
        }
        if (strpos($authResponse, '+OK accepted') === false) {
            throw new Exception("ESL auth failed: {$authResponse}");
        }

        /* =======================
            LEG BUILDING
        ======================== */
        $ringLeg   = "user/{$from}@{$domain}";
        $bridgeLeg = "user/{$to}@{$domain}";

        /* =======================
            ORIGINATE COMMAND
        ======================== */
        $cmd = "originate ".
        "{origination_uuid={$uuid},".
        "caller_id_number={$to},".
        "caller_id_name={$to},".
        "domain_name={$domain},".
        "ignore_early_media=true}".
        "{$ringLeg} &bridge({$bridgeLeg})";
        fwrite($socket, "api {$cmd}\n\n");
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === "\n") break;
            $response .= $line;
        }
        fclose($socket);
        return $this->response->setJsonContent([
            'status' => 'successs',
            'uuid'   => $uuid,
            'result' => trim($response)
        ]);
    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function divertCallByExtensionAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);
    /* =======================
        AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }
    if (
        empty($data['ringing_extension']) ||
        empty($data['divert_extension']) ||
        empty($data['domain'])
    ) {
        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => 'Missing parameters'
        ]);
    }

$redisKey = 'call_api_permissions:' . $data['domain'];
        $canMakeCall = $this->redis->hGet($redisKey, 'divert_call');
        if ((int)$canMakeCall !== 1) {
            return $this->response
                ->setStatusCode(403, 'Forbidden')
                ->setJsonContent([
                    'status'  => 'error',
                    'message' => 'Permission denied: divert_call'
                ]);
        }


    $ringing = $data['ringing_extension'];
    $divert  = $data['divert_extension'];
    $domain  = $data['domain'];

    try {
        /* =======================
           ESL CONNECT
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);
        if (!$socket) {
            throw new Exception("ESL connect failed: {$errstr}");
        }

        // Auth handshake
        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");

        /* =======================
           FIND RINGING CALL
        ======================== */
        fwrite($socket, "api show channels\n\n");

        $contentLength = 0;
        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $contentLength = (int) trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $contentLength ? fread($socket, $contentLength) : '';
        $rows = array_filter(explode("\n", trim($body)));

        if (count($rows) < 2) {
            throw new Exception("No active calls");
        }

        $header = str_getcsv(array_shift($rows));
        $map = array_flip($header);

        foreach (['uuid', 'dest', 'callstate'] as $col) {
            if (!isset($map[$col])) {
                throw new Exception("Missing column {$col}");
            }
        }

        $uuid = null;

        foreach ($rows as $row) {
            $cols = str_getcsv($row);

            if (
                $cols[$map['dest']] == $ringing &&
                in_array($cols[$map['callstate']], ['EARLY', 'RINGING'])
            ) {
                $uuid = $cols[$map['uuid']];
                break;
            }
        }

        if (!$uuid) {
            throw new Exception("No ringing calls found on extension {$ringing}");
        }

        /* =======================
           CORRECT DIVERT (TRANSFER)
        ======================== */
        fwrite($socket, "api uuid_transfer {$uuid} {$divert} XML {$domain}\n\n");

        fclose($socket);

        return $this->response->setJsonContent([
            'status' => 'success',
            'ringing_extension' => $ringing,
            'diverted_to' => $divert,
            'uuid' => $uuid
        ]);

    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function divertCallToVoicemailAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    if (empty($data['ringing_extension']) || empty($data['domain'])) {
        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => 'Missing parameters'
        ]);
    }


$redisKey = 'call_api_permissions:' . $data['domain'];
        $canMakeCall = $this->redis->hGet($redisKey, 'divert_voicemail');
        if ((int)$canMakeCall !== 1) {
            return $this->response
                ->setStatusCode(403, 'Forbidden')
                ->setJsonContent([
                    'status'  => 'error',
                    'message' => 'Permission denied: divert_voicemail'
                ]);
        }

    $ringing = (string)$data['ringing_extension'];
    $domain  = (string)$data['domain'];

    try {
        /* =======================
           ESL CONNECT
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);
        if (!$socket) {
            throw new Exception("ESL connect failed: {$errstr}");
        }

        // Read auth request
        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");

        /* =======================
           FIND UUID (same as before)
        ======================== */
        fwrite($socket, "api show channels\n\n");

        $contentLength = 0;
        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $contentLength = (int)trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $contentLength ? fread($socket, $contentLength) : '';
        $rows = array_filter(explode("\n", trim($body)));

        if (count($rows) < 2) {
            throw new Exception("No active calls");
        }

        $header = str_getcsv(array_shift($rows));
        $map = array_flip($header);

        foreach (['uuid', 'dest', 'callstate'] as $col) {
            if (!isset($map[$col])) {
                throw new Exception("Missing column {$col}");
            }
        }

        $uuid = null;
        $targetPresence = "{$ringing}@{$domain}";

        foreach ($rows as $row) {
    $cols = str_getcsv($row);

    if (
        $cols[$map['dest']] == $ringing &&
        in_array($cols[$map['callstate']], ['EARLY', 'RINGING'])
    ) {
        $uuid = $cols[$map['uuid']];
        break;
    }
}

        if (!$uuid) {
            throw new Exception("No ringing call for extension {$ringing}");
        }

        /* =======================
           ✅ REAL FIX: TRANSFER ONLY
        ======================== */
         $vmCode = '*99' . $ringing;

fwrite(
    $socket,
    "api uuid_transfer {$uuid} {$vmCode} XML {$domain}\n\n"
);

        // IMPORTANT: read ESL response
        while (fgets($socket) !== "\n");

        fclose($socket);

        return $this->response->setJsonContent([
            'status' => 'success',
            'uuid'   => $uuid,
            'action' => 'voicemail'
        ]);

    } catch (Exception $e) {
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status' => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function pickupCallByExtensionAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       VALIDATION
    ======================== */
    if (
        empty($data['ringing_extension']) ||
        empty($data['pickup_extension']) ||
        empty($data['domain'])
    ) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }

    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       PERMISSION CHECK
    ======================== */
    $redisKey   = 'call_api_permissions:' . $data['domain'];
    $canMakeCall = $this->redis->hGet($redisKey, 'pickup_call');

    if ((int)$canMakeCall !== 1) {
        return $this->response
            ->setStatusCode(403, 'Forbidden')
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied: pickup_call'
            ]);
    }

    $ringingExt = (string)$data['ringing_extension'];
    $pickupExt  = (string)$data['pickup_extension'];
    $domain     = (string)$data['domain'];

    try {

        /* =======================
           ESL CONNECT
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);

        if (!$socket) {
            throw new Exception("ESL connect failed: {$errstr}");
        }

        // Auth
        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");


        /* =======================
           GET CHANNELS
        ======================== */
        fwrite($socket, "api show channels\n\n");

        $contentLength = 0;

        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $contentLength = (int) trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $contentLength ? fread($socket, $contentLength) : '';
        $rows = array_filter(explode("\n", trim($body)));

        if (count($rows) < 2) {
            throw new Exception("No active calls");
        }


        /* =======================
           MAP COLUMNS
        ======================== */
        $header = str_getcsv(array_shift($rows));
        $map    = array_flip($header);

        $requiredCols = [
            'uuid',
            'dest',
            'callstate',
            'initial_cid_num',
            'initial_cid_name',
            'cid_num',
            'cid_name'
        ];

        foreach ($requiredCols as $col) {
            if (!isset($map[$col])) {
                throw new Exception("Missing column {$col}");
            }
        }


        /* =======================
           FIND RINGING LEG
           + ORIGINAL CALLER
        ======================== */
        $ringingUuid          = null;
        $originalCallerNumber = null;
        $originalCallerName   = null;

        foreach ($rows as $row) {

            $cols = str_getcsv($row);

            if (
                $cols[$map['dest']] == $ringingExt &&
                in_array($cols[$map['callstate']], ['EARLY', 'RINGING'], true)
            ) {

                $ringingUuid = $cols[$map['uuid']];

                // Prefer original caller
                $originalCallerNumber = $cols[$map['initial_cid_num']];
                $originalCallerName   = $cols[$map['initial_cid_name']];

                // Fallbacks
                if (empty($originalCallerNumber)) {
                    $originalCallerNumber = $cols[$map['cid_num']];
                }

                if (empty($originalCallerName)) {
                    $originalCallerName = $cols[$map['cid_name']] ?: 'Pickup';
                }

                break;
            }
        }

        if (!$ringingUuid) {
            throw new Exception("No ringing call found on {$ringingExt}");
        }


        /* =======================
           SANITIZE
        ======================== */
        $originalCallerName   = addslashes($originalCallerName);
        $originalCallerNumber = 'Pickup';


        /* =======================
           ORIGINATE + INTERCEPT
        ======================== */
        $cmd = sprintf(
            "api originate " .
            "{pickup_call=true," .
            "origination_caller_id_name=%s," .
            "origination_caller_id_number=%s," .
            "hangup_after_bridge=false," .
            "continue_on_fail=false," .
            "call_timeout=60}" .
            "user/%s@%s &intercept(%s)\n\n",
            $originalCallerName,
            $originalCallerNumber,
            $pickupExt,
            $domain,
            $ringingUuid
        );

        fwrite($socket, $cmd);
        fclose($socket);


        /* =======================
           RESPONSE
        ======================== */
        return $this->response->setJsonContent([
            'status'            => 'success',
            'ringing_extension' => $ringingExt,
            'picked_by'         => $pickupExt,
            'ringing_uuid'      => $ringingUuid,
            'original_caller'   => $originalCallerNumber
        ]);

    } catch (Exception $e) {

        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function dropCallAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       VALIDATION
    ======================== */
    if (empty($data['extension']) || empty($data['domain'])) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }

    /* =======================
       PERMISSION
    ======================== */
    $redisKey = 'call_api_permissions:' . $data['domain'];
    $canDrop  = $this->redis->hGet($redisKey, 'drop_call');

    if ((int)$canDrop !== 1) {
        return $this->response
            ->setStatusCode(403)
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied: drop_call'
            ]);
    }

    $extension = (string)$data['extension'];
    $domain    = (string)$data['domain'];

    try {

        /* =======================
           ESL CONNECT
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);

        if (!$socket) {
            throw new Exception("ESL connect failed: {$errstr}");
        }

        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");


        /* =======================
           SHOW CHANNELS
        ======================== */
        fwrite($socket, "api show channels\n\n");

        $contentLength = 0;

        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $contentLength = (int) trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $contentLength ? fread($socket, $contentLength) : '';
        $rows = array_filter(explode("\n", trim($body)));

        if (count($rows) < 2) {
            throw new Exception("No active calls");
        }


        /* =======================
           MAP COLUMNS
        ======================== */
        $header = str_getcsv(array_shift($rows));
        $map    = array_flip($header);

        $required = [
            'uuid',
            'call_uuid',
            'callstate',
            'initial_dest',
            'name'
        ];

        foreach ($required as $col) {
            if (!isset($map[$col])) {
                throw new Exception("Missing column {$col}");
            }
        }

        /* =======================
           FIND ACTIVE CALL
        ======================== */

        $callUuid = null;

        foreach ($rows as $row) {

            $cols = str_getcsv($row);

            $state = $cols[$map['callstate']];
            $dest  = $cols[$map['dest']];

            if (
                $dest == $extension &&
                in_array($state, ['EARLY','RINGING','ACTIVE'], true)
            ) {

                // Prefer master UUID
                if (!empty($cols[$map['call_uuid']])) {
                    $callUuid = $cols[$map['call_uuid']];
                } else {
                    $callUuid = $cols[$map['uuid']];
                }

                break;
            }
        }

        if (!$callUuid) {
            throw new Exception("No call found for extension {$extension}");
        }


        /* =======================
           DROP CALL
        ======================== */
        fwrite($socket, "api uuid_kill {$callUuid}\n\n");

        fclose($socket);


        return $this->response->setJsonContent([
            'status'    => 'success',
            'extension' => $extension,
            'uuid'      => $callUuid,
            'message'   => 'Call dropped successfully'
        ]);

    } catch (Exception $e) {

        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function transferCallAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       VALIDATION
    ======================== */
    if (
        empty($data['from_extension']) ||
        empty($data['to']) ||
        empty($data['domain'])
    ) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }


    /* =======================
       PERMISSION
    ======================== */
    $redisKey = 'call_api_permissions:' . $data['domain'];
    $canMake  = $this->redis->hGet($redisKey, 'transfer_call');

    if ((int)$canMake !== 1) {
        return $this->response
            ->setStatusCode(403, 'Forbidden')
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied: transfer_call'
            ]);
    }


    $fromExt = (string)$data['from_extension'];
    $to      = (string)$data['to'];
    $domain  = (string)$data['domain'];


    try {

        /* =======================
           ESL CONNECT
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);

        if (!$socket) {
            throw new Exception("ESL connect failed: {$errstr}");
        }

        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");


        /* =======================
           SHOW CHANNELS
        ======================== */
        fwrite($socket, "api show channels\n\n");

        $contentLength = 0;

        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $contentLength = (int) trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $contentLength ? fread($socket, $contentLength) : '';
        $rows = array_filter(explode("\n", trim($body)));

        if (count($rows) < 2) {
            throw new Exception("No active calls");
        }


        /* =======================
           MAP COLUMNS
        ======================== */
        $header = str_getcsv(array_shift($rows));
        $map    = array_flip($header);

        $required = [
            'uuid',
            'dest',
            'initial_dest',
            'callstate',
            'call_uuid',
            'name'
        ];

        foreach ($required as $col) {
            if (!isset($map[$col])) {
                throw new Exception("Missing column {$col}");
            }
        }


        /* =======================
           FIND CALL LEG
        ======================== */
        $callUuid = null;

        foreach ($rows as $row) {

            $cols = str_getcsv($row);

            $state = $cols[$map['callstate']];

            if (!in_array($state, ['EARLY','RINGING','ACTIVE'], true)) {
                continue;
            }

            $dest        = $cols[$map['dest']];
            $initialDest = $cols[$map['initial_dest']];
            $name        = $cols[$map['name']];

            $matchByDest    = ($dest == $fromExt);
            $matchByInitial = ($initialDest == $fromExt);
            $matchByName    = (strpos($name, "/{$fromExt}@") !== false);

            if ($matchByDest || $matchByInitial || $matchByName) {

                // Prefer master UUID
                if (!empty($cols[$map['call_uuid']])) {
                    $callUuid = $cols[$map['call_uuid']];
                } else {
                    $callUuid = $cols[$map['uuid']];
                }

                break;
            }
        }

        if (!$callUuid) {
            throw new Exception("No call found for extension {$fromExt}");
        }


        /* =======================
           TRANSFER
        ======================== */

        // Internal
        if (ctype_digit($to)) {

            $cmd = "api uuid_transfer {$callUuid} {$to} XML {$domain}\n\n";

        } else {

            // External (adjust outbound context if needed)
            $cmd = "api uuid_transfer {$callUuid} {$to} XML outbound\n\n";
        }

        fwrite($socket, $cmd);

        fclose($socket);


        return $this->response->setJsonContent([
            'status'          => 'success',
            'from_extension'  => $fromExt,
            'transferred_to' => $to,
            'uuid'            => $callUuid
        ]);

    } catch (Exception $e) {

        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function listenCallAction()
    {
        // listen_number = the AGENT being monitored, agent_extension = the SUPERVISOR who listens.
        $ctx = $this->apiBegin('listen', ['listen_number', 'agent_extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $fp = null;
        try {
            $agentRef = $this->apiMonitorRef($ctx['data']['listen_number'], 'listen_number');
            $supRef = $this->apiMonitorRef($ctx['data']['agent_extension'], 'agent_extension');
            if ($agentRef === $supRef) {
                throw new ApiMonitorException('The supervisor and the agent must be different extensions.', 400);
            }
            $agent = $this->apiMonitorExtension($ctx, $agentRef);
            $supervisor = $this->apiMonitorExtension($ctx, $supRef);

            $fp = $this->apiEslConnect();
            $call = $this->apiMonitorSingleCall($fp, $ctx['domain'], $agent);
            // Silent: the supervisor hears both sides and cannot be heard.
            $this->apiMonitorStart($fp, $ctx['domain'], $supervisor['extension'], 'Listen', $agent['extension'], (string)$call['row']['uuid'], false, false);
            fclose($fp);
            $fp = null;

            $this->apiAudit($ctx, 'calls', 'listen', ['agent' => $agent['extension'], 'supervisor' => $supervisor['extension']]);
            return $this->apiOk([
                'agent'      => $agent['extension'],
                'manager'    => $supervisor['extension'],
                'agent_uuid' => $call['agent_uuid'],
                'mode'       => 'silent_listen',
            ]);
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $this->apiMonitorFail($e);
        }
    }

public function whisperCallAction()
    {
        // agent_ext = the AGENT on the call, supervisor_ext = the SUPERVISOR who whispers.
        $ctx = $this->apiBegin('whisper', ['agent_ext', 'supervisor_ext']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $fp = null;
        try {
            $agentRef = $this->apiMonitorRef($ctx['data']['agent_ext'], 'agent_ext');
            $supRef = $this->apiMonitorRef($ctx['data']['supervisor_ext'], 'supervisor_ext');
            if ($agentRef === $supRef) {
                throw new ApiMonitorException('The supervisor and the agent must be different extensions.', 400);
            }
            $agent = $this->apiMonitorExtension($ctx, $agentRef);
            $supervisor = $this->apiMonitorExtension($ctx, $supRef);

            $fp = $this->apiEslConnect();
            $call = $this->apiMonitorSingleCall($fp, $ctx['domain'], $agent);
            // The supervisor hears the call and speaks only to the agent's side, never to the customer.
            $this->apiMonitorStart(
                $fp,
                $ctx['domain'],
                $supervisor['extension'],
                'Coaching',
                $agent['extension'],
                $call['agent_uuid'],
                $call['side'] === 'a',
                $call['side'] === 'b'
            );
            fclose($fp);
            $fp = null;

            $this->apiAudit($ctx, 'calls', 'whisper', ['agent' => $agent['extension'], 'supervisor' => $supervisor['extension']]);
            return $this->apiOk([
                'mode'          => 'whisper',
                'agent'         => $agent['extension'],
                'supervisor'    => $supervisor['extension'],
                'agent_uuid'    => $call['agent_uuid'],
                'customer_uuid' => $call['peer_uuid'],
            ]);
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $this->apiMonitorFail($e);
        }
    }

public function joinCallAction()
    {
        $ctx = $this->apiBegin('join_call', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $fp = null;
        try {
            $agent = $this->apiMonitorExtension($ctx, $this->apiMonitorRef($ctx['data']['extension'], 'extension'));
            $fp = $this->apiEslConnect();
            $legs = $this->apiMonitorFindLegs(
                $this->apiMonitorCalls($fp),
                $ctx['domain'],
                $agent,
                ['ACTIVE', 'HELD', 'RINGING', 'EARLY', 'RING_WAIT']
            );
            if (count($legs) === 0) {
                throw new ApiMonitorException('Extension ' . $agent['extension'] . ' is not on a call.', 409);
            }
            if (count($legs) === 1) {
                throw new ApiMonitorException('Extension ' . $agent['extension'] . ' does not have two separate calls.', 409);
            }
            if (count($legs) > 2) {
                throw new ApiMonitorException('More than two calls found. Cannot auto-join.', 409);
            }
            $uuidA = $legs[0]['peer_uuid'];
            $uuidB = $legs[1]['peer_uuid'];
            if ($uuidA === $uuidB || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuidA) || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuidB)) {
                throw new ApiMonitorException('The two calls could not be identified.', 409);
            }

            // Connect the two other parties to each other; the extension's own legs drop out.
            $reply = $this->apiEslApi($fp, 'uuid_bridge ' . $uuidA . ' ' . $uuidB);
            fclose($fp);
            $fp = null;
            if (strpos($reply, '+OK') !== 0) {
                error_log('joinCall: uuid_bridge answered: ' . $reply);
                throw new ApiMonitorException('The two calls could not be joined.', 502);
            }

            $this->apiAudit($ctx, 'calls', 'join', ['extension' => $agent['extension']]);
            return $this->apiOk([
                'action'    => 'join',
                'extension' => $agent['extension'],
                'uuid_a'    => $uuidA,
                'uuid_b'    => $uuidB,
            ]);
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $this->apiMonitorFail($e);
        }
    }

public function joinCall2Action()//(Attended Transfer Style) 
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       VALIDATION
    ======================== */
    if (empty($data['extension']) || empty($data['domain'])) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }

    /* =======================
       PERMISSION
    ======================== */
    $redisKey = 'call_api_permissions:' . $data['domain'];
    $canJoin  = $this->redis->hGet($redisKey, 'join_call');

    if ((int)$canJoin !== 1) {
        return $this->response
            ->setStatusCode(403)
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied: join_call'
            ]);
    }

    $extension = (string)$data['extension'];
    $domain    = (string)$data['domain'];

    try {

        /* =======================
           ESL CONNECT
        ======================== */
        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);

        if (!$socket) {
            throw new Exception("ESL connect failed: {$errstr}");
        }

        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");


        /* =======================
           SHOW CHANNELS
        ======================== */
        fwrite($socket, "api show channels\n\n");

        $len = 0;
        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $len = (int) trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $len ? fread($socket, $len) : '';
        $rows = array_filter(explode("\n", trim($body)));

        if (count($rows) < 2) {
            throw new Exception("No active calls");
        }


        /* =======================
           MAP COLUMNS
        ======================== */
        $header = str_getcsv(array_shift($rows));
        $map    = array_flip($header);

        $required = [
            'uuid',
            'dest',
            'initial_dest',
            'callstate',
            'call_uuid',
            'name',
            'direction'
        ];

        foreach ($required as $col) {
            if (!isset($map[$col])) {
                throw new Exception("Missing column {$col}");
            }
        }


        /* =======================
           COLLECT TWO CALLS
        ======================== */
        $calls = [];

        foreach ($rows as $row) {

            $cols = str_getcsv($row);

            if (!in_array($cols[$map['callstate']], ['ACTIVE'], true)) {
                continue;
            }

            $dest        = $cols[$map['dest']];
            $initialDest = $cols[$map['initial_dest']];
            $name        = $cols[$map['name']];

            $isExtension =
                $dest == $extension ||
                $initialDest == $extension ||
                strpos($name, "/{$extension}@") !== false;

            if (!$isExtension) {
                continue;
            }

            $callId = !empty($cols[$map['call_uuid']])
                ? $cols[$map['call_uuid']]
                : $cols[$map['uuid']];

            // Save legs by call_uuid
            $calls[$callId][] = [
                'uuid'      => $cols[$map['uuid']],
                'direction' => $cols[$map['direction']]
            ];
        }


        if (count($calls) !== 2) {
            throw new Exception("Extension {$extension} must have exactly two active calls");
        }


        /* =======================
           FIND REMOTE LEGS
        ======================== */
        $remoteLegs = [];

        foreach ($calls as $callId => $legs) {

            foreach ($legs as $leg) {

                // Remote leg = NOT internal extension leg
                if ($leg['direction'] === 'inbound') {
                    $remoteLegs[] = $leg['uuid'];
                    break;
                }
            }
        }

        if (count($remoteLegs) !== 2) {
            throw new Exception("Unable to determine remote call legs");
        }

        [$legA, $legB] = $remoteLegs;


        /* =======================
           ATTENDED JOIN
        ======================== */
        $cmd = "api uuid_transfer {$legA} UUID:{$legB} inline\n\n";

        fwrite($socket, $cmd);

        fclose($socket);


        return $this->response->setJsonContent([
            'status'    => 'success',
            'action'    => 'attended_join',
            'extension' => $extension,
            'leg_a'     => $legA,
            'leg_b'     => $legB
        ]);

    } catch (Exception $e) {

        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function recordStartAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       VALIDATE
    ======================== */

    if (empty($data['extension']) || empty($data['domain'])) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }

    $extension = trim($data['extension']);
    $domain    = trim($data['domain']);

    /* =======================
       PERMISSION
    ======================== */

    $redisKey = 'call_api_permissions:' . $domain;

    if ((int)$this->redis->hGet($redisKey, 'record_start') !== 1) {
        return $this->response
            ->setStatusCode(403)
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied'
            ]);
    }

    try {

        /* =======================
           CONNECT ESL
        ======================== */

        $socket = fsockopen('127.0.0.1', 8021, $errno, $errstr, 3);

        if (!$socket) {
            throw new Exception("ESL connect failed");
        }

        while (fgets($socket) !== "\n");
        fwrite($socket, "auth ClueCon\n\n");
        while (fgets($socket) !== "\n");

        /* =======================
           GET CHANNELS JSON
        ======================== */

        fwrite($socket, "api show channels as json\n\n");

        $contentLength = 0;

        while (($line = fgets($socket)) !== "\n") {
            if (stripos($line, 'Content-Length:') !== false) {
                $contentLength = (int)trim(str_replace('Content-Length:', '', $line));
            }
        }

        $body = $contentLength ? fread($socket, $contentLength) : '';

        $json = json_decode(trim($body), true);

        if (!$json || empty($json['rows'])) {
            throw new Exception("No active calls");
        }

        $callUuid = null;

        /* =======================
           SMART MATCH
        ======================== */

        foreach ($json['rows'] as $ch) {

            $fields = [

                // Most common
                $ch['caller_id_number'] ?? '',
                $ch['destination_number'] ?? '',

                // FusionPBX / SIP
                $ch['effective_caller_id_number'] ?? '',
                $ch['effective_callee_id_number'] ?? '',
                $ch['sip_from_user'] ?? '',
                $ch['sip_to_user'] ?? '',

                // Presence
                $ch['presence_id'] ?? '',
                $ch['name'] ?? '',

                // Fallback
                json_encode($ch)
            ];

            foreach ($fields as $val) {

                if (
                    !empty($val) &&
                    preg_match('/\b' . preg_quote($extension, '/') . '\b/', $val)
                ) {
                    $callUuid = $ch['uuid'];
                    break 2;
                }
            }
        }

        if (!$callUuid) {
            throw new Exception("No ongoing call found for {$extension}");
        }

        /* =======================
           BUILD ARCHIVE PATH
        ======================== */

        $year  = date('Y');
        $month = date('M');
        $day   = date('d');

        $basePath = "/var/lib/freeswitch/recordings/{$domain}/archive/{$year}/{$month}/{$day}";

        if (!is_dir($basePath)) {
            mkdir($basePath, 0775, true);
        }

        $filename = "{$extension}-{$callUuid}-" . date('Ymd-His') . ".wav";

        $recordFile = "{$basePath}/{$filename}";

        /* =======================
           START RECORDING
        ======================== */

        $cmd = "api uuid_record {$callUuid} start {$recordFile}\n\n";
        fwrite($socket, $cmd);

        $resp = '';

        while ($line = fgets($socket)) {
            $resp .= $line;
            if (strpos($line, '+OK') !== false || strpos($line, '-ERR') !== false) {
                break;
            }
        }

        fclose($socket);

        if (strpos($resp, '+OK') === false) {
            throw new Exception("Record start failed");
        }

        /* =======================
           RESPONSE
        ======================== */

        return $this->response->setJsonContent([
            'status'    => 'success',
            'extension' => $extension,
            'uuid'      => $callUuid,
            'file'      => $recordFile
        ]);

    } catch (Exception $e) {

        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function recordStopAction()
    {
        $ctx = $this->apiBegin('record_stop', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $fp = null;
        try {
            $agent = $this->apiMonitorExtension($ctx, $this->apiMonitorRef($ctx['data']['extension'], 'extension'));
            $fp = $this->apiEslConnect();
            $uuids = $this->apiRecordingChannels($this->apiMonitorChannels($fp), $ctx['domain'], $agent);
            if (!$uuids) {
                throw new ApiMonitorException('No ongoing call found for extension ' . $agent['extension'] . '.', 409);
            }

            // "stop all" ends every recording on the channel, whatever file it was started with.
            $stopped = [];
            foreach ($uuids as $uuid) {
                $reply = $this->apiEslApi($fp, 'uuid_record ' . $uuid . ' stop all');
                if (strpos($reply, '+OK') === 0) {
                    $stopped[] = $uuid;
                }
            }
            fclose($fp);
            $fp = null;
            if (!$stopped) {
                throw new ApiMonitorException('No recording is running on this extension\'s call.', 409);
            }

            $this->apiAudit($ctx, 'calls', 'record_stop', ['extension' => $agent['extension']]);
            return $this->apiOk([
                'extension' => $agent['extension'],
                'uuid'      => $stopped[0],
                'stopped'   => $stopped,
                'action'    => 'record_stop',
            ]);
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $this->apiMonitorFail($e);
        }
    }

public function recordingListAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       PERMISSION
    ======================== */

    $redisKey = 'call_api_permissions:' . ($data['domain'] ?? '');
    $canView  = $this->redis->hGet($redisKey, 'recording_list');

    if ((int)$canView !== 1) {
        return $this->response
            ->setStatusCode(403)
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied'
            ]);
    }

    /* =======================
       VALIDATE
    ======================== */

    if (empty($data['extension']) || empty($data['domain'])) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing extension/domain'
        ]);
    }

    $extension = trim($data['extension']);
    $domain    = trim($data['domain']);

    /* =======================
       MAIN
    ======================== */

    try {

        $baseDir = "/var/lib/freeswitch/recordings/{$domain}/archive";

        if (!is_dir($baseDir)) {
            throw new Exception("Archive not found: {$baseDir}");
        }

        $recordings = [];

        /* =======================
           SCAN WAV + MP3
        ======================== */

        $wavFiles = glob("{$baseDir}/*/*/*/*.wav");
        $mp3Files = glob("{$baseDir}/*/*/*/*.mp3");

        $files = array_merge($wavFiles ?: [], $mp3Files ?: []);

        foreach ($files as $file) {

            if (!is_file($file)) {
                continue;
            }

            $filename = basename($file);

            /* =======================
               FILTER BY EXTENSION
               (1155 inside filename)
            ======================== */

            if (strpos($filename, $extension) === false) {
                continue;
            }

            /*
              Example path:
              /.../archive/2026/Feb/25/file.wav
            */

            $parts = explode('/', $file);
            $count = count($parts);

            $year  = $parts[$count - 4] ?? '';
            $month = $parts[$count - 3] ?? '';
            $day   = $parts[$count - 2] ?? '';

            $mtime = filemtime($file);

            $recordings[] = [
                'file'      => $filename,
                'date'      => "{$year}-{$month}-{$day}",
                'time'      => date('H:i:s', $mtime),
                'timestamp' => $mtime,
                'size_kb'   => round(filesize($file) / 1024, 2),
                'path'      => $file
            ];
        }

        /* =======================
           SORT NEWEST FIRST
        ======================== */

        usort($recordings, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        /* =======================
           RESPONSE
        ======================== */

        return $this->response->setJsonContent([
            'status'     => 'success',
            'domain'     => $domain,
            'extension'  => $extension,
            'count'      => count($recordings),
            'recordings' => $recordings
        ]);

    } catch (Exception $e) {

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function recordingLinkAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);
    /* =======================
       AUTH
    ======================== */
    try {
        $keyData = $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            'error' => 'Unauthorized'
        ]);
    }

    /* =======================
       VALIDATE INPUT
    ======================== */

    if (
        empty($data['extension']) ||
        empty($data['domain']) ||
        empty($data['filename'])
    ) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }

    $extension = trim($data['extension']);
    $domain    = trim($data['domain']);
    $filename  = basename(trim($data['filename'])); // safe

    try {

        /* =======================
           BASE DIR
        ======================== */

        $baseDir = "/var/lib/freeswitch/recordings/{$domain}/archive";

        if (!is_dir($baseDir)) {
            throw new Exception("Archive not found");
        }

        $foundPath = null;

        /* =======================
           SAFE SCAN (NO GLOB FILE)
        ======================== */

        // Loop years
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) as $yearDir) {

            // Loop months
            foreach (glob($yearDir . '/*', GLOB_ONLYDIR) as $monthDir) {

                // Loop days
                foreach (glob($monthDir . '/*', GLOB_ONLYDIR) as $dayDir) {

                    // Scan files inside day folder
                    $files = scandir($dayDir);

                    if (!$files) continue;

                    foreach ($files as $file) {

                        if ($file === '.' || $file === '..') continue;

                        if ($file !== $filename) continue;

                        // Extra safety: belongs to extension
                        if (strpos($file, $extension) === false) continue;

                        $fullPath = $dayDir . '/' . $file;

                        if (is_file($fullPath)) {
                            $foundPath = realpath($fullPath);
                            break 4; // exit all loops
                        }
                    }
                }
            }
        }

        if (!$foundPath) {
            throw new Exception("Recordings file not found");
        }

        /* =======================
           BUILD PUBLIC URL
        ======================== */

        $publicBase = "https://{$domain}/recordings";

        $relativePath = str_replace(
            "/var/lib/freeswitch/recordings/{$domain}",
            '',
            $foundPath
        );

        $downloadUrl = $publicBase . $relativePath;
        $downloadUrl = "https://{$domain}:8443/index/downloadRecording?file=" . urlencode($filename);

        /* =======================
           RESPONSE
        ======================== */

        return $this->response->setJsonContent([
            'status'       => 'success',
            'domain'       => $domain,
            'extension'    => $extension,
            'filename'     => $filename,
            'download_url' => $downloadUrl
        ]);

    } catch (Exception $e) {

        return $this->response->setJsonContent([
            'status'  => 'errors',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function downloadRecordingAction()
{
    $this->view->setRenderLevel(\Phalcon\Mvc\View::LEVEL_NO_RENDER);

    $file = basename($this->request->getQuery('file'));
    $domain = explode(':', $this->request->getHttpHost())[0];

    if (!$file) {
        echo "Missing file";
        exit;
    }

    $baseDir = "/var/lib/freeswitch/recordings/{$domain}/archive";

    $files = glob("{$baseDir}/*/*/*/*.mp3");

    foreach ($files as $path) {
        if (basename($path) === $file) {

            header('Content-Type: audio/mpeg');
            header('Content-Disposition: attachment; filename="'.$file.'"');
            header('Content-Length: ' . filesize($path));

            readfile($path);
            exit;
        }
    }

    echo "Recording not found";
    exit;
}

public function voicemailDeleteAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    /* =======================
       AUTH
    ======================== */

    try {
        $this->authenticate();
    } catch (Exception $e) {
        return $this->response
            ->setStatusCode(401)
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Unauthorized'
            ]);
    }

    /* =======================
       VALIDATE
    ======================== */

    if (empty($data['extension']) || empty($data['domain'])) {
        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => 'Missing parameters'
        ]);
    }

    $extension = trim($data['extension']);
    $domain    = trim($data['domain']);

    $filename = !empty($data['filename'])
        ? basename(trim($data['filename']))
        : null;

    /* =======================
       PERMISSION
    ======================== */

    $redisKey = 'call_api_permissions:' . $domain;

    if ((int)$this->redis->hGet($redisKey, 'voicemail_delete') !== 1) {
        return $this->response
            ->setStatusCode(403)
            ->setJsonContent([
                'status'  => 'error',
                'message' => 'Permission denied: voicemail_delete'
            ]);
    }

    try {

        /* =======================
           BASE PATH
        ======================== */

        $vmBasePath = "/var/lib/freeswitch/storage/voicemail/default/{$domain}/{$extension}";

        if (!is_dir($vmBasePath)) {
            throw new Exception("Voicemail directory not found");
        }

        $deleted = [];
        $failed  = [];

        /* =======================
           DELETE SINGLE
        ======================== */

        if ($filename) {

            $base = preg_replace('/\.(wav|xml)$/i', '', $filename);

            $targets = [
                "{$vmBasePath}/{$base}.wav",
                "{$vmBasePath}/{$base}.xml"
            ];

            $found = false;

            foreach ($targets as $file) {

                if (!file_exists($file)) {
                    continue;
                }

                $found = true;

                if (is_file($file)) {

                    if (@unlink($file)) {
                        $deleted[] = basename($file);
                    } else {
                        $failed[] = basename($file);
                    }
                }
            }

            if (!$found) {
                throw new Exception("Voicemail file not found");
            }

            return $this->response->setJsonContent([
                'status'    => 'success',
                'mode'      => 'single',
                'domain'    => $domain,
                'extension' => $extension,
                'deleted'   => $deleted,
                'failed'    => $failed
            ]);
        }

        /* =======================
           DELETE ALL
        ======================== */

        $files = scandir($vmBasePath);

        if (!$files) {
            throw new Exception("Unable to read voicemail directory");
        }

        foreach ($files as $file) {

            if ($file === '.' || $file === '..') {
                continue;
            }

            if (!preg_match('/\.(wav|xml)$/i', $file)) {
                continue;
            }

            $full = $vmBasePath . '/' . $file;

            if (!is_file($full)) {
                continue;
            }

            if (@unlink($full)) {
                $deleted[] = $file;
            } else {
                $failed[] = $file;
            }
        }

        return $this->response->setJsonContent([
            'status'    => 'success',
            'mode'      => 'all',
            'domain'    => $domain,
            'extension' => $extension,
            'deleted'   => $deleted,
            'failed'    => $failed
        ]);

    } catch (Exception $e) {

        return $this->response->setJsonContent([
            'status'  => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function changeVoicemailPinAction()
    {
        // The switch was saved under two names over time; either one allows the call.
        $ctx = $this->apiBegin(['change_vm_pin', 'voicemail_pin_change'], ['extension', 'new_pin']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        try {
            $extension = $this->apiMonitorRef($ctx['data']['extension'], 'extension');
            $pin = is_scalar($ctx['data']['new_pin']) ? trim((string)$ctx['data']['new_pin']) : '';
            if (!ctype_digit($pin)) {
                throw new ApiMonitorException('Voicemail PIN must be numeric.', 400);
            }
            if (strlen($pin) < 3 || strlen($pin) > 10) {
                throw new ApiMonitorException('Voicemail PIN must be 3-10 digits.', 400);
            }
            $agent = $this->apiMonitorExtension($ctx, $extension);

            $this->db->execute(
                'UPDATE v_voicemails SET voicemail_password = :pin WHERE domain_uuid = :d AND voicemail_id = :e',
                ['pin' => $pin, 'd' => $ctx['domain_uuid'], 'e' => $agent['extension']]
            );
            if ((int)$this->db->affectedRows() < 1) {
                throw new ApiMonitorException('Voicemail not found for extension ' . $agent['extension'] . '.', 404);
            }

            // The PIN is read from the cached directory entry, so clear it or the old PIN stays active.
            $names = ['directory.' . $agent['extension'] . '@' . $ctx['domain']];
            if ($agent['alias'] !== '') {
                $names[] = 'directory.' . $agent['alias'] . '@' . $ctx['domain'];
            }
            $this->apiUnlinkCache($names);
            $this->apiRedisDel(['vExtensions-' . $ctx['domain'], $ctx['domain_uuid'] . '-extensions-cache']);
            try {
                $fp = $this->apiEslConnect();
                $this->apiEslApi($fp, 'memcache delete directory:' . $agent['extension'] . '@' . $ctx['domain']);
                if ($agent['alias'] !== '') {
                    $this->apiEslApi($fp, 'memcache delete directory:' . $agent['alias'] . '@' . $ctx['domain']);
                }
                fclose($fp);
            } catch (\Throwable $e) {
                error_log('changeVoicemailPin: memcache clear skipped: ' . $e->getMessage());
            }

            $this->apiAudit($ctx, 'voicemail', 'change_pin', ['extension' => $agent['extension']]);
            return $this->apiOk([
                'domain'    => $ctx['domain'],
                'extension' => $agent['extension'],
                'message'   => 'Voicemail PIN updated successfully',
            ]);
        } catch (\Throwable $e) {
            return $this->apiMonitorFail($e);
        }
    }

    public function voicemailListAction()
    {
        $ctx = $this->apiBegin('voicemail_list', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        try {
            $extension = $this->apiMonitorRef($ctx['data']['extension'], 'extension');
            $agent = $this->apiMonitorExtension($ctx, $extension);

            $limit = (int)($ctx['data']['limit'] ?? 50);
            $limit = max(1, min(200, $limit));
            $page = max(1, (int)($ctx['data']['page'] ?? 1));
            $offset = ($page - 1) * $limit;
            $filter = strtolower(trim((string)($ctx['data']['status'] ?? '')));
            if ($filter !== '' && !in_array($filter, ['new', 'saved'], true)) {
                throw new ApiMonitorException('status must be "new" or "saved".', 400);
            }

            $mailbox = $this->db->fetchOne(
                'SELECT voicemail_uuid FROM v_voicemails WHERE domain_uuid = :d AND voicemail_id = :e LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $ctx['domain_uuid'], 'e' => $agent['extension']]
            );
            if (!$mailbox) {
                throw new ApiMonitorException('Voicemail not found for extension ' . $agent['extension'] . '.', 404);
            }

            // A message is new until it has been played or saved.
            $where = 'domain_uuid = :d AND voicemail_uuid = :v';
            if ($filter === 'new') {
                $where .= " AND COALESCE(read_epoch, 0) = 0 AND COALESCE(message_status, '') <> 'saved'";
            } elseif ($filter === 'saved') {
                $where .= " AND (COALESCE(read_epoch, 0) > 0 OR message_status = 'saved')";
            }
            $bind = ['d' => $ctx['domain_uuid'], 'v' => $mailbox['voicemail_uuid']];

            $count = $this->db->fetchOne(
                'SELECT COUNT(*) AS c FROM v_voicemail_messages WHERE ' . $where,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );
            $total = (int)($count['c'] ?? 0);
            $rows = $this->db->fetchAll(
                'SELECT voicemail_message_uuid, created_epoch, read_epoch, caller_id_name, caller_id_number, '
                . 'message_length, message_status, message_priority FROM v_voicemail_messages WHERE ' . $where
                . ' ORDER BY created_epoch DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                \Phalcon\Db\Enum::FETCH_ASSOC,
                $bind
            );

            $dir = '/var/lib/freeswitch/storage/voicemail/default/' . $ctx['domain'] . '/' . $agent['extension'] . '/';
            $messages = [];
            foreach ((array)$rows as $r) {
                $base = 'msg_' . $r['voicemail_message_uuid'];
                $file = null;
                $sizeKb = null;
                foreach (['.wav', '.mp3'] as $ext) {
                    if (is_file($dir . $base . $ext)) {
                        $file = $base . $ext;
                        $sizeKb = round(filesize($dir . $base . $ext) / 1024, 2);
                        break;
                    }
                }
                $created = (int)$r['created_epoch'];
                $isNew = (int)($r['read_epoch'] ?? 0) === 0 && (string)($r['message_status'] ?? '') !== 'saved';
                $messages[] = [
                    'id'              => (string)$r['voicemail_message_uuid'],
                    'filename'        => $file,
                    'caller_name'     => (string)($r['caller_id_name'] ?? ''),
                    'caller_number'   => (string)($r['caller_id_number'] ?? ''),
                    'received_at'     => $created > 0 ? gmdate('Y-m-d\TH:i:s\Z', $created) : null,
                    'received_epoch'  => $created,
                    'duration_seconds' => (int)($r['message_length'] ?? 0),
                    'status'          => $isNew ? 'new' : 'saved',
                    'priority'        => (string)($r['message_priority'] ?? ''),
                    'size_kb'         => $sizeKb,
                ];
            }

            return $this->apiOk([
                'domain'     => $ctx['domain'],
                'extension'  => $agent['extension'],
                'total'      => $total,
                'page'       => $page,
                'limit'      => $limit,
                'count'      => count($messages),
                'voicemails' => $messages,
            ]);
        } catch (\Throwable $e) {
            return $this->apiMonitorFail($e);
        }
    }

public function bargeInCallAction()
    {
        // bargein_number = the AGENT being joined, agent_extension = the SUPERVISOR who joins.
        $ctx = $this->apiBegin('bargein', ['bargein_number', 'agent_extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $fp = null;
        try {
            $agentRef = $this->apiMonitorRef($ctx['data']['bargein_number'], 'bargein_number');
            $supRef = $this->apiMonitorRef($ctx['data']['agent_extension'], 'agent_extension');
            if ($agentRef === $supRef) {
                throw new ApiMonitorException('The supervisor and the agent must be different extensions.', 400);
            }
            $agent = $this->apiMonitorExtension($ctx, $agentRef);
            $supervisor = $this->apiMonitorExtension($ctx, $supRef);

            $fp = $this->apiEslConnect();
            $call = $this->apiMonitorSingleCall($fp, $ctx['domain'], $agent);
            // Barge in: the supervisor joins the call and both sides can hear and be heard.
            // Target the agent's own leg (as whisper does), not the call's first leg: aimed at the other
            // party's leg the supervisor's voice reached only the agent (tested: agent 1003 called by 1001).
            $this->apiMonitorStart($fp, $ctx['domain'], $supervisor['extension'], 'BargeIn', $agent['extension'], (string)$call['agent_uuid'], true, true);
            fclose($fp);
            $fp = null;

            $this->apiAudit($ctx, 'calls', 'bargein', ['agent' => $agent['extension'], 'supervisor' => $supervisor['extension']]);
            return $this->apiOk([
                'mode'       => 'barge_in',
                'agent_uuid' => $call['agent_uuid'],
                'agent'      => $agent['extension'],
                'supervisor' => $supervisor['extension'],
            ]);
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $this->apiMonitorFail($e);
        }
    }
 
public function queueLoginAction()
{
    $this->response->setContentType('application/json');
    $data = $this->request->getJsonRawBody(true);

    try {
        $this->authenticate();
    } catch (Exception $e) {
        return $this->response->setStatusCode(401)->setJsonContent([
            "status" => "error",
            "message" => "Unauthorized"
        ]);
    }

    if (
        empty($data['agent_extension']) ||
        empty($data['queue_extension']) ||
        empty($data['domain'])
    ) {
        return $this->response->setJsonContent([
            "status" => "error",
            "message" => "Missing parameters"
        ]);
    }

    $extension = intval($data['agent_extension']);
    $queue     = intval($data['queue_extension']);
    $domain    = trim($data['domain']);
    $status    = ucfirst($data['auxcode'] ?? "Available");

    try {

        /* DOMAIN UUID */

        $domainRow = $this->db->fetchOne(
            "SELECT domain_uuid
             FROM v_domains
             WHERE domain_name = :domain",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['domain' => $domain]
        );

        if (!$domainRow) {
            throw new Exception("Domain not found");
        }

        $domainUuid = $domainRow['domain_uuid'];

        /* EXTENSION UUID */

        $extRow = $this->db->fetchOne(
            "SELECT extension_uuid
             FROM v_extensions
             WHERE extension = :ext
             AND domain_uuid = :domain_uuid",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [
                'ext' => $extension,
                'domain_uuid' => $domainUuid
            ]
        );

        if (!$extRow) {
            throw new Exception("Extension not found");
        }

        /* USER UUID */

        $userRow = $this->db->fetchOne(
            "SELECT user_uuid
             FROM v_extension_users
             WHERE extension_uuid = :uuid",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ['uuid' => $extRow['extension_uuid']]
        );

        if (!$userRow) {
            throw new Exception("Extension not assigned to user");
        }

        $userUuid = $userRow['user_uuid'];

        /* AGENT */

        $agentRow = $this->db->fetchOne(
            "SELECT call_center_agent_uuid
             FROM v_call_center_agents
             WHERE user_uuid = :user_uuid
             AND domain_uuid = :domain_uuid
             AND queue_extension = :queue",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [
                'user_uuid' => $userUuid,
                'domain_uuid' => $domainUuid,
                'queue' => $queue
            ]
        );

        if (!$agentRow) {
            throw new Exception("Agent not assigned to queue");
        }

        /* UPDATE STATUS */

        $this->db->execute(
            "UPDATE v_call_center_agents
             SET agent_status = :status,
                 agent_logout = NULL
             WHERE call_center_agent_uuid = :uuid",
            [
                'status' => $status,
                'uuid'   => $agentRow['call_center_agent_uuid']
            ]
        );

        /* WEBSOCKET EVENT */

        try {

            $client = new Client(
                Client::engine(
                    Client::CLIENT_4X,
                    "ws://127.0.0.1:3000",
                    ["persistent" => false]
                )
            );

            $client->initialize();

            $client->emit('agentstatus', [
                "agentuuid" => $agentRow['call_center_agent_uuid'],
                "extension" => $extension,
                "status" => $status == "Available" ? "Available" : "On Break",
                "subdomain" => explode('.', $domain)[0],
                "auxcode" => $status,
                "queue_extension" => $queue
            ]);

        } catch (Exception $e) {
            // ignore websocket failure
        }

        /* CLEAR CACHE */

        $cache1="/var/cache/fusionpbx/configuration.callcenter.conf.".gethostname();
        $cache2="/var/cache/ringq/configuration.callcenter.conf.".gethostname();

        if (file_exists($cache1)) unlink($cache1);
        if (file_exists($cache2)) unlink($cache2);


  
return $this->response->setStatusCode(200)->setJsonContent([
            "status" => "success",
            "message" => "Agent logged in successfully"
        ]);


    } catch (Exception $e) {

        return $this->response->setJsonContent([
            "status"=>"error",
            "message"=>ControllerBase::safeErrorMessage($e)
        ]);
    }
}

public function queueLogoutAction()
    {
        $ctx = $this->apiBegin('queue_logout', ['agent_extension', 'queue_extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $fp = null;
        try {
            $agentRef = $this->apiMonitorRef($ctx['data']['agent_extension'], 'agent_extension');
            $queueRef = $this->apiMonitorRef($ctx['data']['queue_extension'], 'queue_extension');
            // Optional reason, kept for the audit log only; logging out does not need an aux code.
            $auxcode = trim((string)($ctx['data']['auxcode'] ?? ''));
            if ($auxcode !== '' && !preg_match('/^[A-Za-z0-9 _.-]{1,32}$/', $auxcode)) {
                throw new ApiMonitorException('Invalid auxcode.', 400);
            }
            $agent = $this->apiMonitorExtension($ctx, $agentRef);

            $user = $this->db->fetchOne(
                'SELECT eu.user_uuid FROM v_extension_users eu JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid '
                . 'WHERE e.domain_uuid = :d AND e.extension = :e LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $ctx['domain_uuid'], 'e' => $agent['extension']]
            );
            if (!$user) {
                throw new ApiMonitorException('Extension ' . $agent['extension'] . ' is not assigned to a user.', 404);
            }
            $row = $this->db->fetchOne(
                'SELECT call_center_agent_uuid FROM v_call_center_agents WHERE user_uuid = :u AND domain_uuid = :d '
                . 'AND CAST(queue_extension AS TEXT) = :q LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['u' => $user['user_uuid'], 'd' => $ctx['domain_uuid'], 'q' => $queueRef]
            );
            if (!$row) {
                throw new ApiMonitorException('Agent ' . $agent['extension'] . ' is not assigned to queue ' . $queueRef . '.', 404);
            }
            $agentUuid = (string)$row['call_center_agent_uuid'];
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', $agentUuid)) {
                throw new ApiMonitorException('The agent could not be identified.', 409);
            }

            // Tell the call centre itself, as the app does. Without this the agent keeps receiving queue calls.
            $fp = $this->apiEslConnect();
            $reply = $this->apiEslApi($fp, "callcenter_config agent set status " . $agentUuid . " 'Logged Out'");
            fclose($fp);
            $fp = null;
            if (strpos($reply, '+OK') !== 0 && stripos($reply, 'not found') === false) {
                error_log('queueLogout: callcenter_config answered: ' . $reply);
                throw new ApiMonitorException('The call centre did not accept the log out.', 502);
            }

            $this->db->execute(
                "UPDATE v_call_center_agents SET agent_status = 'Logged Out', agent_logout = NOW() "
                . 'WHERE call_center_agent_uuid = :u AND domain_uuid = :d',
                ['u' => $agentUuid, 'd' => $ctx['domain_uuid']]
            );
            $this->apiUnlinkCache(['configuration.callcenter.conf.' . gethostname()]);

            $this->apiAudit($ctx, 'queue-agents', 'logout', [
                'agent' => $agent['extension'], 'queue' => $queueRef, 'auxcode' => $auxcode,
            ]);
            return $this->apiOk([
                'message'         => 'Agent logged out successfully',
                'agent_extension' => $agent['extension'],
                'queue_extension' => $queueRef,
            ]);
        } catch (\Throwable $e) {
            if (is_resource($fp)) {
                fclose($fp);
            }
            return $this->apiMonitorFail($e);
        }
    }

    // =====================================================================
    // Admin API actions (API-key authenticated, POST /index/<action>).
    // Shared helpers live in library/ApiSupport/.
    // =====================================================================

// ------------------------------------------------------------------
    // Endpoints
    // ------------------------------------------------------------------

    public function addQueueAction()
    {
        $ctx = $this->apiBegin('add_queue', ['name']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];
        $data = $ctx['data'];

        try {
            $this->apiQFlushCaches($domain);
            $s = $this->apiQSettings($data, null);
            if (QueueName::isTaken($this->db, $domainUuid, $s['name'])) {
                return $this->apiFail('A queue with this name already exists', 409);
            }

            // Extension: explicit or auto (max + 1, start 9000).
            if (array_key_exists('extension', $data) && trim((string)$data['extension']) !== '') {
                $ext = $this->apiQExtension($data['extension']);
                if ($this->apiQExtensionTaken($domainUuid, (string)$ext)) {
                    return $this->apiFail('Extension ' . $ext . ' is already in use', 409);
                }
            } else {
                $ext = $this->apiNextNumber('v_call_center_queues', 'queue_extension', $domainUuid, 8999, 9000);
                for ($i = 0; $i < 200 && $this->apiQExtensionTaken($domainUuid, (string)$ext); $i++) {
                    $ext++;
                }
                if ($this->apiQExtensionTaken($domainUuid, (string)$ext)) {
                    return $this->apiFail('No free queue extension available', 409);
                }
            }
            $s['extension'] = $ext;

            $queueUuid = $this->apiUuid();
            $dialplanUuid = $this->apiUuid();
            $now = date('Y-m-d H:i:s');

            $this->db->begin();

            $queue = new VCallCenterQueues();
            $queue->call_center_queue_uuid = $queueUuid;
            $queue->domain_uuid = $domainUuid;
            $queue->dialplan_uuid = $dialplanUuid;
            $this->apiQApplySettings($queue, $s, $domain);
            $queue->queue_record_template = '/var/lib/freeswitch/recordings/' . $domain . '/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}/${uuid}.${record_ext}';
            $queue->queue_discard_abandoned_after = 900;
            $queue->queue_tier_rule_wait_second = 15;
            $queue->queue_tier_rule_wait_multiply_level = 'true';
            $queue->queue_tier_rule_no_agent_no_wait = 'true';
            $queue->queue_abandoned_resume_allowed = 'false';
            $queue->queue_cid_prefix = null;
            $queue->queue_cc_exit_keys = null;
            $queue->insert_user = null;
            $queue->insert_date = $now;
            if (!$queue->create()) {
                throw new \RuntimeException('Queue could not be created');
            }

            $dialplan = new VDialplans();
            $dialplan->domain_uuid = $domainUuid;
            $dialplan->dialplan_uuid = $dialplanUuid;
            $dialplan->dialplan_name = $s['name'];
            $dialplan->dialplan_number = (string)$ext;
            $dialplan->dialplan_context = $domain;
            $dialplan->dialplan_continue = 'false';
            $dialplan->dialplan_xml = $this->apiQDialplanXml($s, $domain, $dialplanUuid, $queueUuid);
            $dialplan->dialplan_order = 75;
            $dialplan->dialplan_enabled = 'true';
            $dialplan->app_uuid = '95788e50-9500-079e-2807-fd530b0ea370';
            $dialplan->insert_user = null;
            $dialplan->insert_date = $now;
            if (!$dialplan->create()) {
                throw new \RuntimeException('Queue dialplan could not be created');
            }

            if ($s['script'] !== '') {
                $script = new QScripts();
                $script->domain_uuid = $domainUuid;
                $script->extension = $ext;
                $script->content = $s['script'];
                $script->insert_user = null;
                $script->insert_date = $now;
                if (!$script->create()) {
                    throw new \RuntimeException('Queue script could not be created');
                }
            }

            $notify = new QQueueNotifications();
            $notify->queue_uuid = $queueUuid;
            $notify->domain_uuid = $domainUuid;
            $notify->queue_extension = $ext;
            $notify->queue_sla_notify = $s['sla_notify'];
            $notify->queue_callback_success = $s['callback_success'];
            $notify->queue_callback_fail = $s['callback_fail'];
            $notify->queue_call_lost = $s['call_lost'];
            $notify->update_user = null;
            $notify->update_date = $now;
            if (!$notify->create()) {
                throw new \RuntimeException('Queue notifications could not be created');
            }

            $this->db->commit();

            $this->apiQWriteRedis($s, $domain, true);
            $this->apiRedisDel(['allDialplans-' . $domain]);
            $this->apiUnlinkCache(['dialplan.' . $domain, 'configuration.callcenter.conf.' . gethostname()]);
            $this->apiEmit('reloadqueue', ['extension' => $ext, 'subdomain' => $domain]);
            $this->apiAudit($ctx, 'queue', 'add', ['extension' => (string)$ext, 'name' => $s['name']]);

            return $this->apiOk([
                'message'                => 'Successfully, added new queue.',
                'extension'              => (string)$ext,
                'call_center_queue_uuid' => $queueUuid,
                'dialplan_uuid'          => $dialplanUuid,
            ]);
        } catch (\Throwable $e) {
            return $this->apiQFailure($e);
        }
    }

public function updateQueueAction()
    {
        $ctx = $this->apiBegin('update_queue', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];
        $data = $ctx['data'];

        try {
            $this->apiQFlushCaches($domain);
            $ext = $this->apiQExtension($data['extension']);

            $queue = VCallCenterQueues::findFirst([
                'conditions' => 'domain_uuid = :d: AND queue_extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => (string)$ext],
                'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if (!$queue) {
                return $this->apiFail('Queue non-existing', 404);
            }
            $oldName = (string)$queue->queue_name;

            $s = $this->apiQSettings($data, $queue);
            if ($s['name'] !== trim($oldName) && QueueName::isTaken($this->db, $domainUuid, $s['name'], (string)$queue->queue_extension)) {
                return $this->apiFail('A queue with this name already exists', 409);
            }
            $s['extension'] = $ext;

            $this->db->begin();

            $this->apiQApplySettings($queue, $s, $domain);
            $queue->update_user = null;
            $queue->update_date = date('Y-m-d H:i:s');
            if (!$queue->update()) {
                throw new \RuntimeException('Queue could not be updated');
            }

            $dialplan = VDialplans::findFirst([
                'conditions' => 'domain_uuid = :d: AND dialplan_uuid = :u:',
                'bind'       => ['d' => $domainUuid, 'u' => (string)$queue->dialplan_uuid],
                'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if (!$dialplan) {
                throw new \InvalidArgumentException('Queue dialplan non-existing', 404);
            }
            $dialplan->dialplan_name = $s['name'];
            $dialplan->dialplan_number = (string)$ext;
            $dialplan->dialplan_context = $domain;
            $dialplan->dialplan_continue = 'false';
            $dialplan->dialplan_xml = $this->apiQDialplanXml($s, $domain, (string)$dialplan->dialplan_uuid, (string)$queue->call_center_queue_uuid);
            $dialplan->update_user = null;
            $dialplan->update_date = date('Y-m-d H:i:s');
            if (!$dialplan->update()) {
                throw new \RuntimeException('Queue dialplan could not be updated');
            }

            if ($oldName !== $s['name']) {
                $this->db->execute(
                    'UPDATE q_dids SET name = :n WHERE domain_uuid = :d AND extension::text = :e',
                    ['n' => $s['name'], 'd' => $domainUuid, 'e' => (string)$ext]
                );
            }

            // Script: provided non-empty -> upsert, provided empty -> remove, absent -> untouched.
            if ($s['script_provided']) {
                if ($s['script'] !== '') {
                    $existing = $this->db->fetchOne(
                        'SELECT 1 AS x FROM q_scripts WHERE domain_uuid = :d AND extension::text = :e',
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        ['d' => $domainUuid, 'e' => (string)$ext]
                    );
                    if ($existing) {
                        $this->db->execute(
                            'UPDATE q_scripts SET content = :c, update_date = :t WHERE domain_uuid = :d AND extension::text = :e',
                            ['c' => $s['script'], 't' => date('Y-m-d H:i:s'), 'd' => $domainUuid, 'e' => (string)$ext]
                        );
                    } else {
                        $script = new QScripts();
                        $script->domain_uuid = $domainUuid;
                        $script->extension = $ext;
                        $script->content = $s['script'];
                        $script->insert_user = null;
                        $script->insert_date = date('Y-m-d H:i:s');
                        if (!$script->create()) {
                            throw new \RuntimeException('Queue script could not be saved');
                        }
                    }
                } else {
                    $this->db->execute(
                        'DELETE FROM q_scripts WHERE domain_uuid = :d AND extension::text = :e',
                        ['d' => $domainUuid, 'e' => (string)$ext]
                    );
                }
            }

            // Notifications: create the row when a legacy queue has none.
            $notify = QQueueNotifications::findFirst([
                'conditions' => 'domain_uuid = :d: AND queue_extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $ext],
                'bindTypes'  => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
            ]);
            if ($notify) {
                $notify->queue_sla_notify = $s['sla_notify'];
                $notify->queue_callback_success = $s['callback_success'];
                $notify->queue_callback_fail = $s['callback_fail'];
                $notify->queue_call_lost = $s['call_lost'];
                $notify->update_user = null;
                $notify->update_date = date('Y-m-d H:i:s');
                if (!$notify->update()) {
                    throw new \RuntimeException('Queue notifications could not be updated');
                }
            } else {
                $notify = new QQueueNotifications();
                $notify->queue_uuid = (string)$queue->call_center_queue_uuid;
                $notify->domain_uuid = $domainUuid;
                $notify->queue_extension = $ext;
                $notify->queue_sla_notify = $s['sla_notify'];
                $notify->queue_callback_success = $s['callback_success'];
                $notify->queue_callback_fail = $s['callback_fail'];
                $notify->queue_call_lost = $s['call_lost'];
                $notify->update_user = null;
                $notify->update_date = date('Y-m-d H:i:s');
                if (!$notify->create()) {
                    throw new \RuntimeException('Queue notifications could not be created');
                }
            }

            $this->db->commit();

            $this->apiQWriteRedis($s, $domain, false);
            $chat = $this->apiQUpdateChatKeys($data, $ext, $domain);
            $this->apiUnlinkCache(['dialplan.' . $domain]);
            if ($chat !== null) {
                $this->apiEmit('chatSettings', $chat);
            }
            $this->apiEmit('reloadqueue', ['extension' => $ext, 'subdomain' => $domain]);
            $this->apiEmit('broadcast4', [
                'extension'    => $ext,
                'subdomain'    => $domain,
                'wrap_up'      => $s['wrap_up'],
                'queue_script' => $s['show_scripts'],
            ]);
            $this->apiAudit($ctx, 'queue', 'update', ['extension' => (string)$ext, 'name' => $s['name']]);

            return $this->apiOk([
                'message'                => 'Successfully, updated queue.',
                'extension'              => (string)$ext,
                'call_center_queue_uuid' => (string)$queue->call_center_queue_uuid,
            ]);
        } catch (\Throwable $e) {
            return $this->apiQFailure($e);
        }
    }

public function deleteQueueAction()
    {
        $ctx = $this->apiBegin('delete_queue', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];
        $data = $ctx['data'];

        try {
            $this->apiQFlushCaches($domain);
            $ext = $this->apiQExtension($data['extension']);
            $extStr = (string)$ext;

            $queue = $this->db->fetchOne(
                'SELECT call_center_queue_uuid, dialplan_uuid FROM v_call_center_queues WHERE domain_uuid = :d AND queue_extension::text = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extStr]
            );
            if (!$queue) {
                return $this->apiFail('Queue non-existing', 404);
            }
            $queueUuid = (string)$queue['call_center_queue_uuid'];

            $tiers = $this->db->fetchAll(
                'SELECT call_center_agent_uuid FROM v_call_center_tiers WHERE domain_uuid = :d AND call_center_queue_uuid = :q',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'q' => $queueUuid]
            );

            $this->db->begin();

            $this->db->execute(
                'DELETE FROM v_call_center_queues WHERE domain_uuid = :d AND call_center_queue_uuid = :q',
                ['d' => $domainUuid, 'q' => $queueUuid]
            );
            $this->db->execute(
                'DELETE FROM v_call_center_tiers WHERE domain_uuid = :d AND call_center_queue_uuid = :q',
                ['d' => $domainUuid, 'q' => $queueUuid]
            );

            // Every dialplan (queue + any time-schedule ones) carrying this number, details first.
            $this->db->execute(
                'DELETE FROM v_dialplan_details WHERE domain_uuid = :d AND dialplan_uuid IN '
                . '(SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :d2 AND (dialplan_number = :e OR dialplan_uuid = :u))',
                ['d' => $domainUuid, 'd2' => $domainUuid, 'e' => $extStr, 'u' => (string)$queue['dialplan_uuid']]
            );
            $this->db->execute(
                'DELETE FROM v_dialplans WHERE domain_uuid = :d AND (dialplan_number = :e OR dialplan_uuid = :u)',
                ['d' => $domainUuid, 'e' => $extStr, 'u' => (string)$queue['dialplan_uuid']]
            );

            // Remove the extension from the work/break schedule lists.
            $schedules = $this->db->fetchAll(
                'SELECT id, work_extensions, break_extensions FROM q_time_schedules WHERE domain_uuid = :d',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid]
            );
            foreach ($schedules as $row) {
                $work = $this->apiQDropFromList((string)($row['work_extensions'] ?? ''), $extStr);
                $break = $this->apiQDropFromList((string)($row['break_extensions'] ?? ''), $extStr);
                if ($work !== (string)($row['work_extensions'] ?? '') || $break !== (string)($row['break_extensions'] ?? '')) {
                    $this->db->execute(
                        'UPDATE q_time_schedules SET work_extensions = :w, break_extensions = :b WHERE id = :id',
                        ['w' => $work, 'b' => $break, 'id' => $row['id']]
                    );
                }
            }

            $this->db->execute(
                'DELETE FROM q_scripts WHERE domain_uuid = :d AND extension::text = :e',
                ['d' => $domainUuid, 'e' => $extStr]
            );
            $this->db->execute(
                'DELETE FROM q_queue_notifications WHERE domain_uuid = :d AND queue_extension::text = :e',
                ['d' => $domainUuid, 'e' => $extStr]
            );
            $this->db->execute(
                'DELETE FROM q_call_queues WHERE domain_uuid = :d AND queue_uuid = :q',
                ['d' => $domainUuid, 'q' => $queueUuid]
            );

            $this->db->commit();

            // Redis leftovers of this queue.
            $suffix = '@' . $domain;
            $keys = [
                'queueSettings' . $extStr . $suffix,
                $extStr . $suffix . '-callBack',
                $extStr . $suffix . '-callBackCustom',
                $extStr . $suffix . '-queue_call_landing',
                $extStr . $suffix . '-queue_call_landing2',
                $extStr . $suffix . '-queue_call_landing_url',
                $extStr . $suffix . '-click2talk-mode',
                $extStr . '-queuemaxcallers' . $suffix,
            ];
            foreach ($this->apiQChatKeys() as $def) {
                $keys[] = $extStr . $suffix . $def[0];
            }
            $this->apiRedisDel($keys);
            $this->redis->set('callboardReset-' . $domain, '1');

            $this->apiUnlinkCache(['dialplan.' . $domain, 'configuration.callcenter.conf.' . gethostname()]);
            foreach ($tiers as $tier) {
                $this->apiEmit('deletetier', [
                    'extension' => $ext,
                    'subdomain' => $domain,
                    'agent'     => (string)$tier['call_center_agent_uuid'],
                ]);
            }
            $this->apiEmit('reloadqueue', ['extension' => $ext, 'subdomain' => $domain]);
            $this->apiAudit($ctx, 'queue', 'delete', ['extension' => $extStr]);

            return $this->apiOk([
                'message'            => 'Successfully, deleted queue.',
                'extension'          => $extStr,
                'tiers_removed'      => count($tiers),
            ]);
        } catch (\Throwable $e) {
            return $this->apiQFailure($e);
        }
    }

public function addAgentsQueueAction()
    {
        $ctx = $this->apiBegin('add_agents_queue', ['queue_extension', 'extensions']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];
        $data = $ctx['data'];

        try {
            $this->apiQFlushCaches($domain);
            $qExt = $this->apiQExtension($data['queue_extension'], 'queue_extension');
            $queue = $this->db->fetchOne(
                'SELECT call_center_queue_uuid FROM v_call_center_queues WHERE domain_uuid = :d AND queue_extension::text = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => (string)$qExt]
            );
            if (!$queue) {
                return $this->apiFail('Queue non-existing', 404);
            }
            $queueUuid = (string)$queue['call_center_queue_uuid'];

            $extensions = $this->apiQExtList($data['extensions']);
            $levels = $this->apiQLevels($data['tier_level'] ?? null, count($extensions));
            $callTimeout = $this->apiQInt($data, 'agent_call_timeout', 0, 3600, null);
            $wrapUp = $this->apiQInt($data, 'agent_wrap_up_time', 0, 3600, null);

            $resolved = $this->apiQResolveExtensions($domainUuid, $extensions);
            $notFound = [];
            $noUser = [];
            $added = [];
            $already = [];
            $agentsMap = [];

            $this->db->begin();
            $now = date('Y-m-d H:i:s');

            foreach ($extensions as $idx => $extension) {
                $info = $resolved[$extension] ?? null;
                if ($info === null) {
                    $notFound[] = $extension;
                    continue;
                }
                if ((string)$info['username'] === '' || (string)$info['user_uuid'] === '') {
                    $noUser[] = $extension;
                    continue;
                }
                $contact = 'user/' . $info['username'] . '@' . $domain;
                $agent = VCallCenterAgents::findFirst([
                    'conditions' => 'domain_uuid = :d: AND agent_contact IN ({c:array}) AND queue_extension = :q:',
                    'bind'       => ['d' => $domainUuid, 'c' => [$contact, 'user/' . $extension . '@' . $domain], 'q' => $qExt],
                    'bindTypes'  => ['d' => Column::BIND_PARAM_STR, 'q' => Column::BIND_PARAM_INT],
                ]);
                $isNew = false;
                if (!$agent) {
                    $isNew = true;
                    $name = trim((string)$info['first_name'] . ' ' . (string)$info['last_name']);
                    $agent = new VCallCenterAgents();
                    $agent->call_center_agent_uuid = $this->apiUuid();
                    $agent->domain_uuid = $domainUuid;
                    $agent->user_uuid = (string)$info['user_uuid'];
                    $agent->agent_name = $name !== '' ? $name : (string)$info['username'];
                    $agent->agent_type = 'callback';
                    $agent->agent_id = (string)$info['username'];
                    $agent->agent_contact = $contact;
                    $agent->agent_status = 'Available';
                    $agent->queue_extension = $qExt;
                    $agent->agent_call_timeout = $callTimeout ?? 0;
                } elseif ($callTimeout !== null) {
                    $agent->agent_call_timeout = $callTimeout;
                }
                if ($wrapUp !== null && $wrapUp > 0) {
                    $agent->agent_wrap_up_time = $wrapUp;
                    $agent->agent_reject_delay_time = $wrapUp;
                    $agent->agent_busy_delay_time = $wrapUp;
                    $agent->agent_no_answer_delay_time = $wrapUp;
                }
                if ($isNew) {
                    $ok = $agent->create();
                } else {
                    $agent->update_user = null;
                    $agent->update_date = $now;
                    $ok = $agent->update();
                }
                if (!$ok) {
                    throw new \RuntimeException('Agent ' . $extension . ' could not be saved');
                }
                $agentUuid = (string)$agent->call_center_agent_uuid;

                $tier = $this->db->fetchOne(
                    'SELECT call_center_tier_uuid FROM v_call_center_tiers WHERE domain_uuid = :d AND call_center_queue_uuid = :q AND call_center_agent_uuid = :a',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'q' => $queueUuid, 'a' => $agentUuid]
                );
                if ($tier) {
                    $already[] = $extension;
                    continue;
                }
                $newTier = new VCallCenterTiers();
                $newTier->call_center_tier_uuid = $this->apiUuid();
                $newTier->domain_uuid = $domainUuid;
                $newTier->call_center_queue_uuid = $queueUuid;
                $newTier->call_center_agent_uuid = $agentUuid;
                $newTier->tier_level = 0;
                // Same mapping as the UI: requested level N becomes tier_position N-1.
                $newTier->tier_position = $levels[$idx] > 0 ? $levels[$idx] - 1 : 0;
                $newTier->insert_user = null;
                $newTier->insert_date = $now;
                if (!$newTier->create()) {
                    throw new \RuntimeException('Agent ' . $extension . ' could not be added to the queue');
                }
                $added[] = $extension;
                $agentsMap[] = [$extension => $agentUuid];
            }

            $this->db->commit();

            if ($added) {
                $queueScript = $this->redis->hGet('queueSettings' . $qExt . '@' . $domain, 'queue_script');
                $this->apiEmit('broadcast5', [
                    'extension'    => $qExt,
                    'queue_script' => $queueScript,
                    'subdomain'    => $domain,
                    'agents'       => $added,
                    'agents2'      => $agentsMap,
                    'type'         => 1,
                ]);
            }
            $this->apiUnlinkCache(['configuration.callcenter.conf.' . gethostname()]);
            $this->apiEmit('reloadqueue', ['extension' => $qExt, 'subdomain' => $domain]);
            $this->apiAudit($ctx, 'queue-agents', 'add', ['queue_extension' => (string)$qExt, 'agents' => $added]);

            return $this->apiOk([
                'message'          => 'Successfully, updated queue.',
                'queue_extension'  => (string)$qExt,
                'added'            => $added,
                'already_in_queue' => $already,
                'not_found'        => $notFound,
                'no_user'          => $noUser,
            ]);
        } catch (\Throwable $e) {
            return $this->apiQFailure($e);
        }
    }

public function removeAgentsQueueAction()
    {
        $ctx = $this->apiBegin('remove_agents_queue', ['queue_extension', 'extensions']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];
        $data = $ctx['data'];

        try {
            $this->apiQFlushCaches($domain);
            $qExt = $this->apiQExtension($data['queue_extension'], 'queue_extension');
            $queue = $this->db->fetchOne(
                'SELECT call_center_queue_uuid FROM v_call_center_queues WHERE domain_uuid = :d AND queue_extension::text = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => (string)$qExt]
            );
            if (!$queue) {
                return $this->apiFail('Queue non-existing', 404);
            }
            $queueUuid = (string)$queue['call_center_queue_uuid'];

            $extensions = $this->apiQExtList($data['extensions']);
            $resolved = $this->apiQResolveExtensions($domainUuid, $extensions);
            $notFound = [];
            $notInQueue = [];
            $removed = [];
            $emits = [];

            $this->db->begin();
            foreach ($extensions as $extension) {
                $info = $resolved[$extension] ?? null;
                if ($info === null) {
                    $notFound[] = $extension;
                    continue;
                }
                $contacts = ['user/' . $extension . '@' . $domain];
                if ((string)$info['username'] !== '') {
                    $contacts[] = 'user/' . $info['username'] . '@' . $domain;
                }
                $ph = [];
                $params = ['d' => $domainUuid, 'q' => $queueUuid, 'qe' => (string)$qExt];
                foreach ($contacts as $i => $c) {
                    $ph[] = ':c' . $i;
                    $params['c' . $i] = $c;
                }
                $rows = $this->db->fetchAll(
                    'SELECT t.call_center_tier_uuid, t.call_center_agent_uuid FROM v_call_center_tiers t '
                    . 'JOIN v_call_center_agents a ON a.call_center_agent_uuid = t.call_center_agent_uuid '
                    . 'WHERE t.domain_uuid = :d AND t.call_center_queue_uuid = :q AND a.domain_uuid = :d '
                    . 'AND a.queue_extension::text = :qe AND a.agent_contact IN (' . implode(',', $ph) . ')',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    $params
                );
                if (!$rows) {
                    $notInQueue[] = $extension;
                    continue;
                }
                foreach ($rows as $row) {
                    $this->db->execute(
                        'DELETE FROM v_call_center_tiers WHERE domain_uuid = :d AND call_center_tier_uuid = :t',
                        ['d' => $domainUuid, 't' => (string)$row['call_center_tier_uuid']]
                    );
                    $emits[] = (string)$row['call_center_agent_uuid'];
                }
                $removed[] = $extension;
            }
            $this->db->commit();

            foreach ($removed as $extension) {
                $this->redis->lrem('queueAgents:' . $qExt . '@' . $domain, 0, $extension);
            }
            $this->apiUnlinkCache(['configuration.callcenter.conf.' . gethostname()]);
            foreach ($emits as $agentUuid) {
                $this->apiEmit('deletetier', [
                    'extension' => $qExt,
                    'subdomain' => $domain,
                    'agent'     => $agentUuid,
                ]);
            }
            $this->apiEmit('reloadqueue', ['extension' => $qExt, 'subdomain' => $domain]);
            $this->apiAudit($ctx, 'queue-agents', 'remove', ['queue_extension' => (string)$qExt, 'agents' => $removed]);

            return $this->apiOk([
                'message'         => 'Successfully, updated queue.',
                'queue_extension' => (string)$qExt,
                'removed'         => $removed,
                'not_in_queue'    => $notInQueue,
                'not_found'       => $notFound,
            ]);
        } catch (\Throwable $e) {
            return $this->apiQFailure($e);
        }
    }

/* ------------------------------------------------------------------ */
    /* POST /index/addAgent                                                */
    /* ------------------------------------------------------------------ */
    public function addAgentAction()
    {
        $ctx = $this->apiBegin('add_agent', ['extension', 'first_name', 'last_name', 'email']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domainUuid = $ctx['domain_uuid'];
        $domain = $ctx['domain'];

        try {
            $in = $this->apiAgentValidate($data, true);
            if (is_string($in)) {
                return $this->apiFail($in, 400);
            }
            $in['first_name'] = PersonName::capitalize($in['first_name']);
            $in['last_name'] = PersonName::capitalize($in['last_name']);
            $extension = $in['extension'];

            // Group (default "user").
            $group = $this->apiAgentResolveGroup($domainUuid, $in['group'] ?? 'user');
            if ($group === null) {
                return $this->apiFail('Unknown or not allowed group.', 400);
            }

            // Duplicate e-mail inside the domain.
            if ($this->apiAgentEmailTaken($domainUuid, $in['email'], null)) {
                return $this->apiFail('The provided email address is already registered. Please use a different one.', 409);
            }

            // Extension limit (Redis <domain_uuid>-ext, plus the 4 reserved numeric extensions).
            $limit = $this->redis->get($domainUuid . '-ext');
            if ($limit) {
                $row = $this->db->fetchOne(
                    "SELECT COUNT(*) AS c FROM v_extensions WHERE domain_uuid = :d AND extension ~ '^[0-9]+$'",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid]
                );
                if (((int)$limit + 4) < (int)($row['c'] ?? 0)) {
                    return $this->apiFail('Sorry, extension limit reached. Please upgrade package to create new extensions.', 409);
                }
            }

            // The number must not be a SIP ID (force_ping) nor used by anything else.
            $clash = $this->db->fetchOne(
                'SELECT 1 AS x FROM q_call_behaviors WHERE domain_uuid = :d AND force_ping = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extension]
            );
            if ($clash) {
                return $this->apiFail('Sorry, SIP ID already exists.', 409);
            }
            $owner = $this->apiAgentNumberOwner($domainUuid, $extension);
            if ($owner !== null) {
                return $this->apiFail('Sorry, extension ' . $extension . ' is already used by ' . $owner . '.', 409);
            }

            // Outbound caller ID (must be one of the domain's DIDs, like the UI).
            $outName = null;
            $outNumber = null;
            if (($in['outbound_caller_id_number'] ?? '') !== '') {
                $did = $this->db->fetchOne(
                    'SELECT did_number FROM q_dids WHERE domain_uuid = :d AND did_number = :n LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'n' => $in['outbound_caller_id_number']]
                );
                if (!$did) {
                    return $this->apiFail('outbound_caller_id_number must be one of the domain numbers.', 400);
                }
                $outNumber = (string)$did['did_number'];
                $outName = ($in['outbound_caller_id_name'] ?? '') !== '' ? $in['outbound_caller_id_name'] : $outNumber;
            } else {
                $did = $this->db->fetchOne(
                    'SELECT did_number FROM q_dids WHERE domain_uuid = :d AND outbound = 1 LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid]
                );
                if ($did) {
                    $outNumber = (string)$did['did_number'];
                    $outName = ($in['outbound_caller_id_name'] ?? '') !== '' ? $in['outbound_caller_id_name'] : $outNumber;
                } else {
                    $outNumber = $extension;
                    $outName = ($in['outbound_caller_id_name'] ?? '') !== ''
                        ? $in['outbound_caller_id_name']
                        : $in['first_name'] . ' ' . $in['last_name'];
                }
            }

            $userUuid = $this->apiUuid();
            $extensionUuid = $this->apiUuid();
            $voicemailUuid = $this->apiUuid();
            $username = bin2hex(random_bytes(10));
            $accountPassword = base64_encode(random_bytes(16));   // never returned or logged
            $sipPassword = base64_encode(random_bytes(16));       // never returned or logged
            $now = date('Y-m-d H:i:s');
            $fullName = $in['first_name'] . ' ' . $in['last_name'];
            $description = ($in['description'] ?? '') !== ''
                ? $in['description']
                : $username . ' ' . $fullName . ' ' . $in['email'];
            $noAnswer = '*99' . $extension;

            $this->db->begin();
            try {
                $qUser = new QUsers();
                $qUser->user_uuid = $userUuid;
                $qUser->domain_uuid = $domainUuid;
                $qUser->first_name = $in['first_name'];
                $qUser->last_name = $in['last_name'];
                $qUser->extension_enabled = true;
                $qUser->insert_date = $now;
                $qUser->user_email = $in['email'];
                $qUser->password = password_hash($accountPassword, PASSWORD_DEFAULT);
                $this->apiAgentSave($qUser, 'user');

                $vUser = new VUsers();
                $vUser->username = $username;
                $vUser->user_uuid = $userUuid;
                $vUser->domain_uuid = $domainUuid;
                $vUser->user_email = $in['email'];
                $vUser->password = password_hash(base64_encode(random_bytes(16)), PASSWORD_BCRYPT);
                $vUser->user_status = 'Logged Out';
                $vUser->user_enabled = 'true';
                $vUser->api_key = base64_encode(random_bytes(32));
                $vUser->image = null;
                $vUser->first_name = $in['first_name'];
                $vUser->last_name = $in['last_name'];
                $vUser->insert_user = $userUuid;
                $vUser->add_user = null;
                $vUser->add_date = null;
                $vUser->insert_date = $now;
                $this->apiAgentSave($vUser, 'user');

                $vExtension = new VExtensions();
                $vExtension->extension_uuid = $extensionUuid;
                $vExtension->domain_uuid = $domainUuid;
                $vExtension->extension = $extension;
                $vExtension->number_alias = $username;
                $vExtension->password = $sipPassword;
                $vExtension->enabled = 'true';
                $vExtension->extension_type = 'default';
                $vExtension->accountcode = $domain;
                $vExtension->directory_visible = 'true';
                $vExtension->directory_exten_visible = 'true';
                $vExtension->limit_max = '5';
                $vExtension->limit_destination = '!USER_BUSY';
                $vExtension->effective_caller_id_name = $fullName;
                $vExtension->effective_caller_id_number = $extension;
                $vExtension->outbound_caller_id_name = $outName;
                $vExtension->outbound_caller_id_number = $outNumber;
                $vExtension->do_not_disturb = 'false';
                $vExtension->forward_all_enabled = 'false';
                $vExtension->forward_all_enabled2 = 'false';
                $vExtension->forward_busy_enabled = 'false';
                $vExtension->forward_busy_enabled2 = 'false';
                $vExtension->forward_no_answer_enabled = 'true';
                $vExtension->forward_no_answer_enabled2 = 'true';
                $vExtension->forward_user_not_registered_enabled = 'false';
                $vExtension->forward_user_not_registered_enabled2 = 'false';
                $vExtension->forward_no_answer_destination = $noAnswer;
                $vExtension->forward_no_answer_destination2 = $noAnswer;
                $vExtension->voicemail_enabled = 'enabled';
                $vExtension->voicemail_file = 'attach';
                $vExtension->call_screen_enabled = 'false';
                $vExtension->user_context = $domain;
                $vExtension->voicemail_uuid = $voicemailUuid;
                $vExtension->description = $description;
                $vExtension->call_timeout = 30;
                $vExtension->call_timeout2 = 30;
                $vExtension->insert_user = $userUuid;
                $vExtension->insert_date = $now;
                $this->apiAgentSave($vExtension, 'extension');

                $vExtensionUser = new VExtensionUsers();
                $vExtensionUser->extension_user_uuid = $this->apiUuid();
                $vExtensionUser->domain_uuid = $domainUuid;
                $vExtensionUser->user_uuid = $userUuid;
                $vExtensionUser->extension_uuid = $extensionUuid;
                $this->apiAgentSave($vExtensionUser, 'extension link');

                $vVoicemail = new VVoicemails();
                $vVoicemail->domain_uuid = $domainUuid;
                $vVoicemail->voicemail_uuid = $voicemailUuid;
                $vVoicemail->voicemail_id = $extension;
                $vVoicemail->voicemail_mail_to = $in['email'];
                $vVoicemail->voicemail_password = (string)random_int(1000, 9999);
                $vVoicemail->voicemail_transcription_enabled = 'false';
                $vVoicemail->voicemail_file = 'attach';
                $vVoicemail->voicemail_local_after_email = 'true';
                $vVoicemail->voicemail_enabled = 'true';
                $this->apiAgentSave($vVoicemail, 'voicemail');

                $vUserGroup = new VUserGroups();
                $vUserGroup->user_group_uuid = $this->apiUuid();
                $vUserGroup->user_uuid = $userUuid;
                $vUserGroup->group_uuid = $group['group_uuid'];
                $vUserGroup->group_name = $group['group_name'];
                $vUserGroup->domain_uuid = $domainUuid;
                $this->apiAgentSave($vUserGroup, 'group');

                $vAgent = new VCallCenterAgents();
                $vAgent->call_center_agent_uuid = $this->apiUuid();
                $vAgent->domain_uuid = $domainUuid;
                $vAgent->user_uuid = $userUuid;
                $vAgent->agent_name = $fullName;
                $vAgent->agent_type = 'callback';
                $vAgent->agent_call_timeout = 30;
                $vAgent->agent_id = $extension;
                $vAgent->agent_contact = 'user/' . $extension . '@' . $domain;
                $vAgent->agent_status = 'Available';
                $vAgent->agent_max_no_answer = 120;
                $this->apiAgentSave($vAgent, 'agent');

                $vPin = new VPinNumbers();
                $vPin->domain_uuid = $domainUuid;
                $vPin->pin_number_uuid = $this->apiUuid();
                $vPin->pin_number = (string)random_int(1000, 9999);
                $vPin->accountcode = $domain;
                $vPin->enabled = 'true';
                $vPin->description = $extension;
                $vPin->insert_date = $now;
                $this->apiAgentSave($vPin, 'pin number');

                $behavior = new QCallBehaviors();
                $behavior->domain_uuid = $domainUuid;
                $behavior->extension_uuid = $extensionUuid;
                $behavior->extension = $extension;
                $behavior->force_ping = null;
                $behavior->forward_no_answer_destination = $noAnswer . ',' . $noAnswer . ',' . $noAnswer . ',' . $noAnswer . ',' . $noAnswer . ',';
                $behavior->forward_no_answer_destination2 = $behavior->forward_no_answer_destination;
                $behavior->forward_no_answer_timeout = '30,30,30,30,30';
                $behavior->forward_no_answer_timeout2 = '30,30,30,30,30';
                $behavior->forward_no_answer_enabled = 'true,true,true,true,true';
                $behavior->forward_no_answer_enabled2 = 'true,true,true,true,true';
                $behavior->type = 1;
                $behavior->enabled = 1;
                if (($in['mobile_number'] ?? '') !== '') {
                    $behavior->mobile_number = $in['mobile_number'];
                }
                $behavior->insert_date = $now;
                $this->apiAgentSave($behavior, 'call behavior');

                // Chameleon modes: the new extension joins mode1 and permission4 (as the UI does).
                $this->apiAgentModesList($domainUuid, $extension, true);

                $this->apiAgentRebuildChangeDest($domainUuid, $domain);

                $this->db->commit();
            } catch (\Throwable $e) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $ignored) {
                }
                throw $e;
            }
        } catch (\DomainException $e) {
            return $this->apiFail($e->getMessage(), 409);
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }

        // ---- after commit: filesystem + Redis + caches (failures here never undo the account) ----
        try {
            $path = '/var/lib/freeswitch/recordings/' . $domain . '/archive';
            if (!is_dir($path)) {
                @mkdir($path, 0777, true);
            }
            $sub = $domain;
            $r = $this->redis;
            $r->set('ringqtalk_config:' . $extension . ':' . $domainUuid, json_encode([
                'buttonText'          => 'Click2Talk',
                'buttonColor'         => '#4CAF50',
                'enableContactDetail' => 1,
                'uiType'              => 'new',
                'click2talkMode'      => '1',
            ]));
            $this->apiAgentFlushLists($sub);
            $r->set($extension . '+' . $sub, '0');
            $r->set($extension . '-' . $sub . '-chameleon', '1');
            $r->set($extension . '-' . $sub . '-permission', '10');
            $r->del($extension . '@' . $sub . '-macAddresses');
            $this->apiAgentResetForwardKeys($extension, $sub);
            $this->apiUnlinkCache(['dialplan.' . $sub, 'directory.' . $extension . '@' . $sub]);
            $r->del($domainUuid . '-extensions-cache');
        } catch (\Throwable $e) {
            error_log('addAgent post-commit: ' . $e->getMessage());
        }

        $this->apiAudit($ctx, 'extensions', 'add', ['extension' => $extension, 'email' => $in['email']]);

        return $this->apiOk([
            'message'        => 'Extension successfully created.',
            'extension'      => $extension,
            'extension_uuid' => $extensionUuid,
            'user_uuid'      => $userUuid,
            'username'       => $username,
        ]);
    }

/* ------------------------------------------------------------------ */
    /* POST /index/updateAgent                                             */
    /* ------------------------------------------------------------------ */
    public function updateAgentAction()
    {
        $ctx = $this->apiBegin('update_agent', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domainUuid = $ctx['domain_uuid'];
        $domain = $ctx['domain'];

        try {
            $in = $this->apiAgentValidate($data, false);
            if (is_string($in)) {
                return $this->apiFail($in, 400);
            }
            $extension = $in['extension'];

            $touch = ['first_name', 'last_name', 'email', 'group', 'description',
                      'outbound_caller_id_name', 'outbound_caller_id_number', 'mobile_number'];
            $provided = array_values(array_filter($touch, static function ($k) use ($data) {
                return array_key_exists($k, $data);
            }));
            if (!$provided) {
                return $this->apiFail('Nothing to update. Provide at least one of: ' . implode(', ', $touch), 400);
            }

            $ext = $this->db->fetchOne(
                'SELECT e.extension_uuid, e.number_alias, e.description, e.effective_caller_id_name, eu.user_uuid
                   FROM v_extensions e
                   LEFT JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid AND eu.domain_uuid = e.domain_uuid
                  WHERE e.domain_uuid = :d AND e.extension = :e LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extension]
            );
            if (!$ext || empty($ext['user_uuid'])) {
                return $this->apiFail('Extension not found.', 404);
            }
            $userUuid = (string)$ext['user_uuid'];
            $now = date('Y-m-d H:i:s');

            $cur = $this->db->fetchOne(
                'SELECT q.first_name, q.last_name, v.user_email
                   FROM v_users v LEFT JOIN q_users q ON q.user_uuid = v.user_uuid
                  WHERE v.domain_uuid = :d AND v.user_uuid = :u',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'u' => $userUuid]
            );
            if (!$cur) {
                return $this->apiFail('Extension not found.', 404);
            }
            $oldFirst = (string)($cur['first_name'] ?? '');
            $oldLast = (string)($cur['last_name'] ?? '');
            $oldEmail = (string)($cur['user_email'] ?? '');

            $newFirst = isset($in['first_name']) ? PersonName::capitalize($in['first_name']) : $oldFirst;
            $newLast = isset($in['last_name']) ? PersonName::capitalize($in['last_name']) : $oldLast;
            $newEmail = $in['email'] ?? $oldEmail;
            $nameChanged = ($newFirst !== $oldFirst || $newLast !== $oldLast);
            $emailChanged = ($newEmail !== $oldEmail);

            if (array_key_exists('email', $in) && $emailChanged
                && $this->apiAgentEmailTaken($domainUuid, $newEmail, $userUuid)) {
                return $this->apiFail('The provided email address is already registered. Please use a different one.', 409);
            }

            $group = null;
            if (array_key_exists('group', $in)) {
                $group = $this->apiAgentResolveGroup($domainUuid, $in['group']);
                if ($group === null) {
                    return $this->apiFail('Unknown or not allowed group.', 400);
                }
            }

            // Outbound caller ID: "" clears it (like the UI), a value must be one of the domain DIDs.
            $setOutbound = false;
            $outName = null;
            $outNumber = null;
            if (array_key_exists('outbound_caller_id_number', $in)) {
                $setOutbound = true;
                if ($in['outbound_caller_id_number'] === '') {
                    $outName = ($in['outbound_caller_id_name'] ?? '') !== '' ? $in['outbound_caller_id_name'] : '';
                    $outNumber = '';
                } else {
                    $did = $this->db->fetchOne(
                        'SELECT did_number FROM q_dids WHERE domain_uuid = :d AND did_number = :n LIMIT 1',
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        ['d' => $domainUuid, 'n' => $in['outbound_caller_id_number']]
                    );
                    if (!$did) {
                        return $this->apiFail('outbound_caller_id_number must be one of the domain numbers.', 400);
                    }
                    $outNumber = (string)$did['did_number'];
                    $outName = ($in['outbound_caller_id_name'] ?? '') !== '' ? $in['outbound_caller_id_name'] : $outNumber;
                }
            } elseif (array_key_exists('outbound_caller_id_name', $in)) {
                $setOutbound = true;
                $outName = $in['outbound_caller_id_name'];
            }

            // Extension description: explicit value wins; otherwise keep the UI's
            // "<username> <first> <last> <email>" pattern only when it was still in use.
            $newDescription = null;
            if (array_key_exists('description', $in)) {
                $newDescription = $in['description'];
            } elseif ($nameChanged || $emailChanged) {
                $oldAuto = $ext['number_alias'] . ' ' . $oldFirst . ' ' . $oldLast . ' ' . $oldEmail;
                if ((string)$ext['description'] === $oldAuto) {
                    $newDescription = $ext['number_alias'] . ' ' . $newFirst . ' ' . $newLast . ' ' . $newEmail;
                }
            }

            $this->db->begin();
            try {
                if ($nameChanged) {
                    $this->db->execute(
                        'UPDATE q_users SET first_name = :f, last_name = :l, update_date = :t WHERE user_uuid = :u',
                        ['f' => $newFirst, 'l' => $newLast, 't' => $now, 'u' => $userUuid]
                    );
                    $full = $newFirst . ' ' . $newLast;
                    $this->db->execute(
                        'UPDATE q_wrapupnotes_list SET username = :n WHERE extension = :e AND domain_uuid = :d',
                        ['n' => $full, 'e' => $extension, 'd' => $domainUuid]
                    );
                    $this->db->execute(
                        'UPDATE q_auxcodes_list SET username = :n WHERE username = :o AND domain_uuid = :d',
                        ['n' => $full, 'o' => $oldFirst . ' ' . $oldLast, 'd' => $domainUuid]
                    );
                    $this->db->execute(
                        'UPDATE v_call_center_agents SET agent_name = :n, update_date = :t WHERE domain_uuid = :d AND user_uuid = :u',
                        ['n' => trim($full), 't' => $now, 'd' => $domainUuid, 'u' => $userUuid]
                    );
                    $this->db->execute(
                        'UPDATE v_extensions SET effective_caller_id_name = :n, update_date = :t WHERE domain_uuid = :d AND extension_uuid = :x',
                        ['n' => $full, 't' => $now, 'd' => $domainUuid, 'x' => $ext['extension_uuid']]
                    );
                }
                if ($emailChanged) {
                    $this->db->execute(
                        'UPDATE v_users SET user_email = :m, update_date = :t WHERE domain_uuid = :d AND user_uuid = :u',
                        ['m' => $newEmail, 't' => $now, 'd' => $domainUuid, 'u' => $userUuid]
                    );
                    $this->db->execute(
                        'UPDATE v_voicemails SET voicemail_mail_to = :m, update_date = :t WHERE domain_uuid = :d AND voicemail_id = :e',
                        ['m' => $newEmail, 't' => $now, 'd' => $domainUuid, 'e' => $extension]
                    );
                }
                if ($group !== null) {
                    $this->db->execute(
                        'UPDATE v_user_groups SET group_uuid = :g, group_name = :n, update_date = :t WHERE domain_uuid = :d AND user_uuid = :u',
                        ['g' => $group['group_uuid'], 'n' => $group['group_name'], 't' => $now, 'd' => $domainUuid, 'u' => $userUuid]
                    );
                }
                if ($newDescription !== null) {
                    $this->db->execute(
                        'UPDATE v_extensions SET description = :s, update_date = :t WHERE domain_uuid = :d AND extension_uuid = :x',
                        ['s' => $newDescription, 't' => $now, 'd' => $domainUuid, 'x' => $ext['extension_uuid']]
                    );
                }
                if ($setOutbound) {
                    if ($outNumber === null) {
                        $this->db->execute(
                            'UPDATE v_extensions SET outbound_caller_id_name = :n, update_date = :t WHERE domain_uuid = :d AND extension_uuid = :x',
                            ['n' => $outName, 't' => $now, 'd' => $domainUuid, 'x' => $ext['extension_uuid']]
                        );
                    } else {
                        $this->db->execute(
                            'UPDATE v_extensions SET outbound_caller_id_name = :n, outbound_caller_id_number = :c, update_date = :t WHERE domain_uuid = :d AND extension_uuid = :x',
                            ['n' => $outName, 'c' => $outNumber, 't' => $now, 'd' => $domainUuid, 'x' => $ext['extension_uuid']]
                        );
                    }
                }
                if (array_key_exists('mobile_number', $in)) {
                    $this->db->execute(
                        'UPDATE q_call_behaviors SET mobile_number = :m, update_date = :t WHERE domain_uuid = :d AND extension_uuid = :x',
                        ['m' => $in['mobile_number'] === '' ? null : $in['mobile_number'], 't' => $now, 'd' => $domainUuid, 'x' => $ext['extension_uuid']]
                    );
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $ignored) {
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }

        try {
            $sub = $domain;
            $this->redis->del($domainUuid . '-extensions-cache');
            $this->apiAgentFlushLists($sub);
            $this->apiUnlinkCache([
                'dialplan.' . $sub,
                'directory.' . $extension . '@' . $sub,
                'directory.' . $ext['number_alias'] . '@' . $sub,
            ]);
        } catch (\Throwable $e) {
            error_log('updateAgent post-commit: ' . $e->getMessage());
        }

        $this->apiAudit($ctx, 'extensions', 'update', ['extension' => $extension, 'fields' => $provided]);

        return $this->apiOk([
            'message'   => 'Successfully updated extension.',
            'extension' => $extension,
            'updated'   => $provided,
        ]);
    }

/* ------------------------------------------------------------------ */
    /* POST /index/deleteAgent                                             */
    /* ------------------------------------------------------------------ */
    public function deleteAgentAction()
    {
        $ctx = $this->apiBegin('delete_agent', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domainUuid = $ctx['domain_uuid'];
        $domain = $ctx['domain'];

        $extension = trim((string)($data['extension'] ?? ''));
        if (!preg_match('/^[0-9]{4}$/', $extension)) {
            return $this->apiFail('extension must be exactly 4 digits.', 400);
        }

        $emits = [];
        $messageFiles = [];
        $forcePing = null;
        $ext = null;
        try {
            $ext = $this->db->fetchOne(
                'SELECT extension_uuid, number_alias FROM v_extensions WHERE domain_uuid = :d AND extension = :e LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extension]
            );
            if (!$ext) {
                return $this->apiFail('Extension not found.', 404);
            }
            $extUuid = (string)$ext['extension_uuid'];
            $link = $this->db->fetchOne(
                'SELECT user_uuid FROM v_extension_users WHERE domain_uuid = :d AND extension_uuid = :x LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'x' => $extUuid]
            );
            $userUuid = $link && !empty($link['user_uuid']) ? (string)$link['user_uuid'] : null;
            $D = ['d' => $domainUuid];

            $this->db->begin();
            try {
                // v_extensions first, then BLF + the user (UI order).
                $this->db->execute('DELETE FROM v_extensions WHERE domain_uuid = :d AND extension_uuid = :x', $D + ['x' => $extUuid]);
                if ($userUuid !== null) {
                    $this->db->execute(
                        'DELETE FROM q_blfs WHERE domain_uuid = :d AND extension = :e AND user_uuid = :u',
                        $D + ['e' => $extension, 'u' => $userUuid]
                    );
                    $this->db->execute('DELETE FROM v_users WHERE domain_uuid = :d AND user_uuid = :u', $D + ['u' => $userUuid]);
                    $this->db->execute('DELETE FROM v_user_groups WHERE domain_uuid = :d AND user_uuid = :u', $D + ['u' => $userUuid]);

                    // Agent rows, their tiers (one deletetier emit per tier, with the queue's extension).
                    $agents = $this->db->fetchAll(
                        'SELECT call_center_agent_uuid FROM v_call_center_agents WHERE domain_uuid = :d AND user_uuid = :u',
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        $D + ['u' => $userUuid]
                    );
                    foreach ($agents as $agent) {
                        $agentUuid = (string)$agent['call_center_agent_uuid'];
                        $tiers = $this->db->fetchAll(
                            'SELECT t.call_center_tier_uuid, q.queue_extension
                               FROM v_call_center_tiers t
                               LEFT JOIN v_call_center_queues q ON q.call_center_queue_uuid = t.call_center_queue_uuid AND q.domain_uuid = t.domain_uuid
                              WHERE t.domain_uuid = :d AND t.call_center_agent_uuid = :a',
                            \Phalcon\Db\Enum::FETCH_ASSOC,
                            $D + ['a' => $agentUuid]
                        );
                        foreach ($tiers as $tier) {
                            if (!empty($tier['queue_extension'])) {
                                $emits[] = [
                                    'extension' => (string)$tier['queue_extension'],
                                    'subdomain' => $domain,
                                    'agent'     => $agentUuid,
                                ];
                            }
                        }
                        $this->db->execute(
                            'DELETE FROM v_call_center_tiers WHERE domain_uuid = :d AND call_center_agent_uuid = :a',
                            $D + ['a' => $agentUuid]
                        );
                        $this->db->execute(
                            'DELETE FROM v_call_center_agents WHERE domain_uuid = :d AND call_center_agent_uuid = :a',
                            $D + ['a' => $agentUuid]
                        );
                    }
                }

                // Dialplans (schedules etc.) numbered with this extension, with their details.
                $this->apiAgentDeleteDialplans($domainUuid, 'dialplan_number = :n', ['n' => $extension]);

                // Voicemail + messages.
                $vm = $this->db->fetchOne(
                    'SELECT voicemail_uuid FROM v_voicemails WHERE domain_uuid = :d AND voicemail_id = :e LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    $D + ['e' => $extension]
                );
                if ($vm) {
                    $msgs = $this->db->fetchAll(
                        'SELECT voicemail_message_uuid FROM v_voicemail_messages WHERE domain_uuid = :d AND voicemail_uuid = :v',
                        \Phalcon\Db\Enum::FETCH_ASSOC,
                        $D + ['v' => $vm['voicemail_uuid']]
                    );
                    foreach ($msgs as $m) {
                        $messageFiles[] = 'msg_' . $m['voicemail_message_uuid'];
                    }
                    $this->db->execute('DELETE FROM v_voicemails WHERE domain_uuid = :d AND voicemail_uuid = :v', $D + ['v' => $vm['voicemail_uuid']]);
                    $this->db->execute('DELETE FROM v_voicemail_messages WHERE domain_uuid = :d AND voicemail_uuid = :v', $D + ['v' => $vm['voicemail_uuid']]);
                }

                // Call behaviours (+ the SIP-ID dialplan named after force_ping).
                $cb = $this->db->fetchOne(
                    'SELECT force_ping FROM q_call_behaviors WHERE domain_uuid = :d AND extension_uuid = :x LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    $D + ['x' => $extUuid]
                );
                if ($cb) {
                    if (!empty($cb['force_ping'])) {
                        $forcePing = (string)$cb['force_ping'];
                        $this->apiAgentDeleteDialplans($domainUuid, 'dialplan_name = :n', ['n' => $forcePing]);
                    }
                    $this->db->execute('DELETE FROM q_call_behaviors WHERE domain_uuid = :d AND extension_uuid = :x', $D + ['x' => $extUuid]);
                }

                $this->db->execute('DELETE FROM v_extension_users WHERE domain_uuid = :d AND extension_uuid = :x', $D + ['x' => $extUuid]);

                // Remove the extension from the work / break schedule lists.
                $ts = $this->db->fetchOne(
                    'SELECT work_extensions, break_extensions FROM q_time_schedules WHERE domain_uuid = :d LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    $D
                );
                if ($ts && (!empty($ts['work_extensions']) || !empty($ts['break_extensions']))) {
                    $this->db->execute(
                        'UPDATE q_time_schedules SET work_extensions = :w, break_extensions = :b WHERE domain_uuid = :d',
                        $D + [
                            'w' => $this->apiAgentWithout((string)($ts['work_extensions'] ?? ''), $extension),
                            'b' => $this->apiAgentWithout((string)($ts['break_extensions'] ?? ''), $extension),
                        ]
                    );
                }

                $this->db->execute('DELETE FROM v_pin_numbers WHERE domain_uuid = :d AND description = :e', $D + ['e' => $extension]);
                $this->db->execute('DELETE FROM q_devices WHERE domain_uuid = :d AND extension = :e', $D + ['e' => $extension]);

                $this->apiAgentModesList($domainUuid, $extension, false);

                $this->db->commit();
            } catch (\Throwable $e) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $ignored) {
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }

        // ---- after commit: sockets, files, Redis, caches ----
        foreach ($emits as $payload) {
            $this->apiEmit('deletetier', $payload);
        }
        try {
            $sub = $domain;
            $dir = '/var/lib/freeswitch/storage/voicemail/default/' . $sub . '/' . $extension;
            foreach ($messageFiles as $base) {
                foreach (['.wav', '.mp3'] as $suffix) {
                    if (file_exists($dir . '/' . $base . $suffix)) {
                        @unlink($dir . '/' . $base . $suffix);
                    }
                }
            }
            if (is_dir($dir)) {
                @rmdir($dir);   // only succeeds when empty
            }

            $r = $this->redis;
            if ($forcePing !== null) {
                $r->set('sipIp:' . $forcePing . '@' . $sub, $extension);
            }
            $r->set($extension . '@' . $sub . '-deletion', time());
            $keys = [
                $extension . ':' . $sub . '-parked',
                $extension . ':' . $sub,
                $extension . '@' . $sub,
                $extension . '+' . $sub,
                $extension . '-' . $sub . '-chameleon',
                $extension . '-' . $sub . '-permission',
                $extension . '@' . $sub . '-chameleon',
                $extension . '@' . $sub . '-permission',
                $extension . '@' . $sub . '-internalCall',
                $extension . '@' . $sub . '-callerExtensionInternal',
                'ringqtalk_config:' . $extension . ':' . $domainUuid,
                $domainUuid . '-extensions-cache',
            ];
            foreach (['forward-all-destination', 'forward-busy-destination', 'forward-no-answer-destination',
                      'forward-no-answer-timeout', 'follow-me-destinations', 'forward-all-enabled',
                      'forward-busy-enabled', 'forward-no-answer-enabled', 'forward-me-enabled',
                      'callScreening'] as $k) {
                $keys[] = $extension . '@' . $sub . '-' . $k;
                $keys[] = $extension . '@' . $sub . '-' . $k . '2';
            }
            $this->apiRedisDel($keys);
            $this->apiAgentFlushLists($sub);
            $this->apiUnlinkCache([
                'dialplan.' . $sub,
                'directory.' . $extension . '@' . $sub,
                'directory.' . $ext['number_alias'] . '@' . $sub,
            ]);
        } catch (\Throwable $e) {
            error_log('deleteAgent post-commit: ' . $e->getMessage());
        }

        $this->apiAudit($ctx, 'extensions', 'delete', ['extension' => $extension]);

        return $this->apiOk([
            'message'   => 'Extension successfully deleted.',
            'extension' => $extension,
        ]);
    }

/* ------------------------------------------------------------------ */
    /* 1. query_extension                                                  */
    /* ------------------------------------------------------------------ */

    public function queryExtensionAction()
    {
        $ctx = $this->apiBegin('query_extension');
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        try {
            // List mode: no extension given.
            if (!isset($data['extension']) || trim((string)$data['extension']) === '') {
                $page = max(1, (int)($data['page'] ?? 1));
                $limit = (int)($data['limit'] ?? 25);
                if ($limit < 1) {
                    $limit = 25;
                }
                $limit = min($limit, 100);
                $offset = ($page - 1) * $limit;

                $total = (int)($this->db->fetchOne(
                    'SELECT COUNT(*) AS c FROM v_extensions WHERE domain_uuid = :d',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid]
                )['c'] ?? 0);

                $rows = $this->db->fetchAll(
                    'SELECT e.extension, e.effective_caller_id_name, e.enabled, e.do_not_disturb, e.user_record,
                            u.user_status, q.first_name, q.last_name
                       FROM (SELECT * FROM v_extensions WHERE domain_uuid = :d
                              ORDER BY length(extension), extension LIMIT :l OFFSET :o) e
                  LEFT JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid AND eu.domain_uuid = e.domain_uuid
                  LEFT JOIN v_users u ON u.user_uuid = eu.user_uuid AND u.domain_uuid = e.domain_uuid
                  LEFT JOIN q_users q ON q.user_uuid::text = u.user_uuid::text
                   ORDER BY length(e.extension), e.extension',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'l' => $limit, 'o' => $offset],
                    ['d' => Column::BIND_PARAM_STR, 'l' => Column::BIND_PARAM_INT, 'o' => Column::BIND_PARAM_INT]
                );

                $names = $this->apiExtProfileNames(null);
                $list = [];
                foreach ($rows as $r) {
                    $ext = (string)$r['extension'];
                    if (isset($list[$ext])) {
                        continue; // an extension with several users appears once
                    }
                    $idx = $this->apiExtProfileIndex($ext, $domain);
                    $list[$ext] = [
                        'extension'    => $ext,
                        'name'         => trim((string)$r['first_name'] . ' ' . (string)$r['last_name']),
                        'caller_id_name' => (string)$r['effective_caller_id_name'],
                        'enabled'      => (string)$r['enabled'] === 'true',
                        'do_not_disturb' => (string)$r['do_not_disturb'] === 'true',
                        'user_status'  => $r['user_status'] === null ? null : (string)$r['user_status'],
                        'record'       => $this->apiExtRecordLabel((string)$r['user_record']),
                        'call_profile' => $idx + 1,
                    ];
                }
                return $this->apiOk([
                    'domain'     => $domain,
                    'page'       => $page,
                    'limit'      => $limit,
                    'total'      => $total,
                    'extensions' => array_values($list),
                ]);
            }

            $extension = $this->apiExtNumber($data['extension']);
            if ($extension === null) {
                return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
            }

            $ext = VExtensions::findFirst([
                'conditions' => 'domain_uuid = :d: AND extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $extension],
            ]);
            if (!$ext) {
                return $this->apiFail('Extension not found', 404);
            }

            $behavior = QCallBehaviors::findFirst([
                'conditions' => 'domain_uuid = :d: AND extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $extension],
            ]);
            $names = $this->apiExtProfileNames($behavior);
            $idx = $this->apiExtProfileIndex($extension, $domain);

            $user = $this->db->fetchOne(
                'SELECT u.user_status, u.user_enabled, q.first_name, q.last_name
                   FROM v_extension_users eu
                   JOIN v_users u ON u.user_uuid = eu.user_uuid AND u.domain_uuid = :d
              LEFT JOIN q_users q ON q.user_uuid::text = u.user_uuid::text
                  WHERE eu.domain_uuid = :d AND eu.extension_uuid = :x
                  LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'x' => (string)$ext->extension_uuid]
            ) ?: [];

            $vm = VVoicemails::findFirst([
                'conditions' => 'domain_uuid = :d: AND voicemail_id = :v:',
                'bind'       => ['d' => $domainUuid, 'v' => $extension],
            ]);

            // Registration snapshot written by signal.js; reg_user is number_alias.
            $alias = (string)$ext->number_alias;
            $registrations = [];
            $raw = json_decode((string)$this->redis->get('onlineStatus-' . $domain), true);
            if (is_array($raw)) {
                foreach ($raw as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $u = (string)($row['reg_user'] ?? '');
                    if ($u === '' || ($u !== $alias && $u !== $extension)) {
                        continue;
                    }
                    $expires = isset($row['expires']) ? (int)$row['expires'] : null;
                    $registrations[] = [
                        'user_agent'         => mb_substr((string)($row['realm'] ?? ''), 0, 120),
                        'network_ip'         => (string)($row['network_ip'] ?? ''),
                        'transport'          => (string)($row['network_proto'] ?? ''),
                        'expires_in_seconds' => $expires === null ? null : $expires - time(),
                    ];
                }
            }

            $devices = [];
            foreach (QDevices::find([
                'conditions' => 'domain_uuid = :d: AND extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $extension],
            ]) as $device) {
                $devices[] = [
                    'id'              => (int)$device->id,
                    'type'            => (int)$device->device_type,
                    'version'         => (string)$device->device_version,
                    'app_version'     => (string)$device->version,
                    'receives_calls'  => ((int)$device->enabled) !== 0,
                    'last_login_at'   => $device->update_login === null ? null : (string)$device->update_login,
                ];
            }

            $fwd = function ($enabled, $destination): array {
                return ['enabled' => (string)$enabled === 'true', 'destination' => (string)$destination];
            };

            return $this->apiOk([
                'extension' => [
                    'extension'        => (string)$ext->extension,
                    'first_name'       => (string)($user['first_name'] ?? ''),
                    'last_name'        => (string)($user['last_name'] ?? ''),
                    'caller_id_name'   => (string)$ext->effective_caller_id_name,
                    'outbound_caller_id' => (string)$ext->outbound_caller_id_number,
                    'description'      => mb_substr((string)$ext->description, 0, 200),
                    'enabled'          => (string)$ext->enabled === 'true',
                    'user_status'      => isset($user['user_status']) ? (string)$user['user_status'] : null,
                    'call_profile'     => $idx + 1,
                    'call_profile_name' => $names[$idx] ?? ('Profile ' . ($idx + 1)),
                    'do_not_disturb'   => (string)$ext->do_not_disturb === 'true',
                    'voicemail_enabled' => $vm ? ((string)$vm->voicemail_enabled === 'true') : false,
                    'record'           => $this->apiExtRecordLabel((string)$ext->user_record),
                    'record_control'   => $behavior ? (int)$behavior->record_control : 0,
                    'call_timeout_seconds' => $ext->call_timeout === null ? null : (int)$ext->call_timeout,
                    'max_registrations' => (string)$ext->max_registrations,
                    'forwarding'       => [
                        'all'            => $fwd($ext->forward_all_enabled, $ext->forward_all_destination),
                        'busy'           => $fwd($ext->forward_busy_enabled, $ext->forward_busy_destination),
                        'no_answer'      => $fwd($ext->forward_no_answer_enabled, $ext->forward_no_answer_destination),
                        'not_registered' => $fwd($ext->forward_user_not_registered_enabled, $ext->forward_user_not_registered_destination),
                    ],
                    'follow_me_enabled' => (string)$ext->follow_me_enabled === 'true',
                    'online'           => count($registrations) > 0,
                    'registrations'    => $registrations,
                    'devices'          => $devices,
                    'access'           => $this->apiExtAccess($extension, $domain),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }
    }

/* ------------------------------------------------------------------ */
    /* 2. change_user_status                                               */
    /* ------------------------------------------------------------------ */

    public function changeUserStatusAction()
    {
        $ctx = $this->apiBegin('change_user_status', ['extension', 'status']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        $extension = $this->apiExtNumber($data['extension']);
        if ($extension === null) {
            return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
        }

        try {
            $behavior = QCallBehaviors::findFirst([
                'conditions' => 'domain_uuid = :d: AND extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $extension],
            ]);
            if (!$behavior) {
                return $this->apiFail('Call profile is not configured for this extension', 404);
            }
            $names = $this->apiExtProfileNames($behavior);

            $number = $this->apiExtProfileNumber($data['status'], $names);
            if ($number === null) {
                return $this->apiFail('Invalid status: use available, away, busy, custom1, custom2 or 1-5', 400);
            }
            $index = $number - 1;

            $vExtension = VExtensions::findFirst([
                'conditions' => 'domain_uuid = :d: AND extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $extension],
            ]);
            if (!$vExtension) {
                return $this->apiFail('Extension not found', 404);
            }

            $pick = function ($csv) use ($index) {
                $parts = explode(',', (string)$csv);
                return $parts[$index] ?? null;
            };
            $colors = explode(',', (string)$behavior->call_profiles_colors);
            $simul1 = explode(',', (string)$behavior->simultaneous_destination_enabled);
            $simul2 = explode(',', (string)$behavior->simultaneous_destination_enabled2);

            $rules = QOutboundRules::find([
                'conditions' => 'domain_uuid = :d:',
                'bind'       => ['d' => $domainUuid],
                'order'      => 'dial_plan_order ASC',
            ]);
            $rules = iterator_to_array($rules, false);

            $gatewayUuid = '';
            $destination = (string)$behavior->mobile_number;
            if ($destination !== '') {
                $hit = $this->apiExtOutboundGateway($rules, $destination, $domainUuid);
                if (is_array($hit)) {
                    $gatewayUuid = (string)$hit[0];
                    $destination = (string)$hit[1];
                }
            }

            $this->db->begin();

            if (($simul1[$index] ?? '') === 'true' && $behavior->mobile_number) {
                $behavior->simultaneous = '1';
                $vExtension->parallel_ring = ',sofia/gateway/' . $gatewayUuid . '/' . $destination;
            } else {
                $behavior->simultaneous = '0';
                $vExtension->parallel_ring = null;
            }
            if (($simul2[$index] ?? '') === 'true' && $behavior->mobile_number) {
                $behavior->simultaneous2 = '1';
                $vExtension->parallel_ring2 = ',sofia/gateway/' . $gatewayUuid . '/' . $destination;
            } else {
                $behavior->simultaneous2 = '0';
                $vExtension->parallel_ring2 = null;
            }

            // Copy slot [index] of each CSV column into the live forwarding field.
            foreach (['', '2'] as $s) {
                foreach ([
                    'forward_all_destination', 'forward_all_enabled',
                    'forward_busy_destination', 'forward_busy_enabled',
                    'forward_no_answer_destination', 'forward_no_answer_enabled',
                    'forward_user_not_registered_destination',
                ] as $field) {
                    $col = $field . $s;
                    if ($behavior->{$col}) {
                        $vExtension->{$col} = $pick($behavior->{$col});
                    }
                }
                $tcol = 'forward_no_answer_timeout' . $s;
                if ($behavior->{$tcol}) {
                    $value = $pick($behavior->{$tcol});
                    $vExtension->{'call_timeout' . $s} = empty($value) ? 30 : $value;
                }
            }

            $this->apiExtApplyFollowMe($vExtension, $behavior, $rules, $index, $domain, $domainUuid);

            if (!$vExtension->update()) {
                $this->db->rollback();
                return $this->apiFail('Could not update extension forwarding', 500);
            }

            $vm = VVoicemails::findFirst([
                'conditions' => 'domain_uuid = :d: AND voicemail_id = :v:',
                'bind'       => ['d' => $domainUuid, 'v' => $extension],
            ]);
            if ($vm) {
                $greeting = VVoicemailGreetings::findFirst([
                    'conditions' => 'domain_uuid = :d: AND voicemail_id = :v: AND greeting_name = :g:',
                    'bind'       => ['d' => $domainUuid, 'v' => $extension, 'g' => ['available', 'away', 'busy', 'custom1', 'custom2'][$index]],
                ]);
                if ($greeting) {
                    $vm->greeting_id = $greeting->greeting_id;
                    if (!$vm->update()) {
                        $this->db->rollback();
                        return $this->apiFail('Could not update voicemail greeting', 500);
                    }
                }
            }

            if (!$behavior->update()) {
                $this->db->rollback();
                return $this->apiFail('Could not update call behavior', 500);
            }
            $this->db->commit();

            $this->apiUnlinkCache(['directory.' . $extension . '@' . $domain]);
            $alias = (string)$vExtension->number_alias;
            if ($alias !== '' && $alias !== $extension) {
                $this->apiUnlinkCache(['directory.' . $alias . '@' . $domain]);
            }
            $this->redis->set($extension . '+' . $domain, $index);
            $this->apiEmit('agentprofile', [
                'extension' => $extension,
                'subdomain' => $domain,
                'status'    => $index,
                'color'     => $colors[$index] ?? '',
            ]);
            $this->apiAudit($ctx, 'extension', 'status', ['extension' => $extension, 'call_profile' => $number]);

            return $this->apiOk([
                'message'      => 'Successfully updated call profile.',
                'extension'    => $extension,
                'status'       => $names[$index] ?? ('Profile ' . $number),
                'call_profile' => $number,
                'name'         => $names[$index] ?? ('Profile ' . $number),
            ]);
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
            }
            return $this->apiFailFrom($e, 500);
        }
    }

/* ------------------------------------------------------------------ */
    /* 3. change_recording_status                                          */
    /* ------------------------------------------------------------------ */

    public function changeRecordingStatusAction()
    {
        $ctx = $this->apiBegin('change_recording_status', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        $extension = $this->apiExtNumber($data['extension']);
        if ($extension === null) {
            return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
        }
        if (!array_key_exists('recording', $data)) {
            return $this->apiFail('Missing parameters: recording', 400);
        }

        $rec = $data['recording'];
        if ($rec === true) {
            $rec = 'all';
        } elseif ($rec === false) {
            $rec = 'none';
        }
        $map = ['all' => 'All', 'inbound' => 'Inbound', 'outbound' => 'Outbound', 'none' => ''];
        $key = is_string($rec) ? strtolower(trim($rec)) : '';
        if (!array_key_exists($key, $map)) {
            return $this->apiFail('Invalid recording: use all, inbound, outbound, none (or true/false)', 400);
        }
        $value = $map[$key];

        $control = null;
        if (array_key_exists('record_control', $data) && $data['record_control'] !== null && $data['record_control'] !== '') {
            if (!is_scalar($data['record_control']) || !in_array((string)$data['record_control'], ['0', '1', '2'], true)) {
                return $this->apiFail('Invalid record_control: use 1 or 2', 400);
            }
            $control = (int)$data['record_control'];
        }

        try {
            $ext = $this->db->fetchOne(
                'SELECT number_alias FROM v_extensions WHERE domain_uuid = :d AND extension = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extension]
            );
            if (!$ext) {
                return $this->apiFail('Extension not found', 404);
            }

            $this->db->begin();
            $this->db->execute(
                'UPDATE v_extensions SET user_record = :r WHERE domain_uuid = :d AND extension = :e',
                ['r' => $value, 'd' => $domainUuid, 'e' => $extension]
            );
            $controlUpdated = false;
            if ($control !== null) {
                $controlUpdated = $this->db->execute(
                    'UPDATE q_call_behaviors SET record_control = :c WHERE domain_uuid = :d AND extension = :e',
                    ['c' => $control, 'd' => $domainUuid, 'e' => $extension]
                );
                $controlUpdated = (bool)$controlUpdated && $this->db->affectedRows() > 0;
            }
            $this->db->commit();

            $alias = (string)$ext['number_alias'];
            $names = ['directory.' . $extension . '@' . $domain];
            $keys = ['directory:' . $extension . '@' . $domain];
            if ($alias !== '' && $alias !== $extension) {
                $names[] = 'directory.' . $alias . '@' . $domain;
                $keys[] = 'directory:' . $alias . '@' . $domain;
            }
            $this->apiUnlinkCache($names);
            $this->apiRedisDel([
                $domainUuid . '-extensions-cache',
                'vExtensions-' . $domain,
                'allVusers-' . $domain,
                'allQUsers-' . $domain,
            ]);
            $this->apiExtEslMemcacheDelete($keys);
            $this->apiAudit($ctx, 'extension', 'recording', ['extension' => $extension, 'recording' => $key]);

            $payload = [
                'message'   => 'Successfully updated recording setting.',
                'extension' => $extension,
                'recording' => $key,
            ];
            if ($control !== null) {
                $payload['record_control'] = $control;
                $payload['record_control_updated'] = $controlUpdated;
            }
            return $this->apiOk($payload);
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
            }
            return $this->apiFailFrom($e, 500);
        }
    }

/* ------------------------------------------------------------------ */
    /* 4. disable_extension                                                */
    /* ------------------------------------------------------------------ */

    public function disableExtensionAction()
    {
        $ctx = $this->apiBegin('disable_extension', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        $extension = $this->apiExtNumber($data['extension']);
        if ($extension === null) {
            return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
        }
        $disable = $this->apiExtBool($data['disable'] ?? true);
        if ($disable === null) {
            return $this->apiFail('Invalid disable: use true or false', 400);
        }

        try {
            $vExtension = VExtensions::findFirst([
                'conditions' => 'domain_uuid = :d: AND extension = :e:',
                'bind'       => ['d' => $domainUuid, 'e' => $extension],
            ]);
            if (!$vExtension) {
                return $this->apiFail('Extension not found', 404);
            }

            // Same field changes as AccountsController::extensionAction type 2.
            $this->db->begin();
            if ($disable) {
                $vExtension->enabled = 'false';
                $vExtension->forward_user_not_registered_destination = null;
                $vExtension->forward_user_not_registered_destination2 = null;
                $vExtension->forward_user_not_registered_enabled = 'false';
                $vExtension->forward_user_not_registered_enabled2 = 'false';
            } else {
                $vExtension->enabled = 'true';
                $vExtension->forward_user_not_registered_enabled = 'false';
                $vExtension->forward_user_not_registered_enabled2 = 'false';
            }
            if (!$vExtension->update()) {
                $this->db->rollback();
                return $this->apiFail('Could not update extension', 500);
            }
            $this->db->commit();

            if ($disable) {
                $this->redis->set($extension . '@' . $domain . '-disabled', '1');
            } else {
                $this->redis->del($extension . '@' . $domain . '-disabled');
            }

            $names = [
                'dialplan.' . $domain,
                'directory.' . $extension . '@' . $domain,
            ];
            $alias = (string)$vExtension->number_alias;
            if ($alias !== '' && $alias !== $extension) {
                $names[] = 'directory.' . $alias . '@' . $domain;
            }
            $this->apiUnlinkCache($names);
            $this->apiRedisDel([
                'allVusers-' . $domain,
                'allQUsers-' . $domain,
                'vExtensions-' . $domain,
                'vCallCenterAgents-' . $domain,
                'vCallCenterAgents5-' . $domain,
                'allVCallCenterTiers-' . $domain,
                $domainUuid . '-extensions-cache',
            ]);

            // Current per-extension access flags, never hardcoded.
            $access = $this->apiExtAccess($extension, $domain);
            $this->apiEmit('extensionAccess', [
                'subdomain'   => $domain,
                'extension'   => $extension,
                'enabled'     => (string)$vExtension->enabled === 'true',
                'app'         => $access['app'],
                'webapp'      => $access['webapp'],
                'callcontrol' => $access['callcontrol'],
            ]);
            $this->apiAudit($ctx, 'extension', $disable ? 'disable' : 'enable', ['extension' => $extension]);

            return $this->apiOk([
                'message'   => $disable ? 'Extension disabled.' : 'Extension enabled.',
                'extension' => $extension,
                'enabled'   => !$disable,
                'disabled'  => $disable,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
            }
            return $this->apiFailFrom($e, 500);
        }
    }

/* ------------------------------------------------------------------ */
    /* 5. disable_external_call                                            */
    /* ------------------------------------------------------------------ */

    public function disableExternalCallAction()
    {
        $ctx = $this->apiBegin('disable_external_call', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        $extension = $this->apiExtNumber($data['extension']);
        if ($extension === null) {
            return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
        }
        $disable = $this->apiExtBool($data['disable'] ?? true);
        if ($disable === null) {
            return $this->apiFail('Invalid disable: use true or false', 400);
        }

        try {
            $exists = $this->db->fetchOne(
                'SELECT 1 AS x FROM v_extensions WHERE domain_uuid = :d AND extension = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extension]
            );
            if (!$exists) {
                return $this->apiFail('Extension not found', 404);
            }

            $ruleName = 'block-external-' . $extension;
            $rows = VDialplans::find([
                'conditions' => 'domain_uuid = :d: AND dialplan_name = :n: AND dialplan_description = :s:',
                'bind'       => ['d' => $domainUuid, 'n' => $ruleName, 's' => 'outbound-rule'],
            ]);
            $existing = iterator_to_array($rows, false);

            $aggregate = VDialplans::findFirst([
                'conditions' => 'domain_uuid = :d: AND dialplan_name = :n:',
                'bind'       => ['d' => $domainUuid, 'n' => 'outbound-rules'],
            ]);

            if ($disable && $existing) {
                return $this->apiOk([
                    'message'   => 'External calls are already blocked for this extension.',
                    'extension' => $extension,
                    'external_calls_blocked' => true,
                    'changed'   => false,
                    'rule'      => $ruleName,
                ]);
            }
            if (!$disable && !$existing) {
                return $this->apiOk([
                    'message'   => 'External calls are not blocked for this extension.',
                    'extension' => $extension,
                    'external_calls_blocked' => false,
                    'changed'   => false,
                    'rule'      => $ruleName,
                ]);
            }

            $security = new \Phalcon\Security\Random();
            $this->db->begin();

            if ($disable) {
                $ruleUuid = $security->uuid();
                $xml = $this->apiExtBlockRuleXml($ruleName, $ruleUuid, $extension, $domainUuid);

                // Lowest order of the per-rule rows so the block is evaluated first.
                $min = $this->db->fetchOne(
                    'SELECT MIN(dialplan_order) AS m FROM v_dialplans
                      WHERE domain_uuid = :d AND dialplan_description = :s AND dialplan_enabled = :f',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 's' => 'outbound-rule', 'f' => 'false']
                );
                $order = 50;
                if ($min && $min['m'] !== null && (int)$min['m'] <= 50) {
                    $order = (int)$min['m'] - 1;
                }

                // Per-rule row, same shape as the UI's rule rows (disabled; only the aggregate runs).
                $rule = new VDialplans();
                $rule->domain_uuid = $domainUuid;
                $rule->dialplan_uuid = $ruleUuid;
                $rule->app_uuid = $security->uuid();
                $rule->hostname = null;
                $rule->dialplan_xml = $xml;
                $rule->dialplan_name = $ruleName;
                $rule->dialplan_order = $order;
                $rule->dialplan_context = $domain;
                $rule->dialplan_continue = 'false';
                $rule->dialplan_enabled = 'false';
                $rule->dialplan_description = 'outbound-rule';
                $rule->insert_date = date('Y-m-d H:i:s');
                if (!$rule->create()) {
                    $this->db->rollback();
                    return $this->apiFail('Could not create the block rule', 500);
                }

                if ($aggregate) {
                    $current = (string)$aggregate->dialplan_xml;
                    $aggregate->dialplan_xml = $xml . ($current === '' ? '' : "\n" . $current);
                    $aggregate->dialplan_enabled = 'true';
                    if (!$aggregate->update()) {
                        $this->db->rollback();
                        return $this->apiFail('Could not update the outbound rules dialplan', 500);
                    }
                } else {
                    // No aggregate yet: build it exactly like the UI does from all per-rule rows.
                    $agg = new VDialplans();
                    $agg->domain_uuid = $domainUuid;
                    $agg->dialplan_uuid = $security->uuid();
                    $agg->app_uuid = $security->uuid();
                    $agg->hostname = null;
                    $agg->dialplan_xml = $this->apiExtOutboundRulesXml($domainUuid);
                    $agg->dialplan_name = 'outbound-rules';
                    $agg->dialplan_order = 85;
                    $agg->dialplan_context = $domain;
                    $agg->dialplan_continue = 'false';
                    $agg->dialplan_enabled = 'true';
                    $agg->insert_date = date('Y-m-d H:i:s');
                    if (!$agg->create()) {
                        $this->db->rollback();
                        return $this->apiFail('Could not create the outbound rules dialplan', 500);
                    }
                }
                $this->redis->set($domain . ':outboundParallel', '1');
            } else {
                $blockXml = [];
                foreach ($existing as $row) {
                    $blockXml[] = (string)$row->dialplan_xml;
                    if (!$row->delete()) {
                        $this->db->rollback();
                        return $this->apiFail('Could not remove the block rule', 500);
                    }
                }
                if ($aggregate) {
                    $current = (string)$aggregate->dialplan_xml;
                    $new = $current;
                    $found = true;
                    foreach ($blockXml as $bx) {
                        if ($bx === '' || strpos($new, $bx) === false) {
                            $found = false;
                            break;
                        }
                        $new = str_replace("\n" . $bx, '', $new);
                        $new = str_replace($bx . "\n", '', $new);
                        $new = str_replace($bx, '', $new);
                    }
                    if (!$found) {
                        // Aggregate drifted from the stored rule: rebuild it the way the UI does.
                        $new = $this->apiExtOutboundRulesXml($domainUuid);
                    }
                    $aggregate->dialplan_xml = $new;
                    $aggregate->dialplan_enabled = 'true';
                    if (!$aggregate->update()) {
                        $this->db->rollback();
                        return $this->apiFail('Could not update the outbound rules dialplan', 500);
                    }
                }
            }
            $this->db->commit();

            $this->redis->del('allDialplans-' . $domain);
            $this->apiUnlinkCache(['dialplan.' . $domain]);
            $this->apiAudit($ctx, 'extension', $disable ? 'block-external' : 'unblock-external', ['extension' => $extension]);

            return $this->apiOk([
                'message'   => $disable ? 'External calls blocked for this extension.' : 'External calls re-enabled for this extension.',
                'extension' => $extension,
                'external_calls_blocked' => $disable,
                'changed'   => true,
                'rule'      => $ruleName,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable $ignored) {
            }
            return $this->apiFailFrom($e, 500);
        }
    }

/* ------------------------------------------------------------------ */
    /* 6. send_welcome_email                                               */
    /* ------------------------------------------------------------------ */

    public function sendWelcomeEmailAction()
    {
        $ctx = $this->apiBegin('send_welcome_email');
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        $hasExt = isset($data['extension']) && trim((string)$data['extension']) !== '';
        $hasMail = isset($data['email']) && trim((string)$data['email']) !== '';
        if (!$hasExt && !$hasMail) {
            return $this->apiFail('Missing parameters: extension or email', 400);
        }

        try {
            $user = null;
            if ($hasExt) {
                $extension = $this->apiExtNumber($data['extension']);
                if ($extension === null) {
                    return $this->apiFail('Invalid extension: digits only, 2-6 characters', 400);
                }
                $user = $this->db->fetchOne(
                    'SELECT u.user_uuid, u.user_email, e.extension_uuid, e.extension
                       FROM v_extensions e
                       JOIN v_extension_users eu ON eu.extension_uuid = e.extension_uuid AND eu.domain_uuid = e.domain_uuid
                       JOIN v_users u ON u.user_uuid = eu.user_uuid AND u.domain_uuid = e.domain_uuid
                      WHERE e.domain_uuid = :d AND e.extension = :e
                      LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'e' => $extension]
                );
                if (!$user) {
                    return $this->apiFail('No user is associated with this extension', 404);
                }
            } else {
                $email = trim((string)$data['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return $this->apiFail('Invalid email', 400);
                }
                $user = $this->db->fetchOne(
                    'SELECT u.user_uuid, u.user_email, e.extension_uuid, e.extension
                       FROM v_users u
                  LEFT JOIN v_extension_users eu ON eu.user_uuid = u.user_uuid AND eu.domain_uuid = u.domain_uuid
                  LEFT JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid AND e.domain_uuid = u.domain_uuid
                      WHERE u.domain_uuid = :d AND u.user_email = :m
                      LIMIT 1',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'm' => $email]
                );
                if (!$user) {
                    return $this->apiFail('No user found for this email', 404);
                }
            }

            $email = trim((string)$user['user_email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->apiFail('The user has no valid email address', 422);
            }

            $qUser = QUsers::findFirst([
                'conditions' => 'user_uuid = :u:',
                'bind'       => ['u' => (string)$user['user_uuid']],
            ]);
            if (!$qUser) {
                return $this->apiFail('User profile not found', 404);
            }

            // Same flow as AccountsController::credentailAction (POST).
            $newPassword = (new \Phalcon\Security\Random())->base64(16);
            $qUser->password = password_hash($newPassword, PASSWORD_DEFAULT);
            if (!$qUser->update()) {
                return $this->apiFail('Could not reset the password', 500);
            }
            $verify = random_int(100000000, 999999999);

            try {
                $qrCode = new \chillerlan\QRCode\QRCode();
                $url = 'https://www.' . $this->config->application->publicUrl . ':8443/register/extension/' . $verify . '/' . $email;
                $qrCode->render($url, '/var/www/ringq/public/temp/' . $verify . '.png');
            } catch (\Throwable $e) {
                error_log('api welcome qr: ' . $e->getMessage());
            }

            $this->redis->lrem('register2', 0, $email);
            $this->redis->lpush('register2', [$email]);
            $this->redis->hmset('verify2-' . $email, [
                'verify'         => $verify,
                'username'       => trim((string)$qUser->first_name . ' ' . (string)$qUser->last_name),
                'user_uuid'      => (string)$user['user_uuid'],
                'domain_uuid'    => $domainUuid,
                'extension_uuid' => (string)($user['extension_uuid'] ?? ''),
                'subdomain'      => $domain,
                'password'       => $newPassword,
            ]);
            $this->redis->expire('verify2-' . $email, 900);
            exec('/usr/bin/php /var/www/ringq/app/cli.php main main2');
            $this->apiAudit($ctx, 'extension', 'welcome-email', ['extension' => (string)($user['extension'] ?? '')]);

            return $this->apiOk([
                'message'   => 'A welcome email with the QR code has been queued.',
                'extension' => (string)($user['extension'] ?? ''),
                'email'     => $this->apiExtMaskEmail($email),
            ]);
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }
    }

/* ------------------------------------------------------------------ */
    /* 7. change_vm_path / change_recording_path (read-only)               */
    /* ------------------------------------------------------------------ */

    public function changeVmPathAction()
    {
        return $this->apiExtStoragePath('change_vm_path', 'voicemail');
    }

public function changeRecordingPathAction()
    {
        return $this->apiExtStoragePath('change_recording_path', 'recordings');
    }

public function addIvrAction()
    {
        $ctx = $this->apiBegin('add_ivr', ['name']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $data = $ctx['data'];
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        // ---- validate input (before touching the DB) ----
        $name = is_string($data['name']) ? trim($data['name']) : '';
        if (!preg_match('/^[A-Za-z0-9 _.()\-]{1,50}$/', $name)) {
            return $this->apiFail('Invalid name: 1-50 characters, letters, digits, space and _ . ( ) - only', 400);
        }

        $extension = 0;
        if (isset($data['extension']) && $data['extension'] !== '' && $data['extension'] !== null) {
            $ext = is_scalar($data['extension']) ? trim((string)$data['extension']) : '';
            if (!preg_match('/^[0-9]{4,6}$/', $ext) || (int)$ext < 6000) {
                return $this->apiFail('Invalid extension: must be a number from 6000 to 999999', 400);
            }
            $extension = (int)$ext;
        }

        $timeout = 10;
        if (isset($data['timeout'])) {
            if (!is_numeric($data['timeout']) || (int)$data['timeout'] != $data['timeout']
                || (int)$data['timeout'] < 1 || (int)$data['timeout'] > 300) {
                return $this->apiFail('Invalid timeout: whole seconds between 1 and 300', 400);
            }
            $timeout = (int)$data['timeout'];
        }

        $greeting = '';
        if (isset($data['greeting']) && $data['greeting'] !== null && $data['greeting'] !== '') {
            $greeting = is_string($data['greeting']) ? trim($data['greeting']) : '';
            if ($greeting === '' || strlen($greeting) > 150 || strpos($greeting, '/') !== false
                || strpos($greeting, '\\') !== false || strpos($greeting, '..') !== false
                || !preg_match('/^[A-Za-z0-9 _.,()+\-]+$/', $greeting)) {
                return $this->apiFail('Invalid greeting: file name only (no path), letters, digits and _ . , ( ) + - space', 400);
            }
        }

        $directDial = false;
        if (array_key_exists('direct_dial', $data)) {
            $directDial = $this->apiIvrBool($data['direct_dial']);
            if ($directDial === null) {
                return $this->apiFail('Invalid direct_dial: must be true or false', 400);
            }
        }

        // exit action: default hangup
        $exitApp = 'hangup';
        $exitData = '';
        if (isset($data['exit'])) {
            if (!is_array($data['exit'])) {
                return $this->apiFail('Invalid exit: expected {type, value}', 400);
            }
            $type = is_string($data['exit']['type'] ?? null) ? strtolower(trim($data['exit']['type'])) : '';
            if ($type === 'hangup') {
                $exitApp = 'hangup';
                $exitData = '';
            } elseif ($type === 'extension' || $type === 'voicemail') {
                $t = $this->apiIvrTarget($type, $data['exit']['value'] ?? null, $domain, 'exit');
                if (is_string($t)) {
                    return $this->apiFail($t, 400);
                }
                $exitApp = 'transfer';
                $exitData = substr($t[1], strlen('transfer '));
            } else {
                return $this->apiFail('Invalid exit.type: use extension, voicemail or hangup', 400);
            }
        }

        // options
        $options = [];
        $order = '1234567890*#';
        if (isset($data['options'])) {
            if (!is_array($data['options']) || count($data['options']) > 12) {
                return $this->apiFail('Invalid options: expected an array of up to 12 entries', 400);
            }
            $seen = [];
            foreach ($data['options'] as $i => $opt) {
                if (!is_array($opt)) {
                    return $this->apiFail('Invalid options[' . $i . ']', 400);
                }
                $digit = is_scalar($opt['digit'] ?? null) ? trim((string)$opt['digit']) : '';
                if (strlen($digit) !== 1 || strpos($order, $digit) === false) {
                    return $this->apiFail('options[' . $i . ']: digit must be one of 0-9, * or #', 400);
                }
                if (isset($seen[$digit])) {
                    return $this->apiFail('options[' . $i . ']: duplicate digit ' . $digit, 400);
                }
                $seen[$digit] = true;
                $t = $this->apiIvrTarget($opt['type'] ?? null, $opt['value'] ?? null, $domain, 'options[' . $i . ']');
                if (is_string($t)) {
                    return $this->apiFail($t, 400);
                }
                $options[$digit] = $t;
            }
        }

        $started = false;
        try {
            $this->db->begin();
            $started = true;

            if ($extension === 0) {
                $extension = $this->apiNextNumber('v_ivr_menus', 'ivr_menu_extension', $domainUuid, 5999, 6000);
            }
            $extStr = (string)$extension;

            // duplicate checks (IVRs, dialplans of any feature, real extensions)
            $dup = $this->db->fetchOne(
                'SELECT 1 AS x FROM v_ivr_menus WHERE domain_uuid = :d AND ivr_menu_extension = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $extStr]
            );
            if (!$dup) {
                $dup = $this->db->fetchOne(
                    'SELECT 1 AS x FROM v_dialplans WHERE domain_uuid = :d AND dialplan_number = :e',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'e' => $extStr]
                );
            }
            if (!$dup) {
                $dup = $this->db->fetchOne(
                    'SELECT 1 AS x FROM v_extensions WHERE domain_uuid = :d AND (extension = :e OR number_alias = :e2)',
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ['d' => $domainUuid, 'e' => $extStr, 'e2' => $extStr]
                );
            }
            if ($dup) {
                $this->db->rollback();
                return $this->apiFail('IVR extension ' . $extStr . ' already exists', 409);
            }

            $ivrMenuUuid = $this->apiUuid();
            $dialplanUuid = $this->apiUuid();
            $user = $ctx['session']['user_uuid'];

            $menu = new VIvrMenus();
            $menu->ivr_menu_uuid = $ivrMenuUuid;
            $menu->domain_uuid = $domainUuid;
            $menu->dialplan_uuid = $dialplanUuid;
            $menu->ivr_menu_extension = $extStr;
            $menu->ivr_menu_name = $name;
            $menu->ivr_menu_timeout = $timeout * 1000;
            $menu->ivr_menu_greet_long = $greeting === '' ? '' : '/var/www/ringq/public/audio/' . $greeting;
            $menu->ivr_menu_parent_uuid = null;
            $menu->ivr_menu_language = '';
            $menu->ivr_menu_dialect = 'us';
            $menu->ivr_menu_voice = 'callie';
            $menu->ivr_menu_invalid_sound = 'ivr/ivr-that_was_an_invalid_entry.wav';
            $menu->ivr_menu_exit_sound = '';
            $menu->ivr_menu_pin_number = '';
            $menu->ivr_menu_confirm_macro = '';
            $menu->ivr_menu_confirm_key = '';
            $menu->ivr_menu_tts_engine = 'flite';
            $menu->ivr_menu_tts_voice = 'rms';
            $menu->ivr_menu_confirm_attempts = 1;
            $menu->ivr_menu_exit_app = $exitApp;
            $menu->ivr_menu_exit_data = $exitData;
            $menu->ivr_menu_inter_digit_timeout = 2000;
            $menu->ivr_menu_max_failures = 3;
            $menu->ivr_menu_max_timeouts = 3;
            $menu->ivr_menu_digit_len = 5;
            $menu->ivr_menu_direct_dial = $directDial ? 'true' : 'false';
            $menu->ivr_menu_ringback = 'local_stream://default';
            $menu->ivr_menu_cid_prefix = '';
            $menu->ivr_menu_context = $domain;
            $menu->ivr_menu_enabled = 'true';
            $menu->ivr_menu_description = '';
            $menu->insert_user = $user;
            $menu->insert_date = date('Y-m-d H:i:s');
            if (!$menu->create()) {
                $this->db->rollback();
                return $this->apiFail('Could not create IVR menu', 500);
            }

            $xName = $this->apiIvrXml($name);
            $xExit = $this->apiIvrXml($exitData);
            $dialplan = new VDialplans();
            $dialplan->domain_uuid = $domainUuid;
            $dialplan->dialplan_uuid = $dialplanUuid;
            $dialplan->app_uuid = $this->apiUuid();
            $dialplan->dialplan_name = $name;
            $dialplan->dialplan_number = $extStr;
            $dialplan->dialplan_order = 81;
            $dialplan->dialplan_context = $domain;
            $dialplan->dialplan_continue = 'false';
            $dialplan->dialplan_xml = '<extension name="' . $xName . '" continue="false" uuid="' . $dialplanUuid . '">
                    <condition field="destination_number" expression="^' . $extStr . '$">
                        <action application="ring_ready" data=""/>
                        <action application="answer" data=""/>
                        <action application="sleep" data="1000"/>
                        <action application="set" data="hangup_after_bridge=true"/>
                        <action application="set" data="ringback=local_stream://default"/>
                        <action application="set" data="transfer_ringback=local_stream://default"/>
                        <action application="set" data="ivr_menu_uuid=' . $ivrMenuUuid . '"/><action application="export" data="sip_h_X-Original-Call-ID=${sip_call_id}"/>
                        <action application="ivr" data="' . $ivrMenuUuid . '"/>
                        <action application="' . $exitApp . '" data="' . $xExit . '"/>
                    </condition>
                </extension>';
            $dialplan->dialplan_enabled = 'true';
            $dialplan->insert_user = $user;
            $dialplan->insert_date = date('Y-m-d H:i:s');
            if (!$dialplan->create()) {
                $this->db->rollback();
                return $this->apiFail('Could not create IVR dialplan', 500);
            }

            // options in the UI order (1..9, 0, *, #)
            $optionOrder = 1;
            foreach (str_split($order) as $digit) {
                if (!isset($options[$digit])) {
                    continue;
                }
                $o = new VIvrMenuOptions();
                $o->domain_uuid = $domainUuid;
                $o->ivr_menu_uuid = $ivrMenuUuid;
                $o->ivr_menu_option_uuid = $this->apiUuid();
                $o->ivr_menu_option_action = $options[$digit][0];
                $o->ivr_menu_option_param = $options[$digit][1];
                $o->ivr_menu_option_order = $optionOrder;
                $o->ivr_menu_option_digits = $digit;
                $o->ivr_menu_option_enabled = 1;
                $o->insert_user = $user;
                if (!$o->create()) {
                    $this->db->rollback();
                    return $this->apiFail('Could not create IVR option ' . $digit, 500);
                }
                $optionOrder++;
            }

            $this->db->commit();
            $started = false;
        } catch (\Throwable $e) {
            if ($started) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $e2) {
                }
            }
            return $this->apiFailFrom($e, 500);
        }

        $this->apiIvrClearCaches($domain, $domainUuid, $ivrMenuUuid);
        $this->apiAudit($ctx, 'ivr', 'add', ['extension' => $extStr, 'name' => $name]);

        return $this->apiOk([
            'message'       => 'Successfully added new IVR.',
            'extension'     => $extStr,
            'ivr_menu_uuid' => $ivrMenuUuid,
        ]);
    }

public function deleteIvrAction()
    {
        $ctx = $this->apiBegin('delete_ivr', ['extension']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $domain = $ctx['domain'];
        $domainUuid = $ctx['domain_uuid'];

        $ext = is_scalar($ctx['data']['extension']) ? trim((string)$ctx['data']['extension']) : '';
        if (!preg_match('/^[0-9]{4,6}$/', $ext) || (int)$ext < 6000) {
            return $this->apiFail('Invalid extension: only IVR extensions above 5999 can be deleted', 400);
        }
        $ext = (string)(int)$ext;

        $started = false;
        $step = 'find menu';
        try {
            $menu = $this->db->fetchOne(
                'SELECT ivr_menu_uuid, dialplan_uuid, ivr_menu_name FROM v_ivr_menus WHERE domain_uuid = :d AND ivr_menu_extension = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $ext]
            );
            if (!$menu) {
                return $this->apiFail('IVR extension ' . $ext . ' not found', 404);
            }
            $ivrMenuUuid = (string)$menu['ivr_menu_uuid'];

            // A DID (q_dids.extension) or destination (v_destinations.destination_actions
            // "transfer <ext> XML <domain>") that still targets this IVR blocks the delete.
            $refs = [];
            $step = 'check DIDs';
            $did = $this->db->fetchAll(
                'SELECT did_number FROM q_dids WHERE domain_uuid = :d AND CAST(extension AS TEXT) = :e',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'e' => $ext]
            );
            foreach ($did as $r) {
                $refs[] = (string)$r['did_number'];
            }
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ext . ' XML ' . $domain) . '%';
            $step = 'check destinations';
            $dest = $this->db->fetchAll(
                'SELECT destination_number, destination_actions FROM v_destinations WHERE domain_uuid = :d AND CAST(destination_actions AS TEXT) LIKE :p',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid, 'p' => $like]
            );
            foreach ($dest as $r) {
                $actions = json_decode((string)$r['destination_actions'], true);
                if (!is_array($actions)) {
                    continue;
                }
                foreach ($actions as $a) {
                    if (is_array($a) && trim((string)($a['destination_data'] ?? '')) === $ext . ' XML ' . $domain) {
                        $refs[] = (string)$r['destination_number'];
                        break;
                    }
                }
            }
            $refs = array_values(array_unique($refs));
            if ($refs) {
                return $this->apiFail(
                    'IVR ' . $ext . ' is still used by DID/destination ' . implode(', ', array_slice($refs, 0, 10))
                    . '. Re-point or delete it first.',
                    409
                );
            }

            $this->db->begin();
            $started = true;

            // dialplans sharing the number (the IVR's own plus work/break schedule ones) and their details
            $step = 'delete dialplan details';
            $this->db->execute(
                'DELETE FROM v_dialplan_details WHERE domain_uuid = :d1 AND dialplan_uuid IN '
                . '(SELECT dialplan_uuid FROM v_dialplans WHERE domain_uuid = :d2 AND (dialplan_number = :e OR dialplan_uuid = :u))',
                ['d1' => $domainUuid, 'd2' => $domainUuid, 'e' => $ext, 'u' => (string)$menu['dialplan_uuid']]
            );
            $step = 'delete dialplans';
            $this->db->execute(
                'DELETE FROM v_dialplans WHERE domain_uuid = :d AND (dialplan_number = :e OR dialplan_uuid = :u)',
                ['d' => $domainUuid, 'e' => $ext, 'u' => (string)$menu['dialplan_uuid']]
            );
            $step = 'delete options';
            $this->db->execute(
                'DELETE FROM v_ivr_menu_options WHERE domain_uuid = :d AND ivr_menu_uuid = :m',
                ['d' => $domainUuid, 'm' => $ivrMenuUuid]
            );
            $step = 'delete menu';
            $this->db->execute(
                'DELETE FROM v_ivr_menus WHERE domain_uuid = :d AND ivr_menu_uuid = :m',
                ['d' => $domainUuid, 'm' => $ivrMenuUuid]
            );

            // drop the extension from the work/break schedule lists
            $step = 'update schedules';
            $ts = $this->db->fetchOne(
                'SELECT work_extensions, break_extensions FROM q_time_schedules WHERE domain_uuid = :d LIMIT 1',
                \Phalcon\Db\Enum::FETCH_ASSOC,
                ['d' => $domainUuid]
            );
            if ($ts) {
                $strip = static function ($list) use ($ext): ?string {
                    if ($list === null || $list === '') {
                        return $list === null ? null : '';
                    }
                    return implode(',', array_diff(explode(',', (string)$list), [$ext]));
                };
                $work = $strip($ts['work_extensions']);
                $break = $strip($ts['break_extensions']);
                if ($work !== $ts['work_extensions'] || $break !== $ts['break_extensions']) {
                    $this->db->execute(
                        'UPDATE q_time_schedules SET work_extensions = :w, break_extensions = :b WHERE domain_uuid = :d',
                        ['w' => $work, 'b' => $break, 'd' => $domainUuid]
                    );
                }
            }

            $step = 'commit';
            $this->db->commit();
            $started = false;
        } catch (\Throwable $e) {
            if ($started) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $e2) {
                }
            }
            error_log('deleteIvr failed at step [' . $step . ']: ' . $e->getMessage());
            return $this->apiFail(ControllerBase::safeErrorMessage($e) . ' (step: ' . $step . ')', 500);
        }

        $this->apiIvrClearCaches($domain, $domainUuid, $ivrMenuUuid);
        $this->apiAudit($ctx, 'ivr', 'delete', ['extension' => $ext, 'name' => (string)$menu['ivr_menu_name']]);

        return $this->apiOk([
            'message'       => 'Successfully deleted IVR.',
            'extension'     => $ext,
            'ivr_menu_uuid' => $ivrMenuUuid,
        ]);
    }

/* ------------------------------------------------------------------ */
    /* Hotel module                                                        */
    /* ------------------------------------------------------------------ */

    public function hotelModuleAction()
    {
        $ctx = $this->apiBegin('hotel_module', ['action']);
        if (!is_array($ctx)) {
            return $ctx;
        }
        $action = is_string($ctx['data']['action']) ? strtolower(trim($ctx['data']['action'])) : '';
        try {
            switch ($action) {
                case 'rooms':
                    return $this->apiHotelRooms($ctx);
                case 'checkin':
                    return $this->apiHotelCheckin($ctx);
                case 'checkout':
                    return $this->apiHotelCheckout($ctx);
                case 'wakeup_set':
                    return $this->apiHotelWakeupSet($ctx);
                case 'wakeup_clear':
                    return $this->apiHotelWakeupClear($ctx);
                case 'get_ivr_settings':
                    return $this->apiOk([
                        'selected_ivr_extension' => $this->redis->get($this->apiHotelIvrKey($ctx)) ?: '',
                    ]);
                case 'set_ivr':
                    return $this->apiHotelSetIvr($ctx);
                case 'get_frontdesk_menu':
                    return $this->apiOk([
                        'enabled' => $this->redis->get($this->apiHotelMenuKey($ctx)) === '1',
                    ]);
                case 'set_frontdesk_menu':
                    return $this->apiHotelSetFrontdesk($ctx);
            }
        } catch (\Throwable $e) {
            return $this->apiFailFrom($e, 500);
        }
        return $this->apiFail(
            'Invalid action: use rooms, checkin, checkout, wakeup_set, wakeup_clear, get_ivr_settings, set_ivr, get_frontdesk_menu or set_frontdesk_menu',
            400
        );
    }

    /**
     * POST /index/playgroundCall — the in-app API Playground (Global Settings > Integrations > RingQ APIs).
     * Admin session only. Body: { api, method?, id?, query?, body?, confirm? }.
     * The server signs the request with the tenant's own API key and calls the real endpoint, so the
     * permission toggles and validation behave exactly as for an external caller.
     */
    public function playgroundCallAction()
    {
        $this->view->disable();
        $this->response->setContentType('application/json');

        $session = $this->apiKeySession();
        if (!$session) {
            return $this->apiFail('Unauthorized', 401);
        }
        if ($this->isPlainUser($session)) {
            return $this->apiFail('Only administrators can use the API Playground.', 403);
        }
        if (!$this->request->isPost()) {
            return $this->apiFail('POST required.', 405);
        }

        $data = $this->request->getJsonRawBody(true);
        if (!is_array($data)) {
            $data = [];
        }
        $domain = (string)$session['subdomain'];

        $api = (string)($data['api'] ?? '');
        $routes = $this->apiPlaygroundRoutes();
        if (!isset($routes[$api])) {
            return $this->apiFail('Unknown API.', 400);
        }
        $route = $routes[$api];

        $method = strtoupper((string)($data['method'] ?? $route['methods'][0]));
        if (!in_array($method, $route['methods'], true)) {
            return $this->apiFail('Method not allowed for this API.', 400);
        }

        // Calls that change or remove live data need an explicit confirmation.
        if ($route['confirm'] && empty($data['confirm'])) {
            return $this->response->setStatusCode(428)->setJsonContent([
                'status'  => 'error',
                'code'    => 'confirm_required',
                'message' => 'This call changes live data. Confirm to run it.',
            ]);
        }

        $path = $route['path'];
        $id = trim((string)($data['id'] ?? ''));
        if ($id !== '') {
            if (empty($route['id']) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
                return $this->apiFail('Invalid id.', 400);
            }
            $path .= '/' . $id;
        } elseif (in_array($method, ['PATCH', 'DELETE'], true)) {
            return $this->apiFail('An id is required for ' . $method . '.', 400);
        }

        $query = trim((string)($data['query'] ?? ''));
        if ($query !== '' && !preg_match('/^\?[A-Za-z0-9_=&.%:+@-]{1,300}$/', $query)) {
            return $this->apiFail('Invalid query string.', 400);
        }

        $rawBody = '';
        if ($method !== 'GET') {
            $body = $data['body'] ?? [];
            if (is_string($body)) {
                $body = trim($body) === '' ? [] : json_decode($body, true);
            }
            if (!is_array($body)) {
                return $this->apiFail('The request body must be a JSON object.', 400);
            }
            if (strpos($route['path'], '/index/') === 0) {
                $body['domain'] = $domain; // always the admin's own domain
            }
            $rawBody = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($rawBody === false || strlen($rawBody) > 65536) {
                return $this->apiFail('The request body is invalid or too large.', 400);
            }
        }

        // 60 calls per minute per domain.
        $bucket = 'playground:' . strtolower($domain) . ':' . intdiv(time(), 60);
        if ((int)$this->redis->incr($bucket) > 60) {
            return $this->apiFail('Too many requests. Try again in a minute.', 429);
        }
        $this->redis->expire($bucket, 90);

        $apiKey = $this->apiKeyForTenant($domain);
        $secret = $apiKey === null ? '' : (string)$this->redis->hGet('call_api_keys:' . $apiKey, 'secret');
        if ($apiKey === null || $secret === '') {
            return $this->apiFail('No API key yet. Generate one under API key management first.', 409);
        }

        try {
            $result = $this->apiPlaygroundForward($domain, $apiKey, $secret, $method, $path . $query, $rawBody);
        } catch (\Throwable $e) {
            return $this->apiFail(ControllerBase::safeErrorMessage($e), 502);
        }

        $this->audit('api_playground', 'call', ['metadata' => [
            'api' => $api, 'method' => $method, 'http_status' => $result['http_status'],
        ]]);

        return $this->apiOk([
            'http_status' => $result['http_status'],
            'duration_ms' => $result['duration_ms'],
            'body'        => $result['body'],
            'request'     => ['method' => $method, 'path' => $path . $query, 'body' => $rawBody],
        ]);
    }

}
