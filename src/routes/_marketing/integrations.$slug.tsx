import { createFileRoute, notFound } from '@tanstack/react-router'
import { DetailView } from '@/components/pages/integrations/DetailView'
import {
  getIntegration,
  getIntegrationMarkdownExport,
} from '@/lib/integrations/content'
import { getIntegrationDetailRouteMetaTags } from '@/lib/integrations/route-meta'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { trackServerPageview } from '@/lib/server-analytics'

export const Route = createFileRoute('/_marketing/integrations/$slug')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  server: {
    handlers: {
      GET: async ({ params, request, next }) => {
        const slug = params.slug
        if (!slug.endsWith('.md')) {
          return next()
        }

        const markdown = getIntegrationMarkdownExport(slug.slice(0, -3))
        if (!markdown) {
          return new Response('Not found', { status: 404 })
        }

        trackServerPageview(request)

        return new Response(markdown, {
          headers: {
            'Content-Type': 'text/markdown; charset=utf-8',
            'Cache-Control': 'public, max-age=3600',
          },
        })
      },
    },
  },
  loader: async ({ context, params }) => {

    if (params.slug.endsWith('.md')) {
      throw notFound()
    }

    const integration = getIntegration(params.slug)
    if (!integration) {
      throw notFound()
    }

    return { integration }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.integration) return {}
    return {
      meta: getIntegrationDetailRouteMetaTags(loaderData.integration),
      links: [
        {
          rel: 'alternate',
          type: 'text/markdown',
          href: `${loaderData.integration.href}.md`,
        },
      ],
    }
  },
  component: IntegrationDetailPage,
})

function IntegrationDetailPage() {
  const { integration } = Route.useLoaderData()

  return (<DetailView integration={integration} />
    )
}
