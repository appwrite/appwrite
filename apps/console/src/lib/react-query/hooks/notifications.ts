import {
  queryOptions,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  mergeBeepPrefs,
  readBeepPrefs,
  type BeepPrefs,
} from '@/lib/notifications/beep-prefs'
import { DEFAULT_STALE_TIME, isClientQueryEnabled } from './constants'
import {
  changeAccountPrefs,
  syncConsoleAccountAfterMutation,
  type ConsoleAccountCache,
} from './auth'

export const CONSOLE_NOTIFICATIONS_LIMIT = 50

export const consoleNotificationsQueryKey = [
  'notifications',
  'console',
  CONSOLE_NOTIFICATIONS_LIMIT,
] as const

export async function fetchConsoleNotifications(
  limit = CONSOLE_NOTIFICATIONS_LIMIT,
) {
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

const BEEP_PREFS_MUTATION_KEY = ['account', 'prefs', 'beep'] as const

/**
 * Push settings shared with Beep, read from the account and changed against
 * the server's latest prefs: Beep may have changed them on a phone, or
 * another tab may have changed other keys, since this tab loaded the account.
 * Changes run one at a time, so each one reads what the last one saved.
 */
export function useBeepPrefs(account: ConsoleAccountCache | undefined) {
  const queryClient = useQueryClient()
  const beep = readBeepPrefs(
    account?.prefs as Record<string, unknown> | undefined,
  )

  const mutation = useMutation({
    mutationKey: BEEP_PREFS_MUTATION_KEY,
    scope: { id: 'beep-prefs' },
    mutationFn: async (change: (current: BeepPrefs) => BeepPrefs) => {
      const updated = await changeAccountPrefs(
        (latest) => mergeBeepPrefs(latest, change(readBeepPrefs(latest))),
        'beep-push-settings',
        { force: true },
      )
      if (!updated) {
        // `changeAccountPrefs` skips writes while impersonating.
        throw new Error('Push settings were not saved.')
      }
      return updated
    },
    onMutate: (change) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeBeepPrefs(
                  current.prefs,
                  change(readBeepPrefs(current.prefs)),
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      // A queued change would be undone on screen until it saves, so the
      // last change in the queue syncs the account.
      if (
        queryClient.isMutating({ mutationKey: BEEP_PREFS_MUTATION_KEY }) > 1
      ) {
        return
      }
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
    onError: () => {
      // Drop the optimistic copy; the account reloads with what was saved.
      void queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  return {
    beep,
    error: mutation.error,
    change: mutation.mutate,
    isPending: mutation.isPending,
  }
}
