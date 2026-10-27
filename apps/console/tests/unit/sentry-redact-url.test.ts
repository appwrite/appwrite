import { describe, expect, test } from 'bun:test'
import type { Breadcrumb, ErrorEvent } from '@sentry/tanstackstart-react'
import {
  redactSentryBreadcrumb,
  redactSentryEvent,
  redactUrl,
} from '@/lib/sentry/redact-url'

const recoveryUrl =
  'https://cloud.appwrite.io/console/reset?userId=user-1&secret=recovery-secret&expire=2026'

function expectNoSecrets(value: unknown) {
  const serialized = JSON.stringify(value)
  for (const secret of [
    'recovery-secret',
    'verify-secret',
    'magic-secret',
    'user-1',
    'standard_key',
  ]) {
    expect(serialized).not.toContain(secret)
  }
}

describe('redactUrl', () => {
  test('redacts every query value and keeps the parameter names', () => {
    expect(redactUrl(recoveryUrl)).toBe(
      'https://cloud.appwrite.io/console/reset?userId=redacted&secret=redacted&expire=redacted',
    )
  })

  test('redacts parameters whatever their case or name', () => {
    expect(
      redactUrl(
        '/verify-email?USERID=user-1&Secret=verify-secret&apiKey=standard_key',
      ),
    ).toBe('/verify-email?USERID=redacted&Secret=redacted&apiKey=redacted')
  })

  test('redacts relative URLs, parameters in the hash, and URLs inside text', () => {
    expect(redactUrl('/auth/magic-url?secret=magic-secret#top')).toBe(
      '/auth/magic-url?secret=redacted#top',
    )
    expect(redactUrl('/auth/oauth2/success#token=magic-secret&state=a')).toBe(
      '/auth/oauth2/success#token=redacted&state=redacted',
    )
    expect(redactUrl(`Failed to load ${recoveryUrl} (500)`)).toBe(
      'Failed to load https://cloud.appwrite.io/console/reset?userId=redacted&secret=redacted&expire=redacted (500)',
    )
  })

  test('leaves URLs without a query unchanged', () => {
    const url = '/console/project-1/databases#indexes'
    expect(redactUrl(url)).toBe(url)
  })
})

describe('redactSentryEvent', () => {
  test('redacts the request URL, query string and referer', () => {
    const event = redactSentryEvent({
      type: undefined,
      request: {
        url: recoveryUrl,
        query_string: 'userId=user-1&secret=recovery-secret',
        headers: { Referer: recoveryUrl },
      },
    } as ErrorEvent)

    expectNoSecrets(event)
    expect(event.request?.query_string).toBe('userId=redacted&secret=redacted')
  })

  test('redacts a query string given as pairs', () => {
    const event = redactSentryEvent({
      type: undefined,
      request: { query_string: [['secret', 'recovery-secret']] },
    } as ErrorEvent)

    expectNoSecrets(event)
  })

  test('redacts URLs in extras, contexts, tags, messages and exceptions', () => {
    const event = redactSentryEvent({
      type: undefined,
      message: `Request to ${recoveryUrl} failed`,
      exception: {
        values: [{ type: 'Error', value: `Could not open ${recoveryUrl}` }],
      },
      extra: {
        url: recoveryUrl,
        pathname: '/console/reset',
        request: { href: '/auth/magic-url?Secret=magic-secret' },
        history: ['/verify-email?userId=user-1&secret=verify-secret'],
      },
      contexts: {
        navigation: { fullUrl: recoveryUrl, pathname: '/console/reset' },
      },
      tags: { url: recoveryUrl },
      breadcrumbs: [{ category: 'navigation', data: { to: recoveryUrl } }],
    } as unknown as ErrorEvent)

    expectNoSecrets(event)
    expect(event.extra?.url).toBe(
      'https://cloud.appwrite.io/console/reset?userId=redacted&secret=redacted&expire=redacted',
    )
    expect(event.extra?.pathname).toBe('/console/reset')
  })

  test('handles circular extras', () => {
    const extra: Record<string, unknown> = { url: recoveryUrl }
    extra.self = extra

    const event = redactSentryEvent({ type: undefined, extra } as ErrorEvent)

    expectNoSecrets(event.extra?.url)
    expect(event.extra?.self).toBe('[Circular]')
  })
})

describe('redactSentryBreadcrumb', () => {
  test('redacts URLs in the message and data', () => {
    const breadcrumb = redactSentryBreadcrumb({
      category: 'navigation',
      message: `Navigated to ${recoveryUrl}`,
      data: {
        from: '/verify-email?userId=user-1&secret=verify-secret',
        to: recoveryUrl,
        url: '/v1/account/recovery?SECRET=recovery-secret',
        nested: { arguments: ['/auth/magic-url?secret=magic-secret'] },
      },
    } as Breadcrumb)

    expectNoSecrets(breadcrumb)
  })
})
