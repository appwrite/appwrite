import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { getProjectRegion, sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { DEFAULT_STALE_TIME, isClientQueryEnabled } from './constants'

export const ASSISTANT_MESSAGES_PAGE_SIZE = 25

export async function fetchAssistantConversations(search?: string) {
  const queries = [Query.orderDesc('$updatedAt')]
  const trimmedSearch = search?.trim() || undefined

  // Console SDK listConversations does not expose `search` yet; call the API
  // directly so we can use the standard list search parameter.
  const assistant = sdk.forConsole.assistant
  const response = (await assistant.client.call(
    'get',
    new URL(`${assistant.client.config.endpoint}/assistant/conversations`),
    {
      'X-Appwrite-Project': assistant.client.config.project,
      accept: 'application/json',
    },
    {
      queries,
      ...(trimmedSearch ? { search: trimmedSearch } : {}),
    },
  )) as Models.AssistantConversationList

  return response.conversations ?? []
}

export async function fetchAssistantMessages(
  conversationId: string,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  if (!conversationId) {
    return { messages: [], total: 0 }
  }
  const response = await sdk.forConsole.assistant.listMessages({
    conversationId,
    queries: [Query.orderDesc('$createdAt'), Query.limit(limit)],
  })
  const messages = (response.messages ?? []).slice().reverse()
  return {
    messages,
    total: response.total ?? messages.length,
  }
}
export interface AssistantMessageContext {
  contextTeamId?: string
  contextProjectId?: string
  contextOrganizationId?: string
  contextPagePath?: string
  contextPageTitle?: string
  contextPageUrl?: string
}

/** Drop empty strings so optional SDK params are omitted (API rejects ""). */
function optionalContextString(
  value: string | null | undefined,
): string | undefined {
  if (typeof value !== 'string') return undefined
  const trimmed = value.trim()
  return trimmed.length > 0 ? trimmed : undefined
}

function toAssistantMessageContextPayload(context?: AssistantMessageContext) {
  return {
    contextTeamId: optionalContextString(context?.contextTeamId),
    contextProjectId: optionalContextString(context?.contextProjectId),
    contextOrganizationId: optionalContextString(
      context?.contextOrganizationId,
    ),
    contextPagePath: optionalContextString(context?.contextPagePath),
    contextPageTitle: optionalContextString(context?.contextPageTitle),
    contextPageUrl: optionalContextString(context?.contextPageUrl),
  }
}

export const ASSISTANT_ATTACHMENTS_BUCKET_ID = 'attachements'

export function assistantConversationsQueryOptions(search?: string) {
  const normalizedSearch = search?.trim() || undefined
  const enabled =
    isClientQueryEnabled && getActiveProfileFeatures().aiAssistant
  return queryOptions({
    queryKey: ['assistant', 'conversations', normalizedSearch ?? ''],
    queryFn: () => fetchAssistantConversations(normalizedSearch),
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function assistantMessagesQueryOptions(
  conversationId: string | null | undefined,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  const enabled =
    !!conversationId &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().aiAssistant
  return queryOptions({
    queryKey: ['assistant', 'messages', conversationId, limit],
    queryFn: () => fetchAssistantMessages(conversationId!, limit),
    enabled,
    placeholderData: keepPreviousData,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export async function fetchAssistantAttachmentFiles(fileIds: string[]) {
  const uniqueFileIds = [...new Set(fileIds.filter(Boolean))]
  if (uniqueFileIds.length === 0) return []

  const files = await Promise.all(
    uniqueFileIds.map(async (fileId) => {
      try {
        return await sdk.forConsole.storage.getFile({
          bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
          fileId,
        })
      } catch {
        return null
      }
    }),
  )

  return files.filter((file): file is Models.File => file !== null)
}

export function assistantAttachmentFilesQueryOptions(fileIds: string[]) {
  const uniqueFileIds = [...new Set(fileIds.filter(Boolean))]
  const enabled =
    uniqueFileIds.length > 0 &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().aiAssistant
  return queryOptions({
    queryKey: ['assistant', 'attachments', ...uniqueFileIds],
    queryFn: () => fetchAssistantAttachmentFiles(uniqueFileIds),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function useAssistantConversations(search?: string) {
  return useQuery(assistantConversationsQueryOptions(search))
}

export function useAssistantMessages(
  conversationId: string | null | undefined,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  return useQuery(assistantMessagesQueryOptions(conversationId, limit))
}

export function useAssistantAttachmentFiles(fileIds: string[]) {
  return useQuery(assistantAttachmentFilesQueryOptions(fileIds))
}

export function useCreateAssistantConversation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      projectId: string
      title?: string
      modelName?: string
      modelTemp?: number
    }) => {
      const { projectId: _projectId, ...conversationParams } = params
      return await sdk.forConsole.assistant.createConversation(conversationParams)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['assistant', 'conversations'],
      })
    },
  })
}

export function useDeleteAssistantConversation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (conversationId: string) => {
      return await sdk.forConsole.assistant.deleteConversation({
        conversationId,
      })
    },
    onSuccess: async (_, conversationId) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['assistant', 'conversations'],
        }),
        queryClient.removeQueries({
          queryKey: ['assistant', 'messages', conversationId],
        }),
      ])
    },
  })
}

