// Streaming 8 kHz <-> 24 kHz PCM16 conversion (factor 3), for realtime providers that do not take
// 8 kHz telephone audio directly (Azure OpenAI documents 24 kHz PCM; Gemini wants 16/24 kHz).
//
// Both converters keep state between calls, because audio arrives in arbitrary-sized pieces and a
// piece boundary must not click or shift the sample grid. Feeding the same samples in one chunk or
// in many gives byte-identical output (tested).

/** 8 kHz -> 24 kHz: linear interpolation, three output samples per input sample. */
export class Upsampler8to24 {
  constructor() {
    this.prev = 0;
  }

  /** @param {Buffer} pcm PCM16LE @ 8 kHz  @returns {Buffer} PCM16LE @ 24 kHz */
  process(pcm) {
    const n = pcm.length >> 1;
    const out = Buffer.allocUnsafe(n * 6);
    let prev = this.prev;
    for (let i = 0; i < n; i++) {
      const cur = pcm.readInt16LE(i * 2);
      for (let k = 1; k <= 3; k++) {
        out.writeInt16LE(Math.round(prev + ((cur - prev) * k) / 3), (i * 3 + k - 1) * 2);
      }
      prev = cur;
    }
    this.prev = prev;
    return out;
  }
}

// Low-pass FIR (windowed sinc) so content above the 4 kHz output Nyquist is removed before every
// third sample is kept; without it the 8 kHz result is full of aliasing. Cut-off 3.8 kHz at 24 kHz, 41 taps: flat through the phone band, about -20 dB by 4.5 kHz.
const TAPS = 41;
const HALF = (TAPS - 1) / 2;
const COEFFS = (() => {
  const fc = 3800 / 24000;
  const h = [];
  let sum = 0;
  for (let i = 0; i < TAPS; i++) {
    const m = i - HALF;
    const sinc = m === 0 ? 2 * fc : Math.sin(2 * Math.PI * fc * m) / (Math.PI * m);
    const hamming = 0.54 - 0.46 * Math.cos((2 * Math.PI * i) / (TAPS - 1));
    h.push(sinc * hamming);
    sum += sinc * hamming;
  }
  return h.map((v) => v / sum); // unity gain at DC
})();

/** 24 kHz -> 8 kHz: low-pass, then keep every third sample. */
export class Downsampler24to8 {
  constructor() {
    this.buf = new Int16Array(HALF); // leading zeros so the first output is centred on sample 0
  }

  /** @param {Buffer} pcm PCM16LE @ 24 kHz  @returns {Buffer} PCM16LE @ 8 kHz */
  process(pcm) {
    const n = pcm.length >> 1;
    const merged = new Int16Array(this.buf.length + n);
    merged.set(this.buf, 0);
    for (let i = 0; i < n; i++) merged[this.buf.length + i] = pcm.readInt16LE(i * 2);

    const outCount = merged.length >= TAPS ? Math.floor((merged.length - TAPS) / 3) + 1 : 0;
    const out = Buffer.allocUnsafe(outCount * 2);
    for (let j = 0; j < outCount; j++) {
      const base = j * 3;
      let acc = 0;
      for (let k = 0; k < TAPS; k++) acc += merged[base + k] * COEFFS[k];
      out.writeInt16LE(Math.max(-32768, Math.min(32767, Math.round(acc))), j * 2);
    }
    this.buf = merged.slice(outCount * 3);
    return out;
  }
}

/** 8 kHz -> 16 kHz (Gemini Live input): linear interpolation, two output samples per input sample. */
export class Upsampler8to16 {
  constructor() {
    this.prev = 0;
  }

  /** @param {Buffer} pcm PCM16LE @ 8 kHz  @returns {Buffer} PCM16LE @ 16 kHz */
  process(pcm) {
    const n = pcm.length >> 1;
    const out = Buffer.allocUnsafe(n * 4);
    let prev = this.prev;
    for (let i = 0; i < n; i++) {
      const cur = pcm.readInt16LE(i * 2);
      out.writeInt16LE(Math.round((prev + cur) / 2), i * 4);
      out.writeInt16LE(cur, i * 4 + 2);
      prev = cur;
    }
    this.prev = prev;
    return out;
  }
}

/**
 * 16 kHz -> 24 kHz (wideband caller audio to OpenAI): linear interpolation at 3:2. Output sample j sits at input
 * position 2j/3, so every third output lands exactly on an input sample. Keeps its place between calls, so the
 * result is identical however the audio is cut into pieces.
 */
export class Upsampler16to24 {
  constructor() {
    this.outIndex = 0;        // global output sample number of the next sample to produce
    this.base = 0;            // global input sample number of buffer[0]
    this.buffer = new Int16Array(0);
  }

  /** @param {Buffer} pcm PCM16LE @ 16 kHz  @returns {Buffer} PCM16LE @ 24 kHz */
  process(pcm) {
    const n = pcm.length >> 1;
    const merged = new Int16Array(this.buffer.length + n);
    merged.set(this.buffer, 0);
    for (let i = 0; i < n; i++) merged[this.buffer.length + i] = pcm.readInt16LE(i * 2);

    const lastInput = this.base + merged.length - 1;
    const out = [];
    for (;;) {
      const num = 2 * this.outIndex;
      const i0 = Math.floor(num / 3);
      const rem = num - i0 * 3;
      if (rem === 0 ? i0 > lastInput : i0 + 1 > lastInput) break;
      const a = merged[i0 - this.base];
      out.push(rem === 0 ? a : Math.round(a + ((merged[i0 + 1 - this.base] - a) * rem) / 3));
      this.outIndex++;
    }
    // Keep from the input sample the next output needs.
    const keepFrom = Math.floor((2 * this.outIndex) / 3);
    const drop = Math.max(0, keepFrom - this.base);
    this.buffer = merged.slice(Math.min(drop, merged.length));
    this.base += Math.min(drop, merged.length);

    const buf = Buffer.allocUnsafe(out.length * 2);
    for (let j = 0; j < out.length; j++) buf.writeInt16LE(out[j], j * 2);
    return buf;
  }
}

// Half-band low-pass (cut-off at a quarter of the sample rate), 7 taps; the weights add up to exactly 1.
const HALF_BAND = [-1 / 32, 0, 9 / 32, 16 / 32, 9 / 32, 0, -1 / 32];

/** 16 kHz -> 8 kHz (the narrow-band copy of wideband caller audio, for speech-to-text that wants 8 kHz). */
export class Downsampler16to8 {
  constructor() {
    this.buf = new Int16Array(3);   // leading zeros so the first output is centred on sample 0
  }

  /** @param {Buffer} pcm PCM16LE @ 16 kHz  @returns {Buffer} PCM16LE @ 8 kHz */
  process(pcm) {
    const n = pcm.length >> 1;
    const merged = new Int16Array(this.buf.length + n);
    merged.set(this.buf, 0);
    for (let i = 0; i < n; i++) merged[this.buf.length + i] = pcm.readInt16LE(i * 2);

    const taps = HALF_BAND.length;
    const outCount = merged.length >= taps ? Math.floor((merged.length - taps) / 2) + 1 : 0;
    const out = Buffer.allocUnsafe(outCount * 2);
    for (let j = 0; j < outCount; j++) {
      let acc = 0;
      for (let k = 0; k < taps; k++) acc += merged[j * 2 + k] * HALF_BAND[k];
      out.writeInt16LE(Math.max(-32768, Math.min(32767, Math.round(acc))), j * 2);
    }
    this.buf = merged.slice(outCount * 2);
    return out;
  }
}
