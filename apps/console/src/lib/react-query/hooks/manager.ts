/**
 * React Query hooks for the Manager (moderation) service.
 *
 * The Manager SDK is only usable by console operators with the `impersonator`
 * flag; calls made by other accounts will fail at the API layer.
 *
 * Exposes:
 * - listBlocks(projectId)            - resource blocks for a project
 * - createBlock                       - create a resource block
 * - createBlocks                      - create one block per resource ID
 * - deleteBlock                       - remove resource block(s)
 * - deleteCache                        - flush internal caches by region/target
 * - updateUserStatus                  - block/unblock a console user
 * - updateOrganizationStatus          - block/unblock an organization
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
import type {
  Models,
  BlockResourceType,
  BlockMode,
  Region,
  CacheTarget,
  CacheDatabase,
  Manager,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export type BlocksListResponse = Models.ManagerBlockList
export type ManagerBlock = Models.ManagerBlock

const BLOCKS_QUERY_KEY = 'manager-blocks' as const

function blocksKey(projectId: string | null | undefined) {
  return [BLOCKS_QUERY_KEY, projectId ?? null] as const
}

export async function fetchBlocks(
  projectId: string,
): Promise<BlocksListResponse> {
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
  resourceType: BlockResourceType
  resourceId?: string
  /**
   * Block mode. `full` blocks reads and writes; `readonly` blocks writes only
   * (database-specific). Omitted defaults to `full` server-side.
   */
  mode?: BlockMode
  reason?: string
  expiredAt?: string
}

export function useCreateBlock(
  options?: Omit<
    UseMutationOptions<Models.ManagerBlock, unknown, CreateBlockParams>,
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
        mode: params.mode,
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

/** How many createBlock calls run at once when blocking a list of IDs. */
const BULK_CREATE_CONCURRENCY = 5

export type CreateBlocksParams = {
  /** Project that owns the resources. Omitted when `projectIds` is set. */
  projectId?: string
  /**
   * Whole-project blocks. Each ID is created as its own project block,
   * with no resource ID.
   */
  projectIds?: string[]
  resourceType: BlockResourceType
  /**
   * Resource IDs to block inside `projectId`. An empty list creates one
   * wildcard block (all resources of this type in the project). Ignored
   * when `projectIds` is set.
   */
  resourceIds: string[]
  mode?: BlockMode
  reason?: string
  expiredAt?: string
  onProgress?: (done: number, total: number) => void
}

export type CreateBlockFailure = {
  projectId: string
  resourceId?: string
  message: string
}

export type CreateBlocksResult = {
  created: Models.ManagerBlock[]
  failed: CreateBlockFailure[]
}

function toErrorMessage(error: unknown): string {
  if (error instanceof Error && error.message) return error.message
  return String(error)
}

async function runPool(
  count: number,
  concurrency: number,
  worker: (index: number) => Promise<void>,
) {
  let next = 0
  const runners = Array.from(
    { length: Math.min(concurrency, count) },
    async () => {
      while (next < count) {
        const index = next
        next += 1
        await worker(index)
      }
    },
  )
  await Promise.all(runners)
}

export function useCreateBlocks(
  options?: Omit<
    UseMutationOptions<CreateBlocksResult, unknown, CreateBlocksParams>,
    'mutationFn'
  >,
) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (params: CreateBlocksParams): Promise<CreateBlocksResult> => {
      const entries: { projectId: string; resourceId?: string }[] =
        params.projectIds && params.projectIds.length > 0
          ? params.projectIds.map((id) => ({ projectId: id }))
          : (params.resourceIds.length > 0 ? params.resourceIds : [undefined]).map(
              (resourceId) => ({
                projectId: params.projectId ?? '',
                resourceId,
              }),
            )
      const outcomes: Array<
        | { ok: true; block: Models.ManagerBlock }
        | { ok: false; message: string }
      > = new Array(entries.length)
      let done = 0

      await runPool(entries.length, BULK_CREATE_CONCURRENCY, async (index) => {
        const entry = entries[index]
        try {
          const block = await sdk.forConsole.manager.createBlock({
            projectId: entry.projectId,
            resourceType: params.resourceType,
            resourceId: entry.resourceId,
            mode: params.mode,
            reason: params.reason?.trim() || undefined,
            expiredAt: params.expiredAt?.trim() || undefined,
          })
          outcomes[index] = { ok: true, block }
        } catch (error) {
          outcomes[index] = { ok: false, message: toErrorMessage(error) }
        }
        done += 1
        params.onProgress?.(done, entries.length)
      })

      const created: Models.ManagerBlock[] = []
      const failed: CreateBlockFailure[] = []
      for (let i = 0; i < outcomes.length; i++) {
        const outcome = outcomes[i]
        const entry = entries[i]
        if (!outcome || !entry) continue
        if (outcome.ok) created.push(outcome.block)
        else
          failed.push({
            projectId: entry.projectId,
            resourceId: entry.resourceId,
            message: outcome.message,
          })
      }
      return { created, failed }
    },
    retry: false,
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      const projectIds = new Set<string>()
      if (vars.projectId) projectIds.add(vars.projectId)
      for (const id of vars.projectIds ?? []) projectIds.add(id)
      for (const id of projectIds) {
        qc.invalidateQueries({ queryKey: blocksKey(id) })
      }
      options?.onSuccess?.(data, vars, onMutateResult, ctx)
    },
  })
}

export type DeleteBlockParams = {
  projectId: string
  resourceType: BlockResourceType
  resourceId?: string
}

export function useDeleteBlock(
  options?: Omit<
    UseMutationOptions<Models.ManagerBlockDelete, unknown, DeleteBlockParams>,
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

export type DeleteCacheParams = {
  region?: Region
  cache?: CacheTarget
  all?: boolean
  database?: CacheDatabase
  projectId?: string
  collectionId?: string
  documentId?: string
}

/**
 * Clears internal caches via the Manager service. Operator-only.
 *
 * Omitting `region` clears the selected target in every region. Returns an
 * empty object on success, so there is nothing to invalidate in the cache.
 */
export function useDeleteCache(
  options?: Omit<
    UseMutationOptions<unknown, unknown, DeleteCacheParams>,
    'mutationFn'
  >,
) {
  return useMutation({
    mutationFn: async (params: DeleteCacheParams) => {
      return sdk.forConsole.manager.deleteCache({
        region: params.region,
        cache: params.cache,
        all: params.all,
        database: params.database,
        projectId: params.projectId?.trim() || undefined,
        collectionId: params.collectionId?.trim() || undefined,
        documentId: params.documentId?.trim() || undefined,
      })
    },
    ...options,
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

export type UpdateOrganizationStatusParams = {
  teamId: string
  status: boolean
  reason?: string
}

// TODO: drop once the console SDK ships `manager.updateOrganizationStatus` (appwrite-labs/cloud#6118).
type ManagerWithOrganizationStatus = Manager & {
  updateOrganizationStatus(
    params: UpdateOrganizationStatusParams,
  ): Promise<Models.Organization>
}

export function useUpdateOrganizationStatus(
  options?: Omit<
    UseMutationOptions<
      Models.Organization,
      unknown,
      UpdateOrganizationStatusParams
    >,
    'mutationFn'
  >,
) {
  return useMutation({
    mutationFn: async (params: UpdateOrganizationStatusParams) => {
      const manager = sdk.forConsole.manager as ManagerWithOrganizationStatus
      return manager.updateOrganizationStatus({
        teamId: params.teamId.trim(),
        status: params.status,
        reason: params.reason?.trim() || undefined,
      })
    },
    ...options,
  })
}
