import { describe, expect, test } from 'bun:test'
import { domainFieldError } from '@/lib/utils/proxy-domains'

describe('domainFieldError', () => {
  test('accepts a plain domain and stays quiet on an untouched empty field', () => {
    expect(domainFieldError('example.com', true)).toBeNull()
    expect(domainFieldError('Sub.Example.co.uk', true)).toBeNull()
    expect(domainFieldError('', false)).toBeNull()
    expect(domainFieldError('', true)).toBe('Required')
  })

  test('points a pasted email at the part after @', () => {
    expect(domainFieldError('owner@example.com', true)).toBe(
      'Enter only the part after @: example.com',
    )
    expect(domainFieldError('owner@', true)).toBe(
      'Enter only the domain, the part after @.',
    )
  })

  test('explains the DNS length limits instead of leaving them to the server', () => {
    const limits =
      'A domain can be at most 253 characters, and each part between dots at most 63.'
    expect(domainFieldError(`${'a'.repeat(64)}.example.com`, true)).toBe(limits)
    expect(domainFieldError(`${'a'.repeat(63)}.example.com`, true)).toBeNull()
    const long = `${Array.from({ length: 5 }, () => 'a'.repeat(60)).join('.')}.com`
    expect(long.length).toBeGreaterThan(253)
    expect(domainFieldError(long, true)).toBe(limits)
  })

  test('keeps the hints for a URL, spaces, a wildcard and a trailing dot', () => {
    expect(domainFieldError('https://example.com/path', true)).toBe(
      'Enter the hostname only: example.com',
    )
    expect(domainFieldError('exa mple.com', true)).toBe('Remove the spaces.')
    expect(domainFieldError('*.example.com', true)).toBe(
      'Wildcard domains are not supported. Enter a specific hostname.',
    )
    expect(domainFieldError('example.com.', true)).toBe(
      'Remove the trailing dot.',
    )
    expect(domainFieldError('example', true)).toBe(
      'Please enter a valid domain',
    )
  })
})
