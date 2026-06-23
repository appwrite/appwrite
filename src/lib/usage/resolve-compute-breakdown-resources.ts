import { fetchProjectFunctionsByIds } from '@/lib/react-query/hooks/functions'
import { fetchProjectSitesByIds } from '@/lib/react-query/hooks/sites'
import { COMPUTE_BREAKDOWN_RESOURCE_LIMIT } from '@/lib/usage/breakdown-limits'

export type ComputeBreakdownResourceType = 'function' | 'site'

export interface ComputeBreakdownResource {
  id: string
  name: string
  type: ComputeBreakdownResourceType
}

export type ComputeBreakdownResourceMap = Record<
  string,
  ComputeBreakdownResource
>

export function normalizeComputeBreakdownResourceIds(
  resourceIds: string[],
): string[] {
  return [
    ...new Set(resourceIds.filter((id) => typeof id === 'string' && id.trim())),
  ].slice(0, COMPUTE_BREAKDOWN_RESOURCE_LIMIT)
}

/**
 * Resolve usage breakdown resource IDs to functions/sites (one list call each).
 */
export async function fetchComputeBreakdownResources(
  projectId: string,
  resourceIds: string[],
): Promise<{ resources: ComputeBreakdownResourceMap }> {
  const ids = normalizeComputeBreakdownResourceIds(resourceIds)
  if (!projectId || ids.length === 0) {
    return { resources: {} }
  }

  const [functionsResult, sitesResult] = await Promise.all([
    fetchProjectFunctionsByIds(projectId, ids).catch(() => ({
      functions: [],
    })),
    fetchProjectSitesByIds(projectId, ids).catch(() => ({ sites: [] })),
  ])

  const resources: ComputeBreakdownResourceMap = {}

  for (const fn of functionsResult.functions) {
    resources[fn.$id] = {
      id: fn.$id,
      name: fn.name,
      type: 'function',
    }
  }

  for (const site of sitesResult.sites) {
    resources[site.$id] = {
      id: site.$id,
      name: site.name,
      type: 'site',
    }
  }

  return { resources }
}

export function getComputeBreakdownResourceTypeLabel(
  type: ComputeBreakdownResourceType,
): string {
  return type === 'function' ? 'Functions' : 'Sites'
}

export function getComputeBreakdownResourceRoute(
  projectId: string,
  resource: ComputeBreakdownResource,
): {
  to: '/projects/$projectId/functions/$functionId' | '/projects/$projectId/sites/$siteId'
  params: { projectId: string; functionId: string } | { projectId: string; siteId: string }
} {
  if (resource.type === 'function') {
    return {
      to: '/projects/$projectId/functions/$functionId',
      params: { projectId, functionId: resource.id },
    }
  }

  return {
    to: '/projects/$projectId/sites/$siteId',
    params: { projectId, siteId: resource.id },
  }
}

export function resolveComputeBreakdownResource(
  resourceId: string,
  lookup?: ComputeBreakdownResourceMap | null,
): ComputeBreakdownResource | undefined {
  if (!lookup) return undefined
  const trimmed = resourceId.trim()
  if (!trimmed) return undefined
  return lookup[trimmed]
}
