import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/company/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/company')({
  ssr: false,
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
    if (typeof window === 'undefined') return

    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})
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
