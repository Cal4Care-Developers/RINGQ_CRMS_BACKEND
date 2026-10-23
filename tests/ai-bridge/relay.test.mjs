// Loads the REAL relay methods out of claude_server.js (same trick the other bridge tests use) and checks them
// against a fake FreeSWITCH socket. Run:  node --test relay.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { Upsampler8to24, Downsampler16to8 } from '../../node/realtime/resample.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(here, '../../node/claude_server.js'), 'utf8');
const grab = (startMarker, endMarker) => {
  const s = src.indexOf(startMarker);
  const e = src.indexOf(endMarker, s);
  assert.ok(s > 0 && e > s, 'marker not found: ' + startMarker);
  return src.slice(s, e);
};
const methods = grab('  /** Called by the relay server', '  // MR AI changes - ends\n\n  interrupt()');
const awaitPlayoutSrc = grab('  async awaitPlayout(tailMs = 0) {', '  async waitForSpeechToFinish');
const relayMeta = grab('function relayMetadataFromUrl', '// ---- CONTROL:');

const factory = new Function('Upsampler8to24', 'Downsampler16to8', 'SAMPLE_RATE', 'RELAY_DELTA_BYTES', `
  ${relayMeta}
  class Call { constructor(){ this.speechEndsAt=0; this.generation=0; this.speakChain=Promise.resolve(); this.relay=null; this.relayUp=null; this.relayHoldPending=false; }
  ${methods}
  ${awaitPlayoutSrc}
  }
  return { Call, relayMetadataFromUrl };`);
const { Call, relayMetadataFromUrl } = factory(Upsampler8to24, Downsampler16to8, 8000, (24000 * 2 * 100) / 1000);

const fakeWs = () => {
  const sent = [];
  const ws = { readyState: 1, sent, send: (m) => sent.push(JSON.parse(m)), on() {} };
  return ws;
};

test('metadata from the connect url', () => {
  assert.equal(relayMetadataFromUrl('/rt?u=abc&c=%2B15551234&e=5001&d=acme.ringq.ai'), 'abc|+15551234|5001|acme.ringq.ai');
  assert.equal(relayMetadataFromUrl('/rt?u=abc&c=&e=5001&d=x'), 'abc||5001|x');
  assert.equal(relayMetadataFromUrl('/rt'), '|||');
});

test('1 s of 8 kHz audio becomes 24 kHz in 100 ms pieces and advances the playout clock by 1 s', () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  const before = Date.now();
  assert.ok(c._relaySend(Buffer.alloc(16000)));            // 1 s @ 8 kHz PCM16
  assert.equal(ws.sent.length, 10);
  assert.ok(ws.sent.every((m) => m.type === 'response.output_audio.delta'));
  const bytes = ws.sent.reduce((n, m) => n + Buffer.from(m.delta, 'base64').length, 0);
  assert.equal(bytes, 48000);                               // 1 s @ 24 kHz PCM16
  assert.ok(c.speechEndsAt - before >= 1000 && c.speechEndsAt - before < 1100);
});

test('audio queued back to back adds up; an odd trailing byte is dropped, not shifted', () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  const t0 = Date.now();
  c._relaySend(Buffer.alloc(8001));
  c._relaySend(Buffer.alloc(8000));
  assert.ok(c.speechEndsAt - t0 >= 1000 && c.speechEndsAt - t0 < 1100);
  const bytes = ws.sent.reduce((n, m) => n + Buffer.from(m.delta, 'base64').length, 0);
  assert.equal(bytes, 48000);
});

test('clear tells FreeSWITCH to drop its queue and resets the converter', () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  c._relaySend(Buffer.alloc(1600));
  const up = c.relayUp;
  c._relayClear();
  assert.deepEqual(ws.sent.at(-1), { type: 'input_audio_buffer.speech_started' });
  assert.notEqual(c.relayUp, up);
});

test('nothing is sent on a closed socket', () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws); ws.readyState = 3;
  assert.equal(c._relaySend(Buffer.alloc(1600)), false);
  c._relayClear();
  assert.equal(ws.sent.length, 0);
});

test('the hold resolves once the playout clock runs out, and only one is queued', async () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  c._relaySend(Buffer.alloc(1600));                          // 100 ms
  c._relayHold(); c._relayHold(); c._relayHold();
  const t0 = Date.now();
  await c.speakChain;
  assert.ok(Date.now() - t0 >= 60, 'resolved too early');
  assert.equal(c.relayHoldPending, false);
});

test('a barge-in (generation bump) releases a waiting hold at once', async () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  c._relaySend(Buffer.alloc(16000 * 5));                     // 5 s
  c._relayHold();
  setTimeout(() => { c.generation++; }, 50);
  const t0 = Date.now();
  await c.speakChain;
  assert.ok(Date.now() - t0 < 600);
});

// ---- wideband (24 kHz out, 16 kHz in) ----
test('24 kHz agent audio goes through untouched and moves the clock by its real length', () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  const before = Date.now();
  assert.ok(c._relaySend(Buffer.alloc(48000), 24000));    // 1 s @ 24 kHz
  const bytes = ws.sent.reduce((n, m) => n + Buffer.from(m.delta, 'base64').length, 0);
  assert.equal(bytes, 48000);
  assert.ok(c.speechEndsAt - before >= 1000 && c.speechEndsAt - before < 1100);
});

test('wideband caller audio: native session gets 16 kHz, everything else the 8 kHz copy', () => {
  const c = new Call(); const ws = fakeWs(); c.attachRelay(ws);
  const wide = []; const narrow = [];
  c.audioChunks = []; c._noteCallerAudio = () => {};
  c.onMessage = (buf) => narrow.push(buf.length);
  c.nativeRt = { sendAudioWide: (b) => wide.push(b.length) }; c.nativeReady = true;
  c.onWideAudio(Buffer.alloc(640));                         // 20 ms @ 16 kHz
  assert.deepEqual(wide, [640]);
  assert.deepEqual(narrow, []);
  c.nativeRt = null;                                         // Deepgram route: no native session
  c.onWideAudio(Buffer.alloc(640));
  assert.equal(narrow.length, 1);
  assert.ok(narrow[0] >= 318 && narrow[0] <= 322, 'got ' + narrow[0]);   // 16 kHz -> 8 kHz: 320 bytes
});

test('relay rate: 16000 only when the connect URL says so, else the 8 kHz of rules saved before', () => {
  const i = src.indexOf('function relayRateFromUrl');
  const rateOf = new Function(src.slice(i, src.indexOf('\n}\n', i) + 3) + '\nreturn relayRateFromUrl;')();
  assert.equal(rateOf('/rt?u=a&c=b&e=1&d=x&r=16000'), 16000);
  assert.equal(rateOf('/rt?u=a&c=b&e=1&d=x'), 8000);
  assert.equal(rateOf('/rt?r=8000'), 8000);
  assert.equal(rateOf('/rt?r=24000'), 8000);
});
