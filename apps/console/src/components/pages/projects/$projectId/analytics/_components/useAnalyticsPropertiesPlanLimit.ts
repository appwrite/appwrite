import { useQuery } from '@tanstack/react-query'
import {
  analyticsPropertiesQueryOptions,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'

/** The plan's per-project property cap (0 = unlimited), counted without the list's search. */
export function useAnalyticsPropertiesPlanLimit(projectId: string | undefined) {
  const { project } = useProject(projectId)
  const { plan, isLoading: planLoading } = useOrganizationPlan(project?.teamId)
  const { data, isLoading: countLoading } = useQuery(
    analyticsPropertiesQueryOptions(projectId, 0, 1),
  )

  const currentCount = data?.total ?? 0
  const limit = plan?.analyticsProperties ?? 0
  const isAtLimit = limit > 0 && currentCount >= limit
  const planName = resolveOrganizationPlanDisplayLabel({
    planName: plan?.name ?? null,
    planId: plan?.$id,
  })

  return {
    currentCount,
    limit,
    isAtLimit,
    isLoaded: plan !== undefined && data !== undefined,
    // A failed query settles too: the server's 402 still guards the create.
    isChecking: !project || planLoading || countLoading,
    orgId: project?.teamId,
    planName,
  }
}
