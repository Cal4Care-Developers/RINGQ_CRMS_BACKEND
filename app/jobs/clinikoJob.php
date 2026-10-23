<?php
/**
 * clinikoJob.php — the Cliniko integration.
 *
 * Consolidated out of worker.php: four job handlers, six helpers, the integration
 * probe, and the eight CLINIKO_* constants. MainTask::getPatientsAction stays
 * where it is — cron calls it by name from tasks.txt, and an action that stops
 * existing fails silently every minute.
 *
 * THE CONSTANTS BELOW ARE WHY THIS FILE MUST BE REQUIRED ABOVE THE DAEMON LOOP.
 * They were once declared BELOW `while (true)` in worker.php. Function
 * declarations hoist at compile time; a top-level `const` is a RUNTIME statement,
 * so execution never reached it, PHP evaluated the bare name as the string
 * "CLINIKO_JOB_MAX_AGE_SECONDS", `(int)` of that is 0, and every call-log job was
 * dropped about a second after being queued instead of waiting for its CDR.
 * Requiring this file after the loop would restore that bug exactly.
 *
 * Declares functions and constants only. No top-level statements: this is loaded
 * while the worker is still wiring itself up, before $redis exists.
 */

// `use` is FILE-SCOPED: the imports in worker.php and MainTask.php do NOT reach a
// required file. Without these lines the code below resolves e.g. `Column` to the
// global `\Column`, which does not exist, and every tick dies with
// "Class 'Column' not found" -- php -l cannot see it and loading the file cannot
// either, because it only fails when the line actually runs.
use Phalcon\Db\Column;

// How long a call-log job may keep retrying. The CDR lookups match only calls
// that ended within the last 10s, so this bounds how long we wait for a call
// still in progress to hang up and write its CDR. A job with no `queued_at`
// (older payload shape) gets one pass and is then dropped rather than looping.
const CLINIKO_JOB_MAX_AGE_SECONDS = 900; // 15 minutes

// How far a matching CDR's start_epoch may sit either side of the moment the job
// was queued.
//
// The old lookups matched on `end_epoch >= time() - 10`, a window anchored to NOW
// rather than to the job, so it slid out from under the job on every retry and a
// job only ever matched by landing in a ten-second slice by luck. Worse, with only
// a phone number to go on, two calls from the same number could satisfy each
// other's job.
//
// The screen pop fires when the agent's phone rings, so the agent leg starts at
// essentially the same instant the job is queued — measured 0-3s across every job
// in the worker log. LEAD is wide enough to also reach the QUEUE leg of the same
// call, which starts when the caller entered the queue and may have waited; LAG
// covers the pop winning the race with the CDR write. Candidates are ordered by
// proximity to queued_at, so a wide window still resolves to the nearest call.
const CLINIKO_CDR_MATCH_LEAD_SECONDS = 600; // call may have started before the pop
const CLINIKO_CDR_MATCH_LAG_SECONDS  = 120; // ...or shortly after it

// The call a job refers to was still RINGING when the job was queued, so its CDR
// must end at or after queued_at. A call that had already hung up before the pop
// fired is a DIFFERENT, earlier call.
//
// Without this, the start-window above (deliberately wide, to reach the queue leg
// of a call that waited) also reaches the previous call from the same number. The
// worker matched that already-journaled row, hit the `-prevcomm` duplicate guard,
// and RETURNED -- consuming the job, so the real call was never logged. Observed:
// of 5 consecutive test calls, the 2 placed within ~4 minutes of a journaled one
// were silently skipped.
//
// The grace is small on purpose. It lets a very short call whose CDR lands right
// as the pop fires still match, while staying well under the 14s gap that let the
// previous call in.
const CLINIKO_CDR_MATCH_END_GRACE_SECONDS = 5;

// Timezone used to render call times in the Cliniko communication when the tenant
// has not configured one. This is the value the integration has always used; it is
// deliberately NOT date_default_timezone_get(), which reads Asia/Shanghai on at
// least one live box and would silently move every existing tenant's timestamps.
const CLINIKO_DEFAULT_TIMEZONE = 'Australia/Sydney';

// How long the `cliniko-patient-created` handshake key stays readable.
//
// The screen pop (in the ionCube-ENCODED ApiController, so unreachable from here)
// queues a cliniko:newuser job and then polls this key for up to 10 seconds,
// reading it BEFORE its first sleep. The key was written with no expiry, so that
// first read returned whatever patient was created last -- possibly hours earlier,
// possibly since deleted in Cliniko -- and the pop opened a dead /patients/<id>
// URL instead of waiting for the patient it had just asked for. Observed live: the
// browser was handed 2019315578619047181, which Cliniko answers 404.
//
// A TTL is the only lever this side of the encoding: once the key expires the
// poll finds nothing, sleeps, retries, and picks up the id the worker actually
// creates for THIS call. It does not close the window entirely -- a creation
// inside the TTL can still be read by an unrelated pop -- but that id is then a
// genuinely recent one rather than a long-dead one. A per-number key would fix it
// properly and requires changing the encoded reader.
const CLINIKO_PATIENT_CREATED_TTL_SECONDS = 30;

