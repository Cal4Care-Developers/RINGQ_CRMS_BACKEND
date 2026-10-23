/**
 * Tests for the durable layer and multi-domain tenancy:
 *   recorder.js  — Postgres audit trail (fake pool, no database needed)
 *   config.js    — per-tenant config: env secrets + PHP domain_uuid lookup
 *
 * Dependency-free and run by the Node 24 service test script:
 *   node ringq/node/meet/tests/durable.js
 */

const assert = require("assert");

const recorderMod = require("../recorder.js");
const configMod = require("../config.js");
const tenantMod = require("../tenant.js");

let passed = 0;
let failed = 0;
const failures = [];
const tests = [];

function test(name, fn) { tests.push({ name: name, fn: fn }); }
function group(name) { tests.push({ group: name }); }

const DOMAIN_A = "11111111-1111-1111-1111-111111111111";
const DOMAIN_B = "22222222-2222-2222-2222-222222222222";
const TENANT_A = Object.freeze({ subdomain: "a.ringq.ai", domainUuid: DOMAIN_A, tenantKey: "LKRQaaaa" });

/** Captures SQL instead of executing it. */
function fakePool(opts) {
  const o = opts || {};
  return {
    queries: [],
    query: function (sql, params) {
      this.queries.push({ sql: sql.replace(/\s+/g, " ").trim(), params: params });
      if (o.throwCode) {
        const err = new Error(o.throwMessage || "boom");
        err.code = o.throwCode;
        return Promise.reject(err);
      }
      return Promise.resolve({ rows: o.rows || [] });
    },
    end: function () { return Promise.resolve(); },
  };
}

function collectLog() {
  const lines = [];
  return {
    lines: lines,
    info: function (m) { lines.push("info:" + m); },
    warn: function (m) { lines.push("warn:" + m); },
    error: function (m) { lines.push("error:" + m); },
  };
}

// ───────────────────────── recorder ─────────────────────────

group("recorder.js — every row is domain-scoped");

test("meetingCreated writes domain_uuid and is idempotent", async function () {
  const pool = fakePool();
  const r = new recorderMod.MeetRecorder({ pool: pool });
  await r.meetingCreated(TENANT_A, { meetingId: "m1", slug: "abc", ownerKey: "jane101", title: "T" });
  assert.strictEqual(pool.queries.length, 1);
  const q = pool.queries[0];
  assert.ok(q.sql.indexOf("INSERT INTO public.meet_meetings") === 0, q.sql);
  assert.ok(q.sql.indexOf("ON CONFLICT DO NOTHING") !== -1, "must be idempotent");
  assert.strictEqual(q.params[0], "m1");
  assert.strictEqual(q.params[1], DOMAIN_A, "domain_uuid must be bound");
});

test("the durable row is looked up by reference, domain-scoped", async function () {
  // This table is the identity of a meeting; Redis is a 12h cache of it. The
  // lookup has to be scoped, because the uniqueness that makes the answer
  // meaningful is per domain.
  const pool = fakePool({ rows: [{ id: "m1", slug: "abcd-efgh-ijkl" }] });
  const r = new recorderMod.MeetRecorder({ pool: pool });

  const found = await r.findMeetingByExternalReference(TENANT_A, "booking:42");
  assert.deepStrictEqual(found, { meetingId: "m1", slug: "abcd-efgh-ijkl" });
  const q = pool.queries[0];
  assert.ok(q.sql.indexOf("FROM public.meet_meetings") !== -1, q.sql);
  // The SQL, not just the params: binding domain_uuid and then not filtering
  // on it reads as scoped and is a cross-tenant query.
  assert.ok(q.sql.indexOf("domain_uuid = $1") !== -1, "must filter on domain_uuid: " + q.sql);
  assert.strictEqual(q.params[0], DOMAIN_A, "domain_uuid must be bound");
  assert.strictEqual(q.params[1], "booking:42");
});

test("an unknown reference reads as mint-fresh, never as an error", async function () {
  const r = new recorderMod.MeetRecorder({ pool: fakePool({ rows: [] }) });
  assert.strictEqual(await r.findMeetingByExternalReference(TENANT_A, "nope"), null);
  assert.strictEqual(await r.findMeetingByExternalReference(TENANT_A, ""), null);
});

test("a failed durable lookup does not stop a meeting starting", async function () {
  // _run swallows and returns null; null means mint fresh, which is exactly
  // the behaviour that existed before this lookup did.
  const pool = fakePool({ throwCode: "42P01", throwMessage: "relation does not exist" });
  const r = new recorderMod.MeetRecorder({ pool: pool, log: collectLog() });
  assert.strictEqual(await r.findMeetingByExternalReference(TENANT_A, "booking:42"), null);
});

