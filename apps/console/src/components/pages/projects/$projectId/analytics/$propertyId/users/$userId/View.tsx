import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import type { DateRange } from 'react-day-picker'
import { AnalyticsDimension, AnalyticsPropertyType } from '@appwrite.io/console'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateRangePicker } from '@/components/global/shared/DateRangePicker'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useT } from '@/lib/i18n/translate'
import { propertyType } from '@/lib/analytics-wizard/property-types'
import {
  getDefaultAnalyticsRange,
  resolveAnalyticsChartInterval,
  toAnalyticsRange,
  useAnalyticsEventMetrics,
  useAnalyticsEvents,
  useAnalyticsProperty,
  useRefreshAnalyticsProperty,
} from '@/lib/react-query/hooks'
import { useAnalyticsChartPrefs } from '@/hooks/use-analytics-chart-prefs'
import { normalizeUsageDateRangeSelection } from '@/lib/usage/usage-date-range'
import {
  ANALYTICS_DATE_RANGE_PRESET_GROUPS,
  findMatchingUsageDateRangePreset,
} from '@/lib/usage/usage-date-range-presets'
import {
  equalFilterEntry,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import {
  mapToQueryParam,
  type CompactFilterKey,
  type FilterMap,
} from '@/lib/table-filters'
import {
  BreakdownPanel,
  type BreakdownTab,
} from '../../../_components/BreakdownPanel'
import { EventsPanel } from '../../../_components/EventsPanel'
import { pageRowHref } from '../../../_components/DimensionPanels'
import {
  AnalyticsFiltersProvider,
  type AnalyticsFiltersContextValue,
} from '../../../_components/analytics-filters-context'
import { formatNumber } from '../../../_components/format'

interface ViewProps {
  projectId: string
  propertyId: string
  userId: string
}

type DateSelection = { dateRange: DateRange; presetId: string | null }

function Stat({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5 px-3 py-1.5">
      <span className="truncate text-[12px] text-muted-foreground">
        {label}
      </span>
      <span className="text-[20px] font-semibold text-foreground">
        {children}
      </span>
    </div>
  )
}

/**
 * One identified user's activity in a property: the property page's series,
 * pages and events read with a `userId` filter.
 */
export function View({ projectId, propertyId, userId }: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const { property } = useAnalyticsProperty(projectId, propertyId)
  const type = propertyType(property)
  const mobile = type === AnalyticsPropertyType.Mobile

  const propertyCreatedAt = property?.$createdAt
  const presetContext = useMemo(
    () => ({
      since: propertyCreatedAt ? new Date(propertyCreatedAt) : undefined,
    }),
    [propertyCreatedAt],
  )
  // Opens on the range the property page last saved; changes here aren't saved.
  const chartPrefs = useAnalyticsChartPrefs(presetContext)
  const [{ dateRange, presetId }, setDateSelection] = useState<DateSelection>(
    () => ({
      dateRange: chartPrefs.initial.dateRange,
      presetId: chartPrefs.initial.presetId,
    }),
  )
  const range = useMemo(
    () => toAnalyticsRange(dateRange) ?? getDefaultAnalyticsRange(),
    [dateRange],
  )
  // Hourly buckets date "last seen" to the hour, up to the hourly cap.
  const interval = resolveAnalyticsChartInterval('1h', dateRange)

  const handleDateRangeChange = useCallback(
    (next: DateRange | undefined) => {
      const normalized = normalizeUsageDateRangeSelection(next)
      if (!normalized?.from) return
      const nextRange = {
        from: normalized.from,
        to: normalized.to ?? normalized.from,
      }
      setDateSelection({
        dateRange: nextRange,
        presetId:
          findMatchingUsageDateRangePreset(nextRange, presetContext)?.value ??
          null,
      })
    },
    [presetContext],
  )

  const userFilter = useMemo(() => equalFilterEntry('userId', userId), [userId])
  const filters = useMemo<AnalyticsFilter[]>(
    () => [{ attribute: 'userId', query: userFilter.query }],
    [userFilter],
  )

  // `userId` is events-only, so the flat aggregate refuses it: totals sum
  // the series instead.
  const { points, isLoading } = useAnalyticsEventMetrics(
    projectId,
    propertyId,
    null,
    range,
    interval,
    filters,
  )
  const totals = useMemo(() => {
    let lastSeen: string | undefined
    let visits = 0
    let pageviews = 0
    let events = 0
    for (const point of points) {
      visits += point.sessions
      pageviews += point.pageviews ?? 0
      events += point.events
      if (point.events > 0 && point.date) lastSeen = point.date
    }
    return { visits, pageviews, events, lastSeen }
  }, [points])

  const { events: eventRows, isLoading: eventsLoading } = useAnalyticsEvents(
    projectId,
    propertyId,
    range,
    undefined,
    filters,
  )

  const { refresh, isRefreshing } = useRefreshAnalyticsProperty(
    projectId,
    propertyId,
  )

  // Cards here are already scoped to the user, so a value click opens the
  // property page filtered by the user and that value.
  const filtersContext = useMemo((): AnalyticsFiltersContextValue => {
    const filterMap: FilterMap = new Map([[userFilter.key, userFilter.query]])
    const openFiltered = (key: CompactFilterKey, query: string) =>
      navigate({
        to: '/projects/$projectId/analytics/$propertyId',
        params: { projectId, propertyId },
        search: {
          query: mapToQueryParam(new Map([...filterMap, [key, query]])),
        },
      })
    return {
      filterMap,
      filters,
      trafficKind: null,
      toggleTrafficKind: () => {},
      isFilterActive: (attribute, value) =>
        attribute === 'userId' && value === userId,
      addEqualFilter: (attribute, value) => {
        const entry = equalFilterEntry(attribute, value)
        openFiltered(entry.key, entry.query)
      },
      onApplyFilter: openFiltered,
      onRemoveFilter: () => {},
      onClearAllFilters: () => {},
      onApplySavedFilterQuery: () => {},
    }
  }, [filters, userFilter, userId, navigate, projectId, propertyId])

  const pageTabs = useMemo<BreakdownTab[]>(
    () => [
      {
        id: 'top',
        label: mobile ? 'Top screens' : 'Top pages',
        dimension: AnalyticsDimension.Page,
      },
    ],
    [mobile],
  )
  const showPages = type !== AnalyticsPropertyType.Agent

  const loading = isLoading && points.length === 0
  const count = (value: number) => (loading ? '-' : formatNumber(value))

  return (
    <AnalyticsFiltersProvider value={filtersContext}>
      <div className="flex flex-col">
        <ServiceHeader
          title={
            <div className="flex min-w-0 items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                asChild
                className="h-7 w-7 p-0"
                aria-label={t('Back to property')}
              >
                <Link
                  to="/projects/$projectId/analytics/$propertyId"
                  params={{ projectId, propertyId }}
                >
                  <ArrowLeft className="h-4 w-4" />
                </Link>
              </Button>
              <span className="truncate">{t('User')}</span>
              <CopyableId
                id={userId}
                size="xs"
                maxWidth={280}
                className="shrink-0"
              />
            </div>
          }
          beforeRefreshButtons={
            <DateRangePicker
              dateRange={dateRange}
              onDateRangeChange={handleDateRangeChange}
              presetId={presetId}
              presetGroups={ANALYTICS_DATE_RANGE_PRESET_GROUPS}
              presetContext={presetContext}
              className="h-9 shrink-0"
            />
          }
          showRefresh
          onRefresh={() => void refresh()}
          isRefreshing={isRefreshing}
          showToolbarBottomBorder
          fullWidthBorder
          fullWidth
        />

        <div className="border-b border-border px-4 py-3 sm:px-6">
          <div className="-mx-3 grid grid-cols-2 gap-1 sm:grid-cols-4">
            <Stat label={t(mobile ? 'Sessions' : 'Visits')}>
              {count(totals.visits)}
            </Stat>
            {showPages ? (
              <Stat label={t(mobile ? 'Screen views' : 'Pageviews')}>
                {count(totals.pageviews)}
              </Stat>
            ) : null}
            <Stat label={t('Events')}>{count(totals.events)}</Stat>
            <Stat label={t('Last seen')}>
              {totals.lastSeen ? (
                <DateTooltip date={totals.lastSeen} className="text-[20px]" />
              ) : (
                <span className="text-muted-foreground">-</span>
              )}
            </Stat>
          </div>
        </div>

        <div className="mx-auto grid w-full max-w-7xl gap-4 px-4 py-6 sm:px-6 lg:grid-cols-2">
          {showPages ? (
            <BreakdownPanel
              projectId={projectId}
              propertyId={propertyId}
              range={range}
              title={mobile ? 'Screens' : 'Pages'}
              description={
                mobile
                  ? "This user's most used screens, by events"
                  : "This user's most visited pages, by events"
              }
              tabs={pageTabs}
              // One user is one visitor on every row, so rank by events.
              valueKey="events"
              ranked
              mono
              mobile={mobile}
              rowHref={
                mobile
                  ? undefined
                  : (value, tabId) =>
                      pageRowHref(value, tabId, property?.domain)
              }
              emptyLabel="No activity from this user in this range"
            />
          ) : null}
          <div className={showPages ? undefined : 'lg:col-span-2'}>
            <EventsPanel
              projectId={projectId}
              propertyId={propertyId}
              range={range}
              events={eventRows}
              isLoading={eventsLoading}
              property={property}
            />
          </div>
        </div>
      </div>
    </AnalyticsFiltersProvider>
  )
}
