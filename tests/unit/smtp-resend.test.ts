import { describe, expect, test } from 'bun:test'
import {
  RESEND_API_KEY_NAME_MAX_LENGTH,
  RESEND_OAUTH_SCOPES,
  RESEND_PROVIDER_ID,
  buildResendApiKeyName,
  buildResendOAuthUrls,
  defaultResendSenderEmail,
  emailBelongsToDomain,
  isProviderTokenExpired,
  parseOAuthErrorMessage,
  parseResendReturnSearch,
  pickDefaultResendDomain,
  sanitizeCreateApiKeyBody,
  sortResendDomains,
  stripResendReturnSearch,
} from '@/lib/smtp/resend'
import {
  isAllowedFetchSite,
  readBearerAuthorization,
} from '@/lib/smtp/resend-proxy'

describe('Resend SMTP quick setup constants', () => {
  test('uses the console OAuth2 provider id and asks for full access', () => {
    expect(RESEND_PROVIDER_ID).toBe('resend')
    expect(RESEND_OAUTH_SCOPES).toEqual(['full_access'])
  })
})

describe('isProviderTokenExpired', () => {
  const now = Date.parse('2026-09-08T12:00:00.000Z')

  test('treats missing or malformed expiry as expired', () => {
    expect(isProviderTokenExpired(undefined, now)).toBe(true)
    expect(isProviderTokenExpired('', now)).toBe(true)
    expect(isProviderTokenExpired('not-a-date', now)).toBe(true)
  })

  test('applies the safety skew before the real expiry', () => {
    expect(isProviderTokenExpired('2026-09-08T12:10:00.000Z', now)).toBe(false)
    expect(isProviderTokenExpired('2026-09-08T12:00:30.000Z', now)).toBe(true)
    expect(isProviderTokenExpired('2026-09-08T11:59:00.000Z', now)).toBe(true)
  })
})

describe('OAuth2 round trip search params', () => {
  test('parses a successful return with the one-time credentials', () => {
    expect(
      parseResendReturnSearch({
        resend: 'connected',
        userId: 'user_1',
        secret: 'jwt.secret.value',
      }),
    ).toEqual({
      status: 'connected',
      userId: 'user_1',
      secret: 'jwt.secret.value',
    })
  })

  test('accepts numeric ids the router JSON-parsed', () => {
    expect(
      parseResendReturnSearch({
        resend: 'connected',
        userId: 123456,
        secret: 'abc',
      }),
    ).toEqual({ status: 'connected', userId: '123456', secret: 'abc' })
  })

  test('downgrades a connected return without credentials to a failure', () => {
    expect(parseResendReturnSearch({ resend: 'connected' })).toEqual({
      status: 'failed',
    })
  })

  test('reads the Appwrite error JSON on failure', () => {
    const error = JSON.stringify({
      message: 'Provider disabled',
      type: 'project_provider_disabled',
      code: 412,
    })
    expect(parseResendReturnSearch({ resend: 'failed', error })).toEqual({
      status: 'failed',
      message: 'Provider disabled',
    })
    expect(parseOAuthErrorMessage('plain text')).toBe('plain text')
    expect(parseOAuthErrorMessage(undefined)).toBeUndefined()
  })

  test('ignores unrelated search state', () => {
    expect(parseResendReturnSearch({ page: 2 })).toBeNull()
    expect(parseResendReturnSearch(undefined)).toBeNull()
  })

  test('strips only the round-trip params', () => {
    expect(
      stripResendReturnSearch({
        resend: 'connected',
        userId: 'u',
        secret: 's',
        error: 'e',
        alert: 'keep-me',
      }),
    ).toEqual({ alert: 'keep-me' })
    expect(stripResendReturnSearch(null)).toEqual({})
  })

  test('builds success and failure URLs on the SMTP tab', () => {
    expect(
      buildResendOAuthUrls('https://cloud.appwrite.io', 'my project'),
    ).toEqual({
      success:
        'https://cloud.appwrite.io/projects/my%20project/settings/smtp?resend=connected',
      failure:
        'https://cloud.appwrite.io/projects/my%20project/settings/smtp?resend=failed',
    })
  })
})

