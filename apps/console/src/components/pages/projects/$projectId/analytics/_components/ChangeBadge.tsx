import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'
import { USAGE_CHART_FADE_IN_CLASS_NAME } from '@/lib/usage/usage-chart-loading'

/**
 * "+12.3%" style change vs a comparison period. Green when the change is good,
 * amber when bad. Its own module so the properties list can use it without
 * pulling in the chart code.
 */
export function ChangeBadge({
  change,
  invert = false,
  suffix,
}: {
  change: number | undefined
  /** Lower is better (e.g. bounce rate): flip the colouring. */
  invert?: boolean
  suffix?: ReactNode
}) {
  if (change === undefined) return null
  const isGood = invert ? change < 0 : change > 0
  const isBad = invert ? change > 0 : change < 0
  return (
    <span
      className={cn(
        'text-[12px] font-medium tabular-nums',
        USAGE_CHART_FADE_IN_CLASS_NAME,
        isGood && 'text-emerald-600 dark:text-emerald-400',
        isBad && 'text-amber-600 dark:text-amber-400',
        !isGood && !isBad && 'text-muted-foreground',
      )}
    >
      {change > 0 ? '+' : ''}
      {change}%{suffix}
    </span>
  )
}