// True when at least one enabled domain has a Cliniko integration
// configured. Uses direct EXISTS lookups on the integration hash keys
// (no keyspace scans) and caches the answer for 60s — integrations
// only change when an admin saves settings, and this runs in a ~1s
// poll loop.
function worker_hasClinikoIntegration($redis) {
    static $has = false, $checkedAt = 0;
    if (time() - $checkedAt < 60) {
        return $has;
    }
    $checkedAt = time();
    $has = false;
    $vDomains = VDomains::find([
        'columns' => "domain_name",
        'conditions' => "domain_enabled = true",
    ]);
    foreach ($vDomains as $vDomain) {
        if ($redis->exists("integration:{$vDomain->domain_name}-cliniko")) {
            $has = true;
            break;
        }
    }
    return $has;
}

/**
 * Normalise a PostgreSQL boolean as it may actually reach PHP.
 *
 * `missed_call` is a genuine `boolean` on this platform and PDO hands back a real
 * PHP bool. But this repository has recorded FusionPBX schema drift converting
 * boolean columns to text/numeric on other servers, where the value arrives as the
 * STRING 'f' -- and 'f' is TRUTHY in PHP. A bare `!$row->missed_call` is therefore
 * correct here and silently inverted on a drifted box, which is the worst kind of
 * difference: it reports every missed call as answered and nothing looks wrong.
 */
function clinikoIsTrue($value) {
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value !== 0;
    if (is_float($value)) return $value != 0.0;
    if (is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 't', 'true', 'y', 'yes'], true);
    }
    return (bool) $value;
}

/**
 * "Answered" / "Unanswered" for a CDR row.
 *
 * This is NOT `cc_cause == "answered"`, which is what it used to be. cc_cause is a
 * call-centre QUEUE field: measured on a live database it is NULL on all 76 agent
 * legs and only ever 'cancel' on the 25 abandoned queue legs -- the string
 * "answered" occurs ZERO times in 255 inbound rows. So the old comparison could
 * never return "Answered", and every communication this integration had ever
 * written said "Unanswered" regardless of what happened on the call.
 *
 * The agent leg carries the outcome in two columns and BOTH are required: all 76
 * agent legs have an answer_stamp (the queue answers the caller to play hold
 * music, so the stamp is set even when no human ever picked up), and 7 of those
 * are still flagged missed_call.
 */
function clinikoCallOutcome($cdr) {
    $answerStamp = is_array($cdr) ? ($cdr['answer_stamp'] ?? null) : $cdr->answer_stamp;
    $missedCall  = is_array($cdr) ? ($cdr['missed_call'] ?? null)  : $cdr->missed_call;

    if (empty($answerStamp)) return "Unanswered";
    return clinikoIsTrue($missedCall) ? "Unanswered" : "Answered";
}

/**
 * The timezone call times are rendered in.
 *
 * Read from an optional `timezone` field on the integration hash -- the same shape
 * the Salesforce path already uses (`sf_getTimeZone` reads $sfKeyVal['timeZone']).
 * There is no per-tenant timezone anywhere in the database on this platform:
 * v_domains has no such column and v_user_settings/v_domain_settings hold zero
 * timezone rows, so a stored setting is the only available source.
 *
 * An unusable value is logged and IGNORED rather than thrown: an uncaught
 * DateInvalidTimeZoneException here would kill the job mid-communication.
 */
function clinikoDisplayTimezone(array $integration) {
    $name = isset($integration['timezone']) ? trim((string) $integration['timezone']) : '';
    if ($name !== '') {
        try {
            return new \DateTimeZone($name);
        } catch (\Throwable $e) {
            error_log("[Cliniko] ignoring unusable timezone '" . $name . "': " . $e->getMessage());
        }
    }
    return new \DateTimeZone(CLINIKO_DEFAULT_TIMEZONE);
}

/**
 * Conditions + binds that tie a CDR to THIS job rather than to whatever ended in
 * the last ten seconds. Returns null when the job carries no usable `queued_at`,
 * so the caller can fall back to the historical behaviour for older payloads
 * instead of dropping them.
 */
/**
 * The "Call Start Time / Call End Time / Duration / Recording" block appended to
 * the communication body.
 *
 * The reason this exists: the times and the duration used to sit INSIDE
 * `if (!empty($record_path) && !empty($record_name))`, so a call with no
 * recording got a bare one-line message with no times at all. Measured on a live
 * database, 251 of 260 inbound CDRs have no recording -- i.e. ~96% of calls were
 * being logged with no call detail whatsoever. Whether a recording exists governs
 * the Recording line and nothing else.
 *
 * Returns null when the timestamps cannot be read, so the caller decides whether
 * that is fatal; it never invents a time.
 */
