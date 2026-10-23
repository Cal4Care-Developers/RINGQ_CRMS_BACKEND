// Gemini Live client for the voice bridge: one WebSocket per phone call.
//
// Same job and same events as openaiRealtime.js, so CallSession can drive either; protocol only.
// Wire format follows Google's API reference for BidiGenerateContent (setup / realtimeInput /
// serverContent / toolCall / toolResponse). The wire format is confined to this file on purpose:
// it was written from the documentation, not from a live session, so if Google rejects the setup
// message the error text is surfaced (without the key) and the call falls back to the standard
// voice engine -- the fix is then local to configure() below.
//
// Differences from OpenAI Realtime that matter to the caller:
//   * Gemini answers by itself when the caller stops talking; there is no "wait for my go". So
//     `autoRespond` is true and the bridge enforces confirm-before-acting on the tool call instead
//     (see CallSession._nativeCallTool).
//   * The setup message is one-shot: tools and the final prompt must be known BEFORE connecting
//     (`needsToolsAtStart`), and reconfigure() cannot change them afterwards.
//   * Audio is 16 kHz PCM in and 24 kHz PCM out, converted from/to the phone's 8 kHz (resample.js).
//   * Barge-in arrives as `serverContent.interrupted`, reported here as 'speechStarted'.
//   * Tool results are batched: sendToolResult() queues, respond() sends them as one toolResponse.
import { EventEmitter } from 'node:events';
import { Upsampler8to16, Downsampler24to8 } from './resample.js';

export const DEFAULT_GEMINI_LIVE_MODEL = 'gemini-3.8-live';
export const GEMINI_VOICES = [
  'Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Leda', 'Orus', 'Aoede', 'Callirrhoe', 'Autonoe',
  'Enceladus', 'Iapetus', 'Umbriel', 'Algieba', 'Despina', 'Erinome', 'Algenib', 'Rasalgethi',
  'Laomedeia', 'Achernar', 'Alnilam', 'Schedar', 'Gacrux', 'Pulcherrima', 'Achird', 'Zubenelgenubi',
  'Vindemiatrix', 'Sadachbia', 'Sadaltager', 'Sulafat',
];
const VOICE_BY_LOWER = new Map(GEMINI_VOICES.map((v) => [v.toLowerCase(), v]));

/** An agent's saved voice name -> a Gemini prebuilt voice (OpenAI/Deepgram names fall back to Kore). */
export function pickGeminiVoice(name) {
  return VOICE_BY_LOWER.get(String(name || '').trim().toLowerCase()) || 'Kore';
}

// Gemini's function-declaration schema wants OpenAPI-style uppercase `type` enums and has no
// additionalProperties/$schema. (Same reshaping providers/gemini.js does for the text API; kept
// here as a copy so this file has no dependency on the Google SDK.)
const TYPE_MAP = { object: 'OBJECT', string: 'STRING', number: 'NUMBER', integer: 'INTEGER', boolean: 'BOOLEAN', array: 'ARRAY' };
export function toGeminiSchema(schema) {
  if (!schema || typeof schema !== 'object') return schema;
  const { additionalProperties, $schema, ...rest } = schema;
  const out = { ...rest };
  if (typeof out.type === 'string') out.type = TYPE_MAP[out.type.toLowerCase()] || out.type.toUpperCase();
  if (out.properties && typeof out.properties === 'object') {
    out.properties = Object.fromEntries(Object.entries(out.properties).map(([k, v]) => [k, toGeminiSchema(v)]));
  }
  if (out.items) out.items = toGeminiSchema(out.items);
  return out;
}

export function toGeminiFunctionDeclaration(tool) {
  return {
    name: tool.name,
    description: tool.description || '',
    parameters: toGeminiSchema(tool.input_schema || { type: 'object', properties: {} }),
  };
}

const ENDPOINT = 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContent';

