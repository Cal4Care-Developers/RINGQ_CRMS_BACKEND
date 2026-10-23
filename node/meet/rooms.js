/**
 * Live meet state, keyed for O(1) access.
 *
 * Replaces the legacy model in MeetController.php, which kept every room and
 * every participant in two global Redis LISTS and scanned them in full on every
 * operation: 29 x lrange(0,-1) and 17 x O(n) lrem. Room lookup, join, leave,
 * disconnect, history and even "does this room exist" were all O(total rooms).
 *
 * Key schema (see docs/MEET-DATA-MODEL.md). Every key is tenant-prefixed, so a
 * forgotten prefix is a missing-key error rather than a silent cross-tenant read:
 *
 *   meet:{tenantKey}:room:{meetingId}                HASH   room facts
 *   meet:{tenantKey}:room:{meetingId}:participants   HASH   participantId -> json
 *   meet:{tenantKey}:room:{meetingId}:waiting        HASH   participantId -> json
 *   meet:{tenantKey}:slug:{slug}                     STRING -> meetingId
 *   meet:{tenantKey}:owner:{ownerKey}:active         STRING -> meetingId
 *   meet:{tenantKey}:external:{externalReference}    STRING -> meetingId
 *   meet:{tenantKey}:guest:{tokenHash}               STRING -> json
 *   meet:{tenantKey}:room:{meetingId}:intents        HASH   participantId -> json
 *   meet:{tenantKey}:active                          HASH   meetingId -> last activity
 *
 * Durable rows live in Postgres (meet_* tables); this layer is deliberately
 * disposable — losing it ends in-flight meetings and nothing more.
 *
 * Runtime: CommonJS on Node.js 24 LTS. Expects the pinned node-redis client.
 */

const crypto = require("crypto");

/** Live keys expire so ghosts die on their own — no sweep loop. */
const DEFAULT_TTL = {
  room: 12 * 60 * 60, // 12h; refreshed on every touch
  waiting: 60 * 60, // 1h
  guest: 2 * 60 * 60, // 2h
};

const ROOM_STATUS = ["created", "live", "ended", "missed"];

/** Sources a host policy can lock. Screen share is deliberately not one. */
const POLICY_SOURCES = Object.freeze(["microphone", "camera"]);

/** Who a hidden name is hidden from. */
const NAME_VISIBILITY = Object.freeze(["off", "participants", "everyone"]);

/**
 * The room media policy as it exists before a host says anything.
 *
 * Every field is permissive: an unconfigured meeting must behave exactly as it
 * did before this feature existed, so an operator who never sets a policy — or a
 * tenant with the feature flag off — sees no behaviour change at all.
 */
const DEFAULT_POLICY = Object.freeze({
  cameraLocked: false,
  microphoneLocked: false,
  cameraRequestsAllowed: true,
  microphoneRequestsAllowed: true,
  hideParticipantNames: "off",
  cohostsMayManageMedia: true,
  chatLocked: false,
  // Off, and deliberately off for a webinar too. A webinar already defaults
  // both device locks on, so an unattended admission puts a muted, camera-off
  // registrant in the room -- but it is still an admission nobody approved,
  // and the setting exists to be chosen rather than inherited.
  autoAdmit: false,
  // The *initial* value of a whiteboard's lock, not a live mirror of it. The
  // board row is created on first touch and owns the flag from that moment;
  // see `collaboration.js`. A form changing this after a meeting has run does
  // not reach that meeting's board, which is why this is named as a policy
  // default rather than as the board's own state.
  whiteboardLocked: false,
  joinRequestSoundRepeats: 1,
});

/**
 * How many times the waiting-room knock may sound for one unanswered request.
 *
 * The default is 1 because that is exactly what Meet did before the setting
 * existed: one cue per new arrival and nothing after it. 0 silences the cue;
 * the ceiling is 3 because a fourth play of something nobody has answered is
 * nagging rather than information.
 */
const JOIN_REQUEST_SOUND_REPEATS = Object.freeze({ min: 0, max: 3 });

/**
 * Coerce whatever is in Redis (all strings) or arrived over HTTP into a policy.
 *
 * Deliberately total: an unreadable field falls back to its permissive default
 * rather than throwing, because a policy that cannot be parsed must not be able
 * to lock a room out of its own devices. Rejecting bad *input* is the router's
 * job and happens before this is ever called with untrusted values.
 */
function normalisePolicy(raw, version) {
  const source = raw && typeof raw === "object" ? raw : {};
  const bool = function (key) {
    const value = source[key];
    if (value === true || value === "true") return true;
    if (value === false || value === "false") return false;
    return DEFAULT_POLICY[key];
  };
  const visibility = String(source.hideParticipantNames || "");
  // Total by contract: an unreadable value falls back to the default rather
  // than throwing, and an out-of-range one is clamped. Refusing bad *input* is
  // the router's job (`parsePolicyPatch`) and happens before this is reached
  // with anything untrusted -- a policy that cannot be parsed must never be
  // able to change how a room behaves.
  const repeats = Number(source.joinRequestSoundRepeats);
  const knocks = Number.isFinite(repeats)
    ? Math.min(JOIN_REQUEST_SOUND_REPEATS.max, Math.max(JOIN_REQUEST_SOUND_REPEATS.min, Math.round(repeats)))
    : DEFAULT_POLICY.joinRequestSoundRepeats;
  return {
    version: Math.max(1, Number(version === undefined ? source.version : version) || 1),
    cameraLocked: bool("cameraLocked"),
    microphoneLocked: bool("microphoneLocked"),
    cameraRequestsAllowed: bool("cameraRequestsAllowed"),
    microphoneRequestsAllowed: bool("microphoneRequestsAllowed"),
    hideParticipantNames: NAME_VISIBILITY.indexOf(visibility) !== -1 ? visibility : DEFAULT_POLICY.hideParticipantNames,
    cohostsMayManageMedia: bool("cohostsMayManageMedia"),
    chatLocked: bool("chatLocked"),
    autoAdmit: bool("autoAdmit"),
    whiteboardLocked: bool("whiteboardLocked"),
    joinRequestSoundRepeats: knocks,
  };
}

