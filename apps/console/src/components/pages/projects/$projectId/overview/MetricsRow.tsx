import { cn } from '@/lib/utils'
import { TrendingUp, TrendingDown } from 'lucide-react'

interface Metric {
  value: string
  label: string
  change: number
}

interface MetricsRowProps {
  metrics: Metric[]
  className?: string
}

export function MetricsRow({ metrics, className }: MetricsRowProps) {
  return (
    <div
      className={cn(
        'flex flex-wrap items-start gap-6 sm:gap-8 lg:gap-10',
        className,
      )}
    >
      {metrics.map((metric, index) => {
        const isPositive = metric.change > 0
        const isNegative = metric.change < 0

        return (
          <div key={index} className="flex flex-col gap-0.5">
            <div className="flex items-baseline gap-2">
              <span className="text-[18px] font-semibold tracking-tight text-foreground sm:text-[20px]">
                {metric.value}
              </span>
              <div className="flex items-center gap-1">
                {isPositive && (
                  <TrendingUp className="h-3 w-3 text-emerald-500 dark:text-emerald-400" />
                )}
                {isNegative && (
                  <TrendingDown className="h-3 w-3 text-red-500 dark:text-red-400" />
                )}
                <span
                  className={cn(
                    'text-[11px] font-medium',
                    isPositive && 'text-emerald-500 dark:text-emerald-400',
                    isNegative && 'text-red-500 dark:text-red-400',
                    !isPositive && !isNegative && 'text-muted-foreground',
                  )}
                >
                  {isPositive && '+'}
                  {metric.change}%
                </span>
              </div>
            </div>
            <span className="text-[12px] text-muted-foreground">
              {metric.label}
            </span>
          </div>
        )
      })}
    </div>
  )
}
