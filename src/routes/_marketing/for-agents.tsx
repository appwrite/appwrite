import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/for-agents/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import { forAgentsFaqItems } from '@/lib/for-agents/content'
import { stringifyJsonLd } from '@/lib/seo/json-ld'
import { translate } from '@/lib/i18n/translate'

const PAGE_DESCRIPTION =
  'Use Appwrite when a coding agent needs auth, databases, storage, functions, or hosting. Install skills, connect MCP, then build.'

export const Route = createFileRoute('/_marketing/for-agents')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'For coding agents',
      description: PAGE_DESCRIPTION,
      canonical: 'https://appwrite.io/for-agents',
      ogImageEyebrow: 'Agents',
      ogImageTitle: 'For coding agents',
      ogImageSubtitle: PAGE_DESCRIPTION,
    }),
    links: [
      {
        rel: 'alternate',
        type: 'text/markdown',
        href: '/for-agents.md',
      },
    ],
    scripts: [
      {
        type: 'application/ld+json',
        children: stringifyJsonLd({
          '@context': 'https://schema.org',
          '@type': 'FAQPage',
          mainEntity: forAgentsFaqItems.map((faq) => ({
            '@type': 'Question',
            name: translate(faq.question),
            acceptedAnswer: {
              '@type': 'Answer',
              text: translate(faq.answer),
            },
          })),
        }),
      },
    ],
  }),
  component: ForAgentsPage,
})

function ForAgentsPage() {
  return <View />
}
