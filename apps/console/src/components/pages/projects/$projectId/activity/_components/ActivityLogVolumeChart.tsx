'use client'

import {
  memo,
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type SyntheticEvent,
} from 'react'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { ChevronDown } from 'lucide-react'
import {
  Bar,
  BarChart,
  Cell,
  Customized,
  ResponsiveContainer,
  Tooltip,
  YAxis,
} from 'recharts'
import { cn } from '@/lib/utils'
import {
  createCompactCountAxisTickFormatter,
} from '@/lib/usage/format-metric'
import { USAGE_CHART_Y_AXIS_WIDTH } from '../../overview/chart-panel'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import { ChartSeriesDot } from '@/components/global/shared/ChartSeriesDot'
import { Button } from '@/components/ui/button'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'
import { useT } from '@/lib/i18n/translate'

/**
 * Brand secondary palette - 100% baselines (style guide). Each hue uses
 * {@link OPACITY_STEPS.length} opacity steps on that hex before the next baseline
 * (Mint → Purple → Orange). Guide 50% swatches: #E7F8F7, #E5E1FF, #FFEAE1.
 */
const BRAND_SECONDARY_100 = {
  mint: '#85DBD8',
  purple: '#7C67FE',
  orange: '#FE9567',
} as const

/** Mock stacked series - resource-type buckets (aligned with activity `resourceType` filter). */
const RESOURCE_SERIES = [
  { key: 'user', label: 'User' },
  { key: 'database', label: 'Database' },
  { key: 'function', label: 'Function' },
  { key: 'file', label: 'File' },
  { key: 'bucket', label: 'Bucket' },
  { key: 'document', label: 'Document' },
  { key: 'collection', label: 'Collection' },
  { key: 'team', label: 'Team' },
  { key: 'site', label: 'Site' },
  { key: 'rule', label: 'Rule' },
  { key: 'project', label: 'Project' },
] as const

/** Opacity ramp on each 100% baseline (7 steps per hue before the next baseline). */
const OPACITY_STEPS = [1, 0.91, 0.82, 0.71, 0.6, 0.48, 0.34] as const

const SHADES_PER_BASE = OPACITY_STEPS.length

/** Baseline order: Mint → Purple → Orange (secondary palette 100% only). */
const SERIES_BASE_COLORS = [
  BRAND_SECONDARY_100.mint,
  BRAND_SECONDARY_100.purple,
  BRAND_SECONDARY_100.orange,
] as const

/** Multiplies every segment’s alpha so stacks read slightly softer on the page. */
const BAR_GLOBAL_ALPHA = 0.86

function hexToRgb(hex: string): { r: number; g: number; b: number } | null {
  const n = hex.replace('#', '').trim()
  if (n.length !== 6 || !/^[0-9a-fA-F]{6}$/.test(n)) return null
  return {
    r: Number.parseInt(n.slice(0, 2), 16),
    g: Number.parseInt(n.slice(2, 4), 16),
    b: Number.parseInt(n.slice(4, 6), 16),
  }
}

function rgba(hex: string, alpha: number): string {
  const rgb = hexToRgb(hex)
  if (!rgb) return hex
  const a = Math.min(1, Math.max(0, alpha))
  return `rgba(${rgb.r},${rgb.g},${rgb.b},${a})`
}

function seriesShade(seriesIndex: number): { baseHex: string; opacity: number } {
  const baseIdx = Math.floor(seriesIndex / SHADES_PER_BASE)
  const stepIdx = seriesIndex % SHADES_PER_BASE
  const baseHex = SERIES_BASE_COLORS[baseIdx % SERIES_BASE_COLORS.length]
  const opacity = OPACITY_STEPS[stepIdx] ?? OPACITY_STEPS[OPACITY_STEPS.length - 1]
  return { baseHex, opacity }
}

function effectiveBarAlpha(seriesIndex: number): number {
  const { opacity } = seriesShade(seriesIndex)
  return Math.min(1, opacity * BAR_GLOBAL_ALPHA)
}

