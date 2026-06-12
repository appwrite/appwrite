/**
 * Marketing routes prerendered at build time (FOR_SITES production builds).
 * Console, auth, and docs stay SSR/SPA at runtime.
 */
export const MARKETING_PRERENDER_PATHS = [
  '/home',
  '/pricing',
  '/privacy',
  '/terms',
  '/cookies',
  '/baa',
  '/company',
  '/startups',
  '/education',
  '/partners',
  '/community',
  '/assets',
  '/llms/txt',
  '/llms-full/txt',
] as const

export function isMarketingPrerenderPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  return (MARKETING_PRERENDER_PATHS as readonly string[]).includes(normalized)
}

/** Prerendered HTML pages (excludes llms txt exports). */
export function isMarketingHtmlPrerenderPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  return isMarketingPrerenderPath(normalized) && !normalized.endsWith('/txt')
}

/** File under dist/client for a prerendered marketing URL (e.g. /home -> home.html). */
export function getMarketingPrerenderHtmlFile(urlPath: string): string | null {
  if (!isMarketingHtmlPrerenderPath(urlPath)) return null
  const segment = urlPath.replace(/^\//, '')
  return `${segment}.html`
}
