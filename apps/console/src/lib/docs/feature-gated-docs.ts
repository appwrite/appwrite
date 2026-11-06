import { isAgentDocsEnabled, isAgentDocsSlug } from './agent-docs-feature'
import {
  isAnalyticsDocsEnabled,
  isAnalyticsDocsSlug,
  shouldBlockAnalyticsDocs,
} from './analytics-docs-feature'
import {
  isPartnersDocsEnabled,
  isPartnersDocsSlug,
  shouldBlockPartnersDocs,
} from './partners-docs-feature'

/**
 * True when a docs slug must stay hidden for the active console profile.
 */
export function isFeatureGatedDocsSlugHidden(
  slug: string,
  options?: {
    deferPartnersOnServer?: boolean
    deferAnalyticsOnServer?: boolean
  },
): boolean {
  if (isPartnersDocsSlug(slug)) {
    if (options?.deferPartnersOnServer) return shouldBlockPartnersDocs()
    return !isPartnersDocsEnabled()
  }
  if (isAgentDocsSlug(slug) && !isAgentDocsEnabled()) return true
  if (isAnalyticsDocsSlug(slug)) {
    if (options?.deferAnalyticsOnServer) return shouldBlockAnalyticsDocs()
    return !isAnalyticsDocsEnabled()
  }
  return false
}
