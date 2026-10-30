import { useQueries } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import type { Organization } from '@/lib/utils/mock-data'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canTransferProject } from '@/lib/console-access-checks'
import {
  deriveAccessFromRolesScopes,
  FULL_ACCESS,
  type ConsoleAccess,
} from '@/lib/console-roles'
import {
  canSeeOrganizationBilling,
  isOrganizationBillingReadonlyStatus,
  organizationBillingInvoicePresenceQueryOptions,
  organizationPlanQueryOptions,
  organizationScopesQueryOptions,
  useOrganizationBillingInvoicePresence,
} from './organizations'
import { activeProjectsTotalQueryOptions } from './projects'

/** Why an organization can't receive a transferred project. */
export type TransferDestinationBlock = 'permission' | 'billing' | 'projectLimit'

/** English labels for each block, translated where they render. */
export const TRANSFER_DESTINATION_BLOCK_LABELS: Record<
  TransferDestinationBlock,
  string
> = {
  permission: 'No permission',
  billing: 'Billing issue',
  projectLimit: 'Project limit reached',
}

export type TransferDestination = {
  organization: Organization
  /** `null` when the organization can receive the project. */
  block: TransferDestinationBlock | null
  isChecking: boolean
}

/** Same rule as the Create project button: the plan's project limit, 0 meaning unlimited. */
function isAtProjectLimit(
  plan: Models.BillingPlan | undefined,
  total: number | undefined,
): boolean {
  const limit = Number(
    plan?.addons?.projects?.limit ?? plan?.addons?.projects?.planIncluded,
  )
  return !Number.isNaN(limit) && limit > 0 && (total ?? 0) >= limit
}

/**
 * Check which organizations can receive a project: the member's role must manage
 * projects there, its billing must be in good standing, and its plan must have room.
 * Pass `enabled: false` to defer the requests until the destinations are shown.
 */
export function useTransferDestinations(
  organizations: Organization[],
  enabled = true,
): TransferDestination[] {
  const features = getActiveProfileFeatures()
  const checkBilling = enabled && features.billing

  const scopes = useQueries({
    queries: organizations.map((organization) => ({
      ...organizationScopesQueryOptions(organization.$id, null),
      enabled: enabled && features.orgRoles,
    })),
  })
  const access: ConsoleAccess[] = scopes.map((query) =>
    query.data
      ? deriveAccessFromRolesScopes(query.data.roles, query.data.scopes)
      : FULL_ACCESS,
  )
  const plans = useQueries({
    queries: organizations.map((organization) => ({
      ...organizationPlanQueryOptions(organization.$id),
      enabled: checkBilling,
    })),
  })
  const totals = useQueries({
    queries: organizations.map((organization) => ({
      ...activeProjectsTotalQueryOptions(organization.$id),
      enabled: checkBilling,
    })),
  })
  const invoices = useQueries({
    queries: organizations.map((organization, index) => ({
      ...organizationBillingInvoicePresenceQueryOptions(organization.$id),
      enabled:
        checkBilling &&
        !scopes[index]?.isLoading &&
        canSeeOrganizationBilling(access[index]),
    })),
  })

  return organizations.map((organization, index) => {
    const isChecking = [scopes, plans, totals, invoices].some(
      (queries) => queries[index]?.isLoading,
    )
    let block: TransferDestinationBlock | null = null
    if (!canTransferProject(access[index], features)) {
      block = 'permission'
    } else if (
      features.billing &&
      (isOrganizationBillingReadonlyStatus(organization.status) ||
        invoices[index]?.data?.hasFailedInvoice === true)
    ) {
      block = 'billing'
    } else if (
      checkBilling &&
      isAtProjectLimit(plans[index]?.data, totals[index]?.data?.total)
    ) {
      block = 'projectLimit'
    }
    return { organization, block, isChecking }
  })
}

/**
 * Whether the organization has billing issues that must be resolved before a project
 * leaves it: restricted after a failed payment, or a payment that failed or awaits authorization.
 */
export function useTransferSourceBillingIssue(
  organization: Organization | undefined,
): boolean {
  const { data: invoices } = useOrganizationBillingInvoicePresence(
    organization?.$id,
  )
  if (!getActiveProfileFeatures().billing) return false
  return (
    isOrganizationBillingReadonlyStatus(organization?.status) ||
    invoices?.hasFailedInvoice === true ||
    invoices?.hasInvoiceRequiringAuthentication === true
  )
}
