import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { respondWithPrebuiltOrRuntime } from '@/lib/seo/export-response'
import { generateSitemapFiles } from '@/lib/sitemap'
import { trackServerPageview } from '@/lib/server-analytics'

/** Sitemap index. Section files live under `/sitemap/*.xml`. */
export const Route = createFileRoute('/sitemap.xml')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ request }) => {
        trackServerPageview(request, { format: 'text' })
        return respondWithPrebuiltOrRuntime(
          'sitemap.xml',
          'application/xml; charset=utf-8',
          async () => (await generateSitemapFiles()).indexXml,
        )
      },
    },
  },
})
