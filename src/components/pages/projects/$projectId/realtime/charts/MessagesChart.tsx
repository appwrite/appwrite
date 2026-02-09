import { useMemo, useEffect, useRef } from 'react'
import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  CartesianGrid,
} from 'recharts'
import { DateRange } from 'react-day-picker'
import { format } from 'date-fns'
import { Info } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import {
  Tooltip as UITooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

interface MessagesDataPoint {
  timestamp: string
  messagesPerMinute: number
}

interface RealtimeMessagesChartProps {
  data: MessagesDataPoint[]
  dateRange: DateRange | undefined
  projectId?: string | null
  description?: string
}

const CustomTooltip = ({
  active,
  payload,
}: {
  active?: boolean
  payload?: Array<{ value: number; payload: MessagesDataPoint }>
}) => {
  if (active && payload && payload.length) {
    const data = payload[0].payload
    return (
      <div className="rounded-md border border-border bg-popover px-3 py-2">
        <p className="text-[11px] text-muted-foreground mb-1">
          {format(new Date(data.timestamp), 'MMM d, yyyy HH:mm')}
        </p>
        <p className="text-[13px] font-medium text-foreground">
          {data.messagesPerMinute.toLocaleString()}{' '}
          <span className="text-muted-foreground font-normal">
            messages/min
          </span>
        </p>
      </div>
    )
  }
  return null
}

export function RealtimeMessagesChart({
  data,
  projectId,
  description = 'Messages per minute over time',
}: RealtimeMessagesChartProps) {
  const cardRef = useRef<HTMLDivElement>(null)

  const chartData = useMemo(() => {
    return data.map((point) => ({
      ...point,
      date: format(new Date(point.timestamp), 'MMM d'),
      fullDate: format(new Date(point.timestamp), 'MMM d, yyyy'),
    }))
  }, [data])

  const currentValue =
    data.length > 0 ? data[data.length - 1].messagesPerMinute : 0

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
              No data available
            </p>
            <p className="mt-1 text-[12px] text-muted-foreground/70">
              Select a date range to view messages data
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
              Messages Throughput
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
                  <p>{description}</p>
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
              messages/min
            </span>
          </div>
        </div>
        {projectId && (
          <Link
            to="/projects/$projectId/realtime/messages"
            params={{ projectId }}
          >
            <Button
              variant="ghost"
              size="sm"
              className="h-7 gap-1.5 text-[11px] text-muted-foreground hover:text-foreground"
            >
              View messages
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
              margin={{ top: 10, right: 10, left: -20, bottom: 0 }}
            >
              <defs>
                <linearGradient
                  id="messagesGradient"
                  x1="0"
                  y1="0"
                  x2="0"
                  y2="1"
                >
                  <stop offset="0%" stopColor="#10b981" stopOpacity={0.2} />
                  <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid
                strokeDasharray="3 3"
                stroke="hsl(var(--border))"
                vertical={false}
              />
              <XAxis
                dataKey="date"
                axisLine={false}
                tickLine={false}
                tick={{
                  fill: 'currentColor',
                  fontSize: 10,
                }}
                dy={10}
                interval="preserveStartEnd"
              />
              <YAxis
                axisLine={false}
                tickLine={false}
                tick={{
                  fill: 'currentColor',
                  fontSize: 10,
                }}
                tickFormatter={(value) => {
                  if (value >= 1_000_000)
                    return `${(value / 1_000_000).toFixed(0)}M`
                  if (value >= 1_000) return `${(value / 1_000).toFixed(0)}K`
                  return value.toString()
                }}
                dx={-5}
                width={45}
              />
              <Tooltip content={<CustomTooltip />} />
              <Area
                type="monotone"
                dataKey="messagesPerMinute"
                stroke="#10b981"
                strokeWidth={2}
                fill="url(#messagesGradient)"
                name="Messages/min"
              />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </div>

      {/* Description */}
      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
    </div>
  )
}