export class GeminiLiveSession extends EventEmitter {
  constructor({ apiKey, model = DEFAULT_GEMINI_LIVE_MODEL, createSocket, wideOutput = false } = {}) {
    super();
    // Live relay only: the agent's voice (24 kHz PCM from Gemini) is passed on untouched instead of being squeezed to 8 kHz.
    this.wideOutput = !!wideOutput;
    if (!apiKey) throw new Error('Gemini Live needs an API key.');
    this.apiKey = apiKey;
    this.model = model;
    this.createSocket = createSocket || null;
    this.socket = null;
    this.closed = false;
    this.responseActive = false;
    this.autoRespond = true;          // Gemini answers on its own; see header
    this.needsToolsAtStart = true;    // setup is one-shot; see header
    this.upsampler = new Upsampler8to16();
    this.downsampler = new Downsampler24to8();
    this._userText = '';
    this._assistantText = '';
    this._callNames = new Map();
    this._pendingResults = [];
    this._readyResolve = null;
    this._readyReject = null;
    this.ready = new Promise((resolve, reject) => {
      this._readyResolve = resolve;
      this._readyReject = reject;
    });
    this.ready.catch(() => {});
  }

  /** Error text with the key removed -- Google's close reasons / socket errors may echo request details. */
  _clean(text) {
    return String(text || '').split(this.apiKey).join('[key]');
  }

  async connect({ timeoutMs = 8000 } = {}) {
    let create = this.createSocket;
    if (!create) {
      const { default: WebSocket } = await import('ws');
      create = (url, options) => new WebSocket(url, options);
    }
    // The key travels as the documented `key` query parameter. This URL is never logged.
    this.socket = create(ENDPOINT + '?key=' + encodeURIComponent(this.apiKey), {});

    let opened;
    let failed;
    const open = new Promise((resolve, reject) => {
      opened = resolve;
      failed = reject;
    });
    open.catch(() => {});

    this.socket.on('open', () => opened());
    this.socket.on('message', (raw) => this._onMessage(raw));
    this.socket.on('close', (code, reason) => {
      this.closed = true;
      const detail = this._clean(reason && reason.toString ? reason.toString() : '');
      const e = new Error('Gemini Live connection closed' + (code ? ' (' + code + ')' : '') + (detail ? ': ' + detail : '') + '.');
      failed(e);
      this._readyReject && this._readyReject(e);
      this.emit('close', { code, reason: detail });
    });
    this.socket.on('error', (err) => {
      const e = new Error('Gemini Live socket error: ' + this._clean(err && err.message ? err.message : 'unknown'));
      failed(e);
      this._readyReject && this._readyReject(e);
      this.emit('error', e);
    });

    await this._withTimeout(open, timeoutMs, 'Timed out connecting to Gemini Live.');
  }

