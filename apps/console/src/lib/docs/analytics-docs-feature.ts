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
