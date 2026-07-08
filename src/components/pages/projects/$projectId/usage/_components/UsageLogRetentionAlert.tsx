import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useT } from '@/lib/i18n/translate'
import { useConsoleProfile } from '@/hooks/use-console-profile'

type UsageLogRetentionAlertProps = {
  retentionDays: number
  organizationId?: string | null
  onAdjustRange?: () => void
}

export function UsageLogRetentionAlert({
  retentionDays,
  organizationId,
  onAdjustRange,
}: UsageLogRetentionAlertProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const showUpgradeCta = features.billing && !!organizationId

  return (
    <div className="mb-6 flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/20 sm:flex-row sm:items-start">
      <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
      <div className="min-w-0 flex-1">
        <p className="text-[13px] font-medium text-amber-800 dark:text-amber-200">
          {t('Usage history limit reached')}
        </p>
        <p className="mt-1 text-[12px] leading-relaxed text-amber-700 dark:text-amber-300">
          {t('Your plan includes')}{' '}
          <span className="font-medium">{retentionDays}</span>{' '}
          {t(
            'days of usage history. Choose a shorter date range or upgrade for longer retention.',
          )}
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {onAdjustRange ? (
          <Button
            variant="outline"
            size="sm"
            className="h-8 border-amber-300 bg-background/80 text-[12px] text-amber-800 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-200 dark:hover:bg-amber-950/40"
            onClick={onAdjustRange}
          >
            {t('Use shorter range')}
          </Button>
        ) : null}
        {showUpgradeCta ? (
          <Button
            variant="outline"
            size="sm"
            className="h-8 border-amber-300 bg-amber-100/80 text-[12px] font-medium text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200 dark:hover:bg-amber-900/50"
            asChild
          >
            <Link
              to="/organizations/$orgId/settings/billing"
              params={{ orgId: organizationId! }}
            >
              {t('Upgrade plan')}
            </Link>
          </Button>
        ) : null}
      </div>
    </div>
  )
}
