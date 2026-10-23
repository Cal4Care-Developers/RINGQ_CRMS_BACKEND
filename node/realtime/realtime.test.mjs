// Run with:  node --test realtime/realtime.test.mjs   (no network, no `ws` package needed)
import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { linearToUlaw, ulawToLinear, pcm16ToUlaw, ulawToPcm16 } from './g711.js';
import { OpenAiRealtimeSession, pickRealtimeVoice, toRealtimeTool } from './openaiRealtime.js';

class FakeSocket extends EventEmitter {
  constructor() {
    super();
    this.sent = [];
    this.closedByUs = false;
  }
  send(data) { this.sent.push(JSON.parse(data)); }
  close() { this.closedByUs = true; }
  server(obj) { this.emit('message', JSON.stringify(obj)); }
}

async function connected() {
  const sock = new FakeSocket();
  let seen;
  const rt = new OpenAiRealtimeSession({
    apiKey: 'sk-test-secret-key',
    createSocket: (url, options) => { seen = { url, options }; return sock; },
  });
  const p = rt.connect({ timeoutMs: 500 });
  sock.emit('open');
  await p;
  return { rt, sock, seen };
}

test('g711: silence and extremes match the standard code points', () => {
  assert.equal(linearToUlaw(0), 0xff);
  assert.equal(ulawToLinear(0xff), 0);
  assert.equal(ulawToLinear(0x80), 32124);
  assert.equal(ulawToLinear(0x00), -32124);
  assert.equal(linearToUlaw(32767), 0x80);
  assert.equal(linearToUlaw(-32768), 0x00);
});

test('g711: a ramp survives a round trip within mu-law quantisation error', () => {
  const pcm = Buffer.alloc(2 * 4001);
  for (let i = 0; i < 4001; i++) pcm.writeInt16LE((i - 2000) * 16, i * 2);
  const back = ulawToPcm16(pcm16ToUlaw(pcm));
  assert.equal(back.length, pcm.length);
  for (let i = 0; i < 4001; i++) {
    const a = pcm.readInt16LE(i * 2);
    const b = back.readInt16LE(i * 2);
    // mu-law steps grow with amplitude; 4% of the sample plus the smallest step is the bound.
    assert.ok(Math.abs(a - b) <= Math.abs(a) * 0.04 + 16, 'sample ' + i + ': ' + a + ' vs ' + b);
  }
});

test('connect sends the key only as an Authorization header, never in the URL', async () => {
  const { seen } = await connected();
  assert.equal(seen.options.headers.Authorization, 'Bearer sk-test-secret-key');
  assert.ok(!seen.url.includes('sk-test'));
  assert.ok(seen.url.startsWith('wss://api.openai.com/v1/realtime?model='));
});

test('configure sends the GA session shape for a phone call', async () => {
  const { rt, sock } = await connected();
  rt.configure({
    instructions: 'Be brief.',
    voice: 'Coral',
    tools: [{ name: 'lookup_caller', description: 'find a caller', input_schema: { type: 'object', properties: { n: { type: 'string' } } } }],
  });
  const msg = sock.sent[0];
  assert.equal(msg.type, 'session.update');
  assert.equal(msg.session.type, 'realtime');
  assert.deepEqual(msg.session.output_modalities, ['audio']);
  assert.deepEqual(msg.session.audio.input.format, { type: 'audio/pcmu' });
  assert.deepEqual(msg.session.audio.output.format, { type: 'audio/pcmu' });
  assert.equal(msg.session.audio.output.voice, 'coral');
  assert.equal(msg.session.audio.input.turn_detection.type, 'server_vad');
  // the bridge, not the model, decides when to answer a finished caller turn
  assert.equal(msg.session.audio.input.turn_detection.create_response, false);
  assert.equal(msg.session.audio.input.turn_detection.interrupt_response, true);
  assert.deepEqual(msg.session.tools[0], {
    type: 'function', name: 'lookup_caller', description: 'find a caller',
    parameters: { type: 'object', properties: { n: { type: 'string' } } },
  });
  assert.equal(msg.session.tool_choice, 'auto');
});

test('reconfigure resends instructions and tools but never the voice', async () => {
  const { rt, sock } = await connected();
  rt.reconfigure({ instructions: 'with tools', tools: [{ name: 'check_availability' }] });
  const s = sock.sent[0].session;
  assert.equal(s.type, 'realtime');
  assert.equal(s.instructions, 'with tools');
  assert.equal(s.tools[0].name, 'check_availability');
  assert.equal(s.audio, undefined);
});

