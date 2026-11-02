import { useQueries } from '@tanstack/react-query'
import type { Organization } from '@/lib/utils/mock-data'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { canReceiveDomain } from '@/lib/console-access-checks'
import { deriveAccessFromRolesScopes, FULL_ACCESS } from '@/lib/console-roles'
import {
  organizationPlanQueryOptions,
  organizationScopesQueryOptions,
} from './organizations'
import { organizationDomainsQueryOptions } from './domains'
import type {
  TransferDestination,
  TransferDestinationBlock,
} from './project-transfers'

/**
 * Check which organizations can receive a domain: the member must own it, and
 * its plan must have room for another domain, as when adding one there.
 * Pass `enabled: false` to defer the requests until the destinations are shown.
 */
export function useDomainTransferDestinations(
  organizations: Organization[],
  enabled = true,
): TransferDestination[] {
  const features = getActiveProfileFeatures()
  const checkLimit = enabled && features.billing

  const scopes = useQueries({
    queries: organizations.map((organization) => ({
      ...organizationScopesQueryOptions(organization.$id, null),
      enabled: enabled && features.orgRoles,
    })),
  })
  const plans = useQueries({
    queries: organizations.map((organization) => ({
      ...organizationPlanQueryOptions(organization.$id),
      enabled: checkLimit,
    })),
  })
  const domains = useQueries({
    queries: organizations.map((organization) => ({
      ...organizationDomainsQueryOptions(organization.$id, 0, 1),
      enabled: checkLimit,
    })),
  })

  return organizations.map((organization, index) => {
    const isChecking = [scopes, plans, domains].some(
      (queries) => queries[index]?.isLoading,
    )
    const roles = scopes[index]?.data
    const access = roles
      ? deriveAccessFromRolesScopes(roles.roles, roles.scopes)
      : FULL_ACCESS
    // Same rule as Add domain: the plan's domain limit, 0 meaning unlimited
    const limit = plans[index]?.data?.domains ?? 0
    const total = domains[index]?.data?.total ?? 0

    let block: TransferDestinationBlock | null = null
    if (!canReceiveDomain(access, features)) {
      block = 'ownerRole'
    } else if (checkLimit && limit > 0 && total >= limit) {
      block = 'domainLimit'
    }
    return { organization, block, isChecking }
  })
}
