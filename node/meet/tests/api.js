/** Contract tests for `/meet/api/v1`. */

const assert = require("assert");
const { FakeRedis } = require("./fake-redis.js");
const authMod = require("../auth.js");
const roomsMod = require("../rooms.js");
const routesMod = require("../routes.js");
const rateLimitMod = require("../rate-limit.js");
const tenantMod = require("../tenant.js");

const DOMAIN_A = "11111111-1111-1111-1111-111111111111";
const DOMAIN_B = "22222222-2222-2222-2222-222222222222";
const HOST_A = "tenanta.ringq.ai";
const GUEST_SECRET = "guest-token-hmac-secret";
const HOST_HEADERS = { authorization: "Bearer valid-host-bearer" };
const tests = [];
function test(name, fn) { tests.push({ name: name, fn: fn }); }

function fakeResponse() {
  return {
    statusCode: 0,
    headers: {},
    body: null,
    headersSent: false,
    setHeader: function (key, value) { this.headers[key] = value; },
    end: function (payload) {
      this.headersSent = true;
      // Binary responses exist now (the lobby banner), and a fake that insists
      // every body is JSON would fail them with a parse error that reads like
      // a broken route.
      if (Buffer.isBuffer(payload)) {
        this.body = payload;
        return;
      }
      this.body = payload ? JSON.parse(payload) : null;
    },
  };
}

function harness(opts) {
  const o = opts || {};
  const redis = new FakeRedis();
  const tenantConfigs = {};
  tenantConfigs[HOST_A] = { domainUuid: DOMAIN_A };
  tenantConfigs["tenantb.ringq.ai"] = { domainUuid: DOMAIN_B };
  const resolver = new tenantMod.TenantResolver({
    loadConfig: function (host) { return tenantConfigs[host] || null; },
  });
  const gatewayCalls = [];
  const gateway = {
    health: async function () { return o.gatewayHealthy !== false; },
    mintToken: async function (tenant, request) {
      gatewayCalls.push({ operation: "token", tenantKey: tenant.tenantKey, request: request });
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
      return { token: "opaque-media-token", serverUrl: "wss://media.ringq.test", expiresIn: 300 };
    },
    removeParticipant: async function (tenant, meetingId, identity) {
      gatewayCalls.push({ operation: "kick", tenantKey: tenant.tenantKey, meetingId: meetingId, identity: identity });
      if (o.gatewayParticipantMissing) {
        throw Object.assign(new Error("The participant is not in the room"), { code: "participant_not_found", statusCode: 404 });
      }
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
    },
    updateParticipantRole: async function (tenant, meetingId, identity, role, avatarUrl) {
      gatewayCalls.push({
        operation: "role",
        tenantKey: tenant.tenantKey,
        meetingId: meetingId,
        identity: identity,
        role: role,
        avatarUrl: avatarUrl,
      });
      if (o.gatewayParticipantMissing) {
        throw Object.assign(new Error("The participant is not in the room"), { code: "participant_not_found", statusCode: 404 });
      }
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
    },
    updateParticipantPublishSources: async function (tenant, meetingId, identity, publishSources) {
      gatewayCalls.push({
        operation: "permissions",
        tenantKey: tenant.tenantKey,
        meetingId: meetingId,
        identity: identity,
        publishSources: publishSources,
      });
      if (o.gatewayParticipantMissing) {
        throw Object.assign(new Error("The participant is not in the room"), { code: "participant_not_found", statusCode: 404 });
      }
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
    },
    updateParticipantName: async function (tenant, meetingId, identity, name) {
      gatewayCalls.push({
        operation: "name",
        tenantKey: tenant.tenantKey,
        meetingId: meetingId,
        identity: identity,
        name: name,
      });
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
    },
    muteParticipantTrack: async function (tenant, meetingId, identity, source) {
      gatewayCalls.push({ operation: "mute", tenantKey: tenant.tenantKey, meetingId: meetingId, identity: identity, source: source });
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
      return { source: source, muted: true, changed: o.alreadyMuted !== true };
    },
    broadcastRoomData: async function (tenant, meetingId, topic, payload, destinationIdentities, delivery) {
      gatewayCalls.push({
        operation: "data",
        tenantKey: tenant.tenantKey,
        meetingId: meetingId,
        topic: topic,
        payload: payload,
        destinationIdentities: destinationIdentities,
        delivery: delivery,
      });
      if (o.gatewayBroadcastFailure) throw Object.assign(new Error("broadcast failed"), { statusCode: 502 });
    },
    endMeeting: async function (tenant, meetingId) {
      gatewayCalls.push({ operation: "end", tenantKey: tenant.tenantKey, meetingId: meetingId });
      if (o.gatewayFailure) throw Object.assign(new Error("gateway failed"), { code: "GATEWAY_BAD_RESPONSE", statusCode: 502 });
    },
  };
  const hostAuth = new authMod.HostAuthenticator({
    lookupBearer: async function (token) {
      if (token === "valid-host-bearer") {
        return {
          ownerKey: "owner-1",
          displayName: "Jane Owner",
          domainUuid: DOMAIN_A,
          canHost: true,
          avatarUrl: Object.prototype.hasOwnProperty.call(o, "hostAvatarUrl")
            ? o.hostAvatarUrl
            : "https://tenant-a.ringq.test:8443/avr/jane.png",
        };
      }
      if (token === "valid-host-bearer-2") {
        return { ownerKey: "owner-2", displayName: "Other Owner", domainUuid: DOMAIN_A, canHost: true };
      }
      if (token === "valid-host-bearer-tenant-b") {
        return { ownerKey: "owner-1", displayName: "Tenant B Owner", domainUuid: DOMAIN_B, canHost: true };
      }
      return null;
    },
  });
  const moderation = [];
  // A durable table, modelled the way the real one behaves: one row per
  // (domain, external reference), enforced by a UNIQUE index. Modelling the
  // uniqueness is the point — the tenant.33 defect passed every fake-store
  // guard precisely because nothing here remembered rows between creates.
  const durableRows = o.durableRows || new Map();
  const durableKey = function (tenant, ref) { return tenant.domainUuid + "|" + ref; };
  const recorder = {
    durableRows: durableRows,
    meetingCreated: async function (tenant, meeting) {
      if (!meeting.externalReference) return;
      const key = durableKey(tenant, meeting.externalReference);
      // ON CONFLICT DO NOTHING: an existing row is left exactly as it is.
      if (durableRows.has(key)) return;
      durableRows.set(key, {
        id: meeting.meetingId,
        slug: meeting.slug || "",
        status: "created",
        endedAt: null,
        title: meeting.title || "",
        // The real column is CHECK (meet_type IN ('instant','scheduled')), so
        // the fake stores what the real INSERT would and nothing wider.
        meetType: meeting.meetType === "scheduled" ? "scheduled" : "instant",
        // settings jsonb. Modelled as a real object because every write to it
        // is a MERGE, and a fake that replaced would pass the tests a
        // replacing implementation fails in production.
        settings: Object.assign({}, meeting.lobby ? { lobby: meeting.lobby } : {}),
      });
    },
    meetingLobby: async function (tenant, meeting) {
      for (const row of durableRows.values()) {
        if (row.id !== meeting.meetingId) continue;
        row.settings = row.settings || {};
        row.settings.lobby = Object.assign({}, row.settings.lobby || {}, meeting.lobby || {});
      }
    },
    meetingLobbyBanner: async function (tenant, meeting) {
      for (const row of durableRows.values()) {
        if (row.id !== meeting.meetingId) continue;
        row.settings = row.settings || {};
        row.settings.lobby = Object.assign({}, row.settings.lobby || {}, { banner: meeting.banner || {} });
      }
    },
    meetingLobbyPhoto: async function (tenant, meeting) {
      for (const row of durableRows.values()) {
        if (row.id !== meeting.meetingId) continue;
        row.settings = row.settings || {};
        // Merged under its own key, never replacing `lobby`: a photo uploaded
        // today must not take the banner uploaded last week with it.
        row.settings.lobby = Object.assign({}, row.settings.lobby || {}, { photo: meeting.photo || {} });
      }
    },
    readLobby: async function (tenant, meetingId) {
      for (const row of durableRows.values()) {
        if (row.id === meetingId) return (row.settings && row.settings.lobby) || null;
      }
      return null;
    },
    findMeetingForLookup: async function (tenant, code) {
      for (const [key, row] of durableRows.entries()) {
        const reference = key.slice(String(tenant.domainUuid).length + 1);
        if (key.indexOf(tenant.domainUuid + "|") !== 0) continue;
        if (row.slug !== code && reference !== code) continue;
        return {
          meetingId: row.id,
          slug: row.slug,
          title: row.title || "",
          status: row.status,
          meetType: row.meetType || "instant",
          lobby: (row.settings && row.settings.lobby) || null,
        };
      }
      return null;
    },
    meetingEnded: async function () {},
    meetingReopened: async function (tenant, meeting) {
      for (const row of durableRows.values()) {
        if (row.id === meeting.meetingId) {
          row.status = "created";
          row.endedAt = null;
        }
      }
    },
    findMeetingByExternalReference: async function (tenant, ref) {
      const row = durableRows.get(durableKey(tenant, ref));
      return row ? { meetingId: row.id, slug: row.slug } : null;
    },
    moderationEvent: async function (tenant, event) { moderation.push(event); },
  };
  const collaboration = o.collaboration || null;
  const storeFor = function (tenant) { return new roomsMod.RoomStore({ redis: redis, tenant: tenant }); };
  const router = routesMod.createRouter({
    resolver: resolver,
    storeFor: storeFor,
    hostAuth: hostAuth,
    invites: o.noInvites ? undefined : new authMod.InviteStore({ redis: redis }),
    guestSecret: GUEST_SECRET,
    gateway: gateway,
    recorder: recorder,
    rateLimiter: o.rateLimiter,
    reactionLimiter: o.reactionLimiter,
    handLimiter: o.handLimiter,
    accessRequestLimiter: o.accessRequestLimiter,
    whiteboardLimiter: o.whiteboardLimiter,
    collaboration: collaboration,
    attachments: o.attachments,
    documents: o.documents,
    capture: o.capture,
    presence: o.presence,
    callbackAuth: o.callbackAuth,
    logger: o.logger,
    transcriberSecret: o.transcriberSecret,
    features: o.features,
  });
  return {
    redis: redis,
    resolver: resolver,
    gatewayCalls: gatewayCalls,
    moderation: moderation,
    durableRows: durableRows,
    collaboration: collaboration,
    storeFor: storeFor,
    call: async function (method, path, requestOpts) {
      const request = requestOpts || {};
      // `raw` drives the routes that read the request stream themselves. The
      // chunks are replayed in the order the handler subscribes, which is the
      // order readRawBody subscribes in: data, then end.
      const rawChunks = request.raw === undefined
        ? null
        : (Array.isArray(request.raw) ? request.raw : [request.raw]);
      const req = {
        method: method,
        headers: Object.assign({ host: request.host || HOST_A }, request.headers || {}),
        body: request.body || {},
        socket: { remoteAddress: "127.0.0.1" },
        setTimeout: function () {},
        on: function (event, handler) {
          if (event === "data" && rawChunks) rawChunks.forEach(function (chunk) { handler(chunk); });
          if (event === "end" && rawChunks) handler();
          return this;
        },
      };
      const res = fakeResponse();
      const handled = await router.handle(req, res, path);
      return { handled: handled, status: res.statusCode, body: res.body, headers: res.headers };
    },
  };
}

async function createMeeting(h, body, headers) {
  return h.call("POST", "/rooms", { headers: headers || HOST_HEADERS, body: body || {} });
}

async function seedGuest(h, body) {
  const created = await createMeeting(h, body);
  const join = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Guest One", email: "guest@example.com" },
  });
  return { meetingId: created.body.meetingId, join: join };
}

async function tenantStore(h) {
  const tenant = await h.resolver.resolve({ headers: { host: HOST_A } });
  return h.storeFor(tenant);
}

function fakeCollaboration() {
  const meetings = {};
  const messages = {};
  const batches = [];
  let cursor = 0;
  let presentation = {
    version: 0, sequence: 0, status: "idle", assetId: null, annotationMode: "presenter",
  };
  /** Annotation documents, keyed "<assetId>:<page>". */
  const annotations = {};
  const out = {
    meetings: meetings,
    messages: messages,
    batches: batches,
    health: async function () { return { chat: true, whiteboard: true }; },
    getMeeting: async function (_tenant, meetingId) { return meetings[meetingId] || null; },
    listHistory: async function (_tenant, ownerKey) {
      return {
        meetings: Object.keys(meetings).filter(function (id) {
          return meetings[id].owner_key === ownerKey && meetings[id].status === "ended";
        }).map(function (id) { return { meetingId: id, hasChat: true, hasWhiteboard: false }; }),
        nextCursor: null,
      };
    },
    listMessages: async function () {
      return { messages: Object.values(messages), cursor: String(cursor), hasOlder: false, oldestCursor: null };
    },
    getMessage: async function (_tenant, _meetingId, id) { return messages[id] || null; },
    createMessage: async function (_tenant, input) {
      const existing = Object.values(messages).find(function (m) {
        return m.clientMessageId === input.clientMessageId && m.participantId === input.participantId;
      });
      if (existing) return { message: existing, duplicate: true };
      cursor++;
      const id = "10000000-0000-4000-8000-" + String(cursor).padStart(12, "0");
      messages[id] = {
        id: id, clientMessageId: input.clientMessageId, participantId: input.participantId,
        senderName: input.senderName, body: input.body, replyToId: input.replyToId,
        attachments: [], createdSequence: cursor, cursor: String(cursor), version: 1,
        sentAt: new Date().toISOString(), editedAt: null, deletedAt: null, deleted: false,
      };
      return { message: messages[id], duplicate: false };
    },
    editMessage: async function (_tenant, input) {
      const message = messages[input.messageId];
      if (!message || message.participantId !== input.participantId || message.version !== input.expectedVersion) {
        throw Object.assign(new Error("Message changed"), { name: "CollaborationError", code: "VERSION_CONFLICT", statusCode: 409 });
      }
      cursor++; message.body = input.body; message.version++; message.cursor = String(cursor); return message;
    },
    deleteMessage: async function (_tenant, input) {
      const message = messages[input.messageId];
      if (!message || message.version !== input.expectedVersion) {
        throw Object.assign(new Error("Message changed"), { name: "CollaborationError", code: "VERSION_CONFLICT", statusCode: 409 });
      }
      cursor++; message.body = ""; message.version++; message.cursor = String(cursor);
      message.deleted = true; message.deletedAt = new Date().toISOString(); return message;
    },
    getFileAsset: async function (_tenant, _meetingId, assetId) {
      return {
        id: assetId,
        attachmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
        name: "slides.pdf",
        mime: "application/pdf",
        status: "ready",
        pageCount: 2,
        pages: [],
      };
    },
    presentationState: async function () { return Object.assign({}, presentation); },
    /** Test hook: put a deck on the stage without going through start. */
    setPresentationForTest: function (next) { presentation = Object.assign({}, presentation, next); },
    applyPresentationAction: async function (_tenant, input) {
      if (input.expectedVersion !== presentation.version) {
        throw Object.assign(new Error("The presentation changed"), {
          name: "CollaborationError", code: "VERSION_CONFLICT", statusCode: 409,
        });
      }
      if (input.kind === "start") {
        if (presentation.status === "active") {
          throw Object.assign(new Error("Another file presentation is active"), {
            name: "CollaborationError", code: "PRESENTATION_ACTIVE", statusCode: 409,
          });
        }
        presentation = {
          version: 1, sequence: 1, status: "active", assetId: input.assetId,
          presenterId: input.actorId, presenterName: input.actorName,
          pageNumber: 1, pageCount: 2, name: "slides.pdf",
          annotationMode: "presenter",
        };
      } else if (input.kind === "page") {
        if (presentation.presenterId !== input.actorId && !input.isModerator) {
          throw Object.assign(new Error("Only the presenter or a moderator can change pages"), {
            name: "CollaborationError", code: "PRESENTATION_FORBIDDEN", statusCode: 403,
          });
        }
        presentation = Object.assign({}, presentation, {
          version: presentation.version + 1,
          sequence: presentation.sequence + 1,
          pageNumber: input.pageNumber,
        });
      } else if (input.kind === "mode") {
        if (presentation.presenterId !== input.actorId && !input.isModerator) {
          throw Object.assign(new Error("Only the presenter or a moderator can change who may draw"), {
            name: "CollaborationError", code: "PRESENTATION_FORBIDDEN", statusCode: 403,
          });
        }
        presentation = Object.assign({}, presentation, {
          version: presentation.version + 1,
          sequence: presentation.sequence + 1,
          annotationMode: input.annotationMode,
        });
      } else if (input.kind === "stop") {
        if (presentation.presenterId !== input.actorId && !input.isModerator) {
          throw Object.assign(new Error("Only presenter or moderator may stop"), {
            name: "CollaborationError", code: "PRESENTATION_FORBIDDEN", statusCode: 403,
          });
        }
        presentation = {
          version: presentation.version + 1,
          sequence: presentation.sequence + 1,
          status: "idle",
          assetId: null,
          annotationMode: "presenter",
        };
      }
      return Object.assign({ duplicate: false }, presentation);
    },
    annotations: annotations,
    presentationAnnotations: async function (_tenant, _meetingId, assetId, pageNumber) {
      const held = annotations[assetId + ":" + pageNumber];
      return held || {
        assetId: assetId, pageNumber: pageNumber, version: 0, sequence: 0,
        document: { elements: {} },
      };
    },
    applyPresentationAnnotationBatch: async function (_tenant, input) {
      const key = input.assetId + ":" + input.pageNumber;
      const held = annotations[key] || {
        assetId: input.assetId, pageNumber: input.pageNumber, version: 0, sequence: 0,
        document: { elements: {} },
      };
      annotations[key] = Object.assign({}, held, {
        version: held.version + 1,
        sequence: held.sequence + 1,
        lastOperations: input.operations,
        lastActor: input.actorId,
      });
      return Object.assign({ duplicate: false }, annotations[key]);
    },
    presentationAnnotationUndoRedo: async function (_tenant, input) {
      const key = input.assetId + ":" + input.pageNumber;
      const held = annotations[key];
      if (!held) {
        throw Object.assign(new Error("Nothing to " + input.kind), {
          name: "CollaborationError",
          code: "NOTHING_TO_" + input.kind.toUpperCase(),
          statusCode: 409,
        });
      }
      annotations[key] = Object.assign({}, held, { version: held.version + 1 });
      return Object.assign({ duplicate: false }, annotations[key]);
    },
    clearPresentationAnnotations: async function (_tenant, input) {
      const key = input.assetId + ":" + input.pageNumber;
      annotations[key] = {
        assetId: input.assetId, pageNumber: input.pageNumber,
        version: ((annotations[key] || {}).version || 0) + 1, sequence: 1,
        document: { elements: {} }, cleared: true,
      };
      return Object.assign({ duplicate: false }, annotations[key]);
    },
    whiteboardState: async function (_tenant, _meetingId, _after, initialLocked) {
      // Recorded rather than acted on: the real repository honours this only in
      // its `ON CONFLICT DO NOTHING` insert, which no fake can model, so what
      // is worth asserting is that the router resolved and passed it.
      out.initialLocked = initialLocked;
      return { version: batches.length, sequence: batches.length, locked: false, resetRequired: true, checkpoint: { sequence: 0, document: { elements: {} } }, batches: batches };
    },
    applyWhiteboardBatch: async function (_tenant, input) {
      const existing = batches.find(function (batch) {
        return batch.id === input.clientBatchId && batch.actorId === input.actorId;
      });
      if (existing) return Object.assign({}, existing, { duplicate: true });
      const batch = { id: input.clientBatchId, kind: "operations", sequence: batches.length + 1,
        version: batches.length + 1, actorId: input.actorId, operations: input.operations, duplicate: false };
      batches.push(batch); return batch;
    },
    whiteboardUndoRedo: async function (_tenant, input) {
      const batch = { id: input.clientBatchId, kind: input.kind, sequence: batches.length + 1,
        version: batches.length + 1, actorId: input.actorId, operations: [] };
      batches.push(batch); return batch;
    },
    clearWhiteboard: async function (_tenant, input) {
      return this.applyWhiteboardBatch(_tenant, Object.assign({}, input, { operations: [{ type: "clear" }] }));
    },
    setWhiteboardLock: async function (_tenant, input) {
      return { id: input.clientBatchId, kind: "lock", sequence: batches.length + 1,
        version: batches.length + 1, actorId: input.actorId, operations: [], locked: input.locked };
    },
    setWhiteboardPresenting: async function (_tenant, input) {
      return { id: input.clientBatchId, kind: "present", sequence: batches.length + 1,
        version: batches.length + 1, actorId: input.actorId, operations: [],
        presenting: input.presenting,
        presenterId: input.presenting ? input.actorId : null,
        presenterName: input.presenting ? (input.actorName || null) : null,
        duplicate: false };
    },
  };
  return out;
}

test("capabilities fail closed when the gateway is unhealthy", async function () {
  const healthy = await harness().call("GET", "/capabilities");
  assert.strictEqual(healthy.body.apiVersion, "v1");
  assert.strictEqual(healthy.body.enabled, true);
  assert.strictEqual(healthy.body.features.cohostManagement, true);
  assert.strictEqual(healthy.body.features.cohostWaitingRoom, true);
  assert.strictEqual(healthy.body.features.mobileLayoutV2, true);
  assert.strictEqual(healthy.body.features.reactions, true);
  assert.strictEqual(healthy.body.features.hostRejoin, true);
  const unhealthy = await harness({ gatewayHealthy: false }).call("GET", "/capabilities");
  assert.strictEqual(unhealthy.status, 200);
  assert.strictEqual(unhealthy.body.enabled, false);
  assert.strictEqual(unhealthy.body.failure.code, "media_unavailable");
});

