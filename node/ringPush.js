// ringPush.js - [ring-push] one push path for every way an extension rings.
//
// FreeSWITCH runs ringq_ring.lua (server-configs/ring-push) on every ring of an extension,
// whatever routed the call there: direct, queue, IVR, ring group, transfer, follow-me. The script
// fires CUSTOM ringq::ring; this module pushes the extension's mobile apps and tells the script
// how many it pushed (hash ringq_ring/<ring id>), so the script knows whether to wait for an app
// to wake up and register. When that ring ends it sends the stop push.
//
// Tokens come from Postgres q_devices (shared by every instance of a multi-server install):
// device_type 2 = iOS VoIP token, 1 = Android FCM token; enabled = 0 means "Calls off".
//
// Mode is the FusionPBX setting ringq / ring_push_mode (default setting, per-domain override):
//   off   - nothing (old push code in server.js runs as before)
//   event - FreeSWITCH reports every ring; logged here, nothing pushed
//   wake  - push + wait for the app; the old push code skips these domains (owns())

const fs = require('fs');
const crypto = require('crypto');
const WebSocket = require('ws');
const apns = require('./apns');
const pushOwner = require('./pushOwner');

const STOP_REASONS = { answered: 'answered', decline: 'decline', missed: 'missed' };
const RING_TTL_MS = 10 * 60 * 1000;

let redis, db, admin, log = console;
let eslConn = null;
let relayUrl = 'ws://157.245.60.143:8880';
let dryRun = false;

const rings = new Map();    // ring id -> { ext, domain, android, ios, answered, at }
const modes = new Map();    // domain -> { mode, at }
const MODE_TTL_MS = 60 * 1000;

// Android ring pushes go through the Firebase project the RingQ app is built with. Its service
// account key is installed per server at FCM_KEY_PATH (server-configs/fcm/install.sh), never in
// git; without it the shared firebase-admin app (node/secret.json) is used, as before.
const FCM_KEY_PATH = '/etc/ringq/fcm/service-account.json';
let fcm = null; // { messaging() }

function fcmFor(defaultAdmin) {
  try {
    if (!fs.existsSync(FCM_KEY_PATH)) return defaultAdmin;
    const key = JSON.parse(fs.readFileSync(FCM_KEY_PATH, 'utf8'));
    const existing = defaultAdmin.apps.find((a) => a && a.name === 'ringq-push');
    const app = existing || defaultAdmin.initializeApp({ credential: defaultAdmin.credential.cert(key) }, 'ringq-push');
    log.log('[ring-push] android pushes via Firebase project', key.project_id);
    return { messaging: () => app.messaging() };
  } catch (e) {
    log.error('[ring-push] FCM key', FCM_KEY_PATH, e.message, '- using the shared Firebase app');
    return defaultAdmin;
  }
}

function init(opts) {
  redis = opts.redis;
  db = opts.db;
  admin = opts.firebaseAdmin;
  if (opts.logger) log = opts.logger;
  fcm = admin ? fcmFor(admin) : null;
  if (opts.logger) log = opts.logger;
  if (opts.relayUrl) relayUrl = opts.relayUrl;
  dryRun = !!opts.dryRun;
  setInterval(() => {
    const cutoff = Date.now() - RING_TTL_MS;
    for (const [id, r] of rings) if (r.at < cutoff) rings.delete(id);
  }, 60 * 1000).unref();
}

// ---- mode ------------------------------------------------------------------

