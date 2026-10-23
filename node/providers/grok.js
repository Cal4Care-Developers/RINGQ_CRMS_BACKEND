// MR AI changes - starts
// Grok (xAI). xAI's API is OpenAI-Chat-Completions-compatible (tool calling included), so like
// Groq and DeepSeek this reuses providers/openai.js with xAI's own base URL and the agent's key.
// Not to be confused with Groq (providers/groq.js), which is a different company.
import { createOpenAiCompatibleProvider } from './openai.js';

export const { streamTurn } = createOpenAiCompatibleProvider({
  label: 'Grok',
  baseURL: 'https://api.x.ai/v1',
});
// MR AI changes - ends
