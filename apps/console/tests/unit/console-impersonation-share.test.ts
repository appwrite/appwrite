import { describe, expect, test } from 'bun:test'
import {
  ACCOUNT_PATH_AFTER_IMPERSONATION,
  buildConsoleImpersonationShareUrl,
  resolveConsoleImpersonationRedirect,
} from '@/lib/console-impersonation'

const origin = 'https://cloud.appwrite.io'

describe('resolveConsoleImpersonationRedirect', () => {
  test('keeps console paths including search and hash', () => {
    expect(
      resolveConsoleImpersonationRedirect(
        '/projects/abc/databases?page=2#indexes',
      ),
    ).toBe('/projects/abc/databases?page=2#indexes')
  })

  test('rejects open redirects and auth pages', () => {
    expect(resolveConsoleImpersonationRedirect('https://evil.example')).toBe(
      ACCOUNT_PATH_AFTER_IMPERSONATION,
    )
    expect(resolveConsoleImpersonationRedirect('//evil.example')).toBe(
      ACCOUNT_PATH_AFTER_IMPERSONATION,
    )
    expect(resolveConsoleImpersonationRedirect('/sign-in')).toBe(
      ACCOUNT_PATH_AFTER_IMPERSONATION,
    )
    expect(resolveConsoleImpersonationRedirect('/impersonate')).toBe(
      ACCOUNT_PATH_AFTER_IMPERSONATION,
    )
    expect(
      resolveConsoleImpersonationRedirect('/impersonate/user123?email=a'),
    ).toBe(ACCOUNT_PATH_AFTER_IMPERSONATION)
  })

  test('rejects paths the URL parser turns into another host', () => {
    // Backslashes parse as slashes; tab, newline and CR are stripped first,
    // so each of these resolves to https://evil.example/ in a browser.
    for (const redirect of [
      '/\\evil.example',
      '/\\/evil.example',
      '/\t/evil.example',
      '/\n/evil.example',
      '/\r/evil.example',
    ]) {
      expect(new URL(redirect, origin).origin).toBe('https://evil.example')
      expect(resolveConsoleImpersonationRedirect(redirect)).toBe(
        ACCOUNT_PATH_AFTER_IMPERSONATION,
      )
    }
    expect(resolveConsoleImpersonationRedirect('/projects/abc\u0000')).toBe(
      ACCOUNT_PATH_AFTER_IMPERSONATION,
    )
  })
})

describe('buildConsoleImpersonationShareUrl', () => {
  test('includes the impersonated email and current console path', () => {
    const url = buildConsoleImpersonationShareUrl('ticket.opener@example.com', {
      origin,
      pathname: '/projects/abc/auth',
      search: '?page=2',
      hash: '',
    })
    expect(url).toBeDefined()
    const parsed = new URL(url!)
    expect(parsed.pathname).toBe('/impersonate')
    expect(parsed.searchParams.get('email')).toBe('ticket.opener@example.com')
    expect(parsed.searchParams.get('redirect')).toBe(
      '/projects/abc/auth?page=2',
    )
  })

  test('falls back to /account when the current page is the confirm route', () => {
    const url = buildConsoleImpersonationShareUrl('ops@example.com', {
      origin,
      pathname: '/impersonate',
      search: '?email=ops@example.com',
      hash: '',
    })
    expect(url).toBeDefined()
    const parsed = new URL(url!)
    expect(parsed.searchParams.get('redirect')).toBe(
      ACCOUNT_PATH_AFTER_IMPERSONATION,
    )
  })

  test('returns undefined without an email', () => {
    expect(
      buildConsoleImpersonationShareUrl('  ', {
        origin,
        pathname: '/account',
        search: '',
        hash: '',
      }),
    ).toBeUndefined()
  })
})
