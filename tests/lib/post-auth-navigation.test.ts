import { describe, it, expect } from 'vitest'
import {
  resolvePostAuthRedirect,
  toRedirectNavigateOptions,
} from '@/lib/post-auth-navigation'

describe('resolvePostAuthRedirect', () => {
  it('returns a /join invite redirect (accepting an invite requires auth)', () => {
    const redirect = '/join?membershipId=m&userId=u&secret=s&teamId=t'
    expect(resolvePostAuthRedirect(redirect)).toBe(redirect)
  })

  it('rejects auth pages to avoid redirect loops', () => {
    expect(resolvePostAuthRedirect('/sign-in')).toBeUndefined()
    expect(resolvePostAuthRedirect('/sign-up?redirect=%2F')).toBeUndefined()
    expect(resolvePostAuthRedirect('/mfa')).toBeUndefined()
  })

  it('rejects non-relative and root redirects', () => {
    expect(resolvePostAuthRedirect('https://evil.example.com')).toBeUndefined()
    expect(resolvePostAuthRedirect('//evil.example.com')).toBeUndefined()
    expect(resolvePostAuthRedirect('/')).toBeUndefined()
    expect(resolvePostAuthRedirect(undefined)).toBeUndefined()
  })
})

describe('toRedirectNavigateOptions', () => {
  it('preserves query params from an OAuth2 consent redirect', () => {
    const result = toRedirectNavigateOptions(
      '/oauth2/consent?client_id=appwrite-cli&redirect_uri=https%3A%2F%2Fc.example.com%2Fcb&state=xyz',
    )
    expect(result.to).toBe('/oauth2/consent')
    expect(result.search).toEqual({
      client_id: 'appwrite-cli',
      redirect_uri: 'https://c.example.com/cb',
      state: 'xyz',
    })
  })

  it('preserves the device user_code', () => {
    expect(toRedirectNavigateOptions('/oauth2/device?user_code=ABCD1234')).toEqual(
      { to: '/oauth2/device', search: { user_code: 'ABCD1234' } },
    )
  })

  it('handles a plain path with no query string', () => {
    expect(toRedirectNavigateOptions('/organizations/abc')).toEqual({
      to: '/organizations/abc',
      search: {},
    })
  })
})
