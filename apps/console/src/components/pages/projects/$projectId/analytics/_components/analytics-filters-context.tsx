import { createContext, useContext, type ReactNode } from 'react'
import type { CompactFilterKey, FilterMap } from '@/lib/table-filters'
import type {
  AnalyticsFilter,
  AnalyticsFilterAttribute,
  AnalyticsTrafficKind,
} from '@/lib/analytics/analytics-filters'

export type AnalyticsFiltersContextValue = {
  filterMap: FilterMap
  /** Active filters in the shape the query layer takes. */
  filters: AnalyticsFilter[]
  /** Add (or replace) an `equal` filter; used by breakdown row clicks. */
  addEqualFilter: (attribute: AnalyticsFilterAttribute, value: string) => void
  /** True when an `equal` filter for this attribute/value is already active. */
  isFilterActive: (attribute: AnalyticsFilterAttribute, value: string) => boolean
  /** Active humans/bots filter, if any. */
  trafficKind: AnalyticsTrafficKind | null
  /** Show only humans or only bots; clicking the active one clears it. */
  toggleTrafficKind: (kind: AnalyticsTrafficKind) => void
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
  trafficKind: null,
  toggleTrafficKind: () => {},
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

// ─── Page context for value menus ───────────────────────────────────────────

export type AnalyticsValueMenuContextValue = {
  projectId: string | null
  /**
   * The Appwrite Site serving the property's domain, when the viewer may
   * create firewall rules for it. Null hides "Create firewall rule".
   */
  firewallSiteId: string | null
}

const AnalyticsValueMenuContext = createContext<AnalyticsValueMenuContextValue>({
  projectId: null,
  firewallSiteId: null,
})

export function AnalyticsValueMenuProvider({
  value,
  children,
}: {
  value: AnalyticsValueMenuContextValue
  children: ReactNode
}) {
  return (
    <AnalyticsValueMenuContext.Provider value={value}>
      {children}
    </AnalyticsValueMenuContext.Provider>
  )
}

export function useAnalyticsValueMenuContext(): AnalyticsValueMenuContextValue {
  return useContext(AnalyticsValueMenuContext)
}

/** Falls back to "no filters" outside a provider, so cards stay reusable. */
export function useAnalyticsFilters(): AnalyticsFiltersContextValue {
  return useContext(AnalyticsFiltersContext)
}