test("reopening UPDATES the row and clears the end markers", async function () {
  // An INSERT cannot do this. The row already exists, so ON CONFLICT DO
  // NOTHING would leave status='ended' and the previous ended_at on a meeting
  // that is happening right now.
  const pool = fakePool();
  const r = new recorderMod.MeetRecorder({ pool: pool });
  await r.meetingReopened(TENANT_A, { meetingId: "m1", ownerKey: "jane101", title: "Standup" });

  const q = pool.queries[0];
  assert.ok(q.sql.indexOf("UPDATE public.meet_meetings") === 0, q.sql);
  assert.ok(q.sql.indexOf("ended_at = NULL") !== -1, "ended_at must be cleared: " + q.sql);
  assert.ok(q.sql.indexOf("end_reason = NULL") !== -1, "end_reason must be cleared: " + q.sql);
  assert.ok(q.sql.indexOf("status = 'created'") !== -1, q.sql);
  assert.strictEqual(q.params[0], "m1");
  assert.strictEqual(q.params[1], DOMAIN_A, "domain_uuid must be bound");
});

test("EVERY write binds the tenant's domain_uuid", async function () {
  const pool = fakePool();
  const r = new recorderMod.MeetRecorder({ pool: pool });
  await r.meetingCreated(TENANT_A, { meetingId: "m1" });
  await r.meetingStarted(TENANT_A, { meetingId: "m1" });
  await r.meetingEnded(TENANT_A, { meetingId: "m1" });
  await r.participantJoined(TENANT_A, { meetingId: "m1", participantId: "p1", displayName: "A" });
  await r.participantLeft(TENANT_A, { meetingId: "m1", participantId: "p1" });
  await r.recordingStarted(TENANT_A, { meetingId: "m1", egressId: "e1" });
  await r.recordingFinished(TENANT_A, { meetingId: "m1", egressId: "e1" });
  await r.transcriptLine(TENANT_A, { meetingId: "m1", text: "hello" });
  await r.chatMessage(TENANT_A, { meetingId: "m1", senderName: "A", body: "hi" });
  await r.summarySaved(TENANT_A, { meetingId: "m1", html: "<p/>" });
  await r.feedbackSaved(TENANT_A, { meetingId: "m1", rating: 5 });
  await r.invitationSent(TENANT_A, { meetingId: "m1", email: "x@e.co" });

  assert.ok(pool.queries.length >= 12, "expected a query per call, got " + pool.queries.length);
  pool.queries.forEach(function (q) {
    assert.ok(
      q.params.indexOf(DOMAIN_A) !== -1,
      "no domain_uuid bound in: " + q.sql.slice(0, 70)
    );
    assert.ok(
      /domain_uuid/.test(q.sql),
      "SQL does not mention domain_uuid: " + q.sql.slice(0, 70)
    );
  });
});

test("a tenant can never read another tenant's history", async function () {
  const pool = fakePool({ rows: [] });
  const r = new recorderMod.MeetRecorder({ pool: pool });
  const tenantB = Object.assign({}, TENANT_A, { domainUuid: DOMAIN_B });
  await r.listHistory(TENANT_A, {});
  await r.listHistory(tenantB, {});
  assert.strictEqual(pool.queries[0].params[0], DOMAIN_A);
  assert.strictEqual(pool.queries[1].params[0], DOMAIN_B);
  assert.ok(/WHERE m.domain_uuid = \$1/.test(pool.queries[0].sql), pool.queries[0].sql);
});

test("history is paginated and capped, so one call cannot scan everything", async function () {
  const pool = fakePool({ rows: [] });
  const r = new recorderMod.MeetRecorder({ pool: pool });
  await r.listHistory(TENANT_A, { limit: 99999, offset: -5 });
  assert.strictEqual(pool.queries[0].params[1], 200, "limit must be capped");
  assert.strictEqual(pool.queries[0].params[2], 0, "offset must not go negative");
});

group("recorder.js — a durable failure never breaks a live meeting");

test("a missing table is reported actionably, not thrown", async function () {
  const pool = fakePool({ throwCode: "42P01", throwMessage: 'relation "meet_meetings" does not exist' });
  const log = collectLog();
  const r = new recorderMod.MeetRecorder({ pool: pool, logger: log });
  const result = await r.meetingCreated(TENANT_A, { meetingId: "m1" });
  assert.strictEqual(result, null, "must resolve null, never reject");
  assert.ok(
    log.lines.some(function (l) { return l.indexOf("meetSchema up") !== -1; }),
    "the log must name the fix; got: " + log.lines.join(" | ")
  );
});

