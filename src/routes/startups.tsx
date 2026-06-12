import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/startups/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/startups')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Startups') },
      {
        name: 'description',
        content:
          "Get cloud credits to fulfill all your startup's backend and hosting needs. Apply for Appwrite's Startups Program today.",
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: StartupsPage,
})

function StartupsPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
