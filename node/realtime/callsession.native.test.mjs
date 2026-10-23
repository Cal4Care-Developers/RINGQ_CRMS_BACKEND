// Run with:  node --test realtime/callsession.native.test.mjs
//
// claude_server.js starts servers when imported and does not export CallSession, so this test lifts
// the REAL native-voice methods out of the source text and runs them against fakes (ESL, Realtime
// socket, MCP tools). If those methods change, this exercises the changed code.
import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { azureRealtimeOptions, toIsoLanguage } from './openaiRealtime.js';

const here = path.dirname(fileURLToPath(import.meta.url));
// CRLF checkouts (Windows) must not change what the markers below match.
const source = fs.readFileSync(path.join(here, '..', 'claude_server.js'), 'utf8').split(String.fromCharCode(13, 10)).join(String.fromCharCode(10));
const start = source.indexOf('  useNativeVoice() {');
const endMarker = "  // MR AI changes - ends\n\n  // MR AI changes - starts\n  // Why this call cannot be answered";
const end = source.indexOf(endMarker, start);
assert.ok(start > 0 && end > start, 'native voice block not found in claude_server.js');
const methods = source.slice(start, end);

const confirmSrc = source.slice(source.indexOf('// <<CONFIRM-BEGIN>>'), source.indexOf('// <<CONFIRM-END>>'));
assert.ok(confirmSrc.length > 100, 'confirm block not found in claude_server.js');
const { classifyConfirmation, describeGatedAction, CONFIRM_REASK_LIMIT } =
  new Function(confirmSrc + '\nreturn { classifyConfirmation, describeGatedAction, CONFIRM_REASK_LIMIT };')();

// fallbackDestination() reads destinations with the bridge's own parser.
const parseStart = source.indexOf('function parseTransferDestination(raw)');
const parseTransferDestination =
  new Function(source.slice(parseStart, source.indexOf('// MR AI changes - ends', parseStart)) + String.fromCharCode(10) + 'return parseTransferDestination;')();

// The agent's prompt reaches the provider wrapped in the language lock (see _nativeInstructions()): lock, prompt, lock.
const promptOf = (instructions) => {
  const parts = String(instructions).split(String.fromCharCode(10, 10));
  assert.equal(parts.length, 3, 'lock + prompt + lock');
  assert.match(parts[0], /^LANGUAGE LOCK: you speak ONLY English/);
  assert.equal(parts[0], parts[2]);
  return parts[1];
};

const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'native-test-'));

class FakeRealtime extends EventEmitter {
  static instances = [];
  static failConnect = false;
  constructor(opts) {
    super();
    this.opts = opts;
    this.calls = [];
    this.responseActive = false;
    FakeRealtime.instances.push(this);
  }
  async connect() { if (FakeRealtime.failConnect) throw new Error('connect refused'); }
  configure(c) { this.calls.push(['configure', c]); }
  reconfigure(c) { this.calls.push(['reconfigure', c]); }
  async waitReady() {}
  sendAudio(b) { this.calls.push(['sendAudio', b.length]); }
  respond(o) { this.calls.push(['respond', o]); }
  say(t) { this.calls.push(['say', t]); }
  addNote(t) { this.noteSeq = (this.noteSeq || 0) + 1; this.calls.push(['addNote', t]); return 'kb_' + this.noteSeq; }
  removeNote(id) { this.calls.push(['removeNote', id]); }
  cancel() { this.calls.push(['cancel']); return true; }
  sendToolResult(id, out) { this.calls.push(['toolResult', id, out]); }
  close() { this.calls.push(['close']); }
  of(kind) { return this.calls.filter((c) => c[0] === kind); }
}

class FakeGemini extends FakeRealtime {
  constructor(opts) {
    super(opts);
    this.autoRespond = true;
    this.needsToolsAtStart = true;
    this.live = '';
  }
  pendingUserText() { return this.live; }
}

