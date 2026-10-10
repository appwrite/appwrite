import { useId, useMemo, type ComponentProps } from 'react'
import { Link } from '@tanstack/react-router'
import { Area, AreaChart, ResponsiveContainer, Tooltip, YAxis } from 'recharts'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useBandwidthForResourceChart,
  useFunctionExecutionsForFunctionChart,
  useFunctionGbHoursForFunctionChart,
  useRequestsForResourceChart,
  useSiteExecutionsForSiteChart,
  useSiteGbHoursForSiteChart,
} from '@/lib/react-query/hooks'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'
import { formatBandwidthTotal } from '@/lib/usage/bandwidth-events'
import { formatExecutionsValue } from '@/lib/usage/executions-events'
import { formatGbHoursTotal } from '@/lib/usage/gb-hours-events'
import { formatRequestsValue } from '@/lib/usage/requests-events'
import { getUsageCategoryLinkProps } from '@/lib/usage/usage-breakdown-links'
import { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
import { getUsageResourceFilterEntries } from '@/lib/usage/usage-resource-filters'
import { cn } from '@/lib/utils'
import { ChangeBadge } from '../analytics/_components/ChangeBadge'
import {
  FUNCTIONS_USAGE_CATEGORY,
  REQUESTS_USAGE_CATEGORY,
  SITES_USAGE_CATEGORY,
} from '../usage/usage-live-categories'

const CHART_FRAME_CLASS =
  'flex h-[120px] min-w-0 flex-col rounded-lg border border-border/60 bg-background/40 px-3 pb-2.5 pt-3'
const DOT_GRID_STYLE = {
  backgroundImage: 'radial-gradient(var(--border) 1px, transparent 1px)',
  backgroundSize: '12px 12px',
  maskImage: 'linear-gradient(to bottom, black, transparent)',
  WebkitMaskImage: 'linear-gradient(to bottom, black, transparent)',
} as const

type TrendPoint = { label: string; value: number }

function TrendChart({
  data,
  color,
  formatValue,
  valueLabel,
}: {
  data: TrendPoint[]
  color: string
  formatValue: (value: number) => string
  valueLabel: string
}) {
  const gradientId = useId().replace(/:/g, '')
  const lastIndex = data.length - 1
  return (
    <ResponsiveContainer width="100%" height="100%">
      <AreaChart data={data} margin={{ top: 8, right: 6, bottom: 2, left: 6 }}>
        <defs>
          <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity={0.28} />
            <stop offset="100%" stopColor={color} stopOpacity={0} />
          </linearGradient>
        </defs>
        <YAxis hide domain={[0, (max: number) => Math.ceil(max * 1.15) || 1]} />
        <Tooltip
          isAnimationActive={false}
          cursor={{ stroke: 'var(--border)' }}
          content={({ active, payload }) => {
            const point = payload?.[0]?.payload as TrendPoint | undefined
            if (!active || !point) return null
            return (
              <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 text-[11px]">
                <p className="text-muted-foreground">{point.label}</p>
                <p className="mt-0.5 font-medium tabular-nums text-foreground">
                  {formatValue(point.value)} {valueLabel}
                </p>
              </div>
            )
          }}
        />
        <Area
          type="monotone"
          dataKey="value"
          stroke={color}
          strokeWidth={2}
          fill={`url(#${gradientId})`}
          isAnimationActive={false}
          activeDot={{
            r: 4,
            fill: color,
            stroke: 'var(--background)',
            strokeWidth: 2,
          }}
          dot={(props: { cx?: number; cy?: number; index?: number }) =>
            props.index === lastIndex &&
            props.cx != null &&
            props.cy != null ? (
              <circle
                key="last"
                cx={props.cx}
                cy={props.cy}
                r={3.5}
                fill={color}
                stroke="var(--background)"
                strokeWidth={2}
              />
            ) : (
              <g key={props.index} />
            )
          }
        />
      </AreaChart>
    </ResponsiveContainer>
  )
}

function UsageMetricColumn({
  title,
  value,
  change,
  loading,
  hasActivity,
  trend,
  color,
  formatValue,
  valueLabel,
  emptyTitle,
}: {
  title: string
  value: string
  change: number | undefined
  loading: boolean
  hasActivity: boolean
  trend: TrendPoint[]
  color: string
  formatValue: (value: number) => string
  valueLabel: string
  emptyTitle: string
}) {
  const t = useT()

  return (
    <div className="min-w-0">
      <p className="text-[12px] text-muted-foreground">{title}</p>
      <div className="mt-1.5 flex h-[28px] items-baseline gap-2">
        {loading ? (
          <span className="h-6 w-16 animate-pulse self-center rounded-md bg-muted" />
        ) : (
          <>
            <span
              className={cn(
                'text-[26px] font-semibold leading-none tracking-tight tabular-nums text-foreground',
                USAGE_CHART_FADE_IN_CLASS_NAME,
              )}
            >
              {value}
            </span>
            <ChangeBadge change={change} />
          </>
        )}
      </div>
      <div className={cn(CHART_FRAME_CLASS, 'mt-4')}>
        <div className={cn('relative min-h-0 flex-1', FORCE_LTR_CLASS)}>
          <div aria-hidden className="absolute inset-0" style={DOT_GRID_STYLE} />
          {loading ? (
            <div className="absolute inset-x-0 bottom-0 top-6 animate-pulse rounded-md bg-muted/40" />
          ) : hasActivity ? (
            <div
              className={cn('absolute inset-0', USAGE_CHART_FADE_IN_CLASS_NAME)}
            >
              <TrendChart
                data={trend}
                color={color}
                formatValue={formatValue}
                valueLabel={valueLabel}
              />
            </div>
          ) : (
            <div className="absolute inset-0 flex items-center justify-center text-center">
              <p className="text-[11px] text-muted-foreground">{emptyTitle}</p>
            </div>
          )}
        </div>
        <div className="mt-2 flex h-3 shrink-0 justify-between text-[10px] leading-3 text-muted-foreground">
          <span>{t('24 hours ago')}</span>
          <span>{t('Now')}</span>
        </div>
      </div>
    </div>
  )
}

function DualUsageCard({
  title,
  subtitle,
  action,
  left,
  right,
}: {
  title: string
  subtitle: string
  action: {
    href: {
      to: string
      params: Record<string, string>
      search?: { query?: string }
    }
    label: string
  }
  left: ComponentProps<typeof UsageMetricColumn>
  right: ComponentProps<typeof UsageMetricColumn>
}) {
  const loading = left.loading || right.loading

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="flex h-16 items-center justify-between gap-3 px-6">
        <div className="min-w-0">
          <h3 className="text-[15px] font-semibold leading-5 text-foreground">
            {title}
          </h3>
          <p className="h-4 truncate text-[12px] leading-4 text-muted-foreground">
            {loading ? (
              <span className="inline-block h-3 w-28 animate-pulse rounded bg-muted align-middle" />
            ) : (
              subtitle
            )}
          </p>
        </div>
      </div>
      <div className="border-t border-border" />
      <div className="grid gap-6 px-6 py-5 sm:grid-cols-2">
        <UsageMetricColumn {...left} />
        <UsageMetricColumn {...right} />
      </div>
      <div className="flex h-[68px] items-center justify-end border-t border-border bg-muted/30 px-6">
        <Button size="sm" variant="outline" className="h-9 text-[13px]" asChild>
          <Link
            to={action.href.to}
            params={action.href.params}
            search={action.href.search}
          >
            {action.label}
          </Link>
        </Button>
      </div>
    </div>
  )
}

