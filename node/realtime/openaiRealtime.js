// OpenAI Realtime (GA) client for the voice bridge: one WebSocket per phone call.
//
// This is protocol only -- it knows nothing about FreeSWITCH, ESL, tools or prompts. The call
// session (claude_server.js) owns those and drives this class through a few methods and events.
// The key is passed in per call (it comes from the agent's saved key via /teamsai/resolveagent);
// nothing is read from the environment and the key is never logged.
//
// Audio is G.711 mu-law at 8 kHz in BOTH directions (OpenAI's "audio/pcmu"), which is what a phone
// call already is, so there is no resampling. Callers hand in / get back PCM16 8 kHz and this class
// converts (see g711.js).
//
// Turn handling: server VAD detects when the caller starts/stops talking, but it is told NOT to
// answer by itself (create_response: false). The bridge decides what happens with each finished
// caller turn -- confirm-before-acting for calendar/email, keyword transfers -- and then calls
// respond(). That costs the time it takes OpenAI to transcribe the turn, and keeps those safety
// rules in code rather than in the model's hands.
//
// Event names follow the GA API (response.output_audio.*, response.output_audio_transcript.*).
// The older beta names (response.audio.*) are accepted too, so a model still on the beta shape
// keeps working.
import { EventEmitter } from 'node:events';
import { pcm16ToUlaw, ulawToPcm16 } from './g711.js';
import { Upsampler8to24, Upsampler16to24, Downsampler16to8, Downsampler24to8 } from './resample.js';

export const DEFAULT_REALTIME_MODEL = 'gpt-realtime';

// Azure OpenAI resources live under these domains. The endpoint comes from the agent's saved
// settings (user-supplied), and the bridge opens a connection to it carrying the agent's key, so
// anything else is refused rather than dialled.
const AZURE_HOST_SUFFIXES = ['.openai.azure.com', '.cognitiveservices.azure.com', '.services.ai.azure.com'];

/**
 * Connection options for an Azure OpenAI realtime deployment (GA protocol: the same events as
 * OpenAI's own endpoint). Differences handled here: the address (/openai/v1/realtime with the
 * DEPLOYMENT name as the model), the `api-key` header, and the audio format -- Azure documents 24 kHz
 * PCM, so audio is converted from/to the phone's 8 kHz instead of using mu-law.
 */
export function azureRealtimeOptions({ apiKey, endpoint, deployment }) {
  if (!apiKey) throw new Error('Azure OpenAI Realtime needs an API key.');
  if (!deployment) throw new Error('Azure OpenAI Realtime needs a realtime deployment name.');
  let u;
  try {
    u = new URL(String(endpoint || '').trim());
  } catch (_) {
    throw new Error('Azure OpenAI endpoint is not a valid URL.');
  }
  const host = u.hostname.toLowerCase();
  if (u.protocol !== 'https:' || !AZURE_HOST_SUFFIXES.some((s) => host.endsWith(s))) {
    throw new Error('Azure OpenAI endpoint must be an https address on an Azure OpenAI domain.');
  }
  return {
    apiKey,
    model: deployment,
    url: 'wss://' + host + '/openai/v1/realtime?model=' + encodeURIComponent(deployment),
    headers: { 'api-key': apiKey },
    audioFormat: 'pcm24',
    transcriptionModel: 'whisper-1',
    includeModel: false, // the deployment is already in the address
  };
}
export const OPENAI_REALTIME_VOICES = new Set([
  'alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar',
]);

/** Maps an agent's saved voice name to an OpenAI realtime voice, defaulting to a neutral one. */
export function pickRealtimeVoice(name) {
  const v = String(name || '').trim().toLowerCase();
  return OPENAI_REALTIME_VOICES.has(v) ? v : 'alloy';
}

/** MCP tool ({name, description, input_schema}) -> Realtime function tool. */
export function toRealtimeTool(tool) {
  return {
    type: 'function',
    name: tool.name,
    description: tool.description || '',
    parameters: tool.input_schema || { type: 'object', properties: {} },
  };
}

