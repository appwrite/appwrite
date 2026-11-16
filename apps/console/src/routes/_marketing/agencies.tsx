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
import { agenciesPage } from '@/lib/solutions/content'

export const Route = createFileRoute('/_marketing/agencies')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/agencies',
      pageName: 'Agencies',
      description:
        'Ship client projects on one Appwrite stack. A project per client, the same Console every time, and a handoff the client can run.',
    })
    return { ...seo, scripts: [getMarketingFaqScript(agenciesPage.faq.items)] }
  },
  loader: async () => {},
  component: AgenciesPage,
})

function AgenciesPage() {
  return <View page={agenciesPage} />
}
