import { afterEach, describe, expect, test } from 'bun:test'
import {
  loadCoverImageBuffer,
  resolveCoverImageHref,
} from '@/lib/cover-generator/brand-background'
import {
  COVER_REMOTE_IMAGE_MAX_BYTES,
  fetchCoverRemoteImageBuffer,
  isPublicCoverImageAddress,
  parseCoverRemoteImageUrl,
} from '@/lib/cover-generator/remote-image'

/** 1×1 transparent PNG, the smallest payload sharp accepts as an image. */
const PNG_PIXEL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==',
  'base64',
)

const originalFetch = globalThis.fetch
const fetchCalls: string[] = []
const fetchInits: RequestInit[] = []

/** Records every outbound request so blocked targets can be asserted as never fetched. */
function stubFetch(respond: (url: string) => Response): void {
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = input instanceof Request ? input.url : String(input)
    fetchCalls.push(url)
    fetchInits.push(init ?? {})
    return respond(url)
  }) as typeof fetch
}

/** Resolver fake: every host maps to a public address unless listed otherwise. */
function lookupFake(
  addresses: Record<string, string[]> = {},
): (hostname: string) => Promise<string[]> {
  return async (hostname: string) => addresses[hostname] ?? ['93.184.216.34']
}

afterEach(() => {
  globalThis.fetch = originalFetch
  fetchCalls.length = 0
  fetchInits.length = 0
})

describe('isPublicCoverImageAddress', () => {
  test.each([
    '0.0.0.0',
    '10.0.0.5',
    '100.64.0.1',
    '127.0.0.1',
    '169.254.169.254',
    '172.16.0.1',
    '172.31.255.255',
    '192.0.0.1',
    '192.168.1.1',
    '198.18.0.1',
    '224.0.0.1',
    '255.255.255.255',
    '::',
    '::1',
    '::ffff:127.0.0.1',
    '::ffff:7f00:1',
    '64:ff9b::7f00:1',
    '2001:db8::1',
    'fc00::1',
    'fd00::1',
    'fe80::1',
    'ff02::1',
    'not-an-address',
  ])('rejects %s', (address) => {
    expect(isPublicCoverImageAddress(address)).toBe(false)
  })

  test.each([
    '1.1.1.1',
    '93.184.216.34',
    '172.32.0.1',
    '2606:2800:220:1:248:1893:25c8:1946',
  ])('allows %s', (address) => {
    expect(isPublicCoverImageAddress(address)).toBe(true)
  })
})

describe('parseCoverRemoteImageUrl', () => {
  test.each([
    'file:///etc/passwd',
    'ftp://example.com/logo.png',
    'http://127.0.0.1/logo.png',
    'http://127.0.0.1:9000/logo.png',
    'http://2130706433/logo.png',
    'http://[::1]/logo.png',
    'http://10.0.0.5/logo.png',
    'http://169.254.169.254/latest/meta-data/',
    'http://localhost/logo.png',
    'http://metadata/computeMetadata/v1/',
    'http://metadata.google.internal/computeMetadata/v1/',
    'http://appwrite.svc.cluster.local/logo.png',
    'http://user:secret@example.com/logo.png',
    'http://example.com:9200/logo.png',
    'http://example.com:443/logo.png',
    'https://example.com:80/logo.png',
  ])('rejects %s', (value) => {
    expect(parseCoverRemoteImageUrl(value)).toBeNull()
  })

  test('allows public http(s) image URLs on default ports', () => {
    expect(parseCoverRemoteImageUrl('https://example.com/logo.png')?.href).toBe(
      'https://example.com/logo.png',
    )
    expect(
      parseCoverRemoteImageUrl('http://cdn.example.com:80/logo.png')?.href,
    ).toBe('http://cdn.example.com/logo.png')
  })
})

