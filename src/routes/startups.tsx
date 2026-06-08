import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/startups/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/startups')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Startups') },
      {
        name: 'description',
        content:
          "Get cloud credits to fulfill all your startup's backend and hosting needs. Apply for Appwrite's Startups Program today.",
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
  component: StartupsPage,
})

function StartupsPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
