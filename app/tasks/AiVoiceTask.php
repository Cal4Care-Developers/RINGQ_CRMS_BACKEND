<?php

use Phalcon\Cli\Task;

/**
 * AI Voice (the AI agent on 5555).
 *
 *   php /var/www/ringq/app/cli.php aiVoice calls   # every minute from tasks.txt
 *
 * Kept out of MainTask so it can ship on servers whose MainTask is an encoded build.
 */
class AiVoiceTask extends Task
{
	// AI Voice in Call Logs and reports: a call to the AI agent (5555) that OpenAI refused still
	// ends with a normal hangup, so it looked answered. Once a minute, read each new AI call's
	// result from the AI module's log lines and set its status to "AI Failed" or "AI Answered".
	public function callsAction() {
		require_once dirname(__DIR__) . "/library/AiVoice.php";
		$lock = "ai:voice-calls-lock";
		if (!$this->redis->set($lock, 1, "EX", 300, "NX")) {
			return;
		}
		try {
			$rows = $this->db->fetchAll(
				"SELECT c.xml_cdr_uuid::text AS uuid, c.start_epoch, c.last_arg, c.caller_id_number AS caller, d.domain_name
				   FROM q_ringq_cdr c
				   JOIN v_domains d ON d.domain_uuid = c.domain_uuid
				  WHERE (c.destination_number = '5555' OR c.caller_destination = '5555')
				    AND c.end_epoch IS NOT NULL
				    AND c.end_epoch BETWEEN :from AND :to
				  ORDER BY c.start_epoch
				  LIMIT 500",
				\Phalcon\Db\Enum::FETCH_ASSOC,
				// Calls that ended 1-60 minutes ago: the module has finished logging them
				["from" => time() - 3600, "to" => time() - 60]
			);
			$byDomain = [];
			foreach ($rows as $row) {
				if ($this->redis->exists("ai:voice-done:" . $row["uuid"])) continue;
				$byDomain[(string) $row["domain_name"]][(string) $row["uuid"]] = $row;
			}
			foreach ($byDomain as $domain => $calls) {
				$starts = array_map(function ($r) { return (int) $r["start_epoch"]; }, $calls);
				$results = \AiVoice::callResults($this->redis, $domain, $starts);
				foreach ($calls as $uuid => $row) {
					$result = $results[$uuid]["result"] ?? "unknown";
					$status = $result === "failed" ? "AI Failed" : ($result === "connected" ? "AI Answered" : null);
					if ($status !== null && $row["last_arg"] !== $status) {
						$this->db->execute(
							"UPDATE q_ringq_cdr SET last_arg = :status WHERE xml_cdr_uuid = CAST(:uuid AS uuid)",
							["status" => $status, "uuid" => $uuid]
						);
					}
					if ($result === "failed") {
						\AiVoice::log($this->redis, $domain, "call", "AI call from " . ($row["caller"] ?? "") . " failed: " . ($results[$uuid]["reason"] ?? ""), ["uuid" => $uuid], (int) $row["start_epoch"]);
					}
					$this->redis->setex("ai:voice-done:" . $uuid, 2 * 86400, $result);
				}
			}
		} catch (\Throwable $e) {
			error_log("aiVoiceCalls: " . $e->getMessage());
		} finally {
			$this->redis->del($lock);
		}
	}
}
