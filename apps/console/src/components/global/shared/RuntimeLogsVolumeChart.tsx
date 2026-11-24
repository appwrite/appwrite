import { useMemo } from 'react'
import type { DateRange } from 'react-day-picker'
import { Bar, BarChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ZoomOut } from 'lucide-react'
import {
  SeriesChartXAxis,
  UsageChartYAxis,
} from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { UsageChartBrushReferenceArea } from '@/components/pages/projects/$projectId/usage/_components/UsageChartBrushReferenceArea'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useUsageChartBrushSelect } from '@/hooks/use-usage-chart-brush'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { useT } from '@/lib/i18n/translate'
import type { RuntimeLogVolumePoint } from '@/lib/runtime-log-usage'
import { createCompactCountAxisTickFormatter } from '@/lib/usage/format-metric'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import { cn } from '@/lib/utils'

const STDOUT_COLOR = 'var(--muted-foreground)'
const STDERR_COLOR = '#ef4444'

export interface RuntimeLogsVolumeChartProps {
  points: readonly RuntimeLogVolumePoint[] | undefined
  interval: UsageChartInterval | undefined
  total: number | undefined
  loading: boolean
  /** Set while the viewer shows a window picked on this chart. */
  zoomed: boolean
  onZoom: (range: DateRange) => void
  onResetZoom: () => void
  insetClassName?: string
}

/**
 * Lines per time bucket above the logs, split into stdout and stderr. Drag
 * across the bars to narrow the logs to that window.
 */
export function RuntimeLogsVolumeChart({
  points,
  interval = '1h',
  total,
  loading,
  zoomed,
  onZoom,
  onResetZoom,
  insetClassName,
}: RuntimeLogsVolumeChartProps) {
  const t = useT()
  const data = useMemo(() => (points ? [...points] : []), [points])
  const spansDays =
    data.length > 1 &&
    data[data.length - 1].day.getTime() - data[0].day.getTime() >
      24 * 60 * 60_000
  const labels = useMemo(
    () =>
      data.map((point) =>
        formatLocalizedDate(
          point.day,
          interval === '1d' ? 'MMM d' : spansDays ? 'MMM d, HH:mm' : 'HH:mm',
        ),
      ),
    [data, interval, spansDays],
  )
  const max = useMemo(
    () => data.reduce((top, point) => Math.max(top, point.total), 0),
    [data],
  )
  const yTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(max),
    [max],
  )
  const { isSelecting, brushLeft, brushRight, surfaceClassName, chartProps } =
    useUsageChartBrushSelect({
      points: data,
      chartInterval: interval,
      onDateRangeChange: (range) => {
        if (range?.from && range.to) onZoom(range)
      },
    })

  return (
    <section
      aria-label={t('Log volume')}
      className={cn('border-b border-border py-3', insetClassName)}
    >
      <div className="mb-2 flex min-h-7 items-center justify-between gap-3">
        <div className="flex min-w-0 items-baseline gap-2">
          <h3 className="text-[13px] font-medium text-foreground">
            {t('Log volume')}
          </h3>
          {total !== undefined && (
            <span className="text-[12px] text-muted-foreground tabular-nums">
              {total === 1
                ? t('1 line')
                : t('{count} lines').replace('{count}', total.toLocaleString())}
            </span>
          )}
        </div>
        <div className="flex items-center gap-3">
          <span className="hidden items-center gap-1.5 text-[11px] text-muted-foreground sm:flex">
            <ChartSeriesDot color={STDOUT_COLOR} /> stdout
          </span>
          <span className="hidden items-center gap-1.5 text-[11px] text-muted-foreground sm:flex">
            <ChartSeriesDot color={STDERR_COLOR} /> stderr
          </span>
          {zoomed && (
            <Button
              variant="ghost"
              size="sm"
              className="h-7 gap-1.5 px-2 text-[12px] text-muted-foreground hover:text-foreground"
              onClick={onResetZoom}
            >
              <ZoomOut className="h-3.5 w-3.5" />
              {t('Reset zoom')}
            </Button>
          )}
        </div>
      </div>
      {loading && data.length === 0 ? (
        <Skeleton className="h-28 w-full" />
      ) : (
        <div
          // A new window starts a fresh chart, so no tooltip points at an old bar
          key={`${data[0]?.date ?? ''}-${data.length}`}
          className="relative h-28 w-full text-muted-foreground"
          title={t('Drag across the bars to zoom in')}
        >
          <ResponsiveContainer width="100%" height="100%">
            <BarChart
              data={data}
              margin={{ top: 4, right: 0, left: 0, bottom: 0 }}
              barCategoryGap="12%"
              className={surfaceClassName}
              {...chartProps}
            >
              <SeriesChartXAxis pointCount={data.length} labels={labels} />
              <UsageChartYAxis
                tickFormatter={yTickFormatter}
                allowDecimals={false}
                // Keep a scale on an empty window
                domain={max > 0 ? [0, 'auto'] : [0, 4]}
              />
              <Tooltip
                cursor={
                  isSelecting ? false : { fill: 'var(--muted)', opacity: 0.5 }
                }
                isAnimationActive={false}
                content={({ active, payload }) => {
                  if (isSelecting || !active || !payload?.length) return null
                  const point = payload[0].payload as RuntimeLogVolumePoint
                  return (
                    <VolumeTooltip
                      heading={formatLocalizedDate(
                        point.day,
                        interval === '1d' ? 'MMM d, yyyy' : 'MMM d, HH:mm',
                      )}
                      point={point}
                    />
                  )
                }}
              />
              <Bar
                dataKey="stdout"
                name="stdout"
                stackId="volume"
                fill={STDOUT_COLOR}
                fillOpacity={0.4}
                isAnimationActive={false}
              />
              <Bar
                dataKey="stderr"
                name="stderr"
                stackId="volume"
                fill={STDERR_COLOR}
                fillOpacity={0.8}
                radius={[2, 2, 0, 0]}
                isAnimationActive={false}
              />
              <UsageChartBrushReferenceArea
                left={brushLeft}
                right={brushRight}
              />
            </BarChart>
          </ResponsiveContainer>
          {max === 0 && (
            <p className="pointer-events-none absolute inset-0 flex items-center justify-center text-[12px] text-muted-foreground">
              {t('No lines in this time range')}
            </p>
          )}
        </div>
      )}
    </section>
  )
}

function VolumeTooltip({
  heading,
  point,
}: {
  heading: string
  point: RuntimeLogVolumePoint
}) {
  const t = useT()
  return (
    <div className="min-w-[160px] rounded-md border border-border bg-popover px-3 py-2 text-[12px]">
      <p className="mb-1.5 text-[11px] text-muted-foreground">{heading}</p>
      <Row color={STDOUT_COLOR} label="stdout" value={point.stdout} />
      <Row color={STDERR_COLOR} label="stderr" value={point.stderr} />
      <div className="mt-1 flex justify-between gap-6 border-t border-border pt-1">
        <span className="text-muted-foreground">{t('Total')}</span>
        <span className="font-medium text-foreground tabular-nums">
          {point.total.toLocaleString()}
        </span>
      </div>
    </div>
  )
}

function Row({
  color,
  label,
  value,
}: {
  color: string
  label: string
  value: number
}) {
  return (
    <div className="flex items-center justify-between gap-6">
      <span className="flex items-center gap-1.5 text-muted-foreground">
        <ChartSeriesDot color={color} />
        {label}
      </span>
      <span className="text-foreground tabular-nums">
        {value.toLocaleString()}
      </span>
    </div>
  )
}
