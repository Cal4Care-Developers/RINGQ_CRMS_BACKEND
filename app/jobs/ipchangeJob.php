<?php
/**
 * ipchangeJob.php — the network-change verification code.
 *
 * NOT Meet, despite where it used to sit. It only borrows meetJob.php's mail
 * builder (meet_mailShell / meet_deliver), which is why it lived in the middle of
 * that block in worker.php and why the first extraction pass left it behind.
 *
 * It is still dispatched from handleMeetingNotification() in meetJob.php, which
 * drains the shared `email_queue`; that call resolves because worker.php loads
 * every job file before the loop starts, so declaration order between files does
 * not matter.
 *
 * Declares only functions. No top-level statements: this is loaded while the worker
 * is still wiring itself up, before $redis exists.
 */

/*
|--------------------------------------------------------------------------
| Network-change verification code
|--------------------------------------------------------------------------
|
| Queued by AccountsController::sendInterfaceOtp as
| {type: 'ip_change_otp', data: {...}} on the same `email_queue` every other
| notification uses, so this needs no new queue and no change to the main loop.
|
| Declared BELOW `while (true)` deliberately, which is safe because function
| declarations are hoisted at compile time. A top-level `const`/`define` is
| NOT -- execution never reaches past the loop -- which is how the six
| MEET_SMTP_* defines and later CLINIKO_JOB_MAX_AGE_SECONDS each silently
| became their own name as a string. Anything constant this function needs
| therefore stays a local variable or moves above line ~328 beside MEET_SMTP_*.
|
| Two rules about the message itself:
|
|   * It carries NO link and no button. An email whose entire purpose is to
|     authorise a change to the server's public address must not also teach the
|     reader to click things in mail claiming to be from us -- with our
|     branding, that is a phishing template.
|   * The code is never logged. The log line names the recipient and the host
|     so a delivery can be traced; anything that read back the code would put
|     it in /var/log/ringq-worker.log for its whole retention.
|
*/
function processIpChangeOtp($redis, $email, $data, $licenseConfig) {
    $to = trim((string)($data['email'] ?? ''));
    $code = (string)($data['code'] ?? '');

    if ($to === '' || $code === '') {
        echo "[IP OTP] dropping job with no recipient or no code\n";
        return;
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = strstr($to, '@', true) ?: 'there';
    }

    $fqdn = (string)($data['fqdn'] ?? 'your server');
    $newInterface = (string)($data['newInterface'] ?? '');
    $newPublicIp = (string)($data['newPublicIp'] ?? '');
    $currentPublicIp = (string)($data['currentPublicIp'] ?? '');
    $ttlMinutes = intval($data['ttlMinutes'] ?? 5);
    if ($ttlMinutes < 1) $ttlMinutes = 5;

    $details = [['Server', htmlspecialchars($fqdn)], ['New public IP', htmlspecialchars($newPublicIp)]];
    if ($currentPublicIp !== '' && $currentPublicIp !== $newPublicIp) {
        $details[] = ['Replacing', htmlspecialchars($currentPublicIp)];
    }
    if ($newInterface !== '') {
        $details[] = ['Internal interface', htmlspecialchars($newInterface)];
    }

    $html = meet_mailShell([
        'title' => 'Network change verification code',
        'intro' => meet_mailIntro(
            $name,
            'Someone signed in to <strong>' . htmlspecialchars($fqdn) . '</strong> is changing the network settings this server presents to the internet. '
            . 'Enter the code below in the Network window to apply it.'
        ),
        'rows' => '',
        'after' => actionOtpCodePanel($code, 'This code expires in ' . $ttlMinutes . ' minutes and can be used once.')
            . actionOtpDetailsTable('Change details', $details)
            . actionOtpWarning('<strong>Not you?</strong> Do not enter the code. Changing these settings can disconnect every phone registered to this server, so tell your administrator instead.'),
        'recipient' => $to,
        'preheader' => 'Your RingQ verification code is ' . $code . '.',
    ]);

    $result = meet_deliver($email, $to, $code . ' is your verification code for a network change on ' . $fqdn, 'custom', $html, null, $licenseConfig);

    // Recipient and host only -- never the code.
    echo "[IP OTP] code sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'unknown') . "\n";
}