function makeSession(overrides = {}) {
  const esl = [];
  const log = { transfers: [], hangups: 0, standardVoice: 0, interrupts: 0, toolsRun: [], gated: [], transcript: [], aiReplies: [], reasons: [], missNext: false, note: '' };
  const factory = new Function(
    'EventEmitter', 'fs', 'path', 'OpenAiRealtimeSession', 'DEFAULT_REALTIME_MODEL', 'SAMPLE_RATE', 'TTS_TMP_DIR',
    'GREETING', 'waitForEsl', 'eslApi', 'pcm16ToWav', 'matchEndCallPhrase', 'gatedActionFailureMessage',
    'gatedActionDeclineMessage', 'azureRealtimeOptions', 'GeminiLiveSession', 'DEFAULT_GEMINI_LIVE_MODEL', 'GATED_TOOLS', 'log',
    'classifyConfirmation', 'describeGatedAction', 'CONFIRM_REASK_LIMIT', 'parseTransferDestination', 'toIsoLanguage',
    `return class T {
      constructor() {
        this.generation = 0; this.speakChain = Promise.resolve(); this.speechEndsAt = 0; this.speaking = false;
        this.ended = false; this.transferred = false; this.transferring = false; this.callUuid = 'uuid-1';
        this.playCounter = 0; this.ttsFiles = []; this.pendingAudio = []; this.mcpTools = [];
        this.nativeRt = null; this.nativeReady = false; this.nativeBuf = []; this.nativeBufBytes = 0;
        this.nativePumping = false; this.nativeAudioDone = false; this.nativeDropAudio = false;
        this.nativeLastAudioAt = 0; this.nativeToolRounds = 0; this.nativeTurnQueue = Promise.resolve();
        this.modelProvider = 'openai'; this.pendingGatedCall = null; this.greeted = false;
        this.knowledgeNoteIds = []; this.heldReadback = null; this.modelRequestedTransferExtension = null; this.modelRequestedTransferReason = null; this.modelRequestedEndCall = false; this.systemPromptLogged = true;
        this.agentConfig = { name: 'Test', enabled: true, voiceMode: 'native', providerApiKey: 'sk-x', voice: 'Marin', greeting: 'Hello from Test' };
      }
      providerSetupProblem() { return ''; }
      sttKeyterms() { return ['RingQ', 'Test']; }
      async knowledgeNoteFor() { return log.note; }
      noteTranscript(role, text) { log.transcript.push([role, text]); }
      noteAiReplyForEscalation(t) { log.aiReplies.push(t); }
      checkEscalation(text) { return text.includes('human') ? { reason: 'caller asked for a person', message: 'Connecting you with a person.', extension: '1007' } : null; }
      missEscalation() { if (!log.missNext) return null; log.missNext = false; return { reason: 'the AI could not answer 2 times in a row', message: 'Let me get a person for you.', extension: '1007' }; }
      ensureMcpClient() { return this._mcp || Promise.resolve(); }
      async ensureCallerLookup() {}
      currentSystemPrompt() { return { text: 'PROMPT tools=' + this.mcpTools.map((t) => t.name).join(',') }; }
      isPlayingAudio() { return Date.now() < this.speechEndsAt - 200; }
      async awaitPlayout() { while (Date.now() < this.speechEndsAt) await new Promise((r) => setTimeout(r, 10)); }
      interrupt() { log.interrupts++; this.generation++; this.speakChain = Promise.resolve(); this.speechEndsAt = 0; }
      async _beginStandardVoice() { log.standardVoice++; }
      transferFlowTurn(text) {
        if (text.includes('okay')) return { say: 'Putting you through to sales.', transfer: { extension: '1001', reason: 'caller confirmed a transfer to "sales"' } };
        if (text.includes('sales')) return { say: 'Would you like me to transfer you to sales?' };
        return null;
      }
      matchTransferPhraseInReply(s) { return s.includes('transferring you') ? '1002' : null; }
      transferCall(ext, reason) { log.transfers.push(ext); log.reasons.push(reason); }
      hangupCall() { log.hangups++; }
      async callMcpTool(name, args) {
        log.toolsRun.push([name, args]);
        if (GATED_TOOLS.has(name)) { this.pendingGatedCall = { name, args }; this.heldReadback = describeGatedAction(name, args).readback; }
        return JSON.stringify({ status: 'needs_confirmation' });
      }
      async executeGatedCall(name, args) { log.gated.push([name, args]); return { spoken: 'Done, added ' + args.summary + '.' }; }
      ${methods}
    }`
  );
  const T = factory(
    EventEmitter, fs, path, FakeRealtime, 'gpt-realtime', 8000, TMP, 'default greeting',
    async () => {}, async (cmd) => { esl.push(cmd); return '+OK'; },
    (pcm) => Buffer.concat([Buffer.alloc(44), pcm]),
    (s) => s.toLowerCase().includes('goodbye'),
    (n) => 'failed ' + n, (n) => 'declined ' + n, azureRealtimeOptions, FakeGemini, 'gemini-3.8-live', new Set(['create_calendar_event', 'send_email']), log,
    classifyConfirmation, describeGatedAction, CONFIRM_REASK_LIMIT, parseTransferDestination, toIsoLanguage
  );
  const s = new T();
  Object.assign(s, overrides);
  return { s, esl, log };
}

const pcmMs = (ms) => Buffer.alloc(Math.round(8000 * 2 * (ms / 1000)));

