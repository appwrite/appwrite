import type { Models } from '@appwrite.io/console'
import {
  DOWNGRADE_RESOURCE_TYPES,
  type DowngradeResourceType,
} from '@/lib/billing/downgrade-plan-limits'

/**
 * Server-side plan change compliance, as returned by
 * `POST /v1/organizations/:organizationId/plan/estimations`.
 *
 * The server is the authority on whether a plan change is allowed. It reports
 * only the resources that are *over* the target plan limit, so a resource type
 * missing from a project's `resources` list is within limits.
 */
export type PlanChangeLimits = Models.PlanChangeLimits
export type PlanChangeProjectCompliance = Models.PlanChangeProjectCompliance
export type PlanChangeResourceCompliance = Models.PlanChangeResourceCompliance

const SELECTABLE_RESOURCE_TYPES = new Set<string>(
  DOWNGRADE_RESOURCE_TYPES.map(({ id }) => id),
)

function isSelectableResourceType(type: string): type is DowngradeResourceType {
  return SELECTABLE_RESOURCE_TYPES.has(type)
}

export function getProjectCompliance(
  limits: PlanChangeLimits | null | undefined,
  projectId: string,
): PlanChangeProjectCompliance | null {
  return limits?.projects?.find((project) => project.$id === projectId) ?? null
}

/**
 * Per-resource limits the server flagged as exceeded for a project.
 *
 * Resource types the server did not flag are omitted: they are within the
 * target plan's limits and need no selection.
 */
export function getServerResourceLimits(
  limits: PlanChangeLimits | null | undefined,
  projectId: string,
): Partial<Record<DowngradeResourceType, number>> {
  const compliance = getProjectCompliance(limits, projectId)
  if (!compliance) return {}

  const result: Partial<Record<DowngradeResourceType, number>> = {}
  for (const resource of compliance.resources ?? []) {
    if (!isSelectableResourceType(resource.type)) continue
    result[resource.type] = resource.limit
  }

  return result
}

/**
 * Over-limit resources the console has no selection UI for (for example
 * `collections`). These still block the change, so they are surfaced with the
 * server's resolution hint instead of being silently dropped.
 */
export function getUnresolvableResources(
  limits: PlanChangeLimits | null | undefined,
): Array<{
  projectId: string
  projectName: string
  resource: PlanChangeResourceCompliance
}> {
  const entries: Array<{
    projectId: string
    projectName: string
    resource: PlanChangeResourceCompliance
  }> = []

  for (const project of limits?.projects ?? []) {
    for (const resource of project.resources ?? []) {
      if (isSelectableResourceType(resource.type)) continue
      entries.push({
        projectId: project.$id,
        projectName: project.name || project.$id,
        resource,
      })
    }
  }

  return entries
}

/**
 * Projects whose compliance could not be evaluated (project DB or Regions API
 * unreachable). The server fails closed on these, so the change stays blocked.
 */
export function getComplianceErrors(
  limits: PlanChangeLimits | null | undefined,
): Array<{ projectId: string; projectName: string; error: string }> {
  return (limits?.projects ?? [])
    .filter((project) => !!project.error)
    .map((project) => ({
      projectId: project.$id,
      projectName: project.name || project.$id,
      error: project.error!,
    }))
}

export function getNonCompliantProjectIds(
  limits: PlanChangeLimits | null | undefined,
): string[] {
  return (limits?.projects ?? [])
    .filter((project) => !project.isCompliant)
    .map((project) => project.$id)
}
