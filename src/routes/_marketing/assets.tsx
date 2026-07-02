import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { View } from '@/components/pages/assets/View'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/assets')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Assets',
      description:
        "Appwrite's key brand assets including the logotype, colors, product visuals, and practical guidelines for their usage.",
    }),
  }),
  loader: async ({ context }) => {
  },
  component: AssetsPage,
})

function AssetsPage() {
  return (<View />
    )
}
