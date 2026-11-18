import { describe, expect, test } from 'bun:test'
import { getCanonicalHostRedirectUrl } from '@/lib/seo/indexing'

describe('getCanonicalHostRedirectUrl', () => {
  test('keeps path and query on the target host', () => {
    expect(
      getCanonicalHostRedirectUrl('https://www.appwrite.io/docs/sdks?lang=php'),
    ).toBe('https://appwrite.io/docs/sdks?lang=php')
  })

  test('keeps a path that starts with two slashes on the target host', () => {
    const url = new URL(
      getCanonicalHostRedirectUrl('https://www.appwrite.io//example.com/x?y=1'),
    )

    expect(url.host).toBe('appwrite.io')
    expect(url.pathname).toBe('//example.com/x')
    expect(url.search).toBe('?y=1')
  })

  test('uses the given target host', () => {
    expect(
      getCanonicalHostRedirectUrl(
        'https://new.staging.appwrite.io//example.com',
        'staging.appwrite.io',
      ),
    ).toBe('https://staging.appwrite.io//example.com')
  })
})
