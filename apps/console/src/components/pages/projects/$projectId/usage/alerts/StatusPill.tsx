import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type RuleState = 'firing' | 'ok' | 'paused'

const STYLES: Record<RuleState, { dot: string; text: string; label: string }> =
  {
    firing: {
      dot: 'bg-red-500 animate-pulse',
      text: 'text-red-600 dark:text-red-400 bg-red-500/10',
      label: 'Firing',
    },
    ok: {
      dot: 'bg-emerald-500',
      text: 'text-emerald-700 dark:text-emerald-400 bg-emerald-500/10',
      label: 'OK',
    },
    paused: {
      dot: 'bg-muted-foreground',
      text: 'text-muted-foreground bg-muted',
      label: 'Paused',
    },
  }

export function StatusPill({
  status,
  className,
}: {
  status: RuleState
  className?: string
}) {
  const t = useT()
  const style = STYLES[status]
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium',
        style.text,
        className,
      )}
    >
      <span className={cn('h-1.5 w-1.5 rounded-full', style.dot)} />
      {t(style.label)}
    </span>
  )
}
