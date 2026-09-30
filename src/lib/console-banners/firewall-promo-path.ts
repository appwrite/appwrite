import { isInitRecapPromoPath } from './init-recap-promo-path'

const FIREWALL_ROUTE_PATTERN = /^\/projects\/[^/]+\/firewall(\/|$)/

/** Console surfaces where the Firewall fullscreen takeover may appear. */
export function isFirewallPromoPath(pathname: string): boolean {
  if (FIREWALL_ROUTE_PATTERN.test(pathname)) return false
  return isInitRecapPromoPath(pathname)
}
