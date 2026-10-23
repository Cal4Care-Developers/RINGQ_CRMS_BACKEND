// MR AI changes - starts
// Multi-model dispatch. Rewritten from a single MODEL_PROVIDER env var
// selected once at process start -- that made sense when one process
// served one fixed model, but provider choice is now PER AGENT (the
// Flutter UI's model dropdown, saved via AiController::saveAction as
// ringq_model, resolved per call by CallSession.ensureAgentConfig() in
// claude_server.js). getProvider() below is called fresh per call turn
// with whichever provider that call's agent is configured for.
//
// Every provider module (claude.js / openai.js / gemini.js) exports:
//
//   async function streamTurn({ model, systemPrompt, history, tools, onTextDelta })
//     -> Promise<{ text, toolCalls, stopReason, historyEntry }>
//
// - model: provider-specific model id string (already resolved by the
//   caller -- see resolveModelForCall() in claude_server.js)
// - systemPrompt: string
// - systemPromptCacheablePrefix: optional string, a true PREFIX of
//   systemPrompt (see CallSession.currentSystemPrompt() in
//   claude_server.js). Only providers/claude.js reads this, to mark an
//   Anthropic cache_control breakpoint at its end -- every other adapter
//   ignores it and just uses the full systemPrompt string, unchanged.
// - history: PROVIDER-NEUTRAL conversation history, an array of entries
//   shaped one of:
//     { role: 'user', text }
//     { role: 'assistant', text, toolCalls: [{id, name, input}] }
//     { role: 'tool_results', results: [{id, name, output}] }
//   -- never a provider-native message shape. Each adapter translates
//   this to/from its own wire format internally, so the tool-call loop in
//   claude_server.js's CallSession never needs to know or care which
//   provider is answering a given call.
// - tools: [{name, description, input_schema}] (JSON Schema) -- the exact
//   shape CallSession.ensureMcpClient() already produces from the local
//   MCP server's tools/list; each adapter reshapes it for its own API
//   (OpenAI: input_schema -> parameters wrapped in {type:'function',...};
//   Gemini: uppercase OpenAPI-style type enum, no additionalProperties).
// - onTextDelta(delta): called for each raw text chunk as it streams, so
//   sentence-splitting/marker-extraction/TTS-enqueueing stays in ONE
//   place (CallSession.runOneModelTurn()) regardless of provider.
//
// Returns:
// - text: full assistant text produced this turn
// - toolCalls: [{id, name, input}], empty if no tool was requested
// - stopReason: 'tool_use' | 'end_turn', normalized across providers
// - historyEntry: the canonical assistant entry to push onto `history`
import * as claudeProvider from './claude.js';
import * as openaiProvider from './openai.js';
import * as geminiProvider from './gemini.js';
import * as groqProvider from './groq.js';
import * as deepseekProvider from './deepseek.js';
import * as azureProvider from './azure.js';
import * as grokProvider from './grok.js';

const PROVIDERS = {
  claude: claudeProvider,
  openai: openaiProvider,
  gemini: geminiProvider,
  groq: groqProvider,
  deepseek: deepseekProvider,
  grok: grokProvider,
  'azure openai': azureProvider, // needs the agent's endpoint + deployment (see azure.js)
  // 'Azure OpenAI' (the sixth option in ai_agent_edit.dart's dropdown) is
  // deliberately NOT mapped here, unlike Groq/DeepSeek: Azure's API needs
  // a resource name, a deployment name, and an api-version per tenant --
  // none of which exist anywhere in the agent schema today (Redis'
  // agent:{orgId}:{agentId} blob has no field for them). Groq/DeepSeek
  // only needed a base URL + one API key, which is why those two got a
  // real adapter and this one didn't -- it's a missing-config gap, not a
  // missing-code one. getProvider() returns null for it and the caller
  // falls back the same way it does for any unmapped/unreachable config.
};

/**
 * Resolves a provider module by name (case-insensitive; matches the
 * Flutter UI's ringq_model strings -- "Claude", "OpenAI", "Gemini",
 * "Groq", "DeepSeek"). Returns null for "Azure OpenAI" (see the comment
 * above) or anything else unmapped -- the caller (claude_server.js) is
 * responsible for falling back sensibly when this returns null, same as
 * it already does for a missing/unreachable agent config.
 */
export function getProvider(providerName) {
  const key = String(providerName || 'claude').toLowerCase();
  return PROVIDERS[key] || null;
}

export function isProviderSupported(providerName) {
  return getProvider(providerName) !== null;
}
// MR AI changes - ends
