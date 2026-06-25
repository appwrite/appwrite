/**
 * TanStack Start options for FOR_SITES production builds (marketing static HTML).
 *
 * Kept out of vite.config.ts so Appwrite's TanStackStart::getAdapter() scan
 * (vite.config.ts only) does not see `prerender` keys and classify the site as static.
 */
import {
  getAllMarketingPrerenderPaths,
  isMarketingPrerenderPath,
} from './src/lib/marketing/marketing-build-paths'

export function getTanstackStartSitesOptions() {
  return {
    prerender: {
      enabled: true,
      crawlLinks: false,
      concurrency: 8,
      failOnError: true,
      filter: ({ path }: { path: string }) => isMarketingPrerenderPath(path),
    },
    pages: getAllMarketingPrerenderPaths().map((path) => ({
      path,
      prerender: { enabled: true },
    })),
  }
}
