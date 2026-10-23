/**
 * mTLS client for the central RingQ Meet SFU gateway.
 *
 * Tenant control planes never receive a LiveKit API key or secret. They identify
 * themselves with a client certificate and send only tenant-scoped meeting ids;
 * the gateway constructs the LiveKit room name and mints the five-minute token.
 */

const fs = require("fs");
const https = require("https");

class GatewayError extends Error {
  constructor(message, code, statusCode) {
    super(message);
    this.name = "GatewayError";
    this.code = code || "GATEWAY_UNAVAILABLE";
    this.statusCode = statusCode || 502;
  }
}

function cleanBaseUrl(value, allowInsecure) {
  const raw = String(value || "").trim().replace(/\/+$/, "");
  if (!raw) throw new GatewayError("SFU gateway URL is not configured", "GATEWAY_NOT_CONFIGURED", 503);
  let parsed;
  try {
    parsed = new URL(raw);
  } catch (err) {
    throw new GatewayError("SFU gateway URL is invalid", "GATEWAY_BAD_URL", 503);
  }
  if (parsed.protocol !== "https:" && !allowInsecure) {
    throw new GatewayError("SFU gateway URL must use HTTPS", "GATEWAY_INSECURE_URL", 503);
  }
  return raw;
}

function fileBytes(path, label) {
  if (!path) throw new GatewayError(label + " is not configured", "GATEWAY_MTLS_NOT_CONFIGURED", 503);
  try {
    return fs.readFileSync(path);
  } catch (err) {
    throw new GatewayError("Could not read " + label, "GATEWAY_MTLS_FILE_UNREADABLE", 503);
  }
}

function safeSegment(value, label) {
  const string = String(value || "").trim();
  if (!string || string.length > 160 || !/^[A-Za-z0-9._:-]+$/.test(string)) {
    throw new GatewayError(label + " is invalid", "GATEWAY_BAD_IDENTIFIER", 400);
  }
  return encodeURIComponent(string);
}

function safeTenantKey(value) {
  const string = String(value || "").trim();
  if (!/^LKRQ[0-9a-f]{12}$/.test(string)) {
    throw new GatewayError("tenantKey is invalid", "GATEWAY_BAD_TENANT_KEY", 400);
  }
  return encodeURIComponent(string);
}

class GatewayClient {
  constructor(opts) {
    const o = opts || {};
    this.baseUrl = cleanBaseUrl(o.baseUrl, !!o.allowInsecure);
    this.timeoutMs = Math.max(1000, Math.min(30000, Number(o.timeoutMs || 8000)));
    this._requestFn = o.request || null;
    this._health = null;
    this._healthTtlMs = Number(o.healthTtlMs || 5000);
    this._now = o.now || Date.now;

    if (o.agent) {
      this.agent = o.agent;
    } else if (!o.allowInsecure) {
      this.agent = new https.Agent({
        ca: fileBytes(o.caFile, "SFU_GATEWAY_CA_FILE"),
        cert: fileBytes(o.certFile, "SFU_GATEWAY_CLIENT_CERT_FILE"),
        key: fileBytes(o.keyFile, "SFU_GATEWAY_CLIENT_KEY_FILE"),
        rejectUnauthorized: true,
        minVersion: "TLSv1.2",
        keepAlive: true,
        maxSockets: 16,
      });
    } else {
      this.agent = null;
    }
  }

  async _request(method, path, body, acceptableStatuses) {
    let response;
    try {
      if (this._requestFn) {
        response = await this._requestFn({ method: method, url: this.baseUrl + path, data: body });
      } else {
        const axios = require("axios");
        response = await axios.request({
          method: method,
          url: this.baseUrl + path,
          data: body,
          httpsAgent: this.agent,
          timeout: this.timeoutMs,
          maxRedirects: 0,
          validateStatus: function () { return true; },
          headers: { "Content-Type": "application/json", Accept: "application/json" },
        });
      }
    } catch (err) {
      throw new GatewayError("SFU gateway is unavailable", "GATEWAY_UNAVAILABLE", 503);
    }

    const status = Number(response && response.status);
    const accepted = acceptableStatuses || [200];
    if (accepted.indexOf(status) !== -1) return response.data || {};

    const data = (response && response.data) || {};
    const nested = data.error && typeof data.error === "object" ? data.error : {};
    const remoteCode = typeof nested.code === "string"
      ? nested.code
      : (typeof data.code === "string" ? data.code : "");
    const remoteMessage = typeof nested.message === "string"
      ? nested.message
      : (typeof data.message === "string" ? data.message : "");
    if (status === 401 || status === 403) {
      throw new GatewayError(remoteMessage || "SFU gateway denied this tenant", remoteCode || "GATEWAY_FORBIDDEN", 502);
    }
    if (status === 404) throw new GatewayError(remoteMessage || "Meeting was not found on the media server", remoteCode || "ROOM_NOT_FOUND", 404);
    if (status === 409) throw new GatewayError(remoteMessage || "Meeting has ended", remoteCode || "ROOM_ENDED", 409);
    if (status === 429) throw new GatewayError(remoteMessage || "SFU gateway rate limit reached", remoteCode || "RATE_LIMITED", 429);
    throw new GatewayError(remoteMessage || "SFU gateway request failed", remoteCode || "GATEWAY_BAD_RESPONSE", 502);
  }

  _meetingPath(tenantKey, meetingId) {
    return "/v1/tenants/" + safeTenantKey(tenantKey) + "/meetings/" + safeSegment(meetingId, "meetingId");
  }

