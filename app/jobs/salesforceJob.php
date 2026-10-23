<?php
/**
 * salesforceJob.php — the Salesforce integration.
 *
 * Consolidated out of worker.php: two job handlers and ten sf_* helpers.
 * MainTask::salesforceTokenAction stays where it is — it is the hourly OAuth
 * refresh, cron calls it by name, and an action that stops existing fails
 * silently every minute.
 *
 * Note sf_getTimeZone() is the pattern the Cliniko work was later modelled on:
 * read the tenant's own zone rather than date_default_timezone_get(), which on
 * these boxes is whatever the host happens to be set to.
 *
 * Declares functions only. No top-level statements: this is loaded while the
 * worker is still wiring itself up, before $redis exists.
 */

// `use` is FILE-SCOPED: the imports in worker.php and MainTask.php do NOT reach a
// required file. Without these lines the code below resolves e.g. `Column` to the
// global `\Column`, which does not exist, and every tick dies with
// "Class 'Column' not found" -- php -l cannot see it and loading the file cannot
// either, because it only fails when the line actually runs.
use Phalcon\Db\Column;

function handleSalesforceQueueKey($redis, &$tempStorage, &$phoneTimers) {
    $currentTime = time();

    foreach ($phoneTimers as $contactNumber => $startTime) {
        if ($currentTime - $startTime >= 15) {
            $batchData = array_map('json_encode', $tempStorage[$contactNumber] ?? []);
            if (!empty($batchData)) {
                $time = time();
                $id = uniqid();
                $sfTaskKey = "sf-tasks:$contactNumber:$time:$id";
                $redis->rPush($sfTaskKey, ...$batchData);
                $redis->expire($sfTaskKey, 86400);
                $redis->sAdd("sf-tasks-index", $sfTaskKey);
            }
            unset($tempStorage[$contactNumber]);
            unset($phoneTimers[$contactNumber]);
        }
    }

    $callKeys = $redis->sMembers("sf-keys-index");
    foreach ($callKeys as $callVal) {
        $queues = $redis->lrange($callVal, 0, -1);
        foreach ($queues as $queueVal) {
            $data = json_decode($queueVal, true);
            if ($data) {
                $contactNumber = $data['contactNumber'];
                if (!isset($tempStorage[$contactNumber])) {
                    $tempStorage[$contactNumber] = [];
                    $phoneTimers[$contactNumber] = time();
                }
                $tempStorage[$contactNumber][] = $data;
            }
        }
        $redis->del($callVal);
        $redis->sRem("sf-keys-index", $callVal);
    }
}

