/** Dependency-free state and tenant tests for the Node 24 control plane. */

const assert = require("assert");
const tenantMod = require("../tenant.js");
const roomsMod = require("../rooms.js");
const { FakeRedis } = require("./fake-redis.js");

const DOMAIN_A = "11111111-1111-1111-1111-111111111111";
const DOMAIN_B = "22222222-2222-2222-2222-222222222222";

function tenant(host, domainUuid) {
  const tenantKey = tenantMod.deriveTenantKey(host, domainUuid);
  return Object.freeze({
    subdomain: host,
    domainUuid: domainUuid,
    tenantKey: tenantKey,
    keyPrefix: tenantMod.keyPrefix(tenantKey),
  });
}

const TENANT_A = tenant("a.ringq.ai", DOMAIN_A);
const TENANT_B = tenant("b.ringq.ai", DOMAIN_B);
const tests = [];
function test(name, fn) { tests.push({ name: name, fn: fn }); }

test("host normalization is stable across case and port", function () {
  assert.strictEqual(tenantMod.normaliseHost("A.RingQ.AI:8443"), "a.ringq.ai");
  assert.strictEqual(tenantMod.normaliseHost("[::1]:443"), "[::1]");
});

test("tenant keys include host and FusionPBX domain", function () {
  assert.strictEqual(tenantMod.deriveTenantKey("a.ringq.ai", DOMAIN_A), TENANT_A.tenantKey);
  assert.notStrictEqual(tenantMod.deriveTenantKey("a.ringq.ai", DOMAIN_B), TENANT_A.tenantKey);
  assert.notStrictEqual(tenantMod.deriveTenantKey("b.ringq.ai", DOMAIN_A), TENANT_A.tenantKey);
});

test("resolver fails closed for unknown and incomplete domains", async function () {
  const resolver = new tenantMod.TenantResolver({
    loadConfig: function (host) {
      if (host === "a.ringq.ai") return { domainUuid: DOMAIN_A };
      if (host === "broken.ringq.ai") return {};
      return null;
    },
  });
  assert.strictEqual((await resolver.resolve({ headers: { host: "a.ringq.ai:443" } })).tenantKey, TENANT_A.tenantKey);
  await assert.rejects(resolver.resolve({ headers: { host: "unknown.ringq.ai" } }), function (err) {
    return err.code === "TENANT_UNKNOWN";
  });
  await assert.rejects(resolver.resolve({ headers: { host: "broken.ringq.ai" } }), function (err) {
    return err.code === "TENANT_INCOMPLETE_CONFIG";
  });
});

test("resolved tenant records never carry LiveKit credentials", async function () {
  const resolver = new tenantMod.TenantResolver({
    loadConfig: function () {
      return { domainUuid: DOMAIN_A };
    },
  });
  const resolved = await resolver.resolve({ headers: { host: "a.ringq.ai" } });
  assert.strictEqual(resolved.livekitKey, undefined);
  assert.strictEqual(resolved.livekitSecret, undefined);
  assert.strictEqual(resolved.sfuUrl, undefined);
});

test("room state is isolated by tenant", async function () {
  const redis = new FakeRedis();
  const a = new roomsMod.RoomStore({ redis: redis, tenant: TENANT_A });
  const b = new roomsMod.RoomStore({ redis: redis, tenant: TENANT_B });
  await a.createRoom({ meetingId: "same", title: "A", slug: "same-code" });
  await b.createRoom({ meetingId: "same", title: "B", slug: "same-code" });
  assert.strictEqual((await a.getRoom("same")).title, "A");
  assert.strictEqual((await b.getRoom("same")).title, "B");
});

test("external references are idempotently reserved per tenant", async function () {
  const redis = new FakeRedis();
  const store = new roomsMod.RoomStore({ redis: redis, tenant: TENANT_A });
  assert.strictEqual(await store.reserveExternalReference("booking:42", "m1"), "m1");
  assert.strictEqual(await store.reserveExternalReference("booking:42", "m2"), "m1");
  assert.strictEqual(await store.resolveExternalReference("booking:42"), "m1");
});

test("scheduled and booking external references resolve without a legacy scan", async function () {
  const redis = new FakeRedis();
  const store = new roomsMod.RoomStore({ redis: redis, tenant: tenant("a.example", DOMAIN_A) });
  await store.createRoom({ meetingId: "new-meeting-id", externalReference: "legacy-booking-42" });
  assert.strictEqual(await store.resolveMeetingId("legacy-booking-42"), "new-meeting-id");
});

test("guest bearer values never appear in Redis", async function () {
  const redis = new FakeRedis();
  const store = new roomsMod.RoomStore({ redis: redis, tenant: TENANT_A });
  const token = "raw-guest-token";
  await store.putGuest(token, { meetingId: "m1", participantId: "p1" });
  assert.strictEqual(Array.from(redis.store.keys()).join("|").indexOf(token), -1);
  assert.strictEqual(JSON.stringify(Array.from(redis.store.values())).indexOf(token), -1);
  assert.strictEqual((await store.getGuest(token)).participantId, "p1");
});

