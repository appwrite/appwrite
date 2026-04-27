import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'

type OrganizationFailedInvoiceHeaderBannerProps = {
  organizationId: string | null | undefined
  show: boolean
}

export function OrganizationFailedInvoiceHeaderBanner({
  organizationId,
  show,
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
      Payment failed - act now. Unresolved billing may interrupt your projects
      and services.
    </HeaderAlertBar>
  )
}
