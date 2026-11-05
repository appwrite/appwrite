import type { QueryClient, QueryKey } from '@tanstack/react-query'

/**
 * True when a React Query key is scoped to a project id (project id appears as a segment).
 * Matches all conventions in hooks, e.g. ['buckets', 'project', id], ['apiKeys', id],
 * ['usage-gauges', 'storage', 'project', id].
 */
export function isProjectScopedQueryKey(
  queryKey: QueryKey,
  projectId: string,
): boolean {
  return Array.isArray(queryKey) && queryKey.includes(projectId)
}

function isOrganizationProjectsListQueryKey(queryKey: QueryKey): boolean {
  if (!Array.isArray(queryKey)) return false
  return (
    queryKey[0] === 'projects' ||
    (queryKey[0] === 'organization' && queryKey[1] === 'projects')
  )
}

/**
 * Refetch cached queries for a project after resume/unpause so errored requests retry.
 * Uses type: 'all' so inactive queries (refetchOnMount: false) are included.
 */
export async function refetchProjectScopedQueries(
  queryClient: QueryClient,
  projectId: string,
): Promise<void> {
  await queryClient.refetchQueries({
    predicate: (query) => isProjectScopedQueryKey(query.queryKey, projectId),
    type: 'all',
  })

  // Project lists/pickers can exclude paused projects; refresh them too.
  await queryClient.refetchQueries({
    predicate: (query) => isOrganizationProjectsListQueryKey(query.queryKey),
    type: 'all',
  })
}
