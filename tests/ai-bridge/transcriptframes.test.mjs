// The live-transcript socket must accept audio from either FreeSWITCH module and ignore everything else.
// Run: node --test tests/ai-bridge/transcriptframes.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const { decodeAudioFrame } = require(path.join(here, '../../node/transcript_frames.js'));

const stereo = (n) => { const b = Buffer.alloc(n * 4); for (let i = 0; i < b.length; i++) b[i] = i % 251; return b; };
const append = (pcm) => JSON.stringify({ type: 'input_audio_buffer.append', audio: pcm.toString('base64') });

test('mod_audio_stream: a binary frame is the PCM itself', () => {
  const pcm = stereo(480);
  const r = decodeAudioFrame(pcm, true);
  assert.equal(r.kind, 'audio');
  assert.deepEqual(r.pcm, pcm);
});

test('mod_openai_audio_stream: a JSON text frame is decoded from base64 (ws 8 passes it as a Buffer with isBinary=false)', () => {
  const pcm = stereo(480);
  const r = decodeAudioFrame(Buffer.from(append(pcm)), false);
  assert.equal(r.kind, 'audio');
  assert.deepEqual(r.pcm, pcm);
});

test('a text frame given as a string (older ws) is decoded the same way', () => {
  const pcm = stereo(240);
  assert.deepEqual(decodeAudioFrame(append(pcm)).pcm, pcm);
});

test('left and right samples keep their places after decoding', () => {
  const pcm = Buffer.from([1, 0, 2, 0, 3, 0, 4, 0]);          // L=1 R=2, L=3 R=4
  const r = decodeAudioFrame(Buffer.from(append(pcm)), false);
  assert.equal(r.pcm.readInt16LE(0), 1);
  assert.equal(r.pcm.readInt16LE(2), 2);
  assert.equal(r.pcm.readInt16LE(4), 3);
  assert.equal(r.pcm.readInt16LE(6), 4);
});

test('the control messages the module sends on connect are ignored', () => {
  for (const type of ['session.update', 'response.create', 'input_audio_buffer.commit']) {
    assert.equal(decodeAudioFrame(Buffer.from(JSON.stringify({ type, session: {} })), false).kind, 'ignore', type);
  }
});

test('junk is ignored, never thrown', () => {
  assert.equal(decodeAudioFrame(Buffer.from('not json'), false).kind, 'ignore');
  assert.equal(decodeAudioFrame('{', undefined).kind, 'ignore');
  assert.equal(decodeAudioFrame(Buffer.from(JSON.stringify({ type: 'input_audio_buffer.append' })), false).kind, 'ignore');
  assert.equal(decodeAudioFrame(Buffer.from(JSON.stringify({ type: 'input_audio_buffer.append', audio: '' })), false).kind, 'ignore');
  assert.equal(decodeAudioFrame(Buffer.alloc(0), true).kind, 'ignore');
  assert.equal(decodeAudioFrame(Buffer.alloc(3), true).kind, 'ignore');
});

test('a frame that is not a whole number of stereo samples is trimmed to whole pairs', () => {
  const r = decodeAudioFrame(Buffer.alloc(4 * 10 + 3, 7), true);
  assert.equal(r.pcm.length, 40);
  const j = decodeAudioFrame(Buffer.from(append(Buffer.alloc(4 * 5 + 2, 9))), false);
  assert.equal(j.pcm.length, 20);
});
