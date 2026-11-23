import { isConsoleImpersonationActive } from '@/lib/console-impersonation'
import { isCloudProfile } from '@/lib/console-profiles'
import { FIREWALL_PROMO_BANNER_ID, getConsoleBannerById } from './catalog'
import { isOperatorAccount, type OperatorAccount } from '@/lib/operator-account'

const FIREWALL_PROMO_BANNER = getConsoleBannerById(FIREWALL_PROMO_BANNER_ID)!

/** The Firewall promo is limited to console operators (plus debug preview). */
export function isFirewallPromoOperatorAudience(
  account: OperatorAccount | null | undefined,
): boolean {
  return isOperatorAccount(account) || isConsoleImpersonationActive(account)
}

export function isFirewallPromoVisible(options: {
  preview: boolean
  dismissed: boolean
  operatorAudience: boolean
}): boolean {
  if (options.preview) return true
  if (!options.operatorAudience || options.dismissed) return false
  if (FIREWALL_PROMO_BANNER.cloudOnly && !isCloudProfile()) return false
  return true
}
