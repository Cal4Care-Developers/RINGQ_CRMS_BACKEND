<?php
declare(strict_types=1);

use Phalcon\Mvc\View;

// Ported from mr.ringq.ai_backend's TeamsController ("MR AI changes" blocks) into this server
// as a NEW file, so the existing TeamsController is untouched. Router aliases
// (app/config/router.php) keep the URLs the web app already calls (/teams/aiconnect,
// /teams/aikbupload, /teams/resolveagent, ...) pointing at this controller.
if (!class_exists('KnowledgeBase', false)) { require_once __DIR__ . '/KnowledgeBase.php'; }
if (!class_exists('AiCallRecord', false)) { require_once __DIR__ . '/AiCallRecord.php'; }
if (!class_exists('AiAgentConnection', false)) { require_once __DIR__ . '/AiAgentConnection.php'; }

class TeamsaiController extends ControllerBase
{

/**
 * Short-circuits a CORS preflight (OPTIONS) before any action's own session check, then
 * hands every other request to ControllerBase so this app's ACL / cross-site / role checks
 * still run (the source server's ControllerBase has none of them, so its copy returned true).
 */
public function beforeExecuteRoute(\Phalcon\Mvc\Dispatcher $dispatcher)
{
    if ($this->request->getMethod() === 'OPTIONS') {
        $this->response->setStatusCode(200, 'OK');
        $this->response->send();
        return false;
    }
    return parent::beforeExecuteRoute($dispatcher);
}

// MR changes - starts
// ---------------------------------------------------------------------------------------------
// DEMO Custom CRM webhook receiver -- for testing node/custom_crm.js without a real CRM.
//
//   Webhook URL to put in Integrations -> Custom CRM:   https://<your-domain>:8443/teamsai/demowebhook
//   Read what it received (signed in):                  https://<your-domain>:8443/teamsai/demowebhooklogs
//
// demowebhook accepts any request, writes it to a log file and answers 200, so you can see exactly what the
// webhook sends per trigger. Add ?status=500 (400-599) to the URL to make it answer with that status and test
// the retry logic. Secrets are masked in the log; the log is only readable by a signed-in user or the
// internal key. Delete both actions (and their "teamsai" ACL entries) when you no longer need them.
// ---------------------------------------------------------------------------------------------

private function demoWebhookLogFile(): string
{
    $dir = '/var/www/custom_crm';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir();
    }
    return $dir . '/demo_webhook.log';
}

/** Keeps the first characters so a value can be recognised, hides the rest. */
private function demoMask($value): string
{
    $s = (string) $value;
    if ($s === '') {
        return '';
    }
    return mb_substr($s, 0, 4) . str_repeat('*', max(3, min(12, mb_strlen($s) - 4)));
}

public function demowebhookAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $raw = (string) $this->request->getRawBody();
        $body = json_decode($raw, true);
        $isJson = is_array($body);

        // Headers worth seeing; credentials are masked, never stored.
        $headers = [];
        foreach ((array) $this->request->getHeaders() as $name => $value) {
            $lower = strtolower((string) $name);
            if ($lower === 'authorization') {
                $parts = explode(' ', (string) $value, 2);
                $value = $parts[0] . ' ' . $this->demoMask($parts[1] ?? '');
            } elseif (strpos($lower, 'key') !== false || strpos($lower, 'token') !== false || strpos($lower, 'secret') !== false) {
                $value = $this->demoMask($value);
            }
            $headers[$name] = $value;
        }
        if ($isJson && isset($body['secret'])) {
            $body['secret'] = $this->demoMask($body['secret']);
        }

        $entry = [
            'received_at' => date('Y-m-d H:i:s'),
            'method'      => $this->request->getMethod(),
            'ip'          => $this->request->getClientAddress(),
            'event_type'  => $isJson ? ($body['event_type'] ?? '') : '',
            'call_id'     => $isJson ? ($body['call_id'] ?? '') : '',
            'json_valid'  => $isJson,
            'headers'     => $headers,
            'body'        => $isJson ? $body : mb_substr($raw, 0, 20000),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (strlen($line) > 200000) {
            $entry['body'] = '(body larger than 200 KB, not stored)';
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        @file_put_contents($this->demoWebhookLogFile(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        error_log('[demo-webhook] ' . $entry['received_at'] . ' event=' . $entry['event_type'] . ' call_id=' . $entry['call_id'] . ' bytes=' . strlen($raw));

        // ?status=NNN makes the receiver fail on purpose, to exercise the sender's retry logic.
        $forced = (int) $this->request->getQuery('status', 'int', 0);
        if ($forced >= 400 && $forced <= 599) {
            $this->response->setStatusCode($forced);
            return $this->response->setJsonContent(["result" => "error", "message" => "Demo webhook forced status {$forced}", "event_type" => $entry['event_type']]);
        }

        return $this->response->setJsonContent([
            "result"      => "success",
            "message"     => "Demo webhook received",
            "event_type"  => $entry['event_type'],
            "call_id"     => $entry['call_id'],
            "fields"      => $isJson ? array_keys($body) : [],
            "received_at" => $entry['received_at'],
        ]);
    } catch (\Throwable $e) {
        error_log('[demo-webhook] failed: ' . $e->getMessage());
        $this->response->setStatusCode(500);
        return $this->response->setJsonContent(["result" => "error", "message" => "Demo webhook failed"]);
    }
}

/**
 * GET /teamsai/demowebhooklogs?limit=20   (newest first, max 200)
 * Signed-in users, or the internal key. Payloads can contain transcripts and contact details, so the log is
 * not public even though the receiver is.
 */
public function demowebhooklogsAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    $this->response->setHeader("Cache-Control", "no-store");

    $internal = $this->request->getHeader("X-Internal-Key") === "RingQ-Internal-Secret-818849038148786300654846001886";
    if (!$internal && !$this->session->get("auth")) {
        $this->response->setStatusCode(401, "Unauthorized");
        return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
    }

    $limit = max(1, min(200, (int) $this->request->getQuery('limit', 'int', 20)));
    $file = $this->demoWebhookLogFile();
    $entries = [];
    if (is_readable($file)) {
        // Only the tail of the file is needed; read at most the last 2 MB.
        $size = filesize($file);
        $fh = fopen($file, 'rb');
        if ($fh) {
            if ($size > 2097152) {
                fseek($fh, -2097152, SEEK_END);
                fgets($fh); // drop the partial first line
            }
            $lines = [];
            while (($l = fgets($fh)) !== false) {
                $lines[] = $l;
            }
            fclose($fh);
            foreach (array_slice(array_reverse($lines), 0, $limit) as $l) {
                $row = json_decode(trim($l), true);
                if (is_array($row)) {
                    $entries[] = $row;
                }
            }
        }
    }
    return $this->response->setJsonContent(["result" => "success", "count" => count($entries), "entries" => $entries]);
}
// MR changes - ends

// Ported from mr.ringq.ai_backend's TeamsController::contactsByNumberAction (+ mapContactRow) as a
// NEW action: the caller lookup the AI voice bridge uses. Unlike this server's own
// TeamsController::contactsByNumberAction it also accepts a "subdomain" (internal calls) and matches
// q_contacts phone columns directly, not only via a wrap-up note. The only change from the source is
// the error branch, which uses ControllerBase::safeErrorMessage like the rest of this server.
public function contactsByNumberAction()
{
        $this->view->disable();
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader("Content-Type", "application/json");

    try {

        if (!$this->request->isPost()) {
            throw new \Exception("Invalid request method");
        }

        $data = $this->request->getJsonRawBody(true);

        $internalKey = $this->request->getHeader("X-Internal-Key");
        $domainUuid  = null;

        if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            // Normal user session required
            $session = $this->session->get("auth");

            if (!$session) {
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "message" => "Unauthorized"
                ]);
            }

            $domainUuid = $session["domain_uuid"] ?? null;
        } else {
            // Internal service call: domain_uuid must be passed explicitly
            $domainUuid = trim($data['domain_uuid'] ?? '');

            // MR AI changes - starts
            // The AI voice bridge (mcp-email's lookup_caller tool) only
            // knows the tenant's subdomain, not its domain_uuid -- it has
            // no session and no reason to look up the uuid itself. Accept
            // "subdomain" as an alternative and resolve domain_uuid the
            // same way AiController already does elsewhere (VDomains
            // domain_name -> domain_uuid), so the internal caller only
            // ever has to know the subdomain.
            if (empty($domainUuid)) {
                $subdomainParam = trim($data['subdomain'] ?? '');
                if (!empty($subdomainParam)) {
                    $vDomain = \VDomains::findFirst([
                        "conditions" => "domain_name = :domain_name:",
                        "bind" => ["domain_name" => $subdomainParam],
                    ]);
                    if ($vDomain) {
                        $domainUuid = $vDomain->domain_uuid;
                    }
                }
            }
            // MR AI changes - ends
        }

        if (empty($domainUuid)) {
            throw new \Exception("Unable to resolve domain");
        }

        $number = trim($data['number'] ?? '');

        if (empty($number)) {
            throw new \Exception("Number is required");
        }

        // Normalize: strip everything except digits (handles +, -, (), spaces, ext.)
        $digitsOnly = preg_replace('/\D+/', '', $number);


        if (empty($digitsOnly)) {
            throw new \Exception("Invalid number");
        }

        // MR AI changes - starts
        // ---------------------------------------------------------------
        // REWRITTEN: contact lookup no longer depends on a wrap-up note.
        //
        // This action used to search QWrapupnotesList for the number, and
        // only then load a contact via that note's contact_id. A contact
        // who had never been wrapped up therefore came back as null even
        // though their number was sitting in q_contacts.phone -- which is
        // why the AI receptionist could not answer "who is calling" for
        // most callers, and why the previous author left the trailing
        // "// but this is not wokring" comment on the return statement.
        //
        // The order is now: find the contact by phone number directly,
        // then attach wrap-up notes as ENRICHMENT. The note path is kept
        // as a fallback so a number that only appears in a note still
        // resolves a contact exactly as it did before -- nothing that
        // worked stops working.
        // ---------------------------------------------------------------

        // 1) CONTACT BY PHONE NUMBER -- all four phone columns.
        //
        // Raw parameterized SQL via $this->db, not PHQL, and deliberately
        // so: PHQL's expression parser has a limited function vocabulary,
        // and this query needs right(), length() and the || concatenation
        // operator, none of which are safe to assume it will pass through
        // to Postgres intact. $this->db->fetchOne with positional ? binds
        // is the pattern insertGoogleContact() in this same controller
        // already uses for exactly that reason. Every value is still
        // bound, never interpolated.
        //
        // q_contacts stores numbers as free text, so they are normalized
        // on both sides of the comparison. Matching is right-anchored as
        // well as exact, because a CRM row is commonly stored in national
        // format ("91234567") while the ANI arrives in E.164 format
        // ("6591234567"), or the reverse -- an equality-only match misses
        // every one of those, which is a large share of real callers. The
        // longer value must end with the shorter one, and the shorter must
        // be at least 7 digits, so a 3-digit internal extension can never
        // be mistaken for the tail of a mobile number.
        //
        // Performance note: the normalization makes this unindexable, so
        // it is a sequential scan of the tenant's contacts. That is fine
        // at one query per inbound call. If q_contacts ever grows past the
        // point where that hurts, the fix is a stored normalized column
        // (generated, or maintained by trigger) with a btree index on it
        // -- not a change here.
        $contact = null;
        $suffixSafe = strlen($digitsOnly) >= 7;
        $digitLen = strlen($digitsOnly);

        // The four phone columns are unnested into rows and normalized ONCE
        // each, rather than repeating regexp_replace inline per column per
        // comparison. Written the flat way this same query needed twenty
        // regexp_replace calls and twenty-one bind parameters to say the
        // same thing; this needs four and five.
        //
        // MIN_SUFFIX_DIGITS guards the right-anchored comparisons: without
        // a floor, a 3-digit internal extension would tail-match half the
        // mobile numbers in the table.
        $matchClauses = ["v.n = ?"];
        $params = [$domainUuid, $digitsOnly];

        if ($suffixSafe) {
            // stored value is longer than the ANI: its last N digits are the ANI
            $matchClauses[] = "(length(v.n) > ? AND right(v.n, ?) = ?)";
            $params[] = $digitLen;
            $params[] = $digitLen;
            $params[] = $digitsOnly;

            // ANI is longer than the stored value: the ANI ends with it
            $matchClauses[] = "(length(v.n) >= 7 AND ? LIKE '%' || v.n)";
            $params[] = $digitsOnly;
        }

        $contactSql =
            "SELECT c.* FROM q_contacts c " .
            "WHERE c.domain_uuid = ? AND EXISTS (" .
            "  SELECT 1 FROM (" .
            "    SELECT regexp_replace(trim(COALESCE(raw, '')), '[^0-9]', '', 'g') AS n " .
            "    FROM unnest(ARRAY[c.phone, c.phone2, c.office_phone, c.whatsapp_phone]) AS raw" .
            "  ) v " .
            "  WHERE v.n <> '' AND (" . implode(' OR ', $matchClauses) . ")" .
            ") ORDER BY c.id DESC LIMIT 1";

        $contactRow = $this->db->fetchOne($contactSql, \Phalcon\Db\Enum::FETCH_ASSOC, $params);
        if ($contactRow) {
            $contact = $this->mapContactRow($contactRow);
        }

        // 2) WRAP-UP NOTES FOR THIS NUMBER -- the last few, not just one.
        //
        // These matter more than the contact record for the AI's actual
        // job: a note body is where an agent writes "order dispatched,
        // tracking ABC123", which is exactly what answers "where is my
        // order". One note was often the wrong one (a short "call
        // dropped" logged after the substantive note), so the last five
        // are returned and the newest is also exposed on its own under
        // the original "wrapupnote" key for backward compatibility.
        $qWraps = QWrapupnotesList::find([
            "conditions" => "
                domain_uuid = :domain_uuid:
                AND regexp_replace(trim(COALESCE(contact, '')), '[^0-9]', '', 'g') = :number:
            ",
            "bind" => [
                "domain_uuid" => $domainUuid,
                "number"      => $digitsOnly,
            ],
            "order" => "id DESC",
            "limit" => 5,
        ]);

        $wrapupNotes = [];
        foreach ($qWraps as $qWrap) {
            $wrapupNotes[] = [
                "id"              => $qWrap->id,
                "wrapup_uuid"     => $qWrap->wrapup_uuid,
                "domain_uuid"     => $qWrap->domain_uuid,
                "content"         => $qWrap->content,
                "insert_date"     => $qWrap->insert_date,
                "user_uuid"       => $qWrap->user_uuid,
                "extension"       => $qWrap->extension,
                "username"        => $qWrap->username,
                "customer"        => $qWrap->customer,
                "contact"         => $qWrap->contact,
                "wrapup_code"     => $qWrap->wrapup_code,
                "contact_id"      => $qWrap->contact_id,
                "update_date"     => $qWrap->update_date,
                "update_user"     => $qWrap->update_user,
                "queue_extension" => $qWrap->queue_extension,
            ];

            // 3) FALLBACK: the old behaviour. If the direct phone lookup
            //    found nothing, take the contact this note points at.
            if ($contact === null && !empty($qWrap->contact_id)) {
                $linked = QContacts::findFirst([
                    "conditions" => "id = :id: AND domain_uuid = :domain_uuid:",
                    "bind"       => ["id" => (int) $qWrap->contact_id, "domain_uuid" => $domainUuid],
                ]);
                if ($linked) {
                    $contact = $this->mapContactRow($linked);
                }
            }
        }

        $wrapupNote = $wrapupNotes[0] ?? null;

        $response = json_encode([
            "result"      => "success",
            "content"     => $contact,      // single contact, or null
            "wrapupnote"  => $wrapupNote,   // newest note for this number, or null
            "wrapupnotes" => $wrapupNotes,  // up to the last 5, newest first
            "matched"     => [
                "number"        => $digitsOnly,
                // Which of the two lookup paths produced the contact. Worth
                // returning rather than inferring: "found via a wrap-up note
                // link" means the caller's number is NOT in q_contacts, which
                // is a data-quality signal the AI's own logs can surface.
                "contactSource" => $contact === null
                    ? 'none'
                    : ($contactRow ? 'phone_column' : 'wrapup_note_link'),
                "noteCount"     => count($wrapupNotes),
            ],
        ]);
        // MR AI changes - ends

    } catch (\Exception $e) {

        $response = json_encode([
            "result"  => "error",
            "message" => ControllerBase::safeErrorMessage($e)
        ]);
    }
    // MR AI changes - starts: the trailing "// but this is not wokring"
    // note is gone because the thing it referred to now works -- see the
    // rewritten lookup above. The response itself is emitted with
    // setContent(json_encode(...)) rather than setJsonContent(...) purely
    // to match what the rest of this action already did; both produce the
    // same body.
    return $this->response->setContent($response);
}

