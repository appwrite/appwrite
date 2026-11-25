import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { getDocsSlugFromPath } from './docs-slug'

const MONITORS_DOCS_SLUG = 'products/monitors'

export function isMonitorsDocsSlug(slug: string): boolean {
  return slug === MONITORS_DOCS_SLUG || slug.startsWith(`${MONITORS_DOCS_SLUG}/`)
}

export function isMonitorsDocsHref(href: string): boolean {
  const [pathname] = href.split(/[?#]/, 1)
  return isMonitorsDocsSlug(getDocsSlugFromPath(pathname))
}

export function isMonitorsDocsEnabled(): boolean {
  return getActiveProfileFeatures().alerts
}
