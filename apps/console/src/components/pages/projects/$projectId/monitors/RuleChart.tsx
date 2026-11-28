import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ReferenceArea,
  ReferenceDot,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Loader2 } from 'lucide-react'
import { alertSeriesQueryOptions } from '@/lib/react-query/hooks'
import type {
  AlertComparator,
  AlertMetric,
  AlertResourceType,
} from '@/lib/appwrite/monitors'
import {
  MB_SECONDS_PER_GB_HOUR,
  formatMetricValue,
  metricDefinition,
} from '@/lib/alerts/metrics'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import {
  breaches,
  firings,
  hasSeries,
  seriesPlan,
  seriesRanges,
  type AlertPoint,
} from '@/lib/alerts/series'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const BREACH_COLOR = '#ef4444'
/** The series is neutral so only the threshold and its breaches read as red. */
const LINE = 'var(--foreground)'

export type ChartedRule = {
  metric: AlertMetric
  resourceType: AlertResourceType
  resourceId: string
  window: number
  comparator: AlertComparator
  threshold: number
}

export function useRuleSeries(
  projectId: string,
  rule: ChartedRule,
  enabled = true,
  range?: number,
) {
  return useQuery({
    ...alertSeriesQueryOptions(projectId, rule, range),
    enabled,
  })
}

const compact = new Intl.NumberFormat('en-US', {
  notation: 'compact',
  maximumFractionDigits: 1,
})

/** A Y-axis tick: the number alone, since the threshold field names the unit. */
function axisValue(metric: AlertMetric, value: number): string {
  const unit = metricDefinition(metric).unit
  if (unit === 'bytes') return formatDecimalBytes(value)
  if (unit === 'percent') return `${compact.format(value)}%`
  if (unit === 'gbHours') return compact.format(value / MB_SECONDS_PER_GB_HOUR)
  return compact.format(value)
}

/** A round step near a third of `max`, so the axis reads 0, step, 2·step… */
function niceStep(max: number): number {
  const raw = max / 3
  const magnitude = 10 ** Math.floor(Math.log10(raw))
  const step =
    [1, 2, 2.5, 5, 10].find((factor) => factor * magnitude >= raw) ?? 10
  return step * magnitude
}

/** The axis top and its ticks for a chart that reaches `max`. */
function axisScale(max: number): { top: number; ticks: number[] } {
  if (!(max > 0)) return { top: 1, ticks: [0, 1] }
  const step = niceStep(max * 1.05)
  const top = step * Math.ceil((max * 1.05) / step)
  const ticks: number[] = []
  for (let tick = 0; tick <= top + step / 2; tick += step) {
    ticks.push(parseFloat(tick.toPrecision(12)))
  }
  return { top, ticks }
}

/** Plot area offsets: the chart margin and Recharts' default X-axis height. */
const PLOT_TOP = 8
const PLOT_RIGHT = 4
const X_AXIS_HEIGHT = 30

/** Upper thresholds fire above the line, lower ones below it. */
function firesAbove(comparator: AlertComparator): boolean {
  return comparator === 'greaterThan' || comparator === 'greaterThanEqual'
}