test("active meetings are host-owned, live, sorted and presence-backed", async function () {
  const h = harness();
  const tenantA = await h.resolver.resolve({ headers: { host: HOST_A } });
  const tenantB = await h.resolver.resolve({ headers: { host: "tenantb.ringq.ai" } });
  const storeA = h.storeFor(tenantA);
  const storeB = h.storeFor(tenantB);

  await storeA.createRoom({ meetingId: "older", slug: "older-code", ownerKey: "owner-1", title: "Older", status: "live" });
  await storeA.updateRoom("older", { startedAt: 1000 });
  await storeA.markActive("older");
  await storeA.addParticipant("older", "guest-1", { displayName: "Guest", role: "participant" });

  await storeA.createRoom({ meetingId: "newer", slug: "newer-code", ownerKey: "owner-1", title: "Newer", status: "live" });
  await storeA.updateRoom("newer", { startedAt: 2000 });
  await storeA.markActive("newer");
  await storeA.addParticipant("newer", "host-owner-1", { displayName: "Jane", role: "host" });
  await storeA.addParticipant("newer", "guest-2", { displayName: "Guest", role: "participant" });

  await storeA.createRoom({ meetingId: "created-only", slug: "created-code", ownerKey: "owner-1", status: "created" });
  await storeA.markActive("created-only");
  await storeA.createRoom({ meetingId: "other-owner", slug: "other-code", ownerKey: "owner-2", status: "live" });
  await storeA.markActive("other-owner");
  await storeB.createRoom({ meetingId: "other-tenant", slug: "tenant-code", ownerKey: "owner-1", status: "live" });
  await storeB.markActive("other-tenant");

  const result = await h.call("GET", "/rooms/active", { headers: HOST_HEADERS });
  assert.strictEqual(result.status, 200);
  assert.deepStrictEqual(result.body.meetings.map(function (room) { return room.meetingId; }), ["newer", "older"]);
  assert.strictEqual(result.body.meetings[0].participantCount, 2);
  assert.strictEqual(result.body.meetings[0].hostPresent, true);
  assert.strictEqual(result.body.meetings[1].hostPresent, false);
  assert.strictEqual(result.body.meetings[0].joinUrl, "https://" + HOST_A + "/meet/m/newer-code");
  assert.ok(Number.isFinite(Date.parse(result.body.meetings[0].serverTime)));
  assert.strictEqual(result.body.meetings[0].serverTime, result.body.meetings[1].serverTime);
});

test("active meetings require host authentication and are limited to twenty", async function () {
  const h = harness();
  const tenant = await h.resolver.resolve({ headers: { host: HOST_A } });
  const store = h.storeFor(tenant);
  for (let i = 0; i < 22; i++) {
    const id = "meeting-" + i;
    await store.createRoom({ meetingId: id, slug: "code-" + i, ownerKey: "owner-1", status: "live" });
    await store.updateRoom(id, { startedAt: i + 1 });
    await store.markActive(id);
  }
  const unauthorized = await h.call("GET", "/rooms/active");
  assert.strictEqual(unauthorized.status, 401);
  const result = await h.call("GET", "/rooms/active", { headers: HOST_HEADERS });
  assert.strictEqual(result.body.meetings.length, 20);
  assert.strictEqual(result.body.meetings[0].meetingId, "meeting-21");
});

test("room creation returns the tenant SPA link", async function () {
  const result = await createMeeting(harness(), { title: "Standup" });
  assert.strictEqual(result.status, 200);
  assert.ok(result.body.joinUrl.indexOf("https://" + HOST_A + "/meet/m/") === 0);
});

test("room lookup returns an optional canonical timer anchor", async function () {
  const h = harness();
  const created = await createMeeting(h, { title: "Timer test" });
  const before = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(before.status, 200);
  assert.strictEqual(before.body.startedAt, null);
  assert.ok(!Number.isNaN(Date.parse(before.body.serverTime)));

  const tenant = await h.resolver.resolve({ headers: { host: HOST_A } });
  const store = h.storeFor(tenant);
  await store.markLive(created.body.meetingId, 1000);
  await store.markLive(created.body.meetingId, 2000);

  const live = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(live.body.startedAt, new Date(1000).toISOString());
  assert.ok(Date.parse(live.body.serverTime) >= Date.parse(live.body.startedAt));
});

test("external reference retries reuse one room", async function () {
  const h = harness();
  const first = await createMeeting(h, { meetType: "booking", externalReference: "booking:42" });
  const second = await createMeeting(h, { meetType: "booking", externalReference: "booking:42" });
  assert.strictEqual(second.body.meetingId, first.body.meetingId);
  assert.strictEqual(second.body.reused, true);
  const tenant = await h.resolver.resolve({ headers: { host: HOST_A } });
  // The live room keeps what was asked for and the durable row records the
  // narrowed value. Two stores, two different questions: a lobby has to tell a
  // webinar from a booking, and `meet_meetings.meet_type` has a CHECK that
  // admits neither word.
  assert.strictEqual((await h.storeFor(tenant).getRoom(first.body.meetingId)).meetType, "booking");
  const durable = Array.from(h.durableRows.values())[0];
  assert.strictEqual(durable.meetType, "scheduled");
});

test("a live room is still reused, so a double press is not two meetings", async function () {
  // The guard that stops the reopen fix being "always mint a fresh room": the
  // Copy link button and the Start meet button both POST /rooms with the same
  // external reference, and pressing either twice must land in one meeting.
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:live" });
  const store = await tenantStore(h);
  await store.markLive(first.body.meetingId, 1000);

  const second = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:live" });
  assert.strictEqual(second.body.meetingId, first.body.meetingId);
  assert.strictEqual(second.body.reused, true);
});

test("a room that has ended reopens as the SAME meeting", async function () {
  // tenant.33 minted a NEW meeting id here. meetingCreated is
  // INSERT … ON CONFLICT DO NOTHING and both indexes are UNIQUE, so that
  // session got no durable row at all — and 19 foreign keys then rejected its
  // chat, whiteboard, recording and transcript writes, silently.
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:ended" });
  const store = await tenantStore(h);
  await store.updateRoom(first.body.meetingId, { status: "ended", endedAt: Date.now() });
  await store.endRoom(first.body.meetingId);

  const second = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:ended" });
  assert.strictEqual(second.body.meetingId, first.body.meetingId, "the meeting keeps its identity");
  assert.strictEqual(second.body.reopened, true);
  assert.strictEqual((await store.getRoom(second.body.meetingId)).status, "created");
});

test("a reopened meeting still has exactly one durable row, marked live again", async function () {
  // The behaviour the 19 foreign keys depend on, stated as a row rather than
  // as an id: one row per (domain, external reference), and its end markers
  // cleared because the meeting is happening now.
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:row" });
  const store = await tenantStore(h);
  await store.updateRoom(first.body.meetingId, { status: "ended", endedAt: Date.now() });
  await store.endRoom(first.body.meetingId);
  // What presence._ended writes durably when the SFU says the room finished.
  // Without this the guard is vacuous: the row would still read 'created' and
  // any reopen, including one that never touches the row, would pass.
  for (const row of h.durableRows.values()) {
    if (row.id === first.body.meetingId) { row.status = "ended"; row.endedAt = Date.now(); }
  }

  await createMeeting(h, { meetType: "scheduled", externalReference: "sched:row" });

  const rows = Array.from(h.durableRows.values()).filter(function (r) { return r.id === first.body.meetingId; });
  assert.strictEqual(rows.length, 1, "one row per booking, never a second");
  assert.strictEqual(rows[0].status, "created");
  assert.strictEqual(rows[0].endedAt, null, "the previous end must not describe this session");
});

test("the link survives Redis forgetting the room entirely", async function () {
  // The case an emailed invitation actually hits: the room's 12h TTL expires
  // long before a meeting scheduled next week. Redis then knows nothing, and
  // before this change the control plane minted a brand-new slug — killing
  // every link already handed out.
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:expired" });
  const store = await tenantStore(h);
  const slug = (await store.getRoom(first.body.meetingId)).slug;

  // Everything Redis held, gone.
  await h.redis.del("meet:" + (await h.resolver.resolve({ headers: { host: HOST_A } })).tenantKey + ":room:" + first.body.meetingId);
  await store.endRoom(first.body.meetingId);
  for (const key of Object.keys(h.redis.store ? Object.fromEntries(h.redis.store) : {})) {
    if (key.indexOf("external") !== -1 || key.indexOf("slug") !== -1 || key.indexOf("room:") !== -1) {
      await h.redis.del(key);
    }
  }

  const second = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:expired" });
  assert.strictEqual(second.body.joinUrl, first.body.joinUrl, "the link is permanent");
  assert.strictEqual((await store.getRoom(second.body.meetingId)).slug, slug);
  assert.strictEqual(second.body.meetingId, first.body.meetingId, "and so is the meeting");
});

test("with no durable record and no room, both are minted fresh", async function () {
  const h = harness();
  const created = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:new" });
  assert.strictEqual(created.status, 200);
  assert.strictEqual(created.body.reused, false);
  assert.strictEqual(created.body.reopened, false);
  assert.ok(created.body.joinUrl.indexOf("/meet/m/") !== -1);
});

test("a recorder that cannot answer still lets the meeting start", async function () {
  // Fail-soft: a durable read must never be the thing that stops a host
  // starting a meeting.
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:soft" });
  assert.strictEqual(first.status, 200);
  assert.ok(first.body.meetingId);
});

test("reopening keeps the link that was already handed out", async function () {
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:link" });
  const store = await tenantStore(h);
  await store.updateRoom(first.body.meetingId, { status: "ended", endedAt: Date.now() });
  await store.endRoom(first.body.meetingId);

  const second = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:link" });
  assert.strictEqual(second.body.joinUrl, first.body.joinUrl);
});

test("the old link resolves to the reopened meeting", async function () {
  const h = harness();
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:resolve" });
  const store = await tenantStore(h);
  const slug = (await store.getRoom(first.body.meetingId)).slug;
  await store.updateRoom(first.body.meetingId, { status: "ended", endedAt: Date.now() });
  await store.endRoom(first.body.meetingId);

  const second = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:resolve" });
  assert.strictEqual(await store.resolveMeetingId(slug), second.body.meetingId);
  assert.strictEqual(await store.resolveExternalReference("sched:resolve"), second.body.meetingId);
});

test("a missed room is terminal too", async function () {
  const h = harness();
  const first = await createMeeting(h, { meetType: "booking", externalReference: "booking:missed" });
  const store = await tenantStore(h);
  await store.updateRoom(first.body.meetingId, { status: "missed" });
  await store.endRoom(first.body.meetingId);

  const second = await createMeeting(h, { meetType: "booking", externalReference: "booking:missed" });
  assert.strictEqual(second.body.reopened, true);
  assert.strictEqual((await store.getRoom(second.body.meetingId)).status, "created");
});

test("host routes accept the short-lived opaque RingQ bearer", async function () {
  const result = await createMeeting(harness(), {}, { authorization: "Bearer valid-host-bearer" });
  assert.strictEqual(result.status, 200);
});

test("missing host authentication has a structured compatibility error", async function () {
  const result = await harness().call("POST", "/rooms", { body: {} });
  assert.strictEqual(result.status, 401);
  assert.strictEqual(result.body.code, "unauthorized");
  assert.ok(result.body.error);
});

test("media token is delegated to the gateway and does not create attendance", async function () {
  const h = harness();
  const created = await createMeeting(h);
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/token", { headers: HOST_HEADERS });
  assert.strictEqual(token.status, 200);
  assert.strictEqual(token.body.serverUrl, "wss://media.ringq.test");
  assert.strictEqual(h.gatewayCalls[0].request.role, "host");
  const tenant = await h.resolver.resolve({ headers: { host: HOST_A } });
  assert.strictEqual(await h.storeFor(tenant).countParticipants(created.body.meetingId), 0);
  assert.ok(await h.storeFor(tenant).getParticipantIntent(created.body.meetingId, token.body.participantId));
});

test("waiting-room polling returns stable structured codes", async function () {
  const h = harness();
  const seeded = await seedGuest(h);
  const pending = await h.call("POST", "/rooms/" + seeded.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + seeded.join.body.guestToken },
  });
  assert.strictEqual(pending.status, 403);
  assert.strictEqual(pending.body.code, "not_admitted");
  assert.strictEqual(pending.body.error, "Not admitted");
  await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: seeded.join.body.participantId, decision: "declined" },
  });
  const declined = await h.call("POST", "/rooms/" + seeded.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + seeded.join.body.guestToken },
  });
  assert.strictEqual(declined.body.code, "declined");
});

test("admitted guest receives participant-only gateway token intent", async function () {
  const h = harness();
  const seeded = await seedGuest(h);
  await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: seeded.join.body.participantId, decision: "admitted" },
  });
  const token = await h.call("POST", "/rooms/" + seeded.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + seeded.join.body.guestToken },
  });
  assert.strictEqual(token.status, 200);
  assert.strictEqual(h.gatewayCalls[0].request.role, "participant");
});

test("a second guest-token redemption keeps the guest's name", async function () {
  const h = harness();
  const seeded = await seedGuest(h);
  await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: seeded.join.body.participantId, decision: "admitted" },
  });
  const auth = { authorization: "Bearer " + seeded.join.body.guestToken };
  const first = await h.call("POST", "/rooms/" + seeded.meetingId + "/guest-token", { headers: auth });
  assert.strictEqual(first.status, 200);
  const mark = h.gatewayCalls.length;
  const second = await h.call("POST", "/rooms/" + seeded.meetingId + "/guest-token", { headers: auth });
  assert.strictEqual(second.status, 200);
  const mint = h.gatewayCalls.slice(mark).find(function (c) { return c.operation === "token"; });
  assert.strictEqual(mint.request.name, "Guest One");
});

test("ended rooms produce room_ended instead of a misleading 404", async function () {
  const h = harness();
  const created = await createMeeting(h);
  await h.call("POST", "/rooms/" + created.body.meetingId + "/end", { headers: HOST_HEADERS });
  const join = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Late Guest", email: "late@example.com" },
  });
  assert.strictEqual(join.status, 409);
  assert.strictEqual(join.body.code, "room_ended");
});

test("moderation reaches the gateway and emits a durable audit", async function () {
  const h = harness();
  const seeded = await seedGuest(h);
  const kick = await h.call("POST", "/rooms/" + seeded.meetingId + "/kick", {
    headers: HOST_HEADERS,
    body: { participantId: seeded.join.body.participantId },
  });
  assert.strictEqual(kick.status, 200);
  assert.strictEqual(h.gatewayCalls[0].operation, "kick");
  assert.strictEqual(h.moderation[0].action, "kick");
});

test("kicking someone the SFU no longer has still clears local state", async function () {
  // Presence can legitimately drift: a token intent is not attendance, and a
  // guest may drop before the host clicks remove. When the SFU reports the
  // identity as absent the host's intent is already satisfied, so the kick must
  // finish rather than 502. Returning early left the roster entry in place and
  // every retry failed identically, so the participant became unremovable.
  const h = harness({ gatewayParticipantMissing: true });
  const seeded = await seedGuest(h);
  const participantId = seeded.join.body.participantId;
  const kick = await h.call("POST", "/rooms/" + seeded.meetingId + "/kick", {
    headers: HOST_HEADERS,
    body: { participantId: participantId },
  });
  assert.strictEqual(kick.status, 200);
  assert.strictEqual(h.moderation[0].action, "kick");
  // `waiting` is an object keyed by participantId, not an array.
  const waiting = await h.call("GET", "/rooms/" + seeded.meetingId + "/waiting", { headers: HOST_HEADERS });
  assert.ok(
    !Object.prototype.hasOwnProperty.call(waiting.body.waiting || {}, participantId),
    "the removed participant must not remain in the waiting list"
  );
});

test("host promotes and demotes a co-host with reconnect-safe role state", async function () {
  const h = harness();
  const seeded = await seedGuest(h);
  const participantId = seeded.join.body.participantId;
  const guestToken = seeded.join.body.guestToken;
  await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: participantId, decision: "admitted" },
  });
  const store = await tenantStore(h);
  await store.addParticipant(seeded.meetingId, participantId, {
    displayName: "Guest One",
    role: "participant",
  });

  const promoted = await h.call("POST", "/rooms/" + seeded.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: participantId, role: "cohost" },
  });
  assert.strictEqual(promoted.status, 200);
  assert.strictEqual((await store.getParticipant(seeded.meetingId, participantId)).role, "cohost");
  assert.strictEqual((await store.getGuest(guestToken)).role, "cohost");
  assert.strictEqual(h.gatewayCalls[0].operation, "role");
  assert.strictEqual(h.gatewayCalls[0].role, "cohost");
  assert.strictEqual(h.moderation[1].action, "promote");

  const refreshed = await h.call("POST", "/rooms/" + seeded.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + guestToken },
  });
  assert.strictEqual(refreshed.status, 200);
  assert.strictEqual(h.gatewayCalls[1].request.role, "cohost");

  const demoted = await h.call("POST", "/rooms/" + seeded.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: participantId, role: "participant" },
  });
  assert.strictEqual(demoted.status, 200);
  assert.strictEqual((await store.getParticipant(seeded.meetingId, participantId)).role, "participant");
  assert.strictEqual((await store.getGuest(guestToken)).role, "participant");
  assert.strictEqual(h.moderation[2].action, "demote");
});

test("co-host can kick a standard participant but never the host or another co-host", async function () {
  const h = harness();
  const cohost = await seedGuest(h);
  const second = await h.call("POST", "/rooms/" + cohost.meetingId + "/join-request", {
    body: { displayName: "Guest Two", email: "guest2@example.com" },
  });
  const store = await tenantStore(h);
  await store.setGuestDecisionByParticipant(cohost.meetingId, cohost.join.body.participantId, "admitted");
  await store.setGuestRoleByParticipant(cohost.meetingId, cohost.join.body.participantId, "cohost");
  await store.addParticipant(cohost.meetingId, cohost.join.body.participantId, {
    displayName: "Co-host",
    role: "cohost",
  });
  await store.addParticipant(cohost.meetingId, second.body.participantId, {
    displayName: "Guest Two",
    role: "participant",
  });
  await store.addParticipant(cohost.meetingId, "host-owner-1", {
    displayName: "Jane Owner",
    role: "host",
  });
  await store.addParticipant(cohost.meetingId, "guest-other-cohost", {
    displayName: "Other co-host",
    role: "cohost",
  });
  const cohostHeaders = {
    authorization: "Bearer " + cohost.join.body.guestToken,
  };

  const hostAttempt = await h.call("POST", "/rooms/" + cohost.meetingId + "/kick", {
    headers: cohostHeaders,
    body: { participantId: "host-owner-1" },
  });
  assert.strictEqual(hostAttempt.status, 403);
  assert.strictEqual(h.gatewayCalls.length, 0);

  const selfAttempt = await h.call("POST", "/rooms/" + cohost.meetingId + "/kick", {
    headers: cohostHeaders,
    body: { participantId: cohost.join.body.participantId },
  });
  assert.strictEqual(selfAttempt.status, 403);
  assert.strictEqual(h.gatewayCalls.length, 0);

  const otherCohostAttempt = await h.call("POST", "/rooms/" + cohost.meetingId + "/kick", {
    headers: cohostHeaders,
    body: { participantId: "guest-other-cohost" },
  });
  assert.strictEqual(otherCohostAttempt.status, 403);
  assert.strictEqual(h.gatewayCalls.length, 0);

  const participantKick = await h.call("POST", "/rooms/" + cohost.meetingId + "/kick", {
    headers: cohostHeaders,
    body: { participantId: second.body.participantId },
  });
  assert.strictEqual(participantKick.status, 200);
  assert.strictEqual(h.gatewayCalls[0].operation, "kick");
  assert.strictEqual(h.moderation[0].actorKey, cohost.join.body.participantId);
});

test("demotion immediately revokes co-host kick permission and the host is immutable", async function () {
  const h = harness();
  const seeded = await seedGuest(h);
  const store = await tenantStore(h);
  const participantId = seeded.join.body.participantId;
  await store.setGuestDecisionByParticipant(seeded.meetingId, participantId, "admitted");
  await store.setGuestRoleByParticipant(seeded.meetingId, participantId, "cohost");
  await store.addParticipant(seeded.meetingId, participantId, {
    displayName: "Co-host",
    role: "cohost",
  });
  await store.addParticipant(seeded.meetingId, "guest-target", {
    displayName: "Target",
    role: "participant",
  });

  const cannotDemoteHost = await h.call("POST", "/rooms/" + seeded.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: "host-owner-1", role: "participant" },
  });
  assert.strictEqual(cannotDemoteHost.status, 403);

  await h.call("POST", "/rooms/" + seeded.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: participantId, role: "participant" },
  });
  const afterDemotion = await h.call("POST", "/rooms/" + seeded.meetingId + "/kick", {
    headers: { authorization: "Bearer " + seeded.join.body.guestToken },
    body: { participantId: "guest-target" },
  });
  assert.strictEqual(afterDemotion.status, 403);
  assert.strictEqual(h.gatewayCalls.filter(function (call) { return call.operation === "kick"; }).length, 0);
});

/**
 * Seed a meeting whose first guest is an admitted co-host, plus one guest still
 * waiting. Returns everything the waiting-room tests need to act as either.
 */
