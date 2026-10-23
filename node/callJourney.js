/* =======================================================================
 * callJourney.js  -- per-call "journey" (landed -> routed -> queue -> agents
 * rung -> answered -> transfers -> ended) for the Call Status page.
 *
 * Storage (Redis, shared by server.js / signal.js / attended.js):
 *   journey:<rootUuid>       list of JSON steps {t, step, ...}   (TTL 2 days)
 *   journeyRoot:<legUuid>    rootUuid for agent / consult legs  (TTL 2 days)
 * The root is the caller's own leg (the customer call).
 *
 * All functions are best-effort: they never throw, so call handling is never
 * affected by journey recording.
 * ========================================================================== */
'use strict';

const TTL = 2 * 24 * 3600;
const MAX_STEPS = 60;
let redis = null;

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const isUuid = (u) => typeof u === 'string' && UUID_RE.test(u);

function init(redisClient) {
  redis = redisClient;
}

async function rootOf(uuid) {
  if (!redis || !isUuid(uuid)) return uuid || null;
  try {
    const r = await redis.get(`journeyRoot:${uuid}`);
    return r || uuid;
  } catch (e) {
    return uuid;
  }
}

async function link(legUuid, rootUuid) {
  if (!redis || !isUuid(legUuid) || !isUuid(rootUuid) || legUuid === rootUuid) return;
  try {
    const root = await rootOf(rootUuid); // follow chains (consult leg of an agent leg)
    if (root === legUuid) return;
    await redis.set(`journeyRoot:${legUuid}`, root, { EX: TTL });
    await redis.sAdd(`journeyLegs:${root}`, legUuid);
    await redis.expire(`journeyLegs:${root}`, TTL);
  } catch (e) { /* ignore */ }
}

async function add(uuid, step, data = {}, opts = {}) {
  if (!redis || !isUuid(uuid)) return;
  try {
    const root = opts.isRoot ? uuid : await rootOf(uuid);
    const key = `journey:${root}`;
    if (opts.onlyIfExists && !(await redis.exists(key))) return;
    const entry = { t: Date.now(), step, ...data };
    await redis.rPush(key, JSON.stringify(entry));
    await redis.lTrim(key, -MAX_STEPS, -1);
    await redis.expire(key, TTL);
  } catch (e) { /* ignore */ }
}

async function has(uuid) {
  if (!redis || !isUuid(uuid)) return false;
  try {
    return (await redis.exists(`journey:${await rootOf(uuid)}`)) === 1;
  } catch (e) {
    return false;
  }
}

async function get(uuid) {
  if (!redis || !isUuid(uuid)) return { root: null, steps: [] };
  try {
    const root = await rootOf(uuid);
    const raw = await redis.lRange(`journey:${root}`, 0, -1);
    const steps = raw.map((r) => { try { return JSON.parse(r); } catch (e) { return null; } }).filter(Boolean);
    steps.sort((a, b) => a.t - b.t);
    return { root, steps };
  } catch (e) {
    return { root: null, steps: [] };
  }
}

/* ---------------- summary + human text ---------------- */

const secs = (ms) => Math.max(0, Math.round(ms / 1000));
const fmtDur = (s) => {
  s = Math.max(0, Math.round(s || 0));
  if (s < 60) return `${s}s`;
  if (s >= 3600) return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
  const m = Math.floor(s / 60);
  const r = s % 60;
  return r ? `${m}m ${r}s` : `${m}m`;
};
const who = (ext, name) => (ext && name && name !== ext ? `${ext} ${name}` : ext || name || '');

