/**
 * React Query hooks for resources the Appwrite Terraform provider manages.
 *
 * The provider tags every request it makes, so a project's activity log says
 * which resources it created and whether anything changed them since.
 */

import {
  queryOptions,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query'
import { useState } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { TERRAFORM_RESOURCE_TYPES } from '@/lib/terraform/resource'
import {
  isTerraformDriftSettled,
  markTerraformDrift,
  summarizeTerraformActivity,
  type TerraformDrift,
  type TerraformPendingDrift,
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
const pendingDrift = new Map<string, Map<string, TerraformPendingDrift>>()

function withPendingDrift(
  projectId: string,
  project: TerraformProject,
): TerraformProject {
  const pending = pendingDrift.get(projectId)
  if (!pending) return project
  let merged = project
  for (const [resource, change] of pending) {
    if (isTerraformDriftSettled(project.resources[resource], change)) {
      pending.delete(resource)
      continue
    }
    merged = markTerraformDrift(merged, resource, change.drift)
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
  const queryKey = terraformProjectQueryOptions(projectId).queryKey
  const managed = queryClient.getQueryData(queryKey)?.resources[resource]
  if (!managed) return
  const pending = pendingDrift.get(projectId) ?? new Map()
  pending.set(resource, {
    drift,
    lastConsoleOrApplyAt: managed.lastConsoleOrApplyAt,
    consoleOrApplyCount: managed.consoleOrApplyCount,
    recordedAt: Date.now(),
  })
  pendingDrift.set(projectId, pending)
  queryClient.setQueryData(queryKey, (project) =>
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
    // The app default drops unwatched queries at once; keep this for pages that mount later.
    gcTime: projectId ? 5 * 60 * 1000 : 0,
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

/**
 * Like `useTerraformResource`, but stays null unless the Terraform state was
 * already loaded when this resource first rendered. Layout-affecting UI
 * (banners) uses it so a slow or failing activity lookup never pops in after
 * first paint; switching to another resource decides again.
 */
export function useTerraformResourceOnMount(
  projectId: string | null | undefined,
  resource: string | null | undefined,
): TerraformResource | null {
  const queryClient = useQueryClient()
  const key = projectId && resource ? `${projectId}:${resource}` : null
  const loaded =
    !!projectId &&
    queryClient.getQueryData(
      terraformProjectQueryOptions(projectId).queryKey,
    ) !== undefined
  const [decision, setDecision] = useState({ key, show: loaded })
  if (decision.key !== key) setDecision({ key, show: loaded })
  const managed = useTerraformResource(projectId, resource)
  return decision.key === key && decision.show ? managed : null
}

/** How long a console write waits for ownership before using the last known state. */
const OWNERSHIP_LOOKUP_TIMEOUT_MS = 5000

/**
 * Fresh Terraform state for a resource about to be changed. The activity store
 * has no failover, so a hang or error falls back to the last known state
 * instead of holding the write.
 */
export async function lookupTerraformResource(
  queryClient: QueryClient,
  projectId: string,
  resource: string,
  timeoutMs: number = OWNERSHIP_LOOKUP_TIMEOUT_MS,
): Promise<TerraformResource | null> {
  const options = terraformProjectQueryOptions(projectId)
  const lastKnown = () =>
    queryClient.getQueryData(options.queryKey)?.resources[resource] ?? null
  let timer: ReturnType<typeof setTimeout> | undefined
  const timedOut = new Promise<null>((resolve) => {
    timer = setTimeout(() => resolve(null), timeoutMs)
  })
  try {
    const project = await Promise.race([
      queryClient.fetchQuery(options),
      timedOut,
    ])
    return project ? (project.resources[resource] ?? null) : lastKnown()
  } catch {
    return lastKnown()
  } finally {
    clearTimeout(timer)
  }
}