describe('loadCoverImageBuffer', () => {
  test.each([
    'http://127.0.0.1:9000/admin',
    'http://169.254.169.254/latest/meta-data/iam/',
    'http://localhost/logo.png',
    'http://[::1]/logo.png',
    'http://10.0.0.5/logo.png',
    'http://192.168.1.1/logo.png',
    'http://metadata.google.internal/computeMetadata/v1/',
  ])('never requests %s', async (source) => {
    stubFetch(
      () =>
        new Response(PNG_PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    )

    await expect(loadCoverImageBuffer(source)).resolves.toBeNull()
    expect(fetchCalls).toEqual([])
  })
})

describe('resolveCoverImageHref', () => {
  test('drops private hosts instead of embedding them in the rendered SVG', async () => {
    await expect(
      resolveCoverImageHref('http://169.254.169.254/latest/meta-data/'),
    ).resolves.toBeNull()
  })

  test('keeps public image URLs', async () => {
    await expect(
      resolveCoverImageHref('https://example.com/logo.png'),
    ).resolves.toBe('https://example.com/logo.png')
  })

  test('rejects public asset paths that escape the bundled asset roots', async () => {
    await expect(resolveCoverImageHref('/../package.json')).resolves.toBeNull()
  })
})

describe('fetchCoverRemoteImageBuffer', () => {
  test('returns the image bytes for an allowed public host', async () => {
    stubFetch(
      () =>
        new Response(PNG_PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    )

    const buffer = await fetchCoverRemoteImageBuffer(
      'https://images.example.com/logo.png',
      { lookup: lookupFake() },
    )

    expect(buffer?.equals(PNG_PIXEL)).toBe(true)
  })

  test('pins the connection to the validated address', async () => {
    stubFetch(
      () =>
        new Response(PNG_PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    )

    await fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
      lookup: lookupFake({ 'images.example.com': ['93.184.216.34'] }),
    })

    // The connection goes to the address that was just validated rather than to
    // a second lookup of the hostname, which closes the rebinding window. TLS
    // still identifies the hostname.
    expect(fetchCalls).toEqual(['https://93.184.216.34/logo.png'])
    const init = fetchInits[0] as RequestInit & {
      headers: Record<string, string>
      tls: { serverName: string }
    }
    expect(init.headers.Host).toBe('images.example.com')
    expect(init.tls.serverName).toBe('images.example.com')
  })

  test('pins an IPv6 address in brackets', async () => {
    stubFetch(
      () =>
        new Response(PNG_PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    )

    await fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
      lookup: lookupFake({
        'images.example.com': ['2606:2800:220:1:248:1893:25c8:1946'],
      }),
    })

    expect(fetchCalls).toEqual([
      'https://[2606:2800:220:1:248:1893:25c8:1946]/logo.png',
    ])
  })

  test('does not follow redirects into the private network', async () => {
    stubFetch(
      () =>
        new Response(null, {
          status: 302,
          headers: { Location: 'http://169.254.169.254/latest/meta-data/' },
        }),
    )

    await expect(
      fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
        lookup: lookupFake(),
      }),
    ).resolves.toBeNull()
    expect(fetchCalls).toEqual(['https://93.184.216.34/logo.png'])
  })

  test('follows redirects between public hosts', async () => {
    let hop = 0
    stubFetch(() =>
      hop++ === 0
        ? new Response(null, {
            status: 302,
            headers: { Location: 'https://cdn.example.com/logo.png' },
          })
        : new Response(PNG_PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    )

    const buffer = await fetchCoverRemoteImageBuffer(
      'https://images.example.com/logo.png',
      { lookup: lookupFake() },
    )

    expect(buffer?.equals(PNG_PIXEL)).toBe(true)
    expect(fetchCalls).toEqual([
      'https://93.184.216.34/logo.png',
      'https://93.184.216.34/logo.png',
    ])
    expect(
      fetchInits.map((init) => (init.headers as Record<string, string>).Host),
    ).toEqual(['images.example.com', 'cdn.example.com'])
  })

  test('rejects hosts that resolve to a private address', async () => {
    stubFetch(
      () =>
        new Response(PNG_PIXEL, { headers: { 'Content-Type': 'image/png' } }),
    )

    await expect(
      fetchCoverRemoteImageBuffer('https://rebind.example.com/logo.png', {
        lookup: lookupFake({ 'rebind.example.com': ['10.0.0.5'] }),
      }),
    ).resolves.toBeNull()
    expect(fetchCalls).toEqual([])
  })

  test('rejects non-image responses without surfacing an error', async () => {
    stubFetch(
      () =>
        new Response('<html>internal dashboard</html>', {
          headers: { 'Content-Type': 'text/html' },
        }),
    )

    await expect(
      fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
        lookup: lookupFake(),
      }),
    ).resolves.toBeNull()
  })

  test('rejects payloads that do not decode as an image', async () => {
    stubFetch(
      () =>
        new Response('not an image', {
          headers: { 'Content-Type': 'image/png' },
        }),
    )

    await expect(
      fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
        lookup: lookupFake(),
      }),
    ).resolves.toBeNull()
  })

  test('rejects responses larger than the size cap', async () => {
    stubFetch(
      () =>
        new Response(Buffer.alloc(COVER_REMOTE_IMAGE_MAX_BYTES + 1), {
          headers: { 'Content-Type': 'image/png' },
        }),
    )

    await expect(
      fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
        lookup: lookupFake(),
      }),
    ).resolves.toBeNull()
  })

  test('aborts the request when the target does not respond in time', async () => {
    let signal: AbortSignal | undefined
    globalThis.fetch = (async (
      input: RequestInfo | URL,
      init?: RequestInit,
    ) => {
      fetchCalls.push(input instanceof Request ? input.url : String(input))
      signal = init?.signal ?? undefined
      return new Response(PNG_PIXEL, {
        headers: { 'Content-Type': 'image/png' },
      })
    }) as typeof fetch

    await fetchCoverRemoteImageBuffer('https://images.example.com/logo.png', {
      lookup: lookupFake(),
    })

    expect(signal).toBeInstanceOf(AbortSignal)
  })
})
