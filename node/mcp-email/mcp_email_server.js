/**
 * Self-hosted MCP server exposing BOTH Microsoft 365/Outlook and Google
 * Workspace (Drive, Sheets, Calendar, Gmail) tools to the voice bridge,
 * from one process, one port, one /mcp/read endpoint -- kept as a single
 * file intentionally rather than splitting into per-provider servers.
 *
 * IMPORTANT -- READ BEFORE DEPLOYING:
 *
 * 1. This server is LOOPBACK-ONLY. It binds 127.0.0.1 (see app.listen at
 *    the bottom) and its only client is claude_server.js on the same box,
 *    which acts as the MCP client itself -- see the "LOCAL MCP CLIENT"
 *    comment block in that file.
 *
 *    An earlier design had Anthropic's API servers connect here directly,
 *    via the remote MCP connector (mcp_servers/mcp_toolset on the Messages
 *    API). That required this process to be internet-reachable over HTTPS
 *    on a real public hostname, and it only ever worked for Claude --
 *    OpenAI's and Gemini's remote-MCP shapes differ. It was abandoned for
 *    both reasons, and this header used to still describe it, which sent
 *    more than one person looking for an nginx location block and a TLS
 *    cert that nothing needs. There is no public HTTPS requirement: keep
 *    it on loopback.
 *
 *    MCP_SHARED_SECRET is still required and still checked on every route.
 *    It is no longer the only thing standing between a public URL and your
 *    mailbox, but it is what stops any other local process on the box from
 *    reading mail through it, so it stays mandatory.
 *
 * 2. This server exposes:
 *      POST /mcp/read     -> ONE MCP endpoint with every read-only tool,
 *                             Outlook and Google alike: search_emails,
 *                             get_email, search_drive_files,
 *                             read_spreadsheet, list_calendar_events,
 *                             search_gmail, get_gmail_message, plus
 *                             lookup_caller (CRM, always registered).
 *                             The model can call any of these on its own
 *                             at any time, subject to the per-agent
 *                             permission filter in claude_server.js.
 *      GET  /mcp/read     -> 405. Stateless mode offers no SSE stream;
 *                             see the route for why answering matters.
 *      GET  /health       -> unauthenticated liveness probe.
 *      POST /send         -> plain REST endpoint (NOT an MCP tool),
 *                             sends via Outlook/Graph.
 *      POST /send-gmail   -> plain REST endpoint (NOT an MCP tool),
 *                             sends via Gmail.
 *      POST /create-event -> plain REST endpoint (NOT an MCP tool),
 *                             creates a Google Calendar event.
 *    None of the three write routes are MCP tools the model can call
 *    directly -- claude_server.js calls them from its own code, only
 *    after verifying the caller confirmed a specific draft/event out
 *    loud. There is no model tool-call decision involved in actually
 *    sending or creating anything.
 *
 * 3. Azure AD app registration needed (one-time, in the Azure portal),
 *    for the Outlook side:
 *      - App registration -> API permissions -> Microsoft Graph ->
 *        Application permissions -> Mail.Read, Mail.Send -> grant admin
 *        consent.
 *      - Create a client secret.
 *      - Note the Tenant ID, Client ID, Client Secret -> set as env vars
 *        below (MS_TENANT_ID, MS_CLIENT_ID, MS_CLIENT_SECRET).
 *      - STRONGLY RECOMMENDED: scope the app to a single mailbox with an
 *        Exchange Application Access Policy, since app-only Mail.Read/
 *        Mail.Send otherwise grants access to every mailbox in the
 *        tenant:
 *          New-ApplicationAccessPolicy -AppId <client-id> \
 *            -PolicyScopeGroupId <mailbox-or-group> -AccessRight RestrictAccess \
 *            -Description "Restrict RingQ bot to one mailbox"
 *      - Set MS_MAILBOX to the address the bot operates as
 *        (e.g. support@ringq.ai).
 *
 * 4. Google side setup -- USES THE EXISTING OAuth flow already built in
 *    the main dashboard (Phalcon PHP, googlecallbackAction), not
 *    anything new. This file is a READER of that same data, nothing more:
 *
 *      key:   google_settings:<subdomain>
 *      value (JSON, written/maintained by googlecallbackAction): {
 *        "client_id": "...",       // this tenant's Google OAuth client id
 *        "client_secret": "...",   // this tenant's Google OAuth client secret
 *        "redirect_uri": "...",
 *        "access_token": "...",
 *        "refresh_token": "...",
 *        "expires_at": 1755064800,  // UNIX SECONDS (time() + expires_in),
 *                                   // NOT milliseconds -- matches PHP's
 *                                   // time() exactly, do not confuse with
 *                                   // the JS client library's expiry_date
 *                                   // (ms). Convert explicitly wherever
 *                                   // these two representations meet.
 *        "connected_account": "...", "organization": "...",
 *        "contact_mode": "...", "connected_since": "...", etc.
 *        -- plus whatever other fields the PHP side already stores; this
 *        file only ever reads two of them (access_token, refresh_token)
 *        and, on refresh, rewrites the FULL object back (never just the
 *        token fields), so any other keys PHP relies on survive intact.
 *      }
 *
 *    subdomain is resolved from the Host header on browser-facing routes
 *    in the PHP app, but THIS server never receives a browser request --
 *    Anthropic's API always calls the one fixed https://mr.ringq.ai/mcp/read
 *    URL. So claude_server.js instead passes the subdomain explicitly, as
 *    a ?subdomain= query param on the MCP URL (and in the POST body for
 *    the /send-gmail and /create-event REST routes) -- see readMcpServer()
 *    there. Until real per-call tenant routing exists on that side, every
 *    request falls back to GOOGLE_DEFAULT_SUBDOMAIN, so this is testable
 *    today by just connecting Google for one real subdomain through the
 *    existing dashboard flow and pointing that env var at it.
 *
 *    IMPORTANT -- SCOPES: googlecallbackAction's job today is importing
 *    Google Contacts. Whatever action builds the initial consent-screen
 *    redirect URL (not shown here) determines which scopes actually get
 *    granted -- if it doesn't already request all five of these, add
 *    them there (existing connections will need to reconnect once scopes
 *    change):
 *      https://www.googleapis.com/auth/drive.readonly
 *      https://www.googleapis.com/auth/spreadsheets.readonly
 *      https://www.googleapis.com/auth/calendar
 *      https://www.googleapis.com/auth/gmail.readonly
 *      https://www.googleapis.com/auth/gmail.send
 *    Without all five, tools for the missing ones will fail with a Google
 *    403 (same failure shape as the earlier Graph permission issue) even
 *    though the token itself is otherwise valid and unexpired.
 *
 * 5. `npm install express dotenv zod googleapis redis @modelcontextprotocol/sdk`
 */

// No dotenv: settings have built-in defaults; Google/Microsoft credentials come from Redis.
import { createHash } from 'node:crypto';
import express from 'express';
import { google } from 'googleapis';
import { createClient as createRedisClient } from 'redis';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { z } from 'zod';

const PORT = process.env.MCP_EMAIL_PORT || 8091; // matches MCP_LOCAL_URL's default in claude_server.js
let MCP_SHARED_SECRET = process.env.MCP_SHARED_SECRET;

const MS_TENANT_ID = process.env.MS_TENANT_ID;
const MS_CLIENT_ID = process.env.MS_CLIENT_ID;
const MS_CLIENT_SECRET = process.env.MS_CLIENT_SECRET;
const MS_MAILBOX = process.env.MS_MAILBOX; // e.g. support@ringq.ai

const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379'; // no .env: local Redis (holds the tenant's Google/Microsoft settings)
const GOOGLE_CALENDAR_ID = process.env.GOOGLE_CALENDAR_ID || 'primary';
// Which connected tenant to use per request, until real per-call tenant
// routing exists on the claude_server.js side (see mountMcp() below).
// NOTE: unlike the earlier draft of this file, there is no global
// GOOGLE_OAUTH_CLIENT_ID/SECRET here -- each tenant's client_id/secret
// live inside their own google_settings:<subdomain> blob, written by the
// existing PHP OAuth callback, and are read from there per request.
const GOOGLE_DEFAULT_SUBDOMAIN = process.env.GOOGLE_DEFAULT_SUBDOMAIN || 'mr.ringq.ai';