/** Whether a locked participant may even ask about a source. */
function policyAllowsRequest(policy, source) {
  return source === "camera" ? policy.cameraRequestsAllowed === true : policy.microphoneRequestsAllowed === true;
}

/** Whether a policy locks this source at all. */
function policyLocks(policy, source) {
  return source === "camera" ? policy.cameraLocked === true : policy.microphoneLocked === true;
}

/**
 * The publish allow-list for one participant, as the SFU needs to hear it.
 *
 * An allow-list, never a deny-list: LiveKit reads an empty `canPublishSources`
 * as "unrestricted", so anything subtractive inverts itself at zero entries.
 * Screen share is always present — locking cameras must not stop someone
 * sharing a document — and moderators are never restricted, which is what keeps
 * a host in control of their own devices.
 */
function publishSourcesFor(policy, grant, role) {
  if (role === "host" || role === "cohost") return ["microphone", "camera", "screenShare"];
  const allowed = ["screenShare"];
  const granted = grant || {};
  if (!policyLocks(policy, "microphone") || granted.microphone === true) allowed.unshift("microphone");
  if (!policyLocks(policy, "camera") || granted.camera === true) allowed.push("camera");
  return allowed;
}

/** True when this participant is currently prevented from publishing a source. */
function sourceLockedFor(policy, grant, role, source) {
  return publishSourcesFor(policy, grant, role).indexOf(source) === -1;
}

class MeetStateError extends Error {
  constructor(message, code) {
    super(message);
    this.name = "MeetStateError";
    this.code = code || "MEET_STATE_ERROR";
  }
}

/** Guest bearer tokens are stored hashed so a Redis dump yields no live tokens. */
function hashToken(token) {
  return crypto.createHash("sha256").update(String(token), "utf8").digest("hex");
}

/**
 * @param {object} opts
 * @param {object} opts.redis        node-redis v4 client (already connected)
 * @param {object} opts.tenant       frozen record from TenantResolver.resolve()
 * @param {object} [opts.ttl]        override DEFAULT_TTL
 * @param {() => number} [opts.now]  injectable clock for tests
 */
class RoomStore {
  constructor(opts) {
    const o = opts || {};
    if (!o.redis) throw new TypeError("RoomStore requires a redis client");
    if (!o.tenant || !o.tenant.keyPrefix) {
      throw new TypeError("RoomStore requires a resolved tenant (with keyPrefix)");
    }
    this.redis = o.redis;
    this.tenant = o.tenant;
    this.ttl = Object.assign({}, DEFAULT_TTL, o.ttl || {});
    this._now = o.now || Date.now;
  }

  // ── key builders — the only place keys are constructed ──

  _room(id) {
    return this.tenant.keyPrefix + "room:" + id;
  }
  _participants(id) {
    return this._room(id) + ":participants";
  }
  _waiting(id) {
    return this._room(id) + ":waiting";
  }
  _slug(slug) {
    return this.tenant.keyPrefix + "slug:" + String(slug).toLowerCase();
  }
  _ownerActive(ownerKey) {
    return this.tenant.keyPrefix + "owner:" + String(ownerKey).toLowerCase() + ":active";
  }
  _external(externalReference) {
    const digest = crypto.createHash("sha256").update(String(externalReference), "utf8").digest("hex");
    return this.tenant.keyPrefix + "external:" + digest;
  }
  _intents(id) {
    return this._room(id) + ":intents";
  }
  _activeRooms() {
    return this.tenant.keyPrefix + "active";
  }
  _guest(token) {
    return this._guestByHash(hashToken(token));
  }
  _guestByHash(hash) {
    return this.tenant.keyPrefix + "guest:" + hash;
  }
  /**
   * Reverse index: (meetingId, participantId) -> hashed guest key.
   *
   * The host admits by participantId — it never sees the guest's bearer token —
   * so admit/decline needs this pointer to find the session to update.
   */
  _guestPointer(meetingId, participantId) {
    return this.tenant.keyPrefix + "guestref:" + meetingId + ":" + participantId;
  }
  _hands(id) {
    return this._room(id) + ":hands";
  }
  _mediaRequests(id) {
    return this._room(id) + ":media-requests";
  }
  _mediaRequestCooldowns(id) {
    return this._room(id) + ":media-request-cooldowns";
  }
  _policy(id) {
    return this._room(id) + ":policy";
  }
  /** `{participantId}:{source}` -> "1" for a moderator's per-person exemption. */
  _mediaGrants(id) {
    return this._room(id) + ":media-grants";
  }
  /** participantId -> alias, plus a `counter` field, so numbering is stable. */
  _aliases(id) {
    return this._room(id) + ":aliases";
  }
  _accessRequests(id) {
    return this._room(id) + ":media-access-requests";
  }
  _accessCooldowns(id) {
    return this._room(id) + ":media-access-cooldowns";
  }
  /**
   * The lobby banner's bytes, cached beside the room.
   *
   * Its own key and never a field on the room hash: `getRoom` is read on every
   * join, every policy read and every lookup, and `hGetAll` has no way to ask
   * for less than all of it — a few hundred kilobytes of image there would be
   * carried by all of them.
   */
  _lobbyAsset(id, kind) {
    return this._room(id) + ":lobby-" + kind;
  }
  _lobbyBanner(id) {
    return this._lobbyAsset(id, "banner");
  }

  // ── rooms ──

