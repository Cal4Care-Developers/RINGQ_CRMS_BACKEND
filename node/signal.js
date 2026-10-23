require('./quietlog'); // [perf] summarise big objects in console.log
require('./servermode')('signal'); // [server-mode] production/debug logging, see servermode.js
const { writeFile, unlinkSync, existsSync } = require("fs");

// [reg-match] a registration belongs to <id> when its Auth-User is <id> or its Contact's SIP user
// is exactly <id>. (Was Contact.includes(id): a short id like "1031" matched any contact that
// merely contains those digits - e.g. port 51031 - and showed the wrong extension as ringing.)
// [tenant-reg] a registration belongs to the tenant in its Auth-Realm (or the domain part of User).
// `sofia status profile internal reg` lists every tenant on the server, so callers filter with this.
function regDomain(r) {
     if (!r) return '';
     const realm = String(r['Auth-Realm'] || '').trim();
     if (realm) return realm.toLowerCase();
     const u = String(r.User || '');
     const at = u.lastIndexOf('@');
     return at >= 0 ? u.slice(at + 1).trim().toLowerCase() : '';
}
function regInDomain(r, domain) {
     if (!domain) return true;
     return regDomain(r) === String(domain).trim().toLowerCase();
}

function regMatch(x, id) {
     if (!x || id === undefined || id === null || id === '') return false;
     const s = String(id);
     if (x['Auth-User'] === s) return true;
     const c = String(x.Contact || '');
     const m = c.match(/sip:([^@;>\s]+)@/);
     if (m && m[1] === s) return true;
     // desk phones label the line '"1004 | Name" <sip:...>' or '"1004" <sip:...>': that number counts too
     const d = c.match(/^\s*"\s*([^"|]*?)\s*[|"]/);
     return Boolean(d && d[1] === s);
}
const esl = require("modesl");
const journey = require("./callJourney"); // [call-journey]
const outboundPin = require("./outboundPin"); // [outbound-pin]
const { EOL } = require("os");
const redis = require("redis");
const { Server } = require("socket.io");
const { io } = require("socket.io-client");
const {  spawn, exec } = require("child_process");
const admin = require("firebase-admin");
const serviceAccount2 = require("./secret.json");
const FileType = require('file-type');
admin.initializeApp({
  credential: admin.credential.cert(serviceAccount2),
});
require("dotenv").config({ path: "/etc/ringq/.env" });

const pg = require("pg");
const { Pool, Client } = pg;
const https = require('https');

const ffmpegStatic = require("ffmpeg-static");
const ffmpeg = require("fluent-ffmpeg");
var cron = require("node-cron");

let redisClient;
let socketClient;

const socketClient3 = new io("ws://127.0.0.1:4070", {
  transports: ["websocket"],
  reconnectionDelayMax: 1000,
  timeout: 2000,
});
socketClient3.connect();

const socketQueueServer = new io("ws://127.0.0.1:4072", {
  transports: ["websocket"],
  reconnectionDelayMax: 1000,
  timeout: 2000,
});
socketQueueServer.connect();

const socketChat = new io("ws://127.0.0.1:5001", {
  transports: ["websocket"],
  reconnectionDelayMax: 1000,
  timeout: 2000,
});
socketChat.connect();

const sshSocketServer = new io("ws://127.0.0.1:3019", {
  transports: ["websocket"],
  reconnectionDelayMax: 1000,
  timeout: 2000,
});
sshSocketServer.connect();

const statusAndroidPush = new io("ws://127.0.0.1:4060", {
  reconnectionDelayMax: 1000,
  timeout: 2000,
});

statusAndroidPush.connect();

// Tell fluent-ffmpeg where it can find FFmpeg
ffmpeg.setFfmpegPath(ffmpegStatic);

const from = "109313205553137"; // '109313205553137';
const token = process.env.whatsappToken;
const fbToken = process.env.facebookToken;

const conn = new esl.Connection("127.0.0.1", 8021, "ClueCon");

const ONLINE_STATUS_DEBOUNCE_MS = 1200;
const ONLINE_STATUS_MIN_INTERVAL_MS = 5000;
const onlineStatusPending = new Map();
const onlineStatusLastRun = new Map();

function scheduleOnlineStatusRefresh(domain) {
  if (!domain || onlineStatusPending.has(domain)) return;
  const sinceLast = Date.now() - (onlineStatusLastRun.get(domain) || 0);
  const wait = Math.max(ONLINE_STATUS_DEBOUNCE_MS, ONLINE_STATUS_MIN_INTERVAL_MS - sinceLast);
  onlineStatusPending.set(
    domain,
    setTimeout(() => {
      onlineStatusPending.delete(domain);
      onlineStatusLastRun.set(domain, Date.now());
      try {
        onlineExtensionStatus(domain);
      } catch (err) {
        console.log("onlineStatus refresh failed", domain, err && err.message);
      }
    }, wait)
  );
}

// [presence-live] A WebSocket client (iOS/Android app going to background, a closed browser tab)
// can drop its registration without any sofia::unregister/expire event, so the live device lists
// kept showing it "Online". Every 20 s compare each domain's set of registrations with the last
// one seen and push a fresh onlineStatus for any domain whose set changed.
const regSnapshot = new Map();
// [my-devices] mobile apps (iOS / Android) drop their registration when they go to the
// background, so remember each one for 24 h: the device lists then show "App closed" with the
// last-seen time instead of the phone vanishing.
const mobileKindOf = (agent) => {
  const a = String(agent || "").toLowerCase();
  if (a.includes("webapp") || a.includes("ringq desktop")) return null;
  if (/iphone|ipad|\bios\b/.test(a)) return "ios";
  if (a.includes("android")) return "android";
  return null;
};
const mobileIdOf = (agent) => {
  const m = / - ([0-9A-Za-z-]{8,})\s*$/.exec(String(agent || ""));
  return (m ? m[1] : String(agent || "")).slice(0, 120);
};
const MOBILE_SEEN_TTL = 24 * 3600;
// [assigned-phones] recent rings per extension (ringq::ring from mod_ringq), so Assigned Phones >
// Stop ringing can send a mobile app the stop push for the call it is ringing for (the app ends a
// call only when the stop carries that call's ring id). Kept 10 minutes, newest last.
const recentRings = new Map(); // "<ext>@<domain>" -> [{ id, at }]
const RECENT_RING_MS = 10 * 60 * 1000;
// [desk-park] a desk phone parked or picked up a call (ringq_desk_park.lua): send the apps the slots
async function deskParkChanged(ev) {
  try {
    const domain = ev.getHeader("domain");
    if (!domain) return;
    const arrStatus = await redisClient.lRange(`${domain}:parked`, 0, -1);
    wallboard.to(domain).emit("parkStatus", arrStatus); deskParkLamps(domain); // [desk-park]
  } catch (e) { /* ignore */ }
}
// [desk-park] the apps changed the slots: light / clear the desk phones' park lamps
function deskParkLamps(domain) {
  try {
    if (domain && /^[A-Za-z0-9._-]+$/.test(String(domain))) conn.api(`luarun ringq_desk_park.lua lamps ${domain}`);
  } catch (e) { /* ignore */ }
}

function rememberRing(ev) {
  const id = String(ev.getHeader("ringq-ring-id") || "");
  const ext = String(ev.getHeader("ringq-ext") || "");
  const domain = String(ev.getHeader("ringq-domain") || "");
  if (!id || !ext || !domain) return;
  const key = `${ext}@${domain}`;
  const cutoff = Date.now() - RECENT_RING_MS;
  const list = (recentRings.get(key) || []).filter((r) => r.at > cutoff && r.id !== id);
  list.push({ id, at: Date.now() });
  recentRings.set(key, list.slice(-5));
}
setInterval(() => {
  const cutoff = Date.now() - RECENT_RING_MS;
  for (const [k, list] of recentRings) {
    const keep = list.filter((r) => r.at > cutoff);
    if (keep.length) recentRings.set(k, keep); else recentRings.delete(k);
  }
}, 60000).unref();

// [assigned-phones] stop pushes go through the same Firebase project / APNs key as ringPush.js
let assignedFcm = null;
function assignedPhonesFcm() {
  if (assignedFcm) return assignedFcm;
  const keyPath = "/etc/ringq/fcm/service-account.json";
  try {
    if (require("fs").existsSync(keyPath)) {
      const key = JSON.parse(require("fs").readFileSync(keyPath, "utf8"));
      const app = admin.apps.find((a) => a && a.name === "ringq-push-assigned")
        || admin.initializeApp({ credential: admin.credential.cert(key) }, "ringq-push-assigned");
      assignedFcm = { messaging: () => app.messaging() };
      return assignedFcm;
    }
  } catch (e) { console.log("[assigned-phones] fcm key", e && e.message); }
  assignedFcm = admin;
  return assignedFcm;
}
let assignedApns = null;
function assignedPhonesApns() {
  if (assignedApns !== null) return assignedApns;
  try {
    const apns = require("./apns");
    assignedApns = apns.init({ redis: redisClient }) && apns.enabled() ? apns : false;
  } catch (e) { assignedApns = false; }
  return assignedApns;
}
async function rememberMobileRegs(regs) {
  const now = Date.now();
  for (const r of regs || []) {
    const kind = r && mobileKindOf(r.Agent);
    if (!kind || !r["Auth-User"] || !r["Auth-Realm"]) continue;
    const key = `mydevMobile-${r["Auth-Realm"]}:${r["Auth-User"]}`;
    await redisClient.hSet(key, mobileIdOf(r.Agent), JSON.stringify({ kind, agent: String(r.Agent || ""), ip: String(r.IP || ""), lastSeen: now }));
    await redisClient.expire(key, MOBILE_SEEN_TTL + 3600);
  }
}
// [moh] give every extension of a domain with uploaded music on hold that music as its
// hold_music (directory variable), so whoever an extension puts on hold -- direct calls, queue
// calls (the agent leg is originated by mod_callcenter and never runs the dialplan), transfers --
// hears the domain's own music instead of the default. Only touches extensions with no hold
// music of their own (empty or the default stream); reverts when the domain's files are removed.
async function syncHoldMusic() {
  try {
    const { rows } = await db2.query(`SELECT domain_uuid, domain_name FROM v_domains WHERE coalesce(domain_enabled, 'true') = 'true'`);
    for (const d of rows) {
      const want = mohStream(d.domain_name);
      const own = `local_stream://${d.domain_name}/moh`;
      let res;
      if (want === own) {
        res = await db2.query(
          `UPDATE v_extensions SET hold_music = $2 WHERE domain_uuid = $1
             AND (hold_music IS NULL OR hold_music = '' OR hold_music = 'local_stream://default') RETURNING extension, number_alias`,
          [d.domain_uuid, own]);
      } else {
        res = await db2.query(
          `UPDATE v_extensions SET hold_music = NULL WHERE domain_uuid = $1 AND hold_music = $2 RETURNING extension, number_alias`,
          [d.domain_uuid, own]);
      }
      for (const r of res.rows) {
        for (const u of [r.extension, r.number_alias]) {
          if (u) { try { unlinkSync(`/var/cache/ringq/directory.${u}@${d.domain_name}`); } catch (e) {} }
        }
      }
      if (res.rowCount) console.log("[moh]", d.domain_name, want === own ? "custom hold music on" : "back to default", res.rowCount, "extension(s)");
    }
  } catch (e) { console.log("[moh] sync", e && e.message); }
}
setTimeout(syncHoldMusic, 20000);
setInterval(syncHoldMusic, 120000);

// [devices] seed once at start: devices already switched to "Calls off" before muting existed
setTimeout(async () => {
  try {
    const { rows } = await db2.query(
      `SELECT q.extension, q.push_token, d.domain_name FROM q_devices q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
        WHERE q.enabled = 0 AND coalesce(q.push_token, '') <> ''`);
    for (const r of rows) {
      const base = `${r.extension}@${r.domain_name}`;
      const ids = [r.push_token];
      const members = await redisClient.sMembers(base).catch(() => []);
      for (const m of members || []) if (String(m).startsWith(r.push_token + ":")) ids.push(String(m).slice(r.push_token.length + 1));
      await redisClient.sAdd(`devMuted-${base}`, ids);
      await redisClient.hSet(`devMutedIds-${base}`, r.push_token, ids.join(","));
    }
    if (rows.length) console.log("[devices] seeded calls-off for", rows.length, "device(s)");
  } catch (e) { console.log("[devices] seed", e && e.message); }
}, 15000);

// [devices] Calls are on by default. "Calls off" is stored on the device's q_devices row, so it
// sticks while that row exists. A row that comes back with calls on is a sign-in (the app signed
// in again, or the phone was signed in after being deleted here): it starts with calls on, so the
// old mute is dropped instead of switching the new sign-in off again.
setInterval(async () => {
  try {
    const { rows } = await db2.query(
      `SELECT q.id, q.extension, q.push_token, d.domain_name FROM q_devices q
         JOIN v_domains d ON d.domain_uuid = q.domain_uuid
        WHERE coalesce(q.enabled, 1) <> 0 AND coalesce(q.push_token, '') <> ''`);
    for (const r of rows) {
      const base = `${r.extension}@${r.domain_name}`;
      const muted = await redisClient.sIsMember(`devMuted-${base}`, r.push_token).catch(() => false);
      if (!muted) continue;
      const known = await redisClient.hGet(`devMutedIds-${base}`, r.push_token).catch(() => null);
      const ids = [...new Set([r.push_token, ...String(known || "").split(",").filter(Boolean)])];
      await redisClient.sRem(`devMuted-${base}`, ids);
      await redisClient.hDel(`devMutedIds-${base}`, r.push_token);
      await redisClient.hDel(`devRemoved-${base}`, r.push_token);
      console.log("[devices] signed in again: calls on by default", base, r.id);
    }
  } catch (e) { /* next round */ }
}, 30000);

setInterval(async () => {
  try {
    const regs = (await fsRegistration()) || [];
    rememberMobileRegs(regs).catch(() => {});
    const byDomain = new Map();
    for (const r of regs) {
      const d = r && r["Auth-Realm"];
      if (!d || !r["Call-ID"]) continue;
      if (!byDomain.has(d)) byDomain.set(d, []);
      byDomain.get(d).push(r["Call-ID"]);
    }
    const domains = new Set([...byDomain.keys(), ...regSnapshot.keys()]);
    for (const d of domains) {
      const sig = (byDomain.get(d) || []).sort().join("|");
      if (regSnapshot.has(d) && regSnapshot.get(d) !== sig) scheduleOnlineStatusRefresh(d);
      if (sig) regSnapshot.set(d, sig); else regSnapshot.delete(d);
    }
  } catch (e) { /* next round */ }
}, 20000);

conn
  .on("esl::ready", function (res) {
    conn.events("json", "CHANNEL_BRIDGE CUSTOM sofia::register sofia::unregister sofia::expire ringq::ring ringq::park"); /* [desk-park] */ // [presence-live] expire too; [moh] bridge
  })
  .on("esl::event::**", function (ev) {
    if (!ev || typeof ev.getHeader !== "function") return;
    if (ev.getHeader("Event-Name") === "CHANNEL_BRIDGE") { applyMohRotation(ev).catch(() => {}); return; }
    if (ev.getHeader("Event-Name") !== "CUSTOM") return;
    const subclass = ev.getHeader("Event-Subclass");
    if (subclass === "ringq::ring") { rememberRing(ev); return; } // [assigned-phones]
    if (subclass === "ringq::park") { deskParkChanged(ev); return; } // [desk-park]
    if (subclass !== "sofia::register" && subclass !== "sofia::unregister" && subclass !== "sofia::expire") return;
    scheduleOnlineStatusRefresh(ev.getHeader("from-host") || ev.getHeader("host") || ev.getHeader("realm"));
  })
  .on("error", function () {
    
  });

// [moh] the domain's own music on hold (Global Settings > Music on hold) when it has uploaded
// files, else the default stream. Hard-coded local_stream://default / local_stream://moh meant
// custom hold music never played on park / transfer waits.
const _mohCache = new Map();
function mohStream(domain) {
  const d = String(domain || "");
  if (!/^[a-z0-9.-]+$/i.test(d)) return "local_stream://default";
  const c = _mohCache.get(d);
  if (c && Date.now() - c.t < 60000) return c.v;
  let v = "local_stream://default";
  try {
    if (require("fs").readdirSync(`/usr/share/freeswitch/sounds/music/${d}/8000`).some((f) => /\.(wav|mp3)$/i.test(f))) v = `local_stream://${d}/moh`;
  } catch (e) { /* no custom music */ }
  _mohCache.set(d, { v, t: Date.now() });
  return v;
}

// [moh] "Randomized playback" (Global Settings > Music on hold): a domain's music normally plays as
// one continuous stream that every held caller joins mid-track. With it on, each bridged call gets
// its own track from the start -- the next file in turn per call, or one file per day -- by
// setting hold_music on both legs to that file (hold plays it in a loop).
const MOH_ROOT = "/usr/share/freeswitch/sounds/music";
function mohFiles(domain) {
  for (const rate of ["16000", "8000", "32000", "48000"]) {
    try {
      const files = require("fs").readdirSync(`${MOH_ROOT}/${domain}/${rate}`).filter((f) => /\.(wav|mp3)$/i.test(f)).sort();
      if (files.length) return files.map((f) => `${MOH_ROOT}/${domain}/${rate}/${f}`);
    } catch (e) { /* try next rate */ }
  }
  return [];
}
async function getMohMode(domain) {
  try { const v = JSON.parse((await redisClient.get(`mohMode-${domain}`)) || "{}"); return { enabled: !!v.enabled, type: v.type === "day" ? "day" : "call" }; }
  catch (e) { return { enabled: false, type: "call" }; }
}
async function applyMohRotation(ev) {
  const domain = String(ev.getHeader("variable_domain_name") || "");
  if (!/^[a-z0-9.-]+$/i.test(domain)) return;
  const mode = await getMohMode(domain);
  if (!mode.enabled) return;
  const files = mohFiles(domain);
  if (files.length < 2) return;
  let idx;
  if (mode.type === "day") {
    const now = new Date();
    idx = Math.floor((Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())) / 86400000) % files.length;
  } else {
    idx = ((await redisClient.incr(`mohRR-${domain}`)) - 1) % files.length;
  }
  const file = files[idx];
  for (const u of [ev.getHeader("Bridge-A-Unique-ID"), ev.getHeader("Bridge-B-Unique-ID")]) {
    if (u && /^[0-9a-f-]{36}$/i.test(u)) conn.api(`uuid_setvar ${u} hold_music ${file}`);
  }
}

const db2 = new Pool({
  user: process.env.PostgreSQL_DB_USER,
  host: process.env.PostgreSQL_DB_HOST,
  database: process.env.PostgreSQL_DB_NAME,
  password: process.env.PostgreSQL_DB_PASSWORD,
  port: Number(process.env.PostgreSQL_DB_PORT),
});

db2.connect((err, client, release) => {
  if (err) {
    console.error("Error acquiring client", err.stack);
    return;
  } else {
    
  }
});

(async () => {
  redisClient = redis.createClient({
    socket: {
      path: "/var/run/redis/redis-server.sock",
    },
  });
  redisClient.on("error", (error) => console.error(`Error : ${error}`));
  await redisClient.connect();
})();
const wallboard = new Server({
  maxHttpBufferSize: 50e6, // 50mb
  pingInterval: 25000,
  pingTimeout: 25000,
});


const extensionRules = [
  { min: 9000, max: 9500, type: 'Queue' },
  { min: 3000, max: 3100, type: 'SpecialIVR' },
  { min: 5555, max: 5600, type: 'AI' },
  { min: 7777, max: 7780, type: 'ExternalAI' },
  { min: 6000, max: 6500, type: 'IVR' },
  { min: 2000, max: 2500, type: 'Teams' },
  { min: 4000, max: 4020, type: 'Conference' },
  { min: 10000, max: Infinity, type: 'ExternalNumber' },
];

// cron.schedule('*/10 * * * * *', async () => {
//   console.log("Checking Redis for active chats...");
  
//   try {
//     const matchingKeys = [];

//     // FIX 1: Change to case-sensitive '*activeChats' to match your Redis CLI
//     for await (const key of redisClient.scanIterator({
//       MATCH: '*activeChats', 
//       COUNT: 100 
//     })) {
//       matchingKeys.push(key);
//     }

//     console.log(`Found ${matchingKeys.length} matching keys.`);

//     for (const key of matchingKeys) {
//       // FIX 2: Ensure sMembers matches the updated key
//       const chatElements = await redisClient.sMembers(key);
//       console.log(`Key: ${key} ->`, chatElements);

//       // Derive the subdomain from the key (e.g. "subdomain-activeChats" -> "subdomain")
//       const subdomain = key.replace(/-?activeChats$/, "");
//       console.log(`Subdomain: ${subdomain}`);

//       // Emit the active chats for this subdomain every 10 seconds
//       // socketSignal.emit(
//       //   "chatParameters",
//       //   "logout",
//       //   { subdomain, key, chats: chatElements, count: chatElements.length },
//       //   mysocket,
//       // );
//         const payload = {
//           matchingKeys,
//         };
//       wallboard.to(socketId).emit("logout", payload);
//     }

//   } catch (error) {
//     console.error("Error processing active chats cron:", error);
//   }
// });

wallboard
  .use(async (socket, next) => {
    if (
      socket.handshake.headers["user-agent"] == "node-XMLHttpRequest" ||
      socket.handshake.headers["host"] == "127.0.0.1:3000"
    ) {
      next();
    } else {
      var value = await redisClient.sMembers(
        "wallboard:" + socket.handshake.auth.channel
      );
      if (value.indexOf(socket.handshake.auth.token) > -1) {
        socket.data.presenceAuthorised = true;
        // conn.api('reloadxml');
        // console.log("<!-- SOCKET CONNECT", socket.handshake.auth, `sokcetID => ${socket.id}`);
        teamDirectoryEvent(socket.handshake.auth.channel)
        next();
      } else {
        next();
        // next(new Error('Socket connection not permitted.'));
      }
    }
  })
  .on("connection", (socket) => {

    emitPresenceSnapshot(socket);

    // EMAN ADDED 06-05-25 START //
    wallboard.sockets.once("connection", async (socket) => {

      if (socket.handshake.auth.pushToken != "") {
        wallboard.to(socket.handshake.auth.pushToken).emit("command", "-1");
        socket.join(socket.handshake.auth.pushToken);
      }

      socket.join(socket.handshake.auth.channel);

      if (socket.handshake.auth.extension) {
        socket.join(socket.handshake.auth.extension + "@" + socket.handshake.auth.channel);
      }

      // [ext-access] a mobile app (push token up to 64 chars) reconnecting while its Mobile App
      // permission is off is told to sign out again
      try {
        const a = socket.handshake.auth || {};
        const pt = String(a.pushToken || "");
        if (a.extension && a.channel && pt && pt.length <= 64 && (await redisClient.exists(`disableapp:${a.extension}:${a.channel}`))) {
          socket.emit("status", { id: "", extension: String(a.extension), device: pt, action: 3 });
          console.log("[ext-access] mobile app sign-out on reconnect", `${a.extension}@${a.channel}`);
        }
      } catch (e) { /* best effort */ }

      console.log("New socket connection:", socket.id, socket.handshake.auth);
      if (socket.handshake.auth.hasOwnProperty("extension")) {

        if(!socket.handshake.auth) return;

        const {channel, extension, agent ,deviceId, device_uuid, pushToken} = socket.handshake.auth

        console.log("🔋<!-- SOCKET CONNECT🔋", socket.handshake.auth);

        if (agent === 'webapp') {

          // const existId = await redisClient.get(`sock-${extension}@${channel}`);
          // console.log("KS🔋", existId, pushToken);
          // if(existId === pushToken) return

          await new Promise((r) => setTimeout(r, 2000));
          const { rows } = await db2.query(
            "SELECT number_alias FROM v_extensions WHERE extension = $1 AND accountcode = $2",
            [extension, channel]
          );
          if(!rows.length) return;
          const extensionAlias = rows[0].number_alias;          
          const reg = await fsRegistration();
          console.log("this is reg", reg);
          const filterWeb = reg.filter(el => String(el['Agent'] || '').includes('Webapp') && (el['Auth-User'] === extensionAlias && el['Auth-Realm'] === channel));
          const testR = filterWeb.find(el => String(el['Agent'] || '').includes(deviceId));
          console.log(`🔋🔋🔋🔋`, extension, deviceId, testR);
          const tokenExist = await redisClient.get(`sock-${pushToken}@${channel}`);
          
          if(testR && !tokenExist){
            if(filterWeb.length > 1){
                const limitExt = await redisClient.get(`limitExtension-${extension}@${channel}`);
                const existingSocket = wallboard.sockets.sockets.get(limitExt);
                if (existingSocket){
                  console.log("🔋TEST", `${extension}@${channel}`, limitExt, socket.id, socket.handshake.auth);
                  wallboard.to(limitExt).emit("webapplimit", 1);
                  conn.api(`sofia profile internal flush_inbound_reg ${filterWeb[0]['Call-ID']}`);
                }
            }
            await redisClient.set(`limitExtension-${extension}@${channel}`, socket.id);
            await redisClient.set(`sock-${pushToken}@${channel}`, 1, {EX:10});
          }
          
          // if(filterWeb.length > 1){
          //   const limitExt = await redisClient.get(`limitExtension-${extension}@${channel}`);
          //   if(limitExt && limitExt !== socket.id){
          //     wallboard.to(limitExt).emit("webapplimit", 1);
          //   }
          // }
          // await redisClient.set(`sock-${extension}@${channel}`, pushToken);
          // await redisClient.set(`limitExtension-${extension}@${channel}`, socket.id);
          // console.log("🔋TEST", pushToken);
          // const limitExt = await redisClient.get(`limitExtension-${extension}@${channel}`);
          // if (limitExt && limitExt !== socket.id){
          //   const existingSocket = wallboard.sockets.sockets.get(limitExt);
          //   if (existingSocket){
          //     console.log("🔋TEST", `${extension}@${channel}`, limitExt, socket.id, socket.handshake.auth);
          //     wallboard.to(limitExt).emit("webapplimit", {socketId: socket.id, deviceID: deviceId});
          //   }
          // }
        }

        /** Online Call Status */
          onlineExtensionStatus(socket.handshake.auth.channel);
          
        /** End */

        const deviceOriginaDevicelId = deviceId || device_uuid

        if(pushToken && deviceOriginaDevicelId){

          const presenceExtension = `${extension}@${channel}`
          await redisClient.set(`deviceOrigId-${pushToken}`, deviceOriginaDevicelId);
          await redisClient.set(`devicePushtoken-${device_uuid}`, pushToken);
          await redisClient.set(`${pushToken}`, 1, {EX: 864000});

          

          if (pushToken.length === 64) {
            const callId = await redisClient.get(`${channel}${extension}-RingQ`);
            conn.api(`uuid_exists ${callId}`, async ({ body }) => {
                console.log(`❄️BODY❄️`, body);
                if(body !== 'true'){
                  await redisClient.sRem(`iosDevicePushToken-${presenceExtension}`, pushToken);
                  await redisClient.set(`deviceIdIOS-${device_uuid}`, pushToken);
                }
            });
          }
        }

        if (socket.handshake.auth.extension && socket.handshake.auth.channel) {

          const timestamp = Math.trunc(Date.now() / 1000);
          await db2.query(
            "UPDATE agent_details SET status = $1, last_status_change = $2 WHERE contact LIKE $3",
            [
              "Available",
              timestamp,
              `%${socket.handshake.auth.extension}@${socket.handshake.auth.channel}%`,
            ]
          );

          const agentNameId = await db2.query(
            "SELECT name FROM agent_details WHERE contact LIKE $1",
            [
              `%${socket.handshake.auth.extension}@${socket.handshake.auth.channel}%`,
            ]
          );

          if (agentNameId.rows.length > 0) {

            agentNameId.rows.forEach( async (agent) => {
              conn.api(
                `callcenter_config agent set status ${agent.name} Available`
              );
              wallboard
              .to(socket.handshake.auth.channel)
              .emit("status", {
                id: "",
                agent: agent.name,
                status: "Available",
                auxcode: "Available",
                queue_extension: socket.handshake.auth.extension,
              });
            })

            conn.api(`callcenter_config agent set status ${agentNameId.rows[0]?.name} Available`);

            wallboard
              .to(socket.handshake.auth.channel)
              .emit("status", {
                id: "",
                agent: agentNameId.rows[0]?.name,
                status: "Available",
                auxcode: "Available",
                queue_extension: socket.handshake.auth.extension,
              });
          }

        }

        let callprofile = await redisClient.get(
          `${socket.handshake.auth.extension}+${socket.handshake.auth.channel}`
        );
        if (callprofile == null) {
          callprofile = 0;
        }
        wallboard
          .to(socket.handshake.auth.channel)
          .emit("profile", {
            id: "",
            extension: socket.handshake.auth.extension,
            status: callprofile,
            color: "0xFF00FF38",
          });
        deviceWebToken(socket.handshake.auth);
        deviceUniqueId(socket.handshake.auth);
        extensionParameters(
          socket.handshake.auth.extension,
          socket.handshake.auth.channel
        );

        await redisClient.set(
          "socketId:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension,
          socket.id
        );

        console.log(`👇🏻 WALL STATUS`, "wallstatus:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension,
          1)

        await redisClient.set(
          "wallstatus:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension,
          1
        );

        const aliasNameExtension = async () => {
            const { rows } = await db2.query(
                  `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                  [socket.handshake.auth.channel, socket.handshake.auth.extension]
            );
            if(rows.length){
              const extensionOrigName = rows[0]?.number_alias;
              return extensionOrigName
            }
            return extension
        }

        const value = await getRegistrationInternalReg();
        const destinationAlias = await aliasNameExtension();
        const filterPresence = value.filter(data => data['Auth-User'] === destinationAlias);
        
        if(!filterPresence.length){
          wallboard
          .to(socket.handshake.auth.channel)
          .emit("status", {
            id: socket.id,
            extension: socket.handshake.auth.extension,
            status: 1,
            auxcode: "Available",
          });
        }


        await redisClient.set(
          "presenceId:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension +
            ":" +
            socket.handshake.auth.pushToken,
          "1"
        );

        if (socket.conn.transport.name === "websocket") {
          redisClient.del(
            `confimrPush-${
              socket.handshake.auth.channel +
              ":" +
              socket.handshake.auth.extension +
              ":" +
              socket.handshake.auth.pushToken
            }`
          );
        }

        if (socket.handshake.auth.agentuuid) {
          db2.query(
            "UPDATE agents SET last_status_change = '" +
              Math.trunc(Date.now() / 1000) +
              "' WHERE name = '" +
              socket.handshake.auth.agentuuid +
              "'",
            async (err, rows) => {}
          );
          // conn.api("callcenter_config agent set status " + socket.handshake.auth.agentuuid + " 'Available'");
          await db2.query(
            "UPDATE v_call_center_agents SET agent_status = 'Available' WHERE agent_contact = 'user/" +
              socket.handshake.auth.extension +
              "@" +
              socket.handshake.auth.channel +
              "' AND queue_extension IS NULL"
          );
        }
        /** Block Call Status */
          blockCallStatus(socket.handshake.auth.channel);
        /** End */
      }
    });
    // EMAN ADDED 06-05-25 END //

    socket.once("initialize", async (arg, callback) => {
      socket.join(arg);
    });

    socket.on("sso-initiate2", async (payload, callback) => {
      
      wallboard
        .to(payload["socket"])
        .emit("sso2", { forgot: payload["forgot"], uuid: payload["uuid"] });
    });

    socket.on('rebootSofiaStatus', async (uid) => {

      const mac = uid['callId'];
      const macNoColon = mac.replace(/:/g, "");

      function getRegistration() {
          return new Promise((resolve, reject) => {
                conn.api(`sofia status profile internal`, (reg) => {
                    try {
                          function parseLog(log) {
                              const blocks = log.trim().split(/\n\s*\n/); // split by empty line
                              const registrations = [];
                              let total = 0;

                              for (const block of blocks) {
                              if (block.startsWith("Total items")) {
                                    total = parseInt(block.replace(/\D+/g, ""), 10);
                                    continue;
                              }

                              const entry = {};
                              block.split("\n").forEach(line => {
                                    const match = line.match(/^(.*?):\s*\t?(.*)$/);
                                    if (match) {
                                        const key = match[1].trim();
                                        const value = match[2].trim();
                                        entry[key] = value;
                                    }
                              });
                              registrations.push(entry);
                              }

                              return { Registrations: registrations, Total: total };
                          }

                          const result = parseLog(reg.body);
                          // console.log(`SOFIA REG2`, result);

                          const match = result.Registrations.find(
                              (x) => x.Agent.includes(mac) ||
                              x.Agent.includes(macNoColon)
                          );

                          if (match) {
                                resolve(match);
                          } else {
                                resolve(null);
                          }

                    } catch (err) {
                          reject(err);
                    }
                });
          });
      }
      const value = await getRegistration();
      console.log('This is reboot sofia status', value['Call-ID'], uid['callId']);
      conn.api(`sofia profile internal flush_inbound_reg ${value['Call-ID']} reboot`);
    });


    socket.on("agentprofile", async (arg, callback) => {
      wallboard
        .to(arg["subdomain"])
        .emit("profile", {
          id: "",
          extension: arg["extension"],
          status: arg["status"],
          color: arg["color"],
        });
    });

    socket.on("gatewaystatuspush", async (arg) => {
      if (!arg || !arg["subdomain"]) return;
      console.log("gatewayStatus??");
      wallboard.to(arg["subdomain"]).emit("gatewaystatus", arg["gateways"] || []);
    });

    socket.on("agentstatus", async (arg, callback) => {

      console.log(`👇🏻 Agent Status`, arg);
      const timestamp = Math.trunc(Date.now() / 1000);
      new Promise ((resolve, reject) => {
        try {
          if (arg.status === "Available" || arg.status === "On Break") {
            const res = conn.api(`callcenter_config agent set status ${arg.agentuuid} '${arg.status}'`);
            resolve(res);
          } else {
            reject(new Error(`Unknown status: ${arg.status}`));
          }
        } catch (err) {
          reject(err);
        }
      }).then( async (res) => {

            const [agentBody, tierBody] = await Promise.all([
                new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
                new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
            ]);

            const agentList = JSON.parse(agentBody.body).response;
            const tierList  = JSON.parse(tierBody.body).response;
            const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

            const matched = tierList.reduce((acc, tier) => {
                const agent = agentMap[tier.agent];
                if (
                    agent && 
                    agent.contact.includes(arg.subdomain)
                ) acc.push(agent);
                return acc;
            }, []);
            console.log("✅ matched:", matched);
            
            if(matched.length){

                const names = matched.map(e => e.name);
                const { rows } = await db2.query(
                    `SELECT 
                        ad.name,
                        ad.contact,
                        ad.max_no_answer,
                        ad.wrap_up_time,
                        ad.reject_delay_time,
                        ad.busy_delay_time,
                        ad.no_answer_delay_time,
                        ad.last_bridge_start,
                        ad.last_bridge_end,
                        ad.last_offered_call,
                        ad.no_answer_count,
                        ad.calls_answered, 
                        ad.talk_time,
                        ad.ready_time,
                        ad.external_calls_count,
                        ve.extension
                    FROM agent_details ad
                    LEFT JOIN v_extensions ve 
                        ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                    WHERE ad.name = ANY($1)`,
                    [names]
                );

                // Map results by name for O(1) lookup
                const dbMap = rows.reduce((acc, row) => {
                    acc[row.name] = row;
                    return acc;
                }, {});

                const newPayload = matched.map(element => {
                    const data = dbMap[element.name];
                    if(data){
                      const { 
                        contact,
                        max_no_answer,
                        wrap_up_time,
                        reject_delay_time,
                        busy_delay_time,
                        no_answer_delay_time,
                        last_bridge_start,
                        last_bridge_end,
                        last_offered_call,
                        no_answer_count,
                        calls_answered, 
                        talk_time,
                        ready_time,
                        external_calls_count,
                        extension 
                      } = data;
                      const newContact = extension ? updateContact(contact, extension) : contact;
                      return {
                          ...element,
                          contact: newContact,
                          max_no_answer: Number(max_no_answer),
                          wrap_up_time: Number(wrap_up_time),
                          reject_delay_time: Number(reject_delay_time),
                          busy_delay_time: Number(busy_delay_time),
                          no_answer_delay_time: Number(no_answer_delay_time),
                          last_bridge_start: Number(last_bridge_start),
                          last_bridge_end: Number(last_bridge_end),
                          last_offered_call: Number(last_offered_call),
                          no_answer_count: Number(no_answer_count),
                          calls_answered: Number(calls_answered),
                          talk_time: Number(talk_time),
                          ready_time: Number(ready_time),
                          external_calls_count: Number(external_calls_count)
                      };
                    }
                });
                
                const filteredAgent = newPayload.filter(Boolean);
                console.log('👉🏻👉🏻', JSON.stringify(filteredAgent));
                await redisClient.set(`${arg.subdomain}:queueWallboardAgentBoard2`, JSON.stringify(filteredAgent));
                wallboard.to(arg.subdomain).emit("queueAgentboard2", JSON.stringify(filteredAgent));
                wallboard.to(arg["subdomain"]).emit("status", {
                  id: "",
                  agent: arg["agentuuid"],
                  status: arg["status"],
                  auxcode: arg["auxcode"],
                  queue_extension: arg["queue_extension"],
                });
              
            }

      }).catch((err) => {
        console.error('CHANNEL AGENTSTATUS', err)
      }).finally(() => {
        wallboard.to(arg["subdomain"]).emit("agentstatus", arg);
      });
     
    });

    socket.on("banIp", async (arg, callback) => {
      wallboard
        .to(arg["subdomain"])
        .emit("status", { id: "", banIp: arg["blockIps"] });
    });

    socket.on("banUuid", async (arg, callback) => {
      
      wallboard
        .to(socket.handshake.auth.channel)
        .emit("status", { id: "", banUuid: arg });
    });

    // [one-mobile] PHP (QR sign-in from a mobile app): the extension is now on a new phone. Every
    // app in the extension's room re-checks its sign-in; the replaced phone's is refused, so it
    // signs itself out (the new phone's, and web / desktop sign-ins, are unaffected).
    socket.on("mobileReplaced", async (payload) => {
      if (!payload || !payload.extension || !payload.subdomain) return;
      wallboard.to(`${payload.extension}@${payload.subdomain}`).emit("mobileReplaced", { extension: String(payload.extension) });
      console.log("[one-mobile] new phone signed in", `${payload.extension}@${payload.subdomain}`);
    });

    socket.on("qrLogin-initiate", async (payload, callback) => {
      
      wallboard.to(payload["subdomain"]).emit("qrLogin", payload["accessCode"]);
    });

    socket.on("autoBroadcast", async (arg, callback) => {

      console.log('AutoBroadcast', arg);

      if(arg["customer_first"] == false){

        const callerNumber = arg["origination_caller_id_number"].includes('@') ? arg["origination_caller_id_number"].split('@')[0] : arg["origination_caller_id_number"];
        conn.bgapi(`originate {origination_caller_id_name=${arg["origination_caller_id_name"]},sip_from_uri=${arg["sip_from_uri"]},origination_caller_id_number=${callerNumber},ignore_early_media=true,domain_uuid=${arg["domain_uuid"]},gateway=${arg["gatewau_uuid"]},outbound_destination=${arg["origination_number"]},call_method=directdial,agent_first=true}loopback/${arg["action"]}/${arg["domain_name"]}/XML &park`);

      }else{

        if (arg["call_type"] == "internal") {
          conn.api(
            "bgapi originate {ignore_early_media=true,origination_number=" +
              arg["origination_number"] +
              ",origination_caller_id_name=" +
              arg["origination_caller_id_name"] +
              ",origination_caller_id_number=" +
              arg["origination_caller_id_number"] +
              ",domain_uuid=" +
              arg["domain_uuid"] +
              ",domain_name=" +
              arg["domain_name"] +
              ",accountcode=" +
              arg["domain_name"] +
              "call_method=directdial" +
              "customer_first=true" +
              "}loopback/" +
              arg["origination_number"] +
              "/" +
              arg["domain_name"] +
              " " +
              arg["action"] +
              " XML " +
              arg["domain_name"] +
              ""
          );
        } else {

          const callerNumber = arg["origination_caller_id_number"].includes('@') ? arg["origination_caller_id_number"].split('@')[0] : arg["origination_caller_id_number"];
          console.log(
            "originate {sip_from_uri=" +
              arg["sip_from_uri"] +
              ",sip_cid_type=rpid,origination_caller_id_name=" +
              arg["origination_caller_id_name"] +
              ",origination_caller_id_number=" +
              callerNumber +
              ",origination_privacy=screen+hide_name+hide_number,ignore_early_media=true,call_timeout=30,hangup_after_bridge=true,domain_uuid=" +
              arg["domain_uuid"] +
              ",domain_name=" +
              arg["domain_name"] +
              ",accountcode=" +
              arg["domain_name"] +
              "}sofia/gateway/" +
              arg["gatewau_uuid"] +
              "/" +
              arg["origination_number"] +
              " &sleep(10000)" +
              arg["action"] +
              " XML " +
              arg["domain_name"] +
              " "
          );
          conn.bgapi(
            "originate {sip_from_uri=" +
              arg["sip_from_uri"] +
              ",sip_cid_type=rpid,origination_caller_id_name=" +
              arg["origination_caller_id_name"] +
              ",origination_caller_id_number=" +
              callerNumber +
              ",origination_privacy=screen+hide_name+hide_number,ignore_early_media=true,hangup_after_bridge=true,domain_uuid=" +
              arg["domain_uuid"] +
              ",domain_name=" +
              arg["domain_name"] +
              ",accountcode=" +
              arg["domain_name"] +
              ",call_method=directdial" +
              ",customer_first=true" +
              ",call_timeout=20" +                          
              ",originate_timeout=20" +
              "}sofia/gateway/" +
              arg["gatewau_uuid"] +
              "/" +
              arg["origination_number"] +
              " " +
              arg["action"] +
              " XML " +
              arg["domain_name"] +
              ' "" "" 20'
          );
          
        }

      }
     
    });

    socket.on("autoBroadcast2", async (arg, callback) => {
      wallboard.to(arg["domain_name"]).emit("autoBroadcast", 1);
    });

    // [ext-access] PHP (AccountsController, extension save) -> make Enable / Mobile App / Webapp /
    // Call Control take effect now instead of at the next sign-in.
    socket.on("extensionAccess", async (arg) => {
      try { await applyExtensionAccess(arg || {}); } catch (e) { console.log("[ext-access]", e && e.message); }
    });

    socket.on("deviceOut", async (arg, callback) => {
      
      if(!arg || !arg.extension){
        console.log("device out")
        return
      }
      // [devices] make "Calls off" and Delete stick. The push senders (server.js iOS, status.js
      // Android) skip anything in devMuted-<ext>@<domain>; a deleted device is muted too, so if a
      // still-signed-in app re-creates its entry it comes back with calls off until someone
      // switches calls on again.
      try {
        const base = `${arg.extension}@${arg.subdomain}`;
        const token = String(arg.device || "");
        const action = String(arg.action);
        if (token) {
          // Android entries carry the device id as "token:device_uuid" in the <ext>@<domain> set
          const ids = [token];
          const members = await redisClient.sMembers(base).catch(() => []);
          for (const m of members || []) if (String(m).startsWith(token + ":")) ids.push(String(m).slice(token.length + 1));
          const known = await redisClient.hGet(`devMutedIds-${base}`, token).catch(() => null);
          if (known) ids.push(...String(known).split(",").filter(Boolean));
          if (action === "0" || action === "3") {
            await redisClient.sAdd(`devMuted-${base}`, [...new Set(ids)]);
            await redisClient.hSet(`devMutedIds-${base}`, token, [...new Set(ids)].join(","));
            if (action === "3") await redisClient.hSet(`devRemoved-${base}`, token, String(Date.now()));
          } else if (action === "1") {
            if (ids.length) await redisClient.sRem(`devMuted-${base}`, [...new Set(ids)]);
            await redisClient.hDel(`devMutedIds-${base}`, token);
            await redisClient.hDel(`devRemoved-${base}`, token);
          }
          console.log("[devices]", base, action === "1" ? "calls on" : action === "0" ? "calls off" : "deleted", ids.length, "id(s)");
        }
      } catch (e) { console.log("[devices] deviceOut", e && e.message); }

      wallboard
        .to(arg["extension"] + "@" + arg["subdomain"])
        .emit("status", {
          id: "",
          extension: arg["extension"],
          device: arg["device"],
          action: arg["action"],
        });
    });

    socket.on("logout", async (arg, callback) => {

      console.log(`logout channel`, arg);
      const { subdomain, extension, pushToken, user_uuid } = arg;

      const removePushTokenHandler = async(pToken) => {
        const deviceId = await redisClient.get(`deviceOrigId-${pToken}`);
        const baseKey = `${extension}@${subdomain}`;
        
        if(!pToken || pToken == 0){
          console.log('Empty pushToken for this device!')

          console.log("this delete iosDevice")
          await new Promise((r) => setTimeout(r, 5000));

          const tokenExist = await redisClient.exists(`iosDevicePushToken-${baseKey}`);
          if(tokenExist){
            // This is the token
             await redisClient.del(`iosDevicePushToken-${baseKey}`);
            // EMAN ADDED 09-11-26 END
            await redisClient.sRem(`iosDevicePushToken-${baseKey}`, pToken);
          }
         

          return
          // throw new Error('Empty pushToken for this device!');
        }

        if (pToken.length > 0 && pToken.length <= 32) {
          console.log('Android-LogOff')
          /** Android **/
          if(!deviceId){
            throw new Error('No device Id found for android!');
          }

          const socketIdStatus = await redisClient.get(`sockets-uuidSet${deviceId}-${baseKey}`);
          if(!socketIdStatus){
            throw new Error('Empty socketId status for Android!');
          }

          await redisClient.sRem(`sockets-${baseKey}`, socketIdStatus);
          return {status:'success', message: 'push token remove successfully in android'}
          
        }
        
        if (pToken.length >= 32 && pToken.length <= 64) {
          console.log(`IOS-LogOff iosDevicePushToken-${baseKey}`, pToken);
          /** IOS **/        
          // EMAN ADDED 09-11-26 START
          await new Promise((r) => setTimeout(r, 5000));
          await redisClient.del(`iosDevicePushToken-${baseKey}`);
          // EMAN ADDED 09-11-26 END
          await redisClient.sRem(`iosDevicePushToken-${baseKey}`, pToken);
          return {status:'success', message: 'push token remove successfully in ios'}
        }
        
        if(pToken.length > 64) {
          /** WEB **/
          return {status:'success', message: 'push token remove successfully in web'}
        }

        return { status: 'error', message: 'Unhandled pToken range' };

      }

      new Promise( async(resolve, reject) => {
        // issue: status
        const { status, message } = await removePushTokenHandler(pushToken);
        if(status === 'success'){
          db2.query("DELETE FROM q_devices WHERE user_uuid = $1", [user_uuid], (err, result) => {
            if (err) {
              return reject(err);
            }
            resolve(result);
          });
        }

        if(status === 'error'){
          throw new Error(message);
        }

      }).then( async(result) => {

        const timestamp = Math.trunc(Date.now() / 1000);
        const { rows } = await db2.query(
          "SELECT number_alias FROM v_extensions WHERE extension = $1 AND accountcode = $2",
          [extension, subdomain]
        );

        if(!rows.length) return;
        const registrations = await fsRegistration();
        const extensionAlias = rows[0].number_alias;
        const findCurrentPresence = registrations.find(x => regMatch(x, extensionAlias));
        console.log('LogoutPresence!', findCurrentPresence);

        const [agentBody, tierBody] = await Promise.all([
          new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
          new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
        ]);

        const agentList = JSON.parse(agentBody.body).response;
        const tierList  = JSON.parse(tierBody.body).response;
        const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

        const matched = tierList.reduce((acc, tier) => {
            const agent = agentMap[tier.agent];
            if (
                agent && 
                agent.contact.includes(subdomain)
            ) acc.push(agent);
            return acc;
        }, []);
        console.log("✅ matched:", matched);

        if(!matched.length) return
        const filterAgentName = matched.map((x) => {
          if(x.contact.includes(`${extensionAlias}@${subdomain}`)){
            return x.name;
          }
        }).filter(Boolean);

        if(!findCurrentPresence){
          console.log("✅ AgentAlias", filterAgentName, extensionAlias);
          for(const aname of filterAgentName) {

            conn.api(`callcenter_config agent set status ${aname} 'Logged Out'`);
            wallboard.to(subdomain).emit("status", {
                id: "",
                agent: aname,
                status: "Logged Out",
                auxcode: "Offline",
                queue_extension: extension,
            });

          }
        }
        

        try {
          await db2.query(
            `UPDATE agent_details SET status = $1, last_status_change = $2 WHERE name = ALL($3)`,
            ["Logged Out", timestamp, filterAgentName]
          );
        } catch (err) {
          console.error("Error update agent_details in logout", err.message);
        }

        const [agentBody2, tierBody2] = await Promise.all([
          new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
          new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
        ]);

        const agentList2 = JSON.parse(agentBody.body).response;
        const tierList2  = JSON.parse(tierBody.body).response;
        const agentMap2 = agentList2.reduce((acc, a) => (acc[a.name] = a, acc), {});

        const matched2 = tierList2.reduce((acc, tier) => {
            const agent = agentMap2[tier.agent];
            if (
                agent && 
                agent.contact.includes(subdomain)
            ) acc.push(agent);
            return acc;
        }, []);
        console.log("✅ matched2:", matched2);
    
        if(matched2.length){

            const names2 = matched2.map(e => e.name);
            const { rows:rows2 } = await db2.query(
                `SELECT 
                    ad.name,
                    ad.contact,
                    ad.max_no_answer,
                    ad.wrap_up_time,
                    ad.reject_delay_time,
                    ad.busy_delay_time,
                    ad.no_answer_delay_time,
                    ad.last_bridge_start,
                    ad.last_bridge_end,
                    ad.last_offered_call,
                    ad.no_answer_count,
                    ad.calls_answered, 
                    ad.talk_time,
                    ad.ready_time,
                    ad.external_calls_count,
                    ve.extension
                FROM agent_details ad
                LEFT JOIN v_extensions ve 
                    ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                WHERE ad.name = ANY($1)`,
                [names2]
            );
            // Map results by name for O(1) lookup
            const dbMap2 = rows2.reduce((acc, row) => {
                acc[row.name] = row;
                return acc;
            }, {});

            const newPayload2 = matched2.map(element => {
              const data2 = dbMap2[element.name];
              if(data2){
                const { 
                  contact,
                  max_no_answer,
                  wrap_up_time,
                  reject_delay_time,
                  busy_delay_time,
                  no_answer_delay_time,
                  last_bridge_start,
                  last_bridge_end,
                  last_offered_call,
                  no_answer_count,
                  calls_answered, 
                  talk_time,
                  ready_time,
                  external_calls_count,
                  extension 
                } = data2;
                const newContact = extension ? updateContact(contact, extension) : contact;

                return {
                    ...element,
                    contact: newContact,
                    max_no_answer: Number(max_no_answer),
                    wrap_up_time: Number(wrap_up_time),
                    reject_delay_time: Number(reject_delay_time),
                    busy_delay_time: Number(busy_delay_time),
                    no_answer_delay_time: Number(no_answer_delay_time),
                    last_bridge_start: Number(last_bridge_start),
                    last_bridge_end: Number(last_bridge_end),
                    last_offered_call: Number(last_offered_call),
                    no_answer_count: Number(no_answer_count),
                    calls_answered: Number(calls_answered),
                    talk_time: Number(talk_time),
                    ready_time: Number(ready_time),
                    external_calls_count: Number(external_calls_count)
                };
              }
            });
            const filteredAgent2 = newPayload2.filter(Boolean);
            await redisClient.set(`${subdomain}:queueWallboardAgentBoard2`, JSON.stringify(filteredAgent2));
            wallboard.to(subdomain).emit("queueAgentboard2", JSON.stringify(filteredAgent2));
            console.log('👉🏻👉🏻', JSON.stringify(filteredAgent2));

        }

        if (pushToken && pushToken.length >= 64) {
          const webDeviceOn = await redisClient.sMembers(
            `deviceOnWeb-${extension}@${subdomain}`
          );
          if (webDeviceOn) {
            const device = await redisClient.get(
              `DeviceId-${extension}@${subdomain}-${pushToken}`
            );
            const key = webDeviceOn.find((x) => x === device);
            
            if (key) {
              await redisClient.sRem(
                `deviceOnWeb-${extension}@${subdomain}`,
                key
              );
              const existKey = await redisClient.exists(
                `deviceOffWeb-${extension}@${subdomain}`
              );
              if (existKey) {
                await redisClient.sRem(
                  `deviceOffWeb-${extension}@${subdomain}`,
                  key
                );
              }
            }
          }

          const webDeviceOff = await redisClient.sMembers(
            `deviceOffWeb-${extension}@${subdomain}`
          );
          if (webDeviceOff) {
            const device = await redisClient.get(
              `DeviceId-${extension}@${subdomain}-${pushToken}`
            );
            const key = webDeviceOff.find((x) => x === device);
            
            if (key) {
              await redisClient.sRem(
                `deviceOffWeb-${extension}@${subdomain}`,
                key
              );
              const existKey = await redisClient.exists(
                `deviceOnWeb-${extension}@${subdomain}`
              );
              if (existKey) {
                await redisClient.sRem(
                  `deviceOnWeb-${extension}@${subdomain}`,
                  key
                );
              }
            }
          }
        } else {
          if (pushToken <= 32) {
            const device = await redisClient.get(
              `DeviceId-${extension}@${subdomain}-${pushToken}`
            );
            
            const androidDeviceOn = await redisClient.sMembers(
              `deviceOn-${extension}@${subdomain}`
            );
            if (androidDeviceOn) {
              const key = androidDeviceOn.find((x) => x === device);
              if (key) {
                await redisClient.sRem(`deviceOn-${extension}@${subdomain}`, key);
                await redisClient.sRem(
                  `deviceOff-${extension}@${subdomain}`,
                  key
                );
              }
            }

            const androidDeviceOff = await redisClient.sMembers(
              `deviceOn-${extension}@${subdomain}`
            );
            if (androidDeviceOff) {
              const key = androidDeviceOff.find((x) => x === device);
              if (key) {
                await redisClient.sRem(
                  `deviceOff-${extension}@${subdomain}`,
                  key
                );
                await redisClient.sRem(`deviceOn-${extension}@${subdomain}`, key);
              }
            }
          }
        }

      }).catch((err) => {
        console.error("❌ DB Error:", err.message);
      });
     
    });

    socket.on("deletetier", async (arg, callback) => {


      console.log(`DELETE TIER ⛳`, arg);

      const {extension, subdomain, agent} = arg

      conn.api(`callcenter_config tier del ${extension}@${subdomain} ${agent}`);
      await db2.query(`DELETE FROM agents WHERE name = $1`,[agent]);
      await db2.query(`DELETE FROM agent_details WHERE name = $1`, [agent]);
      conn.api(`json {"command": "callcenter_config","data": {"arguments":"queue list tiers", "queue_name":"${extension}@${subdomain}"}}`, async ({body}) => {

        const { response } = JSON.parse(body);
        if(!response.length){
          const agentDetailsDB = await db2.query("SELECT EXISTS(SELECT 1 FROM member_board WHERE queue = $1)", [`${extension}@${subdomain}`]);
          const exist = agentDetailsDB.rows[0].exists;
          console.log(`⛳ QUEUE EXIST`, exist);
          if(exist){
            await db2.query(`DELETE FROM member_board WHERE queue = $1`, [`${extension}@${subdomain}`]);
            const configBoardList = await redisClient.get(`${arg.subdomain}:queueWallboardConfigBoard`);
            if(!configBoardList) return;
            const parseConfigBoard = JSON.parse(configBoardList);
            const newConfigBoardList = parseConfigBoard.filter(({queue}) => queue !== `${extension}@${subdomain}`);
            await redisClient.set(`${subdomain}:queueWallboardConfigBoard`,JSON.stringify(newConfigBoardList));
            wallboard.to(subdomain).emit("queueConfigBoard", JSON.stringify(newConfigBoardList));
          }
        }

          const [agentBody, tierBody] = await Promise.all([
              new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
              new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
          ]);

            const agentList = JSON.parse(agentBody.body).response;
            const tierList  = JSON.parse(tierBody.body).response;
            const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

            const matched = tierList.reduce((acc, tier) => {
                const agent = agentMap[tier.agent];
                if (
                    agent && 
                    agent.contact.includes(arg.subdomain)
                ) acc.push(agent);
                return acc;
            }, []);
            console.log("✅ matched:", matched);

            if(matched.length){

                const names = matched.map(e => e.name);
                const { rows } = await db2.query(
                    `SELECT 
                        ad.name,
                        ad.contact,
                        ad.max_no_answer,
                        ad.wrap_up_time,
                        ad.reject_delay_time,
                        ad.busy_delay_time,
                        ad.no_answer_delay_time,
                        ad.last_bridge_start,
                        ad.last_bridge_end,
                        ad.last_offered_call,
                        ad.last_status_change,
                        ad.no_answer_count,
                        ad.calls_answered, 
                        ad.talk_time,
                        ad.ready_time,
                        ad.external_calls_count,
                        ve.extension
                    FROM agent_details ad
                    LEFT JOIN v_extensions ve 
                        ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                    WHERE ad.name = ANY($1)`,
                    [names]
                );

                // Map results by name for O(1) lookup
                const dbMap = rows.reduce((acc, row) => {
                    acc[row.name] = row;
                    return acc;
                }, {});

                const newPayload = matched.map(element => {

                    const data = dbMap[element.name];
                    if(data){
                      const { 
                        contact,
                        max_no_answer,
                        wrap_up_time,
                        reject_delay_time,
                        busy_delay_time,
                        no_answer_delay_time,
                        last_bridge_start,
                        last_bridge_end,
                        last_offered_call,
                        last_status_change,
                        no_answer_count,
                        calls_answered, 
                        talk_time,
                        ready_time,
                        external_calls_count,
                        extension 
                      } = data;
                      const newContact = extension ? updateContact(contact, extension) : contact;

                      return {
                          ...element,
                          contact: newContact,
                          max_no_answer: Number(max_no_answer),
                          wrap_up_time: Number(wrap_up_time),
                          reject_delay_time: Number(reject_delay_time),
                          busy_delay_time: Number(busy_delay_time),
                          no_answer_delay_time: Number(no_answer_delay_time),
                          last_bridge_start: Number(last_bridge_start),
                          last_bridge_end: Number(last_bridge_end),
                          last_offered_call: Number(last_offered_call),
                          last_status_change: Number(last_status_change),
                          no_answer_count: Number(no_answer_count),
                          calls_answered: Number(calls_answered),
                          talk_time: Number(talk_time),
                          ready_time: Number(ready_time),
                          external_calls_count: Number(external_calls_count)
                      };
                    }

                });
                
                const filteredAgent = newPayload.filter(Boolean);
                console.log('👉🏻👉🏻', JSON.stringify(filteredAgent));
                await redisClient.set(
                  `${arg.subdomain}:queueWallboardAgentBoard2`,
                  JSON.stringify(filteredAgent)
                );
                wallboard.to(arg.subdomain).emit("queueAgentboard2", JSON.stringify(filteredAgent));
              
            }

            conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, async ({ body }) => {
              const { status, response } = JSON.parse(body);

              if (status === "success") {
                if (response.length) {
                  const names = response
                    .filter(e => e.name.includes(arg.subdomain))
                    .map(e => e.name);

                  if (!names.length) return;

                  db2.query(
                    `SELECT queue, answered_seconds, abandoned_seconds, talk_time, answered_calls,
                    missed_calls, waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                    FROM member_board WHERE queue = ANY($1)`,
                    [names],
                    async (err, result) => {
                      if (err) {
                        console.error("Error fetching data:", err.message);
                        return;
                      }
                      const rows = result.rows;
                      await redisClient.set(
                        `${arg.subdomain}:queueWallboardConfigBoard`,
                        JSON.stringify(rows)
                      );
                      wallboard.to(arg.subdomain).emit("queueConfigBoard", JSON.stringify(rows));
                    }
                  );
                }
              }
            });

      });

      // if (setLock) {
        
       

      //   /** Remove in  queueWallboardConfigBoard*/
      //   const configBoardList = await redisClient.get(
      //     `${arg.subdomain}:queueWallboardConfigBoard`
      //   );
      //   if (configBoardList) {
      //     /** Removed Redis*/
          

      //     try {

      //       async function removeAgentsFromRedis(agentUUIDs) {

      //         if(!agentUUIDs || agentUUIDs.length === 0) return;

      //         const redisKey = `${arg.subdomain}:queueWallboardAgentBoard2`;
      //         // Get current JSON value
      //         const rawData = await redisClient.get(redisKey);
      //         if (!rawData) return 

      //         // Parse and filter
      //         let agents = JSON.parse(rawData);
      //         agents = agents.filter(
      //           (agent) => !agentUUIDs.includes(agent.name)
      //         );

      //         // Save back to Redis
      //         await redisClient.set(redisKey, JSON.stringify(agents));

      //         const placeholders = agentUUIDs
      //           .map((_, i) => `$${i + 1}`)
      //           .join(", ");

      //         // Delete from agents
      //         await db2.query(
      //           `DELETE FROM agent_details WHERE name IN (${placeholders})`,
      //           agentUUIDs
      //         );

      //       }

      //       const result = await db2.query(
      //         "SELECT call_center_agent_uuid FROM v_call_center_agents WHERE agent_contact LIKE $1 AND queue_extension = $2",
      //         [`%${arg.subdomain}%`, arg.extension]
      //       );
      //       const rows = result.rows;
            
      //       const agentId = rows.map((row) => row.call_center_agent_uuid);
      //       await removeAgentsFromRedis(agentId);

      //       const member_board =  await db2.query(
      //         `SELECT queue, answered_seconds, abandoned_seconds, talk_time, answered_calls,
      //           missed_calls, waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
      //           FROM member_board WHERE queue LIKE $1`,
      //         [`%${arg.subdomain}%`],
      //       );

      //       if(member_board.rows.length > 0){

      //         const rows = member_board.rows;
              
      //         await redisClient.set(
      //             `${arg.subdomain}:queueWallboardConfigBoard`,
      //             JSON.stringify(rows)
      //         );
      //         wallboard.to(arg.subdomain).emit("queueConfigBoard", JSON.stringify(rows));
      //       }


          
            
            

      //     } catch (err) {
      //       console.error(
      //         "Error executing agents query delete tiers:",
      //         err.message
      //       );
      //     }
      //   }
      //   /** end here */
      // }
      
    });

    socket.on("walldataSettings", async (arg, callback) => {
      
      await redisClient.set(
        socket.handshake.auth.extension +
          "@" +
          socket.handshake.auth.channel +
          "-walldataSettings",
        JSON.stringify(arg)
      );
    });

    socket.on("favorite", async (arg, callback) => {

      console.log('FAVORITE', arg);
      
      var extension = parseInt(arg);
      await redisClient.sAdd(
        socket.handshake.auth.extension +
          "@" +
          socket.handshake.auth.channel +
          "-favorite",
        extension.toString()
      );
      wallboard
        .to(socket.handshake.auth.channel)
        .emit(
          "favorites",
          socket.handshake.auth.extension + "@" + socket.handshake.auth.channel
        );
    });

    socket.on("unfavorite", async (arg, callback) => {

      console.log('UNFAVORITE', arg);
      
      var extension = parseInt(arg);
      await redisClient.sRem(
        socket.handshake.auth.extension +
          "@" +
          socket.handshake.auth.channel +
          "-favorite",
        extension.toString()
      );
      wallboard
        .to(socket.handshake.auth.channel)
        .emit(
          "unfavorites",
          socket.handshake.auth.extension + "@" + socket.handshake.auth.channel
        );
    });

    socket.on("reloadqueue", async (arg, callback) => {
      /** For save and update queue */
      console.log("reloadqueue is trigger");
      conn.api("reloadxml");
      conn.api("reloadacl", ({ body }) => {
      console.log("Reload ACL result:", body);
     });
      // The queue's recording setting changed its record-template; mod_callcenter only
      // re-reads a queue's params on a queue reload, not on reloadxml.
      if (Number(arg?.queue_reload) === 1 && arg.extension && arg.subdomain) {
        conn.api(`callcenter_config queue reload ${arg.extension}@${arg.subdomain}`);
      }
    });

    socket.on("localstreamreload", async (arg, callback) => {
      conn.api("reload mod_local_stream");
    });

    // socket.on("gatewaystatus", async (arg, callback) => {

    //   console.log('GATEWAYS PROFILES', arg);
    //   const { profiles, subdomain } = arg;

    //   await new Promise(resolve => setTimeout(resolve, 10000));
    //   await Promise.all(profiles.map(profile => {
    //     conn.api(`sofia profile ${profile} rescan`)
    //   }));

    //   await new Promise(resolve => setTimeout(resolve, 10000));
    //   await Promise.all(profiles.map(profile => {
    //     conn.api(`sofia profile ${profile} restart`)
    //   }));
     
    //   // function apiCall(conn, command) {
    //   //     return new Promise((resolve, reject) => {
    //   //         conn.api(command, function (res) {
    //   //             resolve(res.body);
    //   //         });
    //   //     });
    //   // }

    //   // const containerId = await Promise.all(
    //   //     profiles.map(name => {
    //   //       return apiCall(conn, `sofia profile ${name} gwlist up`)
    //   //     }).filter(Boolean)
    //   // );

    //   // const filterGateways = containerId.filter((id)=> id != '-ERR no reply\n');
    //   // const dataID = filterGateways.toString().split(",").join("").trim();
    //   // console.log('GATEWAYS PROFILES2', containerId, dataID);
    //   // await redisClient.set(arg["subdomain"] + "-gateways", dataID);
    //   await new Promise(resolve => setTimeout(resolve, 10000));

    //   const getProfiles = (conn) => {
    //     return new Promise((resolve, reject) => {
    //       conn.api('sofia xmlstatus profile', (res) => {
    //         if (!res || !res.body) return reject(new Error('No response from sofia xmlstatus profile'));
    //         const names = [...res.body.matchAll(/<name>([^<]+)<\/name>/g)].map(m => m[1]);
    //         resolve(names);
    //       });
    //     });
    //   };

    //   const getRegisteredGateways = async (conn, profiles) => {
    //     const promises = profiles.map(gatewayId => {
    //       return new Promise((resolve, reject) => {
    //         conn.api(`sofia status gateway ${gatewayId}`, (res) => {
    //           if (!res || !res.body) return reject(new Error(`No response for ${gatewayId}`));
    //           resolve({ gatewayId, status: res.body });
    //         });
    //       });
    //     });

    //     const results = await Promise.allSettled(promises);
    //     console.log('🦜EVENT1', results);

    //     return results
    //       .filter(r => r.status === 'fulfilled')
    //       .map(r => r.value)
    //       .filter(({ status }) => (status.includes('REGED') || status.includes('NOREG'))
    //       && !status.includes('UNREGED'));
    //   };

    //   // Usage
    //   const profiles2 = await getProfiles(conn);
    //   console.log('🦜EVENT NEW', profiles2);
    //   const regedGateways = await getRegisteredGateways(conn, profiles2);
    //   const regedGateways2 = regedGateways.map(x => x.gatewayId);
    //   const gateways = regedGateways2.toString().split(",").join(" ");
    //   console.log('🦜EVENT2', regedGateways, gateways);
    //   await redisClient.set(arg["subdomain"] + "-gateways", gateways);
    //   wallboard.to(arg["subdomain"]).emit("gatewaystatus", gateways);

    // });

    socket.on("gatewaystart", async (arg) => {
      // [trunk-reload] Called by the PHP API (/api/gateway) after a trunk is added, edited or deleted.
      // Old code restarted EVERY SIP profile (internal too) when a trunk's proxy/transport changed --
      // that dropped all calls and on 3 Oct 2026 left the phone-side profile half-stopped (phones and
      // apps could not register for 3 h). A trunk lives only in its own profile, and killgw + rescan
      // fully reloads it (proxy, transport, credentials), so no profile is ever restarted here.
      // Only the local PHP API may trigger this (browser sockets arrive through nginx with X-Forwarded-For).
      const hs = socket.handshake || {};
      const fromLocalApi = !(hs.headers && hs.headers["x-forwarded-for"]) &&
        /^(::1|127\.0\.0\.1|::ffff:127\.0\.0\.1)$/.test(String(hs.address || ""));
      if (!fromLocalApi) {
        console.log("[trunk-reload] ignored gatewaystart from", hs.address, hs.headers && hs.headers["x-forwarded-for"]);
        return;
      }
      arg = arg || {};
      const gwUuid = /^[0-9a-f-]{36}$/i.test(String(arg.uuid || "")) ? String(arg.uuid) : "";
      const api = (cmd) => new Promise((resolve) => conn.api(cmd, (res) => resolve(res && res.body)));
      await api("reloadxml");
      await api("reloadacl");
      // which profile(s) hold trunks: the trunk's own profile, else every profile that has gateways
      let profiles = ["external"];
      try {
        const { rows } = gwUuid
          ? await db2.query("SELECT DISTINCT coalesce(nullif(profile,''),'external') AS p FROM v_gateways WHERE gateway_uuid = $1", [gwUuid])
          : await db2.query("SELECT DISTINCT coalesce(nullif(profile,''),'external') AS p FROM v_gateways");
        if (rows.length) profiles = rows.map((r) => r.p).filter((p) => /^[\w.-]+$/.test(p));
      } catch (e) { /* keep default */ }
      for (const prof of profiles) {
        if (gwUuid) await api(`sofia profile ${prof} killgw ${gwUuid}`); // edited/deleted trunk: drop old copy
        await new Promise((r) => setTimeout(r, 1000));
        await api(`sofia profile ${prof} rescan`);                     // (re)load trunks from config
      }
      console.log("[trunk-reload]", gwUuid || "(all)", "profiles", profiles.join(","), arg.restart ? "(proxy/transport changed: killgw+rescan, no restart)" : "");

      await new Promise(resolve => setTimeout(resolve, 10000));
      const getProfiles = (conn) => {
        return new Promise((resolve, reject) => {
          conn.api('sofia xmlstatus profile', (res) => {
            if (!res || !res.body) return reject(new Error('No response from sofia xmlstatus profile'));
            const names = [...res.body.matchAll(/<name>([^<]+)<\/name>/g)].map(m => m[1]);
            resolve(names);
          });
        });
      };

      const getRegisteredGateways = async (conn, profiles) => {
        const promises = profiles.map(gatewayId => {
          return new Promise((resolve, reject) => {
            conn.api(`sofia status gateway ${gatewayId}`, (res) => {
              if (!res || !res.body) return reject(new Error(`No response for ${gatewayId}`));
              resolve({ gatewayId, status: res.body });
            });
          });
        });

        const results = await Promise.allSettled(promises);
        console.log('🦜EVENT1', results);

        return results
          .filter(r => r.status === 'fulfilled')
          .map(r => r.value)
          .filter(({ status }) => (status.includes('REGED') || status.includes('NOREG'))
          && !status.includes('UNREGED'));
      };

      // Usage
      const profiles2 = await getProfiles(conn);
      console.log('🦜EVENT NEW', profiles2);
      const regedGateways = await getRegisteredGateways(conn, profiles2);
      const regedGateways2 = regedGateways.map(x => x.gatewayId);
      const gateways = regedGateways2.toString().split(",").join(" ");
      console.log('🦜EVENT2', regedGateways, gateways);
      await redisClient.set(arg["subdomain"] + "-gateways", gateways);
      wallboard.to(arg["subdomain"]).emit("gatewaystatus", gateways);

    });

    socket.on("upload", async (arg, callback) => {
      
      $imageName =
        Date.now() +
        parseInt(socket.handshake.auth.extension) +
        Date.now() +
        (Date.now() - 123456789) +
        "0.png";
      writeFile("/var/www/ringq/public/avr/" + $imageName, arg, (err) => {
        callback({
          result: err ? "error" : "success",
          message: err
            ? "Sorry, image upload request has failed."
            : "Success, image upload completed.",
          content: $imageName,
        });
        if (!err) {
          redisClient.set(
            "avatar:" +
              socket.handshake.auth.channel +
              ":" +
              socket.handshake.auth.extension,
            $imageName
          );
        }
      });
    });

    socket.on("upload2", async (arg, callback) => {
      
      const extension = parseInt(socket.handshake.auth.extension);
      const timestamp = Date.now();
      const soundName = `${timestamp}${extension}${timestamp - 123456789}0.mp3`;

      writeFile(
        "/var/www/ringq/public/audio/" + soundName,
        arg,
        async (err) => {
          if (err) {
            callback({
              result: "error",
              message: "Sorry, audio upload request has failed.",
              content: null,
            });
            return;
          }

          try {
            const sampleRates = [48000, 32000, 16000, 8000];
            const convertedFiles = [];
            let count = 0;
            for (const rate of sampleRates) {
              count++;
              const outputFileName = `${soundName.split(".")[0]}_${rate}hz.wav`;
              await ffmpeg()
                .input("/var/www/ringq/public/audio/" + soundName)
                .audioFilter("adelay=2000|2000")
                .audioCodec("adpcm_ima_wav")
                .outputOptions("-ab", "192k", "-ar", rate)
                .toFormat("wav")
                .save("/var/www/ringq/public/audio/" + outputFileName)
                .on("end", () => {
                  
                  convertedFiles.push(outputFileName);
                  if (rate == 48000) {
                    callback({
                      result: "success",
                      message: "Success, audio upload completed.",
                      content: convertedFiles[0],
                    });
                  }
                })
                .on("error", (error) => {
                  console.error(`Error converting to ${rate} Hz:`, error);
                });
            }

            setTimeout(() => {
              // Delete the original audio file
              if (existsSync("/var/www/ringq/public/audio/" + soundName)) {
                unlinkSync("/var/www/ringq/public/audio/" + soundName);
                
              }
            }, count * 1000);

            
          } catch (error) {
            console.error("Error processing audio:", error);
            callback({
              result: "error",
              message: "Error processing audio: " + error.message,
              content: null,
            });
          }
        }
      );
    });

    socket.on("upload3", async (arg, callback) => {
      
      const extension2 = parseInt(socket.handshake.auth.extension);
      const timestamp2 = Date.now();
      const soundName2 = `${timestamp2}${extension2}${
        timestamp2 - 123456789
      }0.mp3`;

      writeFile(
        "/var/www/ringq/public/audio/" + soundName2,
        arg,
        async (err) => {
          if (err) {
            callback({
              result: "error",
              message: "Sorry, audio upload request has failed.",
              content: null,
            });
            return;
          }

          try {
            const sampleRates2 = [48000, 32000, 16000, 8000];
            const convertedFiles2 = [];
            let count = 0;
            for (const rate2 of sampleRates2) {
              count++;
              const outputFileName2 = `${
                soundName2.split(".")[0]
              }_${rate2}hz.wav`;
              await ffmpeg()
                .input("/var/www/ringq/public/audio/" + soundName2)
                .audioCodec("adpcm_ima_wav")
                .outputOptions("-ab", "192k", "-ar", rate2)
                .toFormat("wav")
                .save("/var/www/ringq/public/audio/" + outputFileName2)
                .on("end", () => {
                  
                  convertedFiles2.push(outputFileName2);
                  if (rate2 == 48000) {
                    callback({
                      result: "success",
                      message: "Success, audio upload completed.",
                      content: convertedFiles2[0],
                    });
                  }
                })
                .on("error", (error) => {
                  console.error(`Error converting to ${rate2} Hz:`, error);
                });
            }

            setTimeout(() => {
              // Delete the original audio file
              if (existsSync("/var/www/ringq/public/audio/" + soundName2)) {
                unlinkSync("/var/www/ringq/public/audio/" + soundName2);
                
              }
            }, count * 1000);

            
          } catch (error) {
            console.error("Error processing audio:", error);
            callback({
              result: "error",
              message: "Error processing audio: " + error.message,
              content: null,
            });
          }
        }
      );

      // soundName = Date.now() + parseInt(socket.handshake.auth.extension) + Date.now() + (Date.now() - 123456789) + "0.mp3";
      // writeFile("/var/www/ringq/node/audio/" + soundName, arg, (err) => {
      //      callback({
      //           result: err ? "error" : "success",
      //           message: err ? "Sorry, audio upload request has failed." : "Success, audio upload completed.",
      //           content: soundName,
      //      });
      // });
    });

    socket.on("upload4", async (arg, callback) => {
      

      const extension3 = parseInt(socket.handshake.auth.extension);
      const timestamp3 = Date.now();
      const soundName3 = `${timestamp3}${extension3}${
        timestamp3 - 123456789
      }0.mp3`;

      writeFile(
        "/var/www/ringq/public/voice/" + soundName3,
        arg,
        async (err) => {
          if (err) {
            callback({
              result: "error",
              message: "Sorry, audio upload request has failed.",
              content: null,
            });
            return;
          }

          try {
            const sampleRates3 = [48000, 32000, 16000, 8000];
            const convertedFiles3 = [];
            let count = 0;
            for (const rate3 of sampleRates3) {
              count++;
              const outputFileName3 = `${
                soundName3.split(".")[0]
              }_${rate3}hz.wav`;
                ffmpeg()
                .input("/var/www/ringq/public/voice/" + soundName3)
                .audioCodec("adpcm_ima_wav")
                .outputOptions("-ab", "192k", "-ar", rate3)
                .toFormat("wav")
                .save("/var/www/ringq/public/voice/" + outputFileName3)
                .on("end", () => {
                  
                  convertedFiles3.push(outputFileName3);
                  if (rate3 == 48000) {
                    callback({
                      result: "success",
                      message: "Success, audio upload completed.",
                      content: convertedFiles3[0],
                    });
                  }
                })
                .on("error", (error) => {
                  console.error(`Error converting to ${rate3} Hz:`, error);
                });
            }

            setTimeout(() => {
              // Delete the original audio file
              if (existsSync("/var/www/ringq/public/voice/" + soundName3)) {
                unlinkSync("/var/www/ringq/public/voice/" + soundName3);
                
              }
            }, count * 1000);

            
          } catch (error) {
            console.error("Error processing audio:", error);
            callback({
              result: "error",
              message: "Error processing audio: " + error.message,
              content: null,
            });
          }
        }
      );

      // soundName = Date.now() + parseInt(socket.handshake.auth.extension) + Date.now() + (Date.now() - 123456789) + "0.mp3";
      // writeFile("/var/www/ringq/public/voice/" + soundName, arg, (err) => {
      //      callback({
      //           result: err ? "error" : "success",
      //           message: err ? "Sorry, audio upload request has failed." : "Success, audio upload completed.",
      //           content: soundName,
      //      });
      // });
    });

    socket.on("upload5", async (arg, callback) => {
      
      $imageName =
        Date.now() +
        parseInt(socket.handshake.auth.extension) +
        Date.now() +
        (Date.now() - 123456789) +
        "0.png";
      writeFile("/var/www/ringq/public/temp/" + $imageName, arg, (err) => {
        callback({
          result: err ? "error" : "success",
          message: err
            ? "Sorry, image upload request has failed."
            : "Success, image upload completed.",
          content: $imageName,
        });
        if (!err) {
          redisClient.set(
            "hotel:" +
              socket.handshake.auth.channel +
              ":" +
              socket.handshake.auth.extension,
            $imageName
          );
        }
      });
    });

    // The Hotel PBX image sent with upload5 has been attached to its slot
    // (customize/index read it from this key). Clear the key: customize/index
    // applies it to every later box save, so a name/extension-only save of
    // another box was overwriting that box's image with this upload.
    socket.on("upload5-attached", async () => {
      try {
        await redisClient.del(
          "hotel:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension
        );
      } catch (err) {
        console.log("upload5-attached:", err && err.message);
      }
    });


    socket.on("hotelRooms", (arg) => {
      const ownChannel = socket.handshake.auth && socket.handshake.auth.channel;
      const room = ownChannel ? String(ownChannel) : String((arg && arg.subdomain) || "");
      if (!room) {
        return;
      }
      wallboard.to(room).emit("hotelRooms", {
        extension: String((arg && arg.extension) || ""),
      });
    });

    socket.on("upload6", async (arg, callback) => {
      
      $csvFile =
        Date.now() +
        parseInt(socket.handshake.auth.extension) +
        Date.now() +
        (Date.now() - 123456789) +
        "0.csv";
      writeFile("/var/www/ringq/public/temp/" + $csvFile, arg, (err) => {
        callback({
          result: err ? "error" : "success",
          message: err
            ? "Sorry, csv upload request has failed."
            : "Success, csv upload completed.",
          content: $csvFile,
        });
        if (!err) {
          redisClient.set("csv:" + socket.handshake.auth.channel, $csvFile);
        }
      });
    });

    socket.on("upload7", async (arg, callback) => {
      //banner
      const type = await FileType.fromBuffer(arg);

      if (!type || !type.mime.startsWith('image/')) {
        return callback({
          result: "error",
          message: "Invalid file. Only images are allowed.",
          content: null,
        });
      }

      console.log('UPLOAD7', type, socket.handshake.auth.channel );
      const imageName = Date.now() + parseInt(socket.handshake.auth.extension) + Date.now() + (Date.now() - 123456789) + "0.png";
      await redisClient.set(`${socket.handshake.auth.channel}-banner`, imageName);

      writeFile("/var/www/ringq/public/img/" + imageName, arg, (err) => {
        callback({
          result: err ? "error" : "success",
          message: err
            ? "Sorry, image upload request has failed."
            : "Success, image upload completed.",
          content: imageName,
        });
      }); 
    });

    socket.on("upload8", async (arg, callback) => {
      // footer
      const type = await FileType.fromBuffer(arg);

      if (!type || !type.mime.startsWith('image/')) {
        return callback({
          result: "error",
          message: "Invalid file. Only images are allowed.",
          content: null,
        });
      }
      console.log('UPLOAD8', type);
      const imageName = Date.now() + parseInt(socket.handshake.auth.extension) + Date.now() + (Date.now() - 123456789) + "0.png";
      await redisClient.set(`${socket.handshake.auth.channel}-footer`, imageName);

      writeFile("/var/www/ringq/public/img/" + imageName, arg, (err) => {
          callback({
            result: err ? "error" : "success",
            message: err
              ? "Sorry, image upload request has failed."
              : "Success, image upload completed.",
            content: imageName,
          });
        }); 
    });

  //  socket.on("endCall", async (arg) => {
      
  //     const { channel, deviceId } = socket.handshake.auth;
      
  //     const devicePlatform =  await redisClient.get(`${channel}-${deviceId}-platform`);
  //     console.log('🟡 ENDCALL', arg, socket.handshake.auth, devicePlatform);
  //     console.log("this is the data", socket.handshake.auth)
  //          const aliasNameExtension2 = async () => {
  //           const { rows } = await db2.query(
  //               `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
  //               [socket.handshake.auth.channel, socket.handshake.auth.extension]
  //           );
  //           const extensionOrigName = rows[0]?.number_alias;
  //           return extensionOrigName
  //       }

  //       console.log('🟡 DECLINE WEB', arg, socket.handshake.auth);

  //       const aliasName2 = await aliasNameExtension2();
  //       conn.bgapi(`show channels as json`, async (res) => {
  //         if (res.body != "-ERR No such channel!\n") {
  //           const { row_count, rows } = JSON.parse(res.body);
  //           console.log(`🔥 WEB 🔥`, rows, aliasName2);
  //           if (row_count !== 0) {
  //             if (rows.length) {
  //               const findExtension = rows.find( (x) => x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}`);
  //               console.log("1") 
  //               // 28bee7d8bf2cd95496a9

  //               if (findExtension) {
  //                 const { call_uuid } = findExtension;
  //                 if(call_uuid){
              
  //                     const callControlPosition = await redisClient.get(`${arg}+${channel}`);
  //                     console.log("2", `${arg}+${channel}`);
  //                     if(callControlPosition){
  //                       const positionNum = Number(callControlPosition)
  //                       console.log("3", `${arg}@${channel}-forward-busy-enabled`);
  //                       console.log("this is uuid_kill", `${call_uuid}` );

  //                       conn.api(`uuid_transfer ${call_uuid} -bleg 1144 XML ${socket.handshake.auth.channel}` );
  //                       conn.api(`uuid_kill ${call_uuid}`);
                       
  //                       bridgeAfterProcess();

  //                     }

  //                 } 
  //               }
  //             }
  //           }
  //         }
  //       });

  //   });

  //   function bridgeAfterProcess(){
  //      conn.api(`uuid_transfer ${hangupCallId} *99${arg} XML ${socket.handshake.auth.channel}`);
  //   }

    socket.on("decline", async (arg) => {
      
      const { channel, device_uuid } = socket.handshake.auth;

      const devicePlatform =  await redisClient.get(`${channel}-${device_uuid}-platform`, );

      // ADDED EMAN 08-28-26 START
      // const getValidatePark = await redisClient.get(`validatePark-${arg}`);
      // if (devicePlatform != null && devicePlatform.includes("iOS") && getValidatePark) {
      //   console.log("PREVENT DECLINE IOS getValidatePark");
      //   return;
      // }
      // ADDED EMAN 08-28-26 END

      console.log('🟡 DECLINE', arg, socket.handshake.auth, devicePlatform);

      if(devicePlatform && devicePlatform.includes('iOS')){

          const aliasNameExtension2 = async () => {
                const { rows } = await db2.query(
                    `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                    [socket.handshake.auth.channel, socket.handshake.auth.extension]
                );
                const extensionOrigName = rows[0]?.number_alias;
                return extensionOrigName
          }       
          const aliasName2 = await aliasNameExtension2();

          conn.api(`show channels as json`, async ({ body }) => {
            console.log("this is aliasName", aliasName2);
            console.log('🟡 EXIST CALL', body);
            if (body != "-ERR No such channel!\n") {
              const { row_count, rows } = JSON.parse(body);
              console.log("this is the decline sockets", socket.handshake.auth);
              if (row_count !== 0) {

                if (rows.length) {
                  // EMAN ORIGINAL 08-19-26 START
                  // const filterUID = rows.filter( (x) => 
                  //   (
                  //     x.callstate === "EARLY" && x.dest === socket.handshake.auth.extension && ( x.accountcode === socket.handshake.auth.channel || x.context === socket.handshake.auth.channel) 
                  //     || x.callstate === "EARLY" && x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}`
                  //   ) && x.b_state !== 'CS_EXCHANGE_MEDIA' && x.application !== 'bridge' 
                  //    // or else for this other
                  //   || (x.callstate === "ACTIVE" || x.callstate === "EARLY") && x.initial_dest === arg && x.accountcode === socket.handshake.auth.channel
                  // );
                  // EMAN ORIGINAL 08-19-26 END
                  // EMAN MODIFIED 08-19-26 START
                  // const filterUID = rows.filter((x) => {
                  //   const isTargetExtension =
                  //     x.dest === socket.handshake.auth.extension &&
                  //     (x.accountcode === socket.handshake.auth.channel ||
                  //       x.context === socket.handshake.auth.channel);

                  //   const isTargetAlias =
                  //     x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}`;

                  //   const isTargetInitialDest =
                  //     x.initial_dest === arg &&
                  //     x.accountcode === socket.handshake.auth.channel;

                  //   const isValidCallState =
                  //     x.callstate === "CS_PARK" ||
                  //     x.callstate === "EARLY" ||
                  //     x.callstate === "ACTIVE";

                  //   const isNotExchangingOrBridging =
                  //     x.b_state !== "CS_EXCHANGE_MEDIA" && x.application !== "bridge";

                  //   // Grouped logic evaluation:
                  //   return (
                  //     isValidCallState &&
                  //     (isTargetExtension || isTargetAlias || isTargetInitialDest) &&
                  //     isNotExchangingOrBridging
                  //   );
                  // });
                  // console.log(`🟡 IOS 🟡`,rows, aliasName2, socket.handshake.auth.extension, socket.handshake.auth.channel,filterUID);
                  // if(filterUID.length){
                  //   filterUID.forEach( async element => {
                  //     const hangupCallId = element.uuid || element.call_uuid;

                  //     // EMAN MODIFIED 08-19-26 START
                  //     console.log(`🟡 KILL CALL IOS 🟡`, hangupCallId);
                  //     console.log("transfer 1SI")
                  //     conn.api(`uuid_transfer ${hangupCallId} *99${arg} XML ${socket.handshake.auth.channel}`);
                  //     // EMAN MODIFIED 08-19-26 END

                  //   });
                  // }
                  // EMAN MODIFIED 08-19-26 END

                  // EMAN ADDED 08-26-26 START
                  const filterUID = rows.filter((x) => {
                  const isTargetExtension =
                    x.dest === socket.handshake.auth.extension &&
                    (x.accountcode === socket.handshake.auth.channel ||
                      x.context === socket.handshake.auth.channel);

                  const isTargetAlias =
                    x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}`;

                  const isTargetInitialDest =
                    x.initial_dest === arg &&
                    x.accountcode === socket.handshake.auth.channel;

                  // Only allow states where the call hasn't been fully established
                  const isValidCallState =
                    x.callstate === "CS_PARK" ||
                    x.callstate === "EARLY" ||
                    x.callstate === "RINGING";

                  // Prevent killing if either leg is active, exchanging media, or bridged
                  const isActiveOrConnected =
                    x.callstate === "ACTIVE" ||
                    x.b_callstate === "ACTIVE" ||
                    x.b_state === "CS_EXCHANGE_MEDIA" ||
                    x.application === "bridge";

                  // Grouped logic evaluation:
                  return (
                    isValidCallState &&
                    (isTargetExtension || isTargetAlias || isTargetInitialDest) &&
                    !isActiveOrConnected
                  );
                });
                console.log(`🟡 IOS 🟡`, rows, aliasName2, socket.handshake.auth.extension, socket.handshake.auth.channel, filterUID);
                if (filterUID.length) {
                    filterUID.forEach(async (element) => {
                      const hangupCallId = element.uuid || element.call_uuid;

                      console.log(`🟡 KILL CALL IOS 🟡`, hangupCallId);

                      //Added 09-01-26
                      const getUUIDS = await redisClient.get(`retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      console.log("transfer 1SI", `retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      
                      if(!getUUIDS){
                         console.log("tra");
                            conn.api(`uuid_transfer ${hangupCallId} *99${arg} XML ${socket.handshake.auth.channel}`);
                      }
                      else{
                          console.log("nsfer");
                          const validUUIDS = JSON.parse(getUUIDS);
                          const aId = validUUIDS.aLeg;
                          const bId = validUUIDS.bLeg;
                          conn.api(`uuid_exists ${aId}`, (res) => console.log(`existA->☎️☎️ ${aId}`,res));
                          conn.api(`uuid_exists ${bId}`, (res) => console.log(`existB->☎️☎️ ${bId}`,res))
                          console.log("bridging... 5")
                          conn.api(`uuid_bridge ${aId} ${bId}`);
                          redisClient.del(`retrieveCalleeDecline-${arg}@${socket.handshake.auth.channel}`);
                          redisClient.del(`retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      }
                     
                      
                      //     redisClient.set(`retrieveCalleeDeclineIos-${payload.arg}@${payload.subdomain}`, JSON.stringify(retrieveUUIDS));
                    
                      
                             //Added 09-01-26
                    });
                  }

                        //Added 09-01-26
                  else {
                      const getUUIDS = await redisClient.get(`retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      console.log(`hey retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      if(getUUIDS){
                        console.log(`hey 2`);
                          const validUUIDS = JSON.parse(getUUIDS);
                          const aId = validUUIDS.aLeg;
                          const bId = validUUIDS.bLeg;
                          conn.api(`uuid_exists ${aId}`, (res) => console.log(`existA->☎️☎️ ${aId}`,res));
                          conn.api(`uuid_exists ${bId}`, (res) => console.log(`existB->☎️☎️ ${bId}`,res))
                          console.log("bridging... 6")
                          conn.api(`uuid_bridge ${aId} ${bId}`);
                          redisClient.del(`retrieveCalleeDecline-${arg}@${socket.handshake.auth.channel}`);
                          redisClient.del(`retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      }
                  }
                         //Added 09-01-26

                }
                // EMAN ADDED 08-26-26 END
              }
            }

          });

          // EMAN ADDED 08-11-26 START
          await redisClient.set(`prevent-push-ios-${socket.handshake.auth.pushToken}`, 1);
          await redisClient.expire(`prevent-push-ios-${socket.handshake.auth.pushToken}`, 3);
          // EMAN ADDED 08-11-26 START
          console.log("THIS IS IOS");

      }
      if (socket.handshake.auth.pushToken.length <= 32) {
        console.log("android")


         const aliasNameExtension2 = async () => {
                const { rows } = await db2.query(
                    `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                    [socket.handshake.auth.channel, socket.handshake.auth.extension]
                );
                const extensionOrigName = rows[0]?.number_alias;
                return extensionOrigName
          }


          const aliasName2 = await aliasNameExtension2();
          conn.bgapi(`show channels as json`, async (res) => {
            if (res.body != "-ERR No such channel!\n") {

              const { row_count, rows } = JSON.parse(res.body);
              if (row_count !== 0) {
                
                console.log("this is needed", aliasName2, arg, `${aliasName2}@${socket.handshake.auth.channel}`);
                console.log("this is rows for androind old rows", rows);
                if (rows.length) {

                  //Validator for finder validation
                  const targetCallState = rows[0].callstate
                   const sameCallState = rows.filter(row =>
                        row.cid_name === "DOWN" 
                       
                  );

                  // Multiple/Single extension validation
                  const targetCidName = rows[0].cid_name;
                  const targetCidNum = rows[0].cid_num;
                  const targetDirection = rows[0].direction;
                  const sameCaller = rows.filter(row =>
                        row.cid_name === targetCidName &&
                        row.cid_num === targetCidNum && 
                        row.direction === targetDirection
                  );

                  // Qeuue validation
                  const hasDestInRange = rows.some(row => {
                  const dest = Number(row.dest);
                    return (
                        row.direction === 'inbound' &&
                        !Number.isNaN(dest) &&
                        dest >= 9000 &&
                        dest <= 9500
                    );
                  });

                  //Extension finder validation
                  const findExtension = rows.find( (x) => 
                    (x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}` &&
                    x.callstate !== 'ACTIVE') ||
                    (x.initial_dest === arg && x.accountcode == `${socket.handshake.auth.channel}`)
                  );



                  console.log("android2");
                  if (findExtension) {
                    console.log("find extension", findExtension)
                    const { call_uuid, uuid } = findExtension;
                      if(call_uuid){

                          console.log(`🔥 ANDROID 🔥`, rows, call_uuid, `${arg}+${channel}`);
                          console.log("this is park 5")
                          // conn.api(`uuid_park ${call_uuid}`);
                          const callControlPosition = await redisClient.get(`${arg}+${channel}`);
                          console.log(`🔥`, callControlPosition);
                          if(callControlPosition){
                            
                            const positionNum = Number(callControlPosition)
                            const callControlBusyEnabled = await redisClient.lRange(`${arg}@${channel}-forward-busy-enabled`, positionNum, positionNum)
                            console.log(`🔥🔥`, positionNum, callControlBusyEnabled);

                            
                            if(callControlBusyEnabled[0] === 'true'){
                              console.log(`🔥🔥🔥`)
                              const callControlBusyDestination = await redisClient.lRange(`${arg}@${channel}-forward-busy-destination`, positionNum, positionNum);
                              if(callControlBusyDestination.length){
                                console.log("transfer 2SI")
                                  console.log(`🔥uuid_transfer ${call_uuid} ${callControlBusyDestination[0]} XML ${channel}`)
                                  conn.api(`uuid_transfer ${call_uuid} ${callControlBusyDestination[0]} XML ${channel}`);
                              }

                            }else{


                              console.log("transfer 3SI")
                              // conn.api(`uuid_transfer ${call_uuid} *99${arg} XML ${channel}`);
                            }

                          }

                      } 
                      else if(uuid){

                        
                          console.log(`🔥 ANDROID 🔥`, rows, uuid, `${arg}+${channel}`);
                          
                          console.log("this is park 1")
                          conn.api(`uuid_park ${uuid}`);
                          const callControlPosition = await redisClient.get(`${arg}+${channel}`);
                          console.log(`🔥`, callControlPosition);
                          if(callControlPosition){
                            
                            const positionNum = Number(callControlPosition)
                            const callControlBusyEnabled = await redisClient.lRange(`${arg}@${channel}-forward-busy-enabled`, positionNum, positionNum)
                            console.log(`🔥🔥`, positionNum, callControlBusyEnabled);
                            if(callControlBusyEnabled[0] === 'true'){
                              console.log(`🔥🔥🔥`)
                              const callControlBusyDestination = await redisClient.lRange(`${arg}@${channel}-forward-busy-destination`, positionNum, positionNum);
                              if(callControlBusyDestination.length){
                                  console.log("transfer 4SI")
                                  console.log(`🔥uuid_transfer ${uuid} ${callControlBusyDestination[0]} XML ${channel}`)
                                  conn.api(`uuid_transfer ${uuid} ${callControlBusyDestination[0]} XML ${channel}`);
                              }

                            }else{

                              //Modified 09-16-26
                              //Filter by transfer

                              // const findInitialDest = rows.find( (x) => 
                              //   (x.initial_dest === `${aliasName2}@${socket.handshake.auth.channel}` &&
                              //   x.callstate !== 'ACTIVE') ||
                              //   (x.initial_dest === arg && x.accountcode == `${socket.handshake.auth.channel}`)
                              // );

                               const callControlTimeOutEnabled = await redisClient.lRange(`${arg}@${channel}-forward-no-answer-enabled`, positionNum, positionNum);
                              
                               if (callControlTimeOutEnabled[0] === 'true') {

                                   const callControlTimeOutDestination = await redisClient.lRange(`${arg}@${channel}-forward-no-answer-destination`, positionNum, positionNum);
                                    
                                   if (callControlTimeOutDestination.length) {
                                    console.log("call forwarding")

                                  //  const {}
                                    
                                    // console.log("transfer 5SI", `uuid_transfer ${uuid} *99${arg} XML ${channel}`)
                                    console.log(`uuid_transfer ${uuid} ${callControlTimeOutDestination[0]} XML ${channel}`);
                                    conn.api(`uuid_transfer ${uuid} ${callControlTimeOutDestination[0]} XML ${channel}`);

                                    }
                                    else {
                                      console.log("transfer voicemail", `uuid_transfer ${uuid} *99${arg} XML ${channel}`)
                                      conn.api(`uuid_transfer ${uuid} *99${arg} XML ${channel}`);
                                    }
      
                              }

                              else{
                                console.log("not true")
                                  console.log("transfer voicemail", `uuid_transfer ${uuid} *99${arg} XML ${channel}`)
                                  conn.api(`uuid_transfer ${uuid} *99${arg} XML ${channel}`);
                              }

     


                              ////Modified 09-16-26
                            }

                          }
                      }
                  }

                  
                else if(!findExtension && sameCaller.length === 1 && !hasDestInRange){

                //    conn.bgapi(`show channels as json`, async (res) => {
                //       if (res.body != "-ERR No such channel!\n") {

                //       const { row_count, rows } = JSON.parse(res.body); 
                //       console.log("new rows", rows);
                //     } 
                // })

                    //for queue callee decline
                     console.log("hey thereee");
                     const findExtension2 = rows.find( (x) => 
                    //  (x.presence_id.includes(aliasName2)) || (x.dest === arg && x.context == `${socket.handshake.auth.channel}`)
                      (x.dest === arg && x.context == `${socket.handshake.auth.channel}`) && (x.direction == "inbound" && x.presence_id == "")
                  );
                  console.log("this is findextension 2", findExtension2);
                  console.log("this are rows", rows);
                  console.log("this is the other data", arg, `${socket.handshake.auth.channel}`);
                  if(findExtension2){
                  const { uuid } = findExtension2;
                    if (uuid){
                      console.log("transfer 6SI")
                      // conn.api(`uuid_transfer ${uuid} *99${arg} XML ${socket.handshake.auth.channel}`);
                      console.log("killing...");
                    }
                  }
                  else {
                  // Continue
               //Added 09-01-26
                       console.log("go hereee", `retrieveCalleeDecline-${arg}@${socket.handshake.auth.channel}`)
                      const getUUIDS = await redisClient.get(`retrieveCalleeDecline-${arg}@${socket.handshake.auth.channel}`);
                      if(getUUIDS){
                        const validUUIDS = JSON.parse(getUUIDS);
                        const aId = validUUIDS.aLeg;
                        const bId = validUUIDS.bLeg;
                        conn.api(`uuid_exists ${aId}`, (res) => console.log(`existA->☎️☎️ ${aId}`,res));
                        conn.api(`uuid_exists ${bId}`, (res) => console.log(`existB->☎️☎️ ${bId}`,res))
                        console.log("bridging... 7")
                        conn.api(`uuid_bridge ${aId} ${bId}`);
                        redisClient.del(`retrieveCalleeDecline-${arg}@${socket.handshake.auth.channel}`);
                        redisClient.del(`retrieveCalleeDeclineIos-${arg}@${socket.handshake.auth.channel}`);
                      }
                      else{

                        //Queue forward dest only
                        console.log("must not go threr");

                        

                      }
               ////Added 09-01-26

                  }

                  }


                }
              }
            }
          });

      } else if (socket.handshake.auth.pushToken.length <= 64) {

        // const presenceExt = `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`;
        // const aliasNameExtension2 = async () => {
        //       const { rows } = await db2.query(
        //           `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
        //           [socket.handshake.auth.channel, socket.handshake.auth.extension]
        //       );
        //       const extensionOrigName = rows[0]?.number_alias;
        //       return extensionOrigName
        // }
        // const aliasName2 = await aliasNameExtension2();

        // setTimeout(async () => {
        //   conn.api(`show channels as json`, async (res) => {
        //     if (res.body != "-ERR No such channel!\n") {

        //       const { row_count, rows } = JSON.parse(res.body);
        //       console.log(`🟡 IOS 🟡`, rows, aliasName2);
        //       if (row_count !== 0) {
        //         if (rows.length) {

        //           const filterUID = rows.filter( (x) => 
        //             x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}` || 
        //             x.dest === socket.handshake.auth.extension && x.accountcode === socket.handshake.auth.channel &&
        //             x.application_data === 'misc/falsering.wav'
        //           );

        //           if(filterUID.length){
        //             filterUID.forEach(element => {
        //               conn.api(`uuid_kill ${element.uuid} CALL_REJECTED`);
        //             });
        //           }
        //           // const findExtension = rows.find( (x) => x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}`);
        //           // if (findExtension) {
        //           //   const { call_uuid } = findExtension;
        //           //   if(call_uuid){
        //           //     conn.api(`uuid_kill ${call_uuid} CALL_REJECTED`);
        //           //   } 
        //           // }
        //         }
        //       }

        //     }
        //   });
        // }, 500);
        
      } else {



        const aliasNameExtension2 = async () => {
              const { rows } = await db2.query(
                  `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                  [socket.handshake.auth.channel, socket.handshake.auth.extension]
              );
              const extensionOrigName = rows[0]?.number_alias;
              return extensionOrigName
        }

        console.log('🟡 DECLINE WEB', arg, socket.handshake.auth);

        const aliasName2 = await aliasNameExtension2();
        conn.bgapi(`show channels as json`, async (res) => {
          if (res.body != "-ERR No such channel!\n") {
            const { row_count, rows } = JSON.parse(res.body);
            console.log(`🔥 WEB 🔥`, rows, aliasName2);
            if (row_count !== 0) {
              if (rows.length) {
                const findExtension = rows.find( (x) => x.presence_id === `${aliasName2}@${socket.handshake.auth.channel}`);
                console.log("1")
                if (findExtension) {
                  const { call_uuid } = findExtension;
                  if(call_uuid){
              
                      const callControlPosition = await redisClient.get(`${arg}+${channel}`);
                          console.log("2", `${arg}+${channel}`);
                      if(callControlPosition){
                        const positionNum = Number(callControlPosition)
                        console.log("3", `${arg}@${channel}-forward-busy-enabled`);
                        const callControlBusyEnabled = await redisClient.lRange(`${arg}@${channel}-forward-busy-enabled`, positionNum, positionNum)
                        if(callControlBusyEnabled === 'true'){

                          const callControlBusyDestination = await redisClient.lRange("1115@multi.flowersconradplc.ringq.cloud-forward-busy-destination", positionNum, positionNum);
                            console.log("5", "1115@multi.flowersconradplc.ringq.cloud-forward-busy-destination");
                          if(callControlBusyDestination){
                            console.log("transfer 7SI")
                              conn.api(`uuid_transfer ${call_uuid} ${callControlBusyDestination} XML ${channel}`);
                              console.log("4")
                          }

                        }
                      }

                  } 
                }
              }
            }
          }
        });

      }

    });

  socket.on("decline2", async (arg) => {
    console.log("received arg", arg);
  })

    socket.on("answered", async (arg) => {
      console.log('Answered Event Push', arg);



    });

    socket.on("answer", async (arg) => {
      console.log('Answered Event Push2', arg);
    });

    socket.on("supervisor", async (arg, callback) => {

      const { extension: extSupervisor,  channel } = socket.handshake.auth

      //arg = legB ext = legC
      console.log(`🟢 TAKE OVER ARGUMENT`, arg, extSupervisor, socket.handshake.auth);
      let trueArg;
      if(`${arg}@${socket.handshake.auth.channel}` === `${extSupervisor}@${socket.handshake.auth.channel}`) return

      const aliasNameExtension = async () => {
            const { rows } = await db2.query(
                `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                [socket.handshake.auth.channel, arg]
            );
            const extensionOrigName = rows[0]?.number_alias;
            return extensionOrigName
      }

      trueArg = arg

      if(arg.length !== 4){
        trueArg = extSupervisor;
      }

      console.log("value of truearg", trueArg);
      const alias = await aliasNameExtension();
      console.log("value of alias", alias);
      const uid = await redisClient.get(`que:${trueArg}@${socket.handshake.auth.channel}`);
      const uid2 = await redisClient.get(`que:${alias}@${socket.handshake.auth.channel}`);
      console.log("this is getting", `que:${trueArg}@${socket.handshake.auth.channel}`, `que:${alias}@${socket.handshake.auth.channel}`)
      const callId = uid2 || uid;
      console.log("supervisor....11")
      console.log("this is callId", callId);
      if (callId) {

        const res = await promisifiedApi(conn, "show calls as json");
        const { row_count, rows } = JSON.parse(res.body);
        if (!rows.length) return;
        console.log("this is rows", rows)

        // EMAN MODIFIED 09-17-26 START
        const findCall = rows.find(
          (x) => x.uuid == callId || x.b_uuid == callId || x.b_presence_id == `${alias}@${socket.handshake.auth.channel}` 
        );
        // EMAN MODIFIED 09-17-26 END
        
        console.log("this is rows", findCall);
        
        if(findCall){

            const { uuid } = findCall;
            redisClient.set(
              `currentQueueCallId${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
              JSON.stringify({ uid: callId, action: "takeOver", callerNum: trueArg })
            );
            redisClient.set(
              `superVisorCall${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
              uuid
            );
            conn.bgapi(`originate {presence_id=${`${extSupervisor}@${channel}`},accountcode=${channel},domain_name=${channel},nolocal:absolute_codec_string=OPUS,iLBC,G7221@32k,G722,G729,PCMU,PCMA,inherit_codec=true,hangup_after_bridge=false,origination_caller_id_name='Take-Over',origination_caller_id_number=${trueArg},eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/${extSupervisor}@${channel} &eavesdrop(${uuid})`);
            
            console.log(`👍 TAKEOVER3`, `originate {presence_id=${`${extSupervisor}@${channel}`},accountcode=${channel},domain_name=${channel},nolocal:absolute_codec_string=OPUS,iLBC,G7221@32k,G722,G729,PCMU,PCMA,inherit_codec=true,hangup_after_bridge=false,origination_caller_id_name='Take-Over',origination_caller_id_number=${trueArg},eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/${extSupervisor}@${channel} &eavesdrop(${uuid})`);
            // conn
            

        }
       
      }


    });

    socket.on("supervisor2", async (arg, callback) => {

      const { extension: extSupervisor,  channel } = socket.handshake.auth;
      console.log(`🟢 Coaching ARGUMENT`, arg, socket.handshake.auth);

      if(`${arg}@${socket.handshake.auth.channel}` === `${extSupervisor}@${socket.handshake.auth.channel}`) return

      const aliasNameExtension = async () => {
            const { rows } = await db2.query(
                `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                [socket.handshake.auth.channel, arg]
            );
            const extensionOrigName = rows[0]?.number_alias;
            return extensionOrigName
      }
      const alias = await aliasNameExtension();
      const uid = await redisClient.get("que:" + arg + "@" + socket.handshake.auth.channel);
      const uid2 = await redisClient.get("que:" + alias + "@" + socket.handshake.auth.channel);
      const callId = uid2 || uid;

      if (callId) {

        const res = await promisifiedApi(conn, "show calls as json");
        const { row_count, rows } = JSON.parse(res.body);
        if (!rows.length) return;

        // EMAN MODIFIED 09-17-26 START
        const findCall = rows.find(
          (x) => x.uuid == callId || x.b_uuid == callId || x.b_presence_id == `${alias}@${socket.handshake.auth.channel}`
        );
        // EMAN MODIFIED 09-17-26 END

        if(findCall){
          const { uuid, b_uuid } = findCall;
          redisClient.set(
            `currentQueueCallId${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            JSON.stringify({ uid: callId, action: "Coaching", callerNum: arg })
          );
          redisClient.set(
            `superVisorCall${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            uuid
          );

          conn.bgapi(`originate {presence_id=${`${extSupervisor}@${channel}`},accountcode=${channel},domain_name=${channel},nolocal:absolute_codec_string=OPUS,iLBC,G7221@32k,G722,G729,PCMU,PCMA,inherit_codec=true,hangup_after_bridge=false,origination_caller_id_name='Coaching',origination_caller_id_number=${arg},eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=false,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/${extSupervisor}@${channel} &eavesdrop(${b_uuid})`);
        }

      }
    });

    socket.on("supervisor3", async (arg, callback) => {
      const { extension: extSupervisor,  channel } = socket.handshake.auth
      console.log(`🟢 Observe ARGUMENT`, arg, socket.handshake.auth);

      if(`${arg}@${socket.handshake.auth.channel}` === `${extSupervisor}@${socket.handshake.auth.channel}`) return

      const aliasNameExtension = async () => {
        const { rows } = await db2.query(
            `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
            [socket.handshake.auth.channel, arg]
        );
        const extensionOrigName = rows[0]?.number_alias;
        return extensionOrigName
      }
      const alias = await aliasNameExtension();
      const uid = await redisClient.get("que:" + arg + "@" + socket.handshake.auth.channel);
      const uid2 = await redisClient.get("que:" + alias + "@" + socket.handshake.auth.channel);
      const callId = uid2 || uid;

      
      if (callId) {
        const res = await promisifiedApi(conn, "show calls as json");
        const { row_count, rows } = JSON.parse(res.body);
        if (!rows.length) return;
        // EMAN MODIFIED 09-17-26 START
        const findCall = rows.find(
          (x) => x.uuid == callId || x.b_uuid == callId || x.b_presence_id == `${alias}@${socket.handshake.auth.channel}`
        );
        // EMAN MODIFIED 09-17-26 END
        if(findCall){

          const { uuid } = findCall;
          redisClient.set(
            `currentQueueCallId${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            JSON.stringify({ uid: callId, action: "Observe", callerNum: arg })
          );
          redisClient.set(
            `superVisorCall${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            uuid
          );
          conn.bgapi(`originate {presence_id=${`${extSupervisor}@${channel}`},accountcode=${channel},domain_name=${channel},nolocal:absolute_codec_string=OPUS,iLBC,G7221@32k,G722,G729,PCMU,PCMA,inherit_codec=true,hangup_after_bridge=false,origination_caller_id_name='Observe',origination_caller_id_number=${arg},eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=false,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=false}user/${extSupervisor}@${channel} &eavesdrop(${uuid})`);
        
        }

      }
    });

    socket.on("auxcode", async (arg, callback) => {
      if (socket.handshake.auth.hasOwnProperty("extension")) {
        
        console.log("🛜<!-- THIS IS CALLED AUXCODE🛜",arg , JSON.stringify(socket.handshake.auth));
        const {extension, channel, pushToken, device_uuid} = socket.handshake.auth
        const presenceExtension = `${extension}@${channel}`;

        console.log(`👇🏻 WALL STATUS2`, "wallstatus:" +
          socket.handshake.auth.channel +
          ":" +
          socket.handshake.auth.extension,
        1)

        await redisClient.set(
          "wallstatus:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension,
          arg
        );

        if (arg == 1) {
          await redisClient.set(
            "presenceId:" +
              socket.handshake.auth.channel +
              ":" +
              socket.handshake.auth.extension +
              ":" +
              socket.handshake.auth.pushToken,
            "1"
          );

          if (socket.conn.transport.name === "websocket") {
            redisClient.del(
              `confimrPush-${
                socket.handshake.auth.channel +
                ":" +
                socket.handshake.auth.extension +
                ":" +
                socket.handshake.auth.pushToken
              }`
            );
          }

          if (socket.handshake.auth.pushToken.length === 64) {
            /** REMOVE IOS DEVICE PUSH TOKEN */
            console.log(`❄️ iosDevicePushToken ❄️`, presenceExtension, pushToken)
            const callId = await redisClient.get(`${channel}${extension}-RingQ`);
            conn.api(`uuid_exists ${callId}`, async ({body}) => {
                await redisClient.sRem(`iosDevicePushToken-${presenceExtension}`, pushToken);
            });
            await redisClient.del(`iosAux-${presenceExtension}:${pushToken}`)
            await redisClient.set(`devicePushtoken-${device_uuid}`, pushToken);

          }

        }

        if (arg == 4) {


        const aliasNameExtension = async () => {
          const { rows } = await db2.query(
                `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                [socket.handshake.auth.channel, socket.handshake.auth.extension]
          );
          if(rows.length){
            const extensionOrigName = rows[0]?.number_alias;
            return extensionOrigName
          }
          return extension
        }

        const value = await getRegistrationInternalReg();
        const destinationAlias = await aliasNameExtension();
        const filterPresence = value.filter(data => data['Auth-User'] === destinationAlias);

        console.log(`😎`, filterPresence);
          
        if(!filterPresence.length){
          console.log(`😎😎`);
          wallboard
            .to(socket.handshake.auth.channel)
            .emit("status", {
              id: socket.id,
              extension: socket.handshake.auth.extension,
              status: arg,
              auxcode: "Logged Out",
          });
        }

          console.log("🪫<!-- SOCKET DISCONNECT🪫" + JSON.stringify(socket.handshake.auth));

          if (socket.handshake.auth.pushToken.length === 64) {
            /** IOS DEVICE PUSH TOKEN */
            console.log(`🔥 iosDevicePushToken 🔥`, presenceExtension, socket.handshake.auth.pushToken)
            // await redisClient.sAdd(`iosDevicePushToken-${presenceExtension}`, pushToken);

            const key = `iosDevicePushToken-${presenceExtension}`;
            await redisClient.sAdd(key, pushToken);
            await redisClient.expire(key, 3 * 24 * 60 * 60);

            const tokens = await redisClient.sMembers(key);

            console.log("this is the token", tokens);

            await redisClient.set(`iosTokenActive-${presenceExtension}:${pushToken}`, 1, { EX: 172800 });
            await redisClient.set(`iosAux-${presenceExtension}:${pushToken}`, 1, {EX:172800})
          }

          await redisClient.del(
            "presenceId:" +
              socket.handshake.auth.channel +
              ":" +
              socket.handshake.auth.extension +
              ":" +
              socket.handshake.auth.pushToken
          );

          if (socket.conn.transport.name === "websocket") {
            redisClient.set(
              `confimrPush-${
                socket.handshake.auth.channel +
                ":" +
                socket.handshake.auth.extension +
                ":" +
                socket.handshake.auth.pushToken
              }`,
              1
            );
          }
        }
      }
    });

    socket.on("logoutStatus", async (arg, callback) => {

      const {subdomain, extension, status, deviceId} = arg;
      if(status === 1){
          wallboard
          .to(subdomain)
          .emit("status", {
            id: "",
            extension: extension,
            status: status,
            status2:'Available'
          });
      }

    });

    socket.on("logoutStatus2", async (arg, callback) => {

      const {subdomain, extension, status, deviceId, pushToken} = arg
      if(!Object.keys(socket.handshake.auth).length){
          const allKeys =  await redisClient.sMembers(`deviceOff-${extension}@${subdomain}`);
          if(allKeys.length){
            for (const element of allKeys) {
              console.log(`🇩🇪`, element);
              await redisClient.sRem(`deviceOff-${extension}@${subdomain}`, element);
              await redisClient.get(`androidPresence-${element}-${extension}@${subdomain}`);
            }
          }
      }

      const callId = await redisClient.get(`deviceUID-${deviceId}`);
      console.log('This is called logoutStatus', arg, socket.handshake.auth, callId);

      if (callId) {
        conn.api(`sofia profile internal flush_inbound_reg ${callId}`, async (res) => {
          if(res.body === '+OK flushing all registrations matching specified call_id\n'){
            console.log('REMOVE PRESENCE IN REG', socket.handshake.auth);

                await new Promise((r) => setTimeout(r, 5000));
                // EMAN ADDED 08-18-26 START
                if (pushToken) {
                  await redisClient.sRem(`iosDevicePushToken-${extension}@${subdomain}`, pushToken);
                }
                // EMAN ADDED 08-18-26 END

                function getRegistration() {
                    return new Promise((resolve, reject) => {
                          conn.api(`sofia status profile internal reg`, (reg) => {
                              try {
                                    function parseLog(log) {
                                        const blocks = log.trim().split(/\n\s*\n/); // split by empty line
                                        const registrations = [];
                                        let total = 0;

                                        for (const block of blocks) {
                                        if (block.startsWith("Total items")) {
                                              total = parseInt(block.replace(/\D+/g, ""), 10);
                                              continue;
                                        }

                                        const entry = {};
                                        block.split("\n").forEach(line => {
                                              const match = line.match(/^(.*?):\s*\t?(.*)$/);
                                              if (match) {
                                                  const key = match[1].trim();
                                                  const value = match[2].trim();
                                                  entry[key] = value;
                                              }
                                        });
                                        registrations.push(entry);
                                        }

                                        return { Registrations: registrations, Total: total };
                                    }

                                    const result = parseLog(reg.body);
                                    if (result) {
                                        resolve(result.Registrations); // return the match properly
                                    } else {
                                        resolve(null); // no match found
                                    }

                              } catch (err) {
                                    reject(err);
                              }
                          });
                    });
                }

                const aliasNameExtension = async () => {
                    const { rows } = await db2.query(
                          `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                          [subdomain, extension]
                    );
                    if(rows.length){
                      const extensionOrigName = rows[0]?.number_alias;
                      return extensionOrigName
                    }
                    return extension
                }

                const value = await getRegistration();
                const destinationAlias = await aliasNameExtension();
                const filterPresence = value.filter(data => data['Auth-User'] === destinationAlias);
                console.log('This is sofia internal reg destroy', filterPresence, destinationAlias);
                
                if(!filterPresence.length){
                  wallboard
                  .to(subdomain)
                  .emit("status", {
                    id: "",
                    extension: extension,
                    status: status,
                    status2: "Logged Out"
                  });
                }

          }
        });
      }

      // wallboard
      // .to(arg["c"])
      // .emit("status", {
      //   id: "",
      //   extension: arg["extension"],
      //   status: arg["status"],
      //   status2: arg["status"] == 1 ? 'Available':'Logged Out'
      // });

    });

    socket.on("callProcessStatus", async (arg, callback) => {
      
      //   if (parseInt(arg["caller"]) < 2000 && parseInt(arg["caller"]) >= 10 || parseInt(arg["caller"]) < 9000 && parseInt(arg["caller"]) >= 7000) {
      //       await redisClient.set("callstatus:" + arg["channel"] + ":" + arg["caller"], arg["status"]);
      //   }
      //   if (parseInt(arg["callee"]) < 2000 && parseInt(arg["callee"]) >= 10 || parseInt(arg["callee"]) < 9000 && parseInt(arg["callee"]) >= 7000) {
      //       await redisClient.set("callstatus:" + arg["channel"] + ":" + arg["callee"], arg["status"]);
      //   }

      console.log(arg["channel"], {
        caller: arg["caller"],
        callee: arg["callee"],
        status: arg["status"],
      });
      //   wallboard.to(arg["channel"]).emit("call", {caller: arg["caller"], callee: arg["callee"], status: arg["status"]});

    });

    // socket.on("wallboardStatus", (res) => {
    //   if (res) {
    //     console.log(`Wallboard Emit`, res);
    //     // wallboard.to(res.subdomain).emit("wallboard", res.data);
    //   }
    // });

    // socket.on("wallboardCallStatus", (res) => {
    //   console.log(`🦅`, res, socket.id);
    //   wallboard.to(res.subdomain).emit("queueCallboard", res.data);
    // });

    // socket.on("wallboardConfigStatus", (res) => {
    //   wallboard.to(res.subdomain).emit("queueConfigBoard", res.data);
    // });

    // socket.on("wallboardAgentStatus", (res) => {
    //   console.log(`This is called agents Status`, res);
    //   wallboard.to(res.subdomain).emit("queueAgentboard2", res.data);
    // });

    /** For Filter Queue */
    socket.on("wallboardStatus", (res) => {
      if (res) {
        console.log(`Wallboard Emit`, res);
        wallboard.to(res.subdomain).emit("wallboard", res.data);
      }
    });

    socket.on("wallboardCallStatus", async (res, subdomain) => {
      console.log(`🦅`, res, socket.id);

      const queueCallBoardList = await redisClient.lRange(`queueCallBoard-${subdomain}`, 0, -1);
      if(queueCallBoardList.length > 0){

        const newQueueCallBoardList = queueCallBoardList
          .map(el => {
            try {
                  return JSON.parse(el);
              } catch (error) {
                  console.log("Invalid queueCallBoard JSON:", el);
                  return null;
              }
          }).filter(Boolean);

          console.log("this are the actual data queueCallboard", newQueueCallBoardList);
      }
      else{
        console.log("no callboard")
      }

    
      if(res.data.length == 0 || res.data == "[]"){
        // const getCallBoard = await redisClient.get(`wallboardAddAnswer-${res.subdomain}`);
        // console.log("retrieving",`wallboardAddAnswer-${res.subdomain}`)

        // if(getCallBoard) {
          wallboard.to(res.subdomain).emit("queueCallboard", JSON.stringify([]));
        //   return;
        // }
        //Added 09-14-26
        // const dataCallBoard = JSON.parse(getCallBoard);


       
        // if(!dataCallBoard[0].session_uuid){
        //   const getData = 
        // }
        

      //   //InsertEachData
      // const dataCallBoard = JSON.parse(getCallBoard);
      // let totalNewCallBoard = [];
      // // let getType = [];
      // // let typePayload = {};
      // console.log("this is dataCallBoard", dataCallBoard);
      // for (const item of dataCallBoard) {
      //   const channelQueue = item.queue;
      //   const savedAnsweredRaw = await redisClient.get(`broadcastAnsweredQueue:${channelQueue}`);

      //   if (!savedAnsweredRaw) continue;

      //   console.log("get this", savedAnsweredRaw)
      //   const savedAnswered = JSON.parse(savedAnsweredRaw);

      //   // if (!item.session_uuid) {
      //     item.session_uuid = savedAnswered.session_uuid;
      //   // }

      //   // if (!item.serving_agent) {
      //     item.serving_agent = savedAnswered.serving_agent;
      //   // }

      //   console.log("this ITEM", item);
      //   totalNewCallBoard.push(item)
      //   // if (item.state === "Answered") {
      //   //   item.bridge_epoch = item.bridge_epoch || Math.floor(Date.now() / 1000);
      //   // 

      //   redisClient.del(`broadcastAnsweredQueue:${channelQueue}`);
      //   // console.log("deleting this", `broadcastAnsweredQueue:${channelQueue}`)
      //   // const typeQueue = redisClient.get(`broadcastAnsweredQueue:${channelQueue}:type`)

      //   // if(typeQueue){
      //   //   if(typeQueue == "open"){
      //   //     console.log("do nothing open")
      //   //      typePayload = {
      //   //       queue: channelQueue,
      //   //       type: typeQueue
      //   //     }
      //   //   }  
      //   //   else if(typeQueue == "push"){
      //   //       typePayload = {
      //   //       queue: channelQueue,
      //   //       type: typeQueue
      //   //     }
      //   //   }

      //   //   getType.push(typePayload);
      //   // }
      // }


      
      //   redisClient.set(`${res.subdomain}:queueWallboardCallStatus`, JSON.stringify(totalNewCallBoard))
      //   console.log("this is now the new data", `${res.subdomain}:queueWallboardCallStatus`, JSON.stringify(totalNewCallBoard));
      //   wallboard.to(res.subdomain).emit("queueCallboard", JSON.stringify(totalNewCallBoard));
      //   console.log("sending to  🦅", "queueCallboard", JSON.stringify(totalNewCallBoard));
      //   redisClient.del(`wallboardAddAnswer-${res.subdomain}`);
      }
      else{
        console.log("this is the data 2 🦅", res.data);
         wallboard.to(res.subdomain).emit("queueCallboard", res.data);
      }

     
    });

    socket.on("wallboardConfigStatus", (res) => {
      console.log(`🦅🦅🦅 wallboardConfigStatus`, res, socket.id)
      wallboard.to(res.subdomain).emit("queueConfigBoard", res.data);
    });

    socket.on("wallboardAgentStatus", (res) => {
      console.log(`This is called agents Status`, res);
      wallboard.to(res.subdomain).emit("queueAgentboard2", res.data);
    });

    socket.on("queueList2", async (arg, callback) => {
      // Wallboard Filter Event
      console.log(`This is called QUEUE LIST FILTER EVENT`, arg)
      socketQueueServer.emit("wallBoardButton", socket.handshake.auth.channel);
    });

    // EMAN ADDED AUG. 27, 2025 START
    socket.on("sshCommand", async (arg, callback) => {
      sshSocketServer.emit("command", {
        subdomain: socket.handshake.auth.channel, 
        command: arg,
      });
    });

    socket.on("sshInterrupt", async (arg, callback) => {
      sshSocketServer.emit("interrupt", {
        subdomain: socket.handshake.auth.channel,
        command: arg,
      });
    });

    socket.on("sshOutput", async (arg, callback) => {
      wallboard.to(arg.subdomain).emit("sshOutput", arg.command);
    });

    // EMAN ADDED AUG. 27, 2025 END
    socket.on("queueListReset", async (res) => {
      await Promise.all([
        redisClient.hSet(`walldata:${socket.handshake.auth.channel}`, {
          total_wallboard: 0,
          answered_wallboard: 0,
          waiting_wallboard: 0,
          abandoned_wallboard: 0,
          missed_calls: 0,
          answered_calls: 0,
          total: 0, 
          active: 0, 
          waiting: 0, 
          abandoned: 0, 
        }),
        redisClient.del(`queueCallBoard-${socket.handshake.auth.channel}`),
      ]);

      const wallboardReset = await redisClient.hGetAll(
        `walldata:${socket.handshake.auth.channel}`
      );
      wallboard
      .to(socket.handshake.auth.channel)
      .emit("wallboard", wallboardReset);

      redisClient.set(
        `${socket.handshake.auth.channel}:queueWallboardCallStatus`,
        JSON.stringify([])
      );

      wallboard
        .to(socket.handshake.auth.channel)
        .emit("queueCallboard", JSON.stringify([]));

        /** Agent Wallboard */

        wallboard.to(socket.handshake.auth.channel).emit(`${socket.handshake.auth.channel}:queueWallboardAgentBoard2`, JSON.stringify([]));

        await new Promise((resolve, reject) => {

          db2.query(
            `UPDATE agents 
            SET last_bridge_start = $1, 
            last_bridge_end = $2,
            last_offered_call = $3,
            last_status_change = $4,
            no_answer_count = $5,
            calls_answered = $6,
            talk_time = $7,
            ready_time = $8,
            state = $9
            WHERE contact LIKE $10`,
            [0, 0, 0, 0, 0, 0, 0, 0, 'Waiting', `%${socket.handshake.auth.channel}%`],
            (err) => {
              if (err) {
                console.error("Error updating agent stats:", err.message);
                return reject(err);
              }
            }
          );

          db2.query(
            `UPDATE agent_details 
            SET no_answer_count = $1, 
            calls_answered = $2,
            talk_time = $3,
            state = $4
            WHERE contact LIKE $5`,
            [0, 0, 0, 'Waiting',`%${socket.handshake.auth.channel}%`],
            (err, result) => {
              if (err) {
                console.error("Error updating agent stats:", err.message);
                return reject(err);
              }
              
              resolve(result);
            }
          );
          
        })
        .then( async () => {

          const [agentBody, tierBody] = await Promise.all([
              new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
              new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
          ]);

          const agentList = JSON.parse(agentBody.body).response;
          const tierList  = JSON.parse(tierBody.body).response;
          const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

          const matched = tierList.reduce((acc, tier) => {
          const agent = agentMap[tier.agent];
              if (
                  agent && 
                  agent.contact.includes(socket.handshake.auth.channel)
              ) acc.push(agent);
              return acc;
          }, []);
          console.log("✅ matched:", matched);

          if(matched.length){

              const names = matched.map(e => e.name);
              const { rows } = await db2.query(
                  `SELECT 
                      ad.name,
                      ad.contact,
                      ad.max_no_answer,
                      ad.wrap_up_time,
                      ad.reject_delay_time,
                      ad.busy_delay_time,
                      ad.no_answer_delay_time,
                      ad.last_bridge_start,
                      ad.last_bridge_end,
                      ad.last_offered_call,
                      ad.last_status_change,
                      ad.no_answer_count,
                      ad.calls_answered, 
                      ad.talk_time,
                      ad.ready_time,
                      ad.external_calls_count,
                      ve.extension
                  FROM agent_details ad
                  LEFT JOIN v_extensions ve 
                      ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                  WHERE ad.name = ANY($1)`,
                  [names]
              );

              // Map results by name for O(1) lookup
              const dbMap = rows.reduce((acc, row) => {
                  acc[row.name] = row;
                  return acc;
              }, {});

              const newPayload = matched.map(element => {
                  const data = dbMap[element.name];

                  if(data){
                    const { 
                      contact,
                      max_no_answer,
                      wrap_up_time,
                      reject_delay_time,
                      busy_delay_time,
                      no_answer_delay_time,
                      last_bridge_start,
                      last_bridge_end,
                      last_offered_call,
                      last_status_change,
                      no_answer_count,
                      calls_answered, 
                      talk_time,
                      ready_time,
                      external_calls_count,
                      extension 
                    } = data;
                    const newContact = extension ? updateContact(contact, extension) : contact;
                    return {
                        ...element,
                        contact: newContact,
                        max_no_answer: Number(max_no_answer),
                        wrap_up_time: Number(wrap_up_time),
                        reject_delay_time: Number(reject_delay_time),
                        busy_delay_time: Number(busy_delay_time),
                        no_answer_delay_time: Number(no_answer_delay_time),
                        last_bridge_start: Number(last_bridge_start),
                        last_bridge_end: Number(last_bridge_end),
                        last_offered_call: Number(last_offered_call),
                        last_status_change: Number(last_status_change),
                        no_answer_count: Number(no_answer_count),
                        calls_answered: Number(calls_answered),
                        talk_time: Number(talk_time),
                        ready_time: Number(ready_time),
                        external_calls_count: Number(external_calls_count)
                    };
                  }
                 
              });
              
              const filteredAgent = newPayload.filter(Boolean);
              console.log('👉🏻👉🏻', JSON.stringify(filteredAgent));
            

              await redisClient.set(
                `${socket.handshake.auth.channel}:queueWallboardAgentBoard2`,
                JSON.stringify(filteredAgent)
              );
              wallboard
              .to(socket.handshake.auth.channel)
              .emit("queueAgentboard2", JSON.stringify(filteredAgent));
            
          }
          
        })
        .catch((err) => {
          console.error("❌ Failed to reset agent wallboard:", err.message);
        });

        /** Queue Details */
        await new Promise((resolve, reject) => {
          db2.query(
            `UPDATE member_board 
            SET answered_seconds = $1, 
            abandoned_seconds = $2, 
            answered_calls = $3, 
            missed_calls = $4, 
            talk_time = $5, 
            abandoned_wallboard = $6,
            total_wallboard = $7, 
            answered_wallboard = $8, 
            waiting_wallboard = $9,
            waiting_seconds = $10 
            WHERE queue LIKE $11`,
            [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, `%${socket.handshake.auth.channel}%`],
            (err, result) => {
              if (err) {
                console.error(
                  "Error resetting queue details stats:",
                  err.message
                );
                return reject(err);
              }
              console.log(
                `✅ Queue Details reset: ${result.rowCount} row(s) updated`
              );
              resolve(result);
            }
          );
        })
        .then((value) => {

          conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, async ({ body }) => {
            const { status, response } = JSON.parse(body);
            console.log(`STATUS CALLS`, response);
            if (status === "success") {
              if (response.length) {
                const names = response
                .filter(e => e.name.includes(socket.handshake.auth.channel))
                .map(e => e.name);

                if (!names.length) return;

                db2.query(
                  `SELECT queue, answered_seconds, abandoned_seconds, talk_time, answered_calls,
                  missed_calls, waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                  FROM member_board WHERE queue = ANY($1)`,
                  [names],
                  async (err, result) => {
                    if (err) {
                      console.error("Error fetching data:", err.message);
                      return;
                    }
                    const rows = result.rows;
                    await redisClient.set(
                      `${socket.handshake.auth.channel}:queueWallboardConfigBoard`,
                      JSON.stringify(rows)
                    );
                    wallboard
                    .to(socket.handshake.auth.channel)
                    .emit("queueConfigBoard", JSON.stringify(rows));
                  }
                );

              }
            }
          });

        })
        .catch((err) => {
          console.error(
            "❌ Failed to reset queue details wallboard:",
            err.message
          );
        }).finally(() => {
          conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, ({ body }) => {
            const { status, response} = JSON.parse(body)
            if(status === 'success'){
                const agentNames = response.filter(el => el.name.includes(socket.handshake.auth.channel))
                agentNames.forEach( ({name}) => {
                    conn.api(`callcenter_config queue reload ${name}`);
                    console.log('This is called finally reset queue2', name);
                });
            }     
          });
        });
    });



    socket.on("broadcast2", async (arg, callback) => {
      // queueDataWallboard(arg["channel"]);
      db2.query(
        "SELECT queue, uuid, session_uuid, cid_number, cid_name, system_epoch, joined_epoch, rejoined_epoch, bridge_epoch, abandoned_epoch, base_score, skill_score, serving_agent, serving_system, state FROM members WHERE queue LIKE '%" +
          arg["channel"] +
          "' ORDER BY queue ASC",
        async (err, result) => {
          const rows = result.rows;
          rows.forEach(async (element_members) => {
            if (element_members.state === "Trying") {
              await redisClient.set(
                `queueJoinedEpoch-${element_members.queue}`,
                JSON.stringify({
                  uuid: element_members.uuid,
                  epoch: element_members.joined_epoch,
                })
              )}
          });
      });

      let response2 = [];

      const arraybroadcast4 = JSON.stringify(arg.agentSettings);
      const lines2 = arraybroadcast4.split("\\n");

      for (var x = 1; x < lines2.length; x++) {
        response2.push(lines2[x].split("|"));
      }

      let myArray2 = response2.slice(0, -2);
      let filteredArray2 = myArray2.filter(
        (item) => !["+OK", '"'].includes(item[0])
      );

      for (const dataOfQueue of filteredArray2) {
        if (dataOfQueue[6] == "Receiving") {
          console.log(
            "<!-- This is called receiving",
            `${arg["extension"]}@${arg["channel"]}_agent_assign`
          );
          await redisClient.set(
            `${arg["extension"]}@${arg["channel"]}_agent_assign`,
            JSON.stringify(filteredArray2)
          );
        }
      }
    });

    socket.on("broadcast4", async (arg, callback) => {
      
      wallboard
        .to(arg.subdomain)
        .emit(
          "broadcast4",
          JSON.stringify({
            extension: arg.extension,
            wrap_up: arg.wrap_up,
            queue_script: arg.queue_script,
          })
        );
      // await redisClient.hSet("queueSettings:"+ arg["extension"] +"@"+ arg["channel"], "wrap_up", arg["wrap_up"]);
      // await redisClient.hSet("queueSettings:"+ arg["extension"] +"@"+ arg["channel"], "queue_script", arg["queue_script"]);
      // await redisClient.hSet("queueSettings:"+ arg["extension"] +"@"+ arg["channel"], "queue_manager", arg["queue_manager"]);
    });

    // $client->emit("broadcast5", ["extension" => $parameters["queue_extension"], "subdomain" =>
    // $session["subdomain"], "agents" => $extensions, "type" => 1]);

    socket.on("broadcast5", async (arg, callback) => {

      console.log(`BROADCAST5`, arg);
      const queuePresenceOrig = `${arg.extension}@${arg.subdomain}`;

      wallboard.to(arg.subdomain).emit("broadcast5",JSON.stringify({
          extension: arg.extension,
          agents: arg.agents,
          agents2: arg.agents2,
          type: arg.type
      }));

      if (arg.type === 1) {

        console.log('ADD QUEUE', arg);
        conn.api(`callcenter_config queue reload ${queuePresenceOrig}`);

        const [agentBody, tierBody] = await Promise.all([
            new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
            new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
        ]);

        const agentList = JSON.parse(agentBody.body).response;
        const tierList  = JSON.parse(tierBody.body).response;
        const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

        const matched = tierList.reduce((acc, tier) => {
            const agent = agentMap[tier.agent];
            if (
                agent && 
                agent.contact.includes(arg.subdomain)
            ) acc.push(agent);
            return acc;
        }, []);

        console.log("✅ matched:", matched);

        if(matched.length){

          const names = matched.map(e => e.name);
          const { rows: rows2 } = await db2.query(
              `SELECT name FROM agent_details WHERE name != ALL($1) AND contact LIKE $2`,
              [names, `%${arg.subdomain}%`]
          );

          const { rows: rowsTest } = await db2.query(
              `SELECT name FROM agent_details WHERE contact LIKE $1`,
              [`%${arg.subdomain}%`]
          );

          console.log("✅ matched:", names, rowsTest, matched);


          if(rowsTest.length){

            const agentsToExists = rowsTest.map(({ name }) => name);
            const filterName = matched.map((e) => {
              if(!agentsToExists.includes(e.name)){
                return e
              }
            }).filter(Boolean);
            console.log("✅ exists:", filterName);

            if(filterName.length){

              const values = filterName.map((el, i) => {
                const base = i * 22;
                return `($${base+1}, $${base+2}, $${base+3}, $${base+4}, $${base+5}, $${base+6}, $${base+7}, $${base+8}, $${base+9}, $${base+10}, $${base+11}, $${base+12}, $${base+13}, $${base+14}, $${base+15}, $${base+16}, $${base+17}, $${base+18}, $${base+19}, $${base+20}, $${base+21}, $${base+22})`;
              }).join(', ');

              const params = filterName.flatMap(el => [
                el.name, el.instance_id, el.uuid, el.type, el.contact, el.status,
                el.state, el.max_no_answer, el.wrap_up_time, el.reject_delay_time, el.busy_delay_time,
                el.no_answer_delay_time, el.last_bridge_start, el.last_bridge_end, el.last_offered_call,
                el.last_status_change, el.no_answer_count, el.calls_answered, el.talk_time, el.ready_time,
                el.external_calls_count, new Date()
              ]);
              console.log("✅ BATCH DATA", values, params);

              for (const el of filterName) {

                const { name } = el;
                const agentDetailsDB = await db2.query(
                  "SELECT EXISTS(SELECT 1 FROM agent_details WHERE name = $1)",
                    [name]
                );
                const exists = agentDetailsDB.rows[0].exists;
                if(!exists){

                  try {
                    await db2.query(
                        `INSERT INTO agent_details (
                            name, instance_id, uuid, type, contact, status,
                            state, max_no_answer, wrap_up_time, reject_delay_time, busy_delay_time,
                            no_answer_delay_time, last_bridge_start, last_bridge_end, last_offered_call,
                            last_status_change, no_answer_count, calls_answered, talk_time, ready_time,
                            external_calls_count, update_date
                        ) VALUES (
                            $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11,
                            $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22
                        )`,
                        [
                        el.name,
                        el.instance_id,
                        el.uuid,
                        el.type,
                        el.contact,
                        el.status,
                        el.state,
                        el.max_no_answer,
                        el.wrap_up_time,
                        el.reject_delay_time,
                        el.busy_delay_time,
                        el.no_answer_delay_time,
                        el.last_bridge_start,
                        el.last_bridge_end,
                        el.last_offered_call,
                        el.last_status_change,
                        el.no_answer_count,
                        el.calls_answered,
                        el.talk_time,
                        el.ready_time,
                        el.external_calls_count,
                        new Date(),
                        ]
                    );
                    console.log(
                        "Agent_details inserted/updated successfully"
                    );

                    await db2.query(
                      `INSERT INTO member_board (
                          queue, answered_seconds, abandoned_seconds, answered_calls, missed_calls, talk_time,
                          waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                      ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
                      ON CONFLICT (queue) DO NOTHING`,
                      [`${queuePresenceOrig}`,0,0,0,0,0,0,0,0,0,0],
                    );
                    
                  } catch (error) {
                      console.error("Error inserting data agent_details/member_board:", error);
                  }

                };
            
              }

            }

          }else{

            try {

              conn.api(`json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${queuePresenceOrig}"}}`, 
              async ({body}) => {
                const {response} = JSON.parse(body);
                if(response.length){
                    console.log("✅ NOT EXIST IN DB", response);

                    for (const el of response) {

                        const { name } = el;
                        const agentDetailsDB = await db2.query(
                          "SELECT EXISTS(SELECT 1 FROM agent_details WHERE name = $1)",
                            [name]
                        );
                        const exists = agentDetailsDB.rows[0].exists;
                        if(!exists){

                          try {
                            await db2.query(
                                `INSERT INTO agent_details (
                                    name, instance_id, uuid, type, contact, status,
                                    state, max_no_answer, wrap_up_time, reject_delay_time, busy_delay_time,
                                    no_answer_delay_time, last_bridge_start, last_bridge_end, last_offered_call,
                                    last_status_change, no_answer_count, calls_answered, talk_time, ready_time,
                                    external_calls_count, update_date
                                ) VALUES (
                                    $1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11,
                                    $12, $13, $14, $15, $16, $17, $18, $19, $20, $21, $22
                                )`,
                                [
                                el.name,
                                el.instance_id,
                                el.uuid,
                                el.type,
                                el.contact,
                                el.status,
                                el.state,
                                el.max_no_answer,
                                el.wrap_up_time,
                                el.reject_delay_time,
                                el.busy_delay_time,
                                el.no_answer_delay_time,
                                el.last_bridge_start,
                                el.last_bridge_end,
                                el.last_offered_call,
                                el.last_status_change,
                                el.no_answer_count,
                                el.calls_answered,
                                el.talk_time,
                                el.ready_time,
                                el.external_calls_count,
                                new Date(),
                                ]
                            );
                            console.log(
                                "Agent_details inserted/updated successfully"
                            );

                            await db2.query(
                              `INSERT INTO member_board (
                                  queue, answered_seconds, abandoned_seconds, answered_calls, missed_calls, talk_time,
                                  waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                              ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
                              ON CONFLICT (queue) DO NOTHING`,
                              [`${queuePresenceOrig}`,0,0,0,0,0,0,0,0,0,0],
                            );
                            
                          } catch (error) {
                              console.error("Error inserting data agent_details/member_board:", error);
                          }

                        };
                    
                    }


                }
               
              });
              
            } catch (error) {
                console.error("Error inserting data agent_details/member_board:", error);
            }

          }

          const agentsToRemove = rows2.map(row => ({
            name: row.name,
          }));

          const { rows: rows3 } = await db2.query(
              `SELECT name FROM agents WHERE name != ALL($1) AND contact LIKE $2`,
              [names, `%${arg.subdomain}%`]
          );
          const agentsToRemove3 = rows3.map(row => ({
            name: row.name,
          }));

          console.log('👉🏻👉🏻👉🏻👉🏻 ALL AGENTS ', names, names.length);
          console.log('👉🏻👉🏻👉🏻👉🏻 agent_details NEED TO REMOVE AGENT', agentsToRemove, );
          console.log('👉🏻👉🏻👉🏻👉🏻 agents agents NEED TO REMOVE AGENTS', agentsToRemove3);

          if (rows2.length) {
            
                const uuidsToRemove = rows2.map(row => row.name);
                await db2.query(
                    `DELETE FROM agent_details 
                    WHERE name = ANY($1)`,
                    [uuidsToRemove]
                );
                console.log(`🗑️ Removed agents_details ${uuidsToRemove.length} agents from DB`);

          }

          if (rows3.length) {
            
                const uuidsToRemove3 = rows3.map(row => row.name);
                await db2.query(
                    `DELETE FROM agents 
                    WHERE name = ANY($1)`,
                    [uuidsToRemove3]
                );
                console.log(`🗑️ Removed agents ${uuidsToRemove3.length} agents from DB`);
          }
          
          await wait(3000);

          const [agentBody3, tierBody3] = await Promise.all([
            new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
            new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
          ]);

          const agentList3 = JSON.parse(agentBody3.body).response;
          const tierList3  = JSON.parse(tierBody3.body).response;
          const agentMap3 = agentList3.reduce((acc, a) => (acc[a.name] = a, acc), {});

          const matched3 = tierList3.reduce((acc, tier) => {
              const agent3 = agentMap3[tier.agent];
              if (
                  agent3 && 
                  agent3.contact.includes(arg.subdomain)
              ) acc.push(agent3);
              return acc;
          }, []);
          console.log("✅ matched:", matched3);

          if(matched3.length){

              const names3 = matched3.map(e => e.name);
              const { rows } = await db2.query(
                  `SELECT 
                      ad.name,
                      ad.contact,
                      ad.max_no_answer,
                      ad.wrap_up_time,
                      ad.reject_delay_time,
                      ad.busy_delay_time,
                      ad.no_answer_delay_time,
                      ad.last_bridge_start,
                      ad.last_bridge_end,
                      ad.last_offered_call,
                      ad.last_status_change,
                      ad.no_answer_count,
                      ad.calls_answered, 
                      ad.talk_time,
                      ad.ready_time,
                      ad.external_calls_count,
                      ve.extension
                  FROM agent_details ad
                  LEFT JOIN v_extensions ve 
                      ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                  WHERE ad.name = ANY($1)`,
                  [names3]
              );

              // Map results by name for O(1) lookup
              const dbMap3 = rows.reduce((acc, row) => {
                  acc[row.name] = row;
                  return acc;
              }, {});

              const newPayload3 = matched3.map(element => {
                  const data3 = dbMap3[element.name];
                  if(data3){
                      const { 
                          contact,
                          max_no_answer,
                          wrap_up_time,
                          reject_delay_time,
                          busy_delay_time,
                          no_answer_delay_time,
                          last_bridge_start,
                          last_bridge_end,
                          last_offered_call,
                          last_status_change,
                          no_answer_count,
                          calls_answered, 
                          talk_time,
                          ready_time,
                          external_calls_count,
                          extension 
                      } = data3;

                      const newContact3 = extension ? updateContact(contact, extension) : contact;
                      return {
                          ...element,
                          contact: newContact3,
                          max_no_answer: Number(max_no_answer),
                          wrap_up_time: Number(wrap_up_time),
                          reject_delay_time: Number(reject_delay_time),
                          busy_delay_time: Number(busy_delay_time),
                          no_answer_delay_time: Number(no_answer_delay_time),
                          last_bridge_start: Number(last_bridge_start),
                          last_bridge_end: Number(last_bridge_end),
                          last_offered_call: Number(last_offered_call),
                          last_status_change: Number(last_status_change),
                          no_answer_count: Number(no_answer_count),
                          calls_answered: Number(calls_answered),
                          talk_time: Number(talk_time),
                          ready_time: Number(ready_time),
                          external_calls_count: Number(external_calls_count)
                      };
                  }
              });
              
              const filteredAgent3 = newPayload3.filter(Boolean);
              const stringData = JSON.stringify(filteredAgent3)
              console.log('👉🏻👉🏻 SAVE NEW DATA EVENT BROADCAST5', filteredAgent3);
              await redisClient.set(
                `${arg.subdomain}:queueWallboardAgentBoard2`,
                stringData
              );
              wallboard.to(arg.subdomain).emit("queueAgentboard2", JSON.stringify(filteredAgent3));
          }

          conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, async ({ body }) => {
            const { status, response } = JSON.parse(body);
            console.log(`STATUS CALLS`, response);
            if (status === "success") {
              if (response.length) {
                const names = response
                .filter(e => e.name.includes(arg.subdomain))
                .map(e => e.name);

                if (!names.length) return;
                db2.query(
                  `SELECT queue, answered_seconds, abandoned_seconds, talk_time, answered_calls,
                  missed_calls, waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                  FROM member_board WHERE queue = ANY($1)`,
                  [names],
                  async (err, result) => {
                    if (err) {
                      console.error("Error fetching data:", err.message);
                      return;
                    }
                    const rows = result.rows;

                    await redisClient.set(
                      `${arg.subdomain}:queueWallboardConfigBoard`,
                      JSON.stringify(rows)
                    );
                    wallboard
                    .to(arg.subdomain)
                    .emit("queueConfigBoard", JSON.stringify(rows));
                  }
                );

              }
            }
          }); 

        }

      }

      if(arg.type === 2){

        console.log('REMOVE QUEUE', arg);
        const {extension, subdomain} = arg
        conn.api(`json {"command": "callcenter_config","data": {"arguments":"queue list tiers", "queue_name":"${extension}@${subdomain}"}}`, async ({ body }) => {
            const { response } = JSON.parse(body);
            if(response.length){
              const agentNames = response.map(({agent}) => agent);
              await Promise.all(agentNames.map(agentName => {
                  return Promise.all([
                      new Promise(resolve => 
                          conn.api(`callcenter_config tier del ${extension}@${subdomain} ${agentName}`, resolve)
                      ),
                      db2.query("DELETE FROM agent_details WHERE name = $1", [agentName])
                  ]);
              }));
            }
        });

      }

    });

    socket.on("reloadcallcenter", (arg) => {
      conn.api(`reload mod_callcenter`);
    })
    
    // EMAN
    socket.on("attendedTransfer", async (data) => {
      
      let caller = data.split("-");
      let uuid = await redisClient.get("attendedTransfer" + caller[0]);
      let uuid2 = await redisClient.get("attendedTransfer" + caller[1]);
      console.log("bridging... 8")
      conn.api("uuid_bridge " + uuid + " " + uuid2);
    });

    socket.on("walldata", async (ext) => {

      console.log('This is called walldata', ext);
    
    });

    socket.on("supervisorCalls", async (data) => {
        console.log('SUPERVISOR CALL', data);
        wallboard
        .to(data.domain)
        .emit("supervisor4", JSON.stringify({ agentExtension: data.ext }));
    });

    // socket.on("queue", async (arg, callback) => {
    //      wallboard.to(socket.handshake.auth.channel).emit("queue", { socketId: arg["socketId"], username: arg["username"], message: arg["username"] + " has Joined", useremail: arg["useremail"], userdept: arg["userdept"] });
    // });

    let aSocket = [];
    socket.on("agentSocketId", (data) => {
      aSocket.push(data);
      wallboard
        .to(socket.handshake.auth.channel)
        .emit("wallboardQueue", aSocket[0]);
    });

    socket.on("callRecord", async (arg, callback) => {
      console.log(
        "<!-- RECORDING CALL",
        `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      );

      //Internal and External Call
      let data = await redisClient.get(
        `uuidAnswer${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      );
      let parse = JSON.parse(data);

      console.log(
        `🎙️Recording Data🎙️`,
        data,
        socket.handshake.auth.extension,
        socket.handshake.auth.channel
      );

      //Queue Call
      let dataQueue = await redisClient.get(
        `uuidAnswerQueue${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      );
      let parseQueue = JSON.parse(dataQueue);

      let randomNumber = Math.floor(Math.random() * 90000) + 10000;
      let saveNumber = "";
      if (data) {
        if (arg == "1") {
          
          conn.api(
            "uuid_broadcast " + parse.uuid + " misc/recordstart.wav both"
          );
          let newNumber = Number(String(parse.date).slice(0, -3));
          let timeStamp = parseInt(newNumber);
          let options = { month: "short", day: "2-digit", year: "numeric" };
          let dateEvent = new Date(timeStamp)
            .toLocaleDateString("en-US", options)
            .replace(/ /g, "/");
          let cleanedDate = dateEvent.replace(/,/g, "");

          //Time now with timezone
          const currentTimestamp = Date.now();
          const localDate = new Date(currentTimestamp);
          const timeZoneOffset = localDate.getTimezoneOffset();
          const targetTimestamp = currentTimestamp - timeZoneOffset * 60000;

          let convertTime = new Date(targetTimestamp);

          let arrTime = convertTime.toISOString().split("T")[1];
          
          let getTime = arrTime.split(".");

          //2024-01-09T12:16:07.078Z

          let arrDate = cleanedDate.split("/");
          conn.api(
            "uuid_record " +
              parse.uuid +
              " start /var/lib/freeswitch/recordings/" +
              socket.handshake.auth.channel +
              "/archive/" +
              arrDate[2] +
              "/" +
              arrDate[0] +
              "/" +
              arrDate[1] +
              "/" +
              parse.uuid +
              ".wav"
          );
          conn.api(
            "uuid_record " +
              parse.uuid +
              " unmask /var/lib/freeswitch/recordings/" +
              socket.handshake.auth.channel +
              "/archive/" +
              arrDate[2] +
              "/" +
              arrDate[0] +
              "/" +
              arrDate[1] +
              "/" +
              parse.uuid +
              ".wav"
          );
        }

        if (arg == "2") {
          
          let newNumber = Number(String(parse.date).slice(0, -3));
          let timeStamp = parseInt(newNumber);
          let options = { month: "short", day: "2-digit", year: "numeric" };
          let dateEvent = new Date(timeStamp)
            .toLocaleDateString("en-US", options)
            .replace(/ /g, "/");
          let cleanedDate = dateEvent.replace(/,/g, "");

          //Time now with timezone
          const currentTimestamp = Date.now();
          const localDate = new Date(currentTimestamp);
          const timeZoneOffset = localDate.getTimezoneOffset();
          const targetTimestamp = currentTimestamp - timeZoneOffset * 60000;

          let convertTime = new Date(targetTimestamp);

          

          let arrTime = convertTime.toISOString().split("T")[1];
          let getTime = arrTime.split(".");

          //   Jan/08/2024
          let arrDate = cleanedDate.split("/");

          conn.api(
            "uuid_record " +
              parse.uuid +
              " mask /var/lib/freeswitch/recordings/" +
              socket.handshake.auth.channel +
              "/archive/" +
              arrDate[2] +
              "/" +
              arrDate[0] +
              "/" +
              arrDate[1] +
              "/" +
              parse.uuid +
              ".wav"
          );

          conn.api(
            "uuid_broadcast " + parse.uuid + " misc/recordstop.wav both"
          );
          conn.api(`uuid_dump ${parse.uuid} JSON`, (res) => {
            if (res.body) {
              let data = JSON.parse(res.body);
              
              if (data["variable_sip_call_id"]) {
                db2
                  .query(
                    "SELECT domain_name, sip_call_id FROM v_xml_cdr WHERE domain_name = '" +
                      data["variable_dialed_domain"] +
                      "' AND sip_call_id = '" +
                      data["variable_sip_call_id"] +
                      "'"
                  )
                  .then((res) => {
                    if (res.rows.length > 0) {
                      
                    }
                  });

                // pool.query('UPDATE v_call_center_agents SET agent_status = \'Available\' WHERE agent_contact = \'user/'+ socket.handshake.auth.extension +'@'+ socket.handshake.auth.channel +'\' AND queue_extension IS NULL');
              }
            }
          });
        }
      } else if (dataQueue) { 
        if (arg == "1") {
          
          conn.api(
            "uuid_broadcast " + parseQueue.uuid + " misc/recordstart.wav both"
          );
          let newNumber = Number(String(parseQueue.date).slice(0, -3));
          let timeStamp = parseInt(newNumber);
          let options = { month: "short", day: "2-digit", year: "numeric" };
          let dateEvent = new Date(timeStamp)
            .toLocaleDateString("en-US", options)
            .replace(/ /g, "/");
          let cleanedDate = dateEvent.replace(/,/g, "");

          //Time now with timezone
          const currentTimestamp = Date.now();

          const localDate = new Date(currentTimestamp);

          const timeZoneOffset = localDate.getTimezoneOffset();

          const targetTimestamp = currentTimestamp - timeZoneOffset * 60000;

          let convertTime = new Date(targetTimestamp);

          let arrTime = convertTime.toISOString().split("T")[1];
          let getTime = arrTime.split(".");

          //2024-01-09T12:16:07.078Z

          let arrDate = cleanedDate.split("/");
          conn.api(
            "uuid_record " +
              parseQueue.uuid +
              " start /var/lib/freeswitch/recordings/" +
              socket.handshake.auth.channel +
              "/archive/" +
              arrDate[2] +
              "/" +
              arrDate[0] +
              "/" +
              arrDate[1] +
              "/" +
              randomNumber +
              "+" +
              getTime[0] +
              "-" +
              parseQueue.uuid +
              ".wav"
          );
        }

        if (arg == "2") {
          
          conn.api(
            "uuid_broadcast " + parseQueue.uuid + " misc/recordstop.wav both"
          );
          let newNumber = Number(String(parseQueue.date).slice(0, -3));
          let timeStamp = parseInt(newNumber);
          let options = { month: "short", day: "2-digit", year: "numeric" };
          let dateEvent = new Date(timeStamp)
            .toLocaleDateString("en-US", options)
            .replace(/ /g, "/");
          let cleanedDate = dateEvent.replace(/,/g, "");

          //Time now with timezone
          const currentTimestamp = Date.now();

          const localDate = new Date(currentTimestamp);

          const timeZoneOffset = localDate.getTimezoneOffset();

          const targetTimestamp = currentTimestamp - timeZoneOffset * 60000;

          let convertTime = new Date(targetTimestamp);

          let arrTime = convertTime.toISOString().split("T")[1];
          let getTime = arrTime.split(".");

          //   Jan/08/2024
          let arrDate = cleanedDate.split("/");
          conn.api(
            "uuid_record " +
              parseQueue.uuid +
              " stop /var/lib/freeswitch/recordings/" +
              socket.handshake.auth.channel +
              "/archive/" +
              arrDate[2] +
              "/" +
              arrDate[0] +
              "/" +
              arrDate[1] +
              "/" +
              saveNumber +
              "+" +
              getTime[0] +
              "-" +
              parseQueue.uuid +
              ".wav"
          );
          saveNumber = "";
        }
      } else {
        
      }
    });

    socket.on("changeProfile", async (arg, callback) => {
      const {extension, channel} = socket.handshake.auth
      console.log('CHANGE PROFILE', {extension, subdomain: channel, status: arg});
      await redisClient.set(`${extension}+${channel}`, arg);
      wallboard.to(channel).emit("changeProfile", {extension, subdomain: channel, status: arg});
    });

    socket.on("queueScript", async (arg, callback) => {
      console.log('queueScript', arg);
      const {subdomain, extension, action, type} = arg;
      wallboard.to(subdomain).emit("queueScript", {subdomain: subdomain, extension: extension, action: action, type: type});
    });

    socket.on("hold", async (arg, callback) => {
      
      let data = await redisClient.get(
        `uuidAnswer${arg}@${socket.handshake.auth.channel}`
      );
      let dataParse = JSON.parse(data);
      if (dataParse.uuid) {
        // conn.api(`uuid_broadcast ${dataParse.uuid} local_stream://moh aleg`)
      }
    });

    socket.on("unhold", async (arg, callback) => {
      
      let data2 = await redisClient.get(
        `uuidAnswer${arg}@${socket.handshake.auth.channel}`
      );
      let dataParse2 = JSON.parse(data2);
      if (dataParse2.uuid) {
        
        conn.api("uuid_break " + dataParse2.uuid + " all");
      }
    });

    /** Parking call */
    socket.on("park", async (arg) => {

      const {id, slot} = arg;
      const parkSlot = Number(slot) - 1;
      const {extension, channel} = socket.handshake.auth;

      console.log(`PARKING CALL`, id, slot, socket.handshake.auth);

      const parkedContainer = async () => {
        const exist = await redisClient.exists(
          `${channel}:parked`
        );
        if (!exist) {
          await redisClient.lPush(`${channel}:parked`, ["", "", "", "", ""]);
          return true;
        }
        return false;
      };

      await parkedContainer()
      .then(async () => {

        if(
          classifyNumber(Number(extension)) === 'InternalExtension'
        ){
          await redisClient.set(`callstatus:${channel}:${extension}`, 1);
          wallboard
          .to(channel)
          .emit("call", { caller: "", callee: extension, status: 1});

          const aliasNameExtension = async () => {
              const { rows } = await db2.query(
                    `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                    [channel, id]
              );
              if(rows.length){
                return rows[0]?.number_alias;
              }
              return id
          }
          const destinationAlias = await aliasNameExtension();
          const parkAgent = `${destinationAlias}@${channel}`;

          conn.api(`show calls as json`, async (res) => {
            const dataJSON = JSON.parse(res.body);
            const {row_count, rows} = JSON.parse(res.body)

            if(row_count){

                let park_uuid = null;
                for (const data of rows) {
                  if (park_uuid) break;

                  if (data.name?.includes('internal')) {
                    const sipContactId = await sipProfileURL(data.name);
                    const presence = await redisClient.get(`ChannelSipURI-${sipContactId}`);
                    console.log(`🎉`, id, presence, parkAgent, sipContactId)
                    if (`${presence}@${channel}` === parkAgent || sipContactId.includes(parkAgent) || String(sipContactId).split("@")[0] === String(destinationAlias) || data.presence_id === parkAgent) { // [park-match]
                      console.log(`🔵`, parkAgent)
                      park_uuid = data.uuid;
                      break;
                    }
                  }

                  if (data.b_name?.includes('internal')) {
                    const sipContactId = await sipProfileURL(data.b_name);
                    const presence = await redisClient.get(`ChannelSipURI-${sipContactId}`);
                    console.log(`🎉🎉`, id, presence, parkAgent, sipContactId)
                    if (`${presence}@${channel}` === parkAgent || sipContactId.includes(parkAgent) || String(sipContactId).split("@")[0] === String(destinationAlias) || data.b_presence_id === parkAgent) { // [park-match]
                      console.log(`🔵`, parkAgent)
                      park_uuid = data.b_uuid;
                      break;
                    }
                  }

                  if(!data.name?.includes('internal')){
                      const sipContactId = await sipProfileURL(data.name);
                      if (sipContactId.includes(destinationAlias)) {
                      console.log(`🔵External`, parkAgent)
                      park_uuid = data.uuid;
                      break;
                    }
                  }

                   if(!data.b_name?.includes('internal')){
                      const sipContactId = await sipProfileURL(data.b_name);
                      if (sipContactId.includes(destinationAlias)) {
                      console.log(`🔵External`, parkAgent)
                      park_uuid = data.b_uuid;
                      break;
                    }
                  }

                }

                console.log('PARKING', park_uuid, parkAgent, rows);

                if(park_uuid){
                  //Park Call
                  console.log("this is park 2")
                  conn.api("uuid_park " + park_uuid);
                  conn.api("uuid_broadcast " + park_uuid+ " misc/parkslot.wav");
                  console.log("sched_del 1 sig", park_uuid)
                  conn.api("sched_del " + park_uuid);
                  conn.api(`uuid_broadcast ${park_uuid} ${mohStream(channel)}`);
                  await redisClient.lSet(`${channel}:parked`, parkSlot, park_uuid);
                  //Send Status
                  const arrStatus = await redisClient.lRange(`${channel}:parked`, 0, -1);
                  wallboard
                  .to(channel)
                  .emit("parkStatus", arrStatus); deskParkLamps(channel); // [desk-park] 
                }


            }
            
          })

        }
      })

      // await parkedContainer().then(async () => {

      //   if (
      //     (parseInt(socket.handshake.auth.extension) < 2000 &&
      //       parseInt(socket.handshake.auth.extension) >= 10) ||
      //     (parseInt(socket.handshake.auth.extension) < 9000 &&
      //       parseInt(socket.handshake.auth.extension) >= 7000)
      //   ){

      //     await redisClient.set(
      //       "callstatus:" +
      //         socket.handshake.auth.channel +
      //         ":" +
      //         socket.handshake.auth.extension,
      //       1
      //     );
      //     wallboard
      //       .to(socket.handshake.auth.channel)
      //       .emit("call", {
      //         caller: "",
      //         callee: socket.handshake.auth.extension,
      //         status: 1,
      //       });
      //   }

      //   console.log("<!-- Blink Status Called Here ", {
      //     caller: "",
      //     callee: socket.handshake.auth.extension,
      //     status: 1,
      //   });

      //   console.log(
      //     `<!-- PARKING ${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      //   );

      //   let numPark = parseInt(arg.slot) - 1;
      //   let dataCaller = await redisClient.get(
      //     `uuidAnswerOutbound${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      //   );
      //   let parseData = JSON.parse(dataCaller);
      //   console.log(
      //     "DATA PARKING",
      //     "SocketData: " + JSON.stringify(arg),
      //     "RedisData: " + dataCaller,
      //     parseData
      //   );

      //   let slotNumber = await redisClient.lRange(
      //     `${socket.handshake.auth.channel}:parked`,
      //     numPark,
      //     numPark
      //   );

      //   if (slotNumber == "" || slotNumber == parseData.uuid) {
      //     if (dataCaller) {
      //       //Slot Number
      //       await redisClient.set(
      //         `${arg.id}@${socket.handshake.auth.channel}+numSlot`,
      //         JSON.stringify({
      //           slot: numPark,
      //           domain: socket.handshake.auth.channel,
      //         })
      //       );
      //       await redisClient.set(
      //         `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}+numSlot`,
      //         JSON.stringify({
      //           slot: numPark,
      //           domain: socket.handshake.auth.channel,
      //         })
      //       );

      //       //Park Call
      //       conn.api("uuid_park " + parseData.uuid);
      //       conn.api("uuid_broadcast " + parseData.uuid + " misc/parkslot.wav");
      //       conn.api("sched_del " + parseData.uuid);

      //       await redisClient.del(
      //         `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}-parkTrigger`
      //       );

      //       conn.api(`uuid_broadcast ${parseData.uuid} local_stream://moh`);

      //       function timeOutPark() {
      //         return new Promise((resolve, reject) => {
      //           conn.api(
      //             `sched_transfer +180 ${parseData.uuid} hangup`,
      //             function (res) {
      //               resolve(res.body);
      //             }
      //           );
      //         });
      //       }

      //       timeOutPark()
      //         .then(async (response) => {
                

      //           await redisClient.set(
      //             `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}-parkTrigger`,
      //             0
      //           );

      //           await redisClient.expire(
      //             `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}-parkTrigger`,
      //             182
      //           );

      //           setTimeout(async () => {
      //             let existParkTrigger = await redisClient.exists(
      //               `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}-parkTrigger`
      //             );

      //             if (existParkTrigger == 1) {
      //               let orig_uuid = await redisClient.lRange(
      //                 `${socket.handshake.auth.channel}:parked`,
      //                 numPark,
      //                 numPark
      //               );

      //               if (orig_uuid) {
      //                 conn.api(
      //                   "uuid_transfer " +
      //                     orig_uuid +
      //                     " *99" +
      //                     socket.handshake.auth.extension
      //                 );
      //               }

      //               await redisClient.lSet(
      //                 `${socket.handshake.auth.channel}:parked`,
      //                 numPark,
      //                 ""
      //               );
      //               setTimeout(async () => {
      //                 let arrStatus = await redisClient.lRange(
      //                   `${socket.handshake.auth.channel}:parked`,
      //                   0,
      //                   -1
      //                 );
      //                 wallboard
      //                   .to(socket.handshake.auth.channel)
      //                   .emit("parkStatus", arrStatus);
      //               }, 1000);
      //             }
      //           }, 180000);
      //         })
      //         .catch((err) => {
      //           console.error("Error:", err);
      //         });

      //       wallboard
      //       .to(socket.handshake.auth.channel)
      //       .emit("holdPark", socket.handshake.auth.extension);
      //       console.log(
      //         "HOLD PARK EXTENSION-" + socket.handshake.auth.channel,
      //         socket.handshake.auth.extension
      //       );
            

      //       await redisClient.lSet(
      //         `${socket.handshake.auth.channel}:parked`,
      //         numPark,
      //         parseData.uuid
      //       );

      //       let data_uuid = await redisClient.get(
      //         `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}-internalCall`
      //       );
            

      //       redisClient.set(
      //         `${socket.handshake.auth.channel}:parked:details:${parseData.uuid}`,
      //         JSON.stringify({ name: arg.id, number: arg.id })
      //       );
      //       redisClient.expire(
      //         `${socket.handshake.auth.channel}:parked:details:${parseData.uuid}`,
      //         180
      //       );

      //       //Send Status
      //       setTimeout(async () => {
      //         let arrStatus = await redisClient.lRange(
      //           `${socket.handshake.auth.channel}:parked`,
      //           0,
      //           -1
      //         );
      //         wallboard
      //           .to(socket.handshake.auth.channel)
      //           .emit("parkStatus", arrStatus);
      //       }, 1000);
      //     }
      //   }
      // });

    });

    // ATTENDED TRANSFER USING PARK CALL -- EMAN
    socket.on(`declineAttendedTransfer`, (res) => {  
      const [extension, subdomain] = res.split("@");
      wallboard.to(subdomain).emit("declineAttendedTransfer", res);
    });

    // [att-answered] server.js: the attended-transfer target answered the consult call (value: "<agent ext>@<domain>")
    socket.on("attendedConsultAnswered", (res) => {
      const [ext, subdomain] = String(res || "").split("@");
      // only the transferring agent's own sockets (room "<ext>@<domain>"): the web app's SIP
      // username is not the extension number, so the dialer can't filter a domain-wide event
      if (ext && subdomain) wallboard.to(`${ext}@${subdomain}`).emit("attendedConsultAnswered", res);
    });

    // EMAN ADDED MARCH 03, 2026 START
    socket.on(`contactChecker`, (incomingData) => {
      const { channel } = socket.handshake.auth;
      const postData = JSON.stringify({
        "type": incomingData.type.toString(),
        "queue_extension": incomingData.queue_extension.toString(),
        "number_phone_number": incomingData.number_phone_number.toString(),
        "subdomain" : channel,
      });
      const options = {
        hostname: channel, 
        port: 8443,
        path: '/api/contact',
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(postData)
        },
        rejectUnauthorized: false 
      };
      const req = https.request(options, (httpRes) => {
        let result = '';
        httpRes.on('data', (chunk) => {
          result += chunk;
        });
        httpRes.on('end', () => {
          try {
            const responseJson = JSON.parse(result);
            wallboard.to(channel).emit("contact-checker", responseJson);
          } catch (e) {
            wallboard.to(channel).emit("contact-checker", { raw: result });
          }
        });
      });
      req.on('error', (error) => {
        console.error('HTTPS Request Error:', error);
        socket.emit("error", "Internal API request failed");
      });
      req.write(postData);
      req.end();
    });
    // EMAN ADDED MARCH 03, 2026 END


    // [call-journey] live journeys for the Call Status -> Active Calls table.
    // payload: array of channel uuids (a leg and/or b leg); ack: { [uuid]: summary + timeline }
    // [call-resume] the web app was refreshed mid-call: its old WebRTC leg is still
    // up on FreeSWITCH (media_timeout 300s) with the other party on it. Ring the
    // user again (the reloaded client auto-answers it) and take the other party
    // over with intercept, which also hangs up the dead leg.
    socket.on("resumeCall", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) { /* ignore */ } };
      try {
        const { extension, channel } = socket.handshake.auth || {};
        const callId = String((arg && arg.callId) || "");
        if (!extension || !channel || !/^[\w.@+\-]{6,160}$/.test(callId)) return reply({ ok: false, reason: "bad-request" });
        const body = (r) => String((r && (r.body !== undefined ? r.body : (r.getBody && r.getBody()))) || "").trim();
        const getvar = async (u, v) => {
          const out = body(await promisifiedApi(conn, `uuid_getvar ${u} ${v}`));
          return out === "_undef_" || out.startsWith("-ERR") ? "" : out;
        };
        // who may this user resume: its extension and its SIP username (number_alias)
        const own = new Set([String(extension)]);
        try {
          const { rows } = await db2.query(
            `SELECT e.number_alias FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid
             WHERE d.domain_name = $1 AND e.extension = $2 LIMIT 1`, [channel, String(extension)]);
          if (rows[0] && rows[0].number_alias) own.add(String(rows[0].number_alias));
        } catch (e) { /* ignore */ }

        let list = {};
        try { list = JSON.parse(body(await promisifiedApi(conn, "show channels as json")) || "{}"); } catch (e) { list = {}; }
        const rows = Array.isArray(list.rows) ? list.rows : [];
        let old = null;
        for (const r of rows) {
          if (!r || !r.uuid) continue;
          if (r.context !== channel && !String(r.presence_id || "").endsWith("@" + channel)) continue;
          if ((await getvar(r.uuid, "sip_call_id")) !== callId) continue;
          old = r; break;
        }
        if (!old) return reply({ ok: false, reason: "not-found" });
        const owner = String(old.presence_id || "").split("@")[0];
        const fromUser = await getvar(old.uuid, "sip_from_user");
        const toUser = await getvar(old.uuid, "sip_to_user");
        if (![owner, fromUser, toUser].some((u) => own.has(String(u)))) return reply({ ok: false, reason: "not-yours" });

        const partner = await getvar(old.uuid, "bridge_uuid") || await getvar(old.uuid, "signal_bond");
        if (!partner || !/^[0-9a-f-]{36}$/i.test(partner) || body(await promisifiedApi(conn, `uuid_exists ${partner}`)) !== "true") {
          return reply({ ok: false, reason: "no-partner" });
        }
        // what the user should see: the caller (incoming) or the number they dialled (outgoing)
        let number = ""; let name = "";
        if (old.direction === "outbound") {
          number = await getvar(partner, "caller_id_number");
          name = await getvar(partner, "caller_id_name");
        } else {
          number = old.dest || await getvar(old.uuid, "destination_number");
        }
        const clean = (v) => String(v || "").replace(/[{}\[\],'"^|]/g, "").slice(0, 60);
        const cmd = `originate {origination_caller_id_number=${clean(number) || "resume"},origination_caller_id_name='${clean(name) || clean(number)}',originate_timeout=25,ignore_early_media=true,rq_resume=true}user/${extension}@${channel} &intercept(${partner})`;
        console.log("[call-resume]", extension, channel, "old", old.uuid, "partner", partner);
        conn.bgapi(cmd, () => {});
        if (journey && typeof journey.add === "function") journey.add(partner, "resumed", { by: String(extension), text: `Call resumed by ${extension} after the app was refreshed` }).catch?.(() => {});
        return reply({ ok: true, number: clean(number), name: clean(name) });
      } catch (err) {
        console.log("[call-resume] failed", err && err.message);
        return reply({ ok: false, reason: "error" });
      }
    });

    // [queue-tiers] agent order + skill level for a queue. The queue save API drops them, so the
    // web app sends them here right after saving. Only a logged-in Owner/Manager session of this
    // domain may change them: the browser's session cookie is checked against the PHP API.
    const queueTierSessionOk = async () => {
      const cached = socket.data && socket.data.tierAuth;
      if (cached && Date.now() - cached.t < 60000) return cached.ok;
      const { channel } = socket.handshake.auth || {};
      const cookie = socket.handshake.headers && socket.handshake.headers.cookie;
      let ok = false;
      if (channel && cookie && /^[a-z0-9.-]+$/i.test(channel)) {
        ok = await new Promise((resolve) => {
          const req = https.request({ host: "127.0.0.1", port: 8443, path: "/api/index", method: "GET",
            headers: { Host: channel, Cookie: cookie }, rejectUnauthorized: false, timeout: 8000 }, (res) => {
            let body = ""; res.on("data", (c) => { body += c; if (body.length > 5e6) req.destroy(); });
            res.on("end", () => {
              try {
                const d = JSON.parse(body);
                resolve(d.result === "success" && [7, 8].includes(parseInt(d.permissionMode, 10)));
              } catch (e) { resolve(false); }
            });
          });
          req.on("error", () => resolve(false)); req.on("timeout", () => { req.destroy(); resolve(false); });
          req.end();
        });
      }
      socket.data = socket.data || {}; socket.data.tierAuth = { ok, t: Date.now() };
      return ok;
    };
    const queueTierRows = async (channel, queue) => {
      const { rows } = await db2.query(
        `SELECT t.call_center_queue_uuid AS q, t.call_center_agent_uuid AS a, t.domain_uuid AS d, e.extension,
                o.tier_level, o.tier_position, t.tier_level AS api_level, t.tier_position AS api_position
           FROM v_call_center_tiers t
           JOIN v_call_center_queues q ON q.call_center_queue_uuid = t.call_center_queue_uuid
           JOIN v_domains dm ON dm.domain_uuid = q.domain_uuid
           JOIN v_call_center_agents ag ON ag.call_center_agent_uuid = t.call_center_agent_uuid
           LEFT JOIN v_extensions e ON e.domain_uuid = q.domain_uuid
                AND (ag.agent_contact LIKE '%/' || e.number_alias || '@%' OR ag.agent_contact LIKE '%/' || e.extension || '@%')
           LEFT JOIN q_call_center_tier_order o ON o.call_center_queue_uuid = t.call_center_queue_uuid AND o.call_center_agent_uuid = t.call_center_agent_uuid
          WHERE dm.domain_name = $1 AND q.queue_extension = $2`, [channel, String(queue)]);
      return rows;
    };
    socket.on("getQueueTiers", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const queue = String((arg && arg.queue) || "");
        if (!channel || !/^\d{2,6}$/.test(queue)) return reply({ ok: false });
        const rows = await queueTierRows(channel, queue);
        reply({ ok: true, agents: rows.filter((r) => r.extension && r.tier_level != null)
          .map((r) => ({ extension: String(r.extension), level: Number(r.tier_level), position: Number(r.tier_position) })) });
      } catch (e) { reply({ ok: false }); }
    });
    socket.on("setQueueTiers", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const queue = String((arg && arg.queue) || "");
        const list = Array.isArray(arg && arg.agents) ? arg.agents.slice(0, 300) : [];
        if (!channel || !/^\d{2,6}$/.test(queue) || !list.length) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const want = new Map();
        list.forEach((a, i) => {
          const ext = String((a && a.extension) || "");
          const level = Math.min(9, Math.max(1, parseInt(a && a.level, 10) || 1));
          const position = Math.min(999, Math.max(0, Number.isFinite(parseInt(a && a.position, 10)) ? parseInt(a.position, 10) : i));
          if (/^\d{2,10}$/.test(ext)) want.set(ext, { level, position });
        });
        const rows = await queueTierRows(channel, queue);
        let updated = 0;
        for (const r of rows) {
          const w = r.extension && want.get(String(r.extension));
          if (!w) continue;
          await db2.query(
            `INSERT INTO q_call_center_tier_order (call_center_queue_uuid, call_center_agent_uuid, domain_uuid, tier_level, tier_position, updated_at)
             VALUES ($1,$2,$3,$4,$5,now())
             ON CONFLICT (call_center_queue_uuid, call_center_agent_uuid)
             DO UPDATE SET tier_level = EXCLUDED.tier_level, tier_position = EXCLUDED.tier_position, updated_at = now()`,
            [r.q, r.a, r.d, w.level, w.position]);
          const qn = `${queue}@${channel}`;
          await promisifiedApi(conn, `callcenter_config tier set level ${qn} ${r.a} ${w.level}`).catch(() => {});
          await promisifiedApi(conn, `callcenter_config tier set position ${qn} ${r.a} ${w.position}`).catch(() => {});
          updated += 1;
        }
        // agents no longer in the queue: drop stale order rows
        await db2.query(
          `DELETE FROM q_call_center_tier_order o WHERE o.call_center_queue_uuid = ANY($1::uuid[])
             AND NOT EXISTS (SELECT 1 FROM v_call_center_tiers t WHERE t.call_center_queue_uuid = o.call_center_queue_uuid AND t.call_center_agent_uuid = o.call_center_agent_uuid)`,
          [[...new Set(rows.map((r) => r.q))]]).catch(() => {});
        console.log("[queue-tiers]", channel, queue, "updated", updated);
        reply({ ok: true, updated });
      } catch (err) {
        console.log("[queue-tiers] failed", err && err.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [voicemail] The extension save API never returns voicemail_recording_instructions (typo
    // `$vVoicemai` in AccountsController), so the toggle always loaded as off and the next save
    // could turn it off for real; voicemail_enabled's 1/2 meaning also differs between the web
    // app and the API source. The web app reads and writes these two flags here instead.
    // Owner/Manager session of this domain only (same check as queue tiers).
    socket.on("getVoicemailSettings", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const ext = String((arg && arg.extension) || "");
        if (!channel || !/^\d{2,10}$/.test(ext)) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const { rows } = await db2.query(
          `SELECT v.voicemail_enabled, v.voicemail_recording_instructions FROM v_voicemails v
             JOIN v_domains d ON d.domain_uuid = v.domain_uuid
            WHERE d.domain_name = $1 AND v.voicemail_id = $2 LIMIT 1`, [channel, ext]);
        if (!rows.length) return reply({ ok: true, found: false });
        reply({ ok: true, found: true,
          enabled: String(rows[0].voicemail_enabled) === "true",
          recordingInstructions: String(rows[0].voicemail_recording_instructions) === "true" });
      } catch (e) { reply({ ok: false, reason: "error" }); }
    });
    socket.on("setVoicemailSettings", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const ext = String((arg && arg.extension) || "");
        const flag = (v) => (typeof v === "boolean" ? (v ? "true" : "false") : null);
        const enabled = flag(arg && arg.enabled);
        const instr = flag(arg && arg.recordingInstructions);
        if (!channel || !/^\d{2,10}$/.test(ext) || (enabled === null && instr === null)) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const r = await db2.query(
          `UPDATE v_voicemails v SET
                  voicemail_enabled = COALESCE($3, v.voicemail_enabled),
                  voicemail_recording_instructions = COALESCE($4, v.voicemail_recording_instructions)
             FROM v_domains d
            WHERE d.domain_uuid = v.domain_uuid AND d.domain_name = $1 AND v.voicemail_id = $2`,
          [channel, ext, enabled, instr]);
        // the directory XML (voicemail on/off) is cached per user -- drop it so the next call reads the DB
        try { require("fs").unlinkSync(`/var/cache/ringq/directory.${ext}@${channel}`); } catch (e) {}
        console.log("[voicemail]", channel, ext, "enabled", enabled, "instructions", instr, "rows", r.rowCount);
        reply({ ok: true, updated: r.rowCount });
      } catch (err) {
        console.log("[voicemail] failed", err && err.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [my-devices] the signed-in user's own SIP registrations (desk phone, web app, desktop app,
    // mobile app, softphone) for the profile menu, and "kill" for any of them. Identity comes from
    // the browser's PHP session in memcached (cookie), never from what the client claims.
    const phpSession = () => new Promise((resolve) => {
      const cookie = String((socket.handshake.headers && socket.handshake.headers.cookie) || "");
      const m = /(?:^|;\s*)RINGQ=([A-Za-z0-9,-]{10,128})/.exec(cookie) || /(?:^|;\s*)PHPSESSID=([A-Za-z0-9,-]{10,128})/.exec(cookie);
      if (!m) return resolve(null);
      const c = require("net").createConnection({ host: "127.0.0.1", port: 11211 });
      let buf = "", finished = false;
      const done = (v) => { if (finished) return; finished = true; try { c.destroy(); } catch (e) {} resolve(v); };
      const field = (k) => { const r = new RegExp(`s:${k.length}:"${k}";s:\\d+:"([^"]*)"`).exec(buf); return r ? r[1] : ""; };
      c.setTimeout(3000, () => done(null));
      c.on("error", () => done(null));
      c.on("connect", () => c.write(`get ringq_${m[1]}\r\n`));
      c.on("data", (d) => {
        buf += d.toString("utf8");
        if (buf.length > 1e6) return done(null);
        if (!buf.endsWith("END\r\n")) return;
        if (!buf.startsWith("VALUE")) return done(null);
        const sess = { username: field("username"), extension: field("extension"), subdomain: field("subdomain") };
        done(sess.username && sess.subdomain ? sess : null);
      });
    });
    const deviceKind = (agent) => {
      const a = String(agent || "").toLowerCase();
      if (a.includes("webapp")) return "web";
      if (a.includes("ringq desktop") || a.includes("electron")) return "desktop";
      if (/iphone|ipad|\bios\b/.test(a)) return "ios";
      if (a.includes("android")) return "android";
      if (/yealink|fanvil|grandstream|snom|polycom|\bpoly\b|cisco|htek|gigaset|panasonic|avaya|alcatel|mitel|aastra|akuvox|flyingvoice|obihai|linksys|\bspa\d/.test(a)) return "deskphone";
      return "softphone";
    };
    const myRegistrations = async (sess) => {
      const regs = (await fsRegistration()) || [];
      rememberMobileRegs(regs).catch(() => {});
      const live = regs
        .filter((r) => r && r["Auth-User"] === sess.username && r["Auth-Realm"] === sess.subdomain && r["Call-ID"])
        .map((r) => {
          const tm = /transport=([a-z]+)/i.exec(String(r.Contact || ""));
          const em = /EXPSECS\((\d+)\)/.exec(String(r.Status || ""));
          return {
            callId: r["Call-ID"],
            kind: deviceKind(r.Agent),
            agent: String(r.Agent || ""),
            ip: String(r.IP || ""),
            port: String(r.Port || ""),
            transport: tm ? tm[1].toUpperCase() : "",
            reachable: String(r["Ping-Status"] || "").toLowerCase() !== "unreachable",
            expiresIn: em ? Number(em[1]) : null,
            status: "online",
            mobileId: mobileKindOf(r.Agent) ? mobileIdOf(r.Agent) : undefined,
          };
        });
      // mobile apps seen in the last 24 h but not connected now -> "app closed"
      const key = `mydevMobile-${sess.subdomain}:${sess.username}`;
      const seen = await redisClient.hGetAll(key).catch(() => ({}));
      const liveIds = new Set(live.map((d) => d.mobileId).filter(Boolean));
      let iosPush = false, androidPush = false;
      if (sess.extension) {
        iosPush = (await redisClient.sCard(`iosDevicePushToken-${sess.extension}@${sess.subdomain}`).catch(() => 0)) > 0;
        for await (const k of redisClient.scanIterator({ MATCH: `androidTokenActive-${sess.extension}@${sess.subdomain}:*`, COUNT: 200 })) { androidPush = true; break; }
      }
      const closed = [];
      for (const [id, raw] of Object.entries(seen || {})) {
        let v = null;
        try { v = JSON.parse(raw); } catch (e) {}
        if (!v || !v.lastSeen) continue;
        if (Date.now() - v.lastSeen > MOBILE_SEEN_TTL * 1000) { redisClient.hDel(key, id).catch(() => {}); continue; }
        if (liveIds.has(id)) continue;
        closed.push({
          callId: `mobile:${id}`, kind: v.kind, agent: v.agent, ip: v.ip, port: "", transport: "",
          reachable: false, expiresIn: null, status: "closed", lastSeen: v.lastSeen,
          pushReady: v.kind === "ios" ? iosPush : androidPush,
        });
      }
      return live.concat(closed);
    };
    // target = own extension, or (Owner/Manager only) another extension of the same domain
    const deviceTarget = async (sess, arg) => {
      const want = String((arg && arg.extension) || "");
      if (!want || want === String(sess.extension)) return { username: sess.username, subdomain: sess.subdomain, extension: sess.extension };
      if (!/^\d{2,10}$/.test(want) || !(await queueTierSessionOk())) return null;
      const { rows } = await db2.query(
        `SELECT e.number_alias FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid
          WHERE d.domain_name = $1 AND e.extension = $2 LIMIT 1`, [sess.subdomain, want]);
      return rows.length && rows[0].number_alias ? { username: rows[0].number_alias, subdomain: sess.subdomain, extension: want } : null;
    };
    socket.on("myDevices", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const sess = await phpSession();
        if (!sess || sess.subdomain !== (socket.handshake.auth || {}).channel) return reply({ ok: false, reason: "not-allowed" });
        const target = await deviceTarget(sess, arg);
        if (!target) return reply({ ok: false, reason: "not-allowed" });
        reply({ ok: true, extension: target.extension, devices: await myRegistrations(target) });
      } catch (e) {
        console.log("[my-devices] failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });
    socket.on("killMyDevice", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const callId = String((arg && arg.callId) || "");
        if (!callId || callId.length > 200 || /\s/.test(callId)) return reply({ ok: false, reason: "bad-request" });
        const sess = await phpSession();
        if (!sess || sess.subdomain !== (socket.handshake.auth || {}).channel) return reply({ ok: false, reason: "not-allowed" });
        const target = await deviceTarget(sess, arg);
        if (!target) return reply({ ok: false, reason: "not-allowed" });
        if (callId.startsWith("mobile:")) {
          // not connected -- just forget it (to stop its call alerts, switch off "Receive calls")
          await redisClient.hDel(`mydevMobile-${target.subdomain}:${target.username}`, callId.slice(7)).catch(() => {});
          return reply({ ok: true, forgotten: true });
        }
        const mine = (await myRegistrations(target)).find((d) => d.callId === callId);
        if (!mine) return reply({ ok: false, reason: "not-found" });
        // web / desktop sessions of this app sign themselves out when they see their own Call-ID
        wallboard.to(sess.subdomain).emit("sipKill", { extension: target.extension, callId });
        await promisifiedApi(conn, `sofia profile internal flush_inbound_reg ${callId}`).catch(() => {});
        console.log("[my-devices] killed", sess.subdomain, target.extension, mine.kind, mine.ip, "by", sess.extension);
        reply({ ok: true, kind: mine.kind });
      } catch (e) {
        console.log("[my-devices] kill failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [desk-phone-actions] Auto Provisioning > IP Phones: Online / Offline per phone and Reboot,
    // from the same FreeSWITCH registration list as My Devices (no fs_cli from PHP). A phone is
    // its extension's desk-phone registration whose User-Agent carries the MAC (Yealink, Fanvil),
    // or names the brand (Snom has no MAC there), or -- one phone, one desk-phone registration --
    // the only one. Owner / Manager sessions only; every reboot is written to the audit log.
    const deskPhoneRegs = async (channel, ext, phones) => {
      const { rows } = await db2.query(
        `SELECT e.number_alias, d.domain_uuid FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid
          WHERE d.domain_name = $1 AND e.extension = $2 LIMIT 1`, [channel, ext]);
      if (!rows.length) return null;
      const users = new Set([ext, String(rows[0].number_alias || "")].filter(Boolean));
      const regs = ((await fsRegistration().catch(() => [])) || []).filter((r) =>
        r && r["Call-ID"] && r["Auth-Realm"] === channel && users.has(String(r["Auth-User"] || "")) && deviceKind(r.Agent) === "deskphone");
      const clean = (v) => String(v || "").toLowerCase().replace(/[^0-9a-f]/g, "");
      const out = {};
      for (const p of phones) {
        const mac = clean(p.mac);
        const brand = String(p.brand || "").toLowerCase();
        let hit = regs.filter((r) => mac.length === 12 && clean(r.Agent).includes(mac));
        if (!hit.length && brand) hit = regs.filter((r) => String(r.Agent || "").toLowerCase().includes(brand) && !/([0-9a-f]{2}[:-]?){5}[0-9a-f]{2}/i.test(String(r.Agent || "")));
        if (!hit.length && phones.length === 1 && regs.length === 1) hit = regs;
        out[mac] = hit.map((r) => ({ callId: r["Call-ID"], agent: String(r.Agent || ""), ip: String(r.IP || "") }));
      }
      return { domainUuid: rows[0].domain_uuid, byMac: out };
    };
    const deskPhoneArgs = (arg) => {
      const ext = String((arg && arg.extension) || "");
      const phones = (Array.isArray(arg && arg.phones) ? arg.phones : []).slice(0, 20)
        .map((p) => ({ mac: String((p && p.mac) || "").slice(0, 32), brand: String((p && p.brand) || "").slice(0, 32) }))
        .filter((p) => p.mac);
      return /^\d{1,10}$/.test(ext) && phones.length ? { ext, phones } : null;
    };
    socket.on("deskPhoneStatus", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const a = deskPhoneArgs(arg);
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel) || !a) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const found = await deskPhoneRegs(channel, a.ext, a.phones);
        if (!found) return reply({ ok: false, reason: "not-found" });
        const status = {};
        for (const [mac, regs] of Object.entries(found.byMac)) status[mac] = { online: regs.length > 0, ip: regs[0] ? regs[0].ip : "", agent: regs[0] ? regs[0].agent : "" };
        reply({ ok: true, status });
      } catch (e) {
        console.log("[desk-phone] status failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });
    socket.on("deskPhoneReboot", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const a = deskPhoneArgs(arg);
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel) || !a || a.phones.length !== 1) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const found = await deskPhoneRegs(channel, a.ext, a.phones);
        if (!found) return reply({ ok: false, reason: "not-found" });
        const regs = Object.values(found.byMac)[0] || [];
        let sent = 0;
        for (const r of regs) {
          if (/[\s'"]/.test(r.callId)) continue;
          const res = await promisifiedApi(conn, `sofia profile internal check_sync ${r.callId}`).catch(() => null);
          const body = res && typeof res.getBody === "function" ? String(res.getBody() || "") : String((res && res.body) || "");
          if (body.includes("+OK")) sent++;
        }
        const sess = await phpSession().catch(() => null);
        const macLabel = String(a.phones[0].mac).toLowerCase();
        db2.query(
          `INSERT INTO audit_logs (domain_uuid, username, extension, ip_address, user_agent, feature, action, entity, result, metadata)
           VALUES ($1, $2, $3, $4, $5, 'ip-phone', 'reboot', $6, $7, $8::jsonb)`,
          [found.domainUuid, (sess && sess.username) || "unknown", (sess && sess.extension) || null,
            String(socket.handshake.address || "").slice(0, 45), String((socket.handshake.headers || {})["user-agent"] || "").slice(0, 500),
            `${a.ext} (${macLabel})`.slice(0, 255), regs.length ? (sent ? "success" : "failed") : "failed",
            JSON.stringify({ extension: a.ext, mac_address: macLabel, brand: a.phones[0].brand, registrations: regs.length, sent, reason: regs.length ? undefined : "not registered" })]
        ).catch((e) => console.log("[desk-phone] audit", e && e.message));
        console.log("[desk-phone] reboot", `${a.ext}@${channel}`, macLabel, "regs", regs.length, "sent", sent, "by", sess && sess.extension);
        if (!regs.length) return reply({ ok: false, reason: "offline" });
        return reply(sent ? { ok: true, sent } : { ok: false, reason: "not-sent" });
      } catch (e) {
        console.log("[desk-phone] reboot failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [desk-resync] IP Phones > Reprovision: ask the phone to download its provisioning file
    // again without rebooting (SIP NOTIFY "check-sync;reboot=false" to each of its registrations).
    // Reboot stays on 'deskPhoneReboot' (FreeSWITCH's check_sync, reboot=true).
    socket.on("deskPhoneResync", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const a = deskPhoneArgs(arg);
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel) || !a || a.phones.length !== 1) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const found = await deskPhoneRegs(channel, a.ext, a.phones);
        if (!found) return reply({ ok: false, reason: "not-found" });
        const regs = Object.values(found.byMac)[0] || [];
        const all = (await fsRegistration().catch(() => [])) || [];
        let sent = 0;
        for (const r of regs) {
          const full = all.find((x) => x && x["Call-ID"] === r.callId);
          if (!full) continue;
          const ev = new esl.Event("NOTIFY");
          ev.addHeader("profile", "internal");
          ev.addHeader("event-string", "check-sync;reboot=false");
          ev.addHeader("call-id", String(r.callId));
          ev.addHeader("user", String(full["Auth-User"] || ""));
          ev.addHeader("host", String(full["Auth-Realm"] || channel));
          ev.addHeader("content-type", "application/simple-message-summary");
          // sofia's NOTIFY handler sends to the registration's contact (same path as check_sync).
          const ok = await new Promise((resolve) => {
            const t = setTimeout(() => resolve(false), 3000);
            try {
              conn.sendEvent(ev, (res) => {
                clearTimeout(t);
                const txt = res && typeof res.getHeader === "function" ? String(res.getHeader("Reply-Text") || "") : "";
                resolve(txt.startsWith("+OK"));
              });
            } catch (e) { clearTimeout(t); resolve(false); }
          });
          if (ok) sent++;
        }
        const sess = await phpSession().catch(() => null);
        const macLabel = String(a.phones[0].mac).toLowerCase();
        db2.query(
          `INSERT INTO audit_logs (domain_uuid, username, extension, ip_address, user_agent, feature, action, entity, result, metadata)
           VALUES ($1, $2, $3, $4, $5, 'ip-phone', 'reprovision', $6, $7, $8::jsonb)`,
          [found.domainUuid, (sess && sess.username) || "unknown", (sess && sess.extension) || null,
            String(socket.handshake.address || "").slice(0, 45), String((socket.handshake.headers || {})["user-agent"] || "").slice(0, 500),
            `${a.ext} (${macLabel})`.slice(0, 255), sent ? "success" : "failed",
            JSON.stringify({ extension: a.ext, mac_address: macLabel, brand: a.phones[0].brand, registrations: regs.length, sent })]
        ).catch((e) => console.log("[desk-phone] audit", e && e.message));
        console.log("[desk-phone] reprovision", `${a.ext}@${channel}`, macLabel, "regs", regs.length, "sent", sent, "by", sess && sess.extension);
        if (!regs.length) return reply({ ok: false, reason: "offline" });
        return reply(sent ? { ok: true, sent } : { ok: false, reason: "not-sent" });
      } catch (e) {
        console.log("[desk-phone] reprovision failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [assigned-phones] Settings > Assigned Phones: the domain's signed-in mobile apps (iOS /
    // Android, from q_devices), with Release (sign the app out of its extension), Stop ringing
    // (stop push for the extension's recent rings) and Remove token (no more call pushes; the
    // app stays signed in). Owner / Manager sessions only.
    const assignedMobileRow = async (channel, id) => {
      const { rows } = await db2.query(
        `SELECT q.id, q.extension, q.device_type, q.push_token FROM q_devices q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
          WHERE d.domain_name = $1 AND q.id = $2 AND q.device_type::text IN ('1', '2') LIMIT 1`, [channel, id]);
      return rows[0] || null;
    };
    const forgetMobileToken = async (base, token) => {
      if (!token) return;
      await redisClient.sRem(`iosDevicePushToken-${base}`, token).catch(() => {});
      await redisClient.del(`androidTokenActive-${base}:${token}`).catch(() => {});
      for (const m of await redisClient.sMembers(base).catch(() => [])) {
        if (String(m) === token || String(m).startsWith(`${token}:`)) await redisClient.sRem(base, m).catch(() => {});
      }
    };
    socket.on("refreshOnlineStatus", () => {
      const { channel } = socket.handshake.auth || {};
      if (channel && /^[a-z0-9.-]+$/i.test(channel)) scheduleOnlineStatusRefresh(channel);
    });
    socket.on("assignedMobiles", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel)) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const { rows } = await db2.query(
          `SELECT q.id, q.extension, q.device_type, q.device_name, q.device_version, q.version, q.push_token,
                  coalesce(q.enabled, 1) AS enabled, greatest(q.insert_date, q.update_login) AS last_login
             FROM q_devices q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
            WHERE d.domain_name = $1 AND q.device_type::text IN ('1', '2')
            ORDER BY greatest(q.insert_date, q.update_login) DESC NULLS LAST, q.id DESC
            LIMIT 500`, [channel]);
        // registered right now: the app's SIP registration User-Agent names the platform
        const regs = (await fsRegistration().catch(() => [])) || [];
        const online = new Map(); // "<alias or ext>" -> Set(kind)
        for (const r of regs) {
          if (!r || r["Auth-Realm"] !== channel) continue;
          const kind = mobileKindOf(r.Agent);
          if (!kind) continue;
          const u = String(r["Auth-User"] || "");
          if (!online.has(u)) online.set(u, new Set());
          online.get(u).add(kind);
        }
        const aliasOf = new Map();
        try {
          const ex = await db2.query(
            `SELECT e.extension, e.number_alias FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid WHERE d.domain_name = $1`, [channel]);
          ex.rows.forEach((r) => aliasOf.set(String(r.extension), String(r.number_alias || "")));
        } catch (e) { /* online flag is optional */ }
        const now = Date.now();
        reply({
          ok: true,
          devices: rows.map((r) => {
            const kind = Number(r.device_type) === 2 ? "ios" : "android";
            const ext = String(r.extension || "");
            const regKinds = online.get(aliasOf.get(ext) || "") || online.get(ext) || new Set();
            const token = String(r.push_token || "");
            const ringing = (recentRings.get(`${ext}@${channel}`) || []).some((x) => now - x.at < 2 * 60 * 1000);
            return {
              id: r.id,
              extension: ext,
              kind,
              deviceName: String(r.device_name || ""),
              osVersion: String(r.device_version || ""),
              appVersion: String(r.version || ""),
              hasToken: token !== "",
              tokenTail: token ? token.slice(-6) : "",
              callsOn: Number(r.enabled) !== 0,
              registered: regKinds.has(kind),
              recentRing: ringing,
              lastLogin: r.last_login ? new Date(r.last_login).getTime() : null,
            };
          }),
        });
      } catch (e) {
        console.log("[assigned-phones] list failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });
    socket.on("assignedMobileAction", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const id = parseInt(String((arg && arg.id) || ""), 10);
        const action = String((arg && arg.action) || "");
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel) || !(id > 0) || !["release", "stop", "removeToken"].includes(action)) {
          return reply({ ok: false, reason: "bad-request" });
        }
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const row = await assignedMobileRow(channel, id);
        if (!row) return reply({ ok: false, reason: "not-found" });
        const ext = String(row.extension || "");
        const base = `${ext}@${channel}`;
        const token = String(row.push_token || "");
        const kind = Number(row.device_type) === 2 ? "ios" : "android";

        if (action === "release") {
          // the app signs itself out on status {device: <its push token>, action: 3} (same as Delete device)
          if (token) {
            const msg = { id: "", extension: ext, device: token, action: 3 };
            wallboard.to(base).emit("status", msg);
            wallboard.to(token).emit("status", msg);
          }
          await forgetMobileToken(base, token);
          await db2.query(`DELETE FROM q_devices WHERE id = $1`, [id]);
          console.log("[assigned-phones] released", base, kind, id);
          scheduleOnlineStatusRefresh(channel);
          return reply({ ok: true });
        }
        if (action === "removeToken") {
          await forgetMobileToken(base, token);
          await db2.query(`UPDATE q_devices SET push_token = '' WHERE id = $1`, [id]);
          console.log("[assigned-phones] token removed", base, kind, id);
          return reply({ ok: true });
        }
        // stop ringing
        if (!token) return reply({ ok: false, reason: "no-token" });
        const rings = (recentRings.get(base) || []).filter((r) => Date.now() - r.at < RECENT_RING_MS);
        if (!rings.length) return reply({ ok: false, reason: "no-recent-ring" });
        let sent = 0;
        for (const r of rings) {
          const payload = {
            domain: channel, extension: ext, id: r.id, call: kind === "ios" ? "" : "missed",
            caller_id: "", nameCaller: "", queue: "", queue_name: "", avatar: "",
            time: String(Date.now()), time2: new Date().toISOString(), kind: "ring",
          };
          if (kind === "android") {
            const data = { type: "ring", ...payload };
            const res = await assignedPhonesFcm().messaging().sendEachForMulticast({
              tokens: [token], data, android: { priority: "high", ttl: 120 * 1000, collapseKey: `call-${r.id}` },
            }).catch((e) => { console.log("[assigned-phones] fcm", e && e.message); return null; });
            sent += res ? res.successCount : 0;
          } else {
            const apns = assignedPhonesApns();
            if (!apns) return reply({ ok: false, reason: "no-apns" });
            const res = await apns.sendVoipMany([token], payload, r.id).catch(() => []);
            sent += (res || []).filter((x) => x.ok).length;
          }
        }
        console.log("[assigned-phones] stop ringing", base, kind, id, "rings", rings.length, "sent", sent);
        return reply(sent > 0 ? { ok: true, sent } : { ok: false, reason: "push-failed" });
      } catch (e) {
        console.log("[assigned-phones] action failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [moh] preview a music-on-hold file in the browser. The stored files are IMA-ADPCM WAV (what
    // FreeSWITCH plays), which browsers cannot decode, so convert to MP3 once and send the bytes.
    socket.on("mohPreview", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const uuid = String((arg && arg.uuid) || "");
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel) || !/^[0-9a-f-]{36}$/i.test(uuid)) return reply({ ok: false, reason: "bad-request" });
        const { rows } = await db2.query(
          `SELECT r.recording_filename FROM q_recordings r JOIN v_domains d ON d.domain_uuid = r.domain_uuid
            WHERE d.domain_name = $1 AND r.recording_uuid = $2 AND r.type::text = '5' LIMIT 1`, [channel, uuid]);
        if (!rows.length) return reply({ ok: false, reason: "not-found" });
        const base = String(rows[0].recording_filename || "").split("_")[0].replace(/\.[a-z0-9]+$/i, "");
        if (!/^[0-9A-Za-z-]+$/.test(base)) return reply({ ok: false, reason: "not-found" });
        const fs = require("fs");
        let src = "";
        for (const rate of ["16000", "8000", "32000", "48000"]) {
          const f = `${MOH_ROOT}/${channel}/${rate}/${base}.wav`;
          if (fs.existsSync(f)) { src = f; break; }
        }
        if (!src) return reply({ ok: false, reason: "not-found" });
        const dir = "/tmp/ringq-moh-preview";
        fs.mkdirSync(dir, { recursive: true });
        const out = `${dir}/${channel}-${base}.mp3`;
        if (!fs.existsSync(out) || fs.statSync(out).mtimeMs < fs.statSync(src).mtimeMs) {
          await new Promise((resolve, reject) => {
            ffmpeg().input(src).audioCodec("libmp3lame").audioBitrate("64k").audioChannels(1)
              .toFormat("mp3").on("end", resolve).on("error", reject).save(out + ".tmp");
          });
          fs.renameSync(out + ".tmp", out);
        }
        reply({ ok: true, mime: "audio/mpeg", data: fs.readFileSync(out) });
      } catch (e) {
        console.log("[moh] preview failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });

    // [moh] Randomized playback setting (Global Settings > Music on hold)
    socket.on("getMohMode", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      const { channel } = socket.handshake.auth || {};
      if (!channel) return reply({ ok: false });
      reply({ ok: true, ...(await getMohMode(channel)), files: mohFiles(channel).length });
    });
    socket.on("setMohMode", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        if (!channel || !/^[a-z0-9.-]+$/i.test(channel)) return reply({ ok: false, reason: "bad-request" });
        if (!(await queueTierSessionOk())) return reply({ ok: false, reason: "not-allowed" });
        const mode = { enabled: !!(arg && arg.enabled), type: arg && arg.type === "day" ? "day" : "call" };
        await redisClient.set(`mohMode-${channel}`, JSON.stringify(mode));
        console.log("[moh] randomized playback", channel, mode);
        reply({ ok: true, ...mode });
      } catch (e) { reply({ ok: false, reason: "error" }); }
    });

    // [schedules] an extension's own office/break hours as saved by api/scheduling. The extension
    // API never returns them, so the Schedules tab always showed "All Day"/"None" and the default
    // action -- and saving one day then overwrote the real action. Read-only, this domain only.
    socket.on("getExtensionSchedule", async (arg, ack) => {
      const reply = (o) => { try { if (typeof ack === "function") ack(o); } catch (e) {} };
      try {
        const { channel } = socket.handshake.auth || {};
        const ext = String((arg && arg.extension) || "");
        if (!channel || !/^\d{2,10}$/.test(ext)) return reply({ ok: false, reason: "bad-request" });
        const { rows } = await db2.query(
          `SELECT p.dialplan_name, p.dialplan_description FROM v_dialplans p
             JOIN v_domains d ON d.domain_uuid = p.domain_uuid
            WHERE d.domain_name = $1 AND p.dialplan_number = $2
              AND p.dialplan_name ~ '^(nonwork|break)-[1-7]$' AND coalesce(p.dialplan_enabled, 'true') = 'true'`,
          [channel, ext]);
        const office = {}, brk = {}, officeExtra = {}, breakExtra = {};
        let officeDest = null, breakDest = null;
        for (const r of rows) {
          const parts = String(r.dialplan_description || "").split("-");
          if (parts.length < 4) continue;
          const [kind, dow, a, b] = parts;
          // field 5 (optional) holds extra windows for the day: "start_end.start_end"
          const extra = /^\d+_\d+(\.\d+_\d+)*$/.test(parts[5] || "") ? parts[5] : "";
          const dest = extra ? (parts[4] || "") : parts.slice(4).join("-");
          if (!/^[1-7]$/.test(dow) || !/^\d+$/.test(a) || !/^\d+$/.test(b)) continue;
          if (kind === "1") { office[dow] = [Number(a), Number(b)]; if (extra) officeExtra[dow] = extra; if (officeDest === null) officeDest = dest; }
          if (kind === "2") { brk[dow] = [Number(a), Number(b)]; if (extra) breakExtra[dow] = extra; if (breakDest === null) breakDest = dest; }
        }
        const g = await db2.query(
          `SELECT t.work_extensions, t.break_extensions FROM q_time_schedules t
             JOIN v_domains d ON d.domain_uuid = t.domain_uuid WHERE d.domain_name = $1 LIMIT 1`, [channel]);
        const inList = (csv) => String(csv || "").split(",").map((x) => x.trim()).includes(ext);
        reply({ ok: true, office, break: brk, officeExtra, breakExtra, officeDest: officeDest || "", breakDest: breakDest || "",
          globalOffice: g.rows.length ? inList(g.rows[0].work_extensions) : false,
          globalBreak: g.rows.length ? inList(g.rows[0].break_extensions) : false });
      } catch (e) {
        console.log("[schedules] failed", e && e.message);
        reply({ ok: false, reason: "error" });
      }
    });

    socket.on("getCallJourneys", async (uuids, ack) => {
      const out = {};
      try {
        journey.init(redisClient);
        const list = Array.isArray(uuids) ? uuids.slice(0, 60) : [];
        const seenRoots = new Map();
        for (const u of list) {
          if (!journey.isUuid(u) || !(await journey.has(u))) continue;
          const root = await journey.rootOf(u);
          if (!seenRoots.has(root)) {
            const { steps } = await journey.get(root);
            seenRoots.set(root, { root, ...journey.summarize(steps), timeline: journey.timeline(steps) });
          }
          out[u] = seenRoots.get(root);
        }
        // older calls (Redis copy expired) come from the permanent store
        const missing = list.filter((u) => journey.isUuid(u) && !out[u]);
        if (missing.length) {
          const { rows } = await db2.query(
            `SELECT * FROM q_call_journeys WHERE legs && $1::text[] LIMIT 200`, [missing]);
          for (const r of rows) {
            const j = journey.fromDbRow(r);
            for (const u of missing) if ((r.legs || []).includes(u)) out[u] = j;
          }
        }
      } catch (e) {
        console.error("[call-journey] getCallJourneys", e?.message || e);
      }
      if (typeof ack === "function") ack(out);
    });

    socket.on("parkAttended", async (arg) => {
      

      const presenceExtension = `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`;
      const SIP = await redisClient.get(
        `SIP-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      );
      const subdomain = `${socket.handshake.auth.channel}`;

      socketClient3.emit("node-parkAttended", {
        presenceExtension: socket.handshake.auth.extension,
        subdomain: subdomain,
        arg: arg,
      });

      console.log("<!-- PARK ATTENDED", {
        arg: arg,
        socket: `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
      });

    });

    socket.on("attendedTimeOut", async(arg) => {
        
        const OrigDestination = {'legA': arg.legA, 'legB': arg.legB}
        wallboard.to(arg.subdomain).emit("parkAttendedTransfer2", OrigDestination);
    })

    socket.on("parkAttendedTransfer", async (dest) => {
      try {
        const {
          extension,
          channel
        } = socket.handshake.auth;

        const attendedPresence = await redisClient.get(`attendedSent-${extension}@${channel}`);
        if (!attendedPresence) {
          // [att-recover] consult already finished/recovered (e.g. target no-answer) - nothing to complete
          console.log("[att-recover] parkAttendedTransfer ignored: no active attended transfer", { extension, channel, dest });
          return;
        }
        const { parkCall , transferCall} = JSON.parse(attendedPresence);
        const bId = await redisClient.get(`answered-presence-${dest}@${channel}`);

        // [att-recover] Only complete the transfer when the transfer target has a real, live
        // answered leg. Previously this parked the customer and bridged it to a stale/missing
        // leg (or to itself when dest was the customer's own number), stranding the customer
        // in park. If the target leg is not valid, put the customer back with the agent.
        const attAlive = async (u) => {
          if (!u) return false;
          const r = await promisifiedApi(conn, `uuid_exists ${u}`);
          return String(r?.body || "").trim() === "true";
        };
        if (!bId || bId === parkCall || bId === transferCall || !(await attAlive(bId))) {
          console.log("[att-recover] parkAttendedTransfer: no valid answered target leg, not parking customer", { dest, bId, parkCall, transferCall });
          journey.init(redisClient);
          await journey.add(parkCall, 'attended_returned', { by: extension, reason: bId === parkCall ? 'cancelled by agent' : 'target not connected' }); // [call-journey]
          if ((await attAlive(parkCall)) && (await attAlive(transferCall))) {
            await promisifiedApi(conn, `uuid_bridge ${parkCall} ${transferCall}`); // [att-retry] caller first, so a transfer after Cancel holds the caller
            await promisifiedApi(conn, `uuid_setvar ${parkCall} rq_att_agent_leg`); // [att-vm] retrieved during voicemail
            await promisifiedApi(conn, `uuid_setvar ${transferCall} rq_att_agent_leg`);
            console.log("[att-recover] reconnected agent", transferCall, "to customer", parkCall);
          }
          await redisClient.del(`attendedSent-${extension}@${channel}`);
          wallboard.to(channel).emit("declineAttendedTransfer", `${dest}@${channel}`);
          return;
        }

        // [att-display] after a completed transfer the target should see the original caller
        const custNumRaw = String((await promisifiedApi(conn, `uuid_getvar ${parkCall} caller_id_number`))?.body || "").trim();
        // A gone channel answers "-ERR No such channel!", which must not reach the
        // clients as the caller's number (it did, and was shown as a party name).
        const custNum = custNumRaw && custNumRaw !== "_undef_" && !custNumRaw.startsWith("-ERR") ? custNumRaw : extension;
        const custQueueRaw = String((await promisifiedApi(conn, `uuid_getvar ${parkCall} queue_extension`))?.body || "").trim();
        const completedDest = { legA: dest, legB: custNum, queue: /^\d+$/.test(custQueueRaw) ? custQueueRaw : "" };
        journey.init(redisClient);
        await journey.add(parkCall, 'attended_complete', { by: extension, to: dest }); // [call-journey]
        await journey.link(bId, parkCall);

        console.log("EMAN 11");

        console.log(`☎️`, bId, parkCall, dest, extension);
        //Added 08-28-28
        console.log("transfer 8SI");
        console.log("this is the command use in manually that works", `uuid_bridge ${bId} ${parkCall}`);
        const getValidatePark = await redisClient.get(`validatePark-${dest}`);
        if(getValidatePark){

      try {
        // Helper to execute bgapi commands cleanly
        const execBgapi = (cmd) => new Promise((resolve) => {
            conn.bgapi(cmd, (res) => {
                const body = (typeof res?.getBody === 'function' ? res.getBody() : res?.body || '').trim();
                resolve(body);
            });
        });

        // 1. Prevent automatic post-bridge hangups on all involved legs
        const legsToProtect = [bId, parkCall, transferCall].filter(Boolean);
        
        for (const uuid of legsToProtect) {
            await execBgapi(`uuid_setvar ${uuid} hangup_after_bridge false`);
            await execBgapi(`uuid_setvar ${uuid} park_after_bridge false`);
            await execBgapi(`uuid_setvar ${uuid} auto_hangup false`);
            await execBgapi(`uuid_setvar ${uuid} transfer_after_bridge false`);
        }

        // 2. Force answer on bId to prevent early-media hangup drops
        await execBgapi(`uuid_answer ${bId}`);

        // 3. Execute bridge (Formatted strictly without trailing or double spaces)
        console.log("bridging... 9")
        const bridgeCmd = `uuid_bridge ${bId} ${parkCall}`.replace(/\s+/g, ' ').trim();
        console.log("Executing direct bridge command:", bridgeCmd);

        const bridgeRes = await execBgapi(bridgeCmd);
        console.log("[Bridge Output]:", bridgeRes);

        if (bridgeRes.includes('+OK')) {
            await redisClient.del(`validatePark-${dest}`);
            
            // Hang up only the transferer leg if you want to complete the transfer cleanly
            if (transferCall) {
                await execBgapi(`uuid_kill ${transferCall} NORMAL_CLEARING`);
            }

            const OrigDestination = completedDest; // [att-display]
            wallboard.to(channel).emit("parkAttendedTransfer2", OrigDestination);
        } else {
            console.error("Bridge command failed:", bridgeRes);
        }

        } catch (err) {
            console.error("Error during attended transfer bridge:", err.message);
        }
    
          redisClient.del(`validatePark-${dest}`);
          console.log("deleting it now no sched del", `validatePark-${dest}`);

          const OrigDestination = completedDest; // [att-display]

          wallboard.to(channel).emit("parkAttendedTransfer2", OrigDestination);
          console.log("sent to", channel, "with parkAttendedTransfer2", OrigDestination)

        } else{

          console.log("EMAN2 33");

           const getValidatePark = await redisClient.get(`addValidateUUIDPark-${extension}`);
            console.log("get this", `addValidateUUIDPark-${extension}`)
           if(getValidatePark){


            redisClient.del(`addValidateUUIDPark-${extension}`)
            console.log("for currentCallA onlyy")
            
            console.log("this is the getValidate", `addValidateUUIDPark-${extension}`);
            async function api(conn, command) {
              const res = await promisifiedApi(conn, command);
              console.log(command, res?.body || res);
              return res;
            }

            function delay(ms) {
              return new Promise(resolve => setTimeout(resolve, ms));
            }

            async function uuidExists(conn, uuid) {
              if (!uuid) return false;

              const res = await api(conn, `uuid_exists ${uuid}`);
              return String(res?.body || res).trim() === "true";
            }

            async function getChannels(conn) {
              const res = await api(conn, "show channels as json");

              try {
                const data = JSON.parse(res.body);
                return data.rows || [];
              } catch (err) {
                console.error("Failed to parse show channels:", err);
                return [];
              }
            }

            async function getChannelByUuid(conn, uuid) {
              const rows = await getChannels(conn);
              return rows.find(row => row.uuid === uuid);
            }

            async function protectLeg(conn, uuid) {
              if (!uuid) return false;

              const exists = await uuidExists(conn, uuid);

              if (!exists) {
                console.log("Cannot protect. UUID missing:", uuid);
                return false;
              }

              await api(conn, `uuid_setvar ${uuid} hangup_after_bridge false`);
              await api(conn, `uuid_setvar ${uuid} park_after_bridge true`);
              await api(conn, `uuid_setvar ${uuid} auto_hangup false`);
              await api(conn, `uuid_setvar ${uuid} transfer_after_bridge false`);
              await api(conn, `uuid_setvar ${uuid} continue_on_fail true`);

              return true;
            }

            async function protectLegs(conn, uuids) {
              for (const uuid of uuids) {
                await protectLeg(conn, uuid);
              }
            }

            function detectAttendedLegs(rows, {
              parkCall,
              transferCall,
              bId,
            }) {
              /*
                Find parked LegB.
                In your latest log, this is:
                7a1a18d8... state CS_PARK
              */
              const parkedRows = rows.filter(row => row.state === "CS_PARK");

              const legBRow =
                rows.find(row => row.uuid === parkCall && row.state === "CS_PARK") ||
                rows.find(row => row.uuid === bId && row.state === "CS_PARK") ||
                rows.find(row => row.uuid === transferCall && row.state === "CS_PARK") ||
                parkedRows[0];

              /*
                Find LegC.
                Usually this is the active outbound media leg:
                state CS_EXCHANGE_MEDIA
              */
              const legCRow =
                rows.find(row => row.uuid === bId && row.uuid !== legBRow?.uuid) ||
                rows.find(row =>
                  row.state === "CS_EXCHANGE_MEDIA" &&
                  row.uuid !== legBRow?.uuid
                );

              /*
                Find LegA.
                Usually shares call_uuid with LegC or has application bridge.
              */
              const legARow =
                rows.find(row =>
                  row.uuid !== legBRow?.uuid &&
                  row.uuid !== legCRow?.uuid &&
                  (
                    row.uuid === transferCall ||
                    row.call_uuid === legCRow?.call_uuid ||
                    row.application === "bridge" ||
                    row.application === "uuid_bridge"
                  )
                );

              return {
                legA: legARow?.uuid,
                legB: legBRow?.uuid,
                legC: legCRow?.uuid,
                legARow,
                legBRow,
                legCRow,
              };
            }

            async function logDetectedLegs(conn, label, legs) {
              const rows = await getChannels(conn);

              const aRow = rows.find(row => row.uuid === legs.legA);
              const bRow = rows.find(row => row.uuid === legs.legB);
              const cRow = rows.find(row => row.uuid === legs.legC);

              console.log(label, {
                legA: legs.legA,
                legAState: aRow?.state,
                legACallstate: aRow?.callstate,
                legAApplication: aRow?.application,
                legAApplicationData: aRow?.application_data,

                legB: legs.legB,
                legBState: bRow?.state,
                legBCallstate: bRow?.callstate,
                legBApplication: bRow?.application,
                legBApplicationData: bRow?.application_data,

                legC: legs.legC,
                legCState: cRow?.state,
                legCCallstate: cRow?.callstate,
                legCApplication: cRow?.application,
                legCApplicationData: cRow?.application_data,
              });

              return { aRow, bRow, cRow };
            }

            async function createUuid(conn) {
              const res = await api(conn, "create_uuid");
              return String(res?.body || res).trim();
            }

            async function createNewLegBAndBridgeToLegC(conn, {
              legC,
              extensionB,
              subdomain,
              callerIdNumber,
            }) {
              console.log("Creating new LegB because old LegB is gone.", {
                legC,
                extensionB,
                subdomain,
                callerIdNumber,
              });

              const legCExists = await uuidExists(conn, legC);

              if (!legCExists) {
                console.log("Stop. LegC is missing. Cannot recover.");
                return false;
              }

              await protectLeg(conn, legC);

              const newLegB = await createUuid(conn);

              const getName = await redisClient.get(`getOriginateName-${extensionB}@${subdomain}`)
              
              const realName = getName 
              console.log("this is the realName", `getOriginateName-${extensionB}@${subdomain}`, realName)
              const originateCommand =
                `originate {` +
                `origination_uuid=${newLegB},` +
                `presence_id=${extensionB}@${subdomain},` +
                `origination_caller_id_number=${callerIdNumber},` +
                `origination_caller_id_name='${getName}',` +
                `hangup_after_bridge=false,` +
                `park_after_bridge=true,` +
                `auto_hangup=false,` +
                `transfer_after_bridge=false,` +
                `continue_on_fail=true` +
                `}` +
                `user/${extensionB}@${subdomain} &park()`;



              console.log("This is what i originate",`originate {` +
                
                `origination_uuid=${newLegB},` +
                `presence_id=${extensionB}@${subdomain},` +
                `origination_caller_id_number=${callerIdNumber},` +
                `origination_caller_id_name=${getName},` +
                `hangup_after_bridge=false,` +
                `park_after_bridge=true,` +
                `auto_hangup=false,` +
                `transfer_after_bridge=false,` +
                `continue_on_fail=true` +
                `}` +
                `user/${extensionB}@${subdomain} &park()`)

              await api(conn, originateCommand);

              await delay(1500);

              const newLegBExists = await uuidExists(conn, newLegB);

              if (!newLegBExists) {
                console.log("Stop. New LegB failed to create.", {
                  newLegB,
                  extensionB,
                });

                return false;
              }

              await protectLeg(conn, newLegB);

              const finalCExists = await uuidExists(conn, legC);

              if (!finalCExists) {
                console.log("Stop. LegC disappeared before bridge.");
                return false;
              }

              await api(conn, `sched_del ${legC}`);

              console.log("Bridging new LegB + LegC", {
                newLegB,
                legC,
              });

              await api(conn, `uuid_bridge ${newLegB} ${legC}`);

              return {
                success: true,
                newLegB,
                legC,
              };
            }

            async function attendedBridgeAutoDetect(conn, {
              transferCall,
              parkCall,
              bId,
              subdomain,
              extensionB,
              callerIdNumber,
            }) {
              const rows = await getChannels(conn);

              const detected = detectAttendedLegs(rows, {
                parkCall,
                transferCall,
                bId,
              });

              console.log("Detected legs:", detected);

              if (!detected.legB) {
                console.log("Stop. No parked LegB detected.");
                return false;
              }

              if (!detected.legC) {
                console.log("Stop. No LegC detected.");
                return false;
              }

              await logDetectedLegs(conn, "Before start:", detected);

              /*
                IMPORTANT:
                Do not park LegB.
                Do not uuid_transfer LegB park inline.
                It is already CS_PARK.
              */
              if (detected.legBRow?.state !== "CS_PARK") {
                console.log("Detected LegB is not CS_PARK. Stop to avoid hangup.", {
                  legB: detected.legB,
                  state: detected.legBRow?.state,
                });

                return false;
              }

              await protectLegs(conn, [
                detected.legA,
                detected.legB,
                detected.legC,
              ]);

              await delay(300);

              await logDetectedLegs(conn, "After protect:", detected);

              /*
                Do not transfer LegB.
                Only release LegC from LegA side.
              */
              if (detected.legA) {
                console.log("Parking LegA only. Do not touch LegB.");

                await api(conn, `uuid_transfer ${detected.legA} park inline`);

                await delay(1000);
              }

              const afterParkRows = await getChannels(conn);

              const afterDetected = {
                legA: detected.legA,
                legB: detected.legB,
                legC: detected.legC,
              };

              const afterA = afterParkRows.find(row => row.uuid === detected.legA);
              const afterB = afterParkRows.find(row => row.uuid === detected.legB);
              const afterC = afterParkRows.find(row => row.uuid === detected.legC);

              console.log("After parking A:", {
                legA: detected.legA,
                legAState: afterA?.state,

                legB: detected.legB,
                legBState: afterB?.state,

                legC: detected.legC,
                legCState: afterC?.state,
              });

              if (!afterC) {
                console.log("Stop. LegC disappeared.");
                return false;
              }

              if (!afterB) {
                console.log("Old LegB disappeared. Creating new LegB.");

                return await createNewLegBAndBridgeToLegC(conn, {
                  legC: detected.legC,
                  extensionB,
                  subdomain,
                  callerIdNumber,
                });
              }

              const [legBExists, legCExists] = await Promise.all([
                uuidExists(conn, detected.legB),
                uuidExists(conn, detected.legC),
              ]);

              if (!legBExists && legCExists) {
                console.log("LegB missing but LegC exists. Creating new LegB.");

                return await createNewLegBAndBridgeToLegC(conn, {
                  legC: detected.legC,
                  extensionB,
                  subdomain,
                  callerIdNumber,
                });
              }

              if (!legCExists) {
                console.log("Stop. LegC missing before bridge.");
                return false;
              }

              console.log("Bridging existing LegB + LegC.", {
                legB: detected.legB,
                legC: detected.legC,
              });

              await api(conn, `sched_del ${detected.legC}`);
              await api(conn, `uuid_bridge ${detected.legB} ${detected.legC}`);

              await delay(700);

              await logDetectedLegs(conn, "After bridge:", afterDetected);

              return true;
            }

            console.log("this is extension", extension, "this is dest", dest, "getValidatePark", getValidatePark)
                await attendedBridgeAutoDetect(conn, {
              transferCall,
              parkCall,
              bId,
              subdomain: `${channel}`,
              extensionB: getValidatePark,
              callerIdNumber: dest,
            });

           }


           else {
            console.log("for currentCallB onlyy")
              conn.api(`sched_del ${bId}`);
              conn.api(`uuid_transfer ${bId} park inline`);
              conn.api(`uuid_bridge ${parkCall} ${bId}`);
                //  await promisifiedApi(
                //       conn, `uuid_setvar ${currentCallB.b_uuid} variable_transferType "Attended"`
                //   ) variable_transferType "Attended"
                
              conn.api(`uuid_setvar ${bId} variable_transferType "Attended"`);
              conn.api(`uuid_setvar ${bId} variable_callFeatures "Attended_Transfer"`);
              
              const OrigDestination = completedDest; // [att-display]
              // conn.api(`uuid_bridge ${parkCall} ${bId}`);
              wallboard.to(channel).emit("parkAttendedTransfer2", OrigDestination);
              console.log("sent to", channel, "with parkAttendedTransfer2", OrigDestination)
           }





          // console.log("bridging... 10")
          // const OrigDestination = completedDest; // [att-display]
          

          // //redisClient.set(`addValidateUUIDPark-${arg}`, `${currentCallA.b_uuid}`);


          // console.log(`SET TO test: uuid_bridge ${parkCall} ${bId}`);
          // console.log(`SET TO test: uuid_transfer ${bId} park inline`);
          // wallboard.to(channel).emit("parkAttendedTransfer2", OrigDestination);
          // console.log("sent to", channel, "with parkAttendedTransfer2", OrigDestination)



        }
        //Added 08-28-28
       
        conn.api(`uuid_exists ${bId}`, (res) => console.log(`Dest->☎️`,res));
        conn.api(`uuid_exists ${parkCall}`, (res) => console.log(`parkCall->☎️`,res));
      
        // conn.api(`uuid_dump ${parkCall} JSON`, async ({ body }) => {
        //   if(body === '-ERR No such channel!\n') return;
        //   const data = JSON.parse(body); 
        //   console.log(`DUMP CALL QUEUE`, data);

        //   conn.api(`limit_usage db ${channel} ${dest}`, (res) => {
        //     const usage = parseInt(res.getBody().trim()) || 0;
        //     console.log(`Usage for ${dest}: ${usage}`);
        //     if (usage === 0) {
        //       conn.api(`uuid_limit ${bId} db ${channel} ${dest} 1`);
        //     }
        //   });
          
        //   if(data['Channel-Name'].includes('internal')){

        //     const extensionNumber = async (ext, subdomain) => {
        //       const { rows } = await db2.query(
        //        `SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2`,
        //        [ext , subdomain]
        //       );
        //       return rows.length ? rows[0].extension : ext;
        //     }
        //     const extension2 = await presenceId(data['Channel-Name']);
        //     const origExtension2 = await extensionNumber(extension2, channel);

        //     conn.api(`limit_usage db ${channel} ${origExtension2}`, (res) => {
        //     const usage = parseInt(res.getBody().trim()) || 0;
        //     console.log(`Usage for ${origExtension2}: ${usage}`);
        //     if (usage === 0) {
        //         conn.api(`uuid_limit ${parkCall} db ${channel} ${origExtension2} 1`);
        //       }
        //     });
        //     console.log(`LIMIT uuid_limit ${parkCall} db ${channel} ${origExtension2} 1 - uuid_limit ${bId} db ${channel} ${dest} 1`)

        //   }

        //   // const {
        //   //   variable_cc_queue,
        //   //   variable_cc_agent,
        //   //   variable_cc_queue_answered_epoch,
        //   //   variable_cc_queue_joined_epoch
        //   // } = data;

        //   // socketQueueServer.emit("wallBoardButton", socket.handshake.auth.channel);

        //   // if(!variable_cc_queue && !variable_cc_agent) return;
        //   // conn.api(`uuid_setvar ${parkCall} attended_process queue`);
        //   // const [queueExt, queSubdomain] = variable_cc_queue.split('@');
        //   // const timeTerminate = Math.floor(Date.now() / 1000);

        //   // const waitingSeconds = Number(variable_cc_queue_answered_epoch) - Number(variable_cc_queue_joined_epoch)
        //   // const answeredSeconds = Number(timeTerminate) - Number(variable_cc_queue_answered_epoch)
        //   // socketQueueServer.emit("broadcast", { 
        //   //     'uuid': parkCall, 
        //   //     'extension': queueExt, 
        //   //     'callerName': null,
        //   //     'callerNumber': null, 
        //   //     'channel': queSubdomain, 
        //   //     'command': 'ended',
        //   //     'cc_agent': variable_cc_agent,
        //   //     'call_center_queue_uuid': parkCall,
        //   //     waitingSeconds: waitingSeconds,
        //   //     answeredSeconds: answeredSeconds,
        //   //     lastBridgeStart: Number(variable_cc_queue_answered_epoch),
        //   //     lastBridgeEnd: Number(timeTerminate),
        //   //     lastOfferedCall:  Number(timeTerminate)
        //   // });

        // });

        // redisClient.set(
        //   `attendedProcess-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
        //   1,
        //   { EX: 2 }
        // );
        // const attendedPresence = await redisClient.get(
        //   `attendedSent-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
        // );

        // console.log("Attended Process Button", {
        //   socket: `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
        //   destination: dest,
        //   attendedPresence: attendedPresence,
        // });

        // socketClient3.emit(
        //   "node-parkAttendedTransfer",
        //   {
        //     presenceExtension: socket.handshake.auth.extension,
        //     subdomain: socket.handshake.auth.channel,
        //     dest: dest,
        //   },
        //   (response) => {
        //     if (!response.state) {
        //       console.error("Error Attended Proccess!");
        //     } else {
        //       activeCallsEmit(response.result, response.dest, response.sipId);
        //     }
        //   }
        // );

      } catch (error) {
        console.error("Error processing call data:", error);
      }
    });

    socket.on("unparkAttendedTransfer", async (dest) => {
      console.log("Attended Button2 Unpark", {
        socket: `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
        destination: dest,
      });

      socketClient3.emit("node-unparkAttendedTransfer", {
        presenceExtension: socket.handshake.auth.extension,
        subdomain: socket.handshake.auth.channel,
        dest: dest,
      });
    });

    socket.on("unparkAttendedTransfer2", async (dest) => {
      console.log("Attended Button2 Unpark2", {
        socket: `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
        destination: dest,
      });

      let finalDest;

      finalDest = dest
      if(dest.length !== 4){
        console.log("this is invalid dest", dest);
        const number = dest.split(' (')[0].trim();
        finalDest = number;
      }

      console.log("final dest", finalDest);
      socketClient3.emit("node-unparkAttendedTransfer2", {
        presenceExtension: socket.handshake.auth.extension,
        subdomain: socket.handshake.auth.channel,
        dest: finalDest,
      });
    });

    socket.on("recoverAttendedTransfer", async (dest) => {

      const {
        extension,
        channel
      } = socket.handshake.auth;

      console.log(`🚩CHANNEL NAME : node-recoverAttendedTransfer`, extension, dest);

      if(dest === extension){

        const prev = await redisClient.get(`attendedSent-${extension + '@' + channel}`);
        const {parkCall, transferCall} = JSON.parse(prev);

        console.log(`☎️☎️☎️☎️`, parkCall, transferCall);
        if(!parkCall && !transferCall) return;

        conn.api(`uuid_exists ${parkCall}`, async (res) => {
          console.log(`☎️p_res☎️`, res)
        });

        conn.api(`uuid_exists ${transferCall}`, async (res) => {
          console.log(`☎️t_res☎️`, res)
        });
        console.log("transfer 9SI")
        
        await promisifiedApi(conn, `sched_del ${parkCall}`);
        await promisifiedApi(conn, `uuid_transfer ${parkCall} ${extension} XML ${channel}`);


      }else{


        const prev = await redisClient.get(`attendedSent-${extension + '@' + channel}`);
        const {parkCall, transferCall} = JSON.parse(prev);

        console.log(`☎️☎️☎️☎️`, parkCall, transferCall);
        if(!parkCall && !transferCall) return;

        conn.api(`uuid_exists ${parkCall}`, async (res) => {
          console.log(`☎️p_res☎️`, res)
        });

        conn.api(`uuid_exists ${transferCall}`, async (res) => {
          console.log(`☎️t_res☎️`, res)
        });
        console.log("transfer 10SI")
        await promisifiedApi(conn, `sched_del ${parkCall}`);
        await promisifiedApi(conn, `uuid_transfer ${parkCall} ${extension} XML ${channel}`);

        //Added 09-02-26
        const getPushPayloadAndroid = await redisClient.get(`pushNotificationAndroid-${extension}@${channel}`);
        console.log("get this", `pushNotificationAndroid-${extension}@${channel}`)


        if(getPushPayloadAndroid){
                socketClient3.emit("node-pushBackCall", {
                  presenceExtension: extension,
                  subdomain: `${channel}`,
                  dest: dest,
          });
        }
        else{
          //Added 09-04-26

    const retrieveExtension = await redisClient.get(
    `retrieveFirstCalleeExtension-${extension}`
);

//Added 09-04-26
// if (retrieveExtension) {

//     console.log(
//         "ret",
//         `retrieveFirstCalleeExtension-${extension}`
//     );

//     const aId = await redisClient.get(
//         `answered-presence-${extension}@${channel}`
//     );

//     const bId = await redisClient.get(
//         `answered-presence-${retrieveExtension}@${channel}`
//     );

//     // Extract body/string from promisifiedApi response
//     const getApiValue = (result) => {
//         if (!result) return null;

//         if (typeof result === 'string') {
//             return result.trim();
//         }

//         if (typeof result.getBody === 'function') {
//             return String(result.getBody()).trim();
//         }

//         if (result.body) {
//             return String(result.body).trim();
//         }

//         if (result.response) {
//             return String(result.response).trim();
//         }

//         return null;
//     };


//     const uuidExists = async (uuid) => {
//         if (!uuid) return false;

//         const response = await promisifiedApi(
//             conn,
//             `uuid_exists ${uuid}`
//         );

//         const result = getApiValue(response);

//         return result === 'true';
//     };


//     // =========================================================
//     // CREATE B
//     // B = retrieveExtension
//     // =========================================================
//     const createBCall = async () => {

//         try {

//             const uuidResponse = await promisifiedApi(
//                 conn,
//                 'create_uuid'
//             );

//             const newBId = getApiValue(uuidResponse);

//             if (!newBId) {
//                 console.log("Failed to generate B UUID");
//                 return null;
//             }

//             console.log("Creating new B UUID:", newBId);

//             const originateCommand =
//                 `originate {` +
//                 `origination_uuid=${newBId},` +
//                 `presence_id=${retrieveExtension}@${channel},` +
//                 `origination_caller_id_number=${extension}` +
//                 `}` +
//                 `user/${retrieveExtension}@${channel} &park()`;

//             console.log("CREATE B:", originateCommand);

//             const result = await promisifiedApi(
//                 conn,
//                 originateCommand
//             );

//             console.log("CREATE B RESULT:", result);

//             const exists = await uuidExists(newBId);

//             if (!exists) {
//                 console.log(
//                     "New B UUID was not created:",
//                     newBId
//                 );

//                 return null;
//             }

//             await redisClient.set(
//                 `answered-presence-${retrieveExtension}@${channel}`,
//                 newBId
//             );

//             return newBId;

//         } catch (error) {

//             console.error(
//                 "createBCall error:",
//                 error
//             );

//             return null;
//         }
//     };


//     // =========================================================
//     // CREATE A
//     // A = extension
//     // =========================================================
//     const createACall = async () => {

//         try {

//             const uuidResponse = await promisifiedApi(
//                 conn,
//                 'create_uuid'
//             );

//             const newAId = getApiValue(uuidResponse);

//             if (!newAId) {
//                 console.log("Failed to generate A UUID");
//                 return null;
//             }

//             console.log("Creating new A UUID:", newAId);

//             const originateCommand =
//                 `originate {` +
//                 `origination_uuid=${newAId},` +
//                 `presence_id=${extension}@${channel},` +
//                 `origination_caller_id_number=${retrieveExtension}` +
//                 `}` +
//                 `user/${extension}@${channel} &park()`;

//             console.log("CREATE A:", originateCommand);

//             const result = await promisifiedApi(
//                 conn,
//                 originateCommand
//             );

//             const bridgePayload = {
//               aLeg: newAId,
//               bLeg: bId
//             }
//             redisClient.set(`afterCreateBridge-${extension}`, JSON.stringify(bridgePayload));

//             console.log("CREATE A RESULT:", result);

//             const exists = await uuidExists(newAId);

//             if (!exists) {
//                 console.log(
//                     "New A UUID was not created:",
//                     newAId
//                 );

//                 return null;
//             }

//             await redisClient.set(
//                 `answered-presence-${extension}@${channel}`,
//                 newAId
//             );

//             return newAId;

//         } catch (error) {

//             console.error(
//                 "createACall error:",
//                 error
//             );

//             return null;
//         }
//     };


//     // =========================================================
//     // VALIDATE EXISTING UUIDs
//     // =========================================================

//     const aExists = await uuidExists(aId);
//     const bExists = await uuidExists(bId);

//     console.log("UUID STATUS:", {
//         extension,
//         retrieveExtension,
//         aId,
//         aExists,
//         bId,
//         bExists
//     });


//     // =========================================================
//     // BOTH EXIST
//     // =========================================================

//     if (aExists && bExists) {

//         console.log(
//             "Both UUIDs exist:",
//             aId,
//             bId
//         );

//         await redisClient.del(
//             `retrieveFirstCalleeExtension-${extension}`
//         );

//         await promisifiedApi(
//             conn,
//             `uuid_bridge ${aId} ${bId}`
//         );
//     }


//     // =========================================================
//     // A EXISTS / B MISSING
//     // =========================================================

//     else if (aExists && !bExists) {

//         console.log(
//             "A exists, B missing"
//         );

//         const newBId = await createBCall();

//         if (newBId) {

//             console.log(
//                 "BRIDGE A + NEW B:",
//                 aId,
//                 newBId
//             );

//             await promisifiedApi(
//                 conn,
//                 `uuid_bridge ${aId} ${newBId}`
//             );
//         }
//     }


//     // =========================================================
//     // B EXISTS / A MISSING
//     // =========================================================

//     else if (!aExists && bExists) {

//         console.log(
//             "B exists, A missing"
//         );


//         const newAId = await createACall();

//         // if (newAId) {

//             console.log(
//                 "BRIDGE NEW A + B:",
//                 newAId,
//                 bId
//             );
            
//             await promisifiedApi(
//                 conn,
//                 `uuid_bridge ${newAId} ${bId}`
//             );
        
//     }


//     // =========================================================
//     // BOTH MISSING
//     // =========================================================

//     else {

//         console.log(
//             "Both A and B UUIDs are missing"
//         );

//         const newAId = await createACall();
//         const newBId = await createBCall();

//         if (newAId && newBId) {

//             console.log(
//                 "BRIDGE NEW A + NEW B:",
//                 bId,
//                 newAId
//             );

//             await promisifiedApi(
//                 conn,
//                 `uuid_bridge ${bId} ${newAId}`
//             );
//         }
//     }
// }
   


                //   console.log(`☎️`, `answered-presence-${extension}@${channel}:` , aId, `answered-presence-${dest}@${channel}:`, bId)

                //   //Added 09-01-26
                //   console.log("bridging... a3")
                //   if(!bId){
                    
                //     bId = parkCall
                //     console.log("noBid", bId);
                //   }
                // conn.api(`uuid_bridge ${aId} ${bId}`);
          ///Added 09-04-26
////Added 09-04-26
        }
        //extension

        ///Added 09-02-26

      }
      
    });

    // ATTENDED TRANSFER USING PARK CALL -- EMAN
    socket.on("parkAnswer", async (data) => {   
      console.log("what is this")
      let arg = data.split("@");
      wallboard.to(arg[1]).emit("holdPark", "0");
    });
    /** To remove the green color on dialpad **/

    socket.on("parkCancel", async (arg) => {
       console.log("what is thisz")
      let delNum = await redisClient.get(
        `${arg.callerExt}@${arg.subdomain}+numSlot`
      );
      let delNum1 = await redisClient.get(
        `${arg.calleeExt}@${arg.subdomain}+numSlot`
      );

      let delNumParse = JSON.parse(delNum);
      let delNumParse1 = JSON.parse(delNum1);

      if (delNum1) {
        
        await redisClient.lSet(
          `${arg.subdomain}:parked`,
          delNumParse1.slot,
          ""
        );
        
        let arrStatus = await redisClient.lRange(
          `${arg.subdomain}:parked`,
          0,
          -1
        );
        wallboard.to(arg.subdomain).emit("parkStatus", arrStatus); deskParkLamps(arg.subdomain); // [desk-park]
      } else if (delNum) {
        await redisClient.lSet(`${arg.subdomain}:parked`, delNumParse.slot, "");
        
        let arrStatus = await redisClient.lRange(
          `${arg.subdomain}:parked`,
          0,
          -1
        );
        wallboard.to(arg.subdomain).emit("parkStatus", arrStatus); deskParkLamps(arg.subdomain); // [desk-park]
      } else {
        
      }
    });

    /** To park the call again if they cancel **/
    socket.on("parkCancel2", async (arg) => {
      
      let ext1 = await redisClient.get(
        `${arg.extension}@${arg.subdomain}+numSlot`
      );
      let ext2 = await redisClient.get(
        `${arg.extension2}@${arg.subdomain}+numSlot`
      );

      console.log("this is park 3")
      conn.api("uuid_park " + arg.uuid);
      conn.api("uuid_broadcast " + arg.uuid + " misc/parkslot.wav");
      conn.api(`uuid_broadcast ${arg.uuid} ${mohStream(arg.subdomain)}`);

      let ext1Parse = JSON.parse(ext1);
      let ext2Parse = JSON.parse(ext2);

      

      let slot = "";

      if (!ext1) {
        
      } else {
        slot = ext1Parse.slot;
      }

      if (!ext2) {
        
      } else {
        slot = ext2Parse.slot;
      }

      await redisClient.lSet(
        `${arg.subdomain}:parked`,
        parseInt(slot),
        arg.uuid
      );

      setTimeout(async () => {
        let arrStatus = await redisClient.lRange(
          `${arg.subdomain}:parked`,
          0,
          -1
        );
        wallboard.to(arg.subdomain).emit("parkStatus", arrStatus); deskParkLamps(arg.subdomain); // [desk-park]
      }, 1000);
    });

    socket.on("parkTransfer", async (arg) => {

      const {extension, channel} = socket.handshake.auth;
      const slot = Number(arg) - 1;
      const parkId = await redisClient.lRange(`${channel}:parked`, slot, slot);
      if(parkId){
        conn.api(`uuid_exists ${parkId[0]}`, async (res) => {
          const { body } = res;
          if(body === 'true'){
              console.log("transfer 11SI")
              console.log(`park pickup`,`uuid_transfer ${parkId[0]} ${extension} XML ${channel}`);
              conn.api(`uuid_transfer ${parkId[0]} ${extension} XML ${channel}`);
              conn.api(`uuid_setvar ${parkId[0]} park_pickup ${extension}@${channel}`);
              await redisClient.lSet(`${channel}:parked`, slot, "");
              const parkNewStatus = await redisClient.lRange(`${channel}:parked`, 0, -1);
              wallboard.to(channel).emit("parkStatus", parkNewStatus); deskParkLamps(channel); // [desk-park]

          }else{

              await redisClient.lSet(`${channel}:parked`, slot, "");
              const parkNewStatus = await redisClient.lRange(`${channel}:parked`, 0, -1);
              wallboard.to(channel).emit("parkStatus", parkNewStatus); deskParkLamps(channel); // [desk-park]

          }
        })
      }


      // let num = parseInt(arg) - 1;
      // let parkSlot = await redisClient.lRange(
      //   `${socket.handshake.auth.channel}:parked`,
      //   0,
      //   -1
      // );

      // if (parkSlot[num]) {
        
      //   let details = await redisClient.get(
      //     `${socket.handshake.auth.channel}:parked:details:${parkSlot[num]}`
      //   );
      //   let detailsParse = JSON.parse(details);
        
      //   conn.api(
      //     "uuid_setvar " +
      //       parkSlot[num] +
      //       " effective_caller_id_name " +
      //       "Parked Call"
      //   );
      //   conn.api(
      //     "uuid_setvar " +
      //       parkSlot[num] +
      //       " effective_caller_id_number " +
      //       detailsParse.number
      //   );
      //   conn.api(
      //     "uuid_transfer " +
      //       parkSlot[num] +
      //       " " +
      //       socket.handshake.auth.extension +
      //       " XML " +
      //       socket.handshake.auth.channel
      //   );

      //   //Send New Status
      //   await redisClient.lSet(
      //     `${socket.handshake.auth.channel}:parked`,
      //     num,
      //     ""
      //   );
      //   let parkNewStatus = await redisClient.lRange(
      //     `${socket.handshake.auth.channel}:parked`,
      //     0,
      //     -1
      //   );
      //   wallboard
      //     .to(socket.handshake.auth.channel)
      //     .emit("parkStatus", parkNewStatus);

      //   setTimeout(async () => {
      //     await redisClient.del(
      //       `${socket.handshake.auth.channel}:parked:show:${parkSlot[num]}`
      //     );
      //   }, 1000);

      // } else {
      //   await redisClient.lSet(
      //     `${socket.handshake.auth.channel}:parked`,
      //     num,
      //     ""
      //   );
      //   let parkNewStatus = await redisClient.lRange(
      //     `${socket.handshake.auth.channel}:parked`,
      //     0,
      //     -1
      //   );
      //   wallboard
      //     .to(socket.handshake.auth.channel)
      //     .emit("parkStatus", parkNewStatus);
        
      // }

    });

    const matchesNumberLength = (number, lengthRule) => {
      const actualLength = String(number ?? "").length;
      const configuredLength = String(lengthRule ?? "").trim();

      // 0, null, or empty means any number length.
      if (
        configuredLength === "" ||
        configuredLength === "0"
      ) {
        return true;
      }

      // Supports ranges such as 8-10 or 8 - 10.
      const rangeMatch = configuredLength.match(
        /^(\d+)\s*-\s*(\d+)$/
      );

      if (rangeMatch) {
        const minimumLength = Number(rangeMatch[1]);
        const maximumLength = Number(rangeMatch[2]);

        return (
          actualLength >= minimumLength &&
          actualLength <= maximumLength
        );
      }

      // Supports an exact length such as 8.
      const exactLength = Number(configuredLength);

      return (
        Number.isInteger(exactLength) &&
        actualLength === exactLength
      );
    };

    // EMAN UPDATED 07-21-26 START
const getGatewayForDestination = async (
  destinationNumber,
  domainUuid
) => {
  const { rows: outboundRules } = await db2.query(
    `SELECT
       outbound_uuid,
       prefix_pattern,
       calls_number_length,
       strip,
       prepend,
       action,
       outbound_caller_id
     FROM q_outbound_rules
     WHERE domain_uuid = $1
     ORDER BY dial_plan_order ASC`,
    [domainUuid]
  );

  const originalNumber = String(destinationNumber ?? "").trim();

  const matchesNumberLength = (
    number,
    configuredLength
  ) => {
    const actualLength = String(number).length;
    const lengthRule = String(
      configuredLength ?? ""
    ).trim();

    // Empty or 0 means any length.
    if (!lengthRule || lengthRule === "0") {
      return true;
    }

    // Range such as 8-10 or 8 - 10.
    const rangeMatch = lengthRule.match(
      /^(\d+)\s*-\s*(\d+)$/
    );

    if (rangeMatch) {
      const minimumLength = Number(rangeMatch[1]);
      const maximumLength = Number(rangeMatch[2]);

      return (
        actualLength >= minimumLength &&
        actualLength <= maximumLength
      );
    }

    // Exact length such as 8.
    const exactLength = Number(lengthRule);

    return (
      Number.isInteger(exactLength) &&
      actualLength === exactLength
    );
  };

  const callerIdVariableMap = {
    "From User": "${sip_from_user}",
    "From Display": "${sip_from_display}",
    Invite: "${destination_number}",
    "To User": "${sip_to_user}"
  };

  for (const rule of outboundRules) {
    const prefixPattern = String(
      rule.prefix_pattern ?? ""
    );

    const hasMatchingPrefix =
      prefixPattern === "" ||
      originalNumber.startsWith(prefixPattern);

    const hasMatchingLength =
      matchesNumberLength(
        originalNumber,
        rule.calls_number_length
      );

    console.log("Checking outbound rule:", {
      outbound_uuid: rule.outbound_uuid,
      destination: originalNumber,
      prefixPattern,
      configuredLength: rule.calls_number_length,
      actualLength: originalNumber.length,
      hasMatchingPrefix,
      hasMatchingLength
    });

    if (!hasMatchingPrefix || !hasMatchingLength) {
      continue;
    }

    let currentNumber = originalNumber;

    const strip = Number(rule.strip) || 0;
    const prepend = String(rule.prepend ?? "");

    if (strip > 0) {
      currentNumber = currentNumber.substring(strip);
    }

    if (prepend) {
      currentNumber = prepend + currentNumber;
    }

    const action = String(rule.action ?? "")
      .trim()
      .toLowerCase();

    if (!action) {
      console.log(
        "Outbound rule has no action:",
        rule.outbound_uuid
      );

      continue;
    }

    const { rows: gatewayRows } = await db2.query(
      `SELECT
         gateway_uuid,
         custom_user,
         custom_display
       FROM v_gateways
       WHERE domain_uuid = $1
         AND LOWER(gateway) = $2
       LIMIT 1`,
      [domainUuid, action]
    );

    const gateway = gatewayRows[0];

    if (!gateway?.gateway_uuid) {
      console.log("No gateway found:", {
        outbound_uuid: rule.outbound_uuid,
        action
      });

      // Check the next matching outbound rule.
      continue;
    }

    const effective_caller_id_number =
      callerIdVariableMap[gateway.custom_user] ?? "";

    const origination_caller_id_number =
      callerIdVariableMap[gateway.custom_user] ?? "";

    const effective_caller_id_name =
      callerIdVariableMap[gateway.custom_display] ?? "";

    const origination_caller_id_name =
      callerIdVariableMap[gateway.custom_display] ?? "";

    const result = {
      modifiedNumber: currentNumber,
      gatewayUuid: gateway.gateway_uuid,
      outbound_uuid: rule.outbound_uuid,
      strip,
      prepend,
      outbound_caller_id:
        rule.outbound_caller_id ?? "",
      effective_caller_id_number,
      effective_caller_id_name,
      origination_caller_id_number,
      origination_caller_id_name
    };

    console.log("Matched outbound route:", result);

    return result;
  }

  return {
    modifiedNumber: originalNumber,
    gatewayUuid: null,
    outbound_uuid: "",
    strip: "",
    prepend: "",
    outbound_caller_id: "",
    effective_caller_id_number: "",
    effective_caller_id_name: "",
    origination_caller_id_number: "",
    origination_caller_id_name: ""
  };
};
    // EMAN UPDATED 07-21-26 END

    /** this is for conference event */
    socket.on("conferrence", async (arg, callback) => {
      
      console.log(`Conference`, arg);

      if (arg > 10000) {

        // EMAN UPDATED 07-21-26 START
        conn.api(`show calls as json`, async (res) => {
          let data = JSON.parse(res.body);
          console.log(
            `⚠️ JOIN CONFERENCE DATA EXTERNAL ⚠️`,
            data,
            `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            arg
          );
          
          const { row_count, rows: callRows } = data;
          if (!row_count) return;

          const getAlias = (accountcode, extension) =>
            db2.query(
              `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
              [accountcode, extension]
            ).then(({ rows }) => rows[0]?.number_alias ?? extension);

          const [callerConf, destinationConf] = await Promise.all([
            getAlias(socket.handshake.auth.channel, socket.handshake.auth.extension),
            getAlias(socket.handshake.auth.channel, arg),
          ]);
          console.log(`⚠️`, callerConf, destinationConf);

          const value = await fsRegistration();
          
          const newMapCalls = (await Promise.all(
            callRows.map(async (element) => {
              if (element.name && element.name.includes('internal')) {
                const name = await presenceId(element.name);
                const origName = value.find(x => regMatch(x, name));
                if (origName) {
                  const { "Auth-User": authUser, "Auth-Realm": authRealm } = origName;
                  return { ...element, presence_id: `${authUser}@${authRealm}` };
                }
              }
              
              if (element.b_name && element.b_name.includes('internal')) {
                const bname = await presenceId(element.b_name);
                const origBName = value.find(x => regMatch(x, bname));
                if (origBName) {
                  const { "Auth-User": authUser, "Auth-Realm": authRealm } = origBName;
                  return { ...element, b_presence_id: `${authUser}@${authRealm}` };
                }
              }
              return element;
            })
          )).filter(Boolean);

          const conferenceAgentInviteA = newMapCalls.find(x => x.presence_id === `${callerConf}@${socket.handshake.auth.channel}`);
          console.log("why is this undefined", conferenceAgentInviteA, "check with", `${callerConf}@${socket.handshake.auth.channel}`);
          
          const conferenceAgentInviteB = newMapCalls.find(x => x.b_presence_id === `${callerConf}@${socket.handshake.auth.channel}`);
          console.log(`⚠️`, newMapCalls, conferenceAgentInviteA, conferenceAgentInviteB);

          // --- LOGIC FOR AGENT INVITE A ---
          if (conferenceAgentInviteA) {
            const { uuid, b_uuid } = conferenceAgentInviteA;

            conn.api(`uuid_dump ${uuid} JSON`, async ({ body }) => {
              if (body && body.includes('-ERR No such channel!')) return;

              const { variable_domain_uuid } = JSON.parse(body);
              console.log(`⚠️ACHECK`, JSON.parse(body));
              const { outbound_uuid, gatewayUuid, modifiedNumber, strip, prepend, effective_caller_id_name, effective_caller_id_number } = await getGatewayForDestination(arg, variable_domain_uuid);

              if (gatewayUuid) {
                console.log(`Found Gateway ID: ${gatewayUuid}, New Number: ${modifiedNumber}`);
              } else {
                console.log("No matching rule or gateway found.");
              }

              if (outbound_uuid === '') return;
              console.log("Outbound rule:", outbound_uuid);
              // conn.api(`uuid_broadcast ${uuid} playback::misc/falsering.wav aleg`);
              conn.api(`uuid_setvar ${uuid} conference_key ${uuid}`);
              conn.api(`uuid_setvar ${uuid} conference_destination ${arg}@${socket.handshake.auth.channel}`);
              redisClient.set(`conferenceEavesdropValue@${arg}`, uuid);
              
              console.log("saved to conference", `conferenceEavesdropValue@${arg} with value: ${uuid}`);
              console.log("saved to retrive", `retrieveConferenceModified@${modifiedNumber}`);
              redisClient.set(`retrieveConferenceModified@${modifiedNumber}`, `${arg}`);
              redisClient.set(`retrieveConferenceUnmodified@${arg}`, `${modifiedNumber}`);
              // conn.bgapi(`originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name=${effective_caller_id_name},origination_caller_id_number=${effective_caller_id_number},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gateway}/${modifiedNumber} &eavesdrop(${uuid})`);
              conn.bgapi(`originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gatewayUuid}/${modifiedNumber} &eavesdrop(${uuid})`);
              console.log("this is originate", `originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name=${effective_caller_id_name},origination_caller_id_number=${effective_caller_id_number},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gatewayUuid}/${modifiedNumber} &eavesdrop(${uuid})`)
              console.log(`⚠️A2⚠️`, outbound_uuid, modifiedNumber, strip, prepend, destinationConf);
            });
          }

          // --- LOGIC FOR AGENT INVITE B ---
          if (conferenceAgentInviteB) {
            const { uuid, b_uuid } = conferenceAgentInviteB;

            conn.api(`uuid_dump ${b_uuid} JSON`, async ({ body }) => {
              if (body && body.includes('-ERR No such channel!')) return;

              const { variable_domain_uuid } = JSON.parse(body);
              console.log(`⚠️B`, JSON.parse(body));

              const { outbound_uuid, gatewayUuid, modifiedNumber, strip, prepend, effective_caller_id_name, effective_caller_id_number } = await getGatewayForDestination(arg, variable_domain_uuid);

              if (gatewayUuid) {
                console.log(`Found Gateway ID: ${gatewayUuid}, New Number: ${modifiedNumber}`);
              } else {
                console.log("No matching rule or gateway found.");
              }
              if (outbound_uuid === '') return;
              console.log("Outbound rule:", outbound_uuid);

              // conn.api(`uuid_broadcast ${b_uuid} playback::misc/falsering.wav aleg`);
              conn.api(`uuid_setvar ${b_uuid} conference_key ${b_uuid}`);
              conn.api(`uuid_setvar ${b_uuid} conference_destination ${arg}@${socket.handshake.auth.channel}`);
              redisClient.set(`conferenceEavesdropValue@${modifiedNumber}`, b_uuid);
              redisClient.set(`retrieveConferenceModified@${modifiedNumber}`, `${arg}`);
              redisClient.set(`retrieveConferenceUnmodified@${arg}`, `${modifiedNumber}`);
              console.log("saved to conference", `conferenceEavesdropValue@${arg} with value: ${b_uuid}`);
              console.log("retrive", `retrieveConferenceModified@${modifiedNumber}: ${arg}`);

              //conn.bgapi(`originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name=${effective_caller_id_name},origination_caller_id_number=${effective_caller_id_number},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gatewayUuid}/${modifiedNumber} &eavesdrop(${b_uuid})`);
              conn.bgapi(`originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gatewayUuid}/${modifiedNumber} &eavesdrop(${b_uuid})`);

              console.log("this is originate", `originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name=${effective_caller_id_name},origination_caller_id_number=${effective_caller_id_number},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gatewayUuid}/${modifiedNumber} &eavesdrop(${b_uuid})`)
              console.log(`⚠️B2⚠️`, outbound_uuid, modifiedNumber, strip, prepend, destinationConf);
            });
          }
        });
        // EMAN UPDATED 07-21-26 END

        callback({
          status: "ok",
        });

        // EMAN UPDATED 07-20-26 END
        /*
        conn.api(`show calls as json`, async (res) => {
          let data = JSON.parse(res.body);
          console.log(
            `⚠️ JOIN CONFERENCE DATA EXTERNAL ⚠️`,
            data,
            `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            arg
          );
          const { row_count, rows:callRows } = data;
          if(!row_count) return;

          
          const getAlias = (accountcode, extension) =>
          db2.query(
              `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
              [accountcode, extension]
          ).then(({ rows }) => rows[0]?.number_alias ?? extension);

          const [callerConf, destinationConf] = await Promise.all([
              getAlias(socket.handshake.auth.channel, socket.handshake.auth.extension),
              getAlias(socket.handshake.auth.channel, arg),
          ]);
          console.log(`⚠️`, callerConf, destinationConf);


          const value = await fsRegistration();
          const newMapCalls =(await Promise.all(

            callRows.map( async(element) => {
              if(element.name.includes('internal')){
                const name = await presenceId(element.name);
                const origName = value.find(x => regMatch(x, name));
                if(origName){
                  const { "Auth-User": authUser , "Auth-Realm": authRealm} = origName;
                  return {...element, presence_id: `${authUser}@${authRealm}`};
                }
              }W
              if(element.b_name.includes('internal')){
                const bname = await presenceId(element.b_name);
                const origBName = value.find(x => regMatch(x, bname));
                if(origBName) {
                  const { "Auth-User": authUser , "Auth-Realm": authRealm} = origBName;
                  return {...element, b_presence_id: `${authUser}@${authRealm}`}
                }
              }
              return element
            })

          )).filter(Boolean);
          
          

          const conferenceAgentInviteA = newMapCalls.find(x => x.presence_id === `${callerConf}@${socket.handshake.auth.channel}`);
          
          console.log("why is this undefined", conferenceAgentInviteA, "check with", `${callerConf}@${socket.handshake.auth.channel}`);
          const conferenceAgentInviteB = newMapCalls.find(x => x.b_presence_id === `${callerConf}@${socket.handshake.auth.channel}`);
          console.log(`⚠️`, newMapCalls, conferenceAgentInviteA, conferenceAgentInviteB);


          

          if(conferenceAgentInviteA){

            const {
              uuid,
              b_uuid
            } = conferenceAgentInviteA

           
            conn.api(`uuid_dump ${uuid} JSON`, async ({body}) => {

            if(body && body.includes('-ERR No such channel!')) return;

              const {variable_domain_uuid} = JSON.parse(body);
              console.log(`⚠️ACHECK`, JSON.parse(body));

                const getOutboundId = async () => {
                    const { rows } = await db2.query(
                    `SELECT outbound_uuid, priority, strip, prepend
                    FROM q_outbound_rules
                    WHERE domain_uuid = $1
                        AND priority > 0
                    ORDER BY dial_plan_order ASC
                    LIMIT 1`,
                    [variable_domain_uuid]
                    );

                    return rows;
                };

                const outboundRows = await getOutboundId();

                if (outboundRows.length === 0) {
                    return;
                }

                const outboundRule = outboundRows[0];

                console.log("Outbound rule:", outboundRule);


              const { outbound_uuid, strip, prepend } = outboundId[0];

              const getDialplan = async () => {
                  const { rows } = await db2.query(
                        `SELECT dialplan_xml FROM v_dialplans WHERE domain_uuid = $1 AND dialplan_uuid = $2`,
                        [variable_domain_uuid, outbound_uuid]
                  );
                  return rows[0]?.dialplan_xml;
              }
              const dialplanXml = await getDialplan();
              if(dialplanXml){
                const gateway =  dialplanXml?.split('gateway/')[1]?.split('/')[0];
                console.log(`⚠️A2`, JSON.parse(body), gateway);
                if(!gateway) return;


                conn.api(`uuid_setvar ${uuid} conference_key ${uuid}`);
                conn.api(`uuid_setvar ${uuid} conference_destination ${arg}@${socket.handshake.auth.channel}`);
                //redisClient.set(`conference-${uuid}-${socket.handshake.auth.channel}`, uuid, { EX: 300, NX: true });
                redisClient.set(`conferenceEavesdropValue@${arg}`, uuid);
                // conn.api(`uuid_media_reneg ${uuid} =PCMA`);
                // conn.api(`uuid_media_reneg ${b_uuid} =PCMA`);
                console.log("saved to conference", `conferenceEavesdropValue@${arg}   with value: ${uuid}`);
                const stripDestination = destinationConf.slice(Number(strip), destinationConf.length);
                const newDestinationFormat = `${prepend}${stripDestination}`
                conn.bgapi(`originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gateway}/${newDestinationFormat} &eavesdrop(${uuid})`);
                console.log(`This is the conferrence: originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=G729,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gateway}/${newDestinationFormat} &eavesdrop(${uuid}) or &eavesdrop(${b_uuid})`);
                console.log(`⚠️A2⚠️`, outbound_uuid, newDestinationFormat, strip, prepend, destinationConf);

              }
            })
            

          }


          if(conferenceAgentInviteB){
            const {
              uuid,
              b_uuid
            } = conferenceAgentInviteB

            conn.api(`uuid_dump ${b_uuid} JSON`, async ({body}) => {
               
            if(body && body.includes('-ERR No such channel!')) return;
                
              const { variable_domain_uuid } = JSON.parse(body);
              console.log(`⚠️B`, JSON.parse(body));

              
              const getOutboundId = async () => {
                  const { rows } = await db2.query(
                        `SELECT outbound_uuid, priority, strip, prepend FROM q_outbound_rules WHERE domain_uuid = $1 AND priority > 0 ORDER BY dial_plan_order ASC LIMIT 1`,
                        [variable_domain_uuid]
                  );
                  return rows;
              }

              const outboundId = await getOutboundId();
              if(!outboundId) return;
              const { outbound_uuid, strip, prepend } = outboundId[0];

              const getDialplan = async () => {
                  const { rows } = await db2.query(
                        `SELECT dialplan_xml FROM v_dialplans WHERE domain_uuid = $1 AND dialplan_uuid = $2`,
                        [variable_domain_uuid, outbound_uuid]
                  );
                  return rows[0]?.dialplan_xml;
              }
              const dialplanXml = await getDialplan();
              if(dialplanXml){
                const gateway =  dialplanXml?.split('gateway/')[1]?.split('/')[0];
                console.log(`⚠️B2`, JSON.parse(body), gateway);
                if(!gateway) return;

                conn.api(`uuid_broadcast ${b_uuid} playback::misc/falsering.wav aleg`);
                conn.api(`uuid_setvar ${b_uuid} conference_key ${b_uuid}`);
                conn.api(`uuid_setvar ${b_uuid} conference_destination ${arg}@${socket.handshake.auth.channel}`);
                //redisClient.set(`conference-${b_uuid}-${socket.handshake.auth.channel}`, uuid, { EX: 300, NX: true });
                redisClient.set(`conferenceEavesdropValue@${arg}`, b_uuid);
                
                console.log("saved to conference", `conferenceEavesdropValue@${arg}   with value: ${b_uuid}`);
                // conn.api(`uuid_media_reneg ${uuid} =PCMA`);
                // conn.api(`uuid_media_reneg ${b_uuid} =PCMA`);

                // setTimeout(()=>{
                //   console.log("waiting... 2 secs");
                // }, 2000);
                console.log("start conference");
                const stripDestination = destinationConf.slice(Number(strip), destinationConf.length);
                const newDestinationFormat = `${prepend}${stripDestination}`
                conn.bgapi(`originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gateway}/${newDestinationFormat} &eavesdrop(${b_uuid})`);
                console.log(`This is the conferrence: originate {accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=G729,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/${gateway}/${newDestinationFormat} &eavesdrop(${b_uuid})`);
                console.log(`⚠️B2⚠️`, outbound_uuid, newDestinationFormat, strip, prepend, destinationConf);

              }

            })

          }

          // if (data.row_count > 0) {
          //   data.rows.forEach(async (key2) => {
          //     const [ext, subdomain] = key2.presence_id.split("@");
          //     const [b_ext, b_subdomain] = key2.b_presence_id.split("@");

          //     const isPureNumberPresence = /^\d+$/.test(ext);
          //     const isPureNumberPresenceB = /^\d+$/.test(b_ext);

          //     if (!isPureNumberPresence) {
          //       const userPresence = await redisClient.get(
          //         `extension-${key2.presence_id}`
          //       );
          //       console.log(
          //         "ConF",
          //         userPresence,
          //         `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
          //       );
          //       if (userPresence) {
          //         const { extension } = JSON.parse(userPresence);
          //         if (
          //           `${extension}@${socket.handshake.auth.channel}` ===
          //           `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
          //         ) {
          //           conferenceExternalInvite(
          //             key2,
          //             socket.handshake.auth.channel,
          //             arg,
          //             socket
          //           );
          //         } else if (
          //           `${key2.b_presence_id}` ===
          //           `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
          //         ) {
          //           conferenceExternalInvite(
          //             key2,
          //             socket.handshake.auth.channel,
          //             arg,
          //             socket
          //           );
          //         }
          //       }
          //     } else if (!isPureNumberPresenceB) {
          //       const userPresenceB = await redisClient.get(
          //         `extension-${key2.b_presence_id}`
          //       );
          //       if (userPresenceB) {
          //         const { extension } = JSON.parse(userPresenceB);
          //         if (
          //           `${extension}@${socket.handshake.auth.channel}` ===
          //           `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
          //         ) {
          //           conferenceExternalInvite(
          //             key2,
          //             socket.handshake.auth.channel,
          //             arg,
          //             socket
          //           );
          //         }
          //       } else if (
          //         `${key2.presence_id}` ===
          //         `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
          //       ) {
          //         conferenceExternalInvite(
          //           key2,
          //           socket.handshake.auth.channel,
          //           arg,
          //           socket
          //         );
          //       }
          //     } else {
          //       if (
          //         key2.presence_id ==
          //           `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}` ||
          //         key2.b_presence_id ==
          //           `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
          //       ) {
          //         conferenceExternalInvite(
          //           key2,
          //           socket.handshake.auth.channel,
          //           arg,
          //           socket
          //         );
          //       }
          //     }
          //   });
          // }

        });
        callback({
          status: "ok",
        });
        */

      } else {

        conn.api(`show calls as json`, async ({body}) => {

          let data = JSON.parse(body);
          console.log(
            `👋 JOIN CONFERENCE DATA INTERNAL 👋`,
            data,
            `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
            arg
          );
          const { row_count, rows:callRows } = data;
          if(!row_count) return;

          const getAlias = (accountcode, extension) =>
          db2.query(
              `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
              [accountcode, extension]
          ).then(({ rows }) => rows[0]?.number_alias ?? extension);

          const [callerConf, destinationConf] = await Promise.all([
              getAlias(socket.handshake.auth.channel, socket.handshake.auth.extension),
              getAlias(socket.handshake.auth.channel, arg),
          ]);

          const value = await fsRegistration();
          const newMapCalls = (await Promise.all(
            callRows.map( async(element) => {

              if(element.name.includes('internal')){
                const name = await presenceId(element.name);
                const origName = value.find(x => regMatch(x, name));
                if(origName){
                  const { "Auth-User": authUser , "Auth-Realm": authRealm} = origName;
                  return {...element, presence_id: `${authUser}@${authRealm}`};
                }
              }

              if(element.b_name.includes('internal')){
                const bname = await presenceId(element.b_name);
                const origBName = value.find(x => regMatch(x, bname));
                if(origBName) {
                  const { "Auth-User": authUser , "Auth-Realm": authRealm} = origBName;
                  return {...element, b_presence_id: `${authUser}@${authRealm}`}
                }
              }
              
              return element

            })
          )).filter(Boolean);
          
          const conferenceAgentInviteA = newMapCalls.find(x => x.presence_id === `${callerConf}@${socket.handshake.auth.channel}`);
          const conferenceAgentInviteB = newMapCalls.find(x => x.b_presence_id === `${callerConf}@${socket.handshake.auth.channel}`);

          console.log(
            `👋 CONFERENCE NEW DATA`, callerConf, destinationConf,
            newMapCalls,
          );

          if(conferenceAgentInviteA){

            console.log(`👋 conferenceAgentInviteA`);

            const {
              uuid,
              b_uuid
            } = conferenceAgentInviteA
            conn.api(`uuid_setvar ${uuid} conference_key ${uuid}`);
            conn.api(`uuid_setvar ${uuid} conference_destination ${arg}@${socket.handshake.auth.channel}`);
            redisClient.set(`conference-${uuid}-${socket.handshake.auth.channel}`, uuid, { EX: 300, NX: true });
            
            // [conference] no forced re-negotiation of the live call: the caller's trunk leg can't do
            // OPUS, so forcing it gave a robotic voice / dropped the call when the agent answered.

            conn.bgapi(`originate {presence_id=${`${destinationConf}@${socket.handshake.auth.channel}`},accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,G722,OPUS,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/${destinationConf}@${socket.handshake.auth.channel} &eavesdrop(${uuid})`);

          }

          if(conferenceAgentInviteB){

            console.log(`👋 conferenceAgentInviteB`);
            const {
              uuid,
              b_uuid
            } = conferenceAgentInviteB

            conn.api(`uuid_broadcast ${b_uuid} playback::misc/falsering.wav aleg`);
            
            // console.log("uuid broadcast to", `${b_uuid}`);

            // conn.api(`uuid_media_reneg ${uuid} =OPUS`);
            // conn.api(`uuid_media_reneg ${b_uuid} =OPUS`);            
            conn.api(`uuid_setvar ${b_uuid} conference_key ${b_uuid}`);
            conn.api(`uuid_setvar ${b_uuid} conference_destination ${arg}@${socket.handshake.auth.channel}`);
            console.log("let's check this", "b_uuid: ", b_uuid, "uuid: ", uuid);
            console.log("this is socketauth", socket.handshake.auth, "or arg", arg);
            redisClient.set(`conferenceEavesdropValue@${b_uuid}`, b_uuid);
            //e.g "conferenceEavesdropValue@80fb28ee-8706-4d5f-91ea-00560b82a50d"
            // redisClient.set(`conferenceEavesdropValue${}`, b_uuid);
            redisClient.set(`conference-${b_uuid}-${socket.handshake.auth.channel}`, b_uuid, { EX: 300, NX: true });
            conn.bgapi(`originate {presence_id=${`${destinationConf}@${socket.handshake.auth.channel}`},accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,G722,OPUS,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/${destinationConf}@${socket.handshake.auth.channel} &eavesdrop(${b_uuid})`);
            // console.log("check this originate", `originate {presence_id=${`${destinationConf}@${socket.handshake.auth.channel}`},accountcode=${socket.handshake.auth.channel},domain_name=${socket.handshake.auth.channel},absolute_codec_string=PCMU,PCMA,G722,OPUS,hangup_after_bridge=false,origination_caller_id_name='Conference',origination_caller_id_number=${socket.handshake.auth.extension},eavesdrop_enable_dtmf=true,eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/${destinationConf}@${socket.handshake.auth.channel} &eavesdrop(${b_uuid}`);
          }
          
          

        });
        callback({
          status: "ok",
        });
        
      }


    });
    /** end */

    /** for call conference mute, unmute, kick */
    // socket.on("muteCallConference", async (arg, callback) => {
    //   conn.api(`show channels as json`, (res) => {
    //     let data = JSON.parse(res.body);

        
    //     if (data.row_count > 0) {
    //       data.rows.forEach((element) => {
    //         conn.api(`uuid_dump ${element.uuid} JSON`, (res) => {
    //           let dumpData = JSON.parse(res.body);

              

    //           if (dumpData) {
    //             if (
    //               dumpData["variable_sip_from_user"] == arg &&
    //               dumpData["variable_dialed_domain"] ==
    //                 socket.handshake.auth.channel &&
    //               dumpData["Presence-Call-Direction"] == "inbound"
    //             ) {
                  
    //               conn.api(
    //                 `uuid_audio ${dumpData["Unique-ID"]} start read mute -4`
    //               );
    //             } else if (
    //               dumpData["variable_dialed_user"] == arg &&
    //               dumpData["variable_dialed_domain"] ==
    //                 socket.handshake.auth.channel &&
    //               dumpData["Presence-Call-Direction"] == "outbound"
    //             ) {
                  
    //               conn.api(
    //                 `uuid_audio ${dumpData["Unique-ID"]} start read mute -4`
    //               );
    //             } else if (
    //               dumpData["variable_dialed_user"] == arg &&
    //               dumpData["variable_dialed_domain"] ==
    //                 socket.handshake.auth.channel &&
    //               dumpData["variable_origination_caller_id_name"] ==
    //                 "Conference"
    //             ) {
                  
    //               conn.api(
    //                 `uuid_audio ${dumpData["Unique-ID"]} start read mute -4`
    //               );
    //             }
    //           }
    //         });

    //         if (arg > 10000) {
    //           if (element.cid_num == arg) {
                
    //             conn.api(`uuid_audio ${element.uuid} start read mute -4`);
    //           }
    //         }
    //       });
    //     }
    //   });
    // });

    // socket.on("unmuteCallConference", async (arg, callback) => {
    //   conn.api(`show channels as json`, (res) => {
    //     let data = JSON.parse(res.body);

        
    //     if (data.row_count > 0) {
    //       data.rows.forEach((element) => {
    //         conn.api(`uuid_dump ${element.uuid} JSON`, (res) => {
    //           let dumpData = JSON.parse(res.body);
    //           if (dumpData) {
    //             if (
    //               dumpData["variable_sip_from_user"] == arg &&
    //               dumpData["variable_dialed_domain"] ==
    //                 socket.handshake.auth.channel &&
    //               dumpData["Presence-Call-Direction"] == "inbound"
    //             ) {
                  
    //               conn.api(
    //                 `uuid_audio ${dumpData["Unique-ID"]} start read mute 0`
    //               );
    //             } else if (
    //               dumpData["variable_dialed_user"] == arg &&
    //               dumpData["variable_dialed_domain"] ==
    //                 socket.handshake.auth.channel &&
    //               dumpData["Presence-Call-Direction"] == "outbound"
    //             ) {
                  
    //               conn.api(
    //                 `uuid_audio ${dumpData["Unique-ID"]} start read mute 0`
    //               );
    //             } else if (
    //               dumpData["variable_dialed_user"] == arg &&
    //               dumpData["variable_dialed_domain"] ==
    //                 socket.handshake.auth.channel &&
    //               dumpData["variable_origination_caller_id_name"] ==
    //                 "Conference"
    //             ) {
                  
    //               conn.api(
    //                 `uuid_audio ${dumpData["Unique-ID"]} start read mute 0`
    //               );
    //             }
    //           }
    //         });

    //         if (arg > 10000) {
    //           if (element.cid_num == arg) {
                
    //             conn.api(`uuid_audio ${element.uuid} start read mute 0`);
    //           }
    //         }
    //       });
    //     }
    //   });
    // });

    socket.on("kickCallConference", async (arg, callback) => {

       const getDomainId = async () => {
        const { rows } = await db2.query(
              `SELECT domain_uuid FROM v_domains WHERE domain_name = $1`,
              [socket.handshake.auth.channel]
        );
        return rows[0]?.domain_uuid;
      }

      const domainId = await getDomainId();
      
      if(arg > 10000){

          const getOutboundId = async () => {
            const { rows } = await db2.query(
                  `SELECT priority, strip, prepend FROM q_outbound_rules WHERE domain_uuid = $1 AND priority > 0 ORDER BY dial_plan_order ASC LIMIT 1`,
                  [domainId]
            );
            return rows;
          }
          const outboundId = await getOutboundId();
          if(!outboundId) return;
          const { strip, prepend } = outboundId[0];
          const stripDestination = arg.slice(Number(strip), arg.length);
          const newDestinationFormat = `${prepend}${stripDestination}`;
          const origNum = await redisClient.get(`retrieveConferenceModified@${newDestinationFormat}`) || arg;
          conn.api(`show calls as json`, ({ body }) => {
              const {row_count, rows} = JSON.parse(body);
              console.log("this is the kickcall1");
              console.log("check the rows", rows);
              if (!row_count) return;
              const findCall = rows.find(({name, b_name, accountcode, b_accountcode}) => (
                name && name.includes(newDestinationFormat) || b_name && b_name.includes(newDestinationFormat)
                ||
                name && name.includes(origNum) || b_name && b_name.includes(origNum))
                && 
                (accountcode === socket.handshake.auth.channel || b_accountcode === socket.handshake.auth.channel)
              );
              
              console.log(`💨kickCallConference external`, arg, origNum , newDestinationFormat, findCall);
              if(findCall.name && (findCall.name.includes(newDestinationFormat) || findCall.name.includes(arg))){
                conn.api(`uuid_kill ${findCall.uuid} NORMAL_CLEARING`)
              }

              if(findCall.name && findCall.b_name.includes(newDestinationFormat) || findCall.name.includes(arg)){
                conn.api(`uuid_kill ${findCall.b_uuid} NORMAL_CLEARING`)
              }
          });

      }else{

          const kickExtension = `${arg}@${socket.handshake.auth.channel}`;
          conn.api(`show calls as json`, (res) => {
              let data = JSON.parse(res.body);
              console.log(`💨kickCallConference`, arg, data)
              if (data.row_count > 0) {
                data.rows.forEach(async (element) => {
                  const extension_presence = await presenceValidator(
                    element.presence_id,
                    kickExtension
                  );
                  const extension_presence2 = await presenceValidator(
                    element.b_presence_id,
                    kickExtension
                  );
                  if (extension_presence || extension_presence2) {
                    console.log("kickCall1", `${element.uuid}`);
                    conn.api(`uuid_kill ${element.uuid}`);
                  } else {
                    if (arg > 10000) {
                      if (element.cid_num == arg) {     
                        console.log("kickCall2", `${element.uuid}`);      
                        conn.api(`uuid_kill ${element.uuid}`);
                      }
                    }
                  }
                });
              }
          });

      }

    });

    socket.on("muteAttendedTransfer", async (arg, callback) => {
      
      let channel = `${arg}@${socket.handshake.auth.channel}`;
      let inbound = await redisClient.get("conference:inbound" + channel);
      
      if (inbound) {
        conn.api("uuid_audio " + inbound + " start read mute -4");
        conn.api("uuid_audio " + inbound + " start write mute -4");
      } else {
        let outbound = await redisClient.get("conference:outbound" + channel);
        
        if (outbound) {
          conn.api("uuid_audio " + outbound + " start read mute -4");
          conn.api("uuid_audio " + outbound + " start write mute -4");
        } else {
          
        }
      }

      callback({
        status: "ok",
      });
    });

    socket.on("unmuteAttendedTransfer", async (arg, callback) => {
      
      let inbound = await redisClient.get(
        "conference:inbound" + arg + "@" + socket.handshake.auth.channel
      );
      
      if (inbound) {
        conn.api("uuid_audio " + inbound + " start read mute 0"); // <!-- CHANGE THIS JASON
        conn.api("uuid_audio " + inbound + " start write mute 0");
      } else {
        let outbound = await redisClient.get(
          "conference:outbound" + arg + "@" + socket.handshake.auth.channel
        );
        
        if (outbound) {
          conn.api("uuid_audio " + outbound + " start read mute 0"); // <!-- CHANGE THIS JASON
          conn.api("uuid_audio " + outbound + " start write mute 0");
        } else {
          
        }
      }

      callback({
        status: "ok",
      });
    });
    /** end */

    /** signal for invited extension in conference */
    socket.on("conferenceExtensionAnswer", async (arg, callback) => {     
      console.log("conferenceExtensionAnswer arg", arg)
      wallboard.to(arg.domain).emit("conferenceAnswer", arg);
      // wallboard.to(arg.domain).emit("conferenceAnswer", arg);
      //wallboard.to(socket.id).emit(channel, arg);
      console.log("this is the socket.id", socket.id);
      console.log(`emit to ${arg.domain}. with arg: ${arg}`)
    });
    /** end */
    
    /** signal for remove extension on conference or leave the conference */
    socket.on("conferenceExtensionRemove", async (arg, callback) => {
      let data = arg.split("@");
      console.log("conferenceExtensionRemove arg", arg)
      console.log("conferenceExtensionRemove", data[1],arg);
      wallboard.to(data[1]).emit("conferenceRemove", arg);
    });
    /** end */

    /** Park and transfer */
    socket.on("joinSwap", async (arg, callback) => {
      let origCaller = await redisClient.get(
        `outboundExtensionCaller${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
      );

      let data = await redisClient.get(
        "uuidAnswer" + origCaller + "@" + socket.handshake.auth.channel
      );

      

      let calllId = JSON.parse(data).uuid;
      console.log("this is park 4");
      conn.api("uuid_park " + calllId);
      console.log("transfer 12SI")
      setTimeout(() => {
        conn.api(
          "uuid_transfer " +
            calllId +
            " " +
            socket.handshake.auth.extension +
            " XML " +
            socket.handshake.auth.channel
        );
      }, 1000);

      callback({
        status: "ok",
      });
    });
    /** end */

    /** Bridge the call  */
    socket.on("joinAdd", async (arg, callback) => {

      let data = await redisClient.get(
        "uuidAnswer" +
          socket.handshake.auth.extension +
          "@" +
          socket.handshake.auth.channel
      );

      let calllId = JSON.parse(data).uuid;
      conn.api(
        "originate {ignore_early_media=true,OPUS,iLBC,G7221@32k,G722,G729,PCMU,PCMA,sip_auto_answer=true,eavesdrop_enable_dtmf=true,origination_caller_id_name=Conference,origination_caller_id_number=" +
          socket.handshake.auth.extension +
          ",eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}user/" +
          socket.handshake.auth.extension +
          "@" +
          socket.handshake.auth.channel +
          " &eavesdrop(" +
          calllId +
          ")"
      );

      callback({
        status: "ok",
      });
    });
    /** end */

    /** $session["extension"] ."@". $session["subdomain"] ."-missed"  */
    socket.on("missedCall", async (arg, callback) => {
      let channelPresence = arg.split("@");

      let missedCallValue = await redisClient.get(`${arg}-missed`);

      if (missedCallValue) {
        let setMissed = await redisClient.set(
          `${arg}-missed`,
          Number(missedCallValue) + 1
        );
        if (setMissed) {
          let payload = {
            extension: `${channelPresence[0]}`,
            subdomain: `${channelPresence[1]}`,
            missedcall: Number(missedCallValue) + 1,
          };
          
          wallboard
            .to(channelPresence[1])
            .emit("missedCall2", JSON.stringify(payload));
        }
      } else {
        let missedCallSet = await redisClient.set(`${arg}-missed`, 1);

        if (missedCallSet) {
          let payload = {
            extension: `${channelPresence[0]}`,
            subdomain: `${channelPresence[1]}`,
            missedcall: 1,
          };
          
          wallboard
            .to(channelPresence[1])
            .emit("missedCall2", JSON.stringify(payload));
        }
      }
    });
    /** end */

    socket.on("voiceMail", async (arg, callback) => {
      let voiceMailValue = await redisClient.get(
        `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}-voicemails`
      );

      if (voiceMailValue) {
        let payload = {
          extension: `${socket.handshake.auth.extension}`,
          subdomain: `${socket.handshake.auth.channel}`,
          missedcall: voiceMailValue,
        };
        wallboard
          .to(socket.handshake.auth.channel)
          .emit("voiceMail2", JSON.stringify(payload));
      } else {
        const error = new Error();
        const stack = error.stack.split("\n");
        const callerLine = stack[2];
        console.error("Redis Get Error", callerLine);
      }
    });

    socket.on("recordingClick2Call", async (arg, callback) => {
      
      const channelPresence = `${arg.extension}@${arg.subdomain}`;
      const aliasNameExtension = async () => {
          const { rows } = await db2.query(
                `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                [arg.subdomain, arg.extension]
          );
          if(rows.length){
            return rows[0]?.number_alias;
          }
          return arg.extension
      }
      const destinationAlias = await aliasNameExtension()

      const channelAnswer = await redisClient.get(`channel-${`${destinationAlias}@${arg.subdomain}`}`);
      if (channelAnswer) {
        let newNumber = Date.now().toString();
        let timeStamp = parseInt(newNumber);
        let options = { month: "short", day: "2-digit", year: "numeric" };
        let dateEvent = new Date(timeStamp)
          .toLocaleDateString("en-US", options)
          .replace(/ /g, "/");
        let cleanedDate = dateEvent.replace(/,/g, "");
        let arrDate = cleanedDate.split("/");

        console.log(
          `RECORDING_START`,
          "uuid_record " +
            channelAnswer +
            " start /var/lib/freeswitch/recordings/" +
            arg.subdomain +
            "/archive/" +
            arrDate[2] +
            "/" +
            arrDate[0] +
            "/" +
            arrDate[1] +
            "/" +
            channelAnswer +
            ".wav"
        );

        conn.api(
          "uuid_record " +
            channelAnswer +
            " start /var/lib/freeswitch/recordings/" +
            arg.subdomain +
            "/archive/" +
            arrDate[2] +
            "/" +
            arrDate[0] +
            "/" +
            arrDate[1] +
            "/" +
            channelAnswer +
            ".wav"
        );
      }
    });

    //Communication
    //ryan

    socket.on("connect_error", (err) => {
      
    });

    // socket.on("communicate", async (arg, callback) => {
    //      socketChat.emit('communicateChat', {
    //           arg: arg,
    //           subdomain: socket.handshake.auth.channel,
    //           extension: socket.handshake.auth.extension
    //      });
    // });

    
    socket.on("communicate", async (arg, callback) => {
      try {
        if ((arg?.type == 11 || arg?.type == 12) && !socket.data.presenceAuthorised) {
          console.log("[chat] refused type", arg.type, "from a socket without a valid token for", socket.handshake.auth.channel);
          return;
        }


        const ip =
        socket.handshake.headers["x-forwarded-for"]?.split(",")[0] ||
        socket.handshake.address;
    
        console.log("Client IP:", ip);

        // forward to another socket namespace / server

        socketChat.emit(
          "communicateChat",
          {
            arg: arg,
            subdomain: socket.handshake.auth.channel,
            extension: socket.handshake.auth.extension,
            mysocket: socket.id,
            ip: ip
          },
          socket.id
        );
    
        // ---- TYPE 3 LOGIC ----
        if (
          arg.type == 3 &&
          (arg.user_type === "Agent" || arg.role === "Agent")
        ) {
          socket.join("assign_messages");
          console.log("implementation of socket.join happened");
          // io.in("assign_messages").allSockets().then((sockets) => {
          //      console.log("Sockets in assign_messages:", Array.from(sockets));
          //    });
  
          // const sockets = await io.in("assign_messages").allSockets();
          // console.log("Sockets in assign_messages:", Array.from(sockets));
  
          const sockets = await socket.nsp.in("assign_messages").allSockets();
          console.log("Sockets in assign_messages:", Array.from(sockets));
        }
    
        // ---- TYPE 7 LOGIC ----
        if (arg.type == 7) {
          console.log("---------> Type 7---------");
          const agentKey = arg.agent_key;
          let agentUser = null;
          agentUser = await redisClient.hGetAll(agentKey);
          if (!agentUser) {
            console.log("No client found for userKey", agentUser);
            return;
          }
  
          console.log("user", agentUser);
          console.log("this is user", agentUser);
          if (arg.roomtype == "all") {
            console.log("room status", `${socket.handshake.auth.channel}-all_messages`);
            socket.join(`${socket.handshake.auth.channel}-all_messages`);
            socket.leave(`${socket.handshake.auth.channel}-assign_messages`);
            console.log("changed to all messages");
            agentUser.roomID = "all_messages";
  
            switch (arg.roomstatus) {
              case "0":
                agentUser.roomstatus = "all";
                console.log(
                  "this room status is",
                  arg.roomstatus,
                  "converting into",
                  agentUser.roomstatus
                );
                break;
              case "1":
                agentUser.roomstatus = "new";
                console.log(
                  "this room status is",
                  agentUser.roomstatus,
                  "converting into",
                  agentUser.roomstatus
                );
                break;
              case "2":
                agentUser.roomstatus = "active";
                console.log(
                  "this room status is",
                  arg.roomstatus,
                  "converting into",
                  agentUser.roomstatus
                );
                break;
              case "3":
                agentUser.roomstatus = "closed";
                console.log(
                  "this room status is",
                  arg.roomstatus,
                  "converting into",
                  agentUser.roomstatus
                );
                break;
              // // You can add more cases here if needed
              default:
                //     // Optional: handle other cases or do nothing
                agentUser.roomstatus = "0";
                console.log(
                  "this room status is now but using default",
                  arg.roomstatus,
                  "converting into",
                  agentUser.roomstatus
                );
                break;
            }
            // user.roomstatus = "";
            await redisClient.hSet(agentKey, {
              roomID: agentUser.roomID,
              roomstatus: agentUser.roomstatus,
            });
          }
          if (arg.roomtype == "owner") {
            socket.leave(`${socket.handshake.auth.channel}-all_messages`);
            socket.join("assign_messages");
            console.log("changed to assigned messages");
            agentUser.roomID = "assign_messages";
            agentUser.roomstatus = "";
            await redisClient.hSet(agentKey, {
              roomID: agentUser.roomID,
              roomstatus: agentUser.roomstatus,
            });
            console.log("after changing", agentUser.roomID, agentUser.roomstatus);
          }
          console.log("UPDATED SOCKETS AND ROOM TYPE");
          const assignRoomType = await socket.nsp.in(`${socket.handshake.auth.channel}-assign_messages`).allSockets();
          console.log("Sockets in assign_messages:",Array.from(assignRoomType));
          const allRoomType = await socket.nsp.in(`assign_messages`).allSockets();
          console.log("Sockets in all_messages:", Array.from(allRoomType));
        }
    
        // ---- FINAL CALLBACK (SAFE) ----
        if (typeof callback === "function") {
          callback({
            status: "success",
            channel: socket.handshake.auth.channel,
          });
        }
    
      } catch (err) {
        console.error("communicate error:", err);
    
        if (typeof callback === "function") {
          callback({
            status: "error",
            message: err.message,
          });
        }
      }
    });


    async function verifyToken2(config, challengeFqdn, challengeAccessToken, challengeUrl, websiteType) {
      const verifyKey = `${config}:fqdn:${challengeFqdn}`;
      const list = await redisClient.lRange(verifyKey, 0, -1);
      console.log("this is chall", challengeUrl);
      for (const raw of list) {
        let data;

        try {
          data = JSON.parse(raw.replace(/[“”]/g, '"'));
        } catch {
          continue;
        }
        console.log("data.fqdn", data.fqdn, "chall fqdn", challengeFqdn, "data.accessToken", data.accessToken, challengeAccessToken, "data.url", data.url, "challUrl", challengeUrl, "data.fqdn", data.fqdn);
        data.url = (data.url || "").replace(/\/+$/, "");
        if(websiteType === "plugin"){
          if (
            data.fqdn === challengeFqdn &&
            data.accessToken === challengeAccessToken &&
            data.url === challengeUrl
          ) {
            console.log("confirmed plugin verified")
            return data;
          }
          else{
            console.log("nothing plugin")
          }
        }
        else if(websiteType === "iframe"){
          if (
            data.fqdn === challengeFqdn &&
            data.accessToken === challengeAccessToken &&
            data.url === challengeUrl 
          ) {
            return data;
          }
          else{
            console.log("nothing iframe")
          }
        }
        else{
          console.log("nothing")
        }
 
      }
    
      return null;
    }

    async function banChecker(ip, channel, socketId, clientKey) { 
      if (!ip) return 0;

      const baseKey = `${channel}:webchat:ratelimiting`;
      const bannedKey = `${baseKey}:banned:${ip}`;
      const shadowSetKey = `${baseKey}:banned-set:${ip}`;

      const isBanned = await redisClient.exists(bannedKey);

      if (isBanned) {
        if (socketId) {
          // 1. Add socket to the notification list
          await redisClient.sAdd(shadowSetKey, socketId);
          
          // 2. CRITICAL: Map this specific socket to the user's unique clientKey
          // This is what the Cron uses: let cKey = await redisClient.hGet(sId, "clientKey");
          if (clientKey) {
            await redisClient.hSet(socketId, { clientKey: clientKey });
            await redisClient.expire(socketId, 86400); // Safety TTL
          }
          
          await redisClient.expire(shadowSetKey, 86400); 
        }
        return 1;
      }
      return 0;
    }

    cron.schedule('* * * * *', async () => {
      const instance = process.env.NODE_APP_INSTANCE;
      if (instance !== undefined && instance !== '0') return;
    
      const activeBansGlobalSet = `global:active_bans_list`;
    
      try {
        const keys = await redisClient.sMembers(activeBansGlobalSet);
        if (!keys || keys.length === 0) return;
    
        const now = Date.now();
    
        for (const key of keys) {
          // 🛡️ Distributed Lock: Prevents other PM2 instances from processing the same key
          const lockKey = `lock:unban:${key}`;
          const acquiredLock = await redisClient.set(lockKey, 'locked', {
              NX: true,
              EX: 50 // Lock expires after 50s (before the next cron run)
          });
    
          if (!acquiredLock) continue; 
    
          const banData = await redisClient.hGetAll(key);
          if (!banData || Object.keys(banData).length === 0) {
            await redisClient.sRem(activeBansGlobalSet, key);
            continue;
          }
    
          if (banData.expiresAt && now >= parseInt(banData.expiresAt)) {
            const { ip, subdomain } = banData;
            const shadowSetKey = `${subdomain}:webchat:ratelimiting:banned-set:${ip}`;
            
            const rawSocketIds = await redisClient.sMembers(shadowSetKey);
            const socketIds = [...new Set(rawSocketIds)]; // Deduplicate
    
            console.log(`[LOG] Found Sockets for IP ${ip}:`, socketIds);
    
            let primaryClientInfo = null;
    
            if (socketIds.length > 0) {
              for (const sId of socketIds) {
                let cKey = await redisClient.hGet(sId, "clientKey"); 
                console.log(`[MAP] ${sId} => ${cKey || '""'}`);
    
                if (cKey) {
                  const clientProfile = await redisClient.hGetAll(cKey);
                  if (clientProfile && Object.keys(clientProfile).length > 0) {
                    if (!primaryClientInfo) primaryClientInfo = clientProfile;
                    
                    // Remove IP from client record
                    await redisClient.hDel(cKey, "ip");
                    console.log(`[CLEANUP] IP field deleted for Client: ${cKey}`);
                  }
                }
              }
            }
    
            const displayName = (primaryClientInfo && primaryClientInfo.username) 
              ? primaryClientInfo.username.split('-')[0] 
              : "User";
    
            // 🛡️ Staying consistent with banned-Status
            const unbanPayload = {
              ip: ip,
              banned: false,
              type: "queue4",
              status: "active",
              msg: `Your ban has been lifted, ${displayName}.`,
              username: "banned-Status" 
            };
    
            const unbanPayloadClient = {
              type: "13",
              status: "active",
              msg: "Your ban has been lifted. You may chat again.",
              username: `${displayName}-Status`,
            };
    
            // Emit to unique individual sockets
            if (socketIds.length > 0) {
              socketIds.forEach(sId => {
                wallboard.to(sId).emit("unban", unbanPayloadClient);
              });
              console.log(`[UNBAN] Signal sent to ${socketIds.length} sockets.`);
            }
    
            wallboard.to(`${subdomain}-all_messages`).emit("queue4", unbanPayload);
    
            // Final Cleanup
            await redisClient.del(key); 
            await redisClient.del(shadowSetKey); 
            await redisClient.sRem(activeBansGlobalSet, key); 
    
            console.log(`[CRON] Speed-optimized unban for IP: ${ip} complete.`);
          }
        }
      } catch (err) {
        console.error("Optimized Cron Error:", err);
      }
    });


  




let isCronRunning = false;

// cron.schedule('* * * * * *', async () => {
//   try {
//     console.log(`info: ${subdomain}`);
//     console.log(`info: ${channel}`);
//     console.log("per 1 sec?");
//   } catch (error) {
//     console.error("CRON ERROR:", error.message);
//   }
// });




function updateChat(key, payload, subdomain) {
  return new Promise((resolve, reject) => {
    const url = `https://${subdomain}:8443/chatadmin/getassign`;

    const command = `curl -sS -f -X POST "${url}" \
      -H "Content-Type: application/json" \
      -d '${JSON.stringify(payload)}'`;

    exec(command, (error, stdout, stderr) => {
      if (error) {
        return reject(stderr || error.message);
      }

      try {
        resolve(JSON.parse(stdout));
      } catch {
        resolve(stdout);
      }
    });
  });
}


 
socket.on("initializeAccessToken", async (arg, callback) => {
      try {
        const token = '$2y$10$Q0c5WUlCQnFZYU0zRTlEMeRnveV00SzK5qaPEf5XsE.MVKncD4m9C';
        
        // 1. Strict Security Check
        if (socket.handshake.auth.token !== token) {
          console.log("Unauthorized socket access attempt:", socket.id);
          socket.disconnect(true);
          return callback({ status: "failed", message: "Wrong auth token used" });
        }
    
        if (arg.type !== "1") {
          return callback({ status: "failed", message: "Invalid type" });
        }
    
        // 2. Verified Token Check (Iframe vs Plugin)
        let verified;
            
        if(arg.websiteType == "iframe"){
          console.log("aypreym")
           verified = await verifyToken2(
            "webchatconfig",
            arg.channel,
            arg.accessToken,
            arg.currentUrl,
            arg.websiteType
          );
        }
        else if(arg.websiteType == "plugin"){
          console.log("aypreym")
           verified = await verifyToken2(
            "webchatconfig",
            arg.channel,
            arg.accessToken,
            arg.currentUrl,
            arg.websiteType
          );
        }
    
        if (!verified) {
          return callback({
            status: "failed",
            error_type: "VERIFICATION_TOKEN",
            message: "Token verification failed"
          });
        }
    
        // 3. IP and LimitKey Logic
        const ip = socket.handshake.headers["x-forwarded-for"]?.split(",")[0] || socket.handshake.address;
        
        // We prioritize the IP for rate limiting consistency
        let currentLimitKey = (arg.currentLimitKey == ip) ? arg.currentLimitKey : ip;
    
            // 3. Build Client Key only if data exists
        let clientKey = null;
    
        // Only build the key if we have at least one identifier (id, username, or email)
        if (arg.id && arg.username && arg.email) {
            const id = arg.id || "";
            const user = arg.username || "";
            const email = arg.email || "";
            clientKey = `${id}-website-${user}-${email}-${arg.channel}`;
        }
        console.log("before updatiing", clientKey);

        if (clientKey) {

          console.log("abangan ang clientkey", clientKey);
            const clientUser = await redisClient.hGetAll(clientKey);
            console.log("old socket", clientUser.socketId);
            console.log("new socket check", socket.id)
            await redisClient.hSet(clientKey, {
                socketId: socket.id
            });
            console.log(`[REDIS] Updated socketId for valid client: ${clientKey}`);
            
        } else {
            console.log("[AUTH] Guest user detected (no optional IDs provided), skipping hSet.");
        }
            
    
        // 4. 🛡️ The Speed-Optimized Ban Check
        if (currentLimitKey) {
          // Pass socket.id so the Shadow Set knows exactly who to unban later
          const banResult = await banChecker(currentLimitKey, arg.channel, socket.id);
          
    
          if (banResult === 1) {
            console.log(`[AUTH] Blocked banned IP: ${currentLimitKey}`);
            
            
    
            return callback({
              status: "failed",
              username: "error-Status",
              error_type: "RATE_LIMIT_LOGIN",
              message: "You are banned due to spam. Please wait.",
              errorKey: currentLimitKey,
              ...(arg.id && { id: arg.id})
            });
          }
        }
    
    
    
        // 5. Success - Grant Access
        console.log(`[AUTH] Access granted to ${currentLimitKey} on socket ${socket.id}`);

        console.log("this is the external whatsapp", )

        const whatsappExternalData = `${arg.channel}-wordpress-whatsapp`;
        const whatsappExternalPhone = await redisClient.hGet(`${whatsappExternalData}`, `phone`);
        
        const wordPressExternalClientData = `${arg.channel}-wordpress-emailsettings`;
        const chatSettingsClientData = `${arg.channel}-chatsettings`;
        const chatsettingsdata = await redisClient.hGetAll(`${chatSettingsClientData}`);
        const wordPressExternalClientEmail = await redisClient.hGet(`${wordPressExternalClientData}`, `email`);
        const AISelected = await redisClient.hGet(`${arg.channel}-AISettings`, `selectedAI`);
        
        const aiExternalData = `${arg.channel}-wordpress-ai`;
        const aiExternalPhone = await redisClient.hGet(`${aiExternalData}`, `name`);

        // Per-client custom chat settings, keyed by channel + access token.
        // accessToken is always present here (we've passed verification), so
        // this works for guests and logged-in users alike.
        const chatSettingsUniqueueData = await redisClient.hGetAll(`${arg.channel}-${arg.accessToken}`);

        console.log(`arg.channel is ${arg.channel} and arg.accessToken is ${arg.accessToken}`)
        let response = null;

        response = {
          status: "success",
          accessToken: verified.accessToken,
          channel: verified.fqdn,
          unban: currentLimitKey,
        }


        if(whatsappExternalPhone){
          response.whatsappExternalPhone = whatsappExternalPhone;
        }
        if(wordPressExternalClientEmail){
          response.wordpressEmailSettings = wordPressExternalClientEmail;
        }
        if(AISelected){
          response.selectedAI = AISelected;
        }
        if(aiExternalPhone){
          response.aiExternal = aiExternalPhone;
        }
        if(chatsettingsdata){
          response.chatsettings = chatsettingsdata
        }
        // hGetAll returns {} (truthy) for a missing key, so guard on key count —
        // otherwise response.customized would always be set to an empty object.
        if(chatSettingsUniqueueData && Object.keys(chatSettingsUniqueueData).length > 0){
          response.customized = chatSettingsUniqueueData
        }
        console.log("why ", wordPressExternalClientEmail)

        callback(response)
    
      } catch (err) {
        console.error("initializeAccessToken critical error:", err);
        callback({
          status: "error",
          message: "Internal server error during initialization"
        });
      }
    });


   

    socket.on("whatsapp", async (arg, callback) => {
      socketChat.emit(
        "whatsappChat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is whatsappChat subdomain", socket.handshake.auth.channel)
    })

    socket.on("communicate3", async (arg, callback) => {
      socketChat.emit(
        "communicate3Chat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );
      console.log("this is communicate3 subdomain", socket.handshake.auth.channel)
    })

    socket.on("facebook", async (arg, callback) => {
      socketChat.emit(
        "facebookChat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is facebookChat subdomain", socket.handshake.auth.channel)
    })
  
    socket.on("communicate4", async (arg, callback) => {
      socketChat.emit(
        "communicate4Chat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is communicate3 subdomain", socket.handshake.auth.channel)
    })



    socket.on("telnyx", async (arg, callback) => {
      socketChat.emit(
        "telnyxChat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is telnyx subdomain", arg)
    });
    
    socket.on("communicate5", async (arg, callback) => {
      socketChat.emit(
        "communicate5Chat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is communicate3 subdomain", socket.handshake.auth.channel)
    })

    socket.on("twilio", async (arg, callback) => {
      socketChat.emit(
        "twilioChat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is twilio subdomain", arg)
    });

    socket.on("communicate6", async (arg, callback) => {
      socketChat.emit(
        "communicate6Chat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is communicate3 subdomain", socket.handshake.auth.channel)
    })

    socket.on("telegram", async (arg, callback) => {
      console.log("this is reached on telegram");
      
      socketChat.emit(
        "telegramChat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is telegram subdomain", arg)
    });

    socket.on("communicate7", async (arg, callback) => {
        socketChat.emit(
          "communicate7Chat",
          {
            arg: arg,
            subdomain: socket.handshake.auth.channel,
            extension: socket.handshake.auth.extension,
            mysocket: socket.id,
          },
          socket.id
        );
    })
  
    socket.on("line", async (arg, callback) => {
      socketChat.emit(
        "lineChat",
        {
          arg: arg,
          subdomain: socket.handshake.auth.channel,
          extension: socket.handshake.auth.extension,
          mysocket: socket.id,
        },
        socket.id
      );

      console.log("this is line subdomain", arg)
    });

    socket.on("communicate8", async (arg, callback) => {
        socketChat.emit(
          "communicate8Chat",
          {
            arg: arg,
            subdomain: socket.handshake.auth.channel,
            extension: socket.handshake.auth.extension,
            mysocket: socket.id,
          },
          socket.id
        );
    })

    // socket.on("communicate5", async (arg, callback) => {
    //   socketChat.emit(
    //     "communicate4Chat",
    //     {
    //       arg: arg,
    //       subdomain: socket.handshake.auth.channel,
    //       extension: socket.handshake.auth.extension,
    //       mysocket: socket.id,
    //     },
    //     socket.id
    //   );

    //   console.log("this is communicate5 subdomain", socket.handshake.auth.channel)
    // })
  
    socket.on("chatParameters", async (channel, arg, receiver) => {
      
      // if(receiver){
      //      
      //      
      // }
      

      if (arg && arg.type === 1) {
        console.log(
          "specifically for type 1 " +
            receiver +
            " and the channel is " +
            channel +
            " and the data is: " +
            JSON.stringify(arg)
        );
        
        // console.log(
        //   "the first socket from chat.js is " +
        //     arg.socketID +
        //     "and the socket from the signal.js is " +
        //     socket.id
        // );
        wallboard.to(socket.id).emit(channel, arg);
      }

      wallboard.to(receiver).emit(channel, arg);
      // console.log("sent data to", receiver, channel, arg)
    
    });




    socket.on("communicate2", async (arg, callback) => {
      var socketId = await redisClient.get(
        "socketId:" + socket.handshake.auth.channel + ":" + arg["username"]
      );
      if (socketId) {
        

        let array = [socket.handshake.auth.extension, arg["username"]].sort();
        await redisClient.lPush(
          socket.handshake.auth.channel +
            "-internal-" +
            array[0] +
            "-" +
            array[1],
          [
            `${Date.now()}`,
            `${arg.message}`,
            `${socket.handshake.auth.extension}`,
          ]
        );
        console.log(
          "communicate2 saved in",
          socket.handshake.auth.extension +
            "-internal" +
            array[0] +
            "-" +
            array[1]
        );

        wallboard
          .to(socketId)
          .emit("room", {
            message: arg["message"],
            username: socket.handshake.auth.extension,
            time: Date.now(),
          });
      }
      
      
      // wallboard.to(socket.handshake.auth.channel).emit('version', arg['message']);
    });

    const { exec } = require("child_process");

    // socket.on("whatsapp", async (arg, callback) => {

    //      var param = JSON.parse(arg);
    //      
    //      let id = param.entry[0].changes[0].value.contacts[0].wa_id
    //      let userName = param.entry[0].changes[0].value.contacts[0].profile.name;

    //      const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    //      let uniqueId = '';
    //           let length = 16;
    //           for (let i = 0; i < length; i++) {
    //                uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    //           }
    //      uniqueId = `${Date.now()}${uniqueId}`;

    //      

    //      let userExists = false;
    //      let unread_client = "";
    //      let unread_agent = "";
    //      let roomID = "";
    //      let Agent_Name = "";

    //      //Start
    //      let senderUsername = arg.sender_username;
    //      //user exist, needs to update the socket

    //      
    //      // 
    //      if (param.hasOwnProperty("entry") && param.entry[0].hasOwnProperty("changes") && param.entry[0].changes[0].field == "messages") {

    //           var metadata = param.entry[0].changes[0].value.metadata;
    //           var contacts = param.entry[0].changes[0].value.contacts;
    //           var messages = param.entry[0].changes[0].value.messages;
    //           let holderUser = [];

    //           const existingUsers = await redisClient.lRange("user_data", 0, -1);

    //           for (let i = 0; i < existingUsers.length; i++) {

    //                const user = JSON.parse(existingUsers[i]);

    //                // 
    //                // 
    //                if (user.email === id && user.username === userName && user.role === "customer") {
    //                     // 
    //                     unread_agent = user.agent_unread || 0;
    //                      unread_client  = user.client_unread || 0;
    //                      if(user.allocated_room == "CLOSED" ){
    //                           user.allocated_room = "NEW";
    //                      }
    //                      if(messages[0].type == 'text'){
    //                          user.last_message = messages[0].text.body
    //                      }
    //                      else if(messages[0].type == 'document'){
    //                          user.last_message = "document attachments"
    //                     } else if(messages[0].type == 'image'){
    //                          user.last_message = "image attachments"
    //                     } else if(messages[0].type == 'video'){
    //                          user.last_message = "video attachments"
    //                     } else if(messages[0].type == 'audio'){
    //                          user.last_message = "audio attachments"
    //                     }

    //                     if(user.role === "Agent"){
    //                          Agent_Name = user.Agent_Name;
    //                          // 
    //                     }

    //                    user.socketID = socket.id;
    //                    user.Dept = "customer";
    //                    roomID = user.roomID;
    //                    holderUser = user;

    //                     // CASE 1: Sender is already in someone's assigned list
    //                    const assignedEmails = Array.isArray(user.assigned) ? user.assigned : [];
    //                    if (assignedEmails.includes(arg.roomID)) {
    //                     // Sender is assigned to themselves
    //                     if ((senderUsername === user.id) && (user.role === "Agent")) {
    //                     //     
    //                         AgentID = user.id;
    //                         validate = true;
    //                         isAlreadyAssigned = true;
    //                         Agent_Name = user.Agent_Name;
    //                     } else {
    //                         // Sender is assigned to someone else
    //                     //     
    //                         isAlreadyAssigned = true;
    //                     }

    //                 }
    //                //     
    //                //      
    //                //      
    //                    await redisClient.lSet("user_data", i, JSON.stringify(user));
    //                    userExists = true;

    //                    break;
    //                }
    //                else if(user.roomID === user.id && user.username === holderUser.username && user.role === "Agent"){
    //                     // 
    //                      // Agent_Name =
    //                }
    //            }

    //            //dosent exist
    //            if (!userExists) {
    //                 let newUser = {
    //                      id: uniqueId,
    //                      username: userName,
    //                      created: Date.now(),
    //                      email: id,
    //                      role: "customer",
    //                      allocated_room: "NEW",
    //                      socketID: socket.id,
    //                      Dept: "customer",
    //                      client_unread: 0,
    //                      agent_unread: 0,
    //                      last_message: messages[0].text.body

    //                  };
    //                holderUser = {
    //                     id: uniqueId,
    //                     username: userName,
    //                     created: Date.now(),
    //                     email: id,
    //                     role: "customer",
    //                     allocated_room: "NEW",
    //                     socketID: socket.id,
    //                     Dept: "customer",
    //                     client_unread: 0,
    //                     agent_unread: 0,
    //                     last_message: messages[0].text.body,
    //                     // Agent_Name: user.Agent_Name
    //                   };

    //                 unread_agent = 0;
    //                 unread_client  =  0;
    //                 newUser.roomID = uniqueId;
    //                 roomID = uniqueId;

    //                await redisClient.lPush("user_data", JSON.stringify(newUser));
    //                // 
    //            }

    //           // 

    //           // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: messages[0].text.body });

    //           // await redisClient.lPush("whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id, [messages[0].text.body, messages[0].timestamp, messages[0].from, contacts[0].profile.name, messages[0].id]);
    //           let splitPath;
    //           if(param.filePath){
    //                splitPath = param.filePath.split('/public/')[1]
    //           }

    //           if(messages[0].type == 'text'){
    //                // 
    //                // 
    //                let success = await redisClient.lPush(
    //                     "whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id + "-" + roomID,
    //                     [
    //                          messages[0].text.body, messages[0].timestamp,
    //                          // messages[0].from,
    //                          contacts[0].profile.name,
    //                          contacts[0].profile.name,
    //                          messages[0].id
    //                     ]
    //                );
    //                //Dito mag focus ... Client side to
    //                if(success){
    //                     // 
    //                     // 

    //                     // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: messages[0].text.body });
    //                     wallboard.to("demo.us1.ringq.ai").emit("queue3", {
    //                          id: roomID,
    //                          name: contacts[0].profile.name,
    //                          // from: messages[0].from + "-user",
    //                          from: contacts[0].profile.name,
    //                          unix: messages[0].timestamp,
    //                          message: messages[0].text.body,
    //                          agent_unread: holderUser.agent_unread,
    //                          client_unread: holderUser.client_unread,
    //                          assigned_user: holderUser.assigned_user,
    //                          email: holderUser.email,
    //                          allocated_room: holderUser.allocated_room,
    //                          last_message: holderUser.last_message,
    //                          roomID: holderUser.roomID,
    //                          Agent_Name: holderUser.Agent_Name,
    //                     });
    //                     whatsappConversationList(userName, id, roomID);
    //                }

    //           }else if(messages[0].type == 'document'){

    //                messages[0].document.newFilePath = param.filePath;

    //                let success = await redisClient.lPush("whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id + "-" + roomID, [
    //                     splitPath ,
    //                     messages[0].timestamp,
    //                     // messages[0].from,
    //                     contacts[0].profile.name,
    //                     contacts[0].profile.name,
    //                     messages[0].id]);

    //                if(success){
    //                     // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: splitPath });

    //                     // whatsappConversationList(userName, id, roomID);
    //                     wallboard.to("demo.us1.ringq.ai").emit("queue3", {
    //                          id: roomID,
    //                          name: contacts[0].profile.name,
    //                          from: messages[0].from,
    //                          unix: messages[0].timestamp,
    //                          message: "document attachments",
    //                          agent_unread:holderUser.agent_unread,
    //                          client_unread:holderUser.client_unread,
    //                          assigned_user:holderUser.assigned_user,
    //                          email: holderUser.email,
    //                          allocated_room: holderUser.allocated_room,
    //                          last_message: holderUser.last_message,
    //                          roomID: holderUser.roomID,

    //                     });
    //                     whatsappConversationList(userName, id, roomID);
    //                }

    //           }else if(messages[0].type == 'image'){

    //                messages[0].image.newFilePath = param.filePath;

    //                let success = await redisClient.lPush("whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id + "-" + roomID, [
    //                     splitPath,
    //                     messages[0].timestamp,
    //                     // messages[0].from,
    //                     contacts[0].profile.name,
    //                     contacts[0].profile.name,
    //                     messages[0].id]);

    //                if(success){
    //                     // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: splitPath });
    //                     wallboard.to("demo.us1.ringq.ai").emit("queue3", {
    //                          id: roomID,
    //                          name: contacts[0].profile.name,
    //                          from: messages[0].from,
    //                          unix: messages[0].timestamp,
    //                          message: "image attachments",
    //                          agent_unread:holderUser.agent_unread,
    //                          client_unread:holderUser.client_unread,
    //                          assigned_user:holderUser.assigned_user,
    //                          email: holderUser.email,
    //                          allocated_room: holderUser.allocated_room,
    //                          last_message: holderUser.last_message,
    //                          roomID: holderUser.roomID
    //                     });
    //                     whatsappConversationList(userName, id, roomID);

    //                }

    //           }else if(messages[0].type == 'video'){

    //                messages[0].video.newFilePath = param.filePath;
    //                let thumbnail;
    //                const parts = splitPath.split('wp_vid/')[1].split('.');
    //                const lastPart = parts[parts.length - 1];
    //                const thumbnailName = splitPath.split('wp_vid/')[1].split(lastPart)[0];

    //                ffmpeg('/var/www/ringq/public/'+splitPath)
    //                .on('filenames', (filenames) => {
    //                     // 
    //                     thumbnail = 'wp_vid_thumbnail/'+filenames.join(', ')
    //                })
    //                .on('end',async () => {

    //                     // 
    //                     let success = await redisClient.lPush("whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id + "-" + roomID, [
    //                          JSON.stringify({filePath: splitPath, thumbnailPath: thumbnail}),
    //                          messages[0].timestamp,
    //                          // messages[0].from,
    //                          contacts[0].profile.name,
    //                          contacts[0].profile.name,
    //                          messages[0].id]);

    //                     if(success){
    //                          // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: {filePath: splitPath, thumbnailPath: thumbnail} });
    //                          wallboard.to("demo.us1.ringq.ai").emit("queue3", {
    //                               id: roomID,
    //                               name: contacts[0].profile.name,
    //                               from: messages[0].from,
    //                               unix: messages[0].timestamp,
    //                               message: "Video attachments",
    //                               agent_unread:holderUser.agent_unread,
    //                               client_unread:holderUser.client_unread,
    //                               assigned_user:holderUser.assigned_user,
    //                               email: holderUser.email,
    //                               allocated_room: holderUser.allocated_room,
    //                               last_message: holderUser.last_message,
    //                               roomID: holderUser.roomID
    //                          });

    //                          whatsappConversationList(userName, id, roomID);
    //                     }

    //                })
    //                .screenshots({
    //                     count: 1,
    //                     filename: `thumbnail-${thumbnailName}png`,
    //                     folder: '/var/www/ringq/public/wp_vid_thumbnail/'
    //                })

    //           }else if(messages[0].type == 'audio'){

    //                messages[0].audio.newFilePath = param.filePath;

    //                let success = await redisClient.lPush("whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id + "-" + roomID, [
    //                     splitPath,
    //                     messages[0].timestamp,
    //                     // messages[0].from,
    //                     contacts[0].profile.name,
    //                     contacts[0].profile.name,
    //                     messages[0].id]);

    //                if(success){
    //                     // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: splitPath });
    //                     wallboard.to("demo.us1.ringq.ai").emit("queue3", {
    //                          id: roomID,
    //                          name: contacts[0].profile.name,
    //                          from: messages[0].from,
    //                          unix: messages[0].timestamp,
    //                          message: "Audio attachments",
    //                          agent_unread:holderUser.agent_unread,
    //                          client_unread:holderUser.client_unread,
    //                          assigned_user:holderUser.assigned_user,
    //                          email: holderUser.email,
    //                          allocated_room: holderUser.allocated_room,
    //                          last_message: holderUser.last_message,
    //                          roomID: holderUser.roomID
    //                     });

    //                     whatsappConversationList(userName, id, roomID);
    //                }

    //           }else{
    //                // wallboard.to("demo.us1.ringq.ai").emit("queue3", { id: messages[0].id, name: contacts[0].profile.name, from: messages[0].from, unix: messages[0].timestamp, message: JSON.stringify(messages[0].image) });
    //                await redisClient.lPush("whatsapp:" + metadata.display_phone_number + ":" + contacts[0].wa_id + "-" + roomID, [
    //                     JSON.stringify(messages[0].image),
    //                     messages[0].timestamp,
    //                     // messages[0].from,
    //                     contacts[0].profile.name,
    //                     contacts[0].profile.name,
    //                     messages[0].id
    //                ]);
    //                wallboard.to("demo.us1.ringq.ai").emit("queue3", {
    //                     id: roomID,
    //                     name: contacts[0].profile.name,
    //                     from: messages[0].from,
    //                     unix: messages[0].timestamp,
    //                     message: "no attachments",
    //                     agent_unread: holderUser.agent_unread,
    //                     client_unread: holderUser.client_unread,
    //                     assigned_user: holderUser.assigned_user,
    //                     email: holderUser.email,
    //                     allocated_room: holderUser.allocated_room,
    //                     last_message: holderUser.last_message,
    //                     roomID: holderUser.roomID
    //                });
    //                whatsappConversationList(userName, id, roomID);

    //           }

    //           if (messages[0].type == 'text' || messages[0].type == 'document' || messages[0].type == 'image' || messages[0].type == 'video' || messages[0].type == 'audio') {
    //                const key = "status:whatsapp:" + metadata.display_phone_number + "-" + roomID;
    //                const exists = await redisClient.exists(key);

    //                if (exists) {
    //                     const chattb = await redisClient.hGet(key, "chat");
    //                     const chatData = JSON.parse(chattb);

    //                     chatData.read = false;
    //                     chatData.status = "open";

    //                     await redisClient.hSet(key, "chat", JSON.stringify(chatData));
    //                } else {
    //                     await redisClient.hSet(
    //                          key,
    //                          "chat", JSON.stringify({
    //                               key: "whatsapp:" + metadata.display_phone_number,
    //                               status: "open",
    //                               read: false,
    //                               current_agent_assigned_to_chat: '',
    //                               transfer_activity: [],
    //                               seen_activity: [],
    //                               close_activity: [],
    //                          })
    //                     );
    //                }
    //           }
    //      }
    // });



    // socket.on("communicate3", async (arg, callback) => {
    //      
    //      

    //      wallboard.to(socket.handshake.auth.channel).emit("queue3", { id: "", name: arg['to'], from: socket.handshake.auth.extension, unix: Date.now(), message: arg['message']});
    //      await redisClient.lPush("whatsapp:919894340047:"+ arg['to'], [`${arg['message']}`, `${Date.now()}`, `${socket.handshake.auth.extension}`, `${socket.handshake.auth.extension}`, `''`]);
    //      const sendApiUrl = 'https://graph.facebook.com/v18.0/111822498634993/messages';
    //      const curlCommand = `curl -X POST "${sendApiUrl}" -H "Authorization: Bearer ${token}" -H "Content-Type: application/json" -d '${JSON.stringify({ messaging_product: 'whatsapp', to: arg['to'], type: 'text', text: { body: arg['message'] }, language: { code: 'en_US' } })}'`;

    //      // this is the curl for image
    //      //                       const imageUrl = "https://apiringq.ringq.io/img/1.png";
    //      // const curlCommand = `curl -X POST "${sendApiUrl}" -H "Authorization: Bearer ${token}" -H "Content-Type: application/json" -d '${JSON.stringify({
    //      //     messaging_product: 'whatsapp',
    //      //     to: arg['to'],
    //      //     type: 'image',
    //      //     image: {
    //      //         link: imageUrl
    //      //     },
    //      //     language: {
    //      //         code: 'en_US'
    //      //     }
    //      // })}'`;

    //      exec(curlCommand, (error, stdout, stderr) => {
    //           if (error) {
    //             console.error('Error executing curl:', error);
    //             return;
    //           }
    //           
    //           
    //      });
    //      callback(result); // Assuming result contains the result of the WhatsApp message sending
    // });

    // socket.on("facebook", async (arg, callback) => {
    //     const param = JSON.parse(arg);
    //     
    //     if (param.hasOwnProperty("message_id")) {
    //         wallboard.to("cal4care.ringq.ai").emit("facebook", { id: param["message_id"], pageToken: param["pageToken"], name: param["sender_id"], from: param["sender_id"], unix: param["timestamp"], message: param["message"] });

    //         const responseMessage = "You said: " + param["message"];

    //         // Construct the JSON payload for the message
    //         const messagePayload = JSON.stringify({
    //             messaging_type: 'RESPONSE',
    //             recipient: { id: param.sender_id },
    //             message: { text: responseMessage }
    //         });

    //         // Construct the command to execute curl
    //         const sendApiUrl = `https://graph.facebook.com/v12.0/me/messages?access_token=${fbToken}`;
    //         const curlCommand = `curl -X POST "${sendApiUrl}" -H "Content-Type: application/json" -d '${messagePayload}'`;

    //         // Execute the curl command using child_process.exec
    //         exec(curlCommand, (error, stdout, stderr) => {
    //             if (error) {
    //                 console.error('Error executing curl:', error);
    //                 return;
    //             }
    //             
    //         });
    //     }
    // });

    // socket.on("facebook", async (arg, callback) => {
    //   const param = JSON.parse(arg);

    //   const chars =
    //     "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    //   let uniqueId = "";
    //   let length = 16;
    //   for (let i = 0; i < length; i++) {
    //     uniqueId += chars.charAt(Math.floor(Math.random() * chars.length));
    //   }
    //   uniqueId = `${Date.now()}${uniqueId}`;

      

    //   // writeLog({ event: "type3", type3: { arg } });
    //   const existingUsers = await redisClient.lRange("user_data", 0, -1);
    //   let userExists = false;
    //   let unread_client = "";
    //   let unread_agent = "";
    //   let roomID = "";
    //   let sendid = "";

      

    //   //user exist, needs to update the socket
    //   for (let i = 0; i < existingUsers.length; i++) {
    //     const user = JSON.parse(existingUsers[i]);

    //     if (
    //       user.email === param.sender_id &&
    //       user.username === param.userName &&
    //       user.role == "customer"
    //     ) {
    //       console.log(
    //         "updated socket id from " +
    //           user.socketID +
    //           "to new socket.id" +
    //           socket.id
    //       );
    //       unread_agent = user.agent_unread || 0;
    //       unread_client = user.client_unread || 0;

    //       user.socketID = socket.id;
    //       user.Dept = "customer";
    //       // roomID = user.roomID;
    //       sendid = user.id;
    //       user.last_message = param.message;
    //       // socket.join("assign_messages");
    //       // user.roomID = param["sender_id"];

    //       roomID = user.id;

          
    //       await redisClient.lSet("user_data", i, JSON.stringify(user));
    //       userExists = true;
          

    //       break;
    //     }
    //   }

    //   //dosent exist
    //   if (!userExists) {
    //     let newUser = {
    //       id: uniqueId,
    //       username: param.userName,
    //       created: Date.now(),
    //       email: param.sender_id,
    //       role: "customer",
    //       socketID: socket.id,
    //       Dept: "customer",
    //       client_unread: 0,
    //       agent_unread: 0,
    //       last_message: param.message,
    //       allocated_room: "NEW",
    //       // roomID: param["sender_id"],
    //     };
    //     roomID = uniqueId;
    //     //  unread_agent = 0;
    //     //  unread_client  =  0;
    //     //  sendid = uniqueId;

    //     //  

    //     console.log(
    //       "You are about to push on DB the Facebook user: " +
    //         JSON.stringify(newUser, null, 2)
    //     );
    //     await redisClient.lPush("user_data", JSON.stringify(newUser));
        
    //     // socket.join("assign_messages");
    //   }
    //   // wallboard.to(socket.id).emit("login session", {
    //   //      message: "success",
    //   //      socket: socket.id,
    //   //      id: sendid,
    //   //      email: arg.email,
    //   //      username: arg.username,
    //   //      unread_agent: unread_agent,
    //   //      unread_client: unread_client
    //   // });

      
      
    //   if (param.hasOwnProperty("message_id")) {
    //     if (param.message != null) {
          

    //       wallboard.to("demo.us1.ringq.ai").emit("queue2", {
    //         id: roomID,
    //         name: param["userName"],
    //         from: param["userName"],
    //         unix: param["timestamp"],
    //         time: param["timestamp"],
    //         message: param["message"],
    //         agent_unread: "0",
    //         client_unread: "1",
    //         assigned_user: "",
    //         email: param["sender_id"],
    //         allocated_room: "NEW",
    //         last_message: param["message"],
    //         // roomID: param["sender_id"],
    //         Agent_Name: "",
    //       });

    //       console.log(
    //         "message saved on this database" +
    //           "facebook:919894340047:" +
    //           param["sender_id"],
    //         [
    //           `${param["message"]}`,
    //           `${param["timestamp"]}`,
    //           `${param["userName"]}`,
    //           `${param["userName"]}`,
    //           `${param["message_id"]}`,
    //         ]
    //       );
    //       await redisClient.lPush(
    //         "facebook:919894340047:" + param["sender_id"],
    //         [
    //           `${param["message"]}`,
    //           `${param["timestamp"]}`,
    //           `${param["userName"]}`,
    //           `${param["userName"]}`,
    //           `${param["message_id"]}`,
    //         ]
    //       );
    //     } else {
          
    //       let arr = param.attachmentUrls.split(",");

    //       if (arr.length > 1) {
            
    //         let container = [];
    //         arr.forEach((el) => {
    //           container.push(el.split("/public/")[1]);
    //         });

    //         wallboard.to("demo.us1.ringq.ai").emit("queue2", {
    //           id: roomID,
    //           name: param["userName"],
    //           from: param["sender_id"],
    //           unix: param["timestamp"],
    //           message: container,
    //         });

    //         await redisClient.lPush(
    //           "facebook:919894340047:" + param["sender_id"],
    //           [
    //             `${container}`,
    //             `${param["timestamp"]}`,
    //             `${param["userName"]}`,
    //             `${param["userName"]}`,
    //             `${param["message_id"]}`,
    //           ]
    //         );
    //       } else {
            
    //         let splitFilePath = param.attachmentUrls.split("/public/")[1];

    //         wallboard
    //           .to("demo.us1.ringq.ai")
    //           .emit("queue2", {
    //             id: param["message_id"],
    //             name: param["userName"],
    //             from: param["sender_id"],
    //             unix: param["timestamp"],
    //             message: splitFilePath,
    //           });

    //         await redisClient.lPush(
    //           "facebook:919894340047:" + param["sender_id"],
    //           [
    //             `${splitFilePath}`,
    //             `${param["timestamp"]}`,
    //             `${param["userName"]}`,
    //             `${param["userName"]}`,
    //             `${param["message_id"]}`,
    //           ]
    //         );
    //       }
    //     }

    //     const key = "status:facebook:919894340047:" + param["sender_id"];

        
    //     const exists = await redisClient.exists(key);

    //     if (exists) {
    //       const chattb = await redisClient.hGet(key, "chat");
    //       const chatData = JSON.parse(chattb);

    //       chatData.status = "open";

    //       await redisClient.hSet(key, "chat", JSON.stringify(chatData));
    //     } else {
    //       await redisClient.hSet(
    //         key,
    //         "chat",
    //         JSON.stringify({
    //           key: "facebook:919894340047:" + param["sender_id"],
    //           status: "open",
    //           read: false,
    //           current_agent_assigned_to_chat: "",
    //           transfer_activity: [],
    //           seen_activity: [],
    //           close_activity: [],
    //         })
    //       );
    //     }

    //     /** for all chat */
    //     await redisClient.zAdd("demo.us1.ringq.ai-conversation", {
    //       score: Date.now(),
    //       value: `${param["userName"]}-${param["sender_id"]}-facebook-${roomID}`,
    //     });
    //     /** end */

    //     /** for new chat */
    //     await redisClient.zAdd("demo.us1.ringq.ai-conversation-new", {
    //       score: Date.now(),
    //       value: `${param["userName"]}-${param["sender_id"]}-facebook-${roomID}`,
    //     });
    //     /** end */
    //   }
    // });

    // socket.on("chat-read", async (arg, callback) => {
    //   try {
    //     const domainId = "demo.us1.ringq.ai";
    //     const agentSeenChatEx = "1046";
    //     const agentSeenChatId = domainId + ":" + agentSeenChatEx;
    //     const chatKey = "whatsapp:919894340047:639306794096";
    //     const symChatKey = "status:" + chatKey;

    //     const exists = await redisClient.exists(symChatKey);
    //     if (exists) {
    //       const chattb = await redisClient.hGet(symChatKey, "chat");
    //       const chatData = JSON.parse(chattb);

    //       chatData.read = true;
    //       chatData.seen_activity.push({
    //         agent: agentSeenChatId,
    //         tracked: Date.now(),
    //       });

    //       await redisClient.hSet(symChatKey, "chat", JSON.stringify(chatData));

    //       wallboard.to(domainId).emit("read-chat", chatData);
    //     } else {
          
    //     }
    //   } catch (err) {
    //     console.error("Error handling close-chat event: ", err);
    //   }
    // });

    // socket.on("close-chat", async (arg, callback) => {
    //   try {
    //     const domainId = "demo.us1.ringq.ai";
    //     const agentCloseChatEx = "1046";
    //     const agentCloseChatId = domainId + ":" + agentAssignedEx;
    //     const chatKey = "whatsapp:919894340047:639306794096";
    //     const symChatKey = "status:" + chatKey;

    //     const exists = await redisClient.exists(symChatKey);
    //     if (exists) {
    //       const chattb = await redisClient.hGet(symChatKey, "chat");
    //       const chatData = JSON.parse(chattb);

    //       chatData.status = "close";
    //       chatData.current_agent_assigned_to_chat = "";
    //       chatData.close_activity.push({
    //         agent: agentCloseChatId,
    //         tracked: Date.now(),
    //       });

    //       await redisClient.hSet(symChatKey, "chat", JSON.stringify(chatData));

    //       wallboard.to(domainId).emit("chat-close", chatData);
    //     } else {
          
    //     }
    //   } catch (err) {
    //     console.error("Error handling close-chat event: ", err);
    //   }
    // });

    // socket.on("transfer-agent", async (arg, callback) => {
    //   try {
    //     /**
    //      * - Retrieve the ID of the agent assigned to handle the chat.
    //      * - Retrieve the unique key or identifier of the chat being transferred.
    //      */
    //     const domainId = "demo.us1.ringq.ai";
    //     const agentAssignedEx = "1010";
    //     const agentAssignedId = domainId + ":" + agentAssignedEx;
    //     const chatKey = "whatsapp:919894340047:639306794096";
    //     const symChatKey = "status:" + chatKey;

    //     const exists = await redisClient.exists(symChatKey);
    //     if (exists) {
    //       const chattb = await redisClient.hGet(symChatKey, "chat");
    //       const chatData = JSON.parse(chattb);

    //       chatData.current_agent_assigned_to_chat = agentAssignedId;
    //       chatData.transfer_activity.push({
    //         agent: agentAssignedId,
    //         tracked: Date.now(),
    //       });

    //       await redisClient.hSet(symChatKey, "chat", JSON.stringify(chatData));

    //       wallboard.to(domainId).emit("agent-transfer", chatData);
    //     } else {
          
    //     }
    //   } catch (err) {
    //     console.error("Error handling transfer-agent event: ", err);
    //   }
    // });

    // messenger image node js working

    // socket.on("facebook", async (arg, callback) => {
    // const param = JSON.parse(arg);
    // 
    // if (param.hasOwnProperty("message_id")) {
    //      wallboard.to("cal4care.dystope.com").emit("facebook", { id: param["message_id"], name: param["sender_id"], from: param["sender_id"], unix: param["timestamp"], message: param["message"] });

    //      // Define the image URL
    //      const imageUrl = "https://apiringq.ringq.io/img/1.png";

    //      // Construct the command to execute curl
    //      const sendApiUrl = `https://graph.facebook.com/v19.0/me/messages?access_token=${fbToken}`;
    //      const curlCommand = `curl -X POST "${sendApiUrl}" -H "Content-Type: application/json" -d '${JSON.stringify({ messaging_type: 'RESPONSE', recipient: { id: param.sender_id }, message: { attachment: { type: 'image', payload: { url: imageUrl, is_reusable: true } } } })}'`;

    //      // Execute the curl command
    //      exec(curlCommand, (error, stdout, stderr) => {
    //           if (error) {
    //                console.error('Error executing curl:', error);
    //                return;
    //           }
    //           
    //      });
    // }
    // });

    // messenger image node js working

    // socket.on("facebook", async (arg, callback) => {
    // const param = JSON.parse(arg);
    // 
    // if (param.hasOwnProperty("message_id")) {
    //      wallboard.to("cal4care.dystope.com").emit("facebook", { id: param["message_id"], name: param["sender_id"], from: param["sender_id"], unix: param["timestamp"], message: param["message"] });

    //      // Define the image URL
    //      const imageUrl = "https://apiringq.ringq.io/img/1.png";

    //      // Construct the command to execute curl
    //      const sendApiUrl = `https://graph.facebook.com/v19.0/me/messages?access_token=${fbToken}`;
    //      const curlCommand = `curl -X POST "${sendApiUrl}" -H "Content-Type: application/json" -d '${JSON.stringify({ messaging_type: 'RESPONSE', recipient: { id: param.sender_id }, message: { attachment: { type: 'image', payload: { url: imageUrl, is_reusable: true } } } })}'`;

    //      // Execute the curl command
    //      exec(curlCommand, (error, stdout, stderr) => {
    //           if (error) {
    //                console.error('Error executing curl:', error);
    //                return;
    //           }
    //           
    //      });
    // }
    // });

    // EMAN ADDED 06-13-25 START //
    socket.on("unreachable", async (arg, callback) => {
      wallboard.to(arg["domain"]).emit("unreachableStatus", arg["extension"]);
    });
    // EMAN ADDED 06-13-25 END //

    socket.on("disconnect", async (reason, err) => {

      if(!socket.handshake.auth) return;
      const {extension, channel, device_uuid, deviceId, pushToken} = socket.handshake.auth

      if (
        pushToken && deviceId || device_uuid
      ) {

        const deviceOriginalId = deviceId || device_uuid
        const callId = await redisClient.get(`deviceUID-${deviceOriginalId}`);
        console.log(`CALLID`, callId, `deviceUID-${deviceOriginalId}`);
        if (callId) {
          conn.api(`sofia profile internal flush_inbound_reg ${callId}`, async (res) => {
            if(res.body === '+OK flushing all registrations matching specified call_id\n'){
              console.log('REMOVE PRESENCE IN REG', socket.handshake.auth);

                  function getRegistration() {
                      return new Promise((resolve, reject) => {
                            conn.api(`sofia status profile internal reg`, (reg) => {
                                try {
                                      function parseLog(log) {
                                          const blocks = log.trim().split(/\n\s*\n/); // split by empty line
                                          const registrations = [];
                                          let total = 0;

                                          for (const block of blocks) {
                                          if (block.startsWith("Total items")) {
                                                total = parseInt(block.replace(/\D+/g, ""), 10);
                                                continue;
                                          }

                                          const entry = {};
                                          block.split("\n").forEach(line => {
                                                const match = line.match(/^(.*?):\s*\t?(.*)$/);
                                                if (match) {
                                                    const key = match[1].trim();
                                                    const value = match[2].trim();
                                                    entry[key] = value;
                                                }
                                          });
                                          registrations.push(entry);
                                          }

                                          return { Registrations: registrations, Total: total };
                                      }

                                      const result = parseLog(reg.body);
                                      if (result) {
                                          resolve(result.Registrations); // return the match properly
                                      } else {
                                          resolve(null); // no match found
                                      }

                                } catch (err) {
                                      reject(err);
                                }
                            });
                      });
                  }

                  const aliasNameExtension = async () => {
                      const { rows } = await db2.query(
                            `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                            [socket.handshake.auth.channel, socket.handshake.auth.extension]
                      );
                      const extensionOrigName = rows[0]?.number_alias;
                      return extensionOrigName
                  }

                  const value = await getRegistration();
                  const destinationAlias = await aliasNameExtension();
                  const filterPresence = value.filter(data => data['Auth-User'] === destinationAlias);
                  console.log('This is sofia internal reg destroy', filterPresence);
                  if(!filterPresence.length){

                    wallboard
                      .to(socket.handshake.auth.channel)
                      .emit("profile", {
                        id: "",
                        extension: socket.handshake.auth.extension,
                        status: "Offline",
                        color: "#D3D3D3",
                      });

                  }
            }
          });
        }
      }

      console.log("⛔<!-- SOCKET DISCONNECT FULLY CLOSED⛔", {
        reason: reason,
        socket: socket.handshake.auth,
        socketID: socket.id,
      });
    
      if (channel && extension) {

        try {

          console.log("gelzzz")
          await redisClient.sRem(`${channel}-onlineAgents`, extension);
          const onlineList = await redisClient.sMembers(`${channel}-onlineAgents`);
          console.log("sent to", `${channel}-all_messages`, onlineList, extension)
          wallboard.to(`${channel}-all_messages`).emit(`queueOnline`, onlineList);

        } catch (err) {

          console.error("Redis Cleanup Error:", err);

        }

        const realId = device_uuid || deviceId

        if (realId) {
          console.log("this presence???")
          const presenceExtension = `${extension}@${channel}`;
          if (pushToken.length === 64) {
            /** IOS DEVICE PUSH TOKEN */
            await redisClient.del(`iosAux-${presenceExtension}:${pushToken}`);
            // await redisClient.sAdd(`iosDevicePushToken-${presenceExtension}`, pushToken);

            const key = `iosDevicePushToken-${presenceExtension}`;

            await redisClient.sAdd(key, pushToken);
            await redisClient.expire(key, 3 * 24 * 60 * 60);

            const tokens = await redisClient.sMembers(key);

            console.log("this is the token", tokens);

            
            await redisClient.set(`iosTokenActive-${presenceExtension}:${pushToken}`, 1, { EX: 172800 });
          }

          const currentDevice = await redisClient.get(`device-${device_uuid}`);
          if (currentDevice) {
            if (
              currentDevice.includes(" - ") &&
              currentDevice.includes("android")
            ) {
              const devicePresence = `androidPresence-${realId}-${extension}@${channel}`;
              redisClient.set(devicePresence, "push");
            }

            if (
              currentDevice.includes(" - ") &&
              currentDevice.includes("android")
            ) {
              const devicePresence = `iOSPresence-${realId}-${extension}@${channel}`;
              redisClient.set(devicePresence, "push");
            }
            
          }

        }

        redisClient.del(
          "presenceId:" +
            socket.handshake.auth.channel +
            ":" +
            socket.handshake.auth.extension +
            ":" +
            socket.handshake.auth.pushToken
        );
        let presenceChannel = `${extension}@${channel}`;

        if (reason == "ping timeout") {
          /** Queue WallBoard */
          setTimeout(() => {
            queueDataWallboard(channel);
          }, 1000);
          /** End */
          if (socket.conn.transport.name === "websocket") {
            redisClient.set(`confimrPush-${channel}:${extension}:${pushToken}`, 1);
          }

          if (pushToken && pushToken.length > 64) {
            const webDeviceOn = await redisClient.sMembers(
              `deviceOnWeb-${extension}@${channel}`
            );
            if (webDeviceOn) {

              const key = webDeviceOn.find((x) => x === pushToken);
              
              if (key) {
                await redisClient.sRem(
                  `deviceOnWeb-${extension}@${channel}`,
                  key
                );
                const existKey = await redisClient.exists(
                  `deviceOffWeb-${extension}@${channel}`
                );
                if (existKey) {
                  await redisClient.sRem(
                    `deviceOffWeb-${extension}@${channel}`,
                    key
                  );
                }
              }

            }

            const webDeviceOff = await redisClient.sMembers(
              `deviceOffWeb-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
            );
            if (webDeviceOff) {
              const key = webDeviceOff.find(
                (x) => x === socket.handshake.auth.pushToken
              );
              
              if (key) {
                await redisClient.sRem(
                  `deviceOffWeb-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
                  key
                );
                const existKey = await redisClient.exists(
                  `deviceOnWeb-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`
                );
                if (existKey) {
                  await redisClient.sRem(
                    `deviceOnWeb-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
                    key
                  );
                }
              }
            }
          } else {
            if (pushToken && pushToken.length <= 32) {
              const callId = await redisClient.get(
                `deviceUID-${socket.handshake.auth.device_uuid}`
              );
              console.log(
                `ANDROID CALLID`,
                callId,
                `deviceUID-${socket.handshake.auth.device_uuid}`
              );

              if (callId) {
                // Delete the registration from the database
                db2.query(
                  "DELETE FROM registrations WHERE token = $1",
                  [callId],
                  (err, result) => {
                    if (err) {
                      console.error("Database error:", err);
                      return;
                    }
                    console.log(
                      `Deleted registration for USER Android: ${socket.handshake.auth.extension}`,
                      result
                    );
                  }
                );
              }
            }
          }
        }

        if (reason == "transport close") {
          if (socket.conn.transport.name === "websocket") {
            redisClient.set(
              `confimrPush-${
                socket.handshake.auth.channel +
                ":" +
                socket.handshake.auth.extension +
                ":" +
                socket.handshake.auth.pushToken
              }`,
              1
            );
          }

          

          if (pushToken && pushToken.length > 64) {
            
            await redisClient.sAdd(
              `deviceOffWeb-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
              socket.handshake.auth.pushToken
            );
            await redisClient.sRem(
              `deviceOnWeb-${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`,
              socket.handshake.auth.pushToken
            );
          }
        }

        // EMAN ADDED 06-05-25 START //
        setTimeout( async () => {

          function getRegistration() {
              return new Promise((resolve, reject) => {
                    conn.api(`sofia status profile internal reg`, (reg) => {
                        try {
                              function parseLog(log) {
                                  const blocks = log.trim().split(/\n\s*\n/); // split by empty line
                                  const registrations = [];
                                  let total = 0;

                                  for (const block of blocks) {
                                  if (block.startsWith("Total items")) {
                                        total = parseInt(block.replace(/\D+/g, ""), 10);
                                        continue;
                                  }

                                  const entry = {};
                                  block.split("\n").forEach(line => {
                                        const match = line.match(/^(.*?):\s*\t?(.*)$/);
                                        if (match) {
                                            const key = match[1].trim();
                                            const value = match[2].trim();
                                            entry[key] = value;
                                        }
                                  });
                                  registrations.push(entry);
                                  }

                                  return { Registrations: registrations, Total: total };
                              }

                              const result = parseLog(reg.body);
                              // console.log(`SOFIA REG2`, result);

                              if (result) {
                                  resolve(result.Registrations); // return the match properly
                              } else {
                                  resolve(null); // no match found
                              }

                        } catch (err) {
                              reject(err);
                        }
                    });
              });
          }

          const value = await getRegistration();
          if(value){
            const re = /(?:<\s*)?(sip:[^>\s]+)(?:\s*>)?/;
            const result = value
            .filter(r => r.Contact && r.Status && regInDomain(r, socket.handshake.auth.channel)) // [tenant-reg] own tenant only
            .map(r => {
              // extract SIP URI
              const contactMatch = r.Contact.match(re);
              const sipUri = contactMatch ? contactMatch[1] : null;

              // extract transport proto (e.g., WSS, TCP, UDP)
              const protoMatch = r.Status.match(/Registered\((\w+)-/i);
              const proto = protoMatch ? protoMatch[1].toLowerCase() : null;

              // extract expiry date and convert to unix timestamp
              const expMatch = r.Status.match(/EXP\(([^)]+)\)/);
              let unix = null;
              if (expMatch) {
                const dateStr = expMatch[1];
                const date = new Date(dateStr.replace(" ", "T"));
                unix = Math.floor(date.getTime() / 1000);
              }

              return {
                reg_user: r["Auth-User"],
                realm: r["Agent"],
                token: r["Call-ID"],
                url: sipUri ? `sofia/internal/${sipUri}` : null,
                expires: unix,
                network_ip: r.IP,
                network_port: r.Port,
                network_proto: proto,
                hostname: r.Host,
                metadata: null
              };
            });
            
            // console.log('OnlineStatusNew3', result);



            wallboard.to(socket.handshake.auth.channel).emit("onlineStatus", JSON.stringify(result));





          }

          // db2.query(
          //   "SELECT * FROM registrations WHERE realm = '" +
          //   socket.handshake.auth.channel +
          //   "'",
          //   async (err, result) => {

          //     const merged = result.rows.map(os => {
          //       const match = value.find(r => r['Call-ID'] === os.token)
          //       return {
          //         ...os,
          //         realm: match ? match.Agent : os.realm
          //       }
          //     });
          //     wallboard
          //     .to(socket.handshake.auth.channel)
          //     .emit("onlineStatus", JSON.stringify(merged));
          //   }
          // );

        }, 1000);
        // EMAN ADDED 06-05-25 END //

        if (socket.handshake.auth.hasOwnProperty("extension")) {

          const aliasNameExtension = async () => {
            const { rows } = await db2.query(
                  `SELECT number_alias FROM v_extensions WHERE accountcode = $1 AND extension = $2`,
                  [socket.handshake.auth.channel, socket.handshake.auth.extension]
            );
            if(rows.length){
              const extensionOrigName = rows[0]?.number_alias;
              return extensionOrigName
            }
            return extension
          }

          const value = await getRegistrationInternalReg();
          const destinationAlias = await aliasNameExtension();
          const filterPresence = value.filter(data => data['Auth-User'] === destinationAlias);
          if(!filterPresence.length){
            wallboard
            .to(socket.handshake.auth.channel)
            .emit("status", {
              id: socket.id,
              extension: socket.handshake.auth.extension,
              status: 0,
              auxcode: "Logged Out",
            });
          }
          // Query same agent extension and subdomain -> get call_center_agent_uuid
          // foreach call_center_agent_uuid to update
          let agentExtension = `${socket.handshake.auth.extension}@${socket.handshake.auth.channel}`;
          db2.query("SELECT name, contact FROM agents", async (err, result) => {
            const rows = result.rows;
            rows.forEach(async (element) => {
              let agentOrigExtension = element.contact.split("user/")[1];
              if (agentOrigExtension === agentExtension) {
                if (element.name === socket.handshake.auth.agentuuid) {
                  db2.query(
                    "UPDATE agents SET last_status_change = '" +
                      Math.trunc(Date.now() / 1000) +
                      "' WHERE name = '" +
                      socket.handshake.auth.agentuuid +
                      "'",
                    async (err, rows) => {}
                  );

                  conn.api(
                    "callcenter_config agent set status " +
                      socket.handshake.auth.agentuuid +
                      " 'Available'"
                  );

                  db2.query(
                    "UPDATE v_call_center_agents SET agent_status = 'Offline' WHERE agent_contact = 'user/" +
                      socket.handshake.auth.extension +
                      "@" +
                      socket.handshake.auth.channel +
                      "' AND queue_extension IS NULL"
                  );
                }
                // 
              }
            });
          });
        }
        setTimeout(() => {
          queueDataWallboard(socket.handshake.auth.channel);
        }, 1000);
      }

    });

    socket.on("notification-action", async (data, callback) => {
      
      wallboard
        .to(socket.handshake.auth.channel)
        .emit("notification-receiver", {
          id: socket.id,
          extension: data["extension"],
          device: data["device"],
          action: data["action"],
        });
    });

    socket.on("chameleon-action", async (data, callback) => {
      
      wallboard
      .to(socket.handshake.auth.channel)
      .emit("chameleon-receiver", {
        id: socket.id,
        extension: data["extension"],
        action: data["action"],
      });

    });

    socket.on("ongoing-call", async (data, callback) => {
      
      await redisClient.lPush(
        "inprogress" + "-" + data["channel-ongoing-call"],
        [
          `${Date.now()}`,
          `${data["caller-unique-id"]}`,
          `${data["channel-ongoing-call"]}`,
        ]
      );
      await redisClient.expire(
        "inprogress" + "-" + data["channel-ongoing-call"],
        65
      );

    });

    socket.on("queueTalkingTime", async (arg) => {
      // queueTalkingTime(arg)
    });

    // ─── Helpers (defined ONCE, outside the socket handler) ──────────────────────

    function parseRegistrationLog(log) {
      const blocks = log.trim().split(/\n\s*\n/);
      const registrations = [];

      for (const block of blocks) {
        if (block.startsWith("Total items")) continue;
        const entry = {};
        block.split("\n").forEach(line => {
          const match = line.match(/^(.*?):\s*\t?(.*)$/);
          if (match) entry[match[1].trim()] = match[2].trim();
        });
        registrations.push(entry);
      }
      return registrations;
    }

    function getRegistration(conn) {
      return new Promise((resolve, reject) => {
        conn.api("sofia status profile internal reg", (reg) => {
          try {
            resolve(parseRegistrationLog(reg.body));
          } catch (err) {
            reject(err);
          }
        });
      });
    }

    // [tenant] domain names a raw "show calls" row carries (accountcode, presence ids, context)
    function rowDomains(row) {
      const out = new Set();
      for (const k of ["accountcode", "b_accountcode"]) {
        const v = String(row?.[k] || "").trim();
        if (v.includes(".")) out.add(v);
      }
      for (const k of ["presence_id", "b_presence_id"]) {
        const v = String(row?.[k] || "");
        const d = v.includes("@") ? v.split("@").pop().trim() : "";
        if (d.includes(".")) out.add(d);
      }
      return [...out];
    }

    function promisifiedUuidDump(conn, uuid) {
      return new Promise((resolve) => {
        conn.api(`uuid_dump ${uuid} JSON`, (res) => resolve(res));
      });
    }

    function splitSipUri(uri) {
      return uri.includes(":") ? uri.split(":")[0] : uri;
    }

    // ─── extensionId factory (created once per event, captures `value` + `subdomain`) ─

    function makeExtensionIdResolver(value, subdomain, db2) {
      return async (ext) => {
        const found = value.find(x => regInDomain(x, subdomain) && regMatch(x, ext)); // [tenant-reg]
        if (!found) return ext;
        const { rows } = await db2.query(
          "SELECT extension FROM v_extensions WHERE number_alias = $1 AND accountcode = $2",
          [found["Auth-User"], subdomain]
        );
        return rows.length ? rows[0].extension : ext;
      };
    }

    // ─── Park logic ──────────────────────────────────────────────────────────────

    async function resolveParkUris(updatedRes, conn, redisClient) {
      const raw = await promisifiedUuidDump(conn, updatedRes.uuid);
      if (raw.body === "-ERR No such channel!\n") return;

      const data = JSON.parse(raw.body);
      const { "Call-Direction": direction, variable_sip_from_uri: fromUri, variable_sip_to_uri: toUri } = data;

      if (!fromUri || !toUri) return;

      const isInbound = direction === "inbound";
      const from = isInbound ? splitSipUri(fromUri) : fromUri;
      const to   = isInbound ? splitSipUri(toUri)   : toUri;

      const [cidFromRedis, destFromRedis] = await Promise.all([
        redisClient.get(`contactSIP-${from}`),
        redisClient.get(`contactSIP-${to}`),
      ]);

      updatedRes.cid_num = cidFromRedis
        ? cidFromRedis.split("@")[0]
        : from.split("@")[0];

      updatedRes.dest = destFromRedis
        ? destFromRedis.split("@")[0]
        : to.split("@")[0];
    }

    // ─── Socket handler ───────────────────────────────────────────────────────────

    socket.on("progressRinging", async (arg) => {
      const { subdomain } = arg;
      if (!subdomain || subdomain === "default") return;
      
      const showCalls = JSON.parse(arg.data);
      if (!showCalls.row_count) {
        wallboard.to(subdomain).emit("ringProgress", arg.data);
        return;
      }

      const value = await getRegistration(conn);
      const extensionId = makeExtensionIdResolver(value, subdomain, db2);

      const newEventCall = await Promise.all(
        showCalls.rows.map(async (res) => {
          const { uuid, name, dest, b_name } = res;
          console.log("☠️", res);
          if(dest != 'limit_exceeded'){
            let updatedRes = { ...res };
            // [tenant] the domains this call belongs to, from the raw row (before the rewrites
            // below put this subdomain into presence_id)
            updatedRes.__dom = rowDomains(res);
            const channelExactName = await presenceId(name);
            const callerExtension  = await extensionId(channelExactName);



            console.log(`This is called here☠️☠️☠️☠️`,uuid , name, channelExactName, callerExtension)

            if (res.callstate === "ACTIVE") {
              const rawDest  = b_name ? await presenceId(b_name) : dest;
              const destExtension = await extensionId(rawDest);

              console.log('☠️This is called☠️', destExtension);

              if(destExtension != 'bridge_to_agent'){
                const origCidnum       = updatedRes.callee_name === "Conference"
                  ? updatedRes.callee_num
                  : callerExtension;
                console.log(b_name ? "☠️ACTIVE BNAME☠️" : "☠️ACTIVE NORMAL☠️",
                callerExtension, destExtension, b_name, dest);
                updatedRes.cid_num     = origCidnum;
                updatedRes.presence_id = `${callerExtension}@${subdomain}`;
                updatedRes.dest        = destExtension;
                updatedRes.status      = "Answered";
                console.log("this is now updatedRes Answered", updatedRes)
              }else{
                return null
              }
            } else {
              const destExtension    = await extensionId(dest);
              if(destExtension != 'bridge_to_agent'){
                console.log("☠️KWERTY☠️", callerExtension, destExtension);
                updatedRes.cid_num     = callerExtension;
                updatedRes.presence_id = `${callerExtension}@${subdomain}`;
                updatedRes.dest        = destExtension;
                updatedRes.status      = "Ringing";
                console.log("this is now updatedRes Ringing", updatedRes);
              }else{
                return null;
              }
            }

            if (res.dest === "park") {
              await resolveParkUris(updatedRes, conn, redisClient);
            }
            return updatedRes;
          }
        })
      );

      const newEvent = newEventCall.filter(Boolean)
      const callStatusData = await Promise.all(
        newEvent.map(async (element) => {
          const res = await promisifiedUuidDump(conn, element.uuid);
          console.log("RULE♨️", res);
          if (res.body === "-ERR No such channel!\n") {
            return { ...element, caller_id: "", gateway: "", rule: "", duration: 0 };
          }

          try {
            const {
              variable_effective_outbound_caller_id_name: outboundNum,
              variable_domain_uuid,
              variable_domain_name,
              variable_sip_gateway_name,
              variable_rule,
              variable_pin_number,
              variable_current_application_data: callOutbound,
            } = JSON.parse(res.body);

            const sipDIDs = async () => {
                const { rows } = await db2.query(
                      `SELECT sip_trunk_name, name FROM q_dids WHERE domain_uuid = $1 AND extension = $2`,
                      [variable_domain_uuid, element.dest]
                );
                if(!rows.length) return {route: '', rule: ''};
                return {route: rows[0]?.sip_trunk_name, rule: rows[0]?.name};
            }

            const {route, rule} = await sipDIDs();
            console.log('🧩This is RULE. ROUTE.', await sipDIDs());

            const gatewayInbound = callOutbound?.includes("gateway")
              ? callOutbound.split("gateway/")[1]?.split("/")[0]
              : "";

            if (variable_domain_name) element.__dom = [...(element.__dom || []), String(variable_domain_name)];
            return {
              ...element,
              caller_id: variable_domain_name === subdomain ? outboundNum || "" : "",
              gateway:   variable_sip_gateway_name || gatewayInbound,
              // [outbound-pin] "outbound-pin" -> "O-pin/<outbound rule name>"
              rule:      await outboundPin.pinRuleLabel(db2, {
                domainUuid: variable_domain_uuid, rule: variable_rule || rule,
                dest: element.dest, caller: element.cid_num, pinUsed: !!variable_pin_number,
              }),
              route: route, 
              duration:  0,
            };
          } catch (error) {
            console.error("Error parsing uuid_dump response:", error);
            return { ...element, caller_id: "", gateway: "", rule: "", duration: 0 };
          }
        })
      );

      // [tenant] "show calls" lists every call on the server: on a multi-tenant server send this
      // domain only its own calls (a call whose legs belong only to other domains is dropped;
      // a row with no domain information at all is kept, as before)
      const ownCalls = callStatusData.filter((row) => {
        const doms = Array.isArray(row.__dom) ? row.__dom.filter(Boolean) : [];
        delete row.__dom;
        return !doms.length || doms.includes(subdomain);
      });
      const trimmed = ownCalls.slice(0, 9);
      console.log("FIND GATEWAY♨️", trimmed);

      wallboard.to(subdomain).emit(
        "ringProgress",
        JSON.stringify({ row_count: trimmed.length, rows: trimmed })
      );
    });

    socket.on("prevActiveCallsRefresh", async (arg) => {

          // setTimeout( async () => {
          //   console.log('This is called here RING PROGRESS 2', arg.subdomain)
          //   await redisClient.lTrim(`lastActiveCall-${arg.subdomain}`, 0, 9);
          //   const active10Calls = await redisClient.lRange(`lastActiveCall-${arg.subdomain}`, 0, -1);
          //   // console.log('DURATION CALLS', active10Calls);
          //   if (active10Calls) {

          //       const dataNew = active10Calls.map(JSON.parse);
          //       const emitShowCalls2 = JSON.stringify({
          //           row_count: dataNew.length,
          //           rows: dataNew,
          //       });
          //       console.log("This is called filter previous calls", {
          //           data: emitShowCalls2,
          //           subDomain: arg.subdomain,
          //       });
          //       // wallboard.to(arg.subdomain).emit("ringProgress3", emitShowCalls2);
          //   }
          // }, 3000)

    })

    socket.on("lastActive", async (arg) => {

        const redisKey = `lastActiveCall-${arg}`;
        const data = await redisClient.lRange(redisKey, 0, -1);
        const parseData = data.map((res) => JSON.parse(res));
        const emitShowCalls = JSON.stringify({
          row_count: parseData.length,
          rows: parseData.reverse(),
        });
        

        console.log(`DATA LAST ACTIVE`, emitShowCalls);
        wallboard.to(arg).emit(`lastActive`, emitShowCalls);

    });

    socket.on("failedActive", async (arg) => {

        const { channel } = socket.handshake.auth;
        if(channel){
          const redisKey = `failedActiveCall-${channel}`;
          const data = await redisClient.lRange(redisKey, 0, -1);
          const parseData = data.map((res) => JSON.parse(res));

          const emitShowCalls = JSON.stringify({
            row_count: parseData.length,
            rows: parseData.reverse(),
          });

          console.log(`DATA FAILED ACTIVE`, arg, channel, emitShowCalls);
          wallboard.to(channel).emit(`failedActive`, emitShowCalls);
        }

    });

    socket.on("failedActive2", async (subdomain) => {

        const redisKey = `failedActiveCall-${subdomain}`;
        const data = await redisClient.lRange(redisKey, 0, -1);
        const parseData = data.map((res) => JSON.parse(res));

        const emitShowCalls = JSON.stringify({
          row_count: parseData.length,
          rows: parseData.reverse(),
        });

        console.log(`DATA FAILED ACTIVE2`, emitShowCalls);
        wallboard
        .to(subdomain)
        .emit(`failedActive`, emitShowCalls);

    });

    socket.on("killChannel", (arg) => {
      const channel = String((socket.handshake.auth || {}).channel || "");
      const uuid = String(typeof arg === "object" && arg !== null ? arg.uuid || "" : arg || "").trim().toLowerCase();
      if (!socket.data.presenceAuthorised || !channel || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(uuid)) {
        console.log("KILL refused", socket.id, channel);
        return;
      }
      conn.api(`uuid_getvar ${uuid} domain_name`, (getvar) => {
        const domain = String((getvar && getvar.body) || "").trim();
        const kill = () => {
          conn.api(`uuid_kill ${uuid}`);
          console.log("KILL 3", channel, uuid);
        };
        if (domain === channel) {
          kill();
          return;
        }
        conn.api("show channels as json", (res) => {
          let rows = [];
          try {
            rows = JSON.parse((res && res.body) || "{}").rows || [];
          } catch (e) {
            rows = [];
          }
          const row = rows.find((r) => String(r.uuid || "").toLowerCase() === uuid);
          const presence = String((row && row.presence_id) || "");
          if (row && (row.context === channel || presence.endsWith("@" + channel))) {
            kill();
          } else {
            console.log("KILL refused, not this account's call", channel, uuid);
          }
        });
      });
    });

    socket.on("blockedStatusServer", (arg) => {
      wallboard
      .to(arg.subdomain)
      .emit(`blockedStatus`, JSON.stringify({ data: arg.data }));
    });

    socket.on("blockedStatusServer", (arg) => {
      wallboard
      .to(arg.subdomain)
      .emit(`blockedStatus`, JSON.stringify({ data: arg.data }));
    });

    socket.on('systemInfo', async (domain) => {

        const { channel } = socket.handshake.auth;
        const [
            systemLoadSystem,
            systemLoadPercentage,
            systemLoadPercentage2Raw,
            systemDiskUsagePercentage,
            systemMemTotal,
            systemMemUsed,
            systemMemFree,
            systemMemAvailable,
            systemCoreCount,
            totalSpaceGB
        ] = await Promise.all([
            redisClient.lRange("systemLoadSystem2", -1, -1),
            redisClient.hGetAll("systemLoadPercentage2"),
            redisClient.hGetAll("systemLoadPercentage"),
            redisClient.lRange("systemDiskUsagePercentage2", -1, -1),
            redisClient.lRange("systemMemTotal2", -1, -1),
            redisClient.lRange("systemMemUsed2", -1, -1),
            redisClient.lRange("systemMemFree2", -1, -1),
            redisClient.lRange("systemMemAvailable2", -1, -1),
            redisClient.get("systemCoreCount"),
            redisClient.get("totalSpaceGB")
        ]);

        // Handle the fallback for systemLoadPercentage2
        let systemLoadPercentage2 = systemLoadPercentage2Raw;
        if (!systemLoadPercentage2 || Object.keys(systemLoadPercentage2).length === 0) {
            systemLoadPercentage2 = {
                "0": 20, "1": 20, "2": 20, "3": 20, "4": 20, "5": 20,
                "6": 20, "7": 20, "8": 20, "9": 20, "10": 20, "11": 20,
                "12": 20, "13": 20, "14": 20, "15": 20, "16": 20, "17": 20,
                "18": 20, "19": 20, "20": 20, "21": 20, "22": 20, "23": 20
            };
        }

        const keys = Object.keys(systemLoadPercentage);
        const lastKey = keys[keys.length - 1];
        const currentLoad = Number(systemLoadPercentage[lastKey]);

        const payloadEmit = {
          "systemLoadSystem": Number(systemLoadSystem[0]),
          "systemLoadPercentage": currentLoad,
          systemLoadPercentage2,
          "systemDiskUsagePercentage": Number(systemDiskUsagePercentage[0]),
          "systemMemTotal": Number(systemMemTotal[0]),
          "systemMemUsed": Number(systemMemUsed[0]),
          "systemMemFree": Number(systemMemFree[0]),
          "systemMemAvailable": Number(systemMemAvailable[0]),
          "systemCoreCount": Number(systemCoreCount),
          "systemDiskTotalSpace": Number(totalSpaceGB)
        }

        console.log(`📊SYSTEM INFO`, socket.handshake.auth, payloadEmit);
        wallboard.to(channel).emit("systemInfo2", payloadEmit);

    });

    socket.on('agenDetailsWallboard', async (subdomain) => {

        const [agentBody, tierBody] = await Promise.all([
            new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
            new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
        ]);

        const agentList = JSON.parse(agentBody.body).response;
        const tierList  = JSON.parse(tierBody.body).response;
        const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

        const matched = tierList.reduce((acc, tier) => {
            const agent = agentMap[tier.agent];
            if (
                agent && 
                agent.contact.includes(subdomain)
            ) acc.push(agent);
            return acc;
        }, []);
        console.log("✅ matched:", matched);

        if(matched.length){

            const names = matched.map(e => e.name);
            const { rows } = await db2.query(
                `SELECT 
                    ad.name,
                    ad.contact,
                    ad.max_no_answer,
                    ad.wrap_up_time,
                    ad.reject_delay_time,
                    ad.busy_delay_time,
                    ad.no_answer_delay_time,
                    ad.last_bridge_start,
                    ad.last_bridge_end,
                    ad.last_offered_call,
                    ad.last_status_change,
                    ad.no_answer_count,
                    ad.calls_answered, 
                    ad.talk_time,
                    ad.ready_time,
                    ad.external_calls_count,
                    ve.extension
                FROM agent_details ad
                LEFT JOIN v_extensions ve 
                    ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                WHERE ad.name = ANY($1)`,
                [names]
            );

            // Map results by name for O(1) lookup
            const dbMap = rows.reduce((acc, row) => {
                acc[row.name] = row;
                return acc;
            }, {});

            const newPayload = matched.map(element => {
                const data = dbMap[element.name];
                if(data){
                    const { 
                        contact,
                        max_no_answer,
                        wrap_up_time,
                        reject_delay_time,
                        busy_delay_time,
                        no_answer_delay_time,
                        last_bridge_start,
                        last_bridge_end,
                        last_offered_call,
                        last_status_change,
                        no_answer_count,
                        calls_answered, 
                        talk_time,
                        ready_time,
                        external_calls_count,
                        extension 
                    } = data;
                    console.log('👉🏻NAMES', JSON.stringify(names));
                    const newContact = extension ? updateContact(contact, extension) : contact;
                    return {
                        ...element,
                        contact: newContact,
                        max_no_answer: Number(max_no_answer),
                        wrap_up_time: Number(wrap_up_time),
                        reject_delay_time: Number(reject_delay_time),
                        busy_delay_time: Number(busy_delay_time),
                        no_answer_delay_time: Number(no_answer_delay_time),
                        last_bridge_start: Number(last_bridge_start),
                        last_bridge_end: Number(last_bridge_end),
                        last_offered_call: Number(last_offered_call),
                        last_status_change: Number(last_status_change),
                        no_answer_count: Number(no_answer_count),
                        calls_answered: Number(calls_answered),
                        talk_time: Number(talk_time),
                        ready_time: Number(ready_time),
                        external_calls_count: Number(external_calls_count)
                    };
                }
            });
            
            const filteredAgent = newPayload.filter(Boolean);
            console.log('👉🏻👉🏻 AGENT RELOAD', JSON.stringify(filteredAgent));
            await redisClient.set(`${subdomain}:queueWallboardAgentBoard2`, JSON.stringify(filteredAgent));
            wallboard.to(subdomain).emit("queueAgentboard2", JSON.stringify(filteredAgent));
           
        }

    })

})

wallboard.listen(3000);

process.on("SIGINT", () => {
  
  wallboard.emit("server_shutdown");
  setTimeout(() => process.exit(0), 100);
});

async function queueDataWallboard(subdomain) {
  
}



// (async () => {
//   const scheduledCrons = new Set();
//   db2.query("SELECT domain_name FROM v_domains", (err, result) => {
//     if (err) {
//       console.error("Query error:", err);
//       return;
//     }

//     const rows = result.rows;
//     if (!Array.isArray(rows) || rows.length === 0) {
//       console.warn("No subdomains found.");
//       return;
//     }

//     rows.forEach(({ domain_name: subdomain }) => {
//       if (scheduledCrons.has(subdomain)) return;
//       scheduledCrons.add(subdomain);
//       cron.schedule("0 0 * * *", () => resetSubdomainData(subdomain), {
//         timezone: "Asia/Manila",
//       });
//     });
    
//   });
// })();


cron.schedule('*/20 * * * * *', async () => {
    try {
        const instance = process.env.NODE_APP_INSTANCE;

        if (instance !== undefined && instance !== '0') {
            return;
        }

        const result = await db2.query(
            "SELECT domain_name FROM v_domains"
        );

        const rows = result.rows;

        if (!Array.isArray(rows) || rows.length === 0) {
            console.warn("No subdomains found.");
            return;
        }

        await Promise.all(
            rows.map(async ({ domain_name: subdomain }) => {

                if (!subdomain) {
                    return;
                }

                const extensionChannel =
                    `android-devices:${subdomain}`;

                const extensions =
                    await redisClient.sMembers(extensionChannel);

                const payload = {
                    extension: extensions,
                    domain: subdomain
                };

                console.log(
                    "sending android-wakeup:",
                    payload
                );

                statusAndroidPush.emit(
                    "android-wakeup",
                    payload
                );
            })
        );

    } catch (error) {
        console.error(
            "android-wakeup cron error:",
            error
        );
    }
});


const queueExtensionQuery = async (subdomain) => {
  try {
    const query = `
             SELECT did.extension 
             FROM v_domains AS domain 
             LEFT JOIN q_dids AS did ON domain.domain_uuid = did.domain_uuid 
             WHERE domain.domain_name = $1 
             AND CAST(did.extension AS INTEGER) >= 9000 
             AND CAST(did.extension AS INTEGER) < 10000
         `;
    const response = await db2.query(query, [subdomain]);
    return response.rows;
  } catch (err) {
    console.error("Error Queue Waiting", err);
    throw err;
  }
};

const queueListQueuery = async (subdomain) => {
  try {
    const query = `SELECT callcenter.queue_extension
          FROM v_domains AS domain 
          LEFT JOIN v_call_center_queues AS callcenter 
          ON domain.domain_uuid = callcenter.domain_uuid 
          WHERE domain.domain_name = $1 
          AND CAST(callcenter.queue_extension AS INTEGER) >= 9000 
          AND CAST(callcenter.queue_extension AS INTEGER) < 10000
     `;
    const response = await db2.query(query, [subdomain]);
    return response.rows;
  } catch (error) {
    console.error("Error Queue Waiting", error);
    throw error;
  }
};

const isValidNumber = (input) => {
  const regex = /^\+?\d+$/;
  return regex.test(input);
};

async function getActiveCalls(conn) {
  return new Promise((resolve, reject) => {
    conn.api(`show channels as json`, (response) => {
      //    
      try {
        const { body } = response;
        const { row_count, rows } = JSON.parse(body);
        if (row_count) {
          const activeCall = rows.filter((x) => x.callstate === "ACTIVE");
          resolve(activeCall);
        } else {
          resolve([]); // Return an empty array if no active calls
        }
      } catch (error) {
        reject(error);
      }
    });
  });
}

const PRESENCE_SNAPSHOT_TTL_MS = 2000;
const PRESENCE_REGISTRATION_TIMEOUT_MS = 3000;
const PRESENCE_SUPPORT_EXTENSIONS = ["9997", "9998", "9999"];
const presenceSnapshotCache = new Map();

async function loadPresenceSnapshot(channel) {
  const cached = presenceSnapshotCache.get(channel);
  if (cached && (cached.pending || Date.now() - cached.at < PRESENCE_SNAPSHOT_TTL_MS)) {
    return cached.pending || cached.value;
  }
  const pending = (async () => {
    const readAt = Date.now();
    const { rows } = await db2.query(
      `SELECT e.extension, e.number_alias,
              (SELECT u.user_status FROM v_users u
                WHERE u.domain_uuid = e.domain_uuid AND u.username = e.number_alias
                LIMIT 1) AS user_status,
              (SELECT b.call_profiles_colors FROM q_call_behaviors b
                WHERE b.domain_uuid = e.domain_uuid AND b.extension = e.extension
                LIMIT 1) AS call_profiles_colors
         FROM v_extensions e
        WHERE e.accountcode = $1
        ORDER BY e.extension`,
      [channel]
    );
    const profiles = rows.length
      ? await redisClient.mGet(rows.map((row) => `${row.extension}+${channel}`))
      : [];
    let registered = null;
    try {
      const registrations = await Promise.race([
        fsRegistration(),
        new Promise((_, reject) =>
          setTimeout(() => reject(new Error("registration lookup timed out")), PRESENCE_REGISTRATION_TIMEOUT_MS)
        ),
      ]);
      registered = new Set(
        (registrations || [])
          .filter((reg) => reg["Auth-Realm"] === channel)
          .map((reg) => reg["Auth-User"])
      );
    } catch (err) {
      console.error("presenceSnapshot registrations unavailable:", err.message);
    }
    const value = rows.map((row, i) => {
      const status = profiles[i] ? String(profiles[i]) : "0";
      const colors = String(row.call_profiles_colors || "").split(",");
      const index = Number.parseInt(status, 10);
      const color = Number.isInteger(index) && colors[index] ? colors[index].trim() : null;
      const status2 = registered && registered.has(row.number_alias)
        ? "Available"
        : (row.user_status ?? "Logged Out");
      return { extension: String(row.extension), status, color: color || null, status2 };
    });
    const snapshot = { readAt, entries: value };
    presenceSnapshotCache.set(channel, { at: readAt, value: snapshot });
    return snapshot;
  })();
  presenceSnapshotCache.set(channel, { at: Date.now(), pending });
  try {
    return await pending;
  } catch (err) {
    presenceSnapshotCache.delete(channel);
    throw err;
  }
}

async function buildPresenceSnapshot(channel, viewerExtension) {
  const viewerIsSupport = PRESENCE_SUPPORT_EXTENSIONS.includes(String(viewerExtension));
  const { readAt, entries } = await loadPresenceSnapshot(channel);
  return {
    ts: readAt,
    extensions: entries.filter((entry) =>
      entry.extension.length <= 4 &&
      (!PRESENCE_SUPPORT_EXTENSIONS.includes(entry.extension) ||
        (viewerIsSupport && /^\d+$/.test(entry.extension)))
    ),
  };
}

async function emitPresenceSnapshot(socket) {
  const channel = socket.handshake.auth && socket.handshake.auth.channel;
  if (!socket.data.presenceAuthorised || !channel) return;
  try {
    const snapshot = await buildPresenceSnapshot(channel, socket.handshake.auth.extension);
    if (socket.connected) socket.emit("presenceSnapshot", snapshot);
  } catch (err) {
    console.error("presenceSnapshot failed:", err.message);
  }
}

// [device-presence] which kinds of device each extension has connected right now, for the
// Team Directory icons: web app, desktop app, desk phone (any other SIP device), mobile app.
// A mobile app that is not registered but can be woken by push counts as mobile "push".
function deviceKind(agent) {
  const a = String(agent || "");
  if (/webapp/i.test(a)) return "web";
  if (/ringq desktop|electron/i.test(a)) return "desktop";
  if (/ringq (android|ios)|android|iphone|ipad|\bios\b/i.test(a)) return "mobile";
  return "desk";
}

// [ext-access] Enable off: extoff:<ext>:<domain> (push senders skip it), every registration of the
// extension dropped, cached directory entry cleared (FreeSWITCH then refuses it: disabled extensions
// are left out of the directory). Mobile App off: its mobile-app registrations dropped (push senders
// also skip disableapp:<ext>:<domain>). Webapp off: its web-app registrations dropped. In all cases
// the extension's open web apps get 'accessChanged' (sign out / hide Call Control).
// [ext-access] Mobile App permission off: every mobile app of the extension is signed out (the app
// logs itself out on 'status' {device: <its push token>, action: 3}, same as "Delete device"), and its
// push tokens and device sign-ins are removed so a swiped-away app is not woken again.
async function signOutMobileApps(subdomain, ext, alias) {
  const base = `${ext}@${subdomain}`;
  let rows = [];
  try {
    ({ rows } = await db2.query(
      `SELECT q.id, q.push_token FROM q_devices q JOIN v_domains d ON d.domain_uuid = q.domain_uuid
        WHERE d.domain_name = $1 AND q.extension = $2 AND q.device_type::text IN ('1', '2')`, [subdomain, ext]));
  } catch (e) { rows = []; }
  const tokens = new Set(rows.map((r) => String(r.push_token || "")).filter(Boolean));
  for (const t of await redisClient.sMembers(`iosDevicePushToken-${base}`).catch(() => [])) tokens.add(String(t));
  for await (const key of redisClient.scanIterator({ MATCH: `androidTokenActive-${base}:*`, COUNT: 200 })) {
    tokens.add(key.slice(`androidTokenActive-${base}:`.length));
    await redisClient.del(key).catch(() => {});
  }
  for (const t of tokens) {
    const msg = { id: "", extension: ext, device: t, action: 3 };
    wallboard.to(base).emit("status", msg);
    wallboard.to(t).emit("status", msg);
  }
  await redisClient.del([`iosDevicePushToken-${base}`, `deviceOff-${base}`, `deviceOn-${base}`]).catch(() => {});
  if (alias) await redisClient.del(`mydevMobile-${subdomain}:${alias}`).catch(() => {});
  if (rows.length) await db2.query(`DELETE FROM q_devices WHERE id = ANY($1::int[])`, [rows.map((r) => r.id)]).catch((e) => console.log("[ext-access] q_devices", e && e.message));
  console.log("[ext-access] mobile apps signed out", base, tokens.size, "token(s)", rows.length, "sign-in(s)");
}

async function applyExtensionAccess({ subdomain, extension, enabled = true, app = true, webapp = true, callcontrol = true }) {
  if (!subdomain || !extension) return;
  const ext = String(extension);
  const { rows } = await db2.query(
    `SELECT e.number_alias FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid
     WHERE d.domain_name = $1 AND e.extension = $2`, [subdomain, ext]);
  const alias = rows[0] && rows[0].number_alias ? String(rows[0].number_alias) : "";
  if (enabled) await redisClient.del(`extoff:${ext}:${subdomain}`);
  else await redisClient.set(`extoff:${ext}:${subdomain}`, "1");
  const api = (cmd) => new Promise((resolve) => { try { conn.api(cmd, () => resolve()); } catch (e) { resolve(); } });
  for (const u of [ext, alias].filter(Boolean)) await api(`memcache delete directory:${u}@${subdomain}`);
  // registrations of this extension: username (alias) is unique per domain; a bare extension
  // number is only matched together with the domain (flush by user@domain)
  let regs = [];
  try { regs = JSON.parse((await redisClient.get(`onlineStatus-${subdomain}`)) || "[]"); } catch (e) { regs = []; }
  const mine = (regs || []).filter((r) => r && r.token && alias && String(r.reg_user) === alias);
  const drop = async (list) => { for (const r of list) await api(`sofia profile internal flush_inbound_reg ${r.token}`); };
  if (!enabled) {
    await drop(mine);
    for (const u of [ext, alias].filter(Boolean)) await api(`sofia profile internal flush_inbound_reg ${u}@${subdomain}`);
  } else {
    if (!app) await drop(mine.filter((r) => deviceKind(r.realm) === "mobile"));
    if (!webapp) await drop(mine.filter((r) => deviceKind(r.realm) === "web"));
  }
  if (!app) await signOutMobileApps(subdomain, ext, alias);
  wallboard.to(`${ext}@${subdomain}`).emit("accessChanged", { extension: ext, enabled: !!enabled, app: !!app, webapp: !!webapp, callcontrol: !!callcontrol });
  console.log("[ext-access]", `${ext}@${subdomain}`, { enabled, app, webapp, callcontrol, dropped: !enabled ? "all" : mine.length });
  scheduleOnlineStatusRefresh(subdomain);
}

// [perf] extensions with an Android push-token marker (androidTokenActive-<ext>@<domain>:<token>),
// cached per domain for 60 s: one SCAN a minute instead of one per registration event.
const androidPushCache = new Map(); // subdomain -> { t, exts, p }
async function androidPushExtensions(subdomain) {
  const c = androidPushCache.get(subdomain);
  if (c && c.exts && Date.now() - c.t < 60000) return c.exts;
  if (c && c.p) return c.p;
  const p = (async () => {
    const exts = new Set();
    try {
      for await (const key of redisClient.scanIterator({ MATCH: `androidTokenActive-*@${subdomain}:*`, COUNT: 2000 })) {
        exts.add(key.slice("androidTokenActive-".length).split("@")[0]);
      }
    } catch (e) { /* optional */ }
    androidPushCache.set(subdomain, { t: Date.now(), exts });
    return exts;
  })();
  androidPushCache.set(subdomain, { ...(c || {}), p });
  return p;
}

async function emitDevicePresence(subdomain, regs) {
  if (!subdomain) return;
  const { rows } = await db2.query(
    `SELECT e.extension, e.number_alias, e.enabled FROM v_extensions e JOIN v_domains d ON d.domain_uuid = e.domain_uuid
     WHERE d.domain_name = $1`, [subdomain]);
  const byUser = new Map();
  // [device-presence] a disabled extension can't take calls: it gets disabled:true and is never
  // listed as push-reachable (its push token can stay in Redis for ~2 days after the app ran).
  const disabled = new Set();
  rows.forEach((r) => {
    byUser.set(String(r.extension), String(r.extension));
    if (r.number_alias) byUser.set(String(r.number_alias), String(r.extension));
    if (String(r.enabled).toLowerCase() === "false") disabled.add(String(r.extension));
  });
  const out = {};
  const blank = () => ({ web: 0, desktop: 0, desk: 0, mobile: 0, push: [], list: [] });
  // short, IP-free label per device for the Team Directory hover
  const labelOf = (agent, kind) => {
    const a = String(agent || "");
    const ver = (/(\d+\.\d+(?:\.\d+)*)/.exec(a) || [])[1] || "";
    if (kind === "web") {
      const b = /Edg\//.test(a) ? "Edge" : /Firefox\//.test(a) ? "Firefox" : /Chrome\//.test(a) ? "Chrome" : /Safari\//.test(a) ? "Safari" : "Browser";
      const os = /Mac OS X/.test(a) ? "macOS" : /Windows/.test(a) ? "Windows" : /Android/.test(a) ? "Android" : /iPhone|iPad/.test(a) ? "iOS" : /Linux/.test(a) ? "Linux" : "";
      return `Web app · ${b}${os ? ` on ${os}` : ""}`;
    }
    if (kind === "desktop") return `Desktop app${ver ? ` ${ver}` : ""}`;
    if (kind === "mobile") return `${/android/i.test(a) ? "Android" : "iPhone"} app${ver ? ` ${ver}` : ""}`;
    return (a.replace(/\s+[\d.]{5,}.*$/, "").trim() || "SIP device").slice(0, 40);
  };
  const bump = (ext, kind, agent) => {
    out[ext] = out[ext] || blank();
    out[ext][kind] += 1;
    if (out[ext].list.length < 12) out[ext].list.push({ kind, label: labelOf(agent, kind) });
  };
  (regs || []).forEach((r) => {
    const ext = byUser.get(String(r.reg_user || ""));
    if (ext) bump(ext, deviceKind(r.realm), r.realm);
  });
  // mobile apps reachable by push (iOS token set / Android token marker) and the Mobile App /
  // Webapp permission flags. [perf] No SCAN over the whole keyspace on every onlineStatus (busy
  // servers have 500k+ Redis keys: that kept Redis at 100% CPU): iOS / permission keys are read
  // per extension in one pipeline; Android token markers (one key per token) come from a SCAN
  // that runs at most once a minute per domain.
  const exts = [...new Set(rows.map((r) => String(r.extension)))];
  try {
    const m = redisClient.multi();
    exts.forEach((ext) => {
      m.sCard(`iosDevicePushToken-${ext}@${subdomain}`);
      m.exists(`disableapp:${ext}:${subdomain}`);
      m.exists(`disablewebapp:${ext}:${subdomain}`);
    });
    const res = await m.exec();
    exts.forEach((ext, i) => {
      const ios = Number(res[i * 3]) || 0;
      const appOff = Number(res[i * 3 + 1]) === 1;
      const webOff = Number(res[i * 3 + 2]) === 1;
      if (!ios && !appOff && !webOff) return;
      out[ext] = out[ext] || blank();
      if (ios && !out[ext].push.includes("ios")) out[ext].push.push("ios");
      if (webOff) out[ext].webDisabled = true;
      if (appOff) { out[ext].appDisabled = true; }
    });
  } catch (e) { /* push info is optional */ }
  try {
    for (const ext of await androidPushExtensions(subdomain)) {
      if (!byUser.has(ext)) continue;
      out[ext] = out[ext] || blank();
      if (!out[ext].appDisabled && !out[ext].push.includes("android")) out[ext].push.push("android");
    }
  } catch (e) { /* optional */ }
  Object.keys(out).forEach((ext) => { if (out[ext].appDisabled) out[ext].push = []; });
  disabled.forEach((ext) => {
    out[ext] = out[ext] || blank();
    out[ext].push = [];
    out[ext].disabled = true;
  });
  // [ext-access] keep the push senders' "extension off" flag in step with the database
  try {
    const m = redisClient.multi();
    rows.forEach((r) => {
      const k = `extoff:${r.extension}:${subdomain}`;
      if (disabled.has(String(r.extension))) m.set(k, "1"); else m.del(k);
    });
    await m.exec();
  } catch (e) { /* best effort */ }
  const payload = JSON.stringify(out);
  await redisClient.set(`devicePresence-${subdomain}`, payload);
  wallboard.to(subdomain).emit("devicePresence", payload);
}

async function onlineExtensionStatus(subdomain) {

  async function getRegistration() {
      return await new Promise((resolve, reject) => {
            conn.api(`sofia status profile internal reg`, (reg) => {
                try {
                      function parseLog(log) {
                          const blocks = log.trim().split(/\n\s*\n/); // split by empty line
                          const registrations = [];
                          let total = 0;

                          for (const block of blocks) {
                          if (block.startsWith("Total items")) {
                                total = parseInt(block.replace(/\D+/g, ""), 10);
                                continue;
                          }

                          const entry = {};
                          block.split("\n").forEach(line => {
                                const match = line.match(/^(.*?):\s*\t?(.*)$/);
                                if (match) {
                                    const key = match[1].trim();
                                    const value = match[2].trim();
                                    entry[key] = value;
                                }
                          });
                          registrations.push(entry);
                          }

                          return { Registrations: registrations, Total: total };
                      }

                      const result = parseLog(reg.body);
                      // console.log(`SOFIA REG1`, result);

                      if (result) {
                          resolve(result.Registrations); // return the match properly
                      } else {
                          resolve(null); // no match found
                      }

                } catch (err) {
                      reject(err);
                }
            });
      });
  }
  const value = await getRegistration();
  if(value){
      const re = /(?:<\s*)?(sip:[^>\s]+)(?:\s*>)?/;
      const result = value
      .filter(r => r.Contact && r.Status && r["Auth-User"] && regInDomain(r, subdomain)) // [tenant-reg] own tenant only
      .map(r => {
        // extract SIP URI
        const contactMatch = r.Contact.match(re);
        const sipUri = contactMatch ? contactMatch[1] : null;

        // extract transport proto (e.g., WSS, TCP, UDP)
        const protoMatch = r.Status.match(/Registered\((\w+)-/i);
        const proto = protoMatch ? protoMatch[1].toLowerCase() : null;

        // extract expiry date and convert to unix timestamp
        const expMatch = r.Status.match(/EXP\(([^)]+)\)/);
        let unix = null;
        if (expMatch) {
          const dateStr = expMatch[1];
          const date = new Date(dateStr.replace(" ", "T"));
          unix = Math.floor(date.getTime() / 1000);
        }

        return {
          reg_user: r["Auth-User"],
          realm: r["Agent"],
          token: r["Call-ID"],
          url: sipUri ? `sofia/internal/${sipUri}` : null,
          expires: unix,
          network_ip: r.IP,
          network_port: r.Port,
          network_proto: proto,
          hostname: r.Host,
          metadata: null
        };

      });
      await redisClient.set(`onlineStatus-${subdomain}`, JSON.stringify(result))


      //Online status

      console.log("this is onlineStatus", JSON.stringify(result));
      wallboard.to(subdomain).emit("onlineStatus", JSON.stringify(result));
      emitDevicePresence(subdomain, result).catch((e) => console.log("[device-presence]", e && e.message)); // [device-presence]
  }
  else{
    console.log("no online status")
  }

}

async function blockCallStatus(subdomain) {
  let DateFormat = Date.now().toString().slice(0, -3);
  let time = parseInt(DateFormat) - 86400;
  let data = await db2.query(
    "SELECT caller_id_number, caller_destination, direction, start_epoch, json FROM v_xml_cdr WHERE start_epoch > " +
      time +
      " AND domain_name = '" +
      subdomain +
      "' AND hangup_cause = 'OUTGOING_CALL_BARRED' ORDER BY start_epoch DESC LIMIT 10 "
  );
  if (data.rows.length > 0) {
    data.rows.forEach((element) => {
      if (element.json.extension) {
        
        if (element.json.callflow.extension) {
          if (element.json.callflow.extension.application.length > 0) {
            let attributes = element.json.callflow.extension.application;
            let gateway;
            attributes.forEach((x) => {
              if (x["@attributes"].app_name === "bridge") {
                gateway = x["@attributes"].app_data;
              }
            });
            element.app_gateway = gateway;
            delete element.json;
          }
        }
      }
    });
  }
  wallboard
    .to(subdomain)
    .emit(`blockedStatus`, JSON.stringify({ data: data.rows }));
}

// async function sendFileNewUser(channel, clientPresence, objData){

//      let clientInfo = clientPresence.split('-');

//      // await redisClient.zAdd(`${channel}-conversation-new`, { score: objData.time, value: clientPresence });
//      await redisClient.zAdd(`${channel}-conversation`, { score: objData.time, value: clientPresence });

//      let sendFile = await redisClient.lPush(channel + "-external-" + clientInfo[1], [`${JSON.stringify({filePath: objData.filepath})}`,`${objData.message}`, `${objData.time}`, `${clientInfo[0]}`, `${channel}`, `${objData.uid}`]);

//      if(sendFile){
//           wallboard.to(channel).emit("queue", objData);
//      }

// }

function uniqueId() {
  return Date.now().toString(36) + Math.random().toString(36).substr(2);
}

// async function whatsappConversationList(userName, id, roomID) {
  
//   /** for new chat */
//   await redisClient.zAdd("demo.us1.ringq.ai-conversation-new", {
//     score: Date.now(),
//     value: `${userName}-${id}-whatsapp-` + roomID,
//   });
//   /** end */

//   /** for all chat */
//   await redisClient.zAdd("demo.us1.ringq.ai-conversation", {
//     score: Date.now(),
//     value: `${userName}-${id}-whatsapp-` + roomID,
//   });
//   /** end */
// }

// EMAN ADDED 06-04-25 END //
async function checkGatewayAndOriginate(socket, element, arg, callId, codecs) {
  console.log(
    "originate {domain_name=" +
      socket.handshake.auth.channel +
      ",nolocal:absolute_codec_string=" +
      codecs +
      ",ignore_early_media=false,eavesdrop_enable_dtmf=true,origination_caller_id_name=Conference,origination_caller_id_number=" +
      socket.handshake.auth.extension +
      ",eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/" +
      element.gateway_uuid.trim() +
      "/" +
      arg +
      " &eavesdrop(" +
      callId +
      ")"
  );
  // EMAN ADDED 06-04-25 END //

  // PCMU,G722,PCMA,opus
  return new Promise((resolve) => {
    conn.api(
      "originate {domain_name=" +
        socket.handshake.auth.channel +
        ",OPUS,iLBC,G7221@32k,G722,G729,PCMU,PCMA,inherit_codec=true,inbound-late-negotiation=true,ignore_early_media=false,eavesdrop_enable_dtmf=true,origination_caller_id_name=Conference,origination_caller_id_number=" +
        socket.handshake.auth.extension +
        ",eavesdrop_bridge_aleg=true,eavesdrop_whisper_aleg=true,eavesdrop_bridge_bleg=true,eavesdrop_whisper_bleg=true}sofia/gateway/" +
        element.gateway_uuid.trim() +
        "/" +
        arg +
        " &eavesdrop(" +
        callId +
        ")",
      (res) => {
        
        if (res.body.includes("+OK")) {
          resolve(true); // Resolve with true when successful
        } else {
          resolve(false); // Resolve with false if unsuccessful
        }
      }
    );
  });
}

async function extensionParameters(extension, domain) {
  const queueList = await queueListQueuery(domain);
  queueList.forEach(async (item) => {
    conn.api(
      `json {"command": "callcenter_config", "data": {"arguments":"queue list agents", "queue_name":"${item.queue_extension}@${domain}"}}`,
      (res) => {
        const body = JSON.parse(res.body);
        try {
          redisClient.set(
            `agentlist-${body.data.queue_name}`,
            JSON.stringify(body.response)
          );
        } catch (error) {
          reject(error);
        }
      }
    );
  });

  if (
    classifyNumber(Number(extension)) === 'InternalExtension'
  ) {
    /** Queueu Instance **/
    const queueList = await queueListQueuery(domain);
    queueList.map((x) => {
      db2.query(
        `SELECT * FROM member_board WHERE queue = $1`,
        [`${x.queue_extension}@${domain}`],
        (err, result) => {
          if (result) {
            const rows = result.rows;
            if (!rows.length) {
              db2.query(
                `INSERT INTO member_board (
                  queue, answered_seconds, abandoned_seconds, answered_calls, missed_calls, talk_time,
                  waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11)
                ON CONFLICT (queue) DO NOTHING`,
                [`${x.queue_extension}@${domain}`,0,0,0,0,0,0,0,0,0,0],
                (err) => {
                  if (err) {
                    console.error("Error inserting data:", err);
                  } else {
                    
                  }
                }
              );
            }
          }
        }
      );
    });

    /** Username Instance **/
    db2
      .query(
        "SELECT extension_uuid, effective_caller_id_name FROM v_extensions WHERE extension ='" +
          extension +
          "' AND accountcode = '" +
          domain +
          "'"
      )
      .then(async (res) => {
        if (res.rows.length) {
          let extension_uuid = res.rows[0].extension_uuid;
          let effectiveName = res.rows[0].effective_caller_id_name;
          db2
            .query(
              "SELECT user_uuid FROM v_extension_users WHERE extension_uuid  = '" +
                extension_uuid +
                "'"
            )
            .then((res2) => {
              let user_uuid = res2.rows[0].user_uuid;
              db2
                .query(
                  "SELECT username FROM v_users WHERE user_uuid = '" +
                    user_uuid +
                    "'"
                )
                .then(async (res3) => {
                  let username = res3.rows[0].username;
                  console.log(
                    "Presence1",
                    username,
                    extension,
                    domain,
                    effectiveName
                  );

                  const redisEffectiveName = await redisClient.set(
                    `effectiveName-${extension}@${domain}`,
                    effectiveName
                  );
                  

                  const redisUsername = await redisClient.set(
                    `username-${extension}@${domain}`,
                    username
                  );
                  

                  const redisExtension = await redisClient.set(
                    `extension-${username}@${domain}`,
                    JSON.stringify({
                      extension: extension,
                      effectiveName: effectiveName,
                    })
                  );
                  // 
                });
            });
        }
      });

    /** SIP Contact Instance **/
    db2.query(
      "SELECT url FROM registrations WHERE reg_user = $1 AND realm = $2",
      [extension, domain],
      async (err, result) => {
        if (err) {
          
          return;
        }

        const rows = result.rows;
        const map1 = rows.map((x) => x.url);

        

        map1.map(async (y) => {
          const sip = y.split(";")[0].split("sip:")[1];
          await redisClient.set(`contactSIP-${sip}`, `${extension}@${domain}`);
          
        });
      }
    );
  }
}

async function presenceValidator(presence_id, presenceNumber) {
  const [extension, context] = presence_id.split("@");
  const isPureNumber = /^\d+$/.test(extension);
  if (isPureNumber && presence_id === presenceNumber) {
    return true;
  } else {
    const extensionNotPure = await redisClient.get(`extension-${presence_id}`);
    if (extensionNotPure) {
      const { extension } = JSON.parse(extensionNotPure);
      if (`${extension}@${context}` === presenceNumber) {
        return true;
      }
    }
  }
}

async function showCalls(subdomain) {
  await new Promise((resolve) => setTimeout(resolve, 1500));

  const res = await new Promise((resolve) => {
    conn.api(`show calls as json`, resolve);
  });

  const data = JSON.parse(res.body);

  if (data.row_count > 0) {
    for (const [index, element] of data.rows.entries()) {
      if (classifyNumber(Number(element.dest)) === 'ExternalNumber') {
        const query = `
                  SELECT did.did_number, did.extension, domain.domain_name 
                  FROM q_dids AS did 
                  LEFT JOIN v_domains AS domain 
                  ON did.domain_uuid = domain.domain_uuid 
                  WHERE did.did_number = $1
              `;
        const data2 = await db2.query(query, [element.dest]);

        if (data2.rows.length > 0) {
          const domainName = data2.rows[0].domain_name;
          data.rows[index].dest = data2.rows[0].extension;

          
          return { data: JSON.stringify(data), subdomain: domainName };
        } else {
          return { data: res.body, subdomain };
        }
      }
    }
    return { data: res.body, subdomain };
  } else {
    return { data: res.body, subdomain };
  }
}

async function conferenceExternalInvite(key2, subdomain, arg, socket) {
  if (key2.direction == "inbound") {
    conn.api(`uuid_setvar ${key2.b_uuid} conference_key ${key2.b_uuid}`);
    conn.api(
      `uuid_setvar ${key2.b_uuid} conference_destination ${arg}@${subdomain}`
    );

    let callId = key2.b_uuid;
    

    function gateway_uuid() {
      return new Promise((resolve, reject) => {
        conn.api(`uuid_dump ${callId} JSON`, function (res) {
          resolve(res.body);
        });
      });
    }
    gateway_uuid()
      .then(async (data) => {
        let objData = JSON.parse(data);

        // EMAN ADDED 06-03-25 START //

        let gateway_uuid = "";
        const { rows: qOutboundRules } = await db2.query(
          "SELECT * FROM q_outbound_rules WHERE domain_uuid = $1 ORDER BY dial_plan_order ASC",
          [objData.variable_domain_uuid]
        );

        let destinationNumber = arg;
        

        for (const qOutboundRule of qOutboundRules) {
          const startsWith = destinationNumber.startsWith(
            qOutboundRule.prefix_pattern
          );
          const lengthMatches =
            destinationNumber.length == qOutboundRule.calls_number_length ||
            qOutboundRule.calls_number_length === 0;

          if (startsWith && lengthMatches) {
            let transformedNumber = destinationNumber;

            if (qOutboundRule.strip) {
              transformedNumber = transformedNumber.slice(qOutboundRule.strip);
            }

            if (qOutboundRule.prepend) {
              transformedNumber = qOutboundRule.prepend + transformedNumber;
            }

            let action = qOutboundRule.action.toLowerCase();
            let codecs = qOutboundRule.codec_prefs;

            const codecValues = {
              // OPUS: "OPUS",
              ILBC: "iLBC",
              G7221: "G7221@32k",
              G722: "G722",
              G729: "G729",
              PCMU: "PCMU",
              PCMA: "PCMA",
            };
            if (codecs !== null && codecs !== undefined) {
              const codecValues2 = [];
              const codecs = vGateway.codec_prefs.split(",");
              codecs.forEach((codec) => {
                const upperCodec = codec.trim().toUpperCase();
                if (codecValues.hasOwnProperty(upperCodec)) {
                  codecValues2.push(codecValues[upperCodec]);
                }
              });
              codecs = codecValues2.join(",");
            } else {
              codecs =
                "iLBC,G7221@32k,G722,G729,PCMU,PCMA"
            }

            const { rows: qGateway2 } = await db2.query(
              "SELECT * FROM v_gateways WHERE domain_uuid = $1 AND gateway = $2",
              [objData.variable_domain_uuid, action]
            );
            

            if (qGateway2) {
              gateway_uuid = qGateway2[0].gateway_uuid;
              destinationNumber = transformedNumber;

              let success = await checkGatewayAndOriginate(
                socket,
                qGateway2[0],
                destinationNumber,
                callId,
                codecs
              );
              if (success) {
                console.log(
                  "Successful gateway found. Stopping further attempts."
                );
              }
            }

            break;
          }
        }
        // EMAN ADDED 06-03-25 END //
        // EMAN COMMENT 06-02-25 START //

        // let gateway = await pool.query('SELECT domain_uuid, gateway_uuid, gateway FROM v_gateways WHERE domain_uuid = $1',[objData.variable_domain_uuid]);

        // if(gateway.rows.length > 0){

        //      

        //           for (const element of gateway.rows) {
        //                let success = await checkGatewayAndOriginate(socket, element, arg, callId);

        //                if (success) {
        //                    
        //                    break;
        //                }
        //           }

        // }

        // EMAN COMMENT 06-02-25 END //
      })
      .catch((err) => {
        console.error("Error:", err);
      });
  }

  if (key2.direction == "outbound") {
    if (key2.uuid) {
      conn.api(`uuid_dump ${key2.uuid} JSON`, function (res) {
        
      });
    }

    
    let callId = key2.call_uuid;

    function gateway_uuid() {
      return new Promise((resolve, reject) => {
        conn.api(`uuid_dump ${callId} JSON`, function (res) {
          resolve(res.body);
        });
      });
    }

    gateway_uuid()
      .then(async (data) => {
        let objData = JSON.parse(data);
        

        // EMAN ADDED 06-03-25 START //

        let gateway_uuid = "";
        const { rows: qOutboundRules } = await db2.query(
          "SELECT * FROM q_outbound_rules WHERE domain_uuid = $1 ORDER BY dial_plan_order ASC",
          [objData.variable_domain_uuid]
        );

        let destinationNumber = arg;
        for (const qOutboundRule of qOutboundRules) {
          const startsWith = destinationNumber.startsWith(
            qOutboundRule.prefix_pattern
          );
          const lengthMatches =
            destinationNumber.length == qOutboundRule.calls_number_length ||
            qOutboundRule.calls_number_length === 0;

          if (startsWith && lengthMatches) {
            let transformedNumber = destinationNumber;

            if (qOutboundRule.strip) {
              transformedNumber = transformedNumber.slice(qOutboundRule.strip);
            }

            if (qOutboundRule.prepend) {
              transformedNumber = qOutboundRule.prepend + transformedNumber;
            }

            const action = qOutboundRule.action.toLowerCase();
            let codecs = qOutboundRule.codec_prefs;

            const codecValues = {
              // OPUS: "OPUS",
              ILBC: "iLBC",
              G7221: "G7221@32k",
              G722: "G722",
              G729: "G729",
              PCMU: "PCMU",
              PCMA: "PCMA",
            };
            if (codecs !== null && codecs !== undefined) {
              const codecValues2 = [];
              const codecs = vGateway.codec_prefs.split(",");
              codecs.forEach((codec) => {
                const upperCodec = codec.trim().toUpperCase();
                if (codecValues.hasOwnProperty(upperCodec)) {
                  codecValues2.push(codecValues[upperCodec]);
                }
              });
              codecs = codecValues2.join(",");
            } else {
              codecs =
                "iLBC,G7221@32k,G722,G729,PCMU,PCMA";
            }

            const { rows: qGateway2 } = await db2.query(
              "SELECT * FROM v_gateways WHERE domain_uuid = $1 AND gateway = $2",
              [objData.variable_domain_uuid, action]
            );

            if (qGateway2) {
              gateway_uuid = qGateway2[0].gateway_uuid;
              destinationNumber = transformedNumber;

              let success = await checkGatewayAndOriginate(
                socket,
                qGateway2[0],
                destinationNumber,
                objData.variable_current_application_data.trim(),
                codecs
              );
              if (success) {
                console.log(
                  "Successful gateway found. Stopping further attempts."
                );
              }
            }

            
            

            break;
          }
        }
        // EMAN ADDED 06-03-25 END //
        // EMAN COMMENT 06-02-25 START //

        // let gateway = await pool.query('SELECT domain_uuid, gateway_uuid, gateway FROM v_gateways WHERE domain_uuid = $1',[objData.variable_domain_uuid.trim()]);

        // if(gateway.rows.length > 0){

        //      

        //           for (const element of gateway.rows) {

        //                conn.api(`uuid_setvar ${objData.variable_current_application_data.trim()} conference_key ${objData.variable_current_application_data.trim()}`);

        //                conn.api(`uuid_setvar ${objData.variable_current_application_data.trim()} conference_destination ${arg}@${subdomain}`);

        //                let success = await checkGatewayAndOriginate(socket, element, arg, objData.variable_current_application_data.trim());

        //                if (success) {
        //                    
        //                    break;
        //                }

        //           }

        // }
        // EMAN COMMENT 06-02-25 END //
      })
      .catch((err) => {
        console.error("Error:", err);
      });
  }
}

const promisifiedApi = (conn2, command) => {
  return new Promise((resolve, reject) => {
    conn2.api(command, (res) => {
      
      if (res.error) reject(res.error);
      else resolve(res);
    });
  });
};

async function getUniqueCalls(calls) {
  const seen = new Set();
  const uniqueCalls = [];
  for (const call of calls) {
    const key = `${call.cid_num}-${call.dest}`;
    if (!seen.has(key)) {
      seen.add(key);
      uniqueCalls.push(call);
    }
  }
  return uniqueCalls;
}

const deviceWebToken = async (auth) => {
  const { device_uuid, extension, channel, pushToken } = auth;
  if (pushToken && pushToken.length >= 64) {
    await redisClient.sAdd(`deviceWebToken-${extension}@${channel}`, pushToken);
    await redisClient.sAdd(`deviceOnWeb-${extension}@${channel}`, pushToken);
    await redisClient.sRem(`deviceOffWeb-${extension}@${channel}`, pushToken);
    //09-03-26
    await redisClient.set(`currentPushWebToken-${extension}@${channel}`, pushToken);
    ///09-03-26
  }
};

async function callLogs() {
  const phpProcess = spawn("php", ["script.php"]);
  phpProcess.stdout.on("data", (data) => {
    
  });

  phpProcess.stderr.on("data", (data) => {
    console.error(`Error: ${data}`);
  });

  phpProcess.on("close", (code) => {
    
  });
}

function apiCommandReloadXML(conn, command, args = "") {
  return new Promise((resolve, reject) => {
    conn.api(command, args, (res) => {
      if (res) {
        resolve(res.getBody());
      } else {
        reject(new Error("No response from API"));
      }
    });
  });
}

(async () => {
  const scheduledCrons = new Set();
  db2.query("SELECT domain_name FROM v_domains", (err, result) => {
    if (err) {
      console.error("Query error:", err);
      return;
    }

    const rows = result.rows;
    if (!Array.isArray(rows) || rows.length === 0) {
      console.warn("No subdomains found.");
      return;
    }

    rows.forEach(({ domain_name: subdomain }) => {
      if (scheduledCrons.has(subdomain)) return;
      scheduledCrons.add(subdomain);
      cron.schedule("0 0 * * *", () => resetSubdomainData(subdomain), {
        timezone: "Asia/Manila",
      });
    });
    
  });
})();

async function resetSubdomainData(subdomain) {
  try {

      console.log('SUBDOMAIN', subdomain);

      await Promise.all([
        redisClient.hSet(`walldata:${subdomain}`, {
          total_wallboard: 0,
          answered_wallboard: 0,
          waiting_wallboard: 0,
          abandoned_wallboard: 0,
          missed_calls: 0,
          answered_calls: 0,
          total: 0, 
          active: 0, 
          waiting: 0, 
          abandoned: 0
        }),
        redisClient.del(`queueCallBoard-${subdomain}`),
      ]);

      const wallboardReset = await redisClient.hGetAll(
        `walldata:${subdomain}`
      );
      wallboard.to(subdomain).emit("wallboard", wallboardReset);

      redisClient.set(
        `${subdomain}:queueWallboardCallStatus`,
        JSON.stringify([])
      );
      wallboard.to(subdomain).emit("queueCallboard", JSON.stringify([]));

      /** Agent Wallboard */

      wallboard.to(subdomain).emit(`${subdomain}:queueWallboardAgentBoard2`, JSON.stringify([]));

      await new Promise((resolve, reject) => {
        console.log(`👉🏻`);
        db2.query(
          `UPDATE agents 
          SET last_bridge_start = $1, 
          last_bridge_end = $2,
          last_offered_call = $3,
          last_status_change = $4,
          no_answer_count = $5,
          calls_answered = $6,
          talk_time = $7,
          ready_time = $8,
          state = $9
          WHERE contact LIKE $10`,
          [0, 0, 0, 0, 0, 0, 0, 0, 'Waiting', `%${subdomain}%`],
          (err) => {
            if (err) {
              console.error("Error updating agent stats:", err.message);
              return reject(err);
            }
          }
        );

        db2.query(
          `UPDATE agent_details 
          SET no_answer_count = $1, 
          calls_answered = $2,
          talk_time = $3,
          state = $4
          WHERE contact LIKE $5`,
          [0, 0, 0, 'Waiting', `%${subdomain}%`],
          (err, result) => {
            console.log(`👉🏻`, result);
            if (err) {
              console.error("Error updating agent stats:", err.message);
              return reject(err);
            }
            resolve(result);
          }
        );
      })
      .then( async () => {

        const [agentBody, tierBody] = await Promise.all([
          new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"agent list"}}`, resolve)),
          new Promise(resolve => conn.api(`json {"command": "callcenter_config", "data": {"arguments":"tier list"}}`, resolve))
        ]);

        const agentList = JSON.parse(agentBody.body).response;
        const tierList  = JSON.parse(tierBody.body).response;
        const agentMap = agentList.reduce((acc, a) => (acc[a.name] = a, acc), {});

        const matched = tierList.reduce((acc, tier) => {
            const agent = agentMap[tier.agent];
            if (
                agent && 
                agent.contact.includes(subdomain)
            ) acc.push(agent);
            return acc;
        }, []);
        console.log("✅ matched:", matched);

        if(matched.length){

            const names = matched.map(e => e.name);
            const { rows } = await db2.query(
                `SELECT 
                    ad.name,
                    ad.contact,
                    ad.max_no_answer,
                    ad.wrap_up_time,
                    ad.reject_delay_time,
                    ad.busy_delay_time,
                    ad.no_answer_delay_time,
                    ad.last_bridge_start,
                    ad.last_bridge_end,
                    ad.last_offered_call,
                    ad.last_status_change,
                    ad.no_answer_count,
                    ad.calls_answered, 
                    ad.talk_time,
                    ad.ready_time,
                    ad.external_calls_count,
                    ve.extension
                FROM agent_details ad
                LEFT JOIN v_extensions ve 
                    ON ve.number_alias = SPLIT_PART(SPLIT_PART(ad.contact, 'user/', 2), '@', 1)
                WHERE ad.name = ANY($1)`,
                [names]
            );

            // Map results by name for O(1) lookup
            const dbMap = rows.reduce((acc, row) => {
                acc[row.name] = row;
                return acc;
            }, {});

            const newPayload = matched.map(element => {
                const data = dbMap[element.name];
                if(data){
                  const { 
                    contact,
                    max_no_answer,
                    wrap_up_time,
                    reject_delay_time,
                    busy_delay_time,
                    no_answer_delay_time,
                    last_bridge_start,
                    last_bridge_end,
                    last_offered_call,
                    last_status_change,
                    no_answer_count,
                    calls_answered, 
                    talk_time,
                    ready_time,
                    external_calls_count,
                    extension 
                  } = data;
                  const newContact = extension ? updateContact(contact, extension) : contact;
                  return {
                      ...element,
                      contact: newContact,
                      max_no_answer: Number(max_no_answer),
                      wrap_up_time: Number(wrap_up_time),
                      reject_delay_time: Number(reject_delay_time),
                      busy_delay_time: Number(busy_delay_time),
                      no_answer_delay_time: Number(no_answer_delay_time),
                      last_bridge_start: Number(last_bridge_start),
                      last_bridge_end: Number(last_bridge_end),
                      last_offered_call: Number(last_offered_call),
                      last_status_change: Number(last_status_change),
                      no_answer_count: Number(no_answer_count),
                      calls_answered: Number(calls_answered),
                      talk_time: Number(talk_time),
                      ready_time: Number(ready_time),
                      external_calls_count: Number(external_calls_count)
                  };
                }

            });
            
            const filteredAgent = newPayload.filter(Boolean);
            console.log('👉🏻👉🏻', JSON.stringify(filteredAgent));
            await redisClient.set(
              `${subdomain}:queueWallboardAgentBoard2`,
              JSON.stringify(filteredAgent)
            );
            wallboard
            .to(subdomain)
            .emit("queueAgentboard2", JSON.stringify(filteredAgent));
          
        }

      })
      .catch((err) => {
        console.error("❌ Failed to reset agent wallboard:", err.message);
      });

      /** Queue Details */
      await new Promise((resolve, reject) => {
        db2.query(
          `UPDATE member_board 
              SET answered_seconds = $1, 
              abandoned_seconds = $2, 
              answered_calls = $3, 
              missed_calls = $4, 
              talk_time = $5, 
              abandoned_wallboard = $6,
              total_wallboard = $7, 
              answered_wallboard = $8, 
              waiting_wallboard = $9,
              waiting_seconds = $10 
              WHERE queue LIKE $11`,
          [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, `%${subdomain}%`],
          (err, result) => {
            if (err) {
              console.error(
                "Error resetting queue details stats:",
                err.message
              );
              return reject(err);
            }
            console.log(
              `✅ Queue Details reset: ${result.rowCount} row(s) updated`
            );
            resolve(result);
          }
        );
      })
      .then((value) => {

        conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, async ({ body }) => {
            const { status, response } = JSON.parse(body);
            console.log(`STATUS CALLS`, response);
            if (status === "success") {
              if (response.length) {
                const names = response
                .filter(e => e.name.includes(subdomain))
                .map(e => e.name);

                if (!names.length) return;
                db2.query(
                  `SELECT queue, answered_seconds, abandoned_seconds, talk_time, answered_calls,
                  missed_calls, waiting_wallboard, answered_wallboard, abandoned_wallboard, total_wallboard, waiting_seconds
                  FROM member_board WHERE queue = ANY($1)`,
                  [names],
                  async (err, result) => {
                    if (err) {
                      console.error("Error fetching data:", err.message);
                      return;
                    }
                    const rows = result.rows;
                    await redisClient.set(
                      `${subdomain}:queueWallboardConfigBoard`,
                      JSON.stringify(rows)
                    );
                    wallboard
                    .to(subdomain)
                    .emit("queueConfigBoard", JSON.stringify(rows));
                  }
                );

              }
            }
        });
        
      })
      .catch((err) => {
        console.error(
          "❌ Failed to reset queue details wallboard:",
          err.message
        );
      }).finally(() => {
          conn.api(`json {"command": "callcenter_config", "format": "pretty", "data": {"arguments":"queue list"}}`, ({ body }) => {
              const { status, response} = JSON.parse(body)
              if(status === 'success'){
                  const agentNames = response.filter(el => el.name.includes(subdomain))
                  agentNames.forEach( ({name}) => {
                      conn.api(`callcenter_config queue reload ${name}`);
                      console.log('This is called finally reset queue2', name);
                  });
              }     
          });
      })
    
  } catch (error) {
    console.error(`❌ Error resetting subdomain ${subdomain}:`, error);
  }
}

const deviceUniqueId = async (param) => {
  const { extension, channel, pushToken, device_uuid, deviceId } = param;
  if (device_uuid) {
    await redisClient.set(
      `DeviceId-${extension}@${channel}-${pushToken}`,
      device_uuid
    );
    return;
  }
  if (deviceId) {
    await redisClient.set(
      `DeviceId-${extension}@${channel}-${pushToken}`,
      deviceId
    );
    return;
  }
};

function wait(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function updateContact(contact, extension) {
  return contact.replace(/user\/[^@]+@/, `user/${extension}@`);
}

async function teamDirectoryEvent(subdomain) {
    await new Promise(resolve => setTimeout(resolve, 15000));

    const res = await apiPromise("show calls as json");
    if(res.body){
      const showCalls = JSON.parse(res.body);
      if (showCalls.row_count){

        const newEventProm = Promise.all(
          showCalls.rows.map(async (res) => {
            
            let updatedRes = { ...res };
            if (res.callstate === "ACTIVE") {
              //              
              if(!res.name.includes("internal") && !res.b_name.includes("internal")){

                  if(res.direction === 'inbound'){

                    const name = await sipProfileURL(res.name);
                    const extensionNotPureFrom = await redisClient.get(
                    `contactSIP-${name}`
                    );
                    if (extensionNotPureFrom) {
                        const [extension] = extensionNotPureFrom.split("@");
                        updatedRes.cid_num = extension;
                    }else{
                        const extensionNum = name.split('@')[0]
                        updatedRes.cid_num = extensionNum;
                    }

                  }else if(res.direction === 'outbound'){

                    const name = await sipProfileURL(res.name);
                    const extensionNotPureFrom = await redisClient.get(
                    `contactSIP-${name}`
                    );
                    if (extensionNotPureFrom) {
                        const [extension] = extensionNotPureFrom.split("@");
                        updatedRes.cid_num = extension;
                    }else{
                        const extensionNum = name.split('@')[0]
                        updatedRes.cid_num = extensionNum;
                    }

                  }
                  
                  if(res.b_direction === 'outbound'){

                      const name2 = await sipProfileURL(res.b_name);
                      const extensionNotPureFrom2 = await redisClient.get(
                      `contactSIP-${name2}`
                      );
                      if(extensionNotPureFrom2){
                        const [extension] = extensionNotPureFrom2.split("@");
                        updatedRes.dest = extension;
                      }else{
                        const extensionNum = name2.split('@')[0]
                        updatedRes.dest = extensionNum;
                      }

                  }else if(res.b_direction === 'inbound'){

                      const name2 = sipProfileURL(res.b_name);
                      const extensionNotPureFrom2 = await redisClient.get(
                      `contactSIP-${name2}`
                      );
                      if(extensionNotPureFrom2){

                        const [extension] = extensionNotPureFrom2.split("@");
                        updatedRes.dest = extension;

                      }else{
                        const extensionNum = name2.split('@')[0]
                        updatedRes.dest = extensionNum;
                      }

                  }

              }

              if(res.name.includes("internal") && res.b_name.includes("internal")){

                    if(res.direction === 'inbound' && res.name){

                      console.log('ERROR NEW:', res)

                      const name = await sipProfileURL(res.name);
                      const extensionNotPureFrom = await redisClient.get(
                      `contactSIP-${name}`
                      );
                      if (extensionNotPureFrom) {
                          const [extension] = extensionNotPureFrom.split("@");
                          updatedRes.cid_num = extension;
                      }else{
                          const extensionNum = name.split('@')[0]
                          updatedRes.cid_num = extensionNum;
                      }

                    }else if(res.direction === 'outbound' && res.name){

                      const name = await sipProfileURL(res.name);
                      const extensionNotPureFrom = await redisClient.get(
                      `contactSIP-${name}`
                      );
                      if (extensionNotPureFrom) {
                          const [extension] = extensionNotPureFrom.split("@");
                          updatedRes.cid_num = extension;
                      }else{
                          const extensionNum = name.split('@')[0]
                          updatedRes.cid_num = extensionNum;
                      }

                    }

                    if(res.b_direction === 'outbound' && res.b_name){
                        const name2 = await sipProfileURL(res.b_name);
                        const extensionNotPureFrom2 = await redisClient.get(
                        `contactSIP-${name2}`
                        );
                        if(extensionNotPureFrom2){
                          const [extension] = extensionNotPureFrom2.split("@");
                          updatedRes.dest = extension;
                        }else{
                            const extensionNum = name2.split('@')[0]
                            updatedRes.dest = extensionNum;
                        }
                    }else if(res.b_direction === 'inbound' && res.b_name){
                        const name2 = await sipProfileURL(res.b_name);
                        const extensionNotPureFrom2 = await redisClient.get(
                        `contactSIP-${name2}`
                        );
                        if(extensionNotPureFrom2){
                          const [extension] = extensionNotPureFrom2.split("@");
                          updatedRes.dest = extension;
                        }else{
                            const extensionNum = name2.split('@')[0]
                            updatedRes.dest = extensionNum;
                        }
                    }
                    
              }

              if(!res.name.includes("internal") && res.b_name.includes("internal")){

                    if(res.direction === 'inbound'){
                      const name = await sipProfileURL(res.name);
                      const extensionNotPureFrom = await redisClient.get(
                      `contactSIP-${name}`
                      );
                      if (extensionNotPureFrom) {
                          const [extension] = extensionNotPureFrom.split("@");
                          updatedRes.cid_num = extension;
                      }else{
                          const extensionNum = name.split('@')[0]
                          updatedRes.cid_num = extensionNum;
                      }
                    }else if(res.direction === 'outbound'){
                      const name = await sipProfileURL(res.name);
                      const extensionNotPureFrom = await redisClient.get(
                      `contactSIP-${name}`
                      );
                      if (extensionNotPureFrom) {
                          const [extension] = extensionNotPureFrom.split("@");
                          updatedRes.cid_num = extension;
                      }else{
                          const extensionNum = name.split('@')[0]
                          updatedRes.cid_num = extensionNum;
                      }
                    }

                    if(res.b_direction === 'outbound'){

                      const name2 = await sipProfileURL(res.b_name);
                      if(name2){
                        const extensionNotPureFrom2 = await redisClient.get(
                        `contactSIP-${name2}`
                        );
                        if(extensionNotPureFrom2){
                          const [extension] = extensionNotPureFrom2.split("@");
                          updatedRes.dest = extension;
                        }else{
                            const extensionNum = name2.split('@')[0]
                            updatedRes.dest = extensionNum;
                        }
                      }
                     
                    }else if(res.b_direction === 'inbound'){

                      const name2 = await sipProfileURL(res.b_name);
                      if(name2){
                        const extensionNotPureFrom2 = await redisClient.get(
                        `contactSIP-${name2}`
                        );
                        if(extensionNotPureFrom2){
                          const [extension] = extensionNotPureFrom2.split("@");
                          updatedRes.dest = extension;
                        }else{
                            const extensionNum = name2.split('@')[0]
                            updatedRes.dest = extensionNum;
                        }
                      }
                      
                    }
                    
              }

              if(res.name.includes("internal") && !res.b_name.includes("internal")){

                    if(res.direction === 'inbound'){

                      const name = await sipProfileURL(res.name);
                      const extensionNotPureFrom = await redisClient.get(
                      `contactSIP-${name}`
                      );
                      if (extensionNotPureFrom) {
                          const [extension] = extensionNotPureFrom.split("@");
                          updatedRes.cid_num = extension;
                      }else{
                          const extensionNum = name.split('@')[0]
                          updatedRes.cid_num = extensionNum;
                      }

                    }else if(res.direction === 'outbound'){

                      const name = await sipProfileURL(res.name);
                      const extensionNotPureFrom = await redisClient.get(
                      `contactSIP-${name}`
                      );
                      if (extensionNotPureFrom) {
                          const [extension] = extensionNotPureFrom.split("@");
                          updatedRes.cid_num = extension;
                      }else{
                          const extensionNum = name.split('@')[0]
                          updatedRes.cid_num = extensionNum;
                      }

                    }

                    if(res.b_direction === 'outbound'){

                      const name2 = await sipProfileURL(res.b_name);
                      const extensionNotPureFrom2 = await redisClient.get(
                      `contactSIP-${name2}`
                      );
                      if(extensionNotPureFrom2){
                        const [extension] = extensionNotPureFrom2.split("@");
                        updatedRes.dest = extension;
                      }else{
                          const extensionNum = name2.split('@')[0]
                          updatedRes.dest = extensionNum;
                      }

                    }else if(res.b_direction === 'inbound'){

                      const name2 = await sipProfileURL(res.b_name);
                      const extensionNotPureFrom2 = await redisClient.get(
                      `contactSIP-${name2}`
                      );
                      if(extensionNotPureFrom2){
                        const [extension] = extensionNotPureFrom2.split("@");
                        updatedRes.dest = extension;
                      }else{
                          const extensionNum = name2.split('@')[0]
                          updatedRes.dest = extensionNum;
                      }

                    }

              }

            } else {
              // NON-ACTIVE CASES

              // CASE 5: Update `presence_id`
              if (res.presence_id) {

                const [agentExt] = res.presence_id.split("@");
                const isPureNumber = /^\d+$/.test(agentExt);

                if (!isPureNumber) {
                  try {
                    const extensionNotPure = await redisClient.get(
                      `extension-${res.presence_id}`
                    );
                    if (extensionNotPure) {
                      const { extension } = JSON.parse(extensionNotPure);
                      updatedRes.presence_id = `${extension}@${subdomain}`;
                    }
                  } catch (error) {
                    console.error("Error fetching extension:", error);
                  }
                }
              }
              // CASE 6: Update `dest`
              const subDomain = res.name.split("@")[1];
              try {
                const extensionNotPure = await redisClient.get(
                  `contactSIP-${res.dest}@${subDomain}`
                );
                if (extensionNotPure) {
                  const [extension] = extensionNotPure.split("@");
                  updatedRes.dest = extension;
                }

                const extensionNotPure2 = await redisClient.get(
                  `contactSIP-${res.cid_num}@${subDomain}`
                );
                if (extensionNotPure2) {
                  const [extension] = extensionNotPure2.split("@");
                  updatedRes.cid_num = extension;
                }
              }catch (error) {
                console.error("Error fetching contactSIP:", error);
              }

            }

            if(res.dest === 'park'){
              


              
              const result = await promisifiedApi(conn, `uuid_dump ${res.uuid} JSON`);
              const data = JSON.parse(result.body);
              
              if(data['Call-Direction'] === 'inbound'){
                if(data['variable_sip_from_uri'] && data['variable_sip_to_uri']){

                  const splitFrom = async() => {
                    if(data['variable_sip_from_uri'].includes(':')){
                      return data['variable_sip_from_uri'].split(':')[0]
                    }
                    return data['variable_sip_from_uri'];
                  }

                  const splitTo = async() => {
                    if(data['variable_sip_to_uri'].includes(':')){
                      return data['variable_sip_to_uri'].split(':')[0]
                    }
                    return data['variable_sip_to_uri'];
                  }
                  
                  const extensionNotPureFrom = await redisClient.get(
                  `contactSIP-${await splitFrom()}`
                  );

                  if (extensionNotPureFrom) {
                    const [extension] = extensionNotPureFrom.split("@");
                    updatedRes.cid_num = extension;
                  }else{
                    const fromVal = await splitFrom();
                    updatedRes.cid_num = fromVal.split('@')[0];
                  }

                  const extensionNotPureTo = await redisClient.get(
                  `contactSIP-${await splitTo()}`
                  );
                  if (extensionNotPureTo) {
                    const [extension] = extensionNotPureTo.split("@");
                    updatedRes.dest = extension;
                  }else{
                    const toVal = await splitTo()
                    updatedRes.dest = toVal.split('@')[0];
                  }

                }
              }

              if(data['Call-Direction'] === 'outbound'){
                if(data['variable_sip_from_uri'] && data['variable_sip_to_uri']){

                  const from = data['variable_sip_from_uri'];
                  const to = data['variable_sip_to_uri'];

                  const extensionNotPureFrom = await redisClient.get(
                  `contactSIP-${from}`
                  );
                  if (extensionNotPureFrom) {
                    const [extension] = extensionNotPureFrom.split("@");
                    updatedRes.cid_num = extension;
                  }else{
                    updatedRes.cid_num = from.split('@')[0];
                  }

                  const extensionNotPureTo = await redisClient.get(
                  `contactSIP-${to}`
                  );
                  if (extensionNotPureTo) {
                    const [extension] = extensionNotPureTo.split("@");
                    updatedRes.dest = extension;
                  }else{
                    updatedRes.dest = to.split('@')[0];
                  }

                }
              }

            }
            return updatedRes;
          })
        );
        newEventProm
        .then(async (newEvent) => {

          const callStatusData = await Promise.all(
            newEvent.map((element) => {
              return new Promise((resolve, reject) => {
                conn.api(`uuid_dump ${element.uuid} JSON`, (res) => {
                  try {
                    if (res.body !== "-ERR No such channel!\n") {
                      // 
                      const {
                        variable_effective_outbound_caller_id_name:
                          outboundNum,
                        variable_domain_name,
                      } = JSON.parse(res.body);

                      resolve({
                        ...element,
                        caller_id:
                          variable_domain_name === subdomain
                            ? outboundNum || ""
                            : "",
                      });
                    }
                  } catch (error) {
                    console.error("Error parsing response:", error);
                    resolve({ ...element, caller_id: "" });
                  }
                });
              });
            })
          );

          const trimmedCallStatusData = callStatusData.slice(0, 9);
          
          const emitShowCalls = JSON.stringify({
            row_count: trimmedCallStatusData.length,
            rows: trimmedCallStatusData,
          });
          console.log("This is called filteredEvent Original", {
            data: trimmedCallStatusData,
            subDomain: subdomain,
          });

          setTimeout(() => {
            // wallboard.to(subdomain).emit("ringProgress", emitShowCalls);
          }, 5000)

        })
        .catch((error) => {
          console.error("Error processing events:", error);
        });

      }else{
        // wallboard.to(subdomain).emit("ringProgress", res.body);
      }
    }
}

function classifyNumber(num) {
  const rule = extensionRules.find(r => num >= r.min && num <= r.max);
  return rule ? rule.type : 'InternalExtension';
}

function getRegistrationInternalReg() {
    return new Promise((resolve, reject) => {
          conn.api(`sofia status profile internal reg`, (reg) => {
              try {
                    function parseLog(log) {
                        const blocks = log.trim().split(/\n\s*\n/); // split by empty line
                        const registrations = [];
                        let total = 0;

                        for (const block of blocks) {
                        if (block.startsWith("Total items")) {
                              total = parseInt(block.replace(/\D+/g, ""), 10);
                              continue;
                        }

                        const entry = {};
                        block.split("\n").forEach(line => {
                              const match = line.match(/^(.*?):\s*\t?(.*)$/);
                              if (match) {
                                  const key = match[1].trim();
                                  const value = match[2].trim();
                                  entry[key] = value;
                              }
                        });
                        registrations.push(entry);
                        }

                        return { Registrations: registrations, Total: total };
                    }

                    const result = parseLog(reg.body);
                    if (result) {
                        resolve(result.Registrations); // return the match properly
                    } else {
                        resolve(null); // no match found
                    }

              } catch (err) {
                    reject(err);
              }
          });
    });
}




function fsRegistration(){
  return new Promise((resolve, reject) => {
     conn.api(`sofia status profile internal reg`, (reg) => {
     try {
               function parseLog(log) {
               const blocks = log.trim().split(/\n\s*\n/); // split by empty line
               const registrations = [];
               let total = 0;

               for (const block of blocks) {
               if (block.startsWith("Total items")) {
                         total = parseInt(block.replace(/\D+/g, ""), 10);
                         continue;
               }

               const entry = {};
               block.split("\n").forEach(line => {
                         const match = line.match(/^(.*?):\s*\t?(.*)$/);
                         if (match) {
                         const key = match[1].trim();
                         const value = match[2].trim();
                         entry[key] = value;
                         }
               });
               registrations.push(entry);
               }

               return { Registrations: registrations, Total: total };
               }

               const result = parseLog(reg.body);
               if (result) {
               resolve(result.Registrations); // return the match properly
               } else {
               resolve(null); // no match found
               }

     } catch (err) {
               reject(err);
     }
     });
  });
}

function apiPromise(command){
      return new Promise((resolve, reject) => {
            conn.api(command, (res) => {
                if (res) resolve(res);
                else reject(new Error("API returned no result"));
            });
      });
}

const presenceId = async (presence) => {
  const result = presence.match(/\/([^/@]+)@/)?.[1];
  const result2 = presence.match(/sofia\/\w+\/(\+?\d+)/)?.[1];
  const dest = result || result2;
  return dest;
};

const sipProfileURL = async (presence) => {
  if (!presence || typeof presence !== 'string') return null;
  const match = presence.match(/\/([^/]+)$/);
  return match ? match[1] : null;
};



