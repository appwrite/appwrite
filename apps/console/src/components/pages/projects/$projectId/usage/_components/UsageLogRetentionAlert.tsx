import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
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
    <div className="border-b border-border bg-amber-500/5">
      <div className="w-full px-4 py-3 sm:px-6">
        <Alert variant="default" className="border-amber-500/30 bg-transparent">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <div className="flex min-w-0 flex-1 items-center justify-between gap-4">
            <div className="min-w-0 flex-1">
              <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                {t('Usage history limit reached')}
              </AlertTitle>
              <AlertDescription className="col-start-2 block min-w-0 truncate whitespace-nowrap text-[12px] text-amber-600/80 dark:text-amber-400/80">
                {t('Your plan includes')}{' '}
                <span className="font-medium">{retentionDays}</span>{' '}
                {t(
                  'days of usage history. Use a shorter range or upgrade for more.',
                )}
              </AlertDescription>
            </div>
            <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
              {onAdjustRange ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-8 border-amber-500/30 bg-transparent px-3 text-[12px] text-amber-700 hover:bg-amber-500/10 dark:text-amber-300 dark:hover:bg-amber-500/10"
                  onClick={onAdjustRange}
                >
                  {t('Use shorter range')}
                </Button>
              ) : null}
              {showUpgradeCta ? (
                <Button
                  asChild
                  size="sm"
                  className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                >
                  <Link to="/upgrade" search={{ orgId: organizationId! }}>
                    {t('Upgrade plan')}
                  </Link>
                </Button>
              ) : null}
            </div>
          </div>
        </Alert>
      </div>
    </div>
  )
}