function handleSalesforceSync($redis, &$count) {
    $vDomains = VDomains::find(["conditions" => "domain_enabled = true"]);
    $keys = $redis->sMembers("sf-tasks-index");
    $results = [];

    foreach ($keys as $key) {
        $queue = $redis->lrange($key, 0, -1);
        // Key expired (TTL) or already drained — drop the stale index
        // reference so we stop re-reading a key that no longer exists.
        if (empty($queue)) {
            $redis->sRem("sf-tasks-index", $key);
            continue;
        }
        $uniqueStatuses = ['answer' => null, 'outbound' => null, 'reject' => null];
        foreach ($queue as $item) {
            $data = json_decode($item, true);
            if (isset($data['status'])) {
                $status = $data['status'];
                if (in_array($status, ['answer', 'outbound', 'reject']) && !$uniqueStatuses[$status]) {
                    $uniqueStatuses[$status] = $data;
                }
            }
        }
        if (!in_array(true, array_map(fn($status) => $status !== null, $uniqueStatuses))) {
            $missqueue = $redis->lrange($key, 0, -1);
            $missData = json_decode($missqueue[0], true);
            $results[$key]['misscall'] = $missData;
        } else {
            foreach ($uniqueStatuses as $status => $data) {
                if ($data !== null) $results[$key][$status] = $data;
            }
        }
    }

    foreach ($results as $sfKey => $sfKeyVal) {
        if (isset($sfKeyVal['answer'])) {
            if ($vDomains->count()) {
                foreach ($vDomains as $vDomain) {
                    $xmlCdrTable = sf_xmlCdrTable($vDomain, $sfKeyVal['answer']['contactNumber'], 'answer');
                    if ($xmlCdrTable->count()) {
                        $vXmlCdrRecord = $xmlCdrTable->getFirst();
                        if ($vXmlCdrRecord['domain_name'] !== $sfKeyVal['answer']['domain_name']) continue;
                        $ownerId = $sfKeyVal['answer']['sfUserId'] ?? '';
                        $callDirection = ucfirst($vXmlCdrRecord['direction']);
                        $integration = $redis->hgetall('integration:' . $sfKeyVal['answer']['domain_name'] . '-salesforce');
                        $hangupCause = sf_hangUpCause($vXmlCdrRecord);
                        $timeDetail = sf_getTimeZone($vXmlCdrRecord, $sfKeyVal['answer']);
                        $formattedDuration = sf_formatDuration($vXmlCdrRecord['duration']);
                        $searchResult = sf_sfSearchQuery($sfKeyVal['answer'], $integration);
                        $isMultiple = sf_isMultiple($sfKeyVal['answer'], $integration);
                        $description = sf_description($sfKeyVal['answer'], $vXmlCdrRecord, $hangupCause['subject']);
                        $recordingDes = $vXmlCdrRecord['record_name'] && $hangupCause['showRecording']
                            ? "Time Zone: " . $sfKeyVal['answer']['timeZone'] . "\n                Start Time: " . $timeDetail['start'] . "\n                End Time: " . $timeDetail['end'] . "\n                Duration: $formattedDuration\n                Recording: https://" . $sfKeyVal['answer']['domain_name'] . ":8443/temp/" . $vXmlCdrRecord['record_name']
                            : "";
                        if ($searchResult) {
                            $totalLog = $count++;
                            $searchData = $searchResult['data'] ?? null;
                            $attributes = $searchData['attributes']['type'] ?? '';
                            $jsonPayload = array_filter([
                                'OwnerId' => $ownerId,
                                'WhoId' => !$isMultiple && $sfKeyVal['answer']['objectType'] !== 'Account' && $attributes !== 'Account' ? ($searchData['Id'] ?? null) : null,
                                'WhatId' => !$isMultiple ? (!empty($sfKeyVal['answer']['objectType']) ? ($sfKeyVal['answer']['objectType'] !== 'Account' ? ($searchData['AccountId'] ?? null) : ($searchData['Id'] ?? null)) : ($searchData['AccountId'] ?? null)) : null,
                                "Subject" => "RingQ Call " . $sfKeyVal['answer']['contactNumber'] . " ($callDirection" . $hangupCause['subject'] . ")",
                                "Status" => "Completed", "Priority" => "Normal", "TaskSubtype" => "Call",
                                "CallDurationInSeconds" => $vXmlCdrRecord['duration'],
                                "CallType" => $vXmlCdrRecord['direction'] !== 'local' ? $vXmlCdrRecord['direction'] : 'inbound',
                                "Description" => "\n                    $description\n                    $recordingDes\n                "
                            ]);
                            sf_sfAPITask($integration, $sfKey, $jsonPayload, $vXmlCdrRecord, $redis, ['type' => 'answer', 'id' => $totalLog]);
                        }
                    }
                }
            }
        }
        if (isset($sfKeyVal['reject'])) {
            if ($vDomains->count()) {
                foreach ($vDomains as $vDomain) {
                    $xmlCdrTable = sf_xmlCdrTable($vDomain, $sfKeyVal['reject']['contactNumber'], 'reject');
                    if ($xmlCdrTable->count()) {
                        $vXmlCdrRecord = $xmlCdrTable->getFirst();
                        if ($vXmlCdrRecord['domain_name'] !== $sfKeyVal['reject']['domain_name']) continue;
                        $ownerId = $sfKeyVal['reject']['sfUserId'] ?? '';
                        $hangupCause = sf_hangUpCause($vXmlCdrRecord);
                        $callDirection = ucfirst($vXmlCdrRecord['direction']);
                        $integration = $redis->hgetall('integration:' . $sfKeyVal['reject']['domain_name'] . '-salesforce');
                        $searchResult = sf_sfSearchQuery($sfKeyVal['reject'], $integration);
                        $isMultiple = sf_isMultiple($sfKeyVal['reject'], $integration);
                        $description = sf_description($sfKeyVal['reject'], $vXmlCdrRecord, $hangupCause['subject']);
                        if ($searchResult) {
                            $totalLog = $count++;
                            $searchData = $searchResult['data'] ?? null;
                            $attributes = $searchData['attributes']['type'] ?? '';
                            $jsonPayload = array_filter([
                                'OwnerId' => $ownerId,
                                'WhoId' => !$isMultiple && $sfKeyVal['answer']['objectType'] !== 'Account' && $attributes !== 'Account' ? ($searchData['Id'] ?? null) : null,
                                'WhatId' => !$isMultiple ? (!empty($sfKeyVal['answer']['objectType']) ? ($sfKeyVal['answer']['objectType'] !== 'Account' ? ($searchData['AccountId'] ?? null) : ($searchData['Id'] ?? null)) : ($searchData['AccountId'] ?? null)) : null,
                                "Subject" => "RingQ Call " . $sfKeyVal['reject']['contactNumber'] . " ($callDirection Rejected)",
                                "Status" => "Completed", "Priority" => "Normal", "TaskSubtype" => "Call",
                                "CallDurationInSeconds" => $vXmlCdrRecord['duration'],
                                "CallType" => $vXmlCdrRecord['direction'] !== 'local' ? $vXmlCdrRecord['direction'] : 'inbound',
                                "Description" => $description
                            ]);
                            sf_sfAPITask($integration, $sfKey, $jsonPayload, $vXmlCdrRecord, $redis, ['type' => 'reject', 'id' => $totalLog]);
                        }
                    }
                }
            }
        }
        if (isset($sfKeyVal['outbound'])) {
            if ($vDomains->count()) {
                foreach ($vDomains as $vDomain) {
                    $xmlCdrTable = sf_xmlCdrTable($vDomain, $sfKeyVal['outbound']['contactNumber'], 'outbound');
                    if ($xmlCdrTable->count()) {
                        $vXmlCdrRecord = $xmlCdrTable->getFirst();
                        $callDirection = ucfirst($vXmlCdrRecord['direction']);
                        $integration = $redis->hgetall('integration:' . $sfKeyVal['outbound']['domain_name'] . '-salesforce');
                        $hangupCause = sf_hangUpCause($vXmlCdrRecord);
                        $timeDetail = sf_getTimeZone($vXmlCdrRecord, $sfKeyVal['outbound']);
                        $formattedDuration = sf_formatDuration($vXmlCdrRecord['duration']);
                        $searchResult = sf_sfSearchQueryOutbound($sfKeyVal['outbound'], $integration);
                        $description = sf_description($sfKeyVal['outbound'], $vXmlCdrRecord, $hangupCause['subject']);
                        $recordingDes = $vXmlCdrRecord['record_name'] && $hangupCause['showRecording']
                            ? "Time Zone: " . $sfKeyVal['outbound']['timeZone'] . "\n                Start Time: " . $timeDetail['start'] . "\n                End Time: " . $timeDetail['end'] . "\n                Duration: $formattedDuration\n                Recording: https://" . $sfKeyVal['outbound']['domain_name'] . ":8443/temp/" . $vXmlCdrRecord['record_name']
                            : "";
                        $totalLog = $count++;
                        $searchData = $searchResult['data'] ?? null;
                        $jsonPayload = array_filter([
                            'OwnerId' => $sfKeyVal['outbound']['sfUserId'] ?? null,
                            'WhoId' => !empty($sfKeyVal['outbound']['recordId']) ? ($searchData['Id'] ?? null) : null,
                            'WhatId' => !empty($sfKeyVal['outbound']['recordId']) ? ($searchData['AccountId'] ?? null) : null,
                            "Subject" => "RingQ Call " . $sfKeyVal['outbound']['contactNumber'] . " (" . $callDirection . $hangupCause['subject'] . ")",
                            "Status" => "Completed", "Priority" => "Normal", "TaskSubtype" => "Call",
                            "CallDurationInSeconds" => $vXmlCdrRecord['duration'],
                            "CallType" => $vXmlCdrRecord['direction'] !== 'local' ? $vXmlCdrRecord['direction'] : 'inbound',
                            "Description" => "\n                $description\n                $recordingDes\n            "
                        ]);
                        sf_sfAPITask($integration, $sfKey, $jsonPayload, $vXmlCdrRecord, $redis, ['type' => 'outbound', 'id' => $totalLog]);
                    }
                }
            }
        }
        if (isset($sfKeyVal['misscall'])) {
            if ($vDomains->count()) {
                foreach ($vDomains as $vDomain) {
                    $xmlCdrTable = sf_xmlCdrTable($vDomain, $sfKeyVal['misscall']['contactNumber'], 'misscall');
                    if ($xmlCdrTable->count()) {
                        $vXmlCdrRecord = $xmlCdrTable->getFirst();
                        if ($vXmlCdrRecord['domain_name'] !== $sfKeyVal['misscall']['domain_name']) continue;
                        $callDirection = ucfirst($vXmlCdrRecord['direction']);
                        $integration = $redis->hgetall('integration:' . $sfKeyVal['misscall']['domain_name'] . '-salesforce');
                        $hangupCause = sf_hangUpCause($vXmlCdrRecord);
                        $description = sf_description($sfKeyVal['misscall'], $vXmlCdrRecord, $hangupCause['subject']);
                        $searchResult = sf_sfSearchQuery($sfKeyVal['misscall'], $integration);
                        $isMultiple = sf_isMultiple($sfKeyVal['misscall'], $integration);
                        if ($searchResult) {
                            $totalLog = $count++;
                            $searchData = $searchResult['data'] ?? null;
                            $attributes = $searchData['attributes']['type'] ?? '';
                            $jsonPayload = array_filter([
                                'WhoId' => !$isMultiple && $sfKeyVal['misscall']['objectType'] !== 'Account' && $attributes !== 'Account' ? ($searchData['Id'] ?? null) : null,
                                'WhatId' => !$isMultiple ? (!empty($sfKeyVal['misscall']['objectType']) ? ($sfKeyVal['misscall']['objectType'] !== 'Account' ? ($searchData['AccountId'] ?? null) : ($searchData['Id'] ?? null)) : ($searchData['AccountId'] ?? null)) : null,
                                "Subject" => "RingQ Call " . $sfKeyVal['misscall']['contactNumber'] . " (" . $callDirection . $hangupCause['subject'] . ")",
                                "Status" => "Completed", "Priority" => "Normal", "TaskSubtype" => "Call",
                                "CallDurationInSeconds" => $vXmlCdrRecord['duration'],
                                "CallType" => $vXmlCdrRecord['direction'] !== 'local' ? $vXmlCdrRecord['direction'] : 'inbound',
                                "Description" => $description
                            ]);
                            sf_sfAPITask($integration, $sfKey, $jsonPayload, $vXmlCdrRecord, $redis, ['type' => 'misscall', 'id' => $totalLog]);
                        }
                    }
                }
            }
        }
    }
}

