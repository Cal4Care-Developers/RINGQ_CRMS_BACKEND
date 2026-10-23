/**
 * Authentication for the meet control plane.
 *
 * Two callers, two schemes (see docs/MEET-API-CONTRACT.md):
 *
 *   host / moderator  — a 15-minute opaque RingQ bearer minted by PHP after
 *                       normal login. The public control plane never accepts a
 *                       RingQ session cookie directly.
 *   guest             — a signed bearer token this service mints when a guest
 *                       asks to join, redeemed for a LiveKit token once admitted.
 *
 * Guest tokens are HMAC-signed so tampering is detectable without a lookup, AND
 * recorded in Redis so admit/decline decisions and revocation are authoritative.
 * Signature alone would be un-revocable; Redis alone would let a forged id be
 * probed. Both together give integrity plus control.
 *
 * Runtime: CommonJS on Node.js 24 LTS.
 */

const crypto = require("crypto");

class AuthError extends Error {
  constructor(message, code, statusCode) {
    super(message);
    this.name = "AuthError";
    this.code = code || "AUTH_ERROR";
    this.statusCode = statusCode || 401;
  }
}

function opaqueTokenHash(token) {
  return crypto.createHash("sha256").update(String(token || ""), "utf8").digest("hex");
}

/** Longest avatar URL accepted. A real one is ~60 characters. */
const AVATAR_URL_MAX = 300;

/**
 * Normalise a participant's profile photo URL.
 *
 * The value reaches us from our own PHP over loopback Redis, but by the time it
 * is used it has been broadcast to every participant in the room — anonymous
 * guests included — and set as an <img src> in their browsers. So it is
 * validated here, at the boundary, rather than trusted for its provenance.
 *
 * https only, deliberately:
 *   - `http:` would be a mixed-content block on an https meeting page, i.e. a
 *     silently missing photo,
 *   - `data:` would let an arbitrary payload ride the roster,
 *   - `//host/x` is scheme-relative and resolves against the *meeting page's*
 *     origin, so it is not the URL it appears to be.
 *
 * Anything unacceptable becomes "" rather than an error: a malformed photo must
 * never fail a join, and "" is the same well-tested path as a user who simply
 * has no photo.
 */
function normaliseAvatarUrl(value) {
  if (typeof value !== "string") return "";
  const url = value.trim();
  if (!url) return "";
  if (url.length > AVATAR_URL_MAX) return "";
  if (url.slice(0, 8).toLowerCase() !== "https://") return "";
  return url;
}

class OpaqueAccessTokenStore {
  constructor(opts) {
    const o = opts || {};
    if (!o.redis) throw new TypeError("OpaqueAccessTokenStore requires redis");
    this.redis = o.redis;
    this.prefix = o.prefix || "meet:access:";
  }

  async lookup(token) {
    if (!token || String(token).length < 32 || String(token).length > 256) return null;
    const raw = await this.redis.get(this.prefix + opaqueTokenHash(token));
    if (!raw) return null;
    try {
      const value = JSON.parse(raw);
      if (!value || !value.ownerKey || !value.domainUuid) return null;
      return value;
    } catch (err) {
      return null;
    }
  }

  revoke(token) {
    if (!token) return Promise.resolve(0);
    return this.redis.del(this.prefix + opaqueTokenHash(token));
  }
}

class InviteStore {
  constructor(opts) {
    const o = opts || {};
    if (!o.redis) throw new TypeError("InviteStore requires redis");
    this.redis = o.redis;
    this.prefix = o.prefix || "meet:invite:";
  }

  async lookup(token) {
    if (typeof token !== "string") return null;
    const value = token.trim();
    if (value.length < 32 || value.length > 256) return null;
    const raw = await this.redis.get(this.prefix + opaqueTokenHash(value));
    if (!raw) return null;
    try {
      const record = JSON.parse(raw);
      if (!record || record.role !== "cohost" || !record.domainUuid || !record.externalReference) return null;
      return record;
    } catch (err) {
      return null;
    }
  }
}

// ───────────────────────── guest tokens ─────────────────────────

