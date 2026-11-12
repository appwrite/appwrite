import type { Models } from '@appwrite.io/console'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  getPlanDatabaseOperationLimits,
  type PlanDatabaseOperationLimit,
} from '@/lib/databases/dedicated-database-plan'
import { formatCompactCount } from '@/lib/usage/format-metric'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ServerlessSpecPriceProps = {
  plan: Models.BillingPlan | null | undefined
  showTooltip?: boolean
  className?: string
}

function formatOpsCount(
  value: PlanDatabaseOperationLimit,
  t: (text: string) => string,
): string {
  if (value === 'unlimited') return t('Unlimited')
  return formatCompactCount(value)
}

function formatIncludedOpsLabel(
  reads: PlanDatabaseOperationLimit | null,
  writes: PlanDatabaseOperationLimit | null,
  t: (text: string) => string,
): string | null {
  if (reads == null && writes == null) return null
  if (reads === 'unlimited' && writes === 'unlimited') {
    return t('Unlimited reads and writes included')
  }
  if (reads != null && writes != null) {
    return `${formatOpsCount(reads, t)} ${t('reads')}, ${formatOpsCount(writes, t)} ${t('writes included')}`
  }
  if (reads != null) {
    return `${formatOpsCount(reads, t)} ${t('reads included')}`
  }
  return `${formatOpsCount(writes!, t)} ${t('writes included')}`
}

export function ServerlessSpecPrice({
  plan,
  showTooltip = true,
  className,
}: ServerlessSpecPriceProps) {
  const t = useT()
  const { reads, writes } = getPlanDatabaseOperationLimits(plan)
  const label = formatIncludedOpsLabel(reads, writes, t)
  const content = (
    <span
      className={cn(
        'inline-block whitespace-nowrap text-end text-[13px] font-semibold tabular-nums tracking-tight text-foreground',
        className,
      )}
    >
      {label ?? t('No compute fee')}
    </span>
  )

  if (!showTooltip) {
    return content
  }

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-block cursor-help underline decoration-dotted decoration-muted-foreground/50 underline-offset-2">
            {content}
          </span>
        </TooltipTrigger>
        <TooltipContent side="top" className="max-w-[240px]">
          <p className="text-[12px]">
            {t('Included in your plan every month.')}
          </p>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
