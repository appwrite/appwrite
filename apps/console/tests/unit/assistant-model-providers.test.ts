import { describe, expect, test } from 'bun:test'
import {
  ASSISTANT_CHAT_MODEL_TEMP,
  ASSISTANT_FIXED_MODEL_TEMP,
  ASSISTANT_MODEL_PROVIDERS,
  modelRequiresFixedTemperature,
  resolveAssistantModelTemp,
} from '@/lib/assistant/model-providers'

const RETIRED_MODELS = [
  'claude-3-5-sonnet-latest',
  'claude-3-5-haiku-latest',
  'claude-opus-4-1',
  'gemini-1.5-pro',
  'gemini-2.0-flash',
]

describe('assistant model providers', () => {
  test('offers no retired models', () => {
    const offered = ASSISTANT_MODEL_PROVIDERS.flatMap((provider) =>
      provider.models.map((model) => model.id),
    )
    for (const retired of RETIRED_MODELS) {
      expect(offered).not.toContain(retired)
    }
  })

  test.each([
    'gpt-6.1-terra',
    'openai/gpt-6.1-sol',
    'gpt-5-nano',
    'o4-mini',
    'claude-opus-4-7',
    'claude-opus-4-8',
    'claude-opus-5-5',
    'claude-sonnet-5-5',
    'anthropic/claude-sonnet-5.5',
    'claude-haiku-5-5',
    'claude-fable-5-1',
    '',
  ])('uses the fixed temperature for %p', (model) => {
    expect(modelRequiresFixedTemperature(model)).toBe(true)
    expect(resolveAssistantModelTemp(model)).toBe(ASSISTANT_FIXED_MODEL_TEMP)
  })

  test.each(['gpt-4o', 'claude-sonnet-4-5', 'gemini-3.7-flash'])(
    'uses the chat temperature for %p',
    (model) => {
      expect(modelRequiresFixedTemperature(model)).toBe(false)
      expect(resolveAssistantModelTemp(model)).toBe(ASSISTANT_CHAT_MODEL_TEMP)
    },
  )
})
