import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/partners/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/partners')({
  ssr: false,
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
    if (typeof window === 'undefined') return

    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})
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