function clinikoCallDetailBlock($cdr, array $integration, $outcome, $recordingUrl = null) {
    $startUtc = \DateTime::createFromFormat('Y-m-d H:i:sP', $cdr->start_stamp, new \DateTimeZone('UTC'));
    $endUtc   = \DateTime::createFromFormat('Y-m-d H:i:sP', $cdr->end_stamp, new \DateTimeZone('UTC'));
    if (!$startUtc || !$endUtc) return null;

    $tz = clinikoDisplayTimezone($integration);
    // The zone is named by its IANA identifier, not by format('T'). PHP renders
    // Asia/Manila as "PST", which in a clinical record reads as Pacific Standard
    // Time — eight hours and a hemisphere away. Sydney would give "AEST" and
    // Singapore "+08", so the abbreviation is neither consistent nor unambiguous.
    $zoneLabel = $tz->getName();
    $lines = [
        "Call Start Time: " . $startUtc->setTimezone($tz)->format('Y-m-d g:i:s A') . " " . $zoneLabel,
        "Call End Time: "   . $endUtc->setTimezone($tz)->format('Y-m-d g:i:s A') . " " . $zoneLabel,
    ];
    // `duration` is the whole call including ring time, which is what this
    // integration has always reported. Talk time is `billsec` if that is ever
    // wanted -- changing it would silently redefine an existing field.
    if ($outcome === "Answered") {
        $lines[] = "Duration: " . gmdate("H:i:s", (int) $cdr->duration);
    }
    if (!empty($recordingUrl)) {
        $lines[] = "Recording: " . $recordingUrl;
    }
    return "\n" . implode("\n", $lines);
}

function clinikoCdrWindow(array $jobData) {
    $queuedAt = $jobData['queued_at'] ?? null;
    if (!is_numeric($queuedAt)) return null;
    $queuedAt = (int) $queuedAt;
    return [
        'from'     => $queuedAt - CLINIKO_CDR_MATCH_LEAD_SECONDS,
        'to'       => $queuedAt + CLINIKO_CDR_MATCH_LAG_SECONDS,
        'queuedAt' => $queuedAt,
    ];
}

function clinikoJobStillRetryable(array $jobData) {
    $queuedAt = $jobData['queued_at'] ?? null;
    if (!is_numeric($queuedAt)) {
        return false;
    }
    return (time() - (int) $queuedAt) < CLINIKO_JOB_MAX_AGE_SECONDS;
}

