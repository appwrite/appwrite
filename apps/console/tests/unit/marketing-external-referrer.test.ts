import { describe, expect, test } from 'bun:test'
import { resolveExternalReferrer } from '@/lib/marketing/utm'

describe('resolveExternalReferrer', () => {
  test('drops appwrite.io and same-origin referrers', () => {
    expect(resolveExternalReferrer('https://appwrite.io/docs')).toBeNull()
    expect(resolveExternalReferrer('https://APPWRITE.io/')).toBeNull()
    expect(
      resolveExternalReferrer(
        'https://cloud.appwrite.io/console',
        'https://cloud.appwrite.io',
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
