<?php
declare(strict_types=1);

/**
 * Helpers for IndexController::playgroundCallAction (the in-app API Playground).
 *
 * The playground lets a logged-in admin try the call APIs from the browser. The browser never sees
 * the API secret: the server signs the request with the tenant's own key and calls the real
 * endpoint on this machine, so the permission toggles and validation behave exactly as for an
 * external caller.
 */
trait ApiSupportPlayground
{
    /**
     * Allow-list of what the playground may call: API key => allowed methods (first is the default),
     * path, and whether the call changes or removes live data (needs an explicit confirm).
     * Nothing outside this list can be reached through the playground.
     *
     * @return array<string, array{methods: string[], path: string, confirm: bool, id?: bool}>
     */
    protected function apiPlaygroundRoutes(): array
    {
        $post = ['POST'];
        return [
            // Call control
            'make_call'               => ['methods' => $post, 'path' => '/index/makeCall', 'confirm' => true],
            'divert_call'             => ['methods' => $post, 'path' => '/index/divertCallByExtension', 'confirm' => false],
            'divert_voicemail'        => ['methods' => $post, 'path' => '/index/divertCallToVoicemail', 'confirm' => false],
            'pickup_call'             => ['methods' => $post, 'path' => '/index/pickupCallByExtension', 'confirm' => false],
            'transfer_call'           => ['methods' => $post, 'path' => '/index/transferCall', 'confirm' => false],
            'drop_call'               => ['methods' => $post, 'path' => '/index/dropCall', 'confirm' => true],
            'join_call'               => ['methods' => $post, 'path' => '/index/joinCall', 'confirm' => false],
            // Supervisor tools
            'listen'                  => ['methods' => $post, 'path' => '/index/listenCall', 'confirm' => false],
            'whisper'                 => ['methods' => $post, 'path' => '/index/whisperCall', 'confirm' => false],
            'bargein'                 => ['methods' => $post, 'path' => '/index/bargeInCall', 'confirm' => false],
            // Recordings and voicemail
            'record_start'            => ['methods' => $post, 'path' => '/index/recordStart', 'confirm' => false],
            'record_stop'             => ['methods' => $post, 'path' => '/index/recordStop', 'confirm' => false],
            'recording_list'          => ['methods' => $post, 'path' => '/index/recordingList', 'confirm' => false],
            'recording_link'          => ['methods' => $post, 'path' => '/index/recordingLink', 'confirm' => false],
            'voicemail_list'          => ['methods' => $post, 'path' => '/index/voicemailList', 'confirm' => false],
            'voicemail_delete'        => ['methods' => $post, 'path' => '/index/voicemailDelete', 'confirm' => true],
            'change_vm_pin'           => ['methods' => $post, 'path' => '/index/changeVoicemailPin', 'confirm' => false],
            // Queues
            'queue_login'             => ['methods' => $post, 'path' => '/index/queueLogin', 'confirm' => false],
            'queue_logout'            => ['methods' => $post, 'path' => '/index/queueLogout', 'confirm' => false],
            'add_queue'               => ['methods' => $post, 'path' => '/index/addQueue', 'confirm' => false],
            'update_queue'            => ['methods' => $post, 'path' => '/index/updateQueue', 'confirm' => false],
            'delete_queue'            => ['methods' => $post, 'path' => '/index/deleteQueue', 'confirm' => true],
            'add_agents_queue'        => ['methods' => $post, 'path' => '/index/addAgentsQueue', 'confirm' => false],
            'remove_agents_queue'     => ['methods' => $post, 'path' => '/index/removeAgentsQueue', 'confirm' => true],
            // Agents and extensions
            'add_agent'               => ['methods' => $post, 'path' => '/index/addAgent', 'confirm' => false],
            'update_agent'            => ['methods' => $post, 'path' => '/index/updateAgent', 'confirm' => false],
            'delete_agent'            => ['methods' => $post, 'path' => '/index/deleteAgent', 'confirm' => true],
            'query_extension'         => ['methods' => $post, 'path' => '/index/queryExtension', 'confirm' => false],
            'change_user_status'      => ['methods' => $post, 'path' => '/index/changeUserStatus', 'confirm' => false],
            'change_recording_status' => ['methods' => $post, 'path' => '/index/changeRecordingStatus', 'confirm' => false],
            'disable_extension'       => ['methods' => $post, 'path' => '/index/disableExtension', 'confirm' => true],
            'disable_external_call'   => ['methods' => $post, 'path' => '/index/disableExternalCall', 'confirm' => true],
            'send_welcome_email'      => ['methods' => $post, 'path' => '/index/sendWelcomeEmail', 'confirm' => true],
            'change_vm_path'          => ['methods' => $post, 'path' => '/index/changeVmPath', 'confirm' => false],
            'change_recording_path'   => ['methods' => $post, 'path' => '/index/changeRecordingPath', 'confirm' => false],
            // IVR and hotel
            'add_ivr'                 => ['methods' => $post, 'path' => '/index/addIvr', 'confirm' => false],
            'delete_ivr'              => ['methods' => $post, 'path' => '/index/deleteIvr', 'confirm' => true],
            'hotel_module'            => ['methods' => $post, 'path' => '/index/hotelModule', 'confirm' => false],
            // Schedules (own URL space, no "domain" in the body)
            'schedule_read'           => ['methods' => ['GET'], 'path' => '/scheduleApi/v1/schedules', 'confirm' => false, 'id' => true],
            'schedule_write'          => ['methods' => ['POST', 'PATCH'], 'path' => '/scheduleApi/v1/schedules', 'confirm' => false, 'id' => true],
            'schedule_delete'         => ['methods' => ['DELETE'], 'path' => '/scheduleApi/v1/schedules', 'confirm' => true, 'id' => true],
        ];
    }

    /**
     * Sign and send one request to this server's own API listener, then describe the answer.
     *
     * @return array{http_status: int, duration_ms: int, body: string}
     * @throws \RuntimeException when the API could not be reached
     */
    protected function apiPlaygroundForward(string $domain, string $apiKey, string $secret, string $method, string $pathAndQuery, string $rawBody): array
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $apiKey . $timestamp . $nonce . $rawBody, $secret);

        // The call APIs listen on 8443; the Schedule API is published on the standard HTTPS port
        // (nginx maps /scheduleApi/v1/ to the backend), so it is reached on 443 without a port.
        $port = strpos($pathAndQuery, '/scheduleApi/') === 0 ? 443 : 8443;
        $ch = curl_init('https://' . $domain . ($port === 443 ? '' : ':' . $port) . $pathAndQuery);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => false,
            // Reach our own listener without DNS or a proxy; the certificate is still checked
            // against the real host name.
            CURLOPT_RESOLVE        => [$domain . ':' . $port . ':127.0.0.1'],
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-API-KEY: ' . $apiKey,
                'X-TIMESTAMP: ' . $timestamp,
                'X-NONCE: ' . $nonce,
                'X-SIGNATURE: ' . $signature,
            ],
        ];
        if ($rawBody !== '') {
            $options[CURLOPT_POSTFIELDS] = $rawBody;
        }
        curl_setopt_array($ch, $options);

        $started = microtime(true);
        $response = curl_exec($ch);
        $elapsed = (int)round((microtime(true) - $started) * 1000);
        if ($response === false) {
            $message = curl_error($ch);
            curl_close($ch);
            error_log('api playground: ' . $message);
            throw new \RuntimeException('Could not reach the API on this server.');
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'http_status' => $code,
            'duration_ms' => $elapsed,
            'body'        => substr((string)$response, 0, 262144),
        ];
    }
}
