import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/partners/View'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_marketing/partners')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/partners',
      pageName: 'Partners',
      description:
        'Join the Appwrite Partners program and grow your business. Deliver powerful solutions to clients, increase revenue, and expand your reach.',
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: PartnersPage,
})

function PartnersPage() {
  return (<View />
    )
}