async function started(overrides) {
  FakeRealtime.instances.length = 0;
  FakeRealtime.failConnect = false;
  const ctx = makeSession(overrides);
  await ctx.s.startNativeVoice();
  return { ...ctx, rt: FakeRealtime.instances[0] };
}

test('native route is chosen only for an enabled, keyed OpenAI agent that opted in', () => {
  const { s } = makeSession();
  assert.equal(s.useNativeVoice(), true);
  s.agentConfig.voiceMode = 'standard';
  assert.equal(s.useNativeVoice(), false);
  s.agentConfig.voiceMode = 'native';
  s.modelProvider = 'claude';
  assert.equal(s.useNativeVoice(), false);
  s.modelProvider = 'openai';
  s.agentConfig.providerApiKey = '';
  assert.equal(s.useNativeVoice(), false);
  s.agentConfig.providerApiKey = 'sk-x';
  s.agentConfig.enabled = false;
  assert.equal(s.useNativeVoice(), false);
});

test('start: opens Realtime with the agent key, configures, buffers early audio, then greets', async () => {
  FakeRealtime.instances.length = 0;
  const { s } = makeSession();
  s.pendingAudio = [Buffer.alloc(320), Buffer.alloc(320)];
  await s.startNativeVoice();
  const rt = FakeRealtime.instances[0];
  assert.equal(rt.opts.apiKey, 'sk-x');
  assert.equal(rt.opts.model, 'gpt-realtime');
  assert.equal(rt.of('configure')[0][1].voice, 'Marin');
  assert.equal(promptOf(rt.of('configure')[0][1].instructions), 'PROMPT tools=');
  assert.equal(rt.of('configure')[0][1].vocabulary, undefined);   // no word list for the transcriber to echo on noise
  assert.equal(rt.of('sendAudio').length, 2);          // early caller audio was not lost
  assert.deepEqual(rt.of('say')[0], ['say', 'Hello from Test']);
  assert.equal(s.greeted, true);
  assert.equal(s.nativeReady, true);
});

test('start: tools that connect later are added to the live session', async () => {
  FakeRealtime.instances.length = 0;
  const { s } = makeSession();
  let release;
  s._mcp = new Promise((r) => { release = r; });
  await s.startNativeVoice();
  const rt = FakeRealtime.instances[0];
  assert.equal(rt.of('reconfigure').length, 0);
  s.mcpTools = [{ name: 'check_availability' }];
  release();
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(rt.of('reconfigure').length, 1);
  assert.equal(promptOf(rt.of('reconfigure')[0][1].instructions), 'PROMPT tools=check_availability');
  assert.equal(rt.of('reconfigure')[0][1].tools[0].name, 'check_availability');
});

test('start: if Realtime cannot be opened the call falls back to the standard voice engine', async () => {
  FakeRealtime.instances.length = 0;
  FakeRealtime.failConnect = true;
  const { s, log } = makeSession();
  await s.startNativeVoice();
  FakeRealtime.failConnect = false;
  assert.equal(log.standardVoice, 1);
  assert.equal(s.nativeRt, null);
  assert.equal(s.nativeReady, false);
});

test('a normal caller turn lets the model answer', async () => {
  const { s, rt, log } = await started();
  s.nativeToolRounds = 3;
  await s._nativeUserTurn('what are your opening hours');
  assert.deepEqual(log.transcript.pop(), ['caller', 'what are your opening hours']);   // kept for the call record
  assert.equal(s.nativeToolRounds, 0);
  assert.deepEqual(rt.of('respond').pop(), ['respond', undefined]);
});

test('confirm-before-acting: "yes" runs the held action in code and the agent says the outcome', async () => {
  const { s, rt, log } = await started();
  s.pendingGatedCall = { name: 'create_calendar_event', args: { summary: 'Demo' } };
  await s._nativeUserTurn('yes please go ahead');
  assert.deepEqual(log.gated, [['create_calendar_event', { summary: 'Demo' }]]);
  assert.deepEqual(rt.of('say').pop(), ['say', 'Done, added Demo.']);
  assert.equal(s.pendingGatedCall, null);
  assert.equal(rt.of('respond').length, 0);             // the model did not get to answer on its own
});

test('confirm-before-acting: "no" declines and nothing is executed', async () => {
  const { s, rt, log } = await started();
  s.pendingGatedCall = { name: 'send_email', args: { to: 'a@b.c' } };
  await s._nativeUserTurn('no, cancel that');
  assert.equal(log.gated.length, 0);
  assert.deepEqual(rt.of('say').pop(), ['say', 'declined send_email']);
});

test('confirm-before-acting: an unclear reply drops the hold and the turn goes on normally', async () => {
  const { s, rt, log } = await started();
  s.pendingGatedCall = { name: 'send_email', args: {} };
  await s._nativeUserTurn('hmm what about tuesday');
  assert.equal(log.gated.length, 0);
  assert.equal(s.pendingGatedCall, null);
  assert.equal(rt.of('respond').length, 1);
});

