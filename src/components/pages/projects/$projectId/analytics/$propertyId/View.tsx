import { useCallback, useEffect, useMemo, useState } from 'react'
import type { DateRange } from 'react-day-picker'
import { differenceInHours } from 'date-fns'
import { BarChart3, Download } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { InstallTrackingDialog } from '../_components/InstallTrackingDialog'
import {
  ServiceHeader,
  type Tab,
} from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { DetailResourceHeaderTitle } from '@/components/global/shared/ResourceTitleSwitcher'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { UsageChartIntervalToggle } from '../../overview/UsageChartIntervalToggle'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import {
  ANALYTICS_CHART_INTERVALS,
  analyticsRangeKey,
  DEFAULT_ANALYTICS_CHART_INTERVAL,
  DEFAULT_ANALYTICS_COMPARE_MODE,
  getComparisonAnalyticsRange,
  getDefaultAnalyticsRange,
  getPreviousAnalyticsRange,
  toAnalyticsRange,
  useAnalyticsEvents,
  useAnalyticsProperty,
  useAnalyticsLinkedSite,
  useOrganizationScopes,
  useRefreshAnalyticsProperty,
  useProject,
  type AnalyticsChartInterval,
  type AnalyticsCompareMode,
} from '@/lib/react-query/hooks'
import { isUsageChartIntervalValidForRange } from '@/lib/usage/chart-interval'
import { normalizeUsageDateRangeSelection } from '@/lib/usage/usage-date-range'
import {
  findMatchingUsageDateRangePreset,
  getUsageDateRangePresetByValue,
} from '@/lib/usage/usage-date-range-presets'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useAnalyticsChartPrefs } from '@/hooks/use-analytics-chart-prefs'
import {
  canCreateAnalyticsProperty,
  canWriteRules,
} from '@/lib/console-access-checks'
import { isCloudProfile } from '@/lib/console-profiles'
import { PropertySettings } from '../_components/PropertySettings'
import { AnalyticsOverview } from '../_components/AnalyticsOverview'
import { LiveVisitors } from '../_components/LiveVisitors'
import { ExportMenu } from '../_components/ExportMenu'
import type { AnalyticsChartMetric } from '../_components/AnalyticsOverview'
import {
  BotsPanel,
  LocationsPanel,
  PagesPanel,
  TechnologyPanel,
  TrafficSourcesPanel,
} from '../_components/DimensionPanels'
import { EventsPanel } from '../_components/EventsPanel'
import { TrafficSplit } from '../_components/TrafficSplit'
import {
  CompareControl,
  compareModeLabel,
} from '../_components/CompareControl'
import {
  AnalyticsFiltersProvider,
  AnalyticsValueMenuProvider,
  type AnalyticsFiltersContextValue,
} from '../_components/analytics-filters-context'
import {
  ANALYTICS_FILTER_COLUMNS,
  analyticsFiltersFromMap,
  equalFilterEntry,
  trafficKindFilterEntry,
  trafficKindOfFilterKey,
  type AnalyticsTrafficKind,
  sanitizeAnalyticsFilterMap,
} from '@/lib/analytics/analytics-filters'
import {
  mapToQueryParam,
  queryParamToMap,
  type FilterMap,
} from '@/lib/table-filters'

export type PropertyDetailInitialData = {
  property: Models.AnalyticsProperty
  stats?: Models.AnalyticsMetric
  events?: { events: Models.AnalyticsMetric[]; total: number }
  series?: { points: Models.AnalyticsMetric[]; total: number }
}

interface ViewProps {
  projectId: string
  propertyId: string
  onBack?: () => void
  initialData?: PropertyDetailInitialData
  /** Encoded `FilterMap` from the URL `?query=` param. */
  filterQuery?: string
  onFilterQueryChange?: (query: string | undefined) => void
}

/** Ranges this short read better hourly; longer ones default to daily. */
const HOURLY_DEFAULT_MAX_HOURS = 72

type DateSelection = { dateRange: DateRange; presetId: string | null }

function defaultIntervalForRange(dateRange: DateRange): AnalyticsChartInterval {
  if (!dateRange.from || !dateRange.to) return DEFAULT_ANALYTICS_CHART_INTERVAL
  return differenceInHours(dateRange.to, dateRange.from) <=
    HOURLY_DEFAULT_MAX_HOURS
    ? '1h'
    : '1d'
}

