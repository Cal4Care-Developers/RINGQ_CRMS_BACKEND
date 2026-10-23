<?php
declare(strict_types=1);

use Phalcon\Mvc\View;
use Phalcon\Db\Column;
// Both of these were used unqualified with no import, which in a file with no
// namespace resolves to \Process and \ZipFile — neither of which exists. Every
// sibling controller imports Process properly; this one lost it. The deployed build
// is older than the repository copy, so the breakage has never actually run, but
// any host taking this file as plaintext would fatal on the first import.
use Symfony\Component\Process\Process;
use PhpZip\ZipFile;

require_once __DIR__ . '/../library/JobProgress.php';

class BackupController extends ControllerBase {

    public function backupAction($type = 6, $page = 1) {
		$this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json"); 

        $session = $this->session->get("auth");
        if (!$session) {
			return $this->response->setJsonContent([
				"result" => "error",
				"message" => "Unathorized request. (Code AL1000)",
			]);
		}
		// ANSWERED BEFORE THE COMPANY LOOKUP BELOW, DELIBERATELY.
		//
		// A restore runs `DROP SCHEMA public CASCADE` and only then loads the dump, so
		// for the whole database phase `q_companies` does not exist and that lookup
		// fails -- every endpoint in this controller answers "Unathorized (AL1000)"
		// while the restore is running perfectly well. The client read that as the
		// end-of-restore sign-out and painted "Restore complete" over a restore that
		// was at step 2 of 8. A status endpoint that cannot answer during the very
		// operation it reports on is worse than no status endpoint at all.
		//
		// This is not a widening of access: it still requires an authenticated
		// session, the documents it returns are server-wide rather than tenant-scoped
		// (as is every other branch here), and it reads three files on disk -- no
		// database, no Redis, no storage backend.
		// Live job progress for the Time Machine modal. One request answers for both
		// long jobs, so the modal polls once rather than twice, and it never touches
		// Redis or the storage backends — it is cheap enough to poll every 2s.
		//
		// Type 15, NOT 14: 14 already loads the backup settings, and putting this
		// branch above it shadowed that endpoint completely — the settings came back
		// with no `content`, so the schedule, storage config and stats silently fell
		// back to defaults and the Create Backup button stayed disabled forever.
		if ($type == 15) {
			return $this->response->setJsonContent([
				"result"  => "success",
				"restore" => \Library\JobProgress::read('restore'),
				"import"  => \Library\JobProgress::read('import'),
				"backup"  => \Library\JobProgress::read('backup'),
				"message" => "Job progress."
			]);
		}
		$qCompany = QCompanies::findFirst([
			"conditions" => "domain_uuid = :domain_uuid:",
			"bind" => [
				"domain_uuid" => $session["domain_uuid"],
			],
			"bindTypes" => [Column::BIND_PARAM_STR],
		]);
		if (!$qCompany) { 
			return $this->response->setJsonContent([
				"result" => "error",
				"message" => "Unathorized request. (Code AL1000)",
			]);
		}
		$ringqConfig = json_decode($this->redis->get('ringq_config'), true);
		$uuid = $ringqConfig['data']['uuid'];
		$accessToken = $ringqConfig['data']['access_token'];
		$licenseKey = $ringqConfig['data']['licenseKey'];
		$userId = $ringqConfig['data']['user_id'];

		$ringqApi = function ($type) use ($uuid, $accessToken, $licenseKey, $userId) { 
			$ch = curl_init("https://licensekey.ringq.com/backup/backupdata");
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				"Content-Type: application/json",
				"UUID: " . $uuid,
				"Authorization-token: " . $accessToken
			]);

			$payload = json_encode([
				"licenseKey" => $licenseKey,
				"type" => $type,
				"userId" => $userId
			]);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

