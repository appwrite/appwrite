import { afterEach, beforeEach, describe, expect, test } from 'bun:test'
import { setDebugProfileFeatureOverride } from '@/lib/console-profiles'
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

function isZeroPath(path: string): boolean {
  return [
    '/docs/zero',
    '/docs/tooling/command-line/zero',
  ].some((zero) => path === zero || path.startsWith(`${zero}/`))
}

function sectionNavHrefs(): string[] {
  return getAllDocsSectionNavs().flatMap((config) => hrefs(config.navigation))
}

describe('zero docs', () => {
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

  test('stay hidden while the zero flag is off', () => {
    expect(isFeatureGatedDocsSlugHidden('zero')).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('tooling/command-line/zero')).toBe(true)
    expect(
      isFeatureGatedDocsSlugHidden('zero', { deferZeroOnServer: true }),
    ).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('zero-trust')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('tooling/command-line/installation')).toBe(
      false,
    )

    expect(hrefs(getDocsGlobalNav('developers')).some(isZeroPath)).toBe(false)
    expect(sectionNavHrefs().some(isZeroPath)).toBe(false)
    expect(sectionNavHrefs()).toContain('/docs/tooling/command-line/installation')
    expect(getDocsSitemapEntries().some((entry) => isZeroPath(entry.path))).toBe(
      false,
    )
  })

  test('open once the zero flag is on', () => {
    setDebugProfileFeatureOverride('zero', true)

    expect(isFeatureGatedDocsSlugHidden('zero')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('tooling/command-line/zero')).toBe(false)
    expect(hrefs(getDocsGlobalNav('developers'))).toContain('/docs/zero')
    expect(sectionNavHrefs()).toContain('/docs/tooling/command-line/zero')

    setDebugProfileFeatureOverride('zero', false)
    expect(isFeatureGatedDocsSlugHidden('zero')).toBe(true)
  })

  test('the server does not block a reload before the browser applies the flag', () => {
    globals.window = undefined
    expect(
      isFeatureGatedDocsSlugHidden('zero', { deferZeroOnServer: true }),
    ).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('zero')).toBe(true)
  })
})
