// Call push contract: what node/ringPush.js sends must match what the apps read
// (ringqexpoapp lib/sip/voip-payload.ts and RingQMessagingService.kt, and the Flutter app).
// A renamed field or a different stop value means phones keep ringing, or never ring.
// ringPush.js is loaded as-is, with its private functions exposed only inside this test.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import Module from 'node:module';
import vm from 'node:vm';

const file = new URL('../../node/ringPush.js', import.meta.url).pathname;
const realRequire = createRequire(file);
const source = readFileSync(file, 'utf8');
const exposed = '\nmodule.exports.__test = { payloadFor, sendAndroid, sendIos, reasonFor, STOP_REASONS };';
const mod = { exports: {} };
vm.runInThisContext(Module.wrap(source + exposed), { filename: file })(
  mod.exports,
  (id) => (id === 'ws' ? class {} : realRequire(id)),
  mod,
  file,
  new URL('../../node/', import.meta.url).pathname,
);

const logged = [];
mod.exports.init({ dryRun: true, logger: { log: (...a) => logged.push(a), error: () => {} } });
const { payloadFor, sendAndroid, sendIos, reasonFor, STOP_REASONS } = mod.exports.__test;

// The values the apps treat as "stop ringing" (voip-payload.ts TERMINAL_CALL_STATES).
const APP_STOP_VALUES = ['', 'decline', 'missed', 'answered', 'answered2'];

const ring = { id: 'ring-1', ext: 1001, domain: 't.ringq.ai', callerNumber: '6591234567', callerName: 'Alice', avatar: '' };
const lastMessage = () => JSON.parse(logged.at(-1).at(-1));

test('the payload carries every field the apps read', () => {
  const p = payloadFor(ring, ring.id);
  for (const key of ['domain', 'extension', 'id', 'call', 'caller_id', 'nameCaller', 'avatar']) {
    assert.ok(key in p, key);
  }
  assert.equal(p.extension, '1001');
  assert.equal(p.call, 'ring-1');
  assert.equal(p.id, 'ring-1');
  assert.equal(p.caller_id, '6591234567');
  assert.equal(p.nameCaller, 'Alice');
});

test('a queue call shows "<queue name>: <caller>"', () => {
  assert.equal(payloadFor({ ...ring, queueName: 'Sales' }, ring.id).nameCaller, 'Sales: Alice');
});

test('every stop reason is one the apps understand', () => {
  for (const reason of Object.values(STOP_REASONS)) assert.ok(APP_STOP_VALUES.includes(reason), reason);
  assert.equal(reasonFor('LOSE_RACE'), 'answered');
  assert.equal(reasonFor('PICKED_OFF'), 'answered');
  assert.equal(reasonFor('USER_BUSY'), 'decline');
  assert.equal(reasonFor('CALL_REJECTED'), 'decline');
  assert.equal(reasonFor('NO_ANSWER'), 'missed');
});

test('Android ring: high-priority data message of type "ring", all strings', async () => {
  assert.equal(await sendAndroid(ring, ring.id, ['tok-a', 'tok-b']), 2);
  const m = lastMessage();
  assert.deepEqual(m.tokens, ['tok-a', 'tok-b']);
  assert.equal(m.data.type, 'ring');
  assert.equal(m.data.call, 'ring-1');
  for (const [k, v] of Object.entries(m.data)) assert.equal(typeof v, 'string', k);
  assert.equal(m.android.priority, 'high');
  assert.equal(m.android.ttl, 30000);
  assert.equal(m.android.collapseKey, 'call-ring-1');
});

test('Android stop: still type "ring" (the app drops other types), call = reason', async () => {
  await sendAndroid(ring, 'answered', ['tok-a']);
  const m = lastMessage();
  assert.equal(m.data.type, 'ring');
  assert.equal(m.data.call, 'answered');
  assert.equal(m.android.ttl, 120000);
});

test('iOS ring and decline stop', async () => {
  assert.equal(await sendIos(ring, ring.id, ['ios-1']), 1);
  let m = lastMessage();
  assert.equal(m.device, 'ios');
  assert.equal(m.payload.call, 'ring-1');
  await sendIos(ring, 'decline', ['ios-1']);
  m = lastMessage();
  assert.equal(m.payload.call, '', 'the iOS app treats an empty call as the decline stop');
});

test('nothing is sent with no tokens', async () => {
  assert.equal(await sendAndroid(ring, ring.id, []), 0);
  assert.equal(await sendIos(ring, ring.id, []), 0);
});