const CAUSE_TEXT = {
  NORMAL_CLEARING: 'normal hang-up',
  ORIGINATOR_CANCEL: 'caller cancelled',
  NO_ANSWER: 'no answer',
  NO_USER_RESPONSE: 'no response from phone',
  USER_BUSY: 'busy',
  CALL_REJECTED: 'rejected',
  UNALLOCATED_NUMBER: 'number does not exist',
  USER_NOT_REGISTERED: 'phone not registered',
  SUBSCRIBER_ABSENT: 'phone not registered',
  RECOVERY_ON_TIMER_EXPIRE: 'timed out',
  NORMAL_TEMPORARY_FAILURE: 'temporary network failure',
  LOSE_RACE: 'answered elsewhere',
  PICKED_OFF: 'picked up by someone else',
  MEDIA_TIMEOUT: 'no audio (media timeout)',
  INCOMPATIBLE_DESTINATION: 'codec/destination mismatch',
};
const causeText = (c) => (c ? CAUSE_TEXT[c] || c.toLowerCase().replace(/_/g, ' ') : '');

/** Build summary fields + one-line plain status from the steps. */
function summarize(steps) {
  const s = {
    did: '', from: '', queue_ext: '', queue_name: '', rule: '', route: '',
    answered_by: '', answered_name: '', wait_sec: null, talk_sec: null,
    rang: [], ended_by: '', outcome: '', status_text: '',
  };
  if (!steps || !steps.length) return s;

  const first = steps[0];
  let tQueue = null; let tAnswer = null; let tEnd = null;
  let transferredTo = ''; let transferredBy = ''; let transferType = '';
  let consultFailedTo = ''; let queueExit = null; let ended = null; let lastConsultTo = ''; let lastVia = ''; let consultOpen = null; let faxDir = '';
  const ringingNow = new Map(); // agent ext -> name, rung and not yet failed/answered

  for (const st of steps) {
    switch (st.step) {
      case 'landed':
        s.did = s.did || st.did || '';
        s.from = s.from || st.from_ext || st.from || '';
        if (st.rule) s.rule = String(st.rule).trim();
        if (st.route) s.route = String(st.route).trim();
        break;
      case 'routed':
        if (st.rule) s.rule = st.rule;
        if (st.route) s.route = st.route;
        break;
      case 'queue_enter':
        s.queue_ext = st.queue_ext || s.queue_ext;
        s.queue_name = st.queue_name || s.queue_name;
        if (tQueue == null) tQueue = st.t;
        break;
      case 'agent_ringing':
        if (st.agent_ext && !s.rang.includes(st.agent_ext)) s.rang.push(st.agent_ext);
        if (st.agent_ext) ringingNow.set(st.agent_ext, st.agent_name || '');
        break;
      case 'agent_no_answer':
        if (st.agent_ext) ringingNow.delete(st.agent_ext);
        break;
      case 'answered':
        // keep the latest answer (a requeued caller can be answered by a second agent)
        s.answered_by = st.agent_ext || s.answered_by;
        s.answered_name = (st.agent_name && st.agent_name !== 'Outbound Call' ? st.agent_name : '') || s.answered_name;
        if (tAnswer == null) tAnswer = st.t;
        lastVia = st.via || lastVia;
        ringingNow.clear();
        break;
      case 'queue_exit':
        queueExit = st;
        break;
      case 'attended_start':
        lastConsultTo = st.to || lastConsultTo;
        consultOpen = { by: st.by || '', to: st.to || '', t: st.t };
        break;
      case 'attended_complete':
        consultOpen = null;
        transferredTo = st.to || transferredTo;
        transferredBy = st.by || transferredBy;
        transferType = 'attended';
        break;
      case 'attended_voicemail':
      case 'attended_returned':
        consultOpen = null;
        consultFailedTo = lastConsultTo || st.to || consultFailedTo;
        break;
      case 'blind_transfer':
        transferredTo = st.to || transferredTo;
        transferredBy = st.by || transferredBy;
        transferType = 'blind';
        break;
      case 'ended':
        ended = st;
        tEnd = st.t;
        break;
      case 'fax':
        faxDir = st.direction || faxDir || 'in';
        break;
      default:
        break;
    }
  }

  // agents whose phone is ringing for this call right now (Team Directory blink)
  s.ringing_now = (queueExit || ended) ? [] : [...ringingNow.keys()];
  if (tQueue != null) s.wait_sec = secs((tAnswer || (queueExit && queueExit.t) || tEnd || Date.now()) - tQueue);
  if (tAnswer != null && tEnd != null) s.talk_sec = secs(tEnd - tAnswer);
  if (ended) s.ended_by = ended.by || '';

  const qLabel = s.queue_name ? `${s.queue_name} (${s.queue_ext})` : s.queue_ext ? `queue ${s.queue_ext}` : '';
  const rangTxt = s.rang.length ? ` · rang ${s.rang.join(', ')}` : '';

  // ---- call type, used for the colour code on Call Status / reports ----
  const isOutboundDial = first.step === 'landed' && !first.external && String(first.did || '').replace(/\D/g, '').length > 4;
  if (faxDir || first.fax) s.call_type = 'fax';
  else if (tQueue != null) s.call_type = 'queue';
  else if (isOutboundDial) s.call_type = 'outbound';
  else if (first.external) s.call_type = 'inbound';
  else s.call_type = 'internal';

  if (s.call_type === 'fax') {
    const dir = faxDir || (first.external ? 'in' : 'out');
    const peer = dir === 'in' ? (first.from || '') : (first.did || '');
    if (!ended) {
      s.live = true;
      s.outcome = 'live';
      s.status_text = dir === 'in' ? `Receiving fax from ${peer || 'unknown'}` : `Sending fax to ${peer || 'unknown'}`;
      return s;
    }
    if (ended.fax_success === true) {
      s.outcome = 'answered';
      s.status_text = `Fax ${dir === 'in' ? 'received from' : 'sent to'} ${peer || 'unknown'} · ${ended.fax_pages || 0} page${ended.fax_pages === 1 ? '' : 's'}`;
    } else {
      s.outcome = 'failed';
      const why = ended.fax_result || (ended.cause && ended.cause !== 'NORMAL_CLEARING' ? causeText(ended.cause) : 'no fax data');
      s.status_text = `Fax ${dir === 'in' ? 'receive' : 'send'} failed · ${why}`;
    }
    return s;
  }

  // ---- call still in progress: describe what is happening right now ----
  if (!ended) {
    s.live = true;
    const now = Date.now();
    if (consultOpen) {
      s.outcome = 'live-hold';
      s.status_text = `On hold · ${consultOpen.by || 'agent'} consulting ${consultOpen.to || '?'} · ${fmtDur(secs(now - consultOpen.t))}`;
      return s;
    }
    if (transferredTo) {
      s.outcome = 'live';
      s.status_text = `Transferred${transferType === 'blind' ? ' (blind)' : ''}${transferredBy ? ` by ${transferredBy}` : ''} → ${transferredTo}`;
      return s;
    }
    if (s.answered_by) {
      s.outcome = 'live';
      s.talk_sec = secs(now - tAnswer);
      s.status_text = `Talking with ${who(s.answered_by, s.answered_name)} · ${fmtDur(s.talk_sec)}`;
      return s;
    }
    if (tQueue != null && !queueExit) {
      s.outcome = 'live-wait';
      s.wait_sec = secs(now - tQueue);
      s.status_text = `Waiting in ${qLabel || 'queue'} · ${fmtDur(s.wait_sec)}${s.ringing_now.length ? ` · ringing ${s.ringing_now.join(', ')}` : ''}`;
      return s;
    }
    s.outcome = 'live';
    s.status_text = 'In progress';
    return s;
  }

  // outbound: an internal caller dialling an external number
  const outbound = first.step === 'landed' && !first.external && String(first.did || '').replace(/\D/g, '').length > 4;
  s.direction = outbound ? 'outbound' : (first.external ? 'inbound' : 'internal');
  if (outbound && !transferredTo) {
    const caller = first.from_ext || first.from || '';
    if (s.answered_by) {
      s.outcome = 'answered';
      s.status_text = `Outbound${caller ? ` from ${caller}` : ''} to ${first.did} · answered`;
    } else if (ended) {
      const notAns = ['ORIGINATOR_CANCEL', 'NO_ANSWER', 'NO_USER_RESPONSE', 'USER_BUSY', 'CALL_REJECTED'];
      s.outcome = notAns.includes(ended.cause) || !ended.cause || ended.cause === 'NORMAL_CLEARING' ? 'missed' : 'failed';
      s.status_text = `Outbound${caller ? ` from ${caller}` : ''} to ${first.did} · ${ended.cause && ended.cause !== 'NORMAL_CLEARING' ? `not connected (${causeText(ended.cause)})` : 'not answered'}`;
    }
    if (ended && s.answered_by) {
      const by = ended.by === 'caller' ? `${caller || 'caller'} hung up` : ended.by === 'agent' ? `${first.did} hung up` : 'ended by system';
      s.status_text += ` · ${by}`;
    }
    return s;
  }

  if (transferredTo) {
    s.outcome = 'transferred';
    s.status_text = `Transferred${transferType === 'blind' ? ' (blind)' : ''}${transferredBy ? ` by ${transferredBy}` : ''} → ${transferredTo}`;
  } else if (s.answered_by) {
    s.outcome = 'answered';
    s.status_text = `Answered by ${who(s.answered_by, s.answered_name)}`
      + (s.wait_sec != null && tQueue != null ? ` · waited ${fmtDur(s.wait_sec)}` : '')
      + (consultFailedTo ? ` · transfer to ${consultFailedTo} not answered, returned` : '');
  } else if (queueExit) {
    const reason = String(queueExit.reason || '').toUpperCase();
    if (reason === 'TIMEOUT' || reason === 'NO_AGENT_TIMEOUT' || reason === 'EXIT_WITH_KEY') {
      s.outcome = 'missed';
      s.status_text = `No agent answered · ${qLabel || 'queue'} timed out after ${fmtDur(s.wait_sec)}${rangTxt}`;
    } else {
      s.outcome = 'abandoned';
      s.status_text = `Caller hung up in ${qLabel || 'queue'} after ${fmtDur(s.wait_sec)}${rangTxt}`;
    }
  } else if (ended) {
    const notAnswered = ['ORIGINATOR_CANCEL', 'NO_ANSWER', 'NO_USER_RESPONSE', 'USER_BUSY', 'CALL_REJECTED'];
    if (!ended.cause || ended.cause === 'NORMAL_CLEARING') {
      s.outcome = 'ended';
      s.status_text = 'Call ended';
    } else if (notAnswered.includes(ended.cause)) {
      s.outcome = 'missed';
      s.status_text = `Not answered · ${causeText(ended.cause)}`;
    } else {
      s.outcome = 'failed';
      s.status_text = `Not connected · ${causeText(ended.cause)}`;
    }
  }

  if (ended && (s.outcome === 'answered' || s.outcome === 'transferred' || s.outcome === 'ended')) {
    const agentWord = lastVia === 'direct' && s.answered_by ? s.answered_by : 'agent';
    const by = ended.by === 'caller' ? 'caller hung up' : ended.by === 'agent' ? `${agentWord} hung up` : ended.by === 'system' ? 'ended by system' : '';
    if (by) s.status_text += ` · ${by}`;
  }
  if (!s.from && first.from) s.from = first.from;
  return s;
}

