import { AnalyticsPropertyType, type Models } from '@appwrite.io/console'
import type { AnalyticsPlatform } from './snippets'

/**
 * What a property tracks. The type decides which dashboard panels apply; the
 * platform (`AnalyticsPlatform`) is only the SDK used to send events.
 */
export const ANALYTICS_PROPERTY_TYPES = Object.values(AnalyticsPropertyType)

export type AnalyticsPropertyTypeMeta = {
  id: AnalyticsPropertyType
  label: string
  description: string
  /** SDK choices for this type; the first is the default. */
  platforms: readonly AnalyticsPlatform[]
}

export const ANALYTICS_PROPERTY_TYPE_META: Record<
  AnalyticsPropertyType,
  AnalyticsPropertyTypeMeta
> = {
  [AnalyticsPropertyType.Web]: {
    id: AnalyticsPropertyType.Web,
    label: 'Web',
    description: 'Websites and browser apps.',
    platforms: ['web', 'rest'],
  },
  [AnalyticsPropertyType.Mobile]: {
    id: AnalyticsPropertyType.Mobile,
    label: 'Mobile',
    description: 'iOS and Android apps.',
    platforms: ['flutter', 'rest'],
  },
  [AnalyticsPropertyType.Agent]: {
    id: AnalyticsPropertyType.Agent,
    label: 'Agent',
    description: 'AI agents and backend services, over the API.',
    platforms: ['rest'],
  },
}

// The console can ship to a region before its API returns `type`, or a newer
// API can return a type this build doesn't know.
export function propertyType(
  property: Pick<Models.AnalyticsProperty, 'type'> | null | undefined,
): AnalyticsPropertyType {
  const type = property?.type
  return type && type in ANALYTICS_PROPERTY_TYPE_META
    ? type
    : AnalyticsPropertyType.Web
}
