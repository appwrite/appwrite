import { useId } from 'react'
import { useQueries } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Smartphone } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import {
  analyticsEventMetricsQueryOptions,
  useAnalyticsEvents,
  type AnalyticsChartInterval,
  type AnalyticsRange,
} from '@/lib/react-query/hooks'
import { BreakdownHeader, BreakdownMessage } from './BreakdownPanel'
import { ChangeBadge } from './ChangeBadge'
import { useAnalyticsFilters } from './analytics-filters-context'
import {
  analyticsChangePercent,
  buildAnalyticsChartPoints,
} from './chart-series'
import { formatNumber } from './format'

const INSTALL_EVENT = 'first_open'
const UPDATE_EVENT = 'app_updated'

// Asked without the page's filters (mobile opens on humans only), which can
// match none of these events even when the SDK sends them.
const SDK_EVENTS_FILTERS = [
  {
    attribute: 'eventName',
    query: Query.equal('eventName', [INSTALL_EVENT, UPDATE_EVENT]),
  },
] as const

const INSTALLS_COLOR = 'var(--chart-brand)'
const UPDATES_COLOR = 'var(--chart-2)'

/** Body height shared with the Push notifications card beside it. */
export const MOBILE_CARD_BODY_CLASS = 'h-[320px]'

type InstallsPanelProps = {
  projectId: string
  propertyId: string
  range: AnalyticsRange
  interval: AnalyticsChartInterval
  /** The window the change badges compare against; none when comparison is off. */
  comparisonRange: AnalyticsRange | null
  /** Opens the tracking setup, for the empty state. */
  onOpenSetup: () => void
}

type ChartPoint = {
  label: string
  fullDate: string
  installs: number
  updates: number
}

/**
 * New installs and updates of the app, from the mobile SDKs' automatic
 * `first_open` and `app_updated` events: two totals against the comparison
 * window, and both over time.
 */
