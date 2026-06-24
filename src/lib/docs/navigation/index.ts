import type { DocsNavParent, DocsNavTree } from '../types'
import type { DocsSectionNavConfig } from './sections'
import { getAllDocsSectionNavs } from './section-navs'

export { DOCS_GLOBAL_NAV } from './global'
export { DOCS_PARTNERS_GLOBAL_NAV } from './partners'
export {
  getDocsAudienceFromPathname,
  getDocsAudienceFromSlug,
  getDocsAudienceHomeHref,
  getDocsGlobalNav,
  type DocsAudience,
} from './audience'

export function isDocsNavGroup(
  item: DocsNavTree[number],
): item is Extract<DocsNavTree[number], { items: unknown[] }> {
  return 'items' in item
}

function countSectionNavLinks(navigation: DocsNavTree): number {
  let count = 0
  for (const node of navigation) {
    if (isDocsNavGroup(node)) {
      count += node.items.length
    } else {
      count += 1
    }
  }
  return count
}

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

  if (best && countSectionNavLinks(best.navigation) > 1) {
    return { parent: best.parent, navigation: best.navigation }
  }

  return { parent: null, navigation: null }
}
