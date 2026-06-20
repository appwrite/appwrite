import { View, marketplaceSearchSchema } from '@/components/pages/organizations/$orgId/marketplace/View'
import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'
import {
  marketplaceCatalogQueryOptions,
  organizationAppsQueryOptions,
} from '@/lib/react-query/hooks'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/marketplace/',
)({
  head: () => ({ meta: [{ title: pageTitle('Marketplace', 'Organization') }] }),
  validateSearch: marketplaceSearchSchema,
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return
    const { orgId } = params
    const { queryClient } = context
    if (!orgId) return

    await Promise.all([
      queryClient.ensureQueryData(organizationAppsQueryOptions(orgId)),
      queryClient.ensureQueryData(marketplaceCatalogQueryOptions(orgId)),
    ])
  },
  component: MarketplaceIndexPage,
})

function MarketplaceIndexPage() {
  return <View />
}
