import { createContext, useContext, type ReactNode } from 'react'
import type { CompactFilterKey, FilterMap } from '@/lib/table-filters'
import type {
  AnalyticsFilter,
  AnalyticsFilterAttribute,
} from '@/lib/analytics/analytics-filters'

export type AnalyticsFiltersContextValue = {
  filterMap: FilterMap
  /** Active filters in the shape the query layer takes. */
  filters: AnalyticsFilter[]
  /** Add (or replace) an `equal` filter; used by breakdown row clicks. */
  addEqualFilter: (attribute: AnalyticsFilterAttribute, value: string) => void
  /** True when an `equal` filter for this attribute/value is already active. */
  isFilterActive: (attribute: AnalyticsFilterAttribute, value: string) => boolean
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAllFilters: () => void
  onApplySavedFilterQuery: (queryParam: string | undefined) => void
}

const EMPTY: AnalyticsFiltersContextValue = {
  filterMap: new Map(),
  filters: [],
  addEqualFilter: () => {},
  isFilterActive: () => false,
  onApplyFilter: () => {},
  onRemoveFilter: () => {},
  onClearAllFilters: () => {},
  onApplySavedFilterQuery: () => {},
}

const AnalyticsFiltersContext =
  createContext<AnalyticsFiltersContextValue>(EMPTY)

export function AnalyticsFiltersProvider({
  value,
  children,
}: {
  value: AnalyticsFiltersContextValue
  children: ReactNode
}) {
  return (
    <AnalyticsFiltersContext.Provider value={value}>
      {children}
    </AnalyticsFiltersContext.Provider>
  )
}

/** Falls back to "no filters" outside a provider, so cards stay reusable. */
export function useAnalyticsFilters(): AnalyticsFiltersContextValue {
  return useContext(AnalyticsFiltersContext)
}
