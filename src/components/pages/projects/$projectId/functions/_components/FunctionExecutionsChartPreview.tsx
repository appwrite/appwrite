import {
  useId,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
  type RefObject,
} from 'react'
import { createPortal } from 'react-dom'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  YAxis,
} from 'recharts'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { cn } from '@/lib/utils'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { useT } from '@/lib/i18n/translate'
import {
  useFunctionExecutionsForFunctionChart,
  useFunctionGbHoursForFunctionChart,
} from '@/lib/react-query/hooks'
import { getStableUsageChartDateRange } from '@/lib/usage/usage-date-range'
import {
  sumUsageChartPoints,
  type UsageChartPoint,
} from '@/lib/usage/usage-events-common'
import { formatExecutionsValue } from '@/lib/usage/executions-events'
import {
  formatGbHoursTotal,
  formatGbHoursValue,
} from '@/lib/usage/gb-hours-events'

/** Match serverless database card chart mid-section height. */
export const FUNCTION_EXECUTIONS_PREVIEW_HEIGHT = 200

const EXECUTIONS_COLOR = 'var(--chart-brand)'
const GB_HOURS_COLOR = 'var(--chart-2)'

type ChartPoint = UsageChartPoint & {
  executions: number
  gbHours: number
}

type ChartCoordinate = {
  x?: number
  y?: number
}

function buildEmptyExecutionsSeries(): ChartPoint[] {
  return Array.from({ length: 16 }, (_, index) => ({
    date: '',
    day: new Date(index),
    total: 0,
    executions: 0,
    gbHours: 0,
  }))
}

type FunctionExecutionsChartPreviewProps = {
  projectId: string
  functionId: string
  /** When false, skip usage API calls (e.g. usageStats profile flag off). */
  enabled?: boolean
  className?: string
}

function ChartTooltipBody({ point }: { point: ChartPoint }) {
  const t = useT()
  return (
    <>
      {point.date ? (
        <p className="text-[12px] font-medium text-foreground">{point.date}</p>
      ) : null}
      <p className="flex items-center gap-1.5 text-[12px] tabular-nums text-muted-foreground">
        <ChartSeriesDot color={EXECUTIONS_COLOR} />
        {formatExecutionsValue(point.executions)} {t('executions')}
      </p>
      <p className="flex items-center gap-1.5 text-[12px] tabular-nums text-muted-foreground">
        <ChartSeriesDot color={GB_HOURS_COLOR} />
        {formatGbHoursValue(point.gbHours)} {t('GB-hours')}
      </p>
    </>
  )
}

function getChartAnchorElement(
  portalContainerRef: RefObject<HTMLDivElement | null>,
): HTMLElement | null {
  const root = portalContainerRef.current
  if (!root) return null

  return (
    root.querySelector<HTMLElement>('.recharts-wrapper') ??
    root.querySelector<HTMLElement>('.recharts-responsive-container') ??
    root
  )
}

function PortaledChartTooltip({
  active,
  coordinate,
  portalContainerRef,
  children,
}: {
  active?: boolean
  coordinate?: ChartCoordinate
  portalContainerRef: RefObject<HTMLDivElement | null>
  children: ReactNode
}) {
  const [position, setPosition] = useState<{
    left: number
    top: number
    placement: 'above' | 'below'
  } | null>(null)

  const coordinateX = coordinate?.x
  const coordinateY = coordinate?.y

  useLayoutEffect(() => {
    if (
      !active ||
      coordinateX == null ||
      coordinateY == null ||
      !portalContainerRef.current
    ) {
      setPosition(null)
      return
    }

    const anchor = getChartAnchorElement(portalContainerRef)
    if (!anchor) {
      setPosition(null)
      return
    }

    const rect = anchor.getBoundingClientRect()
    const anchorX = rect.left + coordinateX
    const anchorY = rect.top + coordinateY
    const estimatedTooltipHeight = 52
    const gap = 8
    const viewportPadding = 8
    const fitsAbove =
      anchorY - estimatedTooltipHeight - gap >= viewportPadding
    const fitsBelow =
      anchorY + gap + estimatedTooltipHeight <=
      window.innerHeight - viewportPadding
    const placement = fitsAbove || !fitsBelow ? 'above' : 'below'

    setPosition({
      left: anchorX,
      top: anchorY,
      placement,
    })
  }, [active, coordinateX, coordinateY, portalContainerRef])

  if (!active || !position || typeof document === 'undefined') return null

  return createPortal(
    <div
      className={cn(
        'pointer-events-none fixed z-[200] max-w-none whitespace-nowrap rounded-md border border-border bg-popover px-2.5 py-1.5 shadow-md',
        position.placement === 'above'
          ? '-translate-x-1/2 -translate-y-[calc(100%+8px)]'
          : '-translate-x-1/2 translate-y-2',
      )}
      style={{ left: position.left, top: position.top }}
    >
      {children}
    </div>,
    document.body,
  )
}

