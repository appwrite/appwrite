/**
 * Marketing routes prerendered at build time (FOR_SITES production builds).
 * Console, auth, and docs stay SSR/SPA at runtime.
 */
import {
  getChangelogEntryPrerenderPaths,
  getChangelogEntryPrerenderPathsFromClient,
} from '../changelog/prerender-paths'

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
  '/changelog',
  '/assets',
  '/llms/txt',
  '/llms-full/txt',
] as const

export function getAllMarketingPrerenderPaths(
  clientDirectory?: string,
): readonly string[] {
  const changelogPaths = clientDirectory
    ? getChangelogEntryPrerenderPathsFromClient(clientDirectory)
    : getChangelogEntryPrerenderPaths()

  return [...MARKETING_PRERENDER_PATHS, ...changelogPaths]
}

export function isMarketingPrerenderPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  if ((MARKETING_PRERENDER_PATHS as readonly string[]).includes(normalized)) {
    return true
  }

  return normalized.startsWith('/changelog/entry/')
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
