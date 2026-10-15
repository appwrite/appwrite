import { describe, expect, test } from 'bun:test'
import {
  buildCredentialName,
  buildQuickSetupOAuthUrls,
  createMintedCredentialTracker,
  defaultSenderEmail,
  emailBelongsToDomain,
  isProviderTokenExpired,
  parseOAuthErrorMessage,
  parseQuickSetupReturn,
  pickDefaultQuickSetupDomain,
  providerInterestFeatureId,
  sortQuickSetupDomains,
  stripQuickSetupReturn,
} from '@/lib/smtp/quick-setup'
import {
  getAvailableSmtpQuickSetupProvider,
  getSmtpQuickSetupProvider,
  isProviderAvailable,
} from '@/lib/smtp/providers'
import { sanitizeCreateApiKeyBody } from '@/lib/smtp/resend'
import {
  isAllowedFetchSite,
  readBearerAuthorization,
} from '@/lib/smtp/resend-proxy'

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

describe('OAuth2 round trip', () => {
  test('returns the provider to the page that started the flow', () => {
    // The token flow keeps the console session, so the provider comes back to
    // the page itself instead of an intermediate route that restores it.
    const returnUrl =
      'https://cloud.appwrite.io/projects/proj/messaging/providers/create'
    const urls = buildQuickSetupOAuthUrls(returnUrl, 'resend')

    expect(urls.success).toBe(
      `${returnUrl}?smtpSetup=connected&smtpProvider=resend`,
    )
    expect(urls.failure).toBe(
      `${returnUrl}?smtpSetup=failed&smtpProvider=resend`,
    )
  })

  test('appends the outcome to a return URL that already has a query', () => {
    const urls = buildQuickSetupOAuthUrls(
      'https://cloud.appwrite.io/projects/proj/settings/smtp?tab=custom',
      'resend',
    )
    expect(urls.success).toBe(
      'https://cloud.appwrite.io/projects/proj/settings/smtp?tab=custom&smtpSetup=connected&smtpProvider=resend',
    )
  })

  test('parses the outcome on return', () => {
    expect(
      parseQuickSetupReturn({ smtpSetup: 'connected', smtpProvider: 'resend' }),
    ).toEqual({ status: 'connected', providerId: 'resend' })

    const error = JSON.stringify({
      message: 'Provider disabled',
      type: 'project_provider_disabled',
      code: 412,
    })
    expect(
      parseQuickSetupReturn({
        smtpSetup: 'failed',
        smtpProvider: 'resend',
        error,
      }),
    ).toEqual({
      status: 'failed',
      providerId: 'resend',
      message: 'Provider disabled',
    })
    expect(parseOAuthErrorMessage('plain text')).toBe('plain text')
    expect(parseOAuthErrorMessage(undefined)).toBeUndefined()
  })

  test('ignores unrelated search state and returns without a provider', () => {
    expect(parseQuickSetupReturn({ page: 2 })).toBeNull()
    expect(parseQuickSetupReturn({ smtpSetup: 'connected' })).toBeNull()
    expect(parseQuickSetupReturn(undefined)).toBeNull()
  })

  test('strips the round-trip params, including the unused login token', () => {
    expect(
      stripQuickSetupReturn({
        smtpSetup: 'connected',
        smtpProvider: 'resend',
        error: 'e',
        // Appended by Appwrite's token flow; never used and never left in the URL.
        userId: 'user_1',
        secret: 'jwt.token.value',
        alert: 'keep-me',
      }),
    ).toEqual({ alert: 'keep-me' })
    expect(stripQuickSetupReturn(null)).toEqual({})
  })
})