  waitReady(timeoutMs = 10000) {
    return this._withTimeout(this.ready, timeoutMs, 'Timed out waiting for Gemini Live to accept the session setup.');
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

  /** The one-shot setup message: voice, prompt, tools, transcription. Must be the first message sent. */
  configure({ instructions, voice, tools = [] }) {
    const setup = {
      model: this.model.startsWith('models/') ? this.model : 'models/' + this.model,
      generationConfig: {
        responseModalities: ['AUDIO'],
        speechConfig: { voiceConfig: { prebuiltVoiceConfig: { voiceName: pickGeminiVoice(voice) } } },
      },
      systemInstruction: { parts: [{ text: instructions }] },
      inputAudioTranscription: {},
      outputAudioTranscription: {},
    };
    if (tools.length) setup.tools = [{ functionDeclarations: tools.map(toGeminiFunctionDeclaration) }];
    this._send({ setup });
  }

  /** Not possible on Gemini Live -- setup is one-shot. Returns false so callers can tell. */
  reconfigure() {
    return false;
  }

  /** Caller audio at 16 kHz (wideband): exactly what Gemini Live takes, no conversion. */
  sendAudioWide(pcm16k) {
    return this._send({ realtimeInput: { audio: { data: pcm16k.toString('base64'), mimeType: 'audio/pcm;rate=16000' } } });
  }

  /** Caller audio: PCM16 8 kHz mono -> 16 kHz. */
  sendAudio(pcm8k) {
    return this._send({
      realtimeInput: { audio: { data: this.upsampler.process(pcm8k).toString('base64'), mimeType: 'audio/pcm;rate=16000' } },
    });
  }

  /**
   * Gemini answers by itself. With no argument this just sends any queued tool results (the model
   * continues once it has them); with `instructions` it asks for a turn explicitly.
   */
  respond({ instructions } = {}) {
    if (this._pendingResults.length) {
      const functionResponses = this._pendingResults;
      this._pendingResults = [];
      return this._send({ toolResponse: { functionResponses } });
    }
    if (instructions) return this._send({ realtimeInput: { text: instructions } });
    return true;
  }

  /** Have the agent say a fixed line. */
  say(text) {
    return this._send({
      realtimeInput: {
        text: 'Say exactly the following to the caller, in the same language, adding nothing and calling no tools: ' + JSON.stringify(String(text)),
      },
    });
  }

  /** Gemini has no cancel message; the caller talking over the agent is reported as `interrupted`. */
  cancel() {
    return false;
  }

  /** Queue a tool's result; respond() sends everything queued as one toolResponse. */
  sendToolResult(callId, output) {
    let value = output;
    if (typeof output === 'string') {
      try {
        value = JSON.parse(output);
      } catch (_) {
        value = output;
      }
    }
    this._pendingResults.push({ id: callId, name: this._callNames.get(callId) || '', response: { result: value } });
    return true;
  }

  close() {
    this.closed = true;
    try {
      this.socket && this.socket.close();
    } catch (_) {
      // already closing
    }
  }

  /** What the caller has said so far in the turn in progress (not yet reported as userTranscript). */
  pendingUserText() {
    return this._userText.trim();
  }

  _flushUserText() {
    const text = this._userText.trim();
    this._userText = '';
    if (text) this.emit('userTranscript', text);
  }

  _startResponse() {
    if (!this.responseActive) {
      this.responseActive = true;
      this._flushUserText();      // the caller's turn is over once the model starts its own
      this.emit('responseStarted');
    }
  }

  _onMessage(raw) {
    let ev;
    try {
      ev = JSON.parse(typeof raw === 'string' ? raw : raw.toString('utf8'));
    } catch (_) {
      return;
    }

    if (ev.setupComplete !== undefined) {
      this._readyResolve && this._readyResolve();
      this._readyResolve = null;
      this.emit('ready');
      return;
    }

    if (ev.toolCall) {
      this._startResponse();
      this.responseActive = false;
      const calls = [];
      for (const fc of ev.toolCall.functionCalls || []) {
        this._callNames.set(fc.id, fc.name);
        calls.push({ callId: fc.id, name: fc.name, args: fc.args && typeof fc.args === 'object' ? fc.args : {} });
      }
      this.emit('responseDone', { status: 'completed', toolCalls: calls });
      return;
    }

    if (ev.toolCallCancellation) {
      this.emit('toolCallCancelled', ev.toolCallCancellation.ids || []);
      return;
    }

    if (ev.goAway) {
      this.emit('goAway', ev.goAway);
      return;
    }

    const sc = ev.serverContent;
    if (!sc) return;

    if (sc.inputTranscription && sc.inputTranscription.text) {
      this._userText += sc.inputTranscription.text;
    }

    if (sc.interrupted) {
      // the caller talked over the agent: forget this answer, the audio already sent is dropped by the bridge
      this.responseActive = false;
      this._assistantText = '';
      this.emit('speechStarted');
      return;
    }

    if (sc.outputTranscription && sc.outputTranscription.text) {
      this._startResponse();
      this._assistantText += sc.outputTranscription.text;
    }

    const parts = (sc.modelTurn && sc.modelTurn.parts) || [];
    for (const part of parts) {
      const data = part.inlineData && part.inlineData.data;
      if (data) {
        this._startResponse();
        if (this.wideOutput) this.emit('audio', Buffer.from(data, 'base64'), 24000);
        else this.emit('audio', this.downsampler.process(Buffer.from(data, 'base64')));
      }
    }

    if (sc.turnComplete) {
      const text = this._assistantText.trim();
      this._assistantText = '';
      this.emit('audioDone');
      if (text) this.emit('assistantTextDone', text);
      this._flushUserText();      // a turn with no model audio at all still ends the caller's turn
      const wasActive = this.responseActive;
      this.responseActive = false;
      this.emit('responseDone', { status: wasActive ? 'completed' : 'incomplete', toolCalls: [] });
    }
  }
}
