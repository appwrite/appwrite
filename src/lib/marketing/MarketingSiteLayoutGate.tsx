import type { ReactNode } from 'react'
import { useMatches, useRouterState } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { MarketingSiteLayout } from '@/lib/marketing/MarketingSiteLayout'
import { shouldUseMarketingSiteLayout } from '@/lib/marketing/marketing-route-shell'

type MarketingSiteLayoutGateProps = {
  children: ReactNode
}

/**
 * Persistent marketing chrome at the root outlet level.
 * Keeps ConsoleLayout/Header mounted across marketing route transitions
 * (same stability as /docs using DocsPageShell on a parent layout).
 */
export function MarketingSiteLayoutGate({
  children,
}: MarketingSiteLayoutGateProps) {
  const pathname = useRouterState({
    select: (s) => s.resolvedLocation?.pathname ?? s.location.pathname,
  })
  const matches = useMatches()
  const { features } = useConsoleProfile()

  const useMarketingShell = shouldUseMarketingSiteLayout({
    marketingEnabled: features.marketing,
    pathname,
    matches,
  })

  if (!useMarketingShell) {
    return children
  }

  return <MarketingSiteLayout>{children}</MarketingSiteLayout>
}
