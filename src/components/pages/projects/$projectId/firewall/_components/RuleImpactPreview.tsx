import { useEffect, useMemo, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { Activity, Loader2, Target } from 'lucide-react'
import {
  UsageChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import {
  USAGE_CHART_MARGIN,
  USAGE_CHART_RESPONSIVE_CONTAINER_PROPS,
} from '@/lib/usage/chart-layout'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { DEFAULT_USAGE_CHART_INTERVAL } from '@/lib/usage/chart-interval'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import { cn } from '@/lib/utils'
import { OVERVIEW_CHART_HEIGHT } from '../../overview/chart-panel'
import { useFirewallRuleImpact } from '@/lib/react-query/hooks'
import type {
  FirewallConditionDraft,
  FirewallResourceType,
} from '@/lib/firewall/conditions'
import { firewallUsageConditionsKey } from '@/lib/firewall/usage'
import {
  getFirewallActionLabel,
  type FirewallCreatableAction,
} from '@/lib/firewall/actions'
import { useT } from '@/lib/i18n/translate'

const IMPACT_DEBOUNCE_MS = 300

interface RuleImpactPreviewProps {
  conditions: FirewallConditionDraft[]
  action: FirewallCreatableAction
  resourceType: FirewallResourceType
  resourceId?: string
}

export function RuleImpactPreview({
  conditions,
  action,
  resourceType,
  resourceId,
}: RuleImpactPreviewProps) {
  const t = useT()
  const { projectId } = useParams({ strict: false })
  const [debouncedConditions, setDebouncedConditions] = useState(conditions)
  const conditionsKey = firewallUsageConditionsKey(conditions)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedConditions(conditions)
    }, IMPACT_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
    // Re-run when the serialized condition values change, not only array identity.
  }, [conditions, conditionsKey])

  const { impact, isLoading, isFetching } = useFirewallRuleImpact(
    projectId,
    debouncedConditions,
    resourceType,
    resourceId,
  )

  const series = impact?.series ?? []
  const dateRange = impact?.dateRange
  const summary = {
    matched: impact?.matched ?? 0,
    rate: impact?.rate ?? 0,
  }
  const chartPoints = useMemo(
    () => series.map((point) => ({ date: point.date, day: point.day })),
    [series],
  )
  const chartAxisMax = useMemo(
    () => series.reduce((max, point) => Math.max(max, point.total), 0),
    [series],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const filledConditions = conditions.filter((c) => c.value.trim().length > 0)
    .length
  const showSubtleLoading = isFetching && !isLoading

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="border-b border-border px-4 py-3">
          <div className="flex items-center gap-2">
            <h3 className="text-[14px] font-medium text-foreground">
              {t('Estimated impact')}
            </h3>
            {showSubtleLoading || isLoading ? (
              <Loader2
                className="h-3.5 w-3.5 shrink-0 animate-spin text-muted-foreground"
                aria-label={t('Loading')}
              />
            ) : null}
          </div>
          <p className="mt-1 text-[12px] text-muted-foreground">
            {t(
              'Estimated requests this rule would match over the last 24 hours.',
            )}
          </p>
        </div>

        <div
          className={cn(
            'grid grid-cols-2 gap-3 border-b border-border p-4 transition-opacity duration-200',
            showSubtleLoading && 'opacity-60',
          )}
        >
          <div className="rounded-lg border border-border bg-muted/20 p-3">
            <div className="mb-1 flex items-center gap-1.5 text-muted-foreground">
              <Target className="h-3.5 w-3.5" />
              <span className="text-[11px]">{t('Matched requests')}</span>
            </div>
            <p className="text-[18px] font-semibold tabular-nums text-foreground">
              {isLoading && !impact
                ? '—'
                : summary.matched.toLocaleString()}
            </p>
          </div>
          <div className="rounded-lg border border-border bg-muted/20 p-3">
            <div className="mb-1 flex items-center gap-1.5 text-muted-foreground">
              <Activity className="h-3.5 w-3.5" />
              <span className="text-[11px]">{t('Share of traffic')}</span>
            </div>
            <p className="text-[18px] font-semibold tabular-nums text-foreground">
              {isLoading && !impact
                ? '—'
                : `${(summary.rate * 100).toFixed(1)}%`}
            </p>
          </div>
        </div>

        <div className="p-4">
          <div
            className={cn(
              'relative w-full shrink-0 text-muted-foreground transition-opacity duration-200',
              FORCE_LTR_CLASS,
              showSubtleLoading && 'opacity-60',
            )}
            style={{ height: OVERVIEW_CHART_HEIGHT }}
          >
            {dateRange && series.length > 0 ? (
              <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
                <AreaChart data={series} margin={USAGE_CHART_MARGIN}>
                  <defs>
                    <linearGradient
                      id="firewall-impact-matched"
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
                    stroke="hsl(var(--border))"
                    vertical={false}
                  />
                  <UsageChartXAxis
                    points={chartPoints}
                    dateRange={dateRange}
                    chartInterval={DEFAULT_USAGE_CHART_INTERVAL}
                  />
                  <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
                  <Tooltip
                    isAnimationActive={false}
                    content={({ active, payload }) => {
                      if (!active || !payload?.length) return null
                      const point = payload[0]?.payload as {
                        fullDate?: string
                        total?: number
                        matched?: number
                      }
                      return (
                        <div className="rounded-md border border-border bg-popover px-3 py-2">
                          <p className="mb-1.5 text-[11px] text-muted-foreground">
                            {point.fullDate}
                          </p>
                          <div className="space-y-1">
                            <div className="flex justify-between gap-6 text-[11px]">
                              <span className="text-muted-foreground">
                                {t('Total traffic')}
                              </span>
                              <span className="font-medium tabular-nums text-foreground">
                                {(point.total ?? 0).toLocaleString()}
                              </span>
                            </div>
                            <div className="flex justify-between gap-6 text-[11px]">
                              <span className="text-muted-foreground">
                                {t('Matched by rule')}
                              </span>
                              <span className="font-medium tabular-nums text-foreground">
                                {(point.matched ?? 0).toLocaleString()}
                              </span>
                            </div>
                          </div>
                        </div>
                      )
                    }}
                  />
                  <Area
                    type="monotone"
                    dataKey="total"
                    name={t('Total traffic')}
                    stroke="hsl(var(--muted-foreground))"
                    strokeWidth={1.5}
                    strokeDasharray="4 4"
                    fill="transparent"
                    dot={false}
                    {...CHART_ANIMATION_DISABLED}
                  />
                  <Area
                    type="monotone"
                    dataKey="matched"
                    name={t('Matched by rule')}
                    stroke="var(--chart-brand)"
                    strokeWidth={2}
                    fill="url(#firewall-impact-matched)"
                    dot={false}
                    {...CHART_ANIMATION_DISABLED}
                  />
                </AreaChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex h-full items-center justify-center text-[12px] text-muted-foreground">
                {isLoading
                  ? t('Loading...')
                  : t('No traffic data for this period')}
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 px-4 py-3">
        <p className="text-[12px] text-muted-foreground">
          {t('Action')}:{' '}
          <span className="font-medium text-foreground">
            {t(getFirewallActionLabel(action))}
          </span>
        </p>
        <p className="mt-1 text-[12px] text-muted-foreground">
          {filledConditions === 0
            ? t('Add conditions to narrow which requests this rule matches.')
            : t('Matching estimate updates as you refine conditions.')}
        </p>
      </div>
    </div>
  )
}