/**
 * Maps one q_contacts row to the response shape this controller returns.
 *
 * Extracted because contactsByNumberAction resolves a contact from two
 * different places -- a direct phone-column match and the older
 * wrap-up-note link -- and writing this field list out twice is how the two
 * paths would quietly start returning different shapes.
 *
 * Accepts BOTH an associative array (from $this->db->fetchOne, used by the
 * phone-column path because that query needs SQL functions PHQL cannot be
 * trusted to pass through) and a QContacts model object (from the PHQL
 * fallback path). Taking only one of the two would have meant either
 * duplicating the field list or converting at both call sites.
 */
private function mapContactRow($row): array
{
    $get = static function ($key) use ($row) {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }
        return $row->{$key} ?? null;
    };

    $fields = [
        "id", "domain_uuid", "company_name", "title", "first_name", "middle_name",
        "last_name", "email", "email2", "address_1", "address_2", "city", "state",
        "country", "postal", "phone", "phone2", "office_phone", "whatsapp_phone",
        "fax", "timezone", "language", "notes", "contacttype", "insert_uuid",
        "insert_date", "update_date", "createdby",
    ];

    $out = [];
    foreach ($fields as $field) {
        $out[$field] = $get($field);
    }
    return $out;
}
// MR AI changes - ends

// MR AI changes - starts
/**
 * POST /teams/resolveagent
 *
 * Internal, machine-to-machine lookup for claude_server.js: given a
 * tenant subdomain and the extension the call was dialed to, returns the
 * matching AI agent config saved by AiController::saveAction (prompt,
 * greeting, voice, model, keywords, tool permissions, etc.) so the voice
 * bridge can actually run the agent the Flutter UI configured, instead of
 * one hardcoded prompt for every call on every tenant.
 *
 * Same internal-key auth pattern as contactsByNumberAction above -- no
 * new trust boundary. Falls back to the "default" agent (seeded by
 * AiController::listAction) if no agent's agentExtension matches, same
 * as the dashboard does when nothing has been configured yet.
 */
public function resolveAgentAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        if (!$this->request->isPost()) {
            throw new \Exception("Invalid request method");
        }

        $internalKey = $this->request->getHeader("X-Internal-Key");
        if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            return $this->response->setJsonContent([
                "result"  => "error",
                "message" => "Unauthorized"
            ]);
        }

        $data = $this->request->getJsonRawBody(true);
        $subdomain = trim($data['subdomain'] ?? '');
        $extension = trim($data['extension'] ?? '');

        if (empty($subdomain)) {
            throw new \Exception("subdomain is required");
        }

        $vDomain = \VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $subdomain],
        ]);
        if (!$vDomain) {
            throw new \Exception("Unknown subdomain: {$subdomain}");
        }
        $domainUuid = $vDomain->domain_uuid;

        // Normalize the requested extension to digits-only so "5555",
        // " 5555", and "5555 " (or an ext string with stray formatting)
        // all compare equal -- agentExtension is free-text in the UI.
        $extensionDigits = preg_replace('/\D+/', '', $extension);

        $agentIds = $this->redis->sMembers("agents:{$domainUuid}");
        $matched = null;
        $sameExtension = [];
        $defaultAgent = null;

        foreach ((array) $agentIds as $aid) {
            $agentJson = $this->redis->get("agent:{$domainUuid}:{$aid}");
            if (empty($agentJson)) {
                continue;
            }
            $agent = json_decode($agentJson, true);
            if (!is_array($agent)) {
                continue;
            }
            if ($aid === 'default') {
                $defaultAgent = $agent;
            }
            if (!empty($extensionDigits)) {
                $agentExtDigits = preg_replace('/\D+/', '', (string) ($agent['agentExtension'] ?? ''));
                if ($agentExtDigits !== '' && $agentExtDigits === $extensionDigits) {
                    // First match answers the call; the rest are remembered so the bridge can warn that the
                    // extension is shared (a call can then reach a different agent than the one being edited).
                    if ($matched === null) {
                        $matched = $agent;
                    } else {
                        $sameExtension[] = (string) ($agent['name'] ?? ($agent['id'] ?? '?'));
                    }
                }
            }
        }

        $resolved = $matched ?? $defaultAgent;
        // Other agents on this same extension (see the loop above): the bridge logs a warning, because a call can then
        // be answered by a different agent than the one the admin is looking at.
        if ($matched !== null && !empty($sameExtension) && is_array($resolved)) {
            $resolved["duplicateExtensionWith"] = array_values(array_unique($sameExtension));
        }
        if (!$resolved) {
            throw new \Exception("No agent configured for subdomain '{$subdomain}'" .
                (!empty($extension) ? " / extension '{$extension}'" : ""));
        }

        // MR AI changes - starts
        // The agent blob's allowedConnectorIds only ever holds an ID
        // string -- for the built-in "google-workspace"/"microsoft-365"
        // pseudo-connectors that's enough (claude_server.js already knows
        // how to reach those, via the tenant's Google/MS OAuth settings).
        // For a CUSTOM connector (McpController::connectorsAction, "type":
        // "mcp") the ID alone is useless to claude_server.js -- it needs
        // the actual serverUrl/authType/credentials to connect. Embed the
        // full definition for each custom connector this agent is allowed
        // to use, so ensureMcpClient() there can act on it directly
        // without a second round trip. Disabled connectors are skipped --
        // an agent that still lists a since-disabled connector in
        // allowedConnectorIds shouldn't have it silently re-enabled here.
        $customConnectors = [];
        $allowedConnectorIds = $resolved['allowedConnectorIds'] ?? [];
        if (is_array($allowedConnectorIds)) {
            foreach ($allowedConnectorIds as $cid) {
                if ($cid === 'google-workspace' || $cid === 'microsoft-365') {
                    continue; // built-ins, not stored as mcp_connector: blobs
                }
                $connectorJson = $this->redis->get("mcp_connector:{$domainUuid}:{$cid}");
                if (empty($connectorJson)) {
                    continue;
                }
                $connector = json_decode($connectorJson, true);
                if (is_array($connector) && ($connector['type'] ?? '') === 'mcp' && ($connector['enabled'] ?? true)) {
                    $customConnectors[] = $connector;
                }
            }
        }
        // MR AI changes - ends

        // MR AI changes - starts
        // The agent's knowledge "table of contents" -- one line per uploaded
        // document / selected website page. Rides along on the agent-config
        // fetch the voice bridge already makes at the start of every call, so
        // it can never be stale relative to the stored sources and costs no
        // extra round trip. buildIndex() caps it hard (40 entries / 4000
        // chars): the FULL text is deliberately not here, it is reachable
        // through the search_knowledge_base tool, because inlining a whole
        // crawled site would cost ~15k tokens on every turn of the call.
        $knowledgeIndex = [];
        try {
            $resolvedAgentId = $resolved['id'] ?? '';
            if (!empty($resolvedAgentId)) {
                $knowledgeIndex = \KnowledgeBase::buildIndex($this->redis, $domainUuid, $resolvedAgentId);
            }
        } catch (\Throwable $e) {
            // Never fail an inbound call over the knowledge index -- an agent
            // with no knowledge, or a Redis hiccup here, must still answer the
            // phone with its prompt and tools intact.
            error_log('[KB] buildIndex failed for agent ' . ($resolved['id'] ?? '?') . ': ' . $e->getMessage());
        }
        // MR AI changes - ends

        // MR AI changes - starts
        // Bring-your-own provider key for the voice bridge. It rides on this response (and only
        // this one: /ai/list and /ai/get strip it) so claude_server.js needs no .env key. The
        // caller is already gated by the X-Internal-Key check above. A missing key is not an
        // error here -- the bridge decides what to say on the call when it has no key to use.
        $resolved['providerApiKey'] = '';
        try {
            $keyAgentId  = (string) ($resolved['id'] ?? '');
            $keyProvider = (string) ($resolved['ringq_model'] ?? ($resolved['model'] ?? ''));
            if ($keyAgentId !== '' && $keyProvider !== '') {
                $resolved['providerApiKey'] = (new \AiController())->loadProviderKey($this->redis, $domainUuid, $keyAgentId, $keyProvider);
            }
        } catch (\Throwable $e) {
            error_log('[AI] provider key lookup failed for agent ' . ($resolved['id'] ?? '?') . ': ' . $e->getMessage());
        }
        // MR AI changes - ends

        return $this->response->setJsonContent([
            "result" => "success",
            "agent"  => $resolved,
            "customConnectors" => $customConnectors,
            "knowledgeIndex" => $knowledgeIndex, // MR AI changes
        ]);

    } catch (\Exception $e) {
        return $this->response->setJsonContent([
            "result"  => "error",
            "message" => $e->getMessage()
        ]);
    }
}
// MR AI changes - ends

