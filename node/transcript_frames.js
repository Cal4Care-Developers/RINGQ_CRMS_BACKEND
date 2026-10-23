// What FreeSWITCH sends to the live-transcript socket (node/transcript_live.js, port 5000).
//
// Two modules can feed it, and both are handled so either can be used:
//   * mod_audio_stream         -- BINARY frames: raw PCM16, stereo, little-endian (left = caller leg, right = other leg).
//   * mod_openai_audio_stream  -- TEXT frames, JSON: {"type":"input_audio_buffer.append","audio":"<base64 PCM16>"} with the
//                                 same interleaved stereo PCM. On connect it also sends OpenAI-style control messages
//                                 (session.update, response.create); they mean nothing here and are ignored.
//
// Pure function so it can be tested without a socket: decodeAudioFrame(data, isBinary) ->
//   { kind: 'audio', pcm: Buffer }   PCM16 stereo, whole sample pairs only
//   { kind: 'ignore' }               a control message, junk, or an empty frame

function decodeAudioFrame(data, isBinary) {
  let text = null;
  if (typeof data === 'string') {
    text = data;
  } else if (isBinary === false) {
    // ws 8 hands text frames over as a Buffer too; isBinary tells them apart from raw audio.
    text = Buffer.from(data).toString('utf8');
  }

  if (text === null) {
    const pcm = Buffer.isBuffer(data) ? data : Buffer.from(data);
    return pcm.length >= 4 ? { kind: 'audio', pcm: wholeFrames(pcm) } : { kind: 'ignore' };
  }

  let msg;
  try { msg = JSON.parse(text); } catch (_) { return { kind: 'ignore' }; }
  if (!msg || msg.type !== 'input_audio_buffer.append' || typeof msg.audio !== 'string' || !msg.audio) {
    return { kind: 'ignore' };
  }
  const pcm = Buffer.from(msg.audio, 'base64');
  return pcm.length >= 4 ? { kind: 'audio', pcm: wholeFrames(pcm) } : { kind: 'ignore' };
}

// Stereo 16-bit is 4 bytes per sample pair: never split a pair, or every later sample swaps channels.
function wholeFrames(buf) {
  const even = buf.length - (buf.length % 4);
  return even === buf.length ? buf : buf.subarray(0, even);
}

module.exports = { decodeAudioFrame };
