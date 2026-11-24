import { getActiveProfileFeatures } from '@/lib/console-profiles'

export function isVideosDocsSlug(slug: string): boolean {
  return slug === 'products/videos' || slug.startsWith('products/videos/')
}

export function isVideosDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return (
    normalized === '/docs/products/videos' ||
    normalized.startsWith('/docs/products/videos/')
  )
}

export function isVideosDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return (
    path === '/docs/products/videos' || path.startsWith('/docs/products/videos/')
  )
}

/** Videos docs follow the `videos` flag, which the quality team turns on from the header Previews menu. */
export function isVideosDocsEnabled(): boolean {
  return getActiveProfileFeatures().videos
}

/**
 * Whether videos docs should be treated as unavailable for route guards.
 *
 * The `videos` flag defaults to off and is turned on through a debug profile
 * override, which lives in localStorage and isn't visible to SSR. On the
 * server, do not block so a reload doesn't bounce to /docs before the client
 * can apply the override. The docs sidebar still enforces the real flag.
 */
export function shouldBlockVideosDocs(): boolean {
  if (isVideosDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
