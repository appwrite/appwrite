import { Fragment, useMemo, useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useQueries } from '@tanstack/react-query'
import { isToday, isYesterday } from 'date-fns'
import {
  AnalyticsDimension,
  AnalyticsPropertyType,
  type Models,
} from '@appwrite.io/console'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import {
  ArrowUpRight,
  BarChart3,
  ChevronRight,
  Fingerprint,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { isKnownBreakdownValue } from '@/lib/analytics/breakdown-values'
import {
  equalFilterEntry,
  type AnalyticsFilter,
} from '@/lib/analytics/analytics-filters'
import {
  ANALYTICS_PROPERTY_TYPE_META,
  propertyType,
} from '@/lib/analytics-wizard/property-types'
import { resolveCountryDisplayName } from '@/lib/locale/country-lookups'
import {
  ANALYTICS_PAGEVIEW_EVENT,
  analyticsEventMetricsQueryOptions,
  getDefaultAnalyticsRange,
  getPreviousAnalyticsRange,
  isCustomAnalyticsEvent,
  useAnalyticsBreakdown,
  useAnalyticsProperties,
  useCountryLookups,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import {
  OverviewStatTiles,
  type OverviewStatTile,
} from '../../shared/OverviewStatTiles'
import {
  analyticsChangePercent,
  buildAnalyticsChartPoints,
  type AnalyticsChartPoint,
} from '../../analytics/_components/chart-series'
import { formatNumber } from '../../analytics/_components/format'
import {
  BreakdownRow,
  BrowserIcon,
  CountryFlag,
  SourceFavicon,
} from '../../analytics/_components/BreakdownRow'

/** Properties read for the tab; projects rarely track more. */
const PROPERTIES_LIMIT = 100
/** Rows fetched per list, re-ranked by this user's numbers. */
const RANKED_ROWS = 100
/** Rows shown per list. */
const LIST_ROWS = 5
/** Days in the window, for the "Active days" hint. */
const RANGE_DAYS = 30
/** The mobile SDKs' automatic screen event, counted as a screen view. */
const SCREEN_VIEW_EVENT = 'screen_view'

/**
 * One colour per property, in the order the table lists them, so a bar, its
 * legend dot and its table row read as the same thing.
 */
const PROPERTY_COLORS = [
  'var(--chart-brand)',
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-4)',
  'var(--chart-5)',
  'var(--chart-3)',
]

/** The same filter the analytics page writes for "User is X". */
function userFilter(userId: string): AnalyticsFilter {
  return {
    attribute: 'userId',
    query: equalFilterEntry('userId', userId).query,
  }
}

function eventFilter(eventName: string): AnalyticsFilter {
  return {
    attribute: 'eventName',
    query: equalFilterEntry('eventName', eventName).query,
  }
}

type Totals = {
  events: number
  sessions: number
  pageviews: number
}

function sumPoints(points: readonly AnalyticsChartPoint[]): Totals {
  return points.reduce(
    (sum, point) => ({
      events: sum.events + point.events,
      sessions: sum.sessions + point.sessions,
      pageviews: sum.pageviews + point.pageviews,
    }),
    { events: 0, sessions: 0, pageviews: 0 },
  )
}

/** One property as this user used it over the window. */
type PropertyActivity = {
  property: Models.AnalyticsProperty
  type: AnalyticsPropertyType
  color: string
  points: AnalyticsChartPoint[]
  totals: Totals
  previous: Totals
  lastActive: Date | null
  loaded: boolean
}

function lastActiveLabel(date: Date, t: (key: string) => string): string {
  if (isToday(date)) return t('Today')
  if (isYesterday(date)) return t('Yesterday')
  return formatLocalizedDate(date, 'MMM d')
}

/**
 * Everything this user did across the project's analytics, over the last 30
 * days: top-level numbers against the previous 30 days, daily activity split
 * by property, one row per site or app, and the selected property's detail
 * (pages, events, sources, locations, technology).
 *
 * A property is listed when it identifies signed-in users, or when this
 * user's ID reached it anyway (sent by a server with an API key).
 */
export function UserAnalytics({
  projectId,
  userId,
  displayName,
}: {
  projectId: string
  userId: string
  displayName: string
}) {
  const t = useT()
  const range = useMemo(() => getDefaultAnalyticsRange(), [])
  const previousRange = useMemo(() => getPreviousAnalyticsRange(range), [range])
  // One series per property covers both windows: the comparison costs no
  // extra request.
  const seriesRange = useMemo<AnalyticsRange>(
    () => ({ startAt: previousRange.startAt, endAt: range.endAt }),
    [previousRange, range],
  )
  const filters = useMemo(() => [userFilter(userId)], [userId])

  const { properties, isLoading, error } = useAnalyticsProperties(
    projectId,
    0,
    PROPERTIES_LIMIT,
    '',
  )

  // `userId` is an events-only filter: the flat aggregate refuses it, so the
  // totals come from the daily series, which also draws the chart.
  const series = useQueries({
    queries: properties.map((property) =>
      analyticsEventMetricsQueryOptions(
        projectId,
        property.$id,
        null,
        seriesRange,
        '1d',
        filters,
      ),
    ),
  })
  const seriesKey = series.map((result) => result.dataUpdatedAt).join()
  const seriesLoading = series.some((result) => result.isLoading)

  const activity = useMemo(() => {
    const rows = properties.map((property, index) => {
      const metrics = series[index]?.data?.points ?? []
      const points = buildAnalyticsChartPoints(metrics, range, '1d')
      const previous = sumPoints(
        buildAnalyticsChartPoints(metrics, previousRange, '1d'),
      )
      const lastActive =
        [...points].reverse().find((point) => point.events > 0)?.day ?? null
      return {
        property,
        type: propertyType(property),
        points,
        totals: sumPoints(points),
        previous,
        lastActive,
        loaded: series[index]?.data !== undefined,
      }
    })
    // Identifying properties always; others once this user turns up in them.
    const listed = rows.filter(
      (row) => row.property.identifyUsers || row.totals.events > 0,
    )
    // Most recently active first; quiet properties after, by name.
    listed.sort((a, b) => {
      const byDate =
        (b.lastActive?.getTime() ?? 0) - (a.lastActive?.getTime() ?? 0)
      if (byDate !== 0) return byDate
      if (b.totals.events !== a.totals.events)
        return b.totals.events - a.totals.events
      return a.property.name.localeCompare(b.property.name)
    })
    return listed.map(
      (row, index): PropertyActivity => ({
        ...row,
        color: PROPERTY_COLORS[index % PROPERTY_COLORS.length],
      }),
    )
    // `series` is a new array every render; `seriesKey` says when its data changed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [properties, seriesKey, range, previousRange])

  // Null until the viewer opens or closes a row: by default the most
  // recently active property is open.
  const [expanded, setExpanded] = useState<ReadonlySet<string> | null>(null)
  const firstActive = activity.find((row) => row.totals.events > 0)
  const open =
    expanded ??
    new Set(firstActive ? [firstActive.property.$id] : ([] as string[]))
  const toggle = (propertyId: string) => {
    const next = new Set(open)
    if (next.has(propertyId)) next.delete(propertyId)
    else next.add(propertyId)
    setExpanded(next)
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="h-[74px] animate-pulse rounded-xl border border-border bg-card/50" />
        <div className="h-[300px] animate-pulse rounded-xl border border-border bg-card/50" />
      </div>
    )
  }

  if (error) {
    return (
      <EmptyCard
        title={t('Could not load analytics')}
        description={
          error instanceof Error ? error.message : t('Unknown error')
        }
      />
    )
  }

  if (properties.length === 0) {
    return (
      <EmptyCard
        title={t('No analytics yet')}
        description={t(
          "Add an analytics property and turn on User identification to see each signed-in user's pages and events here.",
        )}
        action={
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <Link
              to="/projects/$projectId/analytics/add"
              params={{ projectId }}
            >
              {t('Add analytics')}
            </Link>
          </Button>
        }
      />
    )
  }

  const identifying = properties.some((property) => property.identifyUsers)

  if (!identifying && activity.length === 0) {
    if (seriesLoading) {
      return (
        <div className="h-[74px] animate-pulse rounded-xl border border-border bg-card/50" />
      )
    }
    return (
      <EmptyCard
        icon={Fingerprint}
        title={t('Link {name} to their analytics').replace(
          '{name}',
          displayName,
        )}
        description={t(
          "Turn on User identification in a property's settings to record the signed-in user on every event, so their pages, events and sessions show here.",
        )}
        action={
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px]"
            asChild
          >
            <Link
              to="/projects/$projectId/analytics/$propertyId/settings"
              params={{ projectId, propertyId: properties[0].$id }}
            >
              {t('Open property settings')}
            </Link>
          </Button>
        }
      />
    )
  }

  return (
    <div className="space-y-6">
      <SummaryTiles activity={activity} loading={seriesLoading} />
      <ActivityChart activity={activity} loading={seriesLoading} />
      <PropertiesTable
        projectId={projectId}
        userId={userId}
        activity={activity}
        range={range}
        filters={filters}
        open={open}
        onToggle={toggle}
      />
    </div>
  )
}

// ─── Summary ────────────────────────────────────────────────────────────────

/**
 * Top-level numbers across every listed property, against the previous 30
 * days, in the same tiles Functions and Sites open with.
 */
function SummaryTiles({
  activity,
  loading,
}: {
  activity: PropertyActivity[]
  loading: boolean
}) {
  const t = useT()
  const days = activity[0]?.points.length ?? 0
  const daily = (key: keyof Totals) =>
    Array.from({ length: days }, (_, index) =>
      activity.reduce((sum, row) => sum + (row.points[index]?.[key] ?? 0), 0),
    )
  const total = (key: keyof Totals) =>
    activity.reduce((sum, row) => sum + row.totals[key], 0)
  const previous = (key: keyof Totals) =>
    activity.reduce((sum, row) => sum + row.previous[key], 0)
  // Only compare when the previous window had something to compare with.
  const change = (key: keyof Totals) =>
    previous(key) > 0
      ? analyticsChangePercent(total(key), previous(key))
      : undefined

  const activeDays = daily('events').filter((value) => value > 0).length
  const last = activity.find((row) => row.lastActive)
  const types = new Set(activity.map((row) => row.type))
  const viewsLabel =
    types.size === 1 && types.has(AnalyticsPropertyType.Mobile)
      ? t('Screen views')
      : types.has(AnalyticsPropertyType.Mobile)
        ? t('Views')
        : t('Pageviews')
  const showViews = !(
    types.size === 1 && types.has(AnalyticsPropertyType.Agent)
  )

  const items: OverviewStatTile[] = [
    {
      label: t('Events'),
      value: formatNumber(total('events')),
      change: change('events'),
      points: daily('events'),
      loading,
    },
    {
      label: t('Visits'),
      value: formatNumber(total('sessions')),
      change: change('sessions'),
      points: daily('sessions'),
      loading,
    },
    ...(showViews
      ? [
          {
            label: viewsLabel,
            value: formatNumber(total('pageviews')),
            change: change('pageviews'),
            points: daily('pageviews'),
            loading,
          },
        ]
      : []),
    {
      label: t('Active days'),
      value: formatNumber(activeDays),
      hint: t('of {count}').replace('{count}', String(RANGE_DAYS)),
      loading,
    },
    {
      label: t('Last seen'),
      value: last?.lastActive
        ? lastActiveLabel(last.lastActive, t)
        : t('Not in this period'),
      hint: last && activity.length > 1 ? last.property.name : undefined,
      loading,
    },
  ]

  return <OverviewStatTiles items={items} />
}

// ─── Activity ───────────────────────────────────────────────────────────────

type ChartRow = { label: string; fullDate: string } & Record<
  string,
  number | string
>

/** Daily events, stacked by property, with a legend that names the colours. */
function ActivityChart({
  activity,
  loading,
}: {
  activity: PropertyActivity[]
  loading: boolean
}) {
  const t = useT()
  const active = activity.filter((row) => row.totals.events > 0)
  const days = activity[0]?.points ?? []
  const data = days.map((point, index): ChartRow => {
    const row: ChartRow = { label: point.date, fullDate: point.fullDate }
    for (const entry of active) {
      row[entry.property.$id] = entry.points[index]?.events ?? 0
    }
    return row
  })

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex min-h-16 flex-wrap items-center justify-between gap-x-6 gap-y-2 px-6 py-3">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {t('Activity')}
          </h3>
          <p className="text-[12px] leading-4 text-muted-foreground">
            {t('Events per day, last 30 days')}
          </p>
        </div>
        {active.length > 1 ? (
          <ul className="flex flex-wrap items-center gap-x-4 gap-y-1">
            {active.map((row) => (
              <li
                key={row.property.$id}
                className="flex items-center gap-1.5 text-[12px] text-muted-foreground"
              >
                <span
                  className="h-2 w-2 shrink-0 rounded-[2px]"
                  style={{ backgroundColor: row.color }}
                  aria-hidden
                />
                {row.property.name}
              </li>
            ))}
          </ul>
        ) : null}
      </div>
      <div className="border-t border-border" />
      <div className={cn('relative h-[220px] px-4 pb-3 pt-4', FORCE_LTR_CLASS)}>
        {loading ? (
          <div className="absolute inset-x-6 bottom-8 top-6 animate-pulse rounded-md bg-muted/40" />
        ) : active.length === 0 ? (
          <div className="flex h-full items-center justify-center text-[13px] text-muted-foreground">
            {t('No events from this user in the last 30 days')}
          </div>
        ) : (
          <div className={cn('h-full', USAGE_CHART_FADE_IN_CLASS_NAME)}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={data}
                margin={{ top: 4, right: 4, bottom: 0, left: 4 }}
              >
                <CartesianGrid
                  vertical={false}
                  stroke="var(--border)"
                  strokeDasharray="3 3"
                />
                <YAxis hide allowDecimals={false} />
                <XAxis
                  dataKey="label"
                  tickLine={false}
                  axisLine={false}
                  interval="preserveStartEnd"
                  minTickGap={24}
                  tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }}
                />
                <Tooltip
                  isAnimationActive={false}
                  cursor={{ fill: 'var(--muted)', opacity: 0.4 }}
                  content={({ active: hovering, payload }) => {
                    const row = payload?.[0]?.payload as ChartRow | undefined
                    if (!hovering || !row) return null
                    return (
                      <div className="min-w-[160px] rounded-md border border-border bg-popover px-2.5 py-2 text-[11px]">
                        <p className="mb-1 text-muted-foreground">
                          {row.fullDate}
                        </p>
                        {active.map((entry) => (
                          <p
                            key={entry.property.$id}
                            className="flex items-center gap-2"
                          >
                            <span
                              className="h-2 w-2 shrink-0 rounded-[2px]"
                              style={{ backgroundColor: entry.color }}
                            />
                            <span className="min-w-0 flex-1 truncate text-foreground">
                              {entry.property.name}
                            </span>
                            <span className="font-medium tabular-nums text-foreground">
                              {formatNumber(
                                Number(row[entry.property.$id] ?? 0),
                              )}
                            </span>
                          </p>
                        ))}
                      </div>
                    )
                  }}
                />
                {active.map((entry, index) => (
                  <Bar
                    key={entry.property.$id}
                    dataKey={entry.property.$id}
                    stackId="events"
                    fill={entry.color}
                    maxBarSize={18}
                    isAnimationActive={false}
                    // Round only the top of the stack.
                    radius={index === active.length - 1 ? [3, 3, 0, 0] : 0}
                  />
                ))}
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>
    </div>
  )
}

