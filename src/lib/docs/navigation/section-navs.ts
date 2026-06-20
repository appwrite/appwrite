import { DOCS_LOCAL_SECTION_NAVS } from './local-sections'
import { DOCS_SECTION_NAVS, type DocsSectionNavConfig } from './sections'

/** Imported website docs plus vibes-native sections (local wins on duplicate prefix). */
export function getAllDocsSectionNavs(): DocsSectionNavConfig[] {
  const byPrefix = new Map<string, DocsSectionNavConfig>()
  for (const config of DOCS_SECTION_NAVS) {
    byPrefix.set(config.prefix, config)
  }
  for (const config of DOCS_LOCAL_SECTION_NAVS) {
    byPrefix.set(config.prefix, config)
  }
  return Array.from(byPrefix.values()).sort((a, b) =>
    a.prefix.localeCompare(b.prefix),
  )
}
