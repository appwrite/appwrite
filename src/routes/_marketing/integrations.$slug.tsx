import { createFileRoute, notFound } from '@tanstack/react-router'
import { DetailView } from '@/components/pages/integrations/DetailView'
import { getIntegration } from '@/lib/integrations/content'
import { getIntegrationDetailRouteMetaTags } from '@/lib/integrations/route-meta'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'

export const Route = createFileRoute('/_marketing/integrations/$slug')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

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
    }
  },
  component: IntegrationDetailPage,
})

function IntegrationDetailPage() {
  const { integration } = Route.useLoaderData()

  return (<DetailView integration={integration} />
    )
}
