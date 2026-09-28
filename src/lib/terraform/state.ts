import type { Models } from '@appwrite.io/console'
import {
  getTerraformProviderVersion,
  isTerraformActivity,
} from '@/lib/terraform/activity'

/** The latest write to a managed resource that did not come from Terraform. */
export type TerraformDrift = {
  time: string
  event: string
  actorName: string
  actorType: string
  userAgent: string
}

export type TerraformResource = {
  resource: string
  appliedAt: string
  providerVersion: string | null
  drift: TerraformDrift | null
}

export type TerraformProject = {
  resources: Record<string, TerraformResource>
  appliedAt: string | null
  providerVersion: string | null
  keyName: string | null
}

/** Running a function does not change its configuration. */
const IGNORED_EVENT = /^execution\./

function isResourceDeletion(event: Models.ActivityEvent): boolean {
  return event.event === `${event.resourceType}.delete`
}

/**
 * Builds the Terraform view of a project from its activity log. A resource is
 * managed once Terraform writes to it, and drifts when a later write comes from
 * anywhere else. Expects the newest-first order the API returns.
 */
export function summarizeTerraformActivity(
  events: Models.ActivityEvent[],
): TerraformProject {
  const chronological = [...events]
    .reverse()
    .sort((a, b) => a.time.localeCompare(b.time))
  const resources: Record<string, TerraformResource> = {}
  let latest: Models.ActivityEvent | null = null

  for (const event of chronological) {
    const path = event.resource
    if (!path || IGNORED_EVENT.test(event.event)) continue

    if (isTerraformActivity(event)) {
      latest = event
      if (isResourceDeletion(event)) {
        delete resources[path]
        continue
      }
      resources[path] = {
        resource: path,
        appliedAt: event.time,
        providerVersion: getTerraformProviderVersion(event),
        drift: null,
      }
      continue
    }

    const managed = resources[path]
    if (!managed) continue
    if (isResourceDeletion(event)) {
      delete resources[path]
      continue
    }
    managed.drift = {
      time: event.time,
      event: event.event,
      actorName: event.actorName,
      actorType: event.actorType,
      userAgent: event.userAgent,
    }
  }

  return {
    resources,
    appliedAt: latest?.time ?? null,
    providerVersion: latest ? getTerraformProviderVersion(latest) : null,
    keyName: latest?.actorType.startsWith('key') ? latest.actorName : null,
  }
}

export function isTerraformProjectManaged(
  project: TerraformProject | null | undefined,
): boolean {
  return !!project && Object.keys(project.resources).length > 0
}

export function markTerraformDrift(
  project: TerraformProject,
  resource: string,
  drift: TerraformDrift,
): TerraformProject {
  const managed = project.resources[resource]
  if (!managed) return project
  return {
    ...project,
    resources: { ...project.resources, [resource]: { ...managed, drift } },
  }
}
