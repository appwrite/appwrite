import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/partners/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/partners')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Partners') },
      {
        name: 'description',
        content:
          'Join the Appwrite Partners program and grow your business. Deliver powerful solutions to clients, increase revenue, and expand your reach.',
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: PartnersPage,
})

function PartnersPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
