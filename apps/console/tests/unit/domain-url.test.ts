import { describe, expect, test } from 'bun:test'
import { domainUrl } from '@/lib/domains/url'

describe('domainUrl', () => {
  test('uses http for local site domains', () => {
    expect(domainUrl('blog-template.sites.localhost')).toBe(
      'http://blog-template.sites.localhost',
    )
    expect(domainUrl('my-function.functions.localhost')).toBe(
      'http://my-function.functions.localhost',
    )
    expect(domainUrl('localhost')).toBe('http://localhost')
    expect(domainUrl('localhost:8080')).toBe('http://localhost:8080')
    expect(domainUrl('127.0.0.1')).toBe('http://127.0.0.1')
  })

  test('uses https for public domains', () => {
    expect(domainUrl('blog-template.appwrite.network')).toBe(
      'https://blog-template.appwrite.network',
    )
    expect(domainUrl('example.com')).toBe('https://example.com')
    expect(domainUrl('localhost.example.com')).toBe(
      'https://localhost.example.com',
    )
  })

  test('opens the owner link when given an access token', () => {
    expect(domainUrl('blog.appwrite.network', 'a b&c')).toBe(
      'https://blog.appwrite.network/__appwrite/zero?token=a%20b%26c',
    )
    expect(domainUrl('blog.sites.localhost', 'token')).toBe(
      'http://blog.sites.localhost/__appwrite/zero?token=token',
    )
    expect(domainUrl('example.com', '')).toBe('https://example.com')
  })
})
