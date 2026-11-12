import type { ReactNode } from 'react'
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

export type PostgresMetricsBentoTile = {
  id: string
  label: string
  value: ReactNode
  subValue?: string
  description?: string
  progress?: number | null
  progressTone?: 'normal' | 'warning' | 'critical'
  progressCaption?: string
}

type PostgresMetricsBentoCardProps = {
  title?: string
  tiles: PostgresMetricsBentoTile[]
  /** Max tiles per row before wrapping to the next row. */
  columns?: 2 | 3 | 4
  className?: string
}

function chunkTiles<T>(tiles: T[], size: number): T[][] {
  const rows: T[][] = []
  for (let index = 0; index < tiles.length; index += size) {
    rows.push(tiles.slice(index, index + size))
  }
  return rows
}

function rowGridClassName(columnCount: number): string {
  if (columnCount <= 1) return 'grid-cols-1'
  if (columnCount === 2) {
    return 'grid-cols-1 divide-y divide-border sm:grid-cols-2 sm:divide-x sm:divide-y-0'
  }
  if (columnCount === 3) {
    return 'grid-cols-1 divide-y divide-border sm:grid-cols-3 sm:divide-x sm:divide-y-0'
  }
  return 'grid-cols-1 divide-y divide-border sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-y-0'
}

function PostgresMetricBentoTile({
  label,
  value,
  subValue,
  description,
  progress = null,
  progressTone = 'normal',
  progressCaption,
}: Omit<PostgresMetricsBentoTile, 'id'>) {
  const t = useT()
  return (
    <div className="min-w-0 px-4 py-4">
      <div className="flex items-start justify-between gap-2">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
          {t(label)}
        </p>
        {description ? (
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  className="shrink-0 text-muted-foreground transition-colors hover:text-foreground"
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
        ) : null}
      </div>
      <div className="mt-1.5 flex items-baseline gap-2">
        <span className="text-[15px] font-semibold tabular-nums text-foreground">
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
            {progressCaption
              ? t(progressCaption)
              : `${progress.toFixed(1)}% ${t('of limit')}`}
          </p>
        </div>
      ) : null}
    </div>
  )
}

export function PostgresMetricsBentoCard({
  title,
  tiles,
  columns = 3,
  className,
}: PostgresMetricsBentoCardProps) {
  const rows = chunkTiles(tiles, columns)

  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/50',
        className,
      )}
    >
      {title ? (
        <div className="border-b border-border px-4 py-3">
          <h3 className="text-[14px] font-medium text-foreground">{title}</h3>
        </div>
      ) : null}

      <div className="divide-y divide-border">
        {rows.map((row, rowIndex) => (
          <div
            key={rowIndex}
            className={cn('grid', rowGridClassName(row.length))}
          >
            {row.map((tile) => (
              <PostgresMetricBentoTile key={tile.id} {...tile} />
            ))}
          </div>
        ))}
      </div>
    </div>
  )
}