test('a transfer keyword is asked about first: nothing is transferred until the caller agrees', async () => {
  const { s, rt, log } = await started();
  await s._nativeUserTurn('I want to talk to sales');
  assert.deepEqual(rt.of('say').pop(), ['say', 'Would you like me to transfer you to sales?']);
  assert.equal(rt.of('respond').length, 0);              // the model was not asked to answer
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.deepEqual(log.transfers, []);                    // the question has been asked, no more
});

test('once the caller says ok, the keyword message is spoken and the transfer happens after the audio has played', async () => {
  const { s, rt, log } = await started();
  await s._nativeUserTurn('I want to talk to sales');
  await s._nativeUserTurn('okay');
  assert.deepEqual(rt.of('say').pop(), ['say', 'Putting you through to sales.']);
  assert.deepEqual(log.transfers, []);                    // not before the caller has heard it
  s._nativeAssistantSaid('Putting you through to sales.');
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.deepEqual(log.transfers, ['1001']);
  assert.deepEqual(log.reasons, ['caller confirmed a transfer to "sales"']);
});

test('escalation: a caller asking for a person is told, then transferred with the reason once the audio has played', async () => {
  const { s, rt, log } = await started();
  await s._nativeUserTurn('I want to speak to a human');
  assert.deepEqual(rt.of('say').pop(), ['say', 'Connecting you with a person.']);
  assert.equal(rt.of('respond').length, 0);              // the model was not asked to answer
  assert.deepEqual(log.transfers, []);                    // not before the caller has heard it
  s._nativeAssistantSaid('Connecting you with a person.');
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.deepEqual(log.transfers, ['1007']);
  assert.deepEqual(log.reasons, ['caller asked for a person']);
  assert.equal(s.modelRequestedTransferReason, null);     // consumed
});

test('escalation: after an answer where the AI keeps failing, a person is offered and then transferred', async () => {
  const { s, rt, log } = await started();
  log.missNext = true;
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.deepEqual(rt.of('say').pop(), ['say', 'Let me get a person for you.']);
  assert.deepEqual(log.transfers, []);                    // said first, transferred after it plays
  s._nativeAssistantSaid('Let me get a person for you.');
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.deepEqual(log.transfers, ['1007']);
  assert.deepEqual(log.reasons, ['the AI could not answer 2 times in a row']);
});

test('escalation: every agent reply is fed to the failure counter', () => {
  const { s, log } = makeSession();
  s._nativeAssistantSaid('We open at nine.');
  assert.deepEqual(log.aiReplies, ['We open at nine.']);
});

test('knowledge: the passages found for the caller go in BEFORE the model is asked to answer', async () => {
  const { s, rt, log } = await started();
  log.note = 'KNOWLEDGE FOR THE CALLER\'S LAST QUESTION: [1] Opening hours ...';
  await s._nativeUserTurn('what are your opening hours');
  const names = rt.calls.map((c) => c[0]);
  assert.ok(names.includes('addNote') && names.includes('respond'));
  assert.ok(names.lastIndexOf('addNote') < names.lastIndexOf('respond'), 'note must precede the answer request');
  assert.equal(rt.calls.find((c) => c[0] === 'addNote')[1], log.note);
});

test('knowledge: nothing to add (greeting, no knowledge, failed lookup) -> the model is just asked to answer', async () => {
  const { s, rt, log } = await started();
  log.note = '';
  await s._nativeUserTurn('what are your opening hours');
  assert.equal(rt.calls.filter((c) => c[0] === 'addNote').length, 0);
  assert.equal(rt.of('respond').length, 1);
});

test('knowledge: a call that ended while the lookup ran is not answered into', async () => {
  const { s, rt } = await started();
  s.knowledgeNoteFor = async () => { s.ended = true; return 'NOTE'; };
  await s._nativeUserTurn('what are your opening hours');
  assert.equal(rt.calls.filter((c) => c[0] === 'addNote' || c[0] === 'respond').length, 0);
});

test('knowledge notes: the previous question\'s note is taken out before the next caller turn says anything', async () => {
  const { s, rt, log } = await started();
  log.note = 'NOTE-1: nothing matched';
  await s._nativeUserTurn('what are your opening hours');          // note kb_1 added, model answers
  assert.deepEqual(s.knowledgeNoteIds, ['kb_1']);
  log.note = '';
  await s._nativeUserTurn('I want to talk to sales');               // the stub asks the keyword confirmation via say()
  const names = rt.calls.map((c) => c[0]);
  assert.ok(names.includes('removeNote'), 'old note removed');
  assert.deepEqual(rt.calls.find((c) => c[0] === 'removeNote'), ['removeNote', 'kb_1']);
  assert.ok(names.indexOf('removeNote') < names.lastIndexOf('say'), 'removed BEFORE the scripted line is spoken');
  assert.deepEqual(s.knowledgeNoteIds, []);
});

