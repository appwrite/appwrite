import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/solutions/View'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import {
  getMarketingFaqScript,
  getMarketingRouteHead,
} from '@/lib/marketing/route-meta'
import { startupsPage } from '@/lib/solutions/content'

export const Route = createFileRoute('/_marketing/startups')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/startups',
      pageName: 'Startups',
      description:
        'Auth, databases, storage, functions, and hosting for startups. Start on the Free plan and move to Pro when the product has users.',
    })
    return { ...seo, scripts: [getMarketingFaqScript(startupsPage.faq.items)] }
  },
  loader: async () => {},
  component: StartupsPage,
})

function StartupsPage() {
  return <View page={startupsPage} />
}
