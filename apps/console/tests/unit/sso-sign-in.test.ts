import { describe, expect, test } from 'bun:test'
import {
  isValidWorkEmail,
  SSO_FAILURE_MESSAGES,
  ssoFailureMessage,
  ssoReturnPath,
  ssoSignInURLs,
} from '@/lib/sso-sign-in'
import {
  pageResourceId,
  refusalConcernsPage,
  requiredSSOHref,
} from '@/lib/sso-required'

const origin = 'https://console.example'

describe('ssoReturnPath', () => {
  test('keeps a Console path with its query', () => {
    expect(ssoReturnPath('/organizations/abc')).toBe('/organizations/abc')
    expect(ssoReturnPath('/projects/p1/auth?tab=users')).toBe(
      '/projects/p1/auth?tab=users',
    )
  })

  test('ignores anything that is not a path inside the Console', () => {
    for (const value of [
      '@evil.example',
      'https://evil.example',
      'javascript:alert(1)',
      '//evil.example',
      '/\\evil.example',
      '/%2F%2Fevil.example',
      'evil.example/path',
      '',
      undefined,
      42,
      { redirect: '/x' },
    ]) {
      expect(ssoReturnPath(value)).toBeUndefined()
    }
  })

  test('ignores the home page and auth pages, which pick their own place', () => {
    expect(ssoReturnPath('/')).toBeUndefined()
    expect(ssoReturnPath('/sign-in')).toBeUndefined()
    expect(ssoReturnPath('/sso?required=true')).toBeUndefined()
  })
})

describe('ssoSignInURLs', () => {
  test('returns to a valid redirect, and keeps it for a retry after a failure', () => {
    const urls = ssoSignInURLs(origin, '/organizations/abc/settings')
    expect(urls.success).toBe(`${origin}/organizations/abc/settings`)
    const failure = new URL(urls.failure)
    expect(failure.origin).toBe(origin)
    expect(failure.pathname).toBe('/sso')
    expect(failure.searchParams.get('redirect')).toBe(
      '/organizations/abc/settings',
    )
  })

  test('leaves success out without a usable redirect, so the API picks the organization', () => {
    for (const redirect of [undefined, '@evil.example', '//evil.example']) {
      const urls = ssoSignInURLs(origin, redirect)
      expect(urls.success).toBeUndefined()
      expect(urls.failure).toBe(`${origin}/sso`)
    }
  })
})

describe('isValidWorkEmail', () => {
  test('accepts an ordinary work email, with stray spaces', () => {
    expect(isValidWorkEmail('uma@umbrella.example')).toBe(true)
    expect(isValidWorkEmail('  uma@umbrella.example ')).toBe(true)
  })

  test('refuses what the server would refuse with a raw parameter message', () => {
    expect(isValidWorkEmail('uma@umbrella')).toBe(false)
    expect(isValidWorkEmail('uma')).toBe(false)
    expect(isValidWorkEmail(`${'a'.repeat(290)}@umbrella.example`)).toBe(false)
  })
})

