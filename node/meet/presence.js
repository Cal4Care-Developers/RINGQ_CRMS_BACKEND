const crypto = require("crypto");

/**
 * Authoritative participant presence from gateway callbacks plus reconciliation.
 *
 * Token issuance records only an intent. Attendance is opened after the gateway
 * reports the participant, and closed after a leave callback or a reconciliation
 * diff. Callback ids are de-duplicated because delivery is at-least-once.
 */

function participantMetadata(participant) {
  const raw = participant && participant.metadata;
  if (!raw) return {};
  if (typeof raw === "object") return raw;
  try { return JSON.parse(raw); } catch (err) { return {}; }
}

function participantIdentity(participant) {
  return String((participant && (participant.identity || participant.participantId)) || "").trim();
}

function invalidGatewayEvent(message) {
  const err = new Error(message);
  err.code = "CALLBACK_INVALID";
  err.statusCode = 400;
  return err;
}

class PresenceCoordinator {
  constructor(opts) {
    const o = opts || {};
    if (!o.redis || !o.storeFor || !o.gateway) {
      throw new TypeError("PresenceCoordinator requires redis, storeFor and gateway");
    }
    this.redis = o.redis;
    this.storeFor = o.storeFor;
    this.gateway = o.gateway;
    this.recorder = o.recorder || null;
    this.collaboration = o.collaboration || null;
    this.capture = o.capture || null;
    this.filePresentationsEnabled = !!(o.features && o.features.filePresentations);
    this.presentationGraceMs = Number(o.presentationGraceMs || 30000);
    this.log = o.logger || { info: function () {}, warn: function () {}, error: function () {} };
    this.intervalMs = Number(o.intervalMs || 10000);
    this._tenants = new Map();
    this._timer = null;
    this._running = false;
    this._presentationTimers = new Map();
  }

  async registerTenant(tenant) {
    this._tenants.set(tenant.tenantKey, tenant);
    // markActive writes the persistent tenant registry. A no-room registration
    // should not create an active room, so persist the registry directly here.
    await this.redis.hSet("meet:active-tenants", tenant.tenantKey, JSON.stringify({
      subdomain: tenant.subdomain,
      domainUuid: tenant.domainUuid,
      tenantKey: tenant.tenantKey,
      keyPrefix: tenant.keyPrefix,
    }));
    await this.redis.expire("meet:active-tenants", 12 * 60 * 60);
  }

  async start() {
    if (this._timer) return;
    const RoomStore = require("./rooms.js").RoomStore;
    const remembered = await RoomStore.registeredTenants(this.redis);
    remembered.forEach((tenant) => this._tenants.set(tenant.tenantKey, tenant));
    await this.reconcileAll();
    this._timer = setInterval(() => {
      this.reconcileAll().catch((err) => this.log.warn("meet: reconciliation failed: " + err.message));
    }, this.intervalMs);
    if (this._timer.unref) this._timer.unref();
  }

  stop() {
    if (this._timer) clearInterval(this._timer);
    this._timer = null;
    for (const timer of this._presentationTimers.values()) clearTimeout(timer);
    this._presentationTimers.clear();
  }

  async applyEvent(tenant, event, eventId) {
    if (!event || typeof event !== "object") throw invalidGatewayEvent("gateway event body is required");
    const id = String(eventId || event.id || "").trim();
    if (!id || id.length > 200) throw invalidGatewayEvent("gateway event id is required");
    if (String(event.id || id) !== id) throw invalidGatewayEvent("gateway event id header does not match body");
    if (event.tenantKey !== tenant.tenantKey) throw invalidGatewayEvent("gateway event belongs to another tenant");
    const meetingId = String(event.meetingId || "").trim();
    if (!meetingId || meetingId.length > 160) throw invalidGatewayEvent("gateway event meetingId is invalid");

    const type = String(event.type || "").toLowerCase();
    const supportedTypes = [
      "participant_joined",
      "participant_connected",
      "participant_left",
      "participant_disconnected",
      "room_started",
      "room_finished",
      "room_deleted",
      "meeting_ended",
    ];
    if (supportedTypes.indexOf(type) === -1) {
      throw invalidGatewayEvent("gateway event type is unsupported");
    }

    const claimed = await this.redis.set("meet:gateway-event:" + id, "1", { NX: true, EX: 7 * 24 * 60 * 60 });
    if (!claimed) return { duplicate: true };

    await this.registerTenant(tenant);
    const store = this.storeFor(tenant);
    if (type === "participant_joined" || type === "participant_connected") {
      await this._joined(tenant, store, meetingId, event.participant || {});
    } else if (type === "participant_left" || type === "participant_disconnected") {
      await this._left(tenant, store, meetingId, participantIdentity(event.participant || {}));
    } else if (type === "room_finished" || type === "room_deleted" || type === "meeting_ended") {
      await this._ended(tenant, store, meetingId);
    }
    // room_started is deliberately an acknowledged no-op. The immutable Meet
    // timer anchor starts on the first confirmed participant_joined callback,
    // not merely when LiveKit allocates the room.
    return { duplicate: false };
  }

