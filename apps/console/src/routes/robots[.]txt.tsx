import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { getProductionRobotsTxt } from '@/lib/seo/robots'
import { trackServerPageview } from '@/lib/server-analytics'

/** Production robots.txt (non-indexable hosts are handled by seoIndexingMiddleware). */
export const Route = createFileRoute('/robots.txt')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request, { format: 'text' })
        return respondWithPrebuiltOrRuntime(
          'robots.txt',
          'text/plain; charset=utf-8',
          () => getProductionRobotsTxt(),
        )
      },
    },
  },
})
