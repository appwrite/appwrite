import { View, marketplaceSearchSchema } from '@/components/pages/organizations/$orgId/marketplace/View'
import { createFileRoute } from '@tanstack/react-router'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute(
  '/_public/organizations/$orgId/marketplace/',
)({
  head: () => ({ meta: [{ title: pageTitle('Marketplace', 'Organization') }] }),
  validateSearch: marketplaceSearchSchema,
  component: MarketplaceIndexPage,
})

function MarketplaceIndexPage() {
  return <View />
}