const ISO_LANGUAGES = {
  english: 'en', spanish: 'es', french: 'fr', german: 'de', italian: 'it', portuguese: 'pt',
  dutch: 'nl', russian: 'ru', arabic: 'ar', hindi: 'hi', urdu: 'ur', tamil: 'ta', telugu: 'te',
  bengali: 'bn', malay: 'ms', indonesian: 'id', thai: 'th', vietnamese: 'vi', japanese: 'ja',
  korean: 'ko', chinese: 'zh', mandarin: 'zh', turkish: 'tr', polish: 'pl', ukrainian: 'uk',
  filipino: 'tl', tagalog: 'tl', persian: 'fa', farsi: 'fa', hebrew: 'he', swedish: 'sv',
};

/** "English" / "en" / "en-US" -> "en"; anything unrecognised -> '' (no hint, auto-detect). */
export function toIsoLanguage(value) {
  const v = String(value || '').trim().toLowerCase();
  if (!v) return '';
  // The agent editor saves "English (en)"; several selected languages ("English (en), Hindi (hi)")
  // cannot be expressed as one transcription language, so no hint is sent for those.
  const codes = [...v.matchAll(/\(([a-z]{2,3})(?:[-_][a-z0-9]+)?\)/g)].map((m) => m[1]);
  if (codes.length) return new Set(codes).size === 1 ? codes[0] : '';
  if (/[,;/&]|\band\b/.test(v)) return '';
  if (ISO_LANGUAGES[v]) return ISO_LANGUAGES[v];
  const m = v.match(/^([a-z]{2})(?:[-_][a-z0-9]+)?$/);
  return m ? m[1] : '';
}

export class OpenAiRealtimeSession extends EventEmitter {
  /**
   * @param {object}   opts
   * @param {string}   opts.apiKey          the agent's own OpenAI key
   * @param {string}   [opts.model]
   * @param {string}   [opts.url]
   * @param {function} [opts.createSocket]  (url, options) => socket; injected by tests
   */
  constructor({
    apiKey, model = DEFAULT_REALTIME_MODEL, url, createSocket,
    headers, audioFormat = 'pcmu', transcriptionModel = 'gpt-4o-transcribe', includeModel = true,
    wideInput = false, wideOutput = false,
  } = {}) {
    super();
    if (!apiKey) throw new Error('OpenAI Realtime needs an API key.');
    this.apiKey = apiKey;
    this.model = model;
    this.url = url || ('wss://api.openai.com/v1/realtime?model=' + encodeURIComponent(model));
    this.createSocket = createSocket || null;
    this.headers = headers || { Authorization: 'Bearer ' + apiKey };
    // 'pcmu' = G.711 mu-law 8 kHz (OpenAI's telephony format); 'pcm24' = 24 kHz PCM16 (Azure).
    this.audioFormat = audioFormat === 'pcm24' ? 'pcm24' : 'pcmu';
    this.transcriptionModel = transcriptionModel;
    this.includeModel = includeModel;
    // Wideband audio (live relay only): the caller arrives as 16 kHz PCM and is sent on as 24 kHz PCM, and the agent's voice
    // comes back as 24 kHz PCM and is passed on as it is -- no telephone mu-law squeeze in either direction. Without them
    // everything is 8 kHz as before. Independent of audioFormat, which is the Azure setting.
    this.wideInput = !!wideInput;
    this.wideOutput = !!wideOutput;
    this.upsampler = new Upsampler8to24();
    this.upsampler16 = new Upsampler16to24();
    this.downsampler16 = new Downsampler16to8();
    this.downsampler = new Downsampler24to8();
    this.socket = null;
    this.closed = false;
    this.responseActive = false;
    this.queuedResponses = [];   // respond()/say() requests waiting for the running response to finish
    this.noteCounter = 0;        // ids for addNote()
    this._readyResolve = null;
    this._readyReject = null;
    this.ready = new Promise((resolve, reject) => {
      this._readyResolve = resolve;
      this._readyReject = reject;
    });
    // A rejected `ready` nobody awaited must not become an unhandled rejection.
    this.ready.catch(() => {});
  }