async function seedCohostWithWaitingGuest(h) {
  const cohost = await seedGuest(h);
  const store = await tenantStore(h);
  const cohostId = cohost.join.body.participantId;
  await store.setGuestDecisionByParticipant(cohost.meetingId, cohostId, "admitted");
  await store.setGuestRoleByParticipant(cohost.meetingId, cohostId, "cohost");
  await store.removeWaiting(cohost.meetingId, cohostId);
  await store.addParticipant(cohost.meetingId, cohostId, { displayName: "Co-host", role: "cohost" });
  const pending = await h.call("POST", "/rooms/" + cohost.meetingId + "/join-request", {
    body: { displayName: "Guest Two", email: "guest2@example.com" },
  });
  return {
    store: store,
    meetingId: cohost.meetingId,
    cohostId: cohostId,
    cohostHeaders: { authorization: "Bearer " + cohost.join.body.guestToken },
    pendingId: pending.body.participantId,
    pendingToken: pending.body.guestToken,
  };
}

test("a co-host sees the waiting room and its admissions are audited to them", async function () {
  const h = harness();
  const seeded = await seedCohostWithWaitingGuest(h);

  const listed = await h.call("GET", "/rooms/" + seeded.meetingId + "/waiting", {
    headers: seeded.cohostHeaders,
  });
  assert.strictEqual(listed.status, 200);
  assert.ok(
    Object.prototype.hasOwnProperty.call(listed.body.waiting, seeded.pendingId),
    "the co-host must see the pending guest"
  );

  const admitted = await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: seeded.cohostHeaders,
    body: { participantId: seeded.pendingId, decision: "admitted" },
  });
  assert.strictEqual(admitted.status, 200);
  assert.strictEqual(admitted.body.already, false);
  const event = h.moderation[h.moderation.length - 1];
  assert.strictEqual(event.action, "admit");
  // The co-host, not the room owner — an audit trail attributing this to the
  // host would be false.
  assert.strictEqual(event.actorKey, seeded.cohostId);
  assert.strictEqual(event.details.actorRole, "cohost");
});

test("a standard participant cannot read or act on the waiting room", async function () {
  const h = harness();
  const seeded = await seedCohostWithWaitingGuest(h);
  await seeded.store.setGuestRoleByParticipant(seeded.meetingId, seeded.cohostId, "participant");
  await seeded.store.setParticipantRole(seeded.meetingId, seeded.cohostId, "participant");

  const listed = await h.call("GET", "/rooms/" + seeded.meetingId + "/waiting", {
    headers: seeded.cohostHeaders,
  });
  assert.strictEqual(listed.status, 403);
  assert.strictEqual(listed.body.code, "forbidden");

  const admitted = await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: seeded.cohostHeaders,
    body: { participantId: seeded.pendingId, decision: "admitted" },
  });
  assert.strictEqual(admitted.status, 403);
  assert.strictEqual(
    (await seeded.store.listWaiting(seeded.meetingId))[seeded.pendingId] !== undefined,
    true,
    "a rejected admission must leave the guest waiting"
  );
});

test("an unauthenticated caller cannot read the waiting room", async function () {
  const h = harness();
  const seeded = await seedCohostWithWaitingGuest(h);
  const listed = await h.call("GET", "/rooms/" + seeded.meetingId + "/waiting");
  assert.strictEqual(listed.status, 401);

  // 401 before 404: answering "no such meeting" first would turn a moderator
  // endpoint into an id/slug oracle for anyone who can reach it.
  const missing = await h.call("GET", "/rooms/no-such-meeting/waiting");
  assert.strictEqual(missing.status, 401);
  const missingAdmit = await h.call("POST", "/rooms/no-such-meeting/admit", {
    body: { participantId: "someone", decision: "admitted" },
  });
  assert.strictEqual(missingAdmit.status, 401);

  // The host still gets an honest 404 for a meeting that really is absent.
  const hostMissing = await h.call("GET", "/rooms/no-such-meeting/waiting", { headers: HOST_HEADERS });
  assert.strictEqual(hostMissing.status, 404);
});

test("an already-handled admission reconciles instead of failing", async function () {
  const h = harness();
  const seeded = await seedCohostWithWaitingGuest(h);
  // The host wins the race; the co-host's tap lands a moment later.
  const first = await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: seeded.pendingId, decision: "admitted" },
  });
  assert.strictEqual(first.status, 200);
  assert.strictEqual(first.body.already, false);
  const auditedOnce = h.moderation.length;

  const second = await h.call("POST", "/rooms/" + seeded.meetingId + "/admit", {
    headers: seeded.cohostHeaders,
    body: { participantId: seeded.pendingId, decision: "declined" },
  });
  assert.strictEqual(second.status, 200);
  assert.strictEqual(second.body.already, true);
  assert.strictEqual(h.moderation.length, auditedOnce, "a no-op must not be audited");
  // The late decline must not overturn the admission that already happened.
  const session = await seeded.store.getGuest(seeded.pendingToken);
  assert.strictEqual(session.decision, "admitted");
});

test("waiting-room moderation is refused across meetings and tenants", async function () {
  const h = harness();
  const seeded = await seedCohostWithWaitingGuest(h);
  const other = await createMeeting(h, { title: "Other" });

  const wrongMeeting = await h.call("GET", "/rooms/" + other.body.meetingId + "/waiting", {
    headers: seeded.cohostHeaders,
  });
  assert.strictEqual(wrongMeeting.status, 403);

  const wrongTenant = await h.call("GET", "/rooms/" + seeded.meetingId + "/waiting", {
    host: "tenantb.ringq.ai",
    headers: seeded.cohostHeaders,
  });
  // The guest-token signature is valid on tenant B — the HMAC secret is shared
  // across the deployment — so the tenant claim is what refuses it. 403 rather
  // than 404 on purpose: tenant B's namespace is never consulted, so the answer
  // says nothing about what tenant B does or does not hold.
  assert.strictEqual(wrongTenant.status, 403);
});

// ── raised hands and reactions ──

/**
 * A live meeting with an admitted guest participant and an admitted co-host,
 * both holding redeemable guest sessions. Interaction endpoints authorize at
 * participant level, so the tests need a plain participant as much as a
 * moderator.
 */
async function seedInteractionRoom(h, body) {
  const seeded = await seedGuest(h, body);
  const store = await tenantStore(h);
  const guestId = seeded.join.body.participantId;
  await store.updateRoom(seeded.meetingId, { status: "live" });
  await store.setGuestDecisionByParticipant(seeded.meetingId, guestId, "admitted");
  await store.removeWaiting(seeded.meetingId, guestId);
  await store.addParticipant(seeded.meetingId, guestId, { displayName: "Guest One", role: "participant" });
  await store.addParticipant(seeded.meetingId, "host-owner-1", { displayName: "Jane Owner", role: "host" });

  const cohostJoin = await h.call("POST", "/rooms/" + seeded.meetingId + "/join-request", {
    body: { displayName: "Casey Cohost", email: "casey@example.com" },
  });
  const cohostId = cohostJoin.body.participantId;
  await store.setGuestDecisionByParticipant(seeded.meetingId, cohostId, "admitted");
  await store.setGuestRoleByParticipant(seeded.meetingId, cohostId, "cohost");
  await store.removeWaiting(seeded.meetingId, cohostId);
  await store.addParticipant(seeded.meetingId, cohostId, { displayName: "Casey Cohost", role: "cohost" });

  return {
    store: store,
    meetingId: seeded.meetingId,
    guestId: guestId,
    guestHeaders: { authorization: "Bearer " + seeded.join.body.guestToken },
    cohostId: cohostId,
    cohostHeaders: { authorization: "Bearer " + cohostJoin.body.guestToken },
  };
}

function dataBroadcasts(h) {
  return h.gatewayCalls.filter(function (call) { return call.operation === "data"; });
}

test("host and co-host mute within role bounds while enable always requires participant consent", async function () {
  const h = harness({ features: { participantMediaControls: true } });
  const s = await seedInteractionRoom(h);

  const hostMute = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/participants/" + s.cohostId + "/media/mute",
    { headers: HOST_HEADERS, body: { source: "camera" } }
  );
  assert.strictEqual(hostMute.status, 200);
  assert.strictEqual(hostMute.body.changed, true);
  assert.ok(h.gatewayCalls.some(function (call) {
    return call.operation === "mute" && call.identity === s.cohostId && call.source === "camera";
  }));

  const cohostMute = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/participants/" + s.guestId + "/media/mute",
    { headers: s.cohostHeaders, body: { source: "microphone" } }
  );
  assert.strictEqual(cohostMute.status, 200);
  const cannotControlHost = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/participants/host-owner-1/media/mute",
    { headers: s.cohostHeaders, body: { source: "microphone" } }
  );
  assert.strictEqual(cannotControlHost.status, 403);

  const requested = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/participants/" + s.guestId + "/media/enable-requests",
    { headers: s.cohostHeaders, body: { source: "microphone" } }
  );
  assert.strictEqual(requested.status, 201);
  const requestId = requested.body.request.id;
  const requestPacket = dataBroadcasts(h).find(function (call) {
    return call.topic === "ringq.meet.media-request.v1" && call.payload.kind === "enableRequest";
  });
  assert.deepStrictEqual(requestPacket.destinationIdentities, [s.guestId]);

  const pending = await h.call("GET", "/rooms/" + s.meetingId + "/media/enable-requests", {
    headers: s.guestHeaders,
  });
  assert.strictEqual(pending.status, 200);
  assert.strictEqual(pending.body.requests[0].id, requestId);

  const rejected = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/media/enable-requests/" + requestId + "/respond",
    { headers: s.guestHeaders, body: { decision: "rejected" } }
  );
  assert.strictEqual(rejected.status, 200);
  const responsePacket = dataBroadcasts(h).find(function (call) {
    return call.topic === "ringq.meet.media-request.v1" && call.payload.kind === "enableResponse";
  });
  assert.deepStrictEqual(responsePacket.destinationIdentities, [s.cohostId]);
  assert.strictEqual(responsePacket.payload.decision, "rejected");
  assert.ok(h.moderation.some(function (event) { return event.action === "participant_media_mute"; }));
  assert.ok(h.moderation.some(function (event) { return event.action === "participant_media_request"; }));
  assert.ok(h.moderation.some(function (event) { return event.action === "participant_media_response"; }));
});

test("file presentation state is versioned, moderator-pageable and moderator-stoppable", async function () {
  const collaboration = fakeCollaboration();
  const documents = {
    health: async function () { return true; },
    prepare: async function (_tenant, _meeting, _attachment, _purpose) {
      return collaboration.getFileAsset(null, null, "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb");
    },
    manifest: function (_tenant, _meeting, asset) { return asset; },
  };
  const h = harness({
    collaboration: collaboration,
    documents: documents,
    attachments: { health: async function () { return true; } },
    features: { filePresentations: true },
  });
  const s = await seedInteractionRoom(h);
  const assetId = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
  const prepared = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/presentation/assets/from-attachment",
    { headers: s.guestHeaders, body: { attachmentId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa" } }
  );
  assert.strictEqual(prepared.status, 200);

  const started = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/start", {
    headers: s.guestHeaders,
    body: { clientActionId: "10000000-0000-4000-8000-000000000001", expectedVersion: 0, assetId: assetId },
  });
  assert.strictEqual(started.status, 200);
  assert.strictEqual(started.body.presentation.presenterId, s.guestId);

  const stale = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/page", {
    headers: s.guestHeaders,
    body: { clientActionId: "10000000-0000-4000-8000-000000000002", expectedVersion: 0, pageNumber: 2 },
  });
  assert.strictEqual(stale.status, 409);
  // A co-host advances somebody else's deck. This used to be a 403; the deck is
  // the room's, not only the presenter's, and a host running the meeting should
  // not have to ask the presenter to press Next.
  const paged = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/page", {
    headers: s.cohostHeaders,
    body: { clientActionId: "10000000-0000-4000-8000-000000000003", expectedVersion: 1, pageNumber: 2 },
  });
  assert.strictEqual(paged.status, 200);
  assert.strictEqual(paged.body.presentation.pageNumber, 2);
  assert.strictEqual(paged.body.presentation.presenterId, s.guestId,
    "paging must not hand the presentation to whoever pressed Next");
  // Paging is deliberately not audited: it happens once per slide, and a
  // moderation row per page turn buries the events that matter.
  assert.ok(!h.moderation.some(function (event) { return event.action === "file_presentation_page"; }));

  const stopped = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/stop", {
    headers: s.cohostHeaders,
    body: { clientActionId: "10000000-0000-4000-8000-000000000004", expectedVersion: 2 },
  });
  assert.strictEqual(stopped.status, 200);
  assert.strictEqual(stopped.body.presentation.status, "idle");
  assert.ok(h.moderation.some(function (event) { return event.action === "file_presentation_stop"; }));
  assert.ok(dataBroadcasts(h).some(function (event) {
    return event.topic === "ringq.meet.presentation.v1";
  }));
});

test("a participant raises and lowers their own hand, ordered and broadcast", async function () {
  // A hand is limited to one transition per second, so the clock has to move
  // between real transitions. Retries of an unchanged state are free and are
  // exercised here without advancing it.
  let now = 0;
  const h = harness({
    handLimiter: new rateLimitMod.RateLimiter({ windowMs: 1000, now: function () { return now; } }),
  });
  const s = await seedInteractionRoom(h);

  const raised = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: true },
  });
  assert.strictEqual(raised.status, 200);
  assert.strictEqual(raised.body.changed, true);
  const event = dataBroadcasts(h)[0];
  assert.strictEqual(event.topic, "ringq.meet.interaction.v1");
  assert.strictEqual(event.payload.kind, "hand");
  assert.strictEqual(event.payload.participantId, s.guestId);
  assert.strictEqual(event.payload.raised, true);
  // The roster name wins over whatever the client claims to be called.
  assert.strictEqual(event.payload.displayName, "Guest One");

  // Idempotent: a retry must not re-order the queue or re-announce.
  const again = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: true },
  });
  assert.strictEqual(again.status, 200);
  assert.strictEqual(again.body.changed, false);
  assert.strictEqual(dataBroadcasts(h).length, 1, "an unchanged hand must not broadcast");

  // The co-host raises second, so the queue order is guest then co-host.
  now += 1001;
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.cohostHeaders,
    body: { raised: true },
  });
  const state = await h.call("GET", "/rooms/" + s.meetingId + "/interactions", {
    headers: s.guestHeaders,
  });
  assert.strictEqual(state.status, 200);
  assert.deepStrictEqual(
    state.body.hands.map(function (hand) { return hand.participantId; }),
    [s.guestId, s.cohostId]
  );
  assert.ok(state.body.hands[0].raisedAt, "a hand carries the time it went up");
  assert.ok(state.body.hands[0].sequence < state.body.hands[1].sequence);

  now += 1001;
  const lowered = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: false },
  });
  assert.strictEqual(lowered.status, 200);
  assert.strictEqual(lowered.body.changed, true);
  const after = await h.call("GET", "/rooms/" + s.meetingId + "/interactions", {
    headers: s.guestHeaders,
  });
  assert.deepStrictEqual(
    after.body.hands.map(function (hand) { return hand.participantId; }),
    [s.cohostId]
  );
});

test("only a moderator may lower another hand, and nobody may raise one", async function () {
  const h = harness();
  const s = await seedInteractionRoom(h);
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.cohostHeaders,
    body: { raised: true },
  });

  // A plain participant cannot reach into someone else's hand state.
  const notAllowed = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: false, participantId: s.cohostId },
  });
  assert.strictEqual(notAllowed.status, 403);

  // Raising on someone's behalf is refused even for a moderator: it would fake
  // another person asking to speak.
  const impersonation = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: HOST_HEADERS,
    body: { raised: true, participantId: s.guestId },
  });
  assert.strictEqual(impersonation.status, 403);

  const byHost = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: HOST_HEADERS,
    body: { raised: false, participantId: s.cohostId },
  });
  assert.strictEqual(byHost.status, 200);
  assert.strictEqual(byHost.body.changed, true);
  const audit = h.moderation[h.moderation.length - 1];
  assert.strictEqual(audit.action, "lower_hand");
  assert.strictEqual(audit.actorKey, "host-owner-1");
  assert.strictEqual(audit.targetRef, s.cohostId);
});

test("reactions are relayed from a closed set and never stored", async function () {
  const h = harness();
  const s = await seedInteractionRoom(h);

  const sent = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
    headers: s.guestHeaders,
    body: { reaction: "clap" },
  });
  assert.strictEqual(sent.status, 200);
  const event = dataBroadcasts(h)[0];
  assert.strictEqual(event.payload.kind, "reaction");
  assert.strictEqual(event.payload.reaction, "clap");
  assert.strictEqual(event.payload.participantId, s.guestId);

  // Transient by definition: nothing about it survives into reconnect state.
  const state = await h.call("GET", "/rooms/" + s.meetingId + "/interactions", {
    headers: s.guestHeaders,
  });
  assert.deepStrictEqual(state.body.hands, []);

  for (const bad of ["", "shrug", "<script>", "thumbsup"]) {
    const rejected = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
      headers: s.guestHeaders,
      body: { reaction: bad },
    });
    assert.strictEqual(rejected.status, 400, "reaction '" + bad + "' must be refused");
  }
  assert.strictEqual(dataBroadcasts(h).length, 1);
});

test("interaction endpoints are limited per participant, not per address", async function () {
  let now = 0;
  const clock = function () { return now; };
  const h = harness({
    reactionLimiter: new rateLimitMod.RateLimiter({ windowMs: 5000, now: clock }),
    handLimiter: new rateLimitMod.RateLimiter({ windowMs: 1000, now: clock }),
  });
  const s = await seedInteractionRoom(h);

  for (let i = 0; i < 3; i++) {
    const allowed = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
      headers: s.guestHeaders,
      body: { reaction: "heart" },
    });
    assert.strictEqual(allowed.status, 200, "reaction " + (i + 1) + " of 3 must be allowed");
  }
  const throttled = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
    headers: s.guestHeaders,
    body: { reaction: "heart" },
  });
  assert.strictEqual(throttled.status, 429);
  assert.strictEqual(throttled.body.code, "rate_limited");
  assert.ok(throttled.headers["Retry-After"]);

  // A different participant on the same address is unaffected — one noisy client
  // must not silence the room.
  const other = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
    headers: s.cohostHeaders,
    body: { reaction: "heart" },
  });
  assert.strictEqual(other.status, 200);

  now += 5001;
  const recovered = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
    headers: s.guestHeaders,
    body: { reaction: "heart" },
  });
  assert.strictEqual(recovered.status, 200);

  // Hands: one transition per second.
  const firstHand = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: true },
  });
  assert.strictEqual(firstHand.status, 200);
  const flapping = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: false },
  });
  assert.strictEqual(flapping.status, 429);
  now += 1001;
  const settled = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: false },
  });
  assert.strictEqual(settled.status, 200);
});

test("interactions require an admitted participant of this meeting", async function () {
  const h = harness();
  const s = await seedInteractionRoom(h);

  const anonymous = await h.call("GET", "/rooms/" + s.meetingId + "/interactions");
  assert.strictEqual(anonymous.status, 401);

  // Still in the waiting room: holding a signed token is not membership.
  const waiting = await h.call("POST", "/rooms/" + s.meetingId + "/join-request", {
    body: { displayName: "Not Admitted", email: "nope@example.com" },
  });
  const refused = await h.call("POST", "/rooms/" + s.meetingId + "/reaction", {
    headers: { authorization: "Bearer " + waiting.body.guestToken },
    body: { reaction: "clap" },
  });
  assert.strictEqual(refused.status, 403);

  // A token for another meeting cannot reach this one.
  const otherRoom = await createMeeting(h, { title: "Other" });
  const crossMeeting = await h.call("POST", "/rooms/" + otherRoom.body.meetingId + "/reaction", {
    headers: s.guestHeaders,
    body: { reaction: "clap" },
  });
  assert.strictEqual(crossMeeting.status, 403);
  assert.strictEqual(dataBroadcasts(h).length, 0);
});

test("a kick lowers the departed participant's hand", async function () {
  const h = harness();
  const s = await seedInteractionRoom(h);
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: true },
  });
  await h.call("POST", "/rooms/" + s.meetingId + "/kick", {
    headers: HOST_HEADERS,
    body: { participantId: s.guestId },
  });
  assert.strictEqual(await s.store.getHand(s.meetingId, s.guestId), null);
  const lowered = dataBroadcasts(h).filter(function (call) {
    return call.payload.kind === "hand" && call.payload.raised === false;
  });
  assert.strictEqual(lowered.length, 1, "the room must be told the hand came down");
});

test("a failed broadcast does not fail the interaction it already committed", async function () {
  const h = harness({ gatewayBroadcastFailure: true });
  const s = await seedInteractionRoom(h);
  const raised = await h.call("POST", "/rooms/" + s.meetingId + "/hand", {
    headers: s.guestHeaders,
    body: { raised: true },
  });
  // The state is authoritative and GET /interactions will report it, so telling
  // the caller their hand did not go up would be a lie.
  assert.strictEqual(raised.status, 200);
  assert.strictEqual(raised.body.changed, true);
  assert.ok(await s.store.getHand(s.meetingId, s.guestId));
});

// ── persistent chat and collaborative whiteboard ──

test("collaboration capabilities are disabled by default and health-gated", async function () {
  const disabled = await harness().call("GET", "/capabilities");
  assert.strictEqual(disabled.body.features.chat, false);
  assert.strictEqual(disabled.body.features.chatAttachments, false);
  assert.strictEqual(disabled.body.features.whiteboard, false);

  const collaboration = fakeCollaboration();
  const enabled = await harness({
    collaboration: collaboration,
    attachments: { health: async function () { return false; } },
    features: { chat: true, chatAttachments: true, whiteboard: true },
  }).call("GET", "/capabilities");
  assert.strictEqual(enabled.body.features.chat, true);
  assert.strictEqual(enabled.body.features.chatAttachments, false, "scanner failure only disables files");
  assert.strictEqual(enabled.body.features.whiteboard, true);
});

