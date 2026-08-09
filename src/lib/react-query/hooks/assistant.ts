import {
  infiniteQueryOptions,
  keepPreviousData,
  queryOptions,
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ID, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { getProjectRegion, sdk } from '@/lib/appwrite/sdk'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { messageNeedsToolHydration } from '@/lib/assistant/resource-mutations'
import {
  DEFAULT_PAGE_SIZE,
  DEFAULT_STALE_TIME,
  isClientQueryEnabled,
} from './constants'

/** Cap getMessage hydration so list-row stats stay bounded. */
const ASSISTANT_TOOL_HYDRATION_LIMIT = 8

export const ASSISTANT_MESSAGES_PAGE_SIZE = 25

/** Limit for model pickers / chat composer (not the settings list). */
export const ASSISTANT_MODELS_PICKER_PAGE_SIZE = 25

export const ASSISTANT_SETTINGS_PAGE_SIZE = DEFAULT_PAGE_SIZE
export const ASSISTANT_SETTINGS_PAGE_SIZE_OPTIONS = [10, 25, 50, 100] as const

function isAutomationRunConversation(conversation: {
  automationId?: string | null
}): boolean {
  return Boolean(
    typeof conversation.automationId === 'string' &&
      conversation.automationId.trim(),
  )
}

export async function fetchAssistantConversations(search?: string) {
  const trimmedSearch = search?.trim() || undefined
  const response = await sdk.forConsole.agent.listConversations({
    queries: [
      // Agents list excludes automation runs (those appear under Automations).
      Query.or([
        Query.isNull('automationId'),
        Query.equal('automationId', ''),
      ]),
      Query.orderDesc('$updatedAt'),
    ],
    search: trimmedSearch,
  })
  return (response.conversations ?? []).filter(
    (conversation) => !isAutomationRunConversation(conversation),
  )
}

export async function fetchAssistantMessages(
  conversationId: string,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  if (!conversationId) {
    return { messages: [], total: 0 }
  }
  const response = await sdk.forConsole.agent.listMessages({
    conversationId,
    queries: [Query.orderDesc('$createdAt'), Query.limit(limit)],
  })
  const messages = (response.messages ?? []).slice().reverse()
  return {
    messages,
    total: response.total ?? messages.length,
  }
}

/**
 * List messages and hydrate assistant turns that lack tools/timeline tool
 * events (list payloads are often incomplete for mutation summaries).
 */
export async function fetchAssistantMessagesWithTools(
  conversationId: string,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  const listed = await fetchAssistantMessages(conversationId, limit)

  // Newest first for hydration priority, then restore chronological order.
  const toHydrate = listed.messages
    .map((message, index) => ({ message, index }))
    .reverse()
    .filter(({ message }) => messageNeedsToolHydration(message))
    .slice(0, ASSISTANT_TOOL_HYDRATION_LIMIT)

  const hydratedByIndex = new Map<number, Models.AgentMessage>()
  await Promise.all(
    toHydrate.map(async ({ message, index }) => {
      try {
        const full = await sdk.forConsole.agent.getMessage({
          conversationId,
          messageId: message.$id,
        })
        hydratedByIndex.set(index, full)
      } catch {
        // Keep list payload.
      }
    }),
  )

  const messages = listed.messages.map(
    (message, index) => hydratedByIndex.get(index) ?? message,
  )

  return {
    messages,
    total: listed.total,
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

export function assistantConversationsQueryOptions(
  search?: string,
  options?: { enabled?: boolean },
) {
  const normalizedSearch = search?.trim() || undefined
  const enabled =
    (options?.enabled ?? true) &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'conversations', normalizedSearch ?? ''],
    queryFn: () => fetchAssistantConversations(normalizedSearch),
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
    enabled,
    retry: false,
  })
}

export async function fetchAssistantConversation(conversationId: string) {
  return await sdk.forConsole.agent.getConversation({ conversationId })
}

export function assistantConversationQueryOptions(
  conversationId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  const enabled =
    (options?.enabled ?? true) &&
    !!conversationId &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'conversation', conversationId ?? ''],
    queryFn: () => fetchAssistantConversation(conversationId!),
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
  })
}

export function useAssistantConversation(
  conversationId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  return useQuery(assistantConversationQueryOptions(conversationId, options))
}

