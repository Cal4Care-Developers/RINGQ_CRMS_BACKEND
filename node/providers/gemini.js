// MR AI changes - starts
// Real Gemini provider adapter for the common multi-model interface (see
// providers/index.js for the full contract). The previous version of
// this file was a deliberate stub that threw "not yet implemented" --
// this replaces it with a working implementation using
// @google/generative-ai's stateless generateContentStream() (full
// `contents` array per call, same stateless-per-turn shape the Claude/
// OpenAI adapters use) with function-calling declarations built from the
// same MCP tool defs the other two providers consume.
//
// CAVEAT: Gemini's function-calling schema dialect (uppercase OPENAPI-
// style `type` enum: OBJECT/STRING/NUMBER/INTEGER/BOOLEAN/ARRAY, no
// `additionalProperties`) and the exact current @google/generative-ai
// method names are the least-verified part of this file -- this is
// written from documented patterns, not confirmed against a live call
// (no Gemini API key in this environment). If tool calls fail specifically
// on this provider, re-check https://ai.google.dev/gemini-api/docs/function-calling
// first.
import { GoogleGenerativeAI } from '@google/generative-ai';

// One client per key (each agent brings its own); capped so it cannot grow without bound.
import { MissingProviderKeyError } from './openai.js';
const genAiClients = new Map();
function getClient(apiKey) {
  let client = genAiClients.get(apiKey);
  if (!client) {
    if (genAiClients.size >= 50) genAiClients.delete(genAiClients.keys().next().value);
    client = new GoogleGenerativeAI(apiKey);
    genAiClients.set(apiKey, client);
  }
  return client;
}

// Gemini's function-declaration schema wants OpenAPI-style uppercase
// `type` enums and doesn't support `additionalProperties`/`$schema` --
// recursively reshape our MCP tools' plain JSON Schema into that dialect.
const JSON_SCHEMA_TYPE_TO_GEMINI = {
  object: 'OBJECT',
  string: 'STRING',
  number: 'NUMBER',
  integer: 'INTEGER',
  boolean: 'BOOLEAN',
  array: 'ARRAY',
};

function toGeminiSchema(schema) {
  if (!schema || typeof schema !== 'object') return schema;
  const { additionalProperties, $schema, ...rest } = schema;
  const out = { ...rest };
  if (typeof out.type === 'string') {
    out.type = JSON_SCHEMA_TYPE_TO_GEMINI[out.type.toLowerCase()] || out.type.toUpperCase();
  }
  if (out.properties && typeof out.properties === 'object') {
    out.properties = Object.fromEntries(
      Object.entries(out.properties).map(([k, v]) => [k, toGeminiSchema(v)])
    );
  }
  if (out.items) {
    out.items = toGeminiSchema(out.items);
  }
  return out;
}

function toGeminiFunctionDeclaration(tool) {
  return {
    name: tool.name,
    description: tool.description || '',
    parameters: toGeminiSchema(tool.input_schema || { type: 'object', properties: {} }),
  };
}

// canonical history entry -> one Gemini `content` (Gemini has no
// separate "tool"/"assistant" role -- function calls/results are just
// parts inside 'model'/'user' turns respectively).
function toGeminiContent(entry) {
  if (entry.role === 'tool_results') {
    return {
      role: 'user',
      parts: entry.results.map((r) => {
        // functionResponse.response must be a JSON object, not a bare
        // string -- our tool outputs are already JSON text (from the MCP
        // tool_result content), so parse if possible, else wrap it.
        let response;
        try {
          response = JSON.parse(r.output);
        } catch {
          response = { result: r.output };
        }
        return { functionResponse: { name: r.name, response } };
      }),
    };
  }
  if (entry.role === 'assistant') {
    const parts = [];
    if (entry.text) parts.push({ text: entry.text });
    for (const tc of entry.toolCalls || []) {
      parts.push({ functionCall: { name: tc.name, args: tc.input } });
    }
    return { role: 'model', parts };
  }
  return { role: 'user', parts: [{ text: entry.text }] };
}

export async function streamTurn({ model, systemPrompt, history, tools, onTextDelta, apiKey }) {
  if (!apiKey) throw new MissingProviderKeyError('Gemini');
  const client = getClient(apiKey);

  const generativeModel = client.getGenerativeModel({
    model,
    systemInstruction: systemPrompt,
    ...(tools && tools.length
      ? { tools: [{ functionDeclarations: tools.map(toGeminiFunctionDeclaration) }] }
      : {}),
  });

  const contents = history.map(toGeminiContent);

  const result = await generativeModel.generateContentStream({ contents });

  let text = '';
  for await (const chunk of result.stream) {
    const delta = chunk.text();
    if (delta) {
      text += delta;
      onTextDelta(delta);
    }
  }

  const response = await result.response;

  // Gemini's functionCalls() returns [{name, args}] -- no call id, unlike
  // Claude/OpenAI. Synthesize one so the common {id,name,input} shape
  // still holds; matched back up by `name` (not `id`) in toGeminiContent()
  // above when the tool_results entry is translated for the next turn.
  const rawCalls = typeof response.functionCalls === 'function' ? response.functionCalls() || [] : [];
  const toolCalls = rawCalls.map((c, i) => ({ id: `call_${i}`, name: c.name, input: c.args || {} }));

  return {
    text,
    toolCalls,
    stopReason: toolCalls.length ? 'tool_use' : 'end_turn',
    historyEntry: { role: 'assistant', text, toolCalls },
  };
}
// MR AI changes - ends