// MR AI changes - starts
// CRM lookup ("where is my order" / caller-identification use case).
// Hits the SAME internal Phalcon endpoint (TeamsController::contactsBy
// NumberAction) and the SAME internal-key auth pattern that
// custom_crm.cjs already uses against a different Phalcon route
// (fetchTranscriptDetails/fetchContactDetails) -- no new auth mechanism
// introduced, just reused. The key defaults to the literal value already
// hardcoded in custom_crm.cjs; override via env if that ever rotates.
const RINGQ_INTERNAL_KEY = process.env.RINGQ_INTERNAL_KEY ||
  'RingQ-Internal-Secret-818849038148786300654846001886';
// MR AI changes - ends

// Same derivation as claude_server.js (this server only listens on 127.0.0.1), so no .env is needed.
if (!MCP_SHARED_SECRET) {
  MCP_SHARED_SECRET = createHash('sha256').update('ringq-mcp-local:' + RINGQ_INTERNAL_KEY).digest('hex');
}

// MR AI changes - starts
// Outlook is now OPTIONAL, same treatment as Google below -- this server
// previously hard-exited if any MS_* var was missing, which meant it
// could never boot at all in a dev/test environment (or a tenant) that
// only wants Google + CRM lookups. Missing Outlook config now just skips
// registering its tools/routes, exactly like the Google branch already
// did, instead of killing the whole process (including CRM lookup and
// Google, which don't depend on Outlook at all).
const OUTLOOK_CONFIGURED = Boolean(MS_TENANT_ID && MS_CLIENT_ID && MS_CLIENT_SECRET && MS_MAILBOX);
if (!OUTLOOK_CONFIGURED) {
  console.warn('[MCP] MS_TENANT_ID/MS_CLIENT_ID/MS_CLIENT_SECRET/MS_MAILBOX not fully set -- ' +
    'running without Outlook tools/routes.');
}
// MR AI changes - ends

// Google is OPTIONAL -- if not configured, we just skip registering its
// tools/routes below and log once, rather than crashing the whole
// server (including the already-working Outlook side) over it.
const GOOGLE_CONFIGURED = Boolean(REDIS_URL);
if (!GOOGLE_CONFIGURED) {
  console.warn('[MCP] REDIS_URL not set -- running without Google tools/routes.');
}

let redisClient = null;
if (GOOGLE_CONFIGURED) {
  redisClient = createRedisClient({ url: REDIS_URL });
  redisClient.on('error', (err) => console.error('[REDIS] client error:', err.message));
  // MR AI changes - starts
  // node-redis v4's reconnectStrategy retries forever by default and its
  // `connect()` promise does not settle while that's happening -- so if
  // Redis is unreachable at boot, the bare `await redisClient.connect()`
  // that used to be here hangs FOREVER, and every line after it (Outlook
  // routes, the lookup_caller CRM tool, app.listen() itself) never runs.
  // That's the opposite of "Google is optional": it meant a down/slow
  // Redis silently took the ENTIRE server offline, Outlook and CRM lookup
  // included, contradicting this block's own stated intent. Race the
  // initial connect against a short timeout so a Redis outage only ever
  // costs Google tools (which keep retrying in the background via the
  // client's own reconnectStrategy) -- never the rest of the server.
  try {
    await Promise.race([
      redisClient.connect(),
      new Promise((_, reject) => setTimeout(() => reject(new Error('timed out after 5s')), 5000)),
    ]);
    console.log('[REDIS] connected');
  } catch (err) {
    console.error('[MCP] Redis did not connect within 5s -- continuing to start the server without ' +
      'blocking on it. Outlook/lookup_caller tools do not need Redis; Google tools will keep failing ' +
      'per-request until it reconnects in the background:', err.message);
  }
  // MR AI changes - ends
}

// ---------------------------------------------------------------------
// Microsoft Graph app-only auth (client credentials flow). Tokens are
// cached and refreshed a little before actual expiry.
// ---------------------------------------------------------------------
let cachedToken = null;
let cachedTokenExpiresAt = 0;

async function getGraphToken() {
  if (cachedToken && Date.now() < cachedTokenExpiresAt - 60_000) {
    return cachedToken;
  }

  console.log('[MCP-OUTLOOK] requesting new Graph app-only token...');
  const resp = await fetch(
    `https://login.microsoftonline.com/${MS_TENANT_ID}/oauth2/v2.0/token`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        client_id: MS_CLIENT_ID,
        client_secret: MS_CLIENT_SECRET,
        scope: 'https://graph.microsoft.com/.default',
        grant_type: 'client_credentials',
      }),
    }
  );

  if (!resp.ok) {
    const body = await resp.text();
    throw new Error(`Graph token request failed: ${resp.status} ${body}`);
  }

  const data = await resp.json();
  cachedToken = data.access_token;
  cachedTokenExpiresAt = Date.now() + data.expires_in * 1000;
  console.log('[MCP-OUTLOOK] got new Graph token, expires in', data.expires_in, 's');
  return cachedToken;
}

async function graphFetch(path, options = {}, accessTokenOverride = null) {
  // MR AI changes: accessTokenOverride lets a caller supply a delegated,
  // per-user token (see getOutlookAuthForAccount below) instead of the
  // app-only client-credentials token this function fetches by default.
  const token = accessTokenOverride || await getGraphToken();
  const resp = await fetch(`https://graph.microsoft.com/v1.0${path}`, {
    ...options,
    headers: {
      Authorization: `Bearer ${token}`,
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
  });
  if (!resp.ok) {
    const body = await resp.text();
    throw new Error(`Graph API error ${resp.status}: ${body}`);
  }
  // sendMail returns 202 with no body
  if (resp.status === 202) return null;
  return resp.json();
}

// MR AI changes - starts
// ---------------------------------------------------------------------
// PER-USER OUTLOOK AUTH -- mirrors getGoogleAuthForAccount() exactly. The
// app-only client-credentials token (getGraphToken()) always acts on ONE
// fixed mailbox (MS_MAILBOX) via /users/{mailbox}/... application-permission
// paths. A per-user grant is delegated instead: the token represents the
// actual signed-in agent owner, and Graph only accepts that identity on
// /me/... paths, not /users/{mailbox}/... (which needs application
// permissions this token doesn't have). mailboxPath is what changes between
// the two cases; every caller below is otherwise identical.
// ---------------------------------------------------------------------
async function getOutlookAuthForAccount(subdomain, ownerUserUuid, agentId) {
  const userToken = await fetchUserConnectorToken(subdomain, ownerUserUuid, agentId, 'microsoft');
  if (userToken) {
    return { token: userToken, mailboxPath: '/me' };
  }

  if (!OUTLOOK_CONFIGURED) {
    throw new Error('Outlook is not available: this agent\'s owner has not connected a Microsoft 365 ' +
      'account, and no shared Outlook connection is configured for this server.');
  }
  return { token: await getGraphToken(), mailboxPath: `/users/${MS_MAILBOX}` };
}
// MR AI changes - ends

// ---------------------------------------------------------------------
// Outlook tool implementations
// ---------------------------------------------------------------------
async function searchEmails({ query, top = 5 }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-OUTLOOK] search_emails account=', subdomain, 'query=', JSON.stringify(query), 'top=', top);
  const { token, mailboxPath } = await getOutlookAuthForAccount(subdomain, ownerUserUuid, agentId);
  const encoded = encodeURIComponent(query);
  const data = await graphFetch(
    `${mailboxPath}/messages?$search="${encoded}"&$top=${top}&$select=id,subject,from,receivedDateTime,bodyPreview`,
    {}, token
  );
  return data.value.map((m) => ({
    id: m.id,
    subject: m.subject,
    from: m.from?.emailAddress?.address,
    receivedDateTime: m.receivedDateTime,
    preview: m.bodyPreview,
  }));
}

async function getEmail({ id }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-OUTLOOK] get_email account=', subdomain, 'id=', id);
  const { token, mailboxPath } = await getOutlookAuthForAccount(subdomain, ownerUserUuid, agentId);
  const m = await graphFetch(
    `${mailboxPath}/messages/${id}?$select=id,subject,from,receivedDateTime,body`,
    {}, token
  );
  return {
    id: m.id,
    subject: m.subject,
    from: m.from?.emailAddress?.address,
    receivedDateTime: m.receivedDateTime,
    body: m.body?.content,
  };
}