  async _joined(tenant, store, meetingId, participant) {
    const identity = participantIdentity(participant);
    if (!identity) return;
    this._cancelPresentationStop(tenant.tenantKey, meetingId, identity);
    const room = await store.getRoom(meetingId);
    if (!room || room.status === "ended") return;
    const intent = await store.getParticipantIntent(meetingId, identity);
    const metadata = participantMetadata(participant);
    const role = ["host", "cohost", "participant"].indexOf(metadata.role) !== -1
      ? metadata.role
      : ((intent && intent.role) || "participant");
    const current = await store.listParticipants(meetingId);
    const existing = current.find(function (p) { return p.participantId === identity; });
    if (existing) {
      // Role changes are pushed to LiveKit metadata. Reconciliation uses that
      // signed server state to repair a rare control-plane write failure, while
      // the immutable room owner can never be demoted by metadata.
      const ownerParticipantId = "host-" + room.ownerKey;
      if (
        identity !== ownerParticipantId &&
        existing.role !== "host" &&
        existing.role !== role &&
        (role === "participant" || role === "cohost")
      ) {
        await store.setParticipantRole(meetingId, identity, role);
        await store.setGuestRoleByParticipant(meetingId, identity, role);
      }
      return;
    }
    const record = await store.addParticipant(meetingId, identity, {
      // The intent is preferred over the SFU's name deliberately. Under a name
      // privacy policy the token is minted with an alias, so `participant.name`
      // is "Participant 3" — writing that here would replace the real identity in
      // the authoritative roster, in `meet_participants`, in every audit row and
      // in transcript attribution, and no moderator could ever recover it.
      displayName: (intent && intent.displayName) || metadata.displayName || participant.name || "Guest",
      email: (intent && intent.email) || metadata.email || "",
      // Intent first for the same reason as the name directly above: under a
      // name-privacy policy the SFU metadata carries no photo at all, and
      // persisting that absence would lose the real one permanently.
      avatarUrl: (intent && intent.avatarUrl) || metadata.avatarUrl || "",
      role: role,
      joinedAt: participant.joinedAt || Date.now(),
    });
    await store.removeParticipantIntent(meetingId, identity);
    await store.markActive(meetingId);
    await store.markLive(meetingId, Date.now());
    if (this.recorder) {
      await this.recorder.meetingStarted(tenant, { meetingId: meetingId });
      await this.recorder.participantJoined(tenant, Object.assign({ meetingId: meetingId }, record));
    }
  }

  async _left(tenant, store, meetingId, identity) {
    if (!identity) return;
    const removed = await store.removeParticipant(meetingId, identity);
    await store.removeParticipantIntent(meetingId, identity);
    // A hand belongs to someone in the room. Leaving it up would keep a departed
    // participant at the head of the queue, which no moderator can clear because
    // there is no longer a participant to act on.
    if (store.lowerHand) await store.lowerHand(meetingId, identity);
    if (removed && this.recorder) {
      await this.recorder.participantLeft(tenant, { meetingId: meetingId, participantId: identity });
    }
    if (removed) this._schedulePresentationStop(tenant, store, meetingId, identity);
  }