function processAclChangeOtp($redis, $email, $data, $licenseConfig) {
    $to = trim((string)($data['email'] ?? ''));
    $code = (string)($data['code'] ?? '');

    if ($to === '' || $code === '') {
        echo "[ACL OTP] dropping job with no recipient or no code\n";
        return;
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = strstr($to, '@', true) ?: 'there';
    }

    $fqdn = (string)($data['fqdn'] ?? 'your server');
    $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
    $changeCount = intval($data['changeCount'] ?? count($changes));
    $ttlMinutes = intval($data['ttlMinutes'] ?? 5);
    if ($ttlMinutes < 1) $ttlMinutes = 5;

    $details = [['Server', htmlspecialchars($fqdn)], ['Changes', htmlspecialchars((string)$changeCount)]];

    $list = '';
    foreach ($changes as $line) {
        $list .= '<li style="margin: 0 0 4px 0;">' . htmlspecialchars((string)$line) . '</li>';
    }
    if ($changeCount > count($changes)) {
        $list .= '<li style="margin: 0 0 4px 0;">and ' . ($changeCount - count($changes)) . ' more</li>';
    }
    if ($list !== '') {
        $details[] = ['What changes', '<ul style="margin: 0 0 0 16px; padding: 0; font-weight: normal;">' . $list . '</ul>'];
    }

    $html = meet_mailShell([
        'title' => 'ACL change verification code',
        'intro' => meet_mailIntro(
            $name,
            'Someone signed in to <strong>' . htmlspecialchars($fqdn) . '</strong> is changing the ACL lists that decide which IP addresses this server trusts. '
            . 'Enter the code below in the Network window to apply these changes.'
        ),
        'rows' => '',
        'after' => actionOtpCodePanel($code, 'This code expires in ' . $ttlMinutes . ' minutes and can be used once.')
            . actionOtpDetailsTable('Change details', $details)
            . actionOtpWarning('<strong>Not you?</strong> Do not enter the code. A wrong ACL can stop your carriers reaching this server, so tell your administrator instead.'),
        'recipient' => $to,
        'preheader' => 'Your RingQ verification code is ' . $code . '.',
    ]);

    $result = meet_deliver($email, $to, $code . ' is your verification code for an ACL change on ' . $fqdn, 'custom', $html, null, $licenseConfig);

    echo "[ACL OTP] code sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'unknown') . "\n";
}

/**
 * The code itself, as the first thing under the greeting: a small caps label,
 * the digits large and selectable, and the expiry line inside the same panel
 * so the reader sees how long they have without hunting for it.
 */
function actionOtpCodePanel($code, $expiryText) {
    $c = meet_mailColors();
    return '
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 4px 0 26px 0; border-collapse: separate;">
                            <tr>
                                <td class="m-chip" align="center" style="background-color: ' . $c['chipBg'][0] . '; border-top: 4px solid ' . $c['accent'][0] . '; border-radius: 10px; padding: 20px 16px 18px 16px; text-align: center;">
                                    <p class="m-muted" style="font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; color: ' . $c['muted'][0] . '; margin: 0 0 10px 0;">Your verification code</p>
                                    <p class="m-head" style="font-family: Consolas, Menlo, Monaco, \'Courier New\', monospace; font-size: 36px; line-height: 44px; font-weight: bold; letter-spacing: 10px; color: ' . $c['heading'][0] . '; margin: 0; padding-left: 10px; -webkit-user-select: all; user-select: all;">' . htmlspecialchars($code) . '</p>
                                    <p class="m-muted" style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 18px; color: ' . $c['muted'][0] . '; margin: 10px 0 0 0;">' . $expiryText . '</p>
                                </td>
                            </tr>
                        </table>';
}

/**
 * Label / value pairs as a plain two-column table with hairline rules between
 * rows. $rows is a list of [label, valueHtml]; values must already be escaped.
 */
