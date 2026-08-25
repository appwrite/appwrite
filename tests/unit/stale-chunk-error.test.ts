import { afterEach, describe, expect, mock, test } from 'bun:test'
import {
  STALE_CHUNK_BOOT_SCRIPT,
  STALE_CHUNK_CACHE_BUST_PARAM,
  forceReloadForStaleChunk,
  isHashedBuildAssetUrl,
  isStaleChunkLoadError,
  staleChunkDocumentUrl,
} from '@/lib/stale-chunk-error'

describe('isStaleChunkLoadError', () => {
  test('treats vite preload errors as stale', () => {
    expect(
      isStaleChunkLoadError(new Error('anything'), { fromVitePreload: true }),
    ).toBe(true)
  })

  test('detects hashed /assets/ URLs in the message', () => {
    expect(
      isStaleChunkLoadError(
        new Error(
          'Failed to fetch dynamically imported module: https://example.com/assets/View-abc123.js',
        ),
      ),
    ).toBe(true)
  })

  test('detects MIME / module-load hints', () => {
    expect(
      isStaleChunkLoadError(
        new Error("'text/html' is not a valid JavaScript MIME type"),
      ),
    ).toBe(true)
    expect(
      isStaleChunkLoadError(
        new Error('Failed to fetch dynamically imported module'),
      ),
    ).toBe(true)
  })

  test('does not treat unrelated errors as stale chunks', () => {
    expect(isStaleChunkLoadError(new Error('Project not found'))).toBe(false)
  })
})

describe('isHashedBuildAssetUrl', () => {
  test('matches hashed build assets', () => {
    expect(isHashedBuildAssetUrl('/assets/index-a1b2c3.js')).toBe(true)
    expect(
      isHashedBuildAssetUrl('https://cloud.example/assets/View-dd11ee.js'),
    ).toBe(true)
  })

  test('rejects non-asset URLs', () => {
    expect(isHashedBuildAssetUrl('/projects/abc')).toBe(false)
    expect(isHashedBuildAssetUrl('')).toBe(false)
  })
})

describe('staleChunkDocumentUrl', () => {
  test('strips the cache-bust param and keeps the real path', () => {
    expect(
      staleChunkDocumentUrl(
        'https://console.example/projects/abc/storage?query=name&_sc=123',
      ),
    ).toBe('/projects/abc/storage?query=name')
  })

  test('leaves URLs without the bust param unchanged', () => {
    expect(
      staleChunkDocumentUrl('https://console.example/projects/abc?page=2'),
    ).toBe('/projects/abc?page=2')
  })
})

describe('forceReloadForStaleChunk', () => {
  const originalFetch = globalThis.fetch
  const originalWindow = (globalThis as { window?: unknown }).window

  afterEach(() => {
    globalThis.fetch = originalFetch
    if (originalWindow === undefined) {
      Reflect.deleteProperty(globalThis, 'window')
    } else {
      ;(globalThis as { window?: unknown }).window = originalWindow
    }
  })

  function mockLocation(href: string) {
    const url = new URL(href)
    const locationMock = {
      href,
      pathname: url.pathname,
      search: url.search,
      hash: url.hash,
      reload: mock(() => {}),
      replace: mock(() => {}),
      assign: mock(() => {}),
    }
    ;(globalThis as { window: { location: typeof locationMock } }).window = {
      location: locationMock,
      setTimeout: globalThis.setTimeout.bind(globalThis),
    }
    return locationMock
  }

  test('revalidates the real HTML document then reloads instead of location.replace with ?_sc=', async () => {
    const locationMock = mockLocation(
      'https://console.example/projects/abc/storage?query=name',
    )
    let fetchUrl = ''
    let fetchInit: RequestInit | undefined
    globalThis.fetch = mock((input: RequestInfo | URL, init?: RequestInit) => {
      fetchUrl = String(input)
      fetchInit = init
      return Promise.resolve(new Response('ok'))
    }) as unknown as typeof fetch

    forceReloadForStaleChunk()

    expect(fetchUrl).toBe('/projects/abc/storage?query=name')
    expect(fetchInit?.cache).toBe('reload')
    expect(
      (fetchInit?.headers as Record<string, string>)['Cache-Control'],
    ).toBe('no-cache')
    expect(locationMock.replace).not.toHaveBeenCalled()

    await Promise.resolve()
    await Promise.resolve()

    expect(locationMock.reload).toHaveBeenCalled()
  })

  test('does not use ?_sc= as the revalidation URL', () => {
    mockLocation(
      `https://console.example/projects/abc?${STALE_CHUNK_CACHE_BUST_PARAM}=999`,
    )
    let fetchUrl = ''
    globalThis.fetch = mock((input: RequestInfo | URL) => {
      fetchUrl = String(input)
      return Promise.resolve(new Response('ok'))
    }) as unknown as typeof fetch

    forceReloadForStaleChunk()

    expect(fetchUrl).toBe('/projects/abc')
    expect(fetchUrl).not.toContain(`${STALE_CHUNK_CACHE_BUST_PARAM}=`)
  })
})

describe('STALE_CHUNK_BOOT_SCRIPT', () => {
  test('revalidates HTML with cache reload instead of query-param replace', () => {
    expect(STALE_CHUNK_BOOT_SCRIPT).toContain('cache:"reload"')
    expect(STALE_CHUNK_BOOT_SCRIPT).toContain('Cache-Control')
    expect(STALE_CHUNK_BOOT_SCRIPT).toContain('location.reload()')
    expect(STALE_CHUNK_BOOT_SCRIPT).not.toMatch(/searchParams\.set\(PARAM/)
  })
})
