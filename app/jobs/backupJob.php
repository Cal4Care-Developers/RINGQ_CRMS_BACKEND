<?php

// `use` is FILE-SCOPED: the imports in worker.php and MainTask.php do NOT reach a
// required file. Without these lines the code below resolves e.g. `Column` to the
// global `\Column`, which does not exist, and every tick dies with
// "Class 'Column' not found" -- php -l cannot see it and loading the file cannot
// either, because it only fails when the line actually runs.
use ElephantIO\Client;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Time Machine — the backup, restore, import and delete-queue job runner.
 *
 * Lifted out of MainTask.php unchanged. Every method body in this file is byte
 * identical to the one it replaced; the only edits are mechanical:
 *
 *   - `processJobQueues()` became public, because MainTask now calls it from here
 *     rather than owning it.
 *   - The two credential literals were replaced by constructor-injected values.
 *     See the note on credentials below.
 *
 * WHY A CLASS, AND WHY THIS BASE CLASS
 * The code reaches the DI container 75 times — `$this->redis` (61), `$this->config`
 * (12) and `$this->getDI()` (2) — and calls no MainTask method that stayed behind.
 * `Phalcon\Cli\Task` is itself a `Phalcon\Di\Injectable`, so extending Injectable
 * resolves all three through the identical mechanism and the bodies move verbatim.
 * A trait would work too, but a trait is a compile-time dependency: a missing file
 * would fatal the whole of `cli.php main` and take every cron task down with it,
 * not just backup. A class can be loaded defensively — see MainTask::backupJob().
 *
 * WHY THE CREDENTIALS ARE NOT IN THIS FILE
 * For cohesion, not secrecy. The reason given here originally — that `app/jobs/*.php`
 * ships plaintext while `MainTask.php` reaches customers ionCube-encoded — was wrong:
 * the fileserver payload is ionCube encoded as a whole before it is published, so
 * both files arrive encoded and neither location is in clear on a customer box. Both
 * are in clear in THIS REPOSITORY wherever they sit, which is the exposure that
 * actually matters and that moving them does not fix. They stay in MainTask.php
 * because that is where the schedule that uses them lives, and arrive through the
 * constructor.
 *
 * WHY THE WORKER DOES NOT LOAD THIS FILE
 * `worker.php` runs as www-data and must never run a job inline: a restore takes
 * minutes and that loop drains the mail queue every second. It spawns
 * `sudo php cli.php main runJobs` instead, which is what lands here. The spawn
 * decision (`dispatchTimeMachineJobs`) stays in worker.php on purpose — when to
 * start a job is the daemon's concern, what the job does is this file's.
 *
 * @see MainTask::backupJob()      construction and credential injection
 * @see MainTask::runJobsAction()  the `php cli.php main runJobs` entry point
 * @see docs/PLAN-worker-job-extraction.md
 */
class BackupJob extends \Phalcon\Di\Injectable
{
	/**
	 * How long a finished restore waits before clearing sessions. The browser polls
	 * the progress file every couple of seconds, so this is the window in which it
	 * reads "completed" and shows the sign-out card. Without it the operator is
	 * simply logged out with no explanation — the reported symptom.
	 */
	const RESTORE_SIGNOUT_GRACE_SECONDS = 10;

	/** Open flock handles, kept for the process lifetime so the locks stay held. */
	private $jobLocks = [];

	/** The archive password, injected so it never lands in a plaintext payload. */
	private $zipPassword;

	/** S3 credentials used by the import path, injected for the same reason. */
	private $importS3Config;

	public function __construct(string $zipPassword, array $importS3Config)
	{
		$this->zipPassword    = $zipPassword;
		$this->importS3Config = $importS3Config;
	}

	/**
	 * Decide whether a scheduled backup is due this minute and, if so, queue it.
	 *
	 * Lifted from main7Action, which cron calls every minute — hence the exact
	 * `H:i` match rather than a window. `backup_scheduler_last_run` is what stops
	 * the same schedule firing twice inside its matching minute.
	 */
	public function evaluateBackupSchedule(): void
	{
		$getBackupSettings = $this->redis->get("backup_settings");
		$backupSettings = json_decode($getBackupSettings, true);
		$performBackup = $this->redis->get("performBackup");
		if ($getBackupSettings && !$performBackup && !$this->redis->get("lock:backup_task")) {
			if (isset($backupSettings['scheduleBackup']) && $backupSettings['scheduleBackup'] === true) {
				echo "Start schedule backup \n";
				$timezone = isset($backupSettings['timezone']) ? $backupSettings['timezone'] : date_default_timezone_get();
				try {
					$tz = new \DateTimeZone($timezone);
				} catch (\Exception $e) {
					$tz = new \DateTimeZone(date_default_timezone_get());
				}

				$now = new \DateTime("now", $tz);
				$currentTime = $now->format('H:i');
				$currentDayOfWeek = $now->format('l');
				$currentDayOfMonth = intval($now->format('j'));

				$scheduledTime = isset($backupSettings['time']) ? $backupSettings['time'] : "00:00";

				$shouldRun = false;

				// Check Time Match (Cron runs every minute, so exact match is fine)
				echo "Start schedule backup 1\n";
				echo "Current Time: " . $currentTime . "\n";
				echo "Scheduled Time: " . $scheduledTime . "\n";
				echo "Time Match: " . ($currentTime === $scheduledTime ? "Yes" : "No") . "\n";
				echo "Current Date: " . $now->format('Y-m-d') . " (" . $currentDayOfWeek . ", Day " . $currentDayOfMonth . ")\n";
				if ($currentTime === $scheduledTime) {
					echo "Start schedule backup 2\n";
					$frequency = isset($backupSettings['frequency']) ? $backupSettings['frequency'] : "Daily";

					if ($frequency === "Daily") {
						echo "Start schedule backup 3\n";
						$shouldRun = true;
					} elseif ($frequency === "Weekly") {
						echo "Start schedule backup 4\n";
						$scheduledDay = isset($backupSettings['dayOfWeek']) ? $backupSettings['dayOfWeek'] : "Monday";
						echo "Checking Weekly: Current Day ($currentDayOfWeek) vs Scheduled Day ($scheduledDay)\n";
						if ($currentDayOfWeek === $scheduledDay) {
							echo "Start schedule backup 5\n";
							$shouldRun = true;
						}
					} elseif ($frequency === "Monthly") {
						echo "Start schedule backup 6\n";
						$scheduledDate = isset($backupSettings['dayOfMonth']) ? intval($backupSettings['dayOfMonth']) : 1;
						echo "Checking Monthly: Current Date ($currentDayOfMonth) vs Scheduled Date ($scheduledDate)\n";
						if ($currentDayOfMonth === $scheduledDate) {
							echo "Start schedule backup 7\n";
							$shouldRun = true;
						}
					}
				}

				if ($shouldRun) {
					echo "Start schedule backup 8\n";
					// Check if we already ran this scheduled backup today
					$lastRunKey = "backup_scheduler_last_run";
					$today = $now->format("Y-m-d"); // Today in selected timezone
					$lastRunTimestamp = $this->redis->get($lastRunKey);
					// Extract date portion if timestamp exists, otherwise empty
					$lastRunDate = $lastRunTimestamp ? date("Y-m-d", strtotime($lastRunTimestamp)) : "";

					if ($lastRunDate !== $today) {
						echo "Starting Scheduled Backup...\n";
						$performBackupData = [
							"id" => uniqid(),
							"name" => isset($backupSettings['prefix']) ? rtrim($backupSettings['prefix'], '_') : "AutoBackup",
							"type" => $backupSettings['storage'] ?? "Local Disk",
							"includes" => $backupSettings['includes'] ?? ["Database", "Redis", "Recordings", "Voicemails"],
							"is_scheduled" => true,
							"timezone" => $timezone
						];

						$performBackup = json_encode($performBackupData);
						$this->redis->set("performBackup", $performBackup);
						// Store full timestamp of this run (User Timezone)
						$this->redis->set($lastRunKey, $now->format("Y-m-d H:i:s"));
					}
				}
			}
		}
	}

