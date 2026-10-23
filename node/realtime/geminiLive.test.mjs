// Run with:  node --test realtime/geminiLive.test.mjs   (no network, no `ws` package needed)
import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { GeminiLiveSession, pickGeminiVoice, toGeminiSchema, toGeminiFunctionDeclaration } from './geminiLive.js';

const KEY = 'AIza-test-secret-key';

class FakeSocket extends EventEmitter {
  constructor() { super(); this.sent = []; }
  send(data) { this.sent.push(JSON.parse(data)); }
  close() {}
  server(obj) { this.emit('message', JSON.stringify(obj)); }
}

async function connected(model) {
  const sock = new FakeSocket();
  let seen;
  const gl = new GeminiLiveSession({ apiKey: KEY, model, createSocket: (url, options) => { seen = { url, options }; return sock; } });
  const p = gl.connect({ timeoutMs: 500 });
  sock.emit('open');
  await p;
  return { gl, sock, seen };
}

const audioMsg = (bytes) => ({ serverContent: { modelTurn: { parts: [{ inlineData: { data: Buffer.alloc(bytes).toString('base64'), mimeType: 'audio/pcm;rate=24000' } }] } } });

test('connects to the BidiGenerateContent endpoint with the key as the key query parameter', async () => {
  const { seen } = await connected();
  assert.ok(seen.url.startsWith('wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent?key='));
  assert.ok(seen.url.endsWith(encodeURIComponent(KEY)));
});

test('setup message: model, audio output, voice, prompt, transcription and function declarations', async () => {
  const { gl, sock } = await connected('gemini-3.8-live');
  gl.configure({
    instructions: 'Be brief.',
    voice: 'Puck',
    tools: [{ name: 'lookup_caller', description: 'find', input_schema: { type: 'object', additionalProperties: false, properties: { n: { type: 'string' }, list: { type: 'array', items: { type: 'integer' } } } } }],
  });
  const { setup } = sock.sent[0];
  assert.equal(setup.model, 'models/gemini-3.8-live');
  assert.deepEqual(setup.generationConfig.responseModalities, ['AUDIO']);
  assert.equal(setup.generationConfig.speechConfig.voiceConfig.prebuiltVoiceConfig.voiceName, 'Puck');
  assert.deepEqual(setup.systemInstruction, { parts: [{ text: 'Be brief.' }] });
  assert.deepEqual(setup.inputAudioTranscription, {});
  assert.deepEqual(setup.outputAudioTranscription, {});
  const decl = setup.tools[0].functionDeclarations[0];
  assert.equal(decl.name, 'lookup_caller');
  assert.equal(decl.parameters.type, 'OBJECT');
  assert.equal(decl.parameters.additionalProperties, undefined);
  assert.equal(decl.parameters.properties.n.type, 'STRING');
  assert.equal(decl.parameters.properties.list.items.type, 'INTEGER');
});

test('setup with no tools omits the tools field; a models/ prefix is not doubled', async () => {
  const { gl, sock } = await connected('models/gemini-x');
  gl.configure({ instructions: 'x', voice: 'unknown-name' });
  assert.equal(sock.sent[0].setup.tools, undefined);
  assert.equal(sock.sent[0].setup.model, 'models/gemini-x');
  assert.equal(sock.sent[0].setup.generationConfig.speechConfig.voiceConfig.prebuiltVoiceConfig.voiceName, 'Kore');
});

test('waitReady resolves on setupComplete and rejects on timeout; reconfigure is unavailable', async () => {
  const { gl, sock } = await connected();
  const ready = gl.waitReady(500);
  sock.server({ setupComplete: {} });
  await ready;
  assert.equal(gl.reconfigure({ instructions: 'x', tools: [] }), false);
  const other = await connected();
  await assert.rejects(other.gl.waitReady(30), /Timed out/);
});

test('caller audio is upsampled to 16 kHz and labelled with its rate', async () => {
  const { gl, sock } = await connected();
  gl.sendAudio(Buffer.alloc(320));                                  // 20 ms @ 8 kHz
  const m = sock.sent[0].realtimeInput.audio;
  assert.equal(m.mimeType, 'audio/pcm;rate=16000');
  assert.equal(Buffer.from(m.data, 'base64').length, 640);          // 20 ms @ 16 kHz
});

test('agent audio is downsampled to 8 kHz; responseStarted fires once per answer', async () => {
  const { gl, sock } = await connected();
  const events = [];
  const pcm = [];
  gl.on('responseStarted', () => events.push('started'));
  gl.on('audio', (b) => pcm.push(b));
  sock.server(audioMsg(9600));   // 200 ms @ 24 kHz
  sock.server(audioMsg(9600));
  assert.deepEqual(events, ['started']);
  const samples = pcm.reduce((n, b) => n + b.length / 2, 0);
  assert.ok(Math.abs(samples - 3200) <= 25, 'about 400 ms @ 8 kHz expected, got ' + samples);
});

test('the caller turn is reported when the model starts answering, before its audio', async () => {
  const { gl, sock } = await connected();
  const events = [];
  gl.on('userTranscript', (t) => events.push('user:' + t));
  gl.on('responseStarted', () => events.push('started'));
  sock.server({ serverContent: { inputTranscription: { text: 'book me ' } } });
  sock.server({ serverContent: { inputTranscription: { text: 'for friday' } } });
  assert.deepEqual(events, []);                       // still the caller's turn
  sock.server(audioMsg(960));
  assert.deepEqual(events, ['user:book me for friday', 'started']);
});

