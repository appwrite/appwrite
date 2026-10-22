import { describe, expect, test } from 'bun:test'
import { resolveExternalReferrer } from '@/lib/marketing/utm'

describe('resolveExternalReferrer', () => {
  test('drops appwrite.io, its subdomains and same-origin referrers', () => {
    expect(resolveExternalReferrer('https://appwrite.io/docs')).toBeNull()
    expect(resolveExternalReferrer('https://APPWRITE.io/')).toBeNull()
    expect(resolveExternalReferrer('https://www.appwrite.io/')).toBeNull()
    expect(
      resolveExternalReferrer('https://fra.cloud.appwrite.io/console'),
    ).toBeNull()
    expect(
      resolveExternalReferrer(
        'http://localhost:3000/console',
        'http://localhost:3000',
      ),
    ).toBeNull()
  })

  test('keeps external referrers that only mention appwrite.io', () => {
    expect(
      resolveExternalReferrer('https://example.com/?next=//appwrite.io'),
    ).toBe('https://example.com/?next=//appwrite.io')
    expect(resolveExternalReferrer('https://appwrite.io.example.com/')).toBe(
      'https://appwrite.io.example.com/',
    )
    expect(resolveExternalReferrer('https://news.ycombinator.com/')).toBe(
      'https://news.ycombinator.com/',
    )
  })

  test('keeps a referrer that is not a URL and ignores blanks', () => {
    expect(resolveExternalReferrer('not a url')).toBe('not a url')
    expect(resolveExternalReferrer('  ')).toBeNull()
  })
})
