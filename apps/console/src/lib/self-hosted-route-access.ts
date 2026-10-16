import {
  isConsoleAreaPath,
  isConsoleAuthPath,
} from '@/lib/marketing/marketing-route-shell'

function normalizePath(pathname: string | null | undefined): string {
  return (pathname ?? '/').replace(/\/+$/, '') || '/'
}

/**
 * Routes a self-hosted console may render. Everything else (marketing, docs,
 * blog, changelog) belongs on appwrite.io.
 * `/` stays open so it can hop guests to sign-in and signed-in users into
 * the console without a redirect loop.
 */
export function isSelfHostedAllowedPath(
  pathname: string | null | undefined,
): boolean {
  const path = normalizePath(pathname)
  if (path === '/') return true
  if (isConsoleAuthPath(path)) return true
  if (isConsoleAreaPath(path)) return true
  if (path === '/debug' || path.startsWith('/debug/')) return true
  if (path === '/reset') return true
  return false
}