test("guest role updates keep the reverse lookup aligned with the session TTL", async function () {
  let now = 1000;
  const redis = new FakeRedis({ now: function () { return now; } });
  const store = new roomsMod.RoomStore({
    redis: redis,
    tenant: TENANT_A,
    ttl: { guest: 10 },
    now: function () { return now; },
  });
  const token = "bounded-guest-token";
  await store.putGuest(token, { meetingId: "m1", participantId: "p1" });
  now += 9000;
  await store.setGuestDecisionByParticipant("m1", "p1", "admitted");
  now += 5000;
  await store.setGuestRoleByParticipant("m1", "p1", "cohost");
  now += 6000;
  const demoted = await store.setGuestRoleByParticipant("m1", "p1", "participant");
  assert.strictEqual(demoted.role, "participant");
  assert.strictEqual((await store.getGuest(token)).role, "participant");
});

test("a moderator can read a guest decision without holding the guest token", async function () {
  const store = new roomsMod.RoomStore({ redis: new FakeRedis(), tenant: TENANT_A });
  await store.putGuest("waiting-guest-token", { meetingId: "m1", participantId: "p1" });
  assert.strictEqual((await store.getGuestByParticipant("m1", "p1")).decision, "waiting");
  await store.setGuestDecisionByParticipant("m1", "p1", "admitted");
  assert.strictEqual((await store.getGuestByParticipant("m1", "p1")).decision, "admitted");
  // No pointer, no leak: an unknown participant must not resolve to anyone.
  assert.strictEqual(await store.getGuestByParticipant("m1", "unknown"), null);
  assert.strictEqual(await store.getGuestByParticipant("m2", "p1"), null);
});

test("the raised-hand queue is ordered by a counter, not by a timestamp", async function () {
  // A frozen clock is the point: two hands raised in the same millisecond are
  // ordinary, and "who asked first" must still have one answer.
  const store = new roomsMod.RoomStore({
    redis: new FakeRedis(),
    tenant: TENANT_A,
    now: function () { return 5000; },
  });
  const first = await store.raiseHand("m1", "p1", { displayName: "First" });
  const second = await store.raiseHand("m1", "p2", { displayName: "Second" });
  assert.strictEqual(first.changed, true);
  assert.ok(second.hand.sequence > first.hand.sequence);

  const repeat = await store.raiseHand("m1", "p1", { displayName: "First" });
  assert.strictEqual(repeat.changed, false);
  assert.strictEqual(repeat.hand.sequence, first.hand.sequence, "a retry must not re-queue");

  assert.deepStrictEqual(
    (await store.listHands("m1")).map(function (hand) { return hand.participantId; }),
    ["p1", "p2"]
  );
  assert.strictEqual(await store.lowerHand("m1", "p1"), true);
  assert.strictEqual(await store.lowerHand("m1", "p1"), false, "lowering twice is not a change");
  assert.deepStrictEqual(
    (await store.listHands("m1")).map(function (hand) { return hand.participantId; }),
    ["p2"]
  );

  // A later raise goes to the back, never back to its old place.
  const rejoined = await store.raiseHand("m1", "p1", { displayName: "First" });
  assert.ok(rejoined.hand.sequence > second.hand.sequence);

  // Ending the meeting drops the queue with the rest of the volatile state.
  await store.endRoom("m1");
  assert.deepStrictEqual(await store.listHands("m1"), []);
});

test("participant intents are separate from confirmed presence", async function () {
  const store = new roomsMod.RoomStore({ redis: new FakeRedis(), tenant: TENANT_A });
  await store.createRoom({ meetingId: "m1" });
  await store.putParticipantIntent("m1", "p1", { displayName: "Jane", role: "host" });
  assert.strictEqual(await store.countParticipants("m1"), 0);
  assert.strictEqual((await store.getParticipantIntent("m1", "p1")).role, "host");
});

test("the first confirmed participant permanently anchors the live start", async function () {
  const store = new roomsMod.RoomStore({ redis: new FakeRedis(), tenant: TENANT_A });
  await store.createRoom({ meetingId: "m1" });
  const starts = await Promise.all([
    store.markLive("m1", 1000),
    store.markLive("m1", 2000),
  ]);
  const room = await store.getRoom("m1");
  assert.strictEqual(room.status, "live");
  assert.strictEqual(room.startedAt, "1000");
  assert.deepStrictEqual(starts, ["1000", "1000"]);
  assert.strictEqual(await store.markLive("m1", 3000), "1000");
});

test("ended rooms preserve status while dropping volatile presence", async function () {
  const store = new roomsMod.RoomStore({ redis: new FakeRedis(), tenant: TENANT_A });
  await store.createRoom({ meetingId: "m1", ownerKey: "owner", slug: "code" });
  await store.addParticipant("m1", "p1", {});
  await store.addWaiting("m1", "p2", {});
  await store.updateRoom("m1", { status: "ended" });
  await store.endRoom("m1");
  assert.strictEqual((await store.getRoom("m1")).status, "ended");
  assert.strictEqual(await store.countParticipants("m1"), 0);
  assert.deepStrictEqual(await store.listWaiting("m1"), {});
  assert.strictEqual(await store.resolveMeetingId("owner"), null);
});

