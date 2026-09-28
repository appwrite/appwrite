/**
 * React Query hooks for resources the Appwrite Terraform provider manages.
 *
 * The provider tags every request it makes, so a project's activity log says
 * which resources it created and whether anything changed them since.
 */

import { queryOptions, useQuery } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { TERRAFORM_RESOURCE_TYPES } from '@/lib/terraform/resource'
import {
  summarizeTerraformActivity,
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

  return summarizeTerraformActivity(events)
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
