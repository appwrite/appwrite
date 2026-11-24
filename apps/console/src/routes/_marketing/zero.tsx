import { createFileRoute, notFound } from '@tanstack/react-router'
import { isProductReleased } from '@/lib/products/availability'
import { getProductPageFeatures } from '@/lib/products/availability-ssr'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/zero/View'
import {
  getMarketingFaqScript,
  getMarketingRouteHead,
} from '@/lib/marketing/route-meta'
import { zeroFaqItems, zeroMeta } from '@/lib/zero/marketing-content'

export const Route = createFileRoute('/_marketing/zero')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  // Hidden until launch: 404 while the `zero` feature is off.
  beforeLoad: () => {
    if (!isProductReleased('zero', getProductPageFeatures())) throw notFound()
  },
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/zero',
      pageName: 'Appwrite Zero',
      description: zeroMeta.description,
      ogImageEyebrow: 'Appwrite Zero',
      ogImageTitle: zeroMeta.title,
      ogImageSubtitle: zeroMeta.description,
    })
    return {
      ...seo,
      links: [
        ...seo.links,
        {
          rel: 'alternate',
          type: 'text/markdown',
          href: '/zero.md',
        },
      ],
      scripts: [getMarketingFaqScript(zeroFaqItems)],
    }
  },
  component: ZeroPage,
})

function ZeroPage() {
  return <View />
}
