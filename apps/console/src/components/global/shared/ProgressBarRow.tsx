/**
 * Shared progress bar row: Progress component + percentage label.
 * Used by UploadProgress and CsvExportBox so both look identical.
 */

import { Progress } from '@/components/ui/progress'
import { cn } from '@/lib/utils'

interface ProgressBarRowProps {
  value: number
  className?: string
}

export function ProgressBarRow({ value, className }: ProgressBarRowProps) {
  return (
    <div className={cn('flex items-center gap-2 mb-2', className)}>
      <Progress value={value} className="h-1.5 flex-1" />
      <span className="w-9 shrink-0 text-right text-[11px] tabular-nums text-muted-foreground">
        {Math.round(value)}%
      </span>
    </div>
  )
}
