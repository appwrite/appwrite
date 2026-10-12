import type { DocsNavLink, DocsNavParent, DocsNavTree } from '../types'
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

export type DocsStepNeighbors = {
  previous: DocsNavLink | null
  next: DocsNavLink | null
}

function hrefToDocsSlug(href: string): string {
  const pathname = href.split('#')[0].replace(/\/+$/, '')
  if (pathname === '/docs') return ''
  if (!pathname.startsWith('/docs/')) return pathname
  return pathname.slice('/docs/'.length)
}

/**
 * Previous/next links for a multi-step page, taken from the nav group that lists
 * the current slug. Steps are ordered by the section nav, not by frontmatter.
 */
export function getDocsStepNeighbors(slug: string): DocsStepNeighbors {
  const empty: DocsStepNeighbors = { previous: null, next: null }
  if (!slug) return empty

  const { navigation } = getDocsSectionNav(slug)
  if (!navigation) return empty

  for (const node of navigation) {
    const items = isDocsNavGroup(node) ? node.items : [node]
    const index = items.findIndex((item) => hrefToDocsSlug(item.href) === slug)
    if (index === -1) continue
    return {
      previous: index > 0 ? items[index - 1] : null,
      next: index < items.length - 1 ? items[index + 1] : null,
    }
  }

  return empty
}
