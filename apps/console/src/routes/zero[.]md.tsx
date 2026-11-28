import { createFileRoute } from '@tanstack/react-router'
import { getProfileFeaturesForCookieHeader } from '@/lib/console-profiles'
import { isProductReleased } from '@/lib/products/availability'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { trackServerPageview } from '@/lib/server-analytics'
import { buildZeroMarkdown } from '@/lib/zero/markdown'

/** Markdown twin of /zero for coding agents. */
export const Route = createFileRoute('/zero.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        // Hidden until launch, like /zero.
        const features = getProfileFeaturesForCookieHeader(
          request.headers.get('cookie'),
        )
        if (!isProductReleased('zero', features)) {
          return new Response('Not found', { status: 404 })
        }
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'zero.md',
          'text/markdown; charset=utf-8',
          () => buildZeroMarkdown(),
        )
      },
    },
  },
})
