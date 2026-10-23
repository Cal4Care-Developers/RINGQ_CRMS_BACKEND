// Run with:  node --test realtime/resample.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { Upsampler8to24, Downsampler24to8 } from './resample.js';

function tone(freq, rate, seconds, amp = 10000) {
  const n = Math.round(rate * seconds);
  const b = Buffer.alloc(n * 2);
  for (let i = 0; i < n; i++) b.writeInt16LE(Math.round(amp * Math.sin((2 * Math.PI * freq * i) / rate)), i * 2);
  return b;
}
// RMS over the middle of a buffer (skips the filter's start-up/tail transient)
function rms(buf) {
  const n = buf.length >> 1;
  const from = Math.floor(n * 0.2);
  const to = Math.floor(n * 0.8);
  let s = 0;
  for (let i = from; i < to; i++) s += buf.readInt16LE(i * 2) ** 2;
  return Math.sqrt(s / (to - from));
}

test('upsampler triples the sample count and ends each step exactly on the input sample', () => {
  const up = new Upsampler8to24();
  const input = Buffer.alloc(8);
  [0, 300, -300, 900].forEach((v, i) => input.writeInt16LE(v, i * 2));
  const out = up.process(input);
  assert.equal(out.length, input.length * 3);
  assert.equal(out.readInt16LE(2 * 2), 0);     // 3rd output == 1st input
  assert.equal(out.readInt16LE(5 * 2), 300);   // 6th output == 2nd input
  assert.equal(out.readInt16LE(8 * 2), -300);
  assert.equal(out.readInt16LE(11 * 2), 900);
  assert.equal(out.readInt16LE(0 * 2), 0);     // interpolates 0 -> 0 -> 0 -> 0 up to the first input
  assert.equal(out.readInt16LE(3 * 2), 100);   // 0 -> 300 in thirds
  assert.equal(out.readInt16LE(4 * 2), 200);
});

test('downsampler gives a third of the samples, with unity gain for steady and speech-band signals', () => {
  const down = new Downsampler24to8();
  const out = down.process(tone(1000, 24000, 1));
  assert.ok(Math.abs(out.length / 2 - 8000) <= 10, 'length ' + out.length / 2);
  const gain = rms(out) / rms(tone(1000, 8000, 1));
  assert.ok(gain > 0.97 && gain < 1.03, '1 kHz gain ' + gain);

  const dc = Buffer.alloc(24000 * 2);
  for (let i = 0; i < 24000; i++) dc.writeInt16LE(1000, i * 2);
  const dcOut = new Downsampler24to8().process(dc);
  assert.equal(dcOut.readInt16LE(4000 * 2), 1000);
});

test('downsampler keeps the top of the phone band and removes what would alias', () => {
  const g3k = rms(new Downsampler24to8().process(tone(3000, 24000, 1))) / rms(tone(3000, 24000, 1));
  assert.ok(g3k > 0.95, '3 kHz gain ' + g3k);
  // 4.6 kHz would fold down to 3.4 kHz at 8 kHz if it were not filtered out first
  const g46 = rms(new Downsampler24to8().process(tone(4600, 24000, 1))) / rms(tone(4600, 24000, 1));
  assert.ok(g46 < 0.15, '4.6 kHz should be mostly removed, gain ' + g46);
  const g9k = rms(new Downsampler24to8().process(tone(9000, 24000, 1))) / rms(tone(9000, 24000, 1));
  assert.ok(g9k < 0.05, '9 kHz should be gone, gain ' + g9k);
});

test('a tone survives 8k -> 24k -> 8k with its level intact', () => {
  const src = tone(800, 8000, 1);
  const back = new Downsampler24to8().process(new Upsampler8to24().process(src));
  const gain = rms(back) / rms(src);
  assert.ok(gain > 0.95 && gain < 1.05, 'round-trip gain ' + gain);
});

test('chunk boundaries do not matter: odd-sized pieces give byte-identical output', () => {
  const src24 = tone(700, 24000, 0.5);
  const whole = new Downsampler24to8().process(src24);
  const d = new Downsampler24to8();
  const parts = [];
  for (let off = 0, step = 1; off < src24.length; step = (step * 7 + 3) % 97 + 1) {
    const end = Math.min(src24.length, off + step * 2);   // keep whole samples
    parts.push(d.process(src24.subarray(off, end)));
    off = end;
  }
  assert.ok(Buffer.concat(parts).equals(whole));

  const src8 = tone(700, 8000, 0.25);
  const upWhole = new Upsampler8to24().process(src8);
  const u = new Upsampler8to24();
  const upParts = [];
  for (let off = 0; off < src8.length; off += 18) upParts.push(u.process(src8.subarray(off, Math.min(src8.length, off + 18))));
  assert.ok(Buffer.concat(upParts).equals(upWhole));
});

