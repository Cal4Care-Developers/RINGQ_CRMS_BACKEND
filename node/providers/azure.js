// MR AI changes - starts
// Azure OpenAI: same Chat Completions wire format as OpenAI, but addressed by the tenant's own
// resource endpoint + deployment name (both saved on the agent) instead of a model id. The
// factory in openai.js does the work; this file only turns the Azure client on.
import { createOpenAiCompatibleProvider } from './openai.js';

export const { streamTurn } = createOpenAiCompatibleProvider({ label: 'Azure OpenAI', azure: true });
// MR AI changes - ends
