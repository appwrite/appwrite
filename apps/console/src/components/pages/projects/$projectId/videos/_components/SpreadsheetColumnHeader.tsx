import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react'
import { VideoTermHint } from './VideoTermHint'
import type { VideoGlossaryTerm } from '@/lib/videos/glossary'
import { cn } from '@/lib/utils'

type SpreadsheetColumnHeaderProps = {
  label: string
  term?: VideoGlossaryTerm
  sortColumnKey?: string
  sortBy?: string
  sortOrder?: 'asc' | 'desc'
  onSortColumn?: (columnKey: string) => void
  className?: string
}

/** Table header cell inner layout: truncated label, optional term hint, sort control at the end. */
export function SpreadsheetColumnHeader({
  label,
  term,
  sortColumnKey,
  sortBy,
  sortOrder,
  onSortColumn,
  className,
}: SpreadsheetColumnHeaderProps) {
  const showSort =
    onSortColumn && sortColumnKey != null && sortBy != null && sortOrder != null

  return (
    <div
      className={cn('flex w-full min-w-0 items-center gap-1 pe-0.5', className)}
    >
      <div className="flex min-w-0 flex-1 items-center gap-1 overflow-hidden">
        <span className="truncate text-[12px] font-medium text-foreground">
          {label}
        </span>
        {term ? <VideoTermHint term={term} /> : null}
      </div>
      {showSort ? (
        <button
          type="button"
          onClick={() => onSortColumn(sortColumnKey)}
          className="shrink-0 cursor-pointer rounded p-0.5 transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          aria-label={label}
        >
          {sortBy === sortColumnKey ? (
            sortOrder === 'asc' ? (
              <ArrowUp className="h-3 w-3 shrink-0 text-chart-brand" />
            ) : (
              <ArrowDown className="h-3 w-3 shrink-0 text-chart-brand" />
            )
          ) : (
            <ArrowUpDown className="h-3 w-3 shrink-0 text-muted-foreground" />
          )}
        </button>
      ) : null}
    </div>
  )
}