async function sendEmail({ to, subject, body }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-OUTLOOK] send_email account=', subdomain, 'to=', to, 'subject=', JSON.stringify(subject));
  const { token, mailboxPath } = await getOutlookAuthForAccount(subdomain, ownerUserUuid, agentId);
  await graphFetch(`${mailboxPath}/sendMail`, {
    method: 'POST',
    body: JSON.stringify({
      message: {
        subject,
        body: { contentType: 'Text', content: body },
        toRecipients: [{ emailAddress: { address: to } }],
      },
      saveToSentItems: true,
    }),
  }, token);
  console.log('[MCP-OUTLOOK] send_email succeeded');
  return { status: 'sent', to, subject };
}

// ---------------------------------------------------------------------
// Google auth -- reads the SAME google_settings:<subdomain> Redis blob
// the existing PHP googlecallbackAction already writes. This code never
// writes client_id/client_secret/connected_account/etc -- it only ever
// reads the full object, and if a refresh happens, patches in the two
// fields that changed (access_token, expires_at, occasionally
// refresh_token) and writes the WHOLE object back, exactly the same
// read-mutate-write pattern the PHP callback uses. This avoids any risk
// of this Node process clobbering fields the PHP side depends on.
// ---------------------------------------------------------------------
function googleSettingsKey(subdomain) {
  return `google_settings:${subdomain}`;
}

// Mirrors the token exchange in googlecallbackAction, but for a
// refresh_token grant instead of an authorization_code grant -- same
// endpoint, same form-encoded POST, same client_id/client_secret taken
// from this tenant's own settings (not a global env var).
async function refreshGoogleAccessToken(settings) {
  const resp = await fetch('https://oauth2.googleapis.com/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      client_id: settings.client_id,
      client_secret: settings.client_secret,
      refresh_token: settings.refresh_token,
      grant_type: 'refresh_token',
    }),
  });
  const data = await resp.json();
  if (!resp.ok || !data.access_token) {
    throw new Error(`Google token refresh failed: ${resp.status} ${JSON.stringify(data)}`);
  }
  settings.access_token = data.access_token;
  // UNIX SECONDS, matching PHP's time() + expires_in exactly -- do not
  // multiply by 1000 here, this field is shared with the PHP side.
  settings.expires_at = Math.floor(Date.now() / 1000) + data.expires_in;
  // Google only returns a refresh_token on the original grant -- a
  // refresh response usually omits it, so only overwrite if present.
  if (data.refresh_token) settings.refresh_token = data.refresh_token;
  return settings;
}

async function getGoogleSettingsWithFreshToken(subdomain) {
  const key = googleSettingsKey(subdomain);
  const raw = await redisClient.get(key);
  console.log(`[MCP-GOOGLE] got settings for "${subdomain}":`, raw ? 'found' : 'not found');
  console.log(raw);
  if (!raw) {
    throw new Error(`Google settings not found for "${subdomain}" -- connect Google from the dashboard first.`);
  }
  let settings = JSON.parse(raw);
  if (!settings.access_token || !settings.refresh_token) {
    throw new Error(`Google not connected for "${subdomain}" -- connect Google from the dashboard first.`);
  }

  const nowSeconds = Math.floor(Date.now() / 1000);
  const SAFETY_BUFFER_SECONDS = 60;
  const isExpired = !settings.expires_at || nowSeconds >= settings.expires_at - SAFETY_BUFFER_SECONDS;
  if (isExpired) {
    settings = await refreshGoogleAccessToken(settings);
    // Write the FULL settings object back, not just the token fields --
    // preserves contact_mode, connected_account, organization, etc.
    await redisClient.set(key, JSON.stringify(settings));
  }
  return settings;
}

// MR AI changes - starts
// PER-USER TOKEN RESOLUTION
//
// Google (and Microsoft) credentials used to come from exactly one place:
// google_settings:{subdomain} in Redis, i.e. ONE connection shared by the
// whole company. That made "check the agent's calendar and book the meeting"
// mean "check the company's calendar", which is not the same thing and is
// wrong as soon as two people each have their own AI receptionist.
//
// Tokens are now resolved per (AGENT OWNER, AGENT) PAIR, through
// TeamsController::aiConnectorTokenAction. That endpoint holds the encrypted
// grant, refreshes it when it is close to expiry, and hands back only a
// short-lived access token -- so this process never sees a refresh token and
// never needs the tenant's client_secret.
//
// The agentId dimension exists because ownerUserUuid alone is not enough:
// the same human can own multiple AI agents, and without agentId in the key,
// connecting Google for one agent silently connected it for every agent that
// same human owns (the original per-user-only design). See
// TeamsController.php's aiTokenKey() for the matching Redis key shape.
//
// The tenant-level path is kept as a FALLBACK, not deleted. Existing
// deployments have a working shared Google connection and no per-user grants
// yet; removing it would break them on deploy rather than on migration. An
// agent with no ownerUserUuid/agentId, or an owner who has not connected an
// account for this specific agent, silently continues to use the shared
// connection exactly as before.
const RINGQ_TOKEN_URL_TEMPLATE = process.env.RINGQ_TOKEN_URL_TEMPLATE ||
  'https://{subdomain}:8443/teamsai/aiconnectortoken';
const USER_TOKEN_TIMEOUT_MS = 5000;

// Access tokens live ~1 hour and a single call can make several tool calls,
// so caching avoids a round trip to Phalcon per tool. Keyed by
// subdomain|user|agent|provider. Small and bounded by the number of distinct
// (owner, agent) pairs actually taking calls.
const userTokenCache = new Map();
const USER_TOKEN_CACHE_SAFETY_SECONDS = 120;

/**
 * Fetches a currently-valid access token for one user's connected account,
 * or null when there is no usable grant.
 *
 * Returns null rather than throwing for the "not connected" cases, because
 * they are ordinary states with a sensible fallback, not failures. A genuine
 * transport error also returns null (after logging) for the same reason: a
 * Phalcon that is briefly unreachable should degrade to the shared tenant
 * connection, not fail the caller's question.
 */
async function fetchUserConnectorToken(subdomain, ownerUserUuid, agentId, provider) {
  if (!subdomain || !ownerUserUuid || !agentId || !provider) return null;

  const cacheKey = `${subdomain}|${ownerUserUuid}|${agentId}|${provider}`;
  const cached = userTokenCache.get(cacheKey);
  const nowSeconds = Math.floor(Date.now() / 1000);
  if (cached && cached.expiresAt - USER_TOKEN_CACHE_SAFETY_SECONDS > nowSeconds) {
    return cached.accessToken;
  }

  const url = RINGQ_TOKEN_URL_TEMPLATE.replace('{subdomain}', subdomain);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), USER_TOKEN_TIMEOUT_MS);
  try {
    const resp = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Internal-Key': RINGQ_INTERNAL_KEY },
      body: JSON.stringify({ subdomain, user_uuid: ownerUserUuid, agent_id: agentId, provider }),
      signal: controller.signal,
    });
    const data = await resp.json();

    if (data.result !== 'success' || !data.accessToken) {
      // 'not_connected' is the common, benign case -- log it at a level that
      // does not look like a fault, since the fallback handles it.
      const reason = data.reason || 'unknown';
      if (reason === 'not_connected') {
        console.log(`[MCP-TOKEN] ${provider}: user ${ownerUserUuid} has no connected account; ` +
          'falling back to the shared tenant connection.');
      } else {
        console.warn(`[MCP-TOKEN] ${provider}: could not get a user token (${reason}): ` +
          (data.message || 'no message'));
      }
      userTokenCache.delete(cacheKey);
      return null;
    }

    userTokenCache.set(cacheKey, {
      accessToken: data.accessToken,
      expiresAt: Number(data.expiresAt) || (nowSeconds + 3600),
    });
    console.log(`[MCP-TOKEN] ${provider}: using ${data.accountEmail || ownerUserUuid}'s connected account`);
    return data.accessToken;
  } catch (err) {
    console.error(`[MCP-TOKEN] ${provider}: token lookup failed (${err.message}); ` +
      'falling back to the shared tenant connection.');
    return null;
  } finally {
    clearTimeout(timeout);
  }
}

/**
 * Builds an authenticated Google client for whichever account this call
 * should act on: the agent owner's connected account when there is one, else
 * the shared tenant connection.
 *
 * In the per-user case the OAuth2 client is given only an access token, with
 * no client id/secret and no refresh token. That is deliberate: refreshing is
 * TeamsController's job (it owns the encrypted refresh token), and googleapis
 * will not silently attempt a refresh it cannot perform. If the token does
 * expire mid-call the next tool call re-fetches, because the cache is keyed
 * on expiry.
 */
