import type { ReactNode } from 'react'
import { useLocation, useMatches } from '@tanstack/react-router'
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
  const location = useLocation()
  const matches = useMatches()
  const { features } = useConsoleProfile()

  const useMarketingShell = shouldUseMarketingSiteLayout({
    marketingEnabled: features.marketing,
    pathname: location.pathname,
    matches,
  })

  if (!useMarketingShell) {
    return children
  }

  return <MarketingSiteLayout>{children}</MarketingSiteLayout>
}
