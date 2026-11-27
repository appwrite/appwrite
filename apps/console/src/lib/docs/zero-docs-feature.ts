import { getActiveProfileFeatures } from '@/lib/console-profiles'

const ZERO_DOCS_SLUGS = ['zero', 'tooling/command-line/zero']

function isZeroDocsPath(path: string): boolean {
  return ZERO_DOCS_SLUGS.some(
    (slug) => path === slug || path.startsWith(`${slug}/`),
  )
}

export function isZeroDocsSlug(slug: string): boolean {
  return isZeroDocsPath(slug)
}

export function isZeroDocsPathname(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return normalized.startsWith('/docs/') && isZeroDocsPath(normalized.slice(6))
}

export function isZeroDocsHref(href: string): boolean {
  const path = href.split(/[?#]/, 2)[0] ?? href
  return isZeroDocsPathname(path)
}

/** Zero docs follow the `zero` flag, which turns on at launch with the Zero page. */
export function isZeroDocsEnabled(): boolean {
  return getActiveProfileFeatures().zero
}

/**
 * Whether Zero docs should be treated as unavailable for route guards.
 *
 * Before launch the `zero` flag is turned on through a debug profile override,
 * which lives in localStorage and isn't visible to SSR. On the server, do not
 * block so a reload doesn't bounce to /docs before the client can apply the
 * override. The docs sidebar still enforces the real flag.
 */
export function shouldBlockZeroDocs(): boolean {
  if (isZeroDocsEnabled()) return false
  if (typeof window === 'undefined') return false
  return true
}
