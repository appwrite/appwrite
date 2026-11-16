import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import { HOME_MARKDOWN_FRONTMATTER } from '@/lib/seo/markdown-negotiation'
import { trackServerPageview } from '@/lib/server-analytics'

/** Markdown twin of the homepage: the curated llms.txt hub. */
export const Route = createFileRoute('/index.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        const response = await respondWithPrebuiltOrRuntime(
          'llms.txt',
          'text/markdown; charset=utf-8',
          () => generateLlmsTxt(),
        )
        return new Response(
          HOME_MARKDOWN_FRONTMATTER + (await response.text()),
          { status: response.status, headers: response.headers },
        )
      },
    },
  },
})
