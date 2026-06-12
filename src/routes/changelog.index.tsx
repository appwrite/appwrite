import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/changelog/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/changelog/')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Changelog') },
      {
        name: 'description',
        content:
          "Explore Appwrite's changelog to stay on top of all the product updates and track our journey.",
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: ChangelogPage,
})

function ChangelogPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
