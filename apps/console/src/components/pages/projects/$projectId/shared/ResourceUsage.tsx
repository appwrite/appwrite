import { useCallback, useMemo, useState } from 'react'
import {
  Link,
  useLocation,
  useNavigate,
  useSearch,
} from '@tanstack/react-router'
import { Activity, ArrowUpDown, Cpu, type LucideIcon } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import {
  refetchProjectBandwidthUsageQueries,
  refetchProjectComputeUsageQueries,
  refetchProjectRequestsUsageQueries,
  useCountryLookups,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useUsageChartFilters } from '@/hooks/use-usage-chart-filters'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  SECONDARY_SIDEBAR_WIDTH_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import {
  buildFilterQueryString,
  mapToQueryParam,
  queryParamToMap,
  withFilter,
  type CompactFilterKey,
  type FilterMap,
} from '@/lib/table-filters'
import { getUsageChartIntervalsForPlan } from '@/lib/usage/chart-interval'
import {
  getUsageFilterColumnsForCategory,
  getUsageSavedFilterScope,
} from '@/lib/usage/usage-filter-configs'
import {
  getUsageFilterQueriesForSurface,
  sanitizeUsageFilterMap,
} from '@/lib/usage/usage-filter-queries'
import {
  getUsageLogRetentionDaysFromPlan,
  getUsageLogRetentionHoursFromPlan,
  hasFiniteUsageLogRetention,
} from '@/lib/usage/usage-log-retention'
import {
  getUsageResourceFilterEntries,
  type UsageBreakdownFilterEntry,
} from '@/lib/usage/usage-resource-filters'
import { RefreshProvider } from '@/components/global/shared/RefreshContext'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { ActiveFilterChips } from '@/components/global/shared/ActiveFilterChips'
import { UsageChartIntervalToggle } from '../overview/UsageChartIntervalToggle'
import { ComputeUsageSection } from '../usage/_components/ComputeUsageSection'
import { RequestsSection } from '../usage/RequestsSection'
import { BandwidthSection } from '../usage/BandwidthSection'
import {
  UsageFiltersProvider,
  type UsageFiltersContextValue,
} from '../usage/usage-filters-context'

// Requests and Bandwidth share the network filter columns; the resource
// itself is pinned, so its own columns are not offered again.
const NETWORK_CATEGORY = 'requests'
const PINNED_ATTRIBUTES = new Set(['resourceId', 'resourceType'])

export type ResourceUsageCategory = 'compute' | 'requests' | 'bandwidth'

const CATEGORIES: Record<
  ResourceUsageCategory,
  { label: string; icon: LucideIcon }
> = {
  compute: { label: 'Compute', icon: Cpu },
  requests: { label: 'Requests', icon: Activity },
  bandwidth: { label: 'Bandwidth', icon: ArrowUpDown },
}

/** Usage categories of a resource, its main one first: compute for a function, requests for a site. */
export function resourceUsageCategories(
  resourceType: 'function' | 'site',
): ResourceUsageCategory[] {
  return resourceType === 'function'
    ? ['compute', 'requests', 'bandwidth']
    : ['requests', 'bandwidth', 'compute']
}

type ResourceUsageProps = {
  projectId: string
  resourceType: 'function' | 'site'
  resourceId: string
  /** The open category; the resource's main one when unset. */
  category?: ResourceUsageCategory
}

/**
 * Usage tab of a function or a site: the global Usage sections for compute,
 * requests and bandwidth, scoped to this one resource, one category at a time
 * from a side menu like the Usage page. Requests and bandwidth take extra
 * filters, kept in the URL like the Usage page.
 */
export function ResourceUsage(props: ResourceUsageProps) {
  return (
    <RefreshProvider>
      <ResourceUsageContent {...props} />
    </RefreshProvider>
  )
}

