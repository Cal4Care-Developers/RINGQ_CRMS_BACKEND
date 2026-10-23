// G.711 mu-law <-> 16-bit linear PCM, for talking to OpenAI Realtime in its telephony format
// (audio/pcmu, 8 kHz). FreeSWITCH hands this bridge 8 kHz mono PCM16 little-endian, and plays
// back PCM16 WAV files, so the two directions are:
//
//   caller audio  : PCM16 8 kHz  --pcm16ToUlaw-->  OpenAI
//   agent audio   : OpenAI       --ulawToPcm16-->  PCM16 8 kHz  -> WAV -> uuid_broadcast
//
// Standard ITU-T G.711 mu-law (bias 0x84, clip 32635). No dependencies.

const BIAS = 0x84;
const CLIP = 32635;

export function linearToUlaw(sample) {
  let sign = (sample >> 8) & 0x80;
  if (sign) sample = -sample;
  if (sample > CLIP) sample = CLIP;
  sample += BIAS;
  let exponent = 7;
  for (let mask = 0x4000; (sample & mask) === 0 && exponent > 0; mask >>= 1) exponent--;
  const mantissa = (sample >> (exponent + 3)) & 0x0f;
  return ~(sign | (exponent << 4) | mantissa) & 0xff;
}

export function ulawToLinear(byte) {
  const u = ~byte & 0xff;
  const sign = u & 0x80;
  const exponent = (u >> 4) & 0x07;
  const mantissa = u & 0x0f;
  const sample = (((mantissa << 3) + BIAS) << exponent) - BIAS;
  return sign ? -sample : sample;
}

/** Buffer of PCM16LE samples -> Buffer of mu-law bytes (half the length). */
export function pcm16ToUlaw(pcm) {
  const samples = pcm.length >> 1;
  const out = Buffer.allocUnsafe(samples);
  for (let i = 0; i < samples; i++) out[i] = linearToUlaw(pcm.readInt16LE(i * 2));
  return out;
}

/** Buffer of mu-law bytes -> Buffer of PCM16LE samples (twice the length). */
export function ulawToPcm16(ulaw) {
  const out = Buffer.allocUnsafe(ulaw.length * 2);
  for (let i = 0; i < ulaw.length; i++) out.writeInt16LE(ulawToLinear(ulaw[i]), i * 2);
  return out;
}