function seriesFill(seriesIndex: number): string {
  const { baseHex, opacity } = seriesShade(seriesIndex)
  return rgba(baseHex, Math.min(1, opacity * BAR_GLOBAL_ALPHA))
}

function seriesFillHover(seriesIndex: number): string {
  const { baseHex, opacity } = seriesShade(seriesIndex)
  return rgba(baseHex, Math.min(1, (opacity + 0.22) * BAR_GLOBAL_ALPHA))
}

/** Brighter than `seriesFillHover` - active stacked segment under the pointer. */
function seriesFillEmphasis(seriesIndex: number): string {
  const { baseHex, opacity } = seriesShade(seriesIndex)
  return rgba(baseHex, Math.min(1, (opacity + 0.38) * BAR_GLOBAL_ALPHA))
}

/** Deterministic PRNG for stable shuffle (same seed → same order). */
function mulberry32(seed: number): () => number {
  let a = seed >>> 0
  return () => {
    a += 0x6d2b79f5
    let t = a
    t = Math.imul(t ^ (t >>> 15), t | 1)
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/** Fisher–Yates shuffle of [0..length-1] using `rng` in [0,1). */
function shuffledIndices(length: number, rng: () => number): number[] {
  const arr = Array.from({ length }, (_, i) => i)
  for (let i = length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1))
    const tmp = arr[i]!
    arr[i] = arr[j]!
    arr[j] = tmp
  }
  return arr
}

function colorSlotPermutationSeed(rangeFrom: Date, rangeTo: Date): number {
  return (rangeFrom.getTime() ^ Math.imul(rangeTo.getTime(), 0x9e37_79b9)) >>> 0
}

/** One vertical fade over the whole plot (all stacks) - slightly veils the bottom of every column together. */
function ChartPlotBottomFade({
  offset,
  gradientId,
}: {
  offset?: { top: number; left: number; width: number; height: number }
  gradientId: string
}) {
  if (!offset?.width || !offset.height) return null
  const { left, top, width, height } = offset
  return (
    <g pointerEvents="none" aria-hidden>
      <defs>
        <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor="var(--background)" stopOpacity="0" />
          <stop offset="62%" stopColor="var(--background)" stopOpacity="0" />
          <stop offset="100%" stopColor="var(--background)" stopOpacity="0.2" />
        </linearGradient>
      </defs>
      <rect
        x={left}
        y={top}
        width={width}
        height={height}
        fill={`url(#${gradientId})`}
      />
    </g>
  )
}

type ChartRow = {
  t: string
  label: string
} & Record<(typeof RESOURCE_SERIES)[number]['key'], number>

function parseActiveBarIndex(activeTooltipIndex: unknown): number | null {
  if (activeTooltipIndex === undefined || activeTooltipIndex === null) return null
  const n =
    typeof activeTooltipIndex === 'number'
      ? activeTooltipIndex
      : Number.parseInt(String(activeTooltipIndex), 10)
  return Number.isNaN(n) ? null : n
}

/** Pointer inside chart surface - matches Recharts scaling (ResponsiveContainer / CSS scale). */
function pointerInChartWrapper(e: SyntheticEvent<Element>): {
  x: number
  y: number
  w: number
  h: number
} {
  const ne = e.nativeEvent
  if (!(ne instanceof MouseEvent)) return { x: 0, y: 0, w: 0, h: 0 }
  const el = e.currentTarget
  if (!(el instanceof HTMLElement)) return { x: 0, y: 0, w: 0, h: 0 }
  const rect = el.getBoundingClientRect()
  const scaleX = rect.width / el.offsetWidth || 1
  const scaleY = rect.height / el.offsetHeight || 1
  return {
    x: Math.round((ne.clientX - rect.left) / scaleX),
    y: Math.round((ne.clientY - rect.top) / scaleY),
    w: el.offsetWidth,
    h: el.offsetHeight,
  }
}

