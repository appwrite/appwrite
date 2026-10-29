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
import { METRICS, formatMetricValue } from '@/lib/alerts/metrics'
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
const BRAND = 'var(--chart-brand)'

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
              <stop offset="0%" stopColor={above ? BREACH_COLOR : BRAND} />
              <stop offset={split} stopColor={above ? BREACH_COLOR : BRAND} />
              <stop offset={split} stopColor={above ? BRAND : BREACH_COLOR} />
              <stop offset="100%" stopColor={above ? BRAND : BREACH_COLOR} />
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
  height = 180,
}: {
  projectId: string
  rule: ChartedRule
  height?: number
}) {
  const t = useT()
  const counter = METRICS[rule.metric].counter
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
  const max = useMemo(
    () => Math.max(rule.threshold, ...points.map((point) => point.value)),
    [points, rule.threshold],
  )
  const format = (value: number) => formatMetricValue(rule.metric, value)
  const range = rangeLabel(span, t)
  const tickFormat = span <= 86400 ? 'HH:mm' : 'MMM d'
  const above = firesAbove(rule.comparator)
  const top = max * 1.15 || 1

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

  const outcome =
    fired.length === 0
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
              fired.length === 0
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
              margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
            >
              <defs>
                <linearGradient
                  id="alert-rule-fill"
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor="var(--chart-brand)"
                    stopOpacity={0.2}
                  />
                  <stop
                    offset="100%"
                    stopColor="var(--chart-brand)"
                    stopOpacity={0}
                  />
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
                domain={[0, top]}
                tickFormatter={format}
                tick={{ fontSize: 11, fill: 'currentColor' }}
                tickLine={false}
                axisLine={false}
                width={72}
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
              <ReferenceArea
                y1={above ? rule.threshold : 0}
                y2={above ? top : rule.threshold}
                fill={BREACH_COLOR}
                fillOpacity={0.06}
                ifOverflow="hidden"
              />
              <ReferenceLine
                y={rule.threshold}
                stroke={BREACH_COLOR}
                strokeDasharray="4 4"
                strokeOpacity={0.8}
                label={{
                  value: format(rule.threshold),
                  position: 'insideTopRight',
                  fill: BREACH_COLOR,
                  fontSize: 10,
                }}
              />
              <Area
                type="monotone"
                dataKey="value"
                stroke="var(--chart-brand)"
                strokeWidth={2}
                fill="url(#alert-rule-fill)"
                isAnimationActive={false}
              />
              {points
                .filter((point) => firedSet.has(point.time))
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
      </div>
    </div>
  )
}
