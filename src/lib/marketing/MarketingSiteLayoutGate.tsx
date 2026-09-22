import type { ReactNode } from 'react'
import { useRef } from 'react'
import { useMatches, useRouterState } from '@tanstack/react-router'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { MarketingSiteLayout } from '@/lib/marketing/MarketingSiteLayout'
import {
  isConsoleAreaPath,
  isExcludedMarketingSiteLayoutPath,
  shouldUseMarketingSiteLayout,
} from '@/lib/marketing/marketing-route-shell'

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
  const pendingPathname = useRouterState({
    select: (s) => s.location.pathname,
  })
  const resolvedPathname = useRouterState({
    select: (s) => s.resolvedLocation?.pathname ?? s.location.pathname,
  })
  const matches = useMatches()
  const { features } = useConsoleProfile()
  const keepMarketingShellRef = useRef(false)

  const leavingMarketingShell =
    isExcludedMarketingSiteLayoutPath(pendingPathname) ||
    isConsoleAreaPath(pendingPathname)

  const computed =
    !leavingMarketingShell &&
    (shouldUseMarketingSiteLayout({
      marketingEnabled: features.marketing,
      pathname: pendingPathname,
      matches,
    }) ||
      shouldUseMarketingSiteLayout({
        marketingEnabled: features.marketing,
        pathname: resolvedPathname,
        matches,
      }))

  if (leavingMarketingShell) {
    keepMarketingShellRef.current = false
  } else if (computed) {
    keepMarketingShellRef.current = true
  } else if (
    isExcludedMarketingSiteLayoutPath(resolvedPathname) ||
    isConsoleAreaPath(resolvedPathname)
  ) {
    keepMarketingShellRef.current = false
  }

  const useMarketingShell = computed || keepMarketingShellRef.current

  if (!useMarketingShell) {
    return children
  }

  return <MarketingSiteLayout>{children}</MarketingSiteLayout>
}
