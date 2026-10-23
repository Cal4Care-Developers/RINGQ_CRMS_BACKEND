// [turn] Short-lived TURN relay credentials for signed-in apps.
//
//   GET https://<host>:8443/api/turn   (nginx :8443 -> 127.0.0.1:TURN_API_PORT)
//
// Signed in means what it means everywhere else on :8443: the request's RINGQ session cookies are
// checked by the PHP app itself (GET /api/sipcredentials over loopback), so a signed-out or
// replaced session gets the same 401 it gets from any other API call. Only the extension is taken
// from that answer; the SIP password in it is never kept or returned.
//
// Credentials use the TURN REST scheme coturn checks with use-auth-secret:
//   username   = "<unix expiry>:<extension>"
//   credential = base64(HMAC-SHA1(TURN_SECRET, username))
// TURN_SECRET, TURN_HOST, TURN_PORT and TURNS_PORT come from /etc/ringq/.env (written by
// app/scripts/turn/ringq-turn-setup.sh). Without TURN_SECRET the route answers 503.

const http = require("http");
const https = require("https");
const crypto = require("crypto");

const TTL = 12 * 60 * 60;
const CHECK_TIMEOUT = 8000;

function reply(res, status, body, extraHeaders) {
  res.writeHead(status, Object.assign({
    "Content-Type": "application/json",
    "Cache-Control": "no-store",
  }, extraHeaders || {}));
  res.end(JSON.stringify(body));
}

// Asks PHP whether the caller's cookies are a signed-in session; resolves
// { extension, setCookie } or { status } when they are not.
function checkSession(req) {
  return new Promise((resolve) => {
    const headers = { Accept: "application/json" };
    for (const h of ["cookie", "user-agent", "origin", "referer", "accept-language", "x-forwarded-for"]) {
      if (req.headers[h]) headers[h] = req.headers[h];
    }
    headers.host = req.headers.host || process.env.TURN_HOST;
    // The certificate is checked against the name the app itself used (the tenant name on a
    // multi-tenant server, covered by its wildcard), not TURN_HOST: that one may be a different
    // name on the same certificate, or wrong on a server set up before the setup took the licence's.
    const reqHost = String(req.headers.host || "").replace(/:\d+$/, "").toLowerCase();
    const servername = /^[a-z0-9.-]+\.[a-z0-9-]+$/.test(reqHost) ? reqHost : process.env.TURN_HOST;
    const check = https.request({
      host: "127.0.0.1",
      port: Number(process.env.TURN_API_UPSTREAM_PORT || 8443),
      path: "/api/sipcredentials",
      method: "GET",
      headers,
      servername,
      timeout: CHECK_TIMEOUT,
    }, (res) => {
      let raw = "";
      res.setEncoding("utf8");
      res.on("data", (c) => { if (raw.length < 65536) raw += c; });
      res.on("end", () => {
        const setCookie = res.headers["set-cookie"];
        if (res.statusCode !== 200) return resolve({ status: res.statusCode === 403 ? 403 : 401, setCookie });
        let json = null;
        try { json = JSON.parse(raw); } catch (e) { /* not JSON: login page */ }
        const extension = json && json.result === "success" && json.content && String(json.content.extension || "");
        if (!extension || !/^[0-9A-Za-z_.*#+-]{1,32}$/.test(extension)) return resolve({ status: 401, setCookie });
        resolve({ extension, setCookie });
      });
    });
    check.on("timeout", () => check.destroy(new Error("session check timed out")));
    check.on("error", (e) => {
      console.log("[turn] session check failed:", e.message);
      resolve({ status: 502 });
    });
    check.end();
  });
}

// The name clients reach the relay by. Normally TURN_HOST. On a multi-tenant server (tenants are
// xyz.region.domain.com under one *.region.domain.com certificate and wildcard DNS) the tenant
// name the app itself connected with is a sibling of TURN_HOST and is used instead: the app has
// just resolved it, so it works even where TURN_HOST does not resolve for that client.
function relayHost(reqHost) {
  const host = String(process.env.TURN_HOST || "").toLowerCase();
  const name = String(reqHost || "").replace(/:\d+$/, "").toLowerCase();
  const parent = host.split(".").slice(1).join(".");
  if (parent.split(".").length >= 3 && /^[a-z0-9-]+$/.test(name.slice(0, -parent.length - 1))
      && name.endsWith("." + parent)) {
    return name;
  }
  return host;
}

function credentials(extension, reqHost) {
  const host = relayHost(reqHost);
  const port = Number(process.env.TURN_PORT || 3478);
  const tlsPort = Number(process.env.TURNS_PORT || 5349);
  const username = `${Math.floor(Date.now() / 1000) + TTL}:${extension}`;
  const credential = crypto.createHmac("sha1", process.env.TURN_SECRET).update(username).digest("base64");
  return {
    iceServers: [{
      urls: [
        `turn:${host}:${port}?transport=udp`,
        `turn:${host}:${port}?transport=tcp`,
        `turns:${host}:${tlsPort}?transport=tcp`,
      ],
      username,
      credential,
    }],
    ttl: TTL,
  };
}

async function handle(req, res) {
  const path = (req.url || "").split("?")[0];
  if (path !== "/api/turn") return reply(res, 404, { result: "error", message: "Not found." });
  if (req.method !== "GET") return reply(res, 405, { result: "error", message: "GET required." }, { Allow: "GET" });
  if (!process.env.TURN_SECRET || !process.env.TURN_HOST) {
    return reply(res, 503, { result: "error", message: "Relay is not set up on this server." });
  }
  const who = await checkSession(req);
  const passCookies = who.setCookie ? { "Set-Cookie": who.setCookie } : {};
  if (!who.extension) {
    if (who.status === 502) return reply(res, 502, { result: "error", message: "Please try again." });
    return reply(res, who.status, { result: "error", message: "Unauthorized. (Code AI1001)" }, passCookies);
  }
  reply(res, 200, credentials(who.extension, req.headers.host), passCookies);
}

function start() {
  const port = Number(process.env.TURN_API_PORT || 6097);
  const server = http.createServer((req, res) => {
    handle(req, res).catch((e) => {
      console.log("[turn] request failed:", e.message);
      if (!res.headersSent) reply(res, 500, { result: "error", message: "Please try again." });
    });
  });
  server.on("error", (e) => console.log("[turn] credentials endpoint not listening:", e.message));
  server.listen(port, "127.0.0.1", () => console.log(`[turn] credentials endpoint on 127.0.0.1:${port}`));
  return server;
}

module.exports = { start, credentials, relayHost };
