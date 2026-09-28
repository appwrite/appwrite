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

/** What an event changed, e.g. `function` for `function.update`, `variable` for `variable.delete`. */
function getChangeKind(event: Models.ActivityEvent): string {
  return event.event.slice(0, event.event.lastIndexOf('.'))
}

function toDrift(event: Models.ActivityEvent): TerraformDrift {
  return {
    time: event.time,
    event: event.event,
    actorName: event.actorName,
    actorType: event.actorType,
    userAgent: event.userAgent,
  }
}

/**
 * Builds the Terraform view of a project from its activity log. A resource is
 * managed once Terraform writes to it. A later write from anywhere else drifts
 * it until Terraform writes the same kind of change again, so a Terraform
 * deployment does not hide a manual settings change. Expects the newest-first
 * order the API returns.
 */
export function summarizeTerraformActivity(
  events: Models.ActivityEvent[],
): TerraformProject {
  const chronological = [...events]
    .reverse()
    .sort((a, b) => a.time.localeCompare(b.time))
  const resources: Record<string, TerraformResource> = {}
  const outstanding = new Map<string, Map<string, TerraformDrift>>()
  let latest: Models.ActivityEvent | null = null

  for (const event of chronological) {
    const path = event.resource
    if (!path || IGNORED_EVENT.test(event.event)) continue

    if (isTerraformActivity(event)) {
      latest = event
      if (isResourceDeletion(event)) {
        delete resources[path]
        outstanding.delete(path)
        continue
      }
      resources[path] = {
        resource: path,
        appliedAt: event.time,
        providerVersion: getTerraformProviderVersion(event),
        drift: null,
      }
      outstanding.get(path)?.delete(getChangeKind(event))
      continue
    }

    if (!resources[path]) continue
    if (isResourceDeletion(event)) {
      delete resources[path]
      outstanding.delete(path)
      continue
    }
    const drifts = outstanding.get(path) ?? new Map<string, TerraformDrift>()
    drifts.delete(getChangeKind(event))
    drifts.set(getChangeKind(event), toDrift(event))
    outstanding.set(path, drifts)
  }

  for (const [path, drifts] of outstanding) {
    const resource = resources[path]
    if (resource) resource.drift = [...drifts.values()].pop() ?? null
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

/** Browser and server clocks disagree slightly, so compare drift times loosely. */
const CLOCK_SKEW_MS = 2 * 60 * 1000

/** Stop waiting for the log after this long, so a lost event cannot pin a warning. */
const PENDING_DRIFT_TTL_MS = 10 * 60 * 1000

/**
 * Whether the activity log has caught up with a drift the console recorded
 * locally: the log shows that change (or a later one), Terraform has applied
 * since, the resource is no longer managed, or the log never caught up.
 */
export function isTerraformDriftSettled(
  resource: TerraformResource | undefined,
  drift: TerraformDrift,
  now: number = Date.now(),
): boolean {
  const recordedAt = Date.parse(drift.time)
  if (!resource) return true
  if (now - recordedAt > PENDING_DRIFT_TTL_MS) return true
  if (Date.parse(resource.appliedAt) > recordedAt + CLOCK_SKEW_MS) return true
  return (
    !!resource.drift &&
    Date.parse(resource.drift.time) >= recordedAt - CLOCK_SKEW_MS
  )
}
