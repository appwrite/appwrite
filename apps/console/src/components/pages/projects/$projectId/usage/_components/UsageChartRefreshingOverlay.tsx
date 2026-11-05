import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import {
  USAGE_CHART_FADE_IN_CLASS_NAME,
  usageChartRefreshingClassName,
} from '@/lib/usage/usage-chart-loading'

type UsageChartRefreshingOverlayProps = {
  isRefreshing: boolean
  className?: string
  children: ReactNode
}

/** Fades chart content in on mount and dims it while data refetches. */
export function UsageChartRefreshingOverlay({
  isRefreshing,
  className,
  children,
}: UsageChartRefreshingOverlayProps) {
  return (
    <div
      className={cn(
        'relative h-full w-full',
        USAGE_CHART_FADE_IN_CLASS_NAME,
        usageChartRefreshingClassName(isRefreshing),
        className,
      )}
      aria-busy={isRefreshing || undefined}
    >
      {children}
      {isRefreshing ? (
        <div
          className="pointer-events-none absolute inset-x-0 top-0 z-10 h-px bg-gradient-to-r from-transparent via-primary/35 to-transparent"
          aria-hidden
        />
      ) : null}
    </div>
  )
}
