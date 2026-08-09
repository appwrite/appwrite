import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/affiliates/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/affiliates')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Affiliates',
      description:
        'Earn Appwrite Cloud credits by referring developers. Join the Affiliates program, share invite links, and get rewarded when referrals upgrade to Pro.',
    }),
  }),
  loader: async ({ context }) => {},
  component: AffiliatesPage,
})

function AffiliatesPage() {
  return <View />
}