export function assistantMessagesQueryOptions(
  conversationId: string | null | undefined,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  const enabled =
    !!conversationId &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'messages', conversationId, limit],
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
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'attachments', ...uniqueFileIds],
    queryFn: () => fetchAssistantAttachmentFiles(uniqueFileIds),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function useAssistantConversations(
  search?: string,
  options?: { enabled?: boolean },
) {
  return useQuery(assistantConversationsQueryOptions(search, options))
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
      modelId?: string
    }) => {
      const { projectId: _projectId, modelTemp, ...conversationParams } = params
      // Backend defaults to 0.2, which many current models reject (only temp=1).
      return await sdk.forConsole.agent.createConversation({
        ...conversationParams,
        modelTemp: modelTemp ?? 1,
      })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: ['agent', 'conversations'],
      })
    },
  })
}

export function useDeleteAssistantConversation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (conversationId: string) => {
      return await sdk.forConsole.agent.deleteConversation({
        conversationId,
      })
    },
    onSuccess: async (_, conversationId) => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: ['agent', 'conversations'],
        }),
        queryClient.removeQueries({
          queryKey: ['agent', 'messages', conversationId],
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
      return await sdk.forConsole.agent.createMessage({
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
          queryKey: ['agent', 'conversations'],
        }),
        queryClient.invalidateQueries({
          queryKey: ['agent', 'messages', message.conversationId],
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
      return await sdk.forConsole.agent.updateMessage({
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
          queryKey: ['agent', 'conversations'],
        }),
        queryClient.refetchQueries({
          queryKey: ['agent', 'messages', message.conversationId],
        }),
      ])
    },
  })
}

/** Feedback score: `1` thumbs up, `-1` thumbs down, `0` clear. */
export type AssistantMessageScore = 1 | -1 | 0

export function useScoreAssistantMessage() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      conversationId: string
      messageId: string
      score: AssistantMessageScore
    }) => {
      return await sdk.forConsole.agent.updateMessage({
        conversationId: params.conversationId,
        messageId: params.messageId,
        score: String(params.score),
      })
    },
    onMutate: async (params) => {
      await queryClient.cancelQueries({
        queryKey: ['agent', 'messages', params.conversationId],
      })

      const previous = queryClient.getQueriesData<{
        messages: Models.AgentMessage[]
        total: number
      }>({ queryKey: ['agent', 'messages', params.conversationId] })

      queryClient.setQueriesData<{
        messages: Models.AgentMessage[]
        total: number
      }>({ queryKey: ['agent', 'messages', params.conversationId] }, (current) => {
        if (!current) return current
        return {
          ...current,
          messages: current.messages.map((message) =>
            message.$id === params.messageId
              ? { ...message, score: params.score }
              : message,
          ),
        }
      })

      return { previous }
    },
    onError: (_error, params, context) => {
      if (!context?.previous) return
      for (const [queryKey, data] of context.previous) {
        queryClient.setQueryData(queryKey, data)
      }
    },
    onSuccess: (message) => {
      // Only patch score. updateMessage (and list payloads) often omit or
      // strip hydrated tools/timeline/output, and spreading the response would
      // wipe console surfaces rendered from those fields.
      queryClient.setQueriesData<{
        messages: Models.AgentMessage[]
        total: number
      }>({ queryKey: ['agent', 'messages', message.conversationId] }, (current) => {
        if (!current) return current
        return {
          ...current,
          messages: current.messages.map((cached) =>
            cached.$id === message.$id
              ? { ...cached, score: message.score }
              : cached,
          ),
        }
      })
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
      modelId?: string
      lockReason?: string
    }) => {
      return await sdk.forConsole.agent.updateConversation({
        conversationId: params.conversationId,
        title: params.title,
        status: params.status,
        controlType: params.controlType,
        retryFromMessageId: params.retryFromMessageId,
        modelName: params.modelName,
        modelTemp: params.modelTemp,
        modelId: params.modelId,
        lockReason: params.lockReason,
      })
    },
    onSuccess: async (conversation) => {
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['agent', 'conversations'],
        }),
        queryClient.refetchQueries({
          queryKey: ['agent', 'messages', conversation.$id],
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
  const response = await sdk.forConsole.agent.listMcpConnections({
    queries: [Query.orderDesc('$updatedAt')],
  })
  return response.mcps ?? []
}

export function assistantMcpConnectionsQueryOptions(options?: {
  enabled?: boolean
}) {
  const enabled =
    (options?.enabled ?? true) &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'mcps'],
    queryFn: fetchAssistantMcpConnections,
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
  })
}

