import { useOptionalUsageFilters } from '@/components/pages/projects/$projectId/usage/usage-filters-context'
import type { UsageFilterQueries } from '@/lib/usage/usage-filter-queries'

export type UsageFilterQuerySurface = 'events' | 'gauges'

export function useUsageSectionFilterQueries(
  surface: UsageFilterQuerySurface = 'events',
): UsageFilterQueries {
  const context = useOptionalUsageFilters()
  if (!context) return undefined
  return surface === 'gauges'
    ? context.gaugeFilterQueries
    : context.eventFilterQueries
}
