/**
 * RingQ Meet v1 REST endpoints, mounted under /meet/api/v1.
 *
 * Failures carry stable machine-readable `code` values. The `error` text is
 * retained for the staged client migration but must never drive client logic.
 *
 * Framework-free on purpose: takes an express-style (req, res) so it can mount
 * on the express already in ringq/node/package.json without importing it here,
 * which keeps this unit-testable with plain objects.
 *
 * Runtime: CommonJS on Node.js 24 LTS.
 */

const crypto = require("crypto");

const authMod = require("./auth.js");
const rateLimitMod = require("./rate-limit.js");
const collaborationMod = require("./collaboration.js");
const roomsMod = require("./rooms.js");
const attachmentsMod = require("./attachments.js");

/** Compatibility text for clients predating the typed v1 failure contract. */
const ERR_NOT_ADMITTED = "Not admitted";
const ERR_DECLINED = "Declined by host";

const ERROR_CODES = Object.freeze({
  NOT_ADMITTED: "not_admitted",
  DECLINED: "declined",
  ROOM_ENDED: "room_ended",
  UNAUTHORIZED: "unauthorized",
  FORBIDDEN: "forbidden",
  MEDIA_UNAVAILABLE: "media_unavailable",
  VALIDATION: "validation_error",
  NOT_FOUND: "not_found",
  RATE_LIMITED: "rate_limited",
  INTERNAL: "internal_error",
});

/**
 * Reactions the server will relay. A closed set on purpose: the payload reaches
 * every participant's screen, so "any string the client sent" would be a way to
 * render arbitrary text on other people's tiles.
 */
const REACTIONS = Object.freeze([
  "thumbsUp",
  "thumbsDown",
  "clap",
  "heart",
  "laugh",
  "surprise",
  "celebrate",
]);

/** Topic clients subscribe to for hands and reactions. Versioned. */
const INTERACTION_TOPIC = "ringq.meet.interaction.v1";
const CHAT_TOPIC = "ringq.meet.chat.v1";
const WHITEBOARD_TOPIC = "ringq.meet.whiteboard.v1";
const MEDIA_REQUEST_TOPIC = "ringq.meet.media-request.v1";
const POLICY_TOPIC = "ringq.meet.policy.v1";
const PRESENTATION_TOPIC = "ringq.meet.presentation.v1";
const CAPTURE_TOPIC = "ringq.meet.capture.v1";
const TRANSCRIPT_TOPIC = "ringq.meet.transcript.v1";
const WHITEBOARD_PRESENCE_TOPIC = "ringq.meet.whiteboard-presence.v1";
// Deliberately NOT the whiteboard's topic. One topic for both surfaces would put
// a slide trail on the board and a board trail on the slide, and a client cannot
// tell them apart after the fact - the packet carries a point in 0..1 and
// nothing that says which surface it belongs to.
const PRESENTATION_PRESENCE_TOPIC = "ringq.meet.presentation-presence.v1";

/** Three transient reactions per five seconds, one hand change per second. */
const REACTION_BURST = 3;
const REACTION_WINDOW_MS = 5000;
const HAND_WINDOW_MS = 1000;
const ACCESS_REQUEST_WINDOW_MS = 10000;

function sendJson(res, status, body) {
  res.statusCode = status;
  res.setHeader("Content-Type", "application/json");
  res.end(JSON.stringify(body));
}

function defaultErrorCode(status, message) {
  if (message === ERR_NOT_ADMITTED) return ERROR_CODES.NOT_ADMITTED;
  if (message === ERR_DECLINED) return ERROR_CODES.DECLINED;
  if (/ended/i.test(String(message))) return ERROR_CODES.ROOM_ENDED;
  if (status === 401) return ERROR_CODES.UNAUTHORIZED;
  if (status === 403) return ERROR_CODES.FORBIDDEN;
  if (status === 404) return ERROR_CODES.NOT_FOUND;
  if (status === 429) return ERROR_CODES.RATE_LIMITED;
  if (status >= 500) return ERROR_CODES.MEDIA_UNAVAILABLE;
  return ERROR_CODES.VALIDATION;
}

/** `error` remains for old clients; v1 clients branch exclusively on `code`. */
function sendError(res, status, code, message) {
  if (message === undefined) {
    message = code;
    code = defaultErrorCode(status, message);
  }
  sendJson(res, status, { code: code, error: message });
}

function errorStatus(err) {
  if (err && typeof err.statusCode === "number") return err.statusCode;
  if (err && err.code === "TENANT_UNKNOWN") return 404;
  if (err && String(err.code || "").indexOf("TENANT_") === 0) return 400;
  return 500;
}

function exceptionCode(err, status) {
  const code = String((err && err.code) || "").toUpperCase();
  if (code === "ROOM_ENDED") return ERROR_CODES.ROOM_ENDED;
  if (code.indexOf("AUTH_") === 0) return status === 403 ? ERROR_CODES.FORBIDDEN : ERROR_CODES.UNAUTHORIZED;
  if (code.indexOf("GATEWAY_") === 0) return ERROR_CODES.MEDIA_UNAVAILABLE;
  if (code.indexOf("TENANT_") === 0) return ERROR_CODES.MEDIA_UNAVAILABLE;
  return defaultErrorCode(status, err && err.message);
}

/**
 * Read a raw request body up to `limit`, and refuse past it.
 *
 * The global reader in server.js stops at 64 KiB and parses JSON; this exists
 * for the one route that carries an image. The cap is enforced **here** as
 * well as by nginx, because the limit that protects this process has to be
 * this process's — an nginx that is reconfigured, bypassed, or simply older
 * than the route is not a guarantee.
 *
 * Bytes past the limit are dropped rather than buffered, so an oversize upload
 * costs the memory of the allowance and not of the upload.
 */
function readRawBody(req, limit) {
  return new Promise(function (resolve, reject) {
    const chunks = [];
    let size = 0;
    let tooLarge = false;
    req.on("data", function (chunk) {
      size += chunk.length;
      if (size > limit) {
        tooLarge = true;
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", function () {
      if (tooLarge) {
        const err = new Error("Banner must be at most " + Math.floor(limit / 1024) + " KB");
        err.statusCode = 413;
        return reject(err);
      }
      resolve(Buffer.concat(chunks));
    });
    req.on("error", reject);
  });
}

function validIdentifier(value, max) {
  const text = String(value || "").trim();
  return text.length > 0 && text.length <= (max || 160) && /^[A-Za-z0-9._:-]+$/.test(text);
}

function validExternalReference(value) {
  return value === "" || validIdentifier(value, 160);
}

/** Policy fields a client may set, and the type each one must be. */
const POLICY_BOOLEANS = Object.freeze([
  "cameraLocked",
  "microphoneLocked",
  "cameraRequestsAllowed",
  "microphoneRequestsAllowed",
  "cohostsMayManageMedia",
  "chatLocked",
  "autoAdmit",
  "whiteboardLocked",
]);

/**
 * Policy fields that carry a whole number, and the closed range each accepts.
 *
 * Separate from the booleans because the *router* refuses an out-of-range value
 * while the *store* clamps one: a client must never be answered 200 for a value
 * this server did not store, and `normalisePolicy` must never throw on a hash it
 * reads back.
 */
const POLICY_INTEGERS = Object.freeze({
  joinRequestSoundRepeats: roomsMod.JOIN_REQUEST_SOUND_REPEATS,
});

/**
 * Validate a policy patch.
 *
 * Strict in both directions. An unknown field is refused rather than dropped, so
 * a client cannot believe it set something this server has never heard of; and an
 * unrecognised `hideParticipantNames` is an error rather than a downgrade to
 * `off`, because silently answering 200 while leaving every name visible is the
 * worst possible outcome for a privacy control.
 */
function parsePolicyPatch(body) {
  const source = body && typeof body === "object" ? body : {};
  const patch = {};
  const keys = Object.keys(source);
  for (let i = 0; i < keys.length; i++) {
    const key = keys[i];
    if (POLICY_BOOLEANS.indexOf(key) !== -1) {
      if (typeof source[key] !== "boolean") {
        return { error: key + " must be a boolean" };
      }
      patch[key] = source[key];
      continue;
    }
    if (Object.prototype.hasOwnProperty.call(POLICY_INTEGERS, key)) {
      const range = POLICY_INTEGERS[key];
      const value = source[key];
      // `typeof` first: `true` is a number to `Number()`, and a string that
      // happens to parse would let a client believe its type was accepted.
      if (typeof value !== "number" || !Number.isInteger(value) || value < range.min || value > range.max) {
        return { error: key + " must be a whole number between " + range.min + " and " + range.max };
      }
      patch[key] = value;
      continue;
    }
    if (key === "hideParticipantNames") {
      const value = String(source[key]);
      if (roomsMod.NAME_VISIBILITY.indexOf(value) === -1) {
        return { error: "hideParticipantNames must be one of " + roomsMod.NAME_VISIBILITY.join(", ") };
      }
      patch[key] = value;
      continue;
    }
    return { error: key + " is not a policy field" };
  }
  return { patch: patch };
}

/**
 * Room statuses a meeting cannot come back from.
 *
 * Two of the four in ROOM_STATUS, not one: a "missed" room is as dead as an
 * "ended" one, and checking only the latter leaves the same defect behind a
 * rarer trigger.
 */
/**
 * The pre-join presentation a meeting carries into its lobby.
 *
 * Deliberately NOT "the webinar record". This is the subset of it that an
 * unauthenticated stranger holding the meeting code may see: what the event is
 * called, who is presenting, when it starts and where to follow them. The
 * registrant list, their addresses and every internal id stay in the PHP app
 * where they are, and nothing here may grow to include them.
 */
const LOBBY_KINDS = Object.freeze(["webinar"]);
const LOBBY_MAX_SOCIALS = 8;
const LOBBY_MAX_JSON_BYTES = 4096;
const LOBBY_BANNER_MAX_BYTES = 2 * 1024 * 1024;
const LOBBY_BANNER_MIMES = Object.freeze(["image/jpeg", "image/png", "image/webp"]);

/**
 * The presenter's portrait has a far smaller ceiling than the banner, and the
 * reason is what it is drawn at: a 32pt avatar. A two-megabyte portrait behind
 * one is bytes paid on every fetch for pixels no viewer can resolve.
 */
const LOBBY_PHOTO_MAX_BYTES = 512 * 1024;

/**
 * The binary assets a lobby may carry, and what each one is called everywhere.
 *
 * Two named assets rather than one generic `lobby/asset/:kind` route: the
 * banner's URLs are deployed, cached and mutation-proven, and re-spelling them
 * to gain a third asset nobody has asked for would be a change with no
 * beneficiary. What IS shared is the handler body below — duplicating a
 * hundred lines of upload-and-serve is how the two would drift, and this
 * repository has paid for that shape more than once.
 *
 * `field` is the room-hash field holding the metadata. It is a field of its own
 * per asset and never part of `lobby`, because `createRoom` rewrites `lobby`
 * wholesale on every Start and every Copy link.
 */
const LOBBY_ASSETS = Object.freeze({
  banner: Object.freeze({
    kind: "banner",
    field: "lobbyBanner",
    maxBytes: LOBBY_BANNER_MAX_BYTES,
    label: "Banner",
    recorder: "meetingLobbyBanner",
    payloadKey: "banner",
  }),
  photo: Object.freeze({
    kind: "photo",
    field: "lobbyPhoto",
    maxBytes: LOBBY_PHOTO_MAX_BYTES,
    label: "Presenter photo",
    recorder: "meetingLobbyPhoto",
    payloadKey: "photo",
  }),
});

/**
 * An IANA-shaped zone name, checked by shape and never resolved.
 *
 * The label it produces is the organiser's own wall clock; the instant is
 * carried separately by `startsAt`, which is why a name this server's zone
 * database happens not to know is harmless here.
 */
const LOBBY_TIMEZONE = /^[A-Za-z][A-Za-z0-9_+-]*(?:\/[A-Za-z0-9_+-]+){0,2}$/;

/**
 * An absolute instant, offset included.
 *
 * The trailing offset is mandatory and that is the whole point. A webinar row
 * stores its start as three separate strings — date, time and zone — and
 * composing them anywhere but the client reads them in *that* machine's
 * default zone: a live box was measured at `America/Sao_Paulo`, eleven hours
 * from an Asian tenant. An offsetless value here would be that defect wearing
 * a validated field's clothes, so it is refused rather than guessed at.
 */
const LOBBY_INSTANT = /^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:[Zz]|[+-]\d{2}:\d{2})$/;

/**
 * Whether a social link may be rendered as an anchor for a stranger.
 *
 * The scheme is the whole control, and a hostname check beside it would be
 * unreachable code: http and https are WHATWG *special* schemes, so a URL
 * carrying either cannot parse without a host at all — `https:`, `https://`
 * and `https://#f` every one of them throw, which the try/catch already
 * answers. One was written here, and its mutation survived every fixture that
 * could be put in front of it; it is gone rather than left looking load-bearing.
 *
 * What the two arms genuinely divide is scheme-with-host (`ftp://host/x`) from
 * unparseable-or-hostless (`javascript:alert(1)`, `https:`), and a negative
 * fixture set made only of the second kind would not exercise the first.
 */
function lobbyUrlIsSafe(value) {
  const text = String(value || "").trim();
  if (!text || text.length > 500) return false;
  let parsed;
  try {
    parsed = new URL(text);
  } catch (err) {
    return false;
  }
  return parsed.protocol === "http:" || parsed.protocol === "https:";
}

/**
 * Validate a lobby presentation, or say which field is wrong.
 *
 * Every failure is a 400 naming the field. A lobby is read by people who were
 * invited and never configured anything, so quietly dropping a value the host
 * did set would put a screen in front of them that nobody chose — worse than
 * refusing the create, which the host is standing in front of.
 */
function parseLobby(raw) {
  if (raw === undefined || raw === null) return { lobby: null };
  if (typeof raw !== "object" || Array.isArray(raw)) return { error: "lobby must be an object" };

  const kind = String(raw.kind || "").toLowerCase();
  if (LOBBY_KINDS.indexOf(kind) === -1) {
    return { error: "lobby.kind must be one of: " + LOBBY_KINDS.join(", ") };
  }
  const lobby = { kind: kind };

  if (raw.presenterName !== undefined && raw.presenterName !== null) {
    const name = String(raw.presenterName).trim();
    if (name.length > 120) return { error: "lobby.presenterName must be at most 120 characters" };
    if (name) lobby.presenterName = name;
  }

  if (raw.startsAt !== undefined && raw.startsAt !== null && String(raw.startsAt).trim() !== "") {
    const startsAt = String(raw.startsAt).trim();
    if (!LOBBY_INSTANT.test(startsAt) || Number.isNaN(new Date(startsAt).getTime())) {
      return { error: "lobby.startsAt must be an ISO-8601 instant carrying its UTC offset" };
    }
    // Stored verbatim rather than normalised to Z: the offset is what lets a
    // client print the organiser's own clock without a timezone database, and
    // the guest bundle deliberately carries no such dependency.
    lobby.startsAt = startsAt;
  }

  if (raw.timezone !== undefined && raw.timezone !== null && String(raw.timezone).trim() !== "") {
    const zone = String(raw.timezone).trim();
    if (zone.length > 64 || !LOBBY_TIMEZONE.test(zone)) {
      return { error: "lobby.timezone must be an IANA zone name" };
    }
    lobby.timezone = zone;
  }

  if (raw.socials !== undefined && raw.socials !== null) {
    if (!Array.isArray(raw.socials)) return { error: "lobby.socials must be an array" };
    if (raw.socials.length > LOBBY_MAX_SOCIALS) {
      return { error: "lobby.socials must hold at most " + LOBBY_MAX_SOCIALS + " links" };
    }
    const socials = [];
    for (let i = 0; i < raw.socials.length; i++) {
      const entry = raw.socials[i];
      if (!entry || typeof entry !== "object" || Array.isArray(entry)) {
        return { error: "lobby.socials[" + i + "] must be an object" };
      }
      const url = String(entry.url || "").trim();
      if (!lobbyUrlIsSafe(url)) {
        return { error: "lobby.socials[" + i + "].url must be an http or https address" };
      }
      const key = String(entry.key || "").trim().slice(0, 40);
      const label = String(entry.label || "").trim().slice(0, 40);
      socials.push({ key: key, label: label || key, url: url });
    }
    if (socials.length) lobby.socials = socials;
  }

  // `banner` is never accepted from a request body. It is written only by the
  // upload route, which has the bytes in its hands — a client able to declare
  // one could claim artwork that does not exist and a size that is not true.
  if (JSON.stringify(lobby).length > LOBBY_MAX_JSON_BYTES) {
    return { error: "lobby is too large" };
  }
  return { lobby: lobby };
}

/**
 * The lobby without its banner, which is what the room hash carries.
 *
 * The bytes are never written there; even the banner's metadata lives in its
 * own field, so that `lobby` can be rewritten by a create without taking the
 * artwork with it.
 */
function publicLobbyMetadata(lobby) {
  const out = Object.assign({}, lobby || {});
  delete out.banner;
  delete out.photo;
  return out;
}

/** Parse a room hash's stored lobby. Anything unreadable is simply no lobby. */
function storedLobby(value) {
  if (!value) return null;
  try {
    const parsed = typeof value === "string" ? JSON.parse(value) : value;
    return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : null;
  } catch (err) {
    return null;
  }
}

/**
 * The lobby as a stranger may read it: metadata, never bytes.
 *
 * The banner's image is fetched by its own route so that this response — which
 * a client may re-read while it waits for a host — stays a few hundred bytes,
 * and so the browser and anything in front of it can cache the picture.
 */
function publicLobbyAsset(stored, override, key) {
  const asset = storedLobby(override) || (stored && stored[key]);
  if (!asset || !asset.sha256) return null;
  return {
    mime: String(asset.mime || "image/jpeg"),
    bytes: Number(asset.bytes) || 0,
    sha256: String(asset.sha256),
  };
}

function publicLobby(lobby, bannerOverride, photoOverride) {
  const stored = storedLobby(lobby);
  if (!stored || LOBBY_KINDS.indexOf(String(stored.kind || "")) === -1) return null;
  const out = { kind: stored.kind };
  if (stored.presenterName) out.presenterName = String(stored.presenterName);
  if (stored.startsAt) out.startsAt = String(stored.startsAt);
  if (stored.timezone) out.timezone = String(stored.timezone);
  if (Array.isArray(stored.socials) && stored.socials.length) out.socials = stored.socials;
  // The room hash keeps the banner's metadata in its own field, because
  // `createRoom` rewrites `lobby` wholesale every time a host presses Start or
  // Copy link — artwork stored inside it would be erased by the press that
  // re-sent the very metadata it belongs to.
  const banner = publicLobbyAsset(stored, bannerOverride, "banner");
  if (banner) out.banner = banner;
  const photo = publicLobbyAsset(stored, photoOverride, "photo");
  if (photo) out.photo = photo;
  return out;
}

/**
 * How many queued guests one flip of the auto-admit switch may let in.
 *
 * Fifty is a meeting; five hundred queued at once is something else, and the
 * honest response to it is to admit a bounded number and say how many are
 * left rather than to run an unbounded write loop inside one PATCH.
 */
const AUTO_ADMIT_DRAIN_MAX = 50;

/** The audit actor for an admission the policy made and no person did. */
const AUTO_ADMIT_ACTOR = "system:auto-admit";

const TERMINAL_ROOM_STATUS = Object.freeze(["ended", "missed"]);

/**
 * Meet types the calendar creates, all of which the durable row records as
 * `scheduled`. The live room keeps the narrower value so a lobby can tell a
 * webinar from a booking.
 */
const SCHEDULED_MEET_TYPES = Object.freeze(["scheduled", "booking", "webinar"]);

/**
 * A short, human-friendly meeting code, used for shareable links.
 * Unambiguous alphabet: no O/0, I/1/l — meeting codes get read aloud.
 */
function newMeetingCode() {
  const alphabet = "abcdefghjkmnpqrstuvwxyz23456789";
  let out = "";
  const bytes = crypto.randomBytes(12);
  for (let i = 0; i < 12; i++) out += alphabet[bytes[i] % alphabet.length];
  return out.slice(0, 4) + "-" + out.slice(4, 8) + "-" + out.slice(8, 12);
}

/**
 * @param {object} deps
 * @param {object} deps.resolver     TenantResolver
 * @param {(tenant) => object} deps.storeFor  builds a RoomStore for a tenant
 * @param {object} deps.hostAuth     HostAuthenticator
 * @param {string} deps.guestSecret  HMAC key for guest tokens
 * @param {object} [deps.recorder]   optional durable writer (Postgres via PHP)
 * @param {object} deps.gateway central mTLS SFU gateway client
 * @param {object} [deps.presence] callback + reconciliation coordinator
 * @param {object} [deps.logger]
 */
