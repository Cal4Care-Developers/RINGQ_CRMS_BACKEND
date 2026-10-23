// The "where does the caller go when the AI cannot take the call" order, and the native-voice fallback, taken
// straight out of node/claude_server.js. Run: node --test tests/ai-bridge/fallback.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(here, '../../node/claude_server.js'), 'utf8');
const between = (a, b) => { const s = src.indexOf(a); const e = src.indexOf(b, s); assert.ok(s > 0 && e > s, a); return src.slice(s, e); };

const parseSrc = between('function parseTransferDestination(raw)', '// MR AI changes - ends');
const methods = between('  useNativeVoice() {', '  // The standard voice route (Deepgram) cannot start');
const fallbackSrc = between('  // The standard voice route (Deepgram) cannot start', '  // Where a caller goes when the AI cannot');
const destSrc = between('  fallbackDestination() {', "'1000';") + "'1000'; }";

const build = new Function(`
  ${parseSrc}
  class C {
    constructor(a, provider = 'openai') { this.agentConfig = a; this.modelProvider = provider; this.logs = []; this.transfers = [];
      this.started = 0; this.ended = false; this.greeted = false; this.dgLive = null; }
    providerSetupProblem() { return ''; }
    _providerLabel() { return 'OpenAI'; }
    async providerKeyProblem() { return this.keyProblem || ''; }
    async startNativeVoice() { this.started++; }
    transferCall(d, why) { this.transfers.push([d, why]); }
    ${methods}
    ${fallbackSrc}
    ${destSrc}
  }
  return C;`);
const C = build();

test('1. the agent\'s escalation "Transfer to" wins', () => {
  const c = new C({ escalation: { extension: 'transfer 1006 XML' }, keywords: [{ keyword: 'sales', extension: 'transfer 1010 XML' }] });
  assert.equal(c.fallbackDestination(), '1006');
});
test('2. no escalation target: the first keyword destination', () => {
  const c = new C({ escalation: { extension: '' }, keywords: [{ keyword: 'x', extension: '' }, { keyword: 'sales', extension: 'transfer 1010 XML' }] });
  assert.equal(c.fallbackDestination(), '1010');
});
test('3. nothing configured: extension 1000 (or TRANSFER_NUMBER)', () => {
  assert.equal(new C({}).fallbackDestination(), '1000');
  assert.equal(new C(null).fallbackDestination(), '1000');
  process.env.TRANSFER_NUMBER = '2000';
  assert.equal(new C({}).fallbackDestination(), '2000');
  delete process.env.TRANSFER_NUMBER;
});
test('4. voicemail destinations keep their * prefix', () => {
  assert.equal(new C({ escalation: { extension: 'transfer *991006 XML' } }).fallbackDestination(), '*991006');
});
test('5. a standard agent with an OpenAI key falls back to the native voice, once', async () => {
  const c = new C({ voiceMode: 'standard', providerApiKey: 'sk-x' });
  assert.equal(c.useNativeVoice(), false);
  assert.equal(await c._fallBackToNativeVoice('no Deepgram key'), true);
  assert.equal(c.started, 1);
  assert.equal(await c._fallBackToNativeVoice('again'), false);
  assert.equal(c.started, 1);
});
test('6. no provider key: no native fallback (the caller goes to a person)', async () => {
  const c = new C({ voiceMode: 'standard' });
  assert.equal(await c._fallBackToNativeVoice('no Deepgram key'), false);
  assert.equal(c.started, 0);
});
test('7. a provider without a realtime voice (Claude) never falls back to native', async () => {
  const c = new C({ providerApiKey: 'k' }, 'claude');
  assert.equal(c.nativeCapable(), false);
  assert.equal(await c._fallBackToNativeVoice('x'), false);
});
test('8. a rejected provider key transfers instead of opening the native voice', async () => {
  const c = new C({ providerApiKey: 'bad', escalation: { extension: '1006' } });
  c.keyProblem = 'the provider REJECTED the key';
  assert.equal(await c._fallBackToNativeVoice('no Deepgram key'), true);
  assert.equal(c.started, 0);
  assert.deepEqual(c.transfers[0][0], '1006');
});
test('9. not after the caller heard the greeting', async () => {
  const c = new C({ providerApiKey: 'k' }); c.greeted = true;
  assert.equal(await c._fallBackToNativeVoice('x'), false);
});
test('10. Azure needs its endpoint and realtime deployment; Gemini just a key', () => {
  assert.equal(new C({ providerApiKey: 'k' }, 'azure openai').nativeCapable(), false);
  assert.equal(new C({ providerApiKey: 'k', providerEndpoint: 'https://x', realtimeDeployment: 'rt' }, 'azure openai').nativeCapable(), true);
  assert.equal(new C({ providerApiKey: 'k' }, 'gemini').nativeCapable(), true);
});
