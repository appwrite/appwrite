import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { buildAuthMarkdown } from '@/lib/seo/auth-markdown'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { trackServerPageview } from '@/lib/server-analytics'

/** How agents get credentials for Appwrite (auth.md convention). */
export const Route = createFileRoute('/auth.md')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request)
        return respondWithPrebuiltOrRuntime(
          'auth.md',
          'text/markdown; charset=utf-8',
          () => buildAuthMarkdown(),
        )
      },
    },
  },
})
