/**
 * React Query hooks for Webhooks
 *
 * Handles webhook fetching, creation, updating, and deletion.
 */

import {
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { ID, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from './dependencies'
import { DEFAULT_STALE_TIME } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch webhooks for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @returns Webhooks list response from the API
 */
export async function fetchProjectWebhooks(projectId: string) {
  if (!projectId) {
    return { webhooks: [], total: 0 }
  }

  const response = await sdk.forProject(projectId).webhooks.list()
  return {
    webhooks: response.webhooks || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single webhook by ID.
 */
export async function fetchProjectWebhook(
  projectId: string,
  webhookId: string,
): Promise<Models.Webhook> {
  if (!projectId || !webhookId) {
    throw new Error('Project ID and Webhook ID are required')
  }
  return await sdk.forProject(projectId).webhooks.get({ webhookId })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching webhooks for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function webhooksQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['webhooks', 'project', projectId],
    queryFn: () => fetchProjectWebhooks(projectId!),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch webhooks for a project
 *
 * @param projectId - The project ID
 * @returns Webhooks list with loading state
 */
export function useProjectWebhooks(projectId: string | null | undefined) {
  const { data, isLoading, error, refetch } = useQuery(
    webhooksQueryOptions(projectId),
  )

  return {
    webhooks: data?.webhooks || [],
    total: data?.total || 0,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to get a single webhook
 *
 * @param projectId - The project ID
 * @param webhookId - The webhook ID
 */
export function useProjectWebhook(
  projectId: string | null | undefined,
  webhookId: string | null | undefined,
) {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['webhook', 'project', projectId, webhookId],
    queryFn: () => fetchProjectWebhook(projectId!, webhookId!),
    enabled: !!projectId && !!webhookId,
    staleTime: DEFAULT_STALE_TIME,
  })

  return {
    webhook: data || null,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to create a webhook
 *
 * @param projectId - The project ID
 */
export function useCreateWebhook(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: {
      name: string
      events: string[]
      url: string
      tls: boolean
      enabled?: boolean
      authUsername?: string
      authPassword?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await sdk.forProject(projectId).webhooks.create({
        webhookId: ID.unique(),
        name: data.name,
        events: data.events,
        url: data.url,
        tls: data.tls,
        enabled: data.enabled ?? true,
        authUsername: data.authUsername,
        authPassword: data.authPassword,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['webhooks', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.WEBHOOKS,
      })
    },
  })
}

/**
 * Hook to update a webhook
 *
 * @param projectId - The project ID
 */
export function useUpdateWebhook(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: {
      webhookId: string
      name: string
      events: string[]
      url: string
      tls: boolean
      enabled?: boolean
      authUsername?: string
      authPassword?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await sdk.forProject(projectId).webhooks.update({
        webhookId: data.webhookId,
        name: data.name,
        events: data.events,
        url: data.url,
        tls: data.tls,
        enabled: data.enabled,
        authUsername: data.authUsername,
        authPassword: data.authPassword,
      })
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['webhooks', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['webhook', 'project', projectId, variables.webhookId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.WEBHOOKS,
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.WEBHOOK,
      })
    },
  })
}

/**
 * Hook to regenerate the webhook signing secret
 *
 * @param projectId - The project ID
 */
export function useUpdateWebhookSecret(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (
      input: string | { webhookId: string; secret?: string },
    ): Promise<Models.Webhook> => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      const webhookId = typeof input === 'string' ? input : input.webhookId
      const secret =
        typeof input === 'string'
          ? undefined
          : input.secret?.trim() || undefined
      return await sdk.forProject(projectId).webhooks.updateSecret({
        webhookId,
        secret,
      })
    },
    onSuccess: (_, input) => {
      const webhookId = typeof input === 'string' ? input : input.webhookId
      queryClient.invalidateQueries({
        queryKey: ['webhook', 'project', projectId, webhookId],
      })
      queryClient.invalidateQueries({
        queryKey: ['webhooks', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.WEBHOOK,
      })
    },
  })
}

/**
 * Hook to delete a webhook
 *
 * @param projectId - The project ID
 */
export function useDeleteWebhook(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (webhookId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await sdk.forProject(projectId).webhooks.delete({
        webhookId,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['webhooks', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.WEBHOOKS,
      })
    },
  })
}
