import { isAgentDocsEnabled, isAgentDocsSlug } from './agent-docs-feature'
import { isDatabaseTypeDocsSlugHidden } from './database-docs-feature'
import { isDomainsDocsEnabled, isDomainsDocsSlug } from './domains-docs-feature'
import {
  isFirewallDocsEnabled,
  isFirewallDocsSlug,
} from './firewall-docs-feature'
import {
  isPartnersDocsEnabled,
  isPartnersDocsSlug,
  shouldBlockPartnersDocs,
} from './partners-docs-feature'

/**
 * True when a docs slug must stay hidden for the active console profile.
 * Domains is checked before partners so `/docs/partners/domains` is gated by
 * the domains flag even when partners docs are enabled.
 */
export function isFeatureGatedDocsSlugHidden(
  slug: string,
  options?: { deferPartnersOnServer?: boolean },
): boolean {
  if (isDomainsDocsSlug(slug) && !isDomainsDocsEnabled()) return true
  if (isPartnersDocsSlug(slug)) {
    if (options?.deferPartnersOnServer) return shouldBlockPartnersDocs()
    return !isPartnersDocsEnabled()
  }
  if (isFirewallDocsSlug(slug) && !isFirewallDocsEnabled()) return true
  if (isAgentDocsSlug(slug) && !isAgentDocsEnabled()) return true
  if (isDatabaseTypeDocsSlugHidden(slug)) return true
  return false
}
