import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { ChangelogSeenSync } from '@/components/pages/changelog/ChangelogSeenSync'
import { View } from '@/components/pages/changelog/View'
import { getChangelogEntriesPage } from '@/lib/changelog/content'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

export const Route = createFileRoute('/_marketing/changelog/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Changelog',
      description:
        "Explore Appwrite's changelog to stay on top of all the product updates and track our journey.",
      ogImageEyebrow: 'Changelog',
    }),
  }),
  loader: async ({ context }) => {
    return getChangelogEntriesPage(1)
  },
  component: ChangelogPage,
})

function ChangelogPage() {
  const { entries, nextPage } = Route.useLoaderData()

  return (
    <>
      <ChangelogSeenSync />
      <View entries={entries} nextPage={nextPage} />
    </>
  )
}
