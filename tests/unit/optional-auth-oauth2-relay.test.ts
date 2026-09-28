import { describe, expect, test } from 'bun:test'
import {
  shouldRedirectGuestToSignIn,
  shouldRedirectToConsoleMfa,
} from '@/components/global/auth/RequireAuth'

describe('native OAuth2 relay auth redirects', () => {
  test('keeps the success and failure relays on the page for guests', () => {
    expect(shouldRedirectGuestToSignIn('/auth/oauth2/success')).toBe(false)
    expect(shouldRedirectGuestToSignIn('/auth/oauth2/failure')).toBe(false)
  })

  test('keeps the success and failure relays on the page when MFA is required', () => {
    expect(shouldRedirectToConsoleMfa('/auth/oauth2/success')).toBe(false)
    expect(shouldRedirectToConsoleMfa('/auth/oauth2/failure')).toBe(false)
  })

  test('still sends protected console routes to sign-in or MFA', () => {
    expect(shouldRedirectGuestToSignIn('/projects/abc')).toBe(true)
    expect(shouldRedirectToConsoleMfa('/projects/abc')).toBe(true)
  })

  test('still sends the sign-in page to MFA when a challenge is required', () => {
    expect(shouldRedirectGuestToSignIn('/sign-in')).toBe(false)
    expect(shouldRedirectToConsoleMfa('/sign-in')).toBe(true)
  })
})
