<?php
declare(strict_types=1);

// Unit checks for the Freshdesk integration's pure logic (no DB, no network).
// Run: php tests/freshdesk/freshdesk_unit_test.php   (needs the openssl extension)

use Library\Freshdesk\FreshdeskClient;
use Library\Freshdesk\FreshdeskCrypto;
use Library\Freshdesk\FreshdeskException;
use Library\Freshdesk\FreshdeskService;

require_once __DIR__ . '/../app/library/Freshdesk/FreshdeskService.php';

$failures = 0;
$count = 0;
function check(string $name, bool $cond): void
{
    global $failures, $count;
    $count++;
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: $name\n");
    }
}

// --- domain normalisation -------------------------------------------------
check('domain from bare name', FreshdeskClient::normalizeDomain('cal4care') === 'cal4care.freshdesk.com');
check('domain from url', FreshdeskClient::normalizeDomain('https://Cal4Care.freshdesk.com/a/tickets/1') === 'cal4care.freshdesk.com');
check('domain rejects other hosts', FreshdeskClient::normalizeDomain('https://evil.example.com') === '');
check('domain rejects lookalike', FreshdeskClient::normalizeDomain('cal4care.freshdesk.com.evil.io') === '');
check('domain rejects empty', FreshdeskClient::normalizeDomain('') === '');

// --- phone matching ---------------------------------------------------------
check('same: e164 vs national (SG)', FreshdeskService::sameNumber('+65 9123 4567', '91234567'));
check('same: trunk zero (ID)', FreshdeskService::sameNumber('081234567890', '+6281234567890'));
check('same: identical digits', FreshdeskService::sameNumber('6591234567', '+65-9123-4567'));
check('not same: different numbers', !FreshdeskService::sameNumber('+6591234567', '+6591234568'));
check('not same: short suffix', !FreshdeskService::sameNumber('4567', '+6591234567'));
check('not same: empty', !FreshdeskService::sameNumber('', '+6591234567'));
check('external number', FreshdeskService::isExternalNumber('+6591234567'));
check('extension is internal', !FreshdeskService::isExternalNumber('1004'));

$v = FreshdeskService::phoneVariants('91234567', '65');
check('variants cc: e164 first', ($v[0] ?? '') === '+6591234567');
check('variants cc: includes national', in_array('91234567', $v, true));
check('variants cc: includes digits intl', in_array('6591234567', $v, true));
$v = FreshdeskService::phoneVariants('+6591234567', '65');
check('variants from e164 include national', in_array('91234567', $v, true) && in_array('+6591234567', $v, true));
$v = FreshdeskService::phoneVariants('+6591234567', '');
check('variants no cc', in_array('+6591234567', $v, true) && in_array('6591234567', $v, true));
check('variants skip extensions', FreshdeskService::phoneVariants('1004', '65') === []);
$v = FreshdeskService::phoneVariants("91234567' OR name:'x", '65');
check('variants never carry quotes', count(array_filter($v, function ($x) { return strpos($x, "'") !== false; })) === 0);

// --- settings validation ----------------------------------------------------
list($clean, $errors) = FreshdeskService::validateConfigInput([
    'domain' => 'https://cal4care.freshdesk.com/', 'api_key' => 'abcDEF123456789xyz', 'enabled' => 'true',
    'ticket_status' => '9', 'ticket_priority' => 3, 'unmatched_calls' => 'create_contact', 'screenpop_target' => 'evil',
    'country_code' => '+65', 'timezone' => 'Asia/Singapore', 'recording_link' => '1', 'bogus' => 'x',
]);
check('valid config has no errors', $errors === []);
check('domain normalised', $clean['domain'] === 'cal4care.freshdesk.com');
check('enabled parsed', $clean['enabled'] === true);
check('bad ticket status falls back', $clean['settings']['ticket_status'] === 4);
check('priority kept', $clean['settings']['ticket_priority'] === 3);
check('unmatched kept', $clean['settings']['unmatched_calls'] === 'create_contact');
check('screenpop target whitelisted', $clean['settings']['screenpop_target'] === 'contact_tab');
check('country code stripped of +', $clean['settings']['country_code'] === '65');
check('recording link bool', $clean['settings']['recording_link'] === true);
check('unknown settings dropped', !array_key_exists('bogus', $clean['settings']));
list(, $errors) = FreshdeskService::validateConfigInput(['domain' => 'nope.example.com', 'api_key' => 'bad key!', 'timezone' => 'Mars/Base']);
check('invalid config reports 3 errors', count($errors) === 3);