test('waitReady resolves on session.updated, and rejects on timeout', async () => {
  const { rt, sock } = await connected();
  rt.configure({ instructions: 'x', voice: 'alloy' });
  const ready = rt.waitReady(500);
  sock.server({ type: 'session.updated', session: {} });
  await ready;

  const second = await connected();
  await assert.rejects(second.rt.waitReady(30), /Timed out/);
});

test('caller audio goes out as base64 mu-law, half the byte length', async () => {
  const { rt, sock } = await connected();
  rt.sendAudio(Buffer.alloc(320)); // 20 ms of 8 kHz PCM16 silence
  const msg = sock.sent[0];
  assert.equal(msg.type, 'input_audio_buffer.append');
  const bytes = Buffer.from(msg.audio, 'base64');
  assert.equal(bytes.length, 160);
  assert.ok(bytes.every((b) => b === 0xff)); // mu-law silence
});

test('agent audio is decoded to PCM16 and emitted (GA and beta event names)', async () => {
  const { rt, sock } = await connected();
  const got = [];
  rt.on('audio', (b) => got.push(b));
  const ulaw = Buffer.alloc(80, 0xff).toString('base64');
  sock.server({ type: 'response.output_audio.delta', delta: ulaw });
  sock.server({ type: 'response.audio.delta', delta: ulaw });
  assert.equal(got.length, 2);
  assert.equal(got[0].length, 160);
  assert.ok(got[0].every((b) => b === 0));
});

test('transcripts, speech events and audioDone are surfaced', async () => {
  const { rt, sock } = await connected();
  const events = [];
  for (const name of ['speechStarted', 'speechStopped', 'audioDone']) rt.on(name, () => events.push(name));
  rt.on('userTranscript', (t) => events.push('user:' + t));
  rt.on('assistantTextDone', (t) => events.push('asst:' + t));
  sock.server({ type: 'input_audio_buffer.speech_started' });
  sock.server({ type: 'input_audio_buffer.speech_stopped' });
  sock.server({ type: 'conversation.item.input_audio_transcription.completed', transcript: '  book me Friday ' });
  sock.server({ type: 'response.output_audio_transcript.done', transcript: 'Sure.' });
  sock.server({ type: 'response.output_audio.done' });
  assert.deepEqual(events, ['speechStarted', 'speechStopped', 'user:book me Friday', 'asst:Sure.', 'audioDone']);
});

test('response.done reports function calls with parsed arguments, tolerating bad JSON', async () => {
  const { rt, sock } = await connected();
  let done;
  rt.on('responseDone', (d) => { done = d; });
  sock.server({
    type: 'response.done',
    response: {
      status: 'completed',
      output: [
        { type: 'message', role: 'assistant' },
        { type: 'function_call', call_id: 'call_1', name: 'create_calendar_event', arguments: '{"summary":"Demo"}' },
        { type: 'function_call', call_id: 'call_2', name: 'lookup_caller', arguments: '{not json' },
      ],
    },
  });
  assert.equal(done.status, 'completed');
  assert.deepEqual(done.toolCalls, [
    { callId: 'call_1', name: 'create_calendar_event', args: { summary: 'Demo' } },
    { callId: 'call_2', name: 'lookup_caller', args: {} },
  ]);
});

test('cancel only fires while a response is active', async () => {
  const { rt, sock } = await connected();
  assert.equal(rt.cancel(), false);
  assert.equal(sock.sent.length, 0);
  sock.server({ type: 'response.created', response: { id: 'resp_1' } });
  assert.equal(rt.cancel(), true);
  assert.deepEqual(sock.sent[0], { type: 'response.cancel' });
  sock.server({ type: 'response.done', response: { status: 'cancelled', output: [] } });
  assert.equal(rt.cancel(), false);
});

test('tool results and scripted lines are sent in the documented shapes', async () => {
  const { rt, sock } = await connected();
  rt.sendToolResult('call_9', { ok: true });
  assert.deepEqual(sock.sent[0], {
    type: 'conversation.item.create',
    item: { type: 'function_call_output', call_id: 'call_9', output: '{"ok":true}' },
  });
  rt.say('Done, I have added "Demo" to the calendar.');
  const r = sock.sent[1];
  assert.equal(r.type, 'response.create');
  assert.equal(r.response.tool_choice, 'none');
  assert.ok(r.response.instructions.includes('Done, I have added \\"Demo\\" to the calendar.'));
  sock.server({ type: 'response.done', response: { status: 'completed', output: [] } });   // one response at a time
  rt.respond();
  assert.deepEqual(sock.sent[2], { type: 'response.create' });
});