test("any other database error also resolves rather than rejecting", async function () {
  const pool = fakePool({ throwCode: "57P01", throwMessage: "server shutting down" });
  const log = collectLog();
  const r = new recorderMod.MeetRecorder({ pool: pool, logger: log });
  const result = await r.meetingEnded(TENANT_A, { meetingId: "m1" });
  assert.strictEqual(result, null);
  assert.ok(log.lines.some(function (l) { return l.indexOf("warn:") === 0; }));
});

test("participantLeft closes only the LATEST open row (rejoins keep their history)", async function () {
  const pool = fakePool();
  const r = new recorderMod.MeetRecorder({ pool: pool });
  await r.participantLeft(TENANT_A, { meetingId: "m1", participantId: "p1" });
  const sql = pool.queries[0].sql;
  assert.ok(/ORDER BY joined_at DESC/.test(sql), "must pick the most recent row: " + sql);
  assert.ok(/LIMIT 1/.test(sql), "must close exactly one row");
  assert.ok(/left_at IS NULL/.test(sql), "must only close an open row");
});

test("meetingEnded also closes participant rows left open by crashed clients", async function () {
  const pool = fakePool();
  const r = new recorderMod.MeetRecorder({ pool: pool });
  await r.meetingEnded(TENANT_A, { meetingId: "m1" });
  assert.strictEqual(pool.queries.length, 2, "expected the meeting update AND the participant sweep");
  assert.ok(/UPDATE public.meet_participants/.test(pool.queries[1].sql));
  assert.ok(/left_at IS NULL/.test(pool.queries[1].sql));
});

test("fromEnv returns null (not a crash) when PostgreSQL_DB_* is unset", function () {
  const log = collectLog();
  const r = recorderMod.fromEnv({}, log);
  assert.strictEqual(r, null);
  assert.ok(log.lines.some(function (l) { return l.indexOf("durable writes disabled") !== -1; }));
});

test("constructing without a pool is a programmer error", function () {
  assert.throws(function () { new recorderMod.MeetRecorder({}); }, TypeError);
});

// ───────────────────────── config loader ─────────────────────────

group("config.js — multi-domain tenancy");

const ENV_OK = {};

function loaderWith(env, phpReply) {
  const calls = [];
  const loader = configMod.createConfigLoader({
    env: env,
    logger: collectLog(),
    httpPost: function (url, body) {
      calls.push({ url: url, body: body });
      return Promise.resolve(phpReply(body.host));
    },
  });
  return { loader: loader, calls: calls };
}

test("TWO hosts on ONE box resolve to DIFFERENT domain_uuids (the whole point)", async function () {
  const l = loaderWith(ENV_OK, function (host) {
    const map = { "a.ringq.ai": DOMAIN_A, "b.ringq.ai": DOMAIN_B };
    return map[host]
      ? { status: 200, data: { result: true, domainUuid: map[host] } }
      : { status: 200, data: { result: false, message: "Unknown meet host." } };
  });
  const a = await l.loader("a.ringq.ai");
  const b = await l.loader("b.ringq.ai");
  assert.strictEqual(a.domainUuid, DOMAIN_A);
  assert.strictEqual(b.domainUuid, DOMAIN_B);
  // And therefore two different tenantKeys / namespaces.
  const keyA = tenantMod.deriveTenantKey("a.ringq.ai", a.domainUuid);
  const keyB = tenantMod.deriveTenantKey("b.ringq.ai", b.domainUuid);
  assert.notStrictEqual(keyA, keyB);
});

test("PHP saying 'not a tenant here' means refuse, NOT fall back", async function () {
  const env = Object.assign({ MEET_DOMAIN_UUID: DOMAIN_A }, ENV_OK);
  const l = loaderWith(env, function () {
    return { status: 200, data: { result: false, message: "Unknown meet host." } };
  });
  const cfg = await l.loader("attacker.example.com");
  assert.strictEqual(cfg, null,
    "a clear PHP rejection must not be overridden by MEET_DOMAIN_UUID");
});

