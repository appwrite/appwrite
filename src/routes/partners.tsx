import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/partners/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/partners')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Partners',
      description:
        'Join the Appwrite Partners program and grow your business. Deliver powerful solutions to clients, increase revenue, and expand your reach.',
    }),
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: PartnersPage,
})

function PartnersPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
