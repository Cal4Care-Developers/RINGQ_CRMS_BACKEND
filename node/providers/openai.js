// MR AI changes - starts
// Real OpenAI-COMPATIBLE provider adapter for the common multi-model
// interface (see providers/index.js for the full contract). Rewritten
// from scratch -- the previous version of this file was a documented
// CAVEAT-laden guess at OpenAI's remote-MCP-connector shape and was never
// wired up to anything. This version uses the well-established, stable
// Chat Completions streaming + function-calling API
// (client.chat.completions.create({stream:true, tools:[...]})) instead --
// no MCP-connector dependency, since tool execution happens locally via
// claude_server.js's own MCP client either way (see the "LOCAL MCP
// CLIENT" comment block there); this file only needs to speak OpenAI's
// function-calling wire format, which is long-stable.
//
// FACTORY, not just a fixed export: Groq and DeepSeek (both offered in
// the Flutter model dropdown alongside OpenAI/Claude/Gemini) both expose
// an OpenAI-compatible Chat Completions API -- same request/response
// shape, just a different base URL and API key. createOpenAiCompatibleProvider()
// below is the shared implementation; providers/groq.js and
// providers/deepseek.js are two-line files that just call it with their
// own baseURL/env var, instead of duplicating this whole file. The plain
// `streamTurn` export at the bottom is this file's own use as the real
// OpenAI provider.
import OpenAI, { AzureOpenAI } from 'openai';

// Thrown when an agent has no key for its provider. There is deliberately NO environment
// fallback: keys come from the agent's own saved key (TeamsaiController::resolveAgentAction),
// so a missing one must surface as a clear failure, not silently use someone else's account.
export class MissingProviderKeyError extends Error {
  constructor(provider) {
    super("No API key is saved for this agent (" + provider + ").");
    this.code = 'NO_PROVIDER_KEY';
    this.provider = provider;
  }
}


// MCP tool defs arrive as {name, description, input_schema} (JSON
// Schema) -- OpenAI's function-calling shape just renames input_schema
// to `parameters` and wraps it in {type:'function', function:{...}}.
function toOpenAiTool(tool) {
  return {
    type: 'function',
    function: {
      name: tool.name,
      description: tool.description || '',
      parameters: tool.input_schema || { type: 'object', properties: {} },
    },
  };
}

// canonical history entry -> ARRAY of OpenAI messages (a tool_results
// entry expands to one {role:'tool', ...} message PER result, since
// that's how OpenAI's API represents multiple tool results -- unlike
// Claude/Gemini, which bundle them into one message).
function toOpenAiMessages(entry) {
  if (entry.role === 'tool_results') {
    return entry.results.map((r) => ({
      role: 'tool',
      tool_call_id: r.id,
      content: r.output,
    }));
  }
  if (entry.role === 'assistant') {
    const msg = { role: 'assistant', content: entry.text || null };
    if (entry.toolCalls && entry.toolCalls.length) {
      msg.tool_calls = entry.toolCalls.map((tc) => ({
        id: tc.id,
        type: 'function',
        function: { name: tc.name, arguments: JSON.stringify(tc.input) },
      }));
    }
    return [msg];
  }
  return [{ role: 'user', content: entry.text }];
}

/**
 * Builds a { streamTurn } provider for any OpenAI-Chat-Completions-
 * compatible API. `apiKeyEnvVar` names the env var to read the key from
 * (kept per-provider so a Groq key can never accidentally get used
 * against OpenAI's endpoint or vice versa); `baseURL` overrides the
 * default OpenAI endpoint (omit for the real OpenAI provider itself).
 */
export function createOpenAiCompatibleProvider({ label = 'OpenAI', baseURL, azure = false, apiVersion = '2024-10-21' } = {}) {
  // One client per distinct key/endpoint, not one per process: each agent brings its own key,
  // so two tenants (or two agents) must never share a client. Capped so a long-running process
  // that sees many keys cannot grow without bound.
  const clients = new Map();
  function getClient({ apiKey, endpoint, deployment }) {
    const cacheKey = [apiKey, endpoint || '', deployment || ''].join('|');
    let client = clients.get(cacheKey);
    if (!client) {
      if (clients.size >= 50) clients.delete(clients.keys().next().value);
      client = azure
        ? new AzureOpenAI({ apiKey, endpoint, deployment, apiVersion })
        : new OpenAI({ apiKey, ...(baseURL ? { baseURL } : {}) });
      clients.set(cacheKey, client);
    }
    return client;
  }

  async function streamTurn({ model, systemPrompt, history, tools, onTextDelta, apiKey, endpoint, deployment }) {
    if (!apiKey) throw new MissingProviderKeyError(label);
    if (azure && (!endpoint || !deployment)) {
      throw new Error('Azure OpenAI needs both an endpoint and a deployment name saved on the agent.');
    }
    const messages = [
      { role: 'system', content: systemPrompt },
      ...history.flatMap(toOpenAiMessages),
    ];

    const stream = await getClient({ apiKey, endpoint, deployment }).chat.completions.create({
      // Azure routes by deployment name, not model id.
      model: azure ? deployment : model,
      messages,
      stream: true,
      ...(tools && tools.length ? { tools: tools.map(toOpenAiTool) } : {}),
    });

    let text = '';
    // Tool-call argument JSON arrives in fragments, keyed by the tool
    // call's position in the assistant's response (`index`) -- accumulate
    // by index and parse once the stream ends, same pattern every OpenAI
    // streaming-with-tools example uses.
    const toolCallsByIndex = new Map();
    let finishReason = 'stop';

    for await (const chunk of stream) {
      const choice = chunk.choices?.[0];
      if (!choice) continue;

      const delta = choice.delta || {};
      if (delta.content) {
        text += delta.content;
        onTextDelta(delta.content);
      }

      for (const tcDelta of delta.tool_calls || []) {
        const idx = tcDelta.index;
        if (!toolCallsByIndex.has(idx)) {
          toolCallsByIndex.set(idx, { id: '', name: '', argsText: '' });
        }
        const acc = toolCallsByIndex.get(idx);
        if (tcDelta.id) acc.id = tcDelta.id;
        if (tcDelta.function?.name) acc.name += tcDelta.function.name;
        if (tcDelta.function?.arguments) acc.argsText += tcDelta.function.arguments;
      }

      if (choice.finish_reason) finishReason = choice.finish_reason;
    }

    const toolCalls = [...toolCallsByIndex.values()].map((acc) => {
      let input = {};
      try {
        input = acc.argsText ? JSON.parse(acc.argsText) : {};
      } catch (err) {
        console.error('[OPENAI] failed to parse tool call arguments:', acc.argsText, err.message);
      }
      return { id: acc.id, name: acc.name, input };
    });

    const stopReason = finishReason === 'tool_calls' ? 'tool_use' : 'end_turn';

    return {
      text,
      toolCalls,
      stopReason,
      historyEntry: { role: 'assistant', text, toolCalls },
    };
  }

  return { streamTurn };
}

// This file's own use: the real OpenAI provider.
export const { streamTurn } = createOpenAiCompatibleProvider({ label: 'OpenAI' });
// MR AI changes - ends