async function getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) {
  const userToken = await fetchUserConnectorToken(subdomain, ownerUserUuid, agentId, 'google');
  if (userToken) {
    const oauth2Client = new google.auth.OAuth2();
    oauth2Client.setCredentials({ access_token: userToken });
    return oauth2Client;
  }

  if (!GOOGLE_CONFIGURED) {
    throw new Error('Google is not available: this agent\'s owner has not connected a Google ' +
      'account, and no shared Google connection is configured for this server.');
  }
  const settings = await getGoogleSettingsWithFreshToken(subdomain);
  const oauth2Client = new google.auth.OAuth2(settings.client_id, settings.client_secret);
  oauth2Client.setCredentials({ access_token: settings.access_token });
  return oauth2Client;
}
// MR AI changes - ends
// ---------------------------------------------------------------------
// Google tool implementations
// ---------------------------------------------------------------------
async function searchDriveFiles({ query, top = 5 }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] search_drive_files account=', subdomain, 'query=', JSON.stringify(query), 'top=', top);
  const drive = google.drive({ version: 'v3', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const escaped = query.replace(/'/g, "\\'");
  const res = await drive.files.list({
    q: `(name contains '${escaped}' or fullText contains '${escaped}') and trashed = false`,
    pageSize: top,
    fields: 'files(id,name,mimeType,modifiedTime,webViewLink)',
  });
  return res.data.files || [];
}

async function readSpreadsheet({ fileId, range }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] read_spreadsheet account=', subdomain, 'fileId=', fileId, 'range=', range);
  const sheets = google.sheets({ version: 'v4', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  let effectiveRange = range;
  if (!effectiveRange) {
    // No range given -- default to the whole first sheet rather than
    // forcing the caller (a human on the phone) to know A1 notation.
    const meta = await sheets.spreadsheets.get({ spreadsheetId: fileId });
    const firstTitle = meta.data.sheets?.[0]?.properties?.title;
    effectiveRange = firstTitle || 'A1:Z1000';
  }
  const res = await sheets.spreadsheets.values.get({ spreadsheetId: fileId, range: effectiveRange });
  return { range: res.data.range, values: res.data.values || [] };
}

async function listCalendarEvents({ query, timeMin, timeMax, top = 10 }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] list_calendar_events account=', subdomain, 'query=', query, 'timeMin=', timeMin, 'timeMax=', timeMax);
  const calendar = google.calendar({ version: 'v3', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const res = await calendar.events.list({
    calendarId: GOOGLE_CALENDAR_ID,
    q: query,
    timeMin: timeMin || new Date().toISOString(),
    timeMax,
    maxResults: top,
    singleEvents: true,
    orderBy: 'startTime',
  });
  return (res.data.items || []).map((e) => ({
    id: e.id,
    summary: e.summary,
    start: e.start?.dateTime || e.start?.date,
    end: e.end?.dateTime || e.end?.date,
    attendees: (e.attendees || []).map((a) => a.email),
  }));
}

// Recursively finds a text body inside a Gmail message payload -- Gmail
// nests plain-text/HTML parts arbitrarily deep for multipart messages,
// and body content is base64url-encoded.
function extractGmailBody(payload) {
  if (!payload) return '';
  if (payload.body?.data) {
    return Buffer.from(payload.body.data, 'base64url').toString('utf8');
  }
  if (payload.parts) {
    const plain = payload.parts.find((p) => p.mimeType === 'text/plain');
    if (plain) return extractGmailBody(plain);
    const html = payload.parts.find((p) => p.mimeType === 'text/html');
    if (html) return extractGmailBody(html).replace(/<[^>]+>/g, ' ');
    for (const part of payload.parts) {
      const nested = extractGmailBody(part);
      if (nested) return nested;
    }
  }
  return '';
}

async function searchGmail({ query, top = 5 }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] search_gmail account=', subdomain, 'query=', JSON.stringify(query), 'top=', top);
  const gmail = google.gmail({ version: 'v1', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const list = await gmail.users.messages.list({ userId: 'me', q: query, maxResults: top });
  const messages = list.data.messages || [];
  const results = [];
  for (const m of messages) {
    const msg = await gmail.users.messages.get({
      userId: 'me',
      id: m.id,
      format: 'metadata',
      metadataHeaders: ['From', 'Subject', 'Date'],
    });
    const headers = Object.fromEntries((msg.data.payload.headers || []).map((h) => [h.name, h.value]));
    results.push({
      id: m.id,
      subject: headers.Subject,
      from: headers.From,
      date: headers.Date,
      snippet: msg.data.snippet,
    });
  }
  return results;
}

async function getGmailMessage({ id }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] get_gmail_message account=', subdomain, 'id=', id);
  const gmail = google.gmail({ version: 'v1', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const msg = await gmail.users.messages.get({ userId: 'me', id, format: 'full' });
  const headers = Object.fromEntries((msg.data.payload.headers || []).map((h) => [h.name, h.value]));
  return {
    id,
    subject: headers.Subject,
    from: headers.From,
    date: headers.Date,
    body: extractGmailBody(msg.data.payload),
  };
}

async function sendGmail({ to, subject, body }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] send_gmail account=', subdomain, 'to=', to, 'subject=', JSON.stringify(subject));
  const gmail = google.gmail({ version: 'v1', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const raw = Buffer.from(
    [`To: ${to}`, `Subject: ${subject}`, 'Content-Type: text/plain; charset=utf-8', '', body].join('\n')
  ).toString('base64url');
  await gmail.users.messages.send({ userId: 'me', requestBody: { raw } });
  console.log('[MCP-GOOGLE] send_gmail succeeded');
  return { status: 'sent', to, subject };
}

// MR AI changes: Graph's event start/end wants a NAIVE local dateTime
// string paired with a separate timeZone name -- passing an offset inside
// `dateTime` itself (the shape the model already produces for Google,
// which accepts a full ISO 8601 + offset directly) is silently
// misinterpreted by Graph, not converted, so this resolves to the
// equivalent UTC instant first and always pairs it with timeZone: 'UTC'
// regardless of which offset was supplied.
function toGraphUtcDateTime(isoWithOffset) {
  return new Date(isoWithOffset).toISOString().replace(/\.\d{3}Z$/, '');
}

// MR AI changes - starts
// Mirrors createCalendarEvent() below but against Outlook/Microsoft Graph.
// Always creates a real Microsoft Teams meeting (isOnlineMeeting +
// onlineMeetingProvider) rather than a plain calendar block -- a caller who
// books a meeting over the phone needs a way to actually join it, and Graph
// generates the Teams join link for free as part of creating the event, no
// separate Teams API/permissions needed.
async function createOutlookEvent({ summary, start, end, attendees = [], description }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-OUTLOOK] create_event account=', subdomain, 'summary=', JSON.stringify(summary), 'start=', start, 'end=', end);
  const { token, mailboxPath } = await getOutlookAuthForAccount(subdomain, ownerUserUuid, agentId);
  const event = await graphFetch(`${mailboxPath}/events`, {
    method: 'POST',
    body: JSON.stringify({
      subject: summary,
      body: { contentType: 'Text', content: description || '' },
      start: { dateTime: toGraphUtcDateTime(start), timeZone: 'UTC' },
      end: { dateTime: toGraphUtcDateTime(end), timeZone: 'UTC' },
      attendees: attendees.map((email) => ({ emailAddress: { address: email }, type: 'required' })),
      isOnlineMeeting: true,
      onlineMeetingProvider: 'teamsForBusiness',
    }),
  }, token);
  console.log('[MCP-OUTLOOK] create_event succeeded, id=', event.id, 'teamsLink=', event.onlineMeeting?.joinUrl || '(none)');
  return {
    status: 'created',
    id: event.id,
    webLink: event.webLink,
    teamsJoinUrl: event.onlineMeeting?.joinUrl || null,
  };
}
// MR AI changes - ends

async function createCalendarEvent({ summary, start, end, attendees = [], description }, subdomain, ownerUserUuid, agentId) {
  console.log('[MCP-GOOGLE] create_event account=', subdomain, 'summary=', JSON.stringify(summary), 'start=', start, 'end=', end);
  const calendar = google.calendar({ version: 'v3', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const res = await calendar.events.insert({
    calendarId: GOOGLE_CALENDAR_ID,
    requestBody: {
      summary,
      description,
      start: { dateTime: start },
      end: { dateTime: end },
      attendees: attendees.map((email) => ({ email })),
    },
  });
  console.log('[MCP-GOOGLE] create_event succeeded, id=', res.data.id);
  return { status: 'created', id: res.data.id, htmlLink: res.data.htmlLink };
}

// MR AI changes - starts
// ---------------------------------------------------------------------
// check_availability -- the read-only tool that unblocks "the model can't
// check the calendar for conflicts first before proposing a time". Merges
// busy slots from whichever calendar(s) are actually available: Google
// freebusy for the agent owner's connected account (or the shared tenant
// connection), and Outlook getSchedule when Outlook is configured. Neither
// half throws on its own failure -- one calendar being briefly unreachable
// should degrade the answer, not prevent it, since a caller waiting on
// "am I free Friday" gets a much worse experience from an outright tool
// error than from an answer based on whichever calendar responded.
// ---------------------------------------------------------------------
async function checkGoogleAvailability(startIso, endIso, subdomain, ownerUserUuid, agentId) {
  const calendar = google.calendar({ version: 'v3', auth: await getGoogleAuthForAccount(subdomain, ownerUserUuid, agentId) });
  const res = await calendar.freebusy.query({
    requestBody: { timeMin: startIso, timeMax: endIso, items: [{ id: GOOGLE_CALENDAR_ID }] },
  });
  const busy = res.data.calendars?.[GOOGLE_CALENDAR_ID]?.busy || [];
  return busy.map((b) => ({ start: b.start, end: b.end, source: 'google' }));
}

async function checkOutlookAvailability(startIso, endIso, subdomain, ownerUserUuid, agentId) {
  const { token, mailboxPath } = await getOutlookAuthForAccount(subdomain, ownerUserUuid, agentId);

  // MR AI changes - starts: a per-user delegated token cannot call
  // getSchedule the way the app-only path does below -- getSchedule is a
  // cross-mailbox scheduling-assistant call that needs application
  // permissions (or Calendars.Read.Shared on someone else's box), and this
  // token only represents its own owner. calendarView reads that owner's
  // own calendar directly, which is exactly what a per-user grant is
  // authorized for and is actually the more accurate source for "is this
  // person free", not a coarser cross-mailbox view.
  if (mailboxPath === '/me') {
    const data = await graphFetch(
      `/me/calendarView?startDateTime=${encodeURIComponent(startIso)}&endDateTime=${encodeURIComponent(endIso)}` +
        `&$select=subject,start,end,showAs`,
      {}, token
    );
    return (data.value || [])
      .filter((e) => e.showAs && e.showAs !== 'free')
      .map((e) => ({ start: e.start?.dateTime, end: e.end?.dateTime, source: 'outlook' }));
  }
  // MR AI changes - ends

  const data = await graphFetch(`${mailboxPath}/calendar/getSchedule`, {
    method: 'POST',
    body: JSON.stringify({
      schedules: [MS_MAILBOX],
      startTime: { dateTime: startIso, timeZone: 'UTC' },
      endTime: { dateTime: endIso, timeZone: 'UTC' },
      availabilityViewInterval: 30,
    }),
  }, token);
  const schedule = data.value?.[0];
  return (schedule?.scheduleItems || [])
    .filter((item) => item.status !== 'free')
    .map((item) => ({ start: item.start?.dateTime, end: item.end?.dateTime, source: 'outlook' }));
}

async function checkAvailability({ startIso, endIso }, subdomain, ownerUserUuid, agentId) {
  if (!startIso || !endIso) {
    throw new Error('startIso and endIso are both required, as full ISO 8601 timestamps.');
  }
  console.log('[MCP-AVAIL] check_availability account=', subdomain, 'startIso=', startIso, 'endIso=', endIso);

  const busy = [];
  const checked = [];
  const partialErrors = [];

  if (GOOGLE_CONFIGURED || ownerUserUuid) {
    try {
      busy.push(...await checkGoogleAvailability(startIso, endIso, subdomain, ownerUserUuid, agentId));
      checked.push('google');
    } catch (err) {
      console.error('[MCP-AVAIL] google freebusy failed:', err.message);
      partialErrors.push({ source: 'google', message: err.message });
    }
  }
  // MR AI changes: gated the same way as the Google block above -- a
  // per-user Outlook grant can answer this even when no tenant-wide
  // MS_MAILBOX is configured, so the registration/call-time gate can't be
  // OUTLOOK_CONFIGURED alone. getOutlookAuthForAccount() resolves which of
  // the two to actually use and throws naming both if neither applies.
  if (OUTLOOK_CONFIGURED || ownerUserUuid) {
    try {
      busy.push(...await checkOutlookAvailability(startIso, endIso, subdomain, ownerUserUuid, agentId));
      checked.push('outlook');
    } catch (err) {
      console.error('[MCP-AVAIL] outlook availability check failed:', err.message);
      partialErrors.push({ source: 'outlook', message: err.message });
    }
  }

  if (!checked.length) {
    // Every calendar we tried to check failed, or none was configured at
    // all -- this is the one case worth throwing over, since "available:
    // true" with zero calendars actually checked would read to the model
    // as a real answer instead of the absence of one.
    throw new Error('No calendar could be checked -- ' +
      (partialErrors.length
        ? partialErrors.map((e) => `${e.source}: ${e.message}`).join('; ')
        : 'no calendar is connected for this agent.'));
  }

  return {
    startIso,
    endIso,
    checked,
    available: busy.length === 0,
    busy,
    ...(partialErrors.length ? { partialErrors } : {}),
  };
}

// ---------------------------------------------------------------------
// send_email -- unifies sendGmail() and the Outlook sendEmail() behind one
// MCP tool with an `account` selector, so the model has one tool name to
// call regardless of which account the caller means (see the
// EMAIL ACCOUNT DISAMBIGUATION instruction in claude_server.js, which is
// what decides which account value to pass). Replaces the old
// <<<EMAIL_DRAFT>>>/<<<GMAIL_DRAFT>>> marker split between two tags.
// ---------------------------------------------------------------------
async function sendEmailUnified({ account, to, subject, body }, subdomain, ownerUserUuid, agentId) {
  if (account === 'gmail') {
    return sendGmail({ to, subject, body }, subdomain, ownerUserUuid, agentId);
  }
  if (account === 'outlook') {
    // MR AI changes: no longer pre-checks OUTLOOK_CONFIGURED here -- exactly
    // like the gmail branch above doesn't pre-check GOOGLE_CONFIGURED.
    // sendEmail()/getOutlookAuthForAccount() throws its own message covering
    // both the per-user and shared-connection cases.
    return sendEmail({ to, subject, body }, subdomain, ownerUserUuid, agentId);
  }
  throw new Error(`Unknown account "${account}" -- must be "gmail" or "outlook".`);
}
// MR AI changes - ends

// MR AI changes - starts
// ---------------------------------------------------------------------
// create_calendar_event -- unifies createCalendarEvent() (Google) and
// createOutlookEvent() (Microsoft, which also creates the Teams meeting)
// behind one MCP tool with an `account` selector, exactly the same pattern
// as sendEmailUnified() above (see CALENDAR ACCOUNT DISAMBIGUATION in
// claude_server.js, which is what decides which account value to pass).
// This used to be TWO different concepts that only Google ever implemented
// -- Microsoft was advertised in the provider catalogue but never built.
// ---------------------------------------------------------------------
async function createCalendarEventUnified({ account, summary, start, end, attendees, description }, subdomain, ownerUserUuid, agentId) {
  if (account === 'google') {
    return createCalendarEvent({ summary, start, end, attendees, description }, subdomain, ownerUserUuid, agentId);
  }
  if (account === 'outlook') {
    return createOutlookEvent({ summary, start, end, attendees, description }, subdomain, ownerUserUuid, agentId);
  }
  throw new Error(`Unknown account "${account}" -- must be "google" or "outlook".`);
}
// MR AI changes - ends

// MR AI changes - starts
// ---------------------------------------------------------------------
// CRM lookup tool ("where is my order" / caller-identification).
//
// callerNumber is NEVER a model-supplied tool argument -- same principle
// as `subdomain` above: a caller on the phone has no way to know or say
// their own number in a way we'd trust, so it's resolved server-side
// from the FreeSWITCH-provided caller ID (see claude_server.js's
// CallSession.callerNumber, threaded through as a ?callerNumber= query
// param on this MCP connection, same mechanism ?subdomain= already
// uses) and closed over when the tool is registered in buildReadServer()
// below. The model only ever sees "call lookup_caller with no arguments"
// -- it cannot look up an arbitrary number.
//
// Hits TeamsController::contactsByNumberAction over the SAME internal
// pre-shared-key channel custom_crm.cjs already uses against a sibling
// Phalcon route -- no new trust boundary, just a new endpoint on it.
// ---------------------------------------------------------------------
async function lookupCaller(callerNumber, subdomain) {
  if (!callerNumber) {
    return { error: 'no caller number was available for this call' };
  }
  console.log('[MCP-CRM] lookup_caller number=', callerNumber, 'subdomain=', subdomain);
  const url = `https://${subdomain}:8443/teamsai/contactsbynumber`;

  // MR AI changes - starts
  // Wrap the fetch/parse in its own try/catch so a network failure or a
  // non-JSON response (confirmed live during testing: an unreachable
  // Phalcon backend makes resp.json() throw "Unexpected end of JSON
  // input") doesn't surface to the model as that raw, uninformative
  // exception text. The MCP SDK turns any thrown error here into an
  // isError tool result either way, but a clear, consistent message
  // gives the model an actual signal to apologize and move on, instead of
  // retrying the same tool call against the same cryptic error (observed
  // live: without this, the model retried lookup_caller a second time
  // rather than recovering conversationally).
  let resp;
  let result;
  try {
    resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Internal-Key': RINGQ_INTERNAL_KEY,
      },
      body: JSON.stringify({ subdomain, number: callerNumber }),
    });
  } catch (err) {
    console.error('[MCP-CRM] lookup_caller network error:', err.message);
    throw new Error('CRM lookup is temporarily unavailable -- do not retry this turn, apologize and continue without it.');
  }
  try {
    result = await resp.json();
  } catch (err) {
    console.error('[MCP-CRM] lookup_caller returned a non-JSON response, status=', resp.status);
    throw new Error('CRM lookup is temporarily unavailable -- do not retry this turn, apologize and continue without it.');
  }
  // MR AI changes - ends
  if (!resp.ok || result.result === 'error') {
    throw new Error(result.message || `lookup_caller failed: HTTP ${resp.status}`);
  }
  // content = single q_contacts row (or null), wrapupnote = the single
  // newest wrap-up note (or null, kept for anything already reading this
  // field), wrapupnotes = the last 5, newest first -- see
  // contactsByNumberAction's rewrite. Surfacing all 5 (not just the
  // newest) matters here specifically: a short "call dropped, call back"
  // note logged after the substantive one used to hide the note that
  // actually answers "where is my order".
  return {
    contact: result.content,
    lastWrapupNote: result.wrapupnote,
    recentWrapupNotes: result.wrapupnotes || [],
  };
}
// MR AI changes - ends

// MR AI changes - starts
// ---------------------------------------------------------------------
// Knowledge-base search -- the model's only route to the FULL text of the
// tenant's uploaded documents and selected website pages.
//
// The system prompt carries just an index (title + one-line snippet per
// document/page; see buildContextBlock() in claude_server.js), because
// inlining a crawled site would cost roughly 15k tokens on EVERY turn of a
// voice call. This tool is the other half of that trade: cheap prompt,
// targeted lookup when the caller actually asks something.
//
// `agentId` is NOT a model-supplied argument -- same principle as
// `subdomain` and `callerNumber` above. It is resolved per call from the
// ?agentId= query param mountMcp() reads (set by claude_server.js's
// ensureMcpClient from the resolved agent config) and closed over at
// registration time. The model can only ever search THIS agent's own
// knowledge; it cannot reach another agent's documents by asking.
//
// Hits TeamsController::aiKbSearchAction over the same internal
// pre-shared-key channel lookupCaller() above already uses.
// ---------------------------------------------------------------------
async function searchKnowledgeBase({ query, topK }, subdomain, agentId) {
  if (!agentId) {
    return { results: [], note: 'no knowledge base is configured for this agent' };
  }
  if (!query || !String(query).trim()) {
    return { results: [], note: 'a non-empty query is required' };
  }
  console.log('[MCP-KB] search_knowledge_base agent=', agentId, 'query=', JSON.stringify(query));
  const url = `https://${subdomain}:8443/teamsai/aikbsearch`;

  // Same split try/catch as lookupCaller(): a network failure and a
  // non-JSON body are different faults but need the same conversational
  // recovery, and the raw exception text ("Unexpected end of JSON input")
  // is useless to the model -- observed live to make it retry rather than
  // move on.
  let resp;
  let result;
  try {
    resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Internal-Key': RINGQ_INTERNAL_KEY,
      },
      body: JSON.stringify({ subdomain, agentId, query: String(query), topK: topK || 4 }),
    });
  } catch (err) {
    console.error('[MCP-KB] search_knowledge_base network error:', err.message);
    throw new Error('The knowledge base is temporarily unavailable -- do not retry this turn, and do not answer from general knowledge.');
  }
  try {
    result = await resp.json();
  } catch (err) {
    console.error('[MCP-KB] search_knowledge_base returned a non-JSON response, status=', resp.status);
    throw new Error('The knowledge base is temporarily unavailable -- do not retry this turn, and do not answer from general knowledge.');
  }
  if (!resp.ok || result.result === 'error') {
    throw new Error(result.message || `search_knowledge_base failed: HTTP ${resp.status}`);
  }

  const results = result.results || [];
  console.log('[MCP-KB] search_knowledge_base returned', results.length, 'passage(s)');
  // An empty result is a legitimate, common answer ("we don't cover that"),
  // not an error -- say so explicitly so the model uses its grounding
  // fallback line instead of treating the silence as permission to guess.
  if (!results.length) {
    return { results: [], note: 'Nothing in this business\'s documents or website pages matches that. Do not answer from general knowledge -- use your fallback line.' };
  }
  return { results };
}
// MR AI changes - ends

/**
 * ONE McpServer, ONE set of read-only tools, Outlook and Google together
 * -- this is the single change that satisfies "keep it all in one file,
 * one endpoint": there's no per-provider server object here anymore,
 * just more server.tool(...) calls registered against the same server.
 *
 * NOTE ON send_email / send_gmail / create_event: none of these three
 * are exposed as MCP tools Claude can call. For actions reachable from
 * an unauthenticated phone line that actually send mail or create
 * events, we don't want the model itself deciding when to act and with
 * what exact parameters -- even gated to one turn, that's still "the
 * model's tool-call decision is the send/create." Instead: Claude
 * proposes a structured draft (captured by claude_server.js and spoken
 * back to the caller for confirmation), and only if the caller says yes
 * does claude_server.js call the matching plain REST route below --
 * deterministic code, no model decision in that step. See the three
 * app.post(...) routes further down.
 */
// subdomain identifies which connected business/Google account this
// particular request's Google tools should act on. It is NOT something
// the model provides as a tool argument (a caller has no way to know or
// say it) -- it's resolved per-request from the URL by mountMcp() below
// and closed over here when each tool handler is registered, since a
// fresh server/transport (and therefore a fresh buildReadServer() call)
// already happens on every request.
// MR AI changes - starts: buildReadServer now also takes callerNumber (see
// the lookup_caller comment above) and registers Outlook tools whenever
// Outlook is configured OR this call has an agent owner who may have a
// per-user Microsoft 365 grant, matching the Google branch below.
function buildReadServer(subdomain, callerNumber, ownerUserUuid, agentId) {
  const server = new McpServer({ name: 'ringq-read', version: '1.0.0' });

  // MR AI changes - starts
  // The tenant's own documents and website pages. Gated ONLY on agentId --
  // it has no Google/Outlook dependency at all, and the knowledge is scoped
  // to one agent, so without an agentId there is nothing that could be
  // searched. `query` IS model-supplied (unlike every other argument in this
  // file) because the caller's question is the only thing that can drive a
  // search; it is only ever tokenized and substring-matched server-side,
  // never interpolated into a key or a query language.
  if (agentId) {
    server.tool(
      'search_knowledge_base',
      'Search this business\'s own uploaded documents and website pages for the answer to a ' +
        'question about the business -- its services, pricing, hours, policies, products, people or ' +
        'locations. The KNOWLEDGE INDEX in your instructions lists what is available to search. Use ' +
        'the caller\'s own words as the query. Answer only from what this returns.',
      {
        query: z.string().describe('What to look for, in the caller\'s own words, e.g. "refund policy" or "opening hours"'),
        topK: z.number().optional().describe('How many passages to return (default 4, max 8)'),
      },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await searchKnowledgeBase(args, subdomain, agentId)) }] })
    );
  }
  // MR AI changes - ends

  // MR AI changes: gated the same way as the Google block below -- a
  // per-user Microsoft 365 grant can serve these even with no tenant-wide
  // MS_MAILBOX configured, matching GOOGLE_CONFIGURED || ownerUserUuid.
  if (OUTLOOK_CONFIGURED || ownerUserUuid) {
    server.tool(
      'search_emails',
      'Search the Outlook mailbox for emails matching a query (subject/body/sender keywords).',
      { query: z.string().describe('Search text, e.g. sender name or keywords'), top: z.number().optional() },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await searchEmails(args, subdomain, ownerUserUuid, agentId)) }] })
    );

    server.tool(
      'get_email',
      'Fetch the full content of one Outlook email by its id (from search_emails results).',
      { id: z.string() },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await getEmail(args, subdomain, ownerUserUuid, agentId)) }] })
    );
  }

  // Caller-identification / CRM lookup. Always registered (no Google or
  // Outlook dependency) -- takes no model-supplied arguments, since
  // callerNumber/subdomain are trusted, call-scoped values closed over
  // here, not something the caller-on-the-phone could tell the model.
  server.tool(
    'lookup_caller',
    'Look up the current caller in the CRM by their phone number (already known from the call -- ' +
      'no arguments needed). Returns the matching contact record (name, company, email, notes) and ' +
      'up to the 5 most recent wrap-up notes logged against this number, newest first -- e.g. an ' +
      'agent note like "order dispatched, tracking ABC123" that answers order-status questions. ' +
      'Check more than just the newest note: a short later note like "call dropped, call back" can ' +
      'sit on top of the one that actually answers the caller\'s question. Use this whenever the ' +
      'caller asks about their account, an order, or anything else that depends on who they are.',
    {},
    async () => ({ content: [{ type: 'text', text: JSON.stringify(await lookupCaller(callerNumber, subdomain)) }] })
  );
  // MR AI changes - ends

  // MR AI changes - starts: Google tools register when EITHER a shared tenant
  // connection exists (GOOGLE_CONFIGURED, i.e. REDIS_URL is set) OR this call
  // has an agent owner whose personal Google account may be connected. The
  // second half matters: a server running purely on per-user connectors has
  // no reason to set REDIS_URL, and gating on it alone meant those
  // deployments registered no Google tools at all, so the connector a user
  // had just authorized did nothing. getGoogleAuthForAccount() resolves which
  // of the two credentials to actually use, and throws a message naming both
  // possibilities if neither is available.
  if (GOOGLE_CONFIGURED || ownerUserUuid) {
    server.tool(
      'search_drive_files',
      'Search Google Drive for files matching a name or content query.',
      { query: z.string().describe('Search text, e.g. a file name or keywords'), top: z.number().optional() },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await searchDriveFiles(args, subdomain, ownerUserUuid, agentId)) }] })
    );

    server.tool(
      'read_spreadsheet',
      'Read values from a Google Sheet by its file id (from search_drive_files results). ' +
        'Omit range to read the entire first sheet.',
      { fileId: z.string(), range: z.string().optional().describe('A1 notation, e.g. "Sheet1!A1:D20"') },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await readSpreadsheet(args, subdomain, ownerUserUuid, agentId)) }] })
    );

    server.tool(
      'list_calendar_events',
      'List upcoming Google Calendar events, optionally filtered by a search query or time range.',
      {
        query: z.string().optional(),
        timeMin: z.string().optional().describe('ISO 8601, defaults to now'),
        timeMax: z.string().optional(),
        top: z.number().optional(),
      },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await listCalendarEvents(args, subdomain, ownerUserUuid, agentId)) }] })
    );

    server.tool(
      'search_gmail',
      'Search Gmail for messages matching a query (subject/body/sender keywords).',
      { query: z.string().describe('Gmail search syntax, e.g. "from:jane subject:invoice"'), top: z.number().optional() },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await searchGmail(args, subdomain, ownerUserUuid, agentId)) }] })
    );

    server.tool(
      'get_gmail_message',
      'Fetch the full content of one Gmail message by its id (from search_gmail results).',
      { id: z.string() },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await getGmailMessage(args, subdomain, ownerUserUuid, agentId)) }] })
    );
  }

  // MR AI changes - starts
  // check_availability, create_calendar_event and send_email are gated the
  // same way (the confirm gate for the two writes lives client-side in
  // claude_server.js -- check_availability is read-only so it is never
  // gated at all), and their registration gate is broader than the
  // Google-only block above: any of the three can work from Outlook alone,
  // so all three register whenever EITHER capability might work, and
  // validate the requested account/provider at call time rather than at
  // registration time.
  if (GOOGLE_CONFIGURED || ownerUserUuid || OUTLOOK_CONFIGURED) {
    server.tool(
      'check_availability',
      'Checks whether the connected calendar(s) are free during a specific time range. Always call ' +
        'this BEFORE proposing or creating a meeting time, so you propose a time that is actually free.',
      {
        startIso: z.string().describe('Start of the range to check, full ISO 8601 with timezone offset'),
        endIso: z.string().describe('End of the range to check, full ISO 8601 with timezone offset'),
      },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await checkAvailability(args, subdomain, ownerUserUuid, agentId)) }] })
    );

    // MR AI changes - starts
    // create_calendar_event is a REAL tool the model can call -- it
    // changes state. The safety gate is NOT here: this server executes it
    // the moment it is called, exactly like any read tool. The gate lives
    // entirely in claude_server.js (GATED_TOOLS / callMcpTool()), which
    // intercepts the FIRST call and never lets it reach this server until
    // the caller has confirmed out loud. Creating an "outlook" event always
    // also creates a real Microsoft Teams meeting -- see
    // createOutlookEvent()'s comment for why there is no separate
    // "plain Outlook event, no Teams" option.
    server.tool(
      'create_calendar_event',
      'Creates a calendar event on a connected account. Creating an "outlook" event automatically ' +
        'creates a real Microsoft Teams meeting with a join link. Only call this after you have said ' +
        'the exact title, start time, end time and any attendees back to the caller and they have ' +
        'explicitly confirmed out loud -- this tool holds the request for confirmation rather than ' +
        'creating anything on the first call. Call check_availability first if it is available.',
      {
        account: z.enum(['google', 'outlook']).describe('Which connected calendar to create the event on'),
        summary: z.string().describe('Event title'),
        start: z.string().describe('Full ISO 8601 start time with timezone offset'),
        end: z.string().describe('Full ISO 8601 end time with timezone offset'),
        attendees: z.array(z.string()).optional().describe('Attendee email addresses'),
        description: z.string().optional(),
      },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await createCalendarEventUnified(args, subdomain, ownerUserUuid, agentId)) }] })
    );
    // MR AI changes - ends

    server.tool(
      'send_email',
      'Sends an email from a connected account. Only call this after you have said the exact ' +
        'recipient, subject and body back to the caller and they have explicitly confirmed out loud ' +
        '-- this tool holds the request for confirmation rather than sending anything on the first call.',
      {
        account: z.enum(['gmail', 'outlook']).describe('Which connected account to send from'),
        to: z.string().describe('Recipient email address'),
        subject: z.string(),
        body: z.string(),
      },
      async (args) => ({ content: [{ type: 'text', text: JSON.stringify(await sendEmailUnified(args, subdomain, ownerUserUuid, agentId)) }] })
    );
  }
  // MR AI changes - ends

  return server;
}