/** One human-readable line per step, for the expandable timeline. */
function describe(st) {
  switch (st.step) {
    case 'landed':
      if (st.external) {
        return `${st.fax ? 'Fax call' : 'Call'} from ${st.from || 'unknown'} landed on DID ${st.did || '?'}`
          + (st.rule ? ` · rule "${String(st.rule).trim()}"` : '') + (st.route ? ` · route ${String(st.route).trim()}` : '');
      }
      return `${who(st.from_ext || st.from, st.from_name)} dialled ${st.did || '?'}${st.fax ? ' (fax)' : ''}`;
    case 'routed':
      return `Routed to ${st.to_label || st.to}`;
    case 'queue_enter':
      return `Entered queue ${st.queue_name ? `${st.queue_name} (${st.queue_ext})` : st.queue_ext}`;
    case 'agent_ringing':
      return `Ringing agent ${who(st.agent_ext, st.agent_name)}`;
    case 'agent_no_answer':
      return `Agent ${who(st.agent_ext, st.agent_name)} did not answer${st.cause ? ` (${causeText(st.cause)})` : ''}`;
    case 'answered':
      return `Answered by ${who(st.agent_ext, st.agent_name)}`;
    case 'queue_exit': {
      const r = String(st.reason || '').toUpperCase();
      if (r === 'TIMEOUT' || r === 'NO_AGENT_TIMEOUT') return 'Left queue · timed out, no agent answered';
      if (r === 'BREAK_OUT' || r === 'NONE' || !r) return st.cause === 'Terminated' ? 'Left queue · connected to agent' : 'Left queue · caller hung up';
      return `Left queue · ${r.toLowerCase().replace(/_/g, ' ')}`;
    }
    case 'attended_start':
      return `Attended transfer started by ${st.by || 'agent'} → ${st.to || '?'} (caller on hold)`;
    case 'attended_complete':
      return `Attended transfer completed${st.by ? ` by ${st.by}` : ''} → ${st.to}`;
    case 'attended_returned':
      return `Transfer${st.to ? ` to ${st.to}` : ''} not completed (${st.reason || 'not answered'}) · caller returned to agent`;
    case 'attended_voicemail':
      return `Transfer${st.to ? ` to ${st.to}` : ''} not answered · ${st.by || 'agent'} reached ${st.to ? `${st.to}'s ` : ''}voicemail, caller on hold`;
    case 'blind_transfer':
      return `Blind transfer${st.by ? ` by ${st.by}` : ''} → ${st.to}`;
    case 'returned_to_agent':
      return `Caller sent back to ${st.to}`;
    case 'requeued':
      return `Agent left during transfer · caller returned to queue ${st.to || ''}`.trim();
    case 'agent_left':
      return st.agent_ext ? `${st.agent_ext} hung up` : 'Agent hung up';
    case 'fax':
      return st.direction === 'out' ? 'Fax transmission started (sending)' : 'Fax answered · receiving document';
    case 'ended':
      if (st.fax_success != null) {
        return st.fax_success
          ? `Fax completed · ${st.fax_pages || 0} page${st.fax_pages === 1 ? '' : 's'}`
          : `Fax failed · ${st.fax_result || causeText(st.cause) || 'unknown error'}`;
      }
      return `Call ended · ${st.by === 'caller' ? 'caller hung up' : st.by === 'agent' ? 'agent hung up' : 'ended by system'}`
        + (st.cause && st.cause !== 'NORMAL_CLEARING' ? ` (${causeText(st.cause)})` : '');
    default:
      return st.text || st.step;
  }
}

