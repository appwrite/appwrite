import { Link } from '@tanstack/react-router'
import { Coins } from 'lucide-react'
import { Progress } from '@/components/ui/progress'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { allowsReviewCreditsOverage } from '@/lib/appwrite/organization-review-credits'
import { isReviewCreditsLimitReached } from '@/lib/billing/billing-limits'
import {
  useOrganizationById,
  useOrganizationPlan,
  useOrganizationUsage,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type CreditsProps = {
  projectId: string
  organizationId: string | null | undefined
}

/** Cloud added these after the SDK models were generated. */
type ReviewCreditsUsage = {
  reviewCreditsTotal?: number
  projects?: { projectId?: string; reviewCredits?: number }[]
}
type ReviewCreditsPlan = {
  reviewCredits?: number
  budgeting?: boolean
  usagePerProject?: boolean
  usage?: Record<string, { price?: number; value?: number } | undefined>
}

/**
 * Code review credits spent this billing cycle against the plan's allowance,
 * on Cloud only. Plans that bill usage per project (Pro, Scale) give every
 * project its own allowance; the rest share one across the organization.
 *
 * Past the allowance, budgeting plans keep reviewing and pay for the extra
 * credits up to the budget cap, unless extra credits are off for the
 * organization; other plans stop reviewing until they upgrade.
 */
export function Credits({ projectId, organizationId }: CreditsProps) {
  const t = useT()
  const { isCloud } = useConsoleProfile()
  const orgId = isCloud ? organizationId : null
  const { organization } = useOrganizationById(orgId)
  const { plan: rawPlan } = useOrganizationPlan(orgId)
  const { usage: rawUsage } = useOrganizationUsage(orgId)

  const plan = rawPlan as ReviewCreditsPlan | undefined
  const usage = rawUsage as ReviewCreditsUsage | undefined
  const allowance = plan?.reviewCredits ?? 0

  if (!isCloud || !orgId || !plan || !usage || allowance <= 0) return null

  const perProject = plan.usagePerProject === true
  const spent = Math.max(
    0,
    perProject
      ? (usage.projects?.find((p) => p.projectId === projectId)
          ?.reviewCredits ?? 0)
      : (usage.reviewCreditsTotal ?? 0),
  )
  const extra = Math.max(0, spent - allowance)
  const pricing = plan.usage?.reviewCredits
  const extraCost =
    pricing?.price && pricing.value
      ? (extra / pricing.value) * pricing.price
      : 0
  const budgeting = plan.budgeting === true
  const canBuyMore = budgeting && allowsReviewCreditsOverage(organization)
  const blocked =
    !canBuyMore &&
    (spent >= allowance ||
      isReviewCreditsLimitReached(organization?.billingLimits))
  const percent = Math.min(100, (spent / allowance) * 100)
  const format = (n: number) => n.toLocaleString()

  return (
    <div
      className={cn(
        'mb-4 flex flex-col gap-3 rounded-lg border bg-card px-4 py-3 sm:flex-row sm:items-center sm:gap-6',
        blocked ? 'border-destructive/50' : 'border-border',
      )}
    >
      <div className="flex min-w-0 flex-1 items-center gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted/50">
          <Coins className="h-4 w-4 text-muted-foreground" />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-baseline justify-between gap-2">
            <p className="text-[13px] font-medium text-foreground">
              {perProject
                ? t('Review credits this cycle (this project)')
                : t('Review credits this cycle')}
            </p>
            <p className="shrink-0 text-[12px] tabular-nums text-muted-foreground">
              {format(Math.min(spent, allowance))} / {format(allowance)}
            </p>
          </div>
          <Progress
            value={percent}
            className={cn('mt-1.5 h-1.5', blocked && '[&>div]:bg-destructive')}
          />
          <p className="mt-1.5 text-[11px] text-muted-foreground">
            {blocked
              ? budgeting
                ? t(
                    'All included credits are used. New pull requests are not reviewed until the next billing cycle.',
                  )
                : t(
                    'All included credits are used. New pull requests are not reviewed until the next billing cycle or an upgrade.',
                  )
              : extra > 0
                ? t(
                    '{extra} extra credits so far, about ${cost} on your next invoice.',
                  )
                    .replace('{extra}', format(extra))
                    .replace('{cost}', extraCost.toFixed(2))
                : canBuyMore
                  ? t(
                      'Extra credits past the allowance are billed with your usage, up to your budget cap.',
                    )
                  : budgeting
                    ? t(
                        'Reviews stop when the included credits are used, so you are never billed for extra credits.',
                      )
                    : t(
                        'A normal review costs 50 credits. Answers to comments are free.',
                      )}
          </p>
        </div>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {budgeting ? (
          <Link
            to="/organizations/$orgId/settings/billing"
            params={{ orgId }}
            hash="update-budget"
            className="inline-flex h-8 items-center rounded-md border border-border px-3 text-[13px] font-medium hover:bg-muted"
          >
            {t('Manage budget')}
          </Link>
        ) : (
          <Link
            to="/upgrade"
            search={{ orgId }}
            className={cn(
              'inline-flex h-8 items-center rounded-md px-3 text-[13px] font-medium',
              blocked
                ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                : 'border border-border hover:bg-muted',
            )}
          >
            {t('Upgrade for more credits')}
          </Link>
        )}
        <Link
          to="/organizations/$orgId/settings/billing"
          params={{ orgId }}
          hash="current-cycle-usage"
          className="inline-flex h-8 items-center rounded-md px-3 text-[13px] font-medium text-muted-foreground hover:text-foreground"
        >
          {t('View usage')}
        </Link>
      </div>
    </div>
  )
}