function sf_formatDuration($seconds) {
    if ($seconds < 60) return "$seconds seconds";
    elseif ($seconds < 3600) return gmdate("i:s", $seconds);
    else return gmdate("H:i:s", $seconds);
}

function sf_xmlCdrTable($vDomain, $externalNumber, $direction = null) {
    $conditions = 'domain_uuid = :domain_uuid: AND end_epoch >= :end_epoch: AND hangup_cause != :hangup_cause: AND (caller_id_number = :caller_id_number: OR caller_destination = :caller_destination:)';
    if ($direction) {
        if ($direction == 'answer') $conditions .= " AND (hangup_cause = 'NORMAL_CLEARING' AND sip_hangup_disposition = 'send_bye' OR hangup_cause = 'NORMAL_CLEARING' AND sip_hangup_disposition = 'recv_bye')";
        if ($direction == 'reject') $conditions .= " AND (hangup_cause = 'NORMAL_CLEARING' AND sip_hangup_disposition = 'send_cancel' OR hangup_cause = 'CALL_REJECTED' AND sip_hangup_disposition = 'recv_refuse')";
        if ($direction == 'misscall') $conditions .= " AND (hangup_cause = 'ORIGINATOR_CANCEL' AND sip_hangup_disposition = 'recv_cancel' OR hangup_cause = 'ORIGINATOR_CANCEL' AND sip_hangup_disposition = 'send_cancel' OR hangup_cause = 'NORMAL_CLEARING' AND sip_hangup_disposition = 'send_refuse' OR hangup_cause = 'NO_ANSWER' AND sip_hangup_disposition = 'send_cancel')";
        if ($direction == 'outbound') $conditions .= " AND direction = 'outbound'";
    }
    return QRingqCdr::find([
        "columns" => "sip_hangup_disposition, xml_cdr_uuid, domain_name, direction, caller_id_name, caller_id_number, caller_destination, record_path, record_name, hangup_cause, insert_date, start_stamp, end_stamp, duration",
        "conditions" => $conditions,
        "bind" => ["caller_id_number" => $externalNumber, "caller_destination" => $externalNumber, "domain_uuid" => $vDomain->domain_uuid, "end_epoch" => time() - 20, "hangup_cause" => "LOSE_RACE"],
        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
        "order" => "insert_date ASC", "limit" => 1
    ]);
}

