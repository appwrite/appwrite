import { Link } from '@tanstack/react-router'
import { ShieldCheck } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { useT } from '@/lib/i18n/translate'

type OrganizationInvoiceAuthorizeHeaderBannerProps = {
  organizationId: string | null | undefined
  show: boolean
}

export function OrganizationInvoiceAuthorizeHeaderBanner({
  organizationId,
  show,
}: OrganizationInvoiceAuthorizeHeaderBannerProps) {
  const t = useT()
  if (!show || !organizationId) return null

  return (
    <HeaderAlertBar
      variant="warning"
      icon={ShieldCheck}
      role="alert"
      action={
        <Link
          to="/organizations/$orgId/settings/billing"
          params={{ orgId: organizationId }}
          hash="payment-history"
          className={headerAlertOutlineButtonClass('warning')}
        >
          {t('Authorize payment')}
        </Link>
      }
    >
      {t(
        'Payment authorization required. Complete authentication now. Unresolved billing may interrupt your projects and services.',
      )}
    </HeaderAlertBar>
  )
}