// ---------------------------------------------------------------------
// HTTP wiring: shared-secret bearer auth + a FRESH McpServer/transport
// per request, mounted at the single /mcp/read path. Building these
// once at startup and reusing them across requests works for exactly
// the first request and then 500s on every request after that -- the
// SDK's stateless StreamableHTTPServerTransport is documented to
// require a new instance per request. (This is the exact bug we chased
// down and fixed earlier -- kept fixed here from the start.)
// ---------------------------------------------------------------------
const app = express();
app.use(express.json());

function requireAuth(req, res, next) {
  const auth = req.headers['authorization'] || '';
  const expected = `Bearer ${MCP_SHARED_SECRET}`;
  if (auth !== expected) {
    console.warn('[MCP] rejected request with bad/missing auth header');
    res.status(401).json({ error: 'unauthorized' });
    return;
  }
  next();
}

function mountMcp(path, buildServer) {
  app.post(path, requireAuth, async (req, res) => {
    // Which tenant's Google connection (google_settings:<subdomain>) this
    // request's Google tools should use -- claude_server.js passes this
    // as ?subdomain=<id> per call, once real per-call tenant routing
    // exists on its side. Falls back to one configured default until
    // then, so this is testable today against a real connected subdomain.
    const subdomain = req.query.subdomain || GOOGLE_DEFAULT_SUBDOMAIN;
    // MR AI changes - starts: same per-call resolution as subdomain,
    // for the lookup_caller tool. null (not '') when absent, so
    // lookupCaller() can tell "no caller ID for this call" apart from
    // an empty string it would otherwise try to query with.
    const callerNumber = req.query.callerNumber || null;
    // MR AI changes - ends
    // MR AI changes - starts: which RingQ user's connected Google/Microsoft
    // account this call's tools should act on. Comes from the resolved agent
    // config (agentConfig.ownerUserUuid/agentConfig.id) via claude_server.js's
    // ensureMcpClient, per call -- not from anything the model or the caller
    // can influence. Absent means "use the shared tenant connection", which
    // is the pre-per-user-connectors behaviour. Both must be present for a
    // per-user lookup: the grant is keyed on the (owner, agent) pair, not on
    // either alone -- see fetchUserConnectorToken().
    const ownerUserUuid = req.query.ownerUserUuid || null;
    const agentId = req.query.agentId || null;
    // MR AI changes - ends
    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
    const server = buildServer(subdomain, callerNumber, ownerUserUuid, agentId);
    try {
      await server.connect(transport);
      res.on('close', () => {
        transport.close();
        server.close();
      });
      await transport.handleRequest(req, res, req.body);
    } catch (err) {
      console.error(`[MCP] error handling request on ${path}:`, err);
      if (!res.headersSent) {
        res.status(500).json({ error: 'internal error' });
      }
    }
  });
  console.log(`[MCP] mounted ${path}`);
}

