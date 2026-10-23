/* ================= [outbound-pin] =================
 * Calls dialled through an outbound PIN run the "outbound-pin" dialplan, which
 * exports rule=outbound-pin, so Rule / Route showed just "outbound-pin". This
 * finds the outbound rule the number goes out on and labels the call
 * "O-pin/<rule name>".
 *
 * The outbound rules live in the domain's "outbound-rules" dialplan: one
 * <extension name="<rule name>"> per rule, first match wins. A rule matches
 * when one of its destination_number conditions matches the dialled number
 * and its ${effective_caller_id_number} condition (if any) matches the caller.
 * ================================================== */
const PIN_RULE = 'outbound-pin';
const CACHE_MS = 60000;
const cache = new Map();

const attr = (tag, name) => {
     const m = tag.match(new RegExp(`\\b${name}="([^"]*)"`));
     return m ? m[1] : null;
};
const decode = (s) => String(s).replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&amp;/g, '&');
const test = (expr, value) => {
     try { return new RegExp(decode(expr)).test(String(value ?? '')); } catch (e) { return false; }
};

/** Parse an outbound-rules dialplan XML into [{ name, dest: [regex], caller: [regex] }] in order. */
function parseRules(xml) {
     const rules = [];
     const extRe = /<extension\b([^>]*)>([\s\S]*?)<\/extension>/g;
     let m;
     while ((m = extRe.exec(String(xml || '')))) {
          const name = decode(attr(m[1], 'name') || '').trim();
          if (!name || name === PIN_RULE) continue;
          const rule = { name, dest: [], caller: [] };
          for (const c of m[2].match(/<condition\b[^>]*>/g) || []) {
               const field = attr(c, 'field');
               const expr = attr(c, 'expression');
               if (expr == null) continue;
               if (field === 'destination_number') rule.dest.push(expr);
               else if (field === '${effective_caller_id_number}') rule.caller.push(expr);
          }
          if (rule.dest.length) rules.push(rule);
     }
     return rules;
}

async function rulesFor(db, domainUuid) {
     const c = cache.get(domainUuid);
     if (c && c.exp > Date.now()) return c.v;
     let v = [];
     try {
          const { rows } = await db.query(
               `SELECT dialplan_xml FROM v_dialplans
                 WHERE domain_uuid = $1 AND dialplan_name = 'outbound-rules' AND COALESCE(dialplan_enabled, 'true') = 'true'
                 ORDER BY dialplan_order ASC`, [domainUuid]);
          v = rows.flatMap((r) => parseRules(r.dialplan_xml));
     } catch (e) {
          console.error('[outbound-pin] rules', e?.message || e);
     }
     cache.set(domainUuid, { v, exp: Date.now() + CACHE_MS });
     return v;
}

/** Name of the outbound rule that `dest` dialled by `caller` goes out on, or ''. */
async function matchOutboundRule(db, domainUuid, dest, caller) {
     if (!domainUuid || !dest) return '';
     for (const r of await rulesFor(db, domainUuid)) {
          if (r.caller.some((e) => !test(e, caller))) continue;
          if (r.dest.some((e) => test(e, dest))) return r.name;
     }
     return '';
}

/**
 * Rule / Route label for a call. Non-PIN calls keep their rule as is. A PIN
 * call shows "O-pin/<rule name>", or "O-pin" when no outbound rule matches.
 */
async function pinRuleLabel(db, { domainUuid, rule, dest, caller, pinUsed }) {
     const r = String(rule || '').trim();
     if (r !== PIN_RULE && !pinUsed) return rule;
     const name = r && r !== PIN_RULE ? r : await matchOutboundRule(db, domainUuid, dest, caller);
     return name ? `O-pin/${name}` : 'O-pin';
}

module.exports = { pinRuleLabel, matchOutboundRule, parseRules, PIN_RULE };