test('addNote puts a system message with an id in the conversation and does not start a response', async () => {
  const { rt, sock } = await connected();
  const id = rt.addNote('KNOWLEDGE FOR THE CALLER: [1] Opening hours ...');
  assert.equal(id, 'kb_1');
  assert.deepEqual(sock.sent[0], {
    type: 'conversation.item.create',
    item: { id: 'kb_1', type: 'message', role: 'system', content: [{ type: 'input_text', text: 'KNOWLEDGE FOR THE CALLER: [1] Opening hours ...' }] },
  });
  assert.equal(sock.sent.filter((m) => m.type === 'response.create').length, 0);
  assert.equal(rt.addNote('second'), 'kb_2');                       // ids are unique
});

test('removeNote deletes that item from the conversation', async () => {
  const { rt, sock } = await connected();
  const id = rt.addNote('x');
  rt.removeNote(id);
  assert.deepEqual(sock.sent[1], { type: 'conversation.item.delete', item_id: 'kb_1' });
});

test('addNote on a closed connection gives no id (nothing to remove later)', async () => {
  const { rt } = await connected();
  rt.close();
  assert.equal(rt.addNote('x'), '');
});

test('say() tells the model the line is a scripted announcement to read, not something to answer', async () => {
  const { rt, sock } = await connected();
  rt.say('Putting you through to sales.');
  const r = sock.sent[0].response;
  assert.equal(r.tool_choice, 'none');
  assert.match(r.instructions, /scripted system announcement/);
  assert.match(r.instructions, /Ignore the conversation so far and any earlier notes/);
  assert.ok(r.instructions.includes('"Putting you through to sales."'));
});

test('a request made while a response is running waits and is sent when it finishes (the caller turn is not lost)', async () => {
  const { rt, sock } = await connected();
  rt.say('Hello, thanks for calling.');                          // the greeting
  assert.equal(sock.sent.length, 1);
  rt.respond();                                                  // caller says "Hello." while it is generated
  assert.equal(sock.sent.length, 1);                             // not sent: the API would refuse it
  sock.server({ type: 'response.created', response: { id: 'resp_1' } });
  sock.server({ type: 'response.done', response: { status: 'completed', output: [] } });
  assert.equal(sock.sent.length, 2);
  assert.deepEqual(sock.sent[1], { type: 'response.create' });   // sent now
  sock.server({ type: 'response.done', response: { status: 'completed', output: [] } });
  assert.equal(sock.sent.length, 2);                             // and only once
});

test('waiting requests keep their order, and two plain answers in a row are one', async () => {
  const { rt, sock } = await connected();
  rt.respond();
  rt.say('First announcement.');
  rt.respond();
  rt.respond();                                                  // duplicate of the queued plain one
  rt.say('Second announcement.');
  assert.equal(sock.sent.length, 1);
  const done = () => sock.server({ type: 'response.done', response: { status: 'completed', output: [] } });
  done(); assert.ok(sock.sent[1].response.instructions.includes('First announcement.'));
  done(); assert.deepEqual(sock.sent[2], { type: 'response.create' });
  done(); assert.ok(sock.sent[3].response.instructions.includes('Second announcement.'));
  done(); assert.equal(sock.sent.length, 4);
});

test('a handler that answers when a response ends goes first; the waiting one follows the next', async () => {
  const { rt, sock } = await connected();
  rt.respond();
  rt.say('Waiting line.');
  rt.on('responseDone', () => { if (sock.sent.length === 1) rt.respond({ toolChoice: 'none' }); });
  sock.server({ type: 'response.done', response: { status: 'completed', output: [] } });
  assert.equal(sock.sent.length, 2);                             // the handler's request, not both at once
  assert.equal(sock.sent[1].response.tool_choice, 'none');
  sock.server({ type: 'response.done', response: { status: 'completed', output: [] } });
  assert.equal(sock.sent.length, 3);
  assert.ok(sock.sent[2].response.instructions.includes('Waiting line.'));
});

test('a refused request does not leave the session stuck busy; an "already active" refusal does not free it', async () => {
  const { rt, sock } = await connected();
  rt.on('error', () => {});
  rt.respond();
  rt.say('Next.');
  sock.server({ type: 'error', error: { code: 'invalid_request_error', message: 'something else was wrong' } });
  assert.equal(sock.sent.length, 2);                             // freed: the waiting line goes out
  sock.server({ type: 'error', error: { message: 'Conversation already has an active response in progress: resp_x.' } });
  rt.respond();
  assert.equal(sock.sent.length, 2);                             // still busy, so this waits
});