function createRouter(deps) {
  const d = deps || {};
  function validWorkerBearer(req) {
    const configured = Buffer.from(String(d.transcriberSecret || ""), "utf8");
    const header = String(req.headers && req.headers.authorization || "");
    const supplied = Buffer.from(header.startsWith("Bearer ") ? header.slice(7) : "", "utf8");
    return configured.length >= 32 && supplied.length === configured.length && crypto.timingSafeEqual(configured, supplied);
  }
  ["resolver", "storeFor", "hostAuth", "guestSecret", "gateway"].forEach(function (k) {
    if (!d[k]) throw new TypeError("createRouter requires " + k);
  });
  const log = d.logger || { info: function () {}, warn: function () {}, error: function () {} };
  const limiter = d.rateLimiter || new rateLimitMod.RateLimiter();
  // Separate limiters because these need their own windows, and the shared
  // 60-second one cannot express "three per five seconds".
  const reactionLimiter =
    d.reactionLimiter || new rateLimitMod.RateLimiter({ windowMs: REACTION_WINDOW_MS });
  const handLimiter = d.handLimiter || new rateLimitMod.RateLimiter({ windowMs: HAND_WINDOW_MS });
  const whiteboardLimiter = d.whiteboardLimiter || new rateLimitMod.RateLimiter({ windowMs: 1000 });
  const whiteboardByteLimiter = d.whiteboardByteLimiter || new rateLimitMod.RateLimiter({ windowMs: 1000 });
  // One access request per participant per ten seconds, on top of the durable
  // per-source cooldown. The cooldown stops re-asking after a decision; this
  // stops a client hammering the endpoint before anybody has decided anything.
  const accessRequestLimiter =
    d.accessRequestLimiter || new rateLimitMod.RateLimiter({ windowMs: ACCESS_REQUEST_WINDOW_MS });
  const features = Object.assign({
    chat: false,
    chatAttachments: false,
    whiteboard: false,
    participantMediaControls: false,
    chatAttachmentPreview: false,
    filePresentations: false,
    meetingRecording: false,
    meetingTranscription: false,
    meetingSummary: false,
    meetingReviews: false,
    whiteboardEnhancements: false,
    hostPrivacyControls: false,
  }, d.features || {});

  /**
   * Broadcast an interaction event, treating delivery failure as non-fatal.
   *
   * The control plane has already committed the authoritative state, and
   * `GET /interactions` is what a client reconciles from. Failing the caller's
   * request because the fan-out hiccuped would tell them their hand did not go
   * up when it did.
   */
  async function broadcastInteraction(tenant, meetingId, payload) {
    if (!d.gateway.broadcastRoomData) return;
    try {
      await d.gateway.broadcastRoomData(tenant, meetingId, INTERACTION_TOPIC, payload);
    } catch (err) {
      log.warn("meet: interaction broadcast failed: " + err.message);
    }
  }

  async function broadcastCollaboration(tenant, meetingId, topic, payload) {
    if (!d.gateway.broadcastRoomData) return;
    try {
      await d.gateway.broadcastRoomData(tenant, meetingId, topic, payload);
    } catch (err) {
      log.warn("meet: collaboration broadcast failed: " + err.message);
    }
  }

  async function broadcastTargeted(tenant, meetingId, topic, payload, identities) {
    if (!d.gateway.broadcastRoomData) return;
    try {
      await d.gateway.broadcastRoomData(tenant, meetingId, topic, payload, identities);
    } catch (err) {
      log.warn("meet: targeted broadcast failed: " + err.message);
    }
  }

  async function broadcastLossy(tenant, meetingId, topic, payload) {
    if (!d.gateway.broadcastRoomData) return;
    try {
      await d.gateway.broadcastRoomData(tenant, meetingId, topic, payload, undefined, "lossy");
    } catch (err) {
      log.warn("meet: lossy broadcast failed: " + err.message);
    }
  }

  function scheduleMediaRequestExpiry(ctx, meetingId, request) {
    const delay = Math.max(1, Number(request.expiresAt) - Date.now() + 10);
    const timer = setTimeout(async function () {
      try {
        const outcome = await ctx.store.respondMediaEnableRequest(
          meetingId,
          request.id,
          request.targetId,
          "expired"
        );
        if (!outcome || outcome.duplicate || outcome.request.status !== "expired") return;
        const expiryMask = await participantNameMask(ctx, meetingId);
        await broadcastTargeted(ctx.tenant, meetingId, MEDIA_REQUEST_TOPIC, {
          kind: "enableResponse",
          requestId: request.id,
          participantId: request.targetId,
          participantName: expiryMask
            ? expiryMask(request.targetId, request.targetName)
            : request.targetName,
          source: request.source,
          decision: "expired",
          respondedAt: new Date(Number(outcome.request.respondedAt)).toISOString(),
        }, [request.requesterId]);
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: "system",
          action: "participant_media_response",
          targetRef: request.requesterId,
          details: { source: request.source, decision: "expired", requestId: request.id },
        });
      } catch (err) {
        log.warn("meet: media request expiry failed: " + err.message);
      }
    }, delay);
    if (timer.unref) timer.unref();
  }

  function requireFeature(name) {
    if (!features[name]) {
      throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
    }
    if (!d.collaboration) {
      throw new collaborationMod.CollaborationError("Collaboration storage is unavailable", "COLLABORATION_UNAVAILABLE", 503);
    }
  }

  function requireCaptureFeature(name) {
    if (!features[name]) {
      throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
    }
    if (!d.capture) {
      throw new collaborationMod.CollaborationError("Capture storage is unavailable", "CAPTURE_UNAVAILABLE", 503);
    }
  }

  /** Per-participant limiting, so one noisy client cannot mute a whole tenant. */
  function enforceParticipantRate(req, res, instance, key, limit, weight) {
    const result = instance.consume(key, limit, weight);
    res.setHeader("X-RateLimit-Remaining", String(result.remaining));
    if (result.allowed) return true;
    res.setHeader("Retry-After", String(result.retryAfterSeconds));
    sendError(res, 429, ERROR_CODES.RATE_LIMITED, "Slow down a moment");
    return false;
  }

  /** Resolve tenant + store once per request. Async: see TenantResolver.resolve. */
  async function context(req) {
    const tenant = await d.resolver.resolve(req);
    if (d.presence) await d.presence.registerTenant(tenant);
    return { tenant: tenant, store: d.storeFor(tenant) };
  }

  /**
   * Write a lobby presentation everywhere it has to live, and answer with the
   * public view of it.
   *
   * Two stores, for two different lifetimes. Redis carries it for the room's
   * twelve hours so the lookup a waiting attendee makes is one round trip; the
   * durable row carries it for good, because a webinar link is handed out
   * weeks before that TTL means anything.
   *
   * Each asset's metadata is restored, never rewritten. The bytes are uploaded
   * on their own request and may well predate this one — reading them back is
   * what lets the host be told `bannerSha256`/`photoSha256` and skip
   * re-uploading artwork the server already has.
   *
   * Durably fail-soft: losing the mirror costs a lobby its picture after the
   * room expires. Failing the create would cost the host their meeting.
   */
  async function persistLobby(ctx, meetingId, lobby, existingRoom) {
    await ctx.store.updateRoom(meetingId, { lobby: JSON.stringify(lobby) });
    if (d.recorder && d.recorder.meetingLobby) {
      try {
        await d.recorder.meetingLobby(ctx.tenant, { meetingId: meetingId, lobby: lobby });
      } catch (err) {
        log.warn("meet: durable meetingLobby failed: " + (err && err.message));
      }
    }

    const kinds = Object.keys(LOBBY_ASSETS);
    const found = {};
    const missing = [];
    for (let i = 0; i < kinds.length; i++) {
      const asset = LOBBY_ASSETS[kinds[i]];
      const held = storedLobby(existingRoom && existingRoom[asset.field]);
      if (held) found[asset.kind] = held;
      else missing.push(asset);
    }
    // One durable read for however many assets the room hash could not answer
    // for, rather than one per asset.
    if (missing.length && d.recorder && d.recorder.readLobby) {
      try {
        const stored = await d.recorder.readLobby(ctx.tenant, meetingId);
        for (let i = 0; i < missing.length; i++) {
          const asset = missing[i];
          const durable = stored && stored[asset.payloadKey];
          if (!durable || !durable.sha256) continue;
          const meta = { mime: durable.mime, bytes: durable.bytes, sha256: durable.sha256 };
          found[asset.kind] = meta;
          const patch = {};
          patch[asset.field] = JSON.stringify(meta);
          await ctx.store.updateRoom(meetingId, patch);
        }
      } catch (err) {
        log.warn("meet: durable readLobby failed: " + (err && err.message));
      }
    }
    return publicLobby(lobby, found.banner, found.photo);
  }

  /**
   * Put a lobby the durable row still knows about back on the room hash.
   *
   * The read is gated by its caller on the meeting having a durable history at
   * all, so an ordinary instant meeting never pays for it.
   */
  async function restoreLobbyToRoom(ctx, meetingId) {
    if (!d.recorder || !d.recorder.readLobby) return null;
    let stored = null;
    try {
      stored = await d.recorder.readLobby(ctx.tenant, meetingId);
    } catch (err) {
      return null;
    }
    if (!stored || !stored.kind) return null;
    const patch = { lobby: JSON.stringify(publicLobbyMetadata(stored)) };
    const kinds = Object.keys(LOBBY_ASSETS);
    const found = {};
    for (let i = 0; i < kinds.length; i++) {
      const asset = LOBBY_ASSETS[kinds[i]];
      const durable = stored[asset.payloadKey];
      if (!durable || !durable.sha256) continue;
      found[asset.kind] = { mime: durable.mime, bytes: durable.bytes, sha256: durable.sha256 };
      patch[asset.field] = JSON.stringify(found[asset.kind]);
    }
    await ctx.store.updateRoom(meetingId, patch);
    return publicLobby(stored, found.banner, found.photo);
  }

  /**
   * The durable row behind a code Redis has forgotten — but only for meetings
   * that carry a lobby.
   *
   * Deliberately narrow. Answering for every durable row would change what a
   * stale link means for every meeting ever created: an instant meeting that
   * ended last March would start reporting `ended` forever where it reports
   * `exists: false` today. A webinar is the case that needs this, because its
   * link is emailed weeks ahead and its row is reopened on the same reference
   * for each session, so "the host has not started it" is the true answer
   * rather than a convenient one.
   */
  async function durableLobbyMeeting(ctx, code) {
    if (!d.recorder || !d.recorder.findMeetingForLookup) return null;
    try {
      const row = await d.recorder.findMeetingForLookup(ctx.tenant, code);
      if (!row || !row.lobby) return null;
      return row;
    } catch (err) {
      log.warn("meet: durable findMeetingForLookup failed: " + (err && err.message));
      return null;
    }
  }

  /**
   * What a whiteboard this meeting has never opened should START locked as.
   *
   * Read on every whiteboard call because only the insert can know whether it
   * is the first one; `ON CONFLICT DO NOTHING` discards it for a board that
   * already exists, so paying a hash read is the whole cost of never having to
   * ask "is this the first touch?".
   *
   * Unreadable means unlocked, which is this policy's permissive default: a
   * board nobody can draw on, because a Redis read failed, is a worse outcome
   * than one drawn on by somebody the host meant to restrict.
   */
  async function initialWhiteboardLock(ctx, meetingId) {
    try {
      const policy = await ctx.store.getPolicy(meetingId);
      return !!(policy && policy.whiteboardLocked);
    } catch (err) {
      return false;
    }
  }

  /**
   * Let the people already queued in when auto-admit is switched on.
   *
   * Switching the policy on changes what happens to the NEXT request and
   * nothing else, so without this a host flips the switch and watches five
   * people go on waiting under a control that says they will not — which reads
   * as the feature being broken rather than as a boundary nobody drew.
   *
   * Sequential, through the same per-guest path `admit` uses, never a bulk
   * write: `setGuestDecisionByParticipant` is the only thing that makes an
   * admission real, and a second way to perform one is a second way for the two
   * to disagree.
   *
   * Bounded, and the bound is reported rather than hidden. A queue longer than
   * the cap is a room under something closer to an attack than a meeting, and
   * silently admitting all of it is the wrong answer either way.
   *
   * Never throws to its caller: a policy that committed but could not drain is
   * recoverable by flipping the switch again; a policy refused because the
   * drain was slow is a control that appears not to work at all.
   */
  async function drainWaitingRoom(ctx, meetingId, actor) {
    let waiting = null;
    try {
      waiting = await ctx.store.listWaiting(meetingId);
    } catch (err) {
      log.warn("meet: auto-admit drain could not read the waiting room: " + (err && err.message));
      return { admitted: 0, remaining: 0 };
    }
    const ids = Object.keys(waiting || {});
    let admitted = 0;
    let remaining = 0;
    for (let i = 0; i < ids.length; i++) {
      if (admitted >= AUTO_ADMIT_DRAIN_MAX) {
        remaining = ids.length - i;
        break;
      }
      const participantId = ids[i];
      try {
        const existing = await ctx.store.getGuestByParticipant(meetingId, participantId);
        // Already decided, or the session expired underneath the entry. Both are
        // ordinary and neither is this function's business to overturn.
        if (!existing || existing.decision !== "waiting") continue;
        if (!(await ctx.store.setGuestDecisionByParticipant(meetingId, participantId, "admitted"))) continue;
        admitted++;
        await recordAutoAdmit(ctx, meetingId, participantId, { via: "policy", actorRole: actor && actor.role });
      } catch (err) {
        log.warn("meet: auto-admit drain failed for one guest: " + (err && err.message));
      }
    }
    return { admitted: admitted, remaining: remaining };
  }

  /**
   * Audit an admission that no person made.
   *
   * Its own action rather than `admit`, and a sentinel actor rather than the
   * room owner's key, because both halves of an audit row are claims: writing
   * `admit` under the owner's key would have the trail say a human made a
   * decision they were not present for. `actor_key` is NOT NULL, so the
   * sentinel is a string that cannot be mistaken for an owner key.
   *
   * `recorder.moderationEvent` silently returns null for an action outside its
   * allow-list, and PostgreSQL enforces `meet_audit_events_action_check` on top
   * of that — so `auto_admit` has to be in both or every auto-admission is
   * unaudited with no error anywhere. It has been added to each.
   */
  async function recordAutoAdmit(ctx, meetingId, participantId, details) {
    if (!d.recorder || !d.recorder.moderationEvent) return;
    try {
      await d.recorder.moderationEvent(ctx.tenant, {
        meetingId: meetingId,
        actorKey: AUTO_ADMIT_ACTOR,
        action: "auto_admit",
        targetRef: participantId,
        details: details || {},
      });
    } catch (err) {
      log.warn("meet: durable auto_admit audit failed: " + (err && err.message));
    }
  }

  /**
   * Store one lobby asset's bytes. Shared by the banner and the presenter photo.
   *
   * Raw binary, not JSON and not multipart. JSON would mean base64 through a
   * 64 KiB body reader (server.js BODY_LIMIT) behind a 64 KiB nginx cap, and
   * multipart would mean a parser for a request that carries exactly one thing.
   * Both routes are listed in server.js's streamed-body allow-list, which is
   * what keeps that 64 KiB default in force for every other route.
   *
   * One body for two assets deliberately. The alternative is a hundred lines
   * duplicated per asset, which is the shape that lets two paths that must
   * behave identically stop doing so — and the banner's own suite passing
   * unchanged is what proves this body still behaves as the original did.
   */
  async function uploadLobbyAsset(asset, req, res, params) {
    const ctx = await context(req);
    const host = await d.hostAuth.requireHost(req, ctx.tenant);
    const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
    if (!meetingId) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
    const room = await ctx.store.getRoom(meetingId);
    if (!room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
    // Only the meeting's owner. A co-host may run the meeting; the artwork on
    // the page strangers land on belongs to whoever created it.
    if (String(room.ownerKey || "") !== String(host.ownerKey || "")) {
      return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Only the meeting owner can set the lobby " + asset.kind);
    }

    let body;
    try {
      body = await readRawBody(req, asset.maxBytes);
    } catch (err) {
      return sendError(res, err && err.statusCode === 413 ? 413 : 400, ERROR_CODES.VALIDATION, (err && err.message) || "Upload failed");
    }
    if (!body.length) return sendError(res, 400, ERROR_CODES.VALIDATION, asset.label + " body is empty");

    // Decided by the bytes, never by the declared type. A content-type header
    // is whatever the sender typed.
    const mime = attachmentsMod.signatureMime(body);
    if (LOBBY_BANNER_MIMES.indexOf(mime) === -1) {
      return sendError(res, 400, ERROR_CODES.VALIDATION, asset.label + " must be a JPEG, PNG or WebP image");
    }

    const meta = {
      mime: mime,
      bytes: body.length,
      sha256: crypto.createHash("sha256").update(body).digest("hex"),
    };
    const data = body.toString("base64");
    await ctx.store.setLobbyAsset(meetingId, asset.kind, Object.assign({ data: data }, meta));
    const patch = {};
    patch[asset.field] = JSON.stringify(meta);
    await ctx.store.updateRoom(meetingId, patch);
    if (d.recorder && d.recorder[asset.recorder]) {
      try {
        const payload = { meetingId: meetingId };
        payload[asset.payloadKey] = Object.assign({ data: data }, meta);
        await d.recorder[asset.recorder](ctx.tenant, payload);
      } catch (err) {
        log.warn("meet: durable " + asset.recorder + " failed: " + (err && err.message));
      }
    }
    const out = {};
    out[asset.payloadKey] = meta;
    return sendJson(res, 200, out);
  }

  /**
   * Serve one lobby asset to anybody holding the meeting code.
   *
   * The version segment is a cache buster and nothing else: it is part of the
   * path rather than a query because the clients build their URLs through
   * helpers that drop queries, and because a changed path is the only cache
   * invalidation that needs no cooperation from anything in front of us.
   */
  async function serveLobbyAsset(asset, req, res, params) {
    if (!enforceRate(req, res, "lobby-" + asset.kind, 120)) return;
    if (!validIdentifier(params.slug)) return sendError(res, 400, ERROR_CODES.VALIDATION, "Meeting code is invalid");
    const ctx = await context(req);
    const meetingId = await ctx.store.resolveMeetingId(params.slug);

    let cached = meetingId ? await ctx.store.getLobbyAsset(meetingId, asset.kind) : null;
    let resolvedId = meetingId;
    if (!cached) {
      // The ordinary case for a meeting nobody has started: no room, and the
      // picture living only on the durable row.
      const durable = resolvedId ? null : await durableLobbyMeeting(ctx, params.slug);
      resolvedId = resolvedId || (durable && durable.meetingId) || null;
      if (!resolvedId) return sendError(res, 404, ERROR_CODES.NOT_FOUND, asset.label + " not found");
      let stored = durable && durable.lobby ? durable.lobby : null;
      if (!stored && d.recorder && d.recorder.readLobby) {
        try {
          stored = await d.recorder.readLobby(ctx.tenant, resolvedId);
        } catch (err) {
          stored = null;
        }
      }
      const held = stored && stored[asset.payloadKey];
      if (!held || !held.data) {
        return sendError(res, 404, ERROR_CODES.NOT_FOUND, asset.label + " not found");
      }
      cached = held;
      try {
        await ctx.store.setLobbyAsset(resolvedId, asset.kind, cached);
      } catch (err) {
        // A cache that will not take is not a failed request.
      }
    }

    const body = Buffer.from(String(cached.data), "base64");
    const etag = '"' + String(cached.sha256 || "") + '"';
    if (String(req.headers["if-none-match"] || "") === etag) {
      res.statusCode = 304;
      res.setHeader("ETag", etag);
      return res.end();
    }
    res.statusCode = 200;
    res.setHeader("Content-Type", LOBBY_BANNER_MIMES.indexOf(cached.mime) !== -1 ? cached.mime : "image/jpeg");
    res.setHeader("Content-Length", String(body.length));
    res.setHeader("ETag", etag);
    // Safe to keep for a day: a re-uploaded asset is a different URL.
    res.setHeader("Cache-Control", "public, max-age=86400, immutable");
    return res.end(body);
  }


  /**
   * The name this participant should be known by inside the room.
   *
   * Aliasing is applied to standard participants only. A host or co-host is the
   * identified moderator of the meeting — hiding them would remove the one
   * accountable name in the room — and the requirement is about participants.
   */
  async function publishedNameFor(ctx, meetingId, participantId, realName, role, policy) {
    if (!features.hostPrivacyControls) return realName;
    if (policy.hideParticipantNames === "off") return realName;
    if (role !== "participant") return realName;
    return ctx.store.participantAlias(meetingId, participantId);
  }

  /**
   * Resolve the media policy as it applies to one participant.
   *
   * `publishSources` is left `undefined` unless a lock is actually in force.
   * Sending an allow-list for an unlocked room would work, but it would also
   * change the grant of every existing meeting for no reason; an absent list is
   * exactly the unrestricted behaviour this feature is additive to.
   */
  /**
   * The photo as the room is allowed to see it.
   *
   * A face identifies a person far more strongly than a name does, so a
   * participant whose name is masked to "Participant 3" must not be showing
   * their photograph two inches away — that would defeat the control entirely.
   * Exactly mirrors `publishedNameFor` above, and is called from the same line.
   *
   * Consequence worth naming: this also hides the photo from a *moderator*, who
   * does get the real name back from the moderator-only roster endpoint. There
   * is no way to do otherwise here — the photo travels in LiveKit participant
   * metadata, which is one value broadcast to the whole room, so giving it to
   * the moderator would give it to everybody.
   */
  function publishedAvatarFor(avatarUrl, role, policy) {
    if (!avatarUrl) return "";
    if (!features.hostPrivacyControls) return avatarUrl;
    if (policy.hideParticipantNames === "off") return avatarUrl;
    if (role !== "participant") return avatarUrl;
    return "";
  }

  async function policyViewFor(ctx, meetingId, participantId, role) {
    const policy = await ctx.store.getPolicy(meetingId);
    const grant = await ctx.store.getMediaGrant(meetingId, participantId);
    const enforced =
      features.hostPrivacyControls && (policy.microphoneLocked === true || policy.cameraLocked === true);
    return {
      policy: policy,
      grant: grant,
      publishSources: enforced ? roomsMod.publishSourcesFor(policy, grant, role) : undefined,
    };
  }

  async function mintToken(ctx, meetingId, participantId, displayName, role, avatarUrl) {
    const participantRole = ["host", "cohost"].indexOf(role) !== -1 ? role : "participant";
    const view = await policyViewFor(ctx, meetingId, participantId, participantRole);
    const publishedName = await publishedNameFor(
      ctx,
      meetingId,
      participantId,
      displayName,
      participantRole,
      view.policy
    );
    const publishedAvatar = publishedAvatarFor(avatarUrl, participantRole, view.policy);
    const result = await d.gateway.mintToken(ctx.tenant, {
      meetingId: meetingId,
      identity: participantId,
      name: publishedName,
      avatarUrl: publishedAvatar,
      role: participantRole,
      canPublish: true,
      canSubscribe: true,
      publishSources: view.publishSources,
    });
    if (!result || !result.token || !result.serverUrl) {
      const err = new Error("SFU gateway returned an incomplete media token");
      err.code = "GATEWAY_BAD_RESPONSE";
      err.statusCode = 502;
      throw err;
    }
    // The policy travels back with the token because the client has to decide
    // whether to capture the microphone *before* it connects. Fetching it
    // afterwards would publish a track the policy forbids and then withdraw it.
    return Object.assign({}, result, {
      policy: view.policy,
      mediaLocks: mediaLocksFor(view.policy, view.grant, participantRole),
    });
  }

  /** What this participant may not turn on, in the shape the client renders. */
  function mediaLocksFor(policy, grant, role) {
    return {
      microphone: roomsMod.sourceLockedFor(policy, grant, role, "microphone"),
      camera: roomsMod.sourceLockedFor(policy, grant, role, "camera"),
    };
  }

  /**
   * Push a committed policy onto the SFU, participant by participant.
   *
   * Deliberately after the commit and deliberately non-fatal per participant:
   * the control plane is the authority, `GET /policy` is what a client
   * reconciles from, and one unreachable identity must not abort the sweep for
   * everyone else.
   *
   * Moderators are included, not skipped. `publishSourcesFor` grants them every
   * source, so restating it is idempotent — and skipping them assumed a
   * moderator's grant could never be restrictive, which is false for anyone
   * promoted while a lock was in force: the skip then preserved the very
   * restriction it assumed could not exist. Restating makes a stale grant
   * self-heal on the next policy change instead of lasting the whole meeting.
   */
  async function applyPolicyToRoom(ctx, meetingId, policy, options) {
    const opts = options || {};
    const participants = await ctx.store.listParticipants(meetingId);
    const grants = await ctx.store.listMediaGrants(meetingId);
    let failures = 0;
    for (let i = 0; i < participants.length; i++) {
      const participant = participants[i];
      const role = participant.role === "host" || participant.role === "cohost" ? participant.role : "participant";
      if (opts.sources) {
        try {
          await d.gateway.updateParticipantPublishSources(
            ctx.tenant,
            meetingId,
            participant.participantId,
            roomsMod.publishSourcesFor(policy, grants[participant.participantId], role)
          );
        } catch (err) {
          failures++;
          log.warn("meet: publish-source update failed for " + participant.participantId + ": " + err.message);
        }
      }
      if (opts.names) {
        try {
          const name = await publishedNameFor(
            ctx,
            meetingId,
            participant.participantId,
            participant.displayName || "Guest",
            role,
            policy
          );
          await d.gateway.updateParticipantName(ctx.tenant, meetingId, participant.participantId, name);
        } catch (err) {
          failures++;
          log.warn("meet: name update failed for " + participant.participantId + ": " + err.message);
        }
      }
    }
    return { participants: participants.length, failures: failures };
  }

  /**
   * Re-apply one participant's SFU state after their role changed.
   *
   * A role change moves somebody across the line the media policy draws:
   * `publishSourcesFor` exempts moderators and restricts everyone else, and
   * `publishedNameFor` aliases participants only. The SFU re-derives neither --
   * `updateParticipantRole` writes the role metadata and nothing else, because
   * permissions and names are separate calls -- so without this a participant
   * promoted under a camera lock stays unable to publish, and a co-host demoted
   * under one keeps publishing freely.
   *
   * Deliberately non-fatal per step, for the same reason `applyPolicyToRoom` is:
   * the role has already committed and `GET /policy` is what a client
   * reconciles from, so an unreachable SFU must not turn a promotion into a 502
   * after the fact.
   */
  async function applyRoleToSfu(ctx, meetingId, participantId, role, displayName) {
    const view = await policyViewFor(ctx, meetingId, participantId, role);
    // Absent `publishSources` means no lock is in force, and that absence is
    // meaningful rather than merely empty: an unrestricted room needs no
    // allow-list, and sending one would rewrite the grant on every role change
    // in every unlocked meeting for no reason.
    if (view.publishSources) {
      try {
        await d.gateway.updateParticipantPublishSources(ctx.tenant, meetingId, participantId, view.publishSources);
      } catch (err) {
        log.warn("meet: role publish-source update failed for " + participantId + ": " + err.message);
      }
      // The permission change prevents the *next* publish; a demoted co-host's
      // live camera keeps flowing until it is muted. Same both-halves rule as
      // `muteNewlyLockedTracks`, and the reason a demotion is a real lock and
      // not just a smaller allow-list.
      for (let s = 0; s < roomsMod.POLICY_SOURCES.length; s++) {
        const source = roomsMod.POLICY_SOURCES[s];
        if (!roomsMod.sourceLockedFor(view.policy, view.grant, role, source)) continue;
        try {
          await d.gateway.muteParticipantTrack(ctx.tenant, meetingId, participantId, source);
        } catch (err) {
          const code = String((err && err.code) || "");
          if (!/track_not_found|participant_not_found|ROOM_NOT_FOUND/.test(code)) {
            log.warn("meet: role mute failed for " + participantId + ": " + err.message);
          }
        }
      }
    }
    // Names only matter while the policy hides them. A promotion out of an
    // aliased room has to restore the real name, and a demotion back into one
    // has to take it away again.
    if (features.hostPrivacyControls && view.policy.hideParticipantNames !== "off") {
      try {
        const name = await publishedNameFor(
          ctx,
          meetingId,
          participantId,
          displayName || "Guest",
          role,
          view.policy
        );
        await d.gateway.updateParticipantName(ctx.tenant, meetingId, participantId, name);
      } catch (err) {
        log.warn("meet: role name update failed for " + participantId + ": " + err.message);
      }
    }
    return view;
  }

  /**
   * Stop tracks a freshly applied lock now forbids.
   *
   * The SFU permission change prevents the *next* publish; a track that is
   * already live keeps flowing until it is muted. Both halves are needed for
   * "lock all cameras" to mean what a host reads it as.
   */
  async function muteNewlyLockedTracks(ctx, meetingId, policy) {
    const participants = await ctx.store.listParticipants(meetingId);
    const grants = await ctx.store.listMediaGrants(meetingId);
    for (let i = 0; i < participants.length; i++) {
      const participant = participants[i];
      if (participant.role !== "participant") continue;
      for (let s = 0; s < roomsMod.POLICY_SOURCES.length; s++) {
        const source = roomsMod.POLICY_SOURCES[s];
        if (!roomsMod.sourceLockedFor(policy, grants[participant.participantId], "participant", source)) continue;
        try {
          await d.gateway.muteParticipantTrack(ctx.tenant, meetingId, participant.participantId, source);
        } catch (err) {
          // "no such track" is the common case — most locked participants are not
          // publishing — and it is a success, not a failure.
          const code = String((err && err.code) || "");
          if (!/track_not_found|participant_not_found|ROOM_NOT_FOUND/.test(code)) {
            log.warn("meet: lock mute failed for " + participant.participantId + ": " + err.message);
          }
        }
      }
    }
  }

  /**
   * Apply a creation-time policy to a room that already exists.
   *
   * `POST /rooms` applies a policy before the room can be joined, so there is
   * no window in which the meeting is unlocked. A *reused* room has no such
   * guarantee — people may already be in it — so this has to do everything
   * `updateRoomPolicy` does: commit, push the SFU grants, stop what a new lock
   * has made illegal, and tell the room. Committing alone would leave the
   * grants untouched and the lock advisory, which is indistinguishable from
   * the control doing nothing.
   *
   * **Never fails the create.** A host must not be shut out of their own
   * meeting because a privacy setting could not be applied; the room is
   * returned either way and the failure is logged.
   */
  async function applyPolicyToExistingRoom(ctx, meetingId, policyPatch, host) {
    if (!policyPatch || Object.keys(policyPatch).length === 0) return null;
    try {
      requirePrivacyFeature();
      const outcome = await ctx.store.setPolicy(meetingId, policyPatch, {
        actorId: "host-" + host.ownerKey,
        actorName: host.displayName,
      });
      // No expectedVersion is sent, so this cannot conflict; guarded anyway
      // rather than trusting that to stay true.
      if (outcome.conflict) return outcome.policy;
      const changed = outcome.changed || [];
      // The room already holds exactly what was asked for. Reported rather
      // than swept through the SFU again, so a second press is free.
      if (changed.length === 0) return outcome.policy;

      const touchesSources = changed.some(function (key) {
        return key === "cameraLocked" || key === "microphoneLocked";
      });
      await applyPolicyToRoom(ctx, meetingId, outcome.policy, {
        sources: touchesSources,
        names: changed.indexOf("hideParticipantNames") !== -1,
      });
      if (touchesSources) await muteNewlyLockedTracks(ctx, meetingId, outcome.policy);
      await broadcastPolicy(ctx, meetingId, outcome.policy, null);
      if (d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: host.ownerKey,
          action: "media_policy_update",
          targetRef: null,
          details: { actorRole: "host", changed: changed, version: outcome.policy.version, source: "room_reuse" },
        });
      }
      if (d.recorder && d.recorder.meetingPolicy) {
        Promise.resolve(
          d.recorder.meetingPolicy(ctx.tenant, { meetingId: meetingId, policy: outcome.policy })
        ).catch(function (err) {
          log.warn("meet: durable meetingPolicy failed: " + err.message);
        });
      }
      return outcome.policy;
    } catch (err) {
      log.warn("meet: could not apply the configured policy to a reused room: " + (err && err.message));
      return null;
    }
  }

  async function broadcastPolicy(ctx, meetingId, policy, actor) {
    await broadcastCollaboration(ctx.tenant, meetingId, POLICY_TOPIC, {
      kind: "policy",
      policy: policy,
      changedBy: actor ? { participantId: actor.participantId, displayName: actor.displayName, role: actor.role } : null,
      occurredAt: new Date().toISOString(),
    });
  }

  function requirePrivacyFeature() {
    if (!features.hostPrivacyControls) {
      throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
    }
  }

  /**
   * Refuse a chat write when the host has restricted chat to moderators.
   *
   * Enforced here rather than by hiding the composer: a hidden control is not a
   * permission, and `POST /chat/messages` is reachable from a console.
   *
   * Deliberately inert while `hostPrivacyControls` is off, exactly as the
   * camera and microphone locks already are (see the token minting, which ANDs
   * the same flag before reporting a media lock). Turning the feature off means
   * the whole policy stops applying; the alternative leaves a room chat-locked
   * with no way to unlock it, because the PATCH that would lift it is gated on
   * the same flag.
   *
   * Returns true when the caller may write.
   */
  async function allowChatWrite(ctx, res, meetingId, access) {
    if (!features.hostPrivacyControls) return true;
    if (access.actor.isModerator) return true;
    const policy = await ctx.store.getPolicy(meetingId);
    if (!policy || policy.chatLocked !== true) return true;
    sendError(res, 403, "CHAT_LOCKED", "Only the host and co-hosts can send messages");
    return false;
  }

  /**
   * The name every room-facing surface may show for one participant.
   *
   * One mask, applied at every exit, because a name policy that holds on the
   * tiles and the chat while the hand queue, the live captions and the pen
   * label still announce who everybody is has not hidden anything. Built once
   * per request and handed the stored name, so the record keeps real names and
   * only the reading of it is masked.
   *
   * Resolves to null in the ordinary case (feature off, or names visible),
   * which keeps every existing meeting on exactly the path it was on.
   *
   * Three cases, in the safe order: a current moderator keeps their real name,
   * because that is the name the SFU publishes for them and their tile already
   * says it; anybody holding an alias gets the alias; and anybody else -- a
   * participant who left before the policy was switched on, so was never
   * aliased -- gets a bare "Participant". Falling back to the stored name would
   * leak exactly the name the host asked to hide.
   *
   * A moderator entitled to real names gets them back client-side from the
   * moderator-only roster endpoint, which is the same route the tiles already
   * take. In `everyone` mode that roster is masked too, so there is nothing to
   * restore from and the mask is the last word for every viewer.
   */
  async function participantNameMask(ctx, meetingId) {
    if (!features.hostPrivacyControls) return null;
    const policy = await ctx.store.getPolicy(meetingId);
    if (!policy || policy.hideParticipantNames === "off") return null;
    const aliases = await ctx.store.listAliases(meetingId);
    const participants = await ctx.store.listParticipants(meetingId);
    const moderators = {};
    participants.forEach(function (participant) {
      if (participant.role === "host" || participant.role === "cohost") {
        moderators[participant.participantId] = true;
      }
    });
    return function (participantId, storedName) {
      const id = String(participantId || "");
      if (moderators[id]) return storedName;
      return aliases[id] || "Participant";
    };
  }

  /** Apply a mask to one message, without touching what was stored. */
  function maskChatMessage(message, mask) {
    if (!mask || !message) return message;
    const masked = Object.assign({}, message, {
      senderName: mask(message.participantId, message.senderName),
    });
    // The quoted reply carries its own sender, and a mask that covered the
    // bubble but not the quote would announce the name one scroll further down.
    if (message.reply) {
      masked.reply = Object.assign({}, message.reply, {
        senderName: mask(message.reply.participantId, message.reply.senderName),
      });
    }
    return masked;
  }

  /** Apply a mask to one media access request, without touching what was stored. */
  function maskAccessRequest(request, mask) {
    if (!mask || !request) return request;
    return Object.assign({}, request, {
      participantName: mask(request.participantId, request.participantName),
    });
  }

  /**
   * Apply a mask to whatever carries a presenter, without touching what was
   * stored.
   *
   * Covers the whiteboard board, a whiteboard batch and a file presentation
   * alike: all three name the presenter with the same pair of fields, and
   * `presenterId` is written whenever `presenterName` is, so a null presenter
   * passes through untouched.
   */
  /**
   * Tell the room a page's ink changed.
   *
   * Rides the presentation topic rather than opening a second one: a client
   * that predates annotation reads `payload.presentation`, finds nothing, and
   * ignores the packet, so an upgrade needs no new subscription.
   *
   * The document is NOT in the packet. It can be far larger than the 15 KiB
   * reliable-data ceiling, so this is a nudge carrying the version -- the
   * client re-reads over HTTP, exactly as the chat history does.
   */
  async function broadcastAnnotations(tenant, meetingId, state) {
    return broadcastCollaboration(tenant, meetingId, PRESENTATION_TOPIC, {
      kind: "annotations",
      annotations: {
        assetId: state.assetId,
        pageNumber: state.pageNumber,
        version: state.version,
        sequence: state.sequence,
      },
    });
  }

  function maskPresenter(state, mask) {
    if (!mask || !state || !state.presenterName) return state;
    return Object.assign({}, state, {
      presenterName: mask(state.presenterId, state.presenterName),
    });
  }

  /**
   * Resolve the page an annotation request is about, and who may draw on it.
   *
   * The asset is pinned to whatever is CURRENTLY on the stage: ink belongs to
   * the deck the room is looking at, and accepting any asset id would let a
   * participant write onto a file nobody is presenting.
   *
   * The page deliberately need NOT be the current one. A moderator can page the
   * deck while somebody is mid-stroke, and that stroke belongs on the slide it
   * was drawn on -- silently dropping it would be the worse answer.
   */
  async function resolveAnnotationTarget(ctx, meetingId, access, source, opts) {
    const presentation = await d.collaboration.presentationState(ctx.tenant, meetingId);
    if (presentation.status !== "active" || !presentation.assetId) {
      throw new collaborationMod.CollaborationError(
        "No file is being presented", "PRESENTATION_NOT_ACTIVE", 409);
    }
    const assetId = String((source || {}).assetId || "");
    if (assetId && assetId !== presentation.assetId) {
      throw new collaborationMod.CollaborationError(
        "That file is not being presented", "PRESENTATION_NOT_ACTIVE", 409);
    }
    const pageNumber = Number((source || {}).pageNumber);
    const pageCount = Math.max(Number(presentation.pageCount) || 0, 1);
    if (!Number.isInteger(pageNumber) || pageNumber < 1 || pageNumber > pageCount) {
      throw new collaborationMod.CollaborationError(
        "pageNumber is out of range", "VALIDATION_ERROR", 400);
    }
    if (opts && opts.draw) {
      // Enforced here and not only in the UI. `everyone` is the presenter's own
      // decision; a moderator is included for the same reason they may page and
      // stop somebody else's deck.
      const mayDraw = presentation.presenterId === access.actor.participantId ||
        access.actor.isModerator ||
        presentation.annotationMode === "everyone";
      if (!mayDraw) {
        throw new collaborationMod.CollaborationError(
          "The presenter has not opened this file for drawing",
          "ANNOTATION_FORBIDDEN", 403);
      }
    }
    if (opts && opts.clear) {
      // Wiping a page is not the same as adding to it: only the presenter or a
      // moderator may do it, whatever the mode says, or one participant can
      // destroy everybody's work.
      const mayClear = presentation.presenterId === access.actor.participantId ||
        access.actor.isModerator;
      if (!mayClear) {
        throw new collaborationMod.CollaborationError(
          "Only the presenter or a moderator can clear a page",
          "ANNOTATION_FORBIDDEN", 403);
      }
    }
    return { presentation: presentation, assetId: presentation.assetId, pageNumber: pageNumber };
  }

  /**
   * A moderator who is allowed to change media policy.
   *
   * Two separate gates on purpose: `requireModerator` proves the caller is a
   * host or a live co-host, and `cohostsMayManageMedia` is the host's own
   * decision about whether co-hosts may use that authority here. The host can
   * never be locked out of it.
   */
  async function requirePolicyModerator(req, ctx, meetingId, room) {
    const moderator = await requireModerator(req, ctx, meetingId, room);
    if (moderator.role !== "host") {
      const policy = await ctx.store.getPolicy(meetingId);
      if (policy.cohostsMayManageMedia !== true) {
        throw new authMod.AuthError(
          "The host has reserved participant media control",
          "AUTH_FORBIDDEN",
          403
        );
      }
    }
    return moderator;
  }

  /**
   * Resolve either the immutable room owner or a currently admitted co-host.
   *
   * A guest token is only moderator authority while BOTH the guest session and
   * authoritative participant registry say cohost. Demotion therefore revokes
   * the next request immediately, without waiting for a media-token refresh.
   */
  async function requireModerator(req, ctx, meetingId, room) {
    try {
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      if (room && room.ownerKey && room.ownerKey !== host.ownerKey) {
        throw new authMod.AuthError("Not the host of this meeting", "AUTH_NOT_ROOM_HOST", 403);
      }
      return {
        actorKey: host.ownerKey,
        participantId: "host-" + host.ownerKey,
        displayName: host.displayName,
        role: "host",
      };
    } catch (err) {
      if (err && err.code !== "AUTH_INVALID_BEARER" && err.code !== "AUTH_BEARER_REQUIRED") throw err;
    }

    const raw = authMod.bearerOf(req.headers || {});
    const payload = authMod.verifyGuestToken(d.guestSecret, raw);
    if (payload.t !== ctx.tenant.tenantKey || payload.m !== meetingId) {
      throw new authMod.AuthError("Moderator token belongs to another meeting", "AUTH_WRONG_MEETING", 403);
    }
    const session = await ctx.store.getGuest(raw);
    if (!session || session.meetingId !== meetingId || session.participantId !== payload.p) {
      throw new authMod.AuthError("Guest session expired", "AUTH_INVALID_GUEST", 401);
    }
    const participant = await ctx.store.getParticipant(meetingId, session.participantId);
    if (
      session.decision !== "admitted" ||
      session.role !== "cohost" ||
      !participant ||
      participant.role !== "cohost"
    ) {
      throw new authMod.AuthError("Co-host permission is required", "AUTH_NOT_COHOST", 403);
    }
    const intent = participant || (await ctx.store.getParticipantIntent(meetingId, session.participantId));
    return {
      actorKey: session.participantId,
      participantId: session.participantId,
      displayName: (intent && intent.displayName) || "Co-host",
      role: "cohost",
    };
  }

  /**
   * Resolve the caller as a participant of this meeting — host, co-host, or
   * plain admitted guest.
   *
   * This is the auth level the interaction endpoints need: everyone in the room
   * may raise a hand or send a reaction, so gating them behind moderator
   * credentials would be wrong, and gating them behind nothing at all would let
   * anyone who learned a meeting id spam a meeting they are not in.
   *
   * Deliberately built on the same two credential types that already exist
   * rather than a third scheme: the host bearer and the admitted guest session.
   * `isModerator` is derived here so callers never re-derive it inconsistently.
   */
  async function requireParticipant(req, ctx, meetingId, room) {
    try {
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      if (room && room.ownerKey && room.ownerKey !== host.ownerKey) {
        throw new authMod.AuthError("Not a participant of this meeting", "AUTH_NOT_ROOM_HOST", 403);
      }
      return {
        participantId: "host-" + host.ownerKey,
        displayName: host.displayName,
        role: "host",
        isModerator: true,
      };
    } catch (err) {
      if (err && err.code !== "AUTH_INVALID_BEARER" && err.code !== "AUTH_BEARER_REQUIRED") throw err;
    }

    const raw = authMod.bearerOf(req.headers || {});
    const payload = authMod.verifyGuestToken(d.guestSecret, raw);
    if (payload.t !== ctx.tenant.tenantKey || payload.m !== meetingId) {
      throw new authMod.AuthError("Token belongs to another meeting", "AUTH_WRONG_MEETING", 403);
    }
    const session = await ctx.store.getGuest(raw);
    if (!session || session.meetingId !== meetingId || session.participantId !== payload.p) {
      throw new authMod.AuthError("Guest session expired", "AUTH_INVALID_GUEST", 401);
    }
    // Admission is the membership test. A guest still in the waiting room is not
    // in the meeting and must not be able to reach into it.
    if (session.decision !== "admitted") {
      throw new authMod.AuthError("You have not been admitted to this meeting", "AUTH_NOT_ADMITTED", 403);
    }
    const participant = await ctx.store.getParticipant(meetingId, session.participantId);
    const role = participant && participant.role === "cohost" ? "cohost" : "participant";
    const intent = participant || (await ctx.store.getParticipantIntent(meetingId, session.participantId));
    return {
      participantId: session.participantId,
      displayName: (intent && intent.displayName) || "Guest",
      role: role,
      isModerator: role === "cohost",
    };
  }

  async function requireMeetingOwner(req, ctx, meetingId, liveOnly) {
    if (!d.capture || !validUuid(meetingId)) {
      throw new collaborationMod.CollaborationError("Meeting not found", "NOT_FOUND", 404);
    }
    const host = await d.hostAuth.requireHost(req, ctx.tenant);
    const meeting = await d.capture.getMeeting(ctx.tenant, meetingId);
    if (!meeting) throw new collaborationMod.CollaborationError("Meeting not found", "NOT_FOUND", 404);
    if (meeting.owner_key !== host.ownerKey) {
      throw new authMod.AuthError("Not the host of this meeting", "AUTH_NOT_ROOM_HOST", 403);
    }
    if (liveOnly && meeting.status === "ended") {
      throw new collaborationMod.CollaborationError("Meeting has ended", "ROOM_ENDED", 409);
    }
    return { host: host, meeting: meeting, actorRef: "host-" + host.ownerKey };
  }

  async function requireCaptureViewer(req, ctx, meetingId) {
    try {
      const owner = await requireMeetingOwner(req, ctx, meetingId, false);
      return { actor: { participantId: owner.actorRef, displayName: owner.host.displayName, role: "host" }, owner: true };
    } catch (err) {
      if (err && err.code !== "AUTH_INVALID_BEARER" && err.code !== "AUTH_BEARER_REQUIRED") throw err;
    }
    const room = await ctx.store.getRoom(meetingId);
    if (!room || room.status === "ended") throw new collaborationMod.CollaborationError("Meeting has ended", "ROOM_ENDED", 409);
    return { actor: await requireParticipant(req, ctx, meetingId, room), owner: false };
  }

  /**
   * Live guests use the admitted-participant contract. The immutable owner may
   * additionally read ended collaboration history, but never mutate it.
   */
  async function requireCollaborationAccess(req, ctx, meetingId, opts) {
    const o = opts || {};
    if (!collaborationMod.uuid(String(meetingId || ""))) {
      throw new collaborationMod.CollaborationError("meetingId must be a UUID", "VALIDATION_ERROR", 400);
    }
    let meeting = null;
    try {
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      meeting = await d.collaboration.getMeeting(ctx.tenant, meetingId);
      if (!meeting || meeting.owner_key !== host.ownerKey) {
        throw new authMod.AuthError("Not the host of this meeting", "AUTH_NOT_ROOM_HOST", 403);
      }
      if (o.mutate && meeting.status === "ended") {
        throw new collaborationMod.CollaborationError("Meeting has ended", "ROOM_ENDED", 409);
      }
      return {
        actor: {
          participantId: "host-" + host.ownerKey,
          displayName: host.displayName,
          role: "host",
          isModerator: true,
        },
        meeting: meeting,
        room: await ctx.store.getRoom(meetingId),
        history: meeting.status === "ended",
      };
    } catch (err) {
      if (err && err.code !== "AUTH_INVALID_BEARER" && err.code !== "AUTH_BEARER_REQUIRED") throw err;
    }
    const room = await ctx.store.getRoom(meetingId);
    const actor = await requireParticipant(req, ctx, meetingId, room);
    if (!room) throw new collaborationMod.CollaborationError("Meeting not found", "NOT_FOUND", 404);
    if (room.status === "ended") throw new collaborationMod.CollaborationError("Meeting has ended", "ROOM_ENDED", 409);
    return { actor: actor, meeting: null, room: room, history: false };
  }

  function parseCursor(value, name) {
    if (value === undefined || value === null || value === "") return null;
    const text = String(value);
    if (!/^\d{1,18}$/.test(text)) {
      throw new collaborationMod.CollaborationError(name + " cursor is invalid", "VALIDATION_ERROR", 400);
    }
    const number = Number(text);
    if (!Number.isSafeInteger(number)) {
      throw new collaborationMod.CollaborationError(name + " cursor is invalid", "VALIDATION_ERROR", 400);
    }
    return number;
  }

  function validUuid(value) { return collaborationMod.uuid(String(value || "")); }

  function validateChatBody(value, allowEmpty) {
    const body = String(value === undefined || value === null ? "" : value).replace(/\r\n/g, "\n");
    if (body.indexOf("\u0000") !== -1 || Buffer.byteLength(body, "utf8") > 8192) {
      throw new collaborationMod.CollaborationError("Message must be valid text no larger than 8 KiB", "VALIDATION_ERROR", 400);
    }
    if (!allowEmpty && !body.trim()) {
      throw new collaborationMod.CollaborationError("Message text is required", "VALIDATION_ERROR", 400);
    }
    return body;
  }

  /**
   * Element operation types the whiteboard accepts.
   *
   * The annotation layer over a presented slide takes the SAME set. It used to
   * take a narrower one — pen and highlighter only — and the two are now
   * deliberately identical: a slide is marked up with the same tools a board is
   * drawn on, so a set that differed would be a rule with nothing behind it.
   *
   * Note what this does NOT widen. Select, move, resize, restyle and text-edit
   * need no operation type of their own: each is an upsert of an element id that
   * already exists, which applyOperations handles and inverseOperations already
   * turns into "restore previous". The two constants stay separate names because
   * the surfaces are separately authorised, not because their contents differ.
   */
  const WHITEBOARD_ELEMENT_TYPES = ["stroke", "highlighter", "shape", "text"];
  const ANNOTATION_ELEMENT_TYPES = ["stroke", "highlighter", "shape", "text"];

  function validateWhiteboardOperations(operations, allowedTypes) {
    const allowed = allowedTypes || WHITEBOARD_ELEMENT_TYPES;
    if (!Array.isArray(operations) || operations.length < 1 || operations.length > 64) {
      throw new collaborationMod.CollaborationError("operations must contain 1 to 64 entries", "VALIDATION_ERROR", 400);
    }
    if (Buffer.byteLength(JSON.stringify(operations), "utf8") > 12 * 1024) {
      throw new collaborationMod.CollaborationError("Whiteboard batch exceeds 12 KiB", "VALIDATION_ERROR", 400);
    }
    let destructive = false;
    operations.forEach(function (operation) {
      if (!operation || typeof operation !== "object") {
        throw new collaborationMod.CollaborationError("Whiteboard operation is invalid", "VALIDATION_ERROR", 400);
      }
      const type = String(operation.type || "");
      if (allowed.indexOf(type) !== -1) {
        const element = operation.element;
        if (!element || !validUuid(element.id)) {
          throw new collaborationMod.CollaborationError("Whiteboard element id is invalid", "VALIDATION_ERROR", 400);
        }
        if (type === "text" && String(element.text || "").length > 1000) {
          throw new collaborationMod.CollaborationError("Whiteboard text exceeds 1000 characters", "VALIDATION_ERROR", 400);
        }
        const kind = String(element.kind || "");
        const allowedKinds = type === "shape"
          ? ["line", "arrow", "rectangle", "ellipse"]
          : type === "stroke" ? ["pen"] : type === "highlighter" ? ["highlighter"] : ["text"];
        if (allowedKinds.indexOf(kind) === -1 || !Number.isFinite(Number(element.color)) ||
            !Number.isFinite(Number(element.width)) || Number(element.width) < 1 || Number(element.width) > 24) {
          throw new collaborationMod.CollaborationError("Whiteboard element style is invalid", "VALIDATION_ERROR", 400);
        }
        const points = Array.isArray(element.points) ? element.points : [];
        if (points.length > 64 || points.some(function (point) {
          return !Array.isArray(point) || point.length < 2 || point.length > 3 ||
            point.some(function (number) { return typeof number !== "number" || !Number.isFinite(number) || number < 0 || number > 1; });
        })) {
          throw new collaborationMod.CollaborationError("Whiteboard points are invalid", "VALIDATION_ERROR", 400);
        }
      } else if (type === "erase") {
        destructive = true;
        if (!Array.isArray(operation.elementIds) || operation.elementIds.length < 1 ||
          operation.elementIds.length > 100 || operation.elementIds.some(function (id) { return !validUuid(id); })) {
          throw new collaborationMod.CollaborationError("Whiteboard erase target is invalid", "VALIDATION_ERROR", 400);
        }
      } else if (WHITEBOARD_ELEMENT_TYPES.indexOf(type) !== -1) {
        // A real element type, just not one this surface offers.
        throw new collaborationMod.CollaborationError(
          "This drawing surface does not support " + type, "VALIDATION_ERROR", 400);
      } else {
        throw new collaborationMod.CollaborationError("Whiteboard operation type is unsupported", "VALIDATION_ERROR", 400);
      }
    });
    return destructive;
  }

  function enforceRate(req, res, scope, limit) {
    const key = scope + ":" + rateLimitMod.clientAddress(req);
    const result = limiter.consume(key, limit);
    res.setHeader("X-RateLimit-Remaining", String(result.remaining));
    if (result.allowed) return true;
    res.setHeader("Retry-After", String(result.retryAfterSeconds));
    sendError(res, 429, ERROR_CODES.RATE_LIMITED, "Too many meeting requests");
    return false;
  }

  async function requireMediaTarget(req, ctx, rawMeetingId, participantId) {
    const meetingId = await ctx.store.resolveMeetingId(rawMeetingId);
    const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
    const moderator = await requireModerator(req, ctx, meetingId, room);
    if (!meetingId || !room) {
      throw new collaborationMod.CollaborationError("Meeting not found", "NOT_FOUND", 404);
    }
    if (room.status === "ended") {
      throw new collaborationMod.CollaborationError("Meeting has ended", "ROOM_ENDED", 409);
    }
    const target = await ctx.store.getParticipant(meetingId, participantId);
    if (!target) {
      throw new collaborationMod.CollaborationError("Participant not found", "NOT_FOUND", 404);
    }
    if (participantId === moderator.participantId || target.role === "host") {
      throw new authMod.AuthError("This participant cannot be controlled", "AUTH_FORBIDDEN", 403);
    }
    if (moderator.role === "cohost" && target.role !== "participant") {
      throw new authMod.AuthError("Co-hosts can control standard participants only", "AUTH_FORBIDDEN", 403);
    }
    return { meetingId: meetingId, room: room, moderator: moderator, target: target };
  }

  const handlers = {
    async capabilities(req, res) {
      try {
        await context(req);
        const gatewayHealthy = await d.gateway.health();
        const collaborationHealth = d.collaboration
          ? await d.collaboration.health()
          : { chat: false, whiteboard: false };
        const attachmentHealthy = (features.chatAttachments || features.filePresentations) && d.attachments
          ? await d.attachments.health()
          : false;
        const documentHealthy = (features.chatAttachmentPreview || features.filePresentations) && d.documents
          ? await d.documents.health()
          : false;
        const captureHealthy = d.capture && (
          features.meetingRecording || features.meetingTranscription || features.meetingSummary || features.meetingReviews
        ) ? await d.capture.health() : false;
        return sendJson(res, 200, {
          apiVersion: "v1",
          enabled: gatewayHealthy,
          gatewayHealthy: gatewayHealthy,
          features: {
            audio: true,
            video: true,
            screenShare: true,
            waitingRoom: true,
            moderation: true,
            cohostManagement: true,
            cohostWaitingRoom: true,
            mobileLayoutV2: true,
            reactions: true,
            participantMediaControls: !!features.participantMediaControls,
            hostPrivacyControls: !!features.hostPrivacyControls,
            chat: !!features.chat && collaborationHealth.chat,
            chatAttachments:
              !!features.chat && !!features.chatAttachments && collaborationHealth.chat && attachmentHealthy,
            whiteboard: !!features.whiteboard && collaborationHealth.whiteboard,
            chatAttachmentPreview:
              !!features.chat && !!features.chatAttachmentPreview && collaborationHealth.chat,
            filePresentations:
              !!features.filePresentations && collaborationHealth.chat && attachmentHealthy && documentHealthy,
            documentConversion: !!documentHealthy,
            meetingRecording: !!features.meetingRecording && !!captureHealthy,
            meetingTranscription: !!features.meetingTranscription && !!captureHealthy,
            meetingSummary: !!features.meetingSummary && !!features.meetingTranscription && !!captureHealthy,
            meetingReviews: !!features.meetingReviews && !!captureHealthy,
            whiteboardEnhancements: !!features.whiteboard && !!features.whiteboardEnhancements && collaborationHealth.whiteboard,
            reconciliation: true,
            hostRejoin: true,
            cohostInvites: !!d.invites,
          },
          failure: gatewayHealthy ? null : { code: ERROR_CODES.MEDIA_UNAVAILABLE },
        });
      } catch (err) {
        return sendJson(res, 200, {
          apiVersion: "v1",
          enabled: false,
          gatewayHealthy: false,
          features: {},
          failure: { code: ERROR_CODES.MEDIA_UNAVAILABLE },
        });
      }
    },

    // ── collaboration history — immutable meeting owner only ──
    async collaborationHistory(req, res) {
      if (!features.chat && !features.whiteboard && !features.meetingRecording &&
          !features.meetingTranscription && !features.meetingSummary && !features.meetingReviews) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      if (!d.collaboration) {
        throw new collaborationMod.CollaborationError("Collaboration storage is unavailable", "COLLABORATION_UNAVAILABLE", 503);
      }
      const ctx = await context(req);
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      const query = req.query || {};
      const result = await d.collaboration.listHistory(ctx.tenant, host.ownerKey, {
        before: query.before || null,
        limit: query.limit ? Number(query.limit) : 30,
      });
      // Mask each flag by whether its feature is actually enabled. listHistory
      // reads the tables directly, so retained rows from a feature that has
      // since been turned off still advertised content the client would then be
      // refused: it asked for the section, got 404 FEATURE_DISABLED, and — since
      // one failure discarded the whole load — showed nothing but Retry. A flag
      // now means "you can fetch this", which is what the client assumes.
      const historyFeature = {
        hasChat: features.chat !== false,
        hasWhiteboard: features.whiteboard !== false,
        hasRecordings: features.meetingRecording !== false,
        hasTranscript: features.meetingTranscription !== false,
        hasSummary: features.meetingSummary !== false,
        hasReviews: features.meetingReviews !== false,
      };
      const meetings = (result.meetings || []).map(function (meeting) {
        const masked = Object.assign({}, meeting);
        Object.keys(historyFeature).forEach(function (flag) {
          if (!historyFeature[flag]) masked[flag] = false;
        });
        return masked;
      });
      return sendJson(res, 200, Object.assign({}, result, { meetings: meetings }));
    },

    async gatewayEvent(req, res) {
      if (!d.presence || !d.callbackAuth || !d.callbackAuth(req)) {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Gateway callback was not mTLS verified");
      }
      const ctx = await context(req);
      const body = req.body || {};
      if (d.capture && (body.type === "egress_ended" || body.type === "egress_updated")) {
        const recording = body.recording || {};
        // `egress_updated` is also emitted for healthy transitions such as
        // starting -> active. Only terminal events may finalize the MP4.
        const terminal = body.type === "egress_ended" || !!recording.error;
        if (terminal && validUuid(String(recording.recordingId || ""))) {
          await d.capture.finalizeRecording(
            ctx.tenant,
            String(body.meetingId || ""),
            String(recording.recordingId),
            body.type === "egress_ended" && !recording.error ? "complete" : "failed",
            { failureCode: recording.error ? "egress_failed" : null }
          );
        }
        return sendJson(res, 200, { result: true, duplicate: false });
      }
      const id = String((req.headers && req.headers["x-ringq-event-id"]) || "");
      const result = await d.presence.applyEvent(ctx.tenant, body, id);
      return sendJson(res, 200, { result: true, duplicate: !!result.duplicate });
    },

    async transcriptCallback(req, res) {
      requireCaptureFeature("meetingTranscription");
      if (!validWorkerBearer(req)) {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Transcript callback was not authenticated");
      }
      const ctx = await context(req);
      const body = req.body || {};
      if (body.final === false) {
        await broadcastCollaboration(ctx.tenant, body.meetingId, TRANSCRIPT_TOPIC, {
          kind: "partial",
          segment: {
            participantId: body.participantRef,
            trackSid: body.trackSid,
            sequence: body.sequence,
            startMs: body.startMs,
            endMs: body.endMs,
            language: body.language,
            text: String(body.text || "").slice(0, 8192),
            final: false,
          },
        });
        return sendJson(res, 200, { accepted: true, ephemeral: true });
      }
      const outcome = await d.capture.ingestTranscript(ctx.tenant, body);
      if (outcome.accepted) {
        // Stored under the real name -- the transcript is a record -- and read
        // under the policy, exactly as chat is. The caption strip is drawn for
        // everybody in the room, so this is a participant-facing surface.
        const captionMask = await participantNameMask(ctx, body.meetingId);
        await broadcastCollaboration(ctx.tenant, body.meetingId, TRANSCRIPT_TOPIC, {
          kind: "final",
          segment: {
            segmentId: body.segmentId,
            participantId: body.participantRef,
            participantName: captionMask
              ? captionMask(body.participantRef, outcome.participantName)
              : outcome.participantName,
            sequence: body.sequence,
            startMs: body.startMs,
            endMs: body.endMs,
            language: body.language,
            text: body.text,
            final: true,
          },
        });
      }
      return sendJson(res, outcome.accepted ? 201 : 200, outcome);
    },

    // ── 1. GET /rooms/:slug — public lookup ──
    async lookupRoom(req, res, params) {
      if (!enforceRate(req, res, "lookup", 60)) return;
      if (!validIdentifier(params.slug)) return sendError(res, 400, ERROR_CODES.VALIDATION, "Meeting code is invalid");
      const ctx = await context(req);
      // No early return on an unresolved code: a meeting Redis has never heard
      // of is exactly the case the durable branch below exists for, and
      // returning here would leave that branch reachable only in the narrow
      // window where the room hash has expired but its indexes have not.
      const meetingId = await ctx.store.resolveMeetingId(params.slug);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      if (!room) {
        // Redis forgot, or never knew. For a meeting carrying a lobby the
        // durable row still does — and answering `exists: false` there is the
        // reported defect: a webinar registrant opening the link they were
        // emailed last week is told the link is wrong.
        //
        // `not_started` is a distinct status on purpose. It must never read as
        // joinable, because there is no room to request admission to and the
        // join would 404; the client shows the lobby and waits for the host.
        const durable = await durableLobbyMeeting(ctx, params.slug);
        if (!durable) return sendJson(res, 200, { exists: false });
        return sendJson(res, 200, {
          exists: true,
          meetingId: durable.meetingId,
          status: "not_started",
          title: durable.title || null,
          startedAt: null,
          serverTime: new Date().toISOString(),
          meetType: durable.lobby && durable.lobby.kind ? String(durable.lobby.kind) : durable.meetType,
          lobby: publicLobby(durable.lobby) || undefined,
        });
      }
      return sendJson(res, 200, {
        exists: true,
        meetingId: meetingId,
        status: room.status || "created",
        title: room.title || null,
        startedAt: room.startedAt ? new Date(Number(room.startedAt)).toISOString() : null,
        serverTime: new Date().toISOString(),
        // Additive: absent on nothing that has one, so a meeting with no lobby
        // produces byte-for-byte the response it produced before this existed.
        meetType: room.meetType || "instant",
        lobby: publicLobby(room.lobby, room.lobbyBanner, room.lobbyPhoto) || undefined,
      });
    },

    // ── 1b. Lobby artwork ──
    //
    // Four thin entry points over two shared bodies (`uploadLobbyAsset` and
    // `serveLobbyAsset`). The URLs stay one per asset: the banner's are
    // deployed and cached, and re-spelling them would break every link already
    // handed out for a webinar that has not happened yet.
    async uploadLobbyBanner(req, res, params) {
      return uploadLobbyAsset(LOBBY_ASSETS.banner, req, res, params);
    },

    async uploadLobbyPhoto(req, res, params) {
      return uploadLobbyAsset(LOBBY_ASSETS.photo, req, res, params);
    },

    // ── 1c. GET /rooms/:slug/lobby/(banner|photo)/:version — public artwork ──
    async lobbyBanner(req, res, params) {
      return serveLobbyAsset(LOBBY_ASSETS.banner, req, res, params);
    },

    async lobbyPhoto(req, res, params) {
      return serveLobbyAsset(LOBBY_ASSETS.photo, req, res, params);
    },

    // ── 2. POST /rooms/:meetingId/join-request — public, enters waiting room ──
    async joinRequest(req, res, params) {
      if (!enforceRate(req, res, "join:" + String(params.meetingId).slice(0, 160), 20)) return;
      if (!validIdentifier(params.meetingId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "meetingId is invalid");
      const ctx = await context(req);
      const body = req.body || {};
      const displayName = String(body.displayName || "").trim();
      const email = String(body.email || "").trim();
      if (!displayName || displayName.length > 100) return sendError(res, 400, ERROR_CODES.VALIDATION, "displayName is required and must be at most 100 characters");
      if (email.length > 254 || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) return sendError(res, 400, ERROR_CODES.VALIDATION, "a valid email is required");

      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      if (!meetingId) return sendError(res, 404, "Meeting not found");
      const room = await ctx.store.getRoom(meetingId);
      if (!room) return sendError(res, 404, "Meeting not found");
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");

      // This route is public, and an internal RingQ user joining a colleague's
      // meeting comes through it exactly like an outside guest — so without
      // this, the only person in the room who could ever have a photo is the
      // owner. The credential is optional: absent or invalid simply means no
      // photo, and nothing about admission depends on it.
      //
      // The photo is taken from the bearer and NEVER from the request body.
      // A body-supplied URL would let anyone put an arbitrary image — someone
      // else's face, or worse — on their own tile in a stranger's meeting.
      let joinerAvatar = "";
      try {
        const identity = await d.hostAuth.resolveIdentity(req, ctx.tenant);
        if (identity) joinerAvatar = identity.avatarUrl || "";
      } catch (err) {
        joinerAvatar = "";
      }

      let invitedRole = null;
      const inviteToken = typeof body.inviteToken === "string" ? body.inviteToken.trim() : "";
      if (inviteToken && d.invites) {
        try {
          const invite = await d.invites.lookup(inviteToken);
          if (
            invite &&
            String(invite.domainUuid).toLowerCase() === String(ctx.tenant.domainUuid || "").toLowerCase() &&
            (await ctx.store.resolveExternalReference(invite.externalReference)) === meetingId
          ) {
            invitedRole = "cohost";
          }
        } catch (err) {
          invitedRole = null;
        }
      }

      const participantId = crypto.randomUUID ? crypto.randomUUID() : crypto.randomBytes(16).toString("hex");
      const token = authMod.createGuestToken(d.guestSecret, {
        t: ctx.tenant.tenantKey,
        m: meetingId,
        p: participantId,
      });
      await ctx.store.addWaiting(meetingId, participantId, {
        name: displayName,
        email: email,
        avatarUrl: joinerAvatar,
      });

      // Auto-admit is honoured only where the control that lifts it is
      // reachable. This DIVERGES from `chatLocked`, which is enforced whether or
      // not `hostPrivacyControls` is on, and the difference is the direction of
      // the failure: a chat lock that outlives its control is restrictive, and
      // being stuck restricted is recoverable. An auto-admit that outlives its
      // control admits strangers unattended on a tenant that cannot switch it
      // off, which is not.
      let autoAdmit = false;
      if (!invitedRole && features.hostPrivacyControls) {
        try {
          const policy = await ctx.store.getPolicy(meetingId);
          autoAdmit = !!(policy && policy.autoAdmit);
        } catch (err) {
          // An unreadable policy admits nobody. The permissive reading of a
          // failure is the one that puts a stranger in the room.
          autoAdmit = false;
        }
      }

      await ctx.store.putGuest(token, {
        meetingId: meetingId,
        participantId: participantId,
        decision: invitedRole || autoAdmit ? "admitted" : "waiting",
        role: invitedRole || "participant",
      });
      // The waiting entry is written either way and is NOT skipped here. It is
      // what `guest-token` reconciles against on redemption, and it is how a
      // moderator sees who arrived — the row clears itself the moment they
      // redeem, which for an auto-admitted guest is their next poll.
      if (invitedRole) {
        await recordAutoAdmit(ctx, meetingId, participantId, { via: "cohost_invite" });
      } else if (autoAdmit) {
        await recordAutoAdmit(ctx, meetingId, participantId, { via: "join_request" });
      }

      // Both fields are non-nullable in the client.
      return sendJson(res, 200, { guestToken: token, participantId: participantId });
    },

    // ── 3. POST /rooms/:meetingId/guest-token — polled until admitted ──
    async guestToken(req, res, params) {
      const rawForRate = authMod.bearerOf(req.headers || {});
      const pollKey = crypto.createHash("sha256").update(rawForRate || rateLimitMod.clientAddress(req)).digest("hex");
      if (!enforceRate(req, res, "guest-token:" + pollKey, 90)) return;
      const ctx = await context(req);
      const raw = rawForRate;
      const payload = authMod.verifyGuestToken(d.guestSecret, raw);

      // A token minted for another tenant must not resolve here even if its
      // signature is valid — the secret may be shared across a deployment.
      if (payload.t !== ctx.tenant.tenantKey) {
        return sendError(res, 403, "Token is not valid for this tenant");
      }
      const session = await ctx.store.getGuest(raw);
      if (!session) return sendError(res, 401, "Guest session expired");
      if (session.meetingId !== payload.m) return sendError(res, 401, "Guest session mismatch");

      if (session.decision === "declined") return sendError(res, 403, ERROR_CODES.DECLINED, ERR_DECLINED);
      if (session.decision !== "admitted") return sendError(res, 403, ERROR_CODES.NOT_ADMITTED, ERR_NOT_ADMITTED);

      const room = await ctx.store.getRoom(session.meetingId);
      if (!room) return sendError(res, 404, "Meeting not found");
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");

      const waiting = await ctx.store.listWaiting(session.meetingId);
      const waitingEntry = waiting[session.participantId];
      const intent = waitingEntry
        ? null
        : await ctx.store.getParticipantIntent(session.meetingId, session.participantId);
      const entry = waitingEntry || (intent && {
        name: intent.displayName,
        email: intent.email,
        avatarUrl: intent.avatarUrl,
      });
      const displayName = (entry && entry.name) || "Guest";

      const role = session.role === "cohost" ? "cohost" : "participant";
      await ctx.store.putParticipantIntent(session.meetingId, session.participantId, {
        displayName: displayName,
        email: (entry && entry.email) || "",
        avatarUrl: (entry && entry.avatarUrl) || "",
        role: role,
      });
      await ctx.store.removeWaiting(session.meetingId, session.participantId);
      await ctx.store.markActive(session.meetingId);
      const media = await mintToken(
        ctx,
        session.meetingId,
        session.participantId,
        displayName,
        role,
        (entry && entry.avatarUrl) || ""
      );
      // serverUrl is server-supplied: this is what makes the media host dynamic.
      return sendJson(res, 200, {
        token: media.token,
        serverUrl: media.serverUrl,
        participantId: session.participantId,
        expiresIn: media.expiresIn || 300,
        policy: media.policy,
        mediaLocks: media.mediaLocks,
        reviewToken: features.meetingReviews && d.capture
          ? await d.capture.reviewToken(ctx.tenant, session.meetingId, session.participantId)
          : null,
      });
    },

    // ── 4. POST /rooms/:meetingId/cancel-join ──
    async cancelJoin(req, res) {
      const ctx = await context(req);
      const raw = authMod.bearerOf(req.headers || {});
      const payload = authMod.verifyGuestToken(d.guestSecret, raw);
      if (payload.t !== ctx.tenant.tenantKey) return sendError(res, 403, "Token is not valid for this tenant");
      const session = await ctx.store.getGuest(raw);
      if (session) {
        await ctx.store.removeWaiting(session.meetingId, session.participantId);
        await ctx.store.deleteGuest(raw);
      }
      return sendJson(res, 200, {});
    },

    // ── 5. POST /rooms — host creates a meeting ──
    async createRoom(req, res) {
      const ctx = await context(req);
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      const body = req.body || {};
      const requestedMeetType = String(body.meetType || "instant").toLowerCase();
      // Two values, and they are not the same value.
      //
      // The live room keeps what the client asked for, because a lobby that
      // must know a webinar from a schedule has nowhere else to read it. The
      // durable row keeps the narrowed one, because `meet_meetings` carries
      // `CHECK (meet_type IN ('instant','scheduled'))` and widening a CHECK is
      // a migration this feature does not need — webinar-ness lives in
      // `settings.lobby.kind`, beside the data it describes.
      const meetType = SCHEDULED_MEET_TYPES.indexOf(requestedMeetType) !== -1
        ? requestedMeetType
        : "instant";
      const durableMeetType = meetType === "instant" ? "instant" : "scheduled";
      const title = body.title ? String(body.title).trim() : "";
      const externalReference = body.externalReference ? String(body.externalReference).trim() : "";
      if (title.length > 200) return sendError(res, 400, ERROR_CODES.VALIDATION, "title must be at most 200 characters");
      if (!validExternalReference(externalReference)) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "externalReference contains unsupported characters");
      }
      // A policy chosen in the lobby, before anybody can join. Validated here so
      // a typo fails the create rather than starting a meeting whose privacy
      // settings quietly differ from the ones the host ticked.
      let policyPatch = null;
      if (body.policy !== undefined && body.policy !== null) {
        requirePrivacyFeature();
        const parsed = parsePolicyPatch(body.policy);
        if (parsed.error) return sendError(res, 400, ERROR_CODES.VALIDATION, parsed.error);
        policyPatch = parsed.patch;
      }

      // The pre-join presentation. Refused by name rather than trimmed to fit:
      // see parseLobby.
      const parsedLobby = parseLobby(body.lobby);
      if (parsedLobby.error) return sendError(res, 400, ERROR_CODES.VALIDATION, parsedLobby.error);
      const lobby = parsedLobby.lobby;

      const proposedMeetingId = crypto.randomUUID ? crypto.randomUUID() : crypto.randomBytes(16).toString("hex");
      let meetingId = await ctx.store.reserveExternalReference(externalReference, proposedMeetingId);

      // The durable row is what a meeting *is*; Redis is a twelve-hour cache
      // of it. `meet_meetings` carries a UNIQUE index on
      // (domain_uuid, external_reference), so at most one row exists per
      // booking, schedule or webinar — and its id and slug are permanent.
      //
      // Reading it before minting anything is what makes a link handed out
      // weeks in advance keep working: the room's TTL expires long before the
      // meeting, and without this the control plane would mint a brand-new
      // slug and kill every invitation already sent.
      //
      // Fail-soft throughout: no recorder, no row, or a failed query all mean
      // "mint fresh", which is exactly the behaviour that existed before this
      // lookup did.
      let durable = null;
      if (externalReference && d.recorder && d.recorder.findMeetingByExternalReference) {
        try {
          durable = await d.recorder.findMeetingByExternalReference(ctx.tenant, externalReference);
        } catch (err) {
          log.warn("meet: durable meeting lookup failed: " + (err && err.message));
        }
      }

      // The slug of a room being reopened, so the link already handed out
      // keeps working. Null for an ordinary first start.
      let inheritedSlug = null;
      if (meetingId !== proposedMeetingId) {
        const existing = await ctx.store.getRoom(meetingId);
        if (!existing) return sendError(res, 409, ERROR_CODES.VALIDATION, "Meeting creation is already in progress");
        if (TERMINAL_ROOM_STATUS.indexOf(existing.status) === -1) {
          // Still usable — a retry from a booking integration, a second press
          // of Start meet, or Copy link asking for the same room. One meeting.
          //
          // The policy is applied here as well as on the fresh path. Copying a
          // link deliberately *creates* the room in advance, so by the time a
          // host presses Start meet the room usually already exists — which
          // made this the common case, not the edge case. Returning early
          // without applying it meant a host could tick "lock microphones",
          // watch the meeting open unlocked, and be told nothing.
          //
          // Only when a policy was actually supplied. That is what stops this
          // fighting the host: Copy link and booking integrations that send
          // none can never overwrite a change made during the meeting.
          const reusedPolicy = policyPatch
            ? await applyPolicyToExistingRoom(ctx, meetingId, policyPatch, host)
            : null;
          // And the lobby, for the same reason the policy is applied here.
          // Copy link creates the room in advance, so by the time anything
          // else happens this *is* the path — a webinar whose presentation was
          // only written on the fresh branch would never have one at all.
          const reusedLobby = lobby
            ? await persistLobby(ctx, meetingId, lobby, existing)
            : publicLobby(existing.lobby, existing.lobbyBanner, existing.lobbyPhoto);
          return sendJson(res, 200, {
            meetingId: meetingId,
            ownerKey: existing.ownerKey,
            joinUrl: "https://" + ctx.tenant.subdomain + "/meet/m/" + existing.slug,
            reused: true,
            policy: reusedPolicy,
            meetType: existing.meetType || "instant",
            lobby: reusedLobby || undefined,
          });
        }
        // The reference points at a corpse.
        //
        // endRoom deliberately keeps the room hash and the external-reference
        // index for their 12h TTL so a late join receives a structured
        // room_ended instead of a misleading 404 — and that TTL is refreshed
        // as the room ends, so without this branch a meeting that finished at
        // 10:00 could not be restarted from the calendar until about 22:00.
        //
        // The meeting keeps its identity. An earlier version minted a fresh
        // meetingId here, reasoning that `ended_at = COALESCE(ended_at, now())`
        // made reuse dishonest — but `meet_meetings` is UNIQUE on both
        // (domain_uuid, slug) and (domain_uuid, external_reference), so
        // `meetingCreated`'s ON CONFLICT DO NOTHING wrote **no row at all**,
        // and the nineteen foreign keys referencing meet_meetings(id) then
        // rejected that session's chat, whiteboard, recording and transcript
        // writes — silently, because recorder._run swallows every error. The
        // end markers are cleared on the existing row instead.
        inheritedSlug = existing.slug;
      }

      // Redis knew nothing, but the meeting exists durably: past the room's
      // TTL this is the ordinary case for anything scheduled more than half a
      // day out.
      if (inheritedSlug === null && durable && durable.slug) {
        inheritedSlug = durable.slug;
      }
      if (durable && durable.meetingId) {
        meetingId = durable.meetingId;
      }

      const reopened = inheritedSlug !== null;
      const slug = inheritedSlug || newMeetingCode();
      await ctx.store.createRoom({
        meetingId: meetingId,
        slug: slug,
        ownerKey: host.ownerKey,
        title: title,
        externalReference: externalReference,
        meetType: meetType,
        status: "created",
      });
      let policy = null;
      if (policyPatch && Object.keys(policyPatch).length > 0) {
        // Before the room can be joined, so the very first participant is already
        // governed by it — there is no window in which the meeting is unlocked.
        const applied = await ctx.store.setPolicy(meetingId, policyPatch, {
          actorId: "host-" + host.ownerKey,
          actorName: host.displayName,
        });
        policy = applied.policy;
      }
      if (d.recorder && reopened && d.recorder.meetingReopened) {
        // The row already exists — an INSERT would be a no-op and leave
        // status='ended' with the previous ended_at describing a meeting that
        // is happening right now.
        Promise.resolve(
          d.recorder.meetingReopened(ctx.tenant, {
            meetingId: meetingId,
            ownerKey: host.ownerKey,
            title: title,
          })
        ).catch(function (err) {
          log.warn("meet: durable meetingReopened failed: " + err.message);
        });
      } else if (d.recorder) {
        // Durable audit row; a failure must not block the meeting.
        Promise.resolve(
          d.recorder.meetingCreated(ctx.tenant, {
            meetingId: meetingId,
            slug: slug,
            ownerKey: host.ownerKey,
            title: title,
            externalReference: externalReference,
            meetType: durableMeetType,
            policy: policy,
            lobby: lobby,
          })
        ).catch(function (err) {
          log.warn("meet: durable meetingCreated failed: " + err.message);
        });
      }

      // Durably, and after the row exists: `meetingCreated` is
      // ON CONFLICT DO NOTHING, so on a reopen it writes nothing at all and
      // the lobby would be lost for exactly the meetings that have one.
      //
      // This is also what restores the banner's metadata after the room hash
      // has expired — the bytes outlive Redis on the durable row, and the
      // client uses the sha it returns to decide it need not upload again.
      //
      // And when the client sends none, the durable row is read back instead.
      // Without that, a host starting a webinar from anything that does not
      // carry the metadata — an older client, or any surface that only knows
      // the reference — would recreate the room hash with no lobby in it and
      // silently strip the branding from a meeting that has one.
      const createdLobby = lobby
        ? await persistLobby(ctx, meetingId, lobby, null)
        : (reopened || durable ? await restoreLobbyToRoom(ctx, meetingId) : null);

      // joinUrl is built from the tenant's own host — never a hardcoded domain.
      const joinUrl = "https://" + ctx.tenant.subdomain + "/meet/m/" + slug;
      return sendJson(res, 200, {
        meetingId: meetingId,
        ownerKey: host.ownerKey,
        joinUrl: joinUrl,
        reused: false,
        meetType: meetType,
        lobby: createdLobby || undefined,
        // Additive, and the only way to tell "a new room wearing the old
        // link" from a first start in a log. No client reads it.
        reopened: reopened,
        policy: policy,
      });
    },

    // ── 6. POST /rooms/:meetingId/token — host media token ──
    async hostToken(req, res, params) {
      const ctx = await context(req);
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      if (!meetingId) return sendError(res, 404, "Meeting not found");
      const room = await ctx.store.getRoom(meetingId);
      if (!room) return sendError(res, 404, "Meeting not found");
      if (room.ownerKey && room.ownerKey !== host.ownerKey) {
        return sendError(res, 403, "Not the host of this meeting");
      }
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");

      const participantId = "host-" + host.ownerKey;
      await ctx.store.putParticipantIntent(meetingId, participantId, {
        displayName: host.displayName,
        email: host.email,
        avatarUrl: host.avatarUrl,
        role: "host",
      });
      await ctx.store.markActive(meetingId);

      const media = await mintToken(ctx, meetingId, participantId, host.displayName, "host", host.avatarUrl);
      return sendJson(res, 200, {
        token: media.token,
        serverUrl: media.serverUrl,
        participantId: participantId,
        expiresIn: media.expiresIn || 300,
        policy: media.policy,
        mediaLocks: media.mediaLocks,
      });
    },

    // ── 6a. GET /rooms/active — current host's confirmed live rooms ──
    async activeMeetings(req, res) {
      const ctx = await context(req);
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      const rooms = await ctx.store.listActiveMeetingsForOwner(host.ownerKey, 20);
      const serverTime = new Date().toISOString();
      return sendJson(res, 200, {
        meetings: rooms.map(function (room) {
          return {
            meetingId: room.meetingId,
            title: room.title || "",
            joinUrl: "https://" + ctx.tenant.subdomain + "/meet/m/" + room.slug,
            status: room.status,
            participantCount: Number(room.participantCount || 0),
            hostPresent: room.hostPresent === true,
            startedAt: room.startedAt ? new Date(Number(room.startedAt)).toISOString() : null,
            serverTime: serverTime,
          };
        }),
      });
    },

    // ── 7. GET /rooms/:meetingId/waiting — host or co-host polls every 4s ──
    async waitingList(req, res, params) {
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      // A co-host shares the host's view of the waiting room. The same live
      // role check as every other moderation call, so a demotion stops the
      // next poll rather than the next token refresh.
      //
      // Authorize BEFORE confirming the meeting exists. Resolving first would
      // let an unauthenticated caller probe meeting ids and slugs through a
      // moderator endpoint, one 404-versus-401 at a time.
      await requireModerator(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, "Meeting not found");
      // MUST be an object keyed by participantId, not an array.
      return sendJson(res, 200, { waiting: await ctx.store.listWaiting(meetingId) });
    },

    // ── 8. POST /rooms/:meetingId/admit ──
    async admit(req, res, params) {
      const ctx = await context(req);
      const body = req.body || {};
      const participantId = String(body.participantId || "");
      const decision = String(body.decision || "");
      if (!participantId) return sendError(res, 400, "participantId is required");
      if (decision !== "admitted" && decision !== "declined") {
        return sendError(res, 400, "decision must be 'admitted' or 'declined'");
      }
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      // Authorized before the meeting is confirmed to exist, for the same
      // reason as the waiting list above.
      const moderator = await requireModerator(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, "Meeting not found");

      // Already handled by the other moderator, or gone. Two moderators sharing
      // one waiting room will run this race constantly, so it is a
      // reconciliation signal rather than a failure: `already` lets the client
      // settle its row on the decision that actually stuck, without an error and
      // without overturning it.
      const waiting = await ctx.store.listWaiting(meetingId);
      const existing = await ctx.store.getGuestByParticipant(meetingId, participantId);
      if (!waiting[participantId] || !existing) {
        return sendJson(res, 200, { result: true, already: true, decision: (existing && existing.decision) || null });
      }
      if (existing.decision !== "waiting") {
        return sendJson(res, 200, { result: true, already: true, decision: existing.decision });
      }

      const updated = await ctx.store.setGuestDecisionByParticipant(meetingId, participantId, decision);
      if (!updated) return sendError(res, 404, "Guest session expired");
      if (decision === "declined") await ctx.store.removeWaiting(meetingId, participantId);
      if (d.recorder && d.recorder.moderationEvent) {
        // The actor is whoever actually decided, not the room owner. A co-host
        // admission recorded against the host would make the audit trail lie.
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: moderator.actorKey,
          action: decision === "admitted" ? "admit" : "decline",
          targetRef: participantId,
          details: { actorRole: moderator.role },
        });
      }
      return sendJson(res, 200, { result: true, already: false, decision: decision });
    },

    // ── 9. POST /rooms/:meetingId/kick — must take effect on the SFU ──
    async kick(req, res, params) {
      const ctx = await context(req);
      const body = req.body || {};
      const participantId = String(body.participantId || "");
      if (!participantId) return sendError(res, 400, "participantId is required");
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      if (!meetingId) return sendError(res, 404, "Meeting not found");
      const room = await ctx.store.getRoom(meetingId);
      if (!room) return sendError(res, 404, "Meeting not found");
      const moderator = await requireModerator(req, ctx, meetingId, room);
      const target = await ctx.store.getParticipant(meetingId, participantId);
      const ownerParticipantId = "host-" + room.ownerKey;
      if (participantId === ownerParticipantId || (target && target.role === "host")) {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "The meeting host cannot be removed");
      }
      if (
        moderator.role === "cohost" &&
        (!target || target.role !== "participant")
      ) {
        return sendError(
          res,
          403,
          ERROR_CODES.FORBIDDEN,
          "Co-hosts can remove standard participants only"
        );
      }

      try {
        await d.gateway.removeParticipant(ctx.tenant, meetingId, participantId);
      } catch (err) {
        // A participant the SFU does not have is already in the state the host
        // asked for, so fall through and clear the local registry. Treating it
        // as a failure returned before the cleanup below, so a roster entry
        // that had outlived its media session could never be removed at all.
        if (err.statusCode !== 404) {
          // Any other failure is surfaced: a UI-only kick that leaves them
          // publishing is worse than an honest failure.
          log.error("meet: kick failed on the SFU: " + err.message);
          return sendError(res, 502, "Could not remove the participant from the media server");
        }
        log.warn("meet: kick target was already absent from the SFU; clearing local state");
      }
      await ctx.store.removeParticipant(meetingId, participantId);
      await ctx.store.removeWaiting(meetingId, participantId);
      await ctx.store.removeParticipantIntent(meetingId, participantId);
      // Otherwise a removed participant stays at the head of the hand queue with
      // nobody left to lower it.
      if (await ctx.store.lowerHand(meetingId, participantId)) {
        await broadcastInteraction(ctx.tenant, meetingId, {
          kind: "hand",
          participantId: participantId,
          raised: false,
          actorId: moderator.actorKey,
          actorRole: moderator.role,
        });
      }
      if (d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: moderator.actorKey,
          action: "kick",
          targetRef: participantId,
        });
      }
      return sendJson(res, 200, { result: true });
    },

    // ── 9a. POST /rooms/:meetingId/role — owner-only promotion/demotion ──
    async setParticipantRole(req, res, params) {
      const ctx = await context(req);
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      const body = req.body || {};
      const participantId = String(body.participantId || "");
      const role = String(body.role || "");
      if (!participantId) return sendError(res, 400, "participantId is required");
      if (role !== "cohost" && role !== "participant") {
        return sendError(res, 400, "role must be 'cohost' or 'participant'");
      }
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      if (!meetingId) return sendError(res, 404, "Meeting not found");
      const room = await ctx.store.getRoom(meetingId);
      if (!room) return sendError(res, 404, "Meeting not found");
      if (room.ownerKey !== host.ownerKey) {
        return sendError(res, 403, "Not the host of this meeting");
      }
      if (participantId === "host-" + room.ownerKey) {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "The meeting host role cannot be changed");
      }
      const target = await ctx.store.getParticipant(meetingId, participantId);
      if (!target) return sendError(res, 404, "Participant not found");
      if (target.role === "host") {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "The meeting host role cannot be changed");
      }
      if (target.role === role) return sendJson(res, 200, { result: true, role: role });

      // The photo is re-stated with the role because the SFU call below replaces
      // the participant's whole metadata block. Omitting it here does not leave
      // the old value in place — it erases it, so everyone's photo vanishes the
      // first time anybody is promoted or demoted. Same class of trap as the
      // permissions block, which is likewise atomic.
      //
      // It is masked against the *new* role, so this one call both restores the
      // photo of a participant promoted out of an aliased room and takes it away
      // again on demotion back into one — the mirror of what `applyRoleToSfu`
      // does for the name.
      let roleAvatar = "";
      try {
        const rolePolicy = await ctx.store.getPolicy(meetingId);
        roleAvatar = publishedAvatarFor(target.avatarUrl || "", role, rolePolicy);
      } catch (err) {
        log.warn("meet: could not resolve the photo for a role change: " + err.message);
      }

      try {
        await d.gateway.updateParticipantRole(ctx.tenant, meetingId, participantId, role, roleAvatar);
      } catch (err) {
        if (err.statusCode === 404) {
          return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Participant is no longer in the meeting");
        }
        log.error("meet: role update failed on the SFU: " + err.message);
        return sendError(res, 502, "Could not update the participant role on the media server");
      }
      await ctx.store.setParticipantRole(meetingId, participantId, role);
      await ctx.store.setGuestRoleByParticipant(meetingId, participantId, role);
      // The media policy applies by role, so the grant has to move with it.
      // After the store, because this reads the committed role back through
      // `policyViewFor`; before the response, so a host who promotes somebody
      // and watches their tile does not see a co-host still barred from
      // unmuting.
      await applyRoleToSfu(ctx, meetingId, participantId, role, target.displayName);
      if (d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: host.ownerKey,
          action: role === "cohost" ? "promote" : "demote",
          targetRef: participantId,
          details: { role: role },
        });
      }
      return sendJson(res, 200, { result: true, role: role });
    },

    // ── 9b. GET /rooms/:meetingId/interactions — reconnect state ──
    //
    // Hands are persistent, so a client that reconnects (or joins late) must be
    // able to recover the queue. Reactions are transient and deliberately absent:
    // replaying a four-second animation from ten minutes ago is noise.
    async interactionState(req, res, params) {
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, "Meeting not found");
      // The queue is read by everybody in the room, so it is masked like chat:
      // a hand raised by a hidden participant must not be the one place their
      // name is still printed.
      const handMask = await participantNameMask(ctx, meetingId);
      return sendJson(res, 200, {
        hands: (await ctx.store.listHands(meetingId)).map(function (hand) {
          return {
            participantId: hand.participantId,
            displayName: handMask ? handMask(hand.participantId, hand.displayName) : hand.displayName,
            raisedAt: new Date(Number(hand.raisedAt)).toISOString(),
            sequence: Number(hand.sequence || 0),
          };
        }),
        serverTime: new Date().toISOString(),
      });
    },

    // ── 9c. POST /rooms/:meetingId/hand ──
    async setHand(req, res, params) {
      const ctx = await context(req);
      const body = req.body || {};
      const raised = body.raised === true;
      const requestedTarget = String(body.participantId || "");
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const actor = await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, "Meeting not found");
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");

      const target = requestedTarget || actor.participantId;
      const actingOnSelf = target === actor.participantId;
      // Anyone may put their own hand down. Only a moderator may put someone
      // else's down, and nobody may raise a hand on another person's behalf —
      // that would let one participant fake another asking to speak.
      if (!actingOnSelf) {
        if (raised) {
          return sendError(res, 403, ERROR_CODES.FORBIDDEN, "A hand can only be raised by its owner");
        }
        if (!actor.isModerator) {
          return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Only a host or co-host can lower another hand");
        }
      }

      // Decide whether this is a real transition BEFORE charging the limit. The
      // budget is one transition per second; a client retrying a request whose
      // outcome already holds has not transitioned anything, and spending its
      // budget on the retry would make a dropped response impossible to recover
      // from for a whole second.
      const existing = await ctx.store.getHand(meetingId, target);
      const wouldChange = raised ? !existing : !!existing;
      if (!wouldChange) {
        return sendJson(res, 200, { result: true, raised: raised, changed: false });
      }
      if (!enforceParticipantRate(req, res, handLimiter, "hand:" + meetingId + ":" + actor.participantId, 1)) {
        return;
      }

      let changed;
      let hand = null;
      if (raised) {
        let displayName = actor.displayName;
        const known = await ctx.store.getParticipant(meetingId, target);
        if (known && known.displayName) displayName = known.displayName;
        const result = await ctx.store.raiseHand(meetingId, target, { displayName: displayName });
        changed = result.changed;
        hand = result.hand;
      } else {
        changed = await ctx.store.lowerHand(meetingId, target);
      }

      if (changed) {
        const handMask = await participantNameMask(ctx, meetingId);
        const broadcastName = hand && hand.displayName
          ? (handMask ? handMask(target, hand.displayName) : hand.displayName)
          : undefined;
        await broadcastInteraction(ctx.tenant, meetingId, {
          kind: "hand",
          participantId: target,
          displayName: broadcastName,
          raised: raised,
          raisedAt: hand ? new Date(Number(hand.raisedAt)).toISOString() : null,
          sequence: hand ? Number(hand.sequence) : null,
          actorId: actor.participantId,
          actorRole: actor.role,
        });
        if (!raised && !actingOnSelf && d.recorder && d.recorder.moderationEvent) {
          await d.recorder.moderationEvent(ctx.tenant, {
            meetingId: meetingId,
            actorKey: actor.participantId,
            action: "lower_hand",
            targetRef: target,
            details: { actorRole: actor.role },
          });
        }
      }
      // `changed:false` is a success, not a conflict: the caller's intent already
      // holds. It is reported so a client can skip re-announcing it.
      return sendJson(res, 200, { result: true, raised: raised, changed: changed });
    },

    // ── 9d. POST /rooms/:meetingId/reaction ──
    async sendReaction(req, res, params) {
      const ctx = await context(req);
      const body = req.body || {};
      const reaction = String(body.reaction || "");
      if (REACTIONS.indexOf(reaction) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "reaction is not one of the supported values");
      }
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const actor = await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, "Meeting not found");
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");

      if (
        !enforceParticipantRate(
          req,
          res,
          reactionLimiter,
          "reaction:" + meetingId + ":" + actor.participantId,
          REACTION_BURST
        )
      ) {
        return;
      }

      // Never stored. A reaction is an event, and the only durable trace it
      // leaves is whatever the client drew for four seconds.
      // Nothing renders this name today, but it is on the wire in a room-wide
      // packet, and a payload nobody reads is still a payload anybody can read.
      const reactionPolicy = await ctx.store.getPolicy(meetingId);
      await broadcastInteraction(ctx.tenant, meetingId, {
        kind: "reaction",
        participantId: actor.participantId,
        displayName: await publishedNameFor(
          ctx, meetingId, actor.participantId, actor.displayName, actor.role, reactionPolicy
        ),
        reaction: reaction,
        sentAt: new Date().toISOString(),
      });
      return sendJson(res, 200, { result: true, reaction: reaction });
    },

    // ── 9e. moderator mute + consent-gated participant enable ──
    async muteParticipantMedia(req, res, params) {
      if (!features.participantMediaControls) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      const source = String((req.body || {}).source || "");
      if (["microphone", "camera"].indexOf(source) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "source must be microphone or camera");
      }
      const ctx = await context(req);
      const access = await requireMediaTarget(req, ctx, params.meetingId, String(params.participantId || ""));
      const outcome = await d.gateway.muteParticipantTrack(
        ctx.tenant,
        access.meetingId,
        String(params.participantId),
        source
      );
      if (outcome.changed !== false) {
        await broadcastTargeted(ctx.tenant, access.meetingId, MEDIA_REQUEST_TOPIC, {
          kind: "muted",
          source: source,
          participantId: String(params.participantId),
          requesterId: access.moderator.participantId,
          requesterName: access.moderator.displayName,
          occurredAt: new Date().toISOString(),
        }, [String(params.participantId)]);
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: access.meetingId,
          actorKey: access.moderator.actorKey,
          action: "participant_media_mute",
          targetRef: String(params.participantId),
          details: { actorRole: access.moderator.role, source: source },
        });
      }
      return sendJson(res, 200, Object.assign({ result: true }, outcome));
    },

    async requestParticipantMedia(req, res, params) {
      if (!features.participantMediaControls) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      const source = String((req.body || {}).source || "");
      if (["microphone", "camera"].indexOf(source) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "source must be microphone or camera");
      }
      const ctx = await context(req);
      const participantId = String(params.participantId || "");
      const access = await requireMediaTarget(req, ctx, params.meetingId, participantId);
      const outcome = await ctx.store.createMediaEnableRequest(access.meetingId, {
        targetId: participantId,
        targetName: access.target.displayName,
        requesterId: access.moderator.participantId,
        requesterName: access.moderator.displayName,
        requesterRole: access.moderator.role,
        source: source,
      });
      if (outcome.cooldownUntil) {
        res.setHeader("Retry-After", String(Math.max(1, Math.ceil((outcome.cooldownUntil - Date.now()) / 1000))));
        return sendError(res, 429, ERROR_CODES.RATE_LIMITED, "Wait before asking this participant again");
      }
      if (outcome.conflict) return sendError(res, 409, "request_pending", "A request is already pending");
      const request = outcome.request;
      if (!outcome.duplicate) {
        await broadcastTargeted(ctx.tenant, access.meetingId, MEDIA_REQUEST_TOPIC, {
          kind: "enableRequest",
          request: request,
        }, [participantId]);
        scheduleMediaRequestExpiry(ctx, access.meetingId, request);
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: access.meetingId,
          actorKey: access.moderator.actorKey,
          action: "participant_media_request",
          targetRef: participantId,
          details: { actorRole: access.moderator.role, source: source, requestId: request.id },
        });
      }
      return sendJson(res, outcome.duplicate ? 200 : 201, {
        request: request,
        duplicate: !!outcome.duplicate,
      });
    },

    async pendingMediaRequests(req, res, params) {
      if (!features.participantMediaControls) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const actor = await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      return sendJson(res, 200, {
        requests: await ctx.store.listMediaEnableRequests(meetingId, actor.participantId),
      });
    },

    async respondMediaRequest(req, res, params) {
      if (!features.participantMediaControls) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      const decision = String((req.body || {}).decision || "");
      if (["accepted", "rejected", "failed"].indexOf(decision) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "decision must be accepted, rejected, or failed");
      }
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const actor = await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      const outcome = await ctx.store.respondMediaEnableRequest(
        meetingId,
        String(params.requestId || ""),
        actor.participantId,
        decision
      );
      if (!outcome) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Media request not found");
      const request = outcome.request;
      if (!outcome.duplicate) {
        const answerPolicy = await ctx.store.getPolicy(meetingId);
        await broadcastTargeted(ctx.tenant, meetingId, MEDIA_REQUEST_TOPIC, {
          kind: "enableResponse",
          requestId: request.id,
          participantId: actor.participantId,
          participantName: await publishedNameFor(
            ctx, meetingId, actor.participantId, actor.displayName, actor.role, answerPolicy
          ),
          source: request.source,
          decision: request.status,
          respondedAt: new Date(Number(request.respondedAt)).toISOString(),
        }, [request.requesterId]);
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: actor.participantId,
          action: "participant_media_response",
          targetRef: request.requesterId,
          details: { source: request.source, decision: request.status, requestId: request.id },
        });
      }
      return sendJson(res, 200, { request: request, duplicate: !!outcome.duplicate });
    },

    // ── 9g. host media policy, per-participant grants, access requests ──

    /**
     * The policy as it applies to the caller.
     *
     * Any participant may read it — a locked participant has to be able to see
     * that they are locked, and by what — but only their own grants are
     * disclosed. Who else has been exempted is moderation information.
     */
    async roomPolicy(req, res, params) {
      requirePrivacyFeature();
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const actor = await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      const policy = await ctx.store.getPolicy(meetingId);
      const grant = await ctx.store.getMediaGrant(meetingId, actor.participantId);
      return sendJson(res, 200, {
        policy: policy,
        grants: grant,
        mediaLocks: mediaLocksFor(policy, grant, actor.role),
        canManage: actor.role === "host" || (actor.role === "cohost" && policy.cohostsMayManageMedia === true),
        serverTime: new Date().toISOString(),
      });
    },

    async updateRoomPolicy(req, res, params) {
      requirePrivacyFeature();
      const body = req.body || {};
      const parsed = parsePolicyPatch(body.policy === undefined ? body : body.policy);
      if (parsed.error) return sendError(res, 400, ERROR_CODES.VALIDATION, parsed.error);
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      // Authorize before confirming the meeting exists, so this cannot be used
      // to probe which meeting ids are real.
      const moderator = await requirePolicyModerator(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");
      if (Object.keys(parsed.patch).length === 0) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "No policy fields were supplied");
      }

      const outcome = await ctx.store.setPolicy(meetingId, parsed.patch, {
        expectedVersion: body.expectedVersion,
        actorId: moderator.participantId,
        actorName: moderator.displayName,
      });
      if (outcome.conflict) {
        res.setHeader("X-Meet-Policy-Version", String(outcome.policy.version));
        return sendJson(res, 409, {
          code: "VERSION_CONFLICT",
          error: "The policy changed while you were editing it",
          policy: outcome.policy,
        });
      }
      const changed = outcome.changed || [];
      if (changed.length === 0) {
        // The caller's intent already holds. Reported rather than treated as a
        // conflict so a retried request is free.
        return sendJson(res, 200, { policy: outcome.policy, changed: [] });
      }

      const touchesSources = changed.some(function (key) {
        return key === "cameraLocked" || key === "microphoneLocked";
      });
      const touchesNames = changed.indexOf("hideParticipantNames") !== -1;
      const sweep = await applyPolicyToRoom(ctx, meetingId, outcome.policy, {
        sources: touchesSources,
        names: touchesNames,
      });
      // A newly locked source stops what is already live as well as preventing a
      // restart. Locking a camera that stays published would look like the
      // control did nothing.
      if (touchesSources) await muteNewlyLockedTracks(ctx, meetingId, outcome.policy);
      await broadcastPolicy(ctx, meetingId, outcome.policy, moderator);
      // Switching auto-admit ON also lets in whoever is already queued (see
      // `drainWaitingRoom`). Switching it off is not the mirror image: nobody
      // is un-admitted, because they are already in the meeting.
      let drained = null;
      if (changed.indexOf("autoAdmit") !== -1 && outcome.policy.autoAdmit === true) {
        drained = await drainWaitingRoom(ctx, meetingId, moderator);
      }
      if (d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: moderator.actorKey,
          action: "media_policy_update",
          targetRef: null,
          details: { actorRole: moderator.role, changed: changed, version: outcome.policy.version },
        });
      }
      if (d.recorder && d.recorder.meetingPolicy) {
        Promise.resolve(d.recorder.meetingPolicy(ctx.tenant, { meetingId: meetingId, policy: outcome.policy }))
          .catch(function (err) { log.warn("meet: durable policy mirror failed: " + err.message); });
      }
      return sendJson(res, 200, {
        policy: outcome.policy,
        changed: changed,
        applied: sweep,
        // Present only when the switch that causes it was part of this patch, so
        // an ordinary policy change answers byte for byte what it did before.
        autoAdmitted: drained || undefined,
      });
    },

    /**
     * The moderator roster: the one place a name can still be restored.
     *
     * This endpoint is why name privacy can be enforced at the SFU. Tiles show
     * whatever the SFU says, so a moderator who needs to identify somebody in
     * `participants` mode reads the real name from here instead.
     *
     * In `everyone` mode it is masked like every other surface, because that is
     * what the mode says and the panel used to be the one place it was not
     * true: a host who had hidden every name still read the whole room in the
     * participants list. The two modes now differ in exactly one thing --
     * whether a moderator is entitled to the names -- and switching back is a
     * policy change the audit trail records, which a quiet reveal button in the
     * panel would not be.
     */
    async participantRoster(req, res, params) {
      requirePrivacyFeature();
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const moderator = await requireModerator(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      const policy = await ctx.store.getPolicy(meetingId);
      const grants = await ctx.store.listMediaGrants(meetingId);
      const aliases = await ctx.store.listAliases(meetingId);
      const participants = await ctx.store.listParticipants(meetingId);
      // Only in `everyone` mode. Masking here in `participants` mode would take
      // the names away from the very viewer that mode exists to keep them for,
      // and this endpoint is what the tiles, the chat and the hand queue all
      // restore from.
      const hideFromModerator =
        features.hostPrivacyControls && policy.hideParticipantNames === "everyone";
      return sendJson(res, 200, {
        participants: participants.map(function (participant) {
          const role = participant.role === "host" || participant.role === "cohost" ? participant.role : "participant";
          const grant = grants[participant.participantId] || { microphone: false, camera: false };
          const realName = participant.displayName || "Guest";
          const alias = aliases[participant.participantId] || null;
          return {
            participantId: participant.participantId,
            displayName: hideFromModerator && role === "participant"
              ? (alias || "Participant")
              : realName,
            alias: alias,
            role: role,
            grants: grant,
            mediaLocks: mediaLocksFor(policy, grant, role),
          };
        }),
        policy: policy,
        viewerRole: moderator.role,
        serverTime: new Date().toISOString(),
      });
    },

    /**
     * Exempt (or re-lock) one participant on one source.
     *
     * A grant is permission, not an action: it lets somebody unmute themselves,
     * it never switches their microphone on. That is the consent line this
     * feature must not cross, and it is also what makes "muted" and "not allowed
     * to unmute" two different states in the UI.
     */
    async setParticipantMediaGrant(req, res, params) {
      requirePrivacyFeature();
      const body = req.body || {};
      const source = String(body.source || "");
      if (roomsMod.POLICY_SOURCES.indexOf(source) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "source must be microphone or camera");
      }
      if (typeof body.allowed !== "boolean") {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "allowed must be a boolean");
      }
      const ctx = await context(req);
      const participantId = String(params.participantId || "");
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const moderator = await requirePolicyModerator(req, ctx, meetingId, room);
      const access = await requireMediaTarget(req, ctx, params.meetingId, participantId);
      void moderator;
      const policy = await ctx.store.getPolicy(access.meetingId);
      const outcome = await ctx.store.setMediaGrant(access.meetingId, participantId, source, body.allowed);
      const grant = await ctx.store.getMediaGrant(access.meetingId, participantId);
      if (outcome.changed) {
        try {
          await d.gateway.updateParticipantPublishSources(
            ctx.tenant,
            access.meetingId,
            participantId,
            roomsMod.publishSourcesFor(policy, grant, access.target.role === "cohost" ? "cohost" : "participant")
          );
        } catch (err) {
          log.warn("meet: grant publish-source update failed: " + err.message);
        }
        // Re-locking must also stop what is already live, or the participant
        // keeps publishing until they choose to stop.
        if (!body.allowed && roomsMod.policyLocks(policy, source)) {
          try {
            await d.gateway.muteParticipantTrack(ctx.tenant, access.meetingId, participantId, source);
          } catch (err) {
            if (!/track_not_found|participant_not_found/.test(String(err && err.code))) {
              log.warn("meet: grant mute failed: " + err.message);
            }
          }
        }
        await broadcastTargeted(ctx.tenant, access.meetingId, POLICY_TOPIC, {
          kind: "grant",
          participantId: participantId,
          source: source,
          allowed: body.allowed,
          mediaLocks: mediaLocksFor(policy, grant, access.target.role === "cohost" ? "cohost" : "participant"),
          changedBy: { participantId: access.moderator.participantId, displayName: access.moderator.displayName },
          occurredAt: new Date().toISOString(),
        }, [participantId]);
        if (d.recorder && d.recorder.moderationEvent) {
          await d.recorder.moderationEvent(ctx.tenant, {
            meetingId: access.meetingId,
            actorKey: access.moderator.actorKey,
            action: "media_grant",
            targetRef: participantId,
            details: { actorRole: access.moderator.role, source: source, allowed: body.allowed },
          });
        }
      }
      return sendJson(res, 200, {
        result: true,
        changed: outcome.changed,
        grants: grant,
        mediaLocks: mediaLocksFor(policy, grant, access.target.role === "cohost" ? "cohost" : "participant"),
      });
    },

    /** A locked participant asks for a source. Never a moderator: see below. */
    async requestMediaAccess(req, res, params) {
      requirePrivacyFeature();
      const source = String((req.body || {}).source || "");
      if (roomsMod.POLICY_SOURCES.indexOf(source) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "source must be microphone or camera");
      }
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const actor = await requireParticipant(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      if (room.status === "ended") return sendError(res, 409, ERROR_CODES.ROOM_ENDED, "Meeting has ended");

      const policy = await ctx.store.getPolicy(meetingId);
      const grant = await ctx.store.getMediaGrant(meetingId, actor.participantId);
      if (!roomsMod.sourceLockedFor(policy, grant, actor.role, source)) {
        // Nothing to ask for. Answering 200 would leave the client waiting for a
        // decision that is never coming.
        return sendError(res, 409, "not_locked", "That device is not locked for you");
      }
      if (!roomsMod.policyAllowsRequest(policy, source)) {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "The host has turned off access requests");
      }
      if (!enforceParticipantRate(req, res, accessRequestLimiter, "access:" + meetingId + ":" + actor.participantId, 1)) {
        return;
      }

      const outcome = await ctx.store.createMediaAccessRequest(meetingId, {
        participantId: actor.participantId,
        participantName: actor.displayName,
        source: source,
      });
      if (outcome.cooldownUntil) {
        res.setHeader("Retry-After", String(Math.max(1, Math.ceil((outcome.cooldownUntil - Date.now()) / 1000))));
        return sendError(res, 429, ERROR_CODES.RATE_LIMITED, "Wait before asking again");
      }
      const request = outcome.request;
      // Room-wide, and "only a moderator has any UI for it" is not a reason to
      // put a hidden participant's real name in a packet every client receives.
      const requestMask = await participantNameMask(ctx, meetingId);
      const maskedRequest = maskAccessRequest(request, requestMask);
      if (!outcome.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, POLICY_TOPIC, {
          kind: "accessRequest",
          request: maskedRequest,
        });
        if (d.recorder && d.recorder.moderationEvent) {
          await d.recorder.moderationEvent(ctx.tenant, {
            meetingId: meetingId,
            actorKey: actor.participantId,
            action: "media_access_request",
            targetRef: null,
            details: { source: source, requestId: request.id },
          });
        }
      }
      return sendJson(res, outcome.duplicate ? 200 : 201, { request: maskedRequest, duplicate: !!outcome.duplicate });
    },

    async mediaAccessRequests(req, res, params) {
      requirePrivacyFeature();
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      await requirePolicyModerator(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      const requests = await ctx.store.listMediaAccessRequests(meetingId);
      const queueMask = await participantNameMask(ctx, meetingId);
      return sendJson(res, 200, {
        requests: queueMask
          ? requests.map(function (request) { return maskAccessRequest(request, queueMask); })
          : requests,
        serverTime: new Date().toISOString(),
      });
    },

    async respondMediaAccess(req, res, params) {
      requirePrivacyFeature();
      const decision = String((req.body || {}).decision || "");
      if (["approved", "denied"].indexOf(decision) === -1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "decision must be approved or denied");
      }
      const ctx = await context(req);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      const room = meetingId ? await ctx.store.getRoom(meetingId) : null;
      const moderator = await requirePolicyModerator(req, ctx, meetingId, room);
      if (!meetingId || !room) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Meeting not found");
      const pending = await ctx.store.getMediaAccessRequest(meetingId, String(params.requestId || ""));
      if (!pending) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Request not found");
      const target = await ctx.store.getParticipant(meetingId, pending.participantId);
      if (moderator.role === "cohost" && target && target.role !== "participant") {
        return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Co-hosts can answer standard participants only");
      }
      const outcome = await ctx.store.respondMediaAccessRequest(meetingId, pending.id, decision, {
        actorId: moderator.participantId,
        actorName: moderator.displayName,
      });
      if (!outcome) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Request not found");
      const request = outcome.request;
      const answeredMask = await participantNameMask(ctx, meetingId);
      if (outcome.duplicate) {
        return sendJson(res, 200, { request: maskAccessRequest(request, answeredMask), duplicate: true });
      }

      let grant = await ctx.store.getMediaGrant(meetingId, request.participantId);
      const policy = await ctx.store.getPolicy(meetingId);
      if (request.status === "approved") {
        await ctx.store.setMediaGrant(meetingId, request.participantId, request.source, true);
        grant = await ctx.store.getMediaGrant(meetingId, request.participantId);
        try {
          await d.gateway.updateParticipantPublishSources(
            ctx.tenant,
            meetingId,
            request.participantId,
            roomsMod.publishSourcesFor(policy, grant, "participant")
          );
        } catch (err) {
          log.warn("meet: approved-request publish update failed: " + err.message);
        }
      }
      await broadcastTargeted(ctx.tenant, meetingId, POLICY_TOPIC, {
        kind: "accessResponse",
        requestId: request.id,
        participantId: request.participantId,
        source: request.source,
        decision: request.status,
        mediaLocks: mediaLocksFor(policy, grant, "participant"),
        respondedBy: { participantId: moderator.participantId, displayName: moderator.displayName },
        occurredAt: new Date().toISOString(),
      }, [request.participantId]);
      if (d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: moderator.actorKey,
          action: "media_access_response",
          targetRef: request.participantId,
          details: { actorRole: moderator.role, source: request.source, decision: request.status },
        });
      }
      return sendJson(res, 200, {
        request: maskAccessRequest(request, answeredMask),
        duplicate: false,
        grants: grant,
      });
    },

    // ── 9f. persistent room chat ──
    async chatMessages(req, res, params) {
      requireFeature("chat");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const query = req.query || {};
      if (query.after && query.before) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "Use either after or before, not both");
      }
      const result = await d.collaboration.listMessages(ctx.tenant, meetingId, {
        after: parseCursor(query.after, "after"),
        before: parseCursor(query.before, "before"),
        limit: query.limit ? Number(query.limit) : 50,
      });
      const mask = await participantNameMask(ctx, meetingId);
      if (mask && Array.isArray(result.messages)) {
        result.messages = result.messages.map(function (message) {
          return maskChatMessage(message, mask);
        });
      }
      return sendJson(res, 200, Object.assign(result, { serverTime: new Date().toISOString() }));
    },

    async sendChatMessage(req, res, params) {
      requireFeature("chat");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      if (!(await allowChatWrite(ctx, res, meetingId, access))) return;
      const body = req.body || {};
      const clientMessageId = String(body.clientMessageId || "");
      if (!validUuid(clientMessageId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "clientMessageId must be a UUID");
      const attachmentIds = Array.isArray(body.attachmentIds) ? body.attachmentIds.map(String) : [];
      if (attachmentIds.length > 5 || new Set(attachmentIds).size !== attachmentIds.length ||
        attachmentIds.some(function (id) { return !validUuid(id); })) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "At most five unique attachment UUIDs are allowed");
      }
      if (attachmentIds.length) {
        if (!d.features.chatAttachments || !d.attachments || !(await d.attachments.health())) {
          return sendError(res, 503, "ATTACHMENT_SCANNER_UNAVAILABLE", "Chat attachments are unavailable");
        }
      }
      const textBody = validateChatBody(body.body, attachmentIds.length > 0);
      const replyToId = body.replyToId ? String(body.replyToId) : null;
      if (replyToId && !validUuid(replyToId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "replyToId must be a UUID");
      const outcome = await d.collaboration.createMessage(ctx.tenant, {
        meetingId: meetingId,
        participantId: access.actor.participantId,
        senderName: access.actor.displayName,
        clientMessageId: clientMessageId,
        body: textBody,
        replyToId: replyToId,
        attachmentIds: attachmentIds,
      });
      // Masked before it is broadcast, not per recipient: the packet is one
      // room-wide fan-out, so it carries the name the least entitled viewer may
      // see. A moderator puts the real one back from the roster endpoint,
      // exactly as they do for the video tiles.
      const chatMask = await participantNameMask(ctx, meetingId);
      const visible = maskChatMessage(outcome.message, chatMask);
      if (!outcome.duplicate) await broadcastCollaboration(ctx.tenant, meetingId, CHAT_TOPIC, {
        kind: "message",
        message: visible,
        cursor: visible.cursor,
      });
      return sendJson(res, outcome.duplicate ? 200 : 201, {
        message: visible,
        duplicate: outcome.duplicate,
      });
    },

    async editChatMessage(req, res, params) {
      requireFeature("chat");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const messageId = String(params.messageId || "");
      if (!validUuid(messageId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "messageId must be a UUID");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      // An edit is a write: without this, a restricted participant rewrites an
      // old message to say anything they like. Deleting your own message is
      // deliberately still allowed — removing your own words is never what a
      // chat restriction is protecting the room from.
      if (!(await allowChatWrite(ctx, res, meetingId, access))) return;
      const body = req.body || {};
      const expectedVersion = Number(body.expectedVersion);
      if (!Number.isInteger(expectedVersion) || expectedVersion < 1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "expectedVersion is required");
      }
      const message = await d.collaboration.editMessage(ctx.tenant, {
        meetingId: meetingId,
        messageId: messageId,
        participantId: access.actor.participantId,
        expectedVersion: expectedVersion,
        body: validateChatBody(body.body, false),
      });
      const edited = maskChatMessage(message, await participantNameMask(ctx, meetingId));
      await broadcastCollaboration(ctx.tenant, meetingId, CHAT_TOPIC, { kind: "message", message: edited, cursor: edited.cursor });
      return sendJson(res, 200, { message: edited });
    },

    async deleteChatMessage(req, res, params) {
      requireFeature("chat");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const messageId = String(params.messageId || "");
      if (!validUuid(messageId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "messageId must be a UUID");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const expectedVersion = Number((req.body || {}).expectedVersion);
      if (!Number.isInteger(expectedVersion) || expectedVersion < 1) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "expectedVersion is required");
      }
      const existing = await d.collaboration.getMessage(ctx.tenant, meetingId, messageId);
      if (!existing) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Message not found");
      const moderating = access.actor.isModerator && existing.participantId !== access.actor.participantId;
      const message = await d.collaboration.deleteMessage(ctx.tenant, {
        meetingId: meetingId, messageId: messageId, actorId: access.actor.participantId,
        expectedVersion: expectedVersion, isModerator: access.actor.isModerator,
      });
      if (moderating && d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId, actorKey: access.actor.participantId,
          action: "chat_delete", targetRef: messageId, details: { actorRole: access.actor.role },
        });
      }
      const tombstone = maskChatMessage(message, await participantNameMask(ctx, meetingId));
      await broadcastCollaboration(ctx.tenant, meetingId, CHAT_TOPIC, { kind: "message", message: tombstone, cursor: tombstone.cursor });
      return sendJson(res, 200, { message: tombstone });
    },

    async uploadChatAttachment(req, res, params) {
      requireFeature("chat");
      requireFeature("chatAttachments");
      if (!d.attachments || !(await d.attachments.health())) {
        throw new collaborationMod.CollaborationError("Attachment scanning is unavailable", "ATTACHMENT_SCANNER_UNAVAILABLE", 503);
      }
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      // Checked before the body is streamed and scanned, so a restricted
      // participant cannot spend the scanner on a file that can never be sent.
      if (!(await allowChatWrite(ctx, res, meetingId, access))) return;
      const attachment = await d.attachments.receive(req, ctx.tenant, meetingId, access.actor);
      return sendJson(res, 201, { attachment: attachment });
    },

    async cancelChatAttachment(req, res, params) {
      requireFeature("chatAttachments");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      await d.attachments.cancel(ctx.tenant, meetingId, String(params.attachmentId || ""), access.actor);
      return sendJson(res, 200, { result: true });
    },

    async chatAttachmentLink(req, res, params) {
      requireFeature("chat");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const attachmentId = String(params.attachmentId || "");
      if (!validUuid(attachmentId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "attachmentId must be a UUID");
      const attachment = await d.collaboration.getAttachment(ctx.tenant, meetingId, attachmentId);
      if (!attachment || attachment.status !== "attached" || attachment.deleted_at) {
        return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Attachment not found");
      }
      const requested = String((req.body || {}).disposition || "attachment");
      const disposition = requested === "inline" ? "inline" : "attachment";
      return sendJson(res, 200, {
        url: d.attachments.link(ctx.tenant, meetingId, attachmentId, disposition),
        expiresIn: 900,
      });
    },

    async signedAttachment(req, res, params) {
      requireFeature("chat");
      if (!d.attachments) throw new collaborationMod.CollaborationError("Attachments are unavailable", "FEATURE_DISABLED", 404);
      const ctx = await context(req);
      await d.attachments.serve(req, res, ctx.tenant, String(params.attachmentId || ""), (req.query || {}).token);
    },

    async prepareAttachmentPreview(req, res, params) {
      requireFeature("chatAttachmentPreview");
      if (!d.documents) throw new collaborationMod.CollaborationError("Document previews are unavailable", "FEATURE_DISABLED", 404);
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const asset = await d.documents.prepare(ctx.tenant, meetingId, String(params.attachmentId || ""), "preview");
      return sendJson(res, asset.status === "processing" || asset.status === "queued" ? 202 : 200, {
        asset: d.documents.manifest(ctx.tenant, meetingId, asset),
      });
    },

    async attachmentPreview(req, res, params) {
      requireFeature("chatAttachmentPreview");
      if (!d.documents) throw new collaborationMod.CollaborationError("Document previews are unavailable", "FEATURE_DISABLED", 404);
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const asset = await d.collaboration.getFileAssetByAttachment(
        ctx.tenant,
        meetingId,
        String(params.attachmentId || "")
      );
      if (!asset) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Preview not found");
      return sendJson(res, 200, { asset: d.documents.manifest(ctx.tenant, meetingId, asset) });
    },

    async signedPreviewPage(req, res, params) {
      if (!features.chatAttachmentPreview && !features.filePresentations) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      if (!d.documents) throw new collaborationMod.CollaborationError("Document previews are unavailable", "FEATURE_DISABLED", 404);
      const ctx = await context(req);
      await d.documents.servePage(
        req,
        res,
        ctx.tenant,
        String(params.attachmentId || ""),
        Number(params.pageNumber),
        (req.query || {}).token
      );
    },

    async uploadPresentationAsset(req, res, params) {
      requireFeature("filePresentations");
      if (!d.attachments || !d.documents || !(await d.attachments.health()) || !(await d.documents.health())) {
        throw new collaborationMod.CollaborationError("File presentation uploads are unavailable", "DOCUMENT_CONVERTER_UNAVAILABLE", 503);
      }
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const attachment = await d.attachments.receive(req, ctx.tenant, meetingId, access.actor);
      try {
        const asset = await d.documents.prepare(ctx.tenant, meetingId, attachment.id, "presentation");
        return sendJson(res, 202, {
          attachment: attachment,
          asset: d.documents.manifest(ctx.tenant, meetingId, asset),
        });
      } catch (err) {
        try { await d.attachments.cancel(ctx.tenant, meetingId, attachment.id, access.actor); } catch (_) {}
        throw err;
      }
    },

    async presentationAssetFromAttachment(req, res, params) {
      requireFeature("filePresentations");
      if (!d.documents) throw new collaborationMod.CollaborationError("File presentations are unavailable", "FEATURE_DISABLED", 404);
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const attachmentId = String((req.body || {}).attachmentId || "");
      if (!validUuid(attachmentId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "attachmentId must be a UUID");
      const asset = await d.documents.prepare(ctx.tenant, meetingId, attachmentId, "presentation");
      return sendJson(res, asset.status === "ready" ? 200 : 202, {
        asset: d.documents.manifest(ctx.tenant, meetingId, asset),
      });
    },

    async presentationAsset(req, res, params) {
      requireFeature("filePresentations");
      if (!d.documents) throw new collaborationMod.CollaborationError("File presentations are unavailable", "FEATURE_DISABLED", 404);
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const asset = await d.collaboration.getFileAsset(ctx.tenant, meetingId, String(params.assetId || ""));
      if (!asset) return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Presentation file not found");
      return sendJson(res, 200, { asset: d.documents.manifest(ctx.tenant, meetingId, asset) });
    },

    async presentationState(req, res, params) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const presentation = await d.collaboration.presentationState(ctx.tenant, meetingId);
      return sendJson(res, 200, {
        presentation: maskPresenter(presentation, await participantNameMask(ctx, meetingId)),
      });
    },

    async presentationAction(req, res, params, kind) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      const clientActionId = String(body.clientActionId || "");
      const expectedVersion = Number(body.expectedVersion);
      if (!validUuid(clientActionId) || !Number.isSafeInteger(expectedVersion) || expectedVersion < 0) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "clientActionId and expectedVersion are required");
      }
      const assetId = kind === "start" ? String(body.assetId || "") : null;
      if (kind === "start" && !validUuid(assetId)) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "assetId must be a UUID");
      }
      const annotationMode = kind === "mode" ? String(body.annotationMode || "") : null;
      if (kind === "mode" && annotationMode !== "presenter" && annotationMode !== "everyone") {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "annotationMode must be presenter or everyone");
      }
      const pageNumber = kind === "page" ? Number(body.pageNumber) : null;
      if (kind === "page" && (!Number.isInteger(pageNumber) || pageNumber < 1 || pageNumber > 200)) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "pageNumber is invalid");
      }
      const before = kind === "stop" ? await d.collaboration.presentationState(ctx.tenant, meetingId) : null;
      const result = await d.collaboration.applyPresentationAction(ctx.tenant, {
        meetingId: meetingId,
        kind: kind,
        actorId: access.actor.participantId,
        actorName: access.actor.displayName,
        isModerator: access.actor.isModerator,
        clientActionId: clientActionId,
        expectedVersion: expectedVersion,
        assetId: assetId,
        pageNumber: pageNumber,
        annotationMode: annotationMode,
      });
      const rawState = await d.collaboration.presentationState(ctx.tenant, meetingId);
      const state = maskPresenter(rawState, await participantNameMask(ctx, meetingId));
      if (!result.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, PRESENTATION_TOPIC, {
          kind: "state",
          presentation: state,
        });
        if (kind === "stop" && before && before.presenterId !== access.actor.participantId &&
            access.actor.isModerator && d.recorder && d.recorder.moderationEvent) {
          await d.recorder.moderationEvent(ctx.tenant, {
            meetingId: meetingId,
            actorKey: access.actor.participantId,
            action: "file_presentation_stop",
            targetRef: before.presenterId,
            details: { actorRole: access.actor.role },
          });
        }
      }
      return sendJson(res, 200, { presentation: state, duplicate: !!result.duplicate });
    },

    // ── 9f-bis. ink drawn on the presented page ──

    async presentationAnnotations(req, res, params) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const target = await resolveAnnotationTarget(ctx, meetingId, access, req.query || {}, null);
      const annotations = await d.collaboration.presentationAnnotations(
        ctx.tenant, meetingId, target.assetId, target.pageNumber);
      return sendJson(res, 200, { annotations: annotations });
    },

    async presentationAnnotationBatch(req, res, params) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      const clientBatchId = String(body.clientBatchId || "");
      if (!validUuid(clientBatchId)) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId must be a UUID");
      }
      const target = await resolveAnnotationTarget(ctx, meetingId, access, body, { draw: true });
      const destructive = validateWhiteboardOperations(body.operations, ANNOTATION_ELEMENT_TYPES);
      const participantScope = meetingId + ":" + access.actor.participantId;
      if (!enforceParticipantRate(req, res, whiteboardLimiter, "annotation:" + participantScope, 20)) return;
      if (!enforceParticipantRate(req, res, whiteboardByteLimiter, "annotation-bytes:" + participantScope,
        64 * 1024, Buffer.byteLength(JSON.stringify(body.operations), "utf8"))) return;
      const state = await d.collaboration.applyPresentationAnnotationBatch(ctx.tenant, {
        meetingId: meetingId,
        assetId: target.assetId,
        pageNumber: target.pageNumber,
        actorId: access.actor.participantId,
        clientBatchId: clientBatchId,
        operations: body.operations,
        destructive: destructive,
        expectedVersion: body.expectedVersion,
      });
      if (!state.duplicate) await broadcastAnnotations(ctx.tenant, meetingId, state);
      return sendJson(res, state.duplicate ? 200 : 201, { annotations: state });
    },

    async presentationAnnotationUndoRedo(req, res, params, kind) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      if (!validUuid(body.clientBatchId)) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId must be a UUID");
      }
      // `draw` and not `clear`: undo only ever reaches this actor's own
      // strokes, so a participant who may draw may take back what they drew
      // even after the presenter has closed the deck again.
      const target = await resolveAnnotationTarget(ctx, meetingId, access, body, { draw: true });
      const state = await d.collaboration.presentationAnnotationUndoRedo(ctx.tenant, {
        meetingId: meetingId,
        assetId: target.assetId,
        pageNumber: target.pageNumber,
        actorId: access.actor.participantId,
        kind: kind,
        clientBatchId: body.clientBatchId,
      });
      if (!state.duplicate) await broadcastAnnotations(ctx.tenant, meetingId, state);
      return sendJson(res, 200, { annotations: state });
    },

    async presentationAnnotationPresence(req, res, params) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      const phase = String(body.phase || "");
      const tool = String(body.tool || "");
      const point = body.point;
      if (!validUuid(String(body.gestureId || "")) || !["start", "move", "end"].includes(phase) ||
          !["pen", "highlighter"].includes(tool) || !Array.isArray(point) || point.length !== 2 ||
          !point.every(function (value) { return Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 1; })) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "Invalid presentation presence payload");
      }
      const bytes = Buffer.byteLength(JSON.stringify(body), "utf8");
      if (bytes > 1024) return sendError(res, 413, ERROR_CODES.VALIDATION, "Presentation presence payload is too large");
      // `draw`, so a trail can only be put on the page by somebody who could
      // put a mark there. A viewer who may not draw may not haunt the slide.
      const target = await resolveAnnotationTarget(ctx, meetingId, access, body, { draw: true });
      if (!enforceParticipantRate(req, res, whiteboardLimiter,
        "presentation-presence:" + meetingId + ":" + access.actor.participantId, 20)) return;
      // The trail is labelled with its owner, as it is on the board. One store
      // read on a 20/s path, early-returning to the real name when nothing is
      // hidden.
      const presencePolicy = await ctx.store.getPolicy(meetingId);
      await broadcastLossy(ctx.tenant, meetingId, PRESENTATION_PRESENCE_TOPIC, {
        assetId: target.assetId,
        pageNumber: target.pageNumber,
        gestureId: body.gestureId,
        phase: phase,
        tool: tool,
        point: [Number(point[0]), Number(point[1])],
        color: Number(body.color || 0xff2563eb),
        width: Math.max(1, Math.min(24, Number(body.width || 4))),
        participantId: access.actor.participantId,
        participantName: await publishedNameFor(
          ctx, meetingId, access.actor.participantId, access.actor.displayName,
          access.actor.role, presencePolicy
        ),
        participantRole: access.actor.role,
        sentAt: new Date().toISOString(),
      });
      return sendJson(res, 202, { result: true });
    },

    async presentationAnnotationClear(req, res, params) {
      requireFeature("filePresentations");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      if (!validUuid(body.clientBatchId)) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId must be a UUID");
      }
      const target = await resolveAnnotationTarget(ctx, meetingId, access, body, { clear: true });
      const state = await d.collaboration.clearPresentationAnnotations(ctx.tenant, {
        meetingId: meetingId,
        assetId: target.assetId,
        pageNumber: target.pageNumber,
        actorId: access.actor.participantId,
        clientBatchId: body.clientBatchId,
        expectedVersion: body.expectedVersion,
      });
      if (!state.duplicate) await broadcastAnnotations(ctx.tenant, meetingId, state);
      return sendJson(res, 200, { annotations: state });
    },

    // ── 9g. collaborative whiteboard ──
    async whiteboardState(req, res, params) {
      requireFeature("whiteboard");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCollaborationAccess(req, ctx, meetingId, { mutate: false });
      const state = await d.collaboration.whiteboardState(
        ctx.tenant,
        meetingId,
        parseCursor((req.query || {}).after, "after"),
        await initialWhiteboardLock(ctx, meetingId)
      );
      const boardMask = await participantNameMask(ctx, meetingId);
      if (!boardMask) return sendJson(res, 200, state);
      const masked = maskPresenter(state, boardMask);
      masked.batches = (state.batches || []).map(function (batch) {
        return maskPresenter(batch, boardMask);
      });
      return sendJson(res, 200, masked);
    },

    async whiteboardOperations(req, res, params) {
      requireFeature("whiteboard");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      const clientBatchId = String(body.clientBatchId || "");
      if (!validUuid(clientBatchId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId must be a UUID");
      const destructive = validateWhiteboardOperations(body.operations);
      const participantScope = meetingId + ":" + access.actor.participantId;
      if (!enforceParticipantRate(req, res, whiteboardLimiter, "whiteboard:" + participantScope, 20)) return;
      if (!enforceParticipantRate(req, res, whiteboardByteLimiter, "whiteboard-bytes:" + participantScope,
        64 * 1024, Buffer.byteLength(JSON.stringify(body.operations), "utf8"))) return;
      const batch = await d.collaboration.applyWhiteboardBatch(ctx.tenant, {
        meetingId: meetingId, actorId: access.actor.participantId, isModerator: access.actor.isModerator,
        clientBatchId: clientBatchId, operations: body.operations,
        destructive: destructive, expectedVersion: body.expectedVersion,
        initialLocked: await initialWhiteboardLock(ctx, meetingId),
      });
      if (!batch.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, WHITEBOARD_TOPIC, { kind: "batch", batch: batch });
      }
      return sendJson(res, batch.duplicate ? 200 : 201, { batch: batch });
    },

    async whiteboardUndoRedo(req, res, params, kind) {
      requireFeature("whiteboard");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      if (!validUuid(body.clientBatchId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId must be a UUID");
      const batch = await d.collaboration.whiteboardUndoRedo(ctx.tenant, {
        meetingId: meetingId, actorId: access.actor.participantId, kind: kind,
        clientBatchId: body.clientBatchId, expectedVersion: body.expectedVersion,
        initialLocked: await initialWhiteboardLock(ctx, meetingId),
      });
      if (!batch.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, WHITEBOARD_TOPIC, { kind: "batch", batch: batch });
      }
      return sendJson(res, 200, { batch: batch });
    },

    async whiteboardClear(req, res, params) {
      requireFeature("whiteboard");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      if (!access.actor.isModerator) return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Moderator permission is required");
      const body = req.body || {};
      if (!validUuid(body.clientBatchId)) return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId must be a UUID");
      const batch = await d.collaboration.clearWhiteboard(ctx.tenant, {
        meetingId: meetingId, actorId: access.actor.participantId, isModerator: true,
        clientBatchId: body.clientBatchId, expectedVersion: body.expectedVersion,
      });
      if (!batch.duplicate && d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
        meetingId: meetingId, actorKey: access.actor.participantId,
        action: "whiteboard_clear", targetRef: null, details: { actorRole: access.actor.role },
      });
      if (!batch.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, WHITEBOARD_TOPIC, { kind: "batch", batch: batch });
      }
      return sendJson(res, 200, { batch: batch });
    },

    async whiteboardLock(req, res, params) {
      requireFeature("whiteboard");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      if (!access.actor.isModerator) return sendError(res, 403, ERROR_CODES.FORBIDDEN, "Moderator permission is required");
      const body = req.body || {};
      if (!validUuid(body.clientBatchId) || typeof body.locked !== "boolean") {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId and locked are required");
      }
      const batch = await d.collaboration.setWhiteboardLock(ctx.tenant, {
        meetingId: meetingId, actorId: access.actor.participantId,
        clientBatchId: body.clientBatchId, expectedVersion: body.expectedVersion, locked: body.locked,
      });
      if (!batch.duplicate && d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
        meetingId: meetingId, actorKey: access.actor.participantId,
        action: "whiteboard_lock", targetRef: null,
        details: { actorRole: access.actor.role, locked: body.locked },
      });
      if (!batch.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, WHITEBOARD_TOPIC, { kind: "lock", batch: batch, locked: body.locked });
      }
      return sendJson(res, 200, { batch: batch, locked: body.locked });
    },

    async whiteboardPresent(req, res, params) {
      requireFeature("whiteboard");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      // mutate: true is the "may draw" gate; the board's own lock is checked
      // inside the transaction, because presenting is offered to whoever may
      // draw and a locked board is moderators-only.
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      if (!validUuid(body.clientBatchId) || typeof body.presenting !== "boolean") {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "clientBatchId and presenting are required");
      }
      // The board and a deck compete for one stage. Refusing is deliberate:
      // taking over would silently end somebody else's presentation, and the
      // file path already answers PRESENTATION_ACTIVE for a second deck.
      if (body.presenting && features.filePresentations) {
        const active = await d.collaboration.presentationState(ctx.tenant, meetingId);
        if (active && active.status === "active") {
          return sendError(res, 409, "PRESENTATION_ACTIVE", "A file presentation is active");
        }
      }
      const batch = await d.collaboration.setWhiteboardPresenting(ctx.tenant, {
        meetingId: meetingId, actorId: access.actor.participantId,
        actorName: access.actor.displayName || access.actor.name || null,
        isModerator: access.actor.isModerator,
        clientBatchId: body.clientBatchId, expectedVersion: body.expectedVersion,
        presenting: body.presenting,
      });
      if (!batch.duplicate && d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
        meetingId: meetingId, actorKey: access.actor.participantId,
        action: "whiteboard_present", targetRef: null,
        details: { actorRole: access.actor.role, presenting: body.presenting },
      });
      // "Presented by <name>" is drawn on every board in the room, so the
      // presenter is named under the same policy as everybody else. The batch
      // carries the pair too, and a mask on one half of the payload would just
      // move the leak two lines down.
      const presentMask = await participantNameMask(ctx, meetingId);
      const maskedBatch = maskPresenter(batch, presentMask);
      if (!batch.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, WHITEBOARD_TOPIC, {
          kind: "present", batch: maskedBatch, presenting: !!batch.presenting,
          presenterId: maskedBatch.presenterId || null, presenterName: maskedBatch.presenterName || null,
        });
      }
      return sendJson(res, 200, {
        batch: maskedBatch, presenting: !!batch.presenting,
        presenterId: maskedBatch.presenterId || null, presenterName: maskedBatch.presenterName || null,
      });
    },

    async whiteboardPresence(req, res, params) {
      requireFeature("whiteboard");
      requireFeature("whiteboardEnhancements");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const access = await requireCollaborationAccess(req, ctx, meetingId, { mutate: true });
      const body = req.body || {};
      const phase = String(body.phase || "");
      const tool = String(body.tool || "");
      const point = body.point;
      if (!validUuid(String(body.gestureId || "")) || !["start", "move", "end"].includes(phase) ||
          !["pen", "highlighter"].includes(tool) || !Array.isArray(point) || point.length !== 2 ||
          !point.every(function (value) { return Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 1; })) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "Invalid whiteboard presence payload");
      }
      const bytes = Buffer.byteLength(JSON.stringify(body), "utf8");
      if (bytes > 1024) return sendError(res, 413, ERROR_CODES.VALIDATION, "Whiteboard presence payload is too large");
      if (!enforceParticipantRate(req, res, whiteboardLimiter,
        "whiteboard-presence:" + meetingId + ":" + access.actor.participantId, 20)) return;
      // The pen trail is labelled with its owner on every other board in the
      // room. One store read on a 20/s path, and it early-returns to the real
      // name the moment nothing is hidden.
      const presencePolicy = await ctx.store.getPolicy(meetingId);
      await broadcastLossy(ctx.tenant, meetingId, WHITEBOARD_PRESENCE_TOPIC, {
        gestureId: body.gestureId,
        phase: phase,
        tool: tool,
        point: [Number(point[0]), Number(point[1])],
        color: Number(body.color || 0xff2563eb),
        width: Math.max(1, Math.min(24, Number(body.width || 4))),
        participantId: access.actor.participantId,
        participantName: await publishedNameFor(
          ctx, meetingId, access.actor.participantId, access.actor.displayName,
          access.actor.role, presencePolicy
        ),
        participantRole: access.actor.role,
        sentAt: new Date().toISOString(),
      });
      return sendJson(res, 202, { result: true });
    },

    async captureState(req, res, params) {
      if (!features.meetingRecording && !features.meetingTranscription) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      if (!d.capture) throw new collaborationMod.CollaborationError("Capture is unavailable", "CAPTURE_UNAVAILABLE", 503);
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireCaptureViewer(req, ctx, meetingId);
      return sendJson(res, 200, { state: await d.capture.getState(ctx.tenant, meetingId) });
    },

    async acknowledgeCapture(req, res, params) {
      if (!features.meetingRecording && !features.meetingTranscription) {
        throw new collaborationMod.CollaborationError("Feature is not enabled", "FEATURE_DISABLED", 404);
      }
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const viewer = await requireCaptureViewer(req, ctx, meetingId);
      if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
        meetingId: meetingId, actorKey: viewer.actor.participantId,
        action: "capture_acknowledge", targetRef: null, details: {},
      });
      return sendJson(res, 200, { result: true });
    },

    async startRecording(req, res, params) {
      requireCaptureFeature("meetingRecording");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const owner = await requireMeetingOwner(req, ctx, meetingId, true);
      const body = req.body || {};
      const result = await d.capture.beginRecording(ctx.tenant, {
        meetingId: meetingId, actorRef: owner.actorRef,
        clientActionId: body.clientActionId, expectedVersion: Number(body.expectedVersion),
      });
      if (!result.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, CAPTURE_TOPIC, { kind: "notice", state: result.state, startsInSeconds: 5 });
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId, actorKey: owner.actorRef, action: "recording_start", targetRef: result.recordingId, details: {},
        });
        // Recording only. Transcription is started by `transcription/start`, with
        // its own language choice, and never as a side effect of Record.
        const timer = setTimeout(async function () {
          try {
            const activated = await d.capture.activateRecording(ctx.tenant, meetingId, result.recordingId);
            await broadcastCollaboration(ctx.tenant, meetingId, CAPTURE_TOPIC, { kind: "state", state: activated.state });
          } catch (err) {
            log.warn("meet: delayed recording start failed: " + err.message);
          }
        }, 5000);
        if (timer.unref) timer.unref();
      }
      return sendJson(res, result.duplicate ? 200 : 202, result);
    },

    async stopRecording(req, res, params) {
      requireCaptureFeature("meetingRecording");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const owner = await requireMeetingOwner(req, ctx, meetingId, true);
      const body = req.body || {};
      const result = await d.capture.stopRecording(ctx.tenant, {
        meetingId: meetingId, actorRef: owner.actorRef, clientActionId: body.clientActionId,
        expectedVersion: Number(body.expectedVersion),
      });
      if (!result.duplicate) {
        await broadcastCollaboration(ctx.tenant, meetingId, CAPTURE_TOPIC, { kind: "state", state: result.state });
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId, actorKey: owner.actorRef, action: "recording_stop", targetRef: result.recordingId, details: {},
        });
      }
      return sendJson(res, 200, result);
    },

    async setTranscription(req, res, params, enabled) {
      requireCaptureFeature("meetingTranscription");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      const owner = await requireMeetingOwner(req, ctx, meetingId, true);
      const body = req.body || {};
      const result = await d.capture.setTranscription(ctx.tenant, {
        meetingId: meetingId, actorRef: owner.actorRef, clientActionId: body.clientActionId,
        expectedVersion: Number(body.expectedVersion), language: enabled ? body.language : null,
      }, enabled);
      if (!result.duplicate) {
        const state = enabled
          ? await d.capture.startTranscriber(ctx.tenant, meetingId)
          : await d.capture.stopTranscriber(ctx.tenant, meetingId);
        result.state = state;
        // A transcription that is actually running gets the same consent notice
        // a recording does. It used to inherit one only because Record started a
        // transcription too; now that Record starts nothing else, a
        // transcription-only capture would otherwise be the one capture the room
        // is never told about. Not announced when the worker failed to come up:
        // nobody is being transcribed, so there is nothing to consent to.
        const announced = enabled && state.transcriptionStatus === "active"
          ? { kind: "notice", state: state, startsInSeconds: 0 }
          : { kind: "state", state: state };
        await broadcastCollaboration(ctx.tenant, meetingId, CAPTURE_TOPIC, announced);
        if (d.recorder && d.recorder.moderationEvent) await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId, actorKey: owner.actorRef,
          action: enabled ? "transcription_start" : "transcription_stop", targetRef: null, details: {},
        });
      }
      return sendJson(res, enabled ? 202 : 200, result);
    },

    async recordings(req, res, params) {
      requireCaptureFeature("meetingRecording");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireMeetingOwner(req, ctx, meetingId, false);
      return sendJson(res, 200, { recordings: await d.capture.recordings(ctx.tenant, meetingId) });
    },

    async recordingLink(req, res, params) {
      requireCaptureFeature("meetingRecording");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireMeetingOwner(req, ctx, meetingId, false);
      const recordingId = String(params.recordingId || "");
      const rows = await d.capture.recordings(ctx.tenant, meetingId);
      if (!rows.some(function (row) { return row.id === recordingId && row.status === "complete"; })) {
        return sendError(res, 404, ERROR_CODES.NOT_FOUND, "Recording not found");
      }
      return sendJson(res, 200, { url: d.capture.recordingLink(ctx.tenant, meetingId, recordingId), expiresIn: 900 });
    },

    async signedRecording(req, res, params) {
      requireCaptureFeature("meetingRecording");
      const ctx = await context(req);
      await d.capture.serveRecording(req, res, ctx.tenant, String(params.recordingId || ""), (req.query || {}).token);
    },

    async transcript(req, res, params) {
      requireCaptureFeature("meetingTranscription");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireMeetingOwner(req, ctx, meetingId, false);
      const segments = await d.capture.transcript(ctx.tenant, meetingId);
      const transcriptMask = await participantNameMask(ctx, meetingId);
      return sendJson(res, 200, {
        segments: transcriptMask
          ? segments.map(function (segment) {
              return Object.assign({}, segment, {
                participantName: transcriptMask(segment.participantId, segment.participantName),
              });
            })
          : segments,
      });
    },

    async summary(req, res, params) {
      requireCaptureFeature("meetingSummary");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireMeetingOwner(req, ctx, meetingId, false);
      return sendJson(res, 200, { summary: await d.capture.summary(ctx.tenant, meetingId) });
    },

    async retrySummary(req, res, params) {
      requireCaptureFeature("meetingSummary");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireMeetingOwner(req, ctx, meetingId, false);
      return sendJson(res, 202, { summary: await d.capture.retrySummary(ctx.tenant, meetingId) });
    },

    async review(req, res, params) {
      requireCaptureFeature("meetingReviews");
      const ctx = await context(req);
      const raw = authMod.bearerOf(req.headers || {});
      const payload = d.capture.verifyReviewToken(ctx.tenant, raw);
      if (payload.m !== String(params.meetingId || "")) throw new authMod.AuthError("Review token belongs to another meeting", "AUTH_WRONG_MEETING", 403);
      if (req.method === "GET") return sendJson(res, 200, { review: await d.capture.ownReview(ctx.tenant, payload) });
      const rating = Number((req.body || {}).rating);
      const comment = String((req.body || {}).comment || "").trim();
      if (!Number.isInteger(rating) || rating < 1 || rating > 5 || Buffer.byteLength(comment, "utf8") > 2048) {
        return sendError(res, 400, ERROR_CODES.VALIDATION, "rating must be 1-5 and comment at most 2 KiB");
      }
      return sendJson(res, 200, { review: await d.capture.saveReview(ctx.tenant, payload, rating, comment) });
    },

    async reviews(req, res, params) {
      requireCaptureFeature("meetingReviews");
      const ctx = await context(req);
      const meetingId = String(params.meetingId || "");
      await requireMeetingOwner(req, ctx, meetingId, false);
      return sendJson(res, 200, { reviews: await d.capture.reviews(ctx.tenant, meetingId) });
    },

    // ── 10. POST /rooms/:meetingId/end ──
    async endMeeting(req, res, params) {
      const ctx = await context(req);
      const host = await d.hostAuth.requireHost(req, ctx.tenant);
      const meetingId = await ctx.store.resolveMeetingId(params.meetingId);
      if (!meetingId) return sendError(res, 404, "Meeting not found");
      const room = await ctx.store.getRoom(meetingId);
      if (room && room.ownerKey && room.ownerKey !== host.ownerKey) {
        return sendError(res, 403, "Not the host of this meeting");
      }

      if (d.capture) {
        try {
          await d.capture.endMeeting(ctx.tenant, meetingId);
        } catch (err) {
          log.warn("meet: capture finalization failed (continuing): " + err.message);
        }
      }

      try {
        await d.gateway.endMeeting(ctx.tenant, meetingId);
      } catch (err) {
        // Non-fatal: the room may already be gone. Local state still ends.
        log.warn("meet: deleteRoom failed (continuing): " + err.message);
      }
      await ctx.store.updateRoom(meetingId, { status: "ended", endedAt: Date.now() });
      await ctx.store.endRoom(meetingId);
      if (d.recorder) {
        await Promise.resolve(d.recorder.meetingEnded(ctx.tenant, {
          meetingId: meetingId, endReason: "host_ended", endedByRef: "host-" + host.ownerKey,
        })).catch(function (err) {
          log.warn("meet: durable meetingEnded failed: " + err.message);
        });
      }
      if (features.meetingSummary && d.capture) {
        try { await d.capture.retrySummary(ctx.tenant, meetingId); } catch (err) {
          log.warn("meet: summary enqueue failed: " + err.message);
        }
      }
      if (d.recorder && d.recorder.moderationEvent) {
        await d.recorder.moderationEvent(ctx.tenant, {
          meetingId: meetingId,
          actorKey: host.ownerKey,
          action: "end",
          targetRef: null,
        });
      }
      return sendJson(res, 200, { result: true });
    },
  };

  // ── routing table ──
  const routes = [
    { method: "GET", pattern: /^\/capabilities$/, keys: [], handler: handlers.capabilities },
    { method: "GET", pattern: /^\/files\/([^/]+)$/, keys: ["attachmentId"], handler: handlers.signedAttachment },
    { method: "GET", pattern: /^\/files\/([^/]+)\/preview\/([^/]+)$/, keys: ["attachmentId", "pageNumber"], handler: handlers.signedPreviewPage },
    { method: "GET", pattern: /^\/recordings\/([^/]+)$/, keys: ["recordingId"], handler: handlers.signedRecording },
    { method: "POST", pattern: /^\/gateway\/events$/, keys: [], handler: handlers.gatewayEvent },
    { method: "POST", pattern: /^\/callbacks\/transcript$/, keys: [], handler: handlers.transcriptCallback },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/waiting$/, keys: ["meetingId"], handler: handlers.waitingList },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/join-request$/, keys: ["meetingId"], handler: handlers.joinRequest },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/guest-token$/, keys: ["meetingId"], handler: handlers.guestToken },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/cancel-join$/, keys: ["meetingId"], handler: handlers.cancelJoin },
    { method: "GET", pattern: /^\/rooms\/active$/, keys: [], handler: handlers.activeMeetings },
    { method: "GET", pattern: /^\/rooms\/history$/, keys: [], handler: handlers.collaborationHistory },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/token$/, keys: ["meetingId"], handler: handlers.hostToken },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/admit$/, keys: ["meetingId"], handler: handlers.admit },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/kick$/, keys: ["meetingId"], handler: handlers.kick },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/role$/, keys: ["meetingId"], handler: handlers.setParticipantRole },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/interactions$/, keys: ["meetingId"], handler: handlers.interactionState },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/hand$/, keys: ["meetingId"], handler: handlers.setHand },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/reaction$/, keys: ["meetingId"], handler: handlers.sendReaction },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/participants\/([^/]+)\/media\/mute$/, keys: ["meetingId", "participantId"], handler: handlers.muteParticipantMedia },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/participants\/([^/]+)\/media\/enable-requests$/, keys: ["meetingId", "participantId"], handler: handlers.requestParticipantMedia },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/media\/enable-requests$/, keys: ["meetingId"], handler: handlers.pendingMediaRequests },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/media\/enable-requests\/([^/]+)\/respond$/, keys: ["meetingId", "requestId"], handler: handlers.respondMediaRequest },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/policy$/, keys: ["meetingId"], handler: handlers.roomPolicy },
    { method: "PATCH", pattern: /^\/rooms\/([^/]+)\/policy$/, keys: ["meetingId"], handler: handlers.updateRoomPolicy },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/participants$/, keys: ["meetingId"], handler: handlers.participantRoster },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/participants\/([^/]+)\/media\/grant$/, keys: ["meetingId", "participantId"], handler: handlers.setParticipantMediaGrant },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/media\/access-requests$/, keys: ["meetingId"], handler: handlers.requestMediaAccess },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/media\/access-requests$/, keys: ["meetingId"], handler: handlers.mediaAccessRequests },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/media\/access-requests\/([^/]+)\/respond$/, keys: ["meetingId", "requestId"], handler: handlers.respondMediaAccess },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/chat\/messages$/, keys: ["meetingId"], handler: handlers.chatMessages },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/chat\/messages$/, keys: ["meetingId"], handler: handlers.sendChatMessage },
    { method: "PATCH", pattern: /^\/rooms\/([^/]+)\/chat\/messages\/([^/]+)$/, keys: ["meetingId", "messageId"], handler: handlers.editChatMessage },
    { method: "DELETE", pattern: /^\/rooms\/([^/]+)\/chat\/messages\/([^/]+)$/, keys: ["meetingId", "messageId"], handler: handlers.deleteChatMessage },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/chat\/attachments$/, keys: ["meetingId"], handler: handlers.uploadChatAttachment },
    { method: "DELETE", pattern: /^\/rooms\/([^/]+)\/chat\/attachments\/([^/]+)$/, keys: ["meetingId", "attachmentId"], handler: handlers.cancelChatAttachment },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/chat\/attachments\/([^/]+)\/link$/, keys: ["meetingId", "attachmentId"], handler: handlers.chatAttachmentLink },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/chat\/attachments\/([^/]+)\/preview$/, keys: ["meetingId", "attachmentId"], handler: handlers.prepareAttachmentPreview },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/chat\/attachments\/([^/]+)\/preview$/, keys: ["meetingId", "attachmentId"], handler: handlers.attachmentPreview },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/assets$/, keys: ["meetingId"], handler: handlers.uploadPresentationAsset },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/assets\/from-attachment$/, keys: ["meetingId"], handler: handlers.presentationAssetFromAttachment },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/presentation\/assets\/([^/]+)$/, keys: ["meetingId", "assetId"], handler: handlers.presentationAsset },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/presentation$/, keys: ["meetingId"], handler: handlers.presentationState },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/start$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.presentationAction(req, res, params, "start"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/page$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.presentationAction(req, res, params, "page"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/stop$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.presentationAction(req, res, params, "stop"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/mode$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.presentationAction(req, res, params, "mode"); } },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/presentation\/annotations$/, keys: ["meetingId"], handler: handlers.presentationAnnotations },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/annotations$/, keys: ["meetingId"], handler: handlers.presentationAnnotationBatch },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/annotations\/undo$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.presentationAnnotationUndoRedo(req, res, params, "undo"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/annotations\/redo$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.presentationAnnotationUndoRedo(req, res, params, "redo"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/annotations\/clear$/, keys: ["meetingId"], handler: handlers.presentationAnnotationClear },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/presentation\/annotations\/presence$/, keys: ["meetingId"], handler: handlers.presentationAnnotationPresence },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/whiteboard$/, keys: ["meetingId"], handler: handlers.whiteboardState },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/operations$/, keys: ["meetingId"], handler: handlers.whiteboardOperations },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/undo$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.whiteboardUndoRedo(req, res, params, "undo"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/redo$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.whiteboardUndoRedo(req, res, params, "redo"); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/clear$/, keys: ["meetingId"], handler: handlers.whiteboardClear },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/lock$/, keys: ["meetingId"], handler: handlers.whiteboardLock },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/present$/, keys: ["meetingId"], handler: handlers.whiteboardPresent },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/whiteboard\/presence$/, keys: ["meetingId"], handler: handlers.whiteboardPresence },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/capture$/, keys: ["meetingId"], handler: handlers.captureState },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/capture\/acknowledge$/, keys: ["meetingId"], handler: handlers.acknowledgeCapture },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/recording\/start$/, keys: ["meetingId"], handler: handlers.startRecording },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/recording\/stop$/, keys: ["meetingId"], handler: handlers.stopRecording },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/transcription\/start$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.setTranscription(req, res, params, true); } },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/transcription\/stop$/, keys: ["meetingId"], handler: function (req, res, params) { return handlers.setTranscription(req, res, params, false); } },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/recordings$/, keys: ["meetingId"], handler: handlers.recordings },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/recordings\/([^/]+)\/link$/, keys: ["meetingId", "recordingId"], handler: handlers.recordingLink },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/transcript$/, keys: ["meetingId"], handler: handlers.transcript },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/summary$/, keys: ["meetingId"], handler: handlers.summary },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/summary\/retry$/, keys: ["meetingId"], handler: handlers.retrySummary },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/review$/, keys: ["meetingId"], handler: handlers.review },
    { method: "PUT", pattern: /^\/rooms\/([^/]+)\/review$/, keys: ["meetingId"], handler: handlers.review },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/reviews$/, keys: ["meetingId"], handler: handlers.reviews },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/end$/, keys: ["meetingId"], handler: handlers.endMeeting },
    { method: "POST", pattern: /^\/rooms$/, keys: [], handler: handlers.createRoom },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/lobby\/banner$/, keys: ["meetingId"], handler: handlers.uploadLobbyBanner },
    { method: "POST", pattern: /^\/rooms\/([^/]+)\/lobby\/photo$/, keys: ["meetingId"], handler: handlers.uploadLobbyPhoto },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/lobby\/banner\/([^/]+)$/, keys: ["slug", "version"], handler: handlers.lobbyBanner },
    { method: "GET", pattern: /^\/rooms\/([^/]+)\/lobby\/photo\/([^/]+)$/, keys: ["slug", "version"], handler: handlers.lobbyPhoto },
    { method: "GET", pattern: /^\/rooms\/([^/]+)$/, keys: ["slug"], handler: handlers.lookupRoom },
  ];

  /**
   * Dispatch. `pathname` is relative to the mount point (/meet/api).
   * Returns false when nothing matched, so the caller can 404 or fall through.
   */
  async function handle(req, res, pathname) {
    const path = String(pathname || "").replace(/\/+$/, "") || "/";
    for (let i = 0; i < routes.length; i++) {
      const route = routes[i];
      if (route.method !== req.method) continue;
      const m = route.pattern.exec(path);
      if (!m) continue;
      const params = {};
      route.keys.forEach(function (key, idx) {
        try {
          params[key] = decodeURIComponent(m[idx + 1]);
        } catch (err) {
          params[key] = "";
        }
      });
      try {
        await route.handler(req, res, params);
      } catch (err) {
        const status = errorStatus(err);
        if (status >= 500) log.error("meet: " + req.method + " " + path + " failed: " + (err && err.stack));
        else log.warn("meet: " + req.method + " " + path + " -> " + status + " " + (err && err.message));
        if (!res.headersSent) {
          const rawCollaborationCode = String((err && err.code) || "");
          const preserveCode = err && (
            err.name === "CollaborationError" || err.name === "AttachmentError" || err.name === "CaptureError" ||
            /^(COLLABORATION|FEATURE|IDEMPOTENCY|REPLY|ATTACHMENT|VERSION|WHITEBOARD|NOTHING_|CAPTURE|RECORDING|REVIEW)/.test(rawCollaborationCode)
          );
          const code = preserveCode ? rawCollaborationCode : exceptionCode(err, status);
          if (err && err.details) {
            sendJson(res, status, {
              code: String(code).toLowerCase(),
              error: status >= 500 ? "Meeting collaboration is unavailable" : ((err && err.message) || "Request failed"),
              details: err.details,
            });
          } else {
            sendError(res, status, String(code).toLowerCase(), status >= 500 ? "Meeting service is unavailable" : ((err && err.message) || "Request failed"));
          }
        }
      }
      return true;
    }
    return false;
  }

  return { handle: handle, handlers: handlers, routes: routes, newMeetingCode: newMeetingCode };
}

module.exports = {
  createRouter: createRouter,
  newMeetingCode: newMeetingCode,
  ERR_NOT_ADMITTED: ERR_NOT_ADMITTED,
  ERR_DECLINED: ERR_DECLINED,
  ERROR_CODES: ERROR_CODES,
  REACTIONS: REACTIONS,
  INTERACTION_TOPIC: INTERACTION_TOPIC,
  CHAT_TOPIC: CHAT_TOPIC,
  WHITEBOARD_TOPIC: WHITEBOARD_TOPIC,
  MEDIA_REQUEST_TOPIC: MEDIA_REQUEST_TOPIC,
  PRESENTATION_TOPIC: PRESENTATION_TOPIC,
};
