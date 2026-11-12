import { useMemo } from 'react'
import { Info } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Progress } from '@/components/ui/progress'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { formatCompactBytes } from '@/lib/usage/format-metric'
import { useT } from '@/lib/i18n/translate'

export type MysqlMetricRankedItem = {
  label: string
  value: number
  detail?: string
  fullLabel?: string
}

type MysqlMetricRankedListProps = {
  id: string
  title: string
  description: string
  items: MysqlMetricRankedItem[]
  formatValue?: (value: number) => string
  emptyMessage?: string
  className?: string
}

export function MysqlMetricRankedList({
  id,
  title,
  description,
  items,
  formatValue = (value) => formatCompactBytes(value),
  emptyMessage = 'No data available yet.',
  className,
}: MysqlMetricRankedListProps) {
  const t = useT()
  const rankedItems = useMemo(() => {
    const sorted = [...items].sort((a, b) => b.value - a.value)
    const maxValue = sorted[0]?.value ?? 0
    return sorted.map((item, index) => ({
      ...item,
      fullLabel: item.fullLabel ?? item.label,
      rank: index + 1,
      sharePercent:
        maxValue > 0 ? Math.min(100, (item.value / maxValue) * 100) : 0,
    }))
  }, [items])

  return (
    <div
      id={`mysql-metric-chart-${id}`}
      className={cn(
        'scroll-mt-[calc(4rem+env(safe-area-inset-top))] w-full overflow-hidden rounded-lg border border-border bg-card',
        className,
      )}
    >
      <div className="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <h3 className="text-[14px] font-medium text-foreground">{title}</h3>
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
                  <p>{description}</p>
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          </div>
        </div>
      </div>

      <div className="p-4">
        {rankedItems.length === 0 ? (
          <div className="flex min-h-[180px] items-center justify-center rounded-md border border-dashed border-border bg-muted/20 px-6 text-center">
            <p className="max-w-sm text-[12px] leading-relaxed text-muted-foreground">
              {t(emptyMessage)}
            </p>
          </div>
        ) : (
          <div className="divide-y divide-border">
            {rankedItems.map((item) => (
              <div
                key={`${item.fullLabel}-${item.rank}`}
                className="space-y-2 py-3 first:pt-0 last:pb-0"
              >
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                      <span className="w-5 shrink-0 text-[11px] font-medium tabular-nums text-muted-foreground">
                        {item.rank}
                      </span>
                      <p className="min-w-0 truncate text-[13px] font-medium text-foreground">
                        {item.fullLabel}
                        {item.detail ? (
                          <span className="font-normal text-muted-foreground">
                            {' '}
                            · {item.detail}
                          </span>
                        ) : null}
                      </p>
                    </div>
                  </div>
                  <span className="shrink-0 text-[13px] font-medium tabular-nums text-foreground">
                    {formatValue(item.value)}
                  </span>
                </div>
                <div className="ps-7">
                  <Progress
                    value={item.sharePercent}
                    className="h-1.5 bg-[var(--chart-brand)]/15 [&_[data-slot=progress-indicator]]:bg-[var(--chart-brand)]"
                  />
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="border-t border-border bg-muted/30 px-4 py-3">
        <p className="text-[12px] leading-relaxed text-muted-foreground">
          {description}
        </p>
      </div>
    </div>
  )
}
