<?php

use Phalcon\Db\Enum as DbEnum;

/*
|--------------------------------------------------------------------------
| faxExtensionEmailNotifier.php
|--------------------------------------------------------------------------
|
| SEPARATE, ADDITIVE MODULE — does not modify faxInboundListener.php.
|
| Purpose: for q_dids rows where type = 8 (extension-routed fax DID),
| resolve the destination email address via:
|
|   q_dids.extension
|     -> v_extensions.extension (scoped by domain_uuid)   => extension_uuid
|     -> v_extension_users.extension_uuid                  => user_uuid
|     -> v_users.user_uuid                                 => user_email
|
| ...then email the received fax PDF as an attachment, using the
| "faxreceived" template added to library/Email.php (-----pm code-----
| blocks in that file), instead of raw PHP mail().
|
| This file must be required alongside faxInboundListener.php (e.g. from
| worker.php, the same way faxInboundListener.php itself is required). It
| assumes $db is a Phalcon DB adapter obtained the same way (getDb()).
|
| INTEGRATION: faxInboundListener.php already calls
| sendFaxReceivedEmailToExtension() right after logging the "received"
| status (see its own "NEW (additive)" comment block) — no further edits
| are needed there. This file's job is just what happens inside that
| function: resolve the email, then hand off to library/Email.php.
|
| CONFIG ASSUMPTIONS (-----pm code----- block below) — adjust if these
| don't match your server:
|   - library/Email.php lives at app/library/Email.php, i.e. one directory
|     up from wherever this file sits (override via FAX_EMAIL_LIB_PATH env
|     var if this file isn't in app/jobs/ or similar).
|   - $licenseConfig comes from an existing getLicenseConfig() function if
|     worker.php defines one; otherwise from RINGQ_FQDN / RINGQ_LICENSE_KEY
|     env vars.
|   - Redis (needed by Email.php's $this->redis calls) comes from the
|     app's default Phalcon DI if one is already set with a 'redis'
|     service; otherwise from REDIS_HOST / REDIS_PORT env vars
|     (default 127.0.0.1:6379).
*/

const FAX_DID_TYPE_EXTENSION_EMAIL = 8;

// -----pm code----- (wire this module to library/Email.php) START
//
// Everything in this block is self-contained on purpose: since it wasn't
// confirmed how worker.php exposes $licenseConfig or a DI container to the
// jobs it runs, this module builds its own minimal versions instead of
// assuming something exists in worker.php. If worker.php DOES already
// define a getLicenseConfig() function and/or set a default Phalcon DI
// with a 'redis' service, this code detects and reuses those automatically
// — no changes needed on your end either way. If your paths/env var names
// differ from the defaults below, just adjust the getenv() calls.

// Path to library/Email.php. Default assumes this file lives in
// app/jobs/ (sibling to app/library/), matching the directory layout
// (controllers, forms, jobs, library, models, ... under app/).
// Override with FAX_EMAIL_LIB_PATH env var if that's wrong.
if (!defined('FAX_EMAIL_LIB_PATH')) {
    // Confirmed via screenshot: Email.php lives at this exact absolute path.
    // Hardcoded rather than a relative __DIR__ guess, so it works regardless
    // of which directory this notifier file itself is sitting in.
    define('FAX_EMAIL_LIB_PATH', getenv('FAX_EMAIL_LIB_PATH') ?: '/var/www/ringq/app/library/Email.php');
}

/*
 * Resolves $licenseConfig the same shape Email::send() expects:
 * ['data' => ['fqdn' => ..., 'licenseKey' => ...]]
 *
 * Reuses an existing getLicenseConfig() function from worker.php if one is
 * defined. Otherwise reads it the same way the rest of the app does: the
 * 'ringq_config' key in Redis, JSON-decoded. Only falls back to env vars
 * (RINGQ_FQDN / RINGQ_LICENSE_KEY) if that Redis key isn't there either.
 */
if (!function_exists('faxEmailGetLicenseConfig')) {
    function faxEmailGetLicenseConfig() {
        if (function_exists('getLicenseConfig')) {
            return getLicenseConfig();
        }

        try {
            $di = \Phalcon\Di::getDefault();
            $redis = ($di && $di->has('redis')) ? $di->get('redis') : null;
            if ($redis && $redis->exists('ringq_config')) {
                $decoded = json_decode($redis->get('ringq_config'), true);
                if (is_array($decoded) && isset($decoded['data']['licenseKey'])) {
                    return $decoded;
                }
                faxlog("faxEmailGetLicenseConfig: 'ringq_config' in redis did not decode to the expected ['data']['licenseKey'] shape");
            } else {
                faxlog("faxEmailGetLicenseConfig: no 'ringq_config' key in redis, falling back to env vars");
            }
        } catch (\Throwable $e) {
            faxlog("faxEmailGetLicenseConfig: error reading 'ringq_config' from redis: " . $e->getMessage());
        }

        return [
            'data' => [
                'fqdn'       => getenv('RINGQ_FQDN') ?: '',
                'licenseKey' => getenv('RINGQ_LICENSE_KEY') ?: '',
            ],
        ];
    }
}