function handleClinikoInbound($redis, $jobJson) {
    $jobData = json_decode($jobJson, true);
    if (!$jobData) return;

    $integration = $redis->hgetall("integration:{$jobData['subdomain']}-cliniko");
    if (empty($integration['apiKey'])) return;

    $apiKey = $integration["apiKey"];
    $appVendorName = $integration["vendorName"];
    $appVendorEmail = $integration["vendorEmail"];
    $apiKeyParts = explode('-', $apiKey);
    $lastPart = end($apiKeyParts);
    $patientFirstName = ucfirst($jobData['first_name']);
    $patientLastName = ucfirst($jobData['last_name']);

    $vDomain = VDomains::findFirstByDomainName($jobData['subdomain']);
    if (!$vDomain) return;

    // Tie the CDR lookup to THIS job rather than to "whatever ended in the last ten
    // seconds". See CLINIKO_CDR_MATCH_* above for why the old window could not work.
    // `bindTypes` is dropped deliberately: it was an INDEXED array supplied alongside
    // NAMED binds, so Phalcon could never map it and it was inert. Reinstating it
    // correctly is a separate change; carrying a broken one forward is worse.
    $window = clinikoCdrWindow($jobData);
    if ($window === null) {
        // Older payload shape with no queued_at. Keep the historical behaviour for
        // it rather than dropping the job, and say so, so the fallback is visible.
        error_log("[Cliniko] job for " . ($jobData['contact_number'] ?? 'unknown')
            . " carries no queued_at; using the legacy end_epoch window");
        $matchCondition = "end_epoch >= :end_epoch:";
        $matchBind      = ["end_epoch" => time() - 10];
        $matchOrder     = "insert_date DESC";
    } else {
        $matchCondition = "start_epoch >= :start_from: AND start_epoch <= :start_to: AND end_epoch >= :min_end:";
        $matchBind      = [
            "start_from" => $window['from'],
            "start_to"   => $window['to'],
            // Excludes a call that had already ended before this job was queued.
            "min_end"    => $window['queuedAt'] - CLINIKO_CDR_MATCH_END_GRACE_SECONDS,
        ];
        // Nearest call to the moment the pop fired wins, so even a wide window
        // resolves correctly when the same number calls twice in quick succession.
        // queuedAt is cast to int in clinikoCdrWindow(), so interpolating it here
        // cannot carry anything but digits.
        $matchOrder     = "ABS(start_epoch - " . $window['queuedAt'] . ") ASC";
    }

    $clinikoCall = QRingqCdr::findFirst([
        "conditions" => "direction = :direction: AND domain_uuid = :domain_uuid: AND caller_id_number = :caller_id_number: AND cc_agent IS NOT NULL AND " . $matchCondition,
        "bind" => array_merge([
            "direction" => 'inbound',
            "domain_uuid" => $vDomain->domain_uuid,
            "caller_id_number" => $jobData['contact_number'],
        ], $matchBind),
        "order" => $matchOrder
    ]);

    if (!$clinikoCall) {
        $clinikoCall = QRingqCdr::findFirst([
            "conditions" => "direction = :direction: AND domain_uuid = :domain_uuid: AND caller_id_number = :caller_id_number: AND missed_call = true AND " . $matchCondition,
            "bind" => array_merge([
                "direction" => 'inbound',
                "domain_uuid" => $vDomain->domain_uuid,
                "caller_id_number" => $jobData['contact_number'],
            ], $matchBind),
            "order" => $matchOrder
        ]);

        if ($clinikoCall) {
            // This query filters on missed_call = true, so the helper resolves to
            // "Unanswered" here by construction -- it is used rather than a literal
            // so the rule lives in exactly one place if the query ever widens.
            $subject = clinikoCallOutcome($clinikoCall);
            $callDirection = ucfirst($clinikoCall->direction);
            $callDetails = "$callDirection $subject call from {$jobData['contact_number']}";
            $detail = clinikoCallDetailBlock($clinikoCall, $integration, $subject);
            if ($detail === null) {
                // Used to return silently, with no log and no re-queue, so a
                // format drift would make jobs vanish leaving no trace at all.
                error_log("[Cliniko] unparseable CDR timestamps on "
                    . $clinikoCall->xml_cdr_uuid . "; dropping call-log job");
                return;
            }
            $callDetails .= $detail;

            if (!empty($clinikoCall->record_name)
                && $redis->sIsMember("cliniko:{$jobData['subdomain']}-records", $clinikoCall->record_name)) return;
            if ($redis->get("cliniko:{$clinikoCall->xml_cdr_uuid}-prevcomm")) return;

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "https://api.$lastPart.cliniko.com/v1/communications");
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
                "category_code" => 12, "confidential" => true, "content" => $callDetails,
                "direction_code" => 2, "from" => "$patientFirstName $patientLastName ({$jobData['contact_number']})",
                "patient_id" => $jobData['patient_id'], "to" => "No Answer", "type_code" => 3
            ]));
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                "Accept: application/json", "Content-Type: application/json",
                "User-Agent: $appVendorName ($appVendorEmail)",
                "Authorization: Basic " . base64_encode($apiKey . ":"),
            ]);
            $response = curl_exec($curl);
            $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($response === false) { error_log("cURL error: " . curl_error($curl)); }
            $data = json_decode($response, true);

            if ($http_status >= 200 && $http_status < 300) {
                $redis->set("cliniko:{$clinikoCall->xml_cdr_uuid}-prevcomm", $data['id']);
                if (!empty($clinikoCall->record_path) && !empty($clinikoCall->record_name)) {
                    $source = $clinikoCall->record_path . "/" . $clinikoCall->record_name;
                    $destination = "/var/www/ringq/public/temp/" . $clinikoCall->record_name;
                    $redis->sAdd("cliniko:{$jobData['subdomain']}-records", $clinikoCall->record_name);
                    if (file_exists($source)) copy($source, $destination);
                }
                return;
            }
        }
        // Re-queue if not processed — but only while the job can still match.
        //
        // Both CDR lookups above require `end_epoch >= time() - 10`, i.e. a call
        // that ended in the last ten seconds. That window is what lets a job
        // queued mid-call retry until the CDR lands. Once a job is older than
        // the window it can NEVER match again, so the old unconditional
        // `lpush` turned it into a poison pill: LPOP + LPUSH put it straight
        // back at the HEAD, once a second, forever, blocking every job behind
        // it. Nineteen such jobs (18 of them the same Aug-14 call) blocked this
        // queue for three days and no call log was written in that time.
        //
        // Two changes: give up once the job is past the retry window, and
        // re-queue at the TAIL so one failing job cannot starve the rest.
        if (!clinikoJobStillRetryable($jobData)) {
            error_log(
                "[Cliniko] dropping unmatchable call-log job for "
                . ($jobData['contact_number'] ?? 'unknown')
                . " queued_at=" . ($jobData['queued_at'] ?? 'unset')
                . " (no CDR within the lookup window)"
            );
            return;
        }
        $redis->rpush('queues-cliniko', json_encode($jobData));
        return;
    }

    // Answered call with cc_agent
    if ($clinikoCall) {
        // Was `cc_cause == "answered"`, which is NEVER true on this platform: see
        // clinikoCallOutcome(). Every communication ever written said "Unanswered".
        $subject = clinikoCallOutcome($clinikoCall);
        $callDirection = ucfirst($clinikoCall->direction);

        $vCallCenterAgent = VCallCenterAgents::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND call_center_agent_uuid = :call_center_agent_uuid:",
            "bind" => ["domain_uuid" => $vDomain->domain_uuid, "call_center_agent_uuid" => $clinikoCall->cc_agent],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);

        if ($vCallCenterAgent) {
            $vExtensionUser = VExtensionUsers::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                "bind" => ["domain_uuid" => $vDomain->domain_uuid, "user_uuid" => $vCallCenterAgent->user_uuid],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if ($vExtensionUser) {
                $vExtension = VExtensions::findFirst([
                    "conditions" => "domain_uuid = :domain_uuid: AND extension_uuid = :extension_uuid:",
                    "bind" => ["domain_uuid" => $vDomain->domain_uuid, "extension_uuid" => $vExtensionUser->extension_uuid],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                ]);

                if ($vExtension) {
                    $callDetails = "$callDirection $subject call from {$jobData['contact_number']} to {$vExtension->effective_caller_id_name} ({$vExtension->extension})";
                    // The times and duration are NOT conditional on a recording. They
                    // used to be, which is why a call on a system with recording off
                    // -- 251 of 260 rows here -- produced a bare one-line message.
                    $hasRecording = !empty($clinikoCall->record_path) && !empty($clinikoCall->record_name);
                    $recordingUrl = $hasRecording
                        ? "https://{$clinikoCall->domain_name}:8443/temp/{$clinikoCall->record_name}"
                        : null;
                    $detail = clinikoCallDetailBlock($clinikoCall, $integration, $subject, $recordingUrl);
                    if ($detail === null) {
                        error_log("[Cliniko] unparseable CDR timestamps on "
                            . $clinikoCall->xml_cdr_uuid . "; dropping call-log job");
                        return;
                    }
                    $callDetails .= $detail;

                    if ($hasRecording
                        && $redis->sIsMember("cliniko:{$jobData['subdomain']}-records", $clinikoCall->record_name)) return;

                    if ($redis->get("cliniko:{$clinikoCall->xml_cdr_uuid}-prevcomm")) return;

                    $curl = curl_init();
                    curl_setopt($curl, CURLOPT_URL, "https://api.$lastPart.cliniko.com/v1/communications");
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                    curl_setopt($curl, CURLOPT_POST, true);
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
                        "category_code" => 12, "confidential" => true, "content" => $callDetails,
                        "direction_code" => 2, "from" => "$patientFirstName $patientLastName ({$jobData['contact_number']})",
                        "patient_id" => $jobData['patient_id'],
                        "to" => "{$vExtension->effective_caller_id_name} ({$vExtension->extension})", "type_code" => 3
                    ]));
                    curl_setopt($curl, CURLOPT_HTTPHEADER, [
                        "Accept: application/json", "Content-Type: application/json",
                        "User-Agent: $appVendorName ($appVendorEmail)",
                        "Authorization: Basic " . base64_encode($apiKey . ":"),
                    ]);
                    $response = curl_exec($curl);
                    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    curl_close($curl);

                    if ($response === false) { error_log("cURL error: " . curl_error($curl)); }
                    $data = json_decode($response, true);

                    if ($http_status >= 200 && $http_status < 300) {
                        $redis->set("cliniko:{$clinikoCall->xml_cdr_uuid}-prevcomm", $data['id']);
                        if (!empty($clinikoCall->record_path) && !empty($clinikoCall->record_name)) {
                            $source = $clinikoCall->record_path . "/" . $clinikoCall->record_name;
                            $destination = "/var/www/ringq/public/temp/" . $clinikoCall->record_name;
                            $redis->sAdd("cliniko:{$jobData['subdomain']}-records", $clinikoCall->record_name);
                            if (file_exists($source)) copy($source, $destination);
                        }
                    }
                }
            }
        }
    }
}
 
