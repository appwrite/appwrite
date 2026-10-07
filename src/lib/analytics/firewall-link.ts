import { AnalyticsDimension } from '@appwrite.io/console'
import {
  buildFilterQueryString,
  mapToQueryParam,
  type CompactFilterKey,
} from '@/lib/table-filters'

/**
 * The firewall condition an analytics value maps to, in the compact filter
 * form the firewall create page reads from `?query=` (the same contract the
 * Usage "Create firewall rule" action uses; see `draftsFromUsageFilterMap`).
 *
 * Only dimensions whose values mean the same thing to the firewall are
 * mapped. Browser and OS names come from different parsers on each side, so
 * they're left out rather than producing a rule that never matches.
 */
export function firewallConditionForAnalyticsValue(
  dimension: AnalyticsDimension,
  value: string,
): CompactFilterKey | null {
  const trimmed = value.trim()
  if (!trimmed) return null
  switch (dimension) {
    case AnalyticsDimension.Country:
      return /^[a-z]{2}$/i.test(trimmed)
        ? { c: 'country', o: 'equal', v: trimmed.toUpperCase() }
        : null
    case AnalyticsDimension.City:
      return { c: 'city', o: 'equal', v: trimmed }
    case AnalyticsDimension.Region:
      return { c: 'region', o: 'equal', v: trimmed }
    case AnalyticsDimension.Hostname:
      return { c: 'hostname', o: 'equal', v: trimmed.toLowerCase() }
    case AnalyticsDimension.Page:
    case AnalyticsDimension.EntryPage:
    case AnalyticsDimension.ExitPage: {
      // Request paths only: drop any query string / fragment.
      const path = trimmed.replace(/^https?:\/\/[^/]+/i, '').split(/[?#]/)[0]
      return path.startsWith('/') ? { c: 'path', o: 'equal', v: path } : null
    }
    case AnalyticsDimension.BotName:
      // Bot names ("Bingbot", "GPTBot") appear in the user agent.
      return { c: 'userAgent', o: 'contains', v: trimmed }
    default:
      return null
  }
}

/** `?query=` value for the firewall create page, or null when unmapped. */
export function firewallCreateQueryForAnalyticsValue(
  dimension: AnalyticsDimension,
  value: string,
): string | null {
  const key = firewallConditionForAnalyticsValue(dimension, value)
  if (!key) return null
  const query = buildFilterQueryString(key.o, String(key.c), key.v as string)
  return mapToQueryParam(new Map([[key, query]]))
}
