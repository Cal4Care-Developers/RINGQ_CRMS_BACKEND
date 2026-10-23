'use strict';
// [server-mode] Per-service run mode for the pm2 node services.
// production silences all console output (the service's pm2 out and error logs) after one
// startup line; crash stack traces are still written. debug, the default, logs everything.
// RINGQ_<NAME>_MODE (e.g. RINGQ_QUEUE_MODE) wins over the shared RINGQ_SERVER_MODE, and a
// --production / --debug argument wins over both. Values come from the pm2 environment, then
// /etc/ringq/.env, which is only read here: nothing is added to process.env, so services that
// keep their own config (Meet) or never loaded the shared file are unaffected.
const fs = require('fs');
const dotenv = require('dotenv');

const pick = (v) => {
  const s = String(v || '').trim().toLowerCase();
  return s === 'production' || s === 'debug' ? s : '';
};

let shared = {};
try { shared = dotenv.parse(fs.readFileSync('/etc/ringq/.env')); } catch { /* unreadable: env only */ }
const setting = (key) => pick(process.env[key]) || pick(shared[key]);

module.exports = function serverMode(name) {
  const mode = process.argv.includes('--production') ? 'production'
    : process.argv.includes('--debug') ? 'debug'
    : setting(`RINGQ_${name.toUpperCase()}_MODE`) || setting('RINGQ_SERVER_MODE') || 'debug';
  console.log(`[server-mode] ${name}: ${mode}`);
  if (mode === 'production') {
    const silent = () => {};
    for (const m of ['log', 'info', 'debug', 'dir', 'table', 'trace', 'warn', 'error']) console[m] = silent;
  }
  return mode;
};