describe('ssoFailureMessage', () => {
  const failure = (type: string, message: string) =>
    JSON.stringify({ type, message, code: 400 })

  test('says nothing without an error', () => {
    expect(ssoFailureMessage(undefined)).toBeNull()
    expect(ssoFailureMessage('')).toBeNull()
  })

  test('tells an employee to contact their administrator when the identity provider is set up wrong', () => {
    for (const code of ['invalid_client', '404 Not Found', 'server_error']) {
      expect(
        ssoFailureMessage({
          type: 'user_oauth2_provider_failure',
          message: `Single sign-on couldn't complete sign-in (${code}). Please try again.`,
        }),
      ).toBe(SSO_FAILURE_MESSAGES.misconfigured)
    }
  })

  test('names an identity provider email outside the verified domains, or no email at all', () => {
    expect(
      ssoFailureMessage(
        failure(
          'user_oauth2_provider_failure',
          "Single sign-on couldn't complete sign-in (email_domain_not_verified). Please try again.",
        ),
      ),
    ).toBe(SSO_FAILURE_MESSAGES.domainNotVerified)
    expect(
      ssoFailureMessage(
        failure(
          'user_oauth2_provider_failure',
          "Single sign-on couldn't complete sign-in (email_missing). Please try again.",
        ),
      ),
    ).toBe(SSO_FAILURE_MESSAGES.emailMissing)
  })

  test('a sign-in cancelled at the identity provider is not called a broken setup', () => {
    expect(
      ssoFailureMessage(
        failure(
          'user_oauth2_provider_failure',
          "Single sign-on couldn't complete sign-in (access_denied). Please try again.",
        ),
      ),
    ).toBe(SSO_FAILURE_MESSAGES.generic)
  })

  test('explains a sign-in started in another browser or left too long', () => {
    expect(
      ssoFailureMessage(
        failure(
          'user_oauth2_state_invalid',
          'The OAuth2 sign-in was not started from this browser, or it took too long to complete. Please start the sign-in again.',
        ),
      ),
    ).toBe(SSO_FAILURE_MESSAGES.stateInvalid)
  })

  test("keeps Cloud's own refusals as they are", () => {
    for (const message of [
      'This identity provider is not available.',
      'Single sign-on could not finish. Start again from the sign-in page.',
      'Single sign-on for your organization is not working right now. Contact your administrator.',
    ]) {
      expect(
        ssoFailureMessage(failure('user_oauth2_provider_error', message)),
      ).toBe(message)
    }
  })

  test('never shows text taken from the URL', () => {
    const hostile = [
      { message: 'Your account is locked. Call +1 555 0100 to unlock.' },
      {
        type: 'user_oauth2_provider_error',
        message: 'Your account is locked. Call +1 555 0100 to unlock.',
      },
      { type: 'sso_required', message: '<img src=x onerror=alert(1)>' },
      { message: 'email_domain_not_verified' },
      'Your account is locked. Call +1 555 0100 to unlock.',
      '{"message": 42}',
      '[1, 2]',
    ]
    for (const error of hostile) {
      expect(ssoFailureMessage(error)).toBe(SSO_FAILURE_MESSAGES.generic)
    }
    // A known message with extra words is not the known message.
    expect(
      ssoFailureMessage({
        message: 'This identity provider is not available. Call +1 555 0100.',
      }),
    ).toBe(SSO_FAILURE_MESSAGES.generic)
  })
})

describe('required single sign-on redirect', () => {
  test('returns to the given path', () => {
    const href = new URL(requiredSSOHref('/organizations/a b'), origin)
    expect(href.pathname).toBe('/sso')
    expect(href.searchParams.get('required')).toBe('true')
    expect(href.searchParams.get('redirect')).toBe('/organizations/a b')
  })

  test('reads the organization or project a page belongs to', () => {
    expect(pageResourceId('/organizations/org1/settings')).toBe('org1')
    expect(pageResourceId('/projects/p1/overview')).toBe('p1')
    expect(pageResourceId('/account')).toBeUndefined()
    expect(pageResourceId('/')).toBeUndefined()
  })

  test('applies only to the organization or project the person is on', () => {
    expect(
      refusalConcernsPage('/organizations/wayne', ['organization', 'wayne']),
    ).toBe(true)
    expect(
      refusalConcernsPage('/organizations/wayne/settings', [
        'organization',
        'plan',
        'wayne',
      ]),
    ).toBe(true)
    expect(
      refusalConcernsPage('/projects/p1', ['users', { projectId: 'p1' }]),
    ).toBe(true)
    // The switcher asks about another organization while on this one.
    expect(
      refusalConcernsPage('/organizations/personal', ['organization', 'wayne']),
    ).toBe(false)
    // The home page and sign-in probe organizations to pick a default.
    expect(refusalConcernsPage('/', ['organization', 'wayne'])).toBe(false)
    expect(refusalConcernsPage('/sign-in', ['organization', 'wayne'])).toBe(
      false,
    )
    expect(refusalConcernsPage('/account', ['organization', 'wayne'])).toBe(
      false,
    )
    // A mutation made on the organization's page.
    expect(refusalConcernsPage('/organizations/wayne')).toBe(true)
    expect(refusalConcernsPage('/account')).toBe(false)
  })
})
