<?php
declare(strict_types=1);

// Live check against a REAL Freshdesk account, using the integration's own
// client and ticket/phone logic (no RingQ database needed).
//
// Read-only by default:
//   FRESHDESK_DOMAIN=yourcompany.freshdesk.com FRESHDESK_API_KEY=... [FRESHDESK_TEST_PHONE=+6591234567] [FRESHDESK_CC=65] \
//     php tests/freshdesk/freshdesk_live_check.php
//
// With --write it also creates ONE test ticket on FRESHDESK_TEST_CONTACT_ID and
// adds a private note with a 1-second generated WAV attached, then reads them
// back (exactly what RingQ does after a call). Delete the ticket afterwards.
//
// The API key is read from the environment only and is never printed.

use Library\Freshdesk\FreshdeskClient;
use Library\Freshdesk\FreshdeskException;
use Library\Freshdesk\FreshdeskService;

require_once __DIR__ . '/../app/library/Freshdesk/FreshdeskService.php';

$domain = FreshdeskClient::normalizeDomain((string) getenv('FRESHDESK_DOMAIN'));
$key = trim((string) getenv('FRESHDESK_API_KEY'));
$phone = trim((string) getenv('FRESHDESK_TEST_PHONE'));
$cc = trim((string) getenv('FRESHDESK_CC'));
$write = in_array('--write', $argv, true);
if ($domain === '' || $key === '') {
    fwrite(STDERR, "Set FRESHDESK_DOMAIN and FRESHDESK_API_KEY.\n");
    exit(2);
}

function step(string $name, callable $fn)
{
    try {
        $out = $fn();
        echo "OK   $name" . ($out !== null && $out !== '' ? ": $out" : '') . "\n";
        return true;
    } catch (FreshdeskException $e) {
        echo "FAIL $name: [{$e->reason}] {$e->getMessage()}\n";
        return false;
    }
}

$client = new FreshdeskClient($domain, $key);
echo "Freshdesk: $domain\n";

$ok = step('auth (GET agents/me)', function () use ($client) {
    $me = $client->get('agents/me');
    return 'agent #' . ($me['id'] ?? '?') . ' ' . ($me['contact']['name'] ?? '');
});
if (!$ok) {
    exit(1);
}
step('wrong key is rejected (401)', function () use ($domain) {
    try {
        (new FreshdeskClient($domain, 'invalidkey000000000000'))->get('agents/me');
    } catch (FreshdeskException $e) {
        if ($e->reason === 'auth_failed') {
            return 'rejected as expected';
        }
        throw $e;
    }
    throw new FreshdeskException('A wrong key was accepted.', 'validation');
});
step('account (GET account, may need admin)', function () use ($client) {
    $a = $client->get('account');
    return ($a['account_name'] ?? '?') . ' / plan ' . ($a['tier_type'] ?? '?');
});
step('required ticket fields (GET ticket_fields)', function () use ($client) {
    $w = FreshdeskService::requiredTicketFields($client->get('ticket_fields'));
    return $w ? implode(', ', $w) : 'none';
});

if ($phone !== '') {
    $variants = FreshdeskService::phoneVariants($phone, $cc);
    echo 'Phone formats tried: ' . implode(' | ', $variants) . "\n";
    step('contact search (filter query)', function () use ($client, $variants, $phone) {
        $terms = [];
        foreach ($variants as $v) {
            $terms[] = "phone:'" . $v . "'";
            $terms[] = "mobile:'" . $v . "'";
        }
        $res = $client->get('search/contacts', ['query' => '"' . implode(' OR ', $terms) . '"']);
        $hits = array_filter((array) ($res['results'] ?? []), function ($c) use ($phone) {
            return FreshdeskService::sameNumber((string) ($c['phone'] ?? ''), $phone) || FreshdeskService::sameNumber((string) ($c['mobile'] ?? ''), $phone);
        });
        return count($hits) . ' match(es): ' . implode(', ', array_map(function ($c) { return '#' . $c['id'] . ' ' . $c['name']; }, $hits));
    });
    step('contact search (live list filter)', function () use ($client, $variants) {
        $found = [];
        foreach (array_slice($variants, 0, 2) as $v) {
            foreach (['phone', 'mobile'] as $f) {
                foreach ((array) $client->get('contacts', [$f => $v]) as $c) {
                    $found[$c['id']] = '#' . $c['id'] . ' ' . $c['name'];
                }
            }
        }
        return count($found) . ' match(es): ' . implode(', ', $found);
    });
}

