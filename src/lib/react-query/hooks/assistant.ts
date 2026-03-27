import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ContentType, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'

function toAssistantQueries(queries: string[]): string {
  // Assistant SDK typing currently expects `string`, while backend requires an
  // array of query strings. Keep conversion centralized until SDK typing aligns.
  return queries as unknown as string
}

export async function fetchAssistantConversations() {
  const response = await sdk.forConsole.assistant.listConversations({
    queries: toAssistantQueries([Query.orderDesc('$updatedAt')]),
  })

  return response.conversations ?? []
}

export async function fetchAssistantMessages(conversationId: string) {
  if (!conversationId) return []
  const response = await sdk.forConsole.assistant.listMessages({
    conversationId,
    queries: toAssistantQueries([Query.orderAsc('$createdAt')]),
  })
  const messages = response.messages ?? []
  return messages
}
export interface AssistantMessageContext {
  contextTeamId?: string
  contextProjectId?: string
  contextOrganizationId?: string
  contextPagePath?: string
  contextPageTitle?: string
  contextPageUrl?: string
}

export function assistantConversationsQueryOptions() {
  return queryOptions({
    queryKey: ['assistant', 'conversations'],
    queryFn: fetchAssistantConversations,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function assistantMessagesQueryOptions(
  conversationId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['assistant', 'messages', conversationId],
    queryFn: () => fetchAssistantMessages(conversationId!),
    enabled: !!conversationId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function useAssistantConversations() {
  return useQuery(assistantConversationsQueryOptions())
}

export function useAssistantMessages(
  conversationId: string | null | undefined,
) {
  return useQuery(assistantMessagesQueryOptions(conversationId))
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
      return await sdk.forConsole.assistant.createConversation(params)
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
      return await sdk.forConsole.assistant.deleteConversation({ conversationId })
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
    }) => {
      const assistant = sdk.forConsole.assistant as unknown as {
        createMessage: (
          payload: Record<string, unknown>,
        ) => Promise<Models.AssistantMessage>
      }

      return await assistant.createMessage({
        conversationId: params.conversationId,
        contentText: params.contentText,
        contentType: ContentType.Text,
        contextTeamId: params.context?.contextTeamId,
        contextProjectId: params.context?.contextProjectId,
        contextOrganizationId: params.context?.contextOrganizationId,
        contextPagePath: params.context?.contextPagePath,
        contextPageTitle: params.context?.contextPageTitle,
        contextPageUrl: params.context?.contextPageUrl,
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

export type AssistantConversation = Models.AssistantConversation
export type AssistantMessage = Models.AssistantMessage
