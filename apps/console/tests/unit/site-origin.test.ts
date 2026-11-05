import { describe, expect, test } from 'bun:test'
import { resolveServerSiteOrigin } from '@/lib/marketing/site-origin'

describe('resolveServerSiteOrigin', () => {
  test('keeps loopback origin during development', () => {
    expect(resolveServerSiteOrigin('http://localhost:3000', false)).toBe(
      'http://localhost:3000',
    )
  })

  test('uses public origin for loopback during production prerender', () => {
    expect(resolveServerSiteOrigin('http://localhost', true)).toBe(
      'https://appwrite.io',
    )
    expect(resolveServerSiteOrigin('http://127.0.0.1', true)).toBe(
      'https://appwrite.io',
    )
  })

  test('preserves real request host in production', () => {
    expect(resolveServerSiteOrigin('https://appwrite.io', true)).toBe(
      'https://appwrite.io',
    )
    expect(resolveServerSiteOrigin('https://staging.example.com', true)).toBe(
      'https://staging.example.com',
    )
  })
})
