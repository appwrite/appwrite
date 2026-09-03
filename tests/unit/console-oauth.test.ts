import { describe, expect, test } from 'bun:test'
import { OAUTH_LOGIN_METHODS } from '@/lib/utils/auth-storage'
import {
  DEFAULT_CONSOLE_OAUTH_LOGIN,
  getVisibleConsoleOAuthProviders,
  isConsoleOAuthProviderEnabled,
} from '@/lib/utils/console-oauth'

describe('console OAuth login providers', () => {
  test('shows only GitHub when extra OAuth login is off', () => {
    expect(getVisibleConsoleOAuthProviders(false)).toEqual(['github'])
    expect(isConsoleOAuthProviderEnabled('github', false)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('google', false)).toBe(false)
    expect(isConsoleOAuthProviderEnabled('gitlab', false)).toBe(false)
    expect(isConsoleOAuthProviderEnabled('bitbucket', false)).toBe(false)
  })

  test('shows every console OAuth method when extra OAuth login is on', () => {
    expect(getVisibleConsoleOAuthProviders(true)).toEqual(OAUTH_LOGIN_METHODS)
    for (const provider of OAUTH_LOGIN_METHODS) {
      expect(isConsoleOAuthProviderEnabled(provider, true)).toBe(true)
    }
  })

  test('keeps GitHub as the always-on default', () => {
    expect(DEFAULT_CONSOLE_OAUTH_LOGIN).toBe('github')
  })
})
