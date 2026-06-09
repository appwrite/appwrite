import { createFileRoute, redirect } from '@tanstack/react-router'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { View } from '@/components/pages/pricing/View'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  isPricingHashTarget,
  resetPricingPageScrollContainers,
} from '@/lib/pricing/comparison-scroll'
import { consoleAccountQueryOptions } from '@/lib/react-query/hooks/auth'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/pricing')({
  ssr: false,
  head: () => ({
    meta: [
      { title: pageTitle('Pricing') },
      {
        name: 'description',
        content:
          'All your cloud services under one subscription. Build, deploy, and observe your app from a unified stack under one subscription.',
      },
    ],
  }),
  loader: async ({ context }) => {
    if (typeof window === 'undefined') return

    const hash = window.location.hash.slice(1)
    if (hash && isPricingHashTarget(hash)) {
      history.scrollRestoration = 'manual'
      resetPricingPageScrollContainers(true)
    }

    if (!getActiveProfileFeatures().marketing) {
      throw redirect({ to: '/', replace: true })
    }

    void context.queryClient
      .prefetchQuery(consoleAccountQueryOptions())
      .catch(() => {})
  },
  component: PricingPage,
})

function PricingPage() {
  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout
        header={{
          marketingNav: true,
        }}
        showFooter
        footer={{ expanded: true }}
      >
        <View />
      </ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
