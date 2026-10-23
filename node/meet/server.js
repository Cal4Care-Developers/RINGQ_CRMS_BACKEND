/**
 * RingQ Meet standalone tenant control plane.
 *
 * This process is intentionally HTTP-only and loopback-bound. The retired
 * Socket.IO/:9886 relay is not a rollback path: rollback disables Meet through
 * capabilities, it never restores the compromised legacy stack.
 *
 * Runtime: Node.js 24 LTS. Configuration lives in a dedicated owner-only file
 * (`/etc/ringq/meet-control-plane.env`) rather than the shared RingQ env file.
 */

require("dotenv").config({
  path: process.env.MEET_ENV_FILE || "/etc/ringq/meet-control-plane.env",
  // The dedicated owner-only file is authoritative. PM2 keeps historical
  // process variables across reloads, including empty values, unless they are
  // explicitly removed. Without override an old MEET_FEATURE_* value can
  // silently mask the reconciled configuration indefinitely.
  override: true,
  quiet: true,
});

const http = require("http");
const redis = require("redis");
const callbackAuthenticator = require("./callback-auth.js").callbackAuthenticator;

const PORT = Number(process.env.MEET_PORT || 6000);
const HOST = process.env.MEET_BIND_HOST || "127.0.0.1";
const BODY_LIMIT = Math.max(1024, Math.min(1024 * 1024, Number(process.env.MEET_BODY_LIMIT_BYTES || 64 * 1024)));
const MEET_ENABLED = String(process.env.MEET_ENABLED || "").toLowerCase() === "true";
const ALLOW_UNVERSIONED = String(process.env.MEET_ALLOW_UNVERSIONED_COMPAT || "false").toLowerCase() === "true";

function redact(value) {
  return String(value || "")
    .replace(/Bearer\s+[A-Za-z0-9._~-]+/gi, "Bearer [REDACTED]")
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, "[REDACTED_EMAIL]")
    .replace(/(token|secret|authorization)(\s*[:=]\s*)[^\s,;]+/gi, "$1$2[REDACTED]");
}

const logger = {
  info: (message) => console.log("[meet] " + redact(message)),
  warn: (message) => console.warn("[meet] " + redact(message)),
  error: (message) => console.error("[meet] " + redact(message)),
};

function safeError(err) {
  return redact(err && err.message ? err.message : err);
}

