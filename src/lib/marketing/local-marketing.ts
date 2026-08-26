import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { isPreLaunchModeEnabled } from '@/lib/pre-launch'

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
  return features.marketing && !preLaunch
}
