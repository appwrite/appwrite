/**
 * Paths that must not appear in Google Search (console, auth, internal).
 * Kept in sync with `Disallow` rules in {@link getProductionRobotsTxt}.
 */

const NON_INDEXABLE_EXACT_PATHS = new Set([
  '/sign-in',
  '/sign-up',
  '/sign-out',
  '/recovery',
  '/reset',
  '/join',
  '/mfa',
  '/verify-email',
  '/comps',
  '/blocks',
  '/cache',
])

const NON_INDEXABLE_PREFIXES = [
  '/projects/',
  '/organizations/',
  '/account/',
  '/debug/',
  '/_protected/',
] as const

function normalizePathname(pathname: string): string {
  return pathname.replace(/\/+$/, '') || '/'
}

export function isSearchNonIndexablePath(pathname: string): boolean {
  const normalized = normalizePathname(pathname)
  if (NON_INDEXABLE_EXACT_PATHS.has(normalized)) return true
  return NON_INDEXABLE_PREFIXES.some((prefix) => normalized.startsWith(prefix))
}