function handleClinikoOutbound($redis, $jobJson) {
    $jobData = json_decode($jobJson, true);
    if (!$jobData) return;

    $integration = $redis->hgetall("integration:{$jobData['subdomain']}-cliniko");
    if (empty($integration['apiKey'])) return;

    $apiKey = $integration["apiKey"];
    $appVendorName = $integration["vendorName"];
    $appVendorEmail = $integration["vendorEmail"];
    $apiKeyParts = explode('-', $apiKey);
    $lastPart = end($apiKeyParts);
    $patientFirstName = ucfirst($jobData['first_name']);
    $patientLastName = ucfirst($jobData['last_name']);

    $vDomain = VDomains::findFirstByDomainName($jobData['subdomain']);
    if (!$vDomain) return;

    // This used to take "the newest outbound CDR in the WHOLE DOMAIN that ended in
    // the last 60 seconds", with no reference to the job at all. With two agents
    // dialling out at the same moment that attributes one patient's call to the
    // other patient's record -- a cross-attribution bug, not merely imprecision.
    //
    // Anchor on the job's queued_at (see CLINIKO_CDR_MATCH_*), take a handful of
    // candidates nearest that instant, then keep only one whose dialled number
    // actually matches the patient this job is for. The digits are normalised on
    // both sides because caller_destination carries formatting the job does not.
    $window = clinikoCdrWindow($jobData);
    if ($window === null) {
        $outboundCondition = "end_epoch >= :end_epoch:";
        $outboundBind      = ["end_epoch" => time() - 60];
        $outboundOrder     = "insert_date DESC";
    } else {
        $outboundCondition = "start_epoch >= :start_from: AND start_epoch <= :start_to: AND end_epoch >= :min_end:";
        $outboundBind      = [
            "start_from" => $window['from'],
            "start_to"   => $window['to'],
            "min_end"    => $window['queuedAt'] - CLINIKO_CDR_MATCH_END_GRACE_SECONDS,
        ];
        $outboundOrder     = "ABS(start_epoch - " . $window['queuedAt'] . ") ASC";
    }

    $qRingqCdr = QRingqCdr::find([
        "columns" => "sip_hangup_disposition, xml_cdr_uuid, domain_name, direction, caller_id_name, caller_id_number, caller_destination, record_path, record_name, hangup_cause, insert_date, start_epoch, start_stamp, end_stamp, duration",
        "conditions" => "domain_uuid = :domain_uuid: AND direction = :direction: AND " . $outboundCondition,
        "bind" => array_merge(
            ["domain_uuid" => $vDomain->domain_uuid, "direction" => "outbound"],
            $outboundBind
        ),
        "order" => $outboundOrder, "limit" => 5
    ]);

    if (!$qRingqCdr->count()) return;

    $wantedDigits = preg_replace('/[^\d]/', '', (string) ($jobData['contact_number'] ?? ''));
    $vXmlCdrRecord = null;
    foreach ($qRingqCdr as $candidate) {
        if ($wantedDigits === '') { $vXmlCdrRecord = $candidate; break; }
        if (preg_replace('/[^\d]/', '', (string) $candidate->caller_destination) === $wantedDigits) {
            $vXmlCdrRecord = $candidate;
            break;
        }
    }
    if (!$vXmlCdrRecord) {
        // Candidates existed but none was this patient's call. Say nothing to
        // Cliniko rather than logging somebody else's conversation against them.
        error_log("[Cliniko] outbound job for " . ($jobData['contact_number'] ?? 'unknown')
            . " matched no CDR with that destination; leaving it for the next pass");
        return;
    }
    $integration = $redis->hgetall("integration:{$vDomain->domain_name}-cliniko");
    $apiKey = $integration["apiKey"];
    $appVendorName = $integration["vendorName"];
    $appVendorEmail = $integration["vendorEmail"];
    $apiKeyParts = explode('-', $apiKey);
    $lastPart = end($apiKeyParts);
    $baseUrl = "https://api.$lastPart.cliniko.com/v1";
    $base64Credentials = base64_encode($apiKey . ":");
    $contactNumber = preg_replace('/[^\d]/', '', $vXmlCdrRecord->caller_destination);
    $patientFirstName = ucfirst($jobData['first_name']);
    $patientLastName = ucfirst($jobData['last_name']);

    $subject = $vXmlCdrRecord->hangup_cause == "ORIGINATOR_CANCEL" && $vXmlCdrRecord->sip_hangup_disposition == "recv_cancel" ? "Unanswered" : "Answered";
    $callDirection = ucfirst($vXmlCdrRecord->direction);
    $callDetails = "$callDirection $subject call from {$vXmlCdrRecord->caller_id_name} ({$vXmlCdrRecord->caller_id_number}) to $contactNumber";

    // Detail is emitted for every outbound call, not only answered ones: an
    // unanswered call still has a start and an end worth recording. The Recording
    // line appears only when a recording actually exists -- it used to be written
    // unconditionally here, producing a dangling ".../temp/" URL with no filename.
    $hasRecording = !empty($vXmlCdrRecord->record_path) && !empty($vXmlCdrRecord->record_name);
    $recordingUrl = $hasRecording
        ? "https://{$vXmlCdrRecord->domain_name}:8443/temp/{$vXmlCdrRecord->record_name}"
        : null;
    $detail = clinikoCallDetailBlock($vXmlCdrRecord, $integration, $subject, $recordingUrl);
    if ($detail === null) {
        error_log("[Cliniko] unparseable CDR timestamps on "
            . $vXmlCdrRecord->xml_cdr_uuid . "; dropping call-log job");
        return;
    }
    $callDetails .= $detail;

    if ($redis->get("cliniko:{$vXmlCdrRecord->xml_cdr_uuid}-prevcomm")) return;

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "$baseUrl/communications");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "category_code" => 12, "confidential" => true, "content" => $callDetails,
        "direction_code" => 1,
        "to" => "$patientFirstName $patientLastName ({$vXmlCdrRecord->caller_destination})",
        "from" => "{$vXmlCdrRecord->caller_id_name} ({$vXmlCdrRecord->caller_id_number})",
        "patient_id" => $jobData['patient_id'], "type_code" => 3
    ]));
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        "Accept: application/json", "Content-Type: application/json",
        "User-Agent: $appVendorName ($appVendorEmail)",
        "Authorization: Basic " . base64_encode($apiKey . ":"),
    ]);
    $response = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) { error_log("cURL error: " . curl_error($curl)); }

    if ($http_status >= 200 && $http_status < 300) {
        $data = json_decode($response, true);
        $redis->set("cliniko:{$vXmlCdrRecord->xml_cdr_uuid}-prevcomm", $data['id']);
        if ($subject == "Answered") {
            $source = $vXmlCdrRecord->record_path . "/" . $vXmlCdrRecord->record_name;
            $destination = "/var/www/ringq/public/temp/" . $vXmlCdrRecord->record_name;
            $redis->sAdd("cliniko:{$jobData['subdomain']}-records", $vXmlCdrRecord->record_name);
            if (file_exists($source)) copy($source, $destination);
        }
    }
}

