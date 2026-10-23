/** Small bounded in-memory limiter for public Meet endpoints. */

class RateLimiter {
  constructor(opts) {
    const o = opts || {};
    this.windowMs = Number(o.windowMs || 60000);
    this.maxKeys = Number(o.maxKeys || 10000);
    this._now = o.now || Date.now;
    this._entries = new Map();
  }

  consume(key, limit, weight) {
    const now = this._now();
    let entry = this._entries.get(key);
    if (!entry || entry.resetAt <= now) entry = { count: 0, resetAt: now + this.windowMs };
    entry.count += Math.max(1, Number(weight) || 1);
    this._entries.set(key, entry);
    if (this._entries.size > this.maxKeys) this._prune(now);
    return {
      allowed: entry.count <= limit,
      remaining: Math.max(0, limit - entry.count),
      retryAfterSeconds: Math.max(1, Math.ceil((entry.resetAt - now) / 1000)),
    };
  }

  _prune(now) {
    for (const pair of this._entries) {
      if (pair[1].resetAt <= now || this._entries.size > this.maxKeys) this._entries.delete(pair[0]);
      if (this._entries.size <= this.maxKeys) break;
    }
  }
}

function clientAddress(req) {
  const headers = (req && req.headers) || {};
  return String(headers["x-real-ip"] || (req && req.socket && req.socket.remoteAddress) || "unknown").slice(0, 80);
}

module.exports = { RateLimiter: RateLimiter, clientAddress: clientAddress };