export function useAssistantMcpConnections(options?: { enabled?: boolean }) {
  return useQuery(assistantMcpConnectionsQueryOptions(options))
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
        return await sdk.forConsole.agent.updateMcpConnection(payload)
      }
      try {
        return await sdk.forConsole.agent.createMcpConnection(payload)
      } catch (error) {
        // Connection may already exist from a prior attempt; fall back to update.
        const message =
          error && typeof error === 'object' && 'message' in error
            ? String((error as { message?: unknown }).message)
            : ''
        if (!/already exists|conflict|409/i.test(message)) {
          throw error
        }
        return await sdk.forConsole.agent.updateMcpConnection(payload)
      }
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'mcps'] })
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
      return await sdk.forConsole.agent.updateMcpConnection(params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'mcps'] })
    },
  })
}

export function useDeleteAssistantMcpConnection() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (mcpId: string) => {
      return await sdk.forConsole.agent.deleteMcpConnection({ mcpId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'mcps'] })
    },
  })
}

export async function fetchAssistantMemories(
  page: number = 0,
  limit: number = ASSISTANT_SETTINGS_PAGE_SIZE,
) {
  const response = await sdk.forConsole.agent.listMemories({
    queries: [
      Query.orderDesc('$updatedAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
  const memories = response.memories ?? []
  return {
    memories,
    total: response.total ?? memories.length,
  }
}

export function assistantMemoriesQueryOptions(
  page: number = 0,
  limit: number = ASSISTANT_SETTINGS_PAGE_SIZE,
  options?: { enabled?: boolean },
) {
  const enabled =
    (options?.enabled ?? true) &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'memories', page, limit],
    queryFn: () => fetchAssistantMemories(page, limit),
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
    placeholderData: keepPreviousData,
  })
}

export function useAssistantMemories(
  page: number = 0,
  limit: number = ASSISTANT_SETTINGS_PAGE_SIZE,
  options?: { enabled?: boolean },
) {
  return useQuery(assistantMemoriesQueryOptions(page, limit, options))
}

export function useCreateAssistantMemory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      memoryId?: string
      scope: string
      key: string
      content: string
      category?: string
      priority?: number
      status?: string
      source?: string
      expiresAt?: string
    }) => {
      return await sdk.forConsole.agent.createMemory({
        memoryId: params.memoryId?.trim() || 'unique()',
        scope: params.scope,
        key: params.key,
        content: params.content,
        category: params.category,
        priority: params.priority,
        status: params.status,
        source: params.source,
        expiresAt: params.expiresAt,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'memories'] })
    },
  })
}

export function useUpdateAssistantMemory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      memoryId: string
      content?: string
      category?: string
      priority?: number
      status?: string
      source?: string
      expiresAt?: string
    }) => {
      return await sdk.forConsole.agent.updateMemory(params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'memories'] })
    },
  })
}

export function useDeleteAssistantMemory() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (memoryId: string) => {
      return await sdk.forConsole.agent.deleteMemory({ memoryId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'memories'] })
    },
  })
}

export async function fetchAssistantModels(
  page: number = 0,
  limit: number = ASSISTANT_SETTINGS_PAGE_SIZE,
  search?: string,
) {
  const trimmedSearch = search?.trim() || undefined
  const response = await sdk.forConsole.agent.listModels({
    queries: [
      Query.orderDesc('$updatedAt'),
      Query.limit(limit),
      Query.offset(page * limit),
      ...(trimmedSearch ? [Query.search('search', trimmedSearch)] : []),
    ],
  })
  const models = response.models ?? []
  return {
    models,
    total: response.total ?? models.length,
  }
}

export async function fetchAssistantModel(modelId: string) {
  return await sdk.forConsole.agent.getModel({ modelId })
}

export function assistantModelQueryOptions(
  modelId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  const enabled =
    (options?.enabled ?? true) &&
    !!modelId &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'models', 'detail', modelId],
    queryFn: () => fetchAssistantModel(modelId!),
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
  })
}

