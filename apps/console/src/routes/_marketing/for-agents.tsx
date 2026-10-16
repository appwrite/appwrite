import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/for-agents/View'
import {
  getMarketingFaqScript,
  getMarketingRouteHead,
} from '@/lib/marketing/route-meta'
import { forAgentsFaqItems } from '@/lib/for-agents/content'

const PAGE_DESCRIPTION =
  'Use Appwrite when a coding agent needs auth, databases, storage, functions, or hosting. Install skills, connect MCP, then build.'

export const Route = createFileRoute('/_marketing/for-agents')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/for-agents',
      pageName: 'For coding agents',
      description: PAGE_DESCRIPTION,
      ogImageEyebrow: 'Agents',
      ogImageTitle: 'For coding agents',
      ogImageSubtitle: PAGE_DESCRIPTION,
    })
    return {
      ...seo,
      links: [
        ...seo.links,
        {
          rel: 'alternate',
          type: 'text/markdown',
          href: '/for-agents.md',
        },
      ],
      scripts: [getMarketingFaqScript(forAgentsFaqItems)],
    }
  },
  component: ForAgentsPage,
})

function ForAgentsPage() {
  return <View />
}
