import { useMemo, useState } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import {
  SeriesChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import type { ReviewDay } from '@/lib/appwrite/reviews'
import { useT } from '@/lib/i18n/translate'
import { useLocalizedDateFormat } from '@/lib/i18n/use-localized-date-format'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { cn } from '@/lib/utils'

type View = 'reviews' | 'findings'

type SeriesKey =
  | 'approved'
  | 'commented'
  | 'changesRequested'
  | 'severe'
  | 'other'

type Series = {
  key: SeriesKey
  label: string
  /** SVG fill. Verdicts and severity mean good or bad, so they take status colors. */
  fill: string
  fillOpacity?: number
  /** Applied to the series dot, so it shows the segment's opacity. */
  dot?: string
}

/**
 * Stacked from the baseline up. Commented sits between approved and changes
 * requested, so green and red never touch.
 */
const SERIES: Record<View, Series[]> = {
  reviews: [
    {
      key: 'approved',
      label: 'Approved',
      fill: 'var(--color-emerald-600)',
    },
    {
      key: 'commented',
      label: 'Commented',
      fill: 'var(--muted-foreground)',
      fillOpacity: 0.4,
      dot: 'opacity-40',
    },
    {
      key: 'changesRequested',
      label: 'Changes requested',
      fill: 'var(--color-red-500)',
    },
  ],
  findings: [
    {
      key: 'severe',
      label: 'Critical and major',
      fill: 'var(--color-red-500)',
    },
    {
      key: 'other',
      label: 'Minor and info',
      fill: 'var(--muted-foreground)',
      fillOpacity: 0.4,
      dot: 'opacity-40',
    },
  ],
}

/** Bars never grow past this, so a short range is not a wall of color. */
const MAX_BAR = 18

/** Surface left between stacked segments, so neighbors read apart. */
const SEGMENT_GAP = 2

/** Rounding of a column's top; the baseline end stays square. */
const RADIUS = 4

type Bucket = {
  label: string
  title: string
  reviews: number
  approved: number
  commented: number
  changesRequested: number
  findings: number
  critical: number
  major: number
  minor: number
  info: number
  severe: number
  other: number
  /** The series drawn last in the column, which gets the rounded top. */
  top: Partial<Record<View, SeriesKey>>
}

/** Past a month of days, the chart shows weeks so the columns stay readable. */
const WEEKLY_AFTER = 31

/**
 * One column per day, or per week for a longer range. Weeks are counted back
 * from today, so only the oldest one can be short.
 */
function toBuckets(
  days: ReviewDay[],
  formatDay: (date: Date, withYear?: boolean) => string,
): Bucket[] {
  const size = days.length > WEEKLY_AFTER ? 7 : 1
  const groups: ReviewDay[][] = []
  for (let end = days.length; end > 0; end -= size) {
    groups.unshift(days.slice(Math.max(0, end - size), end))
  }

  return groups.map((group) => {
    const first = new Date(group[0]!.date)
    const last = new Date(group[group.length - 1]!.date)
    const sum = (key: Exclude<keyof ReviewDay, 'date'>) =>
      group.reduce((total, day) => total + day[key], 0)

    const bucket: Bucket = {
      label: formatDay(first),
      title:
        group.length > 1
          ? `${formatDay(first)} - ${formatDay(last, true)}`
          : formatDay(first, true),
      reviews: sum('reviews'),
      approved: sum('approved'),
      commented: sum('commented'),
      changesRequested: sum('changesRequested'),
      findings: sum('findings'),
      critical: sum('critical'),
      major: sum('major'),
      minor: sum('minor'),
      info: sum('info'),
      severe: 0,
      other: 0,
      top: {},
    }
    bucket.severe = bucket.critical + bucket.major
    bucket.other = bucket.findings - bucket.severe

    for (const view of ['reviews', 'findings'] as const) {
      const drawn = SERIES[view].filter((series) => bucket[series.key] > 0)
      bucket.top[view] = drawn[drawn.length - 1]?.key
    }
    return bucket
  })
}

type SegmentProps = {
  x?: number
  y?: number
  width?: number
  height?: number
  fill?: string
  fillOpacity?: number
  payload?: Bucket
}

/**
 * One stacked segment: the column's top segment gets rounded corners, every
 * other one leaves a gap above it for the segment it carries.
 */
function segment(view: View, key: SeriesKey) {
  // Recharts hands a shape its own props, typed loosely.
  return function Segment(props: unknown) {
    const {
      x = 0,
      y = 0,
      width = 0,
      height = 0,
      fill,
      fillOpacity,
      payload,
    } = props as SegmentProps
    if (height <= 0 || width <= 0) return <g />
    const isTop = payload?.top[view] === key
    const gap = isTop ? 0 : Math.min(SEGMENT_GAP, height - 1)
    const top = y + gap
    const h = height - gap
    if (h <= 0) return <g />
    if (!isTop) {
      return (
        <rect
          x={x}
          y={top}
          width={width}
          height={h}
          fill={fill}
          fillOpacity={fillOpacity}
        />
      )
    }
    const r = Math.min(RADIUS, width / 2, h)
    return (
      <path
        d={`M${x},${top + h} V${top + r} Q${x},${top} ${x + r},${top} H${x + width - r} Q${x + width},${top} ${x + width},${top + r} V${top + h} Z`}
        fill={fill}
        fillOpacity={fillOpacity}
      />
    )
  }
}

/**
 * What the range's reviews concluded over time: reviews by verdict, or the
 * findings they posted by severity. Totals sit in the legend; each column's
 * numbers in its tooltip. Axes, grid, dots and tooltip are the Console's
 * shared chart pieces.
 */
export function ActivityChart({ days }: { days: ReviewDay[] }) {
  const t = useT()
  const { formatDateTime } = useLocalizedDateFormat()
  const [view, setView] = useState<View>('reviews')

  const buckets = useMemo(
    () =>
      toBuckets(days, (date, withYear) =>
        formatDateTime(date, {
          month: 'short',
          day: 'numeric',
          ...(withYear ? { year: 'numeric' } : {}),
          timeZone: 'UTC',
        }),
      ),
    [days, formatDateTime],
  )
  const labels = useMemo(() => buckets.map((bucket) => bucket.label), [buckets])

  const series = SERIES[view]
  const totals = useMemo(
    () =>
      Object.fromEntries(
        series.map((entry) => [
          entry.key,
          buckets.reduce((sum, bucket) => sum + bucket[entry.key], 0),
        ]),
      ) as Record<SeriesKey, number>,
    [buckets, series],
  )
  // The tallest stacked column, which the axis labels scale to.
  const max = useMemo(
    () =>
      buckets.reduce(
        (top, bucket) =>
          Math.max(
            top,
            series.reduce((sum, entry) => sum + bucket[entry.key], 0),
          ),
        0,
      ),
    [buckets, series],
  )
  const yTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(max),
    [max],
  )
  const empty = max === 0
  const weekly = days.length > WEEKLY_AFTER

  const views: { id: View; label: string }[] = [
    { id: 'reviews', label: t('Reviews') },
    { id: 'findings', label: t('Findings') },
  ]

  const caption =
    view === 'reviews'
      ? weekly
        ? t('Completed reviews per week, by verdict')
        : t('Completed reviews per day, by verdict')
      : weekly
        ? t('Findings posted per week, by severity')
        : t('Findings posted per day, by severity')

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div
        role="tablist"
        aria-label={t('Chart')}
        className="flex overflow-x-auto border-b border-border px-3"
      >
        {views.map((option) => {
          const selected = view === option.id
          return (
            <button
              key={option.id}
              type="button"
              role="tab"
              aria-selected={selected}
              onClick={() => setView(option.id)}
              className={cn(
                'relative flex shrink-0 cursor-pointer items-center whitespace-nowrap rounded-sm px-3 py-2.5 text-[13px] font-medium transition-colors',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                selected
                  ? 'text-foreground'
                  : 'text-muted-foreground hover:text-foreground/80',
              )}
            >
              {option.label}
              {selected && (
                <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
              )}
            </button>
          )
        })}
      </div>
      <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-6 pt-4">
        <p className="text-[12px] text-muted-foreground">{caption}</p>
        <ul className="flex flex-wrap items-center gap-x-4 gap-y-1">
          {series.map((entry) => (
            <li
              key={entry.key}
              className="flex items-center gap-1.5 text-[12px] text-muted-foreground"
            >
              <ChartSeriesDot color={entry.fill} className={entry.dot} />
              {t(entry.label)}
              <span className="font-medium tabular-nums text-foreground">
                {totals[entry.key].toLocaleString()}
              </span>
            </li>
          ))}
        </ul>
      </div>
      <div
        className={cn(
          'relative h-[220px] px-4 pb-3 pt-3 text-muted-foreground',
          FORCE_LTR_CLASS,
        )}
      >
        {empty ? (
          <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
            {view === 'reviews'
              ? t('No completed reviews in this range')
              : t('No findings in this range')}
          </div>
        ) : (
          <div className={cn('h-full', USAGE_CHART_FADE_IN_CLASS_NAME)}>
            <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
              <BarChart
                data={buckets}
                margin={USAGE_CHART_MARGIN}
                barCategoryGap="20%"
              >
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="var(--border)"
                  vertical={false}
                />
                <SeriesChartXAxis pointCount={buckets.length} labels={labels} />
                <UsageChartYAxis
                  tickFormatter={yTickFormatter}
                  allowDecimals={false}
                  domain={[0, 'auto']}
                />
                <Tooltip
                  isAnimationActive={false}
                  cursor={{ fill: 'var(--muted)', opacity: 0.5 }}
                  content={({ active, payload }) => {
                    const bucket = payload?.[0]?.payload as Bucket | undefined
                    if (!active || !bucket) return null
                    return <ActivityTooltip view={view} bucket={bucket} />
                  }}
                />
                {series.map((entry) => (
                  <Bar
                    key={entry.key}
                    dataKey={entry.key}
                    name={t(entry.label)}
                    stackId={view}
                    fill={entry.fill}
                    fillOpacity={entry.fillOpacity}
                    maxBarSize={MAX_BAR}
                    isAnimationActive={false}
                    shape={segment(view, entry.key)}
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

/** The tooltip box every Console chart uses: heading, one row per series, total. */
function ActivityTooltip({ view, bucket }: { view: View; bucket: Bucket }) {
  const t = useT()
  const severe = SERIES.findings[0]!
  const other = SERIES.findings[1]!
  const rows: { key: string; label: string; value: number; series: Series }[] =
    view === 'reviews'
      ? SERIES.reviews.map((entry) => ({
          key: entry.key,
          label: t(entry.label),
          value: bucket[entry.key],
          series: entry,
        }))
      : [
          {
            key: 'critical',
            label: t('Critical'),
            value: bucket.critical,
            series: severe,
          },
          {
            key: 'major',
            label: t('Major'),
            value: bucket.major,
            series: severe,
          },
          {
            key: 'minor',
            label: t('Minor'),
            value: bucket.minor,
            series: other,
          },
          { key: 'info', label: t('Info'), value: bucket.info, series: other },
        ]
  const total = view === 'reviews' ? bucket.reviews : bucket.findings

  return (
    <div className="min-w-[160px] rounded-md border border-border bg-popover px-3 py-2 text-[12px]">
      <p className="mb-1.5 text-[11px] text-muted-foreground">{bucket.title}</p>
      {rows.map((row) => (
        <div key={row.key} className="flex items-center justify-between gap-6">
          <span className="flex items-center gap-1.5 text-muted-foreground">
            <ChartSeriesDot
              color={row.series.fill}
              className={row.series.dot}
            />
            {row.label}
          </span>
          <span className="tabular-nums text-foreground">
            {row.value.toLocaleString()}
          </span>
        </div>
      ))}
      <div className="mt-1 flex justify-between gap-6 border-t border-border pt-1">
        <span className="text-muted-foreground">{t('Total')}</span>
        <span className="font-medium tabular-nums text-foreground">
          {total.toLocaleString()}
        </span>
      </div>
    </div>
  )
}