function handleClinikoInboundNewPatient($redis, $jobJson) {
    $jobData = json_decode($jobJson, true);
    if (!$jobData) return;

    if ($redis->get("cliniko-patient:{$jobData["phone_number"]}")) return;

    $cliniko = $redis->hgetall("integration:" . $jobData["subdomain"] . "-cliniko");
    if (empty($cliniko['apiKey'])) return;

    $apiKey = $cliniko["apiKey"];
    $appVendorName = $cliniko["vendorName"];
    $appVendorEmail = $cliniko["vendorEmail"];
    $apiKeyParts = explode('-', $apiKey);
    $lastPart = end($apiKeyParts);
    $base64Credentials = base64_encode($apiKey . ":");
    $baseUrl = "https://api.$lastPart.cliniko.com/v1";
    $phoneNumber = $jobData["phone_number"];

    // Drop any previous handshake value before creating this patient. Without
    // this, a pop polling for THIS creation can be satisfied by an older one --
    // the key is global, not keyed by phone number, so nothing else distinguishes
    // them. Paired with the TTL on the write below.
    $redis->del('cliniko-patient-created');

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "$baseUrl/patients");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
        "first_name" => "RingQ",
        "last_name" => "New Contact " . $jobData["phone_number"],
        "patient_phone_numbers" => [["phone_type" => "Mobile", "number" => $jobData["phone_number"]]]
    ]));
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        "Accept: application/json", "User-Agent: $appVendorName ($appVendorEmail)",
        "Authorization: Basic $base64Credentials", "Content-Type: application/json"
    ]);
    $patient_response = curl_exec($curl);
    $patient_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($patient_status >= 200 && $patient_status < 300) {
        $patientReponseData = json_decode($patient_response, true);
        // setex, not set: an immortal handshake key is what made the pop open a
        // patient that had been deleted hours earlier.
        $redis->setex('cliniko-patient-created', CLINIKO_PATIENT_CREATED_TTL_SECONDS, $patientReponseData['id']);
        $redis->rpush('queues-cliniko', json_encode([
            'subdomain' => $jobData["subdomain"],
            'first_name' => $patientReponseData['first_name'],
            'last_name' => $patientReponseData['last_name'],
            'patient_id' => $patientReponseData['id'],
            'contact_number' => $jobData["phone_number"],
            'queue_extension' => $jobData["queue_extension"],
            'queued_at' => time()
        ]));
        $cleanNumber = preg_replace('/[^\d]/', '', $jobData["phone_number"]);
        $redis->set("cliniko-patient:$cleanNumber", json_encode([
            'patient_id' => $patientReponseData['id'] ?? '',
            'first_name' => $patientReponseData['first_name'],
            'last_name' => $patientReponseData['last_name'],
            'numbers' => $cleanNumber,
            'normalized_numbers' => $cleanNumber
        ]));
    }
}

