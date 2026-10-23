<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

class WorkhoursController extends ControllerBase { 
    
    public function workhoursAction($type = 1) {
		$this->view->setRenderLevel(View::LEVEL_NO_RENDER);

		if ($type == 2) {
			$bk = $this->request->get("bk");
			if (!$bk || strlen($bk) < 36) {
				 return $this->response->setJsonContent(["result" => "error", "message" => "Invalid booking identifier."]);
			}
			$domain_uuid = substr($bk, 0, 36);
			$username = substr($bk, 36);

			$extension = $username; 
			try {
				$vExtensions = VExtensions::findFirst([
					"conditions" => "domain_uuid = :domain_uuid: AND number_alias = :username:",
					"bind" => ["domain_uuid" => $domain_uuid, "username" => $username]
				]);
				
				if ($vExtensions) {
					$extension = $vExtensions->extension;
				}
			} catch (\Exception $e) {
				// Fallback
			}

            $subdomain = "";
            $vDomain = VDomains::findFirst([
               "conditions" => "domain_uuid = :domain_uuid:",
               "bind" => ["domain_uuid" => $domain_uuid]
            ]);
            if ($vDomain) { 
                $subdomain = $vDomain->domain_name;
            }

			$key = "workhours:" . $extension . ":" . $subdomain;
			$content = $this->redis->get($key);
			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $content ? json_decode($content, true) : []
			]);
		}

		$session = $this->session->get("auth");
		if (!$session) {
			return $this->response->setJsonContent([
				"result"  => "error",
				"message" => "Unathorized request. (Code AL1000)", 
			]);
		}

		if ($this->request->isGet()) {
			$workhoursKey = "workhours:" . $session['extension'] . ":" . $session['subdomain'];
			if (!$this->redis->exists($workhoursKey)) {
				$defaultWorkHours = [];
				foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
					$defaultWorkHours[] = [
						'day' => $day,
						'slots' => [
							['start' => '08:00', 'end' => '17:00', 'location' => 'Remote']
						]
					];
				}
				$this->redis->set($workhoursKey, json_encode($defaultWorkHours));
			}

			if ($type == 1) { 
				$timezone = date_default_timezone_get();
				try {
					$output = [];
					exec("timedatectl | grep 'Time zone'", $output);
					if (!empty($output)) {
						// Extract value after "Time zone:"
						$parts = explode("Time zone:", $output[0]);
						if (isset($parts[1])) {
							$timezone = trim($parts[1]);
						}
					}
				} catch (\Exception $e) {
					// Fallback to PHP timezone
				}

				$key = "workhours:" . $session['extension'] . ":" . $session['subdomain'];
				$content = $this->redis->get($key);
				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $content ? json_decode($content, true) : [],
					"timezone" => $timezone
				]);
			}
		}

		if ($this->request->isPost()) {
			if ($type == 1) {
				$contents = file_get_contents("php://input");
				$key = "workhours:" . $session['extension'] . ":" . $session['subdomain'];
				$this->redis->set($key, $contents);
				return $this->response->setJsonContent([
					"result" => "success",
					"message" => "Successfully saved work hours.",
				]);
			}
		}
	}
}