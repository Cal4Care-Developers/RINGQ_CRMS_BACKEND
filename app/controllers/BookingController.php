<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

class BookingController extends ControllerBase { 

    public function bookingAction($type = 1)  {
		$this->view->setRenderLevel(View::LEVEL_NO_RENDER);

		require_once "/var/www/ringq/app/library/Meet.php";
		$meet = new Meet();

		if ($type == 4) { // Public booking view
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

			$key = "booking:" . $extension . ":" . $domain_uuid;
			$content = $this->redis->lrange($key, 0, -1);
			$bookings = [];
			foreach ($content as $item) {
				$data = json_decode($item, true);
				if (isset($data['isPrivate']) && ($data['isPrivate'] === true || $data['isPrivate'] === "true" || $data['isPrivate'] === 1 || $data['isPrivate'] === "1")) {
					continue;
				}

				// Skip expired bookings (where all dates are in the past)
				$isPast = false;
				if (!($data['useWorkHours'] ?? false) && isset($data['selectedDates']) && is_array($data['selectedDates']) && !empty($data['selectedDates'])) {
					$isPast = true;
					$today = date('Y-m-d');
					
					$validDates = [];
					foreach ($data['selectedDates'] as $isoDate) {
						$datePart = explode('T', $isoDate)[0];
						if ($datePart >= $today) {
							$isPast = false;
							$validDates[] = $isoDate;
						}
					}
					$data['selectedDates'] = $validDates;
				}
				if ($isPast) {
					continue;
				}

				// Calculate duration in minutes for availability check
				$durationStr = $data['duration'] ?? '30 min';
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
				if ($minutes == 0) $minutes = 30;

				$clientBookingsKey = "client_bookings:" . ($data['id'] ?? '');
				$appointments = $this->redis->lrange($clientBookingsKey, 0, -1);
				$bookedSlots = [];
				foreach ($appointments as $apptJson) {
					$appt = json_decode($apptJson, true);
					if (!empty($appt['date']) && !empty($appt['time']) && $appt['date'] != '0000-00-00') {
						$bookedSlots[] = [
							'date' => $appt['date'],
							'time' => $appt['time'],
							'duration' => $minutes
						];
					}
				}
				$data['bookedSlots'] = $bookedSlots;

				unset($data['internalUsers']);
				unset($data['attendees']);
				unset($data['ringqmeetId']);
				unset($data['hostEmail']);
				unset($data['useWorkHours']);
				unset($data['maxParticipants']);
				unset($data['isPrivate']);
				$bookings[] = $data;
			}
			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $bookings
			]);
		}

		if ($type == 5) { // Public submit booking
			if ($this->request->isPost()) {
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

				$contents = file_get_contents("php://input");
				$data = json_decode($contents, true);

                // Rate Limiting
                $ip = $this->request->getClientAddress();
                $rateLimitKey = "rate_limit:booking:" . $ip;
                $requestCount = $this->redis->incr($rateLimitKey);
                if ($requestCount == 1) {
                    $this->redis->expire($rateLimitKey, 60); // 1 minute window
                }
                if ($requestCount > 5) {
                    return $this->response->setJsonContent([
                        "result" => "error",
                        "message" => "Too many requests. Please try again later."
                    ]);
                }

                // Validation
                if (empty($data['userName']) || empty($data['userEmail']) || empty($data['selectedDate']) || empty($data['selectedTime'])) {
                     return $this->response->setJsonContent(["result" => "error", "message" => "Missing required fields."]);
                }
                if (!filter_var($data['userEmail'], FILTER_VALIDATE_EMAIL)) {
                     return $this->response->setJsonContent(["result" => "error", "message" => "Invalid email address."]);
                }

				$httpHost = explode(':', $this->request->getHttpHost())[0];
				$guestname = htmlspecialchars(strip_tags($data['userName'] ?? ''), ENT_QUOTES, 'UTF-8');
				$guestemail = filter_var($data['userEmail'] ?? '', FILTER_SANITIZE_EMAIL);

				// Look up the booking to get host info
				$bookings = $this->redis->lrange('booking:' . $extension . ':' . $domain_uuid, 0, -1);
				$hostFirstName = '';
				$hostLastName = '';
				foreach ($bookings as $item) {
					$booking = json_decode($item, true);
					if (isset($booking['id']) && $booking['id'] == ($data['bookingId'] ?? '')) {
						$hostParts = explode(' ', $booking['hostName'] ?? '', 2);
						$hostFirstName = $hostParts[0] ?? '';
						$hostLastName = $hostParts[1] ?? '';
						break;
					}
				}

				$ringqmeet = $meet->ringqmeet([
					'first_name' => $hostFirstName,
					'last_name' => $hostLastName,
					'extension_no' => $extension,
					'domain' => $httpHost,
					'meet_type' => 'Booking'
				]);

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

				$appointment = [
					'id' => uniqid(),
					'userType' => 'external',
					'bookingTypeId' => $data['bookingId'] ?? '',
					'guestName' => $guestname,
					'guestEmail' => $guestemail,
					'guestNotes' => htmlspecialchars(strip_tags($data['notes'] ?? ''), ENT_QUOTES, 'UTF-8'),
					'ringqmeetId' => $ringqmeet['room_name'],
					'meetLink' => 'https://' . $httpHost . '/meet/m/' . rawurlencode((string)$ringqmeet['room_name']),
					'date' => $data['selectedDate'] ?? '',
					'time' => $data['selectedTime'] ?? '',
					// `time` above is the ORGANISER's wall clock — availability
					// and double-booking maths run on it. This is only what the
					// guest was reading it as.
					'guestTimezone' => $guestTimezone,
					'created_at' => date('Y-m-d H:i:s')
				];

				$key = "client_bookings:" . $data['bookingId'];
				$this->redis->lpush($key, json_encode($appointment));

				$this->redis->lpush("email_queue", json_encode([
					"type" => "booking",
					"userType" => "external",
					"data" => $appointment,
					"bookingId" => $data['bookingId'],
					"ownerExtension" => $extension,
					"ownerDomainUuid" => $domain_uuid,
					"ownerDomain" => $httpHost
				]));

				return $this->response->setJsonContent([ 
					"result" => "success",
					"message" => "Booking submitted successfully."
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
						"message" => "Error fetching booking data: " . ControllerBase::safeErrorMessage($e),
					]);
				}
			}
			elseif ($type == 2) { // get all created bookings
				$key = "booking:" . $session['extension'] . ":" . $session['domain_uuid'];
				$content = $this->redis->lrange($key, 0, -1);
				$bookings = [];
				$now = time();

				foreach ($content as $item) {
					$data = json_decode($item, true);
					$clientBookingsKey = "client_bookings:" . $data['id'];
					$appointments = $this->redis->lrange($clientBookingsKey, 0, -1);
					$data['bookedCount'] = count($appointments);
					
					$nextMeetingTs = null;
					$nextMeetingStr = null;
					$nextMeetingEndStr = null;
					$durationStr = null;
					$nextMeetingRoomId = null;
					$nextMeetingGuestName = null;

					foreach ($appointments as $apptJson) {
						$appt = json_decode($apptJson, true);
						$date = $appt['date'] ?? '';
						$time = $appt['time'] ?? '';
						
						if (empty($date) || $date == '0000-00-00') continue;

						$ts = strtotime("$date $time");
						if ($ts !== false) {
							// Calculate End Time
							$durationStr = $data['duration'] ?? '30 min';
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
							if ($minutes == 0) $minutes = 30;

							$endTs = $ts + ($minutes * 60);

							if ($endTs > $now) {
								if ($nextMeetingTs === null || $ts < $nextMeetingTs) {
									$nextMeetingTs = $ts;
									$nextMeetingStr = date("M d, h:i A", $ts);
									$nextMeetingEndStr = date("h:i A", $endTs);
									$nextMeetingRoomId = $appt['ringqmeetId'] ?? '';
									$nextMeetingGuestName = $appt['guestName'] ?? 'Guest';
								}
							}
						}
					}
					
					$data['nextMeeting'] = $nextMeetingStr;
					$data['nextMeetingTs'] = $nextMeetingTs;
					$data['nextMeetingEnd'] = $nextMeetingEndStr;
					$data['nextMeetingDuration'] = $durationStr;
					$data['nextMeetingRoomId'] = $nextMeetingRoomId;
					$data['nextMeetingGuestName'] = $nextMeetingGuestName;

					// Check if all selected dates are in the past
					$isPast = false;
					if (!($data['useWorkHours'] ?? false) && isset($data['selectedDates']) && is_array($data['selectedDates']) && !empty($data['selectedDates'])) {
						$isPast = true;
						$today = date('Y-m-d');
						foreach ($data['selectedDates'] as $isoDate) {
							$datePart = explode('T', $isoDate)[0];
							if ($datePart >= $today) {
								$isPast = false;
								break;
							}
						}
					}
					$data['isPast'] = $isPast; 
					$bookings[] = $data;
				}
				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $bookings
				]);
			}
			elseif ($type == 6) { // Get booked users list
				$bookingId = $this->request->get("id");
				if (!$bookingId) {
					 return $this->response->setJsonContent(["result" => "error", "message" => "Booking ID is required."]);
				}
				
				$key = "client_bookings:" . $bookingId;
				$content = $this->redis->lrange($key, 0, -1);
				$bookings = [];

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
					$bookings[] = $participant;
				}
				return $this->response->setJsonContent([
					"result" => "success",
					"content" => $bookings
				]);
			}
		}
		if ($this->request->isPost()) { 
			$key = "booking:" . $session['extension'] . ":" . $session['domain_uuid'];
			$contents = file_get_contents("php://input");
			$data = json_decode($contents, true); 
			if ($type == 1) { // Create
				$data['hostName'] = $session['firstname'] . ' ' . $session['lastname'];
				$data['hostEmail'] = $session['email'];
				$data['id'] = uniqid();
				$contents = json_encode($data); 
				$key = "booking:" . $session['extension'] . ":" . $session['domain_uuid'];
				$this->redis->lpush($key, $contents); 

				if (!empty($data['internalUsers'])) {
					foreach ($data['internalUsers'] as $user) {
						$ringqmeet = $meet->ringqmeet([
							'first_name' => $session['firstname'] ?? '',
							'last_name' => $session['lastname'] ?? '',
							'extension_no' => $session['extension'] ?? '',
							'domain' => $session['subdomain'],
							'meet_type' => 'Booking'
						]); 

						$appointment = [
							'id' => uniqid(),
							'userType' => 'internal',
							'bookingTypeId' => $data['id'],
							'guestName' => $user['display'],
							'guestEmail' => $user['user_email'],
							'guestNotes' => '-',
							'ringqmeetId' => $ringqmeet['room_name'],
							'meetLink' => 'https://' . $session['subdomain'] . '/meet/m/' . rawurlencode((string)$ringqmeet['room_name']),
							'date' => '0000-00-00',
							'time' => '00:00 AM',
							'created_at' => date('Y-m-d H:i:s')
						];

						$this->redis->lpush("client_bookings:" . $data['id'], json_encode($appointment));

						$this->redis->lpush("email_queue", json_encode([
							"type" => "booking",
							"userType" => "internal",
							"data" => $appointment,
							"bookingId" => $data['id'],
							"ownerExtension" => $session['extension'],
							"ownerDomainUuid" => $session['domain_uuid'],
							"ownerDomain" => $session['subdomain']
						]));
					}
				}

				if (!empty($data['attendees'])) {
					$attendees = explode(', ', $data['attendees']);

					foreach($attendees as $attendee) {
						$ringqmeet = $meet->ringqmeet([
							'first_name' => $session['firstname'] ?? '',
							'last_name' => $session['lastname'] ?? '',
							'extension_no' => $session['extension'] ?? '',
							'domain' => $session['subdomain'],
							'meet_type' => 'Booking'
						]); 

						$appointment = [
							'id' => uniqid(),
							'userType' => 'custom',
							'bookingTypeId' => $data['id'],
							'guestName' => '-',
							'guestEmail' => $attendee,
							'guestNotes' => '-',
							'ringqmeetId' => $ringqmeet['room_name'],
							'meetLink' => 'https://' . $session['subdomain'] . '/meet/m/' . rawurlencode((string)$ringqmeet['room_name']),
							'date' => '0000-00-00',
							'time' => '00:00 AM',
							'created_at' => date('Y-m-d H:i:s')
						];

						$this->redis->lpush("client_bookings:" . $data['id'], json_encode($appointment));

						$this->redis->lpush("email_queue", json_encode([
							"type" => "booking",
							"userType" => "custom",
							"data" => $appointment,
							"bookingId" => $data['id'],
							"ownerExtension" => $session['extension'],
							"ownerDomainUuid" => $session['domain_uuid'],
							"ownerDomain" => $session['subdomain']
						]));
					}
				}

				return $this->response->setJsonContent([ 
					"result" => "success", 
					"message" => "Successfully created booking.",
				]);
			} 
			elseif ($type == 2) { // Update 
				if (!isset($data['id'])) { 
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "Booking ID is required."
					]); 
				}  
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $index => $item) {
					$booking = json_decode($item, true);
					if ($booking['id'] == $data['id']) { 
						// Synchronize Participants
						$clientBookingsKey = "client_bookings:" . $data['id'];
						$existingParticipantsRaw = $this->redis->lrange($clientBookingsKey, 0, -1);
						$existingParticipants = [];
						$keptEmails = []; // Track emails that should remain

						foreach ($existingParticipantsRaw as $pItem) {
							$p = json_decode($pItem, true);
							// Key by email for easy lookup. 
							// Note: Using email as unique identifier for this logic.
							$email = $p['guestEmail'] ?? ($p['email'] ?? ''); // Fallback keys
							if ($email) {
								$existingParticipants[$email] = $p;
							}
						}

						// 1. Sync Internal Users
						if (!empty($data['internalUsers'])) {
							foreach ($data['internalUsers'] as $user) {
								$email = $user['user_email'];
								$keptEmails[$email] = true;

								if (!isset($existingParticipants[$email])) {
									// Add new Internal User
									$appointment = [
										'id' => uniqid(),
										'userType' => 'internal',
										'bookingTypeId' => $data['id'],
										'guestName' => $user['display'],
										'guestEmail' => $email,
										'guestNotes' => '-',
										'meetLink' => $user['meetLink'] ?? '',
										'date' => '0000-00-00',
										'time' => '00:00 AM',
										'created_at' => date('Y-m-d H:i:s')
									];
									
									// If meetLink is missing in update data, try to reconstruct it (fallback)
									if (empty($appointment['meetLink'])) {
										$ringqmeetId = $data['ringqmeetId'] ?? ($booking['ringqmeetId'] ?? '');
										$appointment['meetLink'] = 'https://' . $session['subdomain'] . '/meet/m/' . rawurlencode((string)$ringqmeetId);
									}

									$this->redis->lpush($clientBookingsKey, json_encode($appointment));
									
									$this->redis->lpush("email_queue", json_encode([
										"type" => "booking",
										"userType" => "internal",
										"data" => $appointment,
										"bookingId" => $data['id'],
										"ownerExtension" => $session['extension'],
										"ownerDomainUuid" => $session['domain_uuid'],
										"ownerDomain" => $session['subdomain']
									]));
								}
							}
						}

						// 2. Sync Custom Attendees
						if (!empty($data['attendees'])) {
							$attendees = preg_split('/\s*,\s*/', $data['attendees'], -1, PREG_SPLIT_NO_EMPTY);
							foreach($attendees as $attendeeEmail) {
								$keptEmails[$attendeeEmail] = true;

								if (!isset($existingParticipants[$attendeeEmail])) {
									// Add new Custom Attendee
									$ringqmeetId = $data['ringqmeetId'] ?? ($booking['ringqmeetId'] ?? '');
									$appointment = [
										'id' => uniqid(),
										'userType' => 'custom',
										'bookingTypeId' => $data['id'],
										'guestName' => '-',
										'guestEmail' => $attendeeEmail,
										'guestNotes' => '-',
									'meetLink' => 'https://' . $session['subdomain'] . '/meet/m/' . rawurlencode((string)$ringqmeetId),
										'date' => '0000-00-00',
										'time' => '00:00 AM',
										'created_at' => date('Y-m-d H:i:s')
									];

									$this->redis->lpush($clientBookingsKey, json_encode($appointment));

									$this->redis->lpush("email_queue", json_encode([
										"type" => "booking",
										"userType" => "custom",
										"data" => $appointment,
										"bookingId" => $data['id'],
										"ownerExtension" => $session['extension'],
										"ownerDomainUuid" => $session['domain_uuid'],
										"ownerDomain" => $session['subdomain']
									]));
								}
							}
						}

						// 3. Remove Deleted Participants
						foreach ($existingParticipantsRaw as $pItem) {
							$p = json_decode($pItem, true);
							$email = $p['guestEmail'] ?? ($p['email'] ?? '');
							$uType = $p['userType'] ?? 'external';

							// Only remove Internal or Custom users who are NOT in the kept list
							if (($uType == 'internal' || $uType == 'custom') && !isset($keptEmails[$email])) {
								$this->redis->lrem($clientBookingsKey, 0, $pItem);
							}
						}

						$this->redis->lset($key, $index, json_encode($data)); 
						return $this->response->setJsonContent([ 
							"result" => "success", 
							"message" => "Successfully updated booking.", 
						]);
					} 
				} 
				return $this->response->setJsonContent([
					"result" => "error", 
					"message" => "Booking not found."
				]); 
			} 
			elseif ($type == 3) { // Delete 
				if (!isset($data['id'])) {
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "Booking ID is required."
					]); 
				} 
				$list = $this->redis->lrange($key, 0, -1); 
				foreach ($list as $item) { 
					$booking = json_decode($item, true); 
					if ($booking['id'] == $data['id']) {
						$this->redis->lrem($key, 0, $item);
						$this->redis->del("client_bookings:" . $data['id']);
						return $this->response->setJsonContent([ 
							"result" => "success",
							"message" => "Successfully deleted booking." ,
						]);
					} 
				} 
				return $this->response->setJsonContent([
					"result" => "error", 
					"message" => "Booking not found."
				]); 
			} 
			elseif ($type == 7) { // Delete Participant
				if (!isset($data['id']) || !isset($data['bookingId'])) {
					return $this->response->setJsonContent([
						"result" => "error", 
						"message" => "Participant ID and Booking ID are required."
					]); 
				} 
				$key = "client_bookings:" . $data['bookingId'];
				$list = $this->redis->lrange($key, 0, -1); 
				$deletedParticipant = null;

				foreach ($list as $item) { 
					$participant = json_decode($item, true); 
					if (($participant['id'] ?? '') == $data['id']) {
						$this->redis->lrem($key, 0, $item);
						$deletedParticipant = $participant;
						break;
					} 
				} 

				if ($deletedParticipant) {
					// Update parent booking definition if Internal or Custom
					$userType = $deletedParticipant['userType'] ?? 'external';
					if ($userType == 'internal' || $userType == 'custom') {
						$parentKey = "booking:" . $session['extension'] . ":" . $session['domain_uuid'];
						$parentList = $this->redis->lrange($parentKey, 0, -1);
						
						foreach ($parentList as $index => $parentItem) {
							$bookingDef = json_decode($parentItem, true);
							if (($bookingDef['id'] ?? '') == $data['bookingId']) {
								$updated = false;
								$emailToRemove = $deletedParticipant['guestEmail'] ?? '';

								if ($userType == 'internal' && isset($bookingDef['internalUsers'])) {
									$newInternalUsers = [];
									foreach ($bookingDef['internalUsers'] as $u) {
										if (($u['user_email'] ?? '') != $emailToRemove) {
											$newInternalUsers[] = $u;
										} else {
											$updated = true;
										}
									}
									$bookingDef['internalUsers'] = $newInternalUsers;
								}

								if ($userType == 'custom' && isset($bookingDef['attendees'])) {
									$attendeesStr = $bookingDef['attendees'];
									// Split by comma, handling potential spaces
									$attendeesArr = preg_split('/\s*,\s*/', $attendeesStr, -1, PREG_SPLIT_NO_EMPTY);
									$newAttendees = [];
									foreach ($attendeesArr as $att) {
										if ($att != $emailToRemove) {
											$newAttendees[] = $att;
										} else {
											$updated = true;
										}
									}
									$bookingDef['attendees'] = implode(', ', $newAttendees);
								}

								if ($updated) {
									$this->redis->lset($parentKey, $index, json_encode($bookingDef));
								}
								break;
							}
						}
					}

					// Queue cancellation email ONLY if it's an upcoming booking
					$date = $deletedParticipant['date'] ?? '';
					$time = $deletedParticipant['time'] ?? '';
					$isUpcoming = false;
					if (!empty($date) && $date != '0000-00-00') {
						$ts = strtotime("$date $time");
						if ($ts !== false && $ts > time()) {
							$isUpcoming = true;
						}
					}

					if ($isUpcoming) {
						$this->redis->lpush("email_queue", json_encode([
							"type" => "booking_cancellation",
							"bookingId" => $data['bookingId'],
							"data" => $deletedParticipant,
							"ownerExtension" => $session['extension'],
							"ownerDomainUuid" => $session['domain_uuid'],
							"ownerDomain" => $session['subdomain']
						]));
					}

					return $this->response->setJsonContent([ 
						"result" => "success",
						"message" => "Successfully cancelled participant booking." ,
					]);
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
			elseif ($type == 9) { // Add Participant (internal / custom)
				// The exact mirror of type 7. That symmetry is the point: this
				// dialog could already REMOVE an internal or custom participant
				// and keep the parent booking definition in step, so adding one
				// has to write both places too — a row pushed only into
				// client_bookings comes back as removed the next time the
				// booking is edited and saved, because the definition never
				// knew about it.
				//
				// Deliberately unable to create an `external` participant.
				// External rows carry a real date and time and are created by
				// somebody taking a slot on the public page; minting one here
				// would book a slot without any of the availability or
				// double-booking maths that page runs.
				$bookingId  = $data['bookingId'] ?? '';
				$userType   = $data['userType'] ?? '';
				$guestEmail = filter_var(trim((string)($data['guestEmail'] ?? '')), FILTER_SANITIZE_EMAIL);
				$guestName  = htmlspecialchars(strip_tags(trim((string)($data['guestName'] ?? ''))), ENT_QUOTES, 'UTF-8');

				if (empty($bookingId)) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Booking ID is required."
					]);
				}
				if ($userType !== 'internal' && $userType !== 'custom') {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Participant type must be internal or custom."
					]);
				}
				if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "A valid email address is required."
					]);
				}

				// Resolve the booking out of THIS session's own list. That is
				// the tenancy check as much as the lookup: a booking id
				// belonging to somebody else is simply not in this key, so it
				// answers "not found" rather than becoming an id oracle.
				$parentKey    = "booking:" . $session['extension'] . ":" . $session['domain_uuid'];
				$parentList   = $this->redis->lrange($parentKey, 0, -1);
				$bookingDef   = null;
				$bookingIndex = null;
				foreach ($parentList as $index => $parentItem) {
					$candidate = json_decode($parentItem, true);
					if (($candidate['id'] ?? '') == $bookingId) {
						$bookingDef   = $candidate;
						$bookingIndex = $index;
						break;
					}
				}

				if ($bookingDef === null) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Booking not found."
					]);
				}

				// One invitation per person. Adding the same address twice mints
				// a second meeting room and sends a second invitation to
				// somebody already invited, and the two rows are then
				// indistinguishable in the list.
				$participantsKey = "client_bookings:" . $bookingId;
				foreach ($this->redis->lrange($participantsKey, 0, -1) as $item) {
					$existing = json_decode($item, true);
					if (strcasecmp((string)($existing['guestEmail'] ?? ''), $guestEmail) === 0) {
						return $this->response->setJsonContent([
							"result" => "error",
							"message" => "That email address is already a participant."
						]);
					}
				}

				if ($userType === 'custom' || $guestName === '') {
					// What type 1 stores for a custom attendee. The list renders
					// '-' as "Guest" rather than as a name.
					$guestName = '-';
				}

				$ringqmeet = $meet->ringqmeet([
					'first_name'   => $session['firstname'] ?? '',
					'last_name'    => $session['lastname'] ?? '',
					'extension_no' => $session['extension'] ?? '',
					'domain'       => $session['subdomain'],
					'meet_type'    => 'Booking'
				]);

				$appointment = [
					'id'            => uniqid(),
					'userType'      => $userType,
					'bookingTypeId' => $bookingId,
					'guestName'     => $guestName,
					'guestEmail'    => $guestEmail,
					'guestNotes'    => '-',
					'ringqmeetId'   => $ringqmeet['room_name'],
					'meetLink'      => 'https://' . $session['subdomain'] . '/meet/m/' . rawurlencode((string)$ringqmeet['room_name']),
					// A standing invitee is not tied to one slot, so it carries
					// the same empty date type 1 writes. The list reads
					// '0000-00-00' as "no date", never as a parse failure.
					'date'          => '0000-00-00',
					'time'          => '00:00 AM',
					'created_at'    => date('Y-m-d H:i:s')
				];

				$this->redis->lpush($participantsKey, json_encode($appointment));

				// Keep the parent definition in step, exactly as type 7 does on
				// the way out.
				if ($userType === 'internal') {
					$internalUsers = $bookingDef['internalUsers'] ?? [];
					if (!is_array($internalUsers)) {
						$internalUsers = [];
					}
					$internalUsers[] = [
						'display'    => $guestName,
						'user_email' => $guestEmail,
					];
					$bookingDef['internalUsers'] = $internalUsers;
				} else {
					$attendeesStr = (string)($bookingDef['attendees'] ?? '');
					$attendeesArr = preg_split('/\s*,\s*/', $attendeesStr, -1, PREG_SPLIT_NO_EMPTY);
					$attendeesArr[] = $guestEmail;
					$bookingDef['attendees'] = implode(', ', $attendeesArr);
				}
				$this->redis->lset($parentKey, $bookingIndex, json_encode($bookingDef));

				$this->redis->lpush("email_queue", json_encode([
					"type"            => "booking",
					"userType"        => $userType,
					"data"            => $appointment,
					"bookingId"       => $bookingId,
					"ownerExtension"  => $session['extension'],
					"ownerDomainUuid" => $session['domain_uuid'],
					"ownerDomain"     => $session['subdomain']
				]));

				// The created row travels back so the dialog can show it without
				// re-reading the whole list. The id and the meeting room are
				// minted here, so the client could not construct this itself.
				return $this->response->setJsonContent([
					"result"  => "success",
					"message" => "Participant added and invited.",
					"content" => $appointment
				]);
			}
		}
	}
}
