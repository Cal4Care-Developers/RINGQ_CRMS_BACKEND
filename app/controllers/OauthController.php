<?php

use Phalcon\Mvc\View;

require_once __DIR__ . '/../library/McpGrant.php';
require_once __DIR__ . '/../library/McpOAuth.php';
require_once __DIR__ . '/../library/McpAccount.php';
require_once __DIR__ . '/../library/McpUserSession.php';

class OauthController extends ControllerBase
{
    const CONSENT_TTL = 600;

    public function resourceAction()
    {
        $this->json();
        $host = $this->host();
        return $this->send(200, [
            'resource' => 'https://' . $host . '/mcp/account',
            'authorization_servers' => ['https://' . $host],
            'scopes_supported' => McpOAuth::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'RingQ',
        ]);
    }

    public function metadataAction()
    {
        $this->json();
        $base = 'https://' . $this->host();
        return $this->send(200, [
            'issuer' => $base,
            'authorization_endpoint' => $base . '/oauth/authorize',
            'token_endpoint' => $base . '/oauth/token',
            'registration_endpoint' => $base . '/oauth/register',
            'revocation_endpoint' => $base . '/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => McpOAuth::SCOPES,
        ]);
    }

    public function registerAction()
    {
        $this->json();
        if (!$this->request->isPost()) {
            return $this->oauthError(405, 'invalid_request', 'Use POST.');
        }
        $vDomain = $this->domainForHost($this->host());
        if ($vDomain === null || !McpAccount::schemaReady($this->db, $this->redis)) {
            return $this->oauthError(404, 'invalid_request', 'Sign-in is not available on this server.');
        }
        $key = McpOAuth::registerRateKey($this->clientIp());
        $n = (int) $this->redis->incr($key);
        if ($n === 1) {
            $this->redis->expire($key, McpOAuth::REGISTER_WINDOW);
        }
        if ($n > McpOAuth::REGISTER_PER_IP) {
            return $this->oauthError(429, 'invalid_request', 'Too many registrations. Try again later.');
        }
        $body = $this->body();
        $client = McpOAuth::registerClient($this->db, $body);
        if ($client === null) {
            return $this->oauthError(400, 'invalid_client_metadata', 'redirect_uris must be https, a loopback http address, or an app scheme, and token_endpoint_auth_method must be none.');
        }
        return $this->send(201, $client);
    }