function sf_hangUpCause($vXmlCdrRecord) {
    $showRecording = null; $subject = '';
    $hangupCause = $vXmlCdrRecord['hangup_cause'] . ' ' . $vXmlCdrRecord['sip_hangup_disposition'];
    switch ($hangupCause) {
        case 'NORMAL_CLEARING send_bye': case 'NORMAL_CLEARING recv_bye': $subject = ' Answered'; $showRecording = true; break;
        case 'NORMAL_CLEARING send_cancel': case 'CALL_REJECTED recv_refuse': $subject = ' Rejected'; $showRecording = false; break;
        case 'ORIGINATOR_CANCEL recv_cancel': case 'ORIGINATOR_CANCEL send_cancel': $subject = ' Missed'; $showRecording = false; break;
        case 'NORMAL_CLEARING send_refuse': $subject = $vXmlCdrRecord->direction == 'inbound' ? ' Rejected' : ' Missed'; $showRecording = false; break;
        case 'INCOMPATIBLE_DESTINATION send_refuse': case 'USER_NOT_REGISTERED send_refuse': case 'OUTGOING_CALL_BARRED send_refuse': case 'RECOVERY_ON_TIMER_EXPIRE': $subject = ' Failed'; $showRecording = false; break;
        case 'NO_ANSWER send_cancel': $subject = ' Missed'; $showRecording = false; break;
        default: $subject = ' Missed'; $showRecording = false; break;
    }
    return compact('subject', 'showRecording');
}

