<?php
declare(strict_types=1);

require_once __DIR__ . '/../library/Email.php';
use Library\Email as EmailService;
use Orhanerday\OpenAi\OpenAi;
use Phalcon\Http\Response;
use Phalcon\Validation;
use Phalcon\Validation\Validator\Email;
use Phalcon\Validation\Validator\PresenceOf;

class MeetController extends ControllerBase
{
    public function isRoomExistAction($room) {
        $res = new Response();
        if ($this->request->isPost()) {
            $post = [
                'display_name' => trim($this->request->getPost('display_name', 'string')),
                'email' => trim($this->request->getPost('email', 'string') ?: ''),
                'room' => trim($this->request->getPost('room', 'string')),
            ];

            $validation = new Validation();
            $validation->add('display_name', new PresenceOf(['message' => 'Display name is required',]));
            $validation->add('room', new PresenceOf(['message' => 'The room is required',]));
            $validation->add('email', new Email(['message' => 'The e-mail is not valid',]));

            $errMessages = $validation->validate($post);
            if (count($errMessages)) {
                foreach ($errMessages as $message) {
                    $errData[] = $message;
                }
                return $res->setJsonContent([
                    'result' => 'invalid data',
                    'errors' => $errData
                ]);
            } 

            $roomName = urldecode(trim($room));

            $connectedParticipantJson = $this->redis->lrange('connected_participants', 0, -1);
            $roomsJson = $this->redis->lrange('rooms', 0, -1);

            $rooms = array_map('json_decode', $roomsJson);
            $connectedParticipants = array_map('json_decode', $connectedParticipantJson);

            foreach ($rooms as $key => $room) {
                if ($room->room_name === $roomName || strtolower($room->owner) === strtolower($roomName)) {
                    $status = $room->status ?? null;
                    if ($status === 'ended' || $status === 'missed') {
                        return $res->setJsonContent([
                            'result' => 'meet ended',
                            'message' => 'Meeting ended'
                        ]);
                    } 

                    $onGoingMeet = false;
                    $onGoingMeetId = ''; 
                    foreach ($connectedParticipants as $participant) {
                        if ($participant->room_id == $room->owner) {
                            $onGoingMeetId = $participant->room_unique_id;
                            $onGoingMeet = true;
                            break;
                        }
                    }

                    return $res->setJsonContent([
                        'result' => 'success',
                        'message' => 'Room exist',
                        'owner' => $room->owner,
                        'ongoing_meeting' => $onGoingMeet,
                        'room_unique_id' => !$onGoingMeet ? $room->room_name : $onGoingMeetId,
                    ]); 
                }
            }
            return $res->setJsonContent([
                'result' => 'not exist',
                'message' => 'Room does not exist'
            ]);
        }
        return $res->setJsonContent([
            'result' => 'error',
            'message' => 'Invalid request'
        ]); 
    }

