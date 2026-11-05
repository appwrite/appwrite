'use client'

import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { Progress } from '@/components/ui/progress'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { formatDecimalBytes } from '@/lib/utils/byte-display-unit'
import {
  shouldShowBillingStorageBreakdown,
  type BillingStorageBreakdownSegment,
} from '@/lib/billing/billing-storage-breakdown'

type BillingStorageUsageProgressProps = {
  totalUsageBytes: number
  usagePercentage: number | null
  segments: BillingStorageBreakdownSegment[] | undefined
  highlightWhenHigh?: boolean
}

export function BillingStorageUsageProgress({
  totalUsageBytes,
  usagePercentage,
  segments,
  highlightWhenHigh = false,
}: BillingStorageUsageProgressProps) {
  const t = useT()

  const showBreakdown = shouldShowBillingStorageBreakdown(
    segments,
    totalUsageBytes,
  )

  if (!showBreakdown || usagePercentage === null) {
    if (usagePercentage === null) {
      return <div className="h-2" />
    }
    return (
      <TooltipProvider>
        <Tooltip>
          <TooltipTrigger asChild>
            <div>
              <Progress
                value={usagePercentage}
                className={cn(
                  'h-2 cursor-pointer',
                  highlightWhenHigh &&
                    usagePercentage >= 80 &&
                    '[&>div]:bg-blue-500',
                )}
              />
            </div>
          </TooltipTrigger>
          <TooltipContent>
            <p className="text-[12px]">
              {usagePercentage.toFixed(1)}% {t('used')}
            </p>
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>
    )
  }

  const activeSegments = segments!.filter((segment) => segment.bytes > 0)

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <div
            className={cn(
              'bg-primary/20 relative h-2 w-full cursor-pointer overflow-hidden rounded-full',
              highlightWhenHigh &&
                usagePercentage >= 80 &&
                'ring-1 ring-blue-500/30',
            )}
            role="img"
            aria-label={activeSegments
              .map((segment) => {
                const share =
                  totalUsageBytes > 0
                    ? (segment.bytes / totalUsageBytes) * 100
                    : 0
                return `${t(segment.label)} ${share.toFixed(1)}%`
              })
              .join(', ')}
          >
            <div
              className="flex h-full min-w-0"
              style={{ width: `${usagePercentage}%` }}
            >
              {activeSegments.map((segment) => {
                const widthPercent =
                  totalUsageBytes > 0
                    ? (segment.bytes / totalUsageBytes) * 100
                    : 0
                return (
                  <div
                    key={segment.id}
                    className="h-full min-w-0 first:rounded-s-full last:rounded-e-full"
                    style={{
                      width: `${widthPercent}%`,
                      backgroundColor: segment.color,
                    }}
                  />
                )
              })}
            </div>
          </div>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-[260px] py-2">
          <p className="text-[12px] font-medium mb-1.5">
            {usagePercentage.toFixed(1)}% {t('used')}
          </p>
          <ul className="space-y-1.5">
            {activeSegments.map((segment) => {
              const share =
                totalUsageBytes > 0
                  ? (segment.bytes / totalUsageBytes) * 100
                  : 0
              return (
                <li
                  key={segment.id}
                  className="flex items-center gap-2 text-[12px] leading-snug"
                >
                  <span
                    className="size-2 shrink-0 rounded-full ring-1 ring-background/20"
                    style={{ backgroundColor: segment.color }}
                    aria-hidden
                  />
                  <span className="min-w-0 shrink font-medium">
                    {t(segment.label)}
                  </span>
                  <span className="ms-auto shrink-0 tabular-nums text-background/85">
                    {formatDecimalBytes(segment.bytes)} ({share.toFixed(1)}%)
                  </span>
                </li>
              )
            })}
          </ul>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
