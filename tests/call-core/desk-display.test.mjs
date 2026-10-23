// Desk phones register with a 20-character SIP user. On an extension-to-extension call the caller's
// phone showed the callee's SIP user, and a BLF pickup showed both sides each other's SIP user.
// local_extension now sends the dialled extension's number and name as the callee ID, and the BLF
// pickup (ringq_blf_pickup.lua) sends each side the other's extension. The pickup also takes queue
// calls, which never reach the last_dial_ext hash.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL(`../../${p}`, import.meta.url), 'utf8');
const le = read('server-configs/call-control/local_extension.xml');
const [, internal, other] = le.split(/<condition field="\$\{caller_id_number\}"|<condition field="" expression="">/);

test('local_extension sends the extension as callee ID on internal calls', () => {
  assert.ok(internal.includes('initial_callee_id_number=${user_data(${dialed_extension}@${domain_name} attr number-alias)}'));
  assert.ok(internal.includes('export" data="nolocal:origination_callee_id_name=${initial_callee_id_name}"'));
  assert.ok(internal.includes('export" data="nolocal:origination_callee_id_number=${initial_callee_id_number}"'));
});

test('local_extension stops sending it before relay / failover', () => {
  const unset = internal.indexOf('unset" data="nolocal:origination_callee_id_number"');
  const failover = internal.indexOf('ringq_call_control.lua failover');
  const lastFollow = internal.lastIndexOf('var follow_internal');
  assert.ok(unset > lastFollow && unset < failover);
  assert.ok(internal.includes('unset" data="nolocal:origination_callee_id_name"'));
});

test('outside callers are unchanged', () => {
  assert.ok(!other.includes('origination_callee_id'));
  assert.ok(!other.includes('initial_callee_id_number'));
});

test('jobs.sh carries this local_extension and upgrades the earlier RingQ one', () => {
  const jobs = read('app/scripts/jobs.sh');
  assert.ok(jobs.includes(le.trim().split('\n').find((l) => l.includes('[desk-display]'))));
  assert.match(jobs, /CM_OLD_LE="[^"]*27ad8f6e963afa3d6d175fd936173004/);
});

test('BLF pickup runs ringq_blf_pickup.lua, installed on every update', () => {
  const sql = read('app/scripts/desk-features/desk-features.sql');
  assert.ok(sql.includes('<action application="lua" data="ringq_blf_pickup.lua $1"/>'));
  assert.ok(read('app/scripts/desk-features/desk-features-setup.sh').includes('ringq_blf_pickup.lua'));
  const lua = read('app/scripts/desk-features/ringq_blf_pickup.lua');
  assert.ok(lua.includes('cc_member_session_uuid'));
  assert.ok(lua.includes('intercept_unbridged_only'));
});
