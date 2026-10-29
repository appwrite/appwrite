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

/**
 * Whether partners docs should be treated as unavailable for route guards.
 *
 * Partners docs default to off and are usually enabled via a debug profile
 * override. Overrides live in localStorage and are mirrored to a cookie for SSR,
 * but the cookie may be missing on the first request after enabling the flag.
 * On the server, do not block when the feature resolves to off so a reload does
 * not bounce to /docs before the client can apply localStorage. Client-side
 * guards (route beforeLoad + docs sidebar) still enforce the real flag.
 */
export function shouldBlockPartnersDocs(): boolean {
  if (isPartnersDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