  /**
   * Create a room and its lookup indexes in one round trip.
   *
   * The slug and owner indexes are what let `resolveMeetingId` stay O(1) — they
   * replace the legacy `room_name === X || lower(owner) === X` scan.
   */
  async createRoom(room) {
    if (!room || !room.meetingId) {
      throw new MeetStateError("createRoom requires meetingId", "MEET_NO_MEETING_ID");
    }
    if (room.status && ROOM_STATUS.indexOf(room.status) === -1) {
      throw new MeetStateError("invalid room status: " + room.status, "MEET_BAD_STATUS");
    }
    const id = room.meetingId;
    const fields = {
      meetingId: id,
      status: room.status || "created",
      meetType: room.meetType || "instant",
      slug: room.slug || "",
      ownerKey: room.ownerKey || "",
      title: room.title || "",
      externalReference: room.externalReference || "",
      createdAt: String(this._now()),
    };

    const multi = this.redis.multi();
    multi.hSet(this._room(id), fields);
    multi.expire(this._room(id), this.ttl.room);
    if (room.slug) {
      multi.set(this._slug(room.slug), id, { EX: this.ttl.room });
    }
    if (room.ownerKey) {
      multi.set(this._ownerActive(room.ownerKey), id, { EX: this.ttl.room });
    }
    if (room.externalReference) {
      multi.set(this._external(room.externalReference), id, { EX: this.ttl.room });
    }
    await multi.exec();
    return fields;
  }

  /** O(1). Returns null when absent — callers must not treat that as "ended". */
  async getRoom(meetingId) {
    if (!meetingId) return null;
    const hash = await this.redis.hGetAll(this._room(meetingId));
    if (!hash || Object.keys(hash).length === 0) return null;
    return hash;
  }

  /**
   * Cache a lobby banner's bytes for the life of the room.
   *
   * The durable row in Postgres is the copy that matters — a webinar link is
   * handed out weeks before this twelve-hour key means anything — so a miss
   * here is ordinary and must cost a read, never an error.
   */
  async setLobbyAsset(meetingId, kind, asset) {
    if (!meetingId || !kind || !asset || !asset.data) return;
    await this.redis.set(
      this._lobbyAsset(meetingId, kind),
      JSON.stringify({ mime: asset.mime, sha256: asset.sha256, data: asset.data }),
      { EX: this.ttl.room }
    );
  }

  /** Null on a miss and on anything unparseable: both mean "ask Postgres". */
  async getLobbyAsset(meetingId, kind) {
    if (!meetingId || !kind) return null;
    const raw = await this.redis.get(this._lobbyAsset(meetingId, kind));
    if (!raw) return null;
    try {
      const parsed = JSON.parse(raw);
      return parsed && parsed.data ? parsed : null;
    } catch (err) {
      return null;
    }
  }

  async setLobbyBanner(meetingId, banner) {
    return this.setLobbyAsset(meetingId, "banner", banner);
  }

  async getLobbyBanner(meetingId) {
    return this.getLobbyAsset(meetingId, "banner");
  }

  /**
   * Resolve caller input to a meetingId.
   *
   * GET /rooms/:slug accepts a meeting id, shareable slug, scheduled/booking
   * external reference, or active owner key. Every form is an O(1) lookup;
   * unscoped legacy history is never scanned or imported.
   */
  async resolveMeetingId(input) {
    if (!input) return null;
    const direct = await this.redis.exists(this._room(input));
    if (direct) return String(input);
    const bySlug = await this.redis.get(this._slug(input));
    if (bySlug) return bySlug;
    const byExternalReference = await this.redis.get(this._external(input));
    if (byExternalReference) return byExternalReference;
    const byOwner = await this.redis.get(this._ownerActive(input));
    return byOwner || null;
  }

  async resolveExternalReference(externalReference) {
    if (!externalReference) return null;
    return this.redis.get(this._external(externalReference));
  }

  /**
   * Atomically reserve (domain_uuid, external_reference) in Redis. The returned
   * meeting id is stable across retries from booking/scheduling integrations.
   */
  async reserveExternalReference(externalReference, proposedMeetingId) {
    if (!externalReference) return proposedMeetingId;
    const key = this._external(externalReference);
    const claimed = await this.redis.set(key, proposedMeetingId, { NX: true, EX: this.ttl.room });
    if (claimed) return proposedMeetingId;
    return this.redis.get(key);
  }

  /** Patch room fields and refresh the TTL — activity keeps a room alive. */
  async updateRoom(meetingId, patch) {
    if (!meetingId) throw new MeetStateError("updateRoom requires meetingId", "MEET_NO_MEETING_ID");
    if (patch && patch.status && ROOM_STATUS.indexOf(patch.status) === -1) {
      throw new MeetStateError("invalid room status: " + patch.status, "MEET_BAD_STATUS");
    }
    const fields = {};
    Object.keys(patch || {}).forEach(function (k) {
      if (patch[k] !== undefined && patch[k] !== null) fields[k] = String(patch[k]);
    });
    if (Object.keys(fields).length === 0) return;
    const multi = this.redis.multi();
    multi.hSet(this._room(meetingId), fields);
    multi.expire(this._room(meetingId), this.ttl.room);
    await multi.exec();
  }

  /**
   * Mark a room live without ever moving its authoritative start time.
   *
   * LiveKit callbacks can arrive concurrently for the first participants. A
   * normal HSET lets the last callback win, which makes a meeting-wide timer
   * jump forward. HSETNX makes the first confirmed presence the permanent
   * anchor while the MULTI keeps the status and TTL update ordered with it.
   */
  async markLive(meetingId, startedAt) {
    if (!meetingId) throw new MeetStateError("markLive requires meetingId", "MEET_NO_MEETING_ID");
    const timestamp = String(startedAt === undefined ? this._now() : startedAt);
    const multi = this.redis.multi();
    multi.hSetNX(this._room(meetingId), "startedAt", timestamp);
    multi.hSet(this._room(meetingId), "status", "live");
    multi.expire(this._room(meetingId), this.ttl.room);
    await multi.exec();
    return this.redis.hGet(this._room(meetingId), "startedAt");
  }

  /**
   * End a room and drop its volatile presence state.
   *
   * The durable record in meet_meetings is the audit trail, so removing the live
   * The room and shareable indexes stay for their bounded TTL so a late join can
   * receive the structured `room_ended` result instead of a misleading 404.
   */
  async endRoom(meetingId) {
    const room = await this.getRoom(meetingId);
    const multi = this.redis.multi();
    multi.del(this._participants(meetingId));
    multi.del(this._waiting(meetingId));
    multi.del(this._intents(meetingId));
    multi.del(this._hands(meetingId));
    multi.del(this._mediaRequests(meetingId));
    multi.del(this._mediaRequestCooldowns(meetingId));
    multi.hDel(this._activeRooms(), meetingId);
    multi.expire(this._room(meetingId), this.ttl.room);
    if (room && room.ownerKey) multi.del(this._ownerActive(room.ownerKey));
    await multi.exec();
    return room;
  }

