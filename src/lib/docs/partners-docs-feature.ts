import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isPartnersDocsSlug(slug: string): boolean {
  return slug === 'partners' || slug.startsWith('partners/')
}

export function isPartnersDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return normalized === '/docs/partners' || normalized.startsWith('/docs/partners/')
}

export function isPartnersDocsEnabled(): boolean {
  return getActiveProfileFeatures().partnersDocs
}
