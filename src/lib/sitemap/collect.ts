import { SITEMAP_MAX_URLS_PER_FILE } from './config'
import {
  assertSitemapEligible,
  isExcludedFromSitemap,
  normalizeSitemapPath,
} from './excluded-paths'
import { getBlogSitemapEntries } from './providers/blog'
import { getChangelogSitemapEntries } from './providers/changelog'
import { getDocsSitemapEntries } from './providers/docs'
import { getIntegrationsSitemapEntries } from './providers/integrations'
import { getMarketingSitemapEntries } from './providers/marketing'
import type { SitemapBuildResult, SitemapEntry, SitemapSection } from './types'

type SectionDefinition = {
  id: string
  getEntries: () => SitemapEntry[]
}

const SECTION_DEFINITIONS: SectionDefinition[] = [
  { id: 'pages', getEntries: getMarketingSitemapEntries },
  { id: 'docs', getEntries: getDocsSitemapEntries },
  { id: 'blog', getEntries: getBlogSitemapEntries },
  { id: 'changelog', getEntries: getChangelogSitemapEntries },
  { id: 'integrations', getEntries: getIntegrationsSitemapEntries },
]

function dedupeEntries(entries: SitemapEntry[]): SitemapEntry[] {
  const seen = new Set<string>()
  const deduped: SitemapEntry[] = []

  for (const entry of entries) {
    const normalized = normalizeSitemapPath(entry.path)
    if (seen.has(normalized)) continue
    seen.add(normalized)
    deduped.push({ ...entry, path: normalized })
  }

  return deduped
}

function validateEntries(sectionId: string, entries: SitemapEntry[]): SitemapEntry[] {
  const deduped = dedupeEntries(entries)

  for (const entry of deduped) {
    if (isExcludedFromSitemap(entry.path)) {
      throw new Error(
        `Sitemap section "${sectionId}" includes excluded path: ${entry.path}`,
      )
    }
    assertSitemapEligible(entry.path)
  }

  if (deduped.length > SITEMAP_MAX_URLS_PER_FILE) {
    throw new Error(
      `Sitemap section "${sectionId}" exceeds ${SITEMAP_MAX_URLS_PER_FILE} URLs (${deduped.length}). Split the section.`,
    )
  }

  return deduped
}

export function collectSitemapSections(): SitemapBuildResult {
  const sections: SitemapSection[] = SECTION_DEFINITIONS.map(({ id, getEntries }) => ({
    id,
    entries: validateEntries(id, getEntries()),
  }))

  const totalUrls = sections.reduce(
    (count, section) => count + section.entries.length,
    0,
  )

  return { sections, totalUrls }
}

/**
 * Register an additional sitemap section (e.g. future content types).
 * Call before `collectSitemapSections()` in custom build scripts.
 */
export function registerSitemapSection(definition: SectionDefinition): void {
  SECTION_DEFINITIONS.push(definition)
}

export function getSitemapSection(sectionId: string): SitemapSection | null {
  return collectSitemapSections().sections.find((section) => section.id === sectionId) ?? null
}