// --- required ticket fields ---------------------------------------------------
$w = FreshdeskService::requiredTicketFields([
    ['name' => 'requester', 'label' => 'Search a requester', 'required_for_agents' => true],
    ['name' => 'cf_region', 'label' => 'Region', 'required_for_agents' => true],
    ['name' => 'cf_root_cause', 'label' => 'Root cause', 'required_for_closure' => true],
    ['name' => 'cf_optional', 'label' => 'Optional', 'required_for_agents' => false],
]);
check('required ticket fields', $w === ['Region', 'Root cause (when resolving)']);

// --- call reference ---------------------------------------------------------
check('call ref', FreshdeskService::callRef('7F0C1A2B-3C4D-4E5F-8a9b-0c1d2e3f4a5b') === 'RQ7f0c1a2b3c');

// --- ticket body ------------------------------------------------------------
$svc = (new ReflectionClass(FreshdeskService::class))->newInstanceWithoutConstructor();
$journal = [
    'journal_uuid' => '11111111-2222-3333-4444-555555555555', 'local_call_id' => '7f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b',
    'call_direction' => 'inbound', 'caller_number' => '+6591234567', 'destination_number' => '1004', 'agent_extension' => '1004',
    'agent_name' => 'Pat <script>', 'call_status' => 'completed', 'started_at' => '2026-10-23T03:00:00Z',
    'answered_at' => '2026-10-23T03:00:05Z', 'ended_at' => '2026-10-23T03:01:10Z', 'duration' => 70, 'talk_duration' => 65,
    'notes' => "Wants a quote\n<b>bold</b>", 'disposition' => 'Follow-up needed', 'recording_status' => 'waiting',
    'freshdesk_contact_id' => '501',
];
$settings = FreshdeskService::SETTINGS_DEFAULTS;
$settings['timezone'] = 'Asia/Singapore';
$body = $svc->ticketBody($journal, ['name' => 'Jane Tan'], $settings, 4, null);
check('ticket source is Phone (3)', $body['source'] === 3);
check('ticket requester', $body['requester_id'] === 501);
check('ticket status', $body['status'] === 4);
check('ticket subject has ref', strpos($body['subject'], 'RQ7f0c1a2b3c') !== false);
check('ticket subject has contact and talk time', strpos($body['subject'], 'Inbound call from Jane Tan (+6591234567) - Answered 1:05') === 0);
check('ticket tag has ref', in_array('rq7f0c1a2b3c', $body['tags'], true));
check('description escapes html', strpos($body['description'], '<script>') === false && strpos($body['description'], '&lt;script&gt;') !== false);
check('description has local time', strpos($body['description'], '23 Oct 2026, 11:00:00') !== false);
check('description has duration', strpos($body['description'], 'Talk time:</strong> 0:01:05') !== false);
check('description has call id', strpos($body['description'], '7f0c1a2b-3c4d-4e5f-8a9b-0c1d2e3f4a5b') !== false);
check('description has disposition', strpos($body['description'], 'Follow-up needed') !== false);
check('description mentions pending recording', strpos($body['description'], 'private note when it is ready') !== false);
$journal['call_direction'] = 'outbound';
$journal['call_status'] = 'no_answer';
$journal['talk_duration'] = 0;
$journal['destination_number'] = '+6591234567';
$body = $svc->ticketBody($journal, null, $settings, 2, 'remark');
check('outbound unanswered subject', strpos($body['subject'], 'Outbound call to +6591234567 - No answer - RQ') === 0);
check('remark included', strpos($body['description'], 'remark') !== false);

// --- internal calls (between extensions) ------------------------------------------
check('internal calls logged by default', FreshdeskService::SETTINGS_DEFAULTS['log_internal_calls'] === true);
list($clean) = FreshdeskService::validateConfigInput(['domain' => 'x', 'log_internal_calls' => 'false']);
check('internal calls can be turned off', $clean['settings']['log_internal_calls'] === false);
$ij = $journal;
$ij['call_direction'] = 'inbound';
$ij['caller_number'] = '1004';
$ij['destination_number'] = '1001';
$ij['agent_extension'] = '1001';
$ij['call_status'] = 'completed';
$ij['talk_duration'] = 13;
$body = $svc->ticketBody($ij, ['name' => 'Sathiyan developer'], $settings, 4, null);
check('internal subject names colleague and extension', strpos($body['subject'], 'Inbound call from Sathiyan developer (ext 1004) - Answered 0:13') === 0);
check('internal caller shown as extension', strpos($body['description'], 'Caller number:</strong> Extension 1004') !== false);
check('internal direction marked', strpos($body['description'], 'internal call between extensions') !== false);
check('internal tag', in_array('internal-call', $body['tags'], true));
check('external ticket has no internal tag', !in_array('internal-call', $svc->ticketBody($journal, null, $settings, 2, null)['tags'], true));

