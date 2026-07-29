import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithClientStaticFile } from '@/lib/marketing/static-exports'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import { trackServerPageview } from '@/lib/server-analytics'

/** Canonical llms.txt endpoint (https://llmstxt.org). */
export const Route = createFileRoute('/llms.txt')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)

        // Prefer the prebuilt export when available (production builds).
        if (process.env.NODE_ENV === 'production') {
          const prebuilt = await respondWithClientStaticFile(
            'llms.txt',
            'text/markdown; charset=utf-8',
          )
          if (prebuilt.status !== 404) return prebuilt
        }

        return new Response(generateLlmsTxt(), {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
})