async function modeFor(domain) {
  const hit = modes.get(domain);
  if (hit && Date.now() - hit.at < MODE_TTL_MS) return hit.mode;
  let mode = 'off';
  try {
    const { rows } = await db.query(
      `SELECT coalesce(
          (SELECT s.domain_setting_value FROM v_domain_settings s JOIN v_domains d ON d.domain_uuid = s.domain_uuid
            WHERE d.domain_name = $1 AND s.domain_setting_category = 'ringq'
              AND s.domain_setting_subcategory = 'ring_push_mode' AND s.domain_setting_enabled = 'true' LIMIT 1),
          (SELECT default_setting_value FROM v_default_settings
            WHERE default_setting_category = 'ringq' AND default_setting_subcategory = 'ring_push_mode'
              AND default_setting_enabled = 'true' LIMIT 1),
          'off') AS mode`, [domain]);
    mode = rows[0] && rows[0].mode || 'off';
  } catch (e) {
    log.error('[ring-push] mode lookup', domain, e.message);
    if (hit) return hit.mode;
  }
  modes.set(domain, { mode, at: Date.now() });
  // attended.js / status.js read this (pushOwner.js) to keep their old pushes quiet
  if (redis) redis.set(pushOwner.MODE_KEY(domain), mode, { EX: 300 }).catch(() => {});
  return mode;
}

// The default mode for pushOwner.js (a domain nobody has rung yet still follows it).
async function publishDefaultMode() {
  if (!db || !redis) return;
  try {
    const { rows } = await db.query(
      `SELECT default_setting_value AS mode FROM v_default_settings
        WHERE default_setting_category = 'ringq' AND default_setting_subcategory = 'ring_push_mode'
          AND default_setting_enabled = 'true' LIMIT 1`);
    await redis.set(pushOwner.DEFAULT_MODE_KEY, (rows[0] && rows[0].mode) || 'off', { EX: 300 });
  } catch (e) { /* next round */ }
}

// The old per-route push code in server.js checks this and stays quiet for these domains.
async function owns(domain) {
  if (!domain || !db) return false;
  // Only when mod_ringq is loaded here: without it the dial-string stays normal (no ringq/ push),
  // and the old code must keep pushing or this server would push nothing.
  if (!moduleLoaded) return false;
  return (await modeFor(domain)) === 'wake';
}

// Is mod_ringq loaded in this server's FreeSWITCH? Checked on every ESL connect and every minute.
let moduleLoaded = false;
let moduleTimer = null;
function checkModule(conn) {
  try {
    conn.api('module_exists mod_ringq', (res) => {
      const loaded = /true/.test((res && (res.getBody ? res.getBody() : res.body)) || '');
      if (loaded !== moduleLoaded) log.log('[ring-push] mod_ringq', loaded ? 'loaded' : 'not loaded');
      moduleLoaded = loaded;
      if (redis) redis.set(pushOwner.MOD_KEY, loaded ? '1' : '0', { EX: 180 }).catch(() => {});
      publishDefaultMode();
    });
  } catch (e) {
    moduleLoaded = false;
  }
}

// ---- tokens ----------------------------------------------------------------

// Whether FreeSWITCH has a channel up for this extension (other than the one ringing it now):
// the presence id is <ext>@<domain> or <number alias>@<domain> on the extension's own legs.
async function extensionOnCall(ext, domain) {
  if (!eslConn) return true; // can't check: keep the old behaviour
  try {
    const { rows } = await db.query(
      `SELECT e.extension, e.number_alias FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid
        WHERE d.domain_name = $1 AND e.extension = $2`, [domain, String(ext)]);
    const ids = new Set([`${ext}@${domain}`.toLowerCase()]);
    for (const row of rows) if (row.number_alias) ids.add(`${row.number_alias}@${domain}`.toLowerCase());
    const res = await new Promise((resolve) => eslConn.api('show channels as json', resolve));
    const body = JSON.parse(String((res && res.body) || '{}'));
    return (body.rows || []).some((c) => ids.has(String(c.presence_id || '').toLowerCase())
      && String(c.callstate || '').toUpperCase() === 'ACTIVE');
  } catch (e) {
    log.error('[ring-push] on-call check', `${ext}@${domain}`, e.message);
    return true;
  }
}

// The old Flutter Android app registers with this placeholder when it has no FCM token: it
// can't be pushed (FCM answers invalid-argument), so it isn't a phone to ring.
const PUSH_TOKEN_PLACEHOLDER = '693aa721556325f440a886';
function isPlaceholderToken(token) {
  return String(token || '').startsWith(PUSH_TOKEN_PLACEHOLDER);
}

