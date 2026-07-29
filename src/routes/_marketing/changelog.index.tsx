import { createFileRoute } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { ChangelogSeenSync } from '@/components/pages/changelog/ChangelogSeenSync'
import { View } from '@/components/pages/changelog/View'
import { getChangelogEntriesPage } from '@/lib/changelog/content'
import { CHANGELOG_DEFAULT_DESCRIPTION } from '@/lib/changelog/seo'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'
import { CHANGELOG_RSS_PATH } from '@/lib/seo/rss'

export const Route = createFileRoute('/_marketing/changelog/')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  head: () => ({
    meta: getMarketingPageMetaTags({
      pageName: 'Changelog',
      description: CHANGELOG_DEFAULT_DESCRIPTION,
      ogImageEyebrow: 'Changelog',
    }),
    links: [
      {
        rel: 'alternate',
        type: 'application/rss+xml',
        title: 'Appwrite Changelog',
        href: CHANGELOG_RSS_PATH,
      },
    ],
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
