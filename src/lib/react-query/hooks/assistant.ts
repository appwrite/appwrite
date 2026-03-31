import {
  keepPreviousData,
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { ContentType, ID, Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { getProjectRegion, sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'

export const ASSISTANT_MESSAGES_PAGE_SIZE = 25

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

export async function fetchAssistantMessages(
  conversationId: string,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  if (!conversationId) {
    return { messages: [], total: 0 }
  }
  const response = await sdk.forConsole.assistant.listMessages({
    conversationId,
    queries: toAssistantQueries([
      Query.orderDesc('$createdAt'),
      Query.limit(limit),
    ]),
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

export const ASSISTANT_ATTACHMENTS_BUCKET_ID = 'attachements'

export function assistantConversationsQueryOptions() {
  return queryOptions({
    queryKey: ['assistant', 'conversations'],
    queryFn: fetchAssistantConversations,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function assistantMessagesQueryOptions(
  conversationId: string | null | undefined,
  limit: number = ASSISTANT_MESSAGES_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: ['assistant', 'messages', conversationId, limit],
    queryFn: () => fetchAssistantMessages(conversationId!, limit),
    enabled: !!conversationId,
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
  return queryOptions({
    queryKey: ['assistant', 'attachments', ...uniqueFileIds],
    queryFn: () => fetchAssistantAttachmentFiles(uniqueFileIds),
    enabled: uniqueFileIds.length > 0,
    staleTime: DEFAULT_STALE_TIME,
  })
}

export function useAssistantConversations() {
  return useQuery(assistantConversationsQueryOptions())
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
      const assistant = sdk.forConsole.assistant as unknown as {
        createMessage: (payload: {
          conversationId: string
          contentText: string
          contentType: ContentType
          contextTeamId?: string
          contextProjectId?: string
          contextOrganizationId?: string
          contextPagePath?: string
          contextPageTitle?: string
          contextPageUrl?: string
          attachments?: string[]
          continueRun?: boolean
        }) => Promise<Models.AssistantMessage>
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

export type AssistantConversation = Models.AssistantConversation
export type AssistantMessage = Models.AssistantMessage
