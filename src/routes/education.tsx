import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/education/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/education')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Education',
      description:
        'Students can expand their skillset without spending a penny. Sign up for the Appwrite Education program to get access to our Pro plan.',
    }),
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: EducationPage,
})

function EducationPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
