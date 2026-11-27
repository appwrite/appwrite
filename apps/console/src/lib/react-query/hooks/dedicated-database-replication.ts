/**
 * Replication / failover for dedicated databases (native engines + product APIs).
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  dedicatedReplicationService,
  dedicatedReplicationSourceKey,
  type DedicatedReplicationSource,
} from '@/lib/databases/dedicated-replication'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { DEFAULT_STALE_TIME } from './constants'
import {
  invalidateDatabaseModel,
  refetchProjectDatabaseLists,
  type ProductDatabaseIdentity,
} from './databases'
import { postgresDatabaseQueryOptions } from './postgres-databases'

export async function fetchDedicatedDatabaseReplicas(
  projectId: string,
  databaseId: string,
  source: DedicatedReplicationSource,
): Promise<Models.DedicatedDatabaseReplicas | null> {
  if (!projectId || !databaseId) return null
  try {
    return await dedicatedReplicationService(
      sdk.forProject(projectId),
      source,
    ).getReplicas({ databaseId })
  } catch {
    return null
  }
}

export function dedicatedDatabaseReplicasQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedReplicationSource,
  enabled = true,
  refetchInterval?: number | false,
) {
  return queryOptions({
    queryKey: [
      'dedicated-database-replicas',
      'project',
      projectId,
      databaseId,
      dedicatedReplicationSourceKey(source),
    ],
    queryFn: () =>
      fetchDedicatedDatabaseReplicas(projectId!, databaseId!, source),
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

export function useDedicatedDatabaseReplicas(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedReplicationSource,
  enabled = true,
  refetchInterval?: number | false,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    dedicatedDatabaseReplicasQueryOptions(
      projectId,
      databaseId,
      source,
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

export async function createDedicatedDatabaseFailover(
  projectId: string,
  databaseId: string,
  source: DedicatedReplicationSource,
  targetReplicaId: string,
) {
  return dedicatedReplicationService(
    sdk.forProject(projectId),
    source,
  ).createFailover({
    databaseId,
    targetReplicaId,
  })
}

export function useCreateDedicatedDatabaseFailover(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedReplicationSource,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ targetReplicaId }: { targetReplicaId: string }) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return createDedicatedDatabaseFailover(
        projectId!,
        databaseId!,
        source,
        targetReplicaId,
      )
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      queryClient.setQueryData(
        ['dedicated-database', 'project', projectId, databaseId],
        database,
      )
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: [
            'dedicated-database-replicas',
            'project',
            projectId,
            databaseId,
          ],
        }),
        // Legacy postgres query key (pre-generalization)
        queryClient.invalidateQueries({
          queryKey: [
            'postgres-database-replicas',
            'project',
            projectId,
            databaseId,
          ],
        }),
        queryClient.invalidateQueries({
          queryKey: ['database', 'project', projectId, databaseId],
        }),
        queryClient.invalidateQueries({
          queryKey: ['dedicated-database', 'project', projectId, databaseId],
        }),
      ])
    },
  })
}

export type DedicatedDatabaseHaSource =
  | Extract<DedicatedReplicationSource, { type: 'engine' }>
  | (Extract<DedicatedReplicationSource, { type: 'product' }> & {
      identity: ProductDatabaseIdentity
    })

export type UpdateDedicatedDatabaseHaInput = {
  replicas?: number
  syncMode?: string
}

/**
 * Update HA fields on the owning API for this database.
 *
 * Product-owned DBs (tablesDB / documentsDB / vectorsDB) must be updated via
 * the product SDK. Engine services reject mutations on product-owned IDs
 * ("reached only through their product APIs"). Product `update` accepts
 * `replicas` and `syncMode`.
 */
export async function updateDedicatedDatabaseHa(
  projectId: string,
  databaseId: string,
  source: DedicatedDatabaseHaSource,
  input: UpdateDedicatedDatabaseHaInput,
) {
  const service = dedicatedReplicationService(sdk.forProject(projectId), source)
  // Product and engine `update` overloads differ (e.g. required `name` on
  // documentsDB/vectorsDB). Narrow to the shared HA patch surface.
  const updateHa = service.update.bind(service) as (params: {
    databaseId: string
    name?: string
    enabled?: boolean
    replicas?: number
    syncMode?: string
  }) => Promise<Models.DedicatedDatabase>

  const patch = {
    ...(input.replicas != null ? { replicas: input.replicas } : {}),
    ...(input.syncMode != null ? { syncMode: input.syncMode } : {}),
  }

  if (source.type === 'product') {
    return updateHa({
      databaseId,
      name: source.identity.name.trim() || databaseId,
      enabled: source.identity.enabled,
      ...patch,
    })
  }

  return updateHa({ databaseId, ...patch })
}

export function useUpdateDedicatedDatabaseHa(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  source: DedicatedDatabaseHaSource,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: UpdateDedicatedDatabaseHaInput) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return updateDedicatedDatabaseHa(projectId!, databaseId!, source, input)
    },
    onSuccess: async (database) => {
      if (!projectId || !databaseId) return
      queryClient.setQueryData(
        postgresDatabaseQueryOptions(projectId, databaseId).queryKey,
        database,
      )
      queryClient.setQueryData(
        ['dedicated-database', 'project', projectId, databaseId],
        database,
      )
      invalidateDatabaseModel(projectId, databaseId)
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['database', 'project', projectId, databaseId],
        }),
        queryClient.invalidateQueries({
          queryKey: [
            'dedicated-database-replicas',
            'project',
            projectId,
            databaseId,
          ],
        }),
        queryClient.invalidateQueries({
          queryKey: [
            'postgres-database-replicas',
            'project',
            projectId,
            databaseId,
          ],
        }),
        refetchProjectDatabaseLists(queryClient, projectId),
      ])
    },
  })
}
