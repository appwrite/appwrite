import { afterEach, describe, expect, mock, test } from 'bun:test'

let resolvedAddresses: string[] = []

mock.module('node:dns/promises', () => ({
  lookup: async () =>
    resolvedAddresses.map((address) => ({
      address,
      family: address.includes(':') ? 6 : 4,
    })),
}))

const { loadCoverImageBuffer, resolveCoverImageHref } = await import(
  '@/lib/cover-generator/brand-background'
)
const { isBlockedCoverImageAddress, resolveCoverRemoteImageUrl } = await import(
  '@/lib/cover-generator/remote-image'
)

const originalFetch = globalThis.fetch

type FetchCall = {
  url: string
  redirect?: RequestRedirect
  signal?: AbortSignal | null
  host?: string
  servername?: string
}

type StubRequestInit = RequestInit & { tls?: { servername?: string } }

function stubFetch(
  handler: (url: string, init?: StubRequestInit) => Response,
): FetchCall[] {
  const calls: FetchCall[] = []
  globalThis.fetch = (async (
    input: RequestInfo | URL,
    init?: StubRequestInit,
  ) => {
    calls.push({
      url: String(input),
      redirect: init?.redirect,
      signal: init?.signal,
      host: new Headers(init?.headers).get('host') ?? undefined,
      servername: init?.tls?.servername,
    })
    return handler(String(input), init)
  }) as typeof globalThis.fetch
  return calls
}

function pngResponse(): Response {
  return new Response(new Uint8Array([137, 80, 78, 71]), {
    headers: { 'Content-Type': 'image/png' },
  })
}

afterEach(() => {
  globalThis.fetch = originalFetch
  resolvedAddresses = []
})

describe('resolveCoverRemoteImageUrl', () => {
  test('accepts public https image urls', () => {
    expect(
      resolveCoverRemoteImageUrl('https://example.com/logo.png')?.href,
    ).toBe('https://example.com/logo.png')
  })

  test('rejects non-https schemes, credentials and private hosts', () => {
    expect(resolveCoverRemoteImageUrl('http://example.com/logo.png')).toBeNull()
    expect(resolveCoverRemoteImageUrl('file:///etc/passwd')).toBeNull()
    expect(
      resolveCoverRemoteImageUrl('https://user:pass@example.com/a.png'),
    ).toBeNull()
    expect(resolveCoverRemoteImageUrl('https://localhost/a.png')).toBeNull()
    expect(resolveCoverRemoteImageUrl('https://127.0.0.1/a.png')).toBeNull()
    expect(
      resolveCoverRemoteImageUrl('https://169.254.169.254/latest/meta-data'),
    ).toBeNull()
    expect(resolveCoverRemoteImageUrl('https://10.1.2.3/a.png')).toBeNull()
    expect(resolveCoverRemoteImageUrl('https://[::1]/a.png')).toBeNull()
    expect(
      resolveCoverRemoteImageUrl('https://metadata.google.internal/a.png'),
    ).toBeNull()
  })
})

describe('isBlockedCoverImageAddress', () => {
  test('blocks loopback, private, link-local and shared ranges', () => {
    for (const address of [
      '0.0.0.0',
      '127.0.0.1',
      '10.0.0.5',
      '172.16.0.1',
      '172.31.255.255',
      '192.168.1.1',
      '169.254.169.254',
      '100.64.0.1',
      '192.0.0.8',
      '192.0.2.1',
      '198.51.100.1',
      '203.0.113.1',
      '224.0.0.1',
      '255.255.255.255',
      '::',
      '::1',
      'fe80::1',
      'fd00::1',
      'ff02::1',
      '::ffff:127.0.0.1',
      '::ffff:7f00:1',
      '64:ff9b::7f00:1',
    ]) {
      expect(isBlockedCoverImageAddress(address)).toBe(true)
    }
  })

  test('allows public addresses', () => {
    for (const address of [
      '93.184.216.34',
      '8.8.8.8',
      '172.32.0.1',
      '2606:4700::1111',
    ]) {
      expect(isBlockedCoverImageAddress(address)).toBe(false)
    }
  })
})