function sf_sfSearchQuery($sfKeyVal, $integration) {
    $searchOrder = explode('/', preg_replace('/\s*\/\s*/', '/', $integration['lookupOrder']));
    $queries = [
        'Contact' => "SELECT Id, OwnerId, AccountId, CreatedDate FROM Contact WHERE (Phone = '{$sfKeyVal['contactNumber']}' OR MobilePhone = '{$sfKeyVal['contactNumber']}') ORDER BY CreatedDate DESC",
        'Lead' => "SELECT Id, OwnerId, CreatedDate FROM Lead WHERE Phone = '{$sfKeyVal['contactNumber']}' ORDER BY CreatedDate DESC",
        'Account' => "SELECT Id, OwnerId, CreatedDate FROM Account WHERE (Phone = '{$sfKeyVal['contactNumber']}' OR PersonMobilePhone = '{$sfKeyVal['contactNumber']}' OR PersonOtherPhone = '{$sfKeyVal['contactNumber']}') ORDER BY CreatedDate DESC"
    ];
    foreach ($searchOrder as $object) {
        if (!isset($queries[$object])) continue;
        $url = $integration['url'] . "/services/data/v62.0/query/?q=" . urlencode($queries[$object]);
        $curlSearch = curl_init();
        curl_setopt($curlSearch, CURLOPT_URL, $url);
        curl_setopt($curlSearch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlSearch, CURLOPT_TIMEOUT, 30);
        curl_setopt($curlSearch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $integration["token"], 'Content-Type: application/json']);
        $data = json_decode(curl_exec($curlSearch), true);
        $httpSearchStatus = curl_getinfo($curlSearch, CURLINFO_HTTP_CODE);
        curl_close($curlSearch);
        if ($httpSearchStatus >= 200 && $httpSearchStatus < 300 && !empty($data['records'])) {
            $firstRecord = $data['records'];
            return ['status' => true, 'data' => $firstRecord[0], 'length' => count($firstRecord), 'all' => $firstRecord];
        }
    }
    return ['status' => false, 'message' => 'No records found', 'data' => [], 'length' => 0, 'all' => []];
}

