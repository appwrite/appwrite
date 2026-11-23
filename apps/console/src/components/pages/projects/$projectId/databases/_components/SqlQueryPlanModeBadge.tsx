import { ListTree, Timer } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import type { SqlQueryPlanMode } from './sql-query-plan-mode'
import { useT } from '@/lib/i18n/translate'

type SqlQueryPlanModeBadgeProps = {
  mode: SqlQueryPlanMode
}

export function SqlQueryPlanModeBadge({ mode }: SqlQueryPlanModeBadgeProps) {
  const t = useT()
  const isAnalyze = mode === 'analyze'

  return (
    <Badge
      variant={isAnalyze ? 'info' : 'secondary'}
      className="gap-1 text-[10px] shrink-0"
    >
      {isAnalyze ? (
        <Timer className="h-3 w-3 shrink-0" aria-hidden />
      ) : (
        <ListTree className="h-3 w-3 shrink-0" aria-hidden />
      )}
      {isAnalyze ? t('Analyze') : t('Explain')}
    </Badge>
  )
}

export function SqlQueryPlanModeDescription({
  mode,
}: SqlQueryPlanModeBadgeProps) {
  const t = useT()

  return (
    <p className="text-[12px] text-muted-foreground mt-1">
      {mode === 'analyze'
        ? t('Query was executed. Timings show actual performance.')
        : t('Estimated plan only. The query was not executed.')}
    </p>
  )
}
