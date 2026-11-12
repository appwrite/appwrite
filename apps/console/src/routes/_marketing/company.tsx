import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/company/View'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/company')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/company',
      pageName: 'Company',
      description:
        'At Appwrite, we remove technical barriers so developers and agents can build products the world loves. Learn about our mission, team, and investors.',
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: CompanyPage,
})

function CompanyPage() {
  return (<View />
    )
}
