import { useMemo } from 'react'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  createCompactCountAxisTickFormatter,
  getChartSeriesMax,
} from '@/lib/usage/format-metric'
import { USAGE_CHART_MARGIN, USAGE_CHART_RESPONSIVE_CONTAINER_PROPS } from '@/lib/usage/chart-layout'
import { SeriesChartXAxis, UsageChartYAxis } from '@/components/global/shared/ChartXAxis'
import { FORCE_LTR_CLASS } from '@/lib/layout/force-ltr'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
} from 'recharts'
import { usageData } from '@/lib/utils/mock-data'

interface UsageChartProps {
  className?: string
}

export function UsageChart({ className }: UsageChartProps) {
  const t = useT()
  const chartAxisMax = useMemo(
    () =>
      getChartSeriesMax(
        usageData.map((point) => ({
          total: Math.max(point.requests, point.bandwidth),
        })),
      ),
    [],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  return (
    <div className={cn('rounded-lg border border-border bg-card', className)}>
      <div className="flex flex-col gap-2 border-b border-border px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-4 sm:py-3">
        <h3 className="text-[13px] font-medium text-foreground">
          {t('API Requests')}
        </h3>
        <div className="flex items-center gap-3 sm:gap-4">
          <div className="flex items-center gap-1.5">
            <div className="h-1.5 w-1.5 rounded-full bg-chart-brand" />
            <span className="text-[11px] text-muted-foreground">{t('Requests')}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <div className="h-1.5 w-1.5 rounded-full bg-muted-foreground" />
            <span className="text-[11px] text-muted-foreground">{t('Bandwidth')}</span>
          </div>
        </div>
      </div>

      <div className="p-2 sm:p-4">
        <div className={cn('h-[200px] text-muted-foreground', FORCE_LTR_CLASS)}>
          <ResponsiveContainer {...USAGE_CHART_RESPONSIVE_CONTAINER_PROPS}>
            <AreaChart
              data={usageData}
              margin={USAGE_CHART_MARGIN}
            >
              <defs>
                <linearGradient
                  id="requestsGradient"
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor="var(--chart-brand)"
                    stopOpacity={0.15}
                  />
                  <stop
                    offset="100%"
                    stopColor="var(--chart-brand)"
                    stopOpacity={0}
                  />
                </linearGradient>
                <linearGradient
                  id="bandwidthGradient"
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor="hsl(var(--foreground))"
                    stopOpacity={0.08}
                  />
                  <stop
                    offset="100%"
                    stopColor="hsl(var(--foreground))"
                    stopOpacity={0}
                  />
                </linearGradient>
              </defs>
              <SeriesChartXAxis pointCount={usageData.length} />
              <UsageChartYAxis tickFormatter={yAxisTickFormatter} />
              <Tooltip
                contentStyle={{
                  backgroundColor: 'hsl(var(--popover))',
                  border: '1px solid hsl(var(--border))',
                  borderRadius: '6px',
                  boxShadow: '0 4px 12px rgba(0,0,0,0.4)',
                  padding: '8px 12px',
                }}
                labelStyle={{
                  color: 'hsl(var(--muted-foreground))',
                  fontSize: '11px',
                  marginBottom: '4px',
                }}
                itemStyle={{
                  color: 'hsl(var(--foreground))',
                  fontSize: '12px',
                }}
              />
              <Area
                type="monotone"
                dataKey="requests"
                stroke="var(--chart-brand)"
                strokeWidth={1.5}
                fill="url(#requestsGradient)"
                name={t('Requests')}
              />
              <Area
                type="monotone"
                dataKey="bandwidth"
                stroke="hsl(var(--muted-foreground))"
                strokeWidth={1.5}
                fill="url(#bandwidthGradient)"
                name={t('Bandwidth (MB)')}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  )
}