  // ── participants (live presence; LiveKit webhooks are the source of truth) ──

  async addParticipant(meetingId, participantId, data) {
    if (!meetingId || !participantId) {
      throw new MeetStateError("addParticipant requires meetingId and participantId", "MEET_NO_IDS");
    }
    const record = {
      participantId: participantId,
      displayName: (data && data.displayName) || "Guest",
      email: (data && data.email) || "",
      // The real photo, never the aliased one. Same rule as displayName above:
      // under a name-privacy policy the SFU copy is masked, and writing the mask
      // into the authoritative roster is unrecoverable.
      avatarUrl: (data && data.avatarUrl) || "",
      role: (data && data.role) || "participant",
      joinedAt: (data && data.joinedAt) || this._now(),
    };
    const multi = this.redis.multi();
    multi.hSet(this._participants(meetingId), participantId, JSON.stringify(record));
    multi.expire(this._participants(meetingId), this.ttl.room);
    multi.expire(this._room(meetingId), this.ttl.room);
    await multi.exec();
    return record;
  }

  /** O(1) removal — the legacy path was lrem over the global participant list. */
  async removeParticipant(meetingId, participantId) {
    if (!meetingId || !participantId) return 0;
    return this.redis.hDel(this._participants(meetingId), participantId);
  }

  async getParticipant(meetingId, participantId) {
    if (!meetingId || !participantId) return null;
    const raw = await this.redis.hGet(this._participants(meetingId), participantId);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (err) { return null; }
  }

  /** Update only the mutable meeting role; identity and attendance stay intact. */
  async setParticipantRole(meetingId, participantId, role) {
    if (role !== "participant" && role !== "cohost") {
      throw new MeetStateError("participant role must be participant or cohost", "MEET_BAD_ROLE");
    }
    const current = await this.getParticipant(meetingId, participantId);
    if (!current) return null;
    current.role = role;
    await this.redis.hSet(this._participants(meetingId), participantId, JSON.stringify(current));
    await this.redis.expire(this._participants(meetingId), this.ttl.room);
    return current;
  }

  /** O(m) in THIS room's participants only, never in total participants. */
  async listParticipants(meetingId) {
    const hash = await this.redis.hGetAll(this._participants(meetingId));
    return Object.keys(hash || {}).map(function (id) {
      try {
        return JSON.parse(hash[id]);
      } catch (err) {
        return { participantId: id, displayName: "Guest", email: "", role: "participant" };
      }
    });
  }

  async countParticipants(meetingId) {
    return this.redis.hLen(this._participants(meetingId));
  }

  // ── host media policy ──

  /** Never null: an unconfigured room reports the permissive default at v1. */
  async getPolicy(meetingId) {
    if (!meetingId) return normalisePolicy({}, 1);
    const hash = await this.redis.hGetAll(this._policy(meetingId));
    return normalisePolicy(hash || {}, hash && hash.version);
  }

  /**
   * Apply a partial policy change and bump the version.
   *
   * `expectedVersion` is optional optimistic concurrency: a host acting on a
   * stale view is told to reconcile instead of silently overwriting a co-host's
   * change. The version is written explicitly rather than with HINCRBY so the
   * value the caller broadcasts is the value that was stored.
   *
   * A source that transitions unlocked -> locked clears that source's
   * exemptions. "Lock all cameras" has to mean everyone, otherwise a person
   * unlocked ten minutes ago stays live through a lock the host believes is
   * total.
   */
  async setPolicy(meetingId, patch, opts) {
    if (!meetingId) throw new MeetStateError("setPolicy requires meetingId", "MEET_NO_MEETING_ID");
    const options = opts || {};
    const current = await this.getPolicy(meetingId);
    if (options.expectedVersion !== undefined && options.expectedVersion !== null) {
      if (Number(options.expectedVersion) !== current.version) {
        return { conflict: true, policy: current };
      }
    }
    const next = normalisePolicy(Object.assign({}, current, patch || {}), current.version + 1);
    const changed = Object.keys(next).filter(function (key) {
      return key !== "version" && next[key] !== current[key];
    });
    if (changed.length === 0) {
      // Nothing moved. Reporting the current version — not a bumped one — keeps a
      // retried request from invalidating every other client's view.
      return { conflict: false, policy: current, changed: [] };
    }
    const fields = {
      version: String(next.version),
      cameraLocked: String(next.cameraLocked),
      microphoneLocked: String(next.microphoneLocked),
      cameraRequestsAllowed: String(next.cameraRequestsAllowed),
      microphoneRequestsAllowed: String(next.microphoneRequestsAllowed),
      hideParticipantNames: next.hideParticipantNames,
      cohostsMayManageMedia: String(next.cohostsMayManageMedia),
      chatLocked: String(next.chatLocked),
      // Every field is restated because this is a whole-block write: a field
      // missing from here survives its own PATCH and is then erased by the
      // next unrelated policy change.
      autoAdmit: String(next.autoAdmit),
      whiteboardLocked: String(next.whiteboardLocked),
      joinRequestSoundRepeats: String(next.joinRequestSoundRepeats),
      updatedAt: String(this._now()),
      updatedBy: String(options.actorId || ""),
      updatedByName: String(options.actorName || ""),
    };
    const multi = this.redis.multi();
    multi.hSet(this._policy(meetingId), fields);
    multi.expire(this._policy(meetingId), this.ttl.room);
    multi.expire(this._room(meetingId), this.ttl.room);
    await multi.exec();

    const relocked = POLICY_SOURCES.filter(function (source) {
      return !policyLocks(current, source) && policyLocks(next, source);
    });
    if (relocked.length) await this._clearGrantsForSources(meetingId, relocked);
    return { conflict: false, policy: next, changed: changed, relocked: relocked };
  }