test("legacy ACL redirect is accepted only when it carries a valid tenant identity", async function () {
  const valid = loaderWith(ENV_OK, function () {
    return { status: 302, data: { result: true, domainUuid: DOMAIN_A } };
  });
  assert.strictEqual((await valid.loader("a.ringq.ai")).domainUuid, DOMAIN_A);

  const redirectPage = loaderWith(ENV_OK, function () {
    return { status: 302, data: "<html>login</html>" };
  });
  assert.strictEqual(await redirectPage.loader("a.ringq.ai"), null);
});

test("PHP unreachable fails closed even if obsolete MEET_DOMAIN_UUID is set", async function () {
  const env = Object.assign({ MEET_DOMAIN_UUID: DOMAIN_A }, ENV_OK);
  const loader = configMod.createConfigLoader({
    env: env,
    logger: collectLog(),
    httpPost: function () { return Promise.reject(new Error("ECONNREFUSED")); },
  });
  const cfg = await loader("a.ringq.ai");
  assert.strictEqual(cfg, null, "a host must never fall back to a box-wide tenant identity");
});

test("PHP unreachable and NO fallback -> refuse", async function () {
  const loader = configMod.createConfigLoader({
    env: ENV_OK,
    logger: collectLog(),
    httpPost: function () { return Promise.reject(new Error("ECONNREFUSED")); },
  });
  assert.strictEqual(await loader("a.ringq.ai"), null);
});

test("MEET_TENANT_HOST acts as an allow-list, and accepts a list", async function () {
  const env = Object.assign({ MEET_TENANT_HOST: "a.ringq.ai, b.ringq.ai" }, ENV_OK);
  const l = loaderWith(env, function () {
    return { status: 200, data: { result: true, domainUuid: DOMAIN_A } };
  });
  assert.ok(await l.loader("a.ringq.ai"), "listed host allowed");
  assert.ok(await l.loader("b.ringq.ai"), "second listed host allowed");
  assert.strictEqual(await l.loader("c.ringq.ai"), null, "unlisted host refused");
});

test("tenant identity resolution does not require or accept LiveKit config", async function () {
  const log = collectLog();
  const loader = configMod.createConfigLoader({
    env: {},
    logger: log,
    httpPost: function () { return Promise.resolve({ status: 200, data: { result: true, domainUuid: DOMAIN_A } }); },
  });
  const cfg = await loader("a.ringq.ai");
  assert.strictEqual(cfg.domainUuid, DOMAIN_A);
  assert.strictEqual(cfg.livekitSecret, undefined);
});

test("the tenant loader never handles a LiveKit secret", async function () {
  const l = loaderWith(ENV_OK, function () {
    return { status: 200, data: { result: true, domainUuid: DOMAIN_A } };
  });
  const cfg = await l.loader("a.ringq.ai");
  assert.strictEqual(cfg.livekitSecret, undefined);
  l.calls.forEach(function (c) {
    assert.ok(
      JSON.stringify(c.body).indexOf("secret") === -1,
      "the PHP request must not carry or ask for a secret: " + JSON.stringify(c.body)
    );
  });
});

test("the loader plugs into TenantResolver and yields a usable tenant", async function () {
  const l = loaderWith(ENV_OK, function () {
    return { status: 200, data: { result: true, domainUuid: DOMAIN_A } };
  });
  const resolver = new tenantMod.TenantResolver({ loadConfig: l.loader });
  const tenant = await resolver.resolve({ headers: { host: "a.ringq.ai:8443" } });
  assert.strictEqual(tenant.subdomain, "a.ringq.ai");
  assert.strictEqual(tenant.domainUuid, DOMAIN_A);
  assert.strictEqual(tenant.livekitSecret, undefined);
  assert.ok(/^LKRQ[0-9a-f]{12}$/.test(tenant.tenantKey));
});

// ───────────────────────── runner ─────────────────────────

(async function () {
  for (const t of tests) {
    if (t.group) {
      process.stdout.write("\n" + t.group + "\n");
      continue;
    }
    try {
      await t.fn();
      passed++;
      process.stdout.write("  ok   " + t.name + "\n");
    } catch (err) {
      failed++;
      failures.push({ name: t.name, err: err });
      process.stdout.write("  FAIL " + t.name + "\n");
    }
  }
  process.stdout.write("\n" + "-".repeat(62) + "\n");
  process.stdout.write("passed: " + passed + "   failed: " + failed + "\n");
  if (failed) {
    failures.forEach(function (f) {
      process.stdout.write("\n  " + f.name + "\n    " + (f.err && f.err.message) + "\n");
    });
    process.exit(1);
  }
  process.stdout.write("node " + process.version + " — all green\n");
})();