test('knowledge notes: a turn that needs no note still clears the old one, and nothing is removed twice', async () => {
  const { s, rt, log } = await started();
  log.note = 'NOTE';
  await s._nativeUserTurn('what are your opening hours');
  log.note = '';
  await s._nativeUserTurn('what about weekends');
  await s._nativeUserTurn('and holidays');
  assert.equal(rt.calls.filter((c) => c[0] === 'removeNote').length, 1);
});

test('knowledge notes: the transfer announcement is spoken with no stale note left in the conversation (the logged call)', async () => {
  const { s, rt, log } = await started();
  log.note = 'KNOWLEDGE FOR THE CALLER\'S LAST QUESTION: nothing in this business\'s documents matched it.';
  await s._nativeUserTurn('Testemunha');                            // "nothing matched" note goes in
  assert.equal(s.knowledgeNoteIds.length, 1);
  await s._nativeUserTurn('okay');                                  // the caller agrees -> bridge announces the transfer
  const names = rt.calls.map((c) => c[0]);
  assert.ok(names.indexOf('removeNote') < names.lastIndexOf('say'));
  assert.deepEqual(rt.of('say').pop(), ['say', 'Putting you through to sales.']);
});

test('confirm: "okay" confirms the held action (it was never understood as a yes before)', async () => {
  const { s, rt, log } = await started();
  s.pendingGatedCall = { name: 'create_calendar_event', args: { summary: 'Demo' } };
  await s._nativeUserTurn('Okay.');
  assert.deepEqual(log.gated, [['create_calendar_event', { summary: 'Demo' }]]);
  assert.deepEqual(rt.of('say').pop(), ['say', 'Done, added Demo.']);
});

test('confirm: noise and "wait a moment" keep the action waiting and the BRIDGE answers, not the model', async () => {
  const { s, rt, log } = await started();
  s.pendingGatedCall = { name: 'create_calendar_event', args: { summary: 'Demo' } };
  await s._nativeUserTurn('Wait, wait, one minute.');
  assert.match(rt.of('say').pop()[1], /take your time/);
  await s._nativeUserTurn('Hi.');
  assert.match(rt.of('say').pop()[1], /Please say yes or no/);
  assert.equal(rt.of('respond').length, 0, 'the model was not asked to answer');
  assert.ok(s.pendingGatedCall, 'still waiting');
  assert.equal(log.gated.length, 0, 'nothing executed yet');
  await s._nativeUserTurn('Yes, schedule the meeting now.');
  assert.equal(log.gated.length, 1);
});

test('confirm: real new content ends the wait and goes to the model', async () => {
  const { s, rt, log } = await started();
  s.pendingGatedCall = { name: 'create_calendar_event', args: { summary: 'Demo' } };
  await s._nativeUserTurn('abcd@cardpoolsquare.com can you add this person');
  assert.equal(s.pendingGatedCall, null);
  assert.equal(log.gated.length, 0);
  assert.equal(rt.of('respond').length, 1);
});

test('held action: after the tool round the BRIDGE reads the details back; the model is not asked to answer', async () => {
  const { s, rt, log } = await started();
  await s._nativeRunTools([{ callId: 'c1', name: 'create_calendar_event',
    args: { summary: 'Meeting', start: '2026-10-09T15:20:00+08:00', end: '2026-10-09T18:50:00+08:00', attendees: ['sa@corp.cal4care.com'] } }]);
  assert.equal(rt.calls.filter((c) => c[0] === 'toolResult').length, 1, 'the tool result still goes back (the API requires it)');
  assert.deepEqual(rt.of('say').pop(), ['say', 'I will create the event "Meeting" on Friday 9 October from 3:20 PM to 6:50 PM, with sa at corp dot cal4care dot com. Shall I go ahead?']);
  assert.equal(rt.of('respond').length, 0, 'the model cannot announce a result that has not happened');
  assert.equal(s.heldReadback, null, 'consumed');
});

test('held action: an ordinary tool still lets the model answer', async () => {
  const { s, rt } = await started();
  const saidBefore = rt.of('say').length;                // the greeting
  await s._nativeRunTools([{ callId: 'c2', name: 'check_availability', args: { start: 'x' } }]);
  assert.equal(rt.of('respond').length, 1);
  assert.equal(rt.of('say').length, saidBefore, 'the bridge adds no read-back for an ordinary tool');
});