	/**
	 * Run the backup / restore / import / delete queues.
	 *
	 * Called from two places on purpose:
	 *   - `worker.php`, the always-running daemon, within a second of a job being
	 *     queued. That is what makes pressing a button feel immediate.
	 *   - `main7Action`, the one-minute cron tick, as the safety net. If the daemon
	 *     is stopped or wedged, jobs are late but never lost.
	 *
	 * Double execution is impossible regardless of who calls it: every job takes an
	 * flock held for the life of the process (acquireJobLock), so a second caller
	 * finds the lock held and returns without doing anything.
	 */
	public function processJobQueues(): void
	{
		$email          = new Library\Email();
		$ringqConfig    = json_decode($this->redis->get("ringq_config"), true);
		$backupSettings = json_decode($this->redis->get("backup_settings"), true);
		$zipPassword    = $this->zipPassword;

		// Re-read rather than inheriting the caller's copy: main7Action reads this
		// BEFORE its scheduler block runs, so a backup the schedule had just queued
		// used to sit until the following minute.
		$performBackup  = $this->redis->get("performBackup");

		if ($performBackup) {
			if (!$this->redis->get("lock:backup_task")) {
				$this->redis->setex("lock:backup_task", 1800, 1);
				// Claim the job immediately so a process-kill or time-limit abort
				// cannot leave performBackup in Redis and cause a duplicate run
				// once the lock expires.
				$this->redis->del("performBackup");
				$backupDomainUuid = null;

				// Same contract as restore and import: a document on disk, so the UI can
				// show real phases instead of a bar that only says "Please wait...".
				require_once "/var/www/ringq/app/library/JobProgress.php";
				$backupMeta   = json_decode($performBackup, true) ?: [];
				$progress     = new \Library\JobProgress('backup');
				$queuedBackup = \Library\JobProgress::read('backup');
				$progress->begin([
					"name"     => isset($backupMeta['name']) ? $backupMeta['name'] : '',
					"type"     => isset($backupMeta['type']) ? $backupMeta['type'] : 'Local Disk',
					"includes" => isset($backupMeta['includes']) ? $backupMeta['includes'] : [],
				], ($queuedBackup['state'] === 'queued' && isset($queuedBackup['jobId'])) ? $queuedBackup['jobId'] : null);
				$backupSucceeded = false;
				try {
					$serverType = $this->redis->get('install_mode');
					$backupData = json_decode($performBackup, true);
					set_time_limit(1800);

					// Determine timezone for filename
					$timezone = isset($backupData['timezone']) ? $backupData['timezone'] : date_default_timezone_get();
					try {
						$tz = new \DateTimeZone($timezone);
					} catch (\Exception $e) {
						$tz = new \DateTimeZone(date_default_timezone_get());
					}
					$now = new \DateTime("now", $tz);
					$date = $now->format('Y-m-d_H-i-s');

					$baseTmpDir = "/tmp/ringq_backup_" . $date;
					$coreDir    = $baseTmpDir . "/core";
					$mediaDir   = $baseTmpDir . "/media";

					$progress->phase('prepare');
					mkdir($coreDir, 0777, true);
					mkdir($mediaDir, 0777, true);

					// Source domain identification for cross-FQDN restore
					$sourceDomain = VDomains::findFirst(["conditions" => "domain_enabled = 'true' AND domain_name = '{$ringqConfig['data']['fqdn']}'", "limit" => 1]);
					$backupDomainUuid = $sourceDomain ? $sourceDomain->domain_uuid : null;
					$backupDomainName = $sourceDomain ? $sourceDomain->domain_name : null;

					/* ================================================================
					 * STEP 1 — SHARED: dump Redis RDB + PostgreSQL into $coreDir
					 * ============================================================= */
					$strictRdb = in_array($backupData['type'], ['Local Disk', 'RingQ Service']);
					$this->backupDumpCoreFiles($coreDir, $date, $strictRdb, $progress);
					$metadata = $this->backupBuildMetadata(
						$backupData, $date, $timezone, $backupDomainUuid, $backupDomainName, $serverType
					);

					/* ================================================================
					 * STEP 2 — TYPE-SPECIFIC: package and upload
					 * ============================================================= */
					$size = 0;

					if (in_array($backupData['type'], ['Local Disk', 'RingQ Service'])) {
						/* --- FLAT ZIP (Local Disk / RingQ Service) --- */
						echo "Starting {$backupData['type']} Backup\n";
						$finalDir = "/var/www/ringq/app/migrations";
						if (!is_dir($finalDir)) {
							mkdir($finalDir, 0755, true);
						}

						// RingQ Service strips any trailing timestamp from the stored name
						$zipBaseName = ($backupData['type'] === 'RingQ Service')
							? preg_replace('/_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', '', $backupData['name'])
							: $backupData['name'];
						$finalZipPath = "{$finalDir}/{$zipBaseName}_{$date}.zip";

						file_put_contents("{$coreDir}/metadata.json", json_encode($metadata, JSON_PRETTY_PRINT));

						$progress->phase('media');
						$mediaZips = $this->backupCollectMediaZips(
							$backupData['includes'] ?? [], $backupData['name'], $date, $mediaDir, false, $zipPassword, $progress
						);

						$coreFiles = array_merge(glob("{$coreDir}/*.rdb"), glob("{$coreDir}/*.dump"));
						if (empty($coreFiles)) {
							throw new \Exception("No core files found to zip");
						}

						$filesToZip = array_merge(
							$coreFiles,
							["{$coreDir}/metadata.json"],
							array_filter($mediaZips, 'file_exists')
						);

						$progress->phase('package');
						$zip = new Process(array_merge(['zip', '-j', '-P', $zipPassword, $finalZipPath], $filesToZip));
						$zip->setTimeout(1800)->run($this->zipProgressCallback($progress, count($filesToZip)));
						if (!$zip->isSuccessful()) {
							throw new ProcessFailedException($zip);
						}

						$size = filesize($finalZipPath);
						$progress->phase('register');
						exec("rm -rf " . escapeshellarg($baseTmpDir));
						$this->backupLogAndRetain($backupData, $date, $timezone, $size, $backupSettings);
						$backupSucceeded = true;

					} elseif (in_array($backupData['type'], ['AWS S3', 'FTP', 'SFTP'])) {
						/* --- NESTED ZIP → REMOTE UPLOAD (AWS S3 / FTP / SFTP) --- */
						echo "Starting {$backupData['type']} Backup\n";

						// Root metadata
						$metadataFile = "{$baseTmpDir}/metadata.json";
						file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT));

						// Core zip: .rdb + .dump + inner metadata
						$coreZipPath  = "{$baseTmpDir}/{$backupData['name']}_{$date}_core.zip";
						$coreMetadata = $metadata;
						if ($backupData['type'] === 'AWS S3') {
							$coreMetadata['includes'] = array_values(
								array_intersect(["Database", "Redis"], $backupData['includes'] ?? [])
							);
						}
						file_put_contents("{$coreDir}/metadata.json", json_encode($coreMetadata, JSON_PRETTY_PRINT));
						$coreItems = array_merge(
							glob("{$coreDir}/*.rdb"), glob("{$coreDir}/*.dump"), ["{$coreDir}/metadata.json"]
						);
						if (!empty($coreItems)) {
							(new Process(array_merge(['zip', '-j', '-P', $zipPassword, $coreZipPath], $coreItems)))->run();
						}

						// Password-protected media zips
						$progress->phase('media');
						$mediaZips = $this->backupCollectMediaZips(
							$backupData['includes'] ?? [], $backupData['name'], $date, $baseTmpDir, true, $zipPassword, $progress
						);

						// Master zip: root metadata + core zip + media zips
						$masterZipName = "{$backupData['name']}_{$date}.zip";
						$masterZipPath = "{$baseTmpDir}/{$masterZipName}";
						$masterItems   = array_merge([$metadataFile, $coreZipPath], $mediaZips);
						$progress->phase('package');
						$zipMaster     = new Process(array_merge(['zip', '-j', '-P', $zipPassword, $masterZipPath], $masterItems));
						$zipMaster->setTimeout(3600)->run($this->zipProgressCallback($progress, count($masterItems)));
						if (!$zipMaster->isSuccessful()) {
							echo "Master zipping failed: " . $zipMaster->getErrorOutput() . "\n";
						}

						// Upload
						if (file_exists($masterZipPath)) {
							$size = filesize($masterZipPath);
							$progress->phase('upload', $this->formatBytes($size) . ' to ' . $backupData['type']);

							if ($backupData['type'] === 'AWS S3') {
								$s3Config = $backupSettings['storageConfig']['AWS S3'] ?? [];
								$awsEnv   = [
									'AWS_ACCESS_KEY_ID'     => $s3Config['accessKey'] ?? '',
									'AWS_SECRET_ACCESS_KEY' => $s3Config['secretKey'] ?? '',
									'AWS_DEFAULT_REGION'    => $s3Config['region'] ?? 'ap-southeast-1',
								];
								$bucket = !empty($s3Config['storagePath']) ? $s3Config['storagePath'] : ($ringqConfig['data']['fqdn'] ?? 'ringq-backups');
								echo "Bucket: {$bucket}\n";

								$bucketNameOnly = explode('/', $bucket)[0];
								$checkBucket = new Process(['aws', 's3api', 'head-bucket', '--bucket', $bucketNameOnly]);
								$checkBucket->setEnv($awsEnv)->run();
								if (!$checkBucket->isSuccessful()) {
									$createBucket = new Process(['aws', 's3', 'mb', "s3://{$bucketNameOnly}"]);
									$createBucket->setEnv($awsEnv)->run();
									if (!$createBucket->isSuccessful()) {
										$bucketError = $createBucket->getErrorOutput();
										echo "Failed to create bucket: {$bucketError}\n";
										if (strpos($bucketError, 'BucketAlreadyExists') !== false) {
											echo "IMPORTANT: The bucket name '{$bucketNameOnly}' is already taken by another AWS user. Please use a more unique name (e.g., your domain name) in Storage Settings.\n";
										}
									}
								}

								$upload = new Process(['aws', 's3', 'cp', $masterZipPath, "s3://{$bucket}/{$masterZipName}"]);
								$upload->setEnv($awsEnv)->setTimeout(null)->run(function ($type, $buffer) use ($progress) {
									$this->reportAwsProgress($progress, $buffer);
								});
								if (!$upload->isSuccessful()) {
									echo "Master zip upload failed: " . $upload->getErrorOutput() . "\n";
								}

							} elseif ($backupData['type'] === 'FTP') {
								$ftp  = $backupSettings['storageConfig']['FTP'] ?? [];
								$host = $ftp['host'] ?? '';
								$port = $ftp['port'] ?? 21;
								$user = $ftp['username'] ?? '';
								$pass = $ftp['password'] ?? '';
								$path = rtrim($ftp['path'] ?? '', '/');

								$remoteFile = $path ? "$path/$masterZipName" : $masterZipName;
								$url        = "ftp://$host:$port/" . ltrim($remoteFile, '/');
								$uploadCmd  = "curl -v --ftp-create-dirs -T " . escapeshellarg($masterZipPath) .
									" --user " . escapeshellarg("$user:$pass") . " " . escapeshellarg($url);
								exec($uploadCmd . " 2>&1", $ftpOut, $ftpCode);
								if ($ftpCode !== 0) {
									echo "FTP Upload failed (Code $ftpCode): " . implode("\n", $ftpOut) . "\n";
								}

							} elseif ($backupData['type'] === 'SFTP') {
								$sftp       = $backupSettings['storageConfig']['SFTP'] ?? [];
								$host       = $sftp['host'] ?? '';
								$port       = $sftp['port'] ?? 22;
								$user       = $sftp['username'] ?? '';
								$authMethod = $sftp['authMethod'] ?? 'password';
								$path       = rtrim($sftp['path'] ?? '', '/');
								$remoteFile = $path ? "$path/$masterZipName" : $masterZipName;

								$keyFile = null;
								if ($authMethod === 'key' && !empty($sftp['privateKey'])) {
									$keyFile = tempnam(sys_get_temp_dir(), 'sftp_key_');
									file_put_contents($keyFile, $sftp['privateKey']);
									chmod($keyFile, 0400);
									$uploadCmd =
										"sftp -i " . escapeshellarg($keyFile) .
										" -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
										escapeshellarg("$user@$host") . " << 'EOF'\n" .
										"put " . escapeshellarg($masterZipPath) . " " . escapeshellarg($remoteFile) . "\n" .
										"EOF";
								} else {
									$pass = $sftp['password'] ?? '';
									$uploadCmd =
										"export SSHPASS=" . escapeshellarg($pass) . ";\n" .
										"sshpass -e sftp -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
										escapeshellarg("$user@$host") . " << 'EOF'\n" .
										"put " . escapeshellarg($masterZipPath) . " " . escapeshellarg($remoteFile) . "\n" .
										"EOF";
								}
								exec($uploadCmd, $sftpOut, $sftpCode);
								if ($sftpCode !== 0) {
									echo "SFTP Upload failed: " . implode("\n", $sftpOut) . "\n";
								}
								if ($keyFile) {
									@unlink($keyFile);
								}
							}
						}

						$progress->phase('register');
						exec("rm -rf " . escapeshellarg($baseTmpDir));
						$this->redis->del("performBackup");
						$backupSucceeded = true;
						$this->redis->lpush("backups", json_encode([
							"id"       => $backupData['id'],
							"name"     => $backupData['name'],
							"datetime" => $date,
							"timezone" => $timezone,
							"size"     => $size,
							"type"     => $backupData['type'],
							"includes" => $backupData['includes'],
						]));
					}

					// --- EMAIL NOTIFICATION (SUCCESS) ---
					if (isset($backupData['is_scheduled']) && $backupData['is_scheduled'] === true && isset($backupSettings['notifySuccess']) && $backupSettings['notifySuccess'] === true && !empty($backupSettings['email'])) {
						$formatBytes = function ($bytes, $precision = 2) { // Ex. Input 1024, Output 1 KB
							$units = array('B', 'KB', 'MB', 'GB', 'TB');
							$bytes = max($bytes, 0);
							$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
							$pow = min($pow, count($units) - 1);
							$bytes /=pow(1024, $pow);
							return round($bytes, $precision) . ' ' . $units[$pow];
						};

						$customName = "RingQ";
						if ($backupDomainUuid) {
							$domain_uuid = $backupDomainUuid;
							$licenseCacheKey = "api_cache:licensecheckuser:" . $domain_uuid;
							$cachedLicense = $this->redis->get($licenseCacheKey);
							$cachedLicense = json_decode($cachedLicense, true);
							$customName = $cachedLicense["companyNameWhiteLabel"];
						}

						$email->send($backupSettings['email'], "Backup Strategy: Success", "Backup Notification", [
							"type" => 15,
							"hostname" => $ringqConfig['data']['fqdn'] ?? 'RingQ Server',
							"backupName" => $backupData['name'],
							"date" => \DateTime::createFromFormat('Y-m-d_H-i-s', $date, $tz)->format('F j, Y, g:i A'),
							"size" => $formatBytes($size),
							"includes" => implode(", ", $backupData['includes']),
							"storage" => $backupData['type']
						], $ringqConfig, $customName);
					}
				} catch (\Throwable $e) {
					// Widened from \Exception: an Error escaping here still ran the finally
					// below, which deletes performBackup — so the UI saw the job disappear
					// and reported success for a backup that never happened.
					$progress->fail($this->backupFailureCode($e), $e->getMessage());
					// --- EMAIL NOTIFICATION (FAILURE) ---
					if ($backupSettings) {
						if (isset($backupData['is_scheduled']) && $backupData['is_scheduled'] === true && isset($backupSettings['notifyFailure']) && $backupSettings['notifyFailure'] === true && !empty($backupSettings['email'])) {
							$customName = "RingQ";
							if ($vDomains) {
								$licenseCacheKey = "api_cache:licensecheckuser:" . $backupDomainUuid;
								$cachedLicense = $this->redis->get($licenseCacheKey);
								$cachedLicense = json_decode($cachedLicense, true);
								$customName = $cachedLicense["companyNameWhiteLabel"];
							}
							$email->send($backupSettings['email'], "Backup Strategy: Failure", "Backup Notification", [
								"type" => 16,
								"hostname" => $ringqConfig['data']['fqdn'] ?? 'RingQ Server',
								"backupName" => $backupData['name'] ?? 'Unknown',
								"date" => date('Y-m-d H:i:s'),
								"storage" => $backupData['type'] ?? 'Local Disk',
								"errorMessage" => $e->getMessage()
							], $ringqConfig, $customName);
						}
					}
					error_log("Backup failed: " . $e->getMessage());
					$this->redis->del("performBackup");
				} finally {
					if (!empty($backupSucceeded)) {
						$progress->complete([
							"name" => isset($backupData['name']) ? $backupData['name'] : '',
							"type" => isset($backupData['type']) ? $backupData['type'] : '',
							"sizeBytes" => isset($size) ? (int) $size : null,
						]);
					}
					$this->redis->del("performBackup");
					$this->redis->del("lock:backup_task");
				}
			}
		}
		$performRestore = $this->redis->get("performRestore");
		if ($performRestore) {
			// The Redis lock cannot serialise this job on its own: restoreCoreRedis
			// replaces the entire dataset mid-flight, erasing the lock — and if a
			// restored RDB happens to carry a stale performRestore from the source
			// server, nothing stopped the next tick starting the whole restore again.
			// The file lock is held by this process and released only when it exits.
			if (!$this->redis->get("lock:restore_task") && $this->acquireJobLock('restore')) {
				$this->redis->setex("lock:restore_task", 1800, 1);

				// Ensure a valid CWD for all Symfony Process calls throughout restore.
				// The PHP process may have been started from a directory (e.g. /var/lib/freeswitch/recordings)
				// that gets deleted during restore, causing getcwd() to return "" and Process to throw.
				chdir('/tmp');

				// Progress is published to a FILE, never to Redis. This job replaces the
				// whole Redis dataset half way through (restoreCoreRedis), so a
				// Redis-backed status is destroyed by the very job it reports on — which
				// is how the UI came to read "the performRestore key is gone" as "the
				// restore finished", signing the operator out while media extraction and
				// the FQDN remap were still to come.
				require_once "/var/www/ringq/app/library/JobProgress.php";
				$restoreMeta      = json_decode($performRestore, true) ?: [];
				$restoreSucceeded = false;
				$progress         = new \Library\JobProgress('restore');
				$progress->begin([
					"name"      => isset($restoreMeta['name']) ? $restoreMeta['name'] : '',
					"datetime"  => isset($restoreMeta['datetime']) ? $restoreMeta['datetime'] : '',
					"type"      => isset($restoreMeta['type']) ? $restoreMeta['type'] : 'Local Disk',
					"sizeBytes" => isset($restoreMeta['size']) ? (int) $restoreMeta['size'] : null,
					"includes"  => isset($restoreMeta['includes']) ? $restoreMeta['includes'] : [],
				]);

				// --- PRESERVATION (PRE-RESTORE) ---
				$preservedSchedule    = $this->redis->get("backup_settings");
				$preservedLastRun     = $this->redis->get("backup_scheduler_last_run");
				$performBackupUuid    = $this->redis->get('performBackup:uuid');
				$preservedBackups     = $this->redis->lrange("backups", 0, -1);
				$preservedRingqConfig = $this->redis->get("ringq_config");
				$preservedInstallMode = $this->redis->get("install_mode");

				try {
					$backupData = json_decode($performRestore, true);
					$restoreDate = $backupData['datetime'];
					$restoreName = $backupData['name'];
					$restoreType = $backupData['type'];

					// --- CROSS-FQDN DETECTION ---
					$currentConfig     = json_decode($preservedRingqConfig, true);
					$currentDomainName = $currentConfig['data']['fqdn'] ?? null;
					$currentDomainRow  = VDomains::findFirst(["conditions" => "domain_enabled = 'true' AND domain_name = '{$ringqConfig['data']['fqdn']}'", "limit" => 1]);
					$currentDomainUuid = $currentDomainRow ? $currentDomainRow->domain_uuid : null;

					// Source domain info from backup metadata (set during import)
					$sourceDomainUuid = $backupData['source_domain_uuid'] ?? null;
					$sourceDomainName = $backupData['source_domain_name'] ?? null;
					$isCrossFqdn = false;

					// MT with same FQDN: import validation guarantees domain match — skip all remap logic
					$skipRemap = ($preservedInstallMode === 'mt');

					$tryUnzip = function($zipFile, $destDir, $timeout = null) use ($zipPassword, $performBackupUuid, $progress) {
						$passwords = [$zipPassword];
						if ($performBackupUuid) {
							$passwords[] = $zipPassword . ':' . $performBackupUuid;
						}

						// Entry count for the progress fraction. `unzip -Z1` reads only the
						// central directory, so it is cheap even on a multi-GB archive, and
						// it works on an encrypted zip (encryption hides content, not names).
						// A count of 0 means "cannot measure" and the phase then reports null
						// rather than inventing a number.
						$total = 0;
						$list  = new Process(['sh', '-c', 'unzip -Z1 ' . escapeshellarg($zipFile) . ' 2>/dev/null | wc -l']);
						$list->setTimeout(120);
						$list->run();
						if ($list->isSuccessful()) {
							$total = (int) trim($list->getOutput());
						}

						$process = null;
						foreach ($passwords as $pwd) {
							// Reset per attempt: a wrong first password extracts part of the
							// archive before failing, and counting across both attempts would
							// report more files than the archive contains.
							$done    = 0;
							$process = new Process(['unzip', '-P', $pwd, '-o', $zipFile, '-d', $destDir]);
							$process->setTimeout($timeout);
							$process->run(function ($type, $buffer) use (&$done, $total, $progress) {
								$done += preg_match_all('/^\s*(inflating|extracting|creating|linking):/m', $buffer);
								if (!$progress) {
									return;
								}
								if ($total > 0) {
									$progress->count($done, $total, 'files');
								} else {
									$progress->fraction(null, number_format($done) . ' files');
								}
							});
							if ($process->getExitCode() <= 1) {
								if ($progress) {
									$progress->fraction(1.0);
								}
								return $process;
							}
						}
						throw new ProcessFailedException($process);
					};

					$tmpRestoreDir = "/tmp/ringq_restore_{$restoreDate}";
					$masterZipName = "{$restoreName}_{$restoreDate}.zip";
					$coreDir       = $tmpRestoreDir; // default; overridden for nested-zip formats

					/* ================================================================
					 * STEP 1 — ACQUIRE: type-specific download / locate the backup zip
					 * and normalise to a $coreDir containing .dump + .rdb files.
					 * ============================================================= */
					$progress->phase('acquire');
					if ($restoreType === 'Local Disk') {
						echo "Start Local Disk restore\n";
						$localZip = "/var/www/ringq/app/migrations/{$masterZipName}";
						if (!file_exists($localZip)) {
							throw new \Exception("Backup zip not found: {$localZip}");
						}
						$progress->fraction(1.0, 'Found on local disk');
						mkdir($tmpRestoreDir, 0777, true);
						$progress->phase('extract');
						$tryUnzip($localZip, $tmpRestoreDir);

						if (empty(glob("{$coreDir}/*.dump")) || empty(glob("{$coreDir}/*.rdb"))) {
							throw new \Exception("Core backup files missing after unzip");
						}

					} elseif ($restoreType === 'AWS S3') {
						echo "Start AWS S3 restore\n";
						$s3Config = $backupSettings['storageConfig']['AWS S3'] ?? [];
						$awsEnv   = [
							'AWS_ACCESS_KEY_ID'     => $s3Config['accessKey'] ?? '',
							'AWS_SECRET_ACCESS_KEY' => $s3Config['secretKey'] ?? '',
							'AWS_DEFAULT_REGION'    => $s3Config['region'] ?? 'ap-southeast-1',
						];
						$bucket = !empty($s3Config['storagePath']) ? $s3Config['storagePath'] : ($ringqConfig['data']['fqdn'] ?? 'ringq-backups');
						echo "Bucket: {$bucket}\n";

						mkdir($tmpRestoreDir, 0777, true);
						$localMasterZip = "{$tmpRestoreDir}/{$masterZipName}";

						echo date('[H:i:s]') . " Downloading from S3: s3://{$bucket}/{$masterZipName}...\n";
						$progress->fraction(null, 'Connecting to Amazon S3');
						$dl = new Process(['aws', 's3', 'cp', "s3://{$bucket}/{$masterZipName}", $localMasterZip]);
						$dl->setEnv($awsEnv);
						$dl->setTimeout(null);
						// aws-cli reports "Completed 12.0 MiB/91.4 MiB (…)" on stdout, which is
						// a real measurement — parse it rather than guessing at a spinner.
						$dl->mustRun(function ($type, $buffer) use ($progress) {
							$this->reportAwsProgress($progress, $buffer);
						});
						echo date('[H:i:s]') . " Download complete.\n";

						if (!file_exists($localMasterZip)) {
							throw new \Exception("Master backup zip missing after download: {$localMasterZip}");
						}

						echo date('[H:i:s]') . " Extracting master zip...\n";
						$progress->phase('extract');
						$tryUnzip($localMasterZip, $tmpRestoreDir);
						echo date('[H:i:s]') . " Master zip extracted.\n";

						// The master zip is still present in $tmpRestoreDir after extraction;
						// treat it as the nested core zip to extract .dump / .rdb from.
						if (file_exists("{$tmpRestoreDir}/{$masterZipName}")) {
							$coreDir = "{$tmpRestoreDir}/core";
							mkdir($coreDir, 0777, true);
							echo date('[H:i:s]') . " Extracting core zip...\n";
							$tryUnzip("{$tmpRestoreDir}/{$masterZipName}", $coreDir);
							echo date('[H:i:s]') . " Core zip extracted.\n";
						}

					} elseif ($restoreType === 'FTP') {
						echo "Start FTP restore\n";
						$ftp     = $backupSettings['storageConfig']['FTP'] ?? [];
						$host    = $ftp['host'] ?? '';
						$port    = $ftp['port'] ?? 21;
						$user    = $ftp['username'] ?? '';
						$pass    = $ftp['password'] ?? '';
						$ftpPath = rtrim($ftp['path'] ?? '', '/');
						$remoteFile = $ftpPath ? "$ftpPath/$masterZipName" : $masterZipName;

						mkdir($tmpRestoreDir, 0777, true);
						$localMasterZip = "{$tmpRestoreDir}/{$masterZipName}";
						$progress->fraction(null, 'Downloading over FTP');
						$url = "ftp://$host:$port/" . ltrim($remoteFile, '/');
						$downloadCmd = "curl -v --ftp-create-dirs -o " . escapeshellarg($localMasterZip) .
							" --user " . escapeshellarg("$user:$pass") . " " . escapeshellarg($url);
						exec($downloadCmd . " 2>&1", $ftpOutput, $ftpReturnCode);

						if ($ftpReturnCode !== 0 || !file_exists($localMasterZip)) {
							throw new \Exception("FTP Restore failed: Could not download master zip.");
						}

						echo date('[H:i:s]') . " Extracting master zip...\n";
						$progress->phase('extract');
						$tryUnzip($localMasterZip, $tmpRestoreDir);
						echo date('[H:i:s]') . " Master zip extracted.\n";

						if (file_exists("{$tmpRestoreDir}/{$masterZipName}")) {
							$coreDir = "{$tmpRestoreDir}/core";
							mkdir($coreDir, 0777, true);
							echo date('[H:i:s]') . " Extracting core zip...\n";
							$tryUnzip("{$tmpRestoreDir}/{$masterZipName}", $coreDir);
							echo date('[H:i:s]') . " Core zip extracted.\n";
						}

					} elseif ($restoreType === 'SFTP') {
						echo "Start SFTP restore\n";
						$sftp       = $backupSettings['storageConfig']['SFTP'] ?? [];
						$host       = $sftp['host'] ?? '';
						$port       = $sftp['port'] ?? 22;
						$user       = $sftp['username'] ?? '';
						$authMethod = $sftp['authMethod'] ?? 'password';
						$sftpPath   = rtrim($sftp['path'] ?? '', '/');
						$remoteFile = $sftpPath ? "$sftpPath/$masterZipName" : $masterZipName;

						mkdir($tmpRestoreDir, 0777, true);
						$localMasterZip = "{$tmpRestoreDir}/{$masterZipName}";

						$keyFile = null;
						if ($authMethod === 'key' && !empty($sftp['privateKey'])) {
							$keyFile = tempnam(sys_get_temp_dir(), 'sftp_key_');
							file_put_contents($keyFile, $sftp['privateKey']);
							chmod($keyFile, 0400);
							$downloadCmd =
								"scp -i " . escapeshellarg($keyFile) .
								" -P $port -o BatchMode=no -o StrictHostKeyChecking=no " .
								escapeshellarg("$user@$host:$remoteFile") . " " . escapeshellarg($localMasterZip);
						} else {
							$pass = $sftp['password'] ?? '';
							$downloadCmd =
								"export SSHPASS=" . escapeshellarg($pass) . "; " .
								"sshpass -e scp -P $port -o BatchMode=no -o StrictHostKeyChecking=no " .
								escapeshellarg("$user@$host:$remoteFile") . " " . escapeshellarg($localMasterZip);
						}

						$progress->fraction(null, 'Downloading over SFTP');
						exec($downloadCmd, $sftpOutput, $sftpReturnCode);
						if ($keyFile) { @unlink($keyFile); }

						if ($sftpReturnCode !== 0 || !file_exists($localMasterZip)) {
							throw new \Exception("SFTP Restore failed: Could not download master zip.");
						}

						echo date('[H:i:s]') . " Extracting master zip...\n";
						$progress->phase('extract');
						$tryUnzip($localMasterZip, $tmpRestoreDir);
						echo date('[H:i:s]') . " Master zip extracted.\n";

						if (file_exists("{$tmpRestoreDir}/{$masterZipName}")) {
							$coreDir = "{$tmpRestoreDir}/core";
							mkdir($coreDir, 0777, true);
							echo date('[H:i:s]') . " Extracting core zip...\n";
							$tryUnzip("{$tmpRestoreDir}/{$masterZipName}", $coreDir);
							echo date('[H:i:s]') . " Core zip extracted.\n";
						}

					} elseif ($restoreType === 'RingQ Service') {
						echo "Start RingQ Service restore\n";
						$uuid        = $ringqConfig['data']['uuid'] ?? null;
						$accessToken = $ringqConfig['data']['access_token'] ?? null;
						$licenseKey  = $ringqConfig['data']['licenseKey'] ?? null;
						$userId      = $ringqConfig['data']['user_id'] ?? null;
						$downloadId  = $backupData['downloadId'] ?? null;

						if (!$uuid || !$accessToken || !$licenseKey || !$userId || !$downloadId) {
							throw new \Exception("Missing RingQ Service credentials or download ID for restore.");
						}

						mkdir($tmpRestoreDir, 0777, true);
						$fileName       = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', '', $restoreName) . "_{$restoreDate}.zip";
						$localMasterZip = "{$tmpRestoreDir}/{$fileName}";

						$payload = json_encode(["licenseKey" => $licenseKey, "type" => 4, "userId" => $userId, "id" => $downloadId]);

						echo date('[H:i:s]') . " Downloading from RingQ Service...\n";
						$ch        = curl_init("https://licensekey.ringq.com/backup/backupdata");
						$tmpHandle = fopen($localMasterZip, 'wb');
						curl_setopt_array($ch, [
							CURLOPT_POST           => true,
							CURLOPT_POSTFIELDS     => $payload,
							CURLOPT_HTTPHEADER     => [
								"Content-Type: application/json",
								"UUID: "                . $uuid,
								"Authorization-token: " . $accessToken,
							],
							CURLOPT_RETURNTRANSFER => false,
							CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use ($tmpHandle) {
								fwrite($tmpHandle, $chunk);
								return strlen($chunk);
							},
							CURLOPT_FOLLOWLOCATION => true,
							CURLOPT_TIMEOUT        => 0,
							CURLOPT_SSL_VERIFYPEER => true,
							CURLOPT_NOPROGRESS     => false,
							CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($progress) {
								// $dlTotal is 0 until the response headers arrive, and stays 0
								// for a chunked response — report null rather than 0% then.
								if ($dlTotal > 0) {
									$progress->fraction($dlNow / $dlTotal, $this->formatBytes($dlNow) . ' of ' . $this->formatBytes($dlTotal));
								} else {
									$progress->fraction(null, $this->formatBytes($dlNow) . ' downloaded');
								}
								return 0;
							},
						]);
						$success  = curl_exec($ch);
						$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
						curl_close($ch);
						fclose($tmpHandle);

						if (!$success || $httpCode !== 200) {
							exec("rm -rf " . escapeshellarg($tmpRestoreDir));
							throw new \Exception("Failed to download backup from RingQ Service. HTTP Code: {$httpCode}");
						}
						echo date('[H:i:s]') . " Download complete.\n";

						echo date('[H:i:s]') . " Extracting master zip...\n";
						$progress->phase('extract');
						$tryUnzip($localMasterZip, $tmpRestoreDir);
						error_log("Extracted files: " . implode(', ', glob("{$tmpRestoreDir}/*")));
						echo date('[H:i:s]') . " Master zip extracted.\n";
					}

					/* ================================================================
					 * STEP 2 — RESTORE: shared for all storage types
					 * ============================================================= */
					$dumpFiles = glob("{$coreDir}/*.dump");
					$rdbFiles  = glob("{$coreDir}/*.rdb");

					// Now that the archive is open, replace the cold-start weights with
					// shares of the REAL byte sizes: a 600 MB recordings archive must not
					// be given the same slice of the bar as a 2 MB images zip. Percent is
					// clamped monotonic, so this can never rewind what is already shown.
					$phaseBytes = [];
					if (!empty($dumpFiles)) { $phaseBytes['database'] = (int) @filesize($dumpFiles[0]); }
					if (!empty($rdbFiles))  { $phaseBytes['redis']    = (int) @filesize($rdbFiles[0]); }
					$mediaBytes = 0;
					foreach (['voicemails@', 'recordings@'] as $mediaPrefix) {
						foreach (glob("{$tmpRestoreDir}/{$mediaPrefix}*.zip") ?: [] as $mediaZip) {
							$mediaBytes += (int) @filesize($mediaZip);
						}
					}
					if ($mediaBytes > 0) { $phaseBytes['media'] = $mediaBytes; }
					foreach (glob("{$tmpRestoreDir}/images@*.zip") ?: [] as $imageZip) {
						$phaseBytes['images'] = (int) @filesize($imageZip);
					}
					if ($phaseBytes) { $progress->reweight($phaseBytes); }

					if (!empty($dumpFiles)) {
						$progress->phase('database');
						$result = $this->restoreCorePostgres(
							$dumpFiles[0], $skipRemap, $sourceDomainName, $currentDomainName, $progress
						);
						$isCrossFqdn      = $result['isCrossFqdn'];
						$domainMap        = $result['domainMap'];
						$sourceDomainUuid = $result['sourceDomainUuid'];
						$sourceDomainName = $result['sourceDomainName'];
					}

					if (!empty($rdbFiles)) {
						$progress->phase('redis');
						$this->restoreCoreRedis($rdbFiles[0], $skipRemap, $isCrossFqdn, $domainMap, $progress);
					}

					$this->restoreCoreMedia($tmpRestoreDir, $tryUnzip, $progress);

					if (!$skipRemap && $isCrossFqdn) {
						$progress->phase('remap', 'Re-pointing media at this server');
						$this->remapMediaDirectories($sourceDomainName, $currentDomainName);
					}

					$progress->phase('finalize', 'Cleaning up temporary files');
					echo date('[H:i:s]') . " Cleaning up...\n";
					exec("rm -rf " . escapeshellarg($tmpRestoreDir));
					echo date('[H:i:s]') . " Restore Complete.\n";
					$restoreSucceeded = true;

				} catch (\Throwable $e) {
					// Without this catch the failure was INVISIBLE to the operator: the
					// finally below deletes performRestore, the UI read that absence as
					// success, and signed them out saying the restore had worked. The
					// exception is still echoed and logged, so cron mail keeps its trace.
					$progress->fail($this->restoreFailureCode($e), $e->getMessage());
					echo date('[H:i:s]') . " Restore FAILED: " . $e->getMessage() . "\n";
					error_log('[restore] failed: ' . $e->getMessage());
				} finally {
					// --- PRESERVATION (POST-RESTORE) ---
					if ($preservedSchedule) {
						$this->redis->set("backup_settings", $preservedSchedule);
					}
					if ($preservedLastRun) {
						$this->redis->set("backup_scheduler_last_run", $preservedLastRun);
					}
					if (!empty($preservedBackups)) {
						$this->redis->del("backups");
						foreach ($preservedBackups as $backupJson) {
							$this->redis->rpush("backups", $backupJson);
						}
					}
					// Restore server identity keys
					if ($preservedRingqConfig) {
						$this->redis->set("ringq_config", $preservedRingqConfig);
					}
					if ($preservedInstallMode) {
						$this->redis->set("install_mode", $preservedInstallMode);
					}

					// $output = [];
					// $returnCode = 0;
					// $timedCommand = "timeout 60 sudo /var/www/ringq/app/scripts/startup.sh 2>&1";
					// exec($timedCommand, $output, $returnCode);
					// if ($returnCode === 124) {
					// 	$output[] = "Command timed out after 60 seconds";
					// }
					// error_log("Startup script output: " . implode("\n", $output));

					// After cross-FQDN restore, stop fail2ban before startup to prevent
					// stale SIP registrations from banning IPs on ALL ports via freeswitch-reg
					if (!empty($isCrossFqdn)) {
						exec('sudo systemctl stop fail2ban 2>/dev/null');
						echo date('[H:i:s]') . " Cross-FQDN: stopped fail2ban.\n";
					}

					// Restart fail2ban after startup (FreeSWITCH is fresh, old log entries won't re-trigger bans)
					if (!empty($isCrossFqdn)) {
						// Truncate old freeswitch log to clear stale "Can't find user" entries
						exec('sudo truncate -s 0 /var/log/freeswitch/freeswitch.log 2>/dev/null');
						exec('sudo systemctl start fail2ban 2>/dev/null');
						echo date('[H:i:s]') . " Cross-FQDN: cleared freeswitch log and restarted fail2ban.\n";
					}

					$client = new Client(Client::engine(Client::CLIENT_4X, "ws://127.0.0.1:3000", [
						"persistent" => false,
					]));
					$client->initialize();

					$vDomains = VDomains::find(["conditions" => "domain_enabled = true"]);
					foreach ($vDomains as $vDomain) {
						$client->emit("backupProcess", ["subdomain" => $vDomain->domain_name]);
					}

					$this->redis->del("performDeleteQueue");
					$this->redis->del("performBackup");
					$this->redis->del("performRestore");
					$this->redis->del("lock:backup_task");
					$this->redis->del("lock:restore_task");
					$this->redis->del("lock:restore_task");

					// The terminal state is written BEFORE the session flush, and the flush
					// is then held back briefly. The browser polls every couple of seconds,
					// so this window is what lets it read "completed" and show the sign-out
					// card instead of the operator simply being logged out with no message.
					// The flush is not time critical — the sessions it kills belong to a
					// user table that has already been replaced.
					if (!empty($restoreSucceeded)) {
						$progress->complete([
							"crossFqdn"   => !empty($isCrossFqdn),
							"signOut"     => true,
							"elapsedNote" => 'Sessions were cleared because the user accounts on this server were replaced by the backup.',
						]);
						sleep(self::RESTORE_SIGNOUT_GRACE_SECONDS);
					}

					// Flush memcached to invalidate all sessions (forces re-login after restore)
					// Sessions use Libmemcached adapter with prefix "ringq_" on 127.0.0.1:11211
					exec('echo "flush_all" | nc -q1 127.0.0.1 11211 2>/dev/null');
					echo date('[H:i:s]') . " Flushed memcached sessions.\n";
				}
			}
		}

		$performImport = $this->redis->get("performImport");
		if ($performImport && $this->acquireJobLock('import')) {
			$this->processImportJob(json_decode($performImport, true) ?: [], $zipPassword);
		}

		if ($this->redis->exists("performDeleteQueue") && $this->redis->llen("performDeleteQueue") > 0) {
			if (!$this->redis->get("lock:deletion_task")) {
				$this->redis->setex("lock:deletion_task", 600, 1);

				try {
					while ($performDelete = $this->redis->rpop("performDeleteQueue")) {
						$backupData = json_decode($performDelete, true);
						echo date('[H:i:s]') . " Processing Deletion: " . json_encode($backupData) . "\n";
						$date = $backupData['datetime'];
						$name = $backupData['name'];

						// Delete files in Local Disk
						if (!isset($backupData['type']) || $backupData['type'] === 'Local Disk') {
							// Delete local files
							$localFiles = [
								"/var/www/ringq/app/migrations/{$name}_{$date}.zip",
							];

							foreach ($localFiles as $file) {
								if (file_exists($file)) {
									unlink($file);
								}
							}

							if (is_dir("/var/www/ringq/app/migrations/". $date)) {
								exec("rm -rf " . escapeshellarg("/var/www/ringq/app/migrations/". $date));
							}
						}

						if ($backupData['type'] == 'AWS S3') {
							// Delete AWS S3
							$s3CustomConfig = isset($backupSettings['storageConfig']['AWS S3']) ? $backupSettings['storageConfig']['AWS S3'] : [];

							$awsCustomEnv = [
								'AWS_ACCESS_KEY_ID'     => $s3CustomConfig['accessKey'] ?? '',
								'AWS_SECRET_ACCESS_KEY' => $s3CustomConfig['secretKey'] ?? '',
								'AWS_DEFAULT_REGION'    => $s3CustomConfig['region'] ?? 'ap-southeast-1'
							];
							$bucket = !empty($s3CustomConfig['storagePath']) ? $s3CustomConfig['storagePath'] : ($ringqConfig['data']['fqdn'] ?? 'ringq-backups');

							// Delete files in S3
							$file = "{$name}_{$date}.zip";
							$s3Path = "s3://{$bucket}/{$file}";
							$deleteProcess = new Process(['aws', 's3', 'rm', $s3Path]);
							$deleteProcess->setEnv($awsCustomEnv);
							$deleteProcess->run();

							if (!$deleteProcess->isSuccessful()) {
								echo "S3 Deletion Failed for $file: " . $deleteProcess->getErrorOutput() . "\n";
							} else {
								echo "S3 Deletion Success: $file\n";
							}
						}

						// Delete files in FTP
						if ($backupData['type'] === 'FTP') {
							$ftp = $backupSettings['storageConfig']['FTP'] ?? [];
							if (!empty($ftp['host']) && !empty($ftp['username'])) {
								$host = $ftp['host'];
								$port = $ftp['port'] ?? 21;
								$user = $ftp['username'];
								$pass = $ftp['password'];
								$path = rtrim($ftp['path'] ?? '', '/');

								$fileName = "{$name}_{$date}.zip";
								$remoteFile = $path ? "$path/$fileName" : $fileName;

								// Use curl -Q to send DELE command
								$deleteCmd = "curl -v --user " . escapeshellarg("$user:$pass") . " -Q " . escapeshellarg("DELE " . ltrim($remoteFile, '/')) . " " . escapeshellarg("ftp://$host:$port/");

								exec($deleteCmd . " 2>&1", $output, $returnCode);
								if ($returnCode !== 0) {
									echo "FTP Deletion Failed (Code $returnCode) for $remoteFile: " . implode("\n", $output) . "\n";
								} else {
									echo "FTP Deletion Success: $remoteFile\n";
								}
							}
						}

						// Delete files in SFTP
						if ($backupData['type'] === 'SFTP') {
							$sftp = $backupSettings['storageConfig']['SFTP'] ?? [];
							if (!empty($sftp['host']) && !empty($sftp['username'])) {
								$host = $sftp['host'];
								$port = $sftp['port'] ?? 22;
								$user = $sftp['username'];
								$authMethod = $sftp['authMethod'] ?? 'password';
								$path = rtrim($sftp['path'] ?? '', '/');

								$fileName = "{$name}_{$date}.zip";
								$remoteFile = $path ? "$path/$fileName" : $fileName;

								$keyFile = null;
								if ($authMethod === 'key' && !empty($sftp['privateKey'])) {
									$keyFile = tempnam(sys_get_temp_dir(), 'sftp_key_');
									file_put_contents($keyFile, $sftp['privateKey']);
									chmod($keyFile, 0400);
									$deleteCmd =
										"sftp -i " . escapeshellarg($keyFile) .
										" -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
										escapeshellarg("$user@$host") . " << 'EOF'\n" .
										"rm " . escapeshellarg($remoteFile) . "\n" .
										"EOF";
								} else {
									$pass = $sftp['password'] ?? '';
									$deleteCmd =
										"export SSHPASS=" . escapeshellarg($pass) . "; " .
										"sshpass -e sftp -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
										escapeshellarg("$user@$host") . " << 'EOF'\n" .
										"rm " . escapeshellarg($remoteFile) . "\n" .
										"EOF";
								}

								exec($deleteCmd, $output, $returnCode);
								if ($returnCode !== 0) {
									echo "SFTP Deletion Failed for $remoteFile: " . implode("\n", $output) . "\n";
								} else {
									echo "SFTP Deletion Success: $remoteFile\n";
								}

								if ($keyFile) {
									@unlink($keyFile);
								}
							}
						}

						if ($backupData['type'] === 'RingQ Service') {
							// Delete local files
							$localFiles = [
								"/var/www/ringq/app/migrations/{$name}_{$date}.zip",
							];

							foreach ($localFiles as $file) {
								if (file_exists($file)) {
									unlink($file);
								}
							}

							if (is_dir("/var/www/ringq/app/migrations/". $date)) {
								exec("rm -rf " . escapeshellarg("/var/www/ringq/app/migrations/". $date));
							}
						}
					}
				} finally {
					$this->redis->del("lock:deletion_task");
				}
			}
		}

	}

	/**
	 * Remap domain_uuid and domain_name in PostgreSQL after a cross-FQDN restore.
	 *
	 * v_domains is preserved from instance B (the target). All other tables have
	 * their domain_uuid and domain_name remapped from instance A (source) to instance B.
	 *
	 * Mapping logic:
	 *   - Domains with the same UUID in both instances are shared and need no remap.
	 *   - FQDN domains map to each other, IP-based domains map to each other.
	 */

	/**
	 * Save Redis RDB and run pg_dump into $coreDir/{ringq_$date}.rdb/.dump.
	 * When $strict is true, throws if the Redis RDB file is missing.
	 */
	private function backupDumpCoreFiles(string $coreDir, string $date, bool $strict = false, $progress = null): void {
		$dirConfig  = $this->redis->executeRaw(['CONFIG', 'GET', 'dir']);
		$fileConfig = $this->redis->executeRaw(['CONFIG', 'GET', 'dbfilename']);
		$redisPath  = rtrim($dirConfig[1], '/') . '/' . $fileConfig[1];

		if ($progress) { $progress->phase('redis', 'Writing a settings snapshot'); }
		$this->redis->save();

		if (!file_exists($redisPath)) {
			if ($strict) {
				throw new \Exception("Redis RDB not found");
			}
		} else {
			copy($redisPath, "{$coreDir}/ringq_{$date}.rdb");
		}

		$pgDumpFile = "{$coreDir}/ringq_{$date}.dump";
		$pgProcess  = new Process([
			'pg_dump',
			'-U', $this->config->database['username'],
			'-h', $this->config->database['host'],
			'-F', 'c',
			'-f', $pgDumpFile,
			$this->config->database['dbname'],
		]);
		$pgProcess->setEnv(['PGPASSWORD' => $this->config->database['password']]);
		$pgProcess->setTimeout(600);

		if ($progress) {
			// pg_dump -F c writes straight to a file and prints nothing, so the only
			// signal is the file growing. The dump is compressed by a ratio nobody can
			// know in advance, so pg_database_size() would be a misleading total — but
			// THIS server's last dump is an honest, self-calibrating estimate, and the
			// copy says "of about" so it is never read as an exact figure. With no
			// previous dump to compare against the fraction stays null and only the
			// bytes are shown; an invented percentage is worse than "still working".
			$estimate = (int) $this->redis->get('backup_last_dump_bytes');
			$progress->phase('database', 'Starting');
			$pgProcess->start();
			while ($pgProcess->isRunning()) {
				usleep(500000);
				clearstatcache(true, $pgDumpFile);
				$written = (int) @filesize($pgDumpFile);
				if ($written <= 0) {
					$progress->fraction(null, 'Starting');
				} elseif ($estimate > 0) {
					// Capped below 1 so a bigger-than-last dump cannot sit at 100% while
					// it is still being written.
					$progress->fraction(
						min($written / $estimate, 0.99),
						$this->formatBytes($written) . ' of about ' . $this->formatBytes($estimate)
					);
				} else {
					$progress->fraction(null, $this->formatBytes($written) . ' written');
				}
			}
			$pgProcess->wait();
		} else {
			$pgProcess->run();
		}

		if (!$pgProcess->isSuccessful()) {
			throw new ProcessFailedException($pgProcess);
		}
		$dumpBytes = (int) @filesize($pgDumpFile);
		if ($dumpBytes > 0) {
			// Feeds the next backup's estimate. Deliberately not persisted anywhere
			// durable: it is a convenience, and losing it only costs one uncalibrated run.
			$this->redis->set('backup_last_dump_bytes', $dumpBytes);
		}
		if ($progress) {
			$progress->fraction(1.0, $this->formatBytes($dumpBytes));
		}
	}

	/**
	 * Build the standard backup metadata array.
	 */
	private function backupBuildMetadata(array $backupData, string $date, string $timezone, ?string $domainUuid, ?string $domainName, string $serverType): array {
		return [
			"version"     => "1.1",
			"name"        => $backupData['name'],
			"datetime"    => $date,
			"timezone"    => $timezone,
			"type"        => $backupData['type'],
			"includes"    => $backupData['includes'],
			"domain_uuid" => $domainUuid,
			"domain_name" => $domainName,
			"server_type" => $serverType,
		];
	}

	/**
	 * Zip voicemail and recording directories into $destDir.
	 * Returns an array of created zip file paths.
	 * When $passwordProtect is true the zip is encrypted with $zipPassword.
	 */
	private function backupCollectMediaZips(array $includes, string $name, string $date, string $destDir, bool $passwordProtect, string $zipPassword, $progress = null): array {
		$sources = [
			'Voicemails' => [
				'src'    => "/var/lib/freeswitch/storage/voicemail/default",
				'prefix' => 'voicemails',
			],
			'Recordings' => [
				'src'    => "/var/lib/freeswitch/recordings",
				'prefix' => 'recordings',
			],
		];

		$created = [];
		foreach ($sources as $key => $item) {
			if (!in_array($key, $includes) || !is_dir($item['src'])) {
				continue;
			}
			$zipPath = "{$destDir}/{$item['prefix']}@{$name}_{$date}.zip";
			$cmd     = $passwordProtect
				? ['zip', '-r', '-P', $zipPassword, $zipPath, '.']
				: ['zip', '-r', $zipPath, '.'];
			if ($progress) { $progress->fraction(null, 'Compressing ' . strtolower($key)); }
			(new Process($cmd, $item['src']))->setTimeout(1800)->run($this->zipProgressCallback($progress, 0));
			if ($progress) { $progress->fraction(null, strtolower($key) . ' — ' . $this->formatBytes(@filesize($zipPath))); }
			$created[] = $zipPath;
		}

		// Images: multiple absolute-path source directories bundled into one zip.
		// Stored as paths relative to "/" so restore can unzip directly to root.
		if (in_array('Images', $includes)) {
			$imageDirs = $this->backupImageDirs();
			$existing  = array_values(array_filter($imageDirs, 'is_dir'));
			if (!empty($existing)) {
				$zipPath = "{$destDir}/images@{$name}_{$date}.zip";
				$cmd     = $passwordProtect
					? ['zip', '-r', '-P', $zipPassword, $zipPath]
					: ['zip', '-r', $zipPath];
				$cmd     = array_merge($cmd, array_map(fn($p) => ltrim($p, '/'), $existing));
				if ($progress) { $progress->fraction(null, 'Compressing images'); }
				(new Process($cmd, '/'))->setTimeout(1800)->run($this->zipProgressCallback($progress, 0));
				$created[] = $zipPath;
			}
		}

		return $created;
	}

	/**
	 * Absolute paths of image-content directories included in backups when "Images"
	 * is in the backup includes list. Single source of truth used by both backup
	 * (zip creation) and restore (clear-then-extract).
	 */
	private function backupImageDirs(): array {
		return [
			'/var/www/ringq/public/avr',
			'/var/www/webapp/chat_file_upload',
			'/var/www/ringq/public/meet_chat',
			'/var/www/ringq/public/meet_recordings',
			'/var/www/ringq/public/meet_whiteboards',
		];
	}

	/**
	 * Push backup entry to Redis and apply retention policy.
	 * Retention evicts oldest entries beyond $backupSettings['retention'] (default 7).
	 * Only 'Local Disk' entries are queued for physical deletion on eviction.
	 */
	private function backupLogAndRetain(array $backupData, string $date, string $timezone, int $size, ?array $backupSettings): void {
		$backupSettings = $backupSettings ?? [];
		$this->redis->lpush("backups", json_encode([
			"id"       => $backupData['id'],
			"name"     => $backupData['name'],
			"datetime" => $date,
			"timezone" => $timezone,
			"size"     => $size,
			"type"     => $backupData['type'],
			"includes" => $backupData['includes'],
		]));

		$retentionCount = !empty($backupSettings['retention']) ? intval($backupSettings['retention']) : 7;
		if ($retentionCount <= 0) {
			return;
		}

		$formattedBackups = array_map(
			fn($b) => json_decode($b, true),
			$this->redis->lrange("backups", 0, -1)
		);

		if (count($formattedBackups) <= $retentionCount) {
			return;
		}

		$itemsToDelete = array_slice($formattedBackups, $retentionCount);
		foreach ($itemsToDelete as $itemToDelete) {
			$jsonItem = json_encode($itemToDelete);
			$this->redis->lrem("backups", 0, $jsonItem);
			if (isset($itemToDelete['type']) && $itemToDelete['type'] === 'Local Disk') {
				$this->redis->lpush("performDeleteQueue", $jsonItem);
			}
		}
		echo "Retention Policy: Queued " . count($itemsToDelete) . " old backups for deletion.\n";
	}

	/**
	 * Restore PostgreSQL from a dump file and detect cross-FQDN remap needs.
	 * Returns an array with keys: isCrossFqdn, domainMap, sourceDomainUuid, sourceDomainName.
	 */
	/**
	 * Take the on-disk lock for a long job, without blocking.
	 *
	 * A Redis lock cannot do this: a restore replaces the whole Redis dataset, so it
	 * erases its own lock half way through. The handle is kept on the instance
	 * because closing it releases the lock — the kernel drops it when the process
	 * exits, which is exactly the lifetime wanted.
	 */
	private function acquireJobLock(string $job): bool
	{
		require_once "/var/www/ringq/app/library/JobProgress.php";
		$progress = new \Library\JobProgress($job);
		if (!$progress->available()) {
			// The state directory could not be created. Losing the progress bar is
			// acceptable; refusing to run the job because of it is not.
			return true;
		}
		$handle = @fopen($progress->lockPath(), 'c');
		if (!$handle) {
			return true;
		}
		if (!@flock($handle, LOCK_EX | LOCK_NB)) {
			@fclose($handle);
			echo date('[H:i:s]') . " {$job}: another run already holds the lock, skipping.\n";
			return false;
		}
		$this->jobLocks[$job] = $handle;

		return true;
	}

	/**
	 * Turn an exception into a stable code the UI can key copy off, so the operator
	 * is told what went wrong rather than being shown a raw stack message alone.
	 */
	private function restoreFailureCode(\Throwable $e): string
	{
		$message = strtolower($e->getMessage());
		$map = [
			'backup zip not found'      => 'backup_missing',
			'master backup zip missing' => 'download_failed',
			'could not download'        => 'download_failed',
			'failed to download'        => 'download_failed',
			'core backup files missing' => 'archive_incomplete',
			'redis restore failed'      => 'redis_rdb_incompatible',
			'missing ringq service'     => 'service_credentials_missing',
		];
		foreach ($map as $needle => $code) {
			if (strpos($message, $needle) !== false) {
				return $code;
			}
		}
		// A wrong archive password surfaces as a failed unzip process.
		if (strpos($message, 'unzip') !== false) {
			return 'archive_unreadable';
		}

		return 'restore_failed';
	}

	/**
	 * Parse aws-cli's own transfer line, e.g.
	 *   Completed 12.0 MiB/91.4 MiB (3.1 MiB/s) with 1 file(s) remaining
	 * A line that does not match simply reports nothing, so a change in aws-cli's
	 * wording degrades to "no percentage" rather than to a wrong one.
	 */
	private function reportAwsProgress($progress, string $buffer): void
	{
		if (!$progress) {
			return;
		}
		if (!preg_match('/Completed\s+([\d.]+)\s*(Bytes?|KiB|MiB|GiB|TiB)\s*\/\s*([\d.]+)\s*(Bytes?|KiB|MiB|GiB|TiB)/i', $buffer, $m)) {
			return;
		}
		$done  = $this->unitToBytes((float) $m[1], $m[2]);
		$total = $this->unitToBytes((float) $m[3], $m[4]);
		if ($total <= 0) {
			return;
		}
		$progress->fraction($done / $total, $this->formatBytes($done) . ' of ' . $this->formatBytes($total));
	}

	private function unitToBytes(float $value, string $unit): float
	{
		$factors = ['byte' => 1, 'bytes' => 1, 'kib' => 1024, 'mib' => 1048576, 'gib' => 1073741824, 'tib' => 1099511627776];
		$key     = strtolower($unit);

		return $value * (isset($factors[$key]) ? $factors[$key] : 1);
	}

	private function formatBytes($bytes): string
	{
		$bytes = (float) $bytes;
		$units = ['B', 'KB', 'MB', 'GB', 'TB'];
		$i     = 0;
		while ($bytes >= 1024 && $i < count($units) - 1) {
			$bytes /= 1024;
			$i++;
		}

		return round($bytes, $bytes >= 100 || $i === 0 ? 0 : 1) . ' ' . $units[$i];
	}

	/**
	 * Import an uploaded backup archive.
	 *
	 * This is a straight port of what BackupController used to do inside the upload
	 * request. It moved for two reasons: a large archive outlives PHP-FPM's timeout,
	 * and nothing in that request could report progress — the browser was shown four
	 * hardcoded five-second messages ("Validating", "Unzipping", "Verifying",
	 * "Finalizing") that were wired to nothing at all.
	 *
	 * Extraction uses the `unzip` binary rather than PhpZip, matching the restore
	 * path: same two-password convention, and it can report per-file progress.
	 */
	private function processImportJob(array $job, string $zipPassword): void
	{
		require_once "/var/www/ringq/app/library/JobProgress.php";

		$progress = new \Library\JobProgress('import');
		$queued   = \Library\JobProgress::read('import');
		$jobId    = ($queued['state'] === 'queued' && isset($queued['jobId'])) ? $queued['jobId'] : null;
		$progress->begin([
			"name"      => isset($job['originalName']) ? pathinfo($job['originalName'], PATHINFO_FILENAME) : '',
			"fileName"  => isset($job['originalName']) ? $job['originalName'] : '',
			"sizeBytes" => isset($job['sizeBytes']) ? (int) $job['sizeBytes'] : null,
			"type"      => "Local Disk",
		], $jobId);

		$stagedZip  = isset($job['path']) ? $job['path'] : '';
		$domainUuid = isset($job['domain_uuid']) ? $job['domain_uuid'] : '';
		$extractDir = $stagedZip !== '' ? preg_replace('/\.zip$/', '', $stagedZip) . '_x' : '';
		$targetDir  = "/var/www/ringq/app/migrations";

		chdir('/tmp');

		try {
			if ($stagedZip === '' || !is_file($stagedZip)) {
				throw new \Exception("The uploaded archive is no longer on disk. Please upload it again.");
			}

			/* -------- stage: extract, trying both archive passwords -------- */
			$progress->phase('stage');
			if (!is_dir($extractDir) && !mkdir($extractDir, 0770, true) && !is_dir($extractDir)) {
				throw new \Exception("Could not create a working directory for the import.");
			}

			$passwords = [$zipPassword];
			if ($domainUuid !== '') {
				$passwords[] = $zipPassword . ':' . $domainUuid;
			}

			$total = 0;
			$list  = new Process(['sh', '-c', 'unzip -Z1 ' . escapeshellarg($stagedZip) . ' 2>/dev/null | wc -l']);
			$list->setTimeout(120);
			$list->run();
			if ($list->isSuccessful()) {
				$total = (int) trim($list->getOutput());
			}

			$extracted = false;
			foreach ($passwords as $pwd) {
				$done  = 0;
				$unzip = new Process(['unzip', '-P', $pwd, '-o', $stagedZip, '-d', $extractDir]);
				$unzip->setTimeout(null);
				$unzip->run(function ($type, $buffer) use (&$done, $total, $progress) {
					$done += preg_match_all('/^\s*(inflating|extracting|creating|linking):/m', $buffer);
					if ($total > 0) {
						$progress->count($done, $total, 'files');
					} else {
						$progress->fraction(null, number_format($done) . ' files');
					}
				});
				if ($unzip->getExitCode() <= 1) {
					$extracted = true;
					break;
				}
			}
			if (!$extracted) {
				// Same wording the old in-request path used, because it is the right
				// wording: the archive is not one of ours, or not from this tenant.
				throw new \Exception("Backup not match");
			}

			// The archive may hold everything at the root or inside a single directory.
			$items        = array_diff(scandir($extractDir), ['.', '..']);
			$processedDir = $extractDir;
			if (count($items) === 1) {
				$single = reset($items);
				if (is_dir($extractDir . '/' . $single)) {
					$processedDir = $extractDir . '/' . $single;
				}
			}

			/* -------- verify: metadata, server type, tenant, uniqueness -------- */
			$progress->phase('verify');
			$metadataPath       = "{$processedDir}/metadata.json";
			$metadataDate       = null;
			$metadataTimezone   = null;
			$metadataDomainUuid = null;
			$metadataDomainName = null;
			$metadataServerType = null;

			if (file_exists($metadataPath)) {
				$metadata = json_decode(file_get_contents($metadataPath), true);
				if (isset($metadata['datetime']))    { $metadataDate       = $metadata['datetime']; }
				if (isset($metadata['timezone']))    { $metadataTimezone   = $metadata['timezone']; }
				if (isset($metadata['domain_uuid'])) { $metadataDomainUuid = $metadata['domain_uuid']; }
				if (isset($metadata['domain_name'])) { $metadataDomainName = $metadata['domain_name']; }
				if (isset($metadata['server_type'])) { $metadataServerType = $metadata['server_type']; }
			}

			$localServerType = isset($job['serverType']) ? $job['serverType'] : $this->redis->get('install_mode');

			if (!$metadataServerType) {
				throw new \Exception("Backup metadata missing server type. Cannot verify compatibility.");
			}
			if ($localServerType && $metadataServerType !== $localServerType) {
				$typeLabels  = ['st' => 'Single Tenant', 'mt' => 'Multi Tenant'];
				$backupLabel = isset($typeLabels[$metadataServerType]) ? $typeLabels[$metadataServerType] : $metadataServerType;
				$localLabel  = isset($typeLabels[$localServerType]) ? $typeLabels[$localServerType] : $localServerType;
				throw new \Exception("Incompatible backup: {$backupLabel} backup cannot be imported to a {$localLabel} server.");
			}
			if ($localServerType === 'mt' && $metadataDomainName) {
				$localDomain     = VDomains::findFirst(["conditions" => "domain_enabled = 'true'", "limit" => 1]);
				$localDomainName = $localDomain ? $localDomain->domain_name : null;
				if ($localDomainName && $metadataDomainName !== $localDomainName) {
					throw new \Exception("Incompatible backup: Multi Tenant backup from '{$metadataDomainName}' cannot be imported to '{$localDomainName}'.");
				}
			}

			if (!$metadataDate) {
				$rdbGuess = glob("{$processedDir}/*.rdb");
				if (!empty($rdbGuess) && preg_match('/(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', basename($rdbGuess[0]), $m)) {
					$metadataDate = $m[1];
				}
			}
			if (!$metadataDate) {
				$metadataDate = date('Y-m-d_H-i-s');
			}

			$originalBaseName = pathinfo(isset($job['originalName']) ? $job['originalName'] : 'backup.zip', PATHINFO_FILENAME);
			$baseName         = $originalBaseName;
			if (preg_match('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})$/', $originalBaseName, $m)) {
				$baseName = substr($originalBaseName, 0, -strlen($m[0]));
			}

			foreach ($this->redis->lrange("backups", 0, -1) as $backupStr) {
				$existing = json_decode($backupStr, true);
				if (isset($existing['name']) && $existing['name'] === $baseName) {
					throw new \Exception("Backup with this name already exists ({$baseName}).");
				}
			}

			$rdbFiles  = glob("{$processedDir}/*.rdb");
			$dumpFiles = glob("{$processedDir}/*.dump");
			if (empty($rdbFiles) || empty($dumpFiles)) {
				throw new \Exception("Core backup files (dump/rdb) missing in import.");
			}

			/* -------- media_upload: recordings and voicemails go to S3 -------- */
			$progress->phase('media_upload');
			$includes = ["Database", "Redis"];
			// Unchanged from the in-request version, credential literal included. NOTE
			// for whoever reads this next: media uploaded here is REMOVED from the local
			// archive, so restoring an imported backup finds no media zips and clears the
			// server's recordings to match. That asymmetry predates this work and is
			// flagged in docs/PLAN-restore-progress.md; the restore now warns about it.
			$s3Config = $this->importS3Config;
			$ringqConfig = json_decode($this->redis->get("ringq_config"), true);
			$bucket      = isset($ringqConfig['data']['fqdn']) ? $ringqConfig['data']['fqdn'] : 'ringq-backups';
			$awsEnv      = [
				'AWS_ACCESS_KEY_ID'     => $s3Config['accessKey'],
				'AWS_SECRET_ACCESS_KEY' => $s3Config['secretKey'],
				'AWS_DEFAULT_REGION'    => $s3Config['region'],
			];

			$mediaFiles = [];
			foreach (["voicemails", "recordings"] as $mediaType) {
				foreach (glob("{$processedDir}/{$mediaType}@*.zip") as $mediaFile) {
					$mediaFiles[] = [$mediaType, $mediaFile];
				}
			}
			$mediaDone = 0;
			foreach ($mediaFiles as $entry) {
				list($mediaType, $mediaFile) = $entry;
				$progress->fraction(
					count($mediaFiles) > 0 ? ($mediaDone / count($mediaFiles)) : null,
					'Uploading ' . $mediaType . ' (' . $this->formatBytes(@filesize($mediaFile)) . ')'
				);
				$upload = new Process(['aws', 's3', 'cp', $mediaFile, "s3://{$bucket}/" . basename($mediaFile)]);
				$upload->setEnv($awsEnv);
				$upload->setTimeout(null);
				$upload->run(function ($type, $buffer) use ($progress) {
					$this->reportAwsProgress($progress, $buffer);
				});
				if ($upload->isSuccessful()) {
					$includes[] = ucfirst($mediaType);
					@unlink($mediaFile);
				} else {
					$progress->warn(ucfirst($mediaType) . ' could not be uploaded and are not part of this backup.');
				}
				$mediaDone++;
			}
			$includes = array_values(array_unique($includes));

			$imageZips = glob("{$processedDir}/images@*.zip");
			if (!empty($imageZips)) {
				$includes[] = "Images";
			}

			/* -------- repackage: core archive for local storage -------- */
			$progress->phase('repackage');
			$finalZipName = $baseName . "_" . $metadataDate . ".zip";
			$finalZipPath = $targetDir . "/" . $finalZipName;

			$filesToZip = [];
			foreach (array_merge($rdbFiles, $dumpFiles, glob("{$processedDir}/metadata.json"), $imageZips) as $coreFile) {
				$filesToZip[] = basename($coreFile);
			}

			$zipTotal = count($filesToZip);
			$zipDone  = 0;
			$zip      = new Process(array_merge(['zip', '-j', '-P', $zipPassword, $finalZipPath], $filesToZip));
			$zip->setWorkingDirectory($processedDir);
			$zip->setTimeout(1800);
			$zip->run(function ($type, $buffer) use (&$zipDone, $zipTotal, $progress) {
				$zipDone += preg_match_all('/^\s*(adding|updating):/m', $buffer);
				if ($zipTotal > 0) {
					$progress->count(min($zipDone, $zipTotal), $zipTotal, 'files');
				}
			});
			if (!$zip->isSuccessful()) {
				throw new \Exception("Failed to create local core backup: " . $zip->getErrorOutput());
			}

			chmod($finalZipPath, 0755);
			$fileSize = filesize($finalZipPath);

			/* -------- register: make it visible in the list -------- */
			$progress->phase('register');
			$this->redis->lpush("backups", json_encode([
				"id"                 => uniqid(),
				"name"               => $baseName,
				"datetime"           => $metadataDate,
				"timezone"           => $metadataTimezone,
				"size"               => $fileSize,
				"type"               => "Local Disk",
				"includes"           => $includes,
				"imported"           => true,
				"source_domain_uuid" => $metadataDomainUuid,
				"source_domain_name" => $metadataDomainName,
			]));

			$progress->complete([
				"name"      => $baseName,
				"datetime"  => $metadataDate,
				"sizeBytes" => $fileSize,
				"includes"  => $includes,
			]);
			echo date('[H:i:s]') . " Import complete: {$finalZipName}\n";

		} catch (\Throwable $e) {
			$progress->fail($this->importFailureCode($e), $e->getMessage());
			echo date('[H:i:s]') . " Import FAILED: " . $e->getMessage() . "\n";
			error_log('[import] failed: ' . $e->getMessage());
		} finally {
			// Staging is cleaned on every path — success, failure, and the reaper's
			// verdict — or a failed import leaves a multi-hundred-megabyte file behind
			// with nothing that will ever remove it.
			if ($extractDir !== '' && is_dir($extractDir)) {
				exec('rm -rf ' . escapeshellarg($extractDir));
			}
			if ($stagedZip !== '' && is_file($stagedZip)) {
				@unlink($stagedZip);
			}
			$this->redis->del("performImport");
		}
	}

	/**
	 * A callback that counts `zip` output lines. With a known member count it reports
	 * a fraction; with 0 it reports the running count, because a recursive zip of a
	 * media directory has no total worth computing up front.
	 */
	private function zipProgressCallback($progress, int $total): callable
	{
		$done = 0;

		return function ($type, $buffer) use (&$done, $total, $progress) {
			if (!$progress) {
				return;
			}
			$done += preg_match_all('/^\s*(adding|updating|deflating|storing):/m', $buffer);
			if ($total > 0) {
				$progress->count(min($done, $total), $total, 'files');
			} else {
				$progress->fraction(null, number_format($done) . ' files compressed');
			}
		};
	}

	/** Stable codes for backup failures, mirroring restoreFailureCode(). */
	private function backupFailureCode(\Throwable $e): string
	{
		$message = strtolower($e->getMessage());
		$map = [
			'redis rdb not found'  => 'redis_snapshot_failed',
			'pg_dump'              => 'database_dump_failed',
			'no core files'        => 'nothing_to_back_up',
			'no space left'        => 'disk_full',
		];
		foreach ($map as $needle => $code) {
			if (strpos($message, $needle) !== false) {
				return $code;
			}
		}

		return 'backup_failed';
	}

	/** Stable codes for import failures, mirroring restoreFailureCode(). */
	private function importFailureCode(\Throwable $e): string
	{
		$message = strtolower($e->getMessage());
		$map = [
			'backup not match'          => 'archive_unreadable',
			'no longer on disk'         => 'upload_lost',
			'missing server type'       => 'metadata_incomplete',
			'incompatible backup'       => 'incompatible_backup',
			'already exists'            => 'duplicate_name',
			'core backup files'         => 'archive_incomplete',
			'failed to create local'    => 'repackage_failed',
		];
		foreach ($map as $needle => $code) {
			if (strpos($message, $needle) !== false) {
				return $code;
			}
		}

		return 'import_failed';
	}

	public static function pgRestoreOnlyAlreadyExists(string $stderr): ?array {
		$errors = preg_split('/\R/', $stderr);
		$names  = [];
		$count  = 0;
		foreach ($errors as $line) {
			if (stripos($line, 'pg_restore: error:') === false) {
				continue;
			}
			$count++;
			if (!preg_match('/ERROR:\s+\S+\s+"([^"]+)"\s+already exists/', $line, $m)) {
				return null;
			}
			$names[$m[1]] = true;
		}
		return $count > 0 ? array_keys($names) : null;
	}

	private function restoreCorePostgres(string $dumpFile, bool $skipRemap, ?string $sourceDomainName, ?string $currentDomainName, $progress = null): array {
		$isCrossFqdn      = false;
		$domainMap        = [];
		$sourceDomainUuid = null;
		$savedDomains     = [];

		if (!$skipRemap) {
			$savedDomains = $this->getDI()->get('db')->fetchAll(
				"SELECT * FROM v_domains",
				\Phalcon\Db\Enum::FETCH_ASSOC
			);
		}

		// Wipe the schema with CASCADE so views dependent on tables (e.g. view_xml_cdr)
		// don't block the DROP TABLE statements that pg_restore --clean would emit.
		$wipeSchema = new Process([
			'psql',
			'-h', $this->config->database['host'],
			'-U', $this->config->database['username'],
			'-d', $this->config->database['dbname'],
			'-c', 'DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO PUBLIC;',
		]);
		$wipeSchema->setEnv(['PGPASSWORD' => $this->config->database['password']]);
		if ($progress) { $progress->fraction(null, 'Clearing the existing schema'); }
		$wipeSchema->mustRun();

		// How many objects the dump contains. `pg_restore -l` reads only the archive's
		// table of contents, so it is cheap, and every TOC entry line begins with the
		// item number. If the listing fails the phase reports null rather than a made-up
		// number — an invented percentage is worse than an honest "working on it".
		$totalItems = 0;
		if ($progress) {
			$toc = new Process(['pg_restore', '-l', $dumpFile]);
			$toc->setTimeout(120);
			$toc->run();
			if ($toc->isSuccessful()) {
				$totalItems = preg_match_all('/^\d+;/m', $toc->getOutput());
			}
		}

		$pgRestore = new Process([
			'pg_restore',
			'-h', $this->config->database['host'],
			'-p', 5432,
			'-U', $this->config->database['username'],
			'-d', $this->config->database['dbname'],
			// -v makes pg_restore name each item as it processes it, on stderr, which is
			// the only progress signal it offers. The wording differs between major
			// versions (this fleet spans 13 to 18), so the parse matches the verbs rather
			// than a fixed sentence.
			'-v',
			$dumpFile,
		]);
		$pgRestore->setEnv(['PGPASSWORD' => $this->config->database['password']]);
		$pgRestore->setTimeout(null);
		echo date('[H:i:s]') . " Restoring Database...\n";
		$restoredItems = 0;
		$pgRestore->run(function ($type, $buffer) use (&$restoredItems, $totalItems, $progress) {
			if (!$progress) {
				return;
			}
			$restoredItems += preg_match_all('/^pg_restore: (processing|creating|restoring)\b/m', $buffer);
			if ($totalItems > 0) {
				$progress->count(min($restoredItems, $totalItems), $totalItems, 'objects');
			} else {
				$progress->fraction(null, number_format($restoredItems) . ' objects');
			}
		});
		if (!$pgRestore->isSuccessful()) {
			$alreadyExists = self::pgRestoreOnlyAlreadyExists($pgRestore->getErrorOutput());
			if ($alreadyExists === null) {
				throw new ProcessFailedException($pgRestore);
			}
			echo date('[H:i:s]') . " pg_restore skipped objects that already existed: " . implode(', ', $alreadyExists) . "\n";
			if ($progress) {
				$progress->warn('Some database objects already existed and were kept as they were: ' . implode(', ', $alreadyExists) . '.');
			}
		}
		echo date('[H:i:s]') . " Database restored.\n";

		if (!$skipRemap) {
			if (!$sourceDomainName) {
				$restoredDomain = VDomains::findFirst(["conditions" => "domain_enabled = 'true'", "limit" => 1]);
				if ($restoredDomain) {
					$sourceDomainUuid = $restoredDomain->domain_uuid;
					$sourceDomainName = $restoredDomain->domain_name;
				}
			}
			$isCrossFqdn = ($sourceDomainName && $currentDomainName && $sourceDomainName !== $currentDomainName);
			if ($isCrossFqdn) {
				// Reported as detail inside the database phase, deliberately NOT as the
				// 'remap' phase: that one runs later, and moving the phase backwards would
				// make the label jump about even though percent is clamped.
				if ($progress) { $progress->fraction(null, 'Adapting the database to this server'); }
				$domainMap = $this->remapDatabase($currentDomainName, $savedDomains);
			}
		}

		return compact('isCrossFqdn', 'domainMap', 'sourceDomainUuid', 'sourceDomainName');
	}

	/**
	 * Restore Redis RDB file: stop service, overwrite dump, restart, reconnect.
	 * Applies cross-FQDN key remap when needed.
	 */
	private function restoreCoreRedis(string $rdbFile, bool $skipRemap, bool $isCrossFqdn, array $domainMap, $progress = null): void {
		$dirConfig  = $this->redis->executeRaw(['CONFIG', 'GET', 'dir']);
		$fileConfig = $this->redis->executeRaw(['CONFIG', 'GET', 'dbfilename']);
		$redisDest  = rtrim($dirConfig[1], '/') . '/' . $fileConfig[1];

		echo date('[H:i:s]') . " Restoring Redis dump...\n";
		// Snapshot the current RDB so we can roll back if the new one won't load
		// (e.g. backup was made on a server with extra Redis modules installed).
		$rollbackPath = $redisDest . '.pre_restore_' . time();
		if (file_exists($redisDest)) {
			(new Process(['sudo', 'cp', $redisDest, $rollbackPath]))->mustRun();
		}

		if ($progress) { $progress->fraction(0.25, 'Stopping Redis'); }
		(new Process(['sudo', 'systemctl', 'stop', 'redis']))->mustRun();
		if ($progress) { $progress->fraction(0.5, 'Copying the settings snapshot'); }
		(new Process(['sudo', 'cp', $rdbFile, $redisDest]))->setTimeout(null)->mustRun();
		(new Process(['sudo', 'chown', 'redis:redis', $redisDest]))->mustRun();

		if ($progress) { $progress->fraction(0.8, 'Starting Redis'); }
		$start = new Process(['sudo', 'systemctl', 'start', 'redis']);
		$start->run();
		if (!$start->isSuccessful()) {
			// Capture the real load error (module mismatch, RDB corruption, etc.) by
			// grepping for the meaningful lines, not just blind tail. tail alone often
			// shows only the prior shutdown messages because systemd retries 5 times
			// in <1s and not every attempt logs in time.
			$logCmd = "sudo tail -100 /var/log/redis/redis-server.log 2>/dev/null "
				. "| grep -E 'module|RDB|Loading|Wrong|Bad|ERR|Can.t|Failed|Error' "
				. "| tail -10";
			$logSnippet = @shell_exec($logCmd) ?: @shell_exec('sudo tail -20 /var/log/redis/redis-server.log 2>/dev/null') ?: '';

			echo date('[H:i:s]') . " Redis failed to start with restored RDB. Rolling back.\n";
			if (file_exists($rollbackPath)) {
				(new Process(['sudo', 'systemctl', 'stop', 'redis']))->run();
				(new Process(['sudo', 'cp', $rollbackPath, $redisDest]))->mustRun();
				(new Process(['sudo', 'chown', 'redis:redis', $redisDest]))->mustRun();
				(new Process(['sudo', 'systemctl', 'reset-failed', 'redis']))->run();
				(new Process(['sudo', 'systemctl', 'start', 'redis']))->run();
				// Reconnect so the caller's finally block can still preserve
				// ringq_config / install_mode / backups on the recovered Redis.
				$this->redis = new \Predis\Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379]);
				(new Process(['sudo', 'rm', '-f', $rollbackPath]))->run();
			}
			throw new \Exception(
				"Redis restore failed — RDB incompatible with this server. "
				. "Common cause: backup source had a Redis module (e.g. RediSearch) "
				. "that this server does not have loaded. Redis was rolled back to "
				. "pre-restore state. Redis log:\n" . $logSnippet
			);
		}
		if (file_exists($rollbackPath)) {
			(new Process(['sudo', 'rm', '-f', $rollbackPath]))->run();
		}

		$this->redis = new \Predis\Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379]);

		if ($skipRemap || !$isCrossFqdn) {
			return;
		}
		foreach ($domainMap as $srcUuid => $map) {
			$this->remapRedisKeys($this->redis, $map['source_name'], $map['target_name'], $srcUuid, $map['target_uuid']);
		}
	}

	/**
	 * Restore voicemail and recording media zips found in $tmpRestoreDir.
	 * Uses glob so both exact-name and wildcard patterns are handled uniformly.
	 */
	private function restoreCoreMedia(string $tmpRestoreDir, callable $tryUnzip, $progress = null): void
	{
		// If the PHP process's CWD is one of the directories we are about to rm -rf and
		// recreate, Symfony Process will call getcwd() after deletion and get "" (empty
		// string), causing "The provided cwd '' does not exist" errors.  Changing to /tmp
		// first ensures we always have a valid working directory throughout this method.
		chdir('/tmp');

		$mediaMap = [
			'voicemails@' => "/var/lib/freeswitch/storage/voicemail/default",
			'recordings@' => "/var/lib/freeswitch/recordings",
		];

		if ($progress) { $progress->phase('media'); }
		foreach ($mediaMap as $prefix => $dest) {
			try {
				$matches = glob("{$tmpRestoreDir}/{$prefix}*.zip");
				if (empty($matches)) {
					// Backup did not include this media type — clear the destination so the
					// restored state accurately reflects the source (no stale local files).
					if (is_dir($dest)) {
						$this->clearDirectory($dest);
						$this->ensureAppDirectory($dest, 0770);
						// Deliberate: the restored state mirrors the source. But it deletes
						// live media, so the operator has to be TOLD — silently emptying the
						// recordings directory is indistinguishable from losing them, and an
						// imported backup reaches here routinely because import moves media
						// out to S3 while advertising "Recordings" on the card.
						if ($progress) {
							$progress->warn('This backup contains no ' . rtrim($prefix, '@')
								. ' — the existing ones on this server were removed to match it.');
						}
						echo date('[H:i:s]') . " No {$prefix}*.zip in backup — cleared existing: {$dest}\n";
					} else {
						$this->ensureAppDirectory($dest, 0770);
						echo date('[H:i:s]') . " No {$prefix}*.zip in backup and destination absent, created it empty.\n";
					}
					continue;
				}
				$this->clearDirectory($dest);
				$this->ensureAppDirectory($dest, 0770);
				echo date('[H:i:s]') . " Restoring media: {$prefix} -> {$dest}...\n";
				$tryUnzip($matches[0], $dest, null);
				$this->ensureAppDirectory($dest, 0770);
				echo date('[H:i:s]') . " Media restored.\n";
			} catch (\Exception $e) {
				// This failure used to be swallowed entirely: a restore could finish
				// without anyone's voicemails and report unqualified success. It is a
				// warning, not a failure — the restore itself did work — but the operator
				// has to be told, so it now reaches the UI.
				if ($progress) { $progress->warn(rtrim($prefix, '@') . ' could not be restored: ' . $e->getMessage()); }
				echo date('[H:i:s]') . " Warning: media restore failed for {$prefix}: " . $e->getMessage() . "\n";
			}
		}

		// Images: single zip with absolute-relative paths (e.g. "var/www/ringq/public/avr/...").
		// Always clear the known image dirs so the restored state mirrors the source.
		$imageDirs = $this->backupImageDirs();
		$imageZip  = glob("{$tmpRestoreDir}/images@*.zip");
		if ($progress) { $progress->phase('images'); }
		try {
			foreach ($imageDirs as $d) {
				$this->clearDirectory($d);
			}
			if (!empty($imageZip)) {
				echo date('[H:i:s]') . " Restoring images -> " . implode(', ', $imageDirs) . "\n";
				$tryUnzip($imageZip[0], '/', null);
				echo date('[H:i:s]') . " Images restored.\n";
			} else {
				echo date('[H:i:s]') . " No images@*.zip in backup — cleared image dirs.\n";
			}
			foreach ($imageDirs as $d) {
				$this->ensureAppDirectory($d, 0775);
			}
		} catch (\Exception $e) {
			if ($progress) { $progress->warn('Images could not be restored: ' . $e->getMessage()); }
			echo date('[H:i:s]') . " Warning: image restore failed: " . $e->getMessage() . "\n";
		}
	}

	private function clearDirectory(string $dir): void {
		if (is_dir($dir)) {
			exec('find ' . escapeshellarg($dir) . ' -mindepth 1 -delete');
		}
	}

	private function ensureAppDirectory(string $dir, int $mode): void {
		if (!is_dir($dir)) {
			mkdir($dir, $mode, true);
		}
		exec('chown -R www-data:www-data ' . escapeshellarg($dir));
		chmod($dir, $mode);
	}

	private function remapDatabase(string $targetDomainName, array $savedDomains): array {
		$db = $this->getDI()->get('db');

		echo date('[H:i:s]') . " Cross-FQDN: remapping database domain...\n";

		// Tables to exclude from domain_uuid/domain_name remapping
		$excludedTables = [
			'v_domains', 'v_call_broadcasts', 'v_conference_room_users',
			'v_conference_rooms', 'v_database_transactions', 'v_dialplan_details',
			'v_dialplans', 'v_email_queue', 'v_email_templates',
			'v_group_permissions', 'v_groups', 'view_groups',
		];

		$db->begin();

		try {
			// 1. Get instance A's domains (currently in DB after pg_restore)
			$restoredDomains = $db->fetchAll("SELECT * FROM v_domains", \Phalcon\Db\Enum::FETCH_ASSOC);

			$savedUuids    = array_column($savedDomains, 'domain_uuid');
			$restoredUuids = array_column($restoredDomains, 'domain_uuid');

			// Domains only in instance A (need remapping)
			$sourceOnly = array_filter($restoredDomains, function ($d) use ($savedUuids) {
				return !in_array($d['domain_uuid'], $savedUuids);
			});
			// Domains only in instance B (remap targets)
			$targetOnly = array_filter($savedDomains, function ($d) use ($restoredUuids) {
				return !in_array($d['domain_uuid'], $restoredUuids);
			});

			$isFqdn = function ($name) {
				return $name && !filter_var($name, FILTER_VALIDATE_IP);
			};

			// Separate by type: FQDN vs IP
			$sourceFqdn = array_values(array_filter($sourceOnly, function ($d) use ($isFqdn) { return $isFqdn($d['domain_name']); }));
			$sourceIp   = array_values(array_filter($sourceOnly, function ($d) use ($isFqdn) { return !$isFqdn($d['domain_name']); }));
			$targetFqdn = array_values(array_filter($targetOnly, function ($d) use ($isFqdn) { return $isFqdn($d['domain_name']); }));
			$targetIp   = array_values(array_filter($targetOnly, function ($d) use ($isFqdn) { return !$isFqdn($d['domain_name']); }));

			// Build mappings: source_uuid => [target_uuid, source_name, target_name]
			$domainMap = [];

			// Match FQDN domains
			$count = min(count($sourceFqdn), count($targetFqdn));
			for ($i = 0; $i < $count; $i++) {
				$domainMap[$sourceFqdn[$i]['domain_uuid']] = [
					'target_uuid' => $targetFqdn[$i]['domain_uuid'],
					'source_name' => $sourceFqdn[$i]['domain_name'],
					'target_name' => $targetFqdn[$i]['domain_name'],
				];
				echo date('[H:i:s]') . "   Map FQDN: {$sourceFqdn[$i]['domain_name']} ({$sourceFqdn[$i]['domain_uuid']}) -> {$targetFqdn[$i]['domain_name']} ({$targetFqdn[$i]['domain_uuid']})\n";
			}

			// Match IP domains
			$count = min(count($sourceIp), count($targetIp));
			for ($i = 0; $i < $count; $i++) {
				$domainMap[$sourceIp[$i]['domain_uuid']] = [
					'target_uuid' => $targetIp[$i]['domain_uuid'],
					'source_name' => $sourceIp[$i]['domain_name'],
					'target_name' => $targetIp[$i]['domain_name'],
				];
				echo date('[H:i:s]') . "   Map IP: {$sourceIp[$i]['domain_name']} ({$sourceIp[$i]['domain_uuid']}) -> {$targetIp[$i]['domain_name']} ({$targetIp[$i]['domain_uuid']})\n";
			}

			if (empty($domainMap)) {
				echo date('[H:i:s]') . " Cross-FQDN: no domain mappings found, skipping remap.\n";
				$db->rollback();
				return [];
			}

			// 2. Find tables with domain_uuid and/or domain_name columns (base tables only, excluding list)
			$excludePlaceholders = implode(',', array_fill(0, count($excludedTables), '?'));
			$tablesWithColumns = $db->fetchAll(
				"SELECT c.table_name, c.column_name
				 FROM information_schema.columns c
				 JOIN information_schema.tables t ON c.table_name = t.table_name AND c.table_schema = t.table_schema
				 WHERE c.column_name IN ('domain_uuid', 'domain_name')
				   AND c.table_schema = 'public'
				   AND t.table_type = 'BASE TABLE'
				   AND c.table_name NOT IN ({$excludePlaceholders})",
				\Phalcon\Db\Enum::FETCH_ASSOC,
				$excludedTables
			);

			// Group columns by table
			$tableColumns = [];
			foreach ($tablesWithColumns as $row) {
				$tableColumns[$row['table_name']][] = $row['column_name'];
			}

			// 3. Remap domain_uuid and domain_name in all eligible tables
			foreach ($domainMap as $srcUuid => $map) {
				foreach ($tableColumns as $table => $columns) {
					$hasDomainUuid = in_array('domain_uuid', $columns);
					$hasDomainName = in_array('domain_name', $columns);

					if ($hasDomainUuid && $hasDomainName) {
						$db->execute(
							"UPDATE \"{$table}\" SET domain_uuid = :target_uuid, domain_name = :target_name WHERE domain_uuid = :source_uuid AND domain_name = :source_name",
							['target_uuid' => $map['target_uuid'], 'target_name' => $map['target_name'], 'source_uuid' => $srcUuid, 'source_name' => $map['source_name']]
						);
					} elseif ($hasDomainUuid) {
						$db->execute(
							"UPDATE \"{$table}\" SET domain_uuid = :target_uuid WHERE domain_uuid = :source_uuid",
							['target_uuid' => $map['target_uuid'], 'source_uuid' => $srcUuid]
						);
					} elseif ($hasDomainName) {
						$db->execute(
							"UPDATE \"{$table}\" SET domain_name = :target_name WHERE domain_name = :source_name",
							['target_name' => $map['target_name'], 'source_name' => $map['source_name']]
						);
					}
				}
			}

			// 4. Restore v_domains to instance B's original data
			$db->execute("DELETE FROM v_domains");
			foreach ($savedDomains as $domain) {
				$db->execute(
					"INSERT INTO v_domains (domain_uuid, domain_parent_uuid, domain_name, domain_enabled, domain_description, insert_date, insert_user, update_date, update_user) VALUES (:domain_uuid, :domain_parent_uuid, :domain_name, :domain_enabled, :domain_description, :insert_date, :insert_user, :update_date, :update_user)",
					[
						'domain_uuid'        => $domain['domain_uuid'],
						'domain_parent_uuid' => $domain['domain_parent_uuid'],
						'domain_name'        => $domain['domain_name'],
						'domain_enabled'     => $domain['domain_enabled'],
						'domain_description' => $domain['domain_description'],
						'insert_date'        => $domain['insert_date'],
						'insert_user'        => $domain['insert_user'],
						'update_date'        => $domain['update_date'],
						'update_user'        => $domain['update_user'],
					]
				);
			}

			// 5. Update FQDN text columns in FreeSWITCH tables (accountcode, user_context, ivr_menu_context)
			foreach ($domainMap as $srcUuid => $map) {
				$srcName = $map['source_name'];
				$tgtName = $map['target_name'];
				$db->execute(
					"UPDATE v_extensions SET accountcode = :target_name, user_context = :target_name WHERE accountcode = :source_name",
					['target_name' => $tgtName, 'source_name' => $srcName]
				);
				$db->execute(
					"UPDATE v_ivr_menus SET ivr_menu_context = :target_name WHERE ivr_menu_context = :source_name",
					['target_name' => $tgtName, 'source_name' => $srcName]
				);
			}

			// 6. Update SIP profile settings with instance B's actual IPs
			$publicIp = trim(shell_exec("curl -4 -s ifconfig.me 2>/dev/null") ?: '');
			$localIp  = trim(shell_exec("hostname -I | awk '{print \$1}'"));

			if ($publicIp && filter_var($publicIp, FILTER_VALIDATE_IP)) {
				$db->execute(
					"UPDATE v_sip_profile_settings SET sip_profile_setting_value = :ip WHERE sip_profile_setting_name IN ('ext-rtp-ip', 'ext-sip-ip')",
					['ip' => $publicIp]
				);
				echo date('[H:i:s]') . "   SIP ext IPs set to: {$publicIp}\n";
			}
			if ($localIp && filter_var($localIp, FILTER_VALIDATE_IP)) {
				$db->execute(
					"UPDATE v_sip_profile_settings SET sip_profile_setting_value = :ip WHERE sip_profile_setting_name IN ('rtp-ip', 'sip-ip') AND sip_profile_setting_value NOT LIKE '\$%'",
					['ip' => $localIp]
				);
				echo date('[H:i:s]') . "   SIP local IPs set to: {$localIp}\n";
			}

			// 7. Disable IPv6 SIP profiles (they bind to wrong IPs after cross-FQDN restore)
			$db->execute("UPDATE v_sip_profiles SET sip_profile_enabled = 'false' WHERE sip_profile_name IN ('internal-ipv6', 'external-ipv6')");
			echo date('[H:i:s]') . "   Disabled IPv6 SIP profiles.\n";

			// Clear sofia config cache so FreeSWITCH picks up new IPs
			@array_map('unlink', glob('/var/cache/ringq/configuration.sofia.conf*'));

			$db->commit();
			echo date('[H:i:s]') . " Cross-FQDN: database remapping complete.\n";
			return $domainMap;
		} catch (\Exception $e) {
			$db->rollback();
			echo date('[H:i:s]') . " Cross-FQDN: database remapping FAILED: " . $e->getMessage() . "\n";
			throw $e;
		}
	}

	/**
	 * Remap Redis keys that contain source domain names after a cross-FQDN restore.
	 * Supports multiple domain name pairs (FQDN + IP).
	 */
	private function remapRedisKeys($redis, string $sourceDomainName, string $targetDomainName, string $sourceDomainUuid = '', string $targetDomainUuid = ''): void {
		echo date('[H:i:s]') . " Cross-FQDN: remapping Redis keys...\n";

		$remapCount = 0;

		// --- Remap keys by domain name ---
		echo date('[H:i:s]') . "   Redis remap name: {$sourceDomainName} -> {$targetDomainName}\n";

		$namePatterns = [
			"{$sourceDomainName}",
			"{$sourceDomainName}*",
			"*:{$sourceDomainName}",
			"*:{$sourceDomainName}:*",
			"*:{$sourceDomainName}-*",
			"*+{$sourceDomainName}",
			"*+{$sourceDomainName}-*",
			"*-{$sourceDomainName}",
			"*-{$sourceDomainName}-*",
			"*-{$sourceDomainName}:*",
			"*@{$sourceDomainName}",
			"*@{$sourceDomainName}:*",
			"*@{$sourceDomainName}-*",
			"telegramconfig:fqdn:{$sourceDomainName}",
			"lineconfig:fqdn:{$sourceDomainName}",
			"whatsappconfig:fqdn:{$sourceDomainName}",
			"facebookconfig:fqdn:{$sourceDomainName}",
			"queueCallcenter-{$sourceDomainName}",
			"queueCallBoard-{$sourceDomainName}",
		];

		foreach ($namePatterns as $pattern) {
			$keys = $redis->keys($pattern);
			foreach ($keys as $oldKey) {
				$newKey = str_replace($sourceDomainName, $targetDomainName, $oldKey);
				if ($oldKey !== $newKey) {
					$ttl = $redis->ttl($oldKey);
					$redis->rename($oldKey, $newKey);
					if ($ttl > 0) {
						$redis->expire($newKey, $ttl);
					}
					$remapCount++;
				}
			}
		}

		// Remap domain name inside Redis values (whatsappconfig cache)
		$whatsappKeys = $redis->keys('whatsappconfig:cache:number:*');
		foreach ($whatsappKeys as $key) {
			$value = $redis->get($key);
			if ($value && strpos($value, $sourceDomainName) !== false) {
				$newValue = str_replace($sourceDomainName, $targetDomainName, $value);
				$ttl = $redis->ttl($key);
				$redis->set($key, $newValue);
				if ($ttl > 0) {
					$redis->expire($key, $ttl);
				}
				$remapCount++;
			}
		}

		// --- Remap keys by domain UUID ---
		if ($sourceDomainUuid && $targetDomainUuid && $sourceDomainUuid !== $targetDomainUuid) {
			echo date('[H:i:s]') . "   Redis remap UUID: {$sourceDomainUuid} -> {$targetDomainUuid}\n";

			// Rename keys containing source UUID
			$uuidKeys = $redis->keys("*{$sourceDomainUuid}*");
			foreach ($uuidKeys as $oldKey) {
				$newKey = str_replace($sourceDomainUuid, $targetDomainUuid, $oldKey);
				if ($oldKey !== $newKey) {
					$ttl = $redis->ttl($oldKey);
					$redis->rename($oldKey, $newKey);
					if ($ttl > 0) {
						$redis->expire($newKey, $ttl);
					}
					$remapCount++;
				}
			}

			// Replace UUID inside string values
			$allKeys = $redis->keys('*');
			foreach ($allKeys as $key) {
				$type = $redis->type($key);
				if ($type === 'string') {
					$value = $redis->get($key);
					if ($value && strpos($value, $sourceDomainUuid) !== false) {
						$newValue = str_replace($sourceDomainUuid, $targetDomainUuid, $value);
						$ttl = $redis->ttl($key);
						$redis->set($key, $newValue);
						if ($ttl > 0) {
							$redis->expire($key, $ttl);
						}
						$remapCount++;
					}
				}
			}
		}

		echo date('[H:i:s]') . " Cross-FQDN: Redis remapping complete. {$remapCount} keys remapped.\n";
	}

	/**
	 * Safely rename media directories from source FQDN to target FQDN after cross-FQDN restore.
	 */
	private function remapMediaDirectories(string $sourceDomainName, string $targetDomainName): void {
		echo date('[H:i:s]') . " Cross-FQDN: remapping media directories...\n";

		$mediaDirs = [
			"/var/lib/freeswitch/storage/voicemail/default/{$sourceDomainName}" => "/var/lib/freeswitch/storage/voicemail/default/{$targetDomainName}",
			"/var/lib/freeswitch/recordings/{$sourceDomainName}" => "/var/lib/freeswitch/recordings/{$targetDomainName}",
		];

		foreach ($mediaDirs as $oldDir => $newDir) {
			if (is_dir($oldDir)) {
				if (is_dir($newDir)) {
					// Safe merge: copy without overwriting, then remove old
					exec('cp -rn ' . escapeshellarg($oldDir) . '/. ' . escapeshellarg($newDir) . '/');
					exec('rm -rf ' . escapeshellarg($oldDir));
					echo date('[H:i:s]') . "   Merged {$oldDir} into {$newDir}\n";
				} else {
					rename($oldDir, $newDir);
					echo date('[H:i:s]') . "   Renamed {$oldDir} -> {$newDir}\n";
				}
			}
		}
	}
}
