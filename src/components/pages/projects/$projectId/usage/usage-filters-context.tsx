import { createContext, useContext, type ReactNode } from 'react'
import type { DateRange } from 'react-day-picker'
import type {
  CompactFilterKey,
  FilterColumn,
  FilterMap,
} from '@/lib/table-filters'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import type { UsageFilterQueries } from '@/lib/usage/usage-filter-queries'
import type { UsageBreakdownFilterEntry } from '@/lib/usage/usage-resource-filters'

export type UsageFiltersContextValue = {
  plan: 'free' | 'pro' | 'custom'
  organizationId?: string | null
  usageLogRetentionHours: number
  usageLogRetentionDays: number
  dateRange: DateRange | undefined
  chartInterval: UsageChartInterval
  /** Persist a new chart date range (picker or chart brush selection). */
  onDateRangeChange: (dateRange: DateRange | undefined) => void
  filterMap: FilterMap
  /** Filters applied to usage.listEvents calls for the active category. */
  eventFilterQueries: UsageFilterQueries
  /** Filters applied to usage.listGauges calls for the active category. */
  gaugeFilterQueries: UsageFilterQueries
  filterColumns: FilterColumn[]
  filterScope: string
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAllFilters: () => void
  onApplySavedFilterQuery: (queryParam: string | undefined) => void
  onAddBreakdownFilter: (filters: UsageBreakdownFilterEntry[]) => void
}

const UsageFiltersContext = createContext<UsageFiltersContextValue | null>(null)

export function UsageFiltersProvider({
  value,
  children,
}: {
  value: UsageFiltersContextValue
  children: ReactNode
}) {
  return (
    <UsageFiltersContext.Provider value={value}>
      {children}
    </UsageFiltersContext.Provider>
  )
}

export function useUsageFilters(): UsageFiltersContextValue {
  const context = useContext(UsageFiltersContext)
  if (!context) {
    throw new Error('useUsageFilters must be used within UsageFiltersProvider')
  }
  return context
}

export function useOptionalUsageFilters(): UsageFiltersContextValue | null {
  return useContext(UsageFiltersContext)
}
