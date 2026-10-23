// apns.js - [apns-direct] iOS VoIP pushes straight to Apple (HTTP/2 + .p8 token auth), no relay.
//
// Configured from /etc/ringq/.env; without these it stays off and callers keep using the relay:
//   APNS_KEY_FILE=/etc/ringq/apns/AuthKey_XXXXXXXXXX.p8
//   APNS_KEY_ID=XXXXXXXXXX
//   APNS_TEAM_ID=6GP58F5X59
//   APNS_BUNDLE_IDS=com.ringq.app,com.ringq.app.preview,com.ringq.app.dev   (tried in this order)
//
// A device token belongs to one app (bundle id) and one Apple environment: store / TestFlight /
// ad hoc builds use production, Xcode development builds use sandbox. The token alone doesn't
// say which, so the first push tries each bundle on production, then sandbox, and remembers what
// Apple accepted (Redis apnsRoute:<token>, 30 days).

const fs = require('fs');
const http2 = require('http2');
const crypto = require('crypto');

const HOSTS = { production: 'https://api.push.apple.com', sandbox: 'https://api.sandbox.push.apple.com' };
const ROUTE_TTL = 30 * 24 * 3600;

let cfg = null;
let redis = null;
let log = console;
const sessions = {};
let jwt = { token: null, at: 0 };

let onDeadToken = null; // async (token, reason) => void: forget a token Apple says is gone

function init(opts = {}) {
  redis = opts.redis || null;
  if (opts.logger) log = opts.logger;
  if (opts.onDeadToken) onDeadToken = opts.onDeadToken;
  const env = opts.env || process.env;
  if (!env.APNS_KEY_FILE || !env.APNS_KEY_ID || !env.APNS_TEAM_ID) return false;
  try {
    cfg = {
      key: fs.readFileSync(env.APNS_KEY_FILE, 'utf8'),
      keyId: env.APNS_KEY_ID,
      teamId: env.APNS_TEAM_ID,
      bundles: String(env.APNS_BUNDLE_IDS || 'com.ringq.app,com.ringq.app.preview,com.ringq.app.dev')
        .split(',').map((s) => s.trim()).filter(Boolean),
    };
    log.log('[apns] direct to Apple, bundles', cfg.bundles.join(' '));
    return true;
  } catch (e) {
    log.error('[apns] key not readable, staying on the relay:', e.message);
    cfg = null;
    return false;
  }
}

function enabled() { return !!cfg; }

function bearer() {
  // Apple accepts a token for up to an hour; refresh after 50 minutes
  if (jwt.token && Date.now() - jwt.at < 50 * 60 * 1000) return jwt.token;
  const b64 = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');
  const unsigned = `${b64({ alg: 'ES256', kid: cfg.keyId })}.${b64({ iss: cfg.teamId, iat: Math.floor(Date.now() / 1000) })}`;
  const sig = crypto.sign('sha256', Buffer.from(unsigned), { key: cfg.key, dsaEncoding: 'ieee-p1363' });
  jwt = { token: `${unsigned}.${sig.toString('base64url')}`, at: Date.now() };
  return jwt.token;
}

function session(env) {
  const s = sessions[env];
  if (s && !s.closed && !s.destroyed) return s;
  const n = http2.connect(HOSTS[env]);
  n.on('error', (e) => { log.error('[apns]', env, 'session', e.message); delete sessions[env]; });
  n.on('goaway', () => { delete sessions[env]; });
  n.unref();
  sessions[env] = n;
  return n;
}

function post(env, bundle, token, body, collapseId) {
  return new Promise((resolve) => {
    let status = 0;
    let data = '';
    let req;
    try {
      req = session(env).request({
        ':method': 'POST',
        ':path': `/3/device/${token}`,
        authorization: `bearer ${bearer()}`,
        'apns-topic': `${bundle}.voip`,
        'apns-push-type': 'voip',
        'apns-priority': '10',
        'apns-expiration': '0', // now or never: a late ring is worse than none
        ...(collapseId ? { 'apns-collapse-id': String(collapseId).slice(0, 64) } : {}),
        'content-type': 'application/json',
      });
    } catch (e) {
      return resolve({ ok: false, status: 0, reason: e.message });
    }
    req.setTimeout(5000, () => { req.close(); resolve({ ok: false, status: 0, reason: 'timeout' }); });
    req.on('response', (h) => { status = h[':status']; });
    req.on('data', (c) => { data += c; });
    req.on('end', () => {
      let reason = '';
      try { reason = data ? JSON.parse(data).reason : ''; } catch (_) { reason = data; }
      resolve({ ok: status === 200, status, reason });
    });
    req.on('error', (e) => resolve({ ok: false, status: 0, reason: e.message }));
    req.end(JSON.stringify(body));
  });
}

// Reasons that mean "wrong app or wrong environment for this token": try the next route.
const WRONG_ROUTE = new Set(['DeviceTokenNotForTopic', 'BadDeviceToken', 'TopicDisallowed']);

// Send one VoIP push. payload = the app's fields (domain, extension, id, call, caller_id, ...).
// Returns { ok, status, reason, bundle, env }.
async function sendVoip(token, payload, collapseId) {
  if (!cfg) return { ok: false, reason: 'apns not configured' };
  const body = { aps: {}, ...payload };
  const known = redis ? await redis.get(`apnsRoute:${token}`).catch(() => null) : null;
  const routes = [];
  if (known) { const [env, bundle] = known.split('|'); routes.push({ env, bundle }); }
  for (const env of ['production', 'sandbox']) {
    for (const bundle of cfg.bundles) {
      if (!routes.some((r) => r.env === env && r.bundle === bundle)) routes.push({ env, bundle });
    }
  }
  let last = { ok: false, reason: 'no route' };
  for (const r of routes) {
    const res = await post(r.env, r.bundle, token, body, collapseId);
    last = { ...res, ...r };
    if (res.ok) {
      if (redis && known !== `${r.env}|${r.bundle}`) redis.set(`apnsRoute:${token}`, `${r.env}|${r.bundle}`, { EX: ROUTE_TTL }).catch(() => {});
      return last;
    }
    if (res.status === 410 || res.reason === 'Unregistered') {
      // the app was deleted or reinstalled: this token will never ring anything again
      if (redis) redis.del(`apnsRoute:${token}`).catch(() => {});
      if (onDeadToken) Promise.resolve(onDeadToken(token, 'Unregistered')).catch(() => {});
      return last;
    }
    if (!WRONG_ROUTE.has(res.reason)) return last; // a real error (auth, payload, ...): report it
  }
  // no app/environment we know accepted it: not one of our apps' tokens (or a broken one)
  if (redis) redis.del(`apnsRoute:${token}`).catch(() => {});
  if (onDeadToken && WRONG_ROUTE.has(last.reason)) Promise.resolve(onDeadToken(token, last.reason)).catch(() => {});
  return last;
}

// Same as sendVoip for several tokens; logs each result.
async function sendVoipMany(tokens, payload, collapseId) {
  const results = await Promise.all(tokens.map(async (t) => ({ token: t, ...(await sendVoip(t, { ...payload, pushToken: t }, collapseId)) })));
  for (const r of results) {
    const what = `${payload.call || "''"} id=${payload.id || '-'}`;
    if (r.ok) log.log('[apns] sent', what, payload.extension, payload.domain, r.bundle, r.env);
    else log.error('[apns] failed', what, payload.extension, payload.domain, r.status, r.reason, r.token.slice(0, 12));
  }
  return results;
}

module.exports = { init, enabled, sendVoip, sendVoipMany };
