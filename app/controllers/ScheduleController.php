<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

class ScheduleController extends ControllerBase {

	/** Imported ICS events, kept OFF the schedule list on purpose.
	 *
	 * `schedule:<ext>:<domain>` is read by ScheduleApiController as well as by
	 * this controller, so an external event stored there would surface in the
	 * public scheduling API's GET /schedules and be editable and deletable
	 * through it — as though somebody else's Google meeting were a RingQ one.
	 */
	const EXTERNAL_KEY_PREFIX = "external_events:";

	/** Caps on one import. The body is persisted into a list the calendar
	 * re-reads on every open, and it arrives from a file a stranger wrote. */
	const EXTERNAL_IMPORT_MAX_BYTES  = 524288;
	const EXTERNAL_IMPORT_MAX_EVENTS = 200;
	const EXTERNAL_MAX_STORED        = 500;
 
    public function scheduleAction($type = 1) {
		$this->view->setRenderLevel(View::LEVEL_NO_RENDER);

		$session = $this->session->get("auth");
		if (!$session) {
			return $this->response->setJsonContent([
				"result"  => "error",
				"message" => "Unathorized request. (Code AL1000)", 
			]);
		}

		require_once "/var/www/ringq/app/library/Meet.php";
		$meet = new Meet();

		if ($this->request->isGet()) {
			if ($type == 1) { // Get all internal extensions
				try {
					$users = [];
					
					$builder = $this->modelsManager->createBuilder()
						->columns([
							"VUsers.user_email",
							"QUsers.first_name",
							"QUsers.last_name",
							"VExtensions.extension"
						])
						->from("VUsers")
						->leftJoin("QUsers", "VUsers.user_uuid = QUsers.user_uuid")
						->leftJoin("VExtensionUsers", "VUsers.user_uuid = VExtensionUsers.user_uuid AND VUsers.domain_uuid = VExtensionUsers.domain_uuid")
						->leftJoin("VExtensions", "VExtensionUsers.extension_uuid = VExtensions.extension_uuid AND VExtensionUsers.domain_uuid = VExtensions.domain_uuid")
						->where("VUsers.domain_uuid = :domain_uuid:", ["domain_uuid" => $session["domain_uuid"]])
						->andWhere("VUsers.user_uuid != :user_uuid:", ["user_uuid" => $session["user_uuid"]])
						->andWhere("VExtensions.extension != :extension:", ["extension" => $session["extension"]])
						->andWhere("VExtensions.outbound_caller_id_number IS NOT NULL AND VExtensions.outbound_caller_id_number != ''");

					$results = $builder->getQuery()->execute();

					foreach ($results as $row) {
						$users[] = [
							"user_email" => $row->user_email,
							"first_name" => $row->first_name ?: "",
							"last_name" => $row->last_name ?: "",
							"extension" => $row->extension ?: ""
						];
					}

					return $this->response->setJsonContent([
						"result" => "success",
						"content" => [
							"users" => $users
						]
					]);
				} catch (\Exception $e) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Error fetching schedule data: " . ControllerBase::safeErrorMessage($e),
					]);
				}
			}
			elseif ($type == 2) { // get all created schedules
				$key = "schedule:" . $session['extension'] . ":" . $session['domain_uuid'];
				$content = $this->redis->lrange($key, 0, -1);
				$schedules = [];
				foreach ($content as $item) {
					$schedules[] = json_decode($item, true);
				}
				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $schedules
				]);
			}
			elseif ($type == 4) { // List imported external calendar events
				$key = $this->externalEventsKey($session);
				$rows = [];
				foreach ($this->redis->lrange($key, 0, -1) as $item) {
					$decoded = json_decode($item, true);
					if (is_array($decoded)) {
						$rows[] = $decoded;
					}
				}
				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $rows
				]);
			}
		}
		if ($this->request->isPost()) { 
			$key = "schedule:" . $session['extension'] . ":" . $session['domain_uuid'];
			$contents = file_get_contents("php://input");
			$data = json_decode($contents, true); 
			if ($type == 1) { // Create
				$ringqmeet = $meet->ringqmeet([
					'first_name' => $session['firstname'],
					'last_name' => $session['lastname'],
					'extension_no' => $session['extension'],
					'domain' => $session['subdomain'],
					'meet_type' => 'Schedule'
				]); 

				$ringqmeetId = $ringqmeet['room_name'] ?? '';
				$data['hostName'] = $session['firstname'] . ' ' . $session['lastname'];
				$data['hostEmail'] = $session['email'];
				$data['ringqmeetId'] = $ringqmeetId;
				$data['id'] = uniqid();

                // Public links contain only the opaque scheduled reference;
                // identity is collected in the v1 guest lobby, never in URLs.
                $httpHost = explode(':', $this->request->getHttpHost())[0];
                $meetingLink = 'https://' . $httpHost . '/meet/m/' . rawurlencode($ringqmeetId);
                $data['ringqmeetLink'] = $meetingLink;
                $data['ringqmeetParticipantLink'] = $meetingLink;

                if (isset($data['internalUsers']) && is_array($data['internalUsers'])) {
                    foreach ($data['internalUsers'] as &$user) {
                        $email = $user['user_email'] ?? '';
                        $firstName = $user['first_name'] ?? '';
                        $lastName = $user['last_name'] ?? '';
                        $extension = $user['extension'] ?? '';
                        $user['meetLink'] = $meetingLink;
                    }
                }

				$contents = json_encode($data); 
				$this->redis->lpush($key, $contents); 

				$this->redis->lpush("email_queue", json_encode([
					"type" => "schedule",
					"data" => $data
				])); 

				return $this->response->setJsonContent([ 
					"result" => "success", 
					"message" => "Successfully created schedule.",
				]); 
			} 
			elseif ($type == 2) { // Update 
				if (!isset($data['id'])) { 
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "Schedule ID is required."
					]); 
				}  
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $index => $item) {
					$schedule = json_decode($item, true);
					if ($schedule['id'] == $data['id']) { 
						$this->redis->lset($key, $index, json_encode($data)); 
						return $this->response->setJsonContent([ 
							"result" => "success", 
							"message" => "Successfully updated schedule.",
						]);
					} 
				} 
				return $this->response->setJsonContent([
					"result" => "error", 
					"message" => "Schedule not found."
				]); 
			} 
			elseif ($type == 3) { // Delete 
				if (!isset($data['id'])) {
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "Schedule ID is required."
					]); 
				} 
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $item) { 
					$schedule = json_decode($item, true); 
					if ($schedule['id'] == $data['id']) {  
						$this->redis->lrem($key, 0, $item);
						return $this->response->setJsonContent([ 
							"result" => "success",
							"message" => "Successfully deleted schedule." ,
						]);  
					} 
				} 
				return $this->response->setJsonContent([
					"result" => "error", 
					"message" => "Schedule not found."
				]); 
			}
			elseif ($type == 5) { // Import parsed ICS events
				// An imported event is a READ-ONLY COPY of somebody else's
				// meeting. Deliberately absent from this branch, and asserted
				// absent by ops/meet/tests/ics_import_contract_test.php:
				//
				//   * no Meet room is minted, so the row keeps the Google,
				//     Teams or Zoom link the original organiser published;
				//   * nothing is pushed onto email_queue, so importing an
				//     invitation cannot send that organiser's attendees a
				//     RingQ invitation they never asked for.
				//
				// Type 1 above does both of those, which is exactly why this
				// is a separate branch on a separate Redis key rather than a
				// flag on the schedule path.
				if (strlen($contents) > self::EXTERNAL_IMPORT_MAX_BYTES) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "That calendar file is too large to import."
					]);
				}

				$incoming = $data['events'] ?? null;
				if (!is_array($incoming) || count($incoming) === 0) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "No calendar events were supplied."
					]);
				}
				if (count($incoming) > self::EXTERNAL_IMPORT_MAX_EVENTS) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "That calendar file holds more than " . self::EXTERNAL_IMPORT_MAX_EVENTS . " events."
					]);
				}

				$key = $this->externalEventsKey($session);
				$stored = [];
				foreach ($this->redis->lrange($key, 0, -1) as $index => $item) {
					$decoded = json_decode($item, true);
					if (!is_array($decoded) || !isset($decoded['uid'])) {
						continue;
					}
					$stored[(string)$decoded['uid']] = [
						"index"   => $index,
						"encoded" => $item,
						"row"     => $decoded,
					];
				}

				// Writes are ordered so that no earlier one can invalidate a
				// later one's position: every lset uses an index from the
				// snapshot above, lrem shifts indexes so it runs after them,
				// and lpush moves the head so it runs last.
				$updates   = [];
				$removals  = [];
				$additions = [];
				$results   = [];

				foreach ($incoming as $candidate) {
					$row = $this->normaliseExternalEvent($candidate);
					if (is_string($row)) {
						$results[] = [
							"uid"     => is_array($candidate) ? (string)($candidate['uid'] ?? '') : '',
							"summary" => is_array($candidate) ? (string)($candidate['meetingName'] ?? '') : '',
							"status"  => "rejected",
							"reason"  => $row,
						];
						continue;
					}

					$uid  = $row['uid'];
					$have = $stored[$uid] ?? null;

					if (!empty($row['cancel'])) {
						unset($row['cancel']);
						if ($have === null) {
							// Nothing to cancel is not an error: a cancellation
							// for a meeting this calendar never held is simply
							// already true.
							$results[] = ["uid" => $uid, "summary" => $row['meetingName'], "status" => "unchanged"];
							continue;
						}
						$removals[] = $have['encoded'];
						unset($stored[$uid]);
						$results[] = ["uid" => $uid, "summary" => $row['meetingName'], "status" => "cancelled"];
						continue;
					}
					unset($row['cancel']);

					if ($have === null) {
						$row['id'] = uniqid('ext', true);
						$additions[] = $row;
						$results[] = ["uid" => $uid, "summary" => $row['meetingName'], "status" => "added"];
						continue;
					}

					$currentSequence = (int)($have['row']['sequence'] ?? 0);
					if ($row['sequence'] <= $currentSequence) {
						// Re-importing the same invitation must not leave a
						// second copy of the meeting, and must not silently do
						// nothing either.
						$results[] = ["uid" => $uid, "summary" => $row['meetingName'], "status" => "unchanged"];
						continue;
					}

					// The RingQ id is preserved so anything already pointing at
					// this row still resolves after the update.
					$row['id'] = (string)($have['row']['id'] ?? uniqid('ext', true));
					$updates[] = ["index" => $have['index'], "row" => $row];
					$results[] = ["uid" => $uid, "summary" => $row['meetingName'], "status" => "updated"];
				}

				foreach ($updates as $update) {
					$this->redis->lset($key, $update['index'], json_encode($update['row']));
				}
				foreach ($removals as $encoded) {
					$this->redis->lrem($key, 0, $encoded);
				}
				foreach ($additions as $addition) {
					$this->redis->lpush($key, json_encode($addition));
				}
				if (count($additions) > 0) {
					// Bounded so a repeated import cannot grow a list the
					// calendar re-reads on every open.
					$this->redis->ltrim($key, 0, self::EXTERNAL_MAX_STORED - 1);
				}

				return $this->response->setJsonContent([
					"result"  => "success",
					"message" => "Import complete.",
					"content" => $results
				]);
			}
			elseif ($type == 6) { // Remove one imported external event
				if (!isset($data['id'])) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Event ID is required."
					]);
				}
				$key = $this->externalEventsKey($session);
				foreach ($this->redis->lrange($key, 0, -1) as $item) {
					$decoded = json_decode($item, true);
					if (is_array($decoded) && (string)($decoded['id'] ?? '') === (string)$data['id']) {
						$this->redis->lrem($key, 0, $item);
						return $this->response->setJsonContent([
							"result"  => "success",
							"message" => "Removed from your calendar.",
						]);
					}
				}
				return $this->response->setJsonContent([
					"result" => "error",
					"message" => "Event not found."
				]);
			}
		}
	}

	/** Where this user's imported events live. Per extension per domain, so a
	 * UID from one tenant can never collide with the same UID in another. */
	private function externalEventsKey(array $session): string {
		return self::EXTERNAL_KEY_PREFIX . $session['extension'] . ":" . $session['domain_uuid'];
	}

	/** One imported event, reduced to fields this server chose to keep.
	 *
	 * Returns the row, or a string saying why it was refused so the import
	 * dialog can name the event it dropped rather than quietly importing
	 * fewer than the file held.
	 *
	 * This is a whitelist, deliberately unlike the schedule path above, which
	 * persists the request body wholesale. The body here is derived from a
	 * file nobody in this tenant wrote, so anything not named below — an
	 * inflated attachment, an extra megabyte of description, a client-supplied
	 * `type` that would file the row into the Schedule section — does not
	 * reach Redis.
	 */
	private function normaliseExternalEvent($candidate) {
		if (!is_array($candidate)) {
			return "it was not readable";
		}

		$uid = trim((string)($candidate['uid'] ?? ''));
		if ($uid === '' || strlen($uid) > 255) {
			return "it carries no usable UID";
		}

		$startDate = $candidate['startDate'] ?? null;
		if (!is_numeric($startDate)) {
			return "its date could not be read";
		}

		$startTime = (int)($candidate['startTime'] ?? 0);
		$endTime   = (int)($candidate['endTime'] ?? 0);
		if ($startTime < 0 || $startTime > 86399 || $endTime < 0 || $endTime > 86399) {
			return "its time of day is out of range";
		}

		$name = trim((string)($candidate['meetingName'] ?? ''));
		if ($name === '') {
			$name = 'Untitled event';
		}

		$attendees = [];
		if (isset($candidate['attendees']) && is_array($candidate['attendees'])) {
			foreach (array_slice($candidate['attendees'], 0, 100) as $attendee) {
				if (!is_array($attendee)) {
					continue;
				}
				$email = trim((string)($attendee['email'] ?? ''));
				if ($email === '') {
					continue;
				}
				$attendees[] = [
					"name"  => mb_substr(trim((string)($attendee['name'] ?? '')), 0, 120),
					"email" => mb_substr($email, 0, 254),
				];
			}
		}

		$organizer = null;
		if (isset($candidate['organizer']) && is_array($candidate['organizer'])) {
			$email = trim((string)($candidate['organizer']['email'] ?? ''));
			if ($email !== '') {
				$organizer = [
					"name"  => mb_substr(trim((string)($candidate['organizer']['name'] ?? '')), 0, 120),
					"email" => mb_substr($email, 0, 254),
				];
			}
		}

		$rrule = null;
		if (isset($candidate['rrule']) && is_array($candidate['rrule'])) {
			$rrule = [
				"freq"       => mb_substr((string)($candidate['rrule']['freq'] ?? ''), 0, 16),
				"interval"   => max(1, (int)($candidate['rrule']['interval'] ?? 1)),
				"count"      => isset($candidate['rrule']['count']) && is_numeric($candidate['rrule']['count'])
					? (int)$candidate['rrule']['count'] : null,
				"until"      => isset($candidate['rrule']['until'])
					? mb_substr((string)$candidate['rrule']['until'], 0, 32) : null,
				"byDay"      => [],
				"byMonthDay" => [],
			];
			if (isset($candidate['rrule']['byDay']) && is_array($candidate['rrule']['byDay'])) {
				foreach (array_slice($candidate['rrule']['byDay'], 0, 7) as $day) {
					$rrule['byDay'][] = mb_substr((string)$day, 0, 2);
				}
			}
			if (isset($candidate['rrule']['byMonthDay']) && is_array($candidate['rrule']['byMonthDay'])) {
				foreach (array_slice($candidate['rrule']['byMonthDay'], 0, 31) as $day) {
					$rrule['byMonthDay'][] = (int)$day;
				}
			}
		}

		return [
			"id"          => '',
			"uid"         => $uid,
			"sequence"    => (int)($candidate['sequence'] ?? 0),
			// Never taken from the request. `calendarEventType` reads an absent
			// or unexpected type as `Schedule`, which would put somebody else's
			// meeting in the RingQ Schedule section and its count.
			"type"        => "External",
			"source"      => mb_substr(trim((string)($candidate['source'] ?? 'External calendar')), 0, 40),
			"meetingName" => mb_substr($name, 0, 300),
			"meetingDescription" => mb_substr((string)($candidate['meetingDescription'] ?? ''), 0, 2000),
			"location"    => mb_substr((string)($candidate['location'] ?? ''), 0, 300),
			// Re-checked here and not merely on the client: the client check
			// exists so a refusal arrives before the round trip, never as the
			// authorization.
			"joinUrl"     => $this->externalSafeUrl($candidate['joinUrl'] ?? null),
			"organizer"   => $organizer,
			"attendees"   => $attendees,
			"timezone"    => mb_substr(trim((string)($candidate['timezone'] ?? 'UTC')), 0, 64),
			"timezoneResolved" => !empty($candidate['timezoneResolved']),
			"allDay"      => !empty($candidate['allDay']),
			"startDate"   => (string)(int)$startDate,
			"endDate"     => (string)(int)$startDate,
			"startTime"   => (string)$startTime,
			"endTime"     => (string)$endTime,
			"rrule"       => $rrule,
			"recurrenceUnsupported" => !empty($candidate['recurrenceUnsupported']),
			"importedAt"  => time(),
			"cancel"      => !empty($candidate['cancel']),
		];
	}

	/** A join link worth putting under a press, or null.
	 *
	 * Scheme and host are both required. A scheme test alone passes `https:`,
	 * which has no host and resolves against whatever page renders it; a host
	 * test alone passes `ftp://host/x`.
	 */
	private function externalSafeUrl($value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$url = trim($value);
		if ($url === '' || strlen($url) > 2000) {
			return null;
		}
		$parts = parse_url($url);
		if ($parts === false) {
			return null;
		}
		// Exactly one scheme test and exactly one host test, deliberately.
		// There were two host tests here — an isset() and a trim() — and a
		// mutation that deleted one survived because the other caught it. A
		// defence that cannot be proven to be load-bearing will not stay
		// load-bearing.
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}
		if (trim((string)($parts['host'] ?? '')) === '') {
			return null;
		}
		return $url;
	}
}
