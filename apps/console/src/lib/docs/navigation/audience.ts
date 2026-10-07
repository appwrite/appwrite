import type { DocsNavGroup, DocsNavTree } from '../types'
import { isAgentDocsEnabled, isAgentDocsHref } from '../agent-docs-feature'
import { DOCS_GLOBAL_NAV } from './global'
import { DOCS_PARTNERS_GLOBAL_NAV } from './partners'

export type DocsAudience = 'developers' | 'partners'

export function getDocsAudienceFromSlug(slug: string): DocsAudience {
  return slug === 'partners' || slug.startsWith('partners/')
    ? 'partners'
    : 'developers'
}

export function getDocsAudienceFromPathname(pathname: string): DocsAudience {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (
    normalized === '/docs/partners' ||
    normalized.startsWith('/docs/partners/')
  ) {
    return 'partners'
  }
  return 'developers'
}

function isNavGroup(item: DocsNavTree[number]): item is DocsNavGroup {
  return 'items' in item
}

function withoutHref(
  navigation: DocsNavTree,
  isHiddenHref: (href: string) => boolean,
): DocsNavTree {
  return navigation.flatMap((entry) => {
    if (isNavGroup(entry)) {
      const items = entry.items.filter((item) => !isHiddenHref(item.href))
      const footerLink =
        entry.footerLink && !isHiddenHref(entry.footerLink.href)
          ? entry.footerLink
          : undefined
      if (items.length === 0 && !footerLink) return []
      return [{ ...entry, items, footerLink }]
    }
    if (isHiddenHref(entry.href)) return []
    return [entry]
  })
}

export function getDocsGlobalNav(audience: DocsAudience): DocsNavTree {
  let navigation =
    audience === 'partners' ? DOCS_PARTNERS_GLOBAL_NAV : DOCS_GLOBAL_NAV
  if (audience === 'partners') return navigation
  if (!isAgentDocsEnabled()) {
    navigation = withoutHref(navigation, isAgentDocsHref)
  }
  return navigation
}

export function getDocsAudienceHomeHref(audience: DocsAudience): string {
  return audience === 'partners' ? '/docs/partners' : '/docs'
}