function actionOtpDetailsTable($heading, array $rows) {
    $c = meet_mailColors();
    $out = '
                        <p class="m-head" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: bold; color: ' . $c['heading'][0] . '; margin: 0 0 8px 0;">' . $heading . '</p>
                        <table role="presentation" class="m-rule" width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid ' . $c['rule'][0] . '; border-radius: 8px; border-collapse: separate; border-spacing: 0;">';
    $last = count($rows) - 1;
    foreach (array_values($rows) as $i => $r) {
        $line = $i < $last ? 'border-bottom: 1px solid ' . $c['rule'][0] . ';' : '';
        $out .= '
                            <tr>
                                <td class="m-muted m-rule" width="38%" style="width: 38%; padding: 11px 14px; ' . $line . ' font-family: Arial, Helvetica, sans-serif; font-size: 13px; color: ' . $c['muted'][0] . '; vertical-align: top;">' . $r[0] . '</td>
                                <td class="m-head m-rule" style="padding: 11px 14px; ' . $line . ' font-family: Arial, Helvetica, sans-serif; font-size: 14px; font-weight: bold; color: ' . $c['heading'][0] . '; vertical-align: top; word-break: break-word;">' . $r[1] . '</td>
                            </tr>';
    }
    return $out . '
                        </table>';
}

/** The "not you?" line, set apart from the details so it is not skimmed past. */
function actionOtpWarning($html) {
    $c = meet_mailColors();
    return '
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 22px 0 0 0; border-collapse: separate;">
                            <tr>
                                <td class="m-chip" style="background-color: ' . $c['chipBg'][0] . '; border-left: 4px solid #D97706; border-radius: 6px; padding: 12px 14px;">
                                    <p class="m-text" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 20px; color: ' . $c['text'][0] . '; margin: 0;">' . $html . '</p>
                                </td>
                            </tr>
                        </table>';
}

function processUpdateOtp($redis, $email, $data, $licenseConfig) {
    $to = trim((string)($data['email'] ?? ''));
    $code = (string)($data['code'] ?? '');

    if ($to === '' || $code === '') {
        echo "[Update OTP] dropping job with no recipient or no code\n";
        return;
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = strstr($to, '@', true) ?: 'there';
    }

    $fqdn = (string)($data['fqdn'] ?? 'your server');
    $autoUpdate = ($data['action'] ?? '') === 'autoupdate-on';
    $currentVersion = (string)($data['currentVersion'] ?? '');
    $targetVersion = (string)($data['targetVersion'] ?? '');
    $ttlMinutes = intval($data['ttlMinutes'] ?? 5);
    if ($ttlMinutes < 1) $ttlMinutes = 5;

    $details = [['Server', htmlspecialchars($fqdn)]];
    if ($currentVersion !== '') {
        $details[] = ['Current version', htmlspecialchars($currentVersion)];
    }
    if (!$autoUpdate && $targetVersion !== '' && $targetVersion !== $currentVersion) {
        $details[] = ['Updating to', htmlspecialchars($targetVersion)];
    }

    if ($autoUpdate) {
        $title = 'Automatic updates verification code';
        $what = 'is turning on automatic updates. Future updates will install on this server without asking.';
        $subject = $code . ' is your verification code to turn on automatic updates on ' . $fqdn;
        $warning = '<strong>Not you?</strong> Do not enter the code, and tell your administrator.';
    } else {
        $title = 'Update verification code';
        $what = 'is updating this server. The update restarts services and can briefly disconnect calls.';
        $subject = $code . ' is your verification code to update ' . $fqdn;
        $warning = '<strong>Not you?</strong> Do not enter the code. An update restarts services and can disconnect calls in progress, so tell your administrator instead.';
    }

    $html = meet_mailShell([
        'title' => $title,
        'intro' => meet_mailIntro(
            $name,
            'Someone signed in to <strong>' . htmlspecialchars($fqdn) . '</strong> ' . $what . ' '
            . 'Enter the code below to continue.'
        ),
        'rows' => '',
        'after' => actionOtpCodePanel($code, 'This code expires in ' . $ttlMinutes . ' minutes and can be used once.')
            . actionOtpDetailsTable($autoUpdate ? 'Change details' : 'Update details', $details)
            . actionOtpWarning($warning),
        'recipient' => $to,
        'preheader' => 'Your RingQ verification code is ' . $code . '.',
    ]);

    $result = meet_deliver($email, $to, $subject, 'custom', $html, null, $licenseConfig);

    echo "[Update OTP] code sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'unknown') . "\n";
}

