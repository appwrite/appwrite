import { afterEach, describe, expect, mock, test } from 'bun:test'
import {
  STALE_CHUNK_BOOT_SCRIPT,
  STALE_CHUNK_CACHE_BUST_PARAM,
  confirmStaleHashedAsset,
  extractHashedAssetUrl,
  forceReloadForStaleChunk,
  isHashedBuildAssetUrl,
  isStaleChunkLoadError,
  staleChunkDocumentUrl,
  tryReloadForStaleChunk,
} from '@/lib/stale-chunk-error'

describe('isStaleChunkLoadError', () => {
  test('treats HTML MIME mismatches as stale', () => {
    expect(
      isStaleChunkLoadError(
        new Error("'text/html' is not a valid JavaScript MIME type"),
      ),
    ).toBe(true)
    expect(
      isStaleChunkLoadError(
        new Error('Expected a JavaScript module but got MIME type of "text/html"'),
      ),
    ).toBe(true)
  })

  test('treats errors that name a hashed /assets/ URL as stale candidates', () => {
    expect(
      isStaleChunkLoadError(
        new Error(
          'Failed to fetch dynamically imported module: https://example.com/assets/View-abc123.js',
        ),
      ),
    ).toBe(true)
  })

  test('does not treat bare dynamic-import cancel messages as stale', () => {
    expect(
      isStaleChunkLoadError(
        new Error('Failed to fetch dynamically imported module'),
      ),
    ).toBe(false)
    expect(
      isStaleChunkLoadError(new Error('Importing a module script failed')),
    ).toBe(false)
    expect(
      isStaleChunkLoadError(new Error('error loading dynamically imported module')),
    ).toBe(false)
  })

  test('does not treat vite:preloadError alone as stale', () => {
    expect(
      isStaleChunkLoadError(new Error('anything'), { fromVitePreload: true }),
    ).toBe(false)
  })

  test('treats vite:preloadError as stale when the payload names an asset', () => {
    expect(
      isStaleChunkLoadError(
        new Error(
          'Failed to fetch dynamically imported module: /assets/View-abc123.js',
        ),
        { fromVitePreload: true },
      ),
    ).toBe(true)
  })

  test('does not treat unrelated errors as stale chunks', () => {
    expect(isStaleChunkLoadError(new Error('Project not found'))).toBe(false)
  })
})

describe('extractHashedAssetUrl', () => {
  test('extracts absolute and root-relative asset URLs from messages', () => {
    expect(
      extractHashedAssetUrl(
        new Error(
          'Failed to fetch dynamically imported module: https://example.com/assets/View-abc123.js',
        ),
      ),
    ).toBe('https://example.com/assets/View-abc123.js')
    expect(
      extractHashedAssetUrl(
        new Error('Importing a module script failed: /assets/chunk-deadbeef.js'),
      ),
    ).toBe('/assets/chunk-deadbeef.js')
  })

  test('returns null when no hashed asset URL is present', () => {
    expect(
      extractHashedAssetUrl(new Error('Importing a module script failed')),
    ).toBeNull()
  })
})

describe('confirmStaleHashedAsset', () => {
  const originalFetch = globalThis.fetch

  afterEach(() => {
    globalThis.fetch = originalFetch
  })

  test('confirms 404 as stale', async () => {
    globalThis.fetch = mock(() =>
      Promise.resolve(new Response('Not Found', { status: 404 })),
    ) as unknown as typeof fetch
    expect(await confirmStaleHashedAsset('/assets/View-abc123.js')).toBe(true)
  })

  test('confirms text/html Content-Type as stale', async () => {
    globalThis.fetch = mock(() =>
      Promise.resolve(
        new Response('<!doctype html>', {
          status: 200,
          headers: { 'Content-Type': 'text/html; charset=utf-8' },
        }),
      ),
    ) as unknown as typeof fetch
    expect(await confirmStaleHashedAsset('/assets/View-abc123.js')).toBe(true)
  })

  test('does not confirm a successful JS response', async () => {
    globalThis.fetch = mock(() =>
      Promise.resolve(
        new Response('export {}', {
          status: 200,
          headers: { 'Content-Type': 'application/javascript' },
        }),
      ),
    ) as unknown as typeof fetch
    expect(await confirmStaleHashedAsset('/assets/View-abc123.js')).toBe(false)
  })

  test('does not confirm network failures', async () => {
    globalThis.fetch = mock(() =>
      Promise.reject(new TypeError('Failed to fetch')),
    ) as unknown as typeof fetch
    expect(await confirmStaleHashedAsset('/assets/View-abc123.js')).toBe(false)
  })
})

