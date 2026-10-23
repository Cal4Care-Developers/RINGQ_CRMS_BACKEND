<?php
declare(strict_types=1);

use Phalcon\Http\Response;
use Phalcon\Mvc\Controller;

class FaxController extends ControllerBase
{
    /*
    |--------------------------------------------------------------------------
    | Configuration
    |--------------------------------------------------------------------------
    |
    | Everything lives in THIS controller -- no separate worker file, no
    | cron, no exec()/spawned process, no systemd unit to manage.
    |
    | HOW sendAction() responds fast AND still does the real work:
    |
    | It validates the request, saves the PDF, inserts a 'queued' row, and
    | then calls respondNowAndContinue() which sends the HTTP response to
    | the browser via fastcgi_finish_request(). That function flushes the
    | response and closes the connection to the client -- but the PHP-FPM
    | worker process itself keeps running afterward. The code AFTER that
    | call (gateway lookup, PDF->TIFF, ESL connect, originate, waiting for
    | the result, updating status) executes in that same still-running
    | process, invisibly to the client, who already has their job_id and
    | moved on.
    |
    | REQUIREMENT: fastcgi_finish_request() only exists under PHP-FPM. If
    | you're running mod_php/Apache prefork instead, see the fallback note
    | in respondNowAndContinue() -- it still works but less cleanly.
    |
    | TRADE-OFF worth knowing: this ties up one PHP-FPM worker for the
    | full duration of each in-flight fax (until sent/failed/timeout,
    | up to $faxTimeout seconds). If you send many faxes concurrently,
    | make sure pm.max_children in your php-fpm pool is large enough, or
    | give this endpoint its own dedicated FPM pool. A real background
    | worker/queue doesn't have this limitation, but this keeps everything
    | in one file inside the app as requested.
    |
    | FIXES IN THIS VERSION:
    |
    | 1) readEslMessage() now parses the event BODY (text/event-plain) for
    |    Key: Value lines and merges them into $headers. Previously only
    |    the outer envelope (Content-Type/Content-Length) was parsed, so
    |    Event-Name, Job-UUID, Unique-ID, variable_fax_job_id,
    |    variable_fax_success, etc. were never actually read -- every event
    |    matched $eventName === '' and waitForFaxResult() always fell
    |    through to the 120s timeout ("Timed out waiting for FreeSWITCH fax
    |    result"), even when FreeSWITCH was working correctly.
    |
    | 2) Added verifyGatewayExists() -- a fast ESL "sofia status gateway
    |    <name>" pre-flight check run right after connecting, before
    |    subscribing to events or originating. If the gateway name stored
    |    in v_gateways doesn't match a gateway FreeSWITCH actually has
    |    loaded, this fails immediately with a clear, specific message
    |    (naming the exact gateway string it tried) instead of surfacing
    |    only as a generic "-ERR INVALID_GATEWAY" after a full ESL round
    |    trip.
    |
    */

    private string $outboundPdfDir;
    private string $inboxPdfDir;
    private string $outboundTiffDir;
    private string $faxResolution;
    private bool $faxEnableT38;
    private int $faxTimeout = 120; // seconds; dial+ring+answer+T.30 handshake+transfer
    private int $maxPdfSize = 26214400; // 25 MB

    /*
    |--------------------------------------------------------------------------
    | Initialize
    |--------------------------------------------------------------------------
    */

