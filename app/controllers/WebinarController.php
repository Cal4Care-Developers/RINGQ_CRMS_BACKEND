<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

require_once __DIR__ . '/../library/WebinarInvitees.php';

class WebinarController extends ControllerBase { 

    public function webinarAction($type = 1) {
		$this->view->setRenderLevel(View::LEVEL_NO_RENDER);

		require_once "/var/www/ringq/app/library/Meet.php";
		$meet = new Meet();

		if ($type == 4) { // Public webinar view
			$id = $this->request->get("id");
			if (!$id) {
				 return $this->response->setJsonContent(["result" => "error", "message" => "Webinar ID is required."]);
			}
			$key = "webinar_details:" . $id;
			$content = $this->redis->get($key);
			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $content ? json_decode($content, true) : null
			]);
		}

		if ($type == 5) { // Public submit registration
			if ($this->request->isPost()) {
				$contents = file_get_contents("php://input");
				$data = json_decode($contents, true);
				$id = $data['id'] ?? '';

                if (empty($data['firstName']) || empty($data['lastName']) || empty($data['email'])) {
                     return $this->response->setJsonContent(["result" => "error", "message" => "Missing required fields."]);
                }

				$webinarKey = "webinar_details:" . $id;
				$webinarContent = $this->redis->get($webinarKey);
				$ringqmeetId = '';
				if ($webinarContent) {
					$webinarData = json_decode($webinarContent, true);
					$ringqmeetId = $webinarData['ringqmeetId'] ?? '';
				}

				// The timezone the guest was reading the offered times in.
				//
				// Validated against PHP's own zone database rather than stored
				// as sent: this value ends up formatting the times in their
				// confirmation email, and a name the database does not know
				// would throw there instead of here. An unusable value is
				// stored empty, which the email job reads as "fall back to the
				// organiser's timezone" — the behaviour before this existed.
				$guestTimezone = '';
				$rawGuestTimezone = trim((string)($data['guestTimezone'] ?? ''));
				if ($rawGuestTimezone !== '') {
					try {
						new \DateTimeZone($rawGuestTimezone);
						$guestTimezone = $rawGuestTimezone;
					} catch (\Exception $e) {
						$guestTimezone = '';
					}
				}

				$registration = [
					'id' => uniqid(),
					'webinarId' => $id,
					'firstName' => htmlspecialchars(strip_tags($data['firstName'] ?? ''), ENT_QUOTES, 'UTF-8'),
					'lastName' => htmlspecialchars(strip_tags($data['lastName'] ?? ''), ENT_QUOTES, 'UTF-8'),
					'email' => filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL),
					'notes' => htmlspecialchars(strip_tags($data['notes'] ?? ''), ENT_QUOTES, 'UTF-8'),
					'meetLink' => 'https://' . explode(':', $this->request->getHttpHost())[0] . '/meet/m/' . rawurlencode((string)$ringqmeetId),
					'guestTimezone' => $guestTimezone,
					'created_at' => date('Y-m-d H:i:s')
				];

				$key = "webinar_participants:" . $id;
				$this->redis->lpush($key, json_encode($registration));

				$this->redis->lpush("email_queue", json_encode([
					"type" => "webinar",
					"data" => $registration,
					"webinarId" => $id
				]));

				return $this->response->setJsonContent([
					"result" => "success",
					"message" => "Registration successful."
				]);
			}
		}

		$session = $this->session->get("auth");
		if (!$session) {
			return $this->response->setJsonContent([
				"result"  => "error",
				"message" => "Unathorized request. (Code AL1000)", 
			]);
		}

		if ($this->request->isGet()) {
			if ($type == 2) { // get all created webinars
				$key = "webinars:" . $session['extension'] . ":" . $session['domain_uuid'];
				$content = $this->redis->lrange($key, 0, -1);
				$webinars = [];
				$now = time();

				foreach ($content as $item) {
					$data = json_decode($item, true);
					$participantsKey = "webinar_participants:" . ($data['id'] ?? '');
					$data['bookedCount'] = $this->redis->llen($participantsKey);

					$date = $data['date'] ?? '';
					$time = $data['time'] ?? '';
					
					$nextMeetingTs = null;
					$nextMeetingStr = null;
					$nextMeetingEndStr = null;
					$durationStr = $data['duration'] ?? '1 hr';

					if (!empty($date) && !empty($time)) {
						// Clean time string if needed
						$timeParts = explode(':', $time);
						if (count($timeParts) == 2) {
							$datePart = explode('T', $date)[0];
							$ts = strtotime("$datePart $time");

							if ($ts !== false) {
								$nextMeetingTs = $ts;
								$nextMeetingStr = date("M d, h:i A", $ts);

								// Calculate End Time
								$minutes = 0;
								if (preg_match('/(\d+)\s*hr/', $durationStr, $matches)) {
									$minutes += intval($matches[1]) * 60;
								}
								if (preg_match('/(\d+)\s*min/', $durationStr, $matches)) {
									$minutes += intval($matches[1]);
								}
								if ($minutes == 0 && is_numeric($durationStr)) {
									$minutes = intval($durationStr);
								}
								if ($minutes == 0) $minutes = 60;

								$endTs = $ts + ($minutes * 60);
								$nextMeetingEndStr = date("h:i A", $endTs);

								if ($endTs <= $now) {
                                    $nextMeetingTs = null;
                                    $nextMeetingStr = null;
                                    $nextMeetingEndStr = null;
                                }
							}
						}
					}

					$data['nextMeeting'] = $nextMeetingStr;
					$data['nextMeetingTs'] = $nextMeetingTs;
					$data['nextMeetingEnd'] = $nextMeetingEndStr;
					$data['nextMeetingDuration'] = $durationStr;

					// Calculate sort timestamp
					$sortTs = 0;
					if (!empty($date) && !empty($time)) {
						$datePart = explode('T', $date)[0];
						$sortTs = strtotime("$datePart $time") ?: 0;
					}
					$data['sortTs'] = $sortTs;

					$webinars[] = $data;
				}

				// Sort webinars by date and time ascending (soonest/past first)
				usort($webinars, function($a, $b) {
					return ($a['sortTs'] ?? 0) <=> ($b['sortTs'] ?? 0);
				});

				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $webinars
				]);
			}
			elseif ($type == 6) { // Get participants list
				$id = $this->request->get("id");
				if (!$id) {
					 return $this->response->setJsonContent(["result" => "error", "message" => "Webinar ID is required."]);
				}
				$key = "webinar_participants:" . $id;
				$content = $this->redis->lrange($key, 0, -1);
				$participants = [];

				// Pre-fetch all rooms to efficiently check status
				$roomsRaw = $this->redis->lrange("rooms", 0, -1);
				$endedRooms = [];
				$missedRooms = [];
				foreach ($roomsRaw as $rItem) {
					$roomData = json_decode($rItem, true);
					if (isset($roomData['room_name']) && isset($roomData['status'])) {
						if ($roomData['status'] === 'ended') {
							$endedRooms[$roomData['room_name']] = true;
						} elseif ($roomData['status'] === 'missed') {
							$missedRooms[$roomData['room_name']] = true;
						}
					}
				}

				foreach ($content as $item) {
					$participant = json_decode($item, true);
					$participantRoomId = $participant['ringqmeetId'] ?? null;
					$participant['isRoomEnded'] = $participantRoomId && isset($endedRooms[$participantRoomId]);
					$participant['isRoomMissed'] = $participantRoomId && isset($missedRooms[$participantRoomId]);
					$participants[] = $participant;
				}
				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $participants
				]);
			}
		}
		if ($this->request->isPost()) { 
			$key = "webinars:" . $session['extension'] . ":" . $session['domain_uuid'];
			$contents = file_get_contents("php://input");
			$data = json_decode($contents, true); 
			if ($type == 1) { // Create
				$invitees = WebinarInvitees::normalise($data['participants'] ?? null, $session['email'] ?? '');
				if ($invitees['error'] !== null) {
					return $this->response->setJsonContent(["result" => "error", "message" => $invitees['error']]);
				}
				unset($data['participants']);

				$ringqmeet = $meet->ringqmeet([
					'first_name' => $session['firstname'],
					'last_name' => $session['lastname'],
					'extension_no' => $session['extension'],
					'domain' => $session['subdomain'],
					'meet_type' => 'Webinar'
				]); 

				$ringqmeetId = $ringqmeet['room_name'] ?? '';
				$data['hostName'] = $session['firstname'] . ' ' . $session['lastname'];
				$data['hostEmail'] = $session['email'];
				$data['ringqmeetId'] = $ringqmeetId;
				$data['id'] = uniqid();

				$contents = json_encode($data);
				$this->redis->lpush($key, $contents);
				$this->redis->set("webinar_details:" . $data['id'], $contents);
				$this->redis->sadd("webinar_ids", $data['id']);

				// The host's own invitation, once, at creation. Queued only here
				// -- a registration notice carries no .ics, so this is the single
				// point at which the organiser's calendar entry is created. The
				// tenant hostname travels with the job because at creation there
				// is no stored participant link to take it out of.
				$this->redis->lpush("email_queue", json_encode([
					"type" => "webinar_created",
					"webinarId" => $data['id'],
					"host" => explode(':', $this->request->getHttpHost())[0],
					"data" => []
				]));

				$added = WebinarInvitees::register($this->redis, $data['id'], $data, $session['domain_uuid'], $session['subdomain'], $invitees['rows']);

				return $this->response->setJsonContent([ 
					"result" => "success", 
					"message" => "Successfully created webinar.",        
					"added" => count($added),
				]); 
			} 
			elseif ($type == 2) { // Update 
				if (!isset($data['id'])) { 
					return $this->response->setJsonContent(["result" => "error", "message" => "Webinar ID is required."]); 
				}  
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $index => $item) {
					$webinar = json_decode($item, true);
					if ($webinar['id'] == $data['id']) { 
						$invitees = WebinarInvitees::normalise(
							$data['participants'] ?? null,
							$session['email'] ?? '',
							WebinarInvitees::existingEmails($this->redis, $data['id'])
						);
						if ($invitees['error'] !== null) {
							return $this->response->setJsonContent(["result" => "error", "message" => $invitees['error']]);
						}
						unset($data['participants']);

						// Carried forward from the stored record, never from the
						// request body. See webinarOwnerFields for why an update
						// that simply wrote $data back erased the webinar's owner.
						$owner = $this->webinarOwnerFields($webinar, $session, $data['id']);
						if ($owner['ringqmeetId'] === null) {
							$mintedRoom = $meet->ringqmeet([
								'first_name' => $session['firstname'],
								'last_name' => $session['lastname'],
								'extension_no' => $session['extension'],
								'domain' => $session['subdomain'],
								'meet_type' => 'Webinar'
							]);
							$owner['ringqmeetId'] = $mintedRoom['room_name'] ?? '';
						}
						$data['hostName'] = $owner['hostName'];
						$data['hostEmail'] = $owner['hostEmail'];
						$data['ringqmeetId'] = $owner['ringqmeetId'];

						$this->redis->lset($key, $index, json_encode($data)); 
						$this->redis->set("webinar_details:" . $data['id'], json_encode($data));
						$this->redis->sadd("webinar_ids", $data['id']);
						WebinarInvitees::refreshInvites($this->redis, $data['id'], $data);
						$added = WebinarInvitees::register($this->redis, $data['id'], $data, $session['domain_uuid'], $session['subdomain'], $invitees['rows']);
						return $this->response->setJsonContent([ 
							"result" => "success", 
							"message" => "Successfully updated webinar.", 
							"added" => count($added),
							"skipped" => $invitees['skipped'],
						]);
					} 
				} 
				return $this->response->setJsonContent(["result" => "error", "message" => "Webinar not found."]); 
			} 
			elseif ($type == 3) { // Delete 
				if (!isset($data['id'])) {
					return $this->response->setJsonContent(["result" => "error", "message" => "Webinar ID is required."]); 
				} 
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $item) { 
					$webinar = json_decode($item, true); 
					if ($webinar['id'] == $data['id']) {  
						$this->redis->lrem($key, 0, $item);
						$this->redis->del("webinar_details:" . $data['id']);
						WebinarInvitees::revokeWebinar($this->redis, $data['id']);
						$this->redis->del("webinar_participants:" . $data['id']);
						$this->redis->srem("webinar_ids", $data['id']);
						return $this->response->setJsonContent([ 
							"result" => "success",
							"message" => "Successfully deleted webinar." ,
						]);  
					} 
				} 
				return $this->response->setJsonContent(["result" => "error", "message" => "Webinar not found."]); 
			} 
			elseif ($type == 7) { // Delete Participant
				if (!isset($data['id']) || !isset($data['webinarId'])) {
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "Participant ID and Webinar ID are required."
					]); 
				} 
				$key = "webinar_participants:" . $data['webinarId'];
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $item) { 
					$participant = json_decode($item, true); 
					if (($participant['id'] ?? '') == $data['id']) {
						$this->redis->lrem($key, 0, $item);
						WebinarInvitees::revokeParticipant($this->redis, $data['webinarId'], $data['id']);
						return $this->response->setJsonContent([ 
							"result" => "success",
							"message" => "Successfully removed participant." ,
						]);
					} 
				} 
				return $this->response->setJsonContent([
					"result" => "error", 
					"message" => "Participant not found."
				]); 
			} 
			elseif ($type == 8) { // Mark Room Missed
				if (!isset($data['ringqmeetId'])) {
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "ringqmeetId is required."
					]); 
				} 

				$targetRoomId = $data['ringqmeetId'];

				if ($targetRoomId) {
					$roomsRaw = $this->redis->lrange('rooms', 0, -1);
					foreach ($roomsRaw as $roomKey => $roomItem) {
						$roomData = json_decode($roomItem, true);
						if (($roomData['room_name'] ?? '') == $targetRoomId) {
							$this->redis->lrem('rooms', 0, $roomItem);
							$roomData['time_ended'] = date('g:i A');
							$roomData['status'] = 'missed';
							$this->redis->rpush('rooms', json_encode($roomData)); 

							return $this->response->setJsonContent([ 
								"result" => "success",
								"message" => "Room successfully marked as missed."
							]);
						}
					}
					
					return $this->response->setJsonContent([ 
						"result" => "success",
						"message" => "No active room found to end."
					]);
				}

				return $this->response->setJsonContent([
					"result" => "error", 
					"message" => "Missing room ID."
				]); 
			}
			elseif ($type == 9) { // Add Participant (host-registered)
				// What type 5 does for somebody registering themselves, done by
				// the host on their behalf. The stored row is the same shape, so
				// it lists, cancels and emails through the paths that already
				// exist — a host-added registrant is not a second kind of row.
				$webinarId = $data['webinarId'] ?? '';
				$firstName = htmlspecialchars(strip_tags(trim((string)($data['firstName'] ?? ''))), ENT_QUOTES, 'UTF-8');
				$lastName  = htmlspecialchars(strip_tags(trim((string)($data['lastName'] ?? ''))), ENT_QUOTES, 'UTF-8');
				$email     = filter_var(trim((string)($data['email'] ?? '')), FILTER_SANITIZE_EMAIL);
				$notes     = htmlspecialchars(strip_tags(trim((string)($data['notes'] ?? ''))), ENT_QUOTES, 'UTF-8');
				$role      = trim((string)($data['role'] ?? WebinarInvitees::ROLE_PARTICIPANT));

				if (empty($webinarId)) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Webinar ID is required."
					]);
				}
				if ($firstName === '' || $lastName === '') {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "First and last name are required."
					]);
				}
				if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "A valid email address is required."
					]);
				}
				if ($role !== WebinarInvitees::ROLE_PARTICIPANT && $role !== WebinarInvitees::ROLE_COHOST) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Role must be participant or cohost."
					]);
				}

				// Resolve the webinar out of THIS session's own list, which is
				// the tenancy check as much as the lookup: an id belonging to
				// somebody else is not in this key, so it answers "not found"
				// rather than becoming an id oracle.
				$ownWebinars = $this->redis->lrange($key, 0, -1);
				$owned = false;
				foreach ($ownWebinars as $item) {
					$candidate = json_decode($item, true);
					if (($candidate['id'] ?? '') == $webinarId) {
						$owned = true;
						break;
					}
				}

				if (!$owned) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Webinar not found."
					]);
				}

				// One registration per address. A webinar has a single room, so
				// a duplicate is purely a second invitation to somebody already
				// registered plus a row nobody can tell from the first.
				$participantsKey = "webinar_participants:" . $webinarId;
				foreach ($this->redis->lrange($participantsKey, 0, -1) as $item) {
					$existing = json_decode($item, true);
					if (strcasecmp((string)($existing['email'] ?? ''), $email) === 0) {
						return $this->response->setJsonContent([
							"result" => "error",
							"message" => "That email address is already registered."
						]);
					}
				}

				$ringqmeetId = '';
				$webinarData = [];
				$webinarContent = $this->redis->get("webinar_details:" . $webinarId);
				if ($webinarContent) {
					$webinarData = json_decode($webinarContent, true) ?: [];
					$ringqmeetId = $webinarData['ringqmeetId'] ?? '';
				}

				$registration = [
					'id'        => uniqid(),
					'webinarId' => $webinarId,
					'firstName' => $firstName,
					'lastName'  => $lastName,
					'email'     => $email,
					'notes'     => $notes,
					'meetLink'  => 'https://' . $session['subdomain'] . '/meet/m/' . rawurlencode((string)$ringqmeetId),
					// Left empty on purpose. This field records the zone the
					// registrant was reading the offered time in on the public
					// page; the host adding them read it in the ORGANISER's
					// zone, and stamping that here would tell the email job the
					// registrant had chosen it. Empty means "fall back to the
					// organiser's zone", which is exactly what happened.
					'guestTimezone' => '',
					'role'          => $role,
					'source'        => 'host',
					'created_at'    => date('Y-m-d H:i:s')
				];

				$this->redis->lpush($participantsKey, json_encode($registration));
				if ($role === WebinarInvitees::ROLE_COHOST) {
					WebinarInvitees::issueInvite($this->redis, $webinarId, $webinarData, $session['domain_uuid'], $registration);
				}

				$this->redis->lpush("email_queue", json_encode([
					"type"      => "webinar",
					"data"      => $registration,
					"webinarId" => $webinarId
				]));

				// The created row travels back so the dialog can show it without
				// re-reading the whole list.
				return $this->response->setJsonContent([
					"result"  => "success",
					"message" => "Participant registered and invited.",
					"content" => $registration
				]);
			}
		}
	}

	/**
	 * The three server-derived fields a webinar record carries, resolved for an
	 * update.
	 *
	 * The edit form posts only what an organiser can change: `hostName`,
	 * `hostEmail` and `ringqmeetId` are stamped at create (type 1) and are not
	 * in the payload. Writing the request body straight back therefore erased
	 * all three from both `webinars:<ext>:<domain_uuid>` and
	 * `webinar_details:<id>` -- measured live on three of five webinars -- which
	 * left the host notice with no recipient and the participant link with no
	 * room, silently, on every edit.
	 *
	 * Resolution order, and none of it reads the request body: the browser must
	 * not be able to reassign a webinar's owner.
	 *
	 *  1. The stored record, which is the truth for every webinar not yet wiped.
	 *  2. The session, which repairs a record a previous update already wiped.
	 *     Safe because the list key is built from this session's own extension,
	 *     so a webinar belonging to somebody else is never found at all.
	 *
	 * `ringqmeetId` has no session to fall back on, so it is recovered from a
	 * registration's stored `meetLink` -- the one other place the reference is
	 * written. Only when there is nothing to recover does the caller mint a new
	 * room: minting ahead of that recovery would orphan everyone who has already
	 * registered, whose link still points at the old reference.
	 *
	 * Returns `ringqmeetId => null` to mean "mint one". The mint call is the one
	 * line that differs between this controller and its twin, so it stays at the
	 * call site and this body stays identical in both.
	 */
	private function webinarOwnerFields($stored, $session, $webinarId)
	{
		$hostName = '';
		if (is_array($stored) && isset($stored['hostName'])) {
			$hostName = trim((string)$stored['hostName']);
		}
		if ($hostName === '') {
			$hostName = trim((string)($session['firstname'] ?? '') . ' ' . (string)($session['lastname'] ?? ''));
		}

		$hostEmail = '';
		if (is_array($stored) && isset($stored['hostEmail'])) {
			$hostEmail = trim((string)$stored['hostEmail']);
		}
		if ($hostEmail === '') {
			$hostEmail = trim((string)($session['email'] ?? ''));
		}

		$ringqmeetId = '';
		if (is_array($stored) && isset($stored['ringqmeetId'])) {
			$ringqmeetId = trim((string)$stored['ringqmeetId']);
		}
		if ($ringqmeetId === '') {
			$ringqmeetId = $this->webinarReferenceFromRegistrations($webinarId);
		}

		return [
			'hostName'    => $hostName,
			'hostEmail'   => $hostEmail,
			'ringqmeetId' => $ringqmeetId === '' ? null : $ringqmeetId,
		];
	}

	/**
	 * The meeting reference a registration already recorded, or '' when none has.
	 *
	 * Every registration stores `meetLink` as `/meet/m/<ringqmeetId>`, so a
	 * webinar whose own record was wiped can still say which room its
	 * registrants were sent to. Reading it back is what makes the repair
	 * non-destructive for a webinar that already has people in it.
	 */
	private function webinarReferenceFromRegistrations($webinarId)
	{
		$rows = $this->redis->lrange("webinar_participants:" . $webinarId, 0, -1);
		if (!is_array($rows)) return '';
		foreach ($rows as $row) {
			$registration = json_decode($row, true);
			if (!is_array($registration)) continue;
			$link = trim((string)($registration['meetLink'] ?? ''));
			if ($link === '') continue;
			$path = (string)parse_url($link, PHP_URL_PATH);
			if (preg_match('~/meet/m/([^/]+)$~', $path, $matches)) {
				$reference = rawurldecode($matches[1]);
				if ($reference !== '') return $reference;
			}
		}
		return '';
	}

}
