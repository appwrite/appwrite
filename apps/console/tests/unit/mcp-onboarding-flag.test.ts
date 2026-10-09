import { describe, expect, test } from 'bun:test'
import {
  FEATURE_FLAGS_MENU_DEBUG_DEFAULTS,
  getDefaultDebugOverrides,
} from '@/lib/debug-overrides'

describe('MCP onboarding flag', () => {
  test('Project Agents is on by default', () => {
    expect(getDefaultDebugOverrides().showProjectAgents).toBe(true)
    expect(FEATURE_FLAGS_MENU_DEBUG_DEFAULTS.showProjectAgents).toBe(true)
  })
})