test("chat commits before broadcast and retries do not duplicate events", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { chat: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  const clientMessageId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
  const first = await h.call("POST", "/rooms/" + created.body.meetingId + "/chat/messages", {
    headers: HOST_HEADERS,
    body: { clientMessageId: clientMessageId, body: "hello", attachmentIds: [] },
  });
  assert.strictEqual(first.status, 201);
  assert.strictEqual(first.body.message.body, "hello");
  assert.strictEqual(h.gatewayCalls.filter(function (call) { return call.operation === "data" && call.topic === "ringq.meet.chat.v1"; }).length, 1);
  const retry = await h.call("POST", "/rooms/" + created.body.meetingId + "/chat/messages", {
    headers: HOST_HEADERS,
    body: { clientMessageId: clientMessageId, body: "hello", attachmentIds: [] },
  });
  assert.strictEqual(retry.status, 200);
  assert.strictEqual(retry.body.duplicate, true);
  assert.strictEqual(h.gatewayCalls.filter(function (call) { return call.operation === "data" && call.topic === "ringq.meet.chat.v1"; }).length, 1);
});

test("chat edit uses a version and moderator delete leaves a tombstone audit", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { chat: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  const sent = await h.call("POST", "/rooms/" + created.body.meetingId + "/chat/messages", {
    headers: HOST_HEADERS,
    body: { clientMessageId: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", body: "first" },
  });
  const edited = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/chat/messages/" + sent.body.message.id, {
    headers: HOST_HEADERS, body: { body: "edited", expectedVersion: 1 },
  });
  assert.strictEqual(edited.body.message.version, 2);
  const stale = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/chat/messages/" + sent.body.message.id, {
    headers: HOST_HEADERS, body: { body: "stale", expectedVersion: 1 },
  });
  assert.strictEqual(stale.status, 409);
  collaboration.messages[sent.body.message.id].participantId = "guest-other";
  const deleted = await h.call("DELETE", "/rooms/" + created.body.meetingId + "/chat/messages/" + sent.body.message.id, {
    headers: HOST_HEADERS, body: { expectedVersion: 2 },
  });
  assert.strictEqual(deleted.body.message.deleted, true);
  assert.strictEqual(deleted.body.message.body, "");
  assert.ok(h.moderation.some(function (event) { return event.action === "chat_delete"; }));
});

test("ended collaboration is owner-readable but immutable and guest access expires", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { chat: true } });
  const meetingId = "10000000-0000-4000-8000-999999999999";
  collaboration.meetings[meetingId] = { owner_key: "owner-1", status: "ended" };
  const read = await h.call("GET", "/rooms/" + meetingId + "/chat/messages", { headers: HOST_HEADERS });
  assert.strictEqual(read.status, 200);
  const write = await h.call("POST", "/rooms/" + meetingId + "/chat/messages", {
    headers: HOST_HEADERS,
    body: { clientMessageId: "cccccccc-cccc-4ccc-8ccc-cccccccccccc", body: "late" },
  });
  assert.strictEqual(write.status, 409);
  assert.strictEqual(write.body.code, "room_ended");
  const guest = await h.call("GET", "/rooms/" + meetingId + "/chat/messages");
  assert.strictEqual(guest.status, 401);
});

test("presenting the whiteboard broadcasts it to the whole room", async function () {
  // The point of the feature: a participant should not have to be told "open
  // the whiteboard". Without the broadcast nobody else ever learns.
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { whiteboard: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };

  const result = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/present", {
    headers: HOST_HEADERS,
    body: { clientBatchId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd", expectedVersion: 0, presenting: true },
  });
  assert.strictEqual(result.status, 200);
  assert.strictEqual(result.body.presenting, true);

  const packet = h.gatewayCalls.filter(function (call) {
    return call.topic === "ringq.meet.whiteboard.v1";
  }).pop();
  assert.ok(packet, "presenting must be announced on the whiteboard topic");
  assert.strictEqual(packet.payload.kind, "present");
  assert.strictEqual(packet.payload.presenting, true);
});

test("presenting the whiteboard is refused while a file presentation is active", async function () {
  // The board and a deck compete for one stage. Taking over would silently end
  // somebody else's presentation, so this answers rather than acts.
  const collaboration = fakeCollaboration();
  const h = harness({
    collaboration: collaboration,
    features: { whiteboard: true, filePresentations: true },
  });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  collaboration.setPresentationForTest({ version: 3, sequence: 3, status: "active", assetId: "asset-1" });

  const result = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/present", {
    headers: HOST_HEADERS,
    body: { clientBatchId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd", expectedVersion: 0, presenting: true },
  });
  assert.strictEqual(result.status, 409);
  assert.strictEqual(result.body.code, "PRESENTATION_ACTIVE");
});

test("stopping the whiteboard presentation is allowed while a deck is up", async function () {
  // Only starting competes for the stage. Refusing to STOP would strand a
  // presentation nobody could take down.
  const collaboration = fakeCollaboration();
  const h = harness({
    collaboration: collaboration,
    features: { whiteboard: true, filePresentations: true },
  });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  collaboration.setPresentationForTest({ version: 3, sequence: 3, status: "active", assetId: "asset-1" });

  const result = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/present", {
    headers: HOST_HEADERS,
    body: { clientBatchId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd", expectedVersion: 0, presenting: false },
  });
  assert.strictEqual(result.status, 200);
  assert.strictEqual(result.body.presenting, false);
});

test("presenting the whiteboard requires clientBatchId and a boolean", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { whiteboard: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };

  const missing = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/present", {
    headers: HOST_HEADERS,
    body: { expectedVersion: 0, presenting: true },
  });
  assert.strictEqual(missing.status, 400);

  const notBoolean = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/present", {
    headers: HOST_HEADERS,
    body: { clientBatchId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd", expectedVersion: 0, presenting: "yes" },
  });
  assert.strictEqual(notBoolean.status, 400);
});

test("whiteboard_present is accepted by the moderation audit allow-list", async function () {
  // recorder.moderationEvent silently returns null for an unknown action, so a
  // missing entry costs the whole feature its audit trail with no error.
  const recorderMod = require("../recorder.js");
  const source = require("fs").readFileSync(require("path").join(__dirname, "..", "recorder.js"), "utf8");
  assert.ok(/"whiteboard_present"/.test(source), "recorder.js must allow whiteboard_present");
  assert.ok(recorderMod, "recorder module loads");
});

test("whiteboard validates bounded operations and broadcasts canonical batches", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { whiteboard: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  const result = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/operations", {
    headers: HOST_HEADERS,
    body: {
      clientBatchId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
      operations: [{ type: "stroke", element: {
        id: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", kind: "pen",
        points: [[0.1, 0.2], [0.3, 0.4]], color: 4294967295, width: 4,
      } }],
    },
  });
  assert.strictEqual(result.status, 201);
  assert.strictEqual(result.body.batch.sequence, 1);
  assert.ok(h.gatewayCalls.some(function (call) { return call.topic === "ringq.meet.whiteboard.v1"; }));
  const eventCount = h.gatewayCalls.filter(function (call) {
    return call.topic === "ringq.meet.whiteboard.v1";
  }).length;
  const retry = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/operations", {
    headers: HOST_HEADERS,
    body: {
      clientBatchId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd",
      operations: [{ type: "stroke", element: {
        id: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", kind: "pen",
        points: [[0.1, 0.2], [0.3, 0.4]], color: 4294967295, width: 4,
      } }],
    },
  });
  assert.strictEqual(retry.status, 200);
  assert.strictEqual(retry.body.batch.duplicate, true);
  assert.strictEqual(h.gatewayCalls.filter(function (call) {
    return call.topic === "ringq.meet.whiteboard.v1";
  }).length, eventCount);
  const invalid = await h.call("POST", "/rooms/" + created.body.meetingId + "/whiteboard/operations", {
    headers: HOST_HEADERS,
    body: {
      clientBatchId: "ffffffff-ffff-4fff-8fff-ffffffffffff",
      operations: [{ type: "stroke", element: { id: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", points: [[2, 0]] } }],
    },
  });
  assert.strictEqual(invalid.status, 400);
});

test("recording controls remain host-only and disabled capabilities fail closed", async function () {
  const recordingId = "abababab-abab-4bab-8bab-abababababab";
  const capture = {
    health: async function () { return true; },
    getMeeting: async function (_tenant, meetingId) {
      return { id: meetingId, owner_key: "owner-1", status: "live" };
    },
    beginRecording: async function (_tenant, input) {
      return {
        duplicate: false,
        recordingId: recordingId,
        state: {
          version: input.expectedVersion + 1,
          recordingId: recordingId,
          recordingStatus: "starting",
          transcriptionStatus: "idle",
        },
      };
    },
    activateRecording: async function () { throw new Error("test timer should not execute"); },
  };
  const disabled = harness({ capture: capture });
  const disabledRoom = await createMeeting(disabled);
  const disabledResult = await disabled.call("POST", "/rooms/" + disabledRoom.body.meetingId + "/recording/start", {
    headers: HOST_HEADERS,
    body: { clientActionId: "12121212-1212-4121-8121-121212121212", expectedVersion: 0 },
  });
  assert.strictEqual(disabledResult.status, 404);

  const h = harness({ capture: capture, features: { meetingRecording: true } });
  const created = await createMeeting(h);
  const anonymous = await h.call("POST", "/rooms/" + created.body.meetingId + "/recording/start", {
    body: { clientActionId: "13131313-1313-4131-8131-131313131313", expectedVersion: 0 },
  });
  assert.strictEqual(anonymous.status, 401);
  const started = await h.call("POST", "/rooms/" + created.body.meetingId + "/recording/start", {
    headers: HOST_HEADERS,
    body: { clientActionId: "14141414-1414-4141-8141-141414141414", expectedVersion: 0 },
  });
  assert.strictEqual(started.status, 202);
  assert.strictEqual(started.body.recordingId, recordingId);
});

test("healthy Egress updates do not finalize an active recording", async function () {
  const recordingId = "abababab-abab-4bab-8bab-abababababab";
  const finalized = [];
  const capture = {
    finalizeRecording: async function (_tenant, currentMeetingId, currentRecordingId, status, metadata) {
      finalized.push({
        meetingId: currentMeetingId,
        recordingId: currentRecordingId,
        status: status,
        metadata: metadata,
      });
    },
  };
  const h = harness({
    capture: capture,
    presence: {
      registerTenant: function () {},
      applyEvent: async function () { return { duplicate: false }; },
    },
    callbackAuth: function () { return true; },
    logger: {
      info: function () {},
      warn: function () {},
      error: function (message) { process.stderr.write("gateway test: " + message + "\n"); },
    },
  });
  const meetingId = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";

  const active = await h.call("POST", "/gateway/events", {
    body: {
      type: "egress_updated",
      meetingId: meetingId,
      recording: { recordingId: recordingId },
    },
  });
  assert.strictEqual(active.status, 200, JSON.stringify(active.body));
  assert.strictEqual(finalized.length, 0);

  const ended = await h.call("POST", "/gateway/events", {
    body: {
      type: "egress_ended",
      meetingId: meetingId,
      recording: { recordingId: recordingId },
    },
  });
  assert.strictEqual(ended.status, 200);
  assert.strictEqual(finalized.length, 1);
  assert.strictEqual(finalized[0].status, "complete");

  const failed = await h.call("POST", "/gateway/events", {
    body: {
      type: "egress_updated",
      meetingId: meetingId,
      recording: { recordingId: recordingId, error: "upload failed" },
    },
  });
  assert.strictEqual(failed.status, 200);
  assert.strictEqual(finalized.length, 2);
  assert.strictEqual(finalized[1].status, "failed");
  assert.strictEqual(finalized[1].metadata.failureCode, "egress_failed");
});

test("gateway failures are surfaced as media_unavailable", async function () {
  const h = harness({ gatewayFailure: true });
  const created = await createMeeting(h);
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/token", { headers: HOST_HEADERS });
  assert.strictEqual(token.status, 502);
  assert.strictEqual(token.body.code, "media_unavailable");
});

test("rate-limited public lookup returns Retry-After", async function () {
  const limiter = { consume: function () { return { allowed: false, remaining: 0, retryAfterSeconds: 15 }; } };
  const result = await harness({ rateLimiter: limiter }).call("GET", "/rooms/code");
  assert.strictEqual(result.status, 429);
  assert.strictEqual(result.body.code, "rate_limited");
  assert.strictEqual(result.headers["Retry-After"], "15");
});

test("unknown tenant host is never served by a default", async function () {
  const result = await harness().call("GET", "/rooms/code", { host: "attacker.example.com" });
  assert.strictEqual(result.status, 404);
  assert.strictEqual(result.body.code, "media_unavailable");
});

test("unknown paths fall through", async function () {
  const result = await harness().call("GET", "/not-a-route");
  assert.strictEqual(result.handled, false);
});

// The transcript callback is the only way a recognised phrase reaches storage and
// the room. Nothing exercised it before, which is how a 403-for-every-request
// wiring bug survived: the harness below passes `transcriberSecret`, production
// did not. These pin the comparison itself; `tests/production.js` pins the wiring.
test("a transcript callback with the worker bearer authenticates", async function () {
  const secret = "transcriber-callback-secret-that-is-long-enough";
  const h = harness({
    transcriberSecret: secret,
    features: { meetingTranscription: true },
    // `requireCaptureFeature` demands the storage service exist before it even
    // looks at the bearer, so the double is needed even on the ephemeral path.
    capture: { ingestTranscript: async function () { return { accepted: true, participantName: "Jay" }; } },
  });
  const response = await h.call("POST", "/callbacks/transcript", {
    headers: { authorization: "Bearer " + secret },
    // `final: false` is the ephemeral path: it broadcasts and stores nothing, so
    // this authenticates without needing a real meeting or participant row.
    body: {
      meetingId: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
      participantRef: "host-1", trackSid: "TR_1", sequence: 1,
      startMs: 0, endMs: 1000, language: "en", text: "hello test meeting", final: false,
    },
  });
  assert.notStrictEqual(response.status, 403, "the worker bearer must be accepted");
  assert.strictEqual(response.status, 200);
  const broadcasts = h.gatewayCalls.filter(function (c) {
    return c.operation === "data" && c.topic === "ringq.meet.transcript.v1";
  });
  assert.strictEqual(broadcasts.length, 1, "the room must be told");
  assert.strictEqual(broadcasts[0].payload.segment.text, "hello test meeting");
});

test("a transcript callback with the wrong bearer is refused", async function () {
  const h = harness({
    transcriberSecret: "transcriber-callback-secret-that-is-long-enough",
    features: { meetingTranscription: true },
    capture: { ingestTranscript: async function () { return { accepted: true, participantName: "Jay" }; } },
  });
  for (const authorization of ["", "Bearer ", "Bearer wrong-secret-of-a-different-length"]) {
    const response = await h.call("POST", "/callbacks/transcript", {
      headers: authorization ? { authorization: authorization } : {},
      body: { meetingId: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", final: false },
    });
    assert.strictEqual(response.status, 403, "unauthenticated callbacks must be refused");
  }
});

// ── host privacy controls: locks, grants, access requests, name privacy ──

// ─────────────────────── participant profile photos ───────────────────────

test("the host's photo reaches the SFU token, and is absent when they have none", async function () {
  const h = harness();
  const created = await createMeeting(h);
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/token", { headers: HOST_HEADERS });
  assert.strictEqual(token.status, 200);
  assert.strictEqual(
    h.gatewayCalls[0].request.avatarUrl,
    "https://tenant-a.ringq.test:8443/avr/jane.png",
    "the photo on the access token must reach the media token"
  );

  // owner-2 has no photo on their bearer: the field must be empty, not the
  // previous caller's URL and not undefined-turned-into-something.
  const h2 = harness();
  const other = await h2.call("POST", "/rooms", {
    headers: { authorization: "Bearer valid-host-bearer-2" },
    body: { title: "No photo" },
  });
  await h2.call("POST", "/rooms/" + other.body.meetingId + "/token", {
    headers: { authorization: "Bearer valid-host-bearer-2" },
  });
  assert.strictEqual(h2.gatewayCalls[0].request.avatarUrl, "");
});

test("a malformed or oversized photo URL is dropped and never fails the join", async function () {
  const cases = [
    ["http://tenant.test/avr/x.png", "plain http would be a mixed-content block"],
    ["data:image/png;base64,AAAA", "a data URI must not ride the roster"],
    ["//tenant.test/avr/x.png", "scheme-relative resolves against the meeting page"],
    ["https://tenant.test/avr/" + "x".repeat(400) + ".png", "oversized"],
    [12345, "not a string"],
  ];
  for (let i = 0; i < cases.length; i++) {
    const value = cases[i][0];
    const why = cases[i][1];
    const h = harness({ hostAvatarUrl: value });
    const created = await createMeeting(h);
    const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/token", { headers: HOST_HEADERS });
    assert.strictEqual(token.status, 200, "a bad photo must never fail the join: " + why);
    assert.strictEqual(h.gatewayCalls[0].request.avatarUrl, "", why);
  }
});

test("name privacy hides the photo exactly when it hides the name", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "participants" } },
  });

  // Asserted together, deliberately: checking the empty photo alone would pass
  // just as happily if masking were switched off entirely.
  const before = h.gatewayCalls.length;
  const token = await h.call("POST", "/rooms/" + s.meetingId + "/token", { headers: HOST_HEADERS });
  assert.strictEqual(token.status, 200);
  const hostMint = h.gatewayCalls.slice(before).find(function (c) { return c.operation === "token"; });
  assert.strictEqual(hostMint.request.name, "Jane Owner", "a moderator keeps their name");
  assert.strictEqual(
    hostMint.request.avatarUrl,
    "https://tenant-a.ringq.test:8443/avr/jane.png",
    "and therefore keeps their photo"
  );

  // A plain participant is aliased, so their face must go with their name.
  const store = await tenantStore(h);
  const joined = await h.call("POST", "/rooms/" + s.meetingId + "/join-request", {
    headers: HOST_HEADERS, body: { displayName: "Ana Costa", email: "ana@example.com" },
  });
  const anaId = joined.body.participantId;
  await store.setGuestDecisionByParticipant(s.meetingId, anaId, "admitted");
  const mark = h.gatewayCalls.length;
  const anaToken = await h.call("POST", "/rooms/" + s.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + joined.body.guestToken },
  });
  assert.strictEqual(anaToken.status, 200);
  const anaMint = h.gatewayCalls.slice(mark).find(function (c) { return c.operation === "token"; });
  assert.ok(/^Participant \d+$/.test(anaMint.request.name), "the name is masked: " + anaMint.request.name);
  assert.strictEqual(anaMint.request.avatarUrl, "", "so the photo is masked too");
});

test("an authenticated joiner brings their photo; an anonymous one cannot claim any", async function () {
  const h = harness();
  const created = await createMeeting(h);
  const meetingId = created.body.meetingId;
  const store = await tenantStore(h);

  const signedIn = await h.call("POST", "/rooms/" + meetingId + "/join-request", {
    headers: HOST_HEADERS,
    body: { displayName: "Ana Costa", email: "ana@example.com" },
  });
  assert.strictEqual(signedIn.status, 200);
  let waiting = await store.listWaiting(meetingId);
  assert.strictEqual(
    waiting[signedIn.body.participantId].avatarUrl,
    "https://tenant-a.ringq.test:8443/avr/jane.png",
    "an internal user joining a colleague's meeting is not a faceless guest"
  );

  // The photo is derived from the credential, never accepted from the body.
  // Otherwise anyone could put an arbitrary picture on their own tile.
  const spoofed = await h.call("POST", "/rooms/" + meetingId + "/join-request", {
    body: {
      displayName: "Mallory",
      email: "mallory@example.com",
      avatarUrl: "https://evil.test/someone-elses-face.png",
    },
  });
  assert.strictEqual(spoofed.status, 200);
  waiting = await store.listWaiting(meetingId);
  assert.strictEqual(waiting[spoofed.body.participantId].avatarUrl, "", "a body-supplied photo is ignored");

  // And it survives the wait, all the way to the media token.
  await store.setGuestDecisionByParticipant(meetingId, signedIn.body.participantId, "admitted");
  const mark = h.gatewayCalls.length;
  await h.call("POST", "/rooms/" + meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + signedIn.body.guestToken },
  });
  const mint = h.gatewayCalls.slice(mark).find(function (c) { return c.operation === "token"; });
  assert.strictEqual(mint.request.avatarUrl, "https://tenant-a.ringq.test:8443/avr/jane.png");
});

test("a promotion does not erase the participant's photo", async function () {
  const h = harness({ features: { participantMediaControls: true } });
  const s = await seedInteractionRoom(h);
  await s.store.addParticipant(s.meetingId, s.guestId, {
    displayName: "Guest One",
    role: "participant",
    avatarUrl: "https://tenant-a.ringq.test:8443/avr/guest.png",
  });

  const promoted = await h.call("POST", "/rooms/" + s.meetingId + "/role", {
    headers: HOST_HEADERS, body: { participantId: s.guestId, role: "cohost" },
  });
  assert.strictEqual(promoted.status, 200);

  // The SFU metadata write is a whole-block replace, so a role change that does
  // not restate the photo deletes it.
  const roleCall = h.gatewayCalls.filter(function (c) { return c.operation === "role"; }).pop();
  assert.strictEqual(roleCall.role, "cohost");
  assert.strictEqual(roleCall.avatarUrl, "https://tenant-a.ringq.test:8443/avr/guest.png");
});

const PRIVACY = { features: { hostPrivacyControls: true, participantMediaControls: true } };

function policyCalls(h, operation) {
  return h.gatewayCalls.filter(function (call) { return call.operation === operation; });
}

