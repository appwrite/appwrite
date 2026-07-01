import { TrendingDown, TrendingUp } from 'lucide-react'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { GbHoursUnitInfo } from './GbHoursUnitInfo'
import { MetricValueWithUnit } from './MetricValueWithUnit'
import { OVERVIEW_METRIC_NOT_AVAILABLE } from './chart-panel'

const OVERVIEW_TAB_METRIC_ROW_CLASS =
  'flex min-h-7 items-baseline gap-2 sm:min-h-[28px]'

const OVERVIEW_TAB_METRIC_VALUE_CLASS =
  'text-[18px] font-semibold tracking-tight sm:text-[20px]'

const OVERVIEW_TAB_CHANGE_GROUP_CLASS =
  'flex min-h-3 min-w-[3.25rem] shrink-0 items-center gap-1'

/** Reserve the same width as split amount + unit values (e.g. 67.4MB, 1.2M, 6.2GBH). */
const OVERVIEW_TAB_METRIC_VALUE_SKELETON_CLASS =
  'inline-flex items-baseline gap-1'

const TAB_METRIC_AMOUNT_SKELETON_WIDTH: Record<string, string> = {
  bandwidth: 'w-[3.25rem]',
  storage: 'w-[3.25rem]',
  gbhours: 'w-[2.75rem]',
  requests: 'w-[2.5rem]',
  executions: 'w-[2.5rem]',
}

const TAB_METRIC_UNIT_SKELETON_WIDTH: Record<string, string> = {
  bandwidth: 'w-8',
  storage: 'w-8',
  gbhours: 'w-9',
  requests: 'w-4',
  executions: 'w-4',
}

interface OverviewTabMetricContentProps {
  tabId: string
  isLoading?: boolean
  value: string
  change: number | null
  isActive: boolean
}

export function OverviewTabMetricContent({
  tabId,
  isLoading = false,
  value,
  change,
  isActive,
}: OverviewTabMetricContentProps) {
  if (isLoading) {
    const amountWidth =
      TAB_METRIC_AMOUNT_SKELETON_WIDTH[tabId] ?? 'w-[3rem]'
    const unitWidth = TAB_METRIC_UNIT_SKELETON_WIDTH[tabId] ?? 'w-7'

    return (
      <div className={OVERVIEW_TAB_METRIC_ROW_CLASS} aria-hidden>
        <span
          className={cn(
            OVERVIEW_TAB_METRIC_VALUE_SKELETON_CLASS,
            OVERVIEW_TAB_METRIC_VALUE_CLASS,
          )}
        >
          <Skeleton
            className={cn('h-[1em] shrink-0 rounded-md', amountWidth)}
          />
          <Skeleton
            className={cn('h-[0.62em] shrink-0 rounded-md', unitWidth)}
          />
        </span>
        {tabId === 'gbhours' ? (
          <Skeleton className="h-3 w-3 shrink-0 self-center rounded-full" />
        ) : null}
        <div className={OVERVIEW_TAB_CHANGE_GROUP_CLASS}>
          <Skeleton className="h-3 w-3 shrink-0 rounded-sm" />
          <Skeleton className="h-[11px] w-10 shrink-0 rounded-md" />
        </div>
      </div>
    )
  }

  const showChange = change !== null
  const isPositive = showChange && change > 0
  const isNegative = showChange && change < 0

  return (
    <div className={OVERVIEW_TAB_METRIC_ROW_CLASS}>
      <MetricValueWithUnit
        value={value}
        className={cn(
          OVERVIEW_TAB_METRIC_VALUE_CLASS,
          isActive ? 'text-foreground' : 'text-muted-foreground',
        )}
        amountClassName="tabular-nums"
        unitClassName={
          isActive ? 'text-muted-foreground' : 'text-muted-foreground/70'
        }
      />
      {tabId === 'gbhours' ? (
        <GbHoursUnitInfo className="self-center" nested />
      ) : null}
      <div className={OVERVIEW_TAB_CHANGE_GROUP_CLASS}>
        {showChange ? (
          <>
            {isPositive ? (
              <TrendingUp
                className={cn(
                  'h-3 w-3 shrink-0',
                  isActive ? 'text-emerald-500' : 'text-emerald-500/60',
                )}
              />
            ) : null}
            {isNegative ? (
              <TrendingDown
                className={cn(
                  'h-3 w-3 shrink-0',
                  isActive ? 'text-red-500' : 'text-red-500/60',
                )}
              />
            ) : null}
            <span
              className={cn(
                'text-[11px] font-medium tabular-nums',
                isPositive &&
                  (isActive ? 'text-emerald-500' : 'text-emerald-500/60'),
                isNegative &&
                  (isActive ? 'text-red-500' : 'text-red-500/60'),
                !isPositive &&
                  !isNegative &&
                  'text-muted-foreground',
              )}
            >
              {isPositive && '+'}
              {change}%
            </span>
          </>
        ) : (
          <span
            className={cn(
              'text-[11px] font-medium',
              isActive ? 'text-muted-foreground' : 'text-muted-foreground/70',
            )}
          >
            {OVERVIEW_METRIC_NOT_AVAILABLE}
          </span>
        )}
      </div>
    </div>
  )
}