test("an aliased SFU name never replaces the real identity in the roster", async function () {
  const presenceMod = require("../presence.js");
  const redis = new FakeRedis();
  const storeFor = function () { return new roomsMod.RoomStore({ redis: redis, tenant: TENANT_A }); };
  const store = storeFor();
  await store.createRoom({ meetingId: "m1", ownerKey: "owner" });
  await store.putParticipantIntent("m1", "p1", { displayName: "Jane Guest", email: "jane@example.com" });
  const presence = new presenceMod.PresenceCoordinator({
    redis: redis,
    storeFor: storeFor,
    gateway: { listParticipants: async function () { return { participants: [] }; } },
  });

  await presence.applyEvent(TENANT_A, {
    id: "evt-1",
    tenantKey: TENANT_A.tenantKey,
    type: "participant_joined",
    meetingId: "m1",
    // What a name-privacy policy makes the SFU report. Trusting it here would
    // write the alias into the authoritative roster, meet_participants, every
    // audit row and transcript attribution — unrecoverably.
    participant: { identity: "p1", name: "Participant 1" },
  }, "evt-1");

  const participant = await store.getParticipant("m1", "p1");
  assert.strictEqual(participant.displayName, "Jane Guest");
  assert.strictEqual(participant.email, "jane@example.com");
});

test("an unconfigured room reports a permissive policy at version 1", async function () {
  const store = new roomsMod.RoomStore({ redis: new FakeRedis(), tenant: TENANT_A });
  await store.createRoom({ meetingId: "m1" });
  const policy = await store.getPolicy("m1");
  assert.strictEqual(policy.version, 1);
  assert.strictEqual(policy.cameraLocked, false);
  assert.strictEqual(policy.microphoneLocked, false);
  assert.strictEqual(policy.hideParticipantNames, "off");
  assert.strictEqual(policy.cohostsMayManageMedia, true);
});

test("a publish allow-list keeps screen share and never inverts at zero entries", function () {
  const locked = { cameraLocked: true, microphoneLocked: true };
  const both = roomsMod.publishSourcesFor(locked, null, "participant");
  // Screen share always survives: locking cameras must not stop somebody sharing
  // a document. And the list is additive, so "nothing allowed" is a one-entry
  // list rather than the empty list LiveKit reads as "unrestricted".
  assert.deepStrictEqual(both, ["screenShare"]);
  assert.deepStrictEqual(
    roomsMod.publishSourcesFor(locked, { microphone: true }, "participant"),
    ["microphone", "screenShare"]
  );
  assert.deepStrictEqual(
    roomsMod.publishSourcesFor(locked, null, "host"),
    ["microphone", "camera", "screenShare"]
  );
  assert.strictEqual(roomsMod.sourceLockedFor(locked, null, "participant", "camera"), true);
  assert.strictEqual(roomsMod.sourceLockedFor(locked, { camera: true }, "participant", "camera"), false);
  assert.strictEqual(roomsMod.sourceLockedFor(locked, null, "cohost", "camera"), false);
});

test("a policy that cannot be parsed falls back to permissive, never to locked", function () {
  const policy = roomsMod.normalisePolicy(
    { cameraLocked: "definitely", hideParticipantNames: "maybe", version: "not-a-number" },
    undefined
  );
  assert.strictEqual(policy.cameraLocked, false);
  assert.strictEqual(policy.hideParticipantNames, "off");
  assert.strictEqual(policy.version, 1);
});

test("aliases are assigned once, per participant, and never renumbered", async function () {
  const store = new roomsMod.RoomStore({ redis: new FakeRedis(), tenant: TENANT_A });
  await store.createRoom({ meetingId: "m1" });
  const first = await store.participantAlias("m1", "p1");
  const second = await store.participantAlias("m1", "p2");
  assert.strictEqual(first, "Participant 1");
  assert.strictEqual(second, "Participant 2");
  assert.strictEqual(await store.participantAlias("m1", "p1"), "Participant 1");
  // p1 leaving must not turn p2 into "Participant 1" for everyone watching.
  await store.removeParticipant("m1", "p1");
  assert.strictEqual(await store.participantAlias("m1", "p2"), "Participant 2");
  assert.deepStrictEqual(await store.listAliases("m1"), { p1: "Participant 1", p2: "Participant 2" });
});

test("live room keys expire without a global scan", async function () {
  let now = 1000;
  const redis = new FakeRedis({ now: function () { return now; } });
  const store = new roomsMod.RoomStore({ redis: redis, tenant: TENANT_A, now: function () { return now; } });
  await store.createRoom({ meetingId: "m1", slug: "code" });
  now += 13 * 60 * 60 * 1000;
  assert.strictEqual(await store.getRoom("m1"), null);
  assert.strictEqual(redis.liveKeyCount(), 0);
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
