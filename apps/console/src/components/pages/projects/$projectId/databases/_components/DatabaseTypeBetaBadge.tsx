import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

/** Compact chip matching `DatabaseTypeBadge` for database types still in beta. */
export function DatabaseTypeBetaBadge({ className }: { className?: string }) {
  const t = useT()
  return (
    <span
      className={cn(
        'inline-flex shrink-0 items-center rounded-md border border-border bg-muted/40 px-1.5 py-0.5 text-[12px] font-medium text-foreground',
        className,
      )}
    >
      {t('Beta')}
    </span>
  )
}
