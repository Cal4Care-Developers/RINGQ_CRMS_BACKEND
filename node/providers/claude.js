// MR AI changes - starts
// Real Claude provider adapter for the common multi-model interface (see
// providers/index.js for the full contract doc). Rewritten from scratch --
// the previous version of this file assumed Anthropic's remote MCP
// connector (mcp_servers/mcp_toolset), which claude_server.js never
// actually used and which only works for Claude anyway. This version just
// takes a plain `tools` array (already produced by claude_server.js's
// local MCP client, see ensureMcpClient() there) and a provider-neutral
// `history`, and translates both to/from Anthropic's wire format --
// nothing here is remote-MCP-specific anymore.
import Anthropic from '@anthropic-ai/sdk';

// Constructed lazily (not at module load) so importing this file never
// throws just because ANTHROPIC_API_KEY isn't set -- e.g. a tenant whose
// agents are all configured for OpenAI/Gemini should never care whether
// this file can construct an Anthropic client.
// One client per key (each agent brings its own); capped so it cannot grow without bound.
const anthropicClients = new Map();
function getClient(apiKey) {
  let client = anthropicClients.get(apiKey);
  if (!client) {
    if (anthropicClients.size >= 50) anthropicClients.delete(anthropicClients.keys().next().value);
    client = new Anthropic({ apiKey });
    anthropicClients.set(apiKey, client);
  }
  return client;
}

// No environment fallback -- see MissingProviderKeyError in openai.js.
import { MissingProviderKeyError } from './openai.js';

// canonical history entry -> one Anthropic message. Claude's `tools`/
// `tool_use`/`tool_result` shape is what the canonical shape was modelled
// on, so this translation is close to a passthrough compared to the
// OpenAI/Gemini adapters.
function toAnthropicMessage(entry) {
  if (entry.role === 'tool_results') {
    return {
      role: 'user',
      content: entry.results.map((r) => ({
        type: 'tool_result',
        tool_use_id: r.id,
        content: r.output,
      })),
    };
  }
  if (entry.role === 'assistant') {
    const blocks = [];
    if (entry.text) blocks.push({ type: 'text', text: entry.text });
    for (const tc of entry.toolCalls || []) {
      blocks.push({ type: 'tool_use', id: tc.id, name: tc.name, input: tc.input });
    }
    return { role: 'assistant', content: blocks };
  }
  // plain user text turn
  return { role: 'user', content: entry.text };
}

// MR AI changes - starts
// Request tuning for a LIVE PHONE CALL, which is a different problem from a
// chat UI: the caller is listening to silence while we generate, so
// time-to-first-sentence dominates perceived quality.
//
// - Fast mode (`speed: 'fast'`, beta fast-mode-2026-02-01) runs the same
//   model at up to ~2.5x output tokens/sec. It is a research preview on
//   Opus 5 / Opus 4.8 only, Claude API only, priced higher, and has its own
//   rate limit separate from standard Opus -- which is why streamTurn()
//   below falls back to standard speed on a 429 rather than failing the
//   turn. It also requires the BETA messages endpoint, hence
//   client.beta.messages.stream.
//
// - effort 'low' keeps adaptive thinking on but shallow. Thinking is ON by
//   default on Opus 5, and at default effort that is a noticeable pause
//   before the first word. Lowering effort is the right lever here rather
//   than `thinking: {type:'disabled'}`: with thinking disabled, Opus 5
//   occasionally writes a tool call into its VISIBLE text instead of
//   emitting a tool_use block. In a chat that is a cosmetic bug; on a phone
//   call it means the assistant reads the tool call out loud to the caller
//   and nothing actually runs. Low effort avoids that failure mode and
//   still cuts latency and cost.
//
// - max_tokens 1024, up from 300. A turn that emits speech AND a tool_use
//   block has to fit both, and 300 was truncating them -- which surfaces as
//   a sentence cut off mid-word, or a tool call that never arrives.
//   Response length is controlled by the prompt ("short sentences, you are
//   on a live call"), not by starving max_tokens.
//
// - temperature is deliberately NOT sent. Sampling parameters are removed
//   on Opus 5 / Sonnet 5 and return HTTP 400. The Flutter editor still has
//   a temperature slider, and it still applies to the OpenAI/Gemini/Groq/
//   DeepSeek adapters -- it just cannot be forwarded here.
const FAST_MODE_BETA = 'fast-mode-2026-02-01';

// Fast mode is only available on these; anything else must not send
// `speed`, or the request is rejected outright.

const FAST_MODE_MODELS = new Set(['claude-opus-5', 'claude-opus-4-8']);
// Turned off process-wide after the first 429 attributable to the fast-mode
// rate limit, so a tenant that is simply not provisioned for it stops
// paying a failed request per turn.
//
// MR AI changes: that first 429 is NOT free -- it is a full failed round trip
// to the API, and because the flag resets on restart it lands on the FIRST
// turn of the FIRST call after every deploy, which is exactly the turn a
// caller notices. Set CLAUDE_FAST_MODE=0 in .env on a deployment whose
// account is not provisioned for fast mode and that request is never made.
let fastModeAvailable = process.env.CLAUDE_FAST_MODE !== '0';
if (!fastModeAvailable) {
  console.log('[CLAUDE] fast mode disabled by CLAUDE_FAST_MODE=0 -- every turn goes straight to standard speed');
}