test("a lobby policy governs the very first participant to join", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h, {
    policy: { cameraLocked: true, microphoneLocked: true, hideParticipantNames: "participants" },
  });
  assert.strictEqual(created.status, 200);
  assert.strictEqual(created.body.policy.cameraLocked, true);

  const join = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Guest One", email: "guest@example.com" },
  });
  const store = await tenantStore(h);
  await store.setGuestDecisionByParticipant(created.body.meetingId, join.body.participantId, "admitted");
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + join.body.guestToken },
  });
  assert.strictEqual(token.status, 200);
  // The client needs the policy before it decides whether to capture, so it
  // rides back with the token rather than needing a second request.
  assert.deepStrictEqual(token.body.mediaLocks, { microphone: true, camera: true });

  const mint = h.gatewayCalls.find(function (call) {
    return call.operation === "token" && call.request.identity === join.body.participantId;
  });
  // An allow-list, and screen share survives the camera lock.
  assert.deepStrictEqual(mint.request.publishSources, ["screenShare"]);
  assert.strictEqual(mint.request.name, "Participant 1");
});

test("an unlocked meeting mints exactly the grant it did before this feature", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h, {});
  await h.call("POST", "/rooms/" + created.body.meetingId + "/token", { headers: HOST_HEADERS });
  const mint = h.gatewayCalls.find(function (call) { return call.operation === "token"; });
  assert.strictEqual(mint.request.publishSources, undefined, "no policy means no restriction, not an empty list");
  assert.strictEqual(mint.request.name, "Jane Owner");
});

test("a host is never locked by their own policy and keeps their real name", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h, {
    policy: { cameraLocked: true, microphoneLocked: true, hideParticipantNames: "everyone" },
  });
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/token", { headers: HOST_HEADERS });
  assert.deepStrictEqual(token.body.mediaLocks, { microphone: false, camera: false });
  const mint = h.gatewayCalls.find(function (call) { return call.operation === "token"; });
  assert.deepStrictEqual(mint.request.publishSources, ["microphone", "camera", "screenShare"]);
  assert.strictEqual(mint.request.name, "Jane Owner");
});

test("a reused room still applies the policy the host configured", async function () {
  // Copying a link deliberately creates the room in advance, so by the time a
  // host presses Start meet the room usually already exists. Before this the
  // reuse branch returned before the policy was applied, so the common case
  // was "the host ticks lock microphones and the meeting opens unlocked".
  const h = harness(PRIVACY);
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:policy" });
  assert.strictEqual(first.body.reused, false);

  const second = await createMeeting(h, {
    meetType: "scheduled",
    externalReference: "sched:policy",
    policy: { microphoneLocked: true, chatLocked: true },
  });
  assert.strictEqual(second.body.meetingId, first.body.meetingId);
  assert.strictEqual(second.body.reused, true);
  // Asserted on the room's committed policy, not only on the response: a
  // handler that echoed the request back would pass on the response alone.
  const store = await tenantStore(h);
  const stored = await store.getPolicy(first.body.meetingId);
  assert.strictEqual(stored.microphoneLocked, true);
  assert.strictEqual(stored.chatLocked, true);
  assert.ok(stored.version > 1, "the policy version must advance: " + stored.version);
});

test("a reuse that sends no policy cannot overwrite one set during the meeting", async function () {
  // The anti-clobber guard, and the whole reason the apply is conditional on a
  // policy having been supplied. Copy link and booking integrations send none,
  // so they must never undo a change a host made mid-meeting.
  const h = harness(PRIVACY);
  const created = await createMeeting(h, {
    meetType: "scheduled",
    externalReference: "sched:keep",
    policy: { microphoneLocked: true },
  });
  const store = await tenantStore(h);
  await store.markLive(created.body.meetingId, 1000);

  const unlocked = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { microphoneLocked: false } },
  });
  assert.strictEqual(unlocked.status, 200);

  const reused = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:keep" });
  assert.strictEqual(reused.body.reused, true);
  assert.strictEqual((await store.getPolicy(created.body.meetingId)).microphoneLocked, false);
});

test("a reused LIVE room has the new grants pushed to the SFU", async function () {
  // Committing the policy is not enough for a room somebody is already in:
  // without the sweep the grants stay as they were and the lock is advisory,
  // which is indistinguishable from the control doing nothing.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h, { meetType: "scheduled", externalReference: "sched:live-policy" });

  const reused = await createMeeting(h, {
    meetType: "scheduled",
    externalReference: "sched:live-policy",
    policy: { cameraLocked: true },
  });
  assert.strictEqual(reused.body.reused, true);

  const bySource = {};
  policyCalls(h, "permissions").forEach(function (call) { bySource[call.identity] = call.publishSources; });
  assert.deepStrictEqual(bySource[s.guestId], ["microphone", "screenShare"]);
  // A camera already live has to stop, or locking it looks like nothing happened.
  assert.ok(policyCalls(h, "mute").some(function (call) {
    return call.identity === s.guestId && call.source === "camera";
  }), "a newly locked camera must be muted");
  // And the room has to be told, or every client keeps offering the control.
  assert.ok(dataBroadcasts(h).some(function (call) {
    return call.topic === "ringq.meet.policy.v1" && call.payload.policy.cameraLocked === true;
  }), "the room must be told the policy changed");
});

test("a policy that cannot be applied still returns the meeting", async function () {
  // A host must never be shut out of their own meeting by a privacy setting.
  const h = harness({ features: PRIVACY.features, gatewayFailure: true });
  const first = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:broken" });
  const s = await tenantStore(h);
  await s.markLive(first.body.meetingId, 1000);
  await s.addParticipant(first.body.meetingId, "p-1", { displayName: "Guest", role: "participant" });

  const second = await createMeeting(h, {
    meetType: "scheduled",
    externalReference: "sched:broken",
    policy: { cameraLocked: true },
  });
  assert.strictEqual(second.status, 200);
  assert.strictEqual(second.body.meetingId, first.body.meetingId);
  assert.ok(second.body.joinUrl, "the room must still be returned");
});

test("locking a source live pushes permissions, stops live tracks, and broadcasts once", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);

  const locked = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { cameraLocked: true } },
  });
  assert.strictEqual(locked.status, 200);
  assert.strictEqual(locked.body.policy.version, 2);
  assert.deepStrictEqual(locked.body.changed, ["cameraLocked"]);

  const permissions = policyCalls(h, "permissions");
  // Every participant is restated, moderators included. Restating a moderator is
  // idempotent (they always get every source) and it is what repairs a grant
  // left restrictive by a promotion that happened while the lock was on.
  const bySource = {};
  permissions.forEach(function (call) { bySource[call.identity] = call.publishSources; });
  assert.deepStrictEqual(bySource[s.guestId], ["microphone", "screenShare"]);
  assert.deepStrictEqual(bySource[s.cohostId], ["microphone", "camera", "screenShare"]);
  assert.deepStrictEqual(bySource["host-owner-1"], ["microphone", "camera", "screenShare"]);
  // Preventing the next publish is not enough; a camera already live has to stop.
  assert.ok(policyCalls(h, "mute").some(function (call) {
    return call.identity === s.guestId && call.source === "camera";
  }));
  const packets = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.policy.v1" && call.payload.kind === "policy";
  });
  assert.strictEqual(packets.length, 1);
  assert.strictEqual(packets[0].payload.policy.cameraLocked, true);
  assert.ok(h.moderation.some(function (event) { return event.action === "media_policy_update"; }));
});

test("the waiting-room cue defaults to one knock and nothing after it", async function () {
  // The default is the whole safety story for this field: an unconfigured
  // meeting, an older client and a tenant that has never touched the setting
  // must all behave exactly as Meet did before it existed.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const view = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: HOST_HEADERS });
  assert.strictEqual(view.status, 200);
  assert.strictEqual(view.body.policy.joinRequestSoundRepeats, 1);
});

test("a host may silence the waiting-room cue or ask for it three times", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);

  const off = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { joinRequestSoundRepeats: 0 } },
  });
  assert.strictEqual(off.status, 200);
  assert.deepStrictEqual(off.body.changed, ["joinRequestSoundRepeats"]);
  assert.strictEqual(off.body.policy.joinRequestSoundRepeats, 0);
  assert.strictEqual(off.body.policy.version, 2);

  const loud = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { joinRequestSoundRepeats: 3 } },
  });
  assert.strictEqual(loud.status, 200);
  assert.strictEqual(loud.body.policy.joinRequestSoundRepeats, 3);

  // Every moderator is told, because the cue is theirs too.
  const packets = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.policy.v1" && call.payload.kind === "policy";
  });
  assert.strictEqual(packets[packets.length - 1].payload.policy.joinRequestSoundRepeats, 3);
});

test("a repeat count outside the offered range is refused, never clamped", async function () {
  // Clamping would answer 200 for a value this server did not store, and the
  // client would believe it had set it. The *store* clamps because it must be
  // total; the router refuses because a client must not be misled.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const rejected = [4, -1, 2.5, "2", true, null];
  for (let i = 0; i < rejected.length; i++) {
    const answer = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
      headers: HOST_HEADERS,
      body: { policy: { joinRequestSoundRepeats: rejected[i] } },
    });
    assert.strictEqual(answer.status, 400, "expected 400 for " + JSON.stringify(rejected[i]));
    assert.ok(
      /joinRequestSoundRepeats/.test(answer.body.error || ""),
      "the error must name the field: " + answer.body.error
    );
  }
  const view = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: HOST_HEADERS });
  assert.strictEqual(view.body.policy.joinRequestSoundRepeats, 1, "a refused patch must change nothing");
  assert.strictEqual(view.body.policy.version, 1);
});

test("the repeat count survives a change to an unrelated policy field", async function () {
  // `setPolicy` writes the whole hash. A field missing from that block survives
  // its own PATCH and is then erased by the next unrelated change -- the same
  // shape as the participant-metadata wipe that took everybody's avatar away.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { joinRequestSoundRepeats: 3 } },
  });
  const other = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { chatLocked: true } },
  });
  assert.strictEqual(other.status, 200);
  assert.deepStrictEqual(other.body.changed, ["chatLocked"]);
  assert.strictEqual(other.body.policy.joinRequestSoundRepeats, 3);

  const view = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: HOST_HEADERS });
  assert.strictEqual(view.body.policy.joinRequestSoundRepeats, 3, "the stored hash lost the field");
});

test("setting the repeat count to what it already is costs nothing", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const same = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { joinRequestSoundRepeats: 1 } },
  });
  assert.strictEqual(same.status, 200);
  assert.deepStrictEqual(same.body.changed, []);
  assert.strictEqual(same.body.policy.version, 1, "a retried request must not invalidate every other client's view");
});

test("a stale version conflicts, and a participant may not set the cue at all", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { joinRequestSoundRepeats: 2 } },
  });
  const stale = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { joinRequestSoundRepeats: 3 }, expectedVersion: 1 },
  });
  assert.strictEqual(stale.status, 409);
  assert.strictEqual(stale.body.policy.joinRequestSoundRepeats, 2);

  const guest = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: s.guestHeaders,
    body: { policy: { joinRequestSoundRepeats: 0 } },
  });
  assert.strictEqual(guest.status, 403);
});

test("promotion hands a locked participant their devices back", async function () {
  // The reported bug: a host locks cameras and microphones, promotes somebody to
  // co-host, and that co-host still cannot turn anything on. The role moved and
  // the grant did not -- `updateParticipantRole` writes metadata only, so the
  // participant-era allow-list survived a promotion that should have lifted it.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { cameraLocked: true, microphoneLocked: true } },
  });
  const before = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: s.guestHeaders });
  assert.deepStrictEqual(before.body.mediaLocks, { microphone: true, camera: true });

  h.gatewayCalls.length = 0;
  const promoted = await h.call("POST", "/rooms/" + s.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: s.guestId, role: "cohost" },
  });
  assert.strictEqual(promoted.status, 200);

  const granted = policyCalls(h, "permissions").filter(function (call) {
    return call.identity === s.guestId;
  });
  assert.strictEqual(granted.length, 1);
  assert.deepStrictEqual(granted[0].publishSources, ["microphone", "camera", "screenShare"]);
  // And the participant's own view agrees, so the client is not left holding a
  // stale "locked" answer about somebody who is now exempt.
  const after = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: s.guestHeaders });
  assert.deepStrictEqual(after.body.mediaLocks, { microphone: false, camera: false });
});

test("demotion takes the devices away again, and stops what is already live", async function () {
  // The same gap in the other direction, and the dangerous one: nothing
  // re-restricted a demoted co-host, so promote-then-demote was a permanent
  // exemption from a lock the host believes is in force.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { cameraLocked: true } },
  });

  h.gatewayCalls.length = 0;
  const demoted = await h.call("POST", "/rooms/" + s.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: s.cohostId, role: "participant" },
  });
  assert.strictEqual(demoted.status, 200);

  const restricted = policyCalls(h, "permissions").filter(function (call) {
    return call.identity === s.cohostId;
  });
  assert.strictEqual(restricted.length, 1);
  assert.deepStrictEqual(restricted[0].publishSources, ["microphone", "screenShare"]);
  // A permission change only prevents the next publish; the live camera has to
  // be stopped or the lock reads as having done nothing.
  assert.ok(policyCalls(h, "mute").some(function (call) {
    return call.identity === s.cohostId && call.source === "camera";
  }));
});

test("a role change in an unlocked room touches no grant at all", async function () {
  // Absent `publishSources` is meaningful, not merely empty. Sending an
  // allow-list into a room with no lock would rewrite the grant on every
  // promotion in every ordinary meeting.
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);

  h.gatewayCalls.length = 0;
  const promoted = await h.call("POST", "/rooms/" + s.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: s.guestId, role: "cohost" },
  });
  assert.strictEqual(promoted.status, 200);
  assert.deepStrictEqual(policyCalls(h, "permissions"), []);
  assert.deepStrictEqual(policyCalls(h, "mute"), []);
});

test("promotion restores a real name that the policy had aliased", async function () {
  // `applyPolicyToRoom` already documented that a promoted co-host needs their
  // name back -- but only a later policy change delivered it, so until the host
  // touched the policy again a co-host kept introducing themselves as
  // "Participant 2".
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { hideParticipantNames: "participants" } },
  });

  h.gatewayCalls.length = 0;
  await h.call("POST", "/rooms/" + s.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: s.guestId, role: "cohost" },
  });
  const named = policyCalls(h, "name").filter(function (call) {
    return call.identity === s.guestId;
  });
  assert.strictEqual(named.length, 1);
  assert.strictEqual(named[0].name, "Guest One");

  // ...and a demotion puts the alias back, or hiding names would be one-way.
  h.gatewayCalls.length = 0;
  await h.call("POST", "/rooms/" + s.meetingId + "/role", {
    headers: HOST_HEADERS,
    body: { participantId: s.guestId, role: "participant" },
  });
  const realiased = policyCalls(h, "name").filter(function (call) {
    return call.identity === s.guestId;
  });
  assert.strictEqual(realiased.length, 1);
  assert.notStrictEqual(realiased[0].name, "Guest One");
});

test("re-applying a policy that already holds is free and does not bump the version", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { microphoneLocked: true } },
  });
  const again = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { microphoneLocked: true } },
  });
  assert.strictEqual(again.status, 200);
  assert.deepStrictEqual(again.body.changed, []);
  assert.strictEqual(again.body.policy.version, 2, "a no-op retry must not invalidate other clients' views");
});

test("a stale expectedVersion is a conflict carrying the current policy", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cameraLocked: true } },
  });
  const stale = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { microphoneLocked: true }, expectedVersion: 1 },
  });
  assert.strictEqual(stale.status, 409);
  assert.strictEqual(stale.body.code, "VERSION_CONFLICT");
  assert.strictEqual(stale.body.policy.version, 2);
});

test("relocking a source clears the exemptions granted under the previous lock", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cameraLocked: true } },
  });
  await h.call("POST", "/rooms/" + s.meetingId + "/participants/" + s.guestId + "/media/grant", {
    headers: HOST_HEADERS, body: { source: "camera", allowed: true },
  });
  const granted = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: s.guestHeaders });
  assert.strictEqual(granted.body.mediaLocks.camera, false);

  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cameraLocked: false } },
  });
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cameraLocked: true } },
  });
  const relocked = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: s.guestHeaders });
  assert.strictEqual(
    relocked.body.mediaLocks.camera,
    true,
    "lock-all must mean everyone, not everyone-except-earlier-exemptions"
  );
});

test("co-hosts lose policy authority when the host reserves it", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const allowed = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: s.cohostHeaders, body: { policy: { cameraLocked: true } },
  });
  assert.strictEqual(allowed.status, 200);

  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cohostsMayManageMedia: false } },
  });
  const refused = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: s.cohostHeaders, body: { policy: { microphoneLocked: true } },
  });
  assert.strictEqual(refused.status, 403);
  const stillHost = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { microphoneLocked: true } },
  });
  assert.strictEqual(stillHost.status, 200, "the host can never be locked out of their own controls");
});

test("a participant asks for a locked source, is answered, and cannot immediately re-ask", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { microphoneLocked: true } },
  });

  const asked = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "microphone" },
  });
  assert.strictEqual(asked.status, 201);
  const requestId = asked.body.request.id;

  const queue = await h.call("GET", "/rooms/" + s.meetingId + "/media/access-requests", { headers: HOST_HEADERS });
  assert.strictEqual(queue.body.requests.length, 1);
  assert.strictEqual(queue.body.requests[0].participantName, "Guest One");

  const approved = await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/media/access-requests/" + requestId + "/respond",
    { headers: HOST_HEADERS, body: { decision: "approved" } }
  );
  assert.strictEqual(approved.status, 200);
  assert.strictEqual(approved.body.grants.microphone, true);
  // Approval is permission, never an action: the microphone is unlocked, not
  // switched on, so no mute/unmute call is made on the participant's behalf.
  assert.ok(policyCalls(h, "permissions").some(function (call) {
    return call.identity === s.guestId && call.publishSources.indexOf("microphone") !== -1;
  }));

  const asAgain = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "microphone" },
  });
  assert.strictEqual(asAgain.status, 409, "nothing left to ask for once it is granted");
});

test("a denied participant is held off by the cooldown, not merely asked to wait", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cameraLocked: true } },
  });
  const asked = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "camera" },
  });
  await h.call(
    "POST",
    "/rooms/" + s.meetingId + "/media/access-requests/" + asked.body.request.id + "/respond",
    { headers: HOST_HEADERS, body: { decision: "denied" } }
  );
  const again = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "camera" },
  });
  assert.strictEqual(again.status, 429);
  assert.ok(Number(again.headers["Retry-After"]) > 0);
});

test("access requests are refused outright when the host turned them off", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { cameraLocked: true, cameraRequestsAllowed: false } },
  });
  const asked = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "camera" },
  });
  assert.strictEqual(asked.status, 403);
  const microphone = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "microphone" },
  });
  assert.strictEqual(microphone.status, 409, "an unlocked source has nothing to request");
});

test("name privacy renames the room at the SFU and leaves the real roster intact", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const hidden = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "participants" } },
  });
  assert.strictEqual(hidden.status, 200);
  const renames = policyCalls(h, "name");
  const guestRename = renames.find(function (call) { return call.identity === s.guestId; });
  assert.strictEqual(guestRename.name, "Participant 1");
  // Moderators keep their names: they are the accountable identities in the room.
  assert.strictEqual(renames.find(function (call) { return call.identity === "host-owner-1"; }).name, "Jane Owner");
  assert.strictEqual(renames.find(function (call) { return call.identity === s.cohostId; }).name, "Casey Cohost");

  const roster = await h.call("GET", "/rooms/" + s.meetingId + "/participants", { headers: HOST_HEADERS });
  assert.strictEqual(roster.status, 200);
  const guestRow = roster.body.participants.find(function (row) { return row.participantId === s.guestId; });
  assert.strictEqual(guestRow.displayName, "Guest One", "moderation needs the real name");
  assert.strictEqual(guestRow.alias, "Participant 1");

  const restored = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "off" } },
  });
  assert.strictEqual(restored.status, 200);
  const back = policyCalls(h, "name").filter(function (call) { return call.identity === s.guestId; });
  assert.strictEqual(back[back.length - 1].name, "Guest One");
});

test("chat serves the alias while names are hidden and keeps the stored name", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({
    collaboration: collaboration,
    features: { chat: true, hostPrivacyControls: true, participantMediaControls: true },
  });
  const s = await seedInteractionRoom(h);
  collaboration.meetings[s.meetingId] = { owner_key: "owner-1", status: "live" };

  const sent = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.guestHeaders,
    body: { clientMessageId: "dddddddd-dddd-4ddd-8ddd-dddddddddddd", body: "hello" },
  });
  assert.strictEqual(sent.status, 201);
  assert.strictEqual(sent.body.message.senderName, "Guest One", "a room that hides nothing is unchanged");

  const hidden = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "participants" } },
  });
  assert.strictEqual(hidden.status, 200);

  // Read back by the guest: chat used to be the one surface still announcing
  // who everybody was while every tile beside it said "Participant 1".
  const read = await h.call("GET", "/rooms/" + s.meetingId + "/chat/messages", { headers: s.guestHeaders });
  const row = read.body.messages.find(function (message) { return message.id === sent.body.message.id; });
  assert.strictEqual(row.senderName, "Participant 1");

  // A moderator is never aliased at the SFU, so chat must not invent one.
  const fromHost = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: HOST_HEADERS,
    body: { clientMessageId: "eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee", body: "noted" },
  });
  assert.strictEqual(fromHost.body.message.senderName, "Jane Owner");

  // The packet no client can refuse carries the alias too, so a modified client
  // has nothing to un-hide.
  const masked = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.guestHeaders,
    body: { clientMessageId: "ffffffff-ffff-4fff-8fff-ffffffffffff", body: "again" },
  });
  const packet = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.chat.v1" && call.payload.message.id === masked.body.message.id;
  }).pop();
  assert.strictEqual(packet.payload.message.senderName, "Participant 1");

  // Storage, moderation and the audit trail keep the real name: the mask is a
  // read-time rule, not a rewrite.
  assert.strictEqual(collaboration.messages[sent.body.message.id].senderName, "Guest One");
  const roster = await h.call("GET", "/rooms/" + s.meetingId + "/participants", { headers: HOST_HEADERS });
  assert.strictEqual(
    roster.body.participants.find(function (r) { return r.participantId === s.guestId; }).displayName,
    "Guest One"
  );

  const restored = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "off" } },
  });
  assert.strictEqual(restored.status, 200);
  const visible = await h.call("GET", "/rooms/" + s.meetingId + "/chat/messages", { headers: s.guestHeaders });
  assert.strictEqual(
    visible.body.messages.find(function (message) { return message.id === sent.body.message.id; }).senderName,
    "Guest One"
  );
});