describe('Resend domains and sender defaults', () => {
  const domains = [
    { id: 'c', name: 'zeta.dev', status: 'pending' },
    { id: 'b', name: 'beta.dev', status: 'verified' },
    { id: 'a', name: 'alpha.dev', status: 'verified' },
  ]

  test('sorts verified domains first, then alphabetically', () => {
    expect(sortResendDomains(domains).map((domain) => domain.id)).toEqual([
      'a',
      'b',
      'c',
    ])
  })

  test('prefers the domain the project already sends from', () => {
    expect(pickDefaultResendDomain(domains, 'hello@Beta.dev')?.id).toBe('b')
    expect(pickDefaultResendDomain(domains, 'hello@zeta.dev')?.id).toBe('b')
    expect(pickDefaultResendDomain(domains, undefined)?.id).toBe('b')
    expect(
      pickDefaultResendDomain(
        [{ id: 'x', name: 'x.dev', status: 'failed' }],
        undefined,
      ),
    ).toBeUndefined()
  })

  test('matches emails against a domain case-insensitively', () => {
    expect(emailBelongsToDomain('noreply@Example.com', 'example.com')).toBe(
      true,
    )
    expect(emailBelongsToDomain('noreply@sub.example.com', 'example.com')).toBe(
      false,
    )
    expect(emailBelongsToDomain('@example.com', 'example.com')).toBe(false)
    expect(emailBelongsToDomain('noreply@', 'example.com')).toBe(false)
    expect(defaultResendSenderEmail('example.com')).toBe('noreply@example.com')
  })

  test('keeps API key names within the Resend limit', () => {
    expect(buildResendApiKeyName('My App')).toBe('Appwrite SMTP: My App')
    expect(buildResendApiKeyName('   ')).toBe('Appwrite SMTP')
    const long = buildResendApiKeyName('x'.repeat(200))
    expect(long.length).toBeLessThanOrEqual(RESEND_API_KEY_NAME_MAX_LENGTH)
    expect(long.startsWith('Appwrite SMTP: ')).toBe(true)
  })
})

describe('Resend proxy guard rails', () => {
  test('rebuilds the create-key payload as sending-only', () => {
    expect(
      sanitizeCreateApiKeyBody({
        name: ' Appwrite SMTP: demo ',
        permission: 'full_access',
        domain_id: 'dom_1',
        extra: true,
      }),
    ).toEqual({
      name: 'Appwrite SMTP: demo',
      permission: 'sending_access',
      domain_id: 'dom_1',
    })
    expect(sanitizeCreateApiKeyBody({ name: 'k', domainId: 'dom_2' })).toEqual({
      name: 'k',
      permission: 'sending_access',
      domain_id: 'dom_2',
    })
  })

  test('rejects missing or oversized names', () => {
    expect(sanitizeCreateApiKeyBody(null)).toBeNull()
    expect(sanitizeCreateApiKeyBody({})).toBeNull()
    expect(sanitizeCreateApiKeyBody({ name: 'x'.repeat(51) })).toBeNull()
  })

  test('requires a bearer token', () => {
    const withToken = new Request('https://console.test/resend/domains', {
      headers: { Authorization: 'Bearer abc.def' },
    })
    expect(readBearerAuthorization(withToken)).toBe('Bearer abc.def')
    expect(
      readBearerAuthorization(
        new Request('https://console.test/resend/domains'),
      ),
    ).toBeNull()
    expect(
      readBearerAuthorization(
        new Request('https://console.test/resend/domains', {
          headers: { Authorization: 'Basic abc' },
        }),
      ),
    ).toBeNull()
  })

  test('only serves same-origin browser requests', () => {
    const sameOrigin = new Request('https://console.test/resend/domains', {
      headers: { 'Sec-Fetch-Site': 'same-origin' },
    })
    const crossSite = new Request('https://console.test/resend/domains', {
      headers: { 'Sec-Fetch-Site': 'cross-site' },
    })
    expect(isAllowedFetchSite(sameOrigin)).toBe(true)
    expect(isAllowedFetchSite(crossSite)).toBe(false)
    expect(
      isAllowedFetchSite(new Request('https://console.test/resend/domains')),
    ).toBe(true)
  })
})
