import type { DocsNavTree } from '../types'
import { DOCS_GLOBAL_NAV } from './global'
import { DOCS_PARTNERS_GLOBAL_NAV } from './partners'

export type DocsAudience = 'developers' | 'partners'

export function getDocsAudienceFromSlug(slug: string): DocsAudience {
  return slug === 'partners' || slug.startsWith('partners/') ? 'partners' : 'developers'
}

export function getDocsAudienceFromPathname(pathname: string): DocsAudience {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (normalized === '/docs/partners' || normalized.startsWith('/docs/partners/')) {
    return 'partners'
  }
  return 'developers'
}

export function getDocsGlobalNav(audience: DocsAudience): DocsNavTree {
  return audience === 'partners' ? DOCS_PARTNERS_GLOBAL_NAV : DOCS_GLOBAL_NAV
}

export function getDocsAudienceHomeHref(audience: DocsAudience): string {
  return audience === 'partners' ? '/docs/partners' : '/docs'
}
