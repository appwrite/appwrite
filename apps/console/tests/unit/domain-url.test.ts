import { describe, expect, test } from 'bun:test'
import { domainUrl, ownerLinkUrl, visitUrl } from '@/lib/domains/url'

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

  test('never carries a token', () => {
    expect(domainUrl('blog.appwrite.network')).toBe(
      'https://blog.appwrite.network',
    )
  })

  test('builds the owner link only when asked for it', () => {
    expect(ownerLinkUrl('blog.appwrite.network', 'a b&c')).toBe(
      'https://blog.appwrite.network/__appwrite/zero?token=a%20b%26c',
    )
    expect(ownerLinkUrl('blog.sites.localhost', 'token')).toBe(
      'http://blog.sites.localhost/__appwrite/zero?token=token',
    )
  })
})

describe('visitUrl', () => {
  const domain = 'blog.appwrite.network'

  test('opens the owner link for the owner of an unclaimed Zero project', () => {
    expect(visitUrl(domain, { status: 'active', accessToken: 'token' })).toBe(
      'https://blog.appwrite.network/__appwrite/zero?token=token',
    )
    expect(visitUrl(domain, { status: 'expired', accessToken: 'token' })).toBe(
      'https://blog.appwrite.network/__appwrite/zero?token=token',
    )
  })

  test('opens the plain domain for a regular account', () => {
    expect(visitUrl(domain, undefined)).toBe('https://blog.appwrite.network')
  })

  test('opens the plain domain once the Zero project is claimed or deleted', () => {
    expect(visitUrl(domain, { status: 'claimed', accessToken: 'token' })).toBe(
      'https://blog.appwrite.network',
    )
    expect(visitUrl(domain, { status: 'deleted', accessToken: 'token' })).toBe(
      'https://blog.appwrite.network',
    )
  })

  test('opens the plain domain while the token is not loaded yet', () => {
    expect(visitUrl(domain, { status: 'active' })).toBe(
      'https://blog.appwrite.network',
    )
  })
})
