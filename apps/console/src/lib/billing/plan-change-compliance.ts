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
 * every plan-limited resource with its usage and limit, flagging the ones over
 * limit with `status: 'over_limit'`. A resource type absent from the list is
 * not capped by the target plan at all.
 */
export type PlanChangeResourceCompliance = Models.PlanChangeResourceCompliance
export type PlanChangeProjectCompliance = Models.PlanChangeProjectCompliance

/** Organization-level resources, capped per team rather than per project. */
export type OrganizationResourceType = 'projects' | 'members' | 'domains'

/** `limit` value meaning the target plan does not cap the resource. */
export const LIMIT_UNLIMITED = -1

/**
 * The response is already scoped to one organization, so the org-level caps sit
 * alongside `canChangePlan` rather than under a wrapper, and the per-project
 * array is `projectCompliance` so `projects` can carry the org-level cap.
 */
export type PlanChangeLimits = Models.PlanChangeLimits

const SELECTABLE_RESOURCE_TYPES = new Set<string>(
  DOWNGRADE_RESOURCE_TYPES.map(({ id }) => id),
)

function isSelectableResourceType(type: string): type is DowngradeResourceType {
  return SELECTABLE_RESOURCE_TYPES.has(type)
}

function isOverLimit(resource: PlanChangeResourceCompliance): boolean {
  return resource.status === 'over_limit'
}

export function getProjectCompliance(
  limits: PlanChangeLimits | null | undefined,
  projectId: string,
): PlanChangeProjectCompliance | null {
  return (
    limits?.projectCompliance?.find((project) => project.$id === projectId) ??
    null
  )
}

/**
 * Per-resource limits the server reports for a project, over limit or not.
 *
 * Resource types the server omits are not capped by the target plan, so they
 * carry no limit to display and need no selection.
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
 * Resources that are over limit and have no selection UI to resolve them (for
 * example `platforms` or `webhooks`). These still block the change, so they are
 * surfaced with the server's resolution hint instead of being silently dropped.
 *
 * Resources within limits are skipped: the server reports those too, and
 * treating them as blockers would wedge every downgrade.
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

  for (const project of limits?.projectCompliance ?? []) {
    for (const resource of project.resources ?? []) {
      if (!isOverLimit(resource)) continue
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
  return (limits?.projectCompliance ?? [])
    .filter((project) => !!project.error)
    .map((project) => ({
      projectId: project.$id,
      projectName: project.name || project.$id,
      error: project.error!,
    }))
}

const ORGANIZATION_RESOURCE_TYPES: OrganizationResourceType[] = [
  'projects',
  'members',
  'domains',
]

function getOrganizationResources(
  limits: PlanChangeLimits | null | undefined,
): PlanChangeResourceCompliance[] {
  if (!limits) return []
  return ORGANIZATION_RESOURCE_TYPES.map((type) => limits[type]).filter(
    (resource): resource is PlanChangeResourceCompliance => !!resource,
  )
}

/**
 * Organization-level caps (projects, members, domains) as reported by the
 * server, with uncapped resources omitted rather than passed through as the
 * unlimited sentinel.
 *
 * Returns null when the server reported none, which is the case for a console
 * running against an older cloud - callers fall back to deriving them from the
 * plan config.
 */
export function getOrganizationLimits(
  limits: PlanChangeLimits | null | undefined,
): Partial<Record<OrganizationResourceType, number>> | null {
  const result: Partial<Record<OrganizationResourceType, number>> = {}

  for (const type of ORGANIZATION_RESOURCE_TYPES) {
    const resource = limits?.[type]
    if (!resource) continue
    // Callers treat a missing limit as "no cap", which is what the sentinel
    // means - passing -1 through would read as a cap of minus one.
    if (resource.limit === LIMIT_UNLIMITED) continue
    result[type] = resource.limit
  }

  return Object.keys(result).length > 0 ? result : null
}

/** Over-limit organization resources, for surfacing the server's hints. */
export function getOrganizationViolations(
  limits: PlanChangeLimits | null | undefined,
): PlanChangeResourceCompliance[] {
  return getOrganizationResources(limits).filter(isOverLimit)
}

export function getNonCompliantProjectIds(
  limits: PlanChangeLimits | null | undefined,
): string[] {
  return (limits?.projectCompliance ?? [])
    .filter((project) => !project.isCompliant)
    .map((project) => project.$id)
}