function ResourceUsageContent({
  projectId,
  resourceType,
  resourceId,
  category,
}: ResourceUsageProps) {
  const t = useT()
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const { isSelfHosted } = useConsoleProfile()
  const { project } = useProject(projectId)
  const { plan: organizationPlan, isFetched: isOrganizationPlanFetched } =
    useOrganizationPlan(project?.teamId)
  const { lookups: countryLookups } = useCountryLookups()
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [isRefreshing, setIsRefreshing] = useState(false)

  const filterAvailability = useMemo(
    () => ({ allowCity: !isSelfHosted }),
    [isSelfHosted],
  )
  const computeCategory = resourceType === 'function' ? 'functions' : 'sites'

  const usageLogRetentionHours =
    getUsageLogRetentionHoursFromPlan(organizationPlan)
  const {
    dateRange,
    chartInterval,
    dateRangePresetId,
    setDateRange,
    setChartInterval,
    refreshRollingDateRange,
  } = useUsageChartFilters(organizationPlan, usageLogRetentionHours)

  // This resource, in every query on the page.
  const resourceFilterMap = useMemo<FilterMap>(
    () =>
      new Map(
        getUsageResourceFilterEntries(resourceId, {}, resourceType).map(
          ({ dimension, value }) => [
            { c: dimension, o: 'equal', v: value },
            buildFilterQueryString('equal', dimension, value),
          ],
        ),
      ),
    [resourceId, resourceType],
  )

  const filterColumns = useMemo(
    () =>
      getUsageFilterColumnsForCategory(
        NETWORK_CATEGORY,
        filterAvailability,
      ).filter((column) => !PINNED_ATTRIBUTES.has(column.id)),
    [filterAvailability],
  )

  const search = useSearch({ strict: false }) as { query?: string }
  const filterMap = useMemo<FilterMap>(() => {
    const sanitized = sanitizeUsageFilterMap(
      queryParamToMap(search.query),
      NETWORK_CATEGORY,
      filterAvailability,
    )
    return new Map(
      Array.from(sanitized).filter(([key]) => !PINNED_ATTRIBUTES.has(key.c)),
    )
  }, [search.query, filterAvailability])

  const navigateFilters = useCallback(
    (next: FilterMap) => {
      navigate({
        to: location.pathname,
        search: (prev) => ({
          ...(typeof prev === 'object' && prev !== null ? prev : {}),
          query: next.size > 0 ? mapToQueryParam(next) : undefined,
        }),
        replace: true,
      })
    },
    [navigate, location.pathname],
  )

  const applyFilter = useCallback(
    (
      key: CompactFilterKey,
      queryStr: string,
      replaceKey?: CompactFilterKey,
    ) => {
      if (PINNED_ATTRIBUTES.has(key.c)) return
      navigateFilters(withFilter(filterMap, key, queryStr, replaceKey))
    },
    [filterMap, navigateFilters],
  )
  const removeFilter = useCallback(
    (key: CompactFilterKey) => {
      const next = new Map(filterMap)
      next.delete(key)
      navigateFilters(next)
    },
    [filterMap, navigateFilters],
  )
  const clearFilters = useCallback(
    () => navigateFilters(new Map()),
    [navigateFilters],
  )
  const applySavedFilterQuery = useCallback(
    (queryParam: string | undefined) =>
      navigateFilters(
        sanitizeUsageFilterMap(
          queryParamToMap(queryParam),
          NETWORK_CATEGORY,
          filterAvailability,
        ),
      ),
    [navigateFilters, filterAvailability],
  )
  const addBreakdownFilter = useCallback(
    (entries: UsageBreakdownFilterEntry[]) => {
      let next = filterMap
      for (const { dimension, value } of entries) {
        const trimmed = value.trim()
        if (!trimmed || PINNED_ATTRIBUTES.has(dimension)) continue
        next = withFilter(
          next,
          { c: dimension, o: 'equal', v: trimmed },
          buildFilterQueryString('equal', dimension, trimmed),
        )
      }
      navigateFilters(
        sanitizeUsageFilterMap(next, NETWORK_CATEGORY, filterAvailability),
      )
    },
    [filterMap, navigateFilters, filterAvailability],
  )

  const shared = {
    plan: 'pro' as const,
    organizationId: project?.teamId,
    usageLogRetentionHours,
    usageLogRetentionDays: getUsageLogRetentionDaysFromPlan(organizationPlan),
    usageLogRetentionReady: !project?.teamId || isOrganizationPlanFetched,
    dateRange,
    dateRangePresetId,
    chartInterval,
    onDateRangeChange: setDateRange,
    filterScope: getUsageSavedFilterScope(NETWORK_CATEGORY),
    onApplySavedFilterQuery: applySavedFilterQuery,
  }

  // Compute events only filter by resource, so they see the resource alone.
  const computeQueries = getUsageFilterQueriesForSurface(
    resourceFilterMap,
    computeCategory,
    'events',
    filterAvailability,
  )
  const computeContext: UsageFiltersContextValue = {
    ...shared,
    filterMap: new Map(),
    eventFilterQueries: computeQueries,
    gaugeFilterQueries: computeQueries,
    filterColumns: [],
    onApplyFilter: () => {},
    onRemoveFilter: () => {},
    onClearAllFilters: () => {},
    onAddBreakdownFilter: () => {},
  }

  const networkQueries = getUsageFilterQueriesForSurface(
    new Map([...resourceFilterMap, ...filterMap]),
    NETWORK_CATEGORY,
    'events',
    filterAvailability,
  )
  const networkContext: UsageFiltersContextValue = {
    ...shared,
    filterMap,
    eventFilterQueries: networkQueries,
    gaugeFilterQueries: networkQueries,
    filterColumns,
    onApplyFilter: applyFilter,
    onRemoveFilter: removeFilter,
    onClearAllFilters: clearFilters,
    onAddBreakdownFilter: addBreakdownFilter,
  }

  const handleRefresh = async () => {
    refreshRollingDateRange()
    setIsRefreshing(true)
    try {
      await Promise.all([
        refetchProjectComputeUsageQueries(queryClient, projectId),
        refetchProjectRequestsUsageQueries(queryClient, projectId),
        refetchProjectBandwidthUsageQueries(queryClient, projectId),
      ])
    } finally {
      setIsRefreshing(false)
    }
  }

  const categories = resourceUsageCategories(resourceType)
  const activeCategory = category ?? categories[0]
  const showFilters = activeCategory !== 'compute'
  const usagePath =
    resourceType === 'function'
      ? `/projects/${projectId}/functions/${resourceId}/usage`
      : `/projects/${projectId}/sites/${resourceId}/usage`
  const categoryLinks = categories.map((id) => {
    const { label, icon: Icon } = CATEGORIES[id]
    const href: string = `${usagePath}/${id}`
    return (
      <Link
        key={id}
        to={href}
        search={true}
        className={secondarySidebarNavLinkClassName(
          id === activeCategory,
          SECONDARY_SIDEBAR_NAV_LINK_GRID_CLASS,
        )}
        aria-current={id === activeCategory ? 'page' : undefined}
      >
        <Icon className="h-4 w-4 shrink-0" />
        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
          {t(label)}
        </span>
      </Link>
    )
  })

  return (
    <div className="flex flex-1 flex-col">
      <div className="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3 sm:gap-3 sm:px-6">
        {showFilters ? (
          <FiltersPopover
            open={filtersOpen}
            onOpenChange={setFiltersOpen}
            columns={filterColumns}
            filterMap={filterMap}
            onRemoveFilter={removeFilter}
            onClearAll={clearFilters}
            onApplyFilter={applyFilter}
            resourceLabel="usage metrics"
            filterScope={shared.filterScope}
            onApplyQuery={applySavedFilterQuery}
            teamId={project?.teamId}
          />
        ) : null}
        <div className="ms-auto flex flex-wrap items-center justify-end gap-2 sm:gap-3">
          <UsageChartIntervalToggle
            value={chartInterval}
            onValueChange={setChartInterval}
            dateRange={dateRange}
            allowedIntervals={getUsageChartIntervalsForPlan(organizationPlan)}
            className="h-9"
          />
          <DateRangePicker
            dateRange={dateRange}
            onDateRangeChange={setDateRange}
            presetId={dateRangePresetId}
            className="h-9"
            retentionHours={
              hasFiniteUsageLogRetention(organizationPlan)
                ? usageLogRetentionHours
                : null
            }
          />
          <RefreshButton
            onClick={() => void handleRefresh()}
            isRefreshing={isRefreshing}
          />
        </div>
      </div>
      {showFilters ? (
        <ActiveFilterChips
          filterMap={filterMap}
          columns={filterColumns}
          countryLookups={countryLookups}
          onApplyFilter={applyFilter}
          onRemoveFilter={removeFilter}
          onClearAllFilters={clearFilters}
        />
      ) : null}

      <div className="flex min-h-0 flex-1">
        <aside
          className={cn(
            SECONDARY_SIDEBAR_WIDTH_CLASS,
            'hidden shrink-0 border-e border-border lg:block',
          )}
        >
          <nav
            className="sticky top-0 flex flex-col gap-0.5 px-3 py-4"
            aria-label={t('Usage categories')}
          >
            {categoryLinks}
          </nav>
        </aside>

        <div className="min-w-0 flex-1">
          {/* Same content width as the Usage page. */}
          <div className="mx-auto w-full max-w-7xl space-y-4 px-4 py-6 sm:px-6">
            <nav
              className="flex gap-1 overflow-x-auto lg:hidden [&>a]:w-auto [&>a]:shrink-0"
              aria-label={t('Usage categories')}
            >
              {categoryLinks}
            </nav>
            <h2 className="text-[16px] font-semibold text-foreground">
              {t(CATEGORIES[activeCategory].label)}
            </h2>
            {activeCategory === 'compute' ? (
              <UsageFiltersProvider value={computeContext}>
                <ComputeUsageSection
                  projectId={projectId}
                  dateRange={dateRange}
                  chartInterval={chartInterval}
                  scope={computeCategory}
                  onDateRangeChange={setDateRange}
                  showBreakdown={false}
                />
              </UsageFiltersProvider>
            ) : (
              <UsageFiltersProvider value={networkContext}>
                {activeCategory === 'requests' ? (
                  <RequestsSection
                    projectId={projectId}
                    dateRange={dateRange}
                    chartInterval={chartInterval}
                  />
                ) : (
                  <BandwidthSection
                    projectId={projectId}
                    dateRange={dateRange}
                    chartInterval={chartInterval}
                  />
                )}
              </UsageFiltersProvider>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