if (!class_exists('Phalcon\Db\Enum')) {
    eval('namespace Phalcon\Db; class Enum { const FETCH_ASSOC = 2; }'); // stand-in when Phalcon is not installed
}
class FakeExtDb {
    public $rows = [];
    public function fetchOne($sql, $mode = null, $binds = []) { return $this->rows[$binds['n'] ?? ''] ?? null; }
}
$ref = new ReflectionClass(FreshdeskService::class);
$extSvc = $ref->newInstanceWithoutConstructor();
foreach (['db' => new FakeExtDb(), 'domainUuid' => '11111111-2222-3333-4444-555555555555'] as $p => $v) {
    $prop = $ref->getProperty($p);
    $prop->setAccessible(true);
    $prop->setValue($extSvc, $v);
}
$dbProp = $ref->getProperty('db');
$dbProp->setAccessible(true);
$dbProp->getValue($extSvc)->rows = [
    '1004' => ['extension' => '1004', 'effective_caller_id_name' => 'Sathiyan  developer', 'user_email' => 'Sathiyan@Example.COM'],
    '1022' => ['extension' => '1022', 'effective_caller_id_name' => '1022', 'user_email' => 'not-an-email'],
];
$p = $extSvc->extensionPerson('1004');
check('extension person name and email', $p === ['extension' => '1004', 'name' => 'Sathiyan developer', 'email' => 'sathiyan@example.com']);
$p = $extSvc->extensionPerson('1022');
check('extension without name or email', $p === ['extension' => '1022', 'name' => 'Extension 1022', 'email' => '']);
check('unknown number is not an extension', $extSvc->extensionPerson('*97') === null);
check('colleague external id', FreshdeskService::extensionExternalId('1004') === 'ringq-ext-1004');
check('colleague external id strips odd characters', FreshdeskService::extensionExternalId("10'04") === 'ringq-ext-1004');
check('bad characters rejected', $extSvc->extensionPerson("1004' OR 1=1") === null);

// --- exception helpers --------------------------------------------------------
$e = new FreshdeskException('x', 'validation', 400, null, [['field' => 'cf_region', 'code' => 'missing_field']]);
check('missing field detected', $e->hasMissingField());
check('rate limit is transient', (new FreshdeskException('x', 'rate_limited'))->isTransient());
check('auth failure is not transient', !(new FreshdeskException('x', 'auth_failed'))->isTransient());

// --- crypto (dedicated key file, so no Phalcon needed) ------------------------
if (function_exists('openssl_encrypt')) {
    $keyFile = tempnam(sys_get_temp_dir(), 'fdk');
    file_put_contents($keyFile, base64_encode(random_bytes(32)));
    putenv('FRESHDESK_KEY_FILE=' . $keyFile);
    $enc = FreshdeskCrypto::encrypt('abcDEF123456789xyz');
    check('ciphertext format', strpos($enc, 'v1:') === 0 && strpos($enc, 'abcDEF') === false);
    check('decrypt round trip', FreshdeskCrypto::decrypt($enc) === 'abcDEF123456789xyz');
    $bin = base64_decode(substr($enc, 3));
    $bin[strlen($bin) - 1] = chr(ord($bin[strlen($bin) - 1]) ^ 1);
    $tampered = false;
    try {
        FreshdeskCrypto::decrypt('v1:' . base64_encode($bin));
    } catch (FreshdeskException $ex) {
        $tampered = $ex->reason === 'decrypt_failed';
    }
    check('tampering detected', $tampered);
    $sig = FreshdeskCrypto::sign('uuid|0|salt');
    check('signature verifies', FreshdeskCrypto::verify('uuid|0|salt', $sig));
    check('signature bound to salt', !FreshdeskCrypto::verify('uuid|0|other', $sig));
    @unlink($keyFile);
} else {
    fwrite(STDERR, "SKIP: crypto checks (openssl extension not loaded)\n");
}

echo ($count - $failures) . "/$count checks passed\n";
exit($failures > 0 ? 1 : 0);
