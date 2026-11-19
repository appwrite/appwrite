import { afterEach, describe, expect, test } from 'bun:test'
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
import { isAllowedFetchSite, readBearerAuthorization } from '@/lib/smtp/relay'
import {
  birdRegion,
  birdSmtpHost,
  isBirdApiKeyId,
  sanitizeCreateBirdApiKeyBody,
} from '@/lib/smtp/bird'
import {
  proxyCreateBirdApiKey,
  proxyListBirdDomains,
  proxyListBirdSenders,
  proxyRevokeBirdApiKey,
} from '@/lib/smtp/bird-proxy'

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

  test('names the card when one provider serves two setups', () => {
    const returnUrl =
      'https://cloud.appwrite.io/projects/proj/messaging/providers/create'
    const urls = buildQuickSetupOAuthUrls(returnUrl, 'bird', 'bird-sms')

    expect(urls.success).toBe(
      `${returnUrl}?smtpSetup=connected&smtpProvider=bird&smtpTarget=bird-sms`,
    )
    expect(
      parseQuickSetupReturn({
        smtpSetup: 'connected',
        smtpProvider: 'bird',
        smtpTarget: 'bird-sms',
      }),
    ).toEqual({ status: 'connected', providerId: 'bird', target: 'bird-sms' })
    expect(
      stripQuickSetupReturn({ smtpTarget: 'bird-sms', alert: 'keep-me' }),
    ).toEqual({ alert: 'keep-me' })
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

  test('names SMS credentials after their channel', () => {
    expect(buildCredentialName('Lumen Fitness', 64, 'Appwrite SMS')).toBe(
      'Appwrite SMS: Lumen Fitness',
    )
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

  test('revokes a credential minted after the flow was released', () => {
    // The wizard closed while the key was still being created.
    const { minted, revoked } = tracked()
    minted.release()

    expect(minted.track(key1)).toBe(false)
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
  test('resolves the shipped providers and rejects unknown ids', () => {
    expect(getSmtpQuickSetupProvider('resend')?.id).toBe('resend')
    expect(getAvailableSmtpQuickSetupProvider('resend')?.id).toBe('resend')
    expect(getAvailableSmtpQuickSetupProvider('bird')?.id).toBe('bird')
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

  test('resolves relay settings for the selected domain', () => {
    const resend = getAvailableSmtpQuickSetupProvider('resend')!
    const bird = getAvailableSmtpQuickSetupProvider('bird')!
    const domain = { id: 'dom_1', name: 'mail.acme.com', verified: true }

    expect(resend.smtp.host(domain)).toBe('smtp.resend.com')
    expect(bird.smtp.host({ ...domain, region: 'eu1' })).toBe(
      'eu1.smtp.bird.com',
    )
    expect(bird.smtp.username(domain)).toBe('bird')
    expect(getSmtpQuickSetupProvider('mailgun')!.smtp.username(domain)).toBe(
      'postmaster@mail.acme.com',
    )
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

describe('Bird credentials', () => {
  test('reads the region from API keys and access tokens', () => {
    expect(birdRegion('bk_eu1_Ab3xKq9mPayload')).toBe('eu1')
    expect(birdRegion('bt_us1_Ab3xKq9mPayload')).toBe('us1')
    expect(birdSmtpHost('us1')).toBe('us1.smtp.bird.com')
  })

  test('never yields a region that could change the host', () => {
    expect(birdRegion('bk_us12_payload')).toBe('us12')
    expect(birdRegion('')).toBeNull()
    expect(birdRegion('bk_eu_payload')).toBeNull()
    expect(birdRegion('bk_123_payload')).toBeNull()
    expect(birdRegion('bk_e1u_payload')).toBeNull()
    expect(birdRegion('bk_eu1')).toBeNull()
    expect(birdRegion('re_eu1_payload')).toBeNull()
    expect(birdRegion('bk_EU1_payload')).toBeNull()
    expect(birdRegion('bk_evil.com/x_payload')).toBeNull()
    expect(birdRegion('bt_eu1@evil.com_payload')).toBeNull()
  })

  test('accepts only Bird API key ids', () => {
    expect(isBirdApiKeyId('key_01hqz8kp3v9x2m4n5p6q7r8s9t')).toBe(true)
    expect(isBirdApiKeyId('key_01HQZ8KP3V9X2M4N5P6Q7R8S9T')).toBe(false)
    expect(isBirdApiKeyId('key_../../v1/workspace')).toBe(false)
    expect(isBirdApiKeyId('key_01hqz8kp3v9x2m4n5p6q7r8s9')).toBe(false)
    expect(isBirdApiKeyId('key_01hqz8kp3v9x2m4n5p6q7r8sil')).toBe(false)
    expect(isBirdApiKeyId('dom_01hqz8kp3v9x2m4n5p6q7r8s9t')).toBe(false)
  })
})

describe('Bird proxy guard rails', () => {
  const originalFetch = globalThis.fetch
  afterEach(() => {
    globalThis.fetch = originalFetch
  })

  /** Records what the relay sends upstream instead of reaching Bird. */
  function captureUpstream(): { url?: string; init?: RequestInit } {
    const sent: { url?: string; init?: RequestInit } = {}
    globalThis.fetch = (async (url: URL, init?: RequestInit) => {
      sent.url = String(url)
      sent.init = init
      return Response.json({ data: [] })
    }) as unknown as typeof fetch
    return sent
  }

  test('mints SMS keys with only the SMS write scope', () => {
    expect(
      sanitizeCreateBirdApiKeyBody({
        name: 'Appwrite SMS: demo',
        channel: 'sms',
      }),
    ).toEqual({
      name: 'Appwrite SMS: demo',
      scopes: [{ scope: 'sms', level: 'write' }],
    })
    expect(
      sanitizeCreateBirdApiKeyBody({ name: 'k', channel: 'api_keys' }),
    ).toBeNull()
  })

  test('lists SMS senders from the region the token names', async () => {
    const sent = captureUpstream()
    await proxyListBirdSenders(
      new Request('https://console.test/bird/senders?limit=100', {
        headers: { Authorization: 'Bearer bt_eu1_token' },
      }),
    )

    expect(sent.url).toBe(
      'https://eu1.platform.bird.com/v1/sms/senders?limit=100',
    )
  })

  test('rebuilds the create-key payload as email-only', () => {
    expect(
      sanitizeCreateBirdApiKeyBody({
        name: ' Appwrite SMTP: demo ',
        scopes: [{ scope: 'api_keys', level: 'write' }],
        cidrs: ['0.0.0.0/0'],
      }),
    ).toEqual({
      name: 'Appwrite SMTP: demo',
      scopes: [{ scope: 'emails', level: 'write' }],
    })
    expect(sanitizeCreateBirdApiKeyBody(null)).toBeNull()
    expect(sanitizeCreateBirdApiKeyBody({ name: 'x'.repeat(65) })).toBeNull()
  })

  test("calls the region named by the token's prefix", async () => {
    const sent = captureUpstream()
    const response = await proxyListBirdDomains(
      new Request(
        'https://console.test/bird/domains?limit=100&starting_after=dom_1&order=asc',
        { headers: { Authorization: 'Bearer bt_eu1_token' } },
      ),
    )

    expect(response.status).toBe(200)
    expect(sent.url).toBe(
      'https://eu1.platform.bird.com/v1/email/domains?limit=100&starting_after=dom_1',
    )
  })

  test('refuses a token without a region before calling Bird', async () => {
    const sent = captureUpstream()
    const response = await proxyListBirdDomains(
      new Request('https://console.test/bird/domains', {
        headers: { Authorization: 'Bearer bt_evil.com/x_token' },
      }),
    )

    expect(response.status).toBe(401)
    expect(sent.url).toBeUndefined()
  })

  test('mints only email keys, whatever the caller sends', async () => {
    const sent = captureUpstream()
    await proxyCreateBirdApiKey(
      new Request('https://console.test/bird/api-keys', {
        method: 'POST',
        headers: { Authorization: 'Bearer bt_us1_token' },
        body: JSON.stringify({
          name: 'Appwrite SMTP: demo',
          scopes: [{ scope: 'api_keys', level: 'write' }],
        }),
      }),
    )

    expect(sent.url).toBe('https://us1.platform.bird.com/v1/api-keys')
    expect(JSON.parse(String(sent.init?.body))).toEqual({
      name: 'Appwrite SMTP: demo',
      scopes: [{ scope: 'emails', level: 'write' }],
    })
  })

  test('refuses an oversized body without reading all of it', async () => {
    const sent = captureUpstream()
    let pulled = 0
    const endless = new ReadableStream<Uint8Array>({
      pull(controller) {
        pulled++
        controller.enqueue(new Uint8Array(4096).fill(32))
      },
    })
    const response = await proxyCreateBirdApiKey(
      new Request('https://console.test/bird/api-keys', {
        method: 'POST',
        headers: { Authorization: 'Bearer bt_us1_token' },
        body: endless,
        duplex: 'half',
      } as RequestInit),
    )

    expect(response.status).toBe(413)
    expect(pulled).toBeLessThan(5)
    expect(sent.url).toBeUndefined()
  })

  test('refuses before reading the body', async () => {
    const sent = captureUpstream()
    const request = new Request('https://console.test/bird/api-keys', {
      method: 'POST',
      headers: { 'Sec-Fetch-Site': 'cross-site' },
      body: JSON.stringify({ name: 'k' }),
    })

    expect((await proxyCreateBirdApiKey(request)).status).toBe(403)
    expect(request.bodyUsed).toBe(false)
    expect(sent.url).toBeUndefined()
  })

  test('revokes through the revoke endpoint and rejects foreign ids', async () => {
    const sent = captureUpstream()
    const request = () =>
      new Request('https://console.test/bird/api-keys/x', {
        method: 'DELETE',
        headers: { Authorization: 'Bearer bt_us1_token' },
      })

    expect(
      (await proxyRevokeBirdApiKey(request(), 'key_../workspace')).status,
    ).toBe(400)
    expect(sent.url).toBeUndefined()

    await proxyRevokeBirdApiKey(request(), 'key_01hqz8kp3v9x2m4n5p6q7r8s9t')
    expect(sent.url).toBe(
      'https://us1.platform.bird.com/v1/api-keys/key_01hqz8kp3v9x2m4n5p6q7r8s9t/revoke',
    )
    expect(sent.init?.method).toBe('POST')
  })
})
