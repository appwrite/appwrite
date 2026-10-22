import { describe, expect, test } from 'bun:test'
import {
  getCanonicalHostRedirectResponse,
  getCanonicalHostRedirectUrl,
} from '@/lib/seo/indexing'

describe('getCanonicalHostRedirectUrl', () => {
  test('keeps the path and query on the target host', () => {
    expect(
      getCanonicalHostRedirectUrl('https://www.appwrite.io/docs?x=1'),
    ).toBe('https://appwrite.io/docs?x=1')
  })

  test.each([
    'https://www.appwrite.io//example.com/a?x=1',
    'https://www.appwrite.io/\\example.com/a',
    'https://www.appwrite.io///example.com',
  ])('stays on the target host for %s', (url) => {
    const target = new URL(getCanonicalHostRedirectUrl(url))
    expect(target.origin).toBe('https://appwrite.io')
  })
})

describe('getCanonicalHostRedirectResponse', () => {
  test('redirects alias hosts to their target host only', () => {
    const response = getCanonicalHostRedirectResponse(
      new Request('https://new.appwrite.io//example.com/a'),
    )
    expect(response?.status).toBe(301)
    expect(new URL(response?.headers.get('location') ?? '').origin).toBe(
      'https://appwrite.io',
    )
  })
})