/*
 * Builds (or reuses) a Phalcon DI container with a 'redis' service, so
 * Email.php's $this->redis calls work even though this runs from a
 * worker/background job rather than a web request. Reuses the app's
 * default DI automatically if worker.php already set one up with redis
 * registered; otherwise creates its own using REDIS_HOST / REDIS_PORT
 * env vars (defaults 127.0.0.1:6379).
 */
if (!function_exists('faxEmailBuildDi')) {
    function faxEmailBuildDi() {
        $existing = \Phalcon\Di::getDefault();
        if ($existing && $existing->has('redis')) {
            return $existing;
        }

        $di = new \Phalcon\Di\FactoryDefault();
        $di->setShared('redis', function () {
            $redis = new \Redis();
            $redis->connect(
                getenv('REDIS_HOST') ?: '127.0.0.1',
                (int) (getenv('REDIS_PORT') ?: 6379)
            );
            return $redis;
        });
        return $di;
    }
}
// -----pm code----- (wire this module to library/Email.php) END

/*
 * Fetches the q_dids row details needed for routing (type + extension),
 * independent of resolveReceivingDomainUuid() in faxInboundListener.php
 * (which only returns domain_uuid). Kept separate on purpose so this
 * module never has to touch that existing function.
 */
function getDidRoutingDetails($db, $toNumber) {
    if (!$toNumber) {
        return null;
    }
    $row = $db->fetchOne(
        "SELECT domain_uuid, type, extension FROM q_dids WHERE did_number = ? LIMIT 1",
        DbEnum::FETCH_ASSOC,
        [$toNumber]
    );
    return $row ?: null;
}

/*
 * Walks v_extensions -> v_extension_users -> v_users to resolve the
 * email address that should receive the fax for a given extension
 * number within a domain.
 */
function resolveFaxEmailForExtension($db, $extension) {
    if (!$extension) {
        faxlog("resolveFaxEmailForExtension: missing extension, skipping");
        return null;
    }

    $ext = $db->fetchOne(
        "SELECT extension_uuid FROM v_extensions WHERE extension = ? LIMIT 1",
        DbEnum::FETCH_ASSOC,
        [$extension]
    );
    $extensionUuid = $ext['extension_uuid'] ?? null;
    if (!$extensionUuid) {
        faxlog("resolveFaxEmailForExtension: no v_extensions row for extension={$extension}");
        return null;
    }

    $extUser = $db->fetchOne(
        "SELECT user_uuid FROM v_extension_users WHERE extension_uuid = ?::uuid LIMIT 1",
        DbEnum::FETCH_ASSOC,
        [$extensionUuid]
    );
    $userUuid = $extUser['user_uuid'] ?? null;
    if (!$userUuid) {
        faxlog("resolveFaxEmailForExtension: no v_extension_users row for extension_uuid={$extensionUuid}");
        return null;
    }

    $user = $db->fetchOne(
        "SELECT user_email FROM v_users WHERE user_uuid = ?::uuid LIMIT 1",
        DbEnum::FETCH_ASSOC,
        [$userUuid]
    );
    $email = $user['user_email'] ?? null;
    if (!$email) {
        faxlog("resolveFaxEmailForExtension: no user_email set for user_uuid={$userUuid}");
        return null;
    }

    return $email;
}

/*
 * Detects whether another 'received' fax_jobs row already exists for the
 * same domain + from/to numbers within a short time window, excluding the
 * job passed in. Guards against sending a duplicate notification email
 * when two separate job rows get created for what is really one physical
 * fax call (e.g. FreeSWITCH firing hangup events for two call legs with
 * different Unique-IDs for the same inbound fax — matches the symptom of
 * two "Received" rows with identical from/to/time in the jobs list).
 *
 * This does NOT fix the underlying duplicate-job-row cause (that's in
 * faxInboundListener.php's call/leg handling) — it only stops the
 * user-visible symptom of getting two emails for one fax.
 */
function isDuplicateReceivedFax($db, $jobId, $domainUuid, $fromNumber, $toNumber, $windowSeconds = 90) {
    try {
        $row = $db->fetchOne(
            "SELECT id FROM fax_jobs
             WHERE domain_uuid = ?::uuid
               AND direction = 'in'
               AND status = 'received'
               AND from_number = ?
               AND to_number = ?
               AND id != ?::uuid
               AND updated_at >= (EXTRACT(EPOCH FROM NOW()) - ?)
             ORDER BY updated_at DESC
             LIMIT 1",
            DbEnum::FETCH_ASSOC,
            [$domainUuid, $fromNumber, $toNumber, $jobId, $windowSeconds]
        );
        return (bool) $row;
    } catch (\Throwable $e) {
        faxlog("isDuplicateReceivedFax: check failed (non-fatal, will NOT block the email): " . $e->getMessage());
        return false;
    }
}

