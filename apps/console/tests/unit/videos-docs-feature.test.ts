import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { setExperimentEnabled } from '@/lib/experiments'
import { isFeatureGatedDocsSlugHidden } from '@/lib/docs/feature-gated-docs'
import { getDocsGlobalNav } from '@/lib/docs/navigation'
import { getAllDocsSectionNavs } from '@/lib/docs/navigation/section-navs'
import { getDocsSitemapEntries } from '@/lib/sitemap/providers/docs'
import type { DocsNavTree } from '@/lib/docs/types'

const globals = globalThis as { window?: unknown; localStorage?: unknown }
const originalWindow = globals.window
const originalLocalStorage = globals.localStorage

function hrefs(navigation: DocsNavTree): string[] {
  return navigation.flatMap((entry) =>
    'items' in entry ? entry.items.map((item) => item.href) : [entry.href],
  )
}

function isVideosPath(path: string): boolean {
  return path === '/docs/products/videos' || path.startsWith('/docs/products/videos/')
}

describe('videos docs', () => {
  beforeEach(() => {
    const storage = new Map<string, string>()
    const localStorage = {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
    }
    globals.localStorage = localStorage
    globals.window = {
      localStorage,
      location: new URL('https://cloud.appwrite.io/docs'),
      dispatchEvent: () => true,
    }
  })

  afterEach(() => {
    globals.window = originalWindow
    globals.localStorage = originalLocalStorage
  })

  test('stay hidden while the videos flag is off', () => {
    expect(isFeatureGatedDocsSlugHidden('products/videos')).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('products/videos/quick-start')).toBe(true)
    expect(
      isFeatureGatedDocsSlugHidden('products/videos', { deferVideosOnServer: true }),
    ).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('products/storage')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/videos-guide')).toBe(false)

    expect(hrefs(getDocsGlobalNav('developers')).some(isVideosPath)).toBe(false)
    expect(
      getAllDocsSectionNavs().some((config) => config.prefix === 'products/videos'),
    ).toBe(false)
    expect(
      getDocsSitemapEntries().some((entry) => isVideosPath(entry.path)),
    ).toBe(false)
  })

  test('open once the Videos experiment turns the flag on', () => {
    setExperimentEnabled('videos', true)

    expect(isFeatureGatedDocsSlugHidden('products/videos')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/videos/play')).toBe(false)
    expect(hrefs(getDocsGlobalNav('developers'))).toContain('/docs/products/videos')
    expect(
      getAllDocsSectionNavs().some((config) => config.prefix === 'products/videos'),
    ).toBe(true)

    setExperimentEnabled('videos', false)
    expect(isFeatureGatedDocsSlugHidden('products/videos')).toBe(true)
  })

  test('the server does not block a reload before the browser applies the flag', () => {
    globals.window = undefined
    expect(
      isFeatureGatedDocsSlugHidden('products/videos', { deferVideosOnServer: true }),
    ).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/videos')).toBe(true)
  })
})