if ($write) {
    $contactId = trim((string) getenv('FRESHDESK_TEST_CONTACT_ID'));
    if (!preg_match('/^\d+$/', $contactId)) {
        fwrite(STDERR, "--write needs FRESHDESK_TEST_CONTACT_ID (a test contact).\n");
        exit(2);
    }
    $svc = (new ReflectionClass(FreshdeskService::class))->newInstanceWithoutConstructor();
    $localId = bin2hex(random_bytes(16));
    $now = time();
    $j = [
        'journal_uuid' => '00000000-0000-4000-8000-000000000000', 'local_call_id' => $localId, 'call_direction' => 'outbound',
        'caller_number' => '1004', 'destination_number' => $phone ?: '+6500000000', 'agent_extension' => '1004', 'agent_name' => 'RingQ live check',
        'call_status' => 'completed', 'started_at' => gmdate('c', $now - 70), 'answered_at' => gmdate('c', $now - 65), 'ended_at' => gmdate('c', $now),
        'duration' => 70, 'talk_duration' => 65, 'notes' => 'Live check from the RingQ Freshdesk integration. Safe to delete.',
        'disposition' => 'Resolved', 'recording_status' => 'waiting', 'freshdesk_contact_id' => $contactId,
    ];
    $settings = FreshdeskService::SETTINGS_DEFAULTS;
    $ticketId = null;
    step('create call ticket (POST tickets, source Phone)', function () use ($client, $svc, $j, $settings, &$ticketId) {
        $t = $client->post('tickets', $svc->ticketBody($j, ['name' => 'Test contact'], $settings, 2, null));
        $ticketId = (string) $t['id'];
        return "ticket #$ticketId, source " . ($t['source'] ?? '?') . ', subject "' . ($t['subject'] ?? '') . '"';
    });
    if ($ticketId) {
        $wav = tempnam(sys_get_temp_dir(), 'fdw');
        $rate = 8000;
        $pcm = '';
        for ($i = 0; $i < $rate; $i++) {
            $pcm .= pack('v', (int) (8000 * sin(2 * M_PI * 440 * $i / $rate)));
        }
        file_put_contents($wav, 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm);
        step('recording note with attachment (multipart POST notes)', function () use ($client, $ticketId, $wav, $localId) {
            $n = $client->postMultipart('tickets/' . $ticketId . '/notes', [
                'body' => '<p><strong>RingQ recording</strong> - ' . FreshdeskService::callRef($localId) . '</p>', 'private' => true,
            ], ['path' => $wav, 'name' => 'call-recording.wav', 'mime' => 'audio/wav']);
            $att = $n['attachments'][0] ?? null;
            if (!$att) {
                throw new FreshdeskException('Note created without the attachment.', 'validation');
            }
            return 'note #' . $n['id'] . ', private=' . json_encode($n['private'] ?? null) . ', attachment ' . $att['name'] . ' (' . $att['size'] . ' bytes)';
        });
        @unlink($wav);
        step('read back (GET tickets/:id/conversations)', function () use ($client, $ticketId) {
            $list = (array) $client->get('tickets/' . $ticketId . '/conversations');
            return count($list) . ' note(s)';
        });
        step('duplicate recovery by subject reference', function () use ($client, $contactId, $localId, $ticketId) {
            $list = (array) $client->get('tickets', ['requester_id' => $contactId, 'updated_since' => gmdate('Y-m-d\TH:i:s\Z', time() - 900), 'order_by' => 'created_at']);
            foreach ($list as $t) {
                if (stripos((string) $t['subject'], FreshdeskService::callRef($localId)) !== false) {
                    return 'found #' . $t['id'] . ((string) $t['id'] === $ticketId ? ' (same ticket)' : ' (DIFFERENT)');
                }
            }
            throw new FreshdeskException('Ticket not found by reference.', 'not_found');
        });
        echo "Test ticket: https://$domain/a/tickets/$ticketId (delete it when done)\n";
    }
}