/*
 * Resolves the destination email and sends the fax-received notification,
 * so a failure here (bad address, mail server issue, etc.) never affects
 * the fax job's own status, which has already been set to 'received'
 * by the time this runs.
 *
 * NOTE: uses PHP's built-in mail(). If you already have a mail/SMTP
 * helper elsewhere in the codebase (PHPMailer, Symfony Mailer, a queued
 * mail job, etc.), swap the body of this function to call that instead —
 * everything above it (the lookup chain) stays the same either way.
 */
function sendFaxReceivedEmailToExtension($db, $extension, $fromNumber, $toNumber, $pdfPath, $pages) {
    try {
        $email = resolveFaxEmailForExtension($db, $extension);
        if (!$email) {
            faxlog("sendFaxReceivedEmailToExtension: no email resolved for extension={$extension}, skipping send");
            return false;
        }

        if (!is_file($pdfPath)) {
            faxlog("sendFaxReceivedEmailToExtension: PDF not found at {$pdfPath}, skipping send");
            return false;
        }

        // -----pm code----- (send via library/Email.php "faxreceived" template) START
        if (!class_exists('\Library\Email')) {
            if (!is_file(FAX_EMAIL_LIB_PATH)) {
                faxlog("sendFaxReceivedEmailToExtension: Email.php not found at " . FAX_EMAIL_LIB_PATH . " — set FAX_EMAIL_LIB_PATH env var, skipping send");
                return false;
            }
            require_once FAX_EMAIL_LIB_PATH;
        }

        $licenseConfig = faxEmailGetLicenseConfig();

        $mailer = new \Library\Email();
        $mailer->setDI(faxEmailBuildDi());

        // NOTE: this calls sendFaxReceivedEmail() -- the dedicated method
        // appended at the end of Email.php (see the "-----pm code----- START"
        // block near the bottom of that file) -- NOT send() with a "type"
        // param. send() has no fax branch; that logic lives entirely in the
        // standalone sendFaxReceivedEmail() method.
        $result = $mailer->sendFaxReceivedEmail(
            $email,
            [
                "from"      => $fromNumber,
                "toId"      => $toNumber,
                "extension" => $extension,
                "date"      => date('M d, Y h:i A'),
                "pages"     => $pages,
                "file"      => $pdfPath,
            ],
            $licenseConfig
        );

        // sendFaxReceivedEmail() always returns an array with a "success"
        // key (true on both the custom-SMTP and SendGrid-relay paths, or
        // false with "errors" on failure).
        if (is_array($result) && isset($result['success']) && $result['success'] === false) {
            faxlog("sendFaxReceivedEmailToExtension: sendFaxReceivedEmail() reported failure for {$email}: " . json_encode($result['errors'] ?? $result));
            return false;
        }
        // -----pm code----- (send via library/Email.php "faxreceived" template) END

        faxlog("sendFaxReceivedEmailToExtension: fax email sent to {$email} (extension {$extension}, from {$fromNumber} to {$toNumber})");
        return true;

    } catch (\Throwable $e) {
        faxlog("sendFaxReceivedEmailToExtension: exception: " . $e->getMessage());
        return false;
    }
}

/*
|--------------------------------------------------------------------------
| INTEGRATION STATUS
|--------------------------------------------------------------------------
|
| Already done — no action needed in faxInboundListener.php. It already
| contains this call, right after the "received" job-status update and
| before the WhatTheFone webhook block:
|
|     $didRouting = getDidRoutingDetails($db, $message['headers']['Caller-Destination-Number'] ?? 'unknown');
|     if ($didRouting && (int) ($didRouting['type'] ?? 0) === FAX_DID_TYPE_EXTENSION_EMAIL) {
|         sendFaxReceivedEmailToExtension(
|             $db,
|             $didRouting['extension'],
|             $message['headers']['Caller-Caller-ID-Number'] ?? 'unknown',
|             $message['headers']['Caller-Destination-Number'] ?? 'unknown',
|             $pdfPath,
|             $pages
|         );
|     }
|
| One thing to confirm on your end: this file (faxExtensionEmailNotifier.php)
| must be required once alongside faxInboundListener.php wherever that is
| loaded — e.g. via workerRequireJob('faxExtensionEmailNotifier.php') in
| worker.php, right next to the faxInboundListener.php require. Without
| that require, getDidRoutingDetails()/sendFaxReceivedEmailToExtension()
| won't be defined when faxInboundListener.php tries to call them.
*/