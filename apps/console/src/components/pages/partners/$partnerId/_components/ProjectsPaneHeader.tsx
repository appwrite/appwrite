import { useMemo, useState, type ReactNode } from 'react'
import { Search } from 'lucide-react'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { Input } from '@/components/ui/input'
import {
  PARTNER_PANE_HEADER_FILTERS_BUTTON_CLASSNAME,
  PARTNER_PANE_HEADER_SEARCH_INPUT_CLASSNAME,
  PARTNER_PANE_HEADER_SEARCH_WRAP_CLASSNAME,
} from '@/components/pages/partners/$partnerId/_components/pane-header'
import { PartnerTwoRowPaneHeader } from '@/components/pages/partners/$partnerId/_components/PaneHeader'
import { useT } from '@/lib/i18n/translate'
import type {
  CompactFilterKey,
  FilterColumn,
  FilterMap,
} from '@/lib/table-filters'
import {
  mapToQueryParam,
  projectsFilterColumns,
  queryParamToMap,
} from '@/lib/table-filters'

export function usePartnerProjectsFilterState(onFiltersChange: () => void) {
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [filterMap, setFilterMap] = useState<FilterMap>(() => new Map())
  const filterQueries = useMemo(
    () => (filterMap.size > 0 ? Array.from(filterMap.values()) : undefined),
    [filterMap],
  )

  const applyFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const next = new Map(filterMap)
    if (replaceKey) next.delete(replaceKey)
    next.set(compactKey, queryStr)
    setFilterMap(next)
    onFiltersChange()
  }

  const removeFilter = (key: CompactFilterKey) => {
    const next = new Map(filterMap)
    next.delete(key)
    setFilterMap(next)
    onFiltersChange()
  }

  const clearAll = () => {
    setFilterMap(new Map())
    onFiltersChange()
  }

  const applyQuery = (queryParam: string | undefined) => {
    setFilterMap(queryParamToMap(queryParam ?? null))
    onFiltersChange()
  }

  return {
    filtersOpen,
    setFiltersOpen,
    filterMap,
    filterQueries,
    hasFilters: filterMap.size > 0,
    applyFilter,
    removeFilter,
    clearAll,
    applyQuery,
    filterQueryParam: filterMap.size > 0 ? mapToQueryParam(filterMap) : undefined,
  }
}

export function PartnerListPaneHeader({
  title,
  searchValue,
  onSearchChange,
  searchPlaceholder,
  columns,
  filterMap,
  filtersOpen,
  onFiltersOpenChange,
  onRemoveFilter,
  onClearAll,
  onApplyFilter,
  onApplyQuery,
  resourceLabel,
  filterScope,
  teamId,
  actions,
  primaryAction,
  onReload,
  isReloading,
}: {
  title: string
  searchValue: string
  onSearchChange: (value: string) => void
  searchPlaceholder: string
  columns: FilterColumn[]
  filterMap: FilterMap
  filtersOpen: boolean
  onFiltersOpenChange: (open: boolean) => void
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAll: () => void
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
  onApplyQuery: (queryParam: string | undefined) => void
  resourceLabel: string
  filterScope: string
  teamId?: string | null
  actions: ReactNode
  primaryAction?: ReactNode
  onReload: () => void
  isReloading?: boolean
}) {
  return (
    <PartnerTwoRowPaneHeader
      title={title}
      actions={actions}
      primaryAction={primaryAction}
      onReload={onReload}
      isReloading={isReloading}
      toolbar={
        <>
          <div className={PARTNER_PANE_HEADER_SEARCH_WRAP_CLASSNAME}>
            <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder={searchPlaceholder}
              value={searchValue}
              onChange={(e) => onSearchChange(e.target.value)}
              className={PARTNER_PANE_HEADER_SEARCH_INPUT_CLASSNAME}
            />
          </div>
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={onFiltersOpenChange}
            triggerClassName={PARTNER_PANE_HEADER_FILTERS_BUTTON_CLASSNAME}
            columns={columns}
            filterMap={filterMap}
            onRemoveFilter={onRemoveFilter}
            onClearAll={onClearAll}
            onApplyFilter={onApplyFilter}
            resourceLabel={resourceLabel}
            filterScope={filterScope}
            onApplyQuery={onApplyQuery}
            teamId={teamId}
          />
        </>
      }
    />
  )
}

export function PartnerProjectsPaneHeader({
  searchValue,
  onSearchChange,
  filterMap,
  filtersOpen,
  onFiltersOpenChange,
  onRemoveFilter,
  onClearAll,
  onApplyFilter,
  onApplyQuery,
  teamId,
  actions,
  primaryAction,
  onReload,
  isReloading,
}: {
  searchValue: string
  onSearchChange: (value: string) => void
  filterMap: FilterMap
  filtersOpen: boolean
  onFiltersOpenChange: (open: boolean) => void
  onRemoveFilter: (key: CompactFilterKey) => void
  onClearAll: () => void
  onApplyFilter: (
    key: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => void
  onApplyQuery: (queryParam: string | undefined) => void
  teamId?: string | null
  actions: ReactNode
  primaryAction?: ReactNode
  onReload: () => void
  isReloading?: boolean
}) {
  const t = useT()

  return (
    <PartnerListPaneHeader
      title={t('Projects')}
      searchValue={searchValue}
      onSearchChange={onSearchChange}
      searchPlaceholder={t('Search projects...')}
      columns={projectsFilterColumns}
      filterMap={filterMap}
      filtersOpen={filtersOpen}
      onFiltersOpenChange={onFiltersOpenChange}
      onRemoveFilter={onRemoveFilter}
      onClearAll={onClearAll}
      onApplyFilter={onApplyFilter}
      onApplyQuery={onApplyQuery}
      resourceLabel={t('projects')}
      filterScope="partners.projects"
      teamId={teamId}
      actions={actions}
      primaryAction={primaryAction}
      onReload={onReload}
      isReloading={isReloading}
    />
  )
}
