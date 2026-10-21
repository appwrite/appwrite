import { isInitRecapPromoPath } from './init-recap-promo-path'

const FIREWALL_ROUTE_PATTERN = /^\/projects\/[^/]+\/firewall(\/|$)/
const PROJECT_ROUTE_PATTERN = /^\/projects\/[^/]+(\/|$)/

/** Console surfaces where the Firewall fullscreen takeover may appear. */
export function isFirewallPromoPath(pathname: string): boolean {
  if (FIREWALL_ROUTE_PATTERN.test(pathname)) return false
  return isInitRecapPromoPath(pathname)
}

/** Project pages where the Firewall spider may crawl (never the Firewall page itself). */
export function isFirewallSpiderPromoPath(pathname: string): boolean {
  return PROJECT_ROUTE_PATTERN.test(pathname) && isFirewallPromoPath(pathname)
}
