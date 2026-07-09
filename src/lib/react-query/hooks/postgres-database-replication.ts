import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'
import { postgresDatabaseQueryOptions } from './postgres-databases'

export async function fetchPostgresDatabaseReplicas(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabaseReplicas | null> {
  if (!projectId || !databaseId) return null
  try {
    return await sdk.forProject(projectId).postgresql.getReplicas({ databaseId })
  } catch {
    return null
  }
}

export function postgresDatabaseReplicasQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  enabled = true,
  refetchInterval?: number | false,
) {
  return queryOptions({
    queryKey: ['postgres-database-replicas', 'project', projectId, databaseId],
    queryFn: () => fetchPostgresDatabaseReplicas(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId && enabled,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchInterval,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresDatabaseReplicas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  enabled = true,
  refetchInterval?: number | false,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresDatabaseReplicasQueryOptions(
      projectId,
      databaseId,
      enabled,
      refetchInterval,
    ),
  )

  return {
    replicas: data ?? null,
    members: data?.members ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export async function createPostgresDatabaseFailover(
  projectId: string,
  databaseId: string,
  targetReplicaId: string,
) {
  return sdk.forProject(projectId).postgresql.createFailover({
    databaseId,
    targetReplicaId,
  })
}

export function useCreatePostgresDatabaseFailover(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ targetReplicaId }: { targetReplicaId: string }) =>
      createPostgresDatabaseFailover(projectId!, databaseId!, targetReplicaId),
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      await queryClient.invalidateQueries({
        queryKey: ['postgres-database-replicas', 'project', projectId, databaseId],
      })
    },
  })
}
