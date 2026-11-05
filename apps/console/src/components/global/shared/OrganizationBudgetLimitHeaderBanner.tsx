import { Link } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { useT } from '@/lib/i18n/translate'

type OrganizationBudgetLimitHeaderBannerProps = {
  organizationId: string | null | undefined
  show: boolean
}

export function OrganizationBudgetLimitHeaderBanner({
  organizationId,
  show,
}: OrganizationBudgetLimitHeaderBannerProps) {
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
          hash="update-budget"
          className={headerAlertOutlineButtonClass('danger')}
        >
          {t('Update limit')}
        </Link>
      }
    >
      {t(
        'This organization has reached its budget limit and is now blocked. To continue using Appwrite services, update the budget limit.',
      )}
    </HeaderAlertBar>
  )
}
