/**
 * React Query hooks for the Manager (moderation) service.
 *
 * The Manager SDK is only usable by console operators with the `impersonator`
 * flag; calls made by other accounts will fail at the API layer.
 *
 * Exposes:
 * - listBlocks(projectId)            — resource blocks for a project
 * - createBlock                       — create a resource block
 * - deleteBlock                       — remove resource block(s)
 * - updateUserStatus                  — block/unblock a console user
 *
 * Resource blocks are region-scoped on the server. Because the console SDK
 * points at the base endpoint, we pass through `sdk.forConsole.manager` and
 * rely on the caller to supply a region-aware SDK when needed.
 */

import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
} from '@tanstack/react-query'
import type { Models, ResourceType } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export type BlocksListResponse = Models.BlockList
export type Block = Models.Block

const BLOCKS_QUERY_KEY = 'manager-blocks' as const

function blocksKey(projectId: string | null | undefined) {
  return [BLOCKS_QUERY_KEY, projectId ?? null] as const
}

export async function fetchBlocks(projectId: string): Promise<BlocksListResponse> {
  if (!projectId) return { blocks: [], total: 0 }
  return sdk.forConsole.manager.listBlocks({ projectId })
}

export function blocksQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: blocksKey(projectId),
    queryFn: () => fetchBlocks(projectId!),
    enabled: !!projectId?.trim(),
    staleTime: 15 * 1000,
    retry: false,
    refetchOnMount: true,
    refetchOnWindowFocus: false,
  })
}

export function useBlocks(
  projectId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  const opts = blocksQueryOptions(projectId)
  return useQuery({
    ...opts,
    enabled: opts.enabled && (options?.enabled ?? true),
  })
}

export type CreateBlockParams = {
  projectId: string
  resourceType: ResourceType
  resourceId?: string
  reason?: string
  expiredAt?: string
}

export function useCreateBlock(
  options?: Omit<
    UseMutationOptions<Models.Block, unknown, CreateBlockParams>,
    'mutationFn'
  >,
) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateBlockParams) => {
      return sdk.forConsole.manager.createBlock({
        projectId: params.projectId,
        resourceType: params.resourceType,
        resourceId: params.resourceId?.trim() || undefined,
        reason: params.reason?.trim() || undefined,
        expiredAt: params.expiredAt?.trim() || undefined,
      })
    },
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({ queryKey: blocksKey(vars.projectId) })
      options?.onSuccess?.(data, vars, onMutateResult, ctx)
    },
  })
}

export type DeleteBlockParams = {
  projectId: string
  resourceType: ResourceType
  resourceId?: string
}

export function useDeleteBlock(
  options?: Omit<
    UseMutationOptions<Models.BlockDelete, unknown, DeleteBlockParams>,
    'mutationFn'
  >,
) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (params: DeleteBlockParams) => {
      return sdk.forConsole.manager.deleteBlock({
        projectId: params.projectId,
        resourceType: params.resourceType,
        resourceId: params.resourceId?.trim() || undefined,
      })
    },
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({ queryKey: blocksKey(vars.projectId) })
      options?.onSuccess?.(data, vars, onMutateResult, ctx)
    },
  })
}

export type UpdateUserStatusParams = {
  status: boolean
  userId?: string
  email?: string
  reason?: string
}

export function useUpdateUserStatus(
  options?: Omit<
    UseMutationOptions<Models.User, unknown, UpdateUserStatusParams>,
    'mutationFn'
  >,
) {
  return useMutation({
    mutationFn: async (params: UpdateUserStatusParams) => {
      return sdk.forConsole.manager.updateUserStatus({
        status: params.status,
        userId: params.userId?.trim() || undefined,
        email: params.email?.trim() || undefined,
        reason: params.reason?.trim() || undefined,
      })
    },
    ...options,
  })
}