// FCM says this token belongs to no app any more (uninstalled / reinstalled): forget it, as
// apns.js does for iOS tokens Apple reports gone.
const DEAD_FCM_CODES = new Set(['messaging/registration-token-not-registered', 'messaging/invalid-registration-token']);
async function forgetDeadAndroidToken(token, code) {
  try {
    const { rowCount } = await db.query('DELETE FROM q_devices WHERE push_token = $1 AND device_type = 1', [token]);
    log.log('[ring-push] android token gone', token.slice(0, 12), code, 'rows removed', rowCount);
  } catch (e) {
    log.error('[ring-push] android token cleanup', e.message);
  }
}

async function tokensFor(ext, domain) {
  const { rows } = await db.query(
    // A push token is one app on one phone, signed in to one extension: only its most recent
    // sign-in counts. Rows left by earlier sign-ins (the PHP cleanup may not be on this server
    // yet) must not keep ringing a phone that now belongs to another extension or tenant.
    `SELECT q.device_type, q.push_token FROM q_devices q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
      WHERE d.domain_name = $1 AND q.extension = $2 AND coalesce(q.enabled, 1) <> 0
        AND coalesce(q.push_token, '') <> ''
        AND NOT EXISTS (
          SELECT 1 FROM q_devices o
           WHERE o.push_token = q.push_token AND o.id <> q.id
             AND (greatest(o.insert_date, o.update_login), o.id)
               > (greatest(q.insert_date, q.update_login), q.id))
        -- one mobile app per extension: only the phone that signed in (or re-registered) last.
        -- A QR sign-in on a phone that already has a row updates update_login rather than
        -- inserting, so the newest of the two times counts. Where the PHP one-mobile rule is
        -- deployed the replaced phone is signed out and can't register again.
        AND NOT EXISTS (
          SELECT 1 FROM q_devices n
           WHERE n.domain_uuid = q.domain_uuid AND n.extension = q.extension AND n.device_type IN (1, 2)
             AND coalesce(n.enabled, 1) <> 0 AND coalesce(n.push_token, '') <> ''
             AND n.push_token <> q.push_token
             AND (greatest(n.insert_date, n.update_login), n.id) > (greatest(q.insert_date, q.update_login), q.id))`, [domain, String(ext)]);
  const android = new Set();
  const ios = new Set();
  for (const r of rows) {
    if (isPlaceholderToken(r.push_token)) continue;
    if (Number(r.device_type) === 2) ios.add(r.push_token);
    else if (Number(r.device_type) === 1) android.add(r.push_token);
  }
  return { android: [...android], ios: [...ios] };
}

async function blocked(ext, domain) {
  const [a, b] = await Promise.all([
    redis.exists(`disableapp:${ext}:${domain}`),
    redis.exists(`extoff:${ext}:${domain}`),
  ]).catch(() => [0, 0]);
  return a > 0 || b > 0;
}

// Queue calls show "<queue name>: <caller>"; mod_ringq only knows the queue's extension.
async function queueNameFor(queueExt, domain) {
  try {
    const { rows } = await db.query(
      `SELECT q.queue_name FROM v_call_center_queues q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
        WHERE d.domain_name = $1 AND q.queue_extension = $2 LIMIT 1`, [domain, String(queueExt)]);
    return (rows[0] && rows[0].queue_name) || String(queueExt);
  } catch (e) {
    return String(queueExt);
  }
}

async function avatarFor(callerId, domain) {
  const key = callerId ? await redis.get(`${callerId}:${domain}`).catch(() => null) : null;
  return key ? `https://${domain}:8443/avr/${key}` : `https://${domain}:8443/img/ringq-square.png`;
}

// ---- senders ---------------------------------------------------------------

