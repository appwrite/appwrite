import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { matchesMarketingPagePath } from '@/lib/marketing/is-marketing-page-path'
import { useMarketingSiteLayoutProvided } from '@/lib/marketing/marketing-site-layout-context'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'
import { useRouterState } from '@tanstack/react-router'

/**
 * Local docs/blog/marketing routes on this origin.
 * Pre-launch locks those pages, so treat them as unavailable and send
 * visitors to the production site instead.
 */
export function isLocalMarketingEnabled(
  marketingEnabled: boolean,
  cookieHeader?: string | null,
): boolean {
  return marketingEnabled && !isPreLaunchModeEnabled(cookieHeader)
}

export function useLocalMarketingEnabled(): boolean {
  const { features } = useConsoleProfile()
  const { preLaunch } = useDebugOverrides()
  const inMarketingLayout = useMarketingSiteLayoutProvided()
  const pathname = useRouterState({
    select: (s) => s.resolvedLocation?.pathname ?? s.location.pathname,
  })
  return (
    (features.marketing ||
      inMarketingLayout ||
      matchesMarketingPagePath(pathname)) &&
    !preLaunch
  )
}
