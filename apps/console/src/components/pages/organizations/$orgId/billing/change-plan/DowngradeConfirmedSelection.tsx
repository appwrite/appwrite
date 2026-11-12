import type { ReactNode } from 'react'
import { Check } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

const LABEL_PREVIEW_LIMIT = 3

/** Locked view of a confirmed selection; the items are deleted at submit time. */
export function DowngradeConfirmedSelection({
  title,
  labels,
  onEditSelection,
  className,
}: {
  title: ReactNode
  labels: string[]
  onEditSelection: () => void
  className?: string
}) {
  const t = useT()
  const preview = labels.slice(0, LABEL_PREVIEW_LIMIT)
  const hiddenCount = labels.length - preview.length
  const summary =
    hiddenCount > 0
      ? `${preview.join(', ')}, +${hiddenCount} ${t('more')}`
      : preview.join(', ')

  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex items-start gap-3 rounded-lg border border-border bg-background/60 p-3">
        <Check className="mt-0.5 h-4 w-4 shrink-0 text-green-600 dark:text-green-500" />
        <div className="min-w-0 space-y-1">
          <p className="text-[13px] font-medium leading-normal text-foreground">
            {title}
          </p>
          <p
            className="truncate text-[13px] leading-normal text-muted-foreground"
            title={summary}
          >
            {summary}
          </p>
        </div>
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className="h-8 text-[13px]"
        onClick={onEditSelection}
      >
        {t('Edit selection')}
      </Button>
    </div>
  )
}