  async _clearGrantsForSources(meetingId, sources) {
    const all = await this.redis.hGetAll(this._mediaGrants(meetingId));
    const doomed = Object.keys(all || {}).filter(function (field) {
      return sources.some(function (source) { return field.endsWith(":" + source); });
    });
    if (!doomed.length) return 0;
    await this.redis.hDel(this._mediaGrants(meetingId), doomed);
    return doomed.length;
  }

  /** One participant's exemptions, as a plain `{microphone, camera}` record. */
  async getMediaGrant(meetingId, participantId) {
    if (!meetingId || !participantId) return { microphone: false, camera: false };
    const values = await this.redis.hmGet(this._mediaGrants(meetingId), [
      participantId + ":microphone",
      participantId + ":camera",
    ]);
    return {
      microphone: (values && values[0]) === "1",
      camera: (values && values[1]) === "1",
    };
  }

  /** Every exemption in the room, keyed by participant. Moderator views only. */
  async listMediaGrants(meetingId) {
    const all = await this.redis.hGetAll(this._mediaGrants(meetingId));
    const grants = {};
    Object.keys(all || {}).forEach(function (field) {
      if (all[field] !== "1") return;
      const cut = field.lastIndexOf(":");
      if (cut <= 0) return;
      const participantId = field.slice(0, cut);
      const source = field.slice(cut + 1);
      if (POLICY_SOURCES.indexOf(source) === -1) return;
      if (!grants[participantId]) grants[participantId] = { microphone: false, camera: false };
      grants[participantId][source] = true;
    });
    return grants;
  }

  async setMediaGrant(meetingId, participantId, source, allowed) {
    if (!meetingId || !participantId || POLICY_SOURCES.indexOf(source) === -1) {
      throw new MeetStateError("media grant is invalid", "MEET_BAD_MEDIA_GRANT");
    }
    const field = participantId + ":" + source;
    const existing = (await this.redis.hGet(this._mediaGrants(meetingId), field)) === "1";
    if (existing === !!allowed) return { changed: false, allowed: existing };
    const multi = this.redis.multi();
    if (allowed) multi.hSet(this._mediaGrants(meetingId), field, "1");
    else multi.hDel(this._mediaGrants(meetingId), field);
    multi.expire(this._mediaGrants(meetingId), this.ttl.room);
    await multi.exec();
    return { changed: true, allowed: !!allowed };
  }

  /**
   * A stable, room-scoped pseudonym for one participant.
   *
   * HSETNX plus a counter, not `participants.length + 1`: a participant who
   * reconnects must come back as the same "Participant 3", and someone leaving
   * must not renumber everybody behind them.
   */
  async participantAlias(meetingId, participantId) {
    if (!meetingId || !participantId) return "Participant";
    const key = this._aliases(meetingId);
    const existing = await this.redis.hGet(key, participantId);
    if (existing) return existing;
    const ordinal = await this.redis.hIncrBy(key, "counter", 1);
    const alias = "Participant " + ordinal;
    const claimed = await this.redis.hSetNX(key, participantId, alias);
    await this.redis.expire(key, this.ttl.room);
    if (claimed) return alias;
    return (await this.redis.hGet(key, participantId)) || alias;
  }

  async listAliases(meetingId) {
    const all = await this.redis.hGetAll(this._aliases(meetingId));
    const aliases = {};
    Object.keys(all || {}).forEach(function (field) {
      if (field !== "counter") aliases[field] = all[field];
    });
    return aliases;
  }

  // ── participant-initiated media access requests ──

  /**
   * A locked participant asks a moderator for a source.
   *
   * The mirror image of `createMediaEnableRequest`, which is a moderator asking a
   * participant to switch something on. Both directions exist because consent
   * runs both ways: a moderator may not force a camera on, and a participant may
   * not lift their own lock.
   *
   * Anti-spam is structural rather than advisory: one pending request per
   * (participant, source), and a cooldown once it has been answered, so a denied
   * participant cannot re-ask into a moderator's face.
   */
  async createMediaAccessRequest(meetingId, input) {
    const source = input && input.source;
    if (!meetingId || !input || !input.participantId || POLICY_SOURCES.indexOf(source) === -1) {
      throw new MeetStateError("media access request is invalid", "MEET_BAD_ACCESS_REQUEST");
    }
    const now = this._now();
    const slot = "slot:" + input.participantId + ":" + source;
    const existingRaw = await this.redis.hGet(this._accessRequests(meetingId), slot);
    if (existingRaw) {
      try {
        const existing = JSON.parse(existingRaw);
        if (existing.status === "pending" && Number(existing.expiresAt) > now) {
          return { request: existing, duplicate: true };
        }
      } catch (_) {}
    }
    const cooldownRaw = await this.redis.hGet(this._accessCooldowns(meetingId), input.participantId + ":" + source);
    const cooldownUntil = Number(cooldownRaw || 0);
    if (cooldownUntil > now) return { cooldownUntil: cooldownUntil };
    const request = {
      id: input.id || crypto.randomUUID(),
      meetingId: meetingId,
      participantId: input.participantId,
      participantName: input.participantName || "Participant",
      source: source,
      status: "pending",
      createdAt: now,
      expiresAt: now + 120000,
    };
    const multi = this.redis.multi();
    multi.hSet(this._accessRequests(meetingId), slot, JSON.stringify(request));
    multi.hSet(this._accessRequests(meetingId), "id:" + request.id, JSON.stringify(request));
    multi.expire(this._accessRequests(meetingId), this.ttl.room);
    await multi.exec();
    return { request: request, duplicate: false };
  }

  async listMediaAccessRequests(meetingId) {
    const now = this._now();
    const all = await this.redis.hGetAll(this._accessRequests(meetingId));
    return Object.keys(all || {})
      .filter(function (field) { return field.indexOf("id:") === 0; })
      .map(function (field) {
        try { return JSON.parse(all[field]); } catch (_) { return null; }
      })
      .filter(function (request) {
        return request && request.status === "pending" && Number(request.expiresAt) > now;
      })
      .sort(function (a, b) { return Number(a.createdAt) - Number(b.createdAt); });
  }