test('a goodbye the agent says hangs up after the answer, and a transfer phrase is picked up', async () => {
  const { s, log } = await started();
  s._nativeAssistantSaid('Thanks for calling. Goodbye.');
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(log.hangups, 1);
  s._nativeAssistantSaid('One moment, transferring you now.');
  s._nativeResponseDone({ status: 'completed', toolCalls: [] });
  await new Promise((r) => setTimeout(r, 30));
  assert.deepEqual(log.transfers, ['1002']);
});

test('tool calls run through the gate, results go back, then the model continues', async () => {
  const { s, rt, log } = await started();
  s._nativeResponseDone({ status: 'completed', toolCalls: [{ callId: 'c1', name: 'check_availability', args: { day: 'Fri' } }] });
  await s.nativeTurnQueue;
  assert.deepEqual(log.toolsRun, [['check_availability', { day: 'Fri' }]]);
  assert.equal(rt.of('toolResult')[0][1], 'c1');
  assert.deepEqual(rt.of('respond').pop(), ['respond', undefined]);
});

test('runaway tool loops are stopped: after six rounds tools are refused and tool_choice is none', async () => {
  const { s, rt, log } = await started();
  for (let i = 0; i < 7; i++) {
    s._nativeResponseDone({ status: 'completed', toolCalls: [{ callId: 'c' + i, name: 'x', args: {} }] });
    await s.nativeTurnQueue;
  }
  assert.equal(log.toolsRun.length, 6);
  const last = rt.of('toolResult').pop();
  assert.ok(String(last[2]).includes('too many tool calls'));
  assert.deepEqual(rt.of('respond').pop(), ['respond', { toolChoice: 'none' }]);
});

test('agent audio is written as WAV files and broadcast in order, one after the other', async () => {
  const { s, rt, esl } = await started();
  rt.emit('responseStarted');
  rt.emit('audio', pcmMs(400));
  rt.emit('audio', pcmMs(200));
  rt.emit('audioDone');
  rt.emit('responseDone', { status: 'completed', toolCalls: [] });
  await s.speakChain;
  const broadcasts = esl.filter((c) => c.startsWith('uuid_broadcast uuid-1 '));
  assert.ok(broadcasts.length >= 1, 'nothing was broadcast');
  assert.ok(broadcasts.every((c) => c.endsWith(' aleg') && c.includes('tts_uuid-1_')));
  const file = broadcasts[0].split(' ')[2];
  assert.ok(fs.existsSync(file));
  assert.equal(s.nativePumping, false);
  assert.equal(s.ttsFiles.length, broadcasts.length);   // every file is tracked for clean-up
});

test('the caller talking over the agent cancels the answer, silences the line and drops late audio', async () => {
  const { s, rt, log, esl } = await started();
  rt.emit('responseStarted');
  rt.responseActive = true;
  rt.emit('audio', pcmMs(600));
  await new Promise((r) => setTimeout(r, 20));          // the first chunk is now "playing"
  rt.emit('speechStarted');
  assert.equal(log.interrupts, 1);
  assert.equal(rt.of('cancel').length, 1);
  assert.equal(s.nativeDropAudio, true);
  const before = esl.length;
  rt.emit('audio', pcmMs(300));                         // tail of the cancelled answer
  assert.equal(s.nativeBufBytes, 0);
  await new Promise((r) => setTimeout(r, 30));
  assert.equal(esl.filter((c) => c.startsWith('uuid_broadcast')).length, esl.slice(0, before).filter((c) => c.startsWith('uuid_broadcast')).length);
  rt.emit('responseStarted');                           // the next answer plays normally again
  assert.equal(s.nativeDropAudio, false);
});

test('a dropped Realtime connection mid-call transfers the caller; closing at hang-up does not', async () => {
  const a = await started();
  a.rt.emit('close');
  assert.equal(a.log.transfers.length, 1);
  const b = await started();
  b.s.ended = true;
  b.rt.emit('close');
  assert.equal(b.log.transfers.length, 0);
});

test('ignores caller turns once the call is over or handed to a person', async () => {
  const { s, rt } = await started();
  s.transferred = true;
  await s._nativeUserTurn('hello?');
  assert.equal(rt.of('respond').length, 0);
});

// ---- Azure OpenAI ------------------------------------------------------------------------------
const azureAgent = {
  name: 'Az', enabled: true, voiceMode: 'native', providerApiKey: 'az-key', voice: 'alloy', greeting: 'Hi from Azure',
  providerEndpoint: 'https://my-res.openai.azure.com', providerDeployment: 'chat-deploy', realtimeDeployment: 'rt-deploy',
};

