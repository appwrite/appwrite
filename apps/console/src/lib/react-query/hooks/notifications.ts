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
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
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

/**
 * Acknowledges the firing alert a notification is about, as Beep does from a
 * push. Alerts live in their project, so each call names it; that project's
 * alert feed refetches with the acknowledgement.
 */
export function useAcknowledgeAlert() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      projectId,
      alertId,
    }: {
      projectId: string
      alertId: string
    }) =>
      await sdk
        .forProject(projectId)
        .alerts.update({ alertId, acknowledged: true }),
    onSuccess: (_alert, { projectId }) =>
      queryClient.invalidateQueries({
        queryKey: ['alerts', 'project', projectId],
      }),
  })
}

/**
 * Push settings shared with Beep, read from the account and changed against
 * the server's latest copy: Beep may have changed them on a phone since the
 * account was loaded, and `updatePrefs` replaces every key at once.
 */
export function useBeepPrefs(account: ConsoleAccountCache | undefined) {
  const queryClient = useQueryClient()
  const beep = readBeepPrefs(
    account?.prefs as Record<string, unknown> | undefined,
  )

  const mutation = useMutation({
    mutationFn: async (change: (current: BeepPrefs) => BeepPrefs) => {
      const latest = (await sdk.forConsole.account.getPrefs()) as Record<
        string,
        unknown
      >
      const cached = (account?.prefs ?? {}) as Record<string, unknown>
      return await updateAccountPrefs(
        mergeBeepPrefs(cached, change(readBeepPrefs(latest))),
        'beep-push-settings',
        { force: true },
      )
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
