import { describe, expect, test } from 'bun:test'
import {
  toResourceUrl,
  withAdminMode,
} from '@/lib/appwrite/admin-resource-url'

describe('toResourceUrl', () => {
  test('passes strings through', () => {
    expect(toResourceUrl('https://example.com/file')).toBe(
      'https://example.com/file',
    )
  })

  test('stringifies URL instances', () => {
    expect(toResourceUrl(new URL('https://example.com/file?x=1'))).toBe(
      'https://example.com/file?x=1',
    )
  })

  test('reads href from URL-like objects', () => {
    expect(toResourceUrl({ href: 'https://example.com/file' })).toBe(
      'https://example.com/file',
    )
  })

  test('returns empty for nullish values', () => {
    expect(toResourceUrl(null)).toBe('')
    expect(toResourceUrl(undefined)).toBe('')
  })
})

describe('withAdminMode', () => {
  test('appends mode=admin with ? when there is no query', () => {
    expect(withAdminMode('https://example.com/file')).toBe(
      'https://example.com/file?mode=admin',
    )
  })

  test('appends mode=admin with & when a query already exists', () => {
    expect(withAdminMode('https://example.com/file?project=1')).toBe(
      'https://example.com/file?project=1&mode=admin',
    )
  })

  test('does not call includes on a URL object', () => {
    expect(withAdminMode(new URL('https://example.com/file?x=1'))).toBe(
      'https://example.com/file?x=1&mode=admin',
    )
  })
})
