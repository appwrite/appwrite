import { useQuery } from '@tanstack/react-query'
import {
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  organizationDomainsQueryOptions,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'

export function useOrganizationDomainsPlanLimit(
  orgId: string | null | undefined,
) {
  const { plan } = useOrganizationPlan(orgId)
  const { data } = useQuery(
    organizationDomainsQueryOptions(
      orgId,
      0,
      1,
      undefined,
      undefined,
      DOMAINS_DEFAULT_SORT_BY,
      DOMAINS_DEFAULT_SORT_ORDER,
    ),
  )

  const currentCount = data?.total ?? 0
  const limit = plan?.domains ?? 0
  const isAtLimit = limit > 0 && currentCount >= limit
  const planName = resolveOrganizationPlanDisplayLabel({
    planName: plan?.name ?? null,
    planId: plan?.$id,
  })

  return {
    currentCount,
    limit,
    isAtLimit,
    plan,
    planName,
  }
}
