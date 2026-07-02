import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/enterprise/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/enterprise')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Enterprise',
      description:
        "Want to learn more about Appwrite's Enterprise plan? Contact our team for custom resources, premium support, and advanced security features.",
    }),
  }),
  loader: async ({ context }) => {
  },
  component: EnterprisePage,
})

function EnterprisePage() {
  return (<View />
    )
}