// ── one policy, every surface ───────────────────────────────────────────────
//
// Chat was masked in 2026-08-07 and nothing else was. These pin the rest of the
// family: a control that hides a name on the tiles while the hand queue, the
// live captions and the pen label go on printing it has hidden nothing, and all
// three of those are read by every participant, not just a moderator.

test("the hand queue is masked for everybody, not only on the tiles", async function () {
  // A hand is one transition per second, so lowering and re-raising the same
  // hand needs the clock to move. Without this the second raise is refused and
  // the packet assertion below reads a stale broadcast from before the policy.
  let now = 0;
  const h = harness({
    handLimiter: new rateLimitMod.RateLimiter({ windowMs: 1000, now: function () { return now; } }),
    features: { hostPrivacyControls: true, reactions: true, participantMediaControls: true },
  });
  const s = await seedInteractionRoom(h);
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", { headers: s.guestHeaders, body: { raised: true } });

  const before = await h.call("GET", "/rooms/" + s.meetingId + "/interactions", { headers: s.guestHeaders });
  assert.strictEqual(before.body.hands[0].displayName, "Guest One", "a room that hides nothing is unchanged");

  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "participants" } },
  });

  // Read by every participant on reconnect, and by the panel on every repair
  // tick. This was the worst of the leaks: it reached people who are not
  // entitled to the name at all.
  const after = await h.call("GET", "/rooms/" + s.meetingId + "/interactions", { headers: s.guestHeaders });
  assert.strictEqual(after.body.hands[0].displayName, "Participant 1");

  // The packet nobody can refuse carries the alias too, so a modified client has
  // nothing to un-hide.
  now += 1000;
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", { headers: s.guestHeaders, body: { raised: false } });
  now += 1000;
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", { headers: s.guestHeaders, body: { raised: true } });
  const packet = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.interaction.v1" &&
      call.payload.kind === "hand" && call.payload.raised && call.payload.participantId === s.guestId;
  }).pop();
  assert.strictEqual(packet.payload.displayName, "Participant 1");

  // A moderator is never aliased at the SFU, so nothing may invent one for them.
  now += 1000;
  await h.call("POST", "/rooms/" + s.meetingId + "/hand", { headers: HOST_HEADERS, body: { raised: true } });
  const withHost = await h.call("GET", "/rooms/" + s.meetingId + "/interactions", { headers: s.guestHeaders });
  const hostHand = withHost.body.hands.find(function (hand) { return hand.participantId === "host-owner-1"; });
  assert.strictEqual(hostHand.displayName, "Jane Owner");

  // Storage keeps the real name: the mask is a read-time rule, not a rewrite.
  const store = await tenantStore(h);
  const stored = await store.getHand(s.meetingId, s.guestId);
  assert.strictEqual(stored.displayName, "Guest One");
});

test("the pen label and a live caption answer to the name policy", async function () {
  const secret = "transcriber-callback-secret-that-is-long-enough";
  const h = harness({
    transcriberSecret: secret,
    features: {
      hostPrivacyControls: true, participantMediaControls: true,
      whiteboard: true, whiteboardEnhancements: true, meetingTranscription: true,
    },
    collaboration: fakeCollaboration(),
    capture: { ingestTranscript: async function () { return { accepted: true, participantName: "Guest One" }; } },
  });
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "participants" } },
  });

  // The pen trail is labelled with its owner on every other board in the room.
  const presence = await h.call("POST", "/rooms/" + s.meetingId + "/whiteboard/presence", {
    headers: s.guestHeaders,
    body: {
      gestureId: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
      phase: "start", tool: "pen", point: [0.5, 0.5], color: 0xff2563eb, width: 4,
    },
  });
  assert.strictEqual(presence.status, 202);
  const trail = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.whiteboard-presence.v1";
  }).pop();
  assert.strictEqual(trail.payload.participantName, "Participant 1");

  // The caption strip is drawn for the whole room, so it is a participant-facing
  // surface even though only the host can start transcription.
  const caption = await h.call("POST", "/callbacks/transcript", {
    headers: { authorization: "Bearer " + secret },
    body: {
      meetingId: s.meetingId, segmentId: "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
      participantRef: s.guestId, trackSid: "TR_1", sequence: 1,
      startMs: 0, endMs: 1000, language: "en", text: "hello", final: true,
    },
  });
  assert.strictEqual(caption.status, 201);
  const line = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.transcript.v1";
  }).pop();
  assert.strictEqual(line.payload.segment.participantName, "Participant 1");
  assert.strictEqual(line.payload.segment.text, "hello", "only the name is masked");
});

test("hidden from everyone hides the name from the moderator's panel too", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);

  // `participants` keeps the roster real: that mode exists precisely so a
  // moderator can still identify somebody, and the tiles, chat and hand queue
  // all restore their names from here.
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "participants" } },
  });
  const forModeration = await h.call("GET", "/rooms/" + s.meetingId + "/participants", { headers: HOST_HEADERS });
  const guestRow = function (response) {
    return response.body.participants.find(function (row) { return row.participantId === s.guestId; });
  };
  assert.strictEqual(guestRow(forModeration).displayName, "Guest One");

  // `everyone` used to differ only on the host's own tiles, so the panel two
  // inches away still read the whole room. It is not exempt any more.
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "everyone" } },
  });
  const hidden = await h.call("GET", "/rooms/" + s.meetingId + "/participants", { headers: HOST_HEADERS });
  assert.strictEqual(guestRow(hidden).displayName, "Participant 1");
  assert.strictEqual(guestRow(hidden).alias, "Participant 1", "the alias field still says what it always did");

  // The moderators keep their own names: they are the accountable people in the
  // room and the SFU publishes them unaliased.
  const hostRow = hidden.body.participants.find(function (row) { return row.participantId === "host-owner-1"; });
  assert.strictEqual(hostRow.displayName, "Jane Owner");
  const cohostRow = hidden.body.participants.find(function (row) { return row.participantId === s.cohostId; });
  assert.strictEqual(cohostRow.displayName, "Casey Cohost");

  // Grants and locks are unaffected — masking a name must not cost a moderator
  // the controls the panel exists for.
  assert.deepStrictEqual(guestRow(hidden).grants, { microphone: false, camera: false });

  // And the record is untouched: switching back restores everything.
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "off" } },
  });
  const restored = await h.call("GET", "/rooms/" + s.meetingId + "/participants", { headers: HOST_HEADERS });
  assert.strictEqual(guestRow(restored).displayName, "Guest One");
});

test("an access request names the asker under the policy", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { microphoneLocked: true, hideParticipantNames: "participants" } },
  });

  const asked = await h.call("POST", "/rooms/" + s.meetingId + "/media/access-requests", {
    headers: s.guestHeaders, body: { source: "microphone" },
  });
  assert.strictEqual(asked.status, 201);
  assert.strictEqual(asked.body.request.participantName, "Participant 1");

  // Broadcast room-wide, so "only a moderator has UI for it" is not protection.
  const packet = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.policy.v1" && call.payload.kind === "accessRequest";
  }).pop();
  assert.strictEqual(packet.payload.request.participantName, "Participant 1");

  const queue = await h.call("GET", "/rooms/" + s.meetingId + "/media/access-requests", { headers: HOST_HEADERS });
  assert.strictEqual(queue.body.requests[0].participantName, "Participant 1");
});

test("an alias is stable across a rejoin", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "everyone" } },
  });
  const first = policyCalls(h, "name").find(function (call) { return call.identity === s.guestId; }).name;
  const store = await tenantStore(h);
  await store.removeParticipant(s.meetingId, s.guestId);
  await store.addParticipant(s.meetingId, s.guestId, { displayName: "Guest One", role: "participant" });
  assert.strictEqual(await store.participantAlias(s.meetingId, s.guestId), first);
});

test("the participant roster is moderator-only and never an id oracle", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const asGuest = await h.call("GET", "/rooms/" + s.meetingId + "/participants", { headers: s.guestHeaders });
  assert.strictEqual(asGuest.status, 403);
  const unknownMeeting = await h.call("GET", "/rooms/does-not-exist/participants", { headers: {} });
  assert.strictEqual(unknownMeeting.status, 401, "authorization is answered before existence");
});

test("a participant sees only their own grants", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { microphoneLocked: true } },
  });
  await h.call("POST", "/rooms/" + s.meetingId + "/participants/" + s.guestId + "/media/grant", {
    headers: HOST_HEADERS, body: { source: "microphone", allowed: true },
  });
  const view = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: s.guestHeaders });
  assert.strictEqual(view.status, 200);
  assert.deepStrictEqual(view.body.grants, { microphone: true, camera: false });
  assert.strictEqual(view.body.canManage, false);
  assert.strictEqual(view.body.policy.microphoneLocked, true);
});

const CHAT_PRIVACY = {
  features: {
    hostPrivacyControls: true,
    participantMediaControls: true,
    chat: true,
    chatAttachments: true,
  },
  // Healthy scanner and a receive that never runs when the gate does its job.
  attachments: {
    health: async function () { return true; },
    receive: async function () { return { id: "aaaaaaaa-aaaa-4aaa-8aaa-000000000099" }; },
  },
};

/**
 * A live room with a real roster and a chat store behind it.
 *
 * `seedInteractionRoom` alone leaves the collaboration repository unaware of
 * the meeting, so every chat write answers 503 and a permission test passes for
 * entirely the wrong reason.
 */
async function seedChatRoom(options) {
  const collaboration = fakeCollaboration();
  const h = harness(Object.assign({ collaboration: collaboration }, options || CHAT_PRIVACY));
  const s = await seedInteractionRoom(h);
  collaboration.meetings[s.meetingId] = { owner_key: "owner-1", status: "live" };
  return { h: h, s: s, collaboration: collaboration };
}

/** Distinct v4-shaped ids; the router validates the shape, not the entropy. */
let chatIdSeq = 0;
function chatUuid() {
  chatIdSeq += 1;
  const tail = String(chatIdSeq).padStart(12, "0");
  return "aaaaaaaa-aaaa-4aaa-8aaa-" + tail;
}

test("restricted chat refuses a participant's message, edit and upload", async function () {
  const { h, s } = await seedChatRoom();

  // Sent while chat is open, so there is something of theirs to try to edit
  // after the restriction lands.
  const before = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.guestHeaders, body: { clientMessageId: chatUuid(), body: "before" },
  });
  assert.strictEqual(before.status, 201);

  const locked = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { chatLocked: true } },
  });
  assert.strictEqual(locked.status, 200);
  assert.strictEqual(locked.body.policy.chatLocked, true);

  const send = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.guestHeaders, body: { clientMessageId: chatUuid(), body: "after" },
  });
  assert.strictEqual(send.status, 403, "a restricted participant may not post");
  assert.strictEqual(send.body.code, "CHAT_LOCKED");

  // An edit is a write: rewriting an old message is how a hidden composer is
  // defeated when only sending is gated.
  const edit = await h.call("PATCH", "/rooms/" + s.meetingId + "/chat/messages/" + before.body.message.id, {
    headers: s.guestHeaders, body: { body: "rewritten", expectedVersion: before.body.message.version },
  });
  assert.strictEqual(edit.status, 403, "a restricted participant may not edit");

  const upload = await h.call("POST", "/rooms/" + s.meetingId + "/chat/attachments", {
    headers: s.guestHeaders, body: {},
  });
  assert.strictEqual(upload.status, 403, "an attachment is a post");
  assert.strictEqual(upload.body.code, "CHAT_LOCKED");

  // Reading is never restricted — the transcript is a meeting record.
  const read = await h.call("GET", "/rooms/" + s.meetingId + "/chat/messages", { headers: s.guestHeaders });
  assert.strictEqual(read.status, 200);

  // ...and deleting your own words is not what the restriction protects from.
  const removed = await h.call("DELETE", "/rooms/" + s.meetingId + "/chat/messages/" + before.body.message.id, {
    headers: s.guestHeaders, body: { expectedVersion: before.body.message.version },
  });
  assert.strictEqual(removed.status, 200, "own delete stays available");
});

test("restricted chat still lets the host and co-hosts speak", async function () {
  const { h, s } = await seedChatRoom();
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { chatLocked: true } },
  });

  const host = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: HOST_HEADERS, body: { clientMessageId: chatUuid(), body: "from the host" },
  });
  assert.strictEqual(host.status, 201);
  const cohost = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.cohostHeaders, body: { clientMessageId: chatUuid(), body: "from a co-host" },
  });
  assert.strictEqual(cohost.status, 201, "a co-host is a moderator here");

  // Lifting it lets everybody back in without a restart.
  await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { chatLocked: false } },
  });
  const again = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.guestHeaders, body: { clientMessageId: chatUuid(), body: "back" },
  });
  assert.strictEqual(again.status, 201);
});

test("a stored chat lock is inert while host privacy controls are off", async function () {
  // Every other policy field already fails open when the feature is disabled
  // (token minting ANDs the same flag). Enforcing this one anyway would leave
  // a room chat-locked with no way to lift it, because the PATCH that lifts it
  // is gated on the very flag that was turned off.
  const { h, s } = await seedChatRoom({ features: { chat: true } });
  // Written straight to the store: with the flag off the PATCH that would set
  // it is itself refused, which is the whole point of the lockout being real.
  await s.store.setPolicy(s.meetingId, { chatLocked: true }, { actorId: "host-owner-1" });
  const stored = await s.store.getPolicy(s.meetingId);
  assert.strictEqual(stored.chatLocked, true, "the lock really is stored");

  const send = await h.call("POST", "/rooms/" + s.meetingId + "/chat/messages", {
    headers: s.guestHeaders, body: { clientMessageId: chatUuid(), body: "still allowed" },
  });
  assert.strictEqual(send.status, 201);
});

test("an unknown or mistyped policy field is refused rather than ignored", async function () {
  const h = harness(PRIVACY);
  const s = await seedInteractionRoom(h);
  const unknownField = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { camerasLocked: true } },
  });
  assert.strictEqual(unknownField.status, 400);
  const wrongType = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { cameraLocked: "yes" } },
  });
  assert.strictEqual(wrongType.status, 400);
  // Never a silent downgrade to "off": that would answer 200 while leaving every
  // name visible.
  const badVisibility = await h.call("PATCH", "/rooms/" + s.meetingId + "/policy", {
    headers: HOST_HEADERS, body: { policy: { hideParticipantNames: "sometimes" } },
  });
  assert.strictEqual(badVisibility.status, 400);
  const unchanged = await h.call("GET", "/rooms/" + s.meetingId + "/policy", { headers: HOST_HEADERS });
  assert.strictEqual(unchanged.body.policy.version, 1);
});

test("every privacy route is 404 while the feature flag is off", async function () {
  const h = harness({ features: { participantMediaControls: true } });
  const s = await seedInteractionRoom(h);
  const routes = [
    ["GET", "/rooms/" + s.meetingId + "/policy", HOST_HEADERS, {}],
    ["PATCH", "/rooms/" + s.meetingId + "/policy", HOST_HEADERS, { policy: { cameraLocked: true } }],
    ["GET", "/rooms/" + s.meetingId + "/participants", HOST_HEADERS, {}],
    ["POST", "/rooms/" + s.meetingId + "/media/access-requests", s.guestHeaders, { source: "camera" }],
    ["GET", "/rooms/" + s.meetingId + "/media/access-requests", HOST_HEADERS, {}],
  ];
  for (const [method, path, headers, body] of routes) {
    const response = await h.call(method, path, { headers: headers, body: body });
    assert.strictEqual(response.status, 404, method + " " + path + " must be 404 when disabled");
  }
  const created = await createMeeting(h, { policy: { cameraLocked: true } });
  assert.strictEqual(created.status, 404, "a policy cannot be set through create either");
});


/** Join one more ordinary participant, so the presenter is not the only one. */
async function seedExtraParticipant(h, s, name) {
  const join = await h.call("POST", "/rooms/" + s.meetingId + "/join-request", {
    body: { displayName: name, email: name.replace(/\s+/g, ".").toLowerCase() + "@example.com" },
  });
  const id = join.body.participantId;
  await s.store.setGuestDecisionByParticipant(s.meetingId, id, "admitted");
  await s.store.removeWaiting(s.meetingId, id);
  await s.store.addParticipant(s.meetingId, id, { displayName: name, role: "participant" });
  return { id: id, headers: { authorization: "Bearer " + join.body.guestToken } };
}

/** A room with `slides.pdf` already on the stage, presented by the guest. */
async function seedPresentedDeck(h) {
  const s = await seedInteractionRoom(h);
  const started = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/start", {
    headers: s.guestHeaders,
    body: {
      clientActionId: "20000000-0000-4000-8000-000000000001",
      expectedVersion: 0,
      assetId: "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb",
    },
  });
  assert.strictEqual(started.status, 200);
  return s;
}

function deckHarness() {
  const collaboration = fakeCollaboration();
  return harness({
    collaboration: collaboration,
    documents: {
      health: async function () { return true; },
      prepare: async function () { return collaboration.getFileAsset(null, null, "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"); },
      manifest: function (_tenant, _meeting, asset) { return asset; },
    },
    attachments: { health: async function () { return true; } },
    features: { filePresentations: true },
  });
}

const PEN = [{
  type: "stroke",
  element: { id: "aaaa0000-0000-4000-8000-000000000001", kind: "pen", color: 1, width: 4, points: [[0, 0], [0.5, 0.5]] },
}];

test("a deck starts closed: only the presenter and moderators may draw on it", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const other = await seedExtraParticipant(h, s, "Dana Viewer");

  const refused = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: other.headers,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000001", pageNumber: 1, operations: PEN },
  });
  assert.strictEqual(refused.status, 403);
  assert.strictEqual(refused.body.code, "annotation_forbidden");

  const presenter = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000002", pageNumber: 1, operations: PEN },
  });
  assert.strictEqual(presenter.status, 201);

  // A moderator draws without being invited, exactly as they may page and stop.
  const cohost = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.cohostHeaders,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000003", pageNumber: 1, operations: PEN },
  });
  assert.strictEqual(cohost.status, 201);
});

test("the presenter opens the deck, and everyone can draw until it is closed again", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const other = await seedExtraParticipant(h, s, "Dana Viewer");

  const opened = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/mode", {
    headers: s.guestHeaders,
    body: { clientActionId: "20000000-0000-4000-8000-000000000002", expectedVersion: 1, annotationMode: "everyone" },
  });
  assert.strictEqual(opened.status, 200);
  assert.strictEqual(opened.body.presentation.annotationMode, "everyone");

  const drawn = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: other.headers,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000004", pageNumber: 1, operations: PEN },
  });
  assert.strictEqual(drawn.status, 201);

  const closed = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/mode", {
    headers: s.guestHeaders,
    body: { clientActionId: "20000000-0000-4000-8000-000000000003", expectedVersion: 2, annotationMode: "presenter" },
  });
  assert.strictEqual(closed.status, 200);

  const refusedAgain = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: other.headers,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000005", pageNumber: 1, operations: PEN },
  });
  assert.strictEqual(refusedAgain.status, 403);
});

test("an invited participant may draw but may not wipe the page", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const other = await seedExtraParticipant(h, s, "Dana Viewer");
  await h.call("POST", "/rooms/" + s.meetingId + "/presentation/mode", {
    headers: s.guestHeaders,
    body: { clientActionId: "20000000-0000-4000-8000-000000000004", expectedVersion: 1, annotationMode: "everyone" },
  });

  const refused = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations/clear", {
    headers: other.headers,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000006", pageNumber: 1, expectedVersion: 0 },
  });
  assert.strictEqual(refused.status, 403,
    "drawing is additive; wiping everybody's work is not the same permission");

  const cleared = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations/clear", {
    headers: s.guestHeaders,
    body: { clientBatchId: "30000000-0000-4000-8000-000000000007", pageNumber: 1, expectedVersion: 0 },
  });
  assert.strictEqual(cleared.status, 200);
});

// Inverted rather than deleted: this guard used to assert that a slide refused
// shapes and text. The slide now takes the whiteboard's whole tool set, so what
// has to be pinned is the opposite — with the negative control kept, because
// "accepts everything" would pass a test that only checks shapes are allowed.
test("a slide takes the whiteboard's whole tool set, and still refuses what is not a tool", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const accepted = [
    [{ type: "shape", element: { id: "aaaa0000-0000-4000-8000-000000000009", kind: "rectangle", color: 1, width: 4, points: [[0, 0], [1, 1]] } }],
    [{ type: "text", element: { id: "aaaa0000-0000-4000-8000-00000000000a", kind: "text", color: 1, width: 4, text: "hello", points: [[0, 0]] } }],
  ];
  for (const operations of accepted) {
    const ok = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
      headers: s.guestHeaders,
      body: { clientBatchId: "30000000-0000-4000-8000-00000000000" + operations[0].type.length, pageNumber: 1, operations: operations },
    });
    assert.strictEqual(ok.status, 201, operations[0].type + " must be accepted on a slide");
  }
  // A move is an upsert of an id that already exists, and needs no new type.
  const moved = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: {
      clientBatchId: "30000000-0000-4000-8000-00000000000d", pageNumber: 1,
      operations: [{ type: "shape", element: { id: "aaaa0000-0000-4000-8000-000000000009", kind: "rectangle", color: 1, width: 4, points: [[0.2, 0.2], [0.9, 0.9]] } }],
    },
  });
  assert.strictEqual(moved.status, 201, "re-submitting an existing id must be an ordinary upsert");
  // The negative control: a type that is not a tool at all is still refused.
  const refused = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: {
      clientBatchId: "30000000-0000-4000-8000-00000000000e", pageNumber: 1,
      operations: [{ type: "teleport", element: { id: "aaaa0000-0000-4000-8000-00000000000f", kind: "pen", color: 1, width: 4, points: [[0, 0]] } }],
    },
  });
  assert.strictEqual(refused.status, 400, "an unknown operation type must still be refused");
  // The eraser is part of the slide set and must still be accepted.
  const erased = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: {
      clientBatchId: "30000000-0000-4000-8000-00000000000b", pageNumber: 1,
      operations: [{ type: "erase", elementIds: ["aaaa0000-0000-4000-8000-000000000001"] }],
    },
  });
  assert.strictEqual(erased.status, 201);
});

