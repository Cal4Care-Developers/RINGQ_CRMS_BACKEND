const crypto = require("crypto");

/** Trust only a verification header explicitly configured and overwritten by Nginx. */
function callbackAuthenticator(env) {
  const e = env || process.env;
  const header = String(e.SFU_GATEWAY_CALLBACK_VERIFY_HEADER || "").toLowerCase();
  const expected = String(e.SFU_GATEWAY_CALLBACK_VERIFY_VALUE || "");
  if (!header || !expected || !/^[a-z0-9-]+$/.test(header)) {
    return function denyUnconfiguredCallback() { return false; };
  }
  return function verify(req) {
    const supplied = String((req.headers && req.headers[header]) || "");
    const a = Buffer.from(supplied);
    const b = Buffer.from(expected);
    return a.length === b.length && a.length > 0 && crypto.timingSafeEqual(a, b);
  };
}

module.exports = { callbackAuthenticator: callbackAuthenticator };