// MR AI changes - starts
/**
 * /teamsai/voicesettings -- the tenant's speech (Deepgram) key for the AI voice bridge.
 *
 * Deepgram does the live speech-to-text and the text-to-speech on every AI call, and it is one
 * account per tenant (not per agent), so its key lives here rather than on an agent.
 *
 *   Browser, signed in:   GET  -> {hasDeepgramKey}            (the key itself is never returned)
 *                         POST -> {deepgramApiKey} saves it; blank = leave unchanged,
 *                                 {clear:true} removes it. Plain users (permission mode 10) cannot save.
 *   Voice bridge:         POST {subdomain} with X-Internal-Key -> {deepgramApiKey}
 *
 * Stored encrypted under its own key, never inside any agent blob that /ai/list returns.
 */
public function voiceSettingsAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $data = $this->request->getJsonRawBody(true);
        if (!is_array($data)) {
            $data = [];
        }

        // ---- internal caller: the voice bridge --------------------------------------------
        $internalKey = $this->request->getHeader("X-Internal-Key");
        if ($internalKey !== '') {
            if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886" || !$this->request->isPost()) {
                $this->response->setStatusCode(401, "Unauthorized");
                return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
            }
            $subdomain = trim((string) ($data['subdomain'] ?? ''));
            $vDomain = $subdomain === '' ? null : \VDomains::findFirst([
                "conditions" => "domain_name = :domain_name:",
                "bind" => ["domain_name" => $subdomain],
            ]);
            if (!$vDomain) {
                throw new \Exception("Unknown subdomain");
            }
            $raw = $this->redis->get("voice-secret:{$vDomain->domain_uuid}");
            $secret = $raw ? json_decode($raw, true) : null;
            return $this->response->setJsonContent([
                "result"          => "success",
                "deepgramApiKey"  => is_array($secret) ? $this->aiDecrypt((string) ($secret['deepgram'] ?? '')) : '',
            ]);
        }

        // ---- browser caller: a signed-in user ----------------------------------------------
        $session = $this->session->get("auth") ?? [];
        $domainUuid = $session['domain_uuid'] ?? '';
        if (empty($domainUuid)) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }
        $redisKey = "voice-secret:{$domainUuid}";
        $raw = $this->redis->get($redisKey);
        $secret = $raw ? json_decode($raw, true) : null;
        $hasKey = is_array($secret) && !empty($secret['deepgram']);

        if (!$this->request->isPost()) {
            return $this->response->setJsonContent(["result" => "success", "hasDeepgramKey" => $hasKey]);
        }

        // Saving changes what every AI call on this tenant bills to, so plain users may not.
        $mode = $this->redis->get(($session['extension'] ?? '') . "-" . ($session['subdomain'] ?? '') . "-permission");
        if ($mode !== null && $mode !== false && intval($mode) === 10) {
            $this->response->setStatusCode(403, "Forbidden");
            return $this->response->setJsonContent(["result" => "error", "message" => "Only an administrator can change the voice settings."]);
        }

        // The settings screen's eye button. Same rule as above (never a plain user), an explicit POST
        // only, not cacheable, and an audit line that records who looked -- not the key.
        if (!empty($data['reveal'])) {
            $this->response->setHeader("Cache-Control", "no-store");
            if (!$hasKey) {
                throw new \Exception("No key is saved.");
            }
            error_log('[AI] Deepgram key viewed for tenant ' . $domainUuid . ' by user ' . ($session['user_uuid'] ?? '?'));
            return $this->response->setJsonContent([
                "result"         => "success",
                "deepgramApiKey" => $this->aiDecrypt((string) $secret['deepgram']),
            ]);
        }

        if (!empty($data['clear'])) {
            $this->redis->del($redisKey);
            return $this->response->setJsonContent(["result" => "success", "hasDeepgramKey" => false]);
        }
        $newKey = trim((string) ($data['deepgramApiKey'] ?? ''));
        if ($newKey !== '') {
            $this->redis->set($redisKey, json_encode([
                "deepgram" => $this->aiEncrypt($newKey),
                "updated"  => date("Y-m-d H:i"),
            ]));
            $hasKey = true;
        }
        return $this->response->setJsonContent(["result" => "success", "hasDeepgramKey" => $hasKey]);

    } catch (\Throwable $e) {
        error_log('[AI] voicesettings failed: ' . $e->getMessage());
        return $this->response->setJsonContent(["result" => "error", "message" => "Could not read or save the voice settings."]);
    }
}
// MR AI changes - ends

// =====================================================================
// MR AI changes - starts
// PER-USER AI CONNECTORS
// =====================================================================
//
// Everything below backs the Flutter "Connectors" page and the connector
// section of the AI agent editor. It is a deliberately SEPARATE token
// store from the tenant-level Google/Microsoft integrations that already
// live in this controller (google_settings:{subdomain} and
// o365_settings:{subdomain}), and the distinction matters:
//
//   - The tenant-level blobs are ONE connection per company, configured by
//     an admin, and they hold the OAuth app credentials (client_id and
//     client_secret) plus one set of tokens. They drive contact import and
//     SSO.
//   - These per-user records hold ONLY tokens, one per (user, provider),
//     and they answer "whose mailbox and whose calendar is the AI acting
//     on". That is what makes "check the agent's calendar and schedule the
//     meeting" mean the agent's own calendar rather than a shared company
//     one.
//
// The OAuth APP still comes from the tenant blob -- there is exactly one
// client_id/client_secret per company and it is not duplicated here. Only
// the per-user GRANT is new.
//
// Storage:
//   ai_user_token:{domain_uuid}:{user_uuid}:{agent_uuid}:{provider}
//       JSON. access_token and refresh_token are encrypted with
//       $this->crypt. (The tenant blobs store their tokens in plaintext;
//       new code should not.)
//       Keyed on the (user, agent) PAIR, not on user alone -- a grant
//       connected while editing one AI agent must not appear "already
//       connected" on a different agent owned by the same human. Before this
//       key gained the agent segment, exactly that happened: every agent a
//       user owned shared one Google/Microsoft connection.
//   ai_oauth_state:{nonce}
//       JSON, 600s TTL. The authorization request is correlated back to a
//       user through this key rather than by trusting the contents of the
//       state parameter, which is attacker-supplied on the way back in.
//
// DEPLOYMENT NOTE: these flows use their own redirect URI,
//   https://{subdomain}:8443/teamsai/aiconnectcallback
// which must be added to the tenant's Google Cloud OAuth client and Entra
// app as an authorized redirect URI. It is deliberately NOT the existing
// /teams/googlecallback or /teams/callback: those are the tenant-level and
// SSO flows, and branching them on state would have meant editing working
// login code to add a feature. aiConnectorsAction returns the exact URI to
// register, so the UI can show it rather than letting the user discover it
// through a failed consent screen.

/**
 * The provider catalogue. One entry per connectable account type.
 *
 * The tools lists are the labels the Flutter UI shows on a connector tile.
 * They intentionally match Connector.availableTools in connector.dart so
 * the per-tool permission chips in the agent editor line up with the MCP
 * tool names the voice bridge filters on (see BUILTIN_TOOL_CONNECTOR in
 * claude_server.js).
 */
private function aiProviderCatalog(): array
{
    return [
        'google' => [
            'id'           => 'google-workspace',
            'name'         => 'Google Workspace',
            'description'  => 'Gmail, Google Calendar, Drive and Sheets.',
            'settingsKey'  => 'google_settings:',
            'authorizeUrl' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'tokenUrl'     => 'https://oauth2.googleapis.com/token',
            'revokeUrl'    => 'https://oauth2.googleapis.com/revoke',
            'userInfoUrl'  => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'emailField'   => 'email',
            'scopes'       => [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/calendar',
                'https://www.googleapis.com/auth/drive.readonly',
            ],
            'tools' => [
                'Search Gmail', 'Read Gmail Message', 'List Calendar Events',
                'Check Availability', 'Create Calendar Event', 'Send Email',
                'Search Drive Files', 'Read Spreadsheet',
            ],
        ],
        'microsoft' => [
            'id'           => 'microsoft-365',
            'name'         => 'Microsoft 365',
            'description'  => 'Outlook Mail, Outlook Calendar and Contacts.',
            'settingsKey'  => 'o365_settings:',
            // Both endpoints are tenant-specific for Microsoft, so they are
            // built per request in aiAuthorizeUrl()/aiTokenUrl() instead.
            'authorizeUrl' => null,
            'tokenUrl'     => null,
            'revokeUrl'    => null, // Graph has no token revocation endpoint
            'userInfoUrl'  => 'https://graph.microsoft.com/v1.0/me',
            'emailField'   => 'mail',
            'scopes'       => [
                'offline_access', 'openid', 'email', 'profile',
                'User.Read', 'Mail.Read', 'Mail.Send', 'Calendars.ReadWrite',
            ],
            'tools' => [
                'Search Emails', 'Read Email', 'Read Calendar',
                'Check Availability', 'Create Calendar Event', 'Send Email',
            ],
        ],
    ];
}

/** Redis key holding one user's grant on one provider, for one agent. */
private function aiTokenKey(string $domainUuid, string $userUuid, string $agentUuid, string $provider): string
{
    return "ai_user_token:{$domainUuid}:{$userUuid}:{$agentUuid}:{$provider}";
}

/** The redirect URI these flows use. Must be registered with the provider. */
private function aiRedirectUri(string $subdomain): string
{
    return "https://{$subdomain}:8443/teamsai/aiconnectcallback";
}

private function aiAuthorizeUrl(string $provider, array $app): string
{
    if ($provider === 'microsoft') {
        return "https://login.microsoftonline.com/" . rawurlencode($app['tenant_id']) . "/oauth2/v2.0/authorize";
    }
    return $this->aiProviderCatalog()[$provider]['authorizeUrl'];
}

