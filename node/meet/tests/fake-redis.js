/**
 * Minimal in-memory stand-in for a node-redis v4 client.
 *
 * Covers only what RoomStore uses, with the same camelCase promise API, so unit
 * tests need no Redis server. TTLs are
 * tracked as absolute expiry against an injectable clock, which is what lets the
 * "ghosts expire without a sweep loop" test run instantly instead of sleeping.
 */

function FakeRedis(opts) {
  const o = opts || {};
  this.store = new Map(); // key -> { type: 'string'|'hash', value }
  this.expiries = new Map(); // key -> absolute ms
  this._now = o.now || Date.now;
  this.commandCount = 0;
}

FakeRedis.prototype._gc = function (key) {
  const exp = this.expiries.get(key);
  if (exp !== undefined && exp <= this._now()) {
    this.store.delete(key);
    this.expiries.delete(key);
    return true;
  }
  return false;
};

FakeRedis.prototype._hash = function (key) {
  this._gc(key);
  let entry = this.store.get(key);
  if (!entry) {
    entry = { type: "hash", value: new Map() };
    this.store.set(key, entry);
  }
  if (entry.type !== "hash") throw new Error("WRONGTYPE " + key);
  return entry.value;
};

FakeRedis.prototype.hSet = function (key, fieldOrObj, maybeValue) {
  this.commandCount++;
  const h = this._hash(key);
  if (typeof fieldOrObj === "object" && fieldOrObj !== null) {
    const self = this;
    Object.keys(fieldOrObj).forEach(function (k) {
      h.set(k, String(fieldOrObj[k]));
    });
    void self;
  } else {
    h.set(String(fieldOrObj), String(maybeValue));
  }
  return Promise.resolve(1);
};

FakeRedis.prototype.hSetNX = function (key, field, value) {
  this.commandCount++;
  const h = this._hash(key);
  const name = String(field);
  if (h.has(name)) return Promise.resolve(0);
  h.set(name, String(value));
  return Promise.resolve(1);
};

/**
 * Atomic counter inside a hash. Real Redis returns the value AFTER the
 * increment, so the first call on a fresh field yields 1 — the hand queue's
 * ordering depends on that, since a returned 0 would collide with "unset".
 */
FakeRedis.prototype.hIncrBy = function (key, field, by) {
  this.commandCount++;
  const h = this._hash(key);
  const name = String(field);
  const next = (Number(h.get(name)) || 0) + Number(by);
  h.set(name, String(next));
  return Promise.resolve(next);
};

FakeRedis.prototype.hGetAll = function (key) {
  this.commandCount++;
  this._gc(key);
  const entry = this.store.get(key);
  if (!entry || entry.type !== "hash") return Promise.resolve({});
  const out = {};
  entry.value.forEach(function (v, k) {
    out[k] = v;
  });
  return Promise.resolve(out);
};

FakeRedis.prototype.hGet = function (key, field) {
  this.commandCount++;
  this._gc(key);
  const entry = this.store.get(key);
  if (!entry || entry.type !== "hash") return Promise.resolve(null);
  return Promise.resolve(entry.value.has(String(field)) ? entry.value.get(String(field)) : null);
};

/** node-redis returns one entry per requested field, null where absent. */
FakeRedis.prototype.hmGet = function (key, fields) {
  this.commandCount++;
  this._gc(key);
  const entry = this.store.get(key);
  const list = Array.isArray(fields) ? fields : [fields];
  return Promise.resolve(
    list.map(function (field) {
      if (!entry || entry.type !== "hash") return null;
      const name = String(field);
      return entry.value.has(name) ? entry.value.get(name) : null;
    })
  );
};

/** Accepts one field or an array of them, as the real client does. */
FakeRedis.prototype.hDel = function (key, field) {
  this.commandCount++;
  this._gc(key);
  const entry = this.store.get(key);
  if (!entry || entry.type !== "hash") return Promise.resolve(0);
  const fields = Array.isArray(field) ? field : [field];
  let removed = 0;
  fields.forEach(function (name) {
    if (entry.value.delete(String(name))) removed++;
  });
  return Promise.resolve(removed);
};

FakeRedis.prototype.hLen = function (key) {
  this.commandCount++;
  this._gc(key);
  const entry = this.store.get(key);
  return Promise.resolve(entry && entry.type === "hash" ? entry.value.size : 0);
};

FakeRedis.prototype.set = function (key, value, opts) {
  this.commandCount++;
  this._gc(key);
  if (opts && opts.NX && this.store.has(key)) return Promise.resolve(null);
  this.store.set(key, { type: "string", value: String(value) });
  if (opts && opts.EX) this.expiries.set(key, this._now() + opts.EX * 1000);
  else this.expiries.delete(key);
  return Promise.resolve("OK");
};

FakeRedis.prototype.get = function (key) {
  this.commandCount++;
  this._gc(key);
  const entry = this.store.get(key);
  return Promise.resolve(entry && entry.type === "string" ? entry.value : null);
};

FakeRedis.prototype.del = function (key) {
  this.commandCount++;
  this._gc(key);
  const existed = this.store.delete(key);
  this.expiries.delete(key);
  return Promise.resolve(existed ? 1 : 0);
};

FakeRedis.prototype.exists = function (key) {
  this.commandCount++;
  this._gc(key);
  return Promise.resolve(this.store.has(key) ? 1 : 0);
};

FakeRedis.prototype.expire = function (key, seconds) {
  this.commandCount++;
  this._gc(key);
  if (!this.store.has(key)) return Promise.resolve(0);
  this.expiries.set(key, this._now() + seconds * 1000);
  return Promise.resolve(1);
};

/** Queued pipeline with the same shape as redis v4's multi()/exec(). */
FakeRedis.prototype.multi = function () {
  const self = this;
  const queue = [];
  const chain = {
    hSet: function () {
      queue.push(["hSet", arguments]);
      return chain;
    },
    hSetNX: function () {
      queue.push(["hSetNX", arguments]);
      return chain;
    },
    hDel: function () {
      queue.push(["hDel", arguments]);
      return chain;
    },
    set: function () {
      queue.push(["set", arguments]);
      return chain;
    },
    del: function () {
      queue.push(["del", arguments]);
      return chain;
    },
    expire: function () {
      queue.push(["expire", arguments]);
      return chain;
    },
    exec: function () {
      const results = [];
      let p = Promise.resolve();
      queue.forEach(function (op) {
        p = p.then(function () {
          return FakeRedis.prototype[op[0]].apply(self, op[1]).then(function (r) {
            results.push(r);
          });
        });
      });
      return p.then(function () {
        return results;
      });
    },
  };
  return chain;
};

/** Test helper: how many keys are live right now (after TTL gc). */
FakeRedis.prototype.liveKeyCount = function () {
  const self = this;
  Array.from(this.store.keys()).forEach(function (k) {
    self._gc(k);
  });
  return this.store.size;
};

module.exports = { FakeRedis: FakeRedis };
