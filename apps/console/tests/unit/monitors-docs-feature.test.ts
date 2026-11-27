import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { setExperimentEnabled } from '@/lib/experiments'
import { isFeatureGatedDocsSlugHidden } from '@/lib/docs/feature-gated-docs'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import { getDocsGlobalNav } from '@/lib/docs/navigation'
import { getAllDocsSectionNavs } from '@/lib/docs/navigation/section-navs'
import { searchDocs } from '@/lib/docs/search'
import type { DocsNavTree } from '@/lib/docs/types'
import { buildDocsLlmsTxt } from '@/lib/seo/llms'
import { getDocsSitemapEntries } from '@/lib/sitemap/providers/docs'

const globals = globalThis as { window?: unknown; localStorage?: unknown }
const originalWindow = globals.window
const originalLocalStorage = globals.localStorage

function hrefs(navigation: DocsNavTree): string[] {
  return navigation.flatMap((entry) =>
    'items' in entry ? entry.items.map((item) => item.href) : [entry.href],
  )
}

function isMonitorsPath(path: string): boolean {
  return (
    path === '/docs/products/monitors' ||
    path.startsWith('/docs/products/monitors/')
  )
}

function isMonitorsSlug(slug: string): boolean {
  return slug === 'products/monitors' || slug.startsWith('products/monitors/')
}

describe('monitors docs', () => {
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

  test('stay hidden while the alerts flag is off', () => {
    expect(isFeatureGatedDocsSlugHidden('products/monitors')).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('products/monitors/usage')).toBe(true)
    expect(
      isFeatureGatedDocsSlugHidden('products/monitors', {
        deferMonitorsOnServer: true,
      }),
    ).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('products/messaging')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/monitors-guide')).toBe(false)

    expect(hrefs(getDocsGlobalNav('developers')).some(isMonitorsPath)).toBe(false)
    expect(
      getAllDocsSectionNavs().some(
        (config) => config.prefix === 'products/monitors',
      ),
    ).toBe(false)
    expect(
      searchDocs('monitors').some((page) => isMonitorsSlug(page.slug)),
    ).toBe(false)
    expect(
      getDocsSitemapEntries().some((entry) => isMonitorsPath(entry.path)),
    ).toBe(false)
  })

  test('open once the Monitors and alerts experiment turns the flag on', () => {
    setExperimentEnabled('alerts', true)

    expect(isFeatureGatedDocsSlugHidden('products/monitors')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/monitors/alerts')).toBe(false)
    expect(hrefs(getDocsGlobalNav('developers'))).toContain(
      '/docs/products/monitors',
    )
    expect(
      getAllDocsSectionNavs().some(
        (config) => config.prefix === 'products/monitors',
      ),
    ).toBe(true)
    expect(searchDocs('monitors').map((page) => page.slug)).toContain(
      'products/monitors',
    )

    setExperimentEnabled('alerts', false)
    expect(isFeatureGatedDocsSlugHidden('products/monitors')).toBe(true)
  })

  test('the server does not block a reload before the browser applies the flag', () => {
    globals.window = undefined
    expect(
      isFeatureGatedDocsSlugHidden('products/monitors', {
        deferMonitorsOnServer: true,
      }),
    ).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('products/monitors')).toBe(true)
  })

  test('stay out of the anonymous sitemap and llms.txt', () => {
    globals.window = undefined
    expect(
      getDocsSitemapEntries().some((entry) => isMonitorsPath(entry.path)),
    ).toBe(false)
    expect(buildDocsLlmsTxt(DOCS_PAGES)).not.toContain('/docs/products/monitors')
  })
})