export function ResourceUsageCards({
  projectId,
  resourceId,
  resourceType,
}: {
  projectId: string
  resourceId: string
  resourceType: 'site' | 'function'
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const dateRange = useMemo(() => getStableUsageChartDateRange(), [])
  const usageFilters = getUsageResourceFilterEntries(
    resourceId,
    {},
    resourceType,
  )
  const computeCategoryId =
    resourceType === 'function'
      ? FUNCTIONS_USAGE_CATEGORY.id
      : SITES_USAGE_CATEGORY.id

  const requestsQuery = useRequestsForResourceChart(
    projectId,
    resourceId,
    resourceType,
    dateRange,
    features.usageStats,
  )
  const bandwidthQuery = useBandwidthForResourceChart(
    projectId,
    resourceId,
    resourceType,
    dateRange,
    features.usageStats,
  )
  const functionExecutionsQuery = useFunctionExecutionsForFunctionChart(
    projectId,
    resourceId,
    dateRange,
    features.usageStats && resourceType === 'function',
  )
  const siteExecutionsQuery = useSiteExecutionsForSiteChart(
    projectId,
    resourceId,
    dateRange,
    features.usageStats && resourceType === 'site',
  )
  const functionGbHoursQuery = useFunctionGbHoursForFunctionChart(
    projectId,
    resourceId,
    dateRange,
    features.usageStats && resourceType === 'function',
  )
  const siteGbHoursQuery = useSiteGbHoursForSiteChart(
    projectId,
    resourceId,
    dateRange,
    features.usageStats && resourceType === 'site',
  )

  if (!features.usageStats) return null

  const executionsQuery =
    resourceType === 'function' ? functionExecutionsQuery : siteExecutionsQuery
  const gbHoursQuery =
    resourceType === 'function' ? functionGbHoursQuery : siteGbHoursQuery

  const toTrend = (
    points: { date: string; total: number }[],
  ): TrendPoint[] =>
    points.map((point) => ({ label: point.date, value: point.total }))

  const isInitial = (query: {
    isPending: boolean
    data?: unknown
    isError: boolean
  }) => query.isPending && !query.data && !query.isError

  const requestsPoints = requestsQuery.data?.chartPoints ?? []
  const bandwidthPoints = bandwidthQuery.data?.chartPoints ?? []
  const executionsPoints = executionsQuery.data?.chartPoints ?? []
  const gbHoursPoints = gbHoursQuery.data?.chartPoints ?? []
  const requestsTotal = sumUsageChartPoints(requestsPoints)
  const bandwidthTotal = sumUsageChartPoints(bandwidthPoints)
  const executionsTotal = sumUsageChartPoints(executionsPoints)
  const gbHoursTotal = sumUsageChartPoints(gbHoursPoints)

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <DualUsageCard
        title={t('Traffic')}
        subtitle={t('Last 24 hours')}
        action={{
          href: getUsageCategoryLinkProps(
            projectId,
            REQUESTS_USAGE_CATEGORY.id,
            usageFilters,
          ),
          label: t('View usage'),
        }}
        left={{
          title: t('Requests'),
          value: formatRequestsValue(requestsTotal),
          change: requestsQuery.data?.changePercent,
          loading: isInitial(requestsQuery),
          hasActivity: requestsTotal > 0,
          trend: toTrend(requestsPoints),
          color: 'var(--chart-brand)',
          formatValue: formatRequestsValue,
          valueLabel: t('requests'),
          emptyTitle: t('No requests yet'),
        }}
        right={{
          title: t('Bandwidth'),
          value: formatBandwidthTotal(bandwidthTotal),
          change: bandwidthQuery.data?.changePercent,
          loading: isInitial(bandwidthQuery),
          hasActivity: bandwidthTotal > 0,
          trend: toTrend(bandwidthPoints),
          color: 'var(--chart-2)',
          formatValue: formatBandwidthTotal,
          valueLabel: t('bandwidth'),
          emptyTitle: t('No bandwidth yet'),
        }}
      />
      <DualUsageCard
        title={t('Compute')}
        subtitle={t('Last 24 hours')}
        action={{
          href: getUsageCategoryLinkProps(
            projectId,
            computeCategoryId,
            usageFilters,
          ),
          label: t('View usage'),
        }}
        left={{
          title: t('Executions'),
          value: formatExecutionsValue(executionsTotal),
          change: executionsQuery.data?.changePercent,
          loading: isInitial(executionsQuery),
          hasActivity: executionsTotal > 0,
          trend: toTrend(executionsPoints),
          color: 'var(--chart-brand)',
          formatValue: formatExecutionsValue,
          valueLabel: t('executions'),
          emptyTitle: t('No executions yet'),
        }}
        right={{
          title: t('GB-hours'),
          value: formatGbHoursTotal(gbHoursTotal),
          change: gbHoursQuery.data?.changePercent,
          loading: isInitial(gbHoursQuery),
          hasActivity: gbHoursTotal > 0,
          trend: toTrend(gbHoursPoints),
          color: 'var(--chart-2)',
          formatValue: formatGbHoursTotal,
          valueLabel: t('GB-hours'),
          emptyTitle: t('No GB-hours yet'),
        }}
      />
    </div>
  )
}