private function aiTokenUrl(string $provider, array $app): string
{
    if ($provider === 'microsoft') {
        return "https://login.microsoftonline.com/" . rawurlencode($app['tenant_id']) . "/oauth2/v2.0/token";
    }
    return $this->aiProviderCatalog()[$provider]['tokenUrl'];
}

/**
 * Reads the tenant's OAuth app credentials for a provider out of the
 * existing tenant-level settings blob. Returns null when the tenant has
 * not configured an app yet.
 *
 * That null is load-bearing for the UI: "no OAuth app configured" and
 * "app configured but this user has not connected" look identical on a
 * connector tile but have completely different remedies -- the first needs
 * an admin in the Google/Microsoft settings dialog, the second needs the
 * user to click Connect. Showing a Connect button for the first case sends
 * the user through a consent screen that cannot succeed.
 */
private function aiTenantApp(string $subdomain, string $provider): ?array
{
    $catalog = $this->aiProviderCatalog();
    if (!isset($catalog[$provider])) {
        return null;
    }
    $raw = $this->redis->get($catalog[$provider]['settingsKey'] . $subdomain);
    if (empty($raw)) {
        return null;
    }
    $settings = json_decode($raw, true);
    if (!is_array($settings) || empty($settings['client_id']) || empty($settings['client_secret'])) {
        return null;
    }
    return [
        'client_id'     => $settings['client_id'],
        'client_secret' => $settings['client_secret'],
        // Microsoft only. "common" admits personal and any-org accounts; a
        // tenant that pinned its own tenant_id keeps that restriction.
        'tenant_id'     => $settings['tenant_id'] ?? 'common',
    ];
}

private function aiEncrypt(?string $text): string
{
    if (empty($text)) {
        return '';
    }
    return $this->crypt->encryptBase64($text);
}

private function aiDecrypt(?string $encrypted): string
{
    if (empty($encrypted)) {
        return '';
    }
    // rtrim mirrors McpController::decryptToken -- Phalcon's block cipher
    // pads, and the padding survives into the decrypted string.
    return rtrim($this->crypt->decryptBase64($encrypted));
}

/**
 * One JSON HTTP call. Returns [statusCode, decodedBody].
 *
 * Deliberately does NOT throw on a non-2xx. Every caller here needs the
 * provider's own error text: an expired refresh token, a revoked app and a
 * misregistered redirect URI all fail here and all need different
 * remedies, and an exception would flatten them into one "connection
 * failed" that tells the user nothing.
 */
private function aiHttpJson(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
    }
    curl_setopt_array($ch, $opts);
    $raw    = curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        return [0, ['error' => 'transport', 'error_description' => $error]];
    }
    $decoded = json_decode((string) $raw, true);
    return [$status, is_array($decoded) ? $decoded : ['raw' => $raw]];
}

/**
 * Loads and decrypts one stored grant, or null. Never returns the tokens
 * to a caller that only wants status -- see aiPublicGrant().
 */
private function aiLoadGrant(string $domainUuid, string $userUuid, string $agentUuid, string $provider): ?array
{
    $raw = $this->redis->get($this->aiTokenKey($domainUuid, $userUuid, $agentUuid, $provider));
    if (empty($raw)) {
        return null;
    }
    $grant = json_decode($raw, true);
    if (!is_array($grant)) {
        return null;
    }
    $grant['access_token']  = $this->aiDecrypt($grant['access_token'] ?? '');
    $grant['refresh_token'] = $this->aiDecrypt($grant['refresh_token'] ?? '');
    return $grant;
}

private function aiSaveGrant(string $domainUuid, string $userUuid, string $agentUuid, string $provider, array $grant): void
{
    $grant['access_token']  = $this->aiEncrypt($grant['access_token'] ?? '');
    $grant['refresh_token'] = $this->aiEncrypt($grant['refresh_token'] ?? '');
    $grant['updated_at']    = date('c');
    $this->redis->set($this->aiTokenKey($domainUuid, $userUuid, $agentUuid, $provider), json_encode($grant));
}

// ---- the agent's ONE shared connection (see AiAgentConnection.php) -----------------------------------------

/** The agent's saved owner (ownerUserUuid in its blob), or ''. Used only to adopt an older per-user connection. */
private function aiAgentOwnerUuid(string $domainUuid, string $agentId): string
{
    $raw = $this->redis->get("agent:{$domainUuid}:{$agentId}");
    $blob = $raw ? json_decode((string) $raw, true) : null;
    return is_array($blob) ? (string) ($blob['ownerUserUuid'] ?? '') : '';
}

private function aiLoadAgentConnection(string $domainUuid, string $agentId, string $provider, array $legacyUsers): ?array
{
    return \AiAgentConnection::load(
        $this->redis,
        function ($t) { return $this->aiEncrypt($t); },
        function ($t) { return $this->aiDecrypt($t); },
        $domainUuid, $agentId, $provider, $legacyUsers
    );
}

private function aiSaveAgentConnection(string $domainUuid, string $agentId, string $provider, array $grant): void
{
    \AiAgentConnection::save($this->redis, function ($t) { return $this->aiEncrypt($t); }, $domainUuid, $agentId, $provider, $grant);
}

/**
 * The safe-to-return view of a grant: everything the UI needs to render a
 * connected tile, and no token material. Keeping this as its own function
 * is the point -- the natural mistake is to json_encode the stored blob,
 * which would hand an access token to the browser.
 */
private function aiPublicGrant(?array $grant): ?array
{
    if ($grant === null) {
        return null;
    }
    return [
        'accountEmail' => $grant['account_email'] ?? '',
        'accountName'  => $grant['account_name'] ?? '',
        'scopes'       => $grant['scopes'] ?? [],
        'connectedAt'  => $grant['connected_at'] ?? '',
        'updatedAt'    => $grant['updated_at'] ?? '',
        // Whether a refresh token is held at all. Without one, the grant
        // dies silently in an hour and the user has to reconnect, so the UI
        // should be able to warn rather than letting the AI fail mid-call.
        'hasRefreshToken' => !empty($grant['refresh_token']),
        'expiresAt'    => $grant['expires_at'] ?? 0,
    ];
}

/**
 * GET /teams/aiconnectors?agentId=...
 *
 * The connector catalogue plus this user's connection status for each, FOR
 * ONE AGENT. Session-authenticated: it is scoped to whoever is logged in,
 * because a connector is a personal grant -- but it is also scoped to the
 * agent being edited, because the grant is keyed on the (user, agent) pair.
 * Without agentId this would report the caller's connection status against
 * every agent they own, indistinguishably.
 */
