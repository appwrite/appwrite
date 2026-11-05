import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { getHomePageHead, View } from '@/components/pages/home/View'
import { prefetchVisitorCountry } from '@/lib/react-query/hooks/locale'

export const Route = createFileRoute('/_marketing/home')({
  ...marketingRouteLifetime,
  staticData: {
    ...MARKETING_PAGE_ROUTE_STATIC_DATA,
    headerBanner: 'init-org-promo',
  },
  ssr: true,
  head: getHomePageHead,
  loader: async ({ context }) => {
    await prefetchVisitorCountry(context.queryClient)
  },
  component: View,
})
