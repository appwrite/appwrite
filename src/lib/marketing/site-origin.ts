import { createIsomorphicFn } from '@tanstack/react-start'
import { getRequestUrl } from '@tanstack/react-start/server'
import { getSitemapSiteOrigin } from '@/lib/sitemap/config'

export function getDefaultSiteOrigin(): string {
  return getSitemapSiteOrigin()
}

/** Origin for absolute SEO/asset URLs. Prefers the active request host (incl. port). */
export function getSeoSiteOrigin(siteOrigin?: string): string {
  return (siteOrigin ?? getRequestSiteOrigin()).replace(/\/+$/, '')
}

export function resolveSiteAssetUrl(path: string, siteOrigin?: string): string {
  if (!path.startsWith('/')) return path
  return `${getSeoSiteOrigin(siteOrigin)}${path}`
}

export const getRequestSiteOrigin = createIsomorphicFn()
  .server(() => {
    try {
      return getRequestUrl({
        xForwardedHost: true,
        xForwardedProto: true,
      }).origin
    } catch {
      return getDefaultSiteOrigin()
    }
  })
  .client(() => window.location.origin)
