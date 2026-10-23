/**
 * Compatibility entry point for existing PM2 deployments.
 *
 * The standalone Node 24 service and its locked dependencies live in `meet/`.
 */

const service = require("./meet/server.js");

if (require.main === module) {
  service.start().catch(function (err) {
    console.error("[meet] startup failed: " + service.redact(err && err.message));
    process.exitCode = 1;
  });
}

module.exports = service;