  async getMediaAccessRequest(meetingId, requestId) {
    const raw = await this.redis.hGet(this._accessRequests(meetingId), "id:" + requestId);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (_) { return null; }
  }

  /**
   * Settle a request. `cooldownMs` starts only once a moderator has answered, so
   * a request nobody has seen yet never blocks the next one.
   */
  async respondMediaAccessRequest(meetingId, requestId, decision, opts) {
    const options = opts || {};
    const request = await this.getMediaAccessRequest(meetingId, requestId);
    if (!request) return null;
    if (request.status !== "pending") return { request: request, duplicate: true };
    const now = this._now();
    request.status = Number(request.expiresAt) <= now ? "expired" : decision;
    request.respondedAt = now;
    request.respondedBy = String(options.actorId || "");
    request.respondedByName = String(options.actorName || "");
    const cooldownMs = Number(options.cooldownMs || 60000);
    const multi = this.redis.multi();
    multi.hSet(this._accessRequests(meetingId), "id:" + request.id, JSON.stringify(request));
    multi.hDel(this._accessRequests(meetingId), "slot:" + request.participantId + ":" + request.source);
    multi.hSet(
      this._accessCooldowns(meetingId),
      request.participantId + ":" + request.source,
      String(now + cooldownMs)
    );
    multi.expire(this._accessRequests(meetingId), this.ttl.room);
    multi.expire(this._accessCooldowns(meetingId), this.ttl.room);
    await multi.exec();
    return { request: request, duplicate: false };
  }

  // ── consent-gated microphone/camera enable requests ──

  async createMediaEnableRequest(meetingId, input) {
    const source = input && input.source;
    if (!meetingId || !input || !input.targetId || !input.requesterId ||
        ["microphone", "camera"].indexOf(source) === -1) {
      throw new MeetStateError("media request is invalid", "MEET_BAD_MEDIA_REQUEST");
    }
    const now = this._now();
    const slot = "slot:" + input.targetId + ":" + source;
    const existingRaw = await this.redis.hGet(this._mediaRequests(meetingId), slot);
    if (existingRaw) {
      try {
        const existing = JSON.parse(existingRaw);
        if (existing.status === "pending" && Number(existing.expiresAt) > now) {
          return { request: existing, duplicate: existing.requesterId === input.requesterId, conflict: existing.requesterId !== input.requesterId };
        }
      } catch (_) {}
    }
    const cooldownRaw = await this.redis.hGet(this._mediaRequestCooldowns(meetingId), input.targetId + ":" + source);
    const cooldownUntil = Number(cooldownRaw || 0);
    if (cooldownUntil > now) return { cooldownUntil: cooldownUntil };
    const request = {
      id: input.id || crypto.randomUUID(),
      meetingId: meetingId,
      targetId: input.targetId,
      targetName: input.targetName || "Participant",
      requesterId: input.requesterId,
      requesterName: input.requesterName || "Moderator",
      requesterRole: input.requesterRole || "cohost",
      source: source,
      status: "pending",
      createdAt: now,
      expiresAt: now + 30000,
    };
    const multi = this.redis.multi();
    multi.hSet(this._mediaRequests(meetingId), slot, JSON.stringify(request));
    multi.hSet(this._mediaRequests(meetingId), "id:" + request.id, JSON.stringify(request));
    multi.hSet(this._mediaRequestCooldowns(meetingId), input.targetId + ":" + source, String(now + 60000));
    multi.expire(this._mediaRequests(meetingId), this.ttl.room);
    multi.expire(this._mediaRequestCooldowns(meetingId), this.ttl.room);
    await multi.exec();
    return { request: request, duplicate: false, conflict: false };
  }

  async listMediaEnableRequests(meetingId, targetId) {
    const now = this._now();
    const all = await this.redis.hGetAll(this._mediaRequests(meetingId));
    return Object.keys(all || {}).filter(function (field) { return field.indexOf("id:") === 0; }).map(function (field) {
      try { return JSON.parse(all[field]); } catch (_) { return null; }
    }).filter(function (request) {
      return request && request.targetId === targetId && request.status === "pending" && Number(request.expiresAt) > now;
    }).sort(function (a, b) { return Number(a.createdAt) - Number(b.createdAt); });
  }

  async respondMediaEnableRequest(meetingId, requestId, targetId, decision) {
    const raw = await this.redis.hGet(this._mediaRequests(meetingId), "id:" + requestId);
    if (!raw) return null;
    let request;
    try { request = JSON.parse(raw); } catch (_) { return null; }
    if (request.targetId !== targetId) return null;
    if (request.status !== "pending") return { request: request, duplicate: true };
    if (Number(request.expiresAt) <= this._now()) {
      request.status = "expired";
      request.respondedAt = this._now();
    } else {
      request.status = decision;
      request.respondedAt = this._now();
    }
    const multi = this.redis.multi();
    multi.hSet(this._mediaRequests(meetingId), "id:" + request.id, JSON.stringify(request));
    multi.hDel(this._mediaRequests(meetingId), "slot:" + request.targetId + ":" + request.source);
    multi.expire(this._mediaRequests(meetingId), this.ttl.room);
    await multi.exec();
    return { request: request, duplicate: false };
  }

  // A token is only an intent to join. It is deliberately separate from the
  // authoritative participant hash, which is updated only by gateway presence.
  async putParticipantIntent(meetingId, participantId, data) {
    const record = {
      participantId: participantId,
      displayName: (data && data.displayName) || "Guest",
      email: (data && data.email) || "",
      avatarUrl: (data && data.avatarUrl) || "",
      role: (data && data.role) || "participant",
      createdAt: this._now(),
    };
    const multi = this.redis.multi();
    multi.hSet(this._intents(meetingId), participantId, JSON.stringify(record));
    multi.expire(this._intents(meetingId), this.ttl.waiting);
    await multi.exec();
    return record;
  }

  async getParticipantIntent(meetingId, participantId) {
    const raw = await this.redis.hGet(this._intents(meetingId), participantId);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (err) { return null; }
  }