test("ink can only be written onto the file the room is actually looking at", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const wrongAsset = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: {
      clientBatchId: "30000000-0000-4000-8000-00000000000c",
      assetId: "cccccccc-cccc-4ccc-8ccc-cccccccccccc",
      pageNumber: 1, operations: PEN,
    },
  });
  assert.strictEqual(wrongAsset.status, 409);
  assert.strictEqual(wrongAsset.body.code, "presentation_not_active");

  const offTheEnd = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: { clientBatchId: "30000000-0000-4000-8000-00000000000d", pageNumber: 99, operations: PEN },
  });
  assert.strictEqual(offTheEnd.status, 400);
});

test("there is nothing to annotate when nothing is being presented", async function () {
  const h = deckHarness();
  const s = await seedInteractionRoom(h);
  const refused = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: { clientBatchId: "30000000-0000-4000-8000-00000000000e", pageNumber: 1, operations: PEN },
  });
  assert.strictEqual(refused.status, 409);
  assert.strictEqual(refused.body.code, "presentation_not_active");
});

test("a page's ink is announced to the room without the document riding the packet", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations", {
    headers: s.guestHeaders,
    body: { clientBatchId: "30000000-0000-4000-8000-00000000000f", pageNumber: 2, operations: PEN },
  });
  const packets = dataBroadcasts(h).filter(function (call) {
    return call.topic === "ringq.meet.presentation.v1" && call.payload.kind === "annotations";
  });
  assert.strictEqual(packets.length, 1);
  const announced = packets[0].payload.annotations;
  assert.strictEqual(announced.pageNumber, 2);
  assert.ok(announced.version > 0);
  // The document can be far larger than the reliable-data ceiling. The packet
  // is a nudge carrying the version; the client re-reads over HTTP.
  assert.strictEqual(announced.document, undefined);
});

test("a live pen on a slide reaches the room, labelled and page-scoped", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const sent = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations/presence", {
    headers: s.guestHeaders,
    body: {
      gestureId: "40000000-0000-4000-8000-000000000001", phase: "move", tool: "pen",
      point: [0.25, 0.75], color: 0xff2563eb, width: 4, pageNumber: 1,
    },
  });
  assert.strictEqual(sent.status, 202);
  const packets = h.gatewayCalls.filter(function (item) {
    return item.topic === "ringq.meet.presentation-presence.v1";
  });
  assert.strictEqual(packets.length, 1, "one lossy packet per sample");
  const payload = packets[0].payload;
  // Page-scoped, or a trail from a slide nobody is looking at draws on the one
  // they are.
  assert.strictEqual(payload.assetId, "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb");
  assert.strictEqual(payload.pageNumber, 1);
  assert.ok(payload.participantId, "the trail must say whose it is");
  assert.ok(payload.participantName, "the trail must carry a name");
  // It must NOT ride the whiteboard's topic, or a slide trail lands on the board.
  assert.strictEqual(h.gatewayCalls.filter(function (item) {
    return item.topic === "ringq.meet.whiteboard-presence.v1";
  }).length, 0);
});

test("a slide pen is refused what it is not allowed to be", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  const url = "/rooms/" + s.meetingId + "/presentation/annotations/presence";
  const good = {
    gestureId: "40000000-0000-4000-8000-000000000002", phase: "start", tool: "pen",
    point: [0.5, 0.5], pageNumber: 1,
  };
  // Only the two tools the client may broadcast: a select or shape drag has no
  // trail, and the whiteboard's own route enforces the same pair.
  for (const body of [
    Object.assign({}, good, { tool: "select" }),
    Object.assign({}, good, { tool: "eraser" }),
    Object.assign({}, good, { phase: "hover" }),
    Object.assign({}, good, { gestureId: "not-a-uuid" }),
    Object.assign({}, good, { point: [0.5, 1.5] }),
    Object.assign({}, good, { point: [0.5] }),
  ]) {
    const refused = await h.call("POST", url, { headers: s.guestHeaders, body: body });
    assert.strictEqual(refused.status, 400, JSON.stringify(body));
  }
  // A page the deck does not have is out of range, not a silent clamp.
  const offPage = await h.call("POST", url, {
    headers: s.guestHeaders, body: Object.assign({}, good, { pageNumber: 99 }),
  });
  assert.strictEqual(offPage.status, 400);
});

test("a participant who may not draw may not haunt the slide either", async function () {
  const h = deckHarness();
  const s = await seedPresentedDeck(h);
  // A deck starts closed, so somebody who is neither the presenter nor a
  // moderator may not put a mark on it - and must not be able to put a live
  // trail on it either, or the ban is cosmetic.
  const other = await seedExtraParticipant(h, s, "Dana Viewer");
  const refused = await h.call("POST", "/rooms/" + s.meetingId + "/presentation/annotations/presence", {
    headers: other.headers,
    body: {
      gestureId: "40000000-0000-4000-8000-000000000003", phase: "start", tool: "pen",
      point: [0.5, 0.5], pageNumber: 1,
    },
  });
  assert.strictEqual(refused.status, 403);
  assert.strictEqual(refused.body.code, "annotation_forbidden");
});

test("every annotation route is 404 while file presentations are off", async function () {
  const h = harness({ features: { filePresentations: false } });
  const s = await seedInteractionRoom(h);
  const base = "/rooms/" + s.meetingId + "/presentation";
  for (const route of [
    ["GET", base + "/annotations"],
    ["POST", base + "/annotations"],
    ["POST", base + "/annotations/undo"],
    ["POST", base + "/annotations/redo"],
    ["POST", base + "/annotations/clear"],
    ["POST", base + "/annotations/presence"],
    ["POST", base + "/mode"],
  ]) {
    const answer = await h.call(route[0], route[1], {
      headers: s.guestHeaders,
      body: { clientBatchId: "30000000-0000-4000-8000-000000000010", clientActionId: "20000000-0000-4000-8000-000000000010", expectedVersion: 0, pageNumber: 1, annotationMode: "everyone", operations: PEN, gestureId: "40000000-0000-4000-8000-000000000010", phase: "start", tool: "pen", point: [0.5, 0.5] },
    });
    assert.strictEqual(answer.status, 404, route[0] + " " + route[1]);
  }
});


// ── The webinar lobby ──────────────────────────────────────────────────────
//
// A meeting can carry a "lobby presentation": what strangers holding the link
// see before anybody lets them in. Every guard below is about one of three
// things — that it is stored where it survives, that it is never wider than
// the public page it mirrors, and that a meeting without one is untouched.

const PNG_BYTES = Buffer.concat([
  Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  Buffer.alloc(64, 7),
]);
const GIF_BYTES = Buffer.concat([Buffer.from("GIF89a", "ascii"), Buffer.alloc(32, 3)]);

function webinarLobby(extra) {
  return Object.assign(
    {
      kind: "webinar",
      presenterName: "Katie Long",
      startsAt: "2026-09-20T09:00:00+08:00",
      timezone: "Asia/Manila",
      socials: [{ key: "linkedin", label: "LinkedIn", url: "https://linkedin.com/in/katie" }],
    },
    extra || {}
  );
}

async function createWebinar(h, reference, lobby) {
  return createMeeting(h, {
    meetType: "webinar",
    title: "Quarterly product webinar",
    externalReference: reference || "webinar:1",
    lobby: lobby === undefined ? webinarLobby() : lobby,
  });
}

test("a webinar keeps its own meet type on the room", async function () {
  const h = harness();
  const created = await createWebinar(h);
  assert.strictEqual(created.status, 200);
  const store = await tenantStore(h);
  assert.strictEqual((await store.getRoom(created.body.meetingId)).meetType, "webinar");
  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(lookup.body.meetType, "webinar");
});

test("the lobby a host sends is the lobby a stranger reads back", async function () {
  const h = harness();
  const created = await createWebinar(h);
  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(lookup.body.lobby.kind, "webinar");
  assert.strictEqual(lookup.body.lobby.presenterName, "Katie Long");
  // Verbatim, offset included: normalising to Z would leave the client unable
  // to print the organiser's own clock without a timezone database.
  assert.strictEqual(lookup.body.lobby.startsAt, "2026-09-20T09:00:00+08:00");
  assert.strictEqual(lookup.body.lobby.timezone, "Asia/Manila");
  assert.strictEqual(lookup.body.lobby.socials.length, 1);
});

test("a meeting with no lobby answers exactly as it did before one existed", async function () {
  const h = harness();
  const created = await createMeeting(h, { title: "Ordinary" });
  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.ok(!("lobby" in lookup.body), "an ordinary meeting must carry no lobby key at all");
  assert.strictEqual(lookup.body.meetType, "instant");
  assert.strictEqual(lookup.body.exists, true);
});

test("pressing Copy link twice still leaves the webinar with its lobby", async function () {
  // The reuse branch returns early, and it is the common path: Copy link
  // creates the room in advance, so Start meet almost always finds one. A
  // lobby written only on the fresh branch would be there for nobody.
  const h = harness();
  const first = await createWebinar(h, "webinar:reuse");
  // Deliberately a DIFFERENT presenter the second time. Asserting the original
  // value proves only that the first create worked — the reuse branch could
  // write nothing at all and still pass, which is exactly how a host renaming
  // their presenter and pressing Copy link again would find the change
  // silently dropped.
  const second = await createWebinar(h, "webinar:reuse", webinarLobby({ presenterName: "Sam Reyes" }));
  assert.strictEqual(second.body.reused, true);
  assert.strictEqual(second.body.meetingId, first.body.meetingId);
  assert.strictEqual(second.body.lobby.presenterName, "Sam Reyes");
  const lookup = await h.call("GET", "/rooms/" + first.body.meetingId);
  assert.strictEqual(lookup.body.lobby.presenterName, "Sam Reyes");
});

test("a start that carries no lobby keeps the webinar's branding", async function () {
  // The room hash is rebuilt from scratch once Redis has forgotten it. A client
  // that knows only the reference — an older one, or any surface that never
  // held the webinar row — would otherwise recreate the meeting with its
  // branding stripped and no error anywhere.
  const h = harness();
  const created = await createWebinar(h, "webinar:bare");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const store = await tenantStore(h);
  await store.redis.del(store._room(created.body.meetingId));
  await store.redis.del(store._external("webinar:bare"));

  const again = await createMeeting(h, { meetType: "webinar", externalReference: "webinar:bare" });
  assert.strictEqual(again.body.lobby.presenterName, "Katie Long");
  assert.strictEqual(again.body.lobby.banner.sha256, upload.body.banner.sha256);
  const lookup = await h.call("GET", "/rooms/" + again.body.meetingId);
  assert.strictEqual(lookup.body.lobby.presenterName, "Katie Long");
});

test("a social link with a host but the wrong scheme is refused", async function () {
  const h = harness();
  const bad = await createWebinar(h, "webinar:ftp", webinarLobby({
    socials: [{ key: "x", url: "ftp://example.com/feed" }],
  }));
  assert.strictEqual(bad.status, 400);
  assert.ok(/socials\[0\]\.url/.test(bad.body.error), bad.body.error);
});

test("a social link with the right scheme and no host is refused", async function () {
  // Refused by the URL parser rather than by a host check — http and https are
  // WHATWG special schemes and cannot parse without a host — which is why
  // lobbyUrlIsSafe carries no hostname test: one was written, and no fixture
  // could distinguish it from its own absence.
  const h = harness();
  const bad = await createWebinar(h, "webinar:nohost", webinarLobby({
    socials: [{ key: "x", url: "https:" }],
  }));
  assert.strictEqual(bad.status, 400);
});

test("a javascript: social link is refused", async function () {
  const h = harness();
  const bad = await createWebinar(h, "webinar:js", webinarLobby({
    socials: [{ key: "x", url: "javascript:alert(1)" }],
  }));
  assert.strictEqual(bad.status, 400);
});

test("a start time with no offset is refused rather than guessed at", async function () {
  const h = harness();
  const bad = await createWebinar(h, "webinar:naive", webinarLobby({
    startsAt: "2026-09-20T09:00:00",
  }));
  assert.strictEqual(bad.status, 400);
  assert.ok(/offset/i.test(bad.body.error), bad.body.error);
});

test("a timezone that is not a zone name is refused", async function () {
  const h = harness();
  const bad = await createWebinar(h, "webinar:tz", webinarLobby({ timezone: "Eastern Standard Time" }));
  assert.strictEqual(bad.status, 400);
});

test("a client cannot declare a banner it has not uploaded", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:claim", webinarLobby({
    banner: { mime: "image/png", bytes: 999999, sha256: "deadbeef" },
  }));
  assert.strictEqual(created.status, 200);
  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.ok(!lookup.body.lobby.banner, "banner must come from the upload route and nowhere else");
});

test("an unknown lobby kind is refused by name", async function () {
  const h = harness();
  const bad = await createWebinar(h, "webinar:kind", { kind: "seminar" });
  assert.strictEqual(bad.status, 400);
  assert.ok(/lobby\.kind/.test(bad.body.error), bad.body.error);
});

test("the owner can upload a banner and everyone can read it back", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:banner");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  assert.strictEqual(upload.status, 200);
  assert.strictEqual(upload.body.banner.mime, "image/png");
  assert.strictEqual(upload.body.banner.bytes, PNG_BYTES.length);

  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(lookup.body.lobby.banner.sha256, upload.body.banner.sha256);

  const fetched = await h.call(
    "GET",
    "/rooms/" + created.body.meetingId + "/lobby/banner/" + upload.body.banner.sha256
  );
  assert.strictEqual(fetched.status, 200);
  assert.strictEqual(fetched.headers["Content-Type"], "image/png");
  assert.ok(Buffer.isBuffer(fetched.body) && fetched.body.equals(PNG_BYTES), "the bytes must come back unchanged");
  assert.ok(/max-age/.test(fetched.headers["Cache-Control"]), "a banner is cacheable");
});

test("a banner already in the browser is answered 304", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:etag");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const etag = '"' + upload.body.banner.sha256 + '"';
  const again = await h.call(
    "GET",
    "/rooms/" + created.body.meetingId + "/lobby/banner/" + upload.body.banner.sha256,
    { headers: { "if-none-match": etag } }
  );
  assert.strictEqual(again.status, 304);
});

test("the banner type is decided by the bytes, never the header", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:liar");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    // Declares PNG. Is a GIF, which this route does not take.
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: GIF_BYTES,
  });
  assert.strictEqual(upload.status, 400);
  assert.ok(/JPEG, PNG or WebP/.test(upload.body.error), upload.body.error);
});

test("a banner past the limit is refused by this process, not only by nginx", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:big");
  const oversize = [PNG_BYTES, Buffer.alloc(2 * 1024 * 1024 + 1, 1)];
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: oversize,
  });
  assert.strictEqual(upload.status, 413);
});

test("somebody else's host session cannot repaint this lobby", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:owner");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({}, { authorization: "Bearer valid-host-bearer-2" }, { "content-type": "image/png" }),
    raw: PNG_BYTES,
  });
  assert.strictEqual(upload.status, 403);
});

test("a webinar whose room Redis has forgotten is waiting, not missing", async function () {
  // The reported defect: a registrant opens the link they were emailed last
  // week and is told the link is wrong.
  const h = harness();
  const created = await createWebinar(h, "webinar:expired");
  // Every live key shares the room's TTL, so they go together — this is the
  // twelve-hour expiry, not a half of it.
  const store = await tenantStore(h);
  await store.redis.del(store._room(created.body.meetingId));
  await store.redis.del(store._external("webinar:expired"));

  const lookup = await h.call("GET", "/rooms/webinar:expired");
  assert.strictEqual(lookup.body.exists, true);
  assert.strictEqual(lookup.body.status, "not_started");
  assert.strictEqual(lookup.body.lobby.presenterName, "Katie Long");
  assert.strictEqual(lookup.body.title, "Quarterly product webinar");
});

test("an ordinary meeting Redis has forgotten is still simply missing", async function () {
  // The narrowness is the point. Answering for every durable row would change
  // what a stale link means for every meeting ever created.
  const h = harness();
  const created = await createMeeting(h, { meetType: "scheduled", externalReference: "sched:expired" });
  const store = await tenantStore(h);
  await store.redis.del(store._room(created.body.meetingId));
  await store.redis.del(store._external("sched:expired"));
  const lookup = await h.call("GET", "/rooms/sched:expired");
  assert.strictEqual(lookup.body.exists, false);
});

test("the banner still loads for a webinar nobody has started", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:cold");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const store = await tenantStore(h);
  await store.redis.del(store._room(created.body.meetingId));
  await store.redis.del(store._lobbyBanner(created.body.meetingId));
  await store.redis.del(store._external("webinar:cold"));

  const fetched = await h.call(
    "GET",
    "/rooms/webinar:cold/lobby/banner/" + upload.body.banner.sha256
  );
  assert.strictEqual(fetched.status, 200);
  assert.ok(fetched.body.equals(PNG_BYTES));
});

test("a meeting with no banner answers 404 rather than an empty image", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:nobanner");
  const fetched = await h.call("GET", "/rooms/" + created.body.meetingId + "/lobby/banner/none");
  assert.strictEqual(fetched.status, 404);
});

test("re-sending the metadata keeps the banner that was uploaded before it", async function () {
  // `createRoom` rewrites the room's lobby field wholesale, so artwork stored
  // inside it would be erased by the next press of Copy link — silently, and
  // by the very request that re-sent the metadata it belongs to.
  const h = harness();
  const created = await createWebinar(h, "webinar:keep");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const again = await createWebinar(h, "webinar:keep");
  assert.strictEqual(again.body.lobby.banner.sha256, upload.body.banner.sha256);
  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(lookup.body.lobby.banner.sha256, upload.body.banner.sha256);
});

test("the durable lookup is scoped to the tenant, not merely bound to one", async function () {
  // Binding domain_uuid and then matching a code across every tenant is a
  // cross-tenant read wearing a scoped query's clothes.
  const source = require("fs").readFileSync(require("path").join(__dirname, "..", "recorder.js"), "utf8");
  const match = /async findMeetingForLookup[\s\S]*?\[tenant\.domainUuid/.exec(source);
  assert.ok(match, "findMeetingForLookup must exist");
  assert.ok(
    /WHERE\s+domain_uuid\s*=\s*\$1\s+AND\s+\(slug\s*=\s*\$2\s+OR\s+external_reference\s*=\s*\$2\)/.test(match[0]),
    "findMeetingForLookup must FILTER on domain_uuid"
  );
});

test("every policy field survives a patch to a different field", async function () {
  // `setPolicy` writes the whole hash block. A field added to DEFAULT_POLICY but
  // missing from that block is computed, reported as `changed`, and never
  // stored — it survives its own PATCH and is erased by the next unrelated one.
  // This was hit for real by `autoAdmit` while it was being written; a guard
  // that names the fields by hand would only have caught the ones somebody
  // remembered, so it is derived from DEFAULT_POLICY itself.
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  const defaults = roomsMod.DEFAULT_POLICY || {};
  const keys = Object.keys(defaults);
  assert.ok(keys.length >= 8, "expected the policy to have fields to check");

  for (const key of keys) {
    const value = defaults[key];
    // Flip to something that is NOT the default, so a field that is never
    // written cannot pass by matching the default it falls back to.
    const patch = {};
    if (typeof value === "boolean") patch[key] = !value;
    else if (typeof value === "number") patch[key] = value === 0 ? 1 : 0;
    else if (key === "hideParticipantNames") patch[key] = "everyone";
    else continue;

    const room = (await createMeeting(h)).body.meetingId;
    await store.setPolicy(room, patch, { actorId: "host-owner-1" });
    // Now patch a DIFFERENT field. If `key` was never written to the hash, the
    // whole-block write erases it here.
    await store.setPolicy(room, { cohostsMayManageMedia: false }, { actorId: "host-owner-1" });
    const after = await store.getPolicy(room);
    assert.strictEqual(
      after[key], patch[key],
      key + " did not survive an unrelated policy change — is it missing from setPolicy's field block?"
    );
  }
  assert.ok(created.body.meetingId);
});

// ── the presenter photo ──────────────────────────────────────────────────────

test("the owner can upload a presenter photo and everyone can read it back", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:photo");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/photo", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  assert.strictEqual(upload.status, 200);
  // Answered under its own key, never `banner`: a client that reads the wrong
  // one would build a URL for artwork this response is not describing.
  assert.strictEqual(upload.body.photo.mime, "image/png");
  assert.strictEqual(upload.body.banner, undefined);

  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(lookup.body.lobby.photo.sha256, upload.body.photo.sha256);

  const fetched = await h.call(
    "GET",
    "/rooms/" + created.body.meetingId + "/lobby/photo/" + upload.body.photo.sha256
  );
  assert.strictEqual(fetched.status, 200);
  assert.ok(Buffer.isBuffer(fetched.body) && fetched.body.equals(PNG_BYTES), "the bytes must come back unchanged");
  assert.ok(/max-age/.test(fetched.headers["Cache-Control"]), "a photo is cacheable");
});

