import { useCallback, useEffect, useRef, useState } from 'react'
import type { DateRange } from 'react-day-picker'
import type { UsageChartInterval } from '@/lib/usage/chart-interval'
import {
  resolveUsageChartBrushDateRange,
  type UsageChartBrushPoint,
} from '@/lib/usage/chart-brush'
import { cn } from '@/lib/utils'

type ChartMouseEvent = {
  activeTooltipIndex?: number | string | null
  activeIndex?: number | string | null
}

type UseUsageChartBrushSelectOptions = {
  points: readonly UsageChartBrushPoint[]
  chartInterval: UsageChartInterval
  onDateRangeChange?: (dateRange: DateRange | undefined) => void
  enabled?: boolean
}

/** Shared surface styles: horizontal range cursor + no Recharts click focus ring. */
export const USAGE_CHART_BRUSH_SURFACE_CLASS =
  'h-full w-full outline-none [&_.recharts-wrapper]:outline-none [&_.recharts-wrapper_*]:outline-none [&_svg]:outline-none [&_svg:focus]:outline-none [&_.recharts-surface:focus]:outline-none'

/**
 * Drag-to-select a date range on index-based usage AreaCharts (Recharts).
 */
export function useUsageChartBrushSelect({
  points,
  chartInterval,
  onDateRangeChange,
  enabled = true,
}: UseUsageChartBrushSelectOptions) {
  const [anchorIndex, setAnchorIndex] = useState<number | null>(null)
  const [focusIndex, setFocusIndex] = useState<number | null>(null)
  const selectingRef = useRef(false)
  const anchorRef = useRef<number | null>(null)
  const focusRef = useRef<number | null>(null)
  const pointsRef = useRef(points)
  const intervalRef = useRef(chartInterval)
  const onDateRangeChangeRef = useRef(onDateRangeChange)

  pointsRef.current = points
  intervalRef.current = chartInterval
  onDateRangeChangeRef.current = onDateRangeChange

  const isSelecting = anchorIndex !== null
  const canSelect =
    enabled && typeof onDateRangeChange === 'function' && points.length > 1

  const resetSelection = useCallback(() => {
    selectingRef.current = false
    anchorRef.current = null
    focusRef.current = null
    setAnchorIndex(null)
    setFocusIndex(null)
  }, [])

  const commitSelection = useCallback(() => {
    const left = anchorRef.current
    const right = focusRef.current
    resetSelection()

    if (left == null || right == null) return

    const nextRange = resolveUsageChartBrushDateRange(
      pointsRef.current,
      left,
      right,
      intervalRef.current,
    )
    if (!nextRange) return
    onDateRangeChangeRef.current?.(nextRange)
  }, [resetSelection])

  useEffect(() => {
    if (!isSelecting) return

    const handleWindowMouseUp = () => {
      if (!selectingRef.current) return
      commitSelection()
    }

    window.addEventListener('mouseup', handleWindowMouseUp)
    return () => window.removeEventListener('mouseup', handleWindowMouseUp)
  }, [commitSelection, isSelecting])

  const readIndex = (state: ChartMouseEvent | null | undefined) => {
    const raw = state?.activeTooltipIndex ?? state?.activeIndex
    if (typeof raw === 'number' && Number.isFinite(raw)) return raw
    if (typeof raw === 'string' && raw !== '') {
      const parsed = Number(raw)
      return Number.isFinite(parsed) ? parsed : null
    }
    return null
  }

  const onMouseDown = useCallback(
    (state: ChartMouseEvent) => {
      if (!canSelect) return
      const index = readIndex(state)
      if (index == null) return
      selectingRef.current = true
      anchorRef.current = index
      focusRef.current = index
      setAnchorIndex(index)
      setFocusIndex(index)
    },
    [canSelect],
  )

  const onMouseMove = useCallback(
    (state: ChartMouseEvent) => {
      if (!canSelect || !selectingRef.current) return
      const index = readIndex(state)
      if (index == null) return
      focusRef.current = index
      setFocusIndex(index)
    },
    [canSelect],
  )

  const onMouseUp = useCallback(() => {
    if (!canSelect || !selectingRef.current) return
    commitSelection()
  }, [canSelect, commitSelection])

  const brushLeft =
    anchorIndex != null && focusIndex != null
      ? Math.min(anchorIndex, focusIndex)
      : null
  const brushRight =
    anchorIndex != null && focusIndex != null
      ? Math.max(anchorIndex, focusIndex)
      : null

  return {
    canSelect,
    isSelecting,
    brushLeft,
    brushRight,
    surfaceClassName: cn(
      USAGE_CHART_BRUSH_SURFACE_CLASS,
      canSelect && 'cursor-col-resize select-none',
    ),
    chartProps: {
      // Avoid Recharts accessibility focus ring on click (interferes with brush).
      accessibilityLayer: false as const,
      ...(canSelect
        ? {
            onMouseDown,
            onMouseMove,
            onMouseUp,
          }
        : {}),
    },
  }
}
