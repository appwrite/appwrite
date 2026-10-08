import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { buildForAgentsMarkdown } from '@/lib/for-agents/content'
import { trackServerPageview } from '@/lib/server-analytics'

/** Markdown twin of /for-agents for coding agents. */
export const Route = createFileRoute('/for-agents.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'for-agents.md',
          'text/markdown; charset=utf-8',
          () => buildForAgentsMarkdown(),
        )
      },
    },
  },
})
