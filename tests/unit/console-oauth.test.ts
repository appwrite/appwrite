import { describe, expect, test } from 'bun:test'
import { OAUTH_LOGIN_METHODS } from '@/lib/utils/auth-storage'
import {
  DEFAULT_CONSOLE_OAUTH_LOGIN,
  getVisibleConsoleOAuthProviders,
  isConsoleOAuthProviderEnabled,
} from '@/lib/utils/console-oauth'

describe('console OAuth login providers', () => {
  test('shows only GitHub when both flags are off', () => {
    expect(getVisibleConsoleOAuthProviders(false, false)).toEqual(['github'])
    expect(isConsoleOAuthProviderEnabled('github', false, false)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('google', false, false)).toBe(false)
    expect(isConsoleOAuthProviderEnabled('gitlab', false, false)).toBe(false)
    expect(isConsoleOAuthProviderEnabled('bitbucket', false, false)).toBe(
      false,
    )
  })

  test('extraOAuthLogin shows Google, not GitLab/Bitbucket', () => {
    expect(getVisibleConsoleOAuthProviders(true, false)).toEqual([
      'google',
      'github',
    ])
    expect(isConsoleOAuthProviderEnabled('google', true, false)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('gitlab', true, false)).toBe(false)
    expect(isConsoleOAuthProviderEnabled('bitbucket', true, false)).toBe(
      false,
    )
  })

  test('gitlabBitbucketProviders shows GitLab and Bitbucket, not Google', () => {
    expect(getVisibleConsoleOAuthProviders(false, true)).toEqual([
      'github',
      'gitlab',
      'bitbucket',
    ])
    expect(isConsoleOAuthProviderEnabled('gitlab', false, true)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('bitbucket', false, true)).toBe(true)
    expect(isConsoleOAuthProviderEnabled('google', false, true)).toBe(false)
  })

  test('shows every console OAuth method when both flags are on', () => {
    expect(getVisibleConsoleOAuthProviders(true, true)).toEqual(
      OAUTH_LOGIN_METHODS,
    )
    for (const provider of OAUTH_LOGIN_METHODS) {
      expect(isConsoleOAuthProviderEnabled(provider, true, true)).toBe(true)
    }
  })

  test('keeps GitHub as the always-on default', () => {
    expect(DEFAULT_CONSOLE_OAUTH_LOGIN).toBe('github')
  })
})
