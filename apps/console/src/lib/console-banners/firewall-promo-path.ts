import { isInitRecapPromoPath } from './init-recap-promo-path'

const PROJECT_ROUTE_PATTERN = /^\/projects\/[^/]+(\/|$)/

/**
 * Firewall pages: rule creation, and the Firewall tab of a function, a site,
 * or project settings.
 */
function isFirewallPath(pathname: string): boolean {
  const [root, , section, ...rest] = pathname.split('/').filter(Boolean)
  if (root !== 'projects') return false
  if (section === 'firewall') return true
  if (section === 'settings') return rest[0] === 'firewall'
  if (section === 'functions' || section === 'sites') {
    return rest[1] === 'firewall'
  }
  return false
}

/** Console surfaces where the Firewall fullscreen takeover may appear. */
export function isFirewallPromoPath(pathname: string): boolean {
  if (isFirewallPath(pathname)) return false
  return isInitRecapPromoPath(pathname)
}

/** Project pages where the Firewall spider may crawl (never a Firewall page itself). */
export function isFirewallSpiderPromoPath(pathname: string): boolean {
  return PROJECT_ROUTE_PATTERN.test(pathname) && isFirewallPromoPath(pathname)
}
