import { describe, expect, test } from 'bun:test'
import { OAuthProvider } from '@appwrite.io/console'
import { existsSync } from 'node:fs'
import { join } from 'node:path'
import {
  QUICK_SETUP_CALLBACK_PATH,
  buildCredentialName,
  buildQuickSetupOAuthUrls,
  buildQuickSetupReturnPath,
  defaultSenderEmail,
  emailBelongsToDomain,
  isExpectedQuickSetupClaim,
  isProviderTokenExpired,
  parseOAuthErrorMessage,
  parseQuickSetupReturn,
  pickDefaultQuickSetupDomain,
  sortQuickSetupDomains,
  stripQuickSetupReturn,
} from '@/lib/smtp/quick-setup'
import {
  SMTP_QUICK_SETUP_PROVIDERS,
  getAvailableSmtpQuickSetupProvider,
  getSmtpQuickSetupProvider,
  isProviderAvailable,
} from '@/lib/smtp/providers'
import {
  RESEND_OAUTH_SCOPES,
  sanitizeCreateApiKeyBody,
} from '@/lib/smtp/resend'
import {
  isAllowedFetchSite,
  readBearerAuthorization,
} from '@/lib/smtp/resend-proxy'
import { ANALYTICS_ACTIONS } from '@/lib/analytics-actions'

const REPO_ROOT = join(import.meta.dir, '../..')

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
  test('sends the provider to the callback route, not the settings tab', () => {
    const urls = buildQuickSetupOAuthUrls(
      'https://cloud.appwrite.io',
      'my project',
      'resend',
    )
    // Appwrite deletes the current session when the flow starts, so the
    // landing route must tolerate guests and restore it.
    expect(urls.success).toBe(
      `https://cloud.appwrite.io${QUICK_SETUP_CALLBACK_PATH}?smtpSetup=connected&smtpProvider=resend&projectId=my%20project`,
    )
    expect(urls.failure).toBe(
      `https://cloud.appwrite.io${QUICK_SETUP_CALLBACK_PATH}?smtpSetup=failed&smtpProvider=resend&projectId=my%20project`,
    )
    expect(urls.success).not.toContain('/settings/smtp')
  })

  test('returns the user to the SMTP tab with the outcome', () => {
    expect(
      buildQuickSetupReturnPath({
        projectId: 'my project',
        providerId: 'resend',
        status: 'connected',
      }),
    ).toBe(
      '/projects/my%20project/settings/smtp?smtpSetup=connected&smtpProvider=resend',
    )

    const failed = buildQuickSetupReturnPath({
      projectId: 'proj',
      providerId: 'resend',
      status: 'failed',
      error: JSON.stringify({ message: 'Scope not allowed' }),
    })
    expect(
      parseQuickSetupReturn(
        Object.fromEntries(
          new URL(`https://console.test${failed}`).searchParams,
        ),
      ),
    ).toEqual({
      status: 'failed',
      providerId: 'resend',
      message: 'Scope not allowed',
    })
  })

  test('parses the outcome on the settings tab', () => {
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

  test('strips only the round-trip params', () => {
    expect(
      stripQuickSetupReturn({
        smtpSetup: 'connected',
        smtpProvider: 'resend',
        error: 'e',
        alert: 'keep-me',
      }),
    ).toEqual({ alert: 'keep-me' })
    expect(stripQuickSetupReturn(null)).toEqual({})
  })

  test('claims only callbacks this browser started for this account', () => {
    const pending = {
      providerId: 'resend',
      projectId: 'proj',
      accountId: 'user_1',
    }
    const claim = {
      providerId: 'resend',
      projectId: 'proj',
      userId: 'user_1',
    }
    expect(isExpectedQuickSetupClaim(pending, claim)).toBe(true)
    // No pending record: a crafted callback URL must not create a session.
    expect(isExpectedQuickSetupClaim(null, claim)).toBe(false)
    expect(
      isExpectedQuickSetupClaim(pending, { ...claim, userId: 'attacker' }),
    ).toBe(false)
    expect(
      isExpectedQuickSetupClaim(pending, { ...claim, projectId: 'other' }),
    ).toBe(false)
    expect(
      isExpectedQuickSetupClaim(pending, { ...claim, providerId: 'mailgun' }),
    ).toBe(false)
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

describe('provider registry', () => {
  test('ids are unique and resolvable', () => {
    const ids = SMTP_QUICK_SETUP_PROVIDERS.map((provider) => provider.id)
    expect(new Set(ids).size).toBe(ids.length)
    for (const id of ids) {
      expect(getSmtpQuickSetupProvider(id)?.id).toBe(id)
    }
    expect(getSmtpQuickSetupProvider('nope')).toBeUndefined()
    expect(getSmtpQuickSetupProvider(undefined)).toBeUndefined()
  })

  test('every entry ships an icon asset and an analytics action', () => {
    for (const provider of SMTP_QUICK_SETUP_PROVIDERS) {
      expect(existsSync(join(REPO_ROOT, 'public', provider.iconPath))).toBe(
        true,
      )
      expect(ANALYTICS_ACTIONS[provider.analyticsAction]).toBeTruthy()
    }
  })

  test('brand copy is whole sentences per provider, never fragments', () => {
    for (const provider of SMTP_QUICK_SETUP_PROVIDERS) {
      expect(provider.connectLabel).toBe(`Connect with ${provider.name}`)
      expect(provider.setupTitle).toBe(`Set up SMTP with ${provider.name}`)
      expect(provider.disconnectTitle).toBe(`Disconnect ${provider.name}`)
    }
  })

  test('only providers with OAuth and an API adapter are runnable', () => {
    for (const provider of SMTP_QUICK_SETUP_PROVIDERS) {
      expect(isProviderAvailable(provider)).toBe(
        Boolean(provider.oauth && provider.api),
      )
    }
    expect(getAvailableSmtpQuickSetupProvider('resend')?.id).toBe('resend')
    // Coming soon until Appwrite ships console OAuth2 providers for them.
    expect(getAvailableSmtpQuickSetupProvider('mailgun')).toBeUndefined()
    expect(getAvailableSmtpQuickSetupProvider('sendgrid')).toBeUndefined()
  })

  test('Resend uses its documented SMTP relay settings and full access', () => {
    const resend = getAvailableSmtpQuickSetupProvider('resend')!
    expect(resend.smtp.host).toBe('smtp.resend.com')
    expect(resend.smtp.port).toBe(587)
    expect(resend.smtp.secure).toBe('tls')
    expect(resend.smtp.username('acme.dev')).toBe('resend')
    expect(resend.oauth.scopes).toEqual(RESEND_OAUTH_SCOPES)
    expect(resend.oauth.provider).toBe(OAuthProvider.Resend)
  })

  test('usernames resolve per selected domain where the provider needs it', () => {
    const mailgun = getSmtpQuickSetupProvider('mailgun')!
    expect(mailgun.smtp.username('acme.dev')).toBe('postmaster@acme.dev')
    expect(
      getSmtpQuickSetupProvider('sendgrid')!.smtp.username('acme.dev'),
    ).toBe('apikey')
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
