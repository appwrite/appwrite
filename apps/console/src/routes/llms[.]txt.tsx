import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import { trackServerPageview } from '@/lib/server-analytics'

/** Canonical curated llms.txt hub (https://llmstxt.org). */
export const Route = createFileRoute('/llms.txt')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'llms.txt',
          'text/markdown; charset=utf-8',
          () => generateLlmsTxt(),
        )
      },
    },
  },
})
