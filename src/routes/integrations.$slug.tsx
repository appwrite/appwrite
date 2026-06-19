import { createFileRoute, notFound } from '@tanstack/react-router'
import { DetailView } from '@/components/pages/integrations/DetailView'
import { getIntegration } from '@/lib/integrations/content'
import { getIntegrationDetailRouteMetaTags } from '@/lib/integrations/route-meta'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

export const Route = createFileRoute('/integrations/$slug')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {
    await marketingPageLoader(context.queryClient)

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

  return (
    <MarketingPageShell>
      <DetailView integration={integration} />
    </MarketingPageShell>
  )
}