describe('sending domains and sender defaults', () => {
  const domains = [
    { id: 'c', name: 'zeta.dev', verified: false },
    { id: 'b', name: 'beta.dev', verified: true },
    { id: 'a', name: 'alpha.dev', verified: true },
  ]

  test('sorts verified domains first, then alphabetically', () => {
    expect(sortQuickSetupDomains(domains).map((domain) => domain.id)).toEqual([
      'a',
      'b',
      'c',
    ])
  })

  test('prefers the domain the project already sends from', () => {
    expect(pickDefaultQuickSetupDomain(domains, 'hello@Beta.dev')?.id).toBe('b')
    expect(pickDefaultQuickSetupDomain(domains, 'hello@zeta.dev')?.id).toBe('b')
    expect(pickDefaultQuickSetupDomain(domains, undefined)?.id).toBe('b')
    expect(
      pickDefaultQuickSetupDomain(
        [{ id: 'x', name: 'x.dev', verified: false }],
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
    expect(defaultSenderEmail('example.com')).toBe('noreply@example.com')
  })

  test('keeps credential names within the provider limit', () => {
    expect(buildCredentialName('My App', 50)).toBe('Appwrite SMTP: My App')
    expect(buildCredentialName('   ', 50)).toBe('Appwrite SMTP')
    const long = buildCredentialName('x'.repeat(200), 50)
    expect(long.length).toBeLessThanOrEqual(50)
    expect(long.startsWith('Appwrite SMTP: ')).toBe(true)
  })
})

describe('minted credentials', () => {
  type Credential = { id: string; secret: string }
  const key1 = { id: 'key_1', secret: 's1' }
  const key2 = { id: 'key_2', secret: 's2' }

  function tracked() {
    const revoked: string[] = []
    const minted = createMintedCredentialTracker<Credential>(
      async (credentialId) => {
        revoked.push(credentialId)
      },
    )
    return { minted, revoked }
  }

  /** Lets a settled save run its handlers. */
  const settled = () => new Promise((resolve) => setTimeout(resolve, 0))

  test('revokes a credential the flow abandons before anything stores it', () => {
    const { minted, revoked } = tracked()
    minted.track(key1)
    minted.release()
    expect(revoked).toEqual(['key_1'])
  })

  test('keeps a credential once the save that stores it succeeds', async () => {
    const { minted, revoked } = tracked()
    minted.track(key1)
    minted.settle('s1', Promise.resolve())
    await settled()
    minted.release()
    expect(revoked).toEqual([])
  })

  test('lets the save decide when the flow closes while it is in flight', async () => {
    // Closing the wizard while the provider is still being created must not
    // revoke a key that ends up stored in the provider.
    const { minted, revoked } = tracked()
    let finish!: () => void
    const save = new Promise<void>((resolve) => {
      finish = resolve
    })
    minted.track(key1)
    minted.settle('s1', save)
    minted.release()
    expect(revoked).toEqual([])
    finish()
    await settled()
    expect(revoked).toEqual([])
  })

  test('revokes a credential whose save fails after the flow closed', async () => {
    const { minted, revoked } = tracked()
    let fail!: (error: Error) => void
    const save = new Promise<void>((_, reject) => {
      fail = reject
    })
    minted.track(key1)
    minted.settle('s1', save)
    minted.release()
    fail(new Error('boom'))
    await settled()
    expect(revoked).toEqual(['key_1'])
  })

  test('hands a credential back for a retry when its save fails', async () => {
    const { minted, revoked } = tracked()
    minted.track(key1)
    minted.settle('s1', Promise.reject(new Error('boom')))
    await settled()
    expect(revoked).toEqual([])
    minted.settle('s1', Promise.resolve())
    await settled()
    minted.release()
    expect(revoked).toEqual([])
  })

  test('still revokes a minted credential the user replaced by hand', async () => {
    const { minted, revoked } = tracked()
    minted.track(key1)
    minted.settle('typed_by_the_user', Promise.resolve())
    await settled()
    minted.release()
    expect(revoked).toEqual(['key_1'])
  })

  test('minting again revokes only the previous unused credential', async () => {
    const { minted, revoked } = tracked()
    minted.track(key1)
    minted.track(key2)
    expect(revoked).toEqual(['key_1'])
    minted.settle('s2', Promise.resolve())
    await settled()
    minted.release()
    expect(revoked).toEqual(['key_1'])
  })

  test('does nothing without a credential and swallows revocation failures', async () => {
    const { minted, revoked } = tracked()
    minted.settle('s1', Promise.resolve())
    minted.release()
    await settled()
    expect(revoked).toEqual([])

    const failing = createMintedCredentialTracker<Credential>(async () => {
      throw new Error('offline')
    })
    failing.track(key1)
    expect(() => failing.release()).not.toThrow()
    // Let the rejected revocation settle; an unhandled rejection fails the run.
    await settled()
  })
})

describe('provider registry', () => {
  test('resolves the shipped provider and rejects unknown ids', () => {
    expect(getSmtpQuickSetupProvider('resend')?.id).toBe('resend')
    expect(getAvailableSmtpQuickSetupProvider('resend')?.id).toBe('resend')
    expect(getSmtpQuickSetupProvider('nope')).toBeUndefined()
    expect(getSmtpQuickSetupProvider(undefined)).toBeUndefined()
    expect(getAvailableSmtpQuickSetupProvider(null)).toBeUndefined()
  })

  test('a provider is runnable only with both OAuth and an API adapter', () => {
    const resend = getAvailableSmtpQuickSetupProvider('resend')!
    const { oauth, api, ...comingSoon } = resend
    expect(isProviderAvailable(resend)).toBe(true)
    expect(isProviderAvailable({ ...comingSoon, api })).toBe(false)
    expect(isProviderAvailable({ ...comingSoon, oauth })).toBe(false)
    expect(isProviderAvailable(comingSoon)).toBe(false)
  })

  test('interest feature ids stay distinct and safe for the comma-separated pref', () => {
    expect(providerInterestFeatureId('mailgun')).not.toBe(
      providerInterestFeatureId('sendgrid'),
    )
    expect(providerInterestFeatureId('mailgun')).not.toContain(',')
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