// ─── Properties ─────────────────────────────────────────────────────────────

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/**
 * One row per site or app, most recently active first. A row opens in place
 * to show what the user did there, so the detail always sits under the
 * property it describes, however long the list. The arrow opens the user's
 * page in that property's analytics.
 */
function PropertiesTable({
  projectId,
  userId,
  activity,
  range,
  filters,
  open,
  onToggle,
}: {
  projectId: string
  userId: string
  activity: PropertyActivity[]
  range: AnalyticsRange
  filters: readonly AnalyticsFilter[]
  open: ReadonlySet<string>
  onToggle: (propertyId: string) => void
}) {
  const t = useT()

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold leading-5 text-foreground">
          {t('Properties')}
        </h3>
        <p className="text-[12px] leading-4 text-muted-foreground">
          {t('Where this user was active, last 30 days')}
        </p>
      </div>
      <div className="border-t border-border" />
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Property')}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Events')}
            </TableHead>
            <TableHead
              className={cn(HEAD_CLASS, 'hidden text-end sm:table-cell')}
            >
              {t('Visits')}
            </TableHead>
            <TableHead
              className={cn(HEAD_CLASS, 'hidden text-end md:table-cell')}
            >
              {t('Views')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'text-end')}>
              {t('Last active')}
            </TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[60px]')} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {activity.map((row) => {
            const isOpen = open.has(row.property.$id)
            const quiet = row.loaded && row.totals.events === 0
            const detailId = `user-analytics-${row.property.$id}`
            const number = (value: number) =>
              row.loaded ? (
                formatNumber(value)
              ) : (
                <span className="inline-block h-3 w-8 animate-pulse rounded bg-muted align-middle" />
              )
            // Type first, then where it runs: every row is two lines.
            const subtitle = [
              t(ANALYTICS_PROPERTY_TYPE_META[row.type].label),
              row.property.domain,
            ]
              .filter(Boolean)
              .join(' · ')
            return (
              <Fragment key={row.property.$id}>
                <TableRow
                  onClick={() => onToggle(row.property.$id)}
                  className={cn(
                    'cursor-pointer',
                    isOpen && 'border-b-0 bg-muted/20 hover:bg-muted/30',
                  )}
                >
                  <TableCell className="px-4 py-3">
                    <button
                      type="button"
                      aria-expanded={isOpen}
                      aria-controls={detailId}
                      onClick={(event) => {
                        event.stopPropagation()
                        onToggle(row.property.$id)
                      }}
                      className="flex min-w-0 items-center gap-3 text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
                    >
                      <ChevronRight
                        className={cn(
                          'h-4 w-4 shrink-0 text-muted-foreground transition-transform rtl:-scale-x-100',
                          isOpen && 'rotate-90 rtl:rotate-90',
                        )}
                        aria-hidden
                      />
                      <span
                        className={cn(
                          'h-2.5 w-2.5 shrink-0 rounded-[3px]',
                          quiet && 'opacity-30',
                        )}
                        style={{ backgroundColor: row.color }}
                        aria-hidden
                      />
                      <span className="min-w-0">
                        <span className="block truncate text-[13px] font-medium text-foreground">
                          {row.property.name}
                        </span>
                        <span className="block truncate text-[12px] text-muted-foreground">
                          {subtitle}
                        </span>
                      </span>
                    </button>
                  </TableCell>
                  <TableCell
                    className={cn(
                      'px-4 py-3 text-end text-[13px] tabular-nums',
                      quiet
                        ? 'text-muted-foreground'
                        : 'font-medium text-foreground',
                    )}
                  >
                    {number(row.totals.events)}
                  </TableCell>
                  <TableCell className="hidden px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground sm:table-cell">
                    {number(row.totals.sessions)}
                  </TableCell>
                  <TableCell className="hidden px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground md:table-cell">
                    {row.type === AnalyticsPropertyType.Agent
                      ? '—'
                      : number(row.totals.pageviews)}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end text-[13px] text-muted-foreground">
                    {!row.loaded
                      ? number(0)
                      : row.lastActive
                        ? lastActiveLabel(row.lastActive, t)
                        : t('No activity')}
                  </TableCell>
                  <TableCell className="px-4 py-3 text-end">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
                      asChild
                    >
                      {/* The user's own page in that property, with its own
                        range picker, series and filters. */}
                      <Link
                        to="/projects/$projectId/analytics/$propertyId/users/$userId"
                        params={{
                          projectId,
                          propertyId: row.property.$id,
                          userId,
                        }}
                        onClick={(event) => event.stopPropagation()}
                        title={t('View in analytics')}
                        aria-label={`${t('View in analytics')}: ${row.property.name}`}
                      >
                        <ArrowUpRight className="h-4 w-4" />
                      </Link>
                    </Button>
                  </TableCell>
                </TableRow>
                {isOpen ? (
                  <TableRow
                    id={detailId}
                    className="hover:bg-transparent border-b border-border"
                  >
                    <TableCell colSpan={6} className="p-0">
                      <PropertyDetail
                        projectId={projectId}
                        activity={row}
                        range={range}
                        filters={filters}
                      />
                    </TableCell>
                  </TableRow>
                ) : null}
              </Fragment>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}

