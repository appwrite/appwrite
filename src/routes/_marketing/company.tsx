import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/company/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/company')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Company',
      description:
        'At Appwrite, we remove technical barriers so developers and agents can build products the world loves. Learn about our mission, team, and investors.',
    }),
  }),
  loader: async ({ context }) => {
  },
  component: CompanyPage,
})

function CompanyPage() {
  return (<View />
    )
}
