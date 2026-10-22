import { describe, expect, test } from 'bun:test'
import {
  redactSentryBreadcrumb,
  redactSentryEvent,
  redactSentryUrl,
} from '@/lib/sentry/redact-url'

const resetUrl = 'https://cloud.appwrite.io/reset?userId=u1&secret=s1#done'

describe('redactSentryUrl', () => {
  test('keeps only the origin and path', () => {
    expect(redactSentryUrl(resetUrl)).toBe('https://cloud.appwrite.io/reset')
    expect(redactSentryUrl('/auth/magic-url?secret=s1')).toBe('/auth/magic-url')
    expect(redactSentryUrl('/a#x?y')).toBe('/a')
    expect(redactSentryUrl('/projects/p1')).toBe('/projects/p1')
  })
})

describe('redactSentryEvent', () => {
  test('removes query strings from every URL the console attaches', () => {
    const event = redactSentryEvent({
      request: { url: resetUrl, query_string: 'userId=u1&secret=s1' },
      contexts: { navigation: { pathname: '/reset', fullUrl: resetUrl } },
      extra: { url: resetUrl },
      breadcrumbs: [
        { data: { from: '/sign-in', to: '/reset?secret=s1' } },
        { data: { url: `${resetUrl}&x=1`, method: 'GET' } },
      ],
    })

    expect(JSON.stringify(event)).not.toContain('s1')
    expect(event.request?.url).toBe('https://cloud.appwrite.io/reset')
    expect(event.contexts?.navigation?.pathname).toBe('/reset')
    expect(event.breadcrumbs?.[1]?.data?.method).toBe('GET')
  })

  test('scrubs breadcrumbs recorded before an event', () => {
    const breadcrumb = redactSentryBreadcrumb({
      data: { to: '/verify-email?secret=s1' },
    })
    expect(breadcrumb.data?.to).toBe('/verify-email')
  })
})