mountMcp('/mcp/read', buildReadServer);

// MR AI changes - starts
// The MCP Streamable HTTP spec lets a client open a standalone SSE stream
// with GET on the same path, to receive server-initiated messages. This
// server is stateless (a fresh McpServer per POST), so it has nothing to
// push and does not offer that stream -- but the client asks anyway, right
// after notifications/initialized returns 202. The SDK client treats 405
// as the documented "this server has no SSE stream" answer and moves on;
// any OTHER status (Express's default 404 for an unregistered method)
// makes it throw a StreamableHTTPError instead. That error is swallowed by
// the transport's onerror handler, so it never broke a call -- it just
// printed an alarming 404 on every single one, which cost real time during
// diagnosis. Answering 405 is the spec-correct way to say "not offered".
app.get('/mcp/read', requireAuth, (_req, res) => {
  res.status(405).json({ error: 'this server does not offer a standalone SSE stream (stateless mode)' });
});
// MR AI changes - ends

// Plain REST endpoint for the actual Outlook send action -- deliberately
// NOT MCP/tool-call shaped. claude_server.js calls this directly, from
// its own code, only after verifying the caller confirmed a specific
// draft out loud. No model decision happens in this step.
app.post('/send', requireAuth, async (req, res) => {
  // MR AI changes - starts: Outlook is now optional (see OUTLOOK_CONFIGURED
  // above) -- return 501 same as the Gmail/Calendar routes below instead
  // of letting sendEmail() fail with a confusing Graph auth error.
  if (!OUTLOOK_CONFIGURED) {
    res.status(501).json({ error: 'Outlook integration not configured on this server' });
    return;
  }
  // MR AI changes - ends
  try {
    const { to, subject, body } = req.body || {};
    if (!to || !subject || !body) {
      res.status(400).json({ error: 'to, subject, and body are all required' });
      return;
    }
    const result = await sendEmail({ to, subject, body });
    res.json(result);
  } catch (err) {
    console.error('[MCP] /send failed:', err);
    res.status(500).json({ error: err.message });
  }
});

