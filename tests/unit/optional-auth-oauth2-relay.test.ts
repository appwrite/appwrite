import { describe, expect, test } from 'bun:test'
import { isOptionalAuthPage } from '@/components/global/auth/RequireAuth'

describe('isOptionalAuthPage native OAuth2 relays', () => {
  test('lets guests reach the SDK success and failure relays', () => {
    expect(isOptionalAuthPage('/auth/oauth2/success')).toBe(true)
    expect(isOptionalAuthPage('/auth/oauth2/failure')).toBe(true)
  })

  test('matches any path under /auth/oauth2/', () => {
    expect(isOptionalAuthPage('/auth/oauth2/success/')).toBe(true)
    expect(isOptionalAuthPage('/auth/oauth2/extra')).toBe(true)
  })

  test('does not treat nearby auth paths as relays', () => {
    expect(isOptionalAuthPage('/auth/oauth2')).toBe(false)
    expect(isOptionalAuthPage('/auth/magic-url')).toBe(false)
    expect(isOptionalAuthPage('/oauth2/consent')).toBe(true)
    expect(isOptionalAuthPage('/sign-in')).toBe(false)
    expect(isOptionalAuthPage('/projects/abc')).toBe(false)
  })
})
