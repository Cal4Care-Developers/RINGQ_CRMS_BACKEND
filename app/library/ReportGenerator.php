<?php

use Phalcon\Di\Injectable;
use Phalcon\Db\Column;

class ReportGenerator extends Injectable
{
    protected function getSecurity()
    {
        return new \Phalcon\Security\Random();
    }

    protected function formatTimezoneUnix($format, $timestamp, $timezone)
    {
        $dateTime = new DateTime();
        $dateTime->setTimestamp($timestamp);
        $dateTime->setTimezone(new DateTimeZone($timezone));
        return $dateTime->format($format);
    }

    protected function formatTimezoneString($datetime_string, $format, $timezone)
    {
        $dateTime = new DateTime($datetime_string);
        $dateTime->setTimezone(new DateTimeZone($timezone));
        return $dateTime->format($format);
    }

    protected function writeCsv($content, $header)
    {
        $filename = $this->getSecurity()->uuid();
        $path = '/var/www/ringq/cache/'. $filename .'.csv';
        $fp = fopen($path, 'w');
        fputcsv($fp, $header);
        foreach ($content as $fields) {
            fputcsv($fp, $fields);
        }
        fclose($fp);
        return $filename . ".csv";
    }

    /** Agent uuid -> name, for the "Answered by" column. */
    public function agentNames($domainUuid)
    {
        $names = [];
        $agents = VCallCenterAgents::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "bind" => ["domain_uuid" => $domainUuid],
            "bindTypes" => [Column::BIND_PARAM_STR],
        ]);
        foreach ($agents as $agent) {
            $names[(string) $agent->call_center_agent_uuid] = $agent->agent_name . ($agent->agent_id ? " (" . $agent->agent_id . ")" : "");
        }
        return $names;
    }

    /**
     * Call logs CSV columns: the same columns, in the same order, as the Call logs table
     * (download, Generate email and scheduled report share them).
     */
    public static function callLogCsvHeader()
    {
        return ["Call time", "From", "To", "Queue", "Answered by", "Wait", "Ringing", "Talking", "Status", "Recording"];
    }

    /**
     * Call journeys (q_call_journeys, written by node callJourney.persist) for a set of call
     * record uuids, keyed by every leg uuid -- the same lookup the Call logs table does over the
     * socket (signal.js getCallJourneys) for its Queue, Answered by, Wait and Status columns.
     */
    public function callJourneys($uuids)
    {
        $out = [];
        $uuids = array_values(array_unique(array_filter(array_map('strval', (array) $uuids), function ($u) {
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $u);
        })));
        foreach (array_chunk($uuids, 500) as $chunk) {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT legs, queue_ext, queue_name, answered_by, answered_name, wait_sec, status_text
                       FROM q_call_journeys WHERE legs && CAST(:legs AS text[])",
                    \Phalcon\Db\Enum::FETCH_ASSOC,
                    ["legs" => "{" . implode(",", $chunk) . "}"]
                );
            } catch (\Exception $e) {
                return $out; // server without the call-journey table: columns fall back to "--"
            }
            $wanted = array_flip($chunk);
            foreach ($rows as $r) {
                foreach (explode(",", trim((string) $r["legs"], "{}")) as $leg) {
                    $leg = trim($leg, '"');
                    if (isset($wanted[$leg])) {
                        $out[$leg] = $r;
                    }
                }
            }
        }
        return $out;
    }

    public static function callLogCsvRow($cdr, $timezone, $subdomain, $journeys = [])
    {
        $tz = new DateTimeZone($timezone);
        $hms = function ($seconds) {
            if ($seconds === null || $seconds === '' || !is_numeric($seconds)) {
                return "--";
            }
            $seconds = max(0, (int) $seconds);
            return sprintf("%02d:%02d:%02d", intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        };
        $j = $journeys[(string) $cdr->xml_cdr_uuid] ?? null;

        // Same rules as CallLogsReport.tsx.
        $missed = preg_match('/missed|abandon/i', (string) $cdr->last_arg) === 1;
        $name = (string) $cdr->caller_id_name;
        $number = (string) $cdr->caller_id_number;
        if ($name !== '' && $name === $number) {
            $from = $number;
        } else if ($name !== '') {
            $from = $name . " (" . $number . ")";
        } else {
            $from = $number;
        }
        $queue = "--";
        if ($j && !empty($j["queue_ext"])) {
            $queue = !empty($j["queue_name"]) ? trim($j["queue_name"]) . " (" . $j["queue_ext"] . ")" : "Queue " . $j["queue_ext"];
        }
        $answeredBy = ($j && !empty($j["answered_by"])) ? $j["answered_by"] . (!empty($j["answered_name"]) ? " · " . $j["answered_name"] : "") : "--";
        $wait = "--";
        if ($j && $j["wait_sec"] !== null && $j["wait_sec"] !== '' && is_numeric($j["wait_sec"])) {
            $n = max(0, (int) round((float) $j["wait_sec"]));
            $wait = $n < 60 ? $n . "s" : intdiv($n, 60) . "m " . sprintf("%02d", $n % 60) . "s";
        }
        $status = ($j && !empty($j["status_text"])) ? $j["status_text"] : (string) $cdr->last_arg;
        $callTime = intval($cdr->start_epoch) > 0
            ? (new DateTime("@" . intval($cdr->start_epoch)))->setTimezone($tz)->format("m/d/y h:i A")
            : "";
        return [
            $callTime,
            $from,
            (string) $cdr->caller_destination,
            $queue,
            $answeredBy,
            $wait,
            $missed ? $hms($cdr->billsec) : $hms($cdr->waitsec),
            $missed ? "00:00:00" : $hms($cdr->billsec),
            $status,
            !empty($cdr->record_name) ? "https://" . $subdomain . ":8443/api/recordings/1/" . $cdr->xml_cdr_uuid : "--",
        ];
    }

    /** Writes the Call logs CSV (UTF-8 with BOM so Excel shows names correctly). Returns the row count. */
    public static function writeCallLogCsv($path, $cdrs, $timezone, $subdomain, $journeys)
    {
        $fp = fopen($path, 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, self::callLogCsvHeader());
        $rows = 0;
        foreach ($cdrs as $cdr) {
            fputcsv($fp, self::callLogCsvRow($cdr, $timezone, $subdomain, $journeys));
            $rows++;
        }
        fclose($fp);
        return $rows;
    }

    /**
     * Scheduled Call logs: the same rows and filters as the Call logs download
     * (ReportsController::callhistoryAction POST).
     */
    public function generateCallLogs($context, $filters)
    {
        $domain_uuid = $context['domain_uuid'];
        $timezone = $context['timezone'] ?? date_default_timezone_get();

        $startStamp = intval($filters['selectStartDate'] ?? 0);
        $endStamp = intval($filters['selectEndDate'] ?? 0);
        $agent = $filters['selectAgent'] ?? 0;
        $callType = $filters['selectType'] ?? 0; // 1=Missed, 2=Answered
        $callNature = $filters['selectNature'] ?? 0; // 1=Inbound, 2=Outbound

        if (!is_numeric($callType)) {
            $callTypeText = strtolower((string) $callType);
            $callType = strpos($callTypeText, 'missed') !== false ? 1 : (strpos($callTypeText, 'answered') !== false ? 2 : 0);
        }
        if (!is_numeric($callNature)) {
            $callNatureText = strtolower((string) $callNature);
            $callNature = strpos($callNatureText, 'inbound') !== false ? 1 : (strpos($callNatureText, 'outbound') !== false ? 2 : 0);
        }

        $conditions = "(source_number is not NULL OR missed_call = true) AND domain_uuid = :domain_uuid:";
        $bind = ["domain_uuid" => $domain_uuid];

        if (!empty($agent) && $agent != "0") {
            $conditions .= " AND (caller_id_number = :agent: OR caller_destination = :agent: OR source_number = :agent:";
            $bind["agent"] = (string) $agent;
            $vAgent = VCallCenterAgents::findFirst([
                "conditions" => "domain_uuid = :domain_uuid: AND agent_id = :agent_id:",
                "bind" => ["domain_uuid" => $domain_uuid, "agent_id" => (string) $agent],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]);
            if ($vAgent) {
                $conditions .= " OR cc_agent = :cc_agent:";
                $bind["cc_agent"] = $vAgent->call_center_agent_uuid;
            }
            $conditions .= ") AND (caller_id_number != caller_destination OR missed_call = true)";
        }
        if ($startStamp > 0) {
            $conditions .= " AND start_epoch >= :startStamp:";
            $bind["startStamp"] = $startStamp;
        }
        if ($endStamp > 0) {
            $conditions .= " AND start_epoch <= :endStamp:";
            $bind["endStamp"] = $endStamp;
        }
        if ($callNature == 1) {
            $conditions .= " AND (direction = 'inbound' OR direction = 'local')";
        } elseif ($callNature == 2) {
            $conditions .= " AND direction = 'outbound'";
        }
        if ($callType == 1) {
            $conditions .= " AND missed_call = true";
        } elseif ($callType == 2) {
            $conditions .= " AND missed_call = false";
        }

        $qCdrs = QRingqCdr::find([
            "conditions" => $conditions,
            "columns" => "xml_cdr_uuid, direction, start_stamp, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, billsec, missed_call, waitsec, hangup_cause, source_number, record_name, cc_queue, cc_agent, last_arg",
            "bind" => $bind,
            "order" => "start_epoch DESC",
        ]);

        $uuids = [];
        foreach ($qCdrs as $cdr) {
            $uuids[] = $cdr->xml_cdr_uuid;
        }
        $filename = $this->getSecurity()->uuid() . ".csv";
        self::writeCallLogCsv('/var/www/ringq/cache/' . $filename, $qCdrs, $timezone, $context['subdomain'], $this->callJourneys($uuids));
        return $filename;
    }

    /**
     * SQL pieces shared by the Overall and Detail queue reports (alias c = q_ringq_cdr).
     *
     * An attended transfer by the agent writes a second queue row for the same call: caller =
     * the agent, last_arg "Attended Transfer", same cc_queue and start_epoch as the caller's
     * queue row (which keeps last_arg "<queue> - Queue Answered"). That row is not a call of its
     * own: it is left out, and the caller's row is counted as transferred instead.
     * Wait for an answered call is waitsec, or answer - start when waitsec was not recorded
     * (transferred calls); talk time never exceeds duration - wait.
     */
    protected static function queueCallSql()
    {
        $sibling = "SELECT 1 FROM q_ringq_cdr s WHERE s.domain_uuid = c.domain_uuid AND s.cc_queue = c.cc_queue
                       AND s.start_epoch = c.start_epoch AND s.xml_cdr_uuid <> c.xml_cdr_uuid";
        $wait = "(CASE WHEN COALESCE(c.waitsec, 0) > 0 THEN c.waitsec
                       WHEN COALESCE(c.answer_epoch, 0) > c.start_epoch THEN c.answer_epoch - c.start_epoch
                       ELSE 0 END)";
        return [
            "skip" => " AND NOT (COALESCE(c.last_arg, '') ILIKE 'Attended Transfer'
                         AND EXISTS ($sibling AND COALESCE(s.last_arg, '') NOT ILIKE '%Transfer%'))",
            "transferred" => "(c.last_arg ILIKE '%Transfer%' OR EXISTS ($sibling AND s.last_arg ILIKE 'Attended Transfer'))",
            "answer_wait" => $wait,
            "talk" => "LEAST(COALESCE(c.billsec, 0), GREATEST(COALESCE(c.duration, 0) - $wait, 0))",
        ];
    }

    /**
     * Overall report: one row per queue call, read from the call records (q_ringq_cdr rows with
     * cc_queue set). The old q_call_queues_list table stopped being written and never had wait,
     * talk or outcome right. Outcome rules match detailQueueStats so both reports agree.
     * The wrap-up is the first one the answering agent saved between the call start and 15 minutes
     * after it ended, preferring one whose contact number matches the caller.
     *
     * @return array ['rows' => [...], 'count' => calls]
     */
    public function overallQueueCalls($domainUuid, $timezone, $queueExtension = null, $startStamp = 0, $endStamp = 0, $limit = null, $offset = 0, $search = null)
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $timezone = 'UTC';
        }
        // PDO needs a distinct name for every placeholder.
        $where = "c.domain_uuid = CAST(:domain AS uuid) AND c.cc_queue IS NOT NULL AND c.cc_queue <> ''";
        $bind = ["domain" => $domainUuid, "domain2" => $domainUuid, "domain3" => $domainUuid, "domain4" => $domainUuid];
        if ($queueExtension !== null && $queueExtension !== '' && $queueExtension !== '0') {
            $where .= " AND split_part(c.cc_queue, '@', 1) = :qext";
            $bind["qext"] = (string) $queueExtension;
        }
        if (intval($startStamp) > 0) {
            $where .= " AND c.start_epoch >= :start";
            $bind["start"] = intval($startStamp);
        }
        if (intval($endStamp) > 0) {
            $where .= " AND c.start_epoch <= :end";
            $bind["end"] = intval($endStamp);
        }
        $searchWhere = "";
        if ($search !== null && trim((string) $search) !== '') {
            $searchWhere = " WHERE (r.caller_id_name ILIKE :s1 OR r.caller_id_number ILIKE :s2 OR r.queue_name ILIKE :s3
                              OR r.qext ILIKE :s4 OR r.agent_name ILIKE :s5 OR r.agent_ext ILIKE :s6)";
            $like = "%" . trim((string) $search) . "%";
            foreach (["s1", "s2", "s3", "s4", "s5", "s6"] as $k) {
                $bind[$k] = $like;
            }
        }

        $q = self::queueCallSql();
        $base = "
            WITH calls AS (
                SELECT c.xml_cdr_uuid, c.start_epoch, c.end_epoch, c.caller_id_name, c.caller_id_number,
                       c.caller_destination, c.direction, c.record_name, c.last_arg,
                       CAST(c.cc_agent AS text) AS cc_agent,
                       split_part(c.cc_queue, '@', 1) AS qext,
                       {$q['answer_wait']} AS answer_wait,
                       {$q['talk']} AS talk,
                       CASE
                           WHEN {$q['transferred']} THEN 'transferred'
                           WHEN c.last_arg ILIKE '%Abandoned%' OR c.cc_cause = 'cancel' THEN 'abandoned'
                           WHEN c.last_arg ILIKE '%Missed%' OR c.last_arg ILIKE '%Failed%' OR c.hangup_cause = 'TIMEOUT' THEN 'missed'
                           WHEN c.cc_agent IS NOT NULL AND CAST(c.cc_agent AS text) <> '' THEN 'answered'
                           WHEN c.last_arg ILIKE '%Answered%' OR c.last_arg ILIKE '%Attended%' THEN 'answered'
                           ELSE 'missed'
                       END AS outcome,
                       COALESCE(c.waitsec, 0) AS waitsec,
                       COALESCE(c.billsec, 0) AS billsec,
                       COALESCE(c.duration, 0) AS duration
                  FROM q_ringq_cdr c
                 WHERE $where{$q['skip']}
            ), r AS (
                -- Queue agents created from the app have agent_id = the user's login name (a hex id),
                -- so the extension comes from the agent's user.
                SELECT calls.*, q.queue_name, a.agent_name,
                       COALESCE(ue.extension,
                                CASE WHEN CAST(a.agent_id AS text) ~ '^[0-9]+$' THEN CAST(a.agent_id AS text) END,
                                substring(calls.last_arg from '^([0-9]+) - Queue Answered'),
                                CASE WHEN a.call_center_agent_uuid IS NOT NULL AND calls.caller_destination ~ '^[0-9]+$'
                                      AND calls.caller_destination <> calls.qext THEN calls.caller_destination END) AS agent_ext
                  FROM calls
                  LEFT JOIN v_call_center_queues q
                    ON q.domain_uuid = CAST(:domain2 AS uuid) AND q.queue_extension = calls.qext
                  LEFT JOIN v_call_center_agents a
                    ON CAST(a.call_center_agent_uuid AS text) = calls.cc_agent
                  LEFT JOIN LATERAL (
                        SELECT CAST(e.extension AS text) AS extension
                          FROM v_extension_users eu
                          JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid
                         WHERE a.user_uuid IS NOT NULL AND eu.user_uuid = a.user_uuid
                           AND e.domain_uuid = CAST(:domain4 AS uuid)
                         ORDER BY e.extension
                         LIMIT 1
                  ) ue ON true
            )";
        $countRow = $this->db->fetchOne($base . " SELECT COUNT(*) AS n FROM r" . $searchWhere, \Phalcon\Db\Enum::FETCH_ASSOC,
            array_diff_key($bind, ["domain3" => 1]));
        $sql = $base . "
            SELECT r.*, w.wrapup_code, w.content AS wrapup_notes
              FROM r
              LEFT JOIN LATERAL (
                    SELECT wl.wrapup_code, wl.content
                      FROM q_wrapupnotes_list wl
                     WHERE CAST(wl.domain_uuid AS text) = :domain3
                       AND r.outcome IN ('answered', 'transferred')
                       AND r.agent_ext IS NOT NULL
                       AND CAST(wl.extension AS text) = r.agent_ext
                       AND CAST(wl.insert_date AS timestamptz) BETWEEN to_timestamp(r.start_epoch) AND to_timestamp(r.end_epoch + 900)
                     ORDER BY (right(regexp_replace(COALESCE(wl.contact, ''), '[^0-9]', '', 'g'), 7)
                               = right(regexp_replace(COALESCE(r.caller_id_number, ''), '[^0-9]', '', 'g'), 7)) DESC,
                              wl.insert_date
                     LIMIT 1
              ) w ON true" . $searchWhere . "
             ORDER BY r.start_epoch DESC";
        if ($limit !== null) {
            $sql .= " LIMIT " . intval($limit) . " OFFSET " . max(0, intval($offset));
        }

        $tz = new \DateTimeZone($timezone);
        $at = function ($epoch, $format) use ($tz) {
            if (intval($epoch) <= 0) {
                return "--";
            }
            return (new \DateTime("@" . intval($epoch)))->setTimezone($tz)->format($format);
        };
        $hms = function ($seconds) {
            $seconds = max(0, (int) round((float) $seconds));
            return sprintf("%02d:%02d:%02d", intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        };
        $results = ["answered" => "Answered", "transferred" => "Transferred", "abandoned" => "Abandoned", "missed" => "Missed"];
        $rows = [];
        foreach ($this->db->fetchAll($sql, \Phalcon\Db\Enum::FETCH_ASSOC, $bind) as $r) {
            $answered = in_array($r["outcome"], ["answered", "transferred"], true);
            // Ring time: same rule as the Detail queue report.
            if ($answered) {
                $wait = (int) $r["answer_wait"];
            } else if ($r["outcome"] === "abandoned") {
                $wait = max((int) $r["waitsec"], (int) $r["billsec"]);
            } else {
                $wait = max((int) $r["waitsec"], (int) $r["duration"]);
            }
            $rows[] = [
                "xml_cdr_uuid" => $r["xml_cdr_uuid"],
                "call_start_date" => $at($r["start_epoch"], "m/d/Y"),
                "call_start_time" => $at($r["start_epoch"], "h:i:s A"),
                "caller_id_name" => (string) $r["caller_id_name"],
                "caller_id_number" => (string) $r["caller_id_number"],
                "queue_uuid" => ($r["queue_name"] !== null ? $r["queue_name"] : "Queue") . " (" . $r["qext"] . ")",
                "queue_extension" => $r["qext"],
                "callee_id_name" => $answered && $r["agent_name"] !== null ? $r["agent_name"] : "--",
                "callee_id_number" => $answered && $r["agent_ext"] !== null ? $r["agent_ext"] : "--",
                "call_direction" => $r["direction"] === "outbound" ? "Outbound" : ($r["direction"] === "inbound" ? "Inbound" : "Internal"),
                "call_type" => $results[$r["outcome"]],
                "call_duration" => $hms($r["duration"]),
                "call_waiting_time" => $hms($wait),
                "call_time_to_answer" => $answered ? $hms($wait) : "--",
                "call_answered_time" => $answered ? $at(intval($r["start_epoch"]) + $wait, "h:i:s A") : "--",
                "call_talk_time" => $answered ? $hms($r["talk"]) : "--",
                "call_end_time" => $at($r["end_epoch"], "h:i:s A"),
                "wrapup_code" => $r["wrapup_code"] !== null ? (string) $r["wrapup_code"] : "",
                "wrapup_notes" => $r["wrapup_notes"] !== null ? (string) $r["wrapup_notes"] : "",
                "record_name" => $r["record_name"] !== null ? (string) $r["record_name"] : "",
            ];
        }
        return ["rows" => $rows, "count" => (int) ($countRow["n"] ?? 0)];
    }

    /** Header + rows for the Overall CSV (download and scheduled report), same columns as the screen. */
    public static function overallCsvRows($rows, $subdomain = null)
    {
        $header = ["Date", "Time", "Caller name", "Caller number", "Queue", "Agent", "Agent extension", "Direction", "Result",
            "Duration", "Wait time", "Answered at", "Talk time", "Ended at", "Wrap-up", "Notes", "Recording"];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r["call_start_date"], $r["call_start_time"], $r["caller_id_name"], $r["caller_id_number"], $r["queue_uuid"],
                $r["callee_id_name"], $r["callee_id_number"], $r["call_direction"], $r["call_type"],
                $r["call_duration"], $r["call_waiting_time"], $r["call_answered_time"], $r["call_talk_time"], $r["call_end_time"],
                $r["wrapup_code"], $r["wrapup_notes"],
                ($r["record_name"] !== "" && $subdomain) ? "https://" . $subdomain . ":8443/api/recordings/1/" . $r["xml_cdr_uuid"] : "",
            ];
        }
        return [$header, $out];
    }

    public function generateOverall($context, $filters)
    {
        $timezone = $context['timezone'] ?? date_default_timezone_get();
        $calls = $this->overallQueueCalls($context['domain_uuid'], $timezone,
            $this->queueExtensionFilter($context['domain_uuid'], $filters['selectQueue'] ?? "0"),
            intval($filters['selectStartDate'] ?? 0), intval($filters['selectEndDate'] ?? 0));
        list($header, $rows) = self::overallCsvRows($calls['rows'], $context['subdomain'] ?? null);
        return $this->writeCsv($rows, $header);
    }

    /** Queue filter value (queue uuid from the web, or an extension) -> queue extension, or null for all. */
    public function queueExtensionFilter($domainUuid, $queue)
    {
        if ($queue === null || $queue === "0" || $queue === 0 || $queue === "") {
            return null;
        }
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-/i', (string) $queue);
        $vQueue = VCallCenterQueues::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND " . ($isUuid ? "call_center_queue_uuid" : "queue_extension") . " = :value:",
            "bind" => ["domain_uuid" => $domainUuid, "value" => (string) $queue],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        return $vQueue ? $vQueue->queue_extension : (string) $queue;
    }

    /**
     * Detail queue figures, one row per local day and queue, computed from the call records
     * (q_ringq_cdr: one row per call; node records the queue outcome in last_arg). The old
     * q_call_queues table counted every NORMAL_CLEARING hangup as answered and never stored
     * ring or talk time, so it is not used any more.
     *
     * Outcome: transferred / answered by an agent -> answered; "Queue Caller Abandoned" ->
     * abandoned; "Queue Missed" / failed / TIMEOUT (no agent took it) -> missed.
     * Ring time: answered = waitsec; abandoned = time the caller waited (billsec, the queue
     * answers the caller); missed = duration. Talk time: billsec of answered calls.
     * All times in seconds. Used by the screen, the CSV download and the scheduled report.
     *
     * @return array ['rows' => [...], 'count' => groups]
     */
    public function detailQueueStats($domainUuid, $timezone, $queueExtension = null, $startStamp = 0, $endStamp = 0, $limit = null, $offset = 0, $queueSearch = null)
    {
        try {
            new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $timezone = 'UTC';
        }
        $where = "c.domain_uuid = CAST(:domain AS uuid) AND c.cc_queue IS NOT NULL AND c.cc_queue <> ''";
        // PDO needs a distinct name for every placeholder, hence domain/domain2.
        $bind = ["domain" => $domainUuid, "domain2" => $domainUuid, "tz" => $timezone];
        if ($queueExtension !== null && $queueExtension !== '' && $queueExtension !== '0') {
            $where .= " AND split_part(c.cc_queue, '@', 1) = :qext";
            $bind["qext"] = (string) $queueExtension;
        }
        if (intval($startStamp) > 0) {
            $where .= " AND c.start_epoch >= :start";
            $bind["start"] = intval($startStamp);
        }
        if (intval($endStamp) > 0) {
            $where .= " AND c.start_epoch <= :end";
            $bind["end"] = intval($endStamp);
        }
        $searchWhere = "";
        if ($queueSearch !== null && trim((string) $queueSearch) !== '') {
            $searchWhere = " WHERE (q.queue_name ILIKE :search1 OR g.qext ILIKE :search2)";
            $bind["search1"] = $bind["search2"] = "%" . trim((string) $queueSearch) . "%";
        }

        $q = self::queueCallSql();
        $grouped = "
            WITH calls AS (
                SELECT CAST(to_timestamp(c.start_epoch) AT TIME ZONE :tz AS date) AS day,
                       split_part(c.cc_queue, '@', 1) AS qext,
                       {$q['answer_wait']} AS answer_wait,
                       {$q['talk']} AS talk,
                       CASE
                           WHEN {$q['transferred']} THEN 'answered'
                           WHEN c.last_arg ILIKE '%Abandoned%' OR c.cc_cause = 'cancel' THEN 'abandoned'
                           WHEN c.last_arg ILIKE '%Missed%' OR c.last_arg ILIKE '%Failed%' OR c.hangup_cause = 'TIMEOUT' THEN 'missed'
                           WHEN c.cc_agent IS NOT NULL AND CAST(c.cc_agent AS text) <> '' THEN 'answered'
                           WHEN c.last_arg ILIKE '%Answered%' OR c.last_arg ILIKE '%Attended%' THEN 'answered'
                           ELSE 'missed'
                       END AS outcome,
                       COALESCE(c.waitsec, 0) AS waitsec,
                       COALESCE(c.billsec, 0) AS billsec,
                       COALESCE(c.duration, 0) AS duration
                  FROM q_ringq_cdr c
                 WHERE $where{$q['skip']}
            ), g AS (
                SELECT day, qext,
                       COUNT(*) AS total_call,
                       SUM(CASE WHEN outcome = 'missed' THEN 1 ELSE 0 END) AS missed_call,
                       SUM(CASE WHEN outcome = 'abandoned' THEN 1 ELSE 0 END) AS abandoned_call,
                       SUM(CASE WHEN outcome = 'answered' THEN 1 ELSE 0 END) AS answered_call,
                       SUM(CASE outcome
                               WHEN 'answered' THEN answer_wait
                               WHEN 'abandoned' THEN GREATEST(waitsec, billsec)
                               ELSE GREATEST(waitsec, duration)
                           END) AS waiting_time,
                       SUM(CASE WHEN outcome = 'answered' THEN talk ELSE 0 END) AS talking_time
                  FROM calls
                 GROUP BY day, qext
            )";
        $from = " FROM g LEFT JOIN v_call_center_queues q
                    ON q.domain_uuid = CAST(:domain2 AS uuid) AND q.queue_extension = g.qext" . $searchWhere;

        $countRow = $this->db->fetchOne($grouped . " SELECT COUNT(*) AS n" . $from, \Phalcon\Db\Enum::FETCH_ASSOC, $bind);
        $sql = $grouped . " SELECT g.*, q.queue_name" . $from . " ORDER BY g.day DESC, g.qext";
        if ($limit !== null) {
            $sql .= " LIMIT " . intval($limit) . " OFFSET " . max(0, intval($offset));
        }
        $rows = [];
        foreach ($this->db->fetchAll($sql, \Phalcon\Db\Enum::FETCH_ASSOC, $bind) as $r) {
            $total = (int) $r["total_call"];
            $answered = (int) $r["answered_call"];
            $waiting = (int) round((float) $r["waiting_time"]);
            $talking = (int) round((float) $r["talking_time"]);
            $rows[] = [
                "insert_date" => date("m/d/Y", strtotime($r["day"])),
                "queue_uuid" => ($r["queue_name"] !== null ? $r["queue_name"] : "Queue") . " (" . $r["qext"] . ")",
                "queue_extension" => $r["qext"],
                "total_call" => $total,
                "missed_call" => (int) $r["missed_call"],
                "abandoned_call" => (int) $r["abandoned_call"],
                "answered_call" => $answered,
                "percent_serviced" => $total > 0 ? round($answered * 100 / $total, 2) : 0,
                "waiting_time" => $waiting,
                "waiting_mean" => $total > 0 ? round($waiting / $total, 2) : 0,
                "talking_time" => $talking,
                "talking_mean" => $answered > 0 ? round($talking / $answered, 2) : 0,
                // No callback data is recorded anywhere yet.
                "successful_callbacks" => 0,
            ];
        }
        return ["rows" => $rows, "count" => (int) ($countRow["n"] ?? 0)];
    }

    /** "1:02:05" / "4:07" style duration for CSV columns. */
    public static function formatSeconds($seconds)
    {
        $seconds = (int) round((float) $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $sec = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%d:%02d', $m, $sec);
    }

    /** Header + rows for the Detail queue CSV (download and scheduled report). */
    public static function detailQueueCsvRows($rows)
    {
        $header = ["Date", "Queue", "Calls total", "Calls missed", "Calls abandoned", "Calls answered", "% Calls serviced",
            "Ring time total", "Ring time mean", "Talk time total", "Talk time mean", "Successful callbacks"];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                $r["insert_date"], $r["queue_uuid"], $r["total_call"], $r["missed_call"], $r["abandoned_call"], $r["answered_call"],
                number_format($r["percent_serviced"], 2) . "%",
                self::formatSeconds($r["waiting_time"]), self::formatSeconds($r["waiting_mean"]),
                self::formatSeconds($r["talking_time"]), self::formatSeconds($r["talking_mean"]),
                $r["successful_callbacks"],
            ];
        }
        return [$header, $out];
    }

    public function generateDetailQueue($context, $filters)
    {
        $timezone = $context['timezone'] ?? date_default_timezone_get();
        $queueExt = $this->queueExtensionFilter($context['domain_uuid'], $filters['selectQueue'] ?? "0");
        $stats = $this->detailQueueStats($context['domain_uuid'], $timezone, $queueExt,
            intval($filters['selectStartDate'] ?? 0), intval($filters['selectEndDate'] ?? 0));
        list($header, $rows) = self::detailQueueCsvRows($stats['rows']);
        return $this->writeCsv($rows, $header);
    }

    public function generateCallRatings($context, $filters)
    {
        $domain_uuid = $context['domain_uuid'];
        $timezone = $context['timezone'] ?? date_default_timezone_get();
        
        $startStamp = intval($filters['selectStartDate'] ?? 0);
        $endStamp = intval($filters['selectEndDate'] ?? 0);
        $agent = $filters['selectAgent'] ?? 0;
        $queue = $filters['selectQueue'] ?? "";
        $rating = $filters['selectRating'] ?? 0;

        $conditions = "domain_uuid = :domain_uuid:";
        $bind = ["domain_uuid" => $domain_uuid];

        if (!empty($agent) && $agent != "0") {
             $conditions .= " AND extension_uuid ILIKE :agent:";
             $bind["agent"] = "%" . $agent . "%";
        }
        if (!empty($rating) && $rating != 0) {
             $conditions .= " AND rating = :rating:";
             $bind["rating"] = $rating;
        }
        if (!empty($queue) && $queue != "0") {
             $conditions .= " AND queue_name ILIKE :queue:";
             $bind["queue"] = "%" . $queue . "%";
        }

        if ($startStamp > 0 && $endStamp > 0) {
            $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
            $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
            $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);
        }

        $items = QCallRatings::find([
             "conditions" => $conditions,
             "bind" => $bind,
             "bindTypes" => [Column::BIND_PARAM_STR],
             "limit" => 5000,
             "order" => "insert_date DESC"
        ]);

        $content = [];
        foreach ($items as $item) {
             $row = [
                 $this->formatTimezoneString($item->insert_date, "m/d/Y", $timezone),
                 $this->formatTimezoneString($item->insert_date, "h:i:s A", $timezone),
                 $item->caller_number,
                 $item->caller_name,
                 $item->queue_name,
                 $item->rating,
                 $item->extension_uuid
             ];
             $content[] = $row;
        }

        return $this->writeCsv($content, ["Date", "Time", "Caller Number", "Caller Name", "Queue Name", "Rating", "Extension"]);
    }

    public function generateWrapUp($context, $filters)
    {
        $domain_uuid = $context['domain_uuid'];
        $timezone = $context['timezone'] ?? date_default_timezone_get();
        
        $startStamp = intval($filters['selectStartDate'] ?? 0);
        $endStamp = intval($filters['selectEndDate'] ?? 0);
        $agent = $filters['selectAgent'] ?? 0;
        $wrapUp = $filters['selectWrapUp'] ?? "";

        $conditions = "domain_uuid = :domain_uuid:";
        $bind = ["domain_uuid" => $domain_uuid];

        if (!empty($agent) && $agent != "0") {
             $conditions .= " AND extension ILIKE :agent:";
             $bind["agent"] = "%" . $agent . "%";
        }
        if (!empty($wrapUp) && $wrapUp != "0") {
             $conditions .= " AND wrapup_code ILIKE :wrapUp:";
             $bind["wrapUp"] = "%" . $wrapUp . "%";
        }

        if ($startStamp > 0 && $endStamp > 0) {
            $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
            $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
            $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);
        }

        $items = QWrapupnotesList::find([
             "conditions" => $conditions,
             "bind" => $bind,
             "bindTypes" => [Column::BIND_PARAM_STR],
             "limit" => 5000,
             "order" => "insert_date DESC"
        ]);

        $content = [];
        foreach ($items as $item) {
             $row = [
                 $this->formatTimezoneString($item->insert_date, "m/d/Y", $timezone),
                 $this->formatTimezoneString($item->insert_date, "h:i:s A", $timezone),
                 $item->username,
                 $item->extension,
                 isset($item->wrapup_code) ? ucfirst($item->wrapup_code) : "",
                 $item->content,
                 $item->customer,
                 $item->contact
             ];
             $content[] = $row;
        }

        return $this->writeCsv($content, ["Date", "Time", "Username", "Extension", "Wrapup Note", "Content", "Customer", "Contact"]);
    }

    public function generateAuxCodes($context, $filters)
    {
        $domain_uuid = $context['domain_uuid'];
        $timezone = $context['timezone'] ?? date_default_timezone_get();
        
        $startStamp = intval($filters['selectStartDate'] ?? 0);
        $endStamp = intval($filters['selectEndDate'] ?? 0);
        $agent = $filters['selectAgent'] ?? 0;
        $queue = $filters['selectQueue'] ?? 0;
        $auxcode = $filters['selectAuxcode'] ?? "";

        $conditions = "domain_uuid = :domain_uuid:";
        $bind = ["domain_uuid" => $domain_uuid];

        if (!empty($agent) && $agent != "0") {
             $conditions .= " AND extension ILIKE :agent:";
             $bind["agent"] = "%" . $agent . "%";
        }
        if (!empty($queue) && $queue != "0") {
             $conditions .= " AND extension ILIKE :queue:";
             $bind["queue"] = "%" . $queue . "%";
        }
        if (!empty($auxcode) && $auxcode != "0") {
             $conditions .= " AND auxcode_status ILIKE :auxcode:";
             $bind["auxcode"] = "%" . urldecode($auxcode) . "%";
        }

        if ($startStamp > 0 && $endStamp > 0) {
            $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
            $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
            $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);
        }

        $items = QAuxcodesList::find([
             "conditions" => $conditions,
             "bind" => $bind,
             "bindTypes" => [Column::BIND_PARAM_STR],
             "limit" => 5000,
             "order" => "insert_date DESC"
        ]);

        $content = [];
        foreach ($items as $item) {
             $extensionParts = explode(" | ", $item->extension);
             $ext = current($extensionParts);
             $q = next($extensionParts) == "0" ? "All" : current($extensionParts);

             $row = [
                 $this->formatTimezoneString($item->insert_date, "m/d/Y", $timezone),
                 $this->formatTimezoneString($item->insert_date, "h:i:s A", $timezone),
                 $item->username,
                 $ext,
                 $q,
                 ucfirst($item->auxcode_status)
             ];
             $content[] = $row;
        }

        return $this->writeCsv($content, ["Date", "Time", "Username", "Extension", "Queue", "Auxcode"]);
    }

    public function generateActivity($context, $filters)
    {
        $domain_uuid = $context['domain_uuid'];
        $timezone = $context['timezone'] ?? date_default_timezone_get();
        
        $startStamp = intval($filters['selectStartDate'] ?? 0);
        $endStamp = intval($filters['selectEndDate'] ?? 0);
        $agent = $filters['selectAgent'] ?? ""; // Username in controller

        $conditions = "domain_uuid = :domain_uuid:";
        $bind = ["domain_uuid" => $domain_uuid];

        if (!empty($agent) && $agent != "0") {
             $conditions .= " AND username ILIKE :agent:";
             $bind["agent"] = "%" . $agent . "%";
        }

        if ($startStamp > 0 && $endStamp > 0) {
            $conditions .= " AND timestamp BETWEEN :startStamp: AND :endStamp:";
            // With the offset, so the database reads the same instant whatever its own timezone.
            $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
            $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);
        }

        $items = VUserLogs::find([
             "conditions" => $conditions,
             "bind" => $bind,
             "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
             "limit" => 5000,
             "order" => "insert_date DESC"
        ]);

        $content = [];
        foreach ($items as $item) {
             $row = [
                 $this->formatTimezoneString($item->timestamp, "m/d/Y", $timezone),
                 $this->formatTimezoneString($item->timestamp, "h:i:s A", $timezone),
                 $item->username,
                 ucfirst($item->type),
                 ucfirst((string) $item->result),
                 $item->remote_address,
                 $item->user_agent
             ];
             $content[] = $row;
        }

        return $this->writeCsv($content, ["Date", "Time", "Username", "Type", "Result", "IP address", "User Agent"]);
    }
}
