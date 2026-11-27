/**
 * React Query hooks for Sandboxes
 *
 * Handles the project's sandboxes and the files and processes inside one.
 */

import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import type {
  Sandbox,
  SandboxFileList,
  SandboxImage,
  SandboxList,
} from '@/lib/appwrite/sandboxes'
import { DEFAULT_STALE_TIME } from './constants'

/** A sandbox is claimed from a warm pool or created cold, so poll until it settles. */
const SANDBOX_POLL_INTERVAL = 3 * 1000

export function isSandboxSettling(sandbox: Pick<Sandbox, 'status'>): boolean {
  return sandbox.status === 'creating' || sandbox.status === 'deleting'
}

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/** Query function to fetch a page of sandboxes, newest first. */
export async function fetchSandboxes(
  projectId: string,
  page: number,
  limit: number,
): Promise<SandboxList> {
  if (!projectId) {
    return { sandboxes: [], total: 0 }
  }

  return await sdk.forProject(projectId).sandboxes.list({
    queries: [
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
}

export async function fetchSandbox(
  projectId: string,
  sandboxId: string,
): Promise<Sandbox> {
  if (!projectId || !sandboxId) {
    throw new Error('Project ID and Sandbox ID are required')
  }
  return await sdk.forProject(projectId).sandboxes.get({ sandboxId })
}

export async function fetchSandboxFiles(
  projectId: string,
  sandboxId: string,
  path: string,
): Promise<SandboxFileList> {
  return await sdk
    .forProject(projectId)
    .sandboxes.listFiles({ sandboxId, path })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

export function sandboxesQueryOptions(
  projectId: string | null | undefined,
  page: number,
  limit: number,
) {
  return queryOptions({
    queryKey: ['sandboxes', 'project', projectId, page, limit],
    queryFn: () => fetchSandboxes(projectId!, page, limit),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    refetchInterval: (query) =>
      query.state.data?.sandboxes.some(isSandboxSettling)
        ? SANDBOX_POLL_INTERVAL
        : false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function sandboxQueryOptions(
  projectId: string | null | undefined,
  sandboxId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['sandbox', 'project', projectId, sandboxId],
    queryFn: () => fetchSandbox(projectId!, sandboxId!),
    enabled: !!projectId && !!sandboxId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnWindowFocus: false,
    refetchInterval: (query) =>
      query.state.data && isSandboxSettling(query.state.data)
        ? SANDBOX_POLL_INTERVAL
        : false,
  })
}

export function sandboxFilesQueryOptions(
  projectId: string | null | undefined,
  sandboxId: string | null | undefined,
  path: string,
) {
  return queryOptions({
    queryKey: ['sandbox-files', 'project', projectId, sandboxId, path],
    queryFn: () => fetchSandboxFiles(projectId!, sandboxId!, path),
    enabled: !!projectId && !!sandboxId,
    retry: false,
    refetchOnWindowFocus: false,
    placeholderData: keepPreviousData,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

export function useSandboxes(
  projectId: string | null | undefined,
  page: number,
  limit: number,
) {
  const { data, isLoading, isFetching, error } = useQuery(
    sandboxesQueryOptions(projectId, page, limit),
  )

  return {
    sandboxes: data?.sandboxes ?? [],
    total: data?.total ?? 0,
    isLoading,
    isFetching,
    error,
  }
}

export function useSandbox(
  projectId: string | null | undefined,
  sandboxId: string | null | undefined,
) {
  const { data, isLoading, error } = useQuery(
    sandboxQueryOptions(projectId, sandboxId),
  )

  return { sandbox: data, isLoading, error }
}

export function useSandboxFiles(
  projectId: string | null | undefined,
  sandboxId: string | null | undefined,
  path: string,
  enabled = true,
) {
  const { data, isLoading, isFetching, error, refetch } = useQuery({
    ...sandboxFilesQueryOptions(projectId, sandboxId, path),
    enabled: enabled && !!projectId && !!sandboxId,
  })

  return {
    files: data?.files ?? [],
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

// ============================================================================
// MUTATIONS
// ============================================================================

export function useCreateSandbox(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (params: {
      sandboxId: string
      image: SandboxImage
      specification?: string
      idleTimeout?: number
      variables?: Record<string, string>
    }) => sdk.forProject(projectId!).sandboxes.create(params),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['sandboxes', 'project', projectId],
      })
    },
  })
}

export function useDeleteSandbox(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (sandboxId: string) =>
      sdk.forProject(projectId!).sandboxes.delete({ sandboxId }),
    onSuccess: async (_, sandboxId) => {
      queryClient.removeQueries({
        queryKey: ['sandbox', 'project', projectId, sandboxId],
      })
      await queryClient.refetchQueries({
        queryKey: ['sandboxes', 'project', projectId],
      })
    },
  })
}

export function useUploadSandboxFile(
  projectId: string | null | undefined,
  sandboxId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (params: { path: string; file: File }) =>
      sdk
        .forProject(projectId!)
        .sandboxes.createFile({ sandboxId: sandboxId!, ...params }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['sandbox-files', 'project', projectId, sandboxId],
      })
    },
  })
}

export function useDeleteSandboxFile(
  projectId: string | null | undefined,
  sandboxId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (path: string) =>
      sdk
        .forProject(projectId!)
        .sandboxes.deleteFile({ sandboxId: sandboxId!, path }),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['sandbox-files', 'project', projectId, sandboxId],
      })
    },
  })
}