  async _ended(tenant, store, meetingId) {
    const room = await store.getRoom(meetingId);
    if (!room) return;
    await store.updateRoom(meetingId, { status: "ended", endedAt: Date.now() });
    await store.endRoom(meetingId);
    this._cancelMeetingPresentationStops(tenant.tenantKey, meetingId);
    if (this.recorder) await this.recorder.meetingEnded(tenant, { meetingId: meetingId });
    // Release the capture slot last, and never let it fail the room teardown.
    // The slot is server-wide: a meeting that ends mid-recording used to keep
    // its row non-terminal, and that one row then refused every recording on
    // every other meeting until the control plane restarted.
    if (this.capture && this.capture.releaseForEndedMeeting) {
      try {
        await this.capture.releaseForEndedMeeting(tenant, meetingId);
      } catch (error) {
        this.log.warn("meet: capture release on meeting end failed: " + error.message);
      }
    }
  }

  _presentationTimerKey(tenantKey, meetingId, identity) {
    return tenantKey + ":" + meetingId + ":" + identity;
  }

  _cancelPresentationStop(tenantKey, meetingId, identity) {
    const key = this._presentationTimerKey(tenantKey, meetingId, identity);
    const timer = this._presentationTimers.get(key);
    if (timer) clearTimeout(timer);
    this._presentationTimers.delete(key);
  }

  _cancelMeetingPresentationStops(tenantKey, meetingId) {
    const prefix = tenantKey + ":" + meetingId + ":";
    for (const [key, timer] of this._presentationTimers.entries()) {
      if (key.indexOf(prefix) !== 0) continue;
      clearTimeout(timer);
      this._presentationTimers.delete(key);
    }
  }

  _schedulePresentationStop(tenant, store, meetingId, identity) {
    if (!this.filePresentationsEnabled || !this.collaboration) return;
    const key = this._presentationTimerKey(tenant.tenantKey, meetingId, identity);
    this._cancelPresentationStop(tenant.tenantKey, meetingId, identity);
    const timer = setTimeout(async () => {
      this._presentationTimers.delete(key);
      try {
        if (await store.getParticipant(meetingId, identity)) return;
        const current = await this.collaboration.presentationState(tenant, meetingId);
        if (!current || current.status !== "active" || current.presenterId !== identity) return;
        await this.collaboration.applyPresentationAction(tenant, {
          meetingId: meetingId,
          kind: "stop",
          actorId: "system-presence",
          actorName: "System",
          isModerator: true,
          clientActionId: crypto.randomUUID(),
          expectedVersion: current.version,
          assetId: null,
          pageNumber: null,
        });
        const state = await this.collaboration.presentationState(tenant, meetingId);
        if (this.gateway.broadcastRoomData) {
          await this.gateway.broadcastRoomData(tenant, meetingId, "ringq.meet.presentation.v1", {
            kind: "state",
            presentation: state,
          });
        }
      } catch (err) {
        this.log.warn("meet: presenter absence stop failed: " + err.message);
      }
    }, this.presentationGraceMs);
    if (timer.unref) timer.unref();
    this._presentationTimers.set(key, timer);
  }

  async reconcileAll() {
    if (this._running) return;
    this._running = true;
    try {
      for (const tenant of this._tenants.values()) await this.reconcileTenant(tenant);
    } finally {
      this._running = false;
    }
  }

  async reconcileTenant(tenant) {
    const store = this.storeFor(tenant);
    const rooms = await store.listActiveRooms();
    for (let i = 0; i < rooms.length; i++) {
      const meetingId = rooms[i];
      try {
        const data = await this.gateway.listParticipants(tenant, meetingId);
        const actual = Array.isArray(data.participants) ? data.participants : [];
        const local = await store.listParticipants(meetingId);
        const actualIds = new Set(actual.map(participantIdentity).filter(Boolean));
        for (let j = 0; j < actual.length; j++) await this._joined(tenant, store, meetingId, actual[j]);
        for (let j = 0; j < local.length; j++) {
          if (!actualIds.has(local[j].participantId)) await this._left(tenant, store, meetingId, local[j].participantId);
        }
      } catch (err) {
        const code = String((err && err.code) || "").toUpperCase();
        if ((err && err.statusCode === 404) || code === "ROOM_NOT_FOUND" || code === "NOT_FOUND") {
          await this._ended(tenant, store, meetingId);
        }
        else this.log.warn("meet: reconcile " + tenant.tenantKey + "/" + meetingId + " failed: " + err.message);
      }
    }
  }
}

module.exports = { PresenceCoordinator: PresenceCoordinator };
