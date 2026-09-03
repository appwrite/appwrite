import { describe, expect, test } from 'bun:test'
import { OAUTH_LOGIN_METHODS } from '@/lib/utils/auth-storage'
import {
  DEFAULT_CONSOLE_OAUTH_LOGIN,
  getVisibleConsoleOAuthProviders,
  isConsoleOAuthProviderEnabled,
} from '@/lib/utils/console-oauth'

describe('console OAuth login providers', () => {
  test('shows Google, GitHub, GitLab, and Bitbucket regardless of the flag', () => {
    expect(getVisibleConsoleOAuthProviders(false)).toEqual([
      'google',
      'github',
      'gitlab',
      'bitbucket',
    ])
    for (const provider of ['google', 'github', 'gitlab', 'bitbucket'] as const) {
      expect(isConsoleOAuthProviderEnabled(provider, false)).toBe(true)
      expect(isConsoleOAuthProviderEnabled(provider, true)).toBe(true)
    }
  })

  test('gates Cursor behind extra OAuth login', () => {
    expect(isConsoleOAuthProviderEnabled('cursor', false)).toBe(false)
    expect(isConsoleOAuthProviderEnabled('cursor', true)).toBe(true)
    expect(getVisibleConsoleOAuthProviders(false)).not.toContain('cursor')
  })

  test('shows every console OAuth method when extra OAuth login is on', () => {
    expect(getVisibleConsoleOAuthProviders(true)).toEqual(OAUTH_LOGIN_METHODS)
  })

  test('keeps GitHub as the preselected default', () => {
    expect(DEFAULT_CONSOLE_OAUTH_LOGIN).toBe('github')
  })
})
