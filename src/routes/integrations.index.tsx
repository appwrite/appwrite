import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/integrations/View'
import { getFilteredIntegrationsCatalog } from '@/lib/integrations/content'
import { getIntegrationsIndexRouteMetaTags } from '@/lib/integrations/route-meta'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'

const integrationsSearchSchema = z.object({
  search: z.string().optional(),
  category: z.string().optional(),
  platform: z.string().optional(),
})

export const Route = createFileRoute('/integrations/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  validateSearch: integrationsSearchSchema,
  loader: async ({ context, location }) => {
    await marketingPageLoader(context.queryClient)
    const search = integrationsSearchSchema.parse(location.search)
    return getFilteredIntegrationsCatalog(search)
  },
  head: () => ({
    meta: getIntegrationsIndexRouteMetaTags(),
  }),
  component: IntegrationsIndexPage,
})

function IntegrationsIndexPage() {
  const catalog = Route.useLoaderData()
  const search = Route.useSearch()

  return (
    <MarketingPageShell>
      <View {...catalog} search={search} />
    </MarketingPageShell>
  )
}
