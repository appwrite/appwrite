/**
 * React Query hooks for resources the Appwrite Terraform provider manages.
 *
 * The provider tags every request it makes, so a project's activity log says
 * which resources it created and whether anything changed them since.
 */

import { queryOptions, useQuery, type QueryClient } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { TERRAFORM_RESOURCE_TYPES } from '@/lib/terraform/resource'
import {
  isTerraformDriftSettled,
  markTerraformDrift,
  summarizeTerraformActivity,
  type TerraformDrift,
  type TerraformProject,
  type TerraformResource,
} from '@/lib/terraform/state'
import { ACTIVITY_DEFAULT_PAGE_SIZE, DEFAULT_STALE_TIME } from './constants'

/** End users never change resource configuration; only the console and API keys do. */
const TERRAFORM_ACTOR_TYPES = [
  'admin',
  'keyProject',
  'keyAccount',
  'keyOrganization',
  'appInstallation',
]

/** Reads every configuration change the project still retains, newest first. */
export async function fetchTerraformProject(
  projectId: string,
): Promise<TerraformProject> {
  const events: Models.ActivityEvent[] = []
  let cursor: string | null = null

  for (;;) {
    const queries = [
      Query.equal('resourceType', [...TERRAFORM_RESOURCE_TYPES]),
      Query.equal('actorType', TERRAFORM_ACTOR_TYPES),
      Query.orderDesc('time'),
      Query.limit(ACTIVITY_DEFAULT_PAGE_SIZE),
    ]
    if (cursor) queries.push(Query.cursorAfter(cursor))

    const page = await sdk
      .forProject(projectId)
      .activities.listEvents({ queries })
    const pageEvents = page.events ?? []
    events.push(...pageEvents)
    if (pageEvents.length < ACTIVITY_DEFAULT_PAGE_SIZE) break
    cursor = pageEvents[pageEvents.length - 1].$id
  }

  return withPendingDrift(projectId, summarizeTerraformActivity(events))
}

/** Console writes the activity log has not recorded yet, by project and resource. */
const pendingDrift = new Map<string, Map<string, TerraformDrift>>()

function withPendingDrift(
  projectId: string,
  project: TerraformProject,
): TerraformProject {
  const pending = pendingDrift.get(projectId)
  if (!pending) return project
  let merged = project
  for (const [resource, drift] of pending) {
    if (isTerraformDriftSettled(project.resources[resource], drift)) {
      pending.delete(resource)
      continue
    }
    merged = markTerraformDrift(merged, resource, drift)
  }
  return merged
}

/**
 * Marks a managed resource as changed outside Terraform right after a console
 * write. The activity log lags behind the write, so the drift is kept across
 * refetches until the log records it.
 */
export function recordTerraformDrift(
  queryClient: QueryClient,
  projectId: string,
  resource: string,
  drift: TerraformDrift,
): void {
  const pending = pendingDrift.get(projectId) ?? new Map()
  pending.set(resource, drift)
  pendingDrift.set(projectId, pending)
  queryClient.setQueryData(
    terraformProjectQueryOptions(projectId).queryKey,
    (project) =>
      project ? markTerraformDrift(project, resource, drift) : project,
  )
}

export function terraformProjectQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['terraform', 'project', projectId],
    queryFn: () => fetchTerraformProject(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    // Applies happen outside the console, so refresh stale state on mount and focus.
    refetchOnMount: true,
    refetchOnWindowFocus: true,
  })
}

export function useTerraformProject(projectId: string | null | undefined) {
  const { data } = useQuery(terraformProjectQueryOptions(projectId))
  return data ?? null
}

export function useTerraformResource(
  projectId: string | null | undefined,
  resource: string | null | undefined,
): TerraformResource | null {
  const project = useTerraformProject(resource ? projectId : null)
  return (resource && project?.resources[resource]) || null
}