test('azure: native needs the key, the endpoint AND a realtime deployment (the chat deployment is not enough)', () => {
  const { s } = makeSession({ modelProvider: 'azure openai', agentConfig: { ...azureAgent } });
  assert.equal(s.useNativeVoice(), true);
  s.agentConfig.realtimeDeployment = '';
  assert.equal(s.useNativeVoice(), false);
  s.agentConfig.realtimeDeployment = 'rt-deploy';
  s.agentConfig.providerEndpoint = '';
  assert.equal(s.useNativeVoice(), false);
  s.agentConfig.providerEndpoint = 'https://my-res.openai.azure.com';
  s.agentConfig.voiceMode = 'standard';
  assert.equal(s.useNativeVoice(), false);
});

test('azure: the session is opened on the realtime deployment with the api-key header and 24 kHz audio', async () => {
  FakeRealtime.instances.length = 0;
  const { s } = makeSession({ modelProvider: 'azure openai', agentConfig: { ...azureAgent } });
  await s.startNativeVoice();
  const o = FakeRealtime.instances[0].opts;
  assert.equal(o.url, 'wss://my-res.openai.azure.com/openai/v1/realtime?model=rt-deploy');
  assert.deepEqual(o.headers, { 'api-key': 'az-key' });
  assert.equal(o.audioFormat, 'pcm24');
  assert.deepEqual(FakeRealtime.instances[0].of('say')[0], ['say', 'Hi from Azure']);
});

test('azure: an endpoint outside Azure is never dialled -- the call falls back to the standard engine', async () => {
  FakeRealtime.instances.length = 0;
  const { s, log } = makeSession({ modelProvider: 'azure openai', agentConfig: { ...azureAgent, providerEndpoint: 'https://evil.example.com' } });
  await s.startNativeVoice();
  assert.equal(FakeRealtime.instances.length, 0);       // no socket was created, so no key was sent anywhere
  assert.equal(log.standardVoice, 1);
});

// ---- Gemini Live ---------------------------------------------------------------------------------
const geminiAgent = { name: 'Gem', enabled: true, voiceMode: 'native', providerApiKey: 'g-key', voice: 'Kore', greeting: 'Hi from Gemini' };

async function geminiStarted(extra = {}) {
  FakeRealtime.instances.length = 0;
  const ctx = makeSession({ modelProvider: 'gemini', agentConfig: { ...geminiAgent }, ...extra });
  await ctx.s.startNativeVoice();
  return { ...ctx, rt: FakeRealtime.instances[0] };
}

test('gemini: chosen for a keyed, opted-in agent; opened with its own model default', async () => {
  const { s, rt } = await geminiStarted();
  assert.equal(s.useNativeVoice(), true);
  assert.equal(rt.opts.apiKey, 'g-key');
  assert.equal(rt.opts.model, 'gemini-3.8-live');
  assert.equal(FakeRealtime.instances.length, 1);
  assert.ok(rt instanceof FakeGemini);
});

test('gemini: the session is not opened until the tools are connected (setup is one-shot)', async () => {
  FakeRealtime.instances.length = 0;
  const { s } = makeSession({ modelProvider: 'gemini', agentConfig: { ...geminiAgent } });
  let release;
  s._mcp = new Promise((r) => { release = r; });
  const starting = s.startNativeVoice();
  await new Promise((r) => setTimeout(r, 30));
  const rt = FakeRealtime.instances[0];
  assert.equal(rt.of('configure').length, 0);
  s.mcpTools = [{ name: 'check_availability' }];
  release();
  await starting;
  assert.equal(rt.of('configure').length, 1);
  assert.equal(rt.of('configure')[0][1].tools[0].name, 'check_availability');
  assert.equal(promptOf(rt.of('configure')[0][1].instructions), 'PROMPT tools=check_availability');
  assert.equal(rt.of('reconfigure').length, 0);          // nothing can be added later on Gemini
  assert.deepEqual(rt.of('say')[0], ['say', 'Hi from Gemini']);
});

test('gemini: a failed tool connection does not block the call from starting', async () => {
  FakeRealtime.instances.length = 0;
  const { s } = makeSession({ modelProvider: 'gemini', agentConfig: { ...geminiAgent } });
  s._mcp = Promise.reject(new Error('mcp down'));
  await s.startNativeVoice();
  assert.equal(FakeRealtime.instances[0].of('configure').length, 1);
});

test('gemini: caller turns are noted but never answered by us (the provider answers itself)', async () => {
  const { s, rt } = await geminiStarted();
  rt.emit('userTranscript', 'what time do you open');
  await s.nativeTurnQueue;
  assert.equal(s.nativeLastUserText, 'what time do you open');
  assert.equal(s.nativeUserTurns, 1);
  assert.equal(rt.of('respond').length, 0);
  assert.equal(rt.of('say').length, 1);                  // just the greeting
});

