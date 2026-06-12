import { useMemo } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import {
  Tooltip as UITooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { formatCompactBytes } from '@/lib/usage/format-metric'

export type PostgresMetricBarPoint = {
  label: string
  value: number
  detail?: string
}

type PostgresMetricBarChartProps = {
  id: string
  title: string
  description: string
  data: PostgresMetricBarPoint[]
  formatValue?: (value: number) => string
  emptyMessage?: string
  className?: string
}

const BAR_COLORS = [
  'var(--chart-brand)',
  'var(--chart-1)',
  'var(--chart-2)',
  'var(--chart-3)',
  'var(--chart-4)',
  'var(--chart-5)',
]

export function PostgresMetricBarChart({
  id,
  title,
  description,
  data,
  formatValue = (value) => formatCompactBytes(value),
  emptyMessage = 'No table activity data available yet.',
  className,
}: PostgresMetricBarChartProps) {
  const chartData = useMemo(
    () =>
      [...data]
        .sort((a, b) => b.value - a.value)
        .map((point) => ({
          label: point.label,
          value: point.value,
          detail: point.detail,
        })),
    [data],
  )

  return (
    <div
      id={`postgres-metric-chart-${id}`}
      className={cn(
        'scroll-mt-[calc(4rem+env(safe-area-inset-top))] w-full overflow-hidden rounded-lg border border-border bg-card',
        className,
      )}
    >
      <div className="border-b border-border px-4 py-4">
        <div className="flex items-center gap-2">
          <h3 className="text-[14px] font-medium text-foreground">{title}</h3>
          <TooltipProvider delayDuration={0}>
            <UITooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="text-muted-foreground transition-colors hover:text-foreground"
                >
                  <Info className="h-3.5 w-3.5" />
                </button>
              </TooltipTrigger>
              <TooltipContent
                side="top"
                className="max-w-xs text-[12px] leading-relaxed"
              >
                <p>{description}</p>
              </TooltipContent>
            </UITooltip>
          </TooltipProvider>
        </div>
      </div>

      <div className="p-4">
        {chartData.length === 0 ? (
          <div className="flex h-[220px] items-center justify-center rounded-md border border-dashed border-border bg-muted/20 px-6 text-center">
            <p className="max-w-sm text-[12px] leading-relaxed text-muted-foreground">
              {emptyMessage}
            </p>
          </div>
        ) : (
          <div className="h-[220px] text-muted-foreground">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart
                data={chartData}
                layout="vertical"
                margin={{ top: 4, right: 12, left: 4, bottom: 4 }}
              >
                <CartesianGrid
                  strokeDasharray="3 3"
                  stroke="hsl(var(--border))"
                  horizontal={false}
                />
                <XAxis
                  type="number"
                  axisLine={false}
                  tickLine={false}
                  tick={{
                    fill: 'currentColor',
                    fontSize: 10,
                    className: 'tabular-nums',
                  }}
                  tickFormatter={(value) => formatValue(Number(value))}
                />
                <YAxis
                  type="category"
                  dataKey="label"
                  axisLine={false}
                  tickLine={false}
                  width={120}
                  tick={{
                    fill: 'currentColor',
                    fontSize: 10,
                  }}
                />
                <Tooltip
                  content={({ active, payload }) => {
                    if (!active || !payload?.length) return null
                    const row = payload[0].payload as PostgresMetricBarPoint
                    return (
                      <div className="rounded-md border border-border bg-popover px-3 py-2">
                        <p className="text-[13px] font-medium text-foreground">
                          {row.label}
                        </p>
                        <p className="mt-1 text-[12px] text-muted-foreground">
                          {formatValue(row.value)}
                        </p>
                        {row.detail ? (
                          <p className="mt-1 text-[11px] text-muted-foreground">
                            {row.detail}
                          </p>
                        ) : null}
                      </div>
                    )
                  }}
                />
                <Bar dataKey="value" radius={[0, 4, 4, 0]} maxBarSize={18}>
                  {chartData.map((entry, index) => (
                    <Cell
                      key={entry.label}
                      fill={BAR_COLORS[index % BAR_COLORS.length]}
                    />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
    </div>
  )
}
