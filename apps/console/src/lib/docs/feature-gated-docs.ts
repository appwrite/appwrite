import { isAgentDocsEnabled, isAgentDocsSlug } from './agent-docs-feature'
import {
  isBeepDocsEnabled,
  isBeepDocsSlug,
  shouldBlockBeepDocs,
} from './beep-docs-feature'
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
import {
  isVideosDocsEnabled,
  isVideosDocsSlug,
  shouldBlockVideosDocs,
} from './videos-docs-feature'
import {
  isZeroDocsEnabled,
  isZeroDocsSlug,
  shouldBlockZeroDocs,
} from './zero-docs-feature'

/**
 * True when a docs slug must stay hidden for the active console profile.
 */
export function isFeatureGatedDocsSlugHidden(
  slug: string,
  options?: {
    deferPartnersOnServer?: boolean
    deferAnalyticsOnServer?: boolean
    deferVideosOnServer?: boolean
    deferBeepOnServer?: boolean
    deferZeroOnServer?: boolean
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
  if (isVideosDocsSlug(slug)) {
    if (options?.deferVideosOnServer) return shouldBlockVideosDocs()
    return !isVideosDocsEnabled()
  }
  if (isBeepDocsSlug(slug)) {
    if (options?.deferBeepOnServer) return shouldBlockBeepDocs()
    return !isBeepDocsEnabled()
  }
  if (isZeroDocsSlug(slug)) {
    if (options?.deferZeroOnServer) return shouldBlockZeroDocs()
    return !isZeroDocsEnabled()
  }
  return false
}
