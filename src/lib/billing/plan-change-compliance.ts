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

export type PlanChangeOrganizationCompliance = {
  isCompliant: boolean
  resources: PlanChangeResourceCompliance[]
}

/**
 * `organization` is declared locally because the console SDK is regenerated
 * from the cloud spec on its own cadence and does not carry the field yet.
 * Drop this extension once the generated model includes it.
 */
export type PlanChangeLimits = Models.PlanChangeLimits & {
  organization?: PlanChangeOrganizationCompliance
}

/** Organization-level resources, capped per team rather than per project. */
export type OrganizationResourceType = 'projects' | 'members' | 'domains'

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
  return limits?.projects?.find((project) => project.$id === projectId) ?? null
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

  for (const project of limits?.projects ?? []) {
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
  return (limits?.projects ?? [])
    .filter((project) => !!project.error)
    .map((project) => ({
      projectId: project.$id,
      projectName: project.name || project.$id,
      error: project.error!,
    }))
}

/**
 * Organization-level limits (projects, members, domains) as reported by the
 * server. Returns null when the server did not report them, which is the case
 * for upgrades and for a console running against an older cloud - callers fall
 * back to deriving them from the plan config.
 */
export function getOrganizationLimits(
  limits: PlanChangeLimits | null | undefined,
): Partial<Record<OrganizationResourceType, number>> | null {
  const resources = limits?.organization?.resources
  if (!resources?.length) return null

  const result: Partial<Record<OrganizationResourceType, number>> = {}
  for (const resource of resources) {
    if (
      resource.type === 'projects' ||
      resource.type === 'members' ||
      resource.type === 'domains'
    ) {
      result[resource.type] = resource.limit
    }
  }

  return Object.keys(result).length > 0 ? result : null
}

/** Over-limit organization resources, for surfacing the server's hints. */
export function getOrganizationViolations(
  limits: PlanChangeLimits | null | undefined,
): PlanChangeResourceCompliance[] {
  return (limits?.organization?.resources ?? []).filter(isOverLimit)
}

export function getNonCompliantProjectIds(
  limits: PlanChangeLimits | null | undefined,
): string[] {
  return (limits?.projects ?? [])
    .filter((project) => !project.isCompliant)
    .map((project) => project.$id)
}
