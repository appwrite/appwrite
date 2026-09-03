import { describe, expect, test } from 'bun:test'
import { OAUTH_LOGIN_METHODS } from '@/lib/utils/auth-storage'
import {
  DEFAULT_CONSOLE_OAUTH_LOGIN,
  getVisibleConsoleOAuthProviders,
  isConsoleOAuthProviderEnabled,
} from '@/lib/utils/console-oauth'

describe('console OAuth login providers', () => {
  test('shows GitHub, GitLab, and Bitbucket when extra OAuth login is off', () => {
    expect(getVisibleConsoleOAuthProviders(false)).toEqual([
      'github',
      'gitlab',
      'bitbucket',
    ])
    expect(isConsoleOAuthProviderEnabled('github', false)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('gitlab', false)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('bitbucket', false)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('google', false)).toBe(false)
  })

  test('shows every console OAuth method when extra OAuth login is on', () => {
    expect(getVisibleConsoleOAuthProviders(true)).toEqual(OAUTH_LOGIN_METHODS)
    for (const provider of OAUTH_LOGIN_METHODS) {
      expect(isConsoleOAuthProviderEnabled(provider, true)).toBe(true)
    }
  })

  test('keeps GitHub as the preselected default', () => {
    expect(DEFAULT_CONSOLE_OAUTH_LOGIN).toBe('github')
  })
})