// Same pattern, for Gmail. Returns 501 rather than crashing if Google
// isn't configured on this deployment.
app.post('/send-gmail', requireAuth, async (req, res) => {
  // MR AI changes: gate on ownerUserUuid too -- a server with no shared
  // Google connection can still send as the agent owner's own account.
  if (!GOOGLE_CONFIGURED && !(req.body || {}).ownerUserUuid) {
    res.status(501).json({ error: 'Google integration not configured on this server' });
    return;
  }
  try {
    // subdomain travels in the body here (not a query param) since this
    // is a direct REST call claude_server.js makes with a JSON body, not
    // an MCP request -- see sendGmailDirect() there. ownerUserUuid rides
    // along the same way, for the same reason.
    const { subdomain = GOOGLE_DEFAULT_SUBDOMAIN, ownerUserUuid = null, agentId = null, to, subject, body } = req.body || {};
    if (!to || !subject || !body) {
      res.status(400).json({ error: 'to, subject, and body are all required' });
      return;
    }
    const result = await sendGmail({ to, subject, body }, subdomain, ownerUserUuid, agentId);
    res.json(result);
  } catch (err) {
    console.error('[MCP] /send-gmail failed:', err);
    res.status(500).json({ error: err.message });
  }
});

// Same pattern, for creating a Google Calendar event.
app.post('/create-event', requireAuth, async (req, res) => {
  // MR AI changes: see /send-gmail above -- same per-user gate.
  if (!GOOGLE_CONFIGURED && !(req.body || {}).ownerUserUuid) {
    res.status(501).json({ error: 'Google integration not configured on this server' });
    return;
  }
  try {
    const {
      subdomain = GOOGLE_DEFAULT_SUBDOMAIN,
      ownerUserUuid = null,
      agentId = null,
      summary, start, end, attendees, description,
    } = req.body || {};
    if (!summary || !start || !end) {
      res.status(400).json({ error: 'summary, start, and end are all required' });
      return;
    }
    const result = await createCalendarEvent(
      { summary, start, end, attendees, description }, subdomain, ownerUserUuid, agentId);
    res.json(result);
  } catch (err) {
    console.error('[MCP] /create-event failed:', err);
    res.status(500).json({ error: err.message });
  }
});