    public function generateRoomAction() {
        $parameters = array();
        $parameters['firstName'] = $this->request->getPost('first_name', 'string', 20);
        $parameters['lastName'] = $this->request->getPost('last_name', 'string', 20);
        $parameters['extensionNo'] = $this->request->getPost('extension_no', 'int', 5);
        $parameters['meetType'] = $this->request->getPost('meet_type', 'string');

        if (empty($parameters["firstName"])) {
            $contents = file_get_contents("php://input");
            $parameters = json_decode($contents, true);
        }

        $security = new \Phalcon\Security\Random();
        $roomId = $security->uuid();
        $owner = "{$parameters['firstName']}{$parameters['lastName']}{$parameters['extensionNo']}";

        if ($parameters['meetType'] === 'Instant') {
            $roomsJson = $this->redis->lrange('rooms', 0, -1);
            foreach ($roomsJson as $roomJson) {
                $room = json_decode($roomJson);
                if ($room->owner === $owner && isset($room->meet_type) && $room->meet_type === 'Instant' && (!isset($room->status) || $room->status !== 'ended')) {
                    return json_encode([
                        'result' => true,
                        'owner' => $room->owner,
                        'room_name' => $room->room_name
                    ]);
                }
            }
        }

        $isCreated = $this->redis->rpush('rooms', json_encode([
            'owner' => $owner,
            'room_name' => $roomId,
            'meet_type' => $parameters['meetType']
        ]));

        if ($isCreated) {
            return json_encode([
                'result' => true,
                'owner' => $owner,
                'room_name' => $roomId
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Failed to create room'
        ]);
    }

    public function updateTracksAction() {
        if ($this->request->isPost()) {
            $socketId = $this->request->getPost('socket_id', 'string', 20);
            $sessionId = $this->request->getPost('session_id', 'string', 32);
            $roomId = $this->request->getPost('room_id', 'string', 36);
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string');
            $displayName = $this->request->getPost('display_name', 'string', 20);
            $email = $this->request->getPost('email_address', 'string', 50);
            $participantRole = $this->request->getPost('participant_role', 'string', 20);
            $audioStatus = $this->request->getPost('audio_status', 'bool');
            $videoStatus = $this->request->getPost('video_status', 'bool');
            $blurVideo = $this->request->getPost('blur_video', 'bool');
            $backgroundVideo = $this->request->getPost('background_video', 'bool');
            $backgroundImage = $this->request->getPost('background_image');
            $mirrorVideo = $this->request->getPost('mirror_video', 'bool');
            $raiseHandStatus = $this->request->getPost('raisehand_status', 'bool');
            $joinStatus = $this->request->getPost('join_status', 'string', 20);
            $userMedia = $this->request->getPost('user_media');
            $displayMedia = $this->request->getPost('display_media');

            $data = [
                'socket_id' => $socketId,
                'session_id' => $sessionId,
                'room_id' => $roomId,
                'room_unique_id' => $roomUniqueId,
                'display_name' => $displayName,
                'email' => $email,
                'participant_role' => $participantRole,
                'audio_status' => $audioStatus,
                'video_status' => $videoStatus,
                'blur_video' => $blurVideo,
                'background_video' => $backgroundVideo,
                'background_image' => $backgroundImage,
                'mirror_video' => $mirrorVideo,
                'join_status' => $joinStatus,
                'raisehand_status' => $raiseHandStatus,
                'user_media' => $userMedia,
                'display_media' => $displayMedia,
            ];

            $redis = $this->di->get('redis');
            $participantsJson = $redis->lrange('connected_participants', 0, -1);
            $participants = array_map('json_decode', $participantsJson);

            foreach ($participants as $key => $participant) {
                if ($participant->session_id === $sessionId) {
                    $isDeleted = $redis->lrem('connected_participants', $key, $participantsJson[$key]);
                    if ($isDeleted) {
                        $isCreated = $redis->rpush('connected_participants', json_encode($data));
                        if ($isCreated) {
                            return json_encode([
                                'result' => true,
                                'message' => 'Success',
                                'local' => $data
                            ]);
                        }
                    }
                    return json_encode([
                        'result' => false,
                        'message' => 'Delete failed'
                    ]);
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'Not found'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function connectAction() {
        if ($this->request->isPost()) {
            $socketId = $this->request->getPost('socket_id', 'string', 20);
            $sessionId = $this->request->getPost('session_id', 'string', 32);
            $roomId = $this->request->getPost('room_id', 'string', 36);
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string');
            $displayName = $this->request->getPost('display_name', 'string', 20);
            $email = $this->request->getPost('email', 'string', 50);
            $participantRole = $this->request->getPost('participant_role', 'string', 20);
            $audioStatus = $this->request->getPost('audio_status', 'bool');
            $videoStatus = $this->request->getPost('video_status', 'bool');
            $raiseHandStatus = $this->request->getPost('raisehand_status', 'bool');
            $blurVideo = $this->request->getPost('blur_video', 'bool');
            $backgroundVideo = $this->request->getPost('background_video', 'bool');
            $backgroundImage = $this->request->getPost('background_image');
            $mirrorVideo = $this->request->getPost('mirror_video', 'bool');
            $joinStatus = $this->request->getPost('join_status', 'string', 20);
            $userMedia = $this->request->getPost('user_media');
            $displayMedia = $this->request->getPost('display_media');
            $aiListener = $this->request->getPost('ai_listener', 'bool');
            $autoSendAll = $this->request->getPost('auto_send_all', 'bool');
            $timeStarted = $this->request->getPost('time_started');

            $connected = [
                'socket_id' => $socketId,
                'session_id' => $sessionId,
                'room_id' => $roomId,
                'room_unique_id' => $roomUniqueId,
                'display_name' => $displayName,
                'email' => $email,
                'participant_role' => $participantRole,
                'audio_status' => $audioStatus,
                'video_status' => $videoStatus,
                'mirror_video' => $mirrorVideo,
                'blur_video' => $blurVideo,
                'background_video' => $backgroundVideo,
                'background_image' => $backgroundImage,
                'raisehand_status' => $raiseHandStatus,
                'join_status' => $joinStatus,
                'user_media' => $userMedia,
                'display_media' => $displayMedia,
            ];

            $redis = $this->di->get('redis');
            $connectedParticipantJson = $redis->lrange('connected_participants', 0, -1);
            $connectedParticipants = array_map('json_decode', $connectedParticipantJson);

            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);

            // Remove any stale entry for this display_name in this room before building
            // the remotes list — handles PM2 crash where session_id changes on reconnect
            // but the old Redis entry was never cleaned up (ghost participant fix)
            foreach ($connectedParticipants as $key => $participant) {
                if ($participant->room_id === $roomId &&
                    $participant->display_name === $displayName &&
                    $participant->session_id !== $sessionId) {
                    $redis->lrem('connected_participants', 0, $connectedParticipantJson[$key]);
                    $connectedParticipantJson = $redis->lrange('connected_participants', 0, -1);
                    $connectedParticipants = array_map('json_decode', $connectedParticipantJson);
                    break;
                }
            }

            $joinedParticipants = [];
            foreach ($connectedParticipants as $key => $participant) {
                if ($participant->room_id === $roomId && $participant->session_id !== $sessionId) {
                    $joinedParticipants[] = $participant;
                }
            }

            $roomData = [];
            foreach ($rooms as $key => $room) {
                if ($room->room_name === $roomUniqueId) {
                    $roomData[] = $room;
                    if ($participantRole == 'host') {
                        if (isset($room->date_started)) break;

                        $redis->lrem('rooms', 0, $roomsJson[$key]);

                        if (!isset($room->participants) || !is_array($room->participants)) {
                            $room->participants = [];
                        }

                        $room->date_started = date('j F Y');
                        $room->time_started = $timeStarted;
                        $room->ai_listener = $aiListener;
                        $room->auto_send_all = $autoSendAll;
                        $room->participants[] = [
                            'participant' => $displayName,
                            'email' => $email,
                            'role' => $participantRole,
                            'time_joined' => time()
                        ];
                        $redis->rpush('rooms', json_encode($room));
                    } else {
                        $exists = false;
                        foreach ($room->participants as $participant) {
                            if ($participant->participant == $displayName && $participant->email == $email) {
                                $exists = true;
                                break;
                            }
                        }
                        if ($exists) break;

                        $redis->lrem('rooms', 0, $roomsJson[$key]);

                        $updatedRoom = $room;
                        $updatedRoom->participants[] = [
                            'participant' => $displayName,
                            'email' => $email,
                            'role' => $participantRole,
                            'time_joined' => time()
                        ]; 
                        $redis->rpush('rooms', json_encode($updatedRoom));
                    }
                }
            }

            foreach($connectedParticipants as $key => $participant) {
                if ($participant->session_id === $sessionId) {
                    $isDeleted = $redis->lrem('connected_participants', $key, $connectedParticipantJson[$key]);
                    if ($isDeleted) {
                        $isConnectedCreated = $redis->rpush('connected_participants', json_encode($connected)); 
                        if ($isConnectedCreated) {
                            return json_encode([
                                'result' => true,
                                'message' => 'Success',
                                'local' => $connected,
                                'remotes' => $joinedParticipants,
                                'room_data' => $roomData
                            ]);
                        }
                    }
                    return json_encode([
                        'result' => false,
                        'message' => 'Delete failed'
                    ]);
                }
            }

            $isConnectedCreated = $redis->rpush('connected_participants', json_encode($connected));
            if ($isConnectedCreated) {
                return json_encode([
                    'result' => true,
                    'message' => 'Success',
                    'local' => $connected,
                    'remotes' => $joinedParticipants,
                    'room_data' => $roomData
                ]);
            }
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function reconnectAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string');
            $displayName = $this->request->getPost('display_name');
            $sessionId = $this->request->getPost('session_id', 'string');
            $socketId = $this->request->getPost('socket_id', 'string');
            $redis = $this->di->get('redis');
            $participantsJson = $redis->lrange('connected_participants', 0, -1);
            $participants = array_map('json_decode', $participantsJson); 
            foreach ($participants as $key => $participant) {
                if ($participant->session_id === $sessionId && $participant->room_id === $roomId) {
                    $isDel = $redis->lrem('connected_participants', $key, $participantsJson[$key]);
                    if ($isDel) {
                        $isCreated = $redis->rpush('connected_participants', json_encode([
                            'room_id' => $roomId,
                            'session_id' => $sessionId,
                            'socket_id' => $socketId,
                            'display_name' => $displayName
                        ]));
                        if ($isCreated) {
                            return json_encode([
                                'result' => true,
                                'message' => 'Success'
                            ]);
                        }
                        return json_encode([
                            'result' => false,
                            'message' => 'Failed'
                        ]); 
                    }
                    return json_encode([
                        'result' => false,
                        'message' => 'Update failed'
                    ]); 
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'Not found.'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function disconnectParticipantAction() {
        if ($this->request->isPost()) {
            $rawBody = $this->request->getRawBody();
            $data = json_decode($rawBody, true);
            $redis = $this->di->get('redis');
            $participantsJSON = $redis->lrange('connected_participants', 0, -1);
            $participants = array_map('json_decode', $participantsJSON);

            foreach ($participants as $key => $participant) {
                if ($participant->socket_id === $data['socket_id']) {
                    $redis->lrem('connected_participants', $key, $participantsJSON[$key]);
                    return $this->response->setJsonContent([
                        'result' => true,
                        'type' => 'disconnected',
                        'join_status' => $participant->join_status,
                        'room_id' => $participant->room_id,
                        'socket_id' => $participant->socket_id,
                        'session_id' => $participant->session_id,
                        'display_name' => $participant->display_name
                    ]);
                }
            }
            return $this->response->setJsonContent([
                'result' => false,
                'message' => 'Participant not found.'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function leaveAction() {
        if ($this->request->isPost()) {
            $socketId = $this->request->getPost('socket_id', 'string');
            $roomId = $this->request->getPost("room_id");
            $redis = $this->di->get('redis');
            $participantsJson = $redis->lrange('connected_participants', 0, -1);
            $participants = array_map('json_decode', $participantsJson);
            foreach ($participants as $key => $participant) {
                if ($participant->socket_id === $socketId && $participant->room_id === $roomId) {
                    $isDeleted = $redis->lrem('connected_participants', $key, $participantsJson[$key]);
                    if ($isDeleted) {
                        return json_encode([
                            'result' => true,
                            'message' => 'Success',
                            'socket_id' => $participant->socket_id,
                            'session_id' => $participant->session_id,
                            'display_name' => $participant->display_name
                        ]);
                    }
                }
            }
            return json_encode([]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function sendMessageAction() {
        $name = $this->request->getPost('name', 'string', 30);
        $senderId = $this->request->getPost('sender', 'string', 32);
        $receiver = $this->request->getPost('receiver', 'string', 32);
        $message = $this->request->getPost('message', 'string');
        $time = $this->request->getPost('time', 'string', 8); 
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'video/mp4',
            'video/x-ms-wmv', 
            'video/x-flv',
            'video/ogg',
            'video/webm',
            'video/3gpp',
        ]; 
        $files = [];
        
        if ($this->request->hasFiles()) {
            $uploadsDir = "../public/meet_chat/attachments/";
            foreach ($this->request->getUploadedFiles() as $file) {
                $fileName = $file->getName();
                $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
                $mimeType = $file->getType(); 
                
                if (!in_array($mimeType, $allowedMimeTypes)) {
                    return json_encode([
                        'result' => false,
                        'message' => 'Invalid file type: ' . $mimeType . '. Only PNG, JPG, GIF, BMP, and supported video formats are allowed.'
                    ]);
                } 
    
                $uniqueFileName = uniqid() . '-' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
                if ($file->moveTo($uploadsDir . $uniqueFileName) === false) {
                    return json_encode([
                        'result' => false,
                        'message' => 'Failed to move the uploaded file: ' . $file->getError()
                    ]);
                }
                $files[] = $uniqueFileName;
            }
        } 
        
        $data = [
            'name' => $name,
            'sender' => $senderId,
            'receiver' => $receiver,
            'message' => $message,
            'time' => $time,
            'files' => $files
        ]; 
        
        $redis = $this->di->get('redis');
        $isCreated = $redis->rpush('chat', json_encode($data));
    
        if ($isCreated) { 
            return json_encode([
                'result' => true,
                'message' => 'Message sent.',
                'files' => $files
            ]);
        } 
        return json_encode([
            'result' => false,
            'message' => 'Message failed to send.'
        ]);
    } 

    public function removeSessionAction() {
        if ($this->request->isPost()) {
            $sessionIds = $this->request->getPost('session_ids');
            
            if (empty($sessionIds) || !is_array($sessionIds)) {
                return json_encode([
                    'result' => false,
                    'message' => 'Invalid session IDs'
                ]);
            }
    
            $redis = $this->di->get('redis');
            $connectedParticipantsJson = $redis->lrange('connected_participants', 0, -1);
            $connectedParticipants = array_map('json_decode', $connectedParticipantsJson);
            
            foreach ($sessionIds as $sessionId) {
                foreach ($connectedParticipants as $connKey => $connParticipant) {
                    if ($connParticipant->session_id === $sessionId) {
                        $redis->lrem('connected_participants', $connKey, $connectedParticipantsJson[$connKey]);
                        break;
                    }
                }
            }
    
            return json_encode([
                'result' => true,
                'message' => 'Participants removed successfully'
            ]);
        }
    }

    public function totalInMeetingAction() {
        if ($this->request->isPost()) {
            $parameter = array();
            $parameter['room_id'] = $this->request->getPost('room_id', 'string', 32);
            $redis = $this->di->get('redis');

            $roomsJson = $redis->lrange('rooms', 0, -1);
            $meetingId = null;
            $meetType = null;
            foreach ($roomsJson as $roomJson) {
                $room = json_decode($roomJson);
                if ($room->room_name === $parameter['room_id'] || $room->owner === $parameter['room_id']) {
                    if (isset($room->status) && $room->status === 'ended') {
                        return json_encode([
                            'result' => false,
                            'total' => 0,
                            'message' => 'Meet ended'
                        ]);
                    }
                    $meetingId = $room->room_name;
                    $meetType = $room->meet_type ?? null;
                    break;
                }
            }

            $connectedParticipantsJson = $redis->lrange('connected_participants', 0, -1);
            $connectedParticipants = array_map('json_decode', $connectedParticipantsJson);

            $total = 0;
            foreach ($connectedParticipants as $key => $value) {
                if (strtolower($value->room_id) === strtolower($parameter['room_id'])  && $value->join_status === 'accepted') {
                    $total++;
                    $meetingId = $value->room_unique_id;
                }
            }

            return json_encode([
                'result' => true,
                'total' => $total,
                'meeting_id' => $meetingId,
                'meet_type' => $meetType
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function endMeetAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost("room_id", 'string', 36);
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string');
            $redis = $this->di->get('redis');
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);
            $participantsJson = $redis->lrange('connected_participants', 0, -1);
            $participants = array_map('json_decode', $participantsJson);
            $meetingSummary = '';
            $openAI = [];
            $totalParticipants = 0;
            $dateStarted = '';
            $timeStarted = '';

            foreach ($rooms as $room) {
                if (!property_exists($room, 'meet_conversations')) {
                    break;
                }
                $meetConversations = count($room->meet_conversations); 
                if ($meetConversations > 0) {
                    $subject = $this->request->getPost('subject');
                    $emails = $this->request->getPost('emails');
                    $meetDuration = $this->request->getPost('meet_duration');
                    if ($room->owner == $roomId && $room->room_name == $roomUniqueId) {
                        $concatenatedMessages = '';
                        foreach ($room->meet_conversations as $convo) {
                            $concatenatedMessages .= $convo->participant. ': ' . $convo->message . ' ';
                        }
                        $inMeetingHost = '';
                        $inMeetingCohost = '';
                        $inMeetingParticipants = ''; 
                        function appendParticipant(&$list, $participant) {
                            $list .= $participant->participant;
                            if (filter_var($participant->email, FILTER_VALIDATE_EMAIL)) { 
                                $list .= $participant->email;
                            }
                            $list .= ', ';
                        }
                        foreach ($room->participants as $participant) {
                            if ($participant->role == 'host') appendParticipant($inMeetingHost, $participant);
                            if ($participant->role == 'cohost') appendParticipant($inMeetingCohost, $participant);
                            if ($participant->role == 'participant') appendParticipant($inMeetingParticipants, $participant);
                        } 
                        $open_ai = new OpenAi('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');
                        $chat = $open_ai->chat([
                            'model' => 'gpt-4o',
                            'messages' => [
                                [
                                    'role' => 'system',
                                    'content' => 'Use this template to generate meeting details: ' . $this->meetDetails() . 
                                                'Rules:
                                                    * Generate only the HTML without including "```html" or making additional comments.
                                                    * Use the format "1:00 PM to 2:00 PM" for time.
                                                    * Specify the duration in minutes (min), hours (hr\'s), or days.
                                                    * The meeting summary should reflect the conversation of all participants.
                                                    * The meeting summary should be detailed and comprehensive.
                                                    * If there is no cohost or participant, put "N/A".
                                                    * Remove other categories if there is no value'
                                ],
                                [
                                    'role' => 'user',
                                    'content' => "
                                        Here are the room details:
                                            * Date started: $room->date_started
                                            * Time started: $room->time_started
                                            * Time ended: ". date('g:i A') ."
                                            * Meet duration: $meetDuration
                                            * Summarize this conversations: $concatenatedMessages
                                            * Participants: 
                                                - Host: ". rtrim($inMeetingHost, ', ') ."
                                                - Cohost: ". rtrim($inMeetingCohost, ', ') ."
                                                - Participants: ". rtrim($inMeetingParticipants) ."
                                    "
                                ],
                            ],
                            'temperature' => 1.0,
                            'max_tokens' => 4000,
                            'frequency_penalty' => 0,
                            'presence_penalty' => 0,
                        ]);
                        $d = json_decode($chat, true);
                        $meetingSummary = $d['choices'][0]['message']['content'];
                        foreach ($emails as $value) {
                            $this->sendEmail(
                                "RingQ AI meeting summery for “{$subject}”",
                                $meetingSummary,
                                $value
                            );
                        }
                        $openAI = $d;
                        $totalParticipants = count($room->participants);
                        $dateStarted = $room->date_started;
                        $timeStarted = $room->time_started; 
                    }
                }
            } 

            foreach ($participants as $key => $participant) {
                if ($participant->room_id === $roomId) {
                    $isDeleted = $redis->lrem('connected_participants', $key, $participantsJson[$key]);
                    if ($isDeleted) {
                        foreach ($rooms as $roomKey => $room) {
                            if ($room->room_name == $roomUniqueId) {
                                $redis->lrem('rooms', 0, $roomsJson[$roomKey]);
                                $room->time_ended = date('g:i A');
                                $room->status = 'ended';
                                $room->meeting_summary = $meetingSummary;
                                $redis->rpush('rooms', json_encode($room)); 
                            }
                        }

                        return json_encode([
                            'result' => true,
                            'message' => 'Success',
                            'date_started' => $dateStarted,
                            'time_started' => $timeStarted,
                            'time_ended' => date('g:i A'),
                            'total_participants' => $totalParticipants,
                            'openAI' => $openAI
                        ]);
                    }
                    return json_encode([
                        'result' => false,
                        'message' => 'Failed'
                    ]);
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'No found'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function summaryAction() {
        $roomId = $this->request->getPost('roomd_id');
        $roomUniqueId = $this->request->getPost('room_unique_id');
        $redis = $this->di->get('redis');
        $totalParticipants = 0;
        $connectedParticipantsJson = $redis->lrange('connected_participants', 0, -1); 
        $connectedParticipants = array_map('json_decode', $connectedParticipantsJson);
        $roomsJson = $redis->lrange('rooms', 0, -1);
        $rooms = array_map('json_decode', $roomsJson);
        foreach ($connectedParticipants as $value) {
            if ($value->room_id == $roomId) {
                $totalParticipants++;
                if ($totalParticipants === 1) {
                    foreach ($rooms as $room) {
                        if ($room->owner = $roomId && $room->room_name == $roomUniqueId) {
                            return json_encode($room);
                        }
                    }
                    break;
                }
            }
        }
    }

    public function leaveMeetAction() {
        if ($this->request->isPost()) {
            $rawBody = $this->request->getRawBody();
            $data = json_decode($rawBody, true); 
            $redis = $this->di->get('redis'); 
            $connectedParticipantsJson = $redis->lrange('connected_participants', 0, -1); 
            $connectedParticipants = array_map('json_decode', $connectedParticipantsJson); 
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);

            $totalParticipants = 0;
            foreach ($connectedParticipants as $value) {
                if ($value->room_id == $data['room_id']) {
                    $totalParticipants++;
                    if ($totalParticipants === 1) {
                        

                        break;
                    }
                }
            }

            foreach ($connectedParticipants as $connKey => $connParticipant) {
                if ($connParticipant->session_id === $data['session_id'] && $connParticipant->socket_id === $data['socket_id']) {
                    $isDeleted = $redis->lrem('connected_participants', 0, $connectedParticipantsJson[$connKey]);
                    if ($isDeleted) {
                        return json_encode([
                            'result' => true,
                            'type' => 'leave participant',
                            'message' => 'Success',
                            'room_id' => $connParticipant->room_id,
                            'join_status' => $connParticipant->join_status,
                            'session_id' => $connParticipant->session_id,
                            'socket_id' => $connParticipant->socket_id,
                            'display_name' => $connParticipant->display_name,
                        ]); 
                    } else {
                        return json_encode([
                            'result' => false,
                            'message' => 'Failed to delete connected participant'
                        ]);
                    }
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'Participant not found'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function cancelJoinAction() {
        if ($this->request->isPost()) {
            $sessionId = $this->request->getPost('session_id', 'string');
            $socketId = $this->request->getPost('socket_id', 'string'); 

            $redis = $this->di->get('redis');
            $connectedParticipantsJson = $redis->lrange('connected_participants', 0, -1);
            $connectedParticipants = array_map('json_decode', $connectedParticipantsJson);

            foreach ($connectedParticipants as $connKey => $connParticipant) {
                if ($connParticipant->session_id === $sessionId && $connParticipant->socket_id === $socketId) {
                    $isDeleted = $redis->lrem('connected_participants', 0, $connectedParticipantsJson[$connKey]);
                    if ($isDeleted) { 
                        return json_encode([
                            'result' => true,
                            'message' => 'Success'
                        ]);
                    } else {
                        return json_encode([
                            'result' => false,
                            'message' => 'Failed to delete connected participant'
                        ]);
                    }
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'Participant not found'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request Error'
        ]);
    }

    public function sendInvitationsAction() {
        $extensionNo = $this->request->getPost('extension_no');
        $firstName = $this->request->getPost('first_name');
        $lastName = $this->request->getPost('last_name');
        $regards = $this->request->getPost('regards', 'string');
        $roomId = $this->request->getPost('room_id', 'string');
        $roomUniqueId = $this->request->getPost('room_unique_id', 'string');
        $email = $this->request->getPost('from_email', 'string');
        $emails = $this->request->getPost('emails'); 
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http"; 
        $host = $_SERVER['HTTP_HOST']; 
        $host = preg_replace('/:\d+$/', '', $host);  
        $fqdn = "$scheme://$host";

        $allEmailsSent = true;
        $failedEmails = [];

        $fromEmail = $email == '' || $email == null ? $firstName . ' ' . $lastName : $email;

        foreach ($emails as $value) {
            $emailSent = $this->sendEmail(
                "Happening now: {$fromEmail} is inviting you to a video call",
                $this->htmlEmailInvite(
                    $regards, 
                    "$fqdn/meet/roomJoin?room={$roomUniqueId}&email={$value['email']}", 
                    $firstName, 
                    $lastName, 
                    $extensionNo
                ), 
                $value['email']
            );
            if (!$emailSent) {
                $allEmailsSent = false;
                $failedEmails[] = $value['email'];
            }
        }

        if ($allEmailsSent) {
            return json_encode([
                'result' => true,
                'message' => 'All invitations sent successfully'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Some invitations failed to send',
            'failed_emails' => $failedEmails
        ]);
    }

    public function sendEmail($subject, $body, $email) {
        $emailService = new EmailService();
        $licenseConfig = json_decode($this->redis->get('ringq_config'), true);
        return $emailService->send(
            $email,
            $subject,
            '',
            ['type' => 'custom', 'body' => $body],
            $licenseConfig
        );
    }

    public function summarizeAI($concatenatedMessages) {
        $open_ai = new OpenAi('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');
        $chat = $open_ai->chat([
            'model' => 'gpt-4o',
            'messages' => [
                [
                    "role" => "user",
                    "content" => "Summarize the topic of the meeting in 2 paragraphs '" . $concatenatedMessages . "'"
                ],
            ],
            'temperature' => 1.0,
            'max_tokens' => 4000,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
        ]);
        $d = json_decode($chat, true);
        return $d['choices'][0]['message']['content'];
    }

    public function htmlEmailInvite($regards, $link, $firstName, $lastName, $extensionNo) {
        return $this->htmlHead('
            <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse; background:#f8f9fa;">
                <tr>
                    <td>
                        <center style="width: 100%;">
                            <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" class="email-container">
                                <thead>
                                    <td class="full-width-image">
                                        <img src="https://www.ringq.com/wp-content/uploads/2025/06/RingQmailer.jpg" width="600" alt="alt_text" border="0" align="center" style="width: 100%; max-width: 600px; height: auto;">
                                    </td>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="template-title-section" style="padding: 20px 40px 0px 40px; text-align: justify; font-family: sans-serif; font-size: 15px;  line-height: 20px; color: #555555; background-color: #ffffff"> 
                                            <strong>Hi,</strong>
                                            <br><br>
                                            <p style="margin-bottom: 20px; font-size: 14px;">
                                                You have been invited to join the RingQ Web meeting "Meet Now" by "'.$extensionNo.', '. $firstName .' '. $lastName .'"
                                            </p>
                                            <p style="margin-bottom: 20px; font-size: 14px; color: #555555;  !important;">
                                                To join from your PC or mobile, click this link:
                                                <a href="'. $link .'">'. $link .'</a>
                                            </p>
                                            <p>
                                                Regards <br>
                                                '. $regards .' <br><br>
                                                <span style="font-size: 12px">Powered By</span> <br>
                                                RingQ Meet <br><br>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td background="" bgcolor="0xFF1E274a" valign="middle" style="text-align: center; background-position: center center !important; background-size: cover !important;">
                                            <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td>
                                                    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <p style="text-align: center; padding: 10px 40px 10px 40px; font-family: Segoe, "Segoe UI", "DejaVu Sans", "Trebuchet MS", Verdana, "sans-serif"; font-size: 15px; mso-height-rule: exactly; line-height: 20px; color: "white";"> The RingQ Meet </p>
                                                    </table>
                                                </td>
                                            </tr>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot align="center" width="600" class="email-container"  style="background: #ffffff;">
                                    <tr>
                                        <td style="padding: 20px 20px;width: 100%;font-size: 12px; font-family: arial; mso-height-rule: exactly; line-height:18px; text-align: center; color: #2d2a4a;">
                                            Copyright ©️ 2024 RingQ. All Rights Reserved. 
                                            <br>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </center>
                    </td>
                </tr>
            </table>
        ');
    }

    public function htmlMeetSummaryEmail($meetingSummary, $hostName = '', $cohostName = '', $meetingTitle = '', $dateStarted = '', $timeStart = '', $timeEnd = '', $duration, $participants = '') {
        return $this->htmlHead('
            <table bgcolor="#e0e0e0" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse; background:#f8f9fa;">
                <tr>
                    <td>
                        <center style="width: 100%;">
                            <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" class="email-container">
                                <thead>
                                    <td class="full-width-image">
                                        <img src="https://www.ringq.com/wp-content/uploads/2025/06/RingQmailer.jpg" width="600" alt="alt_text" border="0" align="center" style="width: 100%; max-width: 600px; height: auto;">
                                    </td>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="template-title-section" style="padding: 20px 40px 0px 40px; text-align: justify; font-family: sans-serif; font-size: 15px; line-height: 20px; color: #555555; background-color: #ffffff"> 
                                            <strong>Meeting Summary</strong>
                                            <br><br>
                                            <p style="margin-bottom: 20px; font-size: 14px;">
                                                '. $meetingSummary .'
                                            </p>
                                            <p style="margin-bottom: 20px; font-size: 14px; color: #555555;">
                                                <div style="margin-bottom: 3px;"><strong>Host:</strong> '. $hostName .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>Cohost:</strong> '. $cohostName .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>Title of the Meeting:</strong> '. $meetingTitle .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>Date Started:</strong> '. $dateStarted .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>Time Started:</strong> '. $timeStart .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>End Time:</strong> '. $timeEnd .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>Duration:</strong> '. $duration .'<br></div>
                                                <div style="margin-bottom: 3px;"><strong>Participants Joined:</strong> '. $participants .'<br></div>
                                                <br><br>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td background="" bgcolor="0xFF1E274a" valign="middle" style="text-align: center; background-position: center center !important; background-size: cover !important;">
                                            <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td>
                                                    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%">
                                                        <p style="text-align: center; padding: 10px 40px 10px 40px; font-family: Segoe, \'Segoe UI\', \'DejaVu Sans\', \'Trebuchet MS\', Verdana, \'sans-serif\'; font-size: 15px; mso-height-rule: exactly; line-height: 20px; color: white;"> The RingQ Meet </p>
                                                    </table>
                                                </td>
                                            </tr>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot align="center" width="600" class="email-container" style="background: #ffffff;">
                                    <tr>
                                        <td style="padding: 20px 20px;width: 100%;font-size: 12px; font-family: arial; mso-height-rule: exactly; line-height:18px; text-align: center; color: #2d2a4a;">
                                            Copyright ©️ 2024 RingQ. All Rights Reserved.
                                            <br>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </center>
                    </td>
                </tr>
            </table>
        ');
    }

    public function htmlHead($body) {
        return '
            <html>
                <head>
                    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                    <meta http-equiv="X-UA-Compatible" content="IE=edge">
                    <title>Your RingQ Verification Code</title>
                    <style type="text/css">body,html{margin:0!important;padding:0!important;height:100%!important;width:100%!important}{-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%}.ExternalClass{width:100%}div[style="margin: 16px 0"]{margin:0!important}table,td{mso-table-lspace:0!important;mso-table-rspace:0!important}table{border-spacing:0!important;border-collapse:collapse!important;table-layout:fixed!important;margin:0 auto!important}table table table{table-layout:auto}img{-ms-interpolation-mode:bicubic}.yshortcuts a{border-bottom:none!important}a[x-apple-data-detectors]{color:inherit!important}a:link{text-decoration:none;color:rgb(123, 0, 255)}a:visited{color:#87ceeb}
                    </style>
                    <style type="text/css">.button-a,.button-td{transition:all .1s ease-in}.button-a:hover,.button-td:hover{background:#0089cb!important;border-color:#0089cb!important}@media screen and (max-width:600px){.email-container{width:100%!important}.fluid,.fluid-centered{max-width:100%!important;height:auto!important;margin-left:auto!important;margin-right:auto!important}.fluid-centered{margin-left:auto!important;margin-right:auto!important}.stack-column,.stack-column-center{display:block!important;width:100%!important;max-width:100%!important;direction:ltr!important}.stack-column-center{text-align:center!important}.center-on-narrow{text-align:center!important;display:block!important;margin-left:auto!important;margin-right:auto!important;float:none!important}table.center-on-narrow{display:inline-block!important}}</style>
                </head>
                <body bgcolor="#e0e0e0" width="100%" style="margin:0" yahoo="yahoo">
                   '. $body .'
                </body>
            </html>
        ';
    }

    public function webinarRegisterAction() {
        if ($this->request->isPost()) {
            $parameters = array();
            $parameters['name'] = $this->request->getPost('name', 'string', 50);
            $parameters['mail_id'] = $this->request->getPost('mail_id', 'string', 50);
            $parameters['mobile_no'] = $this->request->getPost('mobile_no', 'integer', 15);
            $parameters['destination'] = $this->request->getPost('destination', 'string', 100);
            $parameters['city'] = $this->request->getPost('city', 'string', 50);

            if (empty($parameters["name"])) {
                $contents = file_get_contents("php://input");
                $parameters = json_decode($contents, true);
            }

            $data = [
                'name' => $parameters['name'],
                'mail_id' =>  $parameters['mail_id'],
                'mobile_no' => $parameters['mobile_no'],
                'destination' => $parameters['destination'],
                'city' => $parameters['city'],
            ]; 
            $redis = $this->di->get('redis');
            $isCreated = $redis->rpush('register_webinar', json_encode($data));
            if ($isCreated) {
                return json_encode([
                    'result' => true,
                    'message' => 'Data saved.'
                ]);
            }
            return json_encode([
                'result' => false,
                'message' => 'Data failed to save.'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function webinarInformationAction() {
        if ($this->request->isGet()) {
            return json_encode([
                'result' => true,
                'message' => 'Success',
                'date' => 'July 15, 2024',
                'time' => '2:00 PM - 3:30 PM',
                'image' => 'https://ringq.xyz:8443/webinar/example-webinar.png',
                'title' => 'Embracing Digital Transformation in 2024: Strategies for Success',
                'descriptions' => [
                    'p1' => 'Join us for an insightful webinar on embracing digital transformation in 2024. As businesses continue to evolve in the digital age, it is crucial to stay ahead of the curve. This webinar will cover:',
                    'l1' => [
                        'The latest trends in digital transformation.',
                        'Key strategies to implement digital tools and technologies.',
                        'Case studies of successful digital transformation journeys.',
                        'Best practices for overcoming common challenges.'
                    ],
                    'p2' => 'Our expert speakers will provide practical insights and actionable advice to help you navigate the complexities of digital transformation. Whether you are a business leader, IT professional, or simply interested in the future of digital innovation, this webinar is designed to equip you with the knowledge and tools you need to succeed. Speakers:',
                    'l2' => [
                        [
                            'name' => 'Dr. Jane Smith',
                            'designation' => 'Chief Digital Officer at InnovateTech'
                        ],
                        [
                            'name' => 'John Doe',
                            'designation' => 'Senior Digital Strategist at Tech Solutions'
                        ],
                        [
                            'name' => 'Mary Johnson',
                            'designation' => 'CEO of FutureForward Inc.'
                        ],
                    ],
                ]
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function updateMeetStatusAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string', 50);
            $roomUniqueid = $this->request->get('room_unique_id', 'string', 50);
            $aiListener = $this->request->getPost('ai_listener', 'bool');

            $redis = $this->di->get('redis');
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);

            foreach ($rooms as $key => $value) {
                if ($value->owner == $roomId && $value->room_name == $roomUniqueid) {
                    $isDel = $redis->lrem('rooms', $key, $roomsJson[$key]);
                    if ($isDel) {
                        $value->ai_listener = $aiListener;
                        $redis->rpush('rooms', json_encode($value));
                    }
                }
            }
            return json_encode([
                'result' => true,
                'message' => 'Success'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error'
        ]);
    }

    public function saveSpeechAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string', 50);
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string', 60);
            $meetContext = $this->request->getPost('meet_conversations', 'string');
    
            $redis = $this->di->get('redis');
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);
    
            foreach ($rooms as $key => $value) {
                if ($value->owner == $roomId && $value->room_name == $roomUniqueId) {
                    $del = $redis->lrem('rooms', 0, $roomsJson[$key]);
                    if ($del) {
                        if (!isset($value->meet_conversations) || !is_array($value->meet_conversations)) {
                            $value->meet_conversations = [];
                        }
     
                        $value->meet_conversations[] = $meetContext;
                        $redis->rpush('rooms', json_encode($value));
                    }
                    return json_encode([
                        'result' => true,
                        'message' => 'Meet context updated successfully.'
                    ]);
                }
            }
    
            return json_encode([
                'result' => false,
                'message' => 'Room not found.'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function saveRecordingAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string', 50);
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string', 60);
            
            if ($this->request->hasFiles()) {
                $files = $this->request->getUploadedFiles();
                $recordingFile = $files[0];
                
                $uploadDir = '../public/meet_recordings/';
                $fileName = $roomUniqueId . '_' . time() . '.' . $recordingFile->getExtension();
                
                if ($recordingFile->moveTo($uploadDir . $fileName)) {
                    $redis = $this->di->get('redis');
                    $roomsJson = $redis->lrange('rooms', 0, -1);
                    $rooms = array_map('json_decode', $roomsJson);
                    
                    foreach ($rooms as $key => $room) {
                        if ($room->owner == $roomId && $room->room_name == $roomUniqueId) {
                            $del = $redis->lrem('rooms', 0, $roomsJson[$key]);
                            if ($del) {
                                if (!isset($room->recordings) || !is_array($room->recordings)) {
                                    $room->recordings = [];
                                }
                                $room->recordings[] = [
                                    'file_name' => $fileName,
                                    'timestamp' => time()
                                ];
                                $redis->rpush('rooms', json_encode($room));
                            }
                            return json_encode([
                                'result' => true,
                                'message' => 'Recording saved successfully.',
                                'file_name' => $fileName
                            ]);
                        }
                    }
                    return json_encode([
                        'result' => false,
                        'message' => 'Room not found for recording update.'
                    ]);
                } else {
                    $error = $recordingFile->getError();
                    $msg = 'Failed to move recording file.';
                    if ($error) {
                        $msg .= ' Upload error code: ' . $error;
                    }
                    return json_encode([
                        'result' => false,
                        'message' => $msg,
                        'upload_dir' => $uploadDir,
                        'real_path' => realpath($uploadDir),
                        'is_writable' => is_writable($uploadDir)
                    ]);
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'No recording file uploaded.'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function updateRoomAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string');
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string');
            $autoSendAll = $this->request->getPost('auto_sendall', 'bool');

            $redis = $this->di->get('redis');
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);

            foreach ($rooms as $key => $room) {
                if ($room->owner == $roomId && $room->room_name == $roomUniqueId) {
                    $del = $redis->lrem('rooms', $key, $roomsJson[$key]);
                    if ($del) {
                        $room->auto_send_all = $autoSendAll;
                        $redis->rpush('rooms', json_encode($room));
                        return json_encode([
                            'result' => true,
                            'message' => 'Room updated.'
                        ]);
                    }
                }
            }
            return json_encode([
                'result' => false,
                'message' => 'not found'
            ]);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function meetingHistoryAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id');

            $redis = $this->di->get('redis');
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);

            $history = [];
            foreach ($rooms as $key => $value) {
                if ($value->owner == $roomId && isset($value->participants)) {
                    $history[] = [
                        'date_started' => $value->date_started,
                        'time_started' => $value->time_started,
                        'time_ended' => $value->time_ended,
                        'data' => $value,
                        'recordings' => isset($value->recordings) ? $value->recordings : []
                    ];
                }
            } 
            return json_encode($history);
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error'
        ]);
    } 

    public function getMeetDataAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id');
            $roomUniqueId = $this->request->getPost('room_unique_id');

            $redis = $this->di->get('redis'); 
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);
            
            $data = [
                'emails' => [],
                'meeting_summary' => false
            ];
            
            foreach ($rooms as $room) {
                if ($room->owner == $roomId && $room->room_name == $roomUniqueId) {
                    foreach ($room->participants as $participant) {
                        if (filter_var($participant->email, FILTER_VALIDATE_EMAIL)) {
                            $data['emails'][] = $participant->email;
                        }
                    }
                    $totalConversations = count($room->meet_conversations);
                    $data['meeting_summary'] = $totalConversations > 1;
                }
            }

            return json_encode($data); 
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function updateRoomRoleParticipantAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string', 40);
            $roomUniqueId = $this->request->getPost('room_unique_id', 'string');
            $email = $this->request->getPost('email', 'string');
            $displayName = $this->request->getPost('display_name', 'string');

            $redis = $this->di->get('redis');
            $roomsJson = $redis->lrange('rooms', 0, -1);
            $rooms = array_map('json_decode', $roomsJson);

            foreach ($rooms as $keyRoom => $room) {
                if ($room->owner == $roomId && $room->room_name == $roomUniqueId) {
                    foreach ($room->participants as $pKey => $participant) {
                        if ($participant->email == $email || $participant->participant == $displayName) {
                            $room->participants[$pKey]->role = 'cohost';
                            break;
                        }
                    } 
                    $rooms[$keyRoom] = $room;
                    $redis->lset('rooms', $keyRoom, json_encode($room));
                    return json_encode([
                        'result' => true,
                        'message' => 'Success'
                    ]);
                }
            }
        }
        return json_encode([
            'result' => false,
            'message' => 'Request error'
        ]);
    }

    public function meetDetails() {
        return '
            <!DOCTYPE html>
            <html>
            <head>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background-color: #f8f9fa;
                    margin: 0;
                    padding: 0;
                }
                .email-container {
                    width: 600px;
                    margin: 0 auto;
                    background-color: #ffffff;
                    border: 1px solid #dddddd;
                    box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
                }
                .full-width-image img {
                    width: 100%;
                    max-width: 600px;
                    height: auto;
                }
                .content {
                    padding: 20px 40px;
                    font-size: 15px;
                    line-height: 1.6;
                    color: #555555;
                }
                .content h2, .content h3, .content h4 {
                    color: #333333;
                }
                .metrics {
                    margin-top: 20px;
                }
                .metrics p {
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                .footer {
                    background-color: #1e274a;
                    text-align: center;
                    padding: 20px;
                    color: white;
                }
                .footer-text {
                    font-size: 12px;
                    line-height: 1.5;
                    color: #d1d1e0;
                }
            </style>
            </head>
            <body>
                <table bgcolor="#f8f9fa" cellpadding="0" cellspacing="0" border="0" height="100%" width="100%" style="border-collapse:collapse;">
                    <tr>
                        <td>
                            <center style="width: 100%;">
                                <table cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" width="600" class="email-container">
                                    <thead>
                                    <tr>
                                        <td class="full-width-image">
                                        <img src="https://www.ringq.com/wp-content/uploads/2025/06/RingQmailer.jpg" width="600" alt="Meeting Header Image" border="0" align="center">
                                        </td>
                                    </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="content">
                                                <h2 class="font-bold text-2xl mb-4">Meeting Details</h2>
                                                <p><strong>Date:</strong> [Insert Date]</p>
                                                <p><strong>Time:</strong> [Insert Time]</p>
                                                <p><strong>Duration:</strong> [Insert Duration]</p>
                                                
                                                <h3 class="font-bold text-2xl mb-4">Meeting Summary</h3>
                                                <p>[Insert Summary]</p>
                                                
                                                <h3 class="mb-2 mt-4 font-semibold text-xl">Recap</h3>
                                                <h4 class="mb-2 mt-4 font-semibold text-lg">Chapters & Topics</h4>
                                                <ul>
                                                    <li><strong>[Key Topic]</strong> [Summary Topic]</li>
                                                    [Add more up to 5 list]
                                                </ul>
                                                
                                                <h4 class="mb-2 mt-4 font-semibold text-lg">Action Items</h4>
                                                <ul>
                                                    <li><strong>[Key Topic]</strong>
                                                        <ul class="list-disc ml-5">
                                                            <li>[Summary Topic]</li>
                                                            <li>[Summary Topic]</li>
                                                        </ul>
                                                    </li>
                                                    [Add more up to 5 list]
                                                </ul>
                                                
                                                <h4 class="font-semibold">Key Questions</h4>
                                                <ul class="list-disc ml-5">
                                                    <li>[Meet asked Questions]</li>
                                                    [Add more up to 5 list]
                                                </ul>
                                                
                                                <div class="metrics">
                                                    <h3>Metrics</h3>
                                                    <p><strong>Read Score:</strong> [Insert Score]</p>
                                                    <p><strong>Engagement:</strong> [Insert Engagement]</p>
                                                    <ul class="list-disc ml-5">
                                                        <li>Participation rate: [Insert Rate]</li>
                                                        <li>Questions asked: [Insert Number]</li>
                                                        <li>Action items created: [Insert Number]</li>
                                                    </ul>
                                                    <p class="mt-4 mb-2"><strong>Sentiment:</strong> [Insert Sentiment]</p>
                                                </div>
            
                                                <div class="inmeeting">
                                                    <h3 class="mb-2 mt-4 font-semibold text-xl">In-meeting Participants</h3>
                                                    <p><strong>Host:</strong> [Host name] <a href="mailto:[Host email address]" class="text-blue-500">[Host email address]</a></p>
                                                    <p><strong>Co-host:</strong> [Cohost name] <a href="mailto:[Cohost email address]" class="text-blue-500">[Cohost email address]</a></p>
                                                    <p><strong>Participants:</strong></p>
                                                    [Participant name] <a href="mailto:[Participant email]" class="text-blue-500">[Participant email]</a>
                                                    [Add the remaining here]
                                                </div> 
                                                
                                                <p class="my-4">For any additions or corrections, please reach out to us.</p>
                                                <p>Best,<br>RingQ Meet</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="footer">
                                                <p>The RingQ Meet</p>
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td style="padding: 20px; width: 100%; font-size: 12px; text-align: center; color: #2d2a4a;">
                                            Copyright © 2025 RingQ. All Rights Reserved.
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </center>
                        </td>
                    </tr>
                </table>
            </body>
            </html> 
        ';
    }

    public function saveDrawAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string');
            $imageData = $this->request->getPost('image');
    
            if ($roomId && $imageData) {
                $imageData = str_replace('data:image/png;base64,', '', $imageData);
                $imageData = str_replace(' ', '+', $imageData);
                $decodedImage = base64_decode($imageData);
    
                $filePath = '../public/meet_whiteboards/' . $roomId . '.png';
    
                if ($decodedImage === false) {
                    return json_encode([
                        'result' => false,
                        'message' => 'Failed to decode image data.'
                    ]);
                }

                if (file_put_contents($filePath, $decodedImage) !== false) {
                    return json_encode([
                        'result' => true,
                        'message' => 'Drawing saved successfully.',
                        'file_path' => $filePath
                    ]);
                } else {
                    return json_encode([
                        'result' => false,
                        'message' => 'Failed to save drawing.'
                    ]);
                }
            }
            
            return json_encode([
                'result' => false,
                'message' => 'Invalid data provided.'
            ]);
        }
        
        return json_encode([
            'result' => false,
            'message' => 'Request error.'
        ]);
    }

    public function getDrawAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string');
            $filePath = '../public/whiteboards/' . $roomId . '.png';
    
            if (file_exists($filePath)) {
                $imageData = base64_encode(file_get_contents($filePath));
                return json_encode([
                    'result' => true,
                    'image' => 'data:image/png;base64,' . $imageData
                ]);
            }

            return json_encode([
                'result' => false,
                'message' => 'Drawing not found.'
            ]);
        }

        return json_encode([
            'result' => false,
            'message' => 'Invalid request method.'
        ]);
    }

    public function deleteDrawAction() {
        if ($this->request->isPost()) {
            $roomId = $this->request->getPost('room_id', 'string');

            if ($roomId) {
                $filePath = '../public/whiteboards/' . $roomId . '.png';
                if (file_exists($filePath) && unlink($filePath)) {
                    return json_encode([
                        'result' => true,
                        'message' => 'Drawing deleted successfully.'
                    ]);
                }
            }

            return json_encode([
                'result' => false,
                'message' => 'Failed to delete drawing.'
            ]);
        }

        return json_encode([
            'result' => false,
            'message' => 'Invalid request method.'
        ]);
    }

    public function getMeetingDetailsAction() {
        $roomId = $this->request->getPost('roomId', 'string'); 

        $roomsKey = $this->redis->lrange('rooms', 0, -1);
        $rooms = array_map('json_decode', $roomsKey);

        foreach ($rooms as $roomDetails) {
            if ($roomDetails->room_name == $roomId) {
                return json_encode([
                    'result' => 'success', 
                    'data' => $roomDetails
                ]);
            }
        }
        return json_encode([
            'result' => 'error',
            'msg' => 'not found',
        ]);
    }

    public function editMeetingAction() {
        // $roomId = $this->request->getPost("roomId", 'string');
        $roomId = 'b70bf420-e987-462c-9dab-7ed47a05df72';
        $newRoomId = 'New-Room-Id';

        $roomsKey = $this->redis->lrange('rooms', 0, -1);
        $rooms = array_map('json_decode', $roomsKey);

        foreach ($rooms as $key => $room) {
            if ($room->room_name == $roomId) {
                $rooms[$key]->room_name = $newRoomId;
                break;
            }
        }
    }
}