    public function initialize(): void
    {
        parent::initialize();

        $this->outboundPdfDir  = getenv('OUTBOUND_PDF_DIR') ?: '/var/spool/fax/outbound_pdf';
        $this->inboxPdfDir     = getenv('INBOX_PDF_DIR') ?: '/var/spool/fax/inbox';
        $this->outboundTiffDir = getenv('OUTBOUND_TIFF_DIR') ?: '/var/spool/fax/outbound_tiff';
        $this->faxResolution   = getenv('FAX_RESOLUTION') ?: '204x196';
        $this->faxEnableT38    = strtolower(getenv('FAX_ENABLE_T38') ?: 'false') === 'true';

        $envTimeout = getenv('FAX_TIMEOUT');
        if ($envTimeout !== false && ctype_digit(trim($envTimeout)) && (int) $envTimeout > 0) {
            $this->faxTimeout = (int) $envTimeout;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | POST /api/fax/send
    |--------------------------------------------------------------------------
    |
    | Validates + saves PDF + queues the job + responds to the client
    | immediately, THEN (same process, after the client already has its
    | response) does the actual TIFF conversion + FreeSWITCH origination +
    | waits for the result + updates job status. Client polls
    | GET /api/fax/jobs/{job_id} to see status change from queued -> dialing
    | -> sending -> sent/failed.
    |
    */

    public function sendAction()
    {
        $jobId = null;
        $pdfPath = null;
        $domainUuid = null;

        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse([
                    "status"  => 401,
                    "message" => "Unauthorized",
                    "data"    => ""
                ]);
            }

            $domainUuid = $auth['domain_uuid'];
            $userUuid   = $auth['user_uuid'];

            $toNumber = trim((string) $this->request->getPost('to_number', 'string', ''));
            $fromNumber = trim((string) $this->request->getPost('from_number', 'string', ''));

            if ($toNumber === '') {
                return $this->jsonResponse(["status" => 400, "message" => "to_number is required", "data" => ""]);
            }

            if ($fromNumber === '') {
                return $this->jsonResponse(["status" => 400, "message" => "from_number is required", "data" => ""]);
            }

            if (!$this->isValidPhoneNumber($toNumber)) {
                return $this->jsonResponse(["status" => 400, "message" => "Invalid destination number", "data" => ""]);
            }

            if (!$this->isValidPhoneNumber($fromNumber)) {
                return $this->jsonResponse(["status" => 400, "message" => "Invalid source number", "data" => ""]);
            }

            $file = $this->getUploadedPdf();

            if (!$file) {
                return $this->jsonResponse(["status" => 400, "message" => "Attach a PDF file", "data" => ""]);
            }

            $this->validatePdf($file);

            // Fast existence/enabled check so bad requests fail immediately
            // instead of waiting for the background phase to discover it.
            $did = $this->getDidGateway($domainUuid, $fromNumber);

            if (!$did || strtolower((string) $did['enabled']) !== 'true') {
                return $this->jsonResponse([
                    "status"  => 400,
                    "message" => $fromNumber . " isn't a valid number for this account, or has no working gateway assigned.",
                    "data"    => ""
                ]);
            }

            $jobId = $this->generateUuid();

            $this->ensureDirectory($this->outboundPdfDir);

            $pdfPath = rtrim($this->outboundPdfDir, '/') . '/' . $jobId . '.pdf';
            $this->saveUploadedFile($file, $pdfPath);

            $this->createFaxJob($jobId, $domainUuid, $userUuid, 'out', $fromNumber, $toNumber, 'queued', $pdfPath);

            // ---- Everything below this line, the client never waits for. ----
            // respondNowAndContinue() sends the response now and closes the
            // client connection. Execution then CONTINUES past this call, in
            // the same PHP process, to do the real fax work.
            $this->respondNowAndContinue([
                "status"  => 200,
                "message" => "Fax queued",
                "data"    => ['job_id' => $jobId, 'status' => 'queued']
            ]);

            // ---- Client already has its response. This runs in the background. ----
            $this->processFaxJob($jobId, $domainUuid, $fromNumber, $toNumber, $pdfPath);

            return; // response was already sent above; nothing left to return

        } catch (Throwable $e) {

            error_log("sendAction error: " . $e->getMessage());

            if ($jobId) {
                try {
                    $this->updateFaxJob($jobId, $domainUuid, ['status' => 'failed', 'error_reason' => ControllerBase::safeErrorMessage($e)]);
                } catch (Throwable $ignore) {
                }
            }

            // If we already sent the response via respondNowAndContinue()
            // before this exception happened, we can't send another one --
            // the job's error_reason above is the only place this surfaces.
            if (!headers_sent()) {
                return $this->jsonResponse(["status" => 500, "message" => ControllerBase::safeErrorMessage($e), "data" => ""]);
            }

            return;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Respond to the client now, then keep the process running
    |--------------------------------------------------------------------------
    */

    private function respondNowAndContinue(array $data): void
    {
        ignore_user_abort(true);
        set_time_limit($this->faxTimeout + 30);

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->response->setStatusCode(200);
        $this->response->setContentType('application/json', 'UTF-8');
        $this->response->setHeader('Content-Length', (string) strlen($json));
        $this->response->setContent($json);
        $this->response->sendHeaders();

        echo $json;

        // Prevent Phalcon's view layer from trying to render/output anything
        // else after we've already flushed a response by hand.
        if ($this->view) {
            $this->view->disable();
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        // Fallback for non-PHP-FPM setups (mod_php, etc). Less clean --
        // the connection may not actually close from the client's side
        // until the script fully ends, depending on your webserver/proxy
        // buffering, but this at least flushes what we can.
        error_log('fastcgi_finish_request() not available -- client response may not return until fax processing completes. Consider PHP-FPM for this endpoint.');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    /*
    |--------------------------------------------------------------------------
    | The actual fax work -- runs AFTER the client already has its response
    |--------------------------------------------------------------------------
    */

    private function processFaxJob(string $jobId, string $domainUuid, string $fromNumber, string $toNumber, string $pdfPath): void
    {
        $socket = null;

        try {

            $this->updateFaxJob($jobId, $domainUuid, ['status' => 'dialing']);

            $did = $this->getDidGateway($domainUuid, $fromNumber);

            if (!$did || strtolower((string) $did['enabled']) !== 'true' || empty($did['gateway'])) {
                $this->updateFaxJob($jobId, $domainUuid, [
                    'status' => 'failed',
                    'error_reason' => 'No valid/enabled gateway for from_number',
                ]);
                return;
            }

            $gatewayName = $did['gateway_uuid']; // NAME, not UUID -- required for sofia/gateway/<name>/<dest>

            $this->ensureDirectory($this->outboundTiffDir);
            $tiffPath = rtrim($this->outboundTiffDir, '/') . '/' . $jobId . '.tiff';
            $this->pdfToTiff($pdfPath, $tiffPath);
            $this->updateFaxJob($jobId, $domainUuid, ['file_path' => $tiffPath]);

            $settings = $this->getDomainSettings($domainUuid);
            $companyName = $this->getCompanyName($domainUuid);
            $faxIdent = !empty($settings['fax_ident']) ? $settings['fax_ident'] : ($companyName ?: 'Fax');
            $faxHeader = !empty($settings['fax_header']) ? $settings['fax_header'] : ($companyName ?: 'Fax');

            $socket = $this->connectFreeSwitch();

            // Fail fast with a clear, specific message if the gateway isn't
            // actually loaded in FreeSWITCH, instead of discovering this
            // only after subscribing to events and originating (which used
            // to surface as an opaque "-ERR INVALID_GATEWAY").
            $this->verifyGatewayExists($socket, $gatewayName);

            $subscribeResponse = $this->sendEslCommand($socket, 'event plain CHANNEL_ANSWER CHANNEL_HANGUP_COMPLETE BACKGROUND_JOB');
            if (stripos($subscribeResponse['raw_headers'] ?? '', '+OK') === false) {
                throw new \RuntimeException('Failed to subscribe to RingQ SWITCH events: ' . trim($subscribeResponse['raw_headers'] ?? ''));
            }

            $originationVars = [
                'origination_caller_id_number=' . $this->fsEscapeValue($fromNumber),
                'fax_job_id=' . $jobId,
                'fax_direction=out',
                'fax_enable_t38=' . ($this->faxEnableT38 ? 'true' : 'false'),
                'fax_enable_ecm=true',
                'fax_verbose=true',
                'fax_ident=' . $this->fsEscapeValue($faxIdent),
                'fax_header=' . $this->fsEscapeValue($faxHeader),
                'ignore_early_media=true',
            ];

            $dialString = '{' . implode(',', $originationVars) . '}sofia/gateway/' . $gatewayName . '/' . $toNumber;
            $originateCommand = 'bgapi originate ' . $dialString . ' &txfax(' . $this->fsShellEscape($tiffPath) . ')';

            error_log("fax[{$jobId}]: originate: {$originateCommand}");

            $originateResponse = $this->sendEslCommand($socket, $originateCommand);
            error_log("fax[{$jobId}]: originate response: " . trim($originateResponse['raw_headers'] ?? ''));

            $eslJobUuid = $this->extractEslJobUuid($originateResponse);
            if (!$eslJobUuid) {
                error_log("fax[{$jobId}]: WARNING no ESL Job-UUID extracted from originate response");
            }

            $this->updateFaxJob($jobId, $domainUuid, ['status' => 'sending', 'esl_job_uuid' => $eslJobUuid]);

            $result = $this->waitForFaxResult($socket, $jobId, $eslJobUuid, $domainUuid);

            error_log("fax[{$jobId}]: final status=" . $result['status'] . ' pages=' . $result['pages'] . ' error=' . ($result['error'] ?? 'none'));

        } catch (Throwable $e) {

            error_log("processFaxJob[{$jobId}] error: " . $e->getMessage());

            try {
                $this->updateFaxJob($jobId, $domainUuid, ['status' => 'failed', 'error_reason' => ControllerBase::safeErrorMessage($e)]);
            } catch (Throwable $ignore) {
            }

        } finally {
            if ($socket) {
                $this->closeFreeSwitch($socket);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/fax/jobs
    |--------------------------------------------------------------------------
    */

    // public function jobsListAction()
    // {
    //     try {

    //         $auth_token = $this->session->get("auth");
    //         $auth = $this->authenticateRequest($auth_token);

    //         if (!$auth) {
    //             return $this->jsonResponse([
    //                 "status" => 401,
    //                 "message" => "Unauthorized",
    //                 "data" => ""
    //             ]);
    //         }

    //         $domainUuid = $auth['domain_uuid'];

    //         $limitParam = $this->request->getQuery('limit', 'string');
    //         $limit = 50;
    //         $getAll = strtolower(trim($limitParam)) === 'all';

    //         if (!$getAll && $limitParam !== '') {
    //             $limit = (int) $limitParam;
    //             if ($limit < 1) {
    //                 $limit = 50;
    //             }
    //         }

    //         $sql = "SELECT
    //                     id,
    //                     domain_uuid,
    //                     direction,
    //                     from_number,
    //                     to_number,
    //                     pages,
    //                     status,
    //                     file_path,
    //                     call_uuid,
    //                     esl_job_uuid,
    //                     error_reason,
    //                     created_at,
    //                     updated_at
    //                 FROM fax_jobs
    //                 WHERE domain_uuid = :uuid
    //                 ORDER BY created_at DESC";

    //         if (!$getAll) {
    //             $sql .= " LIMIT " . (int) $limit;
    //         }

    //         $rows = $this->db->fetchAll(
    //             $sql,
    //             \Phalcon\Db\Enum::FETCH_ASSOC,
    //             ["uuid" => $domainUuid]
    //         );

    //         if (empty($rows)) {
    //             return $this->jsonResponse([
    //                 "status" => 200,
    //                 "count" => 0,
    //                 "message" => "no data found",
    //                 "data" => []
    //             ]);
    //         }

    //         return $this->jsonResponse([
    //             "status" => 200,
    //             "count" => count($rows),
    //             "message" => "success",
    //             "data" => $rows
    //         ]);

    //     } catch (Throwable $e) {
    //         error_log("jobsListAction error: " . $e->getMessage());
    //         return $this->jsonResponse([
    //             "status" => 500,
    //             "message" => "Unable to load fax jobs",
    //             "data" => ""
    //         ]);
    //     }
    // }

    public function jobsListAction()
{
    try {

        $auth_token = $this->session->get("auth");
        $auth = $this->authenticateRequest($auth_token);

        if (!$auth) {
            return $this->jsonResponse([
                "status" => 401,
                "message" => "Unauthorized",
                "data" => ""
            ]);
        }

        $domainUuid = $auth['domain_uuid'];
        $userUuid   = $auth['user_uuid']; // <-- confirm this is the actual key your auth array uses

        $limitParam = $this->request->getQuery('limit', 'string');
        $limit = 50;
        $getAll = strtolower(trim($limitParam)) === 'all';

        if (!$getAll && $limitParam !== '') {
            $limit = (int) $limitParam;
            if ($limit < 1) {
                $limit = 50;
            }
        }

        // Direction-aware join: inbound faxes are matched by to_number
        // (the DID that was called), outbound faxes by from_number (the
        // DID used to send). Both resolve through the same
        // q_dids -> v_extensions -> v_extension_users chain used for the
        // fax-received email feature, so only jobs whose DID belongs to
        // an extension assigned to the logged-in user come back.
        $sql = "SELECT
                    fj.id,
                    fj.domain_uuid,
                    fj.direction,
                    fj.from_number,
                    fj.to_number,
                    fj.pages,
                    fj.status,
                    fj.file_path,
                    fj.call_uuid,
                    fj.esl_job_uuid,
                    fj.error_reason,
                    fj.created_at,
                    fj.updated_at
                FROM fax_jobs fj
                JOIN q_dids d
                    ON d.domain_uuid = fj.domain_uuid
                   AND d.did_number = CASE
                                        WHEN fj.direction = 'in' THEN fj.to_number
                                        ELSE fj.from_number
                                      END
                JOIN v_extensions ve
                    ON ve.domain_uuid = fj.domain_uuid
                   AND ve.extension = d.extension
                JOIN v_extension_users veu
                    ON veu.extension_uuid = ve.extension_uuid
                WHERE fj.domain_uuid = :uuid
                  AND veu.user_uuid = :user_uuid
                ORDER BY fj.created_at DESC";

        if (!$getAll) {
            $sql .= " LIMIT " . (int) $limit;
        }

        $rows = $this->db->fetchAll(
            $sql,
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["uuid" => $domainUuid, "user_uuid" => $userUuid]
        );

        if (empty($rows)) {
            return $this->jsonResponse([
                "status" => 200,
                "count" => 0,
                "message" => "no data found",
                "data" => []
            ]);
        }

        return $this->jsonResponse([
            "status" => 200,
            "count" => count($rows),
            "message" => "success",
            "data" => $rows
        ]);

    } catch (Throwable $e) {
        error_log("jobsListAction error: " . $e->getMessage());
        return $this->jsonResponse([
            "status" => 500,
            "message" => "Unable to load fax jobs",
            "data" => ""
        ]);
    }
}

    /*
    |--------------------------------------------------------------------------
    | GET /api/fax/jobs/{jobId}
    |--------------------------------------------------------------------------
    */

    public function statusAction(string $jobId)
    {
        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];

            if (!$this->isValidUuid($jobId)) {
                return $this->jsonResponse(["status" => 400, "message" => "Invalid job ID", "data" => ""]);
            }

            $job = $this->getFaxJob($jobId, $domainUuid, (string) ($auth["user_uuid"] ?? ""));

            if (!$job) {
                return $this->jsonResponse(["status" => 404, "message" => "Job not found", "data" => ""]);
            }

            unset($job['file_path'], $job['esl_job_uuid']);

            return $this->jsonResponse(["status" => 200, "message" => "Fax job", "data" => $job]);

        } catch (Throwable $e) {
            error_log("statusAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to get fax status", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/fax/inbox/{jobId}/file
    |--------------------------------------------------------------------------
    */

    public function downloadAction(string $jobId)
    {
        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];

            if (!$this->isValidUuid($jobId)) {
                return $this->jsonResponse(["status" => 400, "message" => "Invalid job ID", "data" => ""]);
            }

            $job = $this->getFaxJob($jobId, $domainUuid, (string) ($auth["user_uuid"] ?? ""));

            if (!$job || $job['direction'] !== 'in' || $job['status'] !== 'received') {
                return $this->jsonResponse(["status" => 404, "message" => "File not available", "data" => ""]);
            }

            $filePath = $job['file_path'];

            if (!$filePath || !is_file($filePath)) {
                return $this->jsonResponse(["status" => 404, "message" => "File not found", "data" => ""]);
            }

            $realFile = realpath($filePath);
            $realBase = realpath($this->inboxPdfDir);

            if (!$realFile || !$realBase || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
                return $this->jsonResponse(["status" => 403, "message" => "Invalid file location", "data" => ""]);
            }

            return $this->response
                ->setStatusCode(200)
                ->setHeader('Content-Type', 'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $jobId . '.pdf"')
                ->setContent(file_get_contents($realFile));

        } catch (Throwable $e) {
            error_log("downloadAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to download fax", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/fax/gateways/status
    |--------------------------------------------------------------------------
    |
    | Live view of every gateway FreeSWITCH currently has loaded (name,
    | profile, state e.g. REGED/FAIL_WAIT/NOREG), cross-referenced against
    | which of THIS domain's DIDs point at each one. This exists so a
    | DID -> gateway mismatch (wrong name, unprovisioned gateway, a trunk
    | that fell into FAIL_WAIT) can be diagnosed and fixed from the app,
    | without shelling into the FreeSWITCH box to run `fs_cli -x "sofia
    | status gateway"` by hand.
    |
    */

    public function gatewaysStatusAction()
    {
        $socket = null;

        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];

            $socket = $this->connectFreeSwitch();
            $response = $this->sendEslCommand($socket, 'api sofia status gateway');
            $liveGateways = $this->parseGatewayStatusTable($response['body'] ?? '');

            // This domain's DID -> gateway assignments, so the UI can show
            // "this DID uses gateway X, which is currently REGED/FAIL_WAIT/
            // missing" side by side with the live list above.
            $assignments = $this->db->fetchAll(
                "SELECT d.did_number, d.name, d.sip_trunk_name AS gateway
                 FROM q_dids d
                 WHERE d.domain_uuid = ?::uuid
                 ORDER BY d.did_number",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid]
            );

            $liveByName = [];
            foreach ($liveGateways as $gw) {
                $liveByName[$gw['name']] = $gw;
            }

            foreach ($assignments as &$row) {
                $row['live_state'] = $liveByName[$row['gateway']]['state'] ?? 'NOT_LOADED';
                $row['profile'] = $liveByName[$row['gateway']]['profile'] ?? null;
            }
            unset($row);

            return $this->jsonResponse([
                "status"  => 200,
                "message" => "Gateway status",
                "data"    => [
                    'gateways'       => $liveGateways,
                    'did_assignments' => $assignments
                ]
            ]);

        } catch (Throwable $e) {
            error_log("gatewaysStatusAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => ControllerBase::safeErrorMessage($e), "data" => ""]);
        } finally {
            if ($socket) {
                $this->closeFreeSwitch($socket);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PUT /api/fax/dids/{didNumber}/gateway
    |--------------------------------------------------------------------------
    |
    | Re-point a DID at a different gateway name -- e.g. after
    | gatewaysStatusAction() shows the current assignment is NOT_LOADED or
    | FAIL_WAIT, swap it for one that's REGED. Verifies the target gateway
    | actually exists in FreeSWITCH before saving, so this can't create a
    | new instance of the exact "faxgateway doesn't exist" problem.
    |
    */

    public function updateDidGatewayAction(string $didNumber)
    {
        $socket = null;

        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];

            $body = $this->request->getJsonRawBody();
            $gatewayName = isset($body->gateway) ? trim((string) $body->gateway) : '';

            if ($gatewayName === '') {
                return $this->jsonResponse(["status" => 400, "message" => "gateway is required", "data" => ""]);
            }

            $did = $this->db->fetchOne(
                "SELECT did_number FROM q_dids WHERE domain_uuid = ?::uuid AND did_number = ? LIMIT 1",
                \Phalcon\Db\Enum::FETCH_ASSOC,
                [$domainUuid, $didNumber]
            );

            if (!$did) {
                return $this->jsonResponse(["status" => 404, "message" => "DID not found", "data" => ""]);
            }

            $socket = $this->connectFreeSwitch();
            $this->verifyGatewayExists($socket, $gatewayName);

            $this->db->execute(
                "UPDATE q_dids SET sip_trunk_name = ? WHERE domain_uuid = ?::uuid AND did_number = ?",
                [$gatewayName, $domainUuid, $didNumber]
            );

            return $this->jsonResponse([
                "status"  => 200,
                "message" => "DID gateway updated",
                "data"    => ['did_number' => $didNumber, 'gateway' => $gatewayName]
            ]);

        } catch (Throwable $e) {
            error_log("updateDidGatewayAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => ControllerBase::safeErrorMessage($e), "data" => ""]);
        } finally {
            if ($socket) {
                $this->closeFreeSwitch($socket);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/fax/dids
    |--------------------------------------------------------------------------
    */

    public function didsAction()
    {
        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];
            $dids = $this->listDomainDids($domainUuid);

            return $this->jsonResponse(["status" => 200, "message" => "Fax numbers", "data" => $dids]);

        } catch (Throwable $e) {
            error_log("didsAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to load fax numbers", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GET /api/fax/settings
    |--------------------------------------------------------------------------
    */

    public function settingsAction()
    {
        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];
            $settings = $this->getDomainSettings($domainUuid);
            $companyName = $this->getCompanyName($domainUuid);

            return $this->jsonResponse([
                "status"  => 200,
                "message" => "Fax settings",
                "data"    => [
                    'fax_ident'    => $settings['fax_ident'] ?? null,
                    'fax_header'   => $settings['fax_header'] ?? null,
                    'company_name' => $companyName
                ]
            ]);

        } catch (Throwable $e) {
            error_log("settingsAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to load fax settings", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PUT /api/fax/settings
    |--------------------------------------------------------------------------
    */

    public function updateSettingsAction()
    {
        try {

            $auth_token = $this->session->get("auth");
            $auth = $this->authenticateRequest($auth_token);

            if (!$auth) {
                return $this->jsonResponse(["status" => 401, "message" => "Unauthorized", "data" => ""]);
            }

            $domainUuid = $auth['domain_uuid'];
            $body = $this->request->getJsonRawBody();

            $faxIdent = isset($body->fax_ident) ? trim((string) $body->fax_ident) : null;
            $faxHeader = isset($body->fax_header) ? trim((string) $body->fax_header) : null;

            if ($faxIdent !== null && strlen($faxIdent) > 255) {
                return $this->jsonResponse(["status" => 400, "message" => "fax_ident is too long", "data" => ""]);
            }

            if ($faxHeader !== null && strlen($faxHeader) > 255) {
                return $this->jsonResponse(["status" => 400, "message" => "fax_header is too long", "data" => ""]);
            }

            $this->upsertDomainSettings($domainUuid, $faxIdent, $faxHeader);

            return $this->jsonResponse(["status" => 200, "message" => "Fax settings saved", "data" => ['saved' => true]]);

        } catch (Throwable $e) {
            error_log("updateSettingsAction error: " . $e->getMessage());
            return $this->jsonResponse(["status" => 500, "message" => "Unable to save fax settings", "data" => ""]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    private function authenticateRequest($auths): ?array
    {
        $userUuid = $auths['user_uuid'] ?? null;

        if (!$userUuid) {
            return null;
        }

        $rowss = $this->db->fetchOne(
            "SELECT access_token FROM q_tokens WHERE user_uuid = :user_uuid LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            ["user_uuid" => $userUuid]
        );

        if (!$rowss || empty($rowss['access_token'])) {
            return null;
        }

        $token = $rowss['access_token'];

        if (!$token) {
            return null;
        }

        $row = $this->db->fetchOne(
            "SELECT user_uuid, domain_uuid FROM q_tokens WHERE access_token = ? LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$token]
        );

        if (!$row || empty($row['domain_uuid'])) {
            return null;
        }

        return [
            'user_uuid' => $row['user_uuid'],
            'domain_uuid' => $row['domain_uuid']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | DID / Gateway
    |--------------------------------------------------------------------------
    */

    private function getDidGateway(string $domainUuid, string $didNumber): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT g.gateway, g.gateway_uuid, g.enabled
             FROM q_dids d
             JOIN v_gateways g
                 ON g.gateway = d.sip_trunk_name
                AND g.domain_uuid = d.domain_uuid
             WHERE d.domain_uuid = ?::uuid
               AND d.did_number = ?
             LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domainUuid, $didNumber]
        );

        return $row ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | List DIDs
    |--------------------------------------------------------------------------
    */

    private function listDomainDids(string $domainUuid): array
    {
        return $this->db->fetchAll(
            "SELECT d.did_number, d.name, d.sip_trunk_name
             FROM q_dids d
             JOIN v_gateways g
                 ON g.gateway = d.sip_trunk_name
                AND g.domain_uuid = d.domain_uuid
             WHERE d.domain_uuid = ?::uuid
               AND g.enabled = 'true'
             ORDER BY d.did_number",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domainUuid]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Company
    |--------------------------------------------------------------------------
    */

    private function getCompanyName(string $domainUuid): ?string
    {
        $row = $this->db->fetchOne(
            "SELECT company_name FROM q_companies WHERE domain_uuid = ?::uuid LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domainUuid]
        );

        return $row ? $row['company_name'] : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Settings
    |--------------------------------------------------------------------------
    */

    private function getDomainSettings(string $domainUuid): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT domain_uuid, fax_ident, fax_header, updated_at
             FROM fax_domain_settings
             WHERE domain_uuid = ?::uuid
             LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$domainUuid]
        );

        return $row ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | Update / Insert Domain Settings
    |--------------------------------------------------------------------------
    */

    private function upsertDomainSettings(string $domainUuid, ?string $faxIdent, ?string $faxHeader): void
    {
        $this->db->execute(
            "INSERT INTO fax_domain_settings (domain_uuid, fax_ident, fax_header, updated_at)
             VALUES (?::uuid, ?, ?, EXTRACT(EPOCH FROM NOW()))
             ON CONFLICT (domain_uuid)
             DO UPDATE SET
                 fax_ident  = EXCLUDED.fax_ident,
                 fax_header = EXCLUDED.fax_header,
                 updated_at = EXCLUDED.updated_at",
            [$domainUuid, $faxIdent, $faxHeader]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create Fax Job
    |--------------------------------------------------------------------------
    */

    private function createFaxJob(
        string $jobId,
        string $domainUuid,
        string $userUuid,
        string $direction,
        string $fromNumber,
        string $toNumber,
        string $status,
        string $filePath
    ): void {

        try {

            $this->db->execute(
                "INSERT INTO fax_jobs
                 (id, domain_uuid, user_uuid, direction, from_number, to_number, pages, status, file_path, created_at, updated_at)
                 VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, 0, ?, ?, EXTRACT(EPOCH FROM NOW()), EXTRACT(EPOCH FROM NOW()))",
                [$jobId, $domainUuid, $userUuid, $direction, $fromNumber, $toNumber, $status, $filePath]
            );

        } catch (Throwable $e) {

            // Compatibility fallback if user_uuid column doesn't exist yet.
            $this->db->execute(
                "INSERT INTO fax_jobs
                 (id, domain_uuid, direction, from_number, to_number, pages, status, file_path, created_at, updated_at)
                 VALUES (?::uuid, ?::uuid, ?, ?, ?, 0, ?, ?, EXTRACT(EPOCH FROM NOW()), EXTRACT(EPOCH FROM NOW()))",
                [$jobId, $domainUuid, $direction, $fromNumber, $toNumber, $status, $filePath]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Fax Job
    |--------------------------------------------------------------------------
    */

    private function getFaxJob(string $jobId, string $domainUuid, string $userUuid): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT fj.* FROM fax_jobs fj
                JOIN q_dids d
                    ON d.domain_uuid = fj.domain_uuid
                   AND d.did_number = CASE
                                        WHEN fj.direction = 'in' THEN fj.to_number
                                        ELSE fj.from_number
                                      END
                JOIN v_extensions ve
                    ON ve.domain_uuid = fj.domain_uuid
                   AND ve.extension = d.extension
                JOIN v_extension_users veu
                    ON veu.extension_uuid = ve.extension_uuid
                WHERE fj.id = ?::uuid AND fj.domain_uuid = ?::uuid AND veu.user_uuid = ?::uuid
                LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC,
            [$jobId, $domainUuid, $userUuid]
        );

        return $row ?: null;
    }

    /*
    |--------------------------------------------------------------------------
    | Update Fax Job
    |--------------------------------------------------------------------------
    */

    private function updateFaxJob(string $jobId, ?string $domainUuid, array $fields): void
    {
        if (!$fields) {
            return;
        }

        $allowed = ['status', 'file_path', 'call_uuid', 'esl_job_uuid', 'pages', 'error_reason'];

        $sets = [];
        $params = [];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }

        if (!$sets) {
            return;
        }

        $sets[] = 'updated_at = EXTRACT(EPOCH FROM NOW())';

        $sql = 'UPDATE fax_jobs SET ' . implode(', ', $sets) . ' WHERE id = ?::uuid';
        $params[] = $jobId;

        if ($domainUuid !== null) {
            $sql .= ' AND domain_uuid = ?::uuid';
            $params[] = $domainUuid;
        }

        $this->db->execute($sql, $params);
    }

    /*
    |--------------------------------------------------------------------------
    | PDF validation
    |--------------------------------------------------------------------------
    */

    private function validatePdf($file): void
    {
        if (!$file || !$file->isUploadedFile()) {
            throw new \RuntimeException('Invalid uploaded file');
        }

        if ($file->getSize() <= 0) {
            throw new \RuntimeException('Uploaded file is empty');
        }

        if ($file->getSize() > $this->maxPdfSize) {
            throw new \RuntimeException('Maximum PDF size is 25 MB');
        }

        $extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));

        if ($extension !== 'pdf') {
            throw new \RuntimeException('Only PDF files are allowed');
        }

        $mime = $file->getRealType();

        if ($mime !== 'application/pdf') {
            throw new \RuntimeException('Invalid PDF MIME type');
        }

        $handle = fopen($file->getTempName(), 'rb');

        if (!$handle) {
            throw new \RuntimeException('Unable to inspect PDF');
        }

        $header = fread($handle, 5);
        fclose($handle);

        if ($header !== '%PDF-') {
            throw new \RuntimeException('Invalid PDF file');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Uploaded PDF
    |--------------------------------------------------------------------------
    */

    private function getUploadedPdf()
    {
        $files = $this->request->getUploadedFiles();

        foreach ($files as $file) {
            if ($file->getKey() === 'file') {
                return $file;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Save uploaded file
    |--------------------------------------------------------------------------
    */

    private function saveUploadedFile($file, string $destination): void
    {
        if (!is_writable(dirname($destination))) {
            throw new \RuntimeException('Fax directory is not writable');
        }

        $file->moveTo($destination);

        if (!is_file($destination)) {
            throw new \RuntimeException('Unable to save PDF file');
        }

        @chmod($destination, 0640);
    }

    /*
    |--------------------------------------------------------------------------
    | Directory
    |--------------------------------------------------------------------------
    */

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create fax directory: ' . $directory);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | UUID
    |--------------------------------------------------------------------------
    */

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /*
    |--------------------------------------------------------------------------
    | UUID validation
    |--------------------------------------------------------------------------
    */

    private function isValidUuid(string $uuid): bool
    {
        return preg_match(
            '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/',
            $uuid
        ) === 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Phone number validation
    |--------------------------------------------------------------------------
    */

    private function isValidPhoneNumber(string $number): bool
    {
        return preg_match('/^[0-9+*#().\-\s]{3,40}$/', $number) === 1;
    }

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH ESL
    |--------------------------------------------------------------------------
    */

    private function connectFreeSwitch()
    {
        $credential = $this->db->fetchOne(
            "SELECT host, port, password FROM freeswitch_credentials WHERE status = TRUE ORDER BY id ASC LIMIT 1",
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        if (!$credential) {
            throw new \RuntimeException('No active RingQ SWITCH credentials configured');
        }

        $host = trim((string) $credential['host']) ?: '127.0.0.1';
        $port = (int) $credential['port'] ?: 8021;
        $password = (string) $credential['password'];

        if ($password === '') {
            throw new \RuntimeException('RingQ SWITCH password is not configured');
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);

        if (!$socket) {
            throw new \RuntimeException("Unable to connect to RingQ SWITCH ESL: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 5);

        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $response .= $line;
            if (rtrim($line, "\r\n") === '') break;
        }

        if (stripos($response, 'auth/request') === false) {
            fclose($socket);
            throw new \RuntimeException('Invalid RingQ SWITCH ESL response: ' . trim($response));
        }

        fwrite($socket, "auth {$password}\n\n");

        $authResponse = '';
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $authResponse .= $line;
            if (rtrim($line, "\r\n") === '') break;
        }

        if (stripos($authResponse, '+OK accepted') === false) {
            fclose($socket);
            throw new \RuntimeException('RingQ SWITCH ESL authentication failed: ' . trim($authResponse));
        }

        return $socket;
    }

    /*
    |--------------------------------------------------------------------------
    | Verify gateway exists in FreeSWITCH before dialing
    |--------------------------------------------------------------------------
    |
    | bgapi originate against a bad gateway name eventually comes back as
    | "-ERR INVALID_GATEWAY" via the BACKGROUND_JOB event, but that's
    | indistinguishable at a glance from other origination failures and
    | only surfaces after a full ESL round trip. Checking
    | "sofia status gateway <name>" first fails fast with the exact name
    | we tried, which is what you actually need in order to debug a
    | DB/FreeSWITCH gateway-name mismatch (case, whitespace, gateway not
    | provisioned/rescanned, wrong profile, etc).
    |
    */

    private function verifyGatewayExists($socket, string $gatewayName): void
    {
        $response = $this->sendEslCommand($socket, 'api sofia status gateway ' . $gatewayName);
        $body = trim($response['body'] ?? '');

        if ($body === '' || stripos($body, 'Invalid Gateway') !== false) {
            throw new \RuntimeException(
                "Gateway '{$gatewayName}' is not loaded in RingQ SWITCH (checked via status gateway {$gatewayName}'). " .
                "Check that v_gateways.gateway matches the name RingQ SWITCH has loaded exactly (case/whitespace), " .
                "and that the gateway's profile has been rescanned/reloaded."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Parse "sofia status gateway" table output
    |--------------------------------------------------------------------------
    |
    | Typical line looks like:
    |   external::9c0e7a46-919c-42db-a55e-6e96585ae914  sip:...  REGED  0.00  0/14  0/2
    | i.e. "<profile>::<name>" then whitespace-separated columns, ending
    | with a summary line ("N gateways: Inbound(...)"). We only need
    | profile/name/state for the diagnostic view, so this stays deliberately
    | tolerant of column drift across FreeSWITCH versions rather than
    | parsing every column strictly.
    |
    */

    private function parseGatewayStatusTable(string $body): array
    {
        $gateways = [];

        foreach (preg_split('/\r?\n/', $body) as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '::') === false) {
                continue; // skip separators, headers, and the trailing summary line
            }

            $parts = preg_split('/\s+/', $line);
            $profileAndName = $parts[0];

            [$profile, $name] = array_pad(explode('::', $profileAndName, 2), 2, null);

            if (!$name) {
                continue;
            }

            $state = null;
            foreach ($parts as $token) {
                if (in_array($token, ['REGED', 'FAIL_WAIT', 'FAILED', 'NOREG', 'TRYING', 'DOWN', 'UNREGED'], true)) {
                    $state = $token;
                    break;
                }
            }

            $gateways[] = [
                'profile' => $profile,
                'name'    => $name,
                'state'   => $state ?: 'UNKNOWN',
            ];
        }

        return $gateways;
    }

    private function sendEslCommand($socket, string $command): array
    {
        $this->writeEslCommand($socket, $command);
        return $this->readEslMessage($socket, 10);
    }

    private function writeEslCommand($socket, string $command): void
    {
        $data = $command . "\n\n";
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($socket, substr($data, $written));
            if ($result === false) {
                throw new \RuntimeException('Failed writing to RingQ SWITCH ESL');
            }
            $written += $result;
        }
    }

    private function readEslMessage($socket, int $timeout = 10): array
    {
        stream_set_timeout($socket, $timeout);

        $headers = [];
        $headerText = '';

        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;

            $line = rtrim($line, "\r\n");
            if ($line === '') break;

            $headerText .= $line . "\n";

            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }

        $body = '';
        $contentLength = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : 0;

        if ($contentLength > 0) {
            $remaining = $contentLength;
            while ($remaining > 0 && !feof($socket)) {
                $chunk = fread($socket, $remaining);
                if ($chunk === false || $chunk === '') break;
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
        }

        // For text/event-plain messages, the real event fields (Event-Name,
        // Job-UUID, Unique-ID, variable_fax_job_id, variable_fax_success,
        // variable_fax_result_text, variable_fax_document_transferred_pages,
        // ...) live INSIDE $body as their own "Key: Value" lines -- they are
        // NOT part of the outer envelope $headers parsed above (which for
        // an event-plain message only contains Content-Type/Content-Length).
        //
        // Without this, $headers['Event-Name'] etc. is always missing, every
        // check in waitForFaxResult() silently no-ops, and jobs incorrectly
        // fall through to the timeout branch even when FreeSWITCH is
        // reporting success/failure correctly. Parse and merge those fields
        // here so every existing $message['headers'][...] lookup downstream
        // keeps working unchanged.
        if (($headers['Content-Type'] ?? '') === 'text/event-plain' && $body !== '') {
            foreach (preg_split('/\r?\n/', $body) as $bodyLine) {
                if ($bodyLine === '' || strpos($bodyLine, ':') === false) {
                    continue; // skip blank lines / any nested raw body content
                }
                [$key, $value] = explode(':', $bodyLine, 2);
                $key = trim($key);
                if ($key !== '') {
                    $headers[$key] = urldecode(trim($value));
                }
            }
        }

        return ['headers' => $headers, 'body' => $body, 'raw_headers' => $headerText];
    }

    private function waitForFaxResult($socket, string $jobId, ?string $eslJobUuid, string $domainUuid): array
    {
        $started = time();
        $callUuid = null;
        $pages = 0;

        while ((time() - $started) < $this->faxTimeout) {

            $message = $this->readEslMessage($socket, 5);

            if (empty($message['headers'])) {
                continue;
            }

            $eventName = $message['headers']['Event-Name'] ?? '';

            if ($eventName !== '') {
                error_log("fax[{$jobId}]: event: {$eventName} | Job-UUID=" . ($message['headers']['Job-UUID'] ?? '') . " | fax_job_id=" . ($message['headers']['variable_fax_job_id'] ?? ''));
            }

            if ($eventName === 'BACKGROUND_JOB') {
                $eventJobUuid = $message['headers']['Job-UUID'] ?? '';

                if ($eslJobUuid && $eventJobUuid === $eslJobUuid) {
                    $result = $this->extractBackgroundResult($message);

                    if (strpos($result, '-ERR') === 0) {
                        $this->updateFaxJob($jobId, $domainUuid, ['status' => 'failed', 'error_reason' => $result]);
                        return ['status' => 'failed', 'pages' => 0, 'error' => $result];
                    }
                }
                continue;
            }

            if ($eventName === 'CHANNEL_ANSWER') {
                $eventJobId = $message['headers']['variable_fax_job_id'] ?? '';

                if ($eventJobId === $jobId) {
                    $callUuid = $message['headers']['Unique-ID'] ?? null;
                    $this->updateFaxJob($jobId, $domainUuid, ['status' => 'sending', 'call_uuid' => $callUuid]);
                }
                continue;
            }

            if ($eventName === 'CHANNEL_HANGUP_COMPLETE') {
                $eventJobId = $message['headers']['variable_fax_job_id'] ?? '';
                $eventCallUuid = $message['headers']['Unique-ID'] ?? '';

                if ($eventJobId !== $jobId && (!$callUuid || $eventCallUuid !== $callUuid)) {
                    continue;
                }

                $faxSuccess = ($message['headers']['variable_fax_success'] ?? '') === '1';
                $resultText = $message['headers']['variable_fax_result_text'] ?? '';
                $pages = (int) ($message['headers']['variable_fax_document_transferred_pages'] ?? 0);

                // pages reflects however many pages transmitted before the
                // call ended -- independent of overall success/failure. A
                // failed job can legitimately show pages > 0; error_reason
                // (fax_result_text) explains why it failed.

                if ($faxSuccess) {
                    $this->updateFaxJob($jobId, $domainUuid, [
                        'status' => 'sent', 'pages' => $pages, 'call_uuid' => $eventCallUuid, 'error_reason' => null
                    ]);
                    return ['status' => 'sent', 'pages' => $pages, 'error' => null];
                }

                $this->updateFaxJob($jobId, $domainUuid, [
                    'status' => 'failed', 'pages' => $pages, 'call_uuid' => $eventCallUuid, 'error_reason' => $resultText
                ]);
                return ['status' => 'failed', 'pages' => $pages, 'error' => $resultText];
            }
        }

        $this->updateFaxJob($jobId, $domainUuid, [
            'status' => 'failed', 'error_reason' => 'Timed out waiting for RingQ SWITCH fax result'
        ]);

        return ['status' => 'failed', 'pages' => $pages, 'error' => 'Fax operation timed out'];
    }

    private function extractBackgroundResult(array $message): string
    {
        $candidates = [$message['body'] ?? '', $message['raw_headers'] ?? ''];
        $candidates = array_merge($candidates, array_values($message['headers'] ?? []));

        foreach ($candidates as $value) {
            if (!$value) continue;
            foreach (preg_split('/\r?\n/', (string) $value) as $line) {
                $line = trim($line);
                if (strpos($line, '+OK') === 0 || strpos($line, '-ERR') === 0) {
                    return $line;
                }
            }
        }

        return '';
    }

    private function extractEslJobUuid(array $response): ?string
    {
        $text = ($response['body'] ?? '') . "\n" . ($response['raw_headers'] ?? '');
        if (preg_match('/Job-UUID:\s*([0-9a-fA-F-]{36})/', $text, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function fsEscapeValue(string $value): string
    {
        if (strpos($value, ' ') !== false || strpos($value, ',') !== false) {
            $value = str_replace("'", "\\'", $value);
            return "'" . $value . "'";
        }
        return $value;
    }

    private function fsShellEscape(string $value): string
    {
        return str_replace(['\\', '"', "'", "\r", "\n"], ['\\\\', '\\"', "\\'", '', ''], $value);
    }

    private function closeFreeSwitch($socket): void
    {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }

    private function pdfToTiff(string $pdfPath, string $tiffPath): void
    {
        if (!is_file($pdfPath)) {
            throw new \RuntimeException('PDF file not found');
        }

        $command = [
            'gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER',
            '-sDEVICE=tiffg4', '-r' . $this->faxResolution,
            '-sOutputFile=' . $tiffPath, $pdfPath
        ];

        $result = $this->runCommand($command);

        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException('PDF to TIFF conversion failed: ' . $result['stderr']);
        }

        if (!is_file($tiffPath) || filesize($tiffPath) <= 0) {
            throw new \RuntimeException('PDF to TIFF conversion produced no file');
        }

        @chmod($tiffPath, 0640);
    }

    private function runCommand(array $command): array
    {
        $escaped = array_map('escapeshellarg', array_map('strval', $command));
        $commandLine = implode(' ', $escaped);

        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($commandLine, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to execute conversion command');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => trim($stderr)];
    }

    /*
    |--------------------------------------------------------------------------
    | JSON response
    |--------------------------------------------------------------------------
    */

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->response;

        $response->setStatusCode($statusCode);
        $response->setContentType('application/json', 'UTF-8');

        $response->setContent(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response;
    }
}
