/**
 * React Query hooks for Messaging
 *
 * Handles messages, topics, providers, subscribers, and targets.
 */

import {
  useQuery,
  queryOptions,
  keepPreviousData,
  type QueryClient,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { buildAttributePrefixSearchQueries } from '@/lib/appwrite-id'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'
import { fetchUser } from './users'
import { bucketsQueryOptions } from './storage'

/** Message detail: list targets page size (must match message detail View + route loader). */
export const MESSAGE_DETAIL_TARGETS_LIMIT = 100

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch messages for a project
 */
export async function fetchProjectMessages(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<Models.MessageList> {
  if (!projectId) {
    return { messages: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.messaging.listMessages({
    queries,
    search: search?.trim() || undefined,
  })

  return {
    messages: response.messages || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single message by ID
 */
export async function fetchMessage(
  projectId: string,
  messageId: string,
): Promise<Models.Message> {
  if (!projectId || !messageId) {
    throw new Error('Project ID and Message ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.messaging.getMessage({ messageId })
  return response
}

/**
 * Query function to fetch targets for a message
 */
export async function fetchMessageTargets(
  projectId: string,
  messageId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
): Promise<Models.TargetList> {
  if (!projectId || !messageId) {
    return { targets: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  // API only allows limit and offset for message targets (not orderAsc/orderDesc).
  const queries = [Query.limit(limit), Query.offset(page * limit)]

  const response = await projectSdk.messaging.listTargets({
    messageId,
    queries,
  })

  return {
    targets: response.targets || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch topics for a project
 */
export async function fetchProjectTopics(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<Models.TopicList> {
  if (!projectId) {
    return { topics: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...buildAttributePrefixSearchQueries(['name', '$id'], search),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.messaging.listTopics({ queries })

  return {
    topics: response.topics || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single topic by ID
 */
export async function fetchTopic(
  projectId: string,
  topicId: string,
): Promise<Models.Topic> {
  if (!projectId || !topicId) {
    throw new Error('Project ID and Topic ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.messaging.getTopic({ topicId })
  return response
}

/**
 * Query function to fetch subscribers for a topic
 */
export async function fetchTopicSubscribers(
  projectId: string,
  topicId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<Models.SubscriberList> {
  if (!projectId || !topicId) {
    return { subscribers: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.messaging.listSubscribers({
    topicId,
    queries,
    search: search?.trim() || undefined,
  })

  return {
    subscribers: response.subscribers || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch providers for a project
 */
export async function fetchProjectProviders(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
): Promise<Models.ProviderList> {
  if (!projectId) {
    return { providers: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...buildAttributePrefixSearchQueries(['name', '$id'], search),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.messaging.listProviders({ queries })

  return {
    providers: response.providers || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch a single provider by ID
 */
export async function fetchProvider(
  projectId: string,
  providerId: string,
): Promise<Models.Provider> {
  if (!projectId || !providerId) {
    throw new Error('Project ID and Provider ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.messaging.getProvider({ providerId })
  return response
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching paginated messages for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function messagesQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const normalizedSearch = search?.trim() || ''
  return queryOptions({
    queryKey: [
      'messages',
      'project',
      projectId,
      page,
      limit,
      normalizedSearch,
    ],
    queryFn: () =>
      fetchProjectMessages(
        projectId!,
        page,
        limit,
        normalizedSearch || undefined,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData,
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for a single message (detail + settings)
 *
 * Use in route loaders and `useMessage` so keys match exactly.
 */
export function messageQueryOptions(
  projectId: string | null | undefined,
  messageId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['message', 'project', projectId, messageId],
    queryFn: () => fetchMessage(projectId!, messageId!),
    enabled: !!projectId && !!messageId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && messageId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching paginated topics for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function topicsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const normalizedSearch = search?.trim() || ''
  return queryOptions({
    queryKey: [
      'topics',
      'project',
      projectId,
      page,
      limit,
      normalizedSearch,
    ],
    queryFn: () =>
      fetchProjectTopics(
        projectId!,
        page,
        limit,
        normalizedSearch || undefined,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData,
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching paginated providers for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function providersQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const normalizedSearch = search?.trim() || ''
  return queryOptions({
    queryKey: [
      'providers',
      'project',
      projectId,
      page,
      limit,
      normalizedSearch,
    ],
    queryFn: () =>
      fetchProjectProviders(
        projectId!,
        page,
        limit,
        normalizedSearch || undefined,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    placeholderData: keepPreviousData,
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for a single messaging topic (detail + settings)
 */
export function topicQueryOptions(
  projectId: string | null | undefined,
  topicId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['topic', 'project', projectId, topicId],
    queryFn: () => fetchTopic(projectId!, topicId!),
    enabled: !!projectId && !!topicId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && topicId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for a single messaging provider (detail + settings)
 *
 * Use in route loaders and `useProvider` so keys match exactly.
 */
export function providerQueryOptions(
  projectId: string | null | undefined,
  providerId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['provider', 'project', projectId, providerId],
    queryFn: () => fetchProvider(projectId!, providerId!),
    enabled: !!projectId && !!providerId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId && providerId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for message targets (paginated)
 */
export function messageTargetsQueryOptions(
  projectId: string | null | undefined,
  messageId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return queryOptions({
    queryKey: [
      'message-targets',
      'project',
      projectId,
      messageId,
      page,
      limit,
    ],
    queryFn: () =>
      fetchMessageTargets(projectId!, messageId!, page, limit),
    enabled: !!projectId && !!messageId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId && messageId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Prefetch message, targets, related topics, and related users for the message detail screen.
 * Call from the message detail route loader so navigation completes with data ready.
 */
export async function prefetchMessageDetailData(
  queryClient: QueryClient,
  projectId: string,
  messageId: string,
) {
  const message = await queryClient.ensureQueryData(
    messageQueryOptions(projectId, messageId),
  )

  await Promise.all([
    queryClient.ensureQueryData(
      messageTargetsQueryOptions(
        projectId,
        messageId,
        0,
        MESSAGE_DETAIL_TARGETS_LIMIT,
      ),
    ),
    queryClient.ensureQueryData(bucketsQueryOptions(projectId, 0, 100, '')),
  ])

  const targetsOpts = messageTargetsQueryOptions(
    projectId,
    messageId,
    0,
    MESSAGE_DETAIL_TARGETS_LIMIT,
  )
  const targetsData = queryClient.getQueryData<Models.TargetList>(
    targetsOpts.queryKey,
  )
  const targets = targetsData?.targets ?? []

  const topicIds = message.topics ?? []
  await Promise.all(
    topicIds.map((tid) =>
      queryClient.ensureQueryData(topicQueryOptions(projectId, tid)),
    ),
  )

  const userIds = new Set<string>()
  for (const t of targets) {
    if (t.userId) userIds.add(t.userId)
  }
  const messageWithUsers = message as Models.Message & { users?: string[] }
  if (messageWithUsers.users) {
    for (const uid of messageWithUsers.users) {
      userIds.add(uid)
    }
  }

  await Promise.all(
    [...userIds].map((userId) =>
      queryClient.prefetchQuery({
        queryKey: ['user', 'project', projectId, userId],
        queryFn: async (): Promise<Models.User | null> => {
          try {
            return await fetchUser(projectId, userId)
          } catch {
            return null
          }
        },
        staleTime: DEFAULT_STALE_TIME,
      }),
    ),
  )
}

/**
 * Query options for paginated topic subscribers
 *
 * Use in route loaders and `useTopicSubscribers` so keys match exactly.
 */
export function topicSubscribersQueryOptions(
  projectId: string | null | undefined,
  topicId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const normalizedSearch = search?.trim() || ''
  return queryOptions({
    queryKey: [
      'subscribers',
      'project',
      projectId,
      'topic',
      topicId,
      page,
      limit,
      normalizedSearch,
    ],
    queryFn: () =>
      fetchTopicSubscribers(
        projectId!,
        topicId!,
        page,
        limit,
        normalizedSearch || undefined,
      ),
    enabled: !!projectId && !!topicId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    placeholderData: keepPreviousData,
    gcTime: projectId && topicId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch paginated messages for a project
 */
export function useProjectMessages(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: messagesData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(messagesQueryOptions(projectId, page, limit, search))

  const messages = useMemo(() => {
    if (!messagesData?.messages) return []
    return messagesData.messages
  }, [messagesData])

  const totalPages = useMemo(() => {
    if (!messagesData?.total) return 0
    return Math.ceil(messagesData.total / limit)
  }, [messagesData?.total, limit])

  return {
    messages,
    total: messagesData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single message by ID
 */
export function useMessage(
  projectId: string | null | undefined,
  messageId: string | null | undefined,
  initialMessage?: Models.Message,
) {
  return useQuery({
    ...messageQueryOptions(projectId, messageId),
    ...(initialMessage !== undefined ? { initialData: initialMessage } : {}),
  })
}

/**
 * Hook to fetch targets for a message
 */
export function useMessageTargets(
  projectId: string | null | undefined,
  messageId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return useQuery(messageTargetsQueryOptions(projectId, messageId, page, limit))
}

/**
 * Hook to fetch paginated topics for a project
 */
export function useProjectTopics(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: topicsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(topicsQueryOptions(projectId, page, limit, search))

  const topics = useMemo(() => {
    if (!topicsData?.topics) return []
    return topicsData.topics
  }, [topicsData])

  const totalPages = useMemo(() => {
    if (!topicsData?.total) return 0
    return Math.ceil(topicsData.total / limit)
  }, [topicsData?.total, limit])

  return {
    topics,
    total: topicsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single topic by ID
 *
 * Pass `initialTopic` from the route loader so the first paint matches prefetched cache (no loading flash).
 */
export function useTopic(
  projectId: string | null | undefined,
  topicId: string | null | undefined,
  initialTopic?: Models.Topic,
) {
  return useQuery({
    ...topicQueryOptions(projectId, topicId),
    ...(initialTopic !== undefined ? { initialData: initialTopic } : {}),
  })
}

/**
 * Hook to fetch subscribers for a topic
 */
export function useTopicSubscribers(
  projectId: string | null | undefined,
  topicId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: subscribersData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(topicSubscribersQueryOptions(projectId, topicId, page, limit, search))

  const subscribers = useMemo(() => {
    if (!subscribersData?.subscribers) return []
    return subscribersData.subscribers
  }, [subscribersData])

  const totalPages = useMemo(() => {
    if (!subscribersData?.total) return 0
    return Math.ceil(subscribersData.total / limit)
  }, [subscribersData?.total, limit])

  return {
    subscribers,
    total: subscribersData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to fetch paginated providers for a project
 */
export function useProjectProviders(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  const {
    data: providersData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(providersQueryOptions(projectId, page, limit, search))

  const providers = useMemo(() => {
    if (!providersData?.providers) return []
    return providersData.providers
  }, [providersData])

  const totalPages = useMemo(() => {
    if (!providersData?.total) return 0
    return Math.ceil(providersData.total / limit)
  }, [providersData?.total, limit])

  return {
    providers,
    total: providersData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a single provider by ID
 */
export function useProvider(
  projectId: string | null | undefined,
  providerId: string | null | undefined,
  initialProvider?: Models.Provider,
) {
  return useQuery({
    ...providerQueryOptions(projectId, providerId),
    ...(initialProvider !== undefined ? { initialData: initialProvider } : {}),
  })
}

/** Page size for messaging target picker (users with targets). */
export const MESSAGING_TARGET_PICKER_PAGE_SIZE = 25

/**
 * List users for the messaging target picker (same filters as legacy console).
 * Targets on each user are filtered client-side when `providerType` is set.
 */
export async function fetchUsersForMessagingTargetPicker(
  projectId: string,
  page: number = 0,
  limit: number = MESSAGING_TARGET_PICKER_PAGE_SIZE,
  search?: string,
  providerType?: string | null,
): Promise<{ users: Models.User[]; total: number }> {
  if (!projectId) {
    return { users: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]
  if (providerType === 'email') {
    queries.push(Query.notEqual('email', ''))
  } else if (providerType === 'sms') {
    queries.push(Query.notEqual('phone', ''))
  }

  const response = await projectSdk.users.list({
    queries,
    search: search?.trim() || undefined,
  })

  return {
    users: response.users || [],
    total: response.total || 0,
  }
}

export function messagingTargetPickerUsersQueryOptions(
  projectId: string | null | undefined,
  page: number,
  limit: number,
  search: string,
  providerType: string | null | undefined,
) {
  const normalizedSearch = search?.trim() || ''
  return queryOptions({
    queryKey: [
      'messaging-target-picker-users',
      'project',
      projectId,
      page,
      limit,
      normalizedSearch,
      providerType ?? 'all',
    ],
    queryFn: () =>
      fetchUsersForMessagingTargetPicker(
        projectId!,
        page,
        limit,
        normalizedSearch || undefined,
        providerType,
      ),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    retry: false,
  })
}
