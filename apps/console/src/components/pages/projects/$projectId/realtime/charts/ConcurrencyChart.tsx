import { useMemo, useEffect, useRef } from 'react'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  YAxis,
  CartesianGrid,
} from 'recharts'
import { DateRange } from 'react-day-picker'
import { formatLocalizedDate } from '@/lib/i18n/date-format'
import { Info } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  createCompactCountAxisTickFormatter,
} from '@/lib/usage/format-metric'
import { CHART_ANIMATION_DISABLED } from '@/lib/usage/chart-animation'
import { USAGE_CHART_Y_AXIS_WIDTH } from '../../overview/chart-panel'
import { USAGE_CHART_MARGIN } from '@/lib/usage/chart-layout'
import { SeriesChartXAxis } from '@/components/global/shared/ChartXAxis'
import { useT } from '@/lib/i18n/translate'

interface ConcurrencyDataPoint {
  timestamp: string
  connections: number
}

interface RealtimeConcurrencyChartProps {
  data: ConcurrencyDataPoint[]
  dateRange: DateRange | undefined
  projectId?: string | null
  description?: string
}

const CustomTooltip = ({
  active,
  payload,
}: {
  active?: boolean
  payload?: Array<{ value: number; payload: ConcurrencyDataPoint }>
}) => {
  const t = useT()
  if (active && payload && payload.length) {
    const data = payload[0].payload
    return (
      <div className="rounded-md border border-border bg-popover px-3 py-2">
        <p className="text-[11px] text-muted-foreground mb-1">
          {formatLocalizedDate(new Date(data.timestamp), 'MMM d, yyyy HH:mm')}
        </p>
        <p className="text-[13px] font-medium text-foreground">
          {data.connections.toLocaleString()}{' '}
          <span className="text-muted-foreground font-normal">{t('connections')}</span>
        </p>
      </div>
    )
  }
  return null
}

export function RealtimeConcurrencyChart({
  data,
  projectId,
  description = 'Real-time connection count over time',
}: RealtimeConcurrencyChartProps) {
  const t = useT()
  const cardRef = useRef<HTMLDivElement>(null)

  const chartData = useMemo(() => {
    return data.map((point) => ({
      ...point,
      date: formatLocalizedDate(new Date(point.timestamp), 'MMM d'),
      fullDate: formatLocalizedDate(new Date(point.timestamp), 'MMM d, yyyy'),
    }))
  }, [data])

  const chartAxisMax = useMemo(
    () => chartData.reduce((max, point) => Math.max(max, point.connections), 0),
    [chartData],
  )
  const yAxisTickFormatter = useMemo(
    () => createCompactCountAxisTickFormatter(chartAxisMax),
    [chartAxisMax],
  )

  const currentValue = data.length > 0 ? data[data.length - 1].connections : 0

  // Allow scroll events to pass through to parent
  useEffect(() => {
    const card = cardRef.current
    if (!card) return

    const handleWheel = (e: WheelEvent) => {
      // Only handle if the event target is within the card
      if (!card.contains(e.target as Node)) {
        return
      }

      // Find the scrollable parent (start from parent, not card itself)
      let scrollableParent: HTMLElement | null = null
      let element: HTMLElement | null = card.parentElement
      while (element) {
        const style = window.getComputedStyle(element)
        if (style.overflowY === 'auto' || style.overflowY === 'scroll') {
          scrollableParent = element
          break
        }
        element = element.parentElement
      }

      if (scrollableParent) {
        // Check if we can actually scroll in this direction
        const canScrollUp = scrollableParent.scrollTop > 0
        const canScrollDown =
          scrollableParent.scrollTop <
          scrollableParent.scrollHeight - scrollableParent.clientHeight

        if ((e.deltaY > 0 && canScrollDown) || (e.deltaY < 0 && canScrollUp)) {
          scrollableParent.scrollTop += e.deltaY
          e.preventDefault()
          e.stopPropagation()
        }
        // If we can't scroll in this direction, let the event bubble naturally
      }
    }

    // Add listener without capture - only on the card itself
    const options = { passive: false }
    card.addEventListener('wheel', handleWheel, options)

    // Also listen on SVG elements inside (recharts renders SVG)
    const addSvgListeners = () => {
      const svgElements = card.querySelectorAll('svg')
      svgElements.forEach((svg) => {
        svg.addEventListener('wheel', handleWheel, options)
      })
    }

    // Wait for SVG to render
    const timeoutId = setTimeout(addSvgListeners, 100)

    return () => {
      card.removeEventListener('wheel', handleWheel, options)
      clearTimeout(timeoutId)
      const svgElements = card.querySelectorAll('svg')
      svgElements.forEach((svg) => {
        svg.removeEventListener('wheel', handleWheel, options)
      })
    }
  }, [])

  if (chartData.length === 0) {
    return (
      <div className="rounded-lg border border-border bg-card overflow-hidden">
        <div className="flex h-64 items-center justify-center text-center p-4">
          <div>
            <p className="text-[13px] text-muted-foreground">
              {t('No data available')}
            </p>
            <p className="mt-1 text-[12px] text-muted-foreground/70">
              {t('Select a date range to view connection data')}
            </p>
          </div>
        </div>
      </div>
    )
  }

  return (
    <div
      ref={cardRef}
      className="rounded-lg border border-border bg-card overflow-hidden"
    >
      {/* Header */}
      <div className="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2">
            <h3 className="text-[14px] font-medium text-foreground">
              {t('Concurrent Connections')}
            </h3>
            <TooltipProvider delayDuration={0}>
              <UITooltip>
                <TooltipTrigger asChild>
                  <button className="text-muted-foreground hover:text-foreground transition-colors">
                    <Info className="h-3.5 w-3.5" />
                  </button>
                </TooltipTrigger>
                <TooltipContent
                  side="top"
                  className="max-w-xs text-[12px] leading-relaxed"
                >
                  <p>{t(description)}</p>
                </TooltipContent>
              </UITooltip>
            </TooltipProvider>
          </div>

          {/* Current value */}
          <div className="mt-2 flex items-baseline gap-2">
            <span className="text-[24px] font-semibold text-foreground tabular-nums">
              {currentValue.toLocaleString()}
            </span>
            <span className="text-[13px] text-muted-foreground">
              {t('connections')}
            </span>
          </div>
        </div>
        {projectId && (
          <Link
            to="/projects/$projectId/realtime/channels"
            params={{ projectId }}
          >
            <Button
              variant="ghost"
              size="sm"
              className="h-7 gap-1.5 text-[11px] text-muted-foreground hover:text-foreground"
            >
              {t('View channels')}
            </Button>
          </Link>
        )}
      </div>

      {/* Chart */}
      <div className="p-4">
        <div className="h-[180px] text-muted-foreground">
          <ResponsiveContainer
            width="100%"
            height="100%"
            minWidth={0}
            minHeight={0}
          >
            <AreaChart
              data={chartData}
              margin={USAGE_CHART_MARGIN}
            >
              <defs>
                <linearGradient
                  id="connectionsGradient"
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
              <SeriesChartXAxis pointCount={chartData.length} />
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
              <Tooltip content={<CustomTooltip />} />
              <Area
                type="monotone"
                dataKey="connections"
                stroke="var(--chart-brand)"
                strokeWidth={2}
                fill="url(#connectionsGradient)"
                name="Connections"
                {...CHART_ANIMATION_DISABLED}
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Description */}
      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {t(description)}
        </p>
      </div>
    </div>
  )
}
