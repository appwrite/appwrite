import { afterEach, describe, expect, test } from 'bun:test'
import { resolveCoverImageHref } from '@/lib/cover-generator/brand-background'
import {
  fetchCoverRemoteImage,
  isAllowedCoverRemoteImageUrl,
} from '@/lib/cover-generator/remote-image'

const realFetch = globalThis.fetch

afterEach(() => {
  globalThis.fetch = realFetch
})

function serve(response: Response, requests: RequestInit[] = []): void {
  globalThis.fetch = (async (_input: unknown, init?: RequestInit) => {
    requests.push(init ?? {})
    return response
  }) as typeof fetch
}

describe('isAllowedCoverRemoteImageUrl', () => {
  test.each([
    'https://appwrite.io/images/logo.png',
    'https://cloud.appwrite.io/v1/storage/buckets/b/files/f/view',
    'https://raw.githubusercontent.com/appwrite/appwrite/main/logo.png',
  ])('allows %s', (url) => {
    expect(isAllowedCoverRemoteImageUrl(url)).toBe(true)
  })

  test.each([
    'http://appwrite.io/logo.png',
    'https://appwrite.io:8443/logo.png',
    'https://user:pass@appwrite.io/logo.png',
    'https://appwrite.io.example.com/logo.png',
    'https://notappwrite.io/logo.png',
    'https://127.0.0.1/logo.png',
    'https://example.com/logo.png',
    'file:///etc/hosts',
  ])('rejects %s', (url) => {
    expect(isAllowedCoverRemoteImageUrl(url)).toBe(false)
  })
})

describe('resolveCoverImageHref', () => {
  test('keeps allowed remote images', async () => {
    expect(await resolveCoverImageHref('https://appwrite.io/logo.png')).toBe(
      'https://appwrite.io/logo.png',
    )
  })

  test('drops remote images on other hosts', async () => {
    expect(await resolveCoverImageHref('http://127.0.0.1/logo.png')).toBeNull()
    expect(
      await resolveCoverImageHref('https://example.com/logo.png'),
    ).toBeNull()
  })
})

describe('fetchCoverRemoteImage', () => {
  test('returns the image body', async () => {
    serve(new Response(new Uint8Array([1, 2, 3])))
    const buffer = await fetchCoverRemoteImage('https://appwrite.io/logo.png')
    expect(buffer?.length).toBe(3)
  })

  test('does not follow redirects', async () => {
    const requests: RequestInit[] = []
    serve(
      new Response(null, {
        status: 302,
        headers: { location: 'https://example.com/logo.png' },
      }),
      requests,
    )
    expect(
      await fetchCoverRemoteImage('https://appwrite.io/logo.png'),
    ).toBeNull()
    expect(requests[0]?.redirect).toBe('manual')
  })

  test('rejects bodies over the size cap', async () => {
    serve(new Response(new Uint8Array(10 * 1024 * 1024 + 1)))
    expect(
      await fetchCoverRemoteImage('https://appwrite.io/logo.png'),
    ).toBeNull()
  })

  test('does not fetch hosts outside the allowlist', async () => {
    const requests: RequestInit[] = []
    serve(new Response(new Uint8Array([1])), requests)
    expect(await fetchCoverRemoteImage('https://example.com/a.png')).toBeNull()
    expect(requests).toHaveLength(0)
  })
})
