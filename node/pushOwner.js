// [ring-push] Is the new ring push (mod_ringq + ringPush.js in server.js) in charge of a domain?
// For the node processes other than server.js (attended.js, status.js) whose old call-push code
// must stay quiet for those domains, so a phone gets one push per ring, not two. server.js
// publishes what it knows to Redis (ringPush.js): whether mod_ringq is loaded, the default
// ring_push_mode and each domain's mode as it looks them up. Anything missing counts as "not in
// charge", so the old pushes keep working on servers without the new push.
const MOD_KEY = 'ringPush:modLoaded';
const MODE_KEY = (domain) => `ringPush:mode:${domain}`;
const DEFAULT_MODE_KEY = 'ringPush:mode:*';

async function owns(redis, domain) {
  if (!redis || !domain) return false;
  try {
    if ((await redis.get(MOD_KEY)) !== '1') return false;
    const mode = (await redis.get(MODE_KEY(domain))) || (await redis.get(DEFAULT_MODE_KEY)) || 'off';
    return mode === 'wake';
  } catch (e) {
    return false;
  }
}

module.exports = { owns, MOD_KEY, MODE_KEY, DEFAULT_MODE_KEY };