public function aiConnectorsAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $session = $this->session->get("auth") ?? [];
        $domainUuid = $session['domain_uuid'] ?? '';
        $userUuid   = $session['user_uuid'] ?? '';
        $subdomain  = $session['subdomain'] ?? '';
        $agentId    = trim((string) $this->request->getQuery('agentId', 'string', ''));

        if (empty($domainUuid) || empty($userUuid) || empty($subdomain)) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }
        if (empty($agentId)) {
            throw new \Exception("agentId is required -- connectors are scoped per agent.");
        }

        // ONE connection per agent, the same for everyone who opens it -- not "whatever this logged-in user
        // connected". An older per-user connection (the agent owner's, or this viewer's) is adopted on first read.
        $ownerUuid  = $this->aiAgentOwnerUuid($domainUuid, $agentId);
        $connectors = [];
        foreach ($this->aiProviderCatalog() as $provider => $meta) {
            $app   = $this->aiTenantApp($subdomain, $provider);
            $grant = $this->aiLoadAgentConnection($domainUuid, $agentId, $provider, [$ownerUuid, $userUuid]);

            // Three distinct states, not two. "unconfigured" is the one that
            // is easy to omit and confusing to hit.
            if ($app === null) {
                $status = 'unconfigured';
            } elseif ($grant !== null) {
                $status = 'connected';
            } else {
                $status = 'disconnected';
            }

            $connectors[] = [
                'provider'     => $provider,
                'id'           => $meta['id'],
                'name'         => $meta['name'],
                'description'  => $meta['description'],
                'status'       => $status,
                'availableTools' => $meta['tools'],
                'scopes'       => $meta['scopes'],
                'account'      => \AiAgentConnection::publicView($grant, $userUuid),
                // Shown by the UI when status is unconfigured, so an admin
                // knows exactly what to register rather than guessing.
                'redirectUri'  => $this->aiRedirectUri($subdomain),
                'setupHint'    => $app === null
                    ? ($provider === 'google'
                        ? 'No Google OAuth app configured for this company yet. An administrator must set the client ID and secret in Google settings, and add the redirect URI below to the Google Cloud OAuth client.'
                        : 'No Microsoft OAuth app configured for this company yet. An administrator must set the client ID, secret and tenant ID in Microsoft 365 settings, and add the redirect URI below to the Entra app registration.')
                    : '',
            ];
        }

        return $this->response->setJsonContent([
            "result"     => "success",
            "connectors" => $connectors,
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * GET /teams/aiconnect?provider=google|microsoft
 *
 * Returns the provider consent URL for the logged-in user to visit.
 *
 * Returns JSON rather than issuing a 302, because the caller is a Flutter
 * web app that opens the URL in a new tab (the pattern gsetting.dart
 * already uses for the tenant-level Google connect). A redirect response
 * to an XHR would be followed by the browser inside the XHR and the user
 * would never see the consent screen.
 */
public function aiConnectAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $session = $this->session->get("auth") ?? [];
        $domainUuid = $session['domain_uuid'] ?? '';
        $userUuid   = $session['user_uuid'] ?? '';
        $subdomain  = $session['subdomain'] ?? '';
        $agentId    = trim((string) $this->request->getQuery('agentId', 'string', ''));

        if (empty($domainUuid) || empty($userUuid) || empty($subdomain)) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }
        if (empty($agentId)) {
            throw new \Exception("agentId is required -- connectors are scoped per agent.");
        }

        $provider = strtolower(trim((string) $this->request->getQuery('provider', 'string', '')));
        $catalog  = $this->aiProviderCatalog();
        if (!isset($catalog[$provider])) {
            throw new \Exception("Unknown provider: " . $provider);
        }

        $app = $this->aiTenantApp($subdomain, $provider);
        if ($app === null) {
            throw new \Exception("This company has no " . $catalog[$provider]['name'] .
                " OAuth app configured yet. Set the client ID and secret in settings first.");
        }

        // The state we send is an opaque nonce, and everything meaningful is
        // held server-side against it. Encoding domain/user into the state
        // itself -- as AiController's older, now-dead flow did -- means
        // trusting values that came back through the user's browser.
        $nonce = bin2hex(random_bytes(16));
        $this->redis->setex("ai_oauth_state:{$nonce}", 600, json_encode([
            'domain_uuid' => $domainUuid,
            'user_uuid'   => $userUuid,
            // Who is connecting, for "connected by ..." on the agent's connector tile. Held server-side with
            // the nonce like everything else here, never read back from the browser.
            'user_email'  => (string) ($session['email'] ?? ''),
            'agent_id'    => $agentId,
            'subdomain'   => $subdomain,
            'provider'    => $provider,
        ]));

        $params = [
            'client_id'     => $app['client_id'],
            'redirect_uri'  => $this->aiRedirectUri($subdomain),
            'response_type' => 'code',
            'scope'         => implode(' ', $catalog[$provider]['scopes']),
            'state'         => $nonce,
        ];
        if ($provider === 'google') {
            // access_type=offline is what produces a refresh token at all;
            // prompt=consent is what makes Google re-issue one on a repeat
            // authorization. Without the second, reconnecting an account
            // that was connected before yields an access token with no
            // refresh token, and the connection dies an hour later.
            $params['access_type'] = 'offline';
            $params['prompt'] = 'consent';
            $params['include_granted_scopes'] = 'true';
        } else {
            $params['response_mode'] = 'query';
            $params['prompt'] = 'consent';
        }

        $url = $this->aiAuthorizeUrl($provider, $app) . '?' . http_build_query($params);

        return $this->response->setJsonContent([
            "result" => "success",
            "url"    => $url,
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * GET /teamsai/aiconnectcallback
 *
 * Where the provider sends the user back. Not session-authenticated: the
 * nonce in the state parameter is the credential, because a consent
 * redirect can land in a browser context where the RingQ session cookie is
 * not present (a fresh tab, a different browser profile, SameSite rules on
 * a cross-site redirect).
 *
 * Returns a small self-closing HTML page rather than JSON, because a human
 * is looking at this tab. The Flutter Connectors page is polling
 * /teams/aiconnectors independently and will flip the tile to connected on
 * its own -- this page does not need to talk to it.
 */
public function aiConnectCallbackAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "text/html; charset=utf-8");

    try {
        $error = trim((string) $this->request->getQuery('error', 'string', ''));
        if (!empty($error)) {
            $desc = trim((string) $this->request->getQuery('error_description', 'string', ''));
            throw new \Exception("The provider declined the request: " . $error .
                (!empty($desc) ? " (" . $desc . ")" : ""));
        }

        $code  = trim((string) $this->request->getQuery('code', 'string', ''));
        $state = trim((string) $this->request->getQuery('state', 'string', ''));
        if (empty($code) || empty($state)) {
            throw new \Exception("Authorization code or state is missing.");
        }

        $stateKey = "ai_oauth_state:{$state}";
        $stateRaw = $this->redis->get($stateKey);
        if (empty($stateRaw)) {
            throw new \Exception("This authorization link has expired or was already used. Please try connecting again.");
        }
        // Single use. Deleted before the exchange, so a replayed callback
        // cannot mint a second grant even if the exchange itself fails.
        $this->redis->del($stateKey);

        $stateData = json_decode($stateRaw, true);
        $domainUuid = $stateData['domain_uuid'] ?? '';
        $userUuid   = $stateData['user_uuid'] ?? '';
        $agentId    = $stateData['agent_id'] ?? '';
        $subdomain  = $stateData['subdomain'] ?? '';
        $provider   = $stateData['provider'] ?? '';

        $catalog = $this->aiProviderCatalog();
        if (empty($domainUuid) || empty($userUuid) || empty($agentId) || empty($subdomain) || !isset($catalog[$provider])) {
            throw new \Exception("Authorization state was malformed.");
        }

        $app = $this->aiTenantApp($subdomain, $provider);
        if ($app === null) {
            throw new \Exception("The OAuth app for this company is no longer configured.");
        }

        [$status, $tokenData] = $this->aiHttpJson('POST', $this->aiTokenUrl($provider, $app), [
            'Content-Type: application/x-www-form-urlencoded',
        ], [
            'code'          => $code,
            'client_id'     => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'redirect_uri'  => $this->aiRedirectUri($subdomain),
            'grant_type'    => 'authorization_code',
        ]);

        if ($status < 200 || $status >= 300 || empty($tokenData['access_token'])) {
            throw new \Exception("Token exchange failed (HTTP " . $status . "): " .
                ($tokenData['error_description'] ?? $tokenData['error'] ?? 'no access_token returned') .
                ". If this mentions redirect_uri, add " . $this->aiRedirectUri($subdomain) .
                " to the provider's list of authorized redirect URIs.");
        }

        // Identify the account that was actually connected. Worth a second
        // round trip: the whole point of a per-user connector is knowing
        // WHOSE mailbox this is, and the token response does not say.
        $accountEmail = '';
        $accountName  = '';
        [$meStatus, $me] = $this->aiHttpJson('GET', $catalog[$provider]['userInfoUrl'], [
            'Authorization: Bearer ' . $tokenData['access_token'],
            'Accept: application/json',
        ]);
        if ($meStatus >= 200 && $meStatus < 300) {
            $field = $catalog[$provider]['emailField'];
            // Microsoft returns null in "mail" for accounts without an
            // Exchange mailbox and puts the address in userPrincipalName.
            $accountEmail = $me[$field] ?? $me['userPrincipalName'] ?? '';
            $accountName  = $me['name'] ?? $me['displayName'] ?? '';
        }

        // Connecting REPLACES the agent's one connection, whoever made the previous one -- that is how a
        // second person puts their own account on an agent that already has one (they disconnect it first in
        // the editor). The older per-user records are removed so a later disconnect cannot bring one back.
        $this->aiSaveAgentConnection($domainUuid, $agentId, $provider, [
            'provider'      => $provider,
            'account_email' => $accountEmail,
            'account_name'  => $accountName,
            'access_token'  => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? '',
            'expires_at'    => time() + (int) ($tokenData['expires_in'] ?? 3600),
            'scopes'        => isset($tokenData['scope']) ? explode(' ', $tokenData['scope']) : $catalog[$provider]['scopes'],
            'connected_at'  => date('c'),
            'connected_by_user_uuid' => $userUuid,
            'connected_by_email'     => (string) ($stateData['user_email'] ?? ''),
        ]);
        \AiAgentConnection::removeLegacy($this->redis, $domainUuid, $agentId, $provider,
            [$userUuid, $this->aiAgentOwnerUuid($domainUuid, $agentId)]);

        $label = htmlspecialchars($catalog[$provider]['name'] .
            (!empty($accountEmail) ? ' (' . $accountEmail . ')' : ''), ENT_QUOTES, 'UTF-8');

        return $this->response->setContent($this->aiCallbackPage(true, $label . ' is now connected.'));

    } catch (\Throwable $e) {
        return $this->response->setContent(
            $this->aiCallbackPage(false, htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'))
        );
    }
}

/**
 * The tiny HTML page the OAuth callback returns.
 *
 * Tries to close itself, but always renders the message too: window.close()
 * is blocked by most browsers unless the tab was opened by script, and a
 * tab that neither closes nor says anything is the worst outcome here --
 * especially on the failure path, where the message IS the remedy.
 */
private function aiCallbackPage(bool $ok, string $message): string
{
    $colour = $ok ? '#1B9DD9' : '#c0392b';
    $title  = $ok ? 'Connected' : 'Connection failed';
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<title>' . $title . '</title>'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
        . 'background:#101A38;color:#FAFAFA;display:flex;align-items:center;'
        . 'justify-content:center;min-height:100vh;margin:0;padding:24px}'
        . '.card{max-width:520px;background:#1E274a;border-radius:12px;padding:28px 32px;'
        . 'box-shadow:0 2px 12px rgba(0,0,0,.4)}'
        . 'h1{margin:0 0 12px;font-size:20px;color:' . $colour . '}'
        . 'p{margin:0;line-height:1.6;font-size:14px;color:#B7BFEE}'
        . '.hint{margin-top:16px;font-size:12px;color:#686A8A}</style></head>'
        . '<body><div class="card"><h1>' . $title . '</h1><p>' . $message . '</p>'
        . '<p class="hint">You can close this tab and return to RingQ.</p></div>'
        . '<script>setTimeout(function(){try{window.close();}catch(e){}}, '
        . ($ok ? '1200' : '600000') . ');</script></body></html>';
}

/**
 * POST /teams/aidisconnect   body: {"provider":"google"}
 *
 * Revokes where the provider supports it, then deletes the stored grant.
 * The delete happens even if revocation fails -- a user who clicks
 * Disconnect must end up disconnected in RingQ regardless of what the
 * provider's endpoint says, otherwise the tile stays connected and the AI
 * keeps using an account the user asked it to stop using.
 */
public function aiDisconnectAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $session = $this->session->get("auth") ?? [];
        $domainUuid = $session['domain_uuid'] ?? '';
        $userUuid   = $session['user_uuid'] ?? '';

        if (empty($domainUuid) || empty($userUuid)) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data = $this->request->getJsonRawBody(true) ?: [];
        $provider = strtolower(trim((string) ($data['provider'] ?? '')));
        $agentId  = trim((string) ($data['agentId'] ?? ''));
        $catalog  = $this->aiProviderCatalog();
        if (!isset($catalog[$provider])) {
            throw new \Exception("Unknown provider: " . $provider);
        }
        if (empty($agentId)) {
            throw new \Exception("agentId is required -- connectors are scoped per agent.");
        }

        // Anyone who can edit this agent may disconnect its connection (and then connect their own).
        $ownerUuid = $this->aiAgentOwnerUuid($domainUuid, $agentId);
        $grant = $this->aiLoadAgentConnection($domainUuid, $agentId, $provider, [$ownerUuid, $userUuid]);
        $revoked = false;
        $revokeNote = '';

        if ($grant !== null && !empty($catalog[$provider]['revokeUrl'])) {
            $token = !empty($grant['refresh_token']) ? $grant['refresh_token'] : ($grant['access_token'] ?? '');
            if (!empty($token)) {
                [$status, $body] = $this->aiHttpJson('POST', $catalog[$provider]['revokeUrl'],
                    ['Content-Type: application/x-www-form-urlencoded'], ['token' => $token]);
                $revoked = ($status >= 200 && $status < 300);
                if (!$revoked) {
                    $revokeNote = 'Provider revocation returned HTTP ' . $status .
                        '; the local connection was removed anyway.';
                }
            }
        }

        $connectedBy = is_array($grant) ? (string) ($grant['connected_by_user_uuid'] ?? '') : '';
        \AiAgentConnection::remove($this->redis, $domainUuid, $agentId, $provider, [$ownerUuid, $userUuid, $connectedBy]);

        return $this->response->setJsonContent([
            "result"  => "success",
            "revoked" => $revoked,
            "message" => $revokeNote,
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * POST /teams/aiconnectortoken
 * body: {"subdomain":"...","user_uuid":"...","provider":"google"}
 * header: X-Internal-Key
 *
 * Returns a currently-valid access token for one user's connected account.
 * This is what mcp-email/mcp_email_server.js calls so its Gmail/Calendar/
 * Graph tools act on the AI agent owner's account instead of a single
 * shared tenant account.
 *
 * Internal-key authenticated, same machine-to-machine channel as
 * resolveAgentAction and contactsByNumberAction -- no new trust boundary.
 * It is emphatically NOT session-authenticated and must never be reachable
 * from a browser: it returns bearer tokens.
 */
public function aiConnectorTokenAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $internalKey = $this->request->getHeader("X-Internal-Key");
        if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data = $this->request->getJsonRawBody(true) ?: [];
        $subdomain = trim((string) ($data['subdomain'] ?? ''));
        $userUuid  = trim((string) ($data['user_uuid'] ?? ''));
        $agentId   = trim((string) ($data['agent_id'] ?? ''));
        $provider  = strtolower(trim((string) ($data['provider'] ?? '')));

        $catalog = $this->aiProviderCatalog();
        if (empty($subdomain) || empty($userUuid) || empty($agentId) || !isset($catalog[$provider])) {
            throw new \Exception("subdomain, user_uuid, agent_id and a known provider are all required");
        }

        $vDomain = \VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $subdomain],
        ]);
        if (!$vDomain) {
            throw new \Exception("Unknown subdomain: " . $subdomain);
        }
        $domainUuid = $vDomain->domain_uuid;

        // The agent's one connection, whoever made it. $userUuid is the agent's saved owner as the bridge knows
        // it; it only matters for adopting a connection stored the older per-user way.
        $grant = $this->aiLoadAgentConnection($domainUuid, $agentId, $provider, [$userUuid]);
        if ($grant === null) {
            return $this->response->setJsonContent([
                "result"  => "error",
                "reason"  => "not_connected",
                "message" => "No " . $catalog[$provider]['name'] . " account is connected to this agent.",
            ]);
        }

        // Refresh with 5 minutes of headroom rather than on expiry. A token
        // that is valid when this returns but expires while the model is
        // mid-turn fails the tool call for the caller, and there is no
        // second chance inside one phone call.
        $expiresAt = (int) ($grant['expires_at'] ?? 0);
        if ($expiresAt - time() <= 300) {
            if (empty($grant['refresh_token'])) {
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "reason"  => "no_refresh_token",
                    "message" => "The stored " . $catalog[$provider]['name'] . " grant has expired and carries no " .
                        "refresh token, so it cannot be renewed. The user needs to reconnect the account.",
                ]);
            }

            $app = $this->aiTenantApp($subdomain, $provider);
            if ($app === null) {
                throw new \Exception("The OAuth app for this company is no longer configured.");
            }

            $refreshBody = [
                'client_id'     => $app['client_id'],
                'client_secret' => $app['client_secret'],
                'refresh_token' => $grant['refresh_token'],
                'grant_type'    => 'refresh_token',
            ];
            if ($provider === 'microsoft') {
                // Entra requires the scope list on a refresh; Google rejects it.
                $refreshBody['scope'] = implode(' ', $catalog[$provider]['scopes']);
            }

            [$status, $refreshed] = $this->aiHttpJson('POST', $this->aiTokenUrl($provider, $app),
                ['Content-Type: application/x-www-form-urlencoded'], $refreshBody);

            if ($status < 200 || $status >= 300 || empty($refreshed['access_token'])) {
                return $this->response->setJsonContent([
                    "result"  => "error",
                    "reason"  => "refresh_failed",
                    "message" => "Refreshing the " . $catalog[$provider]['name'] . " token failed (HTTP " . $status .
                        "): " . ($refreshed['error_description'] ?? $refreshed['error'] ?? 'unknown error'),
                ]);
            }

            $grant['access_token'] = $refreshed['access_token'];
            $grant['expires_at']   = time() + (int) ($refreshed['expires_in'] ?? 3600);
            // Google usually omits refresh_token on refresh; Microsoft rotates
            // it. Only overwrite when one actually came back, or the account
            // silently loses the ability to refresh again.
            if (!empty($refreshed['refresh_token'])) {
                $grant['refresh_token'] = $refreshed['refresh_token'];
            }
            $this->aiSaveAgentConnection($domainUuid, $agentId, $provider, $grant);
        }

        return $this->response->setJsonContent([
            "result"       => "success",
            "provider"     => $provider,
            "accessToken"  => $grant['access_token'],
            "expiresAt"    => (int) $grant['expires_at'],
            "accountEmail" => $grant['account_email'] ?? '',
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}
// MR AI changes - ends

// =====================================================================
// MR AI changes - starts
// PER-AGENT KNOWLEDGE BASE (documents + website pages)
// =====================================================================
//
// These back the "AI Context" section of the AI agent editor. An agent can
// be given uploaded documents and selected pages of the company's own
// website; the voice bridge then answers from that material instead of from
// the model's training data (see buildContextBlock/buildGroundingRules in
// claude_server.js).
//
// All the real work -- SSRF-guarded fetching, crawling, native text
// extraction, chunking, keyword search -- lives in app/controllers/KnowledgeBase.php.
// The actions here are deliberately thin: resolve identity, validate input,
// call the library, shape JSON.
//
// Two auth models, matching the existing split in this controller:
//   - Everything the Flutter editor calls is SESSION-authenticated, like
//     aiConnectorsAction.
//   - aiKbSearchAction is INTERNAL-KEY authenticated only, like
//     resolveAgentAction and aiConnectorTokenAction, because its caller is
//     mcp_email_server.js during a live phone call, which has no session.

/** Session identity for the editor-facing actions, or null when unauthenticated. */
private function kbSessionContext()
{
    $session = $this->session->get("auth") ?? [];
    $domainUuid = $session['domain_uuid'] ?? '';
    $userUuid   = $session['user_uuid'] ?? '';
    if (empty($domainUuid) || empty($userUuid)) {
        return null;
    }
    return ['domainUuid' => $domainUuid, 'userUuid' => $userUuid, 'subdomain' => $session['subdomain'] ?? ''];
}

/**
 * POST /teams/aikbupload  (multipart: agentId + file)
 *
 * Extracts text from the uploaded file, chunks it, stores it, and returns the
 * new source record. The file itself is never written to disk anywhere -- it
 * is read from the PHP temp path and only the extracted TEXT is kept, so
 * there is no upload directory to configure or clean up.
 */
public function aiKbUploadAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $agentId = trim((string) $this->request->getPost('agentId', 'string', ''));
        if ($agentId === '') {
            throw new \Exception("agentId is required.");
        }
        if (!$this->request->hasFiles()) {
            throw new \Exception("No file was uploaded.");
        }

        $files = $this->request->getUploadedFiles();
        $file  = $files[0];

        if ($file->getError()) {
            throw new \Exception("The upload did not complete. Please try again.");
        }
        if ($file->getSize() > \KnowledgeBase::MAX_UPLOAD_BYTES) {
            throw new \Exception("That file is larger than the " .
                round(\KnowledgeBase::MAX_UPLOAD_BYTES / 1048576) . " MB limit.");
        }

        $fileName = $file->getName();
        $tmpPath  = $file->getTempName();

        $extracted = \KnowledgeBase::extractDocument($tmpPath, $fileName);

        $sourceId = \KnowledgeBase::newId();
        $source = [
            'id'          => $sourceId,
            'agentId'     => $agentId,
            'type'        => 'document',
            'title'       => $extracted['ok'] ? $extracted['title'] : pathinfo($fileName, PATHINFO_FILENAME),
            'url'         => '',
            'fileName'    => $fileName,
            'bytes'       => (int) $file->getSize(),
            'status'      => $extracted['ok'] ? 'ready' : 'error',
            'error'       => $extracted['ok'] ? '' : $extracted['error'],
            'docCount'    => 0,
            'chunkCount'  => 0,
            'createdDate' => date('c'),
        ];

        // An extraction failure is still SAVED, with its message. A row the admin can see
        // and delete ("PDF needs poppler-utils") is far better than an upload that silently
        // vanishes and leaves them guessing.
        if ($extracted['ok']) {
            $stored = \KnowledgeBase::storeDoc($this->redis, $ctx['domainUuid'], $sourceId, [
                'title' => $extracted['title'],
                'url'   => '',
                'text'  => $extracted['text'],
            ]);
            if ($stored === null) {
                $source['status'] = 'error';
                $source['error']  = 'That file contained no readable text.';
            } else {
                $source['docCount']   = 1;
                $source['chunkCount'] = $stored['chunkCount'];
            }
        }

        \KnowledgeBase::saveSource($this->redis, $ctx['domainUuid'], $agentId, $source);

        return $this->response->setJsonContent(["result" => "success", "source" => $source]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * POST /teams/aikbcrawl   {agentId, url, maxPages?, maxDepth?}
 *
 * DISCOVERY ONLY -- fetches the site and returns the pages it found so the
 * admin can choose which ones matter. Nothing is stored in the knowledge
 * base yet; the chosen pages are re-fetched by aiKbCrawlSaveAction.
 *
 * Runs synchronously (this application has no job queue) but is hard-bounded
 * by KnowledgeBase::CRAWL_BUDGET_SECONDS, so it always returns -- truncated
 * if it must be, never hanging.
 */
public function aiKbCrawlAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data     = $this->request->getJsonRawBody(true) ?: [];
        $agentId  = trim((string) ($data['agentId'] ?? ''));
        $url      = trim((string) ($data['url'] ?? ''));
        $maxPages = (int) ($data['maxPages'] ?? \KnowledgeBase::MAX_PAGES_DEFAULT);
        $maxDepth = (int) ($data['maxDepth'] ?? \KnowledgeBase::MAX_DEPTH_DEFAULT);

        if ($agentId === '') { throw new \Exception("agentId is required."); }
        if ($url === '')     { throw new \Exception("A website address is required."); }
        if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . $url; }

        // Fail fast and clearly on an unfetchable address, before the crawl starts.
        list($ok, $ip, $reason) = \KnowledgeBase::assertPublicUrl($url);
        if (!$ok) { throw new \Exception($reason); }

        // A long crawl must not be killed mid-flight by the default script timeout.
        @set_time_limit(\KnowledgeBase::CRAWL_BUDGET_SECONDS + 30);

        $result  = \KnowledgeBase::crawl($url, $maxPages, $maxDepth);
        $crawlId = \KnowledgeBase::newId();

        // Staged with a TTL: discovery is worthless once the admin has walked away, and
        // this keeps abandoned crawls from accumulating.
        $this->redis->setex(
            \KnowledgeBase::crawlKey($ctx['domainUuid'], $crawlId),
            3600,
            json_encode([
                'rootUrl' => $url,
                'agentId' => $agentId,
                'pages'   => $result['pages'],
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );

        return $this->response->setJsonContent([
            "result"    => "success",
            "crawlId"   => $crawlId,
            "rootUrl"   => $url,
            "pages"     => $result['pages'],
            "truncated" => $result['truncated'],
            "warnings"  => $result['warnings'],
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * POST /teams/aikbcrawlsave   {agentId, crawlId, urls[]}
 *
 * Re-fetches exactly the pages the admin ticked, extracts and stores them.
 * Only URLs that were actually part of that crawl are accepted -- the list
 * comes back through the browser, so it cannot be trusted to name arbitrary
 * addresses for the server to fetch.
 */
public function aiKbCrawlSaveAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data    = $this->request->getJsonRawBody(true) ?: [];
        $agentId = trim((string) ($data['agentId'] ?? ''));
        $crawlId = trim((string) ($data['crawlId'] ?? ''));
        $urls    = isset($data['urls']) && is_array($data['urls']) ? $data['urls'] : [];

        if ($agentId === '') { throw new \Exception("agentId is required."); }
        if (empty($urls))    { throw new \Exception("Select at least one page to save."); }

        $staged = $this->redis->get(\KnowledgeBase::crawlKey($ctx['domainUuid'], $crawlId));
        if (empty($staged)) {
            throw new \Exception("That crawl has expired. Please fetch the pages again.");
        }
        $stagedData = json_decode($staged, true);
        if (!is_array($stagedData) || empty($stagedData['pages'])) {
            throw new \Exception("That crawl is no longer usable. Please fetch the pages again.");
        }

        // Allowlist: only URLs this crawl actually discovered.
        $allowed = [];
        foreach ($stagedData['pages'] as $p) { $allowed[$p['url']] = true; }
        $selected = [];
        foreach ($urls as $u) {
            $u = (string) $u;
            if (isset($allowed[$u])) { $selected[] = $u; }
        }
        if (empty($selected)) {
            throw new \Exception("None of the selected pages belong to that crawl. Please fetch the pages again.");
        }
        $selected = array_slice($selected, 0, \KnowledgeBase::MAX_DOCS_PER_SOURCE);

        $rootUrl = (string) $stagedData['rootUrl'];
        $host    = parse_url($rootUrl, PHP_URL_HOST);

        // One website == one source. Re-saving replaces the previous selection rather than
        // accumulating duplicates of the same site.
        $sourceId = null;
        foreach (\KnowledgeBase::listSources($this->redis, $ctx['domainUuid'], $agentId) as $existing) {
            if (($existing['type'] ?? '') === 'website' && ($existing['rootUrl'] ?? '') === $rootUrl) {
                $sourceId = $existing['id'];
                break;
            }
        }
        if ($sourceId === null) { $sourceId = \KnowledgeBase::newId(); }
        else { \KnowledgeBase::clearSourceDocs($this->redis, $ctx['domainUuid'], $sourceId); }

        @set_time_limit(\KnowledgeBase::CRAWL_BUDGET_SECONDS + 30);

        $stored = $this->kbStorePages($ctx['domainUuid'], $sourceId, $selected);

        $source = [
            'id'           => $sourceId,
            'agentId'      => $agentId,
            'type'         => 'website',
            'title'        => $host ?: $rootUrl,
            'url'          => $rootUrl,
            'rootUrl'      => $rootUrl,
            'fileName'     => '',
            'selectedUrls' => $selected,
            'status'       => $stored['docCount'] > 0 ? 'ready' : 'error',
            'error'        => $stored['docCount'] > 0 ? '' : 'None of the selected pages could be read.',
            'docCount'     => $stored['docCount'],
            'chunkCount'   => $stored['chunkCount'],
            'createdDate'  => date('c'),
        ];
        \KnowledgeBase::saveSource($this->redis, $ctx['domainUuid'], $agentId, $source);

        return $this->response->setJsonContent([
            "result"   => "success",
            "source"   => $source,
            "warnings" => $stored['warnings'],
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/** Fetches and stores a list of page URLs. Shared by crawl-save and re-crawl. */
private function kbStorePages($domainUuid, $sourceId, array $urls)
{
    $docCount = 0;
    $chunkCount = 0;
    $warnings = [];

    foreach ($urls as $pageUrl) {
        list($status, $ctype, $body, $finalUrl, $err) = \KnowledgeBase::httpGet($pageUrl);
        if ($err !== null || $status < 200 || $status >= 300 || stripos($ctype, 'text/html') === false) {
            if (count($warnings) < 5) {
                $warnings[] = $pageUrl . ' -- ' . ($err ?: ('HTTP ' . $status));
            }
            continue;
        }
        $parsed = \KnowledgeBase::extractHtml($body, $finalUrl);
        if (trim($parsed['text']) === '') {
            if (count($warnings) < 5) { $warnings[] = $pageUrl . ' -- no readable text.'; }
            continue;
        }
        $stored = \KnowledgeBase::storeDoc($this->redis, $domainUuid, $sourceId, [
            'title'   => $parsed['title'] !== '' ? $parsed['title'] : $finalUrl,
            'url'     => $finalUrl,
            'text'    => $parsed['text'],
            'snippet' => $parsed['snippet'],
        ]);
        if ($stored !== null) {
            $docCount++;
            $chunkCount += $stored['chunkCount'];
        }
    }

    return ['docCount' => $docCount, 'chunkCount' => $chunkCount, 'warnings' => $warnings];
}

/**
 * POST /teams/aikbrecrawl   {agentId, sourceId}
 *
 * Refreshes an existing website source by re-fetching the pages already
 * chosen. Deliberately does NOT re-discover: the admin curated that list,
 * and silently adding pages they never approved would be wrong.
 */
public function aiKbRecrawlAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data     = $this->request->getJsonRawBody(true) ?: [];
        $agentId  = trim((string) ($data['agentId'] ?? ''));
        $sourceId = trim((string) ($data['sourceId'] ?? ''));
        if ($agentId === '' || $sourceId === '') {
            throw new \Exception("agentId and sourceId are required.");
        }

        // Membership check: the source must belong to THIS agent, in THIS tenant.
        if (!$this->redis->sIsMember(\KnowledgeBase::agentSourcesKey($ctx['domainUuid'], $agentId), $sourceId)) {
            throw new \Exception("That source does not belong to this agent.");
        }

        $source = \KnowledgeBase::loadSource($this->redis, $ctx['domainUuid'], $sourceId);
        if ($source === null) { throw new \Exception("That source no longer exists."); }
        if (($source['type'] ?? '') !== 'website') { throw new \Exception("Only website sources can be re-crawled."); }

        $urls = isset($source['selectedUrls']) && is_array($source['selectedUrls']) ? $source['selectedUrls'] : [];
        if (empty($urls)) { throw new \Exception("This site has no saved pages to refresh."); }

        @set_time_limit(\KnowledgeBase::CRAWL_BUDGET_SECONDS + 30);

        \KnowledgeBase::clearSourceDocs($this->redis, $ctx['domainUuid'], $sourceId);
        $stored = $this->kbStorePages($ctx['domainUuid'], $sourceId, $urls);

        $source['docCount']   = $stored['docCount'];
        $source['chunkCount'] = $stored['chunkCount'];
        $source['status']     = $stored['docCount'] > 0 ? 'ready' : 'error';
        $source['error']      = $stored['docCount'] > 0 ? '' : 'None of the saved pages could be read.';
        \KnowledgeBase::saveSource($this->redis, $ctx['domainUuid'], $agentId, $source);

        return $this->response->setJsonContent([
            "result"   => "success",
            "source"   => $source,
            "warnings" => $stored['warnings'],
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/** GET /teams/aikbsources?agentId=...  -> every source bound to that agent. */
public function aiKbSourcesAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $agentId = trim((string) $this->request->getQuery('agentId', 'string', ''));
        if ($agentId === '') { throw new \Exception("agentId is required."); }

        return $this->response->setJsonContent([
            "result"  => "success",
            "sources" => \KnowledgeBase::listSources($this->redis, $ctx['domainUuid'], $agentId),
        ]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/** POST /teams/aikbdelete   {agentId, sourceId} */
public function aiKbDeleteAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data     = $this->request->getJsonRawBody(true) ?: [];
        $agentId  = trim((string) ($data['agentId'] ?? ''));
        $sourceId = trim((string) ($data['sourceId'] ?? ''));
        if ($agentId === '' || $sourceId === '') {
            throw new \Exception("agentId and sourceId are required.");
        }

        if (!$this->redis->sIsMember(\KnowledgeBase::agentSourcesKey($ctx['domainUuid'], $agentId), $sourceId)) {
            throw new \Exception("That source does not belong to this agent.");
        }

        \KnowledgeBase::deleteSource($this->redis, $ctx['domainUuid'], $agentId, $sourceId);
        return $this->response->setJsonContent(["result" => "success"]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * POST /teams/aikbsearch   {subdomain, agentId, query, topK}
 * header: X-Internal-Key
 *
 * The search_knowledge_base MCP tool during a live call. NOT session
 * authenticated and must never be reachable from a browser -- same channel
 * and same reasoning as resolveAgentAction/contactsByNumberAction.
 *
 * `query` is the only model-influenced input anywhere in this feature. It is
 * exclusively tokenized and substring-matched inside KnowledgeBase::search();
 * it is never interpolated into a Redis key, a command, or a path.
 */
public function aiKbSearchAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $internalKey = $this->request->getHeader("X-Internal-Key");
        if ($internalKey !== "RingQ-Internal-Secret-818849038148786300654846001886") {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data      = $this->request->getJsonRawBody(true) ?: [];
        $subdomain = trim((string) ($data['subdomain'] ?? ''));
        $agentId   = trim((string) ($data['agentId'] ?? ''));
        $query     = trim((string) ($data['query'] ?? ''));
        $topK      = (int) ($data['topK'] ?? 4);

        if ($subdomain === '' || $agentId === '') {
            throw new \Exception("subdomain and agentId are required");
        }
        // An empty query is not an error -- return nothing and let the model
        // fall back rather than surfacing an exception mid-call.
        if ($query === '') {
            return $this->response->setJsonContent(["result" => "success", "results" => []]);
        }

        // Tenant is derived from the subdomain, never from agentId alone.
        $vDomain = \VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $subdomain],
        ]);
        if (!$vDomain) { throw new \Exception("Unknown subdomain: " . $subdomain); }

        $results = \KnowledgeBase::search($this->redis, $vDomain->domain_uuid, $agentId, $query, $topK);

        return $this->response->setJsonContent(["result" => "success", "results" => $results]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

// ---------------------------------------------------------------------------------------------
// AI CALL RECORD + HAND-OFF NOTE  (see AiCallRecord.php for the storage design)
// ---------------------------------------------------------------------------------------------

/**
 * POST /teamsai/saveaicall   (voice bridge only: X-Internal-Key)
 * {subdomain, callUuid, transcript[], summary, sentiment, intent, callerName, details[], nextStep,
 *  outcome, transferTo, transferReason, agentName, agentExtension, callerNumber, voiceMode,
 *  startedAt, endedAt, final}
 *
 * Merges into the stored record, then tries to attach it to the call's QRingqCdr row. The row is
 * usually not there yet (FreeSWITCH writes it at hang-up); that is fine -- the record stays in Redis
 * and Reports attaches it when the call is opened.
 */
public function saveAiCallAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        if (!$this->request->isPost()) {
            throw new \Exception("Invalid request method");
        }
        $internalKey = (string) $this->request->getHeader("X-Internal-Key");
        if (!hash_equals("RingQ-Internal-Secret-818849038148786300654846001886", $internalKey)) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }

        $data = $this->request->getJsonRawBody(true);
        if (!is_array($data)) { throw new \Exception("Invalid body"); }

        $subdomain = trim((string) ($data['subdomain'] ?? ''));
        $vDomain = $subdomain === '' ? null : \VDomains::findFirst([
            "conditions" => "domain_name = :domain_name:",
            "bind" => ["domain_name" => $subdomain],
        ]);
        if (!$vDomain) { throw new \Exception("Unknown subdomain"); }
        $domainUuid = $vDomain->domain_uuid;

        $rec = \AiCallRecord::clean($data);
        if (!\AiCallRecord::isCallUuid($rec['callUuid'])) { throw new \Exception("callUuid is required"); }

        $rec = \AiCallRecord::merge(\AiCallRecord::load($this->redis, $domainUuid, $rec['callUuid']), $rec);
        \AiCallRecord::store($this->redis, $domainUuid, $rec);
        \AiCallRecord::indexRecent($this->redis, $domainUuid, $rec);
        $attached = \AiCallRecord::attachToCdr($this->redis, $domainUuid, $rec['callUuid']);

        return $this->response->setJsonContent(["result" => "success", "attachedToCdr" => $attached]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * POST /teamsai/getaicall {callerNumber}  or  GET /teamsai/getaicall?callUuid=...
 * (signed-in user; scoped to their own domain). By number it returns the latest hand-off for that
 * caller in the last 15 minutes -- what the web app asks for when a call starts ringing.
 *
 * The hand-off note for a transferred call: who is calling, what they want, what the AI already told
 * them, and the transcript. Returns {record: null} when there is nothing for that call (a call that
 * never went through the AI) so a screen can simply not show the card.
 */
public function getAiCallAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }
        // POST {callerNumber} from the web app (a phone number does not belong in a URL); GET ?callUuid=
        // still works for a person or tool that already has the call's uuid.
        $body = $this->request->isPost() ? $this->request->getJsonRawBody(true) : [];
        if (!is_array($body)) { $body = []; }
        $callUuid     = trim((string) ($body['callUuid'] ?? $this->request->getQuery('callUuid', 'string', '')));
        $callerNumber = trim((string) ($body['callerNumber'] ?? ''));

        if ($callUuid !== '') {
            if (!\AiCallRecord::isCallUuid($callUuid)) { throw new \Exception("callUuid is not valid"); }
            $record = \AiCallRecord::load($this->redis, $ctx['domainUuid'], $callUuid);
        } elseif ($callerNumber !== '') {
            // What the browser phone has when a call rings: the caller's number. Latest hand-off only, and
            // only once per person -- see AiCallRecord::claimDelivery().
            $record = \AiCallRecord::findRecent($this->redis, $ctx['domainUuid'], $callerNumber);
            if ($record && !\AiCallRecord::claimDelivery($this->redis, $ctx['domainUuid'], (string) $record['callUuid'], (string) $ctx['userUuid'])) {
                $record = null;
            }
        } else {
            throw new \Exception("callUuid or callerNumber is required");
        }

        return $this->response->setJsonContent(["result" => "success", "record" => $record]);

    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

// =====================================================================
// MR AI changes - starts
// AI RECEPTIONISTS: Status and Restart (the two buttons on the AI Receptionists page)
//
//   GET  /teamsai/aireceptioniststatus   how the voice bridge is doing, its recent log and calls, packages, module
//   POST /teamsai/aireceptionistrestart  restart it; installs any missing AI package first if it will not start
//
// The bridge (node/claude_server.js) answers on its loopback port with the internal key, so while it is running
// both are plain HTTP and need no root. When it is NOT running (a missing package keeps it from starting) Restart
// runs app/scripts/ai-receptionist-control.sh through sudo: that installs only the AI packages that are absent from
// the node folder, loads the FreeSWITCH module if needed and starts the pm2 apps.
// =====================================================================

private const AI_BRIDGE_CONTROL = 'http://127.0.0.1:8082';
// Installed by an administrator, once, in a folder only root can write to (see the header of app/scripts/ai-receptionist-control.sh).
private const AI_REPAIR_SCRIPT = '/usr/local/sbin/ringq-ai-receptionist-control.sh';
private const AI_INTERNAL_KEY = 'RingQ-Internal-Secret-818849038148786300654846001886';

/** Signed-in administrator, or null after answering 401 / 403 itself. */
private function aiOpsSession()
{
    $session = $this->session->get("auth") ?? [];
    if (empty($session['domain_uuid']) || empty($session['user_uuid'])) {
        $this->response->setStatusCode(401, "Unauthorized");
        $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        return null;
    }
    // Restarting ends every AI call on the server, so plain users may not (same rule as the voice settings).
    $mode = $this->redis->get(($session['extension'] ?? '') . "-" . ($session['subdomain'] ?? '') . "-permission");
    if ($mode !== null && $mode !== false && intval($mode) === 10) {
        $this->response->setStatusCode(403, "Forbidden");
        $this->response->setJsonContent(["result" => "error", "message" => "Only an administrator can use this."]);
        return null;
    }
    return $session;
}

/** One request to the bridge's control endpoint. [httpStatus, decodedJson|null, curlError] */
private function aiBridgeRequest(string $method, string $path, int $timeoutSeconds = 4): array
{
    $ch = curl_init(self::AI_BRIDGE_CONTROL . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_HTTPHEADER     => ['X-Internal-Key: ' . self::AI_INTERNAL_KEY, 'Accept: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [$status, is_array($json) ? $json : null, $error];
}

/** Is the FreeSWITCH module the live relay streams through loaded? true | false | null (FreeSWITCH not answering). */
private function aiRelayModuleLoaded(): ?bool
{
    $out = trim((string) @shell_exec("timeout 5 /usr/bin/fs_cli -x 'module_exists mod_openai_audio_stream' 2>/dev/null"));
    if ($out === 'true') return true;
    if ($out === 'false') return false;
    return null;
}

public function aiReceptionistStatusAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    $this->response->setHeader("Cache-Control", "no-store");

    try {
        if ($this->aiOpsSession() === null) {
            return $this->response;
        }
        $lines = max(20, min(400, (int) $this->request->getQuery('lines', 'int', 150)));
        [$status, $bridge, $error] = $this->aiBridgeRequest('GET', '/status?lines=' . $lines);
        $online = $status === 200 && is_array($bridge) && !empty($bridge['ok']);

        $problems = [];
        if (!$online) {
            $problems[] = $error !== ''
                ? "The AI voice bridge is not answering. Press Restart to start it."
                : "The AI voice bridge answered with HTTP " . $status . ". Press Restart.";
        } elseif (!empty($bridge['packages']['missing'])) {
            $problems[] = "Missing packages: " . implode(', ', $bridge['packages']['missing']) . ". Press Restart to install them.";
        }
        $module = $this->aiRelayModuleLoaded();
        if ($module === false) {
            $problems[] = "The FreeSWITCH module mod_openai_audio_stream is not loaded, so calls cannot stream. Press Restart.";
        }

        return $this->response->setJsonContent([
            "result"  => "success",
            "content" => [
                "online"        => $online,
                "bridge"        => $online ? $bridge : null,
                "moduleLoaded"  => $module,
                "repairScript"  => is_file(self::AI_REPAIR_SCRIPT),
                "problems"      => $problems,
                "checkedAt"     => gmdate('c'),
            ],
        ]);
    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

public function aiReceptionistRestartAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    $this->response->setHeader("Cache-Control", "no-store");

    try {
        if ($this->aiOpsSession() === null) {
            return $this->response;
        }
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, "Method Not Allowed");
            return $this->response->setJsonContent(["result" => "error", "message" => "POST required."]);
        }

        $steps = [];
        [$status, $bridge] = $this->aiBridgeRequest('GET', '/status?lines=1');
        $online = $status === 200 && is_array($bridge) && !empty($bridge['ok']);
        $missing = $online ? (array) ($bridge['packages']['missing'] ?? []) : [];
        $oldPid = $online ? (int) ($bridge['pid'] ?? 0) : 0;
        $script = self::AI_REPAIR_SCRIPT;

        // 1. Running and healthy: it restarts itself, pm2 brings it back. No root involved.
        if ($online && count($missing) === 0) {
            [$rs, $rj] = $this->aiBridgeRequest('POST', '/restart');
            if ($rs !== 200) {
                throw new \Exception("The bridge refused the restart (HTTP " . $rs . ").");
            }
            $steps[] = "Asked the bridge to restart" . (isset($rj['endedCalls']) ? " (" . (int) $rj['endedCalls'] . " call(s) in progress ended)" : "");
        } else {
            // 2. Not running, or running without a package: the repair script (needs root, so through sudo).
            if (!is_file($script)) {
                throw new \Exception("The bridge is not running and the repair script is not on this server yet "
                    . "(" . $script . "). Install it as root (see app/scripts/ai-receptionist-control.sh), or run: pm2 restart ai-agent");
            }
            $run = "/bin/bash " . escapeshellarg($script) . " repair";
            if (is_dir("/run/systemd/system") && is_executable("/usr/bin/systemd-run")) {
                // php-fpm's sandbox keeps /usr read-only for us; the script runs as its own unit.
                $run = "/usr/bin/systemd-run --quiet --wait --collect --pipe -p RuntimeMaxSec=240 " . $run;
            }
            $raw = (string) @shell_exec("timeout 260 /usr/bin/sudo -n " . $run . " 2>&1");
            $result = null;
            foreach (array_reverse(preg_split('/\R/', trim($raw))) as $line) {
                $decoded = json_decode(trim($line), true);
                if (is_array($decoded) && array_key_exists("ok", $decoded)) {
                    $result = $decoded;
                    break;
                }
            }
            if ($result === null) {
                $why = stripos($raw, 'a password is required') !== false || stripos($raw, 'not allowed') !== false
                    ? "The web server may not run the repair script (sudo). Allow exactly this one command, nothing broader: www-data ALL=(root) NOPASSWD: " . $run
                    : "The repair script gave no result. See /var/log/ringq-ai-receptionist.log on the server.";
                throw new \Exception($why);
            }
            foreach ((array) ($result['steps'] ?? []) as $s) {
                $steps[] = (string) $s;
            }
            if (empty($result['ok'])) {
                return $this->response->setJsonContent([
                    "result" => "error",
                    "message" => (string) ($result['message'] ?? "The restart failed."),
                    "content" => ["steps" => $steps],
                ]);
            }
        }

        // 3. Wait for it to answer again.
        $back = false;
        for ($i = 0; $i < 25; $i++) {
            sleep(1);
            [$s2, $b2] = $this->aiBridgeRequest('GET', '/status?lines=1', 2);
            if ($s2 === 200 && is_array($b2) && !empty($b2['ok']) && ((int) ($b2['pid'] ?? 0) !== $oldPid || $oldPid === 0)) {
                $back = true;
                break;
            }
        }
        $steps[] = $back ? "The bridge is answering again" : "The bridge did not answer within 25 seconds";
        if (!$back) {
            return $this->response->setJsonContent([
                "result" => "error",
                "message" => "The bridge did not come back. Open Status to see why.",
                "content" => ["steps" => $steps],
            ]);
        }
        return $this->response->setJsonContent([
            "result"  => "success",
            "message" => "AI receptionist restarted.",
            "content" => ["steps" => $steps],
        ]);
    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}

// =====================================================================
// MR AI changes - starts
// AI RECEPTIONISTS: destination lists for the "Transfer to" / "Point to Extension/PBX target" pickers
//
//   GET /teamsai/aidestinations   {extensions, ivrs, queues, teams, conferences} of the signed-in person's account
//
// The page normally gets these lists from the app's main data request (api/index). When that request returns nothing,
// every list is empty and the picker offers only its built-in "5555 · AI Agent" row. This answers from the database
// directly, so the picker works whatever state the main request is in. Same field names the pickers already read.
// =====================================================================
public function aiDestinationsAction()
{
    $this->view->disable();
    $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
    $this->response->setHeader("Content-Type", "application/json");
    $this->response->setHeader("Cache-Control", "no-store");

    try {
        $ctx = $this->kbSessionContext();
        if ($ctx === null) {
            $this->response->setStatusCode(401, "Unauthorized");
            return $this->response->setJsonContent(["result" => "error", "message" => "Unauthorized"]);
        }
        $bind = ["domain_uuid" => $ctx['domainUuid']];
        $types = [\Phalcon\Db\Column::BIND_PARAM_STR];

        $extensions = [];
        foreach (\VExtensions::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "columns"    => "extension, effective_caller_id_name, directory_first_name, directory_last_name",
            "bind" => $bind, "bindTypes" => $types, "order" => "extension ASC",
        ]) as $row) {
            $number = (string) $row->extension;
            if ($number === '' || in_array($number, ['9997', '9998', '9999'], true)) {
                continue;                                  // RingQ's own support accounts are not somewhere to send a caller
            }
            $name = trim((string) $row->effective_caller_id_name);
            if ($name === '' || $name === $number) {
                $name = trim(((string) $row->directory_first_name) . ' ' . ((string) $row->directory_last_name));
            }
            $extensions[] = ["extension" => $number, "directory_name" => $name];
        }

        $ivrs = [];
        foreach (\VIvrMenus::find([
            "conditions" => "domain_uuid = :domain_uuid: AND ivr_menu_extension > '5000'",
            "columns"    => "ivr_menu_extension, ivr_menu_name",
            "bind" => $bind, "bindTypes" => $types, "order" => "ivr_menu_extension ASC",
        ]) as $row) {
            $ivrs[] = ["ivr_menu_extension" => (string) $row->ivr_menu_extension, "ivr_menu_name" => (string) $row->ivr_menu_name];
        }

        $queues = [];
        foreach (\VCallCenterQueues::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "columns"    => "queue_extension, queue_name",
            "bind" => $bind, "bindTypes" => $types, "order" => "queue_extension ASC",
        ]) as $row) {
            $queues[] = ["queue_extension" => (string) $row->queue_extension, "queue_name" => (string) $row->queue_name];
        }

        $teams = [];
        foreach (\VRingGroups::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "columns"    => "ring_group_extension, ring_group_name",
            "bind" => $bind, "bindTypes" => $types, "order" => "ring_group_extension ASC",
        ]) as $row) {
            $teams[] = ["extension" => (string) $row->ring_group_extension, "group_name" => (string) $row->ring_group_name];
        }

        $conferences = [];
        foreach (\VConferenceCenters::find([
            "conditions" => "domain_uuid = :domain_uuid:",
            "columns"    => "conference_center_extension, conference_center_name",
            "bind" => $bind, "bindTypes" => $types, "order" => "conference_center_extension ASC",
        ]) as $row) {
            $conferences[] = ["conference_center_extension" => (string) $row->conference_center_extension, "conference_center_name" => (string) $row->conference_center_name];
        }

        return $this->response->setJsonContent([
            "result" => "success",
            "extensions" => $extensions, "ivrs" => $ivrs, "queues" => $queues, "teams" => $teams, "conferences" => $conferences,
        ]);
    } catch (\Throwable $e) {
        return $this->response->setJsonContent(["result" => "error", "message" => $e->getMessage()]);
    }
}
// MR AI changes - ends
}