function handleClinikoOutboundNewPatient($redis) {
    $vDomains = VDomains::find([
        'columns' => "domain_uuid, domain_name",
        'conditions' => "domain_enabled = true",
    ]);

    foreach ($vDomains as $vDomain) {
        // Skip domains without a Cliniko integration — O(1) instead of
        // scanning for their landing keys.
        if (!$redis->exists("integration:{$vDomain->domain_name}-cliniko")) {
            continue;
        }

        // The landing keys are written by the queue-settings save as
        // "<queue_extension>@<domain>-queue_call_landing[2]", so they
        // can be read directly from this domain's call-center queues —
        // the old code found them with two full-keyspace SCANs per
        // domain on every poll.
        $ccQueues = VCallCenterQueues::find([
            'columns' => "queue_extension",
            'conditions' => "domain_uuid = :domain_uuid:",
            'bind' => ["domain_uuid" => $vDomain->domain_uuid],
        ]);

        foreach ($ccQueues as $ccQueue) {
            $landingBase = "{$ccQueue->queue_extension}@{$vDomain->domain_name}-queue_call_landing";
            $showQueueLanding1 = $redis->get($landingBase);
            $showQueueLanding2 = $redis->get("{$landingBase}2");

            if ($showQueueLanding1 && $showQueueLanding2) {
                        if ($showQueueLanding2 == "Cliniko" && ($showQueueLanding1 == "Open External URL or CRM" || $showQueueLanding1 == "Open Both")) {
                            $vXmlCdr = QRingqCdr::find([
                                "columns" => "sip_hangup_disposition, xml_cdr_uuid, domain_name, direction, caller_id_name, caller_id_number, caller_destination, record_path, record_name, hangup_cause, insert_date, start_stamp, end_stamp, duration",
                                "conditions" => "domain_uuid = :domain_uuid: AND direction = :direction: AND end_epoch >= :end_epoch:",
                                "bind" => ["domain_uuid" => $vDomain['domain_uuid'], "direction" => "outbound", "end_epoch" => time() - 60],
                                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                                "order" => "insert_date DESC", "limit" => 1
                            ]);

                            if ($vXmlCdr->count()) {
                                $vXmlCdrRecord = $vXmlCdr->getFirst();
                                $integration = $redis->hgetall("integration:{$vDomain->domain_name}-cliniko");
                                if (empty($integration['apiKey'])) continue;

                                $apiKey = $integration["apiKey"];
                                $appVendorName = $integration["vendorName"];
                                $appVendorEmail = $integration["vendorEmail"];
                                $apiKeyParts = explode('-', $apiKey);
                                $lastPart = end($apiKeyParts);
                                $baseUrl = "https://api.$lastPart.cliniko.com/v1";
                                $base64Credentials = base64_encode($apiKey . ":");
                                $contactNumber = preg_replace('/[^\d]/', '', $vXmlCdrRecord->caller_destination);

                                $newPatientCreation = function() use ($baseUrl, $contactNumber, $appVendorName, $appVendorEmail, $base64Credentials, $vDomain, $redis, $vXmlCdrRecord) {
                                    $curl = curl_init();
                                    curl_setopt($curl, CURLOPT_URL, "$baseUrl/patients");
                                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                                    curl_setopt($curl, CURLOPT_POST, true);
                                    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
                                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
                                        "first_name" => "RingQ",
                                        "last_name" => "New Contact $contactNumber",
                                        "patient_phone_numbers" => [["phone_type" => "Mobile", "number" => $contactNumber]]
                                    ]));
                                    curl_setopt($curl, CURLOPT_HTTPHEADER, [
                                        "Accept: application/json", "User-Agent: $appVendorName ($appVendorEmail)",
                                        "Authorization: Basic $base64Credentials", "Content-Type: application/json"
                                    ]);
                                    $patient_response = curl_exec($curl);
                                    $patient_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                                    curl_close($curl);
                                    if ($patient_status >= 200 && $patient_status < 300) {
                                        $patientReponseData = json_decode($patient_response, true);
                                        $redis->set("cliniko-patient:$contactNumber", json_encode([
                                            'patient_id' => $patientReponseData['id'] ?? '',
                                            'first_name' => $patientReponseData['first_name'],
                                            'last_name' => $patientReponseData['last_name'],
                                            'numbers' => $contactNumber,
                                            'normalized_numbers' => $contactNumber
                                        ]));
                                        $queueKey = 'queues-outbound-cliniko';
                                        $newQueueData = json_encode([
                                            'patient_id' => $patientReponseData['id'],
                                            'subdomain' => $vDomain->domain_name,
                                            'first_name' => $patientReponseData['first_name'],
                                            'last_name' => $patientReponseData['last_name'],
                                            'contact_number' => $contactNumber,
                                            'queue_extension' => $vXmlCdrRecord->caller_id_number,
                                            'queued_at' => time()
                                        ]);
                                        $existingQueue = $redis->lrange($queueKey, 0, -1);
                                        $exists = false;
                                        foreach ($existingQueue as $queueItem) {
                                            $queueData = json_decode($queueItem, true);
                                            if ($queueData['patient_id'] === $patientReponseData['id'] && $queueData['contact_number'] === $contactNumber) {
                                                $exists = true;
                                                continue;
                                            }
                                        }
                                        if (!$exists) $redis->rpush($queueKey, $newQueueData);
                                    }
                                };

                                $patientRingQ = $redis->get("cliniko-patient:$contactNumber");
                                if ($patientRingQ && $integration) {
                                    $patientData = json_decode($patientRingQ, true);
                                    $curl = curl_init();
                                    curl_setopt($curl, CURLOPT_URL, "$baseUrl/patients/{$patientData['patient_id']}");
                                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
                                    curl_setopt($curl, CURLOPT_HTTPHEADER, [
                                        "Accept: application/json", "User-Agent: $appVendorName ($appVendorEmail)",
                                        "Authorization: Basic $base64Credentials"
                                    ]);
                                    $patientResponse = curl_exec($curl);
                                    $patientStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                                    curl_close($curl);

                                    if ($patientStatus >= 200 && $patientStatus < 300) {
                                        $patientReponseData = json_decode($patientResponse, true);
                                        $queueKey = 'queues-outbound-cliniko';
                                        $newQueueData = json_encode([
                                            'patient_id' => $patientReponseData['id'],
                                            'subdomain' => $vDomain->domain_name,
                                            'first_name' => $patientReponseData['first_name'],
                                            'last_name' => $patientReponseData['last_name'],
                                            'contact_number' => $contactNumber,
                                            'queue_extension' => $vXmlCdrRecord->caller_id_number,
                                            'queued_at' => time()
                                        ]);
                                        $existingQueue = $redis->lrange($queueKey, 0, -1);
                                        $exists = false;
                                        foreach ($existingQueue as $queueItem) {
                                            $queueData = json_decode($queueItem, true);
                                            if ($queueData['patient_id'] === $patientReponseData['id'] && $queueData['contact_number'] === $contactNumber) {
                                                $exists = true;
                                                continue;
                                            }
                                        }
                                        if (!$exists) $redis->rpush($queueKey, $newQueueData);
                                    } else {
                                        $redis->del("cliniko-patient:$contactNumber");
                                        $newPatientCreation();
                                    }
                                } else {
                                    $newPatientCreation();
                                }
                            }
                        }
            }
        }
    }
}