/** Keeps the tooltip inside the chart box (right/top bars otherwise clip a wide card). */
function clampVolumeTooltipPosition(
  px: number,
  py: number,
  chartW: number,
  chartH: number,
): { x: number; y: number } {
  const GAP = 36
  const EDGE = 6
  // Matches `VolumeTooltip` min-width + border; a bit of slack for scrollbars / font.
  const EST_W = 300
  const EST_H = 220

  let x = px + GAP
  let y = py + GAP

  if (chartW > 0 && x + EST_W > chartW - EDGE) {
    x = px - GAP - EST_W
  }
  if (chartW > 0) {
    x = Math.min(Math.max(EDGE, x), Math.max(EDGE, chartW - EST_W - EDGE))
  }

  if (chartH > 0 && y + EST_H > chartH - EDGE) {
    y = py - GAP - EST_H
  }
  if (chartH > 0) {
    y = Math.min(Math.max(EDGE, y), Math.max(EDGE, chartH - EST_H - EDGE))
  }

  return { x, y }
}

function buildMockVolumeByResource(from: Date, to: Date): ChartRow[] {
  const spanMs = Math.max(to.getTime() - from.getTime(), 60_000)
  const hours = spanMs / (60 * 60 * 1000)
  const targetPoints =
    hours <= 36 ? 18 : hours <= 7 * 24 ? 14 : Math.min(20, Math.max(10, Math.ceil(hours / 24)))
  const step = spanMs / targetPoints

  return Array.from({ length: targetPoints + 1 }, (_, i) => {
    const t = new Date(from.getTime() + i * step)
    const label =
      spanMs < 48 * 60 * 60 * 1000
        ? formatLocalizedDate(t, 'MMM d, HH:mm')
        : spanMs < 14 * 24 * 60 * 60 * 1000
          ? formatLocalizedDate(t, 'EEE d')
          : formatLocalizedDate(t, 'MMM d')

    const base = 12 + (i % 4) * 6
    const row = {
      t: t.toISOString(),
      label,
      ...Object.fromEntries(RESOURCE_SERIES.map(({ key }) => [key, 0])),
    } as ChartRow

    RESOURCE_SERIES.forEach(({ key }, s) => {
      const wave =
        Math.sin(i / 2.2 + s * 0.4) * 14 +
        Math.cos(i / 3.5 + s * 0.6) * 9 +
        (i / targetPoints) * 18
      row[key] = Math.max(0, Math.round(base + wave + s * 5))
    })

    return row
  })
}

type ResourceKey = (typeof RESOURCE_SERIES)[number]['key']

function VolumeTooltip({
  active,
  payload,
  label,
  highlightedDataKey,
  activeResourceTypeFilter,
}: {
  active?: boolean
  payload?: Array<{
    name?: string
    value?: number
    color?: string
    dataKey?: string | number
  }>
  label?: string
  /** Stacked segment under the pointer (`dataKey`); axis tooltip still lists the full bar. */
  highlightedDataKey?: ResourceKey | null
  /** When set, this resource is emphasized whenever no stack segment is hovered. */
  activeResourceTypeFilter?: string | null
}) {
  const t = useT()
  if (!active || !payload?.length) return null
  const heading =
    typeof label === 'string' || typeof label === 'number'
      ? String(label)
      : ''
  const total = payload.reduce((sum, p) => sum + (Number(p.value) || 0), 0)
  const hi =
    highlightedDataKey != null ? String(highlightedDataKey) : null
  const filterKey =
    activeResourceTypeFilter != null &&
    RESOURCE_SERIES.some((r) => r.key === activeResourceTypeFilter)
      ? activeResourceTypeFilter
      : null

  return (
    <div className="min-w-[280px] max-w-[min(calc(100vw-2rem),22rem)] rounded-lg border border-border bg-popover px-3 py-2.5">
      <p className="mb-2 text-[12px] font-medium text-foreground">{heading}</p>
      <div className="space-y-1.5">
        {payload.map((p) => {
          const rowKey = String(p.dataKey ?? '')
          const isSelected =
            (hi !== null && rowKey === hi) ||
            (hi === null && filterKey != null && rowKey === filterKey)
          return (
            <div
              key={rowKey || String(p.name)}
              className={cn(
                'flex min-h-[28px] items-center justify-between gap-8 rounded-md px-1.5 -mx-1 py-0.5',
                isSelected && 'bg-muted/55',
              )}
            >
              <span
                className={cn(
                  'flex min-w-0 items-center gap-1.5 text-[11px] font-medium leading-5 tabular-nums',
                  isSelected ? 'text-foreground' : 'text-muted-foreground',
                )}
              >
                {typeof p.color === 'string' ? (
                  <ChartSeriesDot color={p.color} />
                ) : null}
                <span className="min-w-0 truncate">
                  {typeof p.name === 'string' ? t(p.name) : p.name}
                </span>
              </span>
              <span
                className={cn(
                  'shrink-0 text-[12px] font-medium tabular-nums leading-5',
                  isSelected ? 'text-foreground' : 'text-muted-foreground',
                )}
              >
                {(p.value ?? 0).toLocaleString()}
              </span>
            </div>
          )
        })}
        <div className="flex items-center justify-between gap-8 border-t border-border pt-1.5">
          <span className="text-[11px] text-muted-foreground">{t('Total')}</span>
          <span className="text-[12px] font-medium text-foreground tabular-nums">
            {total.toLocaleString()}
          </span>
        </div>
      </div>
      <p className="mt-2 text-[10px] text-muted-foreground/50">{t('Mock data')}</p>
    </div>
  )
}