/**
 * Timeline lines. Queue ring rounds are grouped: every agent rung within ~2s becomes one
 * "Ringing ..." line and the failures of that round one line per reason, so a queue that
 * re-rings offline agents every few seconds doesn't flood the view.
 */
function timeline(steps) {
  const out = [];
  const list = steps || [];
  let i = 0;
  let prevRingSet = '';
  while (i < list.length) {
    const st = list[i];
    if (st.step === 'queue_exit' && st.cause === 'Terminated') { i += 1; continue; } // answered: already shown
    if (st.step !== 'agent_ringing' && st.step !== 'agent_no_answer') {
      out.push({ t: st.t, step: st.step, text: describe(st) });
      i += 1;
      continue;
    }
    const t0 = st.t;
    const ringing = [];
    const failed = new Map(); // cause -> [who]
    while (i < list.length && (list[i].step === 'agent_ringing' || list[i].step === 'agent_no_answer') && list[i].t - t0 <= 2000) {
      const x = list[i];
      const label = who(x.agent_ext, x.agent_name);
      if (x.step === 'agent_ringing') {
        if (!ringing.includes(label)) ringing.push(label);
      } else {
        const c = causeText(x.cause) || 'no answer';
        if (!failed.has(c)) failed.set(c, []);
        if (!failed.get(c).includes(x.agent_ext || label)) failed.get(c).push(x.agent_ext || label);
      }
      i += 1;
    }
    if (ringing.length) {
      const set = ringing.join('|');
      out.push({
        t: t0,
        step: 'agent_ringing',
        text: `${set === prevRingSet ? 'Ringing again' : 'Ringing'} ${ringing.length > 1 ? `${ringing.length} agents` : 'agent'}: ${ringing.join(', ')}`,
      });
      prevRingSet = set;
    }
    for (const [c, whoList] of failed) {
      out.push({ t: t0, step: 'agent_no_answer', text: `Not answered (${c}): ${whoList.join(', ')}` });
    }
  }
  return out;
}

