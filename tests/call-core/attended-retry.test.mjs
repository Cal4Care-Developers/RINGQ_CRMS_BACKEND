// When a consult is not answered, [att-recover] in server.js joins the agent back to the caller. It
// must bridge the caller first: the caller then stays the call's A leg, and the next attended transfer
// holds the caller and sends the agent's leg. Bridged agent-first, attended.js took the A-leg branch
// and moved the caller straight to the target (a blind transfer in the call journey).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const server = readFileSync(new URL('../../node/server.js', import.meta.url), 'utf8');

test('server.js [att-recover] reconnects agent and caller with the caller first', () => {
  const line = server.split('\n').find((l) => l.includes('await attApi(`uuid_bridge '));
  assert.ok(line, 'att-recover uuid_bridge not found');
  assert.ok(line.includes('uuid_bridge ${parkCall} ${transferCall}'), line.trim());
});

// The same holds for the agent's Cancel (signal.js parkAttendedTransfer) and the *99 handler in
// server.js: both re-join the call caller first, so a transfer after them consults normally.
const signal = readFileSync(new URL('../../node/signal.js', import.meta.url), 'utf8');

test('signal.js Cancel (parkAttendedTransfer) reconnects the caller first', () => {
  assert.ok(signal.includes('await promisifiedApi(conn, `uuid_bridge ${parkCall} ${transferCall}`);'));
});

test('server.js *99 handler reconnects the caller first', () => {
  assert.ok(server.includes('conn.api(`uuid_bridge ${parkCall} ${transferCall}`);'));
});

for (const [file, src] of [['node/signal.js', signal], ['node/server.js', server]]) {
  test(`${file} has no agent-first re-join left`, () => {
    assert.ok(!src.includes('uuid_bridge ${transferCall} ${parkCall}'), 'agent-first uuid_bridge found');
  });
}
