import type { DocsNavParent, DocsNavTree } from '../types'
import { DOCS_SECTION_NAVS, type DocsSectionNavConfig } from './sections'

export { DOCS_GLOBAL_NAV } from './global'

export function getDocsSectionNav(slug: string): {
  parent: DocsNavParent | null
  navigation: DocsNavTree | null
} {
  if (!slug) return { parent: null, navigation: null }

  let best: DocsSectionNavConfig | null = null
  for (const config of DOCS_SECTION_NAVS) {
    if (slug === config.prefix || slug.startsWith(`${config.prefix}/`)) {
      if (!best || config.prefix.length > best.prefix.length) {
        best = config
      }
    }
  }

  if (best) {
    return { parent: best.parent, navigation: best.navigation }
  }

  return { parent: null, navigation: null }
}

export function isDocsNavGroup(
  item: DocsNavTree[number],
): item is Extract<DocsNavTree[number], { items: unknown[] }> {
  return 'items' in item
}
