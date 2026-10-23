/** Production boundary tests: gateway mTLS contract, opaque host auth, presence. */

const assert = require("assert");
const fs = require("fs");
const path = require("path");
const authMod = require("../auth.js");
const gatewayMod = require("../gateway.js");
const presenceMod = require("../presence.js");
const roomsMod = require("../rooms.js");
const rateMod = require("../rate-limit.js");
const callbackAuthMod = require("../callback-auth.js");
const { FakeRedis } = require("./fake-redis.js");

const TENANT = Object.freeze({
  subdomain: "tenant.ringq.ai",
  domainUuid: "11111111-1111-1111-1111-111111111111",
  tenantKey: "LKRQaaaaaaaaaaaa",
  keyPrefix: "meet:LKRQaaaaaaaaaaaa:",
});

const tests = [];
function test(name, fn) { tests.push({ name: name, fn: fn }); }

test("Meet processes treat the dedicated environment file as authoritative", function () {
  ["server.js", "summary-worker.js"].forEach(function (file) {
    const source = fs.readFileSync(path.join(__dirname, "..", file), "utf8");
    assert.match(source, /require\(["']dotenv["']\)\.config\(\{[\s\S]*?override:\s*true,/);
  });
});

test("gateway constructs the tenant endpoint and sends no room name or secret", async function () {
  const calls = [];
  const gateway = new gatewayMod.GatewayClient({
    baseUrl: "http://gateway.test",
    allowInsecure: true,
    request: async function (request) {
      calls.push(request);
      return { status: 200, data: { token: "media-token", serverUrl: "wss://sfu.test", expiresIn: 300 } };
    },
  });
  await gateway.mintToken(TENANT, { meetingId: "meeting-1", identity: "host-1", name: "Host", role: "host" });
  assert.ok(calls[0].url.endsWith("/v1/tenants/LKRQaaaaaaaaaaaa/meetings/meeting-1/tokens"));
  const wire = JSON.stringify(calls[0].data);
  assert.strictEqual(wire.indexOf("LKRQaaaaaaaaaaaa:meeting-1"), -1);
  assert.strictEqual(wire.toLowerCase().indexOf("secret"), -1);
});

test("gateway refuses plaintext unless a test explicitly opts in", function () {
  assert.throws(function () {
    return new gatewayMod.GatewayClient({ baseUrl: "http://gateway.test", request: async function () {} });
  }, function (err) { return err.code === "GATEWAY_INSECURE_URL"; });
});

test("gateway readiness uses the production readiness endpoint", async function () {
  const calls = [];
  const gateway = new gatewayMod.GatewayClient({
    baseUrl: "http://gateway.test",
    allowInsecure: true,
    request: async function (request) {
      calls.push(request);
      return { status: 200, data: { status: "ready" } };
    },
  });
  assert.strictEqual(await gateway.health(), true);
  assert.ok(calls[0].url.endsWith("/health/ready"));
});

test("gateway parses the canonical nested error envelope", async function () {
  const gateway = new gatewayMod.GatewayClient({
    baseUrl: "http://gateway.test",
    allowInsecure: true,
    request: async function () {
      return {
        status: 403,
        data: { error: { code: "forbidden", message: "Tenant certificate is not authorized" } },
      };
    },
  });
  await assert.rejects(
    gateway.listParticipants(TENANT, "meeting-1"),
    function (err) {
      return err.code === "forbidden" && err.message === "Tenant certificate is not authorized";
    }
  );
});

test("gateway rejects a tenant key outside the canonical namespace", function () {
  const gateway = new gatewayMod.GatewayClient({
    baseUrl: "http://gateway.test",
    allowInsecure: true,
    request: async function () { return { status: 200, data: {} }; },
  });
  assert.throws(function () {
    gateway.listParticipants({ tenantKey: "tenant-a" }, "meeting-1");
  }, function (err) { return err.code === "GATEWAY_BAD_TENANT_KEY"; });
});

test("opaque access token lookup stores and reads only the SHA-256 hash", async function () {
  const redis = new FakeRedis();
  const token = "random-opaque-access-token-that-is-long-enough";
  const record = { ownerKey: "owner-1", domainUuid: TENANT.domainUuid, canHost: true };
  await redis.set("meet:access:" + authMod.opaqueTokenHash(token), JSON.stringify(record), { EX: 900 });
  const store = new authMod.OpaqueAccessTokenStore({ redis: redis });
  assert.strictEqual((await store.lookup(token)).ownerKey, "owner-1");
  assert.strictEqual(Array.from(redis.store.keys()).join("|").indexOf(token), -1);
});

test("host auth accepts a valid opaque bearer and enforces its domain", async function () {
  const authenticator = new authMod.HostAuthenticator({
    lookupBearer: async function () {
      return { ownerKey: "owner-1", displayName: "Owner", domainUuid: TENANT.domainUuid, canHost: true };
    },
  });
  const host = await authenticator.requireHost({ headers: { authorization: "Bearer opaque" } }, TENANT);
  assert.strictEqual(host.ownerKey, "owner-1");
  assert.strictEqual(host.via, "bearer");
});

test("host auth rejects a bearer from another FusionPBX domain", async function () {
  const authenticator = new authMod.HostAuthenticator({
    lookupBearer: async function () {
      return { ownerKey: "owner-1", domainUuid: "22222222-2222-2222-2222-222222222222", canHost: true };
    },
  });
  await assert.rejects(
    authenticator.requireHost({ headers: { authorization: "Bearer opaque" } }, TENANT),
    function (err) { return err.code === "AUTH_WRONG_DOMAIN"; }
  );
});

test("production host auth rejects session cookies in favor of opaque bearers", async function () {
  const authenticator = new authMod.HostAuthenticator({
    lookupBearer: async function () { return null; },
  });
  await assert.rejects(
    authenticator.requireHost({ headers: { cookie: "PHPSESSID=must-not-be-forwarded" } }, TENANT),
    function (err) { return err.code === "AUTH_BEARER_REQUIRED"; }
  );
});

function presenceHarness() {
  const redis = new FakeRedis();
  const storeFor = function (tenant) { return new roomsMod.RoomStore({ redis: redis, tenant: tenant }); };
  const attendance = [];
  let gatewayParticipants = [];
  const gateway = {
    listParticipants: async function () { return { participants: gatewayParticipants }; },
  };
  const recorder = {
    meetingStarted: async function () {},
    meetingEnded: async function () {},
    participantJoined: async function (tenant, p) { attendance.push("join:" + p.participantId + ":" + p.role); },
    participantLeft: async function (tenant, p) { attendance.push("left:" + p.participantId); },
  };
  const presence = new presenceMod.PresenceCoordinator({ redis: redis, storeFor: storeFor, gateway: gateway, recorder: recorder });
  return {
    redis: redis,
    store: storeFor(TENANT),
    presence: presence,
    attendance: attendance,
    setParticipants: function (value) { gatewayParticipants = value; },
  };
}

test("token intent is not attendance; callback confirms role and presence", async function () {
  const h = presenceHarness();
  await h.store.createRoom({ meetingId: "meeting-1" });
  await h.store.putParticipantIntent("meeting-1", "host-1", { displayName: "Jane", role: "host" });
  assert.strictEqual(await h.store.countParticipants("meeting-1"), 0);
  await h.presence.applyEvent(TENANT, {
    id: "event-1", tenantKey: TENANT.tenantKey, meetingId: "meeting-1", type: "participant_joined",
    participant: { identity: "host-1", name: "Jane" },
  }, "event-1");
  assert.strictEqual(await h.store.countParticipants("meeting-1"), 1);
  assert.deepStrictEqual(h.attendance, ["join:host-1:host"]);
});

test("at-least-once callbacks are idempotent", async function () {
  const h = presenceHarness();
  await h.store.createRoom({ meetingId: "meeting-1" });
  const event = {
    id: "event-dup", tenantKey: TENANT.tenantKey, meetingId: "meeting-1", type: "participant_joined",
    participant: { identity: "p-1", name: "Guest" },
  };
  await h.presence.applyEvent(TENANT, event, "event-dup");
  const duplicate = await h.presence.applyEvent(TENANT, event, "event-dup");
  assert.strictEqual(duplicate.duplicate, true);
  assert.strictEqual(h.attendance.length, 1);
});

test("room_started callbacks are acknowledged without starting attendance", async function () {
  const h = presenceHarness();
  await h.store.createRoom({ meetingId: "meeting-1" });
  const result = await h.presence.applyEvent(TENANT, {
    id: "room-started-1", tenantKey: TENANT.tenantKey, meetingId: "meeting-1", type: "room_started",
  }, "room-started-1");
  assert.strictEqual(result.duplicate, false);
  assert.strictEqual(await h.store.countParticipants("meeting-1"), 0);
  assert.deepStrictEqual(h.attendance, []);
});

test("callback tenant mismatch is rejected before state changes", async function () {
  const h = presenceHarness();
  await h.store.createRoom({ meetingId: "meeting-1" });
  await assert.rejects(h.presence.applyEvent(TENANT, {
    id: "event-x", tenantKey: "LKRQbbbbbbbbbbbb", meetingId: "meeting-1", type: "participant_joined",
  }, "event-x"), /another tenant/);
  assert.strictEqual(await h.store.countParticipants("meeting-1"), 0);
});

test("malformed gateway callbacks are validation errors", async function () {
  const h = presenceHarness();
  await assert.rejects(
    h.presence.applyEvent(TENANT, {}, ""),
    function (err) { return err.code === "CALLBACK_INVALID" && err.statusCode === 400; }
  );
  await assert.rejects(
    h.presence.applyEvent(TENANT, {
      id: "event-unsupported",
      tenantKey: TENANT.tenantKey,
      meetingId: "meeting-1",
      type: "unknown_event",
    }, "event-unsupported"),
    function (err) { return err.code === "CALLBACK_INVALID" && err.statusCode === 400; }
  );
});

test("reconciliation repairs missed joins and leaves", async function () {
  const h = presenceHarness();
  await h.store.createRoom({ meetingId: "meeting-1" });
  await h.store.markActive("meeting-1");
  h.setParticipants([{ identity: "p-1", name: "Guest", metadata: { role: "participant" } }]);
  await h.presence.reconcileTenant(TENANT);
  assert.strictEqual(await h.store.countParticipants("meeting-1"), 1);
  h.setParticipants([]);
  await h.presence.reconcileTenant(TENANT);
  assert.strictEqual(await h.store.countParticipants("meeting-1"), 0);
  assert.deepStrictEqual(h.attendance, ["join:p-1:participant", "left:p-1"]);
});

test("reconciliation closes an active room missing from the gateway", async function () {
  const redis = new FakeRedis();
  const store = new roomsMod.RoomStore({ redis: redis, tenant: TENANT });
  await store.createRoom({ meetingId: "meeting-1" });
  await store.markActive("meeting-1");
  const presence = new presenceMod.PresenceCoordinator({
    redis: redis,
    storeFor: function () { return store; },
    gateway: {
      listParticipants: async function () {
        throw Object.assign(new Error("not found"), { code: "room_not_found", statusCode: 404 });
      },
    },
  });
  await presence.reconcileTenant(TENANT);
  assert.strictEqual((await store.getRoom("meeting-1")).status, "ended");
  assert.deepStrictEqual(await store.listActiveRooms(), []);
});

test("presenter departure stops after the grace period and reconnect cancels it", async function () {
  const redis = new FakeRedis();
  const store = new roomsMod.RoomStore({ redis: redis, tenant: TENANT });
  await store.createRoom({ meetingId: "meeting-1" });
  await store.addParticipant("meeting-1", "presenter-1", {
    displayName: "Presenter", role: "participant",
  });
  let active = true;
  let stopped = 0;
  let broadcasts = 0;
  const collaboration = {
    presentationState: async function () {
      return active
        ? { status: "active", presenterId: "presenter-1", version: 4 }
        : { status: "idle", presenterId: null, version: 5 };
    },
    applyPresentationAction: async function () { active = false; stopped++; },
  };
  const presence = new presenceMod.PresenceCoordinator({
    redis: redis,
    storeFor: function () { return store; },
    gateway: {
      listParticipants: async function () { return { participants: [] }; },
      broadcastRoomData: async function () { broadcasts++; },
    },
    collaboration: collaboration,
    features: { filePresentations: true },
    presentationGraceMs: 10,
  });
  await presence.applyEvent(TENANT, {
    id: "presenter-left-1", tenantKey: TENANT.tenantKey, meetingId: "meeting-1",
    type: "participant_left", participant: { identity: "presenter-1" },
  }, "presenter-left-1");
  await presence.applyEvent(TENANT, {
    id: "presenter-rejoined-1", tenantKey: TENANT.tenantKey, meetingId: "meeting-1",
    type: "participant_joined", participant: { identity: "presenter-1", name: "Presenter" },
  }, "presenter-rejoined-1");
  await new Promise(function (resolve) { setTimeout(resolve, 20); });
  assert.strictEqual(stopped, 0);

  await presence.applyEvent(TENANT, {
    id: "presenter-left-2", tenantKey: TENANT.tenantKey, meetingId: "meeting-1",
    type: "participant_left", participant: { identity: "presenter-1" },
  }, "presenter-left-2");
  await new Promise(function (resolve) { setTimeout(resolve, 20); });
  assert.strictEqual(stopped, 1);
  assert.strictEqual(broadcasts, 1);
  presence.stop();
});

test("rate limiter returns a bounded retry time", function () {
  let now = 1000;
  const limiter = new rateMod.RateLimiter({ now: function () { return now; }, windowMs: 60000 });
  assert.strictEqual(limiter.consume("ip", 2).allowed, true);
  assert.strictEqual(limiter.consume("ip", 2).allowed, true);
  const denied = limiter.consume("ip", 2);
  assert.strictEqual(denied.allowed, false);
  assert.strictEqual(denied.retryAfterSeconds, 60);
  now += 60001;
  assert.strictEqual(limiter.consume("ip", 2).allowed, true);
});

test("gateway callback auth fails closed when proxy verification is unconfigured", function () {
  const verify = callbackAuthMod.callbackAuthenticator({});
  assert.strictEqual(verify({ headers: { "x-ringq-mtls-verified": "SUCCESS" } }), false,
    "a public caller must not enable callbacks by spoofing the old default header");
});

test("gateway callback auth requires the exact trusted-proxy value", function () {
  const verify = callbackAuthMod.callbackAuthenticator({
    SFU_GATEWAY_CALLBACK_VERIFY_HEADER: "x-ringq-mtls-verified",
    SFU_GATEWAY_CALLBACK_VERIFY_VALUE: "SUCCESS",
  });
  assert.strictEqual(verify({ headers: {} }), false);
  assert.strictEqual(verify({ headers: { "x-ringq-mtls-verified": "FAILED" } }), false);
  assert.strictEqual(verify({ headers: { "x-ringq-mtls-verified": "SUCCESS" } }), true);
});

/**
 * The router validates the transcript callback bearer against `d.transcriberSecret`.
 * `server.js` minted that same secret into every transcriber job but never handed
 * it to `createRouter`, so `validWorkerBearer`'s `configured.length >= 32` guard was
 * false for every request and `/callbacks/transcript` answered 403 forever — no
 * transcript row could ever be stored.
 *
 * No functional test caught it because `tests/api.js` passes `transcriberSecret`
 * into the harness itself: the double was wired correctly and production was not.
 * So this asserts the PRODUCTION call site, which is the thing that was wrong.
 */
test("server.js gives the router the transcript callback secret", function () {
  const source = fs.readFileSync(path.join(__dirname, "..", "server.js"), "utf8");
  const start = source.indexOf("createRouter({");
  assert.ok(start !== -1, "server.js must build a router");
  const args = source.slice(start, source.indexOf("});", start));
  assert.ok(
    /transcriberSecret\s*:/.test(args),
    "createRouter must receive transcriberSecret or every transcript callback is refused"
  );
  // The same secret has to reach the service that mints it into the worker job,
  // or the two sides authenticate against different values.
  const captureStart = source.indexOf("new captureMod.CaptureService(");
  const captureArgs = captureStart === -1
    ? source
    : source.slice(captureStart, source.indexOf("});", captureStart));
  assert.ok(
    /transcriberSecret\s*:/.test(captureArgs),
    "the capture service must receive transcriberSecret to mint job callback tokens"
  );
});

(async function () {
  let passed = 0;
  for (const item of tests) {
    try {
      await item.fn();
      passed++;
      process.stdout.write("  ok   " + item.name + "\n");
    } catch (err) {
      process.stderr.write("  FAIL " + item.name + "\n    " + err.stack + "\n");
      process.exitCode = 1;
    }
  }
  process.stdout.write("passed: " + passed + "/" + tests.length + "\n");
})();
