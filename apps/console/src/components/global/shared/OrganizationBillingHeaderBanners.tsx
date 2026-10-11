import { useQuery } from '@tanstack/react-query'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  isBudgetLimitReached,
  isOrganizationBillingReadonlyStatus,
  isPlanUsageLimitReached,
  organizationQueryOptions,
  useOrganizationBillingInvoicePresence,
} from '@/lib/react-query/hooks/organizations'
import { OrganizationBudgetLimitHeaderBanner } from '@/components/global/shared/OrganizationBudgetLimitHeaderBanner'
import { OrganizationFailedInvoiceHeaderBanner } from '@/components/global/shared/OrganizationFailedInvoiceHeaderBanner'
import { OrganizationInvoiceAuthorizeHeaderBanner } from '@/components/global/shared/OrganizationInvoiceAuthorizeHeaderBanner'
import { OrganizationPlanLimitHeaderBanner } from '@/components/global/shared/OrganizationPlanLimitHeaderBanner'

type OrganizationBillingHeaderBannersProps = {
  organizationId: string | null | undefined
}

/**
 * Org-scoped billing alerts for ConsoleLayout `headerBanner`.
 * Self-fetches so every org chrome (overview tabs, domain detail, apps) stays in sync.
 */
export function OrganizationBillingHeaderBanners({
  organizationId,
}: OrganizationBillingHeaderBannersProps) {
  const { features } = useConsoleProfile()
  const { data: organization } = useQuery(
    organizationQueryOptions(organizationId),
  )
  const { data: invoicePresence } =
    useOrganizationBillingInvoicePresence(organizationId)

  const showFailedInvoice =
    features.billing && invoicePresence?.hasFailedInvoice === true
  const showInvoiceAuthorization =
    features.billing &&
    !showFailedInvoice &&
    invoicePresence?.hasInvoiceRequiringAuthentication === true
  const showBudgetLimit =
    features.billing && isBudgetLimitReached(organization)
  // Budget cap takes precedence; plan overage is the free/starter path
  const showPlanUsageLimit =
    features.billing &&
    !showBudgetLimit &&
    isPlanUsageLimitReached(organization)
  const orgBillingReadonly =
    showFailedInvoice &&
    isOrganizationBillingReadonlyStatus(organization?.status)

  if (
    !organizationId ||
    (!showFailedInvoice &&
      !showInvoiceAuthorization &&
      !showBudgetLimit &&
      !showPlanUsageLimit)
  ) {
    return null
  }

  return (
    <>
      <OrganizationFailedInvoiceHeaderBanner
        organizationId={organizationId}
        show={showFailedInvoice}
        orgBillingReadonly={orgBillingReadonly}
      />
      <OrganizationInvoiceAuthorizeHeaderBanner
        organizationId={organizationId}
        show={showInvoiceAuthorization}
      />
      <OrganizationBudgetLimitHeaderBanner
        organizationId={organizationId}
        show={showBudgetLimit}
      />
      <OrganizationPlanLimitHeaderBanner
        organizationId={organizationId}
        show={showPlanUsageLimit}
        billingLimits={organization?.billingLimits}
      />
    </>
  )
}