describe('tryReloadForStaleChunk', () => {
  const originalFetch = globalThis.fetch
  const originalWindow = (globalThis as { window?: unknown }).window
  const originalSessionStorage = globalThis.sessionStorage

  afterEach(() => {
    globalThis.fetch = originalFetch
    if (originalWindow === undefined) {
      Reflect.deleteProperty(globalThis, 'window')
    } else {
      ;(globalThis as { window?: unknown }).window = originalWindow
    }
    Object.defineProperty(globalThis, 'sessionStorage', {
      value: originalSessionStorage,
      configurable: true,
    })
  })

  function mockBrowser() {
    const store = new Map<string, string>()
    const locationMock = {
      href: 'https://console.example/projects/abc',
      pathname: '/projects/abc',
      search: '',
      hash: '',
      reload: mock(() => {}),
      replace: mock(() => {}),
      assign: mock(() => {}),
    }
    const sessionStorageMock = {
      getItem: (key: string) => store.get(key) ?? null,
      setItem: (key: string, value: string) => {
        store.set(key, value)
      },
      removeItem: (key: string) => {
        store.delete(key)
      },
    }
    ;(globalThis as { window: unknown }).window = {
      location: locationMock,
      setTimeout: globalThis.setTimeout.bind(globalThis),
      history: { replaceState: mock(() => {}), state: null },
    }
    Object.defineProperty(globalThis, 'sessionStorage', {
      value: sessionStorageMock,
      configurable: true,
    })
    return locationMock
  }

  test('does not reload on bare Firefox cancel messages', () => {
    const locationMock = mockBrowser()
    expect(
      tryReloadForStaleChunk(new Error('Importing a module script failed')),
    ).toBe(false)
    expect(locationMock.reload).not.toHaveBeenCalled()
  })

  test('reloads after confirming a 404 hashed asset', async () => {
    const locationMock = mockBrowser()
    globalThis.fetch = mock((input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes('/assets/')) {
        return Promise.resolve(new Response('Not Found', { status: 404 }))
      }
      return Promise.resolve(new Response('ok'))
    }) as unknown as typeof fetch

    expect(
      tryReloadForStaleChunk(
        new Error(
          'Failed to fetch dynamically imported module: https://example.com/assets/View-abc123.js',
        ),
      ),
    ).toBe(true)

    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()

    expect(locationMock.reload).toHaveBeenCalled()
  })

  test('does not reload when the hashed asset still exists', async () => {
    const locationMock = mockBrowser()
    globalThis.fetch = mock(() =>
      Promise.resolve(
        new Response('export {}', {
          status: 200,
          headers: { 'Content-Type': 'application/javascript' },
        }),
      ),
    ) as unknown as typeof fetch

    expect(
      tryReloadForStaleChunk(
        new Error(
          'Failed to fetch dynamically imported module: /assets/View-abc123.js',
        ),
      ),
    ).toBe(true)

    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()

    expect(locationMock.reload).not.toHaveBeenCalled()
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

  test('verifies hashed assets before reload and ignores bare cancel messages', () => {
    expect(STALE_CHUNK_BOOT_SCRIPT).toContain('confirmAssetThenReload')
    expect(STALE_CHUNK_BOOT_SCRIPT).toContain('text/html')
    expect(STALE_CHUNK_BOOT_SCRIPT).toContain('status===404')
    expect(STALE_CHUNK_BOOT_SCRIPT).not.toContain(
      'failed to fetch dynamically imported module',
    )
    expect(STALE_CHUNK_BOOT_SCRIPT).not.toContain(
      'importing a module script failed',
    )
  })
})
