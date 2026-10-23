<?php
// MR AI changes - starts
/**
 * KnowledgeBase -- per-agent document and website knowledge for the AI receptionist.
 *
 * WHY THIS EXISTS
 * The AI agent editor lets an admin type "Manual Context" free text. That is fine for a
 * paragraph, and useless for a company's actual material. This class lets an agent be
 * backed by uploaded documents and by selected pages of the company's own website, so the
 * voice bridge can answer "what are your opening hours" from the real site rather than from
 * the model's training data.
 *
 * WHY IT LOOKS LIKE THIS (constraints, not preferences)
 *   - There is NO Composer and no vendor/ directory in this application. Every parser here
 *     is therefore native: DOMDocument for HTML, ZipArchive for DOCX, and a shell-out to
 *     pdftotext for PDF. Do not "improve" this by adding a library dependency; it cannot be
 *     installed.
 *   - There is NO embeddings service, vector database or RediSearch module available, so
 *     search() is deterministic keyword scoring (TF-IDF-ish), not semantic retrieval. It is
 *     good enough because the corpus is one company's own pages, where the caller's words
 *     and the page's words usually overlap.
 *   - There is NO job queue or background worker, so crawl() runs inside the web request and
 *     is hard-bounded by a wall-clock budget -- it always returns, truncated if it must.
 *
 * STORAGE (Redis; mirrors the existing mcp_connector:/mcp_connectors: singular-blob + set
 * convention already used by McpController)
 *   kb_source:{domainUuid}:{sourceId}            JSON  one upload or one crawled site
 *   kb_doc:{domainUuid}:{sourceId}:{docId}       JSON  one document/page incl. its chunks
 *   kb_source_docs:{domainUuid}:{sourceId}       SET   docIds belonging to a source
 *   kb_agent_sources:{domainUuid}:{agentId}      SET   AUTHORITATIVE agent -> source binding
 *   kb_crawl:{domainUuid}:{crawlId}              JSON  staged discovery, 1h TTL
 *
 * The agent->source binding lives ONLY in kb_agent_sources. The contextProviders entry in
 * the agent blob is display-only: the Flutter editor round-trips that blob and could drop
 * or stale-overwrite fields, and losing the binding would orphan a tenant's whole corpus.
 *
 * WHERE THIS LIVES
 * app/controllers/, beside its only two callers (TeamsController's aikb* actions and
 * AiController::deleteAction) -- deliberately NOT app/library/. Both callers require_once
 * this file by path, so loading it never depends on which directories the loader happens
 * to register (app/config/loader.php is ionCube-encrypted -- it cannot be inspected or
 * changed from this checkout). Nothing else in the application references this class.
 */
class KnowledgeBase
{
    /** Hard caps. Deliberately conservative -- this runs inside a web request. */
    const MAX_UPLOAD_BYTES     = 10485760;   // 10 MB
    const MAX_PAGE_BYTES       = 2097152;    // 2 MB per fetched page
    const MAX_CRAWL_BYTES      = 20971520;   // 20 MB per crawl
    const MAX_PAGES_DEFAULT    = 20;
    const MAX_PAGES_CAP        = 50;
    const MAX_DEPTH_DEFAULT    = 2;
    const MAX_DEPTH_CAP        = 3;
    const CRAWL_BUDGET_SECONDS = 45;         // wall clock; returns truncated rather than hanging
    const PAGE_TIMEOUT         = 10;
    const CONNECT_TIMEOUT      = 5;
    const MAX_REDIRECTS        = 3;

    const CHUNK_CHARS          = 1200;
    const CHUNK_OVERLAP        = 200;
    const MAX_CHUNKS_PER_DOC   = 120;
    const MAX_DOCS_PER_SOURCE  = 100;
    const SNIPPET_CHARS        = 140;

    const INDEX_MAX_ENTRIES    = 40;
    const INDEX_MAX_CHARS      = 4000;

    const USER_AGENT           = 'RingQ-AI-KB-Crawler/1.0';

