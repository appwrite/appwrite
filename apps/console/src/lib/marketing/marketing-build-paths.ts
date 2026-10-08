/**
 * Marketing routes built as static HTML at build time (FOR_SITES production builds).
 * Console, auth, and docs stay SSR/SPA at runtime.
 *
 * Node-only: do not import this module from client components.
 */
import { getBlogPrerenderPaths, getBlogPrerenderPathsFromClient, isBlogPrerenderPath } from '../blog/prerender-paths'
import {
  getChangelogEntryPrerenderPaths,
  getChangelogEntryPrerenderPathsFromClient,
} from '../changelog/prerender-paths'
import {
  getIntegrationPrerenderPaths,
  isIntegrationPrerenderPath,
} from '../integrations/prerender-paths'
import { isThreadsRoutePath } from '../threads/prerender-paths'
import { MARKETING_PAGE_PATHS } from './marketing-page-paths'
import {
  getSitesPrerenderScope,
  sitesPrerenderIncludesBlogPosts,
  sitesPrerenderIncludesChangelogEntries,
  sitesPrerenderIncludesThreads,
} from './sites-prerender-scope'

export const MARKETING_PRERENDER_PATHS = MARKETING_PAGE_PATHS

export function getAllMarketingPrerenderPaths(
  clientDirectory?: string,
): readonly string[] {
  const changelogPaths = clientDirectory
    ? getChangelogEntryPrerenderPathsFromClient(clientDirectory)
    : sitesPrerenderIncludesChangelogEntries()
      ? getChangelogEntryPrerenderPaths()
      : []
  const blogPaths = clientDirectory
    ? getBlogPrerenderPathsFromClient(clientDirectory)
    : getBlogPrerenderPaths({
        includePosts: sitesPrerenderIncludesBlogPosts(),
      })
  const integrationPaths = getIntegrationPrerenderPaths()

  return [
    ...new Set([
      ...MARKETING_PRERENDER_PATHS,
      ...changelogPaths,
      ...blogPaths,
      ...integrationPaths,
    ]),
  ]
}

export function isMarketingPrerenderPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'

  if (isThreadsRoutePath(normalized)) {
    return sitesPrerenderIncludesThreads()
  }

  if ((MARKETING_PRERENDER_PATHS as readonly string[]).includes(normalized)) {
    return true
  }

  if (normalized.startsWith('/changelog/entry/')) {
    return sitesPrerenderIncludesChangelogEntries()
  }

  if (normalized.startsWith('/blog/post/')) {
    return sitesPrerenderIncludesBlogPosts()
  }

  if (isBlogPrerenderPath(normalized)) return true

  return isIntegrationPrerenderPath(normalized)
}

export function getSitesPrerenderBuildSummary(): string {
  const scope = getSitesPrerenderScope()
  const paths = getAllMarketingPrerenderPaths()
  return `SITES prerender scope=${scope} pages=${String(paths.length)}`
}

/** Built marketing HTML pages (excludes llms txt exports). */
export function isMarketingHtmlPrerenderPath(path: string): boolean {
  const normalized = path.replace(/\/+$/, '') || '/'
  return isMarketingPrerenderPath(normalized) && !normalized.endsWith('/txt')
}

/** File under dist/client for a built marketing URL (e.g. /home -> home.html). */
export function getMarketingPrerenderHtmlFile(urlPath: string): string | null {
  if (!isMarketingHtmlPrerenderPath(urlPath)) return null
  const segment = urlPath.replace(/^\//, '')
  return `${segment}.html`
}
