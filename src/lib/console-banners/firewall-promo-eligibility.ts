import { isConsoleImpersonationActive } from '@/lib/console-impersonation'
import { isCloudProfile } from '@/lib/console-profiles'
import {
  FIREWALL_PROMO_BANNER_ENABLED,
  FIREWALL_PROMO_BANNER_ID,
  getConsoleBannerById,
} from './catalog'
import { isConsoleBannerVisible } from '@/lib/console-banners/visibility'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'

const FIREWALL_PROMO_BANNER = getConsoleBannerById(FIREWALL_PROMO_BANNER_ID)!

/** Console operator preview while `FIREWALL_PROMO_BANNER_ENABLED` is still false. */
export function isFirewallPromoOperatorEarlyAccess(
  account: OperatorAccount | null | undefined,
): boolean {
  if (FIREWALL_PROMO_BANNER_ENABLED) return false
  return (
    isOperatorAccount(account) || isConsoleImpersonationActive(account)
  )
}

export function isFirewallPromoScheduleAndCloudVisible(options: {
  preview: boolean
  dismissed: boolean
  operatorEarlyAccess: boolean
}): boolean {
  if (
    isConsoleBannerVisible(FIREWALL_PROMO_BANNER, {
      preview: options.preview,
      dismissed: options.dismissed,
    })
  ) {
    return true
  }
  if (!options.operatorEarlyAccess || options.dismissed) return false
  if (FIREWALL_PROMO_BANNER.cloudOnly && !isCloudProfile()) return false
  return true
}

export function shouldMountFirewallPromoBanner(options: {
  preview: boolean
  operatorEarlyAccess: boolean
}): boolean {
  return (
    FIREWALL_PROMO_BANNER_ENABLED ||
    options.preview ||
    options.operatorEarlyAccess
  )
}
