import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/enterprise/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/enterprise')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Enterprise',
      description:
        "Want to learn more about Appwrite's Enterprise plan? Contact our team for custom resources, premium support, and advanced security features.",
    }),
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: EnterprisePage,
})

function EnterprisePage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
