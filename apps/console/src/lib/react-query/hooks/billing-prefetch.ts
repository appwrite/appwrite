import type { QueryClient } from '@tanstack/react-query'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  BILLING_CREDITS_PAGE_SIZE,
  BILLING_INVOICES_PAGE_SIZE,
  DEFAULT_BILLING_PROJECTS_LIMIT,
} from './constants'
import {
  billingAddressQueryOptions,
  billingAddressesQueryOptions,
  canSeeOrganizationBilling,
  organizationBillingAggregationQueryOptions,
  organizationCreditsQueryOptions,
  organizationInvoicesQueryOptions,
  organizationPaymentMethodQueryOptions,
  organizationPlanQueryOptions,
  organizationQueryOptions,
  organizationsQueryOptions,
  paymentMethodsQueryOptions,
  resolveOrganizationAccess,
} from './organizations'

type BillingOrganization = {
  billingAggregationId?: string | null
  paymentMethodId?: string | null
  backupPaymentMethodId?: string | null
  billingAddressId?: string | null
}

export type PrefetchOrganizationBillingTabOptions = {
  /**
   * When true, wait for usage aggregation so PlanSummary does not mount in a
   * loading state. The org billing route leaves this false so a slow usage API
   * does not block the rest of the billing page.
   */
  waitForAggregation?: boolean
}

/**
 * Prefetch the queries BillingTab and its cards subscribe to.
 * Query keys and page sizes must match the org settings billing loader and hooks.
 */
export async function prefetchOrganizationBillingTab(
  queryClient: QueryClient,
  orgId: string,
  options?: PrefetchOrganizationBillingTabOptions,
): Promise<void> {
  if (!orgId || typeof window === 'undefined') return

  const features = getActiveProfileFeatures()
  if (!features.billing) {
    await resolveOrganizationAccess(queryClient, orgId).catch(() => undefined)
    return
  }

  queryClient.prefetchQuery(organizationsQueryOptions()).catch(() => {})

  const [orgData] = await Promise.all([
    queryClient.ensureQueryData(organizationQueryOptions(orgId)),
  ])

  const billingAccess = await resolveOrganizationAccess(queryClient, orgId)
  const canFetchBillingInvoices = canSeeOrganizationBilling(billingAccess)

  const billingOrg = orgData as BillingOrganization | undefined

  await Promise.all([
    queryClient.ensureQueryData(organizationPlanQueryOptions(orgId)),
    ...(canFetchBillingInvoices
      ? [
          queryClient.ensureQueryData(
            organizationInvoicesQueryOptions(
              orgId,
              0,
              BILLING_INVOICES_PAGE_SIZE,
            ),
          ),
        ]
      : []),
    queryClient.ensureQueryData(organizationCreditsQueryOptions(orgId, 0, 1)),
    queryClient.ensureQueryData(
      organizationCreditsQueryOptions(orgId, 0, BILLING_CREDITS_PAGE_SIZE),
    ),
    queryClient.ensureQueryData(paymentMethodsQueryOptions()),
    queryClient.ensureQueryData(billingAddressesQueryOptions()),
  ])

  const aggregationOptions = billingOrg?.billingAggregationId
    ? organizationBillingAggregationQueryOptions(
        orgId,
        billingOrg.billingAggregationId,
        DEFAULT_BILLING_PROJECTS_LIMIT,
        0,
      )
    : null

  if (aggregationOptions) {
    if (options?.waitForAggregation) {
      await queryClient.ensureQueryData(aggregationOptions).catch(() => undefined)
    } else {
      void queryClient.prefetchQuery(aggregationOptions).catch(() => undefined)
    }
  }

  const optionalPrefetches = []
  if (billingOrg?.paymentMethodId) {
    optionalPrefetches.push(
      queryClient
        .ensureQueryData(
          organizationPaymentMethodQueryOptions(
            orgId,
            billingOrg.paymentMethodId,
          ),
        )
        .catch(() => {}),
    )
  }
  if (billingOrg?.backupPaymentMethodId) {
    optionalPrefetches.push(
      queryClient
        .ensureQueryData(
          organizationPaymentMethodQueryOptions(
            orgId,
            billingOrg.backupPaymentMethodId,
          ),
        )
        .catch(() => {}),
    )
  }
  if (billingOrg?.billingAddressId) {
    optionalPrefetches.push(
      queryClient
        .ensureQueryData(billingAddressQueryOptions(billingOrg.billingAddressId))
        .catch(() => {}),
    )
  }
  if (optionalPrefetches.length > 0) {
    await Promise.all(optionalPrefetches)
  }
}
