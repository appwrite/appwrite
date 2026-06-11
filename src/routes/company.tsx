import { createFileRoute } from '@tanstack/react-router'
import { View } from '@/components/pages/company/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { marketingPageLoader } from '@/lib/marketing/route-loader'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/company')({
  ssr: true,
  head: () => ({
    meta: [
      { title: pageTitle('Company') },
      {
        name: 'description',
        content:
          'At Appwrite, we remove technical barriers so developers and agents can build products the world loves. Learn about our mission, team, and investors.',
      },
    ],
  }),
  loader: async ({ context }) => {
    await marketingPageLoader(context.queryClient)
  },
  component: CompanyPage,
})

function CompanyPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