// MR AI changes - starts
// Anthropic prompt caching: a cache_control breakpoint marks "cache
// everything up to and including this block". It only ever caches a
// PREFIX, so this only activates when the caller hands us a genuine
// prefix of systemPrompt (see systemPromptCacheablePrefix in
// providers/index.js's contract doc) -- otherwise `system` stays the
// plain string it always was, same as before this existed.
//
// Cache writes cost 25% more than a normal input token; cache READS cost
// ~10% of one. This system prompt is rebuilt fresh every turn (see
// CallSession.currentSystemPrompt()) and was previously billed at full
// price on every single turn of every call -- with the split, turn 2
// onward (once the tool list has resolved -- see the comment on
// buildSystemPrompt() in claude_server.js) hits the cache instead. The
// time-sensitive suffix (current date/time) is deliberately excluded from
// the cached block so it never invalidates it.
function buildSystemParam(systemPrompt, cacheablePrefix) {
  if (!cacheablePrefix || !systemPrompt.startsWith(cacheablePrefix)) {
    return systemPrompt;
  }
  const rest = systemPrompt.slice(cacheablePrefix.length);
  const blocks = [{ type: 'text', text: cacheablePrefix, cache_control: { type: 'ephemeral' } }];
  if (rest) blocks.push({ type: 'text', text: rest });
  return blocks;
}
// MR AI changes - ends

function buildRequest({ model, systemPrompt, systemPromptCacheablePrefix, history, tools }, useFastMode) {
  return {
    model,
    max_tokens: 1024,
    system: buildSystemParam(systemPrompt, systemPromptCacheablePrefix),
    messages: history.map(toAnthropicMessage),
    output_config: { effort: 'low' },
    ...(useFastMode ? { speed: 'fast', betas: [FAST_MODE_BETA] } : {}),
    ...(tools && tools.length ? { tools } : {}), // already {name, description, input_schema} -- Claude-native
  };
}

export async function streamTurn({ model, systemPrompt, systemPromptCacheablePrefix, history, tools, onTextDelta, apiKey }) {
  if (!apiKey) throw new MissingProviderKeyError('Claude');
  const client = getClient(apiKey);
  const wantFastMode = fastModeAvailable && FAST_MODE_MODELS.has(model);

  let text = '';
  // Only forward deltas from the attempt that actually survives. Without
  // this guard a fast-mode attempt that 429s after emitting a partial
  // sentence would have already spoken it, and the retry would then speak
  // an overlapping version of the same thing.
  let deltasCommitted = false;
  const onDelta = (delta) => {
    text += delta;
    deltasCommitted = true;
    onTextDelta(delta);
  };

  async function attempt(useFastMode) {
    // Fast mode requires the BETA messages endpoint. client.beta.messages.stream
    // only exists from @anthropic-ai/sdk ~0.4x onward -- this project was
    // pinned to 0.32.1 for a long time, where it is undefined. Calling it
    // there throws a TypeError mid-call, which is a far worse outcome than
    // simply not using fast mode, so check rather than assume: a box that
    // has not run `npm install` since the version bump degrades to standard
    // speed instead of dropping every call.
    const canUseBeta = typeof client.beta?.messages?.stream === 'function';
    if (useFastMode && !canUseBeta) {
      console.warn('[CLAUDE] installed @anthropic-ai/sdk has no beta.messages.stream, so fast mode ' +
        'is unavailable -- run npm install to pick up the pinned version. Continuing at standard speed.');
      fastModeAvailable = false;
      useFastMode = false;
    }
    // After the guard above, useFastMode implies canUseBeta -- so this is
    // just "prefer the beta endpoint where it exists". The beta endpoint is
    // a superset, and using it unconditionally keeps one code path warm.
    const params = buildRequest({ model, systemPrompt, systemPromptCacheablePrefix, history, tools }, useFastMode);
    const stream = canUseBeta ? client.beta.messages.stream(params) : client.messages.stream(params);
    stream.on('text', onDelta);
    return stream.finalMessage();
  }

  let finalMessage;
  try {
    finalMessage = await attempt(wantFastMode);
  } catch (err) {
    const isRateLimit = err?.status === 429;
    if (wantFastMode && isRateLimit && !deltasCommitted) {
      console.warn('[CLAUDE] fast mode rate-limited (429) -- retrying this turn at standard speed ' +
        'and disabling fast mode for the rest of this process.');
      fastModeAvailable = false;
      text = '';
      finalMessage = await attempt(false);
    } else {
      throw err;
    }
  }

  const toolCalls = [];
  let finalText = '';
  for (const block of finalMessage.content || []) {
    if (block.type === 'text') finalText += block.text;
    if (block.type === 'tool_use') toolCalls.push({ id: block.id, name: block.name, input: block.input });
  }
  // Prefer the streamed accumulation (`text`) for logging/return value,
  // but finalMessage.content is authoritative for what actually goes into
  // history -- they should match, but the API is the source of truth.

  // A safety refusal arrives as HTTP 200 with stop_reason 'refusal' and no
  // usable content, which would otherwise show up as the assistant simply
  // going silent mid-call. Say something instead.
  if (finalMessage.stop_reason === 'refusal') {
    console.warn('[CLAUDE] request was refused by a safety classifier:',
      JSON.stringify(finalMessage.stop_details || {}));
    if (!finalText && !text) {
      finalText = 'I am sorry, I am unable to assist with that.';
    }
  }

  return {
    text: finalText || text,
    toolCalls,
    stopReason: finalMessage.stop_reason === 'tool_use' ? 'tool_use' : 'end_turn',
    historyEntry: { role: 'assistant', text: finalText || text, toolCalls },
  };
}
// MR AI changes - ends
// MR AI changes - ends