/** Whether the element has come into view, so a long list only loads what is seen. */
function useSeen<T extends HTMLElement>() {
  const ref = useRef<T>(null)
  const [seen, setSeen] = useState(false)

  useEffect(() => {
    const element = ref.current
    if (!element || seen) return
    if (typeof IntersectionObserver === 'undefined') {
      setSeen(true)
      return
    }
    const observer = new IntersectionObserver(([entry]) => {
      if (!entry?.isIntersecting) return
      setSeen(true)
      observer.disconnect()
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [seen])

  return { ref, seen }
}

function rangeLabel(range: number, t: (text: string) => string): string {
  if (range <= 43200) return t('last 12 hours')
  if (range <= 86400) return t('last 24 hours')
  if (range <= 604800) return t('last 7 days')
  return t('last 30 days')
}

function rangeShort(range: number): string {
  if (range <= 43200) return '12h'
  if (range <= 86400) return '24h'
  if (range <= 604800) return '7d'
  return '30d'
}

/** Small trend of a monitor's value against its threshold, for the monitors list. */
export function RuleSparkline({
  projectId,
  rule,
  className,
}: {
  projectId: string
  rule: ChartedRule
  className?: string
}) {
  const gradient = `spark-${useId().replace(/:/g, '')}`
  const { ref, seen } = useSeen<HTMLDivElement>()
  const { data } = useRuleSeries(projectId, rule, seen)
  if (!data || data.length === 0) {
    return <div ref={ref} className={cn('h-8 w-28', className)} />
  }
  const top =
    Math.max(rule.threshold, ...data.map((point) => point.value)) * 1.1 || 1
  const above = firesAbove(rule.comparator)
  // Where the threshold crosses the line's height, from the top: the stroke turns red past it.
  const split = `${Math.min(100, Math.max(0, (1 - rule.threshold / top) * 100))}%`
  return (
    <div className={cn('h-8 w-28', FORCE_LTR_CLASS, className)} aria-hidden>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={data}
          margin={{ top: 2, right: 0, bottom: 2, left: 0 }}
        >
          <defs>
            <linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={above ? BREACH_COLOR : LINE} />
              <stop offset={split} stopColor={above ? BREACH_COLOR : LINE} />
              <stop offset={split} stopColor={above ? LINE : BREACH_COLOR} />
              <stop offset="100%" stopColor={above ? LINE : BREACH_COLOR} />
            </linearGradient>
          </defs>
          <YAxis hide domain={[0, top]} />
          <ReferenceArea
            y1={above ? rule.threshold : 0}
            y2={above ? top : rule.threshold}
            fill={BREACH_COLOR}
            fillOpacity={0.08}
            ifOverflow="hidden"
          />
          <ReferenceLine
            y={rule.threshold}
            stroke="var(--muted-foreground)"
            strokeDasharray="3 3"
            strokeOpacity={0.7}
          />
          <Area
            type="monotone"
            dataKey="value"
            stroke={`url(#${gradient})`}
            strokeWidth={1.5}
            fill="none"
            isAnimationActive={false}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

/**
 * The monitor's value over its chart range with the threshold drawn in, and a
 * mark everywhere the monitor would have fired.
 */
export function RuleChart({
  projectId,
  rule,
  height = 200,
  onPeak,
  onThresholdChange,
  incidents,
}: {
  projectId: string
  rule: ChartedRule
  height?: number
  /** Called with the highest value charted once the history loads. */
  onPeak?: (peak: number) => void
  /** Makes the threshold line draggable; called with the new value in the metric's unit. */
  onThresholdChange?: (threshold: number) => void
  /** Times the monitor really fired, shaded on the chart in place of what the threshold would have done. */
  incidents?: { start: number; end: number }[]
}) {
  const t = useT()
  const counter = metricDefinition(rule.metric).counter
  const ranges = seriesRanges(rule.window, counter)
  const [picked, setPicked] = useState<number | undefined>()
  const span =
    picked !== undefined && ranges.includes(picked)
      ? picked
      : seriesPlan(rule.window, counter).range
  const query = useRuleSeries(projectId, rule, true, span)
  const points: AlertPoint[] = useMemo(() => query.data ?? [], [query.data])
  const fired = useMemo(
    () => firings(points, rule.comparator, rule.threshold),
    [points, rule.comparator, rule.threshold],
  )
  const firedSet = useMemo(() => new Set(fired), [fired])
  useEffect(() => {
    if (!onPeak || !query.isSuccess || points.length === 0) return
    onPeak(Math.max(...points.map((point) => point.value)))
  }, [onPeak, query.isSuccess, points])
  const peak = useMemo(
    () => Math.max(0, ...points.map((point) => point.value)),
    [points],
  )
  // The scale holds still while the line is dragged, so the line follows the pointer.
  const [dragScale, setDragScale] = useState<ReturnType<
    typeof axisScale
  > | null>(null)
  const [hovering, setHovering] = useState(false)
  const scale = dragScale ?? axisScale(Math.max(peak, rule.threshold))
  const plotHeight = height - PLOT_TOP - X_AXIS_HEIGHT
  const lineY =
    PLOT_TOP +
    plotHeight * (1 - Math.min(1, Math.max(0, rule.threshold / scale.top)))
  const unit = metricDefinition(rule.metric).unit
  const axisWidth = unit === 'bytes' ? 60 : 40
  const valueAt = (offsetY: number, frozen: { top: number }) => {
    const ratio = 1 - (offsetY - PLOT_TOP) / plotHeight
    const value = Math.min(1, Math.max(0, ratio)) * frozen.top
    return unit === 'percent' ? Math.min(100, value) : value
  }
  const handleDrag = (event: React.PointerEvent<HTMLDivElement>) => {
    if (!onThresholdChange) return
    const frozen = dragScale ?? scale
    const bounds = event.currentTarget.parentElement?.getBoundingClientRect()
    if (!bounds) return
    onThresholdChange(valueAt(event.clientY - bounds.top, frozen))
  }
  const nudge = (direction: 1 | -1) => {
    if (!onThresholdChange) return
    // A fiftieth of the axis per key press.
    const next = Math.max(0, rule.threshold + (direction * scale.top) / 50)
    onThresholdChange(unit === 'percent' ? Math.min(100, next) : next)
  }
  const format = (value: number) => formatMetricValue(rule.metric, value)
  const range = rangeLabel(span, t)
  const tickFormat = span <= 86400 ? 'HH:mm' : 'MMM d'
  const above = firesAbove(rule.comparator)

  if (!hasSeries(rule.metric)) {
    return (
      <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-[12px] text-muted-foreground">
        {t('No history is available for this metric yet.')}
      </p>
    )
  }

  if (rule.resourceType !== 'project' && !rule.resourceId) {
    return (
      <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-[12px] text-muted-foreground">
        {t('Select a resource to see its history.')}
      </p>
    )
  }

  const from = points[0]?.time ?? Date.now() - span * 1000
  const shown = (incidents ?? []).filter((incident) => incident.end >= from)
  const outcome = incidents
    ? shown.length === 0
      ? t('No alerts in the {range}').replace('{range}', range)
      : t('Fired {count}× in the {range}')
          .replace('{count}', String(shown.length))
          .replace('{range}', range)
    : fired.length === 0
      ? t('Would not have fired in the {range}').replace('{range}', range)
      : t('Would have fired {count}× in the {range}')
          .replace('{count}', String(fired.length))
          .replace('{range}', range)

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        {!query.isLoading && !query.isError ? (
          <span
            className={cn(
              'inline-flex items-center rounded-full px-2 py-0.5 text-[11.5px] font-medium',
              (incidents ? shown.length : fired.length) === 0
                ? 'bg-muted text-muted-foreground'
                : 'bg-red-500/10 text-red-600 dark:text-red-400',
            )}
          >
            {outcome}
          </span>
        ) : (
          <span />
        )}
        {ranges.length > 1 && (
          <div
            role="tablist"
            aria-label={t('History range')}
            className="inline-flex gap-0.5 rounded-md border border-border bg-muted p-0.5"
          >
            {ranges.map((option) => (
              <button
                key={option}
                type="button"
                role="tab"
                aria-selected={option === span}
                onClick={() => setPicked(option)}
                className={cn(
                  'rounded px-2 py-0.5 text-[11.5px] tabular-nums',
                  option === span
                    ? 'bg-card text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {rangeShort(option)}
              </button>
            ))}
          </div>
        )}
      </div>
      <div
        className={cn('relative text-muted-foreground', FORCE_LTR_CLASS)}
        style={{ height }}
      >
        {query.isLoading ? (
          <div className="flex h-full items-center justify-center">
            <Loader2 className="h-5 w-5 animate-spin" />
          </div>
        ) : query.isError ? (
          <div className="flex h-full items-center justify-center text-[12px]">
            {t('Could not load usage history.')}
          </div>
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart
              data={points}
              margin={{ top: 8, right: 4, bottom: 0, left: 0 }}
            >
              <defs>
                <linearGradient
                  id="alert-rule-fill"
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop offset="0%" stopColor={LINE} stopOpacity={0.08} />
                  <stop offset="100%" stopColor={LINE} stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid
                strokeDasharray="3 3"
                stroke="var(--border)"
                vertical={false}
              />
              <XAxis
                dataKey="time"
                type="number"
                scale="time"
                domain={['dataMin', 'dataMax']}
                tickFormatter={(time: number) =>
                  formatLocalizedDate(new Date(time), tickFormat)
                }
                tick={{ fontSize: 11, fill: 'currentColor' }}
                tickLine={false}
                axisLine={false}
                minTickGap={40}
              />
              <YAxis
                domain={[0, scale.top]}
                ticks={scale.ticks}
                allowDataOverflow
                tickFormatter={(value: number) => axisValue(rule.metric, value)}
                tick={{ fontSize: 11, fill: 'currentColor' }}
                tickLine={false}
                axisLine={false}
                width={axisWidth}
              />
              <Tooltip
                content={({ active, payload }) => {
                  if (!active || !payload?.length) return null
                  const point = payload[0].payload as AlertPoint
                  const over = breaches(
                    rule.comparator,
                    point.value,
                    rule.threshold,
                  )
                  return (
                    <div className="rounded-md border border-border bg-popover px-3 py-2">
                      <p className="mb-1 text-[11px] text-muted-foreground">
                        {formatLocalizedDate(
                          new Date(point.time),
                          'MMM d, HH:mm',
                        )}
                      </p>
                      <p
                        className={cn(
                          'text-[13px] font-medium',
                          over ? 'text-red-500' : 'text-foreground',
                        )}
                      >
                        {format(point.value)}
                      </p>
                    </div>
                  )
                }}
              />
              {shown.map((incident) => (
                <ReferenceArea
                  key={incident.start}
                  x1={Math.max(incident.start, from)}
                  x2={incident.end}
                  fill={BREACH_COLOR}
                  fillOpacity={0.12}
                  ifOverflow="hidden"
                />
              ))}
              {!incidents && (
                <ReferenceArea
                  y1={above ? rule.threshold : 0}
                  y2={above ? undefined : rule.threshold}
                  fill={BREACH_COLOR}
                  fillOpacity={0.05}
                  ifOverflow="hidden"
                />
              )}
              <ReferenceLine
                y={rule.threshold}
                stroke={BREACH_COLOR}
                strokeDasharray={dragScale || hovering ? undefined : '4 4'}
                strokeOpacity={dragScale || hovering ? 1 : 0.8}
                strokeWidth={dragScale ? 1.5 : 1}
                ifOverflow="hidden"
              />
              <Area
                type="monotone"
                dataKey="value"
                stroke={LINE}
                strokeOpacity={0.7}
                strokeWidth={1.5}
                fill="url(#alert-rule-fill)"
                isAnimationActive={false}
              />
              {points
                .filter((point) => !incidents && firedSet.has(point.time))
                .map((point) => (
                  <ReferenceDot
                    key={point.time}
                    x={point.time}
                    y={point.value}
                    r={4}
                    fill={BREACH_COLOR}
                    stroke="var(--background)"
                    strokeWidth={2}
                  />
                ))}
            </AreaChart>
          </ResponsiveContainer>
        )}
        {onThresholdChange && query.isSuccess && (
          <div
            role="slider"
            tabIndex={0}
            aria-label={t('Threshold')}
            aria-valuemin={0}
            aria-valuemax={scale.top}
            aria-valuenow={rule.threshold}
            aria-valuetext={format(rule.threshold)}
            className="group absolute cursor-ns-resize touch-none outline-none"
            style={{
              left: axisWidth,
              right: PLOT_RIGHT,
              top: lineY - 6,
              height: 12,
            }}
            onPointerEnter={() => setHovering(true)}
            onPointerLeave={() => setHovering(false)}
            onPointerDown={(event) => {
              event.preventDefault()
              event.currentTarget.setPointerCapture(event.pointerId)
              setDragScale(scale)
            }}
            onPointerMove={(event) => {
              if (dragScale) handleDrag(event)
            }}
            onPointerUp={(event) => {
              event.currentTarget.releasePointerCapture(event.pointerId)
              setDragScale(null)
            }}
            onPointerCancel={() => setDragScale(null)}
            onKeyDown={(event) => {
              if (event.key === 'ArrowUp') nudge(1)
              else if (event.key === 'ArrowDown') nudge(-1)
              else return
              event.preventDefault()
            }}
          >
            <span
              className={cn(
                'pointer-events-none absolute end-0 -translate-y-full rounded bg-background/90 px-1.5 py-0.5 text-[11px] font-medium tabular-nums text-red-600 opacity-0 transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100 dark:text-red-400',
                dragScale && 'opacity-100',
              )}
              style={{ top: 4 }}
            >
              {format(rule.threshold)}
            </span>
          </div>
        )}
      </div>
    </div>
  )
}