// ─── Detail ─────────────────────────────────────────────────────────────────

type ListSpec = {
  id: string
  title: string
  unit: 'events' | 'visits' | 'views'
  dimension: AnalyticsDimension
  propKey?: string
  /** Extra filter for this list (pageviews only, for pages). */
  eventName?: string
  include?: (value: string) => boolean
  mono?: boolean
  leading?: (value: string) => ReactNode
  formatLabel?: (value: string) => string
  emptyLabel: string
}

/**
 * One property, seen through this user: what they opened, what they did, how
 * they arrived, where from, and on what. Mounted only while its row is open,
 * so a long list costs nothing until a row is opened.
 */
function PropertyDetail({
  projectId,
  activity,
  range,
  filters,
}: {
  projectId: string
  activity: PropertyActivity
  range: AnalyticsRange
  filters: readonly AnalyticsFilter[]
}) {
  const t = useT()
  const { lookups: countryLookups } = useCountryLookups()
  const { property, type } = activity

  const lists = useMemo(
    () => detailLists(type, countryLookups),
    [type, countryLookups],
  )

  if (activity.loaded && activity.totals.events === 0) {
    return (
      <div className="border-t border-border/60 bg-muted/10 px-6 py-8 text-center text-[13px] text-muted-foreground">
        {t('No events from this user in the last 30 days')}
      </div>
    )
  }

  return (
    <div className="grid gap-px border-t border-border/60 bg-border/60 md:grid-cols-2 xl:grid-cols-3">
      {lists.map((list) => (
        <RankedList
          key={list.id}
          projectId={projectId}
          propertyId={property.$id}
          range={range}
          filters={filters}
          list={list}
        />
      ))}
    </div>
  )
}

