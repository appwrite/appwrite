import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { buildPricingMarkdown } from '@/lib/pricing/markdown'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { trackServerPageview } from '@/lib/server-analytics'

/** Markdown twin of /pricing for agents comparing plans. */
export const Route = createFileRoute('/pricing.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'pricing.md',
          'text/markdown; charset=utf-8',
          () => buildPricingMarkdown(),
        )
      },
    },
  },
})
