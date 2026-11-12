import { Link, useNavigate } from '@tanstack/react-router'
import { Lock, ArrowUpCircle } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { FullScreenCurtain } from '@/components/global/shared/FullScreenCurtain'
import {
  getPlanUsageLimitRecoveryCopy,
  getSingleRecognizedPlanUsageLimitLabel,
} from '@/lib/billing/billing-limits'
import { useT } from '@/lib/i18n/translate'

type PlanUsageLimitProjectCurtainProps = {
  /** Organization id for upgrade / billing CTAs */
  teamId?: string | null
  billingLimits?: Record<string, number | string | null | undefined> | null
}

/**
 * Full-screen curtain when the organization has hit a cycle-consumed plan
 * usage limit (e.g. Free plan GB-hours). Blocks project-scoped pages until
 * the user upgrades or the billing cycle resets. Storage and users overage
 * do not use this curtain so customers can still delete resources.
 * Budget-cap blocks use {@link BudgetLimitProjectCurtain} instead.
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
    <FullScreenCurtain
      icon={Lock}
      title={t('Plan limit reached')}
      description={
        resourceLabel ? (
          <>
            {t('This organization has reached its plan limit for')}{' '}
            {t(resourceLabel)}
            {t(getPlanUsageLimitRecoveryCopy(billingLimits))}
          </>
        ) : (
          t(
            'This organization has reached its plan usage limit. API access to this project is suspended. Upgrade your plan or wait until the end of the billing cycle to restore service.',
          )
        )
      }
      actions={
        <>
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
        </>
      }
      footer={
        hasTeamId ? (
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
        ) : null
      }
    />
  )
}