  async removeParticipantIntent(meetingId, participantId) {
    return this.redis.hDel(this._intents(meetingId), participantId);
  }

  async markActive(meetingId) {
    const multi = this.redis.multi();
    multi.hSet(this._activeRooms(), meetingId, String(this._now()));
    multi.expire(this._activeRooms(), this.ttl.room);
    // This global registry contains identifiers only, never gateway credentials.
    multi.hSet("meet:active-tenants", this.tenant.tenantKey, JSON.stringify({
      subdomain: this.tenant.subdomain,
      domainUuid: this.tenant.domainUuid,
      tenantKey: this.tenant.tenantKey,
      keyPrefix: this.tenant.keyPrefix,
    }));
    multi.expire("meet:active-tenants", this.ttl.room);
    await multi.exec();
  }

  async listActiveRooms() {
    return Object.keys((await this.redis.hGetAll(this._activeRooms())) || {});
  }

  /**
   * Active, confirmed meetings owned by one host.
   *
   * The scan is bounded by this tenant's active-room hash, never its meeting
   * history. A room becomes visible only after authoritative LiveKit presence
   * has changed its status to `live`; token intents alone are not a meeting.
   */
  async listActiveMeetingsForOwner(ownerKey, limit) {
    const maximum = Math.max(1, Math.min(20, Number(limit || 20)));
    const ids = await this.listActiveRooms();
    const meetings = [];
    for (let i = 0; i < ids.length; i++) {
      const room = await this.getRoom(ids[i]);
      if (!room || room.status !== "live" || room.ownerKey !== String(ownerKey || "")) continue;
      const participants = await this.listParticipants(ids[i]);
      meetings.push(Object.assign({}, room, {
        participantCount: participants.length,
        hostPresent: participants.some(function (participant) {
          return participant.role === "host" || participant.participantId === "host-" + ownerKey;
        }),
      }));
    }
    meetings.sort(function (a, b) {
      const left = Number(a.startedAt || a.createdAt || 0);
      const right = Number(b.startedAt || b.createdAt || 0);
      return right - left;
    });
    return meetings.slice(0, maximum);
  }

  async unmarkActive(meetingId) {
    return this.redis.hDel(this._activeRooms(), meetingId);
  }

  static async registeredTenants(redis) {
    const values = await redis.hGetAll("meet:active-tenants");
    return Object.keys(values || {}).map(function (key) {
      try { return Object.freeze(JSON.parse(values[key])); } catch (err) { return null; }
    }).filter(Boolean);
  }

  // ── waiting room ──

  /**
   * Stored as a HASH keyed by participantId because that is exactly the wire
   * shape the shipped client requires: {"waiting": {"<participantId>": {...}}}
   * (docs/MEET-API-CONTRACT.md section 7). HGETALL is the whole endpoint.
   */
  async addWaiting(meetingId, participantId, guest) {
    if (!meetingId || !participantId) {
      throw new MeetStateError("addWaiting requires meetingId and participantId", "MEET_NO_IDS");
    }
    const record = {
      name: (guest && guest.name) || "Guest",
      email: (guest && guest.email) || "",
      // Resolved from the joiner's own RingQ credential, never from the request
      // body. Stored on the waiting entry so it survives the wait and reaches
      // the media token when they are admitted — and so the moderator deciding
      // whether to admit them sees the same face the meeting will.
      avatarUrl: (guest && guest.avatarUrl) || "",
      requestedAt: (guest && guest.requestedAt) || this._now(),
    };
    const multi = this.redis.multi();
    multi.hSet(this._waiting(meetingId), participantId, JSON.stringify(record));
    multi.expire(this._waiting(meetingId), this.ttl.waiting);
    await multi.exec();
    return record;
  }

  /** Returns the contract's map shape directly; client sorts by requestedAt. */
  async listWaiting(meetingId) {
    const hash = await this.redis.hGetAll(this._waiting(meetingId));
    const out = {};
    Object.keys(hash || {}).forEach(function (id) {
      try {
        out[id] = JSON.parse(hash[id]);
      } catch (err) {
        out[id] = { name: "Guest", email: "", requestedAt: 0 };
      }
    });
    return out;
  }

  async removeWaiting(meetingId, participantId) {
    if (!meetingId || !participantId) return 0;
    return this.redis.hDel(this._waiting(meetingId), participantId);
  }

  // ── raised hands ──
  //
  // Persistent, unlike reactions: a hand stays up until it is lowered, so it
  // must survive a reconnect and be readable by a participant who joins later.
  // Reactions are transient and are never stored — they are broadcast and
  // forgotten, which is why there is no reaction key here.

  /**
   * Raise a hand, idempotently.
   *
   * Returns `{ hand, changed }`. `changed` is false when the hand was already
   * up, which is what stops a client retry from re-ordering the queue or firing
   * the moderator's chime a second time.
   *
   * Ordering comes from a per-room counter rather than the timestamp alone.
   * Two hands raised in the same millisecond are real at meeting scale, and
   * "who was first" is the entire point of the queue, so a tie that resolved
   * by map iteration order would be a coin flip.
   */
  async raiseHand(meetingId, participantId, data) {
    if (!meetingId || !participantId) {
      throw new MeetStateError("raiseHand requires meetingId and participantId", "MEET_NO_IDS");
    }
    const existing = await this.getHand(meetingId, participantId);
    if (existing) return { hand: existing, changed: false };
    const sequence = await this.redis.hIncrBy(this._room(meetingId), "handSequence", 1);
    const record = {
      participantId: participantId,
      displayName: (data && data.displayName) || "Guest",
      raisedAt: this._now(),
      sequence: Number(sequence),
    };
    const multi = this.redis.multi();
    multi.hSet(this._hands(meetingId), participantId, JSON.stringify(record));
    multi.expire(this._hands(meetingId), this.ttl.room);
    multi.expire(this._room(meetingId), this.ttl.room);
    await multi.exec();
    return { hand: record, changed: true };
  }

  /** Returns true only when a hand was actually up, so a no-op is detectable. */
  async lowerHand(meetingId, participantId) {
    if (!meetingId || !participantId) return false;
    const removed = await this.redis.hDel(this._hands(meetingId), participantId);
    return Number(removed) > 0;
  }

