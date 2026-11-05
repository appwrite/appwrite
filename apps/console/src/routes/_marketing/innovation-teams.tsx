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
import { innovationTeamsPage } from '@/lib/solutions/content'

export const Route = createFileRoute('/_marketing/innovation-teams')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/innovation-teams',
      pageName: 'Innovation teams',
      description:
        'Stand up a pilot on Appwrite Cloud. If it sticks, the same APIs run on an Enterprise plan, including a self-hosted edition.',
    })
    return { ...seo, scripts: [getMarketingFaqScript(innovationTeamsPage.faq.items)] }
  },
  loader: async () => {},
  component: InnovationTeamsPage,
})

function InnovationTeamsPage() {
  return <View page={innovationTeamsPage} />
}
