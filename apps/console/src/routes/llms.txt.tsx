import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { generateLlmsTxt } from '@/lib/seo/llms-content'
import { respondWithClientStaticFile } from '@/lib/marketing/static-exports'
import { trackServerPageview } from '@/lib/server-analytics'

export const Route = createFileRoute('/llms/txt')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)

        if (process.env.TSS_PRERENDERING === 'true') {
          return new Response(generateLlmsTxt(), {
            headers: {
              'Content-Type': 'text/markdown; charset=utf-8',
              'Cache-Control': 'public, max-age=3600',
            },
          })
        }

        const fromNested = await respondWithClientStaticFile(
          'llms/txt',
          'text/markdown; charset=utf-8',
        )
        if (fromNested.status !== 404) return fromNested

        return respondWithClientStaticFile(
          'llms.txt',
          'text/markdown; charset=utf-8',
        )
      },
    },
  },
})
