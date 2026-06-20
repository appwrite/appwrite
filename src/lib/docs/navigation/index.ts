import type { DocsNavParent, DocsNavTree } from '../types'
import type { DocsSectionNavConfig } from './sections'
import { getAllDocsSectionNavs } from './section-navs'

export { DOCS_GLOBAL_NAV } from './global'

export function getDocsSectionNav(slug: string): {
  parent: DocsNavParent | null
  navigation: DocsNavTree | null
} {
  if (!slug) return { parent: null, navigation: null }

  let best: DocsSectionNavConfig | null = null
  for (const config of getAllDocsSectionNavs()) {
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
