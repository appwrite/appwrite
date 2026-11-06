import { Skeleton } from '@/components/ui/skeleton'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT,
  overviewChartPanelBodyClass,
  overviewChartPanelChartAreaClass,
  overviewTopBreakdownListClass,
  overviewTopBreakdownRowClass,
} from './chart-panel'

interface OverviewChartPanelSkeletonProps {
  variant?: 'list' | 'storage'
  className?: string
  /** When true, omit the panel body wrapper (parent already provides layout). */
  embedded?: boolean
}

export function OverviewChartPanelSkeleton({
  variant = 'list',
  className,
  embedded = false,
}: OverviewChartPanelSkeletonProps) {
  const t = useT()
  const content =
    variant === 'storage' ? <StorageSkeleton /> : <ListSkeleton />

  if (embedded) {
    return (
      <div
        className={className}
        aria-busy="true"
        aria-label={t('Loading usage data')}
      >
        {content}
      </div>
    )
  }

  return (
    <div
      className={cn(overviewChartPanelBodyClass, className)}
      aria-busy="true"
      aria-label={t('Loading usage data')}
    >
      {content}
    </div>
  )
}

function StorageSkeleton() {
  return (
    <div
      className={cn(
        overviewChartPanelChartAreaClass,
        'justify-center gap-2',
      )}
    >
      <Skeleton className="h-4 w-56 max-w-full" />
      <Skeleton className="h-9 w-32 max-w-[60%]" />
    </div>
  )
}

function ListSkeleton() {
  return (
    <div className={overviewTopBreakdownListClass}>
      {Array.from({ length: OVERVIEW_TOP_BREAKDOWN_ITEM_COUNT }).map((_, index) => (
        <div key={index} className={overviewTopBreakdownRowClass}>
          <Skeleton className="h-3 min-w-0 flex-1" />
          <Skeleton className="h-3 w-12 shrink-0" />
        </div>
      ))}
    </div>
  )
}
