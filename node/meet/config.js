/**
 * Per-tenant meet configuration — the `loadConfig` behind TenantResolver.
 *
 * Split from tenant.js so that module stays pure and unit-testable while the
 * network/IO lives here.
 *
 * Two sources, deliberately:
 *
 *   identity <- PHP (/meetApi/tenantMeetConfig), which owns v_domains and can
 *               therefore map a Host header to a FusionPBX domain_uuid. This is
 *               what lets ONE box serve SEVERAL domains — the limitation the
 *               rework exists to remove.
 *
 * LiveKit credentials are deliberately absent. The separate gateway client is
 * authenticated with mTLS and is the only tenant-side media dependency.
 *
 * Runtime: CommonJS on Node.js 24 LTS.
 */

/**
 * @param {object} opts
 * @param {object} [opts.env=process.env]
 * @param {(url, body, headers) => Promise<{status, data}>} [opts.httpPost] injected for tests
 * @param {object} [opts.logger]
 * @returns {(host: string) => Promise<object|null>}
 */
function createConfigLoader(opts) {
  const o = opts || {};
  const env = o.env || process.env;
  const log = o.logger || { info: function () {}, warn: function () {}, error: function () {} };
  const post = o.httpPost || defaultPhpPost;
  const phpBase = o.phpBase || env.MEET_PHP_BASE || "https://127.0.0.1:8443";
  const phpPath = o.phpPath || env.MEET_TENANT_CONFIG_PATH || "/meetApi/tenantMeetConfig";

  // Optional allow-list. When set, only these hosts may resolve at all.
  const allowedHosts = String(env.MEET_TENANT_HOST || "")
    .split(",")
    .map(function (h) { return h.trim().toLowerCase(); })
    .filter(Boolean);

  return async function loadConfig(host) {
    if (allowedHosts.length && allowedHosts.indexOf(host) === -1) {
      log.warn("meet: host not in MEET_TENANT_HOST allow-list: " + host);
      return null;
    }

    let domainUuid = "";
    try {
      const res = await post(phpBase + phpPath, { host: host }, { "Content-Type": "application/json" });
      const hasTenantIdentity = res && res.data && res.data.result === true && res.data.domainUuid;
      if (hasTenantIdentity && res.status >= 200 && res.status < 400) {
        // Some legacy RingQ installations run an ionCube ACL build that leaves
        // `Location: /index` and status 302 on the response even though the
        // loopback-only controller executed and returned its valid JSON body.
        // We never follow that redirect. Accept only the controller's explicit
        // positive identity payload; an ACL redirect containing HTML (or no
        // domainUuid) still fails closed below.
        if (res.status >= 300) {
          log.warn("meet: tenantMeetConfig returned a legacy redirect with a valid tenant identity");
        }
        domainUuid = String(res.data.domainUuid);
      } else if (res && res.status === 200 && res.data && res.data.result === false) {
        // PHP answered clearly: this host is not a tenant domain here. Trust it
        // and refuse, rather than silently falling back to another domain's uuid.
        log.warn("meet: PHP does not recognise meet host " + host + " (" + (res.data.message || "") + ")");
        return null;
      } else if (res && res.status >= 300 && res.status < 400) {
        // Almost always a missing ACL entry for the meetApi controller.
        log.error(
          "meet: tenantMeetConfig redirected (HTTP " + res.status + "). The meetApi " +
            "controller is probably not in the ACL in app/config/services.php."
        );
      } else {
        log.warn("meet: tenantMeetConfig returned status " + (res && res.status));
      }
    } catch (err) {
      log.warn("meet: tenantMeetConfig unreachable (" + err.message + ")");
    }

    if (!domainUuid) return null;

    return {
      domainUuid: domainUuid,
    };
  };
}

function defaultPhpPost(url, body, headers) {
  const https = require("https");
  const axios = require("axios");
  // The :8443 vhost presents the tenant's public certificate, which cannot match
  // 127.0.0.1 — same reason signal.js and the legacy relay disable verification
  // on this loopback hop.
  const agent = new https.Agent({ rejectUnauthorized: false });
  return axios
    .post(url, body, {
      headers: headers,
      httpsAgent: agent,
      timeout: 5000,
      validateStatus: null,
      // Do NOT follow redirects. PHP's ACL guard 302s unauthorised requests to
      // /index; following that returned a 200 page of HTML, which looked like a
      // parse failure and silently fell back to MEET_DOMAIN_UUID. A redirect
      // here means misconfiguration and must surface as one.
      maxRedirects: 0,
    })
    .then(function (res) {
      return { status: res.status, data: res.data };
    });
}

module.exports = { createConfigLoader: createConfigLoader };
