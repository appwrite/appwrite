import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { setExperimentEnabled } from '@/lib/experiments'
import { isFeatureGatedDocsSlugHidden } from '@/lib/docs/feature-gated-docs'
import { DOCS_PAGES } from '@/lib/docs/generated/manifest'
import { isMonitorsDocsSlug } from '@/lib/docs/monitors-docs-feature'
import { getDocsGlobalNav } from '@/lib/docs/navigation'
import { getAllDocsSectionNavs } from '@/lib/docs/navigation/section-navs'
import { searchDocs } from '@/lib/docs/search'
import { buildDocsLlmsTxt } from '@/lib/seo/llms'
import { getDocsSitemapEntries } from '@/lib/sitemap/providers/docs'

const globals = globalThis as { window?: unknown; localStorage?: unknown }
const originalWindow = globals.window
const originalLocalStorage = globals.localStorage
const slug = 'products/monitors'
const href = `/docs/${slug}`

function hasMonitorsNavigation(): boolean {
  return getDocsGlobalNav('developers').some(
    (entry) => 'items' in entry && entry.items.some((item) => item.href === href),
  )
}

describe('Monitors docs preview', () => {
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

  test('uses the Monitors and alerts flag for articles, navigation, and search', () => {
    expect(isFeatureGatedDocsSlugHidden(`${slug}/usage`)).toBe(true)
    expect(hasMonitorsNavigation()).toBe(false)
    expect(
      searchDocs('monitors').some((page) => isMonitorsDocsSlug(page.slug)),
    ).toBe(false)

    setExperimentEnabled('alerts', true)

    expect(isFeatureGatedDocsSlugHidden(`${slug}/usage`)).toBe(false)
    expect(hasMonitorsNavigation()).toBe(true)
    expect(
      getAllDocsSectionNavs().some((section) => section.prefix === slug),
    ).toBe(true)
    expect(searchDocs('monitors').map((page) => page.slug)).toContain(slug)

    setExperimentEnabled('alerts', false)

    expect(isFeatureGatedDocsSlugHidden(slug)).toBe(true)
    expect(hasMonitorsNavigation()).toBe(false)
  })

  test('leaves the preview pages out of anonymous docs exports', () => {
    globals.window = undefined
    expect(
      getDocsSitemapEntries().some((entry) => entry.path.startsWith(href)),
    ).toBe(false)
    expect(buildDocsLlmsTxt(DOCS_PAGES)).not.toContain(href)
  })
})
