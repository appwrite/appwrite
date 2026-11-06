import { Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Progress } from '@/components/ui/progress'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useT } from '@/lib/i18n/translate'

type MysqlMetricKpiCardProps = {
  label: string
  value: string
  subValue?: string
  description: string
  progress?: number | null
  progressTone?: 'normal' | 'warning' | 'critical'
  /** Override the default "{value}% of limit" caption below the progress bar. */
  progressCaption?: string
  className?: string
}

export function MysqlMetricKpiCard({
  label,
  value,
  subValue,
  description,
  progress = null,
  progressTone = 'normal',
  progressCaption,
  className,
}: MysqlMetricKpiCardProps) {
  const t = useT()
  return (
    <div
      className={cn(
        'rounded-lg border border-border bg-card p-4',
        className,
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(label)}
        </p>
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <button
                type="button"
                className="text-muted-foreground transition-colors hover:text-foreground"
              >
                <Info className="h-3.5 w-3.5" />
              </button>
            </TooltipTrigger>
            <TooltipContent
              side="top"
              className="max-w-xs text-[12px] leading-relaxed"
            >
              <p>{t(description)}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
      <div className="mt-2 flex items-baseline gap-2">
        <span className="text-[22px] font-semibold tabular-nums text-foreground">
          {value}
        </span>
        {subValue ? (
          <span className="text-[12px] text-muted-foreground">{subValue}</span>
        ) : null}
      </div>
      {progress != null ? (
        <div className="mt-3 space-y-1">
          <Progress
            value={Math.min(100, Math.max(0, progress))}
            className={cn(
              'h-1.5',
              progressTone === 'critical' &&
                '[&_[data-slot=progress-indicator]]:bg-red-500',
              progressTone === 'warning' &&
                '[&_[data-slot=progress-indicator]]:bg-amber-500',
              progressTone === 'normal' &&
                'bg-[var(--chart-brand)]/15 [&_[data-slot=progress-indicator]]:bg-[var(--chart-brand)]',
            )}
          />
          <p className="text-[11px] text-muted-foreground">
            {progressCaption ?? `${progress.toFixed(1)}% ${t('of limit')}`}
          </p>
        </div>
      ) : null}
    </div>
  )
}
