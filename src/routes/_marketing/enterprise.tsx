import { createFileRoute } from '@tanstack/react-router'
import {
  MARKETING_PAGE_ROUTE_STATIC_DATA,
  marketingRouteLifetime,
} from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/enterprise/View'
import { getMarketingRouteHead } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/enterprise')({
  ...marketingRouteLifetime,
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => {
    const seo = getMarketingRouteHead({
      canonicalPath: '/enterprise',
      pageName: 'Enterprise',
      description:
        "Want to learn more about Appwrite's Enterprise plan? Contact our team for custom resources, premium support, and advanced security features.",
    });
    return seo;
  },
  loader: async ({ context }) => {
  },
  component: EnterprisePage,
})

function EnterprisePage() {
  return (<View />
    )
}
