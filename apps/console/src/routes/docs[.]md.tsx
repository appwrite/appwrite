import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateDocsMarkdownIndex } from '@/lib/seo/llms-content'
import { trackServerPageview } from '@/lib/server-analytics'

/** Top-level documentation Markdown index for agents. */
export const Route = createFileRoute('/docs.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'docs.md',
          'text/markdown; charset=utf-8',
          () => generateDocsMarkdownIndex(),
        )
      },
    },
  },
})
