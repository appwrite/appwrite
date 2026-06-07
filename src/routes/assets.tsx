import { createFileRoute, redirect } from '@tanstack/react-router'
import { View } from '@/components/pages/assets/View'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/assets')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Assets') },
      {
        name: 'description',
        content:
          "Appwrite's key brand assets including the logotype, colors, product visuals, and practical guidelines for their usage.",
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
  component: AssetsPage,
})

function AssetsPage() {
  return (
    <MarketingPageShell>
      <View />
    </MarketingPageShell>
  )
}