function processSipProfileOtp($redis, $email, $data, $licenseConfig) {
    $to = trim((string)($data['email'] ?? ''));
    $code = (string)($data['code'] ?? '');

    if ($to === '' || $code === '') {
        echo "[SIP OTP] dropping job with no recipient or no code\n";
        return;
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = strstr($to, '@', true) ?: 'there';
    }

    $fqdn = (string)($data['fqdn'] ?? 'your server');
    $op = intval($data['op'] ?? 0);
    $actions = [1 => 'Create', 2 => 'Edit', 3 => 'Delete'];
    $actionLabel = $actions[$op] ?? 'Change';
    $profileName = (string)($data['profileName'] ?? '');
    $ttlMinutes = intval($data['ttlMinutes'] ?? 5);
    if ($ttlMinutes < 1) $ttlMinutes = 5;

    $details = [
        ['Server', htmlspecialchars($fqdn)],
        ['Action', htmlspecialchars($actionLabel . ' SIP profile')],
    ];
    if ($profileName !== '') {
        $details[] = ['Profile', htmlspecialchars($profileName)];
    }
    if ($op !== 3) {
        foreach ([['SIP IP', 'sipIp'], ['SIP external IP', 'sipExtIp'], ['SIP port', 'sipPort']] as $field) {
            $value = trim((string)($data[$field[1]] ?? ''));
            if ($value !== '' && $value !== '0') {
                $details[] = [$field[0], htmlspecialchars($value)];
            }
        }
    }

    $html = meet_mailShell([
        'title' => 'SIP profile verification code',
        'intro' => meet_mailIntro(
            $name,
            'Someone signed in to <strong>' . htmlspecialchars($fqdn) . '</strong> is changing the SIP profiles this server uses. '
            . 'Enter the code below in the SIP Profile window to apply it.'
        ),
        'rows' => '',
        'after' => actionOtpCodePanel($code, 'This code expires in ' . $ttlMinutes . ' minutes and can be used once.')
            . actionOtpDetailsTable('Change details', $details)
            . actionOtpWarning('<strong>Not you?</strong> Do not enter the code. Changing a SIP profile can disconnect every phone and trunk that uses it, so tell your administrator instead.'),
        'recipient' => $to,
        'preheader' => 'Your RingQ verification code is ' . $code . '.',
    ]);

    $result = meet_deliver($email, $to, $code . ' is your verification code for a SIP profile change on ' . $fqdn, 'custom', $html, null, $licenseConfig);

    echo "[SIP OTP] code sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'unknown') . "\n";
}

/*
|--------------------------------------------------------------------------
| Support access verification code
|--------------------------------------------------------------------------
|
| Queued by McpController::grantsAction when an owner gives RingQ support a
| time-boxed, read-only view of this server.
|
| The message names what is being granted and for how long, because this is
| the one code in this file that hands an outsider a way in: the reader has to
| be able to tell a grant they asked for from one somebody else requested.
| Like its neighbours it carries no link and never logs the code.
|
*/
function processMcpGrantOtp($redis, $email, $data, $licenseConfig) {
    $to = trim((string)($data['email'] ?? ''));
    $code = (string)($data['code'] ?? '');

    if ($to === '' || $code === '') {
        echo "[MCP OTP] dropping job with no recipient or no code\n";
        return;
    }

    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = strstr($to, '@', true) ?: 'there';
    }

    $fqdn = (string)($data['fqdn'] ?? 'your server');
    $label = (string)($data['label'] ?? '');
    $engineer = (string)($data['engineer'] ?? '');
    $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];
    $hours = (float)($data['hours'] ?? 0);
    $ttlMinutes = intval($data['ttlMinutes'] ?? 5);
    if ($ttlMinutes < 1) $ttlMinutes = 5;

    $forHowLong = $hours >= 1
        ? (rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' hour' . ($hours > 1 ? 's' : ''))
        : intval($hours * 60) . ' minutes';

    $details = [['Server', htmlspecialchars($fqdn)]];
    if ($engineer !== '') {
        $details[] = ['Engineer', htmlspecialchars($engineer)];
    }
    if ($label !== '') {
        $details[] = ['Reference', htmlspecialchars($label)];
    }
    $details[] = ['Valid for', htmlspecialchars($forHowLong)];
    $details[] = ['Access', 'Read-only diagnostics'];
    // The reader is authorising specific changes, so name them. "Some access"
    // is not what they are agreeing to.
    if (count($actions) === 0) {
        $details[] = ['Changes allowed', 'None &mdash; this engineer can only look.'];
    } else {
        $pretty = array_map(function ($a) {
            return htmlspecialchars(str_replace('_', ' ', (string)$a));
        }, $actions);
        $details[] = ['Changes allowed', implode(', ', $pretty)];
    }

    $html = meet_mailShell([
        'title' => 'Support access verification code',
        'intro' => meet_mailIntro(
            $name,
            'Someone signed in to <strong>' . htmlspecialchars($fqdn) . '</strong> is giving RingQ support '
            . 'read-only access to this server. Enter the code below in the Support Access window to allow it.'
        ),
        'rows' => '',
        'after' => actionOtpCodePanel($code, 'This code expires in ' . $ttlMinutes . ' minutes and can be used once.')
            . actionOtpDetailsTable('Access details', $details)
            . meet_mailNote('Support can read settings, registrations, call records and service health. '
                . 'They cannot change anything, and they cannot hear recordings or read messages.')
            . actionOtpWarning('<strong>Not you?</strong> Do not enter the code, and tell your administrator. '
                . 'Access can be withdrawn at any time from the Support Access window.'),
        'recipient' => $to,
        'preheader' => 'Your RingQ verification code is ' . $code . '.',
    ]);

    $result = meet_deliver($email, $to, $code . ' is your verification code for support access on ' . $fqdn, 'custom', $html, null, $licenseConfig);

    echo "[MCP OTP] code sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'unknown') . "\n";
}