  /**
   * Opens the socket. Resolves once it is OPEN; the session is then configured with configure() and
   * `await session.waitReady()` tells you OpenAI accepted that configuration.
   */
  async connect({ timeoutMs = 8000 } = {}) {
    let create = this.createSocket;
    if (!create) {
      // Loaded lazily so this file can be imported (and tested) without the `ws` package.
      const { default: WebSocket } = await import('ws');
      create = (url, options) => new WebSocket(url, options);
    }
    this.socket = create(this.url, { headers: this.headers });

    let opened;
    let failed;
    const open = new Promise((resolve, reject) => {
      opened = resolve;
      failed = reject;
    });
    open.catch(() => {});

    this.socket.on('open', () => opened());
    this.socket.on('message', (raw) => this._onMessage(raw));
    this.socket.on('close', () => {
      this.closed = true;
      const e = new Error('Realtime connection closed.');
      failed(e);
      this._readyReject && this._readyReject(e);
      this.emit('close');
    });
    this.socket.on('error', (err) => {
      // Never include the URL/headers here -- the Authorization header carries the key.
      const e = new Error('OpenAI Realtime socket error: ' + (err && err.message ? err.message : 'unknown'));
      failed(e);
      this._readyReject && this._readyReject(e);
      this.emit('error', e);
    });

    await this._withTimeout(open, timeoutMs, 'Timed out connecting to OpenAI Realtime.');
  }

  /** Resolves when OpenAI has accepted the session.update sent by configure(). */
  waitReady(timeoutMs = 8000) {
    return this._withTimeout(this.ready, timeoutMs, 'Timed out waiting for the Realtime session to be configured.');
  }

