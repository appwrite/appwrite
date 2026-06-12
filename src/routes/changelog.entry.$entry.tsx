import { createFileRoute, notFound } from '@tanstack/react-router'
import { ChangelogSeenSync } from '@/components/pages/changelog/ChangelogSeenSync'
import { DetailView } from '@/components/pages/changelog/DetailView'
import { getChangelogEntry } from '@/lib/changelog/content'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/changelog/entry/$entry')({
  ssr: true,
  loader: async ({ context, params }) => {
    await marketingPageLoader(context.queryClient)

    const entry = getChangelogEntry(params.entry)
    if (!entry) {
      throw notFound()
    }

    return { entry }
  },
  head: ({ loaderData }) => ({
    meta: [
      { title: pageTitle(loaderData?.entry.title ?? 'Changelog') },
      {
        name: 'description',
        content:
          loaderData?.entry.description ??
          "Explore Appwrite's changelog to stay on top of all the product updates and track our journey.",
      },
    ],
  }),
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
