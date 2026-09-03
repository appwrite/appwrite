import { describe, expect, test } from 'bun:test'
import { OAUTH_LOGIN_METHODS } from '@/lib/utils/auth-storage'
import {
  CONSOLE_OAUTH_PROVIDERS,
  DEFAULT_CONSOLE_OAUTH_LOGIN,
  OAUTH_LOGIN_ERROR,
} from '@/lib/utils/console-oauth'

describe('console OAuth login providers', () => {
  test('offers Google, GitHub, GitLab, and Bitbucket unconditionally', () => {
    expect(OAUTH_LOGIN_METHODS).toEqual([
      'google',
      'github',
      'gitlab',
      'bitbucket',
    ])
  })

  test('maps every login method to a provider and an error message', () => {
    for (const provider of OAUTH_LOGIN_METHODS) {
      expect(CONSOLE_OAUTH_PROVIDERS[provider]).toBeTruthy()
      expect(OAUTH_LOGIN_ERROR[provider]).toBeTruthy()
    }
  })

  test('keeps GitHub as the preselected default', () => {
    expect(DEFAULT_CONSOLE_OAUTH_LOGIN).toBe('github')
  })
})
