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

function isLoopbackHostname(hostname: string): boolean {
  return (
    hostname === 'localhost' ||
    hostname === '127.0.0.1' ||
    hostname === '[::1]' ||
    hostname === '::1'
  )
}

function normalizeSiteOrigin(origin: string): string {
  return origin.replace(/\/+$/, '')
}

/**
 * TanStack prerender runs against loopback during production builds. Static HTML
 * must not bake `http://localhost` into og:url / og:image; use the public origin.
 */
export function resolveServerSiteOrigin(
  requestOrigin: string,
  isProd: boolean = import.meta.env.PROD,
): string {
  try {
    const { hostname } = new URL(requestOrigin)
    if (!isLoopbackHostname(hostname)) {
      return normalizeSiteOrigin(requestOrigin)
    }
  } catch {
    return getDefaultSiteOrigin()
  }

  if (isProd) {
    return getDefaultSiteOrigin()
  }

  return normalizeSiteOrigin(requestOrigin)
}

export const getRequestSiteOrigin = createIsomorphicFn()
  .server(() => {
    try {
      const origin = getRequestUrl({
        xForwardedHost: true,
        xForwardedProto: true,
      }).origin
      return resolveServerSiteOrigin(origin)
    } catch {
      return getDefaultSiteOrigin()
    }
  })
  .client(() => window.location.origin)
