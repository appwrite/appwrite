import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { getFactoryHomePageHead, View } from '@/components/pages/home/View'
import { prefetchVisitorCountry } from '@/lib/react-query/hooks/locale'

/** Homepage variant with the 3D software factory hero. */
export const Route = createFileRoute('/_marketing/home1')({
  ...marketingRouteLifetime,
  staticData: {
    ...MARKETING_PAGE_ROUTE_STATIC_DATA,
    headerBanner: 'init-org-promo',
  },
  ssr: true,
  head: getFactoryHomePageHead,
  loader: async ({ context }) => {
    await prefetchVisitorCountry(context.queryClient)
  },
  component: FactoryHomePage,
})

function FactoryHomePage() {
  return <View variant="factory" />
}
