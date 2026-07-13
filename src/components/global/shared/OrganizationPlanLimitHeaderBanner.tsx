import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
  headerAlertTextButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { getSingleRecognizedPlanUsageLimitLabel } from '@/lib/billing/billing-limits'
import { useT } from '@/lib/i18n/translate'

type OrganizationPlanLimitHeaderBannerProps = {
  organizationId: string | null | undefined
  show: boolean
  billingLimits?: Record<string, number | string | null | undefined> | null
}

/**
 * Header alert when a free/starter (or other non-budget) plan usage limit is hit.
 * Cloud sets `billingLimits` with resource percentages (e.g. `{ GBHours: 333 }`).
 */
export function OrganizationPlanLimitHeaderBanner({
  organizationId,
  show,
  billingLimits,
}: OrganizationPlanLimitHeaderBannerProps) {
  const t = useT()
  if (!show || !organizationId) return null

  const resourceLabel = getSingleRecognizedPlanUsageLimitLabel(billingLimits)

  return (
    <HeaderAlertBar
      variant="danger"
      icon={AlertCircle}
      role="alert"
      action={
        <div className="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row sm:items-center">
          <Link
            to="/organizations/$orgId/settings/billing"
            params={{ orgId: organizationId }}
            hash="current-cycle-usage"
            className={headerAlertTextButtonClass('danger')}
          >
            {t('View current cycle usage')}
          </Link>
          <Link
            to="/upgrade"
            search={{ orgId: organizationId }}
            className={headerAlertOutlineButtonClass('danger')}
          >
            {t('Upgrade plan')}
          </Link>
        </div>
      }
    >
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
    </HeaderAlertBar>
  )
}
