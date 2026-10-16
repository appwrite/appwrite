import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isAnalyticsDocsSlug(slug: string): boolean {
  return slug === 'products/analytics' || slug.startsWith('products/analytics/')
}

export function isAnalyticsDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/analytics' ||
    normalized.startsWith('/docs/products/analytics/')
  )
}

export function isAnalyticsDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/analytics' ||
    path.startsWith('/docs/products/analytics/')
  )
}

export function isAnalyticsDocsEnabled(): boolean {
  return getActiveProfileFeatures().analytics
}

/**
 * Whether analytics docs should be treated as unavailable for route guards.
 *
 * Analytics docs default to off and are previewed through a debug profile
 * override, which lives in localStorage and isn't visible to SSR. On the
 * server, do not block so a reload doesn't bounce to /docs before the client
 * can apply the override. The docs sidebar still enforces the real flag.
 */
export function shouldBlockAnalyticsDocs(): boolean {
  if (isAnalyticsDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