export function View({
  projectId,
  propertyId,
  onBack,
  initialData,
  filterQuery,
  onFilterQueryChange,
}: ViewProps) {
  const t = useT()
  const [activeTab, setActiveTab] = useState('analytics')
  const [installOpen, setInstallOpen] = useState(false)
  const isAnalyticsTab = activeTab === 'analytics'
  const [filtersOpen, setFiltersOpen] = useState(false)

  // ── Filters ──
  // Same model as Usage: a FilterMap encoded in the URL. Clicking a row in
  // any card adds an `equal` filter; the popover edits the rest.
  const filterMap = useMemo(
    () => sanitizeAnalyticsFilterMap(queryParamToMap(filterQuery)),
    [filterQuery],
  )
  const filters = useMemo(() => analyticsFiltersFromMap(filterMap), [filterMap])

  const commitFilterMap = useCallback(
    (next: FilterMap) => {
      const sanitized = sanitizeAnalyticsFilterMap(next)
      onFilterQueryChange?.(
        sanitized.size > 0 ? mapToQueryParam(sanitized) : undefined,
      )
    },
    [onFilterQueryChange],
  )

  const filtersContext = useMemo((): AnalyticsFiltersContextValue => {
    const findEqual = (attribute: string, value: string) => {
      for (const key of filterMap.keys()) {
        if (key.c === attribute && key.o === 'equal' && key.v === value) {
          return key
        }
      }
      return undefined
    }
    let trafficKind: AnalyticsTrafficKind | null = null
    for (const key of filterMap.keys()) {
      trafficKind = trafficKindOfFilterKey(key) ?? trafficKind
    }
    return {
      filterMap,
      filters,
      trafficKind,
      toggleTrafficKind: (kind) => {
        const next = new Map(filterMap)
        // At most one humans/bots filter at a time.
        for (const key of next.keys()) {
          if (trafficKindOfFilterKey(key)) next.delete(key)
        }
        if (kind !== trafficKind) {
          if (kind === 'human') {
            // A bot category or agent filter would leave humans empty.
            for (const key of next.keys()) {
              if (key.c === 'botCategory' || key.c === 'botName') next.delete(key)
            }
          }
          const entry = trafficKindFilterEntry(kind)
          next.set(entry.key, entry.query)
        }
        commitFilterMap(next)
      },
      isFilterActive: (attribute, value) => !!findEqual(attribute, value),
      addEqualFilter: (attribute, value) => {
        const next = new Map(filterMap)
        const existing = findEqual(attribute, value)
        if (existing) {
          // Clicking an active value again removes it.
          next.delete(existing)
        } else {
          // One `equal` per attribute: clicking another country switches to
          // it rather than AND-ing two countries into an empty result.
          for (const key of next.keys()) {
            if (key.c === attribute && key.o === 'equal') next.delete(key)
            // Picking a bot type or agent ends "humans only".
            if (
              (attribute === 'botCategory' || attribute === 'botName') &&
              trafficKindOfFilterKey(key) === 'human'
            ) {
              next.delete(key)
            }
          }
          const entry = equalFilterEntry(attribute, value)
          next.set(entry.key, entry.query)
        }
        commitFilterMap(next)
      },
      onApplyFilter: (key, queryStr, replaceKey) => {
        const next = new Map(filterMap)
        if (replaceKey) next.delete(replaceKey)
        next.set(key, queryStr)
        commitFilterMap(next)
      },
      onRemoveFilter: (key) => {
        const next = new Map(filterMap)
        next.delete(key)
        commitFilterMap(next)
      },
      onClearAllFilters: () => onFilterQueryChange?.(undefined),
      onApplySavedFilterQuery: (queryParam) =>
        commitFilterMap(queryParamToMap(queryParam)),
    }
  }, [filterMap, filters, commitFilterMap, onFilterQueryChange])
  const [activeSeries, setActiveSeries] =
    useState<AnalyticsChartMetric>('visitors')

  // ── Date range + interval ──
  // The picker speaks react-day-picker's DateRange; the query layer speaks
  // concrete ISO bounds. The preset id is kept so rolling windows ("Last 24
  // hours") can be re-anchored to now on refresh.
  //
  // Both start from the user's saved analytics prefs (one setting for every
  // property, separate from the usage range) and are saved back on change.
  const chartPrefs = useAnalyticsChartPrefs()
  const [dateSelection, setDateSelection] = useState<DateSelection>(() => ({
    dateRange: chartPrefs.initial.dateRange,
    presetId: chartPrefs.initial.presetId,
  }))
  const [chartInterval, setChartInterval] = useState<AnalyticsChartInterval>(
    chartPrefs.initial.interval,
  )
  const { dateRange, presetId } = dateSelection

  const savePrefs = chartPrefs.save
  useEffect(() => {
    savePrefs({ dateRange, presetId, interval: chartInterval })
  }, [savePrefs, dateRange, presetId, chartInterval])

  const range = useMemo(
    () => toAnalyticsRange(dateRange) ?? getDefaultAnalyticsRange(),
    [dateRange],
  )

  // Hourly buckets are capped at 31 days, same rule as the Usage charts.
  const resolvedInterval: AnalyticsChartInterval =
    chartInterval === '1h' &&
    !isUsageChartIntervalValidForRange('1h', dateRange)
      ? '1d'
      : chartInterval

  const handleDateRangeChange = useCallback(
    (next: DateRange | undefined) => {
      const normalized = normalizeUsageDateRangeSelection(next)
      if (!normalized?.from) return
      const nextRange: DateRange = {
        from: normalized.from,
        to: normalized.to ?? normalized.from,
      }
      const nextKey = toAnalyticsRange(nextRange)
      if (nextKey && analyticsRangeKey(nextKey) === analyticsRangeKey(range)) {
        return
      }
      setDateSelection({
        dateRange: nextRange,
        presetId: findMatchingUsageDateRangePreset(nextRange)?.value ?? null,
      })
      setChartInterval(defaultIntervalForRange(nextRange))
    },
    [range],
  )

  // ── Comparison ──
  const [compareMode, setCompareMode] = useState<AnalyticsCompareMode>(
    DEFAULT_ANALYTICS_COMPARE_MODE,
  )
  const [customCompareDateRange, setCustomCompareDateRange] = useState<
    DateRange | undefined
  >(undefined)
  const customCompareRange = useMemo(
    () => toAnalyticsRange(customCompareDateRange) ?? null,
    [customCompareDateRange],
  )
  const comparisonRange = useMemo(
    () => getComparisonAnalyticsRange(range, compareMode, customCompareRange),
    [range, compareMode, customCompareRange],
  )

  const handleCompareModeChange = useCallback(
    (mode: AnalyticsCompareMode) => {
      // Seed a custom comparison with the previous period so the chart has
      // something to show until the user picks their own window.
      if (mode === 'custom' && !customCompareDateRange) {
        const previous = getPreviousAnalyticsRange(range)
        setCustomCompareDateRange({
          from: new Date(previous.startAt),
          to: new Date(previous.endAt),
        })
      }
      setCompareMode(mode)
    },
    [customCompareDateRange, range],
  )

  const handleCustomCompareDateRangeChange = useCallback(
    (next: DateRange | undefined) => {
      const normalized = normalizeUsageDateRangeSelection(next)
      if (!normalized?.from) return
      setCustomCompareDateRange({
        from: normalized.from,
        to: normalized.to ?? normalized.from,
      })
    },
    [],
  )

  const { refresh, isRefreshing } = useRefreshAnalyticsProperty(
    projectId,
    propertyId,
  )

  const handleRefresh = useCallback(() => {
    // Re-anchor a preset (e.g. rolling "Last 24 hours") to now. When that
    // moves the window, the new query keys fetch on their own; refetching the
    // old keys as well would just be wasted requests.
    const preset = presetId ? getUsageDateRangePresetByValue(presetId) : null
    if (preset) {
      const nextRange = preset.getRange()
      const nextKey = toAnalyticsRange(nextRange)
      if (nextKey && analyticsRangeKey(nextKey) !== analyticsRangeKey(range)) {
        setDateSelection({ dateRange: nextRange, presetId })
        return
      }
    }
    void refresh()
  }, [presetId, range, refresh])

  // ── Property + permissions ──
  const { property: propertyFromHook, isLoading: propertyLoading } =
    useAnalyticsProperty(projectId, propertyId)
  const property = propertyFromHook ?? initialData?.property

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canCreateAnalyticsProperty(access, features)

  // "Create firewall rule" on values: only when the property's domain is
  // served by an Appwrite Site and the viewer can write firewall rules
  // (Cloud only, like the Firewall product).
  const canCreateFirewallRule = isCloudProfile() && canWriteRules(access, features)
  const { siteId: linkedSiteId } = useAnalyticsLinkedSite(
    projectId,
    property?.domain,
    canCreateFirewallRule,
  )
  const valueMenuContext = useMemo(
    () => ({
      projectId,
      firewallSiteId: canCreateFirewallRule ? linkedSiteId : null,
    }),
    [projectId, canCreateFirewallRule, linkedSiteId],
  )

  // Loader-prefetched data is only valid for the window the page opened with
  // (the saved prefs range, same as the loader read) and no filters.
  const initialRangeKey = useMemo(
    () =>
      analyticsRangeKey(
        toAnalyticsRange(chartPrefs.initial.dateRange) ??
          getDefaultAnalyticsRange(),
      ),
    [chartPrefs.initial],
  )
  const isDefaultRange =
    filters.length === 0 && analyticsRangeKey(range) === initialRangeKey

  // ── Events ──
  const { events: eventsFromHook, isLoading: eventsLoading } =
    useAnalyticsEvents(
      projectId,
      propertyId,
      range,
      undefined,
      filters,
    )
  const events = useMemo(() => {
    if (eventsFromHook.length > 0) return eventsFromHook
    if (isDefaultRange) return initialData?.events?.events ?? []
    return []
  }, [eventsFromHook, isDefaultRange, initialData])

  const tabs: Tab[] = [
    { id: 'analytics', label: t('Analytics') },
    { id: 'settings', label: t('Settings') },
  ]

  if (!property && !propertyLoading) {
    return (
      <div className="flex flex-col">
        <ServiceHeader
          title={t('Analytics')}
          showFilters={false}
          fullWidthBorder
          fullWidth
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6">
          <EmptyState
            icon={BarChart3}
            title={t('Property not found')}
            description={t('This analytics property no longer exists.')}
            variant="card"
            iconSize="md"
          />
        </div>
      </div>
    )
  }

  return (
    // The provider wraps the header too: the toolbar's export reads filters.
    <AnalyticsFiltersProvider value={filtersContext}>
    <AnalyticsValueMenuProvider value={valueMenuContext}>
    <div className="flex flex-col">
      <ServiceHeader
        // Same title as every other detail view (functions, sites, topics,
        // users): back, a switcher to jump between properties, and the ID.
        title={
          <div className="flex min-w-0 items-center gap-2">
            <DetailResourceHeaderTitle
              kind="analyticsProperty"
              label={property?.name || t('Property')}
              resourceId={propertyId}
              projectId={projectId}
              back={
                onBack
                  ? { onClick: onBack, 'aria-label': t('Back to analytics') }
                  : undefined
              }
            />
            {property && !property.enabled && (
              <Badge variant="warning" className="shrink-0 text-[10px]">
                {t('Disabled')}
              </Badge>
            )}
          </div>
        }
        titleRightContent={
          <div className="flex items-center gap-2">
            <LiveVisitors
              projectId={projectId}
              propertyId={propertyId}
              enabled={property?.enabled !== false}
            />
            {/* Install instructions, on both tabs (also in Settings). */}
            {property ? (
              <Button
                variant="outline"
                size="sm"
                className="h-8 gap-1.5 text-[12px]"
                onClick={() => setInstallOpen(true)}
              >
                <Download className="h-3.5 w-3.5" />
                {t('Install')}
              </Button>
            ) : null}
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        onTabChange={setActiveTab}
        // Analytics tab toolbar, laid out like the other detail views:
        // Filters on the start side; time controls before Refresh.
        showFilters={isAnalyticsTab}
        filterTrigger={
          isAnalyticsTab ? (
            <FiltersPopover
              open={filtersOpen}
              onOpenChange={setFiltersOpen}
              columns={ANALYTICS_FILTER_COLUMNS}
              filterMap={filterMap}
              onRemoveFilter={filtersContext.onRemoveFilter}
              onClearAll={filtersContext.onClearAllFilters}
              onApplyFilter={filtersContext.onApplyFilter}
              resourceLabel="analytics"
              filterScope="analytics.property"
              onApplyQuery={filtersContext.onApplySavedFilterQuery}
              teamId={project?.teamId}
            />
          ) : undefined
        }
        beforeRefreshButtons={
          isAnalyticsTab ? (
            <>
              <UsageChartIntervalToggle
                value={resolvedInterval}
                onValueChange={(next) => {
                  if (next === '1h' || next === '1d') setChartInterval(next)
                }}
                dateRange={dateRange}
                allowedIntervals={ANALYTICS_CHART_INTERVALS}
                className="h-9"
              />
              <DateRangePicker
                dateRange={dateRange}
                onDateRangeChange={handleDateRangeChange}
                presetId={presetId}
                className="h-9 shrink-0"
              />
              <CompareControl
                mode={compareMode}
                onModeChange={handleCompareModeChange}
                customDateRange={customCompareDateRange}
                onCustomDateRangeChange={handleCustomCompareDateRangeChange}
                comparisonRange={comparisonRange}
              />
            </>
          ) : undefined
        }
        showRefresh={isAnalyticsTab}
        onRefresh={handleRefresh}
        isRefreshing={isRefreshing}
        afterRefreshButtons={
          isAnalyticsTab ? (
            <ExportMenu
              projectId={projectId}
              property={property}
              range={range}
              interval={resolvedInterval}
              comparisonRange={comparisonRange}
              compareLabel={
                comparisonRange ? t(compareModeLabel(compareMode)) : null
              }
            />
          ) : undefined
        }
        showToolbarBottomBorder={isAnalyticsTab}
        fullWidthBorder
        fullWidth
      />

      {activeTab === 'analytics' && (
        <div className="flex-1">
          {/* Full-width traffic overview, same layout as Firewall. */}
          <AnalyticsOverview
            projectId={projectId}
            propertyId={propertyId}
            dateRange={dateRange}
            onDateRangeChange={handleDateRangeChange}
            range={range}
            interval={resolvedInterval}
            activeSeries={activeSeries}
            onActiveSeriesChange={setActiveSeries}
            fallbackStats={isDefaultRange ? initialData?.stats : undefined}
            compareMode={compareMode}
            comparisonRange={comparisonRange}
            onRefresh={handleRefresh}
          />

          <div className="mx-auto w-full max-w-7xl space-y-4 px-4 py-6 sm:px-6">
            {/* Dimension breakdowns. Each panel requests only the dimension
                of its visible tab, and every card has a fixed body height
                so switching tabs never shifts the grid. */}
            <div className="grid gap-4 lg:grid-cols-2">
              {/* Channels, referrers and UTM campaigns in one card. */}
              <TrafficSourcesPanel
                projectId={projectId}
                propertyId={propertyId}
                range={range}
              />
              {/* Humans vs bots, stretched to the sources card's height. */}
              <TrafficSplit
                projectId={projectId}
                propertyId={propertyId}
                range={range}
                comparisonRange={comparisonRange}
                compareLabel={t(compareModeLabel(compareMode))}
              />
              <PagesPanel
                projectId={projectId}
                propertyId={propertyId}
                range={range}
              />
              <LocationsPanel
                projectId={projectId}
                propertyId={propertyId}
                range={range}
              />
              <TechnologyPanel
                projectId={projectId}
                propertyId={propertyId}
                range={range}
              />
              <BotsPanel
                projectId={projectId}
                propertyId={propertyId}
                range={range}
              />
              {/* Custom events close the page at full width: it's the card
                  most specific to the product, and its empty state carries
                  the how-to CTA. */}
              <div className="lg:col-span-2">
                <EventsPanel
                  projectId={projectId}
                  propertyId={propertyId}
                  range={range}
                  events={events}
                  isLoading={eventsLoading}
                  property={property}
                  onOpenSetup={() => setInstallOpen(true)}
                />
              </div>
            </div>
          </div>
        </div>
      )}

      {activeTab === 'settings' && property && (
        <div className="mx-auto w-full max-w-7xl flex-1">
          <PropertySettings
            projectId={projectId}
            property={property}
            canWrite={canWrite}
          />
        </div>
      )}

      {property ? (
        <InstallTrackingDialog
          open={installOpen}
          onOpenChange={setInstallOpen}
          projectId={projectId}
          property={property}
        />
      ) : null}
    </div>
    </AnalyticsValueMenuProvider>
    </AnalyticsFiltersProvider>
  )
}
