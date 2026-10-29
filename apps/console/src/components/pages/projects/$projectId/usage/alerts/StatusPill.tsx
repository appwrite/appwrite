import type { AlertRule } from '@/lib/appwrite/alerts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export type RuleState = 'firing' | 'ok' | 'pending' | 'paused'

/** A rule that has never been checked is pending, not OK: nothing is known about it yet. */
export function ruleState(
  rule: Pick<AlertRule, 'enabled' | 'status' | 'evaluatedAt'>,
): RuleState {
  if (!rule.enabled) return 'paused'
  if (!rule.evaluatedAt) return 'pending'
  return rule.status === 'firing' ? 'firing' : 'ok'
}

const STYLES: Record<RuleState, { dot: string; text: string; label: string }> =
  {
    firing: {
      dot: 'bg-red-500',
      text: 'text-red-600 dark:text-red-400 bg-red-500/10',
      label: 'Firing',
    },
    ok: {
      dot: 'bg-emerald-500',
      text: 'text-emerald-700 dark:text-emerald-400 bg-emerald-500/10',
      label: 'OK',
    },
    pending: {
      dot: 'border-[1.5px] border-blue-600 dark:border-blue-400',
      text: 'text-blue-700 dark:text-blue-300 bg-blue-500/10',
      label: 'Pending',
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
