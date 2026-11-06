export type AssistantModelProviderId =
  | 'openai'
  | 'anthropic'
  | 'google'
  | 'openrouter'
  | 'azure'
  | 'custom'

export type AssistantProviderModelOption = {
  id: string
  label: string
}

export type AssistantModelProvider = {
  id: AssistantModelProviderId
  label: string
  icon: string | null
  defaultBaseUrl?: string
  models: AssistantProviderModelOption[]
}

export const ASSISTANT_MODEL_PROVIDERS: AssistantModelProvider[] = [
  {
    id: 'openai',
    label: 'OpenAI',
    icon: '/icons/chatgpt.svg',
    defaultBaseUrl: 'https://api.openai.com/v1',
    models: [
      { id: 'gpt-6.1-terra', label: 'GPT-6.1 Terra' },
      { id: 'gpt-6.1-sol', label: 'GPT-6.1 Sol' },
      { id: 'gpt-6-astra', label: 'GPT-6 Astra' },
      { id: 'gpt-6-luna', label: 'GPT-6 Luna' },
    ],
  },
  {
    id: 'anthropic',
    label: 'Anthropic',
    icon: '/icons/anthropic.svg',
    defaultBaseUrl: 'https://api.anthropic.com',
    models: [
      { id: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5' },
      { id: 'claude-opus-5-5', label: 'Claude Opus 5.5' },
      { id: 'claude-haiku-5-5', label: 'Claude Haiku 5.5' },
      { id: 'claude-fable-5-1', label: 'Claude Fable 5.1' },
    ],
  },
  {
    id: 'google',
    label: 'Google',
    icon: '/icons/google.svg',
    defaultBaseUrl: 'https://generativelanguage.googleapis.com/v1beta',
    models: [
      { id: 'gemini-3.7-flash', label: 'Gemini 3.7 Flash' },
      { id: 'gemini-3.1-pro-preview', label: 'Gemini 3.1 Pro' },
      { id: 'gemini-2.5-pro', label: 'Gemini 2.5 Pro' },
      { id: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' },
    ],
  },
  {
    id: 'openrouter',
    label: 'OpenRouter',
    icon: '/icons/openrouter.svg',
    defaultBaseUrl: 'https://openrouter.ai/api/v1',
    models: [
      { id: 'openai/gpt-6.1-terra', label: 'GPT-6.1 Terra' },
      { id: 'openai/gpt-6.1-sol', label: 'GPT-6.1 Sol' },
      { id: 'anthropic/claude-sonnet-5.5', label: 'Claude Sonnet 5.5' },
      { id: 'anthropic/claude-opus-5.5', label: 'Claude Opus 5.5' },
      { id: 'google/gemini-3.7-flash', label: 'Gemini 3.7 Flash' },
      { id: 'meta-llama/llama-4-maverick', label: 'Llama 4 Maverick' },
    ],
  },
  {
    id: 'azure',
    label: 'Azure',
    icon: '/icons/azure.svg',
    defaultBaseUrl: 'https://YOUR_RESOURCE.openai.azure.com',
    models: [
      { id: 'gpt-6.1-terra', label: 'GPT-6.1 Terra' },
      { id: 'gpt-6.1-sol', label: 'GPT-6.1 Sol' },
      { id: 'gpt-6-luna', label: 'GPT-6 Luna' },
    ],
  },
  {
    id: 'custom',
    label: 'Custom',
    icon: null,
    models: [],
  },
]

const providerById = new Map(
  ASSISTANT_MODEL_PROVIDERS.map((provider) => [provider.id, provider]),
)

export function getAssistantModelProvider(
  providerId: string | null | undefined,
): AssistantModelProvider | undefined {
  if (!providerId) return undefined
  return providerById.get(providerId as AssistantModelProviderId)
}

export function getAssistantProviderIconPath(
  providerId: string | null | undefined,
): string | null {
  return getAssistantModelProvider(providerId)?.icon ?? null
}

/**
 * Icon for a concrete model ID. Model brands can differ from the provider
 * (Anthropic ≠ Claude, Azure ≠ GPT / o-series).
 */
export function getAssistantModelIconPath(
  providerId: string | null | undefined,
  modelId: string | null | undefined,
): string | null {
  const id = modelId?.trim().toLowerCase() ?? ''
  if (id.includes('claude')) return '/icons/claude.svg'
  // OpenAI-family models hosted on OpenAI, Azure, OpenRouter, etc.
  if (id.includes('gpt') || id.includes('chatgpt')) return '/icons/chatgpt.svg'
  if (/(^|\/)o[1-9]([.\-]|$)/.test(id)) return '/icons/chatgpt.svg'
  if (id.includes('gemini')) return '/icons/google.svg'
  return getAssistantProviderIconPath(providerId)
}

export function getAssistantProviderModels(
  providerId: string | null | undefined,
): AssistantProviderModelOption[] {
  return getAssistantModelProvider(providerId)?.models ?? []
}

export function getAssistantProviderDefaultBaseUrl(
  providerId: string | null | undefined,
): string | undefined {
  return getAssistantModelProvider(providerId)?.defaultBaseUrl
}

export function getAssistantProviderModelLabel(
  providerId: string | null | undefined,
  modelId: string | null | undefined,
): string | undefined {
  if (!modelId) return undefined
  return getAssistantProviderModels(providerId).find(
    (entry) => entry.id === modelId,
  )?.label
}

/** Backend default; rejected by reasoning models that only allow temperature=1. */
export const ASSISTANT_CHAT_MODEL_TEMP = 0.2

/** Safe temperature for models that only accept the provider default. */
export const ASSISTANT_FIXED_MODEL_TEMP = 1

/**
 * Some providers (OpenAI o-series / gpt-5 / gpt-6, Claude Opus 4.7+ and the
 * Claude 5 family) reject any temperature other than the default (1). Prefer
 * that when the model is unknown so create/run does not 400 against the
 * backend's 0.2 default.
 */
export function modelRequiresFixedTemperature(
  providerModel?: string | null,
): boolean {
  if (!providerModel?.trim()) return true
  const id = providerModel.trim().toLowerCase()
  if (/(^|\/)o[1-9]([.\-]|$)/.test(id)) return true
  if (/(^|\/)gpt-[56]/.test(id)) return true
  if (/claude-opus-4[.-][78]/.test(id)) return true
  if (/claude-(opus|sonnet|haiku|fable|mythos)-5/.test(id)) return true
  return false
}

export function resolveAssistantModelTemp(
  providerModel?: string | null,
): number {
  return modelRequiresFixedTemperature(providerModel)
    ? ASSISTANT_FIXED_MODEL_TEMP
    : ASSISTANT_CHAT_MODEL_TEMP
}
