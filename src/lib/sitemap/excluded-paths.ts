/**
 * Console, auth, and internal paths that must never appear in the sitemap.
 * Sitemap providers use allowlists; this module validates and documents exclusions.
 */

/** Exact paths that are not public marketing content. */
export const SITEMAP_EXCLUDED_EXACT_PATHS = [
  '/',
  '/sign-in',
  '/sign-up',
  '/sign-out',
  '/recovery',
  '/reset',
  '/join',
  '/mfa',
  '/verify-email',
  '/init',
  '/comps',
  '/blocks',
  '/cache',
  '/domains/continue',
  '/llms/txt',
  '/llms-full/txt',
  '/llms.txt',
  '/llms-full.txt',
  '/docs/llms.txt',
  '/docs.md',
  '/blog.md',
  '/changelog.md',
  '/integrations.md',
  '/robots.txt',
  '/cli/install.sh',
  '/cli/install.ps1',
  '/.well-known/mcp/server-card.json',
  '/.well-known/ai-catalog.json',
  '/.well-known/agent-skills/index.json',
  '/upgrade',
  '/r/v.js',
  '/r/e',
] as const

/** Path prefixes for authenticated console areas and non-indexable routes. */
export const SITEMAP_EXCLUDED_PATH_PREFIXES = [
  '/projects/',
  '/organizations/',
  '/account/',
  '/_api/',
  '/_protected/',
  '/debug/',
] as const

export function normalizeSitemapPath(pathname: string): string {
  const withoutQuery = pathname.split('?')[0]?.split('#')[0] ?? '/'
  const normalized = withoutQuery.replace(/\/+$/, '') || '/'
  return normalized
}

export function isExcludedFromSitemap(pathname: string): boolean {
  const normalized = normalizeSitemapPath(pathname)

  if (
    (SITEMAP_EXCLUDED_EXACT_PATHS as readonly string[]).includes(normalized)
  ) {
    return true
  }

  return SITEMAP_EXCLUDED_PATH_PREFIXES.some((prefix) =>
    normalized.startsWith(prefix),
  )
}

export function assertSitemapEligible(pathname: string): void {
  if (isExcludedFromSitemap(pathname)) {
    throw new Error(`Path is excluded from sitemap: ${pathname}`)
  }
}