/**
 * Save the journey permanently (table q_call_journeys) so reports keep it after the
 * Redis copy expires. Upsert: safe to call more than once for the same call.
 */
async function persist(db, rootUuid, domainUuid) {
  if (!redis || !db || !isUuid(rootUuid)) return false;
  try {
    const { root, steps } = await get(rootUuid);
    if (!steps.length) return false;
    const sum = summarize(steps);
    if (sum.live) return false; // only finished calls
    let legs = [];
    try { legs = await redis.sMembers(`journeyLegs:${root}`); } catch (e) { legs = []; }
    legs = [...new Set([root, ...legs])];
    await db.query(
      `INSERT INTO q_call_journeys (root_uuid, domain_uuid, start_epoch, legs, caller, did, queue_ext, queue_name,
         answered_by, answered_name, wait_sec, talk_sec, outcome, status_text, rule, route, timeline, call_type, updated_at)
       VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, $17, $18, now())
       ON CONFLICT (root_uuid) DO UPDATE SET
         legs = (SELECT array_agg(DISTINCT x) FROM unnest(q_call_journeys.legs || EXCLUDED.legs) x),
         domain_uuid = COALESCE(EXCLUDED.domain_uuid, q_call_journeys.domain_uuid),
         caller = EXCLUDED.caller, did = EXCLUDED.did, queue_ext = EXCLUDED.queue_ext, queue_name = EXCLUDED.queue_name,
         answered_by = EXCLUDED.answered_by, answered_name = EXCLUDED.answered_name, wait_sec = EXCLUDED.wait_sec,
         talk_sec = EXCLUDED.talk_sec, outcome = EXCLUDED.outcome, status_text = EXCLUDED.status_text,
         rule = EXCLUDED.rule, route = EXCLUDED.route, timeline = EXCLUDED.timeline, call_type = EXCLUDED.call_type, updated_at = now()`,
      [root, isUuid(domainUuid) ? domainUuid : null, Math.floor(steps[0].t / 1000), legs, sum.from || '', sum.did || '',
        sum.queue_ext || '', sum.queue_name || '', sum.answered_by || '', sum.answered_name || '',
        sum.wait_sec, sum.talk_sec, sum.outcome || '', sum.status_text || '', sum.rule || '', sum.route || '',
        JSON.stringify(timeline(steps)), sum.call_type || ''],
    );
    return true;
  } catch (e) {
    console.error('[call-journey] persist', e && e.message ? e.message : e);
    return false;
  }
}

/** Row from q_call_journeys -> same shape getCallJourneys returns from Redis. */
function fromDbRow(r) {
  return {
    root: r.root_uuid, from: r.caller, did: r.did, queue_ext: r.queue_ext, queue_name: r.queue_name,
    answered_by: r.answered_by, answered_name: r.answered_name, wait_sec: r.wait_sec, talk_sec: r.talk_sec,
    outcome: r.outcome, status_text: r.status_text, rule: r.rule, route: r.route, call_type: r.call_type,
    timeline: Array.isArray(r.timeline) ? r.timeline : [],
  };
}

module.exports = { persist, fromDbRow, init, add, link, rootOf, get, has, summarize, describe, timeline, causeText, fmtDur, isUuid };
