import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/startups/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/startups')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Startups',
      description:
        "Get cloud credits to fulfill all your startup's backend and hosting needs. Apply for Appwrite's Startups Program today.",
    }),
  }),
  loader: async ({ context }) => {
  },
  component: StartupsPage,
})

function StartupsPage() {
  return (<View />
    )
}
