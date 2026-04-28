import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'

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
          Fix payment
        </Link>
      }
    >
      {orgBillingReadonly
        ? 'Payment failed - your organization is in read-only mode due to an unresolved billing issue. Changes to projects and services are restricted until payment succeeds. Update billing to restore full access.'
        : 'Payment failed - act now. Unresolved billing may interrupt your projects and services.'}
    </HeaderAlertBar>
  )
}