function ChartTooltip({
  active,
  payload,
  coordinate,
  disabled,
  portalContainerRef,
}: {
  active?: boolean
  payload?: Array<{ payload?: ChartPoint }>
  coordinate?: ChartCoordinate
  disabled?: boolean
  portalContainerRef: RefObject<HTMLDivElement | null>
}) {
  const point = payload?.[0]?.payload
  if (disabled || !active || !point) return null

  return (
    <PortaledChartTooltip
      active={active}
      coordinate={coordinate}
      portalContainerRef={portalContainerRef}
    >
      <ChartTooltipBody point={point} />
    </PortaledChartTooltip>
  )
}

/**
 * Card mid-section for functions: executions sparkline for the last 24 hours.
 * Uses the same usage.listEvents metric as the project compute usage charts.
 */
export function FunctionExecutionsChartPreview({
  projectId,
  functionId,
  enabled = true,
  className,
}: FunctionExecutionsChartPreviewProps) {
  const t = useT()
  const gradientId = useId().replace(/:/g, '')
  const chartContainerRef = useRef<HTMLDivElement>(null)
  const dateRange = useMemo(() => getStableUsageChartDateRange(), [])

  const executionsQuery = useFunctionExecutionsForFunctionChart(
    projectId,
    functionId,
    dateRange,
    enabled,
  )
  const gbHoursQuery = useFunctionGbHoursForFunctionChart(
    projectId,
    functionId,
    dateRange,
    enabled,
  )

  const executionsPoints = executionsQuery.data?.chartPoints ?? []
  const gbHoursPoints = gbHoursQuery.data?.chartPoints ?? []
  const hasPoints = executionsPoints.length > 0 || gbHoursPoints.length > 0

  const chartData = useMemo((): ChartPoint[] => {
    if (!hasPoints) {
      return buildEmptyExecutionsSeries()
    }

    const executionsByTime = new Map(
      executionsPoints.map((point) => [point.day.getTime(), point]),
    )
    const gbHoursByTime = new Map(
      gbHoursPoints.map((point) => [point.day.getTime(), point]),
    )
    const timestamps = [
      ...new Set([
        ...executionsPoints.map((point) => point.day.getTime()),
        ...gbHoursPoints.map((point) => point.day.getTime()),
      ]),
    ].sort((a, b) => a - b)

    return timestamps.map((timestamp) => {
      const executionsPoint = executionsByTime.get(timestamp)
      const gbHoursPoint = gbHoursByTime.get(timestamp)
      const sourcePoint = executionsPoint ?? gbHoursPoint!

      return {
        ...sourcePoint,
        total: executionsPoint?.total ?? 0,
        executions: executionsPoint?.total ?? 0,
        gbHours: gbHoursPoint?.total ?? 0,
      }
    })
  }, [executionsPoints, gbHoursPoints, hasPoints])

  const executionsTotal = useMemo(
    () => sumUsageChartPoints(executionsPoints),
    [executionsPoints],
  )
  const gbHoursTotal = useMemo(
    () => sumUsageChartPoints(gbHoursPoints),
    [gbHoursPoints],
  )

  const tooltipDisabled =
    !hasPoints || (executionsTotal === 0 && gbHoursTotal === 0)

  return (
    <div
      className={cn(
        '-mx-4 mt-2 min-w-0 shrink-0 border-t border-border',
        className,
      )}
    >
      <div
        className="flex min-h-0 min-w-0 flex-col"
        style={{ height: FUNCTION_EXECUTIONS_PREVIEW_HEIGHT }}
      >
        <div className="shrink-0 px-4 pt-2.5 pb-16">
          <div className="flex h-5 min-w-0 items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3">
              <span className="inline-flex items-center gap-1.5 text-[12px] font-medium leading-none text-muted-foreground">
                <span
                  className="h-1.5 w-1.5 rounded-full"
                  style={{ backgroundColor: EXECUTIONS_COLOR }}
                  aria-hidden
                />
                {t('Executions')}
                <span className="font-mono tabular-nums text-foreground">
                  {executionsTotal.toLocaleString()}
                </span>
              </span>
              <span className="inline-flex items-center gap-1.5 text-[12px] font-medium leading-none text-muted-foreground">
                <span
                  className="h-1.5 w-1.5 rounded-full"
                  style={{ backgroundColor: GB_HOURS_COLOR }}
                  aria-hidden
                />
                {t('GB-hours')}
                <span className="font-mono tabular-nums text-foreground">
                  {formatGbHoursTotal(gbHoursTotal)}
                </span>
              </span>
            </div>
            <span className="shrink-0 text-[11px] font-medium leading-none text-muted-foreground/80">
              {t('Last 24 hours')}
            </span>
          </div>
        </div>

        <div
          ref={chartContainerRef}
          className="relative min-h-0 w-full min-w-0 flex-1"
          role="img"
          aria-label={`${t('Function executions')}, ${t('GB-hours')}. ${t('Last 24 hours')}`}
        >
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart
              data={chartData}
              margin={{ top: 4, right: 0, left: 0, bottom: 0 }}
            >
              <defs>
                <linearGradient
                  id={`executions-${gradientId}`}
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor={EXECUTIONS_COLOR}
                    stopOpacity={0.28}
                  />
                  <stop
                    offset="100%"
                    stopColor={EXECUTIONS_COLOR}
                    stopOpacity={0}
                  />
                </linearGradient>
                <linearGradient
                  id={`gb-hours-${gradientId}`}
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop
                    offset="0%"
                    stopColor={GB_HOURS_COLOR}
                    stopOpacity={0.18}
                  />
                  <stop
                    offset="100%"
                    stopColor={GB_HOURS_COLOR}
                    stopOpacity={0}
                  />
                </linearGradient>
              </defs>
              <YAxis yAxisId="executions" hide domain={[0, 'auto']} />
              <YAxis yAxisId="gbHours" hide domain={[0, 'auto']} />
              <Tooltip
                content={
                  <ChartTooltip
                    disabled={tooltipDisabled}
                    portalContainerRef={chartContainerRef}
                  />
                }
                allowEscapeViewBox={{ x: true, y: true }}
                isAnimationActive={false}
                wrapperStyle={{
                  visibility: 'hidden',
                  pointerEvents: 'none',
                  width: 0,
                  height: 0,
                  overflow: 'hidden',
                }}
                cursor={
                  tooltipDisabled
                    ? false
                    : {
                        stroke: 'hsl(var(--border))',
                        strokeWidth: 1,
                        strokeDasharray: '4 4',
                      }
                }
              />
              <Area
                type="monotone"
                dataKey="executions"
                yAxisId="executions"
                stroke={EXECUTIONS_COLOR}
                strokeWidth={1.5}
                fill={`url(#executions-${gradientId})`}
                dot={false}
                activeDot={
                  tooltipDisabled
                    ? false
                    : {
                        r: 3,
                        strokeWidth: 0,
                        fill: EXECUTIONS_COLOR,
                      }
                }
                {...CHART_ANIMATION_DISABLED}
              />
              <Area
                type="monotone"
                dataKey="gbHours"
                yAxisId="gbHours"
                stroke={GB_HOURS_COLOR}
                strokeWidth={1.5}
                fill={`url(#gb-hours-${gradientId})`}
                dot={false}
                activeDot={
                  tooltipDisabled
                    ? false
                    : {
                        r: 3,
                        strokeWidth: 0,
                        fill: GB_HOURS_COLOR,
                      }
                }
                {...CHART_ANIMATION_DISABLED}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>
    </div>
  )
}