test('a finished answer reports audioDone, its spoken text, then responseDone', async () => {
  const { gl, sock } = await connected();
  const events = [];
  gl.on('audioDone', () => events.push('audioDone'));
  gl.on('assistantTextDone', (t) => events.push('text:' + t));
  gl.on('responseDone', (d) => events.push('done:' + d.status));
  sock.server({ serverContent: { outputTranscription: { text: 'Sure. ' } } });
  sock.server({ serverContent: { outputTranscription: { text: 'Friday works.' } } });
  sock.server({ serverContent: { turnComplete: true } });
  assert.deepEqual(events, ['audioDone', 'text:Sure. Friday works.', 'done:completed']);
  assert.equal(gl.responseActive, false);
});

test('the caller talking over the agent is reported as speechStarted and drops the unfinished answer', async () => {
  const { gl, sock } = await connected();
  const events = [];
  gl.on('speechStarted', () => events.push('speech'));
  gl.on('assistantTextDone', (t) => events.push('text:' + t));
  sock.server({ serverContent: { outputTranscription: { text: 'Let me ch' } } });
  sock.server({ serverContent: { interrupted: true } });
  sock.server({ serverContent: { turnComplete: true } });
  assert.deepEqual(events, ['speech']);              // the cut-off half sentence is not treated as spoken
});

test('tool calls surface with parsed args, and results are sent as ONE toolResponse with names', async () => {
  const { gl, sock } = await connected();
  let done;
  gl.on('responseDone', (d) => { done = d; });
  sock.server({ toolCall: { functionCalls: [
    { id: 'c1', name: 'check_availability', args: { day: 'Fri' } },
    { id: 'c2', name: 'lookup_caller', args: {} },
  ] } });
  assert.deepEqual(done.toolCalls, [
    { callId: 'c1', name: 'check_availability', args: { day: 'Fri' } },
    { callId: 'c2', name: 'lookup_caller', args: {} },
  ]);
  gl.sendToolResult('c1', JSON.stringify({ free: true }));
  gl.sendToolResult('c2', 'plain text result');
  assert.equal(sock.sent.length, 0);                 // queued, not sent one by one
  gl.respond();
  assert.deepEqual(sock.sent[0], { toolResponse: { functionResponses: [
    { id: 'c1', name: 'check_availability', response: { result: { free: true } } },
    { id: 'c2', name: 'lookup_caller', response: { result: 'plain text result' } },
  ] } });
  gl.respond();                                      // nothing queued -> nothing sent
  assert.equal(sock.sent.length, 1);
});

test('say() asks for a fixed line through realtimeInput.text', async () => {
  const { gl, sock } = await connected();
  gl.say('Done, I have added "Demo".');
  const t = sock.sent[0].realtimeInput.text;
  assert.ok(t.includes('Say exactly') && t.includes('Done, I have added \\"Demo\\".'));
});

test('close and socket errors never leak the key', async () => {
  const sock = new FakeSocket();
  const gl = new GeminiLiveSession({ apiKey: KEY, createSocket: () => sock });
  const errors = [];
  const closes = [];
  gl.on('error', (e) => errors.push(e));
  gl.on('close', (c) => closes.push(c));
  const p = gl.connect({ timeoutMs: 500 });
  sock.emit('error', new Error('bad request for ' + KEY));
  await assert.rejects(p, (e) => !e.message.includes(KEY) && e.message.includes('[key]'));
  assert.ok(!errors[0].message.includes(KEY));

  const sock2 = new FakeSocket();
  const gl2 = new GeminiLiveSession({ apiKey: KEY, createSocket: () => sock2 });
  gl2.on('close', (c) => closes.push(c));
  const p2 = gl2.connect({ timeoutMs: 500 });
  sock2.emit('close', 1007, Buffer.from('Invalid JSON payload ... key=' + KEY));
  await assert.rejects(p2, (e) => e.message.includes('1007') && !e.message.includes(KEY));
  assert.ok(!JSON.stringify(closes).includes(KEY));
});

test('goAway and malformed frames are handled without throwing', async () => {
  const { gl, sock } = await connected();
  let goAway;
  gl.on('goAway', (g) => { goAway = g; });
  sock.server({ goAway: { timeLeft: '30s' } });
  assert.deepEqual(goAway, { timeLeft: '30s' });
  assert.doesNotThrow(() => sock.emit('message', 'not json'));
  assert.doesNotThrow(() => sock.server({ unknownThing: 1 }));
});

test('helpers', () => {
  assert.equal(pickGeminiVoice('aoede'), 'Aoede');
  assert.equal(pickGeminiVoice('Alloy'), 'Kore');
  assert.equal(pickGeminiVoice(undefined), 'Kore');
  assert.deepEqual(toGeminiSchema({ type: 'string', $schema: 'x' }), { type: 'STRING' });
  assert.deepEqual(toGeminiFunctionDeclaration({ name: 'x' }), { name: 'x', description: '', parameters: { type: 'OBJECT', properties: {} } });
  assert.throws(() => new GeminiLiveSession({}), /API key/);
});