/*
|--------------------------------------------------------------------------
| Public IP followed automatically
|--------------------------------------------------------------------------
|
| Queued by app/scripts/public-ip-watch.sh after the ISP changed this
| server's public address and the SIP profiles were reloaded on it. A notice,
| not a request: nothing to enter, no link. The recipient is the licence owner
| from the cached licence check, since no one is signed in when this happens.
|
*/
function processPublicIpChanged($redis, $email, $data, $licenseConfig) {
    $to = '';
    foreach ((array)$redis->keys('api_cache:licensecheckuser:*') as $key) {
        $cached = json_decode((string)$redis->get($key), true);
        $to = trim((string)($cached['data']['email'] ?? ''));
        if ($to !== '') break;
    }
    if ($to === '') {
        echo "[Public IP] no licence owner email cached, notice not sent\n";
        return;
    }

    $fqdn = (string)($licenseConfig['data']['fqdn'] ?? 'your server');
    $oldIp = (string)($data['oldIp'] ?? '');
    $newIp = (string)($data['newIp'] ?? '');
    $dnsMoved = ($data['dns'] ?? '') === 'updated';

    $details = [['Server', htmlspecialchars($fqdn)], ['New public IP', htmlspecialchars($newIp)]];
    if ($oldIp !== '' && $oldIp !== $newIp) {
        $details[] = ['Previous public IP', htmlspecialchars($oldIp)];
    }
    $details[] = ['Server name', $dnsMoved ? 'Now points at the new IP' : 'NOT moved yet, retrying every minute'];

    $html = meet_mailShell([
        'title' => 'Public IP changed',
        'intro' => meet_mailIntro(
            strstr($to, '@', true) ?: 'there',
            'The internet provider changed the public IP of <strong>' . htmlspecialchars($fqdn) . '</strong>. '
            . 'The server has moved its phone and call settings to the new address, so phones, apps and SIP trunks reconnect on their own.'
        ),
        'rows' => '',
        'after' => actionOtpDetailsTable('Change details', $details)
            . actionOtpWarning('If your SIP trunk provider allows your server by IP address, give them the new IP. '
                . 'For a phone system, a static IP from your internet provider avoids these changes.'),
        'recipient' => $to,
        'preheader' => 'New public IP ' . $newIp . ' on ' . $fqdn . '.',
    ]);

    $result = meet_deliver($email, $to, 'Public IP changed on ' . $fqdn, 'custom', $html, null, $licenseConfig);

    echo "[Public IP] notice sent to {$to} for {$fqdn} via " . ($result['via'] ?? 'unknown') . "\n";
}
