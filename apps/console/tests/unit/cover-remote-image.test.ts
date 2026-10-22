import { afterEach, describe, expect, test } from 'bun:test'
import {
  COVER_REMOTE_IMAGE_MAX_BYTES,
  fetchCoverRemoteImage,
} from '@/lib/cover-generator/remote-image'
import { loadCoverImageBuffer } from '@/lib/cover-generator/brand-background'
import type { AddressLookup } from '@/server/lib/public-address'

const PNG = new Uint8Array([0x89, 0x50, 0x4e, 0x47])

// Public hosts resolve to a public address, everything else to a private one.
const resolve: AddressLookup = async (hostname) =>
  hostname.endsWith('.public.example') ? ['104.18.0.1'] : ['10.0.0.8']

const originalFetch = globalThis.fetch
let requested: string[] = []

function serve(routes: Record<string, () => Response>): void {
  requested = []
  globalThis.fetch = (async (input: RequestInfo | URL) => {
    const url = String(input)
    requested.push(url)
    return routes[url]?.() ?? new Response('missing', { status: 404 })
  }) as typeof fetch
}

afterEach(() => {
  globalThis.fetch = originalFetch
})

describe('fetchCoverRemoteImage', () => {
  test('downloads images from public hosts', async () => {
    serve({ 'https://img.public.example/a.png': () => new Response(PNG) })
    const image = await fetchCoverRemoteImage(
      'https://img.public.example/a.png',
      resolve,
    )
    expect(image && new Uint8Array(image)).toEqual(PNG)
  })

  test('does not request non-public or unsupported URLs', async () => {
    serve({})
    for (const url of [
      'http://169.254.169.254/latest/meta-data/x.png',
      'http://127.0.0.1:3000/x.png',
      'http://[::1]/x.png',
      'http://internal.example/x.png',
      'https://user:pass@img.public.example/a.png',
      'file:///etc/hosts',
      'not a url',
    ]) {
      expect(await fetchCoverRemoteImage(url, resolve)).toBeNull()
    }
    expect(requested).toEqual([])
  })

  test('checks every redirect target', async () => {
    serve({
      'https://img.public.example/next': () =>
        new Response(null, {
          status: 302,
          headers: { Location: 'https://cdn.public.example/a.png' },
        }),
      'https://cdn.public.example/a.png': () => new Response(PNG),
      'https://img.public.example/inside': () =>
        new Response(null, {
          status: 302,
          headers: { Location: 'http://169.254.169.254/latest/' },
        }),
    })
    expect(
      await fetchCoverRemoteImage('https://img.public.example/next', resolve),
    ).not.toBeNull()
    expect(
      await fetchCoverRemoteImage('https://img.public.example/inside', resolve),
    ).toBeNull()
    expect(requested).not.toContain('http://169.254.169.254/latest/')
  })

  test('drops responses over the size limit', async () => {
    serve({
      'https://img.public.example/big.png': () =>
        new Response(new Uint8Array(COVER_REMOTE_IMAGE_MAX_BYTES + 1)),
    })
    expect(
      await fetchCoverRemoteImage(
        'https://img.public.example/big.png',
        resolve,
      ),
    ).toBeNull()
  })
})

describe('loadCoverImageBuffer', () => {
  test('does not request cover sources on non-public addresses', async () => {
    serve({})
    for (const source of [
      'http://169.254.169.254/latest/meta-data/x.png',
      'http://127.0.0.1:3000/x.png',
      'http://[fd00::1]/x.png',
    ]) {
      expect(await loadCoverImageBuffer(source)).toBeNull()
    }
    expect(requested).toEqual([])
  })
})
