import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { usageChartRefreshingClassName } from '@/lib/usage/usage-chart-loading'

type UsageChartRefreshingOverlayProps = {
  isRefreshing: boolean
  className?: string
  children: ReactNode
}

/** Wraps chart content with a soft opacity fade while data refetches. */
export function UsageChartRefreshingOverlay({
  isRefreshing,
  className,
  children,
}: UsageChartRefreshingOverlayProps) {
  return (
    <div
      className={cn(
        'relative h-full w-full',
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
