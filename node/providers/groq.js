// MR AI changes - starts
// Groq is offered in the Flutter model dropdown (ai_agent_edit.dart's
// _aiProviders list) alongside Claude/OpenAI/Gemini. Groq's API is
// OpenAI-Chat-Completions-compatible (same request/response shape, tool
// calling included) -- so instead of a stub or a silent fallback to
// Claude, this reuses providers/openai.js's real implementation with
// Groq's own base URL and API key.
import { createOpenAiCompatibleProvider } from './openai.js';

export const { streamTurn } = createOpenAiCompatibleProvider({
  label: 'Groq',
  baseURL: 'https://api.groq.com/openai/v1',
});
// MR AI changes - ends
