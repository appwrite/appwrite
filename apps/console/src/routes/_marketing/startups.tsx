import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/startups/View'
import {
  getMarketingFaqScript,
  getMarketingRouteHead,
} from '@/lib/marketing/route-meta'
import { startupsFaqItems } from '@/lib/startups/content'

export const Route = createFileRoute('/_marketing/startups')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/startups',
      pageName: 'Startups',
      description:
        "Get cloud credits to fulfill all your startup's backend and hosting needs. Apply for Appwrite's Startups Program today.",
    });
    return { ...seo, scripts: [getMarketingFaqScript(startupsFaqItems)] };
  },
  loader: async ({ context }) => {
  },
  component: StartupsPage,
})

function StartupsPage() {
  return (<View />
    )
}