// The apps' payload, same field names server.js has always sent. `call` is the ring id while
// ringing (it is also the SIP Call-ID of the INVITE, so the app matches the two), or the stop
// reason. `id` is always the ring id.
function payloadFor(r, call) {
  const caller = r.callerName || r.callerNumber || '';
  return {
    domain: r.domain,
    extension: String(r.ext),
    id: r.id,
    call,
    caller_id: r.callerNumber || '',
    // queue calls: "<queue name>: <caller>", like the INVITE ringq_ring.lua sends the phone
    nameCaller: r.queueName ? `${r.queueName}: ${caller}` : caller,
    queue: r.queue || '',
    queue_name: r.queueName || '',
    time: String(Date.now()),
    time2: new Date().toISOString(),
    avatar: r.avatar || '',
    kind: 'ring',
  };
}

async function sendAndroid(r, call, tokens) {
  if (!tokens.length) return 0;
  // Data-only + high priority wakes the app even when it is killed. RingQMessagingService only
  // acts on type "ring" and treats call = answered / decline / missed / '' as "stop ringing".
  const data = { type: 'ring', ...payloadFor(r, call) };
  for (const k of Object.keys(data)) data[k] = String(data[k] ?? '');
  const message = {
    tokens,
    data,
    android: { priority: 'high', ttl: call === r.id ? 30 * 1000 : 120 * 1000, collapseKey: `call-${r.id}` },
  };
  if (dryRun || !fcm) { log.log('[ring-push] android (dry)', JSON.stringify(message)); return tokens.length; }
  try {
    const res = await fcm.messaging().sendEachForMulticast(message);
    res.responses.forEach((x, i) => {
      if (x.success) return;
      const code = x.error && x.error.code;
      log.error('[ring-push] android failed', r.ext, r.domain, tokens[i].slice(0, 12), code);
      if (DEAD_FCM_CODES.has(code)) forgetDeadAndroidToken(tokens[i], code);
    });
    return res.successCount;
  } catch (e) {
    log.error('[ring-push] android send', r.ext, r.domain, e.message);
    return 0;
  }
}

// iOS VoIP push: straight to Apple when apns.js is configured, otherwise through the old relay
// (same message shape as pushVoipMutipleDevice in server.js).
async function sendIos(r, call, tokens) {
  if (!tokens.length) return 0;
  const payload = payloadFor(r, call);
  // the current iOS app treats an empty call as "stop" for a decline
  if (call === 'decline') payload.call = '';
  if (apns.enabled() && !dryRun) {
    const results = await apns.sendVoipMany(tokens, payload, r.id);
    return results.filter((x) => x.ok).length;
  }
  payload.pushToken = tokens[0];
  const message = { token: tokens, payload, device: 'ios', status: 'multiple', apnsId: crypto.randomUUID() };
  if (dryRun) { log.log('[ring-push] ios (dry)', JSON.stringify(message)); return Promise.resolve(tokens.length); }
  return new Promise((resolve) => {
    let done = false;
    const finish = (n) => { if (!done) { done = true; try { ws.close(); } catch (_) {} resolve(n); } };
    const ws = new WebSocket(relayUrl);
    ws.on('open', () => { ws.send(JSON.stringify(message)); setTimeout(() => finish(tokens.length), 3000); });
    ws.on('message', (d) => log.log('[ring-push] ios relay', r.ext, r.domain, d.toString().slice(0, 300)));
    ws.on('error', (e) => { log.error('[ring-push] ios relay', e.message); finish(0); });
    setTimeout(() => finish(0), 5000);
  });
}

// ---- FreeSWITCH events -------------------------------------------------------

function header(e, name) {
  return e && (typeof e.getHeader === 'function' ? e.getHeader(name) : e[name]);
}

// mod_ringq reads "<devices pushed>/<iPhones pushed>": with an iPhone pushed it waits for the iOS
// app to register even when another device of the extension (web dialer, desk phone) already is.
function answerScript(conn, ringId, pushed, iosPushed) {
  try { conn.api(`hash insert/ringq_ring/${ringId}/${pushed}/${iosPushed}`); } catch (e) { log.error('[ring-push] hash', e.message); }
}