describe('loadCoverImageBuffer', () => {
  test('never requests internal targets named by the caller', async () => {
    const calls = stubFetch(() => pngResponse())

    for (const source of [
      'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
      'https://127.0.0.1:9200/_cat/indices',
      'http://localhost:3000/internal',
      'https://10.0.0.5/admin',
      'https://[::1]/admin',
    ]) {
      expect(await loadCoverImageBuffer(source)).toBeNull()
    }

    expect(calls).toHaveLength(0)
  })

  test('fetches allowed images with a deadline and manual redirects', async () => {
    const calls = stubFetch(() => pngResponse())

    const buffer = await loadCoverImageBuffer('https://93.184.216.34/logo.png')

    expect(buffer).not.toBeNull()
    expect(calls).toHaveLength(1)
    expect(calls[0].redirect).toBe('manual')
    expect(calls[0].signal).toBeInstanceOf(AbortSignal)
  })

  test('follows redirects that stay on public hosts', async () => {
    const calls = stubFetch((url) =>
      url.endsWith('/logo.png')
        ? new Response(null, {
            status: 302,
            headers: { Location: 'https://8.8.8.8/moved.png' },
          })
        : pngResponse(),
    )

    expect(
      await loadCoverImageBuffer('https://93.184.216.34/logo.png'),
    ).not.toBeNull()
    expect(calls.map((call) => call.url)).toEqual([
      'https://93.184.216.34/logo.png',
      'https://8.8.8.8/moved.png',
    ])
  })

  test('refuses redirects that leave the public internet', async () => {
    const calls = stubFetch((url) =>
      url.includes('93.184.216.34')
        ? new Response(null, {
            status: 302,
            headers: { Location: 'http://169.254.169.254/latest/meta-data/' },
          })
        : pngResponse(),
    )

    expect(
      await loadCoverImageBuffer('https://93.184.216.34/logo.png'),
    ).toBeNull()
    expect(calls).toHaveLength(1)
  })

  test('rejects non-image responses instead of letting them reach the rasterizer', async () => {
    stubFetch(
      () =>
        new Response('{"ok":true}', {
          headers: { 'Content-Type': 'application/json' },
        }),
    )

    expect(
      await loadCoverImageBuffer('https://93.184.216.34/logo.png'),
    ).toBeNull()
  })

  test('rejects responses larger than the cover image cap', async () => {
    stubFetch(
      () =>
        new Response(new Uint8Array(8), {
          headers: {
            'Content-Type': 'image/png',
            'Content-Length': String(64 * 1024 * 1024),
          },
        }),
    )

    expect(
      await loadCoverImageBuffer('https://93.184.216.34/logo.png'),
    ).toBeNull()
  })

  test('refuses a hostname that resolves to a private address', async () => {
    resolvedAddresses = ['93.184.216.34', '10.0.0.5']
    const calls = stubFetch(() => pngResponse())

    expect(
      await loadCoverImageBuffer('https://images.example.com/a.png'),
    ).toBeNull()
    expect(calls).toHaveLength(0)
  })

  test('requests the checked address, keeping the original authority', async () => {
    resolvedAddresses = ['93.184.216.34']
    const calls = stubFetch(() => pngResponse())

    expect(
      await loadCoverImageBuffer('https://images.example.com/a.png'),
    ).not.toBeNull()
    expect(calls).toHaveLength(1)
    expect(calls[0].url).toBe('https://93.184.216.34/a.png')
    expect(calls[0].host).toBe('images.example.com')
    expect(calls[0].servername).toBe('images.example.com')
  })

  test('still decodes data uris without any request', async () => {
    const calls = stubFetch(() => pngResponse())
    const buffer = await loadCoverImageBuffer(
      'data:image/png;base64,iVBORw0KGgo=',
    )

    expect(buffer).not.toBeNull()
    expect(calls).toHaveLength(0)
  })
})

describe('resolveCoverImageHref', () => {
  test('drops hrefs pointing at internal hosts', async () => {
    expect(
      await resolveCoverImageHref('http://169.254.169.254/x.png'),
    ).toBeNull()
    expect(await resolveCoverImageHref('https://127.0.0.1/x.png')).toBeNull()
    expect(await resolveCoverImageHref('https://example.com/x.png')).toBe(
      'https://example.com/x.png',
    )
  })
})
