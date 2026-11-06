import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { useT } from '@/lib/i18n/translate'

type OrganizationFailedInvoiceHeaderBannerProps = {
  organizationId: string | null | undefined
  show: boolean
  /** Organization billing status is read-only; messaging reflects active disruption */
  orgBillingReadonly?: boolean
}

export function OrganizationFailedInvoiceHeaderBanner({
  organizationId,
  show,
  orgBillingReadonly,
}: OrganizationFailedInvoiceHeaderBannerProps) {
  const t = useT()
  if (!show || !organizationId) return null

  return (
    <HeaderAlertBar
      variant="danger"
      icon={AlertCircle}
      role="alert"
      action={
        <Link
          to="/organizations/$orgId/settings/billing"
          params={{ orgId: organizationId }}
          className={headerAlertOutlineButtonClass('danger')}
        >
          {t('Fix payment')}
        </Link>
      }
    >
      {orgBillingReadonly
        ? t(
            'Payment failed - your organization has restricted access due to an unresolved billing issue. Changes to projects and services are restricted until payment succeeds. Update billing to restore full access.',
          )
        : t(
            'Payment failed - act now. Unresolved billing may interrupt your projects and services.',
          )}
    </HeaderAlertBar>
  )
}