async function onRing(conn, e) {
  const r = {
    id: header(e, 'ringq-ring-id'),
    ext: header(e, 'ringq-ext'),
    domain: header(e, 'ringq-domain'),
    mode: header(e, 'ringq-mode'),
    aUuid: header(e, 'ringq-a-uuid'),
    registered: header(e, 'ringq-registered') === 'true',
    callerNumber: header(e, 'ringq-caller-number'),
    callerName: header(e, 'ringq-caller-name'),
    queue: header(e, 'ringq-queue') || '',
    queueName: header(e, 'ringq-queue-name') || '',
    legs: new Set(),
    legDevices: new Set(),
    answered: false,
    at: Date.now(),
  };
  if (!r.id || !r.ext || !r.domain) return;
  log.log('[ring-push] ring', r.mode, `${r.ext}@${r.domain}`, 'ring', r.id, 'call', r.aUuid || '-',
    'from', r.callerNumber, 'registered', r.registered);
  if (r.mode !== 'wake') return;

  let pushed = 0;
  let iosPushed = 0;
  try {
    if (await blocked(r.ext, r.domain)) {
      log.log('[ring-push] blocked', `${r.ext}@${r.domain}`);
    } else {
      const t = await tokensFor(r.ext, r.domain);
      // an iOS device already on a call gets no new ring (CallKit would put the call on hold)
      const onCall = new Set(await redis.sMembers(`activeCall-${r.ext}@${r.domain}`).catch(() => []));
      // activeCall-* is cleared on hangup by server.js lookups that can miss (e.g. node restarted
      // mid-call), and a stale entry silenced that iPhone for good. Trust it only while the
      // extension really has a call up; otherwise drop it.
      if (onCall.size && !(await extensionOnCall(r.ext, r.domain))) {
        log.log('[ring-push] stale activeCall entries dropped', `${r.ext}@${r.domain}`, onCall.size);
        await redis.del(`activeCall-${r.ext}@${r.domain}`).catch(() => {});
        onCall.clear();
      }
      r.android = t.android;
      // Always push iOS: a registration FreeSWITCH still lists can belong to an app iOS has
      // already suspended, and an INVITE alone would never reach it. The app treats the push and
      // the INVITE for the same ring id as one call.
      r.ios = t.ios.filter((tok) => !onCall.has(tok));
      r.avatar = await avatarFor(r.callerNumber, r.domain);
      if (r.queue && !r.queueName) r.queueName = await queueNameFor(r.queue, r.domain);
      rings.set(r.id, r);
      const [a, i] = await Promise.all([sendAndroid(r, r.id, r.android), sendIos(r, r.id, r.ios)]);
      pushed = a + i;
      // mod_ringq waits for this many mobile app registrations (iOS and Android: a woken app
      // registers on the push), even when another device of the extension is registered
      iosPushed = a + i;
      log.log('[ring-push] pushed', `${r.ext}@${r.domain}`, 'ring', r.id, 'android', a, '/', r.android.length,
        'ios', i, '/', r.ios.length);
    }
  } catch (err) {
    log.error('[ring-push] ring', `${r.ext}@${r.domain}`, err.message);
  }
  answerScript(conn, r.id, pushed, iosPushed);
}

// Android and iOS stop separately: an Android stop is a quiet data message, but iOS must show a
// call for every VoIP push, so an iOS stop is only sent to a phone that never registered for this
// ring. Once it registered it hears about the call over SIP (cancel / answered elsewhere), and a
// stop push would only flash a "RingQ" placeholder call.
async function stopAndroid(r, reason) {
  if (r.androidStopped || !(r.android && r.android.length)) return;
  r.androidStopped = true;
  const a = await sendAndroid(r, reason, r.android);
  log.log('[ring-push] stop', reason, `${r.ext}@${r.domain}`, 'ring', r.id, 'android', a);
}

