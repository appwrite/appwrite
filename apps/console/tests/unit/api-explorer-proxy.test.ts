import { describe, expect, test } from 'bun:test'
import {
  assertAllowedExplorerRequestUrl,
  getExplorerProxyEndpoint,
} from '@/lib/api-explorer/proxy-validation'
import { proxyApiExplorerRequestSchema } from '@/server/functions/api-explorer'

const cloud = { appwriteEndpoint: '', consoleProfile: 'cloud' }

describe('getExplorerProxyEndpoint', () => {
  test('uses the configured Appwrite endpoint', () => {
    expect(
      getExplorerProxyEndpoint({
        appwriteEndpoint: 'https://appwrite.example.com/v1',
        consoleProfile: 'self-hosted',
      }),
    ).toBe('https://appwrite.example.com/v1')
  })

  test('falls back to Appwrite Cloud on cloud consoles', () => {
    expect(getExplorerProxyEndpoint(cloud)).toBe('https://cloud.appwrite.io/v1')
  })

  test('has no proxy endpoint on same-origin self-hosted consoles', () => {
    expect(
      getExplorerProxyEndpoint({
        appwriteEndpoint: '',
        consoleProfile: 'self-hosted',
      }),
    ).toBeNull()
  })
})

describe('assertAllowedExplorerRequestUrl', () => {
  const endpoint = getExplorerProxyEndpoint(cloud) as string

  test('allows the configured endpoint and its regional hosts', () => {
    expect(() =>
      assertAllowedExplorerRequestUrl(
        'https://cloud.appwrite.io/v1/users',
        endpoint,
      ),
    ).not.toThrow()
    expect(() =>
      assertAllowedExplorerRequestUrl(
        'https://fra.cloud.appwrite.io/v1/users?limit=1',
        endpoint,
      ),
    ).not.toThrow()
  })

  test.each([
    'http://127.0.0.1/v1/users',
    'http://cloud.appwrite.io/v1/users',
    'https://cloud.appwrite.io:8443/v1/users',
    'https://cloud.appwrite.io.example.com/v1/users',
    'https://a.b.cloud.appwrite.io/v1/users',
    'https://fra-1.cloud.appwrite.io/v1/users',
    'https://cloud.appwrite.io/v2/users',
    'https://cloud.appwrite.io/v1/../internal',
    'https://user:pass@cloud.appwrite.io/v1/users',
  ])('rejects %s', (url) => {
    expect(() => assertAllowedExplorerRequestUrl(url, endpoint)).toThrow()
  })

  test('does not extend regional hosts to custom endpoints', () => {
    expect(() =>
      assertAllowedExplorerRequestUrl(
        'https://fra.appwrite.example.com/v1/users',
        'https://appwrite.example.com/v1',
      ),
    ).toThrow()
  })
})

describe('proxyApiExplorerRequestSchema', () => {
  const request = {
    url: 'https://cloud.appwrite.io/v1/users',
    method: 'GET',
    headers: { 'X-Appwrite-Project': 'project' },
  }

  test('accepts an explorer request', () => {
    expect(proxyApiExplorerRequestSchema.safeParse(request).success).toBe(true)
  })

  test('does not take the allowed endpoint from the caller', () => {
    const parsed = proxyApiExplorerRequestSchema.safeParse({
      ...request,
      allowedEndpoint: 'https://example.com',
    })
    expect(parsed.success && 'allowedEndpoint' in parsed.data).toBe(false)
  })

  test('rejects headers outside the explorer set', () => {
    expect(
      proxyApiExplorerRequestSchema.safeParse({
        ...request,
        headers: { ...request.headers, Host: 'example.com' },
      }).success,
    ).toBe(false)
  })

  test('rejects methods outside the explorer set', () => {
    expect(
      proxyApiExplorerRequestSchema.safeParse({ ...request, method: 'TRACE' })
        .success,
    ).toBe(false)
  })
})