// MR AI changes - starts
// A cheap unauthenticated liveness probe. Everything else here sits behind
// requireAuth, which meant there was no way to answer "is this process even
// up?" without a valid bearer token -- and "the MCP server is not running"
// was one of the most common reasons a call silently ended up with no
// tools. Deliberately exposes nothing but the boot-time capability flags.
app.get('/health', (_req, res) => {
  res.json({
    result: 'success',
    outlook: OUTLOOK_CONFIGURED ? 'enabled' : 'disabled',
    google: GOOGLE_CONFIGURED ? 'enabled' : 'disabled',
    uptimeSeconds: Math.round(process.uptime()),
  });
});
// MR AI changes - ends

app.listen(PORT, '127.0.0.1', () => {
  console.log('[MCP] listening on 127.0.0.1:' + PORT +
    " (put this behind nginx+TLS, don't expose directly)");
  // MR AI changes - starts: Outlook was reported as "enabled"
  // unconditionally, regardless of OUTLOOK_CONFIGURED -- so the one line an
  // operator checks to find out why the Outlook tools are missing actively
  // said they were fine. Both halves now read the real flag, and a server
  // with neither integration says so outright, because "only lookup_caller
  // is registered" is otherwise something you can only discover by
  // completing an MCP handshake.
  console.log('[MCP] Outlook: ' +
    (OUTLOOK_CONFIGURED ? 'enabled' : 'disabled (MS_* not fully configured)') +
    '. Google: ' +
    (GOOGLE_CONFIGURED ? 'enabled' : 'disabled (REDIS_URL not set)'));
  if (!OUTLOOK_CONFIGURED && !GOOGLE_CONFIGURED) {
    console.warn('[MCP] neither Outlook nor Google is configured -- the only tool this server ' +
      'will expose is lookup_caller, so an AI agent asked about email or calendar has nothing ' +
      'to call.');
  }
  // MR AI changes - ends
});
