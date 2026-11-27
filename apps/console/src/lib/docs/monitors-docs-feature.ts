import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isMonitorsDocsSlug(slug: string): boolean {
  return slug === 'products/monitors' || slug.startsWith('products/monitors/')
}

export function isMonitorsDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/monitors' ||
    normalized.startsWith('/docs/products/monitors/')
  )
}

export function isMonitorsDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/monitors' ||
    path.startsWith('/docs/products/monitors/')
  )
}

/** Monitors docs follow the `alerts` flag, which the quality team turns on from the header Previews menu as Monitors and alerts. */
export function isMonitorsDocsEnabled(): boolean {
  return getActiveProfileFeatures().alerts
}

/**
 * Whether monitors docs should be treated as unavailable for route guards.
 *
 * The `alerts` flag defaults to off and is turned on through a debug profile
 * override, which lives in localStorage. This helper can't see it on the
 * server, so it doesn't block there; the docs route reads the override's
 * cookie mirror instead. The docs sidebar still enforces the real flag.
 */
export function shouldBlockMonitorsDocs(): boolean {
  if (isMonitorsDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