/** The lists that describe a user on each kind of property. */
function detailLists(
  type: AnalyticsPropertyType,
  countryLookups: ReturnType<typeof useCountryLookups>['lookups'],
): ListSpec[] {
  const events: ListSpec = {
    id: 'events',
    title: 'Events',
    unit: 'events',
    dimension: AnalyticsDimension.EventName,
    include: isCustomAnalyticsEvent,
    mono: true,
    emptyLabel: 'No custom events',
  }
  const locations: ListSpec = {
    id: 'locations',
    title: 'Locations',
    unit: 'visits',
    dimension: AnalyticsDimension.Country,
    leading: (value) => <CountryFlag code={value} />,
    formatLabel: (value) =>
      countryLookups ? resolveCountryDisplayName(value, countryLookups) : value,
    emptyLabel: 'No locations',
  }
  const systems: ListSpec = {
    id: 'os',
    title: 'Operating systems',
    unit: 'visits',
    dimension: AnalyticsDimension.OperatingSystem,
    emptyLabel: 'No operating systems',
  }
  if (type === AnalyticsPropertyType.Agent) {
    return [
      events,
      {
        id: 'models',
        title: 'Models',
        unit: 'events',
        dimension: AnalyticsDimension.Props,
        propKey: 'model',
        mono: true,
        emptyLabel: 'No model calls',
      },
      {
        id: 'tools',
        title: 'Tools',
        unit: 'events',
        dimension: AnalyticsDimension.Props,
        propKey: 'tool',
        mono: true,
        emptyLabel: 'No tool calls',
      },
    ]
  }
  if (type === AnalyticsPropertyType.Mobile) {
    return [
      {
        id: 'screens',
        title: 'Screens',
        unit: 'views',
        dimension: AnalyticsDimension.Page,
        eventName: SCREEN_VIEW_EVENT,
        mono: true,
        emptyLabel: 'No screens viewed',
      },
      events,
      locations,
      {
        id: 'devices',
        title: 'Devices',
        unit: 'visits',
        dimension: AnalyticsDimension.Device,
        emptyLabel: 'No devices',
      },
      systems,
      {
        id: 'os-versions',
        title: 'OS versions',
        unit: 'visits',
        dimension: AnalyticsDimension.OperatingSystemVersion,
        emptyLabel: 'No OS versions',
      },
    ]
  }
  return [
    {
      id: 'pages',
      title: 'Pages',
      unit: 'views',
      dimension: AnalyticsDimension.Page,
      eventName: ANALYTICS_PAGEVIEW_EVENT,
      mono: true,
      emptyLabel: 'No pages viewed',
    },
    events,
    {
      id: 'sources',
      title: 'Sources',
      unit: 'visits',
      dimension: AnalyticsDimension.ReferrerSource,
      leading: (value) => <SourceFavicon value={value} />,
      emptyLabel: 'No sources',
    },
    locations,
    {
      id: 'browsers',
      title: 'Browsers',
      unit: 'visits',
      dimension: AnalyticsDimension.Browser,
      leading: (value) => <BrowserIcon name={value} />,
      emptyLabel: 'No browsers',
    },
    systems,
  ]
}

