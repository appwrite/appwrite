import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import {
  canShowOrgOAuthAppsSettings,
  getFirstAllowedOrgSettingsPath,
} from '@/lib/console-access-checks'
import { deriveAccessFromRolesScopes } from '@/lib/console-roles'

const DEBUG_PROFILE_KEY = 'debug:consoleProfile'
const SETTINGS_PATH = '/organizations/team/settings'

function access(role: string) {
  return deriveAccessFromRolesScopes([role], [])
}

describe('organization OAuth apps settings', () => {
  const globals = globalThis as { window?: unknown; localStorage?: unknown }
  let storage: Map<string, string>

  beforeEach(() => {
    storage = new Map<string, string>()
    const localStorage = {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
    }
    globals.localStorage = localStorage
    globals.window = {
      localStorage,
      location: new URL('https://cloud.appwrite.io/console'),
      dispatchEvent: () => true,
    }
  })

  afterEach(() => {
    delete globals.window
    delete globals.localStorage
  })

  function useProfile(id: string, features: Record<string, boolean> = {}) {
    storage.set(DEBUG_PROFILE_KEY, JSON.stringify({ id, features }))
  }

  test('shows to owners and developers on Cloud', () => {
    useProfile('cloud')
    expect(canShowOrgOAuthAppsSettings(access('owner'))).toBe(true)
    expect(canShowOrgOAuthAppsSettings(access('developer'))).toBe(true)
  })

  test('hides from other roles on Cloud even without organization roles', () => {
    useProfile('cloud', { orgRoles: false })
    for (const role of ['billing', 'editor', 'analyst']) {
      expect(canShowOrgOAuthAppsSettings(access(role))).toBe(false)
    }
    expect(
      getFirstAllowedOrgSettingsPath(
        access('analyst'),
        { orgRoles: false },
        SETTINGS_PATH,
      ),
    ).toBe(SETTINGS_PATH)
  })

  test('hides outside Cloud', () => {
    useProfile('self-hosted')
    expect(canShowOrgOAuthAppsSettings(access('owner'))).toBe(false)
  })
})