function sf_sfSearchQueryOutbound($sfKeyVal, $integration) {
    $recordIdCondition = !empty($sfKeyVal['recordId']) ? "Id = '{$sfKeyVal['recordId']}' AND " : '';
    $objectType = $sfKeyVal['objectType'];
    $contactNumber = $sfKeyVal['contactNumber'];
    $baseUrl = $integration['url'] . "/services/data/v62.0/query/?q=";
    $headers = ['Authorization: Bearer ' . $integration["token"], 'Content-Type: application/json'];
    if ($objectType === 'Account') {
        $accountQuery = "SELECT Id FROM Account WHERE {$recordIdCondition} (Phone = '{$contactNumber}' OR PersonMobilePhone = '{$contactNumber}') LIMIT 1";
        $accountResponse = sf_curlRequest($baseUrl . urlencode($accountQuery), $headers);
        if (!empty($accountResponse['records'])) {
            $accountId = $accountResponse['records'][0]['Id'];
            $contactQuery = "SELECT Id, OwnerId, AccountId, CreatedDate, LastName FROM Contact WHERE AccountId = '{$accountId}' ORDER BY CreatedDate DESC LIMIT 1";
            $contactResponse = sf_curlRequest($baseUrl . urlencode($contactQuery), $headers);
            if (!empty($contactResponse['records'])) return ['status' => true, 'data' => $contactResponse['records'][0]];
        }
    }
    if ($objectType === 'Contact' || $objectType === 'Account') {
        $contactQuery = "SELECT Id, OwnerId, AccountId, CreatedDate, LastName FROM Contact WHERE {$recordIdCondition} (Phone = '{$contactNumber}' OR MobilePhone = '{$contactNumber}') ORDER BY CreatedDate DESC LIMIT 1";
        $contactResponse = sf_curlRequest($baseUrl . urlencode($contactQuery), $headers);
        if (!empty($contactResponse['records'])) return ['status' => true, 'data' => $contactResponse['records'][0]];
    }
    return ['status' => false, 'data' => []];
}

