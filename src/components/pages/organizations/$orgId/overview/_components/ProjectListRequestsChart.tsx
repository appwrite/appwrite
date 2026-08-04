import { useId, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { Link } from '@tanstack/react-router'
import { TrendingDown, TrendingUp } from 'lucide-react'
import { Area, AreaChart, ResponsiveContainer, Tooltip } from 'recharts'
import type { TooltipProps } from 'recharts'
import type { ProjectListRequestsUsageEntry } from '@/lib/react-query/hooks/usage-events'
import { RESOURCE_CARD_SECTION_DIVIDER_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import type { UsageChartPoint } from '@/lib/usage/usage-events-common'
import { sumUsageChartPoints } from '@/lib/usage/usage-events-common'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import {
  formatRequestsTotal,
  formatRequestsValue,
} from '@/lib/usage/requests-events'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export const PROJECT_LIST_REQUESTS_CHART_HEIGHT = 48
export const PROJECT_LIST_REQUESTS_TABLE_CHART_HEIGHT = 32

/** Header row (h-5) + mb-1.5 gap + chart area. Keeps card height stable while loading. */
export const PROJECT_LIST_REQUESTS_CONTENT_MIN_HEIGHT =
  20 + 6 + PROJECT_LIST_REQUESTS_CHART_HEIGHT

/** Single-row table cell: value column + chart. */
export const PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT =
  PROJECT_LIST_REQUESTS_TABLE_CHART_HEIGHT

/** Reserved height for the requests table cell content (excludes cell padding). */
export const PROJECT_LIST_REQUESTS_TABLE_CELL_HEIGHT =
  PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT

/** Section divider pt-2.5 + content block. */
export const PROJECT_LIST_REQUESTS_SECTION_MIN_HEIGHT =
  10 + PROJECT_LIST_REQUESTS_CONTENT_MIN_HEIGHT

const CHART_COLOR = 'var(--chart-brand)'
/** Soft greyscale placeholder - border-toned line, whisper-light fill. */
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

const projectChartLinkClassName =
  'pointer-events-auto block min-w-0 cursor-pointer rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background [&_*]:!cursor-pointer'

function ProjectRequestsChartLink({
  projectId,
  children,
  className,
  style,
}: {
  projectId: string
  children: ReactNode
  className?: string
  style?: CSSProperties
}) {
  const t = useT()
  return (
    <Link
      to="/projects/$projectId"
      params={{ projectId }}
      className={cn(projectChartLinkClassName, className)}
      style={style}
      aria-label={t('View project')}
      onClick={(event) => event.stopPropagation()}
    >
      {children}
    </Link>
  )
}

type ProjectListRequestsChartProps = {
  changePercent?: number
  chartPoints?: UsageChartPoint[]
  isLoading: boolean
  isError?: boolean
  /** Locked / blocked: no chart, show unavailable copy instead of a zero series. */
  unavailable?: boolean
  className?: string
  variant?: 'card' | 'table'
}

function ChartTooltipBody({
  point,
}: {
  point: UsageChartPoint & { value: number }
}) {
  const t = useT()
  return (
    <>
      <p className="text-[12px] font-medium text-foreground">{point.date}</p>
      <p className="text-[12px] tabular-nums text-muted-foreground">
        {formatRequestsValue(point.total)} {t('requests')}
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
  coordinate?: TooltipProps<number, string>['coordinate']
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
}: TooltipProps<number, string> & {
  disabled?: boolean
  portalContainerRef?: RefObject<HTMLDivElement | null>
}) {
  if (disabled || !active || !payload?.length) return null

  const point = payload[0].payload as UsageChartPoint & { value: number }

  if (portalContainerRef) {
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

  return (
    <div className="rounded-md border border-border bg-popover px-2.5 py-1.5 shadow-sm">
      <ChartTooltipBody point={point} />
    </div>
  )
}

type RequestsChartAreaProps = {
  chartData: Array<UsageChartPoint & { value: number }>
  gradientId: string
  isSkeleton?: boolean
  tooltipDisabled?: boolean
  height?: number
  usePortalTooltip?: boolean
}

function RequestsChartArea({
  chartData,
  gradientId,
  isSkeleton = false,
  tooltipDisabled = false,
  height = PROJECT_LIST_REQUESTS_CHART_HEIGHT,
  usePortalTooltip = false,
}: RequestsChartAreaProps) {
  const chartContainerRef = useRef<HTMLDivElement>(null)
  const strokeColor = isSkeleton ? SKELETON_CHART_STROKE : CHART_COLOR
  const fillTopColor = isSkeleton ? SKELETON_CHART_FILL : CHART_COLOR
  const fillTopOpacity = isSkeleton ? SKELETON_CHART_FILL_TOP_OPACITY : 0.22
  const fillBottomOpacity = isSkeleton
    ? SKELETON_CHART_FILL_BOTTOM_OPACITY
    : 0
  const strokeWidth = isSkeleton ? 0.75 : 1.5

  return (
    <div
      ref={chartContainerRef}
      className="h-full w-full min-w-0 text-muted-foreground"
    >
      <ResponsiveContainer
        width="100%"
        height={height}
        minHeight={height}
        debounce={0}
        initialDimension={{
          width: 320,
          height,
        }}
      >
        <AreaChart
          data={chartData}
          margin={{ top: 4, right: 0, left: 0, bottom: 0 }}
          style={{ cursor: 'pointer' }}
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
            content={
              <ChartTooltip
                disabled={tooltipDisabled}
                portalContainerRef={
                  usePortalTooltip ? chartContainerRef : undefined
                }
              />
            }
            allowEscapeViewBox={{ x: true, y: true }}
            isAnimationActive={false}
            wrapperStyle={
              usePortalTooltip
                ? {
                    visibility: 'hidden',
                    pointerEvents: 'none',
                    width: 0,
                    height: 0,
                    overflow: 'hidden',
                  }
                : { zIndex: 50, pointerEvents: 'none' }
            }
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
            {...CHART_ANIMATION_DISABLED}
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
  usePortalTooltip = false,
}: {
  chartData: Array<UsageChartPoint & { value: number }>
  gradientId: string
  chartHeight: number
  isLoading: boolean
  isError: boolean
  usePortalTooltip?: boolean
}) {
  const t = useT()
  if (isError) {
    return (
      <div className="flex h-full items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/10 px-2 text-center">
        <span className="text-[11px] text-muted-foreground">{t('Unavailable')}</span>
      </div>
    )
  }

  return (
    <RequestsChartArea
      chartData={chartData}
      gradientId={gradientId}
      isSkeleton={isLoading}
      tooltipDisabled={isLoading}
      height={chartHeight}
      usePortalTooltip={usePortalTooltip}
    />
  )
}

export function ProjectListRequestsChart({
  changePercent = 0,
  chartPoints = [],
  isLoading,
  isError = false,
  unavailable = false,
  className,
  variant = 'card',
}: ProjectListRequestsChartProps) {
  const t = useT()
  const gradientId = useId().replace(/:/g, '')
  const showUnavailable = unavailable || isError
  const hasPoints = chartPoints.length > 0
  const totalRequests = useMemo(
    () => sumUsageChartPoints(chartPoints),
    [chartPoints],
  )
  const isZeroUsage =
    !isLoading && !showUnavailable && totalRequests === 0
  const isTable = variant === 'table'
  const chartHeight = isTable
    ? PROJECT_LIST_REQUESTS_TABLE_CHART_HEIGHT
    : PROJECT_LIST_REQUESTS_CHART_HEIGHT

  const showChange =
    !isLoading && !showUnavailable && hasPoints && !isZeroUsage
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

  const valueTextSizeClass = isTable ? 'text-[12px]' : 'text-[13px]'
  const valueTextLayoutClass = isTable
    ? 'block max-w-full truncate'
    : 'shrink-0'

  const unavailableMessage = (
    <div className="flex h-full w-full items-center justify-center rounded-md border border-dashed border-border/60 bg-muted/10 px-3 text-center">
      <span className="text-[12px] leading-snug text-muted-foreground">
        {t('Usage unavailable')}
      </span>
    </div>
  )

  const valueContent = isLoading ? (
    <span
      className="block h-3.5 w-8 max-w-full shrink-0 rounded-sm bg-border/70"
      aria-hidden
    />
  ) : showUnavailable || isZeroUsage ? (
    <div className="min-w-0">
      <span
        className={cn(
          valueTextLayoutClass,
          'font-medium leading-none text-muted-foreground',
          valueTextSizeClass,
        )}
      >
        {t('N/A')}
      </span>
    </div>
  ) : (
    <div className="min-w-0">
      <span
        className={cn(
          valueTextLayoutClass,
          'font-medium leading-none tabular-nums text-foreground',
          valueTextSizeClass,
        )}
      >
        {formatRequestsTotal(totalRequests)}
      </span>
    </div>
  )

  const changeBadge = !isTable ? (
    <span
      className={cn(
        'ms-auto inline-flex h-3.5 shrink-0 items-center gap-0.5 text-[11px] font-medium leading-none tabular-nums',
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
    if (showUnavailable && !isLoading) {
      return (
        <div
          className={cn('flex h-full min-w-0 items-center', className)}
          style={{
            height: PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT,
            minHeight: PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT,
          }}
          aria-label={t('Usage unavailable')}
        >
          <span className="text-[12px] font-medium text-muted-foreground">
            {t('N/A')}
          </span>
        </div>
      )
    }

    return (
      <div
        className={cn('flex h-full min-w-0 items-center gap-2', className)}
        style={{
          height: PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT,
          minHeight: PROJECT_LIST_REQUESTS_TABLE_ROW_MIN_HEIGHT,
        }}
        aria-busy={isLoading}
        aria-label={isLoading ? t('Loading request usage') : undefined}
      >
        <div className="flex h-full w-11 shrink-0 items-center">
          {valueContent}
        </div>
        <div
          className="relative min-w-0 flex-1 overflow-hidden"
          style={{
            height: chartHeight,
            minHeight: chartHeight,
          }}
        >
          <div className="absolute inset-0">
            <RequestsChartBlock
              chartData={chartData}
              gradientId={gradientId}
              chartHeight={chartHeight}
              isLoading={isLoading}
              isError={isError}
              usePortalTooltip
            />
          </div>
        </div>
      </div>
    )
  }

  return (
    <div
      className={cn('min-w-0', className)}
      style={{ minHeight: PROJECT_LIST_REQUESTS_CONTENT_MIN_HEIGHT }}
      aria-busy={isLoading}
      aria-label={
        isLoading
          ? t('Loading request usage')
          : showUnavailable
            ? t('Usage unavailable')
            : undefined
      }
    >
      <div className="mb-1.5 flex h-5 min-w-0 items-center gap-2">
        <span className="shrink-0 text-[12px] font-medium leading-none text-muted-foreground">
          {t('Requests')}
        </span>

        {isLoading ? (
          <>
            {valueContent}
            <span className="ms-auto inline-flex h-3.5 w-9 shrink-0" aria-hidden />
          </>
        ) : showUnavailable ? null : (
          <div className="flex min-w-0 flex-1 items-center gap-2">
            {isZeroUsage ? (
              <>
                {valueContent}
                <span
                  className="ms-auto inline-flex h-3.5 w-9 shrink-0"
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
        {showUnavailable ? (
          unavailableMessage
        ) : (
          <RequestsChartBlock
            chartData={chartData}
            gradientId={gradientId}
            chartHeight={chartHeight}
            isLoading={isLoading}
            isError={isError}
            usePortalTooltip
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
      unavailable={usage?.unavailable === true}
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
    <ProjectRequestsChartLink
      projectId={projectId}
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
    </ProjectRequestsChartLink>
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
    <div className="flex h-full w-full items-center">
      <div
        className="w-full"
        style={{
          height: PROJECT_LIST_REQUESTS_TABLE_CELL_HEIGHT,
          minHeight: PROJECT_LIST_REQUESTS_TABLE_CELL_HEIGHT,
        }}
      >
        <ProjectListRequestsChartFromUsage
          projectId={projectId}
          usageByProjectId={usageByProjectId}
          variant="table"
        />
      </div>
    </div>
  )
}
