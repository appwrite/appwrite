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

function toolingHrefs(): string[] {
  const tooling = getAllDocsSectionNavs().find(
    (config) => config.prefix === 'tooling',
  )
  return tooling ? hrefs(tooling.navigation) : []
}

function isBeepPath(path: string): boolean {
  return path === '/docs/tooling/beep' || path.startsWith('/docs/tooling/beep/')
}

describe('beep docs', () => {
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
    expect(isFeatureGatedDocsSlugHidden('tooling/beep')).toBe(true)
    expect(
      isFeatureGatedDocsSlugHidden('tooling/beep', { deferBeepOnServer: true }),
    ).toBe(true)
    expect(isFeatureGatedDocsSlugHidden('tooling/appwriter')).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('tooling/beeper')).toBe(false)

    expect(hrefs(getDocsGlobalNav('developers')).some(isBeepPath)).toBe(false)
    expect(toolingHrefs().some(isBeepPath)).toBe(false)
    expect(
      getDocsSitemapEntries().some((entry) => isBeepPath(entry.path)),
    ).toBe(false)
  })

  test('open once the Monitors and alerts experiment turns the flag on', () => {
    setExperimentEnabled('alerts', true)

    expect(isFeatureGatedDocsSlugHidden('tooling/beep')).toBe(false)
    expect(hrefs(getDocsGlobalNav('developers'))).toContain('/docs/tooling/beep')
    expect(toolingHrefs()).toContain('/docs/tooling/beep')

    setExperimentEnabled('alerts', false)
    expect(isFeatureGatedDocsSlugHidden('tooling/beep')).toBe(true)
  })

  test('the server does not block a reload before the browser applies the flag', () => {
    globals.window = undefined
    expect(
      isFeatureGatedDocsSlugHidden('tooling/beep', { deferBeepOnServer: true }),
    ).toBe(false)
    expect(isFeatureGatedDocsSlugHidden('tooling/beep')).toBe(true)
  })
})