function sf_sfAPITask($integration, $sfKey, $jsonPayload, $vXmlCdrRecord, $redis, $info) {
    $ch = curl_init($integration["url"] . "/services/data/v62.0/sobjects/Task");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonPayload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $integration["token"], 'Content-Type: application/json']);
    $resTaskJsonStr = curl_exec($ch);
    $taskApiStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $taskRes = json_decode($resTaskJsonStr, true);
    if ($taskApiStatus >= 200 && $taskApiStatus < 300) {
        if (!is_null($vXmlCdrRecord['record_path'])) {
            copy($vXmlCdrRecord['record_path'] . "/" . $vXmlCdrRecord['record_name'], "/var/www/ringq/public/temp/" . $vXmlCdrRecord['record_name']);
        }
        echo json_encode(['result' => 'success', 'data' => $info], JSON_PRETTY_PRINT);
        $redis->del($sfKey);
        $redis->sRem("sf-tasks-index", $sfKey);
    } else {
        echo json_encode(['result' => 'error', 'content' => $taskRes], JSON_PRETTY_PRINT);
    }
}

function sf_description($sfKeyVal, $vXmlCdrRecord, $subject) {
    switch ($vXmlCdrRecord->direction) {
        case 'inbound':
            $toDescription = trim($subject) !== 'Missed' ? "to " . $sfKeyVal['queueExtension'] : '';
            return "Inbound$subject call from " . $vXmlCdrRecord['caller_id_number'] . " $toDescription";
        case 'outbound':
            return "Outbound$subject call {$vXmlCdrRecord['caller_id_number']} from {$vXmlCdrRecord['caller_destination']}";
        default:
            return "Local$subject call between {$vXmlCdrRecord['caller_id_number']} and {$vXmlCdrRecord['caller_destination']}";
    }
}

function sf_getTimeZone($vXmlCdrRecord, $sfKeyVal) {
    $startUtc = \DateTime::createFromFormat('Y-m-d H:i:sP', $vXmlCdrRecord['start_stamp'], new \DateTimeZone('UTC'));
    $endUtc = \DateTime::createFromFormat('Y-m-d H:i:sP', $vXmlCdrRecord['end_stamp'], new \DateTimeZone('UTC'));
    if (!$startUtc || !$endUtc) throw new Exception("Invalid timestamp format.");
    $timeZone = new \DateTimeZone($sfKeyVal['timeZone']);
    $start = $startUtc->setTimezone($timeZone)->format('h:i:s A');
    $end = $endUtc->setTimezone($timeZone)->format('h:i:s A');
    return compact('start', 'end');
}

function sf_isMultiple($sfKeyVal, $integration) {
    $total = 0;
    $queries = [
        "SELECT COUNT(Id) FROM Contact WHERE (Phone = '{$sfKeyVal['contactNumber']}' OR MobilePhone = '{$sfKeyVal['contactNumber']}') AND IsPersonAccount = false",
        "SELECT COUNT(Id) FROM Lead WHERE Phone = '{$sfKeyVal['contactNumber']}'",
        "SELECT COUNT(Id) FROM Contact WHERE (Phone = '{$sfKeyVal['contactNumber']}' OR MobilePhone = '{$sfKeyVal['contactNumber']}') AND IsPersonAccount = true"
    ];
    foreach ($queries as $query) {
        $url = $integration['url'] . "/services/data/v62.0/query/?q=" . urlencode($query);
        $curlSearch = curl_init();
        curl_setopt($curlSearch, CURLOPT_URL, $url);
        curl_setopt($curlSearch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlSearch, CURLOPT_TIMEOUT, 30);
        curl_setopt($curlSearch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $integration["token"], 'Content-Type: application/json']);
        $data = json_decode(curl_exec($curlSearch), true);
        $httpSearchStatus = curl_getinfo($curlSearch, CURLINFO_HTTP_CODE);
        curl_close($curlSearch);
        if ($httpSearchStatus >= 200 && $httpSearchStatus < 300) $total += $data['records'][0]['expr0'];
    }
    return $total > 1;
}

function sf_curlRequest($url, $headers) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($httpStatus >= 200 && $httpStatus < 300) ? json_decode($response, true) : [];
}