test("the photo and the banner do not overwrite each other", async function () {
  // They merge into one `lobby` object on the durable row and sit in two
  // separate room-hash fields. Either of those written wholesale would make
  // uploading one erase the other, which nothing about a successful 200 would
  // reveal.
  const h = harness();
  const created = await createWebinar(h, "webinar:both");
  const banner = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const photo = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/photo", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    // Deliberately different bytes: with the same image both digests match and
    // "did one overwrite the other" cannot be answered.
    raw: [PNG_BYTES, Buffer.from("ringq-photo")],
  });
  assert.strictEqual(banner.status, 200);
  assert.strictEqual(photo.status, 200);

  const lookup = await h.call("GET", "/rooms/" + created.body.meetingId);
  assert.strictEqual(lookup.body.lobby.banner.sha256, banner.body.banner.sha256);
  assert.strictEqual(lookup.body.lobby.photo.sha256, photo.body.photo.sha256);
  assert.notStrictEqual(lookup.body.lobby.banner.sha256, lookup.body.lobby.photo.sha256);
});

test("a presenter photo past its own smaller limit is refused", async function () {
  // 512 KiB, not the banner's 2 MiB. Asserted at a size the BANNER would
  // accept, so a photo route wired to the wrong cap fails here rather than
  // passing because both happen to refuse a huge file.
  const h = harness();
  const created = await createWebinar(h, "webinar:photo-cap");
  const oversize = [PNG_BYTES, Buffer.alloc(512 * 1024 + 1, 1)];
  const refused = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/photo", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: oversize,
  });
  assert.strictEqual(refused.status, 413);

  const accepted = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: oversize,
  });
  assert.strictEqual(accepted.status, 200, "the same bytes are fine as a banner");
});

test("re-sending the metadata keeps the presenter photo that was uploaded before it", async function () {
  // `createRoom` rewrites `lobby` wholesale on every Start and every Copy link.
  // The photo's metadata lives in its own room-hash field for exactly that
  // reason, and this is the press that would erase it.
  const h = harness();
  const created = await createWebinar(h, "webinar:photo-reuse");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/photo", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const again = await createWebinar(h, "webinar:photo-reuse");
  assert.strictEqual(again.body.lobby.photo.sha256, upload.body.photo.sha256);
});

test("the presenter photo still loads for a webinar nobody has started", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:photo-durable");
  const upload = await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/photo", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  const store = await tenantStore(h);
  await store.redis.del(store._room(created.body.meetingId));
  await store.redis.del(store._lobbyAsset(created.body.meetingId, "photo"));
  await store.redis.del(store._external("webinar:photo-durable"));

  const fetched = await h.call(
    "GET",
    "/rooms/webinar:photo-durable/lobby/photo/" + upload.body.photo.sha256
  );
  assert.strictEqual(fetched.status, 200);
  assert.ok(fetched.body.equals(PNG_BYTES));
});

test("restoring a lobby never puts image bytes on the room hash", async function () {
  // `readLobby` answers with the assets' BYTES, and `getRoom` is read on every
  // join, every policy read and every lookup — `hGetAll` has no way to ask for
  // less than all of it. A restore that carried them would put a few hundred
  // kilobytes of base64 on all of those.
  const h = harness();
  const created = await createWebinar(h, "webinar:no-bytes");
  await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/banner", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: PNG_BYTES,
  });
  await h.call("POST", "/rooms/" + created.body.meetingId + "/lobby/photo", {
    headers: Object.assign({ "content-type": "image/png" }, HOST_HEADERS),
    raw: [PNG_BYTES, Buffer.from("ringq-photo")],
  });

  // Forget the room but keep the durable row, then start again from a client
  // that sends NO lobby — an older one, or any surface that knows only the
  // reference. That is the path `restoreLobbyToRoom` exists for, and the only
  // one that copies the durable lobby (bytes and all) onto the room hash.
  const store = await tenantStore(h);
  await store.redis.del(store._room(created.body.meetingId));
  await store.redis.del(store._external("webinar:no-bytes"));
  const again = await createWebinar(h, "webinar:no-bytes", null);
  assert.strictEqual(again.status, 200);

  const room = await store.getRoom(again.body.meetingId);
  const lobbyField = String(room.lobby || "");
  assert.ok(lobbyField.indexOf("data") === -1, "the lobby field must carry no bytes: " + lobbyField.slice(0, 120));
  // And the metadata is still reachable, so this is not passing by losing it.
  assert.ok(again.body.lobby.banner.sha256);
  assert.ok(again.body.lobby.photo.sha256);
});

test("a meeting with no presenter photo answers 404 rather than an empty image", async function () {
  const h = harness();
  const created = await createWebinar(h, "webinar:photo-absent");
  const fetched = await h.call("GET", "/rooms/" + created.body.meetingId + "/lobby/photo/deadbeef");
  assert.strictEqual(fetched.status, 404);
});

// ── auto-admit ───────────────────────────────────────────────────────────────

test("auto-admit lets a new join request straight in, and audits that nobody decided", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  await store.setPolicy(created.body.meetingId, { autoAdmit: true }, { actorId: "host-owner-1" });

  const join = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Walk In", email: "walkin@example.com" },
  });
  assert.strictEqual(join.status, 200);
  const session = await store.getGuest(join.body.guestToken);
  assert.strictEqual(session.decision, "admitted");

  const event = h.moderation.filter(function (e) { return e.action === "auto_admit"; })[0];
  assert.ok(event, "an auto-admission must be audited");
  // Its own action and a sentinel actor. `admit` under the owner's key would
  // have the trail claim a person made a decision they were not present for.
  assert.strictEqual(event.targetRef, join.body.participantId);
  assert.strictEqual(event.actorKey, "system:auto-admit");
  assert.notStrictEqual(event.actorKey, "owner-1");
});

test("auto-admit off leaves a join request waiting", async function () {
  const h = harness(PRIVACY);
  const seeded = await seedGuest(h);
  const store = await tenantStore(h);
  const session = await store.getGuest(seeded.join.body.guestToken);
  assert.strictEqual(session.decision, "waiting");
  assert.strictEqual(h.moderation.filter(function (e) { return e.action === "auto_admit"; }).length, 0);
});

test("auto-admit is inert on a tenant that cannot switch it off", async function () {
  // Diverges from chatLocked deliberately, and the direction of the failure is
  // why: a chat lock outliving its control is restrictive and recoverable, an
  // auto-admit outliving its control admits strangers unattended.
  const h = harness({ features: { hostPrivacyControls: false } });
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  await store.setPolicy(created.body.meetingId, { autoAdmit: true }, { actorId: "host-owner-1" });

  const join = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Walk In", email: "walkin@example.com" },
  });
  const session = await store.getGuest(join.body.guestToken);
  assert.strictEqual(session.decision, "waiting");
});

test("an auto-admitted guest is still listed for the moderator who is watching", async function () {
  // The waiting entry is what `guest-token` reconciles against on redemption,
  // and it is how a host sees who arrived. Skipping it would make an
  // auto-admitted guest appear from nowhere.
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  await store.setPolicy(created.body.meetingId, { autoAdmit: true }, { actorId: "host-owner-1" });
  const join = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Walk In", email: "walkin@example.com" },
  });
  const waiting = await store.listWaiting(created.body.meetingId);
  assert.ok(waiting[join.body.participantId], "the arrival must still be visible");
});

test("switching auto-admit on lets the people already queued in", async function () {
  // Turning the switch on changes what happens to the NEXT request and nothing
  // else, so without the drain a host watches a queue go on waiting under a
  // control that says it will not.
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  const first = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Queued One", email: "one@example.com" },
  });
  const second = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Queued Two", email: "two@example.com" },
  });
  assert.strictEqual((await store.getGuest(first.body.guestToken)).decision, "waiting");

  const patched = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { autoAdmit: true } },
  });
  assert.strictEqual(patched.status, 200);
  assert.strictEqual(patched.body.autoAdmitted.admitted, 2);
  assert.strictEqual(patched.body.autoAdmitted.remaining, 0);
  assert.strictEqual((await store.getGuest(first.body.guestToken)).decision, "admitted");
  assert.strictEqual((await store.getGuest(second.body.guestToken)).decision, "admitted");
  assert.strictEqual(h.moderation.filter(function (e) { return e.action === "auto_admit"; }).length, 2);
});

test("a policy change that is not auto-admit drains nobody", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  const guest = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Queued", email: "queued@example.com" },
  });
  const patched = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { cameraLocked: true } },
  });
  assert.strictEqual(patched.status, 200);
  assert.strictEqual(patched.body.autoAdmitted, undefined);
  assert.strictEqual((await store.getGuest(guest.body.guestToken)).decision, "waiting");
});

test("switching auto-admit off admits nobody and un-admits nobody", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  await store.setPolicy(created.body.meetingId, { autoAdmit: true }, { actorId: "host-owner-1" });
  const inside = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Already In", email: "in@example.com" },
  });
  const patched = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { autoAdmit: false } },
  });
  assert.strictEqual(patched.status, 200);
  assert.strictEqual(patched.body.autoAdmitted, undefined);
  // Not the mirror image: somebody already admitted is in the meeting.
  assert.strictEqual((await store.getGuest(inside.body.guestToken)).decision, "admitted");
});

test("the drain counts only the people it actually let in", async function () {
  // An admitted guest stays in the waiting hash until they redeem, so the drain
  // walks over them. Re-deciding them would write a second `auto_admit` audit
  // row for one arrival and report a number larger than the number of people
  // let in — a host reading "2 admitted" when one was already inside.
  //
  // Written this way after the first version passed for the wrong reason: it
  // used a DECLINED guest, and `admit` removes those from the waiting hash, so
  // the drain never reached the check the test existed for.
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  const already = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Already In", email: "in@example.com" },
  });
  const queued = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Still Waiting", email: "wait@example.com" },
  });
  await h.call("POST", "/rooms/" + created.body.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: already.body.participantId, decision: "admitted" },
  });
  const before = h.moderation.filter(function (e) { return e.action === "auto_admit"; }).length;

  const patched = await h.call("PATCH", "/rooms/" + created.body.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { autoAdmit: true } },
  });
  assert.strictEqual(patched.body.autoAdmitted.admitted, 1);
  assert.strictEqual((await store.getGuest(queued.body.guestToken)).decision, "admitted");
  const after = h.moderation.filter(function (e) { return e.action === "auto_admit"; }).length;
  assert.strictEqual(after - before, 1, "one arrival, one audit row");
});

test("a declined guest is not quietly let in by the drain", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h);
  const store = await tenantStore(h);
  const declined = await h.call("POST", "/rooms/" + created.body.meetingId + "/join-request", {
    body: { displayName: "Refused", email: "refused@example.com" },
  });
  await h.call("POST", "/rooms/" + created.body.meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: declined.body.participantId, decision: "declined" },
  });
  await h.call("PATCH", "/rooms/" + created.body.meetingId + "/policy", {
    headers: HOST_HEADERS,
    body: { policy: { autoAdmit: true } },
  });
  assert.strictEqual((await store.getGuest(declined.body.guestToken)).decision, "declined");
});

test("auto_admit is accepted by the moderation audit allow-list and the schema", async function () {
  // Both halves, separately. recorder.js silently returns null for an unknown
  // action and PostgreSQL enforces meet_audit_events_action_check on top — the
  // media_* actions were in one and not the other for a whole release, and
  // every one of those events was swallowed.
  const fs = require("fs");
  const path = require("path");
  const recorderSource = fs.readFileSync(path.join(__dirname, "..", "recorder.js"), "utf8");
  assert.ok(/"auto_admit"/.test(recorderSource), "recorder.js must allow auto_admit");

  const schema = path.join(__dirname, "..", "..", "..", "app", "tasks", "MeetSchemaTask.php");
  if (!fs.existsSync(schema)) {
    console.log("  SKIP MeetSchemaTask is not in this tree (deployed release)");
    return;
  }
  const schemaSource = fs.readFileSync(schema, "utf8");
  const check = /ADD CONSTRAINT meet_audit_events_action_check[\s\S]*?\)\);/.exec(schemaSource);
  assert.ok(check, "the action CHECK must exist");
  assert.ok(/'auto_admit'/.test(check[0]), "the CHECK must allow auto_admit, or every row is rejected");
});

// ── the whiteboard permission as a policy field ──────────────────────────────

test("a whiteboard opened in a meeting whose policy locks it starts locked", async function () {
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { whiteboard: true, hostPrivacyControls: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  const store = await tenantStore(h);
  await store.setPolicy(created.body.meetingId, { whiteboardLocked: true }, { actorId: "host-owner-1" });

  await h.call("GET", "/rooms/" + created.body.meetingId + "/whiteboard", { headers: HOST_HEADERS });
  assert.strictEqual(collaboration.initialLocked, true, "the board must be seeded from the policy");
});

test("the board's own lock outranks the policy once it exists", async function () {
  // The policy chooses what a board STARTS as; the board owns it from that
  // moment. Anything else has a form edited months later silently re-lock a
  // board somebody deliberately opened.
  const collaboration = fakeCollaboration();
  const h = harness({ collaboration: collaboration, features: { whiteboard: true, hostPrivacyControls: true } });
  const created = await createMeeting(h);
  collaboration.meetings[created.body.meetingId] = { owner_key: "owner-1", status: "live" };
  const store = await tenantStore(h);
  await store.setPolicy(created.body.meetingId, { whiteboardLocked: false }, { actorId: "host-owner-1" });
  await h.call("GET", "/rooms/" + created.body.meetingId + "/whiteboard", { headers: HOST_HEADERS });
  assert.strictEqual(collaboration.initialLocked, false);
});

test("every whiteboard path that can create the board seeds it", async function () {
  // A participant who draws before anybody reads would otherwise create the
  // board unlocked and draw on it, which is the lock's whole purpose defeated
  // by ordering.
  const source = require("fs").readFileSync(
    require("path").join(__dirname, "..", "collaboration.js"), "utf8"
  );
  // Statement AND argument. Reading only the SQL passes a mutation that keeps
  // the column and binds a literal, which is exactly how the lock would stop
  // being seeded while the guard stayed green.
  const inserts =
    source.match(/INSERT INTO public\.meet_whiteboard_boards[\s\S]*?\]\s*\)/g) || [];
  assert.ok(inserts.length >= 2, "expected every board-creating insert to be found");
  inserts.forEach(function (statement) {
    assert.ok(/locked/.test(statement), "a board-creating insert must seed `locked`");
    assert.ok(
      /initialLocked/.test(statement),
      "a board-creating insert must bind the policy's value, not a literal"
    );
  });
});

test("the raw upload route is on the streamed allow-list, not the JSON path", async function () {
  // Without this it meets server.js's 64 KiB JSON reader and every upload is a
  // 413 that reads like a broken route. Both assets, by name: the photo's cap
  // is smaller but it is still far past 64 KiB.
  const source = require("fs").readFileSync(require("path").join(__dirname, "..", "server.js"), "utf8");
  const stripped = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/(^|[^:])\/\/.*$/gm, "$1");
  const allow = /lobby\\\/\(([a-z|]+)\)\$\//.exec(stripped);
  assert.ok(allow, "server.js must stream the lobby asset routes");
  const streamed = allow[1].split("|");
  assert.ok(streamed.indexOf("banner") !== -1, "server.js must stream the lobby banner route");
  assert.ok(streamed.indexOf("photo") !== -1, "server.js must stream the lobby photo route");
  assert.ok(/multipartUpload \|\| rawUpload/.test(stripped), "the raw upload must bypass the JSON reader");
});

test("nginx lets the banner route carry more than 64k", async function () {
  // Read from the repository, which a deployed release is not: `releases/x/`
  // holds the control plane alone, and this file lives beside the installer.
  // Absent means "running in place on a host", where the retrofit has already
  // been applied and is not this suite's to check — loud about it rather than
  // silently passing, because a guard that cannot find its subject and says
  // nothing is a guard that has stopped guarding.
  const fs = require("fs");
  const target = require("path").join(
    __dirname, "..", "..", "..", "app", "scripts", "ringq-meet-nginx-retrofit.py"
  );
  if (!fs.existsSync(target)) {
    console.log("  SKIP nginx retrofit is not in this tree (deployed release)");
    return;
  }
  const source = fs.readFileSync(target, "utf8");
  assert.ok(
    /chat\/attachments\|presentation\/assets\|lobby\/banner\|lobby\/photo/.test(source),
    "the upload location must include both lobby assets"
  );
  // And the artwork GET needs its own block, or the API location hides the
  // control plane's Cache-Control and replaces it with no-store — measured on
  // a real host, where the picture was re-fetched on every read.
  const artworkBlock = /location ~ \^\/meet\/api\/v1\/rooms\/\[\^\/\]\+\/lobby\/\(banner\|photo\)\/\[\^\/\]\+\$ \{[\s\S]*?\n    \}/.exec(source);
  assert.ok(artworkBlock, "the lobby artwork GET must have its own nginx location");
  assert.ok(
    !/Cache-Control/.test(artworkBlock[0]),
    "the artwork location must not override the control plane's Cache-Control"
  );
});


const INVITE_TOKEN = "cohost-invite-token-0123456789abcdefghijklmnop";

async function seedInvite(h, overrides) {
  const record = Object.assign({
    domainUuid: DOMAIN_A,
    externalReference: "webinar-ref-1",
    webinarId: "w1",
    participantId: "p1",
    email: "cohost@example.com",
    displayName: "Co Host",
    role: "cohost",
  }, overrides || {});
  await h.redis.set("meet:invite:" + authMod.opaqueTokenHash(INVITE_TOKEN), JSON.stringify(record));
  return record;
}

async function joinWithInvite(h, meetingId, token) {
  const body = { displayName: "Co Host", email: "cohost@example.com" };
  if (token !== undefined) body.inviteToken = token;
  return h.call("POST", "/rooms/" + meetingId + "/join-request", { body: body });
}

test("capabilities advertise co-host invites only when the store is wired", async function () {
  assert.strictEqual((await harness().call("GET", "/capabilities")).body.features.cohostInvites, true);
  assert.strictEqual((await harness({ noInvites: true }).call("GET", "/capabilities")).body.features.cohostInvites, false);
});

test("a valid co-host invite admits straight in and mints a co-host token", async function () {
  const h = harness();
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h);
  const join = await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN);
  assert.strictEqual(join.status, 200);
  assert.deepStrictEqual(Object.keys(join.body).sort(), ["guestToken", "participantId"]);
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + join.body.guestToken },
  });
  assert.strictEqual(token.status, 200);
  const mint = h.gatewayCalls.filter(function (c) { return c.operation === "token"; }).pop();
  assert.strictEqual(mint.request.role, "cohost");
  const store = await tenantStore(h);
  const intent = await store.getParticipantIntent(created.body.meetingId, join.body.participantId);
  assert.strictEqual(intent.role, "cohost");
  const audit = h.moderation.filter(function (e) { return e.action === "auto_admit"; });
  assert.strictEqual(audit.length, 1);
  assert.strictEqual(audit[0].details.via, "cohost_invite");
});

test("a co-host invite is honoured even when the meeting is not auto-admitting", async function () {
  const h = harness(PRIVACY);
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h);
  const join = await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN);
  const token = await h.call("POST", "/rooms/" + created.body.meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + join.body.guestToken },
  });
  assert.strictEqual(token.status, 200);
});

async function assertPlainGuest(h, meetingId, join) {
  assert.strictEqual(join.status, 200);
  assert.deepStrictEqual(Object.keys(join.body).sort(), ["guestToken", "participantId"]);
  const pending = await h.call("POST", "/rooms/" + meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + join.body.guestToken },
  });
  assert.strictEqual(pending.status, 403);
  assert.strictEqual(pending.body.code, "not_admitted");
  await h.call("POST", "/rooms/" + meetingId + "/admit", {
    headers: HOST_HEADERS,
    body: { participantId: join.body.participantId, decision: "admitted" },
  });
  const admitted = await h.call("POST", "/rooms/" + meetingId + "/guest-token", {
    headers: { authorization: "Bearer " + join.body.guestToken },
  });
  assert.strictEqual(admitted.status, 200);
  const mint = h.gatewayCalls.filter(function (c) { return c.operation === "token"; }).pop();
  assert.strictEqual(mint.request.role, "participant");
}

test("an unknown invite token is an ordinary waiting guest", async function () {
  const h = harness();
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h);
  await assertPlainGuest(h, created.body.meetingId,
    await joinWithInvite(h, created.body.meetingId, "not-the-invite-token-0123456789abcdefgh"));
});

test("an invite for another meeting does not carry over", async function () {
  const h = harness();
  const created = await createMeeting(h, { externalReference: "webinar-ref-2" });
  await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h);
  await assertPlainGuest(h, created.body.meetingId, await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN));
});

test("an invite issued for another tenant does not carry over", async function () {
  const h = harness();
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h, { domainUuid: DOMAIN_B });
  await assertPlainGuest(h, created.body.meetingId, await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN));
});

test("a revoked invite is an ordinary waiting guest", async function () {
  const h = harness();
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h);
  await h.redis.del("meet:invite:" + authMod.opaqueTokenHash(INVITE_TOKEN));
  await assertPlainGuest(h, created.body.meetingId, await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN));
});

test("an invite that does not grant co-host grants nothing", async function () {
  const h = harness();
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h, { role: "host" });
  await assertPlainGuest(h, created.body.meetingId, await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN));
});

test("with no invite store, an invite token changes nothing", async function () {
  const h = harness({ noInvites: true });
  const created = await createMeeting(h, { externalReference: "webinar-ref-1" });
  await seedInvite(h);
  await assertPlainGuest(h, created.body.meetingId, await joinWithInvite(h, created.body.meetingId, INVITE_TOKEN));
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
