/**
 * Threads routes are always SSR at runtime (index, detail, authors).
 * Do not add thread URLs to marketing prerender paths - ~14k thread pages
 * would bloat the Sites artifact and build cache without meaningful benefit.
 */

export function isThreadsRoutePath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return normalized === '/threads' || normalized.startsWith('/threads/')
}

/** Threads are never built as static HTML, even when SITES_PRERENDER_SCOPE=full. */
export function isThreadPrerenderPath(pathname: string): boolean {
  return false
}
