import { createFileRoute, notFound } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { ChangelogSeenSync } from '@/components/pages/changelog/ChangelogSeenSync'
import { DetailView } from '@/components/pages/changelog/DetailView'
import { getChangelogEntry } from '@/lib/changelog/content'
import {
  getChangelogEntryMetaTags,
  getChangelogEntrySchema,
} from '@/lib/changelog/seo'
import { getRequestSiteOrigin } from '@/lib/marketing/site-origin'
import { CHANGELOG_RSS_PATH } from '@/lib/seo/rss'

export const Route = createFileRoute('/_marketing/changelog/entry/$entry')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {

    const entry = getChangelogEntry(params.entry)
    if (!entry) {
      throw notFound()
    }

    return { entry }
  },
  head: ({ loaderData }) => {
    if (!loaderData?.entry) return {}

    return {
      meta: getChangelogEntryMetaTags(loaderData.entry, {
        siteOrigin: getRequestSiteOrigin(),
      }),
      links: [
        {
          rel: 'alternate',
          type: 'application/rss+xml',
          title: 'Appwrite Changelog',
          href: CHANGELOG_RSS_PATH,
        },
      ],
      scripts: [
        {
          type: 'application/ld+json',
          children: JSON.stringify(getChangelogEntrySchema(loaderData.entry)),
        },
      ],
    }
  },
  component: ChangelogEntryPage,
})

function ChangelogEntryPage() {
  const { entry } = Route.useLoaderData()

  return (
    <>
      <ChangelogSeenSync />
      <DetailView entry={entry} />
    </>
  )
}