    const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'txt', 'md', 'csv', 'html', 'htm'];

    // ------------------------------------------------------------------
    // Redis keys
    // ------------------------------------------------------------------
    public static function sourceKey($domainUuid, $sourceId)      { return "kb_source:{$domainUuid}:{$sourceId}"; }
    public static function docKey($domainUuid, $sourceId, $docId) { return "kb_doc:{$domainUuid}:{$sourceId}:{$docId}"; }
    public static function sourceDocsKey($domainUuid, $sourceId)  { return "kb_source_docs:{$domainUuid}:{$sourceId}"; }
    public static function agentSourcesKey($domainUuid, $agentId) { return "kb_agent_sources:{$domainUuid}:{$agentId}"; }
    public static function crawlKey($domainUuid, $crawlId)        { return "kb_crawl:{$domainUuid}:{$crawlId}"; }

    public static function newId()
    {
        return bin2hex(random_bytes(12));
    }

    /**
     * json_encode() fails outright on invalid UTF-8 and returns false, which would store an
     * empty document with no visible error. Every string extracted from a PDF, a DOCX or a
     * web page goes through this first.
     */
    public static function utf8($s)
    {
        $s = (string) $s;
        if (function_exists('mb_convert_encoding')) {
            $s = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        // Strip control characters except tab/newline.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    }

    // ==================================================================
    // SSRF PROTECTION
    // ==================================================================
    /**
     * Validates that a URL is safe for the SERVER to fetch.
     *
     * This is the most security-sensitive function in the file. The URL comes from an admin
     * in a browser, but the fetch happens from inside the datacentre, where "localhost" and
     * the cloud metadata endpoint (169.254.169.254) are reachable and would otherwise be
     * proxied straight back to the caller. Every hostname is resolved and EVERY resulting
     * address must be public -- a name with one public and one private A record is rejected.
     *
     * Returns [true, ip, null] or [false, null, reason].
     */
    public static function assertPublicUrl($url)
    {
        $parts = @parse_url($url);
        if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
            return [false, null, 'That does not look like a valid web address.'];
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return [false, null, 'Only http and https addresses can be used.'];
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port !== 80 && $port !== 443) {
            return [false, null, 'Only the standard web ports (80 and 443) are allowed.'];
        }

        $host = $parts['host'];

        // A literal IP in the URL is checked directly; a name is resolved and every record
        // checked. dns_get_record is wrapped because it emits warnings for unresolvable names.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) { $ips = array_merge($ips, $v4); }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) { $ips[] = $rec['ipv6']; }
                }
            }
        }

        if (empty($ips)) {
            return [false, null, 'That address could not be resolved.'];
        }

        foreach ($ips as $ip) {
            $public = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if ($public === false) {
                return [false, null, 'That address points to a private or internal network, which cannot be fetched.'];
            }
        }

        // Return the first address so the caller can pin it with CURLOPT_RESOLVE, closing
        // the window where DNS re-resolves to a private address between check and fetch.
        return [true, $ips[0], null];
    }

    /**
     * Fetches one URL as text, following redirects MANUALLY so every hop is re-validated.
     *
     * CURLOPT_FOLLOWLOCATION is deliberately off: with it on, a public URL that 302s to
     * http://169.254.169.254/ would be followed by cURL itself, entirely bypassing
     * assertPublicUrl() -- which is the single most common way an SSRF guard is defeated.
     *
     * Returns [status, contentType, body, finalUrl, error].
     */
    public static function httpGet($url, $maxBytes = self::MAX_PAGE_BYTES)
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            list($ok, $ip, $reason) = self::assertPublicUrl($current);
            if (!$ok) {
                return [0, '', '', $current, $reason];
            }

            $parts  = parse_url($current);
            $scheme = strtolower($parts['scheme']);
            $host   = $parts['host'];
            $port   = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $current,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, // see the comment above -- do not enable
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => self::PAGE_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_USERAGENT      => self::USER_AGENT,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ACCEPT_ENCODING => '',
                // Pin the address we actually validated.
                CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $ip],
                // Abort mid-download once the body exceeds the cap, so a huge or endless
                // response cannot exhaust memory (Content-Length is often absent/lying).
                CURLOPT_NOPROGRESS     => false,
                CURLOPT_PROGRESSFUNCTION => function ($res, $dlTotal, $dlNow) use ($maxBytes) {
                    return ($dlNow > $maxBytes) ? 1 : 0;
                },
            ]);

            $raw       = curl_exec($ch);
            $errno     = curl_errno($ch);
            $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ctype     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $headerLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($errno === CURLE_ABORTED_BY_CALLBACK) {
                return [0, '', '', $current, 'That page is larger than the ' . round($maxBytes / 1048576) . ' MB limit.'];
            }
            if ($errno) {
                return [0, '', '', $current, 'Could not fetch that page (network error).'];
            }

            $headers = substr($raw, 0, $headerLen);
            $body    = substr($raw, $headerLen);

            if ($status >= 300 && $status < 400) {
                if (preg_match('/^\s*Location:\s*(.+?)\s*$/mi', $headers, $m)) {
                    $current = self::resolveUrl($current, trim($m[1]));
                    continue; // re-validated at the top of the next iteration
                }
                return [$status, $ctype, '', $current, 'Redirect without a destination.'];
            }

            return [$status, $ctype, $body, $current, null];
        }

        return [0, '', '', $current, 'Too many redirects.'];
    }

    // ==================================================================
    // URL HANDLING
    // ==================================================================
    /** Resolves a possibly-relative href against a base URL. */
    public static function resolveUrl($base, $href)
    {
        $href = trim($href);
        if ($href === '') { return ''; }
        if (preg_match('#^https?://#i', $href)) { return $href; }
        if (strpos($href, '//') === 0) {
            $b = parse_url($base);
            return (isset($b['scheme']) ? $b['scheme'] : 'https') . ':' . $href;
        }

        $b = parse_url($base);
        if (!$b || empty($b['host'])) { return ''; }
        $scheme = isset($b['scheme']) ? $b['scheme'] : 'https';
        $authority = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

        if (strpos($href, '/') === 0) { return $authority . $href; }

        $path = isset($b['path']) ? $b['path'] : '/';
        $dir  = (substr($path, -1) === '/') ? $path : dirname($path);
        if ($dir === '.' || $dir === DIRECTORY_SEPARATOR) { $dir = '/'; }
        if (substr($dir, -1) !== '/') { $dir .= '/'; }

        $combined = $authority . $dir . $href;

        // Collapse ../ and ./ segments.
        $p = parse_url($combined);
        $segments = explode('/', isset($p['path']) ? $p['path'] : '/');
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') { continue; }
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }
        $clean = '/' . implode('/', $out);
        return $authority . $clean . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    /**
     * Canonical form used for dedupe: drops the fragment and tracking parameters, sorts the
     * remaining query, and normalizes the trailing slash. Without this a crawl re-fetches
     * the same page under ?utm_source=... a dozen times and burns the page budget on it.
     */
    public static function normalizeUrl($url)
    {
        $p = @parse_url($url);
        if (!$p || empty($p['host'])) { return ''; }

        $scheme = strtolower(isset($p['scheme']) ? $p['scheme'] : 'https');
        $host   = strtolower($p['host']);
        $port   = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        $path   = isset($p['path']) ? $p['path'] : '/';
        if ($path === '') { $path = '/'; }
        if ($path !== '/' && substr($path, -1) === '/') { $path = rtrim($path, '/'); }

        $query = '';
        if (!empty($p['query'])) {
            parse_str($p['query'], $params);
            foreach (array_keys($params) as $k) {
                $lk = strtolower($k);
                if (strpos($lk, 'utm_') === 0 || in_array($lk, ['fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'], true)) {
                    unset($params[$k]);
                }
            }
            ksort($params);
            if (!empty($params)) { $query = '?' . http_build_query($params); }
        }

        $portPart = (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))
            ? '' : ':' . $port;

        return $scheme . '://' . $host . $portPart . $path . $query;
    }

    /** True when two URLs share scheme+host+port. The crawl never leaves the origin. */
    public static function sameOrigin($a, $b)
    {
        $pa = @parse_url($a); $pb = @parse_url($b);
        if (!$pa || !$pb || empty($pa['host']) || empty($pb['host'])) { return false; }
        $sa = strtolower(isset($pa['scheme']) ? $pa['scheme'] : 'https');
        $sb = strtolower(isset($pb['scheme']) ? $pb['scheme'] : 'https');
        $porta = isset($pa['port']) ? (int) $pa['port'] : ($sa === 'https' ? 443 : 80);
        $portb = isset($pb['port']) ? (int) $pb['port'] : ($sb === 'https' ? 443 : 80);
        return $sa === $sb && strtolower($pa['host']) === strtolower($pb['host']) && $porta === $portb;
    }

    // ==================================================================
    // EXTRACTION
    // ==================================================================
    /**
     * HTML -> ['title','text','snippet','links'].
     *
     * Chrome/navigation furniture (nav, header, footer, aside, form, script, style) is
     * removed before the text is taken -- otherwise every page in the corpus begins with the
     * same menu, which both wastes chunk space and makes keyword scoring useless because the
     * menu words appear on every single page.
     */
    public static function extractHtml($html, $baseUrl = '')
    {
        $result = ['title' => '', 'text' => '', 'snippet' => '', 'links' => []];
        if (trim((string) $html) === '') { return $result; }

        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        // The XML encoding hint is what makes DOMDocument treat the bytes as UTF-8; without
        // it, accented characters come out mojibake.
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($doc);

        // Meta description makes a much better snippet than the first line of body text,
        // because it is written to be a summary.
        $metaDesc = '';
        foreach ($xpath->query('//meta[translate(@name,"DESCRIPTION","description")="description"]/@content') as $n) {
            $metaDesc = trim($n->nodeValue);
            if ($metaDesc !== '') { break; }
        }

        foreach ($xpath->query('//title') as $n) {
            $result['title'] = trim($n->textContent);
            break;
        }

        // Links are collected BEFORE the furniture is stripped -- the site's main navigation
        // is usually the best (sometimes only) source of internal links.
        if ($baseUrl !== '') {
            foreach ($xpath->query('//a/@href') as $n) {
                $href = trim($n->nodeValue);
                if ($href === '' || $href[0] === '#') { continue; }
                if (preg_match('#^(mailto:|tel:|javascript:|data:|file:)#i', $href)) { continue; }
                $abs = self::resolveUrl($baseUrl, $href);
                if ($abs !== '') { $result['links'][] = $abs; }
            }
            $result['links'] = array_values(array_unique($result['links']));
        }

        foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//aside|//form|//svg|//iframe') as $n) {
            if ($n->parentNode) { $n->parentNode->removeChild($n); }
        }

        if ($result['title'] === '') {
            foreach ($xpath->query('//h1') as $n) { $result['title'] = trim($n->textContent); break; }
        }

        $body = $xpath->query('//body')->item(0);
        $text = $body ? $body->textContent : $doc->textContent;

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        $result['title']   = self::utf8($result['title']);
        $result['text']    = self::utf8(trim($text));
        $result['snippet'] = self::makeSnippet($result['text'], $metaDesc);
        return $result;
    }

    /** First meaningful line, or the meta description, trimmed at a word boundary. */
    public static function makeSnippet($text, $metaDesc = '')
    {
        $candidate = trim((string) $metaDesc);
        if ($candidate === '') {
            foreach (preg_split('/\n+/u', (string) $text) as $line) {
                $line = trim($line);
                if (mb_strlen($line) >= 20) { $candidate = $line; break; }
            }
        }
        if ($candidate === '') { $candidate = trim(preg_replace('/\s+/u', ' ', (string) $text)); }
        $candidate = self::utf8(preg_replace('/\s+/u', ' ', $candidate));

        if (mb_strlen($candidate) <= self::SNIPPET_CHARS) { return $candidate; }
        $cut = mb_substr($candidate, 0, self::SNIPPET_CHARS);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > 40) { $cut = mb_substr($cut, 0, $sp); }
        return $cut . '...';
    }

    /**
     * Uploaded file -> ['ok','title','text','error'].
     *
     * The file is read from its PHP temp path and never moved anywhere: the extracted TEXT
     * is what we keep, so there is no upload directory to configure, secure or clean up.
     */
    public static function extractDocument($tmpPath, $fileName)
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $title = pathinfo($fileName, PATHINFO_FILENAME);
        $fail = function ($msg) { return ['ok' => false, 'title' => '', 'text' => '', 'error' => $msg]; };

        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return $fail('Unsupported file type ".' . $ext . '". Allowed: ' . implode(', ', self::ALLOWED_EXTENSIONS) . '.');
        }
        if (!is_readable($tmpPath)) {
            return $fail('The uploaded file could not be read.');
        }

        switch ($ext) {
            case 'txt':
            case 'md':
                $text = (string) file_get_contents($tmpPath);
                break;

            case 'csv':
                // Rendered as "column: value | column: value" per row so a keyword search
                // hits the column name as well as the value -- a bare comma-joined row loses
                // the header entirely and scores badly.
                $text = '';
                $fh = @fopen($tmpPath, 'r');
                if (!$fh) { return $fail('The uploaded CSV could not be opened.'); }
                $header = null; $rows = 0;
                while (($row = fgetcsv($fh)) !== false) {
                    if ($header === null) { $header = $row; continue; }
                    if (++$rows > 5000) { break; }
                    $pairs = [];
                    foreach ($row as $i => $cell) {
                        $cell = trim((string) $cell);
                        if ($cell === '') { continue; }
                        $col = isset($header[$i]) ? trim((string) $header[$i]) : ('col' . $i);
                        $pairs[] = ($col !== '' ? $col . ': ' : '') . $cell;
                    }
                    if ($pairs) { $text .= implode(' | ', $pairs) . "\n"; }
                }
                fclose($fh);
                break;

            case 'html':
            case 'htm':
                $parsed = self::extractHtml((string) file_get_contents($tmpPath), '');
                $text = $parsed['text'];
                if ($parsed['title'] !== '') { $title = $parsed['title']; }
                break;

            case 'docx':
                if (!class_exists('ZipArchive')) {
                    return $fail('DOCX files need the PHP zip extension, which is not enabled on this server. Upload a TXT or PDF version instead.');
                }
                $zip = new ZipArchive();
                if ($zip->open($tmpPath) !== true) {
                    return $fail('That DOCX file could not be opened -- it may be corrupt.');
                }
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml === false) {
                    return $fail('That does not look like a Word document.');
                }
                // Convert paragraph/break/tab markup to real whitespace BEFORE stripping
                // tags, or the whole document collapses into one unreadable line.
                $xml  = str_replace(['</w:p>', '<w:br/>', '<w:br />', '<w:tab/>', '<w:tab />'],
                                    ["\n", "\n", "\n", "\t", "\t"], $xml);
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
                break;

            case 'pdf':
                // No PHP PDF parser can be installed (no Composer), so this shells out.
                // Probed first so the failure is an actionable sentence rather than an
                // empty document that silently teaches the AI nothing.
                $probe = @shell_exec('command -v pdftotext 2>/dev/null');
                if (!$probe || trim($probe) === '') {
                    return $fail('PDF text extraction needs poppler-utils (pdftotext) on this server. Install it, or upload a TXT or DOCX version of this document.');
                }
                $out = @shell_exec('pdftotext -layout -enc UTF-8 -q ' . escapeshellarg($tmpPath) . ' - 2>/dev/null');
                $text = (string) $out;
                if (trim($text) === '') {
                    return $fail('No text could be read from that PDF. If it is a scan, it needs OCR first -- upload a text-based PDF or a TXT version.');
                }
                break;

            default:
                return $fail('Unsupported file type.');
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        $text = self::utf8(trim($text));

        if ($text === '') {
            return $fail('That file contained no readable text.');
        }
        return ['ok' => true, 'title' => self::utf8($title), 'text' => $text, 'error' => null];
    }

    // ==================================================================
    // CHUNKING
    // ==================================================================
    /**
     * Splits text into overlapping chunks on paragraph boundaries.
     *
     * The overlap matters: without it, a sentence that straddles a chunk boundary is
     * findable by neither chunk, and "what are your opening hours" misses because "opening"
     * ended one chunk and "hours" began the next.
     */
    public static function chunkText($text)
    {
        $text = trim((string) $text);
        if ($text === '') { return []; }

        $paragraphs = preg_split('/\n{2,}/u', $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') { continue; }

            // A single paragraph longer than a chunk is hard-split on word boundaries.
            while (mb_strlen($para) > self::CHUNK_CHARS) {
                $slice = mb_substr($para, 0, self::CHUNK_CHARS);
                $sp = mb_strrpos($slice, ' ');
                if ($sp === false || $sp < (self::CHUNK_CHARS / 2)) { $sp = self::CHUNK_CHARS; }
                $head = trim(mb_substr($para, 0, $sp));
                if ($current !== '') { $chunks[] = $current; $current = ''; }
                $chunks[] = $head;
                $para = trim(mb_substr($para, $sp));
            }

            $candidate = ($current === '') ? $para : $current . "\n\n" . $para;
            if (mb_strlen($candidate) <= self::CHUNK_CHARS) {
                $current = $candidate;
            } else {
                if ($current !== '') { $chunks[] = $current; }
                $current = $para;
            }
            if (count($chunks) >= self::MAX_CHUNKS_PER_DOC) { break; }
        }
        if ($current !== '' && count($chunks) < self::MAX_CHUNKS_PER_DOC) { $chunks[] = $current; }

        // Prepend the tail of the previous chunk to each subsequent chunk.
        $withOverlap = [];
        foreach ($chunks as $i => $c) {
            if ($i === 0) { $withOverlap[] = $c; continue; }
            $prevTail = mb_substr($chunks[$i - 1], -self::CHUNK_OVERLAP);
            $sp = mb_strpos($prevTail, ' ');
            if ($sp !== false) { $prevTail = mb_substr($prevTail, $sp + 1); }
            $withOverlap[] = trim($prevTail) . "\n" . $c;
        }

        return array_slice($withOverlap, 0, self::MAX_CHUNKS_PER_DOC);
    }

    // ==================================================================
    // CRAWLING
    // ==================================================================
    /**
     * Breadth-first same-origin crawl, hard-bounded by page count, depth, bytes AND wall
     * clock. Returns discovered pages (url/title/snippet/depth) WITHOUT their body text --
     * the admin picks which pages they want and only those are re-fetched and stored, so a
     * 50-page discovery never has to hold 50 page bodies in memory or in Redis.
     *
     * Always returns; `truncated` says whether a limit stopped it early.
     */
    public static function crawl($rootUrl, $maxPages = self::MAX_PAGES_DEFAULT, $maxDepth = self::MAX_DEPTH_DEFAULT)
    {
        $maxPages = max(1, min((int) $maxPages, self::MAX_PAGES_CAP));
        $maxDepth = max(0, min((int) $maxDepth, self::MAX_DEPTH_CAP));

        $started   = microtime(true);
        $pages     = [];
        $warnings  = [];
        $truncated = false;
        $bytes     = 0;

        $rootNorm = self::normalizeUrl($rootUrl);
        if ($rootNorm === '') {
            return ['pages' => [], 'truncated' => false, 'warnings' => ['That does not look like a valid web address.']];
        }

        $disallowed = self::robotsDisallowed($rootNorm);

        $queue = [[$rootNorm, 0]];
        $seen  = [$rootNorm => true];

        while (!empty($queue)) {
            if (count($pages) >= $maxPages) { $truncated = true; break; }
            if ((microtime(true) - $started) > self::CRAWL_BUDGET_SECONDS) {
                $truncated = true;
                $warnings[] = 'Stopped early to stay within the time limit; found ' . count($pages) . ' page(s).';
                break;
            }
            if ($bytes >= self::MAX_CRAWL_BYTES) {
                $truncated = true;
                $warnings[] = 'Stopped early after reaching the total download limit.';
                break;
            }

            list($url, $depth) = array_shift($queue);

            if (self::isDisallowed($url, $disallowed)) { continue; }

            list($status, $ctype, $body, $finalUrl, $err) = self::httpGet($url);
            if ($err !== null) {
                if (count($warnings) < 5) { $warnings[] = $url . ' -- ' . $err; }
                continue;
            }
            if ($status < 200 || $status >= 300) {
                if (count($warnings) < 5) { $warnings[] = $url . ' -- HTTP ' . $status; }
                continue;
            }
            if (stripos($ctype, 'text/html') === false) { continue; }

            $bytes += strlen($body);
            $parsed = self::extractHtml($body, $finalUrl);

            $pages[] = [
                'url'     => $finalUrl,
                'title'   => ($parsed['title'] !== '' ? $parsed['title'] : $finalUrl),
                'snippet' => $parsed['snippet'],
                'depth'   => $depth,
            ];

            if ($depth < $maxDepth) {
                foreach ($parsed['links'] as $link) {
                    if (!self::sameOrigin($rootNorm, $link)) { continue; }
                    $norm = self::normalizeUrl($link);
                    if ($norm === '' || isset($seen[$norm])) { continue; }
                    // Skip obvious non-documents by extension.
                    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico|css|js|zip|gz|mp4|mp3|woff2?|ttf|eot)(\?|$)/i', $norm)) { continue; }
                    $seen[$norm] = true;
                    $queue[] = [$norm, $depth + 1];
                }
            }
        }

        if (!empty($queue) && count($pages) >= $maxPages) { $truncated = true; }

        return ['pages' => $pages, 'truncated' => $truncated, 'warnings' => $warnings];
    }

    /** Best-effort robots.txt: returns Disallow prefixes for User-agent: *. */
    public static function robotsDisallowed($rootUrl)
    {
        $p = @parse_url($rootUrl);
        if (!$p || empty($p['host'])) { return []; }
        $scheme = isset($p['scheme']) ? $p['scheme'] : 'https';
        $robots = $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/robots.txt';

        list($status, $ctype, $body, $finalUrl, $err) = self::httpGet($robots, 262144);
        if ($err !== null || $status < 200 || $status >= 300) { return []; }

        $rules = [];
        $applies = false;
        foreach (preg_split('/\r\n|\r|\n/', (string) $body) as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line));
            if ($line === '') { continue; }
            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                $applies = (trim($m[1]) === '*');
                continue;
            }
            if ($applies && preg_match('/^Disallow:\s*(.*)$/i', $line, $m)) {
                $path = trim($m[1]);
                if ($path !== '') { $rules[] = $path; }
            }
        }
        return $rules;
    }

    public static function isDisallowed($url, array $disallowed)
    {
        if (empty($disallowed)) { return false; }
        $p = @parse_url($url);
        $path = isset($p['path']) ? $p['path'] : '/';
        foreach ($disallowed as $prefix) {
            if ($prefix === '/') { return true; }
            if (strpos($path, $prefix) === 0) { return true; }
        }
        return false;
    }

    // ==================================================================
    // STORAGE
    // ==================================================================
    /**
     * Writes one document/page and registers it against its source.
     * $doc = ['title','url','text'].
     */
    public static function storeDoc($redis, $domainUuid, $sourceId, array $doc)
    {
        $ident = ($doc['url'] !== '' ? $doc['url'] : $doc['title']);
        $docId = substr(sha1($ident), 0, 16);
        $chunks = self::chunkText($doc['text']);
        if (empty($chunks)) { return null; }

        $blob = [
            'docId'   => $docId,
            'title'   => self::utf8($doc['title']),
            'url'     => self::utf8($doc['url']),
            'snippet' => isset($doc['snippet']) && $doc['snippet'] !== ''
                ? self::utf8($doc['snippet'])
                : self::makeSnippet($doc['text']),
            'chunks'  => array_map([self::class, 'utf8'], $chunks),
        ];

        $encoded = json_encode($blob, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) { return null; }

        $redis->set(self::docKey($domainUuid, $sourceId, $docId), $encoded);
        $redis->sAdd(self::sourceDocsKey($domainUuid, $sourceId), $docId);
        return ['docId' => $docId, 'chunkCount' => count($chunks)];
    }

    public static function saveSource($redis, $domainUuid, $agentId, array $source)
    {
        $source['updatedDate'] = date('c');
        $redis->set(self::sourceKey($domainUuid, $source['id']), json_encode($source, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $redis->sAdd(self::agentSourcesKey($domainUuid, $agentId), $source['id']);
        return $source;
    }

    public static function loadSource($redis, $domainUuid, $sourceId)
    {
        $raw = $redis->get(self::sourceKey($domainUuid, $sourceId));
        if (empty($raw)) { return null; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** Every source bound to one agent, newest first. Never includes chunk text. */
    public static function listSources($redis, $domainUuid, $agentId)
    {
        $ids = $redis->sMembers(self::agentSourcesKey($domainUuid, $agentId));
        $out = [];
        foreach ((array) $ids as $sid) {
            $s = self::loadSource($redis, $domainUuid, $sid);
            if ($s !== null) { $out[] = $s; }
        }
        usort($out, function ($a, $b) {
            return strcmp((string) ($b['createdDate'] ?? ''), (string) ($a['createdDate'] ?? ''));
        });
        return $out;
    }

    /** Removes every doc of a source, the source itself, and both set memberships. */
    public static function deleteSource($redis, $domainUuid, $agentId, $sourceId)
    {
        $docIds = $redis->sMembers(self::sourceDocsKey($domainUuid, $sourceId));
        foreach ((array) $docIds as $docId) {
            $redis->del(self::docKey($domainUuid, $sourceId, $docId));
        }
        $redis->del(self::sourceDocsKey($domainUuid, $sourceId));
        $redis->del(self::sourceKey($domainUuid, $sourceId));
        $redis->sRem(self::agentSourcesKey($domainUuid, $agentId), $sourceId);
    }

    /** Drops just the documents of a source, keeping the source record (for re-crawl). */
    public static function clearSourceDocs($redis, $domainUuid, $sourceId)
    {
        $docIds = $redis->sMembers(self::sourceDocsKey($domainUuid, $sourceId));
        foreach ((array) $docIds as $docId) {
            $redis->del(self::docKey($domainUuid, $sourceId, $docId));
        }
        $redis->del(self::sourceDocsKey($domainUuid, $sourceId));
    }

    /** Deletes every knowledge key belonging to one agent. Used when an agent is deleted. */
    public static function deleteAllForAgent($redis, $domainUuid, $agentId)
    {
        $ids = $redis->sMembers(self::agentSourcesKey($domainUuid, $agentId));
        foreach ((array) $ids as $sid) {
            self::deleteSource($redis, $domainUuid, $agentId, $sid);
        }
        $redis->del(self::agentSourcesKey($domainUuid, $agentId));
    }

    /** All doc blobs for an agent. Internal helper for search() and buildIndex(). */
    private static function loadAgentDocs($redis, $domainUuid, $agentId)
    {
        $docs = [];
        $sourceIds = $redis->sMembers(self::agentSourcesKey($domainUuid, $agentId));
        foreach ((array) $sourceIds as $sid) {
            $docIds = $redis->sMembers(self::sourceDocsKey($domainUuid, $sid));
            $keys = [];
            foreach ((array) $docIds as $did) { $keys[] = self::docKey($domainUuid, $sid, $did); }
            foreach (array_chunk($keys, 50) as $batch) {
                if (empty($batch)) { continue; }
                $blobs = $redis->mget($batch);
                foreach ((array) $blobs as $raw) {
                    if (empty($raw)) { continue; }
                    $d = json_decode($raw, true);
                    if (is_array($d) && !empty($d['chunks'])) {
                        $d['sourceId'] = $sid;
                        $docs[] = $d;
                    }
                }
            }
        }
        return $docs;
    }

    // ==================================================================
    // INDEX (inlined into the system prompt by the voice bridge)
    // ==================================================================
    /**
     * The compact "table of contents" the model sees on every turn: one line per document or
     * page. Deliberately capped -- this rides along on EVERY agent-config fetch, and an
     * uncapped index would put the whole corpus back into the prompt, which is exactly what
     * the search tool exists to avoid.
     */
    public static function buildIndex($redis, $domainUuid, $agentId)
    {
        $docs = self::loadAgentDocs($redis, $domainUuid, $agentId);
        $index = [];
        $chars = 0;
        foreach ($docs as $d) {
            if (count($index) >= self::INDEX_MAX_ENTRIES) { break; }
            $entry = [
                'title'      => (string) ($d['title'] ?? ''),
                'url'        => (string) ($d['url'] ?? ''),
                'snippet'    => (string) ($d['snippet'] ?? ''),
                'sourceType' => ((string) ($d['url'] ?? '')) !== '' ? 'website' : 'document',
            ];
            $cost = mb_strlen($entry['title']) + mb_strlen($entry['snippet']) + 8;
            if (($chars + $cost) > self::INDEX_MAX_CHARS) { break; }
            $chars += $cost;
            $index[] = $entry;
        }
        return $index;
    }

    // ==================================================================
    // SEARCH
    // ==================================================================
    /** Words too common to discriminate between passages of one company's own site. */
    private static function stopwords()
    {
        return array_flip([
            'the','and','for','are','but','not','you','your','yours','with','that','this','these','those',
            'have','has','had','was','were','will','would','can','could','should','from','they','them',
            'their','there','here','what','when','where','which','who','whom','how','why','all','any',
            'our','ours','out','about','into','over','then','than','also','been','being','does','did',
            'doing','get','got','let','may','might','must','need','use','using','used','very','just',
        ]);
    }

    public static function tokenize($text)
    {
        $text = mb_strtolower(self::utf8($text));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = self::stopwords();
        $out = [];
        foreach ((array) $parts as $p) {
            if (mb_strlen($p) < 3) { continue; }
            if (isset($stop[$p])) { continue; }
            $out[$p] = true;
            if (count($out) >= 12) { break; }
        }
        return array_keys($out);
    }

    /**
     * Deterministic keyword search over one agent's chunks.
     *
     * No embeddings exist in this stack, so this is TF-IDF-ish scoring plus a few bonuses
     * that matter for this specific corpus: an exact phrase match is worth far more than
     * scattered terms, and a hit in the page TITLE is a strong signal that the whole page is
     * about the topic. Results are capped at 2 chunks per document so one long page cannot
     * crowd out every other page.
     */
    public static function search($redis, $domainUuid, $agentId, $query, $topK = 4)
    {
        $topK = max(1, min((int) $topK, 8));
        $tokens = self::tokenize($query);
        if (empty($tokens)) { return []; }

        $docs = self::loadAgentDocs($redis, $domainUuid, $agentId);
        if (empty($docs)) { return []; }

        $phrase = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $query)));

        // Pass 1: document frequency per token across all chunks.
        $totalChunks = 0;
        $df = array_fill_keys($tokens, 0);
        foreach ($docs as $d) {
            foreach ($d['chunks'] as $chunk) {
                $totalChunks++;
                $lc = mb_strtolower($chunk);
                foreach ($tokens as $t) {
                    if (strpos($lc, $t) !== false) { $df[$t]++; }
                }
            }
        }
        if ($totalChunks === 0) { return []; }

        // Pass 2: score every chunk.
        $scored = [];
        foreach ($docs as $d) {
            $titleLc = mb_strtolower((string) ($d['title'] ?? ''));

            // The title score is computed ONCE per document, up front, and is
            // enough on its own to make a document a candidate.
            //
            // This is load-bearing, not a refinement: a page titled "Opening
            // Hours" whose body reads "we are open Monday to Friday" contains
            // neither "opening" nor "hours" anywhere in its text. Scoring the
            // body first and discarding zero-scoring chunks would make that
            // page unreachable by the query "what are your opening hours" --
            // which is precisely the question it exists to answer. A title is
            // a human-written summary of the page; treat a title match as real
            // evidence, not as a bonus on top of evidence.
            $titleScore = 0.0;
            if ($titleLc !== '') {
                foreach ($tokens as $t) {
                    if (strpos($titleLc, $t) !== false) { $titleScore += 2; }
                }
            }

            foreach ($d['chunks'] as $idx => $chunk) {
                $lc = mb_strtolower($chunk);
                $score = 0.0;
                foreach ($tokens as $t) {
                    $tf = substr_count($lc, $t);
                    if ($tf > 0) {
                        $score += $tf * log(1 + ($totalChunks / (1 + $df[$t])));
                    }
                }
                // Nothing matched in either the body or the title -> not a candidate.
                if ($score <= 0 && $titleScore <= 0) { continue; }
                if ($phrase !== '' && strpos($lc, $phrase) !== false) { $score += 5; }
                $score += $titleScore;
                if ($idx === 0) { $score += 1; }

                $scored[] = [
                    'score'    => $score,
                    'docId'    => (string) ($d['docId'] ?? ''),
                    'chunkIdx' => $idx,
                    'title'    => (string) ($d['title'] ?? ''),
                    'url'      => (string) ($d['url'] ?? ''),
                    'sourceId' => (string) ($d['sourceId'] ?? ''),
                    'text'     => $chunk,
                ];
            }
        }
        if (empty($scored)) { return []; }

        // Deterministic ordering: ties broken by docId then chunk index, so the same query
        // always returns the same passages in the same order.
        usort($scored, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                $c = strcmp($a['docId'], $b['docId']);
                return $c !== 0 ? $c : ($a['chunkIdx'] <=> $b['chunkIdx']);
            }
            return ($b['score'] <=> $a['score']);
        });

        $perDoc = [];
        $out = [];
        foreach ($scored as $s) {
            $seen = isset($perDoc[$s['docId']]) ? $perDoc[$s['docId']] : 0;
            if ($seen >= 2) { continue; }
            $perDoc[$s['docId']] = $seen + 1;
            $out[] = [
                'title' => $s['title'],
                'url'   => $s['url'],
                'score' => round($s['score'], 3),
                'text'  => $s['text'],
            ];
            if (count($out) >= $topK) { break; }
        }
        return $out;
    }
}
// MR AI changes - ends