export function InstallsPanel({
  projectId,
  propertyId,
  range,
  interval,
  comparisonRange,
  onOpenSetup,
}: InstallsPanelProps) {
  const t = useT()
  const gradientId = useId().replace(/:/g, '')
  const { filters } = useAnalyticsFilters()

  const windows = comparisonRange ? [range, comparisonRange] : [range]
  const series = useQueries({
    queries: windows.flatMap((window) =>
      [INSTALL_EVENT, UPDATE_EVENT].map((event) =>
        analyticsEventMetricsQueryOptions(
          projectId,
          propertyId,
          event,
          window,
          interval,
          filters,
        ),
      ),
    ),
  })
  const pointsFor = (index: number, window: AnalyticsRange) =>
    buildAnalyticsChartPoints(
      series[index]?.data?.points ?? [],
      window,
      interval,
    )
  const installs = pointsFor(0, range)
  const updates = pointsFor(1, range)
  const total = (points: { events: number }[]) =>
    points.reduce((sum, point) => sum + point.events, 0)
  const installsTotal = total(installs)
  const updatesTotal = total(updates)
  const previous = comparisonRange
    ? {
        installs: total(pointsFor(2, comparisonRange)),
        updates: total(pointsFor(3, comparisonRange)),
      }
    : null

  const loading = series
    .slice(0, 2)
    .some((query) => query.data === undefined && !query.isError)
  const error = series.find((query) => query.isError)?.error
  // Filtered zeros only mean no SDK once the SDK's own events, unfiltered,
  // come back empty too; an error there is unknown, not empty.
  const sdkEvents = useAnalyticsEvents(
    projectId,
    filters.length > 0 ? propertyId : null,
    range,
    undefined,
    SDK_EVENTS_FILTERS,
  )
  const isEmpty =
    !loading &&
    installsTotal + updatesTotal === 0 &&
    (filters.length === 0 ||
      (!sdkEvents.isLoading &&
        !sdkEvents.error &&
        sdkEvents.events.length === 0))

  const data = installs.map(
    (point, index): ChartPoint => ({
      label: point.date,
      fullDate: point.fullDate,
      installs: point.events,
      updates: updates[index]?.events ?? 0,
    }),
  )

  return (
    <div className="flex h-full flex-col rounded-lg border border-border bg-card">
      <BreakdownHeader
        title="Installs"
        description="New installs and updates of your app"
      />
      <div className={cn('p-4', MOBILE_CARD_BODY_CLASS)}>
        {error ? (
          <BreakdownMessage>
            {error instanceof Error
              ? error.message
              : t('Could not load analytics data')}
          </BreakdownMessage>
        ) : isEmpty ? (
          <BreakdownMessage>
            <span className="flex max-w-sm flex-col items-center gap-2">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-muted">
                <Smartphone
                  className="h-4 w-4 text-muted-foreground"
                  aria-hidden
                />
              </span>
              <span className="text-[13px] font-medium text-foreground">
                {t('No installs tracked yet')}
              </span>
              <span className="text-[12px] leading-relaxed text-muted-foreground">
                {t(
                  'Add the latest tracking SDK to your app to count installs and updates.',
                )}
              </span>
              <Button
                variant="outline"
                size="sm"
                className="mt-1 h-8 text-[12px]"
                onClick={onOpenSetup}
              >
                {t('Add the tracking SDK')}
              </Button>
            </span>
          </BreakdownMessage>
        ) : (
          <div className="flex h-full flex-col gap-4">
            <div className="grid grid-cols-2 gap-4">
              <Total
                label={t('Installs')}
                color={INSTALLS_COLOR}
                value={installsTotal}
                change={
                  previous && previous.installs > 0
                    ? analyticsChangePercent(installsTotal, previous.installs)
                    : undefined
                }
                loading={loading}
              />
              <Total
                label={t('Updates')}
                color={UPDATES_COLOR}
                value={updatesTotal}
                change={
                  previous && previous.updates > 0
                    ? analyticsChangePercent(updatesTotal, previous.updates)
                    : undefined
                }
                loading={loading}
              />
            </div>
            <div className={cn('relative min-h-0 flex-1', FORCE_LTR_CLASS)}>
              {loading ? (
                <div className="absolute inset-0 animate-pulse rounded-md bg-muted/40" />
              ) : (
                <div
                  className={cn(
                    'absolute inset-0',
                    USAGE_CHART_FADE_IN_CLASS_NAME,
                  )}
                >
                  <ResponsiveContainer width="100%" height="100%">
                    <AreaChart
                      data={data}
                      margin={{ top: 4, right: 4, bottom: 0, left: 4 }}
                    >
                      <CartesianGrid
                        strokeDasharray="3 3"
                        stroke="var(--border)"
                        vertical={false}
                      />
                      <defs>
                        <linearGradient
                          id={gradientId}
                          x1="0"
                          y1="0"
                          x2="0"
                          y2="1"
                        >
                          <stop
                            offset="0%"
                            stopColor={INSTALLS_COLOR}
                            stopOpacity={0.25}
                          />
                          <stop
                            offset="100%"
                            stopColor={INSTALLS_COLOR}
                            stopOpacity={0}
                          />
                        </linearGradient>
                      </defs>
                      <YAxis hide allowDecimals={false} />
                      <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        interval="preserveStartEnd"
                        minTickGap={32}
                        tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }}
                      />
                      <Tooltip
                        isAnimationActive={false}
                        cursor={{ stroke: 'var(--border)' }}
                        content={({ active, payload }) => {
                          const point = payload?.[0]?.payload as
                            | ChartPoint
                            | undefined
                          if (!active || !point) return null
                          return (
                            <div className="min-w-[140px] rounded-md border border-border bg-popover px-2.5 py-2 text-[11px]">
                              <p className="mb-1 text-muted-foreground">
                                {point.fullDate}
                              </p>
                              <TooltipLine
                                color={INSTALLS_COLOR}
                                label={t('Installs')}
                                value={point.installs}
                              />
                              <TooltipLine
                                color={UPDATES_COLOR}
                                label={t('Updates')}
                                value={point.updates}
                              />
                            </div>
                          )
                        }}
                      />
                      <Area
                        type="monotone"
                        dataKey="updates"
                        stroke={UPDATES_COLOR}
                        strokeWidth={1.5}
                        strokeDasharray="4 3"
                        fill="none"
                        isAnimationActive={false}
                        dot={false}
                      />
                      <Area
                        type="monotone"
                        dataKey="installs"
                        stroke={INSTALLS_COLOR}
                        strokeWidth={2}
                        fill={`url(#${gradientId})`}
                        isAnimationActive={false}
                        dot={false}
                      />
                    </AreaChart>
                  </ResponsiveContainer>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function Total({
  label,
  color,
  value,
  change,
  loading,
}: {
  label: string
  color: string
  value: number
  change?: number
  loading: boolean
}) {
  return (
    <div className="min-w-0">
      <p className="flex items-center gap-1.5 text-[12px] text-muted-foreground">
        <span
          className="h-2 w-2 shrink-0 rounded-full"
          style={{ backgroundColor: color }}
          aria-hidden
        />
        {label}
      </p>
      <div className="mt-1 flex h-7 items-baseline gap-2">
        {loading ? (
          <span className="h-6 w-16 animate-pulse self-center rounded bg-muted" />
        ) : (
          <>
            <span className="text-[22px] font-semibold leading-none tabular-nums text-foreground">
              {formatNumber(value)}
            </span>
            <ChangeBadge change={change} />
          </>
        )}
      </div>
    </div>
  )
}

function TooltipLine({
  color,
  label,
  value,
}: {
  color: string
  label: string
  value: number
}) {
  return (
    <p className="flex items-center gap-2">
      <span
        className="h-2 w-2 shrink-0 rounded-full"
        style={{ backgroundColor: color }}
      />
      <span className="flex-1 text-foreground">{label}</span>
      <span className="font-medium tabular-nums text-foreground">
        {formatNumber(value)}
      </span>
    </p>
  )
}
