'use strict';
// [perf] Production logging without the cost of dumping whole FreeSWITCH events / call lists.
// console.log of a large object (an ESL event has 300+ fields, "show calls" rows, ...) is
// formatted with util.inspect on every call and was most of the services' CPU and log volume
// (server-out.log grew by ~900k lines a day). Big objects are now summarised; strings and small
// values print as before. RINGQ_VERBOSE_LOG=1 in the environment restores the full output.
if (process.env.RINGQ_VERBOSE_LOG !== '1' && !console.__ringqQuiet) {
  const MAX_KEYS = 25;
  const MAX_STR = 2000;
  const summarise = (v) => {
    if (v === null || v === undefined) return v;
    if (typeof v === 'string') return v.length > MAX_STR ? `${v.slice(0, MAX_STR)}... [${v.length} chars]` : v;
    if (typeof v !== 'object') return v;
    if (v instanceof Error) return v;
    if (Array.isArray(v)) return v.length > MAX_KEYS ? `[array of ${v.length}]` : v;
    const big = (o) => {
      if (Array.isArray(o)) return o.length > MAX_KEYS;
      let n = 0;
      for (const k in o) { if (++n > MAX_KEYS) return true; } // eslint-disable-line no-unused-vars
      return false;
    };
    let heavy = big(v);
    if (!heavy) {
      for (const k in v) {
        const x = v[k];
        if (x && typeof x === 'object' && big(x)) { heavy = true; break; }
      }
    }
    if (heavy) {
      const name = (v.constructor && v.constructor.name) || 'object';
      return `[${name} with ${MAX_KEYS}+ fields]`;
    }
    return v;
  };
  const orig = console.log.bind(console);
  console.log = (...args) => orig(...args.map(summarise));
  console.info = console.log;
  console.__ringqQuiet = true;
}
