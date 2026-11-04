import type { Models } from '@appwrite.io/console'
import type { AnalyticsPlatform } from './snippets'

/**
 * What a property tracks. The type decides which dashboard panels apply; the
 * platform (`AnalyticsPlatform`) is only the SDK used to send events.
 */
export const ANALYTICS_PROPERTY_TYPES = ['web', 'mobile', 'agent'] as const

export type AnalyticsPropertyType = (typeof ANALYTICS_PROPERTY_TYPES)[number]

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
  web: {
    id: 'web',
    label: 'Web',
    description: 'Websites and browser apps.',
    platforms: ['web', 'rest'],
  },
  mobile: {
    id: 'mobile',
    label: 'Mobile',
    description: 'iOS and Android apps.',
    platforms: ['flutter', 'rest'],
  },
  agent: {
    id: 'agent',
    label: 'Agent',
    description: 'AI agents and backend services, over the API.',
    platforms: ['rest'],
  },
}

export function isAnalyticsPropertyType(
  value: string | undefined | null,
): value is AnalyticsPropertyType {
  return (
    !!value && (ANALYTICS_PROPERTY_TYPES as readonly string[]).includes(value)
  )
}

/** Properties created before types existed have none and count as web. */
export function analyticsPropertyType(
  property: Models.AnalyticsProperty,
): AnalyticsPropertyType {
  // TODO(#64): read property.type directly once the console SDK RC with type is pinned
  const type = (property as { type?: string }).type
  return isAnalyticsPropertyType(type) ? type : 'web'
}
