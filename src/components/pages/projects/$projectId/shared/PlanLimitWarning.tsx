import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { AlertCircle } from 'lucide-react'
import { Link } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'

interface PlanLimitWarningProps {
  /** The current count of resources */
  currentCount: number
  /** The limit from the plan (0 means unlimited) */
  limit: number | null | undefined
  /** The plan name */
  planName?: string
  /** The resource name (e.g., "databases", "buckets", "functions") */
  resourceName: string
  /** The organization ID for the upgrade link */
  orgId: string | null | undefined
  /** Whether the plan data is still loading */
  isLoading?: boolean
}

export function PlanLimitWarning({
  currentCount,
  limit,
  planName = 'plan',
  resourceName,
  orgId,
  isLoading = false,
}: PlanLimitWarningProps) {
  // If loading, render placeholder to prevent layout shift
  // Match the exact structure and height of the actual alert
  if (isLoading) {
    return (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
          <div className="relative w-full rounded-lg border border-amber-500/30 px-4 py-3">
            <div className="flex items-start justify-between gap-4">
              <div className="flex-1 min-w-0">
                <div className="h-4 w-32 bg-amber-500/20 rounded mb-2" aria-hidden="true" />
                <div className="h-3 w-48 bg-amber-500/20 rounded" aria-hidden="true" />
              </div>
              <div className="h-8 w-20 bg-amber-500/20 rounded shrink-0" aria-hidden="true" />
            </div>
          </div>
        </div>
      </div>
    )
  }

  // If limit is null, undefined, or 0, it means unlimited - no warning needed
  if (!limit || limit === 0) {
    return null
  }

  const isAtLimit = currentCount >= limit
  const isApproachingLimit = currentCount >= limit * 0.5 // Show alert when at 50% of limit
  
  // Only show alert if at limit or approaching limit (50%+)
  if (!isAtLimit && !isApproachingLimit) {
    return null
  }
  
  const remaining = Math.max(0, limit - currentCount)

  return (
    <div className="border-b border-border bg-amber-500/5">
      <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
        <Alert variant="default" className="border-amber-500/30 bg-transparent">
          <AlertCircle className="h-4 w-4 text-amber-500" />
          <div className="flex flex-1 items-start justify-between gap-4">
            <div className="flex-1 min-w-0">
              <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                {isAtLimit
                  ? `You've reached the limit of ${limit} ${resourceName}`
                  : `Approaching ${resourceName} limit`}
              </AlertTitle>
              <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                <span className="inline">
                  {isAtLimit ? (
                    <>
                      Your {planName} plan includes up to {limit} {resourceName}.{' '}
                      {orgId && (
                        <Link
                          to="/organizations/$orgId/billing"
                          params={{ orgId }}
                          className="font-medium underline hover:no-underline"
                        >
                          Upgrade
                        </Link>
                      )}
                      {' '}to unlock more capacity.
                    </>
                  ) : (
                    <>
                      Your {planName} plan includes up to {limit} {resourceName}. You have {remaining} remaining.{' '}
                      {orgId && (
                        <Link
                          to="/organizations/$orgId/billing"
                          params={{ orgId }}
                          className="font-medium underline hover:no-underline"
                        >
                          Upgrade
                        </Link>
                      )}
                      {' '}to unlock more capacity.
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
                <Link
                  to="/organizations/$orgId/billing"
                  params={{ orgId }}
                >
                  Upgrade
                </Link>
              </Button>
            )}
          </div>
        </Alert>
      </div>
    </div>
  )
}

