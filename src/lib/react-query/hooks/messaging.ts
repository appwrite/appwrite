/**
 * React Query hooks for Messaging
 *
 * Handles messages, topics, providers, subscribers, and targets.
 */

import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  DEFAULT_STALE_TIME,
  DEFAULT_PAGE_SIZE,
  keepPreviousData,
} from './constants'

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
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

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
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.messaging.listTopics({
    queries,
    search: search?.trim() || undefined,
  })

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
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.messaging.listProviders({
    queries,
    search: search?.trim() || undefined,
  })

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
  } = useQuery({
    queryKey: ['messages', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectMessages(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
) {
  return useQuery({
    queryKey: ['message', 'project', projectId, messageId],
    queryFn: () => fetchMessage(projectId!, messageId!),
    enabled: !!projectId && !!messageId,
    staleTime: DEFAULT_STALE_TIME,
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
  return useQuery({
    queryKey: ['message-targets', 'project', projectId, messageId, page, limit],
    queryFn: () => fetchMessageTargets(projectId!, messageId!, page, limit),
    enabled: !!projectId && !!messageId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })
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
  } = useQuery({
    queryKey: ['topics', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectTopics(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
 */
export function useTopic(
  projectId: string | null | undefined,
  topicId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['topic', 'project', projectId, topicId],
    queryFn: () => fetchTopic(projectId!, topicId!),
    enabled: !!projectId && !!topicId,
    staleTime: DEFAULT_STALE_TIME,
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
    error,
    refetch,
  } = useQuery({
    queryKey: [
      'subscribers',
      'project',
      projectId,
      'topic',
      topicId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchTopicSubscribers(projectId!, topicId!, page, limit, search),
    enabled: !!projectId && !!topicId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
  } = useQuery({
    queryKey: ['providers', 'project', projectId, page, limit, search],
    queryFn: () => fetchProjectProviders(projectId!, page, limit, search),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    placeholderData: keepPreviousData,
  })

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
) {
  return useQuery({
    queryKey: ['provider', 'project', projectId, providerId],
    queryFn: () => fetchProvider(projectId!, providerId!),
    enabled: !!projectId && !!providerId,
    staleTime: DEFAULT_STALE_TIME,
  })
}
