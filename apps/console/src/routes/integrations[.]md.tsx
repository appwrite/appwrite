import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateIntegrationsMarkdownIndex } from '@/lib/seo/llms-content'
import { trackServerPageview } from '@/lib/server-analytics'

/** Integrations section Markdown index for agents. */
export const Route = createFileRoute('/integrations.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'integrations.md',
          'text/markdown; charset=utf-8',
          () => generateIntegrationsMarkdownIndex(),
        )
      },
    },
  },
})