  async getHand(meetingId, participantId) {
    if (!meetingId || !participantId) return null;
    const raw = await this.redis.hGet(this._hands(meetingId), participantId);
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (err) { return null; }
  }

  /** Oldest raise first — this list is a queue, and the UI renders it in order. */
  async listHands(meetingId) {
    const hash = await this.redis.hGetAll(this._hands(meetingId));
    const hands = Object.keys(hash || {}).map(function (id) {
      try {
        return JSON.parse(hash[id]);
      } catch (err) {
        return { participantId: id, displayName: "Guest", raisedAt: 0, sequence: 0 };
      }
    });
    hands.sort(function (a, b) {
      const bySequence = Number(a.sequence || 0) - Number(b.sequence || 0);
      if (bySequence !== 0) return bySequence;
      return Number(a.raisedAt || 0) - Number(b.raisedAt || 0);
    });
    return hands;
  }

  // ── guest tokens ──

  /**
   * Guest sessions carry the admission decision, because the contract makes the
   * client poll guest-token until admitted and the two 403 messages must be
   * distinguishable ("Not admitted" vs "Declined by host").
   */
  async putGuest(token, session) {
    if (!token) throw new MeetStateError("putGuest requires a token", "MEET_NO_TOKEN");
    const record = {
      meetingId: (session && session.meetingId) || "",
      participantId: (session && session.participantId) || "",
      decision: (session && session.decision) || "waiting",
      role: (session && session.role) === "cohost" ? "cohost" : "participant",
      createdAt: this._now(),
    };
    const hash = hashToken(token);
    const multi = this.redis.multi();
    multi.set(this._guestByHash(hash), JSON.stringify(record), { EX: this.ttl.guest });
    if (record.meetingId && record.participantId) {
      multi.set(this._guestPointer(record.meetingId, record.participantId), hash, { EX: this.ttl.guest });
    }
    await multi.exec();
    return record;
  }

  async getGuest(token) {
    if (!token) return null;
    const raw = await this.redis.get(this._guest(token));
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (err) {
      return null;
    }
  }

  async setGuestDecision(token, decision) {
    return this._setDecisionByHash(hashToken(token), decision);
  }

  /**
   * Read a guest session from the moderator's side of the reverse pointer.
   *
   * Admission does not remove a guest from the waiting hash — they stay listed
   * until they redeem their media token — so "has this already been decided?"
   * can only be answered from the session, never from waiting-list membership.
   */
  async getGuestByParticipant(meetingId, participantId) {
    if (!meetingId || !participantId) return null;
    const hash = await this.redis.get(this._guestPointer(meetingId, participantId));
    if (!hash) return null;
    const raw = await this.redis.get(this._guestByHash(hash));
    if (!raw) return null;
    try { return JSON.parse(raw); } catch (err) { return null; }
  }

  /**
   * Admit/decline by participantId — what the host actually has. Resolves the
   * reverse pointer written by putGuest.
   */
  async setGuestDecisionByParticipant(meetingId, participantId, decision) {
    if (!meetingId || !participantId) return null;
    const pointer = this._guestPointer(meetingId, participantId);
    const hash = await this.redis.get(pointer);
    if (!hash) return null;
    const updated = await this._setDecisionByHash(hash, decision);
    // _setDecisionByHash refreshes the session, so keep its reverse index alive
    // for exactly the same window. Otherwise a later demotion could lose the
    // lookup while the still-promoted guest session remained redeemable.
    if (updated) await this.redis.expire(pointer, this.ttl.guest);
    return updated;
  }

  /**
   * Keep a promoted guest's next five-minute LiveKit token consistent with the
   * live role. This also revokes co-host API authority immediately on demotion.
   */
  async setGuestRoleByParticipant(meetingId, participantId, role) {
    if (role !== "participant" && role !== "cohost") {
      throw new MeetStateError("guest role must be participant or cohost", "MEET_BAD_ROLE");
    }
    if (!meetingId || !participantId) return null;
    const pointer = this._guestPointer(meetingId, participantId);
    const hash = await this.redis.get(pointer);
    if (!hash) return null;
    const raw = await this.redis.get(this._guestByHash(hash));
    if (!raw) return null;
    let current;
    try { current = JSON.parse(raw); } catch (err) { return null; }
    current.role = role;
    const multi = this.redis.multi();
    multi.set(this._guestByHash(hash), JSON.stringify(current), { EX: this.ttl.guest });
    multi.expire(pointer, this.ttl.guest);
    await multi.exec();
    return current;
  }

  async _setDecisionByHash(hash, decision) {
    if (["waiting", "admitted", "declined"].indexOf(decision) === -1) {
      throw new MeetStateError("invalid decision: " + decision, "MEET_BAD_DECISION");
    }
    const raw = await this.redis.get(this._guestByHash(hash));
    if (!raw) return null;
    let current;
    try {
      current = JSON.parse(raw);
    } catch (err) {
      return null;
    }
    current.decision = decision;
    await this.redis.set(this._guestByHash(hash), JSON.stringify(current), { EX: this.ttl.guest });
    return current;
  }

  async deleteGuest(token) {
    if (!token) return 0;
    return this.redis.del(this._guest(token));
  }
}

module.exports = {
  RoomStore: RoomStore,
  MeetStateError: MeetStateError,
  hashToken: hashToken,
  DEFAULT_TTL: DEFAULT_TTL,
  ROOM_STATUS: ROOM_STATUS,
  DEFAULT_POLICY: DEFAULT_POLICY,
  POLICY_SOURCES: POLICY_SOURCES,
  NAME_VISIBILITY: NAME_VISIBILITY,
  JOIN_REQUEST_SOUND_REPEATS: JOIN_REQUEST_SOUND_REPEATS,
  normalisePolicy: normalisePolicy,
  publishSourcesFor: publishSourcesFor,
  sourceLockedFor: sourceLockedFor,
  policyAllowsRequest: policyAllowsRequest,
  policyLocks: policyLocks,
};