type VolumeChartStackedBarsProps = {
  chartData: ChartRow[]
  colorSlots: number[]
  hoveredIndex: number | null
  hoveredResourceKey: ResourceKey | null
  filteredResourceKey: ResourceKey | null
  lastSeriesIndex: number
  /** Fires on enter and on every move over a segment so highlight cannot stick when `mouseenter` is skipped. */
  onBarSegmentPointer: (key: ResourceKey, dataIndex: number) => void
}

function volumeStackedBarsPropsEqual(
  prev: VolumeChartStackedBarsProps,
  next: VolumeChartStackedBarsProps,
): boolean {
  return (
    prev.hoveredIndex === next.hoveredIndex &&
    prev.hoveredResourceKey === next.hoveredResourceKey &&
    prev.chartData === next.chartData &&
    prev.colorSlots === next.colorSlots &&
    prev.filteredResourceKey === next.filteredResourceKey &&
    prev.lastSeriesIndex === next.lastSeriesIndex &&
    prev.onBarSegmentPointer === next.onBarSegmentPointer
  )
}

const VolumeChartStackedBars = memo(function VolumeChartStackedBars({
  chartData,
  colorSlots,
  hoveredIndex,
  hoveredResourceKey,
  filteredResourceKey,
  lastSeriesIndex,
  onBarSegmentPointer,
}: VolumeChartStackedBarsProps) {
  return (
    <>
      {RESOURCE_SERIES.map(({ key, label }, seriesIndex) => (
        <Bar
          key={key}
          name={label}
          dataKey={key}
          stackId="vol"
          isAnimationActive={false}
          fill={seriesFill(colorSlots[seriesIndex]!)}
          onMouseEnter={(_data, index) => onBarSegmentPointer(key, index)}
          onMouseMove={(_data, index) => onBarSegmentPointer(key, index)}
          radius={
            lastSeriesIndex === 0
              ? [5, 5, 5, 5]
              : seriesIndex === 0
                ? [0, 0, 5, 5]
                : seriesIndex === lastSeriesIndex
                  ? [5, 5, 0, 0]
                  : [0, 0, 0, 0]
          }
        >
          {chartData.map((_, index) => {
            const slot = colorSlots[seriesIndex]!
            const colActive = hoveredIndex === index
            const hasSegmentHover = hoveredResourceKey !== null
            const isHoveredSegment =
              colActive && hasSegmentHover && key === hoveredResourceKey
            const isDimmedSiblingInColumn =
              colActive && hasSegmentHover && key !== hoveredResourceKey
            const isOtherColumn = hoveredIndex !== null && !colActive

            let fill: string
            let cellOpacity = 1

            if (isHoveredSegment) {
              fill = seriesFillEmphasis(slot)
            } else if (isDimmedSiblingInColumn) {
              fill = seriesFill(slot)
              cellOpacity = 0.32
            } else if (colActive) {
              fill = seriesFillHover(slot)
            } else {
              fill = seriesFill(slot)
              if (isOtherColumn) cellOpacity = 0.5
            }

            if (
              filteredResourceKey != null &&
              key !== filteredResourceKey &&
              !isHoveredSegment
            ) {
              cellOpacity *= 0.42
            } else if (
              filteredResourceKey != null &&
              key === filteredResourceKey &&
              !isHoveredSegment &&
              !isDimmedSiblingInColumn
            ) {
              fill = seriesFillHover(slot)
            }

            return (
              <Cell
                key={`${key}-${index}`}
                fill={fill}
                opacity={cellOpacity}
              />
            )
          })}
        </Bar>
      ))}
    </>
  )
}, volumeStackedBarsPropsEqual)

