/**
 * Tenant resolution for RingQ Meet.
 *
 * RingQ is multi-tenant on two levels and BOTH must scope every meet operation:
 *
 *   1. the tenant server  — its subdomain, e.g. "station.ind1.ringq.ai"
 *   2. the FusionPBX domain within that server — domain_uuid
 *
 * The legacy meet ignored both: `rooms` was one global Redis list matched on
 * room_name/owner with no domain filter, so two domains on one box shared a
 * namespace (see docs/MEET-DATA-MODEL.md). Everything here exists to make that
 * impossible: callers get a tenantKey or an exception, never a default.
 *
 * Runtime: CommonJS on Node.js 24 LTS.
 */

const crypto = require("crypto");

/** Thrown when a request cannot be attributed to exactly one tenant. */
class TenantResolutionError extends Error {
  constructor(message, code) {
    super(message);
    this.name = "TenantResolutionError";
    this.code = code || "TENANT_UNRESOLVED";
  }
}

/**
 * Strip port and lowercase. Hosts arrive as "example.com:8443" from proxies and
 * as bare hostnames elsewhere; the tenantKey must not differ between the two.
 */
function normaliseHost(host) {
  if (typeof host !== "string") return "";
  let h = host.trim().toLowerCase();
  if (!h) return "";
  // IPv6 literal: [::1]:443
  if (h.charAt(0) === "[") {
    const close = h.indexOf("]");
    return close === -1 ? h : h.slice(0, close + 1);
  }
  const colon = h.indexOf(":");
  return colon === -1 ? h : h.slice(0, colon);
}

/**
 * Derive the opaque per-tenant identifier.
 *
 * Deterministic so provisioning is idempotent (the SFU key map is rendered from
 * it), and opaque so no customer hostname leaks into LiveKit logs or room names.
 *
 * Canonical format: uppercase `LKRQ` plus 12 lowercase hexadecimal characters.
 * The gateway validates this exact shape before any registry lookup.
 */
function deriveTenantKey(subdomain, domainUuid) {
  const host = normaliseHost(subdomain);
  if (!host) {
    throw new TenantResolutionError("Cannot derive tenantKey: empty host", "TENANT_NO_HOST");
  }
  if (!domainUuid) {
    throw new TenantResolutionError(
      "Cannot derive tenantKey: missing domain_uuid",
      "TENANT_NO_DOMAIN"
    );
  }
  const material = host + ":" + String(domainUuid).trim().toLowerCase();
  const digest = crypto.createHash("sha256").update(material, "utf8").digest("hex");
  return "LKRQ" + digest.slice(0, 12);
}

/**
 * Redis/Postgres namespace for a tenant. Every meet key is built from this so a
 * missing prefix is a syntax error rather than a silent cross-tenant read.
 */
function keyPrefix(tenantKey) {
  if (!tenantKey) {
    throw new TenantResolutionError("keyPrefix requires a tenantKey", "TENANT_NO_KEY");
  }
  return "meet:" + tenantKey + ":";
}

/**
 * Per-tenant config resolver with a short positive cache.
 *
 * `loadConfig(host)` is injected rather than imported so this module stays
 * testable and so the source of truth can move (env today, PHP session lookup in
 * T2.4) without touching callers.
 *
 * Only successful resolutions are cached. Caching failures would mean a tenant
 * stays broken for the whole TTL after a transient PHP hiccup.
 */
class TenantResolver {
  /**
   * @param {object} opts
   * @param {(host: string) => object|null} opts.loadConfig returns
   *   { domainUuid } or null/undefined if unknown
   * @param {number} [opts.ttlMs=30000] positive-cache lifetime
   * @param {() => number} [opts.now] injectable clock for tests
   */
  constructor(opts) {
    const options = opts || {};
    if (typeof options.loadConfig !== "function") {
      throw new TypeError("TenantResolver requires a loadConfig function");
    }
    this._loadConfig = options.loadConfig;
    this._ttlMs = typeof options.ttlMs === "number" ? options.ttlMs : 30000;
    this._now = options.now || Date.now;
    this._cache = new Map();
  }

  /**
   * Resolve a tenant from a request-ish object.
   *
   * Accepts an http.IncomingMessage or a bare { host } object.
   *
   * ASYNC because `loadConfig` may need to ask PHP for the FusionPBX
   * domain_uuid behind a host. That lookup is what lets ONE box serve several
   * domains; keeping this synchronous would have pinned every server to a single
   * tenant, which is the limitation the whole rework exists to remove.
   *
   * @returns {Promise<object>} frozen tenant record
   * @throws {TenantResolutionError} on an unknown or ambiguous host. Never falls
   *   back to a default tenant: a wrong tenant is worse than a failed request.
   */
  async resolve(req) {
    const host = this.hostOf(req);
    if (!host) {
      throw new TenantResolutionError("Request carries no Host header", "TENANT_NO_HOST");
    }

    const cached = this._cache.get(host);
    if (cached && cached.expiresAt > this._now()) {
      return cached.tenant;
    }

    // Accepts a plain value or a promise, so an env-only deployment stays simple.
    const config = await this._loadConfig(host);
    if (!config) {
      throw new TenantResolutionError("Unknown meet tenant for host: " + host, "TENANT_UNKNOWN");
    }

    const missing = [];
    if (!config.domainUuid) missing.push("domainUuid");
    if (missing.length) {
      // Fail loudly rather than mint tokens the SFU will reject with a 401 —
      // that failure mode cost the previous stack real debugging time.
      throw new TenantResolutionError(
        "Incomplete meet config for host " + host + "; missing: " + missing.join(", "),
        "TENANT_INCOMPLETE_CONFIG"
      );
    }

    const tenantKey = deriveTenantKey(host, config.domainUuid);
    const tenant = Object.freeze({
      subdomain: host,
      domainUuid: String(config.domainUuid).trim().toLowerCase(),
      tenantKey: tenantKey,
      keyPrefix: keyPrefix(tenantKey),
    });

    this._cache.set(host, { tenant: tenant, expiresAt: this._now() + this._ttlMs });
    return tenant;
  }

  /** Extract the host from the several request shapes this service sees. */
  hostOf(req) {
    if (!req) return "";
    if (typeof req === "string") return normaliseHost(req);
    if (req.host) return normaliseHost(req.host);
    const headers = req.headers || (req.handshake && req.handshake.headers);
    if (!headers) return "";
    // X-Forwarded-Host is set by the tenant's own nginx; Host is the direct case.
    // Both are proxy-controlled, never client-controlled, on this path: node
    // listens on 127.0.0.1 only.
    return normaliseHost(headers["x-forwarded-host"] || headers.host || "");
  }

  /** Drop a cached tenant (call after a config change / key rotation). */
  invalidate(host) {
    if (host) this._cache.delete(normaliseHost(host));
    else this._cache.clear();
  }
}

module.exports = {
  TenantResolver: TenantResolver,
  TenantResolutionError: TenantResolutionError,
  deriveTenantKey: deriveTenantKey,
  keyPrefix: keyPrefix,
  normaliseHost: normaliseHost,
};