export function useAssistantModel(
  modelId: string | null | undefined,
  options?: { enabled?: boolean },
) {
  return useQuery(assistantModelQueryOptions(modelId, options))
}

export function assistantModelsQueryOptions(
  page: number = 0,
  limit: number = ASSISTANT_SETTINGS_PAGE_SIZE,
  search?: string,
  options?: { enabled?: boolean },
) {
  const trimmedSearch = search?.trim() || undefined
  const enabled =
    (options?.enabled ?? true) &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'models', page, limit, trimmedSearch ?? ''],
    queryFn: () => fetchAssistantModels(page, limit, trimmedSearch),
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
    placeholderData: keepPreviousData,
  })
}

export function useAssistantModels(
  page: number = 0,
  limit: number = ASSISTANT_SETTINGS_PAGE_SIZE,
  options?: { enabled?: boolean; search?: string },
) {
  return useQuery(
    assistantModelsQueryOptions(page, limit, options?.search, options),
  )
}

export function assistantModelsInfiniteQueryOptions(
  limit: number = ASSISTANT_MODELS_PICKER_PAGE_SIZE,
  search?: string,
  options?: { enabled?: boolean },
) {
  const trimmedSearch = search?.trim() || undefined
  const enabled =
    (options?.enabled ?? true) &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return infiniteQueryOptions({
    queryKey: ['agent', 'models', 'infinite', limit, trimmedSearch ?? ''],
    queryFn: ({ pageParam }) =>
      fetchAssistantModels(pageParam, limit, trimmedSearch),
    initialPageParam: 0,
    getNextPageParam: (lastPage, _pages, lastPageParam) => {
      const loaded = (lastPageParam + 1) * limit
      return loaded < (lastPage.total ?? 0) ? lastPageParam + 1 : undefined
    },
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
    placeholderData: keepPreviousData,
  })
}

export function useAssistantModelsInfinite(
  limit: number = ASSISTANT_MODELS_PICKER_PAGE_SIZE,
  search?: string,
  options?: { enabled?: boolean },
) {
  return useInfiniteQuery(
    assistantModelsInfiniteQueryOptions(limit, search, options),
  )
}

export function useCreateAssistantModel() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      modelId?: string
      name: string
      provider: string
      model: string
      apiKey: string
      baseUrl?: string
      enabled?: boolean
      status?: string
    }) => {
      return await sdk.forConsole.agent.createModel({
        // Server-side unique(); client ID.unique() can start with a digit and fail validation.
        modelId: params.modelId?.trim() || 'unique()',
        name: params.name,
        provider: params.provider,
        model: params.model,
        apiKey: params.apiKey,
        baseUrl: params.baseUrl?.trim() || undefined,
        enabled: params.enabled ?? true,
        status: params.status,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'models'] })
    },
  })
}

export function useUpdateAssistantModel() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      modelId: string
      name?: string
      provider?: string
      model?: string
      apiKey?: string
      baseUrl?: string
      enabled?: boolean
      status?: string
    }) => {
      return await sdk.forConsole.agent.updateModel({
        modelId: params.modelId,
        name: params.name,
        provider: params.provider,
        model: params.model,
        apiKey: params.apiKey?.trim() ? params.apiKey : undefined,
        baseUrl: params.baseUrl,
        enabled: params.enabled,
        status: params.status,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'models'] })
    },
  })
}

export function useDeleteAssistantModel() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (modelId: string) => {
      return await sdk.forConsole.agent.deleteModel({ modelId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({ queryKey: ['agent', 'models'] })
    },
  })
}

export async function fetchAssistantAutomations(search?: string) {
  const trimmedSearch = search?.trim() || undefined
  const response = await sdk.forConsole.agent.listAutomations({
    queries: [
      Query.orderDesc('$updatedAt'),
      ...(trimmedSearch ? [Query.search('search', trimmedSearch)] : []),
    ],
  })
  return response.automations ?? []
}

/** Conversations created by a given automation (each scheduled run). */
export async function fetchAssistantAutomationRuns(
  automationId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  if (!automationId) return { runs: [], total: 0 }
  const response = await sdk.forConsole.agent.listConversations({
    queries: [
      Query.equal('automationId', automationId),
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
      Query.offset(page * limit),
    ],
  })
  const runs = response.conversations ?? []
  return {
    runs,
    total: response.total ?? runs.length,
  }
}

