import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/zero/View'
import {
  getMarketingFaqScript,
  getMarketingRouteHead,
} from '@/lib/marketing/route-meta'
import { zeroFaqItems, zeroHero } from '@/lib/zero/marketing-content'

export const Route = createFileRoute('/_marketing/zero')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/zero',
      pageName: 'Appwrite Zero',
      description: zeroHero.description,
      ogImageEyebrow: 'Appwrite Zero',
      ogImageTitle: zeroHero.title,
      ogImageSubtitle: zeroHero.description,
    })
    return {
      ...seo,
      scripts: [getMarketingFaqScript(zeroFaqItems)],
    }
  },
  component: ZeroPage,
})

function ZeroPage() {
  return <View />
}