test('a cancel race error is ignored; a real error is emitted without leaking the key', async () => {
  const { rt, sock } = await connected();
  const errors = [];
  rt.on('error', (e) => errors.push(e));
  sock.server({ type: 'error', error: { code: 'response_cancel_not_active', message: 'nothing to cancel' } });
  assert.equal(errors.length, 0);
  sock.server({ type: 'error', error: { code: 'invalid_api_key', message: 'Incorrect API key provided' } });
  assert.equal(errors.length, 1);
  assert.ok(!errors[0].message.includes('sk-test-secret-key'));
});

test('malformed server frames never throw', async () => {
  const { sock } = await connected();
  assert.doesNotThrow(() => sock.emit('message', 'not json at all'));
  assert.doesNotThrow(() => sock.emit('message', Buffer.from('{"type":"nope"}')));
});

test('voice and tool helpers', () => {
  assert.equal(pickRealtimeVoice('Marin'), 'marin');
  assert.equal(pickRealtimeVoice('some-deepgram-voice'), 'alloy');
  assert.equal(pickRealtimeVoice(undefined), 'alloy');
  assert.deepEqual(toRealtimeTool({ name: 'x' }), { type: 'function', name: 'x', description: '', parameters: { type: 'object', properties: {} } });
  assert.throws(() => new OpenAiRealtimeSession({}), /API key/);
});

// ---- Azure OpenAI ------------------------------------------------------------------------------
import { azureRealtimeOptions } from './openaiRealtime.js';

async function azureConnected() {
  const sock = new FakeSocket();
  let seen;
  const opts = azureRealtimeOptions({ apiKey: 'az-secret-key', endpoint: 'https://my-res.openai.azure.com/', deployment: 'rt-deploy' });
  const rt = new OpenAiRealtimeSession({ ...opts, createSocket: (url, options) => { seen = { url, options }; return sock; } });
  const p = rt.connect({ timeoutMs: 500 });
  sock.emit('open');
  await p;
  return { rt, sock, seen };
}

test('azure: address uses the deployment as the model, and the key goes in the api-key header only', async () => {
  const { seen } = await azureConnected();
  assert.equal(seen.url, 'wss://my-res.openai.azure.com/openai/v1/realtime?model=rt-deploy');
  assert.deepEqual(seen.options.headers, { 'api-key': 'az-secret-key' });
  assert.ok(!seen.url.includes('az-secret-key'));
});

test('azure: session uses 24 kHz PCM, no model field, and the whisper transcription model', async () => {
  const { rt, sock } = await azureConnected();
  rt.configure({ instructions: 'hi', voice: 'alloy', tools: [] });
  const s = sock.sent[0].session;
  assert.equal(s.model, undefined);
  assert.deepEqual(s.audio.input.format, { type: 'audio/pcm', rate: 24000 });
  assert.deepEqual(s.audio.output.format, { type: 'audio/pcm', rate: 24000 });
  assert.equal(s.audio.input.transcription.model, 'whisper-1');
  assert.equal(s.audio.input.turn_detection.create_response, false);
});

test('azure: phone audio is upsampled to 24 kHz going out and downsampled to 8 kHz coming back', async () => {
  const { rt, sock } = await azureConnected();
  rt.sendAudio(Buffer.alloc(320));                       // 20 ms @ 8 kHz
  assert.equal(Buffer.from(sock.sent[0].audio, 'base64').length, 960);   // 20 ms @ 24 kHz
  const got = [];
  rt.on('audio', (b) => got.push(b));
  const in24 = Buffer.alloc(24000 * 2 / 5);              // 200 ms @ 24 kHz
  sock.server({ type: 'response.output_audio.delta', delta: in24.toString('base64') });
  const bytes = got.reduce((n, b) => n + b.length, 0);
  assert.ok(Math.abs(bytes / 2 - 1600) <= 20, 'expected about 1600 samples @ 8 kHz, got ' + bytes / 2);
});

