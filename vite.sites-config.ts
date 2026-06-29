/**
 * TanStack Start options for FOR_SITES production builds (marketing static HTML).
 *
 * Kept out of vite.config.ts so Appwrite's TanStackStart::getAdapter() scan
 * (vite.config.ts only) does not see `prerender` keys and classify the site as static.
 */
import {
  getAllMarketingPrerenderPaths,
  getSitesPrerenderBuildSummary,
  isMarketingPrerenderPath,
} from './src/lib/marketing/marketing-build-paths'
import { getSitesPrerenderConcurrency } from './src/lib/marketing/sites-prerender-scope'

export function getTanstackStartSitesOptions() {
  const prerenderConcurrency = getSitesPrerenderConcurrency()

  if (process.env.FOR_SITES === 'true') {
    console.log(`[sites] ${getSitesPrerenderBuildSummary()} concurrency=${String(prerenderConcurrency)}`)
  }

  return {
    prerender: {
      enabled: true,
      crawlLinks: false,
      concurrency: prerenderConcurrency,
      failOnError: true,
      filter: ({ path }: { path: string }) => isMarketingPrerenderPath(path),
    },
    pages: getAllMarketingPrerenderPaths().map((path) => ({
      path,
      prerender: { enabled: true },
    })),
  }
}