			$response = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);

			if ($httpCode == 200 && $response) {
				return json_decode($response, true); 
			}
		};

		// Get backup
		if ($type == 6) {
			$performBackup = $this->redis->get("performBackup");
			$performRestore = $this->redis->get("performRestore");
			// The list view and the progress view must agree about whether a restore is
			// running. They cannot if one reads a Redis key the restore itself deletes
			// half way through, so the file is the authority and the key only a hint.
			$restoreDoc    = \Library\JobProgress::read('restore');
			$restoreActive = in_array($restoreDoc['state'], ['queued', 'running'], true);
			$backups = $this->redis->lrange("backups", 0, -1);
			$processedBackups = [];
			$settingsJson = $this->redis->get("backup_settings");
			$settings = $settingsJson ? json_decode($settingsJson, true) : [];
			$awsCreds = $settings['storageConfig']['AWS S3'] ?? null;
			$ringqConfig = json_decode($this->redis->get("ringq_config"), true);
			$bucket = $awsCreds['storagePath'] ?? ($ringqConfig['data']['fqdn'] ?? 'ringq-backups');
			$awsEnv = $awsCreds ? [
				'AWS_ACCESS_KEY_ID'     => $awsCreds['accessKey'],
				'AWS_SECRET_ACCESS_KEY' => $awsCreds['secretKey'],
				'AWS_DEFAULT_REGION'    => $awsCreds['region'] ?? 'ap-southeast-1',
			] : [];

			foreach ($backups as $backupIndex => $backupStr) {
				$backup = json_decode($backupStr, true);
				$backup['ready'] = false;
				if (isset($backup['datetime'])) { 
					if (($backup['type'] ?? 'Local Disk') === 'Local Disk') {
						$backup['ready'] = file_exists("/var/www/ringq/app/migrations/{$backup['name']}_{$backup['datetime']}.zip");
					} 
					elseif ($backup['type'] === 'AWS S3' && !empty($awsEnv)) {
						$objectKey = "{$backup['name']}_{$backup['datetime']}.zip";
						$s3Check = new Process(['aws', 's3api', 'head-object', '--bucket', $bucket, '--key', $objectKey]);
						$s3Check->setEnv($awsEnv);
						$s3Check->run();
						$backup['ready'] = $s3Check->isSuccessful();
					} 
					elseif ($backup['type'] === 'FTP') { 
						$ftpConfig = $settings['storageConfig']['FTP'] ?? null;
						if ($ftpConfig) {
							$host = $ftpConfig['host'];
							$port = $ftpConfig['port'] ?? 21;
							$user = $ftpConfig['username'];
							$pass = $ftpConfig['password'];
							$path = rtrim($ftpConfig['path'] ?? '', '/');
							$fileName = "{$backup['name']}_{$backup['datetime']}.zip";
							$remoteFile = $path ? "$path/$fileName" : $fileName;

							$url = "ftp://$host:$port/" . ltrim($remoteFile, '/');
							$curl = curl_init($url);
							curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass");
							curl_setopt($curl, CURLOPT_NOBODY, true);
							curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
							curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
							curl_setopt($curl, CURLOPT_FTP_USE_EPSV, 0); // Disable EPSV to force PASV
							curl_exec($curl); 
							$info = curl_getinfo($curl);
							$err = curl_error($curl);
							// Some servers return 213 (Size), 250 (File Action OK), or 350 (Pending further information)
							$backup['ready'] = ($info['http_code'] == 200 || $info['http_code'] == 213 || $info['http_code'] == 250 || $info['http_code'] == 350);
							
							// Debug logging
							$logMsg = date('[Y-m-d H:i:s]') . " FTP Check: $url | Ready: " . ($backup['ready'] ? 'YES' : 'NO') . " | HTTP: " . $info['http_code'] . " | Error: $err\n";
							file_put_contents('/tmp/ftp_debug.log', $logMsg, FILE_APPEND);
							
							curl_close($curl);
						}
					} 
					elseif ($backup['type'] === 'SFTP') {
						$sftpConfig = $settings['storageConfig']['SFTP'] ?? null;
						if ($sftpConfig) {
							$host = $sftpConfig['host'];
							$port = $sftpConfig['port'] ?? 22;
							$user = $sftpConfig['username'];
							$authMethod = $sftpConfig['authMethod'] ?? 'password';
							$path = rtrim($sftpConfig['path'] ?? '', '/');
							$fileName = "{$backup['name']}_{$backup['datetime']}.zip";
							$remoteFile = $path ? "$path/$fileName" : $fileName;

							$keyFile = null;
							if ($authMethod === 'key' && !empty($sftpConfig['privateKey'])) {
								$keyFile = tempnam(sys_get_temp_dir(), 'sftp_key_');
								file_put_contents($keyFile, $sftpConfig['privateKey']);
								chmod($keyFile, 0400);
								$checkCmd =
									"sftp -i " . escapeshellarg($keyFile) . " -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
									escapeshellarg("$user@$host") . " << 'EOF'\n" .
									"ls " . escapeshellarg($remoteFile) . "\n" .
									"EOF";
							} else {
								$pass = $sftpConfig['password'] ?? '';
								$checkCmd =
									"export SSHPASS=" . escapeshellarg($pass) . "; " .
									"sshpass -e sftp -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
									escapeshellarg("$user@$host") . " << 'EOF'\n" .
									"ls " . escapeshellarg($remoteFile) . "\n" .
									"EOF";
							}

							exec($checkCmd, $output, $returnCode);
							$backup['ready'] = ($returnCode === 0);

							if ($keyFile) {
								@unlink($keyFile);
							}
						}
					}
					elseif ($backup['type'] === 'RingQ Service') {
						$responseData = $ringqApi(2); 
						if ($responseData['result'] == 'success') {
							foreach($responseData['data'] as $apiItem) {
								if ($backup['id'] == $apiItem['fileId']) {
									$backup['ready'] = true;
									$backup['downloadId'] = $apiItem['id'];
									$this->redis->lset("backups", $backupIndex, json_encode($backup));
								}
							}
						}
					}
				}
				$processedBackups[] = $backup;
			} 
			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $processedBackups,
				"status" => !is_null($performBackup) ? 1 : (($restoreActive || !is_null($performRestore)) ? 2 : 0),
				"restore" => $restoreDoc,
				"import"  => \Library\JobProgress::read('import'),
				"backup"  => \Library\JobProgress::read('backup')
			]);
		}
		// Perform backup
		if ($type == 7) {
			$customName = $this->request->getPost("name");
			if (empty($customName)) {
				$contents = file_get_contents("php://input");
				$data = json_decode($contents, true);
				$customName = $data['name'] ?? null;
			}

			$id = uniqid();
			$finalName = !empty($customName) ? $customName : "ringq-" . $id;

			// Check for uniqueness
			$backups = $this->redis->lrange("backups", 0, -1);
			foreach ($backups as $backupStr) {
				$backup = json_decode($backupStr, true);
				if (isset($backup['name']) && $backup['name'] === $finalName) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Backup with this name already exists ($finalName)."
					]);
				}
			}

			$backupData = [
				"id" => $id,
				"name" => $finalName,
				"type" => "Local Disk",
				"includes" => [
					"Database",
					"Redis",
					"Recordings",
					"Voicemails",
					"Images"
				],
			];

			// Check for saved settings to respect exclusions
			$settingsJson = $this->redis->get("backup_settings");
			if ($settingsJson) {
				$settings = json_decode($settingsJson, true);
				if (isset($settings['includes']) && is_array($settings['includes'])) {
					$backupData['includes'] = $settings['includes'];
				}
				if (isset($settings['timezone'])) {
					$backupData['timezone'] = $settings['timezone'];
				}
				if (isset($settings['storage'])) {
					$backupData['type'] = $settings['storage'];
				}

				if ($settings['storage'] == 'RingQ Service') {
					$responseData = $ringqApi(3);
					if ($responseData['result'] == 'success') {
						return $this->response->setJsonContent([
							"result" => "success",
							"content" => [],
							"status" => 1,
							"message" => "Backup is now in-progress"
						]);
					}
					return $this->response->setJsonContent([
						"result" => "error",
						"content" => [],
						"status" => 0,
						"message" => "failed to backup in ringq service"
					]);
				}
			} 

			$this->redis->set("performBackup", json_encode($backupData));

			// The queued document is written here, not left for the worker: the worker
			// is cron on a one-minute tick, and without this the client is told the job
			// is idle and stops watching before it ever starts.
			$backupProgress = new \Library\JobProgress('backup');
			$backupJobId    = $backupProgress->begin([
				"name"     => $finalName,
				"type"     => $backupData['type'],
				"includes" => $backupData['includes'],
			], null, \Library\JobProgress::STATE_QUEUED);

			return $this->response->setJsonContent([
				"result" => "success",
				"content" => [],
				"status" => 1,
				"jobId" => $backupJobId,
				"backup" => \Library\JobProgress::read('backup'),
				"message" => "Backup is now in-progress"
			]);
		} 
		// Restore backup
		if ($type == 8) {
			$existing = \Library\JobProgress::read('restore');
			if (in_array($existing['state'], ['queued', 'running'], true)) {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "A restore is already in progress.",
					"restore" => $existing
				]);
			}

			$backups = $this->redis->lrange("backups", $page, $page);
			if (empty($backups)) {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "That backup could not be found. Refresh the list and try again."
				]);
			}
			$data = $backups[0];
			$this->redis->set("performRestore", $data);
			$this->redis->set('performBackup:uuid', $session["domain_uuid"]);

			// The worker is a cron task, so there is a gap of up to a minute before it
			// picks this up. Writing the queued document here means the UI can say
			// "queued, starting shortly" instead of showing a bar that reports nothing.
			$item     = json_decode($data, true) ?: [];
			$progress = new \Library\JobProgress('restore');
			$jobId    = $progress->begin([
				"name"      => $item['name'] ?? '',
				"datetime"  => $item['datetime'] ?? '',
				"type"      => $item['type'] ?? 'Local Disk',
				"sizeBytes" => isset($item['size']) ? (int) $item['size'] : null,
				"includes"  => $item['includes'] ?? [],
			], null, \Library\JobProgress::STATE_QUEUED);

			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $backups,
				"status" => 2,
				"jobId" => $jobId,
				"restore" => \Library\JobProgress::read('restore'),
				"message" => "Restore is now in-progress"
			]);
		}
		// Restore status (kept for clients that predate the progress endpoint, but no
		// longer answered from Redis: the restore replaces the whole Redis dataset, so
		// "the key is gone" meant "the job is somewhere past the Redis step", not
		// "finished" — which is how the old UI came to sign people out mid-restore.)
		if ($type == 9) {
			$doc           = \Library\JobProgress::read('restore');
			$restoreStatus = in_array($doc['state'], ['queued', 'running'], true) ? 1 : 0;

			return $this->response->setJsonContent([
				"result" => "success",
				"content" => [],
				"status" => $restoreStatus,
				"restore" => $doc,
				"message" => "Restoration status."
			]);
		}
		// Delete Backup
		if ($type == 10) {
			$backups = $this->redis->lrange("backups", $page, $page);
			if (!empty($backups)) {
				$item = $backups[0];
				$this->redis->lrem("backups", 0, $item);
				$this->redis->lpush("performDeleteQueue", $item);
			}
			$backups = $this->redis->lrange("backups", 0, -1);
			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $backups,
				"status" => 1,
				"message" => "Successfully deleted backup."
			]);
		}
		// Download Backup
		if ($type == 11) {
			$backups = $this->redis->lrange("backups", $page, $page);
			$data = $backups[0];
			$item = json_decode($data, true);
			$name = $item["name"];
			$date = $item["datetime"];
			$type = $item["type"];

			if ($type == 'Local Disk') {
				$realPath = "/var/www/ringq/app/migrations/{$name}_{$date}.zip";
				if (file_exists($realPath)) { 
					if (ob_get_level()) {
						ob_end_clean();
					}
					header('Content-Description: File Transfer');
					header('Content-Type: application/zip');
					header('Content-Disposition: attachment; filename="'.$name.'_'.$date.'.zip"');
					header('Content-Length: ' . filesize($realPath));
					header('Cache-Control: must-revalidate');
					header('Pragma: public');
					header('Expires: 0');
					$fp = fopen($realPath, 'rb');
					while (!feof($fp)) {
						echo fread($fp, 8192);
						flush();
					}
					fclose($fp);
					exit;
				}
			}
			elseif ($type == 'AWS S3') {
				$backupSettings = $this->redis->get("backup_settings");
				$settings = json_decode($backupSettings, true);
				$s3CustomConfig = isset($settings['storageConfig']['AWS S3']) ? $settings['storageConfig']['AWS S3'] : [];
				
				$accessKey = $s3CustomConfig['accessKey'] ?? '';
				$secretKey = $s3CustomConfig['secretKey'] ?? '';
				$region = $s3CustomConfig['region'] ?? 'ap-southeast-1';
				$storagePath = !empty($s3CustomConfig['storagePath']) ? $s3CustomConfig['storagePath'] : 'ringq-backups';
				
				$bucketParts = explode('/', $storagePath);
				$bucketName = $bucketParts[0];
				array_shift($bucketParts);
				$prefix = !empty($bucketParts) ? implode('/', $bucketParts) . '/' : '';
				$fileName = "{$name}_{$date}.zip";
				$s3Key = $prefix . $fileName;
				
				$env = "export AWS_ACCESS_KEY_ID=" . escapeshellarg($accessKey) . "; export AWS_SECRET_ACCESS_KEY=" . escapeshellarg($secretKey) . "; export AWS_DEFAULT_REGION=" . escapeshellarg($region) . ";";
				
				// Check if object exists
				$checkCommand = "$env aws s3api head-object --bucket " . escapeshellarg($bucketName) . " --key " . escapeshellarg($s3Key) . " 2>&1";
				exec($checkCommand, $output, $returnCode);

				if ($returnCode !== 0) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Backup file not found in S3 bucket. It might have been deleted manually."
					]);
				}

				// Get file size for header if possible
				$size = 0;
				foreach ($output as $line) {
					if (strpos($line, '"ContentLength":') !== false) {
						$size = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
						break;
					}
				}

				// Stream the file
				if (ob_get_level()) {
					ob_end_clean();
				}
				header('Content-Description: File Transfer');
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="' . $fileName . '"');
				if ($size > 0) {
					header('Content-Length: ' . $size);
				}
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Expires: 0');

				$streamCommand = "$env aws s3 cp s3://" . $storagePath . "/" . $fileName . " -";
				$handle = popen($streamCommand, 'r');
				if ($handle) {
					while (!feof($handle)) {
						echo fread($handle, 8192);
						flush();
					}
					pclose($handle);
				}
				exit;
			}
			elseif ($type == 'FTP') {
				$backupSettings = $this->redis->get("backup_settings");
				$settings = json_decode($backupSettings, true);
				$ftpConfig = $settings['storageConfig']['FTP'] ?? [];

				$host = $ftpConfig['host'] ?? '';
				$port = $ftpConfig['port'] ?? 21;
				$user = $ftpConfig['username'] ?? '';
				$pass = $ftpConfig['password'] ?? '';
				$path = rtrim($ftpConfig['path'] ?? '', '/');
				$fileName = "{$name}_{$date}.zip";
				$remoteFile = $path ? "$path/$fileName" : $fileName;

				if (ob_get_level()) {
					ob_end_clean();
				}
				header('Content-Description: File Transfer');
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="' . $fileName . '"');
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Expires: 0');

				$url = "ftp://$host:$port/" . ltrim($remoteFile, '/');
				
				// Get Size
				$curl = curl_init($url);
				curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass");
				curl_setopt($curl, CURLOPT_NOBODY, true);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($curl, CURLOPT_FILETIME, true);
				curl_setopt($curl, CURLOPT_FTP_USE_EPSV, 0);
				curl_exec($curl);
				$size = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
				$sizeErr = curl_error($curl);
				curl_close($curl);

				if ($size > 0) {
					header('Content-Length: ' . $size);
				}

				$curl = curl_init($url);
				curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass");
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
				curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
				curl_setopt($curl, CURLOPT_FTP_USE_EPSV, 0);
				// Stream directly to output
				$success = curl_exec($curl);
				$downErr = curl_error($curl);
				
				if (!$success) {
					$logMsg = date('[Y-m-d H:i:s]') . " FTP Download Failed: $url | Error: $downErr | SizeErr: $sizeErr\n";
					file_put_contents('/tmp/ftp_debug.log', $logMsg, FILE_APPEND);
				}
				
				curl_close($curl);
				exit;
			} 
			elseif ($type == 'SFTP') {
				$backupSettings = $this->redis->get("backup_settings");
				$settings = json_decode($backupSettings, true);
				$sftpConfig = $settings['storageConfig']['SFTP'] ?? [];

				$host = $sftpConfig['host'] ?? '';
				$port = $sftpConfig['port'] ?? 22;
				$user = $sftpConfig['username'] ?? '';
				$authMethod = $sftpConfig['authMethod'] ?? 'password';
				$path = rtrim($sftpConfig['path'] ?? '', '/');
				$fileName = "{$name}_{$date}.zip";
				$remoteFile = $path ? "$path/$fileName" : $fileName;

				if (ob_get_level()) {
					ob_end_clean();
				}
				header('Content-Description: File Transfer');
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="' . $fileName . '"');
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Expires: 0');

				$keyFile = null;
				$size = 0;
				if ($authMethod === 'key' && !empty($sftpConfig['privateKey'])) {
					$keyFile = tempnam(sys_get_temp_dir(), 'sftp_key_');
					file_put_contents($keyFile, $sftpConfig['privateKey']);
					chmod($keyFile, 0400);

					// Get Size
					$sizeCmd = "ssh -q -T -i " . escapeshellarg($keyFile) . " -p $port -o BatchMode=yes -o StrictHostKeyChecking=no " . escapeshellarg("$user@$host") . " stat -c%s " . escapeshellarg($remoteFile) . " 2>/dev/null";
					$size = (int)exec($sizeCmd);

					$streamCommand = "ssh -q -T -i " . escapeshellarg($keyFile) . " -p $port -o BatchMode=yes -o StrictHostKeyChecking=no " . escapeshellarg("$user@$host") . " cat " . escapeshellarg($remoteFile);
				} else {
					$pass = $sftpConfig['password'] ?? '';

					// Get Size
					$sizeCmd = "export SSHPASS=" . escapeshellarg($pass) . "; sshpass -e ssh -q -T -p $port -o BatchMode=no -o StrictHostKeyChecking=no " . escapeshellarg("$user@$host") . " stat -c%s " . escapeshellarg($remoteFile) . " 2>/dev/null";
					$size = (int)exec($sizeCmd);

					$streamCommand = "export SSHPASS=" . escapeshellarg($pass) . "; sshpass -e ssh -q -T -p $port -o BatchMode=no -o StrictHostKeyChecking=no " . escapeshellarg("$user@$host") . " cat " . escapeshellarg($remoteFile);
				}

				if ($size > 0) {
					header('Content-Length: ' . $size);
				}

				$handle = popen($streamCommand, 'r');
				if ($handle) {
					while (!feof($handle)) {
						echo fread($handle, 8192);
						flush();
					}
					pclose($handle);
				}

				if ($keyFile) {
					@unlink($keyFile);
				}
				exit;
			}
			elseif ($type == 'RingQ Service') {
				$fileName = preg_replace('/_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', '', $name) . "_{$date}.zip";
				error_log('FILENAME: '. $fileName);
				$payload  = json_encode([
					"licenseKey" => $licenseKey,
					"type"       => 4,
					"userId"     => $userId,
					"id"         => $item['downloadId'],
				]); 

				set_time_limit(0);
				ini_set('memory_limit', '512M');

				// Step 1: Stream remote file into a temp file on disk (not into memory)
				$tmpFile = tempnam(sys_get_temp_dir(), 'ringq_backup_');
				$tmpHandle = fopen($tmpFile, 'wb');

				$ch = curl_init("https://licensekey.ringq.com/backup/backupdata");
				curl_setopt_array($ch, [
					CURLOPT_POST           => true,
					CURLOPT_POSTFIELDS     => $payload,
					CURLOPT_HTTPHEADER     => [
						"Content-Type: application/json",
						"UUID: "                . $uuid,
						"Authorization-token: " . $accessToken,
					],
					CURLOPT_RETURNTRANSFER => false,
					CURLOPT_WRITEFUNCTION  => function($ch, $chunk) use ($tmpHandle) {
						fwrite($tmpHandle, $chunk);
						return strlen($chunk);
					},
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_TIMEOUT        => 0,
					CURLOPT_SSL_VERIFYPEER => true,
				]);

				$success  = curl_exec($ch);
				$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);
				fclose($tmpHandle);

				if (!$success || $httpCode !== 200) {
					error_log('Backup cURL error, HTTP: ' . $httpCode);
					unlink($tmpFile);
					http_response_code(502);
					echo json_encode(['error' => 'Failed to fetch backup from remote server']);
					exit;
				}

				$fileSize = filesize($tmpFile);
				error_log('Backup downloaded to tmp: ' . $tmpFile . ' size: ' . $fileSize);

				// Step 2: Serve the temp file to browser — same as Local Disk
				while (ob_get_level() > 0) ob_end_clean();
				header('Content-Description: File Transfer');
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="' . $fileName . '"');
				header('Content-Length: ' . $fileSize);       // ← browser now shows progress
				header('Cache-Control: must-revalidate');
				header('Pragma: public');
				header('Expires: 0');

				$fp = fopen($tmpFile, 'rb');
				while (!feof($fp)) {
					echo fread($fp, 8192);
					flush();
				}
				fclose($fp);

				// Clean up temp file
				unlink($tmpFile);
				exit;
			}
		}
		// Import backup
		if ($type == 12 && $this->request->hasFiles() === true) {
			$file = $this->request->getUploadedFiles()[0];

			if ($file->getError() != 0) {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "File upload failed with error code: " . $file->getError()
				]);
			}

			$ext = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
			if ($ext !== 'zip') {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "Allowed file types are .zip."
				]);
			}

			// Everything below the upload used to happen HERE, inside this request:
			// password-checked extraction, an `aws s3 cp` per media archive, then a zip
			// re-package with a ten-minute timeout. On a large backup that outlives
			// PHP-FPM and the browser is left on a bar at 100% with four hardcoded
			// five-second status messages that were wired to nothing. The request now
			// does one thing — take custody of the file and queue it — and the worker
			// reports real progress through the same document the restore uses.
			$existing = \Library\JobProgress::read('import');
			if (in_array($existing['state'], ['queued', 'running'], true)) {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "An import is already in progress. Wait for it to finish before starting another.",
					"import"  => $existing
				]);
			}

			// Staged on the same filesystem as the final archive rather than in /tmp:
			// this host has filled /tmp before, and a failed import there leaves a
			// several-hundred-megabyte file behind with nothing to clean it up.
			$stagingDir = "/var/www/ringq/app/migrations/.imports";
			if (!is_dir($stagingDir) && !@mkdir($stagingDir, 0770, true) && !is_dir($stagingDir)) {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "Could not prepare the import staging directory."
				]);
			}

			$importId  = uniqid("import_");
			$stagedZip = $stagingDir . "/" . $importId . ".zip";
			if (!$file->moveTo($stagedZip)) {
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "Failed to move the uploaded file into place."
				]);
			}
			@chmod($stagedZip, 0640);

			// Extraction plus repackaging needs room for roughly three copies. Refusing
			// now, by name, beats filling the disk and failing somewhere less obvious.
			$uploadedSize = (int) @filesize($stagedZip);
			$freeBytes    = (float) @disk_free_space($stagingDir);
            if ($uploadedSize > 0 && $freeBytes > 0 && $freeBytes < ($uploadedSize * 3)) {
				@unlink($stagedZip);
				return $this->response->setJsonContent([
					"result"  => "error",
					"message" => "Not enough free disk space to import this backup. It needs about "
						. ceil(($uploadedSize * 3) / 1073741824) . " GB free."
				]);
			}

			$progress = new \Library\JobProgress('import');
			$jobId    = $progress->begin([
				"name"      => pathinfo($file->getName(), PATHINFO_FILENAME),
				"fileName"  => $file->getName(),
				"sizeBytes" => $uploadedSize,
				"type"      => "Local Disk",
			], null, \Library\JobProgress::STATE_QUEUED);

			$this->redis->set("performImport", json_encode([
				"jobId"        => $jobId,
				"path"         => $stagedZip,
				"originalName" => $file->getName(),
				"sizeBytes"    => $uploadedSize,
				"domain_uuid"  => $session["domain_uuid"],
				"serverType"   => $this->redis->get('install_mode'),
				"queuedAt"     => time(),
			]));

			return $this->response->setJsonContent([
				"result"  => "success",
				"message" => "Upload complete. Preparing the backup…",
				"jobId"   => $jobId,
				"import"  => \Library\JobProgress::read('import'),
				"content" => []
			]);
		}
		// Save Settings
		if ($type == 13) {
			if ($this->request->isPost()) {
				$contents = file_get_contents("php://input");
				$parameters = json_decode($contents, true);

				if (json_last_error() !== JSON_ERROR_NONE) {
					return $this->response->setJsonContent([
						"result" => "error",
						"message" => "Invalid JSON payload.",
					]);
				}

				$backupSettings = [
					"scheduleBackup" => isset($parameters["scheduleBackup"]) ? (bool)$parameters["scheduleBackup"] : false,
					"frequency" => isset($parameters["frequency"]) ? $parameters["frequency"] : "Daily",
					"time" => isset($parameters["time"]) ? $parameters["time"] : "00:00",
					"retention" => isset($parameters["retention"]) ? intval($parameters["retention"]) : 7,
					"dayOfWeek" => isset($parameters["dayOfWeek"]) ? $parameters["dayOfWeek"] : "Monday",
					"dayOfMonth" => isset($parameters["dayOfMonth"]) ? intval($parameters["dayOfMonth"]) : 1,
					"prefix" => isset($parameters["prefix"]) ? $parameters["prefix"] : "AutoBackup_",
					"notifySuccess" => isset($parameters["notifySuccess"]) ? (bool)$parameters["notifySuccess"] : false,
					"notifyFailure" => isset($parameters["notifyFailure"]) ? (bool)$parameters["notifyFailure"] : true,
					"email" => isset($parameters["email"]) ? $parameters["email"] : "",
					"storage" => isset($parameters["storage"]) ? $parameters["storage"] : "Local Disk",
					"storagePath" => isset($parameters["storagePath"]) ? $parameters["storagePath"] : "",
					"storageConfig" => isset($parameters["storageConfig"]) ? $parameters["storageConfig"] : [],
					"includes" => isset($parameters["includes"]) ? $parameters["includes"] : ["Database", "Redis", "Recordings", "Voicemails", "Images"],
					"timezone" => isset($parameters["timezone"]) ? $parameters["timezone"] : date_default_timezone_get(),
					"domain_uuid" => $session["domain_uuid"]
				];

				$this->redis->set("backup_settings", json_encode($backupSettings));

				// Validate AWS S3 Connection and bucket exists
				if ($backupSettings['storage'] === "AWS S3") {
					
				}

				// Validate SFTP Connection if type is SFTP
				if ($backupSettings['storage'] === "SFTP") {
					$sftp = $backupSettings['storageConfig']['SFTP'] ?? null;
					if ($sftp) {
						$host = $sftp['host'];
						$port = $sftp['port'] ?? 22;
						$user = $sftp['username'];
						$authMethod = $sftp['authMethod'] ?? 'password';
						$path = rtrim($sftp['path'] ?? '', '/');

						// Test connection and directory existence
						$keyFile = null;
						if ($authMethod === 'key' && !empty($sftp['privateKey'])) {
							$keyFile = tempnam(sys_get_temp_dir(), 'sftp_key_');
							file_put_contents($keyFile, $sftp['privateKey']);
							chmod($keyFile, 0400);
							$testCmd =
								"sftp -i " . escapeshellarg($keyFile) .
								" -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
								escapeshellarg("$user@$host") . " << 'EOF'\n" .
								"ls -d " . escapeshellarg($path ?: '.') . "\nEOF";
						} else {
							$pass = $sftp['password'] ?? '';
							$testCmd =
								"export SSHPASS=" . escapeshellarg($pass) . "; " .
								"sshpass -e sftp -o Port=$port -o BatchMode=no -o StrictHostKeyChecking=no " .
								escapeshellarg("$user@$host") . " << 'EOF'\n" .
								"ls -d " . escapeshellarg($path ?: '.') . "\nEOF";
						}

						exec($testCmd, $output, $returnCode);

						if ($keyFile) {
							@unlink($keyFile);
						}

						if ($returnCode !== 0) {
							return $this->response->setJsonContent([
								"result" => "error",
								"message" => "SFTP Connection failed or directory does not exist. Please check your settings.",
							]);
						}
					}
				}

				// Validate FTP Connection if type is FTP
				if ($backupSettings['storage'] === "FTP") {
					$ftp = $backupSettings['storageConfig']['FTP'] ?? null;
					if ($ftp) {
						$host = $ftp['host'];
						$port = $ftp['port'] ?? 21;
						$user = $ftp['username'];
						$pass = $ftp['password'];
						$path = rtrim($ftp['path'] ?? '', '/');

						$url = "ftp://$host:$port/" . ltrim($path ?: '.', '/') . '/';
						$curl = curl_init($url);
						curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass");
						curl_setopt($curl, CURLOPT_FTPLISTONLY, true);
						curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
						$exec = curl_exec($curl);
						$error = curl_error($curl);
						$info = curl_getinfo($curl);
						$success = ($info['http_code'] == 200 || $info['http_code'] == 226 || $info['http_code'] == 250);
						curl_close($curl);

						if (!$success) {
							$msg = "FTP Connection failed or directory does not exist.";
							if ($error) {
								$msg .= " Error: $error";
							} elseif ($info['http_code']) {
								$msg .= " (HTTP Code: " . $info['http_code'] . ")";
							}
							return $this->response->setJsonContent([
								"result" => "error",
								"message" => $msg,
							]);
						}
					}
				}

				return $this->response->setJsonContent([
					"result" => "success",
					"message" => "Backup settings saved successfully.",
					"content" => $backupSettings
				]);
			}
		}
		// Get Settings
		if ($type == 14) {
			$scheduleJson = $this->redis->get("backup_settings");
			$scheduleData = $scheduleJson ? json_decode($scheduleJson, true) : [];
			if (empty($scheduleData)) {
				// Default values if no schedule exists
				$scheduleData = [
					"scheduleBackup" => false,
					"frequency" => "Daily",
					"time" => "00:00",
					"retention" => 7,
					"dayOfWeek" => "Monday",
					"dayOfMonth" => 1,
					"prefix" => "AutoBackup_",
					"notifySuccess" => false,
					"notifyFailure" => true,
					"email" => "",
					"storage" => "Local Disk",
					"storagePath" => "",
					"includes" => ["Database", "Redis", "Recordings", "Voicemails", "Images"],
					"timezone" => date_default_timezone_get()
				];
			}

			$scheduleData['ringq_service_available'] = false;
			$responseData = $ringqApi(1);
			if ($responseData['result'] == 'success') {
				$scheduleData['ringq_service_available'] = $responseData['isBackup'] == 'yes';
			}

			// Add server timezone for UI default/info
			$scheduleData['server_timezone'] = date_default_timezone_get();
			if (!isset($scheduleData['timezone'])) {
				$scheduleData['timezone'] = $scheduleData['server_timezone'];
			}

			// Calculate Next Run
			$nextRun = null;
			if (isset($scheduleData['scheduleBackup']) && $scheduleData['scheduleBackup'] === true) {
				$timezone = $scheduleData['timezone'];
				try {
					$tz = new DateTimeZone($timezone);
				} catch (\Exception $e) {
					$tz = new DateTimeZone(date_default_timezone_get());
				}
				
				$timeStr = isset($scheduleData['time']) ? $scheduleData['time'] : "00:00";
				
				// Create DateTime object for "today" in the specific timezone
				$now = new DateTime("now", $tz);
				$baseTime = new DateTime("today $timeStr", $tz);
				
				$freq = isset($scheduleData['frequency']) ? $scheduleData['frequency'] : "Daily";

				if ($freq == 'Daily') {
					if ($baseTime > $now) {
						$nextRun = $baseTime->getTimestamp();
					} else {
						$baseTime->modify('+1 day');
						$nextRun = $baseTime->getTimestamp();
					}
				} elseif ($freq == 'Weekly') {
					$day = isset($scheduleData['dayOfWeek']) ? $scheduleData['dayOfWeek'] : "Monday";
					// Check if today matches the scheduled day
					if ($now->format('l') == $day && $baseTime > $now) {
						$nextRun = $baseTime->getTimestamp();
					} else {
						$baseTime->modify("next $day");
						$nextRun = $baseTime->getTimestamp();
					}
				} elseif ($freq == 'Monthly') {
					$day = isset($scheduleData['dayOfMonth']) ? intval($scheduleData['dayOfMonth']) : 1;
					// Create date for this month's run
					$currentMonthRun = new DateTime($now->format('Y-m-') . sprintf("%02d", $day) . " $timeStr", $tz);
					
					if ($currentMonthRun > $now) {
						$nextRun = $currentMonthRun->getTimestamp();
					} else {
						$currentMonthRun->modify('+1 month');
						$nextRun = $currentMonthRun->getTimestamp();
					}
				}
			}

			$scheduleData['last_run'] = $this->redis->get("backup_scheduler_last_run");
			
			if ($nextRun) {
				// Format next_run in the User's selected Timezone
				$nextRunDate = new DateTime();
				$nextRunDate->setTimestamp($nextRun);
				
				// Ensure we format it in the user's timezone context
				$timezone = isset($scheduleData['timezone']) ? $scheduleData['timezone'] : date_default_timezone_get();
				try {
					$tz = new DateTimeZone($timezone);
				} catch (\Exception $e) {
					$tz = new DateTimeZone(date_default_timezone_get());
				}
				$nextRunDate->setTimezone($tz);
				
				$scheduleData['next_run'] = $nextRunDate->format("Y-m-d H:i:s");
			} else {
				$scheduleData['next_run'] = null;
			}

			// --- Calculate Size Stats ---
			$stats = [
				"database" => "0B",
				"redis" => "0B",
				"recordings" => "0B",
				"voicemails" => "0B",
				"images" => "0B"
			];

			try {
				// Database
				$dbname = $this->config->database["dbname"];
				$result = $this->db->fetchOne("SELECT pg_size_pretty(pg_database_size('$dbname')) as size");
				if ($result) $stats['database'] = $result['size'];

				// Redis
				$redisUsage = "0B";
				try {
					$infoResponse = null;
					// Helper to extract value
					$parseRedisInfo = function($data) {
						if (is_array($data) && isset($data['used_memory_human'])) {
							return $data['used_memory_human'];
						}
						if (is_string($data)) {
							if (preg_match('/used_memory_human:[\s]*([^\r\n]+)/', $data, $matches)) {
								return trim($matches[1]);
							}
						}
						return null;
					};

					// Try standard info()
					if (method_exists($this->redis, 'info')) {
						$infoResponse = $this->redis->info('memory');
					}
					
					// Fallback to executeRaw if info() failed to give us what we want
					$redisUsage = $parseRedisInfo($infoResponse);
					
					if (!$redisUsage && method_exists($this->redis, 'executeRaw')) {
						$raw = $this->redis->executeRaw(['INFO', 'MEMORY']);
						$redisUsage = $parseRedisInfo($raw);
					}
				} catch (\Exception $e) {
					error_log("Redis Stats Error: " . $e->getMessage());
				}
				if ($redisUsage) $stats['redis'] = $redisUsage;

				// Filesystem 
				$recSize = trim((string) shell_exec("du -sh /var/lib/freeswitch/recordings 2>/dev/null | awk '{print $1}'"));
				if ($recSize) $stats['recordings'] = $recSize;
				
				$vmSize = trim((string) shell_exec("du -sh /var/lib/freeswitch/storage 2>/dev/null | awk '{print $1}'"));
				if ($vmSize) $stats['voicemails'] = $vmSize;

				// Images: sum sizes of all configured image dirs (any missing ones contribute 0)
				$imgPaths = '/var/www/ringq/public/avr /var/www/webapp/chat_file_upload '
					. '/var/www/ringq/public/meet_chat /var/www/ringq/public/meet_recordings '
					. '/var/www/ringq/public/meet_whiteboards';
				$imgSize = trim((string) shell_exec("du -shc $imgPaths 2>/dev/null | tail -n1 | awk '{print \$1}'"));
				if ($imgSize) $stats['images'] = $imgSize;
				
			} catch (\Exception $e) {
				error_log("Backup Stats Error: " . $e->getMessage());
			}

			$scheduleData['stats'] = $stats;
			// ---------------------------

			return $this->response->setJsonContent([
				"result" => "success",
				"content" => $scheduleData,
			]);
		}
    } 
}