  async _withTimeout(promise, ms, message) {
    let timer;
    const timeout = new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(message)), ms);
    });
    try {
      return await Promise.race([promise, timeout]);
    } finally {
      clearTimeout(timer);
    }
  }

  _send(obj) {
    if (this.closed || !this.socket) return false;
    this.socket.send(JSON.stringify(obj));
    return true;
  }

  /** session.update -- instructions, voice, tools and the turn-detection policy. */
  configure({ instructions, voice, tools = [], transcriptionModel = this.transcriptionModel, language }) {
    // Without a hint the transcriber guesses the language per utterance and turns phone noise into
    // Urdu / Persian / Russian text.
    //
    // NO vocabulary `prompt` here, on purpose. One was tried (brand and agent names, so "RingQ" is not
    // heard as "ring queue") and a live call then logged caller turns that were exactly "RingQ" over and
    // over, mostly on quiet audio: a transcriber given a list of words repeats them when it hears
    // noise, and every such phantom turn made the agent answer nobody. The bridge also acts on this
    // text (keyword transfers, confirmations), so a phantom word is worse than a misheard brand name.
    const lang = toIsoLanguage(language);
    const transcription = lang ? { model: transcriptionModel, language: lang } : { model: transcriptionModel };
    const pcm24 = { type: 'audio/pcm', rate: 24000 };
    const inputFormat = this.audioFormat === 'pcm24' || this.wideInput ? pcm24 : { type: 'audio/pcmu' };
    const outputFormat = this.audioFormat === 'pcm24' || this.wideOutput ? pcm24 : { type: 'audio/pcmu' };
    this._send({
      type: 'session.update',
      session: {
        type: 'realtime',
        ...(this.includeModel ? { model: this.model } : {}),
        instructions,
        output_modalities: ['audio'],
        audio: {
          input: {
            format: inputFormat,
            turn_detection: {
              type: 'server_vad',
              // A little less trigger-happy than the API default (0.5): line noise and the caller's own speaker
              // echo were starting "the caller is talking" at the very first word of the greeting and cutting
              // it off. A shorter end-of-speech wait (was 500 ms) makes the answer start sooner. Both can be
              // tuned from the environment without a code change.
              threshold: Number(process.env.OPENAI_VAD_THRESHOLD || 0.6),
              prefix_padding_ms: 300,
              silence_duration_ms: Number(process.env.OPENAI_VAD_SILENCE_MS || 450),
              create_response: false,
              interrupt_response: true,
            },
            // OpenAI cleans the caller's audio before it listens (phone lines are noisy). Not sent to Azure,
            // whose preview of this API does not document the field.
            ...(this.includeModel ? { noise_reduction: { type: 'near_field' } } : {}),
            transcription,
          },
          output: { format: outputFormat, voice: pickRealtimeVoice(voice) },
        },
        tools: tools.map(toRealtimeTool),
        tool_choice: 'auto',
      },
    });
  }

  /**
   * Updates only the instructions and tools of a live session. Used once the tool connection is
   * ready, so the greeting does not have to wait for it. The voice is deliberately not resent: OpenAI
   * does not allow changing it once the agent has spoken.
   */
  reconfigure({ instructions, tools = [] }) {
    return this._send({
      type: 'session.update',
      session: { type: 'realtime', instructions, tools: tools.map(toRealtimeTool), tool_choice: 'auto' },
    });
  }

  /** Caller audio: PCM16 8 kHz mono. */
  sendAudio(pcm8k) {
    const wire = this.audioFormat === 'pcm24' || this.wideInput ? this.upsampler.process(pcm8k) : pcm16ToUlaw(pcm8k);
    return this._send({ type: 'input_audio_buffer.append', audio: wire.toString('base64') });
  }

  /** Caller audio at 16 kHz (wideband): only when the session was opened with wideInput or pcm24. */
  sendAudioWide(pcm16k) {
    // A wideband frame offered to a narrowband session is brought down to 8 kHz so the call still hears it.
    if (!(this.audioFormat === 'pcm24' || this.wideInput)) return this.sendAudio(this.downsampler16.process(pcm16k));
    return this._send({ type: 'input_audio_buffer.append', audio: this.upsampler16.process(pcm16k).toString('base64') });
  }

  /**
   * Ask the model to answer now. `instructions` overrides for this one response only.
   *
   * The API allows ONE response at a time and rejects a second request ("Conversation already has an
   * active response in progress"), which silently lost the caller's turn -- a live call logged it when
   * the caller said "Hello." while the greeting was still being generated. So a request made while one
   * is running waits its turn and is sent when that response finishes. Two plain "just answer" requests
   * in a row are one request: the model already hears everything said so far.
   */
  respond(opts = {}) {
    if (this.responseActive) {
      const plain = !opts.instructions && !opts.toolChoice;
      const plainAlreadyQueued = this.queuedResponses.some((q) => !q.instructions && !q.toolChoice);
      if (!(plain && plainAlreadyQueued)) this.queuedResponses.push(opts);
      return true;
    }
    return this._createResponse(opts);
  }

  _createResponse({ instructions, toolChoice } = {}) {
    const response = {};
    if (instructions) response.instructions = instructions;
    if (toolChoice) response.tool_choice = toolChoice;
    // Marked busy at once, not when the server confirms: a second request in the same moment must queue too.
    this.responseActive = true;
    const sent = this._send(Object.keys(response).length ? { type: 'response.create', response } : { type: 'response.create' });
    if (!sent) this.responseActive = false;
    return sent;
  }

  /** The running response is over: send the next one that was waiting, unless a handler already started one. */
  _sendNextQueued() {
    if (this.responseActive || this.queuedResponses.length === 0) return;
    this._createResponse(this.queuedResponses.shift());
  }

  /**
   * Put a note in front of the model for its next answer, as a system message in the conversation --
   * used for passages found in the agent's documents. It does not trigger a response by itself; call
   * respond() after. A refusal from the API surfaces as an 'error' event and the answer simply goes
   * ahead without the note.
   */
  addNote(text) {
    // A client-chosen id (max 32 chars) so the note can be taken out again -- see removeNote(). Returns the
    // id, or '' when the connection is not open.
    const id = 'kb_' + (++this.noteCounter);
    const sent = this._send({
      type: 'conversation.item.create',
      item: { id, type: 'message', role: 'system', content: [{ type: 'input_text', text: String(text) }] },
    });
    return sent ? id : '';
  }

  /** Take a note added with addNote() back out of the conversation. */
  removeNote(id) {
    return this._send({ type: 'conversation.item.delete', item_id: String(id) });
  }

  /**
   * Have the agent say a fixed line, with tools off for that turn. This is for lines the BRIDGE has decided on
   * (a confirmation question, a transfer announcement), so the instruction says outright that it is not part
   * of the conversation: otherwise the model weighs it against everything else it has been told and can answer
   * the caller instead of reading the line.
   */
  say(text) {
    return this.respond({
      instructions: 'This is a scripted system announcement, not a question and not part of your normal answers. ' +
        'Ignore the conversation so far and any earlier notes. Say exactly the following to the caller, in the same ' +
        'language, adding nothing, explaining nothing, correcting nothing and calling no tools: ' + JSON.stringify(String(text)),
      toolChoice: 'none',
    });
  }

  /**
   * The opening line of the call. Unlike say(), which reads a fixed announcement word for word (right for a
   * confirmation or a transfer notice, but flat for a greeting), this asks for the saved wording delivered the way
   * a friendly person answering the phone would say it: warm, unhurried, a smile in the voice.
   */
  greet(text) {
    return this.respond({
      instructions: 'The call has just been answered. Greet the caller now, out loud, as a warm and friendly person ' +
        'answering the phone would: natural pace, relaxed and welcoming, a smile in your voice. Use the wording below ' +
        'as your opening, in the same language, keeping its meaning; do not add anything else, do not ask anything the ' +
        'wording does not ask, and call no tools. Opening: ' + JSON.stringify(String(text)),
      toolChoice: 'none',
    });
  }

  /** Stop the answer that is currently being generated (the caller started talking). */
  cancel() {
    if (!this.responseActive) return false;
    return this._send({ type: 'response.cancel' });
  }

  /** Give a tool's result back to the model. Follow with respond() once all results are in. */
  sendToolResult(callId, output) {
    return this._send({
      type: 'conversation.item.create',
      item: { type: 'function_call_output', call_id: callId, output: typeof output === 'string' ? output : JSON.stringify(output) },
    });
  }

  close() {
    this.closed = true;
    try {
      this.socket && this.socket.close();
    } catch (_) {
      // already closing
    }
  }

  _onMessage(raw) {
    let ev;
    try {
      ev = JSON.parse(typeof raw === 'string' ? raw : raw.toString('utf8'));
    } catch (_) {
      return; // not JSON -- ignore rather than crash the call
    }
    switch (ev.type) {
      case 'session.updated':
        this._readyResolve && this._readyResolve();
        this._readyResolve = null;
        this.emit('ready');
        break;
      case 'response.created':
        this.responseActive = true;
        this.emit('responseStarted', ev.response && ev.response.id);
        break;
      case 'response.output_audio.delta':
      case 'response.audio.delta':
        if (ev.delta) {
          const raw = Buffer.from(ev.delta, 'base64');
          if (this.wideOutput) this.emit('audio', raw, 24000);          // 24 kHz PCM, passed on untouched
          else this.emit('audio', this.audioFormat === 'pcm24' ? this.downsampler.process(raw) : ulawToPcm16(raw));
        }
        break;
      case 'response.output_audio.done':
      case 'response.audio.done':
        this.emit('audioDone');
        break;
      case 'response.output_audio_transcript.delta':
      case 'response.audio_transcript.delta':
        if (ev.delta) this.emit('assistantTextDelta', ev.delta);
        break;
      case 'response.output_audio_transcript.done':
      case 'response.audio_transcript.done':
        this.emit('assistantTextDone', ev.transcript || '');
        break;
      case 'input_audio_buffer.speech_started':
        this.emit('speechStarted');
        break;
      case 'input_audio_buffer.speech_stopped':
        this.emit('speechStopped');
        break;
      case 'conversation.item.input_audio_transcription.completed':
        this.emit('userTranscript', String(ev.transcript || '').trim());
        break;
      case 'response.done': {
        this.responseActive = false;
        const response = ev.response || {};
        const calls = [];
        for (const item of response.output || []) {
          if (item && item.type === 'function_call') {
            let args = {};
            try {
              args = item.arguments ? JSON.parse(item.arguments) : {};
            } catch (_) {
              args = {};
            }
            calls.push({ callId: item.call_id, name: item.name, args });
          }
        }
        this.emit('responseDone', { status: response.status || 'completed', toolCalls: calls });
        this._sendNextQueued();
        break;
      }
      case 'error': {
        const e = ev.error || {};
        // cancelling when nothing is running is harmless and expected on a barge-in race
        if (e.code === 'response_cancel_not_active') break;
        this.emit('error', new Error('OpenAI Realtime error: ' + (e.message || e.code || 'unknown')));
        // A refused request means no response started, so do not stay "busy" forever -- except when the
        // refusal was "a response is already running", which is exactly what busy means.
        if (!/active response/i.test(String(e.message || ''))) {
          this.responseActive = false;
          this._sendNextQueued();
        }
        break;
      }
      default:
        break;
    }
  }
}