async function initMeetApi(redisClient) {
  const { TenantResolver } = require("./tenant.js");
  const { RoomStore } = require("./rooms.js");
  const { HostAuthenticator, OpaqueAccessTokenStore, InviteStore } = require("./auth.js");
  const { createRouter } = require("./routes.js");
  const { createConfigLoader } = require("./config.js");
  const { fromEnv } = require("./recorder.js");
  const { fromEnv: gatewayFromEnv } = require("./gateway.js");
  const { PresenceCoordinator } = require("./presence.js");
  const { CollaborationRepository } = require("./collaboration.js");
  const { AttachmentService } = require("./attachments.js");
  const { DocumentService } = require("./documents.js");
  const { CaptureService } = require("./capture.js");

  const guestSecret = process.env.MEET_GUEST_TOKEN_SECRET || "";
  if (!guestSecret) throw new Error("MEET_GUEST_TOKEN_SECRET is not configured");

  const resolver = new TenantResolver({
    loadConfig: createConfigLoader({ env: process.env, logger: logger }),
  });
  const storeFor = (tenant) => new RoomStore({ redis: redisClient, tenant: tenant });
  const gateway = gatewayFromEnv(process.env);
  const accessTokens = new OpaqueAccessTokenStore({ redis: redisClient });
  const hostAuth = new HostAuthenticator({
    lookupBearer: (token) => accessTokens.lookup(token),
  });
  const invites = new InviteStore({ redis: redisClient });
  const recorder = fromEnv(process.env, logger);
  const collaboration = recorder && recorder.pool
    ? new CollaborationRepository({ pool: recorder.pool, logger: logger })
    : null;
  const features = {
    chat: String(process.env.MEET_FEATURE_CHAT || "false").toLowerCase() === "true",
    chatAttachments: String(process.env.MEET_FEATURE_CHAT_ATTACHMENTS || "false").toLowerCase() === "true",
    whiteboard: String(process.env.MEET_FEATURE_WHITEBOARD || "false").toLowerCase() === "true",
    participantMediaControls: String(process.env.MEET_FEATURE_PARTICIPANT_MEDIA_CONTROLS || "false").toLowerCase() === "true",
    chatAttachmentPreview: String(process.env.MEET_FEATURE_CHAT_ATTACHMENT_PREVIEW || "false").toLowerCase() === "true",
    filePresentations: String(process.env.MEET_FEATURE_FILE_PRESENTATIONS || "false").toLowerCase() === "true",
    meetingRecording: String(process.env.MEET_FEATURE_MEETING_RECORDING || "false").toLowerCase() === "true",
    meetingTranscription: String(process.env.MEET_FEATURE_MEETING_TRANSCRIPTION || "false").toLowerCase() === "true",
    meetingSummary: String(process.env.MEET_FEATURE_MEETING_SUMMARY || "false").toLowerCase() === "true",
    meetingReviews: String(process.env.MEET_FEATURE_MEETING_REVIEWS || "false").toLowerCase() === "true",
    whiteboardEnhancements: String(process.env.MEET_FEATURE_WHITEBOARD_ENHANCEMENTS || "false").toLowerCase() === "true",
    hostPrivacyControls: String(process.env.MEET_FEATURE_HOST_PRIVACY_CONTROLS || "false").toLowerCase() === "true",
  };
  const attachments = collaboration
    ? new AttachmentService({
        repository: collaboration,
        root: process.env.MEET_COLLABORATION_ROOT || "/var/lib/ringq/meet-collaboration",
        scanner: process.env.MEET_CLAMDSCAN_BIN || "clamdscan",
        signingSecret: process.env.MEET_ATTACHMENT_SIGNING_SECRET || "",
        logger: logger,
      })
    : null;
  const documents = collaboration && attachments
    ? new DocumentService({
        repository: collaboration,
        attachments: attachments,
        root: process.env.MEET_COLLABORATION_ROOT || "/var/lib/ringq/meet-collaboration",
        signingSecret: process.env.MEET_ATTACHMENT_SIGNING_SECRET || "",
        bwrap: process.env.MEET_BWRAP_BIN || "bwrap",
        prlimit: process.env.MEET_PRLIMIT_BIN || "prlimit",
        systemdRun: process.env.MEET_SYSTEMD_RUN_BIN || "systemd-run",
        workerUser: process.env.MEET_CONVERTER_USER || "ringq-meet-converter",
        logger: logger,
      })
    : null;
  const capture = recorder && recorder.pool
    ? new CaptureService({
        pool: recorder.pool,
        gateway: gateway,
        root: process.env.MEET_RECORDING_ROOT || "/var/lib/ringq/meet-recordings",
        signingSecret: process.env.MEET_RECORDING_SIGNING_SECRET || "",
        reviewSecret: process.env.MEET_REVIEW_SIGNING_SECRET || "",
        transcriberUrl: process.env.MEET_TRANSCRIBER_URL || "",
        transcriberWsUrl: process.env.MEET_TRANSCRIBER_WS_URL || "ws://127.0.0.1:7880",
        transcriberSecret: process.env.MEET_TRANSCRIBER_SECRET || "",
        transcriptCallbackUrl: process.env.MEET_TRANSCRIPT_CALLBACK_URL || "",
        segmentMaxMs: Number(process.env.MEET_RECORDING_SEGMENT_MAX_MS || 4 * 60 * 60 * 1000),
        minimumFreeBytes: Number(process.env.MEET_RECORDING_MINIMUM_FREE_BYTES || 2 * 1024 * 1024 * 1024),
        logger: logger,
      })
    : null;
  const presence = new PresenceCoordinator({
    redis: redisClient,
    storeFor: storeFor,
    gateway: gateway,
    recorder: recorder,
    collaboration: collaboration,
    attachments: attachments,
    documents: documents,
    capture: capture,
    transcriberSecret: process.env.MEET_TRANSCRIBER_SECRET || "",
    features: features,
    logger: logger,
    intervalMs: Number(process.env.MEET_RECONCILE_INTERVAL_MS || 10000),
  });
  const router = createRouter({
    resolver: resolver,
    storeFor: storeFor,
    hostAuth: hostAuth,
    invites: invites,
    guestSecret: guestSecret,
    gateway: gateway,
    presence: presence,
    callbackAuth: callbackAuthenticator(process.env),
    recorder: recorder,
    collaboration: collaboration,
    attachments: attachments,
    documents: documents,
    capture: capture,
    // The router VALIDATES the transcript callback bearer against this, while
    // `capture` above MINTS the same value into each transcriber job. Omitting it
    // here did not disable authentication, it made it impossible: `d.transcriberSecret`
    // was undefined, so `validWorkerBearer`'s `configured.length >= 32` guard was
    // false for every request and `/callbacks/transcript` answered 403 forever.
    // No transcript row could ever be stored, and the only visible symptom was a
    // live-caption strip that stayed empty.
    transcriberSecret: process.env.MEET_TRANSCRIBER_SECRET || "",
    features: features,
    logger: logger,
  });
  await presence.start();
  return {
    router: router, presence: presence, recorder: recorder, gateway: gateway,
    collaboration: collaboration, attachments: attachments,
    documents: documents, capture: capture,
  };
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    let tooLarge = false;
    req.on("data", (chunk) => {
      size += chunk.length;
      if (size > BODY_LIMIT) {
        tooLarge = true;
        return;
      }
      chunks.push(chunk);
    });
    req.on("end", () => {
      if (tooLarge) {
        const err = new Error("request body too large");
        err.statusCode = 413;
        reject(err);
      } else {
        resolve(Buffer.concat(chunks));
      }
    });
    req.on("error", reject);
  });
}