export function useCreateAssistantMessage() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      conversationId: string
      contentText: string
      continueRun?: boolean
      context?: AssistantMessageContext
      attachments?: string[]
    }) => {
      return await sdk.forConsole.assistant.createMessage({
        conversationId: params.conversationId,
        contentText: params.contentText,
        contentType: 'text',
        ...toAssistantMessageContextPayload(params.context),
        attachments: params.attachments ?? [],
        continueRun: params.continueRun ?? true,
      })
    },
    onSuccess: async (message) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['assistant', 'conversations'],
        }),
        queryClient.invalidateQueries({
          queryKey: ['assistant', 'messages', message.conversationId],
        }),
      ])
    },
  })
}

export function useUpdateAssistantMessage() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      conversationId: string
      messageId: string
      contentText?: string
      context?: AssistantMessageContext
      attachments?: string[]
    }) => {
      return await sdk.forConsole.assistant.updateMessage({
        conversationId: params.conversationId,
        messageId: params.messageId,
        contentText: params.contentText,
        ...toAssistantMessageContextPayload(params.context),
        attachments: params.attachments,
      })
    },
    onSuccess: async (message) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['assistant', 'conversations'],
        }),
        queryClient.refetchQueries({
          queryKey: ['assistant', 'messages', message.conversationId],
        }),
      ])
    },
  })
}

export function useUpdateAssistantConversation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      conversationId: string
      title?: string
      status?: string
      controlType?: 'stop' | 'retry' | 'unlock' | string
      retryFromMessageId?: string
      modelName?: string
      modelTemp?: number
      lockReason?: string
    }) => {
      return await sdk.forConsole.assistant.updateConversation({
        conversationId: params.conversationId,
        title: params.title,
        status: params.status,
        controlType: params.controlType,
        retryFromMessageId: params.retryFromMessageId,
        modelName: params.modelName,
        modelTemp: params.modelTemp,
        lockReason: params.lockReason,
      })
    },
    onSuccess: async (conversation) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['assistant', 'conversations'],
        }),
        queryClient.refetchQueries({
          queryKey: ['assistant', 'messages', conversation.$id],
        }),
      ])
    },
  })
}

export function useUploadAssistantAttachments() {
  return useMutation({
    mutationFn: async (params: { files: File[]; projectId?: string }) => {
      const region = params.projectId
        ? (getProjectRegion(params.projectId) ?? 'unknown')
        : 'unknown'
      const consoleSdk = sdk.forConsoleIn(region)

      const uploadedFiles = await Promise.all(
        params.files.map((file) =>
          consoleSdk.storage.createFile({
            bucketId: ASSISTANT_ATTACHMENTS_BUCKET_ID,
            fileId: ID.unique(),
            file,
          }),
        ),
      )

      return uploadedFiles.map((file) => file.$id)
    },
  })
}

export async function fetchAssistantMcpConnections() {
  const response = await sdk.forConsole.assistant.listMcpConnections({
    queries: [Query.orderDesc('$updatedAt')],
  })
  return response.mcps ?? []
}

export function assistantMcpConnectionsQueryOptions() {
  const enabled =
    isClientQueryEnabled && getActiveProfileFeatures().aiAssistant
  return queryOptions({
    queryKey: ['assistant', 'mcps'],
    queryFn: fetchAssistantMcpConnections,
    staleTime: DEFAULT_STALE_TIME,
    enabled,
  })
}

export function useAssistantMcpConnections() {
  return useQuery(assistantMcpConnectionsQueryOptions())
}

export function useUpsertAssistantMcpConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      mcpId: string
      name: string
      url: string
      description?: string
      enabled?: boolean
      tokens?: string
      clientInfo?: string
      status?: string
      /** When true, PATCH an existing connection instead of creating. */
      exists?: boolean
    }) => {
      const payload = {
        mcpId: params.mcpId,
        name: params.name,
        url: params.url,
        description: params.description,
        enabled: params.enabled ?? true,
        tokens: params.tokens,
        clientInfo: params.clientInfo,
        status: params.status,
      }
      if (params.exists) {
        return await sdk.forConsole.assistant.updateMcpConnection(payload)
      }
      try {
        return await sdk.forConsole.assistant.createMcpConnection(payload)
      } catch (error) {
        // Connection may already exist from a prior attempt; fall back to update.
        const message =
          error && typeof error === 'object' && 'message' in error
            ? String((error as { message?: unknown }).message)
            : ''
        if (!/already exists|conflict|409/i.test(message)) {
          throw error
        }
        return await sdk.forConsole.assistant.updateMcpConnection(payload)
      }
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['assistant', 'mcps'] })
    },
  })
}

export function useUpdateAssistantMcpConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      mcpId: string
      name?: string
      url?: string
      description?: string
      enabled?: boolean
      tokens?: string
      clientInfo?: string
      status?: string
    }) => {
      return await sdk.forConsole.assistant.updateMcpConnection(params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['assistant', 'mcps'] })
    },
  })
}

export function useDeleteAssistantMcpConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (mcpId: string) => {
      return await sdk.forConsole.assistant.deleteMcpConnection({ mcpId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['assistant', 'mcps'] })
    },
  })
}

export type AssistantConversation = Models.AssistantConversation
export type AssistantMessage = Models.AssistantMessage
export type AssistantMcpConnection = Models.AssistantMcpConnection