// The iPhones that never got a device leg for this ring: they rang from the push alone (the app
// registered too late, or never), so no SIP cancel / answered-elsewhere will reach them and only a
// stop push ends their call. Phones that did get a leg are left to SIP (a stop push there would
// flash a "RingQ" placeholder call). Tokens map to the app's device id through the
// <ext>@<domain> redis set ("token:device_uuid"), and the device id to its SIP contact through
// the "RingQ iOS - <device_uuid>" User-Agent of its registration.
// Each RingQ iOS registration's SIP contacts by app device id ("RingQ iOS - <device_uuid>").
async function iosContactsByDevice() {
  const out = new Map();
  if (!eslConn) return out;
  try {
    const res = await new Promise((resolve) => eslConn.api('sofia status profile internal reg', resolve));
    let contact = '';
    for (const line of String((res && res.body) || '').split('\n')) {
      const c = line.match(/^Contact:.*?sip:([^;>\s]+)/);
      if (c) contact = c[1].toLowerCase();
      const a = line.match(/^Agent:\s*RingQ iOS - ([0-9A-Fa-f-]+)/);
      if (a && contact) {
        const device = a[1].toUpperCase();
        if (!out.has(device)) out.set(device, new Set());
        out.get(device).add(contact);
      }
    }
  } catch (e) {
    log.error('[ring-push] reg list', e.message);
  }
  return out;
}

// Which app devices got a leg of this ring, read while the legs are being created: by the time
// the ring ends a phone may already have gone back to sleep and unregistered, and then it looked
// like it never had the call.
function noteLegDevices(r) {
  if (r.legDevicesPending || !(r.ios && r.ios.length)) return;
  r.legDevicesPending = true;
  // the device legs of one ring are created together; give them a moment
  setTimeout(async () => {
    const byDevice = await iosContactsByDevice();
    for (const [device, contacts] of byDevice) {
      for (const c of contacts) if (r.legs.has(c)) r.legDevices.add(device);
    }
  }, 300).unref();
}

// iPhones that were pushed but never got a leg of the call (still waking, or woke too late): a
// stop push ends their call. Phones that did get a leg are left to SIP (a stop push there would
// flash a "RingQ" placeholder call). Tokens map to the app's device ids through the
// <ext>@<domain> redis set ("token:device_uuid"), and a device id to its SIP contacts through the
// "RingQ iOS - <device_uuid>" User-Agent of its registration. One token can be listed with several
// device ids (earlier sign-ins and reinstalls of the same app): the phone got the call if ANY of
// them did. Only reading the last one, a stale id, sent stop pushes to phones that had answered or
// been cancelled by SIP, and iOS showed them as a blank "RingQ" call (apple, 2026-10-19).
async function iosWithoutLeg(r) {
  const devicesOf = new Map();
  const members = await redis.sMembers(`${r.ext}@${r.domain}`).catch(() => []);
  for (const m of members || []) {
    const i = String(m).lastIndexOf(':');
    if (i <= 0) continue;
    const token = String(m).slice(0, i);
    if (!devicesOf.has(token)) devicesOf.set(token, new Set());
    devicesOf.get(token).add(String(m).slice(i + 1).toUpperCase());
  }
  const byDevice = await iosContactsByDevice();
  const gotLeg = (device) =>
    r.legDevices.has(device) || [...(byDevice.get(device) || [])].some((c) => r.legs.has(c));
  return r.ios.filter((tok) => {
    const devices = devicesOf.get(tok);
    if (!devices || !devices.size) return false; // can't tell which phone this is: leave it to SIP, as before
    return ![...devices].some(gotLeg);
  });
}

async function stopIos(r, reason, appReached) {
  if (r.iosStopped || !(r.ios && r.ios.length)) return;
  r.iosStopped = true;
  let tokens = r.ios;
  if (appReached) {
    tokens = eslConn ? await iosWithoutLeg(r) : [];
    if (!tokens.length) {
      log.log('[ring-push] stop', reason, `${r.ext}@${r.domain}`, 'ring', r.id, 'ios: every app got the call, SIP ends it');
      return;
    }
  }
  const i = await sendIos(r, reason, tokens);
  log.log('[ring-push] stop', reason, `${r.ext}@${r.domain}`, 'ring', r.id, 'ios', i, '/', tokens.length);
}