export function assistantAutomationRunsQueryOptions(
  automationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  options?: { enabled?: boolean },
) {
  const enabled =
    (options?.enabled ?? true) &&
    !!automationId &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: [
      'agent',
      'automations',
      automationId ?? '',
      'runs',
      page,
      limit,
    ],
    queryFn: () => fetchAssistantAutomationRuns(automationId!, page, limit),
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
    placeholderData: keepPreviousData,
  })
}

export function useAssistantAutomationRuns(
  automationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  options?: { enabled?: boolean },
) {
  return useQuery(
    assistantAutomationRunsQueryOptions(automationId, page, limit, options),
  )
}

export function assistantAutomationsQueryOptions(
  search?: string,
  options?: { enabled?: boolean },
) {
  const normalizedSearch = search?.trim() || undefined
  const enabled =
    (options?.enabled ?? true) &&
    isClientQueryEnabled &&
    getActiveProfileFeatures().agent
  return queryOptions({
    queryKey: ['agent', 'automations', normalizedSearch ?? ''],
    queryFn: () => fetchAssistantAutomations(normalizedSearch),
    staleTime: DEFAULT_STALE_TIME,
    enabled,
    retry: false,
    placeholderData: keepPreviousData,
  })
}

export function useAssistantAutomations(
  search?: string,
  options?: { enabled?: boolean },
) {
  return useQuery(assistantAutomationsQueryOptions(search, options))
}

export function useCreateAssistantAutomation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      automationId?: string
      name: string
      prompt: string
      schedule: string
      titlePrefix?: string
      modelId?: string
      modelTemp?: number
      contextTeamId?: string
      contextProjectId?: string
      contextOrganizationId?: string
      contextPagePath?: string
      contextPageTitle?: string
      contextPageUrl?: string
      attachments?: string[]
      enabled?: boolean
    }) => {
      return await sdk.forConsole.agent.createAutomation({
        // Server-side unique(); client ID.unique() can start with a digit and fail validation.
        automationId: params.automationId?.trim() || 'unique()',
        name: params.name,
        prompt: params.prompt,
        schedule: params.schedule,
        titlePrefix: params.titlePrefix,
        modelId: params.modelId?.trim() || undefined,
        // Same as conversations: backend 0.2 breaks models that only allow 1.
        modelTemp: params.modelTemp ?? 1,
        contextTeamId: params.contextTeamId,
        contextProjectId: params.contextProjectId,
        contextOrganizationId: params.contextOrganizationId,
        contextPagePath: params.contextPagePath,
        contextPageTitle: params.contextPageTitle,
        contextPageUrl: params.contextPageUrl,
        attachments: params.attachments,
        enabled: params.enabled ?? true,
      })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['agent', 'automations'],
      })
    },
  })
}

export function useUpdateAssistantAutomation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (params: {
      automationId: string
      name?: string
      titlePrefix?: string
      prompt?: string
      modelId?: string
      modelTemp?: number
      contextTeamId?: string
      contextProjectId?: string
      contextOrganizationId?: string
      contextPagePath?: string
      contextPageTitle?: string
      contextPageUrl?: string
      attachments?: string[]
      schedule?: string
      enabled?: boolean
    }) => {
      return await sdk.forConsole.agent.updateAutomation(params)
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['agent', 'automations'],
      })
    },
  })
}

export function useDeleteAssistantAutomation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (automationId: string) => {
      return await sdk.forConsole.agent.deleteAutomation({ automationId })
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['agent', 'automations'],
      })
    },
  })
}

export type AssistantConversation = Models.AgentConversation
export type AssistantMessage = Models.AgentMessage
export type AssistantMcpConnection = Models.AgentMcpConnection
export type AssistantMemory = Models.AgentMemory
export type AssistantModel = Models.AgentModel
export type AssistantAutomation = Models.AgentAutomation
export type AgentConversation = Models.AgentConversation
export type AgentMessage = Models.AgentMessage
export type AgentMcpConnection = Models.AgentMcpConnection
export type AgentMemory = Models.AgentMemory
export type AgentModel = Models.AgentModel
export type AgentAutomation = Models.AgentAutomation
