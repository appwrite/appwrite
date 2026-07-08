import { useOptionalUsageFilters } from '@/components/pages/projects/$projectId/usage/usage-filters-context'
import type { UsageFilterQueries } from '@/lib/usage/usage-filter-queries'
import { DEFAULT_USAGE_LOG_RETENTION_HOURS } from '@/lib/usage/usage-log-retention'

export type UsageFilterQuerySurface = 'events' | 'gauges'

export function useUsageSectionFilterQueries(
  surface: UsageFilterQuerySurface = 'events',
  logRetentionHoursOverride?: number,
): {
  filterQueries: UsageFilterQueries
  logRetentionHours: number
} {
  const context = useOptionalUsageFilters()
  return {
    filterQueries:
      context == null
        ? undefined
        : surface === 'gauges'
          ? context.gaugeFilterQueries
          : context.eventFilterQueries,
    logRetentionHours:
      logRetentionHoursOverride ??
      context?.usageLogRetentionHours ??
      DEFAULT_USAGE_LOG_RETENTION_HOURS,
  }
}
