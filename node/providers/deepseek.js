// MR AI changes - starts
// DeepSeek is offered in the Flutter model dropdown (ai_agent_edit.dart's
// _aiProviders list) alongside Claude/OpenAI/Gemini. DeepSeek's API is
// OpenAI-Chat-Completions-compatible (same request/response shape, tool
// calling included) -- so instead of a stub or a silent fallback to
// Claude, this reuses providers/openai.js's real implementation with
// DeepSeek's own base URL and API key.
import { createOpenAiCompatibleProvider } from './openai.js';

export const { streamTurn } = createOpenAiCompatibleProvider({
  label: 'DeepSeek',
  baseURL: 'https://api.deepseek.com',
});
// MR AI changes - ends
