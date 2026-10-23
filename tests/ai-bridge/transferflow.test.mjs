// Keyword transfers: the keyword's message is said, then the call goes -- no yes/no first.
// Taken straight out of node/claude_server.js. Run: node --test tests/ai-bridge/transferflow.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const src = fs.readFileSync(path.join(here, '../../node/claude_server.js'), 'utf8').split(String.fromCharCode(13, 10)).join(String.fromCharCode(10));
const start = src.indexOf('// 2. KEYWORD TRANSFERS WITH A CONFIRMATION STEP');
const end = src.indexOf('// MR AI changes - ends', start);
assert.ok(start > 0 && end > start, 'transfer flow block not found');
// humanRequested() belongs to the escalation block; a plain phrase check stands in for it here.
const { TransferFlow, findTarget } = new Function('humanRequested',
  src.slice(start, end) + String.fromCharCode(10) + 'return { TransferFlow, findTarget };')((t) => /\b(human|person|agent)\b/.test(t));

const rulesStart = src.indexOf('function buildKeywordRules(keywords) {');
const buildKeywordRules = new Function('confirmQuestion',
  src.slice(rulesStart, src.indexOf(String.fromCharCode(10) + '}' + String.fromCharCode(10), rulesStart) + 3) + String.fromCharCode(10) + 'return buildKeywordRules;')(() => '');

const targets = [
  { keyword: 'Testing', message: 'Hello user, transfer the call to the preferred agent', extension: '1008' },
  { keyword: 'Sales', message: 'Putting you through to sales.', extension: '1001' },
];

test('saying a keyword speaks its message and transfers at once', () => {
  const flow = new TransferFlow();
  const r = flow.onCallerTurn('I need testing please', targets);
  assert.equal(r.say, 'Hello user, transfer the call to the preferred agent');
  assert.equal(r.transfer.extension, '1008');
  assert.match(r.transfer.reason, /Testing/);
  assert.equal(flow.pending, null);
});

test('the other keyword goes to its own message and destination', () => {
  const r = new TransferFlow().onCallerTurn('sales', targets);
  assert.deepEqual([r.say, r.transfer.extension], ['Putting you through to sales.', '1001']);
});

test('"transfer me" with several keywords asks who; naming one then goes straight through', () => {
  const flow = new TransferFlow();
  const ask = flow.onCallerTurn('please transfer me', targets);
  assert.match(ask.say, /Who would you like me to transfer you to/);
  assert.equal(ask.transfer, undefined);
  const go = flow.onCallerTurn('sales', targets);
  assert.equal(go.transfer.extension, '1001');
});

test('"transfer me" with one keyword goes straight through', () => {
  const r = new TransferFlow().onCallerTurn('can you transfer me', [targets[0]]);
  assert.equal(r.transfer.extension, '1008');
});

test('a sentence that is not about a transfer is left to the AI', () => {
  assert.equal(new TransferFlow().onCallerTurn('what are your opening hours', targets), null);
});

test('a question the model asked is still answered with yes or no', () => {
  const flow = new TransferFlow();
  flow.arm(targets[1]);
  assert.equal(flow.onCallerTurn('yes', targets).transfer.extension, '1001');
  flow.arm(targets[1]);
  assert.match(flow.onCallerTurn('no thanks', targets).say, /no problem/i);
});

test('the model is told to say the message, with no confirmation question', () => {
  const rules = buildKeywordRules([{ keyword: 'Testing', message: 'Hello user, transfer the call to the preferred agent' }, { keyword: 'Billing' }]);
  assert.match(rules, /respond with exactly:\n"Hello user, transfer the call to the preferred agent"/);
  assert.match(rules, /"I am now transferring to a live Billing\."/);
  assert.match(rules, /do not ask the caller to confirm/);
  assert.doesNotMatch(rules, /Would you like me to transfer/);
});