/**
 * Five rows ranked by this user's own numbers. The API ranks by visitors,
 * which is 1 for every row of a single user, so rows are fetched wider and
 * re-ranked here: by events for pages and events, by visits for the rest.
 */
function RankedList({
  projectId,
  propertyId,
  range,
  filters,
  list,
}: {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  filters: readonly AnalyticsFilter[]
  list: ListSpec
}) {
  const t = useT()
  const listFilters = useMemo(
    () =>
      list.eventName ? [...filters, eventFilter(list.eventName)] : filters,
    [filters, list.eventName],
  )
  const { breakdown, isLoading, error } = useAnalyticsBreakdown(
    projectId,
    propertyId,
    list.dimension,
    range,
    RANKED_ROWS,
    true,
    listFilters,
    list.propKey,
  )
  const measure = (entry: Models.AnalyticsMetric) =>
    list.unit === 'visits' ? entry.sessions : entry.events
  const rows = breakdown
    .filter(
      (entry) =>
        isKnownBreakdownValue(entry.value) &&
        (!list.include || list.include(entry.value!)),
    )
    .sort((a, b) => measure(b) - measure(a))
    .slice(0, LIST_ROWS)
  const total = rows.reduce((sum, entry) => sum + measure(entry), 0)
  const max = rows.reduce((top, entry) => Math.max(top, measure(entry)), 0)

  return (
    <div className="min-w-0 bg-card px-5 py-4">
      <div className="mb-2 flex items-baseline justify-between gap-2">
        <p className="text-[12px] font-medium text-foreground">
          {t(list.title)}
        </p>
        <p className="text-[11px] text-muted-foreground">{t(list.unit)}</p>
      </div>
      <div className="h-[148px] space-y-0.5">
        {isLoading ? (
          Array.from({ length: LIST_ROWS }).map((_, index) => (
            <div
              key={index}
              className="h-7 animate-pulse rounded-md bg-muted/50"
              style={{ opacity: 1 - index * 0.15 }}
            />
          ))
        ) : error || rows.length === 0 ? (
          <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
            {error instanceof Error ? error.message : t(list.emptyLabel)}
          </div>
        ) : (
          rows.map((entry) => (
            <BreakdownRow
              key={entry.value}
              label={list.formatLabel?.(entry.value!) ?? entry.value!}
              leading={list.leading?.(entry.value!)}
              value={measure(entry)}
              share={total > 0 ? (measure(entry) / total) * 100 : 0}
              barPercent={max > 0 ? (measure(entry) / max) * 100 : 0}
              mono={list.mono}
            />
          ))
        )}
      </div>
    </div>
  )
}

function EmptyCard({
  icon: Icon = BarChart3,
  title,
  description,
  action,
}: {
  icon?: typeof BarChart3
  title: string
  description: string
  action?: ReactNode
}) {
  return (
    <div className="rounded-xl border border-border bg-card/50 px-6 py-10 text-center">
      <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-lg border border-border bg-background">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <p className="mt-4 text-[14px] font-medium text-foreground">{title}</p>
      <p className="mx-auto mt-1.5 max-w-md text-[13px] text-muted-foreground">
        {description}
      </p>
      {action ? <div className="mt-5 flex justify-center">{action}</div> : null}
    </div>
  )
}
