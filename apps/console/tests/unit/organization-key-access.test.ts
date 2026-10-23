import { describe, expect, test } from 'bun:test'
import {
  canCreateOrgApiKey,
  canShowOrgApiKeysSettings,
} from '@/lib/console-access-checks'
import { deriveAccessFromRolesScopes } from '@/lib/console-roles'

const features = { orgRoles: true, orgApiKeys: true }

describe('organization key access', () => {
  test('developers can inspect key metadata but cannot manage keys', () => {
    const developer = deriveAccessFromRolesScopes(['developer'], [])
    expect(canShowOrgApiKeysSettings(developer, features)).toBe(true)
    expect(canCreateOrgApiKey(developer, features)).toBe(false)
  })

  test('owners retain key management', () => {
    const owner = deriveAccessFromRolesScopes(['owner'], [])
    expect(canCreateOrgApiKey(owner, features)).toBe(true)
  })

  test('profiles without organization roles retain full access', () => {
    const access = deriveAccessFromRolesScopes([], [])
    expect(canCreateOrgApiKey(access, { ...features, orgRoles: false })).toBe(
      true,
    )
  })

  test('the organization key feature must be enabled', () => {
    const owner = deriveAccessFromRolesScopes(['owner'], [])
    expect(canCreateOrgApiKey(owner, { ...features, orgApiKeys: false })).toBe(
      false,
    )
  })
})