test('tiny and empty inputs are fine', () => {
  assert.equal(new Downsampler24to8().process(Buffer.alloc(0)).length, 0);
  assert.equal(new Downsampler24to8().process(Buffer.alloc(4)).length, 0);   // not enough for one output yet
  assert.equal(new Upsampler8to24().process(Buffer.alloc(0)).length, 0);
});

import { Upsampler8to16 } from './resample.js';

test('8k -> 16k doubles the samples, lands on every input sample, and is chunk-invariant', () => {
  const src = Buffer.alloc(8);
  [0, 200, -200, 600].forEach((v, i) => src.writeInt16LE(v, i * 2));
  const out = new Upsampler8to16().process(src);
  assert.equal(out.length, 16);
  assert.deepEqual([0, 1, 2, 3, 4, 5, 6, 7].map((i) => out.readInt16LE(i * 2)), [0, 0, 100, 200, 0, -200, 200, 600]);

  const tone8 = tone(600, 8000, 0.25);
  const whole = new Upsampler8to16().process(tone8);
  const u = new Upsampler8to16();
  const parts = [];
  for (let off = 0; off < tone8.length; off += 22) parts.push(u.process(tone8.subarray(off, Math.min(tone8.length, off + 22))));
  assert.ok(Buffer.concat(parts).equals(whole));
  // a tone keeps its level
  const gain = rms(new Upsampler8to16().process(tone(800, 8000, 1))) / rms(tone(800, 8000, 1));
  assert.ok(gain > 0.95 && gain < 1.05, 'gain ' + gain);
});

// ---- 16 kHz <-> 24 kHz / 8 kHz (wideband caller audio) ----
import { Upsampler16to24, Downsampler16to8 } from './resample.js';

test('16->24 gives 1.5x the samples and lands every third output on an input sample', () => {
  const up = new Upsampler16to24();
  const input = Buffer.alloc(12);
  [0, 300, 600, 900, 1200, 1500].forEach((v, i) => input.writeInt16LE(v, i * 2));
  const out = up.process(input);
  assert.equal(out.length / 2, 8);                   // 6 in -> 8 out (the last needs one more input to interpolate)
  assert.equal(out.readInt16LE(0), 0);               // pos 0
  assert.equal(out.readInt16LE(2), 200);             // pos 2/3 -> 0 + 300 * 2/3
  assert.equal(out.readInt16LE(4), 400);             // pos 4/3 -> 300 + 300 * 1/3
  assert.equal(out.readInt16LE(6), 600);             // pos 2 -> exactly input[2]
});

test('16->24 is the same however the audio is cut up', () => {
  const sig = tone(700, 16000, 0.5);
  const whole = new Upsampler16to24().process(sig);
  const parts = new Upsampler16to24();
  const pieces = [];
  for (let off = 0; off < sig.length; off += 322) pieces.push(parts.process(sig.subarray(off, Math.min(off + 322, sig.length))));
  const joined = Buffer.concat(pieces);
  assert.ok(Math.abs(joined.length - whole.length) <= 4);
  const n = Math.min(joined.length, whole.length);
  assert.ok(joined.subarray(0, n - 4).equals(whole.subarray(0, n - 4)));
});

test('16->24 keeps a tone at the right level and length', () => {
  const out = new Upsampler16to24().process(tone(1000, 16000, 1));
  assert.ok(Math.abs(out.length / 2 - 24000) <= 3);
  const r = rms(out);
  assert.ok(r > 6800 && r < 7300, 'rms ' + r);       // 10000/sqrt(2) = 7071
});

test('16->8 halves the sample count, passes speech and removes what 8 kHz cannot hold', () => {
  const pass = new Downsampler16to8().process(tone(1000, 16000, 1));
  assert.ok(Math.abs(pass.length / 2 - 8000) <= 4);
  const rp = rms(pass);
  assert.ok(rp > 6800 && rp < 7400, 'speech-band rms ' + rp);
  const stop = new Downsampler16to8().process(tone(7000, 16000, 1));   // above the new 4 kHz limit: must not alias in loudly
  assert.ok(rms(stop) < 2000, 'aliased rms ' + rms(stop));
});

test('16->8 is the same however the audio is cut up', () => {
  const sig = tone(900, 16000, 0.5);
  const whole = new Downsampler16to8().process(sig);
  const parts = new Downsampler16to8();
  const joined = Buffer.concat(Array.from({ length: Math.ceil(sig.length / 640) }, (_, i) => parts.process(sig.subarray(i * 640, Math.min((i + 1) * 640, sig.length)))));
  assert.equal(joined.length, whole.length);
  assert.ok(joined.equals(whole));
});
