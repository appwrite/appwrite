import { describe, expect, test } from 'bun:test'
import { isValidRelativeRedirect } from '@/lib/relative-redirect'

describe('isValidRelativeRedirect', () => {
  test('accepts console paths with search and hash', () => {
    expect(isValidRelativeRedirect('/account')).toBe(true)
    expect(
      isValidRelativeRedirect('/projects/abc/databases?page=2#indexes'),
    ).toBe(true)
    // A URL inside a query value is the destination's business, not an origin.
    expect(
      isValidRelativeRedirect('/auth/magic-url?next=https://example.com'),
    ).toBe(true)
  })

  test('rejects absolute and protocol-relative URLs', () => {
    expect(isValidRelativeRedirect('https://evil.example')).toBe(false)
    expect(isValidRelativeRedirect('//evil.example')).toBe(false)
    expect(isValidRelativeRedirect('account')).toBe(false)
  })

  // The URL parser treats a backslash as a slash and strips ASCII tab, LF and
  // CR before parsing, so these resolve to another origin in the browser.
  test('rejects paths browsers resolve to another origin', () => {
    expect(isValidRelativeRedirect('/\\evil.example/x')).toBe(false)
    expect(isValidRelativeRedirect('/\t/evil.example')).toBe(false)
    expect(isValidRelativeRedirect('/\n/evil.example')).toBe(false)
    expect(isValidRelativeRedirect('/\r/evil.example')).toBe(false)
    expect(isValidRelativeRedirect('/%09/evil.example')).toBe(false)
  })
})