function reasonFor(cause) {
  switch (cause) {
    case 'LOSE_RACE':
    case 'PICKED_OFF':
      return STOP_REASONS.answered;
    case 'USER_BUSY':
    case 'CALL_REJECTED':
      return STOP_REASONS.decline;
    default:
      return STOP_REASONS.missed;
  }
}

// The ring ended (mod_ringq fires ringq::ring_end when its dial finishes). If a phone registered
// for this ring, FreeSWITCH's own CANCEL / answered-elsewhere reaches the app; if the call was
// answered, either this phone took it or SIP tells it. Only a phone that never connected needs the
// iOS stop push (iOS shows a call for every VoIP push).
function endRing(r, reason, registered) {
  r.ended = true;
  stopAndroid(r, reason);
  stopIos(r, reason, !!registered || r.answered);
  rings.delete(r.id);
}

function onRingEnd(e) {
  const r = rings.get(header(e, 'ringq-ring-id'));
  if (!r || r.ended) return;
  if (header(e, 'ringq-answered') === 'true') r.answered = true;
  const reason = r.answered ? STOP_REASONS.answered : reasonFor(header(e, 'ringq-cause'));
  endRing(r, reason, header(e, 'ringq-registered') === 'true');
}

// The device legs mod_ringq dials carry ringq_ring_id.
function onChannel(e) {
  const ringId = header(e, 'variable_ringq_ring_id');
  if (!ringId || !rings.has(ringId)) return;
  const name = header(e, 'Event-Name');
  const r = rings.get(ringId);
  // the device's SIP contact (user@host) from "sofia/internal/<contact>"
  const leg = String(header(e, 'Channel-Name') || '').replace(/^sofia\/[^/]+\//, '').toLowerCase();
  if (leg) r.legs.add(leg);
  if (name === 'CHANNEL_CREATE') {
    noteLegDevices(r);
    return;
  }
  if (name === 'CHANNEL_ANSWER') {
    // answered on one of this extension's devices: the others stop (an app ignores a stop for a
    // call it is already on); iOS waits for ring_end
    r.answered = true;
    r.registered = true;
    stopAndroid(r, STOP_REASONS.answered);
  } else if (name === 'CHANNEL_HANGUP_COMPLETE' && !r.answered) {
    // a device leg existed, so the phone was registered. In a forked dial (simultaneous ring group)
    // mod_ringq sends no ring_end: end the ring from the legs, after a moment for ring_end to win.
    r.registered = true;
    const cause = header(e, 'Hangup-Cause');
    setTimeout(() => { if (!r.ended && !r.answered) endRing(r, reasonFor(cause), true); }, 1500);
  }
}

// Call from server.js once per ESL connection (it reconnects with a new conn).
function attach(conn) {
  eslConn = conn;
  conn.on('esl::ready', () => {
    conn.subscribe(['CUSTOM ringq::ring', 'CUSTOM ringq::ring_end']);
    checkModule(conn);
    if (moduleTimer) clearInterval(moduleTimer);
    moduleTimer = setInterval(() => checkModule(conn), 60 * 1000);
    moduleTimer.unref();
  });
  conn.on('esl::event::CUSTOM::**', (e) => {
    const sub = header(e, 'Event-Subclass');
    if (sub === 'ringq::ring') onRing(conn, e);
    else if (sub === 'ringq::ring_end') onRingEnd(e);
  });
  conn.on('esl::event::CHANNEL_CREATE::**', onChannel);
  conn.on('esl::event::CHANNEL_ANSWER::**', onChannel);
  conn.on('esl::event::CHANNEL_HANGUP_COMPLETE::**', onChannel);
}

module.exports = { init, attach, owns };