function json(res, status, value) {
  res.statusCode = status;
  res.setHeader("Content-Type", "application/json");
  res.setHeader("Cache-Control", "no-store");
  res.end(JSON.stringify(value));
}

function disabledCapabilities(res, reason) {
  json(res, 200, {
    apiVersion: "v1",
    enabled: false,
    gatewayHealthy: false,
    features: {},
    failure: { code: "media_unavailable", reason: reason },
  });
}

function createHttpHandler(getApi) {
  return async function handleHttp(req, res) {
    const requestUrl = new URL(req.url || "/", "http://127.0.0.1");
    const pathname = requestUrl.pathname;
    req.query = {};
    requestUrl.searchParams.forEach(function (value, key) { req.query[key] = value; });
    if (pathname === "/healthz") {
      return json(res, 200, { ok: true, meetEnabled: !!getApi(), legacy: false, apiVersion: "v1" });
    }

    const v1Prefix = "/meet/api/v1";
    const legacyPrefix = "/meet/api";
    const isCapabilities = pathname === v1Prefix + "/capabilities";
    if (!getApi() && isCapabilities) return disabledCapabilities(res, MEET_ENABLED ? "misconfigured" : "disabled");
    if (!getApi()) return json(res, 404, { code: "not_found", error: "Not found" });

    let relative = null;
    if (pathname === v1Prefix || pathname.indexOf(v1Prefix + "/") === 0) {
      relative = pathname.slice(v1Prefix.length);
    } else if (ALLOW_UNVERSIONED && (pathname === legacyPrefix || pathname.indexOf(legacyPrefix + "/") === 0)) {
      relative = pathname.slice(legacyPrefix.length);
      res.setHeader("Deprecation", "true");
      res.setHeader("Sunset", "Wed, 31 Dec 2026 23:59:59 GMT");
    }
    if (relative === null) return json(res, 404, { code: "not_found", error: "Not found" });

    const multipartUpload = req.method === "POST" &&
      /\/rooms\/[^/]+\/(?:chat\/attachments|presentation\/assets)$/.test(relative || "") &&
      /^multipart\/form-data(?:;|$)/i.test(String(req.headers["content-type"] || ""));
    // The lobby banner is a raw image, not JSON and not multipart, and its
    // handler reads the stream itself under its own 2 MiB cap. Listed here so
    // the 64 KiB JSON limit above keeps applying to every route that is not
    // named — an allow-list, never a size guess.
    const rawUpload = req.method === "POST" &&
      /\/rooms\/[^/]+\/lobby\/(banner|photo)$/.test(relative || "");
    if (multipartUpload || rawUpload) {
      req.body = {};
      req.setTimeout(120000);
    } else {
      let raw;
      try {
        raw = await readBody(req);
      } catch (err) {
        return json(res, err.statusCode || 400, { code: "validation_error", error: err.message });
      }
      if (raw.length) {
        try {
          req.body = JSON.parse(raw.toString("utf8"));
        } catch (err) {
          return json(res, 400, { code: "validation_error", error: "Body is not valid JSON" });
        }
        if (!req.body || Array.isArray(req.body) || typeof req.body !== "object") {
          return json(res, 400, { code: "validation_error", error: "Body must be a JSON object" });
        }
      } else {
        req.body = {};
      }
    }

    const handled = await getApi().router.handle(req, res, relative || "/");
    if (!handled) json(res, 404, { code: "not_found", error: "Not found" });
  };
}

async function start() {
  const redisClient = redis.createClient({ url: process.env.MEET_REDIS_URL || undefined });
  redisClient.on("error", (err) => logger.error("Redis error: " + safeError(err)));
  await redisClient.connect();

  let api = null;
  if (MEET_ENABLED) {
    try {
      api = await initMeetApi(redisClient);
      logger.info("control plane enabled at /meet/api/v1");
    } catch (err) {
      logger.error("control plane failed closed: " + safeError(err));
    }
  } else {
    logger.info("control plane disabled");
  }

  const server = http.createServer(createHttpHandler(() => api));
  server.requestTimeout = 130000;
  server.headersTimeout = 10000;
  server.keepAliveTimeout = 5000;
  server.listen(PORT, HOST, () => logger.info("listening on " + HOST + ":" + PORT));

  const close = async function () {
    server.close();
    if (api && api.presence) await api.presence.stop();
    if (api && api.recorder) await api.recorder.close();
    await redisClient.quit();
  };
  process.once("SIGTERM", close);
  process.once("SIGINT", close);
  return { server: server, redis: redisClient, api: api, close: close };
}

if (require.main === module) {
  start().catch((err) => {
    logger.error("startup failed: " + safeError(err));
    process.exitCode = 1;
  });
}

module.exports = {
  start: start,
  createHttpHandler: createHttpHandler,
  callbackAuthenticator: callbackAuthenticator,
  redact: redact,
};
