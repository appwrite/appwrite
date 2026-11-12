import type { ReactNode } from 'react'
import { ListTree, Loader2, Timer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { SqlQueryPlanMode } from './sql-query-plan-mode'

type SqlEditorExplainActionsProps = {
  canExplain: boolean
  isExplaining: boolean
  isRunning: boolean
  activeMode?: SqlQueryPlanMode | null
  onExplain: () => void
  onAnalyze: () => void
  explainShortcut: string
  analyzeShortcut: string
  explainDisabledTooltip?: string
  operationsDisabledTooltip?: string
}

function ExplainTooltip({
  enabled,
  disabledReason,
  enabledLabel,
  children,
}: {
  enabled: boolean
  disabledReason?: string
  enabledLabel: string
  children: ReactNode
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-flex">{children}</span>
      </TooltipTrigger>
      <TooltipContent side="top" sideOffset={6} className="text-[12px]">
        {enabled ? enabledLabel : disabledReason}
      </TooltipContent>
    </Tooltip>
  )
}

function activeButtonClass(isActive: boolean) {
  return isActive
    ? 'border-primary/35 bg-primary/8 text-foreground shadow-sm'
    : undefined
}

export function SqlEditorExplainActions({
  canExplain,
  isExplaining,
  isRunning,
  activeMode = null,
  onExplain,
  onAnalyze,
  explainShortcut,
  analyzeShortcut,
  explainDisabledTooltip,
  operationsDisabledTooltip,
}: SqlEditorExplainActionsProps) {
  const t = useT()
  const explainActive = activeMode === 'explain'
  const analyzeActive = activeMode === 'analyze'

  const explainDisabledReason =
    explainDisabledTooltip ??
    (isExplaining
      ? t('Query explanation is running.')
      : isRunning
        ? t('Query is running.')
        : t('Write SQL before explaining a query.'))

  const analyzeDisabledReason =
    operationsDisabledTooltip ??
    explainDisabledTooltip ??
    (isExplaining
      ? t('Query explanation is running.')
      : isRunning
        ? t('Query is running.')
        : t('Write SQL before analyzing a query.'))

  const explainEnabledLabel = `${t('Explain')} (${explainShortcut})`
  const analyzeEnabledLabel = `${t('Analyze')} (${analyzeShortcut}). ${t('Executes the query and shows actual timings.')}`

  return (
    <div className="inline-flex items-center">
      <ExplainTooltip
        enabled={canExplain}
        disabledReason={explainDisabledReason}
        enabledLabel={explainEnabledLabel}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-pressed={explainActive}
          className={cn(
            'h-8 gap-1.5 rounded-r-none border-r-0 px-3 text-[12px] font-medium',
            activeButtonClass(explainActive),
          )}
          onClick={onExplain}
          disabled={!canExplain}
        >
          {isExplaining && explainActive ? (
            <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
          ) : (
            <ListTree className="h-3.5 w-3.5 shrink-0" />
          )}
          {t('Explain')}
        </Button>
      </ExplainTooltip>

      <ExplainTooltip
        enabled={canExplain}
        disabledReason={analyzeDisabledReason}
        enabledLabel={analyzeEnabledLabel}
      >
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-pressed={analyzeActive}
          className={cn(
            'h-8 gap-1.5 rounded-l-none px-3 text-[12px] font-medium',
            activeButtonClass(analyzeActive),
          )}
          onClick={onAnalyze}
          disabled={!canExplain}
        >
          {isExplaining && analyzeActive ? (
            <Loader2 className="h-3.5 w-3.5 shrink-0 animate-spin" />
          ) : (
            <Timer className="h-3.5 w-3.5 shrink-0" />
          )}
          {t('Analyze')}
        </Button>
      </ExplainTooltip>
    </div>
  )
}