export interface ActivityLogVolumeChartProps {
  rangeFrom: Date
  rangeTo: Date
  className?: string
  /** When set, highlights the matching legend chip (URL `resourceType` equal filter). */
  activeResourceTypeFilter?: string | null
  /** Applies or clears `resourceType` equal filter when a legend chip is clicked. */
  onLegendResourceTypeClick?: (
    resourceKey: ResourceKey,
  ) => void
}

export function ActivityLogVolumeChart({
  rangeFrom,
  rangeTo,
  className,
  activeResourceTypeFilter,
  onLegendResourceTypeClick,
}: ActivityLogVolumeChartProps) {
  const t = useT()
  const plotBottomFadeId = useId().replace(/:/g, '')
  const [open, setOpen] = useState(true)
  const [hoveredIndex, setHoveredIndex] = useState<number | null>(null)
  const [hoveredResourceKey, setHoveredResourceKey] = useState<ResourceKey | null>(
    null,
  )
  const [tooltipPointer, setTooltipPointer] = useState<{
    x: number
    y: number
    w: number
    h: number
  } | null>(null)
  const lastTooltipColumnRef = useRef<number | null>(null)
  const tooltipPointerRafRef = useRef<number | null>(null)
  const pendingTooltipPointerRef = useRef<{
    x: number
    y: number
    w: number
    h: number
  } | null>(null)

  const flushTooltipPointer = useCallback(() => {
    tooltipPointerRafRef.current = null
    const next = pendingTooltipPointerRef.current
    pendingTooltipPointerRef.current = null
    if (!next) return
    setTooltipPointer((prev) =>
      prev != null &&
      prev.x === next.x &&
      prev.y === next.y &&
      prev.w === next.w &&
      prev.h === next.h
        ? prev
        : next,
    )
  }, [])

  const scheduleTooltipPointerUpdate = useCallback(
    (next: { x: number; y: number; w: number; h: number }) => {
      pendingTooltipPointerRef.current = next
      if (tooltipPointerRafRef.current !== null) return
      tooltipPointerRafRef.current = requestAnimationFrame(flushTooltipPointer)
    },
    [flushTooltipPointer],
  )

  useEffect(() => {
    return () => {
      if (tooltipPointerRafRef.current !== null) {
        cancelAnimationFrame(tooltipPointerRafRef.current)
        tooltipPointerRafRef.current = null
      }
    }
  }, [])

  const chartData = useMemo(
    () => buildMockVolumeByResource(rangeFrom, rangeTo),
    [rangeFrom, rangeTo],
  )
  const chartAxisMax = useMemo(
    () =>
      chartData.reduce((max, row) => {
        const total = RESOURCE_SERIES.reduce(
          (sum, { key }) => sum + (Number(row[key]) || 0),
          0,
        )
        return Math.max(max, total)
      }, 0),
    [chartData],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  /** `colorSlots[i]` = shade index for `RESOURCE_SERIES[i]` (randomized order, stable for this range). */
  const colorSlots = useMemo(() => {
    const rng = mulberry32(colorSlotPermutationSeed(rangeFrom, rangeTo))
    return shuffledIndices(RESOURCE_SERIES.length, rng)
  }, [rangeFrom, rangeTo])

  const legendDotLight = useMemo(
    () =>
      new Set(
        RESOURCE_SERIES.map((_, i) => i).filter(
          (i) => effectiveBarAlpha(colorSlots[i]!) < 0.75,
        ),
      ),
    [colorSlots],
  )

  /** URL / filter-driven resource highlight (same keys as `RESOURCE_SERIES`). */
  const filteredResourceKey = useMemo((): ResourceKey | null => {
    if (
      activeResourceTypeFilter == null ||
      !RESOURCE_SERIES.some((r) => r.key === activeResourceTypeFilter)
    ) {
      return null
    }
    return activeResourceTypeFilter as ResourceKey
  }, [activeResourceTypeFilter])

  const chartAnimationKey = useMemo(
    () =>
      `${rangeFrom.toISOString()}-${rangeTo.toISOString()}-${chartData.length}`,
    [rangeFrom, rangeTo, chartData.length],
  )

  const lastSeriesIndex = RESOURCE_SERIES.length - 1

  const handleBarSegmentPointer = useCallback(
    (key: ResourceKey, dataIndex: number) => {
      lastTooltipColumnRef.current = dataIndex
      setHoveredResourceKey(key)
    },
    [],
  )

  return (
    <Collapsible
      open={open}
      onOpenChange={setOpen}
      className={cn(
        'flex w-full flex-col border-b border-border',
        className,
      )}
    >
      <div className="px-4 py-4 sm:px-6">
        <div
          className={cn(
            'flex items-center justify-between gap-3',
            open && 'mb-3',
          )}
        >
          <h3 className="min-w-0 text-[13px] font-medium text-foreground">
            {t('Volume by resource')}
          </h3>
          <CollapsibleTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-8 shrink-0 gap-1.5 px-2 text-[12px] text-muted-foreground hover:text-foreground"
              aria-expanded={open}
              aria-label={open ? t('Hide volume chart') : t('Show volume chart')}
            >
              <ChevronDown
                className={cn(
                  'h-4 w-4 transition-transform duration-200',
                  open && '-rotate-180',
                )}
              />
              {open ? t('Hide') : t('Show')}
            </Button>
          </CollapsibleTrigger>
        </div>

        <CollapsibleContent>
          <div className="flex flex-col">
            {/* Same outer treatment as `RequestsChart`: muted wrapper + fixed chart height */}
            <div className="flex-1 text-muted-foreground">
              {chartData.length === 0 ? (
                <div className="flex h-48 items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 text-[13px] text-muted-foreground">
                  {t('No data for this date range')}
                </div>
              ) : (
                <div
                  key={chartAnimationKey}
                  className={cn(
                    'h-48 w-full overflow-visible animate-in fade-in-0 slide-in-from-bottom-1 duration-500 motion-reduce:animate-none',
                  )}
                >
                  <ResponsiveContainer width="100%" height="100%">
                <BarChart
                  data={chartData}
                  margin={{ top: 0, right: 0, left: 0, bottom: 0 }}
                  barCategoryGap="15%"
                  onMouseMove={(state, e) => {
                    const col = parseActiveBarIndex(state.activeTooltipIndex)
                    // Recharts can briefly report `null` while still over a column - do not touch
                    // `lastTooltipColumnRef` or `hoveredResourceKey` in that case.
                    //
                    // Only clear the stacked segment when moving **between two real columns**.
                    // If we clear when `prevCol` was `null` (first hover into a column), we run
                    // after `Bar` `onMouseEnter` in the same tick and wipe the segment highlight.
                    if (col != null) {
                      const prevCol = lastTooltipColumnRef.current
                      if (prevCol != null && col !== prevCol) {
                        setHoveredResourceKey(null)
                      }
                      lastTooltipColumnRef.current = col
                    }
                    setHoveredIndex((prev) => {
                      if (col == null) return prev
                      return prev === col ? prev : col
                    })
                    scheduleTooltipPointerUpdate(pointerInChartWrapper(e))
                  }}
                  onMouseLeave={() => {
                    if (tooltipPointerRafRef.current !== null) {
                      cancelAnimationFrame(tooltipPointerRafRef.current)
                      tooltipPointerRafRef.current = null
                    }
                    pendingTooltipPointerRef.current = null
                    setHoveredIndex(null)
                    setHoveredResourceKey(null)
                    lastTooltipColumnRef.current = null
                    setTooltipPointer(null)
                  }}
                >
                  <SeriesChartXAxis
                    pointCount={chartData.length}
                    dataKey="label"
                  />
                  <YAxis
                    axisLine={false}
                    tickLine={false}
                    tick={{
                      fill: 'currentColor',
                      fontSize: 10,
                    }}
                    tickFormatter={yAxisTickFormatter}
                    dx={-5}
                    width={USAGE_CHART_Y_AXIS_WIDTH}
                  />
                  <Tooltip
                    content={
                      <VolumeTooltip
                        highlightedDataKey={hoveredResourceKey}
                        activeResourceTypeFilter={filteredResourceKey}
                      />
                    }
                    cursor={false}
                    isAnimationActive={false}
                    allowEscapeViewBox={{ x: true, y: true }}
                    wrapperStyle={{ zIndex: 50, pointerEvents: 'none' }}
                    position={
                      tooltipPointer
                        ? clampVolumeTooltipPosition(
                            tooltipPointer.x,
                            tooltipPointer.y,
                            tooltipPointer.w,
                            tooltipPointer.h,
                          )
                        : undefined
                    }
                  />
                  <VolumeChartStackedBars
                    chartData={chartData}
                    colorSlots={colorSlots}
                    hoveredIndex={hoveredIndex}
                    hoveredResourceKey={hoveredResourceKey}
                    filteredResourceKey={filteredResourceKey}
                    lastSeriesIndex={lastSeriesIndex}
                    onBarSegmentPointer={handleBarSegmentPointer}
                  />
                  <Customized
                    component={(chartProps: {
                      offset?: {
                        top: number
                        left: number
                        width: number
                        height: number
                      }
                    }) => (
                      <ChartPlotBottomFade
                        offset={chartProps.offset}
                        gradientId={plotBottomFadeId}
                      />
                    )}
                  />
                </BarChart>
                </ResponsiveContainer>
                </div>
              )}
            </div>

            {/* Legend - centered chips; clicks drive activity `resourceType` filter when wired */}
            <div className="mt-3 flex flex-wrap items-center justify-center gap-x-4 gap-y-2">
              {RESOURCE_SERIES.map(({ key, label }, i) => {
                const isActive = filteredResourceKey === key
                const interactive = !!onLegendResourceTypeClick
                const content = (
                  <>
                    <div
                      className={cn(
                        'h-2 w-2 shrink-0 rounded-full',
                        legendDotLight.has(i) && 'ring-1 ring-border',
                      )}
                      style={{ backgroundColor: seriesFill(colorSlots[i]!) }}
                    />
                    <span
                      className={cn(
                        'text-[11px]',
                        isActive
                          ? 'font-medium text-foreground'
                          : 'text-muted-foreground',
                      )}
                    >
                      {t(label)}
                    </span>
                  </>
                )
                if (!interactive) {
                  return (
                    <div
                      key={key}
                      className="flex items-center gap-1.5 opacity-80"
                    >
                      {content}
                    </div>
                  )
                }
                return (
                  <button
                    key={key}
                    type="button"
                    aria-pressed={isActive}
                    aria-label={`${t('Filter activities by')} ${t(label)}`}
                    onClick={() => onLegendResourceTypeClick?.(key)}
                    className={cn(
                      'flex cursor-pointer items-center gap-1.5 rounded-md px-2 py-1 transition-colors',
                      'hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                      isActive && 'bg-muted/50 ring-1 ring-border',
                    )}
                  >
                    {content}
                  </button>
                )
              })}
            </div>
          </div>
        </CollapsibleContent>
      </div>
    </Collapsible>
  )
}
