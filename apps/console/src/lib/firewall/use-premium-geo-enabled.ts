import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  ADDON_KEY_PREMIUM_GEO_DB,
  findActiveOrPendingAddon,
} from '@/lib/billing/addons'
import { useProjectAddons } from '@/lib/react-query/hooks'

/**
 * Premium Geo DB is required for firewall conditions on city, ISP, connection
 * type, and related attributes. Self-hosted profiles without billing skip gating.
 */
export function useFirewallPremiumGeoEnabled(projectId: string | null | undefined) {
  const { features } = useConsoleProfile()
  const { addons, isLoading } = useProjectAddons(
    features.billing ? (projectId ?? null) : null,
  )
  const premiumGeoEnabled =
    !features.billing ||
    findActiveOrPendingAddon(addons, ADDON_KEY_PREMIUM_GEO_DB)?.status ===
      'active'

  return { premiumGeoEnabled, billingEnabled: features.billing, isLoading }
}
