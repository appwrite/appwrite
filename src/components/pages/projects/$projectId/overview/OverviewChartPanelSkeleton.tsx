import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import {
  OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT,
  overviewChartPanelBodyClass,
  overviewTopBreakdownListClass,
  overviewTopBreakdownRowClass,
} from './chart-panel'

const CHART_BAR_HEIGHTS = [38, 52, 44, 68, 58, 72, 48, 64, 56, 70, 42, 60]

interface OverviewChartPanelSkeletonProps {
  variant?: 'chart' | 'list'
  className?: string
  /** When true, omit the panel body wrapper (parent already provides layout). */
  embedded?: boolean
}

export function OverviewChartPanelSkeleton({
  variant = 'chart',
  className,
  embedded = false,
}: OverviewChartPanelSkeletonProps) {
  const content = variant === 'chart' ? <ChartSkeleton /> : <ListSkeleton />

  if (embedded) {
    return (
      <div className={className} aria-busy="true" aria-label="Loading usage data">
        {content}
      </div>
    )
  }

  return (
    <div
      className={cn(overviewChartPanelBodyClass, className)}
      aria-busy="true"
      aria-label="Loading usage data"
    >
      {content}
    </div>
  )
}

function ChartSkeleton() {
  return (
    <div className="flex h-full min-h-[240px] flex-1 flex-col justify-end rounded-lg border border-border/60 bg-muted/10 px-4 pb-5 pt-4">
      <div className="flex min-h-0 flex-1 items-end gap-1.5">
        {CHART_BAR_HEIGHTS.map((height, index) => (
          <Skeleton
            key={index}
            className="flex-1 rounded-sm"
            style={{ height: `${height}%` }}
          />
        ))}
      </div>
      <div className="mt-4 flex justify-between">
        <Skeleton className="h-3 w-10" />
        <Skeleton className="h-3 w-10" />
        <Skeleton className="h-3 w-10" />
      </div>
    </div>
  )
}

function ListSkeleton() {
  return (
    <div className={overviewTopBreakdownListClass}>
      {Array.from({ length: OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT }).map((_, index) => (
        <div key={index} className={overviewTopBreakdownRowClass}>
          <Skeleton className="h-3 w-9 shrink-0" />
          <Skeleton className="h-3 w-9 shrink-0" />
          <Skeleton className="h-3 min-w-0 flex-1" />
          <Skeleton className="h-3 w-12 shrink-0" />
        </div>
      ))}
    </div>
  )
}
