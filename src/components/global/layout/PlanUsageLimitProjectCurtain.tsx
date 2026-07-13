import { Link, useNavigate } from '@tanstack/react-router'
import { Lock, ArrowUpCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { getSingleRecognizedPlanUsageLimitLabel } from '@/lib/billing/billing-limits'
import { useT } from '@/lib/i18n/translate'

type PlanUsageLimitProjectCurtainProps = {
  /** Organization id for upgrade / billing CTAs */
  teamId?: string | null
  billingLimits?: Record<string, number | string | null | undefined> | null
}

/**
 * Full-screen curtain when the organization has hit a plan usage limit
 * (e.g. Free plan GB-hours). Blocks project-scoped pages until the user
 * upgrades or the billing cycle resets. Budget-cap blocks use
 * {@link BudgetLimitProjectCurtain} instead.
 */
export function PlanUsageLimitProjectCurtain({
  teamId,
  billingLimits,
}: PlanUsageLimitProjectCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const hasTeamId = !!teamId
  const resourceLabel = getSingleRecognizedPlanUsageLimitLabel(billingLimits)

  return (
    <div className="fixed inset-0 z-[120] flex items-center justify-center bg-background/95 backdrop-blur-sm">
      <div className="mx-4 flex max-w-md flex-col items-center text-center">
        <div className="mb-6 flex size-16 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <Lock className="size-9" />
        </div>
        <h1 className="text-[22px] font-semibold text-foreground">
          {t('Plan limit reached')}
        </h1>
        <p className="mt-3 text-[15px] text-muted-foreground">
          {resourceLabel ? (
            <>
              {t('This organization has reached its plan limit for')}{' '}
              {t(resourceLabel)}
              {t(
                '. Upgrade your plan or wait until the end of the billing cycle to restore access.',
              )}
            </>
          ) : (
            t(
              'This organization has reached its plan usage limit and is now blocked. Upgrade your plan or wait until the end of the billing cycle to restore access.',
            )
          )}
        </p>

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:gap-2">
          {hasTeamId ? (
            <Button asChild variant="brandCta" className="gap-1.5">
              <Link to="/upgrade" search={{ orgId: teamId }}>
                <ArrowUpCircle className="size-4" />
                {t('Upgrade plan')}
              </Link>
            </Button>
          ) : null}
          {hasTeamId ? (
            <Button asChild variant="outline">
              <Link
                to="/organizations/$orgId/settings/billing"
                params={{ orgId: teamId }}
                hash="current-cycle-usage"
              >
                {t('View current cycle usage')}
              </Link>
            </Button>
          ) : (
            <Button variant="brandCta" onClick={() => navigate({ to: '/' })}>
              {t('Back to console')}
            </Button>
          )}
        </div>

        {hasTeamId ? (
          <Button
            variant="ghost"
            className="mt-6 text-muted-foreground"
            onClick={() =>
              navigate({
                to: '/organizations/$orgId',
                params: { orgId: teamId },
              })
            }
          >
            {t('Back to organization')}
          </Button>
        ) : null}
      </div>
    </div>
  )
}
