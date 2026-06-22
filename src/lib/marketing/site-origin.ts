import { createIsomorphicFn } from '@tanstack/react-start'
import { getRequestUrl } from '@tanstack/react-start/server'
import { getSitemapSiteOrigin } from '@/lib/sitemap/config'

export function getDefaultSiteOrigin(): string {
  return getSitemapSiteOrigin()
}

export function resolveSiteAssetUrl(path: string, siteOrigin?: string): string {
  if (!path.startsWith('/')) return path
  const origin = (siteOrigin ?? getDefaultSiteOrigin()).replace(/\/+$/, '')
  return `${origin}${path}`
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
