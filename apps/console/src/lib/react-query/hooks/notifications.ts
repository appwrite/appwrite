import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, isClientQueryEnabled } from './constants'

export const CONSOLE_NOTIFICATIONS_LIMIT = 50

export const consoleNotificationsQueryKey = [
  'notifications',
  'console',
  CONSOLE_NOTIFICATIONS_LIMIT,
] as const

export async function fetchConsoleNotifications(limit = CONSOLE_NOTIFICATIONS_LIMIT) {
  const response = await sdk.forConsole.notifications.list({
    queries: [Query.orderDesc('$createdAt'), Query.limit(limit)],
  })

  return {
    notifications: response.notifications ?? [],
    total: response.total ?? 0,
  }
}

export function consoleNotificationsQueryOptions(
  enabled = isClientQueryEnabled,
) {
  return queryOptions({
    queryKey: consoleNotificationsQueryKey,
    queryFn: () => fetchConsoleNotifications(),
    enabled,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnWindowFocus: true,
    refetchInterval: enabled ? 60_000 : false,
  })
}

export function useConsoleNotifications(enabled = true) {
  const query = useQuery(consoleNotificationsQueryOptions(enabled))

  const notifications = query.data?.notifications ?? []
  const unreadCount = notifications.filter((item) => !item.read).length

  return {
    notifications,
    total: query.data?.total ?? 0,
    unreadCount,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error,
    refetch: query.refetch,
  }
}

export function useUpdateConsoleNotificationRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      notificationId,
      read,
    }: {
      notificationId: string
      read: boolean
    }) => {
      return await sdk.forConsole.notifications.update({
        notificationId,
        read,
      })
    },
    onSuccess: (updated) => {
      queryClient.setQueryData<{
        notifications: Models.Notification[]
        total: number
      }>(consoleNotificationsQueryKey, (current) => {
        if (!current) return current
        return {
          ...current,
          notifications: current.notifications.map((item) =>
            item.$id === updated.$id ? updated : item,
          ),
        }
      })
    },
  })
}

export function useMarkAllConsoleNotificationsRead() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (notifications: Models.Notification[]) => {
      const unread = notifications.filter((item) => !item.read)
      if (unread.length === 0) return []

      return await Promise.all(
        unread.map((item) =>
          sdk.forConsole.notifications.update({
            notificationId: item.$id,
            read: true,
          }),
        ),
      )
    },
    onSuccess: (updatedItems) => {
      if (updatedItems.length === 0) return
      const updatedById = new Map(updatedItems.map((item) => [item.$id, item]))
      queryClient.setQueryData<{
        notifications: Models.Notification[]
        total: number
      }>(consoleNotificationsQueryKey, (current) => {
        if (!current) return current
        return {
          ...current,
          notifications: current.notifications.map((item) =>
            updatedById.has(item.$id)
              ? (updatedById.get(item.$id) ?? item)
              : item,
          ),
        }
      })
    },
  })
}
