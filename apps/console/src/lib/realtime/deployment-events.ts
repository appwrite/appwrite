/**
 * Shared helpers for parsing site/function deployment realtime events.
 */

export type DeploymentResourceType = 'site' | 'function'

/** Parse `sites.{id}.deployments.*` or `functions.{id}.deployments.*` from event names. */
export function extractDeploymentResourceIdFromEvents(
  events: string[],
  resourceType: DeploymentResourceType,
): string | undefined {
  const prefix = resourceType === 'site' ? 'sites.' : 'functions.'
  for (const event of events) {
    const match = event.match(new RegExp(`^${prefix}([^.]+)\\.deployments\\.`))
    const id = match?.[1]
    if (id && id !== '*') return id
  }
  return undefined
}

export function resolveDeploymentResourceId(
  events: string[],
  resourceType: DeploymentResourceType,
  payload: Record<string, unknown> | null,
): string | undefined {
  const fromPayload = payload?.resourceId
  if (typeof fromPayload === 'string' && fromPayload.trim().length > 0) {
    return fromPayload
  }
  return extractDeploymentResourceIdFromEvents(events, resourceType)
}