function b64url(buf) {
  return Buffer.from(buf).toString("base64").replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

/**
 * Mint an opaque guest token: base64url(payload).base64url(hmac).
 *
 * The contract only requires an opaque string, so this stays deliberately
 * simple — not a JWT, because nothing should be tempted to trust its claims
 * without the Redis record.
 */
function createGuestToken(secret, payload) {
  if (!secret) throw new AuthError("guest token secret is not configured", "AUTH_NO_SECRET", 500);
  const body = b64url(JSON.stringify(payload || {}));
  const nonce = crypto.randomBytes(9).toString("hex");
  const signed = body + "." + nonce;
  const mac = crypto.createHmac("sha256", secret).update(signed).digest();
  return signed + "." + b64url(mac);
}

/** Verify a guest token's integrity and return its payload, or throw. */
function verifyGuestToken(secret, token) {
  if (!secret) throw new AuthError("guest token secret is not configured", "AUTH_NO_SECRET", 500);
  if (typeof token !== "string" || !token) {
    throw new AuthError("missing guest token", "AUTH_NO_GUEST_TOKEN", 401);
  }
  const parts = token.split(".");
  if (parts.length !== 3) throw new AuthError("malformed guest token", "AUTH_BAD_GUEST_TOKEN", 401);
  const expected = crypto.createHmac("sha256", secret).update(parts[0] + "." + parts[1]).digest();
  const actual = Buffer.from(parts[2].replace(/-/g, "+").replace(/_/g, "/"), "base64");
  if (expected.length !== actual.length || !crypto.timingSafeEqual(expected, actual)) {
    throw new AuthError("guest token signature mismatch", "AUTH_BAD_GUEST_TOKEN", 401);
  }
  try {
    return JSON.parse(Buffer.from(parts[0].replace(/-/g, "+").replace(/_/g, "/"), "base64").toString("utf8"));
  } catch (err) {
    throw new AuthError("unreadable guest token", "AUTH_BAD_GUEST_TOKEN", 401);
  }
}

/** Extract a bearer token from an Authorization header. */
function bearerOf(headers) {
  const raw = (headers && (headers.authorization || headers.Authorization)) || "";
  const m = /^Bearer\s+(.+)$/i.exec(String(raw).trim());
  return m ? m[1].trim() : "";
}

// ───────────────────────── host credentials ─────────────────────────

/**
 * Validates the short-lived opaque bearer. Session cookies and developer
 * bypass headers are intentionally unsupported by this service.
 */
class HostAuthenticator {
  /**
   * @param {object} opts
   * @param {(token: string) => Promise<object|null>} opts.lookupBearer
   *   resolves { ownerKey, displayName, email, avatarUrl, domainUuid, canHost } or null
   */
  constructor(opts) {
    const o = opts || {};
    if (typeof o.lookupBearer !== "function") {
      throw new TypeError("HostAuthenticator requires lookupBearer");
    }
    this._lookupBearer = o.lookupBearer;
  }

  /**
   * Resolve the host behind a request.
   * @throws {AuthError} 401 when unauthenticated, 403 when not allowed to host
   */
  async requireHost(req, tenant) {
    const headers = (req && (req.headers || (req.handshake && req.handshake.headers))) || {};

    const bearer = bearerOf(headers);
    if (bearer) {
      const result = await this._lookupBearer(bearer);
      if (!result) throw new AuthError("access token is invalid or expired", "AUTH_INVALID_BEARER", 401);
      return this._normaliseHost(result, tenant, "bearer");
    }
    throw new AuthError("opaque access token required", "AUTH_BEARER_REQUIRED", 401);
  }

  /**
   * Who is behind this request, when the answer is allowed to be "nobody".
   *
   * `requireHost` is the wrong tool for an optional credential twice over: it
   * throws when there is no bearer, and it throws 403 when the caller may not
   * *host* — but a user on a package that cannot host can still join a
   * colleague's meeting, and is exactly the person whose photo we want.
   *
   * Returns identity only. Nothing this resolves may grant anything; it is used
   * to decorate a request, never to authorise one.
   */
  async resolveIdentity(req, tenant) {
    const headers = (req && (req.headers || (req.handshake && req.handshake.headers))) || {};
    const bearer = bearerOf(headers);
    if (!bearer) return null;
    let result = null;
    try {
      result = await this._lookupBearer(bearer);
    } catch (err) {
      return null;
    }
    if (!result || !result.ownerKey) return null;
    if (result.domainUuid && tenant && tenant.domainUuid
        && String(result.domainUuid).toLowerCase() !== tenant.domainUuid) {
      return null;
    }
    return {
      ownerKey: String(result.ownerKey),
      displayName: result.displayName || "",
      avatarUrl: normaliseAvatarUrl(result.avatarUrl),
    };
  }

  _normaliseHost(result, tenant, via) {
    if (result.canHost === false) {
      throw new AuthError("not permitted to host meetings", "AUTH_CANNOT_HOST", 403);
    }
    if (!result.ownerKey) {
      throw new AuthError("credential carries no ownerKey", "AUTH_INVALID_BEARER", 401);
    }
    if (result.domainUuid && tenant.domainUuid && String(result.domainUuid).toLowerCase() !== tenant.domainUuid) {
      throw new AuthError("credential belongs to another domain", "AUTH_WRONG_DOMAIN", 403);
    }
    return {
      ownerKey: String(result.ownerKey),
      displayName: result.displayName || String(result.ownerKey),
      email: result.email || "",
      // This object is a whitelist: it is rebuilt field by field, so a key the
      // PHP record carries but this function does not name is discarded here
      // with no error anywhere. Adding the photo to the token without adding it
      // here ships the whole feature as a silent no-op.
      avatarUrl: normaliseAvatarUrl(result.avatarUrl),
      domainUuid: result.domainUuid || tenant.domainUuid,
      canHost: true,
      permissions: result.permissions || { host: true },
      via: via,
    };
  }

}

module.exports = {
  AuthError: AuthError,
  HostAuthenticator: HostAuthenticator,
  createGuestToken: createGuestToken,
  verifyGuestToken: verifyGuestToken,
  bearerOf: bearerOf,
  normaliseAvatarUrl: normaliseAvatarUrl,
  opaqueTokenHash: opaqueTokenHash,
  OpaqueAccessTokenStore: OpaqueAccessTokenStore,
  InviteStore: InviteStore,
};
