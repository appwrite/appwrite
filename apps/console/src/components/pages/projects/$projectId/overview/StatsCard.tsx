import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { TrendingUp, TrendingDown } from 'lucide-react'

interface StatsCardProps {
  title: string
  value: string | number
  change?: number
  icon?: React.ReactNode
  className?: string
}

export function StatsCard({
  title,
  value,
  change,
  icon,
  className,
}: StatsCardProps) {
  const t = useT()
  const isPositive = change && change > 0
  const isNegative = change && change < 0

  return (
    <div
      className={cn(
        'group rounded-lg border border-border bg-card p-2.5 transition-colors duration-150 hover:bg-accent/50 sm:p-3',
        className,
      )}
    >
      <div className="flex items-start justify-between">
        <div className="min-w-0 flex-1 space-y-0.5 sm:space-y-1">
          <p className="truncate text-[11px] font-medium text-muted-foreground sm:text-[12px]">
            {title}
          </p>
          <div className="flex items-baseline gap-2 flex-wrap">
            <p className="truncate text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
              {value}
            </p>
            {change !== undefined && (
              <div className="flex items-center gap-1">
                {isPositive && (
                  <TrendingUp className="h-3 w-3 shrink-0 text-emerald-500 dark:text-emerald-400" />
                )}
                {isNegative && (
                  <TrendingDown className="h-3 w-3 shrink-0 text-red-500 dark:text-red-400" />
                )}
                <span
                  className={cn(
                    'text-[10px] font-medium sm:text-[11px]',
                    isPositive && 'text-emerald-500 dark:text-emerald-400',
                    isNegative && 'text-red-500 dark:text-red-400',
                    !isPositive && !isNegative && 'text-muted-foreground',
                  )}
                >
                  {isPositive && '+'}
                  {typeof change === 'number' ? change.toFixed(2) : change}%
                </span>
                <span className="text-[10px] text-muted-foreground/70 sm:text-[11px]">
                  {t('vs last week')}
                </span>
              </div>
            )}
          </div>
        </div>
        {icon && (
          <div className="ms-2 shrink-0 rounded-md bg-muted p-1.5 text-muted-foreground transition-colors group-hover:bg-accent group-hover:text-foreground sm:p-2">
            {icon}
          </div>
        )}
      </div>
    </div>
  )
}