test('azure: the endpoint is checked, because the bridge connects to it carrying the key', () => {
  const ok = (endpoint) => azureRealtimeOptions({ apiKey: 'k', endpoint, deployment: 'd' });
  assert.equal(ok('https://a.openai.azure.com').url, 'wss://a.openai.azure.com/openai/v1/realtime?model=d');
  assert.equal(ok('https://a.cognitiveservices.azure.com/openai/deployments/x').url, 'wss://a.cognitiveservices.azure.com/openai/v1/realtime?model=d');
  assert.throws(() => ok('http://a.openai.azure.com'), /https/);
  assert.throws(() => ok('https://evil.example.com'), /Azure/);
  assert.throws(() => ok('https://openai.azure.com.evil.example.com'), /Azure/);
  assert.throws(() => ok('https://127.0.0.1'), /Azure/);
  assert.throws(() => ok('not a url'), /valid URL/);
  assert.throws(() => azureRealtimeOptions({ apiKey: 'k', endpoint: 'https://a.openai.azure.com', deployment: '' }), /deployment/);
  assert.throws(() => azureRealtimeOptions({ apiKey: '', endpoint: 'https://a.openai.azure.com', deployment: 'd' }), /API key/);
  // a deployment name cannot break out of the query string
  assert.equal(azureRealtimeOptions({ apiKey: 'k', endpoint: 'https://a.openai.azure.com', deployment: 'a&b=c' }).url.split('model=')[1], 'a%26b%3Dc');
});

test('openai default is unchanged: Bearer header, mu-law, model in the session', async () => {
  const { rt, sock, seen } = await connected();
  assert.deepEqual(seen.options.headers, { Authorization: 'Bearer sk-test-secret-key' });
  rt.configure({ instructions: 'x' });
  assert.equal(sock.sent[0].session.model, 'gpt-realtime');
  assert.deepEqual(sock.sent[0].session.audio.input.format, { type: 'audio/pcmu' });
});

// ---- wideband (live relay) ----
async function connectedWide(opts) {
  const sock = new FakeSocket();
  const rt = new OpenAiRealtimeSession({ apiKey: 'sk-test', createSocket: () => sock, ...opts });
  const p = rt.connect({ timeoutMs: 500 });
  sock.emit('open');
  await p;
  return { rt, sock };
}

test('wideband: the caller goes in as 24 kHz PCM and the agent comes back as 24 kHz PCM', async () => {
  const { rt, sock } = await connectedWide({ wideInput: true, wideOutput: true });
  rt.configure({ instructions: 'x', voice: 'marin', tools: [], language: 'English' });
  const s = sock.sent.find((m) => m.type === 'session.update').session;
  assert.deepEqual(s.audio.input.format, { type: 'audio/pcm', rate: 24000 });
  assert.deepEqual(s.audio.output.format, { type: 'audio/pcm', rate: 24000 });
  const heard = [];
  rt.on('audio', (pcm, rate) => heard.push([pcm.length, rate]));
  const pcm24 = Buffer.alloc(480);                         // 10 ms @ 24 kHz
  sock.server({ type: 'response.output_audio.delta', delta: pcm24.toString('base64') });
  assert.deepEqual(heard, [[480, 24000]]);                 // untouched, and the rate says so
});

test('wideband: 16 kHz caller audio is sent on as 1.5x as many 24 kHz samples', async () => {
  const { rt, sock } = await connectedWide({ wideInput: true });
  rt.sendAudioWide(Buffer.alloc(640));                      // 20 ms @ 16 kHz = 320 samples
  const sent = sock.sent.filter((m) => m.type === 'input_audio_buffer.append').pop();
  const samples = Buffer.from(sent.audio, 'base64').length / 2;
  assert.ok(samples >= 478 && samples <= 480, 'got ' + samples);   // the last sample waits for its neighbour
});

test('wideband input with a narrowband voice: input is 24 kHz PCM, output stays mu-law 8 kHz', async () => {
  const { rt, sock } = await connectedWide({ wideInput: true });
  rt.configure({ instructions: 'x', voice: 'marin', tools: [], language: 'English' });
  const s = sock.sent.find((m) => m.type === 'session.update').session;
  assert.deepEqual(s.audio.input.format, { type: 'audio/pcm', rate: 24000 });
  assert.deepEqual(s.audio.output.format, { type: 'audio/pcmu' });
});

test('narrowband sessions are unchanged, and a wideband frame offered to one is brought down to 8 kHz', async () => {
  const { rt, sock } = await connectedWide({});
  rt.configure({ instructions: 'x', voice: 'marin', tools: [], language: 'English' });
  const s = sock.sent.find((m) => m.type === 'session.update').session;
  assert.deepEqual(s.audio.input.format, { type: 'audio/pcmu' });
  rt.sendAudioWide(Buffer.alloc(640));                      // 320 samples @ 16 kHz -> 160 mu-law bytes @ 8 kHz
  const sent = sock.sent.filter((m) => m.type === 'input_audio_buffer.append').pop();
  const bytes = Buffer.from(sent.audio, 'base64').length;
  assert.ok(bytes >= 158 && bytes <= 160, 'got ' + bytes);
});
