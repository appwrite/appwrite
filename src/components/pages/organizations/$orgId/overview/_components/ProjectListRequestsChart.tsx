import { useId, useMemo, type CSSProperties } from 'react'
import { TrendingDown, TrendingUp } from 'lucide-react'
import { Area, AreaChart, ResponsiveContainer, Tooltip } from 'recharts'
import type { ProjectListRequestsUsageEntry } from '@/lib/react-query/hooks/usage-events'
import { RESOURCE_CARD_SECTION_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import type { UsageChartPoint } from '@/lib/usage/usage-events-common'
import {
  formatRequestsTotal,
  formatRequestsValue,
} from '@/lib/usage/requests-events'
import { cn } from '@/lib/utils'

export const PROJECT_LIST_REQUESTS_CHART_HEIGHT = 48
export const PROJECT_LIST_REQUESTS_TABLE_CHART_HEIGHT = 32

/** Header row (h-5) + mb-1.5 gap + chart area. Keeps card height stable while loading. */
export const PROJECT_LIST_REQUESTS_CONTENT_MIN_HEIGHT =
  20 + 6 + PROJECT_LIST_REQUESTS_CHART_HEIGHT

/** Single-row table cell: value column + chart. */
export const PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT =
  PROJECT_LIST_REQUESTS_TABLE_CHART_HEIGHT

/** Section divider pt-2.5 + content block. */
export const PROJECT_LIST_REQUESTS_SECTION_MIN_HEIGHT =
  10 + PROJECT_LIST_REQUESTS_CONTENT_MIN_HEIGHT

const CHART_COLOR = 'var(--chart-brand)'
/** Soft greyscale placeholder — border-toned line, whisper-light fill. */
const SKELETON_CHART_STROKE = 'hsl(var(--border))'
const SKELETON_CHART_FILL = 'hsl(var(--muted-foreground))'
const SKELETON_CHART_FILL_TOP_OPACITY = 0.06
const SKELETON_CHART_FILL_BOTTOM_OPACITY = 0

/** Stable wavy placeholder series for the loading chart. */
const SKELETON_CHART_VALUES = [
  620, 840, 760, 980, 910, 1120, 1040, 1180, 990, 1260, 1100, 1240,
]

const SKELETON_CHART_POINTS: UsageChartPoint[] = SKELETON_CHART_VALUES.map(
  (total, index) => ({
    date: '',
    day: new Date(index),
    total,
  }),
)

const EMPTY_CHART_POINTS: UsageChartPoint[] = Array.from(
  { length: 12 },
  (_, index) => ({
    date: '',
    day: new Date(index),
    total: 0,
  }),
)

/** Soft reveal when usage data replaces the skeleton chart. */
const CHART_REVEAL_CLASS =
  'animate-in fade-in-0 slide-in-from-bottom-1 duration-500 ease-out motion-reduce:animate-none'

const CHART_REVEAL_DELAY_CLASS = 'delay-100'

type ProjectListRequestsChartProps = {
  totalRequests?: number
  changePercent?: number
  chartPoints?: UsageChartPoint[]
  isLoading: boolean
  isError?: boolean
  className?: string
  variant?: 'card' | 'table'
}

function ChartTooltip({
  active,
  payload,
  disabled,
}: {
  active?: boolean
  payload?: Array<{ payload: UsageChartPoint & { value: number } }>
  disabled?: boolean
}) {
  if (disabled || !active || !payload?.length) return null
  const point = payload[0].payload
  return (
    <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 shadow-sm">
      <p className="text-[12px] font-medium text-foreground">{point.date}</p>
      <p className="text-[12px] tabular-nums text-muted-foreground">
        {formatRequestsValue(point.total)} requests
      </p>
    </div>
  )
}

type RequestsChartAreaProps = {
  chartData: Array<UsageChartPoint & { value: number }>
  gradientId: string
  isSkeleton?: boolean
  tooltipDisabled?: boolean
  height?: number
}

function RequestsChartArea({
  chartData,
  gradientId,
  isSkeleton = false,
  tooltipDisabled = false,
  height = PROJECT_LIST_REQUESTS_CHART_HEIGHT,
}: RequestsChartAreaProps) {
  const strokeColor = isSkeleton ? SKELETON_CHART_STROKE : CHART_COLOR
  const fillTopColor = isSkeleton ? SKELETON_CHART_FILL : CHART_COLOR
  const fillTopOpacity = isSkeleton ? SKELETON_CHART_FILL_TOP_OPACITY : 0.22
  const fillBottomOpacity = isSkeleton
    ? SKELETON_CHART_FILL_BOTTOM_OPACITY
    : 0
  const strokeWidth = isSkeleton ? 0.75 : 1.5

  return (
    <div className="h-full w-full min-w-0 text-muted-foreground">
      <ResponsiveContainer
        width="100%"
        height={height}
        initialDimension={{
          width: 320,
          height,
        }}
      >
        <AreaChart
          data={chartData}
          margin={{ top: 4, right: 0, left: 0, bottom: 0 }}
        >
          <defs>
            <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="0%"
                stopColor={fillTopColor}
                stopOpacity={fillTopOpacity}
              />
              <stop
                offset="100%"
                stopColor={fillTopColor}
                stopOpacity={fillBottomOpacity}
              />
            </linearGradient>
          </defs>
          <Tooltip
            content={<ChartTooltip disabled={tooltipDisabled} />}
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
            dataKey="value"
            stroke={strokeColor}
            strokeWidth={strokeWidth}
            fill={`url(#${gradientId})`}
            dot={false}
            isAnimationActive={!isSkeleton}
            animationDuration={500}
            animationEasing="ease-out"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  )
}

function RequestsChartBlock({
  chartData,
  gradientId,
  chartHeight,
  isLoading,
  isError,
}: {
  chartData: Array<UsageChartPoint & { value: number }>
  gradientId: string
  chartHeight: number
  isLoading: boolean
  isError: boolean
}) {
  if (isError) {
    return (
      <div className="flex h-full items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/10 px-2 text-center">
        <span className="text-[11px] text-muted-foreground">Unavailable</span>
      </div>
    )
  }

  if (isLoading) {
    return (
      <RequestsChartArea
        chartData={chartData}
        gradientId={gradientId}
        isSkeleton
        tooltipDisabled
        height={chartHeight}
      />
    )
  }

  return (
    <div
      key="loaded-chart"
      className={cn('h-full', CHART_REVEAL_CLASS, CHART_REVEAL_DELAY_CLASS)}
    >
      <RequestsChartArea
        chartData={chartData}
        gradientId={gradientId}
        tooltipDisabled={false}
        height={chartHeight}
      />
    </div>
  )
}

export function ProjectListRequestsChart({
  totalRequests = 0,
  changePercent = 0,
  chartPoints = [],
  isLoading,
  isError = false,
  className,
  variant = 'card',
}: ProjectListRequestsChartProps) {
  const gradientId = useId().replace(/:/g, '')
  const hasPoints = chartPoints.length > 0
  const isZeroUsage = !isLoading && !isError && totalRequests === 0
  const isTable = variant === 'table'
  const chartHeight = isTable
    ? PROJECT_LIST_REQUESTS_TABLE_CHART_HEIGHT
    : PROJECT_LIST_REQUESTS_CHART_HEIGHT

  const showChange = !isLoading && !isError && hasPoints && !isZeroUsage
  const isPositive = changePercent > 0
  const isNegative = changePercent < 0

  const chartData = useMemo(() => {
    const points = isLoading
      ? SKELETON_CHART_POINTS
      : hasPoints
        ? chartPoints
        : EMPTY_CHART_POINTS

    return points.map((point) => ({
      ...point,
      value: point.total,
    }))
  }, [chartPoints, hasPoints, isLoading])

  const valueContent = isLoading ? (
    <span
      className="h-3.5 w-8 shrink-0 rounded-sm bg-border/70"
      aria-hidden
    />
  ) : (
    <div
      key="loaded-values"
      className={cn('min-w-0', !isTable && CHART_REVEAL_CLASS)}
    >
      {isZeroUsage ? (
        <span
          className={cn(
            'font-medium leading-none text-muted-foreground',
            isTable ? 'text-[12px]' : 'text-[13px]',
          )}
        >
          N/A
        </span>
      ) : (
        <span
          className={cn(
            'font-medium leading-none tabular-nums text-foreground',
            isTable ? 'text-[12px]' : 'text-[13px]',
          )}
        >
          {formatRequestsTotal(totalRequests)}
        </span>
      )}
    </div>
  )

  const changeBadge = !isTable ? (
    <span
      className={cn(
        'ml-auto inline-flex h-3.5 shrink-0 items-center gap-0.5 text-[11px] font-medium leading-none tabular-nums',
        isLoading && 'invisible',
        !isLoading &&
          showChange &&
          cn(
            isPositive && 'text-emerald-600 dark:text-emerald-400',
            isNegative && 'text-red-500 dark:text-red-400',
            !isPositive && !isNegative && 'text-muted-foreground',
          ),
      )}
      aria-hidden={isLoading || !showChange}
    >
      {isPositive ? (
        <TrendingUp className="h-3.5 w-3.5" aria-hidden />
      ) : isNegative ? (
        <TrendingDown className="h-3.5 w-3.5" aria-hidden />
      ) : (
        <span className="h-3.5 w-3.5" aria-hidden />
      )}
      {isPositive ? '+' : ''}
      {changePercent}%
    </span>
  ) : null

  if (isTable) {
    return (
      <div
        className={cn('flex min-w-0 items-center gap-2', className)}
        style={{ minHeight: PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT }}
        aria-busy={isLoading}
        aria-label={isLoading ? 'Loading request usage' : undefined}
        onClick={(event) => event.stopPropagation()}
      >
        <div className="w-11 shrink-0">{valueContent}</div>
        <div
          className="relative min-w-0 flex-1 overflow-hidden"
          style={{ height: chartHeight }}
        >
          <RequestsChartBlock
            chartData={chartData}
            gradientId={gradientId}
            chartHeight={chartHeight}
            isLoading={isLoading}
            isError={isError}
          />
        </div>
      </div>
    )
  }

  return (
    <div
      className={cn('min-w-0', className)}
      style={{ minHeight: PROJECT_LIST_REQUESTS_CONTENT_MIN_HEIGHT }}
      aria-busy={isLoading}
      aria-label={isLoading ? 'Loading request usage' : undefined}
    >
      <div className="mb-1.5 flex h-5 min-w-0 items-center gap-2">
        <span className="shrink-0 text-[12px] font-medium leading-none text-muted-foreground">
          Requests
        </span>

        {isLoading ? (
          <>
            {valueContent}
            <span className="ml-auto inline-flex h-3.5 w-9 shrink-0" aria-hidden />
          </>
        ) : (
          <div
            className={cn(
              'flex min-w-0 flex-1 items-center gap-2',
              CHART_REVEAL_CLASS,
            )}
          >
            {isZeroUsage ? (
              <>
                {valueContent}
                <span
                  className="ml-auto inline-flex h-3.5 w-9 shrink-0"
                  aria-hidden
                />
              </>
            ) : (
              <>
                {valueContent}
                {changeBadge}
              </>
            )}
          </div>
        )}
      </div>

      <div
        className="relative w-full min-w-0 overflow-hidden"
        style={{ height: chartHeight }}
      >
        {isError ? (
          <div className="flex h-full items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/10 px-3 text-center">
            <span className="text-[12px] text-muted-foreground">
              Usage unavailable
            </span>
          </div>
        ) : (
          <RequestsChartBlock
            chartData={chartData}
            gradientId={gradientId}
            chartHeight={chartHeight}
            isLoading={isLoading}
            isError={isError}
          />
        )}
      </div>
    </div>
  )
}

type ProjectListCardRequestsChartProps = {
  projectId: string
  usageByProjectId: Map<string, ProjectListRequestsUsageEntry>
  className?: string
}

function ProjectListRequestsChartFromUsage({
  projectId,
  usageByProjectId,
  className,
  variant = 'card',
}: {
  projectId: string
  usageByProjectId: Map<string, ProjectListRequestsUsageEntry>
  className?: string
  variant?: 'card' | 'table'
}) {
  const usage = usageByProjectId.get(projectId)

  return (
    <ProjectListRequestsChart
      className={className}
      variant={variant}
      isLoading={usage?.isLoading ?? true}
      isError={usage?.isError ?? false}
      totalRequests={usage?.data?.totalRequests}
      changePercent={usage?.data?.changePercent}
      chartPoints={usage?.data?.chartPoints}
    />
  )
}

export function ProjectListCardRequestsChart({
  projectId,
  usageByProjectId,
  className,
}: ProjectListCardRequestsChartProps) {
  return (
    <div
      className={cn(RESOURCE_CARD_SECTION_DIVIDER_CLASSNAME, 'shrink-0')}
      style={
        {
          minHeight: PROJECT_LIST_REQUESTS_SECTION_MIN_HEIGHT,
        } satisfies CSSProperties
      }
    >
      <ProjectListRequestsChartFromUsage
        projectId={projectId}
        usageByProjectId={usageByProjectId}
        className={className}
        variant="card"
      />
    </div>
  )
}

type ProjectListTableRequestsCellProps = {
  projectId: string
  usageByProjectId: Map<string, ProjectListRequestsUsageEntry>
}

export function ProjectListTableRequestsCell({
  projectId,
  usageByProjectId,
}: ProjectListTableRequestsCellProps) {
  return (
    <ProjectListRequestsChartFromUsage
      projectId={projectId}
      usageByProjectId={usageByProjectId}
      variant="table"
    />
  )
}
