/**
 * Marketing routes prerendered at build time (FOR_SITES production builds).
 * Console, auth, and docs stay SSR/SPA at runtime.
 *
 * Node-only: do not import this module from client components.
 */
import { getBlogPrerenderPaths, getBlogPrerenderPathsFromClient, isBlogPrerenderPath } from '../blog/prerender-paths'
import {
  getChangelogEntryPrerenderPaths,
  getChangelogEntryPrerenderPathsFromClient,
} from '../changelog/prerender-paths'
import { MARKETING_PAGE_PATHS } from './marketing-page-paths'

export const MARKETING_PRERENDER_PATHS = MARKETING_PAGE_PATHS

export function getAllMarketingPrerenderPaths(
  clientDirectory?: string,
): readonly string[] {
  const changelogPaths = clientDirectory
    ? getChangelogEntryPrerenderPathsFromClient(clientDirectory)
    : getChangelogEntryPrerenderPaths()
  const blogPaths = clientDirectory
    ? getBlogPrerenderPathsFromClient(clientDirectory)
    : getBlogPrerenderPaths()

  return [...MARKETING_PRERENDER_PATHS, ...changelogPaths, ...blogPaths]
}

export function isMarketingPrerenderPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  if ((MARKETING_PRERENDER_PATHS as readonly string[]).includes(normalized)) {
    return true
  }

  if (normalized.startsWith('/changelog/entry/')) {
    return true
  }

  return isBlogPrerenderPath(normalized)
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
