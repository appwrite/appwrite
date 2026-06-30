import { createFileRoute, notFound } from '@tanstack/react-router'
import { MARKETING_PAGE_ROUTE_STATIC_DATA } from '@/lib/marketing/route-static-data'
import { ChangelogSeenSync } from '@/components/pages/changelog/ChangelogSeenSync'
import { DetailView } from '@/components/pages/changelog/DetailView'
import { getChangelogEntry } from '@/lib/changelog/content'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { getMarketingPageMetaTags } from '@/lib/marketing/route-meta'

const CHANGELOG_OG_FALLBACK_SUBTITLE =
  "Explore Appwrite's changelog to stay on top of all the product updates and track our journey."

export const Route = createFileRoute('/changelog/entry/$entry')({
  staticData: MARKETING_PAGE_ROUTE_STATIC_DATA,
  ssr: true,
  loader: async ({ context, params }) => {
    await marketingPageLoader(context.queryClient)

    const entry = getChangelogEntry(params.entry)
    if (!entry) {
      throw notFound()
    }

    return { entry }
  },
  head: ({ loaderData }) => {
    const entryTitle = loaderData?.entry.title ?? 'Changelog'
    const entryDescription = loaderData?.entry.description?.trim()
    const ogImageSubtitle =
      entryDescription && entryDescription !== entryTitle
        ? entryDescription
        : CHANGELOG_OG_FALLBACK_SUBTITLE

    return {
      meta: getMarketingPageMetaTags({
        pageName: entryTitle,
        description:
          entryDescription ?? CHANGELOG_OG_FALLBACK_SUBTITLE,
        ogImageTitle: entryTitle,
        ogImageSubtitle,
        ogImageEyebrow: 'Changelog',
        ogType: 'article',
      }),
    }
  },
  component: ChangelogEntryPage,
})

function ChangelogEntryPage() {
  const { entry } = Route.useLoaderData()

  return (
    <MarketingPageShell>
      <ChangelogSeenSync />
      <DetailView entry={entry} />
    </MarketingPageShell>
  )
}
