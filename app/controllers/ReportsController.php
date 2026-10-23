<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use ElephantIO\Client;
use Phalcon\Db\Column;
use Phalcon\Image\Enum;

class ReportsController extends ControllerBase
{
    public function callhistoryAction($type = 1, $page = 1, $extension = 0, $callNature = 0, $callType = 0, $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            if ($type == 3 && $this->isPlainUser($session)) {
                $type = 1;
            }
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                $vUserSetting = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone",
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                $timezone = date_default_timezone_get();
                if ($vUserSetting) {
                    $timezone = $vUserSetting->user_setting_value;
                }
                $vCallCenterAgents = VCallCenterAgents::find(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_uuid" => $session["user_uuid"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                $vCallCenterAgentArray = [];
                foreach ($vCallCenterAgents as $vCallCenterAgent) {
                    array_push($vCallCenterAgentArray, $vCallCenterAgent->call_center_agent_uuid);
                }
                $vCallCenterAgentArray = implode(",", $vCallCenterAgentArray);
                $vCallCenterAgentArray = is_array($vCallCenterAgentArray) ? $vCallCenterAgentArray : (strpos((string) $vCallCenterAgentArray, ',') !== false ? explode(",", (string) $vCallCenterAgentArray) : [(string) $vCallCenterAgentArray]);
                $timestamp = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-deletion");
                if (!isset($timestamp)) {
                    $timestamp = 0;
                }
                // CALL LOGS // AGENT, CALL TYPE (MISSED/ANSWERED), CALL NATURE (INBOUND/OUTBOUND), DATE
                if ($type == 1) {
                    $count = QRingqCdr::count(
                        [
                            "conditions" => "(caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent IN ({cc_agent:array}) OR source_number = :extension:) AND (caller_id_number != caller_destination OR missed_call = true) AND (source_number is not NULL OR missed_call = true) AND (caller_id_number != :extension: OR caller_destination != :extension: OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND start_epoch > :start_epoch:",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgentArray,
                                    "start_epoch" => $timestamp
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                        ]
                    );
                    $count2 = QRingqCdr::count(
                        [
                            "conditions" => "caller_destination = :extension: AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch:",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "start_epoch" => $timestamp
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                        ]
                    );
                    $missed = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-missed");
                    $this->redis->set($session["extension"] ."@". $session["subdomain"] ."-missed", $count2);
                    $finalCdrs = []; // Ensure $finalCdrs is initialized
                    $conditions = "(source_number is not NULL OR missed_call = true) AND domain_uuid = :domain_uuid:";
                    $bind = [
                        "domain_uuid" => $session["domain_uuid"],
                    ];
                    $bindTypes = [
                        Column::BIND_PARAM_STR
                    ];
                    // $extension = 0, $callType = 1, $callNature = 1, $startStamp = 0, $endStamp = 0
                    $extension = $session["extension"];
                    if ($extension != 0) {
                        $extension = intval($extension);
                        $vCallCenterAgent = VCallCenterAgents::findFirst(
                            [
                                "conditions" => "domain_uuid = :domain_uuid: AND agent_id = :agent_id:",
                                "bind" =>
                                    [
                                        "domain_uuid" => $session["domain_uuid"],
                                        "agent_id" => $extension,
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                            ]
                        );
                        if (!$vCallCenterAgent) {
                            $response = json_encode(
                                [
                                    "result" => "error",
                                    "content" => [],
                                    "message" => "Sorry, extension is either non-existing or already deleted.",
                                ]
                            );
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $conditions = "domain_uuid = :domain_uuid: AND (caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND (caller_id_number != caller_destination OR missed_call = true) AND (source_number is not NULL OR missed_call = true) AND (caller_id_number != :extension: OR caller_destination != :extension: OR cc_agent != :cc_agent: OR source_number = :extension:) AND start_epoch > :start_epoch:";
                        $bind = [
                            "domain_uuid" => $session["domain_uuid"],
                            "extension" => $extension,
                            "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                            "start_epoch" => $timestamp
                        ];
                        $bindTypes = [
                            Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT
                        ];
                    }
                    if ($callType != 0) {
                        // Missed call
                        if ($callType == 1) {
                            $conditions .= " AND missed_call = TRUE";
                        }
                        // Answered call
                        if ($callType == 2) {
                            $conditions .= " AND missed_call = FALSE";
                        }
                    }
                    if ($callNature != 0) {
                        // Incoming 
                        if ($callNature == 1) {
                            $conditions .= " AND (direction = 'inbound' OR direction = 'local')";
                        }
                        // Outgoing
                        if ($callNature == 2) {
                            $conditions .= " AND direction = 'outbound'";
                        }
                    }
                    if ($startStamp > 0 && $endStamp > 0) {
                        $conditions .= " AND start_stamp BETWEEN :startStamp: AND :endStamp:";
                        $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                        $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                    } else if ($startStamp > 0) {
                        $conditions .= " AND start_stamp > :startStamp:";
                        $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    } else if ($endStamp > 0) {
                        $conditions .= " AND start_stamp < :endStamp:";
                        $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                    }
                    $qRingqCdrs = QRingqCdr::find(
                        [
                            "conditions" => $conditions,
                            "columns" => "xml_cdr_uuid, direction,json, last_arg, start_stamp, billsec, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name, sip_call_id, cc_agent_uuid",
                            "bind" => $bind,
                            "bindTypes" => $bindTypes,
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'start_stamp DESC'
                        ]
                    );
                    $index = 0;
                    $finalCdrs = []; // Ensure $finalCdrs is initialized
                    foreach ($qRingqCdrs as $qRingqCdr) {
                        $finalCdrs[$index]["start_stamp"] = $this->formatTimezoneString($qRingqCdr->start_stamp, "m/d/Y", $timezone);
                        $finalCdrs[$index]["start_stamp2"] = $this->formatTimezoneString($qRingqCdr->start_stamp, "h:i:s A", $timezone);
                        $finalCdrs[$index]["xml_cdr_uuid"] = $qRingqCdr->xml_cdr_uuid;
                        $finalCdrs[$index]["sip_call_id"] = isset($qRingqCdr->sip_call_id) ? $qRingqCdr->sip_call_id : null;
                        $finalCdrs[$index]["last_arg"] = $qRingqCdr->last_arg;
                        $finalCdrs[$index]["direction"] = $qRingqCdr->direction;
                        $finalCdrs[$index]["billsec"] = $qRingqCdr->billsec;
                        $finalCdrs[$index]["answer_stamp"] = $qRingqCdr->answer_stamp;
                        $finalCdrs[$index]["start_epoch"] = $qRingqCdr->start_epoch;
                        $finalCdrs[$index]["answer_epoch"] = $qRingqCdr->answer_epoch;
                        $finalCdrs[$index]["end_stamp"] = $qRingqCdr->end_stamp;
                        $finalCdrs[$index]["end_epoch"] = $qRingqCdr->end_epoch;
                        $finalCdrs[$index]["caller_id_name"] = $qRingqCdr->caller_id_name;
                        $finalCdrs[$index]["caller_id_number"] = $qRingqCdr->caller_id_number;
                        $finalCdrs[$index]["caller_destination"] = $qRingqCdr->caller_destination;
                        $finalCdrs[$index]["destination_number"] = $qRingqCdr->destination_number;
                        $finalCdrs[$index]["duration"] = $qRingqCdr->duration;
                        $finalCdrs[$index]["voicemail_message"] = $qRingqCdr->voicemail_message;
                        $finalCdrs[$index]["missed_call"] = $qRingqCdr->missed_call;
                        $finalCdrs[$index]["waitsec"] = $qRingqCdr->waitsec;
                        $finalCdrs[$index]["hangup_cause"] = $qRingqCdr->hangup_cause;
                        $finalCdrs[$index]["source_number"] = $qRingqCdr->source_number;
                        $vCallCenterAgent2 = VCallCenterAgents::findFirst(
                            [
                                "conditions" => "domain_uuid = :domain_uuid: AND call_center_agent_uuid = :call_center_agent_uuid:",
                                "bind" =>
                                    [
                                        "domain_uuid" => $session["domain_uuid"],
                                        "call_center_agent_uuid" => $qRingqCdr->cc_agent_uuid,
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                            ]
                        );
                        $finalCdrs[$index]["cc_agent_uuid"] =  $vCallCenterAgent2->agent_name .' ('. $qRingqCdr->destination_number .')';
                        $finalCdrs[$index]["record_name"] = $qRingqCdr->record_name;

                        // MR - Start the Call Flow start - 13/05/2026
                        $finalCdrs[$index]["call_flow"] = isset($qRingqCdr->sip_call_id) ? file_exists('/var/call-pcaps/'.  $qRingqCdr->sip_call_id .'.pcap') : false;
                        // MR - Start the Call Flow end - 13/05/2026
                        $index++;
                    }
                    $content = [
                        "callLogs" => $finalCdrs,
                        "count" => $count,
                        "count2" => isset($missed) ? $missed : $count2,
                        "message" => $vCallCenterAgentArray
                    ];
                    $response = json_encode(
                        [
                            "result" => "success",
                            "content" => $content,
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
                if ($type == 2) {
                    $QRingqCdr = QRingqCdr::find(
                        [
                            "conditions" => "(caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch:",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                                    "start_epoch" => $timestamp
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'start_stamp DESC'
                        ]
                    );
                    $count = QRingqCdr::count(
                        [
                            "conditions" => "(caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch:",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                                    "start_epoch" => $timestamp
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                        ]
                    );
                    $QRingqCdrsArray = $QRingqCdr->toArray();
                    $finalCdrs = []; // Ensure $finalCdrs is initialized
                    foreach ($QRingqCdrsArray as $key => $QRingqCdrs) {
                        // $data = json_decode($QRingqCdrs['json'], true);
                        // $callStrategy = $data['variables']['callStrategy'] ?? 'normal';
                        // $transferTo = $data['variables']['extension_caller_id_number'] ?? 'N/A';
                        // $QRingqCdrs['callStrategy'] = $callStrategy; // Update the current record
                        // $callflows = $data['callflow'];
                        // foreach ($callflows as $callflow) {
                        //     $callerProfile = $callflow['caller_profile'] ?? [];
                        //     if (isset($callerProfile['ani']) && isset($callerProfile['destination_number'])) {
                        //         $QRingqCdrs['caller'] = $callerProfile['ani'];
                        //         $QRingqCdrs['initial_callee'] = $callerProfile['destination_number'];
                        //     }
                        //     if (!empty($callerProfile['transfer_source'])) {
                        //         // Get the transferred-to extension (e.g., bl_xfer:1029)
                        //         preg_match('/bl_xfer:(\d+)/', $callerProfile['transfer_source'], $transferToMatch);
                        //         if (!empty($transferToMatch[1])) {
                        //             $QRingqCdrs['transfer_to'] = $transferToMatch[1];
                        //         }
                        //     }
                        //     $answeredTime = $callflow['times']['answered_time'] ?? 0;
                        //     $hangupTime = $callflow['times']['hangup_time'] ?? 0;
                        //     if ($answeredTime == "0" || $hangupTime == "0") {
                        //         $QRingqCdrs['transfer_status'] = 'Not Answered';
                        //     } else {
                        //         $QRingqCdrs['transfer_status'] = 'Answered';
                        //     }
                        // }
                        $finalCdrs[] = $QRingqCdrs; // Add the updated record to $finalCdrs
                    }
                }
                // CALL LOGS // AGENT, CALL TYPE (MISSED/ANSWERED), CALL NATURE (INBOUND/OUTBOUND), DATE
                if ($type == 3) {
                    $conditions = "(source_number is not NULL OR missed_call = true) AND domain_uuid = :domain_uuid:";
                    $bind = [
                        "domain_uuid" => $session["domain_uuid"],
                    ];
                    $bindTypes = [
                        Column::BIND_PARAM_STR
                    ];
                    // $extension = 0, $callType = 1, $callNature = 1, $startStamp = 0, $endStamp = 0
                    if ($extension != 0) {
                        $extension = intval($extension);
                        $vCallCenterAgent = VCallCenterAgents::findFirst(
                            [
                                "conditions" => "domain_uuid = :domain_uuid: AND agent_id = :agent_id:",
                                "bind" =>
                                    [
                                        "domain_uuid" => $session["domain_uuid"],
                                        "agent_id" => $extension,
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                            ]
                        );
                        if (!$vCallCenterAgent) {
                            $response = json_encode(
                                [
                                    "result" => "error",
                                    "content" => [],
                                    "message" => "Sorry, extension is either non-existing or already deleted.",
                                ]
                            );
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $conditions = "domain_uuid = :domain_uuid: AND (caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND (caller_id_number != caller_destination OR missed_call = true) AND (source_number is not NULL OR missed_call = true) AND (caller_id_number != :extension: OR caller_destination != :extension: OR cc_agent != :cc_agent: OR source_number = :extension:) AND start_epoch > :start_epoch:";
                        $bind = [
                            "domain_uuid" => $session["domain_uuid"],
                            "extension" => $extension,
                            "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                            "start_epoch" => $timestamp
                        ];
                        $bindTypes = [
                            Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT
                        ];
                    }
                    if ($callType != 0) {
                        // Missed call
                        if ($callType == 1) {
                            $conditions .= " AND missed_call = TRUE";
                        }
                        // Answered call
                        if ($callType == 2) {
                            $conditions .= " AND missed_call = FALSE";
                        }
                    }
                    if ($callNature != 0) {
                        // Incoming 
                        if ($callNature == 1) {
                            $conditions .= " AND (direction = 'inbound' OR direction = 'local')";
                        }
                        // Outgoing
                        if ($callNature == 2) {
                            $conditions .= " AND direction = 'outbound'";
                        }
                    }
                    if ($startStamp > 0 && $endStamp > 0) {
                        $conditions .= " AND start_stamp BETWEEN :startStamp: AND :endStamp:";
                        $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                        $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                    } else if ($startStamp > 0) {
                        $conditions .= " AND start_stamp > :startStamp:";
                        $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    } else if ($endStamp > 0) {
                        $conditions .= " AND start_stamp < :endStamp:";
                        $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                    }
                    $qRingqCdrs = QRingqCdr::find(
                        [
                            "conditions" => $conditions,
                            "bind" => $bind,
                            "bindTypes" => $bindTypes,
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'start_stamp DESC'
                        ]
                    );
                    $index = 0;
                    $finalCdrs = []; // Ensure $finalCdrs is initialized
                    foreach ($qRingqCdrs as $qRingqCdr) {
                        $finalCdrs[$index]["start_stamp"] = $this->formatTimezoneString($qRingqCdr->start_stamp, "m/d/Y", $timezone);
                        $finalCdrs[$index]["start_stamp2"] = $this->formatTimezoneString($qRingqCdr->start_stamp, "h:i:s A", $timezone);
                        $finalCdrs[$index]["xml_cdr_uuid"] = $qRingqCdr->xml_cdr_uuid;
                        $finalCdrs[$index]["sip_call_id"] = $qRingqCdr->sip_call_id;
                        $finalCdrs[$index]["direction"] = $qRingqCdr->direction;
                        $finalCdrs[$index]["last_arg"] = $qRingqCdr->last_arg;
                        $finalCdrs[$index]["billsec"] = $qRingqCdr->billsec;
                        $finalCdrs[$index]["answer_stamp"] = $qRingqCdr->answer_stamp;
                        $finalCdrs[$index]["start_epoch"] = $qRingqCdr->start_epoch;
                        $finalCdrs[$index]["answer_epoch"] = $qRingqCdr->answer_epoch;
                        $finalCdrs[$index]["end_stamp"] = $qRingqCdr->end_stamp;
                        $finalCdrs[$index]["end_epoch"] = $qRingqCdr->end_epoch;
                        $finalCdrs[$index]["caller_id_name"] = $qRingqCdr->caller_id_name;
                        $finalCdrs[$index]["caller_id_number"] = $qRingqCdr->caller_id_number;
                        $finalCdrs[$index]["caller_destination"] = $qRingqCdr->caller_destination;
                        $finalCdrs[$index]["destination_number"] = $qRingqCdr->destination_number;
                        $finalCdrs[$index]["duration"] = $qRingqCdr->duration;
                        $finalCdrs[$index]["voicemail_message"] = $qRingqCdr->voicemail_message;
                        $finalCdrs[$index]["missed_call"] = $qRingqCdr->missed_call;
                        $finalCdrs[$index]["waitsec"] = $qRingqCdr->waitsec;
                        $finalCdrs[$index]["hangup_cause"] = $qRingqCdr->hangup_cause;
                        $finalCdrs[$index]["source_number"] = $qRingqCdr->source_number;
                        $vCallCenterAgent2 = VCallCenterAgents::findFirst(
                            [
                                "conditions" => "domain_uuid = :domain_uuid: AND call_center_agent_uuid = :call_center_agent_uuid:",
                                "bind" =>
                                    [
                                        "domain_uuid" => $session["domain_uuid"],
                                        "call_center_agent_uuid" => $qRingqCdr->cc_agent_uuid,
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                            ]
                        );
                        $finalCdrs[$index]["cc_agent_uuid"] =  $vCallCenterAgent2->agent_name .' ('. $qRingqCdr->destination_number .')';
                        $finalCdrs[$index]["record_name"] = $qRingqCdr->record_name;
                        // MR - Start the Call Flow start - 13/05/2026
                        $finalCdrs[$index]["call_flow"] = isset($qRingqCdr->sip_call_id) ? file_exists('/var/call-pcaps/'.  $qRingqCdr->sip_call_id .'.pcap') : false;
                        // MR - Start the Call Flow end - 13/05/2026
                        $index++;
                    }
                    $count = QRingqCdr::count(
                        [
                            "conditions" => $conditions,
                            "bind" => $bind,
                            "bindTypes" => $bindTypes,
                        ]
                    );
                    $response = json_encode(
                        [
                            "result" => "success",
                            "content" => $finalCdrs,
                            "count" => $count,
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
                $content = [
                    "callLogs" => $finalCdrs,
                    "count" => $count
                ];
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                $data = $this->request->getRawBody();
                $jsonData = json_decode($data);
                $callType = intval($jsonData->callType ?? 0);
                $callNature = intval($jsonData->callNature ?? 0);
                $startStamp = intval($jsonData->selectStart ?? 0);
                $endStamp = intval($jsonData->selectEnd ?? 0);
                $extension = $jsonData->selectAgent;
                if ($this->isPlainUser($session)) {
                    // Same as the screen: a plain user only sees (and exports) their own calls.
                    $extension = $session["extension"];
                }
                $parameters["page"] = intval($jsonData->page ?? 0);
                $parameters["type"] = intval($jsonData->type ?? 0);
                $timestamp = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-deletion");
                if (!isset($timestamp)) {
                    $timestamp = 0;
                }
                if (empty($parameters["page"])) {
                    $contents = file_get_contents("php://input");
                    $parameters = json_decode($contents, true);
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($parameters["type"] == 0) {
                    $this->redis->set($session["extension"] ."@". $session["subdomain"] ."-deletion", time());
                    $response = json_encode(
                        [
                            "result" => "success",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
                $conditions = "(source_number is not NULL OR missed_call = true) AND domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                $bindTypes = [
                    Column::BIND_PARAM_STR
                ];
                if ($extension != 0) {
                    // $extension = intval($extension);
                    $vCallCenterAgent = VCallCenterAgents::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND agent_id = :agent_id:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "agent_id" => $extension,
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_INT],
                        ]
                    );
                    if (!$vCallCenterAgent) {
                        $response = json_encode(
                            [
                                "result" => "error",
                                "content" => [],
                                "message" => "Sorry, extension is either non-existing or already deleted.",
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    }
                    $extensions = is_array($extension) ? $extension : (strpos((string) $extension, ',') !== false ? explode(",", (string) $extension) : [(string) $extension]);
                    $conditions = "domain_uuid = :domain_uuid: 
                        AND (caller_id_number IN ({extension:array}) 
                        OR caller_destination IN ({extension:array}) 
                        OR cc_agent = :cc_agent: 
                        OR source_number IN ({extension:array})) 
                        AND (caller_id_number != caller_destination OR missed_call = true)
                        AND (source_number IS NOT NULL OR missed_call = true)
                        AND (caller_id_number NOT IN ({extension:array})
                        OR caller_destination NOT IN ({extension:array}) 
                        OR cc_agent != :cc_agent: 
                        OR source_number IN ({extension:array})) 
                        AND start_epoch > :start_epoch:";
                    $bind = [
                        "domain_uuid" => $session["domain_uuid"],
                        "extension" => $extensions, // Must be an array
                        "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                        "start_epoch" => $timestamp
                    ];
                    $bindTypes = [
                        Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT
                    ];
                }
                if ($callType != 0) {
                    // Missed call
                    if ($callType == 1) {
                        $conditions .= " AND missed_call = TRUE";
                    }
                    // Answered call
                    if ($callType == 2) {
                        $conditions .= " AND missed_call = FALSE";
                    }
                }
                if ($callNature != 0) {
                    // Incoming 
                    if ($callNature == 1) {
                        $conditions .= " AND (direction = 'inbound' OR direction = 'local')";
                    }
                    // Outgoing
                    if ($callNature == 2) {
                        $conditions .= " AND direction = 'outbound'";
                    }
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND start_stamp BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                } else if ($startStamp > 0) {
                    $conditions .= " AND start_stamp > :startStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND start_stamp < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                }
                $QRingqCdrs = QRingqCdr::find(
                    [
                        "conditions" => $conditions,
                        "columns" => "xml_cdr_uuid, direction, start_stamp, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, billsec, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name, cc_queue, cc_agent, last_arg",
                        "bind" => $bind,
                        "bindTypes" => $bindTypes,
                        'order' => 'start_epoch DESC'
                    ]
                );
                $count = QRingqCdr::count(
                    [
                        "conditions" => "(source_number is not NULL OR missed_call = true) AND domain_uuid = :domain_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                // Every matching record, with the Call logs table's columns (ReportGenerator::callLogCsvRow).
                $generator = $this->reportGenerator();
                $uuids = [];
                foreach ($QRingqCdrs as $QRingqCdr) {
                    $uuids[] = $QRingqCdr->xml_cdr_uuid;
                }
                $security = new \Phalcon\Security\Random();
                $filename = $security->uuid();
                $rowCount = \ReportGenerator::writeCallLogCsv('/var/www/ringq/cache/'. $filename .'.csv', $QRingqCdrs, $timezone, $session["subdomain"], $generator->callJourneys($uuids));
                if ($parameters["type"] == 1) {
                    $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                    if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                        unlink("/var/www/ringq/cache/". $filename .".csv");
                    }
                } 
                if ($parameters["type"] == 2) {
                    $dayText = function ($epoch) use ($timezone) {
                        return (new \DateTime("@" . intval($epoch)))->setTimezone(new \DateTimeZone($timezone))->format("j M Y");
                    };
                    if ($startStamp > 0 && $endStamp > 0) {
                        $period = $dayText($startStamp) . " - " . $dayText($endStamp);
                    } else if ($startStamp > 0) {
                        $period = "From " . $dayText($startStamp);
                    } else if ($endStamp > 0) {
                        $period = "Up to " . $dayText($endStamp);
                    } else {
                        $period = "All records";
                    }
                    $filters = [];
                    if ($extension != 0) {
                        $filters[] = "Agent " . (is_array($extension) ? implode(", ", $extension) : $extension);
                    }
                    if ($callType == 1) { $filters[] = "Missed calls"; }
                    if ($callType == 2) { $filters[] = "Answered calls"; }
                    if ($callNature == 1) { $filters[] = "Inbound"; }
                    if ($callNature == 2) { $filters[] = "Outbound"; }
                    $this->redis->lpush("report", json_encode([
                        "email" => $session["email"],
                        "file" => $filename .".csv",
                        "domain_uuid" => $session["domain_uuid"],
                        "label" => "Call logs",
                        "filename" => "Call-Logs_" . (new \DateTime("now", new \DateTimeZone($timezone)))->format("Y-m-d_Hi") . ".csv",
                        "rows" => $rowCount,
                        "period" => $period,
                        "filters" => implode(", ", $filters),
                        "timezone" => $timezone,
                    ]));
                    exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                    $response = json_encode(
                        [
                            "result" => "success",
                            "message" => "The report is being emailed to " . $session["email"] . ".",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
            }
        }
    }

    public function overallAction($page = 1, $queue = 0, $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            return;
        }
        // OVERALL // QUEUE, DATE. One row per queue call from the call records; see ReportGenerator::overallQueueCalls().
        $type = 0;
        if ($this->request->isPost()) {
            $jsonData = json_decode($this->request->getRawBody());
            $startStamp = intval($jsonData->selectStart ?? 0);
            $endStamp = intval($jsonData->selectEnd ?? 0);
            $queue = $jsonData->selectQueue ?? "0";
            $type = intval($jsonData->type ?? 0);
        }
        $vQueue = $this->findReportQueue($session["domain_uuid"], $queue);
        $queueExt = $vQueue ? $vQueue->queue_extension : null;
        $timezone = $this->reportTimezone($session["domain_uuid"]);
        $generator = $this->reportGenerator();

        if ($this->request->isGet()) {
            $page = max(1, intval($page));
            $calls = $generator->overallQueueCalls($session["domain_uuid"], $timezone, $queueExt, intval($startStamp), intval($endStamp), 20, ($page - 1) * 20);
            $this->response->setContent(json_encode([
                "result" => "success",
                "content" => $calls["rows"],
                "count" => $calls["count"],
            ]));
            return $this->response;
        }

        $calls = $generator->overallQueueCalls($session["domain_uuid"], $timezone, $queueExt, $startStamp, $endStamp);
        list($header, $rows) = \ReportGenerator::overallCsvRows($calls["rows"], $session["subdomain"] ?? null);
        return $this->sendReportCsv($session, $header, $rows, $type, "Overall");
    }

    public function callqueueAction($page = 1, $queue = 0, $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            return;
        }
        // DETAILED QUEUE // QUEUE, DATE. Computed from the call records; see ReportGenerator::detailQueueStats().
        $type = 0;
        if ($this->request->isPost()) {
            $jsonData = json_decode($this->request->getRawBody());
            $startStamp = intval($jsonData->selectStart ?? 0);
            $endStamp = intval($jsonData->selectEnd ?? 0);
            $queue = $jsonData->selectQueue ?? "0";
            $type = intval($jsonData->type ?? 0);
        }
        $vQueue = $this->findReportQueue($session["domain_uuid"], $queue);
        $queueExt = $vQueue ? $vQueue->queue_extension : null;
        $timezone = $this->reportTimezone($session["domain_uuid"]);
        $generator = $this->reportGenerator();

        if ($this->request->isGet()) {
            $page = max(1, intval($page));
            $stats = $generator->detailQueueStats($session["domain_uuid"], $timezone, $queueExt, intval($startStamp), intval($endStamp), 20, ($page - 1) * 20);
            $this->response->setContent(json_encode([
                "result" => "success",
                "content" => $stats["rows"],
                "count" => $stats["count"],
            ]));
            return $this->response;
        }

        $stats = $generator->detailQueueStats($session["domain_uuid"], $timezone, $queueExt, $startStamp, $endStamp);
        list($header, $rows) = \ReportGenerator::detailQueueCsvRows($stats["rows"]);
        return $this->sendReportCsv($session, $header, $rows, $type, "Detail queue");
    }

    /** The tenant's display timezone (Settings > time zone), else the server's. */
    protected function reportTimezone($domainUuid) {
        $timezone = date_default_timezone_get();
        $vUserSettings = VUserSettings::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
            "bind" => ["domain_uuid" => $domainUuid, "user_setting_subcategory" => "time_zone"],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);
        if ($vUserSettings && !empty($vUserSettings->user_setting_value)) {
            $timezone = $vUserSettings->user_setting_value;
        }
        return $timezone;
    }

    protected function reportGenerator() {
        require_once APP_PATH . "/library/ReportGenerator.php";
        $generator = new \ReportGenerator();
        $generator->setDI($this->getDI());
        return $generator;
    }

    /**
     * Write a report CSV, then either send it as the download ($type 1) or email it to the
     * signed-in user ($type 2), the same way the other report actions do.
     */
    protected function sendReportCsv($session, $header, $rows, $type, $label = "Report") {
        $security = new \Phalcon\Security\Random();
        $filename = $security->uuid();
        $path = '/var/www/ringq/cache/' . $filename . '.csv';
        $fp = fopen($path, 'w');
        fputcsv($fp, $header, ",");
        foreach ($rows as $fields) {
            fputcsv($fp, $fields, ",");
        }
        fclose($fp);
        if ($type == 1) {
            $this->response->setFileToSend($path, $filename . ".csv")->send();
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }
        if ($type == 2) {
            $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename . ".csv", "domain_uuid" => $session["domain_uuid"], "label" => $label, "rows" => count($rows)]));
            exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
            $this->response->setContent(json_encode(["result" => "success", "message" => "Generating reports.", "content" => []]));
            return $this->response;
        }
        @unlink($path);
        $this->response->setContent(json_encode(["result" => "error", "message" => "Unknown export type.", "content" => []]));
        return $this->response;
    }

    public function callcostAction($type = 1, $page = 1)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                $vCallCenterAgent = VCallCenterAgents::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_uuid = :user_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_uuid" => $session["user_uuid"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                $timestamp = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-deletion");
                if (!isset($timestamp)) {
                    $timestamp = 0;
                }
                if ($type == 1) {
                    $QRingqCdr = QRingqCdr::find(
                        [
                            "conditions" => "(caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND (caller_id_number != :extension: OR caller_destination != :extension: OR cc_agent != :cc_agent:  OR source_number = :extension:) AND domain_uuid = :domain_uuid: AND start_epoch > :start_epoch: AND direction = :direction: AND duration is not NULL",
                            "columns" => "xml_cdr_uuid, direction, start_stamp, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                                    "start_epoch" => $timestamp,
                                    "direction" => "outbound",
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'insert_date DESC'
                        ]
                    );
                    $count = QRingqCdr::count(
                        [
                            "conditions" => "(caller_id_number = :extension: OR caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND domain_uuid = :domain_uuid: AND start_epoch > :start_epoch: AND direction = :direction: AND duration is not NULL",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                                    "start_epoch" => $timestamp,
                                    "direction" => "outbound",
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                        ]
                    );
                }
                if ($type == 2) {
                    $QRingqCdr = QRingqCdr::find(
                        [
                            "conditions" => "(caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch: AND direction = :direction: AND duration is not NULL",
                            "columns" => "xml_cdr_uuid, direction, start_stamp, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                                    "start_epoch" => $timestamp,
                                    "direction" => "outbound",
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'insert_date DESC'
                        ]
                    );
                    $count = QRingqCdr::count(
                        [
                            "conditions" => "(caller_destination = :extension: OR cc_agent = :cc_agent: OR source_number = :extension:) AND caller_id_number != caller_destination AND source_number is not NULL AND domain_uuid = :domain_uuid: AND missed_call = true AND start_epoch > :start_epoch: AND direction = :direction: AND duration is not NULL",
                            "bind" =>
                                [
                                    "extension" => $session["extension"],
                                    "domain_uuid" => $session["domain_uuid"],
                                    "cc_agent" => $vCallCenterAgent->call_center_agent_uuid,
                                    "start_epoch" => $timestamp,
                                    "direction" => "outbound",
                                ],
                            "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                        ]
                    );
                }
                if ($type == 3) {
                    $QRingqCdr = QRingqCdr::find(
                        [
                            "conditions" => "source_number is not NULL AND domain_uuid = :domain_uuid: AND direction = :direction: AND duration is not NULL",
                            "columns" => "xml_cdr_uuid, direction, start_stamp, answer_stamp, start_epoch, answer_epoch, end_stamp, end_epoch, caller_id_name, caller_id_number, caller_destination, destination_number, duration, voicemail_message, missed_call, waitsec, hangup_cause, source_number, record_name",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "direction" => "outbound",
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'insert_date DESC'
                        ]
                    );
                    $count = QRingqCdr::count(
                        [
                            "conditions" => "source_number is not NULL AND domain_uuid = :domain_uuid: AND direction = :direction: AND duration is not NULL",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "direction" => "outbound",
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    $response = json_encode(
                        [
                            "result" => "success",
                            "content" => $QRingqCdr,
                            "count" => $count,
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
                // CALL COST // AGENT, DATE
                if ($type == 4) {
                    $QRingqCdr = QCallCost::find(
                        [
                            "conditions" => "domain_uuid = :domain_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR],
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'callStart DESC'
                        ]
                    );
                    $count = QCallCost::count(
                        [
                            "conditions" => "domain_uuid = :domain_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR],
                        ]
                    );
                    $response = json_encode(
                        [
                            "result" => "success",
                            "content" => $QRingqCdr,
                            "count" => $count,
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }

                $content = [
                    "callLogs" => $QRingqCdr,
                    "count" => $count
                ];
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                if ($type == 1 || $type == 2) {
                    $parameters["page"] = intval($this->request->getPost("page", "int"));
                    if (empty($parameters["page"])) {
                        $contents = file_get_contents("php://input");
                        $parameters = json_decode($contents, true);
                    }
                    $offset = $page > 1 ? intval($parameters["page"]) * 20 - 20 : 0;
                    $QRingqCdr = QCallCost::find(
                        [
                            "conditions" => "domain_uuid = :domain_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR],
                            "limit" => 20,
                            "offset"=> $offset,
                            'order' => 'callStart DESC'
                        ]
                    );
                    $count = QCallCost::count(
                        [
                            "conditions" => "domain_uuid = :domain_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR],
                        ]
                    );


                    foreach ($QRingqCdrs as $QRingqCdr) {
                        $content[$index]["date"] = $QRingqCdr->start_stamp;
                        $content[$index]["time"] = $QRingqCdr->start_stamp;
                        $content[$index]["caller"] = $QRingqCdr->caller_id_number;
                        $content[$index]["caller_name"] = $QRingqCdr->caller_id_name;
                        $content[$index]["caller"] = $QRingqCdr->insert_date;
                        $content[$index]["customer"] = $QRingqCdr->customer;
                        $content[$index]["destination"] = $QRingqCdr->caller_destination;
                        $content[$index]["duration"] = $QRingqCdr->duration;
                        $index++;
                    }
                    $security = new \Phalcon\Security\Random();
                    $filename = $security->uuid();
                    $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                    $header = ["Date", "Time", "Extension", "Caller Name", "Caller Number", "Caller", "Customer", "Destination", "Duration"];
                    fputcsv($fp, $header, ",");
                    foreach ($content as $fields) { 
                        fputcsv($fp, $fields, ","); 
                    }
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="output.csv";');
                    fclose($fp);
                    if ($parameters["type"] == 1) {
                        $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                        if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                            unlink("/var/www/ringq/cache/". $filename .".csv");
                        }
                    } 
                    if ($parameters["type"] == 2) {
                        $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Call cost"]));
                        exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                        $response = json_encode(
                            [
                                "result" => "success",
                                "message" => "Generating reports.",
                                "content" => [],
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    }
                }
                if ($type == 3) {
                    $parameters["id"] = intval($this->request->getPost("id", "int"));
                    $parameters["type"] = $this->request->getPost("type", "string");
                    $parameters["prefix"] = $this->request->getPost("prefix", "string");
                    $parameters["country_name"] = $this->request->getPost("country_name", "string");
                    $parameters["country_code"] = $this->request->getPost("country_code", "string");
                    $parameters["unit_cost"] = doubleval($this->request->getPost("unit_cost", "double"));
                    $parameters["minute_cost"] = doubleval($this->request->getPost("minute_cost", "double"));
                    $parameters["second_cost"] = doubleval($this->request->getPost("second_cost", "double"));
                    if (empty($parameters["type"])) {
                        $contents = file_get_contents("php://input");
                        $parameters = json_decode($contents, true);
                    }
                    if ($parameters["type"] == 1) {
                        $qCostTable = QCostTable::findFirst(
                            [
                                "conditions" => "domain_uuid = :domain_uuid: AND country_code = :country_code: AND prefix = :prefix:",
                                "bind" =>
                                    [
                                        "domain_uuid" => $session["domain_uuid"],
                                        "country_code" => $parameters["country_code"],
                                        "prefix" => $parameters["prefix"]
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                            ]
                        );
                        if ($qCostTable) {
                            $response = json_encode(
                                [
                                    "result" => "error",
                                    "message" => "Sorry, call cost entry already exists.",
                                    "content" => "",
                                ]
                            );
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $security = new \Phalcon\Security\Random();
                        $uuid = $security->uuid();
                        $qCostTable = new QCostTable();
                        $qCostTable->domain_uuid = $session["domain_uuid"];
                        $qCostTable->country_uuid = $uuid;
                        $qCostTable->country_name = $parameters["country_name"];
                        $qCostTable->country_code = $parameters["country_code"];
                        $qCostTable->unit_cost = $parameters["unit_cost"];
                        $qCostTable->prefix = $parameters["prefix"];
                        $qCostTable->type = $parameters["type"];
                        $qCostTable->insert_date = date("Y-m-d H:i:s");
                        $qCostTable->insert_user = $session["user_uuid"];
                        if (!$qCostTable->create()) {
                            $response = json_encode([
                               "result" => "error",
                               "message" => "An error has occured. Please check values. (Code AE1023)",
                            ]);
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $response = json_encode(
                            [
                                "result" => "success",
                                "message" => "Successfully, added new call cost entry.",
                                "content" => [],
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    }
                    if ($parameters["type"] == 2) {
                        $qCostTable = QCostTable::findFirst(
                            [
                                "conditions" => "id = :id: AND domain_uuid = :domain_uuid:",
                                "bind" =>
                                    [   
                                        "id" => $parameters["id"],
                                        "domain_uuid" => $session["domain_uuid"],
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                            ]
                        );
                        if (!$qCostTable) {
                            $response = json_encode(
                                [
                                    "result" => "error",
                                    "message" => "Sorry, call cost entry does not exists.",
                                    "content" => "",
                                ]
                            );
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $security = new \Phalcon\Security\Random();
                        $uuid = $security->uuid();
                        $qCostTable->unit_cost = $parameters["unit_cost"];
                        $qCostTable->prefix = $parameters["prefix"];
                        $qCostTable->type = $parameters["type"];
                        $qCostTable->update_date = date("Y-m-d H:i:s");
                        $qCostTable->update_user = $session["user_uuid"];
                        if (!$qCostTable->update()) {
                            $response = json_encode([
                               "result" => "error",
                               "message" => "An error has occured. Please check values. (Code AE1023)",
                            ]);
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $response = json_encode(
                            [
                                "result" => "success",
                                "message" => "Successfully, updated call cost entry.",
                                "content" => [],
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    }
                    if ($parameters["type"] == 3) {
                        $qCostTable = QCostTable::findFirst(
                            [
                                "conditions" => "id = :id: AND domain_uuid = :domain_uuid:",
                                "bind" =>
                                    [   
                                        "id" => $parameters["id"],
                                        "domain_uuid" => $session["domain_uuid"],
                                    ],
                                "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                            ]
                        );
                        if (!$qCostTable) {
                            $response = json_encode(
                                [
                                    "result" => "error",
                                    "message" => "Sorry, call cost entry does not exists.",
                                    "content" => "",
                                ]
                            );
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        if (!$qCostTable->delete()) {
                            $response = json_encode(
                                [
                                    "result" => "error",
                                    "message" => "Sorry, an error has occured deleting call cost entry.",
                                    "content" => "",
                                ]
                            );
                            $this->response->setContent($response);
                            return $this->response;
                        }
                        $response = json_encode(
                            [
                                "result" => "success",
                                "message" => "Successfully, deleted call cost entry.",
                                "content" => [],
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    }
                }
            }
        }
    }

    public function wrapupnotes2Action($page = 0, $agent = 0, $wrapUp = "", $startStamp = 0, $endStamp = 0)
    {

        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            // WRAP-UP // AGENT, WRAP-UP CODES, WRAP-UP CATEGORY, DATE
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                if ($agent != 0) { 
                    $conditions .= " AND extension ILIKE :extension:";
                    $bind["extension"] = "%". $agent . "%";
                }
                if (!empty($wrapUp)) {
                    $conditions .= " AND wrapup_code ILIKE :wrapup_code:";
                    $bind["wrapup_code"] = "%". urldecode($wrapUp) . "%";
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                } else if ($startStamp > 0) {
                    $conditions .= " AND insert_date > :startStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND insert_date < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                }
                $qWrapupnotesLists = QWrapupnotesList::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                        "limit" => 20,
                        "offset"=> $offset,
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = QWrapupnotesList::count(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                $index = 0;
                $content = array();
                foreach ($qWrapupnotesLists as $qWrapupnotesList) {
                    $content[$index]["id"] = $qWrapupnotesList->id;
                    $content[$index]["username"] = $qWrapupnotesList->username;
                    $content[$index]["extension"] = $qWrapupnotesList->extension;
                    $content[$index]["wrapupnote"] = isset($qWrapupnotesList->wrapup_code) ? ucfirst($qWrapupnotesList->wrapup_code) : "";
                    $content[$index]["content"] = $qWrapupnotesList->content;
                    $content[$index]["insert_date"] = $this->formatTimezoneString($qWrapupnotesList->insert_date, "m/d/Y", $timezone);
                    $content[$index]["insert_date2"] = $this->formatTimezoneString($qWrapupnotesList->insert_date, "h:i:s A", $timezone);
                    $content[$index]["customer"] = $qWrapupnotesList->customer;
                    $content[$index]["contact"] = $qWrapupnotesList->contact;
                    $index++;
                }
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                        "count" => $count,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                $parameters = array();
                $parameters["type"] = intval($this->request->getPost("type", "int"));
                $parameters["id"] = intval($this->request->getPost("id", "int"));
                $parameters["content"] = $this->request->getPost("content", "string");
                $parameters["wrapup_type"] = intval($this->request->getPost("wrapup_type", "int"));
                $parameters["wrapup_uuid"] = $this->request->getPost("wrapup_uuid", "string");
                $parameters["firstname"] = $this->request->getPost("firstname", "string");
                $parameters["lastname"] = $this->request->getPost("lastname", "string");
                $parameters["customer"] = $this->request->getPost("customer", "string");
                $parameters["contact"] = $this->request->getPost("contact", "string");
                if (empty($parameters["wrapup_uuid"])) {
                    $contents = file_get_contents("php://input");
                    $parameters = json_decode($contents, true);
                }
                if ($parameters["type"] == 0) {
                    $wrapupnoteslistForm = new WrapupnoteslistForm();
                    if (!$wrapupnoteslistForm->isValid($parameters)) {
                        $messages = $wrapupnoteslistForm->getMessages();
                        $message = "";
                        foreach ($messages as $error) {
                            $message .= $error . " ";
                        }
                        $response = json_encode(
                            [
                                "result" => "error",
                                "message" => trim($message) . " (Code AW1000)",
                                "content" => [],
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    } else {
                        if ($parameters["wrapup_type"] == 1) {
                            $qWrapupnote = QWrapupnotes::findFirst(
                                [
                                    "conditions" => "domain_uuid = :domain_uuid: AND wrapup_uuid = :wrapup_uuid:",
                                    "bind" =>
                                        [
                                            "domain_uuid" => $session["domain_uuid"],
                                            "wrapup_uuid" => $parameters["wrapup_uuid"]
                                        ],
                                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                ]
                            );
                            if (!$qWrapupnote) {
                                $response = json_encode(
                                    [
                                        "result" => "error",
                                        "message" => "Please check wrappup code. (Code AW1004)",
                                        "content" => [],
                                    ]
                                );
                                $this->response->setContent($response);
                                return $this->response;
                            }
                            $qContact = QContacts::findFirst(
                                [
                                    "conditions" => "id = :id: AND domain_uuid = :domain_uuid:",
                                    "bind" =>
                                        [
                                            "id" => $parameters["id"],
                                            "domain_uuid" => $session["domain_uuid"],
                                        ],
                                    "bindTypes" => [Column::BIND_PARAM_INT, Column::BIND_PARAM_STR],
                                ]
                            );
                            if ($qContact == null) {
                                $qContact = new QContacts();
                                $qContact->first_name = $parameters["firstname"];
                                $qContact->last_name = $parameters["lastname"];
                                $qContact->phone = $parameters["contact"];
                                $qContact->domain_uuid = $session["domain_uuid"];
                                $qContact->insert_uuid = $session["user_uuid"];
                                $qContact->insert_date = date("Y-m-d H:i:s");
                                if (!$qContact->create()) {
                                    $messages = $qContact->getMessages();
                                    $message = "";
                                    foreach ($messages as $error) {
                                        $message .= $error . " ";
                                    }
                                    $response = json_encode(
                                        [
                                            "result" => "error",
                                            "message" => trim($message) . " (Code AA1000)",
                                            "content" => [],
                                        ]
                                    );
                                    $this->response->setContent($response);
                                    return $this->response;
                                }
                            }
                            $vUser = VUsers::findFirst(
                                [
                                    "conditions" => "user_uuid = :user_uuid:",
                                    "bind" =>
                                        [
                                            "user_uuid" => $session["user_uuid"],
                                        ],
                                    "bindTypes" =>
                                        [
                                            Column::BIND_PARAM_STR,
                                        ]
                                ]
                            );
                            $qWrapupnoteList = new QWrapupnotesList();
                            $qWrapupnoteList->domain_uuid = $session["domain_uuid"];
                            $qWrapupnoteList->content = $parameters["content"];
                            $qWrapupnoteList->insert_date = date("Y-m-d H:i:s P");
                            $qWrapupnoteList->user_uuid = $session["user_uuid"];
                            $qWrapupnoteList->extension = $session["extension"];
                            $qWrapupnoteList->username = isset($vUser) ? $vUser->qUsers->first_name . " " . $vUser->qUsers->last_name : $session["firstname"] . " " . $session["lastname"];
                            $qWrapupnoteList->customer = $parameters["customer"];
                            $qWrapupnoteList->contact = $parameters["contact"];
                            $qWrapupnoteList->wrapup_uuid = $qWrapupnote->wrapup_uuid;
                            $qWrapupnoteList->wrapup_code = $qWrapupnote->wrapup_note;
                            $qWrapupnoteList->contact_id = $qContact->id;
                            if (!$qWrapupnoteList->create()) {
                                $messages = $qWrapupnoteList->getMessages();
                                $message = "";
                                foreach ($messages as $error) {
                                    $message .= $error . " ";
                                }
                                $response = json_encode(
                                    [
                                        "result" => "error",
                                        "message" => trim($message) . " (Code AW1005)",
                                        "content" => [],
                                    ]
                                );
                                $this->response->setContent($response);
                                return $this->response;
                            } else {
                                $response = json_encode(
                                    [
                                        "result" => "success",
                                        "message" => "Successfully, added wrapup note.",
                                        "content" => [],
                                    ]
                                );
                                $this->response->setContent($response);
                                return $this->response;
                            }
                        }
                    }
                } else {
                    $data = $this->request->getRawBody();
                    $jsonData = json_decode($data);
                    $startStamp = intval($jsonData->selectStart ?? 0);
                    $endStamp = intval($jsonData->selectEnd ?? 0);
                    $agent = $jsonData->selectAgent;
                    $queue = $jsonData->selectQueue;
                    $wrapUp = $jsonData->wrapUpCode;
                    $parameters["page"] = intval($jsonData->page ?? 0);
                    $parameters["type"] = intval($jsonData->type ?? 0);
                    $conditions = "domain_uuid = :domain_uuid:";
                    $bind = [
                        "domain_uuid" => $session["domain_uuid"],
                    ];
                    if ($agent != 0) { 
                        $conditions .= " AND extension ILIKE :extension:";
                        $bind["extension"] = "%". $agent . "%";
                    }
                    if (!empty($wrapUp)) {
                        $conditions .= " AND wrapup_code ILIKE :wrapup_code:";
                        $bind["wrapup_code"] = "%". urldecode($wrapUp) . "%";
                    }
                    $timezone = date_default_timezone_get();
                    $vUserSettings = VUserSettings::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "user_setting_subcategory" => "time_zone"
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    if ($vUserSettings) {
                        $timezone = $vUserSettings->user_setting_value;
                    }
                    if ($startStamp > 0 && $endStamp > 0) {
                        $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
                        $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                        $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                    } else if ($startStamp > 0) {
                        $conditions .= " AND insert_date > :startStamp:";
                        $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    } else if ($endStamp > 0) {
                        $conditions .= " AND insert_date < :endStamp:";
                        $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                    }
                    $qWrapupnotesLists = QWrapupnotesList::find(
                        [
                            "conditions" => $conditions,
                            "bind" => $bind,
                            "bindTypes" => [Column::BIND_PARAM_STR],
                            'order' => 'insert_date DESC'
                        ]
                    );
                    $count = QWrapupnotesList::count(
                        [
                            "conditions" => $conditions,
                            "bind" => $bind,
                            "bindTypes" => [Column::BIND_PARAM_STR],
                        ]
                    );
                    $timezone = date_default_timezone_get();
                    $vUserSettings = VUserSettings::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "user_setting_subcategory" => "time_zone"
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    if ($vUserSettings) {
                        $timezone = $vUserSettings->user_setting_value;
                    }
                    $index = 0;
                    $content = array();
                    foreach ($qWrapupnotesLists as $qWrapupnotesList) {
                        $content[$index]["insert_date"] = $this->formatTimezoneString($qWrapupnotesList->insert_date, "m/d/Y", $timezone);
                        $content[$index]["insert_date2"] = $this->formatTimezoneString($qWrapupnotesList->insert_date, "h:i:s A", $timezone);
                        $content[$index]["username"] = $qWrapupnotesList->username;
                        $content[$index]["extension"] = $qWrapupnotesList->extension;
                        $content[$index]["wrapupnote"] = isset($qWrapupnotesList->wrapup_code) ? ucfirst($qWrapupnotesList->wrapup_code) : "";
                        $content[$index]["content"] = $qWrapupnotesList->content;
                        $content[$index]["customer"] = $qWrapupnotesList->customer;
                        $content[$index]["contact"] = $qWrapupnotesList->contact;
                        $index++;
                    }

                    $security = new \Phalcon\Security\Random();
                    $filename = $security->uuid();
                    $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                    $header = ["Date", "Time", "Username", "Extension", "Wrapup Note", "Content", "Customer", "Contact"];
                    fputcsv($fp, $header, ",");
                    foreach ($content as $fields) { 
                        fputcsv($fp, $fields, ","); 
                    }
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="output.csv";');
                    fclose($fp);
                    if ($parameters["type"] == 1) {
                        $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                        if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                            unlink("/var/www/ringq/cache/". $filename .".csv");
                        }
                    } 
                    if ($parameters["type"] == 2) {
                        $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Wrap up"]));
                        exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                        $response = json_encode(
                            [
                                "result" => "success",
                                "message" => "Generating reports.",
                                "content" => [],
                            ]
                        );
                        $this->response->setContent($response);
                        return $this->response;
                    }
                }
            }
        }
    }

    public function auxcodes2Action($page = 0, $agent = 0, $queue = 0, $auxcode = "", $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            // AUXCODES // AGENT, AUXCODE, QUEUE, DATE
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                // extension is stored as "<agent ext> | <queue ext>"
                if ($agent != 0) { 
                    $conditions .= " AND extension ILIKE :agent_ext:";
                    $bind["agent_ext"] = $agent . " |%";
                }
                if (!empty($queue) && $queue !== "0") { 
                    $auxQueue = $this->findReportQueue($session["domain_uuid"], $queue);
                    $conditions .= " AND extension ILIKE :queue_ext:";
                    $bind["queue_ext"] = "%| " . ($auxQueue ? $auxQueue->queue_extension : $queue);
                }
                if (!empty($auxcode)) {
                    $conditions .= " AND auxcode_status ILIKE :auxcode_status:";
                    $bind["auxcode_status"] = "%". urldecode($auxcode) . "%";
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                } else if ($startStamp > 0) {
                    $conditions .= " AND insert_date > :startStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND insert_date < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                }
                $qAuxcodesLists = QAuxcodesList::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                        "limit" => 20,
                        "offset"=> $offset,
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = QAuxcodesList::count(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                $index = 0;
                $content = array();
                foreach ($qAuxcodesLists as $qAuxcodesList) {
                    $content[$index]["username"] = $qAuxcodesList->username;
                    $content[$index]["extension"] = $qAuxcodesList->extension;
                    $content[$index]["insert_date"] =  $this->formatTimezoneString($qAuxcodesList->insert_date, "m/d/Y", $timezone);
                    $content[$index]["insert_date2"] =  $this->formatTimezoneString($qAuxcodesList->insert_date, "h:i:s A", $timezone);
                    $content[$index]["auxcode_status"] = ucfirst($qAuxcodesList->auxcode_status);
                    $index++;
                }
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                        "count" => $count,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                $data = $this->request->getRawBody();
                $jsonData = json_decode($data);
                $startStamp = intval($jsonData->selectStart ?? 0);
                $endStamp = intval($jsonData->selectEnd ?? 0);
                $agent = $jsonData->selectAgent;
                $queue = $jsonData->selectQueue;
                $auxcode = $jsonData->selectAuxcode;
                $parameters["page"] = intval($jsonData->page ?? 0);
                $parameters["type"] = intval($jsonData->type ?? 0);
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                // extension is stored as "<agent ext> | <queue ext>"
                if ($agent != 0) { 
                    $conditions .= " AND extension ILIKE :agent_ext:";
                    $bind["agent_ext"] = $agent . " |%";
                }
                if (!empty($queue) && $queue !== "0") { 
                    $auxQueue = $this->findReportQueue($session["domain_uuid"], $queue);
                    $conditions .= " AND extension ILIKE :queue_ext:";
                    $bind["queue_ext"] = "%| " . ($auxQueue ? $auxQueue->queue_extension : $queue);
                }
                if (!empty($auxcode)) {
                    $conditions .= " AND auxcode_status ILIKE :auxcode_status:";
                    $bind["auxcode_status"] = "%". urldecode($auxcode) . "%";
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                } else if ($startStamp > 0) {
                    $conditions .= " AND insert_date > :startStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND insert_date < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                }
                $qAuxcodesLists = QAuxcodesList::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = QAuxcodesList::count(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                $index = 0;
                $content = array();
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                foreach ($qAuxcodesLists as $qAuxcodesList) {
                    $content[$index]["insert_date"] = $this->formatTimezoneString($qAuxcodesList->insert_date, "m/d/Y", $timezone);
                    $content[$index]["insert_date2"] =  $this->formatTimezoneString($qAuxcodesList->insert_date, "h:i:s A", $timezone);
                    $content[$index]["username"] = $qAuxcodesList->username;
                    $extension = explode(" | ", $qAuxcodesList->extension);
                    $content[$index]["extension"] = current($extension);
                    $content[$index]["queue"] = next($extension) == "0" ? "All" : current($extension);
                    $content[$index]["auxcode_status"] = ucfirst($qAuxcodesList->auxcode_status);
                    $index++;
                }
                $security = new \Phalcon\Security\Random();
                $filename = $security->uuid();
                $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                $header = [ "Date", "Time", "Username", "Extension", "Queue", "Auxcode"];
                fputcsv($fp, $header, ",");
                foreach ($content as $fields) { 
                    fputcsv($fp, $fields, ","); 
                }
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="output.csv";');
                fclose($fp);
                if ($parameters["type"] == 1) {
                    $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                    if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                        unlink("/var/www/ringq/cache/". $filename .".csv");
                    }
                } 
                if ($parameters["type"] == 2) {
                    $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Aux codes"]));
                    exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                    $response = json_encode(
                        [
                            "result" => "success",
                            "message" => "Generating reports.",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
            }
        }
    }

    public function agentactivityAction($page = 1, $username = "", $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            // ACTIVITY // AGENT, DATE
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                if (!empty($username)) { 
                    $conditions .= " AND username ILIKE :username:";
                    $bind["username"] = "%". $username . "%";
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND timestamp BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                } else if ($startStamp > 0) {
                    $conditions .= " AND timestamp > :startStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND timestamp < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                }
                
                $vUserLogs = VUserLogs::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        "limit" => 20,
                        "offset"=> $offset,
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = VUserLogs::count(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [ Column::BIND_PARAM_STR],
                    ]
                );
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                $index = 0;
                $content = array();
                foreach($vUserLogs as $vUserLog) {
                    $content[$index]["timestamp"] = $this->formatTimezoneString($vUserLog->timestamp, "m/d/Y", $timezone);
                    $content[$index]["timestamp2"] = $this->formatTimezoneString($vUserLog->timestamp, "h:i:s A", $timezone);
                    $content[$index]["username"] = $vUserLog->username;
                    $content[$index]["type"] = ucfirst($vUserLog->type);
                    $content[$index]["result"] = ucfirst($vUserLog->result);
                    $content[$index]["remote_address"] = $vUserLog->remote_address;
                    $content[$index]["user_agent"] = $vUserLog->user_agent;
                    $index++;
                }
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                        "count" => $count,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                $data = $this->request->getRawBody();
                $jsonData = json_decode($data);
                $startStamp = intval($jsonData->selectStart ?? 0);
                $endStamp = intval($jsonData->selectEnd ?? 0);
                $username = $jsonData->selectAgent;
                $parameters["page"] = intval($jsonData->page ?? 0);
                $parameters["type"] = intval($jsonData->type ?? 0);
                $timezone = $this->reportTimezone($session["domain_uuid"]);
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                if (!empty($username)) { 
                    $conditions .= " AND username ILIKE :username:";
                    $bind["username"] = "%". $username . "%";
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND timestamp BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", intval($startStamp), $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", intval($endStamp), $timezone);
                } else if ($startStamp > 0) {
                    $conditions .= " AND timestamp > :startStamp:";
                    $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", intval($startStamp), $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND timestamp < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", intval($endStamp), $timezone);
                }
                $vUserLogs = VUserLogs::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = VUserLogs::count(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [ Column::BIND_PARAM_STR],
                    ]
                );
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                $index = 0;
                $content = array();
                foreach ($vUserLogs as $vUserLog) {
                    $content[$index]["timestamp"] = $this->formatTimezoneString($vUserLog->timestamp, "m/d/Y", $timezone);
                    $content[$index]["timestamp2"] = $this->formatTimezoneString($vUserLog->timestamp, "h:i:s A", $timezone);
                    $content[$index]["username"] = $vUserLog->username;
                    $content[$index]["type"] = $vUserLog->type;
                    $content[$index]["result"] = $vUserLog->result;
                    $content[$index]["remote_address"] = $vUserLog->remote_address;
                    $content[$index]["user_agent"] = $vUserLog->user_agent;
                    $index++;
                }
                $security = new \Phalcon\Security\Random();
                $filename = $security->uuid();
                $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                $header = ["Date", "Time","Username", "Type", "Result", "IP address", "User Agent"];
                fputcsv($fp, $header, ",");
                foreach ($content as $fields) { 
                    fputcsv($fp, $fields, ","); 
                }
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="output.csv";');
                fclose($fp);
                if ($parameters["type"] == 1) {
                    $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                    if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                        unlink("/var/www/ringq/cache/". $filename .".csv");
                    }
                } 
                if ($parameters["type"] == 2) {
                    $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Activity"]));
                    exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                    $response = json_encode(
                        [
                            "result" => "success",
                            "message" => "Generating reports.",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
            }
        }
    }

    public function callratingAction($page = 1, $agent = 0, $startRating = 0, $queue = "", $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            // CALL RATING // AGENT, DATE
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                if ($agent != 0) { 
                    $conditions .= " AND extension_uuid ILIKE :extension:";
                    $bind["extension"] = "%". $agent . "%";
                }
                if ($startRating != 0) {
                    $conditions .= " AND rating = :rating:";
                    $bind["rating"] = $startRating;
                }
                if (!empty($queue) && $queue !== "0") { 
                    // queue_name is stored as "Name (ext)"
                    $ratingQueue = $this->findReportQueue($session["domain_uuid"], $queue);
                    $conditions .= " AND queue_name ILIKE :queue_name:";
                    $bind["queue_name"] = $ratingQueue ? "%(" . $ratingQueue->queue_extension . ")" : "%". $queue . "%";
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                } else if ($startStamp > 0) {
                    $conditions .= " AND insert_date > :startStamp:";
                    $bind["startStamp"] =  $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND insert_date < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);;
                }
                $qCallRatings = QCallRatings::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                        "limit" => 20,
                        "offset"=> $offset,
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = QCallRatings::count(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                $index = 0;
                $content = array();
                foreach ($qCallRatings as $qCallRating) {
                    list($content[$index]["caller_number"], $content[$index]["caller_name"]) = $this->ratingCaller($qCallRating);
                    $content[$index]["rating"] = $qCallRating->rating;
                    $content[$index]["extension_uuid"] = $qCallRating->extension_uuid;
                    $QRingqCdr = QRingqCdr::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND xml_cdr_uuid = :xml_cdr_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "xml_cdr_uuid" => $qCallRating->call_uuid,
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    $queue = "";
                    $recordName = "";
                    if ($QRingqCdr) {
                        $queue = $QRingqCdr->cc_queue;
                        $recordName = $QRingqCdr->record_name;
                    }
                    $content[$index]["xml_cdr_uuid"] = $qCallRating->call_uuid;
                    $content[$index]["queue"] = isset($qCallRating->queue_name) ? $qCallRating->queue_name : "";
                    $content[$index]["record_name"] = $recordName;
                    $content[$index]["insert_date"] =  $this->formatTimezoneString($qCallRating->insert_date, "m/d/Y", $timezone); 
                    $content[$index]["insert_date2"] =  $this->formatTimezoneString($qCallRating->insert_date, "h:i:s A", $timezone); 
                    $index++;
                }
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                        "count" => $count,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                $data = $this->request->getRawBody();
                $jsonData = json_decode($data);
                $startStamp = intval($jsonData->selectStart ?? 0);
                $endStamp = intval($jsonData->selectEnd ?? 0);
                $agent = $jsonData->selectAgent;
                $queue = $jsonData->selectQueue;
                $startRating = $jsonData->selectRating;
                $parameters["page"] = intval($jsonData->page ?? 0);
                $parameters["type"] = intval($jsonData->type ?? 0);
                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];
                if ($agent != 0) { 
                    $conditions .= " AND extension_uuid ILIKE :extension:";
                    $bind["extension"] = "%". $agent . "%";
                }
                if ($startRating != 0) {
                    $conditions .= " AND rating = :rating:";
                    $bind["rating"] = $startRating;
                }
                if (!empty($queue) && $queue !== "0") { 
                    // queue_name is stored as "Name (ext)"
                    $ratingQueue = $this->findReportQueue($session["domain_uuid"], $queue);
                    $conditions .= " AND queue_name ILIKE :queue_name:";
                    $bind["queue_name"] = $ratingQueue ? "%(" . $ratingQueue->queue_extension . ")" : "%". $queue . "%";
                }
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND insert_date BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);
                } else if ($startStamp > 0) {
                    $conditions .= " AND insert_date > :startStamp:";
                    $bind["startStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $startStamp, $timezone);
                } else if ($endStamp > 0) {
                    $conditions .= " AND insert_date < :endStamp:";
                    $bind["endStamp"] = $this->formatTimezoneUnix("Y-m-d H:i:sP", $endStamp, $timezone);
                }
                $qCallRatings = QCallRatings::find(
                    [
                        "conditions" => $conditions,
                        "bind" => $bind,
                        "bindTypes" => [Column::BIND_PARAM_STR],
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = QCallRatings::count(
                    [
                        "conditions" => "domain_uuid = :domain_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                $index = 0;
                $content = array();
                foreach ($qCallRatings as $qCallRating) {
                    $content[$index]["insert_date"] =  $this->formatTimezoneString($qCallRating->insert_date, "m/d/Y", $timezone); 
                    $content[$index]["insert_date2"] =  $this->formatTimezoneString($qCallRating->insert_date, "h:i:s A", $timezone); 
                    list($content[$index]["caller_number"], $content[$index]["caller_name"]) = $this->ratingCaller($qCallRating);
                    $content[$index]["queue"] = isset($qCallRating->queue_name) ? $qCallRating->queue_name : "";
                    $content[$index]["rating"] = $qCallRating->rating;
                    $content[$index]["extension_uuid"] = $qCallRating->extension_uuid;
                    $index++;
                }
                $security = new \Phalcon\Security\Random();
                $filename = $security->uuid();
                $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                $header = ["Date", "Time", "Caller Number", "Caller Name", "Queue Name", "Rating", "Extension"];
                fputcsv($fp, $header, ",");
                foreach ($content as $fields) { 
                    fputcsv($fp, $fields, ","); 
                }
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="output.csv";');
                fclose($fp);
                if ($parameters["type"] == 1) {
                    $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                    if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                        unlink("/var/www/ringq/cache/". $filename .".csv");
                    }
                } 
                if ($parameters["type"] == 2) {
                    $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Call ratings"]));
                    exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                    $response = json_encode(
                        [
                            "result" => "success",
                            "message" => "Generating reports.",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
            }
        }
    }
    
    public function agentsreportsAction($page = 1, $queue = 0, $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
            // DETAILED QUEUE // QUEUE, EXCLUDE DROP BEFORE SECONDS, DATE
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;

                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];

                if ($queue !== "0") { 
                    $conditions .= " AND queue_uuid = :queue_uuid:";
                    $bind["queue_uuid"] = $queue;
                }

                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND call_start_time BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] = $startStamp;
                    $bind["endStamp"] = $endStamp;
                }

                $qCallQueues = QCallQueues::find([
                    "conditions" => $conditions,
                    "bind" => $bind,
                    "bindTypes" => [Column::BIND_PARAM_STR],
                    "limit" => 20,
                    "offset" => $offset,
                    "order" => "insert_date DESC",
                ]);

                $count = QCallQueues::count([
                    "conditions" => $conditions,
                    "bind" => $bind,
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]);
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                $index = 0;
                $content = array();
                foreach ($qCallQueues as $qCallQueue) {
                    $vCallCenterQueues = VCallCenterQueues::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND call_center_queue_uuid = :call_center_queue_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "call_center_queue_uuid" => $qCallQueue->queue_uuid,
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    $content[$index]["total_call"] = $qCallQueue->missed_call +  $qCallQueue->abandoned_call +$qCallQueue->answered_call;
                    $content[$index]["missed_call"] = $qCallQueue->missed_call;
                    $content[$index]["abandoned_call"] = $qCallQueue->abandoned_call;
                    $content[$index]["answered_call"] = $qCallQueue->answered_call;
                    $content[$index]["waiting_time"] = $qCallQueue->waiting_time;
                    $content[$index]["talking_time"] = $qCallQueue->talking_time;
                    $content[$index]["queue_uuid"] = $vCallCenterQueues->queue_extension;
                    $content[$index]["successful_callbacks"] = $qCallQueue->successful_callbacks;
                    $content[$index]["insert_date"] = $this->formatTimezoneString("m/d/Y", $qCallQueue->insert_date, $timezone);
                    $index++;
                }
                $response = json_encode(
                    [
                        "result" => "success",
                        "content" => $content,
                        "count" => $count,
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            } else if ($this->request->isPost()) {
                $parameters = array();
                $parameters["page"] = intval($this->request->getPost("page", "int"));
                $parameters["type"] = intval($this->request->getPost("type", "int"));
                if (empty($parameters["page"])) {
                    $contents = file_get_contents("php://input");
                    $parameters = json_decode($contents, true);
                }
                $offset = $page > 1 ? intval($parameters["page"]) * 20 - 20 : 0;
                $qCallQueues = QCallQueues::find(
                    [
                        "conditions" => "domain_uuid = :domain_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                        "limit" => 20,
                        "offset"=> $offset,
                        'order' => 'insert_date DESC'
                    ]
                );
                $count = QCallQueues::count(
                    [
                        "conditions" => "domain_uuid = :domain_uuid:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR],
                    ]
                );
                $timezone = date_default_timezone_get();
                $vUserSettings = VUserSettings::findFirst(
                    [
                        "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                        "bind" =>
                            [
                                "domain_uuid" => $session["domain_uuid"],
                                "user_setting_subcategory" => "time_zone"
                            ],
                        "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                    ]
                );
                if ($vUserSettings) {
                    $timezone = $vUserSettings->user_setting_value;
                }
                $index = 0;
                $content = array();
                foreach ($qCallQueues as $qCallQueue) {
                    $content[$index]["missed_call"] = $qCallQueue->missed_call;
                    $content[$index]["abandoned_call"] = $qCallQueue->abandoned_call;
                    $content[$index]["answered_call"] = $qCallQueue->answered_call;
                    $content[$index]["waiting_time"] = $qCallQueue->waiting_time;
                    $content[$index]["talking_time"] = $qCallQueue->talking_time;
                    $content[$index]["queue_uuid"] = $qCallQueue->queue_uuid;
                    $content[$index]["successful_callbacks"] = $qCallQueue->recording_path;
                    $content[$index]["insert_date"] = $this->formatTimezoneString("m/d/Y", $qCallQueue->insert_date, $timezone);
                    $index++;
                }
                $security = new \Phalcon\Security\Random();
                $filename = $security->uuid();
                $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                $header = ["Username", "Extension", "Date", "Auxcode"];
                fputcsv($fp, $header, ",");
                foreach ($content as $fields) { 
                    fputcsv($fp, $fields, ","); 
                }
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="output.csv";');
                fclose($fp);
                if ($parameters["type"] == 1) {
                    $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                    if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                        unlink("/var/www/ringq/cache/". $filename .".csv");
                    }
                }
                if ($parameters["type"] == 2) {
                    $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Agents"]));
                    exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                    $response = json_encode(
                        [
                            "result" => "success",
                            "message" => "Generating reports.",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
            }
        }
    }

    public function callrecordingsAction()
    {
        // Disable view rendering
            $this->view->disable();
            $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
            $this->response->setHeader("Content-Type", "application/json");
            $session = $this->session->get("auth");
            if ($session) {
                // Get recording ID
                $id = $this->request->getQuery('id', 'string');
                if (!$id) {
                    return $this->response->setStatusCode(400, "Bad Request")
                                        ->setContent("Missing 'id' parameter.")
                                        ->send();
                }

                $id = basename($id);
                if (!preg_match('/^[A-Za-z0-9._-]+$/', $id) || $this->isPlainUser($session)) {
                    return $this->response->setStatusCode(404, "Not Found")
                                        ->setContent("Recording not found.")
                                        ->send();
                }

                $basePath = '/var/lib/freeswitch/recordings/' . basename((string) $session["subdomain"]) . '/archive';

                $filePath = '';
                if (is_dir($basePath)) {
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS));
                    foreach ($files as $file) {
                        if ($file->isFile() && $file->getFilename() === $id) {
                            $filePath = $file->getPathname();
                            break;
                        }
                    }
                }

                if (!$filePath || !file_exists($filePath)) {
                    return $this->response->setStatusCode(404, "Not Found")
                                        ->setContent("Recording not found.")
                                        ->send();
                }

                // Set headers before output
                header('Content-Type: audio/wav');
                header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
                header('Content-Length: ' . filesize($filePath));

                // Clean any previous output buffer
                while (ob_get_level()) {
                    ob_end_clean();
                }

                // Output file
                readfile($filePath);

                // Stop Phalcon from continuing
                exit;
            } else {
                $response = json_encode(
                    [
                        "result" => "error",
                        "message" => "Unathorized. (Code AI1004)",
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            }
    }

    public function audiototextoldAction($id = 0)
    {
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        if (!$id) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        $id = $this->filter->sanitize($id, "string");
        $QRingqCdr = QRingqCdr::findFirst(
            [
                "conditions" => "xml_cdr_uuid = :xml_cdr_uuid: AND domain_uuid = :domain_uuid:",
                "bind" =>
                    [
                        "xml_cdr_uuid" => $id,
                        "domain_uuid" => $session["domain_uuid"],
                    ],
                "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
            ]
        );
        if (!$QRingqCdr) {
            $response = json_encode([
                "result" => "error",
                "message" => "Call ID is missing or already deleted. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        if ($QRingqCdr->json) {
            $response = json_encode([
                "result" => "success",
                "content" => json_decode($QRingqCdr->json),
                "message" => "Trascription already existing. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        if (!$QRingqCdr->record_path || !$QRingqCdr->record_name) {
            $response = json_encode([
                "result" => "error",
                "message" => "Call recording is missing or already deleted. (Code AI1004)",
            ]);
            return $this->response->setContent($response);

        }

        $basePath = $QRingqCdr->record_path;
        $filePath = $basePath . "/" . $QRingqCdr->record_name;
        if (!file_exists($filePath)) {
            return $this->response->setStatusCode(404)->setContent("File not found.")->send();
        }

        $transcribeEnabled = $this->redis->get("ai:" . $session["subdomain"] . "-transcribeTokenEnabled");
        if (!$transcribeEnabled || $transcribeEnabled == "2") {
            $response = json_encode([
                "result" => "error",
                "message" => "Sorry, please enable AI transcription. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        $apiKey = $this->redis->get("ai:" . $session["subdomain"] . "-transcribeToken");
        if (!$apiKey) {
            $response = json_encode([
                "result" => "error",
                "message" => "Sorry, you do not have AI transcribe token set. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        // STEP 1: Transcription
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/audio/transcriptions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'file' => new CURLFile($filePath, 'audio/wav'),
            'model' => 'whisper-1'
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiKey"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $rawText = $data['text'] ?? 'No transcription available.';
        $transcribedText2 = htmlspecialchars($rawText);

        // STEP 2: Summary & Sentiment using ChatGPT
        $chatRequest = [
            "model" => "gpt-3.5-turbo",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "You are an AI assistant that summarizes and analyzes the sentiment of customer service call transcriptions."
                ],
                [
                    "role" => "user",
                    "content" => "Here's a transcribed phone call:\n\n{$rawText}\n\nPlease:\n1. Summarize the call.\n2. Detect the overall sentiment (positive, negative, or neutral).\nReturn result as JSON with 'summary' and 'sentiment'."
                ]
            ]
        ];

        $chGPT = curl_init();
        curl_setopt($chGPT, CURLOPT_URL, "https://api.openai.com/v1/chat/completions");
        curl_setopt($chGPT, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chGPT, CURLOPT_POST, true);
        curl_setopt($chGPT, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json"
        ]);
        curl_setopt($chGPT, CURLOPT_POSTFIELDS, json_encode($chatRequest));
        $gptResponse = curl_exec($chGPT);
        curl_close($chGPT);

        $gptData = json_decode($gptResponse, true);
        $chatOutput = json_decode($gptData['choices'][0]['message']['content'] ?? '{}', true);

        $summary2 = htmlspecialchars($chatOutput['summary'] ?? 'No summary available.');
        $sentiment2 = strtolower(trim($chatOutput['sentiment'] ?? 'unknown'));

        $summary = $this->filter->sanitize($summary2, "string");
        $sentiment = $this->filter->sanitize($sentiment2, "string");
        $transcribedText = $this->filter->sanitize($transcribedText2, "string");
        $QRingqCdr->json = json_encode(["summary" => $summary, "sentiment" => $sentiment, "transcribedText" => $transcribedText]);
        if(!$QRingqCdr->update()) {
            $this->db->rollback();
            $response = json_encode(
                [
                    "result" => "error",
                    "message" => "An error has occurred. (Code AT21003)",
                ]
            );
            $this->response->setContent($response);
            return $this->response;
        }

        $response = json_encode([
            "result" => "success",
            "content" => json_decode($QRingqCdr->json),
        ]);
        return $this->response->setContent($response);

    }

    public function audiototextAction($id = 0, $type = 'calllogs')
    {
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        if (!$id) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        $id = $this->filter->sanitize($id, "string");


    if ($type === 'voicemail') {

        $vVoicemailMessages = VVoicemailMessages::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND voicemail_message_uuid = :id:",
            "bind" => [
                "domain_uuid" => $session["domain_uuid"],
                "id" => $id,
            ],
        ]);

        if (!$vVoicemailMessages) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Voicemail not found"
            ]));
        }
        if ($this->isPlainUser($session) && (string) $vVoicemailMessages->vVoicemails->voicemail_id !== (string) $session["extension"]) {
            return $this->forbiddenForUser();
        }
        if ($vVoicemailMessages->message_transcription) {
                $response = json_encode([
                    "result" => "success",
                    "content" => json_decode($vVoicemailMessages->message_transcription),
                    "message" => "Transcription already existing. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }

        $extension = $vVoicemailMessages->vVoicemails->voicemail_id;

        $basePath = "/var/lib/freeswitch/storage/voicemail/default/{$session["subdomain"]}/{$extension}/msg_{$id}";

        if (file_exists($basePath . ".wav")) {
            $filePath = $basePath . ".wav";
        } elseif (file_exists($basePath . ".mp3")) {
            $filePath = $basePath . ".mp3";
        }
    } else {
            if ($this->isPlainUser($session)) {
                return $this->forbiddenForUser();
            }
            $QRingqCdr = QRingqCdr::findFirst(
                [
                    "conditions" => "xml_cdr_uuid = :xml_cdr_uuid: AND domain_uuid = :domain_uuid:",
                    "bind" =>
                        [
                            "xml_cdr_uuid" => $id,
                            "domain_uuid" => $session["domain_uuid"],
                        ],
                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                ]
            );
            if (!$QRingqCdr) {
                $response = json_encode([
                    "result" => "error",
                    "message" => "Call ID is missing or already deleted. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }
            if ($QRingqCdr->json) {
                $response = json_encode([
                    "result" => "success",
                    "content" => json_decode($QRingqCdr->json),
                    "message" => "Trascription already existing. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }

            if (!$QRingqCdr->record_path || !$QRingqCdr->record_name) {
                $response = json_encode([
                    "result" => "error",
                    "message" => "Call recording is missing or already deleted. (Code AI1004)",
                ]);
                return $this->response->setContent($response);

            }

            $basePath = $QRingqCdr->record_path;
            $filePath = $basePath . "/" . $QRingqCdr->record_name;
        }


        if (!file_exists($filePath)) {
            return $this->response->setStatusCode(404)->setContent("File not found.")->send();
        }

        $transcribeEnabled = $this->redis->get("ai:" . $session["subdomain"] . "-transcribeTokenEnabled");
        if (!$transcribeEnabled || $transcribeEnabled == "2") {
            $response = json_encode([
                "result" => "error",
                "message" => "Sorry, please enable AI transcription. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        $apiKey = $this->redis->get("ai:" . $session["subdomain"] . "-transcribeToken");
       
  
        $aitranscripttype = $this->redis->get("ai:" . $session["subdomain"] . "-aitranscripttype");

        $ringq_model = $this->redis->get("ai:" . $session["subdomain"] . "-ringq_model");


        // fallback default
        if (empty($aitranscripttype)) {
            $aitranscripttype = 'openai';
        }


        // print_r("Using transcription type: " . $aitranscripttype . "\n"); 
        // echo $filePath . "\n"; exit;

        if ($aitranscripttype === 'ringq') {

            if (!file_exists($filePath)) {
                die("File NOT found: " . $filePath);
            }

            if (!is_readable($filePath)) {
                die("File NOT readable: " . $filePath);
            }




            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://aicloud.ringq.io/ai/transcribe',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => array(
                    'file' => new CURLFile($filePath, mime_content_type($filePath), basename($filePath)),
                    'engine' => $ringq_model ?: 'tiny'
                ),
                CURLOPT_HTTPHEADER => array(
                    'X-API-Key: CHANGE_ME'
                ),
            ));

            $response = curl_exec($curl);

         
            if ($response === false) {
                echo 'cURL Error: ' . curl_error($curl);
                $rawText = 'No transcription available.';
            } else {
                $data = json_decode($response, true);
                $rawText = '';
                if (!empty($data['text'])) {
                    $rawText = $data['text'];
                } elseif (!empty($data['transcription'])) {
                    $rawText = $data['transcription'];
                } elseif (!empty($data['result'])) {
                    if (is_array($data['result'])) {
                        // foreach ($data['result'] as $segment) {
                        //     if (is_array($segment)) {
                        //         $speaker = $segment['speaker'] ?? '';
                        //         $text = $segment['text'] ?? '';
                        //         $rawText .= "{$speaker}: {$text}\n";
                        //     } else {
                        //         $rawText .= $segment . "\n";
                        //     }
                        // }
                           $rawText = $data['text'];
                    } else {
                        $rawText = $data['result'];
                    }
                } else {
                    $rawText = 'No transcription available.';
                }
            }
            curl_close($curl);

            $transcribedText2 = htmlspecialchars($rawText);
            $transcribedText = $this->filter->sanitize($transcribedText2, "string");
           
        } else {
            if (!$apiKey) {
                $response = json_encode([
                    "result" => "error",
                    "message" => "Sorry, you do not have AI transcribe token set. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }

            // STEP 1: Transcription
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/audio/transcriptions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($filePath, 'audio/wav'),
                'model' => 'whisper-1'
            ]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $apiKey"
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            $rawText = $data['text'] ?? 'No transcription available.';
            $transcribedText2 = htmlspecialchars($rawText);
            $transcribedText = $this->filter->sanitize($transcribedText2, "string");
        }

        // AI agent updates
        if ($type === 'voicemail') {
            $vVoicemailMessages->message_transcription = json_encode(["summary" => 'No Summary Available', "sentiment" => 'neutral', "transcribedText" => $transcribedText]);
            if(!$vVoicemailMessages->update()) {
                $response = json_encode(
                    [
                        "result" => "error",
                        "message" => "An error has occurred. (Code AT21003)",
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            }

            $response = json_encode([
                "result" => "success",
                "content" => json_decode($vVoicemailMessages->message_transcription),
            ]);
            return $this->response->setContent($response);
        } else {
            $QRingqCdr->json = json_encode(["summary" => 'No Summary Available', "sentiment" => 'neutral', "transcribedText" => $transcribedText]);
            if(!$QRingqCdr->update()) {
                $this->db->rollback();
                $response = json_encode(
                    [
                        "result" => "error",
                        "message" => "An error has occurred. (Code AT21003)",
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            }

            $response = json_encode([
                "result" => "success",
                "content" => json_decode($QRingqCdr->json),
            ]);
            return $this->response->setContent($response);
        }

    }
 public function nodeaudiototextAction($id = 0, $domain_name)
    {
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $ip = $this->request->getClientAddress();

        $internalKey = $this->request->getHeader("X-Internal-Key");

        if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            // Otherwise require a normal user session
            $session = $this->session->get("auth");

            if (!$session) {
                return $this->response->setJsonContent([
                    "result" => "error",
                    "message" => "Unauthorized"
                ]);
            }
        }
        if (!$id) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        $id = $this->filter->sanitize($id, "string");
        $domain_name = $this->filter->sanitize($domain_name, "string");

            $QRingqCdr = QRingqCdr::findFirst([
                "conditions" => "(xml_cdr_uuid = :uuid: OR originating_leg_uuid = :uuid:)",
                "bind" => [
                    "uuid" => $id,
                ],
                "bindTypes" => [
                    "uuid" => Column::BIND_PARAM_STR,
                    "domain_uuid" => Column::BIND_PARAM_STR,
                ],
            ]);
            if (!$QRingqCdr) {
                $response = json_encode([
                    "result" => "error",
                    "message" => "Call ID is missing or already deleted. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }
            if ($QRingqCdr->json) {
                $response = json_encode([
                    "result" => "success",
                    "content" => json_decode($QRingqCdr->json),
                    "message" => "Trascription already existing. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }

            if (!$QRingqCdr->record_path || !$QRingqCdr->record_name) {
                $response = json_encode([
                    "result" => "error",
                    "message" => "Call recording is missing or already deleted. (Code AI1004)",
                ]);
                return $this->response->setContent($response);

            }

            $basePath = $QRingqCdr->record_path;
            $filePath = $basePath . "/" . $QRingqCdr->record_name;
        


        if (!file_exists($filePath)) {
            return $this->response->setStatusCode(404)->setContent("File not found.")->send();
        }

        $transcribeEnabled = $this->redis->get("ai:" . $domain_name . "-transcribeTokenEnabled");
        if (!$transcribeEnabled || $transcribeEnabled == "2") {
            $response = json_encode([
                "result" => "error",
                "message" => "Sorry, please enable AI transcription. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        $apiKey = $this->redis->get("ai:" . $domain_name . "-transcribeToken");
       
  
        $aitranscripttype = $this->redis->get("ai:" . $domain_name . "-aitranscripttype");

        $ringq_model = $this->redis->get("ai:" . $domain_name . "-ringq_model");


        // fallback default
        if (empty($aitranscripttype)) {
            $aitranscripttype = 'openai';
        }


        // print_r("Using transcription type: " . $aitranscripttype . "\n"); 
        // echo $filePath . "\n"; exit;

        if ($aitranscripttype === 'ringq') {

            if (!file_exists($filePath)) {
                die("File NOT found: " . $filePath);
            }

            if (!is_readable($filePath)) {
                die("File NOT readable: " . $filePath);
            }

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://aicloud.ringq.io/ai/transcribe',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => array(
                    'file' => new CURLFile($filePath, mime_content_type($filePath), basename($filePath)),
                    'engine' => $ringq_model ?: 'tiny'
                ),
                CURLOPT_HTTPHEADER => array(
                    'X-API-Key: CHANGE_ME'
                ),
            ));

            $response = curl_exec($curl);

         
            if ($response === false) {
                echo 'cURL Error: ' . curl_error($curl);
                $rawText = 'No transcription available.';
            } else {
                $data = json_decode($response, true);
                $rawText = '';
                if (!empty($data['text'])) {
                    $rawText = $data['text'];
                } elseif (!empty($data['transcription'])) {
                    $rawText = $data['transcription'];
                } elseif (!empty($data['result'])) {
                    if (is_array($data['result'])) {
                        // foreach ($data['result'] as $segment) {
                        //     if (is_array($segment)) {
                        //         $speaker = $segment['speaker'] ?? '';
                        //         $text = $segment['text'] ?? '';
                        //         $rawText .= "{$speaker}: {$text}\n";
                        //     } else {
                        //         $rawText .= $segment . "\n";
                        //     }
                        // }
                           $rawText = $data['text'];
                    } else {
                        $rawText = $data['result'];
                    }
                } else {
                    $rawText = 'No transcription available.';
                }
            }
            curl_close($curl);

            $transcribedText2 = htmlspecialchars($rawText);
            $transcribedText = $this->filter->sanitize($transcribedText2, "string");
           
        } else {
            if (!$apiKey) {
                $response = json_encode([
                    "result" => "error",
                    "message" => "Sorry, you do not have AI transcribe token set. (Code AI1004)",
                ]);
                return $this->response->setContent($response);
            }

            // STEP 1: Transcription
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/audio/transcriptions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, [
                'file' => new CURLFile($filePath, 'audio/wav'),
                'model' => 'whisper-1'
            ]);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $apiKey"
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            $rawText = $data['text'] ?? 'No transcription available.';
            $transcribedText2 = htmlspecialchars($rawText);
            $transcribedText = $this->filter->sanitize($transcribedText2, "string");
        }
            $QRingqCdr->json = json_encode(["summary" => 'No Summary Available', "sentiment" => 'neutral', "transcribedText" => $transcribedText]);
            if(!$QRingqCdr->update()) {
                $this->db->rollback();
                $response = json_encode(
                    [
                        "result" => "error",
                        "message" => "An error has occurred. (Code AT21003)",
                    ]
                );
                $this->response->setContent($response);
                return $this->response;
            }

            $response = json_encode([
                "result" => "success",
                "content" => json_decode($QRingqCdr->json),
            ]);
            return $this->response->setContent($response);
        

    }

// Voicemail Transcription using RingQ API To


    public function agentsreportAction($page = 1, $queue = 0, $startStamp = 0, $endStamp = 0)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if ($session) {
                    
            if ($this->request->isGet()) {
                $offset = $page > 1 ? intval($page) * 20 - 20 : 0;
                
                // 1. Get all queues or specific queue
                $queueConditions = "domain_uuid = :domain_uuid:";
                $queueBind = ["domain_uuid" => $session["domain_uuid"]];
                
                if ($queue !== "0") {
                    $queueConditions .= " AND call_center_queue_uuid = :queue_uuid:";
                    $queueBind["queue_uuid"] = $queue;
                }
                
                $queues = VCallCenterQueues::find([
                    "conditions" => $queueConditions,
                    "bind" => $queueBind
                ]);
                
                $content = [];
                
                // 2. Process each queue
                foreach ($queues as $queueItem) {
                    $queueExtension = $queueItem->queue_name . " - " . $queueItem->queue_extension;
                    
                    // 3. Get calls for this queue
                    $callConditions = "domain_uuid = :domain_uuid: AND queue_uuid = :queue_uuid:";
                    $callBind = [
                        "domain_uuid" => $session["domain_uuid"],
                        "queue_uuid" => $queueItem->call_center_queue_uuid
                    ];
                    
                    if ($startStamp > 0 && $endStamp > 0) {
                        $callConditions .= " AND call_start_time BETWEEN :startStamp: AND :endStamp:";
                        $callBind["startStamp"] = $startStamp;
                        $callBind["endStamp"] = $endStamp;
                    }
                    
                    $calls = QCallQueuesList::find([
                        "conditions" => $callConditions,
                        "bind" => $callBind
                    ]);
                    
                    // Track agents per queue
                    $queueAgents = [];
                    
                    // 4. Process each call in the queue
                    foreach ($calls as $call) {
                        $agentExtension = $call->callee_id_number;
                        
                        // Initialize agent stats if not exists
                        if (!isset($queueAgents[$agentExtension])) {
                            $queueAgents[$agentExtension] = [
                                'queue_uuid' => $queueExtension,
                                'agent_extension' => $agentExtension,
                                'total_call' => 0,
                                'missed_call' => 0,
                                'abandoned_call' => 0,
                                'answered_call' => 0,
                                'waiting_time' => 0,
                                'talking_time' => 0,
                                'successful_callbacks' => 0
                            ];
                        }
                        
                        // Increment total calls
                        $queueAgents[$agentExtension]['total_call']++;
                        
                        // 5. Get call details from CDR
                        $cdr = QRingqCdr::findFirst([
                            "conditions" => "domain_uuid = :domain_uuid: AND (xml_cdr_uuid = :call_uuid: OR originating_leg_uuid = :call_uuid:)",
                            "bind" => [
                                "domain_uuid" => $session["domain_uuid"],
                                "call_uuid" => $call->call_uuid
                            ]
                        ]);
                        
                        if ($cdr) {
                            // Classify call type
                            if ($cdr->billsec > 0) {
                                $queueAgents[$agentExtension]['answered_call']++;
                                $queueAgents[$agentExtension]['talking_time'] += $cdr->billsec;
                            } elseif (empty($cdr->answer_stamp) && $cdr->duration > 0) {
                                $queueAgents[$agentExtension]['missed_call']++;
                            } else {
                                $queueAgents[$agentExtension]['abandoned_call']++;
                            }
                            
                            // Add waiting time
                            $queueAgents[$agentExtension]['waiting_time'] += $cdr->waitsec;

                            $timezone = date_default_timezone_get();
                            $vUserSettings = VUserSettings::findFirst(
                                [
                                    "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
                                    "bind" =>
                                        [
                                            "domain_uuid" => $session["domain_uuid"],
                                            "user_setting_subcategory" => "time_zone"
                                        ],
                                    "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                                ]
                            );
                            if ($vUserSettings) {
                                $timezone = $vUserSettings->user_setting_value;
                            }
                            $dateTime = new DateTime($cdr->insert_date, new DateTimeZone("UTC"));
                            $dateTime->setTimezone(new DateTimeZone($timezone));
                            $queueAgents[$agentExtension]['insert_date']= $dateTime->format("m/d/Y h:i:s A");

                            // Check for successful callback (customize this logic)
                            if ($this->isSuccessfulCallback($call, $cdr)) {
                                $queueAgents[$agentExtension]['successful_callbacks']++;
                            }
                        } else {
                            // If no CDR found, count as abandoned
                            $queueAgents[$agentExtension]['abandoned_call']++;
                        }
                    }
                    
                    // Add all agents for this queue to the content
                    foreach ($queueAgents as $agentReport) {
                        $agentReport['waiting_time_formatted'] = $this->formatTime($agentReport['waiting_time']);
                        $agentReport['talking_time_formatted'] = $this->formatTime($agentReport['talking_time']);
                        
                        $content[] = $agentReport;
                    }
                }
                
                // Apply pagination
                $paginatedContent = array_slice($content, $offset, 20);
                $totalCount = count($content);
                
                // Return response
                $response = json_encode([
                    "result" => "success",
                    "content" => $paginatedContent,
                    "count" => $totalCount,
                ]);
                
                $this->response->setContent($response);
                return $this->response;
            }     
            else if ($this->request->isPost()) {                
                $data = $this->request->getRawBody();
                $jsonData = json_decode($data);
                $startStamp = intval($jsonData->selectStart ?? 0);
                $endStamp = intval($jsonData->selectEnd ?? 0);
                $queue = $jsonData->selectQueue;               
                $parameters["page"] = intval($jsonData->page ?? 0);
                $parameters["type"] = intval($jsonData->type ?? 0);
                $timestamp = $this->redis->get($session["extension"] ."@". $session["subdomain"] ."-deletion");
                $chatgptAIToken = $this->redis->get("ai:" . $session["subdomain"] . "-token");


                if (!isset($timestamp)) {
                    $timestamp = 0;
                }


                $conditions = "domain_uuid = :domain_uuid:";
                $bind = [
                    "domain_uuid" => $session["domain_uuid"],
                ];

                if ($queue !== "0") { 
                    $conditions .= " AND queue_uuid = :queue_uuid:";
                    $bind["queue_uuid"] = $queue;
                }

                if ($startStamp > 0 && $endStamp > 0) {
                    $conditions .= " AND call_start_time BETWEEN :startStamp: AND :endStamp:";
                    $bind["startStamp"] = $startStamp;
                    $bind["endStamp"] = $endStamp;
                }

                $qCallQueuesList = QCallQueuesList::find([
                    "conditions" => $conditions,
                    "bind" => $bind,
                    "bindTypes" => [Column::BIND_PARAM_STR],
                  
                    "order" => "insert_date DESC",
                ]);

                $count = QCallQueuesList::count([
                    "conditions" => $conditions,
                    "bind" => $bind,
                    "bindTypes" => [Column::BIND_PARAM_STR],
                ]);

                $index = 0;
                $content = array();
                foreach ($qCallQueuesList as $qCallQueue) {
                    $vCallCenterQueues = VCallCenterQueues::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND call_center_queue_uuid = :call_center_queue_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "call_center_queue_uuid" => $qCallQueue->queue_uuid,
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );

                    $QRingqCdr = QRingqCdr::findFirst(
                        [
                            "conditions" => "domain_uuid = :domain_uuid: AND xml_cdr_uuid = :xml_cdr_uuid:",
                            "bind" =>
                                [
                                    "domain_uuid" => $session["domain_uuid"],
                                    "xml_cdr_uuid" => $qCallQueue->call_uuid
                                ],
                            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
                        ]
                    );
                    $record_name = "";
                    if ($QRingqCdr) {
                        // $QRingqCdr = $QRingqCdr->toArray();
                        $record_name = $QRingqCdr->record_name;
                        $call_duration = $QRingqCdr->duration;
                    }

                    $cal_start_data = !empty($qCallQueue->call_start_time) ? date("m/d/Y", intval($qCallQueue->call_start_time)) : "";
                    $cal_start_time = !empty($qCallQueue->call_start_time) ? date("h:i A", intval($qCallQueue->call_start_time)) : "";
                    $call_answered_time = !empty($qCallQueue->call_answered_time) ? date("h:i A", intval($qCallQueue->call_answered_time)) : "";
                    $call_end_time = !empty($qCallQueue->call_end_time) ? date("h:i A", intval($qCallQueue->call_end_time)) : "";
                   
                   if($qCallQueue->call_answered_time != null && $qCallQueue->call_time_to_answer == "0"){
                        $call_time_to_answer = $qCallQueue->call_answered_time - $qCallQueue->call_start_time;
                   }

                   $hours = floor($call_duration / 3600);
                   $minutes = floor(($call_duration % 3600) / 60);
                   $seconds = $call_duration % 60;
                   $formatted_time = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
                   $w_time = $qCallQueue->call_waiting_time;
                   $hours = floor($w_time / 3600);
                   $minutes = floor(($w_time % 3600) / 60);
                   $seconds = $w_time % 60;
                   $w_time = $w_time > 0 ? sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds) : "--";
                    $content[$index]["call_start_date"] = $cal_start_data;
                    $content[$index]["caller_id_name"] = $qCallQueue->caller_id_name;
                    $content[$index]["queue_uuid"] = $vCallCenterQueues->queue_name . " - " . $vCallCenterQueues->queue_extension;
                    $content[$index]["qbc_call"] = $qCallQueue->qbc_call;
                    $content[$index]["callee_id_name"] = $qCallQueue->callee_id_name;
                    $content[$index]["callee_id_number"] = $qCallQueue->callee_id_number;
                    $content[$index]["call_direction"] = $qCallQueue->call_direction;
                    $content[$index]["call_type"] = $qCallQueue->call_type;
                    $content[$index]["call_duration"] = $formatted_time;
                    $content[$index]["call_start_time"] = $cal_start_time;
                    $content[$index]["call_answered_time"] = !empty($cal_start_time) ? $call_answered_time : "--";
                    $content[$index]["call_waiting_time"] = $w_time;
                    $content[$index]["call_end_time"] = $call_end_time;
                    $content[$index]["call_time_to_answer"] = $call_time_to_answer;
                    
                    $index++;
                }

                $security = new \Phalcon\Security\Random();
                $filename = $security->uuid();
                $fp = fopen('/var/www/ringq/cache/'. $filename .'.csv', 'w');
                $header = ["Date", "From Number", "Queue Name", "Qcb call", "To Name", "Extension", "Call Natue", "Cal Type", "Duration", "Queue Time Start", "Agent Answered Call Time Start", "Agent Call Waiting Time","Agent Call End Time", "Time to Answer(Queue Time)"];
                fputcsv($fp, $header, ",");
                foreach ($content as $fields) { 
                    fputcsv($fp, $fields, ","); 
                }
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="output.csv";');
                fclose($fp);
                if ($parameters["type"] == 1) {
                    $this->response->setFileToSend("/var/www/ringq/cache/". $filename .".csv", $filename .".csv")->send();
                    if (file_exists("/var/www/ringq/cache/". $filename .".csv")) {
                        unlink("/var/www/ringq/cache/". $filename .".csv");
                    }
                }
                if ($parameters["type"] == 2) {
                    $this->redis->lpush("report", json_encode(["email" => $session["email"], "file" => $filename .".csv", "domain_uuid" => $session["domain_uuid"], "label" => "Agents"]));
                    exec('/usr/bin/php /var/www/ringq/app/cli.php main main7', $output, $result);
                    $response = json_encode(
                        [
                            "result" => "success",
                            "message" => "Generating reports.",
                            "content" => [],
                        ]
                    );
                    $this->response->setContent($response);
                    return $this->response;
                }
            }
        }
    }

    /**
     * A report's queue filter. The web sends the queue's call_center_queue_uuid; the
     * schedule form and older clients send its extension. Accept either.
     */
    protected function findReportQueue($domainUuid, $value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return null;
        }
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
        return VCallCenterQueues::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND " . ($isUuid ? "call_center_queue_uuid = :value:" : "queue_extension = :value:"),
            "bind" => ["domain_uuid" => $domainUuid, "value" => $value],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]) ?: null;
    }

    /** Older call ratings were stored with caller number and name swapped; show them the right way round. */
    protected function ratingCaller($qCallRating) {
        $number = (string) $qCallRating->caller_number;
        $name = (string) $qCallRating->caller_name;
        $looksLikeNumber = function ($v) { return $v !== '' && preg_match('/^[+0-9*#\s()-]+$/', $v); };
        if (!$looksLikeNumber($number) && $looksLikeNumber($name)) {
            return [$name, $number];
        }
        return [$number, $name];
    }

    protected function formatTimezoneString($dateString, $format, $targetTimezone) {
        $date = new DateTime($dateString, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone($targetTimezone));
        return $date->format($format);
    }

    protected function formatTimezoneUnix($format, $unixTime = 0, $timezone) {
        $result = "";
        if ($unixTime != 0) {
            $timestamp = $unixTime;
            $timezone = new DateTimeZone($timezone);
            $date = new DateTime("@$timestamp"); 
            $date->setTimezone($timezone);
            $result = $date->format($format);
        }
        return $result;
    }

    protected function formatTime($seconds) {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        return sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
    }

    protected function isSuccessfulCallback($call, $cdr) {
        // Implement your callback success logic here
        return false; // Default implementation
    }

    // MR Start - Get the interfaces in the system
    public function getInterfacesAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]));
        }

        // Use `ip -brief a` to get all interfaces
        $output = shell_exec("ip -brief a");

        if (!$output) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unable to retrieve interfaces.",
            ]));
        }

        // Parse the result
        $interfaces = [];
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (isset($parts[0]) && $parts[0] !== 'lo') {
                $interfaces[] = [
                    'name' => $parts[0],
                    'ip' => $parts[2] ?? null
                ];
            }
        }


        $domain = $session['subdomain'] ?? 'default';
        $pidFile = "/var/pcap/capture-{$domain}.pid";

        $captureStatus = "not_started";
        $captureStarted = null;
        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            // Optional: check if process still running
            if (posix_kill((int)$pid, 0)) {
                $captureStatus = "started";
                // startCaptureAction writes the pid file once, when tshark
                // starts, so its mtime is the capture's start time.
                $mtime = @filemtime($pidFile);
                $captureStarted = $mtime ? (int) $mtime : null;
            } else {
                // Clean up stale pid file
                unlink($pidFile);
            }
        }
        $response = json_encode([
            "result" => "success",
            "interfaces" => $interfaces,
            "capture_status" => $captureStatus,
            "capture_started" => $captureStarted
        ]);
        $this->response->setContent($response);
        return $this->response;
    }

    // MR - Start the Network Capture start - 12/05/2026
    public function startCaptureAction($interface = null)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]));
        }

        if ($this->request->isPost()) {
            // Prefer POST param if passed, else fallback to URL param
            $interface = $this->request->getPost("interface", "string", $interface);
            if (!$interface) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Invalid interface selected. (Code AI1006)",
                ]));
            }

            $duration = 300; // EMAN ADDED JULY 13, 2026
            $domain = $session['subdomain'] ?? 'default';
            $filePath = "/var/pcap/capture-{$domain}.pcap";
            $pidFile = "/var/pcap/capture-{$domain}.pid";


            // if ($interface === "All") {
            //     $cmd = "nohup tshark -i any -f 'udp port 5060 or portrange 10000-20000' -w $filePath > /dev/null 2>&1 & echo $!";
            // } else {
            //     $cmd = "nohup tshark -i $interface -f 'udp port 5060 or portrange 10000-20000' -w $filePath > /dev/null 2>&1 & echo $!";
            // }

            if ($interface === "All") {
                $cmd = "nohup tshark -i any -a duration:{$duration} -w $filePath > /var/pcap/tshark.log 2>&1 & echo $!"; // EMAN ADDED JULY 13, 2026 DURATION PARAMETER
            } else {
                $cmd = "nohup tshark -i $interface -a duration:{$duration} -w $filePath > /var/pcap/tshark.log 2>&1 & echo $!";
            }

            file_put_contents('/var/pcap/last_capture_cmd.sh', $cmd);

            $pid = shell_exec($cmd);
            
            if ($pid) {
                file_put_contents($pidFile, trim($pid));
                return $this->response->setContent(json_encode([
                    "result" => "success",
                    "message" => "Capture started on {$interface}. PID: $pid",
                ]));
            } else {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Failed to start capture. (Code AI1005)",
                ]));
            }
        } else {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "POST method required. (Code AI1007)",
            ]));
        }
    }

    public function captureDebugAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]));
        }

        $domain = $session['subdomain'] ?? 'default';
        $key = "capture_debug:{$domain}";

        if ($this->request->isPost()) {
            $debug = $this->request->getPost("debug", "string", "0");
            $debug = ($debug === "1" || $debug === "true") ? "1" : "0";
            $this->redis->set($key, $debug);
            return $this->response->setContent(json_encode([
                "result" => "success",
                "debug" => $debug,
            ]));
        }

        $current = $this->redis->get($key);
        return $this->response->setContent(json_encode([
            "result" => "success",
            "debug" => $current === "1" ? "1" : "0",
        ]));
    }

    // MR - Stop the Network Capture
    public function stopCaptureAction() {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        $session = $this->session->get("auth");
        if (!$session) {
            return $this->response->setContent(json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]));
        }

        $domain = $session['subdomain'] ?? 'default';
        $pidFile = "/var/pcap/capture-{$domain}.pid";
        $filePath = "/var/pcap/capture-{$domain}.pcap";

        if (file_exists($pidFile)) {
            $pid = trim(file_get_contents($pidFile));
            shell_exec("kill $pid");
            unlink($pidFile);

            return $this->response->setContent(json_encode([
                "result" => "success",
                "message" => "Capture stopped successfully.",
                "download" => "/reports/downloadCapture/{$domain}"
            ]));
        }

        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "No running capture found.",
        ]));
    }

    // MR - Auto Download the Captured pcap during network capture
    public function downloadCaptureAction($domain)
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $filePath = "/var/pcap/capture-{$domain}.pcap";
        if (file_exists($filePath)) {
            $this->response->setHeader('Content-Type', 'application/octet-stream');
            $this->response->setHeader('Content-Disposition', "attachment; filename=\"{$domain}_capture.pcap\"");
            $this->response->setContent(file_get_contents($filePath));
            return $this->response;
        }

        $this->response->setStatusCode(404, "Not Found");
        $this->response->setContent("File not found");
        return $this->response;
    }
     // MR - Start the Network Capture start - 12/05/2026


    // MR - Start the Call Flow start - 13/05/2026
    public function showsingleCaptureAction($sipcallid = null) 
    {
        // $sipcallid  = '9bd278b5-e160-123e-2896-00163ebe72d1';
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        } 
        if (!$sipcallid) {
            return $this->response->setStatusCode(400)->setContent("Missing 'id'.")->send();
        }
        if ($this->request->isGet()) {
            $cdr = QRingqCdr::findFirst([
                "conditions" => "sip_call_id = :sip_call_id:",
                "bind" => [
                    "sip_call_id" => $sipcallid,
                ],
            ]);

            if (!$cdr) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "CDR not found for SIP Call ID: $sipcallid",
                ]));
            }
             
            $start_stamp = $cdr->start_stamp ?? null;
            $caller_id = $cdr->caller_id_name ?? null;
            $rdnis = $cdr->caller_id_number;
            $rdnis2 = $cdr->caller_destination;

            // $domain = $caller_profile["domain_name"] ?? $cdrData["domain_name"] ?? null;
            if (!$start_stamp || !$caller_id || !$rdnis) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Missing data in CDR to build filename.",
                ]));
            }
            $start_stamp = $cdr->start_stamp ?? null;
            $caller_id = $cdr->caller_id_name ?? null;
            $rdnis = $cdr->caller_id_number;
            $rdnis2 = $cdr->caller_destination;
            // $domain = $caller_profile["domain_name"] ?? $cdrData["domain_name"] ?? null;
            if (!$start_stamp || !$caller_id || !$rdnis) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Missing data in CDR to build filename.",
                ]));
            }
            $filename = "{$sipcallid}.pcap";
            $filePath = "/var/call-pcaps/" . $filename;


            // $filePath = "/var/call-pcaps/K6COmMPKBKab04e49f-32e4-47f1-b74a-a4e8e01c9a8a@115.42.196.42.pcap"
            $command = "sudo /usr/bin/tshark -r " . escapeshellarg($filePath) . " -Y sip -T json";
            $json = shell_exec($command);
            $json = json_decode($json, true);
            if (!$json) {
                die("❌ Failed to read or decode sip_full.json\n");
            }
            $sipData = [];
            foreach ($json as $packet) {

                $layers = $packet['_source']['layers'];

                $frame_time_main = $layers['frame']['frame.time'] ?? null;
                $frame_time      = $layers['frame']['frame.time_relative'] ?? null;

                /*
                |--------------------------------------------------------------------------
                | IP Address
                |--------------------------------------------------------------------------
                */
                $ip_src = $layers['ip']['ip.src'] ?? null;
                $ip_dst = $layers['ip']['ip.dst'] ?? null;

                $ip_src = is_array($ip_src) ? $ip_src[0] : $ip_src;
                $ip_dst = is_array($ip_dst) ? $ip_dst[0] : $ip_dst;

                /*
                |--------------------------------------------------------------------------
                | Support BOTH UDP + TCP SIP
                |--------------------------------------------------------------------------
                */
                $src_port =
                    $layers['udp']['udp.srcport']
                    ?? $layers['tcp']['tcp.srcport']
                    ?? null;

                $dst_port =
                    $layers['udp']['udp.dstport']
                    ?? $layers['tcp']['tcp.dstport']
                    ?? null;

                $src_port = is_array($src_port) ? $src_port[0] : $src_port;
                $dst_port = is_array($dst_port) ? $dst_port[0] : $dst_port;

                /*
                |--------------------------------------------------------------------------
                | SIP RAW
                |--------------------------------------------------------------------------
                */
                $sip_raw = $layers['text'] ?? $layers['sip'] ?? null;

                if (empty($sip_raw)) {
                    continue;
                }

                $serverIp = $_SERVER['SERVER_ADDR'];

                /*
                |--------------------------------------------------------------------------
                | Convert SIP Text
                |--------------------------------------------------------------------------
                */
                $sip_text = is_array($sip_raw)
                    ? implode("\n", array_map(
                        fn($v) => is_scalar($v) ? $v : json_encode($v),
                        $sip_raw
                    ))
                    : (string)$sip_raw;

                /*
                |--------------------------------------------------------------------------
                | Extract From Number
                |--------------------------------------------------------------------------
                */
                /*
                |--------------------------------------------------------------------------
                | Extract DID / Number
                |--------------------------------------------------------------------------
                */
                $frm_num = null;

                /*
                |--------------------------------------------------------------------------
                | Priority:
                | 1. INVITE sip:number@
                | 2. To:
                | 3. P-Called-Party-ID
                | 4. From:
                |--------------------------------------------------------------------------
                */
                $patterns = [

                    // INVITE sip:919999999999@
                    '/INVITE\s+sip:([0-9]+)@/i',

                    // To: <sip:919999999999@>
                    '/To:\s.*?sip:([0-9]+)@/i',

                    // P-Called-Party-ID
                    '/P-Called-Party-ID:\s.*?sip:([0-9]+)@/i',

                    // From fallback
                    '/From:\s.*?sip:([0-9]+)@/i',
                ];

                foreach ($patterns as $pattern) {

                    if (preg_match($pattern, $sip_text, $m)) {

                        $candidate = trim($m[1]);

                        if (!empty($candidate)) {
                            $frm_num = $candidate;
                            break;
                        }
                    }
                }

                    /*
                |--------------------------------------------------------------------------
                | Dynamic Trunk Mapping Cache
                |--------------------------------------------------------------------------
                */
                static $trunkIpMap = [];

                /*
                |--------------------------------------------------------------------------
                | Find Trunk Name
                |--------------------------------------------------------------------------
                */
                $trunknme = null;

                if (!empty($frm_num)) {

                    $qDid = QDids::findFirst([
                        "conditions" => "domain_uuid = :domain_uuid: AND did_number LIKE :did_number:",
                        "bind" => [
                            "domain_uuid" => $session["domain_uuid"],
                            "did_number"  => "%" . $frm_num . "%",
                        ],
                    ]);

                    $trunknme = $qDid ? $qDid->sip_trunk_name : null;
                }

                /*
                |--------------------------------------------------------------------------
                | Learn Which IP Belongs To Trunk
                |--------------------------------------------------------------------------
                */
                if (!empty($trunknme)) {

                    // external side source
                    if ($ip_src !== $serverIp) {
                        $trunkIpMap[$ip_src] = $trunknme;
                    }

                    // external side destination
                    if ($ip_dst !== $serverIp) {
                        $trunkIpMap[$ip_dst] = $trunknme;
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Display Names
                |--------------------------------------------------------------------------
                */
                $src_display = $ip_src;
                $dst_display = $ip_dst;

                /*
                |--------------------------------------------------------------------------
                | Replace Server Name
                |--------------------------------------------------------------------------
                */
                if ($ip_src === $serverIp) {
                    $src_display = 'RingQ Server';
                }

                if ($ip_dst === $serverIp) {
                    $dst_display = 'RingQ Server';
                }

                /*
                |--------------------------------------------------------------------------
                | Replace Learned Trunk IPs
                |--------------------------------------------------------------------------
                */
                if (isset($trunkIpMap[$ip_src])) {
                    $src_display = $trunkIpMap[$ip_src];
                }

                if (isset($trunkIpMap[$ip_dst])) {
                    $dst_display = $trunkIpMap[$ip_dst];
                }

                /*
                |--------------------------------------------------------------------------
                | Final Endpoint
                |--------------------------------------------------------------------------
                */
                $src = trim($src_display);
                $dst = trim($dst_display);

                if (!empty($src_port)) {
                    $src .= ':' . $src_port;
                }

                if (!empty($dst_port)) {
                    $dst .= ':' . $dst_port;
                }

                /*
                |--------------------------------------------------------------------------
                | RingQ Server
                |--------------------------------------------------------------------------
                */
                if ($ip_src === $serverIp) {
                    $src_display = 'RingQ Server';
                }

                if ($ip_dst === $serverIp) {
                    $dst_display = 'RingQ Server';
                }

                /*
                |--------------------------------------------------------------------------
                | Replace Non-Server Side With Trunk Name
                |--------------------------------------------------------------------------
                */
                if ($ip_src !== $serverIp && !empty($trunknme)) {
                    $src_display = $trunknme;
                }

                if ($ip_dst !== $serverIp && !empty($trunknme)) {
                    $dst_display = $trunknme;
                }

                /*
                |--------------------------------------------------------------------------
                | Safety Fallback
                |--------------------------------------------------------------------------
                */
                $src_display = trim((string)$src_display);
                $dst_display = trim((string)$dst_display);

                if ($src_display === '') {
                    $src_display = $ip_src ?: 'Unknown';
                }

                if ($dst_display === '') {
                    $dst_display = $ip_dst ?: 'Unknown';
                }

                /*
                |--------------------------------------------------------------------------
                | Final Endpoint
                |--------------------------------------------------------------------------
                */
                $src = $src_display;
                $dst = $dst_display;

                if (!empty($src_port)) {
                    $src .= ':' . $src_port;
                }

                if (!empty($dst_port)) {
                    $dst .= ':' . $dst_port;
                }

                /*
                |--------------------------------------------------------------------------
                | SIP Message
                |--------------------------------------------------------------------------
                */
                $sipMessage = is_array($sip_raw)
                    ? implode("\n", array_filter($sip_raw, fn($v) => is_scalar($v)))
                    : (string)$sip_raw;

                /*
                |--------------------------------------------------------------------------
                | Store Packet
                |--------------------------------------------------------------------------
                */
                $sipData[] = [
                    'time'      => $frame_time,
                    'main_time' => $frame_time_main,
                    'src'       => $src,
                    'dst'       => $dst,
                    'sip'       => $sipMessage,
                ];
            }



            /*
            |--------------------------------------------------------------------------
            | Normalize Endpoint Names
            |--------------------------------------------------------------------------
            */

            $ipMap = [];

            foreach ($sipData as $row) {

                if (strpos($row['src'], 'RingQ Server') === false &&
                    preg_match('/^([\d\.]+):(\d+)$/', $row['src'], $m)) {

                    $ipMap[$m[1]] = $row['src'];
                }

                if (strpos($row['dst'], 'RingQ Server') === false &&
                    preg_match('/^([\d\.]+):(\d+)$/', $row['dst'], $m)) {

                    $ipMap[$m[1]] = $row['dst'];
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Replace Raw IP With Trunk Label Everywhere
            |--------------------------------------------------------------------------
            */

            foreach ($sipData as &$row) {

                foreach ($ipMap as $ip => $label) {

                    if (!empty($trunknme)) {

                        $newLabel = str_replace($ip, $trunknme, $label);

                        $row['src'] = str_replace($label, $newLabel, $row['src']);
                        $row['dst'] = str_replace($label, $newLabel, $row['dst']);
                    }
                }
            }

            unset($row);

            if (!empty($sipData)) {
                $encodedFlow = json_encode(
                    $sipData,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

                // Write to log file
                error_log($encodedFlow . "\n", 3, '/var/www/ringq/app/controllers/logs/fb_logs.log');
            }
            // $finalFlow = json_encode($sipData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $encodedFlow = json_encode($sipData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <title>SIP Call Flow Viewer</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f4f4f4; }
                .diagram-container { display: flex; flex-direction: row; background: #fff; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); overflow: hidden; }
                .ladder { flex: 2; padding: 20px; border-right: 1px solid #ccc; }
                .details { flex: 3; padding: 20px; }
                .ip-header { display: flex; justify-content: space-between; font-weight: bold; padding-bottom: 20px; }
                .ip-header div { flex: 1; text-align: center; border: 1px solid; padding: 15px; margin: 0px 45px }
                .arrow-row { position: relative; display: flex; align-items: center; height: 30px; margin: 25px 0; }
                .arrow-line { height: 2px; background: #333; flex: 1; position: relative; }
                .arrow-label { position: absolute; left: 50%; transform: translateX(-50%); background: #e8e8e8; border: 1px solid #aaa; border-radius: 5px; padding: 2px 8px; font-size: 12px; cursor: pointer; z-index: 10; white-space: nowrap; }
                .arrow-label:hover { background: #d0d0d0; }
                .sip-message { white-space: pre-wrap; font-family: monospace; background: #000;color:#fff; border: 1px solid #ccc; padding: 10px; height: 600px; overflow-y: auto; border-radius: 5px; }
                @media (max-width: 768px) {
                .diagram-container { flex-direction: column; }
                .ladder, .details { border: none; padding: 10px; }
                }
                .arrow-label.invite { background-color: #d4f8d4; border-color: #4caf50; }
                .arrow-label.bye { background-color: #f8d4d4; border-color: #f44336; }
                .arrow-label.ack { background-color: #d4e4f8; border-color: #2196f3; }
                .arrow-label.cancel { background-color: #fff3cd; border-color: #ffc107; }
                .arrow-label.options { background-color: #e0e0e0; border-color: #888; }
                .arrow-label.response { background-color: #e3d7f4; border-color: #9c27b0; }
                .arrow-label:hover { background-color: #ddd; }
                .arrow-line {flex: 1; height: 5px; background-color: #ccc; position: relative; }
                .arrow-line.arrow-left {background-color: #ff0000;}
                .arrow-line.arrow-right { background-color: #59df59;}
                .arrow-right::after {content: "→"; position: absolute; right: 0; font-size: 40px; top: -25px;color: #59df59; }
                .arrow-left::before { content: "←";   position: absolute; left: 0;  font-size: 40px;  top: -25px;   color: #ff0000; }
                div#flowLines { border-left: 2px solid;  border-right: 2px solid; width: 365px; margin: auto;  height: 500px }
                .main_title{  border-radius: 5px;  background: #00a9f9; padding: 10px;  color: #ffff; margin: 0px; }
                .arrow-time {  font-size: 10px;  color: #444;  text-align: center; margin-top: 15px; }
                .arrow-time::before { content: "Time: ";  font-weight: bold; }
                .arrow-time span {  font-weight: normal; color: #007eff; }
            </style>
            </head>
            <body>
            <h2 class="main_title">SIP Call Flow Viewer - Call Flow For $sipcallid</h2>
            <div class="diagram-container">
            <div class="ladder">
                <div class="ip-header" id="ipHeader"></div>
                <div id="flowLines"></div>
            </div>
            <div class="details">
                <!-- <h3>Full SIP Message</h3> -->
                <div class="sip-message" id="sipDetail">Click a message to view details here.</div>
            </div>
            </div>
            <script>
            const messages = $encodedFlow;
            const ipSet = new Set();
            messages.forEach(msg => {
                if (msg.src) ipSet.add(msg.src);
                if (msg.dst) ipSet.add(msg.dst);
            });
            
            const ips = Array.from(ipSet);
            console.log("Unique IPs:", ips);
            const ipHeader = document.getElementById("ipHeader");
            ips.forEach(ip => {
                const div = document.createElement("div");
                div.textContent = ip;
                ipHeader.appendChild(div);
            });
            const flowLines = document.getElementById("flowLines");
            messages.forEach(msg => {
                if (!msg.src || !msg.dst || !msg.sip) return;
                const fromIndex = ips.indexOf(msg.src);
                const toIndex = ips.indexOf(msg.dst);
                const timeFrame= ips.indexOf(msg.time);
                if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) return;
                const row = document.createElement("div");
                row.className = "arrow-row";
                for (let i = 0; i < ips.length - 1; i++) {

                    // create line ONLY for active index
                    if (i === Math.min(fromIndex, toIndex)) {

                        const line = document.createElement("div");
                        line.classList.add("arrow-line");

                        if (fromIndex < toIndex) {
                            line.classList.add("arrow-right");
                        } else {
                            line.classList.add("arrow-left");
                        }

                        const timeDiv = document.createElement("div");
                        timeDiv.className = "arrow-time";

                        var fullTime = msg.main_time || "";
                        const match = fullTime.match(/\d{2}:\d{2}:\d{2}\.\d{6}/);

                        const displayTime = match ? match[0] : fullTime;

                        var timeParts = parseFloat(msg.time).toFixed(6) + "s";

                        const span = document.createElement("span");
                        span.textContent = " - +" + timeParts;

                        timeDiv.textContent = displayTime;
                        timeDiv.appendChild(span);

                        line.appendChild(timeDiv);

                        row.appendChild(line);
                    }
                }
                const firstLine = msg.sip.split('\\n')[0].trim().toUpperCase();
                let method;
                if (firstLine.startsWith('SIP/2.0')) {
                    method = firstLine.split(' ').slice(1).join(' ');  // e.g., "100 TRYING", "200 OK"
                } else {
                    method = firstLine.split(' ')[0];  // e.g., "INVITE", "ACK", "BYE"
                }
                const methodLine = method;
                const label = document.createElement("div");
                label.className = "arrow-label";
                method = methodLine.toUpperCase();
                if (method.startsWith("INVITE")) label.classList.add("invite");
                else if (method.startsWith("BYE")) label.classList.add("bye");
                else if (method.startsWith("ACK")) label.classList.add("ack");
                else if (method.startsWith("CANCEL")) label.classList.add("cancel");
                else if (method.startsWith("OPTIONS")) label.classList.add("options");
                else if (method.startsWith("200")) label.classList.add("response");
                else if (/^SIP\/2\.0/.test(method)) label.classList.add("response");
                label.textContent = methodLine;
                label.onclick = () => {
                // document.getElementById("sipDetail").textContent = msg.sip;
                document.getElementById("sipDetail").textContent = msg.sip;
                };
                const mid = (fromIndex + toIndex) / (ips.length - 1);
                
                row.appendChild(label);
                flowLines.appendChild(row);
            });
            </script>
            </body>
            </html>
            HTML;
            return $this->response
                ->setHeader('Content-Type', 'text/html')
                ->setContent($html)
                ->send();
        }
        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "Invalid request.",
        ]));
    }

    public function showsingleCapture2Action($id = "")
    {
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
        $sipcallid = $this->filter->sanitize($id, 'string');
        if (!$sipcallid) {
            return $this->response->setStatusCode(400)->setContent("Missing 'id'.")->send();
        }
        if ($this->request->isGet()) {
            $cdr = QRingqCdr::findFirst([
                "conditions" => "sip_call_id = :sip_call_id:",
                "bind" => [
                    "sip_call_id" => $sipcallid,
                ],
            ]);
            if (!$cdr) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "CDR not found for SIP Call ID: $sipcallid",
                ]));
            }
             $cdr = QRingqCdr::findFirst([
                "conditions" => "sip_call_id = :sip_call_id:",
                "bind" => [
                    "sip_call_id" => $sipcallid,
                ],
            ]);
            if (!$cdr) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "CDR not found for SIP Call ID: $sipcallid",
                ]));
            }
            $start_stamp = $cdr->start_stamp ?? null;
            $caller_id = $cdr->caller_id_name ?? null;
            $rdnis = $cdr->caller_id_number;
            $rdnis2 = $cdr->caller_destination;
            // $domain = $caller_profile["domain_name"] ?? $cdrData["domain_name"] ?? null;
            if (!$start_stamp || !$caller_id || !$rdnis) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Missing data in CDR to build filename.",
                ]));
            }
            $filename = "{$sipcallid}.pcap";
            $filePath = "/var/call-pcaps/" . $filename;
            $command = "sudo /usr/bin/tshark -r " . escapeshellarg($filePath) . " -Y sip -T json";
            // $pcapFile = $filename;
            // FIXED: Added space before -Y
            $command = "tshark -r " . escapeshellarg($filePath) . " -Y sip -T json";
            $json = shell_exec($command);
            $json = json_decode($json, true);
            if (!$json) {
                die("❌ Failed to read or decode sip_full.json\n");
            }
            $sipData = [];
            foreach ($json as $packet) {
                $layers = $packet['_source']['layers'];
                $frame_time_main = $layers['frame']['frame.time'] ?? null;
                $frame_time = $layers['frame']['frame.time_relative'] ?? null;
                $ip_src = $layers['ip']['ip.src'] ?? null;
                $ip_dst = $layers['ip']['ip.dst'] ?? null;
                $udp_srcport = $layers['udp']['udp.srcport'] ?? null;
                $udp_dstport = $layers['udp']['udp.dstport'] ?? null;
                // Fix: ensure values are scalar
                $ip_src = is_array($ip_src) ? $ip_src[0] : $ip_src;
                $ip_dst = is_array($ip_dst) ? $ip_dst[0] : $ip_dst;
                $udp_srcport = is_array($udp_srcport) ? $udp_srcport[0] : $udp_srcport;
                $udp_dstport = is_array($udp_dstport) ? $udp_dstport[0] : $udp_dstport;
                $sip_raw = $layers['text'] ?? $layers['sip'] ?? null;
                if ($sip_raw) {
                    $sipMessage = is_array($sip_raw)
                ? implode("\n", array_filter($sip_raw, fn($v) => is_scalar($v)))
                : (string) $sip_raw;
                    $sipData[] = [
                        'time' => $frame_time,
                        'main_time' => $frame_time_main,
                        'src' => "$ip_src:$udp_srcport",
                        'dst' => "$ip_dst:$udp_dstport",
                        'sip' => $sipMessage,
                    ];
                }
            }
            // $finalFlow = json_encode($sipData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        //   $encodedFlow = json_encode($sipData, flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); 
         $encodedFlow = json_encode($sipData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <title>SIP Call Flow Viewer</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px; }
            .container {  max-width: 960px; margin: auto; background: #fff;  border-radius: 8px;  padding: 20px;   box-shadow: 0 0 10px rgba(0,0,0,0.1);  margin-bottom: 10px;  }
            .head { padding: 0px; }
            .controls { text-align: center; margin-bottom: 20px; }
            .controls button { margin: 0 10px;  padding: 10px 20px; font-size: 16px; cursor: pointer;  background-color: #007bff; color: white;  border: none;  border-radius: 4px; }
            .controls button:hover { background-color: #0056b3; }
            .info {  margin-bottom: 10px;  font-weight: bold;  color: #333; }
            .sip-message { white-space: pre-wrap;  background: #000000;   border: 1px solid #ccc;  padding: 15px;  border-radius: 5px;  font-family: monospace;  font-size: 14px;   overflow-x: auto;  color: #fff;  }
            .sip-highlight span { font-weight: bold; color: white;  padding: 2px 6px;   border-radius: 4px;  }
            .sip-header {   font-weight: bold; }
            .sip-full {   font-family: monospace;  white-space: pre-wrap;  }
            .INVITE { background-color: #28a745; }
            .ACK { background-color: #17a2b8; }
            .BYE { background-color: #dc3545; }
            .TRYING { background-color: #ffc107; color: black; }
            .OK { background-color: #007bff; }
            .CANCEL { background-color: #6c757d; }
            .sip-header span {  border: 1px solid #aaa;  border-radius: 5px;   padding: 5px 10px;  font-size: 12px;  cursor: pointer;  white-space: nowrap;  }
            .main_title { border-radius: 5px;   background: #00a9f9;   padding: 10px;  color: #fff;   margin: 0px;  }
        </style>
        </head>
        <body>
            <div>
                <div class="container head"> 
                    <h2 class="main_title">SIP Call Flow Viewer - Call Flow For {$sipcallid}</h2>
                </div>
            </div>
            <div class="container">
                <div class="controls">
                    <button onclick="prevMessage()">Previous</button>
                    <span id="message-counter">Message 1 of N</span>
                    <button onclick="nextMessage()">Next</button>
                </div>
                <div class="info" id="message-info"></div>
                <div class="sip-message" id="sip-content">Loading...</div>
            </div>
        <script>
        const sipData = {$encodedFlow};
        let currentIndex = 0;
        function highlightSIP(raw) {
            const lines = raw.split(/\\r?\\n/);
            const firstLine = lines[0];
            let className = '';
            if (firstLine.includes('INVITE')) className = 'INVITE';
            else if (firstLine.includes('ACK')) className = 'ACK';
            else if (firstLine.includes('BYE')) className = 'BYE';
            else if (firstLine.includes('100 Trying')) className = 'TRYING';
            else if (firstLine.includes('200 OK')) className = 'OK';
            else if (firstLine.includes('CANCEL')) className = 'CANCEL';
            let method;
            if (firstLine.startsWith('SIP/2.0')) {
                method = firstLine.split(' ').slice(1).join(' ');
            } else {
                method = firstLine.split(' ')[0];
            }
            const colored = '<span class="' + className + '">' + method + '</span>';
            const fullMessage = escapeHTML(raw).replace(/\\r?\\n/g, "<br>");
            return {
                header: colored,
                full: fullMessage
            };
        }
        function escapeHTML(str) {
            return str
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;");
        }
        function showMessage(index) {
            if (index < 0 || index >= sipData.length) return;
            const msg = sipData[index];
            var fullTime = msg.main_time || "";
            const match = fullTime.match(/\\d{2}:\\d{2}:\\d{2}\\.\\d{6}/);
            const displayTime = match ? match[0] : fullTime;
            var timeParts = parseFloat(msg.time).toFixed(6) + "s";
            document.getElementById("message-counter").textContent = `Message \${index + 1} of \${sipData.length}`;
            document.getElementById("message-info").innerHTML = `
                <strong>Time:</strong> \${displayTime}
                <span style="color: blue;"> +\${timeParts}</span>
                &nbsp;|&nbsp; \${msg.src} ➝ \${msg.dst}
            `;
            const result = highlightSIP(msg.sip);
            document.getElementById("sip-content").innerHTML = `
                <div class="sip-header">\${result.header}</div>
                <div class="sip-full">\${result.full}</div>
            `;
        }
        function nextMessage() {
            if (currentIndex < sipData.length - 1) {
                currentIndex++;
                showMessage(currentIndex);
            }
        }
        function prevMessage() {
            if (currentIndex > 0) {
                currentIndex--;
                showMessage(currentIndex);
            }
        }
        // Initial render
        showMessage(currentIndex);
        </script>
        </body>
        </html>
        HTML;
            return $this->response
                ->setHeader('Content-Type', 'text/html')
                ->setContent($html)
                ->send();

        }
        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "Invalid request.",
        ]));
    }

    public function showsingleCapture3Action($id = "")
    {
        // $sipcallid  = '9bd278b5-e160-123e-2896-00163ebe72d1';
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }
         $sipcallid = $this->filter->sanitize($id, 'string');
        if (!$sipcallid) {
            return $this->response->setStatusCode(400)->setContent("Missing 'id'.")->send();
        }
        if ($this->request->isGet()) {
            $cdr = QRingqCdr::findFirst([
                "conditions" => "sip_call_id = :sip_call_id:",
                "bind" => [
                    "sip_call_id" => $sipcallid,
                ],
            ]);
            if (!$cdr) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "CDR not found for SIP Call ID: $sipcallid",
                ]));
            }
            $start_stamp = $cdr->start_stamp ?? null;
            $caller_id = $cdr->caller_id_name ?? null;
            $rdnis = $cdr->caller_id_number;
            $rdnis2 = $cdr->caller_destination;
            // $domain = $caller_profile["domain_name"] ?? $cdrData["domain_name"] ?? null;
            if (!$start_stamp || !$caller_id || !$rdnis) {
                return $this->response->setContent(json_encode([
                    "result" => "error",
                    "message" => "Missing data in CDR to build filename.",
                ]));
            }
            $filename = "{$sipcallid}.pcap";
            $filePath = "/var/call-pcaps/" . $filename;
            $command = "sudo /usr/bin/tshark -r " . escapeshellarg($filePath) . " -Y sip -T json";
            // $pcapFile = $filename;
            // FIXED: Added space before -Y
            $command = "tshark -r " . escapeshellarg($filePath) . " -Y sip -T json";
            $json = shell_exec($command);
            $json = json_decode($json, true);
            if (!$json) {
                die("❌ Failed to read or decode sip_full.json\n");
            }
            $sipData = [];
            foreach ($json as $packet) {
                $layers = $packet['_source']['layers'];
                $frame_time_main = $layers['frame']['frame.time'] ?? null;
                $frame_time = $layers['frame']['frame.time_relative'] ?? null;
                $ip_src = $layers['ip']['ip.src'] ?? null;
                $ip_dst = $layers['ip']['ip.dst'] ?? null;
                $udp_srcport = $layers['udp']['udp.srcport'] ?? null;
                $udp_dstport = $layers['udp']['udp.dstport'] ?? null;
                // Fix: ensure values are scalar
                $ip_src = is_array($ip_src) ? $ip_src[0] : $ip_src;
                $ip_dst = is_array($ip_dst) ? $ip_dst[0] : $ip_dst;
                $udp_srcport = is_array($udp_srcport) ? $udp_srcport[0] : $udp_srcport;
                $udp_dstport = is_array($udp_dstport) ? $udp_dstport[0] : $udp_dstport;
                $sip_raw = $layers['text'] ?? $layers['sip'] ?? null;
                if ($sip_raw) {
                    $sipMessage = is_array($sip_raw)
                ? implode("\n", array_filter($sip_raw, fn($v) => is_scalar($v)))
                : (string) $sip_raw;
                    $sipData[] = [
                        'time' => $frame_time,
                        'main_time' => $frame_time_main,
                        'src' => "$ip_src:$udp_srcport",
                        'dst' => "$ip_dst:$udp_dstport",
                        'sip' => $sipMessage,
                    ];
                }
            }
            // $finalFlow = json_encode($sipData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $encodedFlow = json_encode($sipData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
            $html = <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <title>SIP Call Flow Viewer</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f4f4f4; }
                .diagram-container { display: flex; flex-direction: row; background: #fff; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); overflow: hidden; }
                .ladder { flex: 2; padding: 20px; border-right: 1px solid #ccc; }
                .details { flex: 3; padding: 20px; }
                .ip-header { display: flex; justify-content: space-between; font-weight: bold; padding-bottom: 20px; }
                .ip-header div { flex: 1; text-align: center; border: 1px solid; padding: 15px; margin: 0px 45px }
                .arrow-row { position: relative; display: flex; align-items: center; height: 30px; margin: 25px 0; }
                .arrow-line { height: 2px; background: #333; flex: 1; position: relative; }
                .arrow-label { position: absolute; left: 50%; transform: translateX(-50%); background: #e8e8e8; border: 1px solid #aaa; border-radius: 5px; padding: 2px 8px; font-size: 12px; cursor: pointer; z-index: 10; white-space: nowrap; }
                .arrow-label:hover { background: #d0d0d0; }
                .sip-message { white-space: pre-wrap; font-family: monospace; background: #000;color:#fff; border: 1px solid #ccc; padding: 10px; height: 600px; overflow-y: auto; border-radius: 5px; }
                @media (max-width: 768px) {
                .diagram-container { flex-direction: column; }
                .ladder, .details { border: none; padding: 10px; } }
                .arrow-label.invite { background-color: #d4f8d4; border-color: #4caf50; }
                .arrow-label.bye { background-color: #f8d4d4; border-color: #f44336; }
                .arrow-label.ack { background-color: #d4e4f8; border-color: #2196f3; }
                .arrow-label.cancel { background-color: #fff3cd; border-color: #ffc107; }
                .arrow-label.options { background-color: #e0e0e0; border-color: #888; }
                .arrow-label.response { background-color: #e3d7f4; border-color: #9c27b0; }
                .arrow-label:hover { background-color: #ddd; }
                .controls button {  margin: 0 10px; padding: 10px 20px; font-size: 16px; cursor: pointer;   background-color: #007bff;  color: white;  border: none;  border-radius: 4px; }
                .controls button:hover { background-color: #0056b3;  }
                .arrow-line { flex: 1; height: 2px; background-color: #ccc; position: relative; }
                .arrow-line.arrow-left { background-color: #ff0000;  }
                .arrow-line.arrow-right { background-color: #005a00; }
                .arrow-right::after {content: "→";  position: absolute;  right: 0;font-size: 40px; top: -25px;color: #005a00;}
                .arrow-left::before {  content: "←"; position: absolute; left: 0; font-size: 40px;   top: -25px; color: #ff0000; }
                div#flowLines {  border-left: 2px solid; border-right: 2px solid;   width: 365px;  margin: auto;  height: 500px  }
                .main_title{ border-radius: 5px; background: #00a9f9;  padding: 10px; color: #ffff;  margin: 0px; }
                .arrow-time { font-size: 10px; color: #444;  text-align: center;  margin-top: 15px;  }
                .arrow-time::before { content: "Time: "; font-weight: bold;  }
                .arrow-time span { font-weight: normal;  color: #007eff; }
            </style>
            </head>
            <body>
            <h2 class="main_title">SIP Call Flow Viewer - Call Flow For {{SIPCALLID}}</h2>
            <div class="diagram-container">
            <div class="ladder">
                <div style="text-align:center; margin-bottom: 10px;" class="controls">
                    <button onclick="showPrevious()" title="Previous">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <button onclick="showNext()" title="Next">
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
                <div class="ip-header" id="ipHeader"></div>
                
                <div id="flowLines"></div>
            </div>
            <div class="details">
                <div class="sip-message" id="sipDetail">Click a message to view details here.</div>
            </div>
            </div>
            <script>
            const messages = {{ENCODEDFLOW}};
            const ipSet = new Set();
            messages.forEach(msg => {
                if (msg.src) ipSet.add(msg.src);
                if (msg.dst) ipSet.add(msg.dst);
            });
            const ips = Array.from(ipSet);
            const ipHeader = document.getElementById("ipHeader");
            ips.forEach(ip => {
                const div = document.createElement("div");
                div.textContent = ip;
                ipHeader.appendChild(div);
            });
            const flowLines = document.getElementById("flowLines");
            let currentIndex = 0;
            const renderedRows = [];
            function renderMessage(msg) {
                const fromIndex = ips.indexOf(msg.src);
                const toIndex = ips.indexOf(msg.dst);
                if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) return;
                const row = document.createElement("div");
                row.className = "arrow-row";
                for (let i = 0; i < ips.length - 1; i++) {
                    const line = document.createElement("div");
                    line.className = "arrow-line";
                    if (i === Math.min(fromIndex, toIndex)) {
                        if (fromIndex < toIndex) {
                            line.classList.add("arrow-right");
                        } else {
                            line.classList.add("arrow-left");
                        }
                        const timeDiv = document.createElement("div");
                        timeDiv.className = "arrow-time";
                        const fullTime = msg.main_time || "";
                        const match = fullTime.match(/\d{2}:\d{2}:\d{2}\.\d{6}/);
                        const displayTime = match ? match[0] : fullTime;
                        const timeParts = parseFloat(msg.time).toFixed(6) + "s";
                        const span = document.createElement("span");
                        span.textContent = " - +" + timeParts;
                        timeDiv.textContent = displayTime;
                        timeDiv.appendChild(span);
                        line.appendChild(timeDiv);
                    }
                    row.appendChild(line);
                }
                const firstLine = msg.sip.split('\\n')[0].trim().toUpperCase();
                let method;
                if (firstLine.startsWith('SIP/2.0')) {
                    method = firstLine.split(' ').slice(1).join(' ');
                } else {
                    method = firstLine.split(' ')[0];
                }
                const methodLine = method;
                const label = document.createElement("div");
                label.className = "arrow-label";
                method = methodLine.toUpperCase();
                if (method.startsWith("INVITE")) label.classList.add("invite");
                else if (method.startsWith("BYE")) label.classList.add("bye");
                else if (method.startsWith("ACK")) label.classList.add("ack");
                else if (method.startsWith("CANCEL")) label.classList.add("cancel");
                else if (method.startsWith("OPTIONS")) label.classList.add("options");
                else if (/^SIP\/2\.0/.test(method)) label.classList.add("response");
                label.textContent = methodLine;
                label.onclick = () => {
                    document.getElementById("sipDetail").textContent = msg.sip;
                };
                const mid = (fromIndex + toIndex) / (ips.length - 1);
                label.style.left = `${mid * 100}%`;
                row.appendChild(label);
                flowLines.appendChild(row);
                renderedRows.push(row);
                // Show SIP message
                document.getElementById("sipDetail").textContent = msg.sip;
            }
            function showNext() {
                if (currentIndex < messages.length) {
                    const msg = messages[currentIndex];
                    renderMessage(msg);
                    currentIndex++;
                }
            }
            function showPrevious() {
                if (currentIndex > 0) {
                    currentIndex--;
                    const row = renderedRows.pop();
                    if (row) flowLines.removeChild(row);
                    // Show the previous SIP message or placeholder
                    if (currentIndex > 0) {
                        document.getElementById("sipDetail").textContent = messages[currentIndex - 1].sip;
                    } else {
                        document.getElementById("sipDetail").textContent = "Click a message to view details here.";
                    }
                }
            }
            showNext();
            </script>
            </body>
            </html>
            HTML;
            // Replace placeholders with actual PHP variables
            $html = str_replace(
                ['{{SIPCALLID}}', '{{ENCODEDFLOW}}'],
                [htmlspecialchars($sipcallid, ENT_QUOTES, 'UTF-8'), $encodedFlow],
                $html
            );
            return $this->response
                ->setHeader('Content-Type', 'text/html')
                ->setContent($html)
                ->send();
        }
        return $this->response->setContent(json_encode([
            "result" => "error",
            "message" => "Invalid request.",
        ]));
    }

    // MR - Start the Call Flow end - 13/05/2026
    private function normalizeScheduleReportEmails($value)
    {
        $emails = [];
        $values = is_array($value) ? $value : [$value];
        foreach ($values as $rawValue) {
            $parts = preg_split('/[;,:\s]+/', (string) $rawValue);
            foreach ($parts as $part) {
                $email = strtolower(trim((string) $part));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $emails, true)) {
                    $emails[] = $email;
                }
            }
        }

        return $emails;
    }

    private function normalizeScheduleReportRecipients($value)
    {
        $recipients = [];
        if (!is_array($value)) {
            return $recipients;
        }

        foreach ($value as $reportType => $emails) {
            $normalizedEmails = $this->normalizeScheduleReportEmails($emails);
            if (!empty($normalizedEmails)) {
                $recipients[$reportType] = $normalizedEmails;
            }
        }

        return $recipients;
    }

    private function getScheduleReportHistory($scheduleUuid, $limit = 20)
    {
        $history = [];
        $items = $this->redis->lRange("scheduled_report_history:" . $scheduleUuid, 0, max(0, $limit - 1));
        foreach ($items as $item) {
            $decoded = json_decode($item, true);
            if (is_array($decoded)) {
                $history[] = $decoded;
            }
        }

        return $history;
    }

    /**
     * Normalise a timezone value for storage.
     *
     * A blank value is the "Server Time" sentinel the client sends, and it is stored as-is: it
     * means "follow the server/tenant zone", resolved on every read. Storing a concrete zone here
     * instead would pin the schedule to whatever the server zone happened to be at save time, and
     * would also break the client dropdown, which carries a short fixed list and silently displays
     * anything outside it as "Server Time".
     */
    private function normalizeScheduleReportTimezoneValue($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        if ($value === '' || strcasecmp($value, 'Server Time') === 0) {
            return '';
        }

        $canonical = $this->canonicalScheduleReportTimezone($value);

        return $canonical === null ? '' : $canonical;
    }

    /**
     * The canonical zone name for $value, or null when DateTimeZone will not accept it.
     *
     * The parenthetical suffix is stripped explicitly rather than left to the engine: this app
     * stores `timedatectl` output elsewhere as "Asia/Shanghai (CST, +0800)", and PHP 7.4 happens
     * to parse that while PHP 8 rejects it outright -- so depending on the engine's leniency would
     * make a scheduled report start failing on a PHP upgrade, with no change to this code.
     *
     * Canonical rather than verbatim so the resolved name is what the panel prints.
     */
    private function canonicalScheduleReportTimezone($value)
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $paren = strpos($value, ' (');
        if ($paren !== false) {
            $value = rtrim(substr($value, 0, $paren));
        }

        if ($value === '') {
            return null;
        }

        try {
            $zone = new \DateTimeZone($value);

            return $zone->getName();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Resolve a stored timezone to a name DateTimeZone will accept.
     *
     * Never returns an unusable value: the blank "Server Time" sentinel, or a value DateTimeZone
     * rejects, falls through to the tenant setting, then the server default, then UTC. Both callers
     * previously used `$config['timezone'] ?? $default`, and `??` does not fire on an empty string,
     * so the blank sentinel reached DateTimeZone and threw.
     *
     * MainTask::resolveScheduleReportTimezone() must stay in step with this: if the two disagree,
     * the "Next Run" the panel shows is not the time the cron actually runs.
     */
    private function resolveScheduleReportTimezone($value, $domainUuid = null)
    {
        $canonical = $this->canonicalScheduleReportTimezone($value);
        if ($canonical !== null) {
            return $canonical;
        }

        // Only reached for a blank/unusable value, so the tenant lookup costs nothing in the
        // common case where the schedule carries an explicit zone.
        $canonical = $this->canonicalScheduleReportTimezone($this->scheduleReportDomainTimezone($domainUuid));
        if ($canonical !== null) {
            return $canonical;
        }

        $canonical = $this->canonicalScheduleReportTimezone(date_default_timezone_get());

        return $canonical === null ? 'UTC' : $canonical;
    }

    private function scheduleReportDomainTimezone($domainUuid)
    {
        if (empty($domainUuid)) {
            return '';
        }

        $vUserSetting = VUserSettings::findFirst([
            "conditions" => "domain_uuid = :domain_uuid: AND user_setting_subcategory = :user_setting_subcategory:",
            "bind" => [
                "domain_uuid" => $domainUuid,
                "user_setting_subcategory" => "time_zone",
            ],
            "bindTypes" => [Column::BIND_PARAM_STR, Column::BIND_PARAM_STR],
        ]);

        return $vUserSetting ? (string) $vUserSetting->user_setting_value : '';
    }

    public function scheduleReportsAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");
        $session = $this->session->get("auth");

        if (!$session) {
            return $this->response->setContent(json_encode(["result" => "error", "message" => "Unauthorized"]));
        }

        // Handle POST: Save Schedule
        if ($this->request->isPost()) {
            $data = $this->request->getRawBody();
            $jsonData = json_decode($data, true);
            
            if (empty($jsonData['frequency']) || empty($jsonData['time'])) {
                 return $this->response->setContent(json_encode(["result" => "error", "message" => "Missing required fields"]));
            }

            $reports = isset($jsonData['reports']) && is_array($jsonData['reports']) ? $jsonData['reports'] : [];

            // Blank stays blank: it is the "Server Time" sentinel, resolved on every read by
            // resolveScheduleReportTimezone(). The tenant lookup that used to happen here moved
            // into that resolver so the stored value keeps following the server/tenant zone.
            $timezone = $this->normalizeScheduleReportTimezoneValue($jsonData['timezone'] ?? '');

            $security = new \Phalcon\Security\Random();
            $uuid = $security->uuid();
            $existingConfig = [];

            $schedules = $this->redis->hGetAll("scheduled_reports");
            foreach ($schedules as $existingUuid => $configJson) {
                $comp = json_decode($configJson, true);
                if (isset($comp['user_uuid']) && $comp['user_uuid'] == $session['user_uuid']) {
                    $uuid = $existingUuid;
                    $existingConfig = is_array($comp) ? $comp : [];
                    break;
                }
            }

            $enabled = true;
            if (array_key_exists('enabled', $jsonData)) {
                $enabled = filter_var($jsonData['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $enabled = $enabled === null ? true : $enabled;
            } elseif (array_key_exists('enabled', $existingConfig)) {
                $enabled = filter_var($existingConfig['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $enabled = $enabled === null ? true : $enabled;
            }

            $recipientEmails = $this->normalizeScheduleReportEmails($jsonData['recipient_email'] ?? '');
            $reportRecipients = $this->normalizeScheduleReportRecipients($jsonData['report_recipients'] ?? []);

            $config = [
                'uuid' => $uuid,
                'user_email' => $session['email'],
                'user_uuid' => $session['user_uuid'],
                'domain_uuid' => $session['domain_uuid'],
                'extension' => $session['extension'],
                'subdomain' => $session['subdomain'],
                'timezone' => $timezone,
                'frequency' => $jsonData['frequency'], 
                'time' => $jsonData['time'],
                'day_of_week' => $jsonData['day_of_week'] ?? null,
                'day_of_month' => $jsonData['day_of_month'] ?? null,
                'recipient_email' => implode(', ', $recipientEmails),
                'report_recipients' => $reportRecipients,
                'enabled' => $enabled,
                'reports' => $reports,
                'filters' => $jsonData['filters'] ?? [],
                'last_run' => $existingConfig['last_run'] ?? 0,
                'created_at' => $existingConfig['created_at'] ?? time(),
                'updated_at' => time()
            ];

            $this->redis->hSet("scheduled_reports", $uuid, json_encode($config));

            return $this->response->setContent(json_encode(["result" => "success", "message" => "Report scheduled successfully", "uuid" => $uuid]));
        }

        if ($this->request->isGet()) {
            $user_uuid = $session['user_uuid'];
            $schedules = $this->redis->hGetAll("scheduled_reports");
            
            $serverTimezone = date_default_timezone_get();

            foreach ($schedules as $uuid => $configJson) {
                $config = json_decode($configJson, true);
                if (isset($config['user_uuid']) && $config['user_uuid'] == $user_uuid) {
                    if (!array_key_exists('enabled', $config)) {
                        $config['enabled'] = true;
                    }
                    if (!isset($config['report_recipients']) || !is_array($config['report_recipients'])) {
                        $config['report_recipients'] = [];
                    }

                    // --- Calculate Next Run ---
                    $frequency = $config['frequency'] ?? 'Daily';
                    $time = $config['time'] ?? '00:00'; // HH:mm
                    $timezone = $this->resolveScheduleReportTimezone(
                        $config['timezone'] ?? '',
                        $config['domain_uuid'] ?? ($session['domain_uuid'] ?? null)
                    );
                    
                    try {
                        $tz = new \DateTimeZone($timezone);
                        $now = new \DateTime("now", $tz);
                        $nextRun = new \DateTime("now", $tz);
                        
                        // Parse schedule time. A value without a colon would otherwise leave
                        // $minute undefined and silently schedule the run on the hour.
                        $timeParts = explode(':', (string) $time);
                        $hour = isset($timeParts[0]) ? (int) $timeParts[0] : 0;
                        $minute = isset($timeParts[1]) ? (int) $timeParts[1] : 0;
                        $nextRun->setTime($hour, $minute, 0);

                        if ($frequency == 'Daily') {
                            if ($nextRun <= $now) {
                                $nextRun->modify('+1 day');
                            }
                        } elseif ($frequency == 'Weekly') {
                            $targetDay = $config['day_of_week'] ?? 'Monday';
                            
                            $currentDayName = $now->format('l');
                            if ($currentDayName == $targetDay && $nextRun > $now) {
                            } else {
                                $nextRun->modify("next $targetDay");
                                $nextRun->setTime((int)$hour, (int)$minute, 0);
                            }
                        } elseif ($frequency == 'Monthly') {
                            $targetDay = $config['day_of_month'] ?? '1';
                            $currentDay = $now->format('j');
                            $nextRun->setDate((int)$now->format('Y'), (int)$now->format('m'), (int)$targetDay);
                             if ($nextRun <= $now) {
                                $nextRun->modify('+1 month');
                            }
                            // Re-set time
                            $nextRun->setTime((int)$hour, (int)$minute, 0);
                        }

                        $config['next_run_formatted'] = $nextRun->format('M j, Y g:i A') . " (" . $timezone . ")";
                    } catch (\Exception $e) {
                         $config['next_run_formatted'] = "Error calculating";
                    }

                    // --- Format Last Run ---
                    if (!empty($config['last_run'])) {
                         try {
                             $lastRunDate = new \DateTime("@" . $config['last_run']); // UTC timestamp
                             $lastRunDate->setTimezone(new \DateTimeZone($timezone)); // Convert to user timezone
                             $config['last_run_formatted'] = $lastRunDate->format('M j, Y g:i A');
                         } catch (\Exception $e) {
                             $config['last_run_formatted'] = date('M j, Y g:i A', $config['last_run']);
                         }
                    } else {
                        $config['last_run_formatted'] = "Never";
                    }

                    // Add owner email for frontend reference
                    $config['owner_email'] = $config['user_email'];
                    $config['history'] = $this->getScheduleReportHistory($uuid);

                    return $this->response->setContent(json_encode([
                        "result" => "success",
                        "data" => $config,
                        "server_timezone" => $serverTimezone
                    ]));
                }
            }
            
	            // Return empty config with owner email if no schedule exists
	            return $this->response->setContent(json_encode([
	                "result" => "success",
	                "data" => [
                        "owner_email" => $session['email'],
                        "enabled" => true,
                        "report_recipients" => [],
                        "history" => []
                    ],
	                "server_timezone" => $serverTimezone
	            ]));
        }

        return $this->response->setContent(json_encode(["result" => "error", "message" => "Method not supported"]));
    }





public function saveCustomWebhookDetailsAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {

        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        if (empty($session['subdomain'])) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "Subdomain not found"
            ]);
        }

        $rawBody = $this->request->getRawBody();
        $data = json_decode($rawBody, true);



        if (!is_array($data)) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "Invalid JSON payload"
            ]);
        }

        $redisKey = "custom_webhook:" . $session['subdomain'];

        $this->redis->set(
            $redisKey,
            json_encode($data, JSON_UNESCAPED_SLASHES)
        );

        return $this->response->setJsonContent([
            "result" => "success",
            "message" => "Webhook settings saved successfully"
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            "result" => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ]);
    }
}


public function getCustomWebhookDetailsAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
                        
    try {

        $session = $this->session->get("auth");
        if (!$session) {
            $response = json_encode([
                "result" => "error",
                "message" => "Unauthorized. (Code AI1004)",
            ]);
            return $this->response->setContent($response);
        }

        if (empty($session['subdomain'])) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "Subdomain not found"
            ]);
        }

        $redisKey = "custom_webhook:" . $session['subdomain'];

        $data = $this->redis->get($redisKey);

        if (!empty($data)) {

            return $this->response->setJsonContent([
                "result" => "success",
                "data" => json_decode($data, true)
            ]);
        }


    

        // Default configuration
        $defaultConfig = [
            "enabled" => false,
            "webhook_url" => "",
            "auth_type" => "None",
            "auth_value" => "",
            "timeout" => 30,
            "retry" => 3,
            "triggers" => [
                "call_completed" => true,
                "call_missed" => true,
                "ivr_completed" => true,
                "queue_abandoned" => true,
                "agent_connected" => true
            ],
            "send_transcript" => true,
            "send_recording_url" => true,
            "send_ivr_intent" => true,
            "send_call_flow" => true,
            "send_queue_details" => true,
            "send_agent_details" => true,
            "custom_headers" => []
        ];

        return $this->response->setJsonContent([
            "result" => "success",
            "data" => $defaultConfig
        ]);

    } catch (\Exception $e) {

        return $this->response->setJsonContent([
            "result" => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ]);
    }
}



public function writeLogAction()
{
    $this->view->disable();
    $this->response->setHeader('Content-Type', 'application/json');

    try {

       $logDir = '/var/www/custom_crm';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $logFile = $logDir . '/webhook_' . date('Y-m-d') . '.log';

        $requestInfo = [

            'timestamp' => date('Y-m-d H:i:s'),

            'method' => $this->request->getMethod(),

            'uri' => $_SERVER['REQUEST_URI'] ?? '',

            'remote_ip' => $_SERVER['REMOTE_ADDR'] ?? '',

            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',

            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',

            'query_params' => $_GET,

            'post_params' => $_POST,

            'headers' => function_exists('getallheaders')
                ? getallheaders()
                : [],

            'raw_body' => $this->request->getRawBody(),

            'json_body' => json_decode(
                $this->request->getRawBody(),
                true
            ),

            'server' => [
                'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '',
                'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? '',
                'HTTPS' => $_SERVER['HTTPS'] ?? '',
            ]
        ];

        file_put_contents(
            $logFile,
            json_encode(
                $requestInfo,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) .
            PHP_EOL .
            str_repeat('=', 150) .
            PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return $this->response->setJsonContent([
            'result' => 'success'
        ]);

    } catch (\Throwable $e) {

        file_put_contents(
            APP_PATH . '/logs/custom_webhook_error.log',
            date('Y-m-d H:i:s') .
            ' => ' .
            $e->getMessage() .
            PHP_EOL,
            FILE_APPEND
        );

        return $this->response->setJsonContent([
            'result' => 'error',
            'message' => ControllerBase::safeErrorMessage($e)
        ]);
    }
}






public function getCustomWebhookDetailssAction($phone)
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://adminportal.readylink.in/api/v1/get_user_by_phone/{$phone}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',

        CURLOPT_USERPWD => 'readylink:e14603557040dc85e44806abc0adcdfeb262f746',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    ]);

    $result = curl_exec($curl);

    if (curl_errno($curl)) {
        return $this->response->setJsonContent([
            'result' => 'error',
            'message' => curl_error($curl)
        ]);
    }

    curl_close($curl);

    $response = json_decode($result, true);

    if (!$response || !isset($response[0][0]['User'])) {
        return $this->response->setJsonContent([
            'result' => 'error',
            'message' => 'Invalid API response',
            'api_response' => $result
        ]);
    }

    $user = $response[0][0]['User'];

    $id = $user['id'];
    $accountId = $user['account_id'];

    $url = "https://adminportal.readylink.in/users/user_stats/{$id}/{$accountId}";

    return $this->response->setJsonContent([
        'result' => 'success',
        'id' => $id,
        'account_id' => $accountId,
        'url' => $url
    ]);
}

    // MR changes - starts
    /**
     * GET /reports/nodeivrmenus/{domain_name}
     *
     * Internal (X-Internal-Key) list of a tenant's IVR menus -- name and extension ("IVR number") -- for
     * node/custom_crm.js, so the Custom CRM webhook can tell which IVR a call went through and send its
     * number. Read-only; same key as nodeaudiototextAction.
     */
    public function nodeivrmenusAction($domain_name = '')
    {
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

        if ($this->request->getHeader("X-Internal-Key") !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        try {
            $domain_name = trim((string) $this->filter->sanitize($domain_name, "string"));
            if ($domain_name === '') {
                return $this->response->setJsonContent(["result" => "error", "message" => "Domain is required"]);
            }
            $vDomain = \VDomains::findFirst([
                "conditions" => "domain_name = :domain_name:",
                "bind" => ["domain_name" => $domain_name],
            ]);
            if (!$vDomain) {
                return $this->response->setJsonContent(["result" => "error", "message" => "Unknown domain"]);
            }
            $menus = [];
            $rows = \VIvrMenus::find([
                "conditions" => "domain_uuid = :domain_uuid:",
                "bind" => ["domain_uuid" => $vDomain->domain_uuid],
                "columns" => "ivr_menu_uuid, ivr_menu_name, ivr_menu_extension",
            ]);
            foreach ($rows as $row) {
                $menus[] = [
                    "uuid" => (string) $row->ivr_menu_uuid,
                    "name" => (string) $row->ivr_menu_name,
                    "extension" => (string) $row->ivr_menu_extension,
                ];
            }
            return $this->response->setJsonContent(["result" => "success", "menus" => $menus]);
        } catch (\Throwable $e) {
            error_log('[nodeivrmenus] ' . $e->getMessage());
            return $this->response->setJsonContent(["result" => "error", "message" => "Could not load IVR menus"]);
        }
    }
    // MR changes - ends
}