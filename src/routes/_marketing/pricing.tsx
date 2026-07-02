import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/pricing/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import {
  isPricingHashTarget,
  resetPricingPageScrollContainers,
} from '@/lib/pricing/comparison-scroll'

export const Route = createFileRoute('/_marketing/pricing')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Pricing',
      description:
        'All your cloud services under one subscription. Build, deploy, and observe your app from a unified stack under one subscription.',
    }),
  }),
  loader: async ({ context }) => {
    if (typeof window !== 'undefined') {
      const hash = window.location.hash.slice(1)
      if (hash && isPricingHashTarget(hash)) {
        history.scrollRestoration = 'manual'
        resetPricingPageScrollContainers(true)
      }
    }

  },
  component: PricingPage,
})

function PricingPage() {
  return <View />
}