  async health() {
    if (this._health && this._health.expiresAt > this._now()) return this._health.value;
    let value;
    try {
      const data = await this._request("GET", "/health/ready", null, [200]);
      value = data.status === "ready" || data.ok === true;
    } catch (err) {
      value = false;
    }
    this._health = { value: value, expiresAt: this._now() + this._healthTtlMs };
    return value;
  }

  mintToken(tenant, meeting) {
    const path = this._meetingPath(tenant.tenantKey, meeting.meetingId) + "/tokens";
    return this._request("POST", path, {
      identity: meeting.identity,
      name: meeting.name,
      role: meeting.role,
      // Omitted when empty, so a participant with no photo — and every request
      // made before this feature existed — sends exactly the body it always did.
      ...(meeting.avatarUrl ? { avatarUrl: meeting.avatarUrl } : {}),
      canPublish: meeting.canPublish !== false,
      canSubscribe: meeting.canSubscribe !== false,
      hidden: meeting.hidden === true,
      // Only sent when the room expressed a policy. An absent list means "no
      // restriction"; an empty one means "publish nothing", and the two must not
      // be confused, so `undefined` is never serialised as `[]`.
      ...(Array.isArray(meeting.publishSources) ? { publishSources: meeting.publishSources } : {}),
    }, [200, 201]);
  }

  /**
   * Replace which sources a joined participant may publish.
   *
   * `publishSources` is an allow-list and is always sent explicitly, including
   * as an empty array — that is what makes a lock unambiguous at the SFU.
   */
  updateParticipantPublishSources(tenant, meetingId, identity, publishSources) {
    return this._request(
      "PATCH",
      this._meetingPath(tenant.tenantKey, meetingId) +
        "/participants/" + safeSegment(identity, "identity") + "/permissions",
      { publishSources: Array.isArray(publishSources) ? publishSources : [] },
      [200, 204]
    );
  }

  /** Change the display name the SFU reports for a joined participant. */
  updateParticipantName(tenant, meetingId, identity, name) {
    return this._request(
      "PATCH",
      this._meetingPath(tenant.tenantKey, meetingId) +
        "/participants/" + safeSegment(identity, "identity") + "/name",
      { name: String(name || "").slice(0, 128) },
      [200, 204]
    );
  }

  listParticipants(tenant, meetingId) {
    return this._request("GET", this._meetingPath(tenant.tenantKey, meetingId) + "/participants", null, [200]);
  }

  removeParticipant(tenant, meetingId, identity) {
    return this._request(
      "DELETE",
      this._meetingPath(tenant.tenantKey, meetingId) + "/participants/" + safeSegment(identity, "identity"),
      null,
      [200, 204]
    );
  }

  updateParticipantRole(tenant, meetingId, identity, role, avatarUrl) {
    // `avatarUrl` rides the role because the SFU rewrites the metadata block
    // whole. It is omitted when empty so the request body is byte-identical to
    // what it has always been for a participant with no photo.
    const body = { role: role };
    if (avatarUrl) body.avatarUrl = avatarUrl;
    return this._request(
      "PATCH",
      this._meetingPath(tenant.tenantKey, meetingId) +
        "/participants/" + safeSegment(identity, "identity") + "/role",
      body,
      [200, 204]
    );
  }

  muteParticipantTrack(tenant, meetingId, identity, source) {
    return this._request(
      "POST",
      this._meetingPath(tenant.tenantKey, meetingId) +
        "/participants/" + safeSegment(identity, "identity") + "/media/mute",
      { source: source },
      [200]
    );
  }

  /**
   * Ask the gateway to deliver a server-authored payload to a meeting.
   *
   * The control plane composes and stamps the payload; participants publish no
   * data of their own (their LiveKit grant sets `canPublishData: false`), so a
   * reaction or raised hand cannot be forged in someone else's name.
   */
  broadcastRoomData(tenant, meetingId, topic, payload, destinationIdentities) {
    let delivery = arguments.length > 5 ? arguments[5] : "reliable";
    return this._request(
      "POST",
      this._meetingPath(tenant.tenantKey, meetingId) + "/data",
      {
        topic: topic,
        payload: payload || {},
        ...(Array.isArray(destinationIdentities) && destinationIdentities.length > 0
          ? { destinationIdentities: destinationIdentities }
          : {}),
        delivery: delivery === "lossy" ? "lossy" : "reliable",
      },
      [200, 202, 204]
    );
  }

  startRecording(tenant, meetingId, recordingId) {
    return this._request(
      "POST",
      this._meetingPath(tenant.tenantKey, meetingId) + "/recordings",
      { recordingId: recordingId, layout: "speaker" },
      [200, 201, 202]
    );
  }

  stopRecording(tenant, meetingId, egressId) {
    return this._request(
      "POST",
      this._meetingPath(tenant.tenantKey, meetingId) + "/recordings/stop",
      { egressId: egressId },
      [200, 202]
    );
  }

  endMeeting(tenant, meetingId) {
    return this._request("DELETE", this._meetingPath(tenant.tenantKey, meetingId), null, [200, 204, 404]);
  }
}

function fromEnv(env) {
  const e = env || process.env;
  return new GatewayClient({
    baseUrl: e.SFU_GATEWAY_URL,
    caFile: e.SFU_GATEWAY_CA_FILE,
    certFile: e.SFU_GATEWAY_CLIENT_CERT_FILE,
    keyFile: e.SFU_GATEWAY_CLIENT_KEY_FILE,
    timeoutMs: e.SFU_GATEWAY_TIMEOUT_MS,
    allowInsecure: String(e.SFU_GATEWAY_ALLOW_INSECURE || "").toLowerCase() === "true",
  });
}

module.exports = { GatewayClient: GatewayClient, GatewayError: GatewayError, fromEnv: fromEnv };