test('gemini confirm gate: first call is held; "yes" afterwards carries it out with the arguments the caller heard', async () => {
  const { s, rt, log } = await geminiStarted();
  const first = JSON.parse(await s._nativeCallTool('create_calendar_event', { summary: 'Demo', start: '2026-10-09T10:00' }));
  assert.equal(first.status, 'needs_confirmation');
  assert.equal(log.gated.length, 0);
  assert.equal(s.pendingGatedCall.name, 'create_calendar_event');

  rt.emit('userTranscript', 'yes please go ahead');
  // the model repeats the call, with the same details in a different order and case
  const second = JSON.parse(await s._nativeCallTool('create_calendar_event', { start: '2026-10-09T10:00', summary: 'demo' }));
  assert.equal(second.status, 'done');
  assert.deepEqual(log.gated, [['create_calendar_event', { summary: 'Demo', start: '2026-10-09T10:00' }]]);   // the proposal, not the repeat
  assert.equal(s.pendingGatedCall, null);
});

test('gemini confirm gate: "no" declines and nothing runs', async () => {
  const { s, rt, log } = await geminiStarted();
  await s._nativeCallTool('send_email', { to: 'a@b.c' });
  rt.emit('userTranscript', 'no, cancel that');
  const out = JSON.parse(await s._nativeCallTool('send_email', { to: 'a@b.c' }));
  assert.equal(out.status, 'declined');
  assert.equal(log.gated.length, 0);
  assert.equal(s.pendingGatedCall, null);
});

test('gemini confirm gate: the model repeating the call without the caller saying anything does NOT run it', async () => {
  const { s, log } = await geminiStarted();
  await s._nativeCallTool('send_email', { to: 'a@b.c' });
  const out = JSON.parse(await s._nativeCallTool('send_email', { to: 'a@b.c' }));   // waits ~1.5 s for a reply, hears none
  assert.equal(out.status, 'needs_confirmation');
  assert.equal(log.gated.length, 0);
  assert.notEqual(s.pendingGatedCall, null);                                       // still held
});

test('gemini confirm gate: a "yes" that has not been reported as a finished turn yet is still honoured once it settles', async () => {
  const { s, rt, log } = await geminiStarted();
  await s._nativeCallTool('send_email', { to: 'a@b.c' });
  rt.live = 'yes go ahead';                                                        // transcript still arriving
  const out = JSON.parse(await s._nativeCallTool('send_email', { to: 'a@b.c' }));
  assert.equal(out.status, 'done');
  assert.equal(log.gated.length, 1);
});

test('gemini confirm gate: changed details are a NEW proposal that needs its own confirmation', async () => {
  const { s, rt, log } = await geminiStarted();
  await s._nativeCallTool('create_calendar_event', { summary: 'Demo', start: 'Fri 10am' });
  rt.emit('userTranscript', 'actually make it 11');
  const out = JSON.parse(await s._nativeCallTool('create_calendar_event', { summary: 'Demo', start: 'Fri 11am' }));
  assert.equal(out.status, 'needs_confirmation');
  assert.equal(log.gated.length, 0);
  assert.equal(s.pendingGatedCall.args.start, 'Fri 11am');
});

test('gemini: ordinary tools skip the gate, and a tool round sends results then lets the model continue', async () => {
  const { s, rt, log } = await geminiStarted();
  s._nativeResponseDone({ status: 'completed', toolCalls: [{ callId: 'c1', name: 'check_availability', args: { day: 'Fri' } }] });
  await s.nativeTurnQueue;
  assert.deepEqual(log.toolsRun, [['check_availability', { day: 'Fri' }]]);
  assert.equal(rt.of('toolResult')[0][1], 'c1');
  assert.equal(rt.of('respond').length, 1);              // flushes the queued result on the real client
});

test('gemini: if the session cannot be opened (for example Google rejects the setup) the call uses the standard engine', async () => {
  FakeRealtime.instances.length = 0;
  FakeRealtime.failConnect = true;
  const { s, log } = makeSession({ modelProvider: 'gemini', agentConfig: { ...geminiAgent } });
  await s.startNativeVoice();
  FakeRealtime.failConnect = false;
  assert.equal(log.standardVoice, 1);
  assert.equal(s.nativeRt, null);
});

test('OpenAI behaviour is untouched -- its gated tools still go through the original gate', async () => {
  const { s, log } = await started();
  const out = JSON.parse(await s._nativeCallTool('send_email', { to: 'x@y.z' }));
  assert.equal(out.status, 'needs_confirmation');        // callMcpTool stub's answer, proving the original path ran
  assert.deepEqual(log.toolsRun.pop(), ['send_email', { to: 'x@y.z' }]);
});
