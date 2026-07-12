import { useMutation, useQuery, useQueryClient, queryOptions } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { requireOperationalDatabase } from '@/lib/databases/dedicated-database-write-lock'
import { DEFAULT_STALE_TIME } from './constants'

const EXTENSIONS_POLL_INTERVAL_MS = 3000

export async function fetchPostgresDatabaseExtensions(
  projectId: string,
  databaseId: string,
): Promise<Models.DedicatedDatabaseExtensions> {
  return sdk.forProject(projectId).postgresql.listExtensions({ databaseId })
}

export function postgresDatabaseExtensionsQueryOptions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { pollWhilePending?: boolean },
) {
  return queryOptions({
    queryKey: ['postgres-database-extensions', 'project', projectId, databaseId],
    queryFn: () =>
      fetchPostgresDatabaseExtensions(projectId!, databaseId!),
    enabled: !!projectId && !!databaseId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    refetchInterval: options?.pollWhilePending
      ? EXTENSIONS_POLL_INTERVAL_MS
      : false,
    gcTime: projectId && databaseId ? 5 * 60 * 1000 : 0,
  })
}

export function usePostgresDatabaseExtensions(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
  options?: { pollWhilePending?: boolean },
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery(
    postgresDatabaseExtensionsQueryOptions(projectId, databaseId, options),
  )

  return {
    installed: data?.installed ?? [],
    available: data?.available ?? [],
    metadata: data?.metadata ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

export async function installPostgresDatabaseExtension(
  projectId: string,
  databaseId: string,
  name: string,
) {
  return sdk.forProject(projectId).postgresql.createExtension({
    databaseId,
    name,
  })
}

export async function uninstallPostgresDatabaseExtension(
  projectId: string,
  databaseId: string,
  extensionName: string,
) {
  return sdk.forProject(projectId).postgresql.deleteExtension({
    databaseId,
    extensionName,
  })
}

export function useInstallPostgresDatabaseExtension(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (name: string) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return installPostgresDatabaseExtension(projectId!, databaseId!, name)
    },
    onSuccess: async () => {
      if (!projectId || !databaseId) return
      await queryClient.invalidateQueries({
        queryKey: ['postgres-database-extensions', 'project', projectId, databaseId],
      })
    },
  })
}

export function useUninstallPostgresDatabaseExtension(
  projectId: string | null | undefined,
  databaseId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (extensionName: string) => {
      requireOperationalDatabase(queryClient, projectId!, databaseId!)
      return uninstallPostgresDatabaseExtension(
        projectId!,
        databaseId!,
        extensionName,
      )
    },
    onSuccess: async () => {
      if (!projectId || !databaseId) return
      await queryClient.invalidateQueries({
        queryKey: ['postgres-database-extensions', 'project', projectId, databaseId],
      })
    },
  })
}