    public function authorizeAction()
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader('Cache-Control', 'no-store');
        $this->response->setHeader('X-Frame-Options', 'DENY');
        $this->response->setHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $host = $this->host();
        $vDomain = $this->domainForHost($host);
        if ($vDomain === null || !McpAccount::schemaReady($this->db, $this->redis)) {
            return $this->page(404, 'Sign-in is not available', '<p>This RingQ server does not offer sign-in for AI assistants yet.</p>');
        }
        $p = $this->request->isPost() ? $this->request->getPost() : $this->request->getQuery();
        $clientId = (string) ($p['client_id'] ?? '');
        $client = McpOAuth::findClient($this->db, $clientId);
        if ($client === null) {
            return $this->page(400, 'Unknown app', '<p>The app asking to connect is not registered with this server. Remove the connection in the app and add it again.</p>');
        }
        $redirect = (string) ($p['redirect_uri'] ?? '');
        if (!McpOAuth::redirectAllowed($client['redirect_uris'], $redirect)) {
            return $this->page(400, 'Unexpected return address', '<p>The app asked to be sent back to an address it did not register. Nothing was shared.</p>');
        }
        $state = (string) ($p['state'] ?? '');
        if ((string) ($p['response_type'] ?? '') !== 'code') {
            return $this->back($redirect, ['error' => 'unsupported_response_type', 'state' => $state]);
        }
        $challenge = (string) ($p['code_challenge'] ?? '');
        if ((string) ($p['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge)) {
            return $this->back($redirect, ['error' => 'invalid_request', 'error_description' => 'PKCE with S256 is required.', 'state' => $state]);
        }
        $resource = (string) ($p['resource'] ?? '');
        $expected = 'https://' . $host . '/mcp/account';
        if ($resource !== '' && rtrim($resource, '/') !== $expected) {
            return $this->back($redirect, ['error' => 'invalid_target', 'state' => $state]);
        }
        $scope = McpOAuth::normalizeScope($p['scope'] ?? '');

        $auth = $this->session->get('auth');
        $signedIn = is_array($auth) && ($auth['access'] ?? '') === 'registered' && (string) ($auth['domain_uuid'] ?? '') === (string) $vDomain->domain_uuid;
        $settings = McpGrant::settings($this->redis, (string) $vDomain->domain_name);
        $planAllowsWrites = McpAccount::planAllowsWrites(McpAccount::planName($this->db, (string) $vDomain->domain_uuid));

        if ($this->request->isPost() && $signedIn) {
            $nonce = (string) ($p['consent'] ?? '');
            $bound = $this->takeConsent($nonce);
            $fingerprint = $this->fingerprint($clientId, $redirect, $challenge, $state, (string) $auth['user_uuid']);
            if ($bound === null || !hash_equals($bound, $fingerprint)) {
                return $this->page(400, 'This page expired', '<p>Go back to the app and connect again.</p>');
            }
            if ((string) ($p['decision'] ?? '') !== 'allow') {
                $this->auditConsent($vDomain, $auth, $client, 'denied', $scope);
                return $this->back($redirect, ['error' => 'access_denied', 'state' => $state]);
            }
            if (empty($settings['mcp']['enabled'])) {
                return $this->back($redirect, ['error' => 'access_denied', 'error_description' => 'AI access is off for this account.', 'state' => $state]);
            }
            $chosen = ((string) ($p['access'] ?? 'read')) === 'write' && McpAccount::scopeOffered($settings['mcp'], $planAllowsWrites) === 'write' ? 'write' : 'read';
            $code = McpOAuth::issueCode($this->redis, [
                'client_id' => $clientId,
                'client_name' => (string) $client['client_name'],
                'redirect_uri' => $redirect,
                'code_challenge' => $challenge,
                'scope' => $chosen,
                'resource' => $expected,
                'domain_uuid' => (string) $vDomain->domain_uuid,
                'user_uuid' => (string) $auth['user_uuid'],
            ]);
            $this->auditConsent($vDomain, $auth, $client, 'allowed', $chosen);
            return $this->back($redirect, ['code' => $code, 'state' => $state, 'iss' => 'https://' . $host]);
        }

        $tenant = htmlspecialchars((string) $vDomain->domain_name, ENT_QUOTES);
        $app = htmlspecialchars((string) $client['client_name'], ENT_QUOTES);
        $returnHost = htmlspecialchars((string) (parse_url($redirect, PHP_URL_HOST) ?: parse_url($redirect, PHP_URL_SCHEME)), ENT_QUOTES);
        if (!$signedIn) {
            return $this->page(200, 'Sign in to connect ' . $client['client_name'], $this->loginHtml($app, $tenant));
        }
        if (empty($settings['mcp']['enabled'])) {
            return $this->page(403, 'AI access is off', '<p>An account owner has not turned on AI assistants for <strong>' . $tenant . '</strong>. Ask them to switch it on in Global Settings → Advanced → AI &amp; MCP.</p>'
                . '<p><a class="btn" href="' . htmlspecialchars($this->withQuery($redirect, ['error' => 'access_denied', 'state' => $state]), ENT_QUOTES) . '">Return to the app</a></p>');
        }
        $tier = McpAccount::tierForMode($this->permissionModeOf($auth));
        $nonce = $this->putConsent($this->fingerprint($clientId, $redirect, $challenge, $state, (string) $auth['user_uuid']));
        $writes = McpAccount::scopeOffered($settings['mcp'], $planAllowsWrites) === 'write';
        $name = htmlspecialchars(trim(((string) ($auth['firstname'] ?? '')) . ' ' . ((string) ($auth['lastname'] ?? ''))) ?: (string) ($auth['email'] ?? ''), ENT_QUOTES);
        $hidden = '';
        foreach (['client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'code_challenge' => $challenge,
            'code_challenge_method' => 'S256', 'state' => $state, 'scope' => $scope, 'resource' => $resource, 'consent' => $nonce] as $k => $v) {
            $hidden .= '<input type="hidden" name="' . $k . '" value="' . htmlspecialchars($v, ENT_QUOTES) . '">';
        }
        $choices = '<label class="choice"><input type="radio" name="access" value="read" ' . ($scope === 'read' || !$writes ? 'checked' : '') . '><span><strong>Read only</strong><small>See your account the way you see it in RingQ. Nothing is changed.</small></span></label>';
        $choices .= $writes
            ? '<label class="choice"><input type="radio" name="access" value="write" ' . ($scope === 'write' ? 'checked' : '') . '><span><strong>Read &amp; write</strong><small>Also make changes you are allowed to make. High-risk changes wait for an account owner\'s approval.</small></span></label>'
            : '<p class="muted">' . ($planAllowsWrites ? 'Changes are off for this account, so only Read only is offered.' : 'Your RingQ plan includes read-only AI access, so only Read only is offered.') . '</p>';
        $body = '<p class="lead"><strong>' . $app . '</strong> wants to access <strong>' . $tenant . '</strong> as you.</p>'
            . '<div class="who"><span>Signed in as</span><strong>' . $name . '</strong><em>' . htmlspecialchars(McpAccount::tierLabel($tier), ENT_QUOTES) . '</em></div>'
            . '<form method="post" action="/oauth/authorize">' . $hidden
            . '<fieldset><legend>What it may do</legend>' . $choices . '</fieldset>'
            . '<ul class="never"><li>It can never see call recordings, voicemail audio, message contents or passwords.</li><li>It can never do more than your role allows in RingQ.</li><li>It stays connected until you revoke it in Global Settings → Advanced → AI &amp; MCP.</li></ul>'
            . '<p class="muted">You will be returned to <strong>' . $returnHost . '</strong>.</p>'
            . '<div class="actions"><button class="btn ghost" name="decision" value="deny">Cancel</button><button class="btn primary" name="decision" value="allow" autofocus>Allow</button></div>'
            . '</form>';
        return $this->page(200, 'Connect ' . $client['client_name'], $body);
    }

    public function tokenAction()
    {
        $this->json();
        if (!$this->request->isPost()) {
            return $this->oauthError(405, 'invalid_request', 'Use POST.');
        }
        $p = $this->request->getPost();
        if (!$p) {
            $p = $this->body();
        }
        $grant = (string) ($p['grant_type'] ?? '');
        $clientId = (string) ($p['client_id'] ?? '');
        $client = McpOAuth::findClient($this->db, $clientId);
        if ($client === null) {
            return $this->oauthError(401, 'invalid_client', 'Unknown client.');
        }
        if ($grant === 'authorization_code') {
            $code = McpOAuth::consumeCode($this->redis, (string) ($p['code'] ?? ''));
            if ($code === null
                || !hash_equals((string) $code['client_id'], $clientId)
                || (string) $code['redirect_uri'] !== (string) ($p['redirect_uri'] ?? '')
                || !McpOAuth::pkceMatches((string) ($p['code_verifier'] ?? ''), (string) $code['code_challenge'])) {
                return $this->oauthError(400, 'invalid_grant', 'The code is invalid, expired or was already used.');
            }
            $resource = (string) ($p['resource'] ?? '');
            if ($resource !== '' && rtrim($resource, '/') !== (string) $code['resource']) {
                return $this->oauthError(400, 'invalid_target', 'Unexpected resource.');
            }
            if (McpUserSession::lookupUser($this->db, (string) $code['domain_uuid'], (string) $code['user_uuid']) === null) {
                return $this->oauthError(400, 'invalid_grant', 'That user can no longer sign in.');
            }
            return $this->send(200, McpOAuth::createSession($this->db, $this->redis, $code));
        }
        if ($grant === 'refresh_token') {
            $answer = McpOAuth::refresh($this->db, $this->redis, (string) ($p['refresh_token'] ?? ''), $clientId);
            if ($answer === null) {
                return $this->oauthError(400, 'invalid_grant', 'The refresh token is invalid or was revoked.');
            }
            return $this->send(200, $answer);
        }
        return $this->oauthError(400, 'unsupported_grant_type', 'Use authorization_code or refresh_token.');
    }

    public function revokeAction()
    {
        $this->json();
        if ($this->request->isPost()) {
            $p = $this->request->getPost();
            if (!$p) {
                $p = $this->body();
            }
            McpOAuth::revokeByRefresh($this->db, (string) ($p['token'] ?? ''), 'revoked by the app');
        }
        return $this->send(200, (object) []);
    }

    private function json(): void
    {
        $this->view->setRenderLevel(View::LEVEL_NO_RENDER);
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setHeader('Cache-Control', 'no-store');
    }

    private function send(int $status, $payload)
    {
        $this->response->setStatusCode($status);
        return $this->response->setContent(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function oauthError(int $status, string $code, string $description)
    {
        return $this->send($status, ['error' => $code, 'error_description' => $description]);
    }

    private function host(): string
    {
        return strtolower(trim(explode(':', (string) $this->request->getHttpHost())[0]));
    }

    private function domainForHost(string $host)
    {
        if ($host === '') {
            return null;
        }
        return VDomains::findFirst([
            'conditions' => 'domain_name = :domain_name:',
            'bind' => ['domain_name' => $host],
            'bindTypes' => [\Phalcon\Db\Column::BIND_PARAM_STR],
        ]) ?: null;
    }

    private function body(): array
    {
        try {
            $decoded = $this->request->getJsonRawBody(true);
            if (is_array($decoded)) {
                return $decoded;
            }
        } catch (\Throwable $e) {
        }
        $post = $this->request->getPost();
        return is_array($post) ? $post : [];
    }

    private function clientIp(): string
    {
        $peer = (string) $this->request->getClientAddress();
        if (in_array($peer, ['127.0.0.1', '::1'], true)) {
            $real = trim((string) $this->request->getHeader('X-Real-IP'));
            if ($real !== '') {
                return substr($real, 0, 45);
            }
        }
        return substr($peer, 0, 45);
    }

    private function fingerprint(string $clientId, string $redirect, string $challenge, string $state, string $userUuid): string
    {
        return hash('sha256', implode("\n", [$clientId, $redirect, $challenge, $state, $userUuid]));
    }

    private function putConsent(string $fingerprint): string
    {
        $nonce = bin2hex(random_bytes(24));
        $this->redis->setex('mcp_oauth_consent:' . $nonce, self::CONSENT_TTL, $fingerprint);
        return $nonce;
    }

    private function takeConsent(string $nonce): ?string
    {
        if (!preg_match('/^[0-9a-f]{48}$/', $nonce)) {
            return null;
        }
        $key = 'mcp_oauth_consent:' . $nonce;
        $value = $this->redis->get($key);
        if ($value === null || $value === false || (int) $this->redis->del($key) !== 1) {
            return null;
        }
        return (string) $value;
    }

    private function withQuery(string $uri, array $params): string
    {
        $params = array_filter($params, function ($v) {
            return $v !== '' && $v !== null;
        });
        return $uri . (strpos($uri, '?') === false ? '?' : '&') . http_build_query($params);
    }

    private function back(string $redirect, array $params)
    {
        $this->response->setStatusCode(302);
        $this->response->setHeader('Location', $this->withQuery($redirect, $params));
        return $this->response->setContent('');
    }

    private function auditConsent($vDomain, array $auth, array $client, string $result, string $scope): void
    {
        try {
            $this->db->execute(
                "INSERT INTO audit_logs (domain_uuid, username, user_fullname, user_email, extension, ip_address, user_agent, feature, action, entity, result, metadata)
                 VALUES (:d, 'ai-mcp', :n, :e, :x, :ip, :ua, 'ai-mcp', :a, :en, 'success', CAST(:m AS jsonb))",
                [
                    'd' => (string) $vDomain->domain_uuid,
                    'n' => mb_substr(trim(((string) ($auth['firstname'] ?? '')) . ' ' . ((string) ($auth['lastname'] ?? ''))), 0, 255),
                    'e' => mb_substr((string) ($auth['email'] ?? ''), 0, 190),
                    'x' => (string) ($auth['extension'] ?? ''),
                    'ip' => $this->clientIp(),
                    'ua' => mb_substr((string) $this->request->getUserAgent(), 0, 255),
                    'a' => $result === 'allowed' ? 'app-connected' : 'app-declined',
                    'en' => mb_substr((string) $client['client_name'], 0, 255),
                    'm' => json_encode(['client' => (string) $client['client_name'], 'scope' => $scope]),
                ]
            );
        } catch (\Throwable $e) {
            error_log('ai-mcp consent audit: ' . $e->getMessage());
        }
    }

    private function loginHtml(string $app, string $tenant): string
    {
        return '<p class="lead"><strong>' . $app . '</strong> wants to connect to <strong>' . $tenant . '</strong>. Sign in with your RingQ account first.</p>'
            . '<form id="code-form" class="stack">'
            . '<label>Email<input id="email" type="email" autocomplete="username" required></label>'
            . '<div id="code-step" hidden><label>Access code<input id="code" inputmode="numeric" autocomplete="one-time-code"></label></div>'
            . '<p id="msg" class="muted" role="status"></p>'
            . '<div class="actions"><button class="btn primary" id="go" type="submit">Email me an access code</button></div>'
            . '</form>'
            . '<script>(function(){var api="https://"+location.hostname+":8443";var f=document.getElementById("code-form"),e=document.getElementById("email"),c=document.getElementById("code"),s=document.getElementById("code-step"),m=document.getElementById("msg"),b=document.getElementById("go"),sent=false;'
            . 'f.addEventListener("submit",function(ev){ev.preventDefault();var email=e.value.trim().toLowerCase();if(!email)return;b.disabled=true;'
            . 'if(!sent){fetch(api+"/login/recover",{method:"POST",credentials:"include",headers:{"Content-Type":"application/x-www-form-urlencoded",Accept:"application/json"},body:new URLSearchParams({type:"0",email:email})}).then(function(r){return r.json()}).then(function(j){b.disabled=false;if(j.result==="success"){sent=true;s.hidden=false;c.focus();b.textContent="Sign in";m.textContent="Check your email for the access code."}else{m.textContent=j.message||"Could not send an access code."}}).catch(function(){b.disabled=false;m.textContent="Network error. Try again."});return}'
            . 'var code=c.value.trim();if(!/^[0-9]+$/.test(code)){b.disabled=false;m.textContent="Enter the access code from the email.";return}'
            . 'fetch(api+"/login/forgot/"+encodeURIComponent(code)+"/"+encodeURIComponent(email),{credentials:"include",headers:{Accept:"application/json"}}).then(function(r){return r.json()}).then(function(j){if(j.result==="success"){location.reload()}else{b.disabled=false;m.textContent=j.message||"That code was not accepted."}}).catch(function(){b.disabled=false;m.textContent="Network error. Try again."})})})();</script>';
    }

    private function page(int $status, string $title, string $body)
    {
        $this->response->setStatusCode($status);
        $this->response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $t = htmlspecialchars($title, ENT_QUOTES);
        return $this->response->setContent('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $t . ' · RingQ</title><style>'
            . ':root{--bg:#EEF0FB;--card:#fff;--text:#101828;--sub:#475467;--line:rgba(30,39,74,.12);--accent:#1B9DD9;--accent-text:#fff;--muted:#F2F4F7}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#18223F;--card:#253156;--text:#fff;--sub:#a3adca;--line:rgba(255,255,255,.14);--muted:#2F3C64}}'
            . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--text);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
            . 'main{width:100%;max-width:460px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px}h1{margin:0 0 14px;font-size:20px;line-height:28px}'
            . '.brand{font-weight:700;color:var(--accent);margin-bottom:18px;letter-spacing:.2px}.lead{margin:0 0 16px}.muted{color:var(--sub);font-size:13px}'
            . '.who{display:flex;flex-wrap:wrap;gap:6px;align-items:baseline;padding:10px 12px;border-radius:10px;background:var(--muted);margin-bottom:16px;font-size:14px}.who span{color:var(--sub)}.who em{font-style:normal;font-size:12px;padding:1px 8px;border-radius:999px;border:1px solid var(--line)}'
            . 'fieldset{border:0;margin:0 0 12px;padding:0}legend{font-size:13px;font-weight:600;margin-bottom:8px}.choice{display:flex;gap:10px;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:8px;cursor:pointer}.choice small{display:block;color:var(--sub);font-size:13px}.choice input{margin-top:4px}'
            . '.never{margin:0 0 12px;padding-left:18px;color:var(--sub);font-size:13px}.actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}'
            . '.btn{display:inline-block;height:40px;padding:0 18px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--text);font-weight:600;font-size:14px;cursor:pointer;text-decoration:none;line-height:38px}.btn.primary{background:var(--accent);border-color:var(--accent);color:var(--accent-text)}.btn:disabled{opacity:.6}'
            . '.stack label{display:block;font-size:13px;font-weight:600;margin-bottom:12px}.stack input{display:block;width:100%;margin-top:6px;height:40px;padding:0 12px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--text);font-size:15px}'
            . 'a{color:var(--accent)}:focus-visible{outline:3px solid var(--accent);outline-offset:2px}'
            . '</style></head><body><main><div class="brand">RingQ</div><h1>' . $t . '</h1>' . $body . '</main></body></html>');
    }
}

