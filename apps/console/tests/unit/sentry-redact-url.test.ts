import { describe, expect, test } from 'bun:test'
import type { Breadcrumb, ErrorEvent } from '@sentry/tanstackstart-react'
import {
  redactSentryBreadcrumb,
  redactSentryEvent,
  redactUrl,
} from '@/lib/sentry/redact-url'

const recoveryUrl =
  'https://cloud.appwrite.io/console/reset?userId=user-1&secret=recovery-secret&expire=2026'

describe('redactUrl', () => {
  test('redacts credentials in the query string', () => {
    const redacted = redactUrl(recoveryUrl)

    expect(redacted).not.toContain('recovery-secret')
    expect(redacted).not.toContain('user-1')
    expect(redacted).toBe(
      'https://cloud.appwrite.io/console/reset?userId=redacted&secret=redacted&expire=2026',
    )
  })

  test('redacts relative URLs and keeps the hash', () => {
    expect(redactUrl('/auth/magic-url?secret=magic-secret#top')).toBe(
      '/auth/magic-url?secret=redacted#top',
    )
  })

  test('leaves URLs without credentials unchanged', () => {
    const url = '/console/project-1/databases?page=2&search=a%20b'
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

    expect(JSON.stringify(event)).not.toContain('recovery-secret')
    expect(JSON.stringify(event)).not.toContain('user-1')
  })
})

describe('redactSentryBreadcrumb', () => {
  test('redacts navigation and request URLs', () => {
    const breadcrumb = redactSentryBreadcrumb({
      category: 'navigation',
      data: {
        from: '/verify-email?userId=user-1&secret=verify-secret',
        to: recoveryUrl,
        url: '/v1/account/recovery?secret=recovery-secret',
      },
    } as Breadcrumb)

    const serialized = JSON.stringify(breadcrumb)
    expect(serialized).not.toContain('verify-secret')
    expect(serialized).not.toContain('recovery-secret')
    expect(serialized).not.toContain('user-1')
  })
})
