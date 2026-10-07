import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

interface PlanLimitWarningProps {
  /** The current count of resources */
  currentCount: number
  /** The limit from the plan (0 means unlimited). May be bigint from the SDK. */
  limit: number | bigint | null | undefined
  /** The plan name */
  planName?: string
  /** The resource name (e.g., "databases", "buckets", "functions") */
  resourceName: string
  /** The organization ID for the upgrade link */
  orgId: string | null | undefined
  /** When true, the alert content spans the full viewport width. */
  fullWidth?: boolean
}

export function PlanLimitWarning({
  currentCount,
  limit,
  planName = 'plan',
  resourceName,
  orgId,
  fullWidth = true,
}: PlanLimitWarningProps) {
  const t = useT()
  const limitNumber =
    limit == null
      ? 0
      : typeof limit === 'bigint'
        ? Number(limit)
        : Number(limit)

  // If limit is null, undefined, NaN, or 0, it means unlimited - no warning needed
  if (!Number.isFinite(limitNumber) || limitNumber === 0) {
    return null
  }

  const isAtLimit = currentCount >= limitNumber
  const isApproachingLimit = currentCount >= limitNumber * 0.5 // Show alert when at 50% of limit

  // Only show alert if at limit or approaching limit (50%+)
  if (!isAtLimit && !isApproachingLimit) {
    return null
  }

  const remaining = Math.max(0, limitNumber - currentCount)

  return (
    <div className="border-b border-border bg-amber-500/5">
      <div
        className={cn(
          'w-full px-4 py-3 sm:px-6',
          !fullWidth && 'mx-auto max-w-7xl',
        )}
      >
        <Alert variant="default" className="border-amber-500/30 bg-transparent">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <div className="flex flex-1 items-start justify-between gap-4">
            <div className="flex-1 min-w-0">
              <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                {isAtLimit
                  ? `${t("You've reached the limit of")} ${limitNumber} ${t(resourceName)}`
                  : `${t('Approaching the limit for')} ${t(resourceName)}`}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                <span className="inline">
                  {isAtLimit ? (
                    <>
                      {t('Your plan')} ({planName}) {t('includes up to')}{' '}
                      {limitNumber} {t(resourceName)}.{' '}
                      {orgId && (
                        <Link
                          to="/upgrade"
                          search={{ orgId }}
                          className="font-medium underline hover:no-underline"
                        >
                          {t('Upgrade')}
                        </Link>
                      )}{' '}
                      {t('to unlock more capacity.')}
                    </>
                  ) : (
                    <>
                      {t('Your plan')} ({planName}) {t('includes up to')}{' '}
                      {limitNumber} {t(resourceName)}. {t('Remaining:')}{' '}
                      {remaining}
                      .{' '}
                      {orgId && (
                        <Link
                          to="/upgrade"
                          search={{ orgId }}
                          className="font-medium underline hover:no-underline"
                        >
                          {t('Upgrade')}
                        </Link>
                      )}{' '}
                      {t('to unlock more capacity.')}
                    </>
                  )}
                </span>
              </AlertDescription>
            </div>
            {orgId && (
              <Button
                asChild
                size="sm"
                className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
              >
                <Link to="/upgrade" search={{ orgId }}>
                  {t('Upgrade')}
                </Link>
              </Button>
            )}
          </div>
        </Alert>
      </div>
    </div>
  )
}
