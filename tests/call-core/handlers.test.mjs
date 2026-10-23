// The web dialer and the mobile app drive transfer, conference and park by emitting these
// socket events; node/signal.js must handle each exactly once. A missing handler makes the
// button do nothing, and a duplicate one runs the action twice (two bridges, two parks).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const signal = readFileSync(new URL('../../node/signal.js', import.meta.url), 'utf8');
const registrations = (name) =>
  signal.split('\n').filter((line) => new RegExp(`^\\s*socket\\.on\\(\\s*(['"\`])${name}\\1`).test(line)).length;

const EMITTED_BY_APPS = {
  hold: 'Dialer hold',
  park: 'park into a slot',
  parkTransfer: 'transfer a parked call',
  resumeCall: 'resume after a reload',
  parkAttended: 'attended transfer: start',
  unparkAttendedTransfer: 'attended transfer: switch party',
  parkAttendedTransfer: 'attended transfer: complete / cancel',
  recoverAttendedTransfer: 'attended transfer: recover',
  conferrence: 'conference: add participant (server spelling)',
  kickCallConference: 'conference: remove participant',
};

for (const [event, what] of Object.entries(EMITTED_BY_APPS)) {
  test(`signal.js handles "${event}" (${what}) exactly once`, () => {
    assert.equal(registrations(event), 1);
  });
}

// The web dialer's mute/unmute buttons emit these, but their handlers are commented out
// in signal.js, so the buttons do nothing on the server today.
for (const event of ['muteCallConference', 'unmuteCallConference']) {
  test(`signal.js handles "${event}"`, { todo: 'handler is commented out in signal.js' }, () => {
    assert.equal(registrations(event), 1);
  });
}

test('every protected handler in .github/call-core/protected.json exists exactly once', () => {
  const config = JSON.parse(readFileSync(new URL('../../.github/call-core/protected.json', import.meta.url), 'utf8'));
  for (const name of config.handlers['node/signal.js']) {
    assert.equal(registrations(name), 1, `socket.on("${name}")`);
  }
});

test('every protected server.js push function exists exactly once', () => {
  const config = JSON.parse(readFileSync(new URL('../../.github/call-core/protected.json', import.meta.url), 'utf8'));
  const server = readFileSync(new URL('../../node/server.js', import.meta.url), 'utf8');
  for (const name of config.functions['node/server.js']) {
    const count = server.split('\n').filter((line) => new RegExp(`^(async\\s+)?function\\s+${name}\\s*\\(`).test(line)).length;
    assert.equal(count, 1, `function ${name}()`);
  }
